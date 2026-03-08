-- Migration 0078: Leaderboard indexes for non-default sort columns
-- Idempotent: each index uses CREATE INDEX IF NOT EXISTS (MariaDB 10.11 supports this)
CREATE INDEX IF NOT EXISTS idx_autre_pointsAttaque ON autre(pointsAttaque);
CREATE INDEX IF NOT EXISTS idx_autre_pointsDefense ON autre(pointsDefense);
CREATE INDEX IF NOT EXISTS idx_autre_ressourcesPillees ON autre(ressourcesPillees);
CREATE INDEX IF NOT EXISTS idx_autre_tradeVolume ON autre(tradeVolume);
CREATE INDEX IF NOT EXISTS idx_autre_victoires ON autre(victoires);
CREATE INDEX IF NOT EXISTS idx_autre_points ON autre(points);
CREATE INDEX IF NOT EXISTS idx_autre_batmax ON autre(batmax);
