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
    global $base, $CATALYSTS;

    $stats = dbFetchOne($base, 'SELECT catalyst, catalyst_week FROM statistiques');
    $currentWeek = intval(date('W')) + intval(date('Y')) * 100; // unique week ID

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
    return $catalyst;
}

/**
 * Check if a specific catalyst effect is active. Returns the bonus value or 0.
 */
function catalystEffect($effectName) {
    $catalyst = getActiveCatalyst();
    return $catalyst['effects'][$effectName] ?? 0;
}
