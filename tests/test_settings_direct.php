<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$pdo = getDb();
ensureDatabaseTeamSupport($pdo);

// Test 1: save '3_teams' via saveSetting and getTeamMode
saveSetting($pdo, 'team_mode', '3_teams');
$m = getTeamMode($pdo, true);
echo "Mode after setting 3_teams: $m" . PHP_EOL;
assert($m === '3_teams');

// Test 2: save '2_teams'
saveSetting($pdo, 'team_mode', '2_teams');
$m2 = getTeamMode($pdo, true);
echo "Mode after setting 2_teams: $m2" . PHP_EOL;
assert($m2 === '2_teams');

echo "Setting tests passed successfully!" . PHP_EOL;
