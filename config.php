<?php
/**
 * Turf Teams - Configuration File
 * Dual-compatible with local XAMPP MySQL and Cloud Supabase PostgreSQL / Render.
 */

// Helper to look up an environment variable across getenv, $_SERVER, and $_ENV
function getEnvVar(string $key, ?string $default = null): ?string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string)$_SERVER[$key];
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    return $default;
}

// 1. Check for cloud DATABASE_URL
$databaseUrl = getEnvVar('DATABASE_URL');

// Also check for .env file if present
if (empty($databaseUrl) && file_exists(__DIR__ . '/.env')) {
    $envLines = @file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'DATABASE_URL=')) {
            $databaseUrl = trim(substr($line, strlen('DATABASE_URL=')));
            $databaseUrl = trim($databaseUrl, '"\'');
            break;
        }
    }
}

if (!empty($databaseUrl)) {
    // Robust parser that handles complex passwords with #, @, %, etc.
    $parsed = false;
    if (preg_match('#^(?:postgres|postgresql)://([^:]+):(.*)@([^:/]+)(?::(\d+))?/(.+?)(?:\?(.*))?$#i', $databaseUrl, $m)) {
        define('DB_DRIVER', 'pgsql');
        define('DB_USER', urldecode($m[1]));
        define('DB_PASS', urldecode($m[2]));
        define('DB_HOST', $m[3]);
        define('DB_PORT', !empty($m[4]) ? (int)$m[4] : 5432);
        define('DB_NAME', $m[5]);
        define('DB_SSLMODE', 'require');
        $parsed = true;
    } elseif ($parts = parse_url($databaseUrl)) {
        $scheme = strtolower($parts['scheme'] ?? 'mysql');
        $driver = ($scheme === 'postgres' || $scheme === 'postgresql') ? 'pgsql' : 'mysql';
        define('DB_DRIVER', $driver);
        define('DB_HOST', $parts['host'] ?? '127.0.0.1');
        define('DB_PORT', isset($parts['port']) ? (int)$parts['port'] : ($driver === 'pgsql' ? 5432 : 3306));
        define('DB_NAME', ltrim($parts['path'] ?? 'postgres', '/'));
        define('DB_USER', urldecode($parts['user'] ?? 'root'));
        define('DB_PASS', urldecode($parts['pass'] ?? ''));
        define('DB_SSLMODE', 'require');
        $parsed = true;
    }

    if (!$parsed) {
        define('DB_DRIVER', 'mysql');
        define('DB_HOST', '127.0.0.1');
        define('DB_PORT', 3306);
        define('DB_NAME', 'turf_teams');
        define('DB_USER', 'root');
        define('DB_PASS', '');
        define('DB_SSLMODE', 'prefer');
    }
} else {
    // Check individual environment variables (e.g. DB_HOST, DB_USER, DB_PASS)
    define('DB_DRIVER', getEnvVar('DB_DRIVER', 'mysql'));
    define('DB_HOST', getEnvVar('DB_HOST', '127.0.0.1'));
    define('DB_PORT', (int)getEnvVar('DB_PORT', DB_DRIVER === 'pgsql' ? '5432' : '3306'));
    define('DB_NAME', getEnvVar('DB_NAME', DB_DRIVER === 'pgsql' ? 'postgres' : 'turf_teams'));
    define('DB_USER', getEnvVar('DB_USER', 'root'));
    define('DB_PASS', getEnvVar('DB_PASS', ''));
    define('DB_SSLMODE', getEnvVar('DB_SSLMODE', 'prefer'));
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
define('APP_BASE_URL_OVERRIDE', getEnvVar('APP_BASE_URL'));

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
