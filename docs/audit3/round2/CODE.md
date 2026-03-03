# Code Quality Deep-Dive -- Round 2

Audit date: 2026-03-03
Auditor: Claude Opus 4.6 (Code Reviewer Agent)
Scope: 15+ files not covered in Round 1, focusing on dead code, unreachable paths,
       copy-paste duplication, unused variables, PHP 8.2 deprecations, type safety.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3     |
| HIGH     | 11    |
| MEDIUM   | 19    |
| LOW      | 14    |
| **Total**| **47**|

---

## CRITICAL

### CODE-R2-001 | Variable variables throughout codebase (systemic, not just combat.php)
**Severity:** CRITICAL
**Files:** `includes/game_resources.php:18,147-154`, `includes/player.php:132-165,489-491,549-591,789`, `constructions.php:30,68`, `marche.php:71-83`, `includes/combat.php:9-451` (80+ lines), `armee.php:179-204`
**Description:** R1 flagged variable variables (`$$ressource`, `${'name'.$var}`) in combat.php, but the pattern is systemic across at least 7 files with 80+ distinct occurrences. This is a PHP 8.2 soft-deprecation risk (no current deprecation but dynamic property creation on classes is deprecated, and variable variables make static analysis impossible). Every occurrence defeats IDE autocompletion, type inference, and refactoring tools. Some instances generate undefined variable warnings when execution paths skip the assignment (e.g., `$nombre1` through `$nombre4` in `game_resources.php:175` depend on a while loop iterating exactly 4 times).
**Fix:** Replace all `${'name'.$var}` and `$$var` patterns with associative arrays. For example:
```php
// Before (game_resources.php:18):
${'niveau' . $ressource} = $niveauxAtomes[$num];
// After:
$niveaux[$ressource] = $niveauxAtomes[$num];
```
This is a large refactor touching 7+ files but is the single highest-impact code quality improvement available.

---

### CODE-R2-002 | bbcode.php: 313-line dead JavaScript -- IE6/Mozilla-era code never called
**Severity:** CRITICAL
**File:** `includes/bbcode.php:1-313`
**Description:** The first 313 lines of bbcode.php are a `<script>` block containing JavaScript functions (`storeCaret`, `storeCaretNoValueInto`, `storeCaretValue`, `storeCaretIMG`) written for IE6-era `document.selection.createRange()` API. This code:
1. Uses `document.selection` which was removed from all browsers circa 2016.
2. Contains a duplicate `storeCaret` function definition (line 5 vs line 89) -- the second silently overwrites the first.
3. References undefined variable `as_mef` (typo for `as_mf`) at lines 132, 184, 241, 299 -- this would throw `ReferenceError` in any browser.
4. Contains `var r = oField.createTextRange(); var r = ao_txtfield.createTextRange();` -- duplicate `var r` declarations (lines 74-75, 133-134, etc.).
5. Is never called from any PHP file in the codebase. Grep for `storeCaret` finds zero references outside bbcode.php and audit docs.
6. Is loaded on every page that includes `bbcode.php` (forum.php, messages.php, compte.php, alliance.php, etc.) -- wasted bandwidth on every page load.
**Fix:** Delete lines 1-313 entirely (the `<script>` block). The useful `BBCode()` PHP function at lines 315-365 should remain. This removes ~8KB of dead JavaScript from every forum-related page.

---

### CODE-R2-003 | Duplicate DB queries -- "autre" table fetched 36+ times per request
**Severity:** CRITICAL
**Files:** `includes/game_resources.php:23,38`, `includes/formulas.php:86,106,137,188`, `includes/player.php` (14 uses of `global $base`), `includes/prestige.php:60`, `includes/db_helpers.php:60,72`
**Description:** The `autre` table row for the current player is queried via `dbFetchOne($base, 'SELECT ... FROM autre WHERE login=?', ...)` at least 36 times across includes files during a single page load. Each query selects different subsets of columns from the same row. Similarly, `constructions` is queried 25 times. The `initPlayer()` function in player.php does cache some of these via `global` variables, but the formula functions (e.g., `attaque()`, `defense()`, `pillage()`, `coefDisparition()`) each independently query medal data from `autre`, bypassing the cache.

The `revenuEnergie()` function alone (`game_resources.php:7-64`) makes 5 separate DB queries for a single player: constructions (lines 14 and 21), autre (line 23), alliances (line 27), and autre again (line 38). Three of these query the same tables already loaded by `initPlayer()`.
**Fix:** Pass pre-fetched player data objects into formula functions instead of having each function independently query the database. The `$medalData` parameter pattern already exists in `attaque()`, `defense()`, and `pillage()` but callers rarely use it. Enforce it and remove the fallback `global $base` paths. This could eliminate 20+ queries per page load.

---

## HIGH

### CODE-R2-004 | constructions.php: Near-identical copy-paste blocks for Producteur and Condenseur points
**Severity:** HIGH
**File:** `constructions.php:6-80`
**Description:** Lines 6-42 (producteur points) and lines 44-80 (condenseur points) are structurally identical -- same validation loop, same string building, same DB update pattern. The only differences are the field name prefix (`nbPoints` vs `nbPointsCondenseur`) and the DB columns updated. This is a textbook DRY violation.
**Fix:** Extract a generic `processPointsAllocation($prefix, $currentPoints, $remainingColumn, $dataColumn)` function and call it twice with different parameters.

---

### CODE-R2-005 | constructions.php: `$num - 1 < sizeof($nomsRes)` always true -- trailing semicolon bug
**Severity:** HIGH
**File:** `constructions.php:26,64`
**Description:** The condition `if ($num - 1 < sizeof($nomsRes))` is used to decide whether to append a semicolon separator. Since `$num` is the array index (0-7) and `sizeof($nomsRes)` is 8, this evaluates to `$num - 1 < 8`, which is always true for valid indices. The result: every generated string ends with a trailing semicolon (e.g., `"5;3;2;8;1;4;6;7;"`). The same string is read back via `explode(';', ...)` which produces an empty trailing element. While PHP's `intval('')` returns 0 so it does not crash, this is data corruption waiting to cause subtle bugs.
**Fix:** Change to `if ($num < count($nomsRes) - 1)` to correctly omit the trailing separator, matching the pattern used correctly in `constructions.php:243` (`if ($num < $nbRes)`).

---

### CODE-R2-006 | game_resources.php: `$nombre1` through `$nombre4` undefined if fewer than 4 molecules exist
**Severity:** HIGH
**File:** `includes/game_resources.php:170-198`
**Description:** Lines 170-184 use a while loop over molecules with `${'nombre' . ($compteur + 1)} = $molecules['nombre']` to create `$nombre1`, `$nombre2`, etc. Line 191 then uses all four: `($nombre1 - $donnees5['nombre'])`. If a player has fewer than 4 molecule classes (e.g., a new player), `$nombre3` and `$nombre4` will be undefined, generating PHP warnings and incorrect math (undefined variable evaluates to 0 in arithmetic context in PHP 8.x but triggers E_WARNING).
**Fix:** Initialize `$nombre1 = $nombre2 = $nombre3 = $nombre4 = 0;` before the while loop.

---

### CODE-R2-007 | game_resources.php: Redundant DB query for same player's constructions
**Severity:** HIGH
**File:** `includes/game_resources.php:14,21`
**Description:** Line 14 selects `SELECT * FROM constructions WHERE login=?` and line 21 selects `SELECT producteur FROM constructions WHERE login=?` -- both for the same player. The first query already contains the `producteur` column.
**Fix:** Remove line 21 and use `$constructions['producteur']` from the first query.

---

### CODE-R2-008 | game_resources.php: `moleculesPerdues` re-queried inside while loop (N+1)
**Severity:** HIGH
**File:** `includes/game_resources.php:180`
**Description:** Inside the while loop at line 172, `moleculesPerdues` is queried from `autre` on every iteration (line 180), then immediately updated (line 181). With 4 molecule classes, this is 4 SELECT + 4 UPDATE queries when a single accumulator variable and one final UPDATE would suffice.
**Fix:** Accumulate `$totalMoleculesPerdues` in a local variable, then perform one UPDATE after the loop.

---

### CODE-R2-009 | marche.php: 363-line procedural file with massive duplication between buy and sell
**Severity:** HIGH
**File:** `marche.php:1-363`
**Description:** The buy block (lines 145-253) and sell block (lines 255-363) share nearly identical structure: input validation, resource type lookup, transaction with lock, price update with mean reversion, trade point calculation. The price update logic (lines 188-207 vs 301-320) and trade point logic (lines 212-225 vs 325-338) are near-identical copy-paste. Any future change to market mechanics must be made in two places.
**Fix:** Extract `updateMarketPrice($numRes, $quantity, $isBuy)` and `awardTradePoints($energyAmount)` functions.

---

### CODE-R2-010 | game_actions.php: 543-line god function `updateActions()`
**Severity:** HIGH
**File:** `includes/game_actions.php:7-543`
**Description:** `updateActions()` is a single 536-line function handling constructions (27-31), formations (35-61), attacks including full combat resolution and report generation (63-468), and resource deliveries (471-539). The cyclomatic complexity is extreme -- the attack section alone has 5+ levels of nesting. The function includes `combat.php` via `include()` at line 109, which injects ~450 more lines of variable-variable-heavy code into the scope. The combat report HTML generation (lines 150-310) is an enormous string concatenation block mixing game logic with presentation.
**Fix:** Split into `processConstructions()`, `processFormations()`, `processCombat()`, `processDeliveries()`. Extract combat report generation into a dedicated `generateCombatReport()` function.

---

### CODE-R2-011 | game_actions.php: Molecule loss calculation duplicated between attack departure and return
**Severity:** HIGH
**File:** `includes/game_actions.php:90-103,446-465`
**Description:** The molecule decay calculation during attack travel (lines 90-103, the outbound leg) is copy-pasted nearly verbatim for the return leg (lines 446-465). Both iterate through molecule classes, calculate decay via `coefDisparition`, update molecule counts, and update `moleculesPerdues`. Both also have the N+1 anti-pattern of querying `moleculesPerdues` inside the loop.
**Fix:** Extract `applyTravelDecay($player, $molecules, $nbsecondes)` function used for both legs.

---

### CODE-R2-012 | display.php: `chiffrePetit()` while loop can produce incorrect SI suffix chains
**Severity:** HIGH
**File:** `includes/display.php:70-122`
**Description:** The `chiffrePetit()` function uses a while loop with if/elseif chain to convert numbers to SI notation (K, M, G, T, etc.). The logic prepends suffixes: `$derriere = "Y" . $derriere` (line 84). For numbers >= 1e24, this could theoretically produce concatenated suffixes like "YZ" if the while loop iterates multiple times. In practice, PHP float precision prevents this, but the code is fragile and hard to verify. More critically, the function uses `floor()` on the input which silently truncates negative numbers incorrectly (floor(-0.5) = -1, not 0), though negative values are handled by a separate branch.
**Fix:** Replace with a simple division-by-1000 loop or use `number_format()` with a suffix array lookup, which is clearer and handles edge cases correctly.

---

### CODE-R2-013 | attaquer.php: Player name output without escaping in attack/spy table
**Severity:** HIGH
**File:** `attaquer.php:207-248`
**Description:** In the active attacks table display, player names from the `actionsattaques` DB rows are output without `htmlspecialchars()`:
- Line 207: `$actionsattaques['defenseur']` is used directly in href and text
- Line 209: `$actionsattaques['defenseur']` used directly
- Line 248: `$actionsattaques['attaquant']` used directly
While login names are validated at registration (alphanumeric only), defense-in-depth requires escaping all DB-sourced values on output. If the login validation were ever relaxed or bypassed, this becomes a stored XSS vector.
**Fix:** Apply `htmlspecialchars($actionsattaques['defenseur'], ENT_QUOTES, 'UTF-8')` to all instances. The same escaping is correctly done in other parts of the same file (e.g., line 420, 502).

---

### CODE-R2-014 | messages.php: Malformed HTML -- `<td><a>...<img></td></a>` nesting
**Severity:** HIGH
**File:** `messages.php:68-71`
**Description:** The status column HTML has incorrect tag nesting:
```php
echo '<tr><td><a href="..."><img .../></td></a>';
```
The `</a>` is outside the `</td>`, producing invalid HTML. Browsers will attempt to fix this but the result is unpredictable. This appears on every message in the inbox.
**Fix:** Change to `<td><a href="..."><img .../></a></td>`.

---

## MEDIUM

### CODE-R2-015 | forum.php: `$ex4` used before guaranteed initialization
**Severity:** MEDIUM
**File:** `forum.php:21-26`
**Description:** `$ex4` is only set inside `if(isset($_SESSION['login']))` (line 21), but line 25 checks `isset($ex4)` which works. However, line 30 then accesses `$ex4Result = $ex4` which is redundant (assigning the same value to a new variable for no reason). The overall flow is convoluted: the ban check mixes logged-in and logged-out paths unnecessarily.
**Fix:** Restructure to `$isBanned = false; if (isset($_SESSION['login'])) { /* ban check */ }` without the intermediate `$ex4Result`.

---

### CODE-R2-016 | display.php: `affichageTemps()` string comparison on integer
**Severity:** MEDIUM
**File:** `includes/display.php:136-137`
**Description:** Line 136 computes `$minutes = intval((...)) . ':'` which produces a string like `"5:"`. Line 137 then checks `if ($minutes < 10)` -- this is comparing a string like `"5:"` to an integer. In PHP 8.x, this comparison uses string-to-number conversion and `"5:"` becomes `5`, so it happens to work. But it is fragile and misleading. The zero-padding intent is to format `5:` as `05:`.
**Fix:** Compute minutes as integer first, pad, then append colon:
```php
$minutes = intval(($secondes % 3600) / 60);
$minutes = ($minutes < 10 ? '0' : '') . $minutes . ':';
```

---

### CODE-R2-017 | medailles.php: 5 separate queries to the same `autre` table for the same player
**Severity:** MEDIUM
**File:** `medailles.php:17-30`
**Description:** Lines 17, 19, 22, 24, 29 each query different columns from `autre` and related tables for `$joueur`. Lines 19 and 22 both query `autre`: line 19 selects `count(*) AS nbmessages FROM reponses`, line 22 selects `SELECT * FROM autre`, and line 24 selects `SELECT energieDepensee FROM autre`. Since line 22 already does `SELECT *`, the data from lines 17 and 24 is already available in `$donnees2`.
**Fix:** Use `$donnees2['nbattaques']` instead of a separate query (line 17), and `$donnees2['energieDepensee']` instead of line 24's query. This eliminates 2 queries.

---

### CODE-R2-018 | compte.php: CSRF check at top runs on every POST, including file uploads
**Severity:** MEDIUM
**File:** `compte.php:6`
**Description:** Line 6 calls `csrfCheck()` unconditionally for all POST requests. This means the CSRF token must be present in every form on the page. If a form omits the token field, the entire page breaks. More importantly, this runs before any of the individual `if(isset(...))` blocks, so even a file upload triggers CSRF validation before the upload handler can run. This is actually correct behavior (CSRF should be checked early), but the comment says "CSRF check for all POST requests on this page" suggesting it was added hastily. The real issue: if `csrfCheck()` calls `die()` on failure, a legitimate user who navigates away and back (stale token) loses their entire form submission with no friendly error.
**Fix:** Consider graceful CSRF failure with a redirect and flash message instead of `die()`.

---

### CODE-R2-019 | compte.php: Email regex too restrictive (lines 76)
**Severity:** MEDIUM
**File:** `compte.php:76`
**Description:** The email validation regex `#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#` rejects valid emails with uppercase chars (the regex has no `i` flag), plus signs (user+tag@gmail.com), and TLDs longer than 4 chars (e.g., `.travel`, `.museum`, `.technology`). The same restrictive regex exists in `inscription.php` via `validateEmail()`.
**Fix:** Use PHP's built-in `filter_var($email, FILTER_VALIDATE_EMAIL)` which handles RFC 5322 compliance.

---

### CODE-R2-020 | display.php: `transformInt()` allows injection of partial numbers
**Severity:** MEDIUM
**File:** `includes/display.php:341-352`
**Description:** `transformInt()` replaces SI suffixes (K, M, G, etc.) with zeros using case-insensitive regex. Input like `"1E3"` becomes `"1000000000000000000003"` because `E` is replaced with 18 zeros. More critically, input like `"1E"` becomes `"1000000000000000000"` (1 quintillion). While callers typically use `intval()` after, the intermediate string can overflow PHP integer range on 32-bit systems or produce unexpected float conversion.
**Fix:** Add bounds checking after conversion, or better yet, parse the input with a single regex that extracts the numeric and suffix parts and computes the value directly.

---

### CODE-R2-021 | ui_components.php: `item()` function is 90 lines with 14 optional keys -- too many responsibilities
**Severity:** MEDIUM
**File:** `includes/ui_components.php:129-322`
**Description:** The `item()` function accepts an associative array with 14+ optional keys (noList, floating, disabled, media, input, style, after, titre, soustitre, accordion, autocomplete, link, form, select, retour). This is a "god function" for UI rendering. The function's behavior changes dramatically based on which combination of keys is present, making it extremely difficult to understand what HTML will be produced for any given call. The `retour` branch (line 282) produces slightly different HTML than the `echo` branch (line 301) -- the `retour` branch omits the `$accordion` div.
**Fix:** Split into focused functions: `listItem()`, `accordionItem()`, `formItem()`, `selectItem()`. Or at minimum, document the key combinations with examples.

---

### CODE-R2-022 | ui_components.php: `accordion()` function wraps all content in `item-media` divs
**Severity:** MEDIUM
**File:** `includes/ui_components.php:362-402`
**Description:** The `accordion()` function (lines 362-402) wraps the `titre` and `contenu` in `<div class="item-media">` which is semantically wrong -- `item-media` is for images/icons in Framework7, not for title text or content blocks. This was likely a copy-paste error from the `item()` function's media handling.
**Fix:** Use `item-title` for titre and remove the wrapper div from contenu.

---

### CODE-R2-023 | ui_components.php: `checkbox()` function has unused `$d` and `$e` variables
**Severity:** MEDIUM
**File:** `includes/ui_components.php:404-448`
**Description:** Lines 418-424 compute `$d` and `$e` (list item wrappers) per checkbox entry based on `noList` key, but lines 440-447 use a different `$d` and `$e` that were computed outside the foreach loop. The per-entry `$d`/`$e` inside the loop are never used -- the `<li>` is hardcoded on line 427.
**Fix:** Remove the unused `$d`/`$e` computation inside the foreach loop.

---

### CODE-R2-024 | prestige.php: `$MEDAL_THRESHOLDS_*` globals referenced but not validated against config.php names
**Severity:** MEDIUM
**File:** `includes/prestige.php:47-48`
**Description:** The `calculatePrestigePoints()` function declares globals `$MEDAL_THRESHOLDS_TERREUR`, `$MEDAL_THRESHOLDS_ATTAQUE`, etc. -- but config.php defines them as regular `$` variables, not constants. If prestige.php is ever included before config.php (load order change), all threshold arrays will be null, and the medal count loop (line 70) will silently produce 0 PP for all players.
**Fix:** Either move medal thresholds to `define()` constants or add a guard: `if (!isset($MEDAL_THRESHOLDS_TERREUR)) { error_log('Prestige: medal thresholds not loaded'); return 0; }`.

---

### CODE-R2-025 | voter.php: `$pasDeVote` accepts both GET and POST without sanitization
**Severity:** MEDIUM
**File:** `voter.php:46`
**Description:** Line 46 uses `$_POST['pasDeVote'] ?? $_GET['pasDeVote'] ?? null`. The variable is only used in a boolean context (`if (!$pasDeVote)`), so there is no injection risk, but accepting query parameters alongside POST in a CSRF-protected endpoint is inconsistent. The GET path has no CSRF protection (lines 24-26 only assign `$reponse` from GET, no `csrfCheck()`).
**Fix:** Remove the GET fallback for `pasDeVote`. Ideally remove the entire legacy GET support for `reponse` (lines 24-26) as the comment already says "will be removed in future."

---

### CODE-R2-026 | guerre.php: Division by zero possible when both alliances have 0 pertes
**Severity:** MEDIUM
**File:** `guerre.php:39-49`
**Description:** Line 39 checks `if($guerre['pertes1'] + $guerre['pertes2'] > 0)` before computing percentages, and line 41-42 use the sum as a divisor. However, the `round()` computation `$guerre['pertes1']/($guerre['pertes1'] + $guerre['pertes2'])*100` is evaluated before the `if` check completes in the echo. Wait -- actually the if/else structure is correct here, the division is inside the if-true branch. This is safe. However, the `else` branch (lines 46-49) hardcodes "0%" which is correct but displays the alliance links differently (without percentage calculation). The real issue: neither alliance tag is validated to exist before line 31-34 access them.

Actually, re-examining: lines 25-29 do check `if (!$alliance1 || !$alliance2)` and exit. So this is handled. Downgrading this finding.
**Fix:** No fix needed for division-by-zero. Minor: the number_format uses space as both decimal and thousands separator which is unusual.

---

### CODE-R2-027 | don.php: Self-closing form tag
**Severity:** MEDIUM
**File:** `don.php:60`
**Description:** Line 60 uses `<form name="faireUnDon" method="post" action="don.php" />` with a self-closing slash. In HTML5, `<form>` is not a void element, so the self-closing slash is ignored by browsers. However, in XHTML mode or XML parsers, this closes the form immediately, making the submit button non-functional.
**Fix:** Remove the trailing `/` from the form tag: `<form name="faireUnDon" method="post" action="don.php">`.

---

### CODE-R2-028 | game_resources.php: `// BUG ICI` comment on line 8 -- unresolved marker
**Severity:** MEDIUM
**File:** `includes/game_resources.php:8`
**Description:** Line 8 contains the comment `// BUG ICI` ("BUG HERE") on the `revenuEnergie` function opening brace. This marker has been in the code presumably for years and suggests a known bug that was never documented or resolved. There is no corresponding issue tracker reference.
**Fix:** Investigate what the original developer meant by "BUG HERE", resolve or document the issue, and remove the comment. The function does have the redundant query issue (CODE-R2-007) and variable variable issues which may be what was flagged.

---

### CODE-R2-029 | display.php: `image()` function has duplicate `alt` attribute
**Severity:** MEDIUM
**File:** `includes/display.php:11`
**Description:** Line 11 generates `<img ... alt="Energie" src="images/..." alt="$nomsRes[$num]" ...>`. Two `alt` attributes on one tag -- browsers use the first one, so the actual resource name alt text is ignored. The first alt always says "Energie" regardless of which atom type is displayed.
**Fix:** Remove the first `alt="Energie"` and keep only the correct `alt="' . $nomsRes[$num] . '"`.

---

### CODE-R2-030 | display.php: `antiXSS()` and `antihtml()` are functionally identical
**Severity:** MEDIUM
**File:** `includes/display.php:322-331`
**Description:** `antihtml()` (line 322) calls `htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8')`. `antiXSS()` (line 327) calls `htmlspecialchars(trim($phrase), ENT_QUOTES, 'UTF-8')`. The only difference is `trim()`. Having two nearly identical functions with different names is confusing -- callers must decide which to use and may pick inconsistently.
**Fix:** Deprecate one. Use `antihtml()` everywhere and add `trim()` at the call site when needed, or consolidate into a single `sanitize($str, $trim = false)`.

---

### CODE-R2-031 | constructions.php: `traitementConstructions()` declares `global $ressources` twice
**Severity:** MEDIUM
**File:** `constructions.php:200,204`
**Description:** Line 200 and line 204 both declare `global $ressources`. Duplicate global declarations are harmless but indicate copy-paste assembly of the function.
**Fix:** Remove the duplicate `global $ressources` on line 204.

---

### CODE-R2-032 | constructions.php: `$nbResult = dbFetchOne(...); $nb = $nbResult;` -- pointless alias
**Severity:** MEDIUM
**File:** `constructions.php:149-150,209-210`
**Description:** In both `mepConstructions()` (line 149-150) and `traitementConstructions()` (line 209-210), the pattern `$nbResult = dbFetchOne(...); $nb = $nbResult;` creates an unnecessary alias. Then `$nb['nb']` is used. This appears to be a refactoring artifact where `$nb` was originally the raw result.
**Fix:** Use `$nbResult['nb']` directly and remove the `$nb` alias.

---

### CODE-R2-033 | inscription.php: Nested 7-level deep conditionals
**Severity:** MEDIUM
**File:** `inscription.php:7-53`
**Description:** The registration handler nests `if` blocks 7 levels deep: isset -> !empty -> password match -> login validate -> email validate -> email unique -> login unique. This extreme nesting hurts readability. Each level should be an early return/continue.
**Fix:** Use guard clauses with early returns:
```php
if (!isset($_POST['login'])) goto render;
csrfCheck();
if (empty($_POST['login']) || empty($_POST['pass'])) { $erreur = '...'; goto render; }
// etc.
```

---

## LOW

### CODE-R2-034 | display.php: `creerBBcode()` function body is a no-op
**Severity:** LOW
**File:** `includes/display.php:334-339`
**Description:** The `creerBBcode()` function accepts 3 parameters (`$nomTextArea`, `$interieur`, `$reponse`) but only outputs a static "BBcode active" message. The `$nomTextArea`, `$interieur`, and `$reponse` parameters are completely unused. Callers pass data that is silently discarded. This was likely gutted during the modularization but the callers (8 files) were not updated to stop passing arguments.
**Fix:** Either restore functionality (if BBCode toolbar is desired) or change signature to `creerBBcode()` with no params and update all 8 callers.

---

### CODE-R2-035 | display.php: `nombreTout()` has hardcoded HTML unlike the `chip()` pattern used elsewhere
**Severity:** LOW
**File:** `includes/display.php:198-205`
**Description:** `nombreTout()` and `coutTout()` manually construct chip-style HTML instead of using the `chip()` function. This means any future change to the chip component styling must be made in 3 places.
**Fix:** Refactor to use `chip()` with appropriate parameters.

---

### CODE-R2-036 | display.php: `rangForum()` hardcodes `"Guortates"` as creator name
**Severity:** LOW
**File:** `includes/display.php:280`
**Description:** Line 280 checks `if ($donnees2['login'] == "Guortates")` as a special case for the game creator. This is a hardcoded magic string. If the creator changes their username or a new creator account is needed, this requires a code change.
**Fix:** Move to a config constant: `define('GAME_CREATOR_LOGIN', 'Guortates');`.

---

### CODE-R2-037 | medailles.php: User-facing Easter egg error message
**Severity:** LOW
**File:** `medailles.php:82`
**Description:** Line 82 displays `'A un moment faut s'arreter de jouer avec la barre URL.'` ("At some point you need to stop playing with the URL bar") when an invalid login parameter is provided. While humorous, this leaks information about the validation check and is unprofessional for a production game. The same pattern appears in `guerre.php:68` ("Stop ca petit troll !") and `attaquer.php:50` ("T'y as cru ?").
**Fix:** Replace with neutral messages like "Joueur introuvable." Consistent error messaging improves user experience.

---

### CODE-R2-038 | display.php: `coutAtome()` line 223 has `// BUG ICI` comment
**Severity:** LOW
**File:** `includes/display.php:223`
**Description:** Another `// BUG ICI` ("BUG HERE") marker on the `coutAtome()` function, with no explanation of what the bug is. The function appears correct -- it compares current resources against cost and returns green/red chip styling.
**Fix:** Investigate or remove the marker.

---

### CODE-R2-039 | compte.php: `<center>` tag on line 239 is deprecated HTML
**Severity:** LOW
**File:** `compte.php:239`
**Description:** Line 239 uses `<center>` which has been deprecated since HTML4 and removed in HTML5 strict mode.
**Fix:** Replace with `<div style="text-align:center">`.

---

### CODE-R2-040 | compte.php: JavaScript redirect instead of HTTP redirect for account deletion
**Severity:** LOW
**File:** `compte.php:10,25`
**Description:** Lines 10 and 25 use `echo "<script>window.location.replace(...)</script>"` instead of PHP `header('Location: ...')`. This means the redirect fails if JavaScript is disabled, and the page content may flash before redirect. The `header()` approach is used correctly elsewhere in the same file (e.g., `constructions.php:37`).
**Fix:** Replace with `header('Location: deconnexion.php'); exit();` and `header('Location: compte.php'); exit();`.

---

### CODE-R2-041 | forum.php: JavaScript redirect for ban expiry instead of PHP redirect
**Severity:** LOW
**File:** `forum.php:39`
**Description:** Same pattern as CODE-R2-040. Line 39 uses `echo "<script>window.location.replace(\"forum.php\")</script>"` when a ban has expired. Should be `header('Location: forum.php'); exit();`.
**Fix:** Replace with HTTP redirect.

---

### CODE-R2-042 | bbcode.php: `BBCode()` function overly aggressive smiley replacement
**Severity:** LOW
**File:** `includes/bbcode.php:347`
**Description:** Line 347: `preg_replace('!lol!isU', ...)` replaces ALL occurrences of "lol" (case-insensitive) with a smiley emoji, including inside words. The word "technology" would become "techno😁gy". Similarly, line 348 replaces `=/` which could match inside URLs or code blocks. The `isU` flags make these case-insensitive and ungreedy but not word-boundary-aware.
**Fix:** Add word boundary assertions: `'!\blol\b!isU'` or restrict smiley replacement to only apply outside `[code]` blocks and URLs.

---

### CODE-R2-043 | bbcode.php: `BBCode()` double-encodes entities
**Severity:** LOW
**File:** `includes/bbcode.php:317`
**Description:** Line 317 applies `htmlentities($text, ENT_QUOTES, 'UTF-8')` to the input, then the smiley/tag replacements inject raw HTML (e.g., `<span style="...">$1</span>`). This means BBCode-formatted text is first entity-encoded, then HTML is re-injected. If any text already contains HTML entities (e.g., from a form that was entity-encoded on input), they will be double-encoded: `&amp;` becomes `&amp;amp;`.
**Fix:** Ensure input to `BBCode()` is raw text (not pre-encoded), and let `htmlentities()` on line 317 be the single encoding pass.

---

### CODE-R2-044 | display.php: `imageEnergie()` has double space in HTML attribute
**Severity:** LOW
**File:** `includes/display.php:21`
**Description:** Line 21: `$class . '  alt="Energie"'` has two spaces before `alt=`. Cosmetic issue only.
**Fix:** Remove the extra space.

---

### CODE-R2-045 | Multiple files: `sizeof()` used instead of `count()`
**Severity:** LOW
**Files:** `constructions.php:26,64`, `medailles.php:61`, `includes/game_actions.php:494-530`, `includes/player.php:179`, `marche.php:190,303`, `sinstruire.php:18`
**Description:** `sizeof()` is an alias for `count()` in PHP. While functionally identical, `count()` is the canonical name and `sizeof()` may be confusing to developers coming from C/C++ where it has different semantics.
**Fix:** Replace all `sizeof()` calls with `count()` for consistency.

---

### CODE-R2-046 | ui_components.php: `progressBar()` division by zero possible
**Severity:** LOW
**File:** `includes/ui_components.php:480`
**Description:** Line 480 computes `$vie / $vieMax * 100` for the progress bar width. If `$vieMax` is 0 (e.g., a building at level 0), this is a division by zero.
**Fix:** Add guard: `$pct = ($vieMax > 0) ? ($vie / $vieMax * 100) : 0;`.

---

### CODE-R2-047 | game_resources.php: `$stabilisateur` fetched but never used
**Severity:** LOW
**File:** `includes/game_resources.php:163`
**Description:** Line 163 queries `SELECT stabilisateur FROM constructions WHERE login=?` and assigns to `$stabilisateur`, but this variable is never referenced in the remaining `updateRessources()` function body (lines 163-201). The stabilisateur effect is handled inside `coefDisparition()` which fetches it independently.
**Fix:** Remove the unused query on line 163.

---

## Architecture Notes

### Systemic Pattern: `global $base` in 34 locations across 9 files
Every function that touches the database declares `global $base`. This is the #1 barrier to
testability and creates hidden coupling. The long-term fix is dependency injection (pass `$base`
as a parameter) or a singleton DB wrapper, but this is a project-wide refactor beyond the scope
of individual findings.

### Systemic Pattern: Variable Variables in 7+ files, 80+ occurrences
As detailed in CODE-R2-001, this is the single largest maintainability debt. A dedicated
refactoring pass to convert all `${'name'.$var}` and `$$var` to associative arrays would
dramatically improve IDE support, static analysis coverage, and developer comprehension.

### Systemic Pattern: Inline HTML generation in PHP functions
Functions like `mepConstructions()`, `item()`, `chip()`, `carteForum()`, and the combat
report builders in `game_actions.php` mix business logic with HTML string concatenation.
A template layer (even simple PHP includes with extracted variables) would separate concerns.

---

## Recommended Priority Order

1. **CODE-R2-002** -- Delete 313 lines of dead bbcode JavaScript (5 minutes, zero risk)
2. **CODE-R2-005** -- Fix trailing semicolon bug in constructions point allocation (5 minutes)
3. **CODE-R2-006** -- Initialize `$nombre1-4` before loop (2 minutes)
4. **CODE-R2-007** -- Remove redundant DB query in revenuEnergie (5 minutes)
5. **CODE-R2-013** -- Add missing htmlspecialchars in attaquer.php (5 minutes)
6. **CODE-R2-014** -- Fix malformed HTML in messages.php (2 minutes)
7. **CODE-R2-008** -- Eliminate N+1 in molecule decay loop (15 minutes)
8. **CODE-R2-004** -- DRY the constructions point allocation (30 minutes)
9. **CODE-R2-003** -- Pass cached data to formula functions (1-2 hours)
10. **CODE-R2-001** -- Variable variables refactor (4-8 hours, multi-file)
