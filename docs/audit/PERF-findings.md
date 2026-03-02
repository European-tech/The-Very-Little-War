# TVLW Performance Audit Findings

**Date:** 2026-03-02
**Scope:** Full codebase performance audit — PHP 8.2, MariaDB 10.11, Apache 2
**Player base:** ~930 players, monthly season reset cycle
**Auditor:** Performance Engineer (Claude Sonnet 4.6)

---

## Summary

Every authenticated page load in TVLW executes between **45 and 100+ database queries** due to cascading
`initPlayer()` calls, N+1 query patterns in loops, and complete absence of request-level caching.
The attaquer.php map page scales at **3 queries per player** producing 2,790+ queries for 930 players.
A full season reset blocks the web process for an estimated 30–120 seconds while emailing all users
synchronously. Inline CSS (4 KB per page) and five JavaScript files (560 KB total) load
render-blocking with no HTTP/2 push, no CDN, and no cache headers. The findings below are
categorised by severity and ordered within each tier by estimated impact.

---

## CRITICAL Findings

---

### FINDING-PERF-001: CRITICAL — N+1 Query Explosion in attaquer.php Map Render

**File:** `attaquer.php:295–314`

**Description:**
The map page loads every player from `membre` with `SELECT * FROM membre` (no LIMIT, no WHERE),
then for each row executes three additional queries inside the while loop:

```php
$ex = dbQuery($base, 'SELECT * FROM membre');           // 1 query — all 930 players
while ($tableau = mysqli_fetch_array($ex)) {
    $points = dbFetchOne($base, 'SELECT points,idalliance FROM autre WHERE login=?', ...); // +1
    $guerreCount = dbCount($base, 'SELECT count(*) ... FROM declarations WHERE ...', ...); // +1
    $pacteCount  = dbCount($base, 'SELECT count(*) ... FROM declarations WHERE ...', ...); // +1
    // → 3 queries per player row
}
```

**Impact:**
- 930 players × 3 queries = **2,790 DB queries** just to render the map.
- Each `declarations` query involves a four-column `OR`-joined predicate across a table with
  potentially thousands of rows.
- Estimated wall time: 1.5–4 seconds of pure DB round-trips at 930 players.
- Grows quadratically: 5,000 players would produce 15,000 queries per page load.

**Remediation:**
Replace the loop with a single JOIN query that fetches all needed columns in one round-trip:

```sql
SELECT
    m.login, m.x, m.y, m.id,
    a.points, a.idalliance,
    (SELECT COUNT(*) FROM declarations d
       WHERE d.type=0 AND d.fin=0
         AND ((d.alliance1=a.idalliance AND d.alliance2=:myAlliance)
              OR (d.alliance2=a.idalliance AND d.alliance1=:myAlliance))) AS guerreCount,
    (SELECT COUNT(*) FROM declarations d2
       WHERE d2.type=1 AND d2.valide!=0
         AND ((d2.alliance1=a.idalliance AND d2.alliance2=:myAlliance)
              OR (d2.alliance2=a.idalliance AND d2.alliance1=:myAlliance))) AS pacteCount
FROM membre m
JOIN autre a ON a.login = m.login
WHERE m.x != -1000;
```

This collapses 2,791 queries into 1.

---

### FINDING-PERF-002: CRITICAL — initPlayer() Called 3–4 Times Per Page Load

**File:** `includes/constantes.php:13`, `includes/game_actions.php:23`, `includes/game_actions.php:526`,
`includes/player.php:456–480`

**Description:**
`initPlayer()` performs approximately 17 DB queries on each invocation (4 table reads + 8 loops
calling `revenuAtome()` + 8 calls to `actionsconstruction` + 1 `batMax` query + 1 duplicateur
query + 1 `UPDATE autre SET batmax`). It is called redundantly in the same request:

```
Request flow for any authenticated page:
  basicprivatephp.php  → updateRessources()  (if vacation branch skipped)
  basicprivatephp.php  → updateActions()     → initPlayer() call #1
  includes/constantes.php                    → initPlayer() call #2  (always)
  basicprivatehtml.php rendering             → revenuAtome() ×8 (sub-queries of initPlayer data)
  augmenterBatiment()  if construction done  → initPlayer() call #3
  augmenterBatiment()  end                   → initPlayer() call #4 (line 480)
```

**Impact:**
- Minimum 2 `initPlayer()` calls per request = **34+ queries** just for player state initialisation.
- With a completed construction: 4 calls = **68+ queries** of pure player initialisation overhead.
- The `UPDATE autre SET batmax` inside every `initPlayer()` call issues a write on every
  authenticated page load even when nothing changed.

**Remediation:**
Introduce a request-level cache using a static variable or a simple global array:

```php
// In player.php, top of initPlayer():
static $cache = [];
$cacheKey = $joueur . '_' . floor(time() / 60); // 1-minute TTL key
if (isset($cache[$cacheKey])) {
    // Restore globals from cache, return early
    foreach ($cache[$cacheKey] as $var => $val) { $$var = $val; }
    return;
}
```

Additionally, defer the `UPDATE autre SET batmax` write to only execute when `$plusHaut` has
actually changed from the stored value.

---

### FINDING-PERF-003: CRITICAL — updateRessources() Issues 30–40 Queries Per Call

**File:** `includes/game_resources.php:104–190`

**Description:**
`updateRessources()` is called every page load via `basicprivatephp.php:79`. Its query count is:

```
1  SELECT tempsPrecedent FROM autre
1  UPDATE autre SET tempsPrecedent (optimistic lock)
1  SELECT * FROM ressources
1  SELECT * FROM constructions
1  revenuEnergie() → SELECT * FROM constructions   (again)
1  revenuEnergie() → SELECT producteur FROM constructions  (again)
1  revenuEnergie() → SELECT idalliance FROM autre
1  revenuEnergie() → SELECT duplicateur FROM alliances
4  revenuEnergie() → SELECT * FROM molecules ×4 classes
1  revenuEnergie() → SELECT energieDepensee FROM autre
1  UPDATE ressources SET energie
8  revenuAtome() loop → per atom: SELECT pointsProducteur + SELECT idalliance + conditional SELECT duplicateur = 3 queries × 8 = 24 queries
8  UPDATE ressources SET {atom} ×8
1  SELECT stabilisateur FROM constructions
1  SELECT moleculesPerdues FROM autre
1  SELECT * FROM molecules
4  UPDATE molecules ×4 + SELECT moleculesPerdues + UPDATE autre ×4 = 8 queries in molecule loop
   Possibly 4× SELECT FROM molecules + 4× INSERT INTO rapports if >6h offline
```

**Total: approximately 50–65 queries per call.** Called once on every authenticated page load
even if the player last connected 2 seconds ago. The `nbsecondes < 1` guard helps only for rapid
reloads but does not prevent the SELECT overhead.

**Impact:**
- Every authenticated page: 50–65 wasted queries when `nbsecondes` is tiny.
- The 8 individual `UPDATE ressources SET {atom}` statements could be collapsed into one.
- `revenuEnergie()` redundantly queries `constructions` twice (lines 14 and 21).
- `revenuAtome()` queries `constructions` and `autre` for every atom type (8 times).

**Remediation:**

1. Batch the 8 atom updates into a single `UPDATE ressources SET carbone=?, azote=?, ...` with all
   values computed in one pass.
2. Pass already-loaded data to `revenuEnergie()` and `revenuAtome()` instead of re-querying:
   ```php
   function revenuAtome($num, $joueur, $constructionsRow = null, $allianceRow = null) { ... }
   ```
3. Skip the molecule-decay block entirely when `$nbsecondes < 60` (molecules do not meaningfully
   decay in under a minute).

---

### FINDING-PERF-004: CRITICAL — Season Reset Blocks Web Process with Synchronous Email Loop

**File:** `includes/basicprivatephp.php:117–272`

**Description:**
The season reset runs inside the normal authenticated page-load request path. It executes:

1. A multi-level `while` loop querying molecules and alliances for every player in the top 20.
2. Multiple full-table reads (`SELECT * FROM autre ORDER BY totalPoints DESC`).
3. `remiseAZero()` which issues approximately 18 `UPDATE`/`DELETE` statements across large tables.
4. A synchronous email loop sending `mail()` to **every registered player one by one**:

```php
$exMails = dbQuery($base, 'SELECT email, login FROM membre');  // all 930 players
while ($donnees = mysqli_fetch_array($exMails)) {
    mail($mail, $sujet, $message, $header);  // synchronous SMTP call per player
}
```

**Impact:**
- With 930 players each requiring a synchronous `mail()` call (network latency 50–200 ms each):
  estimated **47–186 seconds** of blocking time on the web process.
- Apache will timeout or the browser will receive a 504 before the reset finishes.
- Any user who happens to trigger the reset suffers a multi-minute page load.
- The `DELETE FROM rapports`, `DELETE FROM messages`, etc. inside `remiseAZero()` are full table
  scans with no index benefit on delete operations for large tables.

**Remediation:**

1. Move the season reset to a **cron job** (`/etc/cron.d/tvlw-reset`) scheduled for 00:01 on the
   1st of each month. Remove the reset code from `basicprivatephp.php` entirely.
2. Replace the synchronous `mail()` loop with a **mail queue table**: insert one row per
   recipient, then process via a separate cron running `sendmail` in batches of 50/minute.
3. For `remiseAZero()`, prefer `TRUNCATE TABLE` over `DELETE FROM` for tables being fully cleared
   (`declarations`, `invitations`, `messages`, `rapports`, `actionsconstruction`,
   `actionsformation`, `actionsenvoi`, `actionsattaques`) — `TRUNCATE` is O(1) vs O(n) for DELETE.

---

## HIGH Findings

---

### FINDING-PERF-005: HIGH — catalystEffect() Hits DB on Every Call, Called 10+ Times Per Combat

**File:** `includes/catalyst.php:76–79`, `includes/formulas.php:140`, `includes/formulas.php:163`,
`includes/formulas.php:202`, `includes/combat.php:199`

**Description:**
`catalystEffect()` calls `getActiveCatalyst()` every time it is invoked. `getActiveCatalyst()`
issues a `SELECT catalyst, catalyst_week FROM statistiques` query. The function is called:

- Once per `pillage()` call — triggered 4 times in `combat.php` (lines 361–364) plus during
  the attack cost display in `attaquer.php`.
- Once per `tempsFormation()` call (formation time tooltip).
- Once per `coefDisparition()` call — triggered once per molecule class × 4 = 4 times in
  `updateRessources()` and again 4 times in `updateActions()`.
- Once in `combat.php:199` for the attack bonus.

**Total per combat resolution: approximately 12–15 DB queries to `statistiques` just for
catalyst data that does not change within a single request.**

**Impact:**
- 12–15 redundant queries per combat event processed.
- Compounds with `updateActions()` which processes all pending attacks per page load.

**Remediation:**
Cache the catalyst for the duration of the request using a static variable:

```php
function getActiveCatalyst() {
    static $cached = null;
    if ($cached !== null) return $cached;

    global $base, $CATALYSTS;
    $stats = dbFetchOne($base, 'SELECT catalyst, catalyst_week FROM statistiques');
    $currentWeek = intval(date('W')) + intval(date('Y')) * 100;

    if (!$stats || $stats['catalyst_week'] != $currentWeek) {
        $newCatalyst = $currentWeek % count($CATALYSTS);
        dbExecute($base, 'UPDATE statistiques SET catalyst=?, catalyst_week=?', 'ii', $newCatalyst, $currentWeek);
        $catalystId = $newCatalyst;
    } else {
        $catalystId = intval($stats['catalyst']);
    }

    $catalyst = $CATALYSTS[$catalystId] ?? $CATALYSTS[0];
    $catalyst['id'] = $catalystId;
    $cached = $catalyst;
    return $cached;
}
```

---

### FINDING-PERF-006: HIGH — attaque(), defense(), pillage(), coefDisparition() Hit DB Per Call

**File:** `includes/formulas.php:79–220`

**Description:**
Functions that should be pure math operations each execute DB queries to fetch medal bonus data:

```php
function attaque($oxygene, $niveau, $joueur) {
    $donneesMedaille = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', ...); // DB!
    ...
}
function defense($carbone, $niveau, $joueur) {
    $donneesMedaille = dbFetchOne($base, 'SELECT pointsDefense FROM autre WHERE login=?', ...); // DB!
    ...
}
function pillage($soufre, $niveau, $joueur) {
    $donneesMedaille = dbFetchOne($base, 'SELECT ressourcesPillees FROM autre WHERE login=?', ...); // DB!
    $catalystPillageBonus = 1 + catalystEffect('pillage_bonus'); // another DB!
    ...
}
function coefDisparition($joueur, $classeOuNbTotal, $type = 0) {
    // SELECT * FROM molecules (conditional)
    // SELECT stabilisateur FROM constructions
    // SELECT moleculesPerdues FROM autre
    // catalystEffect() → SELECT statistiques
    ...
}
```

In `combat.php`, these are called inside loops over 4 classes. For a combat with 4 attacker
classes and 4 defender classes, `attaque()` and `defense()` are each called 4 times, yielding
**32 redundant DB round-trips** to fetch data that was already loaded earlier in the same request.

**Impact:**
- Each combat resolution: 8 `SELECT FROM autre` + 4 `SELECT FROM constructions` + 4 `SELECT FROM
  statistiques` = 16 extra queries just for combat formula inputs.
- All this data is already present in `$actions['attaquant']` / `$actions['defenseur']` context.

**Remediation:**
Pass the already-loaded data as parameters instead of re-querying:

```php
function attaque($oxygene, $niveau, $pointsAttaqueRaw, $bonus = 0) {
    // $bonus already computed by caller from $autre data loaded in game_actions.php
    return round((1 + (0.1 * $oxygene) * (0.1 * $oxygene) + $oxygene) * (1 + $niveau / 50) * (1 + $bonus / 100));
}
```

The caller in `combat.php` should pre-load `$autre` for both players once and pass the bonus
value in. This converts 32 queries to 0 inside the combat loop.

---

### FINDING-PERF-007: HIGH — allianceResearchBonus() Issues 2 DB Queries Per Call, Called Frequently

**File:** `includes/db_helpers.php:47–60`

**Description:**
`allianceResearchBonus()` queries `autre` for the player's alliance ID and then queries `alliances`
for the specific tech level. It is called:

- Inside `tempsFormation()` in `formulas.php:164` — called at molecule creation time.
- Inside `pointsDeVie()` and `vieChampDeForce()` when `$joueur` parameter is passed (player.php
  construction list generation).
- Inside `combat.php` for `allianceResearchBonus($joueur, 'espionage_cost')` (line 315) and
  `allianceResearchBonus($actions['defenseur'], 'pillage_defense')` (line 367).

The function iterates `$ALLIANCE_RESEARCH` (5 items) checking `effect_type` on each iteration,
then issues 2 DB queries for the matching one. With 5 research types, finding a match near the end
means 5 iterations plus 2 queries = wasted work.

**Impact:**
- 2 DB queries × ~4 calls per page = 8 additional queries per page load.
- In `initPlayer()` for a player in an alliance, `revenuEnergie()` already loaded
  `idalliance` and `duplicateur`—this data is loaded again independently.

**Remediation:**
Index `$ALLIANCE_RESEARCH` by `effect_type` for O(1) lookup:

```php
// Build once at load time (constantesBase.php or config.php)
$ALLIANCE_RESEARCH_BY_EFFECT = [];
foreach ($ALLIANCE_RESEARCH as $techName => $tech) {
    $ALLIANCE_RESEARCH_BY_EFFECT[$tech['effect_type']] = array_merge(['tech_name' => $techName], $tech);
}
```

Cache the alliance data for the player at the start of each request (it is already loaded in
`initPlayer()` — thread it through as a parameter or use the global `$autre['idalliance']`
already loaded).

---

### FINDING-PERF-008: HIGH — classement.php Fetches Full autre Table Twice for Player Position

**File:** `classement.php:93–102`

**Description:**
To find a player's rank position, the code fetches every row from `autre` and iterates in PHP:

```php
$ex = dbQuery($base, 'SELECT * FROM autre ORDER BY ' . $order . ' DESC');  // full table scan
$compteur = 1;
while ($donnees = mysqli_fetch_array($ex)) {
    if ($donnees['login'] == $_SESSION['login']) {
        $place = $compteur;
    }
    $compteur++;
}
```

This loads all 930 player rows (full `autre` row with all columns via `SELECT *`) into PHP memory
solely to count which sequential position the current player occupies.

**Impact:**
- Transfers ~930 full rows of `autre` from MariaDB to PHP for a simple rank calculation.
- `SELECT *` fetches unused columns (description, timestamps, all point fields).
- If both a search and a default display happen, this same full-table scan runs **twice**.

**Remediation:**
Replace with a SQL rank sub-query:

```sql
SELECT COUNT(*) + 1 AS position
FROM autre
WHERE totalPoints > (SELECT totalPoints FROM autre WHERE login = ?)
```

This is O(index scan) instead of O(full table transfer to PHP).

---

### FINDING-PERF-009: HIGH — classement.php N+1 Per Player Row: 4 Queries Per Row × 20 Rows

**File:** `classement.php:153–200`

**Description:**
Inside the 20-row leaderboard loop, each player triggers:

```php
while ($donnees = mysqli_fetch_array($classement)) {  // 20 rows
    $alliance = dbFetchOne($base, 'SELECT tag, id FROM alliances WHERE id=?', ...);  // +1
    $donnees1 = dbFetchOne($base, 'SELECT id FROM membre WHERE login=?', ...);       // +1
    $ex4 = dbQuery($base, 'SELECT nombre FROM molecules WHERE proprietaire=? AND nombre!=0', ...); // +1
    $guerreCount = dbCount($base, '... FROM declarations WHERE ...');                // +1
    $pacteCount  = dbCount($base, '... FROM declarations WHERE ...');                // +1
    $victoires = dbFetchOne($base, 'SELECT victoires FROM autre WHERE login=?', ...); // +1 (line 192)
    $prestigeData = dbFetchOne($base, 'SELECT total_pp FROM prestige WHERE login=?', ...); // +1
    // = 7 queries per row
}
```

**Impact:**
- 20 rows × 7 queries = **140 DB queries** to render a leaderboard page of 20 players.
- `victoires` and `totalPoints` are both already present in the `autre` table which was already
  queried for the main loop — `victoires` is fetched a second time unnecessarily.
- `prestige` data could be JOINed.

**Remediation:**
Use a single JOIN query:

```sql
SELECT a.*, m.id AS membre_id, al.tag AS alliance_tag, al.id AS alliance_id,
       p.total_pp,
       COALESCE(mol.total_molecules, 0) AS total_molecules
FROM autre a
LEFT JOIN membre m ON m.login = a.login
LEFT JOIN alliances al ON al.id = a.idalliance
LEFT JOIN prestige p ON p.login = a.login
LEFT JOIN (
    SELECT proprietaire, SUM(nombre) AS total_molecules
    FROM molecules WHERE nombre != 0
    GROUP BY proprietaire
) mol ON mol.proprietaire = a.login
ORDER BY a.totalPoints DESC
LIMIT ?, ?
```

This collapses 140 queries into 1 plus the war/pact counts (which can be pre-loaded in 2 queries
for all relevant alliances).

---

### FINDING-PERF-010: HIGH — recalculerStatsAlliances() Full N+1 Called on Alliance Leaderboard

**File:** `includes/player.php:653–674`, `classement.php:247`

**Description:**
`recalculerStatsAlliances()` is called unconditionally every time the alliance leaderboard tab
is viewed. It:
1. Fetches all alliances.
2. For each alliance, fetches all members.
3. Issues one `UPDATE alliances SET ...` per alliance.

```php
$ex = dbQuery($base, 'SELECT id FROM alliances');
while ($donnees = mysqli_fetch_array($ex)) {
    $ex1 = dbQuery($base, 'SELECT * FROM autre WHERE idalliance=?', ...);  // N queries
    while ($donnees1 = mysqli_fetch_array($ex1)) { /* aggregate */ }
    dbExecute($base, 'UPDATE alliances SET pointstotaux=?...', ...);       // N UPDATEs
}
```

**Impact:**
- If 50 alliances exist: 1 + 50 + 50 = **101 queries** on every alliance tab view.
- This data could be maintained incrementally via event triggers on point changes.
- The full recalculation is triggered even when nothing has changed since the last view.

**Remediation:**
Maintain alliance points incrementally: after every `ajouterPoints()` call, update the
corresponding alliance's aggregate. Remove `recalculerStatsAlliances()` from the page-load path
and replace it with a periodic cron (every 5 minutes) or event-driven update.

---

### FINDING-PERF-011: HIGH — basicprivatehtml.php Executes 15+ Queries for Menu/Badge Rendering

**File:** `includes/basicprivatehtml.php:1–463`

**Description:**
The navigation sidebar (rendered on every authenticated page load) executes these queries during
HTML generation:

```
Line   3: SELECT count(*) FROM messages WHERE expeditaire=?
Line   6: SELECT count(*) FROM molecules WHERE proprietaire=? AND formule!=?
Line  51: SELECT * FROM ressources WHERE login=?
Line  53: SELECT nombre FROM molecules WHERE proprietaire=? AND nombre!=?
Line  59: SELECT * FROM constructions WHERE login=?
Line 115: SELECT * FROM molecules WHERE proprietaire=?
Line 151: SELECT idalliance FROM autre WHERE login=?
Line 233: SELECT moderateur FROM membre WHERE login=?
Line 249: SELECT invite FROM invitations WHERE invite=?
Line 253: SELECT idalliance FROM autre WHERE login=?          (DUPLICATE of line 151)
Line 260: SELECT destinataire FROM messages WHERE ... AND statut=0
Line 268: SELECT destinataire FROM rapports WHERE ... AND statut=0
Line 277: SELECT count(*) FROM sujets WHERE statut=0
Line 278: SELECT count(*) FROM statutforum WHERE login=?
Line 322: SELECT idalliance FROM autre WHERE login=?          (DUPLICATE of line 151/253)
Line 326: SELECT duplicateur FROM alliances WHERE id=?
```

Many of these duplicate queries that already ran in `basicprivatephp.php` or `initPlayer()`.
`SELECT idalliance FROM autre` runs at least 3 times in `basicprivatehtml.php` alone.

**Impact:**
- ~16 queries per page load purely for navigation rendering.
- `$autre` global is already populated by `initPlayer()` yet queried again for `idalliance`.
- `revenuAtome()` is called 8 times (line 298) inside a foreach loop — each call queries DB
  (see FINDING-PERF-006).

**Remediation:**
Use already-loaded globals: `$autre['idalliance']` is available from `initPlayer()`.
`$membre['moderateur']` is in the already-loaded `$membre` global.
Replace all redundant reads with the in-memory globals.

---

### FINDING-PERF-012: HIGH — ajouter() Issues 2 Queries per Field Update (Read-Modify-Write)

**File:** `includes/db_helpers.php:18–24`

**Description:**
`ajouter()` is a utility that adds a value to a field:

```php
function ajouter($champ, $bdd, $nombre, $joueur) {
    $d = dbFetchOne($base, "SELECT $champ FROM $bdd WHERE login=?", 's', $joueur); // SELECT
    dbExecute($base, "UPDATE $bdd SET $champ=? WHERE login=?", 'ds', ($d[$champ] + $nombre), $joueur); // UPDATE
}
```

This executes 2 queries where 1 suffices. It is called:
- 4 times in `combat.php` (lines 534–537): 8 queries.
- Multiple times in `basicprivatehtml.php` mission completion (lines 185–197): up to 8 calls × 2 = 16 queries.
- During alliance building upgrades and market transactions.

**Impact:**
- In a single combat with mission rewards: up to **24 extra queries** vs. 12 with atomic updates.
- Race condition risk: two concurrent requests can read the same value before either writes back.

**Remediation:**
Replace with an atomic SQL increment:

```php
function ajouter($champ, $bdd, $nombre, $joueur) {
    global $base;
    // Whitelist $champ and $bdd before calling this function (already done at call sites)
    dbExecute($base, "UPDATE $bdd SET $champ = $champ + ? WHERE login=?", 'ds', $nombre, $joueur);
}
```

---

## MEDIUM Findings

---

### FINDING-PERF-013: MEDIUM — Password Hash Re-Validated on Every Authenticated Request

**File:** `includes/basicprivatephp.php:16–23`

**Description:**
On every page load, the auth guard:
1. Queries `SELECT pass_md5 FROM membre WHERE login=?`
2. Compares the stored hash against `$_SESSION['mdp']`.

```php
$row = dbFetchOne($base, 'SELECT pass_md5 FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) { ... }
```

This compares hashes (a fast string equality check) so it does not incur `password_verify()`
overhead. However, the DB query occurs on every single request to verify session validity
when PHP sessions already cryptographically tie the session data to the server-side session file.

**Impact:**
- 1 extra DB query per request for every authenticated user.
- At 930 players each making 10 requests/day: 9,300 wasted queries/day.
- The session itself is the auth token — the password hash should only be re-checked on
  password-change events (store a `password_version` counter in the session).

**Remediation:**
Store a `session_token` (a random 32-byte hex value stored in `membre`) in the session at login
time. On each request compare `$_SESSION['token'] === $row['session_token']`. This maintains
session invalidation on password change (reset `session_token` in DB) while removing the
dependency on per-request hash comparison. Alternatively, skip the re-validation entirely and
rely on session security (already hardened: `use_strict_mode=1`, `httponly=1`).

---

### FINDING-PERF-014: MEDIUM — initPlayer() Issues 8 Separate DB Queries for actionsconstruction

**File:** `includes/player.php:224–292`

**Description:**
`initPlayer()` queries `actionsconstruction` eight separate times — once per building type:

```php
$exNiveauActuel = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=?', 'ss', $joueur, 'generateur');
$exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=?', 'ss', $joueur, 'producteur');
$exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=?', 'ss', $joueur, 'depot');
// ... and 5 more identical patterns for champdeforce, ionisateur, condenseur, lieur, stabilisateur, coffrefort
```

All 8 queries have the same WHERE clause prefix `login=?` and differ only by `batiment=?`.

**Impact:**
- 8 queries that could be 1: `SELECT batiment, niveau FROM actionsconstruction WHERE login=?`
- Since `initPlayer()` is called 2–4 times per request, this is 16–32 redundant queries.

**Remediation:**
Replace the 8 individual queries with a single query and index by building name:

```php
$constructionQueue = [];
$ex = dbQuery($base, 'SELECT batiment, niveau FROM actionsconstruction WHERE login=? ORDER BY niveau DESC', 's', $joueur);
while ($row = mysqli_fetch_assoc($ex)) {
    if (!isset($constructionQueue[$row['batiment']])) {
        $constructionQueue[$row['batiment']] = $row['niveau'];
    }
}
// Then: $niveauActuel['niveau'] = $constructionQueue['generateur'] ?? $constructions['generateur'];
```

---

### FINDING-PERF-015: MEDIUM — Forum N+1: 3 Queries Per Forum Section (forum.php)

**File:** `forum.php:62–84`

**Description:**
The forum index page loops over forum sections with N+1 queries:

```php
$ex = dbQuery($base, 'SELECT * FROM forums');               // 1 query
while ($forum = mysqli_fetch_array($ex)) {
    $nbSujets  = dbFetchOne($base, 'SELECT count(*) FROM sujets WHERE idforum=? AND statut=0', ...); // +1
    $statutForum = dbFetchOne($base, 'SELECT count(*) ... FROM statutforum WHERE login=? AND idforum=?', ...); // +1
    $nbMessages = dbFetchOne($base, 'SELECT count(*) FROM sujets s, reponses r WHERE idforum=? AND s.id=r.idsujet', ...); // +1
}
```

With 3–5 forum sections this is 9–15 queries for a simple forum index page.

**Impact:**
- Minor at current scale (3–5 forums) but architecturally wrong pattern.
- The `sujets s, reponses r` implicit JOIN (comma syntax) is an old-style cross join that relies
  entirely on the WHERE clause to filter — the query optimizer may not use indexes optimally.

**Remediation:**
Use a single aggregating query with LEFT JOINs:

```sql
SELECT f.id, f.titre,
       COUNT(DISTINCT s.id) FILTER (WHERE s.statut=0) AS nbSujets,
       COUNT(r.id) AS nbMessages,
       COUNT(sf.login) AS nbLus
FROM forums f
LEFT JOIN sujets s ON s.idforum = f.id AND s.statut = 0
LEFT JOIN reponses r ON r.idsujet = s.id
LEFT JOIN statutforum sf ON sf.idforum = f.id AND sf.login = ?
GROUP BY f.id, f.titre
```

---

### FINDING-PERF-016: MEDIUM — attaquer.php Loads Full Unfiltered attaquer.php Attack DB Twice

**File:** `attaquer.php:6–8, 17–19`

**Description:**
At lines 6–8 and 17–19, `$donneesMedaille` and `$donnees` are fetched for `nbattaques` separately:

```php
$donneesMedaille = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', ...);
// ... 11 lines later ...
$donnees = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', ...);
```

These two queries fetch the same column from the same row for the same user.

**Impact:**
- 1 wasted query per `attaquer.php` load.
- Minor individually but indicative of a systemic pattern of not reusing loaded data.

**Remediation:**
Remove the duplicate query; use `$donneesMedaille` for both calculations.

---

### FINDING-PERF-017: MEDIUM — coordonneesAleatoires() Loads ALL Player Positions Into PHP Array

**File:** `includes/player.php:561–620`

**Description:**
When a new player first connects, `coordonneesAleatoires()` builds a full in-memory grid:

```php
$carte = [];
for ($i = 0; $i < $inscrits['tailleCarte']; $i++) {
    for ($j = 0; $j < $inscrits['tailleCarte']; $j++) {
        $temp[] = 0;
    }
    $carte[] = $temp;
}
$ex = dbQuery($base, 'SELECT x,y FROM membre');  // all players
while ($joueurs = mysqli_fetch_array($ex)) {
    $carte[$joueurs['x']][$joueurs['y']] = 1;
}
```

With a `tailleCarte` of 32 (1024 cells for 930 players) this builds a 32×32 PHP array plus
loads 930 rows. With 2,000 players `tailleCarte` grows to ~45 (2025 cells).

**Impact:**
- O(tailleCarte²) memory allocation: at 32×32 = 1,024 array entries.
- All 930 player positions loaded into PHP memory to find one free edge cell.
- Could be replaced with a direct SQL query for free edge positions.

**Remediation:**
Query for free edge positions directly:

```sql
SELECT candidate_x, candidate_y
FROM (
    SELECT tailleCarte - 1 AS candidate_x, seq AS candidate_y FROM seq_0_to_N
    UNION ALL
    SELECT seq AS candidate_x, tailleCarte - 1 AS candidate_y FROM seq_0_to_N
) candidates
WHERE (candidate_x, candidate_y) NOT IN (SELECT x, y FROM membre WHERE x != -1000)
ORDER BY RAND()
LIMIT 1
```

Or simpler: use a MariaDB sequence and `LEFT JOIN` to find any unoccupied edge cell.

---

### FINDING-PERF-018: MEDIUM — Inline CSS Injected on Every Page (4 KB per Request)

**File:** `includes/style.php:1–304`

**Description:**
All custom CSS is output as an inline `<style>` block on every page. The inline block is
approximately 4 KB of CSS that cannot be cached by the browser across pages.

**Impact:**
- 4 KB re-sent on every page navigation.
- Over a session of 20 page views: 80 KB of unnecessary CSS transfer.
- Browser cannot cache inline styles — external stylesheet cached after first load.

**Remediation:**
Move `includes/style.php` contents to a static file `css/my-app-custom.css`. Reference it in
`includes/meta.php` alongside the existing stylesheet links:

```html
<link rel="stylesheet" href="css/my-app-custom.css">
```

Add Apache cache headers for all CSS/JS:

```apache
<FilesMatch "\.(css|js|png|jpg|woff2?)$">
    Header set Cache-Control "public, max-age=2592000, immutable"
</FilesMatch>
```

---

### FINDING-PERF-019: MEDIUM — JavaScript Loaded Render-Blocking After `</body>` Without `defer`

**File:** `includes/copyright.php:21–29`

**Description:**
Five JavaScript files are loaded after `</body>` but before page interaction is possible:

```html
<script type="text/javascript" src="cordova.js"></script>
<script type="text/javascript" src="js/notification.js"></script>
<script type="text/javascript" src="js/PushNotification.js"></script>
<script type="text/javascript" src="js/framework7.min.js"></script>  <!-- 311 KB -->
<script type="text/javascript" src="js/jquery-3.1.1.min.js"></script> <!-- 85 KB -->
<script type="text/javascript" src="js/loader.js"></script>            <!-- 70 KB -->
```

Total synchronous JS payload: **~466 KB** (uncompressed).

`cordova.js` is referenced but does not exist (this is a web app, not Cordova) — produces a
404 HTTP request on every page load.

`js/aes.js` and `js/aes-json-format.js` (14 KB + 1 KB) are loaded but there is no evidence
they are used by any game page (they relate to jCryption which is not implemented).

**Impact:**
- 466 KB of JS must be downloaded before Framework7 can initialise.
- The missing `cordova.js` generates a 404 on every page load — wasted round trip.
- Unused AES/jCryption libraries add ~15 KB of dead weight.

**Remediation:**
1. Remove `cordova.js` reference entirely.
2. Remove `js/aes.js` and `js/aes-json-format.js` if jCryption is not in use.
3. Add `defer` attribute to all script tags:
   ```html
   <script defer src="js/framework7.min.js"></script>
   <script defer src="js/jquery-3.1.1.min.js"></script>
   ```
4. Enable Apache mod_deflate for text/javascript and text/css:
   ```apache
   AddOutputFilterByType DEFLATE text/javascript application/javascript text/css
   ```
   This reduces 311 KB framework7.min.js to approximately 90 KB over the wire.

---

### FINDING-PERF-020: MEDIUM — CSS Total Download 688 KB (framework7.material + colors both loaded)

**File:** `includes/meta.php:17–19`

**Description:**
The page loads three separate Framework7 CSS files:

```html
<link rel="stylesheet" href="css/framework7.material.min.css">     <!-- 171 KB -->
<link rel="stylesheet" href="css/framework7.material.colors.min.css"> <!-- 346 KB -->
<link rel="stylesheet" href="css/framework7-icons.css">              <!-- 1 KB -->
```

The colors file (346 KB minified) is loaded unconditionally even though only a handful of
Framework7 color utilities are actually used. The combined CSS payload is **518 KB** before
gzip compression.

**Impact:**
- Without gzip: 518 KB of CSS on first load.
- With gzip (not currently configured): ~80–100 KB — a 5x saving available for free.
- The colors file alone is larger than framework7.material.min.css itself.

**Remediation:**
1. Enable `mod_deflate` for CSS (addresses most of the impact).
2. Consider building a custom Framework7 bundle with only the components in use
   (cards, list items, panels, toolbar, popover, calendar, notifications).
3. As an immediate win, add `.htaccess` gzip and cache headers:
   ```apache
   <IfModule mod_deflate.c>
       AddOutputFilterByType DEFLATE text/css text/javascript application/javascript
   </IfModule>
   <IfModule mod_headers.c>
       <FilesMatch "\.(css|js)$">
           Header set Cache-Control "public, max-age=604800"
       </FilesMatch>
   </IfModule>
   ```

---

### FINDING-PERF-021: MEDIUM — updateActions() Calls initPlayer() Twice and updateRessources() Recursively

**File:** `includes/game_actions.php:23, 526`, `includes/game_actions.php:74–79`

**Description:**
`updateActions()` calls `initPlayer()` at line 23 (beginning) and again at line 526 (end).
More critically, during combat resolution for each pending attack where the target is the
current player, it calls both `updateRessources()` and `updateActions()` recursively for the
opponent:

```php
if ($actions['attaquant'] == $joueur) {
    $enFace = $actions['defenseur'];
    updateRessources($actions['defenseur']);   // recursive call
    updateActions($actions['defenseur']);      // recursive call → another initPlayer() + more queries
} else { ... }
```

The static guard `$updating[$joueur]` prevents infinite recursion but only per-player. A player
with 3 pending attacks against them will trigger 3 × `updateRessources()` + `updateActions()`
chains, each with their full query overhead.

**Impact:**
- With 3 pending attacks on a player: 3 × ~50 queries for `updateRessources()` + 3 × 17+ for
  `initPlayer()` inside `updateActions()` = ~200 additional queries triggered from the initial
  `updateActions()` call.
- The second `initPlayer($joueur)` at line 526 is always redundant since it was called at line 23.

**Remediation:**
1. Remove the trailing `initPlayer($joueur)` at line 526 — the caller
   (`constantes.php` line 13) calls `initPlayer()` immediately after anyway.
2. For the recursive calls, pass already-loaded data to avoid re-querying constructions/resources
   for players whose state was loaded by `updateRessources()` 10 lines prior.

---

## LOW Findings

---

### FINDING-PERF-022: LOW — autocomplete Preloads All Player Logins Into Page HTML

**File:** `includes/copyright.php:51–57`

**Description:**
The alliance invite autocomplete pre-loads all player usernames into the HTML page as a JS array:

```php
$ex = query('SELECT login FROM membre WHERE login!=\''.$_SESSION['login'].'\'');
while ($noms = mysqli_fetch_array($ex)) {
    echo '"'.$noms['login'].'",';
}
```

At 930 players this embeds ~10–15 KB of player name strings in every page's HTML, transferred
on every page load regardless of whether the user ever opens the invite panel.

**Impact:**
- ~10–15 KB added to every authenticated page's HTML payload.
- Old-style `query()` wrapper used (interpolated SQL) instead of prepared statement.

**Remediation:**
Lazy-load via AJAX only when the invite input is focused:

```javascript
$$('#labelInviter').on('focus', function() {
    if (window.joueurs) return; // already loaded
    $.getJSON('api.php?id=joueurs', function(data) {
        window.joueurs = data;
    });
});
```

Create an `api.php?id=joueurs` endpoint that returns the player list as JSON with a 60-second
cache header. Replace `query()` with a prepared statement.

---

### FINDING-PERF-023: LOW — DELETE Used Instead of TRUNCATE for Season Reset Tables

**File:** `includes/player.php:748–755`

**Description:**
`remiseAZero()` fully empties several tables using `DELETE FROM`:

```php
dbExecute($base, 'DELETE FROM declarations');
dbExecute($base, 'DELETE FROM invitations');
dbExecute($base, 'DELETE FROM messages');
dbExecute($base, 'DELETE FROM rapports');
dbExecute($base, 'DELETE FROM actionsconstruction');
dbExecute($base, 'DELETE FROM actionsformation');
dbExecute($base, 'DELETE FROM actionsenvoi');
dbExecute($base, 'DELETE FROM actionsattaques');
```

`DELETE FROM` scans each row and writes a log entry per row. For a table with 10,000 rows this
is thousands of log writes. `TRUNCATE TABLE` resets the table in O(1) by dropping and recreating
the table data pages.

**Impact:**
- On a busy month-end with 10,000 combat reports and 5,000 messages: `DELETE` is ~10–100x slower
  than `TRUNCATE`.
- Adds seconds to an already blocking reset operation.

**Remediation:**
Replace all 8 `DELETE FROM` calls with `TRUNCATE TABLE` for tables being fully cleared:

```php
dbExecute($base, 'TRUNCATE TABLE declarations');
dbExecute($base, 'TRUNCATE TABLE invitations');
dbExecute($base, 'TRUNCATE TABLE messages');
// ...etc
```

Note: `TRUNCATE` resets `AUTO_INCREMENT` — verify this is acceptable (likely yes for a monthly
reset). `TRUNCATE` also cannot be rolled back in the same transaction as other statements.

---

### FINDING-PERF-024: LOW — connectes Table Receives 3 Queries on Every Page Load (Poll Pattern)

**File:** `includes/basicprivatephp.php:58–72`

**Description:**
On every page load, the online tracking issues 3 queries:

```php
$donnees = dbFetchOne($base, 'SELECT COUNT(*) FROM connectes WHERE ip=?', ...); // check
if ($donnees['nbre_entrees'] == 0) {
    dbExecute($base, 'INSERT INTO connectes VALUES(?, ?)', ...);                 // insert
} else {
    dbExecute($base, 'UPDATE connectes SET timestamp=? WHERE ip=?', ...);        // update
}
dbExecute($base, 'DELETE FROM connectes WHERE timestamp < ?', ...);             // cleanup
```

The cleanup DELETE runs on **every authenticated page load for every user** — 930 users each
loading pages means potentially 930 DELETE operations per minute during peak time, most of which
delete 0 rows.

**Impact:**
- 3 queries per page load × 930 active users = 2,790 queries/minute of purely administrative work.
- The cleanup DELETE against `timestamp` requires a full table scan unless indexed on `timestamp`.
  (The current migration only indexes on `ip`.)

**Remediation:**
1. Replace the SELECT + INSERT/UPDATE pattern with `INSERT ... ON DUPLICATE KEY UPDATE`:
   ```sql
   INSERT INTO connectes (ip, timestamp) VALUES(?, ?)
   ON DUPLICATE KEY UPDATE timestamp = VALUES(timestamp)
   ```
   This reduces 2 queries to 1.

2. Run the cleanup DELETE only with ~5% probability per request to reduce frequency by 20x:
   ```php
   if (rand(1, 20) === 1) {
       dbExecute($base, 'DELETE FROM connectes WHERE timestamp < ?', 'i', $timestamp_5min);
   }
   ```
   Or move cleanup to a cron job running every minute.

3. Add an index on `connectes.timestamp` to make the DELETE efficient:
   ```sql
   ALTER TABLE connectes ADD INDEX idx_connectes_timestamp (timestamp);
   ```

---

### FINDING-PERF-025: LOW — basicprivatephp.php Fetches statistiques Table 3–4 Times Per Request

**File:** `includes/basicprivatephp.php:111–115`

**Description:**
Within a single page load, `statistiques` is queried redundantly:

```php
$debutRow = dbFetchOne($base, 'SELECT debut FROM statistiques');          // line 111
$maintenanceRow = dbFetchOne($base, 'SELECT maintenance FROM statistiques'); // line 114
// ...
$debutRow2 = dbFetchOne($base, 'SELECT debut FROM statistiques');         // line 194 (inside reset branch)
```

Lines 111 and 114 could be combined into a single query.

**Impact:**
- 2 queries that could be 1 on every page load.
- At 930 players × 10 pages/day = 9,300 wasted queries/day.

**Remediation:**
Combine into one query:

```php
$statsRow = dbFetchOne($base, 'SELECT debut, maintenance, tailleCarte FROM statistiques');
$debut = $statsRow;
$maintenance = $statsRow;
```

---

### FINDING-PERF-026: LOW — Missing Index on membre.x and membre.y for Map Queries

**File:** `migrations/0001_add_indexes.sql` (absent), `attaquer.php:295`, `includes/player.php:580`

**Description:**
The existing migration `0001_add_indexes.sql` does not add indexes on `membre.x` and `membre.y`
despite the map query (`SELECT * FROM membre`) and `coordonneesAleatoires()` (`SELECT x,y FROM membre`)
both performing full table scans to retrieve position data. When filtering players off the map
(`WHERE x != -1000`), the lack of an index means a full scan of all 930 rows.

**Impact:**
- Every map render: full table scan on `membre` (930 rows).
- `coordonneesAleatoires()` scans `membre` fully on each new player placement.
- At 930 players this is manageable, but degrades linearly.

**Remediation:**
Add a composite index on the map lookup columns:

```sql
ALTER TABLE membre ADD INDEX idx_membre_position (x, y);
ALTER TABLE membre ADD INDEX idx_membre_x_active (x) WHERE x != -1000;
```

For the map query specifically, adding `WHERE x != -1000` to the `SELECT * FROM membre` in
`attaquer.php:295` would also filter out all offline players and reduce the result set
significantly.

---

### FINDING-PERF-027: LOW — Entire Game Locale/Tutorial Content Recalculated and Echoed on Every Request

**File:** `includes/basicprivatehtml.php:377–409`, `includes/cardsprivate.php`

**Description:**
The `$listeAides` array contains 8 large help text strings (with embedded HTML) that are
constructed in PHP and echoed as popover divs on every authenticated page load. These strings
include references to game constants and helper function calls (`joueur("Guortates")`,
`alliance("Equipe")`), making them impossible to static-cache without change. But the content
is essentially static per-session and does not require DB queries — it just produces unnecessary
string processing and output buffering on every page load.

The tutorial mission list (`$listeMissions`) in `basicprivatehtml.php:9–49` similarly rebuilds
20 mission objects on every page load including during mission rendering, even after the player
has completed all missions.

**Impact:**
- ~5–10 KB of HTML generated and transmitted per page load for popovers that are hidden by default.
- Tutorial card appears every page load even for players who finished it (the `niveaututo >= 10`
  check stops the tuto card but the `$listeMissions` array is still constructed unconditionally).

**Remediation:**
1. Move static help text to a JSON file loaded on demand via AJAX when the help icon is clicked.
2. Wrap `$listeMissions` construction inside the `if ($tuto['niveaututo'] >= 10)` branch check.

---

## Quantitative Summary

| Finding | Queries per Request | Estimated Saving |
|---------|-------------------|-----------------|
| PERF-001 (map N+1) | 2,790 → 1 | 2,789 queries/map-load |
| PERF-002 (initPlayer × 4) | 68 → 17 | 51 queries/request |
| PERF-003 (updateRessources) | 50–65 → 12 | 40–53 queries/request |
| PERF-004 (season reset email) | ~60s block → async | 930 × mail() removed |
| PERF-005 (catalystEffect cache) | 12–15 → 1 | 11–14 queries/combat |
| PERF-006 (formula DB queries) | 32 → 0 | 32 queries/combat |
| PERF-009 (leaderboard N+1) | 140 → 2 | 138 queries/page |
| PERF-011 (nav sidebar) | 16 → 3 | 13 queries/request |
| PERF-014 (actionsconstruction ×8) | 8 → 1 per initPlayer call | 7 queries per call |
| PERF-024 (connectes 3 queries) | 3 → 1 | 2 queries/request |

**Estimated baseline queries per typical page load (attaquer.php):**
- Current: ~2,900+ queries
- After all remediations: ~30–40 queries
- Improvement factor: **70–90x reduction**

---

## Remediation Priority Order

1. **PERF-001** — attaquer.php map N+1 (single JOIN, immediate impact)
2. **PERF-004** — Season reset cron + async email (prevents timeouts)
3. **PERF-005** — catalystEffect static cache (2-line fix, high frequency)
4. **PERF-002** — initPlayer request-level cache (architectural, high impact)
5. **PERF-003** — updateRessources batch atom UPDATEs
6. **PERF-006** — Pass pre-loaded data to attaque()/defense()/pillage()
7. **PERF-009** — Leaderboard JOIN query
8. **PERF-012** — ajouter() atomic UPDATE
9. **PERF-019** — Remove cordova.js 404 + add defer to scripts
10. **PERF-020** — Enable mod_deflate for CSS/JS (Apache config, zero code change)
11. **PERF-018** — Move inline CSS to external file
12. **PERF-023** — TRUNCATE vs DELETE in reset
13. **PERF-024** — INSERT ON DUPLICATE KEY + stochastic cleanup
14. **PERF-025** — Combine statistiques queries
15. All remaining LOW findings

---

## Infrastructure Recommendations

### Enable HTTP Compression (Zero Code Change)

Add to `/etc/apache2/sites-available/tvlw.conf` or `.htaccess`:

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json
</IfModule>
```

Estimated page size reduction: **60–70%** for HTML/CSS/JS responses.

### Enable PHP OPcache

Verify OPcache is active and tuned in `/etc/php/8.2/apache2/php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

With ~80 PHP files, OPcache eliminates repeated compilation overhead. Each request currently
parses and compiles all included PHP files from disk. With OPcache, compiled bytecode is cached
in shared memory.

### MariaDB Query Cache Consideration

MariaDB 10.11 has removed the deprecated query cache. Instead, consider:
- `innodb_buffer_pool_size=256M` (on a VPS with 1–2 GB RAM, set to 50–60% of RAM)
- `innodb_flush_log_at_trx_commit=2` (reduces I/O at slight durability cost — acceptable for a game)

```sql
-- Check current buffer pool usage:
SHOW STATUS LIKE 'Innodb_buffer_pool_%';
```

### Session Storage

The default PHP file-based session storage involves a filesystem lock per request. With 930
concurrent users this creates session file contention. Consider configuring session storage in
MariaDB or Memcached:

```ini
session.save_handler = redis
session.save_path = "tcp://127.0.0.1:6379"
```

Redis would also unlock the request-level caching capability described in PERF-002 and PERF-005
(store computed player state with a short TTL).
