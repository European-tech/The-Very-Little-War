# Performance Audit -- Round 1

**Auditor:** Claude Opus 4.6 (Performance Engineer)
**Date:** 2026-03-03
**Scope:** PHP 8.2 + MariaDB 10.11 game codebase -- all files specified in audit brief
**Method:** Manual line-by-line analysis of every specified file

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 5     |
| HIGH     | 18    |
| MEDIUM   | 16    |
| LOW      | 9     |
| **Total**| **48**|

The single largest performance problem in this codebase is the **authenticated page-load pipeline**. Every authenticated page request traverses `basicprivatephp.php -> constantes.php -> initPlayer() -> updateRessources() -> updateActions()`, which triggers between **60 and 120+ database queries** depending on player state. The root cause is a cascade of small functions (`revenuAtome`, `revenuEnergie`, `coefDisparition`, `allianceResearchBonus`) that each independently query 2-3 tables, and these functions are called inside loops.

---

## CRITICAL (5)

### [PERF-R1-001] CRITICAL includes/basicprivatephp.php:60,104 -- Double include of constantes.php triggers initPlayer() twice per page load

`constantes.php` is included at line 60 (unconditionally) and again at line 104 (inside the non-vacation branch). Each include calls `initPlayer($_SESSION['login'])` which performs 4-5 DB queries plus calls to `revenuAtome()` (8 calls x 3 queries each = 24 queries) and `revenuEnergie()` (6+ queries). The per-request cache in `initPlayer()` mitigates the second call, but only after the cache is populated -- the first call still runs the full pipeline. More critically, `updateRessources()` at line 98 invalidates portions of the data that `initPlayer()` cached, making the second call at line 104 partially redundant but still necessary. The double include is wasteful and confusing.

**Impact:** ~30 wasted queries on every authenticated page load if cache is invalidated between calls.
**Fix:** Remove the first include at line 60 or restructure so constantes.php is included once after resource updates.

---

### [PERF-R1-002] CRITICAL includes/game_resources.php:146-154 -- revenuAtome() called 8 times inside updateRessources(), each making 3 DB queries

Inside `updateRessources()`, the `foreach ($nomsRes)` loop at line 146 calls `revenuAtome($num, $joueur)` for each of 8 atom types. Each `revenuAtome()` call (lines 71-82) performs:
1. `SELECT pointsProducteur FROM constructions WHERE login=?` (line 71)
2. `SELECT idalliance FROM autre WHERE login=?` (line 75)
3. `SELECT duplicateur FROM alliances WHERE id=?` (line 78, conditional)

This is 8 x 3 = **24 DB queries** just for atom revenue calculation, all querying the same rows.

**Impact:** 24 redundant queries per `updateRessources()` call, which runs on every authenticated page load.
**Fix:** Fetch `constructions`, `autre`, and `alliances` once and pass the data into `revenuAtome()`.

---

### [PERF-R1-003] CRITICAL includes/game_resources.php:7-63 -- revenuEnergie() makes 6+ separate DB queries for data that is already available or could be batched

`revenuEnergie()` queries:
- Line 14: `SELECT * FROM constructions WHERE login=?`
- Line 21: `SELECT producteur FROM constructions WHERE login=?` (same table, second query!)
- Line 23: `SELECT idalliance,totalPoints FROM autre WHERE login=?`
- Line 26: `SELECT duplicateur FROM alliances WHERE id=?` (conditional)
- Lines 32-35: 4 separate queries inside a loop: `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?` for classes 1-4
- Line 38: `SELECT energieDepensee FROM autre WHERE login=?` (same table as line 23!)

Total: 6-10 queries per call, with lines 14 and 21 querying `constructions` twice, and lines 23 and 38 querying `autre` twice.

**Impact:** Called once from `updateRessources()` (line 130) and once from `initPlayer()` (line 172). 12-20 redundant queries per page load.
**Fix:** Accept pre-fetched data as parameters or consolidate into a single multi-table query.

---

### [PERF-R1-004] CRITICAL includes/formulas.php:175-226 -- coefDisparition() makes 3 DB queries per call, called N times in loops

`coefDisparition()` queries:
- Line 183: `SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?`
- Line 186: `SELECT stabilisateur FROM constructions WHERE login=?`
- Line 188: `SELECT moleculesPerdues FROM autre WHERE login=?`

This function is called:
1. Inside `updateRessources()` molecule decay loop (line 174) -- 4 calls = 12 queries
2. Inside `updateActions()` attack processing loop (lines 95, 457) -- N calls per combat action
3. Inside `updateTargetResources()` (update.php line 52) -- 4 calls = 12 queries

**Impact:** 12-36+ queries per page load from decay calculation alone. The `constructions` and `autre` data is the same for all molecule classes of the same player.
**Fix:** Fetch `constructions.stabilisateur` and `autre.moleculesPerdues` once per player and pass them in, or add a per-player cache inside the function.

---

### [PERF-R1-005] CRITICAL includes/basicprivatephp.php:75-89 -- Online tracking writes on every single page load

Every authenticated page load performs:
- Line 75: `SELECT COUNT(*) FROM connectes WHERE ip=?`
- Line 80 or 84: `INSERT` or `UPDATE` on `connectes`
- Line 89: `DELETE FROM connectes WHERE timestamp < ?` (purges stale entries)

This is 3 DB operations (1 read + 2 writes) on every single page load, and the DELETE is a scan of the entire `connectes` table.

**Impact:** 3 operations on every request. The DELETE at line 89 scans a table that could have many rows if multiple users are online. Write operations are expensive and generate WAL/redo log I/O.
**Fix:** Use `INSERT ... ON DUPLICATE KEY UPDATE` to merge the SELECT+INSERT/UPDATE into one operation. Run the DELETE purge probabilistically (e.g., 1 in 10 requests) or via a cron job.

---

## HIGH (18)

### [PERF-R1-006] HIGH includes/game_resources.php:169-184 -- N+1 query inside molecule decay loop in updateRessources()

Lines 169-184: The `while ($molecules = mysqli_fetch_array($ex))` loop iterates over all 4 molecule classes. Inside the loop:
- Line 174: Calls `coefDisparition()` which does 3 queries (see PERF-R1-004)
- Line 180: `SELECT moleculesPerdues FROM autre WHERE login=?` (re-queried every iteration!)
- Line 181: `UPDATE autre SET moleculesPerdues=? WHERE login=?`
- Line 178: `UPDATE molecules SET nombre=? WHERE id=?`

Total inside loop: 3 (coefDisparition) + 1 (SELECT) + 2 (UPDATEs) = 6 queries x 4 classes = **24 queries**.

**Impact:** 24 queries per page load in the decay calculation.
**Fix:** Read `moleculesPerdues` once before the loop. Accumulate the total and do one UPDATE after. Pass pre-fetched data to `coefDisparition()`.

---

### [PERF-R1-007] HIGH includes/game_actions.php:90-103 -- N+1 queries inside attack processing loop

Inside the attack processing `while` loop, for each molecule class:
- Line 90: `SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC`
- Line 95: Calls `coefDisparition()` (3 queries each)
- Line 99: `SELECT moleculesPerdues FROM autre WHERE login=?`
- Line 100: `UPDATE autre SET moleculesPerdues=? WHERE login=?`

Per combat action: 4 molecule classes x (3 + 1 + 1) = **20 queries** just for travel decay.

**Impact:** 20+ queries per pending attack resolution. Multiple attacks compound this.
**Fix:** Pre-fetch `constructions`, `autre`, and `molecules` outside the inner loop.

---

### [PERF-R1-008] HIGH includes/game_actions.php:452-465 -- N+1 queries in return trip processing (duplicate of attack path)

Lines 452-465 repeat the same N+1 pattern as PERF-R1-007 but for the return trip:
- Line 452: `SELECT * FROM molecules`
- Line 457: `coefDisparition()` (3 queries)
- Line 461: `SELECT moleculesPerdues FROM autre` (inside inner loop!)
- Line 462: `UPDATE autre SET moleculesPerdues=?`

**Impact:** Another 20+ queries per return trip resolution.
**Fix:** Same as PERF-R1-007.

---

### [PERF-R1-009] HIGH includes/game_actions.php:40-41 -- DB query inside formation action loop

Line 41: `$molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse'])` is inside the `while ($actions = mysqli_fetch_array($ex))` loop at line 40. Each pending formation action triggers a full row fetch.

**Impact:** 1 query per pending formation action. Players with multiple formations active multiply this.
**Fix:** Pre-fetch all relevant molecules before the loop using an IN clause or JOIN.

---

### [PERF-R1-010] HIGH includes/db_helpers.php:70-88 -- allianceResearchBonus() makes 2 DB queries per call, called from multiple hot paths

`allianceResearchBonus()` queries:
- Line 72: `SELECT idalliance FROM autre WHERE login=?`
- Line 82: `SELECT $techName FROM alliances WHERE id=?`

This function is called from:
- `tempsFormation()` in formulas.php (line 170) -- called per formation action
- `pointsDeVie()` in formulas.php (lines 241, 251) -- called during combat and building display
- `pillage()` in formulas.php (line 146) -- called during combat
- Various building and combat code paths

No caching. The `autre` and `alliances` data is typically already loaded by `initPlayer()`.

**Impact:** 2-10+ additional queries per page load depending on code path.
**Fix:** Use the already-loaded `$GLOBALS['autre']` data and cache alliance data per-request.

---

### [PERF-R1-011] HIGH includes/player.php:182 -- Unnecessary UPDATE on every initPlayer() call

Line 182: `dbExecute($base, 'UPDATE autre SET batmax=? WHERE login=?', 'is', $plusHaut, $joueur)` runs on every `initPlayer()` call. `batMax()` is a pure computation from `constructions` data that rarely changes. This is a write operation on every page load.

**Impact:** 1 unnecessary write per page load. Writes are expensive (lock acquisition, WAL, etc.).
**Fix:** Only UPDATE when the value actually changes: `if ($plusHaut !== $autre['batmax'])`.

---

### [PERF-R1-012] HIGH includes/player.php:150-154 -- revenuAtome() called 8 times during initPlayer(), duplicating work

Lines 153-154 inside `initPlayer()`:
```php
${'revenu' . $ressource} = revenuAtome($num, $joueur);
$revenu[$ressource] = revenuAtome($num, $joueur);
```
Each atom type calls `revenuAtome()` **twice** (once for the variable, once for the array), and each call makes 2-3 DB queries. This is 16 calls x 2-3 queries = **32-48 queries** just for revenue calculation within initPlayer().

Wait -- on closer inspection, these calls share the same underlying data that could be cached. But the function itself queries `constructions` and `autre` independently each time.

**Impact:** 32-48 redundant queries in `initPlayer()`.
**Fix:** Call `revenuAtome()` once per atom type (not twice), and refactor `revenuAtome()` to accept pre-fetched data.

---

### [PERF-R1-013] HIGH includes/player.php:716-737 -- recalculerStatsAlliances() is a full N+1 over all alliances

Line 720: `SELECT id FROM alliances` fetches ALL alliance IDs.
Line 722: For each alliance, `SELECT * FROM autre WHERE idalliance=?` fetches all members.
Line 735: For each alliance, `UPDATE alliances SET pointstotaux=...`

If there are A alliances with M members each: A + A + A = 3A queries plus iterating M members per alliance.

**Impact:** Called from `classement.php` line 250 when viewing alliance rankings. With 20 alliances of 10 members each, this is 60+ queries.
**Fix:** Use a single aggregating query: `SELECT idalliance, SUM(totalPoints), SUM(points), ... FROM autre GROUP BY idalliance` and batch-UPDATE.

---

### [PERF-R1-014] HIGH forum.php:69-86 -- N+1 queries inside forum listing loop (3 queries per forum)

Inside the `while ($forum = mysqli_fetch_array($ex))` loop:
- Line 70: `SELECT count(*) FROM sujets WHERE idforum=? AND statut=0`
- Line 73: `SELECT count(*) FROM statutforum WHERE login=? AND idforum=?`
- Line 85: `SELECT count(*) FROM sujets s, reponses r WHERE idforum=? AND s.id=r.idsujet`

3 queries per forum. With 8 forums, that is **24 queries** just for the forum listing page.

**Impact:** 24 queries on every forum.php page load.
**Fix:** Use a single JOIN/subquery to get subject counts, read counts, and message counts for all forums in one query.

---

### [PERF-R1-015] HIGH sujet.php:151-187 -- N+1 queries inside reply loop (2-3 queries per reply)

Inside the `while ($reponse = mysqli_fetch_array($ex1))` loop:
- Line 153: `rangForum($reponse['auteur'])` which makes 2 DB queries (see PERF-R1-024)
- Line 162: `SELECT image, count(image) as nb FROM autre WHERE login=?`
- Line 170: `SELECT moderateur FROM membre WHERE login=?` (inside the same loop!)

3-4 queries per reply. A topic page showing 10 replies triggers **30-40 queries** just for reply metadata.

**Impact:** 30-40 queries per topic page view.
**Fix:** Pre-load author images, moderator status, and message counts in batch before the loop.

---

### [PERF-R1-016] HIGH includes/display.php:264-279 -- rangForum() makes 2 DB queries per call, called per forum post

`rangForum()` queries:
- Line 269: `SELECT count(*) AS nbmessages FROM reponses WHERE auteur=?`
- Line 271: `SELECT login FROM membre WHERE login=?`
- Line 279: `SELECT moderateur, login, codeur FROM membre WHERE login=?`

2-3 queries per call. Called once per reply in `sujet.php` (line 153) and once per topic author (line 104).

**Impact:** Multiplied by number of forum posts displayed.
**Fix:** Pre-load all author data in batch. Add index on `reponses.auteur` for the COUNT query.

---

### [PERF-R1-017] HIGH alliance.php:161-178 -- PHP-side aggregation and full table scan for alliance rank

Lines 161-166: Instead of `SELECT SUM(totalPoints) FROM autre WHERE idalliance=?`, the code fetches ALL member rows and sums in PHP:
```php
while ($joueur = mysqli_fetch_array($ex2)) {
    $pointstotaux = $joueur['totalPoints'] + $pointstotaux;
}
```

Lines 170-178: To find the alliance's rank, it fetches ALL alliances ordered by points and iterates until it finds the current one:
```php
$rangQuery = dbQuery($base, 'SELECT tag FROM alliances ORDER BY pointstotaux DESC');
while ($rangEx = mysqli_fetch_array($rangQuery)) {
    if ($rangEx['tag'] == $allianceJoueurPage['tag']) break;
    $rang++;
}
```

**Impact:** Full table scan of `alliances` plus iterating all members, on every alliance page view.
**Fix:** Use SQL `SUM()` for totals. Use a subquery or window function for rank: `SELECT COUNT(*)+1 FROM alliances WHERE pointstotaux > ?`.

---

### [PERF-R1-018] HIGH alliance.php:206-228 -- N+1 queries for war/pact alliance tag lookups

Lines 206-213: Inside the war display loop, each war triggers:
- `SELECT tag FROM alliances WHERE id=?` (line 208 or 211)

Lines 221-228: Inside the pact display loop, each pact triggers:
- `SELECT tag FROM alliances WHERE id=?` (line 223 or 226)

**Impact:** 1 query per war + 1 query per pact. With 5 wars and 3 pacts = 8 extra queries.
**Fix:** Pre-load all relevant alliance tags in one query using an IN clause.

---

### [PERF-R1-019] HIGH allianceadmin.php:354,365,398 -- Same alliance member query repeated 3-4 times

The query `SELECT login FROM autre WHERE idalliance=?` is executed at:
- Line 354 (for chef dropdown)
- Line 365 (for bannir dropdown)
- Line 398 (for grade dropdown)
And again at line 14 (for member count).

Each returns the same result set.

**Impact:** 3-4 identical queries on every allianceadmin.php page load.
**Fix:** Execute once and reuse the result.

---

### [PERF-R1-020] HIGH allianceadmin.php:459-481,507-528 -- N+1 queries for pact and war alliance tags

Lines 459-481: Two loops (alliance1 pacts and alliance2 pacts) each query:
- `SELECT tag FROM alliances WHERE id=?` per declaration

Lines 507-528: Two loops (alliance1 wars and alliance2 wars) each query:
- `SELECT tag FROM alliances WHERE id=?` per declaration

**Impact:** 1 query per pact + 1 query per war, duplicated for both directions.
**Fix:** Pre-load all relevant alliance tags with a single query.

---

### [PERF-R1-021] HIGH includes/update.php:35-43 -- N+1 separate UPDATE queries for resource types

Inside `updateTargetResources()`, the `foreach ($nomsRes)` loop at line 35 executes for each of 8 atom types:
- Line 36: `SELECT $ressource, revenu$ressource FROM ressources WHERE login=?`
- Line 42: `UPDATE ressources SET $ressource=? WHERE login=?`

This is 8 x (1 SELECT + 1 UPDATE) = **16 queries** instead of 1 SELECT + 1 UPDATE.

**Impact:** 16 queries per target resource update (called during attack/espionage processing).
**Fix:** Fetch all resource columns in one SELECT, compute all new values, then issue a single UPDATE with all columns.

---

### [PERF-R1-022] HIGH includes/formulas.php:165-171 -- tempsFormation() queries DB and calls two bonus functions

`tempsFormation()` performs:
- Line 168: `SELECT lieur FROM constructions WHERE login=?`
- Line 169: `catalystEffect('formation_speed')` (cached, 0 queries after first call)
- Line 170: `allianceResearchBonus($joueur, 'formation_speed')` (2 queries, see PERF-R1-010)

Called once per formation time display or calculation. The `constructions` data is already loaded by `initPlayer()`.

**Impact:** 3 queries per call; called from formation display and processing.
**Fix:** Accept pre-fetched `constructions` data; use cached alliance data.

---

### [PERF-R1-023] HIGH includes/game_resources.php:163-167 -- Redundant queries for constructions and autre inside updateRessources()

Lines 163 and 167 re-query tables already queried earlier in the same function:
- Line 163: `SELECT stabilisateur FROM constructions WHERE login=?` (already queried at line 127)
- Line 167: `SELECT moleculesPerdues FROM autre WHERE login=?` (already queried at line 112)

**Impact:** 2 redundant queries per page load.
**Fix:** Reuse the data from lines 112 and 127.

---

## MEDIUM (16)

### [PERF-R1-024] MEDIUM includes/basicprivatephp.php:93 -- Separate DB query for vacation check

Line 93: `SELECT vacance FROM membre WHERE login=?` queries the `membre` table, which is already queried at line 55 (`SELECT x, y FROM membre`) and will be fully loaded by `initPlayer()` at line 60.

**Impact:** 1 redundant query per page load.
**Fix:** Combine with the query at line 55 or use the data from `initPlayer()`.

---

### [PERF-R1-025] MEDIUM includes/basicprivatephp.php:100,102 -- Two separate operations that could be combined

Line 100: `UPDATE membre SET derniereConnexion=? WHERE login=?`
Line 102: `SELECT tempsPrecedent FROM autre WHERE login=?`

The UPDATE at line 100 could be combined with other member updates. The SELECT at line 102 fetches data that `updateRessources()` (line 98) already fetched internally.

**Impact:** 1-2 redundant queries per page load.
**Fix:** Combine the UPDATE with other member writes; pass `tempsPrecedent` from `updateRessources()`.

---

### [PERF-R1-026] MEDIUM includes/basicprivatephp.php:110 -- Subquery for vacation date lookup

Line 110: `SELECT dateFin FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login=?)` uses a subquery instead of a JOIN.

**Impact:** Subquery execution on vacation check. Minor performance impact.
**Fix:** Use a JOIN: `SELECT v.dateFin FROM vacances v JOIN membre m ON v.idJoueur = m.id WHERE m.login = ?`.

---

### [PERF-R1-027] MEDIUM includes/basicprivatephp.php:128-132 -- Two separate queries to statistiques table

Lines 128 and 131 query the same `statistiques` table separately:
```php
$debutRow = dbFetchOne($base, 'SELECT debut FROM statistiques');
$maintenanceRow = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
```

**Impact:** 2 queries where 1 suffices, on every authenticated page load.
**Fix:** `SELECT debut, maintenance FROM statistiques` in one query.

---

### [PERF-R1-028] MEDIUM listesujets.php:86-90 -- N+1 query for forum read status inside subject loop

Line 90 inside the `while ($sujet = mysqli_fetch_array($ex1))` loop:
```php
$statutForum = dbFetchOne($base, 'SELECT count(*) AS luOuPas FROM statutforum WHERE idsujet=? AND login=?', ...)
```

1 query per subject per page (up to 10 per page).

**Impact:** Up to 10 queries per listesujets.php page load.
**Fix:** Pre-fetch all read statuses for the current page's subjects with an IN clause.

---

### [PERF-R1-029] MEDIUM messagesenvoyes.php:10 -- Unbounded query for sent messages

Line 10: `SELECT * FROM messages WHERE expeditaire=? ORDER BY timestamp DESC` fetches ALL sent messages with no LIMIT clause.

**Impact:** Could return hundreds or thousands of rows for active users. All columns fetched including message body.
**Fix:** Add pagination with LIMIT (e.g., 50 per page) consistent with the messages.php pattern.

---

### [PERF-R1-030] MEDIUM historique.php:36 -- Unbounded query for all game history

Line 36: `SELECT * FROM parties ORDER BY id DESC` fetches ALL historical game records with no LIMIT.

**Impact:** Grows indefinitely. Each row contains large text blobs (joueurs, alliances, guerres columns with archived rankings).
**Fix:** Add LIMIT or paginate. Only need the IDs and dates for the dropdown, not the full row data.

---

### [PERF-R1-031] MEDIUM marche.php:578 -- Fetching up to 1000 chart data rows

Line 578: `SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1000` fetches up to 1000 rows for the market chart.

**Impact:** 1000 rows with `SELECT *` is substantial data transfer. The `tableauCours` column is a text field. All this data is rendered inline as JavaScript.
**Fix:** Reduce to last 200-300 points for the chart. Consider server-side aggregation (daily averages) for older data.

---

### [PERF-R1-032] MEDIUM attaquer.php:293 -- Loading ALL players for map display

Line 293: `SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login` loads every single player into a PHP array for the map grid.

**Impact:** Unbounded result set. With 200+ registered players, all rows are fetched and iterated. The map grid is `tailleCarte x tailleCarte` cells which are all rendered as HTML.
**Fix:** Filter to only active players (`WHERE m.x != -1000`). Consider viewport-based loading for large maps.

---

### [PERF-R1-033] MEDIUM attaquer.php:457-463 -- N+1 query per molecule class for JavaScript generation

Lines 457-463 inside a `for` loop:
```php
for ($i = 1; $i <= $nbClasses; $i++) {
    $molecules1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $i);
```

4 queries to generate JavaScript cost-calculation data.

**Impact:** 4 queries per attaquer.php page load (attack form).
**Fix:** Fetch all 4 molecule classes in one query: `SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse`.

---

### [PERF-R1-034] MEDIUM includes/update.php:47-57 -- coefDisparition() called inside molecule decay loop in updateTargetResources()

Line 52: `coefDisparition($targetPlayer, $compteurClasse)` is called inside the `while` loop. Each call makes 3 queries (see PERF-R1-004). With 4 molecule classes: 12 queries.

**Impact:** 12 queries per target resource update during combat/espionage processing.
**Fix:** Pre-fetch player data and pass to a refactored `coefDisparition()`.

---

### [PERF-R1-035] MEDIUM includes/formulas.php:84-86,104-106,134-136 -- attaque(), defense(), pillage() each query DB for medal data when not passed

When `$medalData` is null (many call sites don't pass it), each function executes:
```php
$medalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
```

These are called from combat.php, where the `autre` data is already available.

**Impact:** 1-3 extra queries per combat resolution when medal data is not passed.
**Fix:** Always pass `$medalData` from callers that have it (especially combat.php).

---

### [PERF-R1-036] MEDIUM includes/basicprivatephp.php:145-165 -- Season reset archive queries inside page load request

Lines 145-165 (season reset code): When a season reset triggers, the archiving code runs inside a normal player's page request:
- Line 145: `SELECT * FROM autre ORDER BY totalPoints DESC LIMIT 20`
- Line 148: Per player in top-20: `SELECT nombre FROM molecules WHERE proprietaire=? AND nombre != 0`
- Line 150: Per player: `SELECT tag, id FROM alliances WHERE id=?` (conditional)
- Lines 168-188: Similar loops for alliance and war archiving

**Impact:** While this runs rarely (once per month), it runs in a user's HTTP request, causing a very long response time. The N+1 queries inside the archiving loops amplify the delay.
**Fix:** Run season reset in a background cron job, not triggered by a random player's page load.

---

### [PERF-R1-037] MEDIUM includes/basicprivatephp.php:192-209 -- Season victory points distribution iterates ALL players and alliances

Lines 192-197: `SELECT * FROM autre ORDER BY totalPoints DESC` (ALL players), then individual `UPDATE` per player.
Lines 199-209: `SELECT * FROM alliances ORDER BY pointstotaux DESC` (ALL alliances), then per alliance: `SELECT * FROM autre WHERE idalliance=?` + individual `UPDATE` per member.

**Impact:** O(N) queries for N players + O(A*M) queries for A alliances with M members. All in a single HTTP request.
**Fix:** Use batch UPDATE with CASE/WHEN or stored procedure. Move to background job.

---

### [PERF-R1-038] MEDIUM includes/basicprivatephp.php:234-289 -- Sending emails to ALL players inside HTTP request

Lines 234-289: `SELECT email, login FROM membre` fetches ALL players, then sends an email to each via `mail()`. The `mail()` function is synchronous and can block for seconds per email.

**Impact:** If there are 100 players, this blocks the HTTP response for potentially minutes.
**Fix:** Queue emails for background delivery or use an async mailer.

---

### [PERF-R1-039] MEDIUM sujet.php:103-104 -- rangForum() and image query for topic author outside reply loop

Line 103: `SELECT image, count(image) as nb FROM autre WHERE login=?` for topic author
Line 104: `rangForum($sujet['auteur'])` (2 queries)

These are minor but add up: 3 queries per topic page for the original post author.

**Impact:** 3 queries per topic page load.
**Fix:** Pre-load author data along with reply author data.

---

## LOW (9)

### [PERF-R1-040] LOW includes/basicprivatephp.php:11 -- Session token validation query on every page load

Line 11: `SELECT session_token FROM membre WHERE login=?` runs on every authenticated page load.

**Impact:** 1 query per page load. This is a security requirement, so it cannot be eliminated, but `membre.login` should have an index (it does, as primary or unique key).
**Fix:** Acceptable. Ensure index exists on `membre.login`.

---

### [PERF-R1-041] LOW includes/basicprivatephp.php:112 -- DATEDIFF computed in DB instead of PHP

Line 112: `SELECT DATEDIFF(CURDATE(), ?) AS d` uses a DB query for a date comparison that PHP can do natively.

**Impact:** 1 unnecessary query in the vacation branch (only affects players returning from vacation).
**Fix:** Compute in PHP: `$diff = (strtotime('today') - strtotime($vac['dateFin'])) / 86400`.

---

### [PERF-R1-042] LOW marche.php:578 -- cours table ORDER BY timestamp DESC without index verification

While migration 0013 added an index on `cours.timestamp`, verify it covers the DESC ordering used in this query.

**Impact:** Minor. MariaDB can traverse a B-tree index in reverse.
**Fix:** Verify index: `SHOW INDEX FROM cours WHERE Column_name = 'timestamp'`.

---

### [PERF-R1-043] LOW includes/player.php:153-154 -- Double call to revenuAtome() per atom type

As noted in PERF-R1-012, lines 153 and 154 call `revenuAtome()` twice for the same atom:
```php
${'revenu' . $ressource} = revenuAtome($num, $joueur);
$revenu[$ressource] = revenuAtome($num, $joueur);
```

**Impact:** Double the queries. Each `revenuAtome()` call is 2-3 queries.
**Fix:** Call once, assign to both: `$val = revenuAtome($num, $joueur); ${'revenu'.$ressource} = $val; $revenu[$ressource] = $val;`

---

### [PERF-R1-044] LOW includes/player.php:217-219 -- Alliance duplicateur query in initPlayer() despite data being available

Line 217-218: `SELECT duplicateur FROM alliances WHERE id=?` queries the alliance table even though `initPlayer()` could fetch this data alongside the `autre` query.

**Impact:** 1 extra query per page load for alliance members.
**Fix:** JOIN alliances data in the `autre` query or fetch once.

---

### [PERF-R1-045] LOW sujet.php:85,138 -- forums table queried twice for same data

Line 85: `SELECT titre FROM forums WHERE id=?`
Line 138: `SELECT titre FROM forums WHERE id=?` (same query, same parameter)

**Impact:** 1 redundant query per topic page.
**Fix:** Reuse the result from line 85.

---

### [PERF-R1-046] LOW includes/formulas.php:241,251 -- pointsDeVie/vieChampDeForce call allianceResearchBonus when $joueur is passed

Lines 241 and 251 call `allianceResearchBonus($joueur, 'building_hp')` which makes 2 queries. These functions are called during combat resolution (where alliance data is already available) and during building display.

**Impact:** 2-4 extra queries per combat or building page.
**Fix:** Pass the bonus value instead of having the formula function look it up.

---

### [PERF-R1-047] LOW classement.php:250 -- recalculerStatsAlliances() called on alliance tab view

When viewing the alliance ranking tab in classement.php, `recalculerStatsAlliances()` is called (see PERF-R1-013 for the N+1 pattern).

**Impact:** Full N+1 on every alliance ranking view. Could be cached or run less frequently.
**Fix:** Run periodically (cron) or cache results with a TTL.

---

### [PERF-R1-048] LOW guerre.php -- No specific performance issues found

The guerre.php file loads war details with a small number of queries. No N+1 patterns or unbounded queries detected.

**Impact:** None.
**Fix:** None needed.

---

## Missing Index Recommendations

Based on the query patterns identified:

| Table | Column(s) | Reason | Priority |
|-------|-----------|--------|----------|
| `reponses` | `auteur` | `rangForum()` does `COUNT(*) WHERE auteur=?` on every forum post display | HIGH |
| `statutforum` | `(login, idsujet)` | Composite index for the read-status check in `listesujets.php` and `sujet.php` | MEDIUM |
| `statutforum` | `(login, idforum)` | For forum-level read count in `forum.php` | MEDIUM |
| `actionsenvoi` | `envoyeur` | Only `receveur` appears indexed; envoyeur is used in the OR condition at game_actions.php:471 | MEDIUM |
| `vacances` | `idJoueur` | Used in `basicprivatephp.php` line 110 for vacation lookups | LOW |
| `connectes` | `timestamp` | For the DELETE cleanup at `basicprivatephp.php` line 89 | LOW |

Note: Migrations 0001 and 0013 already added 25+ indexes on key columns. The above are gaps identified from actual query patterns.

---

## Query Budget Summary (Typical Authenticated Page Load)

| Phase | Queries | Source |
|-------|---------|--------|
| Session validation | 1 | basicprivatephp.php:11 |
| Position check | 1 | basicprivatephp.php:55 |
| initPlayer (1st call) | ~35-50 | constantes.php:13 via revenuAtome x8, revenuEnergie, DB fetches |
| Online tracking | 3 | basicprivatephp.php:75-89 |
| Vacation check | 1 | basicprivatephp.php:93 |
| updateRessources() | ~35-45 | revenuEnergie (6-10), revenuAtome x8 (24), decay loop (24) |
| derniereConnexion UPDATE | 1 | basicprivatephp.php:100 |
| tempsPrecedent SELECT | 1 | basicprivatephp.php:102 |
| updateActions() | ~10-30+ | Per pending action: formations, attacks, returns |
| initPlayer (2nd call, cached) | 0-5 | basicprivatephp.php:104 (mostly cached) |
| Season check | 2 | basicprivatephp.php:128-131 |
| **Subtotal (framework)** | **~90-140** | **Before any page-specific logic** |
| Page-specific queries | 5-40+ | Varies by page |
| **Grand Total** | **~95-180+** | **Per authenticated page load** |

This is the most impactful finding: the authentication/resource-update pipeline alone accounts for 90-140 queries, dominated by the `revenuAtome()`, `revenuEnergie()`, and `coefDisparition()` function call patterns.

---

## Recommended Fix Priority

1. **Refactor `revenuAtome()`, `revenuEnergie()`, `coefDisparition()`** to accept pre-fetched data instead of querying independently. This alone could reduce per-page queries by 60-80. (PERF-R1-002, 003, 004, 006, 007, 008, 012)

2. **Remove double `constantes.php` include** or restructure the load order. (PERF-R1-001)

3. **Merge `allianceResearchBonus()` data** into the `initPlayer()` cache. (PERF-R1-010)

4. **Eliminate the unconditional `batmax` UPDATE** in `initPlayer()`. (PERF-R1-011)

5. **Optimize online tracking** with INSERT ON DUPLICATE KEY UPDATE and probabilistic cleanup. (PERF-R1-005)

6. **Consolidate `updateTargetResources()`** into a single SELECT + single UPDATE. (PERF-R1-021)

7. **Fix forum N+1 patterns** with pre-loading. (PERF-R1-014, 015, 016)

8. **Fix alliance page N+1 patterns** with SQL aggregation and tag pre-loading. (PERF-R1-013, 017, 018, 019, 020)

9. **Add missing indexes** on `reponses.auteur`, `statutforum` composites. (Index table above)

10. **Move season reset to a cron job** instead of inline HTTP request. (PERF-R1-036, 037, 038)
