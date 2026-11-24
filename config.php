<?php
/**
 * Turf Teams - Configuration File
 * Dual-compatible with local XAMPP MySQL and Cloud Supabase PostgreSQL / Render.
 */

// Check for cloud DATABASE_URL (standard in Render, Supabase, Railway, Heroku)
$databaseUrl = getenv('DATABASE_URL');

if (!empty($databaseUrl)) {
    // Parse URL: postgresql://user:pass@host:port/dbname?sslmode=require
    $dbParts = parse_url($databaseUrl);
    
    $scheme = strtolower($dbParts['scheme'] ?? 'mysql');
    $driver = ($scheme === 'postgres' || $scheme === 'postgresql') ? 'pgsql' : 'mysql';
    
    define('DB_DRIVER', $driver);
    define('DB_HOST', $dbParts['host'] ?? '127.0.0.1');
    define('DB_PORT', isset($dbParts['port']) ? (int)$dbParts['port'] : ($driver === 'pgsql' ? 5432 : 3306));
    define('DB_NAME', ltrim($dbParts['path'] ?? 'postgres', '/'));
    define('DB_USER', urldecode($dbParts['user'] ?? 'root'));
    define('DB_PASS', urldecode($dbParts['pass'] ?? ''));
    define('DB_SSLMODE', 'require');
} else {
    // Environment Variables or Default Local XAMPP Settings
    define('DB_DRIVER', getenv('DB_DRIVER') ?: 'mysql');
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : (DB_DRIVER === 'pgsql' ? 5432 : 3306));
    define('DB_NAME', getenv('DB_NAME') ?: (DB_DRIVER === 'pgsql' ? 'postgres' : 'turf_teams'));
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
    define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'prefer');
}

define('DB_CHARSET', 'utf8mb4');

// Team Balance Rules
define('MAX_SKILLED_PER_TEAM', 4);
define('MAX_REGULAR_PER_TEAM', 5);
define('TOTAL_PLAYERS_PER_TEAM', 9);
define('TOTAL_PLAYERS_ALLOWED', 18);

// Admin Authentication
// Default Admin password is 'turf2026'
// Pre-calculated hash: password_hash('turf2026', PASSWORD_BCRYPT)
define('ADMIN_PASSWORD_HASH', '$2y$10$vKVf0gGKdsALFucdrgC.QeK3RigJU0okf7SoN4lQKKVrl9GlW2aYO');
define('ADMIN_SESSION_KEY', 'turf_admin_auth');

// Optional Base URL override (null = auto-detect from HTTP request)
define('APP_BASE_URL_OVERRIDE', getenv('APP_BASE_URL') ?: null);

/**
 * Returns the current application base URL without trailing slash.
 */
function getAppBaseUrl(): string {
    if (defined('APP_BASE_URL_OVERRIDE') && !empty(APP_BASE_URL_OVERRIDE)) {
        return rtrim(APP_BASE_URL_OVERRIDE, '/');
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Determine path prefix (directory of index.php relative to document root)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = dirname($scriptName);
    
    // Normalize if script is inside /admin subfolder
    if (substr($dir, -6) === '/admin' || substr($dir, -6) === '\\admin') {
        $dir = dirname($dir);
    }

    $path = trim(str_replace('\\', '/', $dir), '/');
    $baseUrl = $protocol . $host . ($path !== '' ? '/' . $path : '');
    
    return rtrim($baseUrl, '/');
}

/**
 * Helper to build player personalized pick URL.
 */
function getPlayerPickUrl(string $token): string {
    return getAppBaseUrl() . '/pick.php?t=' . urlencode($token);
}
