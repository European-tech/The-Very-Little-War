# Pass 7 Audit — BUILDINGS Domain
**Date:** 2026-03-08
**Agent:** Pass7-B5-BUILDINGS

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 2 |
| MEDIUM | 1 |
| LOW | 0 |
| **Total** | **4** |

---

## CRITICAL Findings

### CRITICAL-001 — Dynamic SQL column interpolation in augmenterBatiment/diminuerBatiment
**File:** `includes/player.php:637, 639, 674, 747, 749`
**Description:** `augmenterBatiment()` and `diminuerBatiment()` validate the building name via `in_array()` whitelist (line 599-602), then directly interpolate `$nom` and the computed HP column name `$vieCol` into SQL strings:
```php
dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", 'ids', ...);
```
While the whitelist mitigates immediate SQLi, the pattern violates defense-in-depth and is fragile: any bypass of the `in_array` check (e.g., via reference aliasing or future code changes) would create a SQL injection vector.
**Fix:** Use an explicit mapping array to construct safe column identifiers:
```php
$columnMap = [
    'generateur' => 'generateur', 'producteur' => 'producteur', 'depot' => 'depot',
    'champdeforce' => 'champdeforce', 'ionisateur' => 'ionisateur',
    'condenseur' => 'condenseur', 'lieur' => 'lieur',
    'stabilisateur' => 'stabilisateur', 'coffrefort' => 'coffrefort'
];
if (!isset($columnMap[$nom])) {
    logError("augmenterBatiment: invalid building $nom"); return;
}
$safeCol = $columnMap[$nom];
$safeVieCol = 'vie' . ucfirst($safeCol);
$sql = "UPDATE constructions SET $safeCol=?, $safeVieCol=? WHERE login=?";
dbExecute($base, $sql, 'ids', ($batiments[$nom] + 1), $vieVal, $joueur);
```

---

## HIGH Findings

### HIGH-001 — Queue availability check outside transaction (TOCTOU on UI)
**File:** `constructions.php:172-174`
**Description:** The initial queue count check (`SELECT count(*) FROM actionsconstruction WHERE login=?`) is done outside the `withTransaction()` block at line 285. Two concurrent requests can both read `count=1` and both proceed to the form. The transaction-level re-check at line 285-287 with `FOR UPDATE` prevents actual double-queueing, but the UI is inconsistent (user sees "slot available" but gets rejected).
**Current mitigation:** Transaction-level FOR UPDATE guard at line 285 is correct and sufficient to prevent data corruption.
**Fix:** Accept the minor UI stale-read as acceptable (transaction catches it), and add a code comment explaining the two-layer pattern:
```php
// Pre-check (stale read) — only for UI display; real enforcement is inside withTransaction() below
```

### HIGH-002 — Completed actions SELECT without FOR UPDATE (theoretical TOCTOU)
**File:** `includes/game_actions.php:28`
**Description:** `SELECT * FROM actionsconstruction WHERE login=? AND fin<?` fetches completed actions without `FOR UPDATE`. Two concurrent requests can both read the same action ID. The CAS DELETE at line 33 (`$affected = dbExecute(...DELETE...); if ($affected === 0)`) prevents double-processing. The pattern is functionally correct but relies entirely on the CAS guard.
**Current mitigation:** CAS guard (DELETE with affected-rows check) prevents double-application.
**Fix:** Add a code comment explaining the CAS pattern; no code change strictly required.

---

## MEDIUM Findings

### MEDIUM-001 — Inefficient COUNT(*) on queue check
**File:** `constructions.php:172, 285`
**Description:** `SELECT count(*) FROM actionsconstruction WHERE login=?` counts all rows when only a 2-row existence check is needed. On high-traffic servers, this scans unnecessarily.
**Fix:**
```sql
SELECT 1 FROM actionsconstruction WHERE login=? LIMIT 2 FOR UPDATE
```
If the result has 2 or more rows, queue is full.

---

## Verified Clean

- **CSRF:** `csrfCheck()` called on all POST paths in constructions.php — clean.
- **Building type whitelist:** `in_array($nom, $allowedBuildings)` in both `augmenterBatiment` and `diminuerBatiment` — clean.
- **Resource deduction atomicity:** Entire build scheduling wrapped in `withTransaction()`; resources re-fetched with `FOR UPDATE` inside tx; balance rechecked before deduction — clean.
- **Level bounds (upper):** `MAX_BUILDING_LEVEL = 50` from config; checked at constructions.php:321 and player.php:617 — clean.
- **Level bounds (lower):** Combat calls check `> 1` before `diminuerBatiment()`; function itself checks `> 1` before decrement — clean. Buildings cannot drop below level 1.
- **Double-queue prevention:** Same building cannot be queued twice (line 341-344 + FOR UPDATE re-check) — clean.
- **Build completion CAS:** DELETE + affected-rows check prevents double-application of completed builds — clean.
- **Config usage:** All construction costs/times use `BUILDING_CONFIG` array; `MAX_BUILDING_LEVEL` from config — no magic numbers — clean.
- **Auth/ownership:** `basicprivatephp.php` included; all queries use `WHERE login=?` with `$_SESSION['login']` — clean.
