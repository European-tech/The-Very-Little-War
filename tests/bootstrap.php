<?php
/**
 * Test bootstrap - sets up environment for testing game functions
 * without needing a real database connection.
 */

// Mock database connection for testing
$base = null;

// Load game constants (config.php is loaded by constantesBase.php)
require_once __DIR__ . '/../includes/constantesBase.php';

// Load validation helpers
require_once __DIR__ . '/../includes/validation.php';

// Load CSRF protection (no DB dependency, uses $_SESSION)
require_once __DIR__ . '/../includes/csrf.php';

// Load rate limiter (file-based, no DB dependency)
// RATE_LIMIT_DIR is guarded with if (!defined()) so tests can override it
require_once __DIR__ . '/../includes/rate_limiter.php';

// Mock session for testing
$_SESSION = [];

// Stub DB-dependent functions needed by formulas.php so we can load it
// These stubs return safe defaults; tests calling DB-dependent paths
// should use their own helpers instead of the real game functions.
if (!function_exists('dbQuery')) {
    function dbQuery($base, $sql, $types = '', ...$params) { return false; }
}
if (!function_exists('dbFetchOne')) {
    function dbFetchOne($base, $sql, $types = '', ...$params) { return []; }
}
if (!function_exists('dbFetchAll')) {
    function dbFetchAll($base, $sql, $types = '', ...$params) { return []; }
}
if (!function_exists('dbExecute')) {
    function dbExecute($base, $sql, $types = '', ...$params) { return false; }
}
if (!function_exists('dbCount')) {
    function dbCount($base, $sql, $types = '', ...$params) { return 0; }
}
if (!function_exists('catalystEffect')) {
    function catalystEffect($effectName) { return 0; }
}
if (!function_exists('allianceResearchBonus')) {
    function allianceResearchBonus($joueur, $effectType) { return 0; }
}
if (!function_exists('prestigeProductionBonus')) {
    function prestigeProductionBonus($login) { return 1.0; }
}
if (!function_exists('hasPrestigeUnlock')) {
    function hasPrestigeUnlock($login, $unlock) { return false; }
}

// Load pure game formula functions (attack, defense, HP, speed, etc.)
require_once __DIR__ . '/../includes/formulas.php';

// Load display helper functions (antiXSS, transformInt, chiffrePetit, etc.)
require_once __DIR__ . '/../includes/display.php';

// Load the game functions that don't depend on database
// (pure computation functions can be tested directly)
