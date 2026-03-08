# Pass 7 Infrastructure Audit
**Date:** 2026-03-08
**Scope:** Deployment config, .htaccess, CI pipeline, cron/scripts, security headers, sensitive file exposure

---

## Findings

### INFRA-P7-001 [MEDIUM] — CI pipeline missing PHP syntax lint step

**File:** `.github/workflows/ci.yml`

**Proof:** The CI workflow runs `composer install` then `vendor/bin/phpunit`. It has no step that runs `php -l` (PHP syntax check) across the codebase. PHPUnit only covers files exercised by test cases; a syntax error in an untested page (e.g., a new admin page or migration include) would pass CI entirely.

```yaml
- name: Run tests
  run: vendor/bin/phpunit --colors=always
# ← No lint step before or after
```

**Fix:** Add a lint step before PHPUnit:
```yaml
- name: PHP syntax lint
  run: find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l
```

---

### INFRA-P7-002 [MEDIUM] — .htaccess PHP settings silently no-op on PHP-FPM

**File:** `.htaccess:37-42`

**Proof:** The PHP security settings are wrapped in `<IfModule mod_php.c>`:
```apache
<IfModule mod_php.c>
    php_flag display_errors off
    php_flag log_errors on
    php_value error_reporting 32767
    php_flag expose_php off
</IfModule>
```

The VPS runs Debian 12 with PHP 8.2 via `php8.2-fpm` (the standard Debian package) behind Apache `mod_proxy_fcgi`. The `mod_php.c` module is not loaded; therefore this entire block is silently skipped. `display_errors`, `log_errors`, and `expose_php` rely on whatever the system `/etc/php/8.2/fpm/php.ini` says — which on a fresh Debian install defaults to `display_errors = Off` in production mode, but this is not enforced by the application itself.

**Fix:** Replace the `<IfModule mod_php.c>` block with a `.user.ini` file in the web root (PHP-FPM reads `.user.ini` per directory):
```ini
; /var/www/html/.user.ini
display_errors = Off
log_errors = On
error_reporting = 32767
expose_php = Off
```
Add `.user.ini` to the `.htaccess` FilesMatch deny block (or create it only on the VPS, not in the repo, if it contains environment-specific values).

---

### INFRA-P7-003 [LOW] — .env.example not blocked by FilesMatch pattern

**File:** `.htaccess:15`

**Proof:** The FilesMatch deny rule uses the pattern:
```
\.(sql|psd|md|json|xml|lock|gitignore|bak|swp|swo|orig|env)$
```
This matches files whose name ends with `.env` (e.g., `.env`). However, `.env.example` ends with `.example`, not `.env`, so it does **not** match. The file is therefore web-accessible at `https://theverylittlewar.com/.env.example`. While it contains no production credentials (it is a template), it does expose the admin alert email address and the expected `.env` variable names, reducing attacker reconnaissance effort.

**Fix:** Extend the FilesMatch pattern to also match `.env.example` and any `.env.*` variants:
```apache
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore|bak|swp|swo|orig|env|env\..*)$|~$">
```
Or add a dedicated rule:
```apache
<FilesMatch "^\.env">
    Require all denied
</FilesMatch>
```

---

### INFRA-P7-004 [LOW] — scripts/ directory has no .htaccess protection

**File:** `scripts/` (no `.htaccess`)

**Proof:** `tools/` has `Require all denied`. `scripts/` does not. `scripts/cleanup_old_data.php` has a PHP `PHP_SAPI !== 'cli'` guard that returns HTTP 403, so it cannot be exploited as a script — but Apache still serves the 403 response, confirming the file exists. More importantly, any future script added to `scripts/` without a SAPI guard would be immediately executable via HTTP.

**Fix:** Add `scripts/.htaccess` mirroring `tools/.htaccess`:
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

### INFRA-P7-005 [LOW] — cron/ directory has no .htaccess protection

**File:** `cron/` (no `.htaccess`), `cron/cleanup-logs.sh`

**Proof:** `cron/cleanup-logs.sh` is a bash script with no PHP SAPI guard. Apache typically serves unrecognised extensions as `text/plain` or `application/octet-stream` (depending on Apache MIME configuration). On many Apache installs `.sh` maps to `text/x-sh` and is served as plain text, exposing the cron job logic, the hardcoded log path `/var/www/html/logs`, and the `find -delete` pattern. This constitutes low-severity information disclosure.

**Fix:** Add `cron/.htaccess`:
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

### INFRA-P7-006 [LOW] — composer.phar and phpunit.phar downloadable from web root

**Files:** `composer.phar` (3.1 MB), `phpunit.phar` (4.9 MB)

**Proof:** Both `.phar` files are in the web root. `.phar` is not in the `.htaccess` FilesMatch deny list. These files are downloadable by any visitor. This discloses exact Composer/PHPUnit version numbers (aiding targeted exploits) and wastes bandwidth. Neither file needs to be in the web root for production operation.

**Fix (preferred):** Remove both files from the web root on the VPS. Composer dependencies are installed via `composer install` from `composer.json`; the `.phar` is only needed during setup. Add to `.htaccess` as a belt-and-suspenders measure:
```apache
<FilesMatch "\.phar$">
    Require all denied
</FilesMatch>
```

---

### INFRA-P7-007 [LOW] — api.php missing several security response headers

**File:** `api.php:14`

**Proof:** `api.php` is a JSON endpoint that only sets:
```php
header('Content-Type: application/json; charset=utf-8');
```
It does not set `X-Content-Type-Options: nosniff`, `X-Frame-Options`, `Referrer-Policy`, or `Permissions-Policy`. The root `.htaccess` sets `X-Content-Type-Options` via `mod_headers`, so that one is covered at the Apache layer. However `X-Frame-Options` and `Referrer-Policy` are not set at the `.htaccess` level (they are delegated to `layout.php` — which `api.php` does not include). In practice, JSON API responses are not framed, so the risk is low, but it is an inconsistency.

**Fix:** Add the missing headers at the top of `api.php` after the Content-Type line:
```php
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

---

### INFRA-P7-008 [INFO] — SECRET_SALT hardcoded in config.php with known TODO

**File:** `includes/config.php:19-20`

**Proof:**
```php
// TODO: Load from .env for production. Currently in source for convenience.
define('SECRET_SALT', 'tvlw_audit_salt_2026');
```
The salt is in the git repository. It is used for hashing PII (IP addresses) in log files. If the git repo is ever made public or cloned by an attacker, the salt is known, making the hashed IPs reversible via rainbow table for known IP ranges.

**Fix:** Load from environment at runtime:
```php
define('SECRET_SALT', getenv('SECRET_SALT') ?: 'tvlw_audit_salt_2026');
```
Set `SECRET_SALT` in the VPS `.env` file to a random 32-character string. The fallback ensures dev environments still work.

---

### INFRA-P7-009 [INFO] — CSP style-src retains unsafe-inline (acknowledged)

**File:** `includes/layout.php:14` (comment lines 5-14)

**Proof:** The CSP header includes `'unsafe-inline'` in `style-src`. This is acknowledged in the code with a TODO comment explaining it is needed for inline `style=""` attributes in PHP-generated HTML. This weakens CSS injection protection.

**Status:** Known/accepted technical debt. Tracked for future refactor.

---

### INFRA-P7-010 [INFO] — HSTS not set (blocked on DNS/HTTPS)

**Files:** `.htaccess`, `includes/layout.php`

**Proof:** `Strict-Transport-Security` header is absent from both `.htaccess` and `layout.php`. This is expected — HTTPS is not yet live because DNS has not been updated to point `theverylittlewar.com` to `212.227.38.111`. HSTS must only be set after HTTPS is confirmed working (adding it on HTTP causes browsers to refuse future HTTP connections permanently).

**Status:** Blocked on DNS propagation. Once Certbot is configured, add:
```apache
Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
```

---

## Summary

| ID | Severity | Description |
|----|----------|-------------|
| INFRA-P7-001 | MEDIUM | CI pipeline has no PHP syntax lint step |
| INFRA-P7-002 | MEDIUM | `.htaccess` PHP settings are inside `<IfModule mod_php.c>` which does not load under PHP-FPM |
| INFRA-P7-003 | LOW | `.env.example` not matched by FilesMatch `\.env$` pattern — web-accessible |
| INFRA-P7-004 | LOW | `scripts/` directory has no `.htaccess` deny |
| INFRA-P7-005 | LOW | `cron/` directory has no `.htaccess` deny; `.sh` file may be served as text |
| INFRA-P7-006 | LOW | `composer.phar` and `phpunit.phar` in web root, downloadable (version fingerprinting) |
| INFRA-P7-007 | LOW | `api.php` missing `X-Frame-Options` and `Referrer-Policy` headers |
| INFRA-P7-008 | INFO | `SECRET_SALT` hardcoded in `config.php` (acknowledged TODO) |
| INFRA-P7-009 | INFO | CSP `style-src: unsafe-inline` retained (acknowledged TODO) |
| INFRA-P7-010 | INFO | HSTS absent (blocked on DNS/HTTPS — expected) |

**2 MEDIUM, 4 LOW, 4 INFO. No CRITICAL findings. All existing security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, CSP) are correctly implemented in `layout.php`; gaps exist only in endpoints that bypass `layout.php` (api.php, health.php) and in the PHP-FPM compatibility of the .htaccess PHP flags.**
