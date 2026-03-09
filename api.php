<?php
/**
 * api.php - JSON API for molecule stat previews (ARCH-010 hardened)
 *
 * Security fixes:
 * 1. Replaced antiXSS() with intval() for numeric params + whitelist dispatch table for 'id'
 * 2. Forced 'joueur' to $_SESSION['login'] — no querying other players' bonuses
 * 3. Added rate limiting (60 requests per 60 seconds per IP)
 * 4. Replaced if/elseif chain with dispatch table
 */

require_once("includes/session_init.php");

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['login'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Validate session token against DB
include("includes/connexion.php");
require_once("includes/database.php");
$tokenRow = dbFetchOne($base, 'SELECT session_token, estExclu FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$tokenRow || empty($_SESSION['session_token']) || empty($tokenRow['session_token']) || !hash_equals($tokenRow['session_token'], $_SESSION['session_token'])) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Session invalid']);
    exit;
}

// TAINT-API MEDIUM-001: Banned players must not access API endpoints.
if ((int)$tokenRow['estExclu'] === 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Compte banni.']);
    exit;
}

require_once('includes/rate_limiter.php');

// Rate limit: 60 API calls per 60 seconds per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// 'api' bucket: formula preview only; market ops are rate-limited in their own pages (marche.php)
if (!rateLimitCheck($ip, 'api', 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    exit;
}

include("includes/constantesBase.php");
include("includes/fonctions.php");

// Force joueur to current session — never trust client-supplied player identity
$joueur = $_SESSION['login'];

// Sanitize and clamp numeric params (API-P9-001: negative values produce NaN in formulas)
$nombre        = max(0, min(MAX_ATOMS_PER_ELEMENT, isset($_GET['nombre'])        ? intval($_GET['nombre'])        : 0));
$nombre2       = max(0, min(MAX_ATOMS_PER_ELEMENT, isset($_GET['nombre2'])       ? intval($_GET['nombre2'])       : 0));
$niveau        = max(0, min(MAX_BUILDING_LEVEL,    isset($_GET['niveau'])        ? intval($_GET['niveau'])        : 0));
// nbTotalAtomes = sum of all atom slots in the molecule (C+N+H+O+Cl+S+Br+I).
// Used by tempsFormation() (training time scales with molecule size) and
// demiVie() (half-life depends on total atom count). Max: 8 atom types × MAX_ATOMS_PER_ELEMENT.
$nbTotalAtomes = max(0, min(8 * MAX_ATOMS_PER_ELEMENT, isset($_GET['nbTotalAtomes']) ? intval($_GET['nbTotalAtomes']) : 0));

// V4: Pre-compute medal bonuses and lieur level for covalent formulas
$medalDataApi = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $joueur);
$bonusAttaqueApi = computeMedalBonus($medalDataApi ? $medalDataApi['pointsAttaque'] : 0, $paliersAttaque, $bonusMedailles);
$bonusDefenseApi = computeMedalBonus($medalDataApi ? $medalDataApi['pointsDefense'] : 0, $paliersDefense, $bonusMedailles);
$bonusPillageApi = computeMedalBonus($medalDataApi ? $medalDataApi['ressourcesPillees'] : 0, $paliersPillage, $bonusMedailles);
$lieurDataApi = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
$nivLieurApi = ($lieurDataApi && isset($lieurDataApi['lieur'])) ? $lieurDataApi['lieur'] : 0;

// IMPORTANT: All handlers in this dispatch table are read-only (formula preview).
// Any future handler that mutates state MUST: (1) verify POST method, (2) call csrfCheck().
// Whitelist dispatch table: 'id' value => callable returning the result
// V4: nombre = primary atom, nombre2 = secondary atom (covalent synergy), niveau = condenseur level
$dispatch = [
    'attaque' => function() use ($nombre, $nombre2, $niveau, $bonusAttaqueApi) {
        return attaque($nombre, $nombre2, $niveau, $bonusAttaqueApi);
    },
    'defense' => function() use ($nombre, $nombre2, $niveau, $bonusDefenseApi) {
        return defense($nombre, $nombre2, $niveau, $bonusDefenseApi);
    },
    'pointsDeVieMolecule' => function() use ($nombre, $nombre2, $niveau) {
        return pointsDeVieMolecule($nombre, $nombre2, $niveau);
    },
    'potentielDestruction' => function() use ($nombre, $nombre2, $niveau) {
        return potentielDestruction($nombre, $nombre2, $niveau);
    },
    'pillage' => function() use ($nombre, $nombre2, $niveau, $bonusPillageApi) {
        return pillage($nombre, $nombre2, $niveau, $bonusPillageApi);
    },
    'productionEnergieMolecule' => function() use ($nombre, $niveau) {
        return productionEnergieMolecule($nombre, $niveau);
    },
    'vitesse' => function() use ($nombre, $nombre2, $niveau) {
        return vitesse($nombre, $nombre2, $niveau);
    },
    'tempsFormation' => function() use ($nombre, $nombre2, $niveau, $nbTotalAtomes, $nivLieurApi, $joueur) {
        return affichageTemps(tempsFormation($nbTotalAtomes, $nombre, $nombre2, $niveau, $nivLieurApi, $joueur), true);
    },
    'demiVie' => function() use ($joueur, $nbTotalAtomes) {
        $hl = demiVie($joueur, $nbTotalAtomes, 1);
        return ($hl >= PHP_INT_MAX) ? 'Infinity' : affichageTemps($hl);
    },
];

// Validate 'id' against the whitelist dispatch table; cap length to prevent log flooding / DoS
$id = isset($_GET['id']) ? substr((string)$_GET['id'], 0, 64) : '';

if (!isset($dispatch[$id])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Execute the dispatched handler and return JSON (API-P9-002: guard NaN/INF encoding failures)
$result = json_encode(['valeur' => $dispatch[$id]()]);
if ($result === false) {
    http_response_code(500);
    error_log('api.php json_encode failed for id=' . $id . ': ' . json_last_error_msg());
    echo json_encode(['error' => 'Encoding error']);
} else {
    echo $result;
}
