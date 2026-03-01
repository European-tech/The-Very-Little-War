# TVLW Architecture Review

**Date:** 2026-03-02
**Scope:** Complete codebase analysis of The Very Little War
**Codebase:** ~17,800 lines of PHP across 85 files, 28 database tables

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Request Flow Analysis](#2-request-flow-analysis)
3. [Data Flow Analysis](#3-data-flow-analysis)
4. [Security Architecture](#4-security-architecture)
5. [Code Organization](#5-code-organization)
6. [Database Design](#6-database-design)
7. [Scalability Analysis](#7-scalability-analysis)
8. [Technical Debt Assessment](#8-technical-debt-assessment)
9. [Deployment Architecture](#9-deployment-architecture)
10. [Maintainability Assessment](#10-maintainability-assessment)
11. [Prioritized Recommendations](#11-prioritized-recommendations)

---

## 1. Architecture Overview

### 1.1 Architecture Diagram

```
                    +------------------+
                    |   Web Browser    |
                    |  (Framework7 UI) |
                    +--------+---------+
                             |
                    HTTPS (Apache/mod_php)
                             |
                    +--------+---------+
                    |    .htaccess     |
                    |  Security Headers|
                    |  Directory Deny  |
                    +--------+---------+
                             |
              +--------------+--------------+
              |                             |
     +--------+--------+          +--------+--------+
     |  Public Pages    |          |  Private Pages   |
     |  (index.php,     |          |  (constructions, |
     |   inscription,   |          |   armee, attaquer |
     |   classement)    |          |   marche, etc.)  |
     +--------+---------+          +--------+---------+
              |                             |
     +--------+---------+          +--------+---------+
     | basicpublicphp   |          | basicprivatephp  |
     | - Login handler  |          | - Session check  |
     | - Rate limiting  |          | - Auth verify    |
     | - Password hash  |          | - CSRF setup     |
     +--------+---------+          | - Online track   |
              |                    | - Resource update |
              |                    | - Action process  |
              +----------+---------+ - Vacation check |
                         |         +--------+---------+
                         |                  |
                +--------+---------+        |
                |  includes/       |        |
                |  fonctions.php   +--------+
                |  (2585 lines)    |
                |  - Game formulas |
                |  - Combat stats  |
                |  - Player CRUD   |
                |  - UI helpers    |
                |  - HTML builders |
                +--------+---------+
                         |
                +--------+---------+
                |  database.php    |
                |  - dbQuery()     |
                |  - dbFetchOne()  |
                |  - dbFetchAll()  |
                |  - dbExecute()   |
                +--------+---------+
                         |
                +--------+---------+
                |  MySQLi          |
                |  (28 tables)     |
                +------------------+
```

### 1.2 Include Dependency Graph

```
Every Private Page
  |
  +-- includes/basicprivatephp.php
  |     +-- includes/connexion.php
  |     |     +-- includes/database.php
  |     +-- includes/fonctions.php
  |     +-- includes/csrf.php
  |     +-- includes/validation.php
  |     +-- includes/logger.php
  |     +-- includes/constantes.php
  |           +-- includes/constantesBase.php
  |                 +-- includes/config.php
  |
  +-- includes/tout.php (HTML shell)
        +-- includes/meta.php
        +-- includes/style.php
        +-- includes/basicprivatehtml.php (sidebar, menu, missions, JS)
              +-- includes/cardsprivate.php (resource bar)
              +-- includes/atomes.php (resource display)

Every Public Page
  |
  +-- includes/basicpublicphp.php
  |     +-- includes/connexion.php
  |     +-- includes/fonctions.php
  |     +-- includes/logger.php
  |     +-- includes/rate_limiter.php
  |
  +-- includes/tout.php (HTML shell)
        +-- includes/basicpublichtml.php (public sidebar)
              +-- includes/statistiques.php
```

### 1.3 Technology Stack

| Layer | Technology | Version/Notes |
|-------|-----------|---------------|
| Frontend | Framework7 Material | Mobile-first CSS/JS framework |
| Frontend JS | jQuery 3.1.1 + jQuery 1.7.2 | Two jQuery versions loaded simultaneously |
| Backend | PHP | >= 7.4 (procedural, no framework) |
| Database | MySQL/MariaDB via MySQLi | Prepared statements (recently migrated) |
| Server | Apache with mod_php | .htaccess for security headers |
| Testing | PHPUnit 9 | 6 test files, bootstrap with mock DB |
| Deployment | Ionos VPS | Direct file deployment |

### 1.4 Pattern Classification

The application follows a **Page Controller** pattern -- each PHP file at the root level is a standalone page controller that handles its own request processing, business logic, and HTML rendering. There is no MVC framework, router, or template engine.

---

## 2. Request Flow Analysis

### 2.1 Private Page Request Lifecycle

A request to any authenticated page (e.g., `constructions.php`) triggers the following chain:

```
1. constructions.php
   |
   +-- include("includes/basicprivatephp.php")
         |
         +-- Session validation (check $_SESSION['login'] and $_SESSION['mdp'])
         +-- DB query: verify password hash matches session
         +-- Session regeneration (fixation prevention)
         +-- Player position check (random coordinates if new game)
         +-- Load constants (constantes.php -> constantesBase.php -> config.php)
         +-- Process $_GET['information'] and $_GET['erreur'] for flash messages
         +-- Online tracking (INSERT/UPDATE connectes table, DELETE stale rows)
         +-- Vacation mode check
         +-- updateRessources() -- FULL resource recalculation:
         |     +-- Calculate time delta since last update
         |     +-- Update energy (with cap check)
         |     +-- Update all 8 atom resources (with cap check) -- 8 individual UPDATEs
         |     +-- Process molecule decay for all 4 classes
         |     +-- Generate absence report if offline > 6 hours
         +-- updateActions() -- Process all pending game actions:
               +-- Complete finished constructions
               +-- Complete finished molecule formations
               +-- Resolve pending attacks (includes combat.php)
               +-- Process resource transfers
               +-- Re-initialize player state
   |
   +-- include("includes/tout.php") -- HTML document shell
         +-- include("includes/basicprivatehtml.php")
               +-- 19 database queries for sidebar, missions, tutorials
               +-- JavaScript for real-time resource counters
   |
   +-- Page-specific logic and HTML output
```

### 2.2 Query Count Per Page Load

**Critical Finding:** A single authenticated page load executes an estimated **40-80+ database queries** before any page-specific logic runs. Here is a breakdown:

| Phase | Estimated Queries |
|-------|-------------------|
| basicprivatephp.php (auth + state) | 12-15 |
| updateRessources() | 15-20 (per resource UPDATE, molecule decay) |
| updateActions() | 5-30+ (depends on pending actions, includes recursive calls) |
| basicprivatehtml.php (sidebar/missions) | 15-20 |
| Page-specific logic | 5-20+ |
| **Total per page load** | **40-80+** |

The `initPlayer()` function alone issues 5 queries and is called multiple times during a single page load (once in `updateActions()`, once at the end of `updateActions()`, and implicitly through `constantes.php`).

### 2.3 Public Page Request Lifecycle

Public pages are simpler but still notable:

```
1. index.php
   +-- Session check (redirect to private if logged in)
   +-- basicpublicphp.php
         +-- Destroy any existing session
         +-- Process login form if submitted
         +-- Rate limit check (10 attempts/5 min)
         +-- bcrypt verify with MD5 fallback + auto-upgrade
         +-- Clean up stale "Visiteur" accounts (legacy raw query!)
   +-- tout.php -> basicpublichtml.php
   +-- Page-specific content
```

### 2.4 Authentication Flow

```
Login (basicpublicphp.php):
  1. Rate limit check (file-based, /tmp/tvlw_rates/)
  2. Sanitize login input
  3. Fetch user by login (prepared statement)
  4. Try password_verify() first (bcrypt)
  5. Fall back to md5() comparison (legacy)
  6. Auto-upgrade MD5 hash to bcrypt on successful legacy login
  7. Store login and HASH in $_SESSION['login'] and $_SESSION['mdp']
  8. Redirect to constructions.php

Session Verification (basicprivatephp.php):
  1. Check $_SESSION['login'] and $_SESSION['mdp'] exist
  2. Apply sanitization to session login value (unnecessary, see issues)
  3. Query DB for stored hash
  4. Compare session hash with DB hash
  5. Regenerate session ID once per session
```

---

## 3. Data Flow Analysis

### 3.1 Game State Update Model

TVLW uses a **lazy evaluation** model for game state. Resources and actions are not computed in real-time or via cron jobs. Instead, all state is recalculated when a player loads a page:

```
Player Loads Page
       |
       v
Calculate time since last update (tempsPrecedent)
       |
       v
For each resource: current + (revenue * elapsed_seconds / 3600)
       |
       v
Cap each resource at storage limit
       |
       v
Apply molecule decay: count * pow(decay_coefficient, elapsed_seconds)
       |
       v
Process pending attack actions (resolve combat if arrival time passed)
       |
       v
Process pending construction completions
       |
       v
Process pending formation completions
       |
       v
Process pending resource transfers
       |
       v
Write all updated values back to DB
```

This model has important implications:

- **Attackers update defender resources** before combat resolution (`updateTargetResources()`)
- **Game state drifts** when players are offline -- molecule decay and resource production are retroactively calculated
- **Multiple recursive update calls** can occur when an attack triggers `updateActions()` for both attacker and defender

### 3.2 Data Model (Key Tables)

```
membre              -- Player accounts (login, password, IP, position x/y)
autre               -- Extended player stats (points, alliances, medals, missions)
ressources          -- Player resources (energy + 8 atom types + revenues)
constructions       -- Building levels + HP + point distributions
molecules           -- 4 molecule classes per player (atom composition + count)
alliances           -- Alliance data (tag, duplicateur level, points)
actionsattaques     -- Pending attack/espionage queue
actionsformation    -- Pending molecule formation queue
actionsconstruction -- Pending building construction queue
actionsenvoi        -- Pending resource transfer queue
messages            -- Player-to-player messaging
rapports            -- Combat/espionage/absence reports
news                -- Admin news posts
sujets / reponses   -- Forum threads and replies
cours               -- Market exchange rates (time series)
connectes           -- Currently online tracking (IP-based)
declarations        -- Alliance wars
grades              -- Alliance member ranks
parties             -- Archived game round results
statistiques        -- Global game statistics (single row)
vacances            -- Vacation mode data
invitations         -- Alliance invitations
moderation          -- Moderation actions log
statutforum         -- Forum read tracking
```

### 3.3 Key Data Patterns

**Serialized Data in Columns:**
- `constructions.pointsProducteur` = `"3;2;1;1;1;1;1;1"` (semicolon-separated levels for 8 resources)
- `constructions.pointsCondenseur` = same format
- `actionsattaques.troupes` = `"100;50;30;0;"` (molecule counts per class)
- `actionsenvoi.ressourcesEnvoyees` = `"100;200;50;0;0;0;0;0;500"` (8 resources + energy)
- `autre.missions` = `"1;0;0;1;0;..."` (completion flags)
- `cours.tableauCours` = comma-separated exchange rate matrix

This is a significant normalization violation. These should be separate columns or join tables, but changing them now would require touching nearly every file.

**Variable Variables Pattern:**
The codebase makes extensive use of PHP variable variables (`$$ressource`, `${'points' . $ressource}`, `${'classeAttaquant' . $c}`). This pattern creates:
- Dynamic variable names like `$carbone`, `$azote`, `$pointscarbone`, `$niveaucarbone`
- Hard-to-trace data flow
- Impossible static analysis

---

## 4. Security Architecture

### 4.1 Strengths (Recent Improvements)

| Feature | Status | Implementation |
|---------|--------|---------------|
| SQL Injection Prevention | **Strong** | `database.php` prepared statement helpers used consistently |
| Password Hashing | **Good** | bcrypt via `password_hash()` with MD5 auto-upgrade |
| CSRF Protection | **Good** | Token-based with `csrf.php`, used on most POST forms |
| Rate Limiting | **Good** | File-based rate limiter on login (10/5min) and registration (3/hr) |
| Security Headers | **Good** | X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy |
| Directory Listing | **Good** | Disabled via .htaccess `Options -Indexes` |
| Sensitive File Access | **Good** | .htaccess blocks .sql, .json, .md, hidden files |
| Error Display | **Good** | `display_errors off` in production |
| Logging | **Good** | Structured file-based logging with categories and context |
| Session Hardening | **Partial** | httponly cookies, strict mode, session regeneration |

### 4.2 Critical Vulnerabilities

**CRITICAL: voter.php -- Complete SQL Injection and Credential Exposure**

`voter.php` is the single most dangerous file in the codebase:
- Hardcoded database credentials for a different database (`theveryl_admin` / `mno33d65e`)
- Raw `mysqli_query()` with string concatenation (direct SQL injection)
- CORS `Access-Control-Allow-Origin: *` allows any domain to call it
- No authentication, no CSRF protection
- Completely bypasses all security infrastructure

**CRITICAL: localStorage Password Storage**

`basicpublicphp.php` line 62:
```php
localStorage.setItem("login", "' . $_SESSION['login'] . '");
```

The `index.php` auto-login reads from localStorage (including a `mdp` key). While the password itself no longer appears to be stored in localStorage in the current code, the auto-submit mechanism and the `bbcode.php` filter that strips `localStorage.getItem("mdp")` suggests this was recently partially fixed but the architecture remains fragile. Any XSS vulnerability would expose credentials.

**CRITICAL: Admin Panel Authentication**

`includes/constantesBase.php` line 52:
```php
define('ADMIN_PASSWORD_HASH', password_hash('Faux mot de passe', PASSWORD_DEFAULT));
```

The admin password hash is regenerated on every PHP request because `password_hash()` is called at define-time rather than storing a pre-computed hash. This means:
1. A new hash is computed on every single page load (unnecessary CPU)
2. The plaintext password is visible in source code (even if it is a placeholder)
3. No CSRF protection on admin actions
4. Admin delete-by-IP uses GET parameter (should be POST)

**HIGH: Session Authentication Design**

`basicprivatephp.php` applies `mysqli_real_escape_string()` and `htmlentities()` to `$_SESSION['login']` on every request. Session data is server-side and trusted; this transformation could corrupt the login value if it contains special characters, causing silent authentication failures.

The session stores the password hash itself (`$_SESSION['mdp']`), which means if the session is leaked (e.g., via session fixation), the attacker gets the bcrypt hash.

**HIGH: Remaining Raw Queries**

Seven PHP files still use raw `mysqli_query()` instead of prepared statements:
- `voter.php` (SQL injection, see above)
- `basicpublicphp.php` line 80 (DELETE with concatenation)
- `admin/index.php` (3 raw queries)
- `maintenance.php` (1 raw query)
- `includes/fonctions.php` (1 raw query in `remiseAZero`)
- `includes/redirectionVacance.php` (1 raw query)

**MEDIUM: antiXSS() Function Misuse**

The `antiXSS()` function at line 1594 of `fonctions.php`:
```php
function antiXSS($phrase, $specialTexte = false) {
    global $base;
    if ($specialTexte) {
        return mysqli_real_escape_string($base, antihtml($phrase));
    } else {
        return mysqli_real_escape_string($base, addslashes(antihtml(trim($phrase))));
    }
}
```

This conflates XSS prevention with SQL escaping. With prepared statements now in use, the SQL escaping is redundant and the function name is misleading. The double-escaping (`addslashes` + `mysqli_real_escape_string`) can corrupt data.

**MEDIUM: session.cookie_secure = 0**

`basicprivatephp.php` line 8 explicitly sets `session.cookie_secure` to 0, meaning session cookies are sent over HTTP. If the site is served over HTTPS (as it should be), this should be set to 1.

### 4.3 Security Architecture Summary

```
                 Threat Surface
                 ==============

 [Browser]  ---- HTTPS ----> [Apache + .htaccess headers]
    |                              |
    |                        [mod_php]
    |                              |
    |   +------- voter.php (BYPASS: no auth, raw SQL, CORS *) ------+
    |   |                                                            |
    |   |  +-- basicpublicphp.php                                    |
    |   |  |     Rate limiter (file-based)                          |
    |   |  |     bcrypt + MD5 fallback                              |
    |   |  |     1 raw DELETE query                                 |
    |   |  |                                                         |
    |   |  +-- basicprivatephp.php                                  |
    |   |        Session check (hash comparison)                    |
    |   |        CSRF token generation                              |
    |   |        Session regeneration                               |
    |   |                                                            |
    |   |  +-- database.php (prepared statements)                   |
    |   |  +-- csrf.php (POST verification)                         |
    |   |  +-- validation.php (input filters)                       |
    |   |  +-- logger.php (audit trail)                             |
    |   |  +-- rate_limiter.php (brute force prevention)            |
    |   |                                                            |
    +---+--- admin/ (no CSRF, GET for destructive actions) ---------+
```

---

## 5. Code Organization

### 5.1 File Structure Assessment

```
Root (/)
  +-- 38 PHP page controllers (flat, no routing)
  +-- 1 API endpoint (api.php)
  +-- composer.json/phar, phpunit.xml/phar
  +-- .htaccess, robots.txt
  +-- maintenance.php (HTML file named .html would suffice)
  |
  includes/ (26 PHP files)
  +-- Core infrastructure: connexion.php, database.php, config.php
  +-- Auth: basicprivatephp.php, basicpublicphp.php
  +-- Security: csrf.php, validation.php, logger.php, rate_limiter.php
  +-- Game logic: fonctions.php (2585 lines!), update.php, combat.php
  +-- UI components: menus.php, tout.php, style.php, meta.php
  +-- UI templates: basicprivatehtml.php, basicpublichtml.php,
  |                 cardsprivate.php, cardspublic.php, atomes.php
  +-- Data: config.php, constantes.php, constantesBase.php
  +-- Other: bbcode.php, copyright.php, partenariat.php, mots.php,
  |          redirectionVacance.php, ressources.php, statistiques.php
  |
  admin/ (9 PHP files) -- Admin panel (separate auth)
  moderation/ (3 PHP files) -- Moderator tools
  migrations/ (migrate.php + 2 SQL files)
  tests/ (bootstrap.php + 6 unit test files)
  js/ (Framework7 + jQuery + crypto libs)
  css/ (Framework7 CSS)
  images/ (26 subdirectories of game assets)
  docs/ (existing security/balance analysis)
  logs/ (runtime log files)
```

### 5.2 Separation of Concerns -- Current State

| Concern | Where It Lives | Assessment |
|---------|---------------|------------|
| Routing | Each .php file IS a route | No central router |
| Authentication | basicprivatephp.php / basicpublicphp.php | Reasonable for procedural |
| Authorization | Inline checks (moderateur flag) | No role system |
| Business Logic | fonctions.php (2585 lines) + inline in pages | **Severely coupled** |
| Data Access | database.php helpers + inline queries everywhere | No repository pattern |
| Presentation | Mixed into every PHP file | **No template separation** |
| Configuration | config.php + constantesBase.php (overlapping!) | Partially centralized |
| Validation | validation.php + antiXSS() + inline regex | Fragmented |

### 5.3 The fonctions.php Problem

`fonctions.php` is the architectural bottleneck. At 2585 lines, it contains:

- **Pure game formulas** (attack, defense, HP, pillage, speed, formation time, decay)
- **Resource production calculations** (revenuEnergie, revenuAtome with DB queries)
- **Player CRUD operations** (inscrire, supprimerJoueur, initPlayer)
- **Building management** (augmenterBatiment, diminuerBatiment, full construction config)
- **Resource update logic** (updateRessources -- 80 lines of DB writes)
- **Action processing** (updateActions -- 150+ lines including combat resolution)
- **Point calculations** (ajouterPoints, pointsVictoire formulas)
- **UI helper functions** (HTML generation, formatting, popover builders)
- **Utility functions** (antiXSS, separerZeros, chiffrePetit, affichageTemps)
- **BBCode processing** (couleurFormule, joueur(), alliance())

This file has **30 `global $base`** declarations and **158 database calls**. Every function accesses the global `$base` connection and most modify global state.

### 5.4 Constants Duplication

There are two parallel constant systems:

1. **config.php** -- Modern, well-documented `define()` constants with `$BUILDING_CONFIG` array
2. **constantesBase.php** -- Legacy arrays (`$nomsRes`, `$paliersAttaque`, `$bonusMedailles`, etc.)

`constantesBase.php` requires `config.php` but then re-declares many of the same values in different formats. The actual game logic (fonctions.php) mostly uses the legacy arrays from `constantesBase.php`, not the modern defines from `config.php`. This means `config.php` is partly aspirational -- it documents the intended single source of truth but is not yet the actual source for many values.

---

## 6. Database Design

### 6.1 Table Inventory (28 tables)

```
Player Data:        membre, autre, ressources, constructions, molecules
Action Queues:      actionsattaques, actionsformation, actionsconstruction, actionsenvoi
Social:             messages, rapports, alliances, grades, declarations,
                    invitations, vacances
Community:          sujets, reponses, news, statutforum
Market:             cours
System:             connectes, statistiques, parties, moderation
Infrastructure:     migrations
External:           sondages, reponses (in voter.php, different DB!)
```

### 6.2 Schema Strengths

- The `migrations/` system is a solid recent addition with proper tracking
- Migration `0001_add_indexes.sql` adds 20+ indexes on frequently queried columns
- Migration `0002_fix_column_types.sql` cleans up BIGINT display widths and VARCHAR sizing
- Login column is now VARCHAR(255) with an index (was TEXT, un-indexable)
- IP column is now VARCHAR(45) to support both IPv4 and IPv6

### 6.3 Schema Weaknesses

**Denormalized Serialized Data:**
Multiple columns store semicolon-separated values that should be normalized:
- `constructions.pointsProducteur` / `pointsCondenseur` (8 values each)
- `actionsattaques.troupes` (4 values)
- `actionsenvoi.ressourcesEnvoyees` / `ressourcesRecues` (9 values each)
- `autre.missions` (19+ values)
- `autre.timestampsconstructions` (4 values)
- `cours.tableauCours` (64+ values as CSV)

This prevents:
- SQL-level filtering or aggregation on individual values
- Foreign key constraints
- Indexing on individual values
- Type safety (everything is VARCHAR)

**No Foreign Keys:**
The schema uses no FOREIGN KEY constraints. Referential integrity is enforced entirely by PHP code. This means:
- Orphaned rows are possible when deletion code has bugs
- Cascading deletes must be manually coded (and are, in `supprimerJoueur()`)
- No database-level protection against invalid references

**Login as Primary Key in Some Tables:**
Several tables use `login` (VARCHAR) as the primary or lookup key instead of a numeric `id`. The `membre` table has an auto-increment `id` column, but `autre`, `ressources`, `constructions`, and `molecules` all use `login` as their identifier. This means:
- Username changes would require updating every table
- String comparisons for joins are slower than integer comparisons
- Case sensitivity issues (the code applies `ucfirst(mb_strtolower())` everywhere)

**Single-Row Tables:**
The `statistiques` table appears to be a single-row global state table (debut, inscrits, maintenance). This is a common pattern in legacy PHP apps but should be replaced with a proper key-value configuration table.

### 6.4 Query Pattern Issues

**N+1 Queries:**
Many loops execute queries inside while-loops:

```php
// In basicprivatephp.php -- archives section
while ($data = mysqli_fetch_array($classement)) {
    $sql4Result = dbQuery($base, 'SELECT nombre FROM molecules WHERE proprietaire = ?...');
    $alliance = dbFetchOne($base, 'SELECT tag, id FROM alliances WHERE id = ?...');
}
```

**Repeated Identical Queries:**
`initPlayer()` is called multiple times per request, and each call issues 5 queries. The `revenuEnergie()` function alone makes 4 queries, and it is called multiple times per page for different detail levels.

**SELECT *:**
Most queries use `SELECT *` even when only one or two columns are needed. This transfers unnecessary data and prevents the query optimizer from using covering indexes.

---

## 7. Scalability Analysis

### 7.1 What Would Break at 100+ Concurrent Users

**Database Connection Exhaustion:**
Every page load opens a new `mysqli_connect()` and runs 40-80+ queries. With 100 concurrent users, that is 4,000-8,000+ queries per page-load cycle. There is no connection pooling.

**Lock Contention on `connectes` Table:**
Every authenticated request does INSERT/UPDATE/DELETE on the `connectes` table. With 100 users, this table sees 300+ writes per page-load cycle. The DELETE of stale rows runs on every request.

**`statistiques` Single-Row Contention:**
Multiple page loads read and potentially write to the same single row in `statistiques`. The new-month check in `basicprivatephp.php` includes a massive archival operation that could run simultaneously from multiple concurrent requests.

**File-Based Rate Limiter:**
The rate limiter in `/tmp/tvlw_rates/` uses file I/O with `LOCK_EX`. Under load, this becomes a bottleneck as PHP processes contend for file locks.

**Lazy Update Cascade:**
When Player A attacks Player B, and Player C loads a page, Player C's `updateActions()` might trigger combat resolution that calls `updateRessources()` and `updateActions()` for Players A and B. These recursive updates are not transactional, creating race conditions.

**Session File Storage:**
PHP's default file-based session handler creates one file per session. With many concurrent users, this directory becomes slow to scan, and session GC adds overhead.

### 7.2 Horizontal Scaling Limitations

The application cannot be horizontally scaled because:
1. No central session store (PHP file sessions are per-server)
2. File-based rate limiter is per-server
3. File-based logging is per-server
4. No stateless request handling (every request modifies game state)
5. No database transaction isolation (race conditions on concurrent updates)

### 7.3 Performance Hotspots

| Hotspot | Impact | Frequency |
|---------|--------|-----------|
| `initPlayer()` | 5+ queries | 2-3 times per page load |
| `updateRessources()` | 15-20 queries | Every page load |
| `updateActions()` | 5-30+ queries | Every page load |
| `basicprivatehtml.php` | 15-20 queries | Every page load |
| `revenuEnergie()` | 4 queries per call | Multiple times per page |
| `connectes` cleanup | 3 queries | Every page load |
| Combat resolution | 30+ queries | When attacks arrive |

---

## 8. Technical Debt Assessment

### 8.1 What Has Been Fixed (Recent Refactoring)

The codebase shows clear evidence of a recent, systematic security and modernization effort:

1. **SQL Injection:** Migrated from raw `mysql_*` functions and string concatenation to MySQLi with prepared statements via `database.php` helper functions. This is the single most impactful improvement.

2. **Password Hashing:** Migrated from plain MD5 to bcrypt via `password_hash()` with transparent auto-upgrade of legacy hashes on login.

3. **CSRF Protection:** Added `csrf.php` with token generation and verification. Integrated into most forms across the application.

4. **Security Headers:** Added comprehensive `.htaccess` with modern security headers, directory listing prevention, and sensitive file access controls.

5. **Centralized Configuration:** Created `config.php` as a single source of truth for game balance constants with documentation.

6. **Logging Infrastructure:** Added structured file-based logging with severity levels and context.

7. **Rate Limiting:** Added brute-force protection on login and registration.

8. **Input Validation:** Added `validation.php` with proper filter functions.

9. **Database Migrations:** Added a migration system with index creation and column type fixes.

10. **Unit Tests:** Added PHPUnit with 6 test files covering combat formulas, market formulas, resource formulas, config consistency, and validation.

11. **Session Hardening:** Added httponly cookie flag, strict mode, and session ID regeneration.

### 8.2 What Remains (Ordered by Severity)

**Severity: Critical**

| Issue | Impact | Effort |
|-------|--------|--------|
| voter.php SQL injection + credential exposure | Full DB compromise possible | Low (delete or rewrite) |
| Admin panel lacks CSRF + uses GET for deletes | Unauthorized admin actions | Low |
| 7 files still use raw `mysqli_query()` | SQL injection vectors | Low-Medium |

**Severity: High**

| Issue | Impact | Effort |
|-------|--------|--------|
| fonctions.php monolith (2585 lines) | Unmaintainable, untestable | High |
| No database transactions | Race conditions, data corruption | Medium |
| No request routing (38 root-level PHP files) | Hard to add middleware, no URL control | High |
| HTML mixed with PHP everywhere | Cannot change UI without risking logic | High |
| Global state via `global $base` and variable variables | Unpredictable side effects | High |
| session.cookie_secure = 0 | Session hijacking over HTTP | Low (config change) |
| constantesBase.php duplicates config.php | Two sources of truth | Medium |
| antiXSS() function double-escapes data | Data corruption, false sense of security | Medium |

**Severity: Medium**

| Issue | Impact | Effort |
|-------|--------|--------|
| 40-80 queries per page load | Poor performance | High |
| No caching layer | Every value recomputed every request | Medium |
| Serialized data in DB columns | Cannot query or index individual values | Very High |
| Two jQuery versions (1.7.2 + 3.1.1) | Conflicts, bloat | Low-Medium |
| SELECT * everywhere | Unnecessary data transfer | Medium |
| N+1 query patterns | Exponential query growth with data | Medium |
| No error handling/recovery in combat resolution | Partial state on failure | Medium |
| initPlayer() called multiple times | Redundant queries | Medium |

**Severity: Low**

| Issue | Impact | Effort |
|-------|--------|--------|
| French variable and function names | Limits international contributors | Very High (rename everything) |
| No autoloader (manual include/require) | Fragile dependency management | Medium |
| No Content-Security-Policy header | XSS mitigation gap | Low |
| Email sending via raw `mail()` | Deliverability issues, no queue | Medium |
| `connectes` table for online tracking | Should be Redis or in-memory | Medium |
| composer.phar and phpunit.phar committed | Should be in .gitignore | Low |

---

## 9. Deployment Architecture

### 9.1 Current Production Setup

```
Ionos VPS
  +-- Apache (mod_php)
  +-- PHP >= 7.4
  +-- MySQL/MariaDB
  +-- File system:
  |     /var/www/... or similar
  |     +-- Game files (PHP + assets)
  |     +-- logs/ directory (writable)
  |     +-- /tmp/tvlw_rates/ (rate limiter state)
  +-- No CI/CD pipeline
  +-- No staging environment
  +-- Manual deployment (likely FTP/SCP)
```

### 9.2 Deployment Concerns

1. **No deployment automation:** Changes appear to be manually copied to the VPS. No git hooks, no CI/CD.
2. **No staging environment:** Changes go directly to production.
3. **No database backup automation evident:** Critical for a game with persistent state.
4. **Credentials in source code:** `connexion.php` has DB credentials. `voter.php` has different DB credentials hardcoded. `constantesBase.php` has a plaintext admin password.
5. **composer.phar and phpunit.phar in repo:** These 8MB+ binaries should not be in version control.
6. **No PHP opcode cache configuration visible:** OPcache would significantly improve performance.
7. **Log rotation:** The file-based logger creates a new file per day but there is no rotation/cleanup mechanism.

---

## 10. Maintainability Assessment

### 10.1 Maintainability Score: 3/10

| Factor | Score | Rationale |
|--------|-------|-----------|
| Readability | 3/10 | French naming, variable variables, mixed concerns |
| Testability | 2/10 | Almost everything needs `global $base`, no DI |
| Modularity | 2/10 | fonctions.php monolith, no clear module boundaries |
| Documentation | 5/10 | config.php well-documented, database.php has docblocks, but most code undocumented |
| Consistency | 4/10 | New security code is consistent, legacy code varies wildly |
| Debug-ability | 3/10 | Logger is good, but variable variables and global state make tracing hard |
| Change Safety | 2/10 | No comprehensive test suite, tightly coupled, no type hints |

### 10.2 Key Maintainability Risks

**Cognitive Load:** Understanding a combat resolution requires tracing through:
- `basicprivatephp.php` (loads constants)
- `fonctions.php` `updateActions()` (detects pending attack)
- `fonctions.php` `updateRessources()` (updates both players)
- `includes/combat.php` (included inline, uses variables from calling scope)
- `fonctions.php` again (report generation, point calculations)
- Back to `updateActions()` (database writes)

This spans 400+ lines across 3 files with heavy use of variable variables and global state.

**Fragile Global State:** The `initPlayer()` function sets 20+ global variables. Any of these can be read or modified by any function. A change to one formula can have cascading effects through unrelated code paths.

**combat.php is Not a Function:** The combat resolution file (`includes/combat.php`) is not a function -- it is an included script that reads and writes variables from the calling scope (`$actions`, `$nomsRes`, `$base`, etc.). This makes it impossible to unit test or reason about in isolation.

### 10.3 What Makes This Codebase Survivable

Despite the low maintainability score, several factors make continued development feasible:

1. **Small scale:** 17,800 lines is manageable for a single developer
2. **Procedural simplicity:** No framework abstractions to learn; the code does exactly what it says
3. **Good security foundation:** The recent refactoring has addressed the most dangerous issues
4. **Centralized constants:** config.php provides a clear place to tune game balance
5. **Unit test infrastructure:** PHPUnit is set up and working with 6 test files
6. **Migration system:** Database changes can be tracked and applied systematically

---

## 11. Prioritized Recommendations

### Phase 1: Critical Security Fixes (Effort: Low, Impact: Critical)

1. **Delete or completely rewrite `voter.php`.** This file has hardcoded credentials, SQL injection, and CORS wildcard. If the voting feature is needed, rewrite it using the existing `database.php` helpers and authentication system.

2. **Fix remaining raw `mysqli_query()` calls.** Seven files still use raw queries. Convert them to use `dbQuery()`/`dbExecute()`.

3. **Set `session.cookie_secure = 1`** in `basicprivatephp.php` (assuming HTTPS is deployed).

4. **Fix admin panel:** Add CSRF tokens to all forms. Convert GET-based destructive actions (delete account) to POST. Pre-compute the admin password hash instead of calling `password_hash()` at define-time.

5. **Move database credentials to a file outside the webroot**, or use environment variables.

### Phase 2: Architectural Quick Wins (Effort: Low-Medium, Impact: High)

6. **Cache `initPlayer()` results** in a request-scoped variable to prevent repeated 5-query calls during a single page load.

7. **Remove the dual constants system.** Make `config.php` the true single source of truth and update `fonctions.php` to use its defines instead of the legacy `constantesBase.php` arrays.

8. **Fix `antiXSS()`.** Since prepared statements are now used, the SQL escaping in this function is unnecessary and harmful. Split it into:
   - `sanitizeOutput()` (already exists in `validation.php`) for HTML output escaping
   - Remove `mysqli_real_escape_string` and `addslashes` from input sanitization

9. **Add `Content-Security-Policy` header** to `.htaccess`.

10. **Remove jQuery 1.7.2.** Only include jQuery 3.1.1. Remove `jquery.jcryption`, `sha.js`, `sha1.js`, and `aes.js` if client-side crypto is no longer used.

### Phase 3: Modularize fonctions.php (Effort: Medium-High, Impact: High)

11. **Split `fonctions.php` into focused modules:**

```
includes/
  formulas/
    combat.php       -- attaque(), defense(), pointsDeVieMolecule(), etc.
    resources.php    -- revenuEnergie(), revenuAtome(), placeDepot(), etc.
    molecules.php    -- coefDisparition(), demiVie(), tempsFormation(), etc.
    buildings.php    -- pointsDeVie(), vieChampDeForce(), cost/time formulas
    points.php       -- ajouterPoints(), pointsVictoire*, pointsPillage()
  player/
    init.php         -- initPlayer() (refactored to return array, not set globals)
    registration.php -- inscrire(), supprimerJoueur()
    update.php       -- updateRessources(), updateActions()
  ui/
    helpers.php      -- antihtml(), separerZeros(), chiffrePetit(), etc.
    formatting.php   -- couleurFormule(), nombreAtome(), chipInfo(), etc.
    html.php         -- debutCarte(), debutListe(), item(), etc.
```

12. **Refactor combat.php into a function.** Change it from an included script to:
```php
function resolveCombat($base, $actions, $nomsRes, $nbClasses): array {
    // ... combat logic ...
    return ['winner' => $gagnant, 'losses' => [...], 'pillage' => [...]];
}
```

### Phase 4: Performance Improvements (Effort: Medium, Impact: Medium)

13. **Batch resource updates.** Instead of 8 individual UPDATE queries (one per resource), build a single UPDATE with all columns.

14. **Eliminate N+1 queries** with JOINs or batch fetches, especially in `basicprivatephp.php`'s archive section and `basicprivatehtml.php`'s sidebar.

15. **Replace `SELECT *` with specific columns** in hot-path queries.

16. **Move online tracking to a lighter mechanism.** The `connectes` table write-on-every-request pattern should use a less expensive approach (e.g., only update every 60 seconds, or use Redis).

17. **Enable OPcache** on the VPS if not already configured.

### Phase 5: Structural Improvements (Effort: High, Impact: Medium-Long-term)

18. **Introduce a simple routing layer** to consolidate the 38 root-level PHP files. Even a simple `switch` in an `index.php` front controller would enable centralized middleware (auth, logging, error handling).

19. **Separate HTML from PHP.** Start with the highest-traffic pages. Extract HTML into simple template files. Even basic `include("templates/constructions.tpl.php")` separation would help.

20. **Replace global state with dependency injection.** Pass `$base` as a parameter instead of using `global $base`. Return values from `initPlayer()` instead of setting 20+ globals.

21. **Add database transactions** around critical multi-step operations:
    - Combat resolution (attack resolution + resource transfer + stat updates)
    - Building construction completion
    - Resource transfers between players
    - Game round reset

22. **Normalize serialized database columns** (long-term). Start with `actionsattaques.troupes` and `actionsenvoi.ressourcesEnvoyees` as they are the most operationally impactful.

### Phase 6: Infrastructure (Effort: Medium, Impact: Medium)

23. **Set up automated deployment** with git push hooks or a simple CI/CD script.

24. **Create a staging environment** on the same VPS (different vhost, copy of DB).

25. **Automate database backups** with a daily cron job.

26. **Add log rotation** (either in the PHP logger or via system logrotate).

27. **Remove committed binaries** (composer.phar, phpunit.phar) from version control and add them to `.gitignore`.

---

## Appendix A: File-by-File Risk Assessment

| File | Risk | Key Issue |
|------|------|-----------|
| `voter.php` | **CRITICAL** | SQL injection, hardcoded credentials, CORS * |
| `includes/basicprivatephp.php` | HIGH | 268 lines of mixed auth/state/archival/email logic |
| `includes/fonctions.php` | HIGH | 2585-line monolith, 30 globals, 158 DB calls |
| `includes/combat.php` | HIGH | Included script (not a function), variable-variable heavy |
| `includes/constantesBase.php` | MEDIUM | Plaintext admin password, duplicates config.php |
| `admin/index.php` | MEDIUM | No CSRF, GET-based deletes, raw queries |
| `includes/basicpublicphp.php` | MEDIUM | 1 raw query, localStorage interaction |
| `includes/basicprivatehtml.php` | MEDIUM | 460+ lines of mixed PHP/HTML/JS, 19+ queries |
| `marche.php` | LOW | Complex but functional, uses prepared statements |
| `attaquer.php` | LOW | Uses CSRF and prepared statements |
| `inscription.php` | LOW | Rate limited, CSRF protected, validated |
| `api.php` | LOW | Authenticated, uses game functions, but no rate limiting |

## Appendix B: Database Query Count Estimate by File

| File | Queries (Estimated) | Called When |
|------|-------|------------|
| basicprivatephp.php | 12-15 | Every private page |
| fonctions.php:updateRessources() | 15-20 | Every private page |
| fonctions.php:updateActions() | 5-30+ | Every private page |
| fonctions.php:initPlayer() | 5 | 2-3x per private page |
| basicprivatehtml.php | 15-20 | Every private page |
| combat.php | 25-30 | Per attack resolution |
| constructions.php | 10-15 | Constructions page |
| armee.php | 10-15 | Army page |
| attaquer.php | 10-20 | Map/attack page |
| marche.php | 10-15 | Market page |
| classement.php | 10-20 | Rankings page |

## Appendix C: Glossary of Game Concepts

| French Term | English | Role |
|-------------|---------|------|
| Generateur | Generator | Produces energy |
| Producteur | Producer | Produces atoms (drains energy) |
| Depot | Storage | Increases max resource capacity |
| Champ de force | Force field | Defense bonus, absorbs building damage |
| Ionisateur | Ionizer | Attack bonus |
| Condenseur | Condenser | Increases atom effectiveness |
| Lieur | Binder | Reduces molecule formation time |
| Stabilisateur | Stabilizer | Reduces molecule decay rate |
| Duplicateur | Duplicator | Alliance-wide production/combat bonus |
| Atomes | Atoms | 8 resource types (C, N, H, O, Cl, S, Br, I) |
| Molecules | Molecules | Military units composed of atoms |
| Neutrinos | Neutrinos | Espionage/counter-espionage units |
| Pilier | Pillar/Tier | Medal threshold level |
| Manche | Round | Monthly game reset cycle |

---

*This review was generated by analysis of the complete source code. No files were modified during the review process.*
