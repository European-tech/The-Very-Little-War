-- SCHEMA-CRITICAL-001: Add estExclu column to membre (was missing from all migrations)
-- This column marks banned players (1=banned, 0=active)
ALTER TABLE membre ADD COLUMN IF NOT EXISTS estExclu TINYINT(1) NOT NULL DEFAULT 0;
-- Existing players are not banned by default
