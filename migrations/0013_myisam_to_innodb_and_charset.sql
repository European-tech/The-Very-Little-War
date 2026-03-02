-- Migration 0013: Convert MyISAM tables to InnoDB and standardize charset to utf8mb4
-- Prerequisite for transaction support (InnoDB required for BEGIN/COMMIT)

-- Convert MyISAM tables to InnoDB
ALTER TABLE declarations ENGINE=InnoDB;
ALTER TABLE moderation ENGINE=InnoDB;
ALTER TABLE statutforum ENGINE=InnoDB;

-- Add missing primary keys
ALTER TABLE connectes ADD PRIMARY KEY (ip) IF NOT EXISTS;

-- Fix column type mismatches
ALTER TABLE declarations MODIFY alliance1 INT DEFAULT NULL;
ALTER TABLE declarations MODIFY alliance2 INT DEFAULT NULL;

-- Widen attack_cooldowns login columns to match membre table
ALTER TABLE attack_cooldowns MODIFY attacker VARCHAR(255) NOT NULL;
ALTER TABLE attack_cooldowns MODIFY defender VARCHAR(255) NOT NULL;

-- Add spatial indexes for map queries
ALTER TABLE membre ADD INDEX IF NOT EXISTS idx_membre_xy (x, y);

-- Add index for cours table performance
ALTER TABLE cours ADD INDEX IF NOT EXISTS idx_cours_timestamp (timestamp);

-- Cleanup: add expired cooldown cleanup
DELETE FROM attack_cooldowns WHERE expires_at < NOW();
