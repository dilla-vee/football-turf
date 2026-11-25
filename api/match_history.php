<?php
/**
 * Turf Teams - Match History & Ballon d'Or API
 * Public & Admin API for match archives, Ballon d'Or top scorer leaderboard,
 * and Man of the Match (MOTM) hall of fame records.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Session check for admin operations
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

try {
    $pdo = getDb();
    ensureMatchHistoryTables($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        handleGetHistory($pdo);
    } elseif ($method === 'POST') {
        handlePostHistoryAction($pdo, $isAdminAuth);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Handle GET request for match history and Ballon d'Or leaderboard.
 */
function handleGetHistory(PDO $pdo): void {
    // 1. Fetch all archived matches
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

    // Fetch goals for each match
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

    // 2. Compute Ballon d'Or (All-Time Goal Scorers Leaderboard)
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

    // 3. Compute Man of the Match (MOTM Leaderboard)
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
 * Handle POST actions (Admin only).
 */
function handlePostHistoryAction(PDO $pdo, bool $isAdminAuth): void {
    if (!$isAdminAuth) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Admin authentication required.']);
        return;
    }

    $rawInput = file_get_contents('php://input');
    $json = json_decode($rawInput, true) ?? [];
    $action = trim($_GET['action'] ?? ($_POST['action'] ?? ($json['action'] ?? '')));

    switch ($action) {
        case 'set_motm':
            handleSetMotm($pdo, $json);
            break;

        case 'delete_match':
            handleDeleteMatch($pdo, $json);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'."]);
            break;
    }
}

/**
 * Set or change the Man of the Match for a historical match.
 */
function handleSetMotm(PDO $pdo, array $json): void {
    $matchId = (int)($_POST['match_id'] ?? ($json['match_id'] ?? 0));
    $playerName = trim($_POST['player_name'] ?? ($json['player_name'] ?? ''));

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
 * Delete a match from history (Admin only).
 */
function handleDeleteMatch(PDO $pdo, array $json): void {
    $matchId = (int)($_POST['match_id'] ?? ($json['match_id'] ?? 0));

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
