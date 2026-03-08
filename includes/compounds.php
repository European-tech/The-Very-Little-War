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
 *
 * @param bool $forUpdate  When true, appends FOR UPDATE to lock rows inside a transaction.
 *                         Use this inside activateCompound() to serialize concurrent activations.
 */
function getActiveCompounds($base, $login, bool $forUpdate = false)
{
    $sql = 'SELECT * FROM player_compounds WHERE login = ? AND activated_at IS NOT NULL AND expires_at > ?';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return dbFetchAll($base, $sql, 'si', $login, time());
}

/**
 * Count stored compounds for a player.
 */
function countStoredCompounds($base, $login)
{
    // NEW-002: FOR UPDATE serializes concurrent synthesis requests so both can't
    // read count=N-1 and both insert, exceeding COMPOUND_MAX_STORED by 1.
    return (int)dbCount($base,
        'SELECT COUNT(*) as cnt FROM player_compounds WHERE login = ? AND activated_at IS NULL FOR UPDATE',
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

    $compound = $COMPOUNDS[$compoundKey];
    $recipe = $compound['recipe'];

    // All checks and deductions inside transaction to prevent TOCTOU race
    try {
        withTransaction($base, function() use ($base, $login, $recipe, $compoundKey, $nomsRes) {
            // Check storage limit inside transaction to prevent TOCTOU race
            $storedCount = countStoredCompounds($base, $login);
            if ($storedCount >= COMPOUND_MAX_STORED) {
                throw new \RuntimeException('STORAGE_FULL');
            }

            // Lock resources row to prevent concurrent double-spend
            $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ? FOR UPDATE', 's', $login);
            if (!$ressources) {
                throw new \RuntimeException('PLAYER_NOT_FOUND');
            }

            foreach ($recipe as $resource => $qty) {
                // MED-056: Validate resource key against $nomsRes whitelist before using
                // as a column name in SQL, to prevent injection via a tampered recipe.
                if (!in_array($resource, $nomsRes, true)) {
                    throw new \RuntimeException('INVALID_RESOURCE:' . $resource);
                }
                $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
                if (!isset($ressources[$resource]) || $ressources[$resource] < $needed) {
                    throw new \RuntimeException('INSUFFICIENT_' . strtoupper($resource) . ':' . $needed . ':' . floor($ressources[$resource] ?? 0));
                }
            }

            foreach ($recipe as $resource => $qty) {
                $cost = $qty * COMPOUND_ATOM_MULTIPLIER;
                // MED-054: Use GREATEST(col - ?, 0) to prevent negative balances from
                // a race between the FOR UPDATE read and this UPDATE (belt-and-suspenders).
                // The FOR UPDATE above serializes concurrent synthesis, but GREATEST()
                // adds a hard floor as a final safety net.
                dbExecute($base,
                    "UPDATE ressources SET $resource = GREATEST($resource - ?, 0) WHERE login = ?",
                    'ds', (float)$cost, $login
                );
            }

            dbExecute($base,
                'INSERT INTO player_compounds (login, compound_key) VALUES (?, ?)',
                'ss', $login, $compoundKey
            );
        });
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'PLAYER_NOT_FOUND') {
            return "Joueur introuvable.";
        }
        if ($msg === 'STORAGE_FULL') {
            return "Stock plein (maximum " . COMPOUND_MAX_STORED . " composés).";
        }
        if (str_starts_with($msg, 'INVALID_RESOURCE:')) {
            // MED-056: Recipe key not in nomsRes whitelist — likely a tampered $COMPOUNDS config
            error_log("synthesizeCompound() blocked: " . $msg);
            return "Recette invalide.";
        }
        if (str_starts_with($msg, 'INSUFFICIENT_')) {
            $parts = explode(':', $msg);
            $resName = strtolower(substr($parts[0], strlen('INSUFFICIENT_')));
            return "Pas assez de " . $resName . " (besoin: " . ($parts[1] ?? '?') . ", disponible: " . ($parts[2] ?? '?') . ").";
        }
        throw $e;
    }

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

    // Wrap in transaction to prevent duplicate activation race (P5-GAP-021)
    try {
        withTransaction($base, function() use ($base, $login, $compoundId, $COMPOUNDS) {
            $compound = dbFetchOne($base,
                'SELECT * FROM player_compounds WHERE id = ? AND login = ? AND activated_at IS NULL FOR UPDATE',
                'is', $compoundId, $login
            );

            if (!$compound) {
                throw new \RuntimeException('COMPOUND_NOT_FOUND');
            }

            $key = $compound['compound_key'];
            if (!isset($COMPOUNDS[$key])) {
                throw new \RuntimeException('COMPOUND_INVALID');
            }

            // Check if same effect type is already active — FOR UPDATE locks rows to serialize concurrent activations
            $activeCompounds = getActiveCompounds($base, $login, true);
            foreach ($activeCompounds as $active) {
                if (isset($COMPOUNDS[$active['compound_key']]) &&
                    $COMPOUNDS[$active['compound_key']]['effect'] === $COMPOUNDS[$key]['effect']) {
                    throw new \RuntimeException('DUPLICATE_EFFECT');
                }
            }

            $now = time();
            $duration = $COMPOUNDS[$key]['duration'];

            dbExecute($base,
                'UPDATE player_compounds SET activated_at = ?, expires_at = ? WHERE id = ?',
                'iii', $now, $now + $duration, $compoundId
            );
        });
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'COMPOUND_NOT_FOUND') return "Composé introuvable ou déjà activé.";
        if ($msg === 'COMPOUND_INVALID') return "Composé invalide.";
        if ($msg === 'DUPLICATE_EFFECT') return "Un composé avec le même effet est déjà actif.";
        throw $e;
    }

    // Invalidate cache so subsequent getCompoundBonus() calls in this request see the new active compound
    invalidateCompoundBonusCache($login);

    return true;
}

// Per-request cache for compound bonuses — use global so it can be invalidated after activation
$_compoundBonusCache = [];

/**
 * Invalidate cached compound bonuses for a player (or all players).
 */
function invalidateCompoundBonusCache($login = null)
{
    global $_compoundBonusCache;
    if ($login === null) {
        $_compoundBonusCache = [];
    } else {
        foreach (array_keys($_compoundBonusCache) as $key) {
            if (str_starts_with($key, $login . '-')) {
                unset($_compoundBonusCache[$key]);
            }
        }
    }
}

/**
 * Get the total active compound bonus for a specific effect type.
 *
 * @param string $effectType  e.g. 'production_boost', 'attack_boost'
 * @return float  Bonus multiplier (e.g. 0.10 for +10%)
 */
function getCompoundBonus($base, $login, $effectType)
{
    global $COMPOUNDS, $_compoundBonusCache;

    $cacheKey = $login . '-' . $effectType;
    if (isset($_compoundBonusCache[$cacheKey])) return $_compoundBonusCache[$cacheKey];

    $activeCompounds = getActiveCompounds($base, $login);
    $totalBonus = 0.0;

    foreach ($activeCompounds as $compound) {
        $key = $compound['compound_key'];
        if (isset($COMPOUNDS[$key]) && $COMPOUNDS[$key]['effect'] === $effectType) {
            $totalBonus += $COMPOUNDS[$key]['effect_value'];
        }
    }

    $_compoundBonusCache[$cacheKey] = $totalBonus;
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
    // Invalidate all cached bonuses since we don't know which players were affected
    invalidateCompoundBonusCache();
}
