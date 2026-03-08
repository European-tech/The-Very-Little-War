-- Migration 0076: Add composite index on declarations(type, fin) for war query (PERF-P8-002)

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'declarations'
    AND INDEX_NAME = 'idx_declarations_type_fin'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE declarations ADD INDEX idx_declarations_type_fin (type, fin)',
    'SELECT "idx_declarations_type_fin already exists" AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
