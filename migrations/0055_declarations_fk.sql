-- Migration 0055: Add FK constraints from declarations to alliances
-- declarations.alliance1 and declarations.alliance2 reference alliances.id
-- but had no FK constraint, allowing orphaned war/pact records on alliance deletion.
-- ON DELETE CASCADE removes the declaration when either alliance is deleted.

SET FOREIGN_KEY_CHECKS=0;

-- Clean up any orphaned rows first
DELETE FROM declarations
WHERE alliance1 NOT IN (SELECT id FROM alliances)
   OR alliance2 NOT IN (SELECT id FROM alliances);

-- Ensure InnoDB engine (migration 0013 handles this globally, guard for idempotency)
ALTER TABLE declarations ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Add FK on alliance1 (idempotent via information_schema check)
SET @exist1 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='declarations'
    AND CONSTRAINT_NAME='fk_declarations_alliance_a');
SET @sql1 = IF(@exist1 = 0,
    'ALTER TABLE declarations ADD CONSTRAINT fk_declarations_alliance_a FOREIGN KEY (alliance1) REFERENCES alliances(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

-- Add FK on alliance2 (idempotent via information_schema check)
SET @exist2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='declarations'
    AND CONSTRAINT_NAME='fk_declarations_alliance_b');
SET @sql2 = IF(@exist2 = 0,
    'ALTER TABLE declarations ADD CONSTRAINT fk_declarations_alliance_b FOREIGN KEY (alliance2) REFERENCES alliances(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET FOREIGN_KEY_CHECKS=1;
