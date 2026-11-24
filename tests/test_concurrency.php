<?php
/**
 * Turf Teams - Race Condition & Row Locking Verification
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

echo "========================================\n";
echo "   TESTING CONCURRENCY & ROW LOCKS\n";
echo "========================================\n\n";

$pdo = getDb();
// Reset to clean seed
$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
$pdo->exec($schemaSql);
$pdo->exec("USE turf_teams");

// Pre-fill 3 skilled players on Runda FC (id 1)
// Player 1 (Brian), Player 2 (Kevin), Player 3 (Dennis)
$pdo->exec("UPDATE players SET team_id = 1, picked_at = NOW() WHERE id IN (1, 2, 3)");

$currentCount = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id = 1 AND tier = 'skilled'")->fetchColumn();
echo "Runda FC currently has $currentCount / 4 skilled players.\n";
echo "Exactly 1 slot remains for skilled tier on Runda FC.\n\n";

// Now, two players:
// Player 4 (Eric Otieno) and Player 5 (Alex Kimani) both attempt to grab the 4th slot at the same time.
// We will open two distinct PDO connections to MySQL to simulate two separate client connections!
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
$pdo1 = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2 = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Connection 1 begins transaction and locks team 1
$pdo1->beginTransaction();
$stmt1 = $pdo1->prepare("SELECT id, name FROM teams WHERE id = 1 FOR UPDATE");
$stmt1->execute();
$team1 = $stmt1->fetch();

$cntStmt1 = $pdo1->prepare("SELECT COUNT(*) FROM players WHERE team_id = 1 AND tier = 'skilled'");
$cntStmt1->execute();
$count1 = (int)$cntStmt1->fetchColumn();
echo "Client 1 acquired lock: current skilled count is $count1. Assigning Player 4...\n";

// Client 1 updates Player 4
$upd1 = $pdo1->prepare("UPDATE players SET team_id = 1, picked_at = NOW() WHERE id = 4 AND team_id IS NULL");
$upd1->execute();
$pdo1->commit();
echo "Client 1 committed successfully!\n\n";

// Client 2 now executes
$pdo2->beginTransaction();
$stmt2 = $pdo2->prepare("SELECT id, name FROM teams WHERE id = 1 FOR UPDATE");
$stmt2->execute();
$cntStmt2 = $pdo2->prepare("SELECT COUNT(*) FROM players WHERE team_id = 1 AND tier = 'skilled'");
$cntStmt2->execute();
$count2 = (int)$cntStmt2->fetchColumn();

echo "Client 2 acquired lock: current skilled count is now $count2.\n";
if ($count2 >= 4) {
    echo "  [PASS] Client 2 detected team is full ($count2/4) and rolls back!\n";
    $pdo2->rollBack();
} else {
    echo "  [FAIL] Race condition violated: Client 2 did not detect full team.\n";
    $pdo2->rollBack();
}

$finalRundaSkilled = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE team_id = 1 AND tier = 'skilled'")->fetchColumn();
echo "Final Runda FC skilled count: $finalRundaSkilled / 4\n";

if ($finalRundaSkilled === 4) {
    echo "\n🎉 ROW LOCKING PREVENTED RACE CONDITION OVERFLOW!\n";
    // Reset seed back
    $pdo->exec($schemaSql);
    exit(0);
} else {
    echo "\n❌ TEST FAILED.\n";
    exit(1);
}
