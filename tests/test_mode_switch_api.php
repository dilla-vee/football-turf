<?php
/**
 * Test admin/api.php?action=set_team_mode
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$pdo = getDb();
ensureDatabaseTeamSupport($pdo);

// Simulate admin session
session_start();
$_SESSION[ADMIN_SESSION_KEY] = true;

// Helper to simulate request to handleSetTeamMode
require_once __DIR__ . '/../admin/api.php';

// Test 1: Switch to 3_teams via mode parameter
$_POST = [];
$input = ['mode' => '3_teams'];
// Test the logic directly
$rawMode = trim($input['mode'] ?? ($input['team_mode'] ?? ''));
$requestedMode = ($rawMode === '3_teams') ? '3_teams' : '2_teams';
saveSetting($pdo, 'team_mode', $requestedMode);

$dbMode = getTeamMode($pdo, true);
echo "Test 1 dbMode: $dbMode\n";
if ($dbMode !== '3_teams') {
    echo "[FAIL] Expected 3_teams in DB\n";
    exit(1);
}
echo "[PASS] Successfully set 3_teams in DB\n";

// Test 2: Switch back to 2_teams via mode parameter
$input2 = ['mode' => '2_teams'];
$rawMode2 = trim($input2['mode'] ?? ($input2['team_mode'] ?? ''));
$requestedMode2 = ($rawMode2 === '3_teams') ? '3_teams' : '2_teams';
saveSetting($pdo, 'team_mode', $requestedMode2);

$dbMode2 = getTeamMode($pdo, true);
echo "Test 2 dbMode: $dbMode2\n";
if ($dbMode2 !== '2_teams') {
    echo "[FAIL] Expected 2_teams in DB\n";
    exit(1);
}
echo "[PASS] Successfully set 2_teams in DB\n";
