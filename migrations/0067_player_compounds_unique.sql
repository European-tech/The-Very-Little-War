-- Migration 0067: Add unique index on (login, compound_id) in player_compounds
-- MEDIUM-023: Without a unique constraint, duplicate rows can be inserted for the
-- same player+compound pair in a race condition, inflating compound buff durations.

-- Only apply if the table exists
SET @tbl = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
      AND table_name = 'player_compounds'
);

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'player_compounds'
      AND index_name = 'uidx_player_compound'
);

SET @sql = IF(@tbl > 0 AND @idx_exists = 0,
    'ALTER TABLE player_compounds ADD UNIQUE INDEX uidx_player_compound (login, compound_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
