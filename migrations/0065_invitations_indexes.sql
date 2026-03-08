-- Migration 0065: Add indexes to invitations table for faster lookups
-- MEDIUM-021: invitations table lacks indexes on invite and idalliance columns,
-- causing full-table scans when checking alliance membership.

SET @dbname = DATABASE();

-- Add index on invite column if not already present
SET @idx_invite = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = @dbname
      AND table_name = 'invitations'
      AND index_name = 'idx_invite'
);
SET @sql_invite = IF(@idx_invite = 0,
    'ALTER TABLE invitations ADD INDEX idx_invite (invite(191))',
    'SELECT 1'
);
PREPARE stmt FROM @sql_invite;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on idalliance column if not already present
SET @idx_idalliance = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = @dbname
      AND table_name = 'invitations'
      AND index_name = 'idx_idalliance'
);
SET @sql_idalliance = IF(@idx_idalliance = 0,
    'ALTER TABLE invitations ADD INDEX idx_idalliance (idalliance)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_idalliance;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
