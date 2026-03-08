# Pass 7 Audit — COMPOUNDS Domain
**Date:** 2026-03-08
**Agent:** Pass7-B6-COMPOUNDS

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 1 |
| MEDIUM | 2 |
| LOW | 2 |
| **Total** | **6** |

---

## CRITICAL Findings

### CRITICAL-001 — Unique constraint violation not handled — silent resource loss
**File:** `includes/compounds.php:116-119`
**Description:** The `INSERT INTO player_compounds` statement does not check the return value of `dbExecute()`. If the INSERT fails due to the unique constraint on `(login, compound_key)`, the function silently returns `true` (success) while the compound is never created — but resources were already deducted at lines 110-113.

**Exploit path:**
1. Player synthesizes H2O → stored with `activated_at IS NULL`
2. Player activates H2O → `activated_at` set (no longer counted by `countStoredCompounds`)
3. Player attempts to synthesize H2O again:
   - `countStoredCompounds()` returns 0 (counts only `activated_at IS NULL`)
   - Storage check passes
   - Resources deducted (lines 110-113)
   - INSERT fails on unique constraint
   - `dbExecute()` returns false (unchecked)
   - Function returns `true` → UI shows "Composé synthétisé avec succès !"
   - **Resources permanently lost, no compound created**

**Fix:**
```php
$result = dbExecute($base,
    'INSERT INTO player_compounds (login, compound_key) VALUES (?, ?)',
    'ss', $login, $compoundKey
);
if ($result === false) {
    throw new \RuntimeException('INSERT_FAILED');
}
```

---

## HIGH Findings

### HIGH-001 — Resource UPDATE return value not checked
**File:** `includes/compounds.php:110-113`
**Description:** The UPDATE statements for resource deduction don't check return values from `dbExecute()`. A silent failure would leave resources undeducted, granting a free compound.
**Fix:**
```php
$updated = dbExecute($base,
    "UPDATE ressources SET $resource = GREATEST($resource - ?, 0) WHERE login = ?",
    'ds', (float)$cost, $login
);
if ($updated === false) {
    throw new \RuntimeException('UPDATE_FAILED:' . $resource);
}
```

---

## MEDIUM Findings

### MEDIUM-001 — GREATEST() silently floors to 0 on resource race
**File:** `includes/compounds.php:106-113`
**Description:** `GREATEST($resource - ?, 0)` silently sets balance to 0 rather than rejecting if a concurrent request depleted the resource after the initial check. The `FOR UPDATE` lock on `ressources` (line 81) should prevent this race, but the silent floor masks any gap in the locking chain. Consider logging when GREATEST clamps to 0.

### MEDIUM-002 — Expired compounds remain in DB for up to 24h post-expiry
**File:** `laboratoire.php:36-39`, `includes/compounds.php:256`
**Description:** GC runs at ~5% probability per request; DELETE only removes compounds expired > 24h ago (intentional for UI history display). No immediate fix needed, but database bloat over time on active servers.

---

## LOW Findings

### LOW-001 — Cache key separator could theoretically collide
**File:** `includes/compounds.php:232`
**Description:** Cache key `$login . '-' . $effectType` could collide if login contains dash. Change separator to `:`:
```php
$cacheKey = $login . ':' . $effectType;
```
Also update the cache invalidation prefix check at the `str_starts_with` call.

### LOW-002 — Cache invalidation uses string prefix search tied to separator
**File:** `includes/compounds.php:214-216`
**Description:** `str_starts_with($key, $login . '-')` depends on the `-` separator. Update if separator changes per LOW-001.

---

## Verified Clean

- **Auth:** `laboratoire.php` includes `basicprivatephp.php`; all operations keyed to `$_SESSION['login']` — clean.
- **CSRF:** `csrfCheck()` on all POST handlers; forms include `csrfField()` — clean.
- **Transaction safety:** Both `synthesizeCompound()` and `activateCompound()` wrapped in `withTransaction()` with `FOR UPDATE` locks — clean.
- **Stacking prevention:** `activateCompound()` rejects duplicate effect types; unique constraint on `(login, compound_key)` — clean.
- **Expiry validation:** `expires_at > time()` at query time (not cached) — clean.
- **Cache invalidation:** `invalidateCompoundBonusCache()` called after activation; GC clears all caches on cleanup — clean.
- **Input validation:** `compound_key` validated against `$COMPOUNDS` keys; resource names against `$nomsRes` whitelist — clean.
- **SQL injection:** All queries use prepared statements; column names whitelisted before interpolation — clean.
- **Bonus application:** `getCompoundBonus()` correctly applied to production, combat, and trade — clean.
- **Rate limiting:** 5 synthesis per 60 seconds per player — clean.
