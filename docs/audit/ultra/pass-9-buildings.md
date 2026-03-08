# Pass-9 Building System Audit

**Date:** 2026-03-08
**Scope:** Building construction and upgrade system
**Files reviewed:**
- `constructions.php` (POST handlers, display logic)
- `includes/game_actions.php` (`updateActions`, construction completion)
- `includes/player.php` (`augmenterBatiment`, `diminuerBatiment`, `initPlayer`, `listeConstructions`)
- `includes/combat.php` (building damage, floor checks)
- `includes/config.php` (`MAX_BUILDING_LEVEL`, `$BUILDING_CONFIG`)
- `includes/catalyst.php` (`catalystEffect`)
- `includes/basicprivatephp.php` (auth guard)

---

## Audit Domain: Building Construction and Upgrade System

---

### BLDG-P9-001

**ID:** BLDG-P9-001
**Severity:** INFO
**File:** `constructions.php:311`, `includes/player.php:610`
**Domain:** Max-level enforcement

**Description:**
Max-level enforcement is correctly implemented server-side at two independent points.

In `traitementConstructions()` (constructions.php:303–312), inside the transaction with a locked resource row, the code fetches the highest queued level for the building from `actionsconstruction` and falls back to the current `constructions` table level. If `newNiveau = niveauActuel['niveau'] + 1 > MAX_BUILDING_LEVEL` (50), the operation is rejected before any resource deduction occurs.

In `augmenterBatiment()` (player.php:610), inside its own transaction with `FOR UPDATE`, the same cap is re-checked against the current DB value: `if (($batiments[$nom] ?? 0) >= MAX_BUILDING_LEVEL) return;`.

The constant `MAX_BUILDING_LEVEL = 50` is defined in `config.php:51` and referenced by both checks — no magic numbers.

**Status:** CLEAN. Defense-in-depth with dual enforcement.

---

### BLDG-P9-002

**ID:** BLDG-P9-002
**Severity:** INFO
**File:** `constructions.php:283–344`
**Domain:** Transaction safety — resource deduction and level increment atomicity

**Description:**
The full construction enqueue path is wrapped in `withTransaction()` (constructions.php:283). Inside the transaction the code:

1. Re-checks slot count with `SELECT count(*) ... FOR UPDATE` (line 285)
2. Locks the resource row with `SELECT * FROM ressources WHERE login=? FOR UPDATE` (line 290)
3. Re-validates resource sufficiency against locked values (lines 294–301)
4. Checks max level (lines 303–312)
5. Deducts resources with `UPDATE ressources SET ...` (line 328)
6. Inserts the construction queue entry (line 338)
7. Updates `energieDepensee` (line 341)

All state mutations occur within one atomic transaction. Rollback on any failure leaves the database consistent.

In `augmenterBatiment()` (player.php:605–635), the level increment and point award are similarly wrapped atomically.

**Status:** CLEAN. Full ACID atomicity confirmed.

---

### BLDG-P9-003

**ID:** BLDG-P9-003
**Severity:** INFO
**File:** `constructions.php:7`, `constructions.php:51`, `constructions.php:93`, `constructions.php:232`
**Domain:** CSRF protection

**Description:**
All POST handlers in `constructions.php` call `csrfCheck()` as the very first action inside each `if (isset($_POST[...]))` block, before any state is read or modified:

- Line 7: `if (isset($_POST['nbPointshydrogene']))` → `csrfCheck()` on line 7
- Line 51: condenseur points → `csrfCheck()` on line 51
- Line 93: formation change → `csrfCheck()` on line 93
- Line 251: building upgrade (`traitementConstructions`) → `csrfCheck()` on line 252

All forms in the page render use `csrfField()` (constructions.php:232). The CSRF implementation in `includes/csrf.php` uses `hash_equals()` for timing-safe comparison.

**Status:** CLEAN. CSRF is uniformly enforced.

---

### BLDG-P9-004

**ID:** BLDG-P9-004
**Severity:** INFO
**File:** `includes/basicprivatephp.php:19–51`, `constructions.php:2`
**Domain:** Authorization — session validation, cross-player attacks

**Description:**
`constructions.php` includes `includes/basicprivatephp.php` on line 2. That guard:

1. Checks for valid `$_SESSION['login']` and `$_SESSION['session_token']` (line 19)
2. Validates the session token against the DB column `membre.session_token` using `hash_equals()` (line 21) — prevents session fixation
3. Destroys the session and redirects on mismatch
4. Enforces an idle timeout via `SESSION_IDLE_TIMEOUT` (line 13)

All DB mutations in `constructions.php` use `$_SESSION['login']` as the player identifier — never a user-supplied parameter. There is no mechanism for a player to specify another player's login in any building-related POST. `updateActions()` in `game_actions.php` only processes buildings for the player passed as its `$joueur` argument (always `$_SESSION['login']` from the caller).

**Status:** CLEAN. No cross-player authorization bypass possible.

---

### BLDG-P9-005

**ID:** BLDG-P9-005
**Severity:** LOW
**File:** `includes/player.php:630`, `includes/player.php:632`, `includes/player.php:740`, `includes/player.php:742`
**Domain:** SQL injection in building queries via dynamic column name

**Description:**
In both `augmenterBatiment()` and `diminuerBatiment()`, the `$nom` variable is interpolated directly into the SQL string for the column name:

```php
// player.php:630
dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", ...);
// player.php:632
dbExecute($base, "UPDATE constructions SET $nom=? WHERE login=?", ...);
```

The `$nom` parameter is validated against a static whitelist at the top of each function:

```php
static $allowedBuildings = ['generateur', 'producteur', 'depot', 'champdeforce',
                             'ionisateur', 'condenseur', 'lieur', 'stabilisateur', 'coffrefort'];
if (!in_array($nom, $allowedBuildings, true)) {
    error_log("Invalid building name...");
    return;
}
```

The whitelist uses strict comparison (`true` as third argument) and returns early on rejection. `$vieCol` is derived as `'vie' . ucfirst($nom)` from an already-validated `$nom` value within a branch that only executes for the 5 buildings known to have HP columns.

**Assessment:** The whitelist is correct and adequate. However, the pattern of interpolating identifiers into SQL is fragile — it depends on every caller passing validated data and on the whitelist remaining correct if the schema changes.

**Recommended fix:** Add a secondary assertion or use a lookup map from building name to its column name constant, eliminating the possibility that a future refactor removes the whitelist check while leaving the interpolation.

---

### BLDG-P9-006

**ID:** BLDG-P9-006
**Severity:** INFO
**File:** `constructions.php:330–337`, `includes/game_actions.php:28`
**Domain:** Construction time manipulation — bypass of time requirement

**Description:**
Construction time cannot be bypassed by the client. The flow is:

1. On enqueue (constructions.php:336–337), the server computes `$adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')))` and stores `$finTemps = $tempsDebut + $adjustedConstructionTime` in the DB.
2. Completion is processed by `updateActions()` which queries `actionsconstruction WHERE login=? AND fin < ?` using the server-side `time()` (game_actions.php:28). The `fin` column is not exposed to user input; only the server sets it.
3. `catalystEffect('construction_speed')` returns a value from `$CATALYSTS` array (max 0.15 for the Cristallisation catalyst), so `$adjustedConstructionTime` is always at least 85% of the base time and always positive for any valid building.

The client-side countdown in the browser (constructions.php:369–380) redirects to `constructions.php` when it hits zero, triggering a normal page load that calls `updateActions()`. Even if a player spoofs the redirect earlier, `updateActions()` will not process the construction because `fin > time()`.

**Status:** CLEAN. No client-side bypass possible.

---

### BLDG-P9-007

**ID:** BLDG-P9-007
**Severity:** INFO
**File:** `constructions.php:284–287`, `constructions.php:252–256`
**Domain:** Concurrent build — double-construction race condition

**Description:**
The pre-check for 2-slot limit at lines 252–256 is done outside the transaction (a TOCTOU window). However, inside `withTransaction()` the slot count is re-checked with `SELECT count(*) ... FOR UPDATE` (line 285), locking the `actionsconstruction` table rows for that player. If a concurrent request passed the pre-check at the same moment, the inner locked re-check will serialize them, and the second request will see `nb >= 2` and abort cleanly.

In `game_actions.php` (construction completion), the CAS-delete pattern prevents double-processing: `DELETE FROM actionsconstruction WHERE id=?` returns `affected=0` if another request already claimed the row, and the closure throws `already_processed` which is caught and skipped.

**Status:** CLEAN. Concurrent construction correctly serialized.

---

### BLDG-P9-008

**ID:** BLDG-P9-008
**Severity:** INFO
**File:** `includes/player.php:404–560`, `includes/config.php:145–247`
**Domain:** Config values — hardcoded costs vs $BUILDING_CONFIG

**Description:**
All 9 buildings in `listeConstructions` (generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur, coffrefort) compute their costs and construction times exclusively from `$BUILDING_CONFIG` keys:

- `coutEnergie`: `round((1 - ($bonus/100)) * $BUILDING_CONFIG[$key]['cost_energy_base'] * pow($BUILDING_CONFIG[$key]['cost_growth_base'], $niveau))`
- `coutAtomes`, `coutCarbone`, `coutOxygene`, `coutAzote`: same pattern with respective base keys
- `tempsConstruction`: `round($BUILDING_CONFIG[$key]['time_base'] * pow($BUILDING_CONFIG[$key]['time_growth_base'], $niveau + $offset))`
- Special cases for generateur/producteur level-0 → `time_level1` key

No magic numbers appear in the cost/time computation paths.

**Status:** CLEAN. Full config.php coverage confirmed.

---

### BLDG-P9-009

**ID:** BLDG-P9-009
**Severity:** INFO
**File:** `includes/combat.php:528–642`, `includes/player.php:642–751`
**Domain:** Ionisateur damage — damageable building fix implementation

**Description:**
The ionisateur was added to the combat target pool in the weighted building targeting system (combat.php:534–535):

```php
$buildingTargets = array_filter([
    'generateur'   => (int)$constructions['generateur'],
    'champdeforce' => (int)$constructions['champdeforce'],
    'producteur'   => (int)$constructions['producteur'],
    'depot'        => (int)$constructions['depot'],
    'ionisateur'   => (int)$constructions['ionisateur'],  // ← damageable fix
], fn($v) => $v > 0);
```

The `switch` at line 552 handles `'ionisateur'` as a case (line 557), accumulating damage in `$degatsIonisateur`. The subsequent block at lines 628–642 correctly:

1. Checks `if ($degatsIonisateur > 0)`
2. Guards against going below level 1: `if ($constructions['ionisateur'] > 1)` before calling `diminuerBatiment("ionisateur", ...)`
3. Displays `"Niveau minimum"` when level is already 1 (same pattern as other buildings)
4. Applies partial damage to `vieIonisateur` when the building survives

In `diminuerBatiment()` (player.php:681), the floor guard is `if ($batiments[$nom] > 1)` — the entire downgrade block only executes above level 1, ensuring level 0 is unreachable.

**Status:** CLEAN. Ionisateur damageable fix is correctly implemented.

---

### BLDG-P9-010

**ID:** BLDG-P9-010
**Severity:** INFO
**File:** `includes/combat.php:568–642`, `includes/player.php:681`
**Domain:** Building level floor — combat cannot reduce below level 0

**Description:**
For every damageable building (generateur, champdeforce, producteur, depot, ionisateur), the combat.php damage block follows an identical pattern:

```php
if ($degatsXxx >= $constructions['vieXxx']) {
    if ($constructions['xxx'] > 1) {
        diminuerBatiment("xxx", $actions['defenseur']);
        $destructionXxx = "détruit";
    } else {
        $degatsXxx = 0;
        $destructionXxx = "Niveau minimum";
    }
} else {
    // Partial HP damage only
    dbExecute($base, 'UPDATE constructions SET vieXxx=? WHERE login=?', ...);
}
```

When a building is at level 1, `$constructions['xxx'] > 1` is false, so `diminuerBatiment` is never called — damage is silently discarded. Inside `diminuerBatiment()`, the outer guard `if ($batiments[$nom] > 1)` on line 681 provides a second layer: even if called with a level-1 building, the function does nothing.

Buildings not in the target set (condenseur, lieur, stabilisateur, coffrefort) are never targeted and have no HP columns, so they cannot be damaged by combat at all.

**Status:** CLEAN. Double-guarded floor at level 1. Level 0 is unreachable through combat.

---

### BLDG-P9-011

**ID:** BLDG-P9-011
**Severity:** MEDIUM
**File:** `constructions.php:303–307`
**Domain:** Max-level check uses `actionsconstruction` queue level, not the locked `constructions` row

**Description:**
Inside the transaction in `traitementConstructions()`, the max-level check queries the highest queued level from `actionsconstruction` (line 304):

```php
$niveauActuel = dbFetchOne($base, 'SELECT niveau FROM actionsconstruction
    WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $_SESSION['login'], $liste['bdd']);
if (!$niveauActuel) {
    $niveauActuel['niveau'] = $constructions[$liste['bdd']];  // uses outer-scope snapshot
}
```

The problem is the fallback on line 307. When there is no pending queue entry for the building, the code falls back to `$constructions[$liste['bdd']]` — a value captured at request-start by `initPlayer()`, outside the current transaction. If the building's level changed between `initPlayer()` (before the transaction) and the `INSERT` inside the transaction (e.g., because another concurrent request completed a construction and called `augmenterBatiment()` in that window), the fallback level is stale.

In practice this does not allow exceeding `MAX_BUILDING_LEVEL` because `augmenterBatiment()` has its own `FOR UPDATE` re-check. However, it means the queue-level check in `traitementConstructions` may enqueue a build that `augmenterBatiment()` will then silently drop at completion — wasting the player's resources that were already deducted. The resources are deducted in the same transaction, but the build is not actually completed.

**Recommended fix:** Re-read the current building level from the already-locked `ressources` row (or add a `SELECT constructions ... FOR UPDATE` inside the transaction before the max-level check) to use a fully locked value instead of the outer-scope snapshot.

---

### BLDG-P9-012

**ID:** BLDG-P9-012
**Severity:** LOW
**File:** `constructions.php:285`
**Domain:** `FOR UPDATE` on `actionsconstruction` count does not lock the `ressources` row first

**Description:**
Inside `traitementConstructions()`'s transaction, the locking order is:

1. `SELECT count(*) FROM actionsconstruction WHERE login=? FOR UPDATE` (line 285)
2. `SELECT * FROM ressources WHERE login=? FOR UPDATE` (line 290)
3. `SELECT ... FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC` (line 304) — this second query to the same table is executed without `FOR UPDATE`

The absence of `FOR UPDATE` on the line-304 query is not a safety issue because the count-lock acquired at line 285 already prevents other transactions from inserting rows into `actionsconstruction` for this player while the current transaction holds that lock. However, the intent is not immediately obvious and it relies on a MySQL table-level implicit behavior for `COUNT(*)` with `FOR UPDATE`.

**Recommended fix:** Add a comment clarifying that the line-304 query does not need `FOR UPDATE` because the line-285 lock already serializes concurrent queue insertions.

---

### BLDG-P9-013

**ID:** BLDG-P9-013
**Severity:** LOW
**File:** `constructions.php:369`
**Domain:** JavaScript countdown variable XSS via construction ID

**Description:**
In the construction queue display loop, the construction `id` (a database integer) is embedded directly into JavaScript:

```php
echo cspScriptTag() . '
    var valeur' . $actionsconstruction['id'] . ' = ' . ($actionsconstruction['fin'] - time()) . ';
    function tempsDynamique' . $actionsconstruction['id'] . '(){...}
    setInterval(tempsDynamique' . $actionsconstruction['id'] . ', 1000);
    </script>';
```

The `id` column comes from the DB (auto-increment integer). There is no `htmlspecialchars()` or `intval()` cast applied before embedding it in the JS identifier name or the numeric expression. If the DB value were somehow non-integer (e.g., via direct DB manipulation or a future schema change), this could be an XSS vector.

In the current schema `id` is `INT AUTO_INCREMENT`, so the actual value is always an integer. The risk is theoretical under the current schema.

**Recommended fix:** Apply `(int)$actionsconstruction['id']` and `(int)($actionsconstruction['fin'] - time())` before embedding in JS.

---

### BLDG-P9-014

**ID:** BLDG-P9-014
**Severity:** LOW
**File:** `includes/player.php:485`, `includes/combat.php:534`
**Domain:** Ionisateur `progressBar` inconsistency — HP not displayed on buildings page

**Description:**
In `listeConstructions`, the `ionisateur` entry sets `'progressBar' => false` (player.php:485). The `mepConstructions()` display function uses this flag to render the building's HP bar (`progressBar` = true shows `<div>` HP progress overlay on the building image).

Since `ionisateur` was made damageable by the combat fix (it now has a `vieIonisateur` column and can take HP damage), the HP bar should logically be visible to the player so they can see its current health. Without `progressBar => true`, the ionisateur shows no HP indicator on the constructions page, so players cannot see how damaged their ionisateur is.

This is a UX gap, not a security issue, but it is a direct consequence of the ionisateur damageable fix being incomplete on the display side.

**Recommended fix:** Set `'progressBar' => true` for ionisateur in `listeConstructions` and add `'vie' => $constructions['vieIonisateur']` and `'vieMax' => vieIonisateur($constructions['ionisateur'])` keys to the entry (matching the pattern of generateur, producteur, etc.).

---

### BLDG-P9-015

**ID:** BLDG-P9-015
**Severity:** INFO
**File:** `constructions.php:336`
**Domain:** Catalyst construction-speed reduction — negative time guard

**Description:**
The adjusted construction time is computed as:

```php
$adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')));
```

`catalystEffect('construction_speed')` currently returns 0.15 at most (Cristallisation catalyst, config.php/catalyst.php:39). The maximum discount is 15%, so `$adjustedConstructionTime` is always `>= 85% * base_time`, which is always positive for valid buildings (minimum base time is 10 seconds from `time_level1`).

If a future catalyst were added with `construction_speed >= 1.0`, the result would be 0 or negative, causing an immediate-completion or past-scheduled construction. The code has no guard against this.

**Recommended fix:** Add `max(1, ...)` around the adjusted time computation as a safety floor against misconfigured catalysts.

---

### BLDG-P9-016

**ID:** BLDG-P9-016
**Severity:** INFO
**File:** `includes/game_actions.php:32–42`
**Domain:** Double-processing guard for construction completion

**Description:**
`updateActions()` uses a CAS-delete pattern for construction completion:

```php
$affected = dbExecute($base, 'DELETE FROM actionsconstruction WHERE id=?', 'i', $actions['id']);
if ($affected === 0 || $affected === false) {
    throw new \RuntimeException('already_processed');
}
augmenterBatiment($actions['batiment'], $joueur);
```

The `DELETE` is inside `withTransaction()`. If `DELETE` returns 0 (row already claimed by a concurrent process), the exception is caught and the loop `continue`s. `augmenterBatiment()` is only called after the exclusive claim is confirmed.

This correctly prevents double-completion races where the same construction completes on two concurrent page loads.

**Status:** CLEAN. CAS-delete pattern correctly implemented.

---

## Summary Table

| ID | Severity | Issue |
|----|----------|-------|
| BLDG-P9-001 | INFO | Max-level enforcement: dual server-side checks confirmed clean |
| BLDG-P9-002 | INFO | Transaction atomicity: resource deduction + queue insert fully atomic |
| BLDG-P9-003 | INFO | CSRF: uniformly enforced on all POST handlers |
| BLDG-P9-004 | INFO | Authorization: session-token guard + no cross-player vectors |
| BLDG-P9-005 | LOW | SQL column name interpolation: safe via whitelist, fragile pattern |
| BLDG-P9-006 | INFO | Construction time manipulation: server-enforced, no bypass |
| BLDG-P9-007 | INFO | Concurrent build race: FOR UPDATE + CAS-delete guard confirmed clean |
| BLDG-P9-008 | INFO | Config coverage: all 9 buildings use $BUILDING_CONFIG exclusively |
| BLDG-P9-009 | INFO | Ionisateur damageable fix: correctly implemented in combat.php |
| BLDG-P9-010 | INFO | Building level floor: double-guarded, level 0 unreachable via combat |
| BLDG-P9-011 | MEDIUM | Max-level check falls back to stale outer-scope snapshot when queue empty |
| BLDG-P9-012 | LOW | Implicit reliance on count-lock serialization: correct but needs comment |
| BLDG-P9-013 | LOW | JS countdown: DB int not cast before embedding in identifier/expression |
| BLDG-P9-014 | LOW | Ionisateur HP bar not displayed (progressBar=false) despite being damageable |
| BLDG-P9-015 | INFO | Catalyst time discount: no floor guard against future construction_speed>=1.0 |
| BLDG-P9-016 | INFO | Double-processing guard: CAS-delete pattern correctly implemented |

---

FINDINGS: 0 critical, 0 high, 1 medium, 3 low
