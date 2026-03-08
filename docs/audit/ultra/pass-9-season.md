# Ultra Audit Pass 9 — Season Reset & Cron Jobs
**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent
**Scope:** scripts/, cron/, admin/index.php (reset section), includes/player.php (archiveSeasonData, performSeasonEnd, remiseAZero), includes/basicprivatephp.php (season trigger logic)

---

## Domain Checklist

| # | Domain | Result |
|---|--------|--------|
| 1 | Cron/scripts web-access protection | PASS — both .htaccess deny all |
| 2 | Authentication before reset | PASS (with minor caveat — see SEASON-P9-002) |
| 3 | Transaction safety | PASS — remiseAZero wrapped in withTransaction |
| 4 | Race condition / double-reset | PASS — GET_LOCK advisory lock |
| 5 | Missing cleanup tables | FINDING — email_queue not purged (SEASON-P9-001) |
| 6 | Email header injection | FINDING — recipient_email not sanitized (SEASON-P9-003) |
| 7 | Rankings frozen before reset | PASS — classement.php checks maintenance flag |
| 8 | Hardcoded values | FINDING — two hardcoded 'Guortates' strings (SEASON-P9-004) |
| 9 | Logging | FINDING — no "reset started" audit log event (SEASON-P9-005) |

---

## Findings

### SEASON-P9-001
**Severity:** MEDIUM
**File:** includes/player.php:1263 (remiseAZero)
**Description:** `remiseAZero()` does not purge the `email_queue` table. After a season reset, rows inserted for the previous season's winner announcement remain indefinitely. The async drain in `processEmailQueue()` will re-send those rows on every 1-in-100 page load until they are all marked `sent_at IS NOT NULL`. If a reset succeeds but `processEmailQueue()` has not run yet before the next reset, the previous season's emails pile up and all eventually send — potentially sending players duplicate winner-announcement emails for multiple seasons simultaneously.

Additionally, the `login_history` and `account_flags` tables are populated during gameplay (tracking IP and multi-account data) but are **not cleared** in `remiseAZero()`. While preserving this data cross-season is arguably desirable for ban enforcement, the current behavior is undocumented and inconsistent — `supprimerJoueur()` (line 948–949) does DELETE from these tables per-player, implying they are considered game data, but `remiseAZero()` silently skips them.

**Recommended fix:** Add `DELETE FROM email_queue WHERE sent_at IS NOT NULL` before the new season's emails are queued (purge already-sent rows to prevent unbounded table growth); document explicitly in remiseAZero that login_history and account_flags are intentionally preserved for ban continuity.

---

### SEASON-P9-002
**Severity:** LOW
**File:** includes/basicprivatephp.php:208
**Description:** The automatic season-end trigger (Phase 2 in basicprivatephp.php, line 200–264) uses `ADMIN_LOGIN` comparison via `$isAdminRequest = (!isset($_SESSION['login']) || $_SESSION['login'] === ADMIN_LOGIN)`. The comment says "or a cron/CLI context without a session" — this means **any unauthenticated web request** (i.e., a request that somehow has no session at all) will also pass the `$isAdminRequest` check and trigger `performSeasonEnd()`. In practice, `basicprivatephp.php` destroys and redirects unauthenticated sessions at line 47–51, so a request reaching line 208 without a session is extremely unlikely. However, the logic is still logically inverted from what the comment implies — `!isset($_SESSION['login'])` means "no session" not "CLI context." The correct gate should require `ADMIN_LOGIN` explicitly and block all unauthenticated web paths.

**Recommended fix:** Change the condition to `$isAdminRequest = (isset($_SESSION['login']) && $_SESSION['login'] === ADMIN_LOGIN)` — removing the `!isset($_SESSION['login'])` branch, since no unauthenticated request can legitimately reach this code path through basicprivatephp.php.

---

### SEASON-P9-003
**Severity:** LOW
**File:** includes/player.php:1222–1254 (processEmailQueue)
**Description:** In `processEmailQueue()`, the `$recipient` value is read directly from `email_queue.recipient_email` and passed to PHP's `mail()` as the `$to` argument without validation or sanitization. If malformed or adversarially crafted data were ever inserted into `email_queue` (e.g., via a SQL injection elsewhere, or a compromised admin account), an address containing `\r\n` could inject additional headers into the `mail()` call. The subject line stored in `email_queue.subject` is already RFC 2047-encoded (`=?UTF-8?B?...?=`) when queued from basicprivatephp.php, so it is safe. The body_html could contain injected `<script>` but email clients generally strip this. The primary vector is the raw `$recipient` value passed to `mail($recipient, ...)`.

**Recommended fix:** Add `if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) { continue; }` at the top of the `processEmailQueue()` foreach loop to reject any malformed address before it reaches `mail()`.

---

### SEASON-P9-004
**Severity:** LOW
**File:** includes/display.php:274, connectes.php:29
**Description:** Two hardcoded string literals `"Guortates"` remain in the codebase outside of config.php. `includes/display.php:274` compares `$donnees2['login'] == "Guortates"` to assign the "Créateur" role color. `connectes.php:29` uses `$donnees['login'] != "Guortates"` to hide the admin from the online-users list. Both should reference `ADMIN_LOGIN` from config.php for consistency and maintainability. If the admin account name ever changes, these two locations will be silently wrong while the season-reset trigger (which correctly uses `ADMIN_LOGIN`) works properly.

**Recommended fix:** Replace both `"Guortates"` string literals with `ADMIN_LOGIN` constant.

---

### SEASON-P9-005
**Severity:** INFO
**File:** includes/player.php:1017 (performSeasonEnd)
**Description:** `performSeasonEnd()` does not emit a log event at the point of invocation (before any work begins). The function logs three intermediate events — archive complete (line 1006), sessions invalidated (line 1162), maintenance cleared (line 1179) — and the admin panel logs any exception (admin/index.php:87). However, there is no `logInfo('SEASON', 'Season reset started', [...])` call at the top of `performSeasonEnd()` and no log entry recording the final winner upon success. If the function throws between lock acquisition and the first archive log, there is no audit trail showing the reset was initiated. Similarly, the winner is only recorded in the `news` table and in the email queue, not in the system log.

**Recommended fix:** Add `logInfo('SEASON', 'Season reset started', ['trigger' => 'admin/auto', 'winner_at_start' => ...])` immediately after lock acquisition, and `logInfo('SEASON', 'Season reset completed', ['winner' => $vainqueurManche])` immediately before releasing the lock.

---

## Notes on Passing Checks

**Web access protection (SEASON-P9-N/A-001):** Both `scripts/.htaccess` and `cron/.htaccess` use `Require all denied`, correctly blocking all HTTP access. `scripts/cleanup_old_data.php` also enforces `PHP_SAPI !== 'cli'` as a defence-in-depth check. No cron-specific season reset script exists; the cron directory is currently empty. PASS.

**Transaction safety (SEASON-P9-N/A-002):** `remiseAZero()` wraps its entire body (30+ statements) in a single `withTransaction()` call (line 1269). `archiveSeasonData()` wraps its INSERT loop in a transaction (line 989). VP award chunks each use their own `withTransaction()` call. PASS.

**Race condition (SEASON-P9-N/A-003):** `performSeasonEnd()` acquires `GET_LOCK('tvlw_season_reset', 0)` with a zero timeout at line 1024, throwing `RuntimeException` immediately if the lock is not available. Phase 1 of the automatic trigger uses a separate `GET_LOCK('tvlw_season_phase1', 0)` with a double-check inside the lock (line 272–283). PASS.

**Rankings frozen (SEASON-P9-N/A-004):** `classement.php` checks `maintenance == 1` and displays a "Classement gelé" message for both player and alliance rankings (lines 69–87, 353–371) rather than showing live data during reset. PASS.

**Hardcoded values in reset logic (SEASON-P9-N/A-005):** `SEASON_MAINTENANCE_PAUSE_SECONDS` (24h), `SEASON_ARCHIVE_TOP_N` (20), `ADMIN_LOGIN`, `MAP_INITIAL_SIZE` are all defined in config.php and referenced correctly from reset code. `performSeasonEnd()` itself contains no magic numbers. PASS.

**Email encoding (SEASON-P9-N/A-006):** The email subject is RFC 2047-encoded using `=?UTF-8?B?` + `base64_encode()` at basicprivatephp.php:252. The HTML body uses `htmlspecialchars()` for recipient login name and winner name. The date string uses PHP's `date()` with a fixed format and no user input. PASS.

---

FINDINGS: 0 critical, 0 high, 1 medium, 3 low, 1 info
