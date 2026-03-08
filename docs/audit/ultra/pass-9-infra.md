# Pass 9 Infrastructure Audit
**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent
**Scope:** .htaccess files, CI/CD pipeline, health.php, migrations/migrate.php, directory permissions, .phar blocking, vendor/ exposure

---

## Findings

---

### INFRA-P9-001
**Severity:** HIGH
**File:** `data/.htaccess:1`
**Description:** `data/.htaccess` uses a bare `Require all denied` directive with **no `<IfModule mod_authz_core.c>` guard and no legacy Apache 2.2 fallback** (`Order deny,allow` / `Deny from all`). On Apache 2.2 servers (or any environment where `mod_authz_core` is absent), this directive is silently ignored, leaving rate-limiter token files and any other data stored under `data/` fully readable from the web.
**Recommended fix:** Wrap the directive in `<IfModule mod_authz_core.c>` and add a matching `<IfModule !mod_authz_core.c>` block with `Order deny,allow` / `Deny from all`, mirroring the pattern used in `tests/.htaccess`.

---

### INFRA-P9-002
**Severity:** HIGH
**File:** `logs/.htaccess:1`
**Description:** `logs/.htaccess` has the same defect as `data/.htaccess`: a bare `Require all denied` with no `IfModule` guard and no Apache 2.2 fallback. Application logs (which may contain stack traces, DB errors, and player IP addresses) are potentially served as plain text on non-`mod_authz_core` environments.
**Recommended fix:** Apply the same dual `<IfModule>` pattern used in `tests/.htaccess` to provide a safe fallback for legacy Apache.

---

### INFRA-P9-003
**Severity:** HIGH
**File:** `migrations/.htaccess:1-6`
**Description:** `migrations/.htaccess` has the `<IfModule !mod_authz_core.c>` fallback block but it is **missing the `Order deny,allow` line** — it only contains `Deny from all`. In Apache 2.2, `Deny from all` without `Order deny,allow` may not behave as intended depending on the `Order` inherited from a parent context; the explicit `Order` directive is required for deterministic behaviour.
**Recommended fix:** Add `Order deny,allow` before `Deny from all` in the `<IfModule !mod_authz_core.c>` block (mirrors the correct pattern in `tests/.htaccess`).

---

### INFRA-P9-004
**Severity:** HIGH
**File:** `vendor/` (no `.htaccess`)
**Description:** The `vendor/` directory is present in the web root and contains no `.htaccess` protection. While PHP source files inside `vendor/` are not directly executable via a browser (they are PHP files, not scripts that auto-run), any non-PHP resources — README files, composer metadata, `.json` manifests — are served freely. More critically, if the server were ever misconfigured to treat `.php` files as plain text, the entire vendor tree would become readable. Consistent defence-in-depth requires a deny-all `.htaccess` in `vendor/`.
**Recommended fix:** Add a `vendor/.htaccess` with the standard dual `<IfModule>` deny-all pattern used in `tests/.htaccess`.

---

### INFRA-P9-005
**Severity:** HIGH
**File:** `composer.phar` and `phpunit.phar` (web root)
**Description:** Both `composer.phar` and `phpunit.phar` reside in the **document root**. The root `.htaccess` blocks the `.phar` extension via `FilesMatch` (line 15), so they are blocked from being downloaded. However, if Apache ever passes `.phar` files to the PHP interpreter (e.g., via a misconfigured MIME type or `AddHandler`), they could be **executed** rather than denied. Storing executable tooling archives in the web root is unnecessary risk; the block relies entirely on the `.htaccess` remaining intact.
**Recommended fix:** Move `composer.phar` and `phpunit.phar` outside the web root (e.g., to a `tools/` directory at the same level as the web root, or use system-installed versions), and remove them from the repository.

---

### INFRA-P9-006
**Severity:** MEDIUM
**File:** `.htaccess:37-42` (INFRA-P8-007 follow-up)
**Description:** The PHP settings block uses `<IfModule mod_php.c>`. The VPS runs PHP 8.2 via **PHP-FPM** (not `mod_php`), so this entire block is silently skipped in production. The directives `display_errors off`, `log_errors on`, `error_reporting 32767`, and `expose_php off` are **not applied** at the Apache level. `expose_php` in particular would normally suppress the `X-Powered-By: PHP/x.y.z` response header.
**Recommended fix:** Set these PHP flags in the FPM pool's `php.ini` overrides (e.g., `/etc/php/8.2/fpm/pool.d/www.conf` or a per-vhost `php_admin_value`) rather than relying on the `mod_php.c` `.htaccess` block.

---

### INFRA-P9-007
**Severity:** MEDIUM
**File:** `.env.example:1-12`
**Description:** `.env.example` is blocked from web access by the root `.htaccess` `FilesMatch` rule matching the `.env` extension (line 15 — `env` is listed). However, `.env.example` does **not** have the `.env` extension; its extension is `.example`. The `FilesMatch` pattern `\.env` matches `.env` literally (i.e., files whose name ends in `.env`), not files that merely contain the word "env". `.env.example` is therefore **not blocked** by the current rule and is served freely as a plain-text file. It contains the admin alert email address and the DB name, and exposes the expected structure of production credentials to any visitor.
**Recommended fix:** Add `example` to the `FilesMatch` extension list in `.htaccess`, or add a specific `<Files ".env.example">` deny block.

---

### INFRA-P9-008
**Severity:** MEDIUM
**File:** `migrations/migrate.php:1`
**Description:** `migrate.php` has no CLI-only guard. It is protected from web access only by `migrations/.htaccess`. If that `.htaccess` were removed, disabled, or bypassed (e.g., `AllowOverride None` in the vhost), `migrate.php` would be reachable via HTTP, would connect to the database using credentials from `includes/connexion.php`, and would attempt to apply all pending migrations against the production database. There is no `php_sapi_name() === 'cli'` check inside the script itself.
**Recommended fix:** Add `if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }` at the top of `migrate.php` as a defence-in-depth guard independent of `.htaccess`.

---

### INFRA-P9-009
**Severity:** MEDIUM
**File:** `.github/workflows/ci.yml:1-31`
**Description:** The CI pipeline runs PHPUnit (`vendor/bin/phpunit --colors=always`) and will fail the build if tests fail (exit code propagation is implicit). However: (1) there is **no static analysis step** (e.g., PHPStan or Psalm), (2) there is **no security scanning step** (e.g., `composer audit`), and (3) the pipeline only runs on `push` to `main` and `pull_request` to `main` — there is no scheduled nightly run, so dependency vulnerabilities introduced via `composer.lock` are never automatically detected after the initial merge.
**Recommended fix:** Add a `composer audit` step and a scheduled (`schedule: cron`) trigger to catch newly published CVEs in dependencies.

---

### INFRA-P9-010
**Severity:** LOW
**File:** `health.php:43-47`
**Description:** The `health.php` endpoint correctly restricts detailed output (`php` version, `disk_free_gb`) to `127.0.0.1` / `::1`. However, the `db` boolean field (line 44) reveals to localhost callers whether the database is reachable, and the `disk_free_gb` value leaks server capacity information. This is acceptable for internal monitoring but warrants a note: if the monitoring agent is ever moved to an external IP (e.g., UptimeRobot probing from outside), this guard would need to be extended to an allowlist of monitoring IPs rather than localhost-only.
**Recommended fix:** Document in a comment that the `127.0.0.1` check must be updated if external monitoring agents are added, or add an `HEALTH_TOKEN` environment variable check as an alternative auth mechanism.

---

### INFRA-P9-011
**Severity:** LOW
**File:** `tests/.htaccess:1-7`
**Description:** `tests/.htaccess` is correctly structured with both `<IfModule mod_authz_core.c>` and `<IfModule !mod_authz_core.c>` fallback blocks and is considered correct. This is the reference pattern that `data/.htaccess` and `logs/.htaccess` should follow. No action required on this file.
**Recommended fix:** No fix required — document as the canonical `.htaccess` pattern for deny-all directories.

---

### INFRA-P9-012
**Severity:** INFO
**File:** `.htaccess:15`
**Description:** The root `.htaccess` `FilesMatch` correctly blocks `.phar` files (the extension is listed), `.sql`, `.md`, `.json`, `.env`, `.pem`, `.key`, `.crt`, and others. The `.phar` block is present and was added per INFRA finding history. This check passes.
**Recommended fix:** No action required.

---

### INFRA-P9-013
**Severity:** INFO
**File:** `.github/workflows/ci.yml:29-30`
**Description:** PHPUnit is invoked without a `--stop-on-failure` flag, but GitHub Actions will still fail the workflow on non-zero exit codes. The `PHP Syntax Check` step (line 27) correctly lints all non-vendor PHP files. CI correctly rejects on test failures. This check passes.
**Recommended fix:** No action required, though adding `--stop-on-failure` would make feedback faster on large test suites.

---

## Summary Table

| ID | Severity | File | Issue |
|----|----------|------|-------|
| INFRA-P9-001 | HIGH | `data/.htaccess` | No `IfModule` guard — bare `Require all denied` silently ignored on Apache 2.2 |
| INFRA-P9-002 | HIGH | `logs/.htaccess` | Same defect as `data/.htaccess` — no legacy fallback |
| INFRA-P9-003 | HIGH | `migrations/.htaccess` | Missing `Order deny,allow` in `!mod_authz_core.c` fallback block |
| INFRA-P9-004 | HIGH | `vendor/` (missing) | No `.htaccess` in `vendor/` directory |
| INFRA-P9-005 | HIGH | `composer.phar`, `phpunit.phar` | Executable `.phar` files in web root, protected only by `.htaccess` |
| INFRA-P9-006 | MEDIUM | `.htaccess:37-42` | `mod_php.c` block skipped on PHP-FPM; `expose_php off` not applied |
| INFRA-P9-007 | MEDIUM | `.env.example` | Not blocked by `.htaccess` — extension is `.example`, not `.env` |
| INFRA-P9-008 | MEDIUM | `migrations/migrate.php` | No CLI-only guard; relies solely on `.htaccess` for web protection |
| INFRA-P9-009 | MEDIUM | `.github/workflows/ci.yml` | No `composer audit`, no static analysis, no scheduled runs |
| INFRA-P9-010 | LOW | `health.php:43-47` | Localhost-only guard adequate now but not future-proof for external monitors |
| INFRA-P9-011 | LOW | `tests/.htaccess` | PASS — correct reference pattern, no action needed |
| INFRA-P9-012 | INFO | `.htaccess:15` | PASS — `.phar` extension correctly blocked |
| INFRA-P9-013 | INFO | `.github/workflows/ci.yml` | PASS — CI correctly fails on PHPUnit failures |

---

FINDINGS: 0 critical, 5 high, 4 medium, 1 low
