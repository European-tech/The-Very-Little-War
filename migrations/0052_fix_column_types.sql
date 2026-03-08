-- Migration 0052: Fix column type issues
--
-- season_recap.molecules_perdues: BIGINT to accommodate seasons with many destroyed
--   molecules (INT max ~2.1B could overflow on active servers over time).
--   MODIFY COLUMN IF EXISTS is MariaDB 10.11 syntax; silently skips if column absent.
--
-- actionsattaques.compound_*_bonus: widen from DECIMAL(5,4) to DECIMAL(6,4).
--   DECIMAL(5,4) allows a maximum of 9.9999 (5 total digits, 4 after decimal).
--   With 5 total digits and 4 decimal places, the integer part is limited to 1 digit (max 9).
--   DECIMAL(6,4) allows up to 99.9999, accommodating compound stacking > 1.0x bonus.
--   This is a safe widening — existing data is preserved exactly.

ALTER TABLE season_recap
    MODIFY COLUMN IF EXISTS molecules_perdues BIGINT NOT NULL DEFAULT 0;

ALTER TABLE actionsattaques
    MODIFY COLUMN IF EXISTS compound_atk_bonus DECIMAL(6,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE actionsattaques
    MODIFY COLUMN IF EXISTS compound_def_bonus DECIMAL(6,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE actionsattaques
    MODIFY COLUMN IF EXISTS compound_spd_bonus DECIMAL(6,4) NOT NULL DEFAULT 0.0000;
