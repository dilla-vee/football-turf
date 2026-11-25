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

// 1. Check for cloud DATABASE_URL (supports DATABASE_URL, DATABASE_URI, POSTGRES_URL, or user custom keys)
$databaseUrl = getEnvVar('DATABASE_URL')
    ?: (getEnvVar('DATABASE_URI')
    ?: (getEnvVar('POSTGRES_URL')
    ?: (getEnvVar('turf2')
    ?: (getEnvVar('turrf')
    ?: (getEnvVar('TURF_DB')
    ?: null)))));

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

// Default Team Balance Rules (2 Teams Mode)
define('MAX_SKILLED_PER_TEAM', 5);
define('MAX_REGULAR_PER_TEAM', 5);
define('TOTAL_PLAYERS_PER_TEAM', 10);
define('TOTAL_PLAYERS_ALLOWED', 20);

/**
 * Get active team mode ('2_teams' or '3_teams').
 */
function getTeamMode(PDO $pdo, bool $refresh = false): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'team_mode'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return ($val === '3_teams') ? '3_teams' : '2_teams';
    } catch (Throwable $e) {
        return '2_teams';
    }
}

/**
 * Get capacity parameters based on active match mode:
 * - 2 Teams: 2 squads of 9-10 (5 skilled max, 5 regular max)
 * - 3 Teams: 3 squads of 6 (4 skilled max, 3 regular max)
 */
function getTeamCaps(string $teamMode): array {
    if ($teamMode === '3_teams') {
        return [
            'mode' => '3_teams',
            'team_count' => 3,
            'max_team_size' => 6,
            'max_skilled_per_team' => 4,
            'max_regular_per_team' => 3,
            'total_target' => 18
        ];
    }
    return [
        'mode' => '2_teams',
        'team_count' => 2,
        'max_team_size' => 10,
        'max_skilled_per_team' => 5,
        'max_regular_per_team' => 5,
        'total_target' => 18
    ];
}

/**
 * Auto-ensures database supports 3rd team and mode settings across MySQL, PostgreSQL and SQLite.
 */
function ensureDatabaseTeamSupport(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    try {
        // Ensure team_mode setting exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'team_mode'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('team_mode', '2_teams')");
            $ins->execute();
        }

        // Ensure Team 3 exists
        $stmtT3 = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE id = 3");
        $stmtT3->execute();
        if ((int)$stmtT3->fetchColumn() === 0) {
            $insT3 = $pdo->prepare("INSERT INTO teams (id, name) VALUES (3, 'Parklands FC')");
            $insT3->execute();
        }
    } catch (Throwable $e) {
        // Non-blocking if table not ready yet
    }
}

/**
 * Auto-ensures match history & Ballon d'Or tables exist across MySQL, PostgreSQL and SQLite.
 */
function ensureMatchHistoryTables(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS match_history (
                    id SERIAL PRIMARY KEY,
                    match_code VARCHAR(32) NOT NULL,
                    team_mode VARCHAR(16) NOT NULL DEFAULT '2_teams',
                    started_at TIMESTAMPTZ NULL,
                    ended_at TIMESTAMPTZ NULL,
                    duration_seconds INT NOT NULL DEFAULT 0,
                    duration_formatted VARCHAR(16) NOT NULL DEFAULT '00:00',
                    teams_summary TEXT NOT NULL DEFAULT '[]',
                    winner_team_name VARCHAR(100) NULL,
                    top_scorer_name VARCHAR(100) NULL,
                    top_scorer_goals INT NOT NULL DEFAULT 0,
                    man_of_the_match VARCHAR(100) NULL,
                    total_goals INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );

                CREATE TABLE IF NOT EXISTS match_history_goals (
                    id SERIAL PRIMARY KEY,
                    match_id INT NOT NULL REFERENCES match_history(id) ON DELETE CASCADE,
                    player_id INT NULL,
                    player_name VARCHAR(100) NOT NULL,
                    team_id INT NULL,
                    team_name VARCHAR(100) NOT NULL,
                    is_own_goal BOOLEAN NOT NULL DEFAULT FALSE,
                    match_minute INT NOT NULL DEFAULT 0,
                    match_second INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );

                CREATE INDEX IF NOT EXISTS idx_mh_created ON match_history(created_at);
                CREATE INDEX IF NOT EXISTS idx_mhg_match ON match_history_goals(match_id);
                CREATE INDEX IF NOT EXISTS idx_mhg_player ON match_history_goals(player_name);
            ");
        } else {
            // MySQL and SQLite
            $autoInc = ($driver === 'sqlite') ? 'AUTOINCREMENT' : 'AUTO_INCREMENT';
            $dtType = ($driver === 'sqlite') ? 'TEXT' : 'DATETIME';
            $engine = ($driver === 'mysql') ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
            $boolType = ($driver === 'sqlite') ? 'INTEGER' : 'TINYINT(1)';
            $nowDefault = ($driver === 'sqlite') ? 'TEXT DEFAULT CURRENT_TIMESTAMP' : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS match_history (
                    id INTEGER PRIMARY KEY {$autoInc},
                    match_code VARCHAR(32) NOT NULL,
                    team_mode VARCHAR(16) NOT NULL DEFAULT '2_teams',
                    started_at {$dtType} NULL,
                    ended_at {$dtType} NULL,
                    duration_seconds INT NOT NULL DEFAULT 0,
                    duration_formatted VARCHAR(16) NOT NULL DEFAULT '00:00',
                    teams_summary TEXT NOT NULL,
                    winner_team_name VARCHAR(100) NULL,
                    top_scorer_name VARCHAR(100) NULL,
                    top_scorer_goals INT NOT NULL DEFAULT 0,
                    man_of_the_match VARCHAR(100) NULL,
                    total_goals INT NOT NULL DEFAULT 0,
                    created_at {$nowDefault}
                ) {$engine}
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS match_history_goals (
                    id INTEGER PRIMARY KEY {$autoInc},
                    match_id INT NOT NULL,
                    player_id INT NULL,
                    player_name VARCHAR(100) NOT NULL,
                    team_id INT NULL,
                    team_name VARCHAR(100) NOT NULL,
                    is_own_goal {$boolType} NOT NULL DEFAULT 0,
                    match_minute INT NOT NULL DEFAULT 0,
                    match_second INT NOT NULL DEFAULT 0,
                    created_at {$nowDefault}
                ) {$engine}
            ");
        }
    } catch (Throwable $e) {
        // Non-blocking fallback
    }
}

/**
 * Head-to-Head rival pairing rules for skilled players based on official match sheet:
 * - Jimmy vs Oliver
 * - Jensen vs Eddie
 * - Maxy vs Kristian
 * - Spruu vs Marto
 * - Otis vs Maina
 */
function getPlayerRivalName(string $playerName): ?string {
    $pairs = [
        'jimmy'     => 'Oliver',
        'oliver'    => 'Jimmy',
        'olive'     => 'Jimmy',
        'jensen'    => 'Eddie',
        'eddie'     => 'Jensen',
        'eddy'      => 'Jensen',
        'maxy'      => 'Kristian',
        'max'       => 'Kristian',
        'kristian'  => 'Maxy',
        'christian' => 'Maxy',
        'spruu'     => 'Marto',
        'marto'     => 'Spruu',
        'otis'      => 'Maina',
        'maina'     => 'Otis',
    ];
    $clean = strtolower(trim($playerName));
    return $pairs[$clean] ?? null;
}

/**
 * Searches the database for the given player's paired rival opponent.
 */
function findRivalPlayer(PDO $pdo, string $playerName): ?array {
    $rivalName = getPlayerRivalName($playerName);
    if (!$rivalName) {
        return null;
    }

    $aliases = [$rivalName];
    $lower = strtolower($rivalName);
    if ($lower === 'oliver') $aliases[] = 'olive';
    if ($lower === 'olive') $aliases[] = 'oliver';
    if ($lower === 'eddie') $aliases[] = 'eddy';
    if ($lower === 'eddy') $aliases[] = 'eddie';
    if ($lower === 'kristian') $aliases[] = 'christian';
    if ($lower === 'christian') $aliases[] = 'kristian';
    if ($lower === 'maxy') $aliases[] = 'max';
    if ($lower === 'max') $aliases[] = 'maxy';

    $inClause = implode(',', array_fill(0, count($aliases), '?'));
    $params = array_map('strtolower', $aliases);

    $stmt = $pdo->prepare("SELECT id, name, team_id, tier, token FROM players WHERE LOWER(name) IN ($inClause) LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Admin Authentication
// Default Admin password is 'turf2026'
// Pre-calculated hash: password_hash('turf2026', PASSWORD_BCRYPT)
define('ADMIN_PASSWORD_HASH', '$2y$10$vKVf0gGKdsALFucdrgC.QeK3RigJU0okf7SoN4lQKKVrl9GlW2aYO');
define('ADMIN_SESSION_KEY', 'turf_admin_auth');

/**
 * Verifies admin password against default, env var, or bcrypt hash.
 */
function verifyAdminPassword(string $inputPassword): bool {
    $clean = trim($inputPassword);
    if (empty($clean)) {
        return false;
    }

    // 1. Default password match
    if (strtolower($clean) === 'turf2026') {
        return true;
    }

    // 2. Environment variable plain password match
    $envPass = getEnvVar('ADMIN_PASSWORD');
    if (!empty($envPass) && (hash_equals($envPass, $clean) || strtolower($envPass) === strtolower($clean))) {
        return true;
    }

    // 3. Bcrypt hash match
    $envHash = getEnvVar('ADMIN_PASSWORD_HASH') ?: ADMIN_PASSWORD_HASH;
    if (!empty($envHash) && password_verify($clean, $envHash)) {
        return true;
    }

    return false;
}

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
