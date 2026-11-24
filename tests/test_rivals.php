<?php
/**
 * Turf Teams - Head-to-Head Skilled Rival Pairs Test
 * Validates that paired skilled opponents (Jimmy vs Oliver, etc.) cannot join the same team.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

echo "====================================================\n";
echo "   RUNNING TURF TEAMS SKILLED RIVAL OPPONENT TESTS\n";
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

// Reset Database for tests
echo "1. Resetting database to seed state...\n";
$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
$pdo->exec($schemaSql);
$pdo->exec("USE turf_teams");

// Retrieve players and teams
$jimmy = $pdo->query("SELECT * FROM players WHERE LOWER(name) = 'jimmy'")->fetch();
$oliver = $pdo->query("SELECT * FROM players WHERE LOWER(name) = 'oliver'")->fetch();
$otis = $pdo->query("SELECT * FROM players WHERE LOWER(name) = 'otis'")->fetch();
$maina = $pdo->query("SELECT * FROM players WHERE LOWER(name) = 'maina'")->fetch();

$runda = $pdo->query("SELECT * FROM teams WHERE LOWER(name) LIKE '%runda%'")->fetch();
$makongeni = $pdo->query("SELECT * FROM teams WHERE LOWER(name) LIKE '%makongeni%'")->fetch();

it("Found Jimmy and Oliver in database", !empty($jimmy) && !empty($oliver));
it("Found Otis and Maina in database", !empty($otis) && !empty($maina));
it("Found Runda and Makongeni teams", !empty($runda) && !empty($makongeni));

// Helper to run api.php in isolation
function callApi(string $action, array $params = [], array $postBody = null): array {
    $script = "<?php
        \$_GET = " . var_export(array_merge(['action' => $action], $params), true) . ";
        \$_POST = " . ($postBody !== null ? var_export($postBody, true) : '[]') . ";
        \$_SERVER['REQUEST_METHOD'] = " . ($postBody !== null ? "'POST'" : "'GET'") . ";
        \$_SERVER['HTTP_ACCEPT'] = 'application/json';
        ob_start();
        require __DIR__ . '/../api.php';
        \$out = ob_get_clean();
        echo \$out;
    ";
    $tmpFile = __DIR__ . '/tmp_test_runner.php';
    file_put_contents($tmpFile, $script);
    $output = shell_exec('"C:\xampp\php\php.exe" ' . escapeshellarg($tmpFile));
    if (file_exists($tmpFile)) unlink($tmpFile);
    return json_decode($output, true) ?? ['raw' => $output];
}

echo "\n2. Testing Jimmy picking Makongeni FC...\n";
$pickJimmy = callApi('pick', ['t' => $jimmy['token'], 'team_id' => $makongeni['id']], [
    'action' => 'pick',
    't' => $jimmy['token'],
    'team_id' => $makongeni['id']
]);
it("Jimmy picks Makongeni FC successfully", !empty($pickJimmy['success']));

echo "\n3. Testing Oliver status check when Jimmy is on Makongeni FC...\n";
$statusOliver = callApi('status', ['t' => $oliver['token']]);
it("Oliver receives valid status payload", !empty($statusOliver['success']));

$makoDataForOliver = null;
$rundaDataForOliver = null;
foreach ($statusOliver['teams'] as $t) {
    if ($t['id'] === (int)$makongeni['id']) $makoDataForOliver = $t;
    if ($t['id'] === (int)$runda['id']) $rundaDataForOliver = $t;
}

it("Oliver sees Makongeni FC marked as blocked_by_rival", $makoDataForOliver['blocked_by_rival'] === true);
it("Oliver sees Makongeni FC rival_name is Jimmy", $makoDataForOliver['rival_name'] === 'Jimmy');
it("Oliver sees Makongeni FC spots_left is 0", $makoDataForOliver['spots_left'] === 0);
it("Oliver sees Makongeni FC is_selectable is false", $makoDataForOliver['is_selectable'] === false);
it("Oliver sees Runda FC is_selectable is true", $rundaDataForOliver['is_selectable'] === true);
it("Oliver's auto_assigned_team_id points to Runda FC", (int)$statusOliver['auto_assigned_team_id'] === (int)$runda['id']);

echo "\n4. Testing Oliver attempting to force-join Makongeni FC (Blocked)...\n";
$pickOliverBlocked = callApi('pick', ['t' => $oliver['token'], 'team_id' => $makongeni['id']], [
    'action' => 'pick',
    't' => $oliver['token'],
    'team_id' => $makongeni['id']
]);
it("Oliver is REJECTED from joining Makongeni FC", empty($pickOliverBlocked['success']));
it("Rejection mentions paired opponents", strpos($pickOliverBlocked['error'] ?? '', 'paired opponents') !== false);

echo "\n5. Testing Oliver joining Runda FC (Opposite Team)...\n";
$pickOliverRunda = callApi('pick', ['t' => $oliver['token'], 'team_id' => $runda['id']], [
    'action' => 'pick',
    't' => $oliver['token'],
    'team_id' => $runda['id']
]);
it("Oliver joins Runda FC successfully", !empty($pickOliverRunda['success']));

echo "\n6. Testing Otis vs Maina pair...\n";
$pickOtis = callApi('pick', ['t' => $otis['token'], 'team_id' => $runda['id']], [
    'action' => 'pick',
    't' => $otis['token'],
    'team_id' => $runda['id']
]);
it("Otis joins Runda FC successfully", !empty($pickOtis['success']));

$pickMainaBlocked = callApi('pick', ['t' => $maina['token'], 'team_id' => $runda['id']], [
    'action' => 'pick',
    't' => $maina['token'],
    'team_id' => $runda['id']
]);
it("Maina is REJECTED from joining Runda FC because rival Otis is on Runda FC", empty($pickMainaBlocked['success']));

$pickMainaMako = callApi('pick', ['t' => $maina['token'], 'team_id' => $makongeni['id']], [
    'action' => 'pick',
    't' => $maina['token'],
    'team_id' => $makongeni['id']
]);
it("Maina joins Makongeni FC successfully", !empty($pickMainaMako['success']));

echo "\n====================================================\n";
echo "SUMMARY: Passed {$passCount} / " . ($passCount + $failCount) . " tests.\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
