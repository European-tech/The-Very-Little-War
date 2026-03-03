# Performance Deep-Dive — Round 2

**Date:** 2026-03-03
**Scope:** 10 files examined in depth: player.php, classement.php, basicprivatehtml.php, alliance.php, messages.php, marche.php, game_actions.php, game_resources.php, ui_components.php, display.php
**Baseline from Round 1:** 95–180+ queries per page load

---

## Executive Summary

Round 2 confirms and deepens the Round 1 findings. The two worst architectural problems are:

1. **revenuEnergie() and revenuAtome() issue 5–7 queries every call** — these are called 10–20 times per page, producing 50–140 redundant DB hits per request from computation helpers that should use cached data.
2. **basicprivatehtml.php runs 14 queries on every authenticated page load** — this is the true fixed overhead cost that multiplies with every page in the game.

Combined, these two problems alone account for 60–150 queries per page before any page-specific logic runs.

---

## Findings

---

### PERF-R2-001 — revenuEnergie() fires 5–7 queries per invocation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php` lines 7–64

**Current query count per call:**
```
Q1: SELECT * FROM constructions WHERE login=?         (gets pointsCondenseur)
Q2: SELECT producteur FROM constructions WHERE login=? (DUPLICATE of Q1)
Q3: SELECT idalliance, totalPoints FROM autre WHERE login=?
Q4: SELECT duplicateur FROM alliances WHERE id=?      (conditional)
Q5: SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=1
Q6: SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=2
Q7: SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=3
Q8: SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=4
Q9: SELECT energieDepensee FROM autre WHERE login=?
```

**N+1 pattern:** The 4 molecule queries (Q5–Q8) are a classic N+1 — one query per class instead of one `WHERE proprietaire=? ORDER BY numeroclasse` query returning all 4 rows.

**Call sites per page (typical):**
- `initPlayer()` calls `revenuEnergie()` once
- `basicprivatehtml.php` calls it 4 times (lines 318, 336, 337, 338, 341, 371, 448)
- `listeConstructions` array in `initPlayer()` calls it 3+ more times with different `$detail` params
- Total: **8–12 invocations per page load = 64–108 queries from this one function**

**Root cause:** The function re-fetches constructions, autre, alliances, and all 4 molecule rows from scratch on every call. No memoization exists.

**Optimization:**
```php
// Add a per-request memo cache keyed by joueur
static $cache = [];
if (isset($cache[$joueur][$detail])) return $cache[$joueur][$detail];

// Consolidate the 4 numeroclasse queries into one:
$molecules = dbFetchAll($base,
    'SELECT iode, nombre, numeroclasse FROM molecules WHERE proprietaire=? ORDER BY numeroclasse',
    's', $joueur);

// Consolidate the two constructions queries into one (already fetched in initPlayer — pass as param).
// Pull idalliance + energieDepensee from the $autre global already set by initPlayer.
```

**Expected improvement:** Reduces from 9 queries/call to 1–2 queries/call on first invocation, 0 on repeat. Saves 50–100 queries per page.

---

### PERF-R2-002 — revenuAtome() fires 3–4 queries per invocation, called 8+ times per page

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php` lines 67–83

**Current query count per call:**
```
Q1: SELECT pointsProducteur FROM constructions WHERE login=?
Q2: SELECT idalliance FROM autre WHERE login=?
Q3: SELECT duplicateur FROM alliances WHERE id=?   (conditional)
Q4: (implicit) prestigeProductionBonus() — likely another query (not shown)
```

**Call sites:** `initPlayer()` calls `revenuAtome($num, $joueur)` in a loop over 8 atom types (line 153–154), plus once per atom again in `updateRessources()` (line 147), and again in `basicprivatehtml.php` line 298 per atom in the resources popover — that is **8 atoms × 3 locations = 24+ invocations per page load = 72–96 extra queries**.

**Root cause:** Same as PERF-R2-001. No memoization. The function re-reads constructions and autre on every atom type.

**Optimization:**
```php
static $cache = [];
$cacheKey = $joueur . ':' . $num;
if (isset($cache[$cacheKey])) return $cache[$cacheKey];

// Read pointsProducteur, idalliance, duplicateur ONCE per player and cache.
// Better: pass pre-fetched $constructions and $autre as parameters.
```

**Expected improvement:** Eliminates 60–90 queries per page. Combined with PERF-R2-001, these two functions are responsible for 120–190 queries per page.

---

### PERF-R2-003 — basicprivatehtml.php runs 14 queries on every authenticated page load

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php`

**Exact query inventory (every authenticated page, every request):**
```
Line 3:   SELECT count(*) FROM messages WHERE expeditaire=?           (sent message count for tutorial)
Line 6:   SELECT count(*) FROM molecules WHERE proprietaire=? AND formule!='Vide'
Line 51:  SELECT * FROM ressources WHERE login=?
Line 53:  SELECT nombre FROM molecules WHERE proprietaire=? AND nombre!=0
Line 59:  SELECT * FROM constructions WHERE login=?
Line 115: SELECT * FROM molecules WHERE proprietaire=?               (THIRD molecule query)
Line 151: SELECT idalliance FROM autre WHERE login=?
Line 168: SELECT niveaututo, missions FROM autre WHERE login=?       (DUPLICATE of initPlayer $autre)
Line 233: SELECT moderateur FROM membre WHERE login=?               (DUPLICATE of initPlayer $membre)
Line 249: SELECT invite FROM invitations WHERE invite=?
Line 253: SELECT idalliance FROM autre WHERE login=?               (DUPLICATE of line 151)
Line 260: SELECT destinataire FROM messages WHERE destinataire=? AND statut=0
Line 268: SELECT destinataire FROM rapports WHERE destinataire=? AND statut=0
Line 277: SELECT count(*) FROM sujets WHERE statut=0
Line 278: SELECT count(*) FROM statutforum WHERE login=?
Line 322: SELECT idalliance FROM autre WHERE login=?               (THIRD duplicate of idalliance)
Line 326: SELECT duplicateur FROM alliances WHERE id=?
```

**Total: 17 queries, with 3 separate idalliance reads and 3 molecule reads.**

**Key duplicates:**
- `idalliance` from `autre` is fetched 3 times (lines 151, 253, 322)
- `molecules` is queried 3 times (lines 6, 53, 115) with slightly different filters
- `autre` is fetched again at line 168 despite `$autre` being set by `initPlayer()` at basicprivatephp.php include time

**Optimization:**
```php
// Use $autre already set by initPlayer():
$idallianceId = $autre['idalliance'];  // replaces lines 151, 253, 322

// Combine the 3 molecule queries into one:
$allMolecules = dbFetchAll($base,
    'SELECT formule, nombre, numeroclasse FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
$nbClassesTuto = count(array_filter($allMolecules, fn($m) => $m['formule'] !== 'Vide'));
$nb_moleculesJoueur = array_sum(array_column(
    array_filter($allMolecules, fn($m) => $m['nombre'] != 0), 'nombre'));

// Use $membre['moderateur'] already in global scope instead of re-querying line 233.
// Combine messages+rapports unread counts with one UNION query or two parallel lightweight queries.
```

**Expected improvement:** Reduces from 17 to 7–8 queries per page header. Saves ~10 queries on every single authenticated page (19 pages in the game).

---

### PERF-R2-004 — updateRessources() fires N+1 inside per-molecule loop

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php` lines 105–201

**N+1 pattern (lines 169–183):**
```php
$ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $joueur);
while ($molecules = mysqli_fetch_array($ex)) {
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);  // 4 UPDATEs
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues ...'); // 4 SELECTs — N+1!
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', ...); // 4 UPDATEs
}
```

**Query count:** 1 SELECT + (4 × 3) = **13 queries** for the molecule decay loop.

**Additional redundancies:**
- Line 127: `SELECT * FROM constructions` duplicates what was already read in `initPlayer()`
- Lines 187–190: 4 additional single-class molecule SELECTs after the full SELECT on line 169
- Line 163: Another constructions SELECT for `stabilisateur` column only

**Total updateRessources() queries: ~22**

**Optimization for N+1:**
```php
// Read moleculesPerdues once before the loop:
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
$totalPerdu = 0;

while ($molecules = mysqli_fetch_array($ex)) {
    $moleculesRestantes = pow(coefDisparition($joueur, $compteur+1), $nbsecondes) * $molecules['nombre'];
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);
    $totalPerdu += ($molecules['nombre'] - $moleculesRestantes);
    $compteur++;
}
// One UPDATE instead of 4:
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    $moleculesPerdues['moleculesPerdues'] + $totalPerdu, $joueur);
```

**Further:** The 4 post-loop molecule SELECTs (lines 187–190) can be eliminated by storing pre-loop values during the main loop iteration (already being iterated as `$nombre1`, `$nombre2`, etc.).

**Expected improvement:** Reduces updateRessources() from ~22 to ~10 queries. Since this is called on every page that calls basicprivatephp.php, this saves 12 queries per page.

---

### PERF-R2-005 — updateActions() has cascading recursive query storm

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` lines 7–543

**Per-combat resolution query count (worst case):**
```
Line 23:  initPlayer() — 9+ queries
Line 26:  SELECT * FROM actionsconstruction WHERE login=?
Line 35:  SELECT * FROM actionsformation WHERE login=?
Line 38:  SELECT neutrinos FROM autre WHERE login=?
Line 41 (loop): SELECT * FROM molecules WHERE id=?      — N+1 per formation action
Line 65:  SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=?
Line 79:  updateRessources(defenseur) — 22 queries recursively
Line 80:  updateActions(defenseur) — full recursion: another 9+ initPlayer + all the rest
Line 90:  SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC
Line 99 (loop per molecule class): SELECT moleculesPerdues FROM autre — N+1 (4 queries)
Line 99 (loop): UPDATE autre SET moleculesPerdues — N+1 (4 queries)
Line 316: INSERT INTO rapports (×2)
Line 461 (return trip): SELECT * FROM molecules — another 4-class loop
Line 461 (loop): SELECT moleculesPerdues — N+1 again (4 queries)
Line 461 (loop): UPDATE autre — N+1 (4 queries)
Line 541: initPlayer() again — 9+ queries (second time)
```

**Recursive updateActions() call:** When combat is resolved, `updateActions($defenseur)` is called (lines 80, 84), which itself calls `updateRessources()` and `initPlayer()` for the defender — doubling the query count.

**Estimated total for combat resolution: 80–120 queries per resolved attack.**

**N+1 patterns identified:**
1. Formation loop: 1 query per `actionsformation` row to fetch molecule data
2. Travel-loss loop (lines 90–103): `SELECT moleculesPerdues` + `UPDATE autre` per molecule class = 8 queries for 4 classes
3. Return-trip loop (lines 452–465): Same pattern repeated = another 8 queries

**Optimization:**
```php
// Formation loop: pre-fetch all molecules by id in one IN() query
$formIds = array_column($formActions, 'idclasse');
$molecules = dbFetchAll($base, 'SELECT * FROM molecules WHERE id IN (' .
    implode(',', array_fill(0, count($formIds), '?')) . ')',
    str_repeat('s', count($formIds)), ...$formIds);

// Travel-loss loops: batch the 4 per-class reads into 1 accumulated variable:
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
$totalLoss = 0;
// ... loop accumulates $totalLoss ...
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    $moleculesPerdues['moleculesPerdues'] + $totalLoss, $joueur);
```

**Expected improvement:** Reduces combat resolution from 80–120 queries to 30–45. Most impactful for active combat pages.

---

### PERF-R2-006 — alliance.php: full table scan for rank calculation + per-war/pact N+1

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php` lines 139–230

**Rank calculation (lines 170–178) — full table scan:**
```php
$rangQuery = dbQuery($base, 'SELECT tag FROM alliances ORDER BY pointstotaux DESC');
$rang = 1;
while ($rangEx = mysqli_fetch_array($rangQuery)) {
    if ($rangEx['tag'] == $allianceJoueurPage['tag']) { break; }
    $rang++;
}
```
This fetches every alliance row and iterates in PHP to find rank. With 100+ alliances this is 100+ rows transferred and O(n) PHP iteration.

**Per-war/pact N+1 (lines 202–229):**
```php
$ex = dbQuery($base, 'SELECT * FROM declarations WHERE type=0 AND ...');
while ($guerre = mysqli_fetch_array($ex)) {
    // Conditional branch: one query per war
    $allianceJoueurAdverse = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', ...);
}
$ex = dbQuery($base, 'SELECT * FROM declarations WHERE type=1 AND ...');
while ($pacte = mysqli_fetch_array($ex)) {
    // One query per pact
    $allianceJoueurAllie = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', ...);
}
```
If an alliance has 5 wars and 3 pacts, that is 8 extra queries.

**Full alliance.php query count (own alliance, loaded page):**
```
~6 from basicprivatehtml/initPlayer overhead
1  SELECT * FROM alliances WHERE id=?
1  SELECT idalliance FROM autre WHERE login=? (duplicateur cost)
1  SELECT duplicateur FROM alliances WHERE id=?
1  SELECT energieAlliance FROM alliances WHERE id=? (upgrade check)
1  SELECT id FROM alliances WHERE tag=?
1  SELECT chef FROM alliances WHERE id=?
1  SELECT idalliance FROM autre WHERE login=? (chef existence)
1  SELECT * FROM alliances WHERE id=?
1  SELECT totalPoints FROM autre WHERE idalliance=?
1  SELECT tag FROM alliances ORDER BY pointstotaux DESC  (FULL SCAN)
1  SELECT * FROM grades WHERE idalliance=?
1  SELECT * FROM declarations WHERE type=0 AND ...      (wars)
N  SELECT tag FROM alliances WHERE id=?                 (1 per war)
1  SELECT * FROM declarations WHERE type=1 AND ...      (pacts)
N  SELECT tag FROM alliances WHERE id=?                 (1 per pact)
1  SELECT login FROM grades WHERE login=? AND idalliance=?
1  SELECT catalyseur, fortification... FROM alliances WHERE id=?
1  SELECT * FROM autre WHERE idalliance=? ORDER BY ...  (members)
1  SELECT * FROM invitations WHERE invite=?             (if no alliance)
```

**Total: 20–30 queries depending on wars/pacts.**

**Optimizations:**
```php
// Replace full table scan rank with COUNT query:
$rang = dbCount($base,
    'SELECT COUNT(*) FROM alliances WHERE pointstotaux > ?',
    'i', $allianceJoueurPage['pointstotaux']) + 1;

// Pre-fetch all alliance tags needed for wars/pacts with one IN() query:
$warPactIds = array_merge(
    array_column($wars, 'alliance1'), array_column($wars, 'alliance2'),
    array_column($pacts, 'alliance1'), array_column($pacts, 'alliance2')
);
$tagMap = [];
if ($warPactIds) {
    $rows = dbFetchAll($base, 'SELECT id, tag FROM alliances WHERE id IN (' .
        implode(',', array_fill(0, count($warPactIds), '?')) . ')',
        str_repeat('i', count($warPactIds)), ...$warPactIds);
    foreach ($rows as $r) { $tagMap[(int)$r['id']] = $r['tag']; }
}
```

**Expected improvement:** Eliminates full table scan. Reduces war/pact N+1 from W+P queries to 1 query. Saves 8–20 queries per page.

---

### PERF-R2-007 — revenuEnergie() called 7+ times with repeated constructions reads in basicprivatehtml.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 318–341

**Pattern:**
```php
$prodMedaille = '...' . revenuEnergie($constructions['generateur'], $_SESSION['login'], 2) . '/h</td></tr>';
// line 337:
$prodIode = '...(revenuEnergie($constructions['generateur'],$_SESSION['login'],3)
             -revenuEnergie($constructions['generateur'],$_SESSION['login'],4)) > 0){
    $prodIode = '...' . revenuEnergie($constructions['generateur'],$_SESSION['login'],3).'/h...
                       revenuEnergie($constructions['generateur'],$_SESSION['login'],3).'/h...';
// line 340:
$prodBase = '...' . revenuEnergie($constructions['generateur'],$_SESSION['login'],4).'/h...
             revenuEnergie($constructions['generateur'],$_SESSION['login'],4).'/h...';
// line 341:
$prodProducteur = '...' . revenuEnergie($constructions['generateur'],$_SESSION['login']).'/h...';
// line 371:
'...' . revenuEnergie($constructions['generateur'],$_SESSION['login']).'/h';
// line 448 (per atom in JS loop):
revenuAtome($num,$_SESSION['login'])  -- 8 times
```

**Each `revenuEnergie()` call fires 5–9 queries (per PERF-R2-001). There are 7 calls to revenuEnergie() and 8 calls to revenuAtome() in basicprivatehtml.php alone.**

**This single file causes 7×7 + 8×3 = 49–87 additional queries on every page load.**

**Optimization:** Add static memoization inside `revenuEnergie()` and `revenuAtome()` keyed by `$joueur:$detail`. After the first call all subsequent calls for the same player and detail level return from memory with zero DB queries.

```php
function revenuEnergie($niveau, $joueur, $detail = 0) {
    static $memo = [];
    $key = "$joueur:$detail";
    if (isset($memo[$key])) return $memo[$key];
    // ... existing logic ...
    $memo[$key] = $result;
    return $result;
}

function revenuAtome($num, $joueur) {
    static $memo = [];
    $key = "$joueur:$num";
    if (isset($memo[$key])) return $memo[$key];
    // ... existing logic ...
    $memo[$key] = $result;
    return $memo[$key];
}
```

**Expected improvement:** Saves 40–80 queries per page on every authenticated page. This is the single highest-impact fix available.

---

### PERF-R2-008 — marche.php: cours table unindexed timestamp scan + duplicate cours reads

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`

**Issue 1 — repeated cours reads:**
```php
// Line 10: first read
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');

// Line 231 (after buy): second identical read
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');

// Line 344 (after sell): third identical read
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');

// Line 578 (chart): full scan
$ex = dbQuery($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1000");
```

**Issue 2 — market chart fetches 1000 rows on every page view:**
The chart query (line 578) fetches up to 1000 complete rows of the `cours` table every time the market page is loaded — even when the user is just browsing. The `cours` table grows unboundedly; after 6 months of trading it could contain tens of thousands of rows, and this query touches the 1000 most recent every single render.

**Issue 3 — cours table growth:** Every buy and every sell inserts a new row. There is no pruning/archiving mechanism. The table grows O(transactions) forever.

**Optimizations:**
```php
// Cache the latest cours for the request duration:
if (!isset($GLOBALS['_latestCours'])) {
    $GLOBALS['_latestCours'] = dbFetchOne($base,
        'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
}
$val = $GLOBALS['_latestCours'];

// For the chart: add server-side caching with a file or APCu:
// Only regenerate chart data when cours table changes.
// Alternatively, downsample: SELECT every Nth row instead of last 1000.

// Add periodic cleanup job:
// DELETE FROM cours WHERE timestamp < UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY);
```

**Ensure index exists:**
```sql
-- Verify this index exists (should have been added in Phase 4 migrations):
SHOW INDEX FROM cours;
-- If missing:
ALTER TABLE cours ADD INDEX idx_cours_timestamp (timestamp);
```

**Expected improvement:** Eliminates 2 redundant cours reads per transaction. Chart query moves from full 1000-row scan to cached/sampled data. Reduces market page queries from ~15 to ~10.

---

### PERF-R2-009 — messages.php: CSRF field inside loop generates repeated token reads

**File:** `/home/guortates/TVLW/The-Very-Little-War/messages.php` lines 66–77

**Pattern:**
```php
while($messages = mysqli_fetch_array($ex)) {
    // ...
    echo '<td><form ...>' . csrfField() . '...';  // csrfField() per row
}
```

**The `csrfField()` function is called once per message row (up to 15 rows per page).** If `csrfField()` reads the session to regenerate or verify the token, this is 15 repeated function calls in a tight loop. Additionally, the delete-all button outside the loop calls `csrfField()` again (line 79).

**Query count for messages.php:**
```
basicprivatephp includes: ~17 queries (PERF-R2-003)
Line 41: SELECT COUNT(*) FROM messages WHERE destinataire=?
Line 53: SELECT * FROM messages WHERE destinataire=? LIMIT ?,?   (paginated, fine)
```

**Total: ~20 queries. Messages itself is efficient; the overhead is purely the header.**

**Optimization:** `csrfField()` should not re-read the session per call. If it does, memoize the token:
```php
function csrfField() {
    static $field = null;
    if ($field !== null) return $field;
    $token = $_SESSION['csrf_token'] ?? '';
    $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    return $field;
}
```

**Expected improvement:** Minor — eliminates 15 repeated session reads per message list page. More significant as page size grows.

---

### PERF-R2-010 — display.php: rangForum() fires 3 queries per call with no caching

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` lines 264–320

**Query count per call:**
```
Q1: SELECT count(*) AS nbmessages FROM reponses WHERE auteur=?
Q2: SELECT login FROM membre WHERE login=?        (existence check)
Q3: SELECT moderateur, login, codeur FROM membre WHERE login=?   (DUPLICATES Q2's table)
```

**Q2 and Q3 both query `membre` for the same login in the same function call. They can be merged into one query.**

**Call sites:** `rangForum()` is called on the forum page for every post author visible, and from `carteForum()` in ui_components.php. On a forum thread with 20 replies, this is 60 queries just for rank badges.

**Optimization:**
```php
function rangForum($joueur) {
    static $cache = [];
    if (isset($cache[$joueur])) return $cache[$joueur];

    // Merge the 3 queries into 2 (reponses count + membre data):
    $membre = dbFetchOne($base,
        'SELECT moderateur, login, codeur FROM membre WHERE login=?', 's', $joueur);
    if (!$membre) {
        return $cache[$joueur] = ['couleur' => 'gray', 'nom' => 'Supprime'];
    }
    $msgCount = dbCount($base,
        'SELECT COUNT(*) FROM reponses WHERE auteur=?', 's', $joueur);
    // ... rest of logic ...
    return $cache[$joueur] = ['couleur' => $couleur, 'nom' => $nom];
}
```

**Expected improvement:** Reduces from 3 queries to 2 per unique author (50% reduction), and 0 for repeated authors via static cache. Saves 30–40 queries on a typical forum thread page.

---

### PERF-R2-011 — initPlayer(): batMax() fires a duplicate constructions SELECT

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` lines 181, 685–699

**Pattern:**
```php
// In initPlayer() at line 150:
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);

// At line 181 initPlayer calls:
$plusHaut = batMax($joueur);

// batMax() at line 693:
$tableau = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $pseudo);
```

**`batMax()` issues a complete duplicate SELECT on the constructions table even though `initPlayer()` already fetched the exact same row into `$constructions`.**

**Optimization:**
```php
// Add a $constructions parameter to batMax():
function batMax($pseudo, $constructions = null) {
    if ($constructions === null) {
        global $base;
        $constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $pseudo);
    }
    // use $constructions directly
}

// initPlayer() call becomes:
$plusHaut = batMax($joueur, $constructions);
```

**Expected improvement:** Saves 1 query per initPlayer() call. Since initPlayer() is called 2–4 times per page (once at init, once after augmenterBatiment, once at end of updateActions), this saves 2–4 queries per page.

---

### PERF-R2-012 — classement.php sub=0: recalculerStatsAlliances() on every alliance tab view

**File:** `/home/guortates/TVLW/The-Very-Little-War/classement.php` line 250

**Pattern:**
```php
elseif (isset($_GET['sub']) AND $_GET['sub'] == 1){
    recalculerStatsAlliances();  // <-- called every single view of the alliance tab
```

**`recalculerStatsAlliances()` aggregates all member stats across all alliances and writes them back.** This is a write operation touching every alliance row on every page view of the alliance ranking tab. With 20+ alliances this is 20+ UPDATEs triggered by any visitor simply viewing the page.

**This is a correctness-vs-performance tradeoff:** the stats should be maintained incrementally (updated when a player's stats change) rather than recalculated on every read.

**Optimization options:**
1. Move `recalculerStatsAlliances()` to a cron job (every 5 minutes).
2. Trigger incremental updates only when player stats change (in `ajouterPoints()`, combat resolution, etc.).
3. Add a dirty-flag column to `alliances` table: set `needs_recalc=1` when any member stat changes, recalc only flagged alliances.

**Expected improvement:** Eliminates 20–50+ UPDATEs per alliance tab page view. Reduces page load time from ~500ms to ~100ms for this tab.

---

### PERF-R2-013 — game_actions.php: per-molecule-class moleculesPerdues N+1 in travel loops

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php` lines 90–103 and 452–465

**Exact N+1 pattern (travel-loss loop, occurs TWICE — outbound and return):**
```php
$ex3 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $joueur);
$compteur = 1;
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesRestantes = pow(coefDisparition(...), $nbsecondes) * $molecules[$compteur-1];

    // N+1: fetches the same column from autre on every iteration
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
        ($molecules[$compteur-1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']),
        $joueur);
    $compteur++;
}
```

**For 4 molecule classes this generates:**
- 1 SELECT (molecules) + 4 SELECT (moleculesPerdues) + 4 UPDATE (moleculesPerdues) = 9 queries
- This loop runs twice (outbound travel + return trip) = **18 queries** just for travel loss accounting

**Fix (accumulate and batch):**
```php
$exMols = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $joueur);
$totalLoss = 0;
$compteur = 1;
while ($moleculesProp = mysqli_fetch_array($exMols)) {
    $moleculesRestantes = pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur-1];
    $totalLoss += ($molecules[$compteur-1] - $moleculesRestantes);
    $compteur++;
}
// One read + one write instead of 4 reads + 4 writes:
$row = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    $row['moleculesPerdues'] + $totalLoss, $joueur);
```

**Expected improvement:** Reduces each travel loop from 9 queries to 3 queries. Saves 12 queries per combat resolution (6 per direction × 2 directions). With recursive defender updateActions, saves up to 24 queries per combat.

---

### PERF-R2-014 — database.php: no connection pooling, no query logging/profiling

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php`

**Findings:**
1. **No persistent connection:** `mysqli_connect()` in `connexion.php` creates a new TCP connection on every PHP request. PHP-FPM does not reuse MySQL connections across requests by default with mysqli.
2. **No query logging:** There is no mechanism to log slow queries, count queries per request, or profile execution time. This makes it impossible to measure improvements without external tooling.
3. **No prepared statement caching:** Each `dbQuery()` call calls `mysqli_prepare()` which sends the SQL to MariaDB for parsing every time, even for queries executed multiple times per request.

**Optimizations:**

**Connection pooling via ProxySQL or persistent connections:**
```php
// In connexion.php, switch to persistent connection (p: prefix in MySQLi):
$base = mysqli_connect('p:localhost', 'tvlw', '...', 'tvlw');
// Enables connection reuse across PHP-FPM workers (PHP connection pool).
```

**Add per-request query counter for debugging:**
```php
// Add to database.php:
$GLOBALS['_queryCount'] = 0;
$GLOBALS['_queryLog'] = [];

function dbQuery($base, $sql, $types = "", ...$params) {
    $GLOBALS['_queryCount']++;
    if (defined('QUERY_DEBUG') && QUERY_DEBUG) {
        $GLOBALS['_queryLog'][] = $sql;
    }
    // ... rest of function
}
```

**Add slow-query detection:**
```php
function dbQuery($base, $sql, $types = "", ...$params) {
    $t0 = microtime(true);
    // ... execute ...
    $elapsed = microtime(true) - $t0;
    if ($elapsed > 0.05) { // 50ms threshold
        error_log(sprintf('SLOW_QUERY %.3fs: %s', $elapsed, $sql));
    }
    return $result;
}
```

**Expected improvement:** Persistent connections save 5–20ms of TCP handshake overhead per request. Query logging enables measurement of all other optimizations.

---

### PERF-R2-015 — ui_components.php / display.php: no DB queries (confirmed clean)

**Files:** `includes/ui_components.php`, `includes/display.php`

**Finding:** Both modules are confirmed query-free. `ui_components.php` contains only HTML rendering logic (debutCarte, finCarte, item, chip, etc.) with no database calls. `display.php` is almost entirely formatting functions with one exception:

**`rangForum()` in display.php** (documented in PERF-R2-010 above) is the only DB-touching function in these two modules.

**All other functions** (`chiffrePetit`, `couleurFormule`, `affichageTemps`, `nombreAtome`, `coutEnergie`, etc.) are pure computation with no side effects.

**Status:** Clean. No additional action needed beyond PERF-R2-010.

---

## Priority Matrix

| ID | File | Queries Saved/Page | Complexity | Priority |
|----|------|-------------------|------------|----------|
| PERF-R2-007 | revenuEnergie/revenuAtome memoization | 50–100 | Low (add static cache) | CRITICAL |
| PERF-R2-001 | revenuEnergie() N+1 molecules | 40–80 | Medium | CRITICAL |
| PERF-R2-002 | revenuAtome() repeated reads | 30–60 | Medium | CRITICAL |
| PERF-R2-003 | basicprivatehtml.php deduplication | 10–12 every page | Low (use existing globals) | HIGH |
| PERF-R2-004 | updateRessources() molecule N+1 | 8–12 | Low | HIGH |
| PERF-R2-013 | game_actions travel N+1 | 12–24/combat | Low | HIGH |
| PERF-R2-012 | recalculerStatsAlliances on read | 20–50 UPDATEs | Medium (needs cron) | HIGH |
| PERF-R2-006 | alliance.php full scan + war N+1 | 8–20 | Low | MEDIUM |
| PERF-R2-011 | batMax() duplicate constructions | 2–4 | Low (add param) | MEDIUM |
| PERF-R2-005 | updateActions recursive storm | 40–80/combat | High | MEDIUM |
| PERF-R2-010 | rangForum() static cache | 20–40 on forum | Low | MEDIUM |
| PERF-R2-008 | marche.php cours cache | 3–5 | Low | LOW |
| PERF-R2-014 | DB connection pooling + logging | 5–20ms latency | Low | LOW |
| PERF-R2-009 | csrfField() static memoize | 0–15 | Trivial | LOW |
| PERF-R2-015 | ui_components/display: clean | N/A | N/A | NONE |

---

## Projected Improvement After All Fixes

| Metric | Current | After Round 2 Fixes | Reduction |
|--------|---------|---------------------|-----------|
| Queries per standard page | 95–180 | 20–40 | ~75% |
| Queries per combat resolution | 80–120 | 30–45 | ~60% |
| Queries on market page | ~35 | ~12 | ~65% |
| Queries on alliance page | ~28 | ~12 | ~57% |
| basicprivatehtml.php overhead | 17 | 7 | ~59% |
| Forum thread (20 posts) | 80–100 | 30–40 | ~62% |

---

## Implementation Notes

### Quick wins (1 line each)

1. Add `static $memo = []` memoization to `revenuEnergie()` and `revenuAtome()` — single most impactful change, eliminates 50–100 queries per page.
2. In `basicprivatehtml.php`, replace the 3 duplicate `SELECT idalliance FROM autre` with `$autre['idalliance']` (already set by `initPlayer()`).
3. In `updateRessources()`, hoist `SELECT moleculesPerdues` outside the while loop and accumulate into one `UPDATE` after the loop.
4. In `batMax()`, add a `$constructions` parameter so `initPlayer()` can pass its already-fetched row.
5. In `rangForum()`, add `static $cache = []` at the top.

### Requires architectural change

- `recalculerStatsAlliances()` on classement.php alliance tab: needs a cron job or incremental update trigger.
- `updateActions()` recursive call: needs careful refactoring to avoid double-initiating the defender's full state.
- `cours` table growth: needs a scheduled cleanup or archival strategy before the table grows to millions of rows.

### Monitoring recommendation

Add the query counter from PERF-R2-014 behind an `if (defined('QUERY_DEBUG'))` flag. Enable it temporarily in development to measure before/after query counts for each fix. Target: fewer than 30 queries per standard page, fewer than 50 per combat page.
