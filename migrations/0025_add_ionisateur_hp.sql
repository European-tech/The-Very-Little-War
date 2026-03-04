-- Migration 0025: Add ionisateur HP column for building damage
-- Task 7.5: Ionisateur should be damageable like other buildings

ALTER TABLE constructions
    ADD COLUMN vieIonisateur BIGINT NOT NULL DEFAULT 0 AFTER vieDepot;

-- Set initial HP for all existing players based on their ionisateur level
-- Formula: round(50 * pow(level, 2.5)) — same as pointsDeVie()
UPDATE constructions SET vieIonisateur = ROUND(50 * POW(GREATEST(1, ionisateur), 2.5));
