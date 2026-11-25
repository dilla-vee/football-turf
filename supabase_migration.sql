-- ============================================================================
-- Turf Teams - Supabase PostgreSQL Migration
-- Copy and paste this script into Supabase -> SQL Editor and click "Run".
-- Safe to run on top of your existing Supabase database!
-- ============================================================================

-- 1. Ensure Otis and Maina are tier 'skilled' per the official match sheet
UPDATE players SET tier = 'skilled' WHERE LOWER(name) IN ('otis', 'maina');

-- 2. Create Match State Table (if not exists)
CREATE TABLE IF NOT EXISTS match_state (
    id INT PRIMARY KEY,
    state VARCHAR(20) NOT NULL DEFAULT 'setup',
    started_at TIMESTAMPTZ NULL,
    ended_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Seed initial row in 'setup' state (id = 1)
INSERT INTO match_state (id, state, started_at, ended_at) 
VALUES (1, 'setup', NULL, NULL)
ON CONFLICT (id) DO NOTHING;

-- 3. Create Goals Table (if not exists)
CREATE TABLE IF NOT EXISTS goals (
    id SERIAL PRIMARY KEY,
    player_id INT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    team_id INT NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    is_own_goal BOOLEAN NOT NULL DEFAULT FALSE,
    match_minute INT NOT NULL,
    match_second INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Indexes for lightning-fast queries
CREATE INDEX IF NOT EXISTS idx_goals_team ON goals(team_id);
CREATE INDEX IF NOT EXISTS idx_goals_player ON goals(player_id);
CREATE INDEX IF NOT EXISTS idx_goals_created ON goals(created_at);

-- 4. Ensure Team 3 exists and team_mode setting exists
INSERT INTO settings (setting_key, setting_value) 
VALUES ('team_mode', '2_teams')
ON CONFLICT (setting_key) DO NOTHING;

INSERT INTO teams (id, name)
VALUES (3, 'Parklands FC')
ON CONFLICT (id) DO NOTHING;

-- 5. If Oliver & Jimmy were both registered on Makongeni FC from before,
-- reset Oliver so Oliver can pick Runda FC under the new rival rule:
UPDATE players SET team_id = NULL, picked_at = NULL WHERE LOWER(name) = 'oliver' AND team_id = (SELECT team_id FROM players WHERE LOWER(name) = 'jimmy');

-- 6. Match History & Ballon d'Or Archives
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
