# Pass 9 Remediation Plan

**Date:** 2026-03-08
**Audit:** Ultra Audit Pass 9 — 35 agents across 5 batches
**Total findings:** 1 CRITICAL, ~23 HIGH, ~23 MEDIUM, ~8 LOW
**Migration:** `migrations/0094_pass9_schema_fixes.sql`

All groups are independent and may be dispatched in parallel. Within each group, tasks are ordered by dependency (schema-first, then logic, then tests).

---

## Fix Groups (for parallel agent dispatch)

---

### Group A: Schema & DB Correctness

**Findings:** C-001, H-001, H-002, M-008, M-009, M-010, M-011, M-015, L-002, L-006

---

#### C-001 — CRITICAL: admin_alerts.created_at type mismatch (alert dedup never fires)

- **File:** `includes/multiaccount.php:320`
- **Severity:** CRITICAL
- **Problem:** `created_at` is stored as `INT` (Unix timestamp) but the dedup query compares it with `NOW() - INTERVAL 24 HOUR` (DATETIME arithmetic). The comparison always returns false, so duplicate alerts are never suppressed and the table bloats infinitely.
- **Exact fix:** Change the WHERE condition from:
  ```sql
  created_at > NOW() - INTERVAL 24 HOUR
  ```
  to:
  ```sql
  created_at > UNIX_TIMESTAMP() - 86400
  ```
- **Verification:** After fix, insert a test alert row with `created_at = UNIX_TIMESTAMP()`, call the function again with the same parameters, and confirm no second row is inserted.

---

#### H-001 — HIGH: Season reset sets image=NULL on NOT NULL column

- **File:** `includes/player.php:1356`
- **Severity:** HIGH
- **Problem:** `autre.image = NULL` in the season reset UPDATE will fail in MySQL strict SQL mode (`STRICT_TRANS_TABLES`), causing a catastrophic rollback of the entire season reset.
- **Exact fix:** Change:
  ```php
  image = NULL
  ```
  to:
  ```php
  image = 'defaut.png'
  ```
- **Verification:** Run `UPDATE autre SET image='defaut.png' WHERE login='testuser'` on VPS; confirm no error. Run season reset in test and confirm `image` column is `'defaut.png'` post-reset.

---

#### H-002 — HIGH: Positional INSERT into rapports missing columns (strict mode failure)

- **Files:** `allianceadmin.php:310, 340, 384, 425`
- **Severity:** HIGH
- **Problem:** The INSERT supplies 6 positional values for an 8-column table (missing `image` and `type`). In strict SQL mode this fails entirely.
- **Exact fix:** At all four locations, replace the positional INSERT with a named-column form:
  ```sql
  INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, type)
  VALUES (UNIX_TIMESTAMP(), ?, ?, ?, 0, 'alliance')
  ```
  Adjust the bound parameters to match the new 4-parameter form (titre, contenu, destinataire — drop the two that were supplying missing values).
- **Verification:** Trigger each of the four alliance actions (kick, promote, declare war, end war) on the VPS and confirm reports appear without DB error.

---

#### M-008 — MEDIUM: email_queue cleanup outside remiseAZero() transaction

- **File:** `includes/player.php:1346`
- **Severity:** MEDIUM
- **Problem:** `DELETE FROM email_queue WHERE sent_at IS NOT NULL` is executed outside the `withTransaction` block. If the transaction rolls back, the cleanup is already committed — sent emails are re-queued on next attempt but their records are gone.
- **Exact fix:** Move the DELETE statement inside the `withTransaction` callback, after the season data is confirmed committed.
- **Verification:** Confirm via EXPLAIN / grep that the DELETE is inside the same closure passed to `withTransaction`.

---

#### M-009 — MEDIUM: timeMolecule reset to epoch 0 instead of current time

- **File:** `includes/player.php:1356`
- **Severity:** MEDIUM
- **Problem:** `timeMolecule=0` resets molecule timers to 1970-01-01 (Unix epoch 0). This causes all time-since calculations to report absurdly large values until the player next acts.
- **Exact fix:** Change:
  ```sql
  timeMolecule = 0
  ```
  to:
  ```sql
  timeMolecule = UNIX_TIMESTAMP()
  ```
- **Verification:** After season reset, query `SELECT timeMolecule FROM autre WHERE login=?` and confirm value is within a few seconds of `time()`.

---

#### M-010 — MEDIUM: constructions HP columns bound as DOUBLE instead of INT

- **Files:** `includes/player.php:148, 1357`
- **Severity:** MEDIUM
- **Problem:** `vie*` BIGINT columns are bound with type `'d'` (DOUBLE) in both the registration INSERT and season reset UPDATE. MariaDB may silently accept this, but the mismatch is a correctness risk and can cause float precision artifacts in BIGINT columns.
- **Exact fix:** Change bind type character from `'d'` to `'i'` for all `vie*` parameters at both locations. Cast the PHP values with `(int)` before binding.
- **Verification:** `grep -n "'d'" includes/player.php` — confirm no `'d'` bind type remains adjacent to `vie` columns. Run registration test.

---

#### M-011 — MEDIUM: ceil() returns float used in integer SQL contexts

- **Files:** `includes/combat.php:12, 23`
- **Severity:** MEDIUM
- **Problem:** `ceil($defRow['nombre'])` and `ceil($chaineExplosee[$c - 1])` return PHP floats. These values feed directly into SQL UPDATE `nombre` columns and arithmetic throughout combat. Float precision artifacts accumulate.
- **Exact fix:** Cast after every ceil call:
  ```php
  (int)ceil($defRow['nombre'])
  (int)ceil($chaineExplosee[$c - 1])
  ```
- **Verification:** Run combat unit tests; confirm `nombre` values in DB are always integers post-combat.

---

#### M-015 — MEDIUM: prestige.unlocks VARCHAR(255) overflow risk

- **File:** `migrations/0094_pass9_schema_fixes.sql` (schema migration)
- **Severity:** MEDIUM
- **Problem:** `prestige.unlocks` is VARCHAR(255). As more unlocks are added over time this will silently truncate data.
- **Exact fix:** Migration (included in 0094):
  ```sql
  ALTER TABLE prestige MODIFY COLUMN unlocks TEXT NOT NULL DEFAULT '';
  ```
- **Verification:** After migration, `SHOW COLUMNS FROM prestige LIKE 'unlocks'` should show type `text`.

---

#### L-002 — LOW: season_recap.molecules_perdues receives (int) cast from DOUBLE

- **File:** `includes/player.php:1045`
- **Severity:** LOW
- **Problem:** `(int)$p['moleculesPerdues']` truncates rather than rounds a DOUBLE, potentially writing a value 1 lower than the true count.
- **Exact fix:**
  ```php
  (int)round($p['moleculesPerdues'])
  ```
- **Verification:** Unit test with a value like `3.7` — old cast gives `3`, new gives `4`.

---

#### L-006 — LOW: compound_activations missing created_at column

- **File:** Migration `migrations/0094_pass9_schema_fixes.sql`
- **Severity:** LOW
- **Problem:** `player_compounds` / `compound_activations` table has no `created_at` column. Future storage-expiry tracking requires it.
- **Exact fix:** Migration (included in 0094):
  ```sql
  ALTER TABLE player_compounds ADD COLUMN IF NOT EXISTS created_at INT NOT NULL DEFAULT 0;
  ```
  Update the INSERT in `includes/compounds.php` to set `created_at = UNIX_TIMESTAMP()`.
- **Verification:** After migration, `SHOW COLUMNS FROM player_compounds` includes `created_at`. Activate a compound and confirm the column is populated.

---

### Group B: XSS & Output Escaping

**Findings:** H-003, H-004, H-005, M-016, L-001

---

#### H-003 — HIGH: rapports.php event-handler strip regex bypassable

- **File:** `rapports.php:32–35, 113–116`
- **Severity:** HIGH
- **Problem:** The regex `[\s\/]+on\w+` requires at least one whitespace or slash before `on`. The payload `<img src="x"onerror=alert(1)>` (no space before `onerror`) bypasses it entirely.
- **Exact fix:** Replace the regex-based strip with a DOMDocument attribute whitelist. Parse the HTML, iterate all elements, remove all attributes, then re-add only the safe subset (`href`, `src`, `alt`, `class`, `id`):
  ```php
  function sanitizeReportHtml(string $html): string {
      if (trim($html) === '') return '';
      $dom = new DOMDocument();
      @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
      $allowedAttrs = ['href', 'src', 'alt', 'class', 'id'];
      foreach ($dom->getElementsByTagName('*') as $node) {
          $toRemove = [];
          foreach ($node->attributes as $attr) {
              if (!in_array(strtolower($attr->name), $allowedAttrs, true)) {
                  $toRemove[] = $attr->name;
              }
          }
          foreach ($toRemove as $name) {
              $node->removeAttribute($name);
          }
      }
      return $dom->saveHTML();
  }
  ```
  Call `sanitizeReportHtml()` at lines 32–35 and 113–116 replacing the current regex.
- **Verification:** Send a report containing `<img src="x"onerror=alert(1)>` and verify it renders as `<img src="x">` with the handler stripped.

---

#### H-004 — HIGH: couleurFormule() called with raw DB formula strings — XSS

- **Files:** `includes/game_actions.php:186, 259, 288`
- **Severity:** HIGH
- **Problem:** Player-crafted molecule formulas stored in the DB are passed directly to `couleurFormule()`, whose output goes into combat-report HTML without escaping. A crafted formula like `<script>alert(1)</script>` executes in victims' browsers when they view reports.
- **Exact fix:** At each of the three call sites, wrap the formula argument with `htmlspecialchars` before passing it:
  ```php
  couleurFormule(htmlspecialchars($formula, ENT_QUOTES, 'UTF-8'))
  ```
- **Verification:** Insert a molecule with formula `<script>alert(1)</script>`, trigger a combat report, view the report page — confirm the script tag is rendered as escaped text, not executed.

---

#### H-005 — HIGH: medailles.php stat values output without escaping

- **File:** `medailles.php:66, 69, 70, 76, 79, 80, 83`
- **Severity:** HIGH
- **Problem:** Numeric DB columns (`$infos[1]` etc.) are interpolated directly into HTML. While currently numeric, DB type changes or injection into integer columns via SQL bugs could trigger XSS.
- **Exact fix:** Wrap every `$infos[N]` output at those line numbers:
  ```php
  htmlspecialchars((string)$infos[1], ENT_QUOTES, 'UTF-8')
  ```
  Apply to all seven affected output locations.
- **Verification:** Manually test the medals page with a test account; confirm output is escaped. Run XSS scanner against `/medailles.php`.

---

#### M-016 — MEDIUM: bilan.php vault percentage uncapped; hardcoded spec modifiers

- **File:** `bilan.php`
- **Severity:** MEDIUM
- **Problem:** (1) The displayed vault protection percentage is not capped at `VAULT_MAX_PROTECTION_PCT`, so edge-case builds can display > 100% protection. (2) Condenseur specialization modifiers in the display are hardcoded numbers rather than config constants.
- **Exact fix:**
  1. After computing `$vault_pct`, add: `$vault_pct = min($vault_pct, VAULT_MAX_PROTECTION_PCT);`
  2. Replace each hardcoded spec modifier with the appropriate constant from `config.php` (e.g., `SPEC_CONDENSEUR_BONUS`). If the constant does not exist, add it to `config.php`.
- **Verification:** Create an account with maximum vault upgrades; confirm bilan.php shows exactly `VAULT_MAX_PROTECTION_PCT`% not higher. Grep `bilan.php` for bare numeric modifier literals and confirm none remain.

---

#### L-001 — LOW: bilan.php unlock cost rendered without escaping

- **File:** `bilan.php:769`
- **Severity:** LOW
- **Problem:** `$unlock['cost']` is output directly into HTML without `htmlspecialchars`, inconsistent with the rest of the file.
- **Exact fix:**
  ```php
  htmlspecialchars((string)$unlock['cost'], ENT_QUOTES, 'UTF-8')
  ```
- **Verification:** Grep `bilan.php` for `$unlock['cost']` and confirm it is wrapped.

---

### Group C: Auth & Session Security

**Findings:** H-006, H-007, H-008, H-009

---

#### H-006 — HIGH: No session_regenerate_id after registration (session fixation)

- **File:** `inscription.php:49`
- **Severity:** HIGH
- **Problem:** After successful registration and session setup, the session ID is not regenerated. An attacker who can pre-set a victim's session ID (e.g., via a sub-domain cookie injection) inherits the authenticated session.
- **Exact fix:** Add immediately before the post-registration redirect:
  ```php
  session_regenerate_id(true);
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  ```
- **Verification:** Log the `PHPSESSID` cookie value before and after registration; confirm they differ.

---

#### H-007 — HIGH: Banned accounts can maintain active sessions

- **File:** `includes/basicprivatephp.php` (session validation SELECT)
- **Severity:** HIGH
- **Problem:** The session validation query checks `login = ?` but does not check `estExclu = 0`. A banned player whose session was set before the ban can continue using all private pages.
- **Exact fix:** Add `AND estExclu = 0` to the WHERE clause of the session-validation SELECT on `membre`.
- **Verification:** Ban a test account in the DB, then attempt to load a private page with that account's session cookie — confirm redirect to login.

---

#### H-008 — HIGH: admin/supprimerreponse.php missing ADMIN_LOGIN guard

- **File:** `admin/supprimerreponse.php:4`
- **Severity:** HIGH
- **Problem:** Other admin files (e.g., `supprimercompte.php`) check `$_SESSION['login'] !== ADMIN_LOGIN` as a secondary guard. This file skips that check, so any authenticated player who discovers the URL can delete forum replies.
- **Exact fix:** Add at line 4, after the existing session check:
  ```php
  if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
      header('Location: ../index.php');
      exit;
  }
  ```
- **Verification:** Log in as a non-admin player, POST to `/admin/supprimerreponse.php` — confirm redirect, no deletion.

---

#### H-009 — HIGH: awardPrestigePoints() double-awards PP when statistiques row is missing

- **File:** `includes/prestige.php:123–127`
- **Severity:** HIGH
- **Problem:** The idempotency guard reads `prestige_awarded_season` from `statistiques`. If the row does not exist (new player, or after a partial season reset), `$statsRow` is null and the guard is skipped, allowing double-award on retry.
- **Exact fix:** After the fetch, add:
  ```php
  if ($statsRow === null) {
      // No stats row yet — treat prestige_awarded_season as 0, safe to award
      $statsRow = ['prestige_awarded_season' => 0];
      // Insert the row before proceeding
      dbExecute("INSERT INTO statistiques (login, prestige_awarded_season) VALUES (?, 0) ON DUPLICATE KEY UPDATE login=login", [$login]);
  }
  ```
  Then proceed with the existing check against `$statsRow['prestige_awarded_season']`.
- **Verification:** Delete a player's `statistiques` row, call `awardPrestigePoints()` twice, confirm PP is awarded exactly once.

---

### Group D: Combat & Resource Floor Guards

**Findings:** H-010, H-011, M-003, M-018

**Note:** M-011 (ceil→int cast) is also combat-related but is covered in Group A due to its schema binding context.

---

#### H-010 — HIGH: Building HP UPDATEs can write negative values

- **File:** `includes/combat.php:606, 624, 642, 660, 678`
- **Severity:** HIGH
- **Problem:** HP updates use pre-computed PHP absolute values. Under concurrent attacks or float precision edge cases, the computed new HP can go negative and is written directly to the DB.
- **Exact fix:** Convert all five UPDATE statements from absolute-value form to delta form with a GREATEST floor:
  ```sql
  UPDATE constructions
  SET vieGenerateur = GREATEST(0, vieGenerateur - ?)
  WHERE login = ?
  ```
  Pass the damage delta (positive number) as the first parameter instead of the new absolute value. Repeat for each of the five building types at lines 606, 624, 642, 660, and 678.
- **Verification:** Unit test a concurrent double-attack scenario; confirm building HP floors at 0.

---

#### H-011 — HIGH: Market energy write can produce negative float values

- **File:** `marche.php:287`
- **Severity:** HIGH
- **Problem:** `energie = ?` writes an absolute PHP-computed value. Float rounding during price calculation can produce a value 1e-10 below zero, writing a negative energy.
- **Exact fix:** Change to delta form:
  ```sql
  SET energie = GREATEST(0, energie - ?) WHERE login = ?
  ```
  Pass `$coutAchat` (the cost delta) as the first parameter.
- **Verification:** Purchase an item whose cost equals the player's exact energy balance; confirm `energie` floors at 0 not -0.000001.

---

#### M-003 — MEDIUM: Defender molecule nombre UPDATE lacks max(0,...) floor

- **File:** `includes/combat.php:433–435`
- **Severity:** MEDIUM
- **Problem:** `$classeDefenseur[$di]['nombre'] - $defenseurMort[$di]` can go negative under float precision edge cases or if `$defenseurMort` is slightly overcounted.
- **Exact fix:**
  ```php
  max(0, $classeDefenseur[$di]['nombre'] - $defenseurMort[$di])
  ```
  Cast to int if appropriate: `(int)max(0, ...)`.
- **Verification:** Test with `$defenseurMort` equal to `$nombre` exactly and slightly over; confirm result is 0 in both cases.

---

#### M-018 — MEDIUM: troupes string parsing unguarded against malformed segments

- **Files:** `includes/combat.php:21–23`, `includes/game_actions.php:107, 132–136`
- **Severity:** MEDIUM
- **Problem:** `explode(';', $troupes)` produces segments that may be empty strings or non-numeric (trailing semicolons, corruption). These are used in arithmetic without validation, causing PHP warnings and potential logic errors.
- **Exact fix:** After each `explode`, add a filter/validation loop:
  ```php
  $segments = array_filter(explode(';', $troupes), fn($s) => is_numeric($s) && $s >= 0);
  $segments = array_values($segments); // re-index
  ```
  Use `$segments` instead of the raw explode result. Add a guard: if count does not match the expected number of molecule classes, treat as empty army.
- **Verification:** Pass `"100;200;;300;"` (trailing semicolon, empty segment) to the parsing code and confirm it produces `[100, 200, 300]` not an error.

---

### Group E: Race Conditions & Transaction Hardening

**Findings:** H-012, H-013, H-015, H-017, H-018, H-022, M-013, M-019, L-008

---

#### H-012 — HIGH: Market recalculerTotalPointsJoueur reads autre without FOR UPDATE

- **File:** `marche.php:318, 475`
- **Severity:** HIGH
- **Problem:** Inside the market transaction, `recalculerTotalPointsJoueur` reads `autre` without `FOR UPDATE`, allowing concurrent combat/prestige to overwrite `totalPoints` between the read and the market UPDATE.
- **Exact fix:** Before calling `recalculerTotalPointsJoueur` at both lines, add:
  ```sql
  SELECT totalPoints FROM autre WHERE login = ? FOR UPDATE
  ```
  This escalates the shared lock to an exclusive lock, preventing the clobber.
- **Verification:** Run two concurrent market transactions for the same player; confirm `totalPoints` reflects both changes without one overwriting the other.

---

#### H-013 — HIGH: Season VP ranking includes deleted/sentinel players

- **File:** `includes/player.php:1095, 1148`
- **Severity:** HIGH
- **Problem:** VP ranking queries do not filter out sentinel players (x = -1000, used for deleted/inactive accounts). Sentinels can appear at the top of VP rankings and receive VP awards.
- **Exact fix:** Add to the WHERE clause of both queries:
  ```sql
  JOIN membre m ON m.login = a.login
  WHERE m.x != -1000 AND m.estExclu = 0
  ```
  (or use the `INACTIVE_PLAYER_X` constant if defined in config.php)
- **Verification:** Create a sentinel player (x = -1000), run VP ranking, confirm sentinel does not appear.

---

#### H-015 — HIGH: cours table gap lock instead of row lock causes lost volatility updates

- **File:** `marche.php:267, 409`
- **Severity:** HIGH
- **Problem:** `ORDER BY timestamp DESC LIMIT 1 FOR UPDATE` on `cours` creates a gap lock on the most-recent row, not a precise row lock. Concurrent trades acquire the same gap lock, causing lost volatility updates (last writer wins).
- **Exact fix:** Add a `is_current` TINYINT(1) column to `cours` (migration 0094 or separate migration) with a single `1` row per resource. Replace the `ORDER BY ... LIMIT 1` pattern with `WHERE ressource = ? AND is_current = 1 FOR UPDATE`. Update-in-place instead of INSERT for current price, INSERT only for history rows.

  If the schema change is deferred, as a minimum fix: do the volatility calculation entirely inside a single atomic SQL expression to avoid the read-modify-write cycle:
  ```sql
  UPDATE cours SET prix = LEAST(GREATEST(prix * ?, MIN_PRICE), MAX_PRICE)
  WHERE ressource = ? ORDER BY timestamp DESC LIMIT 1
  ```
- **Verification:** Run two concurrent trades on the same resource; confirm both volatility effects are applied (not one lost).

---

#### H-017 — HIGH: War-end check outside withTransaction (double-end race)

- **File:** `allianceadmin.php:401–402`
- **Severity:** HIGH
- **Problem:** `$guerreExiste` is queried before the `withTransaction` block. Two concurrent war-end requests both see `$guerreExiste = 1`, both enter the transaction, and both mark the war as ended — one with incorrect VP.
- **Exact fix:** Move the `$guerreExiste` SELECT inside `withTransaction`, adding `FOR UPDATE`:
  ```sql
  SELECT id FROM declarations WHERE id = ? AND type = 0 AND fin = 0 FOR UPDATE
  ```
  If no row returned, bail out of the transaction.
- **Verification:** Simulate two concurrent POST requests to end the same war; confirm only one succeeds and the war row is updated exactly once.

---

#### H-018 — HIGH: Espionage defender notification fires on failed espionage

- **File:** `includes/game_actions.php:492–498`
- **Severity:** HIGH
- **Problem:** The defender notification INSERT fires regardless of espionage success or failure. A player can spam cheap failed espionage attempts to flood the defender's notification feed.
- **Exact fix:** Move the defender notification INSERT inside the success branch (`if ($espionageThreshold < ...)` block), so it only fires when espionage succeeds.
- **Verification:** Attempt espionage guaranteed to fail (very low stat ratio); confirm defender receives no notification. Succeed at espionage; confirm defender is notified.

---

#### H-022 — HIGH: VP award lacks per-player idempotency (double-VP on retry)

- **File:** `includes/player.php:1171–1178`
- **Severity:** HIGH
- **Problem:** VP is awarded in chunks without a per-player idempotency check. If the season-end job partially fails and is retried, players in the completed chunk receive VP twice.
- **Exact fix:** Use the existing `victoires_saison` column (or add `vp_awarded_season TINYINT(1) DEFAULT 0` to `autre`) as an idempotency flag. Before awarding VP to a player, check and set this flag atomically:
  ```sql
  UPDATE autre SET vp_awarded_season = 1, victoires = victoires + ?
  WHERE login = ? AND vp_awarded_season = 0
  ```
  If 0 rows affected, skip (already awarded).
- **Verification:** Call the VP award function twice for the same player; confirm VP increments only once.

---

#### M-013 — MEDIUM: editer.php self-delete without transaction (TOCTOU race)

- **File:** `editer.php:50–58`
- **Severity:** MEDIUM
- **Problem:** The flow fetches `auteur` from `reponses`, checks ownership, then DELETEs — without a transaction or FOR UPDATE. A concurrent edit could change ownership between the check and the delete.
- **Exact fix:** Wrap in `withTransaction`:
  ```php
  withTransaction(function() use ($id, $login) {
      $row = dbFetchOne("SELECT auteur FROM reponses WHERE id = ? FOR UPDATE", [$id]);
      if ($row && $row['auteur'] === $login) {
          dbExecute("DELETE FROM reponses WHERE id = ?", [$id]);
      }
  });
  ```
- **Verification:** Confirm `editer.php` uses `withTransaction` after fix. Test concurrent delete/edit requests.

---

#### M-019 — MEDIUM: Market transfer partial-overflow not blocked

- **File:** `marche.php:100–120`
- **Severity:** MEDIUM
- **Problem:** The storage check only verifies whether the recipient is already at max capacity, not whether adding the specific transfer amount would exceed it. A transfer of 1 unit to a recipient who has `max - 0.5` capacity passes the check but overflows storage.
- **Exact fix:** For each resource being transferred, replace:
  ```php
  if ($ressourcesReceveur[$r] >= $maxStorage)
  ```
  with:
  ```php
  if ($ressourcesReceveur[$r] + $amount > $maxStorageReceveur[$r])
  ```
  using the specific `$amount` for resource `$r`.
- **Verification:** Transfer an amount that would put recipient exactly 1 unit over max; confirm transaction is blocked.

---

#### L-008 — LOW: Alliance war-end double-end race (duplicate of H-017)

- **File:** `allianceadmin.php:401`
- **Severity:** LOW
- **Note:** This is the same root cause as H-017. Implementing H-017 fully resolves L-008.
- **No additional fix required beyond H-017.**

---

### Group F: Input Validation & Rate Limiting

**Findings:** H-014, H-016, H-019, M-001, M-002, M-004, M-007, M-012, M-023

**Note:** H-020 (officer kick hierarchy) is covered in Group G.

---

#### H-014 — HIGH: Array injection on attack/espionage target params

- **Files:** `attaquer.php:95, 26`
- **Severity:** HIGH
- **Problem:** `$_POST['joueurAAttaquer']` and `$_POST['joueurAEspionner']` are passed directly to `trim()` without checking `is_string()`. PHP allows POST parameters to be arrays; `trim(array)` returns `""` (with a notice), bypassing self-attack and alliance-membership checks that rely on the player name string.
- **Exact fix:** At both locations, add before `trim()`:
  ```php
  if (!is_string($_POST['joueurAAttaquer'])) {
      $erreur = 'Joueur invalide.';
  } else {
      $cible = trim($_POST['joueurAAttaquer']);
  }
  ```
  Apply the same pattern for `joueurAEspionner`.
- **Verification:** POST `joueurAAttaquer[]=victim` (array form) to `attaquer.php`; confirm error response, no attack executed.

---

#### H-016 — HIGH: Compound activation missing CAS guard (AND activated_at IS NULL)

- **File:** `includes/compounds.php` (activation UPDATE)
- **Severity:** HIGH
- **Problem:** The activation UPDATE does not include `AND activated_at IS NULL` in the WHERE clause. Two concurrent activation requests for the same compound both succeed, activating the compound twice and deducting resources twice.
- **Exact fix:** Change the activation UPDATE WHERE clause from:
  ```sql
  WHERE id = ? AND login = ?
  ```
  to:
  ```sql
  WHERE id = ? AND login = ? AND activated_at IS NULL
  ```
  Check `affected_rows`; if 0, the compound was already activated.
- **Verification:** Submit two simultaneous activation requests for the same compound; confirm only one activation occurs.

---

#### H-019 — HIGH: validatePassword() uses mb_strlen (bytecount mismatch vs bcrypt 72-byte limit)

- **File:** `includes/validation.php:24`
- **Severity:** HIGH
- **Problem:** `mb_strlen($password)` counts Unicode code points, not bytes. A password of 72 multibyte characters (e.g., 72 × 2-byte characters = 144 bytes) passes the length check but is silently truncated by bcrypt to 72 bytes, producing a weaker hash than the user intended.
- **Exact fix:** Change:
  ```php
  mb_strlen($password)
  ```
  to:
  ```php
  strlen($password)
  ```
  This enforces the byte-accurate limit that bcrypt actually uses.
- **Verification:** Test with a 73-byte password (e.g., 73 ASCII characters); confirm `validatePassword()` returns false. Test with a 72-byte multibyte string; confirm it correctly passes or fails based on byte count.

---

#### M-001 — MEDIUM: Rate limiter file read without LOCK_EX (TOCTOU burst bypass)

- **File:** `includes/rate_limiter.php`
- **Severity:** MEDIUM
- **Problem:** The rate limiter reads the counter file without `LOCK_EX`, then writes with `LOCK_EX`. Between the read and the write, concurrent requests see the old counter and all pass the limit check — classic TOCTOU. This allows burst bypass.
- **Exact fix:** Use an atomic read-lock-write pattern. Replace the `file_get_contents` read with an exclusive `fopen`/`flock`/`fread`/`fwrite`/`fclose` sequence:
  ```php
  $fp = fopen($file, 'c+');
  if (flock($fp, LOCK_EX)) {
      $data = fread($fp, 1024);
      // ... check and update counter ...
      fseek($fp, 0);
      ftruncate($fp, 0);
      fwrite($fp, $newData);
      flock($fp, LOCK_UN);
  }
  fclose($fp);
  ```
- **Verification:** Simulate 20 concurrent login attempts; confirm only the configured maximum pass through.

---

#### M-002 — MEDIUM: logLoginEvent() uncaught exception aborts registration

- **File:** `inscription.php:51–53`
- **Severity:** MEDIUM
- **Problem:** `logLoginEvent()` can throw if the multiaccount detection system fails (DB down, disk full). This exception propagates out of inscription.php and aborts the post-registration redirect, leaving the user confused.
- **Exact fix:**
  ```php
  try {
      logLoginEvent($_SERVER['REMOTE_ADDR'], $_SESSION['login']);
  } catch (Throwable $e) {
      logError('logLoginEvent failed during registration: ' . $e->getMessage());
  }
  ```
- **Verification:** Temporarily break `logLoginEvent()` to throw; confirm registration still completes with a logged error.

---

#### M-004 — MEDIUM: Inconsistent salt 'tvlw' vs 'tvlw_salt' in rate-limit log

- **File:** `inscription.php:13`
- **Severity:** MEDIUM
- **Problem:** The IP hashing for rate-limit logging uses `'tvlw'` as the salt literal, but the canonical salt is `'tvlw_salt'` (or `SECRET_SALT` constant). Inconsistency means IP hashes in logs don't match across different code paths.
- **Exact fix:** Change the literal `'tvlw'` to `SECRET_SALT` (the config.php constant). If `SECRET_SALT` is not yet defined, define it in `config.php`.
- **Verification:** Grep `inscription.php` for `'tvlw'` (bare, not `'tvlw_salt'`) — none should remain. Confirm both paths produce the same hash for the same IP.

---

#### M-007 — MEDIUM: No max-length check on joueurAAttaquer before DB query

- **File:** `attaquer.php`
- **Severity:** MEDIUM
- **Problem:** The attack target parameter has no length guard. An extremely long string causes a slow LIKE query or wastes DB resources.
- **Exact fix:**
  ```php
  if (mb_strlen($cible) > LOGIN_MAX_LENGTH) {
      $erreur = 'Joueur invalide.';
  }
  ```
  (Add after the `is_string()` guard from H-014.)
- **Verification:** POST a 1000-character target name; confirm error response, no DB query executed.

---

#### M-012 — MEDIUM: Forum reply missing hourly rate limit

- **Files:** `sujet.php:18`, `listesujets.php:47`
- **Severity:** MEDIUM
- **Problem:** The burst limit (10 per 5 minutes) allows 120 replies per hour effectively — no hourly ceiling. A bot or troll can spam the forum by spacing requests just past the burst window.
- **Exact fix:** Add a secondary hourly limit check after the existing burst check:
  ```php
  rateLimitCheck($_SESSION['login'], 'forum_reply_hourly', 60, 3600);
  ```
  (60 per hour, adjust constant if needed.)
- **Verification:** Post 61 replies in 1 hour (burst-spaced); confirm the 61st is rejected. Confirm first 60 succeed.

---

#### M-023 — MEDIUM: rateLimitCheck uses raw REMOTE_ADDR (proxy-unaware)

- **Files:** `inscription.php:12`, `joueur.php:15`
- **Severity:** MEDIUM (documentation + minor code)
- **Problem:** `$_SERVER['REMOTE_ADDR']` is used directly without proxy awareness. When placed behind a CDN or reverse proxy, all requests will share the proxy's IP, making rate limiting useless.
- **Exact fix:** Centralize IP extraction in `rate_limiter.php`:
  ```php
  function getRateLimitIp(): string {
      if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])
          && in_array($_SERVER['REMOTE_ADDR'], TRUSTED_PROXY_IPS, true)) {
          return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
      }
      return $_SERVER['REMOTE_ADDR'];
  }
  ```
  Replace raw `$_SERVER['REMOTE_ADDR']` in both files with `getRateLimitIp()`.
- **Verification:** Set `TRUSTED_PROXY_IPS` to the current REMOTE_ADDR, set `HTTP_X_FORWARDED_FOR` to a test IP, confirm rate limit applies to the forwarded IP.

---

### Group G: Alliance & Social Logic

**Findings:** H-020, H-021, M-005, M-006, M-017, L-004

---

#### H-020 — HIGH: Alliance officer can kick another officer (no grade hierarchy check)

- **File:** `allianceadmin.php:243–280`
- **Severity:** HIGH
- **Problem:** When banning a member, only alliance membership is checked — not whether the target has an officer grade equal to or higher than the kicker. Officers can kick each other, destabilizing alliances.
- **Exact fix:** Before executing the ban, query the target's grade:
  ```php
  $targetGrade = dbFetchOne(
      "SELECT idgrade FROM grades WHERE login = ? AND idalliance = ?",
      [$targetLogin, $allianceId]
  );
  $kickerIsChef = ($_SESSION['login'] === $allianceChef);
  if ($targetGrade && !$kickerIsChef) {
      $erreur = 'Vous ne pouvez pas exclure un officier.';
  }
  ```
- **Verification:** Log in as officer A, attempt to kick officer B; confirm rejection. Log in as chef; confirm kick of officer B succeeds.

---

#### H-021 — HIGH: Season reset email sent to banned/sentinel players

- **File:** `includes/basicprivatephp.php:243`
- **Severity:** HIGH
- **Problem:** The email SELECT for season-end notifications does not filter banned (`estExclu = 1`) or sentinel (x = -1000) players, sending potentially PII-exposing emails to invalid/banned accounts.
- **Exact fix:** Add to the WHERE clause:
  ```sql
  AND m.x != -1000 AND m.estExclu = 0
  ```
- **Verification:** Mark a test account as `estExclu=1`, run season email dispatch, confirm that account receives no email.

---

#### M-005 — MEDIUM: Member can leave alliance during active war

- **File:** `alliance.php:74–89`
- **Severity:** MEDIUM
- **Problem:** No check prevents a player from leaving their alliance while the alliance is at war. This could abandon the alliance mid-war and invalidate combat/VP calculations.
- **Exact fix:** Before the leave transaction, check:
  ```php
  $activeWar = dbFetchOne(
      "SELECT id FROM declarations WHERE (alliance1 = ? OR alliance2 = ?) AND type = 0 AND fin = 0",
      [$allianceId, $allianceId]
  );
  if ($activeWar) {
      $erreur = 'Vous ne pouvez pas quitter une alliance en guerre.';
  }
  ```
- **Verification:** Declare war on an alliance, attempt to leave from that alliance — confirm blocked. Leave succeeds when no active war.

---

#### M-006 — MEDIUM: Chef can dissolve alliance mid-war

- **File:** `allianceadmin.php:47–52`
- **Severity:** MEDIUM
- **Problem:** Alliance dissolution during an active war silently drops VP that should go to the opposing alliance, and leaves the war in a permanently unresolvable state.
- **Exact fix:** Before allowing dissolution, check for active wars:
  ```php
  $activeWar = dbFetchOne(
      "SELECT id FROM declarations WHERE (alliance1 = ? OR alliance2 = ?) AND type = 0 AND fin = 0",
      [$allianceId, $allianceId]
  );
  if ($activeWar) {
      $erreur = 'Vous ne pouvez pas dissoudre une alliance en guerre.';
  }
  ```
  Alternatively, force-end all active wars first (awarding VP to the opposing side) before dissolution proceeds.
- **Verification:** Declare a war, attempt to dissolve the alliance — confirm blocked or war auto-resolved with VP awarded.

---

#### M-017 — MEDIUM: allianceadmin.php dead duplicate-check queries

- **File:** `allianceadmin.php:291–293`
- **Severity:** MEDIUM
- **Problem:** `$nbDeclarations` and `$nbDeclarations1` are queried but their results are never used — the actual guard logic was moved inside the transaction. These dead queries waste a round-trip on every pact/war page load.
- **Exact fix:** Delete lines 291–293 entirely (the two dead SELECT COUNT queries and their variable assignments).
- **Verification:** After deletion, grep `allianceadmin.php` for `$nbDeclarations` — confirm no references remain (other than inside the transaction where the live version lives).

---

#### L-004 — LOW: allianceadmin.php:265-269 silently swallows alliance_left_at error

- **File:** `allianceadmin.php:265–269`
- **Severity:** LOW
- **Problem:** The catch block for the `alliance_left_at` UPDATE is empty — errors are silently swallowed with no logging.
- **Exact fix:** Add inside the catch block:
  ```php
  logError('Failed to update alliance_left_at for login=' . $targetLogin . ': ' . $e->getMessage());
  ```
- **Verification:** Confirm `logError` is called on a simulated exception. Grep `allianceadmin.php` for empty catch blocks — none should remain.

---

### Group H: Prestige & Season Logic

**Findings:** M-014, M-021, L-003

---

#### M-014 — MEDIUM: Prestige "active final week" check uses wall-clock delta, not season-relative date

- **File:** `includes/prestige.php:56–59`
- **Severity:** MEDIUM
- **Problem:** The check for whether a player was active in the final week of the season compares `time() - $lastActive < SECONDS_PER_WEEK`. This is relative to now (wall clock), not the season end date. A late admin reset means the season lasts longer and the bonus is never awarded for anyone active in the genuine final week.
- **Exact fix:** Store `season_start_timestamp` in `statistiques` (add column if needed) and compute the season end as `season_start_timestamp + SEASON_DURATION`. Then:
  ```php
  $seasonEnd = $statsRow['season_start_timestamp'] + SEASON_DURATION;
  $finalWeekStart = $seasonEnd - SECONDS_PER_WEEK;
  if ($lastActive >= $finalWeekStart) {
      // Award final-week bonus
  }
  ```
- **Verification:** Set `season_start_timestamp` to 30 days ago, set `SEASON_DURATION` to 30 days, confirm `finalWeekStart` is 7 days ago and a player active 5 days ago receives the bonus.

---

#### M-021 — MEDIUM: $PRESTIGE_RANK_BONUSES relies on PHP array insertion order

- **File:** `includes/prestige.php:153–157`
- **Severity:** MEDIUM
- **Problem:** The rank bonus array is iterated assuming ascending key order. PHP guarantees insertion order for string keys but not integer key numeric ordering. If the array is ever reordered in source (e.g., someone adds a rank between existing ones), the iteration produces wrong bonus tiers.
- **Exact fix:** Add `ksort($PRESTIGE_RANK_BONUSES);` immediately before the `foreach` loop:
  ```php
  ksort($PRESTIGE_RANK_BONUSES);
  foreach ($PRESTIGE_RANK_BONUSES as $rank => $bonus) { ... }
  ```
- **Verification:** Shuffle the array definition order in a test; confirm the foreach still produces the correct tier progression.

---

#### L-003 — LOW: prestige.php hardcoded "+1 PP par palier" description

- **File:** `prestige.php:177`
- **Severity:** LOW
- **Problem:** The description "+1 PP par palier" is hardcoded. If `PRESTIGE_PP_PER_MEDAL_TIER` is ever changed in config.php, the UI description becomes stale.
- **Exact fix:** Replace the literal `+1` with the constant:
  ```php
  '+' . PRESTIGE_PP_PER_MEDAL_TIER . ' PP par palier'
  ```
  If `PRESTIGE_PP_PER_MEDAL_TIER` is not yet defined in `config.php`, define it there first.
- **Verification:** Change `PRESTIGE_PP_PER_MEDAL_TIER` to 2; confirm prestige.php displays "+2 PP par palier" without code changes.

---

## Migration File

**Path:** `migrations/0094_pass9_schema_fixes.sql`

Covers: M-015 (prestige.unlocks TEXT), L-006 (player_compounds.created_at), M-020 (cours.timestamp index).

See the migration file for the full SQL.

---

## Verification Checklist (post-fix, all groups)

1. Run full test suite: `cd /home/guortates/TVLW/The-Very-Little-War && vendor/bin/phpunit` — expect 0 failures, 38 expected integration errors unchanged.
2. Run migration on VPS: `php migrate.php` — confirm 0094 applies cleanly.
3. Check all modified PHP files for syntax: `find . -name '*.php' -exec php -l {} \; | grep -v "No syntax errors"`
4. Spot-check each fixed page returns HTTP 200: `curl -I https://theverylittlewar.com/<page>`
5. Grep for any remaining `mb_strlen` in validation.php — should be 0 (H-019).
6. Grep for `image = NULL` in player.php — should be 0 (H-001).
7. Confirm `admin/supprimerreponse.php` contains `ADMIN_LOGIN` check (H-008).
8. Confirm `basicprivatephp.php` session query includes `estExclu = 0` (H-007).

---

## Priority Order for Sequential Deployment

If groups cannot be deployed fully in parallel, deploy in this order:

1. **Group A** — schema migration must run first (other groups may depend on new columns)
2. **Group C** — auth fixes protect the entire surface area
3. **Group E** — transaction hardening prevents data corruption under load
4. **Group D** — resource floor guards prevent negative-value DB state
5. **Group B** — XSS fixes (no DB dependency)
6. **Group F** — input validation
7. **Group G** — alliance logic (functional, not security-critical path)
8. **Group H** — prestige/season logic (fires only at season end)
