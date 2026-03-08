-- Migration 0077: Convert email_queue subject and body_html columns to utf8mb4.
--
-- P9-HIGH-005: The email_queue table was created with DEFAULT CHARSET=latin1 for FK
-- compatibility with the membre table.  The recipient_email column remains latin1 (it
-- mirrors membre.email which is latin1).  The subject and body_html columns carry
-- player-facing text that may contain multi-byte characters (accented letters, emoji),
-- so they must be utf8mb4 to avoid silent data corruption on INSERT.
--
-- This migration is idempotent: it checks information_schema.COLUMNS and only runs
-- each ALTER if the column is not already utf8mb4.

-- Convert subject column if it is not already utf8mb4
SET @col_subject_charset = (
    SELECT CHARACTER_SET_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'email_queue'
      AND COLUMN_NAME  = 'subject'
);

SET @alter_subject = IF(
    @col_subject_charset != 'utf8mb4',
    'ALTER TABLE email_queue MODIFY COLUMN subject VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
    'SELECT 1 -- subject already utf8mb4, no change needed'
);

PREPARE stmt FROM @alter_subject;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Convert body_html column if it is not already utf8mb4
SET @col_body_charset = (
    SELECT CHARACTER_SET_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'email_queue'
      AND COLUMN_NAME  = 'body_html'
);

SET @alter_body = IF(
    @col_body_charset != 'utf8mb4',
    'ALTER TABLE email_queue MODIFY COLUMN body_html TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
    'SELECT 1 -- body_html already utf8mb4, no change needed'
);

PREPARE stmt FROM @alter_body;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
