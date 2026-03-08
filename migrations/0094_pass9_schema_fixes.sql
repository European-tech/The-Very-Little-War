-- Migration 0094: Pass 9 schema fixes
-- Covers findings: M-015 (prestige.unlocks overflow), L-006 (compound created_at), M-020 (cours index)

-- M-015: prestige.unlocks VARCHAR(255) → TEXT to prevent silent truncation as more unlocks are added
ALTER TABLE prestige MODIFY COLUMN unlocks TEXT NOT NULL DEFAULT '';

-- L-006: Add created_at to player_compounds for future storage-expiry tracking
ALTER TABLE player_compounds ADD COLUMN IF NOT EXISTS created_at INT NOT NULL DEFAULT 0;

-- M-020: Ensure cours.timestamp has an index for probabilistic GC query performance
-- (GC DELETE uses WHERE timestamp < X which is a range scan — index prevents full table scans)
ALTER TABLE cours ADD INDEX IF NOT EXISTS idx_cours_timestamp (timestamp);
