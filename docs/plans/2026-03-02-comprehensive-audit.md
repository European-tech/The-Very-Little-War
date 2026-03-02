# TVLW Comprehensive Audit & Remediation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Perform an exhaustive, multi-domain audit of every file, every logic path, and every gameplay system in The Very Little War, then remediate all findings.

**Architecture:** 15 specialized audit domains run by dedicated agents, each covering the full codebase from their domain perspective. Every PHP file is reviewed by a minimum of 4 different domain agents. Global cross-cutting agents then synthesize findings across domains. Remediation tasks follow in priority order.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 CSS, jQuery 3.1.1

---

## Audit Protocol: Guaranteed Full Coverage

### Coverage Matrix

Every file in the codebase is assigned to multiple audit domains. The matrix below ensures **no file escapes review** and **every file is reviewed by at least 4 domain agents**.

#### File-to-Domain Assignment Matrix

| File | SEC | CODE | ARCH | PERF | DB | GAME | INPUT | AUTH | SESS | UI | API | ERR | DATA | CONCUR | DEPLOY |
|------|-----|------|------|------|----|------|-------|------|------|----|-----|-----|------|--------|--------|
| **Root Pages (34)** |
| index.php | X | X | X | | | | X | X | X | X | | X | | | |
| inscription.php | X | X | | | X | | X | X | X | X | | X | X | | |
| deconnexion.php | X | X | | | | | | X | X | | | | | | |
| maintenance.php | X | X | | | | | | | | X | | | | | |
| version.php | X | X | | | | | | | | X | | | | | |
| regles.php | | X | | | | | | | | X | | | | | |
| credits.php | | X | | | | | | | | X | | | | | |
| tutoriel.php | | X | | | | X | | X | | X | | | | | |
| sinstruire.php | | X | | | | X | | X | | X | | | | | |
| video.php | | X | | | | | | | | X | | | | | |
| api.php | X | X | X | X | X | | X | X | | | X | X | | | |
| vacance.php | X | X | | | X | X | X | X | X | X | | X | X | | |
| compte.php | X | X | | | X | | X | X | X | X | | X | X | | |
| comptetest.php | X | X | | | | | X | X | | X | | | | | |
| editer.php | X | X | | | X | | X | X | X | X | | X | X | | |
| profil.php | X | X | | | X | | X | X | | X | | | X | | |
| joueur.php | X | X | | | X | X | X | X | | X | | | X | | |
| atomes.php | X | X | | X | X | X | X | X | | X | | X | X | | |
| marche.php | X | X | X | X | X | X | X | X | | X | | X | X | X | |
| constructions.php | X | X | | X | X | X | X | X | | X | | X | X | X | |
| armee.php | X | X | | | X | X | X | X | | X | | | X | | |
| molecule.php | X | X | | | X | X | X | X | | X | | | X | | |
| attaque.php | X | X | X | X | X | X | X | X | | X | | X | X | X | |
| attaquer.php | X | X | X | X | X | X | X | X | | X | | X | X | X | |
| validerpacte.php | X | X | | | X | X | X | X | | | | X | X | | |
| guerre.php | X | X | | | X | X | X | X | | X | | X | X | | |
| alliance.php | X | X | | | X | X | X | X | | X | | X | X | | |
| allianceadmin.php | X | X | | | X | X | X | X | | X | | X | X | | |
| classement.php | X | X | | X | X | X | | X | | X | | | X | | |
| rapports.php | X | X | | X | X | | X | X | | X | | | X | | |
| historique.php | X | X | | | X | | X | X | | X | | | X | | |
| connectes.php | X | X | | | X | | | X | | X | | | | | |
| annonce.php | X | X | | | X | | X | X | | X | | | | | |
| medailles.php | | X | | | X | X | | X | | X | | | X | | |
| don.php | X | X | | | X | X | X | X | | X | | X | X | | |
| **Forum/Messages (8)** |
| forum.php | X | X | | X | X | | X | X | | X | | X | | | |
| listesujets.php | X | X | | X | X | | X | X | | X | | X | | | |
| sujet.php | X | X | | X | X | | X | X | | X | | X | | | |
| ecriremessage.php | X | X | | | X | | X | X | | X | | X | | | |
| messages.php | X | X | | X | X | | X | X | | X | | X | | | |
| messagesenvoyes.php | X | X | | X | X | | X | X | | X | | X | | | |
| messageCommun.php | X | X | | | X | | X | X | | X | | X | | | |
| moderationForum.php | X | X | | | X | | X | X | | X | | X | | | |
| **Admin (9)** |
| admin/index.php | X | X | X | | X | | X | X | X | X | | X | | | |
| admin/tableau.php | X | X | | X | X | | | X | | X | | X | | | |
| admin/listenews.php | X | X | | | X | | X | X | | X | | X | | | |
| admin/redigernews.php | X | X | | | X | | X | X | | X | | X | | | |
| admin/listesujets.php | X | X | | | X | | X | X | | X | | X | | | |
| admin/supprimerreponse.php | X | X | | | X | | X | X | | | | X | | | |
| admin/ip.php | X | X | | | X | | X | X | | X | | X | | | |
| admin/redirectionmotdepasse.php | X | X | | | X | | X | X | | | | X | | | |
| admin/supprimercompte.php | X | X | | | X | | X | X | | | | X | X | | |
| **Moderation (3)** |
| moderation/index.php | X | X | | | X | | X | X | | X | | X | | | |
| moderation/ip.php | X | X | | | X | | X | X | | X | | X | | | |
| moderation/mdp.php | X | X | | | X | | X | X | | | | X | | | |
| **Core Includes (41)** |
| includes/connexion.php | X | X | X | X | X | | | | | | | X | | | X |
| includes/database.php | X | X | X | X | X | | | | | | | X | | X | |
| includes/db_helpers.php | X | X | | X | X | | | | | | | X | | | |
| includes/constantesBase.php | X | X | X | | | | | X | | | | | | | X |
| includes/config.php | | X | X | | | X | | | | | | | X | | |
| includes/constantes.php | | X | X | | | X | | | | | | | | | |
| includes/csrf.php | X | X | | | | | X | X | X | | | X | | | |
| includes/validation.php | X | X | | | | | X | | | | | X | | | |
| includes/rate_limiter.php | X | X | | X | | | | X | | | | X | | X | |
| includes/formulas.php | | X | X | X | | X | | | | | | | X | | |
| includes/game_resources.php | | X | X | X | X | X | | | | | | X | X | X |
| includes/game_actions.php | X | X | X | X | X | X | | | | | | X | X | X |
| includes/player.php | X | X | X | | X | X | X | | | | | X | X | | |
| includes/ui_components.php | X | X | | | | | | | | X | | | | | |
| includes/display.php | X | X | | | | | | | | X | | | | | |
| includes/combat.php | X | X | X | X | X | X | | | | | | X | X | X | |
| includes/prestige.php | | X | X | | X | X | | | | | | X | X | | |
| includes/catalyst.php | | X | X | | X | X | | | | | | X | X | X | |
| includes/atomes.php | | X | | | | X | | | | | | | X | | |
| includes/ressources.php | | X | | | | X | | | | | | | X | | |
| includes/statistiques.php | | X | | X | X | X | | | | | | | X | | |
| includes/update.php | X | X | X | X | X | X | | | | | | X | X | X | |
| includes/bbcode.php | X | X | | | | | X | | | X | | | | | |
| includes/mots.php | | X | | | | | | | | X | | | | | |
| includes/menus.php | X | X | | | | | | | | X | | | | | |
| includes/meta.php | X | X | | | | | | | | X | | | | | |
| includes/style.php | | X | | | | | | | | X | | | | | |
| includes/copyright.php | | X | | | | | | | | X | | | | | |
| includes/partenariat.php | | X | | | | | | | | X | | | | | |
| includes/basicpublicphp.php | X | X | X | | | | | X | X | | | X | | | |
| includes/basicprivatephp.php | X | X | X | X | X | | | X | X | | | X | | | |
| includes/basicpublichtml.php | X | X | | | | | | | | X | | | | | |
| includes/basicprivatehtml.php | X | X | | | | | | | | X | | | | | |
| includes/redirectionVacance.php | X | X | | | | X | | X | | | | | | | |
| includes/fonctions.php | | X | X | | | | | | | | | | | | |
| includes/tout.php | | X | X | | | | | | | | | | | | |
| includes/logger.php | X | X | | X | | | | | | | | X | | | X |
| includes/cardspublic.php | X | X | | | | | | | | X | | | | | |
| includes/cardsprivate.php | X | X | | | | | | | | X | | | | | |
| **Migrations (12)** |
| migrations/migrate.php | X | X | | | X | | | | | | | X | | | X |
| migrations/0001-0011.sql | | | | | X | | | | | | | | X | | X |
| **Tests (7)** |
| tests/bootstrap.php | | X | | | | | | | | | | | | | |
| tests/unit/*.php (6) | | X | | | | X | | | | | | | X | | |
| **Config** |
| .htaccess (root) | X | | | | | | | | | | | | | | X |
| includes/.htaccess | X | | | | | | | | | | | | | | X |
| docs/.htaccess | X | | | | | | | | | | | | | | X |
| composer.json | | | | | | | | | | | | | | | X |
| phpunit.xml | | X | | | | | | | | | | | | | |
| robots.txt | X | | | | | | | | | | | | | | X |
| **JS/CSS** |
| js/*.js (19 files) | X | X | | X | | | X | | | X | | | | | |
| css/*.css (16 files) | | | | X | | | | | | X | | | | | |

**Coverage Statistics:**
- Total unique PHP files: 104
- Average domains per PHP file: 6.8
- Minimum domains per PHP file: 4 (regles.php, credits.php, video.php)
- Maximum domains per PHP file: 12 (attaquer.php, marche.php, combat.php)
- Every file reviewed by CODE domain (100%)
- Security-sensitive files reviewed by SEC + AUTH + INPUT + SESS (4 security domains)

---

## Phase 1: Domain-Specific Audits (15 Specialized Agents)

Each task below is one complete domain audit executed by a dedicated agent. The agent receives the file list for its domain and produces a findings report.

---

### Task 1: SECURITY Audit (SEC)

**Agent:** `voltagent-qa-sec:security-auditor` + `comprehensive-review:security-auditor`

**Files to audit (68 files):**
- ALL root PHP pages (34)
- ALL admin pages (9)
- ALL moderation pages (3)
- ALL forum/message pages (8)
- Core: `includes/connexion.php`, `includes/database.php`, `includes/csrf.php`, `includes/validation.php`, `includes/rate_limiter.php`, `includes/basicpublicphp.php`, `includes/basicprivatephp.php`, `includes/constantesBase.php`, `includes/logger.php`, `includes/combat.php`, `includes/player.php`, `includes/game_actions.php`, `includes/update.php`, `includes/bbcode.php`, `includes/menus.php`, `includes/meta.php`, `includes/ui_components.php`, `includes/display.php`, `includes/cardspublic.php`, `includes/cardsprivate.php`, `includes/redirectionVacance.php`, `includes/basicpublichtml.php`, `includes/basicprivatehtml.php`
- Config: `.htaccess` (all 3), `robots.txt`
- JS: All 19 JS files (client-side crypto, XSS vectors)
- Migrations: `migrations/migrate.php`

**Audit Checklist:**
1. **SQL Injection**: Every `dbQuery`, `dbExecute`, `dbFetchOne`, `dbFetchAll` call - verify ALL parameters are parameterized. Check for any raw `$_GET`/`$_POST`/`$_SESSION` in SQL strings.
2. **XSS (Stored + Reflected)**: Every `echo`, `print`, `<?=` output - verify `htmlspecialchars()` or `antiXSS()` wrapping. Check BBCode parser for bypass vectors.
3. **CSRF**: Every form submission and state-changing GET request - verify `csrfField()` token present and validated via `validateCsrf()`.
4. **Authentication bypass**: Every page that includes `basicprivatephp.php` - verify it's at the top, no code runs before auth check. Check admin pages for admin-only guards.
5. **Authorization**: Check if player A can modify player B's data. Every query that uses `$_SESSION['login']` - verify it's the right player context.
6. **Session security**: Session configuration (httponly, secure, samesite, regeneration). Check for session fixation vectors.
7. **File inclusion**: Check for any `include`/`require` with user-controlled paths.
8. **Information disclosure**: Error messages exposing internals, debug output left in production, stack traces.
9. **Crypto**: Password hashing (bcrypt), MD5 migration, client-side crypto in JS files (aes.js, sha.js).
10. **Rate limiting**: Login, registration, API endpoints - verify rate_limiter coverage.
11. **HTTP headers**: X-Content-Type-Options, X-Frame-Options, CSP, HSTS readiness.
12. **Admin panel**: Password storage, brute-force protection, IP restrictions.

**Output format:**
```
FINDING-SEC-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Description: What's wrong
  Exploit scenario: How it could be exploited
  Remediation: Exact fix with code
```

**Step 1: Run security audit agent**
```
Agent: voltagent-qa-sec:security-auditor
Prompt: "Audit all 68 files listed for TVLW game at /home/guortates/TVLW/The-Very-Little-War/ using the 12-point security checklist. Read every file. Report findings in FINDING-SEC-NNN format."
```

**Step 2: Save findings**
Write to: `docs/audit/SEC-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/SEC-findings.md
git commit -m "audit: security domain findings"
```

---

### Task 2: CODE QUALITY Audit (CODE)

**Agent:** `voltagent-qa-sec:code-reviewer` + `comprehensive-review:code-reviewer`

**Files to audit: ALL 104 PHP files + 7 test files**

**Audit Checklist:**
1. **Dead code**: Unreachable branches, unused variables, unused functions, commented-out code blocks.
2. **Code duplication**: Copy-pasted logic across files (especially resource calculations, display functions).
3. **Naming conventions**: Inconsistent variable names (camelCase vs snake_case vs French naming).
4. **Function length**: Functions over 50 lines that should be broken down.
5. **Cyclomatic complexity**: Deeply nested if/else/for chains.
6. **Type safety**: Loose comparisons (`==` vs `===`), implicit type coercion, missing type hints.
7. **Error handling**: Unchecked return values, missing try/catch, silent failures.
8. **PHP 8.2 compatibility**: Deprecated features, dynamic properties, implicit nullable types.
9. **Magic numbers**: Hardcoded values that should be constants (check against config.php).
10. **Global state pollution**: Excessive use of `$GLOBALS`, global variables, session abuse.
11. **Include/require consistency**: Mixed include vs require, missing _once variants.
12. **Code comments**: Missing comments on complex logic, outdated comments, TODO items.

**Output format:**
```
FINDING-CODE-001: [HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Description: What's wrong
  Remediation: Exact fix
```

**Step 1: Run code quality agent**
```
Agent: voltagent-qa-sec:code-reviewer
Prompt: "Review all 104 PHP files in TVLW at /home/guortates/TVLW/The-Very-Little-War/ for code quality using 12-point checklist. Read every file. Report as FINDING-CODE-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/CODE-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/CODE-findings.md
git commit -m "audit: code quality domain findings"
```

---

### Task 3: ARCHITECTURE Audit (ARCH)

**Agent:** `comprehensive-review:architect-review` + `voltagent-qa-sec:architect-reviewer`

**Files to audit (24 files):**
- `includes/fonctions.php`, `includes/tout.php`, `includes/connexion.php`, `includes/database.php`, `includes/config.php`, `includes/constantes.php`, `includes/constantesBase.php`
- `includes/basicpublicphp.php`, `includes/basicprivatephp.php`
- `includes/formulas.php`, `includes/game_resources.php`, `includes/game_actions.php`, `includes/player.php`, `includes/combat.php`, `includes/update.php`, `includes/prestige.php`, `includes/catalyst.php`
- `api.php`, `index.php`, `attaquer.php`, `marche.php`, `constructions.php`, `attaque.php`
- `admin/index.php`

**Audit Checklist:**
1. **Separation of concerns**: Business logic mixed with presentation? SQL in page files?
2. **Module coupling**: How tightly are modules coupled? Can combat.php work without game_actions.php?
3. **Dependency graph**: Map include chains. Circular dependencies?
4. **Configuration management**: Constants scattered vs centralized? Config drift between constantesBase, constantes, config.php?
5. **State management**: Global variables, session bloat, shared mutable state.
6. **Scalability bottlenecks**: Single-server assumptions, file locks, blocking operations.
7. **Testability**: Can functions be unit-tested without DB? Are dependencies injectable?
8. **Request lifecycle**: What happens on each page load? How many DB queries per page?
9. **Error recovery**: What happens when DB is down? When a query fails mid-transaction?
10. **Extension points**: How easy is it to add a new building? A new atom type? A new page?

**Output format:**
```
FINDING-ARCH-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  Files: list of affected files
  Description: Architectural concern
  Impact: What this causes
  Remediation: Recommended refactor
```

**Step 1: Run architecture audit agent**
```
Agent: comprehensive-review:architect-review
Prompt: "Audit architecture of TVLW game at /home/guortates/TVLW/The-Very-Little-War/ - 24 core files. Check 10-point architectural checklist. Map include graphs, coupling, state. Report as FINDING-ARCH-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/ARCH-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/ARCH-findings.md
git commit -m "audit: architecture domain findings"
```

---

### Task 4: PERFORMANCE Audit (PERF)

**Agent:** `voltagent-qa-sec:performance-engineer`

**Files to audit (28 files):**
- `includes/connexion.php`, `includes/database.php`, `includes/db_helpers.php`
- `includes/basicprivatephp.php` (auth + data loading per request)
- `includes/game_resources.php`, `includes/game_actions.php`, `includes/update.php`
- `includes/combat.php`, `includes/formulas.php`, `includes/statistiques.php`
- `includes/rate_limiter.php`, `includes/logger.php`
- `marche.php`, `constructions.php`, `attaquer.php`, `attaque.php`, `classement.php`, `atomes.php`
- `forum.php`, `listesujets.php`, `sujet.php`, `messages.php`, `messagesenvoyes.php`, `rapports.php`
- `admin/tableau.php`
- JS: All 19 JS files (bundle size, load times)
- CSS: All 16 CSS files (render-blocking, unused styles)

**Audit Checklist:**
1. **N+1 queries**: Loops that execute DB queries per iteration.
2. **Missing indexes**: Queries that do full table scans (cross-ref with DB schema).
3. **Unbounded queries**: `SELECT *` without LIMIT, missing pagination.
4. **Expensive calculations on page load**: Formulas recalculated on every request instead of cached.
5. **Asset loading**: Multiple JS/CSS files loaded synchronously, no minification, no CDN.
6. **Session data volume**: How much data is loaded into session per page load?
7. **Query count per page**: Estimate total queries for each page type (auth, data, display).
8. **Memory usage**: Large result sets loaded entirely into PHP memory.
9. **Time-based operations**: `updateActions()` processing all pending actions synchronously.
10. **Concurrency under load**: What happens with 50+ simultaneous users?

**Output format:**
```
FINDING-PERF-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Query/Operation: The slow operation
  Estimated impact: How much it slows things down
  Remediation: Optimization with code
```

**Step 1: Run performance audit agent**
```
Agent: voltagent-qa-sec:performance-engineer
Prompt: "Audit performance of TVLW game at /home/guortates/TVLW/The-Very-Little-War/ - 28 PHP files + JS/CSS assets. Check 10-point performance checklist. Count queries per page, find N+1 loops, check indexes. Report as FINDING-PERF-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/PERF-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/PERF-findings.md
git commit -m "audit: performance domain findings"
```

---

### Task 5: DATABASE Audit (DB)

**Agent:** `voltagent-data-ai:database-optimizer` + `everything-claude-code:database-reviewer`

**Files to audit:**
- SQL dump: `/home/guortates/TVLW/theveryl_theverylittlewar (1).sql`
- All 11 migration SQL files in `migrations/`
- `includes/connexion.php`, `includes/database.php`, `includes/db_helpers.php`
- Every PHP file that calls `dbQuery`, `dbExecute`, `dbFetchOne`, `dbFetchAll`, `dbCount` (grep ALL files)

**Audit Checklist:**
1. **Schema integrity**: Missing PKs (connectes, statutforum), missing FKs (all implicit), orphan data risk.
2. **Index coverage**: Every WHERE clause, JOIN condition, ORDER BY - verify index exists.
3. **Engine consistency**: MyISAM tables (declarations, moderation, statutforum) should be InnoDB.
4. **Character set**: Mixed latin1/utf8/utf8mb4 - identify encoding bugs (French characters).
5. **Data types**: VARCHAR(255) overuse, TEXT for structured data (pointsProducteur, timeMolecule), DOUBLE for integer-like values.
6. **Normalization**: Denormalized fields (totalPoints, pointstotaux), CSV-in-columns (pointsProducteur, pointsCondenseur).
7. **Query patterns**: Every unique query pattern in the codebase - is it optimal?
8. **Transaction safety**: Operations that modify multiple tables without transactions.
9. **Migration safety**: Do migrations handle rollback? Are they idempotent?
10. **Data lifecycle**: Old data cleanup (expired cooldowns, old messages, old reports).

**Output format:**
```
FINDING-DB-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  Table(s): affected tables
  File: PHP file where query originates
  Description: What's wrong
  Remediation: SQL + PHP fix
```

**Step 1: Run database audit agent**
```
Agent: voltagent-data-ai:database-optimizer
Prompt: "Audit database of TVLW at /home/guortates/TVLW/The-Very-Little-War/. Read SQL dump, all migrations, all PHP files with DB calls. Check 10-point DB checklist. Report as FINDING-DB-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/DB-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/DB-findings.md
git commit -m "audit: database domain findings"
```

---

### Task 6: GAME LOGIC Audit (GAME)

**Agent:** `voltagent-qa-sec:debugger` + custom game logic review

**Files to audit (32 files):**
- `includes/combat.php` (602 lines - combat resolution)
- `includes/formulas.php` (all game formulas)
- `includes/game_resources.php` (resource production, decay, storage)
- `includes/game_actions.php` (action queue processing)
- `includes/player.php` (registration, season reset, player management)
- `includes/config.php` (all balance constants)
- `includes/prestige.php` (cross-season progression)
- `includes/catalyst.php` (weekly catalyst rotation)
- `includes/atomes.php`, `includes/ressources.php` (atom/resource definitions)
- `includes/statistiques.php` (ranking calculations)
- `includes/update.php` (production updates)
- `includes/redirectionVacance.php` (vacation mode)
- Root pages: `attaquer.php`, `attaque.php`, `constructions.php`, `marche.php`, `molecule.php`, `armee.php`, `atomes.php`, `alliance.php`, `allianceadmin.php`, `guerre.php`, `validerpacte.php`, `classement.php`, `medailles.php`, `don.php`, `vacance.php`, `tutoriel.php`, `sinstruire.php`, `joueur.php`

**Audit Checklist - Gameplay Paths:**
1. **Combat resolution**: Trace full attaque.php → attaquer.php → combat.php flow. Verify damage calc, casualty distribution, pillage, building damage, points, cooldowns. Check edge cases: 0 molecules, all molecules dead, identical stats, maximum possible damage.
2. **Resource production**: Trace updateActions() → resource calculation. Verify energy balance (production - drain), atom production, storage caps, overflow handling. Edge cases: negative energy, zero producteur, max storage.
3. **Molecule lifecycle**: Formation → decay → combat death → replacement. Verify decay formula across isotope types, formation time with all bonuses, molecule count never goes negative.
4. **Building progression**: Construction start → completion → level-up → combat damage → level-down. Verify costs at each level, time formulas, HP calculations, minimum level floors.
5. **Market trading**: Price calculation → buy/sell → transport → delivery. Verify price volatility, floor/ceiling, mean reversion, distance calculations, energy costs.
6. **Alliance system**: Creation → invite → accept → research upgrades → war → pact. Verify duplicateur bonus propagation, research cost scaling, member limits.
7. **Season reset**: Verify remiseAZero() clears everything correctly. Check prestige point calculation, cross-season unlock preservation.
8. **Catalyst rotation**: Weekly rotation → bonus application → expiry. Verify all 6 catalyst types apply correctly, no stacking exploits.
9. **Specialization locks**: Verify unlocks at correct building levels, bonuses apply correctly, can't change after choosing.
10. **Medal/prestige**: Tier progression → bonus application. Verify bonus stacking with other multipliers, no double-counting.
11. **Beginner protection**: 5-day protection + 3-day boost. Verify attack blocking, boost expiry, edge cases at boundary.
12. **Espionage**: Neutrino cost → travel → success check → report. Verify neutrino mechanics, success ratio, report accuracy.
13. **Vacation mode**: Enable → protection → disable. Verify no production, no attacks, no actions during vacation.
14. **Tutorial missions**: Mission progression → reward delivery. Verify all missions completable, rewards correct.
15. **Chemical reactions**: Atom thresholds → combat bonus activation. Verify all 5 reactions with exact thresholds, bonus values, edge cases at boundary.

**Output format:**
```
FINDING-GAME-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  Gameplay path: [combat|resources|molecules|buildings|market|alliance|season|catalyst|specialization|medals|beginner|espionage|vacation|tutorial|reactions]
  File: exact/path.php:line_number
  Description: Logic bug or balance issue
  Reproduction: Steps to trigger
  Remediation: Fix with code
```

**Step 1: Run game logic audit agent**
```
Agent: voltagent-qa-sec:debugger
Prompt: "Audit all game logic in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Read all 32 game logic files. Trace every gameplay path (15 paths listed). Find logic bugs, edge cases, exploits. Report as FINDING-GAME-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/GAME-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/GAME-findings.md
git commit -m "audit: game logic domain findings"
```

---

### Task 7: INPUT VALIDATION Audit (INPUT)

**Agent:** `voltagent-qa-sec:penetration-tester`

**Files to audit (52 files):**
- Every PHP file that reads `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, or `$_SERVER`
- `includes/validation.php` (the validation module itself)
- `includes/csrf.php` (token validation)
- `includes/bbcode.php` (user content formatting)
- All form-handling pages: `inscription.php`, `editer.php`, `compte.php`, `ecriremessage.php`, `attaquer.php`, `constructions.php`, `marche.php`, `allianceadmin.php`, `don.php`, `validerpacte.php`, `admin/redigernews.php`, `admin/supprimercompte.php`

**Audit Checklist:**
1. **Every `$_GET` parameter**: Trace from input to usage. Is it validated? Type-cast? Sanitized before output? Parameterized in SQL?
2. **Every `$_POST` parameter**: Same as GET. Plus CSRF token verification.
3. **Numeric inputs**: `intval()` or `(int)` cast before arithmetic? Range validation?
4. **String inputs**: Length limits? Character whitelist? HTML encoding for output?
5. **Array inputs**: Can attackers pass arrays where strings expected (PHP type juggling)?
6. **File uploads**: Any file upload handlers? MIME validation?
7. **HTTP headers**: `$_SERVER['HTTP_*']` used in output or logic?
8. **Cookie manipulation**: Can modifying cookies bypass auth or alter game state?
9. **URL parameters in redirects**: Open redirect vulnerabilities?
10. **BBCode parser**: Can crafted BBCode inject HTML/JS? Nested tag exploits?

**Output format:**
```
FINDING-INPUT-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Input source: $_GET['param'] / $_POST['param'] / etc.
  Current validation: What's currently done (or nothing)
  Attack vector: How to exploit
  Remediation: Exact validation code
```

**Step 1: Run input validation audit agent**
```
Agent: voltagent-qa-sec:penetration-tester
Prompt: "Audit all user inputs in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Grep every $_GET, $_POST, $_REQUEST, $_COOKIE, $_SERVER usage. Trace each input to its usage. Check validation, sanitization, parameterization. Report as FINDING-INPUT-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/INPUT-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/INPUT-findings.md
git commit -m "audit: input validation domain findings"
```

---

### Task 8: AUTHENTICATION & AUTHORIZATION Audit (AUTH)

**Agent:** `voltagent-infra:security-engineer`

**Files to audit (55+ files):**
- `includes/basicpublicphp.php` (public auth flow)
- `includes/basicprivatephp.php` (private auth guard)
- `includes/constantesBase.php` (admin password hash)
- `includes/rate_limiter.php` (brute force protection)
- `inscription.php` (registration)
- `deconnexion.php` (logout)
- ALL private pages (verify auth guard present)
- ALL admin pages (verify admin-only access)
- ALL moderation pages (verify moderator access)
- `vacance.php`, `compte.php`, `editer.php` (account modification)
- `validerpacte.php`, `allianceadmin.php` (privileged game actions)

**Audit Checklist:**
1. **Auth guard presence**: Every private page includes `basicprivatephp.php` BEFORE any code. No exceptions.
2. **Admin guard presence**: Every admin page checks `$_SESSION['motdepasseadmin']`. No exceptions.
3. **Moderator guard**: Moderation pages check `moderateur` flag. No exceptions.
4. **Session management**: Regeneration on login, destruction on logout, timeout.
5. **Password security**: bcrypt hashing, MD5 auto-migration, password strength requirements.
6. **Registration validation**: Username uniqueness, email validation, IP-based rate limiting.
7. **Horizontal privilege escalation**: Can player A access player B's resources/actions by manipulating parameters?
8. **Vertical privilege escalation**: Can a regular player access admin/moderator functions?
9. **Alliance authorization**: Can non-member modify alliance settings? Can non-chief perform chief actions?
10. **Logout completeness**: Does logout destroy all session data? Clear cookies? Prevent back-button access?

**Output format:**
```
FINDING-AUTH-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Auth context: [public|private|admin|moderator|alliance-chief]
  Description: What's wrong
  Exploit: How to bypass
  Remediation: Fix with code
```

**Step 1: Run auth audit agent**
```
Agent: voltagent-infra:security-engineer
Prompt: "Audit authentication and authorization in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Verify every private page has auth guard, every admin page has admin guard. Check for privilege escalation. Report as FINDING-AUTH-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/AUTH-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/AUTH-findings.md
git commit -m "audit: authentication domain findings"
```

---

### Task 9: SESSION & STATE MANAGEMENT Audit (SESS)

**Agent:** `voltagent-lang:php-pro`

**Files to audit (15 files):**
- `includes/basicpublicphp.php`, `includes/basicprivatephp.php`
- `includes/csrf.php`
- `index.php`, `inscription.php`, `deconnexion.php`
- `vacance.php`, `compte.php`, `editer.php`
- `admin/index.php`
- `includes/game_actions.php` (uses session data for action resolution)
- `attaquer.php`, `constructions.php`, `marche.php` (session-dependent operations)

**Audit Checklist:**
1. **Session configuration**: `session.cookie_httponly`, `session.cookie_secure` (pending HTTPS), `session.use_strict_mode`, `session.cookie_samesite`.
2. **Session data integrity**: What's stored in `$_SESSION`? Can it be tampered with if session ID is stolen?
3. **Session fixation**: Is `session_regenerate_id(true)` called on login? On privilege changes?
4. **Session lifetime**: Is there a timeout? Does it expire on inactivity?
5. **CSRF token lifecycle**: Generated when? Validated when? Single-use or per-session? Can it be predicted?
6. **Race conditions**: Two tabs open simultaneously - does session state conflict?
7. **Session storage**: Default file handler - safe for concurrent access? Locking issues?
8. **Logout cleanup**: `session_destroy()` + `session_unset()` + cookie removal?
9. **Global state**: Variables loaded into `$GLOBALS` from session - any mutation bugs?
10. **Session size**: How much data per session? Any unbounded growth?

**Output format:**
```
FINDING-SESS-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Description: Session/state issue
  Remediation: Fix
```

**Step 1: Run session audit agent**
```
Agent: voltagent-lang:php-pro
Prompt: "Audit session and state management in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Read 15 session-related files. Check 10-point session checklist. Report as FINDING-SESS-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/SESS-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/SESS-findings.md
git commit -m "audit: session management domain findings"
```

---

### Task 10: UI/UX & FRONTEND Audit (UI)

**Agent:** `voltagent-core-dev:ui-designer` + `ui-ux-pro-max:ui-ux-pro-max`

**Files to audit:**
- ALL root PHP pages with HTML output (34)
- ALL admin pages (9)
- ALL moderation pages (3)
- ALL forum pages (8)
- `includes/basicpublichtml.php`, `includes/basicprivatehtml.php`
- `includes/ui_components.php`, `includes/display.php`
- `includes/menus.php`, `includes/meta.php`, `includes/style.php`
- `includes/cardspublic.php`, `includes/cardsprivate.php`
- `includes/bbcode.php`, `includes/mots.php`
- ALL 16 CSS files
- ALL 19 JS files

**Audit Checklist:**
1. **HTML validity**: Unclosed tags, deprecated elements, malformed attributes.
2. **Accessibility (a11y)**: Missing alt text, form labels, ARIA attributes, keyboard navigation.
3. **Responsive design**: Framework7 mobile-first - does it work on desktop too? Breakpoints?
4. **XSS in HTML context**: User-generated content rendered without escaping (overlaps SEC but UI-focused).
5. **JavaScript errors**: Console errors, undefined variables, jQuery deprecated methods.
6. **CSS conflicts**: Framework7 vs custom styles, specificity wars, !important abuse.
7. **Asset loading**: Render-blocking JS/CSS, unused assets loaded, no lazy loading.
8. **User feedback**: Error messages for failed actions, loading indicators, success confirmations.
9. **Navigation flow**: Can users get stuck? Dead-end pages? Missing back buttons?
10. **Internationalization**: French hardcoded strings - are they consistently encoded (UTF-8)?
11. **Mobile usability**: Touch targets, font sizes, scroll behavior on Framework7.
12. **Outdated libraries**: jQuery 1.7.2 loaded alongside 3.1.1, Framework7 version.

**Output format:**
```
FINDING-UI-001: [HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number (or .css/.js)
  Description: UI/UX issue
  User impact: How it affects gameplay
  Remediation: Fix with code
```

**Step 1: Run UI/UX audit agent**
```
Agent: voltagent-core-dev:ui-designer
Prompt: "Audit UI/UX of TVLW game at /home/guortates/TVLW/The-Very-Little-War/. Review all HTML output, CSS, JS files. Check 12-point UI checklist. Report as FINDING-UI-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/UI-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/UI-findings.md
git commit -m "audit: UI/UX domain findings"
```

---

### Task 11: API & EXTERNAL INTERFACE Audit (API)

**Agent:** `voltagent-core-dev:api-designer`

**Files to audit (3 files):**
- `api.php` (main API endpoint)
- `includes/basicpublicphp.php` (API auth if applicable)
- `includes/basicprivatephp.php` (API session handling)

**Audit Checklist:**
1. **Endpoint inventory**: What endpoints does api.php expose? GET/POST methods?
2. **Authentication**: How are API requests authenticated? Session? Token?
3. **Rate limiting**: Is the API rate-limited? Per-user or per-IP?
4. **Input validation**: All API parameters validated?
5. **Response format**: Consistent JSON structure? Error codes?
6. **CORS**: Cross-origin headers present? Overly permissive?
7. **Error handling**: API errors expose internal details?
8. **Versioning**: Any API versioning? Breaking changes?

**Output format:**
```
FINDING-API-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Endpoint: GET/POST /api.php?action=xxx
  Description: What's wrong
  Remediation: Fix
```

**Step 1: Run API audit agent**
```
Agent: voltagent-core-dev:api-designer
Prompt: "Audit API layer of TVLW at /home/guortates/TVLW/The-Very-Little-War/api.php. Check 8-point API checklist. Report as FINDING-API-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/API-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/API-findings.md
git commit -m "audit: API domain findings"
```

---

### Task 12: ERROR HANDLING & LOGGING Audit (ERR)

**Agent:** `voltagent-qa-sec:error-detective`

**Files to audit (ALL PHP files + logger):**
- `includes/logger.php` (logging infrastructure)
- Every PHP file - check error handling patterns
- `includes/database.php` (DB error handling)
- `includes/game_actions.php` (action processing errors)
- `includes/combat.php` (combat error paths)
- `includes/player.php` (registration/player errors)

**Audit Checklist:**
1. **Error suppression**: Any `@` operator hiding errors?
2. **Try/catch coverage**: DB operations, file operations, external calls - are exceptions caught?
3. **Error logging**: Are errors logged via logger.php? Or do they silently fail?
4. **User-facing errors**: Do users see PHP warnings/notices? Stack traces?
5. **Log format**: Consistent format? Timestamps? Context (user, action)?
6. **Log rotation**: Do logs grow unbounded? Any rotation mechanism?
7. **Error recovery**: After a DB error, does the page continue or gracefully degrade?
8. **Production config**: `display_errors = Off`? `error_reporting` level?
9. **Missing error checks**: `dbQuery()` return value checked? `dbExecute()` success verified?
10. **Edge case handling**: Division by zero, empty arrays, null dereferences.

**Output format:**
```
FINDING-ERR-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Error path: What operation can fail
  Current handling: What happens now (nothing/crash/silent)
  Remediation: Proper error handling code
```

**Step 1: Run error handling audit agent**
```
Agent: voltagent-qa-sec:error-detective
Prompt: "Audit error handling in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Check every PHP file for error suppression, missing try/catch, unchecked returns. Check logger.php coverage. Report as FINDING-ERR-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/ERR-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/ERR-findings.md
git commit -m "audit: error handling domain findings"
```

---

### Task 13: DATA INTEGRITY & CONSISTENCY Audit (DATA)

**Agent:** `voltagent-data-ai:data-engineer`

**Files to audit (25 files):**
- `includes/player.php` (remiseAZero, supprimerJoueur, inscrire)
- `includes/game_actions.php` (updateActions - multi-table modifications)
- `includes/game_resources.php` (resource calculations)
- `includes/combat.php` (combat modifies resources, buildings, molecules across 2 players)
- `includes/prestige.php` (cross-season data)
- `includes/config.php` (constants consistency)
- `includes/formulas.php` (formula consistency)
- `marche.php` (market transactions)
- `don.php` (energy donations)
- `alliance.php`, `allianceadmin.php` (alliance data)
- `constructions.php` (building modifications)
- `attaquer.php` (attack initiation)
- `validerpacte.php` (pact acceptance)
- `admin/supprimercompte.php` (account deletion cleanup)
- Tests: All 6 unit test files

**Audit Checklist:**
1. **Transaction boundaries**: Multi-table operations without BEGIN/COMMIT (combat updates attacker + defender + reports).
2. **Orphan data**: Player deletion - are ALL related records cleaned up? (Check: actionsattaques, actionsformation, actionsconstruction, actionsenvoi, molecules, constructions, ressources, autre, grades, invitations, messages, rapports, connectes, prestige, attack_cooldowns, statutforum, declarations).
3. **Numeric precision**: DOUBLE for resource counts - floating point errors? Resources going slightly negative?
4. **Race conditions**: Two simultaneous attacks on same player - double-deduction of resources?
5. **Consistency invariants**:
   - Resources never negative
   - Building levels never below 0 (or 1 for some)
   - Molecule counts never negative
   - Storage never exceeded (or gracefully handled)
   - Points calculations match actual data
6. **Season reset completeness**: Does remiseAZero() miss any table?
7. **Config vs code**: Are constants in config.php actually used where they should be? Any hardcoded values that contradict config?
8. **Formula consistency**: Same formula used identically in formulas.php and combat.php? Display vs actual calculation match?
9. **Test coverage gaps**: What's tested vs what's not? Critical paths without tests?
10. **Data migration integrity**: Do migrations handle existing data correctly? Any data loss risk?

**Output format:**
```
FINDING-DATA-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  Files: affected files
  Data path: What data flow is affected
  Invariant violated: What should always be true but isn't
  Remediation: Transaction/check/fix
```

**Step 1: Run data integrity audit agent**
```
Agent: voltagent-data-ai:data-engineer
Prompt: "Audit data integrity in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Check 10-point data integrity checklist. Trace multi-table operations, find missing transactions, verify invariants. Report as FINDING-DATA-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/DATA-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/DATA-findings.md
git commit -m "audit: data integrity domain findings"
```

---

### Task 14: CONCURRENCY & RACE CONDITIONS Audit (CONCUR)

**Agent:** `voltagent-lang:php-pro`

**Files to audit (12 files):**
- `includes/game_actions.php` (updateActions - recursive, processes all pending)
- `includes/combat.php` (modifies both attacker + defender simultaneously)
- `includes/game_resources.php` (resource modifications)
- `includes/update.php` (production updates)
- `includes/database.php` (no transaction helpers?)
- `marche.php` (market price + inventory modifications)
- `constructions.php` (building queue)
- `attaquer.php` (attack queue)
- `includes/catalyst.php` (weekly rotation - race on first access)
- `includes/rate_limiter.php` (counter increments)
- `don.php` (energy transfer between players)
- `includes/basicprivatephp.php` (session locking during page load)

**Audit Checklist:**
1. **Double-spending**: Can a player spend the same resources twice via concurrent requests? (Two browser tabs, rapid clicking)
2. **Combat race**: Two attacks resolve against same defender simultaneously - double resource deduction?
3. **Market race**: Two players buy last item simultaneously - oversold?
4. **Construction race**: Start two buildings simultaneously from different tabs?
5. **Formation race**: Start formation while previous one hasn't completed processing?
6. **Session locking**: PHP sessions lock file during request - does this serialize requests? What if session is stored differently?
7. **DB-level atomicity**: SELECT then UPDATE without FOR UPDATE - TOCTOU vulnerabilities?
8. **Catalyst rotation**: First player of the week triggers rotation - what if two players load simultaneously?
9. **Alliance donation**: Two members donate simultaneously - final total correct?
10. **Action queue processing**: updateActions() is recursive - what if called simultaneously for attacker and defender?

**Output format:**
```
FINDING-CONCUR-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path.php:line_number
  Race scenario: Step-by-step concurrent execution
  Impact: What goes wrong
  Remediation: Locking/transaction/atomic operation
```

**Step 1: Run concurrency audit agent**
```
Agent: voltagent-lang:php-pro
Prompt: "Audit concurrency in TVLW at /home/guortates/TVLW/The-Very-Little-War/. Read 12 concurrency-critical files. Check 10-point race condition checklist. Identify TOCTOU, double-spend, lost-update patterns. Report as FINDING-CONCUR-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/CONCUR-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/CONCUR-findings.md
git commit -m "audit: concurrency domain findings"
```

---

### Task 15: DEPLOYMENT & INFRASTRUCTURE Audit (DEPLOY)

**Agent:** `voltagent-infra:devops-engineer`

**Files to audit:**
- `.htaccess` (all 3)
- `robots.txt`
- `composer.json`
- `phpunit.xml`
- `includes/connexion.php` (DB credentials)
- `includes/constantesBase.php` (admin password)
- `includes/logger.php` (log paths)
- `migrations/migrate.php`
- All migration SQL files

**Audit Checklist:**
1. **Credential security**: DB passwords in source code? Admin hash exposed? `.env` file usage?
2. **HTTPS readiness**: Cookie secure flag, HSTS header, mixed content.
3. **Apache configuration**: .htaccess rules complete? Directory listing disabled? PHP version exposed?
4. **Error display**: `display_errors` in production? `error_reporting` level?
5. **File permissions**: Writable directories? Log file permissions? Upload directory?
6. **Backup strategy**: Database backups? Code backups? Recovery plan?
7. **Monitoring**: Health checks? Uptime monitoring? Error alerting?
8. **Dependency management**: Composer dependencies up-to-date? Known vulnerabilities?
9. **Deployment process**: Manual FTP? Git-based? CI/CD pipeline?
10. **PHP configuration**: `session.save_path`, `upload_max_filesize`, `max_execution_time`, `memory_limit`.

**Output format:**
```
FINDING-DEPLOY-001: [CRITICAL|HIGH|MEDIUM|LOW] - Title
  File: exact/path or server config
  Description: Infrastructure issue
  Remediation: Config change or process fix
```

**Step 1: Run deployment audit agent**
```
Agent: voltagent-infra:devops-engineer
Prompt: "Audit deployment of TVLW at /home/guortates/TVLW/The-Very-Little-War/. Check .htaccess, credentials, PHP config, HTTPS readiness, backup strategy. VPS at 212.227.38.111 running Debian 12 + PHP 8.2 + Apache 2 + MariaDB 10.11. Report as FINDING-DEPLOY-NNN."
```

**Step 2: Save findings**
Write to: `docs/audit/DEPLOY-findings.md`

**Step 3: Commit**
```bash
git add docs/audit/DEPLOY-findings.md
git commit -m "audit: deployment domain findings"
```

---

## Phase 2: Global Cross-Domain Agents (5 Synthesis Tasks)

These agents run AFTER Phase 1 completes. They read ALL domain findings and synthesize cross-cutting concerns.

---

### Task 16: Cross-Domain Security Synthesis

**Agent:** `comprehensive-review:security-auditor`

**Input:** ALL findings from SEC, AUTH, INPUT, SESS, DEPLOY, CONCUR domains

**Goal:** Find security issues that span multiple domains. Example: an input validation gap (INPUT) that leads to SQL injection (SEC) exploitable via session manipulation (SESS) because there's no rate limiting (AUTH) and no logging (ERR).

**Step 1: Read all security-relevant findings**
Read: `docs/audit/SEC-findings.md`, `docs/audit/AUTH-findings.md`, `docs/audit/INPUT-findings.md`, `docs/audit/SESS-findings.md`, `docs/audit/DEPLOY-findings.md`, `docs/audit/CONCUR-findings.md`

**Step 2: Identify attack chains**
Map multi-step attack scenarios that combine findings across domains.

**Step 3: Save synthesis**
Write to: `docs/audit/SYNTHESIS-security.md`

**Step 4: Commit**
```bash
git add docs/audit/SYNTHESIS-security.md
git commit -m "audit: cross-domain security synthesis"
```

---

### Task 17: Cross-Domain Data Flow Synthesis

**Agent:** `voltagent-data-ai:data-engineer`

**Input:** ALL findings from DB, DATA, GAME, CONCUR domains

**Goal:** Map every data flow end-to-end. User action → input validation → business logic → DB write → DB read → display. Find gaps where data is corrupted, lost, or inconsistent across the pipeline.

**Step 1: Read all data-relevant findings**
Read: `docs/audit/DB-findings.md`, `docs/audit/DATA-findings.md`, `docs/audit/GAME-findings.md`, `docs/audit/CONCUR-findings.md`

**Step 2: Map complete data flows**
For each major operation (attack, build, trade, form molecules, etc.), trace data from user click to final display.

**Step 3: Save synthesis**
Write to: `docs/audit/SYNTHESIS-data-flow.md`

**Step 4: Commit**
```bash
git add docs/audit/SYNTHESIS-data-flow.md
git commit -m "audit: cross-domain data flow synthesis"
```

---

### Task 18: Cross-Domain Game Balance Synthesis

**Agent:** `voltagent-qa-sec:debugger` (game logic focus)

**Input:** ALL findings from GAME, DATA, PERF domains + `includes/config.php`

**Goal:** Synthesize all game balance issues. Map the complete economy: resource generation → spending → combat outcomes → ranking. Find feedback loops, dominant strategies, degenerate game states.

**Step 1: Read all game-relevant findings**
Read: `docs/audit/GAME-findings.md`, `docs/audit/DATA-findings.md`, `docs/audit/PERF-findings.md`

**Step 2: Model game economy**
Build a spreadsheet-style model of resource flows, combat outcomes, optimal strategies.

**Step 3: Save synthesis**
Write to: `docs/audit/SYNTHESIS-game-balance.md`

**Step 4: Commit**
```bash
git add docs/audit/SYNTHESIS-game-balance.md
git commit -m "audit: cross-domain game balance synthesis"
```

---

### Task 19: Cross-Domain Quality & Technical Debt Synthesis

**Agent:** `comprehensive-review:code-reviewer`

**Input:** ALL findings from CODE, ARCH, PERF, ERR, UI domains

**Goal:** Prioritize technical debt. What refactoring would have the highest ROI? What's the minimal set of changes to make the codebase maintainable?

**Step 1: Read all quality-relevant findings**
Read: `docs/audit/CODE-findings.md`, `docs/audit/ARCH-findings.md`, `docs/audit/PERF-findings.md`, `docs/audit/ERR-findings.md`, `docs/audit/UI-findings.md`

**Step 2: Prioritize by impact**
Score each finding: `effort (1-5) × impact (1-5) = priority`

**Step 3: Save synthesis**
Write to: `docs/audit/SYNTHESIS-tech-debt.md`

**Step 4: Commit**
```bash
git add docs/audit/SYNTHESIS-tech-debt.md
git commit -m "audit: cross-domain technical debt synthesis"
```

---

### Task 20: Master Audit Report & Remediation Roadmap

**Agent:** Main orchestrator (human or Claude)

**Input:** ALL 15 domain findings + ALL 4 synthesis reports

**Goal:** Produce the final master audit report with:
1. Executive summary of findings by severity
2. Complete findings inventory (deduplicated across domains)
3. Remediation roadmap in priority order
4. Effort estimates per remediation task
5. Dependency graph (which fixes must come before others)

**Step 1: Read everything**
Read all 19 files in `docs/audit/`

**Step 2: Deduplicate findings**
Same issue found by SEC and INPUT? Merge into single finding with both references.

**Step 3: Prioritize remediation**

Priority tiers:
- **P0 - Fix Now (Security Critical):** Active exploits, data loss risk, auth bypass
- **P1 - Fix This Sprint (High Impact):** Game-breaking bugs, data integrity issues, crash paths
- **P2 - Fix This Month (Medium Impact):** Performance bottlenecks, UX issues, code quality
- **P3 - Fix Eventually (Low Impact):** Tech debt, minor UI issues, nice-to-haves
- **P4 - Won't Fix / Accept Risk:** Cosmetic issues, theoretical concerns with no practical impact

**Step 4: Write master report**
Write to: `docs/audit/MASTER-AUDIT-REPORT.md`

**Step 5: Write remediation plan**
Write to: `docs/audit/REMEDIATION-ROADMAP.md`

Format:
```markdown
## P0 - Fix Now

### REM-001: [Finding title]
- **Source findings:** SEC-003, INPUT-007, AUTH-012
- **Files:** exact/paths
- **Fix:** Exact code changes
- **Test:** How to verify the fix
- **Effort:** XS/S/M/L/XL

### REM-002: ...
```

**Step 6: Commit**
```bash
git add docs/audit/MASTER-AUDIT-REPORT.md docs/audit/REMEDIATION-ROADMAP.md
git commit -m "audit: master report and remediation roadmap"
```

---

## Phase 3: Remediation Execution

After Phase 2, execute remediation tasks in priority order. Each remediation task follows TDD:

1. Write failing test exposing the bug
2. Verify test fails
3. Implement minimal fix
4. Verify test passes
5. Run full test suite
6. Commit

Remediation tasks are generated by Task 20 and documented in `REMEDIATION-ROADMAP.md`.

---

## Execution Protocol

### Parallel Execution Strategy

**Phase 1 (15 domain audits):** Run in 3 waves of 5 agents each:

**Wave 1 (Foundation):** SEC, CODE, ARCH, DB, GAME
- These are the core domains that other domains reference
- Run all 5 in parallel

**Wave 2 (Specialized):** INPUT, AUTH, SESS, PERF, ERR
- These can run in parallel with each other
- Some may reference Wave 1 findings but don't depend on them

**Wave 3 (Cross-cutting):** UI, API, DATA, CONCUR, DEPLOY
- These overlap with Wave 1+2 but provide unique perspectives
- Run all 5 in parallel

**Phase 2 (5 synthesis tasks):** Run after ALL Phase 1 completes
- Tasks 16-19 can run in parallel (each reads different domain subsets)
- Task 20 runs after 16-19 complete

**Phase 3 (Remediation):** Sequential, priority-ordered, TDD

### Quality Assurance: The Multi-Review Protocol

Every file is reviewed by its assigned domains. To verify completeness:

1. **Pre-audit verification:** Before starting each domain, the agent confirms it has read every assigned file.
2. **Post-audit verification:** After each domain completes, verify findings cover all assigned files. If a file has zero findings, the agent must explicitly state "File X reviewed - no issues found."
3. **Cross-domain verification:** Synthesis agents (Phase 2) check that every critical file appears in at least one domain's findings (positive or negative confirmation).
4. **Coverage report:** Task 20 produces a final coverage matrix showing which files were reviewed by which domains, with explicit "reviewed-clean" or "findings" status.

### Findings Severity Definitions

| Severity | Definition | Examples |
|----------|------------|---------|
| CRITICAL | Active exploit possible, data loss imminent, game-breaking | SQL injection, auth bypass, infinite resource exploit |
| HIGH | Significant impact, exploitable with effort | XSS, missing CSRF, race condition causing data corruption |
| MEDIUM | Moderate impact, defense in depth issue | Missing input validation, N+1 queries, no error logging |
| LOW | Minor impact, code hygiene | Dead code, naming inconsistency, missing comments |

---

## File Output Structure

```
docs/audit/
├── SEC-findings.md           (Task 1)
├── CODE-findings.md          (Task 2)
├── ARCH-findings.md          (Task 3)
├── PERF-findings.md          (Task 4)
├── DB-findings.md            (Task 5)
├── GAME-findings.md          (Task 6)
├── INPUT-findings.md         (Task 7)
├── AUTH-findings.md          (Task 8)
├── SESS-findings.md          (Task 9)
├── UI-findings.md            (Task 10)
├── API-findings.md           (Task 11)
├── ERR-findings.md           (Task 12)
├── DATA-findings.md          (Task 13)
├── CONCUR-findings.md        (Task 14)
├── DEPLOY-findings.md        (Task 15)
├── SYNTHESIS-security.md     (Task 16)
├── SYNTHESIS-data-flow.md    (Task 17)
├── SYNTHESIS-game-balance.md (Task 18)
├── SYNTHESIS-tech-debt.md    (Task 19)
├── MASTER-AUDIT-REPORT.md    (Task 20)
└── REMEDIATION-ROADMAP.md    (Task 20)
```

---

## Summary

| Phase | Tasks | Agents | Parallelism | Output |
|-------|-------|--------|-------------|--------|
| Phase 1 | 1-15 | 15 domain agents | 3 waves of 5 | 15 findings files |
| Phase 2 | 16-20 | 5 synthesis agents | 4 parallel + 1 sequential | 6 synthesis files |
| Phase 3 | Dynamic | TDD agents | Sequential by priority | Code fixes + tests |

**Total agent invocations:** 20 audit tasks + N remediation tasks
**Total files covered:** 104 PHP + 19 JS + 16 CSS + 3 .htaccess + SQL + configs = 150+ files
**Minimum reviews per PHP file:** 4 domains
**Maximum reviews per PHP file:** 12 domains (critical game logic files)
