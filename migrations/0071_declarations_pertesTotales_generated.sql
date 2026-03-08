-- Migration 0071: Make declarations.pertesTotales a generated column
-- DB-P6-002: pertesTotales was never written by PHP code (only pertes1/pertes2 updated individually).
-- This caused the season war archive (WHERE pertesTotales != 0) to always be empty.
-- Fix: GENERATED ALWAYS AS (pertes1 + pertes2) STORED so it stays current automatically.

-- Only apply if the column isn't already generated
SET @col_type = (
    SELECT EXTRA FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'declarations'
      AND column_name = 'pertesTotales'
);

SET @sql = IF(
    @col_type NOT LIKE '%GENERATED%' AND @col_type IS NOT NULL,
    'ALTER TABLE declarations MODIFY COLUMN pertesTotales INT GENERATED ALWAYS AS (pertes1 + pertes2) STORED',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
