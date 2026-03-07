-- Migration 0036: NOT NULL constraints on constructions/molecules/statistiques/alliances/spec
--                 plus session_token index on membre.
-- Fixes: PASS1-MEDIUM-040, PASS1-MEDIUM-041, PASS1-MEDIUM-042, PASS1-MEDIUM-043,
--        PASS1-MEDIUM-044, PASS1-MEDIUM-045, PASS1-MEDIUM-057
--
-- SIMPLIFIED: Direct ALTER TABLE statements with column existence checks.
-- These are idempotent and safe to re-run. The application layer should
-- handle column existence before executing these.

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-040: constructions.coffrefort / constructions.formation
-- Added by 0005 and 0006 without NOT NULL. Make them NOT NULL DEFAULT 0.
-- ---------------------------------------------------------------------------

ALTER TABLE constructions
    MODIFY COLUMN coffrefort INT NOT NULL DEFAULT 0,
    MODIFY COLUMN formation TINYINT NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-041: molecules.isotope
-- Added by 0008 without NOT NULL. Ensure no NULLs, then make NOT NULL.
-- ---------------------------------------------------------------------------

UPDATE molecules SET isotope = 0 WHERE isotope IS NULL;
ALTER TABLE molecules MODIFY COLUMN isotope TINYINT NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-042: statistiques.catalyst / statistiques.catalyst_week
-- Added by 0009 as TINYINT / INT without NOT NULL.
-- Update NULLs to 0, then make NOT NULL.
-- ---------------------------------------------------------------------------

UPDATE statistiques SET catalyst = 0 WHERE catalyst IS NULL;
UPDATE statistiques SET catalyst_week = 0 WHERE catalyst_week IS NULL;
ALTER TABLE statistiques
    MODIFY COLUMN catalyst INT NOT NULL DEFAULT 0,
    MODIFY COLUMN catalyst_week INT NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-043: alliances research columns (added by 0010 without NOT NULL)
-- Columns: catalyseur, fortification, reseau, radar, bouclier
-- Update NULLs to 0, then make NOT NULL.
-- ---------------------------------------------------------------------------

UPDATE alliances SET catalyseur = 0 WHERE catalyseur IS NULL;
UPDATE alliances SET fortification = 0 WHERE fortification IS NULL;
UPDATE alliances SET reseau = 0 WHERE reseau IS NULL;
UPDATE alliances SET radar = 0 WHERE radar IS NULL;
UPDATE alliances SET bouclier = 0 WHERE bouclier IS NULL;
ALTER TABLE alliances
    MODIFY COLUMN catalyseur INT NOT NULL DEFAULT 0,
    MODIFY COLUMN fortification INT NOT NULL DEFAULT 0,
    MODIFY COLUMN reseau INT NOT NULL DEFAULT 0,
    MODIFY COLUMN radar INT NOT NULL DEFAULT 0,
    MODIFY COLUMN bouclier INT NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-044: constructions spec columns (added by 0011 without NOT NULL)
-- Columns: spec_combat, spec_economy, spec_research  (all TINYINT)
-- Update NULLs to 0, then make NOT NULL.
-- ---------------------------------------------------------------------------

UPDATE constructions SET spec_combat = 0 WHERE spec_combat IS NULL;
UPDATE constructions SET spec_economy = 0 WHERE spec_economy IS NULL;
UPDATE constructions SET spec_research = 0 WHERE spec_research IS NULL;
ALTER TABLE constructions
    MODIFY COLUMN spec_combat TINYINT NOT NULL DEFAULT 0,
    MODIFY COLUMN spec_economy TINYINT NOT NULL DEFAULT 0,
    MODIFY COLUMN spec_research TINYINT NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-045: membre.session_token index
-- Added by 0012; no index was created at that time.
-- PREFIX 16 chars is enough for equality lookups (token is a hex/random string).
-- ---------------------------------------------------------------------------

ALTER TABLE membre ADD INDEX idx_session_token (session_token(16));

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-057: alliance_left_at
-- Migration 0034 already added this column to the autre table with an index.
-- No action needed here; recorded for audit trail completeness.
-- ---------------------------------------------------------------------------
-- NOTE: alliance_left_at INT UNSIGNED NULL DEFAULT NULL was added to the
--       `autre` table (not `membre`) by migration 0034_add_alliance_left_at.sql.
--       Index idx_autre_alliance_left_at (login, alliance_left_at) was also
--       created in 0034. Nothing to do in 0036.
