# Remediation Plan — Ultra Audit Pass 4
**Date:** 2026-03-08
**Scope:** 12 domain audit reports, 583 tests / 3372 assertions baseline

## Summary: 0 CRITICAL, 5 HIGH, 13 MEDIUM, 22 LOW, 6 INFO

Deduplication notes:
- Domain 9 LOW-001/002 (config comment inaccuracies) are distinct from Domain 11 LOW-001 (literal magic numbers in index.php). All kept separate.
- Domain 7 LOW-SST-001 and Domain 11 LOW-001 overlap in index.php:131-135 — merged into a single finding PASS4-LOW-001.
- Domain 3 LOW-001 (tradeVolume 'is' binding) and Domain 6 LOW-004 (MARKET_POINTS_SCALE unused) are independent; both retained.
- Domain 10 MEDIUM-003 (retroactive nullable fix, no current bug) is recorded for documentation only — no code change needed; marked WONTFIX in the finding.
- Domain 6 INFO-006 (player ranking tab not frozen) is promoted to LOW because it is observable by players during season maintenance.

Total actionable findings: 37 (5 HIGH, 13 MEDIUM, 19 LOW, 6 INFO/WONTFIX).

---

## Batch 1 — Data Corruption and Broken Functionality (CRITICAL/HIGH)

*No external dependencies. Must run before any other batch.*

### PASS4-HIGH-001: diminuerBatiment closure does not capture variable-variables — zeros ALL condenseur/producteur point allocations on building loss

- **Domain:** 12 (Molecules, Compounds, Specializations)
- **File:** `includes/player.php:655,670,691`
- **Severity:** HIGH — data corruption; player atom specialization allocations silently zero out on every building-loss combat event
- **Root cause:** The `withTransaction()` closure at line 655 declares `use ($base, $nom, $joueur, $nomsRes, $points, ...)` but does NOT capture `${'points'.$ressource}` or `${'niveau'.$ressource}`. PHP variable-variables in closures cannot be captured via `use` — the closure reads them as `null`/`0`. The `$chaine` string built at lines 670-677 (producteur) and 691-699 (condenseur) is therefore all-zeros, overwriting the player's real allocation.
- **Fix:** Inside the closure, parse allocations directly from the already-locked `$batiments` row fetched at line 657, rather than relying on the outer-scope variable-variables. For producteur, parse `$batiments['pointsProducteur']` (semicolon-delimited string) into a local array. For condenseur, parse `$batiments['pointsCondenseur']` the same way. Replace all references to `${'points'.$ressource}` and `${'niveau'.$ressource}` inside the closure with reads from these local arrays.
  ```
  // Inside the closure, after the FOR UPDATE SELECT:
  $producteurAllocs = array_map('floatval', explode(';', rtrim($batiments['pointsProducteur'], ';')));
  $condenseurLevels = array_map('intval',   explode(';', rtrim($batiments['pointsCondenseur'],  ';')));
  // Then replace ${'points'.$ressource} → $producteurAllocs[$num]
  // and ${'niveau'.$ressource}          → $condenseurLevels[$num]
  ```
- **Test:** Verify that after a `diminuerBatiment('producteur', $joueur)` call the `pointsProducteur` column still sums to the original total minus the correctly removed points, not all-zeros. Add a unit test seeding a player with known allocation `"5;3;2;1;"` and asserting the post-decrement value is `"4;3;2;1;"` (one point removed from first atom).

---

### PASS4-HIGH-002: Nested transactions in combat — withTransaction inside manual mysqli_begin_transaction causes implicit commits and broken rollback

- **Domain:** 2 (Combat System)
- **File:** `includes/game_actions.php:108`, `includes/database.php:115`
- **Severity:** HIGH — building destruction and totalPoints updates commit prematurely; rollback on combat error leaves database in a partially-written state
- **Root cause:** `updateActions()` manually calls `mysqli_begin_transaction($base)` at line 108, then calls `diminuerBatiment()` (via combat resolution), which internally calls `withTransaction($base, ...)`. MariaDB does not support real nested transactions; a second `BEGIN` implicitly commits the outer transaction, so the CAS guard's rollback path no longer protects the outer work.
- **Fix:** Make `withTransaction()` re-entrant using a static savepoint depth counter. When depth > 0 use `SAVEPOINT sp_N` / `RELEASE SAVEPOINT sp_N` / `ROLLBACK TO SAVEPOINT sp_N` instead of `BEGIN`/`COMMIT`/`ROLLBACK`. **DO NOT** convert `game_actions.php` to use `withTransaction()` — the combat loop uses `continue` at line 116 which cannot be used inside a PHP closure (PHP fatal error). Keep the manual `mysqli_begin_transaction`/`mysqli_commit`/`mysqli_rollback` in `game_actions.php` as-is. The savepoint mechanism in `withTransaction()` alone solves the nested commit problem: nested calls from within the combat transaction will now use savepoints instead of issuing a second `BEGIN`.
  ```php
  // database.php replacement:
  function withTransaction($base, callable $fn) {
      static $depth = 0;
      $useSavepoint = $depth > 0;
      $sp = 'sp_' . $depth;
      if ($useSavepoint) {
          mysqli_query($base, "SAVEPOINT $sp");
      } else {
          mysqli_begin_transaction($base);
      }
      $depth++;
      try {
          $result = $fn();
          $depth--;
          if ($useSavepoint) {
              mysqli_query($base, "RELEASE SAVEPOINT $sp");
          } else {
              mysqli_commit($base);
          }
          return $result;
      } catch (\Throwable $e) {
          $depth--;
          if ($useSavepoint) {
              mysqli_query($base, "ROLLBACK TO SAVEPOINT $sp");
          } else {
              mysqli_rollback($base);
          }
          throw $e;
      }
  }
  ```
- **Test:** Write a test that calls `withTransaction` nested twice and confirms the inner closure's exception rolls back only the inner work, not the outer. Confirm the CAS guard in `game_actions.php` can still observe its own `attaqueFaite=1` write before the outer commit.

---

### PASS4-HIGH-003: declarations table has no FK to alliances.id — orphaned war/pact records on alliance deletion

- **Domain:** 10 (Database Integrity)
- **File:** `migrations/` (new migration needed)
- **Severity:** HIGH — war/pact rows survive alliance deletion; code that reads `declarations` and joins `alliances` silently returns stale/misleading data; pact deduplication checks become wrong
- **Fix:** Create migration `0055_declarations_fk.sql`. Actual column names are `alliance1` and `alliance2` (NOT `idalliance1`/`idalliance2` — confirmed in migrations/0013 and base_schema.sql:121-122). Add `CONSTRAINT fk_declarations_alliance_a FOREIGN KEY (alliance1) REFERENCES alliances(id) ON DELETE CASCADE` and symmetric for `alliance2`. Precede with orphan cleanup: `DELETE FROM declarations WHERE alliance1 NOT IN (SELECT id FROM alliances) OR alliance2 NOT IN (SELECT id FROM alliances);`
- **Test:** Confirm `SHOW CREATE TABLE declarations` contains both FK constraints after migration. Verify `DELETE FROM alliances WHERE id=X` cascades to remove related declarations.

---

### PASS4-HIGH-004: resource_nodes table created with utf8mb4 charset — FK/charset mismatch with latin1 membre table

- **Domain:** 10 (Database Integrity)
- **File:** `migrations/0023_create_resource_nodes.sql:14`
- **Severity:** HIGH — although `resource_nodes` has no direct FK to `membre`, utf8mb4 is the wrong project standard (all new tables must be latin1 per MEMORY.md); any future FK addition will fail silently with "Cannot add foreign key"
- **Fix:** Create migration `0056_fix_resource_nodes_charset.sql` that converts the table: `ALTER TABLE resource_nodes CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;`. Update `0023_create_resource_nodes.sql` to use `DEFAULT CHARSET=latin1` so re-runs from the canonical migration set are correct.
- **Test:** `SHOW CREATE TABLE resource_nodes` must show `CHARSET=latin1`. Run migration twice (idempotency): second run must succeed without error.

---

### PASS4-HIGH-005: season_recap FK to membre.login missing ON UPDATE CASCADE — login rename orphans recap rows; season_recap also missing UNIQUE constraint on (login, season_number) — duplicate archive rows possible

- **Domain:** 10 (Database Integrity) — combines DB-HIGH-003 and DB-MEDIUM-005
- **File:** `migrations/0054_add_season_recap_fk.sql`, `migrations/0029_create_season_recap.sql`
- **Severity:** HIGH (FK gap) + MEDIUM (dupe risk) — grouped here because both require the same migration file
- **Root cause:** Migration 0054 adds `ON DELETE CASCADE` only; `ON UPDATE CASCADE` is absent. If an admin ever renames a login the season_recap rows are silently orphaned. Additionally, 0029 has no UNIQUE index on `(login, season_number)` so `archiveSeasonData()` called twice in the same maintenance window (e.g., script retry) would produce duplicate rows.
- **Fix:** Create migration `0057_season_recap_constraints.sql`:
  1. Drop and re-add the FK with both CASCADE clauses: `DROP FOREIGN KEY fk_season_recap_login` then `ADD CONSTRAINT fk_season_recap_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE`.
  2. Add unique constraint: `ALTER TABLE season_recap ADD UNIQUE KEY uq_season_login (login, season_number);` (guard with `IF NOT EXISTS` pattern via information_schema check).
  3. Before adding unique constraint, remove any existing dupes: `DELETE s1 FROM season_recap s1 INNER JOIN season_recap s2 ON s1.login=s2.login AND s1.season_number=s2.season_number AND s1.id>s2.id;`
- **Test:** After migration, confirm `SHOW CREATE TABLE season_recap` has `ON UPDATE CASCADE`. Attempt to INSERT a duplicate `(login, season_number)` pair and confirm MariaDB rejects it.

---

## Batch 2 — Database Integrity: Missing Constraints and FK Gaps (MEDIUM)

*Depends on Batch 1 (PASS4-HIGH-002 must land first so withTransaction is safe to use in migration helpers). Otherwise independent.*

### PASS4-MEDIUM-001: player_compounds FK missing ON UPDATE CASCADE

- **Domain:** 10 (Database Integrity)
- **File:** `migrations/0024_create_compounds.sql:12`
- **Severity:** MEDIUM
- **Root cause:** `fk_compounds_login` has `ON DELETE CASCADE` but no `ON UPDATE CASCADE`.
- **Fix:** Migration `0058_fix_compound_fk.sql`: drop and re-add the constraint: `ALTER TABLE player_compounds DROP FOREIGN KEY fk_compounds_login; ALTER TABLE player_compounds ADD CONSTRAINT fk_compounds_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;`
- **Test:** `SHOW CREATE TABLE player_compounds` shows `ON UPDATE CASCADE`.

---

### PASS4-MEDIUM-002: login_history FK missing ON UPDATE CASCADE; connectes.ip VARCHAR(15) too short for IPv6

- **Domain:** 10 (Database Integrity) — combines DB-MEDIUM-002 and DB-LOW-003
- **File:** `migrations/0020_create_login_history.sql`, `migrations/` (new)
- **Severity:** MEDIUM (grouped for single migration)
- **Root cause:** `login_history` has `fk_login_history_login` (from migration 0018/0041) without `ON UPDATE CASCADE`. Separately, `connectes.ip` is VARCHAR(15) — too short for IPv6 addresses (max 45 chars); migration 0020 already widened `membre.ip` but connectes was missed.
- **Fix:** Migration `0059_fix_login_history_and_connectes.sql`:
  1. Drop and re-add `fk_login_history_login` with `ON UPDATE CASCADE`.
  2. `ALTER TABLE connectes MODIFY ip VARCHAR(45) NOT NULL DEFAULT '';`
- **Test:** `SHOW CREATE TABLE login_history` shows `ON UPDATE CASCADE`. `SHOW COLUMNS FROM connectes LIKE 'ip'` shows `varchar(45)`.

---

### PASS4-MEDIUM-003: moderation_log.moderator_login has no FK to membre.login — audit trail orphans on moderator deletion

- **Domain:** 10 (Database Integrity)
- **File:** `migrations/0043_create_moderation_log.sql:10`
- **Severity:** MEDIUM — audit integrity: moderator rows persist after account deletion; admin queries over moderation_log may join membre and silently drop rows
- **Fix:** Migration `0060_moderation_log_fk.sql`: orphan cleanup first (`DELETE FROM moderation_log WHERE moderator_login NOT IN (SELECT login FROM membre)`), then `ALTER TABLE moderation_log ADD CONSTRAINT fk_modlog_moderator FOREIGN KEY (moderator_login) REFERENCES membre(login) ON DELETE SET NULL ON UPDATE CASCADE;` — use SET NULL (not CASCADE) so the audit trail row survives moderator account deletion, with `moderator_login` becoming NULL to indicate "deleted moderator". This requires `ALTER TABLE moderation_log MODIFY moderator_login VARCHAR(255) DEFAULT NULL;` first.
- **Test:** `SHOW CREATE TABLE moderation_log` shows FK with SET NULL. Insert a log row, delete the moderator account, confirm the log row still exists with `moderator_login=NULL`.

---

### PASS4-MEDIUM-004: spec_combat/economy/research columns have no CHECK constraint (range 0-2); migration 0035 DATABASE() guard breaks when session has no DB context

- **Domain:** 10 (Database Integrity) — combines DB-MEDIUM-006 and DB-MEDIUM-008
- **File:** `migrations/0035_add_missing_pks_and_fks.sql`, `migrations/` (new)
- **Severity:** MEDIUM
- **Root cause (CHECK):** Specialization columns in `membre` or `constructions` accept any integer; out-of-range values would silently pass to game logic.
- **Root cause (DATABASE()):** Migration 0035 uses `SET @var = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() ...)`. If the session's default database is not set (e.g., `mysql -u tvlw theverylittlewar < migration.sql` vs `mysql -u tvlw < migration.sql`), `DATABASE()` returns NULL and the WHERE clause never matches, so the guard thinks the constraint does not exist and tries to add it again, causing an error.
- **Fix:**
  1. Migration `0061_spec_check_constraints.sql`: `ALTER TABLE membre ADD CONSTRAINT chk_spec_combat CHECK (spec_combat BETWEEN 0 AND 2), ADD CONSTRAINT chk_spec_economy CHECK (spec_economy BETWEEN 0 AND 2), ADD CONSTRAINT chk_spec_research CHECK (spec_research BETWEEN 0 AND 2);` wrapped in idempotency guards using `information_schema.CHECK_CONSTRAINTS`.
  2. In migration 0035, replace `DATABASE()` with the explicit database name literal `'tvlw'` in all `TABLE_SCHEMA = DATABASE()` clauses. Document in a comment that the migration assumes the `tvlw` schema.
- **Test:** Attempt `UPDATE membre SET spec_combat=3 WHERE login='test'` and confirm MariaDB rejects it with a CHECK constraint violation.

---

### PASS4-MEDIUM-005: Test fixture base_schema.sql actionsformation.idclasse is still INT, not VARCHAR(50) as migrations 0015/0019 set — CI uses stale schema for in-memory tests

- **Domain:** 10 (Database Integrity)
- **File:** `tests/fixtures/base_schema.sql:39` (or equivalent fixture path)
- **Severity:** MEDIUM — tests run against a schema that diverges from production; any test exercising `actionsformation.idclasse` with a non-numeric class ID will pass on production but fail in CI, or vice versa
- **Fix:** Locate the test fixture SQL file. Change `idclasse INT` to `idclasse VARCHAR(50)` in the `actionsformation` table definition to match migrations 0015 and 0019. Run the full test suite to confirm no regressions.
- **Test:** `php artisan test` / `./vendor/bin/phpunit` returns 0 failures with the corrected fixture. Grep the fixture for `idclasse` and confirm it reads `VARCHAR(50)`.

---

## Batch 3 — Database Integrity: Data Type and Orphan Gaps (MEDIUM, continued)

*Independent of Batch 2. Can run in parallel.*

### PASS4-MEDIUM-006: vacances.idJoueur has no FK to membre.id; autre.moleculesPerdues DOUBLE should be BIGINT

- **Domain:** 10 (Database Integrity) — combines DB-LOW-001 and DB-LOW-005
- **File:** `migrations/` (new)
- **Severity:** MEDIUM (FK gap with data integrity implications; DOUBLE accumulation)
- **Root cause (FK):** `vacances.idJoueur` has no referential constraint; vacation rows survive player deletion. `supprimerJoueur()` must explicitly delete from `vacances` — verify it does; if not, add it there too.
- **Root cause (DOUBLE):** `autre.moleculesPerdues` is DOUBLE. Over a season, floating-point summation of thousands of molecule-loss events accumulates rounding error. The value is displayed and compared to medal thresholds, so rounding errors affect medal awards.
- **Fix:** Migration `0062_vacances_fk_and_molecules_type.sql`:
  1. Orphan cleanup: `DELETE FROM vacances WHERE idJoueur NOT IN (SELECT id FROM membre);`
  2. `ALTER TABLE vacances ADD CONSTRAINT fk_vacances_joueur FOREIGN KEY (idJoueur) REFERENCES membre(id) ON DELETE CASCADE;`
  3. Convert column: `ALTER TABLE autre MODIFY moleculesPerdues BIGINT NOT NULL DEFAULT 0;` — note this requires that any existing fractional values be rounded first: `UPDATE autre SET moleculesPerdues = ROUND(moleculesPerdues);`
  4. In `game_actions.php`, change the bind type for `moleculesPerdues` accumulation from `'ds'` to `'is'` and cast the value to `(int)` before binding.
- **Test:** Verify FK exists in `SHOW CREATE TABLE vacances`. Verify `SHOW COLUMNS FROM autre LIKE 'moleculesPerdues'` shows `bigint`. Confirm `supprimerJoueur()` deletes from vacances (add to the function if missing).

---

### PASS4-MEDIUM-007: medailles.php calls batMax with extra args that are silently ignored; bilan.php medal section missing 4 of 10 categories

- **Domain:** 6 (Prestige, Medals, Ranking) — combines MEDAL-MEDIUM-005 and MEDAL-LOW-001
- **File:** `medailles.php:38`, `bilan.php:688-695`
- **Severity:** MEDIUM (stale call convention) + LOW (incomplete display) — grouped because both touch medal display in the same pass
- **Root cause (batMax):** Line 38 calls `batMax($donnees4['login'], $nomsRes, $nbRes)` but the current function signature is `batMax($pseudo)`. Extra args are silently ignored by PHP; functionally correct today, but misleading and will break if the signature changes.
- **Root cause (bilan):** The `$medalCategories` array at bilan.php:688 lists only 6 categories: Terreur, Attaquant, Defenseur, Pilleur, Energivore, Pertes. Missing: Constructeur (buildings built), Pipelette (forum posts), Explosif (bombes used), Aleatoire (random event triggers). These medal types exist in `$paliers*` config arrays but are not displayed.
- **Fix:**
  1. `medailles.php:38`: remove the extra arguments — change to `batMax($donnees4['login'])`.
  2. `bilan.php`: add the 4 missing categories to `$medalCategories` using the correct `$donnees2` stat keys and `$paliers*` variables that are already loaded earlier in the file. Verify that `$paliersPipelette`, `$paliersConstructeur`, `$paliersExplosif`, `$paliersAleatoire` are defined in the bilan.php preamble (or add the missing lookups).
- **Test:** Load `bilan.php` for a player who has forum posts; confirm "Pipelette" medal row appears with correct tier. Call `batMax('testuser')` with no extra args; confirm no PHP warning.

---

### PASS4-MEDIUM-008: inscription.php email maxlength hardcoded to 100 instead of EMAIL_MAX_LENGTH constant

- **Domain:** 11 (UI, Display, Client-Side)
- **File:** `inscription.php:85`
- **Severity:** MEDIUM — if `EMAIL_MAX_LENGTH` is ever changed in config.php the form will silently accept a length that the server-side validation rejects (or vice versa), causing confusing UX
- **Fix:** Replace `maxlength="100"` with `maxlength="' . EMAIL_MAX_LENGTH . '"`. Verify `EMAIL_MAX_LENGTH` is defined in `includes/config.php`; if not, add `define('EMAIL_MAX_LENGTH', 100);` there.
- **Test:** Confirm `grep -n 'EMAIL_MAX_LENGTH' inscription.php` returns a match. Confirm the constant is defined in config.php.

---

## Batch 4 — Security: Email Injection, GDPR, and Alliance Atomicity (MEDIUM/HIGH-adjacent)

*Independent of Batches 1-3.*

### PASS4-MEDIUM-009: sendAdminAlertEmail subject not sanitized — email header injection risk

- **Domain:** 1 (Security and Authentication)
- **File:** `includes/multiaccount.php:286-291`
- **Severity:** MEDIUM — the `$subject` parameter passes through to PHP `mail()` without stripping CRLF characters; an attacker who influences flag type or login names could inject additional mail headers
- **Fix:** In `sendAdminAlertEmail()`, strip `\r` and `\n` from `$subject` before passing to `mail()`:
  ```php
  $subject = str_replace(["\r", "\n"], '', $subject);
  ```
  Additionally sanitize `$body` by replacing bare `\n` with `\r\n` to ensure proper MIME encoding.
- **Test:** Call `sendAdminAlertEmail("test\r\nBcc: attacker@evil.com", "body")` and confirm the injected header does not appear in the outgoing message (verify via mail log or a mail trap).

---

### PASS4-MEDIUM-010: Plaintext IP addresses in admin alert messages and account_flags evidence — GDPR risk

- **Domain:** 1 (Security and Authentication)
- **File:** `includes/multiaccount.php:65-66`
- **Severity:** MEDIUM — IP addresses stored verbatim in `admin_alerts.message` and `account_flags.evidence` JSON are personal data under GDPR; they should be hashed or pseudonymised
- **Root cause:** `checkSameIpAccounts()` at line 65 interpolates `$ip` directly into the alert message string and into the evidence JSON at line 55. The `logInfo()` call at line 68 already hashes the IP using `SECRET_SALT`, but the database rows do not.
- **Fix:**
  1. Replace the raw `$ip` in the `createAdminAlert()` message string with a salted hash: `$ipDisplay = substr(hash('sha256', $ip . SECRET_SALT), 0, 12);` and use `$ipDisplay` in the alert message.
  2. In the `$evidence` JSON for `same_ip` flags, replace `'shared_ip' => $ip` with `'shared_ip_hash' => $ipDisplay`.
  3. The admin dashboard still needs to identify accounts — it can look up login_history to correlate by hash if needed, but the alert message itself must not contain a raw IP.
  4. Apply the same treatment to `checkCoordinatedAttacks()` where `$ip` is also surfaced.
- **Test:** Trigger a same-IP detection in the test suite; assert that `admin_alerts.message` does not contain the literal IP address and `account_flags.evidence` does not contain the literal IP address.

---

### PASS4-MEDIUM-011: Alliance leave cooldown update is outside the withTransaction block — crash between commit and cooldown write leaves player with no cooldown

- **Domain:** 4 (Alliance System)
- **File:** `alliance.php:87-92`
- **Severity:** MEDIUM — if the process crashes or the DB connection drops between the `withTransaction` commit (line 86) and the `UPDATE autre SET alliance_left_at` (line 89), the player successfully leaves the alliance but has no cooldown; they can immediately rejoin
- **Fix:** Move the `alliance_left_at` update inside the `withTransaction` callback, before or after the other two DELETEs. Remove the outer `try/catch` that silently swallows the exception on a missing column — handle migration absence explicitly:
  ```php
  withTransaction($base, function() use ($base) {
      dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
      dbExecute($base, 'DELETE FROM grades WHERE login=?', 's', $_SESSION['login']);
      dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $_SESSION['login']);
      dbExecute($base, 'UPDATE autre SET alliance_left_at=UNIX_TIMESTAMP() WHERE login=?', 's', $_SESSION['login']);
  });
  ```
  The `alliance_left_at` column is added by migration 0034 which is already deployed; the silent-skip try/catch is no longer needed.
- **Test:** Verify that after leaving an alliance, `SELECT alliance_left_at FROM autre WHERE login='X'` is non-null and approximately `time()`. Verify the update is rolled back if an exception occurs inside the transaction.

---

## Batch 5 — Ranking, Season, and Archival Correctness (MEDIUM/LOW)

*Depends on Batch 1 PASS4-HIGH-005 (season_recap UNIQUE constraint) being applied first. Otherwise independent.*

### PASS4-MEDIUM-012: archiveSeasonData uses sequential ++$rank instead of DENSE_RANK — tied players get different ranks

- **Domain:** 6 (Prestige, Medals, Ranking)
- **File:** `includes/player.php:927-931`
- **Severity:** MEDIUM — tied players at season end receive different final_rank values; the difference is cosmetic in the recap display but materially affects any future reward-by-rank calculation
- **Fix:** Use MariaDB window function inside `archiveSeasonData()`. Replace the PHP `$rank++` counter with a subquery or window function: either add `RANK() OVER (ORDER BY a.totalPoints DESC) AS final_rank` to the SELECT and remove the PHP counter, or use `DENSE_RANK()` for gap-free ties. Use `DENSE_RANK()` to be consistent with the live `classement.php` ranking logic.
  ```sql
  SELECT a.login, a.totalPoints, ...,
         DENSE_RANK() OVER (ORDER BY a.totalPoints DESC) AS final_rank
  FROM autre a ...
  ```
  Remove `$rank = 0;` and `$rank++;` from the PHP loop, and use `$p['final_rank']` in the INSERT.
- **Test:** Seed two players with equal `totalPoints`. Run `archiveSeasonData()`. Confirm both receive the same `final_rank` value in `season_recap`.

---

### PASS4-MEDIUM-013: Player ranking tab not frozen during season maintenance (only alliance tab is guarded)

- **Domain:** 6 (Prestige, Medals, Ranking) — was INFO-006, promoted to LOW/MEDIUM
- **File:** `classement.php:329` (alliance tab guard) vs player tab (no guard)
- **Severity:** MEDIUM — during the 24h season maintenance window, the player rankings update in real-time while the alliance tab shows a "frozen" notice; players see inconsistent state
- **Fix:** Apply the same `$seasonMaintenance` guard that wraps the alliance tab's `recalculerStatsAlliances()` call to the player tab's ranking query. When `$seasonMaintenance === true`, display the same "classement gelé" card for the player tab and skip the live ranking query.
- **Test:** Set `statistiques.maintenance=1` in the test DB, load `classement.php?sub=0`, confirm the freeze notice appears and no live ranking rows are rendered.

---

## Batch 6 — Forum, Messages, and Admin Pagination (LOW)

*Independent of all prior batches.*

### PASS4-LOW-001: index.php season countdown uses implicit month overflow mktime and magic number literals (86400/3600)

- **Domain:** 7 + 11 (merged SST-001 and UI-LOW-001)
- **File:** `index.php:131-135`
- **Severity:** LOW — `mktime(0, 0, 0, (int)date('n') + 1, ...)` correctly overflows December to January in PHP, but it is not immediately obvious; `layout.php` uses an explicit December check which is the established pattern for this codebase. The literal `86400`/`3600` diverge from the `SECONDS_PER_DAY`/`SECONDS_PER_HOUR` constants used everywhere else.
- **Fix:**
  1. Replace `(int)date('n') + 1` with the explicit overflow pattern from `layout.php`: calculate month and year explicitly with December detection.
  2. Replace `86400` with `SECONDS_PER_DAY` and `3600` with `SECONDS_PER_HOUR` at lines 133-134.
- **Test:** Load `index.php` in December (mock `date()` or test on Dec 31); confirm `$finSaison` resolves to Jan 1 of the following year.

---

### PASS4-LOW-002: Forum topic creation (listesujets.php) not wrapped in transaction — sujets INSERT and statutforum INSERT not atomic

- **Domain:** 8 (Forum, Messages, Social)
- **File:** `listesujets.php:79-81`
- **Severity:** LOW — if the process crashes between the two INSERTs, a topic row exists with no corresponding `statutforum` row; the topic appears in listings but the creator's read-status is absent, causing minor display inconsistency
- **Fix:** Wrap lines 79-81 in `withTransaction()`:
  ```php
  withTransaction($base, function() use ($base, $getId, $titre, $timestamp) {
      dbExecute($base, 'INSERT INTO sujets VALUES(default,?,?,?,?,default,?)', 'isssi', $getId, $titre, $_POST['contenu'], $_SESSION['login'], $timestamp);
      $sujetId = mysqli_insert_id($base);
      dbExecute($base, 'INSERT INTO statutforum VALUES(?,?,?)', 'sii', $_SESSION['login'], $sujetId, $getId);
      return $sujetId;
  });
  ```
  Note: `mysqli_insert_id()` must be called inside the closure on the same connection before it is reset by any other query.
- **Test:** Simulate a failure (throw inside the closure after the first INSERT) and confirm neither row persists after rollback.

---

### PASS4-LOW-003: Admin reply listing loads entire reponses table without LIMIT — performance/DoS risk

- **Domain:** 8 (Forum, Messages, Social)
- **File:** `admin/supprimerreponse.php:47`
- **Severity:** LOW — the query `SELECT * FROM reponses ORDER BY auteur DESC` with no LIMIT will scan all rows; on a busy forum this could time out or consume excessive memory
- **Fix:** Add pagination or at minimum a LIMIT. The simplest safe fix is to add `LIMIT 200` and a note explaining admins should use the search functionality for bulk moderation. A proper fix adds page-based navigation (page param, COUNT query, LIMIT/OFFSET). Apply at minimum: `'SELECT * FROM reponses ORDER BY auteur DESC LIMIT 200'`.
- **Test:** Confirm the page loads within 500ms with a seeded table of 1000 rows.

---

### PASS4-LOW-004: CSRF token not explicitly regenerated after session_regenerate_id() on login

- **Domain:** 1 (Security and Authentication)
- **File:** `includes/basicpublicphp.php:62-65`
- **Severity:** LOW — defense-in-depth gap; the CSRF token stored in `$_SESSION['csrf_token']` persists across the session ID regeneration, so a session fixation attacker who obtained the pre-login session could still use the old CSRF token briefly
- **Fix:** After `session_regenerate_id(true)` at line 62, explicitly regenerate the CSRF token: call `csrfRotate()` if such a function exists in `csrf.php`, or manually `unset($_SESSION['csrf_token'])` and let the next `csrfToken()` call generate a new one. Ensure the login response redirect lands on a page that will produce a fresh token before any state-changing form.
- **Test:** Log in, capture the pre-login CSRF token from the form, attempt to submit a post-login form with the pre-login token — it must fail with a CSRF mismatch.

---

### PASS4-LOW-005: MEDAL_GRACE_PERIOD_DAYS and MEDAL_GRACE_CAP_TIER constants defined but never used; MARKET_POINTS_SCALE effectively unused in production ranking path

- **Domain:** 6 (Prestige, Medals, Ranking) — combines MEDAL-LOW-002 and MEDAL-LOW-004
- **File:** `includes/config.php:514-515, 350-352`
- **Severity:** LOW — dead constants cause confusion: developers reading config.php assume the grace-period cap and market points scale are enforced when they are not
- **Fix:**
  1. Locate where medal bonuses are applied in the codebase (likely `includes/formulas.php` or `includes/player.php`). Implement the `MEDAL_GRACE_PERIOD_DAYS` / `MEDAL_GRACE_CAP_TIER` logic: if the current date is within `MEDAL_GRACE_PERIOD_DAYS` of season start, cap the applied medal tier index at `MEDAL_GRACE_CAP_TIER`. If implementing is deferred, add a `// TODO: implement grace period cap` comment and remove the constants from "live config" into a "planned features" section to avoid confusion.
  2. For `MARKET_POINTS_SCALE`: verify whether it is referenced in the live `classement.php` totalPoints calculation. If not, add a `// TODO` comment or wire it in.
- **Test:** If implemented: create a player with a Gold+ medal, simulate a date within the grace period, verify their applied bonus is capped at `$MEDAL_BONUSES[MEDAL_GRACE_CAP_TIER]`.

---

## Batch 7 — Config Comment Accuracy, Cosmetic and Defense-in-Depth (LOW)

*Independent. No production behavior changes — documentation and minor hardening only.*

### PASS4-LOW-006: Formation constants comments say 70%/30%/25% but actual values are 50%/20%/40%; isotope decay comment says +50% but constant is 0.20 (+20%)

- **Domain:** 9 (Tutorial and New Player) — combines TUT-LOW-001 and TUT-LOW-002
- **File:** `includes/config.php:285-287, 307`
- **Severity:** LOW — comment inaccuracies mislead developers making balance changes; the actual constants are correct, only the comments are wrong
- **Fix:**
  1. Line 285: update comment `(25% each)` — already correct in `$FORMATIONS` description; update the inline comment above to read: `// 0 = Dispersée: 25% each class` (no change needed if the comment at 285 already matches).
  2. Line 286: `// 1 = Phalange: class 1 absorbs 50% of damage, gets +20% defense` (currently says 70%/30%).
  3. Line 287: `// 2 = Embuscade: if defender has more total molecules, +40% attack bonus` (currently says +25%).
  4. Line 307: `// ISOTOPE_REACTIF_DECAY_MOD: +20% faster decay` (currently says +50%).
- **Test:** `grep -n '70%\|30%.*defense\|25%.*attaque\|50%.*decay' includes/config.php` returns no matches.

---

### PASS4-LOW-007: don.php shows donation form even when player has no alliance

- **Domain:** 4 (Alliance System)
- **File:** `don.php` (line not specified in audit, near form rendering)
- **Severity:** LOW — player fills out the form, submits, then receives a confusing error message rather than a clear "you have no alliance" notice at page load
- **Fix:** At page-load time (before rendering the form), check `$idalliance['idalliance'] == 0` and if so render an explanatory message + redirect link to `alliance_discovery.php` instead of the form. The current guard only fires on POST submission.
- **Test:** Log in as a player with no alliance, load `don.php`, confirm the page shows "Vous n'appartenez pas a une alliance" and no form is rendered.

---

### PASS4-LOW-008: version.php exposes git commit hash via shell_exec to all authenticated users

- **Domain:** 11 (UI, Display, Client-Side)
- **File:** `version.php`
- **Severity:** LOW — git commit hashes reveal the deployment revision to players; an attacker can correlate the hash against public GitHub history to identify unpatched versions
- **Fix:** Restrict `version.php` to admin-only access using the existing admin session guard pattern (check `$_SESSION['admin']` or equivalent admin flag). Alternatively, replace `shell_exec('git rev-parse --short HEAD')` with the `GAME_VERSION` constant from config.php for non-admin users.
- **Test:** Log in as a non-admin player, request `version.php`, confirm it returns either a 403/redirect or shows only the `GAME_VERSION` string without a commit hash.

---

### PASS4-LOW-009: CSP header missing explicit connect-src directive (falls back to default-src 'self')

- **Domain:** 11 (UI, Display, Client-Side)
- **File:** `includes/csp.php`
- **Severity:** LOW — `connect-src` governs XHR/fetch/WebSocket origins; without an explicit directive it inherits `default-src 'self'`, which is correct behavior but makes the intent implicit and harder to audit
- **Fix:** Add `connect-src 'self'` explicitly to the CSP header string in `includes/csp.php`. If the game ever adds external API calls (e.g., a CDN for analytics), the explicit directive serves as a clear extension point.
- **Test:** Load any private page, check the `Content-Security-Policy` response header, confirm it contains `connect-src 'self'`.

---

### PASS4-LOW-010: BBCode [url] regex allows spaces in URL component — inconsistent with [img] restriction already in place

- **Domain:** 8 (Forum, Messages, Social)
- **File:** `includes/bbcode.php:28` (or equivalent BBCode parser)
- **Severity:** LOW — allows malformed or obfuscated URLs; a defense-in-depth gap since the [img] tag was already restricted in Batch C of Phase 10
- **Fix:** Tighten the `[url]` regex to disallow spaces and common injection characters in the URL component. Apply the same pattern already used for `[img]`: match URL against `preg_match('#^https?://[^\s"<>\']+$#i', $url)` and strip the tag if it fails.
- **Test:** Attempt `[url=javascript:alert(1)]click[/url]` and `[url=http://example.com bad]click[/url]`; confirm both are stripped or rendered as plain text.

---

### PASS4-LOW-011: Combat.php Dispersee overkill set to 0 on surviving class instead of remaining redistributed damage

- **Domain:** 2 (Combat System)
- **File:** `includes/combat.php:304`
- **Severity:** LOW — minor underapplication of Dispersee overkill damage; surviving classes absorb slightly less damage than intended when a class is not fully wiped
- **Root cause:** Line 304 sets `$disperseeOverkill = 0` when the class survives (kills < nombre), discarding any remaining overkill from that class iteration. The overkill should only reset on a non-wipeout, but the remaining fractional damage from the probabilistic kill at line 297 (`lcg_value()` partial kill) is silently discarded rather than carrying forward.
- **Fix:** The current logic is: wipeout → accumulate overkill; survival → zero overkill. The intended behavior is: wipeout → carry full excess damage forward; survival → no excess (all damage absorbed). Line 304 is actually correct for the survival case — when a class survives, no damage carries over. The true bug is that `$disperseeOverkill` is not applied to subsequent classes in the loop. Verify whether the `$disperseeOverkill` accumulated from wipeouts is actually redistributed to the next class; if not, add the redistribution logic: `$classDamage = ($damagePerClass + $disperseeOverkill)` at the top of the loop body before the `$disperseeOverkill` reset.
- **Test:** Set up a Dispersee scenario where class 1 is fully wiped; confirm the overkill damage is applied to class 2 and reduces its survivors.

---

## Batch 7b — Missing LOW Findings (added from Phase 3 completeness review)

*All independent. Can run in parallel with Batch 7.*

### PASS4-LOW-012: Domain 3 Economy — tradeVolume parameter bound as integer 'i' instead of double 'd' in LEAST cap query

- **Domain:** 3 (Economy & Resources)
- **File:** `marche.php:296,434`
- **Severity:** LOW — bind type `'is'` uses integer binding for `MARKET_POINTS_MAX` (80) while the column is DOUBLE; functionally correct due to MariaDB implicit casting but inconsistent
- **Fix:** Change `'is'` to `'ds'` at lines 296 and 434 to match column type: `dbExecute($base, 'UPDATE autre SET tradeVolume = LEAST(tradeVolume, ?) WHERE login=?', 'ds', MARKET_POINTS_MAX, $_SESSION['login']);`
- **Test:** Confirm bind type uses `'d'` for MARKET_POINTS_MAX parameter.

---

### PASS4-LOW-013: Domain 7 Session — countdown JS uses client clock with potential server-client time skew

- **Domain:** 7 (Session, Season & Time)
- **File:** `js/countdown.js:38`
- **Severity:** LOW — cosmetic; clients with clock skew (±minutes) will see inaccurate countdown. No gameplay impact since season detection is server-side.
- **Fix:** No code change required for now — document as known limitation. Optionally embed `data-server-now="<?php echo time(); ?>"` in the countdown widget in `layout.php` and use the delta as a correction offset in `countdown.js`.
- **Test:** N/A for cosmetic. If implemented, verify that a client clock 1 hour ahead still shows correct time.

---

### PASS4-LOW-014: Domain 11 UI — js/loader.js listed in audit scope but does not exist on disk

- **Domain:** 11 (UI, Display & Client-Side)
- **File:** `js/loader.js`
- **Severity:** LOW — no broken functionality (file is not referenced), but the audit plan references it
- **Fix:** Remove `js/loader.js` from the Domain 11 file list in `docs/plans/2026-03-07-ultra-audit-loop.md` to prevent confusion in future audit passes.
- **Test:** Confirm no PHP/HTML file references `loader.js` via `grep -r 'loader.js'`.

---

### PASS4-LOW-015: Domain 12 Molecules — transformInt 'E' suffix regex case-insensitive could misparse scientific notation strings

- **Domain:** 12 (Molecules, Compounds & Specializations)
- **File:** `includes/display.php:348`
- **Severity:** LOW — extremely unlikely in practice; caller `intval()` guards limit blast radius
- **Fix:** Add a pre-check for scientific notation format at the top of `transformInt()`:
  ```php
  // Before regex loop: normalize PHP scientific notation to integer string
  if (is_numeric($nombre) && stripos((string)$nombre, 'e') !== false && (float)$nombre == (int)(float)$nombre) {
      $nombre = (string)(int)(float)$nombre;
  }
  ```
- **Test:** Call `transformInt('1.5e3')`; confirm it returns `'1500'` not `'1.5000...3'`.

---

### PASS4-LOW-016: Domain 1 Security — severity reclassification note: LOW-002/003 demoted to INFO

- **Domain:** 1 (Security & Authentication)
- **File:** `includes/rate_limiter.php`, `admin/redirectionmotdepasse.php`
- **Severity:** INFO — Phase 3A noted that the original Domain 1 3 LOWs became 1 LOW + 2 INFOs in the plan. The two demotions are:
  - Rate limiter race: accepted known gap (already INFO-005 in plan)
  - Admin UA binding: defense-in-depth, scheduled for future sprint (already INFO-002 in plan)
- **Fix:** No additional change needed. This entry documents the reclassification for audit traceability.
- **Test:** N/A.

---

## Batch 8 — INFO Level and WONTFIX Annotations

*No code changes. Documentation and tracking only.*

### PASS4-INFO-001: SECRET_SALT hardcoded in config.php — should move to .env for production

- **Domain:** 1 (Security)
- **File:** `includes/config.php:20`
- **Status:** Deferred — HTTPS/DNS is still blocked (per MEMORY.md). Will be addressed in the HTTPS sprint alongside `cookie_secure` and `.env` setup. The existing `// TODO` comment is sufficient. No action this pass.

---

### PASS4-INFO-002: Admin session not bound to user-agent (redirectionmotdepasse.php)

- **Domain:** 1 (Security)
- **File:** `redirectionmotdepasse.php`
- **Status:** Defense-in-depth gap. Accept as known limitation. Add a comment documenting the gap. Full implementation would require storing UA hash in the session at login time and verifying on each admin request — schedule for a future security hardening sprint.

---

### PASS4-INFO-003: Pact accept/refuse CSRF token in rapports may be proposer's token rather than recipient's

- **Domain:** 4 (Alliance System)
- **File:** rapports.php (pact form embed)
- **Status:** Investigate. If the pact accept form in `rapports.php` is rendered server-side for the logged-in player (recipient), the CSRF token will correctly belong to the recipient's session — no bug. If the form HTML is cached or statically embedded with the proposer's token, this is a real vulnerability. Verify by inspection: load rapports.php as the recipient, view source, check that the CSRF token matches `$_SESSION['csrf_token']` of the recipient. If correct, mark WONTFIX. If incorrect, add to next pass as HIGH.

---

### PASS4-INFO-004: Isotope Stable HP is +40%; checklist said +30% — checklist was stale, code is correct

- **Domain:** 12 (Molecules)
- **File:** `includes/config.php:311`
- **Status:** WONTFIX. The code value `ISOTOPE_STABLE_HP_MOD = 0.40` is correct per the V4 balance overhaul (Phase 14 Sprint N). The stale checklist reference in the audit is the error. Update the audit checklist if it is committed to the repo.

---

### PASS4-INFO-005: Rate limit file-based storage read-check-write race (rate_limiter.php)

- **Domain:** 1 (Security)
- **File:** `includes/rate_limiter.php`
- **Status:** Known, accepted. This allows at most 1-2 extra login attempts per race window (two concurrent requests). The existing file lock (`LOCK_EX`) mitigates most races. Moving to a DB-based rate limiter would fully fix it but is out of scope for this pass. Document the known gap.

---

### PASS4-INFO-006: MEMORY.md states beginner protection is 5 days but config.php defines 3 days

- **Domain:** 9 (Tutorial and New Player)
- **File:** `docs/game/MEMORY.md`, `includes/config.php`
- **Status:** Documentation inconsistency only — config.php is the source of truth. Update MEMORY.md to say "3 days" to match the constant. No code change.

---

## Batch Dependency Graph

```
Batch 1 (HIGH fixes)
  ├── PASS4-HIGH-001: player.php closure — independent
  ├── PASS4-HIGH-002: withTransaction re-entrancy — independent; must land BEFORE any
  │                   code that calls withTransaction inside another withTransaction
  ├── PASS4-HIGH-003: declarations FK — independent (new migration)
  ├── PASS4-HIGH-004: resource_nodes charset — independent (new migration)
  └── PASS4-HIGH-005: season_recap UNIQUE + ON UPDATE CASCADE — independent (new migration)

Batch 2 (MEDIUM DB constraints)
  └── No strict dependency on Batch 1; run after Batch 1 for safety

Batch 3 (MEDIUM DB types + orphans)
  └── Independent of Batch 2

Batch 4 (Security: email injection, GDPR, alliance atomicity)
  └── PASS4-MEDIUM-011 benefits from PASS4-HIGH-002 (withTransaction re-entrancy) being live

Batch 5 (Ranking / season)
  └── PASS4-MEDIUM-012 should run after PASS4-HIGH-005 (UNIQUE constraint prevents dupe ranks)

Batches 6-7 (LOW)
  └── All independent; can run in any order after Batch 1 is deployed

Batch 8 (INFO)
  └── Documentation only; no deployment dependency
```

---

## Migration Files Required (new, in order)

| Migration | Purpose |
|-----------|---------|
| `0055_declarations_fk.sql` | FK declarations → alliances (PASS4-HIGH-003) |
| `0056_fix_resource_nodes_charset.sql` | Convert resource_nodes to latin1 (PASS4-HIGH-004) |
| `0057_season_recap_constraints.sql` | ON UPDATE CASCADE + UNIQUE on season_recap (PASS4-HIGH-005) |
| `0058_fix_compound_fk.sql` | ON UPDATE CASCADE on player_compounds (PASS4-MEDIUM-001) |
| `0059_fix_login_history_and_connectes.sql` | ON UPDATE CASCADE on login_history; widen connectes.ip (PASS4-MEDIUM-002) |
| `0060_moderation_log_fk.sql` | FK moderation_log.moderator_login SET NULL (PASS4-MEDIUM-003) |
| `0061_spec_check_constraints.sql` | CHECK constraints on spec_* columns (PASS4-MEDIUM-004) |
| `0062_vacances_fk_and_molecules_type.sql` | vacances FK; moleculesPerdues BIGINT (PASS4-MEDIUM-006) |

---

## Acceptance Criteria for Pass 4 Complete

- [ ] All 5 HIGH findings have code changes deployed to VPS and GitHub
- [ ] All 13 MEDIUM findings resolved (code change or documented WONTFIX)
- [ ] All 8 new migrations run successfully on live DB (idempotent re-run confirms no error)
- [ ] Test suite: >= 583 tests, 0 failures, assertions >= 3372
- [ ] `grep -rn 'withTransaction' includes/game_actions.php` shows no manual `mysqli_begin_transaction` in the same code path
- [ ] `SHOW CREATE TABLE declarations` contains FK to alliances
- [ ] `SHOW CREATE TABLE resource_nodes` shows `CHARSET=latin1`
- [ ] `SHOW CREATE TABLE season_recap` shows `ON UPDATE CASCADE` and `UNIQUE KEY uq_season_login`
- [ ] `php -l includes/player.php` returns no syntax errors
- [ ] All pages return HTTP 200 on VPS smoke test
