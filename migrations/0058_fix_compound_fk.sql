-- Migration 0058: Add ON UPDATE CASCADE to player_compounds FK
-- PASS4-MEDIUM-001
--
-- The fk_compounds_login constraint was created in migration 0024 with
-- ON DELETE CASCADE only. A login rename (membre.login UPDATE) would leave
-- player_compounds.login pointing at the old value, breaking referential
-- integrity. Adding ON UPDATE CASCADE closes that gap.
--
-- Pattern: drop-and-recreate with FOREIGN_KEY_CHECKS=0 to avoid needing an
-- empty table, then restore. The drop is guarded by an existence check so
-- re-running this migration is safe.

SET FOREIGN_KEY_CHECKS=0;

-- Drop existing FK (idempotent: no-op if already absent)
-- P28-MED-002: Use DATABASE() instead of hardcoded 'tvlw' so this works on any environment.
SET @existFk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'player_compounds'
      AND CONSTRAINT_NAME = 'fk_compounds_login'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @dropFk = IF(@existFk > 0,
    'ALTER TABLE player_compounds DROP FOREIGN KEY fk_compounds_login',
    'SELECT 1');
PREPARE stmt1 FROM @dropFk; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

-- Re-add FK with both ON DELETE CASCADE and ON UPDATE CASCADE
ALTER TABLE player_compounds
    ADD CONSTRAINT fk_compounds_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS=1;
