# Pass 2 -- Domain 2: Code Quality Deep Dive

**Date:** 2026-03-05
**Auditor:** Claude Opus 4.6 (Pass 2 -- Deep Line-by-Line Analysis)
**Scope:** All 7 fonctions.php sub-modules, combat.php, compounds.php, resource_nodes.php, multiaccount.php, config.php
**Method:** Line-by-line data flow tracing, boundary analysis, logic verification

---

## Executive Summary

Pass 2 deep analysis identified **52 findings** across the priority modules. The most severe issues include:

- **2 CRITICAL:** Wrong logError() call signature (11 callsites silently mislog all combat/formation errors), and undefined variables referenced in combat report HTML
- **6 HIGH:** Dead code in supprimerJoueur() (connectes cleanup after membre deletion), missing table cleanup on player deletion (4 tables), attack_cooldowns column mismatch, division-by-zero in initPlayer(), and a variable dependency bug in absence reports
- **19 MEDIUM:** Logic errors, incorrect formulas, cache poisoning risks, email typos, prestige timestamp bug
- **25 LOW:** Code smells, dead conditions, minor inconsistencies

---

## CRITICAL Findings

### P2-D2-001 | CRITICAL | logError() called with wrong signature -- 11 callsites silently broken
- **Location:** includes/combat.php:30,40,51,57,364,370,454,579; includes/game_actions.php:57,120,491
- **Description:** The `logError()` function signature is `logError($category, $message, $context = [])` (see logger.php:44). However, all 11 callsites in combat.php and game_actions.php pass only ONE argument -- the full error message string. For example: `logError("Combat: missing attacker constructions for " . $actions['attaquant'])`. This means the error message is being stored as the `$category` field, and the `$message` field receives the empty string default (but actually there is no default -- the second param is required). In PHP 8.2, calling with fewer arguments than required triggers an `ArgumentCountError` -- but since this is inside a try/catch block in game_actions.php, the real exception gets swallowed and replaced by the logError exception.
- **Impact:** (1) All combat error logging is completely broken -- either throws an uncaught exception or logs garbled entries with the message in the category field. (2) In PHP 8.2 strict mode, this will throw `ArgumentCountError` which will be caught by the combat try/catch, rolling back the transaction and silently dropping the real error. Combat errors become invisible.
- **Fix:** Change all 11 callsites to include a category as first argument:
  ```php
  // Before:
  logError("Combat: missing attacker constructions for " . $actions['attaquant']);
  // After:
  logError("COMBAT", "Missing attacker constructions for " . $actions['attaquant']);
  ```

### P2-D2-002 | CRITICAL | $activeReactionsAtt and $activeReactionsDef never defined -- PHP warnings in every combat report
- **Location:** includes/game_actions.php:312-320
- **Description:** The combat report generation references `$activeReactionsAtt` and `$activeReactionsDef` variables that are never defined anywhere in the codebase. These appear to be remnants of a planned "chemical reactions" feature that was never implemented. The code is:
  ```php
  if (!empty($activeReactionsAtt) || !empty($activeReactionsDef)) {
      $reactionsHtml = important('Reactions chimiques') . '<br/>';
      foreach ($activeReactionsAtt as $name => $bonuses) { ... }
      foreach ($activeReactionsDef as $name => $bonuses) { ... }
  }
  ```
  In PHP 8.2, accessing undefined variables triggers `E_WARNING`. While `!empty()` on an undefined variable returns `false` (so the block never executes), this still generates warnings in strict error reporting.
- **Impact:** PHP warnings on every combat resolution. The code block is dead code that can never execute.
- **Fix:** Either remove the entire reactions HTML block (lines 311-321), or initialize the variables:
  ```php
  $activeReactionsAtt = [];
  $activeReactionsDef = [];
  ```

---

## HIGH Findings

### P2-D2-003 | HIGH | supprimerJoueur() deletes membre before connectes subquery -- connectes cleanup silently fails
- **Location:** includes/player.php:763,779
- **Description:** In the `supprimerJoueur()` transaction, line 763 executes `DELETE FROM membre WHERE login=?` and then line 779 tries `DELETE FROM connectes WHERE ip IN (SELECT ip FROM membre WHERE login=?)`. Since `membre` was already deleted at line 763, the subquery returns zero rows, and no `connectes` records are ever cleaned up.
- **Impact:** Orphaned `connectes` records accumulate in the database for every deleted player. These records contain IP addresses that are never cleaned.
- **Fix:** Move the `connectes` DELETE before the `membre` DELETE, or save the IP first:
  ```php
  // Move BEFORE line 763:
  $memberIp = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $joueur);
  // ... then after other deletes:
  if ($memberIp) {
      dbExecute($base, 'DELETE FROM connectes WHERE ip=?', 's', $memberIp['ip']);
  }
  ```

### P2-D2-004 | HIGH | supprimerJoueur() missing cleanup for 4 new tables
- **Location:** includes/player.php:760-784
- **Description:** The `supprimerJoueur()` function cleans up 16 tables but misses 4 tables introduced in later phases:
  1. `login_history` (from multiaccount.php) -- stores all login events with IP and fingerprint
  2. `player_compounds` (from compounds.php) -- stored/active compound buffs
  3. `account_flags` (from multiaccount.php) -- multi-account detection flags
  4. `resource_nodes` -- not player-specific, but admin_alerts may reference deleted players
- **Impact:** Orphaned data with player login strings persists after account deletion. `login_history` contains sensitive data (IPs, user agents, fingerprints) that should be deleted for privacy compliance. `player_compounds` will have FK violations if compound_key references are checked.
- **Fix:** Add these DELETE statements inside the transaction:
  ```php
  dbExecute($base, 'DELETE FROM login_history WHERE login=?', 's', $joueur);
  dbExecute($base, 'DELETE FROM player_compounds WHERE login=?', 's', $joueur);
  dbExecute($base, 'DELETE FROM account_flags WHERE login=? OR related_login=?', 'ss', $joueur, $joueur);
  ```

### P2-D2-005 | HIGH | attack_cooldowns column mismatch -- deletion uses wrong column name
- **Location:** includes/player.php:743,776 vs includes/combat.php:340-342
- **Description:** The `attack_cooldowns` table is INSERTed with columns `attacker` and `defender` (combat.php:340). However, `supprimerJoueur()` at line 776 and `supprimerAlliance()` at line 743 both use `DELETE FROM attack_cooldowns WHERE login=?`. The table has no `login` column -- it has `attacker` and `defender` columns.
- **Impact:** The DELETE queries silently fail (or cause SQL errors) because the `login` column does not exist. Cooldown records for deleted players are never cleaned up.
- **Fix:**
  ```php
  dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?', 'ss', $joueur, $joueur);
  ```

### P2-D2-006 | HIGH | Absence report uses variables from molecule loop that may not be set for < 4 classes
- **Location:** includes/game_resources.php:229,257
- **Description:** Line 229 sets `${'nombre' . ($compteur + 1)}` inside the molecule loop. But the absence report at line 257 unconditionally references `$nombre1`, `$nombre2`, `$nombre3`, and `$nombre4`. If a player has fewer than 4 molecule classes (which should not happen normally but could during season reset edge cases), these variables would be undefined.
- **Impact:** PHP warnings and incorrect absence report if molecule count < 4.
- **Fix:** Initialize all 4 variables before the loop:
  ```php
  $nombre1 = $nombre2 = $nombre3 = $nombre4 = 0;
  ```

### P2-D2-007 | HIGH | revenuEnergie() division by zero when $revenuEnergie == 0
- **Location:** includes/player.php:324
- **Description:** The `effetSup` string for the generateur building computes:
  ```php
  time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie
  ```
  If `$revenuEnergie` is 0 (which happens when producteur drain exceeds generator output, or for a brand new player), this divides by zero.
- **Impact:** PHP `DivisionByZeroError` in PHP 8.x, which would crash the `initPlayer()` function and break every page for that player.
- **Fix:**
  ```php
  $revenuEnergie > 0 ? time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie : time()
  ```

### P2-D2-008 | HIGH | revenuEnergie() division by zero second instance with $revenu['energie']
- **Location:** includes/player.php:184
- **Description:** Similar to P2-D2-007, line 184 computes:
  ```php
  $max = max($max, SECONDS_PER_HOUR * ($placeDepot - $ressources[$ressource]) / max(1, $revenu[$ressource]));
  ```
  This correctly uses `max(1, ...)` to prevent division by zero for atom revenues. But the generateur `effetSup` at line 324 does not apply the same protection for energy.
- **Impact:** Inconsistent: atom revenue has div-by-zero protection, but energy revenue does not.
- **Fix:** Apply same `max(1, ...)` pattern to line 324.

---

## MEDIUM Findings

### P2-D2-009 | MEDIUM | combat.php Dispersee overflow only cascades forward, not backward
- **Location:** includes/combat.php:264-280
- **Description:** In the Dispersee formation defender casualty calculation, overflow damage from one class only carries to subsequent classes (forward cascade). If class 4 has overflow, it is lost. If class 1 is weak but class 4 is strong, excess damage from class 1 spills to class 2, but never wraps around to class 4.
- **Impact:** Slightly favors defenders who put weak classes early and strong classes late. Not a bug per se (intended behavior), but the combat documentation says "equal split" without explaining the forward-only cascade. Could lead to player confusion.
- **Fix:** Document this behavior explicitly or implement circular cascade.

### P2-D2-010 | MEDIUM | combat.php hardcodes 4 classes in DB UPDATE statements
- **Location:** includes/combat.php:356-359
- **Description:** Defender molecule UPDATE uses hardcoded `$classeDefenseur1` through `$classeDefenseur4`:
  ```php
  dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur1['nombre'] - $classe1DefenseurMort), $classeDefenseur1['id']);
  dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur2['nombre'] - $classe2DefenseurMort), $classeDefenseur2['id']);
  // ...
  ```
  While `$nbClasses` is 4 and unlikely to change, these literal references mean the code breaks silently if MAX_MOLECULE_CLASSES is ever changed in config.php.
- **Impact:** Config change would not propagate to combat resolution. Some classes would not be updated.
- **Fix:** Replace with a loop:
  ```php
  for ($i = 1; $i <= $nbClasses; $i++) {
      dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di',
          (${'classeDefenseur'.$i}['nombre'] - ${'classe'.$i.'DefenseurMort'}),
          ${'classeDefenseur'.$i}['id']);
  }
  ```

### P2-D2-011 | MEDIUM | combat.php pillage calculation also hardcodes 4 classes
- **Location:** includes/combat.php:395-398
- **Description:** The `$ressourcesAPiller` calculation manually lists classes 1-4 instead of using a loop:
  ```php
  $ressourcesAPiller = (($classeAttaquant1['nombre'] - $classe1AttaquantMort) * pillage(...) +
      ($classeAttaquant2['nombre'] - $classe2AttaquantMort) * pillage(...) + ...);
  ```
- **Impact:** Same as P2-D2-010 -- breaks if class count changes.
- **Fix:** Use a loop.

### P2-D2-012 | MEDIUM | combat.php building destruction also hardcodes 4 classes
- **Location:** includes/combat.php:436-439
- **Description:** Building destruction damage `$hydrogeneTotal` computation manually sums 4 classes.
- **Impact:** Same pattern as P2-D2-010/011.
- **Fix:** Already uses a loop at lines 461-464 (the re-calculation). The initial calculation at 436-439 is dead code since it's overwritten at 460-464. Remove lines 436-439.

### P2-D2-013 | MEDIUM | Email address typo in multiaccount.php and basicprivatephp.php
- **Location:** includes/multiaccount.php:288, includes/basicprivatephp.php:176-177
- **Description:** The fallback admin email is `theverylittewar@gmail.com` -- missing an 'l' in "little". Same typo in basicprivatephp.php "From" header: `noreply@theverylittewar.com`.
- **Impact:** Admin alert emails and season-end emails are sent to the wrong address and from a non-matching domain.
- **Fix:** Change to `theverylittlewar@gmail.com` and `noreply@theverylittlewar.com`.

### P2-D2-014 | MEDIUM | NaCl compound recipe uses incorrect chemistry
- **Location:** includes/config.php:660-666
- **Description:** The "NaCl" (Sel) compound recipe uses `'chlore' => 1, 'soufre' => 1`. NaCl is sodium chloride, not chlorine + sulfur. The game has no sodium atom. Using soufre (sulfur) as a substitute is chemically incorrect and may confuse chemistry-literate players.
- **Impact:** Immersion/flavor issue. The compound name suggests Na+Cl but the recipe does not match.
- **Fix:** Either rename to "SCl2" (sulfur dichloride) or change the recipe to something more thematically appropriate, or keep NaCl and accept it as a game simplification. Document the artistic license.

### P2-D2-015 | MEDIUM | coefDisparition() $type=0 path may access undefined $donnees on empty query
- **Location:** includes/formulas.php:225-226,239-243
- **Description:** When `$type == 0`, the function queries for molecule data at line 225. If `dbFetchOne` returns null (player has no molecule for that class), `$donnees` is null. Then at line 241, `$donnees[$ressource]` triggers "Trying to access array offset on value of type null."
- **Impact:** PHP warning and incorrect decay calculation (all atoms counted as 0). Could affect molecule decay for edge cases where a class exists but has 0 of some atom.
- **Fix:** Add null guard:
  ```php
  if ($type == 0) {
      $donnees = dbFetchOne(...);
      if (!$donnees) {
          $cache[$cacheKey] = 1.0; // no decay for non-existent class
          return 1.0;
      }
  }
  ```

### P2-D2-016 | MEDIUM | coefDisparition() $donneesMedaille may be null
- **Location:** includes/formulas.php:230-234
- **Description:** `$donneesMedaille` is fetched but never null-checked before accessing `$donneesMedaille['moleculesPerdues']`. If the player's `autre` row doesn't exist, this triggers a null access warning.
- **Impact:** PHP warning during decay calculation for edge-case players.
- **Fix:** Add `if (!$donneesMedaille) $donneesMedaille = ['moleculesPerdues' => 0];`

### P2-D2-017 | MEDIUM | coefDisparition() $stabilisateur may be null
- **Location:** includes/formulas.php:228,248
- **Description:** `$stabilisateur` is fetched but never null-checked before `$stabilisateur['stabilisateur']` at line 248.
- **Impact:** PHP warning if player has no constructions row.
- **Fix:** Add null guard: `$stabLevel = ($stabilisateur && isset($stabilisateur['stabilisateur'])) ? $stabilisateur['stabilisateur'] : 0;`

### P2-D2-018 | MEDIUM | Static cache in getCompoundBonus() not invalidated when compound activated
- **Location:** includes/compounds.php:146-148
- **Description:** `getCompoundBonus()` uses a static cache keyed by `$login . '-' . $effectType`. Once called for a player during a request, activating a compound in the same request will not update the cached value. The `activateCompound()` function does not clear this cache.
- **Impact:** If a player activates a compound and immediately engages in combat in the same HTTP request, the compound bonus may not be applied.
- **Fix:** Either clear the static cache in `activateCompound()`, or remove the static cache (it's only per-request anyway).

### P2-D2-019 | MEDIUM | getSpecModifier() cache not invalidated when specialization chosen
- **Location:** includes/formulas.php:19-20
- **Description:** Same pattern as P2-D2-018. `getSpecModifier()` caches results per-request. If a player chooses a specialization and then views their stats in the same request, stale values would be returned.
- **Impact:** Stale modifier values within the same request after specialization choice.
- **Fix:** Add cache invalidation function, or accept that this is a per-request-only issue.

### P2-D2-020 | MEDIUM | resource_nodes.php static $nodesCache never invalidated
- **Location:** includes/resource_nodes.php:87-95
- **Description:** The `getResourceNodeBonus()` function caches all nodes in a static variable. The comment says "they change only on season reset," but if `generateResourceNodes()` is called (during admin reset), the cache is stale for the remainder of that PHP request.
- **Impact:** During season reset, new nodes may not be reflected in resource calculations for that same request.
- **Fix:** Add `$nodesCache = null;` at the end of `generateResourceNodes()`.

### P2-D2-021 | MEDIUM | Catalyst system uses modulo rotation that can repeat
- **Location:** includes/catalyst.php:65
- **Description:** `$newCatalyst = $currentWeek % count($CATALYSTS)` means the catalyst rotation is purely deterministic based on ISO week number. With 6 catalysts, the same catalyst repeats every 6 weeks. Weeks 1-6 map to catalysts 1-0, weeks 7-12 repeat. But `$currentWeek = intval(date('W')) + intval(date('Y')) * 100` is a large number, so the sequence is actually pseudo-random enough. However, when a year crosses (week 52 to week 1), `$currentWeek` jumps from ~202652 to ~202701, potentially skipping several catalysts.
- **Impact:** Not truly random -- predictable. Players can know which catalyst will be active next week.
- **Fix:** Either document as intentional (rotation) or add randomization.

### P2-D2-022 | MEDIUM | chiffrePetit() while loop with only if-elseif means it can loop forever
- **Location:** includes/display.php:81-107
- **Description:** The `while ($nombreFinal >= 1000)` loop reduces the number by dividing. However, if `$nombreFinal` is exactly 1000 and the first `if ($nombreFinal >= 1000000000000000000000000)` fails, and it falls through all the elseif branches... actually, the last branch is `elseif ($nombreFinal >= 1000)` which catches it. So the loop terminates. However, if `$nombreFinal` is `INF` (from a very large float), `INF >= 1000` is always true but `INF / 1000` is still `INF`, creating an infinite loop.
- **Impact:** If any game value overflows to INF (which could happen with exponential growth formulas), the `chiffrePetit()` function enters an infinite loop, hanging the PHP request.
- **Fix:** Add `if (!is_finite($nombreFinal)) return 'INF';` before the while loop.

### P2-D2-023 | MEDIUM | transformInt() suffix replacement is positional, not anchored
- **Location:** includes/display.php:341-348
- **Description:** `transformInt()` replaces K/M/G/etc with zeros using `preg_replace('#K#i', '000', ...)`. The pattern is not anchored to end-of-string, so "KK" would become "000000" (doubling), and "12K5" would become "120005". The case-insensitive flag also means "10km" becomes "100000m".
- **Impact:** If a player types a non-standard format like "5.5K" it becomes "5.5000" (string, not number). When used with `intval()` in marche.php:159, this parses as 5, losing most of the value.
- **Fix:** Replace with a proper suffix parser that handles decimal inputs.

### P2-D2-024 | MEDIUM | affichageTemps() compares string to integer at line 136
- **Location:** includes/display.php:136-137
- **Description:** After `$minutes = intval(...) . ':'`, the variable is a string like `"5:"`. Then `if ($minutes < 10)` does a string-to-number comparison. In PHP 8.x, `"5:" < 10` is `true` (string gets cast to 5), but `"15:" < 10` is `false`. This accidentally works but is fragile and depends on implicit type coercion.
- **Impact:** Works by accident due to PHP type juggling. Could break if PHP changes comparison behavior.
- **Fix:** Compare before appending the colon:
  ```php
  $minutesNum = intval(($secondes % SECONDS_PER_HOUR) / 60);
  $minutes = ($minutesNum < 10 ? '0' : '') . $minutesNum . ':';
  ```

### P2-D2-025 | MEDIUM | game_actions.php envoi processing deletes before transfer is validated
- **Location:** includes/game_actions.php:529
- **Description:** In the envoi (resource transfer) handler, `DELETE FROM actionsenvoi` happens at line 529 before the resources are actually transferred to the recipient (lines 567-599). If the recipient's resource update fails (e.g., DB error), the action is already deleted and the resources are lost.
- **Impact:** Race condition where resources sent between players can be lost if the recipient update fails.
- **Fix:** Wrap the entire envoi processing in a transaction.

### P2-D2-026 | MEDIUM | game_actions.php envoi uses $nbRes which may be undefined
- **Location:** includes/game_actions.php:578
- **Description:** The envoi resource concatenation uses `$nbRes` to decide whether to add a comma. However, `$nbRes` is a global that may not be properly set in all code paths. If it's undefined, the comparison `$num < $nbRes` would compare against null/0.
- **Impact:** Potentially malformed SET clause in the resource UPDATE.
- **Fix:** Use `count($nomsRes) - 1` instead of `$nbRes`.

---

## LOW Findings

### P2-D2-027 | LOW | combat.php $nomsRes referenced as global but never imported
- **Location:** includes/combat.php:34,44
- **Description:** The `$nomsRes` array is used throughout combat.php but never declared `global`. It works because combat.php is `include()`ed from inside `updateActions()` which has `global $nomsRes`. However, this is fragile -- if combat.php is ever included from a different context, $nomsRes would be undefined.
- **Impact:** Works by coincidence of being included from a specific scope.
- **Fix:** Add explicit global declarations at top of combat.php or pass as parameters.

### P2-D2-028 | LOW | combat.php uses variable variables extensively (${'classeDefenseur' . $c})
- **Location:** includes/combat.php:9-13,19-24 (throughout)
- **Description:** The combat system uses PHP variable variables (`${'classeDefenseur' . $c}`) extensively. This makes the code difficult to analyze statically, prevents IDE autocompletion, and makes refactoring risky.
- **Impact:** Maintainability. Code analysis tools cannot trace data flow through variable variables.
- **Fix:** Refactor to use arrays: `$classeDefenseur[$c]` instead of `${'classeDefenseur' . $c}`.

### P2-D2-029 | LOW | combat.php trailing semicolons in troupes string
- **Location:** includes/combat.php:349
- **Description:** The rebuilt troupes string appends a semicolon after each class: `$chaine = $chaine . (...) . ';'`. This produces a trailing semicolon like "100;200;300;400;". When later split with `explode(";", ...)`, this creates an empty string as the last element.
- **Impact:** The empty final element could cause issues in the formation loop if `count($molecules)` is checked against `$nbClasses`.
- **Fix:** Use `implode(';', ...)` or trim trailing semicolons.

### P2-D2-030 | LOW | game_resources.php absence report hours not rounded
- **Location:** includes/game_resources.php:259
- **Description:** The absence report says "Durant votre absence de [X] heures" where X is `$nbheuresDebut` which is `$nbsecondes / 3600`. This is a float like "7.832222". The report displays it with full decimal precision.
- **Impact:** Cosmetic -- ugly display in player reports.
- **Fix:** `round($nbheuresDebut, 1)` or `floor($nbheuresDebut)`.

### P2-D2-031 | LOW | player.php initPlayer() writes batmax on every call
- **Location:** includes/player.php:173
- **Description:** `initPlayer()` calls `batMax()` and writes the result to `autre.batmax` via `dbExecute()` on every invocation (first call per request). This is a write operation on every page load even if the value hasn't changed.
- **Impact:** Unnecessary DB write on every page load.
- **Fix:** Compare old and new values before writing:
  ```php
  if ($plusHaut != $autre['batmax']) {
      dbExecute($base, 'UPDATE autre SET batmax=? WHERE login=?', 'is', $plusHaut, $joueur);
  }
  ```

### P2-D2-032 | LOW | revenuEnergie() redundantly queries constructions twice
- **Location:** includes/game_resources.php:18,25
- **Description:** Line 18 fetches `SELECT * FROM constructions WHERE login=?` and line 25 fetches `SELECT producteur FROM constructions WHERE login=?`. The second query is redundant since `constructions` was already fetched with `*`.
- **Impact:** One redundant DB query per energy revenue calculation.
- **Fix:** Use `$constructions['producteur']` from the first query.

### P2-D2-033 | LOW | revenuAtome() and revenuEnergie() redundantly query alliance duplicateur
- **Location:** includes/game_resources.php:27-32,107-112
- **Description:** Both functions independently query the alliance duplicateur level. When called in sequence (which happens for every resource update), this results in redundant queries.
- **Impact:** 2-3 redundant DB queries per resource update.
- **Fix:** Extract alliance duplicateur lookup into a cached helper function.

### P2-D2-034 | LOW | allianceResearchBonus() queries alliance table for every tech check
- **Location:** includes/db_helpers.php:72-84
- **Description:** `allianceResearchBonus()` queries `autre` for `idalliance` every time it's called. Since it loops through `$ALLIANCE_RESEARCH` to find the matching `effectType`, it makes one query for `idalliance` and one for the alliance level. But when called multiple times for the same player (combat applies several research bonuses), the `idalliance` query is repeated.
- **Impact:** Redundant queries during combat resolution.
- **Fix:** Add static caching for the `idalliance` lookup.

### P2-D2-035 | MEDIUM | prestige.php calculatePrestigePoints() queries wrong column for last active check
- **Location:** includes/prestige.php:53
- **Description:** The function queries `SELECT timestamp FROM membre WHERE login=?` but the `membre` table schema confirms `timestamp` is the registration date (INT, set once at inscription), while `derniereConnexion` is the last login timestamp (updated on every login via basicprivatephp.php:89). The prestige check compares `time() - $lastActive['timestamp']` against `SECONDS_PER_WEEK` to determine if the player was "active in the final week."
- **Impact:** For any player who registered more than 7 days ago (i.e., everyone), `time() - registrationTimestamp` is always greater than `SECONDS_PER_WEEK`. This means the "active in final week" bonus (5 PP) is NEVER awarded to anyone who registered more than a week before season end. This is a real gameplay bug affecting prestige point calculations.
- **Fix:** Change to `SELECT derniereConnexion FROM membre WHERE login=?` and compare `time() - $lastActive['derniereConnexion']`.

### P2-D2-036 | LOW | prestige.php getPrestige() returns inconsistent types
- **Location:** includes/prestige.php:122-126
- **Description:** `getPrestige()` returns either a full DB row (associative array with `total_pp`, `unlocks`, `login`, etc.) or a hardcoded array `['total_pp' => 0, 'unlocks' => '']`. The fallback is missing any other columns the DB row would have.
- **Impact:** Callers that access columns beyond `total_pp` and `unlocks` would get undefined index warnings when the player has no prestige row.
- **Fix:** Return a consistent shape with all expected keys.

### P2-D2-037 | LOW | image() function has duplicate alt attribute
- **Location:** includes/display.php:11
- **Description:** The `image()` function outputs `alt="Energie"` followed by another `alt="..."`. HTML only uses the first alt attribute; the second is ignored.
- **Impact:** Incorrect alt text -- all atom images show "Energie" as alt text.
- **Fix:** Remove the first `alt="Energie"`.

### P2-D2-038 | LOW | checkbox() function uses $d/$e from outside the loop
- **Location:** includes/ui_components.php:419-425,442-448
- **Description:** In `checkbox()`, the `$d` and `$e` variables (list item wrappers) are set inside the foreach loop based on each checkbox's `noList` option, but the final output at line 442-448 uses `$d` and `$e` from the LAST iteration of the loop, not the first. The outer wrapper should be independent of individual checkbox options.
- **Impact:** If the last checkbox has `noList => true`, the outer wrapper uses empty strings. If mixed, behavior is inconsistent.
- **Fix:** Set outer `$d`/`$e` before the loop (or remove them from the loop).

### P2-D2-039 | LOW | debutCarte() $overflow parameter used as both flag and value
- **Location:** includes/ui_components.php:25-29
- **Description:** The `$overflow` parameter is used as both a boolean check and as an HTML id value. If passed as truthy string, it becomes an id. If passed as `true` (boolean), the id becomes `id="1"`.
- **Impact:** If called with `debutCarte('title', '', false, true)`, the div gets `id="1"` which is invalid HTML.
- **Fix:** Check for string type: `if (is_string($overflow) && $overflow !== '')`.

### P2-D2-040 | LOW | compounds.php synthesizeCompound() does not validate resource column names against DB schema
- **Location:** includes/compounds.php:79-81
- **Description:** The recipe keys (e.g., 'hydrogene', 'oxygene') are used as column names in the `ajouter()` call. While `ajouter()` has its own whitelist, the compound recipe could theoretically reference a non-resource column if config.php is misconfigured.
- **Impact:** Mitigated by `ajouter()` whitelist, but defense-in-depth is missing.
- **Fix:** Validate recipe keys against `$nomsRes` before processing.

### P2-D2-041 | LOW | multiaccount.php checkTimingCorrelation() missing status filter for timing_correlation
- **Location:** includes/multiaccount.php:229-232
- **Description:** The "already exists" check for `timing_correlation` flags does not filter by `status != 'dismissed'`. This means once a timing correlation flag is created, it can never be re-detected even if the admin dismisses it and the behavior continues.
- **Impact:** Dismissed timing correlation flags are never re-flagged.
- **Fix:** Add `AND status != ?` with value `'dismissed'` to the existing check query.

### P2-D2-042 | LOW | multiaccount.php checkCoordinatedAttacks() uses time() instead of $timestamp
- **Location:** includes/multiaccount.php:143
- **Description:** The `created_at` for coordinated attack flags uses `time()` instead of `$timestamp`. Since this function is called from game_actions.php with the attack time as `$timestamp`, the flag creation time will differ from the attack time (could be minutes to hours later when the action is processed).
- **Impact:** Forensic timestamps on coordinated attack flags are inaccurate.
- **Fix:** Use `$timestamp` consistently.

### P2-D2-043 | LOW | config.php MEDAL_GRACE_CAP_TIER comment says "Gold = 6%" but index 3 is Emeraude
- **Location:** includes/config.php:490
- **Description:** Comment says `// max tier index during grace (Gold = 6%)` but `$MEDAL_TIER_NAMES[3]` is 'Emeraude', not 'Gold'. Gold is index 2.
- **Impact:** Misleading comment. The actual behavior caps at Emeraude tier (10%), not Gold (6%).
- **Fix:** Correct comment to `// max tier index during grace (Emeraude = 10%)`, or change value to 2 for Gold.

### P2-D2-044 | LOW | config.php $MEDAL_THRESHOLDS_TROLL starts at 0
- **Location:** includes/config.php:530
- **Description:** `$MEDAL_THRESHOLDS_TROLL = [0, 1, 2, 3, 4, 5, 6, 7]`. A threshold of 0 means every player automatically has Bronze tier Troll medal (since any value >= 0 is true).
- **Impact:** All players get a free Bronze Troll medal without doing anything.
- **Fix:** Change first threshold to 1 if the medal should require at least 1 "troll" action.

### P2-D2-045 | LOW | config.php REGISTRATION_ELEMENT_THRESHOLDS probabilities don't match comment
- **Location:** includes/config.php:536-539
- **Description:** Comment says element 7 has 0.5% probability (range 200-200 = 1 out of 200). But the threshold is 200 (inclusive), meaning the only value that reaches index 7 is exactly 200. `mt_rand(1, 200)` inclusive gives probability 1/200 = 0.5%, which matches. However, element 6 range is 198-199 (2 values), giving 1% (matches comment). This is correct.
- **Impact:** No bug -- comment matches code. Documenting for completeness.
- **Fix:** None needed.

### P2-D2-046 | LOW | player.php coordonneesAleatoires() builds full map grid in memory
- **Location:** includes/player.php:630-637
- **Description:** Creates a 2D array of size `tailleCarte * tailleCarte`. For a map size of 100, this is 10,000 entries. For 500, it's 250,000 entries. Each time a player registers.
- **Impact:** Memory usage scales quadratically with map size. For very large maps (many players), this could cause memory exhaustion.
- **Fix:** Use a HashSet of occupied coordinates instead of a full grid.

### P2-D2-047 | LOW | player.php coordonneesAleatoires() loads ALL players for grid
- **Location:** includes/player.php:639
- **Description:** `SELECT x,y FROM membre` loads every player's coordinates every time a new player registers. No WHERE clause filters deleted/inactive players.
- **Impact:** Includes coordinates of deleted players (x=-1000, y=-1000) in the grid, wasting a grid cell at [-1000][-1000] and causing an array access to a very negative index.
- **Fix:** Add `WHERE x >= 0 AND y >= 0` to exclude deleted players.

### P2-D2-048 | LOW | display.php image() function has typo-style redundancy
- **Location:** includes/display.php:11
- **Description:** The function is called `image($num)` but only works for atom images (not arbitrary images). The name is too generic.
- **Impact:** Naming confusion. No functional bug.
- **Fix:** Rename to `imageAtome($num)` for clarity (would require updating all callers).

### P2-D2-049 | LOW | game_actions.php combat transaction nesting
- **Location:** includes/game_actions.php:109-360
- **Description:** The combat processing uses `mysqli_begin_transaction($base)` at line 109, but combat.php internally calls `withTransaction($base, ...)` (e.g., via `augmenterBatiment` -> `withTransaction`) and `dbExecute()` with `ON DUPLICATE KEY`. MariaDB does not support true nested transactions -- the inner `withTransaction` call would commit the outer transaction prematurely.
- **Impact:** If `diminuerBatiment()` (called from combat.php) triggers `withTransaction()`, it commits the outer combat transaction early. However, `diminuerBatiment()` does NOT use `withTransaction()` -- it uses individual `dbExecute()` calls. The combat.php code itself does not call `withTransaction()`. So this is safe in the current code but fragile.
- **Fix:** Document that combat.php code must not use `withTransaction()` internally.

### P2-D2-050 | LOW | game_actions.php espionage report HTML has mismatched div tags
- **Location:** includes/game_actions.php:412-413
- **Description:** Line 412 outputs:
  ```
  " . $constructionsJoueur['vieGenerateur'] . "/" . pointsDeVie($constructionsJoueur['generateur']) . "</div>
  </div></td>
  ```
  There are two `</div>` closing tags but only one opening `<td>`. This creates malformed HTML in spy reports.
- **Impact:** Broken HTML layout in espionage reports for the generator building HP display.
- **Fix:** Remove the extra `</div>`.

### P2-D2-051 | LOW | prestige.php awardPrestigePoints() does not check for inactive players
- **Location:** includes/prestige.php:94
- **Description:** `awardPrestigePoints()` iterates ALL players in `autre` table, including inactive ones. Players who haven't logged in for months still get prestige calculations run.
- **Impact:** Wasted computation on inactive accounts. They'll get 0 PP (since the active check fails), but the queries still run.
- **Fix:** Add `WHERE login IN (SELECT login FROM membre WHERE derniereConnexion > ?)` with threshold.

### P2-D2-052 | LOW | config.php $RESOURCE_NAMES_ACCENTED is identical to $RESOURCE_NAMES
- **Location:** includes/config.php:41-42
- **Description:** `$RESOURCE_NAMES` and `$RESOURCE_NAMES_ACCENTED` contain the exact same values. Neither has accented characters (e.g., "hydrogene" vs "hydrogene" -- both unaccented).
- **Impact:** The "accented" array is misleading and redundant.
- **Fix:** Either add proper accents (`'hydrogene'` -> `'hydrogene'` is actually already unaccented in French -- the game seems to intentionally use unaccented names) or remove the duplicate array.

---

## Summary by File

| File | Critical | High | Medium | Low | Total |
|------|----------|------|--------|-----|-------|
| combat.php | 1 | 0 | 4 | 2 | 7 |
| game_actions.php | 1 | 0 | 2 | 2 | 5 |
| player.php | 0 | 4 | 0 | 4 | 8 |
| formulas.php | 0 | 0 | 3 | 0 | 3 |
| game_resources.php | 0 | 1 | 0 | 3 | 4 |
| config.php | 0 | 0 | 2 | 2 | 4 |
| compounds.php | 0 | 0 | 1 | 1 | 2 |
| resource_nodes.php | 0 | 0 | 1 | 0 | 1 |
| multiaccount.php | 0 | 0 | 1 | 2 | 3 |
| display.php | 0 | 0 | 3 | 2 | 5 |
| ui_components.php | 0 | 0 | 0 | 2 | 2 |
| prestige.php | 0 | 0 | 1 | 1 | 2 |
| db_helpers.php | 0 | 0 | 0 | 1 | 1 |
| catalyst.php | 0 | 0 | 1 | 0 | 1 |
| Cross-file (player.php scope) | 0 | 1 | 0 | 3 | 4 |
| **TOTAL** | **2** | **6** | **19** | **25** | **52** |

*Note: Some findings span multiple files. The table shows the primary location.*

*Note: Some findings span multiple files and are counted in the primary file.*

---

## Recommended Fix Priority

### Immediate (before next deployment)
1. P2-D2-001: Fix all 11 logError() callsites (5 minutes)
2. P2-D2-005: Fix attack_cooldowns column name in DELETE statements (2 minutes)
3. P2-D2-003: Reorder supprimerJoueur() to fix connectes cleanup (5 minutes)

### Next Sprint
4. P2-D2-004: Add missing table cleanup in supprimerJoueur() (5 minutes)
5. P2-D2-002: Remove dead $activeReactions code block (2 minutes)
6. P2-D2-006: Initialize $nombre1-4 before molecule loop (1 minute)
7. P2-D2-007/008: Fix division-by-zero in initPlayer() (3 minutes)
8. P2-D2-013: Fix email typo (1 minute)
9. P2-D2-015/016/017: Add null guards in coefDisparition() (5 minutes)
10. P2-D2-035: Fix prestige timestamp column (derniereConnexion, not timestamp) (1 minute)

### Backlog
11. P2-D2-010/011/012: Replace hardcoded 4-class references with loops
12. P2-D2-022: Add INF guard in chiffrePetit()
13. P2-D2-025: Wrap envoi processing in transaction
14. P2-D2-028: Refactor variable variables to arrays (large effort)
