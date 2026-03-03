# TEST-CROSS: Cross-Domain Test Coverage Analysis
**Round 3 Audit — "The Very Little War"**
**Date: 2026-03-03**
**Analyst: test-automator**

---

## Overview

The project has **11 test files** (plus bootstrap), **370 tests**, and **2325 assertions**.
All tests are pure-unit tests (no database) located under `tests/unit/`.

After reading every test file and every function defined in `includes/`, this report
cross-references what is tested, what is not, and where the highest-risk gaps are.

---

## A) TEST COVERAGE MAP

| File | Functions Defined | Functions Tested? | Notes |
|------|-------------------|-------------------|-------|
| `includes/formulas.php` | `pointsVictoireJoueur`, `pointsVictoireAlliance`, `pointsAttaque`, `pointsDefense`, `pointsPillage`, `bonusDuplicateur`, `drainageProducteur`, `attaque`, `defense`, `pointsDeVieMolecule`, `potentielDestruction`, `pillage`, `productionEnergieMolecule`, `vitesse`, `bonusLieur`, `tempsFormation`, `coefDisparition`, `demiVie`, `pointsDeVie`, `vieChampDeForce`, `coutClasse`, `placeDepot` | PARTIAL | 17/22 functions reached via real calls or inline math. `demiVie`, `tempsFormation`, `attaque`, `defense`, `pillage`, `coefDisparition` called in tests via real function but medal bonus path (DB) is never exercised. |
| `includes/csrf.php` | `csrfToken`, `csrfField`, `csrfVerify`, `csrfCheck` | PARTIAL | `csrfToken/Field/Verify` well-tested. `csrfCheck()` (calls `die()`) has ZERO tests. |
| `includes/validation.php` | `validateLogin`, `validateEmail`, `validatePositiveInt`, `validateRange`, `sanitizeOutput` | GOOD | All 5 functions tested across two files (ValidationTest + SecurityFunctionsTest). Some duplication. |
| `includes/rate_limiter.php` | `rateLimitCheck`, `rateLimitRemaining` | GOOD | Both functions thoroughly tested including window expiry, isolation, edge cases. |
| `includes/display.php` | `image`, `imageEnergie`, `imagePoints`, `imageLabel`, `separerZeros`, `couleur`, `couleurFormule`, `chiffrePetit`, `affichageTemps`, `scriptAffichageTemps`, `nombreMolecules`, `nombrePoints`, `nombreAtome`, `nombreNeutrino`, `nombreEnergie`, `nombreTemps`, `nombreTout`, `coutEnergie`, `coutAtome`, `coutTout`, `pref`, `rangForum`, `antihtml`, `antiXSS`, `creerBBcode`, `transformInt` | PARTIAL | Only `antiXSS` and `transformInt` tested (via SecurityFunctionsTest). 24 other display functions have ZERO tests. |
| `includes/logger.php` | `gameLog`, `logInfo`, `logWarn`, `logError`, `logDebug` | NONE | Zero tests for any logging function. |
| `includes/database.php` | `dbQuery`, `dbFetchOne`, `dbFetchAll`, `dbExecute`, `dbLastId`, `dbEscapeLike`, `dbCount`, `withTransaction` | NONE | All DB abstraction layer functions are untested. `withTransaction` is critical (used in combat, prestige). |
| `includes/db_helpers.php` | `ajouter`, `alliance`, `allianceResearchLevel`, `allianceResearchBonus` | NONE | Column whitelist logic in `ajouter()` is untested. Alliance research bonus math is untested. |
| `includes/player.php` | `statut`, `compterActifs`, `inscrire`, `ajouterPoints`, `initPlayer`, `invalidatePlayerCache`, `augmenterBatiment`, `diminuerBatiment`, `coordonneesAleatoires`, `batMax`, `joueur`, `recalculerStatsAlliances`, `supprimerAlliance`, `supprimerJoueur`, `miseAJour`, `remiseAZero` | NONE | All 16 player management functions have ZERO tests. `ajouterPoints` contains complex multi-type branching that was a known bug fix target. `diminuerBatiment` minimum-level guard (audit fix) is untested. `supprimerJoueur` cleanup across 5 tables is untested. |
| `includes/game_resources.php` | `revenuEnergie`, `revenuAtome`, `revenuAtomeJavascript`, `updateRessources` | NONE | All 4 resource-engine functions untested. `updateRessources` is the core tick function. |
| `includes/game_actions.php` | `updateActions` | NONE | The primary game loop — construction processing, formation, attack resolution, espionage, resource transfers — has ZERO tests. |
| `includes/combat.php` | `checkReactions` (one extractable function) + inline combat script | NONE | `checkReactions()` is the only named function; entire combat resolution (formation logic, vault protection, proportional damage, winner determination) is procedural script — fully untested. |
| `includes/prestige.php` | `calculatePrestigePoints`, `awardPrestigePoints`, `getPrestige`, `hasPrestigeUnlock`, `purchasePrestigeUnlock`, `prestigeProductionBonus`, `prestigeCombatBonus`, `isPrestigeLegend` | NONE | 8/8 prestige functions have ZERO tests. `purchasePrestigeUnlock` contains transaction + row-lock logic for double-spend prevention. |
| `includes/catalyst.php` | `getActiveCatalyst`, `catalystEffect` | NONE | Both catalyst functions have ZERO tests. Weekly rotation logic is untested. |
| `includes/update.php` | `updateTargetResources` | NONE | Pre-combat resource synchronization function is untested. |
| `includes/env.php` | `loadEnv` | NONE | Environment loading helper has ZERO tests. |

### Test File Summary

| Test File | Lines | Tests | What It Covers |
|-----------|-------|-------|----------------|
| `CombatFormulasTest.php` | 736 | ~55 | Raw math formulas only (no real function calls except for constant references). Tests inline helpers, not actual `attaque()`, `defense()` functions. |
| `GameFormulasTest.php` | 67 | 4 | Config array structure and constant existence. Minimal behavioral coverage. |
| `MarketFormulasTest.php` | 425 | ~28 | Market price formulas via inline helpers. No actual market transaction functions tested. |
| `ExploitPreventionTest.php` | 442 | ~35 | Calls `pointsDeVieMolecule`, `potentielDestruction`, `vitesse`, `coutClasse`, `placeDepot`, `pointsDeVie`, `vieChampDeForce`, `pointsVictoireJoueur`, `pointsVictoireAlliance`, `bonusDuplicateur`, `bonusLieur`. Good real-function coverage for pure math. |
| `GameBalanceTest.php` | 324 | ~30 | Calls `pointsVictoireJoueur`, `pointsVictoireAlliance`, `bonusDuplicateur`, `bonusLieur`, `drainageProducteur`, `productionEnergieMolecule`, `vitesse`, `coutClasse`, `placeDepot`, `pointsPillage`, `pointsAttaque`, `pointsDefense`. Well-targeted balance tests. |
| `ResourceFormulasTest.php` | 626 | ~45 | Building config structure + inline formula math. Calls no resource-generating functions directly. |
| `ConfigConsistencyTest.php` | 766 | ~55 | Config constants, arrays, medal thresholds, building config keys. High-value regression guard for config. |
| `CombatFormulasTest.php` | 736 | ~55 | Math formulas only, no DB calls. |
| `CsrfTest.php` | 211 | ~20 | `csrfToken`, `csrfField`, `csrfVerify`. Missing `csrfCheck`. |
| `ValidationTest.php` | 163 | ~15 | `validateLogin`, `validateEmail`, `validatePositiveInt`, `validateRange`, `sanitizeOutput`. |
| `SecurityFunctionsTest.php` | 289 | ~25 | `antiXSS`, `transformInt`, `validateLogin`, `validateEmail`, `sanitizeOutput`. Overlaps with ValidationTest. |
| `RateLimiterTest.php` | 289 | ~20 | `rateLimitCheck`, `rateLimitRemaining`. File I/O-based; solid coverage. |

---

## B) PRIORITY TEST GAPS

Ranked by risk (CRITICAL first). Risk = likelihood of real user harm if logic is wrong.

### CRITICAL

**1. `combat.php` — Formation Logic, Proportional Damage, Winner Determination**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
- What is untested: The entire combat resolution script — `FORMATION_PHALANGE` absorb-70% logic, `FORMATION_EMBUSCADE` condition check, `FORMATION_DISPERSEE` active-class split, vault protection per level, attacker-wins pillage proportional share, building damage randomness, CAS-guard double-execution prevention, prestige combat bonus application.
- Risk: A bug here directly changes who wins battles and how many resources are stolen. This is the highest-value game logic.
- Extractability: The damage-share calculation, winner determination, and vault protection math can be extracted to pure functions and tested without DB.
- Specific untested paths:
  - Phalange absorb: class-1 takes 70% of attacker damage; classes 2-4 take 10% each.
  - Embuscade condition: only active when `totalDefenderMols > totalAttackerMols`.
  - 0-HP-molecule exploit: molecules with `brome=0` take all damage from any attack.
  - Vault protection: resources below `VAULT_PROTECTION_PER_LEVEL * vaultLevel` are immune to pillage.
  - Draw/loss attack cooldown: fires on both draw AND loss, not only on defender win.

**2. `player.php` — `ajouterPoints()` branching**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` line 73
- What is untested: The four type branches (type 0–3) each update `totalPoints` with different formulas. Type 1 clamps at 0 (audit fix). Type 3 uses `pointsPillage` (tanh). These were explicitly cited as bug-fix targets in the project history.
- Risk: Wrong `totalPoints` calculation corrupts the leaderboard for every player.
- Extractability: The pure math delta (`-pointsAttaque(old) + pointsAttaque(new)`) can be tested without DB.

**3. `prestige.php` — `purchasePrestigeUnlock()` double-spend prevention**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php` line 143
- What is untested: The transaction + `FOR UPDATE` row lock preventing players from purchasing the same unlock twice via concurrent requests. The PP balance check (`total_pp >= cost`). Error return strings.
- Risk: Race condition allows a player to spend the same PP twice, getting two unlocks for the price of one.
- Extractability: The validation logic (already-owned check, insufficient PP check) can be extracted to a pure function.

**4. `prestige.php` — `calculatePrestigePoints()` PP award logic**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php` line 46
- What is untested: Medal tier counting (counts tiers reached from raw stats), activity bonus (+5 attacks, +3 trade volume, +2 energy given), final-week activity bonus.
- Risk: Incorrect PP awards distort cross-season progression. Players who should have unlocks won't earn them.
- Extractability: The per-stat tier-counting inner loop is pure math and easily extracted.

**5. `player.php` — `diminuerBatiment()` minimum-level guard**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` line 542
- What is untested: The guard that prevents buildings from dropping below level 1. This was an explicit audit fix (FINDING-GAME-004 / HIGH severity): buildings could reach level 0. The `>1` check in `combat.php` delegates to `diminuerBatiment`.
- Risk: A building at level 0 breaks all game mechanics that read building level (energy production, molecule HP, storage).
- Extractability: Not directly extractable without DB mock, but can test the logic as a state machine.

### HIGH

**6. `db_helpers.php` — `ajouter()` column whitelist**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/db_helpers.php` line 7
- What is untested: The static whitelist (`$allowedColumns`) validation that prevents SQL injection via column-name interpolation. Both the allow-path and the block-path (invalid column logs and returns without executing).
- Risk: If the whitelist is wrong or can be bypassed, arbitrary column names reach the SQL string.
- Extractability: The whitelist check is pure PHP array logic, easily extracted and tested.

**7. `db_helpers.php` — `allianceResearchBonus()` double-whitelist**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/db_helpers.php` line 70
- What is untested: The hard-coded `ALLIANCE_RESEARCH_COLUMNS` whitelist (second line of defense) that guards the dynamically-built `SELECT $techName` query. The bonus multiplication formula (`level * effect_per_level`).
- Risk: A missing tech name in `ALLIANCE_RESEARCH_COLUMNS` silently blocks a valid tech; an extra name allows SQL injection.

**8. `game_resources.php` — `updateRessources()` race condition guard**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php` line 105
- What is untested: The CAS (compare-and-swap) guard `UPDATE... WHERE tempsPrecedent=?` that prevents double-resource-generation when concurrent requests trigger the tick simultaneously. The early-return on `nbsecondes < 1`.
- Risk: Two simultaneous page loads can give a player double resources for a time period.
- Extractability: The timing logic (elapsed seconds calculation, cap to depot max) can be tested as pure arithmetic.

**9. `combat.php` — `checkReactions()` function**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` line 132
- What is untested: The chemical reaction detection function that checks all class pairs for atom threshold conditions. The `function_exists` guard prevents re-definition. Reaction stacking (multiple reactions active simultaneously).
- Risk: A bug here silently misses or double-counts reactions, giving incorrect combat bonuses.
- Extractability: `checkReactions()` is a pure function — takes class arrays and returns filled `$activeReactions`. This is the most testable function in the combat file.

**10. `player.php` — `inscrire()` registration transaction**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` line 27
- What is untested: The `withTransaction` wrapping 5 INSERT statements. The random element assignment via `$alea` thresholds. The `password_hash(PASSWORD_DEFAULT)` call. The initial building HP values from `pointsDeVie(1)` and `vieChampDeForce(0)`.
- Risk: A registration failure mid-transaction could leave orphan rows or duplicate rows across tables.
- Extractability: The `$alea` distribution logic (percentages: 50%, 25%, etc.) is pure and testable.

### MEDIUM

**11. `game_actions.php` — `updateActions()` recursion guard**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` line 7
- What is untested: The `static $updating` guard that prevents stack overflow when `updateActions` calls itself recursively (attacker triggers defender update). The formation-delivery counting logic (`floor((time()-lastFormation)/tempsPourUn)`).
- Risk: Without the guard, recursive mutual attacks could cause stack overflow. Incorrect formation counts result in molecule count errors.

**12. `player.php` — `supprimerJoueur()` multi-table cleanup**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` line 752
- What is untested: The cleanup across (at minimum) 5 tables: `membre`, `autre`, `ressources`, `molecules`, `constructions`. The audit (commit a9d8c60) added these — verifying all 5 are cleaned is critical.
- Risk: Orphan rows in any table corrupt joins, leaderboard queries, or alliance stats.

**13. `catalyst.php` — `getActiveCatalyst()` weekly rotation**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/catalyst.php` line 53
- What is untested: The week-ID formula `intval(date('W')) + intval(date('Y')) * 100`. The rotation modulo `$currentWeek % count($CATALYSTS)`. The per-request static cache.
- Risk: A bug in the week formula could cause the catalyst to never rotate, or to rotate every request.
- Extractability: The week-ID and rotation formula are pure arithmetic testable without DB.

**14. `logger.php` — `gameLog()` level filtering**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php` line 16
- What is untested: The `$level < MIN_LOG_LEVEL` early return (DEBUG messages silently discarded in production). File creation logic. Context JSON encoding.
- Risk: Low severity — wrong log level filter means either too many files on disk or missing critical error records.

**15. `display.php` — `affichageTemps()` time formatting**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` line 124
- What is untested: Conversion from raw seconds to human-readable time strings (minutes, hours, days). Edge cases at exactly 60s, 3600s, 86400s boundaries.
- Risk: Incorrect time display misleads players about formation time, attack arrival, construction completion.

**16. `display.php` — `chiffrePetit()` number suffix formatting**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` line 70
- What is untested: The K/M/G suffix compressor (inverse of `transformInt`). Negative number handling.
- Risk: Display-only; corrupted UI values don't corrupt game state but confuse players.

**17. `display.php` — `couleur()` sign-based coloring**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` line 46
- What is untested: Positive numbers get green `+` prefix, negative get red span, zero is plain. The `+` prefix (visual only but affects player decision-making).

**18. `update.php` — `updateTargetResources()`**
- Path: `/home/guortates/TVLW/The-Very-Little-War/includes/update.php` line 11
- What is untested: Pre-combat defender resource sync. Uses raw `revenuenergie` column instead of live `revenuEnergie()` call — this is an architectural inconsistency that could cause resource values to be outdated.

### LOW

**19. `player.php` — `coordonneesAleatoires()` bounded loop**
- What is untested: The infinite-loop fix (audit fix — bounded retry count). The map boundary checks.

**20. `player.php` — `miseAJour()` / `remiseAZero()` season reset**
- What is untested: These orchestrate the full monthly reset. `remiseAZero()` is the most destructive function in the codebase — zeroes all player resources. Zero tests.

**21. `player.php` — `recalculerStatsAlliances()`**
- What is untested: Alliance score recalculation that feeds the alliance leaderboard.

---

## C) TEST QUALITY ISSUES

### Issue 1: Tests that test implementation details, not behavior

**File: `CombatFormulasTest.php`**
**Pattern:** Helper methods (`rawAttack`, `computeAttack`, `computeDefense`, etc.) duplicate the formula inline rather than calling the actual game function.

Example (line 39-44):
```php
private function computeAttack(int $oxygene, int $niveau, int $medalBonus = 0): int
{
    return (int) round(
        $this->rawAttack($oxygene) * (1 + $niveau / 50) * (1 + $medalBonus / 100)
    );
}
```

The actual game function is `attaque($oxygene, $niveau, $joueur, $medalData)` in
`/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php`.

The test helpers and the real function can diverge without any test failing. If someone
changes the formula in `formulas.php` but not in the test helper, the tests will still
pass while the live game uses wrong values.

**Recommendation:** Call the real `attaque()`, `defense()`, `pillage()` functions with
`$medalData = ['bonus' => 0]` (the optional parameter already supports injection) so
tests actually exercise the deployed code path.

---

### Issue 2: Duplicate test coverage across two files

**Files:** `ValidationTest.php` and `SecurityFunctionsTest.php`
**Pattern:** Both files test `validateLogin()`, `validateEmail()`, and `sanitizeOutput()`
with nearly identical inputs.

`ValidationTest.php` line 51-57 and `SecurityFunctionsTest.php` line 199-205 both
test SQL injection patterns against `validateLogin()`. The assertions are identical.
`sanitizeOutput()` is tested in both at lines 137-142 (ValidationTest) and 243-288
(SecurityFunctionsTest).

**Impact:** ~15 duplicate assertions inflate the count without increasing coverage.
Maintenance cost doubles when the function signature changes.

**Recommendation:** Consolidate into one file. `SecurityFunctionsTest.php` is the better
home since it focuses on security properties. `ValidationTest.php` should cover only
`validatePositiveInt()` and `validateRange()` (not already in SecurityFunctionsTest).

---

### Issue 3: Tests pin accidental behavior as intentional

**File: `ExploitPreventionTest.php` line 274-278**
```php
public function testVictoryPointsRank0EdgeCase(): void
{
    $points = pointsVictoireJoueur(0);
    $this->assertEquals(85, $points, 'Rank 0 currently returns 85 (unguarded edge case)');
}
```

This test explicitly documents a bug (rank 0 yields 85 VP via overflow of the rank<=10
branch) and pins it as expected behavior. If someone fixes the bug, this test fails and
appears to be a regression.

**Recommendation:** Either fix the bug and update the test to `assertEquals(0, ...)`, or
annotate with `@group known-bug` and a GitHub issue reference so the "failure" is
understood as a known-bad state, not a regression.

---

### Issue 4: Formula constants verified in tests but not formula behavior

**File: `ConfigConsistencyTest.php` lines 557-568**
```php
public function testAllCoefficientConstants(): void
{
    $this->assertEquals(0.1, ATTACK_ATOM_COEFFICIENT);
    $this->assertEquals(0.1, DEFENSE_ATOM_COEFFICIENT);
    // ...
}
```

These tests verify that constants exist with expected values. They do NOT verify that
the constants are actually used in the formulas. If `attaque()` were changed to
hard-code `0.2` instead of using `ATTACK_ATOM_COEFFICIENT`, every constant test would
still pass.

**Recommendation:** Add integration-style tests that call `attaque()` with known atom
counts and assert the expected output — which implicitly validates the constants are
wired correctly into the formula.

---

### Issue 5: `GameFormulasTest.php` is nearly empty

**File: `GameFormulasTest.php`**
Only 4 tests, all of which test constant existence and array length. The class docblock
says "to be expanded as game formulas are extracted." It has never been expanded.

The file contributes almost nothing beyond what `ConfigConsistencyTest.php` already
covers more thoroughly. At ~67 lines it is the smallest test file in the suite and
overlaps completely with other tests.

**Recommendation:** Either delete this file and redistribute its 4 tests into
`ConfigConsistencyTest.php`, or expand it with the behavior tests it was planned to contain.

---

### Issue 6: `CombatFormulasTest.php` — `testDecayCoefficientMatchesConstants()` uses wrong constants

**File: `CombatFormulasTest.php` lines 499-518**
```php
// With DECAY_BASE=0.99, DECAY_ATOM_DIVISOR=150, DECAY_POWER_DIVISOR=25000,
// STABILISATEUR_BONUS_PER_LEVEL=0.015, nbAtomes=300, stabLevel=5:
$result = pow(
    pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR),
    1 * (1 - $stabLevel * STABILISATEUR_BONUS_PER_LEVEL)
);
$this->assertEqualsWithDelta(0.999996653243761, $result, 0.000000001);
```

The test comment says `DECAY_ATOM_DIVISOR=150, DECAY_POWER_DIVISOR=25000` — these are
the UPDATED values after the balance change. However, the inline formula earlier in the
same file (lines 438-444 in `computeDecayCoefficient`) uses hard-coded `100` and `5000`:
```php
$innerPow = pow(1 + $nbAtomes / 100, 2) / 5000;
```

The helper method uses the OLD constants while the pinned-value test uses the NEW
constants. This means `computeDecayCoefficient()` is wrong relative to the real game
formula, and tests that call it (lines 449-497) are testing stale math.

**Recommendation:** Rewrite the decay coefficient helper to use the actual constants
`DECAY_ATOM_DIVISOR` and `DECAY_POWER_DIVISOR` rather than hard-coded values.

---

### Issue 7: Rate limiter tests have a timing dependency

**File: `RateLimiterTest.php` lines 238-254**
```php
public function testWindowSizeOf1SecondAllowsImmediateReset(): void
{
    // ...
    sleep(2);
    $this->assertTrue(rateLimitCheck($id, $action, $max, 1), ...);
}
```

This test uses `sleep(2)` to wait for a 1-second window to expire. On slow CI runners
this can fail if the process takes more than 1 second between the exhaust and the sleep.
It also adds at least 2 seconds to every test run.

**Recommendation:** Inject a clock interface (or `$now` parameter) into the rate limiter
so the time can be controlled in tests without real sleep. Alternatively, mark this test
`@group slow` so it can be excluded from fast feedback loops.

---

## D) OVERALL COVERAGE ESTIMATE

| Domain | Functions | Tested | Coverage % |
|--------|-----------|--------|------------|
| Combat formulas (math only) | 8 formulas | 8 (inline math) | ~70% (no real function calls) |
| Combat resolution (logic) | 1 script + `checkReactions` | 0 | 0% |
| Market formulas | 4 inline formulas | 4 (inline math) | ~80% (no real function calls) |
| Validation/sanitization | 5 | 5 | 100% |
| CSRF | 4 | 3 | 75% |
| Rate limiting | 2 | 2 | 100% |
| Config constants | ~60 constants | ~60 | ~95% |
| Resource generation | 4 | 0 | 0% |
| Player management | 16 | 0 | 0% |
| Prestige system | 8 | 0 | 0% |
| Catalyst system | 2 | 0 | 0% |
| Display/formatting | 26 | 2 | 8% |
| Logger | 5 | 0 | 0% |
| DB abstraction | 8 | 0 | 0% |
| DB helpers | 4 | 0 | 0% |
| Game actions | 1 | 0 | 0% |
| Update/sync | 1 | 0 | 0% |

**Effective behavioral coverage: ~25-30%**

The 370 tests / 2325 assertions count is inflated by:
1. Config-constant pinning (tests that say "this constant equals 0.1" — not behavior).
2. Duplicate tests across ValidationTest and SecurityFunctionsTest.
3. Formula tests that use inline math helpers instead of calling real functions.

The actual behavioral coverage of game logic (code paths that execute during a real game
session) is closer to 25-30%, with large blank spots in combat resolution, player
management, resource generation, and the prestige system.

---

## E) TOP 5 TESTS TO WRITE NEXT

Listed in order of risk-reduction per test-writing effort.

### 1. `checkReactions()` — Pure Function, Zero Effort

`checkReactions()` is already extracted as a named function in `combat.php` with a
`function_exists` guard. It takes `$classes` array and `$nbClasses` int, returns via
reference `$activeReactions`. No DB needed.

```php
// Minimal test skeleton
public function testCheckReactionsDetectsMatchingPairs(): void
{
    $GLOBALS['CHEMICAL_REACTIONS'] = [
        'TestReaction' => [
            'condA' => ['oxygene' => 50],
            'condB' => ['carbone' => 50],
            'bonus' => ['attack' => 0.1]
        ]
    ];
    $classes = [
        1 => ['oxygene' => 60, 'carbone' => 10],
        2 => ['oxygene' => 10, 'carbone' => 60],
    ];
    $activeReactions = [];
    checkReactions($classes, 2, $activeReactions);
    $this->assertArrayHasKey('TestReaction', $activeReactions);
}
```

### 2. Formation damage-share math — Extract and test

The Phalange/Dispersee/Embuscade distribution logic can be extracted to a pure function
`calculateDamageShares($totalDamage, $formation, $classes, $nbClasses)`. Test all three
formation types plus edge cases (0 active classes in Dispersee, single class).

### 3. `ajouterPoints()` type-branch arithmetic — Extract and test

Extract the totalPoints delta calculation from each type branch into a pure helper.
Test type 1 (attack points clamped at 0), type 3 (tanh-based pillage contribution),
and the totalPoints delta accumulation.

### 4. `ajouter()` column whitelist — Unit test with mock

Load `db_helpers.php` with a mock `$base` and `$nomsRes`. Call `ajouter()` with
both valid and invalid column names. Assert that invalid names trigger `error_log`
and return without executing any SQL.

### 5. `calculatePrestigePoints()` tier-counting — Extract and test

The inner loop `foreach ($thresholds as $t) { if ($value >= $t) $tier++; }` is pure
PHP. Extract it to `countMedalTier($value, $thresholds)` and test against all 8 medal
threshold arrays with known stat values.

---

*Report generated by test-automator agent — Round 3 of comprehensive cross-domain audit.*
