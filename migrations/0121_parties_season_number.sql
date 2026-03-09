-- P29-MED-SEASON-001: Add season_number to parties for idempotency guard.
-- performSeasonEnd() Phase 1a inserts a row into parties with no idempotency check.
-- If the reset crashes after this INSERT and is retried, a duplicate parties row is created.
-- Adding a UNIQUE season_number column lets the INSERT IGNORE skip on retry.
-- The column is nullable (DEFAULT NULL) so existing rows (without a season number) are preserved.
ALTER TABLE parties ADD COLUMN IF NOT EXISTS season_number INT DEFAULT NULL;
ALTER TABLE parties ADD UNIQUE INDEX IF NOT EXISTS idx_parties_season_number (season_number);
