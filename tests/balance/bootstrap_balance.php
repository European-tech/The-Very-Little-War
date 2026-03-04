<?php
/**
 * Balance test bootstrap — loads config + formulas only (no DB needed).
 * Pure mathematical verification of game balance.
 */

require_once __DIR__ . '/../../includes/constantesBase.php';
require_once __DIR__ . '/../../includes/formulas.php';
require_once __DIR__ . '/../../includes/display.php';

// Stub DB-dependent functions
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
$_SESSION = [];
