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
$tokenRow = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$tokenRow || empty($_SESSION['session_token']) || empty($tokenRow['session_token']) || !hash_equals($tokenRow['session_token'], $_SESSION['session_token'])) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['error' => 'Session invalid']);
    exit;
}

require_once('includes/rate_limiter.php');

// Rate limit: 60 API calls per 60 seconds per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rateLimitCheck($ip, 'api', 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    exit;
}

include("includes/constantesBase.php");
include("includes/fonctions.php");

// Force joueur to current session — never trust client-supplied player identity
$joueur = $_SESSION['login'];

// Sanitize numeric params with intval (safe for formula calculations)
$nombre       = isset($_GET['nombre'])       ? intval($_GET['nombre'])       : 0;
$niveau       = isset($_GET['niveau'])       ? intval($_GET['niveau'])       : 0;
$nbTotalAtomes = isset($_GET['nbTotalAtomes']) ? intval($_GET['nbTotalAtomes']) : 0;

// Whitelist dispatch table: 'id' value => callable returning the result
$dispatch = [
    'attaque' => function() use ($nombre, $niveau, $joueur) {
        return attaque($nombre, $niveau, $joueur);
    },
    'defense' => function() use ($nombre, $niveau, $joueur) {
        return defense($nombre, $niveau, $joueur);
    },
    'pointsDeVieMolecule' => function() use ($nombre, $niveau) {
        return pointsDeVieMolecule($nombre, $niveau);
    },
    'potentielDestruction' => function() use ($nombre, $niveau) {
        return potentielDestruction($nombre, $niveau);
    },
    'pillage' => function() use ($nombre, $niveau, $joueur) {
        return pillage($nombre, $niveau, $joueur);
    },
    'productionEnergieMolecule' => function() use ($nombre, $niveau) {
        return productionEnergieMolecule($nombre, $niveau);
    },
    'vitesse' => function() use ($nombre, $niveau) {
        return vitesse($nombre, $niveau);
    },
    'tempsFormation' => function() use ($nombre, $niveau, $nbTotalAtomes, $joueur) {
        return affichageTemps(tempsFormation($nombre, $niveau, $nbTotalAtomes, $joueur), true);
    },
    'demiVie' => function() use ($joueur, $nbTotalAtomes) {
        return affichageTemps(demiVie($joueur, $nbTotalAtomes, 1));
    },
];

// Validate 'id' against the whitelist dispatch table
$id = isset($_GET['id']) ? $_GET['id'] : '';

if (!isset($dispatch[$id])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Execute the dispatched handler and return JSON
echo json_encode(['valeur' => $dispatch[$id]()]);
