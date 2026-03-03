# Code Quality Audit — Round 1

**Audit Date:** 2026-03-03
**Scope:** 14 of 15 requested PHP files (tableau.php does not exist in the project)
**Dimensions:** Code duplication, function complexity, dead code, naming consistency, coupling, error handling, magic values, PHP best practices

---

## Summary

Total findings: 42 (10 HIGH, 18 MEDIUM, 14 LOW)

| Severity | Count |
|----------|-------|
| HIGH     | 10    |
| MEDIUM   | 18    |
| LOW      | 14    |
| **Total** | **42** |

**Files reviewed:**
includes/player.php, includes/combat.php, includes/ui_components.php, includes/config.php,
marche.php, includes/game_actions.php, allianceadmin.php, attaquer.php, tutoriel.php,
includes/basicprivatehtml.php, armee.php, alliance.php, includes/bbcode.php, classement.php

---

## Findings

### CODE-001 [HIGH] Variable Variables Create Unmaintainable Combat Engine

**File:** `includes/combat.php:9,20,159,233,238,347,380-383,413-416`

**Issue:** The combat engine uses PHP variable variables (`${'classeAttaquant' . $c}`, `${'classe' . $i . 'AttaquantMort'}`, `${$ressource . 'Pille'}`) as its primary data structure. These appear on over 80 lines throughout the file. Variable variables defeat static analysis, IDE autocomplete, and type checking. A single typo in a variable name string silently creates a new variable instead of throwing an error.

**Impact:** Any future modification to combat logic is error-prone. Variable variables bypass PHP 8.x's stricter type enforcement, prevent IDE refactoring support, and make unit testing nearly impossible since there is no structured data to assert against.

**Fix:** Replace variable variables with indexed arrays:
```php
// Before
${'classeAttaquant' . $c} = $classeAttaquant;
${'classe' . $i . 'AttaquantMort'} = 0;

// After
$classesAttaquant[$c] = $classeAttaquant;
$classesMortAttaquant[$i] = 0;
```

---

### CODE-002 [HIGH] combat.php Is a 634-Line Script With No Functions

**File:** `includes/combat.php:1-634`

**Issue:** The entire combat resolution engine is a single procedural script with no function boundaries. It is `include()`d inside `game_actions.php:109`, which means every local variable in the calling function is implicitly shared with combat.php via scope leakage. The only function defined is `checkReactions()` (line 132), wrapped in a `function_exists()` guard to avoid redefinition on repeated includes. This architecture makes it impossible to test combat logic in isolation, as it depends on 20+ variables being pre-set in the calling scope.

**Impact:** Cannot unit test combat. Cannot refactor without risk of breaking shared variable contracts. The `function_exists()` guard is a code smell indicating the architecture is fighting PHP's include model.

**Fix:** Wrap the combat engine in a function that accepts structured parameters and returns a result object:
```php
function resolveCombat(array $attackData, array $defenseData, mysqli $base): array {
    // ... combat logic ...
    return ['winner' => $winner, 'casualties' => $casualties, 'pillage' => $pillage, 'buildingDamage' => $damage];
}
```

---

### CODE-003 [HIGH] initPlayer() Is a 400-Line God Function

**File:** `includes/player.php:108-494`

**Issue:** `initPlayer()` performs at minimum 6 distinct responsibilities in a single function: (1) DB reads for 4 tables, (2) revenue calculations, (3) building queue lookups, (4) cost formula computations, (5) HTML+JS generation for construction UI, and (6) global cache management. It writes to 20+ global variables. The function is approximately 400 lines long, mixing business logic with presentation code (HTML string building with inline `<script>` tags on lines 196-236).

**Impact:** Any change to building costs, UI rendering, or DB schema requires modifying this monolithic function. The function cannot be partially invoked (e.g., "just get building costs") without executing all the HTML generation code as well.

**Fix:** Extract into focused functions: `loadPlayerData($joueur)` for DB reads, `calculateBuildingCosts($constructions, $bonus)` for formulas, `renderConstructionUI($constructions, $revenu)` for HTML generation. Return structured arrays instead of writing to globals.

---

### CODE-004 [HIGH] Hardcoded Loop Bound Instead of $nbClasses

**File:** `includes/combat.php:205`

**Issue:** The main damage calculation loop uses `for ($c = 1; $c <= 4; $c++)` with a hardcoded `4` instead of using the `$nbClasses` variable that is available in scope and used elsewhere in the same file (e.g., lines 69, 158, 231, 255). If the game ever supports more or fewer molecule classes, this loop would silently produce incorrect combat results.

**Impact:** Game balance breakage if class count changes. Inconsistent with every other loop in the file that correctly uses `$nbClasses`.

**Fix:** Replace `4` with `$nbClasses`:
```php
for ($c = 1; $c <= $nbClasses; $c++) {
```

---

### CODE-005 [HIGH] augmenterBatiment Uses $_SESSION Instead of $joueur Parameter

**File:** `includes/player.php:538-539`

**Issue:** At the end of `augmenterBatiment($nom, $joueur)`, the function calls `invalidatePlayerCache($_SESSION['login'])` and `initPlayer($_SESSION['login'])` using the session login instead of the `$joueur` parameter. When `augmenterBatiment` is called for a player other than the current session user (e.g., during automated building upgrades via `updateActions()` processing queued builds for other players), the cache invalidation targets the wrong player. The correct player's cache remains stale.

**Impact:** Stale cached data can cause incorrect building cost calculations and display errors for any player whose building upgrade is processed when they are not the active session user. This is a latent data corruption bug.

**Fix:**
```php
invalidatePlayerCache($joueur);
initPlayer($joueur);
```

---

### CODE-006 [HIGH] Send Resources Lacks Transaction Protection

**File:** `marche.php:20-143`

**Issue:** The resource send operation (lines 20-143) performs multiple database writes -- inserting an action record (line 95-96), updating sender resources (lines 110-114) -- without wrapping them in a transaction. If the server crashes or a query fails between the INSERT and the UPDATE, the action record exists but the resources are never deducted from the sender. The buy and sell operations later in the file correctly use `withTransaction()`, making this omission inconsistent.

**Impact:** Race condition can duplicate resources. Under concurrent requests, a player could send resources multiple times before their balance is actually decremented. This is exploitable.

**Fix:** Wrap lines 95-115 in `withTransaction($base, function() use (...) { ... })` consistent with the buy/sell blocks.

---

### CODE-007 [HIGH] Duplicateur Bonus Formula Mismatch

**File:** `includes/basicprivatehtml.php:327`

**Issue:** Line 327 computes the duplicateur bonus as `1+((0.1*$duplicateur['duplicateur'])/100)`, which for level 10 gives `1 + (0.1*10)/100 = 1.01` (1% bonus). However, the same calculation in `player.php:218` and `combat.php:49` uses `1 + ($duplicateur['duplicateur'] / 100)`, which for level 10 gives `1.10` (10% bonus). The basicprivatehtml.php formula applies an extra `0.1` multiplier that makes the displayed bonus 10x smaller than the actual combat/production bonus.

**Impact:** Players see a misleading duplicateur bonus in the UI navigation that is 10x lower than the actual effect applied in combat and production calculations. This directly misleads alliance upgrade investment decisions.

**Fix:** Align the formula with other files:
```php
$bonusDuplicateur = 1 + ($duplicateur['duplicateur'] / 100);
```

---

### CODE-008 [HIGH] Dead Code: First storeCaret Function Entirely Overwritten

**File:** `includes/bbcode.php:5-87`

**Issue:** A `storeCaret(selec)` function is defined on lines 5-87 (83 lines of code). It is immediately overwritten by a second `function storeCaret(ao_txtfield,as_mf)` on lines 89-140, which has a different signature (2 parameters vs 1). In JavaScript, the second function definition silently replaces the first. The first function is 100% dead code that has been shipping for years.

**Impact:** 83 lines of unreachable code increase file size and confuse any developer trying to understand the BBCode editor behavior. The different signatures suggest a botched refactor where the old version was never cleaned up.

**Fix:** Delete lines 5-87 entirely.

---

### CODE-009 [HIGH] Undefined Variable Reference in BBCode IE Branch

**File:** `includes/bbcode.php:132`

**Issue:** Line 132 references `as_mef.length` but the function parameter is named `as_mf`. This is a typo that causes a `ReferenceError` in the IE code path. While IE is obsolete, the pattern indicates code was copy-pasted without review and may contain other similar errors in IE branches of other storeCaret-like functions.

**Impact:** Runtime JavaScript error in the IE code path. The IE detection via `document.all` is itself a 15-year-old anti-pattern. Indicates no testing was ever done on this code path.

**Fix:** Either fix `as_mef` to `as_mf` on line 132, or preferably delete all IE-specific branches since IE has been end-of-life since 2022.

---

### CODE-010 [HIGH] Debug Echo Left in Production

**File:** `allianceadmin.php:258`

**Issue:** Line 258 contains `echo $nbDeclarations['nbDeclarations'];` which outputs raw debug data directly into the HTML page during war declaration processing. This is visible to end users in production.

**Impact:** Leaks internal state to users. Breaks HTML layout. Indicates the war declaration code path was not properly tested before deployment.

**Fix:** Delete line 258.

---

### CODE-011 [MEDIUM] Defender Molecule UPDATE Not Looped

**File:** `includes/combat.php:354-357`

**Issue:** Four nearly identical UPDATE statements update defender molecule counts individually using hardcoded indices 1-4 instead of a loop. These are tightly coupled to CODE-001's variable variables.

**Impact:** Must edit 4 lines if the number of classes changes. Easy to introduce copy-paste errors (wrong index on one line).

**Fix:** Use a loop (requires CODE-001 array refactor first):
```php
for ($i = 1; $i <= $nbClasses; $i++) {
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di',
        ($classesDefenseur[$i]['nombre'] - $classesMortDefenseur[$i]),
        $classesDefenseur[$i]['id']);
}
```

---

### CODE-012 [MEDIUM] Pillage Calculation Uses Hardcoded Class References

**File:** `includes/combat.php:380-383`

**Issue:** The pillage capacity calculation explicitly references `$classeAttaquant1` through `$classeAttaquant4` and `$classe1AttaquantMort` through `$classe4AttaquantMort` instead of using a loop. Same pattern as CODE-011.

**Impact:** Maintenance burden and fragility if class count changes.

**Fix:** Replace with a loop over `$nbClasses`.

---

### CODE-013 [MEDIUM] Building Damage Blocks Are 90% Identical

**File:** `includes/combat.php:472-523`

**Issue:** Four nearly identical code blocks handle building damage for generateur (472-484), champdeforce (485-496), producteur (498-509), and depot (511-523). Each block follows the exact same pattern: compute damage percentage string, check if damage exceeds HP, call `diminuerBatiment()` or update HP via SQL. The only differences are the column names and the HP function used.

**Impact:** 52 lines of duplicated code. Any logic change must be applied four times.

**Fix:** Extract to a helper function:
```php
function applyBuildingDamage($base, $buildingName, $damage, $currentHP, $maxHP, $login) { ... }
```

---

### CODE-014 [MEDIUM] Hydrogen Destruction First Calculation Is Dead Code

**File:** `includes/combat.php:413-416`

**Issue:** `$hydrogeneTotal` is calculated on lines 413-416 using hardcoded class references with pre-combat molecule counts. It is immediately overwritten on lines 431-434 using a loop with surviving counts. The first calculation is dead code.

**Impact:** Lines 413-416 are unreachable dead code that executes needlessly on every combat resolution.

**Fix:** Delete lines 413-416.

---

### CODE-015 [MEDIUM] Dead Code: Reaction Bonus No-Ops

**File:** `includes/combat.php:175,180`

**Issue:** Two lines add zero to variables:
```php
if (isset($bonuses['defense'])) $attReactionAttackBonus += 0;
if (isset($bonuses['attack'])) $defReactionDefenseBonus += 0;
```
These are no-ops with comments explaining attackers/defenders do not use certain bonuses.

**Impact:** Confusing to maintainers. The `isset()` check runs needlessly.

**Fix:** Remove both lines. Preserve comments as standalone documentation if desired.

---

### CODE-016 [MEDIUM] Building Queue Lookup Repeated 9 Times

**File:** `includes/player.php:249-322`

**Issue:** Nine consecutive blocks follow the identical pattern:
```php
if (isset($queuedNiveaux['X'])) {
    $niveauActuelX = ['niveau' => $queuedNiveaux['X']];
} else {
    $niveauActuelX = ['niveau' => $constructions['X']];
}
```
Each uses a differently named variable (`$niveauActuel`, `$niveauActuel1`, `$niveauActuelDepot`, etc.).

**Impact:** 74 lines of repetitive code. Inconsistent variable naming creates confusion.

**Fix:** Use an associative array built in a loop:
```php
$buildingNames = ['generateur','producteur','depot','champdeforce','ionisateur','condenseur','lieur','stabilisateur','coffrefort'];
$niveauActuel = [];
foreach ($buildingNames as $name) {
    $niveauActuel[$name] = $queuedNiveaux[$name] ?? $constructions[$name] ?? 0;
}
```

---

### CODE-017 [MEDIUM] Member List Query Executed 3-4 Times in allianceadmin.php

**File:** `allianceadmin.php:354,365,398`

**Issue:** The query `SELECT login FROM autre WHERE idalliance=?` is executed separately for each admin section (change chief at line 354, ban member at line 365, create grade at line 398). Each execution builds an identical dropdown string. Multiple DB round-trips for the same data on a single page load.

**Impact:** 3-4 redundant database queries per alliance admin page load.

**Fix:** Execute the query once at the top and reuse the HTML options string.

---

### CODE-018 [MEDIUM] Pagination Code Duplicated 4 Times

**File:** `classement.php:208-234,391-416,469-494,606-631`

**Issue:** The pagination HTML generation (~27 lines) is copy-pasted four times, once for each ranking tab (players, alliances, wars, forum). The only difference is the `$adresse` URL base.

**Impact:** 108 lines of duplicated code. Any pagination fix or style change must be applied four times.

**Fix:** Extract to a function:
```php
function renderPagination($adresse, $page, $nombreDePages) { ... }
```

---

### CODE-019 [MEDIUM] Hardcoded Time Constants in Building Config

**File:** `includes/player.php:259,272,376,394,411,429,444,460`

**Issue:** Building construction times use hardcoded magic numbers: `60` (generateur), `40` (producteur), `80` (depot), `20` (champdeforce, ionisateur), `120` (condenseur, stabilisateur), `100` (lieur). These are embedded in `pow()` expressions. While cost constants have been extracted to `$BUILDING_CONFIG`, time base multipliers have not.

**Impact:** Cannot tune building times without editing player.php. Invisible to config-based balancing.

**Fix:** Add time base constants to `$BUILDING_CONFIG` in config.php.

---

### CODE-020 [MEDIUM] Hardcoded 2678400 (31 Days in Seconds)

**File:** `marche.php:6`, `includes/player.php:10,22`

**Issue:** The magic number `2678400` (31 days in seconds) is used to determine active player thresholds in multiple files.

**Impact:** If the activity window needs to change, every hardcoded instance must be found and updated.

**Fix:** Replace with `ACTIVE_PLAYER_THRESHOLD` config constant.

---

### CODE-021 [MEDIUM] Hardcoded 0.15 Attack Energy Cost Factor

**File:** `attaquer.php:18`

**Issue:** `$coutPourUnAtome = 0.15 * (1 - $bonus / 100);` uses a hardcoded `0.15` factor determining the energy cost of launching attacks.

**Impact:** Attack cost tuning requires editing game page PHP instead of centralized config.

**Fix:** Define `ATTACK_ENERGY_COST_FACTOR` in config.php.

---

### CODE-022 [MEDIUM] Hardcoded 0.3 Market Volatility

**File:** `marche.php:7`

**Issue:** `$volatilite = 0.3 / max(1, $actifs['nbActifs']);` uses a hardcoded `0.3` numerator for market price volatility.

**Impact:** Cannot tune market volatility without editing game logic code.

**Fix:** Define `MARKET_VOLATILITY_BASE` in config.php.

---

### CODE-023 [MEDIUM] Distance Calculation Repeated Without Helper

**File:** `marche.php:89`, `attaquer.php` (multiple locations)

**Issue:** The Euclidean distance formula `pow(pow($x1-$x2,2)+pow($y1-$y2,2),0.5)` is repeated in multiple files with no helper function.

**Impact:** Inconsistency risk if the distance formula needs modification.

**Fix:** Add to a math helpers module:
```php
function distanceJoueurs($x1, $y1, $x2, $y2): float {
    return sqrt(pow($x1 - $x2, 2) + pow($y1 - $y2, 2));
}
```

---

### CODE-024 [MEDIUM] N+1 Query in Attack Cost JavaScript Generation

**File:** `attaquer.php:457`

**Issue:** Inside a loop generating JavaScript, `dbFetchOne()` is called once per molecule class (line 457). With 4 classes, this executes 4 queries that could be a single query.

**Impact:** 4 DB queries instead of 1 per page load of the attack screen.

**Fix:** Fetch all molecule classes in a single query before the loop.

---

### CODE-025 [MEDIUM] item() Function Is 190 Lines With 15+ Options

**File:** `includes/ui_components.php:129-321`

**Issue:** The `item()` function accepts an associative array and checks for 15+ different keys via `array_key_exists()`, each triggering different HTML generation paths. It is 190 lines long with deeply nested conditionals.

**Impact:** Difficult to test, debug, or extend.

**Fix:** Split into specialized functions (`formItem()`, `selectItem()`, `inputItem()`) or use a builder pattern.

---

### CODE-026 [MEDIUM] Mixed Echo/Return Pattern in UI Components

**File:** `includes/ui_components.php` (various functions)

**Issue:** UI functions inconsistently use `echo` vs `return`. `debutCarte()`, `finCarte()` echo directly. `chip()`, `submit()` return strings. This means callers must know which functions echo and which return.

**Impact:** Unpredictable output ordering. Cannot compose components that echo with those that return.

**Fix:** Standardize all UI functions to return strings. The caller decides when to echo.

---

### CODE-027 [MEDIUM] Molecule Decay Logic Not Extracted

**File:** `includes/game_actions.php:94-103`

**Issue:** The molecule decay calculation during attacks (applying `coefDisparition` over travel time) is implemented inline. This decay logic is not extracted into a reusable function.

**Impact:** If the decay formula changes, it must be updated in multiple places.

**Fix:** Extract to `applyTravelDecay($molecules, $travelSeconds, $attacker, $classIndex)`.

---

### CODE-028 [MEDIUM] Combat Report HTML Mixed With Game Logic

**File:** `includes/game_actions.php:110-310`

**Issue:** After `include("includes/combat.php")` on line 109, approximately 200 lines of inline HTML string building generate the combat report, interleaved with game state updates.

**Impact:** Cannot change report format without touching game logic. Cannot test report generation independently.

**Fix:** Extract report generation to a dedicated function accepting combat results as parameters.

---

### CODE-029 [LOW] BBCode IE Detection via document.all

**File:** `includes/bbcode.php:90,143,189,246`

**Issue:** Multiple functions use `var isIE = (document.all);` to detect IE and branch into IE-specific code using `document.selection.createRange()`. IE has been end-of-life since June 2022.

**Impact:** ~200 lines of unreachable code.

**Fix:** Remove all `document.all` detection and IE-specific branches.

---

### CODE-030 [LOW] Grade Permissions Parsed via String Split

**File:** `allianceadmin.php:27`

**Issue:** Grade permissions stored as dot-separated string (e.g., `"1.0.1.0"`) parsed via `explode()`. Each position is a boolean permission. Adding new permissions requires updating every existing grade string.

**Impact:** Cannot add permissions without DB migration. Off-by-one errors are silent.

**Fix:** Define named constants for permission indices:
```php
define('PERM_INVITE', 0);
define('PERM_WAR', 1);
define('PERM_PACT', 2);
define('PERM_BAN', 3);
```

---

### CODE-031 [LOW] Inconsistent Naming of Building Level Variables

**File:** `includes/player.php:250-322`

**Issue:** Nine building queue lookup blocks use inconsistently named variables: `$niveauActuel` (generateur), `$niveauActuel1` (producteur), `$niveauActuelDepot`, `$niveauActuelChampDeForce`, `$niveauActuelIonisateur`, etc. The first two use numeric suffixes while the rest use building names.

**Impact:** Cognitive load when reading `$listeConstructions` which references these variables.

**Fix:** Use consistent naming via the associative array approach in CODE-016.

---

### CODE-032 [LOW] BBCode() Applies 30+ Regex Replacements Sequentially

**File:** `includes/bbcode.php` (BBCode function)

**Issue:** The `BBCode()` function applies over 30 `preg_replace()` calls sequentially with no early exit for empty strings and no batching.

**Impact:** Performance concern for large text inputs.

**Fix:** Use `preg_replace()` with arrays of patterns and replacements in a single call.

---

### CODE-033 [LOW] Alliance Rank Calculated by Fetching All Alliances

**File:** `alliance.php:170-178`

**Issue:** To display the current alliance's rank, all alliances are fetched and iterated instead of using a SQL COUNT query.

**Impact:** Performance degrades linearly with alliance count. Currently negligible but architecturally wrong.

**Fix:**
```php
$rank = dbFetchOne($base, 'SELECT COUNT(*)+1 AS rang FROM alliances WHERE totalPoints > (SELECT totalPoints FROM alliances WHERE id=?)', 'i', $allianceId);
```

---

### CODE-034 [LOW] No Type Hints on Any Function

**File:** All 14 files reviewed

**Issue:** No function uses PHP type declarations (parameter types, return types). PHP 8.2 fully supports union types, nullable types, and return type declarations.

**Impact:** No compile-time type safety. Wrong-type errors manifest as runtime warnings.

**Fix:** Incrementally add type hints starting with most-called functions: `initPlayer`, `dbFetchOne`, `augmenterBatiment`.

---

### CODE-035 [LOW] Loose Comparisons Throughout Codebase

**File:** `includes/player.php:12`, `marche.php:50`, `includes/combat.php:372`, and many others

**Issue:** The codebase consistently uses `==` instead of `===`. In PHP, `==` performs type juggling where `"1" == true` evaluates to true.

**Impact:** Potential for subtle bugs when database values return as strings instead of integers.

**Fix:** Gradually replace `==` with `===` starting with security-sensitive comparisons.

---

### CODE-036 [LOW] Inline HTML/JS in PHP Business Logic

**File:** `includes/player.php:196-236`, `includes/game_actions.php:110-310`

**Issue:** Business logic functions contain HTML string building and inline `<script>` tag generation. `initPlayer()` generates construction UI HTML with embedded JavaScript event handlers. `updateActions()` generates combat report HTML inline.

**Impact:** Cannot modify UI without touching business logic. Violates separation of concerns.

**Fix:** Separate data computation from HTML rendering. Functions should return data structures.

---

### CODE-037 [LOW] function_exists Guard for checkReactions

**File:** `includes/combat.php:131-153`

**Issue:** `checkReactions()` is wrapped in `if (!function_exists('checkReactions'))` to prevent redefinition errors from multiple includes. This is a workaround for combat.php being a script, not a module.

**Impact:** Code smell. If the function is accidentally defined elsewhere, the guard silently uses the wrong implementation.

**Fix:** Addressed by CODE-002. Once combat.php is function-based, `include_once` can be used safely.

---

### CODE-038 [LOW] Tutorial Missions Hardcode Reward Values

**File:** `tutoriel.php`

**Issue:** All 7 tutorial missions hardcode `'recompense_energie' => 500`. Mission tracking uses a magic offset `19` for the bitmask position.

**Impact:** Cannot tune tutorial rewards without editing PHP.

**Fix:** Define `TUTORIAL_ENERGY_REWARD` and `TUTORIAL_MISSION_OFFSET` in config.php.

---

### CODE-039 [LOW] $messagePlus Variable Reused for Two Purposes

**File:** `includes/basicprivatehtml.php:261,280`

**Issue:** `$messagePlus` is first used for unread message count (line 261), then repurposed for forum notification badge (line 280).

**Impact:** Confusing data flow. Code added between these lines could read the wrong value.

**Fix:** Use distinct variable names: `$unreadMessages` and `$forumBadge`.

---

### CODE-040 [LOW] Dynamic SQL Column Names in Resource Updates

**File:** `marche.php:106-110`

**Issue:** The send-resources UPDATE query builds column names dynamically via string interpolation from `$nomsRes`. While `$nomsRes` is server-side (safe), values are interpolated directly into SQL rather than parameterized. The energy portion uses prepared statements, creating inconsistency.

**Impact:** Currently safe but fragile. A future developer could introduce user input into interpolated values.

**Fix:** Use the dynamic prepared statement pattern from combat.php lines 579-590.

---

### CODE-041 [LOW] Global $base Coupling Across All Files

**File:** All 14 files reviewed

**Issue:** Every file uses `global $base;` to access the MySQLi connection. Function signatures do not declare their database dependency.

**Impact:** Cannot substitute a test database connection. Invisible which functions perform I/O.

**Fix:** Long-term: pass `$base` as a parameter. This is architectural and consistent, so low risk currently.

---

### CODE-042 [LOW] initPlayer Writes 20+ Global Variables

**File:** `includes/player.php:124-148`

**Issue:** `initPlayer()` declares and writes to over 20 global variables including `$ressources`, `$revenu`, `$constructions`, `$autre`, `$membre`, `$revenuEnergie`, `$placeDepot`, `$points`, `$plusHaut`, `$production`, `$productionCondenseur`, `$listeConstructions`, plus 16 resource-specific variables.

**Impact:** No explicit contract of what data is available after calling `initPlayer()`. Impossible to reason about data flow.

**Fix:** Return a structured array from `initPlayer()`:
```php
function initPlayer($joueur): array {
    return ['ressources' => $r, 'revenu' => $rev, 'constructions' => $c, ...];
}
```

---

## Priority Recommendations

### Immediate (Next Sprint)
1. **CODE-005** - Fix `$_SESSION['login']` bug in `augmenterBatiment` (data corruption risk)
2. **CODE-006** - Add transaction to resource sends (exploit risk)
3. **CODE-007** - Fix duplicateur formula mismatch (misleading UI)
4. **CODE-010** - Remove debug echo (production data leak)
5. **CODE-004** - Replace hardcoded `4` with `$nbClasses`

### Short-Term (1-2 Sprints)
6. **CODE-008** + **CODE-009** + **CODE-029** - Clean up bbcode.php dead code and IE branches
7. **CODE-014** + **CODE-015** - Remove dead code in combat.php
8. **CODE-017** - Deduplicate alliance member queries
9. **CODE-018** - Extract pagination helper
10. **CODE-019** + **CODE-020** + **CODE-021** + **CODE-022** - Extract remaining magic numbers to config

### Medium-Term (Refactoring Phase)
11. **CODE-001** + **CODE-011** + **CODE-012** - Replace variable variables with arrays
12. **CODE-002** - Extract combat into a testable function
13. **CODE-003** + **CODE-042** - Split initPlayer into focused functions
14. **CODE-016** + **CODE-031** - Simplify building queue lookups
15. **CODE-025** + **CODE-026** - Refactor UI components

---

## Metrics

| Category | Finding Count |
|----------|--------------|
| Code Duplication | 8 (CODE-011, 012, 013, 014, 016, 017, 018, 023) |
| Function Complexity | 4 (CODE-002, 003, 025, 028) |
| Dead Code | 5 (CODE-008, 009, 010, 014, 015) |
| Naming Consistency | 3 (CODE-031, 033, 039) |
| Coupling | 4 (CODE-002, 029, 041, 042) |
| Error Handling | 1 (CODE-006) |
| Magic Values | 6 (CODE-004, 019, 020, 021, 022, 038) |
| PHP Best Practices | 6 (CODE-001, 005, 007, 034, 035, 036) |
| Variable Variables | 1 (CODE-001, affects 80+ lines) |
| Mixed Concerns | 4 (CODE-003, 026, 028, 036) |

**Files with most findings:**
1. includes/combat.php - 9 findings
2. includes/player.php - 8 findings
3. marche.php - 4 findings
4. includes/bbcode.php - 4 findings
5. includes/game_actions.php - 3 findings
6. classement.php - 1 finding
7. allianceadmin.php - 3 findings
8. attaquer.php - 3 findings

---

*Report generated from manual code review of 14 files totaling ~7,500 lines of PHP.*
*File `tableau.php` was requested but does not exist in the project directory.*
