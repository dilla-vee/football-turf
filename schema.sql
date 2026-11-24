-- Turf Teams Database Schema & Seed Data (MySQL / phpMyAdmin)
-- Import this file in phpMyAdmin or run via MySQL CLI

CREATE DATABASE IF NOT EXISTS `turf_teams` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `turf_teams`;

-- 1. Teams Table
DROP TABLE IF EXISTS `players`;
DROP TABLE IF EXISTS `teams`;
DROP TABLE IF EXISTS `settings`;

CREATE TABLE `teams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Teams
INSERT INTO `teams` (`id`, `name`) VALUES 
(1, 'Runda FC'),
(2, 'Makongeni FC');

-- 2. Players Table
CREATE TABLE `players` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `tier` ENUM('skilled', 'regular') NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `team_id` INT NULL DEFAULT NULL,
    `picked_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_player_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
    INDEX `idx_token` (`token`),
    INDEX `idx_team_tier` (`team_id`, `tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Settings Table
CREATE TABLE `settings` (
    `setting_key` VARCHAR(50) PRIMARY KEY,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES 
('picking_locked', '0'),
('app_title', 'Turf Teams');

-- ============================================================================
-- 4. Seed Players from Match Sheet
-- ============================================================================
-- 10 SKILLED PLAYERS (5 Head-to-Head Opponent Pairs, Cap: 5 per team):
--   Pair 1: Jimmy vs Oliver
--   Pair 2: Jensen vs Eddie
--   Pair 3: Maxy vs Kristian
--   Pair 4: Spruu vs Marto
--   Pair 5: Otis vs Maina
--
-- 9 REGULAR PLAYERS (Cap: 5 per team):
--   Josee, Cyro, Don, Kimi, Laban, Fidel, Zack, Joe, Demicko
-- ============================================================================
INSERT INTO `players` (`id`, `name`, `tier`, `token`, `team_id`, `picked_at`) VALUES
-- 10 Skilled Players (5 pairs, 5 per team max)
(1, 'Jimmy', 'skilled', '291c9104b29acd1934f2b669771c13ba', NULL, NULL),
(2, 'Oliver', 'skilled', '2cfc36e668eee6e18c96c08bee581d6f', NULL, NULL),
(3, 'Jensen', 'skilled', 'a47a13d1abbe5a4926b4264542cacbe7', NULL, NULL),
(4, 'Eddie', 'skilled', '700dd40ab62dbf1d7aaac2ba48112c71', NULL, NULL),
(5, 'Maxy', 'skilled', 'ee11595ec62b50adaab88af126b694d5', NULL, NULL),
(6, 'Kristian', 'skilled', '3d38c0a96f36625e9f7a9d52bc6e9bcc', NULL, NULL),
(7, 'Spruu', 'skilled', '6a6c02c176568cc55cc067289c8d4a44', NULL, NULL),
(8, 'Marto', 'skilled', '63051bcbb3c0a4ec88b0f06d2da0f79b', NULL, NULL),
(9, 'Otis', 'skilled', '4b3aaef91ae04df77bc960579de3520c', NULL, NULL),
(13, 'Maina', 'skilled', 'bc8d67a9902eda5e6ab506b3de6fc6d3', NULL, NULL),

-- 9 Regular Players (5 per team max)
(10, 'Josee', 'regular', '3622253a346bf02104f96b34f9193e8a', NULL, NULL),
(11, 'Cyro', 'regular', '111316ace8decde053f613c2cd0ac677', NULL, NULL),
(12, 'Don', 'regular', 'c2694bba07116468b7d318f96ad846e1', NULL, NULL),
(14, 'Kimi', 'regular', '8541e10ad454370b0a927e00f2f78188', NULL, NULL),
(15, 'Laban', 'regular', '80e6e4653dd718b66f81ad1073b74fbd', NULL, NULL),
(16, 'Fidel', 'regular', 'f812ce1b6f12e38c4d5cabc052633660', NULL, NULL),
(17, 'Zack', 'regular', '651490f143c22b0ae259439dcc0a8c9d', NULL, NULL),
(18, 'Joe', 'regular', '74ef21931f2e347b0ec6c3e3e9712932', NULL, NULL),
(19, 'Demicko', 'regular', 'c0e7cd9a624f32e7c444a009213865b6', NULL, NULL);
