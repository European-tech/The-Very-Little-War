<?php
/**
 * Compound Synthesis — temporary buff system.
 *
 * Players craft compounds from atoms. Each compound provides a timed effect.
 */

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/database.php');

/**
 * Get player's stored (not yet activated) compounds.
 */
function getStoredCompounds($base, $login)
{
    return dbFetchAll($base,
        'SELECT * FROM player_compounds WHERE login = ? AND activated_at IS NULL',
        's', $login
    );
}

/**
 * Get player's active (activated and not expired) compounds.
 */
function getActiveCompounds($base, $login)
{
    return dbFetchAll($base,
        'SELECT * FROM player_compounds WHERE login = ? AND activated_at IS NOT NULL AND expires_at > ?',
        'si', $login, time()
    );
}

/**
 * Count stored compounds for a player.
 */
function countStoredCompounds($base, $login)
{
    return (int)dbCount($base,
        'SELECT COUNT(*) as cnt FROM player_compounds WHERE login = ? AND activated_at IS NULL',
        's', $login
    );
}

/**
 * Synthesize a compound: deduct atoms, create stored compound.
 *
 * @return string|true  Error message string or true on success
 */
function synthesizeCompound($base, $login, $compoundKey)
{
    global $COMPOUNDS, $nomsRes;

    if (!isset($COMPOUNDS[$compoundKey])) {
        return "Composé inconnu.";
    }

    if (countStoredCompounds($base, $login) >= COMPOUND_MAX_STORED) {
        return "Stock plein (maximum " . COMPOUND_MAX_STORED . " composés).";
    }

    $compound = $COMPOUNDS[$compoundKey];
    $recipe = $compound['recipe'];

    // Check resources
    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ?', 's', $login);
    if (!$ressources) {
        return "Joueur introuvable.";
    }

    foreach ($recipe as $resource => $qty) {
        $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
        if (!isset($ressources[$resource]) || $ressources[$resource] < $needed) {
            return "Pas assez de " . $resource . " (besoin: " . $needed . ", disponible: " . floor($ressources[$resource] ?? 0) . ").";
        }
    }

    // Deduct resources and create compound in transaction
    withTransaction($base, function() use ($base, $login, $recipe, $compoundKey) {
        foreach ($recipe as $resource => $qty) {
            $cost = $qty * COMPOUND_ATOM_MULTIPLIER;
            ajouter($resource, 'ressources', -$cost, $login);
        }

        dbExecute($base,
            'INSERT INTO player_compounds (login, compound_key) VALUES (?, ?)',
            'ss', $login, $compoundKey
        );
    });

    return true;
}

/**
 * Activate a stored compound (start the timer).
 *
 * @return string|true  Error message or true on success
 */
function activateCompound($base, $login, $compoundId)
{
    global $COMPOUNDS;

    $compound = dbFetchOne($base,
        'SELECT * FROM player_compounds WHERE id = ? AND login = ? AND activated_at IS NULL',
        'is', $compoundId, $login
    );

    if (!$compound) {
        return "Composé introuvable ou déjà activé.";
    }

    $key = $compound['compound_key'];
    if (!isset($COMPOUNDS[$key])) {
        return "Composé invalide.";
    }

    // Check if same effect type is already active
    $activeCompounds = getActiveCompounds($base, $login);
    foreach ($activeCompounds as $active) {
        if (isset($COMPOUNDS[$active['compound_key']]) &&
            $COMPOUNDS[$active['compound_key']]['effect'] === $COMPOUNDS[$key]['effect']) {
            return "Un composé avec le même effet est déjà actif.";
        }
    }

    $now = time();
    $duration = $COMPOUNDS[$key]['duration'];

    dbExecute($base,
        'UPDATE player_compounds SET activated_at = ?, expires_at = ? WHERE id = ?',
        'iii', $now, $now + $duration, $compoundId
    );

    return true;
}

/**
 * Get the total active compound bonus for a specific effect type.
 *
 * @param string $effectType  e.g. 'production_boost', 'attack_boost'
 * @return float  Bonus multiplier (e.g. 0.10 for +10%)
 */
function getCompoundBonus($base, $login, $effectType)
{
    global $COMPOUNDS;

    static $cache = [];
    $cacheKey = $login . '-' . $effectType;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $activeCompounds = getActiveCompounds($base, $login);
    $totalBonus = 0.0;

    foreach ($activeCompounds as $compound) {
        $key = $compound['compound_key'];
        if (isset($COMPOUNDS[$key]) && $COMPOUNDS[$key]['effect'] === $effectType) {
            $totalBonus += $COMPOUNDS[$key]['effect_value'];
        }
    }

    $cache[$cacheKey] = $totalBonus;
    return $totalBonus;
}

/**
 * Clean up expired compounds (garbage collection).
 */
function cleanupExpiredCompounds($base)
{
    dbExecute($base,
        'DELETE FROM player_compounds WHERE activated_at IS NOT NULL AND expires_at < ?',
        'i', time() - 86400 // keep for 24h after expiry for UI display
    );
}
