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

        case 'set_team_mode':
            handleSetTeamMode($pdo);
            break;

        case 'update_team_names':
            handleUpdateTeamNames($pdo);
            break;

        case 'match_history':
            handleAdminMatchHistory($pdo);
            break;

        case 'set_motm':
            handleAdminSetMotm($pdo);
            break;

        case 'delete_match':
            handleAdminDeleteMatch($pdo);
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
    ensureDatabaseTeamSupport($pdo);
    $baseUrl = resolveCurrentBaseUrl($pdo);

    $teamMode = getTeamMode($pdo);
    $caps = getTeamCaps($teamMode);
    $activeTeamCount = $caps['team_count'];

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

    // Fetch active teams (2 or 3 teams)
    $teamsStmt = $pdo->prepare("SELECT id, name FROM teams ORDER BY id ASC LIMIT ?");
    $teamsStmt->bindValue(1, $activeTeamCount, PDO::PARAM_INT);
    $teamsStmt->execute();
    $teamsRaw = $teamsStmt->fetchAll();

    // Also fetch all teams (for team renaming dialog)
    $allTeamsStmt = $pdo->query("SELECT id, name FROM teams ORDER BY id ASC LIMIT 3");
    $allTeams = $allTeamsStmt->fetchAll();

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
                'rival_name' => getPlayerRivalName($p['name']),
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
            'skilled_cap' => $caps['max_skilled_per_team'],
            'regular_count' => $regularCount,
            'regular_cap' => $caps['max_regular_per_team'],
            'total_count' => count($players),
            'max_team_size' => $caps['max_team_size'],
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
            'rival_name' => getPlayerRivalName($p['name']),
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
            'rival_name' => getPlayerRivalName($p['name']),
            'token' => $p['token'],
            'team_id' => $p['team_id'] !== null ? (int)$p['team_id'] : null,
            'team_name' => $p['team_name'],
            'picked_at' => $p['picked_at'],
            'link' => $playerUrl,
            'whatsapp_link' => $whatsappUrl
        ];
    }

    // Detect any rival conflicts on the same team (e.g. Jimmy & Oliver both on Makongeni)
    $rivalConflicts = [];
    foreach ($teamsData as $tData) {
        $seen = [];
        foreach ($tData['players'] as $pl) {
            $rName = getPlayerRivalName($pl['name']);
            if ($rName) {
                $rClean = strtolower(trim($rName));
                if (isset($seen[$rClean])) {
                    $rivalConflicts[] = [
                        'team_id' => $tData['id'],
                        'team_name' => $tData['name'],
                        'player1' => $seen[$rClean],
                        'player2' => $pl
                    ];
                }
                $seen[strtolower(trim($pl['name']))] = $pl;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'base_url' => $baseUrl,
        'picking_locked' => $isLocked,
        'team_mode' => $teamMode,
        'team_caps' => $caps,
        'stats' => [
            'total_players' => $totalPlayers,
            'max_allowed' => TOTAL_PLAYERS_ALLOWED,
            'skilled_count' => $totalSkilled,
            'skilled_target' => 10,
            'regular_count' => $totalRegular,
            'regular_target' => 10,
            'picked_count' => $totalPicked,
            'unassigned_count' => count($unassignedPlayers)
        ],
        'rival_conflicts' => $rivalConflicts,
        'teams' => $teamsData,
        'all_teams' => $allTeams,
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

/**
 * Switch team mode ('2_teams' or '3_teams')
 */
function handleSetTeamMode(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $rawMode = trim($input['mode'] ?? ($input['team_mode'] ?? ($_POST['mode'] ?? ($_POST['team_mode'] ?? ''))));
    $requestedMode = ($rawMode === '3_teams') ? '3_teams' : '2_teams';

    ensureDatabaseTeamSupport($pdo);
    saveSetting($pdo, 'team_mode', $requestedMode);

    $msg = ($requestedMode === '3_teams')
        ? 'Switched to 3 Teams Mode! Players will split into 3 squads of 6.'
        : 'Switched to 2 Teams Mode! Standard 2 squads of 9-10 restored.';

    echo json_encode([
        'success' => true,
        'team_mode' => $requestedMode,
        'message' => $msg
    ]);
}

/**
 * Update Team Names (customizable by Admin)
 */
function handleUpdateTeamNames(PDO $pdo): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $names = $input['names'] ?? ($input['teams'] ?? []);

    if (empty($names)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No team names provided.']);
        return;
    }

    ensureDatabaseTeamSupport($pdo);

    $stmt = $pdo->prepare("UPDATE teams SET name = ? WHERE id = ?");

    if (is_array($names)) {
        foreach ($names as $key => $val) {
            if (is_array($val)) {
                $id = (int)($val['id'] ?? 0);
                $name = trim($val['name'] ?? '');
            } else {
                $id = (int)$key;
                $name = trim((string)$val);
            }
            if ($id > 0 && !empty($name)) {
                $stmt->execute([$name, $id]);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Team names updated successfully! Changes reflect on player passes and match boards.'
    ]);
}

/**
 * Admin Match History & Ballon d'Or data
 */
function handleAdminMatchHistory(PDO $pdo): void {
    ensureMatchHistoryTables($pdo);

    $matchesStmt = $pdo->query("
        SELECT 
            id, match_code, team_mode, started_at, ended_at,
            duration_seconds, duration_formatted, teams_summary,
            winner_team_name, top_scorer_name, top_scorer_goals,
            man_of_the_match, total_goals, created_at
        FROM match_history
        ORDER BY id DESC
    ");
    $rawMatches = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

    $goalsByMatch = [];
    if (!empty($rawMatches)) {
        $goalsStmt = $pdo->query("
            SELECT 
                id, match_id, player_id, player_name, team_id, team_name,
                is_own_goal, match_minute, match_second, created_at
            FROM match_history_goals
            ORDER BY match_minute ASC, match_second ASC, id ASC
        ");
        while ($g = $goalsStmt->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)$g['match_id'];
            if (!isset($goalsByMatch[$mid])) {
                $goalsByMatch[$mid] = [];
            }
            $isOg = !empty($g['is_own_goal']);
            $goalsByMatch[$mid][] = [
                'id' => (int)$g['id'],
                'player_id' => (int)$g['player_id'],
                'player_name' => $g['player_name'],
                'team_id' => (int)$g['team_id'],
                'team_name' => $g['team_name'],
                'is_own_goal' => $isOg,
                'match_minute' => (int)$g['match_minute'],
                'match_second' => (int)$g['match_second'],
                'display_label' => $isOg 
                    ? "{$g['match_minute']}' {$g['player_name']} (OG)"
                    : "{$g['match_minute']}' {$g['player_name']} ({$g['team_name']})"
            ];
        }
    }

    $formattedMatches = [];
    $totalGoalsCount = 0;

    foreach ($rawMatches as $m) {
        $mid = (int)$m['id'];
        $teamsSummary = json_decode($m['teams_summary'] ?? '[]', true) ?: [];
        $goals = $goalsByMatch[$mid] ?? [];
        $totalGoalsCount += (int)$m['total_goals'];

        $formattedMatches[] = [
            'id' => $mid,
            'match_code' => $m['match_code'],
            'team_mode' => $m['team_mode'],
            'started_at' => $m['started_at'],
            'ended_at' => $m['ended_at'],
            'date_formatted' => !empty($m['started_at']) ? date('M j, Y • H:i', strtotime($m['started_at'])) : date('M j, Y', strtotime($m['created_at'])),
            'duration_formatted' => $m['duration_formatted'],
            'teams' => $teamsSummary,
            'winner_team_name' => $m['winner_team_name'],
            'top_scorer_name' => $m['top_scorer_name'],
            'top_scorer_goals' => (int)$m['top_scorer_goals'],
            'man_of_the_match' => $m['man_of_the_match'],
            'total_goals' => (int)$m['total_goals'],
            'created_at' => $m['created_at'],
            'goals' => $goals
        ];
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ogCondition = ($driver === 'pgsql') ? "is_own_goal = FALSE" : "is_own_goal = 0";

    $ballonStmt = $pdo->query("
        SELECT 
            player_name,
            COUNT(*) as total_goals,
            COUNT(DISTINCT match_id) as matches_scored_in
        FROM match_history_goals
        WHERE {$ogCondition}
        GROUP BY player_name
        ORDER BY total_goals DESC, matches_scored_in DESC, player_name ASC
    ");
    $rawBallon = $ballonStmt->fetchAll(PDO::FETCH_ASSOC);

    $ballonDor = [];
    $rank = 1;
    foreach ($rawBallon as $row) {
        $ballonDor[] = [
            'rank' => $rank++,
            'player_name' => $row['player_name'],
            'goals' => (int)$row['total_goals'],
            'matches_scored_in' => (int)$row['matches_scored_in']
        ];
    }

    $motmStmt = $pdo->query("
        SELECT 
            man_of_the_match as player_name,
            COUNT(*) as total_awards
        FROM match_history
        WHERE man_of_the_match IS NOT NULL 
          AND man_of_the_match != '' 
          AND man_of_the_match NOT IN ('None', 'Match Drawn', 'Draw')
        GROUP BY man_of_the_match
        ORDER BY total_awards DESC, player_name ASC
    ");
    $rawMotm = $motmStmt->fetchAll(PDO::FETCH_ASSOC);

    $motmLeaderboard = [];
    $rankMotm = 1;
    foreach ($rawMotm as $row) {
        $motmLeaderboard[] = [
            'rank' => $rankMotm++,
            'player_name' => $row['player_name'],
            'awards' => (int)$row['total_awards']
        ];
    }

    $totalMatchesCount = count($formattedMatches);
    $avgGoals = ($totalMatchesCount > 0) ? round($totalGoalsCount / $totalMatchesCount, 1) : 0;

    echo json_encode([
        'success' => true,
        'matches' => $formattedMatches,
        'ballon_dor' => $ballonDor,
        'motm_leaderboard' => $motmLeaderboard,
        'stats' => [
            'total_matches' => $totalMatchesCount,
            'total_goals' => $totalGoalsCount,
            'avg_goals_per_match' => $avgGoals
        ]
    ]);
}

/**
 * Admin: update Man of the Match on historical match
 */
function handleAdminSetMotm(PDO $pdo): void {
    ensureMatchHistoryTables($pdo);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $matchId = (int)($input['match_id'] ?? ($_POST['match_id'] ?? 0));
    $playerName = trim($input['player_name'] ?? ($_POST['player_name'] ?? ''));

    if ($matchId <= 0 || empty($playerName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid match ID and player name are required.']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE match_history SET man_of_the_match = ? WHERE id = ?");
    $stmt->execute([$playerName, $matchId]);

    echo json_encode([
        'success' => true,
        'message' => "Man of the Match updated to {$playerName}!",
        'match_id' => $matchId,
        'man_of_the_match' => $playerName
    ]);
}

/**
 * Admin: delete match from history
 */
function handleAdminDeleteMatch(PDO $pdo): void {
    ensureMatchHistoryTables($pdo);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $matchId = (int)($input['match_id'] ?? ($_POST['match_id'] ?? 0));

    if ($matchId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid match ID is required.']);
        return;
    }

    $pdo->prepare("DELETE FROM match_history_goals WHERE match_id = ?")->execute([$matchId]);
    $stmt = $pdo->prepare("DELETE FROM match_history WHERE id = ?");
    $stmt->execute([$matchId]);

    echo json_encode([
        'success' => true,
        'message' => 'Match deleted from history.',
        'match_id' => $matchId
    ]);
}
