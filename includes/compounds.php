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
 *
 * @note P9-MED-012: Must be called inside a withTransaction() block.
 *       The FOR UPDATE clause in the query is only effective within an active
 *       transaction; calling this outside a transaction yields no locking and
 *       exposes a TOCTOU race between the count read and the subsequent INSERT.
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

            // INTENTIONAL: Compound synthesis does NOT apply catalyst discounts.
            // Unlike buildings, duplicateur, and market, compound costs are fixed by design —
            // compounds are a premium buff system whose cost must remain independent of the
            // weekly catalyst rotation to preserve game balance. (P27-024 design decision)

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
                // ECO11-003: Use $nomsRes (authoritative atom list) minus 'energie' as the whitelist,
                // rather than a hardcoded list that may diverge when new atoms are added.
                // P9-MED-011: Re-check whitelist immediately before column interpolation.
                $allowedCols = array_diff($nomsRes, ['energie']);
                if (!in_array($resource, $allowedCols, true)) {
                    throw new \RuntimeException('Invalid resource: ' . $resource);
                }
                $cost = $qty * COMPOUND_ATOM_MULTIPLIER;
                // MED-054: Use GREATEST(col - ?, 0) to prevent negative balances from
                // a race between the FOR UPDATE read and this UPDATE (belt-and-suspenders).
                // The FOR UPDATE above serializes concurrent synthesis, but GREATEST()
                // adds a hard floor as a final safety net.
                // HIGH-005: Check return value — silent UPDATE failure would grant a free compound.
                $updated = dbExecute($base,
                    "UPDATE ressources SET $resource = GREATEST($resource - ?, 0) WHERE login = ?",
                    'ds', (float)$cost, $login
                );
                if ($updated === false) {
                    throw new \RuntimeException('UPDATE_FAILED:' . $resource);
                }
                // MEDIUM-031 / P29-MED-COMPOUNDS-001: Log diagnostic only when GREATEST() actually clamped
                // (i.e., pre-update balance was less than cost — a race slipped past FOR UPDATE).
                // Post-update zero check was a false-positive when player had exactly $cost of the resource.
                if ((float)($ressources[$resource] ?? 0) < (float)$cost) {
                    logError('COMPOUNDS', 'Compound synthesis: GREATEST clamped to 0 for ' . $resource . ' — race slipped past FOR UPDATE', ['login' => $login]);
                }
            }

            // CRITICAL-004: Check INSERT return — resource loss without compound creation if INSERT fails.
            $result = dbExecute($base,
                'INSERT INTO player_compounds (login, compound_key, created_at) VALUES (?, ?, ?)',
                'ssi', $login, $compoundKey, time() // L-006: set created_at for storage-expiry tracking (migration 0094 adds column)
            );
            if ($result === false) {
                throw new \RuntimeException('INSERT_FAILED');
            }
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
        if (str_starts_with($msg, 'UPDATE_FAILED:')) {
            logError('COMPOUNDS', "synthesizeCompound() UPDATE failed: " . $msg);
            return "Erreur lors de la déduction des ressources.";
        }
        if ($msg === 'INSERT_FAILED') {
            logError('COMPOUNDS', "synthesizeCompound() INSERT failed for login=$login compound=$compoundKey");
            return "Erreur lors de la création du composé.";
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

            // H-016: AND activated_at IS NULL makes the UPDATE itself atomic — if two concurrent
            // requests both pass the FOR UPDATE SELECT, only one can win the UPDATE race.
            // The other will see 0 affected rows and throw COMPOUND_NOT_FOUND.
            $affected = dbExecute($base,
                'UPDATE player_compounds SET activated_at = ?, expires_at = ? WHERE id = ? AND login = ? AND activated_at IS NULL',
                'iiis', $now, $now + $duration, $compoundId, $login
            );
            // COMPOUNDS-MED-001: 0 rows = compound already activated or not found (race condition).
            if ($affected === 0) {
                throw new \RuntimeException('COMPOUND_NOT_FOUND');
            }
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
            // LOW-022: Use ':' separator to avoid collision when login contains '-'
            if (str_starts_with($key, $login . ':')) {
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

    // LOW-023: Use ':' separator to avoid collision when login contains '-'
    $cacheKey = $login . ':' . $effectType;
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
 *
 * TAINT-CROSS MEDIUM-006: Identify which players have expired compounds before deleting,
 * then invalidate only those players' cache entries. This prevents stale bonus values
 * if getCompoundBonus() was called earlier in the same request before GC ran.
 */
function cleanupExpiredCompounds($base)
{
    // Collect affected logins before deletion so we can do targeted cache invalidation.
    // Use the compound's actual expires_at as the threshold (time()) so expired compounds
    // are immediately eligible for GC and re-synthesis. The old time()-SECONDS_PER_DAY
    // threshold caused a 24h extra delay that blocked re-synthesis due to the UNIQUE index
    // on (login, compound_key).
    $threshold = time();
    $affectedRows = dbFetchAll($base,
        'SELECT DISTINCT login FROM player_compounds WHERE activated_at IS NOT NULL AND expires_at < ?',
        'i', $threshold
    );

    dbExecute($base,
        'DELETE FROM player_compounds WHERE activated_at IS NOT NULL AND expires_at < ?',
        'i', $threshold
    );

    // Invalidate only the affected players' cache entries; fall back to full clear if query failed
    if (!empty($affectedRows)) {
        foreach ($affectedRows as $row) {
            if (!empty($row['login'])) {
                invalidateCompoundBonusCache($row['login']);
            }
        }
    } else {
        // No rows affected or query error — clear entire cache to be safe
        invalidateCompoundBonusCache();
    }
}
