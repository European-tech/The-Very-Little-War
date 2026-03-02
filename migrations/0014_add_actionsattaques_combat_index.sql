-- Add composite index on actionsattaques for combat resolution queries
-- Finding DB-007: Missing composite index on actionsattaques causes suboptimal
-- query plans during combat resolution when filtering by player and ordering by time.
--
-- Single-column indexes on attaquant, defenseur, and tempsAttaque already exist
-- (added in 0001_add_indexes.sql). This migration adds a composite index to
-- optimize the most common combat query pattern:
--   SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque
--
-- Also adds an index on attaqueFaite to speed up filtering unprocessed attacks.

ALTER TABLE `actionsattaques` ADD INDEX `idx_attaques_attaquant_temps` (`attaquant`, `tempsAttaque`);
ALTER TABLE `actionsattaques` ADD INDEX `idx_attaques_defenseur_temps` (`defenseur`, `tempsAttaque`);
ALTER TABLE `actionsattaques` ADD INDEX `idx_attaques_fait` (`attaqueFaite`);
