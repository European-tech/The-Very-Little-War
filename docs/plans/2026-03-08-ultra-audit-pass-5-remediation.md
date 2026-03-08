# Remediation Plan — Ultra Audit Pass 5
## Date: 2026-03-08
## Summary: 2 critical, 7 high, 28 medium, 16 low (after deduplication)

---

## Deduplication Notes

The following findings appeared in multiple domain reports and have been consolidated
to the highest severity instance with all source domains noted:

- **Combat compound snapshot** (D2A-MED-001, D2B-HIGH-002, D12A-MED-003): promoted to HIGH
- **Dispersee overkill drop on last class** (D2A-MED-002, D2B-HIGH-001): promoted to HIGH
- **diminuerBatiment $nom not whitelisted** (D2B-MED-003, D10B-HIGH-003): promoted to HIGH
- **coordonneesAleatoires TOCTOU** (D9-LOW-002, D10B-HIGH-002): promoted to HIGH
- **withTransaction depth counter / begin error check** (D10A-HIGH-006, D10B-HIGH-001): merged into one HIGH
- **Specialization cross-role ignored** (D12A-MED-002, D12B-MED-002): deduplicated as one MEDIUM
- **joueur.php totalPoints unescaped** (D6-LOW-002, D13-MED-004): promoted to MEDIUM under D13
- **Catalyst attack bonus / defender counter** (D12A-MED-001): kept as distinct MEDIUM
- **Beginner protection re-check at resolution** (D2A-MED-003): kept as MEDIUM
- **withTransaction depth stale on FPM abort** (D10B-HIGH-001): kept as HIGH (implementation gap)

---

## Batch 1: Transaction Infrastructure — CRITICAL severity

### PASS5-CRITICAL-001: Raw mysqli_begin_transaction in game_actions.php bypasses withTransaction depth counter
- **Source:** D2B-CRITICAL-001
- **File:** `includes/game_actions.php:108`
- **Problem:** The attack resolution block calls `mysqli_begin_transaction($base)` directly instead
  of routing through `withTransaction()`. This means the static `$depth` counter in `withTransaction()`
  is still 0 when `diminuerBatiment()` and `ajouterPoints()` subsequently call `withTransaction()`.
  Those inner calls then issue a real `BEGIN`, which in MariaDB/InnoDB silently commits the outer
  transaction. Data written before that inner `withTransaction()` call is committed prematurely and
  cannot be rolled back if a later step fails.
- **Fix:** Replace the manual `mysqli_begin_transaction($base)` / `mysqli_commit($base)` /
  `mysqli_rollback($base)` block in `game_actions.php` (the attack resolution block starting at
  line 108) with `withTransaction($base, function() use (...) { ... })`. The manual `continue`
  inside the old catch block (which is invalid inside a closure) must be replaced with a
  `throw new \RuntimeException('cas_skip')` caught in an outer wrapper that performs the continue.
- **Test:** Trigger a combat that causes a building to decrease. Verify that a simulated exception
  thrown inside `diminuerBatiment()` rolls back the entire combat result including the
  `actionsattaques` CAS update — not just the inner savepoint.

### PASS5-CRITICAL-002: Migration 0017 CHECK constraints not idempotent — re-run crashes on existing DB
- **Source:** D10A-CRITICAL-002
- **File:** `migrations/0017_add_check_constraints.sql`
- **Problem:** `ALTER TABLE ressources ADD CONSTRAINT chk_energie_nonneg CHECK ...` has no guard
  against the constraint already existing. On a production DB that has already run 0017, any
  subsequent deploy attempt (e.g., after a rollback and re-apply) will fail with
  `ERROR 1826 (HY000): Duplicate check constraint name`. The VPS migration runner has no
  idempotency mechanism for CHECK constraints specifically.
- **Fix:** Create `migrations/0063_idempotent_check_constraints.sql` that uses a MariaDB-compatible
  pattern: wrap each ADD CONSTRAINT in a procedure that checks
  `information_schema.TABLE_CONSTRAINTS` before applying, or use `ALTER TABLE ... MODIFY COLUMN`
  approach combined with `DROP CONSTRAINT IF EXISTS` (supported in MariaDB 10.2+) before re-adding.
  Alternatively, version-gate the migration so the runner marks it applied only once. The simplest
  safe approach for MariaDB 10.11: add `DROP CONSTRAINT IF EXISTS chk_x` before each
  `ADD CONSTRAINT chk_x` in a new idempotency-repair migration (0063).
- **Test:** Run the migration twice on the live DB (or a clean test DB) and confirm zero errors on
  the second run. Confirm constraints are still present and enforced after double-run.

---

## Batch 2: Database Schema — CRITICAL/HIGH severity

### PASS5-HIGH-001: Six tables still using utf8 charset — FK / index corruption risk
- **Source:** D10A-CRITICAL-001
- **File:** `migrations/` (new migration needed)
- **Problem:** Tables `messages`, `cours`, `parties`, `reponses`, `sujets`, `tutoriel` retain
  `CHARACTER SET utf8` (MySQL's broken 3-byte utf8, not utf8mb4). This causes silent data
  truncation on 4-byte Unicode characters and can produce FK/index inconsistencies when joined
  against tables that were converted in migration 0033. MariaDB stores index pages differently
  for utf8 vs utf8mb4 collation.
- **Fix:** Create `migrations/0063_convert_remaining_utf8_tables.sql` (or 0064 if 0063 is used
  above). For each of the six tables run:
  `ALTER TABLE <tbl> CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;`
  (matching the game's existing latin1 convention per MEMORY.md — new tables MUST use latin1 for
  FK compatibility with membre). If any column is genuinely storing multibyte content, evaluate
  individually; otherwise blanket latin1 conversion is correct per project policy.
- **Test:** After migration, `SHOW CREATE TABLE messages` must show `DEFAULT CHARSET=latin1`.
  Run `SELECT * FROM messages LIMIT 10` and confirm existing content is intact.

### PASS5-HIGH-002: withTransaction depth counter not decremented if FPM request aborts mid-transaction
- **Source:** D10A-HIGH-006, D10B-HIGH-001 (merged)
- **File:** `includes/database.php:120`
- **Problem:** `withTransaction()` uses a `static $depth` counter that persists only within a
  single PHP process request lifecycle. However, if a PHP-FPM worker is killed mid-transaction
  (OOM, timeout, signal), the counter is lost but the DB connection may briefly remain open
  causing the next reused persistent connection (if pconnect were used) to see stale state.
  More concretely: `mysqli_begin_transaction()` and `mysqli_query("SAVEPOINT")` return bool
  but their return values are never checked. A failed SAVEPOINT leaves `$depth` incremented
  while the DB has no actual savepoint — the subsequent ROLLBACK TO SAVEPOINT fails silently,
  leaving mutations from the inner callable committed.
- **Fix:** In `withTransaction()`, check the return value of `mysqli_begin_transaction()` and
  `mysqli_query("SAVEPOINT $sp")`. If either fails: decrement `$depth` back, throw a
  `\RuntimeException('transaction_begin_failed')` before executing `$fn()`. This prevents the
  callable from running against an inconsistent transaction state.
- **Test:** Mock `mysqli_begin_transaction` to return false (via a test shim). Confirm that
  `withTransaction()` throws, that `$depth` returns to 0, and that `$fn` is never called.

### PASS5-HIGH-003: augmenterBatiment / diminuerBatiment interpolate $nom into SQL without whitelist
- **Source:** D2B-MED-003, D10B-HIGH-003 (promoted)
- **File:** `includes/game_actions.php` (augmenterBatiment, diminuerBatiment functions)
- **Problem:** Both functions construct SQL of the form
  `UPDATE constructions SET $nom = $nom + 1 WHERE login=?` where `$nom` comes from the
  `actionsconstruction.batiment` column value. Although that value originates from the game's own
  INSERT at queue time, the column has no CHECK constraint restricting it to known building names.
  A corrupted or hand-crafted row could inject arbitrary SQL into the SET clause. The building
  name whitelist in `db_helpers.php` is not applied here.
- **Fix:** Add a static whitelist array of allowed building column names (matching the keys of
  `$BUILDING_CONFIG` from config.php) at the top of both functions. Before executing the UPDATE,
  confirm `in_array($nom, $allowedBuildings, true)` and throw or log + return on failure.
- **Test:** Call `diminuerBatiment("'; DROP TABLE constructions; --", $joueur)` in a unit test
  and verify it returns without executing SQL. Verify normal calls still work.

### PASS5-HIGH-004: coordonneesAleatoires TOCTOU race — two simultaneous registrations can land on same tile
- **Source:** D9-LOW-002, D10B-HIGH-002 (promoted)
- **File:** `includes/fonctions.php` or wherever `coordonneesAleatoires()` is defined
- **Problem:** The function SELECTs to find an unoccupied (x,y) coordinate and then INSERTs the
  player at that coordinate in a separate statement without a lock. Two concurrent registrations
  that both read the same empty tile will both attempt to INSERT with the same coordinates,
  with the second silently succeeding (no UNIQUE constraint on membre(x,y)) and producing
  duplicate-coordinate players — invisible on map and buggy in proximity calculations.
- **Fix:** Add a UNIQUE INDEX on `membre(x, y)` via a new migration (0064 or 0065). In
  `coordonneesAleatoires()`, wrap the SELECT + INSERT/UPDATE in a `withTransaction()` block and
  use `SELECT ... FOR UPDATE` on the membre table or the coordinate row, then INSERT with
  `ON DUPLICATE KEY` retry logic (up to N attempts). If all attempts fail, expand the search
  radius.
- **Test:** Simulate 10 concurrent registrations in a test and verify all players land on distinct
  tiles. Confirm the UNIQUE constraint causes a catchable duplicate-key error on conflict.

### PASS5-HIGH-005: Dispersee formation drops accumulated overkill on last active class
- **Source:** D2A-MED-002, D2B-HIGH-001 (promoted)
- **File:** `includes/combat.php:274-311`
- **Problem:** In the Dispersee branch, when the loop reaches the last active defender class,
  `$liveClassesAhead` is 0. The overkill spread block (`if ($disperseeOverkill > 0 && $liveClassesAhead > 0)`)
  does not execute. As a result, any `$disperseeOverkill` accumulated from earlier wipe-outs is
  silently discarded. The last class absorbs less than its fair share of total attacker damage,
  making Dispersee artificially favorable when the attacker's damage would have cascaded.
- **Fix:** After the main Dispersee loop, add a second pass: if `$disperseeOverkill > 0` and
  there is still a surviving last class, re-apply the remaining overkill to it (up to its full
  remaining HP). If all classes are wiped, the overkill is rightfully irrelevant. Alternatively,
  restructure the loop so overkill is applied to the current class before checking ahead classes.
- **Test:** Set up a 1-class defender with Dispersee formation where the attacker damage > 2x the
  class HP. Verify kills equal `floor(totalDamage / hpPerMol)` (or +1 with fractional roll),
  not `floor(sharePerClass / hpPerMol)`.

### PASS5-HIGH-006: Defender compound snapshot read from live DB at combat resolution, not attack launch
- **Source:** D2A-MED-001, D2B-HIGH-002, D12A-MED-003 (promoted)
- **File:** `includes/combat.php:181-184`
- **Problem:** Migration 0039 added `compound_atk_bonus` and `compound_def_bonus` snapshot columns
  to `actionsattaques` and combat.php correctly reads them for the attacker's compound bonus.
  However, the defender's compound defense bonus (`$compoundDefenseBonus`) is read from the
  snapshot column `compound_def_bonus` which was set at attack-launch time from the **attacker's**
  perspective. If the defender activates a compound **after** the attack is launched, the snapshot
  reflects 0.0 (or whatever was live at launch for the defender). This is the intended behavior.
  The actual bug: migration 0039 only stores `compound_def_bonus` as seen at launch but the
  defender's **current** compound state can change before resolution, and there is no mechanism
  to re-snapshot it at resolution time. Cross-check with D12A confirms the snapshot at launch
  is correct **design** — but the snapshot column must be populated at launch using the
  **defender's** live compound state at that moment, not the attacker's.
  Audit of `actionsattaques` INSERT site (wherever the attack action is queued) confirms
  `compound_def_bonus` is set from `getCompoundBonus($base, $attaquant, 'defense_boost')` —
  using `$attaquant` not `$defenseur`. This is the root bug.
- **Fix:** In the attack-queuing code (the INSERT into `actionsattaques`), change the
  `compound_def_bonus` binding to use `getCompoundBonus($base, $defenseur, 'defense_boost')`
  so the defender's compound state at launch time is correctly snapshotted.
- **Test:** Activate a defense compound for player B. Have player A launch an attack against B.
  Deactivate B's compound before resolution. Verify B's defense bonus in the combat report
  reflects the compound active at launch, not 0.0.

### PASS5-HIGH-007: tempsPrecedent not reset in remiseAZero() — first login after season gives massive resource burst
- **Source:** D7-MED-001
- **File:** `includes/game_resources.php` (updateRessources function) and the season reset handler
- **Problem:** `updateRessources()` computes resource production as `(time() - tempsPrecedent) * revenuParSeconde`.
  When `remiseAZero()` resets player data at season end, it does not reset `tempsPrecedent` (the
  last-update timestamp). On first login of the new season, `time() - tempsPrecedent` equals the
  entire inter-season gap (potentially 24h+), triggering a massive production burst that gives
  early-season players a multi-day head start worth of resources.
- **Fix:** In `remiseAZero()` (or `performSeasonEnd()`), add an UPDATE to set
  `tempsPrecedent = UNIX_TIMESTAMP()` (or `time()` in PHP) for all players as part of the season
  reset transaction. Specifically: `UPDATE autre SET tempsPrecedent = UNIX_TIMESTAMP()` (or the
  equivalent column name in the schema — verify against `DESCRIBE autre`).
- **Test:** After running a test season reset, log in as a player immediately. Verify that
  resource gain equals exactly `(login_time - reset_time) * production_rate`, not a larger
  interval.

---

## Batch 3: Combat Logic — MEDIUM severity

### PASS5-MEDIUM-001: Beginner protection not re-verified at combat resolution
- **Source:** D2A-MED-003
- **File:** `includes/game_actions.php:93` (attack resolution block)
- **Problem:** Beginner protection (5-day immunity) is checked when the attack is **queued** in
  `attaquer.php`. However, the check is not repeated at resolution time (inside the attack
  processing loop). If a new player registers after an attack against them is already in flight,
  they could have their protection status checked at queue time (no protection) but a different
  player who just became a target via account rename or concurrent logic gets processed without
  a re-check. More practically: if a defender loses beginner protection during the travel time
  of an attack, this is correct behavior. The real gap is the reverse — a defender who still has
  protection at resolution should be shielded even if they were unprotected at launch.
- **Fix:** At combat resolution, before executing combat (after the CAS guard), fetch the
  defender's registration timestamp. If `time() - membre.dateInscription < BEGINNER_PROTECTION_DAYS * 86400`
  AND `autre.victoires == 0` (or whatever the protection condition is in config), skip the combat:
  rollback the CAS update, log an informational message, and mark `attaqueFaite = 2` (or a new
  status) to indicate "cancelled due to protection". Send a report to attacker noting protection.
- **Test:** Queue an attack against a player with 4 days 23 hours of registration. Delay resolution
  past the 5-day mark via test time-mocking. Verify combat proceeds. Queue an attack against a
  player registered 1 hour ago (from outside protection bypass). Verify combat is cancelled at
  resolution.

### PASS5-MEDIUM-002: Molecule decay FOR UPDATE missing — stale decay stat can undo combat losses
- **Source:** D2B-MED-001, D10B-MED-005 (merged)
- **File:** `includes/game_actions.php:120` (the decay loop inside the combat transaction)
- **Problem:** The decay loop reads `molecules WHERE proprietaire=? ORDER BY numeroclasse ASC`
  without `FOR UPDATE`. If `updateRessources()` is called concurrently (e.g., from another tab)
  and updates molecule counts while the combat transaction is in progress, the decay calculation
  uses stale molecule counts. Worse, if `updateRessources()` completes its own UPDATE after the
  combat transaction has already decremented molecule counts for combat losses, the
  `updateRessources()` write can silently overwrite the combat deductions with a pre-combat
  count, undoing the combat losses.
- **Fix:** Add `FOR UPDATE` to the molecule SELECT inside the combat transaction:
  `SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE`
  This already exists in combat.php for the defender/attacker rows (lines 7, 17) but not in the
  decay loop in game_actions.php.
- **Test:** Simulate concurrent combat resolution and `updateRessources()` call for the same
  player. Verify that molecule counts after both complete are consistent (combat loss applied
  exactly once).

### PASS5-MEDIUM-003: Market buy/sell transactions acquire cours/ressources locks in different order — deadlock risk
- **Source:** D5-MED-001
- **File:** `marche.php` (buy transaction) and `marche.php` (sell transaction)
- **Problem:** The buy flow acquires: (1) `cours` row lock, then (2) `ressources` row lock.
  The sell flow acquires: (1) `ressources` row lock, then (2) `cours` row lock. Two concurrent
  buy+sell operations on the same resource will deadlock: buy holds `cours` and waits for
  `ressources`; sell holds `ressources` and waits for `cours`. MariaDB will resolve via deadlock
  detection but this causes one transaction to fail with an error that is not currently caught
  or retried.
- **Fix:** Enforce a consistent global lock ordering for market transactions:
  always acquire `ressources` lock **before** `cours` lock in both buy and sell paths.
  Reorder the SELECT ... FOR UPDATE statements in the buy transaction so `ressources` is locked
  first, then `cours`. Add deadlock retry logic (up to 3 attempts) wrapping the market
  `withTransaction()` call.
- **Test:** Run 50 concurrent buy/sell pairs for the same resource type in a test. Verify zero
  deadlock errors (all transactions either complete or are cleanly retried).

### PASS5-MEDIUM-004: Alliance member count check lacks FOR UPDATE — can exceed MAX_ALLIANCE_MEMBERS
- **Source:** D4-MED-003
- **File:** `alliance.php` (join alliance handler)
- **Problem:** The join flow reads `COUNT(*) FROM autre WHERE idalliance=?` to check capacity,
  then — in a separate statement — updates `autre SET idalliance=?` for the joining player.
  Two simultaneous join requests for the same alliance both read count = N-1 (one slot left),
  both pass the check, and both insert, resulting in N+1 members in the alliance.
- **Fix:** Inside the `withTransaction()` block for join, use a pessimistic lock:
  `SELECT COUNT(*) FROM autre WHERE idalliance=? FOR UPDATE` (or lock the `alliances` row itself
  with `SELECT id FROM alliances WHERE id=? FOR UPDATE` before counting). This serialises
  concurrent join attempts for the same alliance.
- **Test:** Simulate two concurrent join requests targeting a full-minus-one alliance. Verify
  exactly one succeeds and the other receives an "alliance full" error.

### PASS5-MEDIUM-005: nbMessages counter not atomic — SELECT+UPDATE race in forum
- **Source:** D8-MED-001
- **File:** `forum.php` or equivalent post-creation handler
- **Problem:** New post creation performs `SELECT nbMessages FROM sujets WHERE id=?` then
  `UPDATE sujets SET nbMessages=? WHERE id=?` with the incremented value. Two concurrent posts
  to the same topic both read nbMessages=N, both write N+1, and the actual count ends up N+1
  instead of N+2.
- **Fix:** Replace the SELECT+UPDATE pair with a single atomic:
  `UPDATE sujets SET nbMessages = nbMessages + 1 WHERE id=?`
  Remove the preceding SELECT for this purpose (the SELECT may still be needed for other data,
  but the counter update must be atomic).
- **Test:** Simulate 10 concurrent post creations to the same topic. Verify `nbMessages` equals
  10 after all complete.

---

## Batch 4: Security — MEDIUM severity

### PASS5-MEDIUM-006: Specialization choice TOCTOU — no FOR UPDATE on constructions row
- **Source:** D12B-MED-001
- **File:** `specialisation.php` (or wherever specialization choice is saved)
- **Problem:** The specialization selection reads `constructions WHERE login=?` to verify no
  specialization is already set, then UPDATEs the row. Two concurrent requests from the same
  player can both read null specialization, both pass the check, and both write — potentially
  writing different specializations in rapid succession, leaving the player with a whichever
  last-write wins, or skipping the cost deduction on one of the writes.
- **Fix:** In the specialization handler, open a `withTransaction()` and use
  `SELECT specialisation FROM constructions WHERE login=? FOR UPDATE` before checking and writing.
- **Test:** Send two concurrent specialization POST requests for the same player. Verify only one
  is applied and the prestige/cost is deducted exactly once.

### PASS5-MEDIUM-007: Vacation mode can be activated during active combat
- **Source:** D13-MED-001
- **File:** `vacances.php` (vacation mode activation handler)
- **Problem:** A player with an incoming attack in flight (in `actionsattaques` with
  `attaqueFaite=0` and `tempsAttaque > time()`) can activate vacation mode. Vacation mode
  presumably grants resource freeze or protection. This allows a player who sees an imminent
  attack to exploit the vacation toggle to negate combat consequences.
- **Fix:** Before activating vacation mode, query:
  `SELECT COUNT(*) FROM actionsattaques WHERE (defenseur=? OR attaquant=?) AND attaqueFaite=0 AND tempsAttaque > ?`
  with `time()`. If count > 0, reject the vacation activation with a user-facing message
  ("Vous ne pouvez pas activer le mode vacances pendant un combat en cours").
- **Test:** Queue an attack against a player. Before travel time expires, attempt to activate
  vacation mode via the form. Verify it is rejected. After combat resolves, verify vacation
  mode can be activated normally.

### PASS5-MEDIUM-008: News strip_tags allows event handler attributes — stored XSS vector
- **Source:** D11-MED-001
- **File:** `includes/display.php` or news rendering code
- **Problem:** News content is sanitized with `strip_tags($content, '<b><i><a><br>')` (or similar
  allowlist). `strip_tags()` strips tags but does NOT strip attributes on allowed tags. An admin
  who can post news (or an admin account compromise) could include `<a onclick="evil()">` or
  `<b style="background:url(javascript:...)">` to execute JavaScript in other players' browsers.
- **Fix:** Replace `strip_tags()` with a proper attribute-stripping approach. Options:
  (1) After `strip_tags()`, apply a regex to remove all attributes from remaining tags:
  `preg_replace('/<(\w+)[^>]*>/', '<$1>', $output)` (then re-add safe href-only on `<a>`).
  (2) Use HTMLPurifier (already in project?) or a minimal custom sanitizer that allows only
  specific tags with specific attributes. Given the project stack, option 1 is simpler.
- **Test:** Store a news item containing `<a onclick="alert(1)" href="#">click</a>`. Render it
  and verify the `onclick` attribute is stripped from output.

### PASS5-MEDIUM-009: Banned moderator can still edit posts — no ban check in editer.php
- **Source:** D8-MED-002
- **File:** `forum/editer.php` (or equivalent edit post handler)
- **Problem:** The moderator edit path checks that the user has moderator status but does not
  check whether the moderator account has been banned (`membre.banni = 1` or equivalent flag).
  A banned moderator retains edit capability until their moderator flag is separately revoked.
- **Fix:** In the moderator auth check in `editer.php`, add a secondary check:
  `AND banni != 1` (or the project's ban field name) in the moderator credential validation
  query. Alternatively, centralize the moderator auth in `basicprivatephp.php` to include
  ban status.
- **Test:** Ban a moderator account. Attempt to access `editer.php` as that moderator.
  Verify access is denied with an appropriate message.

### PASS5-MEDIUM-010: Moderation session lacks IP binding unlike admin session
- **Source:** D13-MED-003
- **File:** `includes/basicprivatephp.php` or moderation auth guard
- **Problem:** The admin session validates that the request IP matches the IP stored at login
  (session IP binding — a defense against session hijacking). The moderator session does not
  apply the same binding. A stolen moderator session cookie can be used from any IP.
- **Fix:** Apply the same IP-binding logic used in admin auth to moderator session validation.
  Store `$_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR']` at moderator login and validate it
  on each moderated page load, invalidating the session on mismatch.
- **Test:** Log in as moderator on IP A, copy the session cookie, use it from IP B (simulated
  via X-Forwarded-For in test, or separate machine). Verify session is invalidated and
  redirected to login.

---

## Batch 5: Economy & Alliance — MEDIUM severity

### PASS5-MEDIUM-011: coefDisparition called with $compteur+1 assumption — fragile numeroclasse dependency
- **Source:** D3-MED-001
- **File:** `includes/game_actions.php:130`
- **Problem:** The decay loop passes `$compteur` (1-indexed, incremented each iteration) to
  `coefDisparition($attaquant, $compteur)`. If the `molecules` rows come back in a different
  order than expected (e.g., due to a missing ORDER BY or schema change in numeroclasse), the
  wrong decay coefficient is applied to the wrong molecule class. The ORDER BY numeroclasse ASC
  is present but `numeroclasse` values are assumed to be contiguous 1..N.
- **Fix:** Instead of relying on loop counter position, pass `$moleculesProp['numeroclasse']`
  directly to `coefDisparition()`. Update `coefDisparition()` to accept the class number
  directly (it likely already does — verify its signature). This makes the mapping explicit
  and immune to ordering surprises.
- **Test:** Insert a molecule row with numeroclasse=3 for a player with only classes 1,2,3.
  Verify the correct decay coefficient is applied to class 3, not the loop-position coefficient.

### PASS5-MEDIUM-012: Transfer delivery reads depot level without FOR UPDATE — storage cap bypass
- **Source:** D3-MED-002
- **File:** `transfert.php` or transfer delivery handler
- **Problem:** Resource transfer delivery reads the recipient's depot level to compute storage
  cap, then adds the transferred amount. Without a lock, concurrent transfers to the same player
  can both read the same available capacity and both deliver, causing the player to exceed their
  storage cap.
- **Fix:** Wrap the transfer delivery in a `withTransaction()` that opens with
  `SELECT * FROM constructions WHERE login=? FOR UPDATE` (to lock depot level) and
  `SELECT * FROM ressources WHERE login=? FOR UPDATE` (to lock resource row) before computing
  available space and applying the delivery.
- **Test:** Queue two simultaneous transfers that together exceed the recipient's storage cap.
  Verify only one delivers fully and the other is capped or partially rejected.

### PASS5-MEDIUM-013: Alliance rejoin cooldown check outside transaction — alliance_left_at not cleared on join
- **Source:** D4-MED-002
- **File:** `alliance.php` (join handler)
- **Problem:** Two issues: (1) The cooldown check reads `alliance_left_at` from `autre` before
  the join transaction begins. A race could allow a player to bypass the cooldown by sending
  two concurrent join requests before either transaction completes. (2) After successfully
  joining an alliance, `alliance_left_at` is not reset to NULL, so future leave+rejoin cycles
  compute cooldown from the wrong timestamp.
- **Fix:** Move the cooldown check inside the `withTransaction()` block, using the FOR UPDATE
  lock acquired for the member count check (MEDIUM-004 above). After confirming the join,
  include `UPDATE autre SET alliance_left_at = NULL WHERE login=?` in the same transaction.
- **Test:** Verify that a player who left an alliance and waits the exact cooldown period can
  join. Verify `alliance_left_at` is NULL after joining. Verify concurrent rejoin attempts are
  serialized correctly.

### PASS5-MEDIUM-014: awardPrestigePoints not in transaction — partial PP on crash
- **Source:** D6-MED-003
- **File:** `includes/player.php` or wherever `awardPrestigePoints()` is defined
- **Problem:** Prestige point awards involve multiple writes: update `autre.neutrinos`, potentially
  update prestige tier, log the award. If the process crashes between writes, the player could
  receive PP without the log entry, or receive the log without the PP update, causing accounting
  drift.
- **Fix:** Wrap the full `awardPrestigePoints()` body in `withTransaction($base, function() { ... })`.
- **Test:** Use a test that throws after the first write inside `awardPrestigePoints()`. Verify
  that `autre.neutrinos` was not permanently changed (transaction rolled back).

### PASS5-MEDIUM-015: Alliance description has no max length validation — stored XSS / oversized input
- **Source:** D4-MED-001
- **File:** `alliance.php` (create/edit alliance handler)
- **Problem:** The alliance description field has no server-side maximum length validation.
  A player can submit a description of arbitrary length, potentially exceeding the database
  column size (causing truncation) or storing a very long string that affects layout rendering.
  Additionally, if `htmlspecialchars()` is applied late or inconsistently, very long inputs
  increase XSS surface.
- **Fix:** Add server-side validation: `if (mb_strlen($description) > ALLIANCE_DESC_MAX_LENGTH)`
  where `ALLIANCE_DESC_MAX_LENGTH` is defined in config.php (suggest 500). Add the constant to
  config.php. Apply `htmlspecialchars()` at render time (verify this is already done — if not,
  add it). Add `maxlength` attribute to the HTML textarea.
- **Test:** Submit an alliance description of 5000 characters. Verify it is rejected with an
  error message before database write.

---

## Batch 6: Prestige, Ranking, Display — MEDIUM severity

### PASS5-MEDIUM-016: tradeVolume uncapped in totalPoints calculation — cosmetic cap not enforced
- **Source:** D6-MED-001
- **File:** `includes/player.php` or `includes/formulas.php` (totalPoints calculation)
- **Problem:** Config defines `TRADE_VOLUME_CAP` as a cosmetic display cap. However, the
  `totalPoints` formula uses raw `tradeVolume` from `autre` without capping it, allowing a
  player with extreme market activity to accumulate disproportionate ranking points beyond
  what the display suggests is the ceiling.
- **Fix:** In the `totalPoints` calculation, apply:
  `$cappedTradeVolume = min($autre['tradeVolume'], TRADE_VOLUME_CAP);`
  and use `$cappedTradeVolume` in the sqrt ranking formula instead of raw `tradeVolume`.
- **Test:** Set a player's `tradeVolume` to 10x `TRADE_VOLUME_CAP`. Verify their `totalPoints`
  contribution from trade is identical to a player with exactly `TRADE_VOLUME_CAP`.

### PASS5-MEDIUM-017: Catalyst attack bonus not applied to defender's counter-damage
- **Source:** D12A-MED-001
- **File:** `includes/combat.php:163`
- **Problem:** `$catalystAttackBonus = 1 + catalystEffect('attack_bonus')` is applied to
  `$degatsAttaquant` only. `$degatsDefenseur` does not receive a catalyst bonus even if the
  defender has an active catalyst compound or a Catalytique isotope providing an attack-side
  bonus to their counter-damage. The defender's damage output is computed without this
  multiplier.
- **Fix:** Compute a separate `$catalystDefenseBonus = 1 + catalystEffect('attack_bonus', $actions['defenseur'])`
  (if `catalystEffect()` accepts a player parameter — if not, refactor it to do so), and apply
  it to `$degatsDefenseur`. Verify `catalystEffect()` reads from the correct player's compound
  or isotope state.
- **Test:** Give the defender a Catalytique isotope. Run combat. Verify `$degatsDefenseur` is
  higher than without the isotope by the expected factor.

### PASS5-MEDIUM-018: Specialization cross-role modifiers ignored in combat
- **Source:** D12A-MED-002, D12B-MED-002 (merged)
- **File:** `includes/combat.php:187-190`
- **Problem:** Combat applies `getSpecModifier($attaquant, 'attack')` and
  `getSpecModifier($defenseur, 'defense')` but does not apply cross-role modifiers:
  attacker's defense specialization (reduces their casualties) and defender's attack
  specialization (increases their counter-damage). These modifiers exist in config but are
  not wired into the combat calculation.
- **Fix:** Add:
  ```
  $specDefenseMod_att = getSpecModifier($actions['attaquant'], 'defense'); // reduces attacker HP loss
  $specAttackMod_def  = getSpecModifier($actions['defenseur'], 'attack');  // increases defender counter
  ```
  Apply `$specAttackMod_def` to `$degatsDefenseur` and `$specDefenseMod_att` to HP calculations
  for attacker casualties (the `$hpPerMol` term in the attacker-casualty loop, or as a damage
  reduction multiplier on `$remainingDamage` for the attacker).
- **Test:** Give attacker a defense specialization. Run combat and verify attacker casualties
  are reduced by the expected percentage compared to the same combat without specialization.

### PASS5-MEDIUM-019: archiveSeasonData binds 'd' for BIGINT moleculesPerdues — potential precision loss
- **Source:** D10B-MED-001
- **File:** `includes/player.php` or `season_recap.php` (archiveSeasonData function)
- **Problem:** `dbExecute($base, '...', 'sd...', ..., $moleculesPerdues, ...)` uses bind type `'d'`
  (double/float) for what should be `'i'` (integer) for a BIGINT column. PHP floats are 64-bit
  IEEE 754 but lose integer precision above 2^53 (~9 quadrillion). While current game values are
  unlikely to reach this, using `'d'` for integer columns is incorrect and can cause unexpected
  rounding at extreme values.
- **Fix:** Change the bind type string for `moleculesPerdues` (and any other BIGINT/INT columns
  bound as `'d'`) to `'i'` in `archiveSeasonData()`. Audit other calls in the same function.
- **Test:** Unit test `archiveSeasonData()` with `moleculesPerdues = PHP_INT_MAX`. Verify the
  stored value in `season_recap` matches exactly.

### PASS5-MEDIUM-020: recalculerStatsAlliances N+1 + no transaction — torn read
- **Source:** D10B-MED-002
- **File:** `includes/player.php` or alliance stats recalculation function
- **Problem:** `recalculerStatsAlliances()` iterates over alliances and for each alliance issues
  a separate SELECT to aggregate member stats. This is an N+1 query pattern (one per alliance).
  Additionally, member stats are read outside a transaction, so concurrent player actions during
  the recalculation can produce inconsistent aggregate snapshots (e.g., a player's points are
  counted before and after a combat in the same recalculation pass).
- **Fix:** Replace the N+1 loop with a single aggregated query:
  `SELECT idalliance, SUM(totalPoints) AS total, COUNT(*) AS members FROM autre WHERE idalliance > 0 GROUP BY idalliance`
  Then batch-update `alliances` in one pass. Wrap the entire recalculation in a
  `withTransaction()` with appropriate isolation.
- **Test:** Verify the aggregated query produces identical results to the N+1 loop on a 10-player
  test dataset. Verify total execution time decreases significantly.

---

## Batch 7: Database Indexes & Types — HIGH/MEDIUM severity

### PASS5-MEDIUM-021: invitations table missing indexes on invite/idalliance columns
- **Source:** D10A-HIGH-002
- **File:** `migrations/` (new migration)
- **Problem:** `invitations` is queried on `WHERE invite=?` and `WHERE idalliance=?` in alliance
  flow pages but has no index on either column. These are full table scans on every alliance
  management page load.
- **Fix:** Create migration `migrations/0064_invitations_indexes.sql` (or next available number):
  `ALTER TABLE invitations ADD INDEX idx_invite (invite), ADD INDEX idx_idalliance (idalliance);`
  (Check for FK coverage first — if FK index already covers one column, only add the missing one.)
- **Test:** Run `EXPLAIN SELECT * FROM invitations WHERE invite='testuser'`. Verify `key` is not
  NULL and `type` is `ref` not `ALL`.

### PASS5-MEDIUM-022: messages/rapports missing composite indexes on (destinataire, statut)
- **Source:** D10A-HIGH-003
- **File:** `migrations/` (new migration)
- **Problem:** Every page load for most players triggers queries of the form
  `SELECT * FROM messages WHERE destinataire=? AND statut=0` and similar on `rapports`.
  Without composite indexes these are full table scans on tables that grow unboundedly over a season.
- **Fix:** Add to the same or a new migration:
  ```sql
  ALTER TABLE messages ADD INDEX idx_dest_statut (destinataire, statut);
  ALTER TABLE rapports ADD INDEX idx_dest_statut (destinataire, statut);
  ```
- **Test:** `EXPLAIN SELECT * FROM messages WHERE destinataire='x' AND statut=0`. Verify
  composite index is used.

### PASS5-MEDIUM-023: player_compounds missing unique index — duplicate active compound possible
- **Source:** D10A-MED (player_compounds)
- **File:** `migrations/` (new migration)
- **Problem:** If a player activates the same compound twice (e.g., due to a double-submit or
  browser back), two rows can exist in `player_compounds` for the same (login, compound_id)
  pair. `getCompoundBonus()` may then double-count the bonus.
- **Fix:** Add a unique index:
  `ALTER TABLE player_compounds ADD UNIQUE INDEX uidx_player_compound (login, compound_id);`
  Handle the duplicate key error gracefully in the synthesis handler (log and return an
  "already active" message).
- **Test:** Insert two rows for the same (login, compound_id). Verify the second INSERT fails
  with a unique constraint violation. Verify the handler returns a user-friendly error.

### PASS5-MEDIUM-024: declarations table FK nullable columns allow orphan escape
- **Source:** D10A-HIGH-004
- **File:** `migrations/` (new migration or alter to 0055)
- **Problem:** `declarations.declarant` and `declarations.cible` are declared as VARCHAR with
  FK references to `membre.login`, but the columns are NULLABLE. A NULL value in these columns
  bypasses FK constraint enforcement (FK constraints allow NULL foreign keys in MariaDB — NULL
  matches no row, so the FK check is skipped). Orphan declaration records can persist after
  player deletion.
- **Fix:** Alter `declarations.declarant` and `declarations.cible` to NOT NULL:
  First clean up any existing NULL rows:
  `DELETE FROM declarations WHERE declarant IS NULL OR cible IS NULL;`
  Then: `ALTER TABLE declarations MODIFY declarant VARCHAR(20) NOT NULL, MODIFY cible VARCHAR(20) NOT NULL;`
  Ensure cascade delete from membre propagates (already in 0055 migration — verify).
- **Test:** Attempt `INSERT INTO declarations (declarant, cible, ...) VALUES (NULL, 'player', ...)`.
  Verify it is rejected. Delete a player who has a declaration — verify the declaration is
  cascade-deleted.

### PASS5-MEDIUM-025: Market cours FOR UPDATE ORDER BY LIMIT 1 non-deterministic on concurrent insert
- **Source:** D10B-MED-003
- **File:** `marche.php`
- **Problem:** The market price query `SELECT prix FROM cours WHERE ressource=? ORDER BY id DESC LIMIT 1 FOR UPDATE`
  creates a gap lock on the "latest" row. If two transactions both read the same "latest" row,
  one commits a new price, and the second's FOR UPDATE now references a stale row. The ORDER BY
  DESC LIMIT 1 + FOR UPDATE pattern does not guarantee stable row identity under concurrent INSERT.
- **Fix:** Redesign the cours table to maintain a "current price" column directly in a separate
  `prix_courant` table (one row per resource), which can be cleanly locked with a single
  `SELECT ... FOR UPDATE` by resource key. Alternatively, add a `is_current TINYINT(1)` flag
  and update it atomically when posting a new price. Short-term fix: add error handling and
  retry logic around the market transaction (deadlock detection).
- **Test:** Run 20 concurrent buy/sell operations for the same resource. Verify all transactions
  complete without deadlock errors and the final price reflects a valid history sequence.

---

## Batch 8: UI, Display & Low-Severity Security — MEDIUM/LOW severity

### PASS5-MEDIUM-026: tutoriel.php mission titles not escaped
- **Source:** D9-MED-001
- **File:** `tutoriel.php`
- **Problem:** Mission titles fetched from the `tutoriel` table are rendered into HTML without
  `htmlspecialchars()`. While the tutoriel table is admin-controlled, defense-in-depth requires
  escaping all DB values at render time.
- **Fix:** Wrap all `$mission['titre']` (and similar column) renders with
  `htmlspecialchars($mission['titre'], ENT_QUOTES, 'UTF-8')`.
- **Test:** Insert a tutoriel row with title `<script>alert(1)</script>`. Render the tutorial
  page and verify the script tag appears as literal text, not executed.

### PASS5-MEDIUM-027: sinstruire.php hardcodes upper bound 5 instead of dynamic cours count
- **Source:** D9-MED-002
- **File:** `sinstruire.php`
- **Problem:** A loop or condition uses `if ($step >= 5)` to detect the last lesson step, but the
  actual number of rows in the `cours` table may differ. If lessons are added or removed, this
  hardcoded bound causes off-by-one errors.
- **Fix:** Replace the hardcoded `5` with `$total = dbCount($base, "SELECT COUNT(*) AS nb FROM cours")`.
  Use `if ($step >= $total - 1)` or `if ($step >= $total)` depending on 0/1-indexing convention.
- **Test:** Add a 6th cours row. Verify `sinstruire.php` correctly identifies it as the last
  step without hitting the old hardcoded bound.

### PASS5-MEDIUM-028: joueur.php totalPoints / coordinates rendered unescaped
- **Source:** D6-LOW-002, D13-MED-004, D13-LOW-002 (merged)
- **File:** `joueur.php`
- **Problem:** `$totalPoints` and coordinate values (x, y) are rendered directly into HTML
  without `htmlspecialchars()`. While these are numeric, defense-in-depth requires escaping.
  `totalPoints` is a computed float that could in theory contain unexpected values if the
  calculation path returns a non-numeric on error.
- **Fix:** Wrap all output of `$totalPoints`, `$x`, `$y` with `(int)` cast for integer values
  and `number_format()` for display-formatted floats, then `htmlspecialchars()` around any
  string interpolation into HTML attributes.
- **Test:** Manually set `totalPoints` to a string `'"><script>alert(1)</script>'` in a test
  fixture. Verify it is escaped in output.

---

## Batch 9: Low Severity — Code Quality & Minor Security

### PASS5-LOW-001: != vs !== in password comparison (type-coercion risk)
- **Source:** D1-LOW
- **File:** `includes/connexion.php` or login handler
- **Problem:** Password hash comparison uses `!=` instead of `!==`. PHP's `!=` applies type
  coercion — while unlikely to cause a practical bypass with bcrypt hashes, strict comparison
  is the correct practice.
- **Fix:** Change `if ($hash != $inputHash)` to `if ($hash !== $inputHash)`.
- **Test:** Verify login still works normally. Verify a wrong password is rejected.

### PASS5-LOW-002: MD5 fallback branch still active in login flow
- **Source:** D1-LOW
- **File:** Login handler (inscription.php or connexion path)
- **Problem:** MD5 auto-migration code remains active. Per MEMORY.md this was intentional for
  backward compatibility, but it represents ongoing exposure. Any player who has never logged
  in since the bcrypt migration still has an MD5 hash in the DB.
- **Fix:** Add a cron or admin-tool to identify accounts still with MD5 hashes:
  `SELECT login FROM membre WHERE password NOT LIKE '$2y$%'`. Force a password reset email
  for those accounts. Set a deadline (e.g., end of current season) after which MD5 accounts
  are locked and the fallback branch is removed.
- **Test:** Verify that after the deadline removal, a login attempt with a valid MD5 password
  is rejected and the player receives a password-reset notification.

### PASS5-LOW-003: connectes INSERT race — duplicate login in connectes table
- **Source:** D1-LOW
- **File:** `includes/basicprivatephp.php` or session init
- **Problem:** On concurrent page loads (e.g., AJAX + main page), two requests can both attempt
  to INSERT into `connectes` for the same login, violating the PRIMARY KEY added in migration
  0016. Currently this causes a silent mysql error (error_log) but the page still loads. The
  duplicate-key error wastes resources.
- **Fix:** Change `INSERT INTO connectes` to `INSERT INTO connectes ... ON DUPLICATE KEY UPDATE derniere_activite=VALUES(derniere_activite)` (or use `REPLACE INTO`). This is atomic and avoids the race.
- **Test:** Fire two simultaneous requests for the same player. Verify no SQL error in error_log
  and `connectes` has exactly one row for that player.

### PASS5-LOW-004: regles.php hardcodes balance percentages instead of config constants
- **Source:** D9-LOW
- **File:** `regles.php`
- **Problem:** Rule descriptions contain hardcoded percentages (e.g., "40% bonus en embuscade",
  "50% absorption en phalange") that do not reference `FORMATION_AMBUSH_ATTACK_BONUS` or
  `FORMATION_PHALANX_ABSORB` from config.php. If balance constants change, regles.php becomes
  silently outdated.
- **Fix:** Load config.php in regles.php (it likely already is via constantesBase.php). Replace
  hardcoded percentage strings with PHP expressions:
  `<?= round(FORMATION_AMBUSH_ATTACK_BONUS * 100) ?>%`
  Do this for all balance values that have corresponding config constants.
- **Test:** Change `FORMATION_AMBUSH_ATTACK_BONUS` to 0.50 in config. Reload regles.php and
  verify "50%" appears in the ambush description automatically.

### PASS5-LOW-005: historique.php sub htmlspecialchars at wrong time — double-encoding risk
- **Source:** D13-LOW
- **File:** `historique.php`
- **Problem:** `htmlspecialchars()` is applied to a string that has already been processed by
  `htmlspecialchars()` earlier in the pipeline (or a BBCode renderer that outputs HTML). This
  causes double-encoding: `&amp;` displays as `&amp;amp;` in the browser.
- **Fix:** Audit the rendering pipeline for `historique.php`. Apply `htmlspecialchars()` only
  once, at the final output step, to raw data. Do not apply it to already-encoded strings.
- **Test:** Create a history entry containing `&` in player/alliance name. Verify it displays
  as `&` not `&amp;` in the rendered page.

### PASS5-LOW-006: layout.php PHP_SELF explode repeated 5 times — refactor to basename()
- **Source:** D11-MED-003
- **File:** `includes/layout.php`
- **Problem:** `$currentPage = explode('/', $_SERVER['PHP_SELF'])` (or similar pattern to derive
  the current filename) is repeated in 5 places in layout.php. This is redundant and misuses
  `$_SERVER['PHP_SELF']` (which can be manipulated by attackers in some configurations).
- **Fix:** At the top of layout.php, compute once:
  `$currentPage = basename($_SERVER['PHP_SELF']);`
  Replace all 5 occurrences of the explode pattern with `$currentPage`.
  `basename()` is safe against path traversal in this context.
- **Test:** Navigate to each page that uses layout.php. Verify the active nav item is still
  highlighted correctly.

### PASS5-LOW-007: health.php missing X-Content-Type-Options header
- **Source:** D11-MED-004
- **File:** `health.php`
- **Problem:** `health.php` outputs JSON but does not send `X-Content-Type-Options: nosniff`.
  Browsers may sniff content type on this endpoint. All other pages send this header via Apache
  config or CSP headers — health.php was missed.
- **Fix:** Add `header('X-Content-Type-Options: nosniff');` alongside the existing
  `header('Content-Type: application/json')` in health.php.
- **Test:** Curl health.php and verify `X-Content-Type-Options: nosniff` is present in response
  headers.

### PASS5-LOW-008: cleanup_old_data.php uses interpolated variable in raw query
- **Source:** D13-MED-002
- **File:** `cleanup_old_data.php`
- **Problem:** A query uses string interpolation of a variable (presumably a date or count
  threshold) into a raw `mysqli_query()` call rather than a prepared statement. Even if the
  variable originates from config, direct interpolation bypasses the defensive layer.
- **Fix:** Replace the raw query with a `dbExecute($base, 'DELETE FROM ... WHERE created_at < ?', 'i', $threshold)`
  prepared statement call.
- **Test:** Run cleanup with a threshold value containing SQL injection payload. Verify the
  payload is treated as a literal value and no unintended deletion occurs.

### PASS5-LOW-009: countdown.js and framework7.min.js served without SRI hash
- **Source:** D11-LOW
- **File:** `includes/layout.php` or equivalent head template
- **Problem:** `js/countdown.js` (project-local file served from same origin — SRI not strictly
  needed but CDN-fetched `framework7.min.js` has no SRI hash) is loaded without Subresource
  Integrity verification. If the CDN is compromised, malicious JS is loaded silently.
- **Fix:** For `framework7.min.js` (CDN): add `integrity="sha384-..."` and `crossorigin="anonymous"`.
  Compute the hash with:
  `curl -s https://cdn.jsdelivr.net/npm/framework7@... | openssl dgst -sha384 -binary | openssl base64 -A`
  For `countdown.js` (same-origin): SRI is not required but can be added as defense-in-depth.
- **Test:** Load a page in a browser with CSP `require-sri-for script` (or manually verify the
  integrity attribute is present and correct using browser dev tools).

### PASS5-LOW-010: moderation resource grant hardcodes 100000 instead of config constant
- **Source:** D13-LOW
- **File:** Admin/moderation resource grant handler
- **Problem:** `$amount = 100000` is hardcoded when granting resources via admin panel. Should
  reference a config constant for auditability and balance tuning.
- **Fix:** Define `ADMIN_RESOURCE_GRANT_DEFAULT = 100000` in config.php. Replace the literal with
  the constant. Add a validation cap: `$amount = min((int)$_POST['amount'], ADMIN_RESOURCE_GRANT_MAX)`.
- **Test:** Verify the grant form defaults to the config value and that an admin cannot grant
  more than the configured maximum in a single action.

### PASS5-LOW-011: Dispersee last-class overkill (remaining LOW companion to HIGH-005)
- **Source:** D2A-LOW-001 — Building level 0 targetable in combat despite no damage being applied
- **File:** `includes/combat.php` (diminuerBatiment call site)
- **Problem:** `diminuerBatiment()` accepts any building name including those already at level 0.
  The function itself guards against going below 0 (via GREATEST(0,...)), but the targeting
  selection in combat still selects a level-0 building as a valid target, wasting the overkill
  cascade on a no-op.
- **Fix:** In the building targeting logic, filter out buildings already at level 0 before
  selecting the target. Only buildings with level >= 1 should be eligible for combat damage.
- **Test:** Set a defender's `champdeforce = 0`. Run a combat that would target it. Verify a
  different eligible building is targeted instead (or no building if all are at 0).

### PASS5-LOW-012: lcg_value() predictable randomness in combat
- **Source:** D2A-LOW-002
- **File:** `includes/combat.php` (multiple `lcg_value()` calls)
- **Problem:** `lcg_value()` uses a linear congruential generator seeded from system time. Its
  output is predictable if an attacker can infer or control the seed. For fractional casualty
  rounding, a more secure source like `random_int(0, PHP_INT_MAX) / PHP_INT_MAX` would be
  preferable.
- **Fix:** Replace `lcg_value()` calls in combat.php with a helper:
  `function combatRand(): float { return random_int(0, 1000000) / 1000000.0; }`
  Use `combatRand()` in all fractional-remainder probability checks.
- **Test:** Verify statistical distribution of kills across 10000 simulated combat rounds is
  uniform. Verify `combatRand()` produces values in [0, 1).

### PASS5-LOW-013: Market chart LIMIT uses string concatenation
- **Source:** D5-LOW
- **File:** `marche.php` or chart data endpoint
- **Problem:** A query of the form `"SELECT * FROM cours ORDER BY id DESC LIMIT " . $limit` uses
  string concatenation for the LIMIT clause. PHP's `dbQuery()`/`dbExecute()` cannot bind LIMIT
  as a parameter in older MySQL drivers. While $limit likely comes from config, it's still not
  validated as an integer.
- **Fix:** Cast the limit to integer before concatenation: `(int)$limit`. Add a bounds check:
  `$limit = max(1, min((int)$limit, 1000));`. This is safe since LIMIT is not user input,
  but should be explicitly cast for clarity and to pass static analysis.
- **Test:** Set `$limit` to `"10; DROP TABLE cours"` in a test. Verify the cast produces `10`
  and the query executes normally.

### PASS5-LOW-014: Absence report shows unformatted float hours
- **Source:** D3-MED-003
- **File:** Absence/comeback report display
- **Problem:** Absence duration is displayed as a raw PHP float (e.g., "47.856372 heures") instead
  of a human-readable formatted string.
- **Fix:** Format the duration:
  `$heures = floor($absenceSeconds / 3600);`
  `$minutes = floor(($absenceSeconds % 3600) / 60);`
  Display: `"$heures h $minutes min"`.
- **Test:** Set an absence of 47.856 hours. Verify display shows "47 h 51 min".

### PASS5-LOW-015: connectes visitor cleanup on every login — performance overhead
- **Source:** D7-LOW
- **File:** `includes/basicpublicphp.php` or login handler
- **Problem:** On every successful login, a DELETE query purges old visitor records from
  `connectes`. If this runs on every page load for every logged-in user, it creates unnecessary
  DB contention. This should run periodically (cron) rather than on each request.
- **Fix:** Move the `DELETE FROM connectes WHERE ...` cleanup to the cron job that already runs
  log rotation (added in Batch Q). Add it as a step: `DELETE FROM connectes WHERE derniere_activite < ?`
  with a 30-minute threshold. Remove the per-login cleanup.
- **Test:** Verify the cron removes stale connectes rows. Verify page load time decreases
  marginally under load (benchmark before/after).

### PASS5-LOW-016: bilan.php hardcodes specialization modifier values instead of reading from config
- **Source:** D12B-LOW-001, D12B-LOW-002
- **File:** `bilan.php`
- **Problem:** bilan.php displays specialization bonuses with hardcoded percentage values (e.g.,
  "+15% attack") rather than reading from `$SPECIALIZATION_CONFIG` or the relevant config
  constant. If balance is tuned, bilan.php becomes silently wrong.
- **Fix:** Load the specialization config in bilan.php and compute display values from constants:
  `round(SPEC_ATTACK_BONUS * 100)` etc. Reference the same config keys used in combat calculation.
- **Test:** Change a specialization constant in config.php. Reload bilan.php and verify the
  displayed value updates accordingly.

---

## Batch 10: Informational / Deferred

The following findings are acknowledged but deferred to a dedicated pass or are informational only:

### PASS5-INFO-001: GDPR — Raw IP stored in membre/connectes/login_history (D1-MEDIUM)
- **Deferred:** Requires legal assessment, privacy policy update, and potentially IP hashing
  or pseudonymization. Not a runtime security bug. Schedule for a dedicated GDPR compliance pass.

### PASS5-INFO-002: SECRET_SALT in PHP source (D1-MEDIUM)
- **Deferred:** Moving to .env requires web server configuration changes coordinated with HTTPS
  setup. Tracked in existing HTTPS / .env TODO.

### PASS5-INFO-003: CSRF rotation UX issue (D1-MEDIUM)
- **Deferred:** Current CSRF implementation is functional. Rotation on every request causes
  back-button breakage. Acceptable tradeoff for now; revisit when SPA migration is considered.

### PASS5-INFO-004: Admin UI shows raw IPs (D1-MEDIUM)
- **Deferred:** Follows from INFO-001 GDPR work.

### PASS5-INFO-005: Season countdown hardcodes 3600 (D7-MED-003)
- **Status:** Informational. The value of 3600 (1 hour) in countdown.js is correct per design.
  Replace with a PHP-injected config value when template rendering is unified. Low priority.

### PASS5-INFO-006: index.php redundant session_init include (D7-LOW)
- **Fix inline:** Remove the duplicate `require_once` call. One-line change, no test needed.

### PASS5-INFO-007: Forum [url] link text can nest [img] — phishing vector (D8-LOW)
- **Deferred:** Requires BBCode parser refactor. Low severity given the small closed player
  community. Revisit in a BBCode security pass.

### PASS5-INFO-008: war end unilateral design (D4-LOW)
- **Deferred:** Design decision, not a bug. Requires game design discussion.

### PASS5-INFO-009: maintenance.php SESSION_IDLE_TIMEOUT not checked (D7-MED-002)
- **Fix inline:** Add `if (time() - $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT) { session_destroy(); }` at the top of maintenance.php guard.

---

## Execution Order Summary

| Batch | Findings | Severity | Effort |
|-------|----------|----------|--------|
| 1 | CRITICAL-001, CRITICAL-002 | Critical | High — requires refactor of attack resolution block and migration strategy |
| 2 | HIGH-001 to HIGH-007 | High | High — schema migrations, combat logic, transaction wrappers |
| 3 | MED-001 to MED-005 | Medium | Medium — combat and game logic fixes |
| 4 | MED-006 to MED-010 | Medium | Medium — security hardening |
| 5 | MED-011 to MED-015 | Medium | Medium — economy and alliance fixes |
| 6 | MED-016 to MED-020 | Medium | Medium — prestige, ranking, display |
| 7 | MED-021 to MED-025 | Medium | Low-Medium — migrations and index additions |
| 8 | MED-026 to MED-028 | Medium/Low | Low — escaping and display fixes |
| 9 | LOW-001 to LOW-016 | Low | Low — code quality and minor hardening |
| 10 | INFO-001 to INFO-009 | Info | Deferred or inline one-liners |

## Migration Numbers Required

The following new migrations are needed (next available after 0062):
- `0063_idempotent_check_constraints.sql` — DROP IF EXISTS + re-add CHECK constraints (CRITICAL-002)
- `0064_convert_remaining_utf8_tables.sql` — latin1 conversion for 6 tables (HIGH-001)
- `0065_invitations_indexes.sql` — idx_invite, idx_idalliance (MEDIUM-021)
- `0066_messages_rapports_composite_indexes.sql` — composite indexes (MEDIUM-022)
- `0067_player_compounds_unique.sql` — unique constraint (MEDIUM-023)
- `0068_declarations_not_null.sql` — NOT NULL on declarant/cible (MEDIUM-024)
- `0069_membre_coordinates_unique.sql` — UNIQUE(x,y) on membre (HIGH-004)
- `0070_alliance_desc_maxlength.sql` — add ALLIANCE_DESC_MAX_LENGTH check or column constraint

## Total Finding Count (Post-Dedup)

| Severity | Count |
|----------|-------|
| Critical | 2 |
| High | 7 |
| Medium | 28 |
| Low | 16 |
| Info/Deferred | 9 |
| **Total** | **62** |

Note: Raw input across all 16 domain reports was approximately 91 findings across all severity
levels. After deduplication (15 duplicates collapsed), 62 unique actionable findings remain
plus 9 informational/deferred items.
