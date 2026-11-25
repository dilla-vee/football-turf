<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

session_start();
$_SESSION[ADMIN_SESSION_KEY] = true;

// Simulate calling set_team_mode with mode = '3_teams'
$_GET['action'] = 'set_team_mode';
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
$pdo = getDb();
require __DIR__ . '/../admin/api.php';
// In admin/api.php it executes switch($action)

?>
