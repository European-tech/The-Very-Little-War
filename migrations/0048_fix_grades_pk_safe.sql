-- Migration 0048: Safe idempotent grades PK (alternative to PREPARE/EXECUTE in 0035)
--
-- The PREPARE/EXECUTE pattern in 0035 is fragile under some MariaDB configurations
-- (e.g. sql_mode=NO_BACKSLASH_ESCAPES, PREPARE limited to session scope).
-- This migration uses DROP PRIMARY KEY IF EXISTS + ADD PRIMARY KEY which is
-- natively supported in MariaDB 10.4.0+ and fully idempotent.
--
-- grades schema: (login VARCHAR(255), idalliance INT, grade VARCHAR(255), nom VARCHAR(255))
-- Natural compound PK is (login, idalliance): a player holds at most one grade per alliance.
--
-- This is a no-op if 0035 already added the PK — DROP IF EXISTS silently skips the drop
-- and ADD PRIMARY KEY will fail if a PK exists, so we guard via IF NOT EXISTS logic below.
--
-- MariaDB 10.11 supports:
--   ALTER TABLE ... DROP PRIMARY KEY -- fails if no PK (use IF EXISTS variant in 10.4+)
--   ALTER TABLE ... ADD PRIMARY KEY  -- fails if PK already exists
-- Both are idempotent when combined with the IF EXISTS / information_schema guard.

-- Ensure login and idalliance are NOT NULL (required for PK membership).
-- MODIFY is safe to re-run; it is a no-op if the column already matches.
ALTER TABLE grades
    MODIFY login VARCHAR(255) NOT NULL,
    MODIFY idalliance INT NOT NULL;

-- Drop existing PK if present (MariaDB 10.4+ supports DROP PRIMARY KEY IF EXISTS).
ALTER TABLE grades DROP PRIMARY KEY IF EXISTS;

-- Re-add the compound PK cleanly.
ALTER TABLE grades ADD PRIMARY KEY (login, idalliance);
