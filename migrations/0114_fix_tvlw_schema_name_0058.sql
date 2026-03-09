-- P27-010: Migration 0058 hardcoded TABLE_SCHEMA = 'tvlw' — correct it using DATABASE()
-- This is a remediation for the original migration which used the hardcoded database name.
-- The original migration 0058 may have skipped FK/PK additions on non-production environments.
-- This migration re-applies the constraint additions using DATABASE() for portability.

-- Re-apply FK on player_compounds if not already present (idempotent)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'player_compounds'
    AND CONSTRAINT_NAME = 'fk_player_compounds_login'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE player_compounds ADD CONSTRAINT fk_player_compounds_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE',
    'SELECT 1 -- fk_player_compounds_login already exists'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
