# Pass 3 -- Cross-Domain Architecture Analysis

**Date:** 2026-03-05
**Scope:** Systemic interaction patterns across all subsystems
**Method:** Trace data flow, state coupling, error propagation, and transaction boundaries across the entire request lifecycle

---

## Table of Contents

1. [Global State Coupling](#1-global-state-coupling)
2. [Transaction Boundary Violations](#2-transaction-boundary-violations)
3. [Error Propagation Failures](#3-error-propagation-failures)
4. [Session and Request Lifecycle](#4-session-and-request-lifecycle)
5. [Cache Invalidation Hazards](#5-cache-invalidation-hazards)
6. [Deployment Risk Chain](#6-deployment-risk-chain)
7. [Security Boundary Gaps](#7-security-boundary-gaps)
8. [Performance Coupling](#8-performance-coupling)
9. [Testing Coverage Gaps](#9-testing-coverage-gaps)
10. [Configuration Consistency](#10-configuration-consistency)

---

## 1. Global State Coupling

### P3-AR-001 | CRITICAL | initPlayer Global Pollution Creates Invisible Cross-Page Coupling

- **Systems coupled:** initPlayer() in `player.php`, every private page via `basicprivatephp.php`, `game_actions.php`, `combat.php`, `game_resources.php`, `layout.php`, all page-level PHP files
- **Description:** `initPlayer()` (player.php:99-250) injects approximately 50 variables into `$GLOBALS` via `global` declarations: `$ressources`, `$revenu`, `$constructions`, `$autre`, `$membre`, `$revenuEnergie`, `$placeDepot`, `$points`, `$plusHaut`, `$production`, `$productionCondenseur`, `$listeConstructions`, plus 8x `$revenu{resource}`, 8x `$points{resource}`, 8x `$niveau{resource}`. These globals are read and written by dozens of files without any contract specifying which globals are available, their types, or when they are stale. Any function anywhere in the codebase can silently depend on these globals having been set by a prior `initPlayer()` call, and any function can silently mutate them.
- **Impact:** The entire application operates on an invisible contract: "initPlayer() must have been called with the correct player before any game logic executes." When this assumption breaks (stale cache, wrong player context in combat resolution involving two players, updateActions recursing for opponent), game state corrupts silently. The system cannot be safely refactored because no function signature declares its actual dependencies.
- **Fix:** Introduce a `PlayerState` value object returned by `initPlayer()`. Pass it explicitly to functions that need it. Phase 1: wrap the globals in a `$playerState` array within `initPlayer()` and set `$GLOBALS['playerState']`. Phase 2: convert consumers one file at a time to accept `$playerState` as parameter.

### P3-AR-002 | HIGH | Dual-Player Combat Operates in Single-Player Global Context

- **Systems coupled:** `game_actions.php` (attacker context), `combat.php` (included inline), `initPlayer()` globals, `updateRessources()`, `updateActions()` recursive calls
- **Description:** When combat resolves in `game_actions.php:84-524`, the code operates in the attacker's `initPlayer()` context. At line 98-103, `updateRessources()` and `updateActions()` are called recursively for the opponent, which itself calls `initPlayer($opponent)` -- overwriting the caller's global state (`$constructions`, `$ressources`, `$autre`, `$membre`, etc.) with the opponent's data. Then `combat.php` is `include()`-ed at line 134, inheriting the full local scope of `updateActions()`. By this point, the `$constructions` global may reflect the defender rather than the attacker, depending on execution path and cache state. After combat.php executes, `initPlayer($joueur)` is called again at line 602 to restore the original player, but any intermediate code that read the stale globals between the recursive call and the restore operated on wrong data.
- **Impact:** Potential for combat resolution to use the wrong player's building levels, medal bonuses, or resource values. The overkill cascade damage calculations in combat.php depend on `$nomsRes`, `$nbClasses`, `$bonusMedailles`, which are all globals that could be corrupted by the recursive init cycle. This is a latent correctness bug that manifests under specific timing conditions (e.g., combat involving two players who both have pending combats).
- **Fix:** Make `combat.php` a function `resolveCombat($attackerLogin, $defenderLogin, $actions)` that fetches its own data from DB using explicit queries (most of which it already does), eliminating reliance on inherited scope and globals.

### P3-AR-003 | HIGH | constantesBase.php Duplicates config.php Data in Mutable Global Variables

- **Systems coupled:** `config.php` (defines), `constantesBase.php` (mutable vars), every file that references `$nomsRes`, `$bonusMedailles`, `$paliers*`, `$nbClasses`, etc.
- **Description:** `config.php` defines constants and global arrays (`$BUILDING_CONFIG`, `$FORMATIONS`, `$ISOTOPES`, `$MEDAL_THRESHOLDS_*`, etc.). Then `constantesBase.php` creates a parallel set of mutable copies: `$nomsRes = $RESOURCE_NAMES`, `$bonusMedailles = [1,3,6,10,15,20,30,50]` (hardcoded, not referencing `$MEDAL_BONUSES`), `$paliersTerreur = [5,15,30,60,120,250,500,1000]` (hardcoded, not referencing `$MEDAL_THRESHOLDS_TERREUR`), etc. The game code (combat.php, formulas.php, player.php) references the `constantesBase.php` variables, not the `config.php` originals.
- **Impact:** Dual source of truth. If a developer updates `$MEDAL_THRESHOLDS_TERREUR` in config.php, the actual game continues using the hardcoded `$paliersTerreur` in constantesBase.php. Since both arrays currently have identical values, this bug is latent. Furthermore, because the constantesBase variables are mutable `global` arrays (not `define()` constants), any function could inadvertently mutate them.
- **Fix:** Remove the duplicate arrays in constantesBase.php and replace with aliases: `$paliersTerreur = $MEDAL_THRESHOLDS_TERREUR;` etc. Better yet, reference the config.php arrays directly throughout the codebase and eliminate constantesBase.php as an intermediate layer entirely.

---

## 2. Transaction Boundary Violations

### P3-AR-004 | CRITICAL | Combat Transaction Uses Raw mysqli, Not withTransaction -- Throwable Gap Compounds

- **Systems coupled:** `game_actions.php:109-360`, `combat.php` (700 lines included inline), `database.php:withTransaction()`
- **Description:** The combat resolution path uses `mysqli_begin_transaction($base)` at line 109 and `mysqli_commit($base)` at line 351, with a `catch (Exception $combatException)` at line 357 that calls `mysqli_rollback()`. This is the most critical transaction in the entire system -- it handles molecule updates, resource pillage, building damage, point awards, and report creation. But it has TWO compounding defects: (1) It does not use `withTransaction()`, so it is not covered by any future fixes to that function. (2) Like `withTransaction()` itself (P2-D7-003), it catches `Exception` not `Throwable`, meaning a `TypeError`, `DivisionByZeroError`, `ValueError`, or any `Error` subclass thrown during 700 lines of combat math will bypass the catch, leaving the transaction uncommitted. PHP's default behavior on script termination is to rollback uncommitted transactions -- but only if the script terminates cleanly. If the error is caught by a higher-level handler or the script continues, the connection is left in an open transaction state.
- **Impact:** A single `TypeError` in the 700-line combat.php (e.g., null passed to floor(), which throws TypeError in PHP 8.x strict mode) will: (a) not rollback the partial writes, (b) leave molecules, resources, buildings, and reports in an inconsistent state, (c) potentially lock rows until the connection closes. Given that combat.php performs ~30 separate `dbExecute()` calls, partial completion is the likely outcome.
- **Fix:** (1) Convert combat resolution to use `withTransaction()`. (2) Change `withTransaction()` to catch `\Throwable` instead of `\Exception`. Both changes together close the gap for the entire system.

### P3-AR-005 | HIGH | Resource Transfer (envoi) Has No Transaction Boundary

- **Systems coupled:** `game_actions.php:526-600`, `ressources` table, `rapports` table
- **Description:** The resource transfer processing loop (lines 526-600) deletes the action, creates a rapport, reads the receiver's resources, and updates them -- all as separate, non-transactional operations. If the script dies between deleting the action (line 529) and updating the receiver's resources (line 599), the resources vanish: the action is gone but the receiver never got the resources.
- **Impact:** Resource duplication or loss on server restart, timeout, or OOM during resource transfer processing. Each transfer involves 3+ write operations with no atomicity guarantee.
- **Fix:** Wrap the entire envoi processing block (lines 528-600) in `withTransaction()`.

### P3-AR-006 | HIGH | Season Reset Performs 60+ Queries Without Consistent Transaction Scope

- **Systems coupled:** `performSeasonEnd()` in player.php, `awardPrestigePoints()` in prestige.php, `basicprivatephp.php` (season trigger), `statistiques` table, all player tables
- **Description:** `performSeasonEnd()` (player.php:816-930+) uses `withTransaction()` for its core logic, but `awardPrestigePoints()` (called from within or just before) iterates all players with individual `dbExecute()` calls for each, and the email loop at basicprivatephp.php:146-205 runs entirely outside any transaction. The advisory lock at basicprivatephp.php:128 prevents concurrent resets, but within the single execution, there is no all-or-nothing guarantee across prestige awards, ranking archives, and player resets.
- **Impact:** If the server crashes mid-reset, some players may have received prestige points while others have not, or rankings may be archived without the corresponding player reset. The advisory lock prevents a retry from a concurrent request, but the original incomplete reset state persists.
- **Fix:** Ensure `awardPrestigePoints()` runs inside the same transaction as `performSeasonEnd()`. Add an idempotency flag (e.g., a season_id column) so that a partial reset can be detected and retried.

### P3-AR-007 | MEDIUM | FOR UPDATE Locks Without Transaction in ajouterPoints

- **Systems coupled:** `player.php:ajouterPoints()`, combat.php (caller), `autre` table
- **Description:** `ajouterPoints()` at line 77 executes `SELECT * FROM autre WHERE login=? FOR UPDATE` -- but this function is often called outside any explicit transaction (e.g., from combat.php lines 621-623, which is inside the manual `mysqli_begin_transaction` block, but also from other callers that may not be in a transaction). A `FOR UPDATE` outside a transaction acquires and immediately releases the lock in InnoDB's autocommit mode, providing zero concurrency protection.
- **Impact:** The `FOR UPDATE` gives a false sense of safety. When `ajouterPoints` is called outside a transaction context, the read-then-write pattern is racy. Two concurrent combats resolving for the same player can lose point updates.
- **Fix:** Have `ajouterPoints()` assert it is inside a transaction (check `mysqli_begin_transaction` state or use a global flag). Alternatively, convert the type=1/2/3 paths to use atomic `SET column = column + ?` patterns like type=0 already does.

---

## 3. Error Propagation Failures

### P3-AR-008 | CRITICAL | No Global Error/Exception Handler -- Errors White-Screen or Silently Corrupt State

- **Systems coupled:** Every PHP file, `database.php` (returns false on error), every caller of `dbQuery`/`dbFetchOne`/`dbExecute`, combat.php, game_actions.php
- **Description:** The application has no `set_error_handler()`, `set_exception_handler()`, or `register_shutdown_function()` registered anywhere. PHP 8.2 promotes many former warnings to `TypeError`/`ValueError` exceptions. When these occur: (a) Inside `withTransaction()` or the manual combat transaction, they bypass the `catch (Exception)` block (see P3-AR-004). (b) Outside any try/catch, they terminate the script with an unformatted error that the user sees as a blank page (production `display_errors=off`) or an error disclosure (development). (c) Database helpers `dbQuery()` and `dbExecute()` return `false` on error rather than throwing, so callers that don't check the return value silently continue with corrupted state.
- **Impact:** Three failure modes compound: (1) Uncaught Throwable in transactions leaves DB in inconsistent state. (2) `false`-returning DB functions feed null/false into subsequent queries silently. (3) No logging of fatal errors -- the dual logging system (error_log + gameLog) only captures explicitly logged events, not PHP fatals. A single null dereference in a 700-line included file can corrupt game state for both players in a combat and leave no trace in application logs.
- **Fix:** (1) Register `set_exception_handler()` in `session_init.php` to log + show friendly error page. (2) Register `register_shutdown_function()` to catch fatal errors. (3) Change `dbQuery()`/`dbExecute()` to throw on prepare/execute failure instead of returning false, or create strict variants `dbQueryStrict()`/`dbExecuteStrict()` that throw. (4) Convert `withTransaction()` to catch `\Throwable`.

### P3-AR-009 | HIGH | Dual Logging Systems Create Observability Blind Spots

- **Systems coupled:** `logger.php` (gameLog/logError/logWarn/logInfo), PHP's `error_log()`, `database.php` (uses error_log), all include files
- **Description:** 21 occurrences of `error_log()` exist across 8 files (database.php:4, armee.php:6, constructions.php:2, marche.php:2, attaquer.php:2, connexion.php:1, db_helpers.php:3, game_actions.php:1). 42 occurrences of `logError/logWarn/logInfo` exist across 16 files. The two systems write to different locations: `error_log()` writes to Apache's error log or PHP's configured error log, while `gameLog()` writes to `logs/YYYY-MM-DD.log`. Database errors, the most critical operational failures, are exclusively logged via `error_log()` and never appear in the application log. Combat events, the most important game events, are logged via `logInfo()` and never appear in the system error log.
- **Impact:** An operator monitoring only the application logs misses all database errors. An operator monitoring only the system error log misses all game events and security warnings. There is no single log stream that captures the full picture of system health. During incident investigation, correlating events across both logs requires manual timestamp matching.
- **Fix:** Consolidate to a single logging facade that writes to one destination. Phase 1: Have `database.php` call `logError()` in addition to `error_log()`. Phase 2: Replace all `error_log()` calls with the appropriate `logError`/`logWarn` call. Phase 3: Configure `error_log()` to write to the same log directory as `gameLog()`.

### P3-AR-010 | MEDIUM | Database Helper Functions Swallow Errors Via False Return

- **Systems coupled:** `database.php` (dbQuery, dbFetchOne, dbExecute), every consumer (all game logic)
- **Description:** `dbQuery()` returns `false` on prepare or execute failure (lines 13, 22). `dbFetchOne()` returns `null` when `dbQuery()` returns `false` (line 35). `dbExecute()` returns `false` on failure (lines 62, 69). The callers almost never check these return values. For example, in `revenuEnergie()` (game_resources.php:18): `$constructions = dbFetchOne(...)` -- if this returns null, the code proceeds to `$constructions['pointsCondenseur']` on line 20, which triggers a PHP Warning about accessing an index on null (in PHP 8.2 this is actually a Fatal Error: `Cannot access offset of type string on null`). The error in `dbQuery()` is logged via `error_log()`, but the calling code has no idea it failed.
- **Impact:** A single database timeout or connection drop cascades through the entire request as a chain of null dereferences. The original error (e.g., "MySQL server has gone away") is logged once in error_log, then 5-15 subsequent null-access errors fire, none of which appear in application logs. The user sees either a white screen or corrupted partial output.
- **Fix:** Create `dbFetchOneOrThrow()` and `dbExecuteOrThrow()` variants that throw on failure. Migrate critical paths (combat, resource updates, initPlayer) to use the throwing variants. This surfaces failures immediately at the point of origin rather than cascading through the callstack.

---

## 4. Session and Request Lifecycle

### P3-AR-011 | HIGH | Session Lock Serializes All Requests Per User -- No write_close

- **Systems coupled:** `session_init.php`, `basicprivatephp.php`, PHP's file-based session handler, every private page, AJAX requests
- **Description:** PHP's default file-based session handler acquires an exclusive lock on the session file at `session_start()` (session_init.php:14) and holds it until the script terminates or `session_write_close()` is called. There are zero calls to `session_write_close()` or `session_abort()` anywhere in the codebase (verified by grep). Every request to a private page holds the session lock for the entire duration: auth check, database queries (6-10 in basicprivatephp.php alone), initPlayer (60-80 queries), updateRessources, updateActions (potentially recursing), page rendering, and output.
- **Impact:** Two concurrent requests from the same user (e.g., page navigation + AJAX API call, or two browser tabs) are serialized. The second request blocks at `session_start()` until the first completes. For heavy pages like `classement.php` or combat resolution (which can take 1-3 seconds), this creates visible lag for the user and effectively makes the application single-threaded per user. Combined with initPlayer running 60-80 queries, a single slow query blocks all other requests for that user.
- **Fix:** Call `session_write_close()` in `basicprivatephp.php` immediately after the session data has been read and the session variables updated (after line 95). This releases the lock while the rest of the page processes. Any session writes needed later (which is rare -- most session writes happen in the auth block) would need to re-open the session.

### P3-AR-012 | MEDIUM | basicprivatephp.php Performs 6-12 DB Queries Before Any Page Logic Runs

- **Systems coupled:** `basicprivatephp.php`, `connexion.php`, `initPlayer()`, `updateRessources()`, `updateActions()`, every private page
- **Description:** The include chain for every private page request: (1) session_init.php: session_start, config.php loaded. (2) connexion.php: DB connect, database.php loaded. (3) fonctions.php: 9 module files loaded. (4) basicprivatephp.php: session token verify (1 query), coordinate check (1 query), initPlayer #1 (4 queries minimum), vacation check (1 query), updateRessources (8-15 queries if not vacation), updateActions (variable, potentially dozens), initPlayer #2 (cache hit OR 4 more queries if cache invalidated), online status update (2-3 queries), season check (2 queries). Total: 20-80+ queries before any page-specific code runs.
- **Impact:** Minimum request latency is dominated by the include chain, not the page logic. For a simple page like `credits.php` (static content), the system still executes 20+ queries. This is the systemic root cause of P2-D6-002 (initPlayer runs 2-5 times per page). The architecture forces every page to pay the full "game state update" cost even when the page only needs to display static content.
- **Fix:** Separate "auth only" from "full game state update." Create `basicprivatephp_lite.php` that only does auth + session check without initPlayer/updateRessources/updateActions. Use it for pages that don't need live game state (credits, regles, compte, forum, messages, etc.). Reserve the full include chain for game-critical pages (constructions, armee, attaquer, marche).

---

## 5. Cache Invalidation Hazards

### P3-AR-013 | HIGH | Seven Independent Static Caches With No Coordinated Invalidation

- **Systems coupled:** `initPlayer()` (GLOBALS cache), `revenuEnergie()` (static $cache), `revenuAtome()` (static $cache), `getSpecModifier()` (static $cache), `getCompoundBonus()` (static $cache), `getActiveCatalyst()` (static $cachedCatalyst), `catalystEffect()` (static $cache), `getResourceNodeBonus()` (static $nodesCache)
- **Description:** The codebase has 7 independent caching mechanisms, all using PHP `static` variables that persist for the life of the request. The initPlayer cache in `$GLOBALS['_initPlayerCache']` is explicitly invalidated at basicprivatephp.php:94 before the second `initPlayer()` call, but none of the other 6 caches are ever invalidated. When combat resolves (changing building levels, resources, molecule counts), the static caches in `revenuEnergie()`, `revenuAtome()`, `getSpecModifier()`, and `getCompoundBonus()` continue to return pre-combat values for the remainder of the request. Since `updateActions()` calls `initPlayer()` at line 602 to "refresh," this refreshes the GLOBALS cache but leaves the static caches stale.
- **Impact:** After combat modifies building levels (diminuerBatiment), the `revenuEnergie()` cache still returns the pre-combat energy revenue. This affects the resource update calculation if `updateRessources()` is called again within the same request. The staleness window is bounded to a single HTTP request (static caches reset between requests), but within a single request that processes multiple combats (e.g., a player with 3 pending attacks), each subsequent combat sees stale data from the previous one.
- **Fix:** Either (a) add a `clearAllCaches()` function that resets all 7 static caches and call it when game state changes, or (b) replace static caches with a request-scoped cache service that can be globally invalidated. Option (a) is simpler and sufficient for the current architecture.

### P3-AR-014 | MEDIUM | initPlayer Cache Key Is Player Login Only -- No Version/Timestamp

- **Systems coupled:** `initPlayer()` cache in `$GLOBALS['_initPlayerCache']`, `basicprivatephp.php`, `game_actions.php`, `updateActions()`
- **Description:** The cache key for initPlayer is simply the player login string (player.php:108). There is no generation counter, timestamp, or hash. The cache is invalidated explicitly by `unset($GLOBALS['_initPlayerCache'][$_SESSION['login']])` at basicprivatephp.php:94. But if any code path reads the cached data between a database mutation and the explicit invalidation, it gets stale data. The explicit invalidation is only performed in one place (basicprivatephp.php:94), so any other code path that calls `initPlayer()` after a mutation without first invalidating the cache gets stale data.
- **Impact:** Subtle: `augmenterBatiment()` (player.php) performs a DB write but does not invalidate the initPlayer cache. If `initPlayer()` is called after `augmenterBatiment()` within the same request without an explicit cache clear, it returns the pre-upgrade building level. This is partially mitigated by the fact that `updateActions()` does `unset` + `initPlayer()` at line 602, but only for the action-processing path.
- **Fix:** Either (a) have all mutation functions (`augmenterBatiment`, `diminuerBatiment`, etc.) invalidate the cache after writing, or (b) add a version counter that increments on any write and is checked before returning cached data.

---

## 6. Deployment Risk Chain

### P3-AR-015 | HIGH | No Atomic Deployment -- File-by-File Sync Creates Inconsistency Window

- **Systems coupled:** Git deployment workflow, Apache serving live requests, PHP's include chain (9+ files per request), database migrations
- **Description:** The deployment process (documented in project memory) is `git pull` on the VPS followed by file sync to `/var/www/html/`. PHP does not have atomic module loading: each `require_once`/`include` loads and parses the file at the point of the include statement. During a deployment where files are being copied one-by-one (rsync or git checkout), a request can load a mix of old and new files. For example: `basicprivatephp.php` (new version) loads `fonctions.php` (old version) which loads `game_actions.php` (new version) which calls a function signature that changed in `player.php` (still old version).
- **Impact:** During the ~2-5 seconds of a `git pull` + copy, requests may execute with an inconsistent mix of old and new code. If a deployment changes a function signature, adds/removes a `global` declaration, or modifies a `config.php` constant, the mixed execution can produce fatal errors or silent logic bugs. The risk is proportional to request volume -- with low traffic this is unlikely to manifest, but as the game grows, the window widens.
- **Fix:** Deploy to a staging directory and symlink-swap: `rsync` to `/var/www/html-next/`, then `ln -sfn /var/www/html-next /var/www/html`. The symlink swap is atomic at the filesystem level, so all requests see either the old or new version, never a mix. Alternatively, use `opcache_reset()` after deployment to ensure PHP re-reads all files.

### P3-AR-016 | MEDIUM | Database Migrations Not Coupled to Code Deployment

- **Systems coupled:** `/migrations/` directory (0001-0025+), database schema, all PHP files that reference table/column names
- **Description:** Database migrations are stored in the `migrations/` directory and applied manually. There is no migration runner integrated into the deployment process, no version tracking table in the database, and no pre-deployment check that verifies schema compatibility. If a code change requires a new column (e.g., adding `spec_combat` to `constructions`), the code will fatal-error on every request until the migration is manually applied.
- **Impact:** Any deployment that includes both code changes and schema changes has a window where the code references columns/tables that don't exist yet (if code deploys before migration) or the database has columns the code doesn't know about (if migration runs before code). The only mitigation is manual coordination.
- **Fix:** Add a `schema_version` table and a migration runner script. The deployment script runs: (1) enable maintenance mode, (2) run pending migrations, (3) deploy code (symlink swap), (4) disable maintenance mode. This makes deployments atomic from the application's perspective.

---

## 7. Security Boundary Gaps

### P3-AR-017 | HIGH | combat.php Included As Inline Code Inherits Entire Calling Scope

- **Systems coupled:** `game_actions.php:134`, `combat.php` (700 lines), all variables in scope at the include point
- **Description:** `combat.php` is loaded via `include("includes/combat.php")` at game_actions.php:134, which means it inherits ALL local variables from the calling function `updateActions()`: `$actions`, `$joueur`, `$base`, `$nomsRes`, `$nbRes`, `$nbClasses`, `$molecules`, `$chaine`, `$compteur`, `$totalMoleculesPerdues`, `$moleculesRows`, and all globals. combat.php in turn creates ~80 new local variables (classeAttaquant1-4, classeDefenseur1-4, degatsAttaquant, degatsDefenseur, gagnant, etc.) that all leak back into updateActions() scope. After the include, game_actions.php continues to use variables created by combat.php (line 137-350: `$pointsAttaquant`, `$pointsDefenseur`, `$gagnant`, `$totalPille`, `$attaquantsRestants`, etc.).
- **Impact:** This is a bi-directional scope pollution pattern. combat.php reads variables it assumes exist (but never validates), and game_actions.php reads variables combat.php creates (but never validates). If combat.php is modified to rename a variable (e.g., `$gagnant` to `$winner`), game_actions.php silently reads null for `$gagnant`. The coupling is invisible -- there is no interface, no parameter list, no return value. This is the most fragile code boundary in the entire system.
- **Fix:** Convert combat.php to a function: `function resolveCombat($base, $actions, $nomsRes, $nbClasses) : array` that returns a structured result array `['winner' => int, 'attackerLosses' => [...], 'defenderLosses' => [...], 'pillage' => [...], ...]`. game_actions.php destructures the return value. This makes the interface explicit and testable.

### P3-AR-018 | MEDIUM | Untrusted Input Enters Trusted Code Through $_SESSION['login'] Without Validation

- **Systems coupled:** `basicprivatephp.php`, `inscription.php` (registration), `index.php` (login), every page that uses `$_SESSION['login']`
- **Description:** `$_SESSION['login']` is set during login (index.php) from `$_POST['login']` after verifying credentials. It is then used as a trusted identifier in 343 locations across 51 files, most frequently as a SQL parameter. The session token verification in basicprivatephp.php:12 confirms the session is valid, but the login value itself was sanitized only at registration time (`antihtml(trim($pseudo))`). If a login contains characters that are meaningful in HTML or SQL contexts, the sanitization at registration is the single line of defense for all 343 uses.
- **Impact:** The defense is actually solid for SQL (all uses are parameterized), but for HTML contexts, `$_SESSION['login']` is used in email templates at basicprivatephp.php:157-163 without `htmlspecialchars()`. If a username contains HTML, it flows into the email body unsanitized. The broader architectural issue is that there is no type-safe "ValidatedLogin" wrapper; the raw string flows everywhere.
- **Fix:** (1) Immediately fix the email template to escape `$donnees['login']` and `$winnerName`. (2) Add a validation check at login that rejects logins with unexpected characters (defense in depth). (3) Consider a `PlayerLogin` value object that enforces format constraints at construction.

### P3-AR-019 | MEDIUM | CSRF Token Is Per-Session, Not Per-Request -- Replay Window

- **Systems coupled:** `csrf.php`, every POST form, session_init.php
- **Description:** `csrfToken()` generates a token once per session and reuses it for all subsequent requests (csrf.php:7-9). The token remains valid for the entire session lifetime (up to SESSION_IDLE_TIMEOUT = 3600 seconds). In a tab-based attack or shoulder-surfing scenario, a captured CSRF token remains valid for all future requests in that session.
- **Impact:** Low-to-medium severity: an attacker who obtains a CSRF token (via XSS, network sniffing on non-HTTPS, or physical access) has a 1-hour window to use it for any POST action, not just the specific form it was generated for. The mitigation is that HTTPS (when enabled) prevents network sniffing, and CSP nonces prevent XSS.
- **Fix:** Generate per-request tokens with a rotating window (keep last N tokens valid). Alternatively, use the Synchronizer Token Pattern with per-form tokens bound to the action type. For this small game, the current approach is acceptable but should be documented as a known limitation.

---

## 8. Performance Coupling

### P3-AR-020 | HIGH | updateActions Recursion Can Trigger N-Squared Query Amplification

- **Systems coupled:** `game_actions.php:updateActions()`, `updateRessources()`, `initPlayer()`, combat resolution, `basicprivatephp.php`
- **Description:** `updateActions($joueur)` is called once per page load at basicprivatephp.php:92. Within it, at lines 98-103, for each pending attack, it calls `updateRessources($opponent)` and `updateActions($opponent)`. The recursive `updateActions` has a static guard (`$updating`) that prevents infinite recursion, but the first level of recursion still executes fully: it loads the opponent's pending actions (constructions, formations, attacks), processes them, and for each of the opponent's pending attacks, may call `updateRessources()` and `updateActions()` for a third player (blocked by the static guard). Each `updateRessources()` call executes 15-20 queries. Each `initPlayer()` call executes 4 queries (or 0 from cache).
- **Impact:** If player A has 3 pending attacks against different players, and each of those players has their own pending actions, a single page load by player A triggers: (a) 1 `updateRessources(A)` + 1 `updateActions(A)` (top level), (b) 3x `updateRessources(B,C,D)` + 3x `updateActions(B,C,D)` (recursive), (c) Each recursive updateActions processes that player's pending constructions/formations/attacks. In the worst case (all 4 players have pending attacks against each other), this is O(N^2) where N is the number of pending inter-player actions. The query count can reach 200-500 for a single page load.
- **Impact on other users:** PHP's session lock (P3-AR-011) means player A's browser is completely blocked during this processing. The database connection is held for the entire duration, consuming one slot from the connection pool.
- **Fix:** Process only the current player's actions. Schedule opponent action processing asynchronously (e.g., a cron job that processes all pending actions every 30 seconds). This decouples action resolution from page loads and bounds query count per request.

### P3-AR-021 | MEDIUM | revenuEnergie Executes 8+ DB Queries Per Call Despite Static Cache

- **Systems coupled:** `game_resources.php:revenuEnergie()`, `resource_nodes.php`, `compounds.php`, `prestige.php`, `formulas.php`, `db_helpers.php`
- **Description:** `revenuEnergie()` has a static cache (line 9), but when the cache is cold (first call per player per request), it executes: (1) SELECT constructions (line 18), (2) SELECT constructions again for producteur (line 25 -- redundant), (3) SELECT autre (line 27), (4) SELECT alliances (line 31), (5) 4x SELECT molecules for iode (lines 37-41), (6) SELECT autre for medal data (line 44), (7) SELECT membre for position (line 61), (8) DB query in getResourceNodeBonus (line 64), (9) DB query in getCompoundBonus (line 70). That is 11+ queries for a single resource revenue calculation. The function is called 9 times per player (once for energy, once per 8 atom types via revenuAtome which shares some queries but not all).
- **Impact:** Revenue calculation alone accounts for ~30-50 queries per player per initPlayer cycle. When initPlayer is called 2-3 times per request (basicprivatephp does it twice), this becomes 60-100 queries just for revenue. The `static $cache` mitigates repeat calls within a single `revenuEnergie()` invocation but is keyed by `$joueur-$niveau-$detail`, so different detail levels still cold-miss.
- **Fix:** Batch-load all player data in one query at the start of initPlayer: `SELECT r.*, c.*, a.*, m.* FROM ressources r JOIN constructions c USING(login) JOIN autre a USING(login) JOIN membre m USING(login) WHERE login=?`. Pass the result to all revenue functions as a parameter instead of each function querying independently.

### P3-AR-022 | MEDIUM | Session File Locking + Long Requests = User-Visible Serialization

- **Systems coupled:** session_init.php (session_start), basicprivatephp.php (heavy processing), PHP file session handler, concurrent browser requests
- **Description:** This is the systemic coupling between P3-AR-011 (session lock) and P3-AR-020 (query amplification). When a page load triggers combat resolution with recursive updateActions, the request can take 2-5 seconds. During this time, the session file lock prevents any other request from the same user from proceeding past `session_start()`. If the user clicks a link or an AJAX poll fires, that request queues behind the heavy request. The perceived latency for the user doubles: the first request takes 3 seconds, the second request waits 3 seconds + its own processing time.
- **Impact:** Any user action that triggers combat resolution (visiting any page when attacks are pending) creates a 3-10 second period where the game appears frozen. This is particularly noticeable on mobile (the primary target platform per Framework7 usage), where users may retry, causing additional queued requests.
- **Fix:** Combine P3-AR-011 fix (session_write_close early) with P3-AR-020 fix (async action processing). Together, these reduce per-request session lock time to <100ms and eliminate the query amplification.

---

## 9. Testing Coverage Gaps

### P3-AR-023 | HIGH | Combat Resolution Has Zero Unit Test Coverage

- **Systems coupled:** `combat.php` (700 lines of math and DB writes), `game_actions.php` (combat trigger), all formula functions used by combat
- **Description:** The test suite has 415 tests and 2510 assertions, covering security (CSRF, XSS, rate limiting), game balance (formula verification), and exploit prevention. However, `combat.php` -- the single most complex and critical file in the codebase -- has zero direct test coverage. It cannot be unit-tested in its current form because it is an `include` file that reads variables from calling scope and writes results to calling scope (P3-AR-017). There are no integration tests that execute `updateActions()` with pending combat actions against a test database.
- **Impact:** The 700-line combat file contains: damage calculation, overkill cascade, formation-specific damage distribution, isotope modifiers, catalytique ally bonuses, prestige bonuses, compound bonuses, specialization modifiers, pillage calculation, vault protection, building damage with weighted targeting, combat point scaling, medal bonuses, alliance war tracking, and report generation. Any change to any of these systems is unverifiable without manual playtesting. The file has been modified in at least 10 commits (combat balance overhaul, overkill cascade, V4 mechanics) with no regression testing.
- **Fix:** Extract combat.php into a `resolveCombat()` function (P3-AR-017 fix). Create a CombatTest suite that provides mock player data and verifies: (a) correct winner determination for known inputs, (b) overkill cascade math, (c) formation-specific damage distribution, (d) pillage capping at storage limits, (e) building damage targeting. This is the highest-value testing investment possible for this codebase.

### P3-AR-024 | HIGH | Season Reset Path Is Untestable and Untested

- **Systems coupled:** `basicprivatephp.php:125-230` (season trigger), `performSeasonEnd()`, `awardPrestigePoints()`, `remiseAZero()`, email sending, advisory lock, statistiques table
- **Description:** The season reset logic is embedded in `basicprivatephp.php` (an include file loaded by every private page), guarded by a date comparison and an advisory lock. It cannot be triggered in a test environment without either: (a) manipulating the system clock, (b) directly calling `performSeasonEnd()` (which skips the advisory lock and trigger logic), or (c) manually setting `statistiques.maintenance = 1` and advancing time. There are no tests that verify the full reset cycle: archive rankings, award prestige, reset players, update season start, clear maintenance flag, send emails.
- **Impact:** The season reset is the most consequential operation in the entire game -- it affects every player's data, awards permanent prestige points, and sends emails to all users. It runs at most once per month. Bugs in this path (several have been found and fixed, per the project history) affect all players simultaneously and are irreversible. The 24-hour maintenance window was added specifically because an earlier version had bugs that corrupted player data during reset.
- **Fix:** Extract the season trigger logic from `basicprivatephp.php` into a standalone `seasonReset.php` CLI script that can be run by cron or manually. Create an integration test that: (a) sets up a test database with known player data, (b) calls `performSeasonEnd()`, (c) verifies rankings are archived, prestige points are correct, player data is reset, and the season counter advances.

### P3-AR-025 | MEDIUM | Resource Transfer and Formation Processing Have No Test Coverage

- **Systems coupled:** `game_actions.php:526-600` (envoi), `game_actions.php:44-80` (formation), `updateRessources()`, resource tables
- **Description:** The formation processing (molecule production over time) and resource transfer (envoi) code paths in `game_actions.php` have no test coverage. Formation involves time-based partial completion math (`floor((time() - $derniereFormation) / $tempsPourUn)`) that is particularly error-prone for edge cases (zero tempsPourUn, exactly-on-boundary times, overflow). Resource transfer involves a complex resource-capping calculation that was previously bugged (FINDING-GAME-009).
- **Impact:** Regressions in formation or transfer processing would silently duplicate or destroy resources/molecules. The formation code path is executed on every page load for every player with active formations, making it one of the most-executed code paths.
- **Fix:** Create integration tests that set up test data in `actionsformation` and `actionsenvoi` tables, call `updateActions()`, and verify the resulting database state.

---

## 10. Configuration Consistency

### P3-AR-026 | MEDIUM | constantesBase.php Hardcodes Values That Should Reference config.php

- **Systems coupled:** `config.php` ($MEDAL_BONUSES, $MEDAL_THRESHOLDS_*, $RESOURCE_NAMES, etc.), `constantesBase.php` ($bonusMedailles, $paliers*, $nomsRes, etc.)
- **Description:** A direct code comparison reveals the issue:

  **config.php line 484:** `$MEDAL_BONUSES = [1, 3, 6, 10, 15, 20, 30, 50];`
  **constantesBase.php line 26:** `$bonusMedailles = [1,3,6,10,15,20,30,50];`

  **config.php line 502:** `$MEDAL_THRESHOLDS_TERREUR = [5, 15, 30, 60, 120, 250, 500, 1000];`
  **constantesBase.php line 30:** `$paliersTerreur = [5,15,30,60,120,250,500,1000];`

  The values are identical but constantesBase.php hardcodes them rather than referencing the config.php arrays. Only `$nomsRes = $RESOURCE_NAMES` (line 5) correctly references config.php. There are 10 arrays that are hardcoded duplicates of config.php data.
- **Impact:** Anyone modifying game balance by updating config.php medal thresholds or bonuses will not see the change take effect because the game code references the constantesBase.php copies. This is a maintenance trap that will cause confusion the first time someone tries to use config.php as the "single source of truth" for balance tuning.
- **Fix:** Replace all hardcoded arrays in constantesBase.php with references to config.php arrays:
  ```php
  $bonusMedailles = $MEDAL_BONUSES;
  $paliersTerreur = $MEDAL_THRESHOLDS_TERREUR;
  $paliersAttaque = $MEDAL_THRESHOLDS_ATTAQUE;
  // ... etc for all 10 arrays
  ```

### P3-AR-027 | MEDIUM | health.php Bypasses Database Abstraction Layer

- **Systems coupled:** `health.php`, `database.php` (dbQuery/dbFetchOne/dbExecute), `connexion.php`
- **Description:** `health.php` uses `mysqli_query($base, 'SELECT 1')` (line 14) directly instead of `dbQuery($base, 'SELECT 1')`. It also catches `Exception` (not `Throwable`) at line 16. This means: (a) the health check does not exercise the prepared statement path that all game logic uses, so it can report "healthy" when prepared statements are broken, and (b) a PHP Error (e.g., if `$base` is not a valid connection) is not caught.
- **Impact:** The health check gives a false positive when the database abstraction layer is broken but the raw connection works. This is an unlikely but possible scenario (e.g., prepared statement cache corruption, MySQLi extension bug).
- **Fix:** Replace `mysqli_query($base, 'SELECT 1')` with `dbFetchOne($base, 'SELECT 1 AS ok')`. Change `catch (Exception $e)` to `catch (\Throwable $e)`.

### P3-AR-028 | LOW | File-Based Rate Limiter Has TOCTOU Race and No Cleanup

- **Systems coupled:** `rate_limiter.php`, `index.php` (login), `inscription.php` (registration), filesystem
- **Description:** `rateLimitCheck()` reads a JSON file, filters expired entries, checks the count, and writes back. Between the `file_exists()` check (line 21) and the `file_get_contents()` (line 22), another concurrent request can modify the file. While `file_put_contents()` uses `LOCK_EX` for the write (line 36), the read at line 22 does not use a lock, creating a classic TOCTOU race. Additionally, expired rate limit files are never cleaned up -- they accumulate indefinitely in `data/rates/`.
- **Impact:** Under concurrent requests from the same IP, the rate limiter can allow N+1 or even N+2 requests within the window. For login rate limiting (10 attempts per 5 minutes), this means 11-12 attempts instead of 10 -- a minor weakening. The file accumulation is a slow disk space leak but unlikely to be significant given the game's small user base.
- **Fix:** Use `fopen()` + `flock(LOCK_EX)` for both read and write to eliminate the TOCTOU race. Add a probabilistic cleanup: on each `rateLimitCheck()`, with 1% probability, delete files older than the largest window.

---

## Systemic Interaction Map

The following diagram shows how the top findings interact to form cascading failure chains:

```
                              P3-AR-008: No global error handler
                                          |
                            catches nothing, white screens
                                          |
              +---------------------------+---------------------------+
              |                           |                           |
    P3-AR-004: combat tx            P3-AR-010: DB returns       P3-AR-009: dual
    catches Exception               false, not throws            logging splits
    not Throwable                         |                      observability
              |                     null cascades                      |
    TypeError in combat.php         through 700 lines            operator misses
    = partial commit                      |                      DB errors
              |                           |                           |
              +-----------+---------------+---------------------------+
                          |
              P3-AR-001: 50+ globals from initPlayer
                          |
              +---------------------------+
              |                           |
    P3-AR-002: dual-player        P3-AR-013: 7 static
    combat in single-player       caches, no coordinated
    global context                invalidation
              |                           |
    wrong building levels         stale revenue after
    in combat resolution          combat building damage
              |                           |
              +-----------+---------------+
                          |
              P3-AR-017: combat.php is include
              (scope pollution, untestable)
                          |
              P3-AR-023: combat has ZERO
              test coverage
                          |
              P3-AR-020: N^2 query amplification
              in recursive updateActions
                          |
              P3-AR-011: session lock held
              during entire heavy processing
                          |
              P3-AR-022: user-visible
              serialization on mobile
```

---

## Priority Matrix

### CRITICAL (fix before next feature work)
| ID | Title | Effort |
|----|-------|--------|
| P3-AR-004 | Combat tx catches Exception not Throwable + raw mysqli | 1h |
| P3-AR-008 | No global error/exception handler | 2h |
| P3-AR-001 | 50+ initPlayer globals pollute all pages | 4h (phase 1) |

### HIGH (fix within current sprint)
| ID | Title | Effort |
|----|-------|--------|
| P3-AR-002 | Dual-player combat in single-player global context | 8h |
| P3-AR-005 | Resource transfer has no transaction | 30min |
| P3-AR-006 | Season reset lacks atomic transaction scope | 4h |
| P3-AR-007 | FOR UPDATE without transaction in ajouterPoints | 1h |
| P3-AR-009 | Dual logging systems | 2h |
| P3-AR-011 | Session lock serializes all requests | 30min |
| P3-AR-013 | 7 caches with no coordinated invalidation | 2h |
| P3-AR-015 | No atomic deployment | 2h |
| P3-AR-017 | combat.php include scope pollution | 8h |
| P3-AR-020 | N^2 query amplification in updateActions | 8h |
| P3-AR-023 | Combat has zero test coverage | 8h |
| P3-AR-024 | Season reset is untestable | 4h |

### MEDIUM (schedule for next sprint)
| ID | Title | Effort |
|----|-------|--------|
| P3-AR-003 | constantesBase.php duplicates config.php | 1h |
| P3-AR-010 | DB helpers swallow errors | 4h |
| P3-AR-012 | 6-12 DB queries before any page logic | 4h |
| P3-AR-014 | initPlayer cache has no version key | 1h |
| P3-AR-016 | DB migrations not coupled to deployment | 4h |
| P3-AR-018 | Session login not validated at point of use | 2h |
| P3-AR-019 | CSRF token per-session not per-request | 2h |
| P3-AR-021 | revenuEnergie 8+ queries per call | 4h |
| P3-AR-022 | Session lock + long requests = serialization | (combined with 011+020) |
| P3-AR-025 | Formation/transfer have no tests | 4h |
| P3-AR-026 | constantesBase hardcodes config values | 30min |
| P3-AR-027 | health.php bypasses DB abstraction | 15min |

### LOW
| ID | Title | Effort |
|----|-------|--------|
| P3-AR-028 | Rate limiter TOCTOU race | 1h |

---

## Recommended Fix Sequence

The systemic nature of these findings means fixes should be ordered to maximize cascading benefit:

1. **P3-AR-004 + P3-AR-008** (Throwable + error handler) -- protects all subsequent work from silent failures
2. **P3-AR-009** (unified logging) -- makes all subsequent work observable
3. **P3-AR-005** (envoi transaction) -- quick win, closes data loss risk
4. **P3-AR-011** (session_write_close) -- immediate performance win for all users
5. **P3-AR-003 + P3-AR-026** (constantesBase dedup) -- quick win, prevents balance confusion
6. **P3-AR-017 + P3-AR-023** (combat.php function extraction + tests) -- highest-value refactor, enables all subsequent combat changes to be tested
7. **P3-AR-002 + P3-AR-013** (combat scope isolation + cache invalidation) -- depends on step 6
8. **P3-AR-020** (async action processing or bounded recursion) -- performance fix, depends on step 6 for testability
9. **P3-AR-015 + P3-AR-016** (atomic deployment + migration runner) -- deployment safety
10. **P3-AR-024** (season reset extraction + tests) -- reduces monthly risk

---

## Summary Statistics

| Category | Count | Critical | High | Medium | Low |
|----------|-------|----------|------|--------|-----|
| Global State | 3 | 1 | 1 | 1 | 0 |
| Transactions | 4 | 1 | 2 | 1 | 0 |
| Error Handling | 3 | 1 | 1 | 1 | 0 |
| Session/Request | 2 | 0 | 1 | 1 | 0 |
| Caching | 2 | 0 | 1 | 1 | 0 |
| Deployment | 2 | 0 | 1 | 1 | 0 |
| Security | 3 | 0 | 1 | 2 | 0 |
| Performance | 3 | 0 | 1 | 2 | 0 |
| Testing | 3 | 0 | 2 | 1 | 0 |
| Configuration | 3 | 0 | 0 | 2 | 1 |
| **TOTAL** | **28** | **3** | **11** | **13** | **1** |

The three CRITICAL findings (P3-AR-001, P3-AR-004, P3-AR-008) form a single failure chain: uncaught errors in transactions corrupt game state because globals create hidden dependencies. Fixing these three together eliminates the most dangerous systemic interaction in the codebase.
