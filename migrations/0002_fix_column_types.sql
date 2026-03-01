-- Fix nonsensical column types, add proper defaults and constraints
-- The display width parameter on BIGINT/INT (e.g., BIGINT(100)) has no effect
-- on storage or range -- it only affects zero-padding with ZEROFILL.
-- These are cleaned up for clarity.

-- ============================================================
-- actionsformation: BIGINT(100) -> BIGINT
-- ============================================================
ALTER TABLE `actionsformation`
    MODIFY `nombreDebut` BIGINT NOT NULL,
    MODIFY `nombreRestant` BIGINT NOT NULL;

-- ============================================================
-- actionsconstruction: debut/fin stored as varchar but are timestamps
-- ============================================================
ALTER TABLE `actionsconstruction`
    MODIFY `debut` BIGINT NOT NULL,
    MODIFY `fin` BIGINT NOT NULL;

-- ============================================================
-- actionsattaques: varchar(500) for usernames is excessive
-- ============================================================
ALTER TABLE `actionsattaques`
    MODIFY `attaquant` VARCHAR(255) NOT NULL,
    MODIFY `defenseur` VARCHAR(255) NOT NULL;

-- ============================================================
-- actionsenvoi: varchar(500) for usernames is excessive
-- ============================================================
ALTER TABLE `actionsenvoi`
    MODIFY `envoyeur` VARCHAR(255) NOT NULL,
    MODIFY `receveur` VARCHAR(255) NOT NULL;

-- ============================================================
-- alliances: BIGINT(100) -> BIGINT
-- ============================================================
ALTER TABLE `alliances`
    MODIFY `energieAlliance` BIGINT NOT NULL DEFAULT 0,
    MODIFY `energieTotaleRecue` BIGINT NOT NULL DEFAULT 0;

-- ============================================================
-- autre: BIGINT(100) -> BIGINT
-- ============================================================
ALTER TABLE `autre`
    MODIFY `energieDepensee` BIGINT NOT NULL DEFAULT 0,
    MODIFY `energieDonnee` BIGINT NOT NULL DEFAULT 0,
    MODIFY `ressourcesPillees` BIGINT NOT NULL DEFAULT 0;

-- ============================================================
-- constructions: BIGINT(100) -> BIGINT
-- ============================================================
ALTER TABLE `constructions`
    MODIFY `vieGenerateur` BIGINT NOT NULL DEFAULT 0,
    MODIFY `vieChampdeforce` BIGINT NOT NULL DEFAULT 0,
    MODIFY `vieProducteur` BIGINT NOT NULL DEFAULT 0,
    MODIFY `vieDepot` BIGINT NOT NULL DEFAULT 30;

-- ============================================================
-- declarations: BIGINT(100) -> BIGINT
-- ============================================================
ALTER TABLE `declarations`
    MODIFY `pertes1` BIGINT NOT NULL DEFAULT 0,
    MODIFY `pertes2` BIGINT NOT NULL DEFAULT 0,
    MODIFY `pertesTotales` BIGINT NOT NULL DEFAULT 0;

-- ============================================================
-- moderation: BIGINT(50)/BIGINT(5) -> BIGINT (fix inconsistent widths)
-- ============================================================
ALTER TABLE `moderation`
    MODIFY `energie` BIGINT NOT NULL DEFAULT 0,
    MODIFY `carbone` BIGINT NOT NULL DEFAULT 0,
    MODIFY `azote` BIGINT NOT NULL DEFAULT 0,
    MODIFY `hydrogene` BIGINT NOT NULL DEFAULT 0,
    MODIFY `oxygene` BIGINT NOT NULL DEFAULT 0,
    MODIFY `chlore` BIGINT NOT NULL DEFAULT 0,
    MODIFY `soufre` BIGINT NOT NULL DEFAULT 0,
    MODIFY `brome` BIGINT NOT NULL DEFAULT 0,
    MODIFY `iode` BIGINT NOT NULL DEFAULT 0;

-- ============================================================
-- ressources: BIGINT(100) -> BIGINT
-- ============================================================
ALTER TABLE `ressources`
    MODIFY `revenuenergie` BIGINT NOT NULL DEFAULT 12,
    MODIFY `revenuhydrogene` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenucarbone` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenuoxygene` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenuiode` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenubrome` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenuchlore` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenusoufre` BIGINT NOT NULL DEFAULT 9,
    MODIFY `revenuazote` BIGINT NOT NULL DEFAULT 9;

-- ============================================================
-- parties: INT(100) -> INT
-- ============================================================
ALTER TABLE `parties`
    MODIFY `debut` INT NOT NULL;

-- ============================================================
-- membre: login and pass_md5 should be VARCHAR not TEXT
-- (TEXT cannot be indexed efficiently, and these are short strings)
-- Also fix ip column: varchar(11) is too short for IPv4 (15 chars)
-- ============================================================
ALTER TABLE `membre`
    MODIFY `login` VARCHAR(255) NOT NULL,
    MODIFY `pass_md5` VARCHAR(255) NOT NULL,
    MODIFY `ip` VARCHAR(45) NOT NULL,
    MODIFY `vacance` TINYINT(1) NOT NULL DEFAULT 0,
    MODIFY `moderateur` INT(11) NOT NULL DEFAULT 0;

-- Add index on membre.login now that it is VARCHAR
ALTER TABLE `membre` ADD INDEX `idx_membre_login` (`login`);
ALTER TABLE `membre` ADD INDEX `idx_membre_derniereConnexion` (`derniereConnexion`);
