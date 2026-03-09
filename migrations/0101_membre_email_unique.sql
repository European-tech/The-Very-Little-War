-- Migration 0101: Add UNIQUE constraint to membre.email
-- FLOW-REG-MEDIUM-003: Without a DB-level UNIQUE constraint the email dedup check in
-- inscription.php is subject to a TOCTOU race — two concurrent registrations with the
-- same address can both pass the SELECT COUNT(*) guard before either INSERT commits.
-- A UNIQUE index makes the DB the authoritative source of truth and lets inscrire()
-- catch the duplicate-key error and return 'email_taken'.
--
-- NOTE: The original schema uses latin1 charset (FK compatibility with other columns).
-- Duplicate emails should not exist in production but we deduplicate just in case.

ALTER TABLE `membre` ADD UNIQUE KEY IF NOT EXISTS `uq_membre_email` (`email`);
