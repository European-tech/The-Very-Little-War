-- Migration 0019: Fix actionsformation.idclasse from INT to VARCHAR
-- The column stores 'neutrino' as a string sentinel value and molecule class IDs as integers.
-- INT silently truncates 'neutrino' to 0, breaking neutrino formation detection.
-- LOW-041: Note: migration 0015 already applied this same MODIFY statement.
-- This MODIFY is idempotent on MariaDB 10.2+ — re-running on an already-VARCHAR column is a safe no-op.
-- This migration is kept for documentation purposes and sequential runner compatibility.

ALTER TABLE actionsformation MODIFY idclasse VARCHAR(50) NOT NULL DEFAULT '0';
