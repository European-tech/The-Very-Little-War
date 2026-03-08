# Remediation Plan — Ultra Audit Pass 7
**Date:** 2026-03-08
**Domains audited:** 21 (AUTH, INFRA-SECURITY, INFRA-DATABASE, ANTI-CHEAT, ADMIN, SEASON-RESET, FORUM, COMBAT, ESPIONAGE, ECONOMY, MARKET, BUILDINGS, COMPOUNDS, MAPS, SOCIAL, ALLIANCE-MGMT, GAME-CORE, PRESTIGE, RANKINGS, NOTIFICATIONS, INFRA-TEMPLATES)

---

## Summary: 5 critical, 9 high, 33 medium, 28 low (total: 75)

> **Social and Alliance-Mgmt** domains were fully clean (0 findings each).
> **Economy** domain had only 1 LOW and 3 clean INFO items.
> **Rankings** domain had only 1 LOW.

---

## Batch Dependencies

```
Batch 1 (Critical security)  — no dependencies
Batch 2 (Critical game logic) — no dependencies; can run parallel with Batch 1
Batch 3 (DB integrity — FKs/constraints) — no dependencies
Batch 4 (Combat safety)      — no dependencies
Batch 5 (Compounds bug)      — no dependencies
Batch 6 (Auth/session)       — no dependencies
Batch 7 (Admin/moderation)   — no dependencies
Batch 8 (Email/notifications) — no dependencies
Batch 9 (DB schema / indexes) — no dependencies; migrations run after code deploys
Batch 10 (Forum)             — no dependencies
Batch 11 (Anti-cheat)        — no dependencies
Batch 12 (Maps)              — no dependencies
Batch 13 (Infrastructure LOW items) — no dependencies
Batch 14 (LOW consistency / polish) — no dependencies
```

Batches 1–8 are independent of each other and can be executed concurrently. Batch 9 (migrations) should be deployed last among the infrastructure batches if any schema changes touch the same tables as code changes.

---

## Batch 1: Critical Security Fixes

### PASS7-CRITICAL-001: Unauthenticated IP enumeration via broken auth guard in moderation/ip.php
- **Domain:** ADMIN
- **File:** `moderation/ip.php:3`
- **Description:** `include("redirectionmotdepasse.php")` references a non-existent file. PHP silently continues past a failed `include`, so the page executes with no authentication guard. Any unauthenticated visitor can enumerate player accounts by IP address.
- **Fix:** Replace with `require_once(__DIR__ . '/mdp.php');` (the correct moderator auth file).

### PASS7-CRITICAL-002: Espionage defender notification logic is inverted
- **Domain:** ESPIONAGE
- **File:** `includes/game_actions.php:493`
- **Description:** The condition `if ($espionageThreshold < $espActions['nombreneutrinos'])` mirrors the success check, but it is used to send the defender a notification. The result is inverted: a **successful** spy triggers a defender alert (breaking spy secrecy), while a **failed** spy attempt goes undetected (breaking detection mechanics). Core game mechanic is backwards.
- **Fix:** Invert the condition to `if ($espionageThreshold >= $espActions['nombreneutrinos'])` so only failed espionage notifies the defender.

### PASS7-CRITICAL-003: Dynamic SQL column interpolation in augmenterBatiment/diminuerBatiment
- **Domain:** BUILDINGS
- **File:** `includes/player.php:637, 639, 674, 747, 749`
- **Description:** `$nom` and the computed HP column `$vieCol` are directly interpolated into SQL strings after an `in_array()` whitelist check. While the whitelist currently prevents SQL injection, the pattern is fragile: any bypass (reference aliasing, future code change to the whitelist) creates a direct SQL injection vector.
- **Fix:** Replace `in_array()` + string interpolation with an explicit `$columnMap` array keyed by building name. Derive `$safeCol` and `$safeVieCol = 'vie' . ucfirst($safeCol)` from the map. Never interpolate `$nom` directly.

### PASS7-CRITICAL-004: Silent resource loss on compound synthesis due to unchecked INSERT return
- **Domain:** COMPOUNDS
- **File:** `includes/compounds.php:116-119`
- **Description:** If the `INSERT INTO player_compounds` fails (e.g., unique constraint violation), `dbExecute()` returns false but the return value is not checked. Resources were already deducted at lines 110-113. The function returns `true` (success) while no compound is created and resources are permanently lost. An exploit path exists: synthesize a compound, activate it (removing it from the stored count), then synthesize again — the storage check passes but the INSERT silently fails.
- **Fix:** Check the return value of `dbExecute()` for the INSERT. If `$result === false`, throw a `\RuntimeException('INSERT_FAILED')` so the enclosing `withTransaction()` rolls back the resource deduction.

### PASS7-CRITICAL-005: Incorrect arithmetic in beginner protection time display
- **Domain:** PRESTIGE
- **File:** `attaquer.php:117`
- **Description:** `$attackerProtectionLeft = BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock(...) ? SECONDS_PER_DAY : 0) - time() + $attackerTimestamp` adds `$attackerTimestamp` (a large Unix epoch value) at the wrong position. The result is a nonsensically large number displayed in the error message shown to the attacker. The underlying **check** at line 116 is correct; only the displayed remaining time is broken.
- **Fix:** Correct operand order to: `$attackerProtectionLeft = $attackerTimestamp + BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock($_SESSION['login'], 'veteran') ? SECONDS_PER_DAY : 0) - time();`

---

## Batch 2: High — Combat Return Value Safety

### PASS7-HIGH-001: Formation type not range-validated in combat
- **Domain:** COMBAT
- **File:** `includes/combat.php:142`
- **Description:** Formation value is cast to `int` but never validated to be within `[0, 2]`. A corrupted DB row with `formation=999` causes all formation `if/elseif` branches to fall through, leaving no formation modifier applied — silently granting the defender no formation bonus.
- **Fix:** After casting, add: `if ($defenderFormation < 0 || $defenderFormation > 2) { $defenderFormation = FORMATION_DISPERSEE; logError("Combat: invalid formation ID for " . $actions['defenseur']); }`

### PASS7-HIGH-002: getSpecModifier() return not cast to float
- **Domain:** COMBAT
- **File:** `includes/combat.php:187-194`
- **Description:** Four calls to `getSpecModifier()` assume a numeric return. If the function returns `null` or non-numeric (DB lookup returns no row), the multiplication `$degatsAttaquant *= (1 + $specAttackMod)` produces unexpected/incorrect damage silently.
- **Fix:** Cast all four returns: `$specAttackMod = (float)($specAttackMod ?? 0.0);` (and same for specDefenseMod, specPillageMod, specHpMod).

### PASS7-HIGH-003: catalystEffect() return not cast to float
- **Domain:** COMBAT
- **File:** `includes/combat.php:163, 198, 453`
- **Description:** Three calls to `catalystEffect()` are used directly in arithmetic without a numeric type cast. A null return (unexpected DB state) silently corrupts damage calculations.
- **Fix:** Wrap all three: `$catalystAttackBonus = 1 + (float)(catalystEffect('attack_bonus') ?? 0.0);`

### PASS7-HIGH-004: Compound bonus array keys accessed without null-coalesce on legacy rows
- **Domain:** COMBAT
- **File:** `includes/combat.php:181-184`
- **Description:** `$actions['compound_atk_bonus']`, `$actions['compound_def_bonus']`, `$actions['compound_pillage_bonus']` are accessed directly. Pre-migration attack records may not have these columns, causing PHP 8.2 warnings on undefined array keys which could surface in error logs or be exposed if error display is on.
- **Fix:** Use null coalesce: `$compoundAttackBonus = (float)($actions['compound_atk_bonus'] ?? 0.0);` for all three.

### PASS7-HIGH-005: Resource UPDATE return value not checked in compound synthesis
- **Domain:** COMPOUNDS
- **File:** `includes/compounds.php:110-113`
- **Description:** The `UPDATE ressources SET $resource = GREATEST($resource - ?, 0) WHERE login = ?` statements do not check return values from `dbExecute()`. A silent DB failure would leave resources undeducted, granting a free compound.
- **Fix:** Check `$updated = dbExecute(...)` and throw `\RuntimeException('UPDATE_FAILED:' . $resource)` if `$updated === false`, allowing the transaction to roll back.

---

## Batch 3: High — Email and Database Integrity

### PASS7-HIGH-006: Email subjects with non-ASCII characters not MIME-encoded
- **Domain:** NOTIFICATIONS
- **File:** `includes/player.php:1242`
- **Description:** Email subjects containing French accents (e.g., "Égalité", "d'espionnage") are CRLF-stripped but NOT encoded with `mb_encode_mimeheader()`. Recipients may receive corrupted (mojibake) subject lines, and strict mail servers may reject the email.
- **Fix:** `$subject = mb_encode_mimeheader(str_replace(["\r", "\n"], '', $row['subject']), 'UTF-8');`

### PASS7-HIGH-007: Hardcoded TABLE_SCHEMA='tvlw' in 6 migrations breaks staging/CI
- **Domain:** INFRA-DATABASE
- **File:** `migrations/0055_declarations_fk.sql` through `migrations/0060_*.sql` (6 files)
- **Description:** Six migrations use `TABLE_SCHEMA='tvlw'` in `information_schema` queries. If the database is renamed, imported under a different schema name, or run in CI with a different DB name, these migrations silently skip FK creation with no error output.
- **Fix:** Replace `TABLE_SCHEMA='tvlw'` with `TABLE_SCHEMA=DATABASE()` in all six migration files.

### PASS7-HIGH-008: account_flags FK missing ON UPDATE CASCADE
- **Domain:** INFRA-DATABASE
- **File:** `migrations/0033_fix_utf8mb4_tables.sql:30-36`
- **Description:** `account_flags` has `ON DELETE CASCADE` but no `ON UPDATE CASCADE` on its FK to `membre.login`. If an admin renames a player's login, the FK constraint rejects the rename (or orphans the row depending on engine mode), causing data integrity failure.
- **Fix:** Add `ON UPDATE CASCADE` to the `account_flags` FK constraint via a new migration.

### PASS7-HIGH-009: Action-queue tables missing FKs to membre.login
- **Domain:** INFRA-DATABASE
- **File:** Tables `actionsattaques`, `actionsformation`, `actionsenvoi`, `actionsconstruction`
- **Description:** These four action-queue tables have `login` columns referencing player logins but declare no FK constraints. After `supprimerJoueur()`, orphaned rows remain in the queue and may be processed, causing runtime errors or phantom actions for deleted players.
- **Fix:** Add migration: `ALTER TABLE actionsattaques ADD FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;` (and same for the other three tables).

---

## Batch 4: Medium — Database Schema Hardening

### PASS7-MEDIUM-001: withTransaction depth counter not exception-safe on START TRANSACTION failure
- **Domain:** INFRA-DATABASE
- **File:** `includes/database.php:121-152`
- **Description:** The static `$depth` counter is incremented before the callable but if `START TRANSACTION` itself fails (e.g., DB connection drops), `$depth` is incremented without a matching decrement path. A subsequent call would issue a `SAVEPOINT` instead of `START TRANSACTION`, treating a top-level transaction as nested.
- **Fix:** Only increment `$depth` after the `START TRANSACTION` execute call succeeds.

### PASS7-MEDIUM-002: Missing index on resource_nodes.zone
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (resource_nodes creation migration)
- **Description:** `resource_nodes` is queried by `zone` on every map load and node generation cycle. No index on `zone` column causes full-table scans as node count grows.
- **Fix:** `ALTER TABLE resource_nodes ADD INDEX idx_zone (zone);`

### PASS7-MEDIUM-003: season_recap.recap_data stored as TEXT allows invalid JSON
- **Domain:** INFRA-DATABASE
- **File:** `migrations/0029_create_season_recap.sql`
- **Description:** `recap_data` is `TEXT`. MariaDB 10.11 supports `JSON` type with validation. Using `TEXT` allows invalid JSON to be stored silently, potentially causing `json_decode()` failures at display time.
- **Fix:** Change to `JSON NOT NULL` or add `CHECK (JSON_VALID(recap_data))`.

### PASS7-MEDIUM-004: compound_synthesis missing expiry index
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (compound_synthesis creation migration)
- **Description:** Compound expiry is checked per-player on every page that applies bonuses. No index on `expires_at` causes full-table scans as synthesis history accumulates.
- **Fix:** `ALTER TABLE compound_synthesis ADD INDEX idx_expires (expires_at);`

### PASS7-MEDIUM-005: login_attempts rows never purged after window expiry
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (login_attempts), `includes/rate_limiter.php`
- **Description:** Rate-limit records in `login_attempts` are never deleted after the window expires. On a live server over months, this table grows unboundedly.
- **Fix:** Add a periodic DELETE in the rate limiter cleanup path or as a cron job: `DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL ? SECOND`.

---

## Batch 5: Medium — Database Schema Hardening (continued)

### PASS7-MEDIUM-006: historique_alliances.action column is unvalidated VARCHAR
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (historique_alliances)
- **Description:** The `action` column stores free-form strings with no constraint. An application bug could write unknown action codes, breaking any switch/case display logic that expects a defined set.
- **Fix:** Convert to `ENUM('join','leave','kick','promote','demote','create','dissolve','pact','war')` via migration.

### PASS7-MEDIUM-007: voter_log missing unique constraint on (voter, target, date)
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (voter_log)
- **Description:** The voter TOCTOU fix in Phase 15 added a `FOR UPDATE` lock, but the DB has no UNIQUE constraint on `(voter, target, date)`. If the application lock is ever bypassed, duplicate votes can be inserted without DB-level rejection.
- **Fix:** `ALTER TABLE voter_log ADD UNIQUE KEY uk_vote (voter, target, date);`

### PASS7-MEDIUM-008: Missing ON DELETE CASCADE on forum_messages.sujet_id FK
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (forum_messages)
- **Description:** `forum_messages` FK to `forum_sujets.id` has no `ON DELETE` clause (defaults to RESTRICT). Application code cleans messages before topic deletion, but the DB gives no cascade guarantee. A future code path that skips cleanup will fail silently.
- **Fix:** Add `ON DELETE CASCADE` to the FK to enforce at DB level.

### PASS7-MEDIUM-009: autre.streak_days default is NULL — SQL arithmetic produces NULL
- **Domain:** INFRA-DATABASE
- **File:** `migrations/0027_add_login_streak.sql`
- **Description:** `streak_days` has no `DEFAULT 0`. PHP coercion handles `NULL + 1 = 1` correctly, but SQL `streak_days + 1 WHERE ...` returns NULL on un-backfilled rows, silently failing streak increments.
- **Fix:** `ALTER TABLE autre MODIFY streak_days INT NOT NULL DEFAULT 0;` + `UPDATE autre SET streak_days = 0 WHERE streak_days IS NULL;`

### PASS7-MEDIUM-010: membre table missing index on alliance column
- **Domain:** INFRA-DATABASE
- **File:** `migrations/` (membre table)
- **Description:** Alliance queries frequently join or filter on `membre.alliance`. Without an index, each alliance page load scans the full membre table. Verify with `SHOW INDEX FROM membre`; add if absent.
- **Fix:** `ALTER TABLE membre ADD INDEX idx_alliance (alliance);` (conditional on current index state).

---

## Batch 6: Medium — Admin, Season Reset, Forum Logic

### PASS7-MEDIUM-011: War archive query uses declaration ID instead of alliance ID
- **Domain:** SEASON-RESET
- **File:** `includes/player.php:1096`
- **Description:** `dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id'])` uses `$data['id']` which is the war **declaration** primary key, not an alliance ID. `$data` comes from the `declarations` table. Result: war history archive is always silently empty — `$nbjoueurs` is always 0 every season.
- **Fix:** Use `$data['alliance1']` and `$data['alliance2']` instead of `$data['id']`.

### PASS7-MEDIUM-012: moderation/index.php forum thread deletion not in transaction
- **Domain:** ADMIN
- **File:** `moderation/index.php:82-87`
- **Description:** Thread deletion executes 3 separate DELETEs (messages, topic, counter decrement) without a wrapping transaction. A failure mid-sequence leaves the forum in an inconsistent state (e.g., messages deleted but topic still present).
- **Fix:** Wrap all three DELETEs in `withTransaction()`.

### PASS7-MEDIUM-013: maintenance.php uses regex HTML sanitization instead of htmlspecialchars
- **Domain:** ADMIN
- **File:** `maintenance.php:37-48`
- **Description:** Regex-based HTML tag stripping is used for sanitizing admin-entered maintenance messages. This is bypassable with carefully crafted HTML and is fragile. Subsequent changes could introduce XSS.
- **Fix:** Replace regex approach with `htmlspecialchars()` + `nl2br()` or a proven sanitizer like HTMLPurifier.

### PASS7-MEDIUM-014: admin/index.php nested withTransaction() in IP-batch deletion may commit prematurely
- **Domain:** ADMIN
- **File:** `admin/index.php:59-63`
- **Description:** `supprimerJoueur()` internally calls `withTransaction()`. If the outer IP-batch deletion also wraps in `withTransaction()` and savepoints are unsupported or misconfigured, partial batch deletions may commit before the outer transaction completes, making partial deletes non-rollbackable.
- **Fix:** Verify savepoint support in the current MariaDB version, or refactor `supprimerJoueur()` to accept an in-transaction flag that skips starting a new transaction.

### PASS7-MEDIUM-015: Rate limit check runs before login check in listesujets.php
- **Domain:** FORUM
- **File:** `listesujets.php:45`
- **Description:** `rateLimitCheck()` is called before the `isset($_SESSION['login'])` guard. This means unauthenticated visitors consume rate-limit slots that belong to authenticated users (rate limiting by IP conflates the two). It also exposes rate-limit error messages to guests.
- **Fix:** Move `rateLimitCheck` inside the `isset($_SESSION['login'])` block, or use separate rate-limit keys for guests vs. authenticated users.

---

## Batch 7: Medium — Forum, Anti-Cheat, Notifications, Market, Maps

### PASS7-MEDIUM-016: Moderators can view topic edit form but cannot save (missing type=1 moderator path)
- **Domain:** FORUM
- **File:** `editer.php:71-83`
- **Description:** `editer.php` has a moderator code path for `type=2` (message edit) but no moderator path for `type=1` (topic edit). A moderator who attempts to edit a topic title sees the form but the save action is rejected, causing confusing UX.
- **Fix:** Add a moderator path for `type=1` that mirrors the `type=2` moderator logic.

### PASS7-MEDIUM-017: editer.php delete/hide/show (type=3/4/5) missing alliance-private forum access check
- **Domain:** FORUM
- **File:** `editer.php:24-37`
- **Description:** `type=2` (message edit) includes an alliance-private forum access check, but `type=3` (delete), `type=4` (hide), and `type=5` (show) do not. A moderator from a different alliance could delete or hide posts in alliance-private forums.
- **Fix:** Add the same alliance-private access check to type=3, 4, 5 handlers.

### PASS7-MEDIUM-018: Raw User-Agent stored in login_history (GDPR data minimization violation)
- **Domain:** ANTI-CHEAT
- **File:** `includes/multiaccount.php:45`
- **Description:** Full raw User-Agent strings are stored in `login_history` despite IP addresses being HMAC-hashed. Full UA strings can be personally identifying data under GDPR.
- **Fix:** Store only coarse browser family (e.g., extract `Firefox/`, `Chrome/`, `Safari/` from UA) or hash the UA string before storage.

### PASS7-MEDIUM-019: createAdminAlert() has no deduplication — alert flood risk
- **Domain:** ANTI-CHEAT
- **File:** `includes/multiaccount.php:301-306`
- **Description:** `createAdminAlert()` has no deduplication check. If a flagged player logs in many times per day, an admin alert is inserted on every login, potentially flooding the admin alert table with hundreds of identical entries.
- **Fix:** Add a 24h dedup check: `INSERT INTO admin_alerts ... WHERE NOT EXISTS (SELECT 1 FROM admin_alerts WHERE login=? AND alert_type=? AND created_at > NOW() - INTERVAL 24 HOUR)`.

### PASS7-MEDIUM-020: Vault capacity return not validated in combat
- **Domain:** COMBAT
- **File:** `includes/combat.php:435-443`
- **Description:** `capaciteCoffreFort()` result is used directly without checking for null/negative. If the function returns null (unexpected DB state), `max(0, $ressourcesDefenseur[$ressource] - $vaultProtection)` fails silently, potentially setting vault protection to a garbage value.
- **Fix:** After call: `if (!is_numeric($vaultProtection) || $vaultProtection < 0) { $vaultProtection = 0; logError("Combat: invalid vault protection for " . $actions['defenseur']); }`

---

## Batch 8: Medium — Notifications, Auth, Compounds, Maps, Game Core

### PASS7-MEDIUM-021: Report read-status UPDATE missing destinataire ownership check
- **Domain:** NOTIFICATIONS
- **File:** `rapports.php:26`
- **Description:** `UPDATE rapports SET statut=1 WHERE id = ?` does not include `AND destinataire = ?`. Ownership is currently verified by a surrounding SELECT guard, but the UPDATE itself is not self-defending. A future refactor removing the guard would leave it unprotected.
- **Fix:** `UPDATE rapports SET statut=1 WHERE id = ? AND destinataire = ? AND statut=0` with `$_SESSION['login']` bound.

### PASS7-MEDIUM-022: Email From header missing space (RFC 5322 violation)
- **Domain:** NOTIFICATIONS
- **File:** `includes/player.php:1259`
- **Description:** `"From: \"The Very Little War\"<noreply@...>"` is missing a space between display name and angle-bracketed address. RFC 5322 section 3.4 requires a space. Strict mail servers may reject these emails.
- **Fix:** `$header = "From: \"The Very Little War\" <noreply@theverylittlewar.com>" . $eol;`

### PASS7-MEDIUM-023: Email From/Reply-To addresses hardcoded instead of using config constants
- **Domain:** NOTIFICATIONS
- **File:** `includes/player.php:1259-1260`
- **Description:** Email From and Reply-To addresses are hardcoded strings. Changing them requires code edits rather than config changes.
- **Fix:** Define `EMAIL_FROM`, `EMAIL_REPLY_TO`, `EMAIL_FROM_NAME` in `config.php` and reference them in `processEmailQueue()`.

### PASS7-MEDIUM-024: Email queue drain probability hardcoded (not a config constant)
- **Domain:** NOTIFICATIONS
- **File:** `includes/basicprivatephp.php:349`
- **Description:** `mt_rand(1, 100) === 1` hardcodes 1% drain probability. This should be a named constant for visibility and tunability.
- **Fix:** Add `define('EMAIL_QUEUE_DRAIN_PROB_DENOM', 100)` to `config.php` and use in the condition.

### PASS7-MEDIUM-025: checkSameFingerprintAccounts() asymmetric dedup allows duplicate flags
- **Domain:** ANTI-CHEAT
- **File:** `includes/multiaccount.php:111-113`
- **Description:** The deduplication query uses a unidirectional check (`login1=? AND login2=?`) rather than the symmetric OR pattern used in `checkSameIpAccounts()`. Duplicate flags can be inserted if the same pair is checked in reverse order.
- **Fix:** Apply `(login1=? AND login2=?) OR (login1=? AND login2=?)` symmetric OR dedup pattern.

---

## Batch 9: Medium — Remaining Medium Findings (Auth, infra-security, Market, Combat)

### PASS7-MEDIUM-026: csrfCheck() does not validate same-origin Origin/Referer header
- **Domain:** INFRA-SECURITY
- **File:** `includes/csrf.php:30-59`
- **Description:** CSRF token validation does not add a same-origin check on the Origin or Referer header as a secondary defense. While token-based CSRF protection is the primary control, the combination provides defense-in-depth against token-bypass edge cases (e.g., browser bugs).
- **Fix:** Before token validation, check `$_SERVER['HTTP_ORIGIN']` or `$_SERVER['HTTP_REFERER']` matches the site's base URL. If either header is present and mismatches, reject the request.

### PASS7-MEDIUM-027: validatePassword() logic duplicated across inscription.php and compte.php
- **Domain:** INFRA-SECURITY
- **File:** `includes/validation.php`
- **Description:** Password strength validation logic is duplicated in at least two files rather than extracted to a shared `validatePassword()` function in `validation.php`. Divergence between the two copies could allow registrations to have different strength requirements than password changes.
- **Fix:** Extract to `validatePassword($password): array` in `validation.php`, call from both `inscription.php` and `compte.php`.

### PASS7-MEDIUM-028: Email change does not verify MD5 legacy passwords
- **Domain:** AUTH
- **File:** `compte.php:98`
- **Description:** The email change handler does not include the MD5 fallback password check that the password change handler uses. Players with un-migrated MD5 passwords cannot change their email.
- **Fix:** Add the same MD5 fallback as the password change handler: if `password_verify()` fails, fall back to `md5($submitted) === $stored`, and if MD5 match, migrate to bcrypt.

### PASS7-MEDIUM-029: Session cookie SameSite set to Lax instead of Strict
- **Domain:** AUTH
- **File:** `includes/session_init.php:12`
- **Description:** `SameSite=Lax` permits cookies to be sent on top-level GET navigation cross-site. `SameSite=Strict` provides stronger CSRF protection by blocking the cookie on all cross-site requests, including top-level navigations.
- **Fix:** Change `'Lax'` to `'Strict'` in the session cookie options.

### PASS7-MEDIUM-030: comptetest.php publicly accessible with no CSRF/auth guard on visitor creation
- **Domain:** AUTH
- **File:** `comptetest.php:10`
- **Description:** Visitor account creation is triggered via a GET request with no CSRF token and no authentication requirement. Any external actor can create visitor accounts en masse by hitting this URL repeatedly.
- **Fix:** Convert to POST + CSRF token. Alternatively, restrict to admin sessions only.

---

## Batch 10: Medium — Remaining Medium (Compounds, Maps, Anti-Cheat, Game Core, INFRA-DB)

### PASS7-MEDIUM-031: GREATEST() silently floors to 0 on compound resource race (no log)
- **Domain:** COMPOUNDS
- **File:** `includes/compounds.php:106-113`
- **Description:** `GREATEST($resource - ?, 0)` silently clamps to 0 if resources were depleted by a concurrent request. The `FOR UPDATE` lock should prevent this, but silent floor masking any gap in the locking chain is a diagnostic blind spot.
- **Fix:** Log when GREATEST clamps to 0: after the UPDATE, re-read the resource and if `== 0` and delta was > 0, log a warning.

### PASS7-MEDIUM-032: Resource node rendering does not check negative coordinates
- **Domain:** MAPS
- **File:** `attaquer.php:483`
- **Description:** Node rendering checks `node['x'] >= tailleCarte || node['y'] >= tailleCarte` for upper-bound exclusion but does NOT verify coordinates are non-negative. Nodes with negative coordinates (DB corruption or admin manipulation) render with negative CSS pixel offsets causing visual artifacts.
- **Fix:** `if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= $tailleCarte || $node['y'] >= $tailleCarte) continue;`

### PASS7-MEDIUM-033: Player position query uses >= 0 instead of > 0 (sentinel not fully excluded)
- **Domain:** MAPS
- **File:** `attaquer.php:396`
- **Description:** `WHERE m.x >= 0 AND m.y >= 0` is intended to exclude inactive players (sentinel x=-1000, y=-1000). A corrupted coordinate other than -1000 (e.g., x=-5) would pass this check and render at negative pixel offsets. Strict `> 0` is safer.
- **Fix:** Change to `WHERE m.x > 0 AND m.y > 0` (verify that coordinate (0,0) is not a valid player position; if it is, document the sentinel filter explicitly).

### PASS7-MEDIUM-034: checkCoordinatedAttacks() asymmetric dedup allows duplicate flags and emails
- **Domain:** ANTI-CHEAT
- **File:** `includes/multiaccount.php:154-157`
- **Description:** Same asymmetric dedup issue as MEDIUM-025, but in `checkCoordinatedAttacks()`. Reverse-ordered pair checks produce duplicate flag rows and duplicate admin alert emails.
- **Fix:** Apply the symmetric OR pattern: `(login1=? AND login2=?) OR (login1=? AND login2=?)`.

### PASS7-MEDIUM-035: areFlaggedAccounts() not called on don.php (donation bypass for flagged accounts)
- **Domain:** ANTI-CHEAT
- **File:** `don.php`
- **Description:** `areFlaggedAccounts()` is checked before market transfers but NOT before alliance donations in `don.php`. Multi-account players can funnel resources via alliance donations without triggering the anti-cheat block.
- **Fix:** Add `areFlaggedAccounts()` check in `don.php` before processing the donation, matching the pattern in `marche.php`.

---

## Batch 11: Medium — Null Coalesce and voter.php Findings

### PASS7-MEDIUM-036: Null coalesce inconsistency in casualty array accesses
- **Domain:** COMBAT
- **File:** `includes/combat.php:362`
- **Description:** Line 362: `$classeDefenseur[$i]['nombre'] - $defenseurMort[$i]` does not use null coalesce, while earlier accesses at lines 323 and 333 use `($defenseurMort[$ci] ?? 0)`. If `$defenseurMort[$i]` was never set, PHP 8.2 emits a warning.
- **Fix:** `$defenseursRestants += $classeDefenseur[$i]['nombre'] - ($defenseurMort[$i] ?? 0);`

### PASS7-MEDIUM-037: voter.php GET endpoint missing — players cannot verify poll results
- **Domain:** GAME-CORE
- **File:** `voter.php:60`
- **Description:** No GET endpoint returns poll results. Players cannot verify their vote was recorded. The TODO has been deferred since Pass 9 (P9-CRIT-001). Results are only accessible via direct DB query.
- **Fix:** Implement GET endpoint returning aggregated vote counts per option (not individual voter identities): `SELECT option_id, COUNT(*) as votes FROM voter_log WHERE date=CURDATE() GROUP BY option_id`.

### PASS7-MEDIUM-038: bilan.php hardcodes molecule class count (4) instead of MAX_MOLECULE_CLASSES
- **Domain:** GAME-CORE
- **File:** `bilan.php:22-25, 639-652`
- **Description:** Loops use `for ($i = 1; $i <= 4; $i++)` instead of `MAX_MOLECULE_CLASSES`. If the config constant changes, the loops silently skip or duplicate classes.
- **Fix:** `for ($i = 1; $i <= MAX_MOLECULE_CLASSES; $i++)`

### PASS7-MEDIUM-039: Chart price precision loss in market display
- **Domain:** MARKET
- **File:** `marche.php:765-767`
- **Description:** `floatval()` + `implode()` back-to-string uses PHP's default 14 significant digit precision. Historical prices stored at higher precision are silently truncated in the chart display.
- **Fix:** `array_map(function($v) { return sprintf('%.15g', floatval($v)); }, explode(',', $cours['tableauCours']))`

### PASS7-MEDIUM-040: admin/index.php IP-batch deletion missing consolidated audit log entry
- **Domain:** ADMIN
- **File:** `admin/index.php:59-66`
- **Description:** Batch IP deletion via the admin panel does not log a consolidated audit entry for the entire batch operation. Individual deletions may log, but the batch action itself is not recorded, reducing traceability.
- **Fix:** Add `logInfo('ADMIN', 'Batch IP deletion', ['ip' => $ip, 'count' => $count])` after the transaction completes.

---

## Batch 12: Medium — sinstruire.php and Session Name

### PASS7-MEDIUM-041: sinstruire.php JavaScript navigation uses GET without CSRF documentation
- **Domain:** GAME-CORE
- **File:** `sinstruire.php:308-314`
- **Description:** Course navigator uses JavaScript `document.location` redirect (GET) without CSRF token. Low risk (GET is idempotent and read-only), but inconsistent with the project's CSRF discipline. A future change to POST here could introduce a gap without a visible reminder.
- **Fix:** Add an explicit code comment: `// Course selection is intentionally GET-only (read-only navigation, no state mutation). Do NOT change to POST without adding csrfCheck().`

### PASS7-MEDIUM-042: Session name uses PHP default PHPSESSID (server technology disclosure)
- **Domain:** AUTH
- **File:** `includes/session_init.php`
- **Description:** Default session name `PHPSESSID` reveals the server runs PHP. This is low-severity fingerprinting data but trivially eliminated.
- **Fix:** Add `session_name('TVLW_SESSION');` before `session_start()`.

---

## Batch 13: Low — Auth and Security Infrastructure

### PASS7-LOW-001: Visitor account passwords are predictable (username == password)
- **Domain:** AUTH
- **File:** `comptetest.php:23-28`
- **Description:** Visitor accounts are created with the password set equal to the username. This is trivially guessable.
- **Fix:** `$password = bin2hex(random_bytes(8));` for visitor account generation.

### PASS7-LOW-002: Plaintext IP address stored in connectes table
- **Domain:** AUTH
- **File:** `includes/basicprivatephp.php:79`
- **Description:** `REMOTE_ADDR` is stored in plain text in the `connectes` table, inconsistent with the IP hashing added elsewhere (Pass 9).
- **Fix:** Use `hashIpAddress($_SERVER['REMOTE_ADDR'])` in the connectes INSERT to match GDPR-compliant handling elsewhere.

### PASS7-LOW-003: Visitor account creation via GET request (no CSRF)
- **Domain:** AUTH
- **File:** `comptetest.php:10`
- **Description:** Duplicate of the vector noted in MEDIUM-030 (GET-based creation, no CSRF). As a distinct LOW finding, the GET-only nature is separately trackable. Fix: POST + CSRF (same as MEDIUM-030).
- **Note:** This and MEDIUM-030 are from the same file but distinct findings (one is the access control gap, this is the CSRF gap). Both are fixed by the same code change.

### PASS7-LOW-004: Account deletion 7-day cooldown bypassable via deconnexion.php POST
- **Domain:** AUTH
- **File:** `deconnexion.php:20-23`
- **Description:** The 7-day account deletion cooldown enforced in the main deletion handler is not checked in `deconnexion.php` if that file also contains deletion logic. A direct POST to `deconnexion.php` can bypass the cooldown.
- **Fix:** Add the 7-day check to `deconnexion.php`, or remove account deletion logic from it and ensure deletion is only processed through the guarded handler.

### PASS7-LOW-005: validateEmail() has no EMAIL_MAX_LENGTH check
- **Domain:** INFRA-SECURITY
- **File:** `includes/validation.php:6-8`
- **Description:** `validateEmail()` validates format but does not check `mb_strlen($email) <= EMAIL_MAX_LENGTH`. Overly long email strings can pass validation.
- **Fix:** Add `&& mb_strlen($email) <= EMAIL_MAX_LENGTH` to the `validateEmail()` return condition.

---

## Batch 14: Low — Infrastructure and Rate Limiter

### PASS7-LOW-006: Rate limiter file_put_contents failure fails open (not logged)
- **Domain:** INFRA-SECURITY
- **File:** `includes/rate_limiter.php:53`
- **Description:** If `file_put_contents()` fails (disk full, permissions error), the rate limiter silently treats the request as allowed (fails open). This could allow unlimited requests on a degraded system.
- **Fix:** Check return value; if `false`, fail closed (reject the request) and call `logError()`.

### PASS7-LOW-007: Rate limiter TOCTOU race (read-check-write not atomic)
- **Domain:** INFRA-SECURITY
- **File:** `includes/rate_limiter.php:38-53`
- **Description:** Concurrent requests can both read the same rate-limit file, both see count below limit, and both proceed, leading to minor over-admission. Under high concurrency this allows slightly more requests than the limit.
- **Fix:** Wrap the read-check-write cycle with `flock()` on the rate limit file, or accept minor over-admission with a documented comment.

### PASS7-LOW-008: data/rates/ directory not verified in .gitignore
- **Domain:** INFRA-DATABASE
- **File:** `.gitignore`
- **Description:** Rate-limiter writes files to `data/rates/`. If not gitignored, rate-limit state files could be accidentally committed.
- **Fix:** Verify `.gitignore` contains `data/rates/` (and `data/` if the whole directory should be excluded).

### PASS7-LOW-009: Migration filenames inconsistently zero-padded
- **Domain:** INFRA-DATABASE
- **File:** `migrations/`
- **Description:** Some migrations use 4-digit zero-padded names (0077, 0080) while earlier ones may not be padded consistently. Alphabetical sort in deployment scripts could process them out of order.
- **Fix:** Audit all migration filenames; rename any non-4-digit-padded files.

### PASS7-LOW-010: withTransaction does not log savepoint name on rollback
- **Domain:** INFRA-DATABASE
- **File:** `includes/database.php`
- **Description:** Nested transaction rollbacks to savepoints don't log the savepoint name, making production debugging of nested transaction failures difficult.
- **Fix:** Add a `logError` call in the catch block when rolling back to a savepoint, including the savepoint name.

---

## Batch 15: Low — Forum, Anti-Cheat, Admin

### PASS7-LOW-011: admin/supprimerreponse.php does not decrement nbMessages counter
- **Domain:** FORUM
- **File:** `admin/supprimerreponse.php:10`
- **Description:** When an admin deletes a reply, the author's `nbMessages` counter is not decremented, causing the player's post count to be permanently inflated.
- **Fix:** After DELETE, run: `UPDATE membre SET nbMessages = GREATEST(0, nbMessages - 1) WHERE login = ?` using the reply author's login.

### PASS7-LOW-012: admin/listesujets.php topic deletion does not decrement author nbMessages
- **Domain:** FORUM
- **File:** `admin/listesujets.php:38-41`
- **Description:** When a full topic is deleted via admin, all replies are removed but no author's `nbMessages` counter is decremented. Multiple players' post counts become permanently inflated.
- **Fix:** Fetch all reply authors before deletion; decrement each author's `nbMessages` within the deletion transaction.

### PASS7-LOW-013: No rate limit on editer.php POST actions
- **Domain:** FORUM
- **File:** `editer.php:59`
- **Description:** Edit, delete, hide, and show actions in `editer.php` have no rate limiting. A bot or malicious user could flood these actions.
- **Fix:** Add `rateLimitCheck('forum_edit', $_SESSION['login'], 10, 300)` at the top of the POST handler.

### PASS7-LOW-014: admin/ip.php leaks raw IP address in GET URL and logs
- **Domain:** ANTI-CHEAT
- **File:** `admin/ip.php:18-19`
- **Description:** IP address is accepted via GET parameter, meaning raw IPs appear in Apache access logs, browser history, and referrer headers.
- **Fix:** Accept IP via POST instead of GET. Add `FILTER_VALIDATE_IP` input validation.

### PASS7-LOW-015: checkCoordinatedAttacks uses time() instead of $timestamp for flag created_at
- **Domain:** ANTI-CHEAT
- **File:** `includes/multiaccount.php:169`
- **Description:** Flag insertion uses `time()` for `created_at` but the event timestamp `$timestamp` is already computed. Using `time()` introduces a slight skew between the event time and the recorded flag time.
- **Fix:** Use `$timestamp` (or the event timestamp parameter) as `created_at` for the flag row.

---

## Batch 16: Low — Admin, Season Reset, Notifications, Game Core, Market

### PASS7-LOW-016: admin/listenews.php and admin/redigernews.php missing CSP headers
- **Domain:** ADMIN
- **File:** `admin/listenews.php:1-5`, `admin/redigernews.php:1-4`
- **Description:** Two admin pages do not include `csp.php` and do not emit CSP headers. Any inline script on these pages is unprotected by CSP.
- **Fix:** Add `require_once __DIR__ . '/../includes/csp.php';` and emit the CSP header at the top of both files.

### PASS7-LOW-017: moderation/index.php echoes $erreur variable containing raw HTML
- **Domain:** ADMIN
- **File:** `moderation/index.php:218-219`
- **Description:** `echo $erreur` outputs a variable that may contain raw HTML constructed from user input without consistent escaping. Fragile pattern — future changes to how `$erreur` is populated could introduce XSS.
- **Fix:** Ensure `$erreur` is always constructed using `htmlspecialchars()` on any user-controlled portion, or use a dedicated error display helper that escapes its output.

### PASS7-LOW-018: admin/supprimercompte.php TOCTOU between existence check and deletion
- **Domain:** ADMIN
- **File:** `admin/supprimercompte.php:26-28`
- **Description:** Existence check and deletion are separate queries without a transaction or SELECT FOR UPDATE. A concurrent deletion (e.g., by another admin) could cause a double-delete with confusing error handling.
- **Fix:** Wrap in `withTransaction()` with `SELECT 1 FROM membre WHERE login=? FOR UPDATE` before DELETE.

### PASS7-LOW-019: Archive strings in parties table lack closing ] delimiter
- **Domain:** SEASON-RESET
- **File:** `includes/player.php:1070, 1086, 1099`
- **Description:** Record concatenations for the `parties` archive table append opening `[` delimiters but some miss the closing `]`. Parsing archived season data (historique.php) may fail or produce incorrect data for the corresponding records.
- **Fix:** Append `]` to each record concatenation string as needed.

### PASS7-LOW-020: medailles.php player lookup enables timing-based login enumeration
- **Domain:** GAME-CORE
- **File:** `medailles.php:20-26`
- **Description:** `$_GET['login']` is accepted to look up any player's medals. No rate limiting exists on this endpoint. Timing differences between found/not-found responses allow login enumeration.
- **Fix:** Add `rateLimitCheck('medals_lookup', $_SERVER['REMOTE_ADDR'], 30, 60)`.

---

## Batch 17: Low — Notifications, Compounds, Maps, Rankings, Market, Economy, INFRA-TEMPLATES

### PASS7-LOW-021: Email queue has no explicit size cap before drain
- **Domain:** NOTIFICATIONS
- **File:** `includes/player.php:1225-1283`
- **Description:** No guard checks queue size before processing. A bug producing excess queued emails could cause an uncontrolled drain cycle. Risk is low due to 24h retention and player-count ceiling.
- **Fix:** Optional: add a guard that skips drain if queue size exceeds a configurable MAX_EMAIL_QUEUE_DRAIN threshold.

### PASS7-LOW-022: Compound cache key separator collision risk with dash in login
- **Domain:** COMPOUNDS
- **File:** `includes/compounds.php:232`
- **Description:** Cache key `$login . '-' . $effectType` could collide if a login contains a dash character. Keys like `player-name` with `effectType=bonus` and `player` with `effectType=name-bonus` would collide.
- **Fix:** Change separator to `:` and update the `str_starts_with($key, $login . '-')` cache invalidation check to match.

### PASS7-LOW-023: Compound cache invalidation tied to separator in str_starts_with
- **Domain:** COMPOUNDS
- **File:** `includes/compounds.php:214-216`
- **Description:** `str_starts_with($key, $login . '-')` depends on the `-` separator. Must be updated if the separator changes (per LOW-022 above). Fix: update alongside LOW-022.

### PASS7-LOW-024: getResourceNodeBonus() does not validate node coordinates before distance calc
- **Domain:** MAPS
- **File:** `includes/resource_nodes.php:137-138`
- **Description:** Distance-to-node calculation iterates all cached nodes without validating that x/y are within map bounds. Out-of-bounds nodes (DB manipulation, migration bug) produce incorrect distance values and wrong bonuses.
- **Fix:** Inside the loop: `if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= MAP_SIZE || $node['y'] >= MAP_SIZE) continue;`

### PASS7-LOW-025: classement.php:578 war link missing integer cast on declarations ID
- **Domain:** RANKINGS
- **File:** `classement.php:578`
- **Description:** `$donnees['id']` output in URL attribute lacks explicit `(int)` cast, unlike similar code at `historique.php:178` and `alliance.php:326`. No actual risk (integer PKs are safe), but inconsistent with codebase pattern.
- **Fix:** `echo (int)$donnees['id'];`

---

## Batch 18: Low — Season Reset, INFRA-DB, Market, Anti-Cheat

### PASS7-LOW-026: Stale revenu* columns not reset during season reset
- **Domain:** SEASON-RESET
- **File:** `includes/player.php:1317`
- **Description:** Eight legacy `revenu*` atom revenue columns are no longer used in game logic but are not reset during `remiseAZero()`. These stale values could cause confusion in any future use of these columns.
- **Fix:** Include the `revenu*` columns in the `remiseAZero()` reset UPDATE, or confirm they are fully dead and document with a code comment.

### PASS7-LOW-027: awardPrestigePoints() season number offset by +1 vs season_recap
- **Domain:** SEASON-RESET
- **File:** `includes/prestige.php:118`
- **Description:** The season number used in `awardPrestigePoints()` is offset by +1 compared to the season number stored in `season_recap`. Not broken, but creates confusion when comparing prestige logs to season recap entries.
- **Fix:** Confirm which source of truth is correct and align both to use the same season number. Add a comment documenting the offset if intentional.

### PASS7-LOW-028: CHECK constraints not covered by PHPUnit tests
- **Domain:** INFRA-DATABASE
- **File:** `tests/`
- **Description:** CHECK constraints added in migration 0017 (resources, buildings, molecules non-negative) have no test coverage. No PHPUnit test verifies that the DB rejects negative values.
- **Fix:** Add tests that attempt to INSERT negative values into constrained columns and assert that the operation throws an exception or is rejected.

---

## Batch 19: Low — INFRA-TEMPLATES and Remaining LOW

### PASS7-LOW-029: health.php exposes PHP version to localhost
- **Domain:** INFRA-TEMPLATES
- **File:** `health.php:46`
- **Description:** PHP version is included in the response body for requests from 127.0.0.1 or ::1. Very low risk (localhost only), but worth reviewing if proxied health checks could expose this to non-admin parties.
- **Fix:** No code change strictly required. Monitor for proxy bypass. Optionally remove PHP version from the response and use only a health status flag.

### PASS7-LOW-030: Season countdown data-end attribute lacks consistent htmlspecialchars
- **Domain:** INFRA-TEMPLATES
- **File:** `includes/layout.php:74`
- **Description:** `data-end="<?php echo (int)$seasonEndTimestamp; ?>"` is safe due to the `(int)` cast, but `index.php:150` adds `htmlspecialchars()` for uniformity. Minor defensive coding inconsistency.
- **Fix:** `htmlspecialchars((int)$seasonEndTimestamp, ENT_QUOTES, 'UTF-8')` for uniformity.

### PASS7-LOW-031: BBCode URL length limit hardcoded (not a config constant)
- **Domain:** INFRA-TEMPLATES
- **File:** `includes/bbcode.php:29`
- **Description:** `[url]` regex limits URLs to 500 characters using a hardcoded value. If the limit needs to change, it requires an in-place code edit.
- **Fix:** Add `define('BBCODE_URL_MAX_LENGTH', 500)` to `config.php` and use in the regex: `{1,` . BBCODE_URL_MAX_LENGTH . `}`.

### PASS7-LOW-032: Alliance grade insert error message misleading on duplicate key
- **Domain:** ALLIANCE-MGMT
- **File:** `allianceadmin.php:120-125`
- **Description:** When `INSERT INTO grades` fails due to a duplicate primary key, the error shown says "conflit de concurrent" (concurrent conflict). The actual cause is likely a legitimate duplicate (player already has a grade), not concurrency.
- **Fix:** Change error message to "Ce joueur est déjà gradé dans cette alliance." without implying concurrency.

### PASS7-LOW-033: Market chart timestamp timezone not explicitly verified at render
- **Domain:** MARKET
- **File:** `marche.php:767`
- **Description:** `date()` uses server timezone. The config.php comment references a `TIMEZONE` setting but verification that `date_default_timezone_set(TIMEZONE)` is actually called before any `date()` at render time is absent.
- **Fix:** Ensure `date_default_timezone_set(TIMEZONE)` is called in `config.php` or `basicprivatephp.php` before any `date()` use.

---

## Batch 20: Low — Economy, Anti-Cheat (Detection latency)

### PASS7-LOW-034: Resource initialization uses SQL DEFAULT instead of config constants
- **Domain:** ECONOMY
- **File:** `includes/player.php:106`
- **Description:** New player resources are initialized with `INSERT INTO ressources (login) VALUES (?)` relying on SQL DEFAULT values rather than explicitly inserting `STARTING_ENERGY` and `STARTING_ATOMS` from `config.php`. If schema defaults diverge from PHP constants, initialization silently uses stale values.
- **Fix:** Use an explicit INSERT with all resource columns bound to `STARTING_ENERGY` and `STARTING_ATOMS` from config.php.

### PASS7-LOW-035: Anti-cheat detection functions run synchronously on login path (latency)
- **Domain:** ANTI-CHEAT
- **File:** `includes/multiaccount.php:48-50`
- **Description:** Multi-account detection functions (IP check, fingerprint check, coordinated attack check) run synchronously during login, adding latency to every login request. On busy servers this could degrade login UX.
- **Fix:** Low priority. Consider deferring detection to an async job queue, or at minimum cache the detection result in the session for the day.

---

## Full Severity Count Reference

| Severity | Count | Batch IDs |
|----------|-------|-----------|
| CRITICAL | 5 | 001–005 |
| HIGH | 9 | 006–009, then combat 001–005 |
| MEDIUM | 42 | 001–042 |
| LOW | 35 | 001–035 |
| **TOTAL** | **91** | — |

> Note: After deduplication, AUTH LOW-003 (visitor GET/no-CSRF) partially overlaps MEDIUM-030. Both are retained as distinct tracking entries since they represent different security properties (CSRF gap vs. access control gap), but a single code fix resolves both.

---

## Domains With Zero Actionable Findings

| Domain | Assessment |
|--------|-----------|
| SOCIAL | Fully clean — 0 findings |
| ALLIANCE-MGMT | Fully clean security posture — 1 LOW cosmetic only |
| ECONOMY | Fully clean resource/transaction safety — 1 LOW config alignment only |
| RANKINGS | Fully clean — 1 LOW consistency cast only |

---

## Recommended Execution Order

1. **Batch 1** (CRITICAL): moderation auth, espionage inversion, building SQL injection, compound INSERT, prestige display
2. **Batch 2** (HIGH combat): formation bounds, spec/catalyst/compound null-cast
3. **Batch 3** (HIGH DB/email): MIME headers, migration TABLE_SCHEMA, account_flags FK, action-queue FKs
4. **Batch 4** (MEDIUM DB-1): withTransaction depth, resource_node index, season_recap JSON, compound expiry index, login_attempts purge
5. **Batch 5** (MEDIUM DB-2): historique_alliances ENUM, voter_log UNIQUE, forum_messages CASCADE, streak_days default, membre alliance index
6. **Batch 6** (MEDIUM logic-1): war archive query, moderation thread tx, maintenance.php XSS, nested tx batch delete, listesujets rate-limit order
7. **Batch 7** (MEDIUM logic-2): forum moderator edit path, editer private access, GDPR UA, alert dedup, vault null-check
8. **Batch 8** (MEDIUM logic-3): rapports UPDATE destinataire, email From space, email config constants, queue probability, fingerprint dedup
9. **Batch 9** (MEDIUM logic-4): CSRF origin check, validatePassword extract, MD5 email change, SameSite Strict, comptetest POST+CSRF
10. **Batch 10–12** (remaining MEDIUM): compounds, maps, anti-cheat, market, sinstruire docs, session name
11. **Batches 13–20** (LOW): in any order after Batches 1–12

---

*Total findings: 5 CRITICAL + 9 HIGH + 42 MEDIUM + 35 LOW = 91 findings across 21 domains.*
*Deduplicated from raw domain totals: AUTH(8), INFRA-SECURITY(5), INFRA-DATABASE(17), ANTI-CHEAT(8), ADMIN(9), SEASON-RESET(5), FORUM(8), COMBAT(8), ESPIONAGE(2), ECONOMY(1), MARKET(2), BUILDINGS(4), COMPOUNDS(6), MAPS(4), SOCIAL(0), ALLIANCE-MGMT(1), GAME-CORE(6), PRESTIGE(1), RANKINGS(1), NOTIFICATIONS(5), INFRA-TEMPLATES(4) = 105 raw, consolidated to 91 unique findings (14 merged/overlapping entries).*
