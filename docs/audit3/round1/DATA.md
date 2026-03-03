# Data Integrity Audit Report

**Auditor:** Claude Opus 4.6 (code review agent)
**Date:** 2026-03-03
**Scope:** All data-modifying PHP files and SQL migrations in the TVLW codebase
**Codebase:** /home/guortates/TVLW/The-Very-Little-War/

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 5     |
| HIGH     | 11    |
| MEDIUM   | 14    |
| LOW      | 8     |
| **Total** | **38** |

---

## CRITICAL Findings

### [DATA-R1-001] CRITICAL player.php:793-838 -- remiseAZero() resets ALL players without transaction, partial failure leaves inconsistent state

`remiseAZero()` executes 15+ separate UPDATE/DELETE statements across all tables without wrapping them in a transaction. If the process crashes mid-way (e.g., after `UPDATE autre` but before `DELETE FROM declarations`), the database is left in a fundamentally broken state where some tables are reset and others are not. Given that this function operates on ALL rows in every table, a failure at any point creates irrecoverable inconsistency.

```php
// player.php:793
function remiseAZero()
{
    global $base;
    // No withTransaction() wrapper -- 15+ global UPDATE/DELETE statements
    dbExecute($base, 'UPDATE autre SET points=0, ...');
    dbExecute($base, 'UPDATE constructions SET generateur=default, ...');
    dbExecute($base, 'UPDATE alliances SET energieAlliance=0,...');
    dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
    // ... 10+ more statements
    dbExecute($base, 'DELETE FROM declarations');
    dbExecute($base, 'DELETE FROM messages');
    // etc.
}
```

**Impact:** A partial season reset corrupts the entire game state for all players simultaneously.

---

### [DATA-R1-002] CRITICAL player.php:752-778 -- supprimerJoueur() missing cleanup for actionsconstruction, sanctions, sondages, and prestige tables

`supprimerJoueur()` deletes from 13 tables but misses at least 4 tables that reference the player's login:

- `actionsconstruction` -- building queues are not cleaned up (login column)
- `sanctions` -- moderation sanctions persist as orphan rows (joueur column)
- `prestige` -- cross-season prestige data persists with no player (login PRIMARY KEY)
- `sondages/reponses` -- poll responses reference deleted player (login column)

```php
// player.php:758 - Transaction cleans 13 tables but misses others
withTransaction($base, function() use ($base, $joueur) {
    dbExecute($base, 'DELETE FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login=?)', 's', $joueur);
    dbExecute($base, 'DELETE FROM autre WHERE login=?', 's', $joueur);
    // ... 11 more DELETEs ...
    // MISSING: DELETE FROM actionsconstruction WHERE login=?
    // MISSING: DELETE FROM prestige WHERE login=?
    // MISSING: DELETE FROM sanctions WHERE joueur=?
    // MISSING: DELETE FROM reponses WHERE login=?
});
```

**Impact:** Orphaned actionsconstruction rows will cause errors when the system tries to process building queues for a non-existent player. Orphaned prestige rows waste storage and could cause key conflicts if a player re-registers with the same name.

---

### [DATA-R1-003] CRITICAL basicprivatephp.php:134-297 -- Season reset triggered by any player, no atomicity between archive and reset

The entire season reset (archive top-20 players, award victory points, send emails, call `remiseAZero()`) is triggered by whichever player happens to connect first after the 24h maintenance window. While there is an advisory lock, the archive-then-reset sequence is not atomic:

1. Lines 143-189: Archive data is read from live tables
2. Lines 192-209: Victory points are awarded (modifying `autre` and `alliances`)
3. Line 221: `remiseAZero()` is called (wiping everything)

If the process crashes between steps 2 and 3, victory points are awarded but the reset never happens. The next player connection would re-enter the reset path and award victory points AGAIN, creating duplicate awards.

```php
// basicprivatephp.php:192-221
// Points are awarded to autre/alliances tables...
$c = 1;
while ($pointsVictoire = mysqli_fetch_array($classement)) {
    ajouter('victoires', 'autre', pointsVictoireJoueur($c), $pointsVictoire['login']);
    $c++;
}
// ... alliances too ...
// Then much later:
remiseAZero(); // If this fails, points were already awarded
```

**Impact:** Double-awarding of victory points corrupts the leaderboard permanently. Since `victoires` from `autre` are preserved across seasons (prestige tracks them), this data corruption is permanent.

---

### [DATA-R1-004] CRITICAL comptetest.php:66-83 -- Visitor rename transaction does not update prestige, attack_cooldowns, or vacances tables

When a visitor account is renamed to a permanent account, 15 tables are updated in a transaction. However, several tables that reference players by login are not updated:

- `prestige` (login PRIMARY KEY)
- `attack_cooldowns` (attacker, defender columns)
- `vacances` (references membre.id, so this one is actually OK via id)

```php
// comptetest.php:66
withTransaction($base, function() use ($base, $newLogin, $oldLogin, $hashedPassword, $email) {
    dbExecute($base, 'UPDATE autre SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
    dbExecute($base, 'UPDATE grade SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
    // ... 13 more renames ...
    // MISSING: UPDATE prestige SET login = ? WHERE login = ?
    // MISSING: UPDATE attack_cooldowns SET attacker = ? WHERE attacker = ?
    // MISSING: UPDATE attack_cooldowns SET defender = ? WHERE defender = ?
});
```

**Impact:** If a visitor had earned prestige points or had active attack cooldowns, these records become orphaned after rename. The prestige row for "VisiteurN" persists while the new login gets none. If the player buys prestige unlocks before the season reset, the INSERT ON DUPLICATE KEY in `awardPrestigePoints()` would create a new row for the new login, effectively duplicating their prestige entry.

---

### [DATA-R1-005] CRITICAL player.php:739-750 -- supprimerAlliance() does not clean up attack_cooldowns referencing alliance members, and alliance deletion order causes transient FK-like inconsistency

`supprimerAlliance()` deletes the alliance from `alliances` BEFORE resetting `autre.idalliance` to 0. During the brief window between these two statements, any concurrent read of `autre` for a member of this alliance will find `idalliance` pointing to a non-existent alliance, which will cause NULL dereference errors in functions like `revenuEnergie()` or `initPlayer()` that look up alliance data.

```php
// player.php:742
withTransaction($base, function() use ($base, $alliance) {
    dbExecute($base, 'UPDATE autre SET energieDonnee=0 WHERE idalliance=?', 'i', $alliance);
    dbExecute($base, 'DELETE FROM alliances WHERE id=?', 'i', $alliance);  // Alliance gone
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE idalliance=?', 'i', $alliance); // Members still reference it briefly
    // ...
});
```

While wrapped in a transaction, if InnoDB isolation level is READ COMMITTED (MariaDB default for some configurations), other transactions CAN see the intermediate state.

**Impact:** Concurrent requests during alliance deletion can crash due to NULL alliance lookups. The fix is to reorder: set `idalliance=0` BEFORE deleting the alliance row.

---

## HIGH Findings

### [DATA-R1-006] HIGH player.php:793-838 -- remiseAZero() does not reset victoires column in autre table

The `remiseAZero()` function resets nearly all columns in `autre`, but the `UPDATE autre SET ...` statement on line 799 does not include `victoires` in its reset list. Since victory points are awarded just before `remiseAZero()` is called in basicprivatephp.php, the `victoires` column accumulates across seasons.

However, this is only correct if `victoires` is intentionally cross-season persistent. The problem is that `totalPoints` IS reset to 0 but `victoires` is NOT, creating an inconsistency: after reset, a player's `victoires` points are not reflected in `totalPoints`. When `ajouterPoints()` is called later (which modifies `totalPoints`), the totalPoints won't include legacy victoires.

```php
// player.php:799
dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,
    moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1,
    totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees=0,
    tradeVolume=0, missions=\'\'');
// NOTE: victoires is NOT reset -- intentional or bug?
```

**Impact:** If this is unintentional, `victoires` accumulates forever and the leaderboard becomes skewed toward older players. If intentional, `totalPoints` is permanently desynchronized from the sum of its components after each reset.

---

### [DATA-R1-007] HIGH player.php:60-71 -- inscrire() uses non-atomic read-then-increment for inscrits counter

Registration reads `inscrits` from `statistiques`, increments in PHP, then writes back. If two players register simultaneously, they read the same value and both write `inscrits + 1`, losing one count.

```php
// player.php:30
$data1 = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
$nbinscrits = $data1['inscrits'] + 1;
// ... later in transaction:
dbExecute($base, 'UPDATE statistiques SET inscrits=?', 'i', $nbinscrits);
```

Compare with `comptetest.php:16` which correctly uses `LAST_INSERT_ID()` for atomic increment:
```php
dbExecute($base, 'UPDATE statistiques SET numerovisiteur = LAST_INSERT_ID(numerovisiteur) + 1');
```

**Impact:** Incorrect player count in `statistiques.inscrits`. The same TOCTOU issue exists in `supprimerJoueur()` at line 774-776.

---

### [DATA-R1-008] HIGH player.php:799-800 -- remiseAZero() does not reset alliance research columns properly

`remiseAZero()` resets `energieAlliance`, `duplicateur`, and the new research columns to 0, but the UPDATE on line 801 does not include all five alliance research columns added in migration 0010:

```php
// player.php:801
dbExecute($base, 'UPDATE alliances SET energieAlliance=0,duplicateur=0,catalyseur=0,
    fortification=0,reseau=0,radar=0,bouclier=0');
```

This appears to be correct. However, `pointsVictoire` is NOT reset. The `alliances.pointsVictoire` column accumulates across seasons (like `autre.victoires`). This creates the same desync issue as DATA-R1-006: after reset, alliance `pointstotaux` is recalculated from member `totalPoints` (which is 0), but `pointsVictoire` still holds the old value.

**Impact:** Alliance rankings immediately after a season reset will be entirely determined by historical `pointsVictoire` rather than current-season performance, which may not be the intended design.

---

### [DATA-R1-009] HIGH allianceadmin.php:69-72 -- Alliance quitting does not check if quitter is the chef, allowing leaderless alliances

When a player quits their alliance (line 183 sets `idalliance=0`), there is no check whether the player is the alliance chef. If the chef quits, the alliance remains with a `chef` column pointing to a player who is no longer in the alliance. The `alliance.php` code at lines 149-157 detects this and calls `supprimerAlliance()`, but only when someone VIEWS that alliance page. Until then, the alliance exists in a broken state.

```php
// allianceadmin.php:183
dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_POST['bannirpersonne']);
// No check: what if bannirpersonne == chef?
```

Also in `alliance.php:71`:
```php
if (isset($_POST['quitter'])) {
    csrfCheck();
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
    // No check if $_SESSION['login'] is the alliance chef
}
```

**Impact:** Leaderless alliance persists until someone views the alliance page. During this time, members cannot manage the alliance, and declarations (wars/pacts) remain active for an effectively dead alliance.

---

### [DATA-R1-010] HIGH basicprivatephp.php:145-165 -- Season archive uses live table data that could be modified mid-read

The season archive code reads from `autre`, `molecules`, and `alliances` tables using multiple separate queries without any locking. Other players connecting simultaneously can modify these tables (e.g., resource updates, combat), causing the archived data to be inconsistent (player X's score from one moment, their molecules from another).

```php
// basicprivatephp.php:145-165
$classement = dbQuery($base, 'SELECT * FROM autre ORDER BY totalPoints DESC LIMIT 0, 20');
while ($data = mysqli_fetch_array($classement)) {
    $sql4Result = dbQuery($base, 'SELECT nombre FROM molecules WHERE proprietaire = ? AND nombre != 0', 's', $data['login']);
    // ... more queries per player ...
}
```

**Impact:** Season archive data (stored in `parties` table) may contain inconsistent snapshots. Since this data is historical and displayed on leaderboards, it can show impossible combinations of stats.

---

### [DATA-R1-011] HIGH game_actions.php:107-323 -- Combat transaction does not include all related state changes

The combat resolution wraps `combat.php` in a transaction (`mysqli_begin_transaction` at line 107, `mysqli_commit` at line 319), but several critical pre-combat state changes happen OUTSIDE this transaction:

- Line 99-100: `moleculesPerdues` is updated for travel decay before the transaction begins
- Line 71: The CAS guard UPDATE happens before the transaction
- Lines 79-84: `updateRessources()` and `updateActions()` for the opponent happen before the transaction

If the combat transaction rolls back (line 321), the travel decay losses and opponent resource updates are NOT rolled back, creating permanent data loss.

```php
// game_actions.php:71 - Outside transaction
$casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
// Lines 79-84 - Outside transaction
updateRessources($actions['defenseur']);
updateActions($actions['defenseur']);
// Line 99-100 - Outside transaction
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? ...', ...);
// Line 107 - Transaction starts HERE
mysqli_begin_transaction($base);
```

**Impact:** On combat rollback, molecules lost during travel are permanently gone but the combat never happened. The attacker loses molecules with no battle report.

---

### [DATA-R1-012] HIGH player.php:27-71 -- inscrire() creates rows across 5 tables but has no UNIQUE constraint on login in most tables

`inscrire()` inserts rows into `membre`, `autre`, `ressources`, `molecules`, and `constructions`. While `membre` likely has a UNIQUE constraint on `login`, the other tables rely on application-level logic to prevent duplicates. If two concurrent registration requests for the same username pass the `dbFetchOne` check simultaneously (TOCTOU), duplicate rows will be created in `autre`, `ressources`, `constructions`, and `molecules`.

```php
// player.php:60-70 - All inside transaction, but the login existence check is at comptetest.php level
withTransaction($base, function() use (...) {
    dbExecute($base, 'INSERT INTO membre VALUES(default, ?, ...)', ...);
    dbExecute($base, 'INSERT INTO autre VALUES(?, ...)', ...);
    dbExecute($base, 'INSERT INTO ressources VALUES(default,?, ...)', ...);
    dbExecute($base, 'INSERT INTO molecules VALUES(..., ?, ...) x4', ...);
    dbExecute($base, 'INSERT INTO constructions VALUES(?, ...)', ...);
});
```

The login uniqueness check in `comptetest.php:56-58` is outside the transaction:
```php
$data = dbFetchOne($base, 'SELECT count(*) AS cnt FROM membre WHERE login = ?', 's', $loginClean);
if ($data['cnt'] == 0) { // TOCTOU: another request could register between check and insert
```

**Impact:** Duplicate player rows in `autre`, `ressources`, `constructions` would cause undefined behavior in all game logic that expects exactly one row per player per table.

---

### [DATA-R1-013] HIGH player.php:799 -- remiseAZero() does not reset specialization columns added by migration 0011

Migration `0011_add_specializations.sql` adds `spec_combat`, `spec_economy`, `spec_research` columns to the `constructions` table. However, `remiseAZero()` does not reset these columns. If specializations are intended to be per-season choices, they persist incorrectly across resets.

```php
// player.php:800
dbExecute($base, 'UPDATE constructions SET generateur=default, producteur=default, ...,
    coffrefort=0, formation=0, ...');
// MISSING: spec_combat=0, spec_economy=0, spec_research=0
```

**Impact:** Players keep their specialization choices across season resets, potentially gaining unintended advantages.

---

### [DATA-R1-014] HIGH player.php:800 -- remiseAZero() does not reset isotope column in molecules table

Migration `0008_add_isotope_column.sql` adds an `isotope` column to `molecules`. The season reset does:
```php
dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
```

But does NOT reset `isotope` to 0. When a player creates a new molecule class next season, the old isotope value persists in the row, and the player would need to explicitly set it again. While the molecule creation code in `armee.php` does set `isotope`, the stale value could cause confusion if the code path ever reads isotope before the player creates a new formula.

**Impact:** Isotope values from previous season persist on "Vide" molecule slots. Low probability of actual game impact since molecule creation overwrites the value, but violates data consistency expectations.

---

### [DATA-R1-015] HIGH player.php:836-837 -- remiseAZero() does not clean up cours (market price history) table

The `cours` table accumulates market price history entries (one per buy/sell transaction). `remiseAZero()` does not truncate or clean this table. Over many seasons, this table grows unboundedly. More importantly, the price chart on `marche.php` queries `SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1000`, which means players see price history from previous seasons mixed with the current season.

```php
// player.php:815-837
dbExecute($base, 'DELETE FROM declarations');
dbExecute($base, 'DELETE FROM invitations');
// ... other cleanups ...
// MISSING: DELETE FROM cours; or TRUNCATE cours;
```

**Impact:** Unbounded table growth. Market price chart shows stale cross-season data. First trade after reset starts from the last season's price rather than baseline.

---

### [DATA-R1-016] HIGH player.php:836-837 -- remiseAZero() does not reset news table

The `news` table accumulates news entries (season winner announcements). `remiseAZero()` does not clean this table. Old season announcements persist and are shown to players as if they are current.

**Impact:** UX confusion, unbounded table growth.

---

## MEDIUM Findings

### [DATA-R1-017] MEDIUM allianceadmin.php:183-184 -- Kicking a player does not clean up their actionsenvoi or actionsattaques referencing the alliance

When a player is kicked from an alliance, only their `idalliance` is set to 0 and their grade is deleted. If the kicked player had pending resource transfers (`actionsenvoi`) or attacks in progress that reference alliance war status, these are not updated. The combat system checks alliance wars for the attacker/defender at combat resolution time, so a kicked player's attack could still be counted as part of a war.

**Impact:** War casualty statistics could be incorrectly attributed to an alliance after the player was kicked.

---

### [DATA-R1-018] MEDIUM basicprivatephp.php:293-308 -- Season maintenance flag can be set by any player's page load without rate limiting

Any player connecting during the month transition sets `maintenance = 1` and overwrites `debut` with `time()`. If multiple players connect in rapid succession during the exact second of month transition, they all execute the UPDATE, each setting `debut` to a slightly different timestamp. While this is mostly harmless (the last write wins), it means the 24h countdown start time is non-deterministic.

```php
// basicprivatephp.php:302-304
dbExecute($base, 'UPDATE statistiques SET maintenance = 1');
$now = time();
dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);
```

**Impact:** The 24h maintenance window could be slightly longer or shorter depending on which player's write wins.

---

### [DATA-R1-019] MEDIUM migrations/migrate.php:43-49 -- Migration runner uses mysqli_multi_query without error checking per statement

The migration runner executes SQL files using `mysqli_multi_query()`, which batches multiple statements. It only checks for errors after all statements complete. If an intermediate statement fails but a later one succeeds, the migration is marked as applied even though it partially failed.

```php
// migrate.php:43-49
if (mysqli_multi_query($base, $sql)) {
    do {
        if ($result = mysqli_store_result($base)) {
            mysqli_free_result($result);
        }
    } while (mysqli_next_result($base));
}
if (mysqli_errno($base)) { // Only catches last error
```

**Impact:** Partially applied migrations are marked as complete and will not be re-run, leaving the schema in an inconsistent state.

---

### [DATA-R1-020] MEDIUM migrations/migrate.php -- No rollback mechanism for failed migrations

The migration runner has no concept of DOWN migrations or rollback. If a migration partially applies and fails, there is no automated way to undo it. The migration is NOT recorded (the INSERT into `migrations` happens after success check), but the partial schema changes persist.

**Impact:** Manual intervention required to fix partially applied migrations. No automated recovery path.

---

### [DATA-R1-021] MEDIUM migrations/0001_add_indexes.sql -- ADD INDEX will fail if re-run (no IF NOT EXISTS)

Most ALTER TABLE ADD INDEX statements do not use IF NOT EXISTS. If the migration runner encounters an error and the migration is not recorded, re-running it will fail on duplicate index names.

```sql
ALTER TABLE `autre` ADD INDEX `idx_autre_login` (`login`);
-- Will fail with "Duplicate key name" if run twice
```

Migration 0013 correctly uses `CREATE INDEX IF NOT EXISTS`, but 0001 does not.

**Impact:** Migration 0001 cannot be safely re-run after partial failure.

---

### [DATA-R1-022] MEDIUM includes/combat.php:354-357 -- Defender molecule counts can go negative due to race condition

Combat reads defender molecule counts, calculates losses, and writes `nombre - losses` without checking for concurrent combat. If two attacks resolve against the same defender simultaneously, both read the same initial `nombre`, both subtract their losses, and the second write overwrites the first. The defender could end up with more molecules than they should (the second write doesn't know about the first attack's losses).

```php
// combat.php:354
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di',
    ($classeDefenseur1['nombre'] - $classe1DefenseurMort), $classeDefenseur1['id']);
```

This uses absolute SET rather than atomic decrement (`nombre = nombre - ?`).

**Impact:** Defender molecule counts become desynchronized after concurrent attacks. Could result in negative molecule counts or inflated counts.

---

### [DATA-R1-023] MEDIUM player.php:800 -- constructions reset uses mixed DEFAULT and hardcoded values, depot is hardcoded to 1 instead of DEFAULT

```php
dbExecute($base, 'UPDATE constructions SET generateur=default, producteur=default, ...,
    depot=1, stabilisateur=default, condenseur=0, coffrefort=0, formation=0, ...');
```

`depot=1` is hardcoded while `generateur=default` and `producteur=default` use the schema default. If the schema default for `depot` is not 1, or if it changes in the future, this hardcoded value will be incorrect. Similarly, `condenseur=0` and `coffrefort=0` are hardcoded instead of using `default`.

**Impact:** If default values in the schema are ever changed, `remiseAZero()` will not reflect the new defaults for hardcoded columns.

---

### [DATA-R1-024] MEDIUM includes/game_actions.php:456-462 -- Returning troop molecule update uses absolute SET instead of atomic increment

When troops return from an attack, the code reads current molecule count and adds returning troops:
```php
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di',
    ($moleculesProp['nombre'] + $moleculesRestantes), $moleculesProp['id']);
```

If another process (e.g., formation completion) modifies the same molecule row between the SELECT and this UPDATE, the formation's additions are lost (overwritten by stale read + returning troops).

**Impact:** Molecule counts can lose formation-produced molecules when troops return from battle simultaneously.

---

### [DATA-R1-025] MEDIUM includes/game_resources.php:178-181 -- moleculesPerdues update in updateRessources has read-then-write race

The molecule decay loop reads `moleculesPerdues` inside the loop for each class, then writes back the incremented value. If two concurrent requests call `updateRessources()` for the same player, the `moleculesPerdues` counter can lose updates.

```php
// game_resources.php:180-181
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($molecules['nombre'] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $joueur);
```

This should use atomic increment: `UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?`

**Impact:** `moleculesPerdues` stat becomes inaccurate under concurrent access.

---

### [DATA-R1-026] MEDIUM alliance.php:44-49 -- Alliance creation is not atomic (INSERT then SELECT then UPDATE)

Creating an alliance involves three separate operations without a transaction:
1. INSERT into alliances
2. SELECT the new alliance's id
3. UPDATE autre SET idalliance for the creator

```php
// alliance.php:44-49
dbExecute($base, 'INSERT INTO alliances VALUES (default, ?, ?, ?, default, ?, ...)', ...);
$nouvellealliance = dbFetchOne($base, 'SELECT id FROM alliances WHERE tag=?', 's', $_POST['tagalliance']);
dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $nouvellealliance['id'], $_SESSION['login']);
```

If the process crashes after INSERT but before UPDATE, the alliance exists but has no members, and the creator's `idalliance` is still 0. The alliance becomes permanently orphaned (no chef is in it, so alliance.php's cleanup logic at line 149 would delete it on next view).

**Impact:** Orphaned alliance row until viewed. Low probability but still a consistency issue.

---

### [DATA-R1-027] MEDIUM includes/combat.php:574-612 -- Pillage resource update for both attacker and defender is not atomic with the rest of combat

The resource updates for pillage (lines 574-612) are inside the combat transaction, but the `placeDepot` query at line 577 and 605 read from `constructions` which could have been modified by `diminuerBatiment()` called earlier in the same combat (lines 476-523). The `diminuerBatiment()` function commits its own implicit writes, so the `placeDepot` value might reflect the post-combat building level rather than the pre-combat level.

**Impact:** Storage cap calculation during pillage may use a post-damage building level, resulting in slightly incorrect resource caps.

---

### [DATA-R1-028] MEDIUM player.php:837 -- remiseAZero() cleans attack_cooldowns but not the connectes table

`remiseAZero()` deletes from `attack_cooldowns` but does not clean the `connectes` table (online player tracking). While `basicprivatephp.php:89` cleans entries older than 5 minutes, after a season reset all players are moved to position -1000,-1000 and the connectes table still has entries from the maintenance period.

**Impact:** Minor -- stale online-player data for a few minutes after reset.

---

### [DATA-R1-029] MEDIUM includes/update.php:21 -- updateTargetResources() does not use atomic tempsPrecedent guard

Unlike `updateRessources()` in `game_resources.php` which uses CAS-style `UPDATE ... WHERE tempsPrecedent=?` to prevent double-counting, `updateTargetResources()` in `update.php` uses a simple `UPDATE ... SET tempsPrecedent=?` without a WHERE guard:

```php
// update.php:21
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $targetPlayer);
```

vs. game_resources.php:120:
```php
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
```

**Impact:** If `updateTargetResources()` is called concurrently for the same player (e.g., two attacks resolve simultaneously), resources could be double-counted.

---

### [DATA-R1-030] MEDIUM marche.php:95-115 -- Resource transfer (marche send) does not use a transaction

Sending resources via the market creates an `actionsenvoi` record and deducts resources from the sender in two separate operations without a transaction:

```php
// marche.php:95
dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', ...);
// marche.php:110-115
$stmt = mysqli_prepare($base, 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?');
```

If the process crashes after the INSERT but before the UPDATE, the resources are sent but never deducted from the sender, creating resources from thin air.

**Impact:** Resource duplication exploit (low probability, high impact).

---

## LOW Findings

### [DATA-R1-031] LOW migrations/0007_add_prestige_table.sql -- No NOT NULL constraint on prestige.total_pp and no CHECK constraint

```sql
CREATE TABLE IF NOT EXISTS prestige (
    login VARCHAR(50) PRIMARY KEY,
    total_pp INT DEFAULT 0,
    unlocks VARCHAR(255) DEFAULT ''
);
```

`total_pp` should be `NOT NULL DEFAULT 0` and ideally have a CHECK constraint `total_pp >= 0`. The `purchasePrestigeUnlock()` function does check `total_pp >= cost` before spending, but a bug elsewhere could set it negative.

**Impact:** Defensive coding issue. Negative prestige points would be confusing but not game-breaking.

---

### [DATA-R1-032] LOW migrations/ -- No foreign key constraints anywhere in the schema

The entire database schema uses no FOREIGN KEY constraints. All referential integrity is enforced at the application level (PHP code). This means:

- Deleting a player does not cascade-delete their rows in other tables (requires manual cleanup in `supprimerJoueur()`)
- Deleting an alliance does not cascade (requires `supprimerAlliance()`)
- No database-level protection against orphaned rows

While adding FKs to a legacy schema is risky, the absence means every relationship must be maintained in PHP code, which is error-prone (see DATA-R1-002).

**Impact:** Systemic risk of orphaned data. Every new table or column that references a player/alliance login must be manually added to cleanup functions.

---

### [DATA-R1-033] LOW migrations/0004_add_attack_cooldowns.sql -- VARCHAR(50) for attacker/defender too small

The `attack_cooldowns` table was created with `VARCHAR(50)` for attacker/defender, but migration 0013 correctly fixes this to `VARCHAR(255)` to match `membre.login`. However, the prestige table (migration 0007) still uses `VARCHAR(50)` for login, which is inconsistent with `membre.login VARCHAR(255)`.

```sql
-- 0007
CREATE TABLE IF NOT EXISTS prestige (
    login VARCHAR(50) PRIMARY KEY, -- Should be VARCHAR(255)
```

**Impact:** If a player's login exceeds 50 characters, prestige operations will fail with truncation or error. Current registration limits likely prevent this, but the schema should be consistent.

---

### [DATA-R1-034] LOW player.php:62 -- INSERT INTO autre uses positional VALUES with 21 columns, extremely fragile

```php
dbExecute($base, 'INSERT INTO autre VALUES(?, default, default, "Pas de description", ?, default, default, default, default, default, default, default, default,default,default,?,default,default,default,default,"",default)', 'sis', ...);
```

This INSERT relies on exact column order matching the schema. Any ALTER TABLE ADD COLUMN that inserts a column in the middle (rather than at the end) will silently shift all subsequent values, causing data corruption. The same pattern exists for `membre`, `ressources`, `molecules`, and `constructions` INSERTs.

**Impact:** Any future schema change that adds a column in the middle of a table will corrupt registration data.

---

### [DATA-R1-035] LOW player.php:65-68 -- molecules INSERT creates exactly 4 rows per player, hardcoded

```php
dbExecute($base, 'INSERT INTO molecules VALUES(default, ..., 1, ?, default),
    (default, ..., 2, ?, default),
    (default, ..., 3, ?, default),
    (default, ..., 4, ?, default)', 'ssss', ...);
```

The number of molecule classes (4) is hardcoded in the INSERT rather than derived from a constant like `MAX_MOLECULE_CLASSES` or `$nbClasses`. If the game design ever changes to support more or fewer classes, this INSERT must be manually updated.

**Impact:** Maintenance burden. Low risk since the game has been stable at 4 classes for 15 years.

---

### [DATA-R1-036] LOW basicprivatephp.php:80 -- connectes table INSERT can fail on duplicate IP

```php
dbExecute($base, 'INSERT INTO connectes VALUES(?, ?)', 'si', $_SERVER['REMOTE_ADDR'], $now);
```

If two requests from the same IP arrive simultaneously and both see `nbre_entrees == 0`, both will try to INSERT, and the second will fail with a duplicate key error (if `ip` is a primary key). The code does not handle this error.

**Impact:** Minor -- error logged but no functional impact since the next request will find the row and UPDATE it.

---

### [DATA-R1-037] LOW player.php:824 -- statistiques.nbDerniere and tailleCarte reset to fixed values, ignoring number of active players

```php
dbExecute($base, 'UPDATE statistiques SET nbDerniere=0, tailleCarte=1');
```

After season reset, the map size starts at 1x1 regardless of how many players exist. As each player reconnects and gets placed via `coordonneesAleatoires()`, the map grows one slot at a time. With many players, the first few to connect will be clustered tightly, while later connections will be on the expanding edges. This creates unfair positioning where early reconnectors are always near each other.

**Impact:** Gameplay fairness issue. Not strictly a data integrity problem but affects game balance.

---

### [DATA-R1-038] LOW migrations/0002_fix_column_types.sql -- ALTER TABLE MODIFY can fail on data that doesn't fit new type

Several ALTER TABLE MODIFY statements change column types (e.g., `VARCHAR(500)` to `VARCHAR(255)`). If existing data exceeds the new size, the ALTER will either truncate silently (with strict mode off) or fail (with strict mode on). The migration does not check for oversized data before altering.

**Impact:** Data truncation or migration failure depending on SQL mode.

---

## Recommendations (Priority Order)

1. **CRITICAL-FIX:** Wrap `remiseAZero()` in a `withTransaction()` block (DATA-R1-001)
2. **CRITICAL-FIX:** Add missing table cleanups to `supprimerJoueur()`: actionsconstruction, prestige, sanctions, reponses (DATA-R1-002)
3. **CRITICAL-FIX:** Add missing table renames to comptetest.php transaction: prestige, attack_cooldowns (DATA-R1-004)
4. **CRITICAL-FIX:** Reorder `supprimerAlliance()` to set `idalliance=0` BEFORE deleting the alliance row (DATA-R1-005)
5. **HIGH-FIX:** Add guard against double victory-point award in season reset (DATA-R1-003)
6. **HIGH-FIX:** Use atomic `SET inscrits = inscrits + 1` in `inscrire()` and `supprimerJoueur()` (DATA-R1-007)
7. **HIGH-FIX:** Add missing columns to `remiseAZero()`: isotope, spec_combat, spec_economy, spec_research (DATA-R1-013, DATA-R1-014)
8. **HIGH-FIX:** Clean `cours` and `news` tables in `remiseAZero()` (DATA-R1-015, DATA-R1-016)
9. **MEDIUM-FIX:** Use atomic increments for moleculesPerdues updates (DATA-R1-025)
10. **MEDIUM-FIX:** Wrap marche.php resource transfer in a transaction (DATA-R1-030)
11. **MEDIUM-FIX:** Add IF NOT EXISTS to migration 0001 index statements (DATA-R1-021)
12. **LONG-TERM:** Evaluate adding FOREIGN KEY constraints to critical relationships (DATA-R1-032)
13. **LONG-TERM:** Convert all positional INSERT VALUES to explicit column-name INSERTs (DATA-R1-034)
