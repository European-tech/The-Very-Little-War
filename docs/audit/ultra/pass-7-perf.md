# Pass 7 Performance Audit

**Scope:** N+1 query patterns, missing indexes, slow queries, SELECT * on large tables, ORDER BY on non-indexed columns.

**Already fixed (excluded from scope):** revenuAtome shared cache, classement.php N+1 fixed, initPlayer cache, login_history composite index (0072).

---

## Findings

### PERF-P7-001 [MEDIUM] — N+1 in attaquer.php attack form JS generation

**File:** `attaquer.php` lines 567–591
**Proof:**
```php
for ($i = 1; $i <= $nbClasses; $i++) {
    $molecules1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $i);
    // ...
}
```
The loop runs once per molecule class (4 iterations in the standard game). Each iteration fires an independent `SELECT *` query to fetch atom totals for JS energy-cost calculation. The very same rows were already fully loaded two queries earlier into `$moleculesJsRows` (line 552). The data is already in memory; this loop discards it and re-queries the database four times per page load of the attack form.

**Impact:** 4 redundant round-trips per visit to `attaquer.php?type=1`. In a game with concurrent players, this degrades connection throughput needlessly.

**Fix:** Replace the inner `dbFetchOne` with a lookup into the already-loaded `$moleculesJsRows` array, keyed by `numeroclasse`.
```php
// Build a lookup from already-fetched data
$moleculeByClass = [];
foreach ($moleculesJsRows as $m) {
    $moleculeByClass[(int)$m['numeroclasse']] = $m;
}

for ($i = 1; $i <= $nbClasses; $i++) {
    $molecules1 = $moleculeByClass[$i] ?? null;
    $totAtomes = 0;
    if ($molecules1) {
        foreach ($nomsRes as $num => $res) {
            $totAtomes += $molecules1[$res];
        }
    }
    // ... rest of JS echo
}
```
Zero DB queries; uses data already in `$moleculesJsRows`.

---

### PERF-P7-002 [MEDIUM] — Duplicate `idalliance` fetches in combat.php

**File:** `includes/combat.php` lines 58–69 and 781–782
**Proof:**
```php
// Lines 58-62 (attacker alliance for duplicateur)
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);

// Lines 65-69 (defender alliance for duplicateur)
$idallianceDef = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);

// ...  hundreds of lines later ...

// Lines 781-782 (re-fetched for war-loss recording)
$joueur = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$idallianceAutre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
```
The `idalliance` for both combatants is fetched at the top of the file (lines 58, 65) for the duplicateur bonus calculation. At line 781, exactly the same two queries are repeated to populate `$joueurAlliance` and `$autreAlliance` for war-loss recording. The results of the first two fetches are available in `$idalliance` and `$idallianceDef` for the rest of the file scope.

**Impact:** 2 redundant DB round-trips per combat resolution. Since combat runs inside a transaction, these extra queries add unnecessary latency under lock.

**Fix:** Reuse the existing variables:
```php
// Replace lines 781-782 with:
$joueurAlliance  = ($idalliance    && isset($idalliance['idalliance']))    ? $idalliance['idalliance']    : 0;
$autreAlliance   = ($idallianceDef && isset($idallianceDef['idalliance'])) ? $idallianceDef['idalliance'] : 0;
```

---

### PERF-P7-003 [MEDIUM] — Missing composite index on `rapports(destinataire, timestamp)`

**File:** `rapports.php` line 99; index gap in migrations
**Proof:**
```sql
SELECT * FROM rapports WHERE destinataire = ? ORDER BY timestamp DESC LIMIT ?, ?
```
The existing indexes on `rapports` are:
- `idx_rapports_destinataire (destinataire)` — from migration 0001
- `idx_dest_statut (destinataire, statut)` — from migration 0066

Neither covers the sort column `timestamp`. MariaDB can use the `destinataire` index for the WHERE clause but must perform a filesort for `ORDER BY timestamp DESC`. As the `rapports` table grows (each combat produces 2 rows), this filesort cost increases. The query is executed on every visit to the reports list.

**Fix:** Add a migration for a composite index that covers filter + sort:
```sql
-- migrations/0073_rapports_timestamp_index.sql
ALTER TABLE rapports
  ADD INDEX IF NOT EXISTS idx_dest_timestamp (destinataire(191), timestamp);
```
With this index, the query becomes an index range scan with no filesort.

---

### PERF-P7-004 [MEDIUM] — Missing composite index on `sujets(idforum, statut, timestamp)`

**File:** `listesujets.php` line 123; index gap in migrations
**Proof:**
```sql
SELECT * FROM sujets WHERE idforum = ? ORDER BY statut, timestamp DESC LIMIT ?, ?
```
The existing index is:
- `idx_sujets_forum (idforum)` — from migration 0001

The sort is `ORDER BY statut, timestamp DESC` — two columns not covered by the index. MariaDB filters by `idforum` then performs a filesort on the result set. For active forums with many topics, this filesort grows with topic count.

**Fix:**
```sql
-- migrations/0074_sujets_statut_timestamp_index.sql
ALTER TABLE sujets
  ADD INDEX IF NOT EXISTS idx_sujets_forum_statut_ts (idforum, statut, timestamp);
```

---

### PERF-P7-005 [LOW] — `actionsattaques` OR-condition prevents efficient index use in game_actions.php

**File:** `includes/game_actions.php` line 91; `attaquer.php` line 290
**Proof:**
```sql
-- game_actions.php:91
SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque DESC

-- attaquer.php:290
SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque ASC
```
Both `attaquant` and `defenseur` have composite indexes (`idx_attaques_attaquant_temps`, `idx_attaques_defenseur_temps` from migration 0014). However, the `OR` condition forces MariaDB into an index-merge strategy (two index scans + merge) rather than a single efficient seek. The `actionsenvoi` table already received a UNION ALL fix (game_actions.php lines 526–527) for the identical pattern. `actionsattaques` was not similarly updated.

**Note:** At typical game scale (small player counts), the OR + index merge is acceptable. This becomes significant at ~500+ active actions.

**Fix:** Apply the same UNION ALL pattern used for `actionsenvoi`:
```php
// game_actions.php replacement for line 91:
$rowsAtt = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE attaquant=? ORDER BY tempsAttaque DESC', 's', $joueur);
$rowsDef = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE defenseur=? ORDER BY tempsAttaque DESC', 's', $joueur);
$seenActIds = [];
$rows = [];
foreach (array_merge($rowsAtt, $rowsDef) as $r) {
    if (!isset($seenActIds[$r['id']])) {
        $seenActIds[$r['id']] = true;
        $rows[] = $r;
    }
}
usort($rows, fn($a, $b) => $b['tempsAttaque'] <=> $a['tempsAttaque']);
```

---

### PERF-P7-006 [LOW] — Redundant separate queries in `joueur.php` for the same `autre` row

**File:** `joueur.php` lines 22 and 24
**Proof:**
```php
$donnees1 = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $membre['login']); // line 22
$donnees3 = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $membre['login']); // line 24
```
Line 24 fetches `idalliance` from `autre` with a second query. Since line 22 already does `SELECT *` on the same row, `$donnees1['idalliance']` is already available. Additionally, line 45 does a third query (`SELECT totalPoints FROM autre WHERE login=?`) when `$donnees1['totalPoints']` is already in memory.

**Impact:** 2 unnecessary round-trips per profile page view.

**Fix:** Remove lines 24 and 45, replace references:
```php
// Remove: $donnees3 = dbFetchOne(...);
// Use:    $donnees3 = $donnees1; // idalliance is in $donnees1
// Remove: $playerPoints = dbFetchOne(...);
// Use:    $totalPoints = (int)($donnees1['totalPoints'] ?? 0);
```

---

### PERF-P7-007 [LOW] — `SELECT *` on `marche.php` `cours` fetched repeatedly

**File:** `marche.php` lines 10, 311, 460
**Proof:**
```php
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
```
The `cours` table has only 3 columns (`id`, `tableauCours`, `timestamp`), so `SELECT *` is harmless. However, the same latest-row query is executed up to 3 times per page load (unconditionally at line 10, and again after market buy/sell actions at lines 311 and 460). The result at line 10 could be cached in a variable and reused.

**Impact:** Minimal at current scale (cours has index on timestamp). Listed for completeness.

**Fix:** Cache the result of line 10 in `$val` and reuse it where the market state has not changed, or re-query only after a successful buy/sell that modifies `cours`.

---

### PERF-P7-008 [INFO] — `attaquer.php` map loads ALL player rows on every render

**File:** `attaquer.php` line 402
**Proof:**
```php
$allPlayers = dbFetchAll($base, 'SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login', '');
```
This query loads every active player's position and points to render the map grid. With 500 players the result set is ~500 rows × 6 columns — small and acceptable. At very large player counts (1 000+), this becomes a full-table join on every map page view. The column selection is already specific (not `SELECT *`), which is good.

**Note:** No fix required at current scale. If player count grows significantly, consider a spatial index or server-side tile caching. Flagged INFO for future awareness.

---

## Summary

| ID | Severity | Location | Issue |
|---|---|---|---|
| PERF-P7-001 | MEDIUM | `attaquer.php:583` | N+1: 4 per-class DB queries inside JS-generation loop; data already in `$moleculesJsRows` |
| PERF-P7-002 | MEDIUM | `includes/combat.php:781-782` | 2 duplicate `idalliance` fetches; results available from lines 58/65 |
| PERF-P7-003 | MEDIUM | `rapports.php:99` + migrations | Missing `rapports(destinataire, timestamp)` composite index; filesort on every reports list |
| PERF-P7-004 | MEDIUM | `listesujets.php:123` + migrations | Missing `sujets(idforum, statut, timestamp)` composite index; filesort on forum topic list |
| PERF-P7-005 | LOW | `game_actions.php:91`, `attaquer.php:290` | OR condition on `actionsattaques` prevents single-index seek; UNION ALL already applied to `actionsenvoi` but not here |
| PERF-P7-006 | LOW | `joueur.php:22,24,45` | 2 extra queries on `autre` table; data already in `$donnees1` from the `SELECT *` |
| PERF-P7-007 | LOW | `marche.php:10,311,460` | Same latest `cours` row fetched up to 3× per page; safe to cache |
| PERF-P7-008 | INFO | `attaquer.php:402` | Full player-table JOIN on every map render; acceptable now, monitor at scale |

**Total:** 4 MEDIUM, 3 LOW, 1 INFO. No HIGH findings. `classement.php` and `includes/combat.php` main query structure are clean; all ranking loops use pre-loaded caches correctly.
