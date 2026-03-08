-- Migration 0085: Add expiry index on player_compounds for performance
-- NOTE: The table is named player_compounds (not compound_synthesis).
ALTER TABLE player_compounds ADD INDEX idx_expires (expires_at);
