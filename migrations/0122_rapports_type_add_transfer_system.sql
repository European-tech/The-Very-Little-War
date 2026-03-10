-- Migration 0122: Add 'transfer' and 'system' to rapports.type ENUM
-- marche.php and system notifications may insert type='transfer' or type='system'
-- but the ENUM (set by migration 0105) only covers
-- 'attack','espionage','defense','alliance','combat'.
-- A strict-mode MariaDB would reject these inserts with an invalid-enum error.
ALTER TABLE rapports
  MODIFY COLUMN `type`
    ENUM('attack','espionage','defense','alliance','combat','transfer','system')
    NOT NULL DEFAULT 'attack';
