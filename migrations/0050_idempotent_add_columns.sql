-- Migration 0039: Idempotent re-run of ADD COLUMN statements from 0005-0012
-- Uses ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+) so this migration is safe
-- to run multiple times without error.
--
-- Source migrations:
--   0005_add_vault_building.sql      → constructions.coffrefort
--   0006_add_formation_column.sql    → constructions.formation
--   0008_add_isotope_column.sql      → molecules.isotope
--   0009_add_catalyst_columns.sql    → statistiques.catalyst, statistiques.catalyst_week
--   0010_add_alliance_research.sql   → alliances.catalyseur/fortification/reseau/radar/bouclier
--   0011_add_specializations.sql     → constructions.spec_combat/spec_economy/spec_research
--   0012_add_session_token.sql       → membre.session_token
--
-- Note: 0007_add_prestige_table.sql uses CREATE TABLE IF NOT EXISTS — already idempotent.

-- From 0005: Vault (Coffre-fort) building
ALTER TABLE constructions ADD COLUMN IF NOT EXISTS coffrefort INT DEFAULT 0;

-- From 0006: Defensive formation
-- 0 = Dispersée (default), 1 = Phalange, 2 = Embuscade
ALTER TABLE constructions ADD COLUMN IF NOT EXISTS formation TINYINT DEFAULT 0;

-- From 0008: Isotope variant
-- 0 = Normal (default), 1 = Stable, 2 = Réactif, 3 = Catalytique
ALTER TABLE molecules ADD COLUMN IF NOT EXISTS isotope TINYINT DEFAULT 0;

-- From 0009: Weekly catalyst system
ALTER TABLE statistiques ADD COLUMN IF NOT EXISTS catalyst TINYINT DEFAULT 0;
ALTER TABLE statistiques ADD COLUMN IF NOT EXISTS catalyst_week INT DEFAULT 0;

-- From 0010: Alliance research tree
ALTER TABLE alliances ADD COLUMN IF NOT EXISTS catalyseur INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN IF NOT EXISTS fortification INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN IF NOT EXISTS reseau INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN IF NOT EXISTS radar INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN IF NOT EXISTS bouclier INT DEFAULT 0;

-- From 0011: Player specializations
-- 0 = not chosen, 1 = option A, 2 = option B
ALTER TABLE constructions ADD COLUMN IF NOT EXISTS spec_combat TINYINT DEFAULT 0;
ALTER TABLE constructions ADD COLUMN IF NOT EXISTS spec_economy TINYINT DEFAULT 0;
ALTER TABLE constructions ADD COLUMN IF NOT EXISTS spec_research TINYINT DEFAULT 0;

-- From 0012: Session token for auth hardening
ALTER TABLE membre ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) DEFAULT NULL;
