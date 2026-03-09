-- Migration 0105: Add 'combat' to rapports.type ENUM
-- CRITICAL: game_actions.php inserts type='combat' for battle reports but the ENUM
-- (set by migration 0103) only covers 'attack','espionage','defense','alliance'.
-- A strict-mode MariaDB would reject these inserts with an invalid-enum error.
ALTER TABLE rapports MODIFY COLUMN `type` ENUM('attack','espionage','defense','alliance','combat') NOT NULL DEFAULT 'attack';
