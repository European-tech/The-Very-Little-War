-- Migration 0068: declarations table schema note
-- MEDIUM-024 originally targeted declarant/cible columns on a player-to-player
-- declarations table. The actual 'declarations' table is for alliance wars
-- (columns: id, type, alliance1, alliance2, timestamp, pertes1, pertes2, fin,
-- pertesTotales, valide). No declarant/cible columns exist.
-- The alliance1/alliance2 FK was already added in migration 0055.
-- This migration is a no-op.
SELECT 1 AS migration_applied;
