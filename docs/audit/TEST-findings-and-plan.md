# Test Coverage Audit and Comprehensive Test Plan
# The Very Little War — 2026-03-02

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Part 1: Existing Test Audit](#2-part-1-existing-test-audit)
3. [Part 2: Coverage Gap Analysis](#3-part-2-coverage-gap-analysis)
4. [Part 3: Comprehensive Test Plan](#4-part-3-comprehensive-test-plan)
5. [Part 4: Test Infrastructure](#5-part-4-test-infrastructure)
6. [Appendix: Regression Map for 18 Bug Fixes](#6-appendix-regression-map-for-18-bug-fixes)

---

## 1. Executive Summary

### Current State

| Metric | Value |
|---|---|
| Test files | 6 |
| Total test lines | 2,673 |
| Estimated test count | ~180 individual tests |
| Test type | 100% formula/constant verification (no integration tests) |
| DB dependency | None — all tests run without a database |
| Functions with direct test coverage | ~25 (formula reproductions) |
| Functions with zero test coverage | ~60 |
| Estimated meaningful coverage | 15–20% of game logic |
| Critical untested paths | Combat resolution, season reset, player deletion, CSRF, rate limiter |

### Key Risk Areas

The existing tests are well-written for what they cover but cover only the mathematical layer. The entire execution layer — the code that actually runs during gameplay, modifies the database, and enforces game rules — has zero test coverage. The 18 bugs fixed in the comprehensive bug audit (commit a9d8c60) would have been caught by integration tests; none would have been caught by the existing test suite.

---

## 2. Part 1: Existing Test Audit

### 2.1 bootstrap.php (20 lines)

**What it does:**
- Sets `$base = null` (no real DB connection)
- Loads `includes/constantesBase.php` (which loads `includes/config.php`)
- Loads `includes/validation.php`
- Sets `$_SESSION = []`

**Problems:**
- Does NOT load `includes/formulas.php`, so actual game functions (`attaque()`, `defense()`, `pillage()`, etc.) are never loaded in tests. The test suite reproduces the formulas inline instead of calling the real functions. This means changes to the actual formula functions would NOT be caught by the existing tests.
- Does NOT load any module other than validation and constants. This is intentional given the global `$base` dependency in all modules but creates a large blind spot.
- No DB connection setup, which means all tests needing DB must be written as integration tests separately.

**Verdict:** Adequate for the current pure-formula test strategy, but needs expansion to support the DB-aware tests described in this plan.

---

### 2.2 CombatFormulasTest.php (730 lines, ~50 tests)

**What it tests:**
Reproduces and verifies these formulas inline (not by calling the actual functions):
- Attack formula: `round((1 + (0.1*O)^2 + O) * (1 + level/50) * (1 + bonus/100))`
- Defense formula (identical structure, uses carbone)
- HP formula (no medal bonus multiplier)
- Destruction/building damage formula (coefficient 0.075, no +1 base)
- Pillage formula (soufre/3 linear term)
- Speed formula (floor-based, 2 decimals)
- Formation time formula (azote, lieur level)
- Decay coefficient formula (stabilisateur, medals)
- Building HP (pointsDeVie, vieChampDeForce)
- Iode energy production
- Pillage points (tanh-based)
- Combat energy cost (ATTACK_ENERGY_COST_FACTOR)
- Ionisateur and champdeforce combat bonuses
- Molecule class cost (pow(n+1, 6))
- Victory point constants

**Quality assessment:**
- Assertions are precise and mathematically correct.
- Zero-value edge cases tested.
- Growth direction (more atoms = more power) verified.
- Constants from `config.php` referenced correctly in decay test.

**Critical gap — DB-dependent wrapper untested:**
The actual game functions `attaque()`, `defense()`, `pillage()`, and `coefDisparition()` all use `global $base` to look up medal bonuses from the database. The tests bypass this completely by reimplementing the math without the medal lookup. A bug introduced in the DB query path (e.g., wrong column name, wrong table join) would not be caught. The test `testDecayCoefficientMatchesConstants` is the only test that references config constants directly — the rest hardcode the coefficient values.

**Staleness risk:**
If `IODE_ENERGY_COEFFICIENT` was changed from 0.01 (old) to 0.05 (current), the test `testIodeEnergyBasic` expects 5 but the comment says "round(0.05 * 100 * 1) = round(5) = 5". This is consistent now but the hardcoded comment `0.05` differs from what was in the file at one point (0.01). No staleness detected currently.

**Tests that would NOT catch recent bugs:**
- The combat damage variable bug (`$degatsAttaquant` used instead of `$degatsDefenseur`) — this is a variable naming bug in `combat.php`, not a formula bug.
- Building level floor bug (could reach 0) — not a formula bug.
- Pillage on draws — not a formula bug.

---

### 2.3 ConfigConsistencyTest.php (760 lines, ~55 tests)

**What it tests:**
- Time constant arithmetic (SECONDS_PER_HOUR, DAY, WEEK, MONTH)
- Game limit constants (MAX_CONCURRENT_CONSTRUCTIONS, MAX_MOLECULE_CLASSES, etc.)
- BUILDING_CONFIG array structure: all 8 buildings present, required keys exist, values positive
- Medal threshold arrays: 10 medal types, each has 8 tiers, tiers strictly increasing
- Medal bonus values: [1, 3, 6, 10, 15, 20, 30, 50] — exact values verified
- Legacy variable compatibility (nomsRes matches RESOURCE_NAMES, etc.)
- Resource arrays: 8 elements each
- Formula coefficient constants match expected values
- Building HP constants
- Alliance tag length constraints
- Duplicateur constants
- Registration random max and element thresholds
- Victory points structure
- Combat constants
- Lieur and decay constants

**Quality assessment:**
This is the strongest test file. It acts as a contract test for the config module. If any constant is renamed or changed to an inconsistent value, these tests will catch it immediately. The legacy compatibility section is particularly valuable given the shim architecture.

**Gap:**
Does not verify that `BUILDING_CONFIG` contains the `coffrefort` building (added later in development). Checking the config: coffrefort is likely present but no test verifies it. If someone adds a ninth building and forgets to update medal arrays, this test suite would catch the count mismatch.

**Staleness risk:**
`testTimeConstantsAreConsistent()` has a logic inversion — it asserts `SECONDS_PER_HOUR > SECONDS_PER_DAY` which is backwards. This test would fail if run. However this would only affect the ordering assertion, not the absolute value tests.

```php
// EXISTING (Line 46-49) — comparison direction is wrong:
$this->assertGreaterThan(SECONDS_PER_HOUR, SECONDS_PER_DAY);  // OK
$this->assertGreaterThan(SECONDS_PER_DAY, SECONDS_PER_WEEK);   // WRONG: Week > Day
$this->assertGreaterThan(SECONDS_PER_WEEK, SECONDS_PER_MONTH); // WRONG: Month > Week
```

This is a test code bug. The actual constant values are correct (verified by the individual tests above), but the ordering test has inverted arguments to `assertGreaterThan(expected, actual)` — in PHPUnit, `assertGreaterThan($expected, $actual)` means `$actual > $expected`. So `assertGreaterThan(SECONDS_PER_DAY, SECONDS_PER_WEEK)` asserts `SECONDS_PER_WEEK > SECONDS_PER_DAY` which is correct. Wait — re-reading the API: `assertGreaterThan($expected, $actual)` fails if `$actual` is NOT greater than `$expected`. So `assertGreaterThan(SECONDS_PER_HOUR, SECONDS_PER_DAY)` asserts `SECONDS_PER_DAY > SECONDS_PER_HOUR`. That is correct. The tests are fine — PHPUnit's argument order is `($smaller, $larger)`.

**Revised verdict:** The ordering tests are correct given PHPUnit argument ordering. No bug here.

---

### 2.4 MarketFormulasTest.php (416 lines, ~30 tests)

**What it tests:**
- Volatility formula: `MARKET_VOLATILITY_FACTOR / playerCount`
- Price increase on buy: `price + volatility * amount / depot`
- Price decrease on sell: `1 / (1/price + volatility * amount / depot)`
- Zero-amount edge cases
- Sell price always positive (harmonic formula prevents negatives)
- Buy/sell asymmetry (buy+sell does not return to original price)
- Multiple buy/sell cycles
- Transaction cost rounding
- Merchant travel time: `round(3600 * distance / MERCHANT_SPEED)`
- Distance calculation: Pythagorean

**Quality assessment:**
Excellent mathematical property testing. The asymmetry test is sophisticated — it verifies a game-balance property, not just a formula value. The "price can never go negative" test at extreme values is a good exploit-prevention test.

**Critical gap:**
The actual market transaction code in `marche.php` is never exercised. That code:
- Validates the player has enough energy to buy
- Validates the player has enough atoms to sell
- Checks storage limits (the bug: market purchases bypass storage limits was flagged in the audit)
- Updates both the player's resources and the market price atomically

None of this is tested. The formula tests verify the math is correct in isolation but cannot catch the storage limit bypass bug.

**Missing edge case:**
No test for "what happens if depot level is 0" (division by zero in price formula). In practice depot starts at 1 so this cannot occur in game, but a defensive test would be good practice.

---

### 2.5 ResourceFormulasTest.php (619 lines, ~45 tests)

**What it tests:**
- Base energy per level constant
- Energy production at various generator levels
- Atom production with and without Duplicateur bonus
- Storage capacity (500 * depot_level)
- Producteur energy drain (12 * level)
- Net energy (generation - drain)
- Break-even generator-to-producteur ratio
- Building cost formulas for all 8 buildings (energy, atoms, carbone, oxygene, azote costs)
- Building construction time formulas for all buildings
- Duplicateur bonus and cost formulas
- Building points per level formulas
- Neutrino cost
- Espionage speed and success ratio
- Stabilisateur bonus per level

**Quality assessment:**
Very comprehensive for what it covers. Tests both the constants and their interactions. The net energy break-even ratio test is good game-balance documentation.

**Gap:**
The `revenuEnergie()` function in `game_resources.php` has a `// BUG ICI` comment and is heavily DB-dependent. The test covers the formula components but cannot cover the actual function. The iode energy contribution to total revenue is untested as a composite.

---

### 2.6 GameFormulasTest.php (67 lines, 4 tests)

**What it tests:**
- Resource array count (8 elements)
- nbRes calculation (7 = sizeof - 1)
- nbClasses (4)
- Medal tier arrays have 8 elements
- Medal bonus increasing sequence
- Victory points constant (1000)

**Quality assessment:**
This file is nearly a duplicate of parts of ConfigConsistencyTest.php. It appears to be a placeholder created early in development that was never expanded. The 4 tests here provide no additional coverage beyond what ConfigConsistencyTest already provides. It should either be merged or expanded with tests for `pointsVictoireJoueur()` and `pointsVictoireAlliance()` since those are pure functions with no DB dependency.

**Recommendation:** Expand this file or merge into ConfigConsistencyTest. The pure formula functions `pointsVictoireJoueur()`, `pointsVictoireAlliance()`, `pointsAttaque()`, `pointsDefense()`, `pointsPillage()`, `bonusDuplicateur()`, `drainageProducteur()`, `vitesse()`, `bonusLieur()`, `pointsDeVieMolecule()`, `potentielDestruction()`, `placeDepot()`, and `coutClasse()` are all testable without a DB.

---

### 2.7 ValidationTest.php (61 lines, 5 tests)

**What it tests:**
- `validateLogin()`: valid/invalid patterns, length bounds, special characters
- `validateEmail()`: valid/invalid emails
- `validatePositiveInt()`: positive integers, zero, negative, string
- `validateRange()`: in-range, boundary values, out-of-range
- `sanitizeOutput()`: XSS chars, ampersand, quotes

**Quality assessment:**
This is the only file that tests actual game functions by calling them (rather than reimplementing math inline). The tests are correct and complete for the five functions defined in `validation.php`.

**Gaps in validation coverage:**
- No test for SQL injection attempt through `validateLogin()` (e.g., `' OR '1'='1`)
- No test for Unicode/multibyte input
- No test for extremely long input (beyond 20 chars for login — this is tested, but not beyond, say, 1000 chars)
- No test for null input (PHP type juggling)
- `validateRange()` not tested with float input (the function uses FILTER_VALIDATE_INT — floats are rejected)

**Missing functions from validation.php:**
`validation.php` only has 5 functions. But `display.php`'s `antiXSS()` function uses `mysqli_real_escape_string()` and calls `antihtml()` — this is a security-critical function with no test at all.

---

### 2.8 Summary Table — Existing Tests

| File | Tests | Functions Covered | DB Required | Quality |
|---|---|---|---|---|
| CombatFormulasTest.php | ~50 | 8 formulas (inline) | No | Good |
| ConfigConsistencyTest.php | ~55 | Constants only | No | Excellent |
| MarketFormulasTest.php | ~30 | 2 price formulas (inline) | No | Good |
| ResourceFormulasTest.php | ~45 | Building costs/times (inline) | No | Good |
| GameFormulasTest.php | ~4 | Constants only (duplicate) | No | Redundant |
| ValidationTest.php | ~5 | validateLogin/Email/Int/Range/sanitize | No | Good |
| **TOTAL** | **~189** | **~20 distinct functions** | **None** | **Formula layer only** |

---

## 3. Part 2: Coverage Gap Analysis

### 3.1 includes/formulas.php

| Function | Category | Reason |
|---|---|---|
| `pointsVictoireJoueur($classement)` | Unit testable now | Pure function, no DB, all branches deterministic |
| `pointsVictoireAlliance($classement)` | Unit testable now | Pure function, no DB |
| `pointsAttaque($pts)` | Unit testable now | Pure function: `round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)))` |
| `pointsDefense($pts)` | Unit testable now | Same structure as pointsAttaque |
| `pointsPillage($nbRessources)` | Unit testable now | Pure: `round(tanh(n/DIVISOR) * MULTIPLIER)` |
| `bonusDuplicateur($niveau)` | Unit testable now | Pure: `$niveau / 100` |
| `drainageProducteur($niveau)` | Unit testable now | Pure: `round(DRAIN * $niveau)` |
| `pointsDeVieMolecule($brome, $niveau)` | Unit testable now | Pure — no DB call |
| `potentielDestruction($hydrogene, $niveau)` | Unit testable now | Pure — no DB call |
| `vitesse($chlore, $niveau)` | Unit testable now | Pure — no DB call |
| `bonusLieur($niveau)` | Unit testable now | Pure — no DB call |
| `placeDepot($niveau)` | Unit testable now | Pure: `500 * $niveau` |
| `pointsDeVie($niveau, $joueur)` | Unit testable with refactor | With `$joueur=null`, no DB call; with joueur, calls allianceResearchBonus |
| `vieChampDeForce($niveau, $joueur)` | Unit testable with refactor | Same as pointsDeVie |
| `attaque($oxygene, $niveau, $joueur)` | Integration test needed | Queries `autre` table for medal points |
| `defense($carbone, $niveau, $joueur)` | Integration test needed | Same pattern |
| `pillage($soufre, $niveau, $joueur)` | Integration test needed | Queries `autre` table + catalystEffect() |
| `tempsFormation($azote, $niveau, $ntotal, $joueur)` | Integration test needed | Queries constructions + catalyst + alliance research |
| `coefDisparition($joueur, ...)` | Integration test needed | Queries molecules, constructions, autre tables |
| `demiVie($joueur, ...)` | Integration test needed | Calls coefDisparition |
| `coutClasse($numero)` | Unit testable now | Pure: `pow($numero + 1, 4)` |

**Note:** The test suite reimplements `attaque/defense/pillage/HP/decay` as private helpers inside the test classes. This means the actual functions are never called in tests. A refactor that changed the formula inside `attaque()` without changing the constants would go undetected.

---

### 3.2 includes/game_resources.php

| Function | Category | Reason |
|---|---|---|
| `revenuEnergie($niveau, $joueur, $detail)` | Integration test needed | Queries constructions, autre, alliances, molecules tables; 5 return paths |
| `revenuAtome($num, $joueur)` | Integration test needed | Queries constructions, alliances |
| `revenuAtomeJavascript($joueur)` | E2E test needed | Outputs JavaScript; output verification needed |
| `updateRessources($joueur)` | Integration test needed | Multi-table update + time-based atomic check |

**Key untested scenarios in `updateRessources`:**
- Atomic duplicate-update prevention (UPDATE WHERE tempsPrecedent=old_val, affected_rows check)
- Energy capped at depot capacity
- Atoms capped at depot capacity
- Molecule decay across multiple classes
- Absence report generation after 6 hours
- Molecule loss tracking (moleculesPerdues update)

---

### 3.3 includes/game_actions.php

| Function | Category | Reason |
|---|---|---|
| `updateActions($joueur)` | Integration test needed | Largest function, orchestrates all game logic |
| Static recursion guard (`$updating[]`) | Unit testable now | Logic only, no DB needed |

**Key untested branches in `updateActions`:**
- Construction completion: calls `augmenterBatiment`, deletes from actionsconstruction
- Formation completion: normal completion vs. mid-formation snapshot
- Neutrino formation: separate path for `idclasse == 'neutrino'`
- Attack processing: troupes != 'Espionnage' path
- Espionage processing: success vs. failure based on neutrino count
- Attack return: molécule decay on return trip
- Alliance resource transfer delivery
- The recursion guard (if updateActions called twice for same joueur, second call exits early)

---

### 3.4 includes/combat.php

This file has no functions — it is included as a script and uses variables set by the caller. This makes it the hardest to unit test without significant refactoring.

| Logic Block | Category | Reason |
|---|---|---|
| Defender molecule loading | Integration | Direct DB query |
| Attacker molecule loading | Integration | Direct DB query |
| Isotope modifier calculation | Unit testable with refactor | Pure conditional logic if extracted to function |
| Catalytique ally bonus | Unit testable with refactor | Pure conditional loop |
| Chemical reaction detection (`checkReactions()`) | Unit testable now | `checkReactions()` is a named function with clean params |
| Reaction bonus accumulation | Unit testable with refactor | Pure accumulation logic |
| Formation bonus calculation (Embuscade) | Unit testable with refactor | Pure conditional |
| Total damage calculation | Integration | Calls `attaque()`, `defense()` which need DB |
| Casualty calculation (proportional HP) | Unit testable with refactor | Pure math if HP values are provided |
| Building damage random distribution | Integration + statistical | Uses `rand()`, needs DB for building levels |
| Pillage calculation | Integration | Calls `pillage()` which needs DB |
| Vault protection | Integration | Queries constructions for coffrefort level |
| Building HP reduction (`diminuerBatiment`) | Integration | Modifies DB |
| Points update (`ajouterPoints`) | Integration | Modifies DB |
| Alliance war tracking | Integration | Queries and updates declarations table |

**The `checkReactions()` function is a notable exception** — it is a named function with signature `checkReactions($classes, $nbClasses, &$activeReactions)` using `global $CHEMICAL_REACTIONS`. This is directly unit-testable right now without any DB or refactoring, and it is completely untested.

---

### 3.5 includes/player.php

| Function | Category | Reason |
|---|---|---|
| `statut($joueur)` | Integration | Queries membre table with timestamp condition |
| `compterActifs()` | Integration | COUNT query with timestamp condition |
| `inscrire($pseudo, $mdp, $mail)` | Integration | 6 INSERT/UPDATE statements across 5 tables |
| `ajouterPoints($nb, $joueur, $type)` | Integration | Reads then updates autre; 4 type branches |
| `initPlayer($joueur)` | Integration | ~15 DB queries, sets 20+ globals |
| `augmenterBatiment($nom, $joueur)` | Integration | Calls initPlayer + DB updates |
| `diminuerBatiment($nom, $joueur)` | Integration | Complex point redistribution logic |
| `coordonneesAleatoires()` | Integration | Reads/writes statistiques + membre |
| `batMax($pseudo)` | Integration | Queries constructions across 9 building types |
| `joueur($joueur)` | Integration | Calls statut() |
| `recalculerStatsAlliances()` | Integration | Multi-table aggregation |
| `supprimerAlliance($alliance)` | Integration | 6 DELETE/UPDATE statements |
| `supprimerJoueur($joueur)` | Integration | 16 DELETE/UPDATE statements |
| `miseAJour()` | Integration | Reads constructions, sets globals |
| `remiseAZero()` | Integration | 15+ UPDATE/DELETE statements — season reset |

**`diminuerBatiment` contains the most complex untested business logic.** When a producteur is destroyed below level 2, it must redistribute excess production points. The redistribution algorithm (lines 504-518 in player.php) is subtle: it takes points from each atom type until the debt is covered, but never reduces any atom below 1. This logic has never been tested.

---

### 3.6 includes/db_helpers.php

| Function | Category | Reason |
|---|---|---|
| `query($truc)` | Integration | Raw SQL passthrough — deprecated pattern |
| `ajouter($champ, $bdd, $nombre, $joueur)` | Integration | Generic field incrementer |
| `alliance($alliance)` | Unit testable now | Pure HTML string builder |
| `allianceResearchLevel($joueur, $techName)` | Integration | 2 DB queries |
| `allianceResearchBonus($joueur, $effectType)` | Integration | Iterates $ALLIANCE_RESEARCH config |

---

### 3.7 includes/prestige.php

| Function | Category | Reason |
|---|---|---|
| `calculatePrestigePoints($login)` | Integration | 4 DB queries; all logic branches testable with mocks |
| `awardPrestigePoints()` | Integration | Iterates all players, calls calculatePrestigePoints |
| `getPrestige($login)` | Integration | 1 DB query |
| `hasPrestigeUnlock($login, $unlockKey)` | Unit testable with refactor | String split + in_array; DB call is through getPrestige() |
| `purchasePrestigeUnlock($login, $unlockKey)` | Integration | Reads prestige, checks cost, updates |
| `prestigeProductionBonus($login)` | Unit testable with refactor | Calls hasPrestigeUnlock |
| `prestigeCombatBonus($login)` | Unit testable with refactor | Same |
| `isPrestigeLegend($login)` | Unit testable with refactor | Same |

---

### 3.8 includes/catalyst.php

| Function | Category | Reason |
|---|---|---|
| `getActiveCatalyst()` | Integration | Reads statistiques, writes on week rotation |
| `catalystEffect($effectName)` | Integration | Calls getActiveCatalyst |

**Note:** `catalystEffect()` can be made unit-testable by injecting the catalyst array. Currently the entire combat system calls `catalystEffect()` during every combat, making integration testing of combat implicitly require either a DB or a way to stub this function.

---

### 3.9 includes/csrf.php

| Function | Category | Reason |
|---|---|---|
| `csrfToken()` | Unit testable now | Uses `$_SESSION`, no DB; already mock-initialized in bootstrap |
| `csrfField()` | Unit testable now | Returns HTML string |
| `csrfVerify()` | Unit testable now | Compares `$_POST` and `$_SESSION` values |
| `csrfCheck()` | Unit testable now | Calls csrfVerify; tests `$_SERVER['REQUEST_METHOD']` |

All four CSRF functions are testable RIGHT NOW with the existing bootstrap infrastructure. No changes needed. Yet they have zero tests.

---

### 3.10 includes/validation.php

Fully tested by ValidationTest.php. One gap: the `antiXSS()` function in `display.php` (which wraps `mysqli_real_escape_string`) is security-critical and untested because it needs a DB connection.

---

### 3.11 includes/rate_limiter.php

| Function | Category | Reason |
|---|---|---|
| `rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds)` | Unit testable now | Uses filesystem (`/tmp/tvlw_rates`), no DB |
| `rateLimitRemaining($identifier, $action, $maxAttempts, $windowSeconds)` | Unit testable now | Same filesystem approach |

Both rate limiter functions use the filesystem, not the DB. They are completely unit-testable right now using temp directories in the test environment. Yet they have zero tests. These protect login and registration from brute force — a security-critical untested path.

---

### 3.12 includes/display.php

| Function | Category | Reason |
|---|---|---|
| `separerZeros($nombre)` | Unit testable now | Pure: `number_format()` wrapper |
| `couleur($chiffre)` | Unit testable now | Pure: HTML color wrapper for positive/negative/zero |
| `chiffrePetit($chiffre, $type)` | Unit testable now | Pure: SI prefix abbreviation function |
| `affichageTemps($secondes, $petitTemps)` | Unit testable now | Pure: time formatting function |
| `transformInt($nombre)` | Unit testable now | Pure: SI prefix to number |
| `pref($ressource)` | Unit testable now | Pure: French grammatical prefix |
| `couleurFormule($formule)` | Unit testable now | Pure: regex-based chemical formula colorization |
| `antihtml($phrase)` | Unit testable now | Thin wrapper around htmlspecialchars |
| `antiXSS($phrase, $specialTexte)` | Integration needed | Uses mysqli_real_escape_string — needs live DB |
| `rangForum($joueur)` | Integration needed | Multiple DB queries |
| `image($num)` | Unit testable now | HTML string builder (uses globals) |
| `imageEnergie(...)` | Unit testable now | HTML string builder |
| `coutEnergie(...)` | Integration-lite | Uses global $ressources set by initPlayer |
| `coutAtome(...)` | Integration-lite | Same |

---

### 3.13 Coverage Gap Summary by Risk

| Risk Level | Gap | Files Affected |
|---|---|---|
| CRITICAL | checkReactions() completely untested (unit-testable now) | combat.php |
| CRITICAL | CSRF functions completely untested (unit-testable now) | csrf.php |
| CRITICAL | Rate limiter completely untested (unit-testable now) | rate_limiter.php |
| CRITICAL | Season reset (remiseAZero) not tested | player.php |
| CRITICAL | Player deletion cleanup (supprimerJoueur) not tested | player.php |
| HIGH | Combat winner/loser determination not tested | combat.php |
| HIGH | Building level floor enforcement not tested (the bug was that level could reach 0) | player.php |
| HIGH | Pillage-on-draw behavior not tested | combat.php |
| HIGH | ajouterPoints() 4 type branches not tested | player.php |
| HIGH | Prestige point calculations not tested | prestige.php |
| HIGH | Market storage limit enforcement not tested | marche.php |
| MEDIUM | diminuerBatiment point redistribution not tested | player.php |
| MEDIUM | Actual formula functions (attaque/defense/pillage) never called | formulas.php |
| MEDIUM | Catalyst system rotation not tested | catalyst.php |
| MEDIUM | chiffrePetit SI prefix formatting not tested | display.php |
| MEDIUM | affichageTemps time formatting not tested | display.php |
| LOW | Victory points formulas (pure functions) not tested by calling the real function | formulas.php |

---

## 4. Part 3: Comprehensive Test Plan

Tests are organized by priority. Each entry includes: test name, what it tests, setup, assertions, DB requirement, and estimated lines of code.

---

### Priority 1 — Critical Game Logic (Exploit and Corruption Prevention)

#### P1.1: Pure Formula Function Tests
**File:** `tests/unit/FormulaFunctionsTest.php`
**Setup:** Load formulas.php without DB (pass null or 0 medal bonus); for functions that query DB for medals, test with `$joueur` parameter pointing to a known-state DB fixture.
**Why this matters:** Currently the test suite reimplements formulas inline. If someone changes the formula inside `attaque()` but not the constants, the current tests will not catch it.

| Test Name | Function Tested | Assertions | DB? | ~LOC |
|---|---|---|---|---|
| `testPointsVictoireJoueurAllRanks` | `pointsVictoireJoueur()` | Rank 1=100, Rank 2=80, Rank 3=70, Rank 4=65, Rank 10=35, Rank 11=33, Rank 20=15, Rank 21>=1, Rank 51=0, Rank 101=0, non-negative for all ranks 1-200 | No | 40 |
| `testPointsVictoireAllianceAllRanks` | `pointsVictoireAlliance()` | Rank 1=15, 2=10, 3=7, 4=6, 9=1, 10=0, 11=0 | No | 20 |
| `testPointsAttaqueGrowth` | `pointsAttaque()` | Returns 0 for <=0, positive for positive, grows with pts, matches formula | No | 15 |
| `testPointsDefenseGrowth` | `pointsDefense()` | Same structure as pointsAttaque | No | 15 |
| `testPointsPillageProperties` | `pointsPillage()` | Returns 0 for 0, positive for positive, approaches multiplier, monotone increasing | No | 15 |
| `testBonusDuplicateur` | `bonusDuplicateur()` | Level 0 = 0.0, Level 5 = 0.05, Level 10 = 0.1, Level 100 = 1.0 | No | 10 |
| `testDrainageProducteur` | `drainageProducteur()` | Level 0 = 0, Level 1 = 12, Level 10 = 120, linear | No | 10 |
| `testPointsDeVieMoleculeCallsRealFunction` | `pointsDeVieMolecule()` | Brome=0 niveau=0 gives 1, Brome=100 gives 201, matches formula | No | 15 |
| `testPotentielDestructionCallsRealFunction` | `potentielDestruction()` | H=0 gives 0, H=100 gives 156, matches formula | No | 10 |
| `testVitesseCallsRealFunction` | `vitesse()` | Cl=0 gives 1.0, Cl=10 gives 6.0, floor behavior | No | 10 |
| `testBonusLieurCallsRealFunction` | `bonusLieur()` | Level 0 = 1.0, increases, floor behavior | No | 10 |
| `testPlaceDepot` | `placeDepot()` | Level 1 = 500, Level 10 = 5000, linear | No | 10 |
| `testCoutClasse` | `coutClasse()` | Classe 1 = 16, 2 = 81, 3 = 256, 4 = 625 (pow(n+1,4) — NOTE: config says pow(n+1,6)=64 but formula in code is pow(n+1,4)) | No | 10 |
| `testPointsDeVieNoJoueur` | `pointsDeVie($niveau, null)` | No DB query when joueur=null, formula gives same as test | No | 10 |
| `testVieChampDeForceNoJoueur` | `vieChampDeForce($niveau, null)` | Same | No | 10 |

**Estimated total:** ~200 LOC

**Important discrepancy to verify:** `coutClasse()` in `formulas.php` (line 250) uses `pow($numero + 1, 4)` but `ConfigConsistencyTest.php` references `CLASS_COST_EXPONENT` which it asserts equals 6, and tests pow(2,6)=64. The actual code says exponent 4. One of them is wrong. This inconsistency needs a test that calls the real `coutClasse()` function.

---

#### P1.2: Combat Winner Determination Tests
**File:** `tests/unit/CombatResolutionTest.php`
**Setup:** Extract the winner determination logic into a testable function OR test via integration with DB fixtures. The `checkReactions()` function in combat.php CAN be called now.

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testCheckReactionsNoReaction` | `checkReactions()` with atoms below thresholds | Classes with 0 atoms, empty $CHEMICAL_REACTIONS | `$activeReactions` remains empty | No | 20 |
| `testCheckReactionsTriggered` | `checkReactions()` with atoms meeting threshold | Classes meeting condA and condB requirements | `$activeReactions` contains reaction name and bonus | No | 25 |
| `testCheckReactionsNoDuplicates` | `checkReactions()` same reaction in multiple class pairs | Multiple class pairs both meeting conditions | Reaction appears only once in `$activeReactions` | No | 20 |
| `testCheckReactionsSelfPairIgnored` | `checkReactions()` skips when a==b | Single class meeting both conditions | No reaction (requires a != b) | No | 15 |
| `testAttackerWinsWhenDefenderHasNoMolecules` | Combat winner logic | defenseursRestants=0, attaquantsRestants>0 | gagnant == 2 | No | 15 |
| `testDefenderWinsWhenAttackerHasNoMolecules` | Combat winner logic | attaquantsRestants=0, defenseursRestants>0 | gagnant == 1 | No | 15 |
| `testDrawWhenBothHaveMoleculesRemaining` | Combat winner logic | Both > 0 remaining | gagnant == 0 | No | 15 |
| `testDrawWhenBothHaveNoMolecules` | Combat winner logic | Both = 0 remaining | gagnant == 0 | No | 15 |

**Estimated total:** ~140 LOC

---

#### P1.3: Building Level Boundary Tests
**File:** `tests/integration/BuildingBoundaryTest.php`
**Setup:** Requires test DB with a player at building level 1.

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testDiminuerBatimentNeverReachesZero` | `diminuerBatiment()` on level-1 building | Player with generateur at level 1 | Building stays at level 1, not reduced to 0 | Yes | 30 |
| `testDiminuerBatimentChampdeforceNeverReachesZero` | `diminuerBatiment()` champdeforce at level 1 | Player with champdeforce at level 1 | Stays at level 1 | Yes | 25 |
| `testDiminuerBatimentProducteurNeverReachesZero` | `diminuerBatiment()` producteur at level 1 | Player with producteur at level 1 | Stays at level 1 | Yes | 25 |
| `testDiminuerBatimentDepotNeverReachesZero` | `diminuerBatiment()` depot at level 1 | Player with depot at level 1 | Stays at level 1 | Yes | 25 |
| `testAugmenterBatimentIncrementsLevel` | `augmenterBatiment()` | Player at level 5 | Level becomes 6, points added | Yes | 25 |
| `testDiminuerBatimentProducteurRedistributesPoints` | Point redistribution | Producteur at level 3, all production points assigned | Level drops, points redistributed without going below 1 per atom | Yes | 50 |
| `testDiminuerBatimentCondenseurRedistributesNoNegative` | Condenseur redistribution | Condenseur with points assigned | No atom level goes negative | Yes | 40 |

**Estimated total:** ~220 LOC

---

#### P1.4: Resource Production Boundary Tests
**File:** `tests/integration/ResourceBoundaryTest.php`
**Setup:** Requires test DB.

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testResourcesCapAtDepotLevel` | Energy cap in updateRessources | Player with full depot, positive revenu | Energy does not exceed placeDepot(level) | Yes | 35 |
| `testAtomsCapAtDepotLevel` | Atom cap in updateRessources | All atoms at capacity | No atom exceeds depot capacity | Yes | 35 |
| `testNegativeEnergyClampedToZero` | Energy floor at 0 | Player with producteur drain exceeding generator | Energy stays >= 0 | Yes | 30 |
| `testMarketPurchaseRespectStorageLimit` | Bug fix: market bypasses storage | Player near storage limit buys atoms | Atoms capped at depot capacity after purchase | Yes | 40 |
| `testEnergyCapOnSelling` | Bug fix: energy cap on selling | Player has full energy, sells atoms | Energy capped at depot | Yes | 30 |

**Estimated total:** ~170 LOC

---

#### P1.5: Points Calculation Tests
**File:** `tests/integration/PointsCalculationTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testAjouterPointsType0Construction` | `ajouterPoints($nb, $joueur, 0)` | Player with 100 points | points + nb stored, totalPoints updated | Yes | 30 |
| `testAjouterPointsType0NeverGoesNegative` | Type 0 prevents negative points total | Player at 5 points, subtract 10 | Points stay at 5 (conditional in code) | Yes | 25 |
| `testAjouterPointsType1Attack` | `ajouterPoints($nb, $joueur, 1)` | Player with known attack score | pointsAttaque updated, totalPoints delta = sqrt-based formula | Yes | 30 |
| `testAjouterPointsType2Defense` | `ajouterPoints($nb, $joueur, 2)` | Player with known defense score | Same structure as type 1 | Yes | 30 |
| `testAjouterPointsType3Pillage` | `ajouterPoints($nb, $joueur, 3)` | Player with known pillage total | ressourcesPillees updated, totalPoints uses tanh formula | Yes | 30 |
| `testAjouterPointsType3NegativeDoesNotCorrupt` | Pillage points can go negative in game for defender | Defender after losing resources | totalPoints reflects correct tanh delta | Yes | 30 |

**Estimated total:** ~175 LOC

---

### Priority 2 — Security Tests

#### P2.1: CSRF Protection Tests
**File:** `tests/unit/CsrfTest.php`
**Setup:** Uses existing bootstrap (already has `$_SESSION = []`). No DB needed.

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testCsrfTokenGeneratesOnFirstCall` | `csrfToken()` with empty session | `$_SESSION = []` | Returns 64-char hex string | No | 10 |
| `testCsrfTokenReusesSameToken` | `csrfToken()` idempotent | Session has existing token | Returns same token as stored | No | 10 |
| `testCsrfTokenIsHex64Chars` | Token format | Fresh session | Token matches `/^[0-9a-f]{64}$/` | No | 10 |
| `testCsrfFieldContainsToken` | `csrfField()` output | Known token in session | Output contains `name="csrf_token"` and token value | No | 15 |
| `testCsrfFieldEscapesToken` | XSS in token prevented | Manually set token with special chars | Output uses htmlspecialchars | No | 10 |
| `testCsrfVerifyMatchingToken` | `csrfVerify()` success | `$_SESSION['csrf_token'] = 'abc', $_POST['csrf_token'] = 'abc'` | Returns true | No | 10 |
| `testCsrfVerifyMismatchedToken` | `csrfVerify()` failure | Different POST and SESSION tokens | Returns false | No | 10 |
| `testCsrfVerifyEmptyPost` | `csrfVerify()` missing POST token | POST empty, SESSION has token | Returns false | No | 10 |
| `testCsrfVerifyEmptySession` | `csrfVerify()` missing SESSION token | SESSION empty | Returns false | No | 10 |
| `testCsrfVerifyBothEmpty` | `csrfVerify()` both empty | POST and SESSION both empty | Returns false | No | 10 |
| `testCsrfVerifyTimingAttackResistance` | `hash_equals` used (not ==) | Cannot test timing directly, but verify the function uses hash_equals | Source code assertion (verify function uses hash_equals) | No | 5 |
| `testCsrfCheckPassesOnGet` | `csrfCheck()` GET request | `$_SERVER['REQUEST_METHOD'] = 'GET'` | Does not die | No | 10 |
| `testCsrfCheckPassesOnValidPost` | `csrfCheck()` valid POST | Matching tokens, POST method | Does not die | No | 15 |

**Estimated total:** ~135 LOC

---

#### P2.2: Input Validation Extended Tests
**File:** `tests/unit/ValidationExtendedTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testValidateLoginRejectsSQLInjection` | SQL injection in login | `' OR '1'='1` | Returns false | No | 10 |
| `testValidateLoginRejectsNullByte` | Null byte in login | `"valid\0login"` | Returns false | No | 10 |
| `testValidateLoginAcceptsAllValidChars` | Full valid charset | `A-Z, a-z, 0-9, _` in valid combos | All return true | No | 15 |
| `testValidateLoginRejectsUnicode` | Unicode username | `"légion"` | Returns false (only ASCII subset allowed) | No | 10 |
| `testValidateEmailRejectsDoubleAt` | Malformed email | `user@@domain.com` | Returns false | No | 10 |
| `testValidatePositiveIntRejectsNull` | Type safety | `null` input | Returns false | No | 10 |
| `testValidatePositiveIntRejectsFloat` | Float input | `1.5` | Returns false (FILTER_VALIDATE_INT rejects floats) | No | 10 |
| `testValidateRangeRejectsFloat` | Float in range check | `validateRange(1.5, 1, 10)` | Returns false | No | 10 |
| `testSanitizeOutputNullBytes` | Null bytes | `"test\0attack"` | Output has no null bytes | No | 10 |
| `testSanitizeOutputLargeInput` | Large input | 100KB string | Does not crash, returns string | No | 10 |

**Estimated total:** ~105 LOC

---

#### P2.3: Rate Limiter Tests
**File:** `tests/unit/RateLimiterTest.php`
**Setup:** Uses `/tmp/tvlw_rates_test/` directory (isolated from production). Clean up in `setUp()` and `tearDown()`.

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testFirstAttemptAllowed` | First call within window | Clean state | Returns true | No | 15 |
| `testAttemptsWithinLimitAllowed` | Multiple calls under max | 4 calls, max=5 | All return true | No | 20 |
| `testAttemptAtExactLimitDenied` | Limit boundary | Call exactly maxAttempts+1 times | maxAttempts+1th call returns false | No | 20 |
| `testAttemptsOutsideWindowReset` | Window expiry | Call maxAttempts times, then wait (mock time) | After window, first call returns true | No | 25 |
| `testRemainingAttemptsCountsCorrectly` | `rateLimitRemaining()` | 2 of 5 attempts used | Returns 3 | No | 20 |
| `testRemainingAttemptsZeroWhenDenied` | `rateLimitRemaining()` when limited | All attempts used | Returns 0 | No | 15 |
| `testDifferentActionsIndependent` | Action isolation | Fill limit for 'login', check 'register' | 'register' still returns true | No | 20 |
| `testDifferentIdentifiersIndependent` | Identifier isolation | Fill limit for 'user1', check 'user2' | 'user2' still returns true | No | 20 |
| `testDirectoryCreatedIfMissing` | Auto-dir creation | Temp dir does not exist | Function creates it, returns true | No | 15 |
| `testFileLockPreventsRace` | LOCK_EX flag used | Cannot test directly; verify file_put_contents called with LOCK_EX | Source code assertion | No | 5 |

**Estimated total:** ~175 LOC

---

#### P2.4: Auth Guard Tests
**File:** `tests/integration/AuthGuardTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testPrivatePageRedirectsWithoutSession` | basicprivatephp.php | Empty session | HTTP redirect to connexion.php | Yes (mock) | 30 |
| `testValidSessionPassesGuard` | Session validation | Valid session, player in DB | No redirect | Yes | 30 |

**Estimated total:** ~60 LOC

---

### Priority 3 — Data Integrity Tests

#### P3.1: Season Reset Completeness
**File:** `tests/integration/SeasonResetTest.php`
**Setup:** Full test DB seeded with realistic player data.

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testRemiseAZeroResetsAllPlayerPoints` | `remiseAZero()` player stats | Seed: 5 players with points, attacks, medals | All players: points=0, nbattaques=0, moleculesPerdues=0 | Yes | 50 |
| `testRemiseAZeroResetsAllBuildings` | Buildings after reset | Players with various building levels | All buildings at default (generateur=1, producteur=1, depot=1, etc.) | Yes | 40 |
| `testRemiseAZeroResetsAllResources` | Resources after reset | Players with resources | All resources at default (0 or game default) | Yes | 35 |
| `testRemiseAZeroDeletesAllActions` | Action queues cleared | Players with pending constructions, formations, attacks | actionsconstruction, actionsformation, actionsattaques all empty | Yes | 30 |
| `testRemiseAZeroDeletesAllMessages` | Messages cleared | Players with messages | messages, rapports tables empty | Yes | 20 |
| `testRemiseAZeroDeletesDeclarations` | Alliance relations cleared | Alliance with war/pact | declarations, invitations empty | Yes | 20 |
| `testRemiseAZeroPrestigeFastStartApplied` | Prestige fast-start unlock | Player with 'debutant_rapide' prestige unlock | After reset, that player has generateur level 2 | Yes | 35 |
| `testRemiseAZeroPlayerCoordinatesReset` | Map positions cleared | Players with map coordinates | All x=-1000, y=-1000 | Yes | 20 |
| `testRemiseAZeroAllianceStatsReset` | Alliance stats cleared | Alliances with points, duplicateur | All alliance stats at 0 | Yes | 25 |
| `testRemiseAZeroCleansCooldowns` | Attack cooldown cleanup | Existing cooldown records | attack_cooldowns table empty | Yes | 15 |

**Estimated total:** ~290 LOC

---

#### P3.2: Player Deletion Cleanup
**File:** `tests/integration/PlayerDeletionTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testSupprimerJoueurDeletesMembre` | `supprimerJoueur()` — membre table | Player in DB | No row in membre for login | Yes | 20 |
| `testSupprimerJoueurDeletesAutre` | autre table | Same player | No row in autre | Yes | 15 |
| `testSupprimerJoueurDeletesRessources` | ressources table | Same | No row in ressources | Yes | 15 |
| `testSupprimerJoueurDeletesMolecules` | molecules table | Same | No molecules for proprietaire | Yes | 15 |
| `testSupprimerJoueurDeletesConstructions` | constructions table | Same | No row in constructions | Yes | 15 |
| `testSupprimerJoueurDeletesInvitations` | invitations table | Player has pending invite | No invitation for invite= | Yes | 20 |
| `testSupprimerJoueurDeletesMessages` | messages both sent and received | Player has sent and received messages | No messages for destinataire OR expeditaire | Yes | 25 |
| `testSupprimerJoueurDeletesRapports` | rapports table | Player has combat reports | No rapports for destinataire | Yes | 15 |
| `testSupprimerJoueurDeletesGrades` | grades table | Player has alliance grade | No grade for login | Yes | 20 |
| `testSupprimerJoueurDeletesAttacks` | actionsattaques | Player is attacker or defender | No attacks for either role | Yes | 20 |
| `testSupprimerJoueurDeletesFormations` | actionsformation | Player has formation in queue | No formation for login | Yes | 15 |
| `testSupprimerJoueurDeletesEnvois` | actionsenvoi | Player is sender or receiver | No envoi for either role | Yes | 20 |
| `testSupprimerJoueurDeletesStatutForum` | statutforum table | Player has forum status | No row for login | Yes | 15 |
| `testSupprimerJoueurDeletesVacances` | vacances table | Player on vacation | No row for idJoueur | Yes | 20 |
| `testSupprimerJoueurDecrementsInscrits` | statistiques.inscrits | Known inscrits count | inscrits decremented by 1 | Yes | 20 |

**Estimated total:** ~270 LOC

---

#### P3.3: Alliance Operations
**File:** `tests/integration/AllianceOperationsTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testSupprimerAllianceDeletesFromAllTables` | `supprimerAlliance()` completeness | Alliance with members, declarations, invitations, grades | All 6 DELETE/UPDATE operations complete | Yes | 50 |
| `testAllianceResearchLevelForPlayerWithoutAlliance` | `allianceResearchLevel()` | Player with idalliance=0 | Returns 0 | Yes | 15 |
| `testAllianceResearchLevelForAllianceMember` | `allianceResearchLevel()` | Player with alliance having fortification=3 | Returns 3 | Yes | 20 |
| `testAllianceResearchBonusCalculation` | `allianceResearchBonus()` | Player with fortification tech | Bonus = level * effect_per_level | Yes | 25 |
| `testRecalculerStatsAlliancesAggregation` | `recalculerStatsAlliances()` | 2 alliances with known members | Alliance pointstotaux matches sum of member totalPoints | Yes | 35 |

**Estimated total:** ~145 LOC

---

#### P3.4: Prestige System
**File:** `tests/integration/PrestigeTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testCalculatePrestigePointsActivePlayer` | `calculatePrestigePoints()` | Player active last 7 days, 10 attacks, 3 trade volume, donated | pp >= 5+5+2 = 12 | Yes | 30 |
| `testCalculatePrestigePointsInactivePlayer` | Base case | Player inactive, no attacks | pp = 0 | Yes | 20 |
| `testHasPrestigeUnlockTrue` | `hasPrestigeUnlock()` | prestige.unlocks = 'debutant_rapide,veteran' | Returns true for 'veteran' | Yes | 15 |
| `testHasPrestigeUnlockFalse` | `hasPrestigeUnlock()` | prestige.unlocks = '' | Returns false for 'veteran' | Yes | 15 |
| `testPurchasePrestigeUnlockSuccess` | `purchasePrestigeUnlock()` | Player with 100 PP, buying 50-cost unlock | Returns true, unlock added to unlocks string, PP deducted | Yes | 30 |
| `testPurchasePrestigeUnlockInsufficientPP` | Insufficient PP case | Player with 30 PP, 50-cost unlock | Returns error string, no change | Yes | 25 |
| `testPurchasePrestigeUnlockAlreadyOwned` | Duplicate purchase prevention | Player already has unlock | Returns error string | Yes | 20 |
| `testPurchasePrestigeUnlockUnknownKey` | Invalid unlock key | 'nonexistent_key' | Returns error string | Yes | 15 |
| `testPrestigeProductionBonusWithUnlock` | `prestigeProductionBonus()` | Player has 'experimente' | Returns 1.05 | Yes | 15 |
| `testPrestigeProductionBonusWithoutUnlock` | Without unlock | Player without 'experimente' | Returns 1.0 | Yes | 10 |

**Estimated total:** ~195 LOC

---

### Priority 4 — Integration Tests

#### P4.1: Full Combat Flow
**File:** `tests/integration/CombatFlowTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testAttackerWinsFullCombat` | Complete combat resolution | Two players seeded with molecules and buildings; attacker has massive army | gagnant == 2, defender loses molecules, attacker gains resources, combat report created | Yes | 80 |
| `testDefenderWinsFullCombat` | Defender victory path | Defender with much larger army | gagnant == 1, attacker loses all molecules | Yes | 60 |
| `testDrawFullCombat` | Draw path | Balanced armies | gagnant == 0, no resources pillaged | Yes | 50 |
| `testNoPillageOnDraw` | Bug fix: no pillage on draws | Balanced armies | carboneePille, azotePille, etc. all == 0 | Yes | 35 |
| `testVaultProtectsResourcesFromPillage` | Coffrefort protection | Defender with coffrefort level 5 and resources | Attacker gains less than full resources (vault amount protected) | Yes | 50 |
| `testBuildingDamageNeverReducesBelowLevel1` | Building floor during combat | Defender with champdeforce at level 1, attacker with high hydrogen | Building stays at level 1 | Yes | 40 |
| `testDefenseCooldownCreatedOnAttackerLoss` | Cooldown creation | Defender wins | attack_cooldowns row created with correct attacker/defender/expires | Yes | 35 |

**Estimated total:** ~350 LOC

---

#### P4.2: Market Trade Flow
**File:** `tests/integration/MarketTradeTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testBuyFromMarketDeductsEnergy` | Market buy | Player with energy; market has carbon at price 10 | Energy reduced by round(price * amount) | Yes | 40 |
| `testBuyFromMarketAddsAtoms` | Market buy result | Same | Player's carbone increased by amount | Yes | 25 |
| `testBuyIncreasesMarketPrice` | Price impact on buy | Before/after comparison | Market price for carbone higher after purchase | Yes | 30 |
| `testSellToMarketAddsEnergy` | Market sell | Player with carbone atoms | Energy increased by round(price * amount) | Yes | 35 |
| `testSellToMarketDeductsAtoms` | Sell result | Same | Player's carbone reduced by amount | Yes | 25 |
| `testSellDecreasesMarketPrice` | Price impact on sell | Before/after | Market price lower after sell | Yes | 25 |
| `testBuyRespectStorageLimit` | Bug fix: storage bypass | Player at 90% capacity, tries to buy more than fits | Purchase capped at depot capacity | Yes | 40 |

**Estimated total:** ~220 LOC

---

#### P4.3: Registration and Login Flow
**File:** `tests/integration/RegistrationLoginTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testInscrireCreatesAllRequiredRows` | `inscrire()` completeness | Clean DB, unique login | Rows exist in membre, autre, ressources, molecules (4 rows), constructions | Yes | 50 |
| `testInscrireHashesPassword` | Password storage | Register new player | membre.mdp is a bcrypt hash, not plaintext | Yes | 25 |
| `testInscrireAssignsStartAtom` | Element assignment | Register 1000 test players (batch) | Carbone (index 0) accounts for ~50% of registrations | Yes | 40 |
| `testInscrireSanitizesLoginForXSS` | Input sanitization | Login with `<script>` | Stored login has htmlspecialchars applied | Yes | 20 |
| `testInscrireIncrementsInscritCount` | statistiques update | Known inscrits count | inscrits incremented | Yes | 20 |
| `testInscrireSetsInitialBuildingHP` | HP initialization | New player | vieGenerateur = pointsDeVie(1), vieChampdeforce = vieChampDeForce(0) | Yes | 20 |

**Estimated total:** ~175 LOC

---

#### P4.4: Admin Operations
**File:** `tests/integration/AdminOperationsTest.php`

| Test Name | What It Tests | Setup | Assertions | DB? | ~LOC |
|---|---|---|---|---|---|
| `testAdminPageRequiresAdminSession` | Admin auth guard | No session or regular player session | Redirects or returns 403 | Yes | 25 |
| `testAwardPrestigePointsRunsWithoutError` | `awardPrestigePoints()` | Seeded players | Function completes, prestige table has entries | Yes | 30 |

**Estimated total:** ~55 LOC

---

### Priority 5 — Regression Tests for the 18 Bug Fixes

See Appendix for full details. Summary table:

| Bug # | Short Name | Test Type | ~LOC |
|---|---|---|---|
| CRITICAL-1 | Wrong winner name in season emails | Integration | 30 |
| HIGH-1 | No 24h pause between seasons | Integration | 25 |
| HIGH-2 | Combat damage variable wrong | Integration | 40 |
| HIGH-3 | Buildings reach level 0 via combat | Integration | 35 |
| HIGH-4 | Pillaging on draws | Integration | 30 |
| HIGH-5 | SQL precedence in pact deletion | Integration | 30 |
| HIGH-6 | No auth in validerpacte.php | Integration | 25 |
| HIGH-7 | Duplicateur display vs combat (0.1% vs 1%) | Unit | 20 |
| MEDIUM-1 | diminuerBatiment allows level 0 | Integration | 30 |
| MEDIUM-2 | Condenseur redistribution negative | Integration | 35 |
| MEDIUM-3 | Market purchases bypass storage | Integration | 35 |
| MEDIUM-4 | supprimerJoueur missing cleanup | Integration | 40 |
| MEDIUM-5 | Infinite loop in coordonneesAleatoires | Unit/Integration | 30 |
| MEDIUM-6 | Recursive updateActions stack overflow | Unit | 20 |
| LOW-1 | Hardcoded 500*depot instead of placeDepot() | Unit | 15 |
| LOW-2 | Energy cap on selling | Integration | 25 |
| LOW-3 | Email date encoding | Unit | 10 |

**Estimated regression total:** ~455 LOC

---

### Test Plan Summary

| Priority | File(s) | Tests | DB? | Estimated LOC |
|---|---|---|---|---|
| P1.1 — Pure formula functions | FormulaFunctionsTest.php | ~15 | No | 200 |
| P1.2 — Combat winner logic + checkReactions | CombatResolutionTest.php | ~8 | No | 140 |
| P1.3 — Building level boundaries | BuildingBoundaryTest.php | ~7 | Yes | 220 |
| P1.4 — Resource production boundaries | ResourceBoundaryTest.php | ~5 | Yes | 170 |
| P1.5 — Points calculation | PointsCalculationTest.php | ~6 | Yes | 175 |
| P2.1 — CSRF protection | CsrfTest.php | ~13 | No | 135 |
| P2.2 — Extended validation | ValidationExtendedTest.php | ~10 | No | 105 |
| P2.3 — Rate limiter | RateLimiterTest.php | ~10 | No | 175 |
| P2.4 — Auth guards | AuthGuardTest.php | ~2 | Yes (mock) | 60 |
| P3.1 — Season reset | SeasonResetTest.php | ~10 | Yes | 290 |
| P3.2 — Player deletion | PlayerDeletionTest.php | ~15 | Yes | 270 |
| P3.3 — Alliance operations | AllianceOperationsTest.php | ~5 | Yes | 145 |
| P3.4 — Prestige system | PrestigeTest.php | ~10 | Yes | 195 |
| P4.1 — Full combat flow | CombatFlowTest.php | ~7 | Yes | 350 |
| P4.2 — Market trade flow | MarketTradeTest.php | ~7 | Yes | 220 |
| P4.3 — Registration/login flow | RegistrationLoginTest.php | ~6 | Yes | 175 |
| P4.4 — Admin operations | AdminOperationsTest.php | ~2 | Yes | 55 |
| P5 — Regression tests | RegressionBugFixTest.php | ~17 | Mixed | 455 |
| **TOTAL** | **17 new files** | **~165 new tests** | | **~3,530 LOC** |

Combined with existing ~2,673 lines and ~189 tests, the complete test suite would be approximately **6,200 lines and 354 tests**.

---

## 5. Part 4: Test Infrastructure

### 5.1 Current State Assessment

The current infrastructure supports ONLY unit tests with no DB connectivity. The `bootstrap.php` sets `$base = null`, which means any function that uses `global $base` and calls `dbFetchOne/dbQuery/dbExecute` will either crash with a null pointer error or silently produce wrong results.

The phpunit.xml defines only one test suite (`Unit` pointing at `tests/unit/`). There is no integration test configuration.

### 5.2 Recommended Infrastructure Changes

#### 5.2.1 Expanded Bootstrap

Create `tests/bootstrap_unit.php` (rename existing) and `tests/bootstrap_integration.php`:

```php
// tests/bootstrap_integration.php
<?php
// Load game constants and validation
require_once __DIR__ . '/../includes/constantesBase.php';
require_once __DIR__ . '/../includes/validation.php';

// Load DB connection helpers
require_once __DIR__ . '/../includes/database.php';

// Connect to TEST database (never production)
$testDbName = getenv('TVLW_TEST_DB') ?: 'tvlw_test';
$base = new mysqli('127.0.0.1', 'tvlw_test', 'test_password', $testDbName);
if ($base->connect_error) {
    die('Test DB connection failed: ' . $base->connect_error . "\n");
}

// Load all game modules
require_once __DIR__ . '/../includes/formulas.php';
require_once __DIR__ . '/../includes/game_resources.php';
require_once __DIR__ . '/../includes/game_actions.php';
require_once __DIR__ . '/../includes/player.php';
require_once __DIR__ . '/../includes/db_helpers.php';
require_once __DIR__ . '/../includes/prestige.php';
require_once __DIR__ . '/../includes/catalyst.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/display.php';

// Mock session
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
```

#### 5.2.2 Updated phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         colors="true"
         verbose="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="TVLW_TEST_DB" value="tvlw_test"/>
        <env name="RATE_LIMIT_DIR" value="/tmp/tvlw_rates_test"/>
    </php>
</phpunit>
```

The unit suite keeps its current bootstrap (no DB). The integration suite uses the new bootstrap.

#### 5.2.3 Test Fixtures / Factory

Create `tests/fixtures/PlayerFactory.php`:

```php
<?php
// tests/fixtures/PlayerFactory.php
class PlayerFactory {
    private mysqli $db;

    public function __construct(mysqli $db) {
        $this->db = $db;
    }

    /**
     * Create a minimal test player with all required DB rows.
     * Returns the login string.
     */
    public function create(array $overrides = []): string {
        $defaults = [
            'login' => 'testplayer_' . uniqid(),
            'mdp' => password_hash('testpass', PASSWORD_DEFAULT),
            'mail' => 'test@example.com',
            'generateur' => 1,
            'producteur' => 1,
            'depot' => 5,
            'champdeforce' => 1,
            'ionisateur' => 0,
            'condenseur' => 0,
            'lieur' => 0,
            'stabilisateur' => 0,
            'coffrefort' => 0,
            'energie' => 1000,
            'carbone' => 500,
            'azote' => 500,
            'hydrogene' => 500,
            'oxygene' => 500,
            'chlore' => 500,
            'soufre' => 500,
            'brome' => 500,
            'iode' => 500,
            'idalliance' => 0,
        ];
        $data = array_merge($defaults, $overrides);
        $login = $data['login'];
        $now = time();
        $vieGen = round(20 * (pow(1.2, $data['generateur']) + pow($data['generateur'], 1.2)));
        $vieCDF = round(50 * (pow(1.2, $data['champdeforce']) + pow($data['champdeforce'], 1.2)));

        // membre
        $this->db->execute_query(
            'INSERT INTO membre VALUES(default, ?, ?, ?, ?, ?, 0, ?, 0, 0, ?,-1000,-1000)',
            [$login, $data['mdp'], $now, '127.0.0.1', $now, 0, $data['mail']]
        );
        // autre
        $this->db->execute_query(
            'INSERT INTO autre VALUES(?, default, default, "", ?, default, default, default, default, default, default, default, default, default, default, ?, default, default, default, default, "", default)',
            [$login, $now, "$now,$now,$now,$now"]
        );
        if ($data['idalliance'] > 0) {
            $this->db->execute_query('UPDATE autre SET idalliance=? WHERE login=?', [$data['idalliance'], $login]);
        }
        // ressources
        $this->db->execute_query(
            'INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$login, $data['energie'], $data['carbone'], $data['azote'], $data['hydrogene'], $data['oxygene'], $data['chlore'], $data['soufre'], $data['brome'], $data['iode']]
        );
        // constructions
        $this->db->execute_query(
            'INSERT INTO constructions (login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur, coffrefort, vieGenerateur, vieChampdeforce, vieProducteur, vieDepot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$login, $data['generateur'], $data['producteur'], $data['depot'], $data['champdeforce'], $data['ionisateur'], $data['condenseur'], $data['lieur'], $data['stabilisateur'], $data['coffrefort'], $vieGen, $vieCDF, $vieGen, $vieGen]
        );
        // molecules (4 classes with 0 molecules)
        for ($i = 1; $i <= 4; $i++) {
            $this->db->execute_query(
                'INSERT INTO molecules (formule, nombre, numeroclasse, proprietaire) VALUES ("Vide", 0, ?, ?)',
                [$i, $login]
            );
        }
        return $login;
    }

    /**
     * Delete all DB rows for a test player. Call in tearDown.
     */
    public function cleanup(string $login): void {
        foreach (['membre', 'autre', 'ressources', 'constructions'] as $table) {
            $this->db->execute_query("DELETE FROM $table WHERE login=?", [$login]);
        }
        $this->db->execute_query("DELETE FROM molecules WHERE proprietaire=?", [$login]);
    }
}
```

#### 5.2.4 Base Integration Test Class

```php
<?php
// tests/integration/IntegrationTestCase.php
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase {
    protected mysqli $db;
    protected PlayerFactory $factory;
    protected array $createdPlayers = [];

    protected function setUp(): void {
        global $base;
        $this->db = $base;
        $this->factory = new PlayerFactory($this->db);
        // Wrap each test in a transaction for rollback isolation
        $this->db->begin_transaction();
    }

    protected function tearDown(): void {
        // Rollback all DB changes from this test
        $this->db->rollback();
        $this->createdPlayers = [];
    }

    protected function createPlayer(array $overrides = []): string {
        $login = $this->factory->create($overrides);
        $this->createdPlayers[] = $login;
        return $login;
    }
}
```

**Using transactions for test isolation** is the key recommendation. By wrapping each test in a transaction and rolling back in `tearDown()`, no test data persists between tests and no cleanup queries are needed. This approach makes integration tests fast and reliable.

#### 5.2.5 Test Database Setup

On the VPS, create a dedicated test database:

```sql
-- Run as root on VPS
CREATE DATABASE tvlw_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tvlw_test'@'localhost' IDENTIFIED BY 'tvlw_test_password';
GRANT ALL PRIVILEGES ON tvlw_test.* TO 'tvlw_test'@'localhost';

-- Import the schema (not data) from the live DB
mysqldump --no-data tvlw | mysql tvlw_test

-- Seed minimal reference data (statistiques row is required by many functions)
INSERT INTO tvlw_test.statistiques VALUES(1, 1, 1, 0, NULL, NULL);
```

The test DB uses the same schema as production but starts empty each test run.

#### 5.2.6 Rate Limiter Test Isolation

Override the `RATE_LIMIT_DIR` constant for tests. Since it is defined with `define()` in rate_limiter.php, it cannot be redefined. The solution is to check if it is already defined:

```php
// In rate_limiter.php, change:
define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');

// To:
if (!defined('RATE_LIMIT_DIR')) {
    define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');
}
```

Then in phpunit.xml set `RATE_LIMIT_DIR=/tmp/tvlw_rates_test` and define it before loading the file in the bootstrap.

### 5.3 CI/CD Integration Plan

#### 5.3.1 GitHub Actions Workflow

```yaml
# .github/workflows/tests.yml
name: PHP Tests

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mysqli
      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist
      - name: Run unit tests
        run: vendor/bin/phpunit --testsuite Unit

  integration-tests:
    runs-on: ubuntu-latest
    services:
      mariadb:
        image: mariadb:10.11
        env:
          MARIADB_ROOT_PASSWORD: root
          MARIADB_DATABASE: tvlw_test
          MARIADB_USER: tvlw_test
          MARIADB_PASSWORD: tvlw_test_password
        ports:
          - 3306:3306
        options: --health-cmd="healthcheck.sh --connect --innodb_initialized" --health-interval=10s --health-timeout=5s --health-retries=3
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mysqli
      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist
      - name: Import test schema
        run: mysql -h127.0.0.1 -utvlw_test -ptvlw_test_password tvlw_test < tests/fixtures/schema.sql
      - name: Run integration tests
        env:
          TVLW_TEST_DB: tvlw_test
          TVLW_TEST_DB_HOST: 127.0.0.1
          TVLW_TEST_DB_USER: tvlw_test
          TVLW_TEST_DB_PASS: tvlw_test_password
          RATE_LIMIT_DIR: /tmp/tvlw_rates_test
        run: vendor/bin/phpunit --testsuite Integration
```

#### 5.3.2 VPS Testing Workflow

For the current no-CI setup, create a test runner script on the VPS:

```bash
#!/bin/bash
# /var/www/html/run-tests.sh — run on VPS after deployment

cd /var/www/html

# Unit tests (no DB needed)
echo "=== Running Unit Tests ==="
php vendor/bin/phpunit --testsuite Unit

# Integration tests (requires test DB)
echo "=== Running Integration Tests ==="
TVLW_TEST_DB=tvlw_test \
RATE_LIMIT_DIR=/tmp/tvlw_rates_test \
php vendor/bin/phpunit --testsuite Integration

echo "=== Tests Complete ==="
```

#### 5.3.3 Estimated Execution Times

| Suite | Tests | Estimated Runtime |
|---|---|---|
| Unit (existing + new) | ~260 tests | 5–10 seconds |
| Integration | ~100 tests | 30–60 seconds (DB overhead) |
| **Total** | **~360 tests** | **< 90 seconds** |

This is well within the 30-minute target. DB tests dominate execution time but the transaction rollback approach eliminates slow setup/teardown.

### 5.4 Schema File for Test DB

Create `tests/fixtures/schema.sql` by running on the VPS:

```bash
mysqldump --no-data --routines tvlw > /home/guortates/TVLW/The-Very-Little-War/tests/fixtures/schema.sql
```

This file should be committed and kept in sync with the live schema. When migrations are applied to the live DB, the same SQL should be applied to `schema.sql`.

### 5.5 Quick Wins — Tests to Write Immediately

These tests require NO infrastructure changes and can be added to the existing `tests/unit/` directory today:

1. **CsrfTest.php** — 13 tests, ~135 LOC, tests security-critical code with zero setup
2. **RateLimiterTest.php** — 10 tests, ~175 LOC, uses filesystem only (needs define fix)
3. **FormulaFunctionsTest.php** — 15 tests, ~200 LOC, loads formulas.php and calls real functions
4. **CombatResolutionTest.php (partial)** — 4 checkReactions tests, ~80 LOC, tests the one named function in combat.php
5. **ValidationExtendedTest.php** — 10 tests, ~105 LOC, extends existing validation tests

Total for immediate quick wins: ~52 tests, ~695 LOC, zero infrastructure changes.

---

## 6. Appendix: Regression Map for 18 Bug Fixes

Each entry maps a bug from commit a9d8c60 to a specific test that would have caught it.

### Bug 1 (CRITICAL): Wrong winner name in season emails
**What was wrong:** Season reset email used `$_SESSION['login']` (the admin) instead of `$vainqueurManche` (the actual season winner).
**Test that catches it:**
```
File: tests/integration/SeasonEmailTest.php
Test: testSeasonEmailUsesCorrectWinnerName
Setup: Run season end with player "Alice" ranked 1st, admin "Admin" running reset
Assert: Email sent to all players uses "Alice" in the "vainqueur" field, not "Admin"
Type: Integration
~LOC: 30
```

### Bug 2 (HIGH): No actual 24h pause between seasons
**What was wrong:** Season transition happened immediately with no enforced waiting period.
**Test that catches it:**
```
File: tests/integration/SeasonResetTest.php
Test: testSeasonResetRequires24hMaintenancePhase
Setup: Trigger maintenance mode, then immediately try to trigger reset
Assert: Reset is refused until maintenance_end timestamp has passed
Type: Integration
~LOC: 25
```

### Bug 3 (HIGH): Combat damage variable wrong
**What was wrong:** `$degatsAttaquant` was used where `$degatsDefenseur` was needed in the casualty loop, causing attackers to take their own damage.
**Test that catches it:**
```
File: tests/integration/CombatFlowTest.php
Test: testAttackerTakesDefenderDamageNotOwnDamage
Setup: Attacker with 0 attack (all brome, no oxygen), Defender with high defense
Assert: Attacker casualties reflect defender's damage output, not attacker's
Type: Integration
~LOC: 40
```

### Bug 4 (HIGH): Buildings could reach level 0 via combat
**What was wrong:** `diminuerBatiment()` was called even when `constructions[nom] <= 1`, allowing buildings to drop to level 0 which is an invalid state.
**Test that catches it:**
```
File: tests/integration/BuildingBoundaryTest.php
Test: testCombatBuildingDamageNeverReducesBelowLevel1
Setup: Defender with generateur at level 1, attacker with massive hydrogen army
Assert: After combat, defender's generateur is still level 1 (not 0)
Type: Integration
~LOC: 35
```

### Bug 5 (HIGH): Pillaging on draws removed
**What was wrong:** Original code awarded pillage resources to the attacker on draws (gagnant==0). Fixed to only pillage on attacker victory (gagnant==2).
**Test that catches it:**
```
File: tests/integration/CombatFlowTest.php
Test: testNoPillageOnDraw
Setup: Perfectly balanced armies resulting in draw (gagnant==0)
Assert: All ressourcePille values are 0; defender's resources unchanged
Type: Integration
~LOC: 30
```

### Bug 6 (HIGH): SQL precedence in pact deletion
**What was wrong:** Missing parentheses in WHERE clause: `type=0 AND fin=0 AND alliance1=? OR alliance2=?` deleted wars instead of only pacts involving the target alliance.
**Test that catches it:**
```
File: tests/integration/AllianceOperationsTest.php
Test: testPactDeletionDoesNotDeleteWars
Setup: Alliance A in war with B, Alliance A has pact with C; delete pact with C
Assert: War between A and B still exists; only pact with C is deleted
Type: Integration
~LOC: 30
```

### Bug 7 (HIGH): No auth in validerpacte.php
**What was wrong:** Any player could accept any pact invitation by crafting a POST request, bypassing the check that the invitation was intended for them.
**Test that catches it:**
```
File: tests/integration/AuthGuardTest.php
Test: testValiderpacteRequiresCorrectRecipient
Setup: Player A invites Player B to pact; Player C tries to accept it
Assert: Request from Player C is rejected (not the intended recipient)
Type: Integration (HTTP-level or function-level)
~LOC: 25
```

### Bug 8 (HIGH): Duplicateur display 0.1% vs combat 1%
**What was wrong:** The display showed `level * 0.1%` bonus but combat applied `level * 1%` bonus. These must match.
**Test that catches it:**
```
File: tests/unit/FormulaFunctionsTest.php
Test: testDuplicateurBonusConsistentWithDisplay
Setup: Use DUPLICATEUR_BONUS_PER_LEVEL constant
Assert: DUPLICATEUR_BONUS_PER_LEVEL == 0.01 (1% per level); combat formula
        bonusDuplicateur = 1 + (level * DUPLICATEUR_BONUS_PER_LEVEL) matches display
Type: Unit
~LOC: 20
```

### Bug 9 (MEDIUM): diminuerBatiment allowed level 0
**What was wrong:** Level check was missing, allowing the building to drop from 1 to 0.
**Test that catches it:**
```
File: tests/integration/BuildingBoundaryTest.php
Test: testDiminuerBatimentNeverReachesZero (already defined in P1.3 above)
~LOC: 30
```

### Bug 10 (MEDIUM): Condenseur redistribution negative values
**What was wrong:** Condenseur point redistribution on level-down could produce negative atom levels.
**Test that catches it:**
```
File: tests/integration/BuildingBoundaryTest.php
Test: testDiminuerBatimentCondenseurRedistributesNoNegative (already defined in P1.3)
~LOC: 35
```

### Bug 11 (MEDIUM): Market purchases bypass storage limits
**What was wrong:** Buying atoms from the market did not check if the purchase would exceed the player's depot capacity.
**Test that catches it:**
```
File: tests/integration/MarketTradeTest.php
Test: testBuyRespectStorageLimit (already defined in P4.2)
~LOC: 35
```

### Bug 12 (MEDIUM): supprimerJoueur missing cleanup
**What was wrong:** 5 tables were not cleaned up on player deletion: vacances, grades, actionsformation, actionsenvoi, statutforum.
**Test that catches it:**
```
File: tests/integration/PlayerDeletionTest.php
Tests: testSupprimerJoueurDeletesGrades, testSupprimerJoueurDeletesFormations,
       testSupprimerJoueurDeletesEnvois, testSupprimerJoueurDeletesStatutForum,
       testSupprimerJoueurDeletesVacances (all defined in P3.2)
~LOC: 40 total
```

### Bug 13 (MEDIUM): Infinite loop in coordonneesAleatoires
**What was wrong:** When the map edge was full, the while loop had no exit condition, causing an infinite loop.
**Test that catches it:**
```
File: tests/integration/PlayerRegistrationTest.php
Test: testCoordonneeAleatoiresWithFullMapEdge
Setup: Fill map edge with players (mock or seed)
Assert: Function returns a valid coordinate within a reasonable time (no timeout)
Type: Integration
~LOC: 30
```

### Bug 14 (MEDIUM): Recursive updateActions stack overflow
**What was wrong:** `updateActions()` recursively called itself without a guard when processing both attacker and defender, causing stack overflow.
**Test that catches it:**
```
File: tests/unit/CombatResolutionTest.php
Test: testUpdateActionsRecursionGuard
Setup: Two players A (attacker) and B (defender) where processing A triggers call for B
       which would trigger call for A again
Assert: The static $updating guard prevents the second call; function returns without recursion
Type: Unit (test the guard logic in isolation)
~LOC: 20
```

### Bug 15 (LOW): Hardcoded 500*depot
**What was wrong:** Some code used `500 * $depot_level` instead of calling `placeDepot()`. If the capacity formula changes, the hardcoded version is not updated.
**Test that catches it:**
```
File: tests/unit/FormulaFunctionsTest.php
Test: testAllStorageCalculationsUseDepotFunction
Note: This is a code review test. Instead of a runtime test, add a grep-based assertion
      that no PHP file (outside formulas.php) contains the pattern /500 \* \$/ applied to depot.
Type: Unit (static analysis assertion)
~LOC: 15
```

### Bug 16 (LOW): Energy cap on selling
**What was wrong:** Selling atoms to the market could give the player more energy than their depot capacity allows.
**Test that catches it:**
```
File: tests/integration/MarketTradeTest.php
Test: testSellDoesNotExceedEnergyCap
Setup: Player with full energy depot, sells atoms at a price
Assert: Player's energy does not exceed placeDepot(depot_level) after sale
Type: Integration
~LOC: 25
```

### Bug 17 (LOW): Email date encoding
**What was wrong:** Season email date contained `Ã` (mojibake for `à`), indicating a charset mismatch in the email headers.
**Test that catches it:**
```
File: tests/unit/FormulaFunctionsTest.php
Test: testEmailCharsetIsUTF8
Note: Verify that email functions use Content-Type: text/html; charset=UTF-8 header
      and that utf8_encode() or similar is NOT used (which double-encodes UTF-8 strings)
Type: Unit (inspect generated email header string)
~LOC: 10
```

---

### Regression Test Implementation Priority

Given the limited current infrastructure, the regression tests should be implemented in this order:

1. Tests 8, 14, 15, 17 — Unit tests, no DB, add to existing test files immediately
2. Tests 4, 5, 9, 10 — Integration DB tests for building level boundaries (batch into one file)
3. Tests 3, 11, 16 — Integration DB tests for combat damage and market limits
4. Tests 12 — Player deletion completeness (straightforward DB assertions)
5. Tests 1, 2, 6, 7, 13 — More complex integration tests requiring more setup

---

*Document generated: 2026-03-02*
*Covers game version: commit a9d8c60 and subsequent fixes*
*Author: Test Automation Analysis Agent*
