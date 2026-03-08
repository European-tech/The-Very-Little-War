-- Migration 0096: Add UNIQUE constraint on season_recap(season_number, login) for idempotency
-- Prevents duplicate archives if archiveSeasonData() is retried (FLOW-SSN-P10-001)
ALTER TABLE season_recap ADD UNIQUE KEY IF NOT EXISTS uk_season_player (season_number, login);
