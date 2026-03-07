-- Migration 0035: Add missing primary keys and foreign keys
-- Addresses PASS1-HIGH-006 through PASS1-HIGH-009
--
-- PASS1-HIGH-006: reponses_sondage — missing FK constraints
--   Column is 'login' (→ membre.login) and 'sondage' (→ sondages.id).
--   The table already has a UNIQUE KEY on (login, sondage) and PK on id.
--   Adds referential integrity so orphaned poll responses are cleaned up
--   when a player or poll is deleted.
--
-- PASS1-HIGH-007: attack_cooldowns.defender — missing FK
--   Migration 0018 added fk_cooldowns_attacker but omitted the defender
--   column. Adding the symmetric constraint here.
--
-- PASS1-HIGH-008: grades — missing primary key
--   Schema: (login VARCHAR(255), grade VARCHAR(255), idalliance INT, nom VARCHAR(255))
--   A player holds at most one grade per alliance, so the natural compound PK
--   is (login, idalliance). The code always queries/inserts/deletes on this
--   pair (see allianceadmin.php lines 87, 104, 120, 123; alliance.php line 130).
--   Using (login) alone would be wrong because a player's grade history per
--   alliance is tracked, and using (login, grade, idalliance) would allow
--   duplicate entries for the same player+alliance with different grades.
--
-- PASS1-HIGH-009: statutforum — missing primary key
--   Schema: (login VARCHAR(255), idsujet INT, idforum INT)
--   Tracks per-player per-topic read status. Natural compound PK is
--   (login, idsujet). The code guards INSERTs with existence checks and
--   queries on (login, idsujet) pairs (sujet.php, listesujets.php).
--   idforum is denormalised for fast forum-level count queries; it is NOT
--   part of the PK because the uniqueness constraint is login+topic.

-- ============================================================
-- Orphan cleanup — run manually if constraints fail due to orphans:
-- DELETE FROM reponses_sondage WHERE login NOT IN (SELECT login FROM membre);
-- DELETE FROM reponses_sondage WHERE sondage NOT IN (SELECT id FROM sondages);
-- DELETE FROM attack_cooldowns WHERE defender NOT IN (SELECT login FROM membre);
-- DELETE FROM grades WHERE login NOT IN (SELECT login FROM membre);
-- DELETE FROM statutforum WHERE login NOT IN (SELECT login FROM membre);
-- ============================================================

-- -----------------------------------------------------------------
-- PASS1-HIGH-008: grades — add compound primary key (login, idalliance)
-- -----------------------------------------------------------------
-- Guard: only add if no PK exists yet (idempotent)
SET @grades_has_pk = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'grades'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);
SET @grades_sql = IF(
    @grades_has_pk = 0,
    'ALTER TABLE grades ADD PRIMARY KEY (login, idalliance)',
    'SELECT ''grades PK already exists, skipping'' AS info'
);
PREPARE grades_stmt FROM @grades_sql;
EXECUTE grades_stmt;
DEALLOCATE PREPARE grades_stmt;

-- -----------------------------------------------------------------
-- PASS1-HIGH-009: statutforum — add compound primary key (login, idsujet)
-- -----------------------------------------------------------------
-- Guard: only add if no PK exists yet (idempotent)
-- Note: statutforum was MyISAM (migration 0013 converted it to InnoDB).
SET @sf_has_pk = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'statutforum'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);
SET @sf_sql = IF(
    @sf_has_pk = 0,
    'ALTER TABLE statutforum ADD PRIMARY KEY (login, idsujet)',
    'SELECT ''statutforum PK already exists, skipping'' AS info'
);
PREPARE sf_stmt FROM @sf_sql;
EXECUTE sf_stmt;
DEALLOCATE PREPARE sf_stmt;

-- -----------------------------------------------------------------
-- PASS1-HIGH-007: attack_cooldowns.defender — missing FK
-- Symmetric with fk_cooldowns_attacker added in migration 0018.
-- ON DELETE CASCADE: when a player is deleted, cooldowns involving
-- them as defender are removed (no dangling references).
-- -----------------------------------------------------------------
SET @defender_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA        = DATABASE()
      AND TABLE_NAME          = 'attack_cooldowns'
      AND CONSTRAINT_NAME     = 'fk_cooldowns_defender'
      AND REFERENCED_TABLE_NAME IS NOT NULL
);
SET @defender_sql = IF(
    @defender_fk_exists = 0,
    'ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cooldowns_defender FOREIGN KEY (defender) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT ''fk_cooldowns_defender already exists, skipping'' AS info'
);
PREPARE defender_stmt FROM @defender_sql;
EXECUTE defender_stmt;
DEALLOCATE PREPARE defender_stmt;

-- -----------------------------------------------------------------
-- PASS1-HIGH-006: reponses_sondage — add FKs to membre and sondages
-- Column names: login → membre.login, sondage → sondages.id
-- ON DELETE CASCADE: removing a player or a poll cleans up their votes.
-- -----------------------------------------------------------------

-- FK: login → membre.login
SET @rs_login_fk = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA        = DATABASE()
      AND TABLE_NAME          = 'reponses_sondage'
      AND CONSTRAINT_NAME     = 'fk_reponses_login'
      AND REFERENCED_TABLE_NAME IS NOT NULL
);
SET @rs_login_sql = IF(
    @rs_login_fk = 0,
    'ALTER TABLE reponses_sondage ADD CONSTRAINT fk_reponses_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT ''fk_reponses_login already exists, skipping'' AS info'
);
PREPARE rs_login_stmt FROM @rs_login_sql;
EXECUTE rs_login_stmt;
DEALLOCATE PREPARE rs_login_stmt;

-- FK: sondage → sondages.id
SET @rs_sondage_fk = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA        = DATABASE()
      AND TABLE_NAME          = 'reponses_sondage'
      AND CONSTRAINT_NAME     = 'fk_reponses_sondage'
      AND REFERENCED_TABLE_NAME IS NOT NULL
);
SET @rs_sondage_sql = IF(
    @rs_sondage_fk = 0,
    'ALTER TABLE reponses_sondage ADD CONSTRAINT fk_reponses_sondage FOREIGN KEY (sondage) REFERENCES sondages(id) ON DELETE CASCADE',
    'SELECT ''fk_reponses_sondage already exists, skipping'' AS info'
);
PREPARE rs_sondage_stmt FROM @rs_sondage_sql;
EXECUTE rs_sondage_stmt;
DEALLOCATE PREPARE rs_sondage_stmt;
