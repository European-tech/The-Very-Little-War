-- MED-067: Add type column to rapports for reliable report classification.
-- Replaces fragile LIKE-based title matching for espionage mission detection in tutoriel.php.
-- Default 'attack' covers all existing rows (combat/send reports) without data loss.
ALTER TABLE rapports
    ADD COLUMN IF NOT EXISTS `type` ENUM('attack','espionage','defense') NOT NULL DEFAULT 'attack';

-- Backfill espionage reports: successful espionage has title starting with "Vous espionnez"
-- or failed with "Espionnage raté". Defense notification has title 'Tentative d\'espionnage détectée'.
UPDATE rapports SET `type`='espionage'
WHERE titre LIKE 'Vous espionnez%' OR titre = 'Espionnage raté';

UPDATE rapports SET `type`='defense'
WHERE titre = 'Tentative d\'espionnage détectée';
