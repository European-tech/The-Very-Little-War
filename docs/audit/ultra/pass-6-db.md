# Pass 6 — Database Audit

**Date:** 2026-03-08
**Scope:** Database correctness, N+1 queries, missing indexes, transaction gaps, schema issues, data integrity, race conditions, unbounded queries
**Method:** Static analysis of PHP source + schema SQL; all findings are new (not in passes 1–5)

---

## DB-P6-001 — CRITICAL: `combat.php` queries non-existent `jeu` table for CATCHUP_WEEKEND

**Severity:** HIGH
**File:** `includes/combat.php` ~line 689
**Status:** NEW

### Description

The `CATCHUP_WEEKEND_ENABLED` combat points multiplier reads the season start timestamp from a table named `jeu`. No such table exists in the schema. The correct table is `statistiques`, which has a `debut` column. Because `dbFetchOne()` on a non-existent table returns `null`, `$jeuData` is always `null`, so `$seasonStart` is set to `time()`, `$seasonDay` always equals `0`, and the weekend multiplier never fires.

### Code Snippet

```php
// combat.php ~line 689
$jeuData = dbFetchOne($base, 'SELECT debut FROM jeu LIMIT 1', '');
$seasonStart = $jeuData ? (int)$jeuData['debut'] : time();
$seasonDay   = (int)floor((time() - $seasonStart) / SECONDS_PER_DAY);
```

### Fix

```php
$jeuData     = dbFetchOne($base, 'SELECT debut FROM statistiques', '');
$seasonStart = $jeuData ? (int)$jeuData['debut'] : time();
$seasonDay   = (int)floor((time() - $seasonStart) / SECONDS_PER_DAY);
```

---

## DB-P6-002 — HIGH: `declarations.pertesTotales` never updated; season war archive always empty

**Severity:** HIGH
**File:** `includes/player.php` ~line 1064
**Status:** NEW

### Description

`performSeasonEnd()` Phase 1a ranks ended wars by `pertesTotales` to produce the season's war archive:

```php
$guerreClassement = dbFetchAll($base,
    'SELECT * FROM declarations WHERE pertesTotales != 0 AND type = 0 AND fin != 0 ORDER BY pertesTotales DESC LIMIT 0, ' . SEASON_ARCHIVE_TOP_N,
    ''
);
```

However, `pertesTotales` is never written anywhere in the PHP codebase. Combat only updates `pertes1` and `pertes2` individually. The column remains `0` for every war, so the `WHERE pertesTotales != 0` predicate matches nothing and the season war archive is always empty.

### Fix (two options)

**Option A — computed column (preferred, no PHP change required):**

```sql
-- In a new migration:
ALTER TABLE declarations
    MODIFY COLUMN pertesTotales INT GENERATED ALWAYS AS (pertes1 + pertes2) STORED;
```

**Option B — update at write time in combat.php:**

Wherever `pertes1` or `pertes2` is incremented in `combat.php`, also do:
```php
dbExecute($base,
    'UPDATE declarations SET pertesTotales = pertes1 + pertes2 WHERE id = ?',
    'i', $declarationId
);
```

The query in `performSeasonEnd()` then needs no change.

---

## DB-P6-003 — MEDIUM: N+1 redundant queries in `revenuAtome()` — 3 queries × 8 atom types per player

**Severity:** MEDIUM
**File:** `includes/game_resources.php` lines 106, 109–111, 115–118
**Status:** NEW

### Description

`revenuAtome()` is called 8 times per player (once per atom type). The static cache key includes the atom number (`$joueur . '-' . $num`), so the atom-level result is cached, but the shared sub-queries within each call are not:

- `SELECT idalliance FROM autre WHERE login=?` — executed once per atom type (8× per player)
- `SELECT duplicateur FROM alliances WHERE id=?` — executed once per atom type when in alliance (8×)
- `SELECT x, y FROM membre WHERE login=?` — executed once per atom type (8×)

On a cold cache for a player in an alliance, this generates 24 redundant queries for data that is constant across all 8 atoms.

`revenuAtomeJavascript()` (line 147) fixes this correctly by hoisting all shared queries before the per-type loop, but `revenuAtome()` does not.

### Code Snippet

```php
// game_resources.php lines 106–119 (inside per-atom-type function, called 8×)
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
$bonusDuplicateur = 1;
if ($idalliance['idalliance'] > 0) {
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
    $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
}
$pos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', 's', $joueur);
```

### Fix

Add a `$joueur`-level static cache (keyed only on `$joueur`) for the shared query results, populated once and reused for all 8 atoms:

```php
static $sharedCache = [];
if (!isset($sharedCache[$joueur])) {
    $idallianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    $bonusDup = 1;
    if ($idallianceRow['idalliance'] > 0) {
        $dupRow = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idallianceRow['idalliance']);
        $bonusDup = 1 + bonusDuplicateur($dupRow['duplicateur']);
    }
    $pos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', 's', $joueur);
    $sharedCache[$joueur] = ['bonusDuplicateur' => $bonusDup, 'pos' => $pos];
}
$bonusDuplicateur = $sharedCache[$joueur]['bonusDuplicateur'];
$pos              = $sharedCache[$joueur]['pos'];
```

---

## DB-P6-004 — MEDIUM: N+1 queries inside `performSeasonEnd()` Phase 1a transaction

**Severity:** MEDIUM
**File:** `includes/player.php` lines 1026–1068
**Status:** NEW

### Description

The season-end function loops over all players and all wars inside a single transaction. Within those loops, each iteration issues individual queries:

- Per player: `SELECT nombre FROM molecules WHERE proprietaire = ?`
- Per player (in alliance): `SELECT tag, id FROM alliances WHERE id = ?`
- Per alliance member: `SELECT login FROM autre WHERE idalliance = ?`
- Per war: `SELECT tag FROM alliances WHERE id = ?` (×2 — attacker and defender)

For a game with 100 players and 20 active wars, this can issue 200+ queries inside a single serialized transaction. On MariaDB with InnoDB row locks, keeping a transaction open for hundreds of round-trips increases lock contention for all live players.

### Fix

Pre-fetch all required data in batch queries before the transaction begins:

```php
// Before the withTransaction() call:
$allMolecules  = dbFetchAll($base, 'SELECT proprietaire, SUM(nombre) AS total FROM molecules GROUP BY proprietaire', '');
$allAlliances  = dbFetchAll($base, 'SELECT id, tag FROM alliances', '');
$allianceIndex = array_column($allAlliances, null, 'id');
```

Then use the pre-fetched arrays inside the loop rather than per-row queries.

---

## DB-P6-005 — MEDIUM: Missing composite index for `login_history` self-join in `multiaccount.php`

**Severity:** MEDIUM
**File:** `includes/multiaccount.php` line 124; `migrations/0020_create_login_history.sql`
**Status:** NEW

### Description

The shared-IP detection query uses a self-join on `login_history`:

```sql
SELECT COUNT(*) AS cnt
FROM login_history a
INNER JOIN login_history b ON a.ip = b.ip
WHERE a.login = ? AND b.login = ? AND a.timestamp > ?
```

The migration creates four single-column indexes (`login`, `ip`, `fingerprint`, `timestamp`). The optimizer can use at most one of these per table alias in the self-join, leaving the other alias doing a full scan filtered by `WHERE`. For a table with many rows, this degrades to O(n²). The join pattern requires a composite index `(ip, login, timestamp)` so MariaDB can satisfy the `ON a.ip = b.ip` join and the `WHERE a.login = ?` / `a.timestamp > ?` conditions from a single index range scan.

### Fix

New migration:

```sql
-- migrations/0026_add_login_history_composite_idx.sql
ALTER TABLE login_history
    ADD INDEX idx_lh_ip_login_ts (ip, login, timestamp);
```

---

## DB-P6-006 — LOW: `mysqli_affected_rows()` called directly instead of using `dbExecute()` return value

**Severity:** LOW
**File:** `includes/player.php` ~line 153; `includes/game_resources.php` ~line 202
**Status:** NEW

### Description

Two CAS (compare-and-swap) guards use `mysqli_affected_rows($base)` immediately after `dbExecute()` rather than the return value of `dbExecute()` itself:

```php
// player.php ~line 152
$result = dbExecute($base, 'UPDATE autre SET points = points + ? WHERE login = ? AND points + ? >= 0', 'dsd', $nb, $joueur, $nb);
if (mysqli_affected_rows($base) > 0) { ...

// game_resources.php ~line 201
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
if (mysqli_affected_rows($base) === 0) { return; }
```

`dbExecute()` already returns the affected-row count (or the result of `mysqli_affected_rows()` internally). Any query inserted between the `dbExecute()` call and the `mysqli_affected_rows()` check (e.g., by a future developer adding a log call) would silently break the guard.

### Fix

```php
// player.php
$affected = dbExecute($base, ...);
if ($affected > 0) { ...

// game_resources.php
$affected = dbExecute($base, ...);
if ($affected === 0) { return; }
```

---

## DB-P6-007 — INFO: Redundant `autre` table queries per combat for the same player rows

**Severity:** INFO
**File:** `includes/combat.php` lines 58, 65, 73, 74, 653, 654, 777, 778
**Status:** NEW

### Description

A single combat invocation queries the `autre` table multiple times for the same player:

- Lines 58, 65: `SELECT idalliance FROM autre WHERE login=?` for attacker and defender (alliance combat bonus)
- Lines 73–74: `SELECT pointsAttaque, pointsDefense FROM autre WHERE login=?` for both players
- Lines 653–654: `SELECT points, totalPoints FROM autre WHERE login=?` for both players (war loss tracking)
- Lines 777–778: `SELECT idalliance FROM autre WHERE login=?` again for both players (war membership check)

The `autre` table holds one row per player. All these columns (`idalliance`, `pointsAttaque`, `pointsDefense`, `points`, `totalPoints`) can be fetched in a single `SELECT * FROM autre WHERE login=?` query per player at the start of the combat function, and the result reused for the entire function.

### Fix

At the top of the combat function, fetch all needed columns once per player:

```php
$autreAtt = dbFetchOne($base, 'SELECT idalliance, pointsAttaque, pointsDefense, points, totalPoints FROM autre WHERE login=?', 's', $attaquant);
$autreDefense = dbFetchOne($base, 'SELECT idalliance, pointsAttaque, pointsDefense, points, totalPoints FROM autre WHERE login=?', 's', $defenseur);
```

Then replace all downstream per-column queries with references to `$autreAtt` and `$autreDefense`.

---

## Summary

| ID | Severity | Area | Description |
|----|----------|------|-------------|
| DB-P6-001 | HIGH | Schema / Feature | `jeu` table does not exist; `CATCHUP_WEEKEND` multiplier silently never fires |
| DB-P6-002 | HIGH | Data Integrity | `declarations.pertesTotales` never written; season war archive always empty |
| DB-P6-003 | MEDIUM | N+1 | `revenuAtome()` re-queries idalliance, duplicateur, position for each of 8 atom types |
| DB-P6-004 | MEDIUM | N+1 | `performSeasonEnd()` issues per-player/per-war queries inside long-held transaction |
| DB-P6-005 | MEDIUM | Index | `login_history` self-join needs composite `(ip, login, timestamp)` index |
| DB-P6-006 | LOW | Fragility | `mysqli_affected_rows()` called directly instead of using `dbExecute()` return value |
| DB-P6-007 | INFO | Performance | 6–8 separate `autre` queries per combat could be 2 (one per player at function start) |

**Total:** 2 HIGH, 3 MEDIUM, 1 LOW, 1 INFO

### Priority Fix Order

1. **DB-P6-001** — Single-line fix; restores a silently broken game feature
2. **DB-P6-002** — Either a migration (generated column) or a two-line PHP fix; restores season war history
3. **DB-P6-005** — Single-migration index addition; prevents table-scan self-join in anti-cheat detection
4. **DB-P6-003** — Medium refactor; eliminates up to 24 redundant queries per resource update tick
5. **DB-P6-006** — Trivial refactor; hardens two CAS guards against future regressions
6. **DB-P6-004** — Larger refactor (pre-fetch batch queries); reduces lock contention during season reset
7. **DB-P6-007** — Cleanup; no functional impact, reduces round-trips per combat by ~6
