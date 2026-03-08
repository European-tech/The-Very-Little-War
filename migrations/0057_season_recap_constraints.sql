-- Migration 0057: season_recap ON UPDATE CASCADE + UNIQUE constraint
-- The FK added in 0054 lacked ON UPDATE CASCADE (login renames would orphan rows).
-- A UNIQUE constraint on (login, season_number) prevents duplicate recap entries.

-- Remove duplicate rows before adding the unique constraint (keep lowest id per pair)
DELETE s1 FROM season_recap s1
INNER JOIN season_recap s2
ON s1.login = s2.login AND s1.season_number = s2.season_number AND s1.id > s2.id;

-- Drop existing FK (migration 0054 added it without ON UPDATE CASCADE)
ALTER TABLE season_recap DROP FOREIGN KEY IF EXISTS fk_season_recap_login;

-- Re-add FK with both ON DELETE CASCADE and ON UPDATE CASCADE
ALTER TABLE season_recap
    ADD CONSTRAINT fk_season_recap_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Add UNIQUE constraint (idempotent via information_schema check)
SET @existUq = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA='tvlw' AND TABLE_NAME='season_recap'
    AND CONSTRAINT_NAME='uq_season_login');
SET @addUq = IF(@existUq = 0,
    'ALTER TABLE season_recap ADD UNIQUE KEY uq_season_login (login, season_number)',
    'SELECT 1');
PREPARE stmt4 FROM @addUq; EXECUTE stmt4; DEALLOCATE PREPARE stmt4;
