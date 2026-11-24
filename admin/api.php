<?php
/**
 * Turf Teams - Admin API Endpoint
 * Handles authentication, player CRUD, team resets, lock toggling, and data feeds.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    $pdo = getDb();

    // Public actions (Authentication)
    if ($action === 'login') {
        handleLogin();
        exit;
    }

    if ($action === 'logout') {
        handleLogout();
        exit;
    }

    // Protected actions require valid session
    if (!isAdminAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in as admin.']);
        exit;
    }

    switch ($action) {
        case 'check_auth':
            echo json_encode(['success' => true, 'authenticated' => true]);
            break;

        case 'dashboard_data':
            handleDashboardData($pdo);
            break;

        case 'add_player':
            handleAddPlayer($pdo);
            break;

        case 'edit_player':
            handleEditPlayer($pdo);
            break;

        case 'delete_player':
            handleDeletePlayer($pdo);
            break;

        case 'reset_player':
            handleResetPlayer($pdo);
            break;

        case 'reset_all':
            handleResetAll($pdo);
            break;

        case 'toggle_lock':
            handleToggleLock($pdo);
            break;

        case 'set_base_url':
            handleSetBaseUrl($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Check admin session
 */
function isAdminAuthenticated(): bool {
    return !empty($_SESSION[ADMIN_SESSION_KEY]);
}

/**
 * Handle Admin Login
 */
function handleLogin(): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $password = trim($input['password'] ?? '');

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Password is required.']);
        return;
    }

    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION[ADMIN_SESSION_KEY] = true;
        echo json_encode(['success' => true, 'message' => 'Login successful.']);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Incorrect admin password.']);
    }
}

/**
 * Handle Admin Logout
 */
function handleLogout(): void {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
}

/**
 * Retrieve active base URL (checks DB setting override, then config, then auto-detect)
 */
function resolveCurrentBaseUrl(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'custom_base_url'");
    $stmt->execute();
    $custom = $stmt->fetchColumn();

    if (!empty($custom)) {
        return rtrim($custom, '/');
    }

    return getAppBaseUrl();
}

/**
 * Fetch all admin dashboard data
 */
function handleDashboardData(PDO $pdo): void {
    $baseUrl = resolveCurrentBaseUrl($pdo);

    // Lock status
    $stmtLock = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'picking_locked'");
    $stmtLock->execute();
    $isLocked = ($stmtLock->fetchColumn() === '1');

    // Total counts by tier
    $stmtSkilled = $pdo->query("SELECT COUNT(*) FROM players WHERE tier = 'skilled'");
    $totalSkilled = (int)$stmtSkilled->fetchColumn();

    $stmtRegular = $pdo->query("SELECT COUNT(*) FROM players WHERE tier = 'regular'");
    $totalRegular = (int)$stmtRegular->fetchColumn();

    $totalPlayers = $totalSkilled + $totalRegular;

    // Picked counts
    $stmtPicked = $pdo->query("SELECT COUNT(*) FROM players WHERE team_id IS NOT NULL");
    $totalPicked = (int)$stmtPicked->fetchColumn();

    // Teams data
    $teamsStmt = $pdo->query("SELECT id, name FROM teams ORDER BY id ASC");
    $teamsRaw = $teamsStmt->fetchAll();

    $teamsData = [];
    foreach ($teamsRaw as $team) {
        $teamId = (int)$team['id'];

        // Get players on this team
        $stmtPlayers = $pdo->prepare("
            SELECT id, name, tier, token, team_id, picked_at 
            FROM players 
            WHERE team_id = ? 
            ORDER BY tier ASC, picked_at ASC, name ASC
        ");
        $stmtPlayers->execute([$teamId]);
        $players = $stmtPlayers->fetchAll();

        $skilledCount = 0;
        $regularCount = 0;
        $playersFormatted = [];

        foreach ($players as $p) {
            if ($p['tier'] === 'skilled') $skilledCount++;
            if ($p['tier'] === 'regular') $regularCount++;

            $playerUrl = $baseUrl . '/pick.php?t=' . urlencode($p['token']);
            $whatsappMsg = "Hey " . $p['name'] . ", pick your side for Saturday: " . $playerUrl;
            $whatsappUrl = "https://wa.me/?text=" . rawurlencode($whatsappMsg);

            $playersFormatted[] = [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'tier' => $p['tier'],
                'token' => $p['token'],
                'picked_at' => $p['picked_at'],
                'link' => $playerUrl,
                'whatsapp_link' => $whatsappUrl
            ];
        }

        $teamsData[] = [
            'id' => $teamId,
            'name' => $team['name'],
            'skilled_count' => $skilledCount,
            'skilled_cap' => MAX_SKILLED_PER_TEAM,
            'regular_count' => $regularCount,
            'regular_cap' => MAX_REGULAR_PER_TEAM,
            'total_count' => count($players),
            'max_team_size' => TOTAL_PLAYERS_PER_TEAM,
            'players' => $playersFormatted
        ];
    }

    // Unassigned players
    $stmtUnassigned = $pdo->query("
        SELECT id, name, tier, token, team_id, picked_at 
        FROM players 
        WHERE team_id IS NULL 
        ORDER BY tier ASC, name ASC
    ");
    $unassignedRaw = $stmtUnassigned->fetchAll();
    $unassignedPlayers = [];

    foreach ($unassignedRaw as $p) {
        $playerUrl = $baseUrl . '/pick.php?t=' . urlencode($p['token']);
        $whatsappMsg = "Hey " . $p['name'] . ", pick your side for Saturday: " . $playerUrl;
        $whatsappUrl = "https://wa.me/?text=" . rawurlencode($whatsappMsg);

        $unassignedPlayers[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'tier' => $p['tier'],
            'token' => $p['token'],
            'link' => $playerUrl,
            'whatsapp_link' => $whatsappUrl
        ];
    }

    // All players list (for roster manager)
    $stmtAll = $pdo->query("
        SELECT p.id, p.name, p.tier, p.token, p.team_id, t.name AS team_name, p.picked_at 
        FROM players p 
        LEFT JOIN teams t ON p.team_id = t.id 
        ORDER BY p.id ASC
    ");
    $allPlayersRaw = $stmtAll->fetchAll();
    $allPlayers = [];

    foreach ($allPlayersRaw as $p) {
        $playerUrl = $baseUrl . '/pick.php?t=' . urlencode($p['token']);
        $whatsappMsg = "Hey " . $p['name'] . ", pick your side for Saturday: " . $playerUrl;
        $whatsappUrl = "https://wa.me/?text=" . rawurlencode($whatsappMsg);

        $allPlayers[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'tier' => $p['tier'],
            'token' => $p['token'],
            'team_id' => $p['team_id'] !== null ? (int)$p['team_id'] : null,
            'team_name' => $p['team_name'],
            'picked_at' => $p['picked_at'],
            'link' => $playerUrl,
            'whatsapp_link' => $whatsappUrl
        ];
    }

    echo json_encode([
        'success' => true,
        'base_url' => $baseUrl,
        'picking_locked' => $isLocked,
        'stats' => [
            'total_players' => $totalPlayers,
            'max_allowed' => TOTAL_PLAYERS_ALLOWED,
            'skilled_count' => $totalSkilled,
            'skilled_target' => 8,
            'regular_count' => $totalRegular,
            'regular_target' => 10,
            'picked_count' => $totalPicked,
            'unassigned_count' => count($unassignedPlayers)
        ],
        'teams' => $teamsData,
        'unassigned' => $unassignedPlayers,
        'all_players' => $allPlayers
    ]);
}

/**
 * Add Player
 */
function handleAddPlayer(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = trim($input['name'] ?? '');
    $tier = strtolower(trim($input['tier'] ?? ''));

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Player name is required.']);
        return;
    }

    if (!in_array($tier, ['skilled', 'regular'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tier must be either "skilled" or "regular".']);
        return;
    }

    // Generate cryptographic 32-character hex token
    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("INSERT INTO players (name, tier, token) VALUES (?, ?, ?)");
    $stmt->execute([$name, $tier, $token]);
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => "Player '{$name}' added successfully.",
        'player_id' => (int)$newId
    ]);
}

/**
 * Edit Player
 */
function handleEditPlayer(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $tier = strtolower(trim($input['tier'] ?? ''));

    if ($id <= 0 || empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid player ID and name are required.']);
        return;
    }

    if (!in_array($tier, ['skilled', 'regular'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tier must be either "skilled" or "regular".']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE players SET name = ?, tier = ? WHERE id = ?");
    $stmt->execute([$name, $tier, $id]);

    echo json_encode(['success' => true, 'message' => 'Player updated successfully.']);
}

/**
 * Delete Player
 */
function handleDeletePlayer(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid player ID is required.']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM players WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Player deleted successfully.']);
}

/**
 * Reset ONE player's pick
 */
function handleResetPlayer(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid player ID is required.']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE players SET team_id = NULL, picked_at = NULL WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => "Player pick has been reset."]);
}

/**
 * Reset ALL players' picks
 */
function handleResetAll(PDO $pdo): void {
    $stmt = $pdo->query("UPDATE players SET team_id = NULL, picked_at = NULL");
    $affected = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'message' => "All player picks have been reset ($affected picks cleared)."
    ]);
}

/**
 * Universal setting saver (Works on both MySQL and PostgreSQL/Supabase)
 */
function saveSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    if ($stmt->fetch() !== false) {
        $update = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $update->execute([$value, $key]);
    } else {
        $insert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $insert->execute([$key, $value]);
    }
}

/**
 * Toggle picking lock state
 */
function handleToggleLock(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $lockValue = isset($input['locked']) ? ($input['locked'] ? '1' : '0') : null;

    if ($lockValue === null) {
        // Toggle current
        $current = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'picking_locked'")->fetchColumn();
        $lockValue = ($current === '1') ? '0' : '1';
    }

    saveSetting($pdo, 'picking_locked', (string)$lockValue);

    echo json_encode([
        'success' => true,
        'picking_locked' => ($lockValue === '1'),
        'message' => ($lockValue === '1') ? 'Picking is now locked for all players.' : 'Picking is now open!'
    ]);
}

/**
 * Save custom Base URL
 */
function handleSetBaseUrl(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $url = trim($input['base_url'] ?? '');

    saveSetting($pdo, 'custom_base_url', $url);

    echo json_encode(['success' => true, 'base_url' => $url ?: getAppBaseUrl()]);
}
