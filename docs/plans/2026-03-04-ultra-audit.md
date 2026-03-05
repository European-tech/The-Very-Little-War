# TVLW Ultra Audit — Comprehensive Multi-Domain Analysis

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Execute a 7-domain parallel audit of The Very Little War codebase using coordinated agent teams (coordinator + 3-5 subagents per domain) to identify every bug, security hole, code quality issue, balance flaw, missing feature, and architectural weakness — then produce a unified remediation roadmap.

**Architecture:** Each domain has a **coordinator agent** managing 3-5 independent **subagents**. The orchestrator (main session) manages all coordinators. Subagents work in parallel within each domain, producing finding reports. Coordinators merge findings, deduplicate, and prioritize. Final synthesis merges all domain reports into one master remediation plan.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 Material CSS, jQuery 3.7.1, PHPUnit 9, Debian 12

---

## Audit Architecture Overview

```
                          ┌──────────────┐
                          │ ORCHESTRATOR │ (Main Session)
                          │   (You)      │
                          └──────┬───────┘
                                 │
          ┌──────────┬───────────┼───────────┬──────────┬──────────┬──────────┐
          ▼          ▼           ▼           ▼          ▼          ▼          ▼
     ┌─────────┐┌─────────┐┌─────────┐┌─────────┐┌─────────┐┌─────────┐┌─────────┐
     │ DOMAIN 1││ DOMAIN 2││ DOMAIN 3││ DOMAIN 4││ DOMAIN 5││ DOMAIN 6││ DOMAIN 7│
     │Security ││Code Qual││Tech/Infr││Balance  ││Mechanics││UX/Perf  ││Database │
     │Coord.   ││Coord.   ││Coord.   ││Coord.   ││Coord.   ││Coord.   ││Coord.   │
     └────┬────┘└────┬────┘└────┬────┘└────┬────┘└────┬────┘└────┬────┘└────┬────┘
      ┌─┬─┼─┬─┐ ┌─┬─┼─┬─┐ ┌─┬─┼─┬─┐ ┌─┬─┼─┬─┐ ┌─┬─┼─┬─┐ ┌─┬─┼─┬─┐ ┌─┬─┼─┐
      S S S S S S S S S S S S S S S S S S S S S S S S S S S S S S S S S S S
```

**Total: 7 coordinators + ~28 subagents = ~35 parallel agent threads**

---

## Process Flow

### Phase 1: Discovery (Parallel Domain Audits)
1. Launch all 7 domain coordinators simultaneously
2. Each coordinator dispatches 3-5 subagents with specific file scopes
3. Subagents produce structured finding reports (ID, severity, location, description, fix)
4. Coordinators merge + deduplicate their domain findings

### Phase 2: Cross-Domain Correlation
5. Coordinators share findings with orchestrator
6. Orchestrator identifies cross-domain patterns (e.g., security issue + balance exploit)
7. Dependency mapping between findings

### Phase 3: Remediation Roadmap
8. Priority scoring: CRITICAL > HIGH > MEDIUM > LOW
9. Effort estimation per finding
10. Sprint grouping (logical batches, dependency-ordered)
11. Final document: `ultra-audit-findings.md` + `ultra-audit-remediation.md`

### Finding Format (All Domains)

```markdown
#### [DOMAIN-NNN] Title
- **Severity:** CRITICAL | HIGH | MEDIUM | LOW
- **Category:** Bug | Security | Quality | Balance | UX | Performance | Architecture
- **Location:** `file.php:line` or `config.php:CONSTANT_NAME`
- **Description:** What's wrong
- **Impact:** What happens if left unfixed
- **Fix:** Proposed solution (code or design)
- **Effort:** XS (minutes) | S (< 1h) | M (1-4h) | L (4-8h) | XL (> 1 day)
- **Cross-Refs:** Related findings in other domains
```

---

## DOMAIN 1: Security & Vulnerability Audit

**Coordinator Agent:** `comprehensive-review:security-auditor`
**Goal:** Find every exploitable vulnerability, auth bypass, injection vector, and data leak

### Subagent 1.1: Authentication & Session Security
**Agent:** `voltagent-qa-sec:security-auditor`
**Scope:**
- `includes/basicprivatephp.php` — session validation completeness
- `includes/basicpublicphp.php` — login flow, password handling
- `includes/session_init.php` — session initialization
- `includes/csrf.php` — CSRF token lifecycle
- `inscription.php` — registration flow
- `compte.php` — password/email change
- `deconnexion.php` — logout completeness
- `comptetest.php` — test account security (should this exist in prod?)

**Checklist:**
- [ ] Session fixation resistance
- [ ] Session token rotation on privilege change
- [ ] CSRF token per-form vs per-session trade-offs
- [ ] Password policy enforcement (min length, complexity)
- [ ] Bcrypt cost factor (is 10 sufficient for 2026?)
- [ ] Cookie flags (Secure, HttpOnly, SameSite)
- [ ] Login brute-force protection effectiveness
- [ ] Account enumeration via timing or error messages
- [ ] comptetest.php in production (should be disabled or admin-only)
- [ ] Session idle timeout implementation correctness

### Subagent 1.2: Input Validation & Injection
**Agent:** `voltagent-qa-sec:penetration-tester`
**Scope:**
- `includes/validation.php` — all validation functions
- `includes/bbcode.php` — BBCode parser (XSS vectors)
- `includes/database.php` — prepared statement coverage
- ALL form-handling pages (POST endpoints)
- `api.php` — API input validation
- `admin/*.php` — admin input handling

**Checklist:**
- [ ] Every user input path traced to DB or output
- [ ] BBCode [img] tag — can it bypass CSP? Open redirect?
- [ ] BBCode [color] — CSS injection via style attribute?
- [ ] Second-order SQL injection (stored data used in later queries)
- [ ] Type juggling in PHP comparisons (== vs ===)
- [ ] Integer overflow in game formulas (PHP_INT_MAX edge cases)
- [ ] File upload in compte.php (profile image) — MIME validation, path traversal
- [ ] API dispatch table — can unknown actions leak errors?
- [ ] Admin pages — parameter tampering
- [ ] Column whitelist completeness in db_helpers.php

### Subagent 1.3: Authorization & Access Control
**Agent:** `voltagent-qa-sec:code-reviewer`
**Scope:**
- Every page's auth guard (basicprivatephp.php inclusion)
- `admin/` directory — admin auth verification
- `moderation/` directory — moderator auth
- `validerpacte.php` — alliance pact authorization
- `allianceadmin.php` — alliance admin actions
- `moderationForum.php` — mod actions
- `don.php` — donation authorization
- `guerre.php` — war declaration authorization
- `api.php` — API auth

**Checklist:**
- [ ] Every admin page verifies admin session
- [ ] Every alliance admin action verifies leadership/grade
- [ ] No IDOR (Insecure Direct Object Reference) in player-facing actions
- [ ] Can Player A delete Player B's messages?
- [ ] Can Player A edit Player B's forum posts?
- [ ] Can non-alliance members perform alliance actions?
- [ ] Rate limiting on sensitive actions (attacks, market, donations)
- [ ] Vacation mode bypass — can a player on vacation be attacked?
- [ ] Beginner protection bypass vectors

### Subagent 1.4: Infrastructure & Headers
**Agent:** `voltagent-infra:security-engineer`
**Scope:**
- `.htaccess` — security headers completeness
- `includes/csp.php` + `includes/layout.php` — CSP policy
- `includes/meta.php` — meta security tags
- `health.php` — information disclosure
- `composer.json` — dependency vulnerabilities
- Server configuration (PHP settings, Apache config)
- `includes/env.php` — .env handling
- `includes/logger.php` — log injection

**Checklist:**
- [ ] CSP policy — any unsafe-eval? Third-party scripts?
- [ ] HSTS header (requires HTTPS — currently missing)
- [ ] Permissions-Policy header
- [ ] health.php — does it leak server internals?
- [ ] .env file — is it served by Apache? (check .htaccess)
- [ ] Error pages — do they leak stack traces?
- [ ] PHPUnit accessible from web? (vendor/ exposed?)
- [ ] composer.lock — known CVEs in dependencies
- [ ] Log files — accessible from web?
- [ ] data/rates/ — accessible from web?

### Subagent 1.5: Game-Specific Exploits
**Agent:** `voltagent-qa-sec:penetration-tester`
**Scope:**
- `includes/combat.php` — combat manipulation
- `includes/game_actions.php` — race conditions
- `marche.php` — market manipulation
- `don.php` — resource transfer exploits
- `includes/multiaccount.php` — detection bypass
- `includes/compounds.php` — compound stacking
- `includes/resource_nodes.php` — node exploitation

**Checklist:**
- [ ] Race condition: double-submit attack action (CAS guard bypass?)
- [ ] Race condition: simultaneous building constructions
- [ ] Market manipulation: buy-sell cycle to extract energy
- [ ] Compound stacking: use multiple compounds of same type
- [ ] Resource transfer: alt-account farming despite detection
- [ ] Negative number exploits in any formula input
- [ ] Overflow: molecules with 2^31+ atoms
- [ ] Time manipulation: server-side time validation
- [ ] Action queue: can a player queue impossible actions?
- [ ] Multi-account detection: VPN/Tor bypass

---

## DOMAIN 2: Code Quality & Architecture Audit

**Coordinator Agent:** `comprehensive-review:code-reviewer`
**Goal:** Identify code smells, duplication, dead code, naming issues, PHP anti-patterns, and architectural weaknesses

### Subagent 2.1: Code Duplication & DRY Violations
**Agent:** `voltagent-qa-sec:code-reviewer`
**Scope:** All PHP files
**Focus:**
- [ ] Copy-pasted code blocks across pages
- [ ] Similar query patterns not using db_helpers
- [ ] Repeated HTML generation not in ui_components.php
- [ ] Duplicated validation logic
- [ ] Similar auth guard patterns that could be unified
- [ ] Inline SQL vs prepared statement helpers

### Subagent 2.2: PHP Modernization & Best Practices
**Agent:** `voltagent-lang:php-pro`
**Scope:** All PHP files
**Focus:**
- [ ] PHP 7.4 minimum in composer.json but running PHP 8.2 — use 8.2 features
- [ ] Type declarations (parameter types, return types) — currently missing everywhere
- [ ] Null coalescing operator usage
- [ ] Match expressions instead of switch
- [ ] Named arguments where beneficial
- [ ] Readonly properties for config
- [ ] Enums for isotopes, formations, specializations (currently int constants)
- [ ] Strict types declaration (`declare(strict_types=1)`)
- [ ] Error handling: exceptions vs return codes
- [ ] Consistent coding style (PSR-12)

### Subagent 2.3: Architecture & Module Structure
**Agent:** `everything-claude-code:architect-review` (via comprehensive-review:architect-review)
**Scope:** includes/ directory structure
**Focus:**
- [ ] God file analysis: player.php (971 lines), combat.php (699 lines), config.php (756 lines)
- [ ] Module coupling: which files depend on which?
- [ ] Separation of concerns: business logic in page files?
- [ ] MVC pattern feasibility (or similar lightweight pattern)
- [ ] Dependency injection opportunities
- [ ] Global state usage ($base, $_SESSION, $_POST everywhere)
- [ ] Include path management (require_once chains)
- [ ] Configuration management (config.php as array vs class)
- [ ] Template/view separation from logic

### Subagent 2.4: Error Handling & Logging
**Agent:** `voltagent-qa-sec:error-detective`
**Scope:** All PHP files
**Focus:**
- [ ] Uncaught exceptions that could crash pages
- [ ] Silent failures (empty catch blocks, @error suppression)
- [ ] Logger usage consistency (some files may not log errors)
- [ ] Error response consistency (JSON API vs HTML pages)
- [ ] Database error handling in all query paths
- [ ] Transaction rollback completeness
- [ ] User-facing error messages (information disclosure)
- [ ] HTTP status codes (currently everything returns 200?)

### Subagent 2.5: Dead Code & Unused Features
**Agent:** `everything-claude-code:refactor-cleaner`
**Scope:** All PHP files, JS files, CSS files
**Focus:**
- [ ] Unreachable code paths
- [ ] Unused functions in modules
- [ ] Unused config constants
- [ ] Dead admin pages (admin/tableau.php — noted as dead)
- [ ] Commented-out code blocks
- [ ] Unused CSS classes
- [ ] Unused JS files or functions
- [ ] Orphaned database columns/tables
- [ ] Features that exist in code but have no UI entry point

---

## DOMAIN 3: Technology & Infrastructure Audit

**Coordinator Agent:** `voltagent-infra:platform-engineer`
**Goal:** Evaluate current tech stack, propose modern alternatives, assess monitoring/observability, CI/CD, deployment

### Subagent 3.1: PHP Ecosystem & Framework Evaluation
**Agent:** `voltagent-lang:php-pro`
**Scope:** composer.json, all includes/
**Focus:**
- [ ] Framework evaluation: stay vanilla PHP vs lightweight framework (Slim, Lumen, Leaf)?
- [ ] Templating: raw PHP echo vs Twig/Blade/Plates?
- [ ] Routing: current file-based routing vs router library?
- [ ] ORM evaluation: raw SQL vs Eloquent/Doctrine/PDO wrapper?
- [ ] Dependency management: composer packages that would help
  - [ ] monolog/monolog for logging
  - [ ] vlucas/phpdotenv for .env (instead of custom env.php)
  - [ ] league/plates for templating
  - [ ] phpmailer/phpmailer for email
  - [ ] firebase/php-jwt if adding API auth
- [ ] PHP static analysis: PHPStan/Psalm introduction
- [ ] Code formatting: PHP-CS-Fixer or PHP_CodeSniffer
- [ ] PHPUnit 9 → PHPUnit 11 upgrade path

### Subagent 3.2: Frontend Technology Assessment
**Agent:** `voltagent-core-dev:frontend-developer`
**Scope:** JS files, CSS files, HTML in layout.php
**Focus:**
- [ ] Framework7 Material CSS — still maintained? Alternatives?
- [ ] jQuery 3.7.1 — modern alternatives (htmx, Alpine.js, vanilla JS)?
- [ ] Google Charts — alternatives (Chart.js, ApexCharts)?
- [ ] Mobile responsiveness audit
- [ ] Asset bundling (currently none — add Vite/esbuild?)
- [ ] CSS architecture (currently one monolith?)
- [ ] Web components feasibility
- [ ] Service worker for offline support
- [ ] PWA potential (manifest.json, service worker)

### Subagent 3.3: CI/CD & DevOps
**Agent:** `voltagent-infra:devops-engineer`
**Scope:** .github/workflows/ci.yml, deployment scripts
**Focus:**
- [ ] Current CI pipeline completeness (does it run tests? lint? deploy?)
- [ ] Automated deployment to VPS (currently manual git pull?)
- [ ] Staging environment (does one exist?)
- [ ] Database migration automation (migrate.php — is it safe?)
- [ ] Rollback strategy
- [ ] Environment parity (local vs VPS)
- [ ] Docker containerization potential
- [ ] Backup strategy (DB backups, file backups)
- [ ] Blue-green or rolling deployment

### Subagent 3.4: Monitoring & Observability
**Agent:** `voltagent-infra:sre-engineer`
**Scope:** health.php, includes/logger.php, server config
**Focus:**
- [ ] APM (Application Performance Monitoring) — none currently
- [ ] Error tracking service (Sentry, Rollbar?)
- [ ] Uptime monitoring (health.php exists — who checks it?)
- [ ] Log aggregation and analysis
- [ ] Database slow query logging
- [ ] Game metrics dashboard (active players, battles/day, market volume)
- [ ] Alert system for critical events (server down, DB full, etc.)
- [ ] Resource usage monitoring (CPU, RAM, disk)
- [ ] Player analytics (session length, retention, churn)

### Subagent 3.5: Database Technology
**Agent:** `voltagent-infra:database-administrator`
**Scope:** SQL dump, migrations/, database.php
**Focus:**
- [ ] MariaDB 10.11 — upgrade path to 11.x?
- [ ] latin1 charset — migration to utf8mb4 feasibility
- [ ] Connection pooling (currently new connection per request)
- [ ] Query caching configuration
- [ ] Replication / read replicas (needed at this scale?)
- [ ] Backup automation (mysqldump cron? binlog?)
- [ ] Migration tool: custom migrate.php vs Phinx/Doctrine Migrations
- [ ] Database versioning strategy

---

## DOMAIN 4: Game Balance & Strategy Audit

**Coordinator Agent:** `voltagent-data-ai:data-scientist`
**Goal:** Mathematically analyze all formulas to ensure multiple viable strategies exist, no dominant strategy, fair progression, and competitive depth

### Subagent 4.1: Economy & Progression Curves
**Agent:** `voltagent-data-ai:data-analyst`
**Scope:** includes/config.php (all ECO_* constants), includes/formulas.php, includes/game_resources.php
**Focus:**
- [ ] Exponential growth curves: does 1.15/1.20/1.25 create runaway leaders?
- [ ] Early vs late game resource balance (first hour vs day 20)
- [ ] Building cost vs benefit curves for all 9 buildings
- [ ] Break-even analysis: when does each building "pay for itself"?
- [ ] Storage (depot) as bottleneck: is it too limiting or too generous?
- [ ] Energy vs atoms balance (can you have excess of one?)
- [ ] Compound cost-effectiveness (is H2O always best? NH3 never worth it?)
- [ ] Alliance duplicateur: does alliance size create unfair advantage?
- [ ] Prestige bonus (1.05x) — too small to matter or too large for new players?
- [ ] Resource node RNG — too much variance between player positions?

### Subagent 4.2: Combat Balance & Strategy Diversity
**Agent:** `voltagent-data-ai:data-analyst`
**Scope:** includes/combat.php, includes/config.php (COMBAT_*, FORMATION_*, ISOTOPE_*)
**Focus:**
- [ ] Is there a dominant army composition? (e.g., all-O/H glass cannon always wins)
- [ ] Formation effectiveness: is Phalange always better than Dispersée?
- [ ] Isotope balance: is Reactif (+20% atk, -10% HP) always optimal?
- [ ] Catalytique isotope: is +15% to allies worth -10% to self?
- [ ] Overkill cascade: does it punish diverse armies too much?
- [ ] Attack vs defense asymmetry: is attacking always better?
- [ ] Cooldown system: 4h loss / 1h win — does this discourage attacks?
- [ ] Beginner protection: how long is it? Is it exploitable?
- [ ] Building damage: random targeting — too RNG-dependent?
- [ ] Vault protection: 2% per level, max 50% — is coffrefort mandatory?
- [ ] Defense reward (20% of pillage capacity): sufficient incentive to defend?
- [ ] Soufre (S) pillage atom: is pillage too profitable?
- [ ] Chlore (Cl) speed bonus: does speed matter enough?

### Subagent 4.3: Win Condition & Ranking Analysis
**Agent:** `voltagent-data-ai:data-analyst`
**Scope:** includes/config.php (RANKING_*, VICTORY_POINTS_*, MEDAL_*)
**Focus:**
- [ ] SQRT ranking weights: Construction(1.0), Attack(1.5), Defense(1.5), Trade(1.0), Pillage(1.2)
  - Does attack+defense weighting make combat king?
  - Can a peaceful trader ever rank #1?
  - Is pillage weight (1.2) appropriate?
- [ ] Victory points distribution: top 1 gets 100VP, top 50 gets ~6VP — too top-heavy?
- [ ] Alliance VP: does alliance rank matter enough for cooperation?
- [ ] Medal thresholds: are they achievable for casual players?
- [ ] Medal tier bonuses (1% → 50%): Diamant Rouge too powerful?
- [ ] Cross-season medal cap (10%): does it prevent snowballing?
- [ ] Grace period (14 days cap at Gold 6%): fair to veterans?
- [ ] Prestige PP distribution: does winning more give even more advantage?
- [ ] Multiple viable strategies to win (combat, economic, hybrid, alliance)?
- [ ] Turtle strategy viability (pure defense + vault + high storage)
- [ ] Rush strategy viability (early aggression before protection ends)

### Subagent 4.4: Formula Consistency & Edge Cases
**Agent:** `voltagent-data-ai:data-scientist`
**Scope:** includes/formulas.php, includes/config.php, tests/balance/
**Focus:**
- [ ] Are all formulas monotonically increasing with investment?
- [ ] Any formula that becomes negative at extreme values?
- [ ] Division by zero possibilities in any formula
- [ ] Overflow at extreme atom counts (2^31 atoms?)
- [ ] Decay formula: can molecules ever grow instead of decay?
- [ ] Stabilisateur asymptote (0.98): does decay ever reach 0?
- [ ] Speed soft cap (30): is it reachable? Is it fun?
- [ ] condenseur divisor (50): appropriate scaling?
- [ ] All modifier stacks: are they additive or multiplicative? Consistent?
- [ ] Compound + isotope + medal + prestige + specialization stacking: can it produce 10x multiplier?
- [ ] Neutrino espionage: cost (50 energy) vs information gained — worth it?
- [ ] Market volatility formula: can prices be manipulated by a single player?

### Subagent 4.5: Specialization & Late-Game Depth
**Agent:** `voltagent-data-ai:data-analyst`
**Scope:** includes/config.php ($SPECIALIZATIONS, $COMPOUNDS, $ALLIANCE_RESEARCH)
**Focus:**
- [ ] Specializations (irreversible): are all 6 options viable?
  - Combat: Oxydant (+10% atk) vs Réducteur (+10% def) — is one always better?
  - Economy: Industriel (+20% atoms) vs Énergétique (+20% energy) — atoms always better?
  - Research: Théorique (+2 cond pts) vs Appliqué (+20% formation) — which dominates?
- [ ] Alliance research (5 techs): optimal research order? Any useless tech?
- [ ] Compound synthesis: all 5 compounds used? Or only 1-2 meta?
- [ ] Late-game activity: what do endgame players do? Enough content?
- [ ] Season reset: does losing everything feel fair?
- [ ] Catch-up mechanics: can a day-20 joiner compete?

---

## DOMAIN 5: Game Mechanics & Consistency Audit

**Coordinator Agent:** `voltagent-qa-sec:debugger`
**Goal:** Find every bug, logic error, race condition, and inconsistency between code, documentation, and player experience

### Subagent 5.1: Action Queue & Timing Bugs
**Agent:** `voltagent-qa-sec:debugger`
**Scope:** includes/game_actions.php, includes/game_resources.php
**Focus:**
- [ ] CAS (Compare-And-Swap) guard effectiveness on all action types
- [ ] Race condition: two requests processing same action
- [ ] Time precision: ceil() vs floor() vs round() consistency
- [ ] Resource update timing: are resources calculated before or after action?
- [ ] Construction queue: can MAX_CONCURRENT_CONSTRUCTIONS be bypassed?
- [ ] Formation queue: molecule formation during ongoing attack
- [ ] Action deletion: orphaned actions after player deletion
- [ ] Server time vs client time synchronization
- [ ] Negative time remaining display bugs

### Subagent 5.2: Combat Logic Bugs
**Agent:** `voltagent-qa-sec:debugger`
**Scope:** includes/combat.php (699 lines, line by line)
**Focus:**
- [ ] Cascade damage overflow (overkill beyond total HP)
- [ ] Empty army attacks (zero molecules — what happens?)
- [ ] All molecules killed but building damage still applied?
- [ ] Report generation accuracy (do numbers match actual changes?)
- [ ] Alliance war tracking: do war victories count correctly?
- [ ] Simultaneous attacks on same target: resource double-pillage
- [ ] Building HP regeneration: does it happen? Should it?
- [ ] Ionisateur HP: can it reach 0? What happens then?
- [ ] Edge case: attacking yourself (is it prevented?)
- [ ] Edge case: attacking alliance member

### Subagent 5.3: Code vs Documentation Inconsistencies
**Agent:** `voltagent-qa-sec:code-reviewer`
**Scope:** All includes/, all docs/game/*.md
**Focus:**
- [ ] Compare every formula in formulas.php with docs/game/09-BALANCE.md
- [ ] Compare combat.php logic with docs/game/04-COMBAT.md
- [ ] Compare building costs in config.php with docs/game/03-BUILDINGS.md
- [ ] Compare player guide (10-PLAYER-GUIDE.md) with actual code
- [ ] Compare regles.php (in-game rules) with actual mechanics
- [ ] Are all V4 changes documented?
- [ ] Are compounds documented in player-facing content?
- [ ] Are specializations documented accurately?
- [ ] Tutorial missions: do they teach current mechanics?

### Subagent 5.4: Edge Cases & Boundary Conditions
**Agent:** `voltagent-qa-sec:qa-expert`
**Scope:** All game logic files
**Focus:**
- [ ] Level 0 buildings: are all prevented?
- [ ] Level 999+ buildings: any overflow?
- [ ] Empty alliance: what happens when last member leaves?
- [ ] Alliance with 0 members but ongoing wars
- [ ] Player deletion: cleanup completeness (all 28 tables?)
- [ ] Season reset: are all tables properly reset?
- [ ] Market: buying resource at 0 energy price
- [ ] Market: selling when storage is at exactly capacity
- [ ] Message to non-existent player
- [ ] Forum post in locked thread (race condition)
- [ ] Vacation mode: enable during active attack
- [ ] Registration: duplicate login name (case sensitivity?)
- [ ] Login: unicode characters, emoji, SQL special chars
- [ ] Map boundaries: coordinates outside valid range

### Subagent 5.5: Test Coverage Gaps
**Agent:** `voltagent-qa-sec:test-automator`
**Scope:** tests/ directory, all includes/
**Focus:**
- [ ] Which functions have zero test coverage?
- [ ] Which code paths are untested?
- [ ] Integration test gaps (no DB-connected tests for most flows)
- [ ] Missing negative tests (what should NOT work)
- [ ] Missing boundary tests (min/max values)
- [ ] No E2E tests exist — feasibility assessment
- [ ] Test quality: do tests assert correct things?
- [ ] Mock vs real DB: which tests hit the actual DB?
- [ ] Can all tests run without a live DB? (currently yes for unit)
- [ ] CI pipeline: does it run all test suites?

---

## DOMAIN 6: UX, Frontend & Performance Audit

**Coordinator Agent:** `voltagent-core-dev:ui-designer`
**Goal:** Evaluate player experience, UI/UX quality, page performance, accessibility, and mobile readiness

### Subagent 6.1: UI/UX Design Review
**Agent:** `voltagent-core-dev:ui-designer`
**Scope:** All page PHP files (HTML output), includes/ui_components.php, includes/layout.php
**Focus:**
- [ ] Visual hierarchy: can new players understand the game?
- [ ] Navigation flow: how many clicks to common actions?
- [ ] Information density: too much on screen?
- [ ] Empty states: what do new players see? (4 pages have messages, rest?)
- [ ] Error messages: clear, actionable, localized (FR)?
- [ ] Color scheme consistency across pages
- [ ] Button styles and placement consistency
- [ ] Form UX: validation feedback, loading states
- [ ] Onboarding flow: registration → first building → first molecule → first attack
- [ ] Competitive game UX: do players feel progress? Excitement? Tension?

### Subagent 6.2: Mobile & Responsive Design
**Agent:** `voltagent-core-dev:frontend-developer`
**Scope:** CSS, layout.php, all page PHP files
**Focus:**
- [ ] Framework7 Material: is it responsive?
- [ ] Table layouts on small screens (classement, rapports)
- [ ] Touch targets: are buttons large enough?
- [ ] Map view on mobile: usable?
- [ ] Form inputs on mobile: appropriate keyboard types?
- [ ] Navigation on mobile: is it accessible?
- [ ] Font sizes: readable on mobile?
- [ ] Horizontal scrolling issues

### Subagent 6.3: Performance & Loading
**Agent:** `voltagent-qa-sec:performance-engineer`
**Scope:** All PHP files, includes/database.php
**Focus:**
- [ ] N+1 query problems (any remaining after classement.php fix?)
- [ ] Slow queries: which pages generate the most queries?
- [ ] Page load time estimation per page
- [ ] Caching: any server-side caching? (initPlayer cache noted)
- [ ] Client-side caching headers (CSS, JS, images)
- [ ] Image optimization (are game sprites optimized?)
- [ ] Gzip/Brotli compression enabled on Apache?
- [ ] Database connection per-request: connection pooling potential
- [ ] Heavy computation pages (classement.php with SQRT ranking)
- [ ] Market page with 1000 history entries: pagination performance

### Subagent 6.4: Accessibility & i18n
**Agent:** `voltagent-qa-sec:accessibility-tester`
**Scope:** HTML output of all pages
**Focus:**
- [ ] ARIA labels on interactive elements
- [ ] Keyboard navigation support
- [ ] Screen reader compatibility
- [ ] Color contrast ratios
- [ ] Alt text on images (atom sprites, building icons)
- [ ] Language: game is French-only — i18n preparation?
- [ ] Character encoding: latin1 vs UTF-8 display issues
- [ ] RTL support (not needed for FR but relevant for expansion)
- [ ] Font loading performance and fallbacks

---

## DOMAIN 7: Database & Data Integrity Audit

**Coordinator Agent:** `voltagent-data-ai:database-optimizer`
**Goal:** Verify schema design, query performance, constraint coverage, data consistency, and migration correctness

### Subagent 7.1: Schema Design Review
**Agent:** `voltagent-data-ai:postgres-pro` (adapting SQL knowledge to MariaDB)
**Scope:** SQL dump, all 26 migrations
**Focus:**
- [ ] Table normalization: any denormalized data that causes update anomalies?
- [ ] Column types: appropriate INT vs BIGINT vs TINYINT?
- [ ] NULL vs NOT NULL: are NULLs used correctly?
- [ ] Default values: do all columns have sensible defaults?
- [ ] Foreign keys: migration 0018 adds FKs — complete coverage?
- [ ] CHECK constraints: migration 0017 — comprehensive?
- [ ] UNIQUE constraints: login, email, alliance tag
- [ ] Timestamp columns: using TIMESTAMP vs DATETIME vs INT?
- [ ] Auto-increment: any gaps or reuse issues?
- [ ] 28 tables: any that should be merged or split?

### Subagent 7.2: Index & Query Optimization
**Agent:** `voltagent-data-ai:database-optimizer`
**Scope:** All PHP files (every dbQuery/dbFetchOne/dbFetchAll/dbExecute call)
**Focus:**
- [ ] Extract every SQL query from codebase
- [ ] Check EXPLAIN plan for each query pattern
- [ ] Missing indexes on WHERE/JOIN/ORDER BY columns
- [ ] Over-indexing: unused indexes wasting write performance
- [ ] Covering indexes potential
- [ ] Full table scans in production queries
- [ ] Subquery vs JOIN optimization
- [ ] LIKE queries without index (login search in classement)
- [ ] COUNT(*) vs COUNT(1) vs EXISTS optimization
- [ ] Pagination: OFFSET vs cursor-based for large tables

### Subagent 7.3: Data Consistency & Transactions
**Agent:** `voltagent-data-ai:database-optimizer`
**Scope:** All withTransaction() calls, all multi-query flows
**Focus:**
- [ ] Which multi-step operations are NOT wrapped in transactions?
- [ ] SELECT...FOR UPDATE usage: correct for race prevention?
- [ ] Orphaned data: can rows exist in child tables without parent?
- [ ] Resource balance verification: can energy/atoms go negative?
- [ ] Player stats vs actual data: do cached values stay in sync?
- [ ] Alliance member count vs actual members
- [ ] Market price history: can it diverge from actual trades?
- [ ] Season reset: transactional completeness
- [ ] Concurrent modification: two tabs, same player, same action

---

## DOMAIN 8: Feature & Competitiveness Audit (BONUS DOMAIN)

**Coordinator Agent:** `voltagent-biz:product-manager`
**Goal:** Evaluate game against modern browser strategy games, propose features that increase retention, engagement, and competitive depth

### Subagent 8.1: Competitive Analysis
**Agent:** `voltagent-research:competitive-analyst`
**Focus:**
- [ ] Compare TVLW with: OGame, Travian, Tribal Wars, Hades' Star, Polytopia
- [ ] What features do competitors have that TVLW lacks?
- [ ] What makes TVLW unique? (chemistry theme, molecule system)
- [ ] Player retention mechanics in competitors
- [ ] Monetization models (free-to-play, cosmetics, subscription)
- [ ] Social features comparison (chat, guilds, events)
- [ ] Mobile app potential

### Subagent 8.2: Feature Gap Analysis
**Agent:** `voltagent-biz:product-manager`
**Focus:**
- [ ] Chat system (currently only forum + private messages)
- [ ] Push notifications (player removed PushNotification.js — why?)
- [ ] Achievement system beyond medals
- [ ] Daily login rewards
- [ ] Seasonal events / limited-time content
- [ ] Alliance wars: deeper mechanics (territory, objectives)
- [ ] Diplomacy: non-aggression pacts, trade routes
- [ ] Map: territory control, map objectives
- [ ] Research tree: deeper tech tree beyond current buildings
- [ ] Unit types: beyond 4 molecule classes
- [ ] PvE content: AI opponents, campaigns
- [ ] Tutorial: interactive guided tutorial vs current text-based
- [ ] Social features: in-game chat, alliance chat
- [ ] Spectator mode: watch battles
- [ ] Replay system: review past battles

### Subagent 8.3: Retention & Engagement Analysis
**Agent:** `voltagent-biz:product-manager`
**Focus:**
- [ ] New player experience (first 30 minutes)
- [ ] Day-1 retention drivers
- [ ] Day-7 retention mechanics
- [ ] End-of-season engagement (last week)
- [ ] Between-season engagement (what keeps players coming back?)
- [ ] Prestige system as retention hook: strong enough?
- [ ] Season length (31 days): too long? Too short?
- [ ] Notification system: how do players know "something happened"?
- [ ] Social pressure: alliance obligations, war participation
- [ ] Goal clarity: does the player know what to do next?

---

## Execution Plan

### Task 1: Launch Domain 1 — Security Audit

**Files:** All PHP files in the project

**Step 1: Dispatch 5 security subagents in parallel**

Launch all 5 subagents from Domain 1 using `superpowers:dispatching-parallel-agents`. Each subagent receives its specific checklist and file scope from this plan.

**Step 2: Collect Domain 1 findings**

Coordinator reviews all 5 subagent reports, deduplicates, and produces `docs/audit/domain-1-security.md`.

**Step 3: Commit Domain 1 findings**

```bash
git add docs/audit/domain-1-security.md
git commit -m "audit: Domain 1 — Security & Vulnerability findings"
```

---

### Task 2: Launch Domain 2 — Code Quality Audit

**Files:** All PHP files in the project

**Step 1: Dispatch 5 code quality subagents in parallel**

Launch all 5 subagents from Domain 2. Each receives its checklist.

**Step 2: Collect and merge findings into `docs/audit/domain-2-code-quality.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-2-code-quality.md
git commit -m "audit: Domain 2 — Code Quality & Architecture findings"
```

---

### Task 3: Launch Domain 3 — Technology & Infrastructure Audit

**Files:** composer.json, .htaccess, .github/, health.php, includes/logger.php, includes/env.php

**Step 1: Dispatch 5 technology subagents in parallel**

**Step 2: Collect into `docs/audit/domain-3-technology.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-3-technology.md
git commit -m "audit: Domain 3 — Technology & Infrastructure findings"
```

---

### Task 4: Launch Domain 4 — Game Balance Audit

**Files:** includes/config.php, includes/formulas.php, includes/combat.php, tests/balance/

**Step 1: Dispatch 5 balance subagents in parallel**

**Step 2: Collect into `docs/audit/domain-4-balance.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-4-balance.md
git commit -m "audit: Domain 4 — Game Balance & Strategy findings"
```

---

### Task 5: Launch Domain 5 — Game Mechanics Audit

**Files:** All includes/, all page PHP files

**Step 1: Dispatch 5 mechanics subagents in parallel**

**Step 2: Collect into `docs/audit/domain-5-mechanics.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-5-mechanics.md
git commit -m "audit: Domain 5 — Game Mechanics & Consistency findings"
```

---

### Task 6: Launch Domain 6 — UX & Performance Audit

**Files:** All PHP page files, CSS, JS, includes/layout.php, includes/ui_components.php

**Step 1: Dispatch 4 UX subagents in parallel**

**Step 2: Collect into `docs/audit/domain-6-ux-performance.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-6-ux-performance.md
git commit -m "audit: Domain 6 — UX, Frontend & Performance findings"
```

---

### Task 7: Launch Domain 7 — Database Audit

**Files:** SQL dump, migrations/, includes/database.php, all dbQuery/dbExecute calls

**Step 1: Dispatch 3 database subagents in parallel**

**Step 2: Collect into `docs/audit/domain-7-database.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-7-database.md
git commit -m "audit: Domain 7 — Database & Data Integrity findings"
```

---

### Task 8: Launch Domain 8 — Feature & Competitiveness Audit

**Step 1: Dispatch 3 feature subagents in parallel**

**Step 2: Collect into `docs/audit/domain-8-features.md`**

**Step 3: Commit**

```bash
git add docs/audit/domain-8-features.md
git commit -m "audit: Domain 8 — Feature & Competitiveness findings"
```

---

### Task 9: Cross-Domain Correlation & Synthesis

**Step 1: Read all 8 domain finding files**

**Step 2: Identify cross-domain patterns**
- Security issue that enables balance exploit
- Code quality issue that masks a bug
- Architecture weakness that blocks a feature
- Database issue that causes a game mechanic bug

**Step 3: Write master synthesis**

Produce `docs/audit/ultra-audit-master-synthesis.md` containing:
1. Executive summary (top 10 critical findings)
2. All findings unified with cross-references
3. Dependency graph between findings
4. Statistics (count by severity, domain, effort)

**Step 4: Commit**

```bash
git add docs/audit/ultra-audit-master-synthesis.md
git commit -m "audit: Master synthesis — cross-domain correlation"
```

---

### Task 10: Remediation Roadmap

**Step 1: Score all findings**

Priority = Severity × (1 + cross-domain-impact) / Effort

**Step 2: Group into implementation sprints**

- Sprint A: Critical security + critical bugs (must-fix)
- Sprint B: High-priority balance + mechanics (game quality)
- Sprint C: Code quality + architecture (maintainability)
- Sprint D: Technology upgrades (infrastructure)
- Sprint E: UX/performance improvements (player experience)
- Sprint F: New features (competitiveness)
- Sprint G: Low-priority polish (nice-to-have)

**Step 3: Write remediation plan**

Produce `docs/audit/ultra-audit-remediation-roadmap.md` containing:
1. Sprint-by-sprint task breakdown
2. Effort estimates per sprint
3. Dependencies between sprints
4. Risk assessment
5. Success metrics

**Step 4: Commit**

```bash
git add docs/audit/ultra-audit-remediation-roadmap.md
git commit -m "audit: Remediation roadmap — prioritized sprint plan"
```

---

## Agent Selection Matrix

| Domain | Coordinator Agent | Subagent Types |
|--------|-------------------|---------------|
| 1. Security | comprehensive-review:security-auditor | security-auditor, penetration-tester, code-reviewer, security-engineer |
| 2. Code Quality | comprehensive-review:code-reviewer | code-reviewer, php-pro, architect-review, error-detective, refactor-cleaner |
| 3. Technology | voltagent-infra:platform-engineer | php-pro, frontend-developer, devops-engineer, sre-engineer, database-administrator |
| 4. Balance | voltagent-data-ai:data-scientist | data-analyst (×3), data-scientist |
| 5. Mechanics | voltagent-qa-sec:debugger | debugger, code-reviewer, qa-expert, test-automator |
| 6. UX/Perf | voltagent-core-dev:ui-designer | ui-designer, frontend-developer, performance-engineer, accessibility-tester |
| 7. Database | voltagent-data-ai:database-optimizer | database-optimizer (×2), postgres-pro |
| 8. Features | voltagent-biz:product-manager | competitive-analyst, product-manager (×2) |

---

## Success Metrics

- **Coverage:** Every PHP file read by at least 2 subagents from different domains
- **Finding Count:** Expect 100-300 findings across all domains
- **Severity Distribution:** ~5% Critical, ~15% High, ~40% Medium, ~40% Low
- **Cross-References:** At least 20% of findings linked to findings in other domains
- **Actionability:** Every finding has a concrete fix proposal
- **Zero False Positives:** Only report confirmed issues (not speculation)

---

## Output Files

```
docs/audit/
├── domain-1-security.md
├── domain-2-code-quality.md
├── domain-3-technology.md
├── domain-4-balance.md
├── domain-5-mechanics.md
├── domain-6-ux-performance.md
├── domain-7-database.md
├── domain-8-features.md
├── ultra-audit-master-synthesis.md
└── ultra-audit-remediation-roadmap.md
```
