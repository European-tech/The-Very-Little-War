-- Migration 0072: Composite index on login_history for anti-multiaccount self-join
-- DB-P6-005: The shared-IP detection query self-joins login_history on (ip, login, timestamp).
-- Without a composite index, the join degrades to O(n²). This index makes it O(n log n).

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'login_history'
      AND index_name = 'idx_lh_ip_login_ts'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE login_history ADD INDEX idx_lh_ip_login_ts (ip, login, timestamp)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
