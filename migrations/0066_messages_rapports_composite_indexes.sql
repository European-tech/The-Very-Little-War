-- Migration 0066: Add composite indexes on messages and rapports for inbox queries
-- MEDIUM-022: Common pattern is WHERE destinataire=? AND statut=? which causes
-- full-table scans on large tables.

SET @dbname = DATABASE();

-- Add idx_dest_statut on messages if not already present
SET @idx_msg = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = @dbname
      AND table_name = 'messages'
      AND index_name = 'idx_dest_statut'
);
SET @sql_msg = IF(@idx_msg = 0,
    'ALTER TABLE messages ADD INDEX idx_dest_statut (destinataire(191), statut)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_msg;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add idx_dest_statut on rapports if not already present
SET @idx_rap = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = @dbname
      AND table_name = 'rapports'
      AND index_name = 'idx_dest_statut'
);
SET @sql_rap = IF(@idx_rap = 0,
    'ALTER TABLE rapports ADD INDEX idx_dest_statut (destinataire(191), statut)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_rap;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
