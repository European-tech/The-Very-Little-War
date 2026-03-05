# Ultra Audit Pass 1 — Domain 2: Code Quality & Architecture

**Date:** 2026-03-05
**Pass:** 1 (Broad Scan)
**Subagents:** 5 (Duplication, PHP Modern, Architecture, Error Handling, Dead Code)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 10 |
| MEDIUM | 23 |
| LOW | 25 |
| **Total** | **58** |

---

## Area 1: Code Duplication & DRY (19 findings)

#### P1-D2-001: Pagination logic copy-pasted 7 times
- **Severity:** HIGH — **Location:** messages.php, rapports.php, classement.php (x3), listesujets.php, sujet.php
- **Fix:** Extract `renderPagination()` in ui_components.php — **Effort:** S

#### P1-D2-002: Countdown timer JS generated inline 8+ times
- **Severity:** HIGH — **Location:** attaque.php, attaquer.php, constructions.php, armee.php, marche.php
- **Fix:** External `startCountdown(elementId, seconds, redirectUrl)` function — **Effort:** M

#### P1-D2-003: Dynamic resource UPDATE SQL pattern duplicated 4 times
- **Severity:** MEDIUM — **Location:** constructions.php, marche.php, armee.php (x2)
- **Fix:** Create `updateResourceColumns()` helper in db_helpers.php — **Effort:** M

#### P1-D2-004: War/Pact alliance lookup duplicated 3 times
- **Severity:** MEDIUM — **Location:** attaquer.php, classement.php (x2) — **Effort:** S

#### P1-D2-005: Dual auth guard pattern repeated in 7 hybrid pages
- **Severity:** LOW — **Fix:** Create `basichybridphp.php` — **Effort:** XS

#### P1-D2-006: Delete item pattern (messages vs reports) nearly identical
- **Severity:** LOW — **Effort:** XS

#### P1-D2-007: Distance formula repeated 6 times inline
- **Severity:** MEDIUM — **Fix:** Add `distance()` function to formulas.php — **Effort:** XS

#### P1-D2-008: Market buy/sell blocks share ~70% identical code
- **Severity:** MEDIUM — **Fix:** Extract `executeMarketTrade()` helper — **Effort:** M

#### P1-D2-009: Forum ban check duplicated in forum.php and sujet.php
- **Severity:** LOW — **Effort:** XS

#### P1-D2-010: Column-order whitelist switch duplicated 3 times
- **Severity:** LOW — **Fix:** Define column whitelists as arrays — **Effort:** XS

#### P1-D2-011: antiXSS(), sanitizeOutput(), antihtml() are functionally identical
- **Severity:** LOW — **Fix:** Standardize on `sanitizeOutput()` — **Effort:** S

#### P1-D2-012: Producteur/Condenseur point allocation handlers identical
- **Severity:** MEDIUM — **Fix:** Extract `processPointAllocation()` — **Effort:** S

#### P1-D2-013: Dynamic molecule column UPDATE duplicated in armee.php alone (x3)
- **Severity:** LOW — Covered by P1-D2-003

#### P1-D2-014: Email-sending logic monolithic and inline in basicprivatephp.php
- **Severity:** MEDIUM — **Fix:** Extract `sendGameEmail()` into includes/email.php — **Effort:** S

#### P1-D2-015: rangForum() issues 2 DB queries per call (N+1 on forum)
- **Severity:** MEDIUM — **Fix:** Pre-load author data — **Effort:** S

#### P1-D2-016: connectes cleanup runs in both auth guards inconsistently
- **Severity:** LOW — **Effort:** XS

#### P1-D2-017: Semicolon-delimited string building repeated 4 times
- **Severity:** LOW — **Fix:** Use `implode(';', $values)` — **Effort:** XS

#### P1-D2-018: ui_components item() has duplicate HTML in return/echo branches
- **Severity:** LOW — **Effort:** XS

#### P1-D2-019: Resource validation loop repeated in multiple POST handlers
- **Severity:** LOW — **Fix:** Create `validateResourceAmounts()` helper — **Effort:** S

---

## Area 2: PHP Modernization (21 findings)

#### P1-D2-020: No `declare(strict_types=1)` in any file
- **Severity:** HIGH — **Fix:** Add to all includes/ files — **Effort:** M

#### P1-D2-021: All functions lack type declarations
- **Severity:** HIGH — **Fix:** Add typed params and return types — **Effort:** L

#### P1-D2-022: config.php uses procedural define() instead of typed enums/classes
- **Severity:** MEDIUM — **Fix:** Group into PHP 8.1 enums or readonly classes — **Effort:** L

#### P1-D2-023: Null coalescing opportunities missed — runtime warnings possible
- **Severity:** MEDIUM — **Fix:** Replace direct array access after nullable dbFetchOne with `??` — **Effort:** S

#### P1-D2-024: switch statements should use match expressions
- **Severity:** LOW — **Effort:** S

#### P1-D2-025: formulas.php uses loose `==` comparisons without strict_types
- **Severity:** MEDIUM — **Effort:** XS

#### P1-D2-026: BBCode() function naming/parameter violations (PSR-12)
- **Severity:** LOW — **Effort:** XS

#### P1-D2-027: synthesizeCompound() exception propagates uncaught to UI
- **Severity:** MEDIUM — **Effort:** S

#### P1-D2-028: logger.php uses date() instead of gmdate() (timezone implicit)
- **Severity:** LOW — **Effort:** XS

#### P1-D2-029: connexion.php uses procedural mysqli_connect instead of OOP
- **Severity:** LOW — **Effort:** M

#### P1-D2-030: withTransaction() catches Exception only, not Throwable
- **Severity:** HIGH — **Fix:** Change to `catch (\Throwable $e)` — **Effort:** XS

#### P1-D2-031: Dynamic variable variables ($$) used 84 times across 6 files
- **Severity:** MEDIUM — **Fix:** Replace with arrays — **Effort:** L

#### P1-D2-032: prestige.php hasPrestigeUnlock() no memoization (3 DB queries/page)
- **Severity:** MEDIUM — **Fix:** Add static cache to getPrestige() — **Effort:** XS

#### P1-D2-033: validation.php functions lack type declarations
- **Severity:** MEDIUM — **Effort:** M

#### P1-D2-034: Rate limiter file-based read-modify-write race condition
- **Severity:** MEDIUM — **Effort:** S

#### P1-D2-035: game_actions.php mixes raw mysqli_begin_transaction with withTransaction
- **Severity:** MEDIUM — **Effort:** M

#### P1-D2-036: csrfCheck() redirects to HTTP_REFERER (open redirect)
- **Severity:** HIGH — **Fix:** Hardcode safe redirect target — **Effort:** XS (cross-ref P1-D1-009)

#### P1-D2-037: compounds.php hardcodes 86400 magic number
- **Severity:** LOW — **Effort:** XS

#### P1-D2-038: constantesBase.php duplicates medal threshold arrays from config.php
- **Severity:** MEDIUM — **Fix:** Use alias assignments — **Effort:** S

#### P1-D2-039: revenuEnergie $detail is undocumented magic int enum
- **Severity:** LOW — **Fix:** Create PHP 8.1 IntEnum — **Effort:** S

#### P1-D2-040: Mixed indentation (tabs vs spaces) across modules
- **Severity:** LOW — **Fix:** Run php-cs-fixer — **Effort:** XS

---

## Area 3: Architecture (21 findings)

#### P1-D2-041: player.php is 971-line God File with 49 globals
- **Severity:** HIGH — **Fix:** Split into 4 modules — **Effort:** L

#### P1-D2-042: initPlayer injects 24+ variables into global scope
- **Severity:** HIGH — **Fix:** Return structured array instead — **Effort:** L

#### P1-D2-043: combat.php is included script with no function encapsulation (30+ ambient vars)
- **Severity:** HIGH — **Fix:** Wrap in function returning results array — **Effort:** L

#### P1-D2-044: Dynamic variable names (${'var' . $i}) — 84 occurrences across 6 files
- **Severity:** HIGH — **Fix:** Replace with arrays — **Effort:** M (cross-ref P1-D2-031)

#### P1-D2-045: config.php is 756-line flat file mixing constants and arrays
- **Severity:** MEDIUM — **Fix:** Split into domain-specific config files — **Effort:** M

#### P1-D2-046: constantesBase.php duplicates config.php with different var names
- **Severity:** MEDIUM — **Fix:** Use alias assignments from config.php — **Effort:** S

#### P1-D2-047: 97 global variable declarations across includes/
- **Severity:** HIGH — **Fix:** Pass $base as parameter; eliminate globals — **Effort:** L

#### P1-D2-048: Page files mix business logic, data access, and HTML rendering
- **Severity:** MEDIUM — **Fix:** Extract service layer — **Effort:** L

#### P1-D2-049: basicprivatephp.php is 230-line God Include (auth + season reset + email)
- **Severity:** MEDIUM — **Fix:** Split into middleware modules — **Effort:** M

#### P1-D2-050: fonctions.php loads 9 modules on every request regardless of need
- **Severity:** MEDIUM — **Fix:** Per-page explicit includes — **Effort:** M

#### P1-D2-051: No autoloading, namespacing, or class structure
- **Severity:** MEDIUM — **Fix:** Incremental adoption — **Effort:** XL

#### P1-D2-052: initPlayer generates HTML inside data-loading function
- **Severity:** MEDIUM — **Fix:** Move HTML to constructions.php — **Effort:** S

#### P1-D2-053: $base passed via global despite helpers accepting it as param
- **Severity:** MEDIUM — **Fix:** Add $base to all function signatures — **Effort:** M

#### P1-D2-054: Season reset in web request with inline email sending
- **Severity:** MEDIUM — **Fix:** Move to cron job — **Effort:** M

#### P1-D2-055: combat.php builds 160 lines of HTML report strings
- **Severity:** MEDIUM — **Fix:** Store structured data, render at display time — **Effort:** M

#### P1-D2-056: Semicolon-delimited strings as poor-man's arrays in DB
- **Severity:** LOW — **Fix:** Separate columns or JSON — **Effort:** L

#### P1-D2-057: No service layer — business ops span multiple files via includes
- **Severity:** MEDIUM — **Fix:** Create includes/services/ — **Effort:** L

#### P1-D2-058: HTML built via concatenation, no template engine
- **Severity:** LOW — **Fix:** Incremental template extraction — **Effort:** XL

#### P1-D2-059: Static function caches never invalidated during request
- **Severity:** LOW — **Fix:** Add cache invalidation functions — **Effort:** S

#### P1-D2-060: Include path inconsistency (mixed relative and __DIR__)
- **Severity:** LOW — **Fix:** Standardize on __DIR__ — **Effort:** S

#### P1-D2-061: N+1 query patterns in game_resources.php despite caching
- **Severity:** LOW — **Fix:** Consolidate queries using JOINs — **Effort:** S

---

## Area 4: Error Handling & Logging (19 findings)

#### P1-D2-062: withTransaction catches Exception only, not Throwable
- **Severity:** HIGH — Cross-ref P1-D2-030 — **Effort:** XS

#### P1-D2-063: Combat transaction uses raw mysqli outside withTransaction
- **Severity:** HIGH — **Fix:** Refactor to use withTransaction — **Effort:** S

#### P1-D2-064: synthesizeCompound exception propagates uncaught
- **Severity:** HIGH — Cross-ref P1-D2-027 — **Effort:** S

#### P1-D2-065: All HTML pages return HTTP 200 for all error states
- **Severity:** MEDIUM — **Fix:** Set proper status codes — **Effort:** M

#### P1-D2-066: logger.php file_put_contents failure is silent
- **Severity:** MEDIUM — **Fix:** Check return value, fallback to error_log — **Effort:** XS

#### P1-D2-067: sendAdminAlertEmail() uses @mail() suppressing errors
- **Severity:** MEDIUM — **Fix:** Remove @, check return value — **Effort:** XS

#### P1-D2-068: combat.php include inside manual transaction — dbExecute failures silent
- **Severity:** HIGH — **Fix:** Make DB failures throw inside transactions — **Effort:** M

#### P1-D2-069: Resource transfer block has no transaction and no error handling
- **Severity:** HIGH — Cross-ref P1-D1-047 — **Effort:** S

#### P1-D2-070: armee.php SQL failures inside withTransaction don't throw
- **Severity:** MEDIUM — **Fix:** Replace error_log with throw — **Effort:** S

#### P1-D2-071: performSeasonEnd() exception uncaught — permanent maintenance lock
- **Severity:** MEDIUM — **Fix:** Wrap in try/catch, release lock on failure — **Effort:** S

#### P1-D2-072: withTransaction callback early return commits instead of rolling back
- **Severity:** MEDIUM — **Fix:** Use throw instead of return for errors — **Effort:** S

#### P1-D2-073: database.php logs to error_log, not gameLog — split log channels
- **Severity:** LOW — **Fix:** Add logError() alongside error_log() — **Effort:** XS

#### P1-D2-074: Log file grows unbounded between cron rotations
- **Severity:** LOW — **Fix:** Add file size check or configure logrotate — **Effort:** XS

#### P1-D2-075: Espionage report insert + action delete not in transaction
- **Severity:** MEDIUM — **Fix:** Wrap in withTransaction — **Effort:** XS

#### P1-D2-076: api.php dispatch exceptions uncaught — returns HTTP 200 with broken JSON
- **Severity:** MEDIUM — **Fix:** Wrap in try/catch, return 500 — **Effort:** XS

#### P1-D2-077: migrate.php misses intermediate statement errors in multi_query
- **Severity:** LOW — **Fix:** Check errors inside drain loop — **Effort:** S

#### P1-D2-078: constructions.php $erreur via reference in transaction doesn't trigger rollback
- **Severity:** MEDIUM — Cross-ref P1-D2-072 — **Effort:** S

#### P1-D2-079: inscrire() exception propagates uncaught through inscription.php
- **Severity:** MEDIUM — **Fix:** Wrap in try/catch — **Effort:** S

#### P1-D2-080: revenuEnergie() no guard against null $constructions return
- **Severity:** MEDIUM — **Fix:** Add null check with logError — **Effort:** S

---

## Area 5: Dead Code & Unused Features (18 findings)

#### P1-D2-081: 4 utility functions with zero call sites (imagePoints, couleur, logDebug, dbEscapeLike, dbLastId, slider)
- **Severity:** LOW — **Fix:** Delete or annotate — **Effort:** XS

#### P1-D2-082: RESOURCE_NAMES_ACCENTED identical to RESOURCE_NAMES
- **Severity:** LOW — **Fix:** Remove duplicate — **Effort:** S

#### P1-D2-083: $VICTORY_POINTS_PLAYER/$ALLIANCE arrays unused by game logic
- **Severity:** LOW — **Fix:** Remove from config.php — **Effort:** S

#### P1-D2-084: mise en forme.html and convertisseur.html — orphaned non-game files in web root
- **Severity:** MEDIUM — **Fix:** Delete both files — **Effort:** XS

#### P1-D2-085: Troll medal system is stub — $bonusTroll always returns 'Rien'
- **Severity:** LOW — **Fix:** Implement or remove — **Effort:** S

#### P1-D2-086: js/loader.js — self-hosted Google Charts loaded on every page (700KB)
- **Severity:** MEDIUM — **Fix:** Remove from copyright.php, delete file — **Effort:** S

#### P1-D2-087: comptetest.php uses duplicate diverging registration logic
- **Severity:** MEDIUM — **Fix:** Use validateLogin/validateEmail from validation.php — **Effort:** XS

#### P1-D2-088: 3 alliance research techs defined but effects never applied
- **Severity:** HIGH — **Fix:** Implement effects or remove from config/UI — **Effort:** M

#### P1-D2-089: condenseur_points specialization modifier defined but never applied
- **Severity:** HIGH — **Fix:** Apply modifier in point calculation — **Effort:** S

#### P1-D2-090: creerBBcode() stub ignores all parameters
- **Severity:** LOW — **Fix:** Remove dead params — **Effort:** XS

#### P1-D2-091: Multiple constantesBase.php arrays duplicate config.php without aliasing
- **Severity:** LOW — **Fix:** Use alias assignments — **Effort:** S (cross-refs P1-D2-038, P1-D2-046)

#### P1-D2-092: Commented-out HTML in layout.php creates orphaned closing divs in copyright.php
- **Severity:** MEDIUM — **Fix:** Audit and fix div structure — **Effort:** S

#### P1-D2-093: version.php shows V2.0.1.0 but game is at V4+
- **Severity:** LOW — **Fix:** Update version string — **Effort:** XS
