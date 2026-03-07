-- Migration 0041: Orphan cleanup and FK re-application (HIGH-029)
-- Note: ADD CONSTRAINT IF NOT EXISTS for FK not supported on MariaDB 10.11;
-- using DROP FOREIGN KEY IF EXISTS + ADD CONSTRAINT pattern instead.

-- ============================================================
-- Step 1: Delete orphan rows
-- ============================================================

DELETE FROM autre WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM ressources WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM constructions WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM molecules WHERE proprietaire NOT IN (SELECT login FROM membre);
DELETE FROM prestige WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM attack_cooldowns WHERE attacker NOT IN (SELECT login FROM membre);
UPDATE sujets SET auteur = NULL WHERE auteur IS NOT NULL AND auteur NOT IN (SELECT login FROM membre);
UPDATE reponses SET auteur = NULL WHERE auteur IS NOT NULL AND auteur NOT IN (SELECT login FROM membre);

-- ============================================================
-- Step 2: Re-add FK constraints (drop first for idempotency)
-- ============================================================

ALTER TABLE autre DROP FOREIGN KEY IF EXISTS fk_autre_login;
ALTER TABLE autre ADD CONSTRAINT fk_autre_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE ressources DROP FOREIGN KEY IF EXISTS fk_ressources_login;
ALTER TABLE ressources ADD CONSTRAINT fk_ressources_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE constructions DROP FOREIGN KEY IF EXISTS fk_constructions_login;
ALTER TABLE constructions ADD CONSTRAINT fk_constructions_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE molecules DROP FOREIGN KEY IF EXISTS fk_molecules_proprietaire;
ALTER TABLE molecules ADD CONSTRAINT fk_molecules_proprietaire FOREIGN KEY (proprietaire) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE prestige DROP FOREIGN KEY IF EXISTS fk_prestige_login;
ALTER TABLE prestige ADD CONSTRAINT fk_prestige_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE attack_cooldowns DROP FOREIGN KEY IF EXISTS fk_cooldowns_attacker;
ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cooldowns_attacker FOREIGN KEY (attacker) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE sujets DROP FOREIGN KEY IF EXISTS fk_sujets_auteur;
ALTER TABLE sujets ADD CONSTRAINT fk_sujets_auteur FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reponses DROP FOREIGN KEY IF EXISTS fk_reponses_auteur;
ALTER TABLE reponses ADD CONSTRAINT fk_reponses_auteur FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;
