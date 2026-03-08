-- Migration 0099: Add season_vp_awarded flag to alliances for idempotent VP awards.
-- Without this flag, a double-invocation of performSeasonEnd() would add alliance VP twice.
ALTER TABLE alliances ADD COLUMN IF NOT EXISTS season_vp_awarded TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE alliances ADD INDEX IF NOT EXISTS idx_season_vp_awarded (season_vp_awarded);
