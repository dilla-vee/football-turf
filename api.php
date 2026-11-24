<?php
/**
 * Turf Teams - Player API Endpoint
 * Handles live team polling, paired rival opponent balancing, and race-condition-safe atomic team picking.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Handle live status polling for the player page.
 * Returns only slot counts relevant to the viewer's tier, without revealing tier badges/info.
 */
function handleStatus(PDO $pdo, array $jsonInput = []): void {
    $token = trim($_GET['t'] ?? ($_POST['t'] ?? ($jsonInput['t'] ?? '')));

    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing player token', 'code' => 'INVALID_TOKEN']);
        return;
    }

    // Lookup viewer player by token
    $stmt = $pdo->prepare("SELECT id, name, tier, team_id, picked_at FROM players WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $viewer = $stmt->fetch();

    if (!$viewer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Invalid or unknown player token', 'code' => 'INVALID_TOKEN']);
        return;
    }

    // Check if picking is globally locked
    $stmtLock = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'picking_locked'");
    $stmtLock->execute();
    $pickingLocked = ($stmtLock->fetchColumn() === '1');

    // Check if viewer has a paired rival opponent who has already picked
    $rival = findRivalPlayer($pdo, $viewer['name']);
    $rivalTeamId = null;
    $rivalName = null;
    if ($rival) {
        $rivalName = $rival['name'];
        if ($rival['team_id'] !== null) {
            $rivalTeamId = (int)$rival['team_id'];
        }
    }

    // Fetch teams
    $teamsStmt = $pdo->query("SELECT id, name FROM teams ORDER BY id ASC");
    $teams = $teamsStmt->fetchAll();

    $viewerTier = $viewer['tier'];
    $maxForViewerTier = ($viewerTier === 'skilled') ? MAX_SKILLED_PER_TEAM : MAX_REGULAR_PER_TEAM;

    $teamsData = [];
    $selectableCount = 0;
    $singleSelectableTeamId = null;
    $pickedTeamName = null;

    foreach ($teams as $team) {
        $teamId = (int)$team['id'];
        $teamName = $team['name'];

        if ($viewer['team_id'] !== null && (int)$viewer['team_id'] === $teamId) {
            $pickedTeamName = $teamName;
        }

        // Count current players of viewer's tier in this team
        $countTierStmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id = ? AND tier = ?");
        $countTierStmt->execute([$teamId, $viewerTier]);
        $currentTierCount = (int)$countTierStmt->fetchColumn();

        // Total players on team
        $countTotalStmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id = ?");
        $countTotalStmt->execute([$teamId]);
        $totalTeamMembers = (int)$countTotalStmt->fetchColumn();

        // Fetch roster: names ONLY (No tier data to preserve privacy)
        $rosterStmt = $pdo->prepare("SELECT name, picked_at FROM players WHERE team_id = ? ORDER BY picked_at ASC, name ASC");
        $rosterStmt->execute([$teamId]);
        $rosterRows = $rosterStmt->fetchAll();

        $roster = [];
        foreach ($rosterRows as $row) {
            $roster[] = [
                'name' => $row['name'],
                'picked_at' => $row['picked_at']
            ];
        }

        // Rival constraint: cannot join the same team as your paired opponent
        $blockedByRival = ($rivalTeamId !== null && $teamId === $rivalTeamId);

        $spotsLeft = max(0, $maxForViewerTier - $currentTierCount);
        if ($blockedByRival) {
            $spotsLeft = 0;
        }

        $isFullForViewer = ($spotsLeft <= 0 || $blockedByRival);
        $isSelectable = (!$pickingLocked && $viewer['team_id'] === null && !$isFullForViewer);

        if ($isSelectable) {
            $selectableCount++;
            $singleSelectableTeamId = $teamId;
        }

        $teamsData[] = [
            'id' => $teamId,
            'name' => $teamName,
            'total_players' => $totalTeamMembers,
            'max_team_size' => TOTAL_PLAYERS_PER_TEAM,
            'spots_left' => $spotsLeft,
            'is_full_for_viewer' => $isFullForViewer,
            'blocked_by_rival' => $blockedByRival,
            'rival_name' => $blockedByRival ? $rivalName : null,
            'is_selectable' => $isSelectable,
            'roster' => $roster
        ];
    }

    // If only one team is selectable, identify it so frontend can auto-show it
    $autoAssignedTeamId = ($selectableCount === 1) ? $singleSelectableTeamId : null;

    echo json_encode([
        'success' => true,
        'player' => [
            'name' => $viewer['name'],
            'has_picked' => ($viewer['team_id'] !== null),
            'picked_team_id' => $viewer['team_id'] !== null ? (int)$viewer['team_id'] : null,
            'picked_team_name' => $pickedTeamName,
            'picked_at' => $viewer['picked_at'],
            'rival_name' => $rivalName
        ],
        'picking_locked' => $pickingLocked,
        'auto_assigned_team_id' => $autoAssignedTeamId,
        'teams' => $teamsData
    ]);
}

/**
 * Handle race-condition safe atomic team selection using MySQL/PostgreSQL row locking.
 */
function handlePick(PDO $pdo, array $jsonInput = []): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        return;
    }

    // Read payload (supports JSON, POST or GET fallback)
    $token = trim($jsonInput['t'] ?? ($_POST['t'] ?? ($_GET['t'] ?? '')));
    $requestedTeamId = (int)($jsonInput['team_id'] ?? ($_POST['team_id'] ?? ($_GET['team_id'] ?? 0)));

    if (empty($token) || $requestedTeamId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Player token and valid team ID are required.']);
        return;
    }

    // Begin ACID transaction with row-level locks
    $pdo->beginTransaction();

    try {
        // 1. Lock settings row to verify picking lock state
        $stmtLock = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'picking_locked' FOR UPDATE");
        $stmtLock->execute();
        $isLocked = ($stmtLock->fetchColumn() === '1');

        if ($isLocked) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Picking has been locked by the admin. No more selections are allowed.'
            ]);
            return;
        }

        // 2. Lock and retrieve player record by token
        $playerStmt = $pdo->prepare("SELECT id, name, tier, team_id FROM players WHERE token = ? FOR UPDATE");
        $playerStmt->execute([$token]);
        $player = $playerStmt->fetch();

        if (!$player) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Player token not recognized.']);
            return;
        }

        // 3. Enforce single-pick constraint: cannot pick twice
        if ($player['team_id'] !== null) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'You have already picked a team! Team selections are permanent and locked.'
            ]);
            return;
        }

        // 4. Lock target team row in teams table
        // This serializes all concurrent picks for this team, preventing race conditions.
        $teamStmt = $pdo->prepare("SELECT id, name FROM teams WHERE id = ? FOR UPDATE");
        $teamStmt->execute([$requestedTeamId]);
        $team = $teamStmt->fetch();

        if (!$team) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'The selected team does not exist.']);
            return;
        }

        // 5. Enforce Paired Rival Opponent Constraint
        // Paired skilled players (e.g. Jimmy vs Oliver) can NEVER join the same team.
        $rival = findRivalPlayer($pdo, $player['name']);
        if ($rival && $rival['team_id'] !== null && (int)$rival['team_id'] === $requestedTeamId) {
            $pdo->rollBack();
            $otherTeamStmt = $pdo->prepare("SELECT name FROM teams WHERE id != ? LIMIT 1");
            $otherTeamStmt->execute([$requestedTeamId]);
            $otherTeamName = $otherTeamStmt->fetchColumn() ?: 'the opposing team';

            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => "{$player['name']} and {$rival['name']} are paired opponents and cannot be on the same team! You must join {$otherTeamName}."
            ]);
            return;
        }

        $tier = $player['tier'];
        $maxAllowed = ($tier === 'skilled') ? MAX_SKILLED_PER_TEAM : MAX_REGULAR_PER_TEAM;

        // 6. Count existing players in this team for this tier
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id = ? AND tier = ?");
        $countStmt->execute([$requestedTeamId, $tier]);
        $currentTierCount = (int)$countStmt->fetchColumn();

        if ($currentTierCount >= $maxAllowed) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => "{$team['name']} is already full for your tier. Only the other team has open spots."
            ]);
            return;
        }

        // 7. Atomically update player record with team_id IS NULL guard
        $updateStmt = $pdo->prepare("
            UPDATE players 
            SET team_id = :team_id, picked_at = NOW() 
            WHERE id = :player_id AND team_id IS NULL
        ");
        $updateStmt->execute([
            ':team_id' => $requestedTeamId,
            ':player_id' => $player['id']
        ]);

        if ($updateStmt->rowCount() !== 1) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Concurrent update conflict. Your pick could not be completed.'
            ]);
            return;
        }

        // 8. Commit transaction
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Successfully joined {$team['name']}!",
            'team_id' => (int)$team['id'],
            'team_name' => $team['name'],
            'player_name' => $player['name']
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Request Dispatcher
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];

$action = $_GET['action'] 
    ?? ($_POST['action'] 
    ?? ($jsonInput['action'] 
    ?? 'status'));

try {
    $pdo = getDb();

    if ($action === 'status') {
        handleStatus($pdo, $jsonInput);
    } elseif ($action === 'pick') {
        handlePick($pdo, $jsonInput);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action requested']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
