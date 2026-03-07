-- Migration 0034: Add alliance_left_at column for rejoin cooldown
-- Tracks when a player last left or was kicked from an alliance.
-- Used by PASS1-MEDIUM-012 to enforce ALLIANCE_REJOIN_COOLDOWN_SECONDS (24h default).

ALTER TABLE autre
    ADD COLUMN IF NOT EXISTS alliance_left_at INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Unix timestamp of when player last left/was kicked from an alliance';

-- Index for fast lookup during invite-accept cooldown check
CREATE INDEX IF NOT EXISTS idx_autre_alliance_left_at
    ON autre (login, alliance_left_at);
