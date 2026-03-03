# ROUND 3 CROSS-DOMAIN CORRELATION: Infrastructure Hardening Plan

**Date:** 2026-03-03
**Scope:** I18N + Infrastructure + Frontend Headers
**Files analyzed:** .htaccess (root + 4 subdirectories), includes/basicprivatehtml.php, includes/basicpublichtml.php, includes/connexion.php, includes/config.php, includes/copyright.php, includes/meta.php, includes/tout.php, includes/session_init.php, includes/style.php, includes/partenariat.php, includes/basicprivatephp.php, includes/basicpublicphp.php, admin/listesujets.php, admin/redirectionmotdepasse.php, moderation/mdp.php, api.php, deconnexion.php, video.php, marche.php, sujet.php, inscription.php, migrations/0013*.sql

---

## A) SECURITY HEADERS CHECKLIST

### Current State vs Ideal for Every Header

| # | Header | Current State | Location | Ideal State | Gap Severity |
|---|--------|--------------|----------|-------------|-------------|
| 1 | `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'` | `.htaccess` line 7 | Remove `'unsafe-inline'` from both `script-src` and `style-src`; add `https://www.gstatic.com` to `script-src`; add `https://cdnjs.cloudflare.com` to `font-src` (jqueryui themes may load fonts); add nonce-based inline script allowance | **CRITICAL** |
| 2 | `Strict-Transport-Security` | **ABSENT** | Nowhere | `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"` (after HTTPS is enabled) | **CRITICAL** |
| 3 | `X-Content-Type-Options` | `nosniff` | `.htaccess` line 3 | Correct, no change needed | OK |
| 4 | `X-Frame-Options` | `SAMEORIGIN` | `.htaccess` line 4 | Correct, and also covered by CSP `frame-ancestors 'self'` | OK |
| 5 | `X-XSS-Protection` | `1; mode=block` | `.htaccess` line 5 | Deprecated in modern browsers. Should be set to `0` to avoid false positives (Chrome removed auditor in 2019). CSP supersedes it. | **LOW** |
| 6 | `Referrer-Policy` | `strict-origin-when-cross-origin` | `.htaccess` line 6 | Correct. Consider `no-referrer` for stricter privacy since the game has no need to leak URLs to third parties. | **INFO** |
| 7 | `Permissions-Policy` | **ABSENT** | Nowhere | `Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=()"` | **HIGH** |
| 8 | `Cross-Origin-Opener-Policy` | **ABSENT** | Nowhere | `Header always set Cross-Origin-Opener-Policy "same-origin"` -- prevents Spectre-class cross-origin attacks | **MEDIUM** |
| 9 | `Cross-Origin-Resource-Policy` | **ABSENT** | Nowhere | `Header always set Cross-Origin-Resource-Policy "same-origin"` -- blocks cross-origin resource reads | **MEDIUM** |
| 10 | `Cross-Origin-Embedder-Policy` | **ABSENT** | Nowhere | `Header always set Cross-Origin-Embedder-Policy "require-corp"` -- note: must audit external resources first (Google Charts, MathJax, jQuery CDN) as they need `crossorigin` attributes | **MEDIUM** |
| 11 | `Cache-Control` for API | **ABSENT** on `api.php` | `api.php` | `header('Cache-Control: no-store, no-cache, must-revalidate');` for JSON API responses containing player data | **MEDIUM** |
| 12 | `X-Permitted-Cross-Domain-Policies` | **ABSENT** | Nowhere | `Header always set X-Permitted-Cross-Domain-Policies "none"` -- prevents Flash/PDF cross-domain policy abuse | **LOW** |

### Header Implementation Priority

```
1. [CRITICAL] Add HSTS (after HTTPS enabled)
2. [CRITICAL] CSP roadmap to eliminate unsafe-inline (see Section B)
3. [HIGH]    Add Permissions-Policy
4. [MEDIUM]  Add COOP / CORP / COEP headers
5. [MEDIUM]  Add Cache-Control on API responses
6. [LOW]     Set X-XSS-Protection to 0
7. [LOW]     Add X-Permitted-Cross-Domain-Policies
```

### .htaccess Infrastructure Issues

**ISSUE H-01 [HIGH]: `<IfModule mod_php.c>` directives ignored under PHP-FPM**

Location: `/.htaccess` lines 36-41; `/images/profil/.htaccess` lines 2-4

```apache
<IfModule mod_php.c>
    php_flag display_errors off
    php_flag log_errors on
    php_value error_reporting 32767
    php_flag expose_php off
</IfModule>
```

**Problem:** The Debian 12 VPS runs PHP 8.2 via `php-fpm` (Apache mpm_event + mod_proxy_fcgi), not `mod_php`. The `<IfModule mod_php.c>` block is never entered because `mod_php` is not loaded. These PHP settings silently have no effect. Error display, logging, and `expose_php` are controlled only by the system `/etc/php/8.2/fpm/php.ini`.

**Impact:** `display_errors` may be ON in production if php.ini was not explicitly hardened. `expose_php` may be ON, leaking PHP version in `X-Powered-By` header.

**Fix:** Configure these in `/etc/php/8.2/fpm/php.ini` or a pool config `/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
display_errors = Off
log_errors = On
error_reporting = E_ALL
expose_php = Off
```

Also in `/images/profil/.htaccess`, the `<IfModule mod_php.c> php_flag engine off` directive is equally ignored under FPM. The `FilesMatch` deny rule for `.php$` below it provides the actual protection, but this should be documented.

**ISSUE H-02 [MEDIUM]: `.env` file accessible via web if .htaccess bypass occurs**

The root `.htaccess` denies access to files ending in `.json`, `.md`, etc., but `.env` is not in the FilesMatch pattern. The `.htaccess` rule covers hidden files (`^\.`), which would catch `.env`. However, if `.htaccess` processing is disabled (via `AllowOverride None` in a vhost misconfiguration), the `.env` file with database credentials would be directly downloadable.

**Defense in depth:** Move `.env` outside the webroot to `/var/www/tvlw-config/.env` and update `env.php` to read from there. Or add an explicit Apache vhost directive:

```apache
<Files ".env">
    Require all denied
</Files>
```

---

## B) CSP ROADMAP: Eliminating `unsafe-inline`

### Current CSP Analysis

```
script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com
style-src  'self' 'unsafe-inline' https://cdnjs.cloudflare.com
```

### Why `unsafe-inline` is Present

The codebase has extensive inline JavaScript and inline CSS that currently requires `unsafe-inline`:

**Inline Script Locations (76 `<script>` tags across 31 files):**

| Category | Files | Description |
|----------|-------|-------------|
| Dynamic JS generation | `includes/basicprivatehtml.php` (8 scripts) | Resource counters, PHP-to-JS variable injection, dynamic stats |
| Framework7 init | `includes/copyright.php` (11 scripts) | App initialization, autocomplete, notifications, name generator |
| Page-specific JS | `attaquer.php`, `marche.php`, `armee.php`, `alliance.php`, etc. | Chart rendering, AJAX calls, form handling |
| PHP-echoed JS | `includes/basicprivatehtml.php` lines 74, 95, 104, 112, 139, 148, 160 | Tutorial redirects: `echo '<script>document.location.href=...'` |
| Redirect scripts | `deconnexion.php` line 37 | `localStorage.removeItem` + redirect |

**Inline Style Locations (306 `style=` attributes across 44 files):**

These are pervasive throughout the templating system. Every UI component, menu item, card, and game display element uses inline styles. The most style-heavy files are:
- `includes/basicprivatehtml.php` (39 instances)
- `includes/ui_components.php` (18 instances)
- `tutoriel.php` (56 instances)
- `index.php` (14 instances)
- `includes/player.php` (13 instances)
- `includes/display.php` (13 instances)
- `includes/menus.php` (13 instances)

**Event Handler Attributes (non-test files):**

| File | Handler | Usage |
|------|---------|-------|
| `includes/ui_components.php:239` | `onclick="javascript:myApp.closePanel()"` | Menu panel close |
| `sinstruire.php:314` | `onchange="document.location=..."` | Course selector dropdown |
| `armee.php:369` | `onclick` | Army page interaction |

### Phased CSP Hardening Roadmap

#### Phase 1: External Script Audit and CSP Source Whitelist Fix (Immediate)

The current CSP is **missing required external domains**, meaning some scripts already violate CSP silently or the browser falls back to `unsafe-inline`:

| External Script | Current CSP Coverage | Required Addition |
|----------------|---------------------|-------------------|
| jQuery 3.7.1 (cdnjs) | Covered by `https://cdnjs.cloudflare.com` | OK |
| jQuery UI 1.13.3 (cdnjs) | Covered | OK |
| jQuery UI CSS (cdnjs) | Covered in `style-src` | OK |
| Google Charts (`https://www.gstatic.com/charts/loader.js`) | **NOT COVERED** | Add `https://www.gstatic.com` to `script-src` |
| Google Charts runtime | **NOT COVERED** | Add `https://www.google.com` to `script-src` (Charts loads additional scripts from google.com at runtime) |
| MathJax 2.7.9 (cdnjs) | Covered | OK |
| Partenariat scripts (`http://www.theverylittlewar.com/images/partenariat/...`) | **MIXED CONTENT** (HTTP) + not in CSP | Remove or convert to HTTPS; add to CSP if kept |
| `afterglow.min.js` (video.php) | Local file, covered by `'self'` | OK |

**Immediate CSP fix:**

```apache
Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://www.gstatic.com https://www.google.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'; frame-ancestors 'self'"
```

#### Phase 2: Extract Inline Styles to CSS Classes (1-2 weeks)

1. **Audit all 306 `style=` attributes** across 44 PHP files.
2. Create semantic CSS classes in `/css/my-app.css` for each unique style pattern.
3. Replace inline styles with class names.
4. Remove `'unsafe-inline'` from `style-src`.

Priority files (highest inline style count):
```
tutoriel.php .............. 56 instances
includes/basicprivatehtml.php  39 instances
includes/ui_components.php ... 18 instances
index.php ................. 14 instances
includes/player.php ....... 13 instances
includes/display.php ...... 13 instances
includes/menus.php ........ 13 instances
```

**After Phase 2:** `style-src 'self' https://cdnjs.cloudflare.com` (no unsafe-inline)

#### Phase 3: Extract Inline Scripts to External Files (2-4 weeks)

This is the most complex phase. There are three categories of inline scripts:

**Category A: Static JS that can be extracted directly**

These scripts contain no PHP variables and can be moved to `.js` files:

| Source | Target File | Description |
|--------|-------------|-------------|
| `copyright.php` lines 114-261 | `js/nFormatter.js` | Number formatting, name generator |
| `copyright.php` lines 100-103 | `js/deconnexion.js` | Deconnexion function |
| `deconnexion.php` lines 37-38 | `js/logout-redirect.js` | localStorage cleanup |

**Category B: PHP-templated JS requiring data attributes or JSON injection**

These scripts embed PHP variables into JavaScript. They require refactoring to use `data-*` attributes on HTML elements or a single JSON config block:

| Source | Approach |
|--------|----------|
| `basicprivatehtml.php` lines 427-463 | Move to `js/resource-counter.js`; inject PHP data via `<script id="game-data" type="application/json">` |
| `copyright.php` lines 31-98 | Move Framework7 init to `js/app-init.js`; pass config via `data-*` attrs on `<body>` |
| `copyright.php` lines 84-97 | Notification/error display; use `data-error` and `data-info` attributes |
| `tout.php` lines 93-157 | AJAX stat preview; move to `js/stat-preview.js` with data attributes |

**Category C: PHP-echoed `<script>` redirects (tutorial system)**

These are the hardest to extract because they are `echo '<script>document.location.href=...'`:

| Location | Line | Fix |
|----------|------|-----|
| `basicprivatehtml.php` | 74 | Use `header('Location: ...')` instead of JS redirect |
| `basicprivatehtml.php` | 95 | Use `header('Location: ...')` instead of JS redirect |
| `basicprivatehtml.php` | 104 | Use `header('Location: ...')` instead of JS redirect |
| `basicprivatehtml.php` | 112 | Use `header('Location: ...')` instead of JS redirect |
| `basicprivatehtml.php` | 139 | Use `header('Location: ...')` instead of JS redirect |
| `basicprivatehtml.php` | 148 | Use `header('Location: ...')` instead of JS redirect |
| `basicprivatehtml.php` | 160 | Use `header('Location: ...')` instead of JS redirect |

**CRITICAL NOTE:** These `echo '<script>'` redirects happen **after** HTML output has started (the file is included after `<head>` and `<body>` tags are emitted via `tout.php`). Converting to `header('Location:')` requires restructuring the include order so that tutorial logic runs **before** any HTML output. This is the single biggest architectural change required.

**Alternative for Phase 3:** Use CSP nonces instead of eliminating all inline scripts.

#### Phase 4: Implement CSP Nonces (Alternative to Full Extraction)

If full inline script extraction is too disruptive, implement nonces:

1. Generate a cryptographic nonce per request in `session_init.php`:
```php
$csp_nonce = base64_encode(random_bytes(16));
```

2. Add the nonce to every `<script>` tag:
```php
echo '<script nonce="' . $csp_nonce . '">';
```

3. Update CSP header dynamically via PHP (cannot use .htaccess for dynamic nonces):
```php
header("Content-Security-Policy: ... script-src 'self' 'nonce-{$csp_nonce}' https://cdnjs.cloudflare.com https://www.gstatic.com ...");
```

4. Remove `unsafe-inline` from script-src.

**Tradeoff:** Nonces require every page to set the CSP header via PHP (not .htaccess), and every inline script tag must include the nonce. This is manageable since all pages already include `session_init.php`.

**After Phase 4:** `script-src 'self' 'nonce-{random}' https://cdnjs.cloudflare.com https://www.gstatic.com https://www.google.com`

#### Phase 5: CSP Reporting (Ongoing)

1. Add `report-uri` or `report-to` directive to CSP.
2. Start with `Content-Security-Policy-Report-Only` during transition.
3. Monitor violations before enforcing.

### CSP Roadmap Summary

```
Phase 1 [NOW]:     Fix missing external domains in CSP whitelist
Phase 2 [1-2 wk]:  Extract inline styles -> CSS classes
Phase 3 [2-4 wk]:  Extract inline scripts -> .js files + refactor tutorial redirects
Phase 4 [Alt]:      Implement CSP nonces if Phase 3 is too disruptive
Phase 5 [Ongoing]:  CSP reporting + enforcement
```

---

## C) ENCODING CONSISTENCY MAP

### Charset Declarations by Layer

#### 1. HTTP Layer

| Source | Charset | Status |
|--------|---------|--------|
| `api.php` line 14 | `Content-Type: application/json; charset=utf-8` | OK |
| Email headers (`basicprivatephp.php` lines 272, 278) | `charset="UTF-8"` | OK |
| No PHP-level `default_charset` set | Falls back to php.ini default (`UTF-8` in PHP 8.2) | OK but implicit |

#### 2. HTML Meta Layer

| File | Declaration | Charset | Status |
|------|------------|---------|--------|
| `includes/meta.php` line 1 | `<meta charset="utf-8">` | UTF-8 | OK |
| `includes/meta.php` line 5 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | REDUNDANT (already declared on line 1) |
| `admin/index.php` line 55 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | OK |
| `admin/supprimercompte.php` line 26 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | OK |
| `admin/supprimerreponse.php` line 18 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | OK |
| `admin/listenews.php` line 10 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | OK |
| `admin/redigernews.php` line 9 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | OK |
| `admin/ip.php` line 7 | `<meta http-equiv="content-type" content="text/html; charset=utf-8" />` | UTF-8 | OK |
| **`admin/listesujets.php` line 8** | **`<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />`** | **ISO-8859-1** | **MISMATCH -- CRITICAL** |
| `video.php` line 11 | `<meta charset="utf-8">` | UTF-8 | OK |
| `deconnexion.php` | Includes `meta.php` | UTF-8 | OK |
| Main game pages | Include `meta.php` via `tout.php` | UTF-8 | OK |

#### 3. Database Layer

| Setting | Value | Source | Status |
|---------|-------|--------|--------|
| `mysqli_set_charset($base, 'utf8')` | `utf8` (3-byte MySQL alias) | `connexion.php` line 20 | **SHOULD BE `utf8mb4`** |
| Migration 0013 title | "standardize charset to utf8mb4" | `0013_myisam_to_innodb_and_charset.sql` | Migration title says utf8mb4 but **does not contain any ALTER TABLE ... CHARSET statements** |
| Actual table/column charset | Unknown without querying live DB | -- | **VERIFY ON VPS** |

#### 4. Email Layer

| Component | Encoding | Source | Status |
|-----------|----------|--------|--------|
| Email body (text) | `charset="UTF-8"` | `basicprivatephp.php` line 272 | OK |
| Email body (HTML) | `charset="UTF-8"` | `basicprivatephp.php` line 278 | OK |
| Email subject (`$sujet`) | Raw UTF-8 string `"Debut d'une nouvelle partie"` with accented characters | `basicprivatephp.php` line 259 | **MISSING `=?UTF-8?B?...?=` encoding** |
| Email `From:` header | `noreply@theverylittewar.com` | `basicprivatephp.php` line 263 | **TYPO: missing 'l' in 'littlewar'** |
| Email `Reply-to:` header | `theverylittewar@gmail.com` | `basicprivatephp.php` line 264 | **TYPO: missing 'l' in 'littlewar'** |

#### 5. JavaScript Layer

| Source | Encoding Handling | Status |
|--------|-------------------|--------|
| All JS files | Loaded as UTF-8 (inherits page charset) | OK |
| PHP-to-JS variable injection | `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` used consistently | OK |
| `json_encode()` in `copyright.php` lines 86, 91 | `json_encode()` produces UTF-8 by default | OK |

### Encoding Issues Summary

| # | Issue | Severity | File | Fix |
|---|-------|----------|------|-----|
| C-01 | `admin/listesujets.php` declares `charset=iso-8859-1` | **HIGH** | `admin/listesujets.php:8` | Change to `charset=utf-8` |
| C-02 | DB connection uses `utf8` not `utf8mb4` | **HIGH** | `connexion.php:20` | Change to `mysqli_set_charset($base, 'utf8mb4')` |
| C-03 | Migration 0013 never actually converts tables to `utf8mb4` | **MEDIUM** | `migrations/0013_*` | Add `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` for each table |
| C-04 | Email subject not RFC2047 encoded | **MEDIUM** | `basicprivatephp.php:259` | Use `mb_encode_mimeheader($sujet, 'UTF-8')` or `=?UTF-8?B?` encoding |
| C-05 | Email From/Reply-to domain typo | **HIGH** | `basicprivatephp.php:263-264` | `theverylittewar` -> `theverylittlewar` |
| C-06 | Redundant charset meta in `meta.php` | **INFO** | `includes/meta.php:5` | Remove the `http-equiv` duplicate; keep `<meta charset="utf-8">` |

---

## D) ACCESS CONTROL MAP

### Directory-Level Access Control

| Directory | Web Accessible? | Current Protection | Should Be Accessible? | Gap |
|-----------|----------------|-------------------|----------------------|-----|
| `/` (webroot) | YES | `.htaccess` with headers, FilesMatch, Options -Indexes | YES (PHP pages) | OK |
| `/admin/` | YES | PHP-level session check (`redirectionmotdepasse.php`) | NO (restrict to IP or add .htaccess) | **HIGH** |
| `/moderation/` | YES | PHP-level session check (`mdp.php`) | NO (restrict to IP or add .htaccess) | **HIGH** |
| `/includes/` | DENIED | `.htaccess` with `Require all denied` | NO | OK |
| `/docs/` | DENIED | `.htaccess` with `Require all denied` | NO | OK |
| `/logs/` | DENIED | `.htaccess` with `Deny from all` | NO | OK |
| `/migrations/` | **YES** | **NO PROTECTION** | **NO** | **CRITICAL** |
| `/tests/` | **YES** | **NO PROTECTION** | **NO** | **HIGH** |
| `/vendor/` | **YES** | **NO PROTECTION** (`.gitignore` excludes from repo but present on VPS) | **NO** | **HIGH** |
| `/css/` | YES | No restriction | YES (static assets) | OK |
| `/js/` | YES | No restriction | YES (static assets) | OK |
| `/images/` | YES | No restriction (profil/ subdirectory has PHP execution block) | YES (static assets) | OK |
| `/images/profil/` | YES (images only) | `.htaccess` blocks PHP execution, allows only jpg/png/gif | YES (user avatars) | OK |

### File-Level Access Control

| File Pattern | Current Protection | Should Be Accessible? | Gap |
|-------------|-------------------|----------------------|-----|
| `.env` | Hidden files rule (`^\.`) in root `.htaccess` | NO | OK (but defense-in-depth: move outside webroot) |
| `*.sql` | FilesMatch deny in root `.htaccess` | NO | OK |
| `*.md` | FilesMatch deny in root `.htaccess` | NO | OK |
| `*.json` | FilesMatch deny in root `.htaccess` | NO | OK -- but verify `composer.json` is blocked |
| `*.lock` | FilesMatch deny in root `.htaccess` | NO | OK |
| `.gitignore` | FilesMatch deny in root `.htaccess` | NO | OK |
| `.phpunit.result.cache` | Hidden files rule (`^\.`) | NO | OK |
| `composer` (no extension) | **NO PROTECTION** | **NO** | **HIGH** -- executable binary |
| `composer.phar` | **NO PROTECTION** | **NO** | **MEDIUM** -- should be excluded |
| `convertisseur.html` | **NO PROTECTION** | **MAYBE** | **LOW** -- evaluate if needed |
| `phpunit.xml` | Checked by `.xml` FilesMatch | NO | OK |
| `comptetest.php` | **ACCESSIBLE** | **NO** (test page) | **MEDIUM** |
| `video.php` | ACCESSIBLE | **EVALUATE** -- appears to be a legacy streaming page | **LOW** |

### Critical Access Control Fixes Required

#### D-01 [CRITICAL] `/migrations/` directory is web-accessible

**Current state:** No `.htaccess` file exists. Anyone can browse to:
- `https://theverylittlewar.com/migrations/migrate.php` -- could execute migrations
- `https://theverylittlewar.com/migrations/0001_add_indexes.sql` -- leaks schema
- All 14 migration SQL files are downloadable, revealing complete database schema, column names, and index structure.

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

#### D-02 [HIGH] `/tests/` directory is web-accessible

**Current state:** No `.htaccess` file. Test files including `bootstrap.php` and all unit/integration tests can be accessed. While PHPUnit tests will fail without CLI context, the source code reveals internal testing patterns and security test payloads.

**Fix:** Create `/tests/.htaccess` with same deny-all rule.

#### D-03 [HIGH] `/vendor/` directory (on VPS) is web-accessible

**Current state:** While `.gitignore` excludes `vendor/` from the repo, it exists on the VPS after `composer install`. No `.htaccess` protects it. The `vendor/` directory contains:
- `autoload.php` -- could be included to load arbitrary classes
- All dependency source code
- Potential information disclosure

**Fix:** Create `/vendor/.htaccess` with deny-all, or better, move vendor outside webroot.

#### D-04 [HIGH] `/admin/` relies solely on PHP session check

**Current state:** `admin/redirectionmotdepasse.php` checks `$_SESSION['motdepasseadmin']`. This is the only protection. Notably:
- Uses bare `session_start()` instead of `session_init.php` (line 2: `session_start()`)
- No CSRF protection on admin actions
- No IP restriction
- Shared admin password (all who know it get access)

**Fix (defense in depth):**
1. Replace `session_start()` with `require_once(__DIR__ . '/../includes/session_init.php')` (already done in `moderation/mdp.php` but not in `admin/redirectionmotdepasse.php`)
2. Add `.htaccess` IP restriction:
```apache
<IfModule mod_authz_core.c>
    Require ip 212.227.38.111
    Require ip YOUR_ADMIN_IP
</IfModule>
```
3. Add rate limiting to admin login

#### D-05 [HIGH] `composer` and `composer.phar` binaries in webroot

**Current state:** Two copies of the Composer binary (3.3MB each) are directly downloadable. While not a direct vulnerability, they reveal the PHP toolchain version and could be used in supply-chain attacks if the binary is outdated.

**Fix:** Delete from webroot or add to `.htaccess` FilesMatch:
```apache
<FilesMatch "^composer(\.phar)?$">
    Require all denied
</FilesMatch>
```

#### D-06 [MEDIUM] `comptetest.php` is a test page accessible in production

**Current state:** A test variant of the account page is publicly accessible at `/comptetest.php`.

**Fix:** Remove from production or add access control.

### Mixed Content / External Resource Vulnerabilities

| # | Issue | File | Severity |
|---|-------|------|----------|
| D-07 | `partenariat.php` loads scripts over plain HTTP: `http://www.theverylittlewar.com/images/partenariat/charger_barre.js`, `http://www.theverylittlewar.com/images/partenariat/news.json`, `http://www.theverylittlewar.com/images/partenariat/style_barre.css` | `includes/partenariat.php:9-11` | **CRITICAL** (mixed content, MitM-injectable JS) |
| D-08 | Google Charts loaded without SRI: `https://www.gstatic.com/charts/loader.js` | `marche.php:560` | **HIGH** (no integrity verification for third-party JS) |
| D-09 | MathJax loaded without SRI: `https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js` | `sujet.php:223` | **HIGH** (no integrity verification) |
| D-10 | jQuery CDN loaded with SRI in `copyright.php` | `includes/copyright.php:26` | OK |
| D-11 | jQuery UI loaded with SRI in `moderationForum.php` | `moderationForum.php:71-72` | OK |

---

## UNIFIED IMPLEMENTATION PLAN

### Priority 1: CRITICAL (Do Now)

| # | Action | File(s) | Est. Time |
|---|--------|---------|-----------|
| 1 | Add `.htaccess` deny-all to `/migrations/`, `/tests/`, `/vendor/` | New files | 5 min |
| 2 | Fix email domain typo `theverylittewar` -> `theverylittlewar` | `includes/basicprivatephp.php:263-264` | 2 min |
| 3 | Fix `admin/listesujets.php` charset from `iso-8859-1` to `utf-8` | `admin/listesujets.php:8` | 1 min |
| 4 | Fix CSP to add missing Google Charts domains | `.htaccess:7` | 2 min |
| 5 | Fix `partenariat.php` HTTP -> HTTPS or remove entirely | `includes/partenariat.php:9-11` | 5 min |
| 6 | Block `composer`/`composer.phar` via `.htaccess` | `.htaccess` | 2 min |

### Priority 2: HIGH (This Week)

| # | Action | File(s) | Est. Time |
|---|--------|---------|-----------|
| 7 | Add Permissions-Policy header | `.htaccess` | 2 min |
| 8 | Change `mysqli_set_charset` from `utf8` to `utf8mb4` | `includes/connexion.php:20` | 1 min |
| 9 | Add actual charset conversion ALTER statements to migration | New migration `0015_*` | 15 min |
| 10 | Fix `admin/redirectionmotdepasse.php` to use `session_init.php` | `admin/redirectionmotdepasse.php` | 2 min |
| 11 | Add SRI to Google Charts and MathJax script tags | `marche.php`, `sujet.php` | 10 min |
| 12 | Remove/protect `comptetest.php` | `comptetest.php` | 2 min |
| 13 | Move PHP settings from `.htaccess` `mod_php` block to `php.ini`/FPM config | VPS `/etc/php/8.2/fpm/php.ini` | 10 min |
| 14 | Add IP restriction to `/admin/` directory | `admin/.htaccess` | 5 min |

### Priority 3: MEDIUM (This Sprint)

| # | Action | File(s) | Est. Time |
|---|--------|---------|-----------|
| 15 | Add COOP, CORP, COEP headers | `.htaccess` | 5 min |
| 16 | Add Cache-Control to `api.php` | `api.php` | 2 min |
| 17 | Encode email subject with `mb_encode_mimeheader()` | `includes/basicprivatephp.php` | 5 min |
| 18 | Remove redundant charset meta from `meta.php` | `includes/meta.php` | 1 min |
| 19 | Implement CSP nonce infrastructure | `includes/session_init.php`, all pages | 2-3 hours |
| 20 | Set `X-XSS-Protection: 0` (deprecated, remove false sense of security) | `.htaccess` | 1 min |

### Priority 4: LOW / Post-HTTPS (Next Sprint)

| # | Action | File(s) | Est. Time |
|---|--------|---------|-----------|
| 21 | Add HSTS header (after HTTPS is enabled) | `.htaccess` or Apache vhost | 2 min |
| 22 | Hardcode `session.cookie_secure = 1` (after HTTPS) | `includes/session_init.php` | 1 min |
| 23 | Begin CSP Phase 2: Extract inline styles to CSS classes | 44 PHP files | 1-2 weeks |
| 24 | CSP Phase 3: Extract inline scripts to `.js` files | 31 PHP files | 2-4 weeks |
| 25 | CSP Phase 4: Nonce-based script loading | All pages | 2-3 hours |
| 26 | Move `.env` outside webroot | VPS config | 15 min |

---

## CROSS-DOMAIN CORRELATION FINDINGS

### Finding CROSS-01: Tutorial System is an Architectural Security Liability

The tutorial system in `basicprivatehtml.php` uses `echo '<script>document.location.href=...'` for redirects (7 locations). This pattern:
1. **Forces** `unsafe-inline` in CSP (cannot remove without refactoring)
2. **Outputs after headers**, preventing proper HTTP 302 redirects
3. **Mixes DB writes with HTML output**, violating separation of concerns
4. **Injects user-controlled strings** into JS (mitigated by `htmlspecialchars` but defense-in-depth is weaker)

**Recommendation:** Refactor tutorial logic to run before any HTML output. Move from `basicprivatehtml.php` (HTML template) to `basicprivatephp.php` (pre-output logic). Use `header('Location: ...')` for redirects.

### Finding CROSS-02: Encoding Chain Inconsistency

The encoding chain `PHP (UTF-8) -> MySQL (utf8 3-byte) -> HTML (utf-8) -> Email (UTF-8)` has a gap at the database layer. MySQL `utf8` only supports 3-byte characters (BMP), not full Unicode including emoji (4-byte). The application declares `utf8mb4` intent (migration 0013 title) but never executes the conversion. Player names, forum posts, and messages that contain emoji or rare CJK characters will be silently truncated or cause insertion errors.

### Finding CROSS-03: Admin Panel Security Architecture Gap

The admin panel (`/admin/`) and moderation panel (`/moderation/`) have inconsistent security patterns:
- `moderation/mdp.php` uses `session_init.php` (with secure session settings)
- `admin/redirectionmotdepasse.php` uses bare `session_start()` (without httponly, samesite, etc.)
- Neither has `.htaccess` IP restriction
- Neither has CSRF protection on admin actions
- Both use a shared password (not per-user admin accounts)

### Finding CROSS-04: Three External JS Sources Without SRI

| Script | SRI Status | CSP Coverage |
|--------|-----------|-------------|
| jQuery 3.7.1 (cdnjs) | HAS SRI | Covered |
| jQuery UI 1.13.3 (cdnjs) | HAS SRI | Covered |
| Google Charts (gstatic) | **NO SRI** | **NOT in CSP** |
| MathJax 2.7.9 (cdnjs) | **NO SRI** | Covered (cdnjs) |
| Partenariat scripts (HTTP!) | **NO SRI + HTTP** | **NOT in CSP** |

A compromise of any SRI-less CDN endpoint allows arbitrary code execution in the context of the application, with access to session cookies (if not HttpOnly), CSRF tokens, and DOM content.

### Finding CROSS-05: mod_php vs php-fpm Configuration Blindspot

Multiple `.htaccess` files contain `<IfModule mod_php.c>` directives that are completely ignored under the VPS's PHP-FPM setup. This creates a false sense of security -- the `.htaccess` suggests `display_errors` is off and `expose_php` is off, but these settings may not be in effect. The actual PHP configuration is solely controlled by `/etc/php/8.2/fpm/php.ini` on the VPS.

---

## APPENDIX: File Reference Index

All files analyzed in this audit, with absolute paths:

```
/home/guortates/TVLW/The-Very-Little-War/.htaccess
/home/guortates/TVLW/The-Very-Little-War/.env.example
/home/guortates/TVLW/The-Very-Little-War/.gitignore
/home/guortates/TVLW/The-Very-Little-War/api.php
/home/guortates/TVLW/The-Very-Little-War/deconnexion.php
/home/guortates/TVLW/The-Very-Little-War/inscription.php
/home/guortates/TVLW/The-Very-Little-War/marche.php
/home/guortates/TVLW/The-Very-Little-War/sujet.php
/home/guortates/TVLW/The-Very-Little-War/video.php
/home/guortates/TVLW/The-Very-Little-War/comptetest.php
/home/guortates/TVLW/The-Very-Little-War/admin/listesujets.php
/home/guortates/TVLW/The-Very-Little-War/admin/redirectionmotdepasse.php
/home/guortates/TVLW/The-Very-Little-War/moderation/mdp.php
/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php
/home/guortates/TVLW/The-Very-Little-War/includes/basicpublichtml.php
/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php
/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php
/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php
/home/guortates/TVLW/The-Very-Little-War/includes/config.php
/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php
/home/guortates/TVLW/The-Very-Little-War/includes/meta.php
/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php
/home/guortates/TVLW/The-Very-Little-War/includes/style.php
/home/guortates/TVLW/The-Very-Little-War/includes/tout.php
/home/guortates/TVLW/The-Very-Little-War/includes/partenariat.php
/home/guortates/TVLW/The-Very-Little-War/logs/.htaccess
/home/guortates/TVLW/The-Very-Little-War/includes/.htaccess
/home/guortates/TVLW/The-Very-Little-War/docs/.htaccess
/home/guortates/TVLW/The-Very-Little-War/images/profil/.htaccess
/home/guortates/TVLW/The-Very-Little-War/migrations/0013_myisam_to_innodb_and_charset.sql
```
