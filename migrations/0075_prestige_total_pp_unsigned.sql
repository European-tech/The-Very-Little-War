-- Migration 0075: Make prestige.total_pp UNSIGNED to enforce DB-level floor (PRES-P7-003)

SELECT COLUMN_TYPE INTO @col_type FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestige' AND COLUMN_NAME = 'total_pp';

IF @col_type != 'int(10) unsigned' THEN
    ALTER TABLE prestige MODIFY COLUMN total_pp INT UNSIGNED NOT NULL DEFAULT 0;
END IF;
