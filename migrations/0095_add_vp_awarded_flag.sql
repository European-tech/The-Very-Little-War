-- Migration 0095: Add VP award idempotency flag to autre
-- H-022: Prevents double-awarding victory points if performSeasonEnd() is
-- interrupted and re-run. The vp_awarded flag is set to 1 when VP is granted
-- and reset to 0 during remiseAZero() at the start of each new season.
ALTER TABLE autre ADD COLUMN IF NOT EXISTS vp_awarded INT NOT NULL DEFAULT 0;
