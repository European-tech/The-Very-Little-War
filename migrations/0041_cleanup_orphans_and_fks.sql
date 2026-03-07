-- Migration 0041: Orphan cleanup and idempotent FK re-application (HIGH-029)
--
-- Context: migration 0018_add_foreign_keys.sql added FK constraints but left the
-- orphan cleanup queries commented out. If 0018 was applied on a DB that already
-- had orphan rows the FK ALTERs would fail. This migration:
--   1. Deletes orphan rows (rows referencing logins that no longer exist in membre)
--   2. Re-adds each FK using ADD CONSTRAINT IF NOT EXISTS so it is safe to run
--      even if 0018 already succeeded.

-- ============================================================
-- Step 1: Delete orphan rows
-- ============================================================

-- autre: player detail rows with no matching membre account
DELETE FROM autre WHERE login NOT IN (SELECT login FROM membre);

-- ressources: resource rows for deleted players
DELETE FROM ressources WHERE login NOT IN (SELECT login FROM membre);

-- constructions: building rows for deleted players
DELETE FROM constructions WHERE login NOT IN (SELECT login FROM membre);

-- molecules: molecules owned by deleted players
DELETE FROM molecules WHERE proprietaire NOT IN (SELECT login FROM membre);

-- prestige: prestige entries for deleted players
DELETE FROM prestige WHERE login NOT IN (SELECT login FROM membre);

-- attack_cooldowns: cooldown rows referencing deleted attacker accounts
DELETE FROM attack_cooldowns WHERE attacker NOT IN (SELECT login FROM membre);

-- sujets: forum topics authored by deleted players → set to NULL (FK uses SET NULL)
UPDATE sujets SET auteur = NULL WHERE auteur IS NOT NULL AND auteur NOT IN (SELECT login FROM membre);

-- reponses: forum replies authored by deleted players → set to NULL
UPDATE reponses SET auteur = NULL WHERE auteur IS NOT NULL AND auteur NOT IN (SELECT login FROM membre);

-- ============================================================
-- Step 2: Re-add FK constraints with IF NOT EXISTS guard
-- ============================================================

-- Core player tables → membre.login (CASCADE on delete/update)
ALTER TABLE autre ADD CONSTRAINT IF NOT EXISTS fk_autre_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE ressources ADD CONSTRAINT IF NOT EXISTS fk_ressources_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE constructions ADD CONSTRAINT IF NOT EXISTS fk_constructions_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Molecules → membre.login
ALTER TABLE molecules ADD CONSTRAINT IF NOT EXISTS fk_molecules_proprietaire
    FOREIGN KEY (proprietaire) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Prestige → membre.login
ALTER TABLE prestige ADD CONSTRAINT IF NOT EXISTS fk_prestige_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Attack cooldowns → membre.login
ALTER TABLE attack_cooldowns ADD CONSTRAINT IF NOT EXISTS fk_cooldowns_attacker
    FOREIGN KEY (attacker) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;

-- Forum topics → membre.login (SET NULL: keep posts when player deleted)
ALTER TABLE sujets ADD CONSTRAINT IF NOT EXISTS fk_sujets_auteur
    FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;

-- Forum replies → membre.login (SET NULL)
ALTER TABLE reponses ADD CONSTRAINT IF NOT EXISTS fk_reponses_auteur
    FOREIGN KEY (auteur) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;
