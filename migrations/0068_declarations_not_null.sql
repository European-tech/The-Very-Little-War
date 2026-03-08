-- Migration 0068: Enforce NOT NULL on declarations.declarant and declarations.cible
-- MEDIUM-024: NULL values in declarant/cible would break JOIN lookups and allow
-- orphaned declaration rows that bypass alliance war logic.

-- First, remove any orphaned rows with NULL declarant or cible
DELETE FROM declarations WHERE declarant IS NULL OR cible IS NULL;

-- Then enforce NOT NULL constraint on both columns
ALTER TABLE declarations
    MODIFY declarant VARCHAR(20) NOT NULL,
    MODIFY cible VARCHAR(20) NOT NULL;
