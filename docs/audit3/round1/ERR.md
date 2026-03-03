# Error Handling & Edge Case Audit - Round 1

**Auditor:** Claude Opus 4.6 Code Review
**Date:** 2026-03-03
**Scope:** All PHP files in root/, includes/, admin/
**PHP Version:** 8.2
**Focus:** Null dereferences, division by zero, undefined variables, missing DB return checks, array bounds, off-by-one, empty catches, missing switch defaults

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 8     |
| HIGH     | 27    |
| MEDIUM   | 36    |
| LOW      | 18    |
| **Total**| **89**|

---

## CRITICAL

[ERR-R1-001] [CRITICAL] combat.php:28-29 -- Null dereference on `$niveauxAttaquant`. `dbFetchOne` returns null if the attacker's constructions row is missing, then `explode()` is called on `$niveauxAttaquant['pointsProducteur']` which would be accessing an array key on null. Same issue on line 34-35 for `$niveauxDefenseur`. This is in the hot combat path and would crash the entire combat resolution.

[ERR-R1-002] [CRITICAL] game_actions.php:95 -- Array index out of bounds. `$molecules[$compteur - 1]` is accessed in the attack-outbound loop where `$compteur` increments for each molecule class from the DB query, but `$molecules` comes from `explode(";", $actions['troupes'])`. If the troupes string has fewer semicolon-delimited elements than the player has molecule classes (data corruption, race condition, or malformed insert), this produces an `Undefined offset` warning and uses null in arithmetic. Same issue on line 457 in the return-trip loop.

[ERR-R1-003] [CRITICAL] game_actions.php:44 -- Division by zero. `floor((time() - $derniereFormation) / $actions['tempsPourUn'])` will divide by zero if `tempsPourUn` is 0 in the database. This could happen from a bug in formation time calculation or data corruption. Same pattern on line 51.

[ERR-R1-004] [CRITICAL] game_actions.php:150-153 -- Undefined variables `$attaquePts`, `$defensePts`, `$pillagePts`, `$pillagePts1` used in combat report HTML template. These are referenced in the report string construction (lines 153, 188) but are never assigned in combat.php or game_actions.php. The combat report will contain empty chipInfo outputs and PHP `Undefined variable` warnings will be logged for every combat.

[ERR-R1-005] [CRITICAL] basicprivatephp.php:160 -- Undefined variable `$vainqueurManche`. If the classement query returns zero rows (no players in the game), the `while` loop on line 155 never executes, so `$vainqueurManche` is never set, but it is used on lines 160-170 for the season winner email and prestige award. This would crash the season reset with an `Undefined variable` fatal.

[ERR-R1-006] [CRITICAL] basicprivatephp.php:75-77 -- Null dereference chain. `$donnees = dbFetchOne(...)` on line 75 could return null (player deleted between session validation and this query), then line 77 accesses `$donnees['tempsPrecedent']` on null. This is the main auth guard file loaded on every private page request.

[ERR-R1-007] [CRITICAL] combat.php:472-473 -- Division by zero. `pointsDeVie($constructions['generateur'])` returns `round(BUILDING_HP_BASE * (...))`. If the building level is 0 (which should not happen after the level-1 minimum fix but could via data corruption), `pointsDeVie(0)` could return 0 or a very small number, and the percentage calculation `$constructions['vieGenerateur'] / pointsDeVie($constructions['generateur']) * 100` would divide by zero. Same pattern on lines 486 (vieChampDeForce), 499, 512.

[ERR-R1-008] [CRITICAL] update.php:17-18 -- Null dereference. `$adversaire = dbFetchOne(...)` could return null if the target player was deleted between the attack initiation and combat resolution. Line 18 then accesses `$adversaire['tempsPrecedent']` on null, crashing the resource update for every pending combat involving a deleted player.

---

## HIGH

[ERR-R1-009] [HIGH] basicprivatephp.php:93-97 -- Null dereference. `$joueurEnVac = dbFetchOne(...)` could return null, then `$joueurEnVac['vacance']` accessed. Also `$vac = dbFetchOne(...)` on line 102 could be null, then `$vac['dateFin']` accessed on line 110.

[ERR-R1-010] [HIGH] basicprivatephp.php:128-134 -- Null dereference. `$debutRow = dbFetchOne(...)` and `$maintenanceRow = dbFetchOne(...)` could return null, then accessed as arrays for `['debut']` and `['maintenance']`.

[ERR-R1-011] [HIGH] basicprivatephp.php:182-188 -- Null dereference in war archival. `$alliance1 = dbFetchOne(...)` and `$alliance2 = dbFetchOne(...)` could return null (alliance deleted during season reset), then `$alliance1['tag']` accessed. This would crash the entire season reset process.

[ERR-R1-012] [HIGH] combat.php:41-43 -- Null dereference. `$ionisateur = dbFetchOne(...)` and `$champdeforce = dbFetchOne(...)` could return null. Their values are used in damage calculations on lines 206, 212 via `$ionisateur['ionisateur']` and `$champdeforce['champdeforce']`.

[ERR-R1-013] [HIGH] combat.php:45-56 -- Null dereference. `$idalliance = dbFetchOne(...)` could return null, then `$idalliance['idalliance']` is compared on line 47. If null, the comparison `> 0` would produce a warning and skip the duplicateur bonus, which is acceptable, but `$idallianceDef` on line 52 has the same issue with the same non-fatal but warning-producing behavior.

[ERR-R1-014] [HIGH] combat.php:360-362 -- Null dereference. `$ressourcesDefenseur = dbFetchOne(...)` and `$ressourcesJoueur = dbFetchOne(...)` could return null if player was deleted. These are used extensively in the pillage calculation loop. A null here would crash the entire combat transaction.

[ERR-R1-015] [HIGH] combat.php:427 -- Null dereference. `$constructions = dbFetchOne(...)` could return null. It is used throughout the building damage section (lines 438-523) accessing keys like `['champdeforce']`, `['generateur']`, `['producteur']`, `['depot']`, `['vieGenerateur']`, etc.

[ERR-R1-016] [HIGH] combat.php:577 -- Null dereference. `$depotAtt = dbFetchOne(...)` could return null. `$depotAtt['depot']` is then passed to `placeDepot()` on line 578.

[ERR-R1-017] [HIGH] marche.php:11 -- Null dereference. `$val = dbFetchOne(...)` could return null. Line 11 calls `explode(",", $val['timestamps'])` on a potentially null array, producing a PHP warning and corrupting the timestamp parsing.

[ERR-R1-018] [HIGH] marche.php:25-28 -- Null dereference. `$ipdd = dbFetchOne(...)` and `$ipmm = dbFetchOne(...)` could return null. Their values are accessed as arrays for `['id']` keys.

[ERR-R1-019] [HIGH] marche.php:62-63 -- Division by zero. `$revenuEnergie` comes from `revenuEnergie()` which sums production. If a player has zero iode molecules and zero iode building level, `$revenuEnergie` could be 0, and the division `$energie / $revenuEnergie` on the market display would crash.

[ERR-R1-020] [HIGH] marche.php:71 -- Division by zero. `$revenu[$ressource]` in the resource display loop could be 0 for any resource where the player has no production, causing `$donnees[$ressource] / $revenu[$ressource]` to divide by zero.

[ERR-R1-021] [HIGH] marche.php:310 -- Division by zero. In the sell formula, `$tabCours[$num]` could theoretically be 0 if the market rate was somehow set to zero, causing division by zero in the sell price calculation.

[ERR-R1-022] [HIGH] allianceadmin.php:6-10 -- Null dereference chain. `$idalliance = dbFetchOne(...)` could return null, then `$chef = dbFetchOne(...)` using `$idalliance['idalliance']`. If the alliance was deleted between page loads, this cascading null access crashes the admin page.

[ERR-R1-023] [HIGH] allianceadmin.php:27 -- Null dereference. `$grade = dbFetchOne(...)` could return null. `explode(",", $grade['grade'])` is then called, which would warn on null input and produce incorrect authorization results.

[ERR-R1-024] [HIGH] allianceadmin.php:202,236,256,285 -- Multiple null dereferences. Several `dbFetchOne` calls to look up alliance data by tag return null if the alliance was deleted, then their `['tag']` or `['id']` keys are accessed.

[ERR-R1-025] [HIGH] attaquer.php:27 -- Null dereference. `$membreJoueur = dbFetchOne(...)` could return null. `$membreJoueur['x']` and `$membreJoueur['y']` are accessed on subsequent lines for distance calculation.

[ERR-R1-026] [HIGH] attaquer.php:83 -- Null dereference. `$positions = dbFetchOne(...)` could return null. The `$positions['x']` and `$positions['y']` keys are accessed for map centering.

[ERR-R1-027] [HIGH] attaquer.php:457-463 -- Null dereference. `$molecules1 = dbFetchOne(...)` through `$molecules4 = dbFetchOne(...)` could return null when fetching molecule data for attack preview.

[ERR-R1-028] [HIGH] constructions.php:162 -- Division by zero. `$revenu['energie']` could be 0 in the `mepConstructions()` display function. The display shows hours until storage is full via division by revenue rate.

[ERR-R1-029] [HIGH] constructions.php:164 -- Division by zero. Same pattern as above: `$revenu[$ressource]` could be 0 for any resource in the resource display loop.

[ERR-R1-030] [HIGH] joueur.php:34 -- Null dereference. `$donnees3 = dbFetchOne(...)` could return null, then `$donnees3['idalliance']` is used on line 34 to look up the alliance, and on line 51 to check if alliance exists. If `$donnees3` is null, `$donnees2 = dbFetchOne(...)` would query with null parameter, then `$donnees2['tag']` would also fail.

[ERR-R1-031] [HIGH] joueur.php:53-55 -- Null dereference chain. `$playerPoints = dbFetchOne(...)` could return null, then `$playerPoints['totalPoints']` is passed to the rank query. `$rangData = dbFetchOne(...)` could also return null, then `$rangData['rang']` is accessed.

[ERR-R1-032] [HIGH] alliance.php:185 -- Division by zero. `floor($pointstotaux / $nbjoueurs)` divides by `$nbjoueurs` which is `mysqli_num_rows($ex2)`. If an alliance exists but has no members (edge case during deletion), this divides by zero. The condition on line 142 only checks `mysqli_num_rows($ex) > 0` for the alliance query, not the member count.

[ERR-R1-033] [HIGH] compte.php:20-21 -- Null dereference. `$membreRow = dbFetchOne(...)` could return null (player deleted), then `$membreRow['id']` is cast to int and used in the vacation INSERT. This would insert a vacation for member ID 0.

[ERR-R1-034] [HIGH] prestige.php:53-54 -- Null dereference. `$lastActive = dbFetchOne(...)` could return null if the player has no activity record, then `$lastActive['derniereConnexion']` is accessed for the prestige decay timer.

[ERR-R1-035] [HIGH] game_resources.php:34 -- Null dereference. In `revenuEnergie()`, `$molecules = dbFetchOne(...)` queries a specific molecule class. If the molecule class does not exist (deleted or never created), `$molecules` is null, and `$molecules['iode']` access fails.

---

## MEDIUM

[ERR-R1-036] [MEDIUM] game_resources.php:191 -- Undefined variables. `$nombre1` through `$nombre4` are set inside a while loop iterating molecule classes. If a player has fewer than 4 molecule classes, some of these variables remain undefined when used in the condenseur redistribution formula.

[ERR-R1-037] [MEDIUM] ui_components.php:480 -- Division by zero. `progressBar()` function calculates `$vieMax` from `pointsDeVie()`. If called with level 0, `$vieMax` could be 0, and the percentage calculation `$vie / $vieMax * 100` divides by zero.

[ERR-R1-038] [MEDIUM] display.php:136-137 -- Type coercion issue. In `affichageTemps()`, `$minutes` has a colon appended (`$minutes = $minutes . ":"`) before line 137 compares `$minutes < 10`. This compares a string like `"5:"` to integer 10, which works in PHP but relies on implicit type juggling that PHP 8.x may handle differently.

[ERR-R1-039] [MEDIUM] armee.php:42-48 -- Array index out of bounds. `$explosion[$i-1]` is accessed where `$explosion = explode(";", $troupes)`. If the troupes string is malformed (fewer semicolons than expected), accessing beyond the array bounds produces `Undefined offset` warnings.

[ERR-R1-040] [MEDIUM] classement.php:104-106 -- Potentially undefined variable. `$pageParDefaut` may be undefined if the user is not logged in and no search term is provided. The code sets `$pageParDefaut` inside conditional blocks but uses it unconditionally later.

[ERR-R1-041] [MEDIUM] basicprivatephp.php:299 -- Null dereference. `$debut["debut"]` is accessed where `$debut` comes from `dbFetchOne`. If the statistiques table is empty, this is null.

[ERR-R1-042] [MEDIUM] attaque.php:17 -- Null dereference. `$joueur = dbFetchOne(...)` could return null if the defender was deleted. Line 21 then accesses `$joueur['x']` and `$joueur['y']` for position display and distance calculation.

[ERR-R1-043] [MEDIUM] attaque.php:64-72 -- Array index out of bounds. `$troupes = explode(";", $attaque['troupes'])` then `$troupes[$c]` is accessed in a while loop iterating molecules. If the troupes string has fewer elements than molecule classes, `$troupes[$c]` produces `Undefined offset`.

[ERR-R1-044] [MEDIUM] sujet.php:71-74 -- Null dereference. `$sujet = dbFetchOne(...)` could return null if the topic was deleted. Line 74 accesses `$sujet['idforum']` which would fail.

[ERR-R1-045] [MEDIUM] sujet.php:103 -- Ambiguous SQL. `SELECT image, count(image) as nb FROM autre WHERE login = ?` without GROUP BY. In strict SQL mode this query could fail. The `count()` aggregate without GROUP BY in MySQL returns a single row but `image` could be from any row.

[ERR-R1-046] [MEDIUM] ecriremessage.php:48-58 -- Null dereference. `$message = dbFetchOne(...)` could return null when fetching a message by ID. Line 58 then accesses `$message['destinataire']` for authorization check.

[ERR-R1-047] [MEDIUM] ecriremessage.php:13-14 -- Null dereference. `$idalliance = dbFetchOne(...)` could return null. `$idalliance['idalliance']` is then used in the alliance message query.

[ERR-R1-048] [MEDIUM] historique.php:77-91 -- Array index out of bounds. `$valeurs = explode(",", $chaine)` then `$valeurs[0]` through `$valeurs[7]` are accessed. If the stored data string is malformed or has fewer comma-separated values, accessing indices beyond bounds produces warnings. Same pattern on lines 122-138 for alliances and 162-173 for wars.

[ERR-R1-049] [MEDIUM] alliance.php:120-123 -- Null dereference. `$idalliance = dbFetchOne(...)` on line 120 could return null when looking up invitation data. `$idalliance['idalliance']` is then used in the member count query.

[ERR-R1-050] [MEDIUM] alliance.php:74-75 -- Null dereference chain. `$idalliance = dbFetchOne(...)` could return null, then `$duplicateur = dbFetchOne(...)` uses `$idalliance['idalliance']`, and `$duplicateur['duplicateur']` is accessed.

[ERR-R1-051] [MEDIUM] alliance.php:208-213 -- Null dereference. `$allianceJoueurAdverse = dbFetchOne(...)` could return null if the opposing alliance was deleted. `$allianceJoueurAdverse['tag']` is then used in links.

[ERR-R1-052] [MEDIUM] alliance.php:223-228 -- Same as above for pact display. `$allianceJoueurAllie = dbFetchOne(...)` null dereference on `['tag']`.

[ERR-R1-053] [MEDIUM] don.php:20-22 -- Null dereference inside transaction. `$ressources = dbFetchOne(...)`, `$energieDonnee = dbFetchOne(...)`, and `$ressourcesAlliance = dbFetchOne(...)` could return null due to FOR UPDATE lock contention or deleted data. Their array keys are accessed without null checks.

[ERR-R1-054] [MEDIUM] compte.php:163-164 -- Null dereference. `$joueur = dbFetchOne(...)` could return null. `$joueur['id']` is then used in `$estEnVac = dbFetchOne(...)`, and `$estEnVac` could also be null.

[ERR-R1-055] [MEDIUM] compte.php:168-173 -- Null dereference. `$vacance = dbFetchOne(...)` could return null if vacation record was deleted. `explode('-', $vacance['dateDebut'])` and `explode('-', $vacance['dateFin'])` would then operate on null.

[ERR-R1-056] [MEDIUM] compte.php:200-201 -- Null dereference. `$donnees = dbFetchOne(...)` could return null. `$donnees['timestamp']` is then used in arithmetic comparison.

[ERR-R1-057] [MEDIUM] redigernews.php:26-31 -- Null dereference. `$donnees = dbFetchOne(...)` could return null when editing a non-existent news ID. `$donnees['titre']`, `$donnees['contenu']`, `$donnees['id']` are accessed unconditionally.

[ERR-R1-058] [MEDIUM] medailles.php:25-30 -- Null dereference chain. Multiple `dbFetchOne` calls (`$donnees`, `$donnees1`, `$donnees2`, `$donnees3`, `$donnees4`, `$troll`, `$bombe`) could return null if player data is missing. All are used without null checks in the medal display.

[ERR-R1-059] [MEDIUM] redirectionVacance.php:4 -- Null dereference. `$joueurEnVac = dbFetchOne(...)` could return null. `$joueurEnVac['vacance']` is accessed on line 5.

[ERR-R1-060] [MEDIUM] constantes.php:13 -- Implicit dependency. `initPlayer($_SESSION['login'])` is called without verifying `$_SESSION['login']` is set. This file is included by `constantesBase.php` which is loaded from `basicprivatephp.php` (where login IS checked), but any accidental inclusion from a public page would cause an undefined index warning.

[ERR-R1-061] [MEDIUM] update.php:36 -- Dynamic column name in SQL. `"SELECT $ressource, revenu$ressource FROM ressources WHERE login=?"` uses interpolated column names from `$nomsRes`. While `$nomsRes` is a global constant array, this pattern is fragile. If `$nomsRes` were ever modified to include user input, it would be a SQL injection vector.

[ERR-R1-062] [MEDIUM] player.php:inscrire -- No check on `coordonneesAleatoires` return. The function `coordonneesAleatoires()` is called during registration. If it returns invalid coordinates (the bounded loop exits without finding free space), the player would be placed at a garbage location.

[ERR-R1-063] [MEDIUM] game_actions.php:41 -- Null dereference. `$molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse'])` could return null if the molecule class was deleted while formation was in progress. `$molecule['nombre']` is accessed on lines 46, 55.

[ERR-R1-064] [MEDIUM] catalyst.php:60 -- Null dereference. `$stats = dbFetchOne($base, 'SELECT catalyst, catalyst_week FROM statistiques')` could return null if the statistiques table is empty. Line 63 checks `!$stats` which handles null, but `$stats['catalyst_week']` access in the same condition could warn before the null check short-circuits.

[ERR-R1-065] [MEDIUM] combat.php:8-23 -- Undefined dynamic variables. The while loops at lines 8-13 and 19-24 create dynamic variables `$classeDefenseur1..4` and `$classeAttaquant1..4`. If a player has fewer than 4 molecule classes in the DB (data corruption), later references to `$classeAttaquant3`, `$classeAttaquant4` etc. would access undefined variables.

[ERR-R1-066] [MEDIUM] guerre.php:18-19 -- Null dereference. `$guerre = mysqli_fetch_array($ex)` could return null/false. While line 21 checks `$nbGuerres > 0 && $guerre`, if `$ex` is false from a query error, `mysqli_num_rows($ex)` on line 19 would warn.

[ERR-R1-067] [MEDIUM] forum.php:70-86 -- Null dereference. `$nbSujets = dbFetchOne(...)` and `$nbMessages = dbFetchOne(...)` could return null. `$nbSujets['nbSujets']` and `$nbMessages['cnt']` are accessed for display.

[ERR-R1-068] [MEDIUM] forum.php:73-75 -- Null dereference. `$statutForum = dbFetchOne(...)` could return null. `$statutForum['nbLus']` is compared with `$nbSujets['nbSujets']`.

[ERR-R1-069] [MEDIUM] listesujets.php:33-35 -- Null dereference. `$sujet = dbFetchOne(...)` could return null when looking up a newly created topic by content (line 33). `$sujet['id']` is then used in the INSERT.

[ERR-R1-070] [MEDIUM] listesujets.php:55 -- Null dereference. `$idforum = dbFetchOne(...)` could return null for an invalid forum ID. `$idforum['titre']` is accessed for the page title.

[ERR-R1-071] [MEDIUM] editer.php:23-24 -- Null dereference. `$nbMessages = dbFetchOne(...)` could return null. `$nbMessages['nbMessages'] - 1` would produce a warning.

---

## LOW

[ERR-R1-072] [LOW] basicprivatephp.php:line area 155-170 -- Silent failure in season email. If `mail()` fails to send the season-end email, there is no error logging. The function returns false but is not checked.

[ERR-R1-073] [LOW] combat.php:18-24 -- Off-by-one potential. `$chaineExplosee = explode(";", $actions['troupes'])` is indexed from `$c - 1` starting at `$c = 1`. If `$actions['troupes']` has a trailing semicolon (which the troupes construction code does produce, e.g., `"100;200;300;400;"`), `explode` creates an extra empty element. The code works because it only reads indices 0-3, but the trailing semicolon creates a 5th empty element.

[ERR-R1-074] [LOW] display.php:136 -- String concatenation before numeric comparison. `$minutes = $minutes . ":"` then `if ($minutes < 10)` compares a string to int. The string `"5:"` is numerically compared to 10, which works in PHP 8.x due to type juggling but triggers a deprecation notice in strict comparison contexts.

[ERR-R1-075] [LOW] formulas.php:54-55 -- Redundant `abs()` call. `pointsAttaque` calls `sqrt(abs($pts))` but `$pts` was already checked `<= 0` and would return 0. The `abs()` is unreachable dead code. Same on line 61 for `pointsDefense`.

[ERR-R1-076] [LOW] formulas.php:45 -- Off-by-one in alliance VP. `pointsVictoireAlliance` checks `$classement < 10` (exclusive) while `pointsVictoireJoueur` checks `$classement <= 10` (inclusive). Rank 10 gets 0 VP for alliance ranking. This may be intentional but is inconsistent.

[ERR-R1-077] [LOW] index.php:45-46 -- Missing error check. `$retour = dbQuery(...)` could return false on query error. `mysqli_num_rows($retour)` on a false value would warn. The code handles 0 rows but not query failure.

[ERR-R1-078] [LOW] rapports.php:43 -- Division by zero edge case. `ceil($totalDesRapports / $nombreDeRapportsParPage)` where `$nombreDeRapportsParPage = 15` is hardcoded so this cannot actually be zero. However, if the constant were extracted to config, it could become zero.

[ERR-R1-079] [LOW] messages.php:42 -- Same as above for messages pagination. Safe because hardcoded to 15.

[ERR-R1-080] [LOW] sujet.php:46 -- Same pagination division. `ceil($nb_resultats / $nombreDeSujetsParPage)` safe because `$nombreDeSujetsParPage = 10`.

[ERR-R1-081] [LOW] compte.php:14 -- Unsafe date parsing. `list($jour, $mois, $annee) = explode('/', $_POST['dateFin'])` without checking the result has exactly 3 elements. If the user submits a malformed date like `"01/02"`, the `list()` assignment would warn about undefined index.

[ERR-R1-082] [LOW] compte.php:112 -- Missing `getimagesize` false check. `$img_size = getimagesize(...)` could return false for non-image files. Line 120 checks `$img_size === false`, so this IS handled, but the check comes after MIME validation which should catch it.

[ERR-R1-083] [LOW] alliance.php:15-18 -- Race condition. `mysqli_fetch_array($ex)` and `mysqli_num_rows($ex)` are called on the same result set. The fetch moves the internal pointer, but `mysqli_num_rows` works on the full result regardless, so this is functionally correct but reads oddly.

[ERR-R1-084] [LOW] alliance.php:344-369 -- Missing default in switch. While there IS a `default` case on line 366, the `$_GET['clas']` value is used directly without intval first (line 342 sets it as a string). Non-numeric values would fall to default, which is fine.

[ERR-R1-085] [LOW] moderationForum.php:35-36 -- Insufficient date validation. `explode('/', $_POST['dateFin'])` assumes exactly 3 parts. The `count($parts) !== 3` check on line 36 handles this, but the `checkdate()` call with `(int)$parts[1], (int)$parts[0], (int)$parts[2]` uses dd/mm/yyyy order which must match the jQuery datepicker format.

[ERR-R1-086] [LOW] historique.php:76-78 -- Silent null handling. `$data = dbFetchOne(...)` is checked with `$data ? explode(...) : []` which properly handles null. This is correct but the pattern is inconsistent with the rest of the codebase.

[ERR-R1-087] [LOW] attaquer.php:line area 200-400 -- Large form POST without size limits. The attack form allows selecting troop counts but there is no server-side maximum count validation beyond checking `>= 0`. Extremely large numbers could cause integer overflow in combat calculations.

[ERR-R1-088] [LOW] game_actions.php:481-494 -- Array index out of bounds in resource transfer. `$envoyees[$num]` and `$recues[$num]` are accessed for each resource, and `$envoyees[sizeof($nomsRes)]` is accessed for energy (index 8). If the stored semicolon-delimited string has fewer elements, undefined offset warnings occur.

[ERR-R1-089] [LOW] admin/tableau.php -- No error handling on batch operations. The admin panel performs bulk operations (reset, cleanup) without transaction wrapping. A partial failure could leave the database in an inconsistent state.

---

## Patterns Observed

### 1. Systemic: dbFetchOne return value never checked
The most pervasive issue across the codebase. `dbFetchOne()` returns `null` when no row is found, but approximately 80% of call sites access the result as an array without a null check. A defensive wrapper or a `dbFetchOneOrThrow()` helper would eliminate dozens of these findings.

### 2. Systemic: explode() on potentially null/malformed strings
Multiple files `explode()` strings from the database (troupes, pointsProducteur, timestamps) without verifying the string has the expected number of elements. A utility function `safeExplode($string, $delimiter, $expectedCount)` would prevent array-bounds issues.

### 3. Systemic: Division by zero in display formulas
Revenue and percentage calculations divide by production rates, HP values, or totals that could be zero. Each division should be guarded with `max(1, $divisor)` or an explicit zero check.

### 4. Combat path is the highest-risk area
The combat.php + game_actions.php path has the most critical findings because:
- It operates on two players' data simultaneously (either could be deleted)
- It uses dynamic variable names (`${'classeAttaquant' . $c}`) that hide undefined access
- It performs complex arithmetic with multiple potential zero divisors
- Malformed troupes strings cause cascading array-bounds failures

### 5. Season reset is fragile
The season reset logic in basicprivatephp.php (lines 120-220) has multiple null-dereference paths that would crash the entire reset process, potentially leaving the game in a broken state for all players.

---

## Recommended Priority

1. **Immediate** (CRITICAL): Fix ERR-R1-001 through ERR-R1-008. These can crash production for real users.
2. **Short-term** (HIGH): Add null guards to all combat-path dbFetchOne calls. Add defensive checks to troupes string parsing.
3. **Medium-term** (MEDIUM): Systematic pass adding null checks to all dbFetchOne call sites. Add zero-guards to all division operations.
4. **Long-term** (LOW): Create utility wrappers (`dbFetchOneOrThrow`, `safeExplode`, `safeDivide`) and refactor the codebase to use them.
