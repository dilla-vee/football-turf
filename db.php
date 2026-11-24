<?php
/**
 * Turf Teams - Database Connection Helper
 * Supports both MySQL (local XAMPP) and PostgreSQL (Supabase / Render Cloud).
 */

require_once __DIR__ . '/config.php';

function getDb(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';

        if ($driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                defined('DB_SSLMODE') ? DB_SSLMODE : 'require'
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ];
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) || 
                     (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            
            if ($isApi) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Database connection failed: ' . $e->getMessage()
                ]);
                exit;
            } else {
                http_response_code(500);
                echo '<!DOCTYPE html><html><head><title>Database Connection Error</title><meta name="viewport" content="width=device-width, initial-scale=1.0">';
                echo '<style>body{background:#06140d;color:#f0fdf4;font-family:system-ui,sans-serif;padding:2rem;text-align:center;}';
                echo '.box{max-width:540px;margin:3rem auto;background:#0f2c1d;padding:2rem;border-radius:16px;border:1px solid #10b981;box-shadow:0 10px 25px rgba(0,0,0,0.5);}';
                echo 'h2{color:#f87171;margin-bottom:1rem;}p{color:#a7f3d0;line-height:1.6;}</style></head><body>';
                echo '<div class="box"><h2>Database Connection Failed</h2>';
                echo '<p>Could not connect to the database (' . htmlspecialchars($driver) . ').</p>';
                if ($driver === 'pgsql') {
                    echo '<p>Please check your Supabase connection string / <code>DATABASE_URL</code> environment variable.</p>';
                } else {
                    echo '<p>If running locally on XAMPP, ensure MySQL is started and <code>schema.sql</code> is imported.</p>';
                }
                echo '<p style="font-size:0.85rem;color:#94a3b8;margin-top:1rem;word-break:break-all;">' . htmlspecialchars($e->getMessage()) . '</p></div>';
                echo '</body></html>';
                exit;
            }
        }
    }

    return $pdo;
}
