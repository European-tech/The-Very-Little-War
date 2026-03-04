-- Migration 0019: Fix actionsformation.idclasse from INT to VARCHAR
-- The column stores 'neutrino' as a string sentinel value and molecule class IDs as integers.
-- INT silently truncates 'neutrino' to 0, breaking neutrino formation detection.

ALTER TABLE actionsformation MODIFY idclasse VARCHAR(50) NOT NULL DEFAULT '0';
