-- P27-010: Migration 0059 hardcoded TABLE_SCHEMA = 'tvlw' — correct using DATABASE()
-- Re-applies the FK drop check for login_history using DATABASE() instead of 'tvlw'.

SET FOREIGN_KEY_CHECKS=0;

-- Re-apply: drop and re-add FK with ON UPDATE CASCADE (idempotent)
SET @existFk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'login_history'
      AND CONSTRAINT_NAME = 'fk_login_history_login'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @dropFk = IF(@existFk > 0,
    'ALTER TABLE login_history DROP FOREIGN KEY fk_login_history_login',
    'SELECT 1');
PREPARE stmt1 FROM @dropFk; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

ALTER TABLE login_history
    ADD CONSTRAINT fk_login_history_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS=1;
