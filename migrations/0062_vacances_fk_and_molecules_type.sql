-- Migration 0062: vacances FK + moleculesPerdues BIGINT

SET FOREIGN_KEY_CHECKS=0;

-- 1. Clean orphaned vacation rows
DELETE FROM vacances WHERE idJoueur NOT IN (SELECT id FROM membre);

-- 2. Add FK from vacances.idJoueur → membre.id
SET @existFk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vacances'
    AND CONSTRAINT_NAME='fk_vacances_joueur');
SET @addFk = IF(@existFk = 0,
    'ALTER TABLE vacances ADD CONSTRAINT fk_vacances_joueur FOREIGN KEY (idJoueur) REFERENCES membre(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt1 FROM @addFk; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

-- 3. Convert moleculesPerdues: round any fractional values, then change to BIGINT
UPDATE autre SET moleculesPerdues = ROUND(moleculesPerdues) WHERE moleculesPerdues != ROUND(moleculesPerdues);
ALTER TABLE autre MODIFY COLUMN moleculesPerdues BIGINT NOT NULL DEFAULT 0;

SET FOREIGN_KEY_CHECKS=1;
