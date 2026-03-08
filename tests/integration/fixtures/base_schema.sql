-- TVLW Test Database Schema
-- Auto-extracted from production SQL dump
-- Includes all 28 tables

CREATE TABLE `actionsattaques` (
  `id` int(11) NOT NULL,
  `attaquant` varchar(500) NOT NULL,
  `defenseur` varchar(500) NOT NULL,
  `tempsAller` int(11) NOT NULL,
  `tempsAttaque` int(11) NOT NULL,
  `tempsRetour` int(11) NOT NULL,
  `troupes` text NOT NULL,
  `attaqueFaite` int(11) NOT NULL,
  `nombreneutrinos` bigint(20) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `actionsconstruction` (
  `id` int(11) NOT NULL,
  `login` varchar(255) NOT NULL,
  `debut` varchar(255) NOT NULL,
  `fin` varchar(255) NOT NULL,
  `batiment` varchar(255) NOT NULL,
  `niveau` int(11) NOT NULL,
  `affichage` text NOT NULL,
  `points` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `actionsenvoi` (
  `id` int(11) NOT NULL,
  `envoyeur` varchar(500) NOT NULL,
  `receveur` varchar(500) NOT NULL,
  `ressourcesEnvoyees` text NOT NULL,
  `ressourcesRecues` text NOT NULL,
  `tempsArrivee` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `actionsformation` (
  `id` int(11) NOT NULL,
  `idclasse` varchar(50) NOT NULL DEFAULT '0',
  `login` varchar(255) NOT NULL,
  `debut` bigint(20) NOT NULL,
  `fin` bigint(20) NOT NULL,
  `nombreDebut` bigint(100) NOT NULL,
  `nombreRestant` bigint(100) NOT NULL,
  `formule` varchar(1000) NOT NULL,
  `tempsPourUn` double NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `alliances` (
  `id` int(11) NOT NULL,
  `nom` varchar(155) NOT NULL,
  `tag` varchar(155) NOT NULL,
  `description` text NOT NULL,
  `pointstotaux` int(11) NOT NULL DEFAULT 0,
  `chef` varchar(255) NOT NULL,
  `energieAlliance` bigint(100) NOT NULL DEFAULT 0,
  `duplicateur` int(11) NOT NULL DEFAULT 0,
  `energieTotaleRecue` bigint(100) NOT NULL,
  `totalConstructions` int(11) NOT NULL DEFAULT 0,
  `totalAttaque` int(11) NOT NULL DEFAULT 0,
  `totalDefense` int(11) NOT NULL DEFAULT 0,
  `totalPillage` int(11) NOT NULL DEFAULT 0,
  `pointsVictoire` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `autre` (
  `login` varchar(255) NOT NULL,
  `points` int(11) NOT NULL DEFAULT 0,
  `idalliance` int(11) NOT NULL DEFAULT 0,
  `description` text NOT NULL,
  `tempsPrecedent` int(11) NOT NULL,
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
  `totalPoints` int(11) NOT NULL DEFAULT 0,
  `missions` text NOT NULL,
  `neutrinos` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `connectes` (
  `ip` varchar(15) NOT NULL,
  `timestamp` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `constructions` (
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
  `pointsProducteur` varchar(10000) NOT NULL DEFAULT '1;

CREATE TABLE `cours` (
  `id` int(11) NOT NULL,
  `tableauCours` text NOT NULL,
  `timestamp` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `declarations` (
  `id` int(11) NOT NULL,
  `type` int(11) NOT NULL,
  `alliance1` varchar(255) NOT NULL,
  `alliance2` varchar(255) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `pertes1` bigint(100) NOT NULL DEFAULT 0,
  `pertes2` bigint(100) NOT NULL DEFAULT 0,
  `fin` int(11) NOT NULL DEFAULT 0,
  `pertesTotales` bigint(100) NOT NULL DEFAULT 0,
  `valide` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `forums` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `grades` (
  `login` varchar(255) NOT NULL,
  `grade` varchar(255) NOT NULL,
  `idalliance` int(11) NOT NULL,
  `nom` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `invitations` (
  `id` int(11) NOT NULL,
  `idalliance` int(11) NOT NULL,
  `tag` varchar(255) NOT NULL,
  `invite` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `membre` (
  `id` int(11) NOT NULL,
  `login` text NOT NULL,
  `pass_md5` text NOT NULL,
  `timestamp` int(11) NOT NULL,
  `ip` varchar(11) NOT NULL,
  `derniereConnexion` int(11) NOT NULL DEFAULT 0,
  `vacance` tinyint(1) NOT NULL,
  `troll` int(11) NOT NULL DEFAULT 1,
  `moderateur` int(11) NOT NULL,
  `codeur` tinyint(4) NOT NULL DEFAULT 0,
  `email` varchar(255) NOT NULL DEFAULT 'rien@rien.com',
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL DEFAULT 'Sans titre',
  `contenu` text NOT NULL,
  `expeditaire` varchar(255) NOT NULL,
  `destinataire` varchar(255) NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `moderation` (
  `id` int(11) NOT NULL,
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
  `justification` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `molecules` (
  `id` int(11) NOT NULL,
  `formule` varchar(255) NOT NULL DEFAULT 'Vide',
  `hydrogene` int(11) NOT NULL DEFAULT 0,
  `carbone` int(11) NOT NULL DEFAULT 0,
  `oxygene` int(11) NOT NULL DEFAULT 0,
  `azote` int(11) NOT NULL DEFAULT 0,
  `iode` int(11) NOT NULL DEFAULT 0,
  `brome` int(11) NOT NULL DEFAULT 0,
  `chlore` int(11) NOT NULL DEFAULT 0,
  `soufre` int(11) NOT NULL DEFAULT 0,
  `numeroclasse` smallint(6) NOT NULL,
  `proprietaire` varchar(255) NOT NULL,
  `nombre` double NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `news` (
  `id` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `contenu` text NOT NULL,
  `timestamp` bigint(20) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `parties` (
  `id` int(11) NOT NULL,
  `debut` int(100) NOT NULL,
  `joueurs` text NOT NULL,
  `alliances` text NOT NULL,
  `guerres` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `rapports` (
  `id` int(11) NOT NULL,
  `timestamp` int(11) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `contenu` longtext NOT NULL,
  `destinataire` varchar(255) NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `image` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `reponses` (
  `id` int(11) NOT NULL,
  `idsujet` int(11) NOT NULL,
  `visibilite` int(11) NOT NULL DEFAULT 1,
  `contenu` text NOT NULL,
  `auteur` varchar(255) NOT NULL,
  `timestamp` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `ressources` (
  `id` int(11) NOT NULL,
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
  `revenuazote` bigint(100) NOT NULL DEFAULT 9
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `sanctions` (
  `idSanction` int(11) NOT NULL,
  `joueur` varchar(30) NOT NULL,
  `dateDebut` date NOT NULL,
  `dateFin` date NOT NULL,
  `motif` text NOT NULL,
  `moderateur` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `statistiques` (
  `inscrits` int(11) NOT NULL,
  `maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `debut` int(11) NOT NULL,
  `numerovisiteur` int(11) NOT NULL DEFAULT 1,
  `tailleCarte` int(11) NOT NULL DEFAULT 1,
  `nbDerniere` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `statutforum` (
  `login` varchar(255) NOT NULL,
  `idsujet` int(11) NOT NULL,
  `idforum` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `sujets` (
  `id` int(11) NOT NULL,
  `idforum` int(11) NOT NULL,
  `titre` varchar(255) CHARACTER SET latin1 NOT NULL,
  `contenu` text CHARACTER SET latin1 NOT NULL,
  `auteur` varchar(255) CHARACTER SET latin1 NOT NULL,
  `statut` tinyint(1) NOT NULL DEFAULT 0,
  `timestamp` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `tutoriel` (
  `id` int(11) NOT NULL,
  `niveau` int(11) NOT NULL,
  `bonusenergie` double NOT NULL,
  `bonushydrogene` double NOT NULL,
  `bonusoxygene` double NOT NULL,
  `bonuscarbone` double NOT NULL,
  `bonusazote` double NOT NULL,
  `bonusiode` double NOT NULL,
  `bonusbrome` double NOT NULL,
  `bonuschlore` double NOT NULL,
  `bonussoufre` double NOT NULL,
  `description` text CHARACTER SET latin1 NOT NULL,
  `bonus` text CHARACTER SET latin1 NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `vacances` (
  `idVacance` int(11) NOT NULL,
  `idJoueur` int(11) NOT NULL,
  `dateDebut` date NOT NULL,
  `dateFin` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

