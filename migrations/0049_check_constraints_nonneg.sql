-- Migration 0049: CHECK constraints for non-negative columns
--
-- Adds database-level guards preventing invalid negative values from being stored.
-- MariaDB 10.11 enforces CHECK constraints (unlike MySQL < 8.0.16 which silently ignored them).
-- ADD CONSTRAINT IF NOT EXISTS is supported in MariaDB 10.4.3+.
--
-- constructions.vieIonisateur: HP of the ionisateur building; must be >= 0 (not negative HP).
-- autre.tradeVolume: cumulative trade volume used in ranking; must be >= 0.

ALTER TABLE constructions
    ADD CONSTRAINT IF NOT EXISTS chk_vie_ionisateur_nonneg CHECK (vieIonisateur >= 0);

ALTER TABLE autre
    ADD CONSTRAINT IF NOT EXISTS chk_tradeVolume_nonneg CHECK (tradeVolume >= 0);
