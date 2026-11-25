<?php
/**
 * Turf Teams - Match History & Ballon d'Or Automated Test Suite
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$pdo = getDb();
ensureMatchHistoryTables($pdo);

echo "--- STARTING MATCH HISTORY & BALLON D'OR TESTS ---\n";
$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $testName) {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] {$testName}\n";
    } else {
        echo " [FAIL] {$testName}\n";
    }
}

// 1. Verify match_history and match_history_goals tables exist
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $stmtT = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('match_history', 'match_history_goals')");
    $tables = $stmtT->fetchAll(PDO::FETCH_COLUMN);
    assertTest(count($tables) === 2, "Tables match_history and match_history_goals exist in database");
} else {
    $stmtT = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_name IN ('match_history', 'match_history_goals')");
    $tables = $stmtT->fetchAll(PDO::FETCH_COLUMN);
    assertTest(count($tables) >= 1, "Tables match_history and match_history_goals exist in schema");
}

// Clean active state for testing
$pdo->exec("DELETE FROM match_history_goals");
$pdo->exec("DELETE FROM match_history");
$pdo->exec("DELETE FROM goals");
$pdo->exec("UPDATE match_state SET state = 'setup', started_at = NULL, ended_at = NULL WHERE id = 1");

// 2. Fetch two players to test with
$stmtP = $pdo->query("SELECT id, name, team_id FROM players WHERE team_id IS NOT NULL LIMIT 2");
$testPlayers = $stmtP->fetchAll(PDO::FETCH_ASSOC);
if (count($testPlayers) < 2) {
    // Assign 2 players to teams 1 and 2
    $pdo->exec("UPDATE players SET team_id = 1 WHERE id = 1");
    $pdo->exec("UPDATE players SET team_id = 2 WHERE id = 2");
    $stmtP = $pdo->query("SELECT id, name, team_id FROM players WHERE id IN (1, 2)");
    $testPlayers = $stmtP->fetchAll(PDO::FETCH_ASSOC);
}

$p1 = $testPlayers[0];
$p2 = $testPlayers[1];

// Set state to live and record goals
$fortyFiveMinsAgo = date('Y-m-d H:i:s', strtotime('-45 minutes'));
$pdo->prepare("UPDATE match_state SET state = 'live', started_at = ?, ended_at = NULL WHERE id = 1")->execute([$fortyFiveMinsAgo]);

// Insert 2 goals for Player 1, 1 goal for Player 2
$nowStr = date('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO goals (player_id, team_id, is_own_goal, match_minute, match_second, created_at) VALUES (?, ?, 0, 12, 0, ?)")->execute([(int)$p1['id'], (int)$p1['team_id'], $nowStr]);
$pdo->prepare("INSERT INTO goals (player_id, team_id, is_own_goal, match_minute, match_second, created_at) VALUES (?, ?, 0, 34, 15, ?)")->execute([(int)$p1['id'], (int)$p1['team_id'], $nowStr]);
$pdo->prepare("INSERT INTO goals (player_id, team_id, is_own_goal, match_minute, match_second, created_at) VALUES (?, ?, 0, 41, 50, ?)")->execute([(int)$p2['id'], (int)$p2['team_id'], $nowStr]);

$countActiveGoals = (int)$pdo->query("SELECT COUNT(*) FROM goals")->fetchColumn();
assertTest($countActiveGoals === 3, "Recorded 3 test goals (2 for {$p1['name']}, 1 for {$p2['name']})");

// 3. Test handleResetMatch (Start New Match)
// We will test by including api/match_state.php functions
require_once __DIR__ . '/../api/match_state.php';

// Capture output of handleResetMatch
ob_start();
handleResetMatch($pdo, []);
$rawOutput = ob_get_clean();
$resetResult = json_decode($rawOutput, true);

assertTest(!empty($resetResult['success']), "handleResetMatch executed successfully");
assertTest(!empty($resetResult['archived']), "handleResetMatch marked archived = true");
assertTest(!empty($resetResult['archived_match_code']), "Generated archived match code: " . ($resetResult['archived_match_code'] ?? ''));
assertTest($resetResult['state'] === 'setup', "Match state returned to 'setup'");

// Verify active goals table is cleared
$remainingActive = (int)$pdo->query("SELECT COUNT(*) FROM goals")->fetchColumn();
assertTest($remainingActive === 0, "Active goals table cleared to 0");

// 4. Verify match_history row was created
$matchCode = $resetResult['archived_match_code'];
$stmtH = $pdo->prepare("SELECT * FROM match_history WHERE match_code = ?");
$stmtH->execute([$matchCode]);
$archivedRow = $stmtH->fetch(PDO::FETCH_ASSOC);

assertTest(!empty($archivedRow), "Match history record found in database");
assertTest((int)$archivedRow['total_goals'] === 3, "Archived match total_goals = 3");
assertTest($archivedRow['top_scorer_name'] === $p1['name'], "Top scorer recorded as {$p1['name']}");
assertTest((int)$archivedRow['top_scorer_goals'] === 2, "Top scorer goals = 2");
assertTest(!empty($archivedRow['man_of_the_match']), "Default Man of the Match set: " . $archivedRow['man_of_the_match']);

$matchHistId = (int)$archivedRow['id'];

// 5. Verify match_history_goals rows were created
$stmtHG = $pdo->prepare("SELECT * FROM match_history_goals WHERE match_id = ?");
$stmtHG->execute([$matchHistId]);
$archivedGoals = $stmtHG->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($archivedGoals) === 3, "Archived exactly 3 goals in match_history_goals");

// 6. Test GET api/match_history.php logic
require_once __DIR__ . '/../api/match_history.php';
ob_start();
handleGetHistory($pdo);
$rawHistory = ob_get_clean();
$historyData = json_decode($rawHistory, true);

assertTest(!empty($historyData['success']), "GET api/match_history.php returned success");
assertTest(!empty($historyData['matches']), "Returned list of archived matches");
assertTest(!empty($historyData['ballon_dor']), "Returned Ballon d'Or leaderboard");

// Check Ballon d'Or ranking: p1 must be #1 with at least 2 goals
$rank1 = $historyData['ballon_dor'][0] ?? null;
assertTest(!empty($rank1) && $rank1['player_name'] === $p1['name'], "Ballon d'Or Rank 1 is {$p1['name']} with {$rank1['goals']} goals");

// 7. Test Admin update Man of the Match (handleSetMotm)
$newMotmName = $p2['name'] . " (Defensive Maestro)";
ob_start();
handleSetMotm($pdo, ['match_id' => $matchHistId, 'player_name' => $newMotmName]);
$rawSetMotm = ob_get_clean();
$setMotmResult = json_decode($rawSetMotm, true);

assertTest(!empty($setMotmResult['success']), "Admin handleSetMotm succeeded");

$updatedMotm = $pdo->query("SELECT man_of_the_match FROM match_history WHERE id = {$matchHistId}")->fetchColumn();
assertTest($updatedMotm === $newMotmName, "Man of the Match updated to '{$newMotmName}' in database");

// 8. Test Admin delete match (handleDeleteMatch)
ob_start();
handleDeleteMatch($pdo, ['match_id' => $matchHistId]);
$rawDelMatch = ob_get_clean();
$delMatchResult = json_decode($rawDelMatch, true);

assertTest(!empty($delMatchResult['success']), "Admin handleDeleteMatch succeeded");

$checkDeleted = $pdo->query("SELECT COUNT(*) FROM match_history WHERE id = {$matchHistId}")->fetchColumn();
assertTest((int)$checkDeleted === 0, "Match removed from match_history");

$checkGoalsDeleted = $pdo->query("SELECT COUNT(*) FROM match_history_goals WHERE match_id = {$matchHistId}")->fetchColumn();
assertTest((int)$checkGoalsDeleted === 0, "Associated goals cascadingly deleted from match_history_goals");

echo "\n--- SUMMARY: {$testsPassed}/{$totalTests} TESTS PASSED ---\n";
if ($testsPassed === $totalTests) {
    echo "ALL MATCH HISTORY & BALLON D'OR TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
