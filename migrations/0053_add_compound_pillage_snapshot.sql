-- Migration 0053: Snapshot compound pillage bonus at attack launch time
-- Prevents retroactive activation of H2SO4 (pillage_boost) compound after
-- an attack is already in-flight. combat.php reads this column instead of
-- calling getCompoundBonus() live at resolution time.

ALTER TABLE `actionsattaques`
    ADD COLUMN IF NOT EXISTS `compound_pillage_bonus` DECIMAL(6,4) NOT NULL DEFAULT 0.0000
    COMMENT 'Compound pillage boost snapshotted at launch';
