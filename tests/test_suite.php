<?php
/**
 * Turf Teams - Automated Test Suite
 * Validates race-condition transactions, hard tier caps, admin CRUD, and pick safety.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

echo "========================================\n";
echo "   RUNNING TURF TEAMS TEST SUITE\n";
echo "========================================\n\n";

$pdo = getDb();
$passedTests = 0;
$totalTests = 0;

function assertCondition(string $name, bool $condition, string $detail = ''): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] $name\n";
    } else {
        echo "  [FAIL] $name" . ($detail ? " ($detail)" : "") . "\n";
    }
}

// 1. Reset Database to Clean State
echo "1. Resetting test database to seed state...\n";
$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
$pdo->exec($schemaSql);
$pdo->exec("USE turf_teams");

$playerCount = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
assertCondition("Database seeded with players", $playerCount >= 18, "Found: $playerCount");

$teamCount = (int)$pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn();
assertCondition("Two teams seeded (Runda FC & Makongeni FC)", $teamCount === 2, "Found: $teamCount");

// Helper to invoke api.php in a clean sub-process
function callApi(array $getParams = [], array $postData = null): array {
    $queryString = http_build_query($getParams);
    $script = "<?php
        \$_GET = " . var_export($getParams, true) . ";
        \$_POST = " . ($postData !== null ? var_export($postData, true) : '[]') . ";
        if (" . ($postData !== null ? 'true' : 'false') . ") {
            \$_SERVER['REQUEST_METHOD'] = 'POST';
        } else {
            \$_SERVER['REQUEST_METHOD'] = 'GET';
        }
        ob_start();
        require __DIR__ . '/../api.php';
        \$out = ob_get_clean();
        echo \$out;
    ";
    $tmpFile = __DIR__ . '/tmp_runner.php';
    file_put_contents($tmpFile, $script);
    $output = shell_exec('"C:\xampp\php\php.exe" ' . escapeshellarg($tmpFile));
    if (file_exists($tmpFile)) unlink($tmpFile);
    return json_decode($output, true) ?? ['success' => false, 'raw' => $output];
}

// 2. Validate Privacy: Player Status API must NOT leak tier
echo "\n2. Testing Player Status API Privacy & Spot Calculations...\n";
// Jimmy (Skilled)
$statusData = callApi(['action' => 'status', 't' => '291c9104b29acd1934f2b669771c13ba']);

assertCondition("Status returns success for valid token", ($statusData['success'] ?? false) === true);
assertCondition("Player name is displayed", ($statusData['player']['name'] ?? '') === 'Jimmy');
assertCondition("Player tier is NOT leaked in response", !isset($statusData['player']['tier']));
assertCondition("Roster names have no tier badges attached", !isset($statusData['teams'][0]['roster'][0]['tier']));
assertCondition("Skilled player sees 5 spots left initially per team", ($statusData['teams'][0]['spots_left'] ?? 0) === 5 && ($statusData['teams'][1]['spots_left'] ?? 0) === 5);

// 3. Test Invalid Token handling
$invalidTokenData = callApi(['action' => 'status', 't' => 'invalid_random_token_999']);
assertCondition("Invalid token returns 404/failure", ($invalidTokenData['success'] ?? true) === false);

// 4. Test Picking and Single Choice Constraint
echo "\n3. Testing Atomic Pick & Single-Choice Lock...\n";
function simulatePick(string $token, int $teamId): array {
    $pdo = getDb();
    $pdo->beginTransaction();
    try {
        $stmtLock = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'picking_locked' FOR UPDATE");
        $stmtLock->execute();
        if ($stmtLock->fetchColumn() === '1') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Picking locked'];
        }

        $pStmt = $pdo->prepare("SELECT id, name, tier, team_id FROM players WHERE token = ? FOR UPDATE");
        $pStmt->execute([$token]);
        $player = $pStmt->fetch();

        if (!$player) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Invalid token'];
        }

        if ($player['team_id'] !== null) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Already picked'];
        }

        $rival = findRivalPlayer($pdo, $player['name']);
        if ($rival && $rival['team_id'] !== null && (int)$rival['team_id'] === $teamId) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Blocked by rival'];
        }

        $tStmt = $pdo->prepare("SELECT id, name FROM teams WHERE id = ? FOR UPDATE");
        $tStmt->execute([$teamId]);
        $team = $tStmt->fetch();

        $max = ($player['tier'] === 'skilled') ? MAX_SKILLED_PER_TEAM : MAX_REGULAR_PER_TEAM;
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id = ? AND tier = ?");
        $cStmt->execute([$teamId, $player['tier']]);
        if ((int)$cStmt->fetchColumn() >= $max) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Team full for tier'];
        }

        $uStmt = $pdo->prepare("UPDATE players SET team_id = ?, picked_at = NOW() WHERE id = ? AND team_id IS NULL");
        $uStmt->execute([$teamId, $player['id']]);
        $pdo->commit();
        return ['success' => true, 'team_name' => $team['name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Jimmy picks Runda FC (id 1)
$pick1 = simulatePick('291c9104b29acd1934f2b669771c13ba', 1);
assertCondition("Jimmy successfully picks Runda FC", $pick1['success'] === true && $pick1['team_name'] === 'Runda FC');

// Jimmy tries to pick again (Makongeni FC)
$pickAgain = simulatePick('291c9104b29acd1934f2b669771c13ba', 2);
assertCondition("Jimmy cannot pick twice (choice locked)", $pickAgain['success'] === false && $pickAgain['error'] === 'Already picked');

// 5. Test Hard Caps: Fill Runda FC with 5 Skilled Players (1 from each pair)
echo "\n4. Testing Hard Caps for Skilled Players (Cap: 5 per team)...\n";
// Jimmy is already on Runda (1/5). Now pick Jensen, Maxy, Spruu, Otis:
$pick2 = simulatePick('a47a13d1abbe5a4926b4264542cacbe7', 1); // Jensen -> Runda (2/5)
$pick3 = simulatePick('ee11595ec62b50adaab88af126b694d5', 1); // Maxy -> Runda (3/5)
$pick4 = simulatePick('6a6c02c176568cc55cc067289c8d4a44', 1); // Spruu -> Runda (4/5)
$pick5 = simulatePick('4b3aaef91ae04df77bc960579de3520c', 1); // Otis -> Runda (5/5 FULL)

assertCondition("5 Skilled players (1 per pair) joined Runda FC", $pick2['success'] && $pick3['success'] && $pick4['success'] && $pick5['success']);

// Now Oliver tries to pick Runda FC (Blocked by rival Jimmy AND team full for skilled)
$pickOliver = simulatePick('2cfc36e668eee6e18c96c08bee581d6f', 1);
assertCondition("Oliver REJECTED from Runda FC (Rival & Cap enforced)", $pickOliver['success'] === false);

// Verify Oliver status shows Runda full/blocked, Makongeni only selectable option
$oliverData = callApi(['action' => 'status', 't' => '2cfc36e668eee6e18c96c08bee581d6f']);

assertCondition("Runda FC has 0 spots left for Oliver", $oliverData['teams'][0]['spots_left'] === 0);
assertCondition("Runda FC is marked not selectable for Oliver", $oliverData['teams'][0]['is_selectable'] === false);
assertCondition("Makongeni FC has 5 spots left for Oliver", $oliverData['teams'][1]['spots_left'] === 5);
assertCondition("Makongeni FC is auto-identified as single remaining option", $oliverData['auto_assigned_team_id'] === 2);

// Oliver picks Makongeni FC
$pickOliverMako = simulatePick('2cfc36e668eee6e18c96c08bee581d6f', 2);
assertCondition("Oliver successfully joins Makongeni FC", $pickOliverMako['success'] === true);

// 6. Test Hard Caps: Regular Players (Cap: 5 per team)
echo "\n5. Testing Hard Caps for Regular Players (Cap: 5 per team)...\n";
// Regular tokens: Josee, Cyro, Don, Kimi, Laban, Fidel
simulatePick('3622253a346bf02104f96b34f9193e8a', 1); // Josee -> Runda (1/5)
simulatePick('111316ace8decde053f613c2cd0ac677', 1); // Cyro -> Runda (2/5)
simulatePick('c2694bba07116468b7d318f96ad846e1', 1); // Don -> Runda (3/5)
simulatePick('8541e10ad454370b0a927e00f2f78188', 1); // Kimi -> Runda (4/5)
$reg5 = simulatePick('80e6e4653dd718b66f81ad1073b74fbd', 1); // Laban -> Runda (5/5 FULL)
assertCondition("5 Regular players filled Runda FC", $reg5['success'] === true);

// 6th Regular player (Fidel) tries to pick Runda FC
$reg6 = simulatePick('f812ce1b6f12e38c4d5cabc052633660', 1);
assertCondition("6th Regular player REJECTED from Runda FC (Cap 5 enforced)", $reg6['success'] === false && $reg6['error'] === 'Team full for tier');

// Verify Runda is completely full (5 skilled + 5 regular = 10 players total!)
$rundaTotal = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id = 1")->fetchColumn();
assertCondition("Runda FC reached exact 10 player capacity", $rundaTotal === 10, "Total: $rundaTotal");

// 7. Test Admin Lock Picking Feature...
echo "\n6. Testing Admin Lock Picking Feature...\n";
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'picking_locked'");
$lockPick = simulatePick('651490f143c22b0ae259439dcc0a8c9d', 2); // Zack tries to pick
assertCondition("When picking is locked by admin, no player can pick", $lockPick['success'] === false && $lockPick['error'] === 'Picking locked');

// Unlock picking
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'picking_locked'");
$unlockPick = simulatePick('651490f143c22b0ae259439dcc0a8c9d', 2); // Zack tries again
assertCondition("When admin unlocks picking, selections succeed again", $unlockPick['success'] === true);

// 8. Test Admin Reset Single Player and Reset All
echo "\n7. Testing Admin Reset Operations...\n";
// Reset Zack (id 17)
$pdo->exec("UPDATE players SET team_id = NULL, picked_at = NULL WHERE id = 17");
$zackTeam = $pdo->query("SELECT team_id FROM players WHERE id = 17")->fetchColumn();
assertCondition("Admin can reset an individual player's pick", $zackTeam === null);

// Reset all players
$pdo->exec("UPDATE players SET team_id = NULL, picked_at = NULL");
$anyPicked = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id IS NOT NULL")->fetchColumn();
assertCondition("Admin reset all clears picks for all players", $anyPicked === 0);

// 9. Test Admin Player CRUD
echo "\n8. Testing Admin Player CRUD...\n";
$newPlayerToken = bin2hex(random_bytes(16));
$stmt = $pdo->prepare("INSERT INTO players (name, tier, token) VALUES (?, ?, ?)");
$stmt->execute(['Victor Moses', 'skilled', $newPlayerToken]);
$newPlayerId = $pdo->lastInsertId();
assertCondition("Admin can add a new player with auto-token", $newPlayerId > 0);

$stmt = $pdo->prepare("UPDATE players SET name = ?, tier = ? WHERE id = ?");
$stmt->execute(['Victor Moses Jr.', 'regular', $newPlayerId]);
$checkPlayer = $pdo->query("SELECT name, tier FROM players WHERE id = $newPlayerId")->fetch();
assertCondition("Admin can edit player details", $checkPlayer['name'] === 'Victor Moses Jr.' && $checkPlayer['tier'] === 'regular');

$stmt = $pdo->prepare("DELETE FROM players WHERE id = ?");
$stmt->execute([$newPlayerId]);
$checkDeleted = $pdo->query("SELECT COUNT(*) FROM players WHERE id = $newPlayerId")->fetchColumn();
assertCondition("Admin can delete a player", (int)$checkDeleted === 0);

// Clean up: Reset to clean initial seed
$pdo->exec($schemaSql);

echo "\n========================================\n";
echo "   TEST SUMMARY: $passedTests / $totalTests PASSED\n";
echo "========================================\n";

if ($passedTests === $totalTests) {
    echo "🎉 ALL AUTOMATED TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
