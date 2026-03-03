# SQL-CROSS — Cross-Domain SQL Injection and Query Safety Analysis

**Date:** 2026-03-03
**Auditor:** postgres-pro (SQL-CROSS scan)
**Scope:** All PHP files in game root and includes/ (vendor excluded)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 5     |
| MEDIUM   | 9     |
| LOW      | 6     |
| INFO     | 4     |

---

## CRITICAL Findings

---

### CRIT-01 — Raw column interpolation in `update.php` — SQL Injection via `$nomsRes` column names embedded in query string

**File:** `includes/update.php`
**Lines:** 36, 42

**Pattern:**
```php
$donnees = dbFetchOne($base, "SELECT $ressource, revenu$ressource FROM ressources WHERE login=?", 's', $targetPlayer);
dbExecute($base, "UPDATE ressources SET $ressource=? WHERE login=?", 'ds', $$ressource, $targetPlayer);
```

**Analysis:**
`$ressource` is drawn from the global `$nomsRes` array, which is defined in `includes/config.php` as a fixed array of 8 atom names (`hydrogene`, `carbone`, `oxygene`, etc.). Under normal circumstances this is safe because `$nomsRes` is server-defined. However, the column names are interpolated directly into the SQL string without any whitelist check at the point of use. If `$nomsRes` were ever modified — by a misconfigured include, a global variable collision, or a future code change — an attacker who can influence `$nomsRes` could inject arbitrary SQL. This differs from `db_helpers.php:ajouter()` and `game_resources.php:updateRessources()`, both of which explicitly validate against a whitelist before interpolation.

The pattern also creates `revenu$ressource` column names (e.g. `revenuhydrogene`) in the SELECT, which are not validated at all.

**Impact:** If `$nomsRes` is tampered with, full column-level injection is possible in an UPDATE affecting the `ressources` table for any player. The function `updateTargetResources()` is called from `game_actions.php` before every combat resolution, making this a high-frequency, high-value code path.

**Recommendation:**
Apply the same whitelist guard used in `db_helpers.php`:
```php
static $allowedResColumns = ['hydrogene','carbone','oxygene','azote','chlore','soufre','brome','iode'];
foreach ($nomsRes as $num => $ressource) {
    if (!in_array($ressource, $allowedResColumns, true)) {
        error_log("updateTargetResources: invalid column '$ressource'");
        continue;
    }
    // safe to use
}
```

---

### CRIT-02 — Raw column interpolation in `game_resources.php:updateRessources()` — no whitelist guard at point of use

**File:** `includes/game_resources.php`
**Lines:** 152-158

**Pattern:**
```php
foreach ($nomsRes as $num => $ressource) {
    ${'revenu' . $ressource} = revenuAtome($num, $joueur);
    $$ressource = $donnees[$ressource] + ${'revenu' . $ressource} * ($nbsecondes / 3600);
    // ...
    $sqlParts[] = "$ressource=?";
    // ...
}
dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $sqlParts) . ' WHERE login=?', $sqlTypes, ...$sqlParams);
```

**Analysis:**
`$ressource` is used to build `$sqlParts[]` which is then concatenated into the SQL string. Unlike `db_helpers.php:ajouter()`, there is no explicit whitelist validation before insertion into `$sqlParts`. The value is trusted to come from `$nomsRes`, but there is no defensive check at the use site. The comment in `ajouter()` explains this exact concern — yet `updateRessources()` skips the same protection.

**Impact:** Same as CRIT-01 — a compromised `$nomsRes` leads to SQL injection in a high-frequency UPDATE path executed every time a player's resources are refreshed.

**Recommendation:** Add a whitelist check inside the `foreach` loop before appending to `$sqlParts`, mirroring the pattern in `db_helpers.php`.

---

## HIGH Findings

---

### HIGH-01 — `alliance.php` lines 94-113 — Alliance research column interpolated into SELECT and UPDATE without sufficient runtime protection

**File:** `alliance.php`
**Lines:** 98, 108

**Pattern:**
```php
$techName = $_POST['upgradeResearch'];  // user-supplied POST value
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
// ...
dbExecute($base, 'UPDATE alliances SET ' . $techName . '=?, energieAlliance=? WHERE id=?', 'idi', $newLevel, $newEnergie, $idalliance['idalliance']);
```

**Analysis:**
`$techName` originates directly from `$_POST['upgradeResearch']` — a user-controlled value. The check at line 94 is:
```php
if (isset($_POST['upgradeResearch']) && isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']]))
```
This uses `isset()` on the `$ALLIANCE_RESEARCH` config array as a whitelist. This is functional, but the protection hinges on the config array key matching the column name exactly and on the config not being modified. Critically, there is no secondary validation against the `ALLIANCE_RESEARCH_COLUMNS` constant defined in `db_helpers.php`. The hard-coded constant exists precisely for this defence-in-depth purpose but is not applied here.

The identical pattern appears in `db_helpers.php:allianceResearchLevel()` and `allianceResearchBonus()`, where the hard-coded constant `ALLIANCE_RESEARCH_COLUMNS` is checked before interpolation. `alliance.php` skips this second check.

**Impact:** If an attacker can craft a POST body with `upgradeResearch` set to a value that passes the `isset($ALLIANCE_RESEARCH[...])` check but also contains SQL metacharacters (currently not possible because config keys are safe), or if a future config change adds a key with special characters, this becomes a direct injection point into an UPDATE on the `alliances` table.

**Recommendation:**
Add the whitelist guard:
```php
if (!in_array($techName, ALLIANCE_RESEARCH_COLUMNS, true)) {
    $erreur = "Recherche invalide.";
    // do not proceed
}
```
before the SELECT and UPDATE at lines 98 and 108.

---

### HIGH-02 — `marche.php` lines 106-115 — Dynamic UPDATE for resource deduction uses raw string concatenation

**File:** `marche.php`
**Lines:** 100-115

**Pattern:**
```php
foreach ($nomsRes as $num => $ressource) {
    $chaine = $chaine . '' . $ressource . '=' . ($ressources[$ressource] - $_POST[$ressource . 'Envoyee']) . '' . $plus;
}
$stmt = mysqli_prepare($base, 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?');
```

**Analysis:**
The `$chaine` variable is constructed by concatenating resource column names from `$nomsRes` (server-side) with computed numeric values derived from `$ressources[$ressource]` (DB row) and `$_POST[$ressource . 'Envoyee']`. The POST values have been through `intval()` at lines 39-40, so numeric injection is mitigated. However:

1. The column names from `$nomsRes` are interpolated without whitelist check at this site.
2. The numeric values are computed as `($ressources[$ressource] - $_POST[$ressource . 'Envoyee'])`, where `$_POST` has been intval-cast. The result is numeric and safe in practice, but the approach bypasses parameterized binding for the column data.
3. The same style query appears in `constructions.php:traitementConstructions()` at line 246-251.

This pattern predates the `dbExecute` refactor. The transaction-protected market buy/sell paths (lines 168-226 and 279-338) were correctly updated to use `mysqli_prepare` with parameterized column names via `$nomsRes[$numRes]`, but the "envoi" (resource transfer) path was not fully migrated.

**Impact:** Medium-high. The column names are server-controlled and POST values are intval-cast, so exploitation is difficult in the current code, but the pattern is fragile and inconsistent with the rest of the codebase.

**Recommendation:** Replace `$chaine`-based concatenation with the parameterized pattern already used in `combat.php` lines 579-590:
```php
$setClauses = [];
$setTypes = '';
$setParams = [];
foreach ($nomsRes as $num => $ressource) {
    $setClauses[] = "$ressource=?";
    $setTypes .= 'd';
    $setParams[] = $ressources[$ressource] - intval($_POST[$ressource . 'Envoyee']);
}
$setParams[] = $_SESSION['login'];
$setTypes .= 's';
dbExecute($base, 'UPDATE ressources SET energie=?,' . implode(',', $setClauses) . ' WHERE login=?', 'd' . $setTypes, $newEnergie, ...$setParams);
```

---

### HIGH-03 — `constructions.php:traitementConstructions()` lines 240-263 — Same raw concatenation pattern as HIGH-02

**File:** `constructions.php`
**Lines:** 240-263

**Pattern:**
```php
foreach ($nomsRes as $num => $ressource) {
    $chaine = $chaine . $ressource . '=' . ($ressources[$ressource] - $liste['cout' . ucfirst($ressource)]) . $plus;
}
$sql2 = 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?';
$stmt = mysqli_prepare($base, $sql2);
```

**Analysis:**
Same structural issue as HIGH-02. Column names come from `$nomsRes` (server-side), but values are derived from server-side `$ressources` and the building cost config — not user input. However, the `$liste` array is populated from a server-side config array (`$listeConstructions`), so the risk is internal code corruption rather than external injection. The pattern is still inconsistent with the parameterized approach used elsewhere and makes the codebase harder to audit.

**Impact:** Low exploitability in current form, but constitutes a code quality and audit risk. Flagged HIGH because inconsistency can mask genuine future vulnerabilities.

**Recommendation:** Replace with parameterized `dbExecute` pattern as in `combat.php`.

---

### HIGH-04 — `game_actions.php` lines 522-538 — Resource envoi UPDATE builds column list via string concatenation

**File:** `includes/game_actions.php`
**Lines:** 528-538

**Pattern:**
```php
$envoiSetClauses = ['energie=?'];
$envoiTypes = 'd';
$envoiParams = [min(...)];
foreach ($nomsRes as $num => $ressource) {
    $envoiSetClauses[] = "$ressource=?";
    // ...
}
dbExecute($base, 'UPDATE ressources SET ' . implode(',', $envoiSetClauses) . ' WHERE login=?', $envoiTypes, ...$envoiParams);
```

**Analysis:**
This code path (resource delivery on `actionsenvoi` completion) correctly uses parameterized values, but still interpolates column names from `$nomsRes` directly into the SQL string without a whitelist check at the use site. The same issue as CRIT-02 but in a different code path. This is the correctly-migrated version (uses `implode` and parameterized values) but lacks the defensive whitelist check.

**Impact:** Same residual risk as CRIT-02 if `$nomsRes` is ever corrupted.

**Recommendation:** Add the same whitelist guard before adding each column name to `$envoiSetClauses`.

---

### HIGH-05 — `combat.php` lines 579-612 — Dynamic resource UPDATE column list interpolation without whitelist

**File:** `includes/combat.php`
**Lines:** 579-612

**Pattern:**
```php
foreach ($nomsRes as $num => $ressource) {
    $setClauses[] = "$ressource=?";
    // ...
}
$sql = 'UPDATE ressources SET ' . implode(',', $setClauses) . ' WHERE login=?';
dbExecute($base, $sql, ...);
```

**Analysis:**
This pattern appears twice in `combat.php` (once for attacker resources, once for defender resources). Column names from `$nomsRes` are interpolated without whitelist validation. This is the template pattern that other files should follow for values — but all sites lack the column-name whitelist guard that only `db_helpers.php:ajouter()` implements.

**Recommendation:** Apply consistent whitelist check across all sites.

---

## MEDIUM Findings

---

### MED-01 — `update.php:updateTargetResources()` — Non-atomic read-then-write without locking

**File:** `includes/update.php`
**Lines:** 17-43

**Pattern:**
```php
$adversaire = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', ...);
$nbsecondesAdverse = time() - $adversaire['tempsPrecedent'];
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', ...);
// then reads energie and updates it
```

**Analysis:**
Unlike `updateRessources()` in `game_resources.php` which uses an optimistic concurrency check (`AND tempsPrecedent=?` in the UPDATE, checks `affected_rows`), `updateTargetResources()` in `update.php` does a plain unconditional UPDATE of `tempsPrecedent` with no CAS guard. Two concurrent attack resolutions for the same defender could both read the same `tempsPrecedent`, compute the same `nbsecondesAdverse`, and double-credit the defender's resources.

**Impact:** Defenders could receive double resource production credit during concurrent attack resolution, which is an economic exploit. Difficult to trigger in practice with low player counts but a correctness issue.

**Recommendation:** Apply the same CAS (compare-and-swap) pattern as `game_resources.php:updateRessources()`:
```php
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?',
    'isi', time(), $targetPlayer, $adversaire['tempsPrecedent']);
if (mysqli_affected_rows($base) === 0) {
    return; // Another process already updated
}
```

---

### MED-02 — `voter.php` lines 40-49 — Race condition on vote insert/update

**File:** `voter.php`
**Lines:** 32-50

**Pattern:**
```php
$existing = dbFetchOne($base, 'SELECT count(*) AS nb FROM reponses WHERE login = ? AND sondage = ?', ...);
if ($existing['nb'] == 0) {
    dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, ?, ?)', ...);
} else {
    // update
    dbExecute($base, 'UPDATE reponses SET reponse = ? WHERE login = ? AND sondage = ?', ...);
}
```

**Analysis:**
The check-then-act pattern is unprotected. Two concurrent requests from the same user can both pass the `nb == 0` check and both execute `INSERT`, causing a duplicate vote. MariaDB unique constraints on `(login, sondage)` would prevent the second INSERT from succeeding, but the error would surface as an uncaught exception since the code does not handle it. The `reponses` table here is used for both forum replies and votes — it is unclear whether a unique constraint exists on `(login, sondage)`.

**Impact:** Double-vote exploit or unhandled DB error under race conditions.

**Recommendation:** Use `INSERT ... ON DUPLICATE KEY UPDATE` or wrap in a transaction with a `FOR UPDATE` lock.

---

### MED-03 — `game_actions.php` lines 94-103 — Molecule decay N+1 query pattern in combat travel

**File:** `includes/game_actions.php`
**Lines:** 90-103

**Pattern:**
```php
$ex3 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);
$compteur = 1;
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    // ...
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['attaquant']);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', ...);
    $compteur++;
}
```

**Analysis:**
For each molecule class (up to 4), a SELECT and UPDATE are issued against `autre` to read and increment `moleculesPerdues`. This is a classic N+1 pattern: 4 classes = 4 SELECT + 4 UPDATE = 8 queries where 1 accumulated UPDATE would suffice.

The same pattern appears again at lines 456-462 (return journey decay).

**Impact:** Performance. With 4 classes this is 8 redundant queries per combat. At scale (many simultaneous actions), this adds measurable lock contention on the `autre` table.

**Recommendation:** Accumulate the total decay outside the loop and issue a single atomic UPDATE:
```php
$totalDecay = 0;
while (...) {
    $totalDecay += $molecules[$compteur - 1] - $moleculesRestantes;
    $compteur++;
}
if ($totalDecay > 0) {
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $totalDecay, $actions['attaquant']);
}
```

---

### MED-04 — `game_resources.php:updateRessources()` — N+1 query pattern for molecule decay

**File:** `includes/game_resources.php`
**Lines:** 169-183

**Pattern:**
```php
$ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=?', ...);
while ($molecules = mysqli_fetch_array($ex)) {
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', ...);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', ...);
}
```

**Analysis:**
For each of 4 molecule classes: 1 UPDATE (molecules) + 1 SELECT + 1 UPDATE (autre) = 3 queries per row = up to 12 queries. The `moleculesPerdues` accumulation SELECT+UPDATE inside the loop is identical to the N+1 in MED-03 and can be collapsed into a single atomic UPDATE outside the loop.

**Impact:** 12 queries per call to `updateRessources()`. This function is called on every page load for authenticated players, making it the most frequent query hotspot.

**Recommendation:** Accumulate total decay and issue one `UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?` after the loop.

---

### MED-05 — `alliance.php` lines 170-178 — Alliance ranking computed by iterating all alliances in PHP rather than SQL ORDER BY

**File:** `alliance.php`
**Lines:** 170-178

**Pattern:**
```php
$rangQuery = dbQuery($base, 'SELECT tag FROM alliances ORDER BY pointstotaux DESC');
$rang = 1;
while ($rangEx = mysqli_fetch_array($rangQuery)) {
    if ($rangEx['tag'] == $allianceJoueurPage['tag']) {
        break;
    }
    $rang++;
}
```

**Analysis:**
This fetches all alliances, sorted descending by `pointstotaux`, and counts rows in PHP until the target alliance is found. With N alliances this is O(N) PHP iteration + full table fetch when the rank could be computed in a single COUNT query (the same optimisation already applied to `classement.php` for player ranking).

**Impact:** Performance. Grows linearly with number of alliances. Not a SQL injection issue, but a query efficiency issue filed in this audit for completeness.

**Recommendation:**
```php
$rankRow = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM alliances WHERE pointstotaux > ?', 'd', $allianceJoueurPage['pointstotaux']);
$rang = ($rankRow['rank'] ?? 0) + 1;
```

---

### MED-06 — `forum.php` — N+1 per-forum stat queries inside loop

**File:** `forum.php`
**Lines:** 68-88`

**Pattern:**
```php
$ex = dbQuery($base, 'SELECT * FROM forums');
while ($forum = mysqli_fetch_array($ex)) {
    $nbSujets = dbFetchOne($base, 'SELECT count(*) AS nbSujets FROM sujets WHERE idforum = ? AND statut = 0', 'i', $forum['id']);
    if (isset($_SESSION['login'])) {
        $statutForum = dbFetchOne($base, 'SELECT count(*) AS nbLus FROM statutforum WHERE login = ? AND idforum = ?', ...);
    }
    $nbMessages = dbFetchOne($base, 'SELECT count(*) AS cnt FROM sujets s, reponses r WHERE idforum = ? AND s.id = r.idsujet', ...);
}
```

**Analysis:**
For each forum (N forums): up to 3 queries per row. With 8 forums this is up to 24 queries on a page that is shown to every visitor. These could be collapsed into JOINs or GROUP BY aggregations.

The `FROM sujets s, reponses r WHERE s.id = r.idsujet` implicit join at line 85 also lacks an `INNER JOIN` keyword — it is a cross-join filtered by WHERE, which is correct but not readable and may confuse the query planner in older MariaDB versions.

**Impact:** Performance on a high-traffic page.

---

### MED-07 — `listesujets.php` line 90 — Per-row `statutforum` lookup inside subject listing loop

**File:** `listesujets.php`
**Lines:** 86-112

**Pattern:**
```php
while ($sujet = mysqli_fetch_array($ex1)) {
    if (isset($_SESSION['login'])) {
        $statutForum = dbFetchOne($base, 'SELECT count(*) AS luOuPas FROM statutforum WHERE idsujet = ? AND login = ?', 'is', $sujet['id'], $_SESSION['login']);
    }
}
```

**Analysis:**
For each subject listed per page (up to 10), a query is issued to `statutforum` to determine read status. This is an N+1 pattern. With 10 subjects per page, this is 10 additional queries per page load, per authenticated user.

**Recommendation:** Pre-load all `statutforum` entries for the subject IDs on the current page in a single `WHERE idsujet IN (...)` query before the loop.

---

### MED-08 — `allianceadmin.php` — Multiple repeated `SELECT login FROM autre WHERE idalliance=?` queries

**File:** `allianceadmin.php`
**Lines:** 354, 365, 398`

**Pattern:**
```php
$ex2 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
// ... build options ...
$ex2 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']); // again
// ... build options again ...
$ex2 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']); // third time
```

**Analysis:**
The same query is executed three times in the display section to populate member dropdowns. The result set should be fetched once and reused.

**Impact:** Unnecessary DB round-trips on the alliance admin page.

---

### MED-09 — `sujet.php` — Per-message moderator status and player image queries in loop

**File:** `sujet.php`
**Lines:** 151-188

**Pattern:**
```php
while ($reponse = mysqli_fetch_array($ex1)) {
    $image = dbFetchOne($base, 'SELECT image, count(image) as nb FROM autre WHERE login = ?', 's', $reponse['auteur']);
    if (isset($_SESSION['login'])) {
        $donnees4 = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
    }
}
```

**Analysis:**
The moderator status check (`SELECT moderateur FROM membre WHERE login = ?`) is issued once per message inside the loop, but the result never changes — it always queries the *current* user's moderator status. This should be fetched once outside the loop.

The `$image` query per message author is also an N+1 pattern (up to 10 queries per page).

**Impact:** Up to 20 extra queries per page. For the moderator query it is also logically incorrect (fetching the same row repeatedly).

---

## LOW Findings

---

### LOW-01 — `marche.php` line 578 — Unparameterized `ORDER BY timestamp DESC LIMIT 1000` on `cours` table

**File:** `marche.php`
**Line:** 578

**Pattern:**
```php
$ex = dbQuery($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1000");
```

**Analysis:**
While this query is entirely hardcoded (no user input), it fetches up to 1000 rows for chart rendering. The `timestamp` column should have an index if not already present. No injection risk, but a performance concern on a growing `cours` table.

---

### LOW-02 — `marche.php` line 170 — `SELECT ... FOR UPDATE` column name interpolation inside transaction

**File:** `marche.php`
**Line:** 170

**Pattern:**
```php
$locked = dbFetchOne($base, 'SELECT energie, ' . $nomsRes[$numRes] . ' AS res FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
```

**Analysis:**
`$nomsRes[$numRes]` is used in the column list of a `FOR UPDATE` SELECT. The value is validated by the `foreach` loop at lines 152-157 that checks `$_POST['typeRessourceAAcheter'] == $ressource` for each known resource name. This is correct and the whitelist is enforced before `$numRes` is set. However, the comment on line 179 says "server-side resource name, not user input" — this is accurate but the defence relies on `$numRes` only being set inside a validated `foreach` branch. This is safe but not immediately obvious. No action required, but noting for code clarity.

---

### LOW-03 — `classement.php` lines 54-56 — `$order` column interpolated into two COUNT subqueries

**File:** `classement.php`
**Lines:** 54-56, 83-85

**Pattern:**
```php
$playerScore = dbFetchOne($base, 'SELECT ' . $order . ' AS score FROM autre WHERE login=?', 's', $searchLogin);
$rankRow = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM autre WHERE ' . $order . ' > ?', 'd', $playerScore['score']);
```

**Analysis:**
`$order` is set by a `switch($_GET['clas'])` statement at lines 19-43 with a fixed set of known column names and a default fallback. This is correctly whitelisted. No injection risk. Noted here for completeness and to confirm the whitelist approach was reviewed.

---

### LOW-04 — `alliance.php` lines 372, 575 — `$order` and `$table` interpolation for forum ranking

**File:** `classement.php`
**Lines:** 530-575

**Pattern:**
```php
$ex = dbQuery($base, 'SELECT login FROM ' . $table . ' ORDER BY ' . $order . ' DESC LIMIT ?, ?', 'ii', ...);
```

**Analysis:**
Both `$table` and `$order` are set by a whitelist `switch` block at lines 530-549. The table name interpolation (`autre` or `membre`) is safe because both values are hardcoded in the switch. Noted for completeness.

---

### LOW-05 — `sujet.php` line 33 — Forum subject lookup by content value after INSERT

**File:** `listesujets.php`
**Line:** 33

**Pattern:**
```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
$sujet = dbFetchOne($base, 'SELECT id FROM sujets WHERE contenu = ?', 's', $_POST['contenu']);
```

**Analysis:**
After inserting a new forum subject, the code retrieves the new row's ID by querying by `contenu` (the post body). This is fragile: if two users post identical content simultaneously, the wrong ID could be returned. The correct approach is to use `dbLastId($base)` or `mysqli_insert_id($base)` immediately after the INSERT.

**Impact:** Rare race condition where the `statutforum` entry is inserted with the wrong subject ID, causing a read-status tracking error. Low severity but a correctness issue.

**Recommendation:**
```php
$newSubjectId = dbLastId($base);
dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sii', $_SESSION['login'], $newSubjectId, $getId);
```

---

### LOW-06 — `voter.php` line 43 — `INSERT INTO reponses` table name collision with forum replies

**File:** `voter.php`
**Line:** 43

**Pattern:**
```php
$existing = dbFetchOne($base, 'SELECT count(*) AS nb FROM reponses WHERE login = ? AND sondage = ?', 'si', $login, $sondageId);
if ($existing['nb'] == 0) {
    dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, ?, ?)', 'sis', $login, $sondageId, $reponse);
```

**Analysis:**
`voter.php` uses the `reponses` table for survey votes, but `sujet.php` uses the same table for forum reply posts. The two use cases share a table name, which means a query that searches `reponses WHERE login = ?` without a `sondage` filter (or `idsujet` filter) would inadvertently match both types. The schema relies on one column being NULL to distinguish the two use cases. This is a design concern that affects query correctness and safety rather than injection, but warrants a note.

---

## INFO Findings (No Direct Risk, Noted for Completeness)

---

### INFO-01 — `database.php:withTransaction()` — Exception type mismatch

**File:** `includes/database.php`
**Lines:** 111-121

The `withTransaction()` helper catches `Exception` but callers in `don.php` throw `\RuntimeException` and callers in `marche.php` throw plain `new Exception(...)`. All subclass `Exception` so the catch is correct. However, `withTransaction()` re-throws the exception after rollback, meaning callers must also catch it. The callers in `marche.php` do catch `Exception` correctly. `don.php` catches `\RuntimeException` specifically. This is correct but fragile.

---

### INFO-02 — `combat.php` lines 107-109 — Transaction wraps `include()` of `combat.php`

**File:** `includes/game_actions.php`
**Lines:** 107-109

```php
mysqli_begin_transaction($base);
try {
    include("includes/combat.php");
    // ... report generation ...
    mysqli_commit($base);
}
```

The entire combat resolution (reading, computing, writing all combat state) runs within a single transaction started in `game_actions.php`. This is the correct pattern. The transaction covers all the INSERTs and UPDATEs in `combat.php` and the report INSERTs. The `attack_cooldown` INSERT at `combat.php:338` is also inside this transaction, meaning if the transaction rolls back, the cooldown is not recorded. This could allow an attacker to retry an attack that produced an exception — a minor gap but noted.

---

### INFO-03 — `db_helpers.php:ajouter()` — `ALLIANCE_RESEARCH_COLUMNS` constant defined in `db_helpers.php` but not used in `alliance.php`

**File:** `includes/db_helpers.php`
**Line:** 46

The constant `ALLIANCE_RESEARCH_COLUMNS` is defined and used consistently within `db_helpers.php` functions. The intent is to provide a hard-coded defence-in-depth backstop. This same constant should be used in `alliance.php` for all alliance research column name validation (see HIGH-01). Currently the constant is available but alliance.php does not import it via the module load path.

---

### INFO-04 — `game_resources.php:updateRessources()` uses `mysqli_affected_rows($base)` directly after `dbExecute()`

**File:** `includes/game_resources.php`
**Line:** 121

```php
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', ...);
if (mysqli_affected_rows($base) === 0) {
    return;
}
```

`dbExecute()` closes the prepared statement before returning. `mysqli_affected_rows()` called on the connection after statement close should still return the correct count for the most recently executed statement in MariaDB 10.11. This is correct behaviour but relies on MariaDB implementation details. A cleaner approach would be to have `dbExecute()` return the affected row count (it does, at line 72) and check the return value:

```php
$affected = dbExecute($base, 'UPDATE autre ...', ...);
if ($affected === 0 || $affected === false) {
    return;
}
```

This pattern would be more portable and clearer.

---

## Consolidated Risk Matrix

| ID | File | Lines | Category | Severity | Exploitability | Remediation Effort |
|----|------|-------|----------|----------|----------------|-------------------|
| CRIT-01 | includes/update.php | 36, 42 | Raw column interpolation | CRITICAL | Low (requires $nomsRes corruption) | Low (add whitelist) |
| CRIT-02 | includes/game_resources.php | 152-158 | Raw column interpolation | CRITICAL | Low (requires $nomsRes corruption) | Low (add whitelist) |
| HIGH-01 | alliance.php | 94-113 | User-supplied column name in SQL | HIGH | Medium (POST value → column name) | Low (add constant check) |
| HIGH-02 | marche.php | 100-115 | Dynamic UPDATE concatenation | HIGH | Low (intval-cast values) | Medium (refactor to parameterized) |
| HIGH-03 | constructions.php | 240-263 | Dynamic UPDATE concatenation | HIGH | Very Low (config values only) | Medium (refactor to parameterized) |
| HIGH-04 | includes/game_actions.php | 528-538 | Column interpolation without whitelist | HIGH | Low (requires $nomsRes corruption) | Low (add whitelist) |
| HIGH-05 | includes/combat.php | 579-612 | Column interpolation without whitelist | HIGH | Low (requires $nomsRes corruption) | Low (add whitelist) |
| MED-01 | includes/update.php | 17-43 | Non-atomic read-then-write | MEDIUM | Medium (concurrent requests) | Low (add CAS guard) |
| MED-02 | voter.php | 32-50 | Race condition on vote | MEDIUM | Low (race timing) | Low (INSERT ON DUPLICATE KEY) |
| MED-03 | includes/game_actions.php | 90-103 | N+1 in combat decay loop | MEDIUM | N/A (performance) | Low |
| MED-04 | includes/game_resources.php | 169-183 | N+1 in resource decay loop | MEDIUM | N/A (performance, high frequency) | Low |
| MED-05 | alliance.php | 170-178 | Full table scan for rank | MEDIUM | N/A (performance) | Low |
| MED-06 | forum.php | 68-88 | N+1 per-forum stats | MEDIUM | N/A (performance) | Medium |
| MED-07 | listesujets.php | 86-112 | N+1 read-status queries | MEDIUM | N/A (performance) | Low |
| MED-08 | allianceadmin.php | 354, 365, 398 | Repeated identical queries | MEDIUM | N/A (performance) | Low |
| MED-09 | sujet.php | 151-188 | N+1 per-message queries | MEDIUM | N/A (performance) | Low |
| LOW-01 | marche.php | 578 | Missing index hint (performance) | LOW | N/A | Low |
| LOW-02 | marche.php | 170 | FOR UPDATE column interpolation (safe) | LOW | None | None |
| LOW-03 | classement.php | 54-56 | Whitelisted ORDER BY (safe) | LOW | None | None |
| LOW-04 | classement.php | 530-575 | Whitelisted table/order (safe) | LOW | None | None |
| LOW-05 | listesujets.php | 33 | Post-INSERT ID retrieval by content | LOW | Low (race condition) | Very Low |
| LOW-06 | voter.php | 43 | Table sharing design concern | LOW | None | Design decision |

---

## Prioritised Remediation Plan

### Immediate (Block Deploy if Not Addressed)

1. **CRIT-01 / CRIT-02 / HIGH-04 / HIGH-05**: Add `in_array($ressource, $allowedResColumns, true)` whitelist check before all `$nomsRes` column interpolation points. This is a 4-line change at each site and completely closes the attack surface.

2. **HIGH-01**: Add `in_array($techName, ALLIANCE_RESEARCH_COLUMNS, true)` check in `alliance.php` before line 98 and before line 108.

### Short Term (Next Sprint)

3. **HIGH-02 / HIGH-03**: Migrate the remaining raw-concatenation UPDATE patterns in `marche.php` (envoi path) and `constructions.php:traitementConstructions()` to parameterized `dbExecute` with `implode(',', $setClauses)`.

4. **MED-01**: Apply CAS guard to `updateTargetResources()` in `update.php`.

5. **MED-02**: Replace check-then-act in `voter.php` with `INSERT ... ON DUPLICATE KEY UPDATE`.

6. **LOW-05**: Use `dbLastId($base)` in `listesujets.php` after forum subject INSERT.

### Performance (Can Be Batched)

7. **MED-03 / MED-04**: Collapse `moleculesPerdues` accumulation loops into single atomic UPDATE.

8. **MED-05**: Replace PHP-loop alliance ranking with COUNT query.

9. **MED-06 / MED-07 / MED-08 / MED-09**: Batch per-row queries with pre-loads or GROUP BY aggregations.

---

*End of SQL-CROSS audit report.*
