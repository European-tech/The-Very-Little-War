# Pass 7 Error Handling Audit

**Date:** 2026-03-08
**Scope:** Error handling completeness and logging quality across all PHP source files.
**Already confirmed fixed (pre-conditions):** die()/exit() replaced with proper error handling, logger.php exists, withTransaction re-throws exceptions, rate limiter errors logged.

---

## Logger Infrastructure Review

`includes/logger.php` provides four levels:
- `logDebug` (0) — filtered out in production (MIN_LOG_LEVEL = INFO)
- `logInfo` (1)
- `logWarn` (2)
- `logError` (3)

IP address is hashed with SHA-256+salt before writing. Log lines include timestamp, level, category, user@ip_hash, and a JSON context block. Daily rotating files written to `logs/YYYY-MM-DD.log` with `LOCK_EX`.

**Gap:** logger.php is not loaded by database.php (it cannot be — it is a lower-level file). database.php calls PHP's native `error_log()` instead of the structured `logError()`. This is a logging consistency issue, not a functionality gap.

---

## Findings

### ERR-P7-001 [LOW] database.php — SQL errors logged via error_log(), not structured gameLog()

**File:** `includes/database.php` lines 15, 23, 64, 72

**Proof:**
```php
error_log("SQL Prepare Error [" . mysqli_errno($base) . "]: " . mysqli_error($base) . " | Query: " . $truncatedQuery);
```

**Problem:** database.php calls `error_log()` (PHP native) instead of the project's structured `logError()`. This means SQL failures go to Apache/PHP-FPM error log (typically `/var/log/apache2/error.log` or `/var/log/php8.2-fpm.log`) rather than the application's daily log file at `logs/YYYY-MM-DD.log`. SQL errors lack: player login, hashed IP, and category field — reducing traceability for security analysis.

**Root cause:** database.php cannot safely `require_once` logger.php without creating a circular dependency or load-order fragility, since database.php is itself included very early by connexion.php.

**Fix:** Either (a) move logger.php load to before database.php in connexion.php's bootstrap sequence and add `if (function_exists('logError')) logError(...)` guards, or (b) accept the split logging and document it. Option (b) is lower risk. Severity remains LOW as the errors do get recorded — just in a different log.

---

### ERR-P7-002 [LOW] Multiple withTransaction() call sites lack try/catch — uncaught exceptions produce HTTP 500 / blank page

**Files (uncaught withTransaction callers):**
- `ecriremessage.php` lines 33–38 (alliance broadcast) and 52–57 (admin broadcast)
- `messageCommun.php` line 52
- `moderation/index.php` line 120
- `voter.php` line 48
- `validerpacte.php` line 7
- `bilan.php` line 62
- `compte.php` line 29
- `comptetest.php` line 77
- `includes/basicprivatehtml.php` lines 72, 88, 122, 138, 171, 192, 209, 231

**Proof (voter.php, representative):**
```php
withTransaction($base, function() use ($base, $login, $sondageId, $reponse, $pasDeVote, &$dejaRepondu) {
    // ... INSERT/UPDATE ...
});
exit(json_encode(["erreur" => false, "dejaRepondu" => $dejaRepondu]));
```
No `try/catch` wraps the `withTransaction()` call. If the DB savepoint fails or an INSERT triggers a constraint violation, `withTransaction()` re-throws the exception. With PHP-FPM + `display_errors=Off`, the user sees a blank page or malformed JSON (for AJAX endpoints). No log entry is written by the caller.

**Impact:** User-facing: confusing blank pages or broken AJAX. Ops-facing: the exception goes to PHP's default handler which logs to the FPM error log (not the application log), losing player context (login, action intent).

**Note:** The most critical callers (combat, market, constructions, alliance admin, armee, season reset) already have proper try/catch. The uncaught sites above are lower-criticality (voting, messaging, tutorial rewards, vacation mode) but are still observable by users.

**Fix (pattern):**
```php
try {
    withTransaction($base, function() use (...) { ... });
} catch (\Throwable $e) {
    logError('VOTE', 'Transaction failed: ' . $e->getMessage());
    // For AJAX endpoints:
    exit(json_encode(["erreur" => true]));
    // For page endpoints: set $erreur = "Une erreur est survenue. Veuillez réessayer.";
}
```

---

### ERR-P7-003 [LOW] connexion.php — DB connection failure uses die() without structured logging

**File:** `includes/connexion.php` lines 11, 17

**Proof:**
```php
if (!$db_user || $db_name === false) {
    error_log('TVLW: DB credentials not loaded from .env — check file exists and is readable');
    die('Configuration error. Contact administrator.');
}
// ...
if (!$base) {
    die('Erreur de connexion à la base de données.');
}
```

**Problem:** The second `die()` at line 17 (actual DB connection failure) has no logging at all — no `error_log()`, no `logError()`. A DB outage would produce a bare `die()` with no trace in any log. The first `die()` does call `error_log()`, but also uses raw `die()` which outputs plain text without an HTTP status code, breaking JSON-expecting clients and producing unstyled output.

**Fix:** Replace both `die()` calls with proper logging + graceful output:
```php
if (!$base) {
    error_log('TVLW: mysqli_connect() failed: ' . mysqli_connect_error());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    exit('Erreur de connexion à la base de données.');
}
```

---

### ERR-P7-004 [LOW] .htaccess PHP settings silently ignored under PHP-FPM (pre-existing, documented)

**File:** `.htaccess` lines 37–42

**Proof:**
```apache
<IfModule mod_php.c>
    php_flag display_errors off
    php_flag log_errors on
    php_value error_reporting 32767
    php_flag expose_php off
</IfModule>
```

**Problem:** The VPS uses PHP 8.2 via PHP-FPM (`mod_proxy_fcgi`), not `mod_php`. The `<IfModule mod_php.c>` block is never entered. `display_errors` and `expose_php` settings rely entirely on `/etc/php/8.2/fpm/php.ini` and the FPM pool config. If those were not explicitly hardened at VPS setup, errors may be displayed to users. Additionally, `error_reporting = 32767` (E_ALL) in production generates excessive log volume on PHP 8.2 where deprecation notices are common.

**Note:** This issue was flagged in Pass 3 (INFRA-CROSS) and Pass 2 (pass-2-domain-3). Its status on the live VPS (`/etc/php/8.2/fpm/php.ini`) was not verified in this pass — the source tree has no PHP-FPM config file.

**Fix:** On VPS, verify `/etc/php/8.2/fpm/php.ini` contains:
```
display_errors = Off
expose_php = Off
log_errors = On
error_reporting = 24575
```
Or equivalently in the FPM pool config `/etc/php/8.2/fpm/pool.d/www.conf`:
```
php_flag[display_errors] = off
php_value[error_reporting] = 24575
```
Additionally, add a runtime fallback in `includes/session_init.php`:
```php
ini_set('display_errors', '0');
```

---

### ERR-P7-005 [INFO] No global set_exception_handler() — uncaught exceptions fall through to PHP default handler

**Context:** No call to `set_exception_handler()`, `set_error_handler()`, or `register_shutdown_function()` exists anywhere in the codebase.

**Impact:** If any unexpected exception escapes the request lifecycle (e.g., a type error in a utility function, an out-of-memory error, or a logic bug in a code path without try/catch), PHP's default handler takes over. With `display_errors=Off` (assumed), the user sees a blank page. No application-level log entry is written via `logError()`. The exception may or may not appear in the FPM error log depending on VPS configuration.

**Recommendation:** Add to `includes/session_init.php` (which is the universal bootstrap):
```php
set_exception_handler(function(\Throwable $e) {
    logError('UNCAUGHT', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
    echo 'Une erreur inattendue est survenue. Veuillez réessayer.';
});
```
This does not change behavior for caught exceptions; it only improves observability for the uncaught cases.

---

### ERR-P7-006 [INFO] Silenced @ operators — audit of appropriateness

All `@` usages in production PHP code:

| File | Line | Usage | Appropriate? |
|------|------|-------|-------------|
| `health.php` | 23 | `@mysqli_connect(...)` | YES — health check intentionally suppresses error output; wrapped in try/catch |
| `includes/player.php` | 1242 | `@mail(...)` | YES — mail() failures are handled by checking return value and calling `logWarn()` |
| `includes/multiaccount.php` | 294 | `@mail(...)` | BORDERLINE — admin alert failure silently ignored; no logWarn on failure |
| `includes/rate_limiter.php` | 14 | `@mkdir(...)` | YES — existence already checked; errno suppressed but failure handled |
| `includes/rate_limiter.php` | 28 | `@unlink($file)` | YES — race-safe cleanup; if file already gone, irrelevant |
| `scripts/cleanup_old_data.php` | 115 | `@unlink($file)` | YES — CLI script; file may already be deleted |

**Issue (multiaccount.php line 294):** Admin alert emails fail silently. If `mail()` returns false, nothing is logged. Low impact (admin alert is best-effort), but adds a `logWarn` call for completeness.

---

### ERR-P7-007 [INFO] error_log() vs logError() split — 14 sites use error_log() directly in game code

**Files:** `attaquer.php`, `armee.php`, `marche.php`, `includes/game_actions.php`, `includes/compounds.php`, `includes/db_helpers.php`, `includes/player.php`

These sites correctly log errors but use PHP's native `error_log()` instead of the project's `logError()`. As a result, these messages go to the FPM/Apache error log without player login, IP hash, or category fields.

This is a consistency issue, not a functional one. The errors do get recorded. Upgrading to `logError()` throughout would centralize all error data in the application daily logs, making incident response easier.

---

## Summary

| ID | Severity | File | Issue |
|----|----------|------|-------|
| ERR-P7-001 | LOW | includes/database.php | SQL errors use error_log() not logError() |
| ERR-P7-002 | LOW | 16 files | withTransaction() callers lack try/catch — uncaught exceptions give blank pages |
| ERR-P7-003 | LOW | includes/connexion.php | Second die() has zero logging; both use raw die() without HTTP 503 |
| ERR-P7-004 | LOW | .htaccess | PHP-FPM ignores IfModule mod_php.c block — display_errors status unverified on VPS |
| ERR-P7-005 | INFO | (global) | No set_exception_handler() — uncaught exceptions not captured by application logger |
| ERR-P7-006 | INFO | includes/multiaccount.php | @mail() admin alert fails silently without logging |
| ERR-P7-007 | INFO | 7 files | error_log() used instead of logError() in game logic — loses player/IP context |

**Total: 4 LOW, 3 INFO. Zero HIGH or CRITICAL.**

The error handling architecture is solid at the framework level: `withTransaction()` correctly re-throws, combat/market/alliance/season reset all have proper try/catch with user-friendly messages. The remaining gaps are all in lower-traffic code paths (voting, messaging, tutorial rewards) and in log consistency rather than security or data integrity.

**Priority fix order:**
1. ERR-P7-002 — add try/catch to the 16 uncaught withTransaction sites (user-visible on DB errors)
2. ERR-P7-003 — add logging + HTTP 503 to connexion.php DB connection failure
3. ERR-P7-004 — verify VPS php.ini has display_errors=Off; add ini_set fallback in session_init.php
4. ERR-P7-001, ERR-P7-005, ERR-P7-006, ERR-P7-007 — logging consistency improvements
