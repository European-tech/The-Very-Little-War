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
define('BEGINNER_PROTECTION_SECONDS', 3 * SECONDS_PER_DAY); // V4: 3 days
define('ABSENCE_REPORT_THRESHOLD_HOURS', 6); // hours offline before loss report
define('ONLINE_TIMEOUT_SECONDS', 300);   // 60 * 5 = 5 minutes for online status
define('VICTORY_POINTS_TOTAL', 1000);    // $nbPointsVictoire

// --- V4 ECONOMIC GROWTH BASES ---
define('ECO_GROWTH_BASE', 1.15); // Standard building cost/storage growth
define('ECO_GROWTH_ADV', 1.20);  // Strategic buildings (champdeforce, ionisateur, condenseur, lieur)
define('ECO_GROWTH_ULT', 1.25);  // Stabilisateur (strong exponential)

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

// V4 Covalent synergy: universal condenseur modifier
define('COVALENT_CONDENSEUR_DIVISOR', 50); // modCond = 1 + (nivCond / 50)
define('COVALENT_BASE_EXPONENT', 1.2);     // pow(atom, 1.2) + atom
define('COVALENT_SYNERGY_DIVISOR', 100);   // (1 + secondary / 100)
define('MOLECULE_MIN_HP', 10);             // Min HP — prevents 0-brome insta-wipe

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

// Pillage (soufre): round(((0.1 * soufre)^2 + soufre / 2) * (1 + niveau / 50))
// BAL-CROSS C1: Divisor 3→2 bridges S/O efficiency gap from 56-67% to 67-83%
define('PILLAGE_ATOM_COEFFICIENT', 0.1);
define('PILLAGE_SOUFRE_DIVISOR', 2);
define('PILLAGE_LEVEL_DIVISOR', 50);

// Iode energy production: round((0.003 * iode^2 + 0.04 * iode) * (1 + niveau / 50))
// BAL-CROSS C2: Added quadratic term to match structural class of other atoms
// At I=100: 34 E/mol (was 10). Makes iode a genuine energy strategy.
define('IODE_ENERGY_COEFFICIENT', 0.04);
define('IODE_QUADRATIC_COEFFICIENT', 0.003);
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
define('STABILISATEUR_ASYMPTOTE', 0.98);  // V4: pow(0.98, level) — never negative
define('DECAY_MASS_EXPONENT', 1.5);       // V4: power 1.5 (was 2)

// =============================================================================
// BUILDING HP FORMULAS
// =============================================================================
// pointsDeVie = round(BASE * (pow(1.2, level) + pow(level, 1.2)))
define('BUILDING_HP_BASE', 50);
define('BUILDING_HP_GROWTH_BASE', 1.2);
define('BUILDING_HP_LEVEL_EXP', 1.2);
define('BUILDING_HP_POLY_EXP', 2.5);      // V4: polynomial 50 * level^2.5

// vieChampDeForce = round(BASE * (pow(1.2, level) + pow(level, 1.2)))
define('FORCEFIELD_HP_BASE', 125);
define('FORCEFIELD_HP_GROWTH_BASE', 1.2);
define('FORCEFIELD_HP_LEVEL_EXP', 1.2);

// --- V4 STORAGE / VAULT / ECONOMY / COMBAT ---
define('BASE_STORAGE_INITIAL', 1000);
define('VAULT_PCT_PER_LEVEL', 0.02);
define('VAULT_MAX_PROTECTION_PCT', 0.50);
define('MARKET_GLOBAL_ECONOMY_DIVISOR', 10000);
define('COMBAT_MASS_DIVISOR', 100);
define('IODE_CATALYST_DIVISOR', 50000);
define('IODE_CATALYST_MAX_BONUS', 1.0);
define('LIEUR_LINEAR_BONUS_PER_LEVEL', 0.15);

// =============================================================================
// BUILDING COST FORMULAS
// Cost: round((1 - medalBonus/100) * BASE_COST * pow(level, COST_EXP))
// Time: round(BASE_TIME * pow(level, TIME_EXP)) seconds
// =============================================================================
$BUILDING_CONFIG = [
    'generateur' => [
        'cost_energy_base'  => 50,
        'cost_atoms_base'   => 75,   // cost per atom type
        'cost_growth_base'  => ECO_GROWTH_BASE, // V4: 1.15 exponential growth
        'time_base'         => 60,   // seconds (level 1 = 10s special case)
        'time_growth_base'  => 1.10, // V4: universal time growth
        'time_level1'       => 10,   // special case: level 1 construction time
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'description'       => 'Generates energy',
    ],
    'producteur' => [
        'cost_energy_base'  => 75,
        'cost_atoms_base'   => 50,
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 40,
        'time_growth_base'  => 1.10,
        'time_level1'       => 10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'points_per_level'  => 8,    // sizeof($nomsRes) = 8 points to distribute
        'description'       => 'Produces atoms (drains energy)',
    ],
    'depot' => [
        'cost_energy_base'  => 100,
        'cost_atoms_base'   => 0,    // no atom cost
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 80,
        'time_growth_base'  => 1.10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'description'       => 'Increases max resource storage',
    ],
    'champdeforce' => [
        'cost_carbone_base' => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV, // V4: strategic building
        'time_base'         => 20,
        'time_growth_base'  => 1.10,
        'time_level_offset' => 2,    // pow(level + 2, exp)
        'points_base'       => 1,
        'points_level_factor' => 0.075,
        'bonus_per_level'   => 2,    // +2% defense per level
        'description'       => 'Provides defense bonus, absorbs damage first if highest level',
    ],
    'ionisateur' => [
        'cost_oxygene_base' => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 20,
        'time_growth_base'  => 1.10,
        'time_level_offset' => 2,    // pow(level + 2, exp)
        'points_base'       => 1,
        'points_level_factor' => 0.075,
        'bonus_per_level'   => 2,    // +2% attack per level
        'description'       => 'Provides attack bonus',
    ],
    'condenseur' => [
        'cost_energy_base'  => 25,
        'cost_atoms_base'   => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 120,
        'time_growth_base'  => 1.10,
        'time_level_offset' => 1,    // pow(level + 1, exp)
        'points_base'       => 2,
        'points_level_factor' => 0.1,
        'points_per_level'  => 5,    // condenseur points per level
        'description'       => 'Improves atom effectiveness via levels',
    ],
    'lieur' => [
        'cost_azote_base'   => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 100,
        'time_growth_base'  => 1.10,
        'time_level_offset' => 1,    // pow(level + 1, exp)
        'points_base'       => 2,
        'points_level_factor' => 0.1,
        'lieur_growth_base' => 1.07, // bonusLieur = floor(100 * pow(1.07, level)) / 100
        'description'       => 'Reduces molecule formation time',
    ],
    'stabilisateur' => [
        'cost_atoms_base'   => 75,
        'cost_growth_base'  => ECO_GROWTH_ULT, // V4: strong exponential
        'time_base'         => 120,
        'time_growth_base'  => 1.10,
        'time_level_offset' => 1,    // pow(level + 1, exp)
        'points_base'       => 3,
        'points_level_factor' => 0.1,
        'stability_per_level' => 1.5, // 1.5% decay reduction per level (matches STABILISATEUR_BONUS_PER_LEVEL)
        'description'       => 'Reduces molecule decay rate',
    ],
    'coffrefort' => [
        'cost_energy_base'  => 150,
        'cost_atoms_base'   => 0,
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 90,
        'time_growth_base'  => 1.10,
        'time_level_offset' => 1,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'protection_per_level' => 100, // protects 100 * level of each resource from pillage
        'description'       => 'Protects resources from pillage',
    ],
];

define('VAULT_PROTECTION_PER_LEVEL', 100); // resources protected per vault level

// =============================================================================
// COMBAT FORMULAS
// =============================================================================
// Attack energy cost: 0.15 * (1 - terreur_medal_bonus / 100) * nbAtomes per molecule
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
define('ATTACK_COOLDOWN_SECONDS', 4 * SECONDS_PER_HOUR);  // 4 hours on loss/draw
// BAL-CROSS C4: Prevents chain-attack bullying — winners wait 1h before same target
define('ATTACK_COOLDOWN_WIN_SECONDS', 1 * SECONDS_PER_HOUR);

// Defensive formations — pre-battle defensive stance choices
// 0 = Dispersée (default): damage split equally across all classes (25% each)
// 1 = Phalange: class 1 absorbs 70% of damage, gets +30% defense
// 2 = Embuscade: if defender has more total molecules, +25% attack bonus
define('FORMATION_DISPERSEE', 0);
define('FORMATION_PHALANGE', 1);
define('FORMATION_EMBUSCADE', 2);
// BAL-CROSS C7: Reduces empty-class-1 exploit (60% free damage discard, not 70%)
define('FORMATION_PHALANX_ABSORB', 0.60);        // class 1 absorbs this % of damage
define('FORMATION_PHALANX_DEFENSE_BONUS', 0.20);  // +20% defense for phalanx class 1
define('FORMATION_AMBUSH_ATTACK_BONUS', 0.15);    // +15% attack when outnumbering attacker
$FORMATIONS = [
    FORMATION_DISPERSEE => ['name' => 'Dispersée', 'desc' => 'Les dégâts sont répartis également entre vos 4 classes (25% chacune). Efficace contre les attaques concentrées.'],
    FORMATION_PHALANGE => ['name' => 'Phalange', 'desc' => 'Votre classe 1 absorbe 60% des dégâts et gagne +20% de défense. Idéal si votre classe 1 est très résistante.'],
    FORMATION_EMBUSCADE => ['name' => 'Embuscade', 'desc' => 'Si vous avez plus de molécules que l\'attaquant, vous gagnez +15% d\'attaque. Idéal pour les armées nombreuses.'],
];

// =============================================================================
// ISOTOPE VARIANTS — molecule specializations chosen at creation
// =============================================================================
define('ISOTOPE_NORMAL', 0);
define('ISOTOPE_STABLE', 1);     // Tank: -10% attack, +20% HP, -30% decay
define('ISOTOPE_REACTIF', 2);    // Glass cannon: +20% attack, -10% HP, +50% decay
define('ISOTOPE_CATALYTIQUE', 3); // Support: -10% attack, -10% HP, +15% to other classes

define('ISOTOPE_STABLE_ATTACK_MOD', -0.10);
define('ISOTOPE_STABLE_HP_MOD', 0.20);
define('ISOTOPE_STABLE_DECAY_MOD', -0.30);      // negative = slower decay
define('ISOTOPE_REACTIF_ATTACK_MOD', 0.20);
define('ISOTOPE_REACTIF_HP_MOD', -0.10);
// BAL-CROSS C9: Reduced from 0.50→0.20 so Réactif lifetime output matches Normal
define('ISOTOPE_REACTIF_DECAY_MOD', 0.20);       // positive = faster decay
define('ISOTOPE_CATALYTIQUE_ATTACK_MOD', -0.10);
define('ISOTOPE_CATALYTIQUE_HP_MOD', -0.10);
define('ISOTOPE_CATALYTIQUE_ALLY_BONUS', 0.15);  // +15% to all stats of other classes

$ISOTOPES = [
    ISOTOPE_NORMAL => ['name' => 'Normal', 'desc' => 'Pas de modification.'],
    ISOTOPE_STABLE => ['name' => 'Stable', 'desc' => '-10% attaque, +20% points de vie, -30% vitesse de disparition. Rôle : tank/défenseur.'],
    ISOTOPE_REACTIF => ['name' => 'Réactif', 'desc' => '+20% attaque, -10% points de vie, +20% vitesse de disparition. Rôle : canon de verre.'],
    ISOTOPE_CATALYTIQUE => ['name' => 'Catalytique', 'desc' => '-10% attaque et PV, mais +15% à toutes les stats des AUTRES classes. Rôle : support.'],
];

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
define('MARKET_VOLATILITY_FACTOR', 0.3); // $volatilite = 0.3 / nbActifs
define('MARKET_PRICE_FLOOR', 0.1);       // minimum price any resource can reach
define('MARKET_PRICE_CEILING', 10.0);    // maximum price any resource can reach
define('MARKET_SELL_TAX_RATE', 0.95);    // 95% value returned on sell (5% fee)
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
define('DUPLICATEUR_BASE_COST', 100);
define('DUPLICATEUR_COST_FACTOR', 1.5);

// Duplicateur resource bonus: bonusDuplicateur = level / 100 (i.e. 1% per level)
define('DUPLICATEUR_BONUS_PER_LEVEL', 0.01); // 1% per level for resource production

// BAL-CROSS C8: Duplicateur cost reduced so level 10-12 is achievable in a 31-day season

// Alliance research level cap
define('ALLIANCE_RESEARCH_MAX_LEVEL', 25);

// Alliance tag constraints
define('ALLIANCE_TAG_MIN_LENGTH', 3);
define('ALLIANCE_TAG_MAX_LENGTH', 16);

// Alliance Research Tree — 5 technologies alongside Duplicateur
// Cost: round(BASE_COST * pow(COST_FACTOR, level + 1))
$ALLIANCE_RESEARCH = [
    'catalyseur' => [
        'name' => 'Catalyseur',
        'desc' => 'Réduit le temps de formation des molécules de 2% par niveau.',
        'icon' => 'images/molecule/temps.png',
        'effect_per_level' => 0.02,   // -2% formation time per level
        'effect_type' => 'formation_speed',
        'cost_base' => 15,
        'cost_factor' => 2.0,
    ],
    'fortification' => [
        'name' => 'Fortification',
        'desc' => 'Augmente les points de vie des bâtiments de 1% par niveau.',
        'icon' => 'images/batiments/shield.png',
        'effect_per_level' => 0.01,   // +1% building HP per level
        'effect_type' => 'building_hp',
        'cost_base' => 15,
        'cost_factor' => 2.0,
    ],
    'reseau' => [
        'name' => 'Réseau',
        'desc' => 'Augmente les points de commerce gagnés de 5% par niveau.',
        'icon' => 'images/marche/achat.png',
        'effect_per_level' => 0.05,   // +5% trade points per level
        'effect_type' => 'trade_points',
        'cost_base' => 12,
        'cost_factor' => 1.8,
    ],
    'radar' => [
        'name' => 'Radar',
        'desc' => 'Réduit le coût en neutrinos pour l\'espionnage de 2% par niveau.',
        'icon' => 'images/rapports/espionnage.png',
        'effect_per_level' => 0.02,   // -2% neutrino cost per level
        'effect_type' => 'espionage_cost',
        'cost_base' => 20,
        'cost_factor' => 2.5,
    ],
    'bouclier' => [
        'name' => 'Bouclier',
        'desc' => 'Réduit les pertes de pillage en défense de 1% par niveau.',
        'icon' => 'images/molecule/shield.png',
        'effect_per_level' => 0.01,   // -1% pillage losses per level
        'effect_type' => 'pillage_defense',
        'cost_base' => 15,
        'cost_factor' => 2.0,
    ],
];

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
// BAL-CROSS C10: Smoother VP curve — ranks 21-100 have meaningful differentiation
define('VP_PLAYER_RANK21_50_BASE', 12);
define('VP_PLAYER_RANK21_50_STEP', 0.23);
define('VP_PLAYER_RANK51_100_BASE', 6);
define('VP_PLAYER_RANK51_100_STEP', 0.08);

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

// BAL-CROSS C6: Cap on cross-season medal bonuses to reduce veteran snowball
// During first 14 days of a new season, bonuses are capped at Gold tier (6%)
define('MAX_CROSS_SEASON_MEDAL_BONUS', 10); // absolute % cap (Emeraude tier)
define('MEDAL_GRACE_PERIOD_DAYS', 14);      // first N days: use grace cap
define('MEDAL_GRACE_CAP_TIER', 3);          // max tier index during grace (Gold = 6%)

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
// PRESTIGE SYSTEM
// =============================================================================
define('PRESTIGE_PRODUCTION_BONUS', 1.05);    // +5% resource production
define('PRESTIGE_COMBAT_BONUS', 1.05);        // +5% combat stats
define('PRESTIGE_PP_ACTIVE_FINAL_WEEK', 5);   // PP for logging in during final week
define('PRESTIGE_PP_ATTACK_THRESHOLD', 10);   // Min attacks for attack activity bonus
define('PRESTIGE_PP_ATTACK_BONUS', 5);        // PP for reaching attack threshold
define('PRESTIGE_PP_TRADE_THRESHOLD', 20);    // Min trade volume for trade activity bonus
define('PRESTIGE_PP_TRADE_BONUS', 3);         // PP for reaching trade threshold
define('PRESTIGE_PP_DONATION_BONUS', 2);      // PP for donating energy
// Rank bonuses (awarded by final leaderboard position)
$PRESTIGE_RANK_BONUSES = [
    5  => 50,  // Top 5
    10 => 30,  // Top 10
    25 => 20,  // Top 25
    50 => 10,  // Top 50
];

// =============================================================================
// SESSION & SECURITY
// =============================================================================
define('SESSION_IDLE_TIMEOUT', SECONDS_PER_HOUR);        // 3600s = 1 hour
define('SESSION_REGEN_INTERVAL', 1800);                   // 30 minutes
define('ONLINE_UPDATE_THROTTLE_SECONDS', 60);             // Throttle online status updates
define('SEASON_MAINTENANCE_PAUSE_SECONDS', SECONDS_PER_DAY); // 24h between season phases
define('VISITOR_SESSION_CLEANUP_SECONDS', 3 * SECONDS_PER_HOUR); // 3 hours

// =============================================================================
// RATE LIMITING
// =============================================================================
define('RATE_LIMIT_LOGIN_MAX', 10);           // Max login attempts per window
define('RATE_LIMIT_LOGIN_WINDOW', 300);       // 5 minutes
define('RATE_LIMIT_REGISTER_MAX', 3);         // Max registrations per window
define('RATE_LIMIT_REGISTER_WINDOW', SECONDS_PER_HOUR); // 1 hour

// =============================================================================
// USER INPUT VALIDATION
// =============================================================================
define('PASSWORD_MIN_LENGTH', 8);
define('LOGIN_MIN_LENGTH', 3);
define('LOGIN_MAX_LENGTH', 20);

// =============================================================================
// ACCOUNT / VACATION / PROFILE
// =============================================================================
define('VACATION_MIN_ADVANCE_SECONDS', 3 * SECONDS_PER_DAY); // 3 days notice
define('PROFILE_IMAGE_MAX_SIZE_BYTES', 2000000);  // 2MB
define('PROFILE_IMAGE_MAX_DIMENSION_PX', 150);

// =============================================================================
// MAP DISPLAY
// =============================================================================
define('MAP_TILE_SIZE_PX', 80);
$MAP_ICON_DIVISORS = [16, 8, 4, 2]; // Fractions of VICTORY_POINTS_TOTAL for icon sizes

// =============================================================================
// TUTORIAL
// =============================================================================
define('TUTORIAL_STARTER_MOLECULE_TOTAL_ATOMS', 1000);

// =============================================================================
// LEADERBOARD / ARCHIVES / PAGINATION
// =============================================================================
define('LEADERBOARD_PAGE_SIZE', 20);
define('SEASON_ARCHIVE_TOP_N', 20);
define('MESSAGES_PER_PAGE', 15);
define('REPORTS_PER_PAGE', 15);
define('FORUM_POSTS_PER_PAGE', 10);

// =============================================================================
// ADMIN RATE LIMITS
// =============================================================================
define('RATE_LIMIT_ADMIN_MAX', 5);
define('RATE_LIMIT_ADMIN_WINDOW', 300);

// =============================================================================
// MARKET DISPLAY
// =============================================================================
define('MARKET_HISTORY_LIMIT', 1000);

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

// =============================================================================
// ATOM SPECIALIZATIONS — irreversible choices unlocked at building milestones
// =============================================================================
// 0 = not chosen, 1 = option A, 2 = option B
$SPECIALIZATIONS = [
    'combat' => [
        'column' => 'spec_combat',
        'unlock_building' => 'ionisateur',
        'unlock_level' => 15,
        'options' => [
            1 => [
                'name' => 'Oxydant',
                'desc' => '+10% attaque, -5% défense',
                'icon' => 'images/molecule/sword.png',
                'modifiers' => ['attack' => 0.10, 'defense' => -0.05],
            ],
            2 => [
                'name' => 'Réducteur',
                'desc' => '+10% défense, -5% attaque',
                'icon' => 'images/molecule/shield.png',
                'modifiers' => ['defense' => 0.10, 'attack' => -0.05],
            ],
        ],
    ],
    'economy' => [
        'column' => 'spec_economy',
        'unlock_building' => 'producteur',
        'unlock_level' => 20,
        'options' => [
            1 => [
                'name' => 'Industriel',
                'desc' => '+20% production d\'atomes, -10% production d\'énergie',
                'icon' => 'images/atom.png',
                'modifiers' => ['atom_production' => 0.20, 'energy_production' => -0.10],
            ],
            2 => [
                'name' => 'Énergétique',
                'desc' => '+20% production d\'énergie, -10% production d\'atomes',
                'icon' => 'images/energie.png',
                'modifiers' => ['energy_production' => 0.20, 'atom_production' => -0.10],
            ],
        ],
    ],
    'research' => [
        'column' => 'spec_research',
        'unlock_building' => 'condenseur',
        'unlock_level' => 15,
        'options' => [
            1 => [
                'name' => 'Théorique',
                'desc' => '+2 points condenseur/niveau, -20% vitesse de formation',
                'icon' => 'images/molecule/temps.png',
                'modifiers' => ['condenseur_points' => 2, 'formation_speed' => -0.20],
            ],
            2 => [
                'name' => 'Appliqué',
                'desc' => '+20% vitesse de formation, -1 point condenseur/niveau',
                'icon' => 'images/molecule/vitesse.png',
                'modifiers' => ['formation_speed' => 0.20, 'condenseur_points' => -1],
            ],
        ],
    ],
];
