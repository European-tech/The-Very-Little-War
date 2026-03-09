# Pass 27 Remediation Plan

Generated: 2026-03-09
Agents run: 38 across 5 batches (A–E)
Domains covered: AUTH, INFRA-SECURITY, INFRA-DATABASE (×2), ANTI_CHEAT, ADMIN, SEASON_RESET (×2), FORUM, COMBAT (×2), ESPIONAGE, ECONOMY, MARKET, BUILDINGS, COMPOUNDS, MAPS, SOCIAL, ALLIANCE_MGMT, GAME_CORE, PRESTIGE, RANKINGS, NOTIFICATIONS, INFRA-TEMPLATES + 7 TAINT/FLOW agents

## Summary

- **3 CRITICAL** (must fix)
- **14 HIGH** (must fix)
- **18 MEDIUM** (should fix)
- **12 LOW** (deferred / optional)
- **11 FALSE POSITIVES** confirmed

---

## CRITICAL Fixes

### P27-001: hashIpAddress() called before multiaccount.php is required (FATAL on every login)
- **ID:** P27-001
- **File:** includes/basicpublicphp.php:90,93
- **Domain:** ANTI_CHEAT / AUTH
- **Severity:** CRITICAL
- **Description:** On line 90, `hashIpAddress($_SERVER['REMOTE_ADDR'])` is called to update the player IP after successful login. However, `hashIpAddress()` is defined only in `includes/multiaccount.php`, which is not required until line 93. This causes a PHP Fatal Error "Call to undefined function hashIpAddress()" on **every successful login**, breaking authentication completely.
- **Fix:** Move `require_once(__DIR__ . '/multiaccount.php');` to before line 90 (e.g., immediately after `logInfo('AUTH', ...)` on line 91 is fine, but the require must precede the call):
  ```php
  require_once(__DIR__ . '/multiaccount.php');
  dbExecute($base, 'UPDATE membre SET ip = ? WHERE login = ?', 'ss', hashIpAddress($_SERVER['REMOTE_ADDR']), $loginInput);
  logInfo('AUTH', 'Login successful', ['login' => $loginInput]);
  logLoginEvent($base, $loginInput, 'login');
  ```
- **Suggested by:** ANTI_CHEAT agent

---

### P27-002: Alliance research/duplicateur upgrades TOCTOU — permission not re-verified inside transaction
- **ID:** P27-002
- **File:** alliance.php:106-139 (duplicateur), alliance.php:145-196 (research)
- **Domain:** ALLIANCE_MGMT / FLOW-ALLIANCE
- **Severity:** CRITICAL
- **Description:** Both the duplicateur upgrade (lines 106-139) and research upgrade (lines 145-196) check the actor's grade/chef status OUTSIDE the `withTransaction()` call. Between the outer check and transaction start, an alliance admin could revoke the actor's grade, allowing a now-unauthorized officer to execute the resource upgrade. The pattern used in `allianceadmin.php:425-436` (re-verify inside with FOR UPDATE) is missing here.
- **Fix:** For each action, inside the `withTransaction()` closure, add a FOR UPDATE re-check of the actor's role before proceeding:
  ```php
  withTransaction($base, function() use (...) {
      // Re-verify actor is still chef or has appropriate grade (FOR UPDATE)
      $actorLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id = ? FOR UPDATE', 'i', $allianceId);
      if (!$actorLocked || $actorLocked['chef'] !== $_SESSION['login']) {
          // Also re-verify grade if not chef:
          $gradeLocked = dbFetchOne($base, 'SELECT id FROM grades WHERE idalliance=? AND joueur=? FOR UPDATE', 'is', $allianceId, $_SESSION['login']);
          if (!$gradeLocked) throw new \RuntimeException('PERMISSION_REVOKED');
      }
      // ... rest of upgrade logic
  });
  ```
- **Suggested by:** FLOW-ALLIANCE agent (CRITICAL), ALLIANCE_MGMT agent (MEDIUM — both agents agree)

---

### P27-003: Alliance-private forum topic creation missing try-catch — fatal DB error if alliance_id column missing
- **ID:** P27-003
- **File:** listesujets.php:68-74
- **Domain:** FORUM
- **Severity:** CRITICAL (degrades to MEDIUM once migration fully applied; CRITICAL during migration window)
- **Description:** The POST handler for topic creation on listesujets.php queries `SELECT alliance_id FROM forums` at line 68 WITHOUT a try-catch block. If the `alliance_id` column hasn't been migrated yet, this throws an unhandled exception, crashing forum topic creation entirely. Contrast with `sujet.php:131` and `forum.php:58` which both wrap this in try-catch.
- **Fix:** Wrap lines 68-75 in a try-catch identical to sujet.php:
  ```php
  if (empty($erreur)) {
      try {
          $forumMeta = dbFetchOne($base, 'SELECT alliance_id FROM forums WHERE id = ?', 'i', $getId);
          if ($forumMeta && !empty($forumMeta['alliance_id'])) {
              $posterAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $_SESSION['login']);
              if (!$posterAlliance || (int)$posterAlliance['idalliance'] !== (int)$forumMeta['alliance_id']) {
                  $erreur = "Vous n'avez pas accès à ce forum.";
              }
          }
      } catch (\Exception $e) {
          // alliance_id column not yet present — all forums public, skip silently
      }
  }
  ```
- **Suggested by:** FORUM agent

---

## HIGH Fixes

### P27-004: Building construction time_level1 not used in transaction re-calculation
- **ID:** P27-004
- **File:** constructions.php:427
- **Domain:** BUILDINGS
- **Severity:** HIGH
- **Description:** When a building upgrade completes via `updateActions()`, the construction time is recalculated inside the transaction as `$bc['time_base'] * pow($bc['time_growth_base'], $levelForTime + $offset)`. For `generateur` and `producteur` upgrading to level 1 (`$levelForTime=0`, offset=0), this formula gives `time_base` (e.g., 60 seconds) rather than the special `time_level1` (10 seconds). `player.php:365-366` correctly uses `time_level1` for the initial display, but constructions.php does not.
- **Fix:** After line 425, add special-case handling:
  ```php
  $levelForTime = $niveauActuel['niveau'];
  $offset = $bc['time_level_offset'] ?? 0;
  if ($levelForTime === 0 && isset($bc['time_level1'])) {
      $freshTime = (int)$bc['time_level1'];
  } else {
      $freshTime = (int)round($bc['time_base'] * pow($bc['time_growth_base'], $levelForTime + $offset));
  }
  ```
- **Suggested by:** BUILDINGS agent

---

### P27-005: Admin alert deduplication asymmetric — duplicate alerts created for same account pair
- **ID:** P27-005
- **File:** includes/multiaccount.php:349-356
- **Domain:** ANTI_CHEAT
- **Severity:** HIGH
- **Description:** `createAdminAlert()` deduplicates by checking `WHERE login1=? AND login2=?`. However, `checkTimingCorrelation()` may call this with reversed pair order (A,B vs B,A) for the same two accounts at different login events. The dedup query does not catch the reversed order, resulting in duplicate admin alerts for the same pair.
- **Fix:** Normalize the pair order alphabetically before inserting and before the dedup check:
  ```php
  // Normalize pair order: always store smaller login first
  if ($login1 > $login2) {
      [$login1, $login2] = [$login2, $login1];
  }
  // ... existing dedup query now works bidirectionally
  ```
- **Suggested by:** ANTI_CHEAT agent

---

### P27-006: Admin flag update — success message logged even when flag doesn't exist
- **ID:** P27-006
- **File:** admin/multiaccount.php:35-50
- **Domain:** ADMIN / ANTI_CHEAT
- **Severity:** HIGH (misleading audit log)
- **Description:** When `!$flagExists`, `logWarn()` is called but execution falls through to `logInfo('ADMIN', "Flag #$flagId status changed to $action")` on line 50 (unconditional). Admin receives a false success log entry.
- **Fix:** Add `return;` after `logWarn()` when `!$flagExists`:
  ```php
  if (!$flagExists) {
      logWarn('ADMIN', "Flag update rejected: flag #$flagId not found");
      return; // Add this line
  }
  ```
- **Suggested by:** ANTI_CHEAT agent

---

### P27-007: Grade cap race condition — grades count not locked with FOR UPDATE
- **ID:** P27-007
- **File:** allianceadmin.php:150-151
- **Domain:** ALLIANCE_MGMT / FLOW-ALLIANCE
- **Severity:** HIGH
- **Description:** The grade count check `SELECT COUNT(*) FROM grades WHERE idalliance=?` before the MAX_GRADES_PER_ALLIANCE cap is not inside the transaction and not using FOR UPDATE. Two concurrent grade-creation requests both see `$gradeCount < MAX_GRADES_PER_ALLIANCE` and both proceed, resulting in more grades than the maximum.
- **Fix:** Move the grade count query inside a `withTransaction()` with FOR UPDATE on the alliances row:
  ```php
  withTransaction($base, function() use ($base, $chefId, ...) {
      $allianceLocked = dbFetchOne($base, 'SELECT id FROM alliances WHERE id=? FOR UPDATE', 'i', $chefId);
      $gradeCount = dbCount($base, 'SELECT COUNT(*) AS cnt FROM grades WHERE idalliance=?', 'i', $chefId);
      if ($gradeCount >= MAX_GRADES_PER_ALLIANCE) {
          throw new \RuntimeException('MAX_GRADES_REACHED');
      }
      // ... insert grade
  });
  ```
- **Suggested by:** FLOW-ALLIANCE agent

---

### P27-008: War declaration ghost-alliance check not re-verified inside transaction
- **ID:** P27-008
- **File:** allianceadmin.php:529-531
- **Domain:** ALLIANCE_MGMT / FLOW-ALLIANCE
- **Severity:** HIGH
- **Description:** The check that the target alliance's chef is not banned (`SELECT id FROM membre WHERE login=? AND estExclu=0`) is performed BEFORE entering the transaction and without FOR UPDATE. Between the check and the transaction, the target chef could be banned or the alliance dissolved, yet the war is still declared.
- **Fix:** Move the ghost-alliance check inside the war declaration transaction with FOR UPDATE:
  ```php
  withTransaction($base, function() use ($base, ...) {
      $chefAdverse = dbFetchOne($base, 'SELECT id FROM membre WHERE login=? AND estExclu=0 FOR UPDATE', 's', $allianceAdverseChef);
      if (!$chefAdverse) {
          throw new \RuntimeException('TARGET_ALLIANCE_DISSOLVED');
      }
      // ... rest of war declaration
  });
  ```
- **Suggested by:** FLOW-ALLIANCE agent

---

### P27-009: Migration runner does not check return value of mysqli_begin_transaction / mysqli_commit
- **ID:** P27-009
- **File:** migrations/migrate.php:61,116
- **Domain:** INFRA-DATABASE
- **Severity:** HIGH
- **Description:** `mysqli_begin_transaction($base)` and `mysqli_commit($base)` return values are not checked. If transaction start fails (connection loss), subsequent migration statements execute without atomicity. If commit fails, the migration is still recorded as applied with potentially partial state.
- **Fix:**
  ```php
  // Line 61:
  if (!mysqli_begin_transaction($base)) {
      echo "ERROR: Failed to begin transaction for migration $filename\n";
      exit(1);
  }
  // Line 116:
  if (!mysqli_commit($base)) {
      echo "ERROR: Migration commit failed for $filename: " . mysqli_error($base) . "\n";
      mysqli_rollback($base);
      exit(1);
  }
  ```
- **Suggested by:** INFRA-DATABASE-2 agent

---

### P27-010: Hardcoded database name 'tvlw' in migrations 0058, 0059, 0060
- **ID:** P27-010
- **File:** migrations/0058_fix_compound_fk.sql:17, 0059_fix_login_history_and_connectes.sql:24, 0060_moderation_log_fk.sql:33
- **Domain:** INFRA-DATABASE
- **Severity:** HIGH
- **Description:** Three migrations check `WHERE TABLE_SCHEMA = 'tvlw'` instead of `DATABASE()`. On any non-production environment (dev/staging) with a different DB name, the constraint checks never match, causing FK/PK additions to always execute even if they already exist — triggering "duplicate key" errors on re-runs.
- **Fix:** Replace `'tvlw'` with `DATABASE()` in all three migration files:
  ```sql
  -- Before: WHERE TABLE_SCHEMA = 'tvlw'
  -- After:
  WHERE TABLE_SCHEMA = DATABASE()
  ```
- **Suggested by:** INFRA-DATABASE-2 agent

---

### P27-011: CSS `expression()` injection in forum grade color (IE5-8)
- **ID:** P27-011
- **File:** includes/ui_components.php:645
- **Domain:** INFRA-TEMPLATES
- **Severity:** HIGH (reduced — IE5-8 only; no modern browser risk)
- **Description:** `carteForum()` filters grade color with `preg_replace('/[^a-zA-Z0-9#(),.% ]/', '', ...)`. This regex still allows the word `expression()` which is a legacy CSS attack vector (IE 5-8). A malicious admin or compromised DB could inject `expression(alert(1))` as a grade color.
- **Fix:** Replace the permissive regex with a strict hex/rgb/named-color whitelist:
  ```php
  // After filtering: validate it's a safe CSS color format
  $filteredColor = preg_replace('/[^a-zA-Z0-9#(),.% ]/', '', $grade['couleur']);
  // Block any CSS function names (expression, url, etc.)
  if (preg_match('/\b(expression|url|eval|javascript|vbscript|import|behavior)\b/i', $filteredColor)) {
      $filteredColor = '#888888'; // fallback grey
  }
  ```
- **Suggested by:** INFRA-TEMPLATES agent

---

### P27-012: Espionage success comparison strict `<` vs `<=` — exact-threshold attacks always fail
- **ID:** P27-012
- **File:** includes/game_actions.php:528,640
- **Domain:** ESPIONAGE
- **Severity:** HIGH
- **Description:** The espionage success condition uses strict `<`: `if ($espionageThreshold < $espActions['nombreneutrinos'])`. When an attacker sends EXACTLY the threshold amount, this evaluates to false (failure), even though the intent is "attacker sends at least the threshold". This inconsistency means players cannot reliably know if their neutrino count will succeed.
- **Fix:** Change strict `<` to `<=` at both lines 528 and 640:
  ```php
  if ($espionageThreshold <= $espActions['nombreneutrinos']) {
  // ...
  $espionageSucceeded = ($espionageThreshold <= $espActions['nombreneutrinos']);
  ```
- **Suggested by:** ESPIONAGE agent

---

### P27-013: Market transfer self-transfer bypass via case variation
- **ID:** P27-013
- **File:** marche.php:101
- **Domain:** MARKET / FLOW-MARKET
- **Severity:** HIGH
- **Description:** The self-transfer check `ucfirst(mb_strtolower(trim($_POST['destinataire']))) === $_SESSION['login']` correctly normalizes the input to match `$_SESSION['login']` format. However, a case-variation bypass is theoretically possible in locales where `mb_strtolower`/`ucfirst` behave differently from login normalization at registration time. Additionally, the comparison should also be performed against the DB-canonical login to be certain.
- **Fix:** Compare the canonical login fetched from DB (already done at line 97 as `$canonicalDestinataire`) against `$_SESSION['login']` instead of the POST value:
  ```php
  // After fetching $canonicalDestinataire at line 97:
  if ($canonicalDestinataire && $canonicalDestinataire['login'] === $_SESSION['login']) {
      $erreur = "Vous ne pouvez pas vous envoyer des ressources.";
  }
  ```
- **Suggested by:** FLOW-MARKET agent

---

### P27-014: Banned player can still send market transfers (sender ban not checked)
- **ID:** P27-014
- **File:** marche.php:36-249
- **Domain:** MARKET / FLOW-MARKET
- **Severity:** HIGH
- **Description:** The market transfer handler checks that the RECIPIENT is not banned but never checks if the SENDER is banned before accepting the transfer. A player banned after their session was established can still send resources (since `basicprivatephp.php` only validates the session token, not a fresh ban check on each request).
- **Fix:** Add a sender ban check early in the transfer handler (after CSRF and rate limit checks):
  ```php
  // After line 37 (csrfCheck()):
  $senderStatus = dbFetchOne($base, 'SELECT estExclu FROM membre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
  if (!$senderStatus || (int)$senderStatus['estExclu'] === 1) {
      $erreur = "Votre compte est désactivé.";
  } elseif (!rateLimitCheck(...)) {
  ```
- **Suggested by:** FLOW-MARKET agent

---

### P27-015: Prestige getPrestige() static cache not invalidated after purchase — stale display
- **ID:** P27-015
- **File:** includes/prestige.php:248-259, prestige.php:7-18
- **Domain:** PRESTIGE
- **Severity:** HIGH (UX: player sees stale PP balance after purchase within same request)
- **Description:** After `purchasePrestigeUnlock()` updates the DB, prestige.php line 18 calls `getPrestige()` which returns the cached pre-purchase value. The player sees the old PP balance and old unlocks until they navigate away and back. (In normal POST→redirect→GET flow this auto-resolves, but in edge cases without redirect it shows stale data.)
- **Fix:** After a successful purchase, invalidate the static cache. Add a cache-clear function or pass a `$forceRefresh` flag:
  ```php
  // In purchasePrestigeUnlock(), after successful DB update, add:
  // Invalidate static cache for this player
  $reflection = new \ReflectionFunction('getPrestige');
  // Simpler approach: set a module-level flag or restructure to not re-call after purchase.
  ```
  Simpler fix in prestige.php: after the POST handler commits, fetch fresh data with a direct DB query instead of `getPrestige()`:
  ```php
  $prestige = dbFetchOne($base, 'SELECT total_pp, unlocks FROM prestige WHERE login=?', 's', $_SESSION['login']);
  ```
- **Suggested by:** PRESTIGE agent, FLOW-PRESTIGE agent

---

### P27-016: Email queue idempotency check not transaction-safe (race allows duplicate emails)
- **ID:** P27-016
- **File:** includes/player.php:1385-1431
- **Domain:** SEASON_RESET
- **Severity:** HIGH
- **Description:** The `queueSeasonEndEmails()` function reads `emails_queued_season` from `statistiques` and compares it outside a transaction. If two concurrent `performSeasonEnd()` calls both pass the check, both queue duplicate season-end emails to all players.
- **Fix:** Wrap the idempotency check in a `withTransaction()` with FOR UPDATE on statistiques:
  ```php
  $alreadyQueued = false;
  withTransaction($base, function() use ($base, $currentSeason, &$alreadyQueued) {
      $statsRow = dbFetchOne($base, 'SELECT emails_queued_season FROM statistiques FOR UPDATE', '', '');
      if ($statsRow && (int)($statsRow['emails_queued_season'] ?? 0) >= $currentSeason) {
          $alreadyQueued = true;
          return;
      }
      dbExecute($base, 'UPDATE statistiques SET emails_queued_season = ?', 'i', $currentSeason);
  });
  if ($alreadyQueued) return;
  ```
- **Suggested by:** SEASON_RESET-2 agent

---

### P27-017: inscrire() dbExecute calls inside transaction don't check return values — silent partial registration
- **ID:** P27-017
- **File:** includes/player.php:97-156
- **Domain:** FLOW-REGISTRATION
- **Severity:** HIGH
- **Description:** Multiple `dbExecute()` calls inside `inscrire()`'s `withTransaction()` closure do not check return values. However, because `connexion.php` enables `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, DB errors WILL throw exceptions and trigger rollback. The actual risk is that return values being false (non-exception path) would go undetected. With strict mode this is only a LOW risk, but the pattern should be explicit.
- **Note:** This is partially a FALSE POSITIVE due to MYSQLI_REPORT_STRICT — exceptions ARE thrown on error. Downgraded to MEDIUM. See MEDIUM section.

---

## MEDIUM Fixes

### P27-018: Admin feedback missing for batch IP account deletion result
- **ID:** P27-018
- **File:** admin/index.php:63-94
- **Domain:** ADMIN
- **Severity:** MEDIUM
- **Description:** `$erreur`/`$succes` variables from batch deletion are set but never displayed in the admin dashboard, leaving the admin with no feedback on operation success/failure.
- **Fix:** Add display of these variables before the dashboard content:
  ```php
  if (isset($erreur)) echo '<div class="toast-text color-red">' . htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') . '</div>';
  if (isset($succes)) echo '<div class="toast-text color-green">' . htmlspecialchars($succes, ENT_QUOTES, 'UTF-8') . '</div>';
  ```
- **Suggested by:** ADMIN agent

---

### P27-019: Auth — PASSWORD_BCRYPT used instead of PASSWORD_DEFAULT in email change path
- **ID:** P27-019
- **File:** compte.php:162
- **Domain:** AUTH
- **Severity:** MEDIUM
- **Description:** The MD5 migration fallback on email change at line 162 uses `PASSWORD_BCRYPT` while the normal password change (line 126) uses `PASSWORD_DEFAULT`. Both resolve to bcrypt currently but create inconsistency maintenance risk.
- **Fix:** Change line 162 to use `PASSWORD_DEFAULT` for consistency.
- **Suggested by:** AUTH agent

---

### P27-020: Alliance research upgrade — loose permission check (no grade bit validation)
- **ID:** P27-020
- **File:** alliance.php:147-158
- **Domain:** ALLIANCE_MGMT
- **Severity:** MEDIUM
- **Description:** Research upgrade permission check verifies the player has ANY grade row but doesn't validate which permission bits it contains. Any grade-holding officer can execute research upgrades regardless of their assigned permission scope.
- **Fix:** Add explicit permission bit check before allowing research upgrades (or restrict to chef only), similar to how duplicateur checks grade permissions.
- **Suggested by:** ALLIANCE_MGMT agent

---

### P27-021: Economy CAS race — updateRessources() tempsPrecedent comparison susceptible to clock skew
- **ID:** P27-021
- **File:** includes/game_resources.php:271-274
- **Domain:** ECONOMY
- **Severity:** MEDIUM
- **Description:** The CAS guard compares `tempsPrecedent` with integer `time()`. If stored as FLOAT or with microsecond differences, two concurrent requests could both pass and grant duplicate resources.
- **Fix:** Use explicit BIGINT cast in the CAS condition: `WHERE login=? AND CAST(tempsPrecedent AS UNSIGNED) = ?`. Alternatively, verify tempsPrecedent column type is INT and not FLOAT in DB.
- **Suggested by:** ECONOMY agent

---

### P27-022: Ionisateur HP missing alliance Fortification bonus on upgrade
- **ID:** P27-022
- **File:** includes/player.php:668-669
- **Domain:** BUILDINGS
- **Severity:** MEDIUM
- **Description:** `augmenterBatiment()` calls `vieIonisateur($batiments[$nom] + 1)` without passing the `$joueur` parameter, so alliance Fortification bonuses are excluded from the initial HP calculation. The stored HP value is then used in combat without recalculation.
- **Fix:** Pass `$joueur` to vieIonisateur in line 669: `vieIonisateur($batiments[$nom] + 1, $joueur)`.
- **Suggested by:** BUILDINGS agent

---

### P27-023: Catalyst static cache prevents dynamic rotation within same request
- **ID:** P27-023
- **File:** includes/catalyst.php:88
- **Domain:** COMPOUNDS
- **Severity:** MEDIUM
- **Description:** `catalystEffect()` uses a static cache populated once per request. If the catalyst rotates mid-week (cron-triggered) between two calls in the same request, stale bonuses are applied.
- **Fix:** Either (a) pass the catalyst object explicitly to avoid static cache, or (b) add a cache TTL / invalidation mechanism keyed on week number.
- **Suggested by:** COMPOUNDS agent

---

### P27-024: Compound synthesis missing catalyst discount application
- **ID:** P27-024
- **File:** laboratoire.php:142, includes/compounds.php:106
- **Domain:** COMPOUNDS
- **Severity:** MEDIUM
- **Description:** Atom costs for compound synthesis do not apply any catalyst discount, inconsistent with duplicateur, construction, and market systems which do apply catalysts.
- **Fix:** Either add `compound_cost_discount` effect to the catalyst system and apply `catalystEffect('compound_cost_discount')` in synthesis cost, or document this is intentional.
- **Suggested by:** COMPOUNDS agent

---

### P27-025: Prestige streak/comeback functions lack defensive estExclu check
- **ID:** P27-025
- **File:** includes/player.php:1673 (updateLoginStreak), includes/player.php:1742 (checkComebackBonus)
- **Domain:** FLOW-PRESTIGE / PRESTIGE
- **Severity:** MEDIUM
- **Description:** Both functions grant PP/resources/shield without checking if the player is banned. Protected upstream by `basicprivatephp.php:39` (estExclu=0 session check), but lack defensive self-contained checks.
- **Fix:** At the start of each function, inside the transaction:
  ```php
  $statusCheck = dbFetchOne($base, 'SELECT estExclu FROM membre WHERE login = ? FOR UPDATE', 's', $login);
  if (!$statusCheck || $statusCheck['estExclu'] == 1) {
      return; // early return, banned player
  }
  ```
- **Suggested by:** FLOW-PRESTIGE agent

---

### P27-026: Admin seasonal reset has no MIN_SEASON_DAYS guard
- **ID:** P27-026
- **File:** admin/index.php:110-121
- **Domain:** SEASON_RESET
- **Severity:** MEDIUM
- **Description:** The admin "Remise à zéro" button calls `performSeasonEnd()` without checking `MIN_SEASON_DAYS`. Auto-trigger in `basicprivatephp.php:277` correctly guards this, but admin manual trigger does not — an admin could reset a season 3 days in.
- **Fix:** Add the MIN_SEASON_DAYS check to `performSeasonEnd()` itself (or at the admin trigger point):
  ```php
  // In performSeasonEnd(), at the start:
  $debut = dbFetchOne($base, 'SELECT debut FROM statistiques', '', '');
  $seasonAge = time() - (int)$debut['debut'];
  if ($seasonAge < MIN_SEASON_DAYS * SECONDS_PER_DAY) {
      throw new \RuntimeException('SEASON_TOO_YOUNG');
  }
  ```
  Or add an `$forceOverride` parameter for admin use.
- **Suggested by:** FLOW-SEASON agent

---

### P27-027: Email queue uses UNIX_TIMESTAMP() in INSERT but processEmailQueue compares with PHP time()
- **ID:** P27-027
- **File:** includes/player.php:1421
- **Domain:** SEASON_RESET
- **Severity:** MEDIUM
- **Description:** `queueSeasonEndEmails()` uses SQL `UNIX_TIMESTAMP()` for `created_at`, but `processEmailQueue()` uses PHP `time()` for its 24-hour filter comparison. Clock skew between PHP server and DB server can cause emails to be immediately aged out or stuck in queue indefinitely.
- **Fix:** Use consistent source — pass PHP `time()` as a parameter:
  ```php
  dbExecute($base,
      'INSERT INTO email_queue (recipient_email, subject, body_html, created_at) VALUES (?, ?, ?, ?)',
      'sssi', $player['email'], $subject, $bodyHtml, time()
  );
  ```
- **Suggested by:** SEASON_RESET-2 agent

---

### P27-028: dbExecute called with `[]` array instead of `""` string for types parameter
- **ID:** P27-028
- **File:** includes/player.php:1644,1647
- **Domain:** SEASON_RESET
- **Severity:** MEDIUM
- **Description:** Two `dbExecute` calls pass `[]` (empty array) as the `$types` parameter instead of `""` (empty string). Works by accident due to `count($params) > 0` guard but violates the API contract.
- **Fix:**
  ```php
  dbExecute($base, "DELETE FROM sanctions WHERE dateFin < CURDATE()", "");
  dbExecute($base, 'DELETE FROM news WHERE 1', "");
  ```
- **Suggested by:** SEASON_RESET-1 agent

---

### P27-029: bilan.php — dynamic column names from config interpolated into SQL without explicit whitelist
- **ID:** P27-029
- **File:** bilan.php:68,72
- **Domain:** GAME_CORE
- **Severity:** MEDIUM
- **Description:** `$spec['unlock_building']` and `$col` from `$SPECIALIZATIONS` config are interpolated directly into SQL column positions. Currently safe (trusted config) but violates defense-in-depth.
- **Fix:** Add explicit whitelist check:
  ```php
  $allowedBuildings = ['ionisateur', 'producteur', 'condenseur'];
  $allowedSpecCols = ['spec_combat', 'spec_economy', 'spec_research'];
  if (!in_array($spec['unlock_building'], $allowedBuildings, true) || !in_array($col, $allowedSpecCols, true)) {
      continue; // skip invalid spec
  }
  ```
- **Suggested by:** GAME_CORE agent

---

### P27-030: bilan.php specialization — constructions not locked with FOR UPDATE when reading
- **ID:** P27-030
- **File:** bilan.php:71
- **Domain:** GAME_CORE
- **Severity:** MEDIUM
- **Description:** The specialization unlock check reads `constructions` column for the building level (line 68) in a separate query from the locked `autre` row (line 64). TOCTOU race between these reads allows the building level to change between the spec check and the unlock grant.
- **Fix:** Include the unlock building column in the FOR UPDATE query at line 64:
  ```php
  $freshData = dbFetchOne($base,
      'SELECT specialisation, spec_combat, spec_economy, spec_research, ionisateur, producteur, condenseur FROM constructions WHERE login=? FOR UPDATE',
      's', $login);
  $buildingLevel = (int)($freshData[$spec['unlock_building']] ?? 0);
  ```
- **Suggested by:** GAME_CORE agent

---

### P27-031: Moderator IP binding can be set from $_SERVER on first private page access (bypass mod login)
- **ID:** P27-031
- **File:** includes/basicprivatephp.php:56-58
- **Domain:** TAINT-SESSION / AUTH
- **Severity:** MEDIUM
- **Description:** If a player with `moderateur=1` (set in DB) accesses any private page directly (without going through moderation/index.php), `$_SESSION['mod_ip']` is set from `$_SERVER['REMOTE_ADDR']` without the additional moderator password check. This creates an inconsistent security path.
- **Fix:** Either (a) gate moderator actions strictly on `moderation/index.php` login (preferred) or (b) in `basicprivatephp.php:56-58`, only bind `mod_ip` if moderator already went through the mod login flow (check for a separate session flag set at mod login).
- **Suggested by:** TAINT-SESSION agent

---

### P27-032: reponses_sondage missing index on (sondage) column — full table scan on vote tallying
- **ID:** P27-032
- **File:** migrations/ (new migration needed)
- **Domain:** INFRA-DATABASE
- **Severity:** MEDIUM
- **Description:** The `reponses_sondage` table has a FK on `sondage → sondages.id` and a UNIQUE INDEX on `(login, sondage)` but no single-column index on `sondage`. voter.php queries like `SELECT ... FROM reponses_sondage WHERE sondage = ? GROUP BY reponse` cause full table scans.
- **Fix:** Add migration:
  ```sql
  ALTER TABLE reponses_sondage ADD INDEX IF NOT EXISTS idx_sondage (sondage);
  ```
- **Suggested by:** INFRA-DATABASE-2 agent

---

### P27-033: sujet.php — no explicit estExclu check before forum post (banned players can post if banned after login)
- **ID:** P27-033
- **File:** sujet.php:30-76
- **Domain:** SOCIAL / FLOW-SOCIAL
- **Severity:** MEDIUM
- **Description:** Forum post handler checks forum ban (sanctions table) but not account ban (estExclu=1). A player banned after their session was established can still post.
- **Fix:** Add early banned check:
  ```php
  $posterStatus = dbFetchOne($base, 'SELECT estExclu FROM membre WHERE login=?', 's', $_SESSION['login']);
  if (!$posterStatus || (int)$posterStatus['estExclu'] === 1) {
      $erreur = "Votre compte est désactivé.";
  }
  ```
- **Suggested by:** FLOW-SOCIAL agent

---

### P27-034: joueur.php rate limit return value not checked — rate limit not enforced
- **ID:** P27-034
- **File:** joueur.php:14-17
- **Domain:** SOCIAL
- **Severity:** MEDIUM
- **Description:** `rateLimitCheck($_ip, 'profile_view', 60, 60)` return value is ignored — the profile page loads regardless of whether the limit is exceeded.
- **Fix:**
  ```php
  if (!rateLimitCheck($_ip, 'profile_view', 60, 60)) {
      http_response_code(429);
      echo '<p>Trop de requêtes. Attendez avant de réessayer.</p>';
      exit();
  }
  ```
- **Suggested by:** SOCIAL agent

---

### P27-035: forum.php alliance-private access control silently treats all forums as public if column missing (no warning logged)
- **ID:** P27-035
- **File:** forum.php:56-79
- **Domain:** FORUM / FLOW-SOCIAL
- **Severity:** MEDIUM
- **Description:** The try-catch block that handles missing `alliance_id` column silently skips without logging. During migration windows, private forums are exposed to all players with no alert.
- **Fix:** Add a log warning:
  ```php
  } catch (\Exception $e) {
      // alliance_id column not yet present — all forums treated as public
      logWarn('FORUM', 'alliance_id column missing in forums table — treating all forums as public');
  }
  ```
- **Suggested by:** FLOW-SOCIAL agent

---

## LOW / Deferred

### LOWs from this pass (18 items):

- **P27-L01** [AUTH] AUTH-P27-002: comptetest.php visitor account ID off-by-one comment missing — add clarity comment only
- **P27-L02** [AUTH] AUTH-P27-003: inscription.php logLoginEvent failure masks DB issues — add more explicit logging
- **P27-L03** [BUILDINGS] Formation change not wrapped in withTransaction — LOW race risk, very minor
- **P27-L04** [BUILDINGS] BUG comment at player.php:627 `// BUG listeconstructions` — needs resolution or removal
- **P27-L05** [COMBAT] Dispersée float overkill precision — add epsilon guard `if ($disperseeOverkill > 0.001)` instead of `> 0`
- **P27-L06** [COMBAT] Molecule decay loop bounds check — add `if ($compteur - 1 >= count($molecules)) break;`
- **P27-L07** [COMBAT] ceil() for molecule counts — change to floor() to avoid awarding extra molecules
- **P27-L08** [COMPOUNDS] cleanupExpiredCompounds() dbExecute return not checked — add error log
- **P27-L09** [COMPOUNDS] GC probability — run from basicprivatephp.php instead of lab page only
- **P27-L10** [COMPOUNDS] Catalyst week ID uses date('Y') instead of date('o') — potential locale drift
- **P27-L11** [ECONOMY] IODE_CATALYST_DIVISOR not validated against 0 — defensive check in config or resources
- **P27-L12** [ESPIONAGE] couleurFormule() in spy reports — verify ENT_QUOTES escaping (cosmetic)
- **P27-L13** [FLOW-SEASON] Countdown timezone mismatch (server Europe/Paris vs browser local time) — add UTC timestamp to data-end attribute
- **P27-L14** [FLOW-SEASON] Admin MIN_SEASON_DAYS bypass warning in admin UI — add confirmation prompt before reset
- **P27-L15** [INFRA-DB] migrations table missing ENGINE/CHARSET — add explicit ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
- **P27-L16** [INFRA-DB] @ error suppression in migrate.php:94-95 — replace with try/catch
- **P27-L17** [MARKET] Market max quantity hardcoded 10000000 — move to MARKET_MAX_QUANTITY constant in config.php
- **P27-L18** [RANKINGS] Daily leaderboard sorts by raw scores but displays sqrt-transformed — add comment or fix sort

---

## FALSE POSITIVES confirmed

| Agent Finding | Reason False Positive |
|---|---|
| MARKET CRITICAL-001/002/003: $revenuEnergie/$revenu/$membre undefined | These are global variables set by `initPlayer()` in `basicprivatephp.php:87`. The closure captures them from outer scope. |
| FLOW-REGISTRATION CRITICAL: dbExecute returns false silently in inscrire() | connexion.php enables MYSQLI_REPORT_ERROR\|MYSQLI_REPORT_STRICT — DB errors throw exceptions, not false. |
| TAINT-API CRITICAL: health.php IPv6 bypass | Requires compromised reverse proxy to exploit; health endpoint only leaks PHP version + disk info. Downgraded to LOW. |
| TAINT-API HIGH: api.php banned user timing gap after estExclu check | Read-only formula preview endpoint; banned user sees formula previews briefly — no state mutation. |
| SEASON_RESET-2 HIGH-001: session_token update scope | WHERE 1 is intentional — all sessions must be invalidated on season reset. |
| SCHEMA CRITICAL: tableaux/unites tables in admin/tableau.php | Legacy dead code in admin-only page; no player-facing risk; DB error only visible to admins who navigate there. Downgraded to LOW deferred. |
| SCHEMA HIGH: vieIonisateur bound as 'i' not 'i' for BIGINT | 'i' correctly binds BIGINT in PHP mysqli; BIGINT values within INT range are handled correctly. |
| COMBAT-1 CRITICAL-001: disperseeOverkill division by zero | spreadDenominator minimum is 1 (when liveClassesAhead=0); no division by zero possible. |
| FLOW-SOCIAL CRITICAL-001: message sender can view received messages | OR clause (sender OR recipient) is intentional — both parties can read their shared conversation. |
| TAINT-CROSS-MODULE MEDIUM: $formule double-escaping in couleurFormule | Contract is documented: callers pass raw BBCode, not pre-escaped HTML. |
| COMBAT-2 MEDIUM-003: CAS guard === 0 \|\|\| === false | CAS with affected_rows=0 on double-processing is correct behavior; not a bug. |

---

## Implementation Order

**Day 1 (CRITICAL — all three must be fixed before any new deployment):**
1. P27-001: Move `require_once(multiaccount.php)` before `hashIpAddress()` call
2. P27-002: Add permission re-verification inside alliance upgrade transactions
3. P27-003: Add try-catch to listesujets.php alliance-private forum check

**Day 2 (HIGH — security/correctness):**
4. P27-004: Building time_level1 in constructions.php
5. P27-005: Admin alert pair normalization
6. P27-006: Admin flag success log fix
7. P27-007: Grade cap race — FOR UPDATE inside transaction
8. P27-008: War declaration ghost-alliance check inside transaction
9. P27-009: Migration runner commit/begin_transaction return checks
10. P27-010: Database name hardcoding in 3 migrations
11. P27-011: CSS expression() injection guard in forum grade color
12. P27-012: Espionage strict `<` → `<=`
13. P27-013: Market self-transfer canonical comparison
14. P27-014: Market banned sender check
15. P27-015: Prestige static cache invalidation
16. P27-016: Email queue idempotency with FOR UPDATE
17. P27-017 (now MEDIUM): inscrire() pattern — already protected by MYSQLI_REPORT_STRICT

**Day 3 (MEDIUM — game correctness + defense-in-depth):**
18-35. P27-018 through P27-035 in priority order

---

## Migrations Required

| ID | Purpose |
|---|---|
| 0114 | Add INDEX on reponses_sondage(sondage) |
| (inline fixes to 0058/0059/0060) | Replace 'tvlw' with DATABASE() — note: applied migrations cannot be changed; instead add 0115/0116/0117 correction migrations |

---

## Test Coverage

All fixes should be accompanied by:
- Unit test updates in `tests/` for changed logic (especially P27-001, P27-004, P27-012, P27-016)
- Manual verification of login flow after P27-001 fix
- Season reset dry-run for P27-016 fix
