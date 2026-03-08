-- Migration 0018: Add foreign key constraints for referential integrity
--
-- Prerequisites:
-- 1. membre.login must be VARCHAR(255) with UNIQUE index (added here)
-- 2. attack_cooldowns.attacker/defender widened from VARCHAR(50) to VARCHAR(255)
-- 3. Orphan cleanup runs in Step 0 (before FK additions) to prevent constraint failures

-- Step 0: Orphan cleanup — must run BEFORE FK additions
-- Diagnostic SELECTs kept commented (they produce unwanted result sets in multi_query).
-- SELECT COUNT(*) FROM autre WHERE login NOT IN (SELECT login FROM membre);
-- SELECT COUNT(*) FROM ressources WHERE login NOT IN (SELECT login FROM membre);
-- SELECT COUNT(*) FROM constructions WHERE login NOT IN (SELECT login FROM membre);
-- SELECT COUNT(*) FROM molecules WHERE proprietaire NOT IN (SELECT login FROM membre);
-- SELECT COUNT(*) FROM prestige WHERE login NOT IN (SELECT login FROM membre);
-- SELECT COUNT(*) FROM attack_cooldowns WHERE attacker NOT IN (SELECT login FROM membre);
-- SELECT COUNT(*) FROM attack_cooldowns WHERE defender NOT IN (SELECT login FROM membre);

DELETE FROM autre WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM ressources WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM constructions WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM molecules WHERE proprietaire NOT IN (SELECT login FROM membre);
DELETE FROM prestige WHERE login NOT IN (SELECT login FROM membre);
DELETE FROM attack_cooldowns WHERE attacker NOT IN (SELECT login FROM membre);
DELETE FROM attack_cooldowns WHERE defender NOT IN (SELECT login FROM membre);

-- Step 1: Add UNIQUE index on membre.login (required for FK references)
-- Drop the non-unique index first if it exists
ALTER TABLE membre DROP INDEX IF EXISTS idx_membre_login;
ALTER TABLE membre ADD UNIQUE INDEX idx_membre_login (login);

-- Step 2: Fix attack_cooldowns column width to match membre.login
ALTER TABLE attack_cooldowns
    MODIFY attacker VARCHAR(255) NOT NULL,
    MODIFY defender VARCHAR(255) NOT NULL;

-- Step 3: Allow NULL on forum authors (SET NULL on delete)
ALTER TABLE sujets MODIFY auteur VARCHAR(255) CHARACTER SET utf8mb4 NULL;
ALTER TABLE reponses MODIFY auteur VARCHAR(255) CHARACTER SET utf8mb4 NULL;

-- Step 4: Add foreign key constraints

-- Core player tables → membre.login (CASCADE: delete player = delete their data)
ALTER TABLE autre ADD CONSTRAINT fk_autre_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE ressources ADD CONSTRAINT fk_ressources_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE constructions ADD CONSTRAINT fk_constructions_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Molecules → membre.login
ALTER TABLE molecules ADD CONSTRAINT fk_molecules_proprietaire
    FOREIGN KEY (proprietaire) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Prestige → membre.login
ALTER TABLE prestige ADD CONSTRAINT fk_prestige_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Attack cooldowns → membre.login
ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cooldowns_attacker
    FOREIGN KEY (attacker) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Attack cooldowns defender → membre.login (symmetric with attacker FK)
-- Note: migration 0035 added this via PREPARE/EXECUTE guard; 0041 does DROP+ADD for idempotency.
ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cooldowns_defender
    FOREIGN KEY (defender) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Forum → membre.login (SET NULL: keep posts when player deleted)
ALTER TABLE sujets ADD CONSTRAINT fk_sujets_auteur
    FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reponses ADD CONSTRAINT fk_reponses_auteur
    FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;
