## Rev 2 — Updated after reviewer corrections

**Revised:** 2026-03-08
**Reviewers:** Reviewer A (Completeness) + Reviewer B (Technical Correctness)

### What changed in Rev 2

**New batch added — Batch 15 (new MEDIUM findings from Reviewer A):**
- SPY-P9-009 (MEDIUM): `couleurFormule()` unescaped output in espionage report — add `htmlspecialchars()` on input at `includes/game_actions.php:378` before calling `couleurFormule()`.
- MULTI-P9-004 (MEDIUM): Raw IPs displayed in admin account detail view at `admin/multiaccount.php:260` — show only 12-char hash prefix (separate from the storage fix in P9-HIGH-007).
- MULTI-P9-007 (MEDIUM): No trusted-proxy config — add `TRUSTED_PROXY_IPS` constant to `includes/config.php` with documentation comment.
- REG-P9-004 (MEDIUM, DEFERRED): No CAPTCHA/bot-protection on registration — explicitly acknowledged as deferred; documented gap with rationale.

**Existing items corrected from Reviewer B:**
- P9-HIGH-001: Remove alias — use `AND estExclu = 0` (not `AND m.estExclu = 0`); actual query has no table alias.
- P9-MED-001: Marked ALREADY APPLIED — `array_filter(array_map('trim', ...))` is already present in `voter.php:39`; fix agent must skip.
- P9-MED-007: `$moderateur['alliance']` does not exist in the fetched array (only `moderateur` key). Fix agent must fetch the moderator's alliance separately from the `autre` table.
- P9-MED-008: CAS guard placement clarified — a new outer `withTransaction()` must wrap the entire espionage resolution block (lines 350-476); the CAS guard is the first statement inside that new wrapper; this is NOT the existing narrow transaction at line 465.
- P9-HIGH-007: Side-effect fix added — all IP-comparison lookup queries in `moderation/ip.php` and `checkSameIpAccounts()` must also hash the input value before comparison, otherwise the admin IP lookup tool breaks after migration.
- P9-MED-019: `$canonicalLogin` variable does not exist in current code — fix must locate the actual recipient variable and add the self-send guard against `$_SESSION['login']` at the point of DB lookup resolution.

**Batch dependency updated:**
- P9-LOW-020 (IPv6 normalization, Batch 10) must be applied BEFORE P9-HIGH-007 (IP hashing, Batch 10). Normalization must happen before hashing so that the HMAC input is canonical. Applying hashing first then normalizing hashed hex strings would produce incorrect results.

---

# Remediation Plan — Pass 9

**Date:** 2026-03-08
**Sources:** 16 domain audit reports (api, buildings, email, espionage, forum, infra, lab, map, market, messages, multiaccount, prestige, ranking, registration, season, voter)
**Total raw findings across all domains:** ~120 (after INFO/PASS collapse)

---

## Summary (Rev 2): 5 critical, 15 high, 43 medium, 34 low (22 already-fixed/already-applied, 75 remaining, 1 deferred)

Severity breakdown of **remaining** (non-already-fixed/non-deferred) findings:
- CRITICAL: 1
- HIGH: 11
- MEDIUM: 28 (was 27; +4 from Rev 2 Batch 15 minus 1 already-applied P9-MED-001 minus 1 deferred REG-P9-004 = net +2 actionable new items)
- LOW: 20
- INFO (actionable): 13
- DEFERRED: 1 (REG-P9-004)

**Rev 2 delta:** +4 new MEDIUM findings (Batch 15); 5 existing items corrected; 1 item (P9-MED-001) confirmed already-applied; batch dependency ordering updated for Batch 10.

---

## Already Fixed (skip these)

The following findings were directly fixed before this consolidation phase. Fix agents must skip them.

| Item | Domain | Title |
|------|--------|-------|
| VOTE-P9-001 | voter | Column name `reponses` mismatch — now fixed |
| VOTE-P9-002 | voter | Active-poll `WHERE active=1` filter — now added |
| VOTE-P9-003 | voter | Unlimited vote-change via client flag — now removed |
| VOTE-P9-004 | voter | INSERT IGNORE for first-vote race — now applied |
| INFRA-P9-001 | infra | `data/.htaccess` — IfModule guard now added |
| INFRA-P9-002 | infra | `logs/.htaccess` — IfModule guard now added |
| INFRA-P9-003 | infra | `migrations/.htaccess` missing `Order deny,allow` — now added |
| INFRA-P9-004 | infra | `vendor/.htaccess` — now added |
| INFRA-P9-008 | infra | `migrations/migrate.php` CLI guard — now added |
| INFRA-P9-007 | infra | `.env.example` FilesMatch pattern — now updated |
| MSG-P9-001 | messages | `[url]` domain indicator + length cap + null byte strip — now in bbcode.php |
| EMAIL-P9-001 | email | `player.php` CRLF sanitization on recipient — now added |
| EMAIL-P9-002 | email | Hotmail EOL regex case-insensitive + outlook.com added — now fixed |
| MKT-P9-002 | market | `marche.php` transfer rate limit (10/60) — now added |
| MKT-P9-001 | market | `marche.php` market_buy/sell unified to `market_op` key — now done |
| FORUM-P9-002 | forum | `bbcode.php` [url] length cap — now done (with MSG-P9-001) |
| FORUM-P9-003 | forum | `bbcode.php` LaTeX denylist extended — now done |
| FORUM-P9-004 | forum | `editer.php` form action hardcoded to `editer.php` — now done |
| RANK-P9-001 | ranking | `classement.php` CSRF check added on search POST — now done |
| API-P9-001 | api | `api.php` params clamped to `[0, max]` — now done |
| API-P9-002 | api | `api.php` `json_last_error()` guard added — now done |
| VOTE-P9-006 | voter | `voter.php:39` `array_filter(array_map('trim', ...))` — confirmed already in codebase by Reviewer B (Rev 2) |

---

## Batch 1: CRITICAL + voter residual (voter.php)

### P9-CRIT-001: voter.php — No poll results page exists (system half-implemented)
- **Source:** VOTE-P9-007
- **Severity:** LOW (system usability)
- **File:** `voter.php` (no results page exists anywhere)
- **Fix:** Implement a `sondage_resultats.php` (or add an admin view in `admin/index.php`) that queries `SELECT reponse, COUNT(*) AS votes FROM reponses_sondage WHERE sondage = ? GROUP BY reponse ORDER BY reponse` and maps indices to option labels split from the `options` column.
- **Verify:** Admin can view tallied results for the most recent poll.

### P9-HIGH-001: voter.php — No account status check (suspended/vacation players can vote)
- **Source:** VOTE-P9-005
- **Severity:** MEDIUM
- **File:** `voter.php:8-17`
- **Fix:** Add `AND estExclu = 0` (no table alias — the query at line 13 selects from `membre` directly with no alias) to the session-token lookup query so suspended accounts cannot vote. The corrected query clause is:
  ```sql
  SELECT session_token FROM membre WHERE login = ? AND estExclu = 0
  ```
  Do NOT use `m.estExclu`; there is no alias in this query and the prefixed form will produce a SQL error. Verify column existence with `SHOW COLUMNS FROM membre LIKE 'estExclu'` before applying.
- **Verify:** A suspended player's session token is not found, resulting in auth failure.

### P9-MED-001: voter.php — Option count validation fragile (trailing comma inflates count)
- **Source:** VOTE-P9-006
- **Severity:** MEDIUM
- **Status:** **ALREADY APPLIED — SKIP.** Reviewer B confirmed `voter.php:39` already reads `array_filter(array_map('trim', explode(',', $data['options'])))`. Fix agents must not re-apply this item.
- **File:** `voter.php:38-41`
- ~~**Fix:** Replace `explode(',', $data['options'])` with `array_filter(array_map('trim', explode(',', $data['options'])))` and return error if `count($options) === 0`.~~
- **Verify:** Confirm existing code matches the pattern above; no action needed.

---

## Batch 2: Infrastructure remaining (HIGH priority)

### P9-HIGH-002: INFRA — `composer.phar` and `phpunit.phar` in web root
- **Source:** INFRA-P9-005
- **File:** web root (project root)
- **Fix:** Move `composer.phar` and `phpunit.phar` out of the web-accessible document root. If the VPS web root is `/var/www/html/` and the project root is `/home/guortates/TVLW/The-Very-Little-War/`, create a `tools/` directory at the same level as the web root and move phar files there. Update any scripts that reference them. If they must remain for CI, add them to `.gitignore` and ensure `.htaccess` blocks `.phar` (already done — confirm this is sufficient defence-in-depth).
- **Verify:** `curl https://theverylittlewar.com/composer.phar` returns 403.

### P9-MED-002: INFRA — `mod_php.c` PHP settings block skipped on PHP-FPM
- **Source:** INFRA-P9-006
- **File:** `.htaccess:37-42` + VPS `/etc/php/8.2/fpm/pool.d/www.conf`
- **Fix:** Add PHP INI overrides in the FPM pool config on the VPS:
  ```
  php_admin_value[display_errors] = Off
  php_admin_value[log_errors] = On
  php_admin_value[expose_php] = Off
  php_admin_value[error_reporting] = 32767
  ```
  The `.htaccess` `<IfModule mod_php.c>` block can remain for documentation but add a comment explaining it is only active under mod_php.
- **Verify:** `curl -I https://theverylittlewar.com/` shows no `X-Powered-By: PHP` header.

### P9-MED-003: INFRA — No `composer audit` or static analysis in CI pipeline
- **Source:** INFRA-P9-009
- **File:** `.github/workflows/ci.yml`
- **Fix:** Add a step after PHPUnit:
  ```yaml
  - name: Composer security audit
    run: composer audit --no-dev
  ```
  Also add a scheduled trigger:
  ```yaml
  on:
    schedule:
      - cron: '0 6 * * 1'  # weekly Monday 06:00 UTC
  ```
- **Verify:** CI runs on schedule; `composer audit` fails if a known CVE exists in dependencies.

---

## Batch 3: Email system (HIGH + MEDIUM)

### P9-HIGH-003: EMAIL — Admin alert body contains raw user login (CRLF body injection)
- **Source:** EMAIL-P9-003
- **Severity:** MEDIUM (body injection risk, not header injection)
- **File:** `includes/multiaccount.php:282,294`
- **Fix:** In `sendAdminAlertEmail()`, sanitize `$body` before passing to `mail()`:
  ```php
  $body = str_replace(["\r", "\n"], ' ', $body);
  ```
  Also in `createAdminAlert()`, strip CRLF from interpolated login names in messages.
- **Verify:** A player with login `"Hack\r\nFake: header"` triggers an admin alert that does not produce extra email lines.

### P9-HIGH-004: EMAIL — `$resetDate` written raw into HTML body; latin1 column corrupts UTF-8 `à`
- **Source:** EMAIL-P9-004
- **Severity:** MEDIUM
- **File:** `includes/basicprivatephp.php:247-250`
- **Fix:** Wrap `$resetDate` with `htmlspecialchars($resetDate, ENT_QUOTES, 'UTF-8')` in the email body construction. Also change the date format string from `'d/m/Y à H\hi'` to `'d/m/Y \a H\hi'` (ASCII `\a` → literally `a`) to avoid the UTF-8 `à` character being stored in a latin1 column.
- **Verify:** Season-end emails display the date correctly without mojibake.

### P9-HIGH-005: EMAIL — `email_queue` subject/body columns latin1 — UTF-8 content corrupted
- **Source:** EMAIL-P9-005
- **File:** `migrations/0038_create_email_queue.sql` (create new migration)
- **Fix:** Create migration `migrations/0039_email_queue_utf8.sql`:
  ```sql
  ALTER TABLE email_queue
    MODIFY COLUMN subject VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY COLUMN body_html MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
  -- recipient_email remains latin1 for FK compatibility
  ```
- **Verify:** A season email with accented characters (`être`, `à`) is stored and sent without mojibake.

### P9-MED-004: EMAIL — Failed email sends have no retry cap — unbounded queue growth
- **Source:** EMAIL-P9-006
- **File:** `includes/player.php:1200-1261` + migration needed
- **Fix:** Create migration `migrations/0040_email_queue_retry.sql`:
  ```sql
  ALTER TABLE email_queue ADD COLUMN retry_count INT DEFAULT 0 NOT NULL AFTER sent_at;
  ALTER TABLE email_queue ADD COLUMN failed_at INT NULL AFTER retry_count;
  ```
  In `processEmailQueue()`, on `mail()` failure: `dbExecute($base, 'UPDATE email_queue SET retry_count = retry_count + 1, failed_at = ? WHERE id = ?', 'ii', time(), $id)`. At the start of the loop, skip rows with `retry_count >= 5`.
- **Verify:** An undeliverable email is retried at most 5 times, then abandoned.

### P9-LOW-001: EMAIL — Full email address (PII) logged on send failure
- **Source:** EMAIL-P9-010
- **File:** `includes/player.php:1258`
- **Fix:** Replace:
  ```php
  logWarn('EMAIL_QUEUE', 'mail() failed for queued email', ['id' => $id, 'recipient' => $recipient]);
  ```
  With:
  ```php
  logWarn('EMAIL_QUEUE', 'mail() failed for queued email', ['id' => $id, 'recipient_hash' => substr(hash('sha256', $recipient . (defined('SECRET_SALT') ? SECRET_SALT : '')), 0, 12)]);
  ```
- **Verify:** Log file on failure shows a 12-char hash, not a full email address.

### P9-LOW-002: EMAIL — Admin email hardcoded in source (committed to GitHub)
- **Source:** EMAIL-P9-011
- **File:** `includes/multiaccount.php:291`
- **Fix:** Define `ADMIN_ALERT_EMAIL` in `config.php` with the value `'theverylittlewar@gmail.com'`. Update `multiaccount.php:291` to:
  ```php
  $adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: ADMIN_ALERT_EMAIL;
  ```
  This keeps the fallback in config.php (version-controlled, one place to change) while allowing .env override.
- **Verify:** Changing `ADMIN_ALERT_EMAIL` in config.php routes alerts correctly.

---

## Batch 4: Forum system (HIGH + MEDIUM)

### P9-HIGH-006: FORUM — MathJax enabled by hardcoded DB row ID `8` — not in config.php
- **Source:** FORUM-P9-001, FORUM-P9-008
- **Files:** `sujet.php:139`, `includes/config.php`
- **Fix:** Add to `config.php`:
  ```php
  define('FORUM_MATH_ID', 8); // ID of the math/LaTeX forum in the `forums` table
  ```
  In `sujet.php:139`, replace `$sujet['idforum'] == 8` with `$sujet['idforum'] == FORUM_MATH_ID`.
- **Verify:** Changing `FORUM_MATH_ID` to another value routes LaTeX rendering to the correct forum.

### P9-MED-005: FORUM — Whitespace-only posts bypass `!empty()` check
- **Source:** FORUM-P9-005
- **Files:** `sujet.php:48-49`, `listesujets.php:77`
- **Fix:** After fetching `$contenu` in both files, add:
  ```php
  $contenu = trim($contenu);
  if (empty($contenu)) { $erreur = 'Le contenu ne peut pas être vide.'; }
  ```
  Also add `maxlength="10000"` to both content textareas.
- **Verify:** A post with only spaces is rejected with a meaningful error message.

### P9-MED-006: FORUM — Unbounded `SELECT * FROM sanctions` — no LIMIT or date filter
- **Source:** FORUM-P9-006
- **File:** `moderationForum.php:90`
- **Fix:** Change the query to:
  ```sql
  SELECT * FROM sanctions WHERE dateFin >= CURDATE() ORDER BY idSanction DESC LIMIT 200
  ```
- **Verify:** The sanctions list loads quickly even with a large sanctions history.

### P9-MED-007: FORUM — Moderator edit bypasses alliance-private forum access control
- **Source:** FORUM-P9-009
- **File:** `editer.php:89-121`
- **Fix:** The `$moderateur` array in `editer.php` is fetched at line 12 as:
  ```php
  $moderateur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
  ```
  It contains only the `moderateur` key — NOT an `alliance` key. Using `$moderateur['alliance']` would always be `null`, making the guard non-functional. The fix agent must fetch the moderator's alliance separately from the `autre` table, then use that value in the access check. Before processing a moderator edit/delete/hide on a reply (type 2 moderator path), add:
  ```php
  // Fetch the topic's forum alliance restriction
  $topicForum = dbFetchOne($base,
      'SELECT s.idforum, f.alliance_tag FROM reponses r JOIN sujets s ON r.idSujet=s.id JOIN forums f ON s.idforum=f.id WHERE r.id=?',
      'i', $replyId);
  if ($topicForum && $topicForum['alliance_tag']) {
      // Fetch the moderator's current alliance separately
      $modAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
      $modAlliance = $modAllianceRow['idalliance'] ?? null;
      if ($modAlliance !== $topicForum['alliance_tag']) {
          http_response_code(403); exit;
      }
  }
  ```
  Fix agent must verify actual column name for alliance membership in `autre` (likely `idalliance`) and the column name for alliance restriction in `forums` (likely `alliance_tag`) by inspecting the live schema with `DESCRIBE autre` and `DESCRIBE forums` before applying. The guard must be a no-op (allow through) when `$topicForum['alliance_tag']` is NULL (public forum).
- **Verify:** A moderator in alliance A cannot edit a post in alliance B's private forum; a moderator can edit posts in any public forum.

### P9-LOW-003: FORUM — `editer.php` missing `session_init.php` include
- **Source:** FORUM-P9-010
- **File:** `editer.php:1`
- **Fix:** Add as the very first line:
  ```php
  require_once("includes/session_init.php");
  ```
- **Verify:** `editer.php` includes session_init before basicprivatephp.

### P9-LOW-004: FORUM — `moderationForum.php` missing `session_init.php` include
- **Source:** FORUM-P9-011
- **File:** `moderationForum.php:1`
- **Fix:** Add as the very first line:
  ```php
  require_once("includes/session_init.php");
  ```
- **Verify:** Moderator page has session initialization before auth guard.

### P9-LOW-005: FORUM — `localStorage.getItem` filter is security theater
- **Source:** FORUM-P9-012
- **File:** `includes/bbcode.php:16`
- **Fix:** Remove the `str_replace`/regex for `localStorage.getItem("mdp` and add a comment explaining that XSS is handled by `htmlspecialchars()` at line 14.
- **Verify:** `bbcode.php` no longer contains the `localStorage.getItem` pattern.

### P9-LOW-006: FORUM — No hourly rate limit cap on forum replies
- **Source:** FORUM-P9-013
- **File:** `sujet.php:18`, `listesujets.php:45`
- **Fix:** After the existing 5-minute rate limit check in `sujet.php`, add:
  ```php
  rateLimitCheck($_SESSION['login'], 'forum_reply_hourly', 60, 3600);
  ```
  Similarly add `rateLimitCheck($_SESSION['login'], 'forum_topic_hourly', 20, 3600)` in `listesujets.php`.
- **Verify:** After 60 replies in an hour, further replies are rejected for the remainder of the hour.

### P9-LOW-007: FORUM — `$sujet['id']` not cast to int in href
- **Source:** FORUM-P9-015
- **File:** `listesujets.php:154`
- **Fix:** Replace `$sujet['id']` with `(int)$sujet['id']` in the `href` attribute.
- **Verify:** Code review confirms cast is present.

---

## Batch 5: Espionage & combat race conditions (MEDIUM)

### P9-MED-008: ESPIONAGE — No CAS guard in espionage resolution (double-report possible)
- **Source:** SPY-P9-004
- **File:** `includes/game_actions.php:349-476`
- **Fix (CORRECTED from Rev 1):** The existing `withTransaction()` at line 465 only wraps the final report INSERT + DELETE — it does NOT wrap the initial data-fetch phase that starts at line 350. Placing the CAS guard only inside the line-465 closure is a partial fix: it prevents double-write but does NOT prevent double-read (the report could be computed twice before either write commits, potentially producing duplicate entries during a race window). The correct fix is to wrap the entire espionage resolution block in a NEW outer `withTransaction()` and place the CAS guard as the FIRST statement inside that new wrapper:

  Structure of `includes/game_actions.php:349-476` after fix:
  ```php
  // NEW outer transaction wrapping the full espionage resolution
  withTransaction($base, function() use ($base, $actions, ...) {
      // CAS guard — first statement inside the new transaction
      $cas = dbExecute($base,
          'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0',
          'i', $actions['id']);
      if ($cas === 0 || $cas === false) {
          throw new \RuntimeException('cas_skip');
      }

      // ... existing data-fetch code (lines 350-464) moves inside here ...

      // The existing narrow withTransaction at line 465 can be merged into this
      // outer transaction or removed — its INSERT/DELETE are now inside a larger tx.
  });
  ```
  Catch `'cas_skip'` in the outer `foreach` loop and `continue` to next action. Do NOT merely add the CAS guard to the existing line-465 inner transaction alone — that does not protect the data-fetch phase.
- **Verify:** Two concurrent page loads processing the same espionage action produce exactly one report and no PHP warnings about double-write.

### P9-MED-009: ESPIONAGE — NULL-dereference if target deleted between data-read and report-write
- **Source:** SPY-P9-014
- **File:** `includes/game_actions.php:363-456`
- **Fix:** Add null-checks after fetching `$ressourcesJoueur` and `$constructionsJoueur`:
  ```php
  if (!$ressourcesJoueur || !$constructionsJoueur) {
      // Target deleted mid-resolution — write a minimal failure report
      $rapport = "La cible n'existe plus.";
      // skip the full report generation
  }
  ```
- **Verify:** Deleting a player while an espionage action is pending does not cause a PHP warning/fatal.

### P9-LOW-008: ESPIONAGE — Defender neutrino fetch not locked during resolution
- **Source:** SPY-P9-006
- **File:** `includes/game_actions.php:350`
- **Fix:** Change the neutrino SELECT to include `FOR UPDATE` inside the `withTransaction()` closure:
  ```sql
  SELECT neutrinos FROM autre WHERE login=? FOR UPDATE
  ```
- **Verify:** Code review confirms `FOR UPDATE` is added.

---

## Batch 6: Building system (MEDIUM + LOW)

### P9-MED-010: BUILDINGS — Max-level check falls back to stale outer-scope snapshot
- **Source:** BLDG-P9-011
- **File:** `constructions.php:303-307`
- **Fix:** Inside the `withTransaction()` closure, replace the fallback `$constructions[$liste['bdd']]` with a fresh locked read:
  ```php
  if (!$niveauActuel) {
      $niveauActuelRow = dbFetchOne($base, 'SELECT ' . $liste['bdd'] . ' AS niveau FROM constructions WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
      $niveauActuel['niveau'] = $niveauActuelRow['niveau'] ?? 0;
  }
  ```
  Note: the column name `$liste['bdd']` must be validated against the building whitelist before interpolation (it is already validated at this point in the flow via `$liste` from `listeConstructions()`).
- **Verify:** Code review confirms the locked fallback is used. No resources are deducted for a build that will be silently dropped.

### P9-LOW-009: BUILDINGS — Ionisateur HP bar not shown (progressBar=false despite damageable)
- **Source:** BLDG-P9-014
- **File:** `includes/player.php:485` (ionisateur entry in `listeConstructions`)
- **Fix:** Set `'progressBar' => true` and add HP keys matching the pattern of other damageable buildings:
  ```php
  'progressBar' => true,
  'vie' => $constructions['vieIonisateur'],
  'vieMax' => vieIonisateur($constructions['ionisateur']),
  ```
- **Verify:** The ionisateur building card on `constructions.php` shows an HP bar.

### P9-LOW-010: BUILDINGS — JS countdown embeds DB int without explicit cast
- **Source:** BLDG-P9-013
- **File:** `constructions.php:369`
- **Fix:** Cast both values before embedding in JS:
  ```php
  $id = (int)$actionsconstruction['id'];
  $remaining = (int)($actionsconstruction['fin'] - time());
  echo cspScriptTag() . "var valeur{$id} = {$remaining}; ...";
  ```
- **Verify:** JS output only contains integer literals for countdown variables.

### P9-LOW-011: BUILDINGS — Catalyst construction-speed: no floor guard against discount >= 1.0
- **Source:** BLDG-P9-015
- **File:** `constructions.php:336`
- **Fix:** Wrap the adjusted time computation:
  ```php
  $adjustedConstructionTime = max(1, round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed'))));
  ```
- **Verify:** With a hypothetical catalyst discount of 100%, construction time is 1 second, not 0 or negative.

---

## Batch 7: Lab system (MEDIUM + LOW)

### P9-MED-011: LAB — Dynamic column interpolation in UPDATE — whitelist only in pre-check loop
- **Source:** LAB-P9-001
- **File:** `includes/compounds.php:100`
- **Fix:** Add an explicit whitelist assertion immediately before each UPDATE in the deduction loop:
  ```php
  if (!in_array($resource, $nomsRes, true)) {
      throw new \RuntimeException('invalid_resource_column: ' . $resource);
  }
  dbExecute($base, "UPDATE ressources SET $resource = GREATEST($resource - ?, 0) WHERE login = ?", 'ds', $cost, $login);
  ```
- **Verify:** Modifying a recipe to use an invalid column name throws a RuntimeException rather than executing a SQL error.

### P9-MED-012: LAB — `countStoredCompounds()` has `FOR UPDATE` outside transaction context
- **Source:** LAB-P9-002
- **File:** `includes/compounds.php:45`
- **Fix:** Remove `FOR UPDATE` from `countStoredCompounds()`. Add `FOR UPDATE` inline only within the `synthesizeCompound()` `withTransaction()` closure where needed. Add a code comment explaining why.
- **Verify:** Calling `countStoredCompounds()` outside a transaction no longer acquires a lock.

### P9-MED-013: LAB — No rate limiting on synthesis/activation
- **Source:** LAB-P9-003
- **File:** `laboratoire.php:7-28`
- **Fix:** Add at the top of both the synthesis POST branch (line 7) and the activation POST branch (line 20):
  ```php
  rateLimitCheck($_SESSION['login'], 'lab_synth', 10, 60);
  ```
- **Verify:** After 10 synthesis/activation requests in 60 seconds, further requests return an error.

### P9-MED-014: LAB — Hardcoded `86400` in GC cleanup
- **Source:** LAB-P9-004
- **File:** `includes/compounds.php:244-245`, `includes/config.php`
- **Fix:** In `config.php`, add:
  ```php
  define('COMPOUND_DISPLAY_GRACE_SECONDS', SECONDS_PER_DAY);
  ```
  In `compounds.php:245`, replace `time() - 86400` with `time() - COMPOUND_DISPLAY_GRACE_SECONDS`.
- **Verify:** `grep -r '86400' includes/compounds.php` returns no matches.

### P9-LOW-012: LAB — `COMPOUND_GC_PROBABILITY` config comment incorrect
- **Source:** LAB-P9-005
- **File:** `includes/config.php` (COMPOUND_GC_PROBABILITY definition)
- **Fix:** Update the comment from "per updateRessources call" to "per laboratoire.php page load". Add a note that `updateRessources()` does NOT call GC.
- **Verify:** Config comment is accurate.

### P9-LOW-013: LAB — `$cost` bound as `double` instead of `int`
- **Source:** LAB-P9-007
- **File:** `includes/compounds.php:87`
- **Fix:** Cast `$cost` to int:
  ```php
  $cost = (int)($qty * COMPOUND_ATOM_MULTIPLIER);
  ```
  Change the binding string from `'ds'` to `'is'`.
- **Verify:** No float binding used for whole-atom cost values.

---

## Batch 8: Map system (MEDIUM + LOW)

### P9-MED-015: MAP — GET x/y not clamped to map bounds (used for JS scroll)
- **Source:** MAP-P9-001
- **File:** `attaquer.php:386-396`
- **Fix:** After `intval()` calls:
  ```php
  $x = max(0, min($tailleCarte['tailleCarte'] - 1, intval($_GET['x'] ?? $centre['x'])));
  $y = max(0, min($tailleCarte['tailleCarte'] - 1, intval($_GET['y'] ?? $centre['y'])));
  ```
- **Verify:** `?x=-9999` or `?x=999999` clamps to valid map bounds.

### P9-MED-016: MAP — Players with coords >= tailleCarte cause undefined-offset and array corruption
- **Source:** MAP-P9-002
- **File:** `attaquer.php:434`, `includes/player.php:780`
- **Fix:** In both locations, wrap the `$carte[x][y]` assignment with a bounds guard:
  ```php
  if ($tableau['x'] >= 0 && $tableau['x'] < $tailleCarte && $tableau['y'] >= 0 && $tableau['y'] < $tailleCarte) {
      $carte[$tableau['x']][$tableau['y']] = [...];
  }
  ```
- **Verify:** No PHP E_NOTICE undefined-index warnings in error log during season transitions.

### P9-MED-017: MAP — Inactive sentinel players (x=-1000) included in map query; ghost array entries
- **Source:** MAP-P9-009
- **File:** `attaquer.php:402`
- **Fix:** Add `AND m.x >= 0 AND m.y >= 0` to the `$allPlayers` query.
- **Verify:** `INACTIVE_PLAYER_X` sentinel value (-1000) players are excluded from the map render query.

### P9-LOW-014: MAP — Force-expand in `coordonneesAleatoires` references undefined `$carte` index
- **Source:** MAP-P9-006
- **File:** `includes/player.php:753-831`
- **Fix:** After expanding `$inscrits['tailleCarte']` and computing new `$x`, skip the collision check (the newly expanded cell is provably empty):
  ```php
  // force-expand path: cell is guaranteed empty, skip collision check
  // $carte[$x][$y] is auto-vivified — add explicit initialization
  $carte[$x] = $carte[$x] ?? [];
  $carte[$x][$y] = 0;
  ```
- **Verify:** No E_NOTICE in logs when the force-expand path fires.

### P9-LOW-015: MAP — Resource node render guard missing lower-bound check
- **Source:** MAP-P9-011
- **File:** `attaquer.php:485`
- **Fix:** Change:
  ```php
  if ($node['x'] >= $tailleCarte || $node['y'] >= $tailleCarte) continue;
  ```
  To:
  ```php
  if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= $tailleCarte || $node['y'] >= $tailleCarte) continue;
  ```
- **Verify:** A node with negative coordinates is skipped cleanly.

---

## Batch 9: Messages system (MEDIUM + LOW)

### P9-MED-018: MSG — Raw DB content passed to `creerBBcode()` — latent XSS sink
- **Source:** MSG-P9-002
- **File:** `ecriremessage.php:130`
- **Fix:** Remove the second and third arguments from the `creerBBcode()` call:
  ```php
  creerBBcode("contenu");  // was: creerBBcode("contenu", $message['contenu'], 1)
  ```
  The function currently ignores those parameters anyway.
- **Verify:** `ecriremessage.php` no longer passes raw DB content to `creerBBcode()`.

### P9-MED-019: MSG — No self-messaging guard; enables self-loop spam
- **Source:** MSG-P9-003
- **File:** `ecriremessage.php` (recipient resolution section)
- **Fix (CORRECTED from Rev 1):** The variable `$canonicalLogin` does not exist in the current `ecriremessage.php` code. The file uses `$_POST['destinataire']` directly. The fix agent must:
  1. Locate where the recipient is resolved from the DB (the `SELECT ... FROM membre WHERE login = ?` lookup that validates the recipient exists). This is the authoritative point where the login name is canonicalized.
  2. Add the self-send guard immediately after that DB lookup, comparing the DB-returned login (or the normalized POST value) against `$_SESSION['login']`:
  ```php
  // After fetching recipient row from DB (variable name may differ — use whatever the code has):
  if (strtolower($recipientRow['login']) === strtolower($_SESSION['login'])) {
      $erreur = "Vous ne pouvez pas vous envoyer un message à vous-même.";
  }
  ```
  If the code does not canonicalize via a DB lookup and uses `$_POST['destinataire']` raw, add the check directly against that:
  ```php
  if (strtolower(trim($_POST['destinataire'])) === strtolower($_SESSION['login'])) {
      $erreur = "Vous ne pouvez pas vous envoyer un message à vous-même.";
  }
  ```
  The comparison must be case-insensitive since login names in this codebase may have mixed case. Fix agent must read `ecriremessage.php:7-60` to locate the actual recipient variable before applying.
- **Verify:** Submitting a message with `destinataire` equal to the sender's own login (in any case) produces the error message and no message is stored.

### P9-MED-020: MSG — No HTML `maxlength` on titre/contenu inputs
- **Source:** MSG-P9-004
- **File:** `ecriremessage.php:118,140`
- **Fix:** Add `maxlength="200"` to the titre input and `maxlength="<?= MESSAGE_MAX_LENGTH ?>"` to the contenu textarea.
- **Verify:** Browser enforces the character limits client-side.

### P9-LOW-016: MSG — Sender cannot soft-delete their own sent message via detail view
- **Source:** MSG-P9-006
- **File:** `messages.php:15-16`
- **Fix:** In the delete handler, detect whether the viewing player is the sender or recipient:
  ```php
  if ($_SESSION['login'] === $messages['destinataire']) {
      dbExecute($base, 'UPDATE messages SET deleted_by_recipient=1 WHERE id=? AND destinataire=?', 'is', $messageId, $_SESSION['login']);
  } else {
      dbExecute($base, 'UPDATE messages SET deleted_by_sender=1 WHERE id=? AND expeditaire=?', 'is', $messageId, $_SESSION['login']);
  }
  ```
- **Verify:** A sender viewing their sent message can delete it from their sent view.

### P9-LOW-017: MSG — `?information=` / `?erreur=` GET params enable fake notification injection
- **Source:** MSG-P9-009
- **File:** `includes/basicprivatephp.php:67-68`
- **Fix:** Instead of passing raw GET parameters, only accept them if they come from a session flash. Add an allowlist of known-safe notification types, or better: ignore GET-based `information`/`erreur` params and require callers to use `$_SESSION['flash_message']` instead. At minimum add a length cap:
  ```php
  $information = isset($_GET['information']) ? mb_substr(antiXSS($_GET['information']), 0, 100) : '';
  ```
- **Verify:** `?information=<very long fake alert>` is truncated to 100 chars and cannot render alarming multi-line fake messages.

---

## Batch 10: Multi-account system (HIGH + MEDIUM)

### P9-HIGH-007: MULTI — Plaintext IP stored in `login_history.ip` and `membre.ip` (GDPR)
- **Source:** MULTI-P9-001, REG-P9-003 (duplicate finding across two reports)
- **Files:** `includes/multiaccount.php:22`, `includes/player.php:72`, `includes/basicpublicphp.php:69`, `moderation/ip.php:20`, `migrations/` (schema)
- **PREREQUISITE:** P9-LOW-020 (IPv6 normalization in `marche.php`) must be implemented FIRST. IPv6 normalization must be applied to raw IP strings BEFORE hashing them. The canonical order is: raw IP → `inet_pton`/`inet_ntop` normalize → `hash_hmac`. If hashing is applied before normalization, two representations of the same IPv6 address will produce different hashes and fail to match. See Batch 10 dependency note.
- **Fix:** Replace plaintext IP storage with a keyed HMAC. In all `INSERT`/`UPDATE` calls that write IP addresses, normalize first, then hash:
  ```php
  $packed = @inet_pton($ip);
  $canonicalIp = ($packed !== false) ? inet_ntop($packed) : $ip;
  $hashedIp = hash_hmac('sha256', $canonicalIp, defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt');
  ```
  Update column size if needed (SHA-256 hex output = 64 chars, fits VARCHAR(64)).
  Create migration `migrations/0041_hash_ip_columns.sql`:
  ```sql
  ALTER TABLE membre MODIFY ip VARCHAR(64);
  ALTER TABLE login_history MODIFY ip VARCHAR(64);
  ```
  **SIDE-EFFECT FIX (mandatory, from Reviewer B):** After this migration, any query that compares a raw IP string against the now-hashed column will silently fail to match. The following locations MUST also be updated to hash the lookup value before comparison:
  1. `moderation/ip.php:20` — currently `SELECT * FROM membre WHERE ip = ?` with a raw IP input. Change to hash the `$_GET['ip']` (or equivalent) value using the same `hash_hmac` call before binding it to the query.
  2. `checkSameIpAccounts()` in `includes/multiaccount.php` — any query that reads from `login_history` by IP value must hash the comparison IP first.
  Fix agent must audit ALL query sites that read/compare `membre.ip` or `login_history.ip` and update every one.
  **Note:** This will invalidate existing IP-match detections — document the migration cost in a comment in `0041_hash_ip_columns.sql`.
- **Verify:** `SELECT ip FROM login_history LIMIT 1` returns a 64-char hex string. Admin IP lookup in `moderation/ip.php` still returns results when queried with a raw IP (hashed internally before lookup).

### P9-HIGH-008: MULTI — Pseudonymization salt hardcoded in source — rainbow-table reversible
- **Source:** MULTI-P9-002
- **File:** `includes/config.php:20`, `includes/multiaccount.php:54,69`
- **Fix:** Move the salt to a deploy-time secret loaded from `.env` or environment variable. For now, at minimum unify the two fallback strings in multiaccount.php (`'tvlw_salt'` at line 54 and `'tvlw'` at line 69) to use `SECRET_SALT` consistently. Update the config comment to note the salt should be set as an environment variable in production.
- **Verify:** Both fallback strings in multiaccount.php reference the same constant.

### P9-HIGH-009: MULTI — Fragile auth guard structure in `moderation/ip.php`
- **Source:** MULTI-P9-003
- **File:** `moderation/ip.php:1-4`
- **Fix:** Replace the `include("mdp.php")` auth guard with the standard `include("redirectionmotdepasse.php")` pattern used by all other moderation pages, placing it as the very first statement with an explicit `exit` path.
- **Verify:** An unauthenticated request to `moderation/ip.php` redirects to the login page without executing any subsequent code.

### P9-MED-021: MULTI — Duplicate flags for same pair (reverse-pair dedup missing)
- **Source:** MULTI-P9-005
- **File:** `includes/multiaccount.php:49`
- **Fix:** Update the dedup query to check both orderings:
  ```sql
  SELECT id FROM account_flags WHERE status != 'dismissed'
  AND ((login = ? AND related_login = ?) OR (login = ? AND related_login = ?))
  AND flag_type = 'same_ip'
  ```
  Pass `($loginA, $loginB, $loginB, $loginA)`.
- **Verify:** When both player A and player B log in from the same IP, only one flag is created (not two).

### P9-MED-022: MULTI — `login_history` table grows unbounded — no scheduled purge
- **Source:** MULTI-P9-006
- **File:** `includes/multiaccount.php:20-35` + cron
- **Fix:** Add probabilistic GC at the end of `logLoginEvent()`:
  ```php
  if (mt_rand(1, 200) === 1) {
      dbExecute($base, 'DELETE FROM login_history WHERE timestamp < ?', 'i', time() - 30 * SECONDS_PER_DAY);
  }
  ```
  Also create `cron/purge-login-history.sh` that runs a scheduled monthly purge.
- **Verify:** After 30+ days of test entries, the probabilistic purge removes old rows.

### P9-MED-023: MULTI — Timing-correlation check: narrow window; dismissed flags re-opened
- **Source:** MULTI-P9-008
- **File:** `includes/multiaccount.php:210-257`
- **Fix:**
  1. Widen the simultaneous window from ±5 minutes to ±15 minutes.
  2. Raise the minimum login count from 10 to 20.
  3. Add `AND status != 'dismissed'` to the dedup query at line 233.
- **Verify:** Two players with different schedules but same IP are not flagged with a CRITICAL timing correlation.

### P9-MED-024: MULTI — `resolved_by` hardcoded `'admin'` — no audit trail per session
- **Source:** MULTI-P9-009
- **File:** `admin/multiaccount.php:36`
- **Fix:** Replace `'admin'` with a session-based identifier:
  ```php
  $resolvedBy = 'admin_' . substr(session_id(), 0, 8);
  ```
- **Verify:** Moderation actions show a unique session prefix in `resolved_by`, enabling minimal session tracing.

### P9-LOW-018: MULTI — `checkCoordinatedAttacks()` defined but never called
- **Source:** MULTI-P9-012
- **File:** `includes/game_actions.php` (attack processing), `includes/multiaccount.php:113-156`
- **Fix:** At the point a successful attack is recorded in `actionsattaques` (in `attaquer.php` or `game_actions.php`), add:
  ```php
  checkCoordinatedAttacks($base, $attacker, $defender, time());
  ```
- **Verify:** A coordinated attack (two accounts attacking the same player from the same IP) generates an `account_flags` row.

### P9-LOW-019: MULTI — `checkTransferPatterns()` defined but never called
- **Source:** MULTI-P9-013
- **File:** `game_actions.php` (actionsenvoi delivery) or `envoi.php`
- **Fix:** After a successful `actionsenvoi` INSERT, add:
  ```php
  checkTransferPatterns($base, $sender, $receiver, time());
  ```
- **Verify:** One-sided resource flow between two accounts triggers a transfer pattern flag.

### P9-LOW-020: MULTI — IPv6 normalization not applied in `marche.php` IP equality check
- **Source:** MULTI-P9-010
- **File:** `marche.php:41`
- **Fix:** Normalize both IP strings before comparison:
  ```php
  function normalizeIp($ip) {
      $packed = @inet_pton($ip);
      return $packed !== false ? inet_ntop($packed) : $ip;
  }
  if (normalizeIp($ipmm['ip']) === normalizeIp($ipdd['ip'])) { // same IP — block transfer }
  ```
- **Verify:** Two IPv6 addresses that are the same host but in different notation formats are correctly detected as the same.

---

## Batch 11: Registration & Season (MEDIUM + LOW)

### P9-MED-025: REG — Password max-length not enforced in `comptetest.php`
- **Source:** REG-P9-001
- **File:** `comptetest.php:54`
- **Fix:** Add the same guard as in `inscription.php`:
  ```php
  if (mb_strlen($_POST['pass']) > PASSWORD_BCRYPT_MAX_LENGTH) {
      $erreur = 'Le mot de passe est trop long (max ' . PASSWORD_BCRYPT_MAX_LENGTH . ' caractères).';
  }
  ```
- **Verify:** A 200-character password in comptetest.php is rejected with an error message.

### P9-MED-026: REG — Underscore in login breaks `comptetest.php` validation (denial of service for valid usernames)
- **Source:** REG-P9-002
- **File:** `comptetest.php:61`
- **Fix:** Remove the redundant `preg_match("#^[A-Za-z0-9]*$#", $_POST['login'])` check on line 61. Rely solely on `validateLogin()` which already enforces `[a-zA-Z0-9_]{3,20}`.
- **Verify:** A visitor with underscore username (e.g., `test_user`) can convert their account successfully.

### P9-MED-027: REG — No duplicate email check in `comptetest.php`
- **Source:** REG-P9-005
- **File:** `comptetest.php:62-68`
- **Fix:** Add before the login uniqueness check:
  ```php
  $nbMail = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ?', 's', $email);
  if ($nbMail > 0) {
      $erreur = 'L\'email est déjà utilisé.';
  }
  ```
- **Verify:** Two visitors trying to convert with the same email produces a friendly error, not a DB exception.

### P9-LOW-021: REG — `antihtml()` applied to username/email at storage (double-encoding)
- **Source:** REG-P9-006
- **File:** `includes/player.php:48-49` (`inscrire()` function)
- **Fix:** Remove the `antihtml()` wrapping from both `$pseudo` and `$mail` in `inscrire()`. Store the raw validated value. All display code already applies `htmlspecialchars()` at render time.
- **Verify:** A username containing `&` is stored as `&` in the DB and displayed as `&amp;` in HTML — not `&amp;amp;`.

### P9-MED-028 (season): SEASON — `email_queue` not purged during season reset; unbounded sent-rows growth
- **Source:** SEASON-P9-001
- **File:** `includes/player.php:1263` (`remiseAZero()`)
- **Fix:** At the start of `remiseAZero()`, add:
  ```php
  dbExecute($base, 'DELETE FROM email_queue WHERE sent_at IS NOT NULL');
  ```
  Add a code comment documenting that `login_history` and `account_flags` are intentionally preserved cross-season for ban enforcement.
- **Verify:** After a season reset, `SELECT COUNT(*) FROM email_queue WHERE sent_at IS NOT NULL` returns 0.

### P9-LOW-022: SEASON — Admin trigger condition includes unauthenticated requests (logic inversion)
- **Source:** SEASON-P9-002
- **File:** `includes/basicprivatephp.php:208`
- **Fix:** Change:
  ```php
  $isAdminRequest = (!isset($_SESSION['login']) || $_SESSION['login'] === ADMIN_LOGIN);
  ```
  To:
  ```php
  $isAdminRequest = (isset($_SESSION['login']) && $_SESSION['login'] === ADMIN_LOGIN);
  ```
- **Verify:** An unauthenticated request to a private page cannot trigger `performSeasonEnd()`.

### P9-LOW-023: SEASON — Hardcoded `"Guortates"` in `display.php` and `connectes.php`
- **Source:** SEASON-P9-004
- **Files:** `includes/display.php:274`, `connectes.php:29`
- **Fix:** Replace both `"Guortates"` string literals with `ADMIN_LOGIN` (already defined in config.php).
- **Verify:** `grep -r '"Guortates"' includes/ *.php` returns no matches.

---

## Batch 12: Prestige & Ranking (LOW + INFO actionable)

### P9-LOW-024: PRES — Non-milestone 25 PP/season undocumented in player guide
- **Source:** PRES-P9-004
- **Files:** `regles.php`, `docs/game/10-PLAYER-GUIDE.md`
- **Fix:** Add one sentence in both documents: "Chaque connexion journalière rapporte également +1 PP (en plus des jalons de série), soit jusqu'à ~25 PP supplémentaires par saison pour les joueurs très actifs."
- **Verify:** Both documents mention the 1 PP/day baseline floor.

### P9-LOW-025: PRES — Migration 0075 idempotency check uses wrong `information_schema` column
- **Source:** PRES-P9-002
- **File:** `migrations/0075_prestige_total_pp_unsigned.sql:3-12`
- **Fix:** Change the guard from checking `DATA_TYPE = 'int'` to checking `COLUMN_TYPE = 'int(10) unsigned'`:
  ```sql
  SELECT COLUMN_TYPE INTO @col_type FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestige' AND COLUMN_NAME = 'total_pp';
  IF @col_type != 'int(10) unsigned' THEN
      ALTER TABLE prestige MODIFY COLUMN total_pp INT UNSIGNED NOT NULL DEFAULT 0;
  END IF;
  ```
- **Verify:** Running `migrate.php` twice does not re-execute the ALTER after the first run.

### P9-LOW-026: RANK — `recalculerStatsAlliances()` triggered by unauthenticated GET — DoS vector
- **Source:** RANK-P9-002
- **File:** `classement.php:373`
- **Fix:** Gate the call behind authentication:
  ```php
  if (isset($_SESSION['login'])) {
      recalculerStatsAlliances($base);
  }
  ```
  Alternatively, cache the result using a `statistiques.lastAllianceRecalc` timestamp and only call when last call was > 5 minutes ago.
- **Verify:** An unauthenticated GET to `classement.php?sub=1` does not trigger a DB write.

### P9-LOW-027: RANK — Missing indexes on non-default sort columns
- **Source:** RANK-P9-003
- **File:** New migration `migrations/0042_leaderboard_indexes.sql`
- **Fix:** Create a migration adding indexes:
  ```sql
  CREATE INDEX idx_autre_pointsAttaque ON autre(pointsAttaque);
  CREATE INDEX idx_autre_pointsDefense ON autre(pointsDefense);
  CREATE INDEX idx_autre_ressourcesPillees ON autre(ressourcesPillees);
  CREATE INDEX idx_autre_tradeVolume ON autre(tradeVolume);
  CREATE INDEX idx_autre_victoires ON autre(victoires);
  CREATE INDEX idx_autre_points ON autre(points);
  CREATE INDEX idx_autre_batmax ON autre(batmax);
  ```
- **Verify:** `EXPLAIN SELECT ... ORDER BY pointsAttaque DESC` shows index use.

---

## Batch 13: Market + API residual (LOW + INFO actionable)

### P9-MED-029: MKT — `tableauCours` written raw into JS chart — latent stored XSS if DB compromised
- **Source:** MKT-P9-003
- **File:** `marche.php:742`
- **Fix:** Validate each `tableauCours` value when emitting JS:
  ```php
  $vals = array_map('floatval', explode(',', $cours['tableauCours']));
  $safeVals = implode(',', $vals);
  $tot = '["' . date('d/m H\hi', $cours['timestamp']) . '",' . $safeVals . ']' . $fin . $tot;
  ```
- **Verify:** A manually injected `tableauCours` value of `"0,<script>alert(1)</script>"` is sanitized to `"0,0"` in JS output.

### P9-LOW-028: MKT — Stale volatility snapshot inside transaction
- **Source:** MKT-P9-004
- **File:** `marche.php:7`, buy closure (`use`), sell closure (`use`)
- **Fix:** Move the `COUNT(*) AS actifs FROM autre` query inside the transaction closure, immediately after the `cours FOR UPDATE` lock, so volatility is computed from the locked, consistent state.
- **Verify:** Concurrent buy/sell operations use the same volatility divisor.

### P9-LOW-029: MKT — No length assertion on `txTabCours` slice
- **Source:** MKT-P9-005
- **File:** `marche.php:261,396`
- **Fix:** After `array_slice`, add:
  ```php
  if (count($txTabCours) !== $nbRes) {
      throw new \RuntimeException('corrupt_cours_data: expected ' . $nbRes . ', got ' . count($txTabCours));
  }
  ```
- **Verify:** A corrupted `tableauCours` string triggers an exception rather than storing malformed data.

### P9-INFO-001: API — CSRF note for future API mutating endpoints
- **Source:** API-P9-003
- **File:** `api.php` (dispatch table area)
- **Fix:** Add a comment above the dispatch table:
  ```php
  // IMPORTANT: All handlers in this dispatch table are read-only (formula preview).
  // Any future handler that mutates state MUST: (1) verify POST method, (2) call csrfCheck().
  ```
- **Verify:** Comment is present.

### P9-INFO-002: API — Add comment on rate limit bucket separation
- **Source:** API-P9-005
- **File:** `api.php:37`
- **Fix:** Add inline comment:
  ```php
  // 'api' bucket: formula preview only; market ops are rate-limited in their own pages (marche.php)
  rateLimitCheck($ip, 'api', 60, 60);
  ```
- **Verify:** Comment is present.

---

## Batch 14: Cross-cutting INFO findings (low-effort cleanups)

### P9-INFO-003: SEASON — No "reset started" audit log event
- **Source:** SEASON-P9-005
- **File:** `includes/player.php:1017` (`performSeasonEnd()`)
- **Fix:** Add immediately after lock acquisition:
  ```php
  logInfo('SEASON', 'Season reset started', ['trigger' => 'admin/auto', 'timestamp' => time()]);
  ```
  Add before releasing the lock:
  ```php
  logInfo('SEASON', 'Season reset completed', ['winner' => $vainqueurManche]);
  ```
- **Verify:** Log file contains `SEASON reset started` and `SEASON reset completed` entries after a season reset.

### P9-INFO-004: PRES — `awardPrestigePoints` double-season idempotency guard missing
- **Source:** PRES-P9-001
- **File:** `includes/prestige.php:110-157` + migration
- **Fix:** Add a `prestige_awarded_season INT DEFAULT 0` column to `statistiques`. In `awardPrestigePoints()`, read the current season number and compare; if already awarded for this season, return immediately. Set the column value after completing the award batch.
- **Verify:** Calling `awardPrestigePoints()` twice for the same season awards PP only once.

### P9-INFO-005: MULTI — Admin alert `mail()` uses `@` error suppression
- **Source:** MULTI-P9-011
- **File:** `includes/multiaccount.php:291`
- **Fix:** Remove the `@` suppressor and log failures:
  ```php
  $sent = mail($adminEmail, $subject, $body, $headers);
  if (!$sent) {
      logWarn('MULTI_ALERT', 'Admin alert email failed to send', ['subject' => $subject]);
  }
  ```
- **Verify:** A failed admin alert email produces a warning log entry.

### P9-INFO-006: BLDG — Add comment on implicit count-lock serialization
- **Source:** BLDG-P9-012
- **File:** `constructions.php:285` and line 304
- **Fix:** Add comment at line 304:
  ```php
  // Note: no FOR UPDATE needed here — the line 285 COUNT(*) FOR UPDATE already
  // serializes concurrent queue insertions for this player.
  ```
- **Verify:** Comment is present.

### P9-INFO-007: EMAIL — MIME boundary uses `md5(id+time)` — switch to `random_bytes`
- **Source:** EMAIL-P9-013
- **File:** `includes/player.php:1234`
- **Fix:**
  ```php
  $boundary = "-----=" . bin2hex(random_bytes(8));
  ```
- **Verify:** `grep 'md5.*id.*time' includes/player.php` returns no matches.

### P9-INFO-008: REG — Starting resource values should be config constants
- **Source:** REG-P9-007
- **File:** `includes/config.php`, `includes/player.php` (`inscrire()`), `base_schema.sql`
- **Fix:** Define in config.php:
  ```php
  define('STARTING_ENERGY', 64);
  define('STARTING_ATOMS', 64);
  define('STARTING_REVENUE_ENERGY', 12);
  define('STARTING_REVENUE_ATOMS', 9);
  ```
  Update `inscrire()` to pass these explicit values in the INSERT into `ressources` rather than relying on schema DEFAULT values.
- **Verify:** Changing `STARTING_ENERGY` in config.php changes the starting energy for new players.

---

## Batch 15: New MEDIUM findings from Reviewer A (Rev 2 additions)

### SPY-P9-009: ESPIONAGE — `couleurFormule()` unescaped output in espionage report HTML (latent XSS)
- **Source:** SPY-P9-009 (Reviewer A gap GAP-1)
- **Severity:** MEDIUM
- **File:** `includes/game_actions.php:378` (call site of `couleurFormule()`); `includes/display.php` (function definition)
- **Fix:** The `couleurFormule()` function outputs HTML that may contain the formula string unescaped. To harden against latent XSS if any code path ever writes unsafe content into the formula/molecule-name field in the DB, wrap the string input with `htmlspecialchars()` BEFORE passing it to `couleurFormule()` at line 378:
  ```php
  // Before (line 378, approximate):
  $ligne = couleurFormule($formule, ...);

  // After:
  $safeFormule = htmlspecialchars($formule, ENT_QUOTES, 'UTF-8');
  $ligne = couleurFormule($safeFormule, ...);
  ```
  Fix agent must verify the exact parameter position of the formula string in the `couleurFormule()` call at line 378, and confirm the function itself does not double-escape (if it already calls `htmlspecialchars()` internally on the same field, wrapping again would produce double-encoding). If double-encoding would result, the fix should instead apply `htmlspecialchars()` inside `couleurFormule()` at the point where the formula string is embedded into an HTML attribute or content node, rather than at the call site.
- **Verify:** A molecule name stored in the DB containing `<script>` is rendered as the literal text `&lt;script&gt;` in the espionage report HTML, not as a live script tag.

### MULTI-P9-004: MULTI — Raw IP addresses displayed in admin account detail view
- **Source:** MULTI-P9-004 (Reviewer A gap GAP-2)
- **Severity:** MEDIUM
- **File:** `admin/multiaccount.php:260`
- **Note:** This is a DISPLAY-LAYER fix, separate from the storage fix (P9-HIGH-007). Even after P9-HIGH-007 migrates storage to hashed IPs, the admin UI must not reveal the full raw hash in a way that provides unnecessary information. This item addresses showing a truncated, non-reversible display identifier.
- **Fix:** At line 260 of `admin/multiaccount.php`, where raw IP (or hashed IP) is rendered in the account detail view, replace the full value with a 12-character prefix of the stored hash:
  ```php
  // Before (approximate):
  echo htmlspecialchars($account['ip']);

  // After (show only first 12 chars of the stored hash as a display identifier):
  $ipDisplay = substr($account['ip'], 0, 12) . '…';
  echo htmlspecialchars($ipDisplay);
  ```
  If after P9-HIGH-007 the column stores a 64-char HMAC hex string, this prefix is a non-reversible opaque identifier — it communicates that an IP is recorded without revealing its value. Do NOT add a "Reveal IP" link at this stage (out of scope for this batch; the hashed value is irreversible by design post-P9-HIGH-007).
- **Depends on:** P9-HIGH-007 (storage hash migration). Apply this display fix after P9-HIGH-007 is deployed so the truncation applies to the hash, not a raw IP.
- **Verify:** The admin account detail page at `admin/multiaccount.php` shows a 12-character truncated identifier (e.g., `a3f7c902b1e4…`) in the IP field, not a full IP address or full hash.

### MULTI-P9-007: MULTI — No trusted-proxy configuration documented or enforced
- **Source:** MULTI-P9-007 (Reviewer A gap GAP-3)
- **Severity:** MEDIUM
- **Files:** `includes/config.php`, `includes/multiaccount.php:22`
- **Fix:** Add a configuration constant and a documentation comment to `includes/config.php` in the network/deployment section:
  ```php
  // Trusted upstream proxy IPs for X-Forwarded-For extraction.
  // IMPORTANT: This project currently runs on a VPS with direct client connections
  // (no load balancer or reverse proxy in front of Apache). REMOTE_ADDR is used
  // directly as the client IP. If a reverse proxy is ever added (e.g., Cloudflare,
  // nginx), set TRUSTED_PROXY_IPS to the proxy's IP(s) and update multiaccount.php
  // logLoginEvent() to extract the real client IP from X-Forwarded-For only when
  // REMOTE_ADDR matches a trusted proxy. Using X-Forwarded-For without validation
  // allows any client to spoof their IP and evade multi-account detection.
  define('TRUSTED_PROXY_IPS', []); // empty = direct connect assumed; add proxy IPs as array of strings if needed
  ```
  In `includes/multiaccount.php:22`, add a comment on the `$_SERVER['REMOTE_ADDR']` line:
  ```php
  // Direct connection assumed (TRUSTED_PROXY_IPS is empty in config.php).
  // If TRUSTED_PROXY_IPS is ever populated, update this line to extract real client IP from X-Forwarded-For.
  $ip = $_SERVER['REMOTE_ADDR'];
  ```
  No functional code change is required at this time (direct-connect assumption is currently correct). This item is documentation and forward-proofing only.
- **Verify:** `config.php` contains `TRUSTED_PROXY_IPS` constant definition with the explanatory comment. `multiaccount.php` contains the referenced comment at the IP assignment line.

### REG-P9-004: REG — No CAPTCHA or bot-protection on registration (DEFERRED)
- **Source:** REG-P9-004 (Reviewer A gap GAP-4)
- **Severity:** MEDIUM (audit label)
- **Status:** **EXPLICITLY DEFERRED** — not implemented in this remediation cycle.
- **Files:** `inscription.php`, `comptetest.php`
- **Gap acknowledged:** Neither `inscription.php` nor `comptetest.php` has any CAPTCHA, honeypot, or proof-of-work challenge. The only bot deterrent is the rate limiter (which limits by IP, not by proof of humanity). A bot with rotating IPs or a distributed botnet can mass-register accounts, enabling spam, resource farming, and multi-account abuse.
- **Rationale for deferral:** Integrating a CAPTCHA (Google reCAPTCHA, hCaptcha, or self-hosted proof-of-work) is a non-trivial UI/UX change that requires third-party service configuration, privacy policy update, and cross-device testing. Adding a honeypot field is lower-effort but provides limited protection against targeted bots. Given the game's small active player base and the existing rate limiter, the immediate risk is low. This will be addressed in a dedicated UX sprint.
- **Interim mitigation already in place:** Rate limiting in `includes/rate_limiter.php` (10 registration attempts / 60 seconds per IP).
- **Future fix (deferred):** Add a hidden honeypot field `<input type="text" name="website" style="display:none">` to both `inscription.php` and `comptetest.php`. In `comptetest.php` and the registration POST handler, reject any submission where `$_POST['website']` is non-empty (bots fill hidden fields; humans don't). This requires no third-party dependency and is a 30-minute implementation.
- **Verify (deferred):** When implemented, a POST with `website=anything` is rejected with HTTP 400.

---

## Batch Dependencies

```
Batch 1 (voter residual)         — independent, run first after ALREADY-FIXED items confirmed
Batch 2 (infra)                  — independent
Batch 3 (email)                  — P9-HIGH-005 (migration 0039) must precede P9-MED-004 (migration 0040)
Batch 4 (forum)                  — P9-LOW-003/004 before P9-MED-007 (session_init needed for auth)
Batch 5 (espionage)              — P9-MED-008 before P9-MED-009 (CAS guard reduces race window)
Batch 6 (buildings)              — independent; P9-LOW-009 depends on schema having vieIonisateur
Batch 7 (lab)                    — P9-MED-011 before P9-MED-013 (whitelist must be solid before rate limit added)
Batch 8 (map)                    — P9-MED-016 and P9-MED-017 are independent; P9-MED-015 depends on tailleCarte being loaded
Batch 9 (messages)               — independent
Batch 10 (multiaccount)          — ORDER WITHIN BATCH MATTERS: P9-LOW-020 (IPv6 normalization) MUST be applied BEFORE P9-HIGH-007 (IP hashing). Normalization must happen on the raw IP string before it is hashed; normalizing a 64-char hex hash would produce nonsense. After both are applied, P9-HIGH-007 (schema migration 0041) must then deploy before P9-LOW-018/019 (detection functions called).
Batch 11 (registration/season)   — P9-LOW-021 (remove antihtml) before verifying email display
Batch 12 (prestige/ranking)      — P9-LOW-027 migration can deploy in parallel; PRES-P9-002 is a standalone fix
Batch 13 (market/api)            — independent
Batch 14 (INFO cleanups)         — fully independent; can be done in any order or skipped if time-constrained
Batch 15 (Rev 2 new MEDIUM)      — SPY-P9-009 independent; MULTI-P9-004 depends on P9-HIGH-007 (Batch 10) being deployed first; MULTI-P9-007 independent; REG-P9-004 DEFERRED (no implementation required this cycle)
```

---

## Quick Reference: Remaining Findings by Severity

### CRITICAL (1 remaining after already-fixed)
| ID | Batch | Title |
|----|-------|-------|
| P9-CRIT-001 (VOTE-P9-007) | 1 | No poll results page exists |

### HIGH (11 remaining)
| ID | Batch | Title |
|----|-------|-------|
| P9-HIGH-002 (INFRA-P9-005) | 2 | composer.phar / phpunit.phar in web root |
| P9-HIGH-003 (EMAIL-P9-003) | 3 | Admin alert body CRLF injection |
| P9-HIGH-004 (EMAIL-P9-004) | 3 | `$resetDate` raw in HTML body; latin1 `à` corruption |
| P9-HIGH-005 (EMAIL-P9-005) | 3 | email_queue columns latin1 — UTF-8 corruption |
| P9-HIGH-006 (FORUM-P9-001+008) | 4 | MathJax enabled by hardcoded DB ID 8 |
| P9-HIGH-007 (MULTI-P9-001+REG-P9-003) | 10 | Plaintext IP in DB (GDPR) |
| P9-HIGH-008 (MULTI-P9-002) | 10 | Salt hardcoded/inconsistent — rainbow-table reversible |
| P9-HIGH-009 (MULTI-P9-003) | 10 | Fragile auth guard in moderation/ip.php |
| FORUM-P9-003 remaining | ALREADY-FIXED | LaTeX denylist — confirmed fixed |
| EMAIL-P9-001/002 | ALREADY-FIXED | CRLF / EOL — confirmed fixed |
| MKT-P9-001/002 | ALREADY-FIXED | Rate limit / key unification — confirmed fixed |

### MEDIUM (30 total, 1 already-applied, 28 remaining + 1 deferred)
P9-MED-001 — **ALREADY APPLIED** (voter.php array_filter/trim — confirmed in codebase by Reviewer B; skip)
P9-MED-002 through P9-MED-029 — see batch sections above.
P9-MED-030 (SPY-P9-009) — Batch 15: `couleurFormule()` unescaped output — `includes/game_actions.php:378`
P9-MED-031 (MULTI-P9-004) — Batch 15: Raw IP display in admin UI — `admin/multiaccount.php:260`
P9-MED-032 (MULTI-P9-007) — Batch 15: No trusted-proxy config — `includes/config.php` (documentation only)
P9-MED-033 (REG-P9-004) — Batch 15: No bot-protection on registration — **DEFERRED** (see Batch 15 entry)

### LOW (20 remaining)
P9-LOW-001 through P9-LOW-029 — see batch sections above.

### INFO/Actionable (8 remaining)
P9-INFO-001 through P9-INFO-008 — see Batch 14 above.

---

## Deduplication Notes

The following findings appeared in multiple domain reports and were merged:

| Merged Into | Original Sources | Topic |
|-------------|-----------------|-------|
| P9-HIGH-007 | MULTI-P9-001 + REG-P9-003 | Plaintext IP storage (GDPR) |
| P9-HIGH-005 + P9-HIGH-004 | EMAIL-P9-004 + EMAIL-P9-005 | email_queue UTF-8/latin1 encoding issues |
| P9-HIGH-006 | FORUM-P9-001 + FORUM-P9-008 | MathJax hardcoded DB ID 8 (two facets of same bug) |
| MSG-P9-001 already-fixed + FORUM-P9-002 already-fixed | Both cover [url] length cap in bbcode.php |
| SEASON-P9-003 | Overlaps with EMAIL-P9-001 (already-fixed) — SEASON report identifies same recipient sanitization need; EMAIL fix covers it |

---

*Plan generated by consolidation agent. Revised in Rev 2 after Reviewer A (Completeness) and Reviewer B (Technical Correctness) reports dated 2026-03-08.*

*Fix agents should process batches 1-13 in order, then Batch 15 (Rev 2 new items), deferring Batch 14 (INFO cleanups) to a final polish pass. P9-MED-001 is already applied — skip. REG-P9-004 is deferred — skip for this cycle. Within Batch 10, apply P9-LOW-020 (IPv6 normalization) before P9-HIGH-007 (IP hashing).*
