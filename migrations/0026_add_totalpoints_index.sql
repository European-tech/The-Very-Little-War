-- M-040: Add index on autre.totalPoints for faster ranking queries
-- Also add index on autre.idalliance for alliance lookups
ALTER TABLE autre ADD INDEX idx_totalPoints (totalPoints);
