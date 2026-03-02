<?php
/**
 * TVLW Game Configuration
 * All game balance parameters in one place.
 * Modify these values to tune game balance.
 *
 * This file is the single source of truth for all magic numbers
 * and game constants used throughout the codebase.
 */

// =============================================================================
// TIME CONSTANTS
// =============================================================================
define('SECONDS_PER_HOUR', 3600);
define('SECONDS_PER_DAY', 86400);
define('SECONDS_PER_WEEK', 604800);
define('SECONDS_PER_MONTH', 2678400); // 31 days - used for active player check

// =============================================================================
// GAME LIMITS
// =============================================================================
define('MAX_CONCURRENT_CONSTRUCTIONS', 2);
define('MAX_MOLECULE_CLASSES', 4);       // 4 classes per player
define('MAX_ATOMS_PER_ELEMENT', 200);    // max atoms of one type in a molecule
define('MAX_ALLIANCE_MEMBERS', 20);      // $joueursEquipe
define('BEGINNER_PROTECTION_SECONDS', 5 * SECONDS_PER_DAY); // 432000 = 5 days
define('NEW_PLAYER_BOOST_DURATION', 3 * SECONDS_PER_DAY); // 259200 = 3 days of 2x production
define('NEW_PLAYER_BOOST_MULTIPLIER', 2); // production multiplier during boost period
define('ABSENCE_REPORT_THRESHOLD_HOURS', 6); // hours offline before loss report
define('ONLINE_TIMEOUT_SECONDS', 300);   // 60 * 5 = 5 minutes for online status
define('VICTORY_POINTS_TOTAL', 1000);    // $nbPointsVictoire

// =============================================================================
// RESOURCE NAMES AND DISPLAY
// =============================================================================
// These arrays define the 8 atom types and their display properties.
// Changing these would require database schema changes.
$RESOURCE_NAMES = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
$RESOURCE_NAMES_ACCENTED = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
$RESOURCE_COLORS = ['black', 'blue', 'gray', 'red', 'green', '#D07D00', '#840000', '#BB6668'];
$RESOURCE_COLORS_SIMPLE = ['black', 'blue', 'gray', 'red', 'green', 'orange', 'brown', 'pink'];
$RESOURCE_LETTERS = ['C', 'N', 'H', 'O', 'Cl', 'S', 'Br', 'I'];

// =============================================================================
// RESOURCE PRODUCTION FORMULAS
// =============================================================================
// Energy: revenuEnergie = BASE_ENERGY_PER_LEVEL * generateur_level
define('BASE_ENERGY_PER_LEVEL', 75);

// Atoms: revenuAtome = bonusDuplicateur * BASE_ATOMS_PER_POINT * niveau
define('BASE_ATOMS_PER_POINT', 60);

// Storage: placeDepot = BASE_STORAGE_PER_LEVEL * depot_level
define('BASE_STORAGE_PER_LEVEL', 500);

// Producteur energy drain: drainageProducteur = PRODUCTEUR_DRAIN_PER_LEVEL * level
define('PRODUCTEUR_DRAIN_PER_LEVEL', 8);

// =============================================================================
// MOLECULE STAT FORMULAS
// Formulas: stat = round((1 + (COEF * atoms)^2 + atoms) * (1 + level / LEVEL_DIVISOR))
// =============================================================================

// Attack: round((1 + (0.1 * oxygene)^2 + oxygene) * (1 + niveau / 50))
define('ATTACK_ATOM_COEFFICIENT', 0.1);
define('ATTACK_LEVEL_DIVISOR', 50);

// Defense: round((1 + (0.1 * carbone)^2 + carbone) * (1 + niveau / 50))
define('DEFENSE_ATOM_COEFFICIENT', 0.1);
define('DEFENSE_LEVEL_DIVISOR', 50);

// HP (brome): round((1 + (0.1 * brome)^2 + brome) * (1 + niveau / 50))
define('HP_ATOM_COEFFICIENT', 0.1);
define('HP_LEVEL_DIVISOR', 50);

// Destruction (hydrogene): round(((0.075 * hydrogene)^2 + hydrogene) * (1 + niveau / 50))
define('DESTRUCTION_ATOM_COEFFICIENT', 0.075);
define('DESTRUCTION_LEVEL_DIVISOR', 50);

// Pillage (soufre): round(((0.1 * soufre)^2 + soufre / 3) * (1 + niveau / 50))
define('PILLAGE_ATOM_COEFFICIENT', 0.1);
define('PILLAGE_SOUFRE_DIVISOR', 3);
define('PILLAGE_LEVEL_DIVISOR', 50);

// Iode energy production: round(0.10 * iode * (1 + niveau / 50))
// Buffed from 0.01→0.05→0.10 to make iodine a real energy source
define('IODE_ENERGY_COEFFICIENT', 0.10);
define('IODE_LEVEL_DIVISOR', 50);

// Speed (chlore): floor((1 + 0.5 * chlore) * (1 + niveau / 50) * 100) / 100
define('SPEED_ATOM_COEFFICIENT', 0.5);
define('SPEED_LEVEL_DIVISOR', 50);

// Formation time (azote): ceil(ntotal / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur * 100) / 100
define('FORMATION_AZOTE_COEFFICIENT', 0.09);
define('FORMATION_AZOTE_EXPONENT', 1.09);
define('FORMATION_LEVEL_DIVISOR', 20);

// =============================================================================
// MOLECULE DECAY / DISAPPEARANCE
// =============================================================================
// coefDisparition = pow(pow(0.99, pow(1 + nbAtomes / 100, 2) / 5000), ...)
define('DECAY_BASE', 0.99);
define('DECAY_ATOM_DIVISOR', 150); // Increased from 100 — large molecules slightly more viable
define('DECAY_POWER_DIVISOR', 25000);
define('STABILISATEUR_BONUS_PER_LEVEL', 0.015); // 1.5% per level (buffed from 1%)

// =============================================================================
// BUILDING HP FORMULAS
// =============================================================================
// pointsDeVie = round(BASE * (pow(1.2, level) + pow(level, 1.2)))
define('BUILDING_HP_BASE', 20);
define('BUILDING_HP_GROWTH_BASE', 1.2);
define('BUILDING_HP_LEVEL_EXP', 1.2);

// vieChampDeForce = round(BASE * (pow(1.2, level) + pow(level, 1.2)))
define('FORCEFIELD_HP_BASE', 50);
define('FORCEFIELD_HP_GROWTH_BASE', 1.2);
define('FORCEFIELD_HP_LEVEL_EXP', 1.2);

// =============================================================================
// BUILDING COST FORMULAS
// Cost: round((1 - medalBonus/100) * BASE_COST * pow(level, COST_EXP))
// Time: round(BASE_TIME * pow(level, TIME_EXP)) seconds
// =============================================================================
$BUILDING_CONFIG = [
    'generateur' => [
        'cost_energy_base'  => 50,
        'cost_energy_exp'   => 0.7,
        'cost_atoms_base'   => 75,   // cost per atom type
        'cost_atoms_exp'    => 0.7,
        'time_base'         => 60,   // seconds (level 1 = 10s special case)
        'time_exp'          => 1.5,
        'time_level1'       => 10,   // special case: level 1 construction time
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'description'       => 'Generates energy',
    ],
    'producteur' => [
        'cost_energy_base'  => 75,
        'cost_energy_exp'   => 0.7,
        'cost_atoms_base'   => 50,
        'cost_atoms_exp'    => 0.7,
        'time_base'         => 40,
        'time_exp'          => 1.5,
        'time_level1'       => 10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'points_per_level'  => 8,    // sizeof($nomsRes) = 8 points to distribute
        'description'       => 'Produces atoms (drains energy)',
    ],
    'depot' => [
        'cost_energy_base'  => 100,
        'cost_energy_exp'   => 0.7,
        'cost_atoms_base'   => 0,    // no atom cost
        'cost_atoms_exp'    => 0,
        'time_base'         => 80,
        'time_exp'          => 1.5,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'description'       => 'Increases max resource storage',
    ],
    'champdeforce' => [
        'cost_carbone_base' => 100,
        'cost_carbone_exp'  => 0.7,
        'time_base'         => 20,
        'time_exp'          => 1.7,
        'time_level_offset' => 2,    // pow(level + 2, exp)
        'points_base'       => 1,
        'points_level_factor' => 0.075,
        'bonus_per_level'   => 2,    // +2% defense per level
        'description'       => 'Provides defense bonus, absorbs damage first if highest level',
    ],
    'ionisateur' => [
        'cost_oxygene_base' => 100,
        'cost_oxygene_exp'  => 0.7,
        'time_base'         => 20,
        'time_exp'          => 1.7,
        'time_level_offset' => 2,    // pow(level + 2, exp)
        'points_base'       => 1,
        'points_level_factor' => 0.075,
        'bonus_per_level'   => 2,    // +2% attack per level
        'description'       => 'Provides attack bonus',
    ],
    'condenseur' => [
        'cost_energy_base'  => 25,
        'cost_energy_exp'   => 0.8,
        'cost_atoms_base'   => 100,
        'cost_atoms_exp'    => 0.8,
        'time_base'         => 120,
        'time_exp'          => 1.6,  // reduced from 1.8 for faster military progression
        'time_level_offset' => 1,    // pow(level + 1, exp)
        'points_base'       => 2,
        'points_level_factor' => 0.1,
        'points_per_level'  => 5,    // condenseur points per level
        'description'       => 'Improves atom effectiveness via levels',
    ],
    'lieur' => [
        'cost_azote_base'   => 100,
        'cost_azote_exp'    => 0.8,
        'time_base'         => 100,
        'time_exp'          => 1.5,  // reduced from 1.7 for faster military progression
        'time_level_offset' => 1,    // pow(level + 1, exp)
        'points_base'       => 2,
        'points_level_factor' => 0.1,
        'lieur_growth_base' => 1.07, // bonusLieur = floor(100 * pow(1.07, level)) / 100
        'description'       => 'Reduces molecule formation time',
    ],
    'stabilisateur' => [
        'cost_atoms_base'   => 75,
        'cost_atoms_exp'    => 0.9,
        'time_base'         => 120,
        'time_exp'          => 1.5,  // reduced from 1.7 for faster military progression
        'time_level_offset' => 1,    // pow(level + 1, exp)
        'points_base'       => 3,
        'points_level_factor' => 0.1,
        'stability_per_level' => 0.5, // 0.5% decay reduction per level
        'description'       => 'Reduces molecule decay rate',
    ],
];

// =============================================================================
// COMBAT FORMULAS
// =============================================================================
// Attack energy cost: 0.15 * (1 + terreur_medal_bonus / 100) * nbAtomes per molecule
define('ATTACK_ENERGY_COST_FACTOR', 0.15);

// Ionisateur/Champdeforce combat bonus: level * 2 / 100
define('IONISATEUR_COMBAT_BONUS_PER_LEVEL', 2);  // +2% per level
define('CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL', 2); // +2% per level

// Duplicateur combat bonus in combat.php: duplicateur_level / 100 (1% per level, matching resource bonus)
// Fixed: was (0.1 * level) / 100 giving only 0.1% per level (10x too weak)
define('DUPLICATEUR_COMBAT_COEFFICIENT', 1.0);

// Building damage targeting: random 1-4 selects target building
define('NUM_DAMAGEABLE_BUILDINGS', 4); // generateur, champdeforce, producteur, depot

// Combat point scaling
// Raw combat points awarded = floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt(total_casualties))
// Only the winner gets positive points; loser gets negative
define('COMBAT_POINTS_BASE', 1);           // Minimum points for any combat
define('COMBAT_POINTS_CASUALTY_SCALE', 0.5); // Scale factor for sqrt(casualties)
define('COMBAT_POINTS_MAX_PER_BATTLE', 20);  // Cap per single battle

// pointsAttaque/pointsDefense scaling: sqrt(rawPoints) * MULTIPLIER
// Boosted from 3.0 to 5.0 to bring combat from ~10% to ~25-30% of total points
define('ATTACK_POINTS_MULTIPLIER', 5.0);
define('DEFENSE_POINTS_MULTIPLIER', 5.0);

// Defensive rewards — incentivize defense as a viable playstyle
define('DEFENSE_REWARD_RATIO', 0.20);         // 20% resource bonus on successful defense
define('DEFENSE_POINTS_MULTIPLIER_BONUS', 1.5); // 1.5x combat points for defensive victories
define('ATTACK_COOLDOWN_SECONDS', 4 * 3600);  // 4 hours before same attacker can hit same target

// =============================================================================
// ESPIONAGE
// =============================================================================
define('ESPIONAGE_SPEED', 20);   // cases per hour ($vitesseEspionnage)
define('ESPIONAGE_SUCCESS_RATIO', 0.5); // need > half defender's neutrinos

// =============================================================================
// NEUTRINOS
// =============================================================================
define('NEUTRINO_COST', 50);     // energy per neutrino ($coutNeutrino)

// =============================================================================
// MARKET
// =============================================================================
define('MARKET_VOLATILITY_FACTOR', 0.5); // $volatilite = 0.3 / nbActifs
define('MARKET_PRICE_FLOOR', 0.1);       // minimum price any resource can reach
define('MARKET_PRICE_CEILING', 10.0);    // maximum price any resource can reach
define('MARKET_MEAN_REVERSION', 0.01);   // 1% pull toward baseline price per trade
define('MERCHANT_SPEED', 20);    // cases per hour ($vitesseMarchands)

// Market trading points: contribute to totalPoints via trade volume
// Points awarded = floor(MARKET_POINTS_SCALE * sqrt(totalTradeVolume))
// Boosted: scale 0.05→0.08, cap 40→80 to reward active traders
define('MARKET_POINTS_SCALE', 0.08);       // sqrt scaling for energy spent on market buys
define('MARKET_POINTS_MAX', 80);           // cap on market points contribution to totalPoints

// =============================================================================
// ALLIANCE / DUPLICATEUR
// =============================================================================
// Duplicateur cost: round(BASE * pow(FACTOR, level + 1))
define('DUPLICATEUR_BASE_COST', 10);
define('DUPLICATEUR_COST_FACTOR', 2.5);

// Duplicateur resource bonus: bonusDuplicateur = level / 100 (i.e. 1% per level)
define('DUPLICATEUR_BONUS_PER_LEVEL', 0.01); // 1% per level for resource production

// Alliance tag constraints
define('ALLIANCE_TAG_MIN_LENGTH', 3);
define('ALLIANCE_TAG_MAX_LENGTH', 16);

// =============================================================================
// MOLECULE CLASS COST
// =============================================================================
// coutClasse = pow(numero + 1, 4) -- reduced from 6 for accessible class unlocks
define('CLASS_COST_EXPONENT', 4);
define('CLASS_COST_OFFSET', 1); // pow(numero + CLASS_COST_OFFSET, CLASS_COST_EXPONENT)

// =============================================================================
// VICTORY POINTS - PLAYER RANKINGS
// =============================================================================
// Points awarded per ranking position at end of round
$VICTORY_POINTS_PLAYER = [
    1  => 100,
    2  => 80,
    3  => 70,
    // Ranks 4-10:  70 - (rank - 3) * 5  => 65, 60, 55, 50, 45, 40, 35
    // Ranks 11-20: 35 - (rank - 10) * 2 => 33, 31, 29, 27, 25, 23, 21, 19, 17, 15
    // Ranks 21-50: floor(15 - (rank - 20) * 0.5)
    // Ranks 51-100: max(1, floor(15 - (rank - 20) * 0.15))
    // Ranks 101+:   0
];
define('VP_PLAYER_RANK1', 100);
define('VP_PLAYER_RANK2', 80);
define('VP_PLAYER_RANK3', 70);
define('VP_PLAYER_RANK4_10_BASE', 70);
define('VP_PLAYER_RANK4_10_STEP', 5);
define('VP_PLAYER_RANK11_20_BASE', 35);
define('VP_PLAYER_RANK11_20_STEP', 2);
define('VP_PLAYER_RANK21_50_BASE', 15);
define('VP_PLAYER_RANK21_50_STEP', 0.5);

// =============================================================================
// VICTORY POINTS - ALLIANCE RANKINGS
// =============================================================================
$VICTORY_POINTS_ALLIANCE = [
    1 => 15,
    2 => 10,
    3 => 7,
    // Ranks 4-9: 10 - rank
    // Ranks 10+: 0
];
define('VP_ALLIANCE_RANK1', 15);
define('VP_ALLIANCE_RANK2', 10);
define('VP_ALLIANCE_RANK3', 7);

// =============================================================================
// PILLAGE POINTS FORMULA
// =============================================================================
// pointsPillage = tanh(nbRessources / DIVISOR) * MULTIPLIER
// Boosted: divisor 100k→50k, multiplier 50→80 to reward raiders
define('PILLAGE_POINTS_DIVISOR', 50000);
define('PILLAGE_POINTS_MULTIPLIER', 80);

// =============================================================================
// MEDALS / TIERS
// =============================================================================
// Medal tier names
$MEDAL_TIER_NAMES = ['Bronze', 'Argent', 'Or', 'Emeraude', 'Saphir', 'Rubis', 'Diamant', 'Diamant Rouge'];
$MEDAL_TIER_IMAGES = [
    'medaillebronze.png', 'medailleargent.png', 'medailleor.png', 'emeraude.png',
    'saphir.png', 'rubis.png', 'diamant.png', 'diamantrouge.png'
];

// Medal bonus percentages per tier: Bronze(1%), Silver(3%), Gold(6%), ...
$MEDAL_BONUSES = [1, 3, 6, 10, 15, 20, 30, 50];

// Forum badge names per tier
$MEDAL_FORUM_BADGES = [
    'insigne bronze', 'insigne argent', 'insigne or', 'insigne emeraude',
    'insigne saphir', 'insigne rubis', 'insigne diamant', 'insigne diamant rouge'
];

// =============================================================================
// MEDAL THRESHOLDS (paliers) - values needed to reach each tier
// =============================================================================
// Terreur (number of attacks launched)
$MEDAL_THRESHOLDS_TERREUR = [5, 15, 30, 60, 120, 250, 500, 1000];

// Attaque (total attack points)
$MEDAL_THRESHOLDS_ATTAQUE = [100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000];

// Defense (total defense points)
$MEDAL_THRESHOLDS_DEFENSE = [100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000];

// Pillage (total resources pillaged)
$MEDAL_THRESHOLDS_PILLAGE = [1000, 10000, 50000, 200000, 1000000, 5000000, 20000000, 100000000];

// Pipelette (forum messages posted)
$MEDAL_THRESHOLDS_PIPELETTE = [10, 25, 50, 100, 200, 500, 1000, 5000];

// Pertes (molecules lost)
$MEDAL_THRESHOLDS_PERTES = [10, 100, 500, 2000, 10000, 50000, 200000, 1000000];

// Energievore (energy spent on constructions)
$MEDAL_THRESHOLDS_ENERGIEVORE = [100, 500, 3000, 20000, 100000, 2000000, 10000000, 1000000000];

// Constructeur (highest building level)
$MEDAL_THRESHOLDS_CONSTRUCTEUR = [5, 10, 15, 25, 35, 50, 70, 100];

// Bombe (number of buildings destroyed? unclear)
$MEDAL_THRESHOLDS_BOMBE = [1, 2, 3, 4, 5, 6, 8, 12];

// Troll
$MEDAL_THRESHOLDS_TROLL = [0, 1, 2, 3, 4, 5, 6, 7];

// =============================================================================
// REGISTRATION / NEW PLAYER
// =============================================================================
// Random element distribution probabilities (out of 200 total)
// 0: 1-100 (50%), 1: 101-150 (25%), 2: 151-175 (12.5%), 3: 176-187 (6%),
// 4: 188-193 (3%), 5: 194-197 (2%), 6: 198-199 (1%), 7: 200 (0.5%)
define('REGISTRATION_RANDOM_MAX', 200);
$REGISTRATION_ELEMENT_THRESHOLDS = [100, 150, 175, 187, 193, 197, 199, 200];

// =============================================================================
// LIEUR BONUS FORMULA
// =============================================================================
// bonusLieur = floor(100 * pow(LIEUR_GROWTH_BASE, level)) / 100
define('LIEUR_GROWTH_BASE', 1.07);

// =============================================================================
// ACTIVE PLAYER THRESHOLD
// =============================================================================
// A player is considered "active" if last connection was within this many seconds
define('ACTIVE_PLAYER_THRESHOLD', SECONDS_PER_MONTH); // 2678400 = 31 days
