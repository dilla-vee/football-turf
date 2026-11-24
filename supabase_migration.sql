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

-- 4. If Oliver & Jimmy were both registered on Makongeni FC from before,
-- reset Oliver so Oliver can pick Runda FC under the new rival rule:
UPDATE players SET team_id = NULL, picked_at = NULL WHERE LOWER(name) = 'oliver' AND team_id = (SELECT team_id FROM players WHERE LOWER(name) = 'jimmy');
