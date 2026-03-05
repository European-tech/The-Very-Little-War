# Pass 2 — Domain 7: Database Deep Dive Audit
## The Very Little War — MariaDB 10.11 / PHP 8.2

**Audit date:** 2026-03-05
**Auditor:** postgres-pro (adapted for MariaDB/mysqli)
**Pass:** 2 (Deep Analysis)
**Scope:** Full query-path tracing from PHP through DB helpers; every transaction boundary; every FOR UPDATE; all migrations; orphan paths; charset conflicts; deadlock surface; index coverage.

---

## Executive Summary

The codebase has undergone significant hardening. The core `database.php` abstraction is sound, prepared statements cover the vast majority of paths, and `withTransaction()` is used broadly. However, this deep pass reveals **36 discrete findings** spanning critical concurrency defects, structural transaction design errors, silent data-loss paths, migration idempotency failures, charset conflicts, and missing index coverage on high-frequency query patterns.

The two most urgent findings are:
1. **P2-D7-001 (CRITICAL):** The combat transaction uses raw `mysqli_begin_transaction`/`mysqli_commit` *outside* `withTransaction()`, and the `Error` base class (not caught by `catch(Exception)`) will bypass `mysqli_rollback`, leaving the database in a partially-written combat state.
2. **P2-D7-006 (CRITICAL):** `ajouterPoints()` type 1/2/3 issues `SELECT … FOR UPDATE` with no surrounding `mysqli_begin_transaction` / `withTransaction()` call, making the row lock a no-op and the read-modify-write a full TOCTOU race condition.

---

## Findings

---

### P2-D7-001 | CRITICAL | Combat transaction does not catch PHP `Error`; partial commit guaranteed on OOM or type error

- **Location:** `includes/game_actions.php:109-360`
- **Description:** The combat block uses raw `mysqli_begin_transaction($base)` / `try { ... } catch (Exception $combatException) { mysqli_rollback($base); }`. PHP 8 `Error` subclasses (e.g. `TypeError`, `DivisionByZeroError`, `ArithmeticError`, `OutOfMemoryError`, `ParseError`) are **not** caught by `catch (Exception $e)`. If `combat.php` triggers any `Error` — for example a `DivisionByZeroError` from a zero `hpPerMol` path that slips past the `$hpPerMol > 0` guards, or a memory exhaustion when building the large HTML report string for a high-molecule battle — execution unwinds without reaching `mysqli_rollback`. The transaction is left open. On InnoDB, an abandoned transaction is rolled back only when the connection closes (end of request). However, in this path `attaqueFaite` was already set to 1 via a separate CAS `UPDATE … SET attaqueFaite=1` *before* the transaction began (line 90-94). That CAS update is **auto-committed** (it is outside any transaction). So after the Error, the action is permanently marked done but no combat result, resource changes, or reports are written. The combat silently disappears.
- **Impact:** Permanent silent data loss. Attacker's molecules are consumed (decay was applied inside the aborted transaction), defender molecules untouched, no report generated, no resource transfer. Repeatable whenever the HTML report building hits an edge case.
- **Fix:**
  ```php
  // Replace:
  mysqli_begin_transaction($base);
  try { ... } catch (Exception $combatException) { mysqli_rollback($base); }

  // With: use withTransaction() which should be updated to catch Throwable:
  // In includes/database.php, change:
  // catch (Exception $e) -> catch (\Throwable $e)
  ```
  And refactor the combat block to use `withTransaction()`. Also move the CAS `UPDATE attaqueFaite=1` *inside* the transaction so it rolls back on failure.

---

### P2-D7-002 | CRITICAL | CAS `attaqueFaite=1` committed before combat transaction begins — creates permanent zombie actions on Error

- **Location:** `includes/game_actions.php:90-94` and `109`
- **Description:** The CAS guard that marks a combat as "claimed" (`UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0`) executes and auto-commits **before** `mysqli_begin_transaction($base)` on line 109. If the subsequent transaction throws any uncaught `Throwable`, the row permanently has `attaqueFaite=1` but `tempsRetour` was never set (the return-trip check on line 484 fires on `tempsRetour < time()` for the attacker). The row remains in `actionsattaques` indefinitely because neither the commit path (which deletes it) nor the rollback path (which never ran) cleaned it up.
- **Impact:** Zombie action rows accumulate. The attacker's molecules that were sent are never returned. The attacker sees a permanent pending attack with no resolution.
- **Fix:** Move the CAS guard inside `withTransaction()`. Use a single atomic `SELECT … FOR UPDATE` → validate → proceed pattern as already used in the formation processing (line 49-80). This ensures the `attaqueFaite=1` mark rolls back if anything fails.

---

### P2-D7-003 | CRITICAL | `withTransaction()` catches only `Exception`, not `\Throwable` — PHP 8 `Error` bypasses rollback everywhere

- **Location:** `includes/database.php:117`
- **Description:** `catch (Exception $e)` does not catch PHP 8's `Error` hierarchy (`TypeError`, `ValueError`, `ArithmeticError`, `DivisionByZeroError`, `OutOfMemoryError`). Every `withTransaction()` call site in the application is therefore susceptible to partial commit on any PHP engine-level error. This affects: `inscrire()` (partial player creation), `supprimerJoueur()` (partial deletion with dangling FK rows), `remiseAZero()` (partial season reset leaving mixed old/new state), `synthesizeCompound()`, `activateCompound()`, `augmenterBatiment()`, all market transactions, and all formation/construction transactions.
- **Impact:** Application-wide. Any PHP `Error` inside a transaction leaves the database in an undefined partially-written state. On a season reset this would be catastrophic.
- **Fix:**
  ```php
  // includes/database.php line 117:
  } catch (\Throwable $e) {   // was: Exception
      mysqli_rollback($base);
      throw $e;
  }
  ```

---

### P2-D7-004 | CRITICAL | `ajouterPoints()` types 1/2/3 issue `FOR UPDATE` outside any transaction — row lock is a no-op TOCTOU

- **Location:** `includes/player.php:77`
- **Description:** `ajouterPoints($nb, $joueur, $type)` for types 1 (attack points), 2 (defense points), and 3 (pillage points) does:
  ```php
  $points = dbFetchOne($base, 'SELECT * FROM autre WHERE login=? FOR UPDATE', 's', $joueur);
  // ... compute new value ...
  dbExecute($base, 'UPDATE autre SET pointsAttaque=? WHERE login=?', 'ds', $newPoints, $joueur);
  ```
  `FOR UPDATE` only acquires a lock when inside an active InnoDB transaction. Since `ajouterPoints()` is called directly from `combat.php` (lines 621-623) which is `include()`d inside the raw `mysqli_begin_transaction` block in `game_actions.php`, the `FOR UPDATE` *does* operate within a transaction in that specific path. However, `ajouterPoints()` is also called from other locations (e.g. construction completion callbacks) where no transaction is active. In those cases `FOR UPDATE` is silently ignored by InnoDB (treated as a plain `SELECT`), and the subsequent `UPDATE` is a classic read-modify-write race. Two concurrent requests can both read the same value and both write incremented values, with one overwriting the other.
- **Impact:** Points double-awarded or lost under concurrent load. Particularly dangerous for prestige points and pillage totals which affect rankings.
- **Fix:** `ajouterPoints()` should use atomic `UPDATE autre SET pointsAttaque = GREATEST(0, pointsAttaque + ?) WHERE login=?` for the common case, or be exclusively called from within a transaction that already holds the row lock.

---

### P2-D7-005 | HIGH | `inscrire()` reads `statistiques.inscrits` counter outside the transaction — counter race condition

- **Location:** `includes/player.php:31-32` and `50-60`
- **Description:** `inscrire()` reads `$nbinscrits = $data1['inscrits'] + 1` before calling `withTransaction()`. Two concurrent registrations will both read the same value of `inscrits`, both compute `+1`, and both write the same incremented count. The `statistiques` table will be off by 1 (or more) per concurrent registration pair. The actual `INSERT INTO membre` happens inside the transaction, so player records are correct — only the displayed registration count is wrong.
- **Impact:** `statistiques.inscrits` drifts below actual player count. Minor cosmetic issue but reflects a design pattern that could be applied to more critical counters.
- **Fix:** Move the read inside the transaction, or better: `UPDATE statistiques SET inscrits = inscrits + 1` atomically.

---

### P2-D7-006 | HIGH | `supprimerJoueur()` deletes from `autre` before `membre` — FK violation possible when migration 0018 is applied

- **Location:** `includes/player.php:762-763`
- **Description:** `supprimerJoueur()` deletes in this order: `vacances` → `autre` → `membre` → `ressources` → `molecules` → `constructions` → .... With FK migration 0018 applied (which adds `fk_autre_login REFERENCES membre(login) ON DELETE CASCADE`), deleting `autre` before `membre` is harmless because the FK goes from `autre` to `membre`. However, the function also deletes `membre` on line 763 which would cascade-delete `autre` again — this is harmless but wastes an operation. More critically: `delete FROM attack_cooldowns WHERE login=?` on line 776 uses column `login` which does **not exist** in the `attack_cooldowns` table. That table has columns `attacker` and `defender`, not `login`. This query silently fails (returns 0 affected rows) and leaves cooldown rows referencing the deleted player. With FK constraints from migration 0018, `attack_cooldowns.attacker` has `ON DELETE CASCADE`, so the cooldown is eventually cleaned up by the FK — but only if migration 0018 was applied. If it was not, the rows are permanently orphaned.
- **Impact:** Cooldown rows referencing deleted players pollute the table. After player deletion, a different player with the same username (re-registration) would inherit old cooldowns. The bogus `WHERE login=?` query silently does nothing.
- **Fix:**
  ```php
  // Line 776 — wrong column name:
  dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?', 'ss', $joueur, $joueur);
  // Also in supprimerAlliance() line 743:
  dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?', 'ss', $member['login'], $member['login']);
  ```

---

### P2-D7-007 | HIGH | `supprimerJoueur()` does not delete from `login_history` or `account_flags` — orphaned audit trail

- **Location:** `includes/player.php:760-784`
- **Description:** When a player is deleted, `supprimerJoueur()` does not clean up `login_history` (migration 0020) or `account_flags` (migration 0021). These tables have no FK to `membre`. After deletion, `login_history` retains all IP/fingerprint/UA records for the deleted login. `account_flags` retains all suspicion flags (including those where the deleted player is `related_login`). The `checkSameIpAccounts()` function queries `login_history` for IPs shared with the deleted login — those records will trigger false flags against future players from the same IP.
- **Impact:** False multi-account detections triggered long after player deletion. Admin alert queue polluted. Privacy concern — deleted accounts retain PII (IP addresses, user agent strings) indefinitely.
- **Fix:**
  ```php
  // Add to supprimerJoueur() transaction:
  dbExecute($base, 'DELETE FROM login_history WHERE login=?', 's', $joueur);
  dbExecute($base, 'DELETE FROM account_flags WHERE login=? OR related_login=?', 'ss', $joueur, $joueur);
  dbExecute($base, 'DELETE FROM player_compounds WHERE login=?', 's', $joueur);
  // admin_alerts has no player reference, skip
  ```

---

### P2-D7-008 | HIGH | `remiseAZero()` does not reset `player_compounds` or `login_history` — compounds survive season reset

- **Location:** `includes/player.php:923-971`
- **Description:** `remiseAZero()` (called from `performSeasonEnd()`) resets all game state but does not delete from `player_compounds`. Players' synthesized compounds (which provide timed combat/production buffs) survive the season reset and remain active at the start of the new season. Additionally, `login_history` grows unbounded across seasons — there is no purge of old records. Over multiple seasons this table will reach millions of rows.
- **Impact:** Balance exploit: players who craft compounds just before season end get a head start in the new season. `login_history` unbounded growth degrades detection query performance and wastes disk.
- **Fix:**
  ```php
  // In remiseAZero() transaction, add:
  dbExecute($base, 'DELETE FROM player_compounds');
  // For login_history, add age-based cleanup:
  dbExecute($base, 'DELETE FROM login_history WHERE timestamp < ?', 'i', time() - (90 * 86400)); // 90 days
  ```

---

### P2-D7-009 | HIGH | Formation action: `molecules` table read without `FOR UPDATE` inside transaction — stale read TOCTOU

- **Location:** `includes/game_actions.php:54`
- **Description:** Inside the formation processing transaction (which correctly locks `actionsformation` with `FOR UPDATE`), the molecule record is fetched without a lock:
  ```php
  $molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse']);
  ```
  Then `molecules.nombre` is updated:
  ```php
  dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'ds', ($molecule['nombre'] + $formed), $actions['idclasse']);
  ```
  This is a read-modify-write without locking the `molecules` row. If `updateRessources()` is concurrently decaying molecules (it directly `UPDATE molecules SET nombre=?` on line 231 of `game_resources.php`), the molecule count can be corrupted. Specifically: Formation reads `nombre=100`, decay writes `nombre=95`, formation writes `nombre=100+formed` — the decay is undone.
- **Impact:** Molecule decay bypassed when molecule formation completes concurrently with resource update. Players accumulate molecules that should have decayed.
- **Fix:**
  ```php
  $molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=? FOR UPDATE', 's', $actions['idclasse']);
  // Then use atomic increment instead:
  dbExecute($base, 'UPDATE molecules SET nombre = nombre + ? WHERE id=?', 'di', $formed, $actions['idclasse']);
  ```

---

### P2-D7-010 | HIGH | `updateRessources()` molecule decay uses read-modify-write without transaction or locks

- **Location:** `includes/game_resources.php:226-236`
- **Description:** The molecule decay loop in `updateRessources()` fetches all molecules with a plain `SELECT *` (no lock), computes decay, then issues individual `UPDATE molecules SET nombre=? WHERE id=?` with the pre-computed absolute value. This is a classic TOCTOU: if two requests overlap (attacker page loads, defender page loads simultaneously), both read the same `nombre` value, both compute decay from it, and the second write overwrites the first — effectively applying decay only once instead of twice for the combined time. Worse, if a formation completes between the SELECT and the UPDATE, the formation's molecules are lost (overwritten by the stale pre-formation count).
- **Impact:** Molecule counts become incorrect under any concurrent load. More serious: newly formed molecules can be silently deleted when decay overwrites with a stale pre-formation count.
- **Fix:** Use atomic relative updates: `UPDATE molecules SET nombre = GREATEST(0, nombre * ?)` with a decay coefficient, rather than computing absolute values from a stale read. This eliminates the TOCTOU entirely.

---

### P2-D7-011 | HIGH | `actionsenvoi` (resource transfer) processing has no transaction and no FOR UPDATE — double-credit race

- **Location:** `includes/game_actions.php:526-600`
- **Description:** The `actionsenvoi` processing block (lines 526-600) deletes the action row first (`DELETE FROM actionsenvoi WHERE id=?` on line 529), then reads recipient resources (line 567), then updates recipient resources (line 599). The DELETE-before-read pattern is an informal CAS guard, but the subsequent resource update is a read-modify-write without any transaction or lock. Specifically: the recipient resources are fetched on line 567, calculated on lines 578-598, and written on line 599. If two concurrent requests both call `updateActions()` for the sender (or receiver), the DELETE on line 529 will correctly prevent double-deletion of the action, but the resource credit can race: request A reads resources, request B reads resources (same values), request A writes, request B writes the same stale base + received amount — only one transfer's worth credited instead of two (or the second write corrupts the first).
- **Impact:** In normal gameplay this is unlikely (one player rarely triggers simultaneous sessions), but on high concurrency it can cause resource amounts to be wrong after transfers.
- **Fix:** Wrap the entire envoi processing block in `withTransaction()`, add `FOR UPDATE` on both the action row and the recipient resources row.

---

### P2-D7-012 | HIGH | Market buy/sell transactions use raw `mysqli_prepare` inside `withTransaction()` — bypasses `dbExecute` error logging

- **Location:** `marche.php:192-198` (buy) and `marche.php:313-318` (sell)
- **Description:** Inside `withTransaction()` closures for market transactions, the resource UPDATE uses raw `mysqli_prepare` / `mysqli_stmt_bind_param` / `mysqli_stmt_execute` / `mysqli_stmt_close`. This bypasses `dbExecute()`'s error logging. If `mysqli_stmt_execute()` returns false (e.g., CHECK constraint violation from migration 0017, or deadlock), the failure is silently swallowed — `$stmt` is closed but no exception is thrown, no error logged. The transaction then continues and commits with the resource update having silently failed, creating phantom energy: the price record was inserted into `cours` but the player's energy was not actually deducted.
- **Impact:** If the resource UPDATE silently fails, the player receives atoms for free (price is recorded, but energy deduction did not commit). Extremely unlikely with current constraints but represents a structural design failure.
- **Fix:** Replace both raw `mysqli_prepare` blocks with `dbExecute()` calls. The column name is already validated against the `$nomsRes` whitelist, so parameterization is safe:
  ```php
  dbExecute($base, "UPDATE ressources SET energie=?, {$nomsRes[$numRes]}=? WHERE login=?", 'dds', $diffEnergieAchat, $newResVal, $_SESSION['login']);
  ```

---

### P2-D7-013 | HIGH | `inscrire()` does not insert into `prestige` table — new players have no prestige row until season end

- **Location:** `includes/player.php:50-60`
- **Description:** `inscrire()` inserts into `membre`, `autre`, `ressources`, `molecules`, and `constructions` but not into `prestige`. The `prestige` table was populated by migration 0007 for existing players (`INSERT IGNORE INTO prestige (login) SELECT login FROM membre`), but new registrations after that migration are not covered. Any code path that reads `prestige` for a new player will get `null` from `dbFetchOne`. The `unlockPrestige()` function (in `includes/prestige.php`) calls `dbFetchOne(... FROM prestige WHERE login=? FOR UPDATE ...)` and if it returns null, it returns an error string "Données de prestige introuvables." — preventing new players from ever unlocking prestige benefits unless the admin manually runs the INSERT.
- **Impact:** All players registered after migration 0007 have no prestige row. They cannot use prestige unlocks, and `prestigeCombatBonus()` / `prestigeProductionBonus()` that query prestige will fail silently (returning defaults) depending on implementation. The `awardPrestigePoints()` function in `performSeasonEnd()` may also skip them.
- **Fix:**
  ```php
  // Add to the withTransaction block in inscrire():
  dbExecute($base, 'INSERT IGNORE INTO prestige (login, total_pp, unlocks) VALUES (?, 0, ?)', 'ss', $safePseudo, '');
  ```

---

### P2-D7-014 | HIGH | `remiseAZero()` string interpolation in UPDATE ressources builds dynamic SQL without column validation

- **Location:** `includes/player.php:938-947`
- **Description:** `remiseAZero()` builds:
  ```php
  $chaine = "";
  foreach ($nomsRes as $num => $ressource) {
      $chaine = $chaine . '' . $ressource . '=default' . $plus;
  }
  $sql = 'UPDATE ressources SET energie=default, terrain=default, revenuenergie=default, niveauclasse=1, ' . $chaine . '';
  dbExecute($base, $sql);
  ```
  While `$nomsRes` is defined in config and not user-controlled, there is no whitelist validation here, unlike the whitelist check in `updateRessources()` on line 196-198. If `$nomsRes` were ever manipulated (e.g., via a config injection, or a future developer adding a new entry carelessly), arbitrary column names could be interpolated into SQL. This is a defense-in-depth gap rather than an immediate injection risk.
- **Impact:** Low direct risk but structural inconsistency — the same pattern that caused audit findings in other modules is present in the season reset path.
- **Fix:** Add the same whitelist check present in `updateRessources()`:
  ```php
  $allowedColumns = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
  foreach ($nomsRes as $num => $ressource) {
      if (!in_array($ressource, $allowedColumns, true)) throw new \RuntimeException("Invalid column: $ressource");
      ...
  }
  ```

---

### P2-D7-015 | HIGH | `performSeasonEnd()` Phase 2 (`remiseAZero`) runs outside Phase 1 transaction — partial season state possible

- **Location:** `includes/player.php:816-920` and `923-971`
- **Description:** `performSeasonEnd()` runs in two separate transactions:
  - Phase 1: Archives rankings, awards VP, awards prestige (one transaction)
  - Phase 2: `remiseAZero()` resets all game state (separate transaction)

  If Phase 1 commits but Phase 2 fails (e.g., disk full during `DELETE FROM rapports` on a large table, or a `\Throwable` that bypasses the rollback per P2-D7-003), the database is in a state where VP and prestige were awarded for the new season but old game data (molecules, resources, buildings, actions) still exists. Players logging in would see their old armies and resources alongside new prestige awards. Worse, the `statistiques.debut` update and the winner news INSERT on lines 910-917 also run **outside** both transactions.
- **Impact:** Catastrophic if a crash occurs mid-season-reset. Results in an undefined game state mixing old season data with new season awards.
- **Fix:** The two-phase design exists for valid reasons (very large DELETE operations might lock tables too long inside one transaction). However, at minimum the `debut` UPDATE and news INSERT should be inside Phase 2. A migration table or `statistiques.maintenance` flag should record the phase number so interrupted resets can be resumed. Consider a lock table or `GET_LOCK()` advisory lock for the entire reset operation.

---

### P2-D7-016 | HIGH | `checkTimingCorrelation()` uses non-sargable `ABS(a.timestamp - b.timestamp) < 300` join — Cartesian product risk

- **Location:** `includes/multiaccount.php:221`
- **Description:** The timing correlation query:
  ```sql
  SELECT COUNT(*) AS cnt
  FROM login_history a
  INNER JOIN login_history b ON ABS(a.timestamp - b.timestamp) < 300
  WHERE a.login = ? AND b.login = ? AND a.timestamp > ?
  ```
  The join condition `ABS(a.timestamp - b.timestamp) < 300` is non-sargable — it cannot use an index. InnoDB will perform a nested-loop scan: for each row in `a` (all logins for player A in 30 days), it scans all rows in `b` (all logins for player B in 30 days). With two highly active players each having 1000 logins in 30 days, this executes 1,000,000 comparisons per call. `checkTimingCorrelation()` is called on every login event. If `login_history` grows to typical size (potentially 50k+ rows per active season), this query will cause multi-second response times.
- **Impact:** DoS via login: a high-frequency bot or automated test will trigger this O(n²) join repeatedly. Page load times degrade for all players whenever any flagged-account player logs in.
- **Fix:** Rewrite using a range join that MariaDB can optimize:
  ```sql
  SELECT COUNT(*) AS cnt
  FROM login_history a
  INNER JOIN login_history b
    ON b.login = ?
    AND b.timestamp BETWEEN a.timestamp - 300 AND a.timestamp + 300
  WHERE a.login = ? AND a.timestamp > ?
  ```
  Also add composite index: `CREATE INDEX idx_login_history_login_ts ON login_history (login, timestamp)`.

---

### P2-D7-017 | HIGH | `prestige` table created with `VARCHAR(50)` login PK — truncates logins > 50 chars; FK incompatible

- **Location:** `migrations/0007_add_prestige_table.sql:3`
- **Description:** The initial prestige table creation uses `login VARCHAR(50) PRIMARY KEY`. The `membre` table's `login` column was widened to `VARCHAR(255)` by migration 0002. Migration 0015 and 0022 both run `ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL` to fix this, but these corrections are applied as separate later migrations. Any login between 51 and 255 characters that was registered between migration 0007 and 0015 application would have had their prestige insert silently truncated or rejected, depending on MariaDB's `sql_mode`. With `STRICT_TRANS_TABLES` (MariaDB default), the INSERT would fail and be silently swallowed by `INSERT IGNORE`. Such players would have no prestige row permanently.
- **Impact:** Players with logins 51-255 chars registered during the window have no prestige row. This is a historical data integrity issue that cannot be automatically repaired.
- **Fix:** Verify with: `SELECT login FROM membre WHERE LENGTH(login) > 50 AND login NOT IN (SELECT login FROM prestige);` and manually insert prestige rows for any affected players.

---

### P2-D7-018 | HIGH | `attack_cooldowns` table has no FK to `membre` in migration 0004, only in 0013 — orphan rows accumulate in old deployments

- **Location:** `migrations/0004_add_attack_cooldowns.sql` vs `migrations/0013_myisam_to_innodb_and_charset.sql`
- **Description:** Migration 0004 creates `attack_cooldowns` with `attacker VARCHAR(50)` and `defender VARCHAR(50)`. Migration 0013 widens these to `VARCHAR(255)`. Migration 0018 adds `fk_cooldowns_attacker` FK. Between 0004 and 0018, there are no FK constraints. Players deleted during this window leave orphaned cooldown rows. More importantly, the FK added in 0018 only covers `attacker`, not `defender` — if the defender is deleted, their cooldown rows against the deleted player remain. These can never match a real player again but sit in the table forever.
- **Impact:** Orphaned cooldown rows for deleted defenders accumulate indefinitely. Not a game-correctness issue since the cooldown lookup is `WHERE attacker=? AND defender=?` (both must match), but represents table bloat and a schema design gap.
- **Fix:** Add FK for defender as well: `ADD CONSTRAINT fk_cooldowns_defender FOREIGN KEY (defender) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE`.

---

### P2-D7-019 | HIGH | Deadlock potential: combat path locks `molecules` then `autre`, construction path locks `autre` then `molecules`

- **Location:** `includes/game_actions.php:113-360` (combat) and `includes/game_actions.php:30-41` (construction)
- **Description:** The lock acquisition order differs between combat and construction processing in `updateActions()`:

  - **Combat path (lines 109+):** `mysqli_begin_transaction` → lock `actionsattaques` (implicit via UPDATE) → lock `molecules` (line 113 SELECT inside transaction) → lock `autre` (via `ajouterPoints` SELECT FOR UPDATE line 77) → lock `ressources` (line 368) → lock `constructions` (line 452).

  - **Construction path (lines 29-41):** `withTransaction` → lock `actionsconstruction` → calls `augmenterBatiment()` which calls `ajouterPoints()` which locks `autre` → then potentially locks `molecules` via `initPlayer()`.

  - **Resource update (`updateRessources`):** locks `autre` (line 169 via affected_rows check) → locks `molecules` (line 222 SELECT).

  If player A's request triggers combat (locking `molecules` for A, then trying to lock `autre`) while player B's request triggers construction completion for player A (which `augmenterBatiment` calls, locking `autre` first), InnoDB will detect a deadlock and roll back one transaction. This is survivable but causes a failed page load and error log noise.
- **Impact:** Deadlock-induced rollbacks visible as errors in logs and as failed page loads for users. Under high load this could be frequent.
- **Fix:** Standardize lock acquisition order globally: always lock in the sequence `membre` → `autre` → `ressources` → `constructions` → `molecules` → action tables. Document this ordering in a code comment at the top of `database.php`.

---

### P2-D7-020 | MEDIUM | `revenuEnergie()` makes 7 separate DB queries per invocation with no connection between them — N+1 within a single function

- **Location:** `includes/game_resources.php:18-78`
- **Description:** `revenuEnergie()` issues the following independent queries for each call:
  1. `SELECT * FROM constructions WHERE login=?` (line 18)
  2. `SELECT producteur FROM constructions WHERE login=?` (line 25) — duplicate of #1
  3. `SELECT idalliance, totalPoints FROM autre WHERE login=?` (line 27)
  4. `SELECT duplicateur FROM alliances WHERE id=?` (line 30)
  5. `SELECT iode, nombre FROM molecules WHERE proprietaire=? AND numeroclasse=?` (line 37) × 4 iterations = 4 queries
  6. `SELECT energieDepensee FROM autre WHERE login=?` (line 44) — duplicate of #3
  7. `SELECT x, y FROM membre WHERE login=?` (line 61)
  Total: at minimum 10 queries, 11 if alliance exists. This function is called multiple times per page load (from `initPlayer()`, construction cost display, etc.) and has a per-player-per-detail cache but not a cross-call cache. Queries #1 and #2 fetch the same table with different column lists.
- **Impact:** Each page load triggers 30-50+ identical DB queries to `constructions` and `autre` for the logged-in player. On a 100-player active session this is 3000-5000 queries per second to the same two tables.
- **Fix:** Merge queries #1 and #2 into one. Pass the already-fetched `$constructions` and `$autre` row from `initPlayer()` as parameters rather than re-fetching. The static cache already exists; extend it to cover multi-call scenarios within the same request.

---

### P2-D7-021 | MEDIUM | `getResourceNodeBonus()` has a request-scoped static cache that persists stale data after season reset within the same PHP process

- **Location:** `includes/resource_nodes.php:87-95`
- **Description:** `static $nodesCache = null` is set to `null` only once per PHP process lifetime. In PHP-FPM, workers are persistent across requests. The first request after season reset will populate `$nodesCache` with the new nodes. However, if a long-lived worker handled the reset request (which calls `generateResourceNodes()` and then returns), the same worker's `$nodesCache` (null) will be repopulated on the next request. This is actually fine in the common case. The stale cache issue arises if: a worker loads nodes in request A, then the season resets in request B (a different worker), then request A's worker handles request C — it still holds old nodes in `$nodesCache`. Since season resets happen at most once per month, this is a low-frequency window (one stale worker handling one request). Not a critical bug.
- **Impact:** A single request after season reset may use old node positions for production bonus calculations. Impact is a few wrong production ticks at season boundary.
- **Fix:** Add a generation counter or timestamp to `statistiques` and invalidate the cache when it mismatches: `if ($nodesCache !== null && $nodesCache['_generation'] !== $currentGeneration) $nodesCache = null;`.

---

### P2-D7-022 | MEDIUM | `migration.php` uses `mysqli_multi_query()` — does not wrap each migration in a transaction; partial migration leaves inconsistent schema

- **Location:** `migrations/migrate.php:43-53`
- **Description:** `migrate.php` uses `mysqli_multi_query()` to execute each migration SQL file. There is no `BEGIN TRANSACTION` / `COMMIT` / `ROLLBACK` wrapping each migration. If a migration file contains multiple statements and one fails mid-way (e.g., `ALTER TABLE` succeeds but a subsequent `INSERT INTO migrations` fails), the schema is partially modified without being recorded. The migration runner would attempt to re-apply it on the next run (since it's not in the `migrations` table), causing duplicate index errors or other DDL conflicts.
- **Impact:** A failed mid-migration leaves the schema in an inconsistent state that is difficult to detect and fix. Repeated migration runs will fail with duplicate key errors.
- **Fix:** Wrap each migration execution in a transaction where possible (DDL is auto-committed in MariaDB, but DML within the migration can be transactional). At minimum, record the migration entry in the `migrations` table atomically with error detection. Add error-on-failure rather than `exit(1)` only — currently the runner continues if `mysqli_errno` is checked but `mysqli_next_result()` may consume errors silently.

---

### P2-D7-023 | MEDIUM | Migration 0015 and 0026 both add `idx_totalPoints` on `autre` — duplicate index on re-run

- **Location:** `migrations/0015_fix_schema_issues.sql:11` and `migrations/0026_add_totalpoints_index.sql:3`
- **Description:** Migration 0015 adds: `ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_totalPoints (totalPoints);`. Migration 0026 adds: `ALTER TABLE autre ADD INDEX idx_totalPoints (totalPoints);` (without `IF NOT EXISTS`). If both migrations are applied (which they should be if following the standard migration runner), migration 0026 will fail with `Duplicate key name 'idx_totalPoints'`. The migration runner checks `mysqli_errno()` and calls `exit(1)` on error — so the entire migration run stops at migration 0026 permanently.
- **Impact:** Migration runner is broken for any fresh database that applies all migrations in sequence. Running `php migrate.php` on a new installation will exit at migration 0026 with an error.
- **Fix:** Change migration 0026 to use `IF NOT EXISTS`:
  ```sql
  ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_totalPoints (totalPoints);
  ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_idalliance (idalliance);
  ```

---

### P2-D7-024 | MEDIUM | Migration 0016 (`connectes_primary_key`) is not idempotent — re-running drops a non-existent index and fails

- **Location:** `migrations/0016_connectes_primary_key.sql`
- **Description:** Migration 0016 contains:
  ```sql
  DROP INDEX idx_connectes_ip ON connectes;
  ALTER TABLE connectes ADD PRIMARY KEY (ip);
  ```
  If this migration is re-run (e.g., after the migration table was accidentally dropped, or during development reset), `DROP INDEX idx_connectes_ip` will fail because the index no longer exists after the first run (it was replaced by the PRIMARY KEY). This causes the migration runner to exit with error code 1.
- **Impact:** Migration runner non-idempotent. Re-running migrations on a partially applied database fails immediately.
- **Fix:** Use `DROP INDEX IF EXISTS idx_connectes_ip ON connectes;` and `ALTER TABLE connectes ADD PRIMARY KEY IF NOT EXISTS (ip);`.

---

### P2-D7-025 | MEDIUM | Migration 0013 runs `DELETE FROM attack_cooldowns WHERE expires < UNIX_TIMESTAMP()` as data mutation in schema migration — wrong for re-runs

- **Location:** `migrations/0013_myisam_to_innodb_and_charset.sql:27`
- **Description:** Schema migrations should contain only DDL (schema changes) and be idempotent. Migration 0013 includes a data cleanup `DELETE` statement. This is fine on the first run, but if rerun (for any reason), it deletes legitimate cooldowns. More importantly, it runs at migration-apply time, which may be during an active game session — deleting active cooldowns that players rely on to be protected from repeated attacks.
- **Impact:** Active attack cooldowns deleted if migration is rerun during an active session. Schema migrations should not mutate live game data.
- **Fix:** Move the `DELETE` to a separate maintenance script or cron job. Remove it from the migration file.

---

### P2-D7-026 | MEDIUM | Charset conflict: `player_compounds` created with `latin1`, `membre.login` is effectively `utf8mb4` — FK join collation mismatch

- **Location:** `migrations/0024_create_compounds.sql:13`
- **Description:** `player_compounds` is created with `DEFAULT CHARSET=latin1`. The `login` column has an FK to `membre(login)`. The `membre` table's `login` column was modified by migration 0018 with `MODIFY login VARCHAR(255) NOT NULL` — the charset inherits from the table's default, which was already `utf8mb4` after earlier charset fixes, or `latin1` if the original table was never converted. Additionally, `connexion.php` sets the connection charset to `utf8mb4` (line 20). When a JOIN or FK lookup is performed between a `latin1` column and the connection's `utf8mb4` charset, MariaDB may use a full-table scan (no index) because the collations are incompatible. Compound queries will be slower than expected and may produce incorrect results for logins containing non-ASCII characters (accented characters in usernames).
- **Impact:** Index inefficiency on FK lookups between `player_compounds.login` and `membre.login`. Potential data corruption for logins with accented characters (accent stripped by latin1 truncation, creating silent mismatches). The memory note states "new tables MUST use latin1 for FK compatibility with membre" but this was an outdated note — `membre.login` was converted to `utf8mb4` in migration 0002/0018.
- **Fix:** Alter `player_compounds` to use `utf8mb4`:
  ```sql
  ALTER TABLE player_compounds CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

---

### P2-D7-027 | MEDIUM | `account_flags` and `login_history` tables have no FK to `membre` — orphan rows accumulate silently

- **Location:** `migrations/0020_create_login_history.sql`, `migrations/0021_create_account_flags.sql`
- **Description:** `login_history.login` and `account_flags.login` / `account_flags.related_login` have no FK constraints referencing `membre`. These are intentionally left without FKs (for audit preservation), but the lack of FK means there is no automated cleanup on player deletion (covered in P2-D7-007), and no referential integrity check that prevents inserting records for non-existent players. A bug in `logLoginEvent()` that supplies a wrong login (e.g., an empty string from a failed session lookup) would insert a record silently.
- **Impact:** Unreferenced audit records. Detection algorithms produce false positives for deleted-player IPs.
- **Fix:** For `login_history`, the deliberate no-FK design is acceptable for audit purposes, but add a cleanup trigger or scheduled purge. Document explicitly that the table outlives the player record by design.

---

### P2-D7-028 | MEDIUM | `recalculerStatsAlliances()` has no transaction — concurrent player point updates can corrupt totals

- **Location:** `includes/player.php:713-733`
- **Description:** `recalculerStatsAlliances()` iterates all alliances, fetches all members' points, sums them, and writes the total. This read-aggregate-write is not wrapped in a transaction. If any player's points change between the `SELECT * FROM autre WHERE idalliance=?` fetch and the `UPDATE alliances SET pointstotaux=?` write, the written total is inconsistent. This function is called from multiple places including after combat point updates.
- **Impact:** Alliance rankings can show stale or incorrect totals. In combat-heavy sessions where many players' points update simultaneously, the alliance leaderboard can be persistently wrong.
- **Fix:** Wrap in `withTransaction()`. Alternatively, use a computed column or trigger to maintain `alliances.pointstotaux` atomically as individual player totals change. At minimum, the function should run inside the same transaction as the combat point updates.

---

### P2-D7-029 | MEDIUM | `cours` table (market prices) cleanup uses unsafe `LIMIT … OFFSET` pattern — may delete wrong rows if gaps exist

- **Location:** `includes/game_resources.php:271-273`
- **Description:**
  ```php
  $keepId = dbFetchOne($base, 'SELECT id FROM cours ORDER BY id DESC LIMIT 1 OFFSET ' . MARKET_HISTORY_LIMIT);
  if ($keepId) {
      dbExecute($base, 'DELETE FROM cours WHERE id < ?', 'i', $keepId['id']);
  }
  ```
  `MARKET_HISTORY_LIMIT` is a PHP constant (1000) interpolated directly into SQL. This is safe since it's a compile-time constant, not user input. However, the logic is subtly wrong: it deletes all rows with `id < keepId`, but auto-increment IDs have gaps (after deletes). If 1200 rows exist with non-sequential IDs (e.g., rows 1-800 and 1001-1400 after earlier deletions), the OFFSET-based selection picks the 1000th row from the top, which may not be row 200. The rows actually deleted may be fewer or more than `total - MARKET_HISTORY_LIMIT`. Additionally, `LIMIT … OFFSET` on a large table without a covering index for the sort key is slow.
- **Impact:** Market history may not be trimmed accurately. Minor — does not affect game correctness, only disk usage.
- **Fix:**
  ```php
  // More precise: delete rows older than N rows by count
  dbExecute($base, 'DELETE FROM cours WHERE id <= (SELECT id FROM (SELECT id FROM cours ORDER BY id DESC LIMIT 1 OFFSET ?) sub)', 'i', MARKET_HISTORY_LIMIT);
  ```
  Or better: use a dedicated cleanup job that runs after season reset (when the table is already deleted by `remiseAZero()`).

---

### P2-D7-030 | MEDIUM | `dbQuery()` / `dbFetchOne()` return `false` on prepare error but callers check for `null` — silent query failures

- **Location:** `includes/database.php:13-16` and `34-36`
- **Description:** `dbQuery()` returns `false` (not `null`) when `mysqli_prepare()` fails. `dbFetchOne()` calls `dbQuery()` and checks `if (!$result) return null;` — this correctly handles `false` (falsy). However, many call sites check `if (!$row)` or `if ($row)` which also handles null/false correctly. The structural issue is that `dbExecute()` returns `false` on prepare failure and calling code often checks `if ($result === false)` or `if ($affected === 0 || $affected === false)`. But code that checks `$affected > 0` without explicit false-check will treat a prepare error (false) as success (false > 0 is false, so it proceeds as "0 rows affected" which may be wrong). Specifically: `ajouterPoints()` type 0 checks `if (mysqli_affected_rows($base) > 0)` after `dbExecute()` — if `dbExecute()` returned false (prepare error), `mysqli_affected_rows()` on the previous statement is undefined.
- **Impact:** Silent query failures. A prepare error in `ajouterPoints()` would not award points but also would not raise an error — the caller silently proceeds.
- **Fix:** `dbExecute()` should throw an exception (not just log and return false) on prepare failure, or callers should explicitly check `=== false`. The function should also check `$stmt === false` before proceeding to `mysqli_stmt_bind_param`.

---

### P2-D7-031 | MEDIUM | `connexion.php` sets charset to `utf8mb4` but legacy tables may remain `latin1` — collation index bypass

- **Location:** `includes/connexion.php:20`
- **Description:** `mysqli_set_charset($base, 'utf8mb4')` sets the connection character set. When PHP sends a query joining a `utf8mb4` connection against a `latin1` column (e.g., `membre.login` in older deployments before charset migrations), MariaDB must coerce the comparison. Depending on the target collation (`latin1_swedish_ci` vs `utf8mb4_unicode_ci`), the index on `membre.login` may be bypassed for `WHERE login=?` lookups, resulting in full table scans. This specifically affects the most common query in the entire application.
- **Impact:** Full table scan on `membre` for every player lookup if charset coercion prevents index use. On a 500-player database this is manageable; at larger scale it becomes a bottleneck.
- **Fix:** Ensure all string columns used in WHERE clauses and JOINs share the same charset and collation as the connection. Run `SHOW CREATE TABLE membre` to verify `login` column charset matches the connection's `utf8mb4`.

---

### P2-D7-032 | LOW | `dbCount()` returns `0` on both "no rows" and "query error" — callers cannot distinguish

- **Location:** `includes/database.php:96-99`
- **Description:** `dbCount()` returns `0` when `dbFetchOne()` returns null, which happens both for "no matching rows" and "query preparation failed". A caller like `countStoredCompounds()` in `compounds.php` that returns 0 on error will allow synthesis to proceed even if the underlying count query failed.
- **Impact:** In theory, a prepare error on the count query would allow compound synthesis past the `COMPOUND_MAX_STORED` limit check. In practice, if the same table is consistently failing `mysqli_prepare`, other queries would also fail. Low severity.
- **Fix:** `dbCount()` should distinguish between `null` (query error) and `0` (genuine count of zero) by checking `$row` more carefully, or `dbFetchOne()` should throw on prepare error.

---

### P2-D7-033 | LOW | `migrate.php` uses raw `mysqli_query` for migrations table creation — if this fails, subsequent queries produce undefined behavior

- **Location:** `migrations/migrate.php:15-21`
- **Description:** `mysqli_query($base, "CREATE TABLE IF NOT EXISTS migrations ...")` on line 15 uses raw `mysqli_query` (not a prepared statement, though DDL doesn't need parameterization). The return value is not checked. If this fails (e.g., insufficient privileges), the subsequent `mysqli_query($base, "SELECT filename FROM migrations ...")` will fail too, returning false. `while ($row = mysqli_fetch_assoc(false))` will produce a PHP warning but continue with an empty `$applied` array, causing all migrations to be re-applied.
- **Impact:** All migrations re-applied on privilege failure, potentially corrupting the schema with duplicate indexes and key errors.
- **Fix:** Check the return value of the CREATE TABLE query and die with an informative message if it fails.

---

### P2-D7-034 | LOW | `ajouter()` in `db_helpers.php` uses `$global $nomsRes` for column whitelist initialization — if called before `$nomsRes` is set, whitelist is incomplete

- **Location:** `includes/db_helpers.php:16-27`
- **Description:** The `ajouter()` function initializes its `$allowedColumns` static array using `global $nomsRes` on first call. If `ajouter()` is called before `$nomsRes` is populated (e.g., from a script that includes `database.php` but not the full `fonctions.php` chain), `$nomsRes` is null/empty, `is_array($nomsRes)` returns false, and the whitelist only contains the hardcoded stat columns (`victoires`, `energieDonnee`, etc.) but not the resource columns (`carbone`, `azote`, etc.). A subsequent call to `ajouter('carbone', 'ressources', 100, $joueur)` would be blocked with an error log.
- **Impact:** `ajouter()` calls in contexts where `$nomsRes` is not yet available silently fail with an error log and no DB write. This could cause silent resource award failures.
- **Fix:** Make `$nomsRes` a hardcoded array in `db_helpers.php` (it's a fixed game constant, not dynamic) rather than relying on a global variable. Or initialize the whitelist lazily only when needed.

---

### P2-D7-035 | LOW | Season archive string building in `performSeasonEnd()` uses unclosed bracket notation for archive data

- **Location:** `includes/player.php:833`
- **Description:** The archive string for player rankings uses:
  ```php
  $chaine = $chaine . '[' . $data['login'] . ',' . $data['totalPoints'] . ',' . ...
  ```
  Note the `[` is opened but never closed with `]`. The same pattern appears for alliances (line 849) and wars (line 862). If this archive string is ever parsed programmatically, the malformed bracket structure would cause parsing errors. Currently the archive data is likely only displayed as raw text.
- **Impact:** Archive data is structurally malformed — cannot be reliably parsed if archive viewer is ever built. Low immediate impact.
- **Fix:** Add closing `]` to each archive record, or use `json_encode()` for the archive format.

---

### P2-D7-036 | LOW | No connection timeout or reconnect logic — long-running season reset may lose the connection mid-transaction

- **Location:** `includes/connexion.php:14` and `includes/player.php:923-971`
- **Description:** `mysqli_connect()` uses default connection parameters. MariaDB's `wait_timeout` (default 28800 seconds on most systems, but 600 seconds on some VPS configurations) closes idle connections. The `remiseAZero()` function inside `performSeasonEnd()` performs many sequential operations — `DELETE FROM rapports` on a large table can take minutes. If the connection times out mid-transaction, the transaction is rolled back but the PHP script gets a "MySQL server has gone away" error on the next query. There is no reconnect logic and no explicit `mysql_attr_timeout` setting.
- **Impact:** Season resets that take longer than `wait_timeout` fail mid-way. With the two-phase design (P2-D7-015), Phase 1 may commit (VP awarded) while Phase 2 fails (game state not reset), leaving the database in a mixed state.
- **Fix:**
  ```php
  // In connexion.php, after mysqli_connect:
  mysqli_options($base, MYSQLI_OPT_CONNECT_TIMEOUT, 30);
  // And set MariaDB session timeout:
  mysqli_query($base, 'SET SESSION wait_timeout=3600, interactive_timeout=3600');
  ```
  Also consider running the season reset from a CLI script (not a web request) where timeouts are not an issue.

---

## Summary Table

| ID | Severity | Title |
|----|----------|-------|
| P2-D7-001 | CRITICAL | Combat transaction does not catch `Error`; partial commit on OOM/type error |
| P2-D7-002 | CRITICAL | CAS `attaqueFaite=1` committed before combat transaction — zombie actions on Error |
| P2-D7-003 | CRITICAL | `withTransaction()` catches only `Exception`, not `\Throwable` — global rollback bypass |
| P2-D7-004 | CRITICAL | `ajouterPoints()` FOR UPDATE outside transaction — TOCTOU on attack/defense/pillage points |
| P2-D7-005 | HIGH | `inscrire()` reads `statistiques.inscrits` outside transaction — counter race |
| P2-D7-006 | HIGH | `supprimerJoueur()` deletes wrong column (`login`) from `attack_cooldowns` — silent no-op |
| P2-D7-007 | HIGH | `supprimerJoueur()` missing cleanup for `login_history`, `account_flags`, `player_compounds` |
| P2-D7-008 | HIGH | `remiseAZero()` does not reset `player_compounds` — compounds survive season reset |
| P2-D7-009 | HIGH | Formation molecule read missing `FOR UPDATE` inside transaction — stale read TOCTOU |
| P2-D7-010 | HIGH | `updateRessources()` molecule decay is a read-modify-write without lock — formation molecule loss |
| P2-D7-011 | HIGH | `actionsenvoi` resource credit has no transaction or lock — double-credit race |
| P2-D7-012 | HIGH | Market buy/sell uses raw `mysqli_prepare` inside `withTransaction()` — silent failure path |
| P2-D7-013 | HIGH | `inscrire()` does not insert into `prestige` — all new players lack prestige row |
| P2-D7-014 | HIGH | `remiseAZero()` resource column whitelist missing — defense-in-depth gap |
| P2-D7-015 | HIGH | `performSeasonEnd()` Phases 1 and 2 are separate transactions — catastrophic split on crash |
| P2-D7-016 | HIGH | `checkTimingCorrelation()` uses O(n²) non-sargable join — DoS via login events |
| P2-D7-017 | HIGH | `prestige` table created VARCHAR(50) — historical truncation for long logins |
| P2-D7-018 | HIGH | `attack_cooldowns` missing FK for `defender` column — orphan rows on player deletion |
| P2-D7-019 | HIGH | Deadlock potential: combat vs construction lock ordering inverted |
| P2-D7-020 | MEDIUM | `revenuEnergie()` issues 10+ queries per invocation — N+1 within single function |
| P2-D7-021 | MEDIUM | `getResourceNodeBonus()` static cache stale across season reset in same FPM worker |
| P2-D7-022 | MEDIUM | `migrate.php` no per-migration transaction — partial migration on statement failure |
| P2-D7-023 | MEDIUM | Migration 0026 adds `idx_totalPoints` without `IF NOT EXISTS` — duplicate index error on fresh deploy |
| P2-D7-024 | MEDIUM | Migration 0016 not idempotent — `DROP INDEX` fails if re-run |
| P2-D7-025 | MEDIUM | Migration 0013 includes live data DELETE — wrong for re-runs during active session |
| P2-D7-026 | MEDIUM | `player_compounds` charset `latin1` vs `membre.login` `utf8mb4` — FK join collation mismatch |
| P2-D7-027 | MEDIUM | `login_history` / `account_flags` no FK to `membre` — orphan rows, no automated cleanup |
| P2-D7-028 | MEDIUM | `recalculerStatsAlliances()` no transaction — alliance totals inconsistent under concurrency |
| P2-D7-029 | MEDIUM | Market history cleanup uses `LIMIT OFFSET` — may not trim correctly with ID gaps |
| P2-D7-030 | MEDIUM | `dbExecute()` returns false on prepare error but callers check affected_rows — silent failures |
| P2-D7-031 | MEDIUM | Connection charset `utf8mb4` may conflict with legacy `latin1` columns — index bypass |
| P2-D7-032 | LOW | `dbCount()` returns 0 for both no-rows and query error — callers cannot distinguish |
| P2-D7-033 | LOW | `migrate.php` migrations table creation return value unchecked — re-applies all on failure |
| P2-D7-034 | LOW | `ajouter()` whitelist depends on global `$nomsRes` — incomplete if called too early |
| P2-D7-035 | LOW | Season archive strings use unclosed `[` notation — malformed if parsed programmatically |
| P2-D7-036 | LOW | No connection timeout handling — long season reset can lose connection mid-transaction |

---

## Priority Fix Order

**Immediate (block deployment):**
1. P2-D7-003 — change `catch (Exception $e)` to `catch (\Throwable $e)` in `withTransaction()` (one-line fix, eliminates half the critical findings)
2. P2-D7-001 + P2-D7-002 — refactor combat block to use `withTransaction()` with the CAS guard inside
3. P2-D7-006 — fix `supprimerJoueur()` column name bug (`login` → `attacker OR defender`)
4. P2-D7-013 — add `prestige` insert to `inscrire()`

**High priority (next sprint):**
5. P2-D7-023 — fix migration 0026 `IF NOT EXISTS` (breaks fresh deployments)
6. P2-D7-004 — make `ajouterPoints()` use atomic `UPDATE ... SET col = col + ?`
7. P2-D7-008 — add `DELETE FROM player_compounds` to `remiseAZero()`
8. P2-D7-007 — add `login_history` / `account_flags` / `player_compounds` cleanup to `supprimerJoueur()`
9. P2-D7-009 + P2-D7-010 — fix molecule read-modify-write with atomic increment
10. P2-D7-012 — replace raw `mysqli_prepare` in market transactions with `dbExecute()`
11. P2-D7-016 — rewrite timing correlation join with range-based predicate

**Medium priority (next two weeks):**
12. P2-D7-015 — add advisory lock and phase tracking to season reset
13. P2-D7-026 — convert `player_compounds` to `utf8mb4`
14. P2-D7-022 — add per-migration error handling in `migrate.php`
15. P2-D7-024 — make migration 0016 idempotent
16. P2-D7-019 — document and enforce lock ordering globally
17. P2-D7-028 — wrap `recalculerStatsAlliances()` in transaction
18. P2-D7-011 — add transaction to `actionsenvoi` processing

---

*End of Pass 2 — Domain 7 Database Audit. 36 findings: 4 CRITICAL, 15 HIGH, 11 MEDIUM, 6 LOW.*
