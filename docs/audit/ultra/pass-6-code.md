# Pass 6 Code Quality Audit

**Scope:** Deprecated PHP 8.2 patterns, undefined variable usage, logic errors, copy-paste bugs, unreachable code, uncaught exceptions, type errors.
**Date:** 2026-03-08

---

## Findings

---

### CODE-P6-001 [HIGH] `withTransaction()` depth counter goes negative on begin/savepoint failure

**File:** `includes/database.php:124-133`

**Description:** In `withTransaction()`, the error branches for both `SAVEPOINT` failure (line 126) and `mysqli_begin_transaction()` failure (line 131) call `$depth--` before `$depth` has been incremented. At the point of failure, `$depth` equals whatever it was before the call (e.g. 0 for a top-level call). Decrementing it produces `-1`. Subsequent calls to `withTransaction()` will see `$depth > 0` and use savepoints instead of real transactions, silently corrupting all future transactional behavior in the same request.

**Code:**
```php
if ($useSavepoint) {
    if (!mysqli_query($base, "SAVEPOINT $sp")) {
        $depth--;   // BUG: depth not yet incremented; becomes -1
        throw new \RuntimeException('savepoint_failed');
    }
} else {
    if (!mysqli_begin_transaction($base)) {
        $depth--;   // BUG: same
        throw new \RuntimeException('transaction_begin_failed');
    }
}
$depth++;
```

**Fix:** Remove the `$depth--` lines in the failure branches (the exception propagates without needing to unwind a depth that was never incremented).

---

### CODE-P6-002 [HIGH] `echo` inside validation error branch corrupts HTML output in `constructions.php`

**File:** `constructions.php:15` and `constructions.php:59`

**Description:** When a posted resource point value is negative, the code sets `$bool = false` and calls `echo intval(...)`. This raw `echo` fires before the HTML layout (`include basicprivatephp.php` has already started output), injecting a bare integer into the middle of the page body. The `$erreur` variable set at line 46/91 would be the correct way to surface the error; the `echo` call serves no user-visible purpose and produces invalid HTML.

**Code:**
```php
if ($_POST['nbPoints' . $ressource] < 0) {
    $bool = false;
    echo intval($_POST['nbPoints' . $ressource]);  // line 15: corrupts output
}
```

**Fix:** Remove both `echo` statements. The `$erreur = "Le nombre de points n'est pas valide.";` at line 46 already surfaces the rejection.

---

### CODE-P6-003 [MEDIUM] Static caches in `revenuEnergie()`, `revenuAtome()`, `getSpecModifier()`, and `coefDisparition()` are not cleared by `invalidatePlayerCache()`

**Files:**
- `includes/game_resources.php:9` (`revenuEnergie` cache)
- `includes/game_resources.php:93` (`revenuAtome` cache)
- `includes/formulas.php:19` (`getSpecModifier` cache)
- `includes/formulas.php:217` (`coefDisparition` cache)

**Description:** `invalidatePlayerCache()` in `player.php:583` clears `$GLOBALS['_initPlayerCache'][$joueur]`. However, the four functions above use PHP function-level `static $cache = []` variables which are separate from the global cache and are never invalidated. When `augmenterBatiment()` or `diminuerBatiment()` upgrades a building (which calls `invalidatePlayerCache()` then `initPlayer()`), the production functions continue returning stale values for the rest of the request. For example, after a generateur upgrade, `revenuEnergie()` at the same niveau will return the pre-upgrade value from the static cache because the niveau did not change — but the specialization modifier or compound bonus may have changed mid-request. The worst case is `getSpecModifier()`: specialization changes write to `constructions` but the static cache retains old values.

**Fix:** Either add `invalidatePlayerCache()` calls that also reset these static caches (using a sentinel or closure parameter), or replace the per-function static arrays with entries in `$GLOBALS['_initPlayerCache']` so a single invalidation clears all.

---

### CODE-P6-004 [MEDIUM] `catch (RuntimeException ...)` and `catch (Exception ...)` without leading backslash in `attaquer.php` and `marche.php`

**Files:**
- `attaquer.php:244` — `catch (RuntimeException $e)`
- `attaquer.php:251` — `catch (Exception $e)`
- `marche.php:315` — `catch (Exception $e)`
- `marche.php:465` — `catch (Exception $e)`
- `includes/basicprivatephp.php:211` — `catch (Exception $e)`

**Description:** All other catch blocks in this codebase use the FQCN `\RuntimeException` and `\Exception` (with leading backslash). Without the backslash, PHP resolves the class name relative to the current namespace. These files have no `namespace` declaration, so the global namespace is used and the catch works — but it is an inconsistency that would silently break if namespaces are ever added. Additionally, `attaquer.php:244` catching `RuntimeException` (without backslash) is inside a closure where `\RuntimeException` is thrown; while both resolve to the same class in the global namespace today, the inconsistency obscures intent.

**Fix:** Add `\` prefix consistently: `catch (\RuntimeException $e)`, `catch (\Exception $e)`.

---

### CODE-P6-005 [MEDIUM] `sizeof()` used as deprecated alias for `count()` in 8 locations

**Files:**
- `includes/constantesBase.php:19`
- `includes/cardsprivate.php:34, 48, 156`
- `includes/player.php:259`
- `includes/game_actions.php:556, 557, 561, 562, 590, 594`
- `constructions.php:33, 77`
- `marche.php:275, 424`
- `medailles.php:75`

**Description:** `sizeof()` is an alias for `count()` that is not officially deprecated in PHP 8.2, but it is considered poor style, linter noise, and signals code quality debt from the 15-year-old codebase. PHP documentation notes that function aliases "may be removed in future major versions." More importantly it reduces IDE analysis quality.

**Fix:** Replace all `sizeof(...)` with `count(...)` (mechanical rename).

---

### CODE-P6-006 [MEDIUM] Division by zero risk in `armee.php:296,299,315,319` when `tempsPourUn == 0`

**File:** `armee.php:295-319`

**Description:** `$actionsformation['tempsPourUn']` is fetched from the database and used as the divisor in modulo operations (`% $actionsformation['tempsPourUn']`). After `ceil()` at line 295, a value of 0.x rounds up to 1, but a stored value of 0 remains 0. The guard in `game_actions.php:62-65` deletes rows where `tempsPourUn <= 0` during processing — but that guard only fires when `updateActions()` is called. A race condition can produce a view where the row exists (fetched at armee.php display time) but `tempsPourUn` is 0 before `updateActions()` has cleaned it up. PHP raises a `DivisionByZeroError` for `%` with 0, which is fatal (uncaught Throwable).

**Fix:** Add a guard before line 296:
```php
if ($actionsformation['tempsPourUn'] <= 0) { continue; }
```

---

### CODE-P6-007 [LOW] `ajouterPoints()` type=0 calls `mysqli_affected_rows($base)` after `dbExecute()` which already returns affected rows

**File:** `includes/player.php:152-153`

**Description:** `dbExecute()` returns `mysqli_stmt_affected_rows($stmt)` and immediately closes the statement. The caller at line 153 then also calls `mysqli_affected_rows($base)` (connection-level). While the connection-level value persists after statement close in MariaDB (it reflects the last query), relying on this is implementation-dependent behavior and creates a hidden dependency ordering. The `$result` from `dbExecute()` at line 152 is never used — instead the code re-reads it from the connection. This works today but is fragile.

**Code:**
```php
$result = dbExecute($base, 'UPDATE autre SET points = points + ? WHERE login = ? AND points + ? >= 0', 'dsd', $nb, $joueur, $nb);
if (mysqli_affected_rows($base) > 0) {   // should use $result > 0
```

**Fix:** Change to `if ($result !== false && $result > 0) {`.

---

### CODE-P6-008 [LOW] `couleurFormule()` applies HTML injection to unescaped DB-sourced `$formule` strings

**File:** `includes/display.php:57-68`

**Description:** `couleurFormule($formule)` receives molecule formula strings from the database (e.g. `C<sub>6</sub>H<sub>12</sub>O<sub>6</sub>`) and applies `preg_replace` to inject `<span>` tags. The input is never HTML-escaped before the regex, and the function produces HTML output. The `formule` column stores structured chemistry notation that contains literal `<sub>` tags — if this column were ever compromised or hand-edited to contain malicious HTML, `couleurFormule()` would pass it through unescaped to the page. All call sites (`armee.php`, `attaquer.php`, `bilan.php`, `molecule.php`, `game_actions.php`, `game_resources.php`) output the result directly into HTML without further escaping.

The current codebase only inserts formula strings via the molecule creation form in `armee.php:201-210` which constructs `$formule` by concatenating hardcoded letters and `<sub>` tags — no user-controlled data enters the formula. This is a defense-in-depth gap rather than an active vulnerability, but it creates an XSS escalation path if formula generation logic changes.

**Fix:** Document that `couleurFormule()` input must be safe structured chemistry strings. Add an assertion or input validation in `armee.php` that the constructed formula matches `^([A-Z][a-z]?(<sub>[0-9]+</sub>)?)+$` before storing it.

---

### CODE-P6-009 [LOW] Formation type binding `'ds'` for integer `$formed` (floor result)

**File:** `includes/game_actions.php:72, 74, 80, 82`

**Description:** `$formed = floor(...)` at line 70 returns a float (PHP's `floor()` always returns float). It is bound with type string `'ds'` (double, string) at line 72, which is correct for the double bind. However, the paired `UPDATE actionsformation SET nombreRestant = nombreRestant - ?` at line 76 uses `'di'` (double, int). The symmetry inconsistency (`ds` vs `di`) is not a bug today — MariaDB accepts the double — but signals that `$formed` could be `'i'` in both positions since it is always a whole number. At line 80, `$actions['nombreRestant']` is a DB integer, also bound as `'ds'`.

**Fix:** Cast `$formed = (int)floor(...)` and use `'is'` / `'ii'` bind types for clarity.

---

### CODE-P6-010 [INFO] `updateActions()` guard not reset on early-return paths

**File:** `includes/game_actions.php:9-13, 612`

**Description:** The `$updating[$joueur] = true` guard at line 13 prevents recursive re-entry. The guard is cleared via `unset($updating[$joueur])` at line 612 only when the function completes normally. The function has multiple early `return` paths at lines 53, 59, 65 (inside closures, so they only return from the closure). The outermost function can also throw if `withTransaction()` re-throws. If an uncaught exception propagates out of `updateActions()`, `$updating[$joueur]` is never unset and subsequent calls within the same request will silently skip processing for that player. This is unlikely in production but could occur during development or unusual error states.

**Fix:** Wrap the body in a try/finally to guarantee cleanup:
```php
try { /* existing body */ } finally { unset($updating[$joueur]); }
```

---

## Summary

| ID | Severity | Title |
|----|----------|-------|
| CODE-P6-001 | HIGH | `withTransaction()` depth counter goes negative on failure |
| CODE-P6-002 | HIGH | `echo` in validation error corrupts HTML output in constructions.php |
| CODE-P6-003 | MEDIUM | Static caches not cleared by `invalidatePlayerCache()` |
| CODE-P6-004 | MEDIUM | Missing `\` prefix on Exception catch clauses (5 locations) |
| CODE-P6-005 | MEDIUM | `sizeof()` deprecated alias used in 15+ locations |
| CODE-P6-006 | MEDIUM | Division-by-zero risk in armee.php when tempsPourUn == 0 |
| CODE-P6-007 | LOW | `ajouterPoints()` ignores `dbExecute()` return value, re-reads affected rows |
| CODE-P6-008 | LOW | `couleurFormule()` passes unescaped DB strings through preg_replace (defense gap) |
| CODE-P6-009 | LOW | Formation bind types use `'ds'` where `'is'` is more appropriate |
| CODE-P6-010 | INFO | `updateActions()` guard not reset on exception propagation |

**Total: 10 findings — 2 HIGH / 3 MEDIUM / 3 LOW / 1 INFO**
