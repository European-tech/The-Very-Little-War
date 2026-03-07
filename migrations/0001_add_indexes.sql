-- Add indexes for frequently queried columns
-- These dramatically improve query performance
-- Note: For idempotency on re-run, use "ADD INDEX IF NOT EXISTS" syntax (supported MariaDB 10.1.4+)

-- Primary lookup columns
ALTER TABLE `autre` ADD INDEX `idx_autre_login` (`login`);
ALTER TABLE `autre` ADD INDEX `idx_autre_idalliance` (`idalliance`);
ALTER TABLE `ressources` ADD INDEX `idx_ressources_login` (`login`);
ALTER TABLE `constructions` ADD INDEX `idx_constructions_login` (`login`);

-- Molecule lookups
ALTER TABLE `molecules` ADD INDEX `idx_molecules_proprietaire` (`proprietaire`);
ALTER TABLE `molecules` ADD INDEX `idx_molecules_proprietaire_classe` (`proprietaire`, `numeroclasse`);

-- Action queues (checked on every page load)
ALTER TABLE `actionsattaques` ADD INDEX `idx_attaques_attaquant` (`attaquant`);
ALTER TABLE `actionsattaques` ADD INDEX `idx_attaques_defenseur` (`defenseur`);
ALTER TABLE `actionsattaques` ADD INDEX `idx_attaques_temps` (`tempsAttaque`);
ALTER TABLE `actionsformation` ADD INDEX `idx_formation_login` (`login`);
ALTER TABLE `actionsformation` ADD INDEX `idx_formation_fin` (`fin`);
ALTER TABLE `actionsconstruction` ADD INDEX `idx_construction_login` (`login`);
ALTER TABLE `actionsconstruction` ADD INDEX `idx_construction_fin` (`fin`);
ALTER TABLE `actionsenvoi` ADD INDEX `idx_envoi_receveur` (`receveur`);
ALTER TABLE `actionsenvoi` ADD INDEX `idx_envoi_temps` (`tempsArrivee`);

-- Messages and reports
ALTER TABLE `messages` ADD INDEX `idx_messages_destinataire` (`destinataire`);
ALTER TABLE `messages` ADD INDEX `idx_messages_expeditaire` (`expeditaire`);
ALTER TABLE `rapports` ADD INDEX `idx_rapports_destinataire` (`destinataire`);

-- Online tracking (connectes uses ip, not login)
ALTER TABLE `connectes` ADD INDEX `idx_connectes_ip` (`ip`);

-- Alliance lookups
ALTER TABLE `grades` ADD INDEX `idx_grades_login` (`login`);
ALTER TABLE `grades` ADD INDEX `idx_grades_alliance` (`idalliance`);
ALTER TABLE `declarations` ADD INDEX `idx_declarations_alliance1` (`alliance1`);
ALTER TABLE `declarations` ADD INDEX `idx_declarations_alliance2` (`alliance2`);

-- Forum
ALTER TABLE `sujets` ADD INDEX `idx_sujets_forum` (`idforum`);
ALTER TABLE `reponses` ADD INDEX `idx_reponses_sujet` (`idsujet`);
