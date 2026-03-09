<?php
/**
 * Weekly Catalyst System
 *
 * One catalyst is active per week, providing a global gameplay modifier.
 * Rotates automatically on Monday when any player loads a page.
 */

// Catalyst definitions: ID => effect modifiers
$CATALYSTS = [
    0 => [
        'name' => 'Combustion',
        'desc' => 'Toutes les attaques infligent +10% de dégâts',
        'icon' => 'sword',
        'effects' => ['attack_bonus' => 0.10],
    ],
    1 => [
        'name' => 'Synthèse',
        'desc' => 'Formation de molécules 20% plus rapide',
        'icon' => 'temps',
        'effects' => ['formation_speed' => 0.20],
    ],
    2 => [
        'name' => 'Équilibre',
        'desc' => 'Les prix du marché convergent 50% plus vite vers 1.0',
        'icon' => 'bag',
        'effects' => ['market_convergence' => 0.50],
    ],
    3 => [
        'name' => 'Fusion',
        'desc' => 'Coûts du Duplicateur d\'alliance -25%',
        'icon' => 'alliance',
        'effects' => ['duplicateur_discount' => 0.25],
    ],
    4 => [
        'name' => 'Cristallisation',
        'desc' => 'Temps de construction -15%',
        'icon' => 'museum',
        'effects' => ['construction_speed' => 0.15],
    ],
    5 => [
        'name' => 'Volatilité',
        'desc' => 'Disparition des molécules +30% plus rapide, capacité de pillage +25%',
        'icon' => 'demivie',
        'effects' => ['decay_increase' => 0.30, 'pillage_bonus' => 0.25],
    ],
];

/**
 * Get the current active catalyst. Auto-rotates weekly.
 * Returns the catalyst array with 'id' field added.
 */
function getActiveCatalyst() {
    // FIX FINDING-GAME-034: Cache catalyst per-request to avoid redundant DB queries
    static $cachedCatalyst = null;
    if ($cachedCatalyst !== null) return $cachedCatalyst;

    global $base, $CATALYSTS;

    $stats = dbFetchOne($base, 'SELECT catalyst, catalyst_week FROM statistiques');
    $currentWeek = intval(date('W')) + intval(date('Y')) * 100; // unique week ID

    // IMPORTANT: Weekly catalyst rotation uses (weekNumber % count($CATALYSTS)).
    // Rules to prevent disruption:
    // 1. ALWAYS append new catalysts to the END of the array — never remove mid-season
    // 2. If mid-season change is needed, update the DB with new week->catalyst mapping
    // 3. Adding a catalyst changes ALL future weeks for that season
    // TODO: Consider storing the rotation in DB (game_state table) for flexibility
    if (!$stats || $stats['catalyst_week'] != $currentWeek) {
        // Rotate to a new catalyst
        $newCatalyst = $currentWeek % count($CATALYSTS);
        dbExecute($base, 'UPDATE statistiques SET catalyst=?, catalyst_week=?', 'ii', $newCatalyst, $currentWeek);
        $catalystId = $newCatalyst;
    } else {
        $catalystId = intval($stats['catalyst']);
    }

    $catalyst = $CATALYSTS[$catalystId] ?? $CATALYSTS[0];
    $catalyst['id'] = $catalystId;
    $cachedCatalyst = $catalyst;
    return $cachedCatalyst;
}

/**
 * Check if a specific catalyst effect is active. Returns the bonus value or 0.
 */
function catalystEffect($effectName) {
    // P27-023: Keyed on ISO week so a mid-request cron rotation takes effect immediately
    // rather than serving stale effects for the rest of the request.
    static $cache = null;
    static $cacheWeek = null;
    $currentWeek = date('oW'); // ISO year + week number (e.g. "202612")
    if ($cache === null || $cacheWeek !== $currentWeek) {
        $catalyst = getActiveCatalyst();
        $cache = $catalyst['effects'];
        $cacheWeek = $currentWeek;
    }
    return $cache[$effectName] ?? 0;
}
