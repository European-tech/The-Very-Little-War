-- Migration 0048: Safe idempotent grades PK
--
-- MariaDB 10.11 does not support DROP PRIMARY KEY IF EXISTS.
-- Use SET/PREPARE/EXECUTE to conditionally drop the PK only when present.
-- grades schema: (login VARCHAR(255), idalliance INT, grade VARCHAR(255), nom VARCHAR(255))
-- Compound PK (login, idalliance): a player holds at most one grade per alliance.

-- Ensure columns are NOT NULL (required for PK membership). Safe to re-run.
ALTER TABLE grades
    MODIFY login VARCHAR(255) NOT NULL,
    MODIFY idalliance INT NOT NULL;

-- Conditionally drop existing PK using information_schema check
SET @has_pk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @sql_drop := IF(@has_pk > 0, 'ALTER TABLE grades DROP PRIMARY KEY', 'SELECT 1');
PREPARE _stmt FROM @sql_drop;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- Add the compound PK cleanly
ALTER TABLE grades ADD PRIMARY KEY (login, idalliance);
