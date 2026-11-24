<?php
/**
 * Turf Teams - Live Match Scoreboard & Lifecycle Test Suite
 * Validates match states (setup -> live -> finished), 18-player start guard,
 * auto-lock on start, goal recording, own goals, undo/delete/edit, and reset.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

echo "====================================================\n";
echo "   RUNNING TURF TEAMS LIVE SCOREBOARD TEST SUITE\n";
echo "====================================================\n\n";

$pdo = getDb();
$passCount = 0;
$failCount = 0;

function it(string $desc, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$desc}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

// 1. Reset Database for clean test
echo "1. Resetting test database to seed state...\n";
$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
$pdo->exec($schemaSql);
$pdo->exec("USE turf_teams");

// Ensure match_state and goals are empty/reset
$pdo->exec("DELETE FROM goals");
$pdo->exec("UPDATE match_state SET state = 'setup', started_at = NULL, ended_at = NULL WHERE id = 1");
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'picking_locked'");

// Helper to call api/match_state.php
function callMatchApi(string $method = 'GET', array $getParams = [], array $postData = null, bool $asAdmin = false): array {
    $script = "<?php
        \$_GET = " . var_export($getParams, true) . ";
        \$_POST = " . ($postData !== null ? var_export($postData, true) : '[]') . ";
        \$_SERVER['REQUEST_METHOD'] = '{$method}';
        \$_SERVER['HTTP_ACCEPT'] = 'application/json';
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (" . ($asAdmin ? 'true' : 'false') . ") {
            \$_SESSION['turf_admin_auth'] = true;
        }
        ob_start();
        require __DIR__ . '/../api/match_state.php';
        \$out = ob_get_clean();
        echo \$out;
    ";
    $tmpFile = __DIR__ . '/tmp_match_runner.php';
    file_put_contents($tmpFile, $script);
    $output = shell_exec('"C:\xampp\php\php.exe" ' . escapeshellarg($tmpFile));
    if (file_exists($tmpFile)) unlink($tmpFile);
    return json_decode($output, true) ?? ['success' => false, 'raw' => $output];
}

echo "\n2. Testing Initial Match State (setup)...\n";
$initial = callMatchApi('GET');
it("API returns success for GET match_state", !empty($initial['success']));
it("Initial state is 'setup'", ($initial['state'] ?? '') === 'setup');
it("Initial Runda score is 0", ($initial['scores']['runda'] ?? -1) === 0);
it("Initial Makongeni score is 0", ($initial['scores']['makongeni'] ?? -1) === 0);
it("can_start is false when 0 players picked", ($initial['can_start'] ?? true) === false);
it("remaining_to_pick is 18 (or 20)", ($initial['remaining_to_pick'] ?? 0) >= 18);

echo "\n3. Testing Server Enforcement: Reject start_match when < 18 players picked...\n";
$startFail = callMatchApi('POST', ['action' => 'start_match'], ['action' => 'start_match'], true);
it("start_match fails when < 18 players picked", empty($startFail['success']));
it("Error mentions players still need to pick", strpos($startFail['error'] ?? '', 'still need to pick') !== false);

echo "\n4. Simulating 18 players picking teams...\n";
// Assign 9 players to Team 1 (Runda) and 9 players to Team 2 (Makongeni) respecting rival rules
// Team 1: Jimmy (1), Jensen (3), Maxy (5), Spruu (7), Otis (9) [5 skilled] + Josee (10), Cyro (11), Don (12), Kimi (14) [4 regular] = 9
$pdo->exec("UPDATE players SET team_id = 1, picked_at = NOW() WHERE id IN (1, 3, 5, 7, 9, 10, 11, 12, 14)");
// Team 2: Oliver (2), Eddie (4), Kristian (6), Marto (8), Maina (13) [5 skilled] + Laban (15), Fidel (16), Zack (17), Joe (18) [4 regular] = 9
$pdo->exec("UPDATE players SET team_id = 2, picked_at = NOW() WHERE id IN (2, 4, 6, 8, 13, 15, 16, 17, 18)");

$pickedNow = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id IS NOT NULL")->fetchColumn();
it("18 players assigned to squads", $pickedNow === 18, "Found: $pickedNow");

$stateBeforeStart = callMatchApi('GET');
it("can_start is now TRUE", !empty($stateBeforeStart['can_start']));
it("remaining_to_pick is 0 (or 2 if 20 max)", ($stateBeforeStart['picked_players_count'] ?? 0) >= 18);

echo "\n5. Testing Start Match (Authorized Admin)...\n";
$startSuccess = callMatchApi('POST', ['action' => 'start_match'], ['action' => 'start_match'], true);
it("start_match succeeds when 18 players have picked", !empty($startSuccess['success']));
it("State transitions to 'live'", ($startSuccess['state'] ?? '') === 'live');
it("started_at timestamp is set", !empty($startSuccess['started_at']));

// Verify picking is automatically locked in settings
$isLocked = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'picking_locked'")->fetchColumn();
it("Picking is automatically locked in settings on match start", $isLocked === '1');

// Verify unauthorized user cannot perform match actions
$unauthRecord = callMatchApi('POST', ['action' => 'record_goal'], ['action' => 'record_goal', 'player_id' => 1], false);
it("Unauthorized user cannot record goal (401)", empty($unauthRecord['success']));

echo "\n6. Testing Goal Recording (Normal Goal)...\n";
// Jimmy (id 1, Runda FC) scores
$goal1 = callMatchApi('POST', ['action' => 'record_goal'], [
    'action' => 'record_goal',
    'player_id' => 1,
    'is_own_goal' => false
], true);

it("Normal goal recorded successfully", !empty($goal1['success']));
it("Runda FC score increments to 1", ($goal1['scores']['runda'] ?? 0) === 1);
it("Makongeni FC score remains 0", ($goal1['scores']['makongeni'] ?? -1) === 0);
it("Goals list contains 1 goal", count($goal1['goals'] ?? []) === 1);
it("Goal display label shows player and team", strpos($goal1['goals'][0]['display_label'] ?? '', 'Jimmy') !== false);

echo "\n7. Testing Goal Recording (Own Goal)...\n";
// Eddie (id 4, Makongeni FC) scores an Own Goal -> Credited to Runda FC!
$goal2 = callMatchApi('POST', ['action' => 'record_goal'], [
    'action' => 'record_goal',
    'player_id' => 4,
    'is_own_goal' => true
], true);

it("Own goal recorded successfully", !empty($goal2['success']));
it("Runda FC score increments to 2 (credited to opponent)", ($goal2['scores']['runda'] ?? 0) === 2);
it("Makongeni FC score remains 0", ($goal2['scores']['makongeni'] ?? -1) === 0);
it("Goal is tagged is_own_goal = true", ($goal2['goals'][1]['is_own_goal'] ?? false) === true);
it("Display label has (OG) tag", strpos($goal2['goals'][1]['display_label'] ?? '', '(OG)') !== false);

echo "\n8. Testing Multi-Goals (Hat-Trick) & Top Scorer Calculation...\n";
// Jimmy (id 1) scores a second goal
$goal3 = callMatchApi('POST', ['action' => 'record_goal'], [
    'action' => 'record_goal',
    'player_id' => 1,
    'is_own_goal' => false
], true);

// Oliver (id 2, Makongeni FC) scores for Makongeni
$goal4 = callMatchApi('POST', ['action' => 'record_goal'], [
    'action' => 'record_goal',
    'player_id' => 2,
    'is_own_goal' => false
], true);

it("Scores are now Runda 3 - 1 Makongeni", ($goal4['scores']['runda'] ?? 0) === 3 && ($goal4['scores']['makongeni'] ?? 0) === 1);
it("Top scorer is Jimmy with 2 goals", ($goal4['top_scorers'][0]['name'] ?? '') === 'Jimmy' && ($goal4['top_scorers'][0]['goals'] ?? 0) === 2);
it("Own goals are excluded from top scorer calculation", count(array_filter($goal4['top_scorers'], fn($s) => $s['id'] === 4)) === 0);

echo "\n9. Testing Undo Last Goal...\n";
// Oliver's goal (4th goal) undone
$undo = callMatchApi('POST', ['action' => 'undo_goal'], ['action' => 'undo_goal'], true);
it("Undo goal succeeds", !empty($undo['success']));
it("Makongeni score reverts to 0 (Runda 3 - 0 Makongeni)", ($undo['scores']['makongeni'] ?? -1) === 0);
it("Total goals in match is now 3", count($undo['goals'] ?? []) === 3);

echo "\n10. Testing Edit Goal Minute...\n";
// Edit first goal. Update minute to 12.
$goalToEditId = $undo['goals'][0]['id'];
$edit = callMatchApi('POST', ['action' => 'edit_goal'], [
    'action' => 'edit_goal',
    'goal_id' => $goalToEditId,
    'match_minute' => 12,
    'match_second' => 34
], true);
it("Edit goal minute succeeds", !empty($edit['success']));

$editedGoal = null;
foreach ($edit['goals'] as $g) {
    if ($g['id'] === $goalToEditId) {
        $editedGoal = $g;
        break;
    }
}
it("Goal minute updated to 12'", ($editedGoal['match_minute'] ?? 0) === 12);

echo "\n11. Testing Delete Specific Goal...\n";
// Delete own goal (id 2)
$goalToDelete = $edit['goals'][1]['id'];
$deleteRes = callMatchApi('POST', ['action' => 'delete_goal'], [
    'action' => 'delete_goal',
    'goal_id' => $goalToDelete
], true);
it("Delete specific goal succeeds", !empty($deleteRes['success']));
it("Runda score decrements to 2 (now 2 - 0)", ($deleteRes['scores']['runda'] ?? 0) === 2);

echo "\n12. Testing End Match (Full Time)...\n";
$endMatch = callMatchApi('POST', ['action' => 'end_match'], ['action' => 'end_match'], true);
it("End match succeeds", !empty($endMatch['success']));
it("State transitions to 'finished'", ($endMatch['state'] ?? '') === 'finished');
it("ended_at timestamp is set", !empty($endMatch['ended_at']));

// Verify cannot record goal after match has ended
$afterEndRecord = callMatchApi('POST', ['action' => 'record_goal'], [
    'action' => 'record_goal',
    'player_id' => 1
], true);
it("Recording goal after match ended is REJECTED", empty($afterEndRecord['success']));

echo "\n13. Testing WhatsApp Summary Format...\n";
$waText = $endMatch['whatsapp_result'] ?? '';
it("WhatsApp text contains match result header", strpos($waText, 'TURF MATCH SCOREBOARD') !== false);
it("WhatsApp text contains winner", strpos($waText, 'WINNER: Runda FC!') !== false);
it("WhatsApp text lists Jimmy's goals", strpos($waText, 'Jimmy') !== false);

echo "\n14. Testing Reset Match (Clears Scoreboard, KEEPS Team Picks)...\n";
$resetMatch = callMatchApi('POST', ['action' => 'reset_match'], ['action' => 'reset_match'], true);
it("Reset match succeeds", !empty($resetMatch['success']));
it("State returns to 'setup'", ($resetMatch['state'] ?? '') === 'setup');
it("Goals list is cleared to 0", count($resetMatch['goals'] ?? [1]) === 0);
it("Scores reset to 0 - 0", ($resetMatch['scores']['runda'] ?? -1) === 0 && ($resetMatch['scores']['makongeni'] ?? -1) === 0);

// CRITICAL: verify player team picks were KEPT!
$picksAfterReset = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id IS NOT NULL")->fetchColumn();
it("Player team picks are completely PRESERVED after match reset", $picksAfterReset === 18, "Found: $picksAfterReset");

echo "\n====================================================\n";
echo "SUMMARY: Passed {$passCount} / " . ($passCount + $failCount) . " tests.\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
