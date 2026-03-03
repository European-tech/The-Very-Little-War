# Mega Audit Findings Tracker

**Date:** 2026-03-03
**Scope:** 51 agent reports across 3 rounds (R1 primary scan, R2 deep-dive, R3 cross-domain correlation), 17 domains
**Total findings before dedup:** ~850+
**Total unique findings after dedup:** 198 (32 CRITICAL, 62 HIGH, 59 MEDIUM, 25 LOW, 20 QoL/IDEA)

---

## Deduplication Notes

Many findings were reported by multiple agents across rounds. The following major merges were performed:

| Merged Finding | Source IDs |
|---|---|
| C-001 (Season reset no transaction) | DATA-R1-001, DATA-R1-003, SEASON-CROSS-002, DATA-CROSS #2 |
| C-002 (Combat null dereferences) | ERR-R1-001, ERR-R2-001–005, COMBAT-CROSS CRASH-01–08 |
| C-003 (Attack launch no transaction) | DB-R1-002, DATA-CROSS #11 |
| C-004 (CSP unsafe-inline) | FE-R1-003, FE-R1-005, INFRA-R1-001, INFRA-R2-001, FE-R2-003, UX-CROSS P4-001 |
| C-005 (Admin session/auth) | ADMIN-R1-001, ADMIN-R1-002, ADMIN-R2-001, ADMIN-R2-002, SEC-R1-001, SEC-R2-001, AUTH-CROSS |
| C-006 (HSTS missing) | INFRA-R1-003, INFRA-R2-002, INFRA-CROSS |
| C-007 (Cookie secure conditional) | INFRA-R1-002, INFRA-R2-003 |
| C-010 (Double resource credit) | DB-R1-001, GAME-R1-001, SQL-CROSS MED-01 |
| C-016 (Invitation theft) | SOC-R1-001, SOC-R2-001, SOC-CROSS #2-3 |
| H-003 (revenuEnergie/revenuAtome N+1) | PERF-R1-002, PERF-R1-003, PERF-R2-001, PERF-R2-002, PERF-R2-007, PERF-CROSS |

---

## CRITICAL Findings (32) — Must Fix Before Launch

### C-001: Season reset (remiseAZero) has no transaction wrapper
- **Domain:** DATA / SEASON
- **Files:** player.php:793-838, basicprivatephp.php:134-297
- **Found by:** DATA-R1-001, DATA-R1-003, SEASON-CROSS-002, DATA-CROSS #2
- **Description:** 18+ destructive SQL statements (UPDATE/DELETE across all tables) execute without withTransaction(). Partial failure leaves database in mixed-season state. Players active during reset read inconsistent data.
- **Fix:** Wrap entire remiseAZero() body in withTransaction($base, function() use (...) { ... })
- **Status:** OPEN

### C-002: Combat null dereferences crash combat resolution permanently
- **Domain:** ERR / COMBAT
- **Files:** combat.php:5,15,28-35,41,43,48,55,360,427,557
- **Found by:** ERR-R1-001, ERR-R2-001–005, COMBAT-CROSS CRASH-01–12
- **Description:** 8+ dbFetchOne() calls in combat path return null when player/data is missing. Null → TypeError crashes combat mid-transaction, permanently losing attacker's troops with no report generated.
- **Fix:** Add null guards with throw RuntimeException before each dbFetchOne usage. Return troops on failure.
- **Status:** OPEN

### C-003: Attack launch has no transaction — molecule double-spend
- **Domain:** DB / DATA
- **Files:** attaquer.php:146-158
- **Found by:** DB-R1-002, DATA-CROSS #11
- **Description:** Per-molecule UPDATE loop, INSERT actionsattaques, energy deduction — all without transaction or FOR UPDATE. Concurrent submissions overdraft troops and energy.
- **Fix:** Wrap in withTransaction() with SELECT molecules FOR UPDATE.
- **Status:** OPEN

### C-004: CSP allows unsafe-inline — XSS protection defeated
- **Domain:** INFRA / FE / SEC
- **Files:** .htaccess:7
- **Found by:** FE-R1-003, FE-R1-005, INFRA-R1-001, INFRA-R2-001, FE-R2-003, UX-CROSS, INFRA-CROSS
- **Description:** Content-Security-Policy allows 'unsafe-inline' for both script-src and style-src. 76+ inline scripts need extraction to external files before CSP can be tightened.
- **Fix:** Phase 1: Add gstatic.com to script-src. Phase 2: Extract inline scripts to .js files. Phase 3: Remove unsafe-inline, add nonce-based CSP.
- **Status:** OPEN

### C-005: Admin/moderator share password and session namespace
- **Domain:** ADMIN / SEC / AUTH
- **Files:** admin/index.php:4,11, moderation/index.php:15-16, includes/session_init.php
- **Found by:** ADMIN-R1-001–002, ADMIN-R2-001–002, SEC-R1-001, SEC-R2-001–002, AUTH-CROSS
- **Description:** Single shared password for admin and moderator. No session_regenerate_id on admin login. No CSRF on admin login form. Admin and player sessions share same PHP namespace.
- **Fix:** Separate admin session namespace (session_name), add CSRF to admin login, add session_regenerate_id, create per-user admin accounts.
- **Status:** OPEN

### C-006: No HSTS header — SSL stripping possible
- **Domain:** INFRA
- **Files:** .htaccess
- **Found by:** INFRA-R1-003, INFRA-R2-002, INFRA-CROSS
- **Description:** No Strict-Transport-Security header. After HTTPS is enabled, browsers can still be downgraded to HTTP.
- **Fix:** Add `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"` after HTTPS setup.
- **Status:** OPEN (blocked on HTTPS)

### C-007: session.cookie_secure conditionally disabled
- **Domain:** INFRA / AUTH
- **Files:** includes/session_init.php:8
- **Found by:** INFRA-R1-002, INFRA-R2-003
- **Description:** cookie_secure only set when HTTPS detected. Sessions transmit over HTTP allowing hijacking.
- **Fix:** Hardcode session.cookie_secure=1 after HTTPS is live.
- **Status:** OPEN (blocked on HTTPS)

### C-008: Admin stored XSS via strip_tags
- **Domain:** SEC / ADMIN
- **Files:** admin/tableau.php:76,285-296,303,635
- **Found by:** ADMIN-R1-003, SEC-CROSS CHAIN-05
- **Description:** Database values rendered without htmlspecialchars in admin panel. strip_tags allows attribute-based XSS (e.g. `<img onerror=...>`). Admin news editor content reaches all players.
- **Fix:** Replace strip_tags with htmlspecialchars() on all admin outputs.
- **Status:** OPEN

### C-009: Build queue has no transaction — resource double-spend
- **Domain:** DATA
- **Files:** constructions.php:251-290
- **Found by:** DATA-CROSS #13
- **Description:** UPDATE ressources + INSERT actionsconstruction + UPDATE energieDepensee without transaction. Concurrent submissions double-deduct and insert duplicate queue entries.
- **Fix:** Wrap in withTransaction() with SELECT ressources FOR UPDATE.
- **Status:** OPEN

### C-010: Double resource credit via non-atomic tempsPrecedent update
- **Domain:** DB / GAME
- **Files:** includes/update.php:21 (dead file), game_resources.php:105-201
- **Found by:** DB-R1-001, GAME-R1-001, SQL-CROSS MED-01
- **Description:** updateTargetResources (in dead update.php) has no CAS guard. updateRessources has CAS on tempsPrecedent but moleculesPerdues loop has TOCTOU gap.
- **Fix:** Delete update.php (dead). Fix moleculesPerdues to use atomic UPDATE ... SET moleculesPerdues = moleculesPerdues + ? instead of SELECT+UPDATE.
- **Status:** OPEN

### C-011: Combat pre-transaction decay writes cause permanent molecule loss
- **Domain:** COMBAT / DATA
- **Files:** game_actions.php:94-103, combat.php
- **Found by:** DATA-CROSS #7, COMBAT-CROSS
- **Description:** Molecule decay loop runs OUTSIDE the manual BEGIN/COMMIT block. If transaction rolls back, decay is already committed = permanent molecule loss with no combat report.
- **Fix:** Move decay loop inside the transaction block.
- **Status:** OPEN

### C-012: Maintenance mode doesn't block game actions
- **Domain:** SEASON
- **Files:** basicprivatephp.php:299-307, attaquer.php, marche.php, constructions.php
- **Found by:** SEASON-CROSS-001, SEASON-CROSS-003
- **Description:** Maintenance check only sets a display variable ($erreur) but never exits or redirects. Players continue attacking, trading, building during reset.
- **Fix:** Add `if ($maintenance) { echo json_encode(['error' => 'maintenance']); exit; }` to all action processing blocks.
- **Status:** OPEN

### C-013: Admin password hash hardcoded in source-controlled file
- **Domain:** INFRA
- **Files:** includes/constantesBase.php:54
- **Found by:** INFRA-R1-004
- **Description:** Admin bcrypt hash in version-controlled PHP file. Anyone with repo access can crack it.
- **Fix:** Move to .env file loaded via getenv().
- **Status:** OPEN

### C-014: game_actions.php division by zero in formation processing
- **Domain:** ERR
- **Files:** game_actions.php:44,51
- **Found by:** ERR-R1-003
- **Description:** floor((...) / $actions['tempsPourUn']) divides by zero if tempsPourUn=0 in database.
- **Fix:** Add guard: if ($actions['tempsPourUn'] <= 0) { logError(...); continue; }
- **Status:** OPEN

### C-015: Undefined variables in combat report template
- **Domain:** ERR
- **Files:** game_actions.php:150-153,188
- **Found by:** ERR-R1-004
- **Description:** $attaquePts, $defensePts, $pillagePts, $pillagePts1 never assigned. Every combat report has PHP warnings and empty chipInfo outputs.
- **Fix:** Assign from combat resolution output before template usage.
- **Status:** OPEN

### C-016: Invitation acceptance has no ownership check — any player steals invitations
- **Domain:** SOC / SEC
- **Files:** alliance.php:116-134
- **Found by:** SOC-R1-001, SOC-R2-001, SOC-CROSS #2-3
- **Description:** DELETE/UPDATE on invitation by ID only. No check that invite == $_SESSION['login']. Any logged-in player can accept any invitation.
- **Fix:** Add WHERE invite = ? with $_SESSION['login'] to all invitation queries.
- **Status:** OPEN

### C-017: Season winner variable undefined when no players exist
- **Domain:** ERR / SEASON
- **Files:** basicprivatephp.php:155-170
- **Found by:** ERR-R1-005
- **Description:** $vainqueurManche undefined if classement query returns zero rows. Season reset crashes with fatal error.
- **Fix:** Initialize $vainqueurManche = null before loop; guard prestige/email with if ($vainqueurManche !== null).
- **Status:** OPEN

### C-018: Null dereference in auth guard on every private page
- **Domain:** ERR
- **Files:** basicprivatephp.php:75-77
- **Found by:** ERR-R1-006
- **Description:** dbFetchOne returns null if player deleted between session check and query. Line 77 accesses null['tempsPrecedent']. Crashes every page for deleted-but-session-active players.
- **Fix:** Add null guard: if (!$donnees) { session_destroy(); header('Location: index.php'); exit; }
- **Status:** OPEN

### C-019: Hardcoded HTTP URL in notification.js leaks GCM key
- **Domain:** FE
- **Files:** js/notification.js:56
- **Found by:** FE-R1-001
- **Description:** Push notification registration sends GCM key over plain HTTP. Mixed content error on HTTPS.
- **Fix:** Change to protocol-relative URL or HTTPS. Or remove dead push notification code entirely.
- **Status:** OPEN

### C-020: External scripts without SRI (Google Charts, CKEditor)
- **Domain:** FE / SEC
- **Files:** marche.php:560, sujet.php:223
- **Found by:** FE-R1-004, QOL-R2-022
- **Description:** Google Charts loader.js loaded without integrity attribute. CDN compromise = XSS on all market pages.
- **Fix:** Self-host loader.js or add integrity/crossorigin attributes.
- **Status:** OPEN

### C-021: Quadratic stat formulas create exponential snowball
- **Domain:** BAL
- **Files:** config.php:63-81
- **Found by:** BAL-R1-001
- **Description:** Attack/defense/pillage formulas are quadratic (N^2 terms). Combined with condenseur levels, creates exponential advantage that new players cannot overcome.
- **Fix:** Change formulas to linear or soft-logarithmic scaling. See BAL-CROSS for specific coefficient recommendations.
- **Status:** OPEN

### C-022: Iode energy production negligibly weak — dead atom type
- **Domain:** BAL
- **Files:** config.php:83-86
- **Found by:** BAL-R1-005, BAL-R2-006
- **Description:** Iode linear formula vs quadratic others = 20x weaker at high counts. No reason to ever use iode atoms.
- **Fix:** Buff iode formula or add unique iode-only mechanics. See BAL-CROSS recommendations.
- **Status:** OPEN

### C-023: Decay formula exponential-on-exponential kills molecule diversity
- **Domain:** BAL
- **Files:** config.php:101-104
- **Found by:** BAL-R1-006
- **Description:** Molecule decay is so aggressive that large diverse armies are impossible. Only single-class stacking is viable.
- **Fix:** Reduce decay exponent or add a soft cap. See BAL-CROSS.
- **Status:** OPEN

### C-024: Building destruction is pure RNG — no strategic counterplay
- **Domain:** BAL
- **Files:** combat.php:438-468
- **Found by:** BAL-R1-007
- **Description:** Which building gets destroyed in combat is purely random. No way to protect key buildings.
- **Fix:** Add targeting priority or building protection mechanics.
- **Status:** OPEN

### C-025: Attacker damage uses defender's isotope modifier (combat formula bug)
- **Domain:** BAL / GAME
- **Files:** combat.php:206
- **Found by:** BAL-R1-004
- **Description:** Damage calculation uses wrong variable — defender's isotope attack modifier applied to attacker's damage.
- **Fix:** Use $isotopeAttaquant instead of $isotopeDefenseur in attacker damage formula.
- **Status:** OPEN

### C-026: ISO-8859-1 charset in admin page
- **Domain:** I18N
- **Files:** admin/listesujets.php:8
- **Found by:** I18N-R1-001, I18N-R2-001
- **Description:** Admin page declares ISO-8859-1 while all other pages use UTF-8. French characters corrupt.
- **Fix:** Change to UTF-8 charset declaration.
- **Status:** OPEN

### C-027: Database charset utf8 instead of utf8mb4
- **Domain:** I18N / SCHEMA
- **Files:** includes/connexion.php:20
- **Found by:** I18N-R1-003, SCHEMA-CROSS SC-009
- **Description:** Connection uses 'utf8' (3-byte). Mixed charsets (latin1, utf8, utf8mb4) prevent index use on JOINs and corrupt accented characters.
- **Fix:** SET NAMES utf8mb4. Migrate all tables to utf8mb4.
- **Status:** OPEN

### C-028: Zero foreign key constraints in entire schema
- **Domain:** SCHEMA
- **Files:** All tables
- **Found by:** SCHEMA-CROSS SC-001
- **Description:** No FK constraints exist. All referential integrity enforced in PHP. supprimerJoueur runs 11+ DELETEs — mid-failure leaves orphans.
- **Fix:** Add ON DELETE CASCADE FKs for critical relationships (membre→autre, membre→ressources, etc).
- **Status:** OPEN

### C-029: actionsformation.idclasse type mismatch (INT stores string 'neutrino')
- **Domain:** SCHEMA
- **Files:** actionsformation table, armee.php
- **Found by:** SCHEMA-CROSS SC-003
- **Description:** Column is INT(11) but code stores 'neutrino' string literal. INT receives 'neutrino' → silently stores 0. Neutrino formation path unreliable.
- **Fix:** ALTER TABLE actionsformation MODIFY idclasse VARCHAR(50).
- **Status:** OPEN

### C-030: No prestige.php page — entire progression system unreachable
- **Domain:** QOL / GAME
- **Files:** includes/prestige.php (functions exist, no UI)
- **Found by:** QOL-R2-046, QOL-R2-031
- **Description:** 5 unlocks implemented, purchasePrestigeUnlock() works, PP awards exist. Zero UI. Players cannot interact with the prestige system.
- **Fix:** Create prestige.php page with PP display, unlock shop, and history.
- **Status:** OPEN

### C-031: BBCode [url=] ReDoS via nested quantifiers
- **Domain:** INP / SEC
- **Files:** bbcode.php:331
- **Found by:** INP-R2-003
- **Description:** URL regex has nested quantifiers that cause catastrophic backtracking on crafted input. DoS vector.
- **Fix:** Rewrite regex with atomic grouping or possessive quantifiers.
- **Status:** OPEN

### C-032: Medal bonuses carry across seasons — unbreakable veteran advantage
- **Domain:** BAL
- **Files:** config.php (medal system), combat.php
- **Found by:** BAL-R2-010
- **Description:** Diamond+ medal holders from season 1 get 30-50% combat multiplier in season 2. New players cannot compete.
- **Fix:** Reset medal bonuses each season, or cap cross-season bonus at 10%.
- **Status:** OPEN

---

## HIGH Findings (62)

### H-001: Return trip molecule double-credit race condition
- **Files:** game_actions.php:446-468
- **Found by:** SEC-R2-002, DATA-CROSS #8
- **Fix:** Wrap in withTransaction() with FOR UPDATE on molecules.

### H-002: Formation completion double-processing (no CAS guard)
- **Files:** game_actions.php:26-31, 40-60
- **Found by:** DATA-CROSS #4-6
- **Fix:** Add CAS guard on actionsformation/actionsconstruction before processing.

### H-003: revenuEnergie/revenuAtome N+1 queries (285-351 queries/page)
- **Files:** game_resources.php:7-63, 146-154
- **Found by:** PERF-R1-002–003, PERF-R2-001–002, PERF-CROSS
- **Fix:** Add static cache to revenuEnergie() and revenuAtome(). Single query for all atoms.

### H-004: coefDisparition() makes 3 DB queries per call in loops
- **Files:** formulas.php:175-226
- **Found by:** PERF-R1-004
- **Fix:** Cache result per request.

### H-005: Online tracking writes on every page load
- **Files:** basicprivatephp.php:75-89
- **Found by:** PERF-R1-005
- **Fix:** Throttle to once per 60 seconds using session timestamp.

### H-006: Double include of constantes.php triggers initPlayer() twice
- **Files:** basicprivatephp.php:60,104
- **Found by:** PERF-R1-001
- **Fix:** Use include_once or guard with defined() check.

### H-007: augmenterBatiment/diminuerBatiment invalidate wrong player's cache
- **Files:** player.php:538,619
- **Found by:** GAME-R1-002
- **Fix:** Use target player's login for cache invalidation, not $_SESSION['login'].

### H-008: Soufre pillage formula permanently handicapped (S/3 divisor)
- **Files:** config.php:78-80
- **Found by:** BAL-R1-002, BAL-R2-007
- **Fix:** Remove /3 divisor or increase soufre base coefficient.

### H-009: Chlore speed formula gives diminishing returns — dominant must-have
- **Files:** formulas.php:155-158
- **Found by:** BAL-R1-003
- **Fix:** Linear or soft-cap speed formula.

### H-010: Embuscade/Dispersée/Phalange formation balance issues
- **Files:** config.php formation definitions
- **Found by:** BAL-R1-008–014, BAL-R2-013, BAL-CROSS
- **Fix:** Rebalance formation multipliers per BAL-CROSS recommendations.

### H-011: Resource send has no transaction — overdraft possible
- **Files:** marche.php:95-115
- **Found by:** DATA-CROSS #20, MARKET-CROSS D3-SEND
- **Fix:** Wrap in withTransaction() with SELECT ressources FOR UPDATE.

### H-012: Espionage launch — neutrino double-spend
- **Files:** attaquer.php:36-39
- **Found by:** DATA-CROSS #12
- **Fix:** Wrap in withTransaction() with SELECT autre FOR UPDATE.

### H-013: Neutrino formation — energy double-spend
- **Files:** armee.php:70-76
- **Found by:** DATA-CROSS #16
- **Fix:** Wrap in withTransaction().

### H-014: Molecule formation queue — atom double-spend
- **Files:** armee.php:118-139
- **Found by:** DATA-CROSS #17
- **Fix:** Wrap in withTransaction() with SELECT ressources FOR UPDATE.

### H-015: Molecule class creation — niveauclasse double-increment
- **Files:** armee.php:191-220
- **Found by:** DATA-CROSS #14
- **Fix:** Wrap in withTransaction().

### H-016: Alliance research upgrade — double upgrade at single cost
- **Files:** alliance.php:106-108
- **Found by:** DATA-CROSS #25
- **Fix:** Wrap in withTransaction() with SELECT alliances FOR UPDATE.

### H-017: ajouterPoints has no lock — concurrent lost updates on points
- **Files:** player.php:73-106
- **Found by:** DATA-CROSS #35
- **Fix:** Use atomic UPDATE autre SET points = points + ?, totalPoints = totalPoints + ? instead of SELECT+UPDATE.

### H-018: supprimerJoueur missing cleanup (prestige, sanctions, sondages)
- **Files:** player.php:752-778
- **Found by:** DATA-R1-002, SCHEMA-CROSS SC-005
- **Fix:** Add DELETE from prestige, sanctions, reponses inside the transaction.

### H-019: comptetest.php visitor rename missing table updates
- **Files:** comptetest.php:66-83
- **Found by:** DATA-R1-004
- **Fix:** Add prestige, attack_cooldowns, vacances to rename transaction.

### H-020: supprimerAlliance missing attack_cooldowns cleanup
- **Files:** player.php:739-750
- **Found by:** DATA-R1-005
- **Fix:** Add DELETE attack_cooldowns WHERE login IN (SELECT login FROM autre WHERE idalliance=?) inside transaction.

### H-021: Empty login registration possible
- **Files:** comptetest.php:51
- **Found by:** INP-R1-001
- **Fix:** Change regex to require minimum 3 characters.

### H-022: Vacation date parsed without format validation
- **Files:** compte.php:14
- **Found by:** INP-R1-002
- **Fix:** Validate with DateTime::createFromFormat and reject invalid dates.

### H-023: No minimum password length at registration
- **Files:** inscription.php, compte.php
- **Found by:** INP-R2-001, SEC-R2-003
- **Fix:** Require minimum 8 characters.

### H-024: Forum post content has no length limit
- **Files:** sujet.php:21, listesujets.php:31
- **Found by:** INP-R2-004
- **Fix:** Add mb_strlen check, reject posts > 10000 chars.

### H-025: BBCode [img] enables CSRF via external images
- **Files:** bbcode.php:332
- **Found by:** SOC-R2-003, SEC-CROSS CHAIN-01, XSS-CROSS XSS-11
- **Fix:** Proxy external images or restrict to self-hosted only.

### H-026: Grade name stored XSS via alliance admin
- **Files:** allianceadmin.php
- **Found by:** SEC-CROSS CHAIN-02
- **Fix:** Apply htmlspecialchars() to grade name on output.

### H-027: Alliance admin access with stale grades after quit
- **Files:** allianceadmin.php:17-24
- **Found by:** SOC-R2-002, SOC-CROSS #24
- **Fix:** Clear grades on alliance quit. Add idalliance check to allianceadmin.php.

### H-028: Pact acceptance form lacks CSRF token
- **Files:** allianceadmin.php:214-219
- **Found by:** SOC-R1-002
- **Fix:** Add csrfCheck() to pact acceptance/rejection.

### H-029: Market resource transfer destinataire not validated
- **Files:** marche.php
- **Found by:** INP-R2-005
- **Fix:** Verify recipient exists via dbFetchOne before processing send.

### H-030: Scripts loaded after closing </html> tag
- **Files:** includes/copyright.php:20-29
- **Found by:** FE-R1-002, FE-R2-001–002
- **Fix:** Move scripts before </body> tag. Remove dead Cordova/AES scripts.

### H-031: Dead Cordova/AES/jcryption scripts loaded on every page
- **Files:** includes/copyright.php:21-29
- **Found by:** FE-R2-001–002
- **Fix:** Remove entirely.

### H-032: Corrupted double-encoded character in date format string
- **Files:** includes/menus.php:30
- **Found by:** I18N-R1-002
- **Fix:** Fix the UTF-8 encoding of the date format string.

### H-033: Missing charset declaration in moderation panel
- **Files:** moderation/index.php:150-166
- **Found by:** I18N-R2-002
- **Fix:** Add <meta charset="UTF-8">.

### H-034: Stored XSS via message titles (debutCarte renders raw HTML)
- **Files:** messages.php:28, ui_components.php:16-19
- **Found by:** XSS-CROSS XSS-03, XSS-17
- **Fix:** Add htmlspecialchars() inside debutCarte() itself.

### H-035: PHP-to-JS injection without json_encode
- **Files:** basicprivatehtml.php:433-459
- **Found by:** XSS-CROSS XSS-06
- **Fix:** Use json_encode() for all PHP→JS value injection.

### H-036: Alliance data rendered without escaping
- **Files:** alliance.php:196,209,212,224,227,432
- **Found by:** XSS-CROSS XSS-02
- **Fix:** Apply htmlspecialchars() to all alliance data outputs.

### H-037: Alliance research column name from user POST
- **Files:** alliance.php:94-113
- **Found by:** SQL-CROSS HIGH-01
- **Fix:** Validate against ALLIANCE_RESEARCH_COLUMNS whitelist.

### H-038: Raw column interpolation in game_resources.php
- **Files:** game_resources.php:152-158
- **Found by:** SQL-CROSS CRIT-02
- **Fix:** Add in_array($col, ALLOWED_RESOURCE_COLUMNS) guard.

### H-039: Raw column interpolation in combat.php
- **Files:** combat.php:579-612
- **Found by:** SQL-CROSS HIGH-05
- **Fix:** Add whitelist guard on $nomsRes columns.

### H-040: Market SET clause via raw string concatenation
- **Files:** marche.php:100-115
- **Found by:** SQL-CROSS HIGH-02
- **Fix:** Use parameterized UPDATE with column whitelist.

### H-041: constructions.php raw-concatenation UPDATE
- **Files:** constructions.php:240-263
- **Found by:** SQL-CROSS HIGH-03
- **Fix:** Use parameterized UPDATE with column whitelist.

### H-042: game_actions.php resource delivery column interpolation
- **Files:** game_actions.php:528-538
- **Found by:** SQL-CROSS HIGH-04
- **Fix:** Add whitelist guard.

### H-043: N+1 moleculesPerdues SELECT+UPDATE in combat/resource loops
- **Files:** game_actions.php, game_resources.php
- **Found by:** SQL-CROSS MED-03–04
- **Fix:** Accumulate in variable, single atomic UPDATE at end.

### H-044: connectes table has no primary key
- **Files:** connectes table
- **Found by:** SCHEMA-CROSS SC-004
- **Fix:** Add PRIMARY KEY or UNIQUE index.

### H-045: prestige.login VARCHAR(50) vs VARCHAR(255) everywhere else
- **Files:** prestige table
- **Found by:** SCHEMA-CROSS SC-002
- **Fix:** ALTER TABLE prestige MODIFY login VARCHAR(255) NOT NULL.

### H-046: /migrations/ directory web-accessible
- **Files:** migrations/
- **Found by:** INFRA-CROSS D-01
- **Fix:** Add <Directory> deny in Apache vhost or .htaccess in migrations/.

### H-047: partenariat.php loads scripts over plain HTTP
- **Files:** includes/partenariat.php:9-11
- **Found by:** INFRA-CROSS D-07
- **Fix:** Remove dead file entirely (FILE-INVENTORY confirms orphaned).

### H-048: Email loop timeout can lock game in maintenance mode
- **Files:** basicprivatephp.php:292
- **Found by:** SEASON-CROSS HIGH
- **Fix:** Set maintenance=0 BEFORE email loop, not after.

### H-049: Admin reset button calls remiseAZero without VP/prestige/archiving
- **Files:** admin.php
- **Found by:** SEASON-CROSS HIGH
- **Fix:** Admin reset should use full season-end flow, not bare remiseAZero().

### H-050: Missing column resets in remiseAZero
- **Files:** player.php:793-838
- **Found by:** SEASON-CROSS HIGH
- **Fix:** Add resets for molecules.isotope, constructions.spec_combat/spec_economy/spec_research.

### H-051: cours/news/connectes/vacances/grades tables not cleared on season reset
- **Files:** player.php:793-838
- **Found by:** SEASON-CROSS HIGH
- **Fix:** Add DELETE/TRUNCATE for stale season data.

### H-052: Rankings not frozen before VP awards — concurrent shifts
- **Files:** basicprivatephp.php
- **Found by:** SEASON-CROSS HIGH
- **Fix:** SELECT rankings into temp table before awarding VP.

### H-053: Ionisateur non-damageable vs champdeforce degradable — permanent offense advantage
- **Files:** combat.php
- **Found by:** BAL-R2-003
- **Fix:** Either make both damageable or neither.

### H-054: Producteur drain can create negative energy at startup
- **Files:** game_resources.php
- **Found by:** BAL-R2-001
- **Fix:** Floor energy at 0 after production calculation.

### H-055: Formation time uses uninitialized $niveauazote = 0
- **Files:** armee.php:116
- **Found by:** GAME-R2-002
- **Fix:** Fetch actual azote level from constructions.

### H-056: Return trip processes even when attack not yet complete
- **Files:** game_actions.php:446
- **Found by:** GAME-R2-001
- **Fix:** Add elseif instead of if on return-trip branch.

### H-057: Array index out of bounds on troupes string mismatch
- **Files:** game_actions.php:95,457
- **Found by:** ERR-R1-002
- **Fix:** Validate explode count matches nbClasses before array access.

### H-058: N+1 queries in forum/subject/message loops
- **Files:** forum.php, listesujets.php, sujet.php
- **Found by:** SQL-CROSS MED-06–09
- **Fix:** Batch queries outside loops.

### H-059: No post-login redirect to tutorial for first-time players
- **Files:** index.php, basicprivatephp.php
- **Found by:** QOL-R2-001
- **Fix:** Add redirect in basicprivatephp.php when niveaututo==1.

### H-060: Three competing tutorial systems with no clear primary path
- **Files:** tutoriel.php, basicprivatehtml.php, cardsprivate.php
- **Found by:** QOL-R2-003
- **Fix:** Serialize tutorials; hide missions until niveaututo >= 10.

### H-061: Timer completion auto-reloads destroy player context
- **Files:** constructions.php:317-328, armee.php:282-300
- **Found by:** QOL-R2-009
- **Fix:** Use AJAX poll instead of full page reload.

### H-062: No navigation badges for pending completions
- **Files:** menu system
- **Found by:** QOL-R2-038
- **Fix:** Add badge counts using initPlayer() data.

---

## MEDIUM Findings (59)

| # | ID | Domain | File | Description | Fix |
|---|---|---|---|---|---|
| M-001 | DATA-CROSS #15 | DATA | armee.php:12-53 | Molecule deletion not atomic | Wrap in withTransaction |
| M-002 | DATA-CROSS #18-19 | DATA | marche.php | Market buy/sell autre not locked in transaction | Add FOR UPDATE on autre |
| M-003 | DATA-CROSS #22 | DATA | alliance.php:44-49 | Alliance creation not atomic | Wrap in withTransaction |
| M-004 | DATA-CROSS #23 | DATA | alliance.php:126-130 | Alliance join not atomic, can exceed cap | Wrap in withTransaction |
| M-005 | DATA-CROSS #28 | DATA | allianceadmin.php:263-269 | War declaration not atomic | Wrap in withTransaction |
| M-006 | DATA-CROSS #34 | DATA | player.php:542-621 | diminuerBatiment ajouterPoints not locked | Use atomic point update |
| M-007 | DATA-CROSS #36 | DATA | player.php:623-682 | Coordinate collision on concurrent registration | Add FOR UPDATE on statistiques |
| M-008 | DATA-CROSS #38 | DATA | validerpacte.php:20-24 | Pact accept check-then-act | Wrap in withTransaction |
| M-009 | QOL-R2-004 | QOL | tutoriel.php:91-106 | Mission 5 conflates profile editing and market | Split into two missions |
| M-010 | QOL-R2-005 | QOL | tutoriel.php:113-114 | Espionage mission uses fragile LIKE pattern | Check actionsattaques table directly |
| M-011 | QOL-R2-006 | QOL | tutoriel.php:15-150 | All seven tutorial rewards identical (500) | Escalating rewards |
| M-012 | QOL-R2-008 | GAME | constructions.php:162-166 | No bottleneck identification in build cost | Show which resource is bottleneck |
| M-013 | QOL-R2-011 | CODE | armee.php:354-360 | Molecule count ceil() vs formation floor() mismatch | Use floor() consistently |
| M-014 | QOL-R2-012 | FE | attaquer.php:435-490 | Attack cost calculator doesn't update on clear | Always call actualiseTemps() |
| M-015 | QOL-R2-013 | GAME | attaquer.php:247-249 | Incoming attack no time estimate | Show tempsRetour |
| M-016 | QOL-R2-014 | FE | rapports.php:63-73 | Broken HTML (missing <tr> tags) | Fix table markup |
| M-017 | QOL-R2-016 | SOC | alliance.php:264-320 | No mechanism to communicate donation goals | Add alliance_message column |
| M-018 | QOL-R2-017 | UX | alliance.php:291-318 | Research effects hidden inside accordion | Show bonus in collapsed subtitle |
| M-019 | QOL-R2-018 | I18N | alliance.php:271-278 | Duplicateur description copy-paste error | Fix text |
| M-020 | QOL-R2-019 | PERF | alliance.php:170-178 | Alliance rank uses O(n) PHP loop | Use SQL COUNT |
| M-021 | QOL-R2-020 | UX | alliance.php | No war/pact buttons on alliance view | Add quick-action links |
| M-022 | QOL-R2-023 | UX | marche.php:577-592 | Market chart X-axis has no timestamps | Add date() to labels |
| M-023 | QOL-R2-024 | UX | marche.php:255-363 | No post-sale price preview | JS preview formula |
| M-024 | QOL-R2-025 | UX | marche.php:372-409 | In-transit no resource breakdown | Parse ressourcesEnvoyees |
| M-025 | QOL-R2-028 | UX | medailles.php:49-50 | Medal progress no visual bar | Call existing progressBar() |
| M-026 | QOL-R2-029 | GAME | medailles.php:42-43 | Explosif/Aléatoire medals unexplained | Add description strings |
| M-027 | QOL-R2-030 | UX | classement.php:163-165 | PP column unexplained | Link to prestige.php |
| M-028 | QOL-R2-032 | UX | classement.php:157-164 | Points column not clickable for sort | Add href |
| M-029 | QOL-R2-033 | UX | all pages | Season end countdown absent | Add header countdown |
| M-030 | QOL-R2-034 | UX | compte.php:151-161 | Password change no current email shown | Show email |
| M-031 | QOL-R2-035 | UX | compte.php:188-195 | Vacation mode no benefit explanation | Add benefit list |
| M-032 | QOL-R2-039 | GAME | armee.php:321-335 | Isotope system never displayed | Show isotope label |
| M-033 | QOL-R2-040 | GAME | config.php:312-344 | Chemical reactions defined but never activate | Phase 1: display. Phase 2: implement |
| M-034 | QOL-R2-041 | GAME | config.php:570-629 | Specialization system has no UI | Add to constructions.php |
| M-035 | QOL-R2-042 | PERF | attaquer.php:329-365 | Map renders all N×N tiles including empty | CSS grid with occupied only |
| M-036 | SQL-CROSS MED-02 | DATA | voter.php | Vote insert race condition | Use ON DUPLICATE KEY UPDATE |
| M-037 | SQL-CROSS MED-05 | PERF | alliance.php | Alliance rank full PHP scan | SQL COUNT |
| M-038 | SQL-CROSS MED-08 | PERF | allianceadmin.php | Same query executed 3 times | Cache result |
| M-039 | SCHEMA SC-007 | SCHEMA | declarations table | alliance1/2 VARCHAR→INT migration verify | Check live VPS data |
| M-040 | SCHEMA SC-010 | PERF | autre table | totalPoints has no index | Add INDEX |
| M-041 | SCHEMA | SCHEMA | ressources table | DOUBLE columns for resources | Consider INT + scaling |
| M-042 | SCHEMA | SCHEMA | constructions table | Serialized VARCHAR arrays unqueryable | Document limitation |
| M-043 | SCHEMA | SCHEMA | cours table | No TTL cleanup strategy | Add cleanup job |
| M-044 | SCHEMA | SCHEMA | vacances table | idJoueur references membre.id (inconsistent) | Refactor to use login |
| M-045 | SCHEMA | SCHEMA | sanctions table | VARCHAR(30) vs VARCHAR(255) | Widen columns |
| M-046 | XSS-01 | SEC | attaquer.php:207,209,248 | Unescaped player names | htmlspecialchars() |
| M-047 | XSS-05 | SEC | admin/tableau.php:76 | Admin tableau name unescaped | htmlspecialchars() |
| M-048 | ERR-R1-007 | ERR | combat.php:472-499 | Division by zero in pointsDeVie if building level 0 | Guard against 0 |
| M-049 | ERR-R1-008 | ERR | update.php:17-18 | Null dereference on deleted target player | Add null guard |
| M-050 | DEAD-CODE #1-7 | CODE | 7 dead files | annonce.php, video.php, update.php, cardspublic.php, mots.php, menus.php, partenariat.php | Delete files |
| M-051 | DEAD-CODE #8-20 | CODE | Various | 20 dead code blocks | Remove dead code |
| M-052 | TEST-CROSS | TEST | tests/ | Effective coverage ~25-30%, combat tests use inline math | Fix tests to call real functions |
| M-053 | FE-R1-006–011 | FE | Various | jQuery UI Theme loaded twice, multiple CSS duplicates | Remove duplicates |
| M-054 | I18N various | I18N | Various | 45+ hardcoded French strings | Extract to i18n constants |
| M-055 | INFRA-CROSS | INFRA | Various | 76 inline <script> blocks need extraction | Phase 2 of CSP roadmap |
| M-056 | UX-CROSS | UX | Various | submit() JS function pattern across 15+ forms | Replace with button type=submit |
| M-057 | QOL-R2-010 | UX | armee.php:340-445 | Army overview requires ?sub=1 with no button | Add tab switcher |
| M-058 | QOL-R2-026 | UX | marche.php:460-465 | Buy form field no label | Add placeholder |
| M-059 | QOL-R2-037 | CODE | index.php:3, classement.php:2 | $_SESSION['start'] dead code | Remove |

---

## LOW Findings (25)

| # | Domain | Description |
|---|---|---|
| L-001 | QOL | Tutorial "Comprendre le jeu" duplicates niveaututo content |
| L-002 | QOL | Delete-all reports button mixed with pagination |
| L-003 | QOL | Donation percentage column no unit label |
| L-004 | QOL | Market sell success hides fee amount |
| L-005 | QOL | Viewing rival medals no comparison to own |
| L-006 | QOL | War history includes trivial 1-molecule wars |
| L-007 | QOL | Active defensive formation minimal visual distinction |
| L-008 | QOL | Account deletion uses image-only buttons |
| L-009 | QOL | Login form renders below cards on mobile |
| L-010 | QOL | Report list delete button placement |
| L-011 | ERR | Various LOW error handling items (10+ from ERR reports) |
| L-012 | FE | jQuery UI loaded from CDN without fallback |
| L-013 | FE | Multiple console.log statements in production |
| L-014 | I18N | Non-breaking spaces inconsistently used |
| L-015 | SCHEMA | Redundant idx_constructions_login index (PK already on login) |
| L-016 | SCHEMA | rapports.contenu LONGTEXT where TEXT suffices |
| L-017 | CODE | constantesBase.php could merge into config.php |
| L-018 | CODE | Legacy MD5 session migration block removable after 30d uptime |
| L-019 | SQL | listesujets.php post-INSERT subject ID by content not dbLastId() |
| L-020 | XSS | video.php iframe src no protocol validation (dead file) |
| L-021 | XSS | urlencode vs htmlspecialchars inconsistency in joueur.php |
| L-022 | XSS | Raw $erreur echo in moderation/index.php |
| L-023 | XSS | strip_tags attribute XSS in rapports.php |
| L-024 | BAL | Various minor balance tweaks (10+ LOW items from BAL reports) |
| L-025 | DATA | Various LOW data integrity items |

---

## QoL / IDEA Findings (20)

| # | Source | Description |
|---|---|---|
| QOL-001 | QOL/IDEA R1+R2 | Prestige system UI (prestige.php page) |
| QOL-002 | QOL/IDEA | Chemical reactions combat system activation |
| QOL-003 | QOL/IDEA | Specialization system UI |
| QOL-004 | QOL/IDEA | Season countdown in header |
| QOL-005 | QOL/IDEA | Navigation badges for pending completions |
| QOL-006 | QOL/IDEA | Medal progress bars |
| QOL-007 | QOL/IDEA | Market chart timestamps |
| QOL-008 | QOL/IDEA | Alliance message system |
| QOL-009 | QOL/IDEA | Isotope display system |
| QOL-010 | QOL/IDEA | Attack cost preview |
| QOL-011 | QOL/IDEA | Army tab switcher |
| QOL-012 | QOL/IDEA | Vacation mode benefits explanation |
| QOL-013 | QOL/IDEA | Alliance research bonuses visible |
| QOL-014 | QOL/IDEA | Password change shows email |
| QOL-015 | QOL/IDEA | Tutorial reward escalation |
| QOL-016 | QOL/IDEA | Build bottleneck identification |
| QOL-017 | QOL/IDEA | In-transit resource breakdown |
| QOL-018 | QOL/IDEA | Post-sale price preview |
| QOL-019 | QOL/IDEA | Map optimization (occupied tiles only) |
| QOL-020 | IDEA R1+R2 | 20+ new feature proposals from IDEA agents |

---

## Statistics

| Metric | Count |
|---|---|
| Round 1 reports | 17 |
| Round 2 reports | 17 |
| Round 3 reports | 17 |
| Total agent reports | 51 |
| R1 findings (raw) | ~680 |
| R2 findings (raw) | ~580 |
| R3 findings (raw) | ~300 |
| **Total before dedup** | **~1560** |
| **Total after dedup** | **198** |
| Duplicates removed | ~1362 |
| CRITICAL | 32 |
| HIGH | 62 |
| MEDIUM | 59 |
| LOW | 25 |
| QoL/IDEA | 20 |

---

## Verification Checklist

After remediation, verify each finding is addressed:

| Category | Count | Verified |
|---|---|---|
| CRITICAL security (C-003–008, C-013, C-016, C-020, C-031) | 10 | [ ] |
| CRITICAL data integrity (C-001, C-009–012, C-017–018) | 7 | [ ] |
| CRITICAL game bugs (C-002, C-014–015, C-025) | 4 | [ ] |
| CRITICAL balance (C-021–024, C-032) | 5 | [ ] |
| CRITICAL infrastructure (C-004, C-006–007, C-026–029) | 6 | [ ] |
| HIGH security (H-016, H-021–029, H-034–042) | 18 | [ ] |
| HIGH data integrity (H-001–002, H-011–020, H-043–052) | 20 | [ ] |
| HIGH performance (H-003–006, H-058) | 5 | [ ] |
| HIGH game/balance (H-007–010, H-053–057) | 9 | [ ] |
| HIGH UX/QoL (H-059–062) | 4 | [ ] |
| HIGH infrastructure (H-030–033, H-044–047) | 6 | [ ] |
| MEDIUM (all) | 59 | [ ] |
| LOW (all) | 25 | [ ] |
