<?php
/**
 * Turf Teams - Match State & Scoreboard API Endpoint
 * Handles live polling, server-timestamp sync, match lifecycle, and goal management.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Non-blocking session check: read session and close file lock immediately to prevent request queuing
$isAdminAuth = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    $isAdminAuth = !empty($_SESSION[ADMIN_SESSION_KEY]);
    session_write_close();
} else {
    $isAdminAuth = !empty($_SESSION[ADMIN_SESSION_KEY]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Helper to check admin authentication
function isAdminAuthenticated(): bool {
    global $isAdminAuth;
    return $isAdminAuth;
}

try {
    $pdo = getDb();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        handleGetMatchState($pdo);
    } elseif ($method === 'POST') {
        handlePostMatchAction($pdo);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    $msg = $e->getMessage();
    if (stripos($msg, 'match_state') !== false || stripos($msg, 'goals') !== false) {
        $msg = "Database tables 'match_state' or 'goals' are missing. Please run supabase_migration.sql in your Supabase SQL Editor.";
    }
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $msg]);
}

/**
 * Build complete match data payload.
 */
function buildMatchPayload(PDO $pdo): array {
    // 1. Fetch match_state row (id = 1)
    $stateStmt = $pdo->query("SELECT id, state, started_at, ended_at FROM match_state WHERE id = 1 LIMIT 1");
    $matchState = $stateStmt->fetch();

    if (!$matchState) {
        $pdo->exec("INSERT INTO match_state (id, state, started_at, ended_at) VALUES (1, 'setup', NULL, NULL)");
        $matchState = ['id' => 1, 'state' => 'setup', 'started_at' => null, 'ended_at' => null];
    }

    $state = $matchState['state'] ?? 'setup';
    $startedAt = $matchState['started_at'];
    $endedAt = $matchState['ended_at'];
    $serverTime = time();

    // 2. Calculate elapsed seconds from server timestamp
    $elapsedSeconds = 0;
    if ($state === 'live' && !empty($startedAt)) {
        $elapsedSeconds = max(0, $serverTime - strtotime($startedAt));
    } elseif ($state === 'finished' && !empty($startedAt) && !empty($endedAt)) {
        $elapsedSeconds = max(0, strtotime($endedAt) - strtotime($startedAt));
    }

    // 3. Fetch Teams
    $teamsStmt = $pdo->query("SELECT id, name FROM teams ORDER BY id ASC");
    $teamsRaw = $teamsStmt->fetchAll();

    // 4. Calculate Scores directly from goals table
    $scoresByTeam = [];
    foreach ($teamsRaw as $t) {
        $scoresByTeam[(int)$t['id']] = 0;
    }

    $scoreCountStmt = $pdo->query("SELECT team_id, COUNT(*) as score FROM goals GROUP BY team_id");
    while ($row = $scoreCountStmt->fetch()) {
        $scoresByTeam[(int)$row['team_id']] = (int)$row['score'];
    }

    // Map named team scores
    $rundaScore = 0;
    $makoScore = 0;
    $teamsFormatted = [];
    foreach ($teamsRaw as $t) {
        $tid = (int)$t['id'];
        $score = $scoresByTeam[$tid] ?? 0;
        if (stripos($t['name'], 'runda') !== false) {
            $rundaScore = $score;
        } else {
            $makoScore = $score;
        }
        $teamsFormatted[] = [
            'id' => $tid,
            'name' => $t['name'],
            'score' => $score
        ];
    }

    // 5. Fetch Goals chronologically
    $goalsStmt = $pdo->query("
        SELECT 
            g.id, 
            g.player_id, 
            g.team_id, 
            g.is_own_goal, 
            g.match_minute, 
            g.match_second, 
            g.created_at,
            p.name AS player_name,
            p.team_id AS player_team_id,
            t.name AS credited_team_name
        FROM goals g
        JOIN players p ON g.player_id = p.id
        JOIN teams t ON g.team_id = t.id
        ORDER BY g.match_minute ASC, g.match_second ASC, g.id ASC
    ");
    $goalsRaw = $goalsStmt->fetchAll();

    $goalsFormatted = [];
    foreach ($goalsRaw as $g) {
        $isOwnGoal = (bool)$g['is_own_goal'];
        $goalsFormatted[] = [
            'id' => (int)$g['id'],
            'player_id' => (int)$g['player_id'],
            'player_name' => $g['player_name'],
            'player_team_id' => (int)$g['player_team_id'],
            'team_id' => (int)$g['team_id'],
            'credited_team_name' => $g['credited_team_name'],
            'is_own_goal' => $isOwnGoal,
            'match_minute' => (int)$g['match_minute'],
            'match_second' => (int)$g['match_second'],
            'time_formatted' => sprintf("%02d:%02d", (int)$g['match_minute'], (int)$g['match_second']),
            'minute_label' => (int)$g['match_minute'] . "'",
            'display_label' => $isOwnGoal 
                ? "{$g['match_minute']}' {$g['player_name']} (OG) - credited to {$g['credited_team_name']}"
                : "{$g['match_minute']}' {$g['player_name']} ({$g['credited_team_name']})",
            'created_at' => $g['created_at']
        ];
    }

    // 6. Calculate Top Scorers (Excludes own goals)
    $topScorers = [];
    $scorerCounts = [];
    foreach ($goalsRaw as $g) {
        if ($g['is_own_goal']) continue;
        $pId = (int)$g['player_id'];
        $pName = $g['player_name'];
        if (!isset($scorerCounts[$pId])) {
            $scorerCounts[$pId] = ['id' => $pId, 'name' => $pName, 'goals' => 0];
        }
        $scorerCounts[$pId]['goals']++;
    }

    if (!empty($scorerCounts)) {
        usort($scorerCounts, fn($a, $b) => $b['goals'] <=> $a['goals']);
        $maxGoals = $scorerCounts[0]['goals'];
        if ($maxGoals > 0) {
            foreach ($scorerCounts as $sc) {
                if ($sc['goals'] === $maxGoals) {
                    $topScorers[] = $sc;
                }
            }
        }
    }

    // 7. Picked players count (for 18-player start condition)
    $countPickedStmt = $pdo->query("SELECT COUNT(*) FROM players WHERE team_id IS NOT NULL");
    $pickedCount = (int)$countPickedStmt->fetchColumn();
    $requiredPlayers = 18;
    $remainingToPick = max(0, $requiredPlayers - $pickedCount);
    $canStart = ($pickedCount >= $requiredPlayers && $state === 'setup');

    // 8. Generate WhatsApp share text
    $whatsappText = formatWhatsAppResult($teamsFormatted, $goalsFormatted, $elapsedSeconds, $topScorers, $state);

    return [
        'success' => true,
        'state' => $state,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'server_time' => $serverTime,
        'elapsed_seconds' => $elapsedSeconds,
        'elapsed_formatted' => sprintf("%02d:%02d", floor($elapsedSeconds / 60), $elapsedSeconds % 60),
        'scores' => [
            'runda' => $rundaScore,
            'makongeni' => $makoScore,
        ],
        'teams' => $teamsFormatted,
        'goals' => $goalsFormatted,
        'top_scorers' => $topScorers,
        'picked_players_count' => $pickedCount,
        'required_players_count' => $requiredPlayers,
        'remaining_to_pick' => $remainingToPick,
        'can_start' => $canStart,
        'whatsapp_result' => $whatsappText
    ];
}

/**
 * Handle public GET match state request.
 */
function handleGetMatchState(PDO $pdo): void {
    $payload = buildMatchPayload($pdo);
    echo json_encode($payload);
}

/**
 * Handle protected POST match actions (Admin only).
 */
function handlePostMatchAction(PDO $pdo): void {
    if (!isAdminAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Admin authentication required to perform match actions.']);
        return;
    }

    $rawInput = file_get_contents('php://input');
    $json = json_decode($rawInput, true) ?? [];
    $action = trim($_GET['action'] ?? ($_POST['action'] ?? ($json['action'] ?? '')));

    switch ($action) {
        case 'start_match':
            handleStartMatch($pdo, $json);
            break;

        case 'end_match':
            handleEndMatch($pdo);
            break;

        case 'record_goal':
            handleRecordGoal($pdo, $json);
            break;

        case 'undo_goal':
            handleUndoGoal($pdo);
            break;

        case 'delete_goal':
            handleDeleteGoal($pdo, $json);
            break;

        case 'edit_goal':
            handleEditGoal($pdo, $json);
            break;

        case 'reset_match':
            handleResetMatch($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."]);
            break;
    }
}

/**
 * Start Match: enforces 18 picked players (or admin override force), transitions to 'live', and locks picking.
 */
function handleStartMatch(PDO $pdo, array $jsonInput = []): void {
    $pdo->beginTransaction();
    try {
        // Verify current state
        $stmtState = $pdo->query("SELECT state FROM match_state WHERE id = 1 FOR UPDATE");
        $currentState = $stmtState->fetchColumn() ?: 'setup';

        if ($currentState === 'live') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Match is already live!']);
            return;
        }

        if ($currentState === 'finished') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Match is already finished. Please reset to start a new match.']);
            return;
        }

        // Server-side enforcement: Check all 18 players have picked (unless force is requested)
        $force = !empty($_POST['force'] ?? ($jsonInput['force'] ?? false));
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM players WHERE team_id IS NOT NULL");
        $pickedCount = (int)$stmtCount->fetchColumn();
        $requiredPlayers = 18;

        if ($pickedCount < $requiredPlayers && !$force) {
            $pdo->rollBack();
            $remaining = $requiredPlayers - $pickedCount;
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "Cannot start match: {$remaining} player(s) still need to pick their team ({$pickedCount}/{$requiredPlayers} picked).",
                'can_force' => true,
                'picked_count' => $pickedCount,
                'remaining' => $remaining
            ]);
            return;
        }

        // Transition to live & save server start timestamp
        $updateState = $pdo->prepare("
            UPDATE match_state 
            SET state = 'live', started_at = NOW(), ended_at = NULL 
            WHERE id = 1
        ");
        $updateState->execute();

        // Automatically lock picking so nobody changes sides mid-game
        $updateLock = $pdo->prepare("UPDATE settings SET setting_value = '1' WHERE setting_key = 'picking_locked'");
        $updateLock->execute();

        $pdo->commit();

        $payload = buildMatchPayload($pdo);
        $payload['message'] = 'Match started! Live timer is now running.';
        echo json_encode($payload);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to start match: ' . $e->getMessage()]);
    }
}

/**
 * End Match: transitions to 'finished' and freezes duration.
 */
function handleEndMatch(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $stmtState = $pdo->query("SELECT state, started_at FROM match_state WHERE id = 1 FOR UPDATE");
        $row = $stmtState->fetch();
        $currentState = $row['state'] ?? 'setup';

        if ($currentState !== 'live') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Cannot end match: match is not currently live.']);
            return;
        }

        $updateStmt = $pdo->prepare("
            UPDATE match_state 
            SET state = 'finished', ended_at = NOW() 
            WHERE id = 1
        ");
        $updateStmt->execute();

        $pdo->commit();

        $payload = buildMatchPayload($pdo);
        $payload['message'] = 'Match ended! Final results are now locked in.';
        echo json_encode($payload);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to end match: ' . $e->getMessage()]);
    }
}

/**
 * Record Goal: records normal goal or own goal, supports custom minute and auto-kickoff from setup.
 */
function handleRecordGoal(PDO $pdo, array $jsonInput): void {
    $playerId = (int)($_POST['player_id'] ?? ($jsonInput['player_id'] ?? 0));
    $isOwnGoal = !empty($_POST['is_own_goal'] ?? ($jsonInput['is_own_goal'] ?? false));
    $chosenTeamId = (int)($_POST['team_id'] ?? ($jsonInput['team_id'] ?? 0));

    if ($playerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid player ID is required.']);
        return;
    }

    $pdo->beginTransaction();
    try {
        // Verify match state
        $stmtState = $pdo->query("SELECT state, started_at FROM match_state WHERE id = 1 FOR UPDATE");
        $stateRow = $stmtState->fetch();
        $currentState = $stateRow['state'] ?? 'setup';

        if ($currentState === 'finished') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Match is already finished. Reset match to record new goals.']);
            return;
        }

        // If in setup state, automatically transition to live
        if ($currentState === 'setup' || empty($stateRow['started_at'])) {
            $pdo->exec("UPDATE match_state SET state = 'live', started_at = NOW(), ended_at = NULL WHERE id = 1");
            $pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'picking_locked'");
            $stateRow['state'] = 'live';
            $stateRow['started_at'] = date('Y-m-d H:i:s');
        }

        // Verify player and get their registered team
        $stmtPlayer = $pdo->prepare("SELECT id, name, team_id FROM players WHERE id = ?");
        $stmtPlayer->execute([$playerId]);
        $player = $stmtPlayer->fetch();

        if (!$player) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Player does not exist.']);
            return;
        }

        // If player has not yet picked a team, assign them to chosenTeamId if specified
        if (empty($player['team_id']) && $chosenTeamId > 0) {
            $pdo->prepare("UPDATE players SET team_id = ?, picked_at = NOW() WHERE id = ?")->execute([$chosenTeamId, $playerId]);
            $player['team_id'] = $chosenTeamId;
        }

        if (empty($player['team_id'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Player does not have a team assigned.']);
            return;
        }

        $playerTeamId = (int)$player['team_id'];
        $creditedTeamId = $playerTeamId;

        if ($isOwnGoal) {
            // Own Goal: credited to the OPPOSITE team
            $oppStmt = $pdo->prepare("SELECT id FROM teams WHERE id != ? LIMIT 1");
            $oppStmt->execute([$playerTeamId]);
            $creditedTeamId = (int)$oppStmt->fetchColumn();

            if ($creditedTeamId <= 0) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Could not determine opposing team.']);
                return;
            }
        }

        // Check if custom match minute/second was passed (manual key-in)
        $rawMin = $_POST['match_minute'] ?? ($jsonInput['match_minute'] ?? null);
        $rawSec = $_POST['match_second'] ?? ($jsonInput['match_second'] ?? null);

        if ($rawMin !== null && is_numeric($rawMin) && (int)$rawMin >= 0) {
            $matchMinute = (int)$rawMin;
            $matchSecond = ($rawSec !== null && is_numeric($rawSec) && (int)$rawSec >= 0 && (int)$rawSec < 60) ? (int)$rawSec : 0;
        } else {
            // Calculate match minute and second automatically from server start time
            $startedAtTs = !empty($stateRow['started_at']) ? strtotime($stateRow['started_at']) : time();
            $elapsedSec = max(0, time() - $startedAtTs);
            $matchMinute = (int)floor($elapsedSec / 60);
            $matchSecond = (int)($elapsedSec % 60);
        }

        // Insert goal row
        $stmtInsert = $pdo->prepare("
            INSERT INTO goals (player_id, team_id, is_own_goal, match_minute, match_second, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmtInsert->execute([
            $playerId,
            $creditedTeamId,
            $isOwnGoal ? 1 : 0,
            $matchMinute,
            $matchSecond
        ]);

        $goalId = (int)$pdo->lastInsertId();

        $pdo->commit();

        $payload = buildMatchPayload($pdo);
        $msg = $isOwnGoal 
            ? "Own Goal recorded: {$player['name']} (OG) at {$matchMinute}'"
            : "Goal recorded: {$player['name']} at {$matchMinute}'";
        $payload['message'] = $msg;
        $payload['new_goal_id'] = $goalId;
        echo json_encode($payload);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to record goal: ' . $e->getMessage()]);
    }
}

/**
 * Undo Last Goal: deletes the most recently recorded goal.
 */
function handleUndoGoal(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $lastGoalStmt = $pdo->query("
            SELECT g.id, p.name as player_name, g.match_minute 
            FROM goals g 
            JOIN players p ON g.player_id = p.id 
            ORDER BY g.id DESC LIMIT 1 FOR UPDATE
        ");
        $lastGoal = $lastGoalStmt->fetch();

        if (!$lastGoal) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No goals to undo.']);
            return;
        }

        $delStmt = $pdo->prepare("DELETE FROM goals WHERE id = ?");
        $delStmt->execute([(int)$lastGoal['id']]);

        $pdo->commit();

        $payload = buildMatchPayload($pdo);
        $payload['message'] = "Undid goal: {$lastGoal['player_name']} {$lastGoal['match_minute']}'";
        echo json_encode($payload);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to undo goal: ' . $e->getMessage()]);
    }
}

/**
 * Delete Specific Goal by ID.
 */
function handleDeleteGoal(PDO $pdo, array $jsonInput): void {
    $goalId = (int)($_POST['goal_id'] ?? ($jsonInput['goal_id'] ?? 0));

    if ($goalId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid goal ID is required.']);
        return;
    }

    $delStmt = $pdo->prepare("DELETE FROM goals WHERE id = ?");
    $delStmt->execute([$goalId]);

    $payload = buildMatchPayload($pdo);
    $payload['message'] = 'Goal removed successfully.';
    echo json_encode($payload);
}

/**
 * Edit Goal minute/second if recorded late.
 */
function handleEditGoal(PDO $pdo, array $jsonInput): void {
    $goalId = (int)($_POST['goal_id'] ?? ($jsonInput['goal_id'] ?? 0));
    $minute = isset($_POST['match_minute']) ? (int)$_POST['match_minute'] : (int)($jsonInput['match_minute'] ?? -1);
    $second = isset($_POST['match_second']) ? (int)$_POST['match_second'] : (int)($jsonInput['match_second'] ?? 0);

    if ($goalId <= 0 || $minute < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid goal ID and minute (>= 0) are required.']);
        return;
    }

    $second = max(0, min(59, $second));

    $updateStmt = $pdo->prepare("UPDATE goals SET match_minute = ?, match_second = ? WHERE id = ?");
    $updateStmt->execute([$minute, $second, $goalId]);

    $payload = buildMatchPayload($pdo);
    $payload['message'] = "Goal time updated to {$minute}'";
    echo json_encode($payload);
}

/**
 * Reset Match: clears goals and returns state to 'setup', keeping player teams intact.
 */
function handleResetMatch(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM goals");
        $pdo->exec("UPDATE match_state SET state = 'setup', started_at = NULL, ended_at = NULL WHERE id = 1");
        $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'picking_locked'");
        $pdo->commit();

        $payload = buildMatchPayload($pdo);
        $payload['message'] = 'Match scoreboard reset to setup! Team rosters have been preserved.';
        echo json_encode($payload);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to reset match: ' . $e->getMessage()]);
    }
}

/**
 * Format clean WhatsApp match results block.
 */
function formatWhatsAppResult(array $teams, array $goals, int $elapsedSeconds, array $topScorers, string $state): string {
    if (count($teams) < 2) return '';

    $team1 = $teams[0];
    $team2 = $teams[1];
    $durationStr = sprintf("%02d:%02d", floor($elapsedSeconds / 60), $elapsedSeconds % 60);

    // Group scorers per credited team
    $t1Scorers = [];
    $t2Scorers = [];

    foreach ($goals as $g) {
        $label = $g['is_own_goal'] ? "{$g['player_name']} (OG) {$g['match_minute']}'" : "{$g['player_name']} {$g['match_minute']}'";
        if ($g['team_id'] === $team1['id']) {
            $t1Scorers[] = $label;
        } else {
            $t2Scorers[] = $label;
        }
    }

    $t1Str = !empty($t1Scorers) ? implode(', ', $t1Scorers) : 'None';
    $t2Str = !empty($t2Scorers) ? implode(', ', $t2Scorers) : 'None';

    $statusHeader = ($state === 'finished') ? "FT {$durationStr}" : (($state === 'live') ? "LIVE {$durationStr}" : "Upcoming");

    $out = "⚽ TURF MATCH SCOREBOARD ({$statusHeader})\n";
    $out .= "------------------------------------\n";
    $out .= "🟢 {$team1['name']} {$team1['score']} - {$team2['score']} {$team2['name']} 🔵\n\n";
    $out .= "🟢 {$team1['name']}: {$t1Str}\n";
    $out .= "🔵 {$team2['name']}: {$t2Str}\n";

    if ($state === 'finished') {
        if ($team1['score'] > $team2['score']) {
            $out .= "\n🏆 WINNER: {$team1['name']}!\n";
        } elseif ($team2['score'] > $team1['score']) {
            $out .= "\n🏆 WINNER: {$team2['name']}!\n";
        } else {
            $out .= "\n🤝 RESULT: Match Drawn!\n";
        }
    }

    if (!empty($topScorers)) {
        $names = array_map(fn($s) => $s['name'], $topScorers);
        $goalsNum = $topScorers[0]['goals'];
        $suffix = $goalsNum > 1 ? "goals" : "goal";
        $out .= "⭐ Top Scorer: " . implode(', ', $names) . " ({$goalsNum} {$suffix})\n";
    }

    return trim($out);
}
