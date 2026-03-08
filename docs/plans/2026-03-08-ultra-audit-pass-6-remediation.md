# Ultra Audit Pass 6 — Remediation Plan

**Date:** 2026-03-08
**Source reports:** pass-6-sec, pass-6-db, pass-6-combat, pass-6-economy, pass-6-alliance, pass-6-season, pass-6-ux, pass-6-code
**Total findings:** 73 (13 HIGH / 30 MEDIUM / 18 LOW / 12 INFO)
**Deduplicated:** SEC-P6-001 = ADM-P6-002 (moderation IP binding — one fix)

---

## BATCH A — HIGH priority (critical fixes, minimal risk)

### A-1: CODE-P6-001 — withTransaction depth counter goes negative on failure
**File:** includes/database.php
**Fix:** Remove `$depth--` in the begin/savepoint failure branches (they fire before `$depth++`).

### A-2: DB-P6-001 — combat.php queries non-existent `jeu` table
**File:** includes/combat.php ~line 689
**Fix:** `SELECT debut FROM jeu` → `SELECT debut FROM statistiques`

### A-3: SOC-P6-001 + SOC-P6-002 — Pact accept/refuse form completely broken
**Files:** rapports.php, allianceadmin.php, validerpacte.php
**Fix:** In `rapports.php` display loop: detect pact declaration ID in report content and inject a fresh dynamically-generated form (with current viewer's CSRF token) instead of relying on stored HTML. Remove form/CSRF token from the stored `rapportContenu` in `allianceadmin.php`.

### A-4: ADM-P6-001 — supprimerJoueur omits login_history and account_flags
**File:** includes/player.php supprimerJoueur()
**Fix:** Add `DELETE FROM login_history WHERE login=?` and `DELETE FROM account_flags WHERE login=? OR related_login=?` inside the deletion transaction.

### A-5: SEC-P6-001 / ADM-P6-002 — Moderation session no IP binding
**Files:** moderation/index.php, moderation/mdp.php
**Fix:** Store `$_SESSION['mod_ip']` at login; validate in `mdp.php` guard.

### A-6: CODE-P6-002 — Raw echo corrupts HTML output in constructions.php
**File:** constructions.php:15 and :59
**Fix:** Remove both bare `echo intval(...)` calls (error is already shown via `$erreur`).

### A-7: DB-P6-002 — declarations.pertesTotales never written; season war archive empty
**File:** new migration
**Fix:** Add generated column migration: `ALTER TABLE declarations MODIFY COLUMN pertesTotales INT GENERATED ALWAYS AS (pertes1 + pertes2) STORED;`

### A-8: ECO-P6-001 — tradeVolume capped at 80 in DB, TRADE_VOLUME_CAP (10M) dead
**File:** marche.php
**Fix:** Remove the `LEAST(tradeVolume, MARKET_POINTS_MAX)` UPDATE from buy and sell paths. Let raw tradeVolume accumulate up to TRADE_VOLUME_CAP.

### A-9: UX-P6-001 — Invalid CSS rgba(,0.6) in classement.php daily + forum tabs
**File:** classement.php:147, :689
**Fix:** Wrap `$enGuerre` output in conditional: only emit style when `$enGuerre !== ''`.

### A-10: UX-P6-002 — joueur.php blank page without ?id param
**File:** joueur.php
**Fix:** Add else branch after the `if (isset($_GET['id']))` block with redirect to own profile.

---

## BATCH B — MEDIUM security/correctness fixes

### B-1: SEC-P6-006 — Vacation mode allows alliance mutations
**File:** includes/basicprivatephp.php, alliance.php
**Fix:** Remove `alliance.php` from `$vacationAllowedPages`; add per-action vacation checks inside `alliance.php` POST handlers for state-mutating actions.

### B-2: SEC-P6-003 — ecriremessage.php [all] broadcast checks non-existent `role` column
**File:** ecriremessage.php
**Fix:** Replace `WHERE login=? AND role='admin'` with `$_SESSION['login'] === ADMIN_LOGIN`.

### B-3: SEC-P6-004 — Rate limiter GC maxWindow hardcoded to 3600, may be < window
**File:** includes/rate_limiter.php
**Fix:** `$maxWindow = max(RATE_LIMIT_LOGIN_WINDOW, RATE_LIMIT_ADMIN_WINDOW, RATE_LIMIT_REGISTER_WINDOW) * 2;`

### B-4: SEC-P6-005 — Avatar upload uses uniqid() (predictable)
**File:** compte.php
**Fix:** `$fichier = bin2hex(random_bytes(16)) . '.' . $extension;`

### B-5: SEC-P6-008 — Session token write outside transaction in comptetest.php
**File:** comptetest.php
**Fix:** Move session token regeneration + DB update inside the existing `withTransaction()` block.

### B-6: SEC-P6-009 — comptetest.php uses weak email regex
**File:** comptetest.php
**Fix:** Replace `preg_match(...)` with `validateEmail($_POST['email'])`.

### B-7: ADM-P6-003 — Mass-delete by IP uses unvalidated POST value
**File:** admin/index.php
**Fix:** Add `filter_var($ip, FILTER_VALIDATE_IP)` check + cap at 5 accounts.

### B-8: ADM-P6-004 — checkTimingCorrelation Cartesian self-join DoS
**File:** includes/multiaccount.php
**Fix:** Rewrite to EXISTS-based subquery; add login_history periodic purge cron.

### B-9: ADM-P6-005 — Full fingerprint stored in evidence JSON
**File:** includes/multiaccount.php
**Fix:** `'shared_fingerprint' => substr($fingerprint, 0, 12)`

### B-10: SOC-P6-003 — Leadership transfer doesn't remove old chef's grade
**File:** allianceadmin.php changerchef block
**Fix:** Add `DELETE FROM grades WHERE login=? AND idalliance=?` inside transaction.

### B-11: SOC-P6-004 — Grade string not validated before explode
**File:** allianceadmin.php
**Fix:** Validate `count($bits) === 5` before trusting.

### B-12: SOC-P6-005 — No cap on grades per alliance
**File:** allianceadmin.php
**Fix:** Add count check before INSERT; cap at MAX_ALLIANCE_MEMBERS.

### B-13: ECO-P6-003 — Transfer storage check only blocks if ALL resources overflow
**File:** marche.php
**Fix:** Change `$noRoomCount === $sentCount` to `$noRoomCount > 0`.

### B-14: ECO-P6-005 — revenuAtome() not clamped to max(0, ...)
**File:** includes/game_resources.php
**Fix:** Add `$result = max(0, $result);` before return in `revenuAtome()`.

### B-15: DB-P6-003 — N+1 queries in revenuAtome() (3 queries × 8 atom types)
**File:** includes/game_resources.php
**Fix:** Add player-level static cache for idalliance/duplicateur/position shared across all 8 atom calls.

### B-16: DB-P6-005 — Missing composite index on login_history for self-join
**File:** new migration
**Fix:** `ALTER TABLE login_history ADD INDEX idx_lh_ip_login_ts (ip, login, timestamp);`

### B-17: CODE-P6-003 — Static function caches not cleared by invalidatePlayerCache()
**File:** includes/game_resources.php, includes/formulas.php, includes/player.php
**Fix:** Wire invalidatePlayerCache() to also clear static cache arrays via a global flag/generation counter.

### B-18: CODE-P6-006 — Division by zero in armee.php when tempsPourUn == 0
**File:** armee.php
**Fix:** Add `if ($actionsformation['tempsPourUn'] <= 0) { continue; }` guard.

### B-19: UX-P6-003 — CSRF rotation breaks multi-form compte.php
**File:** includes/csrf.php + compte.php
**Fix:** Don't rotate CSRF token on success in csrf.php (or use per-action tokens).

### B-20: UX-P6-004 — Prestige streak progress bar outside F7 page context
**File:** prestige.php
**Fix:** Use inline CSS `width` on inner `<span>` instead of F7 `data-progress`.

### B-21: UX-P6-005 — Daily leaderboard missing Commerce/Victoire columns
**File:** classement.php
**Fix:** Add `tradeVolume` and `victoires` columns to daily view headers and rows.

### B-22: UX-P6-006 — attaquer.php cost display uses innerHTML
**File:** attaquer.php
**Fix:** `textContent` instead of `innerHTML` for `#coutEnergie`.

### B-23: UX-P6-007 — Navbar countdown shows only "Xj" server-side, flickers to "Xj Yh Zm"
**File:** includes/layout.php
**Fix:** Pre-render full days+hours+minutes server-side.

---

## BATCH C — LOW/cleanup fixes

### C-1: CMB-P6-001 — mt_rand in building targeting (inconsistent with CSPRNG policy)
**File:** includes/combat.php:543
**Fix:** `random_int(1, $totalWeight)`

### C-2: CMB-P6-002 — Dispersée post-loop overkill missing fractional kill probability
**File:** includes/combat.php
**Fix:** Add fractional probability rounding to post-loop overkill application.

### C-3: CMB-P6-003 — DUPLICATEUR_COMBAT_COEFFICIENT dead constant
**File:** includes/config.php
**Fix:** Remove constant; fix comment to describe formula using DUPLICATEUR_BONUS_PER_LEVEL.

### C-4: ECO-P6-006 — Market price loop has no count guard against trailing-comma corruption
**File:** marche.php
**Fix:** `$txTabCours = array_slice($txTabCours, 0, $nbRes);` before loop; `sizeof` → `count`.

### C-5: ECO-P6-007 — Transfer ressourcesRecues stores unrounded floats
**File:** marche.php
**Fix:** Wrap with `round()` before string concatenation.

### C-6: ECO-P6-008 — diminuerBatiment silent point leak when allocations < points-to-remove
**File:** includes/player.php
**Fix:** After foreach, add check for residual `$pointsAEnlever > 0` and log/clamp.

### C-7: ADM-P6-006 — season_recap.php casts molecules_perdues to float
**File:** season_recap.php
**Fix:** `(int)` instead of `(float)`.

### C-8: ADM-P6-007 — detailLogin not validated in multiaccount.php
**File:** admin/multiaccount.php
**Fix:** Add strlen + regex validation before DB queries.

### C-9: ADM-P6-008 — maintenance.php href filter misses data: and vbscript:
**File:** maintenance.php
**Fix:** Expand preg_replace to cover `(javascript|data|vbscript)` schemes.

### C-10: SOC-P6-006 — Topic count inconsistency forum.php vs listesujets.php
**File:** forum.php
**Fix:** Remove `AND statut = 0` from nbSujets query to match listesujets.php.

### C-11: SOC-P6-008 — Alliance invitation fullness uses stale count
**File:** allianceadmin.php
**Fix:** Re-fetch live member count inside invitation logic.

### C-12: DB-P6-006 — mysqli_affected_rows() called directly instead of dbExecute return
**File:** includes/player.php, includes/game_resources.php
**Fix:** Use `$result = dbExecute(...)` return value directly.

### C-13: CODE-P6-004 — Missing \ prefix on Exception catch clauses (5 locations)
**Files:** attaquer.php, marche.php, includes/basicprivatephp.php
**Fix:** Add `\` prefix: `catch (\RuntimeException $e)`, `catch (\Exception $e)`.

### C-14: CODE-P6-005 — sizeof() alias in 15+ locations
**Fix:** Replace all `sizeof(` with `count(`.

### C-15: CODE-P6-007 — ajouterPoints CAS guard ignores dbExecute return
**File:** includes/player.php
**Fix:** `if ($result > 0)` instead of `if (mysqli_affected_rows($base) > 0)`.

### C-16: CODE-P6-009 — Formation bind types use 'ds' where 'is' appropriate
**File:** includes/game_actions.php
**Fix:** Cast `$formed = (int)floor(...)`, use `'is'` bind types.

### C-17: UX-P6-008 — season_recap.php unaccented French strings
**File:** season_recap.php
**Fix:** Replace ASCII substitutions with proper UTF-8 accents.

### C-18: UX-P6-009 — symboleEnNombre JS dead-code bug (.replace result discarded)
**File:** includes/copyright.php
**Fix:** Remove the discarded `.replace()` line; just `chaine = parseFloat(chaine) * si[j].value;`.

### C-19: UX-P6-010 — Tutorial hardcodes "deux jours" for beginner protection
**File:** includes/cardsprivate.php
**Fix:** Use `round(BEGINNER_PROTECTION_SECONDS / SECONDS_PER_DAY)` dynamically.

### C-20: UX-P6-011 — affichageTemps() called with negative values
**Files:** constructions.php, attaquer.php
**Fix:** Wrap with `max(0, ...)` at call sites.

### C-21: SEC-P6-010 — Rate limiter key uses concatenation separator '_' (collision risk)
**File:** includes/rate_limiter.php
**Fix:** `hash('sha256', json_encode([$identifier, $action]))` as key.

### C-22: CODE-P6-010 — updateActions guard not reset on exception
**File:** includes/game_actions.php
**Fix:** Wrap body in try/finally: `finally { unset($updating[$joueur]); }`

---

## New Migrations

- **0071_declarations_pertesTotales_generated.sql** — GENERATED ALWAYS AS (pertes1+pertes2) STORED
- **0072_login_history_composite_idx.sql** — composite idx_lh_ip_login_ts

---

## INFO / Deferred (no code fix needed now)

- CMB-P6-004/008: Compound snapshot semantics — document in regles.php
- CMB-P6-005: NaCl recipe chemistry — cosmetic
- CMB-P6-006: Dispersée overkill redistribution logic — complex design change, defer
- CMB-P6-007: Building targeting 0-level guard — correct, no action
- DB-P6-004: performSeasonEnd N+1 — optimization, not a bug
- DB-P6-007: Redundant autre queries in combat — optimization, not a bug
- ECO-P6-002: Transfer IP check architectural issues — NAT problem, advisory
- ECO-P6-004: coefDisparition cache invalidation — complex, low-impact
- ECO-P6-009: TRADE_VOLUME_CAP naming — cosmetic rename
- SEC-P6-002: Shared admin/mod password — architectural, requires new constants
- SEC-P6-007: Moderation CSP — addressed alongside IP binding fix
- SEC-P6-011: cookie_secure dynamic — addressed after HTTPS/DNS
- SEC-P6-012: SECRET_SALT in config — .env migration is larger work
- SOC-P6-007: Pact break symmetry — no bug
- SOC-P6-009: sujet.php script tag — correct
- UX-P6-012: Forum ranking DENSE_RANK — minor, defer
- ADM-P6-009: Admin gate CLI assumption — document only
- CODE-P6-008: couleurFormule defense gap — document, add assertion
