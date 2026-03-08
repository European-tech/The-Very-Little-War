-- Migration 0074: Add winner column to declarations table (ALL-P7-002)
-- Records which alliance won a war: NULL=ongoing, 0=draw, 1=alliance1 wins, 2=alliance2 wins

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'declarations'
    AND COLUMN_NAME = 'winner'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE declarations ADD COLUMN winner TINYINT DEFAULT NULL COMMENT "NULL=ongoing, 0=draw, 1=alliance1 wins, 2=alliance2 wins"',
    'SELECT "winner column already exists" AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
