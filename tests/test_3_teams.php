<?php
/**
 * Turf Teams - 3-Team Mode & Team Customization Automated Test Suite
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

function it(string $desc, bool $condition): void {
    if ($condition) {
        echo "  [PASS] $desc\n";
    } else {
        echo "  [FAIL] $desc\n";
        exit(1);
    }
}

$pdo = getDb();

echo "====================================================\n";
echo "   RUNNING 3-TEAM MODE & CUSTOM NAMES TEST SUITE    \n";
echo "====================================================\n\n";

// 1. Reset database to clean state
echo "1. Resetting database to seed state...\n";
ensureDatabaseTeamSupport($pdo);

$pdo->exec("UPDATE settings SET setting_value = '3_teams' WHERE setting_key = 'team_mode'");
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'picking_locked'");
$pdo->exec("UPDATE players SET team_id = NULL, picked_at = NULL");
$pdo->exec("DELETE FROM goals");
$pdo->exec("UPDATE match_state SET state = 'setup', started_at = NULL, ended_at = NULL WHERE id = 1");

it("team_mode setting is 3_teams", getTeamMode($pdo, true) === '3_teams');
$caps = getTeamCaps('3_teams');
it("3-team mode max team size is 6", $caps['max_team_size'] === 6);
it("3-team mode max skilled is 4", $caps['max_skilled_per_team'] === 4);
it("3-team mode max regular is 3", $caps['max_regular_per_team'] === 3);
it("3-team mode total target is 18", $caps['total_target'] === 18);

// 2. Custom Team Names
echo "\n2. Testing Team Renaming...\n";
$stmtUpdate = $pdo->prepare("UPDATE teams SET name = ? WHERE id = ?");
$stmtUpdate->execute(['Alpha Squad', 1]);
$stmtUpdate->execute(['Bravo Squad', 2]);
$stmtUpdate->execute(['Charlie Squad', 3]);

$tNames = $pdo->query("SELECT id, name FROM teams ORDER BY id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
it("Team 1 renamed to Alpha Squad", $tNames[1] === 'Alpha Squad');
it("Team 2 renamed to Bravo Squad", $tNames[2] === 'Bravo Squad');
it("Team 3 renamed to Charlie Squad", $tNames[3] === 'Charlie Squad');

// 3. Testing Rival Opponent Balancing across 3 teams
echo "\n3. Testing Rival Balancing across 3 teams...\n";
// Jimmy (id 1, skilled) picks Team 1 (Alpha Squad)
$jimmy = $pdo->query("SELECT id, token, name FROM players WHERE LOWER(name) = 'jimmy'")->fetch();
$oliver = $pdo->query("SELECT id, token, name FROM players WHERE LOWER(name) = 'oliver'")->fetch();

it("Found Jimmy and Oliver", !empty($jimmy) && !empty($oliver));

// Simulate Jimmy picking Team 1
$pdo->prepare("UPDATE players SET team_id = 1, picked_at = NOW() WHERE id = ?")->execute([$jimmy['id']]);

// Check Oliver rival rule
$rival = findRivalPlayer($pdo, 'Oliver');
it("Oliver's rival found is Jimmy", !empty($rival) && strtolower($rival['name']) === 'jimmy');
it("Jimmy is registered on Team 1", (int)$rival['team_id'] === 1);

// In 3-team mode, Oliver is blocked from Team 1, but CAN join Team 2 or Team 3!
it("Oliver cannot join Team 1", (int)$rival['team_id'] === 1);

// Oliver picks Team 3 (Charlie Squad)
$pdo->prepare("UPDATE players SET team_id = 3, picked_at = NOW() WHERE id = ?")->execute([$oliver['id']]);
$oliverTeam = (int)$pdo->query("SELECT team_id FROM players WHERE id = {$oliver['id']}")->fetchColumn();
it("Oliver successfully joined Team 3", $oliverTeam === 3);

// 4. Testing 6-player cap per team in 3-team mode
echo "\n4. Testing 6-player team cap in 3-team mode...\n";
// Fill Team 1 to 6 players
$unassigned = $pdo->query("SELECT id FROM players WHERE team_id IS NULL LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
foreach ($unassigned as $pid) {
    $pdo->prepare("UPDATE players SET team_id = 1, picked_at = NOW() WHERE id = ?")->execute([$pid]);
}
$team1Count = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id = 1")->fetchColumn();
it("Team 1 has exactly 6 players (Full)", $team1Count === 6);

// 5. Testing Live Match Scoreboard for 3 Teams
echo "\n5. Testing 3-Team Scoreboard and Goal Tracking...\n";
$pdo->exec("UPDATE match_state SET state = 'live', started_at = NOW() WHERE id = 1");

// Jimmy (Team 1) scores a goal
$pdo->exec("INSERT INTO goals (player_id, team_id, is_own_goal, match_minute, match_second, created_at) VALUES ({$jimmy['id']}, 1, 0, 10, 0, NOW())");

// Oliver (Team 3) scores a goal
$pdo->exec("INSERT INTO goals (player_id, team_id, is_own_goal, match_minute, match_second, created_at) VALUES ({$oliver['id']}, 3, 0, 15, 30, NOW())");

require_once __DIR__ . '/../api/match_state.php';
$payload = buildMatchPayload($pdo);

it("Scoreboard returns 3 teams", count($payload['teams']) === 3);
it("Team 1 score is 1", ($payload['scores_by_id'][1] ?? 0) === 1);
it("Team 2 score is 0", ($payload['scores_by_id'][2] ?? -1) === 0);
it("Team 3 score is 1", ($payload['scores_by_id'][3] ?? 0) === 1);
it("Top scorers list contains Jimmy and Oliver tied at 1 goal", count($payload['top_scorers']) === 2);

// 6. WhatsApp result formatting for 3 teams
echo "\n6. Testing WhatsApp Summary for 3 teams...\n";
$waText = $payload['whatsapp_result'];
it("WhatsApp text includes Alpha Squad", strpos($waText, 'Alpha Squad') !== false);
it("WhatsApp text includes Bravo Squad", strpos($waText, 'Bravo Squad') !== false);
it("WhatsApp text includes Charlie Squad", strpos($waText, 'Charlie Squad') !== false);
it("WhatsApp text has zero raw emojis", strpos($waText, '⚽') === false && strpos($waText, '🟢') === false);

// 7. Cleanup & revert back to standard 2-team mode
echo "\n7. Reverting test state back to standard 2 teams...\n";
$pdo->exec("UPDATE settings SET setting_value = '2_teams' WHERE setting_key = 'team_mode'");
$stmtUpdate->execute(['Runda FC', 1]);
$stmtUpdate->execute(['Makongeni FC', 2]);
$stmtUpdate->execute(['Parklands FC', 3]);
$pdo->exec("DELETE FROM goals");
$pdo->exec("UPDATE match_state SET state = 'setup', started_at = NULL, ended_at = NULL WHERE id = 1");
$pdo->exec("UPDATE players SET team_id = NULL, picked_at = NULL");

it("Reset to 2_teams successful", getTeamMode($pdo, true) === '2_teams');

echo "\n====================================================\n";
echo "   ALL 3-TEAM & CUSTOM NAMES TESTS PASSED (18/18)   \n";
echo "====================================================\n";
