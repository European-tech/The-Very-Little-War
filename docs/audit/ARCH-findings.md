# ARCHITECTURE AUDIT: The Very Little War

## Codebase Summary

- **Stack:** PHP 8.2 (procedural), MariaDB 10.11, Apache 2, Framework7 CSS, jQuery
- **Files audited:** 30 core files across 6 architectural layers
- **Lines of code (core):** ~5,500 lines across audited files
- **Architecture style:** Classic PHP "include-based" monolith, no framework, no OOP, no dependency injection

---

## I. DEPENDENCY GRAPH

```
PAGE FILES (entry points)
=========================

index.php
  |-- session_start()
  |-- [if logged in]  includes/basicprivatephp.php
  |   |-- includes/connexion.php
  |   |   |-- mysqli_connect()
  |   |   `-- includes/database.php          [dbQuery, dbFetchOne, dbFetchAll, dbExecute]
  |   |-- includes/fonctions.php             [SHIM: loads 9 modules]
  |   |   |-- includes/formulas.php          [attaque(), defense(), pillage(), etc.]
  |   |   |-- includes/game_resources.php    [revenuEnergie(), updateRessources()]
  |   |   |-- includes/game_actions.php      [updateActions()]
  |   |   |-- includes/player.php            [initPlayer(), inscrire(), supprimerJoueur()]
  |   |   |-- includes/ui_components.php     [debutCarte(), item(), chip(), etc.]
  |   |   |-- includes/display.php           [couleurFormule(), affichageTemps(), antiXSS()]
  |   |   |-- includes/db_helpers.php        [ajouter(), allianceResearchBonus()]
  |   |   |-- includes/prestige.php          [awardPrestigePoints(), getPrestige()]
  |   |   `-- includes/catalyst.php          [getActiveCatalyst(), catalystEffect()]
  |   |-- includes/csrf.php                  [csrfToken(), csrfCheck()]
  |   |-- includes/validation.php            [validateLogin(), sanitizeOutput()]
  |   |-- includes/logger.php                [logInfo(), logWarn(), logError()]
  |   |-- includes/constantes.php
  |   |   |-- includes/constantesBase.php
  |   |   |   `-- includes/config.php        [defines + $BUILDING_CONFIG, etc.]
  |   |   `-- initPlayer($_SESSION['login']) [SIDE EFFECT: sets ~30 globals]
  |   `-- [season reset logic: ~160 lines inline]
  |-- [if not logged in] includes/basicpublicphp.php
  |   |-- includes/connexion.php
  |   |-- includes/fonctions.php
  |   |-- includes/logger.php
  |   `-- includes/rate_limiter.php
  `-- includes/tout.php                      [HTML template + sub-menus]
```

### Include Chain Depth: 5 levels max (no circular dependencies)

---

## II. REQUEST LIFECYCLE: Private Page Load

TOTAL DB QUERIES PER PAGE LOAD: 40-100+ (normal), 150+ (if combat resolves)

initPlayer() is called 3-4 times per request, each executing 15-20 queries.

---

## III. FINDINGS

### FINDING-ARCH-001: [CRITICAL] - God Function: initPlayer() called 3-4 times per request
- **Files:** includes/player.php (lines 104-449), includes/basicprivatephp.php, includes/constantes.php, constructions.php
- **Description:** initPlayer() is a 345-line function performing 4 responsibilities: loads player data from 4 DB tables, computes derived state, generates HTML strings with inline JS, and queries 9 building upgrade queues. Called 3-4 times per request = 60-80 redundant queries.
- **Impact:** 60-80 wasted DB queries/page, untestable, HTML in data layer
- **Remediation:** Split into PlayerDataLoader (cacheable), PlayerStateCalculator, BuildingRenderer. Cache per-request. Call once.

### FINDING-ARCH-002: [CRITICAL] - combat.php included (not required) shares caller scope, 600 lines of side effects
- **Files:** includes/combat.php (602 lines), includes/game_actions.php (line 102)
- **Description:** combat.php is `include()`d inside a while loop in updateActions(), sharing all local variables. Creates ~50 variables in caller scope via variable variables (${'classeAttaquant' . $c}). If second combat resolves same request, leftover variables corrupt results.
- **Impact:** Subtle combat bugs from implicit variable sharing, untestable
- **Remediation:** Refactor to function resolveCombat($attacker, $defender, $troops, $base) returning CombatResult array

### FINDING-ARCH-003: [CRITICAL] - No separation of concerns: SQL, HTML, JS interleaved everywhere
- **Files:** ALL page files, includes/player.php, includes/game_actions.php, includes/tout.php
- **Description:** Single files contain SQL queries, business logic, HTML generation, and inline JS. player.php::initPlayer() generates HTML strings. game_actions.php has 150+ lines of HTML report markup inside action processing.
- **Impact:** Cannot change UI without understanding business logic, untestable, XSS surface
- **Remediation:** Adopt MVC separation: controllers (pages), models (game logic+DB), views (templates)

### FINDING-ARCH-004: [HIGH] - Configuration triple-definition across 3 files with drift risk
- **Files:** includes/config.php, includes/constantes.php, includes/constantesBase.php
- **Description:** Same data defined in 3 places: config.php ($RESOURCE_NAMES), constantesBase.php ($nomsRes), constantes.php ($nomsRes again). Changing one but not others = silent bugs.
- **Impact:** Must update 3 files for any constant change
- **Remediation:** Make config.php single source of truth, delete duplicates

### FINDING-ARCH-005: [HIGH] - ~45 functions use global $base, making them untestable
- **Files:** formulas.php, game_resources.php, player.php, db_helpers.php, display.php, combat.php
- **Description:** Almost every function uses `global $base` to access DB. Even "formula" functions query the DB. Only 5 of ~90 functions can be unit tested without DB.
- **Impact:** Cannot unit test without live DB, implicit coupling via shared globals
- **Remediation:** Pass $base as parameter, separate pure math from DB lookups

### FINDING-ARCH-006: [HIGH] - Season reset (160 lines) embedded in auth guard, runs every page load
- **Files:** includes/basicprivatephp.php (lines 110-283)
- **Description:** Complete season reset logic in auth guard file. First player after month change triggers 30+ second blocking reset with synchronous mail() calls.
- **Impact:** Random user gets 30s hang, auth guard does game lifecycle
- **Remediation:** Extract to cron job, remove game logic from auth guard

### FINDING-ARCH-007: [HIGH] - updateActions() is 528-line monolith handling all action types + HTML
- **Files:** includes/game_actions.php (528 lines)
- **Description:** Processes constructions, formations, attacks, espionage, trade deliveries in one function. Contains 150+ lines of HTML report generation. Recursive calls for opponent.
- **Impact:** Impossible to modify safely, mixing data mutation with presentation
- **Remediation:** Split into processConstructions(), processFormations(), processAttacks(), etc.

### FINDING-ARCH-008: [HIGH] - No transaction support for multi-table combat updates
- **Files:** includes/combat.php, includes/game_actions.php, includes/game_resources.php
- **Description:** Combat updates 6+ tables (molecules x8, ressources x2, constructions, autre x4, declarations, rapports x2, cooldowns) sequentially without BEGIN/COMMIT. Failure mid-sequence = inconsistent state.
- **Impact:** Partial combat = free kills, lost resources, phantom battles
- **Remediation:** Wrap in mysqli_begin_transaction() / mysqli_commit()

### FINDING-ARCH-009: [MEDIUM] - Variable variables (${'classeAttaquant' . $c}) throughout combat
- **Files:** includes/combat.php, includes/game_actions.php, includes/game_resources.php
- **Description:** Dynamic variable names instead of arrays. Cannot grep, no IDE support, typos create silent undefined variables.
- **Remediation:** Replace with indexed arrays: $classeAttaquant[$c]

### FINDING-ARCH-010: [MEDIUM] - api.php flat dispatcher, no routing/rate limiting, exposes formulas for any player
- **Files:** api.php (53 lines)
- **Description:** GET parameter maps to function call. No rate limiting, joueur parameter allows querying any player's formula results, no CSRF.
- **Remediation:** Dispatch table, rate limiting, restrict joueur to current session

### FINDING-ARCH-011: [MEDIUM] - db_helpers.php contains raw query() function bypassing prepared statements
- **Files:** includes/db_helpers.php (line 7-16)
- **Description:** Legacy query() uses mysqli_query() directly. ajouter() uses dynamic column names.
- **Remediation:** Remove query(), whitelist table/column names in ajouter()

### FINDING-ARCH-012: [MEDIUM] - Full map grid loaded into memory on every attaquer.php load
- **Files:** attaquer.php (lines 274-316)
- **Description:** Loads ALL players, builds 2D grid, runs 2 queries per player for war/pact checks. O(N^2) memory, O(N) queries.
- **Remediation:** Spatial queries, viewport-based loading, cache alliance relationships

### FINDING-ARCH-013: [MEDIUM] - Password hash stored in session, compared every request
- **Files:** includes/basicprivatephp.php (lines 16-23)
- **Description:** Password hash in session file on disk, extra DB query per request for auth.
- **Remediation:** Use session token approach instead

### FINDING-ARCH-014: [MEDIUM] - Rate limiter uses filesystem with no cleanup
- **Files:** includes/rate_limiter.php
- **Description:** JSON files in /tmp/tvlw_rates/, no cleanup, race condition between read/write.
- **Remediation:** Use DB table with automatic expiry or Redis

### FINDING-ARCH-015: [LOW] - config.php defines unused constants (formulas use hardcoded values)
- **Files:** includes/config.php, includes/formulas.php
- **Description:** config.php defines ATTACK_ATOM_COEFFICIENT=0.1 but formulas.php uses hardcoded 0.1. Config is documentation, not configuration.
- **Remediation:** Update formulas to use defined constants

### FINDING-ARCH-016: [LOW] - Adding new building/atom requires 5-10 file changes
- **Files:** Multiple
- **Description:** New building needs changes in config.php, constantes, player.php (3 functions), constructions.php, combat.php, DB schema.
- **Remediation:** Data-driven design using $BUILDING_CONFIG consistently

---

## SEVERITY SUMMARY

| Severity | Count | Key Issues |
|----------|-------|------------|
| CRITICAL | 3 | initPlayer() god function, combat.php scope sharing, no separation of concerns |
| HIGH | 5 | Config triple-def, global state, season reset in auth, updateActions monolith, no transactions |
| MEDIUM | 6 | Variable variables, api.php, raw query(), map loading, password in session, rate limiter |
| LOW | 2 | Unused config constants, extension difficulty |

## TESTABILITY: Only 5 of ~90 functions can be unit tested without DB
