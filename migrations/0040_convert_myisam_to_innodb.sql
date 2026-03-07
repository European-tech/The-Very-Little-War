-- Migration 0040: Convert remaining MyISAM tables to InnoDB (HIGH-028)
-- messages and sanctions were not converted in 0013_myisam_to_innodb_and_charset.sql.
-- InnoDB is required for foreign key support, crash recovery, and row-level locking.

ALTER TABLE messages ENGINE=InnoDB;
ALTER TABLE sanctions ENGINE=InnoDB;
