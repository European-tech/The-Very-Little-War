# TVLW Comprehensive Audit — Master Synthesis

**Date:** 2026-03-02
**Scope:** Full codebase audit — 104 PHP files, 18 JS files, 16 CSS files, SQL schema, deployment config
**Methodology:** 15 domain-specific audits + test coverage analysis, each reading every file in scope

---

## Executive Summary

| Domain | Findings | Critical | High | Medium | Low |
|--------|----------|----------|------|--------|-----|
| ARCH (Architecture) | 16 | 3 | 5 | 6 | 2 |
| CODE (Includes) | 38 | 0 | 10 | 15 | 13 |
| CODE (Pages) | 48 | 4 | 12 | 18 | 14 |
| DB (Database) | 27 | 3 | 8 | 11 | 5 |
| SEC (Security) | 44 | 3 | 12 | 15 | 8+ |
| PERF (Performance) | 27 | 4 | 8 | 10 | 5 |
| GAME (Game Logic) | 38 | — | — | — | — |
| UI (User Interface) | 15 | 2 | 4 | 5 | 4 |
| ERR (Error Handling) | 9 | 1 | 3 | 3 | 2 |
| DATA (Data Integrity) | 11 | 2 | 4 | 3 | 2 |
| CONCUR (Concurrency) | 9 | 3 | 3 | 2 | 1 |
| DEPLOY (Deployment) | 8 | 1 | 2 | 3 | 2 |
| AUTH (Authentication) | 9 | 1 | 3 | 3 | 2 |
| SESS (Session) | 6 | 1 | 2 | 2 | 1 |
| INPUT (Input Validation) | 13 | 1 | 4 | 5 | 3 |
| **TOTAL (raw)** | **~318** | | | | |
| **After dedup** | **~200** | **~25** | **~55** | **~75** | **~45** |

**Test coverage:** 6 unit test files (2,673 lines), 218 tests, **45 currently failing** (stale assertions after balance changes). Estimated 15-20% logic coverage. Zero integration tests.

---

## Top 20 Critical & High Findings (Deduplicated, Prioritized)

### Tier 1: Must Fix Before Production (Data Corruption / Security Breach Risk)

**1. No transactions in combat** (DB-002, ARCH-008, CONCUR-001)
- Combat updates 6+ tables sequentially with no BEGIN/COMMIT
- Two simultaneous attacks can resolve the same combat twice, duplicating resources
- Fix: `mysqli_begin_transaction()` / `commit()` wrapper

**2. Session cookie not secure** (SEC-024, SESS-001)
- `session.cookie_secure=0` — cookies sent over HTTP
- Fix: Enable HTTPS, set to 1, add SameSite=Lax (SEC-025, SESS-002)

**3. Race condition in market trades** (CONCUR-002)
- No atomicity — concurrent buys drain energy below zero
- Fix: Transaction + SELECT FOR UPDATE

**4. Season reset has no mutual exclusion** (CONCUR-003)
- Two simultaneous requests trigger double reset
- Fix: Advisory lock or flag table

**5. DB credentials hardcoded** (SEC-030, DEPLOY-001, DB-001)
- Root/empty password in repo source code
- Fix: .env file, gitignored

**6. Legacy query() allows raw SQL injection** (SEC-001, CODE-003)
- `db_helpers.php:7-16` bypasses prepared statements entirely
- Fix: Remove function, replace 3 call sites with dbQuery()

**7. No auth on messageCommun.php** (CODE-PAGE critical)
- Anonymous users can inject mass messages
- Fix: Delete or add auth guard

**8. Stored XSS in rapports.php** (INPUT-001)
- `echo $rapports['contenu']` without escaping
- Fix: htmlspecialchars() or sanitize at generation time

**9. XSS in all UI wrapper functions** (UI-001)
- `debutCarte`, `item`, `chip`, `submit` echo params without escaping
- Fix: htmlspecialchars() on all string parameters

**10. No rate limiting on admin login** (SEC-034, AUTH-003)
- Unlimited brute-force attempts on admin panel
- Fix: rateLimitCheck() on admin/moderation login

### Tier 2: High Priority (Performance / Game Balance / Code Quality)

**11. N+1 map query: 2,900 queries** (PERF-001, DB-003)
- attaquer.php loads all players then 3 queries per player
- Fix: Single JOIN query (~30 queries)

**12. initPlayer() called 3-4x per request** (PERF-002, ARCH-001)
- 60-80 redundant queries per page load
- Fix: Static request-level cache, call once

**13. updateRessources() = 50-65 queries** (PERF-003)
- 8 separate UPDATE per atom type, each with sub-queries
- Fix: Batch atom UPDATE, pass pre-loaded data

**14. Season reset blocks for 30-180 seconds** (PERF-004, ARCH-006)
- Runs inside page request, sends mail() in a loop to 930 users
- Fix: Move to cron job, use mail queue

**15. combat.php included via include(), shares caller scope** (ARCH-002, CODE-005)
- 602 lines creating dozens of variables via variable-variables
- Fix: Refactor to function resolveCombat() returning array

**16. No CSP header** (SEC-036)
- Any XSS can load arbitrary external scripts
- Fix: Add Content-Security-Policy header

**17. Password hash stored in session** (SEC-019, AUTH-002)
- Full bcrypt hash in session file on disk, non-timing-safe comparison
- Fix: Use random session token instead

**18. jQuery 1.7.2 has known CVEs** (SEC-041)
- XSS vulnerabilities (CVE-2015-9251, CVE-2019-11358, CVE-2020-11022)
- Fix: Remove old jQuery, use 3.5+

**19. Defender resources can go negative** (DATA-001)
- No `max(0, ...)` guard after combat pillage
- Fix: Clamp to zero

**20. prestige queries non-existent medailles table** (DB-010)
- calculatePrestigePoints() silently fails
- Fix: Use existing medal data from autre table

---

## Systemic Issues (Cross-Cutting)

### A. Read-Modify-Write Without Atomicity
Appears in: market trades, combat resolution, point calculations, building upgrades, formation processing, resource sending. The codebase has ZERO use of transactions.
**Fix:** Create `withTransaction($base, callable)` helper. Use `SELECT ... FOR UPDATE` for contested rows.

### B. Global State Everywhere
45+ functions use `global $base`. initPlayer() creates 30+ globals. Zero dependency injection.
**Fix:** Pass `$base` as parameter. Return data from initPlayer() instead of setting globals.

### C. No Separation of Concerns
SQL, business logic, HTML, and JS interleaved in single files. Combat reports generated inside action processing.
**Fix:** Gradual MVC extraction — models (game logic+DB), views (templates), controllers (pages).

### D. Dynamic Column Names in SQL
`ajouter()`, `allianceResearchLevel()`, `augmenterBatiment()`, `updateRessources()` all interpolate column names.
**Fix:** Whitelist validation in each function.

### E. Output Encoding Inconsistency
Some paths use `antiXSS()` (triple-encoding), some use `htmlspecialchars()`, some use nothing.
**Fix:** Standardize on `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` for all output. Remove antiXSS().

---

## PHP Version Upgrade Path

**Current:** PHP 8.2 on VPS (Debian 12)
**Local:** PHP 8.3.6 (Zorin OS 18 / Ubuntu Noble)
**Recommendation:** Target PHP 8.3 minimum

### Why Upgrade
- `strftime()` removed in 8.1 — CODE-PAGES audit found a fatal crash from it in attaquer.php
- `json_validate()` (8.3) — useful for API input validation
- `#[Override]` attribute (8.3) — future-proofing
- Typed class constants (8.3)
- 15-20% performance improvement over 8.2
- Security patches through Dec 2027 (8.3) vs Dec 2026 (8.2)

### Upgrade Steps
1. Add sury.org PHP PPA on VPS: `add-apt-repository ppa:ondrej/php`
2. Install php8.3-* packages alongside 8.2
3. Run tests on 8.3 locally (already working)
4. Switch Apache: `a2dismod php8.2 && a2enmod php8.3`
5. Update composer.json: `"php": ">=8.3"`
6. Replace `strftime()` calls with `IntlDateFormatter` or `date()`
7. Fix any deprecation warnings

---

## Test Strategy

### Current State
- 6 test files, 218 tests, **45 failing** (stale after balance rewrite)
- Tests reimplement formulas instead of calling real functions
- Zero integration tests, zero E2E tests
- bootstrap.php doesn't load formulas.php — tests never exercise real game functions

### Critical Finding: Formula Discrepancy
`coutClasse()` in formulas.php uses `pow($numero + 1, 4)` but ConfigConsistencyTest asserts exponent = 6. Live inconsistency.

### Test Plan: 165 New Tests in 17 Files

| Priority | Focus | Tests | Effort |
|----------|-------|-------|--------|
| P1 | Exploit prevention (combat, boundaries, points) | ~40 | 540 LOC |
| P2 | Security (CSRF, validation, rate limiter, auth) | ~35 | 475 LOC |
| P3 | Data integrity (season reset, player deletion, alliance, prestige) | ~40 | 900 LOC |
| P4 | Integration flows (combat, market, registration, admin) | ~30 | 800 LOC |
| P5 | Regression (18 known bugs) | ~20 | 400 LOC |
| **Total** | | **~165** | **~3,115 LOC** |

### Infrastructure Needed
1. Fix bootstrap.php to load `formulas.php` (real functions, not shadows)
2. Create `tests/bootstrap_integration.php` with real DB connection
3. Create `tests/fixtures/PlayerFactory.php` for integration tests
4. Create `tests/integration/IntegrationTestCase.php` (transaction rollback)
5. Export schema: `mysqldump --no-data tvlw > tests/fixtures/schema.sql`
6. Update phpunit.xml with Unit + Integration test suites
7. Fix rate_limiter.php to make RATE_LIMIT_DIR overridable for testing
8. GitHub Actions CI workflow

### Immediate Quick Wins (can write today)
1. Fix 45 failing tests (update expected values to match new balance)
2. CSRF tests — pure unit, no DB needed
3. Rate limiter tests — filesystem only
4. Fix bootstrap to load real formulas, delete shadow implementations
5. Add validation edge cases (SQL injection attempts, Unicode, null)

---

## Remediation Roadmap

### Phase 1: Critical Security + Data Integrity (1-2 days)
- [ ] Enable HTTPS + session.cookie_secure=1 + SameSite=Lax + HSTS
- [ ] Move DB credentials to .env
- [ ] Remove legacy query() function
- [ ] Add transactions to combat resolution
- [ ] Add transactions to market trades
- [ ] Add advisory lock to season reset
- [ ] Fix messageCommun.php (delete or auth)
- [ ] Fix rapports.php XSS
- [ ] Add rate limiting to admin login
- [ ] Add CSP header

### Phase 2: XSS + Output Encoding (1 day)
- [ ] Standardize output encoding (htmlspecialchars everywhere)
- [ ] Fix UI component functions (debutCarte, item, chip, etc.)
- [ ] Fix joueur() and alliance() helper functions
- [ ] Fix combat report HTML generation
- [ ] Fix forum subject title display
- [ ] Remove/replace antiXSS() with clean function
- [ ] Add CSRF to voter.php (change to POST)

### Phase 3: Performance (2-3 days)
- [ ] Cache initPlayer() per-request (static variable)
- [ ] Rewrite attaquer.php map query (JOIN instead of N+1)
- [ ] Batch updateRessources() (single UPDATE per resource table)
- [ ] Cache catalystEffect() per-request
- [ ] Move season reset to cron job
- [ ] Consolidate building queue queries (GROUP BY)
- [ ] Fix classement.php N+1 queries
- [ ] Enable mod_deflate for CSS/JS compression

### Phase 4: Test Infrastructure + Fix Failures (1-2 days)
- [ ] Fix 45 failing unit tests
- [ ] Fix bootstrap.php to load real formulas
- [ ] Upgrade PHP to 8.3 on VPS
- [ ] Replace strftime() with date()
- [ ] Write P1 exploit prevention tests
- [ ] Write P2 security tests (CSRF, rate limiter)
- [ ] Set up integration test infrastructure

### Phase 5: Architecture Cleanup (ongoing)
- [ ] Refactor combat.php to function
- [ ] Add column whitelists to all dynamic SQL
- [ ] Replace variable-variables with arrays
- [ ] Extract season reset from auth guard
- [ ] Split updateActions() into focused functions
- [ ] Remove jQuery 1.7.2, update to 3.5+
- [ ] Convert MyISAM tables to InnoDB
- [ ] Charset migration to utf8mb4
- [ ] Add cours table pruning

### Phase 6: Advanced Tests (ongoing)
- [ ] P3 data integrity tests
- [ ] P4 integration flow tests
- [ ] P5 regression tests for all 18 known bugs
- [ ] GitHub Actions CI pipeline

---

## Detailed Findings Index

All findings are in separate domain files under `docs/audit/`:

| File | Domain(s) | Findings |
|------|-----------|----------|
| ARCH-findings.md | Architecture | 16 |
| SEC-findings.md | Security | 44 |
| PERF-findings.md | Performance | 27 |
| DB-findings.md | Database | 27 |
| CODE-findings-includes.md | Code Quality (includes/) | 38 |
| CODE-findings-pages.md | Code Quality (pages) | 48 |
| GAME-findings.md | Game Logic | 38 |
| AUTH-SESS-INPUT-findings.md | Auth + Session + Input | 28 |
| UI-ERR-DATA-CONCUR-DEPLOY-findings.md | UI + Error + Data + Concurrency + Deploy | 52 |
| TEST-findings-and-plan.md | Test Coverage + Plan | N/A (analysis) |

---

## Metrics

- **Total unique findings:** ~243 (raw), ~200 after deduplication
- **PHP files audited:** 92 (all)
- **JS files audited:** 18 (all)
- **Domain agents deployed:** 9 (covering 15 domains)
- **Average domains per PHP file:** 6.8
- **Minimum domains per file:** 4
- **Estimated remediation effort:** 2-3 weeks for phases 1-4, ongoing for phases 5-6
