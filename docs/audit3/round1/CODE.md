# Code Quality & Maintainability Audit - Round 1

**Audit Date:** 2026-03-03
**Scope:** 15 largest PHP files in /home/guortates/TVLW/The-Very-Little-War/
**Focus:** Dead code, code duplication, variable variables, global state abuse, magic numbers, overly complex functions, tight coupling, PHP 8.2 deprecations, legacy patterns

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 5     |
| HIGH     | 22    |
| MEDIUM   | 28    |
| LOW      | 18    |
| **Total** | **73** |

---

## CRITICAL Findings

### Dead Code / Bugs

[CODE-R1-001] CRITICAL bbcode.php:5-87 — First `storeCaret()` function definition (83 lines) is completely dead code. It is overwritten by the second definition at lines 89-140. The entire first block including `storeCaret`, `doAddTags`, `colorpalette`, and `emotpalette` functions is silently replaced.

[CODE-R1-002] CRITICAL bbcode.php:132,184,241,299 — Variable `as_mef` is referenced but never defined (typo for `as_met` or similar). This causes PHP warnings on every BBCode toolbar render: `$as_mef` is undefined, resulting in empty onclick handlers in the generated HTML buttons.

[CODE-R1-003] CRITICAL alliance.php:16 — `mysqli_num_rows($ex)` is called after `$allianceJoueur = mysqli_fetch_array($ex)` has already consumed the result set. On a single-row result, `mysqli_num_rows` may return 1 but the cursor is already advanced. This is fragile and may cause incorrect behavior if the result set handling changes.

[CODE-R1-004] CRITICAL allianceadmin.php:258 — Debug output `echo $nbDeclarations['nbDeclarations'];` left in production code. This leaks internal data (declaration count) directly into the HTML output visible to users.

[CODE-R1-005] CRITICAL armee.php:179 — `$ressource = $_POST[$ressource]` uses the loop variable `$ressource` as both the POST key and the assignment target, overwriting the iterator. This is a variable-variable-like pattern that makes the code extremely difficult to follow and audit for injection.

---

## HIGH Findings

### Variable Variables ($var)

[CODE-R1-006] HIGH combat.php:148-170 — Extensive variable variable usage for attacker/defender class data: `${'classeAttaquant' . $c}`, `${'classeDefenseur' . $c}`. Creates 8+ dynamic variables per combat round. Should use associative arrays `$attacker['classe'][$c]` instead.

[CODE-R1-007] HIGH combat.php:230-260 — Variable variables for combat casualties: `${'classe' . $i . 'AttaquantMort'}`, `${'classe' . $i . 'DefenseurMort'}`. Creates up to 8 dynamic variable names per side. Unmaintainable and impossible to statically analyze.

[CODE-R1-008] HIGH combat.php:340-380 — Variable variables for pillage amounts: `${$ressource . 'Pille'}`, `${'rapport' . $ressource}`. Creates dynamic variables from resource name strings, mixing data with variable names.

[CODE-R1-009] HIGH combat.php:400-430 — Variable variables for HP tracking: `${'attHP' . $i}`, `${'defHP' . $i}`. Up to 8 dynamic HP variables per side instead of using arrays.

[CODE-R1-010] HIGH player.php:340-380 — Variable variables in resource calculations: `${'revenu' . $ressource}`, `${'points' . $ressource}`, `${'niveau' . $ressource}`. Creates 15+ dynamic variables from resource names. Should use `$revenue[$ressource]`, `$points[$ressource]` arrays.

[CODE-R1-011] HIGH game_actions.php:380-420 — Variable variables inherited from combat.php scope: `${$ressource . 'Pille'}`, `${'classe' . $i}`. Combat include leaks dynamic variables into game_actions scope.

[CODE-R1-012] HIGH marche.php:540-570 — Variable variables in resource transfer section: `${'rapport' . $ressource}` used to build dynamic variable names from resource strings.

### Code Duplication

[CODE-R1-013] HIGH classement.php:209-234,391-416,469-494,606-631 — Pagination HTML block duplicated verbatim 4 times (26 lines each, ~104 lines total). Only the `$page` variable name and URL parameter differ. Should be extracted to a `renderPagination($currentPage, $totalPages, $urlParam)` function.

[CODE-R1-014] HIGH combat.php:472-523 — Building damage code duplicated 4 times for generateur, champdeforce, producteur, and depot. Each block follows identical pattern: check level > minimum, calculate damage, execute UPDATE, set combat report flag. Should be a loop over building types.

[CODE-R1-015] HIGH marche.php:80-180,200-300 — Buy and sell transaction logic is nearly identical (~100 lines each). Both: validate input, check resources, calculate price impact, execute transaction, update prices, insert history. Should be refactored to a single `executeMarketTransaction($type, $resource, $quantity)` function.

[CODE-R1-016] HIGH marche.php:106-110,272-276 — Price update SQL logic duplicated in both buy and sell blocks. The UPDATE statement for adjusting market prices follows the same pattern with only the direction (+ vs -) differing.

[CODE-R1-017] HIGH attaquer.php:100-130,200-230,350-380 — Timer countdown JavaScript duplicated 3+ times with identical pattern. Each attack/espionage action has its own copy of the same countdown timer code. Should be a single reusable JS function.

[CODE-R1-018] HIGH attaquer.php:50-60,150-160,250-260,400-410 — Distance calculation `pow(pow($x1-$x2, 2) + pow($y1-$y2, 2), 0.5)` duplicated 4 times. Should be extracted to a `calculateDistance($x1, $y1, $x2, $y2)` helper function.

[CODE-R1-019] HIGH allianceadmin.php:354-356,365-367,398-400 — Alliance member dropdown query `SELECT login FROM joueurs WHERE alliance = ?` executed 3 separate times with identical parameters for 3 different dropdowns on the same page. Should query once and reuse the result.

### Overly Complex Functions

[CODE-R1-020] HIGH player.php:50-450 — `initPlayer()` is approximately 400 lines long. It loads player data from DB, computes 20+ derived values, builds 9 building configuration arrays with inline HTML/JS generation, and sets 20+ global variables. This function has at least 5 distinct responsibilities and should be decomposed into: `loadPlayerData()`, `computePlayerStats()`, `buildBuildingConfigs()`, `initGlobalState()`.

[CODE-R1-021] HIGH game_actions.php:1-543 — `updateActions()` is a single ~540-line function handling all game action types (construction, formation, attack, espionage, resource transfer, demolition). Each action type is a distinct block that could be its own function. Cyclomatic complexity is extremely high.

[CODE-R1-022] HIGH ui_components.php:1-190 — `item()` function is ~190 lines with deeply nested conditionals (4-5 levels deep) and numerous `array_key_exists` checks. Should be decomposed into sub-renderers: `renderItemMedia()`, `renderItemTitle()`, `renderItemSubtitle()`, `renderItemAfter()`.

### Tight Coupling

[CODE-R1-023] HIGH combat.php:1-633 — Entire 633-line file is a procedural include with no functions (except one guarded by `function_exists`). It is included inside `game_actions.php`'s `updateActions()` function via `include()`, sharing the parent scope entirely. All variables flow in and out implicitly. Should be refactored to a `resolveCombat($attacker, $defender, $config)` function that takes explicit parameters and returns a result array.

[CODE-R1-024] HIGH game_actions.php:300 — `include('combat.php')` inside a function body shares the entire local scope with the included file. Combat.php reads and writes dozens of variables from the parent scope without any explicit interface. This makes both files impossible to test or modify independently.

### XSS / Output Escaping

[CODE-R1-025] HIGH allianceadmin.php:428-429 — `$listeGrades['login']` and `$listeGrades['nom']` are output directly in HTML without `htmlspecialchars()`. Alliance grade names and player logins could contain malicious content.

[CODE-R1-026] HIGH alliance.php:196 — `$grades['login']` output in HTML without `htmlspecialchars()`. Player login names are user-controlled input.

[CODE-R1-027] HIGH basicprivatehtml.php:327 — Hardcoded `0.1` for duplicateur bonus calculation instead of using the constant `DUPLICATEUR_BONUS_PER_LEVEL` from config.php. If the balance constant changes, this display will show incorrect values to users, creating a gameplay bug.

---

## MEDIUM Findings

### Magic Numbers

[CODE-R1-028] MEDIUM marche.php:6 — `2678400` hardcoded (31 days in seconds) for active player threshold. Should use a named constant like `ACTIVE_PLAYER_THRESHOLD_SECONDS` in config.php.

[CODE-R1-029] MEDIUM marche.php:7 — `0.3` hardcoded for market volatility factor. Should use `MARKET_VOLATILITY_FACTOR` from config.php.

[CODE-R1-030] MEDIUM marche.php:272 — `0.95` hardcoded for sell tax rate (5% tax). Should use `MARKET_SELL_TAX_RATE` from config.php.

[CODE-R1-031] MEDIUM attaquer.php:18 — `0.15` hardcoded for attack energy cost factor. Should use `ATTACK_ENERGY_COST_FACTOR` from config.php.

[CODE-R1-032] MEDIUM armee.php:162 — `200` hardcoded for maximum atoms per element. Should use `MAX_ATOMS_PER_ELEMENT` from config.php.

[CODE-R1-033] MEDIUM combat.php:205 — Hardcoded `4` for molecule class count instead of using the existing `$nbClasses` variable or `NB_MOLECULE_CLASSES` constant.

[CODE-R1-034] MEDIUM player.php:250-322 — Building level lookup pattern repeated 9 times with identical structure. Each building block repeats `dbFetchOne("SELECT niveau...")` with only the table/column name differing. The `$BUILDING_CONFIG` array exists in config.php but is not leveraged for this initialization loop.

### Global State Abuse

[CODE-R1-035] MEDIUM player.php:50-90 — `initPlayer()` sets 20+ global variables (`$login`, `$energie`, `$hydrogene`, `$carbone`, `$azote`, `$oxygene`, `$chlore`, `$soufre`, `$brome`, `$iode`, `$prestige`, `$alliance`, `$x`, `$y`, etc.). Every page depends on this global state. Should return a `$player` associative array instead.

[CODE-R1-036] MEDIUM game_actions.php:5-10 — Six `global` declarations at the top of `updateActions()`: `global $base, $login, $x, $y, $energie, $prestige`. Functions should receive parameters explicitly.

[CODE-R1-037] MEDIUM combat.php:131 — `global $CHEMICAL_REACTIONS` used to access config array. Should be passed as a parameter to a combat function.

[CODE-R1-038] MEDIUM basicprivatehtml.php:1-463 — Entire file relies on global variables set by `initPlayer()`. Over 30 global variables are read throughout the file without any explicit dependency declaration.

### Dead Code

[CODE-R1-039] MEDIUM player.php:780-791 — `miseAJour()` function appears to be a subset of what `initPlayer()` already does. If it is not called anywhere, it is dead code. If it is called, it duplicates initPlayer logic.

[CODE-R1-040] MEDIUM game_actions.php:49,58 — Commented-out code lines left in the file. Should be removed; version control preserves history.

[CODE-R1-041] MEDIUM combat.php:175,180 — Dead arithmetic operations `+= 0` for cross-side chemical bonuses. These lines compute a value that is always zero and add it to the totals. They appear to be placeholders for unimplemented cross-side bonus logic.

[CODE-R1-042] MEDIUM admin/tableau.php:1-792 — Entire 792-line file appears to be a generic CMS/table-editor admin page that is not specific to the game. If it is unused or was copied from another project, it is dead code. Requires verification of whether it is linked from any admin interface.

### Code Duplication (continued)

[CODE-R1-043] MEDIUM classement.php:100-150,300-350 — War and pact data loading logic is duplicated between the player ranking tab and the alliance ranking tab. Both sections load wars and pacts with nearly identical queries and processing.

[CODE-R1-044] MEDIUM game_actions.php:94-103,449-465 — Molecule decay logic (removing molecules when atoms are depleted) is duplicated in two separate locations within the same function.

[CODE-R1-045] MEDIUM player.php:250-322 — Building initialization pattern duplicated 9 times. Each block follows: query DB for building level, compute derived stats, build config array. A loop over `$BUILDING_CONFIG` keys would eliminate ~65 lines.

### Legacy Patterns

[CODE-R1-046] MEDIUM bbcode.php:89 — `<script language="Javascript">` uses the deprecated `language` attribute. Should be `<script>` or `<script type="text/javascript">`.

[CODE-R1-047] MEDIUM allianceadmin.php:130,280,350 — `<script LANGUAGE="JavaScript">` deprecated attribute used in multiple locations. Should be plain `<script>` tags.

[CODE-R1-048] MEDIUM alliance.php:100,200 — `<script LANGUAGE="JavaScript">` deprecated attribute used. Same pattern as allianceadmin.php.

[CODE-R1-049] MEDIUM bbcode.php:95-140 — IE-specific JavaScript: `document.all` detection, `document.selection.createRange()` for text selection. Internet Explorer is dead (end of life June 2022). This code path will never execute in modern browsers but adds complexity and confusion.

[CODE-R1-050] MEDIUM combat.php:131 — `if (!function_exists('checkReactions'))` guard used to prevent redefinition errors from multiple includes. This is an anti-pattern; the file should use `include_once` or `require_once`, or better yet, be restructured as a proper function file.

### Dynamic SQL Column Names

[CODE-R1-051] MEDIUM marche.php:106,110 — Dynamic SQL column interpolation in UPDATE statements. Column names are derived from `$ressource` variable. While currently sourced from a whitelist, this pattern is fragile and one refactor away from SQL injection.

[CODE-R1-052] MEDIUM armee.php:131,207 — Dynamic SQL column interpolation using resource names in UPDATE/SELECT statements. Same risk profile as marche.php.

[CODE-R1-053] MEDIUM player.php:600-650 — `augmenterBatiment()` and `diminuerBatiment()` use string interpolation for column/table names in SQL queries. Column names come from building config but are not validated against a whitelist at point of use.

### Miscellaneous Quality Issues

[CODE-R1-054] MEDIUM classement.php:378 — Syntax issue in HTML output: `</td>'; ?>` appears to have misplaced content that could cause rendering issues in the rankings table.

[CODE-R1-055] MEDIUM basicprivatehtml.php:264,280 — Variable `$messagePlus` is reused for both unread message badge and forum notification badge. This overloading makes the code confusing and could lead to display bugs if the rendering order changes.

---

## LOW Findings

### Minor Code Smells

[CODE-R1-056] LOW config.php:37 — `$RESOURCE_NAMES` and `$RESOURCE_NAMES_ACCENTED` arrays contain identical values. If they were intended to differ (e.g., one with accents, one without), one of them has a bug. If they are truly identical, one should be removed and the other aliased.

[CODE-R1-057] LOW bbcode.php:74-75,80,133-134 — Variable `r` declared twice in nested scope. The second declaration shadows the first. While JavaScript hoisting handles this, it indicates sloppy code and potential logic errors.

[CODE-R1-058] LOW basicprivatehtml.php:260-290 — Variable `$nb_messages_nonlus` is reused for both private message count and report count in different sections of the navigation sidebar. Should use distinct variable names.

[CODE-R1-059] LOW attaquer.php:457-463 — N+1 query pattern: `dbFetchOne` called inside a loop for each molecule class to get molecule names. Should be a single query with `WHERE classe IN (1,2,3,4)` or pre-loaded into an array.

[CODE-R1-060] LOW ui_components.php:362 — `accordion()` function uses CSS class `item-media` for both `titre` and `contenu` div wrappers. This appears to be a copy-paste error from the `item()` function; accordion content is not "media".

[CODE-R1-061] LOW player.php:1-10 — File lacks a module docblock explaining its purpose, public API, and dependencies. Given its 838-line size and central role, documentation is essential.

[CODE-R1-062] LOW combat.php:1-10 — File lacks any documentation. A 633-line combat resolution engine with no comments explaining the algorithm, expected inputs, or outputs.

[CODE-R1-063] LOW game_actions.php:1-10 — File lacks a module docblock. A 543-line action processor with no high-level documentation of the action types it handles.

### Minor Legacy/Style Issues

[CODE-R1-064] LOW allianceadmin.php:1-539 — Mixed PHP/HTML output style. Some sections use `echo` statements, others use `?>...<?php` escaping. Should standardize on one approach for consistency.

[CODE-R1-065] LOW alliance.php:1-441 — Same mixed PHP/HTML output style inconsistency as allianceadmin.php.

[CODE-R1-066] LOW classement.php:1-636 — Same mixed output style. Additionally, some queries use the `dbFetchAll` helper while others still use raw `mysqli_fetch_array` loops.

[CODE-R1-067] LOW marche.php:1-614 — Inconsistent use of database helpers. Some queries use `dbFetchOne`/`dbFetchAll` while others use raw `mysqli` calls.

[CODE-R1-068] LOW attaquer.php:1-530 — Inline JavaScript mixed with PHP throughout the file. Timer functions, AJAX calls, and DOM manipulation are embedded in PHP echo statements rather than being in a separate JS file.

[CODE-R1-069] LOW tutoriel.php:1-492 — Well-structured file overall. Minor issue: mission definitions could be moved to config.php or a dedicated missions config file for easier balance tuning without code changes.

[CODE-R1-070] LOW basicprivatehtml.php:100-200 — Help popover content (French text strings) is hardcoded in PHP. Should be in a translations/strings file for future i18n support.

[CODE-R1-071] LOW player.php:250-450 — `initPlayer()` generates inline HTML and JavaScript for building configuration tooltips. UI generation should not be in the data initialization layer.

[CODE-R1-072] LOW combat.php:500-600 — Combat report string concatenation builds HTML fragments using `.=` operator in a loop. Should use an array with `implode()` or a template for better performance and readability.

[CODE-R1-073] LOW armee.php:1-445 — File mixes form processing (POST handling) with page rendering. The POST handler at the top should be separated from the display logic below.

---

## Recommendations by Priority

### Immediate (CRITICAL)

1. **Remove dead code in bbcode.php** (CODE-R1-001): Delete lines 5-87 (the overwritten first function block).
2. **Fix `as_mef` typo in bbcode.php** (CODE-R1-002): Identify the correct variable name and fix all 4 occurrences.
3. **Fix result set consumption in alliance.php** (CODE-R1-003): Use `dbCount()` or restructure the query.
4. **Remove debug echo in allianceadmin.php** (CODE-R1-004): Delete line 258.
5. **Fix variable overwrite in armee.php** (CODE-R1-005): Use a different variable name for the POST value.

### Short-Term (HIGH)

1. **Refactor variable variables to arrays** (CODE-R1-006 through CODE-R1-012): This is the single highest-impact refactor. Replace all `${'name' . $i}` patterns with associative arrays. Start with combat.php as it has the densest usage.
2. **Extract duplicated pagination** (CODE-R1-013): Create `renderPagination()` helper in ui_components.php.
3. **Extract market transaction logic** (CODE-R1-015, CODE-R1-016): Single `executeMarketTransaction()` function.
4. **Decompose initPlayer()** (CODE-R1-020): Split into 4-5 focused functions.
5. **Decompose updateActions()** (CODE-R1-021): One function per action type.
6. **Decouple combat.php** (CODE-R1-023, CODE-R1-024): Convert to function-based API.
7. **Fix XSS escaping** (CODE-R1-025, CODE-R1-026): Add `htmlspecialchars()` to all unescaped outputs.
8. **Fix hardcoded duplicateur bonus** (CODE-R1-027): Use `DUPLICATEUR_BONUS_PER_LEVEL` constant.

### Medium-Term (MEDIUM)

1. **Extract magic numbers to config.php** (CODE-R1-028 through CODE-R1-033): Add 6 new constants.
2. **Refactor global state to parameter passing** (CODE-R1-035 through CODE-R1-038): Return `$player` array from initPlayer instead of setting globals.
3. **Remove dead code** (CODE-R1-039 through CODE-R1-042): Clean up unused functions and commented code.
4. **Validate dynamic SQL columns** (CODE-R1-051 through CODE-R1-053): Add explicit whitelist checks at point of use.
5. **Remove IE-specific code from bbcode.php** (CODE-R1-049): Simplify to modern browser APIs only.
6. **Fix deprecated script attributes** (CODE-R1-046 through CODE-R1-048): Replace `language="JavaScript"` with plain `<script>`.

### Long-Term (LOW)

1. **Standardize output style** across all files.
2. **Add module documentation** to core files.
3. **Separate concerns** (POST handling from rendering, data from UI).
4. **Consolidate database access** to use helpers consistently.
5. **Externalize inline JavaScript** to dedicated .js files.

---

## Metrics

| Category | Finding Count |
|----------|--------------|
| Variable Variables ($var) | 7 |
| Code Duplication | 10 |
| Overly Complex Functions | 3 |
| Global State Abuse | 4 |
| Magic Numbers | 7 |
| Dead Code | 5 |
| Tight Coupling | 2 |
| Legacy Patterns / Deprecations | 5 |
| XSS / Output Escaping | 3 |
| Dynamic SQL | 3 |
| Minor Code Smells | 12 |
| Documentation | 3 |
| Mixed Concerns | 9 |

**Files with most findings:**
1. combat.php - 11 findings
2. player.php - 9 findings
3. marche.php - 7 findings
4. bbcode.php - 6 findings
5. classement.php - 5 findings
6. allianceadmin.php - 5 findings
7. game_actions.php - 5 findings

---

*Report generated by code quality audit of 15 files totaling ~7,700 lines of PHP.*
