# Pass 25 Raw Findings

*Note: FLOW-COMBAT, FLOW-ALLIANCE, FLOW-PRESTIGE agents were cut off before producing final reports. Their best partial analysis is included. FLOW-REGISTRATION and FLOW-SEASON also incomplete.*


---

## AUTH

Now I have all the information needed. Let me compile the findings.

---

# AUTH Domain Audit -- Pass 25

## Findings

### [MEDIUM]-001: compte.php email change does not normalize to lowercase before duplicate check

- **File:** /home/guortates/TVLW/The-Very-Little-War/compte.php:155
- **Domain:** AUTH
- **Description:** When changing email, the new email value is taken directly from `$_POST['changermail']` without calling `strtolower()` before the duplicate check or the UPDATE. The code on line 155 reads:
  ```php
  $newEmail = $_POST['changermail'];
  $existingCount = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ? AND login != ?', 'ss', $newEmail, $_SESSION['login']);
  ```
  By contrast, `inscription.php` correctly normalizes: `$emailInput = strtolower(trim($_POST['email']));` (line 27). If MariaDB uses a case-sensitive collation for the email column, a user could register `User@Example.com` via the email-change form even though `user@example.com` already exists. Even with a case-insensitive collation, the stored email will have inconsistent casing compared to the registration flow.
- **Impact:** Potential duplicate email bypass (depending on collation), and inconsistent email storage that complicates downstream matching (e.g., password reset, notifications).
- **Suggested fix:**
  ```php
  // BEFORE (line 155):
  $newEmail = $_POST['changermail'];
  
  // AFTER:
  $newEmail = strtolower(trim($_POST['changermail']));
  ```

### [MEDIUM]-002: compte.php performs no estExclu check before account mutations

- **File:** /home/guortates/TVLW/The-Very-Little-War/compte.php (entire file)
- **Domain:** AUTH
- **Description:** The `basicprivatephp.php` guard on line 39 checks `estExclu = 0` during session validation, so a banned player should not normally reach `compte.php`. However, there is a TOCTOU window: a moderator can ban a player (set `estExclu = 1`) between the `basicprivatephp.php` check and `compte.php` processing the POST. In that window, the banned player can change their password (line 72-125), change their email (line 127-167), change their description (line 169-178), upload an avatar (line 180-218), activate vacation mode (line 30-68), or delete their account (line 8-28). None of these POST handlers re-verify `estExclu` before mutating.
- **Impact:** A banned player who has an active session could change their password or email during the brief TOCTOU window, potentially complicating moderation (e.g., new password set after ban, email changed to evade tracking). The account deletion path is less concerning since deletion is the desired outcome for banned accounts anyway.
- **Suggested fix:** Add a single estExclu guard at the top of `compte.php`, after the `basicprivatephp.php` include and before any POST handling:
  ```php
  // After line 3 (include basicprivatephp.php):
  $bannedCheck = dbFetchOne($base, 'SELECT estExclu FROM membre WHERE login = ?', 's', $_SESSION['login']);
  if (!$bannedCheck || (int)$bannedCheck['estExclu'] === 1) {
      session_destroy();
      header('Location: index.php');
      exit;
  }
  ```

### [MEDIUM]-003: inscription.php does not set `$_SESSION['login']` after successful registration

- **File:** /home/guortates/TVLW/The-Very-Little-War/inscription.php:67-73
- **Domain:** AUTH
- **Description:** After successful registration, the code regenerates the session, sets `csrf_token`, `session_created`, `last_activity`, and `session_token`, and writes the session token to the DB. However, it never sets `$_SESSION['login']`:
  ```php
  session_regenerate_id(true);
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  $_SESSION['session_created'] = time();
  $_SESSION['last_activity'] = time();
  $_SESSION['session_token'] = bin2hex(random_bytes(32));
  dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $_SESSION['session_token'], $loginInput);
  header("Location: index.php?inscrit=1"); exit;
  ```
  The redirect goes to `index.php?inscrit=1` which is a public page, so the user must still log in manually. This means the session token stored in DB is orphaned -- no session has the matching login to validate against it. While this is not an active vulnerability (the user just logs in normally), it leaves a dangling DB token that will never be matched or cleared until the next successful login overwrites it.
- **Impact:** Low functional impact since the user must log in anyway, but the orphaned session token in the DB is unnecessary state. If a future code change auto-logs in after registration, the missing `$_SESSION['login']` would cause the session validation in `basicprivatephp.php` to fail.
- **Suggested fix:** Either (a) set `$_SESSION['login'] = $loginInput;` to auto-login after registration, or (b) remove the session token DB write since it serves no purpose without a matching session login.

### [LOW]-001: Legacy MD5 password fallback still active in trois locations

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php:57, /home/guortates/TVLW/The-Very-Little-War/compte.php:92, /home/guortates/TVLW/The-Very-Little-War/compte.php:140
- **Domain:** AUTH
- **Description:** Three code paths still accept MD5-hashed passwords with auto-upgrade to bcrypt: login (basicpublicphp.php:57), password change verification (compte.php:92), and email change verification (compte.php:140). For a 15-year-old game this is expected as a migration path, but after sufficient time all accounts should have been upgraded. The MD5 fallback uses `hash_equals(md5($passwordInput), $storedHash)` which is timing-safe but MD5 itself provides no brute-force resistance.
- **Impact:** Any account that has never logged in since the bcrypt migration retains its MD5 hash. If the database is compromised, these MD5 hashes are trivially crackable. This is a diminishing risk as accounts log in and get upgraded.
- **Suggested fix:** Add a migration deadline. After a defined date (e.g., 6 months from bcrypt deployment), force MD5-hashed accounts to reset their password via email rather than accepting MD5 at login. Add a log entry when MD5 fallback is triggered to track remaining accounts:
  ```php
  logWarn('AUTH', 'MD5 fallback triggered', ['login' => $loginInput]);
  ```

### [LOW]-002: deconnexion.php logout confirmation page has no `</html>` closing tag

- **File:** /home/guortates/TVLW/The-Very-Little-War/deconnexion.php:74
- **Domain:** AUTH
- **Description:** The HTML output at the end of `deconnexion.php` is missing the closing `</html>` tag. The file ends with `</body>` on line 73 and a blank line 74. While browsers tolerate this, it is a minor standards compliance issue.
- **Impact:** No security impact. Cosmetic/standards issue only.
- **Suggested fix:** Add `</html>` after line 73.

---

No CRITICAL findings.

No HIGH findings.

## Summary

**0 CRITICAL, 0 HIGH, 3 MEDIUM, 2 LOW**

The AUTH domain is in strong shape after the extensive prior remediation passes. The key remaining items are:

1. **MEDIUM-001**: Email change path lacks `strtolower()` normalization, creating potential case-sensitivity inconsistency with the registration path.
2. **MEDIUM-002**: No `estExclu` re-check in `compte.php` before mutations, leaving a TOCTOU window if a ban is applied mid-session.
3. **MEDIUM-003**: Registration writes an orphaned session token to DB without setting `$_SESSION['login']`, creating unused state.
4. **LOW-001**: MD5 fallback is still active in 3 locations -- acceptable for migration but should have a sunset plan.
5. **LOW-002**: Minor missing `</html>` tag in logout page.



---

## INFRA-SECURITY

Now I have a comprehensive view of all the audited files. Let me compile my findings.

---

## INFRA-SECURITY Domain Audit -- Pass 25

### Checklist Results

Going through each checklist item:

- **csrfCheck() uses hash_equals()**: PASS -- line 21 of csrf.php uses `hash_equals()` for timing-safe comparison.
- **csrfCheck() validates same-origin referer**: PASS -- lines 34-54 validate `HTTP_ORIGIN` header using `SERVER_NAME`.
- **CSRF token generated with random_bytes()**: PASS -- line 8 uses `bin2hex(random_bytes(32))`.
- **Rate limiter GC failure handled gracefully**: PASS -- `@unlink()` suppresses errors on line 29, glob returns empty array gracefully.
- **Rate limiter fails closed if directory unwritable**: PASS -- line 15 returns `false` if mkdir fails; line 53 returns `false` if fopen fails.
- **Rate limiter key includes identifier AND bucket name**: PASS -- line 44 hashes `[$identifier, $action]`.
- **Rate limiter file locking**: PASS -- `flock(LOCK_EX)` on line 56 with atomic read-check-write.
- **CSP nonce generated fresh per request**: PASS -- line 7 of csp.php uses `random_bytes(16)` at include time.
- **CSP header script-src nonce only (no unsafe-inline/eval)**: PASS for script-src in layout.php line 16 -- uses `'nonce-$nonce'` only.
- **validateLogin() uses config constants**: PASS -- line 3 uses `LOGIN_MIN_LENGTH` and `LOGIN_MAX_LENGTH`.
- **validateEmail() uses FILTER_VALIDATE_EMAIL + length**: PASS -- lines 7-8.
- **validatePassword() enforces min/max**: PASS -- lines 28-31, uses `strlen()` correctly for bcrypt byte limit.
- **transformInt() iterative, no infinite loop**: PASS -- `while ($prev !== $nombre)` loop on line 361 terminates when no substitutions occur.
- **logger.php does NOT log passwords/tokens/raw IPs**: PASS -- IPs are HMAC-hashed (line 39), no password logging found.
- **Log files not web-accessible**: PASS -- `logs/.htaccess` denies all access.
- **env.php no hardcoded credentials**: PASS -- all secrets loaded from `.env` via `getenv()`.
- **logError() signature 3 args**: PASS -- all 2-arg calls use the default `$context = []`; no 4+ arg calls found.
- **Rate limiter data directory**: PASS -- `data/.htaccess` blocks web access, `data/rates` exists.
- **csrfField() correctly formatted**: PASS -- line 14 uses `htmlspecialchars()` on the token value.

---

## Findings

### MEDIUM-001: Inconsistent CSP policies across standalone pages missing critical directives

- **File:** `/home/guortates/TVLW/The-Very-Little-War/deconnexion.php:59`
- **Domain:** INFRA-SECURITY
- **Description:** The CSP header on `deconnexion.php` is:
  ```
  default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self';
  ```
  This is missing `form-action 'self'` and `object-src 'none'` which are present in the main `layout.php` CSP (line 16). The same gaps exist in several admin pages (`admin/supprimercompte.php:17`, `admin/index.php:14`, `admin/listenews.php:8`, `admin/redigernews.php:7`, `admin/multiaccount.php:10`). Without `object-src 'none'`, an attacker who finds an HTML injection on these pages could embed a Flash or Java applet object. Without `form-action 'self'`, a form could be injected that posts to an external domain to exfiltrate data.
- **Impact:** Reduced CSP protection on admin and standalone pages. An HTML injection (even without script execution) could exfiltrate form data via `form-action` or load plugin content via `object-src`. Admin pages are the highest-value target.
- **Suggested fix:** Add the missing directives to all standalone CSP headers. For example in `deconnexion.php`:
  ```php
  // Before:
  header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self';");
  // After:
  header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none';");
  ```
  Apply the same pattern to all admin pages that emit their own CSP.

### MEDIUM-002: Rate limiter GC uses mt_rand which is not cryptographically secure (but acceptable for probabilistic GC)

After review, this is actually a **non-finding**. The `mt_rand(1, 100)` on line 20 of `rate_limiter.php` is used only to decide whether to run garbage collection (~1% probability). This is not a security-sensitive random value -- it is purely a performance optimization heuristic. No issue here.

### LOW-001: transformInt returns a string that may contain non-numeric characters if input has no recognized suffix

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php:367`
- **Domain:** INFRA-SECURITY
- **Description:** `transformInt()` returns `$nombre` as-is after the substitution loop. If the input is something like `"abc"`, the function returns `"abc"` unchanged. The function does not validate that the result is numeric. All callers appear to wrap the result in `intval()` or use it in a numeric context, but the function itself provides no guarantee.
- **Impact:** Low. If any future caller forgets to `intval()` the result, non-numeric data could propagate. Currently all known callers handle this correctly.
- **Suggested fix:** Add a final numeric validation or document the contract clearly:
  ```php
  // At end of transformInt(), after the while loop:
  // Ensure the result is numeric; return '0' for non-numeric input
  if (!is_numeric($nombre)) {
      return '0';
  }
  return $nombre;
  ```

### LOW-002: SECRET_SALT fallback uses deterministic value derived from file path

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/config.php:25`
- **Domain:** INFRA-SECURITY
- **Description:** The fallback for `SECRET_SALT` is:
  ```php
  define('SECRET_SALT', getenv('SECRET_SALT') ?: 'tvlw_fallback_' . substr(md5(__FILE__), 0, 16));
  ```
  If the environment variable is not set, the salt is derived from the absolute filesystem path of `config.php`. On any standard deployment (e.g., `/var/www/html/includes/config.php`), this value is trivially predictable. An attacker knowing the path can precompute the salt and reverse the HMAC-hashed IP addresses in log files.
- **Impact:** Low in practice because the VPS has `SECRET_SALT` set via `.env`. However, any development or staging environment without `.env` would use a trivially guessable salt, weakening IP anonymization in logs.
- **Suggested fix:** The comment on line 23-24 already acknowledges this. To make the failure more visible, log a warning at startup if the fallback is used:
  ```php
  $secretSalt = getenv('SECRET_SALT');
  if ($secretSalt === false || $secretSalt === '') {
      $secretSalt = 'tvlw_fallback_' . substr(md5(__FILE__), 0, 16);
      // Warning will be logged once logger.php is loaded
      $GLOBALS['_secret_salt_fallback'] = true;
  }
  define('SECRET_SALT', $secretSalt);
  ```

---

## Summary

**0 CRITICAL, 0 HIGH, 1 MEDIUM, 2 LOW**

The INFRA-SECURITY infrastructure layer is in strong shape. The core security primitives (CSRF, rate limiting, CSP, validation, logging, environment loading) are all well-implemented with proper defenses:

- CSRF uses `random_bytes()` + `hash_equals()` + Origin validation
- Rate limiter uses atomic `flock(LOCK_EX)` and fails closed on every error path
- CSP nonces are fresh per request via `random_bytes(16)`
- Logger hashes IPs with HMAC and sanitizes all user-controlled values against log injection
- Validation functions reference config constants and use `strlen()` (not `mb_strlen()`) for bcrypt limits
- `.env` loading correctly handles quoted values and does not overwrite existing environment variables

The single MEDIUM finding (MEDIUM-001) is about inconsistent CSP directives across standalone pages that are missing `form-action` and `object-src` directives present in the main layout. The two LOW findings are defensive hardening suggestions with no current exploitability.



---

## INFRA-DATABASE

Confirmed: the `finally` block on line 183 executes even when the exception is thrown at line 146 or 150 (before `$depth++` on line 154). This means:

- On `$depth = 0`: if `mysqli_begin_transaction` throws (line 150), the throw propagates through no try-catch (the inner try starts at line 156, after the throw). The `finally` block IS executed, decrementing to `$depth = -1`.
- On subsequent calls: `$depth = -1 > 0` is false, so `$useSavepoint = false` — this is "safe" in that it does not use savepoints when it shouldn't. However `$depth` is permanently corrupted at `-1`.
- If a *nested* call had the savepoint fail (line 146), same issue: `$depth` goes one below where it should be.

This is a real bug. The `finally` should only decrement if the increment actually happened.

---

Now I have all findings. Here is the complete report:

---

## INFRA-DATABASE Audit Report — Pass 25

---

### HIGH-001: `withTransaction()` — `$depth` decremented in `finally` even when increment never happened, permanently corrupts nesting counter

- **File:** includes/database.php:149–183
- **Domain:** INFRA-DATABASE
- **Description:** The `$depth++` increment (line 154) only executes after the `mysqli_begin_transaction()` / `SAVEPOINT` succeeds. However the `finally` block (line 183) decrements `$depth--` unconditionally. If `mysqli_begin_transaction()` fails (line 149–150), or `SAVEPOINT` fails (line 145–146), the exception is thrown *before* the increment, but `finally` still fires and decrements. On the first call with `$depth = 0`, `$depth` becomes `-1`. On all subsequent calls, `$depth = -1 > 0` is `false` so savepoints are never used — outer real transactions are silently nested as new `BEGIN` statements, triggering an implicit commit of the outer transaction in MariaDB.
- **Impact:** A DB connection failure mid-season-reset (e.g., in `remiseAZero()` which calls `withTransaction` twice) causes all subsequent nested `withTransaction` calls for the duration of the PHP request to issue real `BEGIN` instead of savepoints, silently committing outer transactions prematurely. Data integrity is compromised for the rest of the request lifetime.
- **Suggested fix:** Guard the decrement: move `$depth++` immediately after the opening of the outer try-block (or move the `if ($useSavepoint)` block inside the try), or use a boolean flag:
  ```php
  $incremented = false;
  // ... begin transaction ...
  $depth++;
  $incremented = true;
  try { ... } finally {
      if ($incremented) $depth--;
  }
  ```

---

### HIGH-002: `supprimerJoueur()` — connectes DELETE silently no-ops for virtually all players

- **File:** includes/player.php:1014
- **Domain:** INFRA-DATABASE
- **Description:** `DELETE FROM connectes WHERE ip IN (SELECT ip FROM membre WHERE login=?)` retrieves the player's *registration* IP hash from `membre.ip` and tries to find a match in `connectes.ip`. Since migration 0107 `connectes.ip` stores the *current-session* IP hash (hashed at login time in `basicprivatephp.php:106`), these two values represent different IP addresses for any player who moved, changed ISP, or whose IP changed since registration. The IP hash values will not match.
- **Impact:** Player deletion leaves the player's `connectes` row permanently behind until the next season reset. The online-users counter in `statistiques.php` is inflated for deleted accounts for the remainder of the season.
- **Suggested fix:** `connectes` has no `login` column, so it cannot be authoritatively cleaned per-player. Either: (a) remove this single DELETE line from `supprimerJoueur()` and rely on the existing stale-row cron to age it out, or (b) add a `login VARCHAR(255)` column to `connectes` and delete by `WHERE login = ?`.

---

### HIGH-003: No `CREATE TABLE` migration exists for the `news` table

- **File:** migrations/ (gap)
- **Domain:** INFRA-DATABASE
- **Description:** `player.php` lines 1347 and 1574 issue `INSERT INTO news` and `DELETE FROM news`. The `news` table exists only in the original SQL dump (`base_schema.sql:208`) but has no corresponding `CREATE TABLE IF NOT EXISTS` migration across all 109 numbered migrations. A fresh-deployment scenario (empty schema + run all migrations) lacks the `news` table entirely.
- **Impact:** `performSeasonEnd()` at season reset will throw a `mysqli_sql_exception` on `INSERT INTO news`, aborting the season-end transaction. The game is stuck in a maintenance loop. This is a silent blocker for any new VPS deployment or disaster-recovery restore from migrations only.
- **Suggested fix:** Add `migrations/0110_create_news.sql`:
  ```sql
  CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(255) NOT NULL DEFAULT '',
    contenu TEXT NOT NULL,
    date INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
  ```

---

### HIGH-004: Migration 0109 `ADD COLUMN` missing `IF NOT EXISTS` — blocks re-run and future migrations

- **File:** migrations/0109_statistiques_maintenance_started_at.sql:6
- **Domain:** INFRA-DATABASE
- **Description:** `ALTER TABLE statistiques ADD COLUMN maintenance_started_at INT NOT NULL DEFAULT 0;` lacks `IF NOT EXISTS`. DDL in MariaDB is not transactional — the column is added even if `migrate.php` later fails to record the migration. On retry, `migrate.php` will attempt to apply 0109 again and get `ERROR 1060: Duplicate column name`, then `exit(1)`, blocking migrations 0110+.
- **Impact:** Any deploy that crashes between the DDL commit and the `INSERT INTO migrations` record causes permanent pipeline breakage requiring manual intervention.
- **Suggested fix:** `ALTER TABLE statistiques ADD COLUMN IF NOT EXISTS maintenance_started_at INT NOT NULL DEFAULT 0;`

---

### MEDIUM-001: Duplicate UNIQUE indexes on `season_recap(season_number, login)` — doubled write cost

- **File:** migrations/0057_season_recap_constraints.sql:22 and migrations/0096_season_recap_unique.sql:3
- **Domain:** INFRA-DATABASE
- **Description:** Migration 0057 creates `UNIQUE KEY uq_season_login (login, season_number)`; migration 0096 creates `UNIQUE KEY uk_season_player (season_number, login)`. Both enforce uniqueness on the same two columns. MariaDB maintains them as two separate B-tree indexes. The `ADD UNIQUE KEY IF NOT EXISTS` in 0096 only guards against the exact same constraint name, not a semantically equivalent reversed-column index — so both are created.
- **Impact:** Every `INSERT INTO season_recap` during `archiveSeasonData()` (one row per active player at season end) maintains two indexes instead of one, roughly doubling write I/O for this operation. On a game with 500+ players per season the difference is measurable.
- **Suggested fix:** Add `migrations/0111_drop_redundant_season_recap_unique.sql` that drops `uq_season_login`: `ALTER TABLE season_recap DROP INDEX uq_season_login;`

---

### MEDIUM-002: `declarations.winner` column created with conflicting nullability across migrations 0074 and 0092

- **File:** migrations/0074_declarations_winner.sql:11 and migrations/0092_declarations_add_winner_column.sql:4
- **Domain:** INFRA-DATABASE
- **Description:** Migration 0074 creates `winner TINYINT DEFAULT NULL` (nullable). Migration 0092 attempts `ADD COLUMN IF NOT EXISTS winner TINYINT NOT NULL DEFAULT 0`. On a fresh database, 0074 runs first, creates the nullable column, then 0092's `IF NOT EXISTS` silently skips — the column is permanently `NULLABLE` contrary to 0092's intent. PHP code comparing `$row['winner'] == 0` for "draw" detection will fail to match `NULL` rows (ongoing wars), misclassifying them.
- **Impact:** On fresh VPS deployments the `winner` column is nullable, causing incorrect war-status classification. Existing production deployments where 0074 was applied first have this problem today.
- **Suggested fix:** Add a migration that unconditionally modifies the column: `ALTER TABLE declarations MODIFY COLUMN winner TINYINT NOT NULL DEFAULT 0;`

---

### MEDIUM-003: `email_queue.created_at` UNSIGNED silently dropped by migration 0093

- **File:** migrations/0038_create_email_queue.sql:16 and migrations/0093_email_queue_created_at_default.sql:2
- **Domain:** INFRA-DATABASE
- **Description:** Migration 0038 creates `created_at INT UNSIGNED NOT NULL`. Migration 0093 does `MODIFY COLUMN created_at INT NOT NULL DEFAULT 0` without the `UNSIGNED` keyword, converting the column to signed INT. MariaDB executes MODIFY silently without warning. Unix timestamps stored after 2038 will overflow a signed INT (max 2,147,483,647 = 19 Jan 2038).
- **Impact:** Y2K38 regression introduced for a 15-year-old game that may still be running in 12 years. Email-queue processing logic using `WHERE created_at <= UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR)` will produce incorrect results after 2038.
- **Suggested fix:** Change migration 0093 to `ALTER TABLE email_queue MODIFY COLUMN created_at INT UNSIGNED NOT NULL DEFAULT 0;`

---

### LOW-001: `ajouter()` whitelist in `db_helpers.php` missing `nbDons` column added by migration 0100

- **File:** includes/db_helpers.php:23–35 and migrations/0100_autre_add_nb_dons.sql
- **Domain:** INFRA-DATABASE
- **Description:** Migration 0100 adds `nbDons INT NOT NULL DEFAULT 0` to `autre` for donation medal tracking. The `ajouter()` function whitelist (lines 23–35) does not include `nbDons`. Any call `ajouter('nbDons', 'autre', 1, $login)` will be silently rejected with an `error_log` message and the donation count never incremented.
- **Impact:** Donation medals remain permanently broken — `nbDons` is never incremented through `ajouter()`, so the donation counter stays at 0 for all players. The migration comment itself says "donation medals were always broken" — this fix is incomplete without the whitelist update.
- **Suggested fix:** Add `'nbDons'` to the `$allowedColumns` array in `db_helpers.php`:
  ```php
  'nbDons',               // donation count for Donateur medal
  ```

---

### LOW-002: `migrate.php` transaction wraps DDL that implicitly commits — rollback on error is ineffective

- **File:** migrations/migrate.php:61–116
- **Domain:** INFRA-DATABASE
- **Description:** `migrate.php` wraps each migration in `mysqli_begin_transaction()` / `mysqli_commit()` (lines 61, 116) with `mysqli_rollback()` on error (line 99). However, MariaDB issues an implicit COMMIT before every DDL statement (`ALTER TABLE`, `CREATE TABLE`, `DROP TABLE`). For migrations that are purely DDL (the majority), the transaction wrapper provides zero rollback protection — the DDL is committed immediately, yet the `migrations` table INSERT that follows is in the same "transaction" that can no longer be rolled back atomically.
  The comment at line 57–60 acknowledges this but calls it "best-effort". The actual behavior is: DDL succeeds → migration committed to schema → if `INSERT INTO migrations` fails → `rollback` does nothing → the migration is re-run on next deploy → `IF NOT EXISTS` / `IF NOT EXISTS` patterns save it, but migrations without those guards (e.g. 0109) will error.
- **Impact:** Low — only affects the edge case where the `migrations` INSERT fails after DDL commits. In practice the `IF NOT EXISTS` idiom used in most migrations prevents double-application errors.
- **Suggested fix:** Document this limitation more prominently in the file header. For DDL-only migrations, consider adding a pre-flight check: if the target column/table already exists, skip the migration with an informational message rather than relying solely on `IF NOT EXISTS` in the SQL.

---

## Summary

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | 0 | — |
| HIGH | 4 | HIGH-001 through HIGH-004 |
| MEDIUM | 3 | MEDIUM-001 through MEDIUM-003 |
| LOW | 2 | LOW-001 through LOW-002 |

**Total: 9 findings**

### Priority remediation order:

1. **HIGH-001** (`withTransaction` depth underflow) — fix the `finally`/`$depth` guard before any concurrent season-reset scenario occurs.
2. **HIGH-003** (missing `news` table migration) — add `0110_create_news.sql` before next clean-deploy or disaster recovery attempt.
3. **HIGH-004** (migration 0109 not idempotent) — one-line fix, zero risk.
4. **HIGH-002** (connectes DELETE no-op) — remove the dead DELETE line from `supprimerJoueur()`.
5. **LOW-001** (`nbDons` whitelist gap) — add to `db_helpers.php` or donation medals remain broken post-pass-22.
6. **MEDIUM-002** (winner nullability conflict) — add a type-promotion migration.
7. **MEDIUM-001** (duplicate season_recap index) — add a DROP INDEX migration.
8. **MEDIUM-003** (email_queue UNSIGNED drop) — fix migration 0093 or add corrective 0112.
9. **LOW-002** (migrate.php DDL/transaction mismatch) — documentation + comment improvement only.



---

## ANTI_CHEAT

I have now reviewed all files thoroughly. Let me compile the audit findings.

---

# ANTI_CHEAT Domain Audit -- Pass 25

## Findings

### HIGH-001: createAdminAlert() asymmetric pair dedup allows duplicate alerts when caller swaps login order

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php:341-343
- **Domain:** ANTI_CHEAT
- **Description:** The `createAdminAlert()` dedup query checks `login1 = ? AND login2 = ?` but does NOT check the reverse ordering `(login1 = ? AND login2 = ?) OR (login1 = ? AND login2 = ?)`. Meanwhile, the callers pass `$login, $other['login']` in checkSameIpAccounts (line 103-105) and the same in checkSameFingerprintAccounts (line 141-143). These detection functions are called once per login event with `$login` as the current user. When user A logs in, the alert is created with `login1=A, login2=B`. When user B logs in later, the alert dedup checks `login1=B, login2=A` which does NOT match the existing `login1=A, login2=B` row. This produces a duplicate alert.

  The account_flags dedup (lines 84-87, 126-128, 170-172, 234-236) all use symmetric `((login=? AND related_login=?) OR (login=? AND related_login=?))` checks. But `createAdminAlert()` at line 342 does not:
  ```php
  'SELECT COUNT(*) FROM admin_alerts WHERE alert_type = ? AND login1 = ? AND login2 = ? AND created_at > UNIX_TIMESTAMP() - ' . MULTIACCOUNT_CHECK_WINDOW,
  ```

- **Impact:** Every same_ip or same_fingerprint pair generates two admin alerts (one when A logs in, one when B logs in) instead of one. This doubles alert noise and triggers duplicate critical alert emails for timing_correlation and coord_attack events. Not a security hole per se, but degrades the admin's ability to triage effectively and can double email volume.

- **Suggested fix:**
  ```php
  // Before:
  'SELECT COUNT(*) FROM admin_alerts WHERE alert_type = ? AND login1 = ? AND login2 = ? AND created_at > UNIX_TIMESTAMP() - ' . MULTIACCOUNT_CHECK_WINDOW,
  'sss', $alertType, $login1, $login2

  // After:
  'SELECT COUNT(*) FROM admin_alerts WHERE alert_type = ? AND ((login1 = ? AND login2 = ?) OR (login1 = ? AND login2 = ?)) AND created_at > UNIX_TIMESTAMP() - ' . MULTIACCOUNT_CHECK_WINDOW,
  'sssss', $alertType, $login1, $login2, $login2, $login1
  ```

---

### HIGH-002: hashIpAddress() fallback salt diverges from config.php SECRET_SALT definition

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php:25
- **Domain:** ANTI_CHEAT
- **Description:** `hashIpAddress()` uses the fallback `'tvlw_salt'` when `SECRET_SALT` is not defined:
  ```php
  $salt = defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt';
  ```
  But `config.php` line 25 always defines `SECRET_SALT`:
  ```php
  define('SECRET_SALT', getenv('SECRET_SALT') ?: 'tvlw_fallback_' . substr(md5(__FILE__), 0, 16));
  ```
  If `multiaccount.php` is ever loaded before `config.php` (which it currently is not, but the guard exists), the fallback `'tvlw_salt'` would produce different hashes than the config.php fallback `'tvlw_fallback_...'`. More importantly, `basicpublicphp.php` line 27 uses the same `'tvlw_salt'` fallback in an inline hash, but `config.php` uses a different fallback. The salt inconsistency is latent since `config.php` is always loaded via `constantesBase.php` before these functions run. However, it represents fragile defensive coding.

- **Impact:** If load order changes or if `hashIpAddress()` is called from a context where `config.php` has not been loaded, IP hashes will silently differ, breaking all IP-based detection (same-IP checks will never match). The detection system would fail silently.

- **Suggested fix:** Remove the fallback entirely and use `SECRET_SALT` directly, since it is always defined by the time this function runs:
  ```php
  $salt = SECRET_SALT;
  ```
  Or if defensive coding is desired, match the config.php fallback exactly.

---

### MEDIUM-001: areFlaggedAccounts() fails open on DB error -- flagged multi-account pairs can trade freely

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php:321-328
- **Domain:** ANTI_CHEAT
- **Description:** `areFlaggedAccounts()` calls `dbFetchOne()` which returns `null` on DB error. The function then evaluates `!empty(null)` which is `false`, meaning it returns `false` (not flagged). This is a fail-open design:
  ```php
  function areFlaggedAccounts($base, $loginA, $loginB)
  {
      $flag = dbFetchOne($base, ...);
      return !empty($flag);
  }
  ```
  If the database connection is degraded, the account_flags table is locked, or any query error occurs, all flagged multi-account pairs are silently allowed to transfer resources.

- **Impact:** During any database hiccup, the market transfer block (marche.php line 70) and donation block (don.php) are silently bypassed. A sophisticated attacker could potentially trigger query failures (e.g., via concurrent lock contention during heavy load) to circumvent multi-account protections.

- **Suggested fix:** Fail closed by treating null/false as flagged:
  ```php
  function areFlaggedAccounts($base, $loginA, $loginB)
  {
      $flag = dbFetchOne($base, ...);
      if ($flag === null || $flag === false) {
          logWarn('MULTIACCOUNT', 'areFlaggedAccounts query failed, failing closed', ['a' => $loginA, 'b' => $loginB]);
          return true; // fail closed: block transfer when uncertain
      }
      return !empty($flag);
  }
  ```

---

### MEDIUM-002: checkTimingCorrelation() asymmetric dedup -- only checks flags where current user is `login`, not `related_login`

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php:270-272, 291-293
- **Domain:** ANTI_CHEAT
- **Description:** `checkTimingCorrelation()` fetches related accounts only from flags where the current user is in the `login` column:
  ```php
  $related = dbFetchAll($base,
      'SELECT DISTINCT related_login FROM account_flags WHERE login = ? AND status != ?',
      'ss', $login, 'dismissed'
  );
  ```
  It does NOT check `WHERE related_login = ?` to find flags where the current user was the "other" party. Similarly, the timing_correlation dedup at lines 291-293 only checks `login = ? AND related_login = ?`, not the reverse. This means:
  1. If A triggered a same_ip flag on B (login=A, related_login=B), then when A logs in, timing correlation checks A vs B. But when B logs in, timing correlation does NOT check B vs A because B is in the `related_login` column, not `login`.
  2. The dedup for timing_correlation flags is also asymmetric (unlike the symmetric dedup in other detection functions).

- **Impact:** Timing correlation detection works for only one direction of previously-flagged pairs. Approximately half of timing correlations are missed. Additionally, if both users happen to trigger the check, duplicate timing_correlation flags can be created (one for each direction).

- **Suggested fix:** Fetch related accounts from both columns:
  ```php
  $related = dbFetchAll($base,
      'SELECT DISTINCT CASE WHEN login = ? THEN related_login ELSE login END AS other_login
       FROM account_flags WHERE (login = ? OR related_login = ?) AND status != ?',
      'ssss', $login, $login, $login, 'dismissed'
  );
  ```
  And apply symmetric dedup to the timing_correlation flag insertion (same pattern as other functions).

---

### MEDIUM-003: admin/ip.php still accepts raw IP via GET, leaking it in browser history, referer headers, and server logs

- **File:** /home/guortates/TVLW/The-Very-Little-War/admin/ip.php:31-36
- **Domain:** ANTI_CHEAT
- **Description:** While POST support was added (lines 22-29), the GET fallback remains active:
  ```php
  } else {
      $rawIp = isset($_GET['ip']) ? trim($_GET['ip']) : '';
      $ip = filter_var($rawIp, FILTER_VALIDATE_IP) ? $rawIp : '';
  ```
  The displayed output on line 38 shows the raw IP in HTML:
  ```php
  echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'<p>';
  ```
  This means any admin who uses the GET method (e.g., via a bookmark or link) has the queried IP stored in their browser history, server access logs, and any referer headers if they click away from the page. For a system that hashes IPs for GDPR compliance everywhere else, displaying and logging raw IPs via GET undermines the privacy posture.

- **Impact:** Raw IP addresses leak into server access logs, browser history, and HTTP referer headers. This contradicts the GDPR-compliant IP hashing implemented everywhere else in the anti-cheat system.

- **Suggested fix:** Remove GET support entirely and require POST-only access. Redirect GET requests to a form that takes IP input via POST.

---

### LOW-001: login_history GC uses mt_rand() which is not cryptographically secure (minor)

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php:66
- **Domain:** ANTI_CHEAT
- **Description:** The probabilistic garbage collection uses `mt_rand(1, 200)`:
  ```php
  if (mt_rand(1, 200) === 1) {
  ```
  While `mt_rand()` is fine for non-security purposes like GC probability, it uses the Mersenne Twister PRNG which is predictable. An attacker who can observe enough outputs could predict when GC runs and time attacks around it. This is extremely low risk since the GC is purely a maintenance operation with no security impact.

- **Impact:** Negligible. An attacker could theoretically predict GC timing, but this provides no meaningful advantage.

- **Suggested fix:** No action required. This is noted for completeness only. If desired, replace with `random_int(1, 200)` for consistency with the codebase's use of `random_bytes()` elsewhere.

---

### LOW-002: admin/multiaccount.php log message for manual flag includes unsanitized player names

- **File:** /home/guortates/TVLW/The-Very-Little-War/admin/multiaccount.php:74
- **Domain:** ANTI_CHEAT
- **Description:** The log message interpolates player names directly:
  ```php
  logInfo('ADMIN', "Manual flag added: $manualLogin <-> $manualRelated");
  ```
  While the login names are validated to exist in the `membre` table (lines 57-58), the raw POST values are used in the log string. If a login contains special characters that interfere with log parsing (though the preg_match on `detailLogin` suggests names are alphanumeric), this could cause log injection in structured log analysis tools.

- **Impact:** Low. Player names in `membre` are already constrained by registration validation. Log injection risk is minimal but the practice is inconsistent with the rest of the codebase which uses structured log context arrays.

- **Suggested fix:** Use structured logging:
  ```php
  logInfo('ADMIN', 'Manual flag added', ['login' => $manualLogin, 'related' => $manualRelated]);
  ```

---

## Summary

**1 CRITICAL, 0 HIGH... correcting:**

- **0 CRITICAL**
- **2 HIGH** (H-001: createAdminAlert asymmetric dedup, H-002: hashIpAddress salt divergence)
- **3 MEDIUM** (M-001: areFlaggedAccounts fail-open, M-002: checkTimingCorrelation asymmetric related lookup, M-003: admin/ip.php GET leaks raw IP)
- **2 LOW** (L-001: mt_rand for GC, L-002: unsanitized log interpolation)

**Total: 0 CRITICAL, 2 HIGH, 3 MEDIUM, 2 LOW**

### Checklist verification (items with no findings):

- **logLoginEvent()**: Correctly inserts login_history row on every successful login. Called from basicpublicphp.php line 94 after successful authentication. PASS.
- **IP hashing**: Raw IP NOT stored, only HMAC-SHA256 hash via `hashIpAddress()`. PASS.
- **Fingerprint**: UA + accept-language hashed with SHA-256. Only browser family string stored (not full UA). PASS.
- **Same-IP detection**: 30-day window, correct symmetric flag dedup. PASS.
- **Coordinated attacks**: Properly called from both `attaquer.php:123` and `game_actions.php:467`. Symmetric dedup. PASS.
- **Transfer patterns**: Bidirectional check confirmed (calls `_checkOneSidedTransfers` for both directions). Called from `game_actions.php:825` and `marche.php:221`. PASS.
- **areFlaggedAccounts()**: Called by `marche.php:70` and `don.php:5-6`. PASS (but fails open -- see M-001).
- **createAdminAlert()**: Per-pair dedup works but is asymmetric. See H-001.
- **admin/multiaccount.php**: Requires admin auth via `redirectionmotdepasse.php`. CSRF on all POST actions. PASS.
- **Flag resolution**: Flags can be marked investigating/confirmed/dismissed. PASS.
- **Detection non-blocking**: Runs synchronously on login (noted in comments). Acceptable at current player count. PASS.
- **admin/ip.php**: Requires admin auth. POST+CSRF on POST actions. GET still accepted (see M-003).
- **account_flags table**: Migration 0021 exists with correct schema. PASS.
- **login_history table**: Migration 0020 exists, IP column expanded to VARCHAR(64) in migration 0080. PASS.



---

## ADMIN

Now I have a thorough picture. Let me compile the findings.

---

## ADMIN Domain Audit -- Pass 25 Findings

### HIGH-001: supprimercompte.php references `$_SESSION['login']` which is undefined in admin session

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/supprimercompte.php:36`
- **Domain:** ADMIN
- **Description:** The audit log line reads `logInfo('ADMIN', 'Player account deleted', ['target_login' => $loginToDelete, 'admin' => $_SESSION['login']]);`. The admin session uses the `TVLW_ADMIN` session name (set in `redirectionmotdepasse.php`), which only stores `motdepasseadmin`, `admin_ip`, `admin_last_activity`, and `session_created`. There is no `$_SESSION['login']` key in the admin session -- that key exists only in the player session (`TVLW_SESSION`). The result is that the `'admin'` field in every audit log entry for account deletion is `null`, making it impossible to trace who performed the deletion in a multi-admin scenario or during forensic review.
- **Impact:** Audit trail for account deletions lacks the identity of the administrator who performed the action. In the current single-admin setup this is tolerable, but the log entry is misleading (always null). If a second admin were added, deletions would be unattributable.
- **Suggested fix:** Since the admin panel uses a shared password (no per-user login), log the admin IP instead:
  - Before: `'admin' => $_SESSION['login']`
  - After: `'admin_ip' => $_SESSION['admin_ip'] ?? 'unknown'`

---

### HIGH-002: admin/ip.php still accepts GET requests without CSRF, exposing IP lookup as a read-only side channel

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/ip.php:30-37`
- **Domain:** ADMIN
- **Description:** The file explicitly supports GET as a fallback: `} else { $rawIp = isset($_GET['ip']) ? trim($_GET['ip']) : ''; ... }`. While the comment says "backwards compatibility (read-only display)", accepting GET means: (a) the raw IP address appears in the URL, server access logs, referer headers, and browser history; (b) a CSRF attack can force an authenticated admin's browser to load `ip.php?ip=TARGET` and the page will render -- if combined with a CSS injection or timing side-channel the attacker could learn whether accounts exist for that IP.
- **Impact:** IP address leakage via URL/referer/logs. The moderation version (`moderation/ip.php`) correctly rejects GET, but the admin version does not.
- **Suggested fix:** Remove the GET fallback entirely, matching the moderation version:
  - Before: lines 30-37 (else branch accepting GET)
  - After: Remove the else branch; if `REQUEST_METHOD !== 'POST'`, show only the search form (no query).

---

### HIGH-003: maintenance.php has no CSRF protection on its actions

- **File:** `/home/guortates/TVLW/The-Very-Little-War/maintenance.php` (entire file)
- **Domain:** ADMIN
- **Description:** The file does not include `csrf.php`, does not call `csrfCheck()`, and does not emit `csrfField()` in any form. While it is currently a read-only display page (it just shows the latest news), it performs an admin-only auth check. If any POST-based functionality is ever added, it would be unprotected. More importantly, the page does not require `csrf.php` at all, so if someone adds a maintenance toggle form here (the page is named "maintenance" after all), they might forget CSRF.
- **Impact:** Low immediate impact since the page is currently read-only, but the naming strongly suggests maintenance toggling should live here, and the missing CSRF infrastructure makes it a trap for future changes.
- **Suggested fix:** Add `require_once('includes/csrf.php');` to the includes block. This is defensive and costs nothing.

---

### MEDIUM-001: moderation/mdp.php lacks idle timeout and absolute timeout enforcement

- **File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/mdp.php` (entire file, 15 lines)
- **Domain:** ADMIN
- **Description:** The `mdp.php` auth guard checks `motdepasseadmin`, IP binding, but does NOT check idle timeout or absolute timeout. Compare with `redirectionmotdepasse.php` (admin side) which checks both `SESSION_IDLE_TIMEOUT` and `SESSION_ABSOLUTE_TIMEOUT`. The moderation `index.php` does its own idle+absolute checks in its body, but any moderation sub-page that includes only `mdp.php` (like `ip.php`) will bypass those timeout checks entirely. An expired moderation session that should have been invalidated by idle/absolute timeout will still work on `ip.php` because `mdp.php` never checks timestamps.
- **Impact:** A moderator session that has exceeded idle or absolute timeout can still access `ip.php` (and any future pages that include `mdp.php` as their auth guard), circumventing session lifetime controls.
- **Suggested fix:** Add idle and absolute timeout checks to `mdp.php`, matching the pattern in `redirectionmotdepasse.php`:
  ```php
  if (isset($_SESSION['mod_last_activity']) && (time() - $_SESSION['mod_last_activity']) > SESSION_IDLE_TIMEOUT) {
      unset($_SESSION['motdepasseadmin']);
      header('Location: index.php');
      exit();
  }
  if (isset($_SESSION['session_created']) && (time() - $_SESSION['session_created']) > SESSION_ABSOLUTE_TIMEOUT) {
      session_unset(); session_destroy();
      header('Location: index.php');
      exit();
  }
  $_SESSION['mod_last_activity'] = time();
  ```

---

### MEDIUM-002: listenews.php includes connexion.php AFTER headers are sent (inside `<body>`)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/listenews.php:41`
- **Domain:** ADMIN
- **Description:** The file outputs HTML (the `<!DOCTYPE html>` preamble and `<body>` opening tag) before `include("../includes/connexion.php")` on line 41. This means the CSP header on line 8 works (it is before output), but if `connexion.php` or the CSRF check triggers an error or a header redirect, it will fail because output has already started. The POST handling block (lines 44-72) runs after the HTML header has been sent, so any `header('Location: ...')` call from CSRF failure would produce a "headers already sent" warning.
- **Impact:** If `csrfCheck()` calls `header()` and `exit()` on failure (which is the typical pattern), the redirect header will silently fail because output started at line 9. The exit still fires so the action is blocked, but the user sees a broken partial HTML page instead of a redirect.
- **Suggested fix:** Move the `include("../includes/connexion.php")` and the entire POST-handling block (lines 41-72) to before the `<!DOCTYPE html>` output on line 10, matching the pattern used in `admin/index.php`.

---

### MEDIUM-003: listenews.php and redigernews.php do not include database.php explicitly

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/listenews.php:41,59` and `/home/guortates/TVLW/The-Very-Little-War/admin/redigernews.php:25,30`
- **Domain:** ADMIN
- **Description:** Both files call `dbExecute()` and `dbFetchAll()`/`dbFetchOne()` but neither includes `database.php` explicitly. They rely on `connexion.php` or `redirectionmotdepasse.php` to have loaded it transitively. If the include chain changes, these files will break with a fatal "Call to undefined function dbExecute()" error. `supprimercompte.php` correctly includes `require_once(__DIR__ . '/../includes/database.php');`.
- **Impact:** Fragile dependency chain. Currently works but one refactor to the include order could break both news management pages silently.
- **Suggested fix:** Add `require_once(__DIR__ . '/../includes/database.php');` near the top of both files.

---

### MEDIUM-004: admin/index.php multi-account IP list form posts to `ip.php` (relative), targeting admin/ip.php which still supports GET fallback

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php:183`
- **Domain:** ADMIN
- **Description:** Line 183 posts hashed IPs to `action="ip.php"` but sends the value from `$donnees['ip']`, which is an HMAC-SHA256 hash (64 hex characters). However, `admin/ip.php:24` validates the POST value with `FILTER_VALIDATE_IP`, which will always reject a 64-character hex hash. This means clicking any IP button in the multi-account list on the admin dashboard will always show "Adresse IP invalide." The same bug exists for the GET path (line 32 applies `FILTER_VALIDATE_IP`). The form is functionally broken.
- **Impact:** The "view accounts by IP" buttons on the admin dashboard are non-functional. Clicking them always shows an error because hashed IPs cannot pass IP format validation. The admin cannot investigate multi-account clusters from the dashboard.
- **Suggested fix:** `admin/ip.php` should accept the hashed IP directly (like `admin/index.php` does for deletion on line 68 with the `preg_match('/^[0-9a-f]{64}$/')` pattern) and query `membre WHERE ip = ?` without re-hashing.

---

### LOW-001: tableau.php contains dead JavaScript referencing removed signalement/lieux functionality

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/tableau.php:611-649`
- **Domain:** ADMIN
- **Description:** The `signalement` and `lieux` table queries were correctly removed (per P10 fixes), but the surrounding JavaScript that processes `signalementTotal` (lines 611-649) remains. The `tableauSignalements` array is now always empty (line 619: `var tableauSignalements = [];`), so the loop on line 630 never matches anything. This is dead code that increases page size and confuses future maintainers.
- **Impact:** No functional impact. Dead code / maintainability issue.
- **Suggested fix:** Remove the dead JavaScript block that processes `signalementTotal` and `tableauSignalements`, since the data source was removed.

---

### LOW-002: redigernews.php does not include connexion.php before database calls on line 25

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/redigernews.php:25-30`
- **Domain:** ADMIN
- **Description:** `connexion.php` is included on line 25 (inside the `<body>` tag), but `redirectionmotdepasse.php` (line 2) already includes `constantesBase.php` which does not load `connexion.php`. The `dbFetchOne()` call on line 30 depends on `$base` being available. Currently this works because `connexion.php` is included on line 25 before the query on line 30, but the HTML output started on line 9. Same pattern as listenews.php -- the connection and DB work happen after headers/output have started.
- **Impact:** Same as MEDIUM-002: any header-based response (CSRF redirect) would fail after output started. Currently no POST handling in this file (it only displays the form and submits to listenews.php), so actual risk is minimal.
- **Suggested fix:** Move `include("../includes/connexion.php")` to before the HTML output block.

---

## Checklist Verification

| Check | Status |
|---|---|
| supprimercompte.php: auth gate is admin session check (not redirectionmotdepasse only) | PASS -- lines 3 + 8-11 (redundant explicit check) |
| supprimercompte.php: csrfCheck() called FIRST inside POST block | PASS -- line 22 |
| supprimercompte.php: logInfo('ADMIN', ...) called after deletion | PASS (line 36) but logs `$_SESSION['login']` which is null (HIGH-001) |
| supprimercompte.php: supprimerJoueur() wrapped in withTransaction() | PASS -- line 30 |
| tableau.php: no queries referencing non-existent tables | PASS -- signalement/lieux queries removed (JS remnants remain, LOW-001) |
| admin/index.php: IP-batch deletion wrapped in withTransaction() | PASS -- line 83 |
| admin/index.php: manual season reset calls performSeasonEnd() correctly | PASS -- line 116 |
| listenews.php + redigernews.php: require admin auth, CSRF on POST | PASS -- auth via redirectionmotdepasse.php; CSRF on POST (line 44-46 in listenews) |
| moderation/* files: require appropriate auth level | PASS -- index.php has inline auth, ip.php uses mdp.php |
| maintenance.php: CSRF on toggle POST, admin auth required | PARTIAL FAIL -- auth is present, but no CSRF infrastructure (HIGH-003) |
| scripts/cleanup_old_data.php: CLI-only guard | PASS -- line 23 `PHP_SAPI !== 'cli'` |
| cleanup_old_data.php: DELETE FROM sanctions WHERE dateFin < CURDATE() | PASS -- line 132 |
| admin/index.php: admin idle + absolute timeout enforced | PASS -- lines 32-44 |
| admin/index.php: session_created stamped on first admin login | PASS -- lines 48-50 |
| moderation/ip.php: only accessible via POST+CSRF | PASS -- moderation/ip.php enforces POST+CSRF (lines 31-32) |
| moderation/ip.php: requires authenticated moderator/admin session | PASS -- line 3 requires mdp.php |
| admin/index.php: batch deletion cap (>5 accounts refused) | PASS -- line 75 |
| Does any admin/moderation page expose internal server info? | PASS -- no phpinfo/phpversion/stack trace exposure found |

---

## Summary

**0 CRITICAL, 3 HIGH, 4 MEDIUM, 2 LOW**

The most impactful finding is **MEDIUM-004** (admin/ip.php form is functionally broken because it validates hashed IPs against FILTER_VALIDATE_IP) which renders the multi-account investigation feature on the admin dashboard non-functional. **HIGH-001** (null admin identity in audit logs) weakens forensic accountability. **MEDIUM-001** (missing timeout in moderation auth guard) creates a gap in session lifetime enforcement for moderation sub-pages.



---

## SEASON_RESET

This is a new finding. However, let me check whether this function is actually called during season reset or if it's just a general email drain.

The `processEmailQueue()` is called probabilistically from basicprivatephp.php (line 354) during normal page loads, and also from remiseAZero (indirect). But the sentinel `-1` into UNSIGNED INT is the core issue. The function is part of the season reset domain because it processes season-end notification emails.

Let me also verify the `tempsPrecedent` reset timing. In `remiseAZero()` line 1555, `tempsPrecedent` is set to `time()`. But `remiseAZero()` runs BEFORE the maintenance flag is cleared (line 1342 in performSeasonEnd). Players can't log in during maintenance, so when they finally log in after maintenance clears, their `tempsPrecedent` is already set to the reset time. The gap between reset and maintenance clear is minimal (lines 1313-1342). This seems fine.

Now I have all findings. Let me compile the report.

---

## SEASON_RESET Domain Audit -- Pass 25 Findings

### HIGH-001: `nbDons` column not reset in `remiseAZero()`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:1514`
- **Domain:** SEASON_RESET
- **Description:** Migration `0100_autre_add_nb_dons.sql` added column `nbDons` (donation count for medal tracking) to the `autre` table, but `remiseAZero()` does not reset it. The UPDATE statement on line 1514 sets many columns back to their defaults (`nbattaques=0`, `energieDonnee=0`, `moleculesPerdues=0`, etc.) but omits `nbDons`. This means donation counts carry over across seasons, inflating medal metrics for players who played in previous seasons.
- **Impact:** Players accumulate donation counts across multiple seasons. If a "Donateur" medal threshold is, say, 100 donations, a player who had 80 last season starts the new season at 80 instead of 0, gaining an unfair advantage over new players. This violates the season-reset contract that all per-season stats are zeroed.
- **Suggested fix:**
  ```
  // Before (line 1514, inside the UPDATE autre SET ...):
  ...alliance_left_at = NULL WHERE 1', '');
  
  // After — add nbDons=0 to the existing UPDATE autre SET:
  ...nbDons=0, alliance_left_at = NULL WHERE 1', '');
  ```
  Alternatively, add `nbDons=0` to the comma-separated column list in the large UPDATE on line 1514.

---

### HIGH-002: `processEmailQueue()` writes `-1` sentinel to `INT UNSIGNED` column under `STRICT_TRANS_TABLES`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:1404`
- **Domain:** SEASON_RESET
- **Description:** The claim pattern writes `sent_at = -1` as a sentinel value to mark rows as "claimed but not yet sent." However, the `sent_at` column is defined as `INT UNSIGNED` in migration `0038_create_email_queue.sql` (line 17). The production MariaDB server runs with `STRICT_TRANS_TABLES` SQL mode. In strict mode, inserting a negative value into an unsigned integer column raises an out-of-range error, causing the transaction to fail.
  ```php
  // Line 1404:
  dbExecute($base, 'UPDATE email_queue SET sent_at = -1 WHERE id IN (' . $placeholders . ')', $types, ...$claimedIds);
  ```
  And also at line 1477:
  ```php
  dbExecute($base, 'UPDATE email_queue SET sent_at = NULL WHERE id = ? AND sent_at = -1', 'i', $id);
  ```
- **Impact:** The entire email queue drain fails silently (the transaction rolls back). Season-end notification emails are never sent. Repeated failures on every probabilistic invocation mean the queue never drains. This does not block gameplay, but players never receive season-end notifications.
- **Suggested fix:** Use a valid unsigned sentinel value instead of `-1`. A reasonable choice is `1` (Unix timestamp for 1970-01-01 00:00:01 -- clearly distinguishable from real timestamps):
  ```php
  // Before:
  dbExecute($base, 'UPDATE email_queue SET sent_at = -1 WHERE id IN (...)', ...);
  // After:
  dbExecute($base, 'UPDATE email_queue SET sent_at = 1 WHERE id IN (...)', ...);
  
  // Before (release claim on failure):
  dbExecute($base, 'UPDATE email_queue SET sent_at = NULL WHERE id = ? AND sent_at = -1', 'i', $id);
  // After:
  dbExecute($base, 'UPDATE email_queue SET sent_at = NULL WHERE id = ? AND sent_at = 1', 'i', $id);
  ```

---

### MEDIUM-001: `performSeasonEnd()` has no maintenance-mode guard

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:1135`
- **Domain:** SEASON_RESET
- **Description:** `performSeasonEnd()` does not verify that `statistiques.maintenance = 1` before proceeding with the reset. While the admin interface (`admin/index.php` line 110) does not enforce this either -- the admin can POST `miseazero` at any time. The two-phase design (Phase 1: set maintenance, wait 24h; Phase 2: reset) exists to give players time to prepare, but the admin can bypass it entirely by pressing the reset button without first enabling maintenance. This means players could be mid-game when the reset fires: their resources, buildings, and troops vanish without the 24h warning period.
- **Impact:** An accidental admin click on the reset button without first enabling maintenance wipes the entire game state while players are actively playing, with no warning. Players lose in-progress attacks, constructions, and market orders. The advisory lock prevents concurrent resets but not premature ones.
- **Suggested fix:** Add a maintenance guard at the top of `performSeasonEnd()`:
  ```php
  function performSeasonEnd()
  {
      global $base;
      
      // Guard: only allow reset when maintenance mode is active
      $maint = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
      if (!$maint || (int)$maint['maintenance'] !== 1) {
          throw new \RuntimeException('Cannot reset season: maintenance mode is not active');
      }
      
      // ... existing advisory lock code ...
  }
  ```

---

### MEDIUM-002: `season_recap.php` does not call `finContent()` for the empty-state branch

- **File:** `/home/guortates/TVLW/The-Very-Little-War/season_recap.php:14-16`
- **Domain:** SEASON_RESET
- **Description:** When no recap data exists, the code calls `debutContent()` at line 14 and `finContent()` at line 16, which is correct. However, in the non-empty branch (the `foreach` loop starting at line 18), neither `debutContent()` nor `finContent()` is called. This is not necessarily a bug if `debutCarte`/`finCarte` provide sufficient wrapper structure, but it creates an inconsistency: the empty-state path wraps content in `debutContent()`/`finContent()` while the populated path does not. If the layout functions expect matched pairs, the populated path may render with broken HTML structure.
- **Impact:** Minor UI inconsistency. If `debutContent()`/`finContent()` add critical wrapper elements (like a scrollable container), the populated season recap page may render with slightly broken styling. Functional impact is cosmetic only.
- **Suggested fix:** Verify that `debutCarte()`/`finCarte()` provide the content wrapper. If `debutContent()`/`finContent()` is needed, wrap the `foreach` loop as well:
  ```php
  } else {
      debutContent();
      foreach ($recaps as $recap) {
          // ... existing card rendering ...
      }
      finContent();
  }
  ```

---

### LOW-001: `archiveSeasonData()` idempotency guard uses `season_number` only -- no cross-check with `statistiques.debut`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:1082-1093`
- **Domain:** SEASON_RESET
- **Description:** The idempotency guard checks whether `season_recap` already contains rows for `$prevSeason`. The comments (lines 1063-1069) describe an intent to also cross-check against `statistiques.debut`, but the actual implementation (line 1086-1088) only checks `COUNT(*) FROM season_recap WHERE season_number = ?`. This is documented as intentional ("FIX: Use season_number only" at line 1083), but the consequence is that if the `season_recap` table is manually tampered with (rows deleted for the current season), the guard would fail to detect that archiving already occurred, and a retry would insert duplicate archive data with a new season number. The advisory lock in `performSeasonEnd()` prevents concurrent execution, but manual DB operations could still trigger this edge case.
- **Impact:** Very low probability. Only occurs if an admin manually deletes `season_recap` rows AND retries the season reset. The existing advisory lock and the fact that `performSeasonEnd()` is admin-only make this extremely unlikely.
- **Suggested fix:** No code change needed. This is an acceptable design tradeoff documented in the code. Adding a comment noting the manual-tamper caveat would improve clarity.

---

## Checklist Verification

| Check | Status | Notes |
|-------|--------|-------|
| Winner determination uses DB-fetched login | PASS | Line 1184: `$vainqueurManche = $data['login']` from `dbFetchAll` |
| Advisory lock in `performSeasonEnd()` | PASS | Line 1142: `GET_LOCK('tvlw_season_reset', 0)` |
| Lock name identical in both files | PASS | `'tvlw_season_reset'` in both basicprivatephp.php:286 and player.php:1142 |
| Archive runs BEFORE reset | PASS | Line 1238: `archiveSeasonData()` before line 1313: `remiseAZero()` |
| 15-table reset completeness | PARTIAL | `nbDons` column on `autre` not reset (HIGH-001) |
| VP awards before reset | PASS | Lines 1248-1310 (VP), then line 1310 (prestige), then line 1313 (reset) |
| Session invalidation post-reset | PASS | Line 1318: `UPDATE membre SET session_token = NULL` |
| Resource node generation post-reset | PASS | Line 1331: `generateResourceNodes()` |
| Winner news post | PASS | Lines 1344-1347: `INSERT INTO news` |
| Email queue drain | FAIL | Sentinel `-1` into UNSIGNED INT (HIGH-002) |
| Two-phase maintenance | PASS | basicprivatephp.php:269-301 (Phase 1) and 246-267 (Phase 2 block) |
| `season_recap` populated correctly | PASS | Lines 1107-1122 with correct types |
| `season_recap.php` read-only, auth, XSS-safe | PASS | Auth via basicprivatephp.php, all output escaped with `htmlspecialchars` |
| `processEmailQueue` non-blocking | PASS | Probabilistic, limited to 20 per call, no loops |
| `season_recap` INSERT correct types | PASS | `'isiiiidiiidsii'` matches 14 columns |
| No-data first-reset handling | PASS | `$nextSeason = 1` when no recap rows exist |
| Maintenance=0 guard in `performSeasonEnd` | FAIL | No guard (MEDIUM-001) |
| `MIN_SEASON_DAYS` guard | PASS | basicprivatephp.php:276-279 |
| Streak/comeback fields reset | PASS | Line 1514: `streak_days=0, streak_last_date=NULL, last_catch_up=0, comeback_shield_until=0` |

---

## Summary

**0 CRITICAL, 2 HIGH, 2 MEDIUM, 1 LOW**

The season reset pipeline is well-architected overall: advisory locking, idempotency guards, correct archive-before-reset ordering, session invalidation, and two-phase maintenance are all properly implemented. The two HIGH findings are actionable bugs: `nbDons` persisting across seasons (data correctness) and the unsigned integer sentinel causing email queue failures under strict SQL mode. The MEDIUM findings are defensive improvements (maintenance guard, minor UI inconsistency).



---

## FORUM

Now I have all the data I need for a comprehensive audit. Let me compile my findings.

---

# Forum Domain Audit -- Pass 25

## Findings

### HIGH-001: Moderator can ban other moderators and admins

- **File:** /home/guortates/TVLW/The-Very-Little-War/moderationForum.php:37-47
- **Domain:** FORUM
- **Description:** When creating a sanction (ban), the code only checks that the target player exists (`SELECT count(*) FROM membre WHERE login = ?`). It does not check whether the target is a moderator or admin. A moderator can therefore ban another moderator or even themselves, disrupting forum moderation capability.
- **Impact:** A rogue or compromised moderator account can lock all other moderators out of the forum by issuing bans against them. There is no hierarchy protection. This is a privilege escalation vector that undermines moderation integrity.
- **Suggested fix:** Before the INSERT on line 47, add a check:
  ```php
  // Before:
  dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)', 'ssss', ...);

  // After:
  $targetMod = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_POST['pseudo']);
  if ($targetMod && $targetMod['moderateur'] != '0') {
      $erreur = "<strong>Erreur</strong> : Impossible de sanctionner un modérateur.";
  } else {
      dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)', 'ssss', ...);
  }
  ```

### HIGH-002: Ban sanction motif rendered via BBCode without ban check on forum.php display

- **File:** /home/guortates/TVLW/The-Very-Little-War/forum.php:35
- **Domain:** FORUM
- **Description:** When a banned user views the forum, the ban motif is rendered through `BBcode($sanction['motif'])`. The motif is set by a moderator, but if a moderator account is compromised (or a malicious moderator exists), the motif can contain `[url=...]` tags pointing to arbitrary external URLs. Unlike `[img]` which is restricted to whitelisted domains, `[url]` allows any `https?://` URL. The motif is rendered to every banned user without any sanitization beyond what BBCode provides. The same pattern appears in `sujet.php:177`.
- **Impact:** A malicious moderator can embed phishing links in ban motifs that are shown to all banned users. Since these appear as part of the game's official moderation UI, users are more likely to trust them.
- **Suggested fix:** Either sanitize the motif with `htmlspecialchars()` only (no BBCode parsing) for ban messages, or restrict BBCode in motifs to formatting-only tags (no `[url]`). The simplest approach:
  ```php
  // Before:
  echo "Motif de la sanction : " . BBcode($sanction['motif']);
  // After:
  echo "Motif de la sanction : " . htmlspecialchars($sanction['motif'], ENT_QUOTES, 'UTF-8');
  ```

### HIGH-003: Topic creation does not increment nbMessages on `autre` table

- **File:** /home/guortates/TVLW/The-Very-Little-War/listesujets.php:81-85
- **Domain:** FORUM
- **Description:** When a new topic is created (lines 81-85), the `withTransaction` block inserts into `sujets` and `statutforum` but does NOT increment `nbMessages` on the `autre` table. In contrast, reply creation in `sujet.php:73` correctly increments it. Similarly, admin topic deletion in `admin/listesujets.php:50-54` decrements `nbMessages` for reply authors, creating an asymmetric counter. If a user creates many topics (which contain an initial message body) and those topics are later deleted by an admin, their `nbMessages` counter will go negative (or clamp to 0 via GREATEST), causing their forum rank (from `rangForum()`) to be incorrect.
- **Impact:** Forum post counts are inaccurate. Users who primarily create topics rather than replies appear to have fewer messages than they actually posted. The `rangForum()` function uses `SELECT count(*) FROM reponses WHERE auteur=?` not `nbMessages`, so the visual forum rank color is unaffected, but the `nbMessages` column on the player profile will be wrong. Admin deletions of topics will corrupt the counter downward.
- **Suggested fix:** Add `nbMessages` increment inside the topic creation transaction:
  ```php
  withTransaction($base, function() use ($base, $getId, $titre, $contenu, $login, $timestamp, &$sujetId) {
      dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $titre, $contenu, $login, $timestamp);
      $sujetId = mysqli_insert_id($base);
      dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sii', $login, $sujetId, $getId);
      dbExecute($base, 'UPDATE autre SET nbMessages = nbMessages + 1 WHERE login = ?', 's', $login);
  });
  ```

### HIGH-004: admin/supprimerreponse.php delete+decrement not wrapped in transaction (TOCTOU)

- **File:** /home/guortates/TVLW/The-Very-Little-War/admin/supprimerreponse.php:15-21
- **Domain:** FORUM
- **Description:** The admin reply deletion fetches the reply author (line 15), deletes the reply (line 16), then decrements `nbMessages` (line 20) outside of any transaction. If two concurrent admin requests delete the same reply, the author fetch can succeed for both before the DELETE executes, causing `nbMessages` to be decremented twice for a single reply. Compare with `editer.php:62-73` which correctly uses `withTransaction` + `FOR UPDATE`.
  ```php
  $replyRow = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ?', 'i', $supprimerId);
  dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $supprimerId);
  if ($replyRow && !empty($replyRow['auteur'])) {
      // ... decrement nbMessages
  }
  ```
- **Impact:** Race condition allows double-decrement of `nbMessages`, corrupting the player's message counter. Low probability in practice (requires two admins deleting the same reply simultaneously) but the pattern is inconsistent with the rest of the codebase.
- **Suggested fix:** Wrap in a transaction with FOR UPDATE:
  ```php
  withTransaction($base, function() use ($base, $supprimerId) {
      $replyRow = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ? FOR UPDATE', 'i', $supprimerId);
      dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $supprimerId);
      if ($replyRow && !empty($replyRow['auteur'])) {
          dbExecute($base, 'UPDATE autre SET nbMessages = GREATEST(0, nbMessages - 1) WHERE login = ?', 's', $replyRow['auteur']);
      }
  });
  ```

### MEDIUM-001: listesujets.php textarea for topic content has no maxlength HTML attribute

- **File:** /home/guortates/TVLW/The-Very-Little-War/listesujets.php:222
- **Domain:** FORUM
- **Description:** The textarea for topic content does not include a `maxlength` HTML attribute, unlike the title input which has `maxlength="' . FORUM_TITLE_MAX_LENGTH . '"`. The server-side check on line 76 enforces `FORUM_POST_MAX_LENGTH`, but the client gives no feedback to the user about the limit.
  ```php
  item(['floating' => true, 'titre' => "Contenu", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50"></textarea>']);
  ```
- **Impact:** Poor UX -- users can type a long message only to have it rejected on submit. The server-side guard is in place, so this is not a security issue, but it causes unnecessary frustration. Same pattern exists in `sujet.php:333` for replies.
- **Suggested fix:** Add `maxlength` attribute:
  ```php
  '<textarea name="contenu" id="contenu" rows="10" cols="50" maxlength="' . FORUM_POST_MAX_LENGTH . '"></textarea>'
  ```

### MEDIUM-002: sujet.php queries `rangForum('[supprime]')` for deleted/banned authors

- **File:** /home/guortates/TVLW/The-Very-Little-War/sujet.php:189-190
- **Domain:** FORUM
- **Description:** When the topic author is replaced with `'[supprime]'` (deleted or banned), the code still calls `rangForum('[supprime]')` and queries the `autre` table with `login = '[supprime]'`. This executes two unnecessary DB queries (one on `reponses`, one on `membre`) that will always return zero results. The same issue occurs in the reply loop at line 274 via `rangForum($reponseAuteur)` when `$reponseAuteur === '[supprime]'`.
  ```php
  $image = dbFetchOne($base, 'SELECT image, count(image) as nb FROM autre WHERE login = ?', 's', $sujetAuteur);
  $couleur = rangForum($sujetAuteur);
  ```
- **Impact:** Wasted DB queries on every page load for topics/replies by deleted or banned users. On threads with many deleted authors, this compounds. No functional bug, but unnecessary database load.
- **Suggested fix:** Short-circuit when author is `'[supprime]'`:
  ```php
  if ($sujetAuteur === '[supprimé]') {
      $image = ['image' => 'defaut.png', 'nb' => 0];
      $couleur = '#7A7B7A'; // default color for deleted users
  } else {
      $image = dbFetchOne($base, 'SELECT image, count(image) as nb FROM autre WHERE login = ?', 's', $sujetAuteur);
      $couleur = rangForum($sujetAuteur);
  }
  ```

### MEDIUM-003: editer.php type=2 reply edit does not check if topic is locked

- **File:** /home/guortates/TVLW/The-Very-Little-War/editer.php:212-272
- **Domain:** FORUM
- **Description:** When editing a reply (type=2), the code checks authorship but does not verify whether the parent topic is locked (`statut == 1`). In contrast, type=1 (topic edit) checks `$auteur['statut'] == 1` on line 166. The reply creation in `sujet.php:59-63` also correctly checks lock status. This means a user can still edit their existing replies on a locked topic, which defeats the purpose of locking.
  ```php
  if ($type == 2) {
      // No lock status check here
      if (empty($erreur) && $moderateur['moderateur'] == '0') {
          $auteur = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ?', 'i', $id);
          if ($auteur && $auteur['auteur'] == $_SESSION['login']) {
              dbExecute($base, 'UPDATE reponses SET contenu = ? WHERE auteur = ? AND id = ?', ...);
  ```
- **Impact:** Users can modify the content of their replies on locked topics. A moderator may lock a thread to preserve its state (e.g., after a dispute), but users can still alter their posts retroactively.
- **Suggested fix:** Add a lock check at the beginning of the type=2 block:
  ```php
  if ($type == 2) {
      if (mb_strlen($contenu) > FORUM_POST_MAX_LENGTH) {
          $erreur = "Le contenu est trop long (" . FORUM_POST_MAX_LENGTH . " caractères max).";
      }
      // Check topic lock status
      if (empty($erreur)) {
          $replyTopic = dbFetchOne($base, 'SELECT s.statut FROM reponses r JOIN sujets s ON s.id = r.idsujet WHERE r.id = ?', 'i', $id);
          if ($replyTopic && (int)$replyTopic['statut'] === 1 && $moderateur['moderateur'] == '0') {
              $erreur = "Ce sujet est verrouillé. Vous ne pouvez pas modifier vos réponses.";
          }
      }
  ```

### MEDIUM-004: Ban date validation allows past dates

- **File:** /home/guortates/TVLW/The-Very-Little-War/moderationForum.php:42-47
- **Domain:** FORUM
- **Description:** The date validation uses `checkdate()` which only validates calendar correctness (valid month/day/year), not that the date is in the future. The jQuery datepicker has `minDate: 1` (tomorrow), but a crafted POST request can submit a past date. A moderator could create a sanction with a `dateFin` in the past, which would be immediately expired and never shown in the "Sanctions en cours" list (which filters `dateFin >= CURDATE()`), wasting a database row.
  ```php
  if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) {
      $erreur = "<strong>Erreur</strong> : Date invalide.";
  } else {
      // No check that $date > today
      dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)', ...);
  }
  ```
- **Impact:** Invisible dead rows accumulate in the sanctions table. Moderators might think they issued a ban but it never takes effect. Low severity since it only affects moderator workflow, not security.
- **Suggested fix:** After formatting the date, validate it is at least tomorrow:
  ```php
  $date = sprintf('%04d-%02d-%02d', (int)$annee, (int)$mois, (int)$jour);
  if ($date <= date('Y-m-d')) {
      $erreur = "<strong>Erreur</strong> : La date de fin doit être dans le futur.";
  } else {
      dbExecute($base, ...);
  }
  ```

### LOW-001: BBCode [url] allows arbitrary external URLs

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php:31-41
- **Domain:** FORUM
- **Description:** While `[img]` tags are correctly restricted to relative paths and `theverylittlewar.com`, the `[url]` tag allows any `https?://` URL. The code does add a domain indicator `<small>(domain.com)</small>` and `rel="nofollow noopener noreferrer"`, which mitigates the most common abuse vectors. However, unlike `[img]`, there is no domain whitelist.
- **Impact:** Users can post links to phishing sites, malware, or offensive content. The domain indicator helps users make informed decisions, and `rel="nofollow noopener noreferrer"` prevents tab-nabbing. The risk is mitigated but not eliminated. This is acceptable for a forum (restricting all URLs would be overly limiting), so this is LOW severity. Consider adding a link interstitial page for external URLs if phishing becomes a problem.
- **Suggested fix:** No immediate action required. The domain indicator + `rel` attributes are good mitigations. If needed later, add a redirect interstitial: `href="/redirect.php?url=..."` that warns users they are leaving the site.

### LOW-002: admin/listesujets.php topic deletion does not decrement nbMessages for the topic author

- **File:** /home/guortates/TVLW/The-Very-Little-War/admin/listesujets.php:44-56
- **Domain:** FORUM
- **Description:** When an admin deletes a topic, the code correctly decrements `nbMessages` for each reply author (lines 50-54), but it does not decrement for the topic author (the `sujets.auteur`). This is currently consistent with the fact that topic creation also does not increment `nbMessages` (see HIGH-003). However, if HIGH-003 is fixed to increment on topic creation, this code must also be updated to decrement for the topic author on deletion.
- **Impact:** No current functional impact due to symmetric omission. Will become a bug if HIGH-003 is fixed without updating this file. Flagged as a reminder.
- **Suggested fix:** After fixing HIGH-003, add topic author decrement:
  ```php
  $topicRow = dbFetchOne($base, 'SELECT auteur FROM sujets WHERE id = ?', 'i', $supprimersujet);
  // ... inside the transaction, after deleting the topic:
  if ($topicRow && !empty($topicRow['auteur'])) {
      dbExecute($base, 'UPDATE autre SET nbMessages = GREATEST(0, nbMessages - 1) WHERE login = ?', 's', $topicRow['auteur']);
  }
  ```

### LOW-003: sujet.php `$image` query uses non-standard `count(image) as nb` pattern

- **File:** /home/guortates/TVLW/The-Very-Little-War/sujet.php:189
- **Domain:** FORUM
- **Description:** The query `SELECT image, count(image) as nb FROM autre WHERE login = ?` mixes a regular column with an aggregate function without `GROUP BY`. In MySQL/MariaDB with `ONLY_FULL_GROUP_BY` disabled (which is the default for MariaDB 10.11 with legacy mode), this works but returns non-deterministic results if multiple rows match. Since `login` is unique in `autre`, this works correctly in practice, but the SQL is semantically incorrect. A cleaner approach would be to check `$row !== null` instead of using a count trick.
- **Impact:** No functional bug with the current schema (login is unique), but the query pattern is fragile and would break under `ONLY_FULL_GROUP_BY` strict mode.
- **Suggested fix:**
  ```php
  $imageRow = dbFetchOne($base, 'SELECT image FROM autre WHERE login = ?', 's', $sujetAuteur);
  $image = $imageRow ? $imageRow : ['image' => 'defaut.png'];
  ```

---

## Summary

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | 0 | -- |
| HIGH | 4 | H-001 (mod can ban mods), H-002 (BBCode in ban motif), H-003 (topic creation missing nbMessages increment), H-004 (admin delete race condition) |
| MEDIUM | 4 | M-001 (textarea missing maxlength), M-002 (wasted queries for deleted authors), M-003 (reply edit on locked topic), M-004 (past ban date accepted) |
| LOW | 3 | L-001 (URL tag allows external links), L-002 (admin delete asymmetric with topic creation), L-003 (non-standard aggregate query) |

**Total: 0 CRITICAL, 4 HIGH, 4 MEDIUM, 3 LOW**



---

## COMBAT

Key insight from line 13: `MYSQLI_REPORT_ERROR|STRICT` means `mysqli_prepare` will **throw a `mysqli_sql_exception`** for an unknown column, not return false. This exception is uncaught in the combat resolution code (the try/catch on line 469 of game_actions.php catches `\RuntimeException` and `\Throwable`). The `\Throwable` catch on line 474 would catch it, log it, and the combat would silently fail without refunding molecules (the refund logic is only in the `RuntimeException('cas_skip')` path).

Actually, looking again at line 474: `catch (\Throwable $combatException)` -- this catches the `mysqli_sql_exception`, logs it, and then... what happens to the transaction? The `withTransaction` function should handle the rollback. So the CAS guard (attaqueFaite=1) would be rolled back, meaning the attack would be retried on next page load, creating an infinite retry loop.

Actually wait, the CAS guard sets `attaqueFaite=1` INSIDE the transaction. If the transaction is rolled back, `attaqueFaite` stays 0, and the attack will be retried every time `updateActions` is called. This creates an infinite retry loop that silently fails each time.

This is CRITICAL. Let me finalize my audit.

---

# COMBAT Domain Audit -- Pass 25

## Findings

### CRITICAL-001: Combat resolution queries `comeback_shield_until` from wrong table (`membre` instead of `autre`)

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:204
- **Domain:** COMBAT
- **Description:** The combat resolution code queries `comeback_shield_until` from the `membre` table, but migration 0028 added this column to the `autre` table. The same bug exists for espionage resolution at line 503.

```php
// Line 204 (combat resolution):
$defStatut = dbFetchOne($base, 'SELECT vacance, estExclu, comeback_shield_until FROM membre WHERE login=?', 's', $actions['defenseur']);

// Line 503 (espionage resolution):
$espDefStatut = dbFetchOne($base, 'SELECT vacance, estExclu, comeback_shield_until FROM membre WHERE login=?', 's', $espActions['defenseur']);
```

Meanwhile `hasActiveShield()` in player.php:1724 correctly queries `autre`:
```php
$row = dbFetchOne($base, 'SELECT comeback_shield_until FROM autre WHERE login = ?', 's', $login);
```

- **Impact:** Under `MYSQLI_REPORT_ERROR|STRICT` (see database.php line 13), `mysqli_prepare` throws `mysqli_sql_exception` for the unknown column. This exception is caught by the `\Throwable` handler at game_actions.php:474, which logs the error and continues. The `withTransaction` wrapper rolls back the CAS guard (`attaqueFaite=1`), leaving the attack stuck in an infinite retry loop -- every call to `updateActions()` re-attempts and re-fails. No combat or espionage can ever resolve successfully on the live server. If the column was manually added to `membre` on the VPS (outside the migration), then the column has DEFAULT 0 and shields are silently ignored at resolution time.
- **Suggested fix:** Split the query into two: fetch `vacance, estExclu` from `membre` and `comeback_shield_until` from `autre`. Apply to both combat (line 204) and espionage (line 503) resolution paths.

**Before (line 204):**
```php
$defStatut = dbFetchOne($base, 'SELECT vacance, estExclu, comeback_shield_until FROM membre WHERE login=?', 's', $actions['defenseur']);
```

**After:**
```php
$defStatut = dbFetchOne($base, 'SELECT vacance, estExclu FROM membre WHERE login=?', 's', $actions['defenseur']);
$defShield = dbFetchOne($base, 'SELECT comeback_shield_until FROM autre WHERE login=?', 's', $actions['defenseur']);
// Merge for downstream code:
if ($defStatut && $defShield) {
    $defStatut['comeback_shield_until'] = $defShield['comeback_shield_until'];
} elseif ($defStatut) {
    $defStatut['comeback_shield_until'] = 0;
}
```

Same pattern for espionage at line 503.

---

### HIGH-001: Attack path does not check `estExclu` (banned status) on target player

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:174
- **Domain:** COMBAT
- **Description:** The espionage guard at line 44-45 correctly checks `estExclu` on the target and blocks banned players. The attack guard at line 174 only fetches `vacance,timestamp` and never checks `estExclu`:

```php
// Espionage (correct):
$espTarget = dbFetchOne($base, 'SELECT vacance,timestamp,estExclu FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
if (!$espTarget || $espTarget['estExclu'] == 1) { ...

// Attack (missing estExclu check):
$enVac = dbFetchOne($base, 'SELECT vacance,timestamp FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
if (!$enVac) { // only checks existence, not banned status
```

- **Impact:** Players can launch attacks against banned accounts. At resolution time, game_actions.php line 209 does check `estExclu` and cancels, but the attacker's energy is already spent and molecules are deducted at launch time. The refund at resolution only returns molecules, not the energy cost. This wastes the attacker's energy and creates unnecessary pending actions in the database.
- **Suggested fix:**

**Before (line 174):**
```php
$enVac = dbFetchOne($base, 'SELECT vacance,timestamp FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
if (!$enVac) {
    $erreur = "Ce joueur n'existe pas.";
```

**After:**
```php
$enVac = dbFetchOne($base, 'SELECT vacance,timestamp,estExclu FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
if (!$enVac || $enVac['estExclu'] == 1) {
    $erreur = "Ce joueur n'existe pas.";
```

---

### HIGH-002: `attaque.php` displays troop composition using attacker's **current** molecules, not launch-time snapshot

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaque.php:66-74
- **Domain:** COMBAT
- **Description:** When viewing a pending attack, the page fetches the attacker's current molecule roster to label troop columns:

```php
$moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $_SESSION['login']);
$c = 0;
foreach($moleculesRows as $molecules){
    if($troupes[$c] > 0){
        echo '<tr><td>'.couleurFormule($molecules['formule']).'</td><td>'.$troupes[$c].'</td></tr>';
    }
    $c++;
}
```

If the attacker deletes a molecule class and creates a new one (different formula) in the same slot after launching the attack, the displayed formula will be wrong. The `$troupes` array contains the correct counts from launch time, but the labels come from the current DB state.

- **Impact:** Misleading display: an attacker sees incorrect molecule formulas next to their troop counts for a pending attack. No gameplay or data corruption, but confusing UX that could lead to poor decisions.
- **Suggested fix:** Store the troop formulas in the `actionsattaques` row at launch time (e.g., a `troupes_formulas` column with semicolon-delimited formulas), or at minimum display the slot number (Classe 1, Classe 2, etc.) instead of the current formula.

---

### MEDIUM-001: `armee.php` molecule creation charges energy using stale `$cout['niveauclasse']` for cost display but correct for deduction

- **File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:434-436
- **Domain:** COMBAT
- **Description:** The cost display outside the transaction (line 434) fetches `niveauclasse` without `FOR UPDATE`, so it could show a stale cost if another request creates a class concurrently. The actual deduction inside the transaction (line 235-236) is correct (uses `FOR UPDATE`). This is a minor display-vs-reality discrepancy.

```php
// Outside transaction (display only, line 434):
$cout = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
echo coutEnergie(coutClasse($cout['niveauclasse']));
```

- **Impact:** If two tabs are open and one creates a class, the other tab shows the old (lower) cost. The user might expect to pay less than they actually do. The actual charge is correct.
- **Suggested fix:** Acknowledge this is a low-risk TOCTOU display issue. If desired, add a note in the UI: "Le cout reel sera verifie lors de la creation."

---

### MEDIUM-002: `attaque.php` does not verify the viewer owns the attacking action before displaying troop details

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaque.php:9
- **Domain:** COMBAT
- **Description:** The query correctly filters by `attaquant=?` using `$_SESSION['login']`:

```php
$attaque = dbFetchOne($base, 'SELECT * FROM actionsattaques WHERE id=? AND attaquant=? AND troupes!=?', 'iss', $_GET['id'], $_SESSION['login'], 'Espionnage');
```

This is correct -- only the attacker can see their own pending attack. However, the **defender** gets attack countdown notifications on `attaquer.php` (line 479-494) showing the attacker's name and ETA. This is by design (defender can see incoming attacks). No actual authorization issue here.

- **Impact:** None -- this is working as designed. (Withdrawing as a finding.)

---

### MEDIUM-003: Dispersee overkill redistribution includes dead classes in count

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/combat.php:312-314
- **Domain:** COMBAT
- **Description:** In the Dispersee formation casualty loop, the code counts "live classes ahead" at line 312-314:

```php
$liveClassesAhead = 0;
for ($j = $i + 1; $j <= $nbClasses; $j++) {
    if (($classeDefenseur[$j]['nombre'] - ($defenseurMort[$j] ?? 0)) > 0) $liveClassesAhead++;
}
```

But `$defenseurMort[$j]` is initialized to 0 for classes $j > $i (not yet processed in the forward loop), so this check `$classeDefenseur[$j]['nombre'] - 0 > 0` just checks if the class has any starting units. This is correct in concept -- classes that start with 0 molecules are excluded. However, if an earlier iteration (same loop pass) killed all units of class $j, the `$defenseurMort[$j]` would still be 0 here because the forward pass hasn't reached $j yet. The forward pass only sets `$defenseurMort[$j]` when it processes class $j.

Wait -- actually `$defenseurMort` is initialized at line 308 `$defenseurMort[$i] = 0` inside the loop, so for $j > $i, `$defenseurMort[$j]` hasn't been set yet (it's still from a prior loop iteration or default). The `?? 0` handles the case. This is actually fine -- the overkill redistribution counts classes that have starting molecules as "live ahead", which is the correct approximation since we haven't calculated damage for them yet.

- **Impact:** None -- withdrawing this finding. The logic is correct.

---

### MEDIUM-004: Neutrino purchase uses `'is'` type string but `neutrinos` is an integer column

- **File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:89
- **Domain:** COMBAT
- **Description:** The neutrino update uses type 'is' (integer, string):
```php
dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $login);
```
This is correct -- `neutrinos` is an INT column and 'i' binds it as integer. No bug here. (Withdrawing.)

---

### MEDIUM-005: Energy cost deduction uses `'ds'` (double) for `energie` column

- **File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:93
- **Domain:** COMBAT
- **Description:** The energy deduction:
```php
$newEnergie = max(0, $res['energie'] - $nombreNeutrinos * $coutNeutrino);
dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $login);
```
Uses 'd' (double). If `energie` is a BIGINT column, binding as double can lose precision for very large values (above 2^53). However, energy values in this game are unlikely to reach that magnitude. (Withdrawing as not practically relevant.)

---

### LOW-001: `attaque.php` timer can display negative countdown values

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaque.php:23-27
- **Domain:** COMBAT
- **Description:** The return time display calculates `$attaque['tempsRetour'] - time()` without clamping to 0:

```php
echo nombreTemps('<strong>Retour</strong> : <span id="affichage'.$attaque['id'].'">'.affichageTemps($attaque['tempsRetour']-time()).'</span>');
// ...
var valeur = '.($attaque['tempsRetour']-time()).';
```

If the return time has already passed but `updateActions` hasn't been called yet, this injects a negative value into the JavaScript timer variable. The `affichageTemps` function may display garbage (negative hours/minutes).

- **Impact:** Minor cosmetic -- the page auto-reloads when the timer hits 0, which would trigger `updateActions`, but between the page load and the timer reaching 0, the display could show negative time.
- **Suggested fix:** Clamp to zero: `max(0, $attaque['tempsRetour'] - time())`

---

### LOW-002: Trailing semicolon in troupes string creates an empty segment

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:283
- **Domain:** COMBAT
- **Description:** The troop string is built with a trailing semicolon:
```php
$troupes = $troupes . $_POST['nbclasse' . $c] . ';';
```
This creates strings like "10;20;0;5;" -- when `explode(";", ...)` is called later, the trailing semicolon produces an empty final element. The combat resolution code at combat.php:24 uses `$chaineExplosee[$c - 1]` with index-based access and the `?? ''` guard handles this, and game_actions.php:251 has an `is_numeric` guard. So this is handled defensively, but the trailing semicolon is a latent fragility.

- **Impact:** No current bug due to defensive guards, but the trailing semicolon means `count(explode(";", $troupes))` returns `$nbClasses + 1`, which could confuse future code.
- **Suggested fix:** Use `rtrim($troupes, ';')` before storing, or build with `implode(';', $troopArray)`.

---

### LOW-003: `armee.php` molecule deletion decrements `niveauclasse` without floor check

- **File:** /home/guortates/TVLW/The-Very-Little-War/armee.php:17
- **Domain:** COMBAT
- **Description:**
```php
$niveauclasse = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login=? FOR UPDATE', 's', $login);
$newNiveauClasse = $niveauclasse['niveauclasse'] - 1;
dbExecute($base, 'UPDATE ressources SET niveauclasse=? WHERE login=?', 'is', $newNiveauClasse, $login);
```

There is no `max(0, ...)` or `max(1, ...)` guard. If somehow `niveauclasse` is already 0 or 1 (e.g., data corruption, concurrent deletion), it could go to 0 or -1. The `coutClasse()` function uses this value with `pow($numero + CLASS_COST_OFFSET, CLASS_COST_EXPONENT)`, which could produce unexpected results for negative inputs.

- **Impact:** Low probability -- the guard at line 9 checks the molecule isn't already "Vide", and niveauclasse should always match the count of non-Vide molecules. But a concurrent deletion race could theoretically make it inconsistent.
- **Suggested fix:** Add `$newNiveauClasse = max(0, $niveauclasse['niveauclasse'] - 1);`

---

## Summary

| Severity | Count | Details |
|----------|-------|---------|
| CRITICAL | 1 | C-001: `comeback_shield_until` queried from wrong table (`membre` vs `autre`) in combat + espionage resolution -- either blocks all combat resolution (SQL error) or silently ignores shields |
| HIGH | 2 | H-001: Attack path missing `estExclu` check on target; H-002: `attaque.php` displays current molecules instead of launch-time snapshot |
| MEDIUM | 1 | M-001: Stale energy cost display for molecule creation (cosmetic TOCTOU) |
| LOW | 3 | L-001: Negative countdown display; L-002: Trailing semicolon in troupes string; L-003: No floor guard on niveauclasse decrement |

**Total: 1 CRITICAL, 2 HIGH, 1 MEDIUM, 3 LOW**

The CRITICAL finding (C-001) is the highest priority and should be fixed immediately. If `comeback_shield_until` does not exist on the `membre` table in the live database, every combat and espionage resolution will fail with a SQL exception, roll back, and retry infinitely on every `updateActions()` call. If it was manually added to `membre` on the VPS, then shields are silently ignored at resolution time (DEFAULT 0 on `membre` while the actual value is stored on `autre`).



---

## ESPIONAGE

Now I have a thorough understanding. Let me compile the findings.

---

## ESPIONAGE Domain Audit -- Pass 25

### MEDIUM-001: Espionage report INSERT omits `image` column on NOT NULL column without DEFAULT

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:644
- **Domain:** ESPIONAGE
- **Description:** The espionage report INSERT statement omits the `image` column:
  ```php
  dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, type) VALUES(?, ?, ?, ?, ?)', 'issss', ...);
  ```
  The `rapports.image` column is defined as `varchar(255) NOT NULL` with no DEFAULT value (see `tests/integration/fixtures/base_schema.sql:230`). The same omission occurs at line 651 for the defender's report. In contrast, combat report INSERTs at lines 459 and 461 correctly include the `image` column. MariaDB in non-strict mode silently inserts an empty string with a warning, but under strict mode (STRICT_TRANS_TABLES) this would cause an outright INSERT failure, breaking espionage entirely.
- **Impact:** Under strict SQL mode, all espionage report creation fails silently inside a transaction (the outer `withTransaction` catches the error and marks the action processed via the CAS guard, so neutrinos are consumed but no report is ever delivered). Even in non-strict mode, the empty `image` field causes the report list in rapports.php to render with a broken/empty image cell.
- **Suggested fix:**
  ```php
  // Before (line 644):
  dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, type) VALUES(?, ?, ?, ?, ?)', 'issss', $espActions['tempsAttaque'], $titreRapportJoueur, $contenuRapportJoueur, $espActions['attaquant'], 'espionage');
  
  // After:
  dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES(?, ?, ?, ?, 0, ?, ?)', 'isssss', $espActions['tempsAttaque'], $titreRapportJoueur, $contenuRapportJoueur, $espActions['attaquant'], '<img alt="espion" src="images/rapports/espionnage.png" class="imageAide">', 'espionage');
  ```
  Apply the same fix to the defender report INSERT at line 651.

### MEDIUM-002: Espionage reveals defender's exact neutrino count indirectly via success/failure threshold

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:526-528
- **Domain:** ESPIONAGE
- **Description:** The espionage success formula is `espionageThreshold = defenderNeutrinos * ESPIONAGE_SUCCESS_RATIO * radarDiscount`. An attacker can binary-search the defender's neutrino count by sending incrementally larger neutrino amounts and observing success vs. failure. The report explicitly says "vous avez envoye moins de la moitie des neutrinos de votre adversaire" on failure. This is by-design game information warfare, but the report text literally tells the attacker the exact threshold rule, making it trivial to deduce the defender's neutrino count within 1 unit.
- **Impact:** Low strategic impact since neutrino counts are not highly secret, but the failure message reveals the exact game formula to the player. This is an information leak of the game mechanic itself rather than a security vulnerability. Marking as MEDIUM because it can be exploited to precisely measure a target's neutrino reserves via repeated cheap espionage attempts.
- **Suggested fix:** Consider obfuscating the failure message to not reveal the exact ratio:
  ```php
  // Before:
  $contenuRapportJoueur = "<p>Votre espionnage a raté, vous avez envoyé moins de la moitié des neutrinos de votre adversaire.</p>";
  
  // After:
  $contenuRapportJoueur = "<p>Votre espionnage a échoué. La cible disposait de défenses suffisantes pour bloquer vos agents.</p>";
  ```

### MEDIUM-003: Spy report reveals defender's defensive formation name

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:630
- **Domain:** ESPIONAGE
- **Description:** A successful espionage report includes the defender's defensive formation:
  ```php
  important('Formation défensive') . "<strong>" . htmlspecialchars($FORMATIONS[$constructionsJoueur['formation'] ?? 0]['name'] ?? 'Dispersée') . "</strong>"
  ```
  The defensive formation is a critical tactical choice that significantly affects combat outcomes (e.g., Phalange gives +50% defense, Embuscade gives +40% attack). Revealing this to the attacker before an attack gives a decisive strategic advantage that may be unintended -- it allows the attacker to pick the perfect counter-formation.
- **Impact:** This is a design decision rather than a bug. If the intent is that espionage should reveal military posture (army, buildings, resources), then formation fits. If formation is meant to be a hidden tactical surprise, this is a significant information leak. Flagging for design review.
- **Suggested fix:** If this is unintended, remove the formation line from the report. If intended, no action needed -- just documenting the design decision.

### LOW-001: No upper bound validation on neutrino count sent for espionage

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:91
- **Domain:** ESPIONAGE
- **Description:** The validation on line 91 checks `$_POST['nombreneutrinos'] <= $autre['neutrinos']` which correctly prevents spending more neutrinos than owned. However, `$autre['neutrinos']` is read before the transaction. The FOR UPDATE lock inside the transaction (line 111-113) properly re-validates, so there is no race condition. However, there is no server-side cap on the maximum neutrinos that can be sent per espionage mission. The HTML form has `max="' . $autre['neutrinos'] . '"` but this is client-side only.
- **Impact:** Negligible -- the player can only spend what they own (enforced by FOR UPDATE lock). A player sending all their neutrinos on one spy mission is a valid (if unwise) strategy. No exploit vector.
- **Suggested fix:** No action required. The existing FOR UPDATE validation is sufficient.

### LOW-002: No Visitor account check on espionage target

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:44-46
- **Domain:** ESPIONAGE
- **Description:** The espionage branch checks `!$espTarget` (non-existent player) and `estExclu == 1` (banned), but does not check whether the target is a Visitor/test account (login starting with "Visitor"). The attack branch also lacks this check, so this is consistent behavior. Visitor accounts are meant for demo purposes and typically have minimal resources.
- **Impact:** Minimal -- spying on a Visitor account wastes the attacker's neutrinos and reveals a nearly empty base. Not exploitable.
- **Suggested fix:** If Visitor accounts should be invisible to espionage, add a check:
  ```php
  } elseif (strpos($espTarget_login, 'Visitor') === 0) {
      $erreur = "Ce joueur n'existe pas.";
  }
  ```
  But this is a design preference, not a bug.

### LOW-003: Nested transaction in espionage resolution (inner withTransaction inside outer withTransaction)

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:643 inside 482
- **Domain:** ESPIONAGE
- **Description:** Line 643 calls `withTransaction()` inside the outer `withTransaction()` that starts at line 482. MariaDB does not support true nested transactions. The inner `withTransaction` likely uses SAVEPOINTs (if the helper supports it) or silently starts a new transaction which commits the outer one prematurely.
- **Impact:** If `withTransaction()` uses `BEGIN`/`COMMIT`, the inner `COMMIT` at line 655 will commit the outer transaction early, and the outer `COMMIT` at line 656 becomes a no-op. This means the CAS guard at line 487 and the espionage resolution are not truly atomic. In practice, the CAS guard (UPDATE ... SET attaqueFaite=1 WHERE attaqueFaite=0) provides idempotency, so double-processing risk is mitigated by that mechanism. However, if the inner transaction fails (e.g., INSERT fails), the outer transaction's CAS update is already committed and cannot be rolled back.
- **Suggested fix:** Verify that `withTransaction()` supports SAVEPOINT nesting. If not, flatten the inner transaction into the outer one by moving the report INSERT and DELETE into the outer closure.

---

## Summary

**0 CRITICAL, 0 HIGH, 3 MEDIUM, 3 LOW**

The espionage domain is well-hardened after the previous passes. The most impactful finding is **MEDIUM-001** (missing `image` column in espionage report INSERTs), which can cause INSERT failures under strict SQL mode and currently results in empty image cells in the report listing. The remaining findings are informational or low-risk design considerations. All security-critical aspects (CSRF, FOR UPDATE atomicity, vacation/ban/shield checks, beginner protection, pact checks, self-espionage, rate limiting, XSS protection in reports) are correctly implemented.



---

## ECONOMY

This is a real cross-domain bug but it's in game_actions.php (combat resolution), not in the economy files. I'll note it but it belongs more to the COMBAT domain. Let me focus on economy-specific findings.

Let me now verify the final checklist item about the donation alliance membership inside the transaction.

Looking at don.php:
- Line 24: `$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', ...)` -- fetched outside transaction
- Line 81: `withTransaction($base, function() use ($base, $idalliance) {` -- closure captures the pre-fetched `$idalliance`
- Line 84: `dbFetchOne($base, 'SELECT energieDonnee FROM autre WHERE login=? FOR UPDATE', ...)` -- locks `autre` row but does NOT re-read `idalliance`
- Line 103: Updates alliance with the captured (potentially stale) `$idalliance`

TOCTOU: Between line 24 and line 84, the player could be kicked from their alliance (another request sets `autre.idalliance = 0`). The FOR UPDATE on line 84 locks the row, but by that point `$idalliance` is already captured. The locked row's `idalliance` might now be 0 or a different alliance, but the code uses the stale value from `use ($base, $idalliance)`.

This is a legitimate finding -- the donation should re-verify alliance membership inside the transaction.

Now let me compile all findings.

---

# ECONOMY Domain Audit -- Pass 25

## Findings

### HIGH-001: Donation does not re-verify alliance membership inside transaction (TOCTOU)

- **File:** /home/guortates/TVLW/The-Very-Little-War/don.php:24,81-84
- **Domain:** ECONOMY
- **Description:** The player's alliance ID is fetched on line 24 outside the transaction (`$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', ...)`), then captured by the closure on line 81 (`use ($base, $idalliance)`). Inside the transaction, `autre` is locked with FOR UPDATE on line 84, but only `energieDonnee` is read -- `idalliance` is not re-checked. If the player is kicked from their alliance between line 24 and line 84 (a concurrent admin/officer action), the donation proceeds to the old alliance using the stale `$idalliance` value.
- **Impact:** A player who has been kicked from their alliance can still donate energy to that alliance for a brief race window. This allows a kicked/departed member to inject energy into an alliance they no longer belong to, which is both a logic error and could be abused to funnel energy to a friend's alliance after intentionally leaving.
- **Suggested fix:**
  ```php
  // BEFORE (line 84):
  $energieDonnee = dbFetchOne($base, 'SELECT energieDonnee FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);

  // AFTER (line 84):
  $autreRow = dbFetchOne($base, 'SELECT energieDonnee, idalliance FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
  if ((int)($autreRow['idalliance'] ?? 0) !== (int)$idalliance['idalliance']) {
      throw new \RuntimeException('ALLIANCE_CHANGED');
  }
  ```
  Then handle the `ALLIANCE_CHANGED` exception in the catch block with an appropriate error message.

---

### HIGH-002: Donation energy deduction not guarded with GREATEST(0, ...) in SQL

- **File:** /home/guortates/TVLW/The-Very-Little-War/don.php:101
- **Domain:** ECONOMY
- **Description:** The energy deduction is: `UPDATE ressources SET energie = energie - ? WHERE login=?`. While there is a PHP-level check on line 87 (`$ressources['energie'] - $_POST['energieEnvoyee'] < DONATION_MIN_ENERGY_RESERVE`), this check reads `energie` with FOR UPDATE, and the deduction happens atomically in the same transaction, so under normal conditions the check prevents negative values. However, the check on line 87 validates against `$_POST['energieEnvoyee']` while line 101 deducts `$montant`. In the normal case `$montant <= $_POST['energieEnvoyee']` so the guard holds. But the SQL itself lacks a `GREATEST(0, ...)` defense-in-depth guard that is consistently applied elsewhere in the codebase (e.g., `updateRessources` line 267). If future code changes alter the control flow, the missing SQL guard becomes a vulnerability.
- **Impact:** Low immediate risk due to the PHP-level check and FOR UPDATE lock. However, it violates the codebase's established defensive pattern where all resource mutations use `GREATEST(0, ...)` to prevent negative resource values. A future maintenance error could allow negative energy.
- **Suggested fix:**
  ```php
  // BEFORE (line 101):
  dbExecute($base, 'UPDATE ressources SET energie = energie - ? WHERE login=?', 'ds', $montant, $_SESSION['login']);

  // AFTER:
  dbExecute($base, 'UPDATE ressources SET energie = GREATEST(0, energie - ?) WHERE login=?', 'ds', $montant, $_SESSION['login']);
  ```

---

### MEDIUM-001: revenuEnergie() cache key does not account for mutable state changes

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:9-11
- **Domain:** ECONOMY
- **Description:** The static cache in `revenuEnergie()` uses `$cacheKey = $joueur . '-' . $niveau . '-' . $detail` as the key. This caches the result for the entire request lifecycle. However, the function's output depends on several mutable factors beyond the key: the player's alliance duplicateur level, iode molecule counts, medal progress, prestige unlocks, resource node bonuses, compound bonuses, and specialization modifiers. If any of these change mid-request (e.g., a compound expires, or an alliance research is upgraded by another request during a long-running page), the cached value becomes stale. Within a single PHP request, this is unlikely to cause issues since these values rarely change. The same pattern exists in `revenuAtome()` (line 93).
- **Impact:** In practice, minimal impact since these values change rarely and PHP requests are short-lived. However, the cache key is misleading -- it appears to vary by `(joueur, niveau, detail)` but the output actually depends on ~8 additional parameters not included in the key.
- **Suggested fix:** This is a design note rather than an urgent fix. For correctness, the cache should either include all dependency values in the key, or the cache should be documented as "valid only for a single page render where player state is assumed immutable." Adding a comment is the minimal fix:
  ```php
  // Cache is valid for the current request only. Output depends on alliance duplicateur,
  // iode count, medals, prestige, resource nodes, compounds, and specialization -- all
  // assumed immutable within a single request.
  static $cache = [];
  ```

---

### MEDIUM-002: Molecule decay reads from snapshot taken before transaction, not inside it

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:242,277-278
- **Domain:** ECONOMY
- **Description:** On line 242, `$moleculesRows` is fetched **before** the transaction begins (line 252). Inside the transaction (line 277), the code iterates over this stale snapshot and applies decay with `UPDATE molecules SET nombre=?`. If another concurrent request (e.g., combat resolution or molecule formation) modifies `molecules.nombre` between line 242 and the transaction's UPDATE on line 282, the decay calculation uses the stale `$molecules['nombre']` value. The CAS guard on `tempsPrecedent` prevents double resource updates, but does not prevent a stale molecule snapshot from overwriting a concurrent combat loss.
  ```php
  // Line 242: fetched BEFORE withTransaction()
  $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $joueur);
  ```
- **Impact:** If combat resolution decreases a molecule count to 50 right before the decay transaction runs, the decay code still uses the old count (say 100) and computes `floor(pow(coef, seconds) * 100)`. If `coef^seconds * 100 = 95`, it writes `nombre=95`, effectively undoing the combat loss (restoring from 50 to 95). The CAS on `tempsPrecedent` does not protect against this because `tempsPrecedent` is in the `autre` table while molecule counts are in the `molecules` table.
- **Suggested fix:** Move the molecule SELECT inside the transaction, and use FOR UPDATE to lock molecule rows:
  ```php
  // Inside the withTransaction closure, AFTER the CAS check:
  $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $joueur);
  ```

---

### MEDIUM-003: coefDisparition() performs DB query for $type==0 but $donnees used unconditionally later

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:232-233,254-258
- **Domain:** ECONOMY
- **Description:** On line 232-233, the `$donnees` variable (molecule row) is only assigned when `$type == 0`:
  ```php
  if ($type == 0) {
      $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', ...);
  }
  ```
  Then on line 254-258, `$donnees` is accessed unconditionally when `$type == 0`:
  ```php
  if ($type == 0) {
      $nbAtomes = 0;
      foreach ($nomsRes as $num => $ressource) {
          $nbAtomes = $nbAtomes + ($donnees[$ressource] ?? 0);
      }
  }
  ```
  If `$type == 0` but the DB query returns `null` (molecule row doesn't exist -- deleted class, empty slot), `$donnees` is null, and `$donnees[$ressource]` triggers a PHP warning (accessing index on null). The `?? 0` fallback catches the value, but the warning is still emitted.
  
  Additionally, on lines 274-276, `$donnees['isotope']` is accessed for isotope modifiers when `$type == 0`, but if `$donnees` is null, this generates another warning.
- **Impact:** PHP warnings in logs for edge cases (empty molecule classes). No incorrect behavior due to `?? 0` fallback, but generates noise in error logs and indicates a missing null guard.
- **Suggested fix:**
  ```php
  // BEFORE (line 232-233):
  if ($type == 0) {
      $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
  }

  // AFTER:
  if ($type == 0) {
      $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
      if (!$donnees) {
          $cache[$cacheKey] = 1.0; // No molecule = no decay
          return 1.0;
      }
  }
  ```

---

### LOW-001: revenuEnergie() issues 5 separate DB queries per call (not cached across detail modes)

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:18,27,31,37,58
- **Domain:** ECONOMY
- **Description:** Each call to `revenuEnergie()` performs up to 5 DB queries:
  1. `SELECT * FROM constructions` (line 18)
  2. `SELECT idalliance, totalPoints, energieDepensee FROM autre` (line 27)
  3. `SELECT duplicateur FROM alliances` (line 31, conditional)
  4. `SELECT SUM(iode * nombre) FROM molecules` (line 37)
  5. `SELECT x, y FROM membre` (line 58)

  Plus `getCompoundBonus()` and `getSpecModifier()` each issue their own queries. The static cache prevents re-computation for the same `(joueur, niveau, detail)` tuple, but when `updateRessources` calls it with `detail=0`, all 5+ queries execute. This is not a bug but a performance concern -- the same player data is already fetched in `updateRessources` (line 210 fetches `constructions`, line 199 fetches `autre`) but is not passed through.
- **Impact:** Redundant database round trips (approximately 3-5 extra queries per `updateRessources` call). On a server with few concurrent users, this is negligible. On a busy server, it adds latency to every page load.
- **Suggested fix:** Refactor `revenuEnergie()` to accept pre-fetched data as optional parameters, similar to how `revenuAtome()` accepts `$constructions`. This would require a larger refactor and is not urgent.

---

### LOW-002: GC probability conversion uses integer arithmetic that could lose precision

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:329
- **Domain:** ECONOMY
- **Description:** The probabilistic GC trigger is:
  ```php
  if (mt_rand(1, 1000) <= (int)(COMPOUND_GC_PROBABILITY * 1000))
  ```
  With `COMPOUND_GC_PROBABILITY = 0.05`, this evaluates to `(int)(0.05 * 1000) = (int)(50.0) = 50`, so `mt_rand(1,1000) <= 50` gives a 5% probability. This works correctly for the current value. However, if `COMPOUND_GC_PROBABILITY` were set to, say, `0.001` (0.1%), the expression becomes `(int)(0.001 * 1000) = (int)(1.0) = 1`, and due to floating-point representation, `0.001 * 1000` might be `0.9999...` which truncates to 0 with `(int)`, making GC never trigger.
- **Impact:** No current impact (0.05 * 1000 = 50 exactly in float). Edge case if the config value is changed to a value that doesn't multiply cleanly to an integer.
- **Suggested fix:** Use `(int)round(COMPOUND_GC_PROBABILITY * 1000)` instead of `(int)(COMPOUND_GC_PROBABILITY * 1000)`.

---

## Summary

**0 CRITICAL, 2 HIGH, 3 MEDIUM, 2 LOW**

Key findings:
- **HIGH-001**: Donation alliance membership not re-verified inside the transaction -- a TOCTOU race allows a kicked player to still donate to their former alliance.
- **HIGH-002**: Missing `GREATEST(0, ...)` SQL guard on donation energy deduction, inconsistent with the rest of the codebase's defensive pattern.
- **MEDIUM-002**: Molecule decay operates on a pre-transaction snapshot, which can overwrite concurrent combat losses -- a correctness issue under concurrent load.
- **MEDIUM-003**: Missing null guard in `coefDisparition()` causes PHP warnings for empty molecule slots.

The overall economy code is well-structured. The CAS guard on `tempsPrecedent`, atomic SQL updates with `LEAST/GREATEST`, and proper FOR UPDATE locking demonstrate solid engineering. The `placeDepot()` formula correctly uses config constants. Comeback bonus logic is properly guarded with cooldown and LEAST-capped resource grants. The weekend catchup multiplier correctly checks season day range and day-of-week.



---

## MARKET

The delivery query at line 724 uses `WHERE receveur=?` with `$joueur` (the session login). Since MySQL latin1 collation is case-insensitive, even if `$_POST['destinataire']` stored "alice" while the login is "Alice", the delivery query would still match. So this is functionally safe, though storing the non-canonical form is messy.

Now I have a comprehensive picture. Let me compile the findings.

---

# MARKET Domain Audit -- Pass 25

## Findings

### MEDIUM-001: Transfer INSERT stores raw POST login instead of DB-canonical login

- **File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:189-190
- **Domain:** MARKET
- **Description:** The `actionsenvoi` INSERT uses `$_POST['destinataire']` for the recipient column instead of the canonicalized `$safeDestinataire` (which was fetched from DB at line 97-98). The code:
  ```php
  dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', 'ssssi',
      $_SESSION['login'], $_POST['destinataire'], $ressourcesEnvoyees, $ressourcesRecues, $tempsArrivee);
  ```
  While MySQL latin1 collation is case-insensitive so delivery queries still match, this stores user-supplied casing (e.g., "alice" instead of "Alice") in the database. This can cause display inconsistencies when the transfer list at lines 599-604 shows the stored recipient name, and could break if a future migration adds a case-sensitive collation or foreign key.
- **Impact:** Display inconsistency in transfer list; potential breakage on collation changes. LOW functional impact currently due to latin1 case-insensitivity.
- **Suggested fix:**
  Before:
  ```php
  dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', 'ssssi',
      $_SESSION['login'], $_POST['destinataire'], $ressourcesEnvoyees, $ressourcesRecues, $tempsArrivee);
  ```
  After:
  ```php
  dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', 'ssssi',
      $_SESSION['login'], $safeDestinataire, $ressourcesEnvoyees, $ressourcesRecues, $tempsArrivee);
  ```
  Note: `$safeDestinataire` must be added to the closure's `use` list (it already IS at line 108). This is correct.

### MEDIUM-002: Transfer transaction uses raw `$_POST['destinataire']` for all recipient DB queries

- **File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:122, 127, 154, 162, 178
- **Domain:** MARKET
- **Description:** Inside the `withTransaction` closure, all DB queries for the recipient use `$_POST['destinataire']` directly instead of the canonical login from `$safeDestinataire`:
  ```php
  $constructionsJoueur = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $_POST['destinataire']);  // line 122
  $ressourcesReceveur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $_POST['destinataire']);    // line 127
  ```
  While functionally safe due to latin1 case-insensitivity, this is inconsistent with the canonical login already available in `$safeDestinataire`. If the POST value contains leading/trailing whitespace beyond what `trim()` handled (e.g., null bytes, unusual Unicode), the queries could fail to match while the validation query at line 45 succeeded.
- **Impact:** Consistency and defense-in-depth. If any edge-case whitespace slips through `trim()`, the transaction queries could return NULL rows and silently skip resource locking.
- **Suggested fix:** Replace all `$_POST['destinataire']` references inside the transaction closure with `$safeDestinataire`, which is already in the `use` list.

### MEDIUM-003: Delivery-time `constructions` read lacks FOR UPDATE lock

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:793
- **Domain:** MARKET
- **Description:** When delivering a transfer at arrival time, the recipient's storage capacity is read without a `FOR UPDATE` lock:
  ```php
  $depotReceveur = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
  ```
  A concurrent building upgrade transaction could change the depot level between this read and the resource UPDATE at line 809, allowing the delivery to cap resources at a stale (lower) storage limit, silently losing some delivered resources.
- **Impact:** Resources could be silently truncated during delivery if a concurrent depot upgrade completes between the read and write. This is a data loss scenario, though the window is very small.
- **Suggested fix:**
  Before:
  ```php
  $depotReceveur = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
  ```
  After:
  ```php
  $depotReceveur = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=? FOR UPDATE', 's', $actions['receveur']);
  ```

### LOW-001: Transfer regex accepts empty string as valid amount

- **File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:88, 92
- **Domain:** MARKET
- **Description:** The regex `#^[0-9]*$#` uses `*` (zero or more), meaning an empty string passes validation:
  ```php
  if (!(preg_match("#^[0-9]*$#", $_POST[$ressource . 'Envoyee']))) {  // line 88
  ```
  This is functionally safe because empty values are converted to 0 at lines 73-74 and 80-81 via `intval()`, and the zero-sum check at line 103 catches all-zero transfers. However, the regex should use `+` for clarity and defense-in-depth.
- **Impact:** No functional impact due to upstream `intval()` and downstream zero-check. Purely a code hygiene issue.
- **Suggested fix:** Change `#^[0-9]*$#` to `#^[0-9]+$#` at lines 88 and 92. However, since empty-string-to-0 conversion happens before the regex, this would require adjusting the flow (the regex runs after `intval()` which always produces a digit string).

### LOW-002: Market chart query does not parameterize LIMIT constant

- **File:** /home/guortates/TVLW/The-Very-Little-War/marche.php:819
- **Domain:** MARKET
- **Description:** The chart data query uses string interpolation for the LIMIT:
  ```php
  $coursRows = dbFetchAll($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT " . (int)MARKET_HISTORY_LIMIT);
  ```
  While `MARKET_HISTORY_LIMIT` is a server-defined constant cast to `(int)`, best practice is to use parameterized queries. Since the value is a PHP constant (defined in config.php as `1000`), there is no injection risk.
- **Impact:** No security risk -- purely a style/consistency concern.
- **Suggested fix:** Use a prepared statement parameter: `dbFetchAll($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT ?", 'i', (int)MARKET_HISTORY_LIMIT)`

---

## Checklist Verification

| Check | Status | Notes |
|-------|--------|-------|
| Buy price > sell price (spread positive) | PASS | 5% sell tax (`MARKET_SELL_TAX_RATE = 0.95`) ensures buy cost always exceeds sell revenue |
| Global slippage uses FOR UPDATE | PASS | `cours` table locked at lines 298, 457 |
| Volatility from config | PASS | `MARKET_VOLATILITY_FACTOR` and `MARKET_GLOBAL_ECONOMY_DIVISOR` from config.php |
| Price bounds (no negative, no infinite) | PASS | Clamped to `[MARKET_PRICE_FLOOR, MARKET_PRICE_CEILING]` at lines 335, 508, and on load at line 25 |
| Storage limits respected on buy | PASS | `$newResVal > $placeDepotTx` checked inside transaction at line 315 |
| `areFlaggedAccounts()` blocks flagged transfers | PASS | Line 70 |
| Rate limit on transfers (10/60s) | PASS | Line 39 |
| Cannot transfer to self | PASS | Line 101, case-insensitive check matching login canonical form |
| Buy/sell use `withTransaction()` + FOR UPDATE | PASS | Lines 286, 291 (buy); 444, 446 (sell) |
| Chart timestamps timezone | PASS | `date_default_timezone_set('Europe/Paris')` in config.php |
| Minimum trade amount enforced | PASS | `<= 0` rejected at lines 259, 415; zero transfer rejected at line 103 |
| CSRF on all POSTs | PASS | `csrfCheck()` at lines 37, 251, 407 |
| Market tutorial hint | PASS | Lines 662-670, shown for generateur < 10 |
| Atom type index valid range | PASS | Validated by foreach match against `$nomsRes` array; invalid type produces `$numRes = -1` and `$bool = 1`, rejected at line 270/426 |
| Race-free resource check | PASS | FOR UPDATE lock on `ressources` row inside transaction |
| LEAST/GREATEST guards | PASS | Used in transfer deduction (line 197), energy deduction uses GREATEST (line 319) |
| Delivery-time storage cap | PASS | Lines 792-805 in game_actions.php cap at `maxStorageRecv` |
| Recipient validated (exists, not banned, not self) | PASS | Lines 45-46 (exists+not banned), line 101 (not self) |
| No SSRF/URL injection in chart | PASS | Chart data comes from DB `cours` table, cast to float via `sprintf('%.15g', floatval($v))` at line 830 |
| Prices server-side only | PASS | All cost calculations use server-side `$tabCours`/`$txTabCours`; client JS is display-only |

---

## Summary

**0 CRITICAL, 0 HIGH, 3 MEDIUM, 2 LOW**

The market domain is well-hardened. The transaction locking, price clamping, rate limiting, CSRF protection, storage cap enforcement, and multi-account detection are all correctly implemented. The three MEDIUM findings are consistency/defense-in-depth issues (using canonical login instead of raw POST, adding FOR UPDATE to delivery-time depot read). The two LOW findings are code hygiene items with no functional impact.



---

## BUILDINGS

So `$points['producteur']` is always `count($nomsRes)` (=8) and `$points['condenseur']` is `max(1, 5 + specMod)`. These are constants (not level-dependent), so the stale-capture concern doesn't apply here.

Now let me verify the production point allocation more carefully. In constructions.php lines 23-36, the check is `$somme > $locked['pointsProducteurRestants']`. This is correct -- it checks that the allocated points don't exceed remaining points. But there's no global cap on total production points. The total is bounded by the building level (each producteur level gives `count($nomsRes)` = 8 points). The `pointsProducteurRestants` decreases when points are allocated and increases when the building is upgraded. There's no issue here because the remaining points are the constraint.

Let me now look at a specific edge case -- what happens if `$somme` is 0 in the production point allocation?

Looking at line 26: `if ($somme > $locked['pointsProducteurRestants'])` -- if `$somme` is 0, this passes, and the code proceeds to update the points. Each resource gets `$_POST['nbPoints' . $ressource] + ($existingPoints[$num] ?? 0)`, which with all-zero POST values just re-writes existing points. This is harmless (a no-op) but wastes a DB write.

Now I have enough information for my findings. Let me compile them.

---

# BUILDINGS Domain Audit -- Pass 25

## Findings

### HIGH-001: Construction time uses stale (pre-transaction) level, not re-computed inside transaction like cost

- **File:** /home/guortates/TVLW/The-Very-Little-War/constructions.php:422
- **Domain:** BUILDINGS
- **Description:** Inside `traitementConstructions`, the building cost is carefully re-computed from the freshly locked level inside the transaction (lines 340-367), but the construction TIME on line 422 still uses `$liste['tempsConstruction']` which was computed outside the transaction from the page-load snapshot. The code is:
  ```php
  $adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')));
  ```
  Meanwhile `$liste['tempsConstruction']` was computed by `initPlayer()` before the transaction began, using the pre-transaction building level. If a concurrent request completes a queued build between the page load and the transaction lock, `$niveauActuel['niveau']` is re-fetched (line 310) and costs are recomputed, but the construction time remains based on the old (lower) level.
- **Impact:** A player with two browser tabs can trigger a race where the second build uses a shorter construction time than intended for the actual level being built. For buildings with `time_growth_base` of 1.10, a single-level difference is ~10% shorter build time. While the window is narrow, it is exploitable by determined players.
- **Suggested fix:** Re-compute the construction time inside the transaction from the locked `$niveauActuel['niveau']`, same as costs are re-computed:
  ```php
  // Before (line 422):
  $adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')));

  // After:
  if (isset($BUILDING_CONFIG[$bddKey])) {
      $bc = $BUILDING_CONFIG[$bddKey];
      $levelForTime = $niveauActuel['niveau'];
      $offset = $bc['time_level_offset'] ?? 0;
      $freshTime = round($bc['time_base'] * pow($bc['time_growth_base'], $levelForTime + $offset));
  } else {
      $freshTime = $liste['tempsConstruction'];
  }
  $adjustedConstructionTime = round($freshTime * (1 - catalystEffect('construction_speed')));
  ```

### MEDIUM-001: Combat formation validation uses hardcoded `2` instead of `MAX_FORMATION_ID`

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/combat.php:150
- **Domain:** BUILDINGS
- **Description:** The formation range check in combat.php uses a hardcoded `2`:
  ```php
  if ($defenderFormation < 0 || $defenderFormation > 2) {
  ```
  While `constructions.php:96` was already fixed to use `MAX_FORMATION_ID`, the combat resolution path still uses a hardcoded literal. If a new formation is added and `MAX_FORMATION_ID` is bumped to 3, combats would incorrectly reject formation 3 and fall back to `FORMATION_DISPERSEE`.
- **Impact:** Adding a new formation type would silently break combat for players using it. The formation would save in the DB (constructions.php allows it) but be ignored during combat resolution (combat.php rejects it). This is an inconsistency that could cause hard-to-diagnose gameplay bugs.
- **Suggested fix:**
  ```php
  // Before:
  if ($defenderFormation < 0 || $defenderFormation > 2) {

  // After:
  if ($defenderFormation < 0 || $defenderFormation > MAX_FORMATION_ID) {
  ```

### MEDIUM-002: `augmenterBatiment` uses pre-lock `$listeConstructions` points value inside transaction

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:676
- **Domain:** BUILDINGS
- **Description:** In `augmenterBatiment`, the `$listeConstructions` global is captured by the closure at line 648 from `initPlayer()` called at line 644 (before the transaction). Inside the transaction, the actual building level is re-read with FOR UPDATE (line 650), but the points awarded come from the stale `$listeConstructions[$nom]['points']` which was computed from the pre-lock level:
  ```php
  ajouterPoints($listeConstructions[$nom]['points'], $joueur);
  ```
  The points formula is `points_base + floor(niveau * points_level_factor)`. For most buildings, `points_level_factor` is 0.1, so the difference per level is often 0 due to `floor()`. However, at certain level thresholds (e.g., level 10, 20, 30...), the points increment changes, and a stale level could award incorrect points.
- **Impact:** In a concurrent scenario where two builds complete simultaneously, one could award points based on a stale level. The magnitude is small (typically 0-1 point difference) but is a correctness issue. Over many constructions, this accumulates.
- **Suggested fix:** Compute points from the locked `$batiments[$nom]` inside the transaction:
  ```php
  // Before:
  ajouterPoints($listeConstructions[$nom]['points'], $joueur);

  // After:
  $bc = $BUILDING_CONFIG[$nom] ?? null;
  $freshPoints = $bc ? ($bc['points_base'] + floor(($batiments[$nom] + 1) * $bc['points_level_factor'])) : 1;
  ajouterPoints($freshPoints, $joueur);
  ```

### MEDIUM-003: `diminuerBatiment` does not enforce level floor for non-HP buildings (lieur, stabilisateur, coffrefort, condenseur)

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:731
- **Domain:** BUILDINGS
- **Description:** The `diminuerBatiment` function checks `if ($batiments[$nom] > 1)` before proceeding (line 731), which enforces a floor of level 1 for ALL buildings. However, for buildings without HP tracking (lieur, stabilisateur, coffrefort, condenseur), these buildings are not included in combat targeting (combat.php lines 587-593), so `diminuerBatiment` would only be called for them from combat for the 5 HP-tracked buildings. This means the floor of 1 is effectively enforced. BUT: if `diminuerBatiment` were ever called for a non-combat path (admin action, future feature), it would correctly prevent going below 1. This is actually fine -- no bug here on deeper inspection.

  **Retracted** -- this is not a finding. The level floor is correctly enforced.

### LOW-001: `$safeVieCol` computed but never used for non-HP buildings in `diminuerBatiment`

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:698
- **Domain:** BUILDINGS
- **Description:** In `diminuerBatiment`, `$safeVieCol` is computed at line 698 for every building name:
  ```php
  $safeVieCol = 'vie' . ucfirst($safeCol);
  ```
  For buildings like `lieur`, `stabilisateur`, `coffrefort`, `condenseur`, this produces column names like `vieLieur` which do not exist in the database. However, these are only used inside the `if ($nom == "champdeforce" || ...)` branch (line 781), so they are never actually used for non-HP buildings. This is dead computation, not a bug.
- **Impact:** No functional impact. Minor code clarity issue.
- **Suggested fix:** Move `$safeVieCol` computation inside the conditional branch where it is used, or add a comment clarifying it is only used for HP-tracked buildings.

### LOW-002: Producteur/condenseur point allocation allows zero-sum submissions

- **File:** /home/guortates/TVLW/The-Very-Little-War/constructions.php:6-47
- **Domain:** BUILDINGS
- **Description:** The producteur point allocation form (lines 6-47) and condenseur point allocation form (lines 49-90) both accept submissions where `$somme` equals 0. When all POST values are 0, the code enters the transaction, locks the row with FOR UPDATE, reads existing points, and writes them back unchanged. This is a no-op that still acquires a row lock and performs a write.
- **Impact:** No game logic impact. A player can repeatedly submit the form with zero values to cause unnecessary DB writes and row locks. This is a negligible DoS vector given rate limiting is presumably in place for authenticated pages.
- **Suggested fix:** Add an early return before the transaction:
  ```php
  if ($somme === 0) {
      header('Location: constructions.php');
      exit();
  }
  ```

---

## Summary

**0 CRITICAL, 1 HIGH, 2 MEDIUM, 2 LOW**

The buildings domain is generally well-hardened after the many prior audit passes. The most significant finding is **HIGH-001**: the construction time is not re-computed inside the transaction (unlike the cost, which was fixed in Pass 24), creating a race condition where a player could get a shorter build time than intended. The two MEDIUM findings are a hardcoded formation range in combat.php that will break if new formations are added, and stale points computation inside a transaction closure. The LOW findings are cosmetic/minor efficiency issues.

Key positive observations:
- CSRF protection is consistently applied across all three POST actions (upgrade, producteur points, condenseur points)
- FOR UPDATE locking is used correctly in all critical paths
- Duplicate-building queue check is inside the transaction (BUILDINGS-P20-003)
- Building level cap (MAX_BUILDING_LEVEL) is enforced both in UI and in server-side logic
- Column whitelist prevents SQL injection in dynamic column names
- Cost re-computation inside transaction prevents stale-cost exploitation
- The CAS guard pattern in `updateActions` prevents double-processing of completed constructions
- Building level floor of 1 is enforced in both `diminuerBatiment` and combat.php damage handlers



---

## COMPOUNDS

Now I have a comprehensive picture of the compounds domain. Let me compile my findings.

---

## COMPOUNDS Domain Audit - Pass 25

### Analysis Summary

I reviewed all four target files thoroughly: `laboratoire.php`, `includes/compounds.php`, `includes/config.php` (compound section), and `includes/catalyst.php`. Below are the findings.

---

### HIGH-001: UNIQUE index on (login, compound_key) blocks legitimate re-synthesis of same compound type

- **File:** `/home/guortates/TVLW/The-Very-Little-War/migrations/0067_player_compounds_unique.sql:23`
- **Domain:** COMPOUNDS
- **Description:** Migration 0067 adds `UNIQUE INDEX uidx_player_compound (login, compound_key)`. This means a player cannot have two rows with the same `compound_key` -- even if one is stored (activated_at IS NULL) and another is active (activated_at IS NOT NULL). However, the `cleanupExpiredCompounds()` function only deletes expired rows (where `expires_at < time()`). Between when a compound is activated and when it expires and is garbage-collected, the player cannot synthesize another compound of the same type. The `synthesizeCompound()` INSERT at line 128 will fail with a duplicate key error:
  ```sql
  INSERT INTO player_compounds (login, compound_key, created_at) VALUES (?, ?, ?)
  ```
  The code does catch `INSERT_FAILED` at line 131 and returns an error, but the resources have already been deducted within the same transaction -- so the transaction WILL roll back (the RuntimeException triggers rollback in `withTransaction`). The data integrity is preserved, but the user experience is broken: a player who activates H2O cannot synthesize another H2O until the first one expires AND GC runs (or the probabilistic GC fires). The `countStoredCompounds` check at line 75 only counts rows with `activated_at IS NULL`, so it won't detect the conflict -- the INSERT itself will fail.

  **Worse scenario:** If GC hasn't run yet and the player tries to re-synthesize, they get a confusing "Erreur lors de la creation du compose" error message instead of something helpful like "You already have an active compound of this type."

- **Impact:** Players cannot pre-craft compounds of a type they already have active. This is a significant gameplay limitation that is not communicated to the user. The error message is misleading (generic DB error vs. informative duplicate message).
- **Suggested fix:** Either (a) change the UNIQUE index to a partial unique on stored-only compounds (not possible in MySQL/MariaDB without a generated column), or (b) catch the duplicate key error specifically and return a user-friendly message, or (c) remove the UNIQUE constraint and rely on the application-level `countStoredCompounds()` + `COMPOUND_MAX_STORED` check which already prevents unbounded duplication, or (d) add a pre-check before INSERT:
  ```php
  // Before INSERT, check if player already has a row with this compound_key
  $existing = dbFetchOne($base, 
      'SELECT id FROM player_compounds WHERE login = ? AND compound_key = ?', 
      'ss', $login, $compoundKey);
  if ($existing) {
      throw new \RuntimeException('COMPOUND_ALREADY_EXISTS');
  }
  ```
  And handle `COMPOUND_ALREADY_EXISTS` with a user-friendly message like "Vous avez deja ce compose (actif ou en stock)."

---

### HIGH-002: Catalyst discount is never applied to compound synthesis costs

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php:92`
- **Domain:** COMPOUNDS
- **Description:** The audit checklist asks whether `catalystEffect()` is applied to compound costs. After reviewing `synthesizeCompound()`, the cost calculation at line 92 is simply:
  ```php
  $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
  ```
  There is no call to `catalystEffect()` anywhere in `compounds.php`. The catalyst "Synthese" (catalyst ID 1) provides `'formation_speed' => 0.20` which affects molecule formation speed, not compound costs. However, catalyst "Fusion" (ID 3) provides `'duplicateur_discount' => 0.25` which only applies to the alliance duplicateur. There is no catalyst effect that discounts compound synthesis costs.

  While this is arguably by design (no catalyst explicitly targets compound costs), the audit checklist flags it as something to verify. The game description and player guide should clarify that catalysts do not affect compound synthesis. If a compound cost discount catalyst was intended but missing, this is a gap.

- **Impact:** Design clarity issue. If players expect a catalyst to reduce compound costs, there is none. This is a balance/feature gap rather than a bug.
- **Suggested fix:** If no catalyst discount on compounds is intended, this is a non-issue -- document it. If a discount was intended, add a new catalyst effect (e.g., `'compound_discount' => 0.15`) and apply it in `synthesizeCompound()`:
  ```php
  $discount = catalystEffect('compound_discount');
  $needed = ceil($qty * COMPOUND_ATOM_MULTIPLIER * (1 - $discount));
  ```
  **Reclassifying to MEDIUM** since the current code is consistent (no catalyst claims to discount compounds).

---

### MEDIUM-001: No inventory limit enforcement for activated compounds -- only stored ones are capped

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php:75-76`
- **Domain:** COMPOUNDS
- **Description:** `COMPOUND_MAX_STORED` (3) limits stored (inactive) compounds via `countStoredCompounds()`. However, there is no limit on total compounds (stored + active). A player could theoretically:
  1. Synthesize 3 compounds (hits stored limit)
  2. Activate all 3 (stored count drops to 0)
  3. Synthesize 3 more (stored count is 0 again)
  4. Repeat

  The UNIQUE constraint on `(login, compound_key)` partially mitigates this since there are only 5 compound types, but the combination of UNIQUE + activation means a player can have at most 5 active + 3 stored = 8 total rows. The duplicate-effect check in `activateCompound()` (line 195-200) also limits active compounds to one per effect type (and since all 5 compounds have different effect types, max 5 active).

  This is actually well-constrained by the existing checks, but the HIGH-001 UNIQUE issue means in practice a player can only have 5 total (one per type, either stored or active, not both).

- **Impact:** Low practical impact due to the 5-compound-type limit and UNIQUE constraint. The system naturally caps at 5 distinct compound rows per player.
- **Suggested fix:** No code change needed, but this interaction should be documented. The UNIQUE constraint (HIGH-001) is the actual limiting factor, not COMPOUND_MAX_STORED.

---

### MEDIUM-002: `cleanupExpiredCompounds()` does not run inside a transaction -- SELECT/DELETE race

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php:294-303`
- **Domain:** COMPOUNDS
- **Description:** The function first SELECTs distinct logins of expired compounds, then DELETEs them in a separate statement:
  ```php
  $affectedRows = dbFetchAll($base,
      'SELECT DISTINCT login FROM player_compounds WHERE activated_at IS NOT NULL AND expires_at < ?',
      'i', $threshold);
  dbExecute($base,
      'DELETE FROM player_compounds WHERE activated_at IS NOT NULL AND expires_at < ?',
      'i', $threshold);
  ```
  Between the SELECT and DELETE, a compound could expire (new rows match the DELETE that weren't in the SELECT), or a previously-expired compound's player could have their cache invalidated for a different reason. This is a minor TOCTOU: the cache invalidation might miss a player whose compound expired between the SELECT and DELETE.

  More critically, if the DELETE succeeds but the SELECT returned empty (e.g., DB hiccup), the fallback at line 314 clears the entire cache, which is safe but wasteful.

- **Impact:** Low. The worst case is a player's compound bonus cache is not invalidated for one request cycle, after which the next `getCompoundBonus()` call will re-query the DB and get correct results. The global cache is per-request only.
- **Suggested fix:** Wrap in a transaction or simply rely on the DELETE alone (skip the SELECT, always invalidate full cache since GC runs rarely at 5% probability):
  ```php
  function cleanupExpiredCompounds($base) {
      $deleted = dbExecute($base,
          'DELETE FROM player_compounds WHERE activated_at IS NOT NULL AND expires_at < ?',
          'i', time());
      if ($deleted > 0) {
          invalidateCompoundBonusCache(); // full clear is fine for rare GC
      }
  }
  ```

---

### MEDIUM-003: NaCl recipe uses chlore + soufre -- chemically incorrect (Na is not in the game)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/config.php:821-828`
- **Domain:** COMPOUNDS
- **Description:** The compound "NaCl" (table salt) is defined with recipe `['chlore' => 1, 'soufre' => 1]`. Sodium (Na) is not one of the 8 atom types in the game (C, N, H, O, Cl, S, Br, I), so the recipe substitutes soufre (sulfur) for sodium. While this is a gameplay abstraction, naming the compound "NaCl" while using sulfur instead of sodium could confuse chemistry-literate players. The compound should either be renamed to something that makes sense with Cl+S (e.g., "SCl2" -- sulfur dichloride) or acknowledged as a creative liberty.

- **Impact:** Cosmetic/thematic inconsistency only. No gameplay bug.
- **Suggested fix:** Either rename to a sulfur-chlorine compound or add a tooltip/note explaining the creative liberty. This is a LOW-priority design choice.

---

### LOW-001: `affichageTemps()` output in active compounds table is not HTML-escaped

- **File:** `/home/guortates/TVLW/The-Very-Little-War/laboratoire.php:80`
- **Domain:** COMPOUNDS
- **Description:** The line:
  ```php
  echo '<td>' . affichageTemps($remaining) . '</td>';
  ```
  The `affichageTemps()` function returns a formatted time string. If this function ever returns HTML-unsafe characters, it would be an XSS vector. However, examining typical time formatting functions, they only output digits and time-related words (e.g., "1h 30min"), so this is extremely low risk. The same pattern appears at line 159 for duration display.

- **Impact:** Negligible -- `affichageTemps()` only produces safe numeric/text output. Defense-in-depth suggests wrapping with `htmlspecialchars()`.
- **Suggested fix:**
  ```php
  echo '<td>' . htmlspecialchars(affichageTemps($remaining), ENT_QUOTES, 'UTF-8') . '</td>';
  ```

---

### LOW-002: Compound display cost in lab UI does not account for any potential future catalyst discount

- **File:** `/home/guortates/TVLW/The-Very-Little-War/laboratoire.php:142`
- **Domain:** COMPOUNDS
- **Description:** The display cost is calculated as:
  ```php
  $needed = $qty * COMPOUND_ATOM_MULTIPLIER;
  ```
  This matches the actual synthesis cost in `compounds.php:92`. If a catalyst discount were ever added to the synthesis logic but not to the display logic, there would be a drift between displayed cost and actual cost. Currently there is no drift because neither applies a catalyst discount.

- **Impact:** No current impact. Future-proofing concern only.
- **Suggested fix:** Extract cost calculation into a shared helper function used by both display and synthesis logic.

---

### Verification of Checklist Items (No Issues Found)

1. **Synthesis requirements:** All 5 compounds correctly reference atom names from `$nomsRes` (carbone, azote, hydrogene, oxygene, chlore, soufre). Verified against `$RESOURCE_NAMES` in config.php line 93. CLEAN.

2. **Atom cost deduction:** Uses `withTransaction()` with `FOR UPDATE` on ressources row (line 81). Resources checked and deducted inside the transaction. CLEAN.

3. **Synthesis rate limit:** `rateLimitCheck($_SESSION['login'], 'lab_synthesis', 5, 60)` at laboratoire.php:11. CLEAN.

4. **Buff duration:** All 5 compounds use `SECONDS_PER_HOUR` constant. CLEAN.

5. **Buff activation:** Duplicate effect check at compounds.php:195-200 prevents two compounds with the same `effect` type from being active simultaneously. CLEAN.

6. **Compound cache:** Uses global `$_compoundBonusCache` (not static) at line 232. `invalidateCompoundBonusCache()` called after activation at line 226. CLEAN.

7. **cleanupExpiredCompounds():** Called on lab page load (probabilistic) and in `game_resources.php` (also probabilistic). Removes expired rows. CLEAN.

8. **CSRF:** Both synthesis (line 8) and activation (line 26) call `csrfCheck()`. Both forms include `csrfField()`. CLEAN.

9. **htmlspecialchars:** All user-visible fields (compound key, name, description) escaped with `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`. CLEAN.

10. **Cost display matches synthesis:** Both use `$qty * COMPOUND_ATOM_MULTIPLIER`. CLEAN.

11. **Resource verification inside transaction:** Yes, resources are checked inside `withTransaction()` after `FOR UPDATE` lock (line 81-96). CLEAN.

12. **Type index validation:** `isset($COMPOUNDS[$compoundKey])` check at line 64 validates compound key. CLEAN.

13. **getCompoundBonus() with empty table:** Returns `$totalBonus = 0.0` (initialized at line 267) when no active compounds exist. CLEAN.

14. **Activation stacking:** Duplicate effect check prevents stacking. `activated_at IS NULL` in both SELECT and UPDATE prevents double-activation. CLEAN.

15. **Separate CSRF for synthesis and activation:** Both POST handlers have individual `csrfCheck()` calls. CLEAN.

---

## Summary

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | 0 | -- |
| HIGH | 1 | HIGH-001 |
| MEDIUM | 3 | MEDIUM-001, MEDIUM-002, MEDIUM-003 |
| LOW | 2 | LOW-001, LOW-002 |

**Total: 0 CRITICAL, 1 HIGH, 3 MEDIUM, 2 LOW**

The most significant finding is **HIGH-001**: the UNIQUE index on `(login, compound_key)` prevents a player from pre-crafting a compound of a type they already have active, and produces a misleading error message when they try. This is a gameplay-impacting issue that should be addressed either by removing the UNIQUE constraint (relying on application-level guards instead) or by adding a pre-check with a clear error message.

The compound system is otherwise well-implemented with proper transaction handling, FOR UPDATE locking, CSRF protection, rate limiting, cache invalidation, and XSS prevention. The code quality is high and follows the established patterns of the codebase.



---

## MAPS

Now I have a comprehensive understanding of the MAPS domain. Let me compile the audit findings.

---

# MAPS Domain Audit -- Pass 25

## Findings

### MEDIUM-001: Map displays banned players (estExclu) without filtering

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:534
- **Domain:** MAPS
- **Description:** The map query fetches all players with positive coordinates but does not filter out banned players (`estExclu=1`). The query is:
  ```sql
  SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login WHERE m.x > 0 AND m.y > 0
  ```
  Banned players remain visible on the map, occupying tiles and appearing as clickable targets. While the espionage handler at line 45 does check `estExclu`, the attack handler at lines 174-176 only checks if the player exists (`if (!$enVac)`) without verifying ban status. A banned player would pass this check and potentially reach the combat flow.
- **Impact:** Banned players clutter the map. Attempting to attack a banned player wastes the attacker's time and could reach unexpected code paths. Information leak: other players can see that a banned account exists and where it was located.
- **Suggested fix:**
  ```php
  // Before (line 534):
  $allPlayers = dbFetchAll($base, 'SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login WHERE m.x > 0 AND m.y > 0', '');
  
  // After:
  $allPlayers = dbFetchAll($base, 'SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login WHERE m.x > 0 AND m.y > 0 AND m.estExclu = 0', '');
  ```
  Also add an `estExclu` check in the attack handler near line 174:
  ```php
  $enVac = dbFetchOne($base, 'SELECT vacance,timestamp,estExclu FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
  if (!$enVac || $enVac['estExclu'] == 1) {
      $erreur = "Ce joueur n'existe pas.";
  ```

---

### MEDIUM-002: Node generation boundary mismatch -- nodes only cover MAP_INITIAL_SIZE but map grows to MAP_SIZE

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/resource_nodes.php:28-29, /home/guortates/TVLW/The-Very-Little-War/includes/player.php:1330
- **Domain:** MAPS
- **Description:** `generateResourceNodes()` is called at season reset with `$nodeMapSize = MAP_INITIAL_SIZE` (20). All nodes are placed in the 20x20 starting area. As the season progresses and `tailleCarte` grows toward MAP_SIZE (200), players placed beyond the 20x20 area receive no proximity bonus from any resource node. The game has no mechanism to regenerate or add nodes mid-season.
- **Impact:** Players who register later in a season and are placed at coordinates beyond 20x20 are permanently disadvantaged -- they receive 0% resource node bonus while early registrants near the center can stack up to 50%. This creates an unfair advantage based purely on registration timing.
- **Suggested fix:** Either (a) generate nodes across the full MAP_SIZE range at season start so they are pre-distributed for map growth, or (b) implement a periodic node regeneration (e.g., via cron or on tailleCarte expansion milestones) that adds nodes in newly opened map areas:
  ```php
  // In player.php line 1330, use MAP_SIZE instead of MAP_INITIAL_SIZE:
  $nodeMapSize = MAP_SIZE;
  ```
  Note: this would spread 15-25 nodes across 200x200 = 40,000 tiles, making them very sparse. A better approach may be to scale RESOURCE_NODE_MIN_COUNT/MAX_COUNT proportionally or regenerate periodically.

---

### MEDIUM-003: `coordonneesAleatoires()` only places players on map edges, leaving interior empty

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:856-890
- **Domain:** MAPS
- **Description:** The placement algorithm always sets either `$y = tailleCarte - 1` (horizontal edge) or `$x = tailleCarte - 1` (vertical edge), meaning new players are always placed on the outermost row or column. As the map expands, interior tiles remain permanently empty. This is an intentional design choice from the original code, but it creates a structural interaction with resource nodes: nodes placed in the map interior (coordinates 1 through tailleCarte-2) will never have any player within proximity radius.
- **Impact:** Interior resource nodes are wasted -- they contribute no bonus to any player. Combined with MEDIUM-002, this means the effective value of resource nodes is lower than designed.
- **Suggested fix:** This is a design-level consideration. If the edge-only placement is intentional, resource node generation should be adjusted to only place nodes along map edges (within NODE_PROXIMITY_RADIUS of the edge). Alternatively, placement could use a spiral or random interior fill.

---

### LOW-001: `$_GET['type']` used without integer cast -- loose comparison with 0

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:502-506
- **Domain:** MAPS
- **Description:** The code checks `$_GET['type'] == 0` using loose comparison. Since `$_GET['type']` is a string, `"0" == 0` is true (correct), but any non-numeric string like `"abc"` also evaluates as `== 0` in PHP's loose comparison (in PHP 7; in PHP 8 this was fixed). The code does set a default at line 503: `$_GET['type'] = 0` when not set, but the subsequent comparisons at lines 506, 652, 778 all use loose `==`. On PHP 8.2 (the target platform), string-to-int comparison was tightened so `"abc" == 0` is false. This is not a bug on the current platform but is fragile.
- **Impact:** On PHP 8.2 this works correctly. On older PHP versions it would cause the map view to render for any garbage type parameter. Low practical risk given the target platform.
- **Suggested fix:** Cast to int for clarity:
  ```php
  $type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
  // Then use $type throughout instead of $_GET['type']
  ```

---

### LOW-002: Map link coordinates not integer-cast in href attributes

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:669, 785
- **Domain:** MAPS
- **Description:** Player coordinates from the database (`$joueur['x']` and `$joueur['y']`) are injected into `href` attributes without explicit integer cast:
  ```php
  echo chip('<a href="attaquer.php?x=' . $joueur['x'] . '&y=' . $joueur['y'] . '">...
  ```
  These values come from `SELECT * FROM membre WHERE login=?` (line 648). While the DB schema enforces integer types, and the scroll handler at line 523-524 does cast via `(int)$_GET['x']`, the output path should also cast to be defense-in-depth.
- **Impact:** Minimal in practice since DB column types enforce integers, and the receiving side casts. This is a defense-in-depth concern.
- **Suggested fix:**
  ```php
  echo chip('<a href="attaquer.php?x=' . (int)$joueur['x'] . '&y=' . (int)$joueur['y'] . '">...
  ```

---

### LOW-003: War declarations filter uses `fin=0` but pact filter uses `valide!=0` -- inconsistent status semantics

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:540, 545
- **Domain:** MAPS
- **Description:** War declarations are filtered with `fin=0` (active wars), while pacts use `valide!=0`. These are the correct filters per the declarations table schema, but the code on the map (lines 540, 545) uses different column names than the same checks in the attack/espionage handlers (lines 71, 194). The attack handler checks pacts with `valide!=0` (matching), and wars are not re-checked in the attack handler (only alliance membership and pacts are). This is consistent but worth noting: the map shows war indicators based on `declarations.fin=0`, which correctly identifies ongoing wars.
- **Impact:** No functional bug. The filters are correct for their respective declaration types.
- **Suggested fix:** No change needed -- documenting for completeness.

---

### INFO-001: `coordonneesAleatoires()` uniqueness guarantee

The function uses `SELECT ... FOR UPDATE` on the statistiques row to serialize concurrent calls, builds a full occupancy grid from all existing player positions, and only selects unoccupied cells. The outer retry loop (10 attempts) handles the unlikely case of a duplicate key error. The `(0,0)` sentinel is excluded by the `max(1, $x)` / `max(1, $y)` guards. This provides a strong uniqueness guarantee.

### INFO-002: Distance formula consistency

All distance calculations in the codebase use Euclidean distance (`sqrt(dx^2 + dy^2)` or equivalently `pow(dx^2 + dy^2, 0.5)`):
- Espionage travel (line 98): `pow(pow(dx,2) + pow(dy,2), 0.5)`
- Attack travel (line 279): same formula
- Attack display (line 662): same formula
- Resource node proximity (resource_nodes.php:152): `sqrt(pow(dx,2) + pow(dy,2))`
- Node generation min-distance (resource_nodes.php:52): same formula

All are consistent. Travel time uses `distance / speed * SECONDS_PER_HOUR`, which is correct.

### INFO-003: XSS review -- all output paths properly escaped

- Map player names: `$safeMapLogin = htmlspecialchars(...)` at line 615
- Resource node types: `$safeType = htmlspecialchars(ucfirst(...))` at line 630
- Node bonus_pct and radius: cast with `(int)` at line 633
- Attack target: `htmlspecialchars(...)` at line 689
- Espionage target: `htmlspecialchars(...)` at line 797
- CSP nonce: `htmlspecialchars(cspNonce(), ...)` at line 637

### INFO-004: Map accessibility

The map view (type=0) at line 506 is gated only by `basicprivatephp.php` (login required) -- it is accessible to all logged-in players regardless of alliance membership. This is correct.

### INFO-005: Season reset node generation

`generateResourceNodes()` is called at line 1331 within `performSeasonEnd()`, after `remiseAZero()` but before the maintenance flag is cleared. Cache invalidation via `clearResourceNodeCache()` is called at line 89. This is correct.

---

## Summary

**0 CRITICAL, 0 HIGH, 3 MEDIUM, 3 LOW**

| ID | Severity | Title |
|----|----------|-------|
| MEDIUM-001 | MEDIUM | Map displays banned players without filtering; attack handler missing estExclu check |
| MEDIUM-002 | MEDIUM | Resource nodes only cover 20x20 starting area; late-season players get no bonus |
| MEDIUM-003 | MEDIUM | Edge-only player placement means interior nodes are unreachable |
| LOW-001 | LOW | `$_GET['type']` loose comparison (safe on PHP 8.2 but fragile) |
| LOW-002 | LOW | Map link coordinates not integer-cast in href output |
| LOW-003 | LOW | War/pact filter column difference is correct but noted for documentation |

The MAPS domain is in good shape overall. The most actionable finding is MEDIUM-001 (banned player filtering), which is a straightforward fix. MEDIUM-002 and MEDIUM-003 are design-level considerations about how resource nodes interact with the map expansion and player placement algorithms -- they affect game balance rather than correctness or security.



---

## SOCIAL

Now I have a comprehensive picture. Let me compile the audit findings.

---

# SOCIAL Domain Audit -- Pass 25

## Findings

### HIGH-001: messageCommun.php broadcasts to banned players

- **File:** /home/guortates/TVLW/The-Very-Little-War/messageCommun.php:50
- **Domain:** SOCIAL
- **Description:** The admin broadcast query on line 50 fetches all members without filtering out banned accounts:
  ```php
  $membres = dbFetchAll($base, 'SELECT login FROM membre');
  ```
  By contrast, the `[all]` broadcast path in `ecriremessage.php:80` correctly uses `WHERE m.estExclu = 0`. This inconsistency means messageCommun.php will insert messages for banned players, wasting storage and creating rows that can never be read.
- **Impact:** Database bloat with unreachable messages for banned players. If a banned player is later unbanned, they receive stale admin broadcasts they should not have received.
- **Suggested fix:** Change line 50 from:
  ```php
  $membres = dbFetchAll($base, 'SELECT login FROM membre');
  ```
  to:
  ```php
  $membres = dbFetchAll($base, 'SELECT login FROM membre WHERE estExclu = 0');
  ```

### HIGH-002: Private message inbox cap not protected by FOR UPDATE (TOCTOU race)

- **File:** /home/guortates/TVLW/The-Very-Little-War/ecriremessage.php:116
- **Domain:** SOCIAL
- **Description:** The private (1:1) message path checks the inbox count without `FOR UPDATE` and outside any transaction:
  ```php
  $inboxCount = dbCount($base, 'SELECT COUNT(*) FROM messages WHERE destinataire=? AND deleted_by_recipient=0', 's', $canonicalLogin);
  if ($inboxCount >= INBOX_MAX_MESSAGES) {
  ```
  The broadcast paths (alliance on line 52 and global on line 88) correctly use `FOR UPDATE` inside a transaction. Two concurrent private messages to the same recipient can both read the count as below the cap and both insert, exceeding `INBOX_MAX_MESSAGES`.
- **Impact:** Inbox cap bypass allowing a recipient to accumulate more messages than the configured limit. With scripted sending, this could be used for targeted storage abuse.
- **Suggested fix:** Wrap the inbox count check and INSERT in a `withTransaction()` block and add `FOR UPDATE` to the count query, matching the pattern already used in the broadcast path.

### HIGH-003: messageCommun.php inbox count check lacks FOR UPDATE (TOCTOU race)

- **File:** /home/guortates/TVLW/The-Very-Little-War/messageCommun.php:58
- **Domain:** SOCIAL
- **Description:** Inside the transaction, the inbox count query does not use `FOR UPDATE`:
  ```php
  $inboxCount = dbFetchOne($base, 'SELECT COUNT(*) AS nb FROM messages WHERE destinataire=? AND deleted_by_recipient=0', 's', $d['login']);
  ```
  Compare with `ecriremessage.php:52` which correctly uses `FOR UPDATE`. A concurrent message insertion between the COUNT and the INSERT could exceed the cap.
- **Impact:** Inbox cap bypass during concurrent admin broadcast and private messaging.
- **Suggested fix:** Add `FOR UPDATE` to the query:
  ```php
  $inboxCount = dbFetchOne($base, 'SELECT COUNT(*) AS nb FROM messages WHERE destinataire=? AND deleted_by_recipient=0 FOR UPDATE', 's', $d['login']);
  ```

### MEDIUM-001: messages.php accepts `information` query parameter but never displays it (dead redirect target)

- **File:** /home/guortates/TVLW/The-Very-Little-War/messageCommun.php:73
- **Domain:** SOCIAL
- **Description:** `messageCommun.php:73` redirects to `messages.php?information=...`:
  ```php
  header('Location: messages.php?information=' . urlencode($info));
  ```
  However, `messages.php` never reads `$_GET['information']`. The admin broadcast success message is silently discarded. This is not a security issue but a functional bug -- the admin never sees confirmation.
- **Impact:** Admin gets no feedback that the broadcast was sent successfully.
- **Suggested fix:** Either use the flash message pattern already established (set `$_SESSION['flash_message']` before redirect, as done in `ecriremessage.php:68/94`), or add a display block in `messages.php` that reads and escapes `$_GET['information']`.

### MEDIUM-002: messageCommun.php error redirect passes unsanitized query parameter

- **File:** /home/guortates/TVLW/The-Very-Little-War/messageCommun.php:38
- **Domain:** SOCIAL
- **Description:** The error redirect uses a query parameter:
  ```php
  header('Location: messageCommun.php?erreur=' . urlencode('Titre et message requis.'));
  ```
  But when `messageCommun.php` is loaded via GET, it never reads or displays `$_GET['erreur']`. The error message is lost. The error values here are hardcoded strings (not user input), so there is no XSS risk, but the error feedback loop is broken.
- **Impact:** Admin gets no error feedback when title or message is empty; the form just silently reloads.
- **Suggested fix:** Either add error display logic that reads `$_GET['erreur']` with `htmlspecialchars()`, or use the session flash pattern.

### MEDIUM-003: joueur.php leaks player existence to unauthenticated users via timing/response difference

- **File:** /home/guortates/TVLW/The-Very-Little-War/joueur.php:25-36
- **Domain:** SOCIAL
- **Description:** When an unauthenticated user visits `joueur.php?id=X`, they can distinguish between existing players (who get a full profile card with name, rank, alliance, position placeholder, points, victories, last seen) and non-existing players (who get "Ce joueur n'existe pas!"). While the rate limiter (60 requests/minute) mitigates bulk enumeration, authenticated details like description are already gated behind `$_SESSION['login']`, so the remaining public information (rank, points, victories, last seen indicator) could be considered acceptable for a game. However, the banned player check on line 30-32 returns the same "not found" message, which is good.
- **Impact:** Low-severity player enumeration. The rate limit at 60/minute already mitigates this adequately.
- **Suggested fix:** No action strictly required given the existing rate limit. If desired, reduce the rate limit for unauthenticated profile views (e.g., 20/minute instead of 60).

### LOW-001: connectes.php dead `if (true)` branch

- **File:** /home/guortates/TVLW/The-Very-Little-War/connectes.php:30
- **Domain:** SOCIAL
- **Description:** Line 30 contains a vestigial conditional:
  ```php
  if (true) { // Admin already excluded from query
  ```
  This is dead code from a previous refactor where admin exclusion was done in PHP rather than SQL. The condition is always true and adds an unnecessary indentation level.
- **Impact:** No functional impact; minor code smell reducing readability.
- **Suggested fix:** Remove the `if (true) {` wrapper and its closing brace, keeping the body at the same indentation level as the foreach.

### LOW-002: messageCommun.php title length not validated

- **File:** /home/guortates/TVLW/The-Very-Little-War/messageCommun.php:34
- **Domain:** SOCIAL
- **Description:** The message body length is validated against `MESSAGE_MAX_LENGTH` (line 43), but the title (`$titre`) has no length check. In `ecriremessage.php:15`, title length is validated at 200 characters. An admin could submit an extremely long title.
- **Impact:** Minimal since only admins can access this page, but inconsistent with the validation in `ecriremessage.php`. Could cause display issues with very long titles.
- **Suggested fix:** Add title length validation after line 37:
  ```php
  if (mb_strlen($titre) > 200) {
      header('Location: messageCommun.php?erreur=' . urlencode('Titre trop long.'));
      exit();
  }
  ```

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0     |
| HIGH     | 3     |
| MEDIUM   | 3     |
| LOW      | 2     |

**0 CRITICAL, 3 HIGH, 3 MEDIUM, 2 LOW**

**Key observations on what is already well-implemented:**
- CSRF protection is present on all POST forms across all files
- BBCode output goes through `sanitizeReportHtml()` in both `messages.php:41` and `joueur.php:127`
- `htmlspecialchars()` is consistently applied to all user-controlled output (logins, titles, descriptions, timestamps)
- Rate limiting is in place for private messages (10/300s), alliance broadcasts (3/300s), global broadcasts (2/3600s), and profile views (60/60s)
- Global broadcast is properly gated to `ADMIN_LOGIN`
- Canonical login resolution prevents case-spoofing on private messages
- Self-message prevention is implemented
- Recipient validation checks existence and banned status
- Soft-delete with cascade cleanup is correctly implemented
- Read-marking is properly gated to the actual recipient
- Pagination uses `intval()` for page numbers and prepared statement bind parameters for LIMIT/OFFSET
- `messagesenvoyes.php` correctly filters to only the logged-in user's sent messages
- `messageCommun.php` is properly admin-gated
- No email or IP information is disclosed on public profiles
- `connectes.php` properly escapes login names and excludes banned players



---

## ALLIANCE_MGMT

I see that the war-end and pact-break transactions do NOT re-verify the actor's grade permissions inside the transaction. The `$guerre` and `$pacte` booleans were set at the top of the file (lines 29-44) and are trusted for the entire page. If a grade is revoked concurrently, the actor could still end a war or break a pact. This is the same TOCTOU pattern as the description change.

Similarly, the ban/kick flow DOES re-verify permissions inside the transaction (lines 340-349), but the war-end and pact-break flows do NOT. Let me compare:
- Ban: re-verifies inside TX -- GOOD
- War-end: no re-verification inside TX -- MEDIUM
- Pact-break: no re-verification inside TX -- MEDIUM  
- Description change: no re-verification inside TX -- LOW
- War declaration: no re-verification inside TX -- MEDIUM

These are all the same pattern. The outer check at the top of the file (line 25-44) sets permission flags, but the transaction bodies for war/pact operations don't re-verify. The ban flow was specifically hardened in a previous pass.

Let me finalize my audit findings.

---

# Alliance Management Domain Audit -- Pass 25

## Findings

### CRITICAL-001: Non-existent column `nbMax` in invitation acceptance query causes SQL error

- **File:** /home/guortates/TVLW/The-Very-Little-War/alliance.php:248
- **Domain:** ALLIANCE_MGMT
- **Description:** The invitation acceptance transaction fetches a column `nbMax` that does not exist on the `alliances` table. The original schema (and all migrations) show no `nbMax` column. The query `SELECT id, nbMax FROM alliances WHERE id=? FOR UPDATE` will produce a MySQL error, preventing any player from accepting alliance invitations.

```php
$allianceLocked = dbFetchOne($base, 'SELECT id, nbMax FROM alliances WHERE id=? FOR UPDATE', 'i', $idalliance['idalliance']);
```

Additionally, the fetched `nbMax` value is never used -- the member count is compared against the global `$joueursEquipe` constant (line 254), not `$allianceLocked['nbMax']`.

- **Impact:** All alliance invitation acceptances fail with a SQL error. Players cannot join any alliance via invitation. This is a complete functional breakage of the alliance join flow.
- **Suggested fix:**

Before:
```php
$allianceLocked = dbFetchOne($base, 'SELECT id, nbMax FROM alliances WHERE id=? FOR UPDATE', 'i', $idalliance['idalliance']);
```

After:
```php
$allianceLocked = dbFetchOne($base, 'SELECT id FROM alliances WHERE id=? FOR UPDATE', 'i', $idalliance['idalliance']);
```

---

### HIGH-001: War-end transaction does not re-verify actor's `guerre` grade permission (TOCTOU)

- **File:** /home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:560-606
- **Domain:** ALLIANCE_MGMT
- **Description:** The war-end flow is gated by the `$guerre` boolean set once at the top of the file (line 29-44). Inside the `withTransaction` block (lines 568-602), the actor's grade is never re-read under `FOR UPDATE`. If a chef revokes an officer's `guerre` grade between the initial page-load permission check and the transaction execution, the officer can still end a war.

The ban/kick flow was correctly hardened to re-verify permissions inside its transaction (lines 340-349), but the war-end flow was not given the same treatment.

- **Impact:** An officer whose war permission was just revoked can still end an active war, potentially awarding victory points incorrectly or ending a war the alliance leadership wants to continue.
- **Suggested fix:** Inside the `withTransaction` closure, re-read the actor's grade with `FOR UPDATE` and verify the `guerre` bit (index 1) before proceeding, following the same pattern used in the ban flow:

```php
// Re-verify actor's guerre permission inside transaction
$actorGrade = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $_SESSION['login'], $chef['id']);
$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $chef['id']);
$isChef = ($allianceLocked && $allianceLocked['chef'] === $_SESSION['login']);
if (!$isChef) {
    $bits = explode('.', $actorGrade['grade'] ?? '');
    if (count($bits) !== 5 || $bits[1] !== '1') {
        throw new \RuntimeException('PERMISSION_DENIED');
    }
}
```

---

### HIGH-002: Pact-break transaction does not re-verify actor's `pacte` grade permission (TOCTOU)

- **File:** /home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:452-482
- **Domain:** ALLIANCE_MGMT
- **Description:** Same pattern as HIGH-001. The pact-break flow is gated by the `$pacte` boolean from the initial page load, but the `withTransaction` block on lines 459-476 never re-verifies the actor's pact permission under a lock. A concurrently revoked officer can still break a pact.

- **Impact:** An officer whose pact permission was just revoked can break an existing pact, potentially disrupting diplomatic relationships the alliance leadership wants to maintain.
- **Suggested fix:** Same pattern as HIGH-001 -- re-read the actor's grade with `FOR UPDATE` inside the transaction and verify bit index 2 (`pacte`).

---

### HIGH-003: Pact proposal transaction does not re-verify actor's `pacte` grade permission (TOCTOU)

- **File:** /home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:424-445
- **Domain:** ALLIANCE_MGMT
- **Description:** The pact proposal flow inside `withTransaction` (lines 425-441) does not re-verify the actor's pact permission. Same TOCTOU vulnerability as HIGH-001 and HIGH-002.

- **Impact:** An officer whose pact permission was just revoked can still send pact proposals on behalf of the alliance.
- **Suggested fix:** Re-read the actor's grade with `FOR UPDATE` inside the transaction and verify the `pacte` bit (index 2) before inserting.

---

### HIGH-004: War declaration transaction does not re-verify actor's `guerre` grade permission (TOCTOU)

- **File:** /home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:511-548
- **Domain:** ALLIANCE_MGMT
- **Description:** The war declaration transaction does not re-verify the actor's `guerre` grade permission inside the transaction, despite performing complex operations (deleting pending pacts, inserting war declaration, sending reports). Same TOCTOU pattern.

- **Impact:** An officer whose war permission was just revoked can declare war, which also deletes any pending pact proposals between the alliances.
- **Suggested fix:** Re-read the actor's grade with `FOR UPDATE` inside the transaction and verify bit index 1 (`guerre`) before proceeding.

---

### MEDIUM-001: `supprimerAlliance()` deletes alliance row before updating member rows

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:989-991
- **Domain:** ALLIANCE_MGMT
- **Description:** In `supprimerAlliance()`, the alliance row is deleted (line 989) BEFORE the `autre` rows are updated to set `idalliance=0` (line 991). While this is inside a transaction so it's atomically safe, the order means that if a concurrent read happens within the transaction isolation level, members briefly reference a deleted alliance ID. More practically, the `UPDATE autre SET idalliance=0, alliance_left_at=UNIX_TIMESTAMP() WHERE idalliance=?` on line 991 uses `idalliance=?` as its WHERE clause. Since the `alliances` row was already deleted, there's no FK issue (no FKs exist), but the UPDATE works correctly because it references the `autre` table's own `idalliance` column, not a JOIN.

However, the `energieDonnee` reset on line 988 also uses `WHERE idalliance=?` which is fine. The real concern is that if a new alliance is created with the same auto-increment ID between delete and update (extremely unlikely but theoretically possible within a transaction gap), the wrong members could be affected.

- **Impact:** Low practical risk due to transaction wrapping, but the ordering is semantically incorrect. Best practice is to update dependent rows first, then delete the parent.
- **Suggested fix:** Move `DELETE FROM alliances WHERE id=?` (line 989) to AFTER the `UPDATE autre` (line 991) and all other cleanup queries.

---

### MEDIUM-002: Alliance creation duplicate check lacks `FOR UPDATE` lock

- **File:** /home/guortates/TVLW/The-Very-Little-War/alliance.php:45
- **Domain:** ALLIANCE_MGMT
- **Description:** The alliance creation duplicate check uses a plain `SELECT` without `FOR UPDATE`:

```php
$allianceCheckRows = dbFetchAll($base, 'SELECT nom FROM alliances WHERE tag=? OR nom=?', 'ss', $_POST['tagalliance'], $_POST['nomalliance']);
```

While the UNIQUE indexes on `tag` and `nom` (migration 0030) prevent actual duplicates at the DB level, a concurrent race would cause a `mysqli_sql_exception` on the INSERT, not a `RuntimeException`. The catch block on line 58 only catches `\RuntimeException`, so a duplicate key MySQL error would propagate uncaught, potentially showing a raw error to the user.

- **Impact:** Under concurrent alliance creation attempts with the same tag/name, the user could see an unhandled MySQL exception instead of the friendly "Ce nom existe deja" message.
- **Suggested fix:** Either add `FOR UPDATE` to the SELECT, or broaden the catch to also handle `\mysqli_sql_exception`:

```php
} catch (\RuntimeException $e) {
    $erreur = "Une equipe avec ce nom ou ce tag existe deja.";
} catch (\mysqli_sql_exception $e) {
    $erreur = "Une equipe avec ce nom ou ce tag existe deja.";
}
```

---

### MEDIUM-003: Description change not re-verified inside transaction (TOCTOU on permission)

- **File:** /home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:288-303
- **Domain:** ALLIANCE_MGMT
- **Description:** The description change is gated by the `$description` boolean from the initial permission check, but the UPDATE on line 297 is a bare `dbExecute` with no transaction wrapping and no re-verification of the actor's grade. If the officer's grade is revoked between the page load and the POST submission, they can still change the description.

Unlike the ban flow which was hardened with in-transaction re-verification, this flow has no such protection.

- **Impact:** A recently de-graded officer could change the alliance description without authorization. Impact is limited to text content.
- **Suggested fix:** Wrap in a transaction that re-reads the actor's grade under `FOR UPDATE` and verifies bit index 4 (`description`), or at minimum verifies the actor is still in the alliance with a grade.

---

### LOW-001: Loose `==` comparison for chef check in admin link display

- **File:** /home/guortates/TVLW/The-Very-Little-War/alliance.php:401
- **Domain:** ALLIANCE_MGMT
- **Description:** The admin link visibility check uses loose `==`:

```php
if ($freshAlliance['chef'] == $_SESSION['login'] or $grade > 0) {
```

While this is not the grade "0" truthy bug (it compares string login values), using loose `==` here is inconsistent with the strict `===` used elsewhere in the file (lines 110, 150) and could theoretically cause unexpected matches if login values look like numeric strings.

- **Impact:** Very low. Login values are typically alphabetic strings where `==` and `===` behave identically. This is a code quality / consistency issue.
- **Suggested fix:**

Before:
```php
if ($freshAlliance['chef'] == $_SESSION['login'] or $grade > 0) {
```

After:
```php
if ($freshAlliance['chef'] === $_SESSION['login'] or $grade > 0) {
```

---

### LOW-002: Loose `==` comparison for chef check in leave validation

- **File:** /home/guortates/TVLW/The-Very-Little-War/alliance.php:78
- **Domain:** ALLIANCE_MGMT
- **Description:** The chef check when leaving uses loose `==`:

```php
if ($allianceCheck && $allianceCheck['chef'] == $_SESSION['login']) {
```

Same class of issue as LOW-001.

- **Impact:** Very low practical risk but inconsistent with the strict comparisons used elsewhere.
- **Suggested fix:** Change `==` to `===`.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 4 |
| MEDIUM | 3 |
| LOW | 2 |

**1 CRITICAL, 4 HIGH, 3 MEDIUM, 2 LOW**

The most urgent fix is **CRITICAL-001**: the `nbMax` column reference in alliance.php line 248 will cause a SQL error on every invitation acceptance, completely blocking the alliance join flow. The HIGH findings all follow the same pattern -- the ban/kick flow was properly hardened with in-transaction permission re-verification, but the war/pact operations were not given the same treatment, leaving TOCTOU windows where revoked officers can still perform diplomatic actions.



---

## GAME_CORE

Now I have all the data I need. Let me compile the findings.

---

# GAME_CORE Domain Audit -- Pass 25

## Findings

### [MEDIUM]-001: No vacation re-activation cooldown after deactivation
- **File:** /home/guortates/TVLW/The-Very-Little-War/compte.php:30-67 and /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:175-179
- **Domain:** GAME_CORE
- **Description:** When a player's vacation end date passes, `basicprivatephp.php` line 175 automatically sets `vacance = 0` and deletes the `vacances` row. There is no cooldown period recorded anywhere. The player can immediately POST a new `dateFin` to `compte.php` to re-enter vacation mode. The only constraint is the 3-day minimum advance (`VACATION_MIN_ADVANCE_SECONDS`), but that is a forward-looking minimum duration, not a cooldown after the previous vacation ended. A player could cycle vacation mode repeatedly to become permanently un-attackable while still collecting resources during the gap between deactivation and the next activation taking effect.
- **Impact:** Abuse vector: a player can chain vacations back-to-back, spending minimal time exposed to attacks while still benefiting from resource accumulation during the brief windows. This undermines the PvP economy.
- **Suggested fix:** Add a `VACATION_COOLDOWN_SECONDS` constant to config.php (e.g., 7 days). In the vacation activation POST handler in `compte.php`, before activating, query the most recent `vacances` row for the player and check that `dateFin + VACATION_COOLDOWN_SECONDS < CURDATE()`. If not, reject with an error message.

### [MEDIUM]-002: Banned player can still cast votes via voter.php
- **File:** /home/guortates/TVLW/The-Very-Little-War/voter.php:18-23
- **Domain:** GAME_CORE
- **Description:** The session token validation on line 18 does check `estExclu = 0`:
  ```php
  $row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ? AND estExclu = 0', 's', $_SESSION['login']);
  ```
  However, this only works if the banned player's session was invalidated. If a player is banned *after* they obtained a valid `session_token` and their token in `membre` was not cleared during the ban, the `hash_equals` check on line 19 will still pass because the stored token was set before the ban and may still match. The `estExclu = 0` filter would return no row, which would correctly trigger session_destroy. **On closer inspection, this is actually safe** -- if `estExclu` is set to a nonzero value, the query returns null, `$row` is false, and the check on line 19 fails. So banned players ARE blocked from voting.
  
  **Revised: NOT A BUG.** The `estExclu = 0` condition properly blocks banned players. Withdrawing this finding.

### [MEDIUM]-003: Tutorial instructions echoed without htmlspecialchars
- **File:** /home/guortates/TVLW/The-Very-Little-War/tutoriel.php:443
- **Domain:** GAME_CORE
- **Description:** The instruction steps are output with raw `echo $step` inside the foreach loop:
  ```php
  <li style="margin-bottom:4px;"><?php echo $step; ?></li>
  ```
  These strings are hardcoded in the same file (lines 20-24, etc.) and contain intentional HTML like `<strong>Constructions</strong>`. Since the values are developer-controlled constants (not user input), this is not exploitable XSS. However, it is an inconsistency with the project's pattern of escaping all output -- every other field (`titre`, `description`, `objectif`) is passed through `htmlspecialchars()`.
- **Impact:** No immediate security risk since the data is hardcoded. However, if a future developer adds user-controlled content to instructions or refactors to load them from the database, this becomes an XSS vector.
- **Suggested fix:** Either: (a) escape and remove the `<strong>` tags, using CSS classes instead, or (b) add a comment explicitly documenting that instructions contain trusted HTML and must never include user input. Option (b) is the pragmatic choice:
  ```php
  <!-- SECURITY: $step contains trusted developer HTML (bold tags). Never inject user input. -->
  <li style="margin-bottom:4px;"><?php echo $step; ?></li>
  ```

### [MEDIUM]-004: tutoriel.php help section outputs $couleurs and $utilite without escaping
- **File:** /home/guortates/TVLW/The-Very-Little-War/tutoriel.php:522
- **Domain:** GAME_CORE
- **Description:** Line 522 outputs color values and utility descriptions directly:
  ```php
  echo '<li><span style="color:'.$couleurs[$num].';font-weight:bold;">'.ucfirst($nomsAccents[$num]).'</span> : '.$utilite[$num].'</li>';
  ```
  The `$couleurs` array contains values like `black`, `blue`, `#D07D00`, `#840000`, `#BB6668` and `$utilite` contains French descriptions. These are all developer-defined constants from `constantesBase.php`. While not user-controllable, this violates the project's consistent escaping pattern. The color values contain `#` characters that are safe in a `style=` context, but the descriptions contain HTML-sensitive characters like apostrophes (e.g., "Produit de l'énergie" -- though the apostrophe is not the HTML-special kind).
- **Impact:** LOW -- no exploitable vector currently. Same future-proofing concern as MEDIUM-003.
- **Suggested fix:**
  ```php
  echo '<li><span style="color:'.htmlspecialchars($couleurs[$num], ENT_QUOTES, 'UTF-8').';font-weight:bold;">'.htmlspecialchars(ucfirst($nomsAccents[$num]), ENT_QUOTES, 'UTF-8').'</span> : '.htmlspecialchars($utilite[$num], ENT_QUOTES, 'UTF-8').'</li>';
  ```

### [LOW]-001: medailles.php does not filter banned players from medal view
- **File:** /home/guortates/TVLW/The-Very-Little-War/medailles.php:31
- **Domain:** GAME_CORE
- **Description:** The existence check on line 31 is:
  ```php
  $donnees = dbFetchOne($base, 'SELECT count(*) AS ok FROM membre WHERE login=?', 's', $joueur);
  ```
  This allows viewing medals for banned (`estExclu != 0`) players. An attacker (or just a curious user) can pass `?login=bannedPlayer` and see all their stats. This is a minor information disclosure.
- **Impact:** LOW -- medal stats are not sensitive. However, the classement.php and other pages already filter out banned players for consistency. Showing banned player profiles is inconsistent.
- **Suggested fix:**
  ```php
  $donnees = dbFetchOne($base, 'SELECT count(*) AS ok FROM membre WHERE login=? AND estExclu = 0', 's', $joueur);
  ```

### [LOW]-002: sinstruire.php course title in dropdown not escaped
- **File:** /home/guortates/TVLW/The-Very-Little-War/sinstruire.php:313
- **Domain:** GAME_CORE
- **Description:** Line 313 builds `<option>` elements with course titles injected directly:
  ```php
  $options = $options."<option value=\"?cours=$num\" $selected>$titre</option>";
  ```
  The `$titre` variable comes from the hardcoded `$cours` array on line 14. These contain French text with apostrophes (e.g., "De l'atome a l'element chimique"). The apostrophe used is `'` which is harmless outside attribute contexts, and the string is placed between `<option>...</option>` tags (not in an attribute). Still, this violates the escaping-everywhere pattern.
- **Impact:** No exploitable vector since data is developer-controlled. Defensive coding improvement.
- **Suggested fix:**
  ```php
  $options = $options."<option value=\"?cours=$num\" $selected>".htmlspecialchars($titre, ENT_QUOTES, 'UTF-8')."</option>";
  ```

---

## Checklist Verification

| Check | Status | Notes |
|-------|--------|-------|
| Tutorial mission progression: can't claim step N without step N-1 | PASS | Sequential enforcement at lines 208-223 (pre-check) and 247-260 (in-transaction re-check with FOR UPDATE) |
| Tutorial reward escalation matches config | PASS | All 7 missions use `TUTORIAL_REWARDS[0..6]` from config.php (200, 300, 400, 500, 600, 800, 1000) |
| Tutorial espionage step: valid target | PASS | Checks `type='espionage'` in rapports table (line 136); espionage creation in game_actions.php validates target |
| Tutorial reward claim: DB re-verified inside tx | PASS | `verify_db` callable re-checks inside `withTransaction` at lines 239-244 |
| Medal thresholds from config | PASS | All thresholds assigned from `$MEDAL_THRESHOLDS_*` constants via constantesBase.php |
| Medal progress bar: no divide-by-zero | PASS | `medalProgress()` line 16: `$range > 0 ? ...` guard present |
| voter.php: INSERT IGNORE prevents dupes | PASS | Line 79 uses `FOR UPDATE` on existing check, line 81 uses `INSERT IGNORE` |
| voter.php: FOR UPDATE on sondages row | PARTIAL | The `FOR UPDATE` is on `reponses_sondage` (voter's row) not `sondages`, but this suffices since it prevents the same user from double-voting |
| voter.php: session token validated | PASS | Lines 18-23 validate session_token with hash_equals |
| voter.php: CSRF on vote POST | PASS | Line 51 calls `csrfCheck()` |
| Vacation mode: blocks during active combat | PASS | Transaction in compte.php lines 45-58 with FOR UPDATE on membre and active combat count check |
| Vacation mode: blocks resource actions | PASS | `redirectionVacance.php` included by action pages (armee, constructions, marche, attaquer, laboratoire, don, attaque) |
| bilan.php: formulas match actual functions | PASS | Uses same functions (revenuEnergie, revenuAtome, etc.) for verification |
| bilan.php: specialization irreversible | PASS | Transaction checks `$currentChoice === 0` before allowing SET (line 71) |
| molecule.php: read-only, no mutations | PASS | Only SELECT queries, no POST handler |
| vacance.php: CSRF on activation POST | PASS | CSRF handled in compte.php (line 6: `csrfCheck()` on all POST); vacance.php is just the display page |
| Vacation re-activation cooldown | FAIL | See MEDIUM-001 |
| Banned player voting blocked | PASS | `estExclu = 0` in session token query |
| Medal progress div-by-zero for zero counters | PASS | `medalProgress()` handles `$range > 0` and returns 0 pct |
| bilan.php: values escaped | PASS | Consistent use of `htmlspecialchars()` throughout |
| sinstruire.php: read-only | PASS | No POST handler, no mutations, GET-only navigation with comment (line 327) |
| tutoriel.php max overflow | PASS | `$missionIndex >= 0 && $missionIndex < count($tutorielMissions)` bounds check on line 201 |

---

## Summary

**0 CRITICAL, 0 HIGH, 4 MEDIUM, 2 LOW**

The GAME_CORE domain files are in solid shape. The most actionable finding is **MEDIUM-001** (no vacation cooldown), which is a game design exploit that allows players to chain vacation periods and avoid PvP indefinitely. The remaining findings are defensive-coding consistency issues (unescaped developer-controlled strings) rather than exploitable vulnerabilities.



---

## PRESTIGE

Now I have a thorough picture of the code. Let me compile my findings.

---

## Prestige Domain Audit -- Pass 25

### MEDIUM-001: `hasPrestigeUnlock()` issues N+1 DB queries with no per-request cache

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:260-263`
- **Domain:** PRESTIGE
- **Description:** Every call to `hasPrestigeUnlock()` invokes `getPrestige()`, which runs a full `SELECT * FROM prestige WHERE login=?` query. On `prestige.php`, `hasPrestigeUnlock()` is called 3 times (lines 104, 111, 118 via `isPrestigeLegend`), plus `prestigeProductionBonus()` and `prestigeCombatBonus()` each call it once more (lines 24-25). That is 5 separate DB queries for the same row on one page load. On `bilan.php`, it is called 5 more times (lines 785-797). On `attaquer.php`, it can be called up to 4 times per request. None of these results are cached.

  More critically, after `purchasePrestigeUnlock()` completes on prestige.php (line 9), `getPrestige()` is called again on line 18 to reload data. Then the 5 `hasPrestigeUnlock` calls re-query the row 5 more times. The data is always fresh (no stale-cache bug), but this is wasteful.

- **Impact:** Performance: up to 7 redundant DB queries per prestige.php page load. On bilan.php, 6 redundant queries. Not a correctness bug, but measurable overhead on every authenticated page that checks prestige unlocks.
- **Suggested fix:** Add a static cache to `getPrestige()`:
  ```php
  // Before
  function getPrestige($login) {
      global $base;
      $data = dbFetchOne($base, 'SELECT * FROM prestige WHERE login=?', 's', $login);
      ...
  }
  
  // After
  function getPrestige($login, $bustCache = false) {
      global $base;
      static $cache = [];
      if (!$bustCache && isset($cache[$login])) return $cache[$login];
      $data = dbFetchOne($base, 'SELECT * FROM prestige WHERE login=?', 's', $login);
      if (!$data) $data = ['total_pp' => 0, 'unlocks' => ''];
      $cache[$login] = $data;
      return $data;
  }
  ```
  Call `getPrestige($login, true)` after `purchasePrestigeUnlock()` to bust the cache.

---

### MEDIUM-002: Season reset sets `streak_days=0` but first login increments to 1, causing day-1 milestone to fire every season start

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:1514` (reset) and `1614-1617` (streak logic)
- **Domain:** PRESTIGE
- **Description:** On season reset (line 1514), `streak_days` is set to `0` and `streak_last_date` to `NULL`. On the player's first login after reset, `updateLoginStreak()` runs. Since `streak_last_date` is `NULL`, it does not match `$today` (line 1607) or `$yesterday` (line 1614), so the code falls to the `else` on line 1616 and sets `$currentStreak = 1`. Then line 1626 checks `isset($STREAK_MILESTONES[1])` which is `true` (maps to `STREAK_REWARD_DAY_1 = 1`). So the player earns 1 PP.

  This is correct behavior -- day 1 is a milestone and the player should get 1 PP. However, every non-milestone day (days 2, 4, 5, 6, 8-13, 15-20, 22-27) also awards `STREAK_REWARD_DAY_1 = 1` PP via the `elseif` on line 1629. So the day-1 milestone reward is identical to the daily non-milestone reward. The milestone flag on day 1 is cosmetically misleading but functionally harmless.

  **This is actually NOT a bug** -- the design intentionally awards 1 PP every day you log in, whether via milestone (day 1) or daily (non-milestone days). The milestone marker just drives the UI notification. No issue here upon further analysis.

---

### MEDIUM-003: `prestige.php` displays `$currentStreak` from a separate raw query, not from `updateLoginStreak()` result

- **File:** `/home/guortates/TVLW/The-Very-Little-War/prestige.php:53-54`
- **Domain:** PRESTIGE
- **Description:** Line 53 runs `SELECT streak_days, streak_last_date FROM autre WHERE login = ?` to display the current streak. This is a separate query from the one in `updateLoginStreak()` which already ran in `basicprivatephp.php`. The streak data was already computed and could be passed via `$_SESSION`. Instead, an extra DB query is made. This is a minor performance concern, not a correctness issue -- the data returned will be consistent since `updateLoginStreak()` already committed the transaction by the time `prestige.php` runs.
- **Impact:** One unnecessary DB query per prestige.php page load. Low severity.
- **Suggested fix:** Store the streak count in `$_SESSION['current_streak']` inside the `updateLoginStreak()` call path in `basicprivatephp.php`, then read it from the session in `prestige.php` instead of re-querying.

---

### LOW-001: `$currentStreak` output on line 68 is not escaped with `htmlspecialchars`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/prestige.php:68`
- **Domain:** PRESTIGE
- **Description:** Line 68 uses `<?= $currentStreak ?>` directly in HTML output. While `$currentStreak` is cast to `(int)` on line 54 so it cannot contain XSS payload, the pattern is inconsistent with the rest of the page which wraps every output in `htmlspecialchars()`. Lines 70 and 71 also use `<?= $nextMilestone ?>` and `<?= $nextReward ?>` and `<?= $pct ?>` without escaping, though all are integers from config constants.
- **Impact:** No actual XSS risk since these are all integer-typed values. Inconsistent coding pattern.
- **Suggested fix:** Use `<?= (int)$currentStreak ?>` or `<?= htmlspecialchars((string)$currentStreak, ENT_QUOTES, 'UTF-8') ?>` for consistency.

---

### LOW-002: Streak milestones in prestige.php UI (lines 80-82) are hardcoded constant names, not the `$STREAK_MILESTONES` array

- **File:** `/home/guortates/TVLW/The-Very-Little-War/prestige.php:80-82`
- **Domain:** PRESTIGE
- **Description:** The help text on lines 80-82 references `STREAK_REWARD_DAY_1` through `STREAK_REWARD_DAY_28` constants directly. If new milestones were added to `$STREAK_MILESTONES` (e.g., day 60), this display section would not update automatically. The milestone iteration loop on lines 57-63 correctly uses the array, but the help text is hardcoded.
- **Impact:** Maintenance burden if milestones are changed. Low severity since the config array and constants are defined together in config.php.
- **Suggested fix:** Generate the milestone text from `$STREAK_MILESTONES` in a loop instead of hardcoding individual constant references.

---

### Summary of checked items with no issues found:

1. **PP earning formulas** -- All sources (medals, combat wins, economy, streak) correctly use config constants. Verified: `PRESTIGE_PP_PER_MEDAL_TIER`, `PRESTIGE_PP_ATTACK_BONUS`, `PRESTIGE_PP_TRADE_BONUS`, `PRESTIGE_PP_DONATION_BONUS`, `PRESTIGE_PP_ACTIVE_FINAL_WEEK`, `PP_DONATION_MIN_THRESHOLD`, `PRESTIGE_PP_ATTACK_THRESHOLD`, `PRESTIGE_PP_TRADE_THRESHOLD`.

2. **PP spending atomicity** -- `purchasePrestigeUnlock()` uses `withTransaction()` + `FOR UPDATE` on the prestige row. The `total_pp >= cost` check is inside the transaction (line 308 and the WHERE clause on line 318). The UPDATE uses `GREATEST(0, total_pp - ?)` as a DB-level floor. The column is `INT UNSIGNED` per migration 0075. Solid.

3. **Double-purchase prevention** -- Line 303 checks `in_array($unlockKey, $unlocks, true)` inside the transaction after acquiring the FOR UPDATE lock. Correct.

4. **Double-season guard** -- `awardPrestigePoints()` has idempotency via `prestige_awarded_season` in `statistiques`, checked both outside (fast path) and inside the transaction with `FOR UPDATE`. INSERT IGNORE ensures row exists for locking. Robust.

5. **Prestige unlock application** -- `debutant_rapide` is applied at season reset (player.php:1560-1563, sets generateur=2). `experimente` is applied via `prestigeProductionBonus()` in game_resources.php (3 call sites). `maitre_chimiste` is applied via `prestigeCombatBonus()` in combat.php (lines 185-186). `veteran` adds +1 day to beginner protection in attaquer.php (4 call sites). `legende` is cosmetic (display only). All correct.

6. **Bonus stacking** -- Production and combat bonuses are multiplicative factors (1.05), not additive. Each is a single unlock (not stackable). No double-counting possible.

7. **Login streak timezone** -- Uses `DateTimeZone('Europe/Paris')` with `DateTime` objects (lines 1595-1596, 1612). Timezone-safe.

8. **Streak reset** -- Resets to 1 when gap > 1 day (line 1617: `$currentStreak = 1`). Correct.

9. **Comeback bonus** -- Uses all config constants: `COMEBACK_ABSENCE_DAYS` (3 days), `COMEBACK_COOLDOWN_DAYS` (7 days), `COMEBACK_ENERGY_BONUS` (500), `COMEBACK_ATOMS_BONUS` (100), `COMEBACK_SHIELD_HOURS` (24). Cooldown check uses `>=` (line 1676). Resource grants capped at `LEAST(current + bonus, storageMax)`. All correct.

10. **CSRF on purchase** -- `csrfCheck()` called on line 8 of prestige.php for POST requests. `csrfField()` included in every purchase form (line 154). Correct.

11. **PP display correctness** -- `total_pp` in the DB is the available balance (purchases deduct from it). The page reloads data after purchase (line 18 runs after lines 7-14). No stale session issue.

12. **Prestige row creation** -- `awardPrestigePoints()` uses `INSERT ... ON DUPLICATE KEY UPDATE` (line 236). `updateLoginStreak()` does the same (line 1635). `purchasePrestigeUnlock()` does `INSERT ... ON DUPLICATE KEY UPDATE login=login` to ensure the row exists before FOR UPDATE (line 292). All paths handle missing rows.

13. **Banned player exclusion** -- `purchasePrestigeUnlock()` checks `estExclu` with FOR UPDATE inside the transaction (line 283-287). `awardPrestigePoints()` filters banned players in the query (line 169: `m.estExclu = 0`). Correct.

14. **Dense ranking** -- Uses proper dense rank algorithm (lines 173-181). No gaps for tied scores. Correct.

---

## Final Tally

**0 CRITICAL, 0 HIGH, 2 MEDIUM, 2 LOW**

The prestige domain is in solid shape after the previous 24 passes of remediation. The two MEDIUM findings are performance-only (no caching on `hasPrestigeUnlock` queries, and a redundant streak query). The two LOW findings are cosmetic consistency issues. No correctness, security, or game balance bugs were found.



---

## RANKINGS

There's an issue: when `isset($_SESSION['login'])` is true AND `$pasTrouve` is set AND equals 1 (meaning the POST search found the player), `$pageParDefaut` was set earlier on line 48. But when `isset($pasTrouve)` is true AND `$pasTrouve == 1`, the code does nothing (empty if block), and `$pageParDefaut` from line 48 is used. That's fine.

But if the user is logged in and does NOT search (`$pasTrouve` is not set), it goes to the `else` branch and computes `$pageParDefaut`. OK, this is safe.

Let me now compile the findings.

---

Here is the complete audit report for the RANKINGS domain (Pass 25).

---

### MEDIUM-001: Trade volume display uses wrong cap constant

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:311
- **Domain:** RANKINGS
- **Description:** The trade volume column in the player leaderboard displays `min((int)$donnees['tradeVolume'], MARKET_POINTS_MAX)`. `MARKET_POINTS_MAX` is defined as `80` -- the cap on the *computed market points contribution to totalPoints* (a derived score). But `tradeVolume` is the raw cumulative trade volume (potentially millions). Capping the display at 80 means every player who has traded more than 80 units of energy shows "80" in the Commerce column, making the column useless for differentiation.
- **Impact:** The Commerce column displays the same value (80) for virtually all active traders, making the ranking column uninformative. Players cannot compare relative trade activity.
- **Suggested fix:**
  ```php
  // Before (line 311):
  <td><?php echo chiffrePetit(min((int)$donnees['tradeVolume'], MARKET_POINTS_MAX)); ?></td>
  
  // After — show actual trade volume (the ranking formula already caps internally):
  <td><?php echo chiffrePetit((int)$donnees['tradeVolume']); ?></td>
  ```

### MEDIUM-002: Visitor accounts appear in player rankings after placement

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:107,217
- **Domain:** RANKINGS
- **Description:** The leaderboard queries filter out unplaced accounts (`m.x != -1000`) and banned accounts (`m.estExclu = 0`), but do not exclude Visitor accounts (login matching `Visiteur%`). Once a Visitor account is placed on the map (which happens automatically in `basicprivatephp.php:83`), it appears in the rankings alongside real players. Visitor accounts are temporary throwaway accounts intended for quick trials.
- **Impact:** Rankings can be polluted with dozens of Visitor accounts, pushing real players down. This undermines ranking integrity and could confuse legitimate players.
- **Suggested fix:** Add a filter to all leaderboard queries:
  ```php
  // Add to WHERE clause of all player ranking queries:
  AND a.login NOT LIKE 'Visiteur%'
  
  // Example for the main leaderboard (line 217):
  'SELECT a.*, DENSE_RANK() OVER (ORDER BY a.' . $order . ' DESC) AS rang
   FROM autre a JOIN membre m ON m.login = a.login
   WHERE m.x != -1000 AND m.estExclu = 0 AND a.login NOT LIKE \'Visiteur%\'
   ORDER BY a.' . $order . ' DESC
   LIMIT ?, ?'
  ```
  Apply the same filter to: the daily leaderboard query (line 102-109), the player count query (line 199), the player search rank query (lines 43, 46), and the logged-in player rank query (lines 182, 185).

### MEDIUM-003: Daily leaderboard shows cumulative scores, not daily gains

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:99-110
- **Domain:** RANKINGS
- **Description:** The "Aujourd'hui" (daily) toggle filters players who logged in today (`derniereConnexion >= midnight`) but sorts them by their cumulative `$order` column (e.g., `totalPoints`, `pointsAttaque`). This means the daily leaderboard is just "today's active players sorted by all-time score" -- it does not show who made the most progress today. The label "Aujourd'hui" is misleading.
- **Impact:** Players who are already at the top of the all-time leaderboard will always dominate the daily view, making it useless for identifying who is most active or making the most gains on a given day. The feature fails to deliver its intended purpose.
- **Suggested fix:** This is a known limitation documented in `docs/plans/2026-03-05-audit-remediation-final.md:1084`. A proper fix requires a daily snapshot table (storing yesterday's points for delta calculation). At minimum, the label should clarify what it shows:
  ```php
  // Change label from:
  <a ... class="button <?= $mode === 'daily' ? 'button-active' : '' ?>">Aujourd'hui</a>
  // To:
  <a ... class="button <?= $mode === 'daily' ? 'button-active' : '' ?>">Actifs aujourd'hui</a>
  ```

### MEDIUM-004: Forum ranking (sub=3) does not freeze during season maintenance

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:621-750
- **Domain:** RANKINGS
- **Description:** The player ranking (sub=0) and alliance ranking (sub=1) both check for season maintenance and show a "frozen" banner during end-of-season reset. However, the forum/medal ranking tab (sub=3, the `else` branch at line 621) has no such maintenance check. While forum stats are less critical than combat stats, during a season reset the underlying `membre` and `autre` tables may be in a partially-reset state, causing inconsistent data to display.
- **Impact:** During the brief season maintenance window, the forum ranking could display stale or partially-reset data (e.g., if `autre` rows are being reset). Low risk since forum stats are typically not reset during seasons.
- **Suggested fix:** Add the same maintenance check at the start of the forum ranking block:
  ```php
  else {
      // Check for active season maintenance
      $seasonMaintenanceForum = false;
      if (isset($maintenance['maintenance']) && $maintenance['maintenance'] == 1) {
          $seasonMaintenanceForum = true;
      } else {
          $maintenanceCheckForum = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
          if ($maintenanceCheckForum && $maintenanceCheckForum['maintenance'] == 1) {
              $seasonMaintenanceForum = true;
          }
      }
      if ($seasonMaintenanceForum) {
          // Show frozen banner (same as other tabs)
      } else {
          // Existing forum ranking code
      }
  }
  ```

### LOW-001: War ranking (sub=2) does not freeze during season maintenance

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:542-619
- **Domain:** RANKINGS
- **Description:** Same issue as MEDIUM-004 but for the war ranking tab (sub=2). War declarations are historical and unlikely to be modified during a season reset, making this lower severity.
- **Impact:** Minimal -- war history is not reset during seasons.
- **Suggested fix:** Add the same maintenance check for consistency.

### LOW-002: Alliance ranking pagination URL does not preserve `clas` parameter

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:514
- **Domain:** RANKINGS
- **Description:** The pagination URL for the alliance ranking tab is hardcoded as `$adresse = "classement.php?sub=1&"` without appending the `clas` parameter. When a player sorts alliances by a specific column (e.g., `clas=2` for attack), navigating to page 2 resets the sort to the default (`pointstotaux`).
- **Impact:** Players lose their sort preference when paginating through alliance rankings. This is a UX annoyance.
- **Suggested fix:**
  ```php
  // Before (line 514):
  $adresse = "classement.php?sub=1&";
  
  // After:
  $adresse = 'classement.php?sub=1' . ($clas !== -1 ? '&clas=' . $clas : '') . '&';
  ```

### LOW-003: Duplicate maintenance check logic across ranking tabs

- **File:** /home/guortates/TVLW/The-Very-Little-War/classement.php:72-80,364-373
- **Domain:** RANKINGS
- **Description:** The season maintenance check (checking `$maintenance['maintenance']` then falling back to a DB query) is duplicated verbatim for the player and alliance tabs. This violates DRY and creates a risk that future changes to the maintenance check will be applied to one tab but not the other.
- **Impact:** Maintenance burden; no functional bug currently.
- **Suggested fix:** Extract the maintenance check into a helper at the top of the file:
  ```php
  $isSeasonMaintenance = false;
  if (isset($maintenance['maintenance']) && $maintenance['maintenance'] == 1) {
      $isSeasonMaintenance = true;
  } else {
      $maintenanceCheck = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
      if ($maintenanceCheck && $maintenanceCheck['maintenance'] == 1) {
          $isSeasonMaintenance = true;
      }
  }
  ```
  Then reference `$isSeasonMaintenance` in each tab.

---

## Summary

**0 CRITICAL, 0 HIGH, 4 MEDIUM, 3 LOW**

**Checklist verification:**

- **Sqrt ranking formula:** Correctly applied in `calculerTotalPoints()` using config constants (`RANKING_*_WEIGHT`, `RANKING_SQRT_EXPONENT`). No hardcoded weights.
- **Category weights match docs:** Yes -- construction 1.0, attack 1.5, defense 1.5, trade 1.0, pillage 1.2.
- **Daily toggle:** Shows cumulative scores of active-today players, not daily deltas (MEDIUM-003). Known limitation, documented.
- **Seasonal toggle:** Full-season cumulative `totalPoints` sourced correctly from `autre` table.
- **Rankings frozen during maintenance:** Implemented for player (sub=0) and alliance (sub=1) tabs, but missing for war (sub=2) and forum (sub=3) tabs (MEDIUM-004, LOW-001).
- **ORDER BY whitelist:** Properly whitelisted via strict `===` comparison at lines 17-33 (player), 410-422 (alliance), 652-661 (forum). No SQL injection possible.
- **LIMIT/OFFSET pagination:** Integer-cast via `intval()` at lines 204, 402, 548, 628. Safe.
- **Public read-only:** Confirmed no mutations. Search POST has CSRF check.
- **XSS:** `joueur()` uses `htmlspecialchars()`, `alliance()` uses `htmlspecialchars()`. Safe.
- **Visitor accounts excluded:** NOT excluded once placed (MEDIUM-002). Only `x != -1000` and `estExclu = 0` filters exist.
- **Numeric overflow/negative:** `pointsAttaque`/`pointsDefense` guard against `<= 0` with early return. `calculerTotalPoints` uses `max(0, ...)`. `chiffrePetit` handles negatives. Safe.
- **Midnight timezone:** `strtotime('today midnight')` uses `Europe/Paris` timezone set in config.php line 13. Consistent.
- **Sqrt edge cases (0, negative):** `pow(max(0, ...), 0.5)` handles zero and negative values. `pointsAttaque`/`pointsDefense` return 0 for non-positive inputs. Safe.
- **N+1 queries:** All pre-loaded with cache arrays (alliances, prestige, member counts, wars, pacts, forum stats, subject counts). No N+1 patterns.



---

## INFRA-TEMPLATES

I have now read all the specified files. Here is my complete audit report.

---

# INFRA-TEMPLATES Domain Audit -- Pass 25

## Findings

### HIGH-001: `coutTout()` outputs `$cout` without escaping in chip-label

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php:252`
- **Domain:** INFRA-TEMPLATES
- **Description:** The `coutTout()` function outputs `$cout` directly into HTML without `htmlspecialchars()`:
  ```php
  <div class="chip-label">' . $cout . '</div>
  ```
  While `$cout` is typically numeric, it is a function parameter that callers pass user-influenced values into. In `cardsprivate.php` line 38, `coutTout(20)` is hardcoded, but the function signature accepts any value. Contrast with `nombreTout()` on line 204 which correctly escapes `$nombre`.
- **Impact:** If a caller ever passes an unescaped string (e.g., from DB or user input), this is a stored/reflected XSS vector. The inconsistency with `nombreTout()` (which escapes) signals an oversight.
- **Suggested fix:**
  ```php
  // Before
  <div class="chip-label">' . $cout . '</div>
  // After
  <div class="chip-label">' . htmlspecialchars((string)$cout, ENT_QUOTES, 'UTF-8') . '</div>
  ```

---

### HIGH-002: `sanitizeReportHtml()` allows `style` attribute via omission from allowedAttrs, but `style` can enable CSS-based data exfiltration

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php:380-416`
- **Domain:** INFRA-TEMPLATES
- **Description:** The `sanitizeReportHtml()` function has an `allowedAttrs` whitelist that includes `href`, `src`, `alt`, `id`, and `class`. Crucially, `style` is **not** in the allowlist, meaning any `style` attribute on allowed tags will be **removed**. This is actually correct and secure. However, reviewing more closely, the function does not strip `title`, `data-*`, or event handler attributes that share prefixes -- wait, it does: the loop removes any attribute whose `name` is not a key in `$allowedAttrs`. This is correct.

  On re-examination, this is actually fine. Withdrawing this finding.

---

### HIGH-002: `slider()` outputs color, min, max, value, step options without escaping

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php:535-542`
- **Domain:** INFRA-TEMPLATES
- **Description:** The `slider()` function injects `$color` into a class attribute without escaping:
  ```php
  $color = 'class="color-' . $options['color'] . '"';
  ```
  And injects `$min`, `$max`, `$value`, `$step` directly into `<input>` attributes:
  ```php
  <input type="range" min="' . $min . '" max="' . $max . '" value="' . $value . '" step="' . $step . '">
  ```
  None of these are passed through `htmlspecialchars()`. If any caller passes user-controlled data through the options array, attribute injection is possible.
- **Impact:** Attribute injection could break out of the attribute context and inject event handlers (e.g., `" onfocus="alert(1)`).
- **Suggested fix:**
  ```php
  // Before
  $color = 'class="color-' . $options['color'] . '"';
  // After
  $color = 'class="color-' . htmlspecialchars($options['color'], ENT_QUOTES, 'UTF-8') . '"';

  // Before
  <input type="range" min="' . $min . '" max="' . $max . '" value="' . $value . '" step="' . $step . '">
  // After
  <input type="range" min="' . htmlspecialchars((string)$min, ENT_QUOTES, 'UTF-8') . '" max="' . htmlspecialchars((string)$max, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '" step="' . htmlspecialchars((string)$step, ENT_QUOTES, 'UTF-8') . '">
  ```

---

### HIGH-003: `progressBar()` outputs `$vie`, `$vieMax`, `$pct`, `$couleur` without escaping

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php:498-509`
- **Domain:** INFRA-TEMPLATES
- **Description:** The `progressBar()` function outputs multiple parameters directly into HTML attributes and content:
  ```php
  data-progress="' . $pct . '" class="progressbar color-' . $couleur . '"
  ...
  <strong style="font-size:13px">' . $vie . '/' . $vieMax . '</strong>
  ```
  While `$pct` is computed internally via `min(100, round(...))` and is safe, `$couleur` is a raw string parameter injected into a class attribute, and `$vie`/`$vieMax` are placed into HTML content without escaping.
- **Impact:** If a caller passes an unsanitized color string, attribute injection is possible. If `$vie`/`$vieMax` come from DB and contain unexpected data, XSS is possible.
- **Suggested fix:**
  ```php
  // Escape $couleur in class context
  class="progressbar color-' . htmlspecialchars($couleur, ENT_QUOTES, 'UTF-8') . '"
  // Escape $vie / $vieMax in content
  <strong style="font-size:13px">' . htmlspecialchars((string)$vie, ENT_QUOTES, 'UTF-8') . '/' . htmlspecialchars((string)$vieMax, ENT_QUOTES, 'UTF-8') . '</strong>
  ```

---

### MEDIUM-001: `countdown.js` uses hardcoded magic numbers 86400 and 3600 instead of sharing constants

- **File:** `/home/guortates/TVLW/The-Very-Little-War/js/countdown.js:46-48`
- **Domain:** INFRA-TEMPLATES
- **Description:** The countdown timer uses hardcoded `86400` and `3600` for time calculations:
  ```javascript
  var jours = Math.floor(diff / 86400);
  var heures = Math.floor((diff % 86400) / 3600);
  var minutes = Math.floor((diff % 3600) / 60);
  ```
  While these are standard seconds-per-day/hour constants, the PHP side uses named constants (`SECONDS_PER_DAY`, `SECONDS_PER_HOUR`) consistently. This is a minor consistency issue -- no functional bug.
- **Impact:** Maintainability: if time semantics ever changed, JS and PHP could drift. Low practical risk.
- **Suggested fix:** Add `var SECONDS_PER_DAY = 86400, SECONDS_PER_HOUR = 3600;` at the top of the IIFE for readability consistency.

---

### MEDIUM-002: `index.php` season countdown uses `date('n')` (current month) instead of `$debut['debut']` for navbar consistency

- **File:** `/home/guortates/TVLW/The-Very-Little-War/index.php:145-152`
- **Domain:** INFRA-TEMPLATES
- **Description:** The index.php hero countdown computes season end based on the current calendar month:
  ```php
  $endMonth = (int)date('n') + 1;
  $endYear  = (int)date('Y');
  ```
  While `layout.php` (line 64-72) uses `$debut['debut']` (the actual season start timestamp) to compute the season end. If a season ever starts on a non-standard date or spans month boundaries differently, these two countdowns could diverge.
- **Impact:** Potential inconsistency between navbar countdown and homepage countdown. Currently both happen to produce the same result because seasons are monthly, but the logic drift could cause confusion if season timing changes.
- **Suggested fix:** Use the same `$debut['debut']`-based calculation as `layout.php`, or extract the season-end computation into a shared function in `game_resources.php`.

---

### MEDIUM-003: `chip()` does not escape `$label` parameter

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php:489`
- **Domain:** INFRA-TEMPLATES
- **Description:** The `chip()` function outputs `$label` directly into the `chip-label` div:
  ```php
  <div class="chip-label" ' . $id . '>' . $label . '</div>
  ```
  Many callers pass pre-formatted HTML (e.g., `chiffrePetit()` output which includes `<span>` tags), so this is intentional. However, callers like `nombreTout()` wisely escape their own `$nombre` before passing it. Other callers like `coutAtome()` pass `chiffrePetit($cout)` which includes a `title` attribute with `number_format()` output -- safe in practice. The documentation/contract is implicit.
- **Impact:** The function is designed to accept trusted HTML, but without a clear contract, a future caller could pass unsanitized user input. This is a defense-in-depth concern.
- **Suggested fix:** Add a PHPDoc comment to `chip()` clearly documenting that `$label` accepts trusted HTML and callers must escape user data before passing. Alternatively, provide a `chipText()` wrapper that escapes automatically.

---

### MEDIUM-004: `meta.php` lacks canonical `<link>` tag

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php`
- **Domain:** INFRA-TEMPLATES
- **Description:** The file includes `og:url` with `content="https://theverylittlewar.com"` but does not include a `<link rel="canonical" ...>` tag. The `og:url` is also hardcoded to the root URL rather than being dynamic per page, which means every page claims to be the homepage for social sharing.
- **Impact:** SEO: search engines may not properly deduplicate pages. Social shares from non-homepage pages all point back to the root.
- **Suggested fix:** Add a dynamic canonical link:
  ```php
  <link rel="canonical" href="https://theverylittlewar.com/<?= htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8') ?>" />
  ```
  And update `og:url` similarly.

---

### LOW-001: `copyright.php` Framework7 JS loaded without `nonce` attribute

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:18`
- **Domain:** INFRA-TEMPLATES
- **Description:** The Framework7 JS is loaded as:
  ```html
  <script type="text/javascript" src="js/framework7.min.js"></script>
  ```
  The CSP header in `layout.php` includes `script-src 'self'`, which permits same-origin scripts. So this works correctly. However, unlike jQuery (line 19 which has `integrity` and `crossorigin` attributes for SRI), `framework7.min.js` has no SRI hash. If the local file were tampered with, SRI would not catch it.
- **Impact:** Low. SRI for local files is defense-in-depth against file tampering on the server. The risk is minimal since the file is served from the same origin.
- **Suggested fix:** Add an `integrity` attribute to the Framework7 script tag with its SHA-384 hash.

---

### LOW-002: `copyright.php` calls `cspNonce()` a second time instead of reusing `$nonce`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:22`
- **Domain:** INFRA-TEMPLATES
- **Description:** The copyright/footer file calls `cspNonce()` again:
  ```php
  <script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
  ```
  Since `cspNonce()` returns `$GLOBALS['csp_nonce']` which is set once per request in `csp.php`, this returns the same value every time. So functionally there is no bug. However, for code clarity and consistency, `layout.php` stores it in `$nonce` at line 2 -- it would be cleaner to reference `$nonce` where available.
- **Impact:** None functionally. Minor code clarity issue.
- **Suggested fix:** Use `$nonce` instead of `cspNonce()` where the variable is already in scope. This is cosmetic.

---

### LOW-003: `style.php` checks `isset($nonce)` instead of calling `cspNonce()`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/style.php:1`
- **Domain:** INFRA-TEMPLATES
- **Description:** The style tag uses:
  ```php
  <style<?php if (isset($nonce)) { echo ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"'; } ?>>
  ```
  This relies on `$nonce` being in scope from the including file (`layout.php` sets `$nonce` at line 2). If `style.php` were ever included from a context where `$nonce` is not defined, the style block would lack a nonce. Using `cspNonce()` directly would be more robust.
- **Impact:** Currently safe because `style.php` is only included from `layout.php` which sets `$nonce`. But fragile coupling.
- **Suggested fix:** Replace with `cspNonce()` call for consistency.

---

### LOW-004: `version.php` does not expose sensitive server details (CONFIRMED SAFE)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/version.php`
- **Domain:** INFRA-TEMPLATES
- **Description:** Verified that `version.php` only displays game version history text and a `GAME_VERSION` constant. It does not expose PHP version, MariaDB version, file paths, or any server internals. This is correct.
- **Impact:** None -- this is a positive confirmation.

---

### LOW-005: `health.php` localhost detection could be bypassed via `X-Forwarded-For` if behind a misconfigured proxy

- **File:** `/home/guortates/TVLW/The-Very-Little-War/health.php:45-50`
- **Domain:** INFRA-TEMPLATES
- **Description:** The health endpoint checks `$_SERVER['REMOTE_ADDR']` to restrict detailed info to localhost. It normalizes IPv6-mapped IPv4. However, there is a comment noting the concern about reverse proxies. If Apache ever uses `mod_remoteip` with `X-Forwarded-For`, an attacker could spoof the header. Currently the code relies on `REMOTE_ADDR` which Apache sets from the TCP connection, so this is safe in the default configuration.
- **Impact:** Low. In current configuration (direct Apache, no proxy), `REMOTE_ADDR` cannot be spoofed. The existing code comment already flags this concern. No action needed unless the architecture changes.
- **Suggested fix:** No change needed. The existing comment is sufficient documentation.

---

## Items Verified as Correct (No Finding)

1. **CSP nonce:** `cspNonce()` is called once per request in `csp.php` line 7 via `$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16))`. The same nonce is returned by all subsequent `cspNonce()` calls. The CSP header in `layout.php` line 16 uses the same `$nonce`. All script tags use either `cspScriptTag()` or `nonce="<?php echo htmlspecialchars(cspNonce(), ...) ?>"`. Correct.

2. **CSP header:** Set via `header()` in `layout.php` line 16, not via meta tag. Includes `script-src 'nonce-$nonce'`. No `unsafe-inline` in script-src. Correct.

3. **No inline event handlers:** No `onclick=`, `onload=`, `oninput=` handlers found in `layout.php`. Event binding is done via `addEventListener` in nonce-tagged script blocks. Correct.

4. **jQuery SRI:** Loaded with `integrity="sha512-..."` and `crossorigin="anonymous"` at `copyright.php` line 19. Correct.

5. **Countdown timer:** Handles negative time correctly via `if (diff <= 0)` at `countdown.js` line 42, showing "Nouvelle saison imminente !" and clearing the interval. Reads timestamp from `data-end` attribute. Correct.

6. **SEO meta tags:** `meta.php` includes `og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `og:locale`, `og:site_name`, and a meta description. Correct.

7. **Page title escaping:** `meta.php` line 19 uses `htmlspecialchars($pageTitle ?? 'The Very Little War', ENT_QUOTES, 'UTF-8')`. Correct.

8. **BBCode escaping:** `bbcode.php` line 14 calls `htmlspecialchars($text, ENT_QUOTES, 'UTF-8')` at the START before any tag parsing. Correct.

9. **Forum widget escaping in index.php:** Lines 177-178 use `htmlspecialchars()` on both `$thread['titre']` and `$thread['auteur']`. Correct.

10. **Navbar login display:** `basicprivatehtml.php` line 303 escapes login with `htmlspecialchars($_SESSION['login'], ENT_QUOTES, 'UTF-8')` and profile image with `htmlspecialchars($autre['image'], ENT_QUOTES, 'UTF-8')`. Correct.

11. **`display.php` number formatting:** `couleur()`, `nombreTout()`, `imageLabel()` all use `htmlspecialchars()`. `chiffrePetit()` outputs only computed numeric values via `number_format()` in title attributes. Correct.

12. **`health.php` data leak:** Only returns `status` and `ts` to non-localhost clients. DB credentials are never included. Error details are caught and suppressed. Correct.

13. **`cardsprivate.php`:** Uses `htmlspecialchars()` for catalyst name and description at line 7. Correct.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0     |
| HIGH     | 3     |
| MEDIUM   | 4     |
| LOW      | 5     |

**0 CRITICAL, 3 HIGH, 4 MEDIUM, 5 LOW**

The three HIGH findings are all unescaped output in UI component helper functions (`coutTout`, `slider`, `progressBar`). While these currently receive hardcoded or computed numeric values from callers, they violate the defense-in-depth principle: any future caller passing user data through these functions would create an XSS vulnerability. The fix for all three is straightforward `htmlspecialchars()` wrapping.



---

## TAINT-DB-OUTPUT

I've now traced all the specified files. Let me compile the findings.

---

# TAINT-DB-OUTPUT Audit Report -- Pass 25

## Findings

### [HIGH]-001: Unescaped `actionsconstruction.affichage` column in constructions.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php:462`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** The `affichage` column from the `actionsconstruction` table is rendered directly into HTML without `htmlspecialchars()`:
  ```php
  echo '<tr><td>' . $actionsconstruction['affichage'] . ' <strong>niveau ' . $actionsconstruction['niveau'] . '</strong>...
  ```
  The `affichage` value is set at line 427 from `$liste['titre']`, which comes from server-side building config. However, the defense-in-depth principle requires escaping all DB output at the rendering layer regardless of input trust level. If the DB were compromised or a future code path wrote unsanitized values, this would be a stored XSS vector.
- **Impact:** Stored XSS if `actionsconstruction.affichage` is ever tainted (currently low risk since the value comes from server-side config arrays, not user input).
- **Suggested fix:** `htmlspecialchars($actionsconstruction['affichage'], ENT_QUOTES, 'UTF-8')`

### [HIGH]-002: Unescaped `actionsformation.formule` in armee.php (neutrino edge case)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php:316-319`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** When `idclasse === -1` (neutrino formation), the raw `$actionsformation['formule']` is assigned to `$affichageFormule` without escaping and then echoed into HTML:
  ```php
  $affichageFormule = $actionsformation['formule'];
  // ...
  echo '...' . $affichageFormule . '</td>...';
  ```
  When `idclasse !== -1`, `couleurFormule()` is used which applies `htmlspecialchars()` internally. But the neutrino branch bypasses this.
- **Impact:** Stored XSS if the `formule` field in `actionsformation` were tainted. Currently the value is written by server-side code, but defense-in-depth requires escaping at output.
- **Suggested fix:** `$affichageFormule = htmlspecialchars($actionsformation['formule'], ENT_QUOTES, 'UTF-8');`

### [HIGH]-003: `json_encode($tabCours)` without JSON_HEX_TAG flags in marche.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php:723,754`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** Market price array `$tabCours` is embedded directly into a `<script>` block via:
  ```php
  var echange = <?php echo json_encode($tabCours);
  ```
  The `$tabCours` is derived from the `cours.tableauCours` DB column (a CSV string of float prices). Without `JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP`, if the DB column were ever corrupted or tainted with a string containing `</script>`, it could break out of the script context. The values are clamped to floats on lines 24-26, which provides significant mitigation, but the flag omission violates defense-in-depth for `json_encode` in HTML context.
- **Impact:** Script injection if `cours.tableauCours` is corrupted with non-numeric content. Low practical risk due to float clamping, but the pattern should use proper flags.
- **Suggested fix:** `json_encode($tabCours, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)`

### [HIGH]-004: `json_encode($nodeBonusByType)` without JSON_HEX_TAG flags in game_resources.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:179-183`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** The `$nodeBonusByType` array is derived from `resource_nodes` DB table and embedded into a `<script>` block:
  ```php
  $nodeBonusJson = json_encode($nodeBonusByType);
  // ...
  echo 'var _nodeBonusByType = ' . $nodeBonusJson . ';';
  ```
  Missing `JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP` flags.
- **Impact:** Script injection if `resource_nodes` table values are tainted. Values are computed from numeric DB columns so practical risk is low.
- **Suggested fix:** `json_encode($nodeBonusByType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)`

### [MEDIUM]-001: `json_encode` without JSON_HEX_TAG in basicprivatehtml.php (multiple occurrences)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php:521-544`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** Multiple `json_encode()` calls emit DB-sourced values (energy, resources) into `<script>` blocks without `JSON_HEX_TAG` flags:
  ```php
  var revenuJSEnergie=<?php echo json_encode(revenuEnergie(...)/SECONDS_PER_HOUR);?>
  var valeur = <?php echo json_encode($ressources['energie']); ?>;
  ```
  All values are numeric from the `ressources` and `constructions` tables. The risk is low since `json_encode` of a float/int cannot produce `</script>`, but the pattern is inconsistent with the `JSON_HEX_TAG` usage elsewhere (e.g., `copyright.php:50`).
- **Impact:** Theoretical only -- all values are numeric. Inconsistency violates the project's own security standard.
- **Suggested fix:** Add `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` flags for consistency.

### [MEDIUM]-002: `json_encode` without JSON_HEX_TAG in api.php response

- **File:** `/home/guortates/TVLW/The-Very-Little-War/api.php:119`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** The API response:
  ```php
  $result = json_encode(['valeur' => $dispatch[$id]()]);
  ```
  Outputs `Content-Type: application/json` (line 14), which means browsers should not interpret it as HTML. However, `demiVie` handler (line 106) returns the string `'Infinity'` directly. If a handler ever returned user-controlled content, the missing flags would be relevant. The `Content-Type: application/json` header mitigates browser-based XSS.
- **Impact:** Low -- `Content-Type: application/json` prevents HTML interpretation. Noted for completeness.
- **Suggested fix:** Add `JSON_HEX_TAG | JSON_HEX_AMP` flags as defense-in-depth.

### [MEDIUM]-003: `json_encode` without JSON_HEX_TAG in admin/tableau.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/tableau.php:285-300,745`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** DB values from `unites` table (`unite`, `compagnie`, `sousunite`) are encoded with `json_encode()` and used inside `new RegExp()` calls and as JS string assignments without `JSON_HEX_TAG` flags. Additionally at line 745, `$colonnes[$i]` (from a DB column) is passed through `json_encode()` without flags. This is admin-only page behind password gate, but the values originate from DB.
- **Impact:** XSS if admin DB data is tainted. Mitigated by admin-only access.
- **Suggested fix:** Add `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` flags.

### [MEDIUM]-004: `json_encode` without JSON_HEX_TAG for `$erreur` and `$information` in copyright.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:80,85,97,102`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** The `$erreur` and `$information` variables are passed through `strip_tags(htmlspecialchars_decode(...))` then `json_encode()` without `JSON_HEX_TAG` flags:
  ```php
  echo "myApp.alert(".json_encode(strip_tags(htmlspecialchars_decode((string)$erreur, ENT_QUOTES))).",...
  ```
  While `strip_tags()` removes HTML tags, `json_encode()` without `JSON_HEX_TAG` could still emit `</script>` if the decoded string somehow contained it (the `strip_tags` should prevent this, but defense-in-depth applies). Session flash messages (`$_SESSION['streak_milestone']`, `$_SESSION['comeback_bonus']`) follow the same pattern.
- **Impact:** Low -- `strip_tags()` removes most dangerous content. Defense-in-depth.
- **Suggested fix:** Add `JSON_HEX_TAG | JSON_HEX_AMP` flags to all `json_encode()` calls in this file.

### [LOW]-001: `allie` hidden input value not cast to int in allianceadmin.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:826,838`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** The `declarations.alliance2` / `alliance1` DB value is output as an HTML attribute without `(int)` cast or `htmlspecialchars()`:
  ```php
  '<input type="hidden" name="allie" value="' . $pacte['alliance2'] . '"/>'
  ```
  The value is an integer alliance ID from the DB, so it is not user-controlled. But if the column held unexpected data, it would be unescaped in an attribute.
- **Impact:** Extremely low -- alliance IDs are integers.
- **Suggested fix:** Cast with `(int)$pacte['alliance2']` for consistency.

### [LOW]-002: `adversaire` hidden input value not cast to int in allianceadmin.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:875,889`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** Same pattern as LOW-001 but for `$guerre['alliance2']` / `$guerre['alliance1']`:
  ```php
  '<input type="hidden" name="adversaire" value="' . $guerre['alliance2'] . '"/>'
  ```
- **Impact:** Extremely low -- alliance IDs are integers.
- **Suggested fix:** Cast with `(int)$guerre['alliance2']`

### [LOW]-003: `pertes1` / `pertes2` output without cast in allianceadmin.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php:873,887`
- **Domain:** TAINT-DB-OUTPUT
- **Description:** Loss counts from `declarations` table rendered without `(int)` cast:
  ```php
  '<td>' . $guerre['pertes1'] . '</td>'
  ```
- **Impact:** Extremely low -- integer columns.
- **Suggested fix:** `(int)$guerre['pertes1']`

---

## Files Verified Clean

The following files had all DB-sourced values properly escaped at the output layer:

- **index.php**: News title uses `htmlspecialchars()`, content uses admin-only allowlist+sanitizer, forum threads use `htmlspecialchars()` for `titre` and `auteur`.
- **classement.php**: All player names go through `joueur()` (internally `htmlspecialchars()`), alliance tags through `alliance()` (internally `htmlspecialchars()`), numeric values through `number_format()` or `(int)` casts.
- **joueur.php**: Login escaped with `htmlspecialchars()`, description through `sanitizeReportHtml(BBcode(...))`, image through `htmlspecialchars()`, coordinates through `(int)` cast.
- **rapports.php**: Titles through `htmlspecialchars()`, content through `sanitizeReportHtml()`.
- **messages.php**: Titles, expeditaire through `htmlspecialchars()`, content through `sanitizeReportHtml(BBcode(...))`.
- **messagesenvoyes.php**: All fields properly escaped.
- **forum.php**: Ban motif through `BBcode()` (starts with `htmlspecialchars()`), forum titles through `htmlspecialchars()`.
- **listesujets.php**: Topic titles through `htmlspecialchars()`, authors through `joueur()`.
- **sujet.php**: All DB values properly escaped. BBcode() applies `htmlspecialchars()` first.
- **alliance.php**: Alliance name, tag, grades all properly escaped. Description through `sanitizeReportHtml(BBcode(...))`.
- **alliance_discovery.php**: All fields properly escaped.
- **prestige.php**: All values cast to `(int)` or through `htmlspecialchars()`.
- **medailles.php**: Player names through `htmlspecialchars()`, stat values through `htmlspecialchars((string)...)`.
- **bilan.php**: All values through `(int)` casts and `fmtNum()`.
- **historique.php**: All history values through `htmlspecialchars()`, alliance tags through `alliance()`.
- **season_recap.php**: All values through `(int)` casts, `number_format()`, or `htmlspecialchars()`.
- **attaque.php**: Defender name through `joueur()` (safe), coordinates through numeric operations, `$attaque['id']` is `(int)` cast at input.
- **messageCommun.php**: Admin-only, no DB output rendered.
- **copyright.php line 50**: Uses `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT` (good).
- **BBcode()**: Applies `htmlspecialchars()` at the start before tag parsing (line 14).

## Key Observations

1. **joueur() and alliance() helper functions**: Both apply `htmlspecialchars()` internally. Every call site passing DB `login` or `tag` columns through these functions is safe.

2. **BBcode() function**: Applies `htmlspecialchars()` as the first operation (line 14 of bbcode.php), then replaces BBcode tags with HTML. This is the correct order. All callers (`forum.php`, `sujet.php`, `messages.php`, `alliance.php`) use this correctly.

3. **couleurFormule()**: Applies `htmlspecialchars()` first, then restores only `<sub>` tags. Safe pattern.

4. **sanitizeReportHtml()**: Used for combat reports and message content -- DOMDocument-based whitelist sanitizer. Previously audited and confirmed safe.

---

## Summary

| Severity | Count | Description |
|----------|-------|-------------|
| CRITICAL | 0 | -- |
| HIGH | 4 | 2 unescaped DB columns in HTML; 2 json_encode without HEX flags in script blocks |
| MEDIUM | 4 | json_encode without HEX_TAG flags in various script contexts |
| LOW | 3 | Integer DB values without explicit (int) cast in HTML attributes |

**Total: 0 CRITICAL, 4 HIGH, 4 MEDIUM, 3 LOW**

The codebase is in good shape overall. The `joueur()`, `alliance()`, `BBcode()`, and `couleurFormule()` helper functions all perform proper escaping internally, and the vast majority of call sites use them correctly. The remaining findings are primarily defense-in-depth issues where DB values are known to be safe (server-generated or numeric) but lack explicit escaping at the output layer, and `json_encode()` calls missing `JSON_HEX_TAG` flags when embedded in `<script>` blocks.



---

## TAINT-CROSS-MODULE

Now I have sufficient data to produce the full audit report.

---

# TAINT-CROSS-MODULE Audit Report -- Pass 25

## Findings

### CRITICAL-001: comeback_shield_until read from wrong table in combat resolution

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:204` and `:503`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `game_actions.php` reads `comeback_shield_until` from the `membre` table (`SELECT vacance, estExclu, comeback_shield_until FROM membre`), but `player.php:1706` writes it to the `autre` table (`UPDATE autre SET ... comeback_shield_until = ?`). Migration `0028_add_comeback_tracking.sql` adds `comeback_shield_until` to `autre`, not `membre`. The `membre` table likely does not have this column at all -- and even if it was added there by a separate migration, it would never be updated. The result is that the comeback shield check **always reads 0** (the default or a nonexistent column), meaning attacks are never blocked by the comeback shield. If the column does not exist on `membre`, this query would produce a PHP warning and `$defStatut['comeback_shield_until']` would be `null`, which casts to `(int)0`, also failing to protect the player.
- **Impact:** The comeback shield (24h protection after returning from a long absence) is completely non-functional. Returning players can be attacked immediately despite the system awarding them a shield. This also occurs on line 503 for espionage resolution.
- **Suggested fix:** Change both queries in `game_actions.php` to read `comeback_shield_until` from `autre` instead of `membre`:
  - Line 204: `SELECT vacance, estExclu FROM membre WHERE login=?` for vacation/ban checks, plus a separate `SELECT comeback_shield_until FROM autre WHERE login=?` for shield check.
  - Alternatively, JOIN the two tables: `SELECT m.vacance, m.estExclu, a.comeback_shield_until FROM membre m JOIN autre a ON m.login = a.login WHERE m.login = ?`.

### HIGH-001: revenuEnergie() and revenuAtomeJavascript() null-dereference on missing autre row

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:27-30` and `:145-148`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** In `revenuEnergie()`, `$autreRow` is fetched from DB and aliased as `$idalliance`. The very next line does `$idalliance['idalliance'] > 0` without checking if `$autreRow` is null/false. If the `autre` row does not exist (deleted player, race condition during account deletion), this causes a PHP warning/error accessing a key on null. The same pattern repeats in `revenuAtomeJavascript()` at line 147.
- **Impact:** If `updateRessources()` or any display page calls these functions for a player whose `autre` row was just deleted (e.g., by `supprimerJoueur()` running concurrently), it triggers an uncaught error. Since these functions are called inside transactions in `updateRessources()`, the error could propagate and roll back the entire resource update.
- **Suggested fix:** Add a null guard: `if ($autreRow && $autreRow['idalliance'] > 0)` in both locations.

### HIGH-002: revenuEnergie() null-dereference on missing constructions row

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:18-20`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `revenuEnergie()` fetches `$constructions` from DB and immediately does `explode(';', $constructions['pointsCondenseur'])` without checking if `$constructions` is null. If the player's `constructions` row is missing (deleted player, concurrent deletion, or data integrity issue), this is a null dereference. The same pattern exists in `revenuAtome()` at line 104 when `$constructions` is passed as null by default.
- **Impact:** Fatal error during resource calculation. Since this is called inside the `updateRessources()` transaction, it causes a rollback and blocks resource updates for the player. If called on a page render, it produces a 500 error.
- **Suggested fix:** Add `if (!$constructions) return 0;` after the fetch at line 18, and add a null guard in `revenuAtome()` at line 101.

### HIGH-003: supprimerJoueur() does not delete email_queue or reponses_sondage rows

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:998-1041`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `supprimerJoueur()` deletes rows from 17 tables but does not delete from `email_queue` (which has a `recipient_email` column -- emails queued for the deleted player would still be sent) or `reponses_sondage` (vote records for the deleted player remain). While `email_queue` uses email addresses rather than login names, the votes in `reponses_sondage` use login.
- **Impact:** After account deletion, queued emails to the player may still be sent (minor -- would bounce). More importantly, `reponses_sondage` retains orphaned vote records referencing a nonexistent player. If the login is re-registered, the new account inherits the old account's votes, preventing them from voting (UNIQUE constraint on login+sondage).
- **Suggested fix:** Add `dbExecute($base, 'DELETE FROM reponses_sondage WHERE login=?', 's', $joueur);` to the transaction in `supprimerJoueur()`.

### HIGH-004: initPlayer cache serves stale data after concurrent combat modifies resources/molecules

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:215-229`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `initPlayer()` uses a per-request `$GLOBALS['_initPlayerCache']` to cache DB data. When `updateActions()` calls `initPlayer($joueur)` at line 25 of `game_actions.php`, all subsequent reads within that request use the cached data. However, combat resolution (`combat.php`) modifies the defender's molecules, resources, and constructions via direct DB writes. If the same request later needs fresh data for the defender (e.g., a second combat in the same `updateActions()` call, or if `initPlayer()` is called again for the defender), it gets stale cached data. While `augmenterBatiment`/`diminuerBatiment` call `invalidatePlayerCache()`, combat.php does not.
- **Impact:** If a defender is involved in multiple combats resolved in the same request (unlikely but possible with two attacks landing simultaneously), the second combat uses stale resource/molecule values from before the first combat's DB writes, leading to incorrect pillage calculations (pillaging already-pillaged resources).
- **Suggested fix:** Call `invalidatePlayerCache($actions['defenseur'])` and `invalidatePlayerCache($actions['attaquant'])` after combat.php completes (after the `include("includes/combat.php")` line in game_actions.php).

### MEDIUM-001: getResourceNodeBonus() does not validate px/py are numeric

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/resource_nodes.php:114`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `getResourceNodeBonus()` receives `$px` and `$py` from callers without validating they are integers. In `game_resources.php:59-61`, the values come from `dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', ...)`. The DB returns strings, which are then passed to `pow($px - $node['x'], 2)`. While PHP coerces strings to numbers in arithmetic, if the DB somehow returns a non-numeric value (data corruption, null column), the distance calculation would silently produce `NaN` or `0`, granting undeserved bonuses.
- **Impact:** A corrupted `membre.x` or `membre.y` value could cause incorrect resource node bonus calculations. Low probability but the defensive guard is missing.
- **Suggested fix:** Add `$px = (int)$px; $py = (int)$py;` at the top of `getResourceNodeBonus()`.

### MEDIUM-002: areFlaggedAccounts() return value not wrapped in try-catch in marche.php

- **File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php:70`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `marche.php` calls `areFlaggedAccounts($base, $_SESSION['login'], $_POST['destinataire'])` at line 70. If the `account_flags` table does not exist (e.g., migration not run) or the DB query fails for any reason, `dbFetchOne()` would return `false` and the function would return `false` (empty check on false). However, `areFlaggedAccounts()` itself does not validate its inputs -- if `$loginA` or `$loginB` are empty strings, it would execute a query matching any account pair with empty strings, potentially returning a false positive.
- **Impact:** An empty `$_POST['destinataire']` (which passes the initial `!empty()` check but could become empty after `trim()` -- unlikely given the `!empty` guard) would cause a spurious DB query. Low real-world risk due to upstream guards, but the function lacks defensive input validation.
- **Suggested fix:** Add `if (empty($loginA) || empty($loginB)) return false;` at the top of `areFlaggedAccounts()`.

### MEDIUM-003: coefDisparition() fetches molecules row even when type=1 (dead code path)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:232-234`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** In `coefDisparition()`, when `$type == 0`, the function fetches a molecule row from DB: `$donnees = dbFetchOne(...)`. But when `$type == 1` (called from `updateRessources()` for neutrino decay), `$donnees` is never set. Later at line 274, the code checks `if ($type == 0 && isset($donnees['isotope']))`, which safely skips isotope processing for type=1. However, the variable `$donnees` is used inside the `if ($type == 0)` block at line 256 without a null guard: `$nbAtomes = ... + ($donnees[$ressource] ?? 0)`. If `dbFetchOne` returns false (molecule deleted between calls), the `$donnees[$ressource]` access on false would trigger a PHP warning.
- **Impact:** If a molecule is deleted between the time `updateRessources()` fetches the molecules list and `coefDisparition()` queries for it, a PHP warning occurs. The `?? 0` fallback means `$nbAtomes` becomes 0, giving maximum decay (counterintuitively fast decay for a deleted molecule). Low real-world impact since the molecule is already gone.
- **Suggested fix:** Add `if (!$donnees) return 1.0;` after the `dbFetchOne` at line 233 (return 1.0 = no decay = safe fallback).

### MEDIUM-004: Compound bonus cache uses global variable, not request-scoped for CLI/long-running contexts

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/compounds.php:232`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** The compound bonus cache `$_compoundBonusCache` is a global variable (line 232). For standard PHP-FPM requests, this is effectively per-request because globals are reset between requests. However, `getCompoundBonus()` is called from both `game_resources.php` (during `updateRessources()`) and `combat.php` (during combat resolution) within the same request. If a compound expires between the first and second call in the same request, the cache serves stale bonus data. The cache has no TTL or timestamp validation.
- **Impact:** If `updateRessources()` caches a defense_boost for a defender, and then combat resolution reads the same cached value after the compound actually expired (within the same request), the defender gets an undeserved defense bonus. The time window is narrow (same-request) but real during long-running requests.
- **Suggested fix:** Add the current `time()` check inside `getCompoundBonus()` to skip expired entries, or invalidate the cache for a player when processing combat for them.

### MEDIUM-005: revenuAtomeJavascript() unguarded $idalliance['idalliance'] access (same as HIGH-001)

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:147`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `revenuAtomeJavascript()` fetches `$idalliance` from DB and accesses `$idalliance['idalliance']` without a null guard. This is the same pattern as HIGH-001 but in the JavaScript revenue display function. If the `autre` row is missing, this produces a warning and the page renders broken JavaScript.
- **Impact:** Broken JavaScript output on the constructions page for a player with a missing `autre` row. Merged with HIGH-001 for remediation.
- **Suggested fix:** Same as HIGH-001: `if ($idalliance && $idalliance['idalliance'] > 0)`.

### LOW-001: Static caches in formulas.php persist across multiple players in same request

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:223-226`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `coefDisparition()` uses a static cache keyed by `$joueur-$classeOuNbTotal-$type`. In `updateActions()`, when processing attacks for both attacker and defender, the static cache correctly separates entries by player. However, the `revenuEnergie()` and `revenuAtome()` functions also use static caches. If a building upgrade completes (via `augmenterBatiment`) for the same player earlier in the same `updateActions()` call, the production formulas in the static cache are stale. `invalidatePlayerCache()` does invalidate the `getSpecModifier` cache, but NOT the static caches in `revenuEnergie()` or `revenuAtome()`.
- **Impact:** After a building upgrade completes during `updateActions()`, subsequent resource display or calculation functions in the same request may show pre-upgrade values. This only affects within-request staleness; the next request will be correct. Low impact since resource calculations in `updateRessources()` happen before `updateActions()` in the typical flow.
- **Suggested fix:** Add cache invalidation for `revenuEnergie` and `revenuAtome` static caches to `invalidatePlayerCache()`, or accept this as a known minor staleness window.

### LOW-002: combat.php declares $niveauxAtt/$niveauxDef without initializing arrays first

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:53-58`
- **Domain:** TAINT-CROSS-MODULE
- **Description:** `combat.php` populates `$niveauxAtt` and `$niveauxDef` associative arrays inside a foreach loop (`$niveauxAtt[$ressource] = $niveauxAttaquant[$num]`) without initializing them as empty arrays first. If `$nomsRes` is empty (impossible in practice since it is hardcoded in config), the arrays would be undefined when accessed later. PHP tolerates this, but it is technically an "implicit array creation" code smell.
- **Impact:** None in practice. `$nomsRes` is always populated from config.php. This is a code quality observation only.
- **Suggested fix:** Add `$niveauxAtt = []; $niveauxDef = [];` before the foreach loops at lines 52 and 56.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 1     |
| HIGH     | 4     |
| MEDIUM   | 5     |
| LOW      | 2     |

**1 CRITICAL, 4 HIGH, 5 MEDIUM, 2 LOW**

The most impactful finding is **CRITICAL-001**: the comeback shield is completely broken because `game_actions.php` reads `comeback_shield_until` from the `membre` table while `player.php` writes it to the `autre` table. This means returning players are attackable despite the game telling them they have a 24-hour shield.

The HIGH findings center on null-dereference patterns where cross-module function calls (particularly `revenuEnergie` and `revenuAtomeJavascript`) access DB result arrays without checking for null returns, and a cascade incompleteness in `supprimerJoueur()`.



---

## SCHEMA-USAGE

Good! estExclu exists in migration 0102. Now I have comprehensive information. Let me generate the final audit report:

Based on my comprehensive SCHEMA-USAGE audit of "The Very Little War" codebase (Pass 25), I have identified the following findings:

---

## SCHEMA-USAGE AUDIT REPORT - PASS 25

### HIGH-001: Column Name Case Mismatch - batMax vs batmax
- **File:** includes/player.php:1514
- **Domain:** SCHEMA-USAGE
- **Description:** In the remiseAZero() season reset function, the UPDATE statement uses `batMax=1` (with capital M) but the actual schema column in autre table is defined as `batmax` (lowercase). While MySQL/MariaDB with case-insensitive collation will execute this successfully, it violates proper schema usage conventions and represents a naming mismatch between code and schema definition.
- **Impact:** Database compatibility issue; could cause runtime errors if database is migrated to case-sensitive systems (PostgreSQL, case-sensitive MySQL/MariaDB configuration). Violates column naming consistency standards.
- **Suggested fix:** Change line 1514 from `batMax=1` to `batmax=1` to match the actual schema column definition.

---

### SUMMARY
- **Total Findings:** 1
- **CRITICAL:** 0
- **HIGH:** 1
- **MEDIUM:** 0
- **LOW:** 0

### AUDIT RESULTS
The codebase demonstrates excellent schema consistency overall. Key verification results:

✓ All table structures verified - 28 tables properly defined across SQL dump and migrations
✓ All referenced columns exist in schema (pertes1/pertes2, pertesTotales, estExclu, nbDons, vieIonisateur, streak_days, comeback_shield_until, etc.)
✓ GENERATED columns properly used (pertesTotales computed from pertes1+pertes2, only read in code)
✓ Data type bindings correct (moleculesPerdues uses 'd' for DOUBLE type)
✓ Column whitelists properly maintained (columnMap in player.php for building upgrades)
✓ VARCHAR lengths validated (ALLIANCE_DESC_MAX_LENGTH:500, LOGIN_MAX_LENGTH:20)
✓ New columns from recent migrations properly integrated (migration 0102+ estExclu, 0100 nbDons, 0027/0028 streak/comeback)
✓ NULL/NOT NULL constraints respected throughout code
✓ Foreign key relationships properly referenced (alliance1/alliance2 as INT after migration 0013)

### RECOMMENDATIONS
1. Fix the single HIGH severity case mismatch on line 1514 for consistency
2. Consider adopting a database collation audit to ensure consistency across all column names
3. No blocking schema issues detected - all critical functionality properly backed by schema

**Audit Status: PASS - Safe for production with single cosmetic fix recommended**

---

**Relevant Files:**
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` - Contains the case mismatch on line 1514
- `/home/guortates/TVLW/theveryl_theverylittlewar (1).sql` - Base schema dump
- `/home/guortates/TVLW/The-Very-Little-War/migrations/` - All migration files (verified consistency)



---

## TAINT-EMAIL

I now have a comprehensive picture. Let me compile the audit findings.

---

# TAINT-EMAIL Audit Report -- Pass 25

## Audit Scope

Two `mail()` call sites exist in the production codebase:
1. **`includes/player.php:1468`** -- `processEmailQueue()` (centralized queue drain)
2. **`includes/multiaccount.php:380`** -- `sendAdminAlertEmail()` (admin-only critical alerts)

No other raw `mail()` calls exist outside documentation.

---

### CRITICAL-001: Email queue has no producer -- season reset emails are never sent

- **File:** `includes/player.php` (entire email queue system) and `migrations/0038_create_email_queue.sql`
- **Domain:** TAINT-EMAIL
- **Description:** The `email_queue` table was created (migration 0038) and `processEmailQueue()` drains it (player.php:1375-1484), but there are **zero INSERT INTO email_queue** statements anywhere in the codebase. The old synchronous `mail()` loop in basicprivatephp.php was removed and replaced with the queue architecture, but the producer side (queuing season-end notification emails) was never implemented. Season reset (`performSeasonEnd()` at player.php:1135) completes without queuing any emails. Players receive no season-end email notifications.
- **Impact:** Complete loss of season-end email notifications to all players. The entire email queue infrastructure (table, drain logic, probabilistic trigger, GDPR purge) is dead code.
- **Suggested fix:** Add an email queue population step in `performSeasonEnd()` after Phase 1d (prestige awards) and before Phase 2 (reset). Example:
  ```php
  // Phase 1e: Queue season-end notification emails
  $players = dbFetchAll($base, 'SELECT m.email, m.login FROM membre m WHERE m.email IS NOT NULL AND m.email != "" AND m.estExclu = 0');
  $seasonWinner = htmlspecialchars($vainqueurManche ?? 'Personne', ENT_QUOTES, 'UTF-8');
  $subject = 'Fin de saison — The Very Little War';
  foreach ($players as $p) {
      $body = '<html><body>Bonjour ' . htmlspecialchars($p['login'], ENT_QUOTES, 'UTF-8') . ',<br/><br/>La saison est terminée. Le vainqueur est ' . $seasonWinner . '.<br/>Une nouvelle saison commence !</body></html>';
      dbExecute($base, 'INSERT INTO email_queue (recipient_email, subject, body_html, created_at) VALUES (?, ?, ?, ?)', 'sssi', $p['email'], $subject, $body, time());
  }
  ```

---

### HIGH-001: `sendAdminAlertEmail()` subject not MIME-encoded -- non-ASCII corruption

- **File:** `includes/multiaccount.php:374,380`
- **Domain:** TAINT-EMAIL
- **Description:** The subject is CRLF-stripped (`str_replace(["\r", "\n"], '', $subject)`) on line 374, but it is NOT encoded with `mb_encode_mimeheader()` before being passed to `mail()` on line 380. The `processEmailQueue()` function correctly applies `mb_encode_mimeheader()` (player.php:1433), but `sendAdminAlertEmail()` does not. While current alert types are ASCII-only (`"[TVLW] same_ip"`, etc.), the `$body` fed into line 376 may contain login names with accented characters (validated as `[a-zA-Z0-9_]` but stored after `trim()` only). More importantly, the inconsistency means any future alert type with non-ASCII text will produce a malformed Subject header.
- **Impact:** Non-RFC-2047-compliant Subject header. Some MTAs may reject or garble the email. While current subjects are ASCII-safe, this is a latent defect.
- **Suggested fix:**
  ```php
  // Before (line 374):
  $subject = str_replace(["\r", "\n"], '', $subject);
  // After:
  $subject = mb_encode_mimeheader(str_replace(["\r", "\n"], '', $subject), 'UTF-8');
  ```

---

### HIGH-002: `processEmailQueue()` silently purges claimed-but-unsent emails after 24 hours

- **File:** `includes/player.php:1483`
- **Domain:** TAINT-EMAIL
- **Description:** After the send loop, line 1483 runs:
  ```php
  dbExecute($base, 'DELETE FROM email_queue WHERE sent_at IS NOT NULL OR (sent_at IS NULL AND created_at IS NOT NULL AND created_at <= UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR))');
  ```
  This DELETE runs on **every** invocation of `processEmailQueue()`, not just during season reset. If `mail()` fails for a row and the row is released (sent_at reset to NULL on line 1477), the row survives only until it is 24 hours old, at which point this same function deletes it -- with no logging of the permanent failure. The email is silently lost.
- **Impact:** Permanently failed emails are deleted without any record. No alert or log entry indicates that a player never received their notification. Combined with CRITICAL-001 (no emails are queued at all), this is currently unreachable but will become relevant once the producer is implemented.
- **Suggested fix:** Log a warning before deleting stale unsent rows:
  ```php
  $staleCount = dbCount($base, 'SELECT COUNT(*) FROM email_queue WHERE sent_at IS NULL AND created_at IS NOT NULL AND created_at <= UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR)');
  if ($staleCount > 0) {
      logWarn('EMAIL_QUEUE', 'Purging stale unsent emails', ['count' => $staleCount]);
  }
  ```

---

### MEDIUM-001: `processEmailQueue()` uses `@mail()` error suppression

- **File:** `includes/player.php:1468`
- **Domain:** TAINT-EMAIL
- **Description:** Line 1468: `$sent = @mail($recipient, $subject, $message, $header);` The `@` operator suppresses PHP warnings/notices emitted by `mail()`. While the return value is checked and failures are logged via `logWarn()`, the suppressed diagnostic (e.g., "Unable to connect to sendmail" or "sendmail returned failure") is lost. This makes debugging delivery issues harder in production.
- **Impact:** Reduced observability for mail delivery failures. The `logWarn()` call logs only the queue ID and a recipient hash, not the underlying MTA error.
- **Suggested fix:** Remove the `@` suppressor and let the error handler (logger.php) capture the PHP warning:
  ```php
  // Before:
  $sent = @mail($recipient, $subject, $message, $header);
  // After:
  $sent = mail($recipient, $subject, $message, $header);
  ```

---

### MEDIUM-002: `sendAdminAlertEmail()` body contains `htmlspecialchars()`-encoded login names in a `text/plain` email

- **File:** `includes/multiaccount.php:104,379`
- **Domain:** TAINT-EMAIL
- **Description:** The `$message` parameter passed to `createAdminAlert()` (and forwarded to `sendAdminAlertEmail()` as `$body`) contains login names encoded with `htmlspecialchars()` (line 104):
  ```php
  "Comptes sur la même IP: " . htmlspecialchars($login, ENT_QUOTES, 'UTF-8') . " et " . htmlspecialchars($other['login'], ENT_QUOTES, 'UTF-8') . " ($ipDisplay)"
  ```
  But `sendAdminAlertEmail()` sends the email as `Content-Type: text/plain; charset=UTF-8` (line 379). HTML entities like `&amp;` or `&lt;` will appear literally in the admin's inbox as `&amp;` rather than `&`. While login names are restricted to `[a-zA-Z0-9_]` (no special characters), the `$ipDisplay` value and future alert messages could contain characters that `htmlspecialchars()` encodes, leading to garbled plain-text emails.
- **Impact:** Cosmetic issue in admin alert emails. HTML entities rendered as literal text in plain-text context.
- **Suggested fix:** Since the email is `text/plain`, do not HTML-encode the body. The HTML encoding is appropriate for the admin_alerts DB table (displayed in the web UI) but should not be applied to the email body. Either:
  - Pass a separate plain-text version to `sendAdminAlertEmail()`, or
  - Use `html_entity_decode()` on the body before sending.

---

### MEDIUM-003: `sendAdminAlertEmail()` missing `Reply-To` header

- **File:** `includes/multiaccount.php:379`
- **Domain:** TAINT-EMAIL
- **Description:** The `processEmailQueue()` function includes both `From:` and `Reply-to:` headers (player.php:1453-1454), but `sendAdminAlertEmail()` only sets `From:` (line 379):
  ```php
  $headers = "From: " . $encodedFromName . " <" . EMAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8";
  ```
  No `Reply-To` header is set. If the admin replies to the alert email, the reply goes to the `From` address (`noreply@theverylittlewar.com`), which may not be monitored.
- **Impact:** Admin replies to alert emails go to a noreply address. Low operational impact but inconsistent with the queue-based email system.
- **Suggested fix:** Add `Reply-To` matching the queue email system:
  ```php
  $headers = "From: " . $encodedFromName . " <" . EMAIL_FROM . ">\r\n"
           . "Reply-To: " . $encodedFromName . " <" . EMAIL_REPLY_TO . ">\r\n"
           . "Content-Type: text/plain; charset=UTF-8";
  ```

---

### LOW-001: `processEmailQueue()` DELETE purge runs inside every drain invocation -- unnecessary on empty queue

- **File:** `includes/player.php:1483`
- **Domain:** TAINT-EMAIL
- **Description:** The `DELETE FROM email_queue WHERE sent_at IS NOT NULL OR ...` statement on line 1483 runs on every invocation of `processEmailQueue()`, even when no rows were processed (the function returns early on line 1409 if `claimedIds` is empty, but the purge still runs after successful processing). When the queue is empty (most of the time, especially given CRITICAL-001), this DELETE scans the table unnecessarily.
- **Impact:** Minor performance overhead -- a no-op DELETE on an indexed table is fast, but it runs on 1% of all page loads.
- **Suggested fix:** Move the DELETE inside a guard:
  ```php
  // Only purge after we actually processed something
  if (!empty($rows)) {
      dbExecute($base, 'DELETE FROM email_queue WHERE ...');
  }
  ```

---

### LOW-002: MIME boundary in `processEmailQueue()` uses 8 bytes of randomness (64 bits)

- **File:** `includes/player.php:1445`
- **Domain:** TAINT-EMAIL
- **Description:** Line 1445: `$boundary = "-----=" . bin2hex(random_bytes(8));` This produces a 16-character hex boundary with 64 bits of entropy. While `random_bytes()` is cryptographically secure, MIME boundaries are typically 70 characters or more. A short boundary increases the (extremely unlikely) chance of the boundary string appearing in the email body, which would break the MIME structure.
- **Impact:** Theoretical MIME parsing issue if the boundary collides with body content. Practically negligible given the fixed prefix and random suffix.
- **Suggested fix:** Increase to 16 bytes for 128 bits of entropy:
  ```php
  $boundary = "-----=" . bin2hex(random_bytes(16));
  ```

---

## Checklist Verification Summary

| Check | Status | Notes |
|---|---|---|
| Recipient email sourced from DB with validation on registration | PASS | `validateEmail()` uses `FILTER_VALIDATE_EMAIL` |
| Recipient CRLF-stripped before `mail()` `$to` | PASS | player.php:1426 strips `\r\n` |
| Subject CRLF-stripped AND MIME-encoded | PASS (processEmailQueue) / **FAIL** (sendAdminAlertEmail) | HIGH-001 |
| From header RFC 5322 compliant, no CRLF injection | PASS | Config constants, MIME-encoded display name |
| Reply-To header encoded | PASS (processEmailQueue) / **FAIL** (sendAdminAlertEmail) | MEDIUM-003 |
| Email body: user values escaped | PASS | Login names are `[a-zA-Z0-9_]` only (validation.php:3) |
| MIME boundary from `random_bytes()` | PASS | player.php:1445 uses `random_bytes(8)` |
| No user-controlled data in headers | PASS | Headers built from config constants only |
| queueEmail() call sites traced | N/A | **No INSERT into email_queue exists** (CRITICAL-001) |
| No raw `mail()` outside processEmailQueue | **FAIL** | `sendAdminAlertEmail()` is a second call site (admin alerts only, not user-facing) |
| Email queue schema correct | PASS | All NOT NULL columns defined; charset fixed by migration 0077 |
| `mail()` failure handled | PASS | Both call sites check return value and log |
| Content-Type charset declared | PASS | UTF-8 declared in both text/plain and text/html parts |
| Plain text fallback | PASS (processEmailQueue) / N/A (sendAdminAlertEmail, text/plain only) | |
| Attacker CRLF injection via login/winner name | NOT POSSIBLE | Login regex `^[a-zA-Z0-9_]+$` blocks all control characters |
| Email rate-limited | PASS | Queue capped at `MAX_EMAIL_QUEUE_DRAIN` (500), probabilistic 1% trigger |

---

## Summary

| Severity | Count | IDs |
|---|---|---|
| CRITICAL | 1 | CRITICAL-001 (email queue has no producer -- dead infrastructure) |
| HIGH | 2 | HIGH-001 (admin subject not MIME-encoded), HIGH-002 (silent purge of failed emails) |
| MEDIUM | 3 | MEDIUM-001 (`@mail` suppression), MEDIUM-002 (HTML entities in plain-text email), MEDIUM-003 (missing Reply-To) |
| LOW | 2 | LOW-001 (unnecessary purge on empty queue), LOW-002 (short MIME boundary) |

**Total: 1 CRITICAL, 2 HIGH, 3 MEDIUM, 2 LOW**

The most significant finding is CRITICAL-001: the entire email queue system is a consumer without a producer. The `email_queue` table, `processEmailQueue()`, the probabilistic trigger in `basicprivatephp.php`, and the GDPR purge logic are all fully implemented and correct -- but no code ever INSERTs rows into the queue. Season-end email notifications to players are completely non-functional.

**Relevant files:**
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` (lines 1135-1484: `performSeasonEnd()` + `processEmailQueue()`)
- `/home/guortates/TVLW/The-Very-Little-War/includes/multiaccount.php` (lines 365-384: `sendAdminAlertEmail()`)
- `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` (line 354: queue drain trigger)
- `/home/guortates/TVLW/The-Very-Little-War/includes/config.php` (lines 41-50: email constants)
- `/home/guortates/TVLW/The-Very-Little-War/includes/validation.php` (lines 2-3: login regex, lines 6-8: email validation)
- `/home/guortates/TVLW/The-Very-Little-War/migrations/0038_create_email_queue.sql` (queue table schema)



---

## TAINT-SESSION

I now have a complete picture. Let me compile the full audit report.

---

# TAINT-SESSION Audit Report -- Pass 25

## Session Key Map

### Complete inventory of all `$_SESSION` keys used in the codebase:

| Key | Set In | Read In | Purpose |
|-----|--------|---------|---------|
| `login` | basicpublicphp.php:82, comptetest.php:50,165, inscription.php (no set -- see note) | ~60+ files | Player identity / DB key |
| `session_token` | basicpublicphp.php:84, comptetest.php:63,166, compte.php:115, inscription.php:71 | basicprivatephp.php:35,40, api.php:26, deconnexion.php:11, voter.php:19, maintenance.php:23 | DB-backed session validation |
| `csrf_token` | csrf.php:8, basicpublicphp.php:81, comptetest.php:51,162, inscription.php:68 | csrf.php:7,18,21 | CSRF protection |
| `last_activity` | basicpublicphp.php:85, basicprivatephp.php:72, comptetest.php:53,164, inscription.php:70 | basicprivatephp.php:13, maintenance.php:9 | Idle timeout anchor |
| `session_created` | basicpublicphp.php:86, comptetest.php:52,163, inscription.php:69, admin/index.php:49, moderation/index.php:24,64, compte.php:116 | basicprivatephp.php:25, admin/index.php:40,48, admin/redirectionmotdepasse.php:17, moderation/index.php:40,63 | Absolute session lifetime |
| `_last_regeneration` | basicprivatephp.php:49 | basicprivatephp.php:47 | Session ID rotation timer |
| `mod_ip` | basicprivatephp.php:58, moderation/index.php:25 | basicprivatephp.php:56,59, moderation/mdp.php:10, moderation/index.php:67 | Moderator IP binding |
| `last_online_update` | basicprivatephp.php:109 | basicprivatephp.php:101 | Online presence throttle |
| `streak_pp_today` | basicprivatephp.php:200,205 | prestige.php:29 | Daily streak PP display |
| `streak_pp_date` | basicprivatephp.php:201,206 | basicprivatephp.php:202 | Streak date tracking |
| `streak_milestone` | basicprivatephp.php:210 | copyright.php:94-97 | One-time milestone toast |
| `comeback_bonus` | basicprivatephp.php:218 | copyright.php:99-102 | One-time comeback toast |
| `comeback_checked` | basicprivatephp.php:220 | basicprivatephp.php:215 | Once-per-session flag |
| `unread_attacks` | basicprivatephp.php:228 | basicprivatehtml.php:355-356 | Navbar badge count |
| `flash_message` | ecriremessage.php:68,94,123 | messages.php:22-24, ecriremessage.php:140-143 | Flash message (read-once) |
| `motdepasseadmin` | admin/index.php:24, moderation/index.php:23 | admin/index.php:32,40,45, admin/redirectionmotdepasse.php:5, admin/supprimercompte.php:8, moderation/index.php:32,40,49, moderation/mdp.php:5 | Admin/mod auth flag |
| `admin_ip` | admin/index.php:25 | admin/index.php:52, admin/redirectionmotdepasse.php:26 | Admin IP binding |
| `admin_last_activity` | admin/index.php:46, admin/redirectionmotdepasse.php:32, moderation/index.php:61 | admin/index.php:32, admin/redirectionmotdepasse.php:10, moderation/index.php:32 | Admin idle timeout |
| `mod_last_activity` | moderation/index.php:61 | moderation/index.php:32 | Mod idle timeout |
| `numeropartie` | historique.php:19 | historique.php:23 | Archive selection persistence |

---

## Findings

### HIGH-001: `$_SESSION['streak_milestone']` stores array, cast to `(string)` produces "Array"

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:97`
- **Domain:** TAINT-SESSION
- **Description:** In `basicprivatephp.php:210`, `$_SESSION['streak_milestone']` is set to `$streakResult`, which is an array `['streak' => int, 'pp_earned' => int, 'milestone' => bool]`. In `copyright.php:95-97`, this array value is assigned to `$msg`, then processed as `(string)$msg` inside `strip_tags(htmlspecialchars_decode((string)$msg, ENT_QUOTES))`. Casting an array to string in PHP produces the literal text "Array", so the notification always displays "Array" instead of a meaningful milestone message. The `json_encode` call wrapping the result prevents XSS (outputs `"Array"` as a JSON string), so this is not a security vulnerability but rather a functional defect affecting user-facing output.
- **Impact:** Players see "Array" in their streak milestone notification instead of a meaningful message. No security impact due to json_encode wrapping.
- **Suggested fix:**
  - Before: `$msg = $_SESSION['streak_milestone'];`
  - After: `$msg = 'Connexion jour ' . (int)($_SESSION['streak_milestone']['streak'] ?? 0) . ' ! +' . (int)($_SESSION['streak_milestone']['pp_earned'] ?? 0) . ' PP';`

### HIGH-002: `$_SESSION['comeback_bonus']` stores array, cast to `(string)` produces "Array"

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:100-102`
- **Domain:** TAINT-SESSION
- **Description:** Same pattern as HIGH-001. `checkComebackBonus()` returns an array `['applied' => true, 'energy' => int, 'atoms' => int, 'shield_hours' => int]`. This is stored in `$_SESSION['comeback_bonus']` at `basicprivatephp.php:218`. In `copyright.php:100`, `(string)$msg` casts the array to "Array". Again, `json_encode` prevents XSS but the notification text is broken.
- **Impact:** Players see "Array" in their comeback bonus notification instead of the bonus details. No security impact.
- **Suggested fix:**
  - Before: `$msg = $_SESSION['comeback_bonus'];`
  - After: `$msg = 'Bonus de retour ! +' . (int)($_SESSION['comeback_bonus']['energy'] ?? 0) . ' énergie, +' . (int)($_SESSION['comeback_bonus']['atoms'] ?? 0) . ' atomes, bouclier ' . (int)($_SESSION['comeback_bonus']['shield_hours'] ?? 0) . 'h';`

### HIGH-003: `$_SESSION['login']` used in admin/supprimercompte.php:36 but never set in admin session

- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/supprimercompte.php:36`
- **Domain:** TAINT-SESSION
- **Description:** The admin pages use session name `TVLW_ADMIN` (set in `admin/index.php:4` and `admin/redirectionmotdepasse.php:2`). The admin login flow sets `$_SESSION['motdepasseadmin']`, `$_SESSION['admin_ip']`, `$_SESSION['admin_last_activity']`, and `$_SESSION['session_created']`, but never sets `$_SESSION['login']`. However, `admin/supprimercompte.php:36` references `$_SESSION['login']` in the audit log: `logInfo('ADMIN', 'Player account deleted', ['target_login' => $loginToDelete, 'admin' => $_SESSION['login']]);`. Because `$_SESSION['login']` is never set in the `TVLW_ADMIN` session namespace, this will log `null` as the admin identifier, making audit trails useless for attributing who performed the deletion.
- **Impact:** Audit log for admin account deletion does not identify which admin performed the action. In a multi-admin scenario this breaks accountability. Also triggers PHP notice for undefined index.
- **Suggested fix:**
  - Before: `'admin' => $_SESSION['login']`
  - After: `'admin' => 'admin_panel'` (or introduce `$_SESSION['admin_user']` set during admin login)

### MEDIUM-001: `inscription.php` sets session_token and session_created but never sets `$_SESSION['login']`

- **File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php:68-73`
- **Domain:** TAINT-SESSION
- **Description:** After successful registration in `inscription.php`, the code sets `$_SESSION['csrf_token']`, `$_SESSION['session_created']`, `$_SESSION['last_activity']`, and `$_SESSION['session_token']`, then redirects to `index.php?inscrit=1`. But it never sets `$_SESSION['login']`. At `index.php`, the code checks `isset($_SESSION['login'])` (line 3) -- since it is not set, the user falls into the public (unauthenticated) path via `basicpublicphp.php`. Then `basicpublicphp.php:13-16` unsets `$_SESSION['login']` and `$_SESSION['session_token']` (both already unset), so the registration effectively leaves the player logged out. The session_token was written to the DB but is now orphaned -- the player must log in normally. This is not a security bug (no bypass), but it is a UX waste: the session_token DB write at line 72 serves no purpose since the user immediately needs to log in again.
- **Impact:** Wasted DB write on registration. Player must manually log in after registering despite session being prepared. The orphaned session_token in DB is harmless since a fresh login overwrites it.
- **Suggested fix:** Either add `$_SESSION['login'] = $loginInput;` before the redirect (to auto-login), or remove the session_token writes since they serve no purpose without `$_SESSION['login']`.

### MEDIUM-002: Deconnexion.php links are `<a href="deconnexion.php">` but logout requires POST

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:106-108`
- **Domain:** TAINT-SESSION
- **Description:** `deconnexion.php:21-24` enforces POST-only for logout (`if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }`). However, `copyright.php:106-108` still defines `function deconnexion(){ document.location.href="deconnexion.php"; }` which performs a GET request. This means the JavaScript-based logout link simply redirects to index.php without actually logging out. The user thinks they logged out but their session persists.
- **Impact:** Users who click the logout link via the JavaScript `deconnexion()` function are not actually logged out. Their session remains valid. This is a security concern on shared computers where a user believes they have logged out.
- **Suggested fix:** Change the JavaScript function to submit a POST form with a CSRF token:
  - Before: `function deconnexion(){ document.location.href="deconnexion.php"; }`
  - After: Create a hidden form with CSRF token and method POST, then submit it programmatically.

### MEDIUM-003: `$_SESSION['mod_ip']` stores raw IP address

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:58`
- **Domain:** TAINT-SESSION
- **Description:** When a moderator is detected, their raw IP address is stored in `$_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR']`. Similarly, `admin/index.php:25` stores `$_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR']`. While session data is stored server-side (not exposed to client), this stores PII (raw IP) in the session file. The rest of the codebase hashes IPs before storage (e.g., `hashIpAddress()` for `connectes` and `membre` tables), but session files written to `/tmp` or the configured session path contain the raw IP. If an attacker gains read access to session storage, they get moderator/admin IP addresses.
- **Impact:** Low confidentiality risk. Raw IPs in session files on disk. Session files are typically only readable by the web server user, but a file disclosure vulnerability or shared hosting misconfiguration could expose them.
- **Suggested fix:** This is an acceptable tradeoff for IP binding functionality -- hashing the IP would prevent meaningful comparison (since hash(IP) comparison works the same as raw comparison). Mark as acknowledged risk.

### MEDIUM-004: `historique.php` stores user-controlled `$_POST['numeropartie']` in session

- **File:** `/home/guortates/TVLW/The-Very-Little-War/historique.php:18-19`
- **Domain:** TAINT-SESSION
- **Description:** `$_POST['numeropartie']` is converted to `intval()` then stored in `$_SESSION['numeropartie']`. Later at line 23, it falls back to session or default. This is used directly in a prepared statement (`'SELECT * FROM parties WHERE id=?', 'i', $_POST['numeropartie']`). Since `intval()` produces a safe integer and the DB query uses a prepared statement, there is no injection risk. However, no range validation is performed -- an attacker could store an arbitrary integer (e.g., -1, PHP_INT_MAX) in the session, though the DB query would simply return no results.
- **Impact:** No security impact. The intval cast and prepared statement prevent injection. Stored integer is harmless.
- **Suggested fix:** No fix required. The current handling is adequate.

### LOW-001: Flash messages are properly read-once (no finding)

- **Verified:** `$_SESSION['flash_message']` is unset immediately after display in `messages.php:24` and `ecriremessage.php:142`. `$_SESSION['streak_milestone']` and `$_SESSION['comeback_bonus']` are unset after display in `copyright.php:96,101`. All flash-type session values follow read-once semantics correctly.

### LOW-002: `$_SESSION['login']` used in logger.php without existence check

- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php:36`
- **Domain:** TAINT-SESSION
- **Description:** `$login = sanitizeLogValue($_SESSION['login'] ?? 'anonymous');` uses the null coalescing operator, so it handles the undefined case gracefully. No issue.

---

## Verification of Key Security Properties

### `$_SESSION['login']` -- Re-validated against DB every page load?
**YES.** `basicprivatephp.php:39` queries `SELECT session_token FROM membre WHERE login = ? AND estExclu = 0` on every private page load. If the login does not exist, is banned, or the session_token does not match, the session is destroyed. `api.php:25-31` and `voter.php:18-19` perform equivalent checks.

### `$_SESSION['session_token']` -- DB validation on every private page?
**YES.** `basicprivatephp.php:40` performs `hash_equals($row['session_token'], $_SESSION['session_token'])` using constant-time comparison on every request. The check also verifies the token is not null/empty.

### `$_SESSION['idalliance']` -- Stale after alliance change?
**NOT USED.** Alliance membership is never stored in session. Every access queries `SELECT idalliance FROM autre WHERE login=?` fresh from the DB. This is the correct pattern.

### `$_SESSION['moderateur']` -- Cached in session?
**NOT USED.** Moderator status is never stored in session. Every check queries `SELECT moderateur FROM membre WHERE login=?` fresh from the DB (e.g., `basicprivatephp.php:54`, `sujet.php:95`, `editer.php:13`). This is the correct pattern.

### Ban detection mid-session?
**YES.** `basicprivatephp.php:39` includes `AND estExclu = 0` in the session validation query. If a player is banned mid-session, their next page load fails the check and destroys their session.

### Session fixation after login?
**YES.** `session_regenerate_id(true)` is called at login (`basicpublicphp.php:79`), visitor creation (`comptetest.php:49`), visitor-to-permanent conversion (`comptetest.php:161`), registration (`inscription.php:67`), admin login (`admin/index.php:23`), and moderation login (`moderation/index.php:22`).

### CSRF token not leaked in responses?
**YES.** `csrfField()` uses `htmlspecialchars(csrfToken())` for HTML output. The token is never exposed in URLs, logs, or error messages.

### Prepared statements for session data in DB queries?
**YES.** All uses of `$_SESSION['login']` in DB queries use prepared statements with `'s'` type binding throughout the codebase.

### Session data HTML-escaped?
**YES.** `basicprivatehtml.php:303` uses `htmlspecialchars($_SESSION['login'], ENT_QUOTES, 'UTF-8')` and `urlencode($_SESSION['login'])` for HTML and URL contexts respectively. All other session value outputs follow the same pattern.

### No sensitive data in session?
**MOSTLY.** No passwords or email addresses are stored in session. Raw IPs are stored for moderator/admin IP binding (`$_SESSION['mod_ip']`, `$_SESSION['admin_ip']`) which is an acceptable tradeoff (MEDIUM-003).

### No client-controlled session data?
**YES.** All session data is either generated server-side (tokens, timestamps) or derived from authenticated DB state. No session values come from `$_GET` or `$_POST` except `$_SESSION['numeropartie']` which is intval-cast.

### Admin session IP binding?
**YES.** Both admin (`admin/index.php:52`, `admin/redirectionmotdepasse.php:26`) and moderation (`moderation/index.php:67`, `moderation/mdp.php:10`) sessions enforce IP binding with `hash_equals()` timing-safe comparison.

### Privilege escalation via session manipulation?
**NO.** `$_SESSION['motdepasseadmin']` is only set after `password_verify()` against `ADMIN_PASSWORD_HASH`. There is no code path where form input can set this value. `$_SESSION['login']` is only set after successful authentication. The admin and moderator sessions use separate session namespaces (`TVLW_ADMIN`, `TVLW_MOD`) from player sessions (`TVLW_SESSION`).

---

## Summary

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | 0 | -- |
| HIGH | 3 | H-001, H-002, H-003 |
| MEDIUM | 4 | M-001, M-002, M-003, M-004 |
| LOW | 0 | -- |

**Total: 0 CRITICAL, 3 HIGH, 4 MEDIUM, 0 LOW**

The session architecture is fundamentally sound. The DB-backed session_token validation on every private page load, ban detection via `estExclu = 0`, periodic session ID regeneration, and separate session namespaces for admin/mod/player are all best-practice implementations. The two highest-impact findings (H-001, H-002) are functional defects (array-to-string cast producing "Array" in notifications) rather than security vulnerabilities. H-003 is an accountability gap in admin audit logging. M-002 (logout via GET silently failing) is the most security-relevant finding, as it leaves sessions alive when users believe they have logged out.



---

## FLOW-REGISTRATION

I now have sufficient information for a comprehensive audit report. Let me compile the findings.

---

## FLOW-REGISTRATION End-to-End Audit — Pass 25

---

### HIGH-001: Registration redirects to index.php (not logged in), but login then skips tutoriel.php — new players land on constructions.php with no onboarding prompt

- **File:** includes/basicpublicphp.php:96
- **Domain:** FLOW-REGISTRATION
- **Description:** After successful registration, `inscription.php` redirects to `index.php?inscrit=1` (line 73) with no active session. The player then submits the login form. On successful authentication, `basicpublicphp.php` redirects directly to `constructions.php` (line 96), not `tutoriel.php`. The `niveaututo` for a brand-new account defaults to `1` (DB default confirmed in `base_schema.sql:72`). The legacy tutorial system in `basicprivatehtml.php` requires `?suivreTuto` GET parameter at `niveaututo=1` to advance to step 2 (line 62); without it the inline tutorial never starts. The new `tutoriel.php` module exists but there is no redirect to it on first post-registration login. A new player who logs in and lands on `constructions.php` will see no onboarding nudge unless they navigate to tutoriel.php manually.
- **Impact:** New players receive no guided onboarding after registration. `niveaututo=1` stays at 1 indefinitely if the player never navigates to a page that fires `?suivreTuto`. Tutorial mission rewards are never claimed, increasing early churn.
- **Suggested fix:** In `basicpublicphp.php`, after successful login, check `niveaututo` from `autre` and redirect new players (`niveaututo == 1`) to `tutoriel.php` instead of `constructions.php`.

---

### HIGH-002: comptetest.php visitor conversion does not update `player_compounds`, `login_history`, `account_flags` tables — login references remain stale after rename

- **File:** comptetest.php:131–158
- **Domain:** FLOW-REGISTRATION
- **Description:** The visitor-to-permanent conversion transaction at lines 116–159 renames the login in 22 tables. However it is missing `UPDATE` statements for `player_compounds`, `login_history`, and `account_flags` — all three tables store the player login as a text key. Compare to `supprimerJoueur()` at player.php:1033–1036 which `DELETE`s from all three. After a visitor converts, `player_compounds` rows (if any compound was synthesised during the visitor session) will still reference the old `Visiteur*` login; `getCompoundBonus()` will return zero for all compounds because the lookup is by login. Similarly, `login_history` and `account_flags` rows from the visitor session become orphaned and referencing the wrong login.
- **Impact:** Compound bonuses silently lost on conversion. Login-history audit trail broken. Account-flag moderation state orphaned.
- **Suggested fix:** Add to the conversion transaction: `UPDATE player_compounds SET login = ? WHERE login = ?`, `UPDATE login_history SET login = ? WHERE login = ?`, `UPDATE account_flags SET login = ? WHERE login = ?` (and `related_login`), mirroring the `supprimerJoueur` DELETE list.

---

### HIGH-003: `updateLoginStreak()` awards `STREAK_REWARD_DAY_1` PP on the very first ever login — new player never had a yesterday entry, so `$lastDate` is NULL, falls through to `$currentStreak = 1`, and PP is granted unconditionally

- **File:** includes/player.php:1614–1631
- **Domain:** FLOW-REGISTRATION
- **Description:** When a brand-new player loads their first private page, `updateLoginStreak()` is called. `streak_last_date` in `autre` is `NULL` (not set by `inscrire()`). At line 1614 the code checks `if ($lastDate === $yesterday)` — NULL never equals a date string, so it falls to the `else` branch at line 1617: `$currentStreak = 1`. The code then reaches line 1629 where `elseif ($currentStreak >= 1)` is always true, so `$ppEarned = STREAK_REWARD_DAY_1` is set and PP is written to the `prestige` table. The player receives prestige points on account creation without any actual login-streak behaviour, and gets a false `pp_earned > 0` notification banner. On their second login the following day, they correctly increment to streak 2.
- **Impact:** Every new account receives a free `STREAK_REWARD_DAY_1` PP grant on first login, which may be exploited by repeated account creation (circumventing the anti-multiaccount goal) and distorts prestige progression metrics.
- **Suggested fix:** In `inscrire()`, initialise `streak_last_date` to `date('Y-m-d')` in the `INSERT INTO autre` statement. This marks today as already counted, so the first `updateLoginStreak()` call sees `$lastDate === $today` and returns `pp_earned=0`. Alternatively add a guard in `updateLoginStreak()`: if `$lastDate === null`, set streak to 1, update the date, but do **not** award PP.

---

### MEDIUM-001: `inscrire()` uses comma (`,`) as `timeMolecule` separator but the DB default is `'0,0,0,0'` — however the season-reset `CONCAT` also uses comma, so formats match; the real problem is that the DB default `'0,0,0,0'` survives if `inscrire()` is ever bypassed, producing epoch-0 timestamps that cause MAX_OFFLINE_SECONDS capping on first resource tick

- **File:** includes/player.php:55, includes/player.php:98–101
- **Domain:** FLOW-REGISTRATION
- **Description:** `inscrire()` correctly sets `timeMolecule` to four comma-separated `$now` values. However, the `INSERT INTO autre` statement does not include `niveaututo` in the column list (line 98), relying on the DB default of `1`. The integration test fixture `base_schema.sql:72` confirms `DEFAULT 1`, so this is safe in production. More concerning: `tempsPrecedent` is set to `$now` at line 101, but the `updateRessources()` function calculates `$nbsecondes = time() - $donnees['tempsPrecedent']`. On the very first page load, `basicprivatephp.php` calls `updateRessources()` at line 138, which runs approximately 0–3 seconds after registration completes. This produces `$nbsecondes < 1`, hits the early-return guard at line 202–203 (`if ($nbsecondes < 1) return;`), and exits cleanly. **No production tick is applied on first login.** The player sees STARTING_ENERGY=64 and STARTING_ATOMS=64, but revenue is zero until a second page load. This is technically correct but means the displayed income on the first page load is stale — a minor UX gap, not a bug.
- **Impact:** Low: no data corruption, only cosmetic. First page load shows 0 earned since last connection.
- **Suggested fix:** This is a cosmetic/UX issue. If desired, set `tempsPrecedent = $now - 60` in `inscrire()` so that the first updateRessources tick produces one minute of initial production.

---

### MEDIUM-002: Post-registration session in `inscription.php` sets `$_SESSION['login']` and `session_token` but then immediately redirects to `index.php?inscrit=1` which calls `basicpublicphp.php` — that file **unsets** `$_SESSION['login']` and `session_token` at lines 14–16, silently logging the player out before they reach the login form

- **File:** includes/basicpublicphp.php:13–16, inscription.php:67–73
- **Domain:** FLOW-REGISTRATION
- **Description:** After `inscrire()` succeeds, `inscription.php` sets `$_SESSION['login']`, `$_SESSION['session_token']`, `$_SESSION['session_created']`, `$_SESSION['last_activity']`, and writes the token to the DB (lines 67–72), then issues `header("Location: index.php?inscrit=1"); exit`. On the next request `index.php` loads `basicpublicphp.php` because `$_SESSION['login']` is set — wait, actually `index.php` lines 3–7 check `isset($_SESSION['login'])` and loads `basicprivatephp.php` if set. **But**, the session set in inscription.php is done after `session_regenerate_id(true)` which is correct. If `index.php` sees the session and loads `basicprivatephp.php` the player would land in the authenticated area without needing to log in again, which is fine. However, the `index.php` flow displays the login form (line 30: `if (!isset($_SESSION['login']))`) and the authenticated content. The `?inscrit=1` message only appears if `$_SESSION['login']` is NOT set. If the player IS logged in after inscription, the `$information` variable is set regardless (line 13), and layout.php will display it. This means the flow is: registration sets session → redirect to index.php → basicprivatephp.php fires → player is already logged in and sees the authenticated dashboard with the "Vous avez bien été inscrit" message. **This is actually correct.** However, the UX issue is that the player lands on `index.php` rather than `constructions.php` or `tutoriel.php`, and there is no explicit redirect to the game. See HIGH-001 for the onboarding impact.
- **Impact:** Low severity as implemented. Newly registered player is logged in and sees the homepage, not the game. Combined with HIGH-001, no tutorial prompt appears.
- **Suggested fix:** In `inscription.php`, after successful registration, redirect to `tutoriel.php` instead of `index.php?inscrit=1`, since the session is already established. Remove the `?inscrit=1` mechanism and display the success message on tutoriel.php.

---

### MEDIUM-003: `comptetest.php` visitor creation calls `inscrire("Visiteur" . $visitorNum, ...)` where `$visitorNum` is the value AFTER the `LAST_INSERT_ID()+1` increment — visitor account name is `Visiteur(N-1)` but the row uses `Visiteur(N)` login — off-by-one between the incremented counter and the created login

- **File:** comptetest.php:30–33
- **Domain:** FLOW-REGISTRATION
- **Description:** Line 30: `UPDATE statistiques SET numerovisiteur = LAST_INSERT_ID(numerovisiteur) + 1`. Line 31: `SELECT LAST_INSERT_ID() AS num` returns the new value (after +1). Line 33: `$log = 'Visiteur' . ($visitorNum - 1)` — this is the login that is **checked for staleness**. Line 48: `inscrire("Visiteur" . $visitorNum, ...)` — this creates a **different** account (`$visitorNum`, the post-increment value). Then line 50: `$_SESSION['login'] = ucfirst(mb_strtolower("Visiteur" . $visitorNum))`. The stale-check at line 41 locks `Visiteur(N-1)` but creates `Visiteur(N)`. The staleness guard (which checks if the previous visitor is stale enough to recycle the slot) is checking the wrong row — it checks `Visiteur(N-1)` to decide whether to create `Visiteur(N)`. If `Visiteur(N-1)` is active, creation is blocked, but `Visiteur(N)` is a new unused slot and could be created regardless. The intent of checking `$log` (the pre-increment login) appears to be a same-slot recycling pattern, but the logic is using two different visitor numbers: `N-1` for the guard and `N` for the INSERT. In practice this means the staleness check does not protect the slot being created; it protects the previous slot.
- **Impact:** Medium: under concurrent load, two requests could both increment the counter to adjacent values, both check different previous-slot rows, and both succeed in creating new visitors. More visitor accounts are created than expected when `Visiteur(N-1)` is stale but `Visiteur(N)` is fresh. The guard is ineffective for its stated purpose.
- **Suggested fix:** Check the staleness of `"Visiteur" . $visitorNum` (the login that will actually be created), not `$visitorNum - 1`. The guard should be: `SELECT timestamp FROM membre WHERE login = 'Visiteur{$visitorNum}' FOR UPDATE` to determine whether the new slot already exists and is still active.

---

### MEDIUM-004: `inscrire()` does not set `streak_last_date` or `streak_days` in the `INSERT INTO autre` — these columns are added by migration and have no DB default, relying on NULL/0 defaults

- **File:** includes/player.php:96–102
- **Domain:** FLOW-REGISTRATION
- **Description:** The `INSERT INTO autre` statement omits `streak_days` and `streak_last_date` (added in migration 0027). The columns appear to have DB defaults (`streak_days INT DEFAULT 0`, `streak_last_date DATE DEFAULT NULL`), so the INSERT succeeds. But `streak_last_date = NULL` causes the `updateLoginStreak()` function to treat every new player as a "returning" user on their first login (see HIGH-003). This is the root cause of that defect. No explicit initialisation is provided in `inscrire()`.
- **Impact:** See HIGH-003 for the PP-award consequence.
- **Suggested fix:** Add `streak_last_date = CURDATE()` and `streak_days = 0` (or pass them in the INSERT) in `inscrire()` to mark the registration day as already counted.

---

### MEDIUM-005: `tutoriel.php` uses `$constructions` variable directly at line 29 and line 52 (e.g. `$constructions['generateur'] >= 2`) but `$constructions` is populated by `initPlayer()` inside `basicprivatephp.php` — if `initPlayer()` cache is populated before the `updateActions()` call completes in `basicprivatephp.php`, stale `constructions` data could be used for mission condition checks

- **File:** tutoriel.php:29, 52, 74; includes/basicprivatephp.php:87, 143–146
- **Domain:** FLOW-REGISTRATION
- **Description:** `basicprivatephp.php` calls `initPlayer()` at line 87, then `updateRessources()` at line 138, then `updateActions()` at line 143, then **invalidates the cache** and calls `initPlayer()` again at lines 145–146. `tutoriel.php` then uses `$constructions` which was populated by this second `initPlayer()` call. This sequence is correct. However, `tutoriel.php` defines mission conditions using `$constructions` at module load time (lines 29, 52, etc.) before `csrfCheck()` and the POST claim handler. If `updateActions()` completes a building upgrade between `initPlayer()` at line 87 and the second `initPlayer()` at line 146, the conditions will correctly reflect the upgrade. The `verify_db` closures inside each mission provide a DB-backed re-check inside the claim transaction, so the claim logic is safe. The only risk is a false-negative display: a building that completes between lines 87 and 146 would show as incomplete in `$constructions` but be correctly validated on claim. This is a cosmetic inconsistency, not a data integrity issue.
- **Impact:** Low: a player might see Mission 1 as "En cours" when the building actually completed milliseconds before, requiring a page refresh to see "Prete a valider". The claim itself is transactionally correct.
- **Suggested fix:** Move the `$constructions` reads for mission conditions to after the second `initPlayer()` call, or reuse the `verify_db` DB check for display conditions. (Low priority.)

---

### LOW-001: `inscription.php` login-duplicate check at line 53 (`SELECT COUNT(*) FROM membre WHERE login = ?`) is a non-atomic pre-check and races with the `inscrire()` UNIQUE constraint check — the pre-check and `inscrire()` are not within the same transaction

- **File:** inscription.php:53–58
- **Domain:** FLOW-REGISTRATION
- **Description:** Lines 49–53 perform two sequential `SELECT COUNT(*)` queries (email, then login) outside any transaction. A concurrent registration could insert the same login between the count query returning 0 and the `inscrire()` call. This is acknowledged in the code comment at line 45 ("early-exit optimisation only"), and `inscrire()` correctly handles the DB UNIQUE constraint by catching `errno 1062` and returning `'login_taken'` / `'email_taken'`. The pre-check is redundant and the fallback is correct. However the redundant SELECT is misleading: the `if ($nbLogin == 0)` guard at line 55 calls `inscrire()` only when the count is 0, so if the concurrent INSERT happens after the count but before `inscrire()`, the DB UNIQUE constraint fires and is correctly handled. No data integrity issue exists.
- **Impact:** Low. The only risk is a slightly confusing error path (the outer count says 0, inscrire() returns 'login_taken'). The error message displayed to the user is correct in all cases.
- **Suggested fix:** Remove the redundant `$nbLogin` SELECT (lines 53–55) and directly call `inscrire()`, relying solely on the UNIQUE constraint handling that is already implemented. This simplifies the code and eliminates the window.

---

### LOW-002: After visitor conversion in `comptetest.php`, there is no call to `updateLoginStreak()` or streak initialisation — the converted account inherits `streak_last_date = NULL` from the visitor's `autre` row and will receive a free PP grant on the next login (same as HIGH-003 for direct registration)

- **File:** comptetest.php:158 (`UPDATE autre SET niveaututo = 8`)
- **Domain:** FLOW-REGISTRATION
- **Description:** The conversion transaction updates `niveaututo` to 8 but does not set `streak_last_date` to today or `streak_days` to 0. The visitor account's `autre` row was inserted by `inscrire()` which also did not initialise these fields (see MEDIUM-004). After conversion, the first login as the permanent account will trigger `updateLoginStreak()` with `$lastDate = NULL`, awarding `STREAK_REWARD_DAY_1` PP.
- **Impact:** Same as HIGH-003 for the visitor-conversion path. Minor PP inflation.
- **Suggested fix:** Add `streak_last_date = CURDATE(), streak_days = 0` to the `UPDATE autre SET niveaututo = 8` statement in the conversion transaction.

---

### LOW-003: `inscription.php` rate-limiter check (line 12) uses `RATE_LIMIT_REGISTER_MAX` and `RATE_LIMIT_REGISTER_WINDOW` but the per-IP counter is shared with the visitor creation rate limiter key `'visitor_reg'` — different keys, so no sharing conflict — but there is no per-email or per-login rate limit, allowing an attacker to enumerate usernames/emails by timing the duplicate check vs. rate limit responses

- **File:** inscription.php:12, comptetest.php:25
- **Domain:** FLOW-REGISTRATION
- **Description:** The rate limiter key for registration is `'register'` (inscription.php) and for visitor creation `'visitor_reg'` (comptetest.php). These are independent counters. The registration flow performs two DB queries before calling `inscrire()`: one for email uniqueness (line 49) and one for login uniqueness (line 53). Both happen before the rate limiter is checked inside `inscrire()`. An attacker making fewer than `RATE_LIMIT_REGISTER_MAX` requests per window can submit registration attempts to enumerate whether emails or logins exist (timing-based or error-message-based). The error messages at lines 51 and 82 ("Ce login ou email est déjà utilisé") are intentionally vague for email, but the login check at line 82 says "Ce login est déjà utilisé" — this confirms login existence to an attacker under the rate limit.
- **Impact:** Low: username enumeration is possible for a patient attacker (limited to 3 probes per `RATE_LIMIT_REGISTER_WINDOW`). Not exploitable for mass enumeration.
- **Suggested fix:** Merge the login error message with the email error message to use the same vague wording ("Ce login ou email est déjà utilisé"). Already done for email (line 51) but not for login at line 82. This is a UX privacy improvement.

---

## Summary

**1 CRITICAL, 3 HIGH, 3 MEDIUM, 3 LOW**

Wait — re-examining the severity: none of the issues causes a complete registration failure, data loss, or security bypass of the authentication system. Let me restate the summary accurately:

| Severity | Count | IDs |
|----------|-------|-----|
| HIGH | 3 | HIGH-001, HIGH-002, HIGH-003 |
| MEDIUM | 4 | MEDIUM-001, MEDIUM-002, MEDIUM-003, MEDIUM-004 |
| LOW | 3 | LOW-001, LOW-002, LOW-003 |

**0 CRITICAL, 3 HIGH, 4 MEDIUM, 3 LOW**

**Key actionable issues by priority:**

1. **HIGH-002** (comptetest.php visitor conversion missing 3 table renames) is the most concrete data-integrity defect: compound bonuses silently vanish and moderation state becomes orphaned after visitor conversion.

2. **HIGH-003 / MEDIUM-004** (streak NULL init → free PP on first login) is a game-economy defect that affects every newly registered account and every visitor conversion (LOW-002). Fix is a single-line change in `inscrire()`.

3. **HIGH-001** (post-login redirect skips tutoriel.php) is a UX/retention defect: new players never see the tutorial unless they navigate there manually.

4. **MEDIUM-003** (comptetest.php off-by-one in visitor slot guard) is a logic error where the staleness check guards the wrong visitor slot, allowing more concurrent visitor accounts than intended.

**Relevant files:**
- `/home/guortates/TVLW/The-Very-Little-War/inscription.php`
- `/home/guortates/TVLW/The-Very-Little-War/comptetest.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
- `/home/guortates/TVLW/The-Very-Little-War/tutoriel.php`



---

## FLOW-COMBAT

I'll systematically read all the relevant files to conduct a thorough audit of the FLOW-COMBAT end-to-end cycle.



---

## FLOW-ALLIANCE

I now have a complete picture of the alliance lifecycle. Here is the full audit report.

---

## Pass 25 — FLOW-ALLIANCE Audit Report

### HIGH-001: War declaration has no minimum-member guard
- **File:** allianceadmin.php:485-558
- **Domain:** FLOW-ALLIANCE
- **Description:** Any alliance with a single player (the chef alone) can declare war on a large enemy alliance. There is no `COUNT(membres) >= N` check before inserting into `declarations`. A one-person alliance can legally be in a perpetual war state, block itself from dissolving (war active guard), and never suffer meaningful losses, while harassing an opponent's morale score.
- **Impact:** Logic / game-balance abuse. A grief player creates an alliance, declares war, and cannot be dissolved (M-006 guard fires), but also cannot lose the war since the war-end requires a *manual* submission by either side.
- **Suggested fix:** Inside the war-declaration transaction, count active members (`SELECT COUNT(*) FROM autre WHERE idalliance=? FOR UPDATE`) and throw if the count is below a configurable constant `WAR_MIN_MEMBERS` (suggested: 2). Apply the same guard on the receiving side so skeleton alliances cannot be war targets (their chef's ghost-alliance guard is weaker).

---

### HIGH-002: `nbMax` column fetched from `alliances` but never used — member-cap enforcement falls back on global constant only
- **File:** alliance.php:248
- **Domain:** FLOW-ALLIANCE
- **Description:** The join-acceptance path reads `SELECT id, nbMax FROM alliances WHERE id=? FOR UPDATE` and stores the result in `$allianceLocked`, but the member-cap check at line 253-255 uses `$joueursEquipe` (the global constant `MAX_ALLIANCE_MEMBERS`), not `$allianceLocked['nbMax']`. The `alliances` schema (base_schema.sql) does not even include an `nbMax` column — it was never added. The query therefore selects a non-existent column, which in PHP/MySQLi silently returns `null` rather than throwing an error. This means the cap is enforced correctly only by accident (the global constant path is correct), but the dead column reference constitutes a schema contract bug and indicates the per-alliance cap feature was intended but never implemented. If a future migration adds `nbMax`, the per-alliance cap would silently override the global one with potentially `null` (0), causing all joins to fail.
- **Impact:** Latent logic error; schema mismatch. If `nbMax` is ever added with a default of 0, all alliance joins break silently.
- **Suggested fix:** Remove `nbMax` from the SELECT. Enforce cap solely via `MAX_ALLIANCE_MEMBERS`. If per-alliance caps are desired in the future, create a migration that adds the column with a proper NOT NULL DEFAULT, and update the cap check to use `$allianceLocked['nbMax'] ?? MAX_ALLIANCE_MEMBERS`.

---

### HIGH-003: `supprimerAlliance()` is called from inside `withTransaction()` in `allianceadmin.php`, which itself opens a nested transaction via `supprimerAlliance()`
- **File:** includes/player.php:982, allianceadmin.php:62-76
- **Domain:** FLOW-ALLIANCE
- **Description:** The dissolution path in `allianceadmin.php` (line 62) calls `withTransaction(...)`, and inside that closure it calls `supprimerAlliance($dissolveAlliId)` (line 75). `supprimerAlliance()` itself opens a second `withTransaction()` at player.php:982. In MariaDB/InnoDB, `START TRANSACTION` issued while already inside a transaction implicitly commits the outer transaction first (autocommit semantics apply). This means:
  1. The outer transaction's `SELECT chef ... FOR UPDATE` lock is committed before `supprimerAlliance()` runs.
  2. The re-verification of chef status is committed as a standalone transaction, no longer part of the same atomic unit as the DELETE/UPDATE cascade.
  3. A concurrent chef-transfer request could slip in between the two commits and transfer leadership; the dissolution then proceeds on a stale lock, orphaning a newly-installed chef.
- **Impact:** TOCTOU race: if chef A dissolves while chef B is concurrently being transferred leadership, the dissolution may succeed after the transfer, orphaning the new chef.
- **Suggested fix:** Extract the body of `supprimerAlliance()` into a helper that accepts the `$base` connection and performs no transaction management. The outer `withTransaction()` in `allianceadmin.php` then calls the helper directly. Alternatively, detect the active transaction via `$base->autocommit` before calling `withTransaction()` inside `supprimerAlliance()` and skip the inner `START TRANSACTION` if one is already open. The outer call in `allianceadmin.php` (lines 62-76) is the authoritative transaction boundary.

---

### HIGH-004: War-end permission gate does not re-verify the actor's `guerre` bit inside the transaction
- **File:** allianceadmin.php:560-606
- **Domain:** FLOW-ALLIANCE
- **Description:** The war-end path (`$_POST['adversaire']`) checks `if ($guerre)` at line 485, where `$guerre` is a PHP variable set from the grade bits read at page boot (lines 29-44). This grade check is performed outside the `withTransaction()` block. Inside the transaction (lines 568-601), the code only locks the war row and the opposing alliance row — it never re-reads the actor's grade to confirm the `guerre` permission bit still holds. If the chef revokes the actor's grade between page load and form submission, the officer retains the ability to end the war for that request.
- **Impact:** Privilege bypass: a revoked officer can end an active war using a stale grade check.
- **Suggested fix:** Inside the `withTransaction()` closure at line 568, add a `SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE` fetch for the actor. Re-derive the `$guerre` bit from this locked row and throw `PERMISSION_REVOKED` if it is false. The validerpacte.php path (lines 23-30) already does this correctly with `FOR UPDATE` on the grade row and can serve as a template.

---

### MEDIUM-001: War-declaration duplicate check covers active pacts (`valide!=0`) but does NOT block a war declaration when a pending (unaccepted, `valide=0`) pact already exists in BOTH directions
- **File:** allianceadmin.php:513-518
- **Domain:** FLOW-ALLIANCE
- **Description:** The duplicate check query is:
  ```sql
  SELECT COUNT(*) FROM declarations
  WHERE ((fin=0 AND type=0) OR (type=1 AND valide!=0))
  AND ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?))
  ```
  The `valide!=0` condition for pacts correctly blocks war if an active pact exists. However a pact row in state `type=1, valide=0` (pending, not yet accepted) is NOT caught by this check. This creates an asymmetry: if Alliance A sent a pact proposal to Alliance B that B hasn't accepted yet, Alliance A can simultaneously declare war on B. The war declaration code does clean up pending pacts (lines 541-542), but the race exists: between the duplicate check and the cleanup `DELETE`, another session could accept the pending pact, setting `valide=1`. The DELETE at line 541 uses `fin=0 AND valide=0`, so an accepted pact (`valide=1`) would survive. The result is an alliance that simultaneously holds an active pact AND an active war with the same opponent.
- **Impact:** Logic error / data integrity: active war + active pact coexist. Combat checks use `type=1 AND valide!=0` so the attack-blocking pact check would fire even during an active declared war, paradoxically making alliance members immune to attack while the war is ongoing.
- **Suggested fix:** In the duplicate check (line 513-514), change the pact condition from `(type=1 AND valide!=0)` to `(type=1 AND fin=0)` to include pending pacts. The pending-pact cleanup DELETEs (lines 541-542) already handle `fin=0 AND valide=0`; add a second DELETE covering `fin=0 AND valide=1` (accepted pacts) or change to `fin=0` without the `valide` filter to delete all non-expired pact rows before inserting the war.

---

### MEDIUM-002: `supprimerAlliance()` does not delete `attack_cooldowns` for the opponent side of active wars — orphaned cooldown rows survive dissolution
- **File:** includes/player.php:979-996
- **Domain:** FLOW-ALLIANCE
- **Description:** `supprimerAlliance()` iterates over members and deletes `attack_cooldowns` where `attacker = member OR defender = member` (lines 984-987). It then deletes the `declarations` row (line 993). However, `attack_cooldowns` rows where the *opponent's* players attacked members of the dissolving alliance still reference the now-deleted players by login string. These rows are not cleaned up. While this is low-impact today (cooldowns expire by timestamp), the ghost rows grow indefinitely and could interfere with future cooldown logic if a new player reuses a login.
- **Impact:** Data leak / unbounded table growth. Marginal attack timing edge cases if login reuse occurs.
- **Suggested fix:** After `DELETE FROM grades` (line 994), add `DELETE FROM attack_cooldowns WHERE attacker IN (SELECT login FROM autre WHERE idalliance=? FOR SHARE) OR defender IN (SELECT login FROM autre WHERE idalliance=? FOR SHARE)` **before** the `UPDATE autre SET idalliance=0` resets membership. Since `supprimerAlliance()` already fetches member logins in `$members` (lines 984-986), reuse that list with a single parameterized `DELETE ... WHERE attacker IN (...)` using `implode`,`,`,$logins)` safely — or better yet, clean up using the pre-collected `$members` loop.

---

### MEDIUM-003: Pact-break action deletes ALL declarations of `type=1` between the two alliances — including pending (unaccepted) ones — without notifying the initiating alliance
- **File:** allianceadmin.php:465
- **Domain:** FLOW-ALLIANCE
- **Description:** The pact-break DELETE at line 465 is:
  ```sql
  DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1
  ```
  There is no `valide` filter. If Alliance A proposed a pact to Alliance B (`valide=0`), and Alliance B separately breaks a different active pact with Alliance A, this DELETE also removes the pending proposal. The pact-proposer (A's chef) receives no notification that their proposal was silently deleted.
- **Impact:** Lost data / confusing UX: a pending pact proposal disappears from the proposer's view without any feedback or error.
- **Suggested fix:** Narrow the DELETE to `AND valide!=0` (active pacts only) when the intent is to break an active pact. To handle pending proposals separately, add an explicit check: if `valide=0` rows also exist, send a cancellation report to the proposer's chef. Alternatively, add `AND valide!=0` to the break-DELETE and add a separate step to cancel pending proposals with a notification, mirroring the pattern already used in the war-declaration path (lines 523-542).

---

### MEDIUM-004: Race condition — simultaneous invitation acceptance by two different players can exceed the alliance member cap by one
- **File:** alliance.php:248-255
- **Domain:** FLOW-ALLIANCE
- **Description:** The member-cap check inside the accept-invite transaction correctly uses `FOR UPDATE` on the `alliances` row (line 248) and then counts members (line 253). However the count query at line 253 is:
  ```sql
  SELECT COUNT(*) AS nb FROM autre WHERE idalliance=?
  ```
  This is a separate SELECT executed after the `alliances` row lock. Two concurrent transactions, T1 and T2, that both lock the `alliances` row in succession will each see a count of N (before either has committed their UPDATE), allowing both to pass the `< MAX_ALLIANCE_MEMBERS` check. The `FOR UPDATE` on `alliances` serialises the two transactions, but the COUNT on `autre` is a snapshot read that does not include uncommitted changes from the other transaction (under InnoDB default REPEATABLE READ isolation). Once T1 commits and increments `idalliance`, T2's count still sees the pre-T1 value.
- **Impact:** Alliance overflow by 1 when two invitations are accepted nearly simultaneously.
- **Suggested fix:** Change the `autre` count to use `FOR SHARE` (or `FOR UPDATE`) to force a current read: `SELECT COUNT(*) AS nb FROM autre WHERE idalliance=? LOCK IN SHARE MODE`. This forces a lock-wait behind T1 if T1 has an uncommitted `UPDATE autre` on any row with that `idalliance`, giving T2 the accurate post-T1 count when it proceeds.

---

### MEDIUM-005: `alliance_discovery.php` exposes the internal numeric `id` of each alliance in the rendered HTML
- **File:** alliance_discovery.php:63-76
- **Domain:** FLOW-ALLIANCE
- **Description:** The query at line 14 selects `a.id` and the rendered table at line 65 compares `(int)$row['id'] === $playerAllianceId` and outputs the `id` value in the inline `rowStyle` attribute. While the style attribute is HTML-escaped and the comparison is purely internal, the raw numeric alliance primary key is also indirectly observable because the generated `rowStyle` string contains `rgba(160,160,160,0.6)` only for the player's own alliance. An attacker can enumerate which numeric `id` corresponds to their alliance by inspecting the highlighted row. More directly, the `$row['id']` is passed to `joueur()` and `alliance()` helper functions which may embed it in `href` attributes — confirming the private integer PK in page source. The PK itself is low-sensitivity since alliance tags are public, but exposing the PK enables direct DB-ID based attacks on any future admin endpoints that accept `id` without validation.
- **Impact:** Low information disclosure. Integer PK is not a secret, but defense-in-depth prefers tag-based (opaque string) references.
- **Suggested fix:** Replace internal-ID usage in the row highlight check with a tag comparison: `$row['tag'] === $playerAllianceTag`. Do not select `a.id` unless it is needed for a functional purpose. The `alliance()` and `joueur()` helper links already use the tag string, so no functional change is needed — simply remove `a.id` from the SELECT list.

---

### MEDIUM-006: War can be ended unilaterally by either side with no minimum-combat condition
- **File:** allianceadmin.php:560-606
- **Domain:** FLOW-ALLIANCE
- **Description:** Either the attacking or defending alliance can end a war at any time by POSTing `adversaire` with the opponent's alliance ID. There is no minimum duration, no minimum loss threshold, and no confirmation from the other side required. This means an alliance can declare war, immediately end it (gaining a win if the opponent had any prior losses), and repeat this cycle to farm `pointsVictoire`. Since the winner is determined by total losses (`pertes1` vs `pertes2`), an alliance that declares war while the opponent has zero combat history starts with guaranteed win potential — declare, wait one battle cycle with one attack, immediately end the war.
- **Impact:** Game-balance exploit: `pointsVictoire` can be farmed via quick-declare/end war loops.
- **Suggested fix:** Add a `minimum_duration` constant (e.g., `WAR_MIN_DURATION_SECONDS = 86400`). Inside the war-end transaction, check `NOW() - declaration.timestamp < WAR_MIN_DURATION_SECONDS` and throw `WAR_TOO_SHORT`. Optionally require mutual consent (both alliances must submit a stop request) or a minimum total-losses threshold before the war can be closed.

---

### MEDIUM-007: When a chef is deleted (`supprimerJoueur()`), `supprimerAlliance()` is called without checking for active wars first
- **File:** includes/player.php:1005-1011
- **Domain:** FLOW-ALLIANCE
- **Description:** `supprimerJoueur()` calls `supprimerAlliance()` unconditionally when the player is a chef (lines 1008-1010). `supprimerAlliance()` runs `DELETE FROM declarations WHERE (alliance1=? OR alliance2=?)` (line 993), which purges any active war rows. If the target alliance was in an ongoing war, the opponent alliance's war display simply disappears with no winner recorded, no `pointsVictoire` awarded, and no notification to the opponent. This differs from the admin-driven dissolution path in `allianceadmin.php`, which blocks dissolution during an active war via the M-006 guard.
- **Impact:** Active war silently cancelled when a chef account is deleted; opponent alliance loses a legitimate in-progress war with no recourse.
- **Suggested fix:** Before `supprimerAlliance()` in `supprimerJoueur()`, query `SELECT id FROM declarations WHERE (alliance1=? OR alliance2=?) AND type=0 AND fin=0`. For each active war, compute the winner from current `pertes1`/`pertes2`, write `fin=UNIX_TIMESTAMP(), winner=?`, award `pointsVictoire` to the winner, and send a notification report to the surviving alliance chef. Then proceed with dissolution.

---

### LOW-001: `alliance_discovery.php` is accessible to unauthenticated (public) visitors and discloses chef logins and alliance names
- **File:** alliance_discovery.php:1-7
- **Domain:** FLOW-ALLIANCE
- **Description:** The page uses `basicpublicphp.php` for unauthenticated visitors, meaning the full alliance roster — including chef login names, alliance names, tags, duplicator levels, and member counts — is visible without being logged in. This is probably intentional for discoverability, but the chef login column (`joueur($row['chef'])`) renders a link to the player's profile page. Combined with the `avg_points` column, a competitor can map competitive alliances without creating an account.
- **Impact:** Low. Information disclosure of aggregated public game data. Consistent with the existing public profile pages.
- **Suggested fix:** If chef identity is considered sensitive for non-logged-in users, gate the `chef` column display behind `isset($_SESSION['login'])`. This is optional and may be intentional game design.

---

### LOW-002: Pact-acceptance page (`validerpacte.php`) redirects to `rapports.php` via JavaScript even when the `idDeclaration` POST parameter is missing
- **File:** validerpacte.php:63-76
- **Domain:** FLOW-ALLIANCE
- **Description:** When `$_POST['idDeclaration']` is not set (line 4), the entire transaction block is skipped and the page falls through to the HTML body which contains a JavaScript redirect `window.location = "rapports.php"`. This means a GET request to `validerpacte.php` (with no POST body) silently redirects to `rapports.php` rather than returning a 4xx error or showing a meaningful message. A user who bookmarks or shares this URL will be silently redirected.
- **Impact:** Low. Minor UX confusion; no security impact.
- **Suggested fix:** Add an early `if (!isset($_POST['idDeclaration'])) { header('Location: rapports.php'); exit; }` before the transaction block, removing the need for the JavaScript redirect altogether. This also removes the inline `<script>` tag dependency for a simple redirect.

---

### LOW-003: `supprimerAlliance()` does not delete forum `sujets` and `reponses` scoped to the alliance's forum thread, leaving orphaned content
- **File:** includes/player.php:979-996
- **Domain:** FLOW-ALLIANCE
- **Description:** Alliance dissolution cleans up `grades`, `invitations`, `declarations`, and `attack_cooldowns`. The `forums` table has no `idalliance` column (confirmed in base_schema.sql line 131-135), so a per-alliance forum sub-area does not exist at the DB schema level. However if the game uses a specific forum ID reserved for alliance discussions (via `idforum` on `sujets`), those `sujets`/`reponses` rows are not deleted on dissolution. The `supprimerAlliance()` function has no `DELETE FROM sujets` step, and `supprimerJoueur()` also omits it (line 1028 only handles `statutforum`).
- **Impact:** Low. Orphaned forum threads after alliance dissolution. No functional breakage since the forum is not alliance-scoped at the schema level.
- **Suggested fix:** If alliance threads are stored in `sujets` with a specific `idforum` or `auteur=[alliance]` convention, document and clean them up during dissolution. If there is no such convention, this is informational only.

---

## Summary

| Severity | Count | Findings |
|----------|-------|---------|
| CRITICAL | 0 | — |
| HIGH | 4 | HIGH-001, HIGH-002, HIGH-003, HIGH-004 |
| MEDIUM | 5 | MED-001 through MED-007 (7 total) |
| LOW | 3 | LOW-001 through LOW-003 |

**4 HIGH, 7 MEDIUM, 3 LOW. 0 CRITICAL.**

**Key files referenced:**
- `/home/guortates/TVLW/The-Very-Little-War/alliance.php` (lines 248-255 for HIGH-002/MEDIUM-004)
- `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` (lines 62-76 for HIGH-003; 485-558 for HIGH-001; 560-606 for HIGH-004/MEDIUM-006; 513-518 for MEDIUM-001; 465 for MEDIUM-003)
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` (lines 979-996 for supprimerAlliance/MEDIUM-002/LOW-003; lines 1005-1011 for MEDIUM-007)
- `/home/guortates/TVLW/The-Very-Little-War/alliance_discovery.php` (lines 14-76 for MEDIUM-005/LOW-001)
- `/home/guortates/TVLW/The-Very-Little-War/validerpacte.php` (lines 63-76 for LOW-002)



---

## FLOW-SEASON

Now I have everything needed for a complete and accurate audit report. Let me compile my findings:

---

## FLOW-SEASON Pass 25 Audit Report

### CRITICAL-001: Phase 2 maintenance block never executes `performSeasonEnd()` — game stays in perpetual maintenance without admin intervention

- **File:** `includes/basicprivatephp.php:246-267`
- **Domain:** FLOW-SEASON
- **Description:** When `maintenance=1` AND `maintenance_started_at > 0` AND 24h have elapsed, the Phase 2 block fires on every private page load. However it only outputs a 503 maintenance page and calls `exit`. It does **not** call `performSeasonEnd()`. The code comment says "triggered only by admin/index.php or a CLI cron job," but there is **no cron job** in `cron/` that calls `performSeasonEnd()`, and no automatic CLI script. This means the game will stay in the 503-blocked maintenance state **indefinitely** after the 24h window passes, until an admin manually navigates to `admin/index.php` and clicks the reset button. If the admin is unavailable (on holiday, sick, forgets), the game is stuck for days or weeks.
- **Impact:** Game-breaking operational failure. All players see 503 indefinitely. No data corruption occurs but the game is non-functional without manual admin intervention.
- **Suggested fix:** Either (a) add a `cron/season_reset.php` CLI script that calls `performSeasonEnd()` and schedule it via cron to run hourly (checking the maintenance flag and 24h elapsed condition itself), or (b) in the Phase 2 block in `basicprivatephp.php`, gate execution on `$_SESSION['login'] === ADMIN_LOGIN` and call `performSeasonEnd()` for the admin user when 24h have elapsed, keeping the 503 for all other users.

---

### CRITICAL-002: `performSeasonEnd()` has no guard against being called when `maintenance=0` — admin can trigger accidental mid-season reset

- **File:** `includes/player.php:1135-1145` and `admin/index.php:110-121`
- **Domain:** FLOW-SEASON
- **Description:** `performSeasonEnd()` acquires an advisory lock but performs **no check** that `maintenance=1` before proceeding. `admin/index.php` also has no guard — the `miseazero` POST action calls `performSeasonEnd()` unconditionally regardless of the current maintenance flag value. An admin who accidentally submits the reset form during an active season (or during the 24h maintenance wait window) will execute a full season reset with no warning or confirmation.
- **Impact:** Catastrophic data loss — full season reset (all 15 tables wiped) at an unexpected time, destroying all player progress for the current season with no recovery path.
- **Suggested fix:** Add a guard at the top of `performSeasonEnd()`: read `maintenance` from `statistiques` and throw a `RuntimeException` if `maintenance != 1`. In `admin/index.php`, add a visible warning and a `<input type="hidden" name="confirm_reset" value="1">` confirmation checkbox before enabling the reset button.

---

### HIGH-001: `sondages` and `reponses_sondage` tables not cleared on season reset — stale polls carry over into new season

- **File:** `includes/player.php:1486-1586` (`remiseAZero()`)
- **Domain:** FLOW-SEASON
- **Description:** `remiseAZero()` wipes 15+ tables but does not touch `sondages` (active polls) or `reponses_sondage` (player vote records). Both tables are season-scoped: polls are created by admins for a specific game season, and vote records reference those polls by `sondage` FK. After reset, old polls from the prior season remain active (their `active=1` flag is never cleared), old vote records remain (players who voted last season cannot vote again because the `uk_login_sondage` unique key is still satisfied), and new player logins from after the reset may find a poll already "voted" by their account from last season.
- **Impact:** Broken poll system for the first season after reset. Players may be blocked from voting on new polls due to stale `reponses_sondage` rows from the prior season.
- **Suggested fix:** Add to `remiseAZero()` inside the transaction: `DELETE FROM reponses_sondage;` and `UPDATE sondages SET active = 0;` (deactivate old polls rather than delete so archives are preserved).

---

### HIGH-002: `moderation` table (resource sanctions) not cleared on season reset — cross-season sanctions block new-season resource distribution

- **File:** `includes/player.php:1486-1586` (`remiseAZero()`)
- **Domain:** FLOW-SEASON
- **Description:** The `moderation` table stores per-player resource penalties (energy, atoms) applied by moderators. It is never cleared in `remiseAZero()`. After a season reset, any pending moderation entries from the prior season persist. Since all resource values are reset via `UPDATE ressources SET energie=default, ...`, the moderation records become orphaned — they reference prior-season infractions but will be applied to the new season's fresh resource pools when `moderation/index.php` processes them.
- **Impact:** Players who were sanctioned last season will have resources drained at the start of the new season for an offense that was already served. This is both a game balance issue and a player trust issue.
- **Suggested fix:** Add `DELETE FROM moderation;` to `remiseAZero()` inside the main transaction.

---

### HIGH-003: `archiveSeasonData()` failure does NOT block `remiseAZero()` — season data can be lost silently

- **File:** `includes/player.php:1238` and `1313`
- **Domain:** FLOW-SEASON
- **Description:** `archiveSeasonData($base)` is called at line 1238 outside any `try/catch`. If it throws (e.g., `season_recap` table write fails, DB deadlock), the exception will propagate up and be caught by the outer `try/finally` at line 1149. The `finally` block releases the advisory lock. But critically, there is no explicit check that `archiveSeasonData()` succeeded before calling `awardPrestigePoints()` (line 1310) and `remiseAZero()` (line 1313). The `withTransaction` inside `archiveSeasonData()` may silently fail and return the `$prevSeason` idempotency bypass without actually inserting any rows if the inner `withTransaction` throws and is caught internally. Reading the code: `withTransaction` in `archiveSeasonData()` at line 1107 does NOT have a try/catch — if it throws, the exception propagates to the caller. So a DB error during archive will halt `performSeasonEnd()` before `remiseAZero()`. This is actually the CORRECT behavior. However: if `archiveSeasonData()` returns successfully but the `$allPlayers` query (line 1097) returned an empty array (e.g., all players inactive), `performSeasonEnd()` proceeds to wipe data with zero archive rows written, giving a silent empty season_recap.
- **Impact:** A season with all-inactive players produces a completely empty `season_recap` archive. The game resets with no historical record. This is an edge case but can occur on a very new server.
- **Suggested fix:** Add a post-archive sanity check: if `count($allPlayers) === 0`, log a warning but do not block the reset. Optionally insert a sentinel row recording the season number and a "no active players" note.

---

### MEDIUM-001: `alliances` rows not deleted on season reset — disbanded alliance IDs persist and can conflict with new season alliance creation

- **File:** `includes/player.php:1517`
- **Domain:** FLOW-SEASON
- **Description:** `remiseAZero()` does `UPDATE alliances SET energieAlliance=0, ..., pointstotaux=0 ...` but does **not** `DELETE FROM alliances`. Alliance rows and their IDs persist into the new season. Players' `autre.idalliance` values also remain intact (no `UPDATE autre SET idalliance=0` is performed in `remiseAZero()`), meaning players start the new season already in the same alliance with the same membership structure. This may be intentional for continuity, but the consequence is that `declarations` (war declarations) are deleted but alliances with active membership are not reset to a clean state. Alliances also retain their `pointsVictoire` (cross-season VP is an intentional design per the comment at line 1510), but the missing `idalliance` reset means the `alliance_left_at` NULL-ing at line 1577 is the only alliance membership change. Players who quit mid-season cannot rejoin their old alliance because the `ALLIANCE_REJOIN_COOLDOWN_SECONDS` (24h) may not have passed — but `alliance_left_at` is cleared, removing that guard. This is a minor inconsistency.
- **Impact:** Low game impact if alliance continuity is intended. No data corruption. Potential confusion for players who expected fresh alliance standings.
- **Suggested fix:** Add a code comment explicitly documenting that alliances persist across seasons by design. No code change needed if intentional.

---

### MEDIUM-002: `season_recap.php` displays `molecules_perdues` cast as `(int)` — truncates float values silently

- **File:** `season_recap.php:28`
- **Domain:** FLOW-SEASON
- **Description:** The `molecules_perdues` column in `season_recap` is `DOUBLE` (per migration 0029) and is inserted as `(float)` in `archiveSeasonData()`. But in `season_recap.php`, it is read and cast as `$molLost = (int)$recap['molecules_perdues']`. This silently truncates decimal molecule loss values, potentially showing `0` when the actual value is e.g. `0.75`.
- **Impact:** Inaccurate historical data display. Players who lost fractional molecules see `0` in their season recap.
- **Suggested fix:** Change line 28 to `$molLost = (float)$recap['molecules_perdues'];` and update the `number_format` call on line 50 to `number_format($molLost, 2, ',', ' ')` to show two decimal places.

---

### MEDIUM-003: `processEmailQueue()` called probabilistically (1% of requests) and is NOT called explicitly during `performSeasonEnd()` — season winner email may be delayed by hours

- **File:** `includes/basicprivatephp.php:353-355` and `includes/player.php:1135-1358`
- **Domain:** FLOW-SEASON
- **Description:** Season winner and top-player notification emails are queued during `performSeasonEnd()` (via the email_queue table filled elsewhere). However, `performSeasonEnd()` itself does not call `processEmailQueue()` before returning. The queue is drained only probabilistically (1-in-`EMAIL_QUEUE_DRAIN_PROB_DENOM`, default 100) on each private page load. During the maintenance window, ALL private page loads exit at 503 before reaching the `processEmailQueue()` call at line 353. After the reset clears `maintenance=0`, only 1% of page loads drain the queue. In a low-traffic game, this means season winner emails may not be sent for hours or even days after the season ends.
- **Impact:** Poor player experience. Season winners and top players may not receive their notification emails in a timely manner. This is particularly important for a monthly-cycle game where the announcement of a winner is a key engagement moment.
- **Suggested fix:** Add an explicit `processEmailQueue($base, 100)` call at the end of `performSeasonEnd()` (after the maintenance flag is cleared) to immediately drain the queue for season-end notifications. The probabilistic drain in `basicprivatephp.php` can then handle any overflow.

---

### LOW-001: `season_recap.php` accessible during maintenance window — shows empty state instead of proper maintenance message

- **File:** `includes/basicprivatephp.php:118-130`
- **Domain:** FLOW-SEASON
- **Description:** `season_recap.php` is in `$vacationAllowedPages` (line 123) which controls access during vacation mode, not maintenance mode. During the maintenance window, `basicprivatephp.php` shows a 503 for all private pages including `season_recap.php`. This is correct. However, the maintenance 503 page has no link to `season_recap.php`, and the page itself queries `season_recap WHERE login = ?` — after reset, `season_recap` contains the just-archived data, so the page correctly shows the most recent season. This is acceptable behavior.
- **Impact:** No impact. Informational only.
- **Suggested fix:** No change needed.

---

### LOW-002: `winner news` INSERT in `performSeasonEnd()` uses `time()` which can differ by seconds from `debut` timestamp — minor timestamp inconsistency

- **File:** `includes/player.php:1342-1348`
- **Domain:** FLOW-SEASON
- **Description:** The final transaction in `performSeasonEnd()` at line 1338 sets `debut = $now` (where `$now = time()`) and then inserts a news item also using `time()` again at line 1347. Since `$now` is assigned before the news INSERT, and PHP executes sequentially, there can be a 0–1 second difference between the `debut` timestamp and the news timestamp if the INSERT takes time. The `date('d/m/Y à H\hi', time())` in the news content at line 1346 also recomputes `time()` rather than using the already-captured `$now`.
- **Impact:** Cosmetic only — a 0–1 second timestamp discrepancy between season debut and winner announcement. No game logic depends on these timestamps being identical.
- **Suggested fix:** Replace `time()` inside the news content string with `$now` for consistency: `date('d/m/Y à H\hi', $now)`.

---

### LOW-003: `remiseAZero()` uses `DELETE FROM news WHERE 1` instead of `TRUNCATE TABLE news` — slower on large tables and leaves auto-increment counter high

- **File:** `includes/player.php:1574`
- **Domain:** FLOW-SEASON
- **Description:** `DELETE FROM news WHERE 1` deletes all rows but does not reset the `AUTO_INCREMENT` counter. After several seasons the `news.id` will be in the hundreds or thousands even though only a handful of rows exist. This is cosmetic but could confuse debugging. The same pattern is used across many tables (`DELETE FROM declarations`, `DELETE FROM messages`, etc.). `TRUNCATE TABLE` would be faster and reset the counter.
- **Impact:** No functional impact. Minor cosmetic/performance issue.
- **Suggested fix:** Replace `DELETE FROM news WHERE 1` with `TRUNCATE TABLE news`. Consider applying the same pattern to other `DELETE FROM table` calls in `remiseAZero()` where the table is always fully emptied.

---

## Summary

| Severity | Count | Findings |
|---|---|---|
| CRITICAL | 2 | CRITICAL-001 (Phase 2 never triggers performSeasonEnd — perpetual maintenance without admin), CRITICAL-002 (no maintenance=1 guard in performSeasonEnd — accidental mid-season reset) |
| HIGH | 3 | HIGH-001 (sondages/reponses_sondage not cleared), HIGH-002 (moderation sanctions not cleared), HIGH-003 (empty allPlayers silent empty archive) |
| MEDIUM | 3 | MEDIUM-001 (alliances not deleted — needs design comment), MEDIUM-002 (molecules_perdues cast to int in season_recap), MEDIUM-003 (season emails delayed by probabilistic drain + maintenance block) |
| LOW | 3 | LOW-001 (season_recap during maintenance — no issue), LOW-002 (winner news uses re-called time()), LOW-003 (DELETE vs TRUNCATE for news) |

**Total: 2 CRITICAL, 3 HIGH, 3 MEDIUM, 3 LOW**

The most urgent fix is CRITICAL-001: the game currently has **no automated mechanism** to advance from Phase 2 maintenance back to a live season — it relies entirely on a human admin clicking a button. If no cron job or automation is added, every season transition requires manual intervention within hours of the 24h window expiring or players face an indefinitely blocked game. CRITICAL-002 is equally important as it exposes a destructive operation (full game reset) with no confirmation gate.

Relevant files:
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` — `performSeasonEnd()` line 1135, `remiseAZero()` line 1486, `archiveSeasonData()` line 1060
- `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` — Phase 1/2 trigger logic lines 246–348
- `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` — manual reset trigger lines 110–121
- `/home/guortates/TVLW/The-Very-Little-War/season_recap.php` — historical data display
- `/home/guortates/TVLW/The-Very-Little-War/migrations/0029_create_season_recap.sql` — season_recap schema
- `/home/guortates/TVLW/The-Very-Little-War/migrations/0031_create_sondages_table.sql` — sondages schema
- `/home/guortates/TVLW/The-Very-Little-War/migrations/0032_create_reponses_sondage.sql` — reponses_sondage schema



---

## FLOW-PRESTIGE

I now have everything I need to write the complete audit report.

---

## FLOW-PRESTIGE Audit Report — Pass 25

---

### HIGH-001: Streak PP double-awards on day-1 milestone — `STREAK_MILESTONES[1]` hit AND the fallback branch both award STREAK_REWARD_DAY_1

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:1626-1631
- **Domain:** FLOW-PRESTIGE
- **Description:** The `updateLoginStreak()` logic awards PP in two mutually exclusive branches: first checks `isset($STREAK_MILESTONES[$currentStreak])` and then falls through to `elseif ($currentStreak >= 1)`. On a day-1 login, `$currentStreak` becomes 1. `$STREAK_MILESTONES[1]` exists (it maps to `STREAK_REWARD_DAY_1 = 1`), so the first branch fires and sets `$ppEarned = 1`, then `$isMilestone = true`. That is correct and the `else if` is properly skipped. There is no double-award here for milestone days.

  However there is a subtler issue: when the streak resets (any non-consecutive login sets `$currentStreak = 1`), the player hits the day-1 milestone again. `$STREAK_MILESTONES[1] = STREAK_REWARD_DAY_1 = 1`, so a player who breaks their streak earns the milestone PP every time they reconnect after a gap. This is the intended "base daily PP" mechanic but the UI (prestige.php:80) lists "1j (+1PP)" as a milestone, misleading players into thinking it is a one-time achievement — it fires every reset.

  This is a documentation/UX inconsistency, not a double-award exploit, and is lower severity than initially flagged. Reclassified: MEDIUM.

---

### MEDIUM-001: `updateLoginStreak()` has no banned-player guard — banned players earn streak PP on every page load

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/player.php:1592-1641 and /home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:188-207
- **Domain:** FLOW-PRESTIGE
- **Description:** `basicprivatephp.php` first checks `estExclu = 0` in the session_token query (line 39) and exits if the player is banned. That guard correctly prevents banned players from reaching `updateLoginStreak()`. The gate is sufficient. However, `updateLoginStreak()` itself has no internal banned check — it is callable directly by any code path that bypasses `basicprivatephp.php` (e.g. a CLI script, future admin tool, or a test harness). The function only locks on `autre` which has no `estExclu` column, so it cannot self-defend.
- **Impact:** In the current production code path the gate in basicprivatephp.php is effective. But the function violates the defense-in-depth principle and a future caller that omits the ban check would silently reward banned players with PP. Low risk currently, but a hardening gap.
- **Suggested fix:** Add a banned check inside `updateLoginStreak()`: query `SELECT estExclu FROM membre WHERE login = ? FOR UPDATE` at the top of the transaction (the membre lock can be shared with the existing FOR UPDATE on `autre`). Return early if `estExclu = 1`.

---

### MEDIUM-002: `hasPrestigeUnlock()` and derived functions (`prestigeProductionBonus`, `prestigeCombatBonus`) call `getPrestige()` which hits the DB on every call — no per-request cache means a single page can issue 6+ identical queries

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:248-263
- **Domain:** FLOW-PRESTIGE
- **Description:** `getPrestige()` issues `SELECT * FROM prestige WHERE login=?` with no caching. In a single request, `prestige.php` calls `getPrestige()` once explicitly (line 18), then `prestigeProductionBonus()` → `hasPrestigeUnlock()` → `getPrestige()` again (line 24), then `prestigeCombatBonus()` → same chain (line 25), then `hasPrestigeUnlock('debutant_rapide')` (line 104), `hasPrestigeUnlock('veteran')` (line 111), `isPrestigeLegend()` (line 118). That is at least six DB queries for the same row in one request. `revenuEnergie()` and `revenuAtome()` use `static $cache[]` but those caches key on `$joueur-$niveau`, which does include the prestige bonus in the calculation without any per-request invalidation concern here. The prestige row itself is not cached.
- **Impact:** Performance waste; ~6 extra queries per private page load. More critically, after `purchasePrestigeUnlock()` succeeds and commits, any subsequent call to `hasPrestigeUnlock()` in the same request correctly reads from DB (no stale cache issue) — but the absence of a cache means there is no staleness risk and this is purely a performance finding.
- **Suggested fix:** Add a static per-request cache inside `getPrestige()`: `static $cache = []; if (isset($cache[$login])) return $cache[$login];` and add an `invalidatePrestigeCache($login)` function called at the end of `purchasePrestigeUnlock()` to clear the entry if needed on the same request path.

---

### MEDIUM-003: `revenuEnergie()` and `revenuAtome()` use static caches keyed without considering prestige — after `purchasePrestigeUnlock('experimente')` the static cache in `revenuEnergie` will return the pre-purchase production rate for the remainder of the request

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php:9-11 and :93-95
- **Domain:** FLOW-PRESTIGE
- **Description:** `revenuEnergie($niveau, $joueur)` has `static $cache = []` keyed on `$joueur . '-' . $niveau . '-' . $detail`. Once computed in a request, this value is frozen even if `purchasePrestigeUnlock('experimente')` subsequently commits and changes `prestigeProductionBonus($joueur)` from 1.0 to 1.05. If the same request first renders production values, then processes a purchase, then re-renders, the displayed value is stale.
- **Impact:** In practice `prestige.php` calls `purchasePrestigeUnlock()` in the POST path (lines 8-15) and then re-loads prestige data (line 18) — but does not re-render energy production. The stale cache is mostly a next-request concern because production display happens outside prestige.php. However the same request flow in `basicprivatephp.php` (which loads the navbar) runs `revenuAtome` and `revenuEnergie` for the current player *after* the streak update but the purchase POST processes first, so the navbar could momentarily show old production. Additionally, any admin tool that grants unlocks and then checks production in the same PHP process would read stale values.
- **Suggested fix:** Add an `invalidateProductionCache($login)` function that clears the `static $cache` entries for a given login in both `revenuEnergie` and `revenuAtome`, and call it from `purchasePrestigeUnlock()` after a successful purchase.

---

### MEDIUM-004: `awardPrestigePoints()` new-player row — the `INSERT IGNORE INTO statistiques` inside the transaction supplies all zero values for NOT NULL columns, but `statistiques.debut` being set to 0 corrupts the season window calculation if the main statistiques row truly does not exist

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:220-226
- **Domain:** FLOW-PRESTIGE
- **Description:** When `$statsRowMissing` is true, the code does `INSERT IGNORE INTO statistiques (..., debut, ...) VALUES (..., 0, ...)`. The `debut` column is used by `calculatePrestigePoints()` (lines 64-80) to determine season end. If no statistiques row exists and `awardPrestigePoints()` inserts one with `debut=0`, then `calculatePrestigePoints()` reads `$seasonRow['debut'] = 0` which hits the `> 0` check at line 72 as false, falling back to `$seasonEnd = time()`. This is actually the safer path — the fallback is `time()` which makes the final-week window `[now - 7 days, now]`, which is correct for an immediate season end. So the logic degrades gracefully. However, the inserted `statistiques` row with `debut=0` persists and is used by subsequent code (including the season reset trigger), potentially causing the maintenance flag logic in `basicprivatephp.php` to see a stale `debut=0` and behave unexpectedly. This is a low-probability edge case on a fresh install with no statistiques row, but represents a data integrity gap.
- **Impact:** Fresh install or data loss scenario: the zero-debut row becomes the canonical statistiques row, and `debut=0` could trigger maintenance checks prematurely on the next season cycle.
- **Suggested fix:** The `INSERT IGNORE` should set `debut = UNIX_TIMESTAMP()` rather than 0, so the newly created row has a valid season start timestamp.

---

### MEDIUM-005: The `'legende'` unlock's `legend_badge` effect is partially implemented — the badge appears on `joueur.php` but the "nom coloré" (colored name) part is not applied anywhere in classement.php, alliance member lists, or forum posts

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:38-39 and /home/guortates/TVLW/The-Very-Little-War/joueur.php:57-59
- **Domain:** FLOW-PRESTIGE
- **Description:** The unlock description promises "Badge unique + nom coloré". The badge (`&#9733; Légendaire` span) is rendered on `joueur.php` (line 57-59) when viewing that player's profile. However, the player's name is not rendered with special color anywhere else: `classement.php` fetches `prestige` rows and shows them in a `total_pp` column but does not color the login name; forum posts, alliance member lists, and the `attaquer.php` target list use `htmlspecialchars($login)` with no prestige decoration. `isPrestigeLegend()` is only called in `prestige.php` (the self-view) and in the single `joueur.php` check.
- **Impact:** Players who pay 1000 PP for the legend unlock receive less than the advertised effect. This is a broken promise with a 1000 PP price tag — the most expensive unlock in the game.
- **Suggested fix:** Apply `isPrestigeLegend()` check when rendering player names in classement.php ranking rows and in any public-facing name display. Create a helper `formatPlayerName($login, $isLegend)` that wraps the name in a gold-colored span when the legend badge is active.

---

### MEDIUM-006: PP balance display uses raw `total_pp` which already has spent PP subtracted — but the column name `total_pp` implies it is a lifetime total, not a current balance

- **File:** /home/guortates/TVLW/The-Very-Little-War/prestige.php:19 and /home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:308-318
- **Domain:** FLOW-PRESTIGE
- **Description:** `purchasePrestigeUnlock()` does `UPDATE prestige SET total_pp = GREATEST(0, total_pp - cost)` (line 318), meaning `total_pp` is the current spendable balance, not a lifetime accumulation total. The column name `total_pp` is misleading — it functions as `available_pp`. The UI shows it as "Solde total" (total balance) which is at least honest about it being a balance. However there is no separate `spent_pp` or `lifetime_pp` column, so there is no way to show a player's historical PP earning for achievement purposes, and there is no way to audit PP flow (detect if negative PP was introduced). The audit checklist question "reads correct columns (total_pp minus spent_pp or verify schema)" confirms: the schema has no `spent_pp` — it is a balance-only model.
- **Impact:** No double-spend vulnerability since the purchase deducts from the same column. But audit trail is zero — there is no way to detect if a player had their PP artificially inflated or if a bug granted extra PP. Also the column name creates confusion for future developers.
- **Suggested fix:** Either rename `total_pp` to `available_pp` (migration required), or add a `lifetime_pp` column that only ever goes up (via `ON DUPLICATE KEY UPDATE lifetime_pp = lifetime_pp + ?` in all PP-granting locations) to enable auditing and display of career total.

---

### LOW-001: The `'veteran'` unlock protection extension (line 208-211 in `attaquer.php`) calls `hasPrestigeUnlock()` twice for the same player in the same expression — one for the target and one for the attacker — each triggering a DB query with no cache

- **File:** /home/guortates/TVLW/The-Very-Little-War/attaquer.php:208-211
- **Domain:** FLOW-PRESTIGE
- **Description:** Lines 208 and 211 in `attaquer.php` each call `hasPrestigeUnlock($player, 'veteran')` independently. Line 211 calls it twice for `$_SESSION['login']` within consecutive lines (once for the check, once for the arithmetic). With no per-request cache in `getPrestige()`, this produces 3-4 DB queries for veteran status checks alone in the attack validation path. The espionage path at lines 49 and 83 is similarly redundant.
- **Impact:** Minor performance waste — 3-4 extra queries on attack submission. No correctness issue.
- **Suggested fix:** Cache the veteran status in a local variable: `$attackerIsVeteran = hasPrestigeUnlock($_SESSION['login'], 'veteran'); $veteranBonusSeconds = $attackerIsVeteran ? SECONDS_PER_DAY : 0;` and reuse it.

---

### LOW-002: `awardPrestigePoints()` does not award any PP for combat wins — `ajouterPoints()` updates attack/defense/pillage stats but there is no per-combat PP grant; PP is only awarded at season end via `calculatePrestigePoints()`

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/combat.php:809-876 and /home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:46-129
- **Domain:** FLOW-PRESTIGE
- **Description:** The audit checklist's entry "PP earned via combat: ajouterPoints() correctly writes to prestige table" cannot be confirmed as true — `ajouterPoints()` never writes to the prestige table. It only updates `autre.pointsAttaque`, `autre.pointsDefense`, and `autre.ressourcesPillees`. PP is calculated at season end by reading those accumulated stats via `calculatePrestigePoints()`. This is the intended design (PP is a season-end award, not a per-combat award). However the checklist phrasing may have been intended to verify that the combat stats used in `calculatePrestigePoints()` are correctly accumulated by `ajouterPoints()` — which they are. No bug, but a documentation clarity gap.
- **Impact:** No exploit. Design is correct but the audit trail phrase "combat win writes to prestige table" is a false expectation. A new developer reading the code could spend time looking for a prestige write in combat.php that does not exist.
- **Suggested fix:** Add a comment in combat.php near `ajouterPoints()` calls: "PP are awarded at season end via calculatePrestigePoints() which reads these stats — no direct prestige table write occurs per combat."

---

### LOW-003: `calculatePrestigePoints()` is called on every `prestige.php` load to display "Cette saison : +N PP" but the result is a snapshot of current stats, not the actual PP that will be awarded if the season ended now — the value will drift upward during the season and could mislead players

- **File:** /home/guortates/TVLW/The-Very-Little-War/prestige.php:21
- **Domain:** FLOW-PRESTIGE
- **Description:** The prestige UI shows `$seasonPP = calculatePrestigePoints($_SESSION['login'])` as the projected end-of-season award. This is computed fresh every page load, which is correct behavior. However the label "Points attribués à la fin de la saison" implies this is the final number, when it is a live projection. If a player logs in for the first time today (gaining the `PRESTIGE_PP_ACTIVE_FINAL_WEEK` bonus this very load), the displayed number includes that bonus even before the season actually ends. No incorrect PP is awarded — `awardPrestigePoints()` independently calls `calculatePrestigePoints()` at season end. The display is just potentially confusing.
- **Impact:** No PP exploit. UX confusion only.
- **Suggested fix:** Change the label to "Estimation si la saison se terminait maintenant" to make the projection nature clear.

---

### LOW-004: `prestige.php` UI balance display at line 19 reads `getPrestige()` which could return default `['total_pp' => 0, 'unlocks' => '']` for a player with no prestige row — but since all new players get a row via the season-end `INSERT INTO prestige ... ON DUPLICATE KEY`, this is only a risk for players registered after `awardPrestigePoints()` ran but before their first streak PP

- **File:** /home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:248-255
- **Domain:** FLOW-PRESTIGE
- **Description:** `getPrestige()` returns a default zero array if no row exists for the player. A new player who registers after the season's `awardPrestigePoints()` ran, but earns their first streak PP before they visit `prestige.php`, will have a row created by `updateLoginStreak()`'s `INSERT INTO prestige ... ON DUPLICATE KEY`. So in practice the row will exist on first visit. The risk is narrowed to a player who registers, never logs in again until they visit `prestige.php`, with no streak PP having been awarded. In that case they correctly see 0 PP with no unlocks. No bug, but `getPrestige()` could also proactively `INSERT IGNORE INTO prestige (login, total_pp) VALUES (?, 0)` to ensure row existence.
- **Impact:** No incorrect behavior — default zeros are safe. Minor defensive hardening gap.
- **Suggested fix:** Add an `INSERT IGNORE INTO prestige (login, total_pp) VALUES (?, 0)` at the start of `getPrestige()` before the SELECT, to guarantee the row always exists after the first call.

---

## Summary

**Findings by severity:**

| Severity | Count | IDs |
|----------|-------|-----|
| CRITICAL | 0 | — |
| HIGH | 0 | — |
| MEDIUM | 6 | MEDIUM-001 through MEDIUM-006 |
| LOW | 4 | LOW-001 through LOW-004 |

**0 CRITICAL, 0 HIGH, 6 MEDIUM, 4 LOW**

**Key findings summary:**

1. MEDIUM-001 — `updateLoginStreak()` lacks an internal banned-player guard; defense-in-depth relies solely on `basicprivatephp.php`.
2. MEDIUM-002 — `getPrestige()` has no per-request cache, causing 6+ redundant DB queries per page load on `prestige.php`.
3. MEDIUM-003 — `revenuEnergie()`/`revenuAtome()` static caches are not invalidated after `purchasePrestigeUnlock('experimente')` succeeds, producing stale production values within the same request.
4. MEDIUM-004 — The fallback `INSERT IGNORE INTO statistiques` in `awardPrestigePoints()` sets `debut=0`, which could corrupt the season-start timestamp on a fresh install and propagate downstream to the maintenance trigger.
5. MEDIUM-005 — The `'legende'` unlock's "nom coloré" effect is defined but never applied outside `joueur.php`; the most expensive unlock (1000 PP) delivers less than advertised.
6. MEDIUM-006 — `total_pp` acts as a spendable balance (decremented on purchase) with no separate lifetime or `spent_pp` column, making PP flow unauditable and the column naming misleading.
7. LOW-001/002/003/004 — Minor performance waste (redundant queries), documentation gaps, and a UX labeling imprecision on the season PP estimate display.

