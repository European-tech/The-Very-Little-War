# Remediation Plan — Ultra Audit Pass 1
**Date:** 2026-03-07
**Total findings:** 124 (2 critical, 27 high, 57 medium, 38 low)
**Deduplicated from:** 133 raw findings across 12 domains
**Deduplication notes:**
- Market MEDIUM-001, Market MEDIUM-002, Economy MEDIUM-003 merged into PASS1-MEDIUM-018 (stale market prices outside transaction)
- Economy MEDIUM-004 (trade volume atomic) and Market note on tradeVolume treated as one: PASS1-MEDIUM-019
- Economy LOW-001 (concurrent price erase) is a sub-aspect of PASS1-MEDIUM-018, noted there
- Domain 9 LOW-009 and Domain 7 LOW-001 are design decisions — retained as LOW no-fix notes

**Batches:** 21 batches, estimated 21 commits

---

## Summary Table

| ID | Severity | Title | File(s) | Batch |
|---|---|---|---|---|
| PASS1-CRITICAL-001 | CRITICAL | connexion.php charset utf8mb4 vs latin1 DB | includes/connexion.php | 0 |
| PASS1-CRITICAL-002 | CRITICAL | Migration 0026 duplicate index halts runner | migrations/0026_add_totalpoints_index.sql | 0 |
| PASS1-HIGH-001 | HIGH | attack_cooldowns table charset utf8mb4 breaks FK | migrations/0004_add_attack_cooldowns.sql | 1 |
| PASS1-HIGH-002 | HIGH | login_history table charset utf8mb4 and no FK | migrations/0020_create_login_history.sql | 1 |
| PASS1-HIGH-003 | HIGH | account_flags / admin_alerts charset utf8mb4 and no FKs | migrations/0021_create_account_flags.sql | 1 |
| PASS1-HIGH-004 | HIGH | resource_nodes table charset utf8mb4 | migrations/0023_create_resource_nodes.sql | 1 |
| PASS1-HIGH-005 | HIGH | prestige table created without latin1 charset | migrations/0007_add_prestige_table.sql | 1 |
| PASS1-HIGH-006 | HIGH | reponses_sondage no FK to sondages or membre | migrations/0032_create_reponses_sondage.sql | 2 |
| PASS1-HIGH-007 | HIGH | attack_cooldowns.defender missing FK constraint | migrations/0018_add_foreign_keys.sql | 2 |
| PASS1-HIGH-008 | HIGH | grades table has no primary key | (no migration) | 2 |
| PASS1-HIGH-009 | HIGH | statutforum table has no primary key | (no migration) | 2 |
| PASS1-HIGH-010 | HIGH | Migration 0015 has INSERT INTO migrations with wrong schema | migrations/0015_fix_schema_issues.sql | 2 |
| PASS1-HIGH-011 | HIGH | $_SESSION['login'] injected unescaped into JavaScript | includes/layout.php | 3 |
| PASS1-HIGH-012 | HIGH | Inline onclick handlers blocked by CSP | sujet.php, bilan.php | 3 |
| PASS1-HIGH-013 | HIGH | html lang attribute missing on all pages | includes/layout.php | 3 |
| PASS1-HIGH-014 | HIGH | Alliance tag unescaped in pacte/guerre tables | allianceadmin.php | 3 |
| PASS1-HIGH-015 | HIGH | War end notification unescaped alliance tag | allianceadmin.php | 3 |
| PASS1-HIGH-016 | HIGH | supprimerJoueur() misses DELETE FROM player_compounds | includes/player.php | 4 |
| PASS1-HIGH-017 | HIGH | Comeback bonus depot read without FOR UPDATE | includes/player.php | 4 |
| PASS1-HIGH-018 | HIGH | Login streak PP not shown in prestige.php display | prestige.php, includes/player.php | 4 |
| PASS1-HIGH-019 | HIGH | classement.php includes dead/inactive players (x=-1000) | classement.php | 4 |
| PASS1-HIGH-020 | HIGH | Alliance tag change allows 1-char tag (bypasses minimum) | allianceadmin.php | 4 |
| PASS1-HIGH-021 | HIGH | MathJax loaded on ALL topic pages unconditionally | sujet.php, includes/bbcode.php | 5 |
| PASS1-HIGH-022 | HIGH | Hardcoded admin username for global broadcast | ecriremessage.php | 5 |
| PASS1-HIGH-023 | HIGH | Message reply fetches content before auth check | ecriremessage.php | 5 |
| PASS1-HIGH-024 | HIGH | Misleading comment: says +50% but code is +20% | includes/formulas.php | 5 |
| PASS1-HIGH-025 | HIGH | Isotope stable HP described +30% but code is +40% | docs / comments | 5 |
| PASS1-HIGH-026 | HIGH | membre lookup via secondary index, no clustered PK | original schema | DB-DOC |
| PASS1-HIGH-027 | HIGH | Migration 0009 INSERT uses wrong schema | migrations/0015_fix_schema_issues.sql | 2 |
| PASS1-MEDIUM-001 | MEDIUM | Email logged in plaintext during registration | inscription.php | 6 |
| PASS1-MEDIUM-002 | MEDIUM | Rate limit check before CSRF check in inscription.php | inscription.php | 6 |
| PASS1-MEDIUM-003 | MEDIUM | Account deletion on deconnexion.php lacks session token validation | deconnexion.php | 6 |
| PASS1-MEDIUM-004 | MEDIUM | No password confirmation for email change | compte.php | 6 |
| PASS1-MEDIUM-005 | MEDIUM | Weak email validation regex in compte.php | compte.php | 6 |
| PASS1-MEDIUM-006 | MEDIUM | Energy cost TOCTOU — not re-validated under FOR UPDATE | attaquer.php | 7 |
| PASS1-MEDIUM-007 | MEDIUM | Attacker's beginner protection check uses stale data | attaquer.php | 7 |
| PASS1-MEDIUM-008 | MEDIUM | revenuAtomeJavascript() omits 5 bonus multipliers | includes/game_resources.php | 7 |
| PASS1-MEDIUM-009 | MEDIUM | Market buy cost rounds to 0 at price floor — free atom exploit | marche.php | 7 |
| PASS1-MEDIUM-010 | MEDIUM | Donation transfer doesn't check recipient storage capacity | marche.php | 7 |
| PASS1-MEDIUM-011 | MEDIUM | Loose comparison for alliance membership check | allianceadmin.php | 8 |
| PASS1-MEDIUM-012 | MEDIUM | No alliance rejoin cooldown after leaving or being kicked | alliance.php, allianceadmin.php | 8 |
| PASS1-MEDIUM-013 | MEDIUM | Pact duplicate check not atomic | allianceadmin.php | 8 |
| PASS1-MEDIUM-014 | MEDIUM | Duplicateur/research upgrade has no grade permission check | alliance.php | 8 |
| PASS1-MEDIUM-015 | MEDIUM | validerpacte.php only allows chef, not graded officers | validerpacte.php | 8 |
| PASS1-MEDIUM-016 | MEDIUM | Quitting alliance does not clean up pending invitations | alliance.php | 9 |
| PASS1-MEDIUM-017 | MEDIUM | Tag/name change not wrapped in transaction | allianceadmin.php | 9 |
| PASS1-MEDIUM-018 | MEDIUM | Market buy and sell use stale prices outside transaction | marche.php | 9 |
| PASS1-MEDIUM-019 | MEDIUM | Trade volume uses read-then-write instead of atomic increment | marche.php | 9 |
| PASS1-MEDIUM-020 | MEDIUM | Market depot read outside transaction (buy and sell paths) | marche.php | 9 |
| PASS1-MEDIUM-021 | MEDIUM | Missing day-1 streak milestone from prestige.php display | prestige.php | 10 |
| PASS1-MEDIUM-022 | MEDIUM | Dual-source medal threshold arrays | includes/constantesBase.php, includes/config.php | 10 |
| PASS1-MEDIUM-023 | MEDIUM | Season-frozen rankings not enforced on classement.php during maintenance | classement.php | 10 |
| PASS1-MEDIUM-024 | MEDIUM | medailles.php doesn't sanitize login GET parameter | medailles.php | 10 |
| PASS1-MEDIUM-025 | MEDIUM | Vault display in bilan.php doesn't show per-level rate | bilan.php | 10 |
| PASS1-MEDIUM-026 | MEDIUM | calculatePrestigePoints() omits 4 of 10 medal categories | includes/prestige.php | 11 |
| PASS1-MEDIUM-027 | MEDIUM | Vacation mode not checked centrally — actions leak | includes/basicprivatephp.php | 11 |
| PASS1-MEDIUM-028 | MEDIUM | Season countdown timezone not explicit | index.php | 11 |
| PASS1-MEDIUM-029 | MEDIUM | last_activity not initialized at login | includes/basicpublicphp.php | 11 |
| PASS1-MEDIUM-030 | MEDIUM | Login streak date comparison uses implicit server timezone | includes/player.php | 11 |
| PASS1-MEDIUM-031 | MEDIUM | No rate limiting on forum topic creation | listesujets.php | 12 |
| PASS1-MEDIUM-032 | MEDIUM | No rate limiting on forum replies | sujet.php | 12 |
| PASS1-MEDIUM-033 | MEDIUM | No rate limiting on private message sending | ecriremessage.php | 12 |
| PASS1-MEDIUM-034 | MEDIUM | Forum topic title input has no maxlength HTML attribute | listesujets.php | 12 |
| PASS1-MEDIUM-035 | MEDIUM | BBCode [url] missing nofollow attribute | includes/bbcode.php | 12 |
| PASS1-MEDIUM-036 | MEDIUM | regles.php says "5 jours" but config is 3 days | regles.php | 13 |
| PASS1-MEDIUM-037 | MEDIUM | Tutorial missions have no sequential enforcement | tutoriel.php | 13 |
| PASS1-MEDIUM-038 | MEDIUM | Tutorial reward energy added without storage cap | tutoriel.php | 13 |
| PASS1-MEDIUM-039 | MEDIUM | Tutorial reward values are hardcoded | tutoriel.php | 13 |
| PASS1-MEDIUM-040 | MEDIUM | constructions missing NOT NULL constraints | migrations/0005, 0006 | DB-SCHEMA |
| PASS1-MEDIUM-041 | MEDIUM | molecules.isotope missing NOT NULL | migrations/0008 | DB-SCHEMA |
| PASS1-MEDIUM-042 | MEDIUM | statistiques catalyst columns missing NOT NULL | migrations/0009 | DB-SCHEMA |
| PASS1-MEDIUM-043 | MEDIUM | Alliance research columns missing NOT NULL | migrations/0010 | DB-SCHEMA |
| PASS1-MEDIUM-044 | MEDIUM | spec columns missing NOT NULL | migrations/0011 | DB-SCHEMA |
| PASS1-MEDIUM-045 | MEDIUM | membre.session_token has no index | migrations/0012 | DB-SCHEMA |
| PASS1-MEDIUM-046 | MEDIUM | CSS typo: semicolon instead of colon in height property | includes/style.php, includes/display.php | 14 |
| PASS1-MEDIUM-047 | MEDIUM | CSP allows style-src 'unsafe-inline' | includes/layout.php | 14 |
| PASS1-MEDIUM-048 | MEDIUM | Email maxlength="25" too short in cardsprivate.php | includes/cardsprivate.php | 14 |
| PASS1-MEDIUM-049 | MEDIUM | debutCarte $overflow parameter not escaped | includes/ui_components.php | 14 |
| PASS1-MEDIUM-050 | MEDIUM | debutCarte $titre allows raw HTML (alliance callers) | includes/ui_components.php, guerre.php | 14 |
| PASS1-MEDIUM-051 | MEDIUM | Catalyst weekly rotation is fragile | includes/catalyst.php | 15 |
| PASS1-MEDIUM-052 | MEDIUM | getActiveCompounds() lacks FOR UPDATE inside activateCompound() | includes/compounds.php | 15 |
| PASS1-MEDIUM-053 | MEDIUM | coefDisparition() doesn't handle null stabilisateur row | includes/formulas.php | 15 |
| PASS1-MEDIUM-054 | MEDIUM | cleanupExpiredCompounds runs every laboratoire.php load | laboratoire.php | 15 |
| PASS1-MEDIUM-055 | MEDIUM | Countdown timer has no timezone label | js/countdown.js | 16 |
| PASS1-MEDIUM-056 | MEDIUM | News content uses fragile regex attribute stripping | index.php | 16 |
| PASS1-MEDIUM-057 | MEDIUM | alliance_left_at column missing from membre table | migrations/0035 | DB-SCHEMA |
| PASS1-LOW-001 | LOW | Residual $_SESSION['mdp'] unset dead code | includes/basicpublicphp.php | 16 |
| PASS1-LOW-002 | LOW | Admin panel missing CSP headers | admin/index.php, admin/supprimercompte.php, admin/ip.php, admin/multiaccount.php | 17 |
| PASS1-LOW-003 | LOW | Rate limiter files not automatically cleaned up | includes/rate_limiter.php | 17 |
| PASS1-LOW-004 | LOW | Dispersée formation overkill wasted (design note / doc) | docs/game/ | DOC |
| PASS1-LOW-005 | LOW | Dead code: $hydrogeneTotal calculated twice | includes/combat.php | 17 |
| PASS1-LOW-006 | LOW | strip_tags allows img event handlers in rapports.php | rapports.php | 17 |
| PASS1-LOW-007 | LOW | 4 redundant DB queries in medailles.php | medailles.php | 17 |
| PASS1-LOW-008 | LOW | Prestige shop button not disabled when can't afford | prestige.php | 18 |
| PASS1-LOW-009 | LOW | Attack section in bilan.php missing formation description | bilan.php | 18 |
| PASS1-LOW-010 | LOW | Daily leaderboard toggle loses sort parameter | classement.php | 18 |
| PASS1-LOW-011 | LOW | Pagination loses mode parameter in leaderboard | classement.php | 18 |
| PASS1-LOW-012 | LOW | Comeback bonus fires for brand-new players | includes/player.php | 18 |
| PASS1-LOW-013 | LOW | MathJax CDN lacks SRI hash | sujet.php | 19 |
| PASS1-LOW-014 | LOW | Broken HTML nesting in messages.php | messages.php | 19 |
| PASS1-LOW-015 | LOW | messagesenvoyes.php has no pagination | messagesenvoyes.php | 19 |
| PASS1-LOW-016 | LOW | Forum ban check inconsistent date comparison | forum.php, listesujets.php, sujet.php | 19 |
| PASS1-LOW-017 | LOW | Espionage mission fragile LIKE query | tutoriel.php | 19 |
| PASS1-LOW-018 | LOW | Espionage tutorial unused $nbNeutrinos variable | tutoriel.php | 19 |
| PASS1-LOW-019 | LOW | Password fields missing maxlength="72" for bcrypt | inscription.php | 20 |
| PASS1-LOW-020 | LOW | Alliance creation regex hardcodes {3,16} | alliance.php | 20 |
| PASS1-LOW-021 | LOW | Alliance name not validated for special characters | allianceadmin.php | 20 |
| PASS1-LOW-022 | LOW | War declaration duplicate check not inside transaction | allianceadmin.php | 20 |
| PASS1-LOW-023 | LOW | No rate limiting on market buy/sell | marche.php | 20 |
| PASS1-LOW-024 | LOW | Chart timestamp lacks explicit timezone declaration | marche.php | 20 |
| PASS1-LOW-025 | LOW | intval() buy quantity missing explicit > 0 check | marche.php | 21 |
| PASS1-LOW-026 | LOW | loader.js is local copy of Google Charts unversioned | loader.js | DOC |
| PASS1-LOW-027 | LOW | version.php has no dynamic build info | version.php | 21 |
| PASS1-LOW-028 | LOW | Page title static across all pages | includes/meta.php | 21 |
| PASS1-LOW-029 | LOW | progressBar() division by zero when $vieMax = 0 | includes/ui_components.php | 21 |
| PASS1-LOW-030 | LOW | Copyright footer hardcodes "V2.0.1.0" | includes/copyright.php | 21 |
| PASS1-LOW-031 | LOW | transformInt() missing numeric clamp | includes/display.php | 21 |
| PASS1-LOW-032 | LOW | molecule.php initializes $mx to oxygene instead of 0 | molecule.php | 21 |
| PASS1-LOW-033 | LOW | $javascript parameter is dead code in BBCode function | includes/bbcode.php | 19 |
| PASS1-LOW-034 | LOW | updateRessources molecule decay not atomic per-molecule | includes/game_resources.php | 20 |
| PASS1-LOW-035 | LOW | Comeback bonus checked every page load (performance) | includes/basicprivatephp.php | 18 |
| PASS1-LOW-036 | LOW | $vainqueurManche may be uninitialized before ?? operator | includes/basicprivatephp.php | 18 |
| PASS1-LOW-037 | LOW | Migration 0016 TEMPORARY TABLE INSERT column-order-dependent | migrations/0016 | DB-SCHEMA |
| PASS1-LOW-038 | LOW | Resource node bonus has no distance falloff (design doc) | docs/game/, regles.php | DB-DOC |

---

## Batch Dependencies

```
Batch 0 (CRITICAL)
    └── Batch 1 (HIGH: DB charset/FK fixes) — requires Batch 0 migrations deployed first
        └── Batch 2 (HIGH: DB structural) — requires Batch 1 tables exist
            └── DB-SCHEMA batch — requires Batch 2

Batch 3 (HIGH: XSS/CSP) — independent, can run after Batch 0
Batch 4 (HIGH: auth/gameplay) — independent
Batch 5 (HIGH: forum/compound docs) — independent

Batches 6-21 — independent of each other unless noted inline
```

---

## Batch 0: CRITICAL — Migration Runner & Database Charset (MUST RUN FIRST)

**Prerequisite for:** All database batches (1, 2, DB-SCHEMA)

### PASS1-CRITICAL-001: connexion.php sets utf8mb4 client charset vs latin1 database

- **Files:** `includes/connexion.php` line 20
- **Fix:** Change `mysqli_set_charset($base, 'utf8mb4')` to `mysqli_set_charset($base, 'latin1')`. The entire database and all tables use `latin1`. Keeping `utf8mb4` at the connection level causes MySQL to perform silent charset conversion on every query, which wastes CPU, can corrupt multi-byte data on INSERT, and degrades FK join performance. All new tables added in migrations 0020–0032 that use `utf8mb4` will also need conversion (handled in Batch 1).
- **Test:** Add `tests/DatabaseCharsetTest.php` asserting that `SHOW VARIABLES LIKE 'character_set_client'` returns `latin1` after connection. Alternatively add assertion in existing `DatabaseConnectionTest`.

### PASS1-CRITICAL-002: Migration 0026 creates duplicate idx_totalPoints, halts migration runner

- **Files:** `migrations/0026_add_totalpoints_index.sql`
- **Context:** Migration 0015 already has `ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_totalPoints (totalPoints)` (line 11 of that file). Migration 0026 omits `IF NOT EXISTS`. Any environment where 0015 ran before 0026 gets "Duplicate key name 'idx_totalPoints'" which crashes the migration runner, meaning migrations 0027–0032 (login streak, comeback tracking, season recap, alliance constraints, poll tables) were never applied to production, breaking the corresponding features entirely.
- **Fix:** Replace the `ALTER TABLE autre ADD INDEX idx_totalPoints (totalPoints);` line in `0026_add_totalpoints_index.sql` with `ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_totalPoints (totalPoints);`. Also add `ALTER TABLE autre ADD INDEX IF NOT EXISTS idx_idalliance (idalliance);` with the same guard if it was part of the same migration intent.
- **Action on VPS:** After fixing the file, connect to production, verify which migrations applied, manually run 0027–0032 if missing: `SELECT version FROM migrations WHERE version BETWEEN 27 AND 32;`
- **Test:** Add `tests/MigrationIdempotencyTest.php` that runs the migration SQL twice against a test DB and asserts no error on second run.

---

## Batch 1: HIGH — Database Charset Conversions for New Tables

**Prerequisite:** Batch 0 deployed
**Purpose:** All tables added in phases 13–14 were incorrectly created with `DEFAULT CHARSET=utf8mb4`. This breaks foreign key relationships with `membre` (latin1). A single new migration (`0033_fix_utf8mb4_tables.sql`) handles all conversions.

### PASS1-HIGH-001: attack_cooldowns table charset utf8mb4 breaks FK to latin1 membre

- **Files:** New migration `migrations/0033_fix_utf8mb4_tables.sql`
- **Fix:** Add to migration 0033: `ALTER TABLE attack_cooldowns CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;` — attacker/defender VARCHAR(50) columns reference `membre.login` VARCHAR(50). FK will fail at engine level if charsets mismatch.
- **Test:** Integration: attempt INSERT with a known membre login and assert FK constraint works.

### PASS1-HIGH-002: login_history table charset utf8mb4 and no FK to membre

- **Files:** New migration `migrations/0033_fix_utf8mb4_tables.sql`
- **Fix:** In migration 0033: `ALTER TABLE login_history CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;` then `ALTER TABLE login_history ADD CONSTRAINT fk_login_history_membre FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;`. The cascade ensures cleanup when a player is deleted.
- **Test:** Assert FK exists via `SHOW CREATE TABLE login_history` in migration test.

### PASS1-HIGH-003: account_flags and admin_alerts charset utf8mb4 and no FKs

- **Files:** New migration `migrations/0033_fix_utf8mb4_tables.sql`
- **Fix:** In migration 0033:
  ```sql
  ALTER TABLE account_flags CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
  ALTER TABLE account_flags ADD CONSTRAINT fk_account_flags_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
  ALTER TABLE account_flags ADD CONSTRAINT fk_account_flags_related FOREIGN KEY (related_login) REFERENCES membre(login) ON DELETE SET NULL;
  ALTER TABLE admin_alerts CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
  ```
  `admin_alerts` has no login column so no FK needed there, only charset.
- **Test:** Confirm no orphan rows possible; test DELETE cascade on membre row.

### PASS1-HIGH-004: resource_nodes table charset utf8mb4

- **Files:** New migration `migrations/0033_fix_utf8mb4_tables.sql`
- **Fix:** `ALTER TABLE resource_nodes CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;` — `resource_nodes` has no FK to membre, but latin1 consistency is required for the migration runner's collation assumptions.
- **Test:** Confirm `SHOW CREATE TABLE resource_nodes` shows `latin1`.

### PASS1-HIGH-005: prestige table created without latin1 charset

- **Files:** New migration `migrations/0033_fix_utf8mb4_tables.sql`
- **Context:** Migration 0007 created prestige without `DEFAULT CHARSET`. Migration 0022 has a duplicate `MODIFY` on prestige. Charset is undefined (inherits DB default).
- **Fix:** In migration 0033: `ALTER TABLE prestige CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;` and add FK: `ALTER TABLE prestige ADD CONSTRAINT fk_prestige_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;`. Remove duplicate MODIFY from 0022 or add `IF NOT EXISTS` guard.
- **Test:** Confirm charset and FK in SHOW CREATE TABLE.

---

## Batch 2: HIGH — Database Structural Integrity

**Prerequisite:** Batch 1

### PASS1-HIGH-006: reponses_sondage has no FK to sondages.id or membre.login

- **Files:** New migration `migrations/0034_add_missing_fks.sql`
- **Fix:**
  ```sql
  ALTER TABLE reponses_sondage
    ADD CONSTRAINT fk_reponses_sondage_login FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE,
    ADD CONSTRAINT fk_reponses_sondage_sondage FOREIGN KEY (sondage) REFERENCES sondages(id) ON DELETE CASCADE;
  ```
  The table already uses `latin1` so no charset conversion needed.
- **Test:** Verify deletion of a membre cascades to reponses_sondage.

### PASS1-HIGH-007: attack_cooldowns.defender has no FK constraint

- **Files:** New migration `migrations/0034_add_missing_fks.sql`
- **Fix:** `ALTER TABLE attack_cooldowns ADD CONSTRAINT fk_cooldowns_defender FOREIGN KEY (defender) REFERENCES membre(login) ON DELETE CASCADE;` — already have FK on attacker from migration 0018 but defender was skipped.
- **Test:** Confirm both attacker and defender are constrained.

### PASS1-HIGH-008: grades table has no primary key

- **Files:** New migration `migrations/0034_add_missing_fks.sql`
- **Fix:** `ALTER TABLE grades ADD PRIMARY KEY (login);` — grades appears to be keyed by login. Verify with `DESCRIBE grades` first; if login can repeat (player has multiple grades), use composite PK `(login, niveau)` instead. Include a comment.
- **Test:** Assert `SHOW KEYS FROM grades WHERE Key_name = 'PRIMARY'` returns a row.

### PASS1-HIGH-009: statutforum table has no primary key

- **Files:** New migration `migrations/0034_add_missing_fks.sql`
- **Fix:** `ALTER TABLE statutforum ADD PRIMARY KEY (login, idsujet);` — this table tracks which forum topics a player has read, so (login, idsujet) is the natural compound key.
- **Test:** Assert duplicate INSERT on same (login, idsujet) raises an error.

### PASS1-HIGH-010: Migration 0015 has INSERT INTO migrations with wrong schema

- **Files:** `migrations/0015_fix_schema_issues.sql` line 18
- **Fix:** Remove the `INSERT INTO migrations (version, description, applied_at)` statement from 0015. The migration runner should manage the migrations table itself. This INSERT uses a schema (`version, description, applied_at`) that may not match the actual migrations table definition, causing silent errors or constraint violations when the migration runner also inserts. After removal, add a comment: `-- Runner inserts migration record; do not duplicate here.`
- **Test:** Run 0015 twice against a test DB; assert no duplicate key error in migrations table.

### PASS1-HIGH-027: Migration 0009 INSERT in 0015_fix_schema_issues.sql uses wrong schema

- **Files:** `migrations/0015_fix_schema_issues.sql`
- **Note:** This is a separate finding from PASS1-HIGH-010 even though both affect the same file. HIGH-010 concerns the self-referential `INSERT INTO migrations` that 0015 performs for its own record. HIGH-027 concerns an `INSERT` statement inside 0015 that was meant to correct or replay data from migration 0009 (statistiques catalyst columns) but references column names that do not match the actual table schema established by 0009. The two issues must be fixed independently.
- **Fix:** Locate the INSERT statement in 0015 that references migration 0009's data (catalyst/catalyst_week population). Compare the column list against `DESCRIBE statistiques` on a current DB. Correct the column names to match the live schema. If the INSERT is entirely redundant (0009 already inserted the correct seed data), remove it with a comment: `-- 0009 seed data already applied; removed redundant INSERT with wrong column order.` If the INSERT is a correction, fix the column list and add a `WHERE NOT EXISTS` guard to make it idempotent.
- **Test:** Run the corrected 0015 against a test DB that has already had 0009 applied; assert no column-not-found error and no duplicate-key error.

---

## Batch 3: HIGH — XSS and CSP Violations

**Prerequisite:** None (independent)

### PASS1-HIGH-011: $_SESSION['login'] injected unescaped into JavaScript in layout.php

- **Files:** `includes/layout.php` line 137 and similar occurrences
- **Fix:** For JavaScript variable assignments use `json_encode($_SESSION['login'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)`. For URL parameters use `urlencode($_SESSION['login'])`. Audit all occurrences with `grep -n 'SESSION.*login' includes/layout.php`. A logged-in user with a username like `"; alert(1)//` could inject arbitrary script otherwise.
- **Test:** Add `SecurityTest::testSessionLoginXSSPrevention()` that asserts the rendered layout output for a specially crafted login contains only the json_encode-safe version.

### PASS1-HIGH-012: Inline onclick event handlers blocked by CSP (confirm dialogs never fire)

- **Files:** `sujet.php` lines 203, 208; `bilan.php` line 853
- **Fix:** Replace `onclick="return confirm('...')"` patterns with `data-confirm="..."` attributes on the buttons. Add a small event listener block in the relevant page's nonce-tagged `<script>` block:
  ```javascript
  document.addEventListener('click', function(e) {
      var el = e.target.closest('[data-confirm]');
      if (el && !confirm(el.dataset.confirm)) e.preventDefault();
  });
  ```
  This pattern is already used elsewhere in the codebase after Batch M from Phase 12.
- **Test:** Add CSP test confirming no `onclick` attributes remain in rendered output for these pages.

### PASS1-HIGH-013: `<html>` tag missing lang="fr" attribute on all pages

- **Files:** `includes/layout.php` line 6
- **Fix:** Change `<html>` to `<html lang="fr">`. This is a single-character change affecting all 80 pages since they all use layout.php. Required for WCAG 2.1 compliance and SEO.
- **Test:** Add `UITest::testHtmlLangAttribute()` asserting rendered layout contains `<html lang="fr">`.

### PASS1-HIGH-014: Alliance tag unescaped in pacte/guerre tables in allianceadmin.php

- **Files:** `allianceadmin.php` lines 487, 498, 535, 547, 551
- **Fix:** Wrap all `$tagAlliance['tag']` echo outputs with `htmlspecialchars($tagAlliance['tag'], ENT_QUOTES, 'UTF-8')`. An alliance tag containing `<script>` would execute on every admin page load.
- **Test:** Add `AllianceXSSTest::testTagEscapedInPactTable()` asserting `<` is rendered as `&lt;`.

### PASS1-HIGH-015: War end notification includes unescaped alliance tag

- **Files:** `allianceadmin.php` lines 312, 253
- **Fix:** Apply `htmlspecialchars($allianceAdverse['tag'], ENT_QUOTES, 'UTF-8')` before the `$information` string composition. The `$information` variable is stored to DB and later rendered; escaping at the point of string assembly prevents stored XSS.
- **Test:** Add assertion in existing `AllianceTest` suite for stored notification content.

---

## Batch 4: HIGH — Auth, Gameplay Integrity & Ranking

**Prerequisite:** None (independent)

### PASS1-HIGH-016: supprimerJoueur() misses DELETE FROM player_compounds

- **Files:** `includes/player.php` lines 768–798
- **Fix:** Inside the `withTransaction()` block in `supprimerJoueur()`, add `dbExecute($base, "DELETE FROM player_compounds WHERE login = ?", [$login]);` after the existing DELETE statements. Without this, deleted player rows remain in `player_compounds`, breaking FK integrity once Batch 1 adds the cascade (and currently leaving orphan data).
- **Test:** Add `PlayerDeletionTest::testCompoundsCleanedUp()` that creates a player with an active compound, deletes the player, and asserts `player_compounds` has zero rows for that login.

### PASS1-HIGH-017: Comeback bonus storage cap uses depot read without FOR UPDATE

- **Files:** `includes/player.php` lines 1122–1139 in `checkComebackBonus()`
- **Fix:** Within the existing `withTransaction()` block, change the `SELECT depot` query to `SELECT depot FROM constructions WHERE login = ? FOR UPDATE` so that the storage capacity value is locked for the duration of the transaction. This prevents a concurrent market purchase from increasing storage while the comeback bonus is being applied, potentially over-awarding atoms.
- **Test:** Add `PlayerTest::testComebackBonusStorageCapRespected()`.

### PASS1-HIGH-018: Login streak PP not shown correctly in prestige.php display

- **Files:** `prestige.php`, `includes/player.php` lines 1079–1080
- **Fix:** The streak PP is credited immediately to `prestige.total_pp` via `checkLoginStreak()`. The prestige.php page should display a "Streak actuelle" row showing `$streakDays` days and the PP already credited. Either: (a) add a dedicated query to `autre` for `streak_days` and display it as a read-only info row, or (b) add a tooltip/note on the PP total clarifying streak PP is already included. Option (a) is cleaner. Add `$streakData = dbFetchOne($base, "SELECT streak_days, streak_last_date FROM autre WHERE login = ?", [$login]);` and render a row.
- **Test:** Add `PrestigeTest::testStreakDisplayedOnPrestigePage()`.

### PASS1-HIGH-019: classement.php includes dead/inactive players (x=-1000) in leaderboard

- **Files:** `classement.php` lines 182, 195
- **Fix:** Add `AND m.x != -1000` to the `WHERE` clause (or `JOIN membre m ON a.login = m.login WHERE m.x != -1000`) in both the count query and the data fetch query in classement.php. Players with x=-1000 have been removed from the map (deleted/banned) and pollute the rankings.
- **Test:** Add `ClassementTest::testDeletedPlayersNotInLeaderboard()` creating a player with x=-1000 and asserting they don't appear.

### PASS1-HIGH-020: Alliance tag change allows 1-character tag (bypasses 3-char minimum)

- **Files:** `allianceadmin.php` line 126
- **Fix:** Change the tag regex for the rename operation from a pattern that does not enforce minimum length to `'/^[A-Z0-9]{' . ALLIANCE_TAG_MIN_LENGTH . ',' . ALLIANCE_TAG_MAX_LENGTH . '}$/i'`. Currently creation enforces {3,16} but renaming allows any length. Both must be consistent.
- **Test:** Add `AllianceTest::testTagRenameEnforcesMinLength()` attempting a 1-char and 2-char rename and asserting rejection.

---

## Batch 5: HIGH — Forum Security & Documentation Corrections

**Prerequisite:** None (independent)

### PASS1-HIGH-021: MathJax loaded on ALL topic pages unconditionally

- **Files:** `sujet.php` line 256; `includes/bbcode.php` line 2
- **Fix:** Pass the forum ID through to `sujet.php`. If `$idForum == 8` (math forum), include MathJax; otherwise omit. For `[latex]` BBCode processing in `bbcode.php`, the existing `$javascript` parameter should gate the MathJax-dependent output — implement the conditional that is currently dead code (see PASS1-LOW-033). This saves ~180KB of JS load on every non-math topic page.
- **Test:** Add `ForumTest::testMathJaxOnlyLoadedForMathForum()` checking rendered HTML for forum 1 vs forum 8.

### PASS1-HIGH-022: Hardcoded admin username for global broadcast in ecriremessage.php

- **Files:** `ecriremessage.php` line 34
- **Fix:** Replace the hardcoded username string with a DB role check: `$isAdmin = (dbCount($base, "SELECT COUNT(*) FROM membre WHERE login = ? AND role = 'admin'", [$_SESSION['login']]) > 0);`. Then gate the broadcast path on `$isAdmin`. Also add `rateLimitCheck('broadcast_' . $_SESSION['login'], 2, 3600)` to limit broadcasts to 2 per hour per admin.
- **Test:** Add `MessagingTest::testOnlyAdminCanBroadcast()`.

### PASS1-HIGH-023: Message reply fetches content before auth check in ecriremessage.php

- **Files:** `ecriremessage.php` lines 60–77
- **Fix:** Change the content query from `SELECT * FROM messages WHERE id = ?` to `SELECT * FROM messages WHERE id = ? AND (destinataire = ? OR expeditaire = ?)` with `[$id, $_SESSION['login'], $_SESSION['login']]` as parameters. This ensures only participants can retrieve the message content. The current pattern of fetching first then blanking is fragile and leaks content if the blanking code path has a bug.
- **Test:** Add `MessagingTest::testCannotFetchOtherPlayersMessageContent()`.

### PASS1-HIGH-024: Misleading comment in formulas.php says "+50%" but code is "+20%"

- **Files:** `includes/formulas.php` line 266
- **Fix:** Update the comment to accurately reflect `ISOTOPE_REACTIF_DECAY_MOD` (which equals +20% as per config.php). Change comment from `// +50% decay` to `// ISOTOPE_REACTIF_DECAY_MOD = ` . ISOTOPE_REACTIF_DECAY_MOD . `% decay modifier`. This is a documentation accuracy issue that leads to incorrect game balance reasoning.
- **Test:** No runtime test needed; add a constant-comment consistency grep in the CI lint step.

### PASS1-HIGH-025: Isotope stable HP described as +30% in documentation but code is +40%

- **Files:** `docs/game/09-BALANCE.md`, `docs/game/10-PLAYER-GUIDE.md`, any inline comments referencing "+30%"
- **Fix:** Search for "+30%" references related to isotope stable HP: `grep -r "30%" docs/`. Update all documentation to say "+40%" which matches the code constant `ISOTOPE_STABLE_HP_BONUS`. The code is correct; the documentation is wrong. Update the changelog to note the correction.
- **Test:** Add a doc-vs-code consistency check in the CI pipeline that extracts the constant value and compares with documentation strings (simple grep assertion).

---

## Batch 6: MEDIUM — Security & Auth Cleanup

**Prerequisite:** None

### PASS1-MEDIUM-001: Email logged in plaintext during registration

- **Files:** `inscription.php` line 43
- **Fix:** Remove `'email' => $email` from the `logInfo()` call (or the equivalent array passed to the logger). Email addresses must not appear in application log files (GDPR compliance, potential data breach). Keep the login and IP in the log but drop the email field.
- **Test:** Add `RegistrationTest::testEmailNotLoggedInPlaintext()` capturing log output and asserting the email string is absent.

### PASS1-MEDIUM-002: Rate limit check before CSRF check in inscription.php

- **Files:** `inscription.php` lines 9–15
- **Fix:** Reorder so `csrfCheck()` is called before `rateLimitCheck()`. The CSRF check should always be the first gate on POST handlers. Calling rate limiter first means an attacker can exhaust rate limits on a user via forged requests that bypass CSRF. After reordering: `csrfCheck(); rateLimitCheck('register', 5, 3600); // then validation`.
- **Test:** Update `CsrfTest::testRegistrationCsrfEnforced()` to confirm CSRF rejection happens before any rate limit decrement.

### PASS1-MEDIUM-003: Account deletion on deconnexion.php lacks session token DB validation

- **Files:** `deconnexion.php` lines 7–10
- **Fix:** Before processing account deletion (if that path exists in deconnexion.php), add session token validation matching the pattern in `basicprivatephp.php`:
  ```php
  $tokenDb = dbFetchOne($base, "SELECT session_token FROM membre WHERE login = ?", [$_SESSION['login']]);
  if (!$tokenDb || $tokenDb['session_token'] !== $_SESSION['session_token']) {
      session_destroy(); header('Location: index.php'); exit;
  }
  ```
  This prevents session fixation attacks from triggering account deletion.
- **Test:** Add `AuthTest::testDeconnexionRequiresValidSessionToken()`.

### PASS1-MEDIUM-004: No password confirmation required for email change

- **Files:** `compte.php` lines 86–98
- **Fix:** Add a `mot_de_passe_actuel` field to the email change form. In the handler, verify `password_verify($currentPassword, $membre['mdp'])` before updating the email. This prevents an attacker with a stolen session from silently changing the victim's email for account takeover.
- **Test:** Add `AccountTest::testEmailChangeRequiresCurrentPassword()`.

### PASS1-MEDIUM-005: Weak email validation regex in compte.php

- **Files:** `compte.php` line 88
- **Fix:** Replace the custom regex with `validateEmail($email)` from `includes/validation.php` which uses `FILTER_VALIDATE_EMAIL`. This ensures consistency with the registration flow and catches edge cases the regex misses.
- **Test:** Extend `ValidationTest` with `testEmailValidationConsistentInCompte()`.

---

## Batch 7: MEDIUM — Combat & Economy Integrity

**Prerequisite:** None

### PASS1-MEDIUM-006: Energy cost TOCTOU — not re-validated under FOR UPDATE lock

- **Files:** `attaquer.php` line 188
- **Fix:** Inside the `withTransaction()` block, add a re-SELECT before the energy deduction:
  ```php
  $energieFraiche = dbFetchOne($base, "SELECT energie FROM autre WHERE login = ? FOR UPDATE", [$attaquant]);
  if ($energieFraiche['energie'] < $coutEnergie) {
      throw new RuntimeException('Energie insuffisante');
  }
  ```
  This eliminates the race window between the pre-transaction validation and the actual deduction.
- **Test:** Add `CombatTest::testEnergyValidatedUnderLock()`.

### PASS1-MEDIUM-007: Attacker beginner protection check uses stale $membre data

- **Files:** `attaquer.php` line 99
- **Fix:** Replace the use of the outer-scope `$membre['dateInscription']` with a fresh fetch inside the validation block:
  ```php
  $attackerFresh = dbFetchOne($base, "SELECT dateInscription FROM membre WHERE login = ?", [$attaquant]);
  $isProtected = (time() - $attackerFresh['dateInscription']) < BEGINNER_PROTECTION_SECONDS;
  ```
  Stale session data could incorrectly grant or deny beginner protection.
- **Test:** Add `CombatTest::testBeginnerProtectionUsesCurrentTimestamp()`.

### PASS1-MEDIUM-008: revenuAtomeJavascript() omits 5 bonus multipliers

- **Files:** `includes/game_resources.php` lines 134–152
- **Fix:** The JS-facing revenue calculation misses prestige bonus, resource node proximity bonus, compound buff, specialization modifier, and medal bonus — all of which are applied in the server-side path. Compute the combined multiplier server-side and inject it as a scalar:
  ```php
  $totalMultiplier = getProductionMultiplier($login); // new helper or inline
  // Pass to JS: json_encode(['multiplier' => $totalMultiplier, 'base' => $baseRevenu])
  ```
  The JS display will then show correct income estimates to players.
- **Test:** Add `GameResourcesTest::testRevenuAtomeJavascriptIncludesAllMultipliers()`.

### PASS1-MEDIUM-009: Market buy cost rounds to 0 at price floor — free atom exploit

- **Files:** `marche.php` line 195
- **Fix:** Wrap the computed cost with `max(1, (int)round($prix * $quantite))`. The price floor is non-zero but for small quantities at low prices `round()` returns 0, enabling free purchases. A minimum of 1 atom cost per transaction eliminates this.
- **Test:** Add `MarketTest::testBuyCostNeverZero()` with quantity=1 and minimum price.

### PASS1-MEDIUM-010: Donation transfer doesn't check recipient storage capacity

- **Files:** `marche.php` lines 72–136
- **Fix:** Before crediting atoms to the recipient, call `placeDepot($recipientLogin)` and subtract their current inventory to determine available space. Cap the transfer to `min($quantiteDonnee, $placeLibre)`. If `$placeLibre <= 0`, return an error message. This prevents donated atoms from silently vanishing due to storage overflow.
- **Test:** Add `MarketTest::testDonationRespectRecipientStorageCap()`.

---

## Batch 8: MEDIUM — Alliance Integrity Part 1

**Prerequisite:** None

### PASS1-MEDIUM-011: Loose comparison for alliance membership check

- **Files:** `allianceadmin.php` line 8
- **Fix:** Change `$membre['idalliance'] == 0` to `$membre['idalliance'] === 0` (or `=== '0'` depending on type). Loose comparison in PHP can allow `null == 0` to return true, potentially bypassing alliance membership guards.
- **Test:** Add `AllianceTest::testMembershipCheckUsesStrictComparison()`.

### PASS1-MEDIUM-012: No alliance rejoin cooldown after leaving or being kicked

- **Files:** `alliance.php` lines 72–81 (quit); `allianceadmin.php` lines 176–201 (kick)
- **Fix:** Add `alliance_left_at INT DEFAULT NULL` column (migration in DB-SCHEMA batch). On quit or kick, set `UPDATE membre SET alliance_left_at = UNIX_TIMESTAMP() WHERE login = ?`. On invite accept in `alliance.php`, check: `if ($membre['alliance_left_at'] && (time() - $membre['alliance_left_at']) < ALLIANCE_REJOIN_COOLDOWN_SECONDS) { // reject }`. Add `ALLIANCE_REJOIN_COOLDOWN_SECONDS = 86400` (24h) to config.php.
- **Test:** Add `AllianceTest::testRejoinCooldownEnforced()`.

### PASS1-MEDIUM-013: Pact duplicate check not atomic (race condition)

- **Files:** `allianceadmin.php` lines 212–216
- **Fix:** Wrap the duplicate check SELECT and the INSERT into `withTransaction()`:
  ```php
  withTransaction($base, function() use ($base, $id1, $id2) {
      $exists = dbCount($base, "SELECT COUNT(*) FROM pactes WHERE (alliance1=? AND alliance2=?) OR (alliance1=? AND alliance2=?) FOR UPDATE", [$id1,$id2,$id2,$id1]);
      if ($exists > 0) throw new RuntimeException('Pact already exists');
      dbExecute($base, "INSERT INTO pactes ...", [...]);
  });
  ```
- **Test:** Add `AllianceTest::testPactDuplicatePreventedRaceCondition()`.

### PASS1-MEDIUM-014: Duplicateur/research upgrade has no grade permission check

- **Files:** `alliance.php` lines 88–108
- **Fix:** Before processing an upgrade to `duplicateur` or any research item, verify the member's grade has the `recherche` or `upgrade` permission. Check the `grades` table for the member's grade and its allowed actions. If no permission, return error "Permission insuffisante".
- **Test:** Add `AllianceTest::testResearchUpgradeRequiresGradePermission()`.

### PASS1-MEDIUM-015: validerpacte.php only allows chef, not graded officers with pact permission

- **Files:** `validerpacte.php` lines 12–14
- **Fix:** Extend the authorization check from a single `$membre['grade'] === 'chef'` check to also allow members whose grade record in the `grades` table has `pactes = 1` (or equivalent permission flag). Load the player's grade permissions from DB and check both conditions.
- **Test:** Add `AllianceTest::testGradedOfficerCanAcceptPact()`.

---

## Batch 9: MEDIUM — Alliance Integrity Part 2 & Market Transactions

**Prerequisite:** None

### PASS1-MEDIUM-016: Quitting alliance does not clean up pending invitations

- **Files:** `alliance.php` lines 72–81
- **Fix:** In the quit handler, after the `UPDATE membre SET idalliance = 0` statement, add `dbExecute($base, "DELETE FROM invitations WHERE invite = ?", [$login]);`. Otherwise the player has pending invitations that point to an alliance they already left.
- **Test:** Add `AllianceTest::testQuitCleansUpInvitations()`.

### PASS1-MEDIUM-017: Tag change and name change not wrapped in transaction

- **Files:** `allianceadmin.php` lines 61–63 (name), 129–132 (tag)
- **Fix:** Wrap both the uniqueness-check SELECT and the UPDATE into `withTransaction()` with a `FOR UPDATE` lock on the conflicting row (or use `INSERT ... ON DUPLICATE KEY` pattern). This prevents two concurrent requests from both passing the uniqueness check and creating duplicate tags/names.
- **Test:** Add `AllianceTest::testTagChangeIsAtomic()`.

### PASS1-MEDIUM-018: Market buy and sell use stale prices outside transaction (merged finding)

- **Files:** `marche.php` lines 195, 204–206 (buy), 296, 304–306 (sell)
- **Context:** This merges Market MEDIUM-001, Market MEDIUM-002, and Economy MEDIUM-003.
- **Fix:** For both buy and sell paths, re-read the `cours` row inside the `withTransaction()` block with a `SELECT prix FROM cours WHERE type = ? FOR UPDATE` query, then use that freshly-locked price for all subsequent calculations. Also add Economy LOW-001 mitigation: since the row is already locked, concurrent trades on the same resource will serialize correctly.
- **Test:** Add `MarketTest::testPriceReadInsideTransaction()` asserting the locked price is used.

### PASS1-MEDIUM-019: Trade volume uses read-then-write instead of atomic increment

- **Files:** `marche.php` lines 244–247
- **Fix:** Replace the SELECT-then-UPDATE pattern with: `dbExecute($base, "UPDATE autre SET tradeVolume = tradeVolume + ? WHERE login = ?", [$delta, $login]);` This is atomic at the SQL level and eliminates the race condition where two simultaneous trades from the same player could overwrite each other's volume contribution.
- **Test:** Add `MarketTest::testTradeVolumeAtomicIncrement()`.

### PASS1-MEDIUM-020: Market depot read outside transaction (buy and sell paths)

- **Files:** `marche.php` lines 204, 212
- **Fix:** Move the `placeDepot($login)` call inside the `withTransaction()` block, directly before the inventory update. The depot level (coffrefort building) could change between the pre-transaction read and the actual write if a building upgrade completes concurrently.
- **Test:** Add `MarketTest::testDepotReadInsideTransaction()`.

---

## Batch 10: MEDIUM — Prestige, Medals & Ranking Display

**Prerequisite:** None

### PASS1-MEDIUM-021: Missing day-1 streak milestone from prestige.php display

- **Files:** `prestige.php` line 68
- **Fix:** Add `STREAK_REWARD_DAY_1` to the streak milestones display array. The display currently skips the first milestone, making players think day 1 has no reward when it actually grants 1 PP. Add a row: `['jours' => 1, 'pp' => STREAK_REWARD_DAY_1, 'label' => 'Connexion']` to the displayed milestones table.
- **Test:** Add `PrestigeTest::testDay1StreakRewardDisplayed()`.

### PASS1-MEDIUM-022: Dual-source medal threshold arrays

- **Files:** `includes/constantesBase.php` lines 30–39; `includes/config.php` lines 504–523
- **Fix:** In `constantesBase.php`, replace the hardcoded `$paliers*` arrays with derivation from config constants:
  ```php
  $paliers_attaque = array_values(array_column($MEDAL_THRESHOLDS_ATTAQUE, 'threshold'));
  ```
  This makes `constantesBase.php` the shim it's supposed to be, with config.php as the single source of truth. If the legacy arrays and config arrays have diverged values, use config.php values.
- **Test:** Add `MedalTest::testLegacyArraysMatchConfigConstants()`.

### PASS1-MEDIUM-023: Season-frozen rankings not enforced on classement.php during maintenance

- **Files:** `classement.php`
- **Fix:** At the top of the ranking calculation block, check the maintenance flag (from config or a DB flag set by `performSeasonEnd()`). If in maintenance: (a) skip the `recalculerStatsAlliances()` call, (b) display a banner "Classement gelé — fin de saison en cours", (c) show the last pre-maintenance snapshot. This was partially implemented in Phase 10 Batch D but classement.php was not updated.
- **Test:** Add `ClassementTest::testRankingFrozenDuringMaintenance()`.

### PASS1-MEDIUM-024: medailles.php doesn't sanitize login GET parameter

- **Files:** `medailles.php` line 22
- **Fix:** Keep `$joueur = trim($_GET['login'])` unchanged for use in DB queries — the prepared statement handles SQL safety and applying `htmlspecialchars()` at this stage would corrupt lookups for any login containing `&`, `'`, `<`, or `>`. Instead, apply escaping exclusively at the HTML output layer: after the DB query fetches the player row, use `htmlspecialchars($donnees2['login'], ENT_QUOTES, 'UTF-8')` when echoing the login into the page (e.g., in the "Médailles de [joueur]" heading). This separates DB safety (prepared statements) from HTML output safety (escaping at the point of rendering).
- **Test:** Add `MedalTest::testLoginParameterEscapedInOutput()`.

### PASS1-MEDIUM-025: Vault display in bilan.php doesn't show per-level rate

- **Files:** `bilan.php` line 410
- **Fix:** In the vault bonus row, add a sub-line showing `VAULT_PCT_PER_LEVEL . '% par niveau (niveau ' . $coffrefort . ' = ' . ($coffrefort * VAULT_PCT_PER_LEVEL) . '%)'`. This gives players actionable upgrade cost-benefit information without requiring them to consult regles.php.
- **Test:** Add `BilanTest::testVaultPerLevelRateDisplayed()`.

---

## Batch 11: MEDIUM — Prestige Calculation, Vacation Mode & Session Integrity

**Prerequisite:** None

### PASS1-MEDIUM-026: calculatePrestigePoints() omits 4 of 10 medal categories

- **Files:** `includes/prestige.php` lines 63–77
- **Fix:** Either (a) add the 4 missing medal categories to the PP calculation with their respective PP values defined in config.php, or (b) if the omission is intentional, add a clear comment and update prestige.php UI to specify "PP awarded for: [list of 6 categories]" so players are not confused. Option (a) is preferred for game fairness.
- **Test:** Add `PrestigeTest::testAllMedalCategoriesContributeToPP()`.

### PASS1-MEDIUM-027: Vacation mode not checked centrally — game actions leak during vacation

- **Files:** `includes/basicprivatephp.php` lines 82–100
- **Fix:** Add vacation mode enforcement to `basicprivatephp.php`. Define an allowlist of pages permitted during vacation (e.g., `['compte.php', 'regles.php', 'prestige.php', 'maintenance.php', 'deconnexion.php']`). For any page not in the allowlist, if `$membre['vacances'] == 1`, redirect to `index.php?msg=vacation`. This centralizes the check and eliminates the current fragile per-page checks.
- **Test:** Add `VacationTest::testGameActionsBlockedDuringVacation()`.

### PASS1-MEDIUM-028: Season countdown timezone not explicit

- **Files:** `index.php` line 131
- **Fix:** Add `date_default_timezone_set('Europe/Paris');` to `includes/config.php` or `includes/connexion.php` (bootstrap). This ensures all `date()` and `strtotime()` calls throughout the application, including the countdown, use the correct game timezone. Document this in config.php as `// Game timezone — all date calculations use this`.
- **Test:** Add `ConfigTest::testTimezoneIsExplicitlySet()` asserting `date_default_timezone_get() === 'Europe/Paris'`.

### PASS1-MEDIUM-029: last_activity not initialized at login

- **Files:** `includes/basicpublicphp.php` lines 59–76
- **Fix:** In the login success block, add `$_SESSION['last_activity'] = time();`. Without this, the session timeout check in `basicprivatephp.php` that reads `$_SESSION['last_activity']` may produce a PHP notice and incorrectly trigger an early timeout on the first private page hit.
- **Test:** Add `AuthTest::testLastActivitySetOnLogin()`.

### PASS1-MEDIUM-030: Login streak date comparison uses implicit server timezone

- **Files:** `includes/player.php` lines 1042, 1058
- **Fix:** Replace any `date('Y-m-d')` calls in the streak logic with `gmdate('Y-m-d')` or explicitly use `(new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d')`. The streak `streak_last_date` DATE column should be interpreted consistently. If config timezone is set (per PASS1-MEDIUM-028), `date('Y-m-d')` will be correct; but explicit is better. Add a comment documenting timezone assumption.
- **Test:** Add `PlayerTest::testStreakDateConsistentTimezone()`.

---

## Batch 12: MEDIUM — Forum Rate Limiting & Input Validation

**Prerequisite:** None

### PASS1-MEDIUM-031: No rate limiting on forum topic creation

- **Files:** `listesujets.php` lines 24–42
- **Fix:** Add `rateLimitCheck('forum_topic_' . $_SESSION['login'], 5, 300)` at the top of the POST handler (5 topics per 5 minutes). This prevents topic spam and aligns with the rate limiting applied to other user-generated content.
- **Test:** Add `ForumTest::testTopicCreationRateLimited()`.

### PASS1-MEDIUM-032: No rate limiting on forum replies

- **Files:** `sujet.php` lines 11–52
- **Fix:** Add `rateLimitCheck('forum_reply_' . $_SESSION['login'], 10, 300)` at the start of the POST handler.
- **Test:** Add `ForumTest::testReplyRateLimited()`.

### PASS1-MEDIUM-033: No rate limiting on private message sending

- **Files:** `ecriremessage.php` lines 41–48
- **Fix:** Add `rateLimitCheck('private_msg_' . $_SESSION['login'], 10, 300)` in the send handler.
- **Test:** Add `MessagingTest::testPrivateMessageRateLimited()`.

### PASS1-MEDIUM-034: Forum topic title input has no maxlength HTML attribute

- **Files:** `listesujets.php` line 39
- **Fix:** Add `maxlength="200"` to the `<input name="titre">` element. The DB column presumably has a VARCHAR(200) or similar limit; the HTML attribute provides client-side UX feedback. Pair this with server-side: `if (mb_strlen($titre) > 200) { // error }`.
- **Test:** Add `ForumTest::testTopicTitleMaxLengthEnforced()` testing 201-char title.

### PASS1-MEDIUM-035: BBCode [url] missing nofollow attribute

- **Files:** `includes/bbcode.php` line 18
- **Fix:** Change the generated `<a href="...">` to `<a href="..." rel="nofollow noopener noreferrer">`. User-submitted URLs should always have `nofollow` to avoid PageRank manipulation and `noopener noreferrer` for security (prevents target page from accessing `window.opener`).
- **Test:** Add `BBCodeTest::testUrlTagHasNofollowAttribute()`.

---

## Batch 13: MEDIUM — Tutorial Correctness

**Prerequisite:** None

### PASS1-MEDIUM-036: regles.php says "protection de 5 jours" but config is 3 days

- **Files:** `regles.php` line 274
- **Fix:** Replace the hardcoded "5 jours" with a PHP expression: `<?= BEGINNER_PROTECTION_SECONDS / SECONDS_PER_DAY ?> jours`. Also audit regles.php for other hardcoded numeric values that could be mismatched with config.php constants (PASS1-MEDIUM-039 adds more; combine the audit).
- **Test:** Add `RulesTest::testBeginnerProtectionDaysMatchConfig()`.

### PASS1-MEDIUM-037: Tutorial missions have no sequential enforcement

- **Files:** `tutoriel.php` line 170
- **Fix:** Before allowing a mission reward claim, add a loop checking all prior missions are completed:
  ```php
  for ($i = 1; $i < $missionIndex; $i++) {
      if (!isTutorialMissionComplete($login, $i)) {
          // Return error: complete previous missions first
      }
  }
  ```
  This prevents players from skipping to high-reward missions.
- **Test:** Add `TutorialTest::testCannotSkipMissions()`.

### PASS1-MEDIUM-038: Tutorial reward energy added without storage cap

- **Files:** `tutoriel.php` line 199
- **Fix:** Replace the direct `UPDATE autre SET energie = energie + ?` with a capped version:
  ```sql
  UPDATE autre SET energie = LEAST(energie + ?, ?) WHERE login = ?
  ```
  with the max storage value (from `MAX_ENERGIE` config constant) as the cap parameter.
- **Test:** Add `TutorialTest::testEnergyRewardCappedAtMax()`.

### PASS1-MEDIUM-039: Tutorial reward values are hardcoded

- **Files:** `tutoriel.php` lines 28–148
- **Fix:** Extract all reward values to a `$TUTORIAL_REWARDS` array in `config.php`:
  ```php
  define('TUTORIAL_REWARDS', [
      1 => ['type' => 'energie', 'amount' => 100],
      2 => ['type' => 'atomes', 'quantite' => 50, 'type_atome' => 'carbone'],
      // ...
  ]);
  ```
  Reference `TUTORIAL_REWARDS[$missionId]` in tutoriel.php. This allows balance tuning without modifying game logic.
- **Test:** Add `TutorialTest::testAllRewardValuesFromConfig()` asserting no numeric literals in tutoriel.php reward dispatch.

---

## Batch 14: MEDIUM — UI/Display Fixes

**Prerequisite:** None

### PASS1-MEDIUM-046: CSS typo — semicolon instead of colon in height property

- **Files:** `includes/style.php` line 260; `includes/display.php` line 164
- **Fix:** Change `height; 32px` to `height: 32px` in both files. This CSS property is silently ignored by browsers, causing layout breakage in affected elements (likely atom icons or progress bars). Simple one-character fix.
- **Test:** Visual regression note; no automated test needed — add to manual QA checklist.

### PASS1-MEDIUM-047: CSP allows style-src 'unsafe-inline'

- **Files:** `includes/layout.php` line 3
- **Fix:** This is a large refactor. Approach: (a) Add a nonce to the CSP style-src directive using the existing `cspNonce()` mechanism already in place for scripts: `style-src 'self' 'nonce-{$nonce}' fonts.googleapis.com`. (b) Audit inline `style=""` attributes — move them to named CSS classes. (c) For the Framework7 dynamic styles that require inline, use the nonce attribute. This is a multi-file refactor; budget 2-3 commits for full removal. As a first step, add the nonce to style-src even if unsafe-inline remains — it narrows the attack surface.
- **Test:** Add `CSPTest::testStyleSrcDoesNotContainUnsafeInline()` — this will fail initially until the refactor is complete; mark as `@todo`.

### PASS1-MEDIUM-048: Email maxlength="25" too short in cardsprivate.php

- **Files:** `includes/cardsprivate.php` line 108; `includes/config.php`
- **Fix:** Define `EMAIL_MAX_LENGTH = 100` in config.php. Use `maxlength="<?= EMAIL_MAX_LENGTH ?>"` in the HTML input. Update server-side validation to check `mb_strlen($email) <= EMAIL_MAX_LENGTH`. RFC 5321 allows 254 characters; 100 is a reasonable practical limit.
- **Test:** Add `AccountTest::testEmailMaxLengthFromConfig()`.

### PASS1-MEDIUM-049: debutCarte $overflow parameter not escaped

- **Files:** `includes/ui_components.php` line 27
- **Fix:** Apply `htmlspecialchars($overflow, ENT_QUOTES, 'UTF-8')` to the `$overflow` parameter before emitting it into the HTML. Although this parameter is typically developer-controlled, defense in depth requires escaping all values that reach HTML output.
- **Test:** Add `UITest::testDebutCarteOverflowEscaped()`.

### PASS1-MEDIUM-050: debutCarte $titre allows raw HTML from callers (alliance tags in guerre.php)

- **Files:** `includes/ui_components.php` lines 16–21; `guerre.php` line 33
- **Fix:** In `guerre.php` (and any other caller passing untrusted content as `$titre`), escape the alliance tag before passing: `debutCarte(htmlspecialchars($tagAlliance, ENT_QUOTES, 'UTF-8') . ' - ...', ...)`. Alternatively, escape inside `debutCarte()` itself but document that callers must not pass pre-escaped HTML if they want bold/markup in titles.
- **Test:** Add `UITest::testDebutCarteAllianceTagEscaped()`.

---

## Batch 15: MEDIUM — Compounds & Chemistry Module

**Prerequisite:** None

### PASS1-MEDIUM-051: Catalyst weekly rotation is fragile — adding catalysts shifts all weeks

- **Files:** `includes/catalyst.php` line 64
- **Fix:** Add a comment block explaining: "The weekly rotation is `(weekNumber % count($CATALYSTS))`. Adding or removing catalysts will shift all future weeks. To avoid disruption: always append new catalysts to the end of the array, never remove mid-season. If a mid-season change is needed, update the DB with the new week->catalyst mapping." Alternatively store the week rotation index in the DB (`other` table or a new `game_state` table). For now, the documentation comment is sufficient.
- **Test:** Add `CatalystTest::testRotationStableWithCurrentCatalystCount()` as a regression guard.

### PASS1-MEDIUM-052: getActiveCompounds() lacks FOR UPDATE inside activateCompound()

- **Files:** `includes/compounds.php` line 138
- **Fix:** When `getActiveCompounds()` is called inside `activateCompound()` (which runs inside `withTransaction()`), change the query to append `FOR UPDATE` to the SELECT. Without the lock, two concurrent activations could both read "0 active compounds" and both proceed, exceeding the maximum active compound limit.
- **Test:** Add `CompoundTest::testConcurrentActivationDoesNotExceedLimit()`.

### PASS1-MEDIUM-053: coefDisparition() doesn't handle null stabilisateur row

- **Files:** `includes/formulas.php` line 228
- **Fix:** Add null check:
  ```php
  if ($stabilisateur === null || !isset($stabilisateur['stabilisateur'])) {
      return $baseDecayRate; // return default decay with no stabilisateur
  }
  ```
  A player who has never built a stabilisateur building will have no row in the relevant table, causing a PHP notice or fatal on `$stabilisateur['stabilisateur']`.
- **Test:** Add `FormulasTest::testCoefDisparitionHandlesNullStabilisateur()`.

### PASS1-MEDIUM-054: cleanupExpiredCompounds runs every laboratoire.php load

- **Files:** `laboratoire.php` line 31
- **Fix:** Add probabilistic guard: `if (mt_rand(1, 20) === 1) { cleanupExpiredCompounds($base); }`. This reduces the cleanup from 100% of page loads to ~5%, matching the pattern established for GC in the codebase (e.g., rate limiter cleanup). Expired compounds don't need immediate removal; they are filtered by expiry timestamp at read time.
- **Test:** Statistical test not practical; add `// GC runs ~5% of requests` comment and verify no performance regression.

---

## Batch 16: MEDIUM — Minor UI and Display Polish

**Prerequisite:** None

### PASS1-MEDIUM-055: Countdown timer has no timezone label

- **Files:** `js/countdown.js` line 19 (or wherever the countdown renders)
- **Fix:** Add `" (heure de Paris)"` to the countdown string, or add a `<small>` element below the countdown display: `<small>Heure de Paris (UTC+1/+2)</small>`. Players in other timezones may otherwise be confused about when the season ends for them.
- **Test:** Visual only — add to manual QA checklist.

### PASS1-MEDIUM-056: News content uses fragile regex attribute stripping

- **Files:** `index.php` lines 115–120
- **Fix:** Since news is admin-only content (only admins post news), the fragile regex is acceptable IF confirmed admin-only. Add a clear comment: `// News entries created by admins only — see inscription role check in admin/news.php`. If non-admins can ever post news, switch to HTML Purifier. For now: document the assumption and ensure news creation is admin-gated.
- **Test:** Add `SecurityTest::testNewsCreationRequiresAdminRole()`.

### PASS1-LOW-001: Residual $_SESSION['mdp'] unset dead code

- **Files:** `includes/basicpublicphp.php` line 15
- **Fix:** Remove `unset($_SESSION['mdp'])`. The MD5 session migration was removed in Phase 10 Batch I (commit referenced in MEMORY.md). This line is now dead code that serves no purpose.
- **Test:** No test needed — dead code removal.

---

## Batch 17: LOW — Security Hardening & Dead Code Cleanup

**Prerequisite:** None

### PASS1-LOW-002: Admin panel missing CSP headers

- **Files:** `admin/index.php`, `admin/supprimercompte.php`, `admin/ip.php`, `admin/multiaccount.php`
- **Fix:** Add `require_once '../includes/csp.php'; $nonce = cspNonce();` and the corresponding CSP header call at the top of each admin file, matching the pattern in non-admin pages. Admin pages process sensitive data and deserve stronger CSP enforcement, not weaker.
- **Test:** Add `CSPTest::testAdminPagesHaveCSPHeaders()` checking all 4 files.

### PASS1-LOW-003: Rate limiter files not automatically cleaned up

- **Files:** `includes/rate_limiter.php` lines 19–38
- **Fix:** Add probabilistic cleanup at the start of `rateLimitCheck()`:
  ```php
  if (mt_rand(1, 100) === 1) {
      array_map('unlink', glob(RATE_LIMIT_DIR . '*.json') ?: []);
      // Only delete files older than max window
  }
  ```
  More precisely, only delete files where the timestamp inside the JSON is older than the maximum rate limit window (e.g., 3600s). This prevents unbounded accumulation of stale files in `data/rates/`.
- **Test:** Add `RateLimiterTest::testStaleFilesEventuallyCleanedUp()`.

### PASS1-LOW-005: Dead code — $hydrogeneTotal calculated twice in combat.php

- **Files:** `includes/combat.php` lines 435–463
- **Fix:** Remove the first (dead) calculation at lines 435–438. The second calculation at lines 439–463 is the one actually used. The first block was likely left over from a refactor. Verify by checking that neither variable from the first block is referenced after line 438.
- **Test:** No test needed — dead code removal. Run existing `CombatTest` suite to confirm no regression.

### PASS1-LOW-006: strip_tags allows img event handlers in rapports.php

- **Files:** `rapports.php` line 85
- **Fix:** After `strip_tags($content, '<img><b><i><u>')`, add:
  ```php
  $content = preg_replace('/\s+on\w+="[^"]*"/i', '', $content);
  $content = preg_replace('/\s+on\w+=\'[^\']*\'/i', '', $content);
  ```
  This strips `onerror`, `onload`, `onclick` etc. from allowed tags. Alternatively switch to `DOMDocument`-based sanitization.
- **Test:** Add `SecurityTest::testRapportImgEventHandlersStripped()`.

### PASS1-LOW-007: 4 redundant DB queries in medailles.php for same player

- **Files:** `medailles.php` lines 32–46
- **Fix:** Remove the first 3 individual-column SELECT queries and rely solely on `$donnees2 = dbFetchOne($base, "SELECT * FROM membre WHERE login = ?", [$joueur])`. Access fields as `$donnees2['carbone']`, `$donnees2['nb_victoires']` etc. This reduces 4 queries to 1.
- **Test:** Add `MedalTest::testPageLoadsWithSinglePlayerQuery()` — check via query count assertion if available, or simply add a `@performance` doc tag.

---

## Batch 18: LOW — Gameplay & UX Small Fixes

**Prerequisite:** None

### PASS1-LOW-008: Prestige shop button not disabled when can't afford

- **Files:** `prestige.php` lines 140–145
- **Fix:** In the button rendering loop, add: `<?= !$canAfford ? 'disabled' : '' ?>` to the `<button>` element. Also add `class="button-disabled"` for CSS styling. This prevents confusion where players click a purchase button and get an error message rather than clear visual affordance.
- **Test:** Add `PrestigeTest::testShopButtonDisabledWhenCantAfford()`.

### PASS1-LOW-009: Attack section in bilan.php missing formation description

- **Files:** `bilan.php` line 319
- **Fix:** Add `<small><?= htmlspecialchars($formationInfo['desc']) ?></small>` below the formation name in the attack bonus section. The `$formationInfo` array already contains a `desc` key (from config.php `FORMATION_CONFIG`); it's just not being displayed.
- **Test:** Add `BilanTest::testFormationDescriptionDisplayed()`.

### PASS1-LOW-010: Daily leaderboard toggle loses sort parameter

- **Files:** `classement.php` line 83
- **Fix:** Change the daily toggle link from `?mode=daily` to `?mode=daily&clas=<?= htmlspecialchars($_GET['clas'] ?? 'points') ?>`. This preserves the current sort column when toggling between daily and all-time views.
- **Test:** Add `ClassementTest::testDailyTogglePreservesSortParam()`.

### PASS1-LOW-011: Pagination loses mode parameter in total leaderboard

- **Files:** `classement.php` line 294 (`$adresse` construction)
- **Fix:** Include `mode` and `clas` in `$adresse`: `$adresse = 'classement.php?mode=' . urlencode($_GET['mode'] ?? 'all') . '&clas=' . urlencode($_GET['clas'] ?? 'points') . '&page='`. Without this, clicking page 2 of the leaderboard resets to default mode and sort.
- **Test:** Add `ClassementTest::testPaginationPreservesModeAndClas()`.

### PASS1-LOW-012: Comeback bonus fires for brand-new players

- **Files:** `includes/player.php` line 1112
- **Fix:** Add guard in `checkComebackBonus()`:
  ```php
  $registeredAt = $membre['dateInscription'];
  $minAgeForComeback = COMEBACK_ABSENCE_DAYS * SECONDS_PER_DAY;
  if ((time() - $registeredAt) < $minAgeForComeback) {
      return; // Player too new to qualify for comeback bonus
  }
  ```
  A new player's `lastLogin` is epoch 0, making `absenceDays` enormous and incorrectly triggering the comeback bonus.
- **Test:** Add `PlayerTest::testNewPlayerDoesNotGetComebackBonus()`.

### PASS1-LOW-035: Comeback bonus checked every page load (performance)

- **Files:** `includes/basicprivatephp.php` line 135
- **Fix:** Add session flag: `if (!isset($_SESSION['comeback_checked'])) { checkComebackBonus($base, $login); $_SESSION['comeback_checked'] = true; }`. The comeback bonus check requires a DB query; running it on every page load is wasteful. The flag resets naturally at session expiry.
- **Test:** Add `PlayerTest::testComebackCheckOnlyOncePerSession()`.

### PASS1-LOW-036: $vainqueurManche may be uninitialized before ?? operator

- **Files:** `includes/basicprivatephp.php` line 169
- **Fix:** Add `$vainqueurManche = null;` before the try block that populates it. While `??` handles null, an undefined variable generates a PHP 8 deprecation notice; explicit initialization is cleaner.
- **Test:** No test needed — notice suppression. Add to lint/static analysis run.

---

## Batch 19: LOW — Forum, Tutorial & Message Cleanup

**Prerequisite:** None

### PASS1-LOW-013: MathJax CDN lacks SRI hash

- **Files:** `sujet.php` line 256
- **Fix:** Add integrity and crossorigin attributes to the MathJax `<script>` tag. Obtain the current SRI hash from https://www.srihash.org/ for the pinned MathJax version. Example: `<script src="https://cdn.mathjax.org/..." integrity="sha384-..." crossorigin="anonymous">`. This is also partly addressed by PASS1-HIGH-021 (conditional loading).
- **Test:** Add `CSPTest::testMathJaxHasSRIHash()`.

### PASS1-LOW-014: Broken HTML nesting in messages.php

- **Files:** `messages.php` lines 65–68
- **Fix:** Change `</td></a>` to `</a></td>`. The `<a>` must close before the `<td>` closes since `<a>` is inline and cannot contain block-level elements, and `<td>` must close after its inline content. This fixes a subtle rendering bug in certain browsers.
- **Test:** HTML validation check — add `HtmlValidationTest::testMessagesPageValidHTML()`.

### PASS1-LOW-015: messagesenvoyes.php has no pagination (hardcoded LIMIT 200)

- **Files:** `messagesenvoyes.php` line 10
- **Fix:** Implement pagination matching `messages.php`. Add `$page = max(1, intval($_GET['page'] ?? 1)); $offset = ($page - 1) * MESSAGES_PER_PAGE;` where `MESSAGES_PER_PAGE = 20`. Add `LIMIT ?, ?` to the query and render pagination links. LIMIT 200 in a single query is a performance concern for active senders.
- **Test:** Add `MessagingTest::testSentMessagesPaginated()`.

### PASS1-LOW-016: Forum ban check uses inconsistent date comparison methods

- **Files:** `forum.php` line 29; `listesujets.php` line 30; `sujet.php` line 119
- **Fix:** Standardize all three to use `WHERE login = ? AND banni_jusqua > UNIX_TIMESTAMP()` (timestamp comparison) rather than mixing string date comparisons and UNIX comparisons. Choose one approach, apply consistently, and ensure the `banni_jusqua` column is an INT UNIX timestamp.
- **Test:** Add `ForumTest::testBanCheckConsistent()` verifying ban enforcement works identically in all three entry points.

### PASS1-LOW-017: Espionage tutorial mission uses fragile LIKE query on report title

- **Files:** `tutoriel.php` line 113
- **Fix:** Add a code comment: `// FRAGILE: This checks for espionage completion by matching report title. If report title format changes, this mission will break. TODO: add rapport_type column to rapports table and check rapport_type = 'espionage' instead.` This documents the technical debt without requiring an immediate DB migration.
- **Test:** Add `TutorialTest::testEspionageMissionDetectionWorksWithCurrentTitleFormat()`.

### PASS1-LOW-018: Espionage tutorial unused $nbNeutrinos variable

- **Files:** `tutoriel.php` line 110
- **Fix:** Remove the `$nbNeutrinos = ...` assignment if the variable is never used after line 110. Verify with `grep -n 'nbNeutrinos' tutoriel.php`. If unused, removal prevents static analysis warnings.
- **Test:** No test needed — dead code removal.

### PASS1-LOW-033: $javascript parameter is dead code in BBCode function

- **Files:** `includes/bbcode.php` line 2
- **Fix:** Either (a) implement the conditional `[latex]` gating using this parameter (connecting to PASS1-HIGH-021), or (b) remove the parameter if no callers pass it. Check with `grep -rn 'bbcode.*javascript\|bbCode.*true\|bbCode.*false' .`. If all callers omit it, remove the parameter and the dead branch.
- **Test:** Add `BBCodeTest::testLatexConditionalOnJavascriptParam()`.

---

## Batch 20: LOW — Alliance, Market & Resource Small Fixes

**Prerequisite:** None

### PASS1-LOW-019: Password fields missing maxlength="72" for bcrypt truncation

- **Files:** `inscription.php` lines 65–66
- **Fix:** Add `maxlength="72"` to both the password and password-confirmation `<input>` fields. bcrypt silently truncates passwords longer than 72 bytes, meaning two passwords that differ only after the 72nd character are treated as identical. The HTML attribute provides UX-level prevention. Server-side, add: `if (mb_strlen($mdp) > 72) { // error: password too long }`.
- **Test:** Add `RegistrationTest::testPasswordMaxLength72Enforced()`.

### PASS1-LOW-020: Alliance creation regex hardcodes {3,16} instead of using constants

- **Files:** `alliance.php` line 36
- **Fix:** Change `'/^[A-Z0-9]{3,16}$/i'` to `'/^[A-Z0-9]{' . ALLIANCE_TAG_MIN_LENGTH . ',' . ALLIANCE_TAG_MAX_LENGTH . '}$/i'`. This is the same fix as PASS1-HIGH-020 (rename path) but for the creation path. Both should be fixed together.
- **Test:** Add `AllianceTest::testCreationTagRegexUsesConstants()`.

### PASS1-LOW-021: Alliance name not validated for special characters

- **Files:** `allianceadmin.php` lines 54–72
- **Fix:** Add regex validation: `if (!preg_match('/^[\w\s\-\.\']{3,50}$/u', $nom)) { // error }`. The alliance name allows Unicode word characters, spaces, hyphens, dots, and apostrophes, max 50 characters. This prevents HTML injection via alliance names that aren't caught by htmlspecialchars later.
- **Test:** Add `AllianceTest::testAllianceNameValidation()`.

### PASS1-LOW-022: War declaration duplicate check not inside transaction

- **Files:** `allianceadmin.php` lines 268–272
- **Fix:** Wrap the war existence check and the war INSERT into `withTransaction()` matching the fix pattern from PASS1-MEDIUM-013 (pact duplicate). Add `FOR UPDATE` to the SELECT.
- **Test:** Add `AllianceTest::testWarDeclarationAtomicDuplicateCheck()`.

### PASS1-LOW-023: No rate limiting on market buy/sell

- **Files:** `marche.php` lines 181–276 (buy), 278–394 (sell)
- **Fix:** Add `rateLimitCheck('market_buy_' . $_SESSION['login'], 30, 60)` (30 per minute) to the buy handler and same for sell. This prevents automated buy-sell cycling for trade point farming while keeping manual trading fluid.
- **Test:** Add `MarketTest::testMarketBuySellRateLimited()`.

### PASS1-LOW-024: Chart timestamp lacks explicit timezone declaration

- **Files:** `marche.php` line 641
- **Fix:** Ensure `date_default_timezone_set('Europe/Paris')` is called before the chart timestamp generation (handled by PASS1-MEDIUM-028 in config.php). Add a comment at line 641 referencing the timezone setting.
- **Test:** Covered by PASS1-MEDIUM-028 test.

### PASS1-LOW-034: updateRessources molecule decay not atomic per-molecule

- **Files:** `includes/game_resources.php` lines 219–228
- **Fix:** Wrap the per-molecule decay loop in a transaction: `withTransaction($base, function() use ($base, $login, $molecules) { foreach ($molecules as $m) { ... decay UPDATE ... } });`. Without a transaction, a partial failure midway through the molecule list leaves some molecules decayed and others not, creating inconsistent state.
- **Test:** Add `GameResourcesTest::testMoleculeDecayIsAtomic()`.

---

## Batch 21: LOW — Miscellaneous Display & Utility

**Prerequisite:** None

### PASS1-LOW-025: intval() buy quantity missing explicit > 0 check

- **Files:** `marche.php` lines 183, 185
- **Fix:** After `$quantite = intval($_POST['quantite'])`, add `if ($quantite <= 0) { echo json_encode(['error' => 'Quantité invalide']); exit; }`. Currently `intval('0')` or `intval('-5')` returns 0 or -5, which could trigger a zero-cost purchase or a negative inventory operation.
- **Test:** Add `MarketTest::testNegativeQuantityRejected()` and `testZeroQuantityRejected()`.

### PASS1-LOW-027: version.php has no dynamic build info

- **Files:** `version.php`
- **Fix:** Add `$gitHash = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?? 'unknown');` and display it alongside the version string. Wrap in `if (function_exists('shell_exec'))` guard. This makes it easy to correlate a bug report with a specific commit.
- **Test:** No automated test; visual verification.

### PASS1-LOW-028: Page title is static across all pages

- **Files:** `includes/meta.php` line 19; all page files
- **Fix:** Define `$pageTitle = 'The Very Little War';` as a default in `meta.php`. Allow individual pages to override it by setting `$pageTitle = 'Classement — TVLW';` before including `basicprivatephp.php`. Update `meta.php` to use `<title><?= htmlspecialchars($pageTitle) ?></title>`. This improves browser tab usability and SEO.
- **Test:** Add `UITest::testPageTitlesArePageSpecific()` for a sample of pages.

### PASS1-LOW-029: progressBar() division by zero when $vieMax = 0

- **Files:** `includes/ui_components.php` line 482
- **Fix:** Change the percentage calculation to `$pct = $vieMax > 0 ? min(100, ($vie / $vieMax) * 100) : 0;`. A fresh player with no built units could trigger `$vieMax = 0` and cause a division-by-zero PHP warning.
- **Test:** Add `UITest::testProgressBarHandlesZeroMax()`.

### PASS1-LOW-030: Copyright footer hardcodes "V2.0.1.0"

- **Files:** `includes/copyright.php` line 5; `includes/config.php`
- **Fix:** Define `define('GAME_VERSION', 'V4.0');` (or appropriate version) in `config.php`. Use `<?= GAME_VERSION ?>` in copyright.php. This keeps the version in one place.
- **Test:** Add `ConfigTest::testGameVersionConstantDefined()`.

### PASS1-LOW-031: transformInt() missing numeric clamp

- **Files:** `includes/display.php` lines 333–348
- **Fix:** Add clamp before the suffix logic:
  ```php
  $n = intval($n); // ensure integer
  if ($n < 0) return '0'; // or handle negatives explicitly
  ```
  The function formats large integers (1K, 1M etc.) but could produce unexpected strings for negative values or non-numeric input.
- **Test:** Add `DisplayTest::testTransformIntHandlesNegative()` and `testTransformIntHandlesNonNumeric()`.

### PASS1-LOW-032: molecule.php initializes $mx to oxygene instead of 0

- **Files:** `molecule.php` line 16
- **Fix:** Change `$mx = $molecule['oxygene']` to `$mx = 0`. Then let the subsequent loop fill `$mx` with the actual maximum value across all atoms. The current code incorrectly seeds the maximum with the oxygene value, causing the max-detection logic to fail for atoms with more than the initial oxygene count.
- **Test:** Add `MoleculeTest::testMxInitializedToZero()` with a test case where oxygene is not the maximum atom.

---

## DB-SCHEMA Batch: MEDIUM — Column NOT NULL Constraints & Missing Index

**Prerequisite:** Batch 2
**Note:** These are all DDL-only changes grouped into a single migration `migrations/0035_fix_not_null_constraints.sql`.

### PASS1-MEDIUM-040: constructions missing NOT NULL constraints

- **Files:** New migration `migrations/0035_fix_not_null_constraints.sql`
- **Fix:**
  ```sql
  ALTER TABLE constructions MODIFY coffrefort INT NOT NULL DEFAULT 0;
  ALTER TABLE constructions MODIFY formation TINYINT NOT NULL DEFAULT 0;
  ```

### PASS1-MEDIUM-041: molecules.isotope missing NOT NULL

- **Fix:** `ALTER TABLE molecules MODIFY isotope TINYINT NOT NULL DEFAULT 0;`

### PASS1-MEDIUM-042: statistiques catalyst columns missing NOT NULL

- **Fix:**
  ```sql
  ALTER TABLE statistiques MODIFY catalyst INT NOT NULL DEFAULT 0;
  ALTER TABLE statistiques MODIFY catalyst_week INT NOT NULL DEFAULT 0;
  ```

### PASS1-MEDIUM-043: Alliance research columns missing NOT NULL

- **Fix:** Apply `NOT NULL DEFAULT 0` to all 5 alliance research columns added in migration 0010.

### PASS1-MEDIUM-044: spec columns missing NOT NULL

- **Fix:** Apply `NOT NULL DEFAULT 0` to `spec_combat`, `spec_economy`, `spec_research` in the membre or autre table.

### PASS1-MEDIUM-045: membre.session_token has no index

- **Fix:** `ALTER TABLE membre ADD INDEX idx_session_token (session_token(16));` — the session token is used for auth on every private page load; indexing it is critical for performance. A 16-char prefix index is sufficient for uniqueness selectivity.

### PASS1-MEDIUM-057: alliance_left_at column missing from membre table

- **Fix:** `ALTER TABLE membre ADD COLUMN alliance_left_at INT DEFAULT NULL;` — required by PASS1-MEDIUM-012 rejoin cooldown.

### PASS1-LOW-037: Migration 0016 TEMPORARY TABLE INSERT column-order-dependent

- **Files:** `migrations/0016_connectes_primary_key.sql`
- **Fix:** In the migration, change the INSERT into the temporary table from implicit column-order to explicit column names: `INSERT INTO connectes_new (ip, login, timestamp) SELECT ip, login, timestamp FROM connectes;`. Add a comment warning future migration authors about column-order-dependent INSERTs.
- **Test:** Add to migration idempotency test suite.

---

## DB-DOC: Design Notes (No Code Change Required)

### PASS1-HIGH-026: membre lookup via secondary index, not clustered PK

- **Assessment:** MariaDB/InnoDB uses the first UNIQUE NOT NULL column as the clustered key if no explicit PRIMARY KEY is defined. `membre.login` is the effective clustered key. This is by design for a login-keyed game; document as intentional. If query performance on `membre.id` lookups becomes an issue, consider restructuring FKs to use the id (INT) column.
- **Action:** Add comment to `docs/game/09-BALANCE.md` DB schema section explaining the design choice.

### PASS1-LOW-004: Dispersée formation overkill is wasted

- **Assessment:** Design decision. Document in `docs/game/09-BALANCE.md`: "Dispersée: overkill damage is not redistributed — choose Dispersée against large numbers of equal-HP units for best efficiency."

### PASS1-LOW-026: loader.js is a local copy of Google Charts loader — unversioned

- **Files:** `loader.js` (project root or `/js/loader.js`)
- **Assessment:** The file is a locally-hosted copy of the Google Charts loader script with no version pin or integrity hash. This creates two risks: (1) the local copy may fall out of sync with the Google Charts API it loads, silently breaking charts; (2) there is no SRI protection if the file is ever served from a CDN.
- **Fix (preferred):** Replace the local copy with the canonical CDN reference and add an SRI hash:
  ```html
  <script src="https://www.gstatic.com/charts/loader.js"
          integrity="sha384-[hash-of-pinned-version]"
          crossorigin="anonymous"></script>
  ```
  Obtain the SRI hash for the current stable Google Charts loader version via https://www.srihash.org/. Document the pinned version in a comment. If offline/CDN-free operation is required, keep the local copy but record the source version and date in a file header comment: `// Google Charts loader v47 — downloaded 2026-03-07 from https://www.gstatic.com/charts/loader.js`.
- **Action:** Whichever approach is chosen, commit the version record so future maintainers know what they are running.

### PASS1-LOW-038 (Economy): Resource node bonus has no distance falloff

- **Assessment:** Design decision. Current flat bonus within radius is simpler and more predictable for players. Document in `regles.php` resource node section. Revisit post-playtesting if map control becomes too polarized.

---

## Commit Message Templates

```
fix(db): fix connexion charset from utf8mb4 to latin1 [PASS1-CRITICAL-001]
fix(migration): add IF NOT EXISTS to 0026 totalPoints index [PASS1-CRITICAL-002]
fix(migration): convert utf8mb4 tables to latin1, add missing FKs [PASS1-HIGH-001..005]
fix(migration): add grades/statutforum PKs, reponses_sondage FKs [PASS1-HIGH-006..010]
fix(xss): escape session login in JS, fix onclick CSP, add lang=fr [PASS1-HIGH-011..013]
fix(xss): escape alliance tags in allianceadmin war/pact tables [PASS1-HIGH-014..015]
fix(auth): fix supprimerJoueur compounds, comeback FOR UPDATE, prestige display [PASS1-HIGH-016..018]
fix(ranking): filter x=-1000 players, enforce alliance tag min length [PASS1-HIGH-019..020]
fix(forum): MathJax conditional, admin broadcast auth, message reply auth [PASS1-HIGH-021..023]
fix(docs): correct formulas.php comment +50%→+20%, isotope stable HP +30%→+40% [PASS1-HIGH-024..025]
fix(security): email not logged, CSRF before rate limit, session token on deconnexion [PASS1-MEDIUM-001..003]
fix(auth): email change requires password, validateEmail in compte.php [PASS1-MEDIUM-004..005]
fix(combat): re-validate energy under FOR UPDATE, freshen beginner check [PASS1-MEDIUM-006..007]
fix(economy): JS revenue multiplier, market price floor, donation storage cap [PASS1-MEDIUM-008..010]
fix(alliance): strict compare, rejoin cooldown, atomic pact check, grade perms [PASS1-MEDIUM-011..015]
fix(alliance): quit cleanup invitations, atomic tag/name change [PASS1-MEDIUM-016..017]
fix(market): re-read prices inside transaction, atomic tradeVolume, depot inside tx [PASS1-MEDIUM-018..020]
fix(prestige): day-1 streak display, dual medal arrays, frozen rankings, medailles XSS [PASS1-MEDIUM-021..025]
fix(prestige): PP all medal categories, vacation mode central, timezone, last_activity [PASS1-MEDIUM-026..030]
fix(forum): rate limits on topic/reply/pm, title maxlength, bbcode nofollow [PASS1-MEDIUM-031..035]
fix(tutorial): beginner days from config, sequential missions, energy cap, reward config [PASS1-MEDIUM-036..039]
fix(ui): CSS height typo, CSP style nonce, email maxlength, debutCarte escaping [PASS1-MEDIUM-046..050]
fix(compounds): catalyst doc, FOR UPDATE in activateCompound, null stabilisateur, GC rate [PASS1-MEDIUM-051..054]
fix(ui): countdown timezone label, news doc, remove dead mdp session unset [PASS1-MEDIUM-055..056, LOW-001]
fix(security): admin CSP headers, rate limiter GC, remove dead combat code, img events [PASS1-LOW-002..007]
fix(ux): prestige button disabled, formation desc in bilan, leaderboard params, comeback guards [PASS1-LOW-008..012, 035..036]
fix(forum): MathJax SRI, messages nesting, sent pagination, ban date consistency [PASS1-LOW-013..018, 033]
fix(misc): password maxlength, alliance regex constants, name validation, war atomic, market rate [PASS1-LOW-019..024, 034]
fix(display): buy qty check, version hash, page titles, progressBar zero, copyright const [PASS1-LOW-025..032]
fix(migration): NOT NULL constraints, session_token index, alliance_left_at column [PASS1-MEDIUM-040..045, LOW-037]
```

---

## Estimated Effort Summary

| Batch | Findings | Estimated Hours | Priority |
|---|---|---|---|
| 0 | 2 | 1h | MUST DO FIRST |
| 1 | 5 | 2h | MUST DO SECOND |
| 2 | 5 | 2h | HIGH |
| 3 | 5 | 3h | HIGH |
| 4 | 5 | 3h | HIGH |
| 5 | 5 | 2h | HIGH |
| 6 | 5 | 3h | MEDIUM |
| 7 | 5 | 3h | MEDIUM |
| 8 | 5 | 3h | MEDIUM |
| 9 | 5 | 3h | MEDIUM |
| 10 | 5 | 2h | MEDIUM |
| 11 | 5 | 2h | MEDIUM |
| 12 | 5 | 2h | MEDIUM |
| 13 | 4 | 2h | MEDIUM |
| 14 | 5 | 3h | MEDIUM |
| 15 | 4 | 2h | MEDIUM |
| 16 | 3+1 | 1h | LOW-MEDIUM |
| 17 | 5 | 2h | LOW |
| 18 | 6 | 2h | LOW |
| 19 | 7 | 2h | LOW |
| 20 | 6 | 2h | LOW |
| 21 | 7 | 2h | LOW |
| DB-SCHEMA | 7 | 2h | MEDIUM |
| DB-DOC | 3 | 0.5h | INFO |
| **TOTAL** | **~120** | **~51h** | |

---

*Generated by knowledge-synthesizer agent on 2026-03-07. All file paths are relative to `/home/guortates/TVLW/The-Very-Little-War/`.*
