-- Migration 0079: Add prestige_awarded_season to statistiques for idempotency
-- Prevents awardPrestigePoints() from running twice in the same season
-- (e.g. if performSeasonEnd() is called concurrently or retried).
ALTER TABLE statistiques ADD COLUMN IF NOT EXISTS prestige_awarded_season INT DEFAULT 0 NOT NULL;
