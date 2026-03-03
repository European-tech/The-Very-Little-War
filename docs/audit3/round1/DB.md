# Database Security Audit -- Round 1

**Auditor**: Claude Opus 4.6 (Security Auditor)
**Date**: 2026-03-03
**Scope**: PHP 8.2 + MariaDB 10.11 game codebase -- all PHP files + migrations
**Format**: `[DB-R1-NNN] [SEVERITY] file.php:line -- Description`

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 14    |
| MEDIUM   | 16    |
| LOW      | 10    |
| **Total**| **42**|

---

## CRITICAL

### [DB-R1-001] CRITICAL update.php:21 -- Missing CAS guard on tempsPrecedent allows double resource crediting

`updateTargetResources()` updates `tempsPrecedent` without the Compare-And-Swap (CAS) guard that `updateRessources()` correctly implements. Compare:

- **update.php:21** (vulnerable):
  ```php
  dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $targetPlayer);
  ```
- **game_resources.php:120** (correct):
  ```php
  dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
  if (mysqli_affected_rows($base) === 0) return;
  ```

If two concurrent requests (e.g., two attacks hitting the same defender) call `updateTargetResources()` simultaneously, both will succeed, crediting resources twice for the same time window. This is exploitable by launching two attacks that resolve at the same instant.

**Fix**: Add `AND tempsPrecedent=?` CAS condition and check `affected_rows`, identical to `game_resources.php:120`.

---

### [DB-R1-002] CRITICAL attaquer.php:146-157 -- Attack launch lacks transaction: molecule double-spend race condition

The attack launch path reads molecules, checks availability, then deducts across multiple UPDATE statements without a transaction or row locks:

```php
// Line 146: Reads molecules (no lock)
$ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=?...', ...);
// Line 148-152: Loop subtracting molecules one-by-one
while ($moleculesAttaque = mysqli_fetch_array($ex)) {
    $newNombre = $moleculesAttaque['nombre'] - $_POST['nbclasse' . $c];
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);
}
// Line 154: Inserts attack action
dbExecute($base, 'INSERT INTO actionsattaques VALUES(...)');
```

A player can submit two attack forms simultaneously. Both requests read the same molecule counts, both pass the sufficiency check (line 117), and both deduct -- effectively sending twice the molecules they own. The molecules could even go negative.

**Fix**: Wrap lines 146-158 in `withTransaction()` and use `SELECT ... FOR UPDATE` on the molecules rows.

---

## HIGH

### [DB-R1-003] HIGH armee.php:67-76 -- Neutrino purchase race condition (TOCTOU)

Neutrino purchase reads energy at page load (line 67 checks `$ressources['energie']`), then deducts without transaction or lock:

```php
if ($_POST['nombreneutrinos'] * $coutNeutrino <= $ressources['energie']) {
    dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', ...);
    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', ...);
    dbExecute($base, 'UPDATE autre SET energieDepensee=? WHERE login=?', ...);
}
```

Three separate UPDATEs, no transaction, resource check uses stale page-load data. Two simultaneous submissions can both pass the check and double-spend energy.

**Fix**: Wrap in `withTransaction()`, re-read `ressources ... FOR UPDATE` inside the transaction, then re-check.

---

### [DB-R1-004] HIGH armee.php:92-139 -- Molecule formation race condition (TOCTOU)

Molecule formation reads resources at line 93, checks sufficiency at lines 96-100, then deducts at lines 122-139 without transaction or locks. The resource deduction itself is built as a dynamic SQL string (lines 122-131):

```php
$ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', ...);
// ... check loop ...
$chaine = $chaine . $ressource . '=' . ($ressources[$ressource] - ...) . $plus;
$stmt = mysqli_prepare($base, 'UPDATE ressources SET ' . $chaine . ' WHERE login=?');
```

Two concurrent formation requests both read the same resource values, both pass the check, and both deduct the full cost -- spending resources the player does not have.

**Fix**: Wrap in `withTransaction()` with `SELECT ... FOR UPDATE` on the resources row.

---

### [DB-R1-005] HIGH armee.php:174-220 -- Molecule creation race condition (TOCTOU)

Molecule class creation reads energy at line 174, checks sufficiency at line 175, then deducts at lines 219-220 without transaction or locks. Between the check and the deduction, another request can also pass the check.

**Fix**: Wrap in `withTransaction()` with `SELECT ... FOR UPDATE` on the resources row.

---

### [DB-R1-006] HIGH constructions.php:197-299 -- Construction start race condition (TOCTOU)

`traitementConstructions()` reads resources (via global `$ressources` loaded at page init), checks sufficiency at lines 232-238, then deducts at lines 240-263 without transaction or row locks. The resource deduction builds a dynamic SQL UPDATE string (lines 241-246):

```php
$chaine = $chaine . $ressource . '=' . ($ressources[$ressource] - $liste['cout'...]) . $plus;
```

Two simultaneous construction requests can both pass the sufficiency check and deduct from the same stale resource values.

**Fix**: Wrap in `withTransaction()`, re-read `ressources ... FOR UPDATE` inside the transaction.

---

### [DB-R1-007] HIGH alliance.php:78-85 -- Duplicator upgrade race condition (TOCTOU)

Alliance duplicator upgrade reads `energieAlliance` at line 80, checks at line 82, then deducts at line 85 without transaction or `FOR UPDATE`:

```php
$energieAlliance = dbFetchOne($base, 'SELECT energieAlliance FROM alliances WHERE id=?', ...);
if ($energieAlliance['energieAlliance'] >= $cout) {
    dbExecute($base, 'UPDATE alliances SET duplicateur=?, energieAlliance=? WHERE id=?', ...);
}
```

If two alliance members click "Upgrade" simultaneously, both requests see the same energy balance, both pass the check, and both execute the UPDATE. One upgrade is paid for; the other is free.

**Fix**: Wrap in `withTransaction()` with `SELECT ... FOR UPDATE` on the alliances row.

---

### [DB-R1-008] HIGH alliance.php:94-113 -- Alliance research upgrade race condition (TOCTOU)

Same pattern as DB-R1-007. Research upgrade reads alliance data at line 98 without lock, checks at line 105, and deducts at line 108:

```php
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', ...);
// check
dbExecute($base, 'UPDATE alliances SET ' . $techName . '=?, energieAlliance=? WHERE id=?', ...);
```

Concurrent requests from multiple alliance members can double-spend alliance energy.

**Fix**: Wrap in `withTransaction()` with `SELECT ... FOR UPDATE` on the alliances row.

---

### [DB-R1-009] HIGH game_resources.php:180 -- N+1 query: moleculesPerdues re-read inside molecule decay loop

Inside the molecule decay loop (lines 172-183), every iteration re-reads `moleculesPerdues` from the database:

```php
while ($molecules = mysqli_fetch_array($ex)) {
    // ...
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=?...', ...);
}
```

With 4 molecule classes, this causes 4 extra SELECT queries per player update. In a game with 100+ active players, this adds 400+ unnecessary queries per update cycle.

**Fix**: Read `moleculesPerdues` once before the loop, accumulate locally, and write a single UPDATE after the loop.

---

### [DB-R1-010] HIGH game_actions.php:99-100 -- N+1 query: moleculesPerdues re-read inside combat decay loop

Same pattern as DB-R1-009, but in the attack processing path (lines 94-103):

```php
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', ...);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=?...', ...);
}
```

This runs during every attack resolution (attacker travel decay), with 4 iterations per attack.

**Fix**: Read once before loop, accumulate, write once after.

---

### [DB-R1-011] HIGH game_actions.php:456-462 -- N+1 query: moleculesPerdues re-read inside return decay loop

Same pattern again, in the troop return path (lines 456-462):

```php
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', ...);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=?...', ...);
}
```

**Fix**: Same as DB-R1-009 and DB-R1-010.

---

### [DB-R1-012] HIGH game_actions.php:324-442 -- Espionage processing not in transaction

The espionage resolution path (lines 324-442) performs multiple reads and a report INSERT without any transaction protection. While the attack path (lines 107-323) is correctly wrapped in `mysqli_begin_transaction`/`commit`, the espionage `else` branch at line 324 has no transaction:

```php
} else {
    $nDef = dbFetchOne(...);
    // ... many reads and report generation ...
    dbExecute($base, 'INSERT INTO rapports VALUES(...)');
    dbExecute($base, 'INSERT INTO rapports VALUES(...)'); // defender spy report
    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', ...);
}
```

If an error occurs mid-way (e.g., after inserting the attacker's report but before the DELETE), the action will be re-processed on the next page load, generating duplicate reports.

**Fix**: Wrap the espionage branch in `withTransaction()`.

---

### [DB-R1-013] HIGH game_resources.php:32-36 -- N+1 query: 4 individual molecule queries in revenuEnergie()

`revenuEnergie()` queries molecules one-by-one in a loop (lines 32-36):

```php
for ($i = 1; $i <= 4; $i++) {
    $molecules = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $i);
    $totalIode += productionEnergieMolecule($molecules['iode'], $niveauiode) * $molecules['nombre'];
}
```

This function is called on every page load for the current player and during resource updates. The 4 queries could be a single `SELECT * FROM molecules WHERE proprietaire=?`.

**Fix**: Batch into one query with WHERE proprietaire=? and iterate the result set.

---

### [DB-R1-014] HIGH ecriremessage.php:14-18 -- Alliance-wide messaging: unbounded INSERT loop without transaction

When sending to `[alliance]`, individual INSERT statements are executed in a loop without a transaction:

```php
while ($destinataire = mysqli_fetch_array($ex)) {
    dbExecute($base, 'INSERT INTO messages VALUES(...)');
}
```

If the loop fails mid-way (e.g., connection timeout), some alliance members get the message and others do not. With a large alliance (up to 10 members), this is 10 individual INSERT calls.

**Fix**: Wrap the loop in `withTransaction()`.

---

### [DB-R1-015] HIGH attaquer.php:36-39 -- Espionage launch lacks transaction: neutrino double-spend

The espionage launch deducts neutrinos and inserts an attack action without a transaction:

```php
dbExecute($base, 'INSERT INTO actionsattaques VALUES(...)');
$newNeutrinos = $autre['neutrinos'] - $_POST['nombreneutrinos'];
dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', ...);
```

Two simultaneous espionage requests can both pass the check at line 26 (`$_POST['nombreneutrinos'] <= $autre['neutrinos']`) using stale data from page load, and both deduct from the same baseline.

**Fix**: Wrap in `withTransaction()` with `SELECT neutrinos FROM autre WHERE login=? FOR UPDATE`.

---

### [DB-R1-016] HIGH game_actions.php:446-467 -- Troop return processing lacks transaction

Troop return (lines 446-467) reads molecules, updates molecule counts, updates moleculesPerdues, and deletes the action -- all without a transaction:

```php
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', ...);
}
dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', ...);
```

A partial failure (e.g., connection loss after updating 2/4 molecule classes) leaves the action undeleted, and re-processing will add molecules again (double-return).

**Fix**: Wrap in `withTransaction()`.

---

## MEDIUM

### [DB-R1-017] MEDIUM catalyst.php:63-66 -- Catalyst rotation race condition

Catalyst rotation checks `catalyst_week != $currentWeek` then updates without a lock:

```php
if (!$stats || $stats['catalyst_week'] != $currentWeek) {
    $newCatalyst = $currentWeek % count($CATALYSTS);
    dbExecute($base, 'UPDATE statistiques SET catalyst=?, catalyst_week=?', ...);
}
```

Two concurrent requests at the start of a new week can both see the old week and both execute the UPDATE. While the result is idempotent (both set the same value), the two UPDATE queries are wasteful and create unnecessary write contention on the `statistiques` table.

**Fix**: Use `UPDATE statistiques SET catalyst=?, catalyst_week=? WHERE catalyst_week != ?` as an atomic conditional UPDATE.

---

### [DB-R1-018] MEDIUM listesujets.php:33 -- Topic lookup by content is non-unique and racy

After inserting a new topic, the code finds its ID by matching on content:

```php
$sujet = dbFetchOne($base, 'SELECT id FROM sujets WHERE contenu = ?', 's', $_POST['contenu']);
```

If two players create topics with identical content simultaneously, this query can return the wrong topic ID, causing the `statutforum` entry to reference the wrong topic.

**Fix**: Use `LAST_INSERT_ID()` via `mysqli_insert_id($base)` to get the newly inserted row's ID.

---

### [DB-R1-019] MEDIUM voter.php:40-43 -- Poll vote check-then-insert without transaction

The poll voting logic checks for an existing vote, then inserts or updates:

```php
$existing = dbFetchOne($base, 'SELECT count(*) AS nb FROM reponses WHERE login = ? AND sondage = ?', ...);
if ($existing['nb'] == 0) {
    dbExecute($base, 'INSERT INTO reponses VALUES(...)');
} else {
    dbExecute($base, 'UPDATE reponses SET reponse = ? WHERE ...');
}
```

Two concurrent requests from the same player can both see `nb=0` and both INSERT, creating duplicate votes. The impact is low (cosmetic) but the pattern is incorrect.

**Fix**: Use `INSERT ... ON DUPLICATE KEY UPDATE` with a unique index on `(login, sondage)`, or wrap in transaction with `FOR UPDATE`.

---

### [DB-R1-020] MEDIUM update.php:35-42 -- N+1 query: individual resource SELECT+UPDATE per atom type

`updateTargetResources()` loops over 8 atom types, performing a SELECT and UPDATE for each:

```php
foreach ($nomsRes as $num => $ressource) {
    $donnees = dbFetchOne($base, "SELECT $ressource, revenu$ressource FROM ressources WHERE login=?", ...);
    // compute
    dbExecute($base, "UPDATE ressources SET $ressource=? WHERE login=?", ...);
}
```

This produces 16 queries (8 SELECT + 8 UPDATE) where a single SELECT and single UPDATE would suffice, exactly as `updateRessources()` in game_resources.php already does (lines 141-158).

**Fix**: Read all resources once, compute all values, execute one UPDATE with all columns.

---

### [DB-R1-021] MEDIUM update.php:47-57 -- N+1 query: individual molecule decay without batching

The molecule decay loop in `updateTargetResources()` updates each molecule individually:

```php
while ($molecules = mysqli_fetch_array($exResult)) {
    // compute decay
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', ...);
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', ...);
}
```

With 4 molecule classes, this is 8 UPDATE queries. The `moleculesPerdues` update could be accumulated and written once.

**Fix**: Accumulate `moleculesPerdues` delta in PHP, write one UPDATE after the loop.

---

### [DB-R1-022] MEDIUM formulas.php:84-87 -- attaque() queries DB on every call for medal data

The `attaque()` function queries the database for medal data by default:

```php
if ($medalData === null) {
    global $base;
    $medalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
}
```

This function is called once per molecule class during combat (4 times for attacker + 4 times for defender = 8 calls per combat). While it accepts a `$medalData` parameter to avoid the query, callers in combat.php do not pass it.

**Fix**: Pre-fetch medal data once in combat.php and pass it to all `attaque()` / `defense()` / `pillage()` calls.

---

### [DB-R1-023] MEDIUM formulas.php:104-107 -- defense() queries DB on every call for medal data

Same pattern as DB-R1-022 for the `defense()` function.

---

### [DB-R1-024] MEDIUM formulas.php:134-137 -- pillage() queries DB on every call for medal data

Same pattern as DB-R1-022 for the `pillage()` function.

---

### [DB-R1-025] MEDIUM formulas.php:176-188 -- coefDisparition() queries DB for each molecule class

`coefDisparition()` queries `molecules`, `constructions`, and `autre` tables. It is called inside loops in:
- game_resources.php:174 (4 calls)
- update.php:52 (4 calls)
- game_actions.php:95 (4 calls per attack resolution)
- game_actions.php:457 (4 calls per troop return)

Each call triggers 3 DB queries (molecules, constructions, autre), totaling 12 queries per invocation site. For resource updates that run on every page load, this is 48 extra queries.

**Fix**: Pre-fetch the needed data for all molecule classes in one query and pass to `coefDisparition()`.

---

### [DB-R1-026] MEDIUM forum.php:70-85 -- N+1 query: per-forum stats queries in loop

Forum listing queries `nbSujets`, `statutforum`, and last-topic info per forum in a loop. With 8 forums, this is 24+ additional queries.

**Fix**: Use JOINs or subqueries to fetch forum stats in a single query.

---

### [DB-R1-027] MEDIUM sujet.php:162-170 -- N+1 query: per-reply image and moderator queries

Topic view queries image and moderator status per reply:

```php
while ($reponses = mysqli_fetch_array($ex)) {
    $image = dbFetchOne($base, 'SELECT image FROM membre WHERE login=?', ...);
    $mod = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login=?', ...);
}
```

A topic with 20 replies generates 40 extra queries. These two columns could be JOINed in the initial reply query.

**Fix**: JOIN `membre` in the initial reply query to fetch `image` and `moderateur` alongside reply data.

---

### [DB-R1-028] MEDIUM listesujets.php:90 -- N+1 query: statutforum checked per topic in loop

Topic listing checks `statutforum` for read/unread per topic:

```php
while (...) {
    $ex3 = dbFetchOne($base, 'SELECT ... FROM statutforum WHERE login=? AND idsujet=?', ...);
}
```

With 20 topics per page, this is 20 extra queries. The status could be fetched in bulk.

**Fix**: Pre-load all `statutforum` entries for the current page of topics in one query.

---

### [DB-R1-029] MEDIUM alliance.php:206-229 -- N+1 query: war/pact alliance tag lookup per declaration

Alliance page queries each war's and pact's opposing alliance tag individually:

```php
while ($guerre = mysqli_fetch_array($ex)) {
    $allianceJoueurAdverse = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', ...);
}
while ($pacte = mysqli_fetch_array($ex)) {
    $allianceJoueurAllie = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', ...);
}
```

With multiple wars and pacts, each requires its own query.

**Fix**: JOIN `alliances` in the initial declarations query.

---

### [DB-R1-030] MEDIUM game_resources.php:14-28 -- revenuEnergie() queries constructions and autre redundantly

`revenuEnergie()` queries the `constructions` table twice (lines 14 and 21) and `autre` once (line 23) and `alliances` once (line 26). These are the same tables that `initPlayer()` has already loaded into globals. The function should accept pre-fetched data instead of re-querying.

**Fix**: Accept constructor/autre/alliance data as parameters or use the cached globals.

---

### [DB-R1-031] MEDIUM player.php:758-777 -- supprimerJoueur does not clean up prestige, attack_cooldowns, connectes, or actionsconstruction

The `supprimerJoueur()` transaction deletes from 12 tables but misses:
- `prestige` (player's cross-season progression data remains orphaned)
- `attack_cooldowns` (expired cooldowns referencing deleted player persist)
- `connectes` (online tracking entries persist)
- `actionsconstruction` (pending constructions persist)
- `reponses` (poll votes reference non-existent player)
- `sujets` / `reponses` (forum posts reference deleted player login)

**Fix**: Add DELETE statements for `prestige`, `attack_cooldowns`, `actionsconstruction` to the transaction. Forum posts (sujets, reponses) may be intentionally kept for history.

---

### [DB-R1-032] MEDIUM player.php:774-776 -- supprimerJoueur statistiques counter has TOCTOU race

Inside the transaction, the inscrit count is read then decremented:

```php
$donnees = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
$nbinscrits = $donnees['inscrits'] - 1;
dbExecute($base, 'UPDATE statistiques SET inscrits=?', 'i', $nbinscrits);
```

If two players are deleted simultaneously, both read the same count and both decrement by 1, resulting in only a net -1 instead of -2.

**Fix**: Use atomic decrement: `UPDATE statistiques SET inscrits = inscrits - 1`.

---

## LOW

### [DB-R1-033] LOW armee.php:20-24 -- Dynamic SQL column names from server-side array (cosmetic risk)

Molecule deletion builds column reset SQL from `$nomsRes`:

```php
$chaine = $chaine . '' . $ressource . '=default' . $plus;
```

While `$nomsRes` is a server-side constant array and not user-controllable, interpolating column names into SQL is a fragile pattern. A future developer adding a resource with a SQL-significant character (e.g., containing a space or keyword) could introduce injection.

**Fix**: Use backtick-quoting on interpolated column names: `` "`$ressource`=default" ``.

---

### [DB-R1-034] LOW constructions.php:241-246 -- Dynamic SQL column names from server-side array (cosmetic risk)

Same pattern as DB-R1-033 in `traitementConstructions()`.

---

### [DB-R1-035] LOW update.php:36,42 -- Dynamic SQL column names from server-side array (cosmetic risk)

Same pattern as DB-R1-033 in `updateTargetResources()`:

```php
$donnees = dbFetchOne($base, "SELECT $ressource, revenu$ressource FROM ressources WHERE login=?", ...);
dbExecute($base, "UPDATE ressources SET $ressource=? WHERE login=?", ...);
```

---

### [DB-R1-036] LOW game_actions.php:517-538 -- Dynamic SQL column names from server-side array (cosmetic risk)

Resource send processing builds dynamic column list:

```php
$envoiSetClauses[] = "$ressource=?";
```

This uses parameterized values (good) but unquoted column names from `$nomsRes`.

---

### [DB-R1-037] LOW player.php:532-534 -- augmenterBatiment interpolates column name from whitelist

```php
dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", ...);
```

`$nom` comes from the `$listeConstructions` keys which are hardcoded server-side. Safe, but should use backtick quoting for defense-in-depth.

---

### [DB-R1-038] LOW alliance.php:98 -- Dynamic column name from validated POST key in SQL

```php
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', ...);
```

`$techName` comes from `$_POST['upgradeResearch']` but is validated against `$ALLIANCE_RESEARCH` keys at line 94 (`isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])`). Safe due to whitelist validation, but should use backtick quoting.

---

### [DB-R1-039] LOW migrations -- Missing index on reponses(login, sondage) for vote uniqueness

The poll voting code (voter.php:40) queries `SELECT count(*) FROM reponses WHERE login=? AND sondage=?`. There is no index on this pair of columns, causing a full table scan for every vote check. Additionally, a UNIQUE index here would prevent the race condition in DB-R1-019 at the database level.

**Fix**: Add migration: `CREATE UNIQUE INDEX idx_reponses_login_sondage ON reponses (login, sondage);`

---

### [DB-R1-040] LOW migrations -- Missing index on invitations(invite) for player invitation lookup

`alliance.php:427` queries `SELECT * FROM invitations WHERE invite=?` but no index exists on `invite`. The `invitations` table only has indexes on `idalliance` (from supprimerAlliance usage).

**Fix**: Add `CREATE INDEX idx_invitations_invite ON invitations (invite);`

---

### [DB-R1-041] LOW migrations -- prestige table uses VARCHAR(50) while membre.login is VARCHAR(255)

Migration 0007 creates `prestige.login` as `VARCHAR(50)` while migration 0002 sets `membre.login` to `VARCHAR(255)`. This column width mismatch means long usernames would be silently truncated in the prestige table.

**Fix**: `ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL;`

---

### [DB-R1-042] LOW admin/tableau.php -- No DB operations (informational)

`admin/tableau.php` is a static HTML page with no database operations. It only includes `connexion.php` for the admin auth redirect. No findings.

---

## Positive Findings (No Action Required)

The following patterns were reviewed and found to be correctly implemented:

| Pattern | File | Assessment |
|---------|------|------------|
| Market buy/sell with `withTransaction` + `FOR UPDATE` | marche.php | Correct |
| Alliance donation with `withTransaction` + `FOR UPDATE` | don.php | Correct |
| Combat resolution CAS guard on `attaqueFaite` | game_actions.php:71 | Correct |
| Resource update CAS guard on `tempsPrecedent` | game_resources.php:120 | Correct |
| Visitor-to-player rename in transaction | comptetest.php | Correct |
| Alliance deletion in transaction | player.php:742-749 | Correct |
| Player deletion in transaction | player.php:758-777 | Correct (minus missing tables) |
| Classement ORDER BY whitelist | classement.php:54-56, alliance.php:344-372 | Correct |
| Alliance research key whitelist | alliance.php:94 | Correct |
| Dynamic column names from `$nomsRes` server array | Multiple files | Safe (server constant, not user input) |
| Prepared statements throughout | All files | Correct (all queries use parameterized helpers) |
| MyISAM to InnoDB migration | migration 0013 | Correct (enables transactions) |

---

## Remediation Priority

### Phase 1 -- Critical (fix immediately)
1. **DB-R1-001**: Add CAS guard to update.php (1 line change)
2. **DB-R1-002**: Wrap attack launch in transaction with FOR UPDATE

### Phase 2 -- High race conditions (fix this week)
3. **DB-R1-003 through DB-R1-008**: Wrap all resource-spending operations in transactions with FOR UPDATE (armee.php, constructions.php, alliance.php, attaquer.php)
4. **DB-R1-012**: Wrap espionage in transaction
5. **DB-R1-015**: Wrap espionage launch in transaction
6. **DB-R1-016**: Wrap troop return in transaction

### Phase 3 -- High N+1 queries (fix this week)
7. **DB-R1-009, DB-R1-010, DB-R1-011**: Batch moleculesPerdues updates
8. **DB-R1-013**: Batch molecule queries in revenuEnergie
9. **DB-R1-014**: Wrap alliance messaging in transaction

### Phase 4 -- Medium (fix this sprint)
10. **DB-R1-017 through DB-R1-032**: N+1 patterns, missing indexes, orphaned data

### Phase 5 -- Low (backlog)
11. **DB-R1-033 through DB-R1-042**: Cosmetic hardening, column quoting, schema alignment
