# Pass 2 - Domain 3: Technology & Architecture Deep Dive

**Audit Date:** 2026-03-05
**Auditor:** Pass 2 Deep Analysis
**Scope:** Line-by-line analysis of architecture patterns, dependency issues, technology gaps
**Findings:** P2-D3-001 through P2-D3-075

---

## Table of Contents

1. [PHP 8.2 Compatibility & Modernization](#1-php-82-compatibility--modernization)
2. [Framework7 v1.5 Obsolescence](#2-framework7-v15-obsolescence)
3. [jQuery & JavaScript Architecture](#3-jquery--javascript-architecture)
4. [HTTP Security Headers](#4-http-security-headers)
5. [Apache Configuration Hardening](#5-apache-configuration-hardening)
6. [Composer & Dependency Management](#6-composer--dependency-management)
7. [Database Abstraction Completeness](#7-database-abstraction-completeness)
8. [Error Handling Architecture](#8-error-handling-architecture)
9. [Session & Cookie Configuration](#9-session--cookie-configuration)
10. [Deployment Pipeline](#10-deployment-pipeline)
11. [Architectural Patterns & Anti-Patterns](#11-architectural-patterns--anti-patterns)
12. [Email Infrastructure](#12-email-infrastructure)
13. [Frontend Asset Pipeline](#13-frontend-asset-pipeline)
14. [Severity Summary](#severity-summary)

---

## 1. PHP 8.2 Compatibility & Modernization

### P2-D3-001 | HIGH | composer.json declares PHP >=7.4 but runs on 8.2

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/composer.json:4`
- **Description:** The `"require": {"php": ">=7.4"}` constraint is dangerously permissive. The codebase runs on PHP 8.2, but composer will not enforce minimum 8.2 requirements. If someone deploys on PHP 7.4, features like `random_bytes()` (used in CSP nonce), `str_contains()`, and `match` expressions would fail. More critically, the `phpunit/phpunit: ^9.0` version is the PHP 7.x/8.0 line; PHPUnit 10.x is the current PHP 8.2 line.
- **Impact:** Deployment on wrong PHP version would silently break. PHPUnit 9 is EOL; no security patches.
- **Fix:** Change to `"php": ">=8.2"` and upgrade PHPUnit to `^10.0` or `^11.0`. Update `phpunit.xml` configuration format accordingly.

### P2-D3-002 | MEDIUM | No PSR-4 autoloading -- 97 `global` declarations across includes

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/*.php` (97 occurrences across 10 files)
- **Description:** The codebase uses no classes, no namespaces, and no PSR-4 autoloading. Instead, `composer.json` autoloads three specific files via the `"files"` key, and the rest are loaded via `include()`/`require_once()` chains. All functions access shared state via 97 `global $base`, `global $nomsRes`, etc. declarations. This is the single largest architectural debt item.
- **Impact:** Every function is tightly coupled to global state, making unit testing require elaborate mocking, refactoring dangerous, and dependency graphs invisible. Adding any new feature requires understanding which globals must be available.
- **Fix:** Phased migration to dependency injection:
  1. Create a `GameContext` value object holding `$base`, `$nomsRes`, config arrays
  2. Pass it as first parameter to functions instead of `global`
  3. Eventually wrap in PSR-4 namespaced classes
  4. Use constructor injection in a lightweight DI container

### P2-D3-003 | LOW | `$GLOBALS['csp_nonce']` superglobal usage for CSP nonce

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/csp.php:7`
- **Description:** The CSP nonce is stored in `$GLOBALS['csp_nonce']` and accessed via a function wrapper. While functional, `$GLOBALS` is a PHP anti-pattern that breaks encapsulation. Any code anywhere can overwrite it.
- **Impact:** Fragile -- accidental overwrite would break all CSP nonce validation.
- **Fix:** Use a static variable inside `cspNonce()`:
  ```php
  function cspNonce(): string {
      static $nonce = null;
      if ($nonce === null) {
          $nonce = base64_encode(random_bytes(16));
      }
      return $nonce;
  }
  ```

### P2-D3-004 | LOW | No PHP type declarations anywhere in the codebase

- **Location:** All files in `includes/`
- **Description:** Zero functions use PHP 7.0+ parameter type hints, return types, or PHP 8.0+ union types. For example, `dbQuery($base, $sql, $types = "", ...$params)` should be `dbQuery(mysqli $base, string $sql, string $types = "", mixed ...$params): mysqli_result|false`. PHP 8.2 strongly encourages typed code.
- **Impact:** Runtime type errors are caught late, IDE support is weak, and refactoring safety is low.
- **Fix:** Progressively add type declarations starting with `database.php`, `validation.php`, `csrf.php`, and formula functions.

### P2-D3-005 | LOW | Unused CSS files shipped in `/css/` directory

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/css/` (18 CSS files)
- **Description:** The `css/` directory contains 18 Framework7 CSS files including iOS variants (`framework7.ios.css`, `framework7.ios.min.css`, `framework7.ios.rtl.css`, etc.) that are never loaded. Only `framework7.material.min.css`, `framework7.material.colors.min.css`, `framework7-icons.css`, and `my-app.css` are referenced in `meta.php`.
- **Impact:** Wasted disk space and potential confusion. If `.htaccess` misconfiguration allows directory listing, exposes framework version information.
- **Fix:** Delete all unused CSS files: `framework7.ios.*`, `framework7.material.css` (unminified), `framework7.material.rtl.*`, `framework7.material.colors.css` (unminified).

---

## 2. Framework7 v1.5 Obsolescence

### P2-D3-006 | HIGH | Framework7 v1.5.3 (February 2017) -- 9 years EOL, known vulnerabilities

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/js/framework7.min.js` (line 1 header: "Framework7 1.5.3")
- **Description:** Framework7 1.5.3 was released February 10, 2017. The current version is Framework7 v8.x. Version 1.x is no longer maintained and has known XSS vulnerabilities in its DOM manipulation utilities (Dom7). The entire API has been rewritten three times since then (v2 in 2018, v4 in 2019, v8 in 2023).
- **Impact:** No security patches, no bug fixes, no modern CSS features (CSS variables, grid), no accessibility improvements. The `Dom7` library bundled in v1.5 has XSS-prone `.html()` methods.
- **Fix:** Migration path:
  1. **Short-term:** Audit all `Dom7` `.html()` calls for XSS safety (most are server-rendered via `json_encode`, which is safe)
  2. **Long-term:** Evaluate whether Framework7 is still the right choice. For a simple mobile-first game UI, consider migrating to vanilla CSS with a lightweight component approach, or upgrade to Framework7 v8 (complete rewrite required)

### P2-D3-007 | MEDIUM | Framework7 v1.5 uses deprecated `pushState` routing that conflicts with modern browsers

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:25`
- **Description:** The Framework7 initialization uses `pushState: true` which enables AJAX-based page loading with browser history manipulation. In v1.5, this implementation has known issues with Firefox and Safari's back-forward cache (bfcache). Modern browsers may cache pages differently, causing stale CSRF tokens and session state after back navigation.
- **Impact:** Users hitting "Back" button may see stale forms with expired CSRF tokens, leading to form submission failures.
- **Fix:** Add `Cache-Control: no-store` header for all authenticated pages, or disable pushState and use traditional navigation.

### P2-D3-008 | MEDIUM | `Dom7` alias `$$` conflicts with browser DevTools

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:26`
- **Description:** `var $$ = Dom7;` creates a global `$$` variable that shadows the browser console's `$$` (querySelectorAll alias). While not a runtime issue, it makes debugging in Chrome DevTools confusing.
- **Impact:** Developer experience issue during debugging.
- **Fix:** Document this conflict or rename to `var dom7 = Dom7;`

---

## 3. jQuery & JavaScript Architecture

### P2-D3-009 | HIGH | Two jQuery versions loaded simultaneously -- v1.7.1 and v3.7.1

- **Location:**
  - `/home/guortates/TVLW/The-Very-Little-War/images/partenariat/jquery.js` (jQuery v1.7.1, December 2011)
  - `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:19` (jQuery v3.7.1 CDN with SRI)
- **Description:** `js/loader.js` is Google Charts Loader (not the game's loader), and `images/partenariat/charger_barre.js` loads a partner bar that uses `$()` from jQuery 1.7.1 (bundled locally). The game's copyright.php also loads jQuery 3.7.1 via CDN. Two jQuery instances coexist in memory. jQuery 1.7.1 is 15 years old with multiple known CVEs (XSS in `.html()`, `.append()`, selector parsing).
- **Impact:** jQuery 1.7.1 has CVE-2012-6708, CVE-2015-9251, CVE-2019-11358, CVE-2020-11022, CVE-2020-11023 -- all XSS vulnerabilities. Double jQuery loading wastes ~130KB of bandwidth.
- **Fix:** Remove the bundled jQuery 1.7.1 from `images/partenariat/jquery.js`. Rewrite `charger_barre.js` to use the already-loaded jQuery 3.7.1 or vanilla JS.

### P2-D3-010 | HIGH | `js/loader.js` is Google Charts Loader (~38,000 tokens) -- obfuscated, unversioned

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/js/loader.js`
- **Description:** This file is a locally-bundled copy of Google Charts loader (the Closure Library bootstrap + Charts init). It is obfuscated, unversioned, and extremely large (~38K tokens). The game loads it on **every page** via `copyright.php:20`, even though Google Charts is only used on `marche.php` (market price chart). `marche.php:587` also loads `https://www.gstatic.com/charts/loader.js` from CDN, meaning the CDN version and local copy may conflict.
- **Impact:** Wasted bandwidth on every page load. Potential version conflict between local copy and CDN copy. Security risk from unauditable obfuscated code.
- **Fix:** Remove `js/loader.js` from `copyright.php`. It is only needed on `marche.php`, which already loads it via CDN with SRI.

### P2-D3-011 | MEDIUM | 9 sequential AJAX calls in `actualiserStats()` -- thundering herd on every keystroke

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:129-189`
- **Description:** The `actualiserStats()` function fires 9 parallel `$.ajax()` GET requests to `api.php` on every `input` event of any atom composition field. There is no debouncing. A user typing "100" triggers 3 `input` events x 9 AJAX calls = 27 HTTP requests in under a second. Each request hits `api.php`, which validates session token against the database, checks rate limits (file I/O), includes all game modules, and computes medal bonuses.
- **Impact:** Server load spike during molecule creation. Rate limiter (60/60s) will be exhausted in ~7 keystrokes. After that, the user sees "Rate limit exceeded" errors in the stat preview.
- **Fix:**
  1. Add a 300ms debounce to `actualiserStats()`
  2. Batch all 9 stat calculations into a single API endpoint (`api.php?id=allStats`)
  3. Consider computing stats client-side since all formulas are deterministic

### P2-D3-012 | MEDIUM | `charger_barre.js` partner bar has hardcoded HTTP URLs and XSS-prone DOM insertion

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/images/partenariat/charger_barre.js:20-24`
- **Description:** The partner bar JavaScript constructs HTML via string concatenation and injects it via `$("body").prepend(t)`. The URLs are hardcoded as `http://www.theverylittlewar.com` (not HTTPS). The `e[i].name` and `e[i].txt` values are hardcoded strings (not user input), so XSS is not exploitable today, but the pattern is dangerous.
- **Impact:** Mixed content warnings when HTTPS is enabled. Unsafe DOM construction pattern.
- **Fix:** Update URLs to use `https://` or protocol-relative `//`. Consider removing this partner bar entirely if Pyromagnon partnership is no longer active.

### P2-D3-013 | LOW | `countdown.js` is clean vanilla JS -- good pattern, should be replicated

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/js/countdown.js`
- **Description:** This is a well-written IIFE using strict mode, vanilla DOM APIs, and no jQuery dependency. It demonstrates that jQuery is not needed for simple DOM interactions.
- **Impact:** Positive finding -- this pattern should be replicated for other JS code.
- **Fix:** No action needed. Use as template for future JS refactoring.

### P2-D3-014 | LOW | Inline `<script>` blocks in `copyright.php` contain ~270 lines of JS

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:23-276`
- **Description:** The copyright.php footer contains two large inline `<script>` blocks: one for Framework7 initialization, autocomplete, and error/notification display (~95 lines), and another for number formatting and random name generation (~180 lines). These are properly nonced but cannot be cached by the browser.
- **Impact:** ~270 lines of JS re-downloaded on every page load instead of being cacheable in external files.
- **Fix:** Extract static JS (nFormatter, symboleEnNombre, generate, genererLettre, genererConsonne) into `js/app.js`. Keep only the PHP-dynamic parts (player list, error/info display) inline.

### P2-D3-015 | LOW | `type="text/javascript"` attribute is redundant on `<script>` tags

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:18,20,21` and `marche.php:587`
- **Description:** HTML5 default `type` for `<script>` is `text/javascript`. Including the attribute explicitly is unnecessary boilerplate.
- **Impact:** Minor HTML bloat, no functional impact.
- **Fix:** Remove `type="text/javascript"` from all `<script>` tags.

---

## 4. HTTP Security Headers

### P2-D3-016 | CRITICAL | No `Strict-Transport-Security` (HSTS) header -- BLOCKED on HTTPS

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
- **Description:** No HSTS header is configured anywhere. This is documented as blocked on DNS pointing to the VPS (212.227.38.111) and certbot setup. Without HSTS, even after HTTPS is enabled, the first request to `http://theverylittlewar.com` will be unencrypted, allowing SSL stripping attacks.
- **Impact:** Session cookies, CSRF tokens, and credentials transmitted in plaintext on first visit.
- **Fix:** After HTTPS is live: `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"`. Prepare the `.htaccess` entry now with a comment to uncomment after certbot.

### P2-D3-017 | HIGH | No `Permissions-Policy` header -- unrestricted browser API access

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
- **Description:** Without `Permissions-Policy`, if an XSS attack occurs, the attacker can access camera, microphone, geolocation, payment API, and other sensitive browser features. A strategy game has no legitimate need for any of these.
- **Impact:** Expanded attack surface for XSS exploits.
- **Fix:** Add to `.htaccess`:
  ```
  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()"
  ```

### P2-D3-018 | MEDIUM | `X-XSS-Protection: 1; mode=block` is deprecated and counterproductive

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess:5`
- **Description:** The `X-XSS-Protection` header triggers the browser's built-in XSS auditor, which has been removed from Chrome (2019), Firefox (never supported), and Edge (Chromium). The old XSS auditor itself had vulnerabilities that could be exploited to create new XSS vectors. Modern best practice is to set `X-XSS-Protection: 0` and rely on CSP instead.
- **Impact:** On old IE/Edge Legacy, the XSS auditor can introduce information leaks.
- **Fix:** Change to `Header set X-XSS-Protection "0"` and rely on the existing CSP header.

### P2-D3-019 | MEDIUM | CSP `style-src 'unsafe-inline'` undermines XSS protection

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:3`
- **Description:** The CSP header includes `style-src 'self' 'unsafe-inline'`. The `'unsafe-inline'` directive allows any injected `<style>` tag or `style="..."` attribute to execute. While CSS injection is less dangerous than JS injection, it enables UI redressing attacks (CSS-based clickjacking), data exfiltration via CSS selectors (`input[value^="a"]`), and keylogging via CSS animations.
- **Impact:** CSS injection attacks remain possible despite CSP.
- **Fix:** Refactor all inline `style="..."` attributes to CSS classes (there are many throughout layout.php and game pages). Then replace `'unsafe-inline'` with `'nonce-$nonce'` for styles, matching the script approach.

### P2-D3-020 | MEDIUM | CSP allows `www.gstatic.com` on all pages but Google Charts only used on `marche.php`

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:3`
- **Description:** The CSP `script-src` directive includes `https://www.gstatic.com` on every page, but Google Charts is only loaded on `marche.php`. This broadens the attack surface unnecessarily -- if `gstatic.com` serves any user-controllable content, it becomes a CSP bypass vector.
- **Impact:** Widened CSP trust boundary across all pages.
- **Fix:** Set a tighter CSP on most pages and add `gstatic.com` only on `marche.php` via a page-specific CSP override.

### P2-D3-021 | LOW | CSP `img-src` allows `data:` URIs globally

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:3`
- **Description:** `img-src 'self' data: https://www.theverylittlewar.com` allows `data:` URI images, which can be used for tracking pixels and data exfiltration in combination with XSS.
- **Impact:** Minor CSP weakening.
- **Fix:** If `data:` URIs are not actually used in `<img>` tags, remove `data:` from `img-src`.

### P2-D3-022 | LOW | No `X-Permitted-Cross-Domain-Policies` header

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
- **Description:** Missing `X-Permitted-Cross-Domain-Policies: none` header. While Flash Player is dead, PDF readers still check `crossdomain.xml` policies.
- **Impact:** Minimal -- Flash is dead, but defense-in-depth.
- **Fix:** Add `Header always set X-Permitted-Cross-Domain-Policies "none"` to `.htaccess`.

---

## 5. Apache Configuration Hardening

### P2-D3-023 | HIGH | `.htaccess` FilesMatch does not block `.env` files

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess:15`
- **Description:** The FilesMatch pattern `\.(sql|psd|md|json|xml|lock|gitignore)$` blocks common sensitive file types, but does not include `.env` files. The `.env` file contains database credentials (`DB_USER`, `DB_PASS`, `DB_HOST`, `DB_NAME`) and the admin password hash. While the hidden files rule (`^\.`) should catch `.env`, this only works if the filename starts with a dot. If a backup like `env.bak` or `env.txt` were created, it would be accessible.
- **Impact:** Database credentials potentially exposed if `.env` file naming convention changes.
- **Fix:** Add `env` to the FilesMatch pattern: `\.(sql|psd|md|json|xml|lock|gitignore|env)$`. Also add a specific rule for `.env*` files.

### P2-D3-024 | MEDIUM | No `mod_php` detection for PHP-FPM configurations

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess:37-42`
- **Description:** The PHP settings block uses `<IfModule mod_php.c>` which only works with Apache's `mod_php`. If the server switches to PHP-FPM (which is recommended for PHP 8.2 for performance), these `php_flag`/`php_value` directives are silently ignored, and `display_errors` may revert to `On`.
- **Impact:** PHP errors could be displayed to users if server config changes to PHP-FPM.
- **Fix:** Set these values in `php.ini` or a `.user.ini` file (which works with PHP-FPM), or add equivalent `ini_set()` calls in the bootstrap.

### P2-D3-025 | MEDIUM | No cache control headers for static assets

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
- **Description:** No `Cache-Control`, `Expires`, or `ETag` headers are configured for static assets (CSS, JS, images, fonts). Every page load re-downloads all Framework7 CSS, custom CSS, Framework7 JS, and game images from the server.
- **Impact:** Slow page loads, wasted bandwidth, poor mobile experience.
- **Fix:** Add cache control for static assets:
  ```apache
  <IfModule mod_expires.c>
      ExpiresActive On
      ExpiresByType image/png "access plus 1 month"
      ExpiresByType image/jpeg "access plus 1 month"
      ExpiresByType text/css "access plus 1 week"
      ExpiresByType application/javascript "access plus 1 week"
      ExpiresByType font/woff2 "access plus 1 year"
  </IfModule>
  ```

### P2-D3-026 | MEDIUM | No gzip/deflate compression configured

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
- **Description:** No `mod_deflate` configuration for compressing HTML, CSS, JS, and JSON responses. The Framework7 minified JS alone is ~200KB uncompressed, which could be ~60KB gzipped.
- **Impact:** 3-5x larger response sizes than necessary.
- **Fix:** Add to `.htaccess`:
  ```apache
  <IfModule mod_deflate.c>
      AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json
  </IfModule>
  ```

### P2-D3-027 | LOW | `data/` directory protection relies on `.htaccess` in parent

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/data/`
- **Description:** The `data/rates/` directory contains rate limiter JSON files with IP-derived filenames. The `.gitignore` references `!data/.htaccess`, implying a `.htaccess` exists in `data/`, but the main `.htaccess` does not have a Directory-level deny rule for `data/`.
- **Impact:** Rate limiter files potentially accessible via HTTP.
- **Fix:** Ensure `data/.htaccess` contains `Require all denied` and verify it is deployed.

---

## 6. Composer & Dependency Management

### P2-D3-028 | HIGH | PHPUnit 9.x is EOL -- no security patches since February 2024

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/composer.json:8`
- **Description:** `"phpunit/phpunit": "^9.0"` is in the `require-dev` section. PHPUnit 9 reached end-of-life in February 2024. PHPUnit 10 (for PHP 8.1+) and PHPUnit 11 (for PHP 8.2+) are the current supported versions. The `phpunit.xml` uses the v9 schema (`<phpunit ... verbose="true">` -- the `verbose` attribute was removed in PHPUnit 10).
- **Impact:** No security patches for the test framework. CI pipeline running on known-EOL software.
- **Fix:** Upgrade to PHPUnit 11 (`^11.0`), update `phpunit.xml` to the v11 schema, and update any deprecated assertions.

### P2-D3-029 | MEDIUM | `composer.lock` is gitignored -- non-reproducible builds

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.gitignore:26`
- **Description:** The `.gitignore` file includes `composer.lock`. This means every `composer install` resolves dependencies fresh, potentially getting different versions of PHPUnit and its dependencies. Two developers or CI runs may use different package versions.
- **Impact:** Non-reproducible builds. A PHPUnit patch version bump could break tests without any code change.
- **Fix:** Remove `composer.lock` from `.gitignore` and commit it. This is the official Composer recommendation for applications (as opposed to libraries).

### P2-D3-030 | MEDIUM | No production dependencies -- zero runtime Composer packages

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/composer.json:4-5`
- **Description:** The `"require"` section only contains `"php": ">=7.4"`. There are no runtime Composer dependencies. While minimizing dependencies is good for security, the application reinvents several wheels:
  - `env.php` -- a custom .env parser (could use `vlucas/phpdotenv`)
  - `rate_limiter.php` -- a custom file-based rate limiter
  - `logger.php` -- a custom file logger (could use `monolog/monolog`)
  - `csrf.php` -- custom CSRF implementation
  - Email via raw `mail()` (could use `symfony/mailer` or `phpmailer/phpmailer`)
- **Impact:** Each custom implementation must be maintained, tested, and security-audited independently. The `mail()` function is particularly risky (header injection, deliverability issues).
- **Fix:** Consider adopting at minimum:
  1. `phpmailer/phpmailer` for email (most impactful -- fixes header injection, adds SMTP support, SPF/DKIM)
  2. `monolog/monolog` for structured logging (PSR-3 compliant, log rotation, severity levels)
  3. Keep custom .env, CSRF, and rate limiter -- they are simple enough

### P2-D3-031 | LOW | No `composer.json` `scripts` section for common tasks

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/composer.json`
- **Description:** No `"scripts"` section defining `test`, `lint`, `cs-fix`, or `deploy` commands. Developers must remember exact commands.
- **Impact:** Developer experience issue.
- **Fix:** Add:
  ```json
  "scripts": {
      "test": "phpunit --colors=always",
      "lint": "php -l includes/*.php *.php"
  }
  ```

---

## 7. Database Abstraction Completeness

### P2-D3-032 | CRITICAL | `health.php` uses raw `mysqli_query()` bypassing the abstraction layer

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/health.php:14`
- **Description:** `$result = mysqli_query($base, 'SELECT 1');` is a raw mysqli call that bypasses `database.php`. While this specific query is safe (no user input), it breaks the architectural rule that ALL database access goes through `dbQuery()`/`dbFetchOne()`/`dbExecute()`.
- **Impact:** Sets precedent for bypassing the abstraction layer. If the query were to be parameterized later, the pattern is wrong.
- **Fix:** Replace with: `$result = dbFetchOne($base, 'SELECT 1 AS ok', ''); $db_ok = ($result !== null);`

### P2-D3-033 | HIGH | `dbQuery()` returns `false` on failure but callers don't consistently check

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php:11-28`
- **Description:** `dbQuery()` returns `false` if `mysqli_prepare()` or `mysqli_stmt_execute()` fails. `dbFetchOne()` maps this to `null` (line 35), `dbFetchAll()` maps to `[]` (line 46), and `dbExecute()` returns `false` (line 62). However, callers throughout the codebase rarely check these return values:
  - `initPlayer()` in `player.php` calls `dbFetchOne()` and immediately accesses array keys without null checks
  - `updateRessources()` assumes `dbFetchOne()` returns valid data
  - `withTransaction()` catches `Exception` but `dbExecute()` returns `false` (not throws) on failure, so the transaction silently continues after a failed statement
- **Impact:** Silent data corruption on database failures. A failed UPDATE inside `withTransaction()` would not trigger rollback because `dbExecute()` returns `false` instead of throwing.
- **Fix:** Create a `dbExecuteOrThrow()` variant that throws on failure, and use it inside all `withTransaction()` callbacks. Alternatively, modify `dbExecute()` to throw on failure (breaking change, requires audit of all callers).

### P2-D3-034 | MEDIUM | `withTransaction()` only catches `Exception`, not `Throwable`

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php:117`
- **Description:** `catch (Exception $e)` does not catch `Error` subclasses (TypeError, DivisionByZeroError, etc.). If a PHP 8 TypeError occurs inside a transaction callback (e.g., passing null where string expected), the transaction is left open and the `Error` propagates without rollback.
- **Impact:** Orphaned database transactions on PHP runtime errors.
- **Fix:** Change to `catch (\Throwable $e)` to catch both `Exception` and `Error`.

### P2-D3-035 | MEDIUM | No `dbLastId()` usage validation -- race condition window

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php:80-82`
- **Description:** `dbLastId()` wraps `mysqli_insert_id()` but is not tied to a specific statement. If two inserts happen rapidly (e.g., inside a transaction), calling `dbLastId()` after `dbExecute()` may return the wrong ID if another connection inserts between. This is inherent to `mysqli_insert_id()` being connection-scoped, but the API does not make this constraint visible.
- **Impact:** Potential wrong ID returned in high-concurrency scenarios. Currently mitigated by `withTransaction()` serialization.
- **Fix:** Document the constraint. Consider returning the insert ID directly from `dbExecute()` for INSERT statements by checking `mysqli_stmt_insert_id()` before closing the statement.

### P2-D3-036 | LOW | Database connection uses `utf8mb4` but tables are `latin1`

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php:20`
- **Description:** `mysqli_set_charset($base, 'utf8mb4')` sets the connection charset to UTF-8, but the memory/MEMORY.md states "Database charset: latin1 (new tables MUST use latin1 for FK compatibility with membre)". This charset mismatch means PHP sends UTF-8 encoded strings which MariaDB interprets as latin1 column data. For ASCII-only French text (accents encoded differently), this works by accident, but true UTF-8 characters (emoji, CJK) would be garbled.
- **Impact:** Character corruption for non-latin1 characters. The `charset="utf-8"` meta tag in HTML promises UTF-8 to browsers, but the database stores latin1.
- **Fix:** This is a known constraint. Long-term: migrate all tables to `utf8mb4` with a coordinated schema migration. Short-term: document the charset architecture clearly.

---

## 8. Error Handling Architecture

### P2-D3-037 | HIGH | Dual logging systems -- `error_log()` and `gameLog()` used inconsistently

- **Location:** Multiple files (21 `error_log()` calls, 43 `logInfo/Warn/Error()` calls)
- **Description:** The codebase has two competing logging mechanisms:
  1. PHP's built-in `error_log()` -- used in `database.php` (4 calls), `armee.php` (6), `attaquer.php` (2), `constructions.php` (2), `marche.php` (2), `db_helpers.php` (3)
  2. Custom `gameLog()` via `logger.php` -- used in `combat.php` (9), `game_actions.php` (3), `basicpublicphp.php` (4), `multiaccount.php` (5), admin pages, etc.

  `error_log()` writes to PHP's error log (usually `/var/log/apache2/error.log`), while `gameLog()` writes to `logs/YYYY-MM-DD.log`. There is no unified way to search all logs.
- **Impact:** Debugging requires checking two separate log locations. Database errors go to one place, game events to another.
- **Fix:** Replace all `error_log()` calls with `logError()` calls. Keep `error_log()` only in the database layer where `logger.php` may not yet be loaded (bootstrap order issue), and add a comment explaining why.

### P2-D3-038 | MEDIUM | No global error/exception handler registered

- **Location:** Absent -- no `set_error_handler()`, `set_exception_handler()`, or `register_shutdown_function()` anywhere
- **Description:** Zero instances of `set_error_handler()`, `set_exception_handler()`, or `register_shutdown_function()` exist in the codebase. If an uncaught exception or fatal error occurs, PHP's default handler displays a generic error (or nothing with `display_errors` off). The user sees a blank page or partial HTML.
- **Impact:** Uncaught exceptions produce white screens with no logging. OOM errors, uncatchable fatals, and type errors all silently fail.
- **Fix:** Create `includes/error_handler.php` and include it in the bootstrap:
  ```php
  set_exception_handler(function(\Throwable $e) {
      logError('FATAL', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
      http_response_code(500);
      // Show user-friendly error page
  });
  register_shutdown_function(function() {
      $error = error_get_last();
      if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
          logError('FATAL', $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
      }
  });
  ```

### P2-D3-039 | MEDIUM | `die()` calls in `connexion.php` produce raw text output

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php:11,17`
- **Description:** Two `die()` calls produce raw text without HTML, headers, or logging:
  - `die('Configuration error. Contact administrator.');` (line 11)
  - `die('Erreur de connexion a la base de donnees.');` (line 17)
  These fire before layout.php is loaded, so the user sees plain text in the browser without any styling.
- **Impact:** Ugly user experience on database failure. No logging of the failure event.
- **Fix:** Replace with a minimal error page that includes basic HTML and logs the error:
  ```php
  error_log('TVLW CRITICAL: Database connection failed');
  http_response_code(503);
  echo '<!DOCTYPE html><html><body><h1>Service temporairement indisponible</h1></body></html>';
  exit;
  ```

### P2-D3-040 | LOW | `logger.php` uses `file_put_contents()` with `LOCK_EX` -- performance bottleneck under load

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php:33`
- **Description:** Every log write acquires an exclusive file lock on the daily log file. Under concurrent requests, this creates lock contention. With 50+ simultaneous users, log writes may queue up.
- **Impact:** Logging becomes a bottleneck under high concurrency. Not a current issue but will be if player count grows.
- **Fix:** Accept for now. If scaling becomes an issue, switch to `error_log()` (uses syslog which handles concurrency) or adopt Monolog with a buffered handler.

---

## 9. Session & Cookie Configuration

### P2-D3-041 | HIGH | Admin and moderation panels use raw `session_start()` bypassing security hardening

- **Location:**
  - `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php:3` -- `session_start()` directly
  - `/home/guortates/TVLW/The-Very-Little-War/admin/index.php:5` -- `session_start()` directly
  - `/home/guortates/TVLW/The-Very-Little-War/admin/redirectionmotdepasse.php:3` -- `session_start()` directly
- **Description:** The admin and moderation panels call `session_start()` directly instead of using `includes/session_init.php`. This means they lack:
  - `cookie_httponly` (cookies accessible to JavaScript)
  - `cookie_samesite` (no SameSite=Lax protection)
  - `use_strict_mode` (accepts uninitialized session IDs)
  - `gc_maxlifetime` setting

  They do use separate session names (`TVLW_ADMIN`, `TVLW_MOD`), which is good for isolation, but the session cookies themselves have weaker security properties.
- **Impact:** Admin session cookies could be stolen via XSS or CSRF, since httpOnly and SameSite are not set.
- **Fix:** Refactor to call session security settings before `session_start()`:
  ```php
  session_name('TVLW_ADMIN');
  ini_set('session.cookie_httponly', 1);
  ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
  ini_set('session.use_strict_mode', 1);
  ini_set('session.cookie_samesite', 'Lax');
  session_start();
  ```
  Or create a shared `session_init_with_name($name)` function.

### P2-D3-042 | MEDIUM | Session cookie `Secure` flag is conditional on HTTPS detection

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php:10`
- **Description:** `ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0)` dynamically sets the Secure flag based on whether the current request is HTTPS. On the current HTTP-only deployment, this means the Secure flag is **never set**. When HTTPS is eventually enabled, if some requests arrive via HTTP (before redirect), the session cookie will be transmitted insecurely.
- **Impact:** Session cookie sent in plaintext on HTTP requests.
- **Fix:** Once HTTPS is enabled, hardcode to `1`. In the meantime, this is acceptable behavior.

### P2-D3-043 | MEDIUM | No session fingerprinting -- session fixation resilience is incomplete

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:20-23`
- **Description:** Session IDs are regenerated every 30 minutes (good), and on login (good). However, there is no validation that the session is being used from the same user agent or IP. If an attacker steals a session ID (e.g., via network sniffing on HTTP), they can use it from any device/location without triggering any alert.
- **Impact:** Stolen sessions are fully usable from different IPs/devices.
- **Fix:** Add basic session fingerprinting:
  ```php
  $fingerprint = md5($_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
  if (isset($_SESSION['fingerprint']) && $_SESSION['fingerprint'] !== $fingerprint) {
      session_destroy(); // Suspicious session reuse
  }
  $_SESSION['fingerprint'] = $fingerprint;
  ```
  Note: This can cause legitimate issues with users whose IP changes (mobile networks), so make the IP check optional or only log a warning.

### P2-D3-044 | LOW | Session idle timeout (1 hour) may be too long for a game with real-time elements

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/config.php:563`
- **Description:** `SESSION_IDLE_TIMEOUT = 3600` (1 hour) keeps sessions alive for a long time. The online status check uses 5 minutes (`ONLINE_TIMEOUT_SECONDS`), so a player is shown as "offline" after 5 minutes but their session remains valid for 55 more minutes.
- **Impact:** Stale sessions consuming server memory and database session_token entries.
- **Fix:** Consider reducing to 30 minutes (1800 seconds). The game already regenerates session IDs every 30 minutes.

---

## 10. Deployment Pipeline

### P2-D3-045 | HIGH | CI pipeline has no linting, no static analysis, no security scanning

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.github/workflows/ci.yml`
- **Description:** The CI pipeline only runs `composer install` and `phpunit`. It lacks:
  1. PHP syntax check (`php -l`)
  2. Static analysis (PHPStan, Psalm, or Phan)
  3. Dependency vulnerability scanning (`composer audit` -- available since Composer 2.4)
  4. Code style checking (PHP-CS-Fixer or PHP_CodeSniffer)
  5. Security-specific scanning
  6. No build artifact or deployment step
- **Impact:** Broken PHP syntax, type errors, and known vulnerable dependencies can be pushed to main without detection.
- **Fix:** Add at minimum:
  ```yaml
  - name: PHP syntax check
    run: find . -name "*.php" -not -path "./vendor/*" | xargs -n 1 php -l

  - name: Security audit
    run: composer audit
  ```

### P2-D3-046 | MEDIUM | No automated deployment from CI to VPS

- **Location:** Absent -- no deployment step in CI, deployment is manual via SSH
- **Description:** The deployment process is manual: SSH to VPS, `git pull`, verify. There is no CI/CD step for automated deployment, no rollback mechanism, and no smoke test after deployment.
- **Impact:** Manual deployment is error-prone and slow. No automated verification that the deployed version works.
- **Fix:** Add a deployment job to CI that:
  1. SSHs to VPS (using secrets for SSH key)
  2. Runs `git pull`
  3. Runs `php health.php` to verify deployment
  4. Optionally runs a subset of tests on the live database

### P2-D3-047 | MEDIUM | CI uses `ubuntu-latest` which will change PHP availability over time

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.github/workflows/ci.yml:10`
- **Description:** `runs-on: ubuntu-latest` will silently change the Ubuntu version when GitHub updates their runners. While `shivammathur/setup-php@v2` handles PHP version, other system dependencies (MariaDB client libraries, etc.) may change.
- **Impact:** CI may break unexpectedly when GitHub updates `ubuntu-latest`.
- **Fix:** Pin to `ubuntu-24.04` (or whatever the current version is).

### P2-D3-048 | LOW | No CI caching for Composer dependencies

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/.github/workflows/ci.yml:24`
- **Description:** Each CI run downloads all Composer dependencies from scratch. Adding a cache step would save ~10-30 seconds per run.
- **Impact:** Slower CI feedback loop.
- **Fix:** Add:
  ```yaml
  - name: Cache Composer packages
    uses: actions/cache@v4
    with:
      path: vendor
      key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
  ```
  (Requires committing `composer.lock` first -- see P2-D3-029.)

---

## 11. Architectural Patterns & Anti-Patterns

### P2-D3-049 | HIGH | God Function: `initPlayer()` -- 370+ lines, 50+ global variable exports

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:100-490`
- **Description:** `initPlayer()` is the most critical function in the codebase. It queries 6+ database tables, computes building levels, resource totals, medal bonuses, alliance data, and exports 50+ variables into `$GLOBALS`. It runs on every authenticated page load. The function is a Single Responsibility violation -- it is effectively the application's dependency injection container, data layer, and initialization logic rolled into one.
- **Impact:** Any bug in `initPlayer()` affects every page. The function is extremely difficult to test because it requires a full database with correct data in 6 tables. Changes to any one system (medals, alliances, buildings) require modifying this function.
- **Fix:** Decompose into focused sub-functions:
  - `loadPlayerResources($login)` returns resource data
  - `loadPlayerBuildings($login)` returns building levels
  - `loadPlayerMedals($login)` returns medal tiers and bonuses
  - `loadPlayerAlliance($login)` returns alliance data
  - Keep `initPlayer()` as an orchestrator that calls these and populates the cache

### P2-D3-050 | HIGH | Template layer mixes PHP logic and HTML throughout -- no separation of concerns

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php`, `includes/copyright.php`, all page files
- **Description:** The application has no template engine. PHP files freely mix business logic, SQL queries, and HTML output. `layout.php` alone contains:
  - Database queries (lines 107-112)
  - PHP formula computations (lines 108-122)
  - JavaScript generation via PHP echo (lines 127-198)
  - HTML structure (throughout)
  - CSS inline styles (throughout)

  This pattern repeats across all 80+ PHP page files.
- **Impact:** Changes to the UI require modifying the same files as business logic changes. Testing the UI is impossible without a database. Designers cannot work on HTML/CSS without understanding PHP.
- **Fix:** Long-term: adopt a lightweight template engine (Plates, Twig, or even PHP template files with extract). Short-term: at minimum, extract the molecule stat computation and AJAX JavaScript from `layout.php` into a separate `includes/molecule_stats_widget.php`.

### P2-D3-051 | MEDIUM | `include()` used instead of `require_once()` for critical dependencies

- **Location:** Multiple files:
  - `includes/basicprivatephp.php:5` -- `include("includes/connexion.php")`
  - `includes/basicpublicphp.php:2` -- `include("includes/connexion.php")`
  - `includes/layout.php:8-9` -- `include("includes/meta.php")`, `include("includes/style.php")`
  - `includes/game_actions.php:134` -- `include("includes/combat.php")`
- **Description:** `include()` produces a warning and continues execution if the file is missing. For critical files like `connexion.php` (database connection), `fonctions.php` (all game functions), and `combat.php` (combat resolution), a missing file should be a fatal error, not a warning. Also, using `include()` without `_once` means duplicate inclusion can cause "function already defined" errors.
- **Impact:** If a critical include file is accidentally deleted or renamed, the application continues with undefined functions/variables, producing cryptic errors downstream.
- **Fix:** Replace all `include()` with `require_once()` for critical dependencies. Use `__DIR__` for relative paths.

### P2-D3-052 | MEDIUM | `constantesBase.php` duplicates config.php data into legacy variables

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`
- **Description:** This file requires `config.php` then copies all config arrays into duplicate variables with different names:
  - `$nomsRes = $RESOURCE_NAMES` (line 5)
  - `$lettre = array("C","N","H","O","Cl","S","Br","I")` (line 10, duplicates `$RESOURCE_LETTERS`)
  - `$paliersMedailles = [...]` (line 23, duplicates `$MEDAL_TIER_NAMES`)
  - `$bonusMedailles = [1,3,6,10,15,20,30,50]` (line 26, duplicates `$MEDAL_BONUSES`)
  - All medal threshold arrays duplicated (lines 30-39)

  This means every constant exists in two places. `config.php` is the "source of truth" but the legacy names are used everywhere.
- **Impact:** Confusion about which variable name to use. Risk of desync if someone updates one but not the other.
- **Fix:** Progressively replace legacy variable names with `config.php` constants/arrays throughout the codebase, then remove `constantesBase.php`.

### P2-D3-053 | MEDIUM | File-based rate limiter has no garbage collection for old entries

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php`
- **Description:** The rate limiter creates JSON files in `data/rates/` keyed by `md5(identifier . '_' . action)`. Old entries within a file are filtered by timestamp (lines 24-28), but once a file is created, it is never deleted. Over time, the directory accumulates thousands of files (one per unique IP + action combination). The `file_get_contents()` + `json_decode()` + `json_encode()` + `file_put_contents()` cycle is not atomic -- concurrent requests can produce race conditions.
- **Impact:** Disk usage grows without bound. Rate limit checks become slower as the filesystem fills. Under concurrent requests, two processes may read the same file simultaneously, both pass the limit check, and write conflicting data.
- **Fix:**
  1. Add a probabilistic GC: `if (mt_rand(1, 100) === 1) { // cleanup old files }`
  2. Use `flock()` for atomic read-modify-write, or migrate to a database-backed rate limiter
  3. Add a cron job to delete files older than 24 hours

### P2-D3-054 | LOW | `env.php` parser does not handle multi-line values, comments mid-line, or export prefix

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/env.php`
- **Description:** The custom `.env` parser is minimal (13 lines). It does not handle:
  - Multi-line values (e.g., private keys)
  - Comments at end of line (`KEY=value # comment`)
  - `export KEY=value` syntax
  - Escaped characters (`\n`, `\"`)
  - Variable interpolation (`${OTHER_VAR}`)

  Additionally, `putenv()` is used which is not thread-safe in PHP-FPM with threads.
- **Impact:** Limited `.env` syntax support. Thread-safety issue if migrating to PHP-FPM with worker threads (unlikely for this project).
- **Fix:** Accept for now -- the current `.env` file only has 4 simple key=value pairs. If more complex configuration is needed, adopt `vlucas/phpdotenv`.

---

## 12. Email Infrastructure

### P2-D3-055 | HIGH | Raw `mail()` function with manual MIME boundary construction

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:146-205`
- **Description:** Season reset emails are sent via PHP's built-in `mail()` function with manually constructed MIME multipart messages. The implementation:
  1. Uses `md5(rand())` for MIME boundary generation (line 168) -- weak randomness
  2. Manually constructs multipart/alternative with hardcoded charset and encoding
  3. Iterates over ALL players and sends individual emails synchronously
  4. Has a typo in the From address: `noreply@theverylittewar.com` (missing an 'l' -- "littlewar" not "littlewar")
  5. Filters Hotmail/Live/MSN for newline differences (outdated workaround from 2010)
  6. No SPF, DKIM, or DMARC headers
- **Impact:** Emails likely land in spam. The From address typo means replies bounce. Synchronous sending of N emails blocks the page load for all concurrent users during season reset. `md5(rand())` for boundary is predictable and could theoretically be exploited for MIME injection.
- **Fix:** Adopt `phpmailer/phpmailer`:
  ```php
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->setFrom('noreply@theverylittlewar.com', 'The Very Little War');
  // ... configure SMTP, DKIM, etc.
  ```
  Move email sending to a background job (cron or queue).

### P2-D3-056 | MEDIUM | Email sending during season reset blocks all players

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:146-205`
- **Description:** The season reset email loop (`foreach ($mailRows as $donnees)`) runs synchronously inside the authenticated page load. If there are 100 registered players, the server sends 100 individual emails (each with DNS lookup, SMTP handshake, etc.) while the advisory lock is released but the page is still loading. Any player who connects during this time waits for all emails to complete.
- **Impact:** Season reset can take 30-60+ seconds, during which the triggering player sees a blank/loading page.
- **Fix:** Decouple email from the web request:
  1. Insert email jobs into a `pending_emails` database table
  2. Process them via a cron job running every minute
  3. Or use `exec('php send_season_emails.php > /dev/null 2>&1 &')` for a quick background process

### P2-D3-057 | LOW | `mail()` return value checked but no retry mechanism

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php:200-203`
- **Description:** `$mailResult = mail(...)` is checked, and failures are logged via `logWarn()`. But failed emails are never retried. If the mail server is temporarily down during season reset, those players never get notified.
- **Impact:** Silent email delivery failure with no retry.
- **Fix:** Queue failed emails for retry (requires the background job from P2-D3-056).

---

## 13. Frontend Asset Pipeline

### P2-D3-058 | HIGH | No asset versioning/cache busting -- stale CSS/JS after deployments

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php:21-25`
- **Description:** CSS files are loaded without any version query string:
  ```html
  <link rel="stylesheet" href="css/framework7.material.min.css">
  <link rel="stylesheet" href="css/my-app.css">
  ```
  Similarly, JS files in `copyright.php`:
  ```html
  <script src="js/framework7.min.js"></script>
  ```
  After a deployment that changes CSS or JS, users with cached versions will see the old styles/behavior until their browser cache expires.
- **Impact:** Players see broken layouts after CSS updates until they hard-refresh.
- **Fix:** Add version query strings:
  ```php
  <link rel="stylesheet" href="css/my-app.css?v=<?php echo filemtime('css/my-app.css'); ?>">
  ```
  Or use a deployment version constant.

### P2-D3-059 | MEDIUM | Custom fonts declared without `font-display` property

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/style.php:36-60`
- **Description:** Two `@font-face` declarations (`magmawave_capsbold` and `bpmoleculesregular`) are defined without `font-display` property. The default behavior (`auto`) typically blocks text rendering until the font loads, causing a Flash of Invisible Text (FOIT).
- **Impact:** Text using custom fonts is invisible for up to 3 seconds on slow connections.
- **Fix:** Add `font-display: swap;` to both `@font-face` declarations.

### P2-D3-060 | MEDIUM | `includes/style.php` contains CSS in a `<style>` tag -- no caching

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/style.php` (304 lines)
- **Description:** The entire application's custom CSS (304 lines) is in a PHP file that outputs a `<style>` tag inline. This CSS is re-sent with every HTML page response and cannot be cached independently by the browser.
- **Impact:** ~10KB of CSS re-downloaded on every page navigation (with pushState off) or every full page load.
- **Fix:** Move the CSS content to `css/my-app.css` (which already exists but may contain only a subset). Serve it as a static `.css` file with proper caching headers.

### P2-D3-061 | MEDIUM | No `<link rel="preconnect">` for CDN origins

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php`
- **Description:** The page loads jQuery from `cdnjs.cloudflare.com` and Google Charts from `www.gstatic.com`, but there are no preconnect hints. The browser must wait until it encounters the `<script>` tag at the bottom of the page before starting DNS resolution and TLS handshake with these origins.
- **Impact:** Added latency (100-300ms) for CDN resource loading.
- **Fix:** Add to `meta.php`:
  ```html
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  ```

### P2-D3-062 | LOW | CSS has a typo: `height; 32px` (semicolon instead of colon)

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/style.php:260`
- **Description:** `.imageClassement { width: 32px; height; 32px; }` -- the `height` property uses a semicolon instead of a colon. This means the height rule is ignored and the element has auto height.
- **Impact:** Ranking images may not be correctly sized.
- **Fix:** Change `height;` to `height:`.

### P2-D3-063 | LOW | Vendor-prefixed CSS gradients without standard syntax fallback order

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/style.php:87-90`
- **Description:** The `hr` rule uses `-webkit-`, `-moz-`, `-ms-`, and `-o-` linear-gradient prefixes but the standard `background-image: linear-gradient(...)` is listed as the `-o-` variant. The standard unprefixed syntax should be last for proper cascade.
- **Impact:** In non-WebKit/Gecko browsers, the gradient may not render.
- **Fix:** Add the standard syntax as the last declaration:
  ```css
  background-image: linear-gradient(to right, #f0f0f0, #8c8b8b, #f0f0f0);
  ```

---

## 14. Additional Deep Findings

### P2-D3-064 | HIGH | `$_SESSION['login']` embedded directly in JavaScript strings -- potential XSS

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:137-189`
- **Description:** The molecule stats AJAX calls embed `$_SESSION['login']` directly into JavaScript string interpolation:
  ```php
  echo '$.ajax({url: "api.php?id=attaque&joueur='.$_SESSION['login'].'&niveau=...
  ```
  While `api.php` ignores the `joueur` parameter (forces `$_SESSION['login']`), the login value is embedded in the JS source without `json_encode()` or `htmlspecialchars()`. If a username contained `'` or `</script>`, it would break the JavaScript or allow XSS. Usernames are validated against `/^[a-zA-Z0-9_]{3,20}$/`, which prevents this today, but the pattern is dangerous.
- **Impact:** If username validation is ever relaxed, this becomes an XSS vector.
- **Fix:** Use `json_encode()` for embedding PHP values in JavaScript:
  ```php
  $loginJs = json_encode($_SESSION['login']);
  echo "var playerLogin = $loginJs;";
  ```
  Then use `playerLogin` in AJAX URLs.

### P2-D3-065 | MEDIUM | `health.php` exposes server internals -- PHP version, disk space

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/health.php:23-29`
- **Description:** The health endpoint returns `PHP_VERSION` and `disk_free_space('/')` without any authentication. Anyone can learn the exact PHP version (for targeted exploits) and available disk space (useful for DoS planning).
- **Impact:** Information disclosure to unauthenticated users.
- **Fix:** Either:
  1. Restrict to internal IPs: `if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1') { http_response_code(403); exit; }`
  2. Remove sensitive fields: only return `{"status": "ok"}` for external consumers
  3. Add a shared secret: `?token=<secret>` parameter check

### P2-D3-066 | MEDIUM | Visitor cleanup runs on every login attempt

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php:37-39`
- **Description:** Every login form submission triggers a database scan and deletion of old visitor accounts:
  ```php
  $suppRows = dbFetchAll($base, "SELECT login FROM membre WHERE login LIKE ? AND derniereConnexion < ?", 'si', 'Visiteur%', time() - VISITOR_SESSION_CLEANUP_SECONDS);
  foreach ($suppRows as $supp) { supprimerJoueur($supp['login']); }
  ```
  This runs a `LIKE 'Visiteur%'` query (which cannot use an index prefix efficiently on the `login` column) and then calls `supprimerJoueur()` (which deletes from 5+ tables) for each expired visitor.
- **Impact:** Login page performance degradation. If many visitor accounts accumulate, each login attempt triggers expensive multi-table deletions.
- **Fix:** Move visitor cleanup to a cron job or a probabilistic trigger (1 in 100 requests). Do not tie it to login form submission.

### P2-D3-067 | MEDIUM | No HTML `lang` attribute on `<html>` element

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:5`
- **Description:** `<html>` tag has no `lang="fr"` attribute. The game is entirely in French, but screen readers and search engines cannot detect this without the `lang` attribute.
- **Impact:** Accessibility violation (WCAG 3.1.1 -- Language of Page). Search engines may not properly index the French content.
- **Fix:** Change to `<html lang="fr">`.

### P2-D3-068 | MEDIUM | Duplicate `<meta charset>` declarations

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php:1,5`
- **Description:** Both `<meta charset="utf-8">` (line 1) and `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` (line 5) declare the charset. The `http-equiv` variant is the HTML4 way; `charset` is the HTML5 way. Having both is redundant.
- **Impact:** Minor HTML validation issue.
- **Fix:** Remove line 5 (`<meta http-equiv="content-type"...>`). Keep only `<meta charset="utf-8">`.

### P2-D3-069 | MEDIUM | `meta name="keywords"` is ignored by all modern search engines

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php:8`
- **Description:** `<meta name="keywords" content="atomes, jeu, molecules, war, strategie" />` has been ignored by Google since 2009 and by Bing since 2014.
- **Impact:** No SEO benefit. Reveals game topics to competitors.
- **Fix:** Remove the `keywords` meta tag.

### P2-D3-070 | LOW | Open Graph `og:image` references HTTPS URL that does not work yet

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php:14`
- **Description:** `<meta property="og:image" content="https://theverylittlewar.com/images/icone.png" />` references an HTTPS URL, but the site is currently HTTP-only. Social media crawlers following this URL will get a connection error.
- **Impact:** Social sharing preview images will not load.
- **Fix:** Use the current working URL scheme, or add a protocol-relative approach. Fix once HTTPS is live.

### P2-D3-071 | LOW | `favicon` uses `.png` instead of standard `.ico`

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/meta.php:17`
- **Description:** `<link rel="icon" type="image/x-icon" href="images/icone.png"/>` declares `type="image/x-icon"` (ICO format) but the actual file is `icone.png` (PNG format). Some browsers may have issues with this mismatch.
- **Impact:** Favicon may not display in some browsers.
- **Fix:** Change `type` to `image/png`, or convert the icon to `.ico` format.

### P2-D3-072 | LOW | Version displayed as "V2.0.1.0" in footer -- does not reflect actual version

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php:5`
- **Description:** The footer displays "V2.0.1.0" as the game version, linked to `version.php`. After 130+ commits of refactoring, security hardening, V4 balance overhaul, and new features, the displayed version does not reflect reality.
- **Impact:** Player confusion about game version.
- **Fix:** Update to a meaningful version (e.g., "V4.0.0") or derive from a config constant.

### P2-D3-073 | LOW | `images/partenariat/` directory contains dead partnership infrastructure

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/images/partenariat/`
- **Description:** The `images/partenariat/` directory contains `charger_barre.js`, `jquery.js` (jQuery 1.7.1), `style_barre.css`, and partnership images for a cross-promotion with "Pyromagnon" (appears to be another French browser game). This partner bar loads on every page via `copyright.php` -> `loader.js`. If Pyromagnon is no longer active, this is dead code loading a vulnerable jQuery 1.7.1.
- **Impact:** Dead code, security risk (jQuery 1.7.1 CVEs), wasted bandwidth.
- **Fix:** Verify if the partnership is still active. If not, remove all files from `images/partenariat/` and remove the `js/loader.js` script include from `copyright.php`.

### P2-D3-074 | LOW | `my-app.css` file exists but is separate from `style.php` inline CSS

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/css/my-app.css` and `/home/guortates/TVLW/The-Very-Little-War/includes/style.php`
- **Description:** `meta.php:25` loads `css/my-app.css` as an external stylesheet AND `layout.php:9` includes `includes/style.php` which outputs `<style>` with 304 lines of inline CSS. This means CSS is defined in two places with unclear precedence and potential conflicts.
- **Impact:** CSS maintenance confusion, potential cascade conflicts.
- **Fix:** Consolidate all CSS into `css/my-app.css` and remove `includes/style.php`.

### P2-D3-075 | LOW | `<head>` is missing `</head>` close tag in layout.php before `<body>`

- **Location:** `/home/guortates/TVLW/The-Very-Little-War/includes/layout.php:9-10`
- **Description:** After including `meta.php` and `style.php`, the `</head>` tag is closed (line 10), but then the HTML jumps directly to conditional includes without a `<body>` tag. The `<body>` tag is only opened implicitly by the browser. While browsers auto-correct this, it is invalid HTML.
- **Impact:** HTML validation errors. Some accessibility tools may have issues parsing the DOM.
- **Fix:** Add explicit `<body>` tag after `</head>`.

---

## Severity Summary

| Severity | Count | Findings |
|----------|-------|----------|
| CRITICAL | 2 | P2-D3-016, P2-D3-032 |
| HIGH | 14 | P2-D3-001, P2-D3-006, P2-D3-009, P2-D3-010, P2-D3-017, P2-D3-023, P2-D3-028, P2-D3-033, P2-D3-037, P2-D3-041, P2-D3-045, P2-D3-049, P2-D3-050, P2-D3-055, P2-D3-058, P2-D3-064 |
| MEDIUM | 27 | P2-D3-002, P2-D3-007, P2-D3-008, P2-D3-011, P2-D3-012, P2-D3-018, P2-D3-019, P2-D3-020, P2-D3-024, P2-D3-025, P2-D3-026, P2-D3-029, P2-D3-030, P2-D3-034, P2-D3-035, P2-D3-038, P2-D3-039, P2-D3-042, P2-D3-043, P2-D3-046, P2-D3-047, P2-D3-052, P2-D3-053, P2-D3-056, P2-D3-059, P2-D3-060, P2-D3-061, P2-D3-065, P2-D3-066, P2-D3-067, P2-D3-068, P2-D3-069 |
| LOW | 20 | P2-D3-003, P2-D3-004, P2-D3-005, P2-D3-013, P2-D3-014, P2-D3-015, P2-D3-021, P2-D3-022, P2-D3-027, P2-D3-031, P2-D3-036, P2-D3-040, P2-D3-044, P2-D3-048, P2-D3-054, P2-D3-057, P2-D3-062, P2-D3-063, P2-D3-070, P2-D3-071, P2-D3-072, P2-D3-073, P2-D3-074, P2-D3-075 |

## Recommended Remediation Priority

### Immediate (Security-Critical)
1. **P2-D3-041** -- Fix admin/moderation session hardening (5 min)
2. **P2-D3-032** -- Replace raw `mysqli_query` in health.php (2 min)
3. **P2-D3-009** -- Remove jQuery 1.7.1 from `images/partenariat/` (5 min)
4. **P2-D3-023** -- Add `.env` to FilesMatch in .htaccess (1 min)
5. **P2-D3-017** -- Add Permissions-Policy header (2 min)
6. **P2-D3-064** -- Use json_encode for session login in JS (10 min)

### Short-Term (This Sprint)
7. **P2-D3-010** -- Remove local `js/loader.js` from copyright.php (5 min)
8. **P2-D3-001** -- Update composer.json PHP requirement to >=8.2 (2 min)
9. **P2-D3-028** -- Upgrade PHPUnit to ^11.0 (30 min)
10. **P2-D3-045** -- Add PHP lint + composer audit to CI (10 min)
11. **P2-D3-058** -- Add cache busting query strings to assets (15 min)
12. **P2-D3-034** -- Change withTransaction to catch Throwable (2 min)
13. **P2-D3-037** -- Consolidate error_log to gameLog (30 min)
14. **P2-D3-018** -- Change X-XSS-Protection to 0 (1 min)
15. **P2-D3-067** -- Add lang="fr" to html element (1 min)

### Medium-Term (Next Month)
16. **P2-D3-011** -- Debounce + batch AJAX stat updates (1 hour)
17. **P2-D3-038** -- Add global error/exception handler (30 min)
18. **P2-D3-025/026** -- Add cache control + gzip to .htaccess (15 min)
19. **P2-D3-029** -- Commit composer.lock (5 min + update CI)
20. **P2-D3-055** -- Adopt PHPMailer for email (2 hours)
21. **P2-D3-056** -- Background email sending for season reset (2 hours)
22. **P2-D3-059/060** -- font-display + externalize style.php CSS (30 min)

### Long-Term (Architectural)
23. **P2-D3-002** -- Eliminate global variables (multi-sprint effort)
24. **P2-D3-049** -- Decompose initPlayer() (1 day)
25. **P2-D3-050** -- Template layer separation (multi-sprint)
26. **P2-D3-006** -- Framework7 upgrade/replacement evaluation (major project)
27. **P2-D3-019** -- Eliminate unsafe-inline from CSP (major CSS refactor)

---

*Total findings: 75 | CRITICAL: 2 | HIGH: 16 | MEDIUM: 33 | LOW: 24*
*Pass 2 complete. Findings are incremental to Pass 1 surface-level analysis.*
