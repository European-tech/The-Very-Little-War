# Ultra Audit Pass 1 — Domain 3: Technology & Infrastructure

**Date:** 2026-03-05
**Pass:** 1 (Broad Scan)
**Subagents:** 5 (PHP Ecosystem, Frontend Tech, CI/CD DevOps, Monitoring/SRE, Database Tech)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 14 |
| MEDIUM | 36 |
| LOW | 25 |
| **Total** | **75** |

*Note: ~20 duplicate findings across subagents were merged. Cross-references noted.*

---

## Area 1: PHP Ecosystem & Framework (19 findings)

#### P1-D3-001: composer.json declares `php: >=7.4` while VPS runs PHP 8.2
- **Severity:** MEDIUM — **Location:** composer.json:5 — **Fix:** Change to `"php": "^8.2"` — **Effort:** XS
- Cross-ref: P1-D3-053

#### P1-D3-002: PHPUnit 9 pinned — EOL, needs upgrade to PHPUnit 11
- **Severity:** MEDIUM — **Location:** composer.json:8 — **Fix:** Upgrade to `^11.0`, migrate annotations to attributes — **Effort:** M

#### P1-D3-003: No Composer autoloading — all includes are manual require_once chains
- **Severity:** MEDIUM — **Location:** includes/fonctions.php — **Fix:** Add files to autoload.files near-term; PSR-4 long-term — **Effort:** M

#### P1-D3-004: No static analysis tool (PHPStan/Psalm) configured
- **Severity:** MEDIUM — **Location:** composer.json (missing) — **Fix:** Add PHPStan level 1 baseline — **Effort:** S
- Cross-ref: P1-D3-041

#### P1-D3-005: No code formatter (PHP-CS-Fixer) configured
- **Severity:** LOW — **Fix:** Add php-cs-fixer with PSR-12 rules — **Effort:** S
- Cross-ref: P1-D3-054

#### P1-D3-006: Custom env loader instead of vlucas/phpdotenv
- **Severity:** LOW — **Location:** includes/env.php — **Fix:** Replace with phpdotenv — **Effort:** S

#### P1-D3-007: File-based routing with ~80 entry-point PHP files (no router)
- **Severity:** LOW — **Fix:** Acceptable as-is; Slim 4 if ever pursued — **Effort:** XL

#### P1-D3-008: No ORM — raw mysqli with procedural helpers
- **Severity:** LOW — **Fix:** Acceptable as-is; DBAL for new code — **Effort:** XL

#### P1-D3-009: No templating — raw PHP echo, HTML in business logic
- **Severity:** LOW — **Fix:** Extract PHP template files for reports — **Effort:** L

#### P1-D3-010: `putenv()`/`getenv()` is SAPI-dependent and not thread-safe
- **Severity:** MEDIUM — **Location:** includes/env.php:11 — **Fix:** Populate `$_ENV`/`$_SERVER` too — **Effort:** XS

#### P1-D3-011: No `declare(strict_types=1)` in any file
- **Severity:** MEDIUM — **Fix:** Add incrementally starting with formulas.php — **Effort:** M
- Cross-ref: P1-D2-020

#### P1-D3-012: No type declarations on any function (~200 functions)
- **Severity:** MEDIUM — **Fix:** Start with database.php and formulas.php — **Effort:** M
- Cross-ref: P1-D2-021

#### P1-D3-013: phpunit.xml uses deprecated `verbose="true"` (removed in PHPUnit 10)
- **Severity:** LOW — **Fix:** Remove attribute, update schema URL — **Effort:** XS

#### P1-D3-014: Rate limiter file-based read-modify-write race condition
- **Severity:** LOW — **Fix:** DB-backed rate limiter or atomic file lock — **Effort:** S
- Cross-ref: P1-D2-034

#### P1-D3-015: Logger writes flat files with no monolog integration
- **Severity:** LOW — **Fix:** Add monolog or PSR-3 interface — **Effort:** S

#### P1-D3-016: Season emails sent with raw `mail()` — no SMTP, no DKIM, typo in From
- **Severity:** MEDIUM — **Location:** basicprivatephp.php:157-202 — **Fix:** Add phpmailer/phpmailer — **Effort:** S

#### P1-D3-017: CI has no static analysis or lint step
- **Severity:** LOW — **Fix:** Add after PHPStan installed (P1-D3-004) — **Effort:** XS
- Cross-ref: P1-D3-040, P1-D3-041

#### P1-D3-018: combat.php included as script, not callable as function
- **Severity:** MEDIUM — **Fix:** Convert to `resolveCombat()` function — **Effort:** L
- Cross-ref: P1-D2-043

#### P1-D3-019: Pervasive `global` variables instead of dependency injection
- **Severity:** MEDIUM — **Fix:** Thread $base as explicit parameter — **Effort:** M
- Cross-ref: P1-D2-042, P1-D2-047

---

## Area 2: Frontend Technology (20 findings)

#### P1-D3-020: Framework7 v1.5.x — 8+ years EOL, no security patches
- **Severity:** HIGH — **Location:** js/framework7.min.js — **Fix:** Replace with Tailwind+Alpine.js or vanilla CSS+JS — **Effort:** XL

#### P1-D3-021: jQuery only used for 9 `$.ajax()` calls — unjustified 31KB dependency
- **Severity:** MEDIUM — **Location:** copyright.php:19 — **Fix:** Replace with `fetch()` API — **Effort:** S

#### P1-D3-022: Google Charts loaded from gstatic.com with fragile SRI
- **Severity:** MEDIUM — **Location:** marche.php:587 — **Fix:** Replace with Chart.js (self-hosted) — **Effort:** S

#### P1-D3-023: `style-src 'unsafe-inline'` in CSP defeats style injection protection
- **Severity:** HIGH — **Location:** layout.php:3 — **Fix:** Move style.php to my-app.css, remove unsafe-inline — **Effort:** M

#### P1-D3-024: No asset bundling — raw files served with no cache-busting
- **Severity:** MEDIUM — **Fix:** Add esbuild/Vite build step — **Effort:** S

#### P1-D3-025: 658 game images are PNG/JPG/GIF — zero WebP conversion
- **Severity:** MEDIUM — **Location:** images/ (34 MB) — **Fix:** Batch WebP conversion, `<picture>` elements — **Effort:** M

#### P1-D3-026: `@font-face` declared twice (style.php + my-app.css), no `font-display: swap`
- **Severity:** LOW — **Fix:** Remove duplicates, add font-display: swap — **Effort:** XS

#### P1-D3-027: Legacy EOT/SVG font formats still in declarations
- **Severity:** LOW — **Fix:** Keep only woff2/woff — **Effort:** XS

#### P1-D3-028: `<html>` missing `lang="fr"` — WCAG 3.1.1 failure
- **Severity:** MEDIUM — **Location:** layout.php:6 — **Fix:** Add `lang="fr"` — **Effort:** XS

#### P1-D3-029: `<center>` deprecated HTML element in progressBar()
- **Severity:** LOW — **Location:** ui_components.php:482 — **Fix:** Use CSS text-align — **Effort:** XS

#### P1-D3-030: No PWA manifest or service worker
- **Severity:** LOW — **Fix:** Add manifest.json (XS), service worker (M) — **Effort:** XS/M

#### P1-D3-031: Dead orphan files — images/partenariat/jquery.js (jQuery 1.7.1, 2011)
- **Severity:** LOW — **Fix:** Delete entire partenariat/ directory — **Effort:** XS

#### P1-D3-032: 9 concurrent uncached AJAX calls on every atom input change (no debounce)
- **Severity:** MEDIUM — **Location:** layout.php:127-198 — **Fix:** Debounce + batch endpoint — **Effort:** S

#### P1-D3-033: Scripts load without `defer` — blocking HTML parsing
- **Severity:** MEDIUM — **Fix:** Add defer to standalone scripts — **Effort:** XS

#### P1-D3-034: No SRI on local Framework7 script
- **Severity:** LOW — **Fix:** Moot when Framework7 replaced — **Effort:** XS

#### P1-D3-035: All JavaScript uses `var` — pre-ES6 code style
- **Severity:** LOW — **Fix:** Modernize to const/let — **Effort:** S

#### P1-D3-036: Responsive design has only one breakpoint at 750px
- **Severity:** LOW — **Fix:** Add tablet/large desktop breakpoints — **Effort:** M

#### P1-D3-037: No image lazy loading — all 658 images load eagerly
- **Severity:** LOW — **Fix:** Add `loading="lazy"` to off-screen images — **Effort:** S

#### P1-D3-038: Google Charts requires gstatic.com in script-src CSP
- **Severity:** MEDIUM — **Fix:** Resolved by P1-D3-022 (Chart.js migration) — **Effort:** XS

#### P1-D3-039: Double `<meta charset>` declaration
- **Severity:** LOW — **Location:** meta.php:1,5 — **Fix:** Remove legacy http-equiv line — **Effort:** XS

---

## Area 3: CI/CD & DevOps (20 findings)

#### P1-D3-040: CI pipeline lacks PHP syntax lint step
- **Severity:** HIGH — **Location:** ci.yml — **Fix:** Add `php -l` step — **Effort:** XS

#### P1-D3-041: No static analysis (PHPStan/Psalm) in CI
- **Severity:** HIGH — **Location:** ci.yml — **Fix:** Add PHPStan level 3 — **Effort:** M
- Cross-ref: P1-D3-004

#### P1-D3-042: No automated deployment — manual `git pull` on VPS
- **Severity:** HIGH — **Location:** ci.yml — **Fix:** Add SSH deploy job via appleboy/ssh-action — **Effort:** S

#### P1-D3-043: No staging environment — all changes deploy directly to production
- **Severity:** HIGH — **Fix:** Docker Compose for local parity; staging vhost on VPS — **Effort:** L

#### P1-D3-044: migrate.php has no rollback or dry-run mode
- **Severity:** HIGH — **Location:** migrate.php — **Fix:** Add transaction wrapping, dry-run flag — **Effort:** M
- Cross-ref: P1-D3-087, P1-D3-093

#### P1-D3-045: Integration tests not executed in CI (no MariaDB service)
- **Severity:** HIGH — **Location:** ci.yml — **Fix:** Add MariaDB service + test DB setup — **Effort:** M

#### P1-D3-046: No code coverage measurement or minimum threshold
- **Severity:** MEDIUM — **Location:** ci.yml — **Fix:** Enable PCOV, enforce 60% minimum — **Effort:** S

#### P1-D3-047: 3.2MB Composer binary committed to git
- **Severity:** MEDIUM — **Location:** composer (root file) — **Fix:** git rm + .gitignore — **Effort:** XS

#### P1-D3-048: No Docker/containerization for environment parity
- **Severity:** MEDIUM — **Fix:** Add docker-compose.yml matching VPS stack — **Effort:** M

#### P1-D3-049: No automated database backup strategy
- **Severity:** HIGH — **Fix:** Daily mysqldump cron + offsite copy — **Effort:** S
- Cross-ref: P1-D3-080, P1-D3-082

#### P1-D3-050: health.php exposes PHP version without authentication
- **Severity:** MEDIUM — **Location:** health.php — **Fix:** Add token check — **Effort:** XS
- Cross-ref: P1-D3-061

#### P1-D3-051: No log rotation cron confirmed deployed on VPS
- **Severity:** LOW — **Fix:** Verify/install on VPS — **Effort:** XS
- Cross-ref: P1-D3-065

#### P1-D3-052: Secret management lacks rotation and CI scanning
- **Severity:** MEDIUM — **Fix:** Add gitleaks to CI, document rotation — **Effort:** S

#### P1-D3-053: composer.json PHP version mismatches production
- **Severity:** MEDIUM — **Fix:** Update to `>=8.2` — **Effort:** XS
- Cross-ref: P1-D3-001

#### P1-D3-054: No code style enforcement in CI
- **Severity:** LOW — **Fix:** Add PHP-CS-Fixer check — **Effort:** S
- Cross-ref: P1-D3-005

#### P1-D3-055: docs/DEPLOYMENT.md contains stale information
- **Severity:** LOW — **Fix:** Update references — **Effort:** XS

#### P1-D3-056: No zero-downtime deployment (in-place git pull)
- **Severity:** MEDIUM — **Fix:** Symlink swap deployment pattern — **Effort:** M

#### P1-D3-057: No maintenance mode integration with deployment
- **Severity:** LOW — **Fix:** Maintenance flag file check — **Effort:** XS

#### P1-D3-058: CI action versions not SHA-pinned (supply chain risk)
- **Severity:** LOW — **Fix:** Pin to commit SHAs + add Dependabot — **Effort:** XS

#### P1-D3-059: No uptime monitoring configured for health.php
- **Severity:** MEDIUM — **Fix:** Register with UptimeRobot — **Effort:** XS
- Cross-ref: P1-D3-060, P1-D3-064

---

## Area 4: Monitoring & Observability (20 findings)

#### P1-D3-060: No external uptime monitoring — health.php has no subscriber
- **Severity:** HIGH — **Fix:** Register UptimeRobot + email alert — **Effort:** XS

#### P1-D3-061: health.php exposes server information publicly with no auth
- **Severity:** MEDIUM — **Fix:** Add token header check — **Effort:** XS
- Cross-ref: P1-D3-050

#### P1-D3-062: No global PHP exception/error handler registered
- **Severity:** HIGH — **Fix:** Add set_exception_handler + set_error_handler — **Effort:** S

#### P1-D3-063: health.php lacks depth — no game-layer checks
- **Severity:** MEDIUM — **Fix:** Add stats table, logs writable, rates writable checks — **Effort:** XS

#### P1-D3-064: PII (email) written to application log in plain text (GDPR)
- **Severity:** HIGH — **Location:** inscription.php:43 — **Fix:** Remove email from log context — **Effort:** XS

#### P1-D3-065: Log rotation script not verified deployed on VPS
- **Severity:** MEDIUM — **Fix:** SSH verify + install if absent — **Effort:** XS

#### P1-D3-066: No database slow query logging at application layer
- **Severity:** MEDIUM — **Fix:** Add timing to dbQuery(), enable MariaDB slow log — **Effort:** S
- Cross-ref: P1-D3-084, P1-D3-086

#### P1-D3-067: No game metrics dashboard — zero visibility into player activity
- **Severity:** MEDIUM — **Fix:** Add admin/metrics.php with aggregated game queries — **Effort:** M

#### P1-D3-068: Log files contain no request correlation ID
- **Severity:** MEDIUM — **Fix:** Generate per-request ID in bootstrap — **Effort:** XS

#### P1-D3-069: Logging coverage sparse — 37 of 50+ action pages have zero log calls
- **Severity:** MEDIUM — **Fix:** Add logInfo() to all state-mutating actions — **Effort:** M

#### P1-D3-070: Admin alert system is multiaccount-only
- **Severity:** MEDIUM — **Fix:** Extend alerts to season reset, rate limit flood, VP threshold — **Effort:** S

#### P1-D3-071: health.php has no disk space threshold alerting
- **Severity:** MEDIUM — **Fix:** Return 503 when disk < 500MB — **Effort:** XS

#### P1-D3-072: No request ID for multi-step action tracing
- **Severity:** LOW — **Fix:** Define REQUEST_ID constant in bootstrap — **Effort:** XS
- Cross-ref: P1-D3-068

#### P1-D3-073: Log injection via unsanitized session values
- **Severity:** LOW — **Location:** logger.php:26-27 — **Fix:** Sanitize login/IP in gameLog() — **Effort:** XS

#### P1-D3-074: No cron job health monitoring (silent failures)
- **Severity:** LOW — **Fix:** Add Healthchecks.io heartbeat ping — **Effort:** XS

#### P1-D3-075: database.php errors go to error_log, not structured game log
- **Severity:** LOW — **Fix:** Route to logError() with fallback — **Effort:** XS

#### P1-D3-076: No VPS resource monitoring (CPU, RAM, disk I/O)
- **Severity:** MEDIUM — **Fix:** Install Netdata on VPS — **Effort:** S

#### P1-D3-077: Log files stored inside web root
- **Severity:** MEDIUM — **Fix:** Move to /var/log/tvlw/ — **Effort:** S

#### P1-D3-078: No alerting on repeated combat errors or game state corruption
- **Severity:** LOW — **Fix:** Threshold-based admin alert from logError() — **Effort:** S

#### P1-D3-079: No changelog/version endpoint — deployed version not observable
- **Severity:** LOW — **Fix:** Write git hash to data/VERSION on deploy — **Effort:** XS

---

## Area 5: Database Technology (16 findings)

#### P1-D3-080: Mixed charset — latin1 legacy vs utf8mb4 new tables, FK cross-charset
- **Severity:** HIGH — **Location:** migrations/ — **Fix:** Full utf8mb4 migration — **Effort:** M
- Cross-ref: P1-D3-085, P1-D3-094

#### P1-D3-081: withTransaction() catches Exception not Throwable
- **Severity:** HIGH — **Location:** database.php:117 — **Fix:** Change to `catch (\Throwable $e)` — **Effort:** XS
- Cross-ref: P1-D2-030

#### P1-D3-082: Mixed transaction styles — bare mysqli_begin_transaction in game_actions.php
- **Severity:** MEDIUM — **Location:** game_actions.php:109 — **Fix:** Refactor to use withTransaction — **Effort:** S
- Cross-ref: P1-D2-035

#### P1-D3-083: Per-request connection with no pooling
- **Severity:** MEDIUM — **Location:** connexion.php:14 — **Fix:** Use `p:` persistent connection prefix — **Effort:** XS

#### P1-D3-084: Connection failure produces French die() with no error logging
- **Severity:** MEDIUM — **Location:** connexion.php:16-18 — **Fix:** Log error, return 503 — **Effort:** XS

#### P1-D3-085: Inconsistent charset across tables (latin1/utf8/utf8mb4)
- **Severity:** MEDIUM — Cross-ref: P1-D3-080

#### P1-D3-086: No MariaDB slow query log configured
- **Severity:** MEDIUM — **Fix:** Enable in /etc/mysql/my.cnf — **Effort:** XS
- Cross-ref: P1-D3-066

#### P1-D3-087: migrate.php multi-query batch with no partial-failure recovery
- **Severity:** MEDIUM — **Fix:** Add IF NOT EXISTS guards, split statements — **Effort:** S
- Cross-ref: P1-D3-044

#### P1-D3-088: No MariaDB binary logging — point-in-time recovery impossible
- **Severity:** MEDIUM — **Fix:** Enable log_bin + expire_logs_days — **Effort:** S

#### P1-D3-089: MariaDB 10.11 — no upgrade path documented (EOL 2028)
- **Severity:** LOW — **Fix:** Document upgrade to 11.4 LTS — **Effort:** S

#### P1-D3-090: Migration 0015 contains conflicting manual migrations table INSERT
- **Severity:** LOW — **Location:** 0015_fix_schema_issues.sql:18 — **Fix:** Remove embedded INSERT — **Effort:** XS

#### P1-D3-091: migrate.php lacks CLI-only guard
- **Severity:** LOW — **Fix:** Add `PHP_SAPI !== 'cli'` check — **Effort:** XS

#### P1-D3-092: dbQuery() implicitly requires mysqlnd — no availability check
- **Severity:** LOW — **Fix:** Add startup assertion — **Effort:** XS

#### P1-D3-093: No down-migration (rollback) support
- **Severity:** LOW — **Fix:** Add .down.sql convention or adopt Phinx — **Effort:** S
- Cross-ref: P1-D3-044

#### P1-D3-094: player_compounds latin1 vs login_history utf8mb4 inconsistency
- **Severity:** LOW — Cross-ref: P1-D3-080

#### P1-D3-095: health.php uses raw mysqli_query bypassing db abstraction
- **Severity:** LOW — **Fix:** Use dbFetchOne() — **Effort:** XS

---

## Area 6: MariaDB Configuration & Performance (5 findings — from DB subagent)

#### P1-D3-096: No MariaDB configuration tuning documented
- **Severity:** MEDIUM — **Fix:** Tune innodb_buffer_pool_size, flush_log, thread_cache — **Effort:** S

#### P1-D3-097: cours table grows unboundedly — no archival
- **Severity:** MEDIUM — **Fix:** Add cleanup cron deleting records > 90 days — **Effort:** XS

#### P1-D3-098: No read replica — all reads hit primary
- **Severity:** LOW — **Fix:** Not needed at current scale; document threshold — **Effort:** L

#### P1-D3-099: No APM — zero request timing instrumentation
- **Severity:** MEDIUM — **Fix:** Add microtime() shim to auth guards — **Effort:** S
- Cross-ref: P1-D3-062

#### P1-D3-100: No player analytics or retention data collection
- **Severity:** LOW — **Fix:** Weekly cron metrics + game_metrics table — **Effort:** M

---

## Cross-Domain References

| This Finding | References |
|---|---|
| P1-D3-011 (strict_types) | P1-D2-020 |
| P1-D3-012 (type declarations) | P1-D2-021 |
| P1-D3-018 (combat.php script) | P1-D2-043 |
| P1-D3-019 (global variables) | P1-D2-042, P1-D2-047 |
| P1-D3-081 (Throwable) | P1-D2-030 |
| P1-D3-082 (mixed transactions) | P1-D2-035 |
