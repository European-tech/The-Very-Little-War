-- Migration 0088: Add ON DELETE CASCADE to forum_messages FK to forum_sujets
-- First drop existing FK, then re-add with CASCADE
SET @fk_name = (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_messages' AND REFERENCED_TABLE_NAME = 'forum_sujets'
  LIMIT 1
);
SET @sql = CONCAT('ALTER TABLE forum_messages DROP FOREIGN KEY ', @fk_name);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE forum_messages ADD CONSTRAINT fk_fm_sujet
  FOREIGN KEY (sujet_id) REFERENCES forum_sujets(id) ON DELETE CASCADE;
