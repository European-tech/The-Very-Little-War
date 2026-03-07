-- Migration 0036: NOT NULL constraints on constructions/molecules/statistiques/alliances/spec
--                 plus session_token index on membre.
-- Fixes: PASS1-MEDIUM-040, PASS1-MEDIUM-041, PASS1-MEDIUM-042, PASS1-MEDIUM-043,
--        PASS1-MEDIUM-044, PASS1-MEDIUM-045, PASS1-MEDIUM-057
--
-- All ALTER TABLE … MODIFY statements are wrapped in INFORMATION_SCHEMA guards so that
-- re-running this migration (e.g. after a restore) is safe and idempotent.
-- Each PROCEDURE is created with a unique name, executed, then dropped immediately.

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-040: constructions.coffrefort / constructions.formation
-- Added by 0005 and 0006 without NOT NULL.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _m0036_040_coffrefort;
DELIMITER $$
CREATE PROCEDURE _m0036_040_coffrefort()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'constructions'
          AND COLUMN_NAME  = 'coffrefort'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        ALTER TABLE constructions MODIFY coffrefort INT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_040_coffrefort();
DROP PROCEDURE IF EXISTS _m0036_040_coffrefort;

DROP PROCEDURE IF EXISTS _m0036_040_formation;
DELIMITER $$
CREATE PROCEDURE _m0036_040_formation()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'constructions'
          AND COLUMN_NAME  = 'formation'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        ALTER TABLE constructions MODIFY formation TINYINT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_040_formation();
DROP PROCEDURE IF EXISTS _m0036_040_formation;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-041: molecules.isotope
-- Added by 0008 without NOT NULL.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _m0036_041_isotope;
DELIMITER $$
CREATE PROCEDURE _m0036_041_isotope()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'molecules'
          AND COLUMN_NAME  = 'isotope'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        -- Ensure no existing NULLs before tightening constraint
        UPDATE molecules SET isotope = 0 WHERE isotope IS NULL;
        ALTER TABLE molecules MODIFY isotope TINYINT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_041_isotope();
DROP PROCEDURE IF EXISTS _m0036_041_isotope;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-042: statistiques.catalyst / statistiques.catalyst_week
-- Added by 0009 as TINYINT / INT without NOT NULL.
-- Note: 0009 used TINYINT for catalyst; we widen to INT here to match
--       catalyst_week (both store cumulative counts that can exceed 127).
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _m0036_042_catalyst;
DELIMITER $$
CREATE PROCEDURE _m0036_042_catalyst()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'statistiques'
          AND COLUMN_NAME  = 'catalyst'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        UPDATE statistiques SET catalyst = 0 WHERE catalyst IS NULL;
        ALTER TABLE statistiques MODIFY catalyst INT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_042_catalyst();
DROP PROCEDURE IF EXISTS _m0036_042_catalyst;

DROP PROCEDURE IF EXISTS _m0036_042_catalyst_week;
DELIMITER $$
CREATE PROCEDURE _m0036_042_catalyst_week()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'statistiques'
          AND COLUMN_NAME  = 'catalyst_week'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        UPDATE statistiques SET catalyst_week = 0 WHERE catalyst_week IS NULL;
        ALTER TABLE statistiques MODIFY catalyst_week INT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_042_catalyst_week();
DROP PROCEDURE IF EXISTS _m0036_042_catalyst_week;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-043: alliances research columns (added by 0010 without NOT NULL)
-- Columns: catalyseur, fortification, reseau, radar, bouclier
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _m0036_043_research;
DELIMITER $$
CREATE PROCEDURE _m0036_043_research()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'alliances'
          AND COLUMN_NAME  = 'catalyseur'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        UPDATE alliances SET catalyseur    = 0 WHERE catalyseur    IS NULL;
        UPDATE alliances SET fortification = 0 WHERE fortification IS NULL;
        UPDATE alliances SET reseau        = 0 WHERE reseau        IS NULL;
        UPDATE alliances SET radar         = 0 WHERE radar         IS NULL;
        UPDATE alliances SET bouclier      = 0 WHERE bouclier      IS NULL;
        ALTER TABLE alliances
            MODIFY catalyseur    INT NOT NULL DEFAULT 0,
            MODIFY fortification INT NOT NULL DEFAULT 0,
            MODIFY reseau        INT NOT NULL DEFAULT 0,
            MODIFY radar         INT NOT NULL DEFAULT 0,
            MODIFY bouclier      INT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_043_research();
DROP PROCEDURE IF EXISTS _m0036_043_research;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-044: constructions spec columns (added by 0011 without NOT NULL)
-- Columns: spec_combat, spec_economy, spec_research  (all TINYINT)
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _m0036_044_spec;
DELIMITER $$
CREATE PROCEDURE _m0036_044_spec()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'constructions'
          AND COLUMN_NAME  = 'spec_combat'
          AND IS_NULLABLE  = 'YES'
    ) THEN
        UPDATE constructions SET spec_combat    = 0 WHERE spec_combat    IS NULL;
        UPDATE constructions SET spec_economy   = 0 WHERE spec_economy   IS NULL;
        UPDATE constructions SET spec_research  = 0 WHERE spec_research  IS NULL;
        ALTER TABLE constructions
            MODIFY spec_combat   TINYINT NOT NULL DEFAULT 0,
            MODIFY spec_economy  TINYINT NOT NULL DEFAULT 0,
            MODIFY spec_research TINYINT NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;
CALL _m0036_044_spec();
DROP PROCEDURE IF EXISTS _m0036_044_spec;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-045: membre.session_token index
-- Added by 0012; no index was created at that time.
-- PREFIX 16 chars is enough for equality lookups (token is a hex/random string).
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _m0036_045_session_token_idx;
DELIMITER $$
CREATE PROCEDURE _m0036_045_session_token_idx()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'membre'
          AND INDEX_NAME   = 'idx_session_token'
    ) THEN
        ALTER TABLE membre ADD INDEX idx_session_token (session_token(16));
    END IF;
END$$
DELIMITER ;
CALL _m0036_045_session_token_idx();
DROP PROCEDURE IF EXISTS _m0036_045_session_token_idx;

-- ---------------------------------------------------------------------------
-- PASS1-MEDIUM-057: alliance_left_at
-- Migration 0034 already added this column to the autre table with an index.
-- No action needed here; recorded for audit trail completeness.
-- ---------------------------------------------------------------------------
-- NOTE: alliance_left_at INT UNSIGNED NULL DEFAULT NULL was added to the
--       `autre` table (not `membre`) by migration 0034_add_alliance_left_at.sql.
--       Index idx_autre_alliance_left_at (login, alliance_left_at) was also
--       created in 0034. Nothing to do in 0036.
