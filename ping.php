<?php
/**
 * Turf Teams - Fast Health & Diagnostics Endpoint
 * Use to instantly verify if Render is awake and if Database connection is alive.
 */

header('Content-Type: application/json; charset=utf-8');

$start = microtime(true);
$response = [
    'status' => 'ok',
    'timestamp' => time(),
    'server_time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'render' => !empty(getenv('RENDER')) || !empty($_SERVER['RENDER'])
];

require_once __DIR__ . '/config.php';
$response['db_driver'] = defined('DB_DRIVER') ? DB_DRIVER : 'unknown';
$response['db_host'] = defined('DB_HOST') ? DB_HOST : 'unknown';
$response['db_port'] = defined('DB_PORT') ? DB_PORT : 0;

try {
    require_once __DIR__ . '/db.php';
    $pdo = getDb();
    $stmt = $pdo->query("SELECT 1");
    $response['database_connected'] = true;
} catch (Throwable $e) {
    $response['database_connected'] = false;
    $response['db_error'] = $e->getMessage();
}

$response['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);

echo json_encode($response, JSON_PRETTY_PRINT);
