-- TVLW Test Database Schema
-- Fully corrected to match production (all migrations through 0121 applied)
-- All tables use ENGINE=InnoDB DEFAULT CHARSET=latin1 unless noted.
-- No FOREIGN KEY constraints (tests keep fixtures simple).
-- All PRIMARY KEYs defined inline.

CREATE TABLE IF NOT EXISTS `actionsattaques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attaquant` varchar(500) NOT NULL,
  `defenseur` varchar(500) NOT NULL,
  `tempsAller` int(11) NOT NULL,
  `tempsAttaque` int(11) NOT NULL,
  `tempsRetour` int(11) NOT NULL,
  `troupes` text NOT NULL,
  `attaqueFaite` int(11) NOT NULL,
  `nombreneutrinos` bigint(20) NOT NULL DEFAULT 0,
  `compound_atk_bonus` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  `compound_spd_bonus` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  `compound_def_bonus` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  `compound_pillage_bonus` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `actionsconstruction` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `debut` int(11) NOT NULL DEFAULT 0,
  `fin` int(11) NOT NULL DEFAULT 0,
  `batiment` varchar(255) NOT NULL,
  `niveau` int(11) NOT NULL,
  `affichage` text NOT NULL,
  `points` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_actionsconstruction_login_fin` (`login`, `fin`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `actionsenvoi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `envoyeur` varchar(500) NOT NULL,
  `receveur` varchar(500) NOT NULL,
  `ressourcesEnvoyees` text NOT NULL,
  `ressourcesRecues` text NOT NULL,
  `tempsArrivee` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `actionsformation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idclasse` varchar(50) NOT NULL DEFAULT '0',
  `login` varchar(255) NOT NULL,
  `debut` bigint(20) NOT NULL,
  `fin` bigint(20) NOT NULL,
  `nombreDebut` bigint(100) NOT NULL,
  `nombreRestant` bigint(100) NOT NULL,
  `formule` varchar(1000) NOT NULL,
  `tempsPourUn` double NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `alliances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(155) NOT NULL,
  `tag` varchar(155) NOT NULL,
  `description` text NOT NULL,
  `pointstotaux` int(11) NOT NULL DEFAULT 0,
  `chef` varchar(255) NOT NULL,
  `energieAlliance` bigint(100) NOT NULL DEFAULT 0,
  `duplicateur` int(11) NOT NULL DEFAULT 0,
  `energieTotaleRecue` bigint(100) NOT NULL DEFAULT 0,
  `totalConstructions` int(11) NOT NULL DEFAULT 0,
  `totalAttaque` int(11) NOT NULL DEFAULT 0,
  `totalDefense` int(11) NOT NULL DEFAULT 0,
  `totalPillage` int(11) NOT NULL DEFAULT 0,
  `pointsVictoire` int(11) NOT NULL DEFAULT 0,
  `catalyseur` int(11) NOT NULL DEFAULT 0,
  `fortification` int(11) NOT NULL DEFAULT 0,
  `reseau` int(11) NOT NULL DEFAULT 0,
  `radar` int(11) NOT NULL DEFAULT 0,
  `bouclier` int(11) NOT NULL DEFAULT 0,
  `season_number` int(11) DEFAULT NULL,
  `vp_awarded` int(11) NOT NULL DEFAULT 0,
  `season_vp_awarded` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_season_vp_awarded` (`season_vp_awarded`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `autre` (
  `login` varchar(255) NOT NULL,
  `points` int(11) NOT NULL DEFAULT 0,
  `idalliance` int(11) NOT NULL DEFAULT 0,
  `description` text NOT NULL,
  `tempsPrecedent` int(11) NOT NULL DEFAULT 0,
  `niveaututo` int(11) NOT NULL DEFAULT 1,
  `nbattaques` int(11) NOT NULL DEFAULT 0,
  `moleculesPerdues` double NOT NULL DEFAULT 0,
  `energieDepensee` bigint(100) NOT NULL DEFAULT 0,
  `bombe` int(11) NOT NULL DEFAULT 0,
  `energieDonnee` bigint(100) NOT NULL DEFAULT 0,
  `victoires` int(11) NOT NULL DEFAULT 0,
  `image` varchar(255) NOT NULL DEFAULT 'defaut.png',
  `nbMessages` int(11) NOT NULL DEFAULT 0,
  `batmax` int(11) NOT NULL DEFAULT 1,
  `timeMolecule` varchar(1000) NOT NULL DEFAULT '0,0,0,0',
  `pointsAttaque` int(11) NOT NULL DEFAULT 0,
  `pointsDefense` int(11) NOT NULL DEFAULT 0,
  `ressourcesPillees` bigint(100) NOT NULL DEFAULT 0,
  `tradeVolume` double NOT NULL DEFAULT 0,
  `totalPoints` int(11) NOT NULL DEFAULT 0,
  `missions` text NOT NULL,
  `neutrinos` int(11) NOT NULL DEFAULT 0,
  `streak_days` int(11) NOT NULL DEFAULT 0,
  `streak_last_date` date DEFAULT NULL,
  `last_catch_up` int(11) NOT NULL DEFAULT 0,
  `comeback_shield_until` int(11) NOT NULL DEFAULT 0,
  `alliance_left_at` int(10) unsigned DEFAULT NULL,
  `vp_awarded` int(11) NOT NULL DEFAULT 0,
  `nbDons` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`login`),
  KEY `idx_autre_alliance_left_at` (`login`, `alliance_left_at`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `connectes` (
  `ip` varchar(64) NOT NULL DEFAULT '',
  `timestamp` int(11) NOT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `constructions` (
  `login` varchar(255) NOT NULL,
  `generateur` int(11) NOT NULL DEFAULT 1,
  `producteur` int(11) NOT NULL DEFAULT 1,
  `champdeforce` int(11) NOT NULL DEFAULT 0,
  `ionisateur` int(11) NOT NULL DEFAULT 0,
  `depot` int(11) NOT NULL DEFAULT 1,
  `stabilisateur` int(11) NOT NULL DEFAULT 0,
  `condenseur` int(11) NOT NULL DEFAULT 0,
  `lieur` int(11) NOT NULL DEFAULT 0,
  `vieGenerateur` bigint(100) NOT NULL DEFAULT 0,
  `vieChampdeforce` bigint(100) NOT NULL DEFAULT 0,
  `vieProducteur` bigint(100) NOT NULL DEFAULT 0,
  `vieDepot` bigint(100) NOT NULL DEFAULT 30,
  `vieIonisateur` bigint(100) NOT NULL DEFAULT 0,
  `vieStabilisateur` bigint(100) NOT NULL DEFAULT 0,
  `vieCondenseur` bigint(100) NOT NULL DEFAULT 0,
  `vieLieur` bigint(100) NOT NULL DEFAULT 0,
  `pointsProducteur` varchar(10000) NOT NULL DEFAULT '1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',
  `coffrefort` int(11) NOT NULL DEFAULT 0,
  `formation` tinyint(4) NOT NULL DEFAULT 0,
  `spec_combat` tinyint(4) NOT NULL DEFAULT 0,
  `spec_economy` tinyint(4) NOT NULL DEFAULT 0,
  `spec_research` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `cours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tableauCours` text NOT NULL,
  `timestamp` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cours_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `declarations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(11) NOT NULL,
  `alliance1` varchar(255) NOT NULL,
  `alliance2` varchar(255) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `pertes1` bigint(100) NOT NULL DEFAULT 0,
  `pertes2` bigint(100) NOT NULL DEFAULT 0,
  `fin` int(11) NOT NULL DEFAULT 0,
  `pertesTotales` int(11) GENERATED ALWAYS AS (`pertes1` + `pertes2`) STORED,
  `valide` tinyint(4) NOT NULL DEFAULT 0,
  `winner` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `forums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titre` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `grades` (
  `login` varchar(255) NOT NULL,
  `grade` varchar(255) NOT NULL,
  `idalliance` int(11) NOT NULL,
  `nom` varchar(255) NOT NULL,
  PRIMARY KEY (`login`, `idalliance`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idalliance` int(11) NOT NULL,
  `tag` varchar(255) NOT NULL,
  `invite` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invite_alliance` (`invite`, `idalliance`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `membre` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `pass_md5` text NOT NULL,
  `pass_bcrypt` text DEFAULT NULL,
  `timestamp` int(11) NOT NULL,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `derniereConnexion` int(11) NOT NULL DEFAULT 0,
  `vacance` tinyint(1) NOT NULL DEFAULT 0,
  `troll` int(11) NOT NULL DEFAULT 1,
  `moderateur` int(11) NOT NULL DEFAULT 0,
  `codeur` tinyint(4) NOT NULL DEFAULT 0,
  `email` varchar(255) NOT NULL DEFAULT 'rien@rien.com',
  `x` int(11) NOT NULL DEFAULT -1000,
  `y` int(11) NOT NULL DEFAULT -1000,
  `session_token` varchar(64) DEFAULT NULL,
  `estExclu` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_membre_login` (`login`),
  UNIQUE KEY `uq_membre_email` (`email`),
  KEY `idx_session_token` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL DEFAULT 'Sans titre',
  `contenu` text NOT NULL,
  `expeditaire` varchar(255) NOT NULL,
  `destinataire` varchar(255) NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_by_sender` tinyint(4) NOT NULL DEFAULT 0,
  `deleted_by_recipient` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_messages_soft_delete` (`deleted_by_sender`, `deleted_by_recipient`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `moderation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destinataire` varchar(255) NOT NULL,
  `energie` bigint(50) NOT NULL DEFAULT 0,
  `carbone` bigint(50) NOT NULL DEFAULT 0,
  `azote` bigint(50) NOT NULL DEFAULT 0,
  `hydrogene` bigint(5) NOT NULL DEFAULT 0,
  `oxygene` bigint(50) NOT NULL DEFAULT 0,
  `chlore` bigint(50) NOT NULL DEFAULT 0,
  `soufre` bigint(50) NOT NULL DEFAULT 0,
  `brome` bigint(50) NOT NULL DEFAULT 0,
  `iode` bigint(50) NOT NULL DEFAULT 0,
  `justification` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `molecules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `formule` varchar(255) NOT NULL DEFAULT 'Vide',
  `hydrogene` int(11) NOT NULL DEFAULT 0,
  `carbone` int(11) NOT NULL DEFAULT 0,
  `oxygene` int(11) NOT NULL DEFAULT 0,
  `azote` int(11) NOT NULL DEFAULT 0,
  `iode` int(11) NOT NULL DEFAULT 0,
  `brome` int(11) NOT NULL DEFAULT 0,
  `chlore` int(11) NOT NULL DEFAULT 0,
  `soufre` int(11) NOT NULL DEFAULT 0,
  `numeroclasse` smallint(6) NOT NULL DEFAULT 0,
  `proprietaire` varchar(255) NOT NULL,
  `nombre` double NOT NULL DEFAULT 0,
  `isotope` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titre` varchar(255) NOT NULL DEFAULT '',
  `contenu` text NOT NULL,
  `timestamp` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `debut` int(100) NOT NULL,
  `joueurs` text NOT NULL,
  `alliances` text NOT NULL,
  `guerres` text NOT NULL,
  `season_number` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_parties_season_number` (`season_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `rapports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `contenu` longtext NOT NULL,
  `destinataire` varchar(255) NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `image` varchar(255) NOT NULL DEFAULT '',
  `type` enum('attack','espionage','defense','alliance','combat') NOT NULL DEFAULT 'attack',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `reponses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idsujet` int(11) NOT NULL,
  `visibilite` int(11) NOT NULL DEFAULT 1,
  `contenu` text NOT NULL,
  `auteur` varchar(255) NOT NULL,
  `timestamp` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ressources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `energie` double NOT NULL DEFAULT 64,
  `terrain` double NOT NULL DEFAULT 100,
  `revenuenergie` bigint(100) NOT NULL DEFAULT 12,
  `niveauclasse` smallint(6) NOT NULL DEFAULT 1,
  `hydrogene` double NOT NULL DEFAULT 64,
  `carbone` double NOT NULL DEFAULT 64,
  `oxygene` double NOT NULL DEFAULT 64,
  `iode` double NOT NULL DEFAULT 64,
  `brome` double NOT NULL DEFAULT 64,
  `chlore` double NOT NULL DEFAULT 64,
  `soufre` double NOT NULL DEFAULT 64,
  `azote` double NOT NULL DEFAULT 64,
  `revenuhydrogene` bigint(100) NOT NULL DEFAULT 9,
  `revenucarbone` bigint(100) NOT NULL DEFAULT 9,
  `revenuoxygene` bigint(100) NOT NULL DEFAULT 9,
  `revenuiode` bigint(100) NOT NULL DEFAULT 9,
  `revenubrome` bigint(100) NOT NULL DEFAULT 9,
  `revenuchlore` bigint(100) NOT NULL DEFAULT 9,
  `revenusoufre` bigint(100) NOT NULL DEFAULT 9,
  `revenuazote` bigint(100) NOT NULL DEFAULT 9,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `sanctions` (
  `idSanction` int(11) NOT NULL AUTO_INCREMENT,
  `joueur` varchar(30) NOT NULL,
  `dateDebut` date NOT NULL,
  `dateFin` date NOT NULL,
  `motif` text NOT NULL,
  `moderateur` varchar(30) NOT NULL,
  PRIMARY KEY (`idSanction`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `statistiques` (
  `inscrits` int(11) NOT NULL DEFAULT 0,
  `maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `debut` int(11) NOT NULL DEFAULT 0,
  `numerovisiteur` int(11) NOT NULL DEFAULT 1,
  `tailleCarte` int(11) NOT NULL DEFAULT 1,
  `nbDerniere` int(11) NOT NULL DEFAULT 0,
  `catalyst` tinyint(4) NOT NULL DEFAULT 0,
  `catalyst_week` int(11) NOT NULL DEFAULT 0,
  `prestige_awarded_season` int(11) NOT NULL DEFAULT 0,
  `maintenance_started_at` int(11) NOT NULL DEFAULT 0,
  `emails_queued_season` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`inscrits`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `statutforum` (
  `login` varchar(255) NOT NULL,
  `idsujet` int(11) NOT NULL,
  `idforum` int(11) NOT NULL,
  PRIMARY KEY (`login`, `idsujet`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `sujets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idforum` int(11) NOT NULL,
  `titre` varchar(255) CHARACTER SET latin1 NOT NULL,
  `contenu` text CHARACTER SET latin1 NOT NULL,
  `auteur` varchar(255) CHARACTER SET latin1 NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `timestamp` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tutoriel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `niveau` int(11) NOT NULL,
  `bonusenergie` double NOT NULL DEFAULT 0,
  `bonushydrogene` double NOT NULL DEFAULT 0,
  `bonusoxygene` double NOT NULL DEFAULT 0,
  `bonuscarbone` double NOT NULL DEFAULT 0,
  `bonusazote` double NOT NULL DEFAULT 0,
  `bonusiode` double NOT NULL DEFAULT 0,
  `bonusbrome` double NOT NULL DEFAULT 0,
  `bonuschlore` double NOT NULL DEFAULT 0,
  `bonussoufre` double NOT NULL DEFAULT 0,
  `description` text CHARACTER SET latin1 NOT NULL,
  `bonus` text CHARACTER SET latin1 NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `vacances` (
  `idVacance` int(11) NOT NULL AUTO_INCREMENT,
  `idJoueur` int(11) NOT NULL,
  `dateDebut` date NOT NULL,
  `dateFin` date NOT NULL,
  PRIMARY KEY (`idVacance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================
-- New tables added via migrations (not in original SQL dump)
-- ============================================================

CREATE TABLE IF NOT EXISTS `attack_cooldowns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attacker` varchar(50) NOT NULL,
  `defender` varchar(50) NOT NULL,
  `expires` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attacker_defender` (`attacker`, `defender`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `prestige` (
  `login` varchar(50) NOT NULL,
  `total_pp` int(10) unsigned NOT NULL DEFAULT 0,
  `unlocks` text NOT NULL DEFAULT '',
  PRIMARY KEY (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `login_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `ip` varchar(64) NOT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `fingerprint` varchar(64) DEFAULT NULL,
  `timestamp` int(11) NOT NULL,
  `event_type` enum('login','register','action') NOT NULL DEFAULT 'login',
  PRIMARY KEY (`id`),
  KEY `idx_login_history_login` (`login`),
  KEY `idx_login_history_ip` (`ip`),
  KEY `idx_login_history_fingerprint` (`fingerprint`),
  KEY `idx_login_history_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `account_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `flag_type` enum('same_ip','same_fingerprint','coord_attack','coord_transfer','timing_correlation','manual') NOT NULL,
  `related_login` varchar(255) DEFAULT NULL,
  `evidence` text NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('open','investigating','confirmed','dismissed') NOT NULL DEFAULT 'open',
  `created_at` int(11) NOT NULL,
  `resolved_at` int(11) DEFAULT NULL,
  `resolved_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_flags_login` (`login`),
  KEY `idx_flags_related` (`related_login`),
  KEY `idx_flags_status` (`status`),
  KEY `idx_flags_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `admin_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `details` text DEFAULT NULL,
  `login1` varchar(50) DEFAULT NULL,
  `login2` varchar(50) DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alerts_unread` (`is_read`, `created_at`),
  KEY `idx_alerts_pair` (`alert_type`, `login1`, `login2`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `resource_nodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `resource_type` enum('carbone','azote','hydrogene','oxygene','chlore','soufre','brome','iode','energie') NOT NULL,
  `bonus_pct` decimal(5,2) NOT NULL DEFAULT 10.00,
  `radius` int(11) NOT NULL DEFAULT 5,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_nodes_coords` (`x`, `y`),
  KEY `idx_nodes_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `player_compounds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `compound_key` varchar(20) NOT NULL,
  `activated_at` int(11) DEFAULT NULL,
  `expires_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_player_compound` (`login`, `compound_key`),
  KEY `idx_compounds_login` (`login`),
  KEY `idx_compounds_active` (`login`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `season_recap` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `season_number` int(11) NOT NULL,
  `login` varchar(255) NOT NULL,
  `final_rank` int(11) NOT NULL DEFAULT 0,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `points_attaque` int(11) NOT NULL DEFAULT 0,
  `points_defense` int(11) NOT NULL DEFAULT 0,
  `trade_volume` double NOT NULL DEFAULT 0,
  `ressources_pillees` bigint(20) NOT NULL DEFAULT 0,
  `nb_attaques` int(11) NOT NULL DEFAULT 0,
  `victoires` int(11) NOT NULL DEFAULT 0,
  `molecules_perdues` bigint(20) NOT NULL DEFAULT 0,
  `alliance_name` varchar(255) DEFAULT NULL,
  `streak_max` int(11) NOT NULL DEFAULT 0,
  `batmax` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_season_player` (`season_number`, `login`),
  KEY `idx_season` (`season_number`),
  KEY `idx_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `sondages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question` varchar(500) NOT NULL,
  `options` text NOT NULL,
  `date` int(11) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `reponses_sondage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) NOT NULL,
  `sondage` int(11) NOT NULL,
  `reponse` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login_sondage` (`login`, `sondage`),
  KEY `idx_sondage` (`sondage`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_email` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` text NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `sent_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_unsent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `moderation_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moderator_login` varchar(255) NOT NULL,
  `target_post_id` int(11) NOT NULL,
  `post_type` enum('sujet','reponse') NOT NULL DEFAULT 'reponse',
  `original_content` text NOT NULL,
  `new_content` text NOT NULL,
  `action_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_moderator` (`moderator_login`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
