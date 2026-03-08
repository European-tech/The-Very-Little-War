-- HIGH-024: Snapshot compound bonuses at attack launch time.
-- Without these columns, combat.php re-queries live compound state at resolution,
-- allowing a player to activate a compound AFTER launching an attack to retroactively
-- benefit from it (timing exploit).
--
-- compound_atk_bonus: attack_boost compound value active when the attack was launched
-- compound_spd_bonus: speed_boost compound value used to compute travel time
-- compound_def_bonus: defense_boost compound value active for the defender at launch time
--   (defender snapshot is best-effort; attacker snapshotting is the primary fix)

-- MIG-M-004: Use IF NOT EXISTS for idempotency on re-runs
ALTER TABLE `actionsattaques`
    ADD COLUMN IF NOT EXISTS `compound_atk_bonus` DECIMAL(5,4) NOT NULL DEFAULT 0.0000 AFTER `nombreneutrinos`,
    ADD COLUMN IF NOT EXISTS `compound_spd_bonus` DECIMAL(5,4) NOT NULL DEFAULT 0.0000 AFTER `compound_atk_bonus`,
    ADD COLUMN IF NOT EXISTS `compound_def_bonus` DECIMAL(5,4) NOT NULL DEFAULT 0.0000 AFTER `compound_spd_bonus`;
