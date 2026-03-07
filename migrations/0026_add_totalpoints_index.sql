-- M-040: Add index on autre.totalPoints for faster ranking queries
-- Also add index on autre.idalliance for alliance lookups
-- LOW-040: Note: migration 0015 also adds idx_totalPoints with the same IF NOT EXISTS guard.
-- Both statements are idempotent; running 0026 after 0015 is safe — MariaDB will no-op the duplicate.
ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_totalPoints (totalPoints);
