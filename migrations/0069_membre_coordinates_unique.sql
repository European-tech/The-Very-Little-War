-- Migration 0069: Coordinate placement race condition — no-op
-- NOTE: The TOCTOU race in coordonneesAleatoires() is already fixed by the
-- FOR UPDATE lock on statistiques (added in a prior audit pass). The function
-- reads existing coords inside the transaction with WHERE x >= 0 AND y >= 0,
-- excluding the unplaced sentinel (-1000,-1000). Adding a UNIQUE INDEX on
-- membre(x,y) is NOT safe because remiseAZero() sets ALL players to the same
-- sentinel coordinate, which would violate a global unique constraint.
-- This migration is intentionally a no-op: the fix lives in the application code.
SELECT 1 AS migration_applied;
