-- Migration 0070: Limit alliance description to VARCHAR(500) to enforce max length
-- MEDIUM-025: The description column is currently TEXT with no length limit.
-- Capping at 500 chars prevents oversized payload storage and aligns with UI maxlength.

-- Only apply if the column is still TEXT type (idempotent)
SET @col_type = (
    SELECT DATA_TYPE
    FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'alliances'
      AND column_name = 'description'
);

SET @sql = IF(@col_type = 'text',
    "ALTER TABLE alliances MODIFY description VARCHAR(500) NOT NULL DEFAULT ''",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
