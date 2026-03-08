-- Migration 0075: Make prestige.total_pp UNSIGNED to enforce DB-level floor (PRES-P7-003)

SET @col_type = (
    SELECT DATA_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'prestige'
    AND COLUMN_NAME = 'total_pp'
);

SET @sql = IF(@col_type = 'int',
    'ALTER TABLE prestige MODIFY COLUMN total_pp INT UNSIGNED DEFAULT 0',
    'SELECT "total_pp already modified" AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
