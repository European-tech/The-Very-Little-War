-- Migration 0059: login_history ON UPDATE CASCADE + widen connectes.ip for IPv6
-- PASS4-MEDIUM-002
--
-- Two independent fixes bundled in one migration:
--
-- (1) login_history FK — fk_login_history_login was created in migration 0033
--     with ON DELETE CASCADE only. Login renames would leave login_history rows
--     referencing the old login value. ON UPDATE CASCADE propagates the rename.
--
-- (2) connectes.ip width — the original schema defined ip as VARCHAR(15), which
--     fits only IPv4 addresses (max 15 chars). IPv6 addresses require up to 45
--     characters (e.g. full IPv6 "0000:0000:0000:0000:0000:0000:0000:0000" = 39
--     chars; IPv4-mapped "::ffff:255.255.255.255" = 22 chars; with zone IDs up
--     to 45). membre.ip was already widened to VARCHAR(45) in migration 0020.
--     connectes.ip is the PRIMARY KEY — MariaDB allows MODIFY on a PK column
--     when the change is a compatible widening (no data loss, no collation change).

SET FOREIGN_KEY_CHECKS=0;

-- -----------------------------------------------------------------------
-- (1) login_history: drop and re-add FK with ON UPDATE CASCADE
-- -----------------------------------------------------------------------
SET @existFk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'tvlw'
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

-- -----------------------------------------------------------------------
-- (2) connectes.ip: widen to VARCHAR(45) for IPv6 support
--     Column is the PRIMARY KEY; MariaDB supports widening in-place.
--     Idempotency: MODIFY is a no-op if the column is already VARCHAR(45).
-- -----------------------------------------------------------------------
ALTER TABLE connectes MODIFY ip VARCHAR(45) NOT NULL DEFAULT '';

SET FOREIGN_KEY_CHECKS=1;
