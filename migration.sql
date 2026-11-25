-- ============================================================================
-- Turf Teams - Database Migration: Live Match Scoreboard & Goal Tracking
-- Import this SQL file in phpMyAdmin on top of your existing 'turf_teams' database.
-- ============================================================================

-- 1. Create Match State Table
CREATE TABLE IF NOT EXISTS `match_state` (
    `id` INT PRIMARY KEY,
    `state` ENUM('setup', 'live', 'finished') NOT NULL DEFAULT 'setup',
    `started_at` DATETIME NULL,
    `ended_at` DATETIME NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize single row with id = 1 in 'setup' state
INSERT INTO `match_state` (`id`, `state`, `started_at`, `ended_at`) 
VALUES (1, 'setup', NULL, NULL)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- 2. Create Goals Table
CREATE TABLE IF NOT EXISTS `goals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `player_id` INT NOT NULL,
    `team_id` INT NOT NULL,
    `is_own_goal` TINYINT(1) NOT NULL DEFAULT 0,
    `match_minute` INT NOT NULL,
    `match_second` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_goals_team` (`team_id`),
    INDEX `idx_goals_player` (`player_id`),
    INDEX `idx_goals_created` (`created_at`),
    CONSTRAINT `fk_goals_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_goals_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure Team 3 exists and team_mode setting exists
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('team_mode', '2_teams');
INSERT IGNORE INTO `teams` (`id`, `name`) VALUES (3, 'Parklands FC');

-- 3. Match History & Ballon d'Or Archives
CREATE TABLE IF NOT EXISTS `match_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `match_code` VARCHAR(32) NOT NULL,
    `team_mode` VARCHAR(16) NOT NULL DEFAULT '2_teams',
    `started_at` DATETIME NULL,
    `ended_at` DATETIME NULL,
    `duration_seconds` INT NOT NULL DEFAULT 0,
    `duration_formatted` VARCHAR(16) NOT NULL DEFAULT '00:00',
    `teams_summary` TEXT NOT NULL,
    `winner_team_name` VARCHAR(100) NULL,
    `top_scorer_name` VARCHAR(100) NULL,
    `top_scorer_goals` INT NOT NULL DEFAULT 0,
    `man_of_the_match` VARCHAR(100) NULL,
    `total_goals` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mh_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `match_history_goals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `match_id` INT NOT NULL,
    `player_id` INT NULL,
    `player_name` VARCHAR(100) NOT NULL,
    `team_id` INT NULL,
    `team_name` VARCHAR(100) NOT NULL,
    `is_own_goal` TINYINT(1) NOT NULL DEFAULT 0,
    `match_minute` INT NOT NULL DEFAULT 0,
    `match_second` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mhg_match` (`match_id`),
    INDEX `idx_mhg_player` (`player_name`),
    CONSTRAINT `fk_mhg_match` FOREIGN KEY (`match_id`) REFERENCES `match_history` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
