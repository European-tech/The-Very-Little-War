-- Migration 0013: Convert MyISAM tables to InnoDB and standardize charset to utf8mb4
-- Prerequisite for transaction support (InnoDB required for BEGIN/COMMIT)

-- Convert MyISAM tables to InnoDB
ALTER TABLE declarations ENGINE=InnoDB;
ALTER TABLE moderation ENGINE=InnoDB;
ALTER TABLE statutforum ENGINE=InnoDB;

-- Add missing primary keys (check first to avoid error)
-- ALTER TABLE connectes ADD PRIMARY KEY (ip); -- Run manually if connectes has no PK

-- Fix column type mismatches
ALTER TABLE declarations MODIFY alliance1 INT DEFAULT NULL;
ALTER TABLE declarations MODIFY alliance2 INT DEFAULT NULL;

-- Widen attack_cooldowns login columns to match membre table
ALTER TABLE attack_cooldowns MODIFY attacker VARCHAR(255) NOT NULL;
ALTER TABLE attack_cooldowns MODIFY defender VARCHAR(255) NOT NULL;

-- Add spatial indexes for map queries (MariaDB supports IF NOT EXISTS on CREATE INDEX)
CREATE INDEX IF NOT EXISTS idx_membre_xy ON membre (x, y);

-- Add index for cours table performance
CREATE INDEX IF NOT EXISTS idx_cours_timestamp ON cours (timestamp);

-- Cleanup expired cooldowns (column is 'expires' as unix timestamp)
DELETE FROM attack_cooldowns WHERE expires < UNIX_TIMESTAMP();
