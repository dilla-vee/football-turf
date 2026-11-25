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

    // 3. Fetch Teams based on active match mode (2 or 3 teams)
    ensureDatabaseTeamSupport($pdo);
    $teamMode = getTeamMode($pdo);
    $caps = getTeamCaps($teamMode);

    $teamsStmt = $pdo->prepare("SELECT id, name FROM teams ORDER BY id ASC LIMIT ?");
    $teamsStmt->bindValue(1, $caps['team_count'], PDO::PARAM_INT);
    $teamsStmt->execute();
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

    // Map team scores
    $rundaScore = 0;
    $makoScore = 0;
    $teamsFormatted = [];
    foreach ($teamsRaw as $t) {
        $tid = (int)$t['id'];
        $score = $scoresByTeam[$tid] ?? 0;
        if ($tid === 1 || stripos($t['name'], 'runda') !== false) {
            $rundaScore = $score;
        } elseif ($tid === 2 || stripos($t['name'], 'makongeni') !== false) {
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
        'team_mode' => $teamMode,
        'team_caps' => $caps,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'server_time' => $serverTime,
        'elapsed_seconds' => $elapsedSeconds,
        'elapsed_formatted' => sprintf("%02d:%02d", floor($elapsedSeconds / 60), $elapsedSeconds % 60),
        'scores' => [
            'runda' => $rundaScore,
            'makongeni' => $makoScore,
        ],
        'scores_by_id' => $scoresByTeam,
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
            handleResetMatch($pdo, $json);
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
            // Own Goal: credited to the opposing team or chosen team
            if ($chosenTeamId > 0 && $chosenTeamId !== $playerTeamId) {
                $creditedTeamId = $chosenTeamId;
            } else {
                $teamMode = getTeamMode($pdo);
                if ($teamMode === '2_teams') {
                    $creditedTeamId = ($playerTeamId === 1) ? 2 : 1;
                } else {
                    $oppStmt = $pdo->prepare("SELECT id FROM teams WHERE id != ? ORDER BY id ASC LIMIT 1");
                    $oppStmt->execute([$playerTeamId]);
                    $creditedTeamId = (int)$oppStmt->fetchColumn();
                }
            }

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
 * Reset Match / Start New Match:
 * Archives the completed match into persistent Match History & Ballon d'Or records,
 * clears active goals, and returns state to 'setup' while preserving player teams.
 */
function handleResetMatch(PDO $pdo, array $jsonInput = []): void {
    ensureMatchHistoryTables($pdo);
    $pdo->beginTransaction();
    try {
        // 1. Fetch current match state
        $stmtState = $pdo->query("SELECT state, started_at, ended_at FROM match_state WHERE id = 1 FOR UPDATE");
        $stateRow = $stmtState->fetch();
        $currentState = $stateRow['state'] ?? 'setup';
        $startedAt = $stateRow['started_at'] ?? null;
        $endedAt = $stateRow['ended_at'] ?? null;

        // 2. Fetch all current goals
        $goalsStmt = $pdo->query("
            SELECT 
                g.id, g.player_id, g.team_id, g.is_own_goal, g.match_minute, g.match_second,
                p.name AS player_name, t.name AS team_name
            FROM goals g
            JOIN players p ON g.player_id = p.id
            JOIN teams t ON g.team_id = t.id
            ORDER BY g.match_minute ASC, g.match_second ASC, g.id ASC
        ");
        $goals = $goalsStmt->fetchAll(PDO::FETCH_ASSOC);

        $archived = false;
        $matchCode = null;
        $motm = null;

        // Archive if goals were scored OR if match was live/finished
        if (!empty($goals) || $currentState === 'live' || $currentState === 'finished') {
            $teamMode = getTeamMode($pdo);
            $caps = getTeamCaps($teamMode);

            // Calculate duration
            $durationSec = 0;
            if (!empty($startedAt) && !empty($endedAt)) {
                $durationSec = max(0, strtotime($endedAt) - strtotime($startedAt));
            } elseif (!empty($startedAt)) {
                $durationSec = max(0, time() - strtotime($startedAt));
            }
            $durationFormatted = sprintf("%02d:%02d", floor($durationSec / 60), $durationSec % 60);

            // Fetch teams & scores
            $teamsStmt = $pdo->prepare("SELECT id, name FROM teams ORDER BY id ASC LIMIT ?");
            $teamsStmt->bindValue(1, $caps['team_count'], PDO::PARAM_INT);
            $teamsStmt->execute();
            $teamsRaw = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

            $scoresByTeam = [];
            foreach ($teamsRaw as $t) {
                $scoresByTeam[(int)$t['id']] = 0;
            }
            foreach ($goals as $g) {
                $tid = (int)$g['team_id'];
                if (isset($scoresByTeam[$tid])) {
                    $scoresByTeam[$tid]++;
                }
            }

            $teamsSummary = [];
            foreach ($teamsRaw as $t) {
                $tid = (int)$t['id'];
                $teamsSummary[] = [
                    'id' => $tid,
                    'name' => $t['name'],
                    'score' => $scoresByTeam[$tid] ?? 0
                ];
            }

            // Determine winner
            $winnerTeam = 'Draw';
            if (count($teamsSummary) >= 2) {
                $sorted = $teamsSummary;
                usort($sorted, fn($a, $b) => $b['score'] <=> $a['score']);
                if ($sorted[0]['score'] > $sorted[1]['score']) {
                    $winnerTeam = $sorted[0]['name'];
                }
            }

            // Calculate top scorer
            $playerGoals = [];
            foreach ($goals as $g) {
                if (!empty($g['is_own_goal'])) continue;
                $pName = $g['player_name'];
                $playerGoals[$pName] = ($playerGoals[$pName] ?? 0) + 1;
            }
            arsort($playerGoals);
            $topScorerName = null;
            $topScorerGoals = 0;
            if (!empty($playerGoals)) {
                $topScorerName = array_key_first($playerGoals);
                $topScorerGoals = reset($playerGoals);
            }

            // Man of the Match: override from admin input, or default to top scorer / winner MVP
            $customMotm = trim($_POST['man_of_the_match'] ?? ($jsonInput['man_of_the_match'] ?? ''));
            if (!empty($customMotm)) {
                $motm = $customMotm;
            } elseif (!empty($topScorerName)) {
                $motm = $topScorerName;
            } elseif ($winnerTeam !== 'Draw') {
                $motm = $winnerTeam . ' MVP';
            } else {
                $motm = 'Match Drawn';
            }

            $matchCode = 'TURF-' . date('Ymd-His');
            $totalGoals = count($goals);
            $teamsSummaryJson = json_encode($teamsSummary);

            // Insert into match_history
            $insHistory = $pdo->prepare("
                INSERT INTO match_history (
                    match_code, team_mode, started_at, ended_at, duration_seconds,
                    duration_formatted, teams_summary, winner_team_name, top_scorer_name,
                    top_scorer_goals, man_of_the_match, total_goals, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insHistory->execute([
                $matchCode,
                $teamMode,
                $startedAt,
                $endedAt ?: ($currentState === 'live' ? date('Y-m-d H:i:s') : null),
                $durationSec,
                $durationFormatted,
                $teamsSummaryJson,
                $winnerTeam,
                $topScorerName,
                $topScorerGoals,
                $motm,
                $totalGoals
            ]);

            $matchHistoryId = (int)$pdo->lastInsertId();

            // Insert each goal into match_history_goals
            $insGoal = $pdo->prepare("
                INSERT INTO match_history_goals (
                    match_id, player_id, player_name, team_id, team_name,
                    is_own_goal, match_minute, match_second, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            foreach ($goals as $g) {
                $insGoal->execute([
                    $matchHistoryId,
                    $g['player_id'],
                    $g['player_name'],
                    $g['team_id'],
                    $g['team_name'],
                    !empty($g['is_own_goal']) ? 1 : 0,
                    (int)$g['match_minute'],
                    (int)$g['match_second']
                ]);
            }

            $archived = true;
        }

        // 3. Clear active goals & reset match_state to 'setup'
        $pdo->exec("DELETE FROM goals");
        $pdo->exec("UPDATE match_state SET state = 'setup', started_at = NULL, ended_at = NULL WHERE id = 1");
        $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'picking_locked'");

        $pdo->commit();

        $payload = buildMatchPayload($pdo);
        $payload['archived'] = $archived;
        $payload['archived_match_code'] = $matchCode;
        $payload['man_of_the_match'] = $motm;

        if ($archived) {
            $payload['message'] = "Match results archived to history! ({$matchCode}) Ballon d'Or & MOTM records updated. New match is ready.";
        } else {
            $payload['message'] = 'Match scoreboard reset to setup! Team rosters have been preserved.';
        }

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

    $durationStr = sprintf("%02d:%02d", floor($elapsedSeconds / 60), $elapsedSeconds % 60);
    $statusHeader = ($state === 'finished') ? "FT {$durationStr}" : (($state === 'live') ? "LIVE {$durationStr}" : "Upcoming");

    // Group scorers per team
    $scorersByTeam = [];
    foreach ($teams as $t) {
        $scorersByTeam[$t['id']] = [];
    }

    foreach ($goals as $g) {
        $label = $g['is_own_goal'] ? "{$g['player_name']} (OG) {$g['match_minute']}'" : "{$g['player_name']} {$g['match_minute']}'";
        if (isset($scorersByTeam[$g['team_id']])) {
            $scorersByTeam[$g['team_id']][] = $label;
        }
    }

    $out = "TURF MATCH SCOREBOARD ({$statusHeader})\n";
    $out .= "------------------------------------\n";

    // Summary line
    if (count($teams) === 2) {
        $out .= "{$teams[0]['name']} {$teams[0]['score']} - {$teams[1]['score']} {$teams[1]['name']}\n\n";
    } else {
        $scoreParts = [];
        foreach ($teams as $t) {
            $scoreParts[] = "{$t['name']}: {$t['score']}";
        }
        $out .= implode(' | ', $scoreParts) . "\n\n";
    }

    // Team detail lines
    foreach ($teams as $t) {
        $sList = $scorersByTeam[$t['id']] ?? [];
        $sStr = !empty($sList) ? implode(', ', $sList) : 'None';
        $out .= "• {$t['name']} ({$t['score']}): {$sStr}\n";
    }

    if ($state === 'finished') {
        // Find highest score
        usort($teams, fn($a, $b) => $b['score'] <=> $a['score']);
        if ($teams[0]['score'] > $teams[1]['score']) {
            $out .= "\nWINNER: {$teams[0]['name']}!\n";
        } else {
            $out .= "\nRESULT: Match Drawn!\n";
        }
    }

    if (!empty($topScorers)) {
        $names = array_map(fn($s) => $s['name'], $topScorers);
        $goalsNum = $topScorers[0]['goals'];
        $suffix = $goalsNum > 1 ? "goals" : "goal";
        $out .= "Top Scorer: " . implode(', ', $names) . " ({$goalsNum} {$suffix})\n";
    }

    return trim($out);
}
