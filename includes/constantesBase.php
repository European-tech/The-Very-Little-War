<?php
require_once(__DIR__ . '/config.php');

// Derive from config.php single source of truth
$nomsRes = $RESOURCE_NAMES;
$nomsAccents = $RESOURCE_NAMES_ACCENTED;
$couleurs = $RESOURCE_COLORS;
$couleursSimples = $RESOURCE_COLORS_SIMPLE;
$utilite = array("Défense","Temps de formation","Dégâts aux bâtiments","Attaque","Vitesse de déplacement","Capacité de pillage","Points de vie","Produit de l'énergie");
$lettre = array("C", "N", "H", "O", "Cl","S","Br","I");
$aidesAtomes = ['Le carbone augmente la défense de votre molécule. Ce sont les dégâts que votre molécule infligera aux molécules adverses lorsque l\'on vous attaquera.',
               'L\'azote diminue le temps de formation de votre molécule : plus il y a d\'azote dans votre molécule, moins cela prendra de temps pour créer une de ces molécules.',
               'L\'hydrogène inflige des dégâts aux bâtiments adverses lors de vos attaques. Cela vous permettra d\'affaiblir la production adverses.',
               'L\'oxygène augmente l\'attaque de votre molécule. Ce sont les dégâts que votre molécule infligera aux molécules adverses lorsque vous attaquerez.',
               'Le chlore augmente la vitesse de déplacement de vos molécules sur la carte. Il vous faudra des molécules rapides si vous voulez prendre par surprise un adversaire loin de vous.',
               'Le soufre vous permet de piller l\'adversaire lors d\'une des vos attaques. Vous récupérez ainsi une partie de ses ressources pour vous.',
               'Le brome augmente les points de vie de vos molécules. Lors d\'une attaque, les dégâts infligés par les molécules adverses seront comparés à la vie de vos molécules pour déterminer le nombre de morts.',
               'L\'iode est particulier, cela permet de produire de l\'énergie. Ces molécules seront plutôt destinées à rester chez vous mais devront être défendues par des molécules carbonées pour éviter que vous perdiez toute votre production sur une attaque surprise.'];
$nbRes = count($nomsRes); // MARKET-CRIT-001: 8 atoms incl. iode at index 7
$nbClasses = MAX_MOLECULE_CLASSES;
$nbPointsVictoire = VICTORY_POINTS_TOTAL;

// Derive from config.php — single source of truth for medal tiers and thresholds
$paliersMedailles = $MEDAL_TIER_NAMES;
$imagesMedailles  = $MEDAL_TIER_IMAGES;

$bonusMedailles = $MEDAL_BONUSES;
$bonusForum     = $MEDAL_FORUM_BADGES;
$bonusTroll = ['Rien','Rien','Rien','Rien','Rien','Rien','Rien','Rien'];

$paliersTerreur     = $MEDAL_THRESHOLDS_TERREUR;
$paliersAttaque     = $MEDAL_THRESHOLDS_ATTAQUE;
$paliersDefense     = $MEDAL_THRESHOLDS_DEFENSE;
$paliersPillage     = $MEDAL_THRESHOLDS_PILLAGE;
$paliersPipelette   = $MEDAL_THRESHOLDS_PIPELETTE;
$paliersPertes      = $MEDAL_THRESHOLDS_PERTES;
$paliersEnergievore = $MEDAL_THRESHOLDS_ENERGIEVORE;
$paliersConstructeur = $MEDAL_THRESHOLDS_CONSTRUCTEUR;
$paliersBombe       = $MEDAL_THRESHOLDS_BOMBE;
$paliersTroll       = $MEDAL_THRESHOLDS_TROLL;

//EQUIPES
$joueursEquipe = MAX_ALLIANCE_MEMBERS;

//MARCHE

$vitesseMarchands = MERCHANT_SPEED;

//ESPIONNAGE
$vitesseEspionnage = ESPIONAGE_SPEED;
$coutNeutrino = NEUTRINO_COST;

// Ensure .env is loaded (may already be loaded by connexion.php)
require_once(__DIR__ . '/env.php');
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    loadEnv($envPath);
}

// Admin password hash — loaded from .env for security, falls back to placeholder.
// To set: add ADMIN_PASSWORD_HASH="$2y$10$..." to .env
// To generate: php -r "echo password_hash('new-password', PASSWORD_DEFAULT);"
if (!defined('ADMIN_PASSWORD_HASH')) {
    $envHash = getenv('ADMIN_PASSWORD_HASH');
    define('ADMIN_PASSWORD_HASH', $envHash !== false && $envHash !== '' ? $envHash : '$2y$10$PLACEHOLDER_SET_IN_ENV');
}
?>