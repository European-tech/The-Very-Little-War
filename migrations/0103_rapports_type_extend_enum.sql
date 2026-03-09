-- SCHEMA-HIGH-001: Extend rapports.type ENUM to include 'alliance' type
-- allianceadmin.php inserts reports with type='alliance' but it wasn't in ENUM
ALTER TABLE rapports MODIFY COLUMN `type` ENUM('attack','espionage','defense','alliance') NOT NULL DEFAULT 'attack';
