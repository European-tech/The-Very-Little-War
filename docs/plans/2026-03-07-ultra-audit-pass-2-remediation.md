# Remediation Plan — Ultra Audit Pass 2
**Date:** 2026-03-07
**Raw findings:** 191 across 12 domains
**After deduplication:** 171 unique findings
**Severity breakdown:** 4 CRITICAL, 38 HIGH, 72 MEDIUM, 57 LOW
**Batches:** 25 batches, estimated 25 commits

---

## Deduplication Notes

| Canonical ID | Duplicates Merged |
|---|---|
| CRIT-001 (inscrire positional INSERT) | D10-001 = D9-014 |
| HIGH-002 (rate limit ignored in marche.php) | D3-007 = D5-001 |
| HIGH-003 (stale price in market buy) | D3-001 = D5-003 |
| HIGH-018 (timing-unsafe token in deconnexion) | D1-003 = D7-027 |
| HIGH-019 (admin session no DB token) | D1-014 = D7-023 |
| MED-001 (revenuAtomeJavascript averages node bonus) | D3-004 = D12-003 |
| LOW-001 (CSP style-src unsafe-inline) | D1-011 = D11-012 |
| D12-013 (revenuEnergie queries constructions twice) | merged into D10-026 note (LOW-049) |
| D3-002 (MARKET_POINTS_MAX never enforced) | addressed as new HIGH-039 |
| D5-004 (sell trade points arbitrage on post-tax energy) | = MED-003 (buy-sell cycling inflates trade volume) — both address the same market manipulation exploit via cycling |
| D5-005 (don.php missing rate limit) | addressed as new MED-073 |

---

## Summary Table

| ID | Sev | Title | File(s) | Batch |
|---|---|---|---|---|
| CRIT-001 | CRITICAL | inscrire() positional INSERTs broken after migrations | includes/player.php | A |
| CRIT-002 | CRITICAL | Migration runner silently ignores per-statement errors | migrations/migrate.php | A |
| CRIT-003 | CRITICAL | sujets/reponses.auteur column charset utf8mb4 (should be latin1) | migrations/0018 | A |
| CRIT-004 | CRITICAL | db_helpers.php whitelist has 7 columns that don't exist in DB | includes/db_helpers.php | A |
| HIGH-001 | HIGH | Account deletion executes before session token validation | deconnexion.php | B |
| HIGH-002 | HIGH | rateLimitCheck() return value ignored in marche.php | marche.php | B |
| HIGH-003 | HIGH | Market buy deducts stale pre-transaction price | marche.php | B |
| HIGH-004 | HIGH | don.php missing intval() after transformInt() — integer overflow | don.php | B |
| HIGH-005 | HIGH | War end unilateral — no check requesting alliance is declarer | allianceadmin.php | B |
| HIGH-006 | HIGH | actionsenvoi row not locked FOR UPDATE — double-delivery race | includes/game_actions.php | C |
| HIGH-007 | HIGH | MathJax CDN loaded without SRI hash | sujet.php | C |
| HIGH-008 | HIGH | bbcode.php incomplete LaTeX sanitizer (missing \def/\let/\gdef) | includes/bbcode.php | C |
| HIGH-009 | HIGH | Forum reply uses $_GET['id'] for POST action | sujet.php | C |
| HIGH-010 | HIGH | AJAX API responses injected via innerHTML | includes/layout.php | D |
| HIGH-011 | HIGH | $image param unescaped in CSS url() context | includes/ui_components.php | D |
| HIGH-012 | HIGH | Multiple item() params not HTML-escaped in attributes | includes/ui_components.php | D |
| HIGH-013 | HIGH | Espionage bypasses comeback shield protection | attaquer.php | D |
| HIGH-014 | HIGH | Old tutorial rewards bypass storage cap (no LEAST()) | includes/basicprivatehtml.php | E |
| HIGH-015 | HIGH | Old tutorial rewards lack transaction safety (double-grant) | includes/basicprivatehtml.php | E |
| HIGH-016 | HIGH | Season reset triggerable by any authenticated player | includes/game_actions.php (season) | E |
| HIGH-017 | HIGH | Email loop runs in user request after season reset (timeout) | season reset handler | E |
| HIGH-018 | HIGH | Timing-unsafe token comparison in deconnexion.php | deconnexion.php | F |
| HIGH-019 | HIGH | Admin session lacks DB-backed session token validation | admin/*.php | F |
| HIGH-020 | HIGH | session.use_only_cookies and use_trans_sid not set | includes/session_init.php | F |
| HIGH-021 | HIGH | CSRF token not rotated after use (replay risk) | includes/csrf.php | F |
| HIGH-022 | HIGH | coefDisparition() missing null guard on $donneesMedaille | includes/formulas.php | G |
| HIGH-023 | HIGH | Resource nodes generated on 20×20 post-reset map, never regenerated | includes/player.php | G |
| HIGH-024 | HIGH | Compound bonuses read at resolution time, not launch time | includes/combat.php | G |
| HIGH-025 | HIGH | Migration 0005-0012 ADD COLUMN lacks IF NOT EXISTS guard | migrations/0005-0012 | H |
| HIGH-026 | HIGH | Migration 0001 ADD INDEX lacks IF NOT EXISTS guard | migrations/0001 | H |
| HIGH-027 | HIGH | Migration 0016 DROP INDEX lacks IF EXISTS guard | migrations/0016 | H |
| HIGH-028 | HIGH | messages and sanctions tables still MyISAM (not rolled back in tx) | migrations | H |
| HIGH-029 | HIGH | Orphan cleanup queries commented out in 0018; FK will fail on live DB | migrations/0018 | H |
| HIGH-030 | HIGH | season_recap has no FK and no supprimerJoueur() cleanup | migrations/0029, includes/player.php | I |
| HIGH-031 | HIGH | Migration 0022 bulk UPDATE runs unconditionally — corrupts rankings | migrations/0022 | I |
| HIGH-032 | HIGH | prestige/migrations tables created without ENGINE/CHARSET | migrations/0007 | I |
| HIGH-033 | HIGH | Player login names injected into HTML email without htmlspecialchars | season email handler | I |
| HIGH-034 | HIGH | No HTTPS (blocked on DNS; track but skip implementation) | VPS | — |
| HIGH-035 | HIGH | Password change missing bcrypt 72-byte max length check | compte.php | J |
| HIGH-036 | HIGH | Alliance invitation not blocked for already-membered player | allianceadmin.php | J |
| HIGH-037 | HIGH | Accepting invitation does not clear other pending invitations | alliance.php | J |
| HIGH-038 | HIGH | hardcoded 4 classes in defender molecule update (should loop) | includes/combat.php | K |
| HIGH-039 | HIGH | MARKET_POINTS_MAX constant never enforced — trade volume uncapped in rankings | marche.php, classement.php | L |
| MED-001 | MED | revenuAtomeJavascript() averages node bonus (per-type needed) | includes/game_resources.php | L |
| MED-002 | MED | bilan.php energy breakdown missing node/compound/spec bonus stages | bilan.php | L |
| MED-003 | MED | Buy-sell cycling inflates trade volume (5% tax, ~1.95× trade pts) | marche.php | L |
| MED-004 | MED | augmenterBatiment() not self-transactional | includes/player.php | L |
| MED-005 | MED | PP donation threshold too low (> 0 instead of real threshold) | includes/prestige.php | M |
| MED-006 | MED | awardPrestigePoints() includes inactive players (x=-1000) | includes/prestige.php | M |
| MED-007 | MED | Rank calc inconsistency: leaderboard filters x=-1000, PP award does not | includes/prestige.php, classement.php | M |
| MED-008 | MED | nbMessages not reset across seasons | season reset | M |
| MED-009 | MED | description not reset across seasons (tutorial pre-completed) | season reset | N |
| MED-010 | MED | Profile image not reset across seasons | season reset | N |
| MED-011 | MED | Forum tables (sujets/reponses/statutforum) not cleared on season reset | season reset | N |
| MED-012 | MED | Phase 1 maintenance detection has no advisory lock (race) | season reset | N |
| MED-013 | MED | Maintenance does not block GET requests (only POST) | includes/basicprivatephp.php | O |
| MED-014 | MED | Season reset does not invalidate session tokens | season reset | O |
| MED-015 | MED | Admin maintenance toggle does not update debut timestamp | admin | O |
| MED-016 | MED | Admin reset does not clear maintenance flag | admin | O |
| MED-017 | MED | Failed season reset clears maintenance permanently | season reset | O |
| MED-018 | MED | No session_regenerate_id() on public pages before login | includes/basicpublicphp.php | P |
| MED-019 | MED | Registration email/login uniqueness TOCTOU-vulnerable | inscription.php | P |
| MED-020 | MED | hardcoded 4 classes in pillage calculation | includes/combat.php | P |
| MED-021 | MED | Floor-based kill calc wastes fractional damage | includes/combat.php | P |
| MED-022 | MED | Dispersee overkill asymmetry vs cascade | includes/combat.php | P |
| MED-023 | MED | Building damage RNG per class not per unit (swingy outcomes) | includes/combat.php | Q |
| MED-024 | MED | 10 duplicate constructions queries in combat (should be 2 FOR UPDATE) | includes/combat.php | Q |
| MED-025 | MED | Attacker vacation check missing in POST handler (TOCTOU) | attaquer.php | Q |
| MED-026 | MED | grade name not validated (length/chars/uniqueness) | allianceadmin.php | Q |
| MED-027 | MED | Kicked player invitations not cleaned (inconsistent vs leave) | allianceadmin.php | R |
| MED-028 | MED | No forum-level access control (any user posts anywhere) | listesujets.php | R |
| MED-029 | MED | Forum reply insert + message count not transactional | sujet.php | R |
| MED-030 | MED | Alliance broadcast sends messages without transaction | ecriremessage.php | R |
| MED-031 | MED | Admin broadcast hardcoded to username "Guortates" | messageCommun.php | R |
| MED-032 | MED | No length validation on admin broadcast message | messageCommun.php | R |
| MED-033 | MED | edit form action="" relies on attacker-controlled GET | editer.php | S |
| MED-034 | MED | Moderator edits have no audit trail | editer.php | S |
| MED-035 | MED | N+1: profile image query per reply in thread | sujet.php | S |
| MED-036 | MED | Ban check deletes expired sanctions on every page load (write in read) | sanction check | S |
| MED-037 | MED | Profile description update has no length limit | compte.php | S |
| MED-038 | MED | old tutorial stage 4 condition fragile/bypassable | includes/basicprivatehtml.php | T |
| MED-039 | MED | Tutorial mission 5 condition allows trivially gaming profile desc | tutoriel.php | T |
| MED-040 | MED | Sequential enforcement reads stale pre-transaction mission data | tutoriel.php | T |
| MED-041 | MED | Old mission rewards bypass storage cap | includes/basicprivatehtml.php | T |
| MED-042 | MED | Espionage beginner protection excludes veteran prestige extension | attaquer.php | T |
| MED-043 | MED | preg_match on integer triggers PHP 8.2 deprecation | attaquer.php | T |
| MED-044 | MED | No rate limiting or cooldown on espionage actions | attaquer.php | U |
| MED-045 | MED | N+1: 4 DB calls per loop iteration for iode catalyst bonus | includes/game_resources.php | U |
| MED-046 | MED | classement.php switch uses loose comparison | classement.php | U |
| MED-047 | MED | vacances table charset utf8 (original dump), never converted | migrations | U |
| MED-048 | MED | migrations/0035 PREPARE/EXECUTE dynamic SQL fragile with multi_query | migrations/0035 | U |
| MED-049 | MED | game_actions.php OR-condition WHERE prevents index use | includes/game_actions.php | V |
| MED-050 | MED | migrations/0018 SET NULL FK on deleted authors; NULL not handled in display | migrations/0018, sujet.php | V |
| MED-051 | MED | db_helpers.php ajouter() uses DOUBLE bind type for integer columns | includes/db_helpers.php | V |
| MED-052 | MED | resource_nodes table uses utf8mb4 (should be latin1, verify 0033 fixed) | migrations/0023, 0033 | V |
| MED-053 | MED | static node cache never invalidated after regeneration | includes/resource_nodes.php | V |
| MED-054 | MED | ajouter() deduction not guarded against negative balance in compounds | includes/compounds.php | W |
| MED-055 | MED | molecule.php condenseur level variables use implicit global init | molecule.php | W |
| MED-056 | MED | Recipe resource names not validated against nomsRes whitelist | includes/compounds.php | W |
| MED-057 | MED | Expired compounds accumulate until probabilistic GC fires | includes/compounds.php | W |
| MED-058 | MED | Alliance energy donations have no upper bound | includes/game_actions.php | W |
| MED-059 | MED | style.php nonce ineffective (CSP still has unsafe-inline for styles) | style.php, includes/layout.php | X |
| MED-060 | MED | copyright.php: loader.js and countdown.js loaded without nonce | copyright.php | X |
| MED-061 | MED | Player logins in JS array via htmlspecialchars instead of json_encode | copyright.php | X |
| MED-062 | MED | index.php news HTML sanitized by regex (bypassable) | index.php | X |
| MED-063 | MED | .htaccess .env not explicitly in extension blocklist | .htaccess | X |
| MED-064 | MED | CSRF token replay: token not rotated after use | includes/csrf.php | (see HIGH-021) |
| MED-065 | MED | duplicateur upgrade has no grade permission check | alliance.php | (see HIGH-036 batch) |
| MED-066 | MED | preg_match BBCode [joueur=] limit {3,16} too short (LOGIN_MAX_LENGTH=20) | includes/bbcode.php | Y |
| MED-067 | MED | Espionage mission detection uses fragile LIKE on report titles | tutoriel.php | Y |
| MED-068 | MED | Espionage resolution not wrapped in transaction | attaquer.php | Y |
| MED-069 | MED | XSS in attack pending list (defender login not htmlspecialchars'd) | attaquer.php | Y |
| MED-070 | MED | timeMolecule column not reset across seasons | season reset | Y |
| MED-071 | MED | moderation/sanctions tables not cleared on season reset | season reset | Y |
| MED-072 | MED | news table not cleared on season reset | season reset | Y |
| MED-073 | MED | don.php missing rate limit — unlimited donation spam possible | don.php | B |
| LOW-001 | LOW | CSP style-src unsafe-inline (D1-011 = D11-012) | includes/layout.php | Z |
| LOW-002 | LOW | .htaccess data/ and logs/ use legacy Apache 2.2 Deny syntax | data/.htaccess, logs/.htaccess | Z |
| LOW-003 | LOW | SQL errors logged with full query text | includes/database.php | Z |
| LOW-004 | LOW | Logger includes IP PII | includes/logger.php | Z |
| LOW-005 | LOW | Season email From domain typo "theverylittewar" (missing 'l') | season email handler | Z |
| LOW-006 | LOW | Email regex overly restrictive for TLD matching | season email / compte.php | Z |
| LOW-007 | LOW | Online tracking by IP not by login (NAT issues) | connectes.php | AA |
| LOW-008 | LOW | Idle timeout check after game state operations | includes/basicprivatephp.php | AA |
| LOW-009 | LOW | Rate limiter stale file cleanup probabilistic (acceptable) | includes/rate_limiter.php | AA |
| LOW-010 | LOW | Float precision on energy cost | attaquer.php | AA |
| LOW-011 | LOW | Draw condition asymmetry in combat | includes/combat.php | AA |
| LOW-012 | LOW | Espionage beginner protection not checked (informational) | attaquer.php | AA |
| LOW-013 | LOW | Resource/vault fetch without FOR UPDATE (espionage read) | includes/combat.php | AA |
| LOW-014 | LOW | Combat report reveals defender building HP | includes/combat.php | AA |
| LOW-015 | LOW | Variable-variable architecture fragility in combat | includes/combat.php | AA |
| LOW-016 | LOW | Streak PP not shown in PP counter | prestige.php | BB |
| LOW-017 | LOW | Leaderboard rank ties wrong page number | classement.php | BB |
| LOW-018 | LOW | Daily leaderboard hardcoded LIMIT 50 | classement.php | BB |
| LOW-019 | LOW | prestige varchar(50) vs 255 | prestige table | BB |
| LOW-020 | LOW | Prestige button disabled via fragile str_replace | prestige.php | BB |
| LOW-021 | LOW | Comeback storage cap not fully atomic (see HIGH-017 area) | includes/player.php | BB |
| LOW-022 | LOW | Long-running transaction in performSeasonEnd Phase 1 | season reset | BB |
| LOW-023 | LOW | victoires=0 reset may erase just-awarded VP | season reset | BB |
| LOW-024 | LOW | maintenance.php accessible without authentication | maintenance.php | BB |
| LOW-025 | LOW | Timing-unsafe token comparison (see HIGH-018 — fixed there) | deconnexion.php | — |
| LOW-026 | LOW | historique.php array index OOB on malformed archived data | historique.php | CC |
| LOW-027 | LOW | joueur.php totalPoints not cast to int | joueur.php | CC |
| LOW-028 | LOW | joueur.php player coordinates exposed to unauthenticated visitors | joueur.php | CC |
| LOW-029 | LOW | connectes.php exposes all last login timestamps | connectes.php | CC |
| LOW-030 | LOW | PM send success message never displayed (redirect before display) | ecriremessage.php | CC |
| LOW-031 | LOW | Recipient name case normalization inconsistency | ecriremessage.php | CC |
| LOW-032 | LOW | Delete-all-messages has no confirmation step | messages.php | CC |
| LOW-033 | LOW | Sent messages disappear when recipient deletes | messages.php | CC |
| LOW-034 | LOW | Espionage mission detection fragile LIKE pattern (dup of MED-067) | tutoriel.php | — |
| LOW-035 | LOW | Registration login normalization (ucfirst) not shown to user | inscription.php | DD |
| LOW-036 | LOW | Login streak daily PP non-milestone days (design verify) | includes/player.php | DD |
| LOW-037 | LOW | Tutorial missions string trailing semicolons | tutoriel.php | DD |
| LOW-038 | LOW | Old mission array OOB for legacy accounts | includes/basicprivatehtml.php | DD |
| LOW-039 | LOW | Prestige seed depends on inscrire() fix (fixed by CRIT-001) | migrations | — |
| LOW-040 | LOW | idx_totalPoints added twice (0015 + 0026) | migrations | DD |
| LOW-041 | LOW | idclasse VARCHAR fix duplicated (0015 + 0019) | migrations | DD |
| LOW-042 | LOW | prestige.login widened to 255 twice (0015 + 0022) | migrations | DD |
| LOW-043 | LOW | membre.login index created non-unique then dropped/recreated | migrations | DD |
| LOW-044 | LOW | Periodic cleanup embedded in one-time migration (0013) | migrations/0013 | DD |
| LOW-045 | LOW | revenuAtome() re-queries constructions already in initPlayer() | includes/game_resources.php | EE |
| LOW-046 | LOW | revenuEnergie() queries autre twice for same player | includes/formulas.php | EE |
| LOW-047 | LOW | Inconsistent GC probability for compounds (5% vs 1%) | includes/compounds.php | EE |
| LOW-048 | LOW | $img undefined if all atom counts are 0 in molecule display | molecule.php | EE |
| LOW-049 | LOW | Merchant transfers ignore speed_boost compound | includes/game_actions.php | EE |
| LOW-050 | LOW | Countdown "(heure de Paris)" label misleading (UTC timestamps) | js/countdown.js | EE |
| LOW-051 | LOW | chip() color parameters unescaped | includes/ui_components.php | EE |
| LOW-052 | LOW | item() style parameter unescaped | includes/ui_components.php | EE |
| LOW-053 | LOW | Duplicate season-countdown element IDs (navbar + index body) | index.php, copyright.php | EE |
| LOW-054 | LOW | Missing security response headers (X-Content-Type-Options, etc.) | includes/layout.php | FF |
| LOW-055 | LOW | Password fields missing autocomplete attributes | inscription.php, compte.php | FF |
| LOW-056 | LOW | Dead partner bar JS (charger_barre.js) with jQuery 1.7.1 | images/partenariat/ | FF |
| LOW-057 | LOW | Implicit global variable `lettre` in JS name generator | copyright.php | FF |

---

## Batch Definitions

---

### Batch A — CRITICAL: Registration & Migration Infrastructure
**Files:** `includes/player.php`, `migrations/migrate.php`, `migrations/0018_*.sql`, `includes/db_helpers.php`

#### CRIT-001 — inscrire() positional INSERTs broken after migrations
**File:** `includes/player.php:51-59`
**Problem:** `inscrire()` uses positional `VALUES (?,?,?,?,...)` for all 4 INSERT statements (membre, autre, constructions, molecules). After >15 migrations have added columns, the positional count no longer matches. Registration is BROKEN on any live DB that has been through full migrations.
**Fix:** Rewrite all 4 INSERT statements to use explicit column-name lists:
- `INSERT INTO membre (login, password, email, blason, dernierVisite, session_token) VALUES (?,?,?,?,NOW(),?)`
- `INSERT INTO autre (login, energie, ...) VALUES (?,?,...)` — list every column with default
- Similarly for constructions and molecules
Use `dbExecute()` with named columns. Run `DESCRIBE membre` on VPS to get current column list before writing.

> ⚠️ IMPLEMENTER: Before writing the new INSERT statements, run `DESCRIBE membre; DESCRIBE autre; DESCRIBE ressources; DESCRIBE constructions; DESCRIBE molecules;` on the live VPS (ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111) to get the current authoritative column list. The sample column list in this plan is ILLUSTRATIVE ONLY and must not be used verbatim. The `membre` table has additional columns including `timestamp`, `ip`, `x`, `y`, `troll`, `derniereConnexion`, `vacance`, `moderateur`, `codeur` that are not shown in the examples above. The `constructions` table has columns added by migrations 0005 (`coffrefort`), 0006 (`formation`), 0011 (`spec_combat`, `spec_economy`, `spec_research`), and 0025 (`vieIonisateur`) — the explicit INSERT column list MUST enumerate ALL of these.

#### CRIT-002 — Migration runner silently ignores per-statement errors
**File:** `migrations/migrate.php:43-54`
**Problem:** The migration runner checks `mysqli_errno()` after each file and exits on error — however, DDL statements (ALTER TABLE) are auto-committed by MariaDB and cannot be rolled back even if an error is caught in the same file. The fix improves per-statement detection to catch errors earlier in the drain loop.
**Fix:** After each `$base->query($stmt)`, check `if ($base->errno !== 0)` and `throw new RuntimeException("Migration $file stmt failed: ".$base->error)`. Wrap the per-migration statement loop in a transaction where the engine allows it (DDL outside). Log the failing statement text.

#### CRIT-003 — sujets/reponses.auteur charset utf8mb4 breaks FK with membre.login (latin1)
**File:** `migrations/0018_add_foreign_keys.sql:19-20`
**Problem:** Migration 0018 modifies `sujets.auteur` and `reponses.auteur` to `utf8mb4`, but `membre.login` is `latin1`. MariaDB refuses a FK between mismatched charsets; the ALTER either silently fails or the FK is never created, leaving orphan rows unchecked.
**Fix:** Add a new migration (`0037_fix_auteur_charset.sql`) that alters both columns back to `latin1_swedish_ci` to match `membre.login`:
```sql
ALTER TABLE sujets MODIFY auteur VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL;
ALTER TABLE reponses MODIFY auteur VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL;
```
Then re-add the FK in the same migration.

> Note: The column width must be `VARCHAR(255)` to match `membre.login` which was already widened to 255 by migrations 0015/0022. Using `VARCHAR(20)` would cause FK creation to fail due to width mismatch.

#### CRIT-004 — db_helpers.php whitelist contains 7 columns that don't exist in DB
**File:** `includes/db_helpers.php:19-22`
**Problem:** The column whitelist for `updatePlayer()` / `ajouter()` includes `nbdefenses`, `nbespionnages`, `pointsPillage`, and 4 other columns that were never created by any migration. Every call that uses those column names silently returns false, causing stat update failures with no error.
**Fix:** Audit `DESCRIBE membre`, `DESCRIBE autre`, `DESCRIBE statistiques` on VPS. Remove every whitelist entry that has no matching column. Add a comment listing the columns and their source table.

---

### Batch B — HIGH: Critical Auth & Market Exploits
**Files:** `deconnexion.php`, `marche.php`, `don.php`, `allianceadmin.php`

#### HIGH-001 — Account deletion executes before session token validation
**File:** `deconnexion.php:7-10`
**Problem:** The `supprimer` action branch calls `supprimerJoueur()` before validating the session token against the DB. An attacker who knows a valid session cookie (e.g., XSS) can delete the account without DB-token confirmation.
**Fix:** Move `supprimerJoueur()` call to AFTER `verifierToken()` / session token DB check. Require a fresh CSRF token AND re-validate `session_token` against `membre.session_token` immediately before deletion.

#### HIGH-002 — rateLimitCheck() return value ignored in marche.php (rate limiting non-functional)
**File:** `marche.php:212,320`
**Problem:** `rateLimitCheck('market', ...)` is called but the return value is never checked. Market buy and sell are therefore unlimited and the rate limiter has zero effect.
**Fix:**
```php
if (!rateLimitCheck('market', $login, MARKET_RATE_LIMIT)) {
    // display error, exit
}
```
Apply to both the buy path (line ~212) and the sell path (line ~320).

#### HIGH-003 — Market buy deducts stale pre-transaction price
**File:** `marche.php:228,244,252`
**Problem:** The atom price is read before the `withTransaction()` block opens. Inside the transaction, the price may have changed (another buyer). The cost deducted from the buyer uses the old price, so the player can buy atoms at an outdated (lower) price.
**Fix:** Move the price SELECT inside the `withTransaction()` callback, after acquiring a `SELECT ... FOR UPDATE` lock on the atom row. Recompute cost from the freshly locked price before deducting.

#### HIGH-004 — don.php integer overflow via missing intval() after transformInt()
**File:** `don.php:10`
**Problem:** `transformInt()` returns a string (it strips non-digits). The result is never cast to `int`. PHP silently treats extremely large strings as float, allowing astronomically large donation amounts that overflow storage and bypass validation.
**Fix:** `$montant = (int) transformInt($_POST['montant']);` — explicitly cast. Then add a `MAX_DONATION` constant in `config.php` (e.g., 1,000,000) and reject donations above it.

#### HIGH-005 — War end unilateral: no check that requester is the war declarer
**File:** `allianceadmin.php:331-349`
**Problem:** The "end war" handler only checks that the player is an alliance chef. It does not verify that the requesting alliance is the one that declared the war. Any alliance chef can end any alliance's wars.
**Fix:** Add a WHERE clause check: `SELECT id FROM guerres WHERE (idA=? OR idB=?) AND declaredBy=?` before processing end. Only proceed if the current alliance was the declarer, or add a mutual-consent flow.

#### MED-073 — don.php missing rate limit — unlimited donation spam possible
**File:** `don.php` (POST handler entry point)
**Problem:** `rateLimitCheck()` is available in the codebase (used in `marche.php`, `inscription.php`, etc.) but `don.php` never calls it. A player can submit the donation form in rapid succession — spamming donations to cycle PP or trigger donation-based prestige rewards with no frequency enforcement. This was rated MEDIUM by the audit (finding D5-005).
**Fix:** At the very start of the POST handler in `don.php` (before any resource or PP reads), add:
```php
if (!rateLimitCheck('donation_' . $_SESSION['login'], 10, 3600)) {
    // 10 donations per hour max
    $erreur = "Trop de dons effectués. Veuillez attendre avant de réessayer.";
    // render error and exit — do NOT fall through to process the donation
    exit; // or equivalent page-render-and-exit pattern
}
```
Note: unlike `marche.php` (HIGH-002, where the return value was ignored), this fix MUST check the return value and abort processing on rate limit exceeded. The rate limit key is namespaced per-login to avoid cross-player collisions.

---

### Batch C — HIGH: Delivery Race & Forum Security
**Files:** `includes/game_actions.php`, `sujet.php`, `includes/bbcode.php`

#### HIGH-006 — actionsenvoi row not locked FOR UPDATE — double-delivery race condition
**File:** `includes/game_actions.php:528`
**Problem:** Transfer delivery reads `actionsenvoi` without `FOR UPDATE`. Two concurrent PHP processes handling the same delivery event can both find the row and both deliver the atoms. Items are duplicated.
**Fix:** Inside `withTransaction()`, use `SELECT ... FOR UPDATE` on the `actionsenvoi` row before reading the payload. After delivering, DELETE the row. If the row is missing (already delivered), abort cleanly.

#### HIGH-007 — MathJax CDN loaded without SRI hash
**File:** `sujet.php:252-253`
**Problem:** `<script src="https://cdn.jsdelivr.net/npm/mathjax@3/...">` has no `integrity` attribute. A CDN compromise or MITM can serve malicious JS executed in the game context.
**Fix:** Compute the SHA-384 of the exact MathJax bundle version in use and add `integrity="sha384-..."  crossorigin="anonymous"`. Pin to a specific version (not `latest`).

#### HIGH-008 — bbcode.php incomplete LaTeX sanitizer (missing \def, \let, \gdef)
**File:** `includes/bbcode.php:43-47`
**Problem:** The LaTeX/MathJax sanitizer strips some dangerous commands but misses `\def`, `\let`, `\gdef`, `\newcommand`, `\renewcommand`, and `\catcode`. These allow redefining control sequences to execute arbitrary content in MathJax's JS sandbox.
**Fix:** Extend the blocklist regex to include all LaTeX macro-definition commands:
```php
$blacklist = ['def','let','gdef','newcommand','renewcommand','catcode','input','include','csname','expandafter'];
```
Strip any `\cmd` occurrence where `cmd` is in the blocklist.

#### HIGH-009 — Forum reply uses $_GET['id'] for POST action (CSRF amplification)
**File:** `sujet.php:12-41`
**Problem:** The reply form's target topic ID is taken from `$_GET['id']` inside the POST handler. An attacker can craft a link like `sujet.php?id=99` embedded in an `<img>` or redirect to silently post a reply to topic 99 on behalf of the victim (CSRF amplified by GET parameter).
**Fix:** Move the topic ID to a hidden POST field in the form. In the POST handler, read `$_POST['sujet_id']` and validate it as a positive integer. Do not use `$_GET` in any write path.

---

### Batch D — HIGH: XSS / Injection in UI Layer
**Files:** `includes/layout.php`, `includes/ui_components.php`, `attaquer.php`

#### HIGH-010 — AJAX API responses injected into DOM via innerHTML
**File:** `includes/layout.php:138-191`
**Problem:** JavaScript code uses `element.innerHTML = responseText` to insert API responses. If any response contains HTML (e.g., player names, atom amounts), this is a stored XSS vector.
**Fix:** Replace all `innerHTML` assignments with `textContent` for plain text, or use structured JSON responses that populate individual DOM fields via `textContent`. Where HTML structure is required, build elements with `document.createElement`.

#### HIGH-011 — $image parameter unescaped in CSS url() context
**File:** `includes/ui_components.php:22`
**Problem:** `$image` is inserted directly into `style="background-image: url('$image')"` without encoding. An attacker who controls `$image` can inject CSS or break out of the attribute.
**Fix:** Apply `htmlspecialchars($image, ENT_QUOTES)` before interpolation. Additionally validate that `$image` matches an expected pattern (e.g., `^images/[a-z0-9_./]+$`).

#### HIGH-012 — Multiple item() parameters not HTML-escaped in attributes
**File:** `includes/ui_components.py:246,252,266,356`
**Problem:** Several `item()` calls pass `$titre`, `$sous_titre`, `$lien`, and `$extra` directly into HTML attribute contexts without `htmlspecialchars()`. Player-controlled data (e.g., alliance names, login) reaching these parameters is an XSS vector.
**Fix:** In `item()`, apply `htmlspecialchars($titre, ENT_QUOTES)`, `htmlspecialchars($lien, ENT_QUOTES)` etc. at the point of output, not at call sites (defense in depth).

#### HIGH-013 — Espionage bypasses comeback shield protection
**File:** `attaquer.php:20-74`
**Problem:** The comeback shield (`comeback_shield_until`) is checked for standard attacks but not for espionage actions. A shielded player can still be spied on, leaking their resource counts, army composition, and coordinates.
**Fix:** In the espionage branch (before any `SELECT` of target data), add:
```php
if ($cible['comeback_shield_until'] && strtotime($cible['comeback_shield_until']) > time()) {
    // reject espionage with "target is protected" message
}
```

---

### Batch E — HIGH: Tutorial Safety & Season Auth
**Files:** `includes/basicprivatehtml.php`, season reset handler

#### HIGH-014 — Old tutorial rewards bypass storage cap (no LEAST())
**File:** `includes/basicprivatehtml.php:60-209`
**Problem:** Old-style tutorial reward grants add atoms/energy to player totals with `UPDATE ... SET energie=energie+?` without capping at the storage maximum. Players can receive rewards that push resources above cap.
**Fix:** Use `LEAST(energie+?, placeEnergie)` pattern (same as the new tutorial system) for all energy grants. For atoms: `LEAST(atom_col+?, placeDepot())`.

#### HIGH-015 — Old tutorial rewards lack transaction safety (double-grant)
**File:** `includes/basicprivatehtml.php:60-209`
**Problem:** Reward grants happen outside a `withTransaction()` block. Two concurrent page loads at the same tutorial stage can both pass the "already granted?" check and both apply the reward.
**Fix:** Wrap the entire check-and-grant sequence in `withTransaction()`. Inside, `SELECT mission_status FOR UPDATE` before checking, then update in the same transaction.

#### HIGH-016 — Season reset triggerable by any authenticated player
**File:** Season reset handler (admin or game_actions.php)
**Problem:** The endpoint/action that calls `performSeasonEnd()` does not restrict to admin users. Any logged-in player who knows the URL/action can trigger a full season reset.
**Fix:** Add an admin-only gate at the top of the handler:
```php
if ($_SESSION['login'] !== ADMIN_LOGIN && !isAdmin($base, $_SESSION['login'])) {
    http_response_code(403); exit;
}
```
Add `ADMIN_LOGIN` to `config.php` if not present.

> ⚠️ IMPLEMENTER: Before implementing this fix, verify whether the `ADMIN_LOGIN` constant and `isAdmin()` function exist in `includes/config.php` and `includes/player.php`. If `isAdmin()` doesn't exist, use `$_SESSION['login'] === 'Guortates'` as a temporary gate (consistent with the admin pattern used elsewhere in the codebase), to be refactored when role-based auth is added.

#### HIGH-017 — Email loop runs in user request context after season reset (timeout risk)
**File:** Season reset handler
**Problem:** After `performSeasonEnd()` completes, the code iterates all player emails and sends winner notifications synchronously. With hundreds of players, this exceeds PHP `max_execution_time` (typically 30s), leaving the season partially reset with emails unsent.
**Fix:** Queue emails into an `email_queue` table during `performSeasonEnd()`. A cron job (or the existing log-rotation cron) processes the queue asynchronously, 10–20 emails per run, with exponential back-off on failure.

Create migration `0043_create_email_queue.sql` with:
```sql
CREATE TABLE IF NOT EXISTS email_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient_email VARCHAR(100) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html TEXT NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  sent_at INT UNSIGNED NULL,
  INDEX idx_unsent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
```

---

### Batch F — HIGH: Session & Token Hardening
**Files:** `includes/session_init.php`, `includes/csrf.php`, `deconnexion.php`, `admin/*.php`

#### HIGH-018 — Timing-unsafe token comparison in deconnexion.php
**File:** `deconnexion.php:15`
**Problem:** The logout CSRF token is compared with `!==` (string comparison), which is timing-unsafe. An attacker on the same network can exploit timing differences to brute-force the token.
**Fix:** In `deconnexion.php` line 15, replace `$tokenDb['session_token'] !== $_SESSION['session_token']` with `!hash_equals((string)$tokenDb['session_token'], (string)$_SESSION['session_token'])`. Note: `csrf.php` already uses `hash_equals` correctly for CSRF tokens — this fix targets the DB session_token comparison in the account deletion path specifically.

#### HIGH-019 — Admin session lacks DB-backed session token validation
**File:** `admin/*.php`
**Problem:** Admin pages validate only `$_SESSION['isAdmin']` (PHP session variable). If the session is hijacked (e.g., via session fixation), the PHP session variable is trusted without any DB check. Regular pages validate `session_token` against `membre.session_token`; admin pages must do the same.
**Fix:** In the admin auth guard (or a new `includes/admin_auth.php`), add:
```php
$row = dbFetchOne($base, "SELECT login FROM membre WHERE login=? AND session_token=?",
    [$_SESSION['login'], $_SESSION['session_token']]);
if (!$row) { session_destroy(); header('Location: login.php'); exit; }
```

#### HIGH-020 — session.use_only_cookies and use_trans_sid not set
**File:** `includes/session_init.php`
**Problem:** PHP defaults allow session IDs to travel via URL parameters if cookies fail. An attacker can inject a crafted URL with a known session ID (session fixation).
**Fix:** Before `session_start()`:
```php
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
ini_set('session.cookie_httponly', 1);
```

#### HIGH-021 — CSRF token not rotated after use (replay risk)
**File:** `includes/csrf.php`
**Problem:** The CSRF token is generated once per session and never regenerated after a successful POST. An attacker who captures a token (network sniff, browser history) can replay it indefinitely within the same session.
**Fix:** Inside `csrfVerify()` in `includes/csrf.php`, after `hash_equals()` returns true, add `$_SESSION['csrf_token'] = bin2hex(random_bytes(32));` to regenerate the token. The correct function names are: `csrfVerify()` and `csrfCheck()` for validation (in `includes/csrf.php`), and `csrfToken()` / `csrfField()` for rendering tokens in forms. All forms already call `csrfField()` which reads `$_SESSION['csrf_token']` live, so no form-side changes are needed after adding the regeneration line.

---

### Batch G — HIGH: Formula & Map Bugs
**Files:** `includes/formulas.php`, `includes/player.php`, `includes/combat.php`

#### HIGH-022 — coefDisparition() missing null guard on $donneesMedaille
**File:** `includes/formulas.php:236-240`
**Problem:** `coefDisparition()` calls `$donneesMedaille['stabilisateur']` without checking if the row exists. If a player has no medal record, this throws a PHP 8.2 null-offset notice that halts rendering.
**Fix:**
```php
$stabilisateurBonus = $donneesMedaille['stabilisateur'] ?? 0;
```
Apply the same null-coalescing pattern to every key access on `$donneesMedaille` in this function.

#### HIGH-023 — Resource nodes generated on 20×20 post-reset map, never regenerated as map grows
**File:** `includes/player.php:961-967`
**Problem:** `generateResourceNodes()` is called once during season reset with a 20×20 map boundary. As the game progresses and MAP_SIZE grows (or is config-set larger), the existing nodes remain clustered in the 20×20 area. New players placed outside that area get no node proximity bonus.
**Fix:** Change `generateResourceNodes()` to read `MAP_SIZE` from `config.php` at call time, not hardcode 20. Add a cron hook or admin action to regenerate nodes when map size increases. Alternatively, regenerate nodes at each season start with the correct configured map size.

#### HIGH-024 — Compound bonuses read at resolution time, not launch time (timing exploit)
**File:** `includes/combat.php:181-185`
**Problem:** When an attack is queued (travel time), compound bonuses (attack %, speed) are not recorded. At resolution, the system reads the current compound state. A player can activate a compound after launching an attack to retroactively benefit from it.
**Fix:** When queuing an attack in `attaquer.php`, snapshot the active compound modifiers and store them in the `actionsenvoi` row (add columns `compound_atk_bonus`, `compound_def_bonus`). In `combat.php` resolution, use the stored values instead of re-querying `player_compounds`.

---

### Batch H — HIGH: Migration Safety
**Files:** `migrations/0001*.sql`, `migrations/0005-0012*.sql`, `migrations/0016*.sql`, various

#### HIGH-025 — ADD COLUMN in migrations 0005-0012 lack IF NOT EXISTS guard
**Files:** `migrations/0005_*.sql` through `migrations/0012_*.sql`
**Problem:** All `ALTER TABLE ... ADD COLUMN` statements in these migrations fail fatally if the column already exists (e.g., re-run after partial failure). CRIT-002 fix (error detection) will now surface these as hard failures.
**Fix:** Wrap each ADD COLUMN in a stored procedure or use MariaDB's `IF NOT EXISTS` syntax (supported in MariaDB 10.0+): `ALTER TABLE t ADD COLUMN IF NOT EXISTS col_name ...`. Create migration `0038_idempotent_add_columns.sql` that re-runs these safely.

#### HIGH-026 — Migration 0001 ADD INDEX lacks IF NOT EXISTS guard
**File:** `migrations/0001_add_indexes.sql`
**Problem:** 22 `ALTER TABLE ... ADD INDEX` statements fail if the index already exists. Same re-run problem as HIGH-025.
**Fix:** These already-applied migrations (0001, 0016) cannot be re-run on the VPS. The idempotency improvement is documentation-only for disaster recovery scenarios. Add `IF NOT EXISTS` / `IF EXISTS` to the files as a comment/note but these changes won't affect the live VPS. For future fresh deployments, the updated files will provide idempotency. No new migration needed for the VPS.

#### HIGH-027 — Migration 0016 DROP INDEX lacks IF EXISTS guard
**File:** `migrations/0016_*.sql`
**Problem:** `ALTER TABLE ... DROP INDEX idx_name` fails if the index was already dropped or never existed.
**Fix:** These already-applied migrations (0001, 0016) cannot be re-run on the VPS. The idempotency improvement is documentation-only for disaster recovery scenarios. Add `IF NOT EXISTS` / `IF EXISTS` to the files as a comment/note but these changes won't affect the live VPS. For future fresh deployments, the updated files will provide idempotency. No new migration needed for the VPS.

#### HIGH-028 — messages and sanctions tables still MyISAM (not rolled back inside withTransaction)
**File:** `migrations/` (original schema)
**Problem:** MyISAM tables do not support transactions. Any `withTransaction()` block that writes to `messages` or `sanctions` will NOT be rolled back on error, leaving partial state.
**Fix:** Create migration `0039_convert_myisam_to_innodb.sql`:
```sql
ALTER TABLE messages ENGINE=InnoDB;
ALTER TABLE sanctions ENGINE=InnoDB;
```

#### HIGH-029 — Orphan cleanup queries commented out in 0018; FK addition will fail on live DB
**File:** `migrations/0018_add_foreign_keys.sql:57-71`
**Problem:** The migration has DELETE statements for orphan cleanup commented out. The subsequent FK additions fail on any DB with orphan rows (login references that no longer exist in membre).
**Fix:** Uncomment the cleanup DELETEs, or move them to a separate pre-migration `0040_cleanup_orphans.sql` that runs before the FK migration. Add `ON DELETE SET NULL` or `ON DELETE CASCADE` as appropriate per table. All `ADD CONSTRAINT ... FOREIGN KEY` statements in migration 0040 must use `ADD CONSTRAINT IF NOT EXISTS` syntax (supported in MariaDB 10.0+) to be idempotent, since some constraints may have already been partially applied in migration 0018 (which ran but had orphan row failures).

---

### Batch I — HIGH: Data Integrity
**Files:** `includes/player.php`, `migrations/0022*.sql`, `migrations/0029*.sql`, `migrations/0007*.sql`, season email handler

#### HIGH-030 — season_recap has no FK and no supprimerJoueur() cleanup
**Files:** `migrations/0029_create_season_recap.sql`, `includes/player.php`
**Problem:** The `season_recap` table stores login-keyed rows but has no FK to `membre`. When a player is deleted via `supprimerJoueur()`, their recap rows remain, causing ghost data.
**Fix:** Add `FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE` to `season_recap` in a new migration. Add `DELETE FROM season_recap WHERE login=?` in `supprimerJoueur()` as a belt-and-suspenders guard.

#### HIGH-031 — Migration 0022 bulk UPDATE runs unconditionally — corrupts rankings if deployed before PHP
**File:** `migrations/0022_*.sql:15-23`
**Problem:** The migration runs an `UPDATE classement SET score = ...` recalculation unconditionally. If applied before the new PHP formulas are deployed, it overwrites rankings using the wrong formula version.
**Fix:** Gate the migration behind an application version check, or convert it to a stored procedure that checks `@@version_comment` for the expected schema version. As a minimum, add a comment instructing deployers to apply ONLY after PHP deploy, and wrap in `-- MANUAL STEP:` markers.

#### HIGH-032 — prestige and migrations tables created without ENGINE/CHARSET
**File:** `migrations/0007_add_prestige_table.sql`
**Problem:** `CREATE TABLE prestige (...)` has no `ENGINE=InnoDB` or `DEFAULT CHARSET=latin1`. MariaDB uses the server default, which may be utf8mb4/MyISAM depending on server config. This causes charset FK mismatches and potential MyISAM transaction issues.
**Fix:** RESOLVED: Migration 0033 (already applied) already performs this conversion via `CONVERT TO CHARACTER SET latin1` and `ENGINE=InnoDB`. Verify with `SHOW CREATE TABLE prestige` on VPS — expected result: ENGINE=InnoDB, DEFAULT CHARSET=latin1. No new migration needed. Mark as closed if confirmed.

#### HIGH-033 — Player login names injected into HTML email without htmlspecialchars
**File:** Season email handler
**Problem:** Winner/loser login names are concatenated directly into the HTML email body. A login like `<script>` or `O'Brien` could break the email HTML or cause display issues in HTML-capable email clients.
**Fix:** Wrap all login/alliance name interpolations in `htmlspecialchars($name, ENT_QUOTES, 'UTF-8')` before embedding in the HTML email template.

---

### Batch J — HIGH: Account & Alliance Validation
**Files:** `compte.php`, `allianceadmin.php`, `alliance.php`

#### HIGH-035 — Password change missing bcrypt 72-byte max length check
**File:** `compte.php`
**Problem:** bcrypt silently truncates passwords at 72 bytes. A user who sets a 100-byte password believes it is their full password, but only the first 72 bytes are stored. If they enter the full string at login, it works — but this creates a false security expectation and can break on platform changes.
**Fix:** Add validation: `if (strlen($_POST['newPassword']) > 72) { // error: password too long }`. Alternatively, pre-hash with SHA-256 before bcrypt (per standard workaround), but the simple cap is sufficient for this app.

#### HIGH-036 — Alliance invitation not blocked for already-membered player
**File:** `allianceadmin.php:331-349`
**Problem:** The invite handler does not check if the target player is already in an alliance. Sending an invitation to a membered player wastes quota and creates confusing UX when they accept (potentially joining while already in another alliance).
**Fix:** Before inserting the invitation, check `SELECT alliance FROM membre WHERE login=?`. If `alliance IS NOT NULL AND alliance != ''`, reject with an error message.

#### HIGH-037 — Accepting invitation does not clear other pending invitations
**File:** `alliance.php:202-213`
**Problem:** When a player accepts an invitation, they join an alliance but their other pending invitations remain in the `invitations` table. These stale records can be accepted later (if the player ever leaves) or clutter admin views.
**Fix:** After successful join, `DELETE FROM invitations WHERE login=?` (all invitations for that player, not just the accepted one).

---

### Batch K — HIGH/MEDIUM: Combat Hardcoding
**Files:** `includes/combat.php`

#### HIGH-038 — Hardcoded 4 classes in defender molecule update
**File:** `includes/combat.php:352-355`
**Problem:** The loop that applies molecule losses to the defender iterates `for ($i = 1; $i <= 4; $i++)` rather than using `$nbClasses` (already defined). If the game ever adds a 5th molecule class, this silently skips it.
**Fix:** Replace `4` with `$nbClasses` (or `NB_CLASSES` from config.php) in the loop bounds. Also fix the identical hardcoding at line 391-394 (pillage calculation) — treat as part of the same commit.

---

### Batch L — HIGH/MEDIUM: Economy Accuracy
**Files:** `includes/game_resources.php`, `bilan.php`, `marche.php`, `includes/player.php`, `classement.php`

#### HIGH-039 — MARKET_POINTS_MAX constant never enforced — trade volume uncapped in rankings
**File:** `marche.php` (trade volume increment), `classement.php` (ranking read)
**Problem:** `MARKET_POINTS_MAX` is defined in `config.php` but is never applied anywhere. The `tradeVolume` stat that feeds the market ranking in `classement.php` can grow without bound. Combined with the buy-sell cycling exploit (MED-003 / D5-004), a player can achieve an arbitrarily high market rank with no ceiling. This was rated HIGH by the audit (finding D3-002).
**Fix:** In `marche.php`, after the `tradeVolume` increment UPDATE, add a cap query:
```php
$totalTradeVolume = dbFetchOne($base, "SELECT tradeVolume FROM statistiques WHERE login=?", [$login])['tradeVolume'];
if ($totalTradeVolume > MARKET_POINTS_MAX) {
    dbExecute($base, "UPDATE statistiques SET tradeVolume=? WHERE login=?", [MARKET_POINTS_MAX, $login]);
}
```
Also add the cap as a defensive guard in `classement.php` where `tradeVolume` is read for display/ranking: `min((int)$row['tradeVolume'], MARKET_POINTS_MAX)`. Verify `MARKET_POINTS_MAX` is a reasonable value in `config.php` and document its rationale.

#### MED-001 — revenuAtomeJavascript() averages node bonus instead of per-atom-type
**File:** `includes/game_resources.php:151-163`
**Problem:** The JS-side revenue display averages the resource node bonus across all atom types rather than computing each atom's proximity bonus individually. The displayed per-atom income is inaccurate — players see wrong numbers.
**Fix:** Pass the per-type node bonus map (already computed in `revenuAtome()` for server-side) to the JS output as a JSON object. In the JS display, multiply each atom type's income by its own node multiplier.

#### MED-002 — bilan.php energy breakdown missing resource node, compound, and specialization bonus stages
**File:** `bilan.php:111-176`
**Problem:** The bonus summary page shows the base energy formula and building bonuses but omits: (1) resource node proximity bonus, (2) active compound energy modifier, (3) specialization energy multiplier. Players cannot see the full breakdown of their energy income.
**Fix:** Add three additional rows to the energy section in bilan.php, fetching the relevant data via `getResourceNodeBonus()`, `getActiveCompounds()`, and the player's `specialisation` value.

#### MED-003 — Buy-sell cycling inflates trade volume (~1.95× trade points per cycle)
**File:** `marche.php:411-414`
**Problem:** Selling atoms awards trade points based on energy gained (post-5% tax). Buying atoms also awards trade points. A player can cycle buy→sell repeatedly, paying only the 5% spread but earning nearly 2× trade points per atom per cycle. This inflates market leaderboard ranking.
**Fix:** Award sell trade points based on the energy-equivalent of atoms sold at the current price, MINUS the buy cost for those same atoms (net gain). Or cap total daily trade points at `MARKET_POINTS_MAX` (constant exists but is never enforced — see MED-003 note below). Enforcing the cap is the simpler fix.

#### MED-004 — augmenterBatiment() not self-transactional
**File:** `includes/player.php:508-548`
**Problem:** `augmenterBatiment()` deducts resources and increments the building level in separate DB calls with no transaction. A PHP crash or concurrent call between the two operations leaves the player resource-deducted but without the building upgrade.
**Fix:** Wrap the function body in `withTransaction($base, function() use (...) { ... })`. All callers already expect it to be atomic.

---

### Batch M — MEDIUM: Prestige/Ranking Consistency
**Files:** `includes/prestige.php`, `classement.php`

#### MED-005 — PP donation threshold too low (> 0 instead of meaningful floor)
**File:** `includes/prestige.php:100`
**Problem:** Any donation > 0 PP awards prestige to the recipient. This allows donating 1 PP to farm prestige interactions. The design intent is a meaningful threshold.
**Fix:** Add `PP_DONATION_MIN_THRESHOLD` to `config.php` (suggested: 10). In `awardPrestigePoints()` donation branch, check `if ($amount < PP_DONATION_MIN_THRESHOLD) return false;`.

#### MED-006 — awardPrestigePoints() includes inactive players (x=-1000) in PP calculation
**File:** `includes/prestige.php:113-132`
**Problem:** When computing the PP ranking (percentile-based awards), the query does not filter out inactive/banned players (x=-1000). Their presence deflates the percentile for active players.
**Fix:** Add `AND x != -1000` to the ranking SELECT in `awardPrestigePoints()`.

#### MED-007 — Rank calculation inconsistency between leaderboard and PP award
**File:** `includes/prestige.php`, `classement.php`
**Problem:** `classement.php` WHERE clause filters `x != -1000` but `awardPrestigePoints()` does not (MED-006). Additionally, the sqrt ranking formula in `classement.php` may not match the formula used in `awardPrestigePoints()`. Players see different relative rankings in the two contexts.
**Fix:** Extract the ranking WHERE clause into a shared constant or helper function `getActivePlayerCondition()`. Both `classement.php` and `awardPrestigePoints()` must use the same conditions and formula.

#### MED-008 — nbMessages not reset across seasons
**File:** Season reset handler
**Problem:** `autre.nbMessages` tracks unread message count. After a season reset where messages are cleared, nbMessages remains at the old count, showing phantom unread messages.
**Fix:** Add `nbMessages=0` to the season reset UPDATE on `autre`.

---

### Batch N — MEDIUM: Season Reset Completeness
**Files:** Season reset handler, relevant tables

#### MED-009 — description not reset across seasons (tutorial pre-completed for veterans)
**File:** Season reset handler
**Problem:** `membre.description` persists across seasons. New tutorial missions check if description is set to mark mission 5 complete. Veterans who set a description in a previous season start the new season with that mission already "done," bypassing tutorial flow and its rewards.
**Fix:** Add `description=''` (or `description=NULL`) to the member reset UPDATE. Also reset `autre.timeMolecule=0` (MED-070 below) in the same UPDATE.

#### MED-010 — Profile image not reset across seasons
**File:** Season reset handler
**Problem:** Profile images persist across resets. While not a game-breaking issue, it creates inconsistency when all other profile data is reset. It may also reference images uploaded in the old season's file path.
**Fix:** Add `blason=NULL` (or the default placeholder) to the member reset UPDATE. Optionally delete uploaded image files from disk.

#### MED-011 — Forum tables not cleared on season reset
**File:** Season reset handler
**Problem:** `sujets`, `reponses`, `statutforum` tables are not emptied at season end. Forum history carries over, which may be intentional (meta-community value) but contradicts "full reset" semantics. If retained, ensure cross-season references to player data don't break after player data is reset.
**Fix:** Decision required: if forum should reset, add `TRUNCATE TABLE sujets; TRUNCATE TABLE reponses; TRUNCATE TABLE statutforum;` to `performSeasonEnd()`. If forum is intentionally persistent, document the decision in a config comment and ensure NULL auteur display (MED-050) is handled.

#### MED-012 — Phase 1 maintenance detection has no advisory lock (race condition)
**File:** Season reset handler
**Problem:** Two admin requests or a poorly timed cron can both detect "maintenance mode" and both attempt to start Phase 1 of the reset simultaneously. The result is double-reset, corrupted data.
**Fix:** Use `SELECT GET_LOCK('season_reset', 0)` at the start of `performSeasonEnd()`. If it returns 0 (lock held), abort. Release with `SELECT RELEASE_LOCK('season_reset')` at the end or on error.

---

### Batch O — MEDIUM: Maintenance Mode & Session Security
**Files:** `includes/basicprivatephp.php`, season reset handler, admin panel

#### MED-013 — Maintenance mode does not block GET requests
**File:** `includes/basicprivatephp.php`
**Problem:** The maintenance check only intercepts POST requests. Players can still navigate, view pages, and trigger GET-based state changes during maintenance/season reset.
**Fix:** Remove the POST-only condition. Check maintenance mode for ALL requests (GET and POST), displaying a "server maintenance" page for non-admin users.

#### MED-014 — Season reset does not invalidate existing session tokens
**File:** Season reset handler
**Problem:** After a season reset, all player data (resources, buildings, molecules) is cleared, but `membre.session_token` is unchanged. Players with open browser tabs continue with valid session tokens and may see partially reset state or cause race conditions.
**Fix:** In `performSeasonEnd()`, after Phase 2 data reset, run:
```sql
UPDATE membre SET session_token = '' WHERE 1
```
This forces all players to log in again, receiving fresh session tokens and a clean game state.

#### MED-015 — Admin maintenance toggle does not update debut timestamp
**File:** Admin panel maintenance handler
**Problem:** When an admin manually toggles maintenance mode, the `debut` timestamp (used to track season start for duration calculations) is not updated. Season length calculations drift.
**Fix:** When setting maintenance ON, also `UPDATE config SET debut=NOW()` (or equivalent). When turning maintenance OFF (season live), update debut to the go-live time.

#### MED-016 — Admin reset does not clear maintenance flag
**File:** Admin panel reset handler
**Problem:** After triggering a season reset from the admin panel, the `maintenance` flag remains set. The game stays in maintenance forever unless an admin manually clears it.
**Fix:** At the END of `performSeasonEnd()` Phase 2 (after all data is reset and confirmed), set `maintenance=0` in the config table.

#### MED-017 — Failed season reset clears maintenance flag permanently
**File:** Season reset handler error path
**Problem:** The error/exception handler in `performSeasonEnd()` clears `maintenance=0` on failure, leaving the game live with a half-reset database.
**Fix:** On error, set maintenance=1 (keep maintenance mode ON) and log the error. Only clear maintenance when reset completes successfully. Send an admin alert email/log entry.

---

### Batch P — MEDIUM: Auth & Combat Edge Cases
**Files:** `includes/basicpublicphp.php`, `inscription.php`, `includes/combat.php`

#### MED-018 — No session_regenerate_id() before login on public pages
**File:** `includes/basicpublicphp.php`
**Problem:** The login handler does not call `session_regenerate_id(true)` after successful credential validation. The pre-login session ID is promoted to an authenticated session, enabling session fixation attacks.
**Fix:** Immediately after setting `$_SESSION['login']` and `$_SESSION['session_token']`, call `session_regenerate_id(true)`.

#### MED-019 — Registration email/login uniqueness TOCTOU-vulnerable
**File:** `inscription.php:37-48`
**Problem:** Registration checks email and login uniqueness with two separate SELECTs before the INSERT. Between the check and insert, another concurrent registration can claim the same login/email. The result is a duplicate (FK violation or silent truncation).
**Fix:** Add `UNIQUE` constraints on `membre.email` if not already present (check schema). Rely on the DB constraint as the authoritative uniqueness check, and handle the duplicate-key exception gracefully with a user-facing error message.

#### MED-020 — Hardcoded 4 classes in pillage calculation
**File:** `includes/combat.php:391-394`
**Problem:** Same issue as HIGH-038 — pillage loop hardcodes 4 classes.
**Fix:** Replace `4` with `$nbClasses` (fixed together with HIGH-038 in Batch K, but listed here for tracking — confirm the Batch K commit covers this line too).

#### MED-021 — Floor-based kill calculation wastes fractional damage
**File:** `includes/combat.php:205,249,271,285`
**Problem:** `floor($damage / $unitHP)` discards fractional kills. Over many rounds this systematically under-kills, making combat last longer than the math implies and slightly favoring large armies.
**Fix:** Use a probabilistic rounding: `$kills = intdiv($damage, $unitHP); $remainder = fmod($damage, $unitHP); if (lcg_value() < $remainder / $unitHP) $kills++;`

#### MED-022 — Dispersee overkill asymmetry vs cascade
**File:** `includes/combat.php`
**Problem:** When a Dispersee unit is killed, its overkill damage is not propagated to the next unit in the cascade (unlike Phalange). This creates an asymmetry where Dispersee armies are effectively more durable against concentrated attacks.
**Fix:** After Dispersee unit death, carry excess damage forward: `$remainingDamage = $totalDamage - $killed * $unitHP;` and apply to the next iteration.

---

### Batch Q — MEDIUM: Combat & Alliance Admin
**Files:** `includes/combat.php`, `attaquer.php`, `allianceadmin.php`

#### MED-023 — Building damage RNG per class not per unit (swingy outcomes)
**File:** `includes/combat.php:475-494`
**Problem:** Building damage rolls one random value per molecule class, then applies it to potentially hundreds of units. A single "all miss" roll wipes out expected building damage from an entire class. Outcomes are wildly variable.
**Fix:** Roll the building damage RNG per unit (or per N units with N small). This produces statistically smoother outcomes while retaining randomness.

#### MED-024 — 10 duplicate constructions queries in combat (2 with FOR UPDATE needed)
**File:** `includes/combat.php:28-59,145,372-380,450`
**Problem:** The combat function queries the `constructions` table up to 10 times for attacker and defender. These should be 2 `SELECT ... FOR UPDATE` queries (one per player) executed once at the start of the transaction, with results reused.
**Fix:** Hoist both construction fetches to the top of `resolverCombat()`, inside the transaction, with FOR UPDATE. Pass the results as parameters or use a shared array throughout the function.

#### MED-025 — Attacker vacation check missing in POST handler (TOCTOU)
**File:** `attaquer.php:77-249`
**Problem:** The GET handler checks if the attacker is on vacation before rendering the attack form. But the POST handler (which actually queues the attack) does not re-check. A player can enter vacation mode between viewing the form and submitting it, launching attacks while "on vacation."
**Fix:** Add the vacation check to the POST handler:
```php
if ($joueur['vacances'] == 1) { // error: cannot attack on vacation }
```

#### MED-026 — Grade name not validated (length, charset, uniqueness)
**File:** `allianceadmin.php:84-117`
**Problem:** Grade names accept any input with no length limit, no character restriction, and no uniqueness check. An admin can create two grades named "Officier" or one named `<script>alert(1)</script>`.
**Fix:** Validate: `strlen($name) <= ALLIANCE_GRADE_MAX_LENGTH` (add constant, suggest 20), `preg_match('/^[a-zA-Z0-9 _-]+$/', $name)`, and uniqueness check against existing grades for that alliance.

---

### Batch R — MEDIUM: Forum & Alliance Social
**Files:** `allianceadmin.php`, `listesujets.php`, `sujet.php`, `ecriremessage.php`, `messageCommun.php`

#### MED-027 — Kicked player invitations not cleaned
**File:** `allianceadmin.php:192-222`
**Problem:** When a player is kicked from an alliance, their pending outgoing invitations are not deleted. Inconsistent with voluntary leave (`alliance.php`) which does clean up. A kicked player can still accept a pending invitation to a new alliance immediately.
**Fix:** After kicking a player, `DELETE FROM invitations WHERE login=?`. Mirror the behavior in the voluntary leave path.

#### MED-028 — No forum-level access control
**File:** `listesujets.php:25-53`
**Problem:** Any authenticated player can create a topic in any forum category (including alliance-only forums). There is no check whether the user's alliance membership or grade grants them access to the target forum.
**Fix:** Add a forum access check: if the target forum is alliance-only (`statutforum.type='alliance'`), verify the posting player is in that alliance.

#### MED-029 — Forum reply insert + message count not transactional
**File:** `sujet.php:35-41`
**Problem:** Reply INSERT and `sujets.nbReponses++` UPDATE are two separate queries. A crash between them leaves a reply in the DB without updating the reply count, breaking topic display.
**Fix:** Wrap both in `withTransaction()`.

#### MED-030 — Alliance broadcast sends messages without transaction
**File:** `ecriremessage.php:27-31`
**Problem:** Sending a message to all alliance members loops and inserts one row per member outside a transaction. If the loop fails mid-way, some members get the message and others do not.
**Fix:** Wrap the entire broadcast loop in `withTransaction()`.

#### MED-031 — Admin broadcast hardcoded to username "Guortates"
**File:** `messageCommun.php:10`
**Problem:** The global admin broadcast is restricted to `$_SESSION['login'] === 'Guortates'`. If the admin account is renamed or a second admin is added, they cannot use this feature.
**Fix:** Replace with `isAdmin($base, $_SESSION['login'])` check (or `$_SESSION['login'] === ADMIN_LOGIN` using the config constant).

#### MED-032 — No length validation on admin broadcast message
**File:** `messageCommun.php:42-48`
**Problem:** The admin broadcast body is inserted without any length check. An oversized message can overflow the `messages.texte` column (TEXT type, but a 4GB message is a DoS).
**Fix:** Add `if (mb_strlen($texte) > MESSAGE_MAX_LENGTH) { // error }`. `MESSAGE_MAX_LENGTH` should already be in config.php; if not, add it (suggest 5000).

---

### Batch S — MEDIUM: Forum UI & Moderation
**Files:** `editer.php`, `sujet.php`, `compte.php`, sanction check code

#### MED-033 — edit form action="" relies on attacker-controlled GET
**File:** `editer.php:50-95`
**Problem:** `<form action="">` causes the form to POST to the current URL, which includes the `?id=...&type=...` GET parameters. An attacker can craft a URL like `editer.php?id=99&type=sujet` to make the victim's browser submit an edit to message 99.
**Fix:** Set form `action` to a fixed path: `action="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')) ?>"`. Move all routing parameters to hidden POST fields.

#### MED-034 — Moderator edits have no audit trail
**File:** `editer.php:67-95`
**Problem:** When a moderator edits a post, no record is kept of what was changed, who changed it, or when. Players have no way to see that their post was modified.
**Fix:** Create a `moderation_log` table (migration) with columns: `id`, `moderateur`, `cible_login`, `type` (sujet/reponse), `cible_id`, `action`, `avant`, `apres`, `date`. Insert a log row in the editer.php handler before applying changes.

Create migration `0044_create_moderation_log.sql` with:
```sql
CREATE TABLE IF NOT EXISTS moderation_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  moderator_login VARCHAR(255) NOT NULL,
  target_post_id INT NOT NULL,
  post_type ENUM('sujet','reponse') NOT NULL DEFAULT 'reponse',
  original_content TEXT NOT NULL,
  new_content TEXT NOT NULL,
  action_at INT UNSIGNED NOT NULL,
  INDEX idx_moderator (moderator_login)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
```

#### MED-035 — N+1: profile image query per reply in thread
**File:** `sujet.php`
**Problem:** For each reply, a separate `SELECT blason FROM membre WHERE login=?` is executed. A thread with 50 replies makes 50 queries.
**Fix:** Collect all distinct author logins from the reply result set, then `SELECT login, blason FROM membre WHERE login IN (...)` once. Build a map and look up from it when rendering each reply.

#### MED-036 — Ban check deletes expired sanctions on every page load (write in read path)
**File:** Sanction check code (called from basicprivatephp.php)
**Problem:** The ban check runs `DELETE FROM sanctions WHERE fin < NOW()` on every authenticated page load. This is a write operation in the hot path of every request, creating unnecessary DB writes and table locks.
**Fix:** Move the cleanup to a cron job (daily or hourly). The ban check itself should only SELECT; the DELETE can happen asynchronously.

#### MED-037 — Profile description update has no length limit
**File:** `compte.php:112-118`
**Problem:** No server-side length validation on the description field. A malicious player can submit a megabyte-long description, filling the DB TEXT column.
**Fix:** Add `if (mb_strlen($description) > DESCRIPTION_MAX_LENGTH) { // error }`. Add `DESCRIPTION_MAX_LENGTH` to config.php (suggest 500). Also add `maxlength` attribute to the HTML textarea.

---

### Batch T — MEDIUM: Tutorial & Espionage Protection
**Files:** `includes/basicprivatehtml.php`, `tutoriel.php`, `attaquer.php`

#### MED-038 — Old tutorial stage 4 condition fragile/bypassable
**File:** `includes/basicprivatehtml.php:96-103`
**Problem:** Stage 4 completion is checked via a fragile PHP condition on player state that can be triggered without completing the intended action. Players can bypass the mission.
**Fix:** Audit the exact condition and align it with the new tutorial system's server-side mission completion check. Consider consolidating the old and new tutorial systems.

#### MED-039 — Tutorial mission 5 allows trivially gaming profile description
**File:** `tutoriel.php:105`
**Problem:** Mission 5 is "set your description." Any non-empty description completes it, including a single space character. Players do not engage with the feature.
**Fix:** Require `mb_strlen(trim($description)) >= 10` for mission completion.

#### MED-040 — Sequential enforcement reads stale pre-transaction mission data
**File:** `tutoriel.php:172-189`
**Problem:** The sequential enforcement check reads mission data from a prior SELECT (outside the completion transaction). A concurrent completion of the previous mission may not be visible, allowing out-of-order completion.
**Fix:** Inside the completion `withTransaction()`, re-SELECT mission state with FOR UPDATE before checking prerequisites.

#### MED-041 — Old mission rewards bypass storage cap (duplicate of HIGH-014 — verify both code paths)
**File:** `includes/basicprivatehtml.php:175-209`
**Problem:** Another code path in the same file grants rewards without LEAST() cap (distinct from HIGH-014 lines 60-209).
**Fix:** Same fix as HIGH-014 — apply LEAST() to all reward grants in this file, ensuring no code path is missed.

#### MED-042 — Espionage beginner protection excludes veteran prestige extension
**File:** `attaquer.php:32-34`
**Problem:** Beginner protection (first N days) does not account for the prestige-based extension that veteran players receive. A player with high prestige who starts a new season gets extra protection time, but the espionage check uses the base time only.
**Fix:** Apply the same prestige-extension formula to the espionage protection check as is used for combat protection.

#### MED-043 — preg_match on integer triggers PHP 8.2 deprecation
**File:** `attaquer.php:34`
**Problem:** `preg_match('/pattern/', $integerVar)` — passing an integer where a string is expected triggers a PHP 8.2 deprecation notice.
**Fix:** Cast: `preg_match('/pattern/', (string)$integerVar)`.

---

### Batch U — MEDIUM: Performance & DB Consistency
**Files:** `attaquer.php`, `includes/game_resources.php`, `classement.php`, `migrations/`

#### MED-044 — No rate limiting on espionage actions
**File:** `attaquer.php`
**Problem:** Espionage can be performed without any cooldown or rate limit. A player can spam espionage on the same target to build a complete intelligence profile in seconds.
**Fix:** Apply `rateLimitCheck('espionage', $login, ESPIONAGE_RATE_LIMIT)` at the top of the espionage POST handler. Add `ESPIONAGE_RATE_LIMIT` to config.php (suggest max 5 per minute).

#### MED-045 — N+1: 4 separate DB calls per loop iteration for iode catalyst bonus
**File:** `includes/game_resources.php`
**Problem:** For each player in a loop, 4 separate queries fetch iode-related catalyst data. With hundreds of players, this is thousands of queries.
**Fix:** Pre-fetch all iode catalyst data in one batched query outside the loop. Build a lookup map indexed by login.

#### MED-046 — classement.php switch uses loose comparison
**File:** `classement.php:18-44`
**Problem:** `switch ($_GET['clas'])` with loose comparison allows type juggling. `$_GET['clas'] = '0'` or `true`-like values may match unintended cases.
**Fix:** Replace `switch` with strict `match` (PHP 8.0+) or use `===` comparisons in if-elseif chains.

#### MED-047 — vacances table charset utf8 (original dump), never converted
**File:** Migrations
**Problem:** The `vacances` table uses `utf8` charset from the original schema dump, never updated to `latin1` or `utf8mb4`. Potential collation mismatch on JOINs with `membre`.
**Fix:** Migration: `ALTER TABLE vacances CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;`

#### MED-048 — migrations/0035 PREPARE/EXECUTE dynamic SQL fragile with multi_query
**File:** `migrations/0035_*.sql`
**Problem:** Dynamic SQL via PREPARE/EXECUTE inside a migration file processed by `multi_query` is fragile. PREPARE statements do not always work correctly in multi-statement mode and can leave prepared statement handles open.
**Fix:** Replace the PREPARE/EXECUTE/DEALLOCATE block with:
```sql
DROP PROCEDURE IF EXISTS tvlw_fix_0035;
CREATE PROCEDURE tvlw_fix_0035()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_NAME='grades' AND CONSTRAINT_TYPE='PRIMARY KEY') THEN
    ALTER TABLE grades ADD PRIMARY KEY (login, idalliance);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_NAME='statutforum' AND CONSTRAINT_TYPE='PRIMARY KEY') THEN
    ALTER TABLE statutforum ADD PRIMARY KEY (login, idsujet);
  END IF;
END;
CALL tvlw_fix_0035();
DROP PROCEDURE IF EXISTS tvlw_fix_0035;
```

---

### Batch V — MEDIUM: DB & Resource Nodes
**Files:** `includes/game_actions.php`, `migrations/0018`, `includes/db_helpers.php`, `includes/resource_nodes.php`, `migrations/0023`

#### MED-049 — game_actions.php OR-condition WHERE prevents index use
**File:** `includes/game_actions.php:90`
**Problem:** `WHERE login=? OR cible=?` prevents the query optimizer from using an index on either column efficiently. On large `actionsenvoi` tables this becomes a full scan.
**Fix:** Rewrite as `UNION ALL`: `(SELECT ... FROM actionsenvoi WHERE login=?) UNION ALL (SELECT ... FROM actionsenvoi WHERE cible=?)`.

#### MED-050 — SET NULL FK on deleted authors; NULL not handled in forum display
**File:** `migrations/0018`, `sujet.php`, `listesujets.php`
**Problem:** When a player is deleted, `sujets.auteur` is set to NULL via FK cascade. The forum display code does `echo $row['auteur']` without a NULL check, outputting blank or triggering a PHP notice.
**Fix:** In all forum display templates, replace `$row['auteur']` with `$row['auteur'] ?? '[supprimé]'`.

#### MED-051 — db_helpers.php ajouter() uses DOUBLE bind type for integer columns
**File:** `includes/db_helpers.php:34`
**Problem:** Integer atom/resource columns are bound as `d` (DOUBLE) instead of `i` (INTEGER) in prepared statements. This can cause precision issues with large integers (>2^53) and is semantically incorrect.
**Fix:** Pass integer values with `i` bind type. Since `ajouter()` may be used for both int and float columns, add an optional parameter or detect int vs float via `is_int()`.

#### MED-052 — resource_nodes table charset needs verification (utf8mb4 vs latin1)
**File:** `migrations/0023_create_resource_nodes.sql`, `migrations/0033_*.sql`
**Problem:** Migration 0023 creates `resource_nodes` in utf8mb4. Migration 0033 was supposed to fix this, but the fix needs verification. If the table is still utf8mb4, JOINs on login columns cause collation errors.
**Fix:** Check `SHOW CREATE TABLE resource_nodes` on VPS. If still utf8mb4, create migration `0042_fix_resource_nodes_charset.sql` with CONVERT TO latin1.

#### MED-053 — Static node cache never invalidated after regeneration
**File:** `includes/resource_nodes.php:91-98`
**Problem:** `getResourceNodes()` uses a PHP static variable cache. If `generateResourceNodes()` is called (on reset), the static cache in the same request still holds stale node data and returns it.
**Fix:** After `generateResourceNodes()` completes, unset the static cache variable. Alternatively, return the newly generated nodes from `generateResourceNodes()` and pass them directly instead of re-calling `getResourceNodes()`.

---

### Batch W — MEDIUM: Compounds & Donations
**Files:** `includes/compounds.php`, `molecule.php`, `includes/game_actions.php`

#### MED-054 — ajouter() deduction not guarded against negative balance in compounds
**File:** `includes/compounds.php:88`
**Problem:** When synthesizing a compound, `ajouter()` deducts resources without checking that the player has enough. A race condition (or direct crafting exploitation) can push atom counts below 0.
**Fix:** Use `GREATEST(atom_col - ?, 0)` in the UPDATE and check affected rows / the pre-deduction balance with FOR UPDATE to ensure sufficient resources exist.

#### MED-055 — molecule.php condenseur level variables use implicit global initialization
**File:** `molecule.php:40-48`
**Problem:** `$condenseurLevel` and similar variables are read from the request scope/globals without explicit initialization. If the preceding DB query returns no rows, these variables are undefined, triggering PHP notices and incorrect calculations.
**Fix:** Initialize all building-level variables to `0` before the DB fetch: `$condenseurLevel = 0;` etc. Then assign from DB results if present.

#### MED-056 — Recipe resource names not validated against nomsRes whitelist
**File:** `includes/compounds.php`
**Problem:** Compound recipe resource names are taken from a config array and passed to `ajouter()` as column names without validating against the `nomsRes` whitelist. A misconfigured recipe can reference a non-existent column, causing silent DB errors.
**Fix:** Before executing any compound recipe deduction, validate each resource key against `NOMS_RESSOURCES` (or equivalent config whitelist). Throw an exception if any key is unrecognized.

#### MED-057 — Expired compounds accumulate until probabilistic GC fires
**File:** `includes/compounds.php`
**Problem:** Expired compound rows stay in `player_compounds` until a 5% (or 1%) probability GC fires on a page load. A player with many expired compounds consumes DB storage and may experience stale reads if GC is delayed.
**Fix:** Add a daily cron job: `DELETE FROM player_compounds WHERE expires_at < NOW()`. Remove or reduce the probabilistic inline GC (it becomes a fallback only).

#### MED-058 — Alliance energy donations have no upper bound
**File:** `includes/game_actions.php`
**Problem:** The donation function accepts any energy amount. A player can donate all their energy (or more, if MED-054-style race applies) in a single transaction, destroying their own economy. No per-day cap exists.
**Fix:** Add `ALLIANCE_DONATION_MAX` to config.php (suggest 10,000 per day). Track donations in `autre.lastDonationDate` and `autre.donationToday`. Reset daily counter on new day; reject if `donationToday + amount > ALLIANCE_DONATION_MAX`.

---

### Batch X — MEDIUM: CSP & Client Security
**Files:** `style.php`, `copyright.php`, `index.php`, `.htaccess`

#### MED-059 — style.php nonce ineffective (CSP still has unsafe-inline for styles)
**File:** `style.php`, `includes/layout.php`
**Problem:** A nonce attribute is added to the `<link>` or `<style>` tag in style.php, but the CSP header still includes `style-src 'unsafe-inline'`. The nonce provides no security improvement when unsafe-inline is also present.
**Fix:** Remove `'unsafe-inline'` from `style-src` in the CSP header. Ensure all inline styles are moved to external stylesheets or use nonce attributes. Test that Framework7 CSS works without unsafe-inline.

#### MED-060 — copyright.php: loader.js and countdown.js loaded without nonce
**File:** `copyright.php`
**Problem:** External local JS files (`js/loader.js`, `js/countdown.js`) are included with `<script src="...">` but no `nonce="..."` attribute. The CSP `script-src 'nonce-...'` directive blocks them.
**Fix:** Add `nonce="<?= cspNonce() ?>"` to each `<script>` tag for locally served JS files.

#### MED-061 — Player logins injected into JS array via htmlspecialchars instead of json_encode
**File:** `copyright.php:51`
**Problem:** Player logins are output as `'<?= htmlspecialchars($login) ?>'` inside a JS string array. `htmlspecialchars` encodes `&<>"'` but does not handle JS special characters (backslash, newline, etc.). A login like `test\neval("evil")` would break the JS context.
**Fix:** Use `json_encode($logins)` to produce a properly JS-escaped array: `var players = <?= json_encode($logins, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;`

#### MED-062 — index.php news HTML sanitized with bypassable regex
**File:** `index.php:118-123`
**Problem:** A regex strips some HTML tags from news content, but regex-based HTML sanitization is well-known to be bypassable (nested tags, encoding tricks). News content from the DB is admin-entered but should still be safe.
**Fix:** Install or include HTML Purifier (or use `strip_tags` with an allowlist of `<b><i><u><br><p>` only). For admin-only content, a strict allowlist in `strip_tags` is sufficient.

#### MED-063 — .htaccess .env not explicitly in extension blocklist
**File:** `.htaccess`
**Problem:** The `.htaccess` denies access to common sensitive file extensions but does not explicitly list `.env`. If a `.env` file is accidentally created in the web root (e.g., by a deployment tool), it is publicly accessible.
**Fix:** Add `\.env$` to the `FilesMatch` deny block in `.htaccess`.

---

### Batch Y — MEDIUM: Espionage & Season Reset Cleanup
**Files:** `attaquer.php`, `tutoriel.php`, season reset handler

#### MED-066 — BBCode [joueur=] limit {3,16} too short (LOGIN_MAX_LENGTH=20)
**File:** `includes/bbcode.php`
**Problem:** The [joueur=] BBCode tag uses regex `{3,16}` to match login names. `LOGIN_MAX_LENGTH` is 20, so players with logins of 17-20 characters can never be mentioned via BBCode.
**Fix:** Change `{3,16}` to `{3,<?= LOGIN_MAX_LENGTH ?>}` — or hardcode `{3,20}` to match the constant. Verify the constant value matches.

#### MED-067 — Espionage mission detection uses fragile LIKE on report titles
**File:** `tutoriel.php`
**Problem:** Whether a player has completed an espionage mission is detected by `SELECT ... FROM rapports WHERE titre LIKE '%espionnage%'`. Report titles change with translation or content updates, breaking the detection.
**Fix:** Add a `type` column to `rapports` (migration) with an enum (`attack`, `espionage`, `defense`). Filter by `type='espionage'` in the tutorial check.

Create migration `0045_add_rapport_type.sql` with:
```sql
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS type ENUM('attack','espionage','defense') NOT NULL DEFAULT 'attack';
UPDATE rapports SET type='espionage' WHERE titre LIKE '%spionnage%' AND titre NOT LIKE 'Tentative%';
UPDATE rapports SET type='defense' WHERE destinataire != expeditaire AND type='attack';
```

#### MED-068 — Espionage resolution not wrapped in transaction
**File:** `attaquer.php`
**Problem:** The espionage action creates a report and updates player stats in separate queries outside a transaction. A crash between them leaves the report written but stats not updated (or vice versa).
**Fix:** Wrap the entire espionage resolution in `withTransaction()`.

#### MED-069 — XSS in attack pending list (defender login not htmlspecialchars'd)
**File:** `attaquer.php:277-279`
**Problem:** The "pending attacks" list renders the defender's login name without `htmlspecialchars()`. A login name containing `<` can break the page HTML.
**Fix:** `echo htmlspecialchars($attack['cible'], ENT_QUOTES, 'UTF-8');`

#### MED-070 — timeMolecule column not reset across seasons
**File:** Season reset handler
**Problem:** `autre.timeMolecule` (tracks last molecule conversion time) persists across resets. Players who converted a molecule right before season end start the new season with the cooldown still active.
**Fix:** Add `timeMolecule=0` to the season reset UPDATE on `autre`.

#### MED-071 — moderation/sanctions tables not cleared on season reset
**File:** Season reset handler
**Problem:** Active sanctions (bans) persist across seasons. A player banned in season N is still banned in season N+1, even if the new season is intended as a fresh start.
**Fix:** On season reset, delete expired sanctions and optionally all temporary sanctions: `DELETE FROM sanctions WHERE type='temp' OR fin < NOW()`. Keep permanent bans.

#### MED-072 — news table not cleared on season reset
**File:** Season reset handler
**Problem:** Season-specific news items (victory announcements, patch notes) carry over to the next season, cluttering the news feed.
**Fix:** `DELETE FROM news WHERE saison < currentSeason` (or `TRUNCATE TABLE news` if news is always per-season). Add a `saison` column to news if not present.

---

### Batch Z — LOW: Security Hardening (Group 1)
**Files:** `includes/layout.php`, `data/.htaccess`, `logs/.htaccess`, `includes/database.php`, `includes/logger.php`

#### LOW-001 — CSP style-src unsafe-inline (consolidated from D1-011/D11-012)
See MED-059 in Batch X — fixing MED-059 resolves this. Mark LOW-001 as resolved by MED-059.

#### LOW-002 — .htaccess data/ and logs/ use legacy Apache 2.2 Deny syntax
**Fix:** Replace `Order deny,allow` / `Deny from all` with `Require all denied` (Apache 2.4 syntax).

#### LOW-003 — SQL errors logged with full query text
**File:** `includes/database.php`
**Fix:** In the error handler, log only the error code and a truncated (first 100 chars) version of the query. Never log bound parameter values.

#### LOW-004 — Logger includes IP PII
**File:** `includes/logger.php`
**Fix:** Replace raw IP with `hash('sha256', $ip . SECRET_SALT)` before logging. Add `SECRET_SALT` to config.php.

#### LOW-005 — Season email From domain typo ("theverylittewar" missing 'l')
**Fix:** Correct to `"theverylittlewar.com"` in the email handler.

#### LOW-006 — Email regex overly restrictive for TLD matching
**Fix:** Use PHP's built-in `filter_var($email, FILTER_VALIDATE_EMAIL)` instead of a custom regex.

---

### Batch AA — LOW: Combat & Session Info
**Files:** `includes/combat.php`, `includes/basicprivatephp.php`, `connectes.php`

#### LOW-007 — Online tracking by IP not by login (NAT issues)
**Fix:** Change the "currently connected" query to use `login` (session-based) rather than IP. Keep IP as a secondary column.

#### LOW-008 — Idle timeout check occurs after game state operations
**Fix:** Move the idle timeout check to the TOP of the auth guard in `basicprivatephp.php`, before any game state reads.

#### LOW-010 — Float precision on energy cost
**Fix:** Round energy costs with `round($cost, 2)` before storing/comparing. Use integer arithmetic in cents where precision matters.

#### LOW-011 — Draw condition asymmetry in combat
**Fix:** On a draw (equal kills), neither side should pillage. Verify the code enforces this for both attacker and defender paths.

#### LOW-013 — Resource/vault fetch without FOR UPDATE in espionage
**Fix:** Add FOR UPDATE to the espionage target resource SELECT to prevent reads during concurrent writes.

#### LOW-014 — Combat report reveals defender building HP
**Fix:** Omit building HP values from the combat report shown to the attacker. Show only "building damaged" / "building destroyed" without exact HP values.

#### LOW-015 — Variable-variable architecture fragility in combat
**Fix:** Replace `$$varName` patterns with explicit array lookups: `$stats[$varName]` where $varName comes from an iterated whitelist.

---

### Batch BB — LOW: Prestige & Season Admin
**Files:** `prestige.php`, `classement.php`, `maintenance.php`, `includes/player.php`

#### LOW-016 — Streak PP not shown in PP counter
**Fix:** Add streak PP earned today to the PP breakdown display on `prestige.php`.

#### LOW-017 — Leaderboard rank ties produce wrong page numbers
**Fix:** Use `DENSE_RANK()` window function (MariaDB 10.2+) instead of `@rank` counter for tie-correct ranking.

#### LOW-018 — Daily leaderboard hardcoded LIMIT 50
**Fix:** Add `CLASSEMENT_PAGE_SIZE` to config.php (default 50). Use the constant.

#### LOW-019 — prestige.login column varchar(50) vs expected 255
**Fix:** ALREADY RESOLVED: Migrations 0015 and 0022 already widened `prestige.login` to VARCHAR(255). Verify with `SHOW CREATE TABLE prestige` on VPS. No implementation needed — mark as closed.

#### LOW-020 — Prestige button disabled via fragile str_replace
**Fix:** Replace the `str_replace('disabled','',...)` pattern with a proper PHP flag variable controlling the button's disabled attribute.

#### LOW-022 — Long-running transaction in performSeasonEnd Phase 1
**Fix:** Split Phase 1 into smaller batches. Process players in chunks of 100, each in their own transaction, to avoid long lock hold times.

#### LOW-023 — victoires=0 reset may erase just-awarded victory points
**Fix:** Document the design intent. If VP should survive reset as historical medals, use `medals` table only. If VP should reset, ensure VP is archived to `season_recap` before the reset UPDATE.

#### LOW-024 — maintenance.php accessible without authentication
**Fix:** Add an admin auth guard at the top of `maintenance.php`. At minimum, check `$_SESSION['isAdmin']` and the DB-backed session token.

---

### Batch CC — LOW: Social & Messaging UX
**Files:** `historique.php`, `joueur.php`, `connectes.php`, `ecriremessage.php`, `messages.php`

#### LOW-026 — historique.php array index OOB on malformed archived data
**Fix:** Wrap array accesses on archived data with `$data[$key] ?? null` checks. Skip rendering for rows with missing required fields.

#### LOW-027 — joueur.php totalPoints not cast to int
**Fix:** `$totalPoints = (int) $row['totalPoints'];`

#### LOW-028 — Player coordinates exposed to unauthenticated visitors
**Fix:** Only show coordinates on `joueur.php` if the viewing user is logged in. For public views, show a map icon without coordinates.

#### LOW-029 — connectes.php exposes all last login timestamps
**Fix:** For non-admin viewers, show only "last seen X minutes ago" (relative time) instead of the exact timestamp.

#### LOW-030 — PM send success message never displayed (redirect before display)
**Fix:** Store the success message in `$_SESSION['flash']` before redirect. Display it on the redirected page.

#### LOW-031 — Recipient name case normalization inconsistency
**Fix:** Normalize all login lookups with `LOWER()` in the SQL query, or use the stored `ucfirst` form consistently.

#### LOW-032 — Delete-all-messages has no confirmation step
**Fix:** Add a JS `confirm()` dialog or a two-step confirmation form before executing the mass delete.

#### LOW-033 — Sent messages disappear when recipient deletes
**Fix:** Add a `deleted_by_sender` column (boolean) to the messages table. Show the message to the sender unless `deleted_by_sender=1`. Recipient delete sets `deleted_by_recipient=1`; only truly delete the row when both flags are set.

Create migration `0046_add_message_soft_delete.sql` with:
```sql
ALTER TABLE messages ADD COLUMN IF NOT EXISTS deleted_by_sender TINYINT NOT NULL DEFAULT 0;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS deleted_by_recipient TINYINT NOT NULL DEFAULT 0;
```
Note: HIGH-028 (migration 0039, converting messages to InnoDB) must run before this migration.

---

### Batch DD — LOW: Migration Cleanup & Tutorial Text
**Files:** `migrations/`, `inscription.php`, `includes/player.php`, `tutoriel.php`, `includes/basicprivatehtml.php`

#### LOW-035 — Registration login normalization (ucfirst) not communicated to user
**Fix:** Show the normalized login to the user on the confirmation screen: "Votre identifiant sera : {normalizedLogin}."

#### LOW-036 — Login streak daily PP for non-milestone days (design verify)
**Fix:** Confirm with game design whether daily PP (non-milestone) is intended. If not, remove the non-milestone daily grant from `awardStreakPP()`.

#### LOW-037 — Tutorial missions string trailing semicolons
**Fix:** Remove trailing semicolons from mission description strings in `tutoriel.php`.

#### LOW-038 — Old mission array OOB for legacy accounts
**Fix:** In `includes/basicprivatehtml.php`, guard mission array accesses with `isset($missions[$index])` before reading.

#### LOW-040 — idx_totalPoints added twice (0015 + 0026)
**Fix:** In migration 0026, add `ADD INDEX IF NOT EXISTS` to avoid the duplicate. Alternatively, note in 0026 that it is idempotent.

#### LOW-041 — idclasse VARCHAR fix duplicated (0015 + 0019)
**Fix:** In migration 0019, add `IF NOT EXISTS` / `IF col type changed` guard or mark as no-op with comment.

#### LOW-042 — prestige.login widened to 255 twice (0015 + 0022)
**Fix:** In migration 0022, wrap in `IF` check using stored procedure or add `MODIFY COLUMN IF EXISTS` pattern.

#### LOW-043 — membre.login index non-unique then unique across migrations
**Fix:** Document the sequence in migration comments. No code change needed; verify the final state on VPS with `SHOW INDEX FROM membre`.

#### LOW-044 — Periodic cleanup in one-time migration 0013
**Fix:** Extract the cleanup logic into a cron job script (`scripts/cleanup_old_data.php`). Remove from 0013 to keep migrations idempotent.

---

### Batch EE — LOW: Performance & UI Polish
**Files:** `includes/game_resources.php`, `includes/formulas.php`, `includes/compounds.php`, `molecule.php`, `includes/game_actions.php`, `js/countdown.js`, `includes/ui_components.php`, `index.php`

#### LOW-045 — revenuAtome() re-queries constructions already in initPlayer()
**Fix:** Pass the already-loaded constructions array into `revenuAtome()` as a parameter instead of re-querying.

#### LOW-046 — revenuEnergie() queries autre twice for same player
**Fix:** Cache the `autre` row in a local variable or pass it as a parameter.

#### LOW-047 — Inconsistent GC probability for compounds (5% vs 1%)
**Fix:** Standardize to one probability defined in config.php (`COMPOUND_GC_PROBABILITY = 0.05`). Remove the inline `1%` variant.

#### LOW-048 — $img undefined if all atom counts are 0 in molecule display
**Fix:** Initialize `$img = 'images/atomes/default.png'` before the conditional block.

#### LOW-049 — Merchant transfers ignore speed_boost compound
**Fix:** In `game_actions.php` transfer delivery, check active speed_boost compound and adjust travel time if present.

#### LOW-050 — Countdown "(heure de Paris)" label misleading
**Fix:** Change label to "UTC+1 (heure de Paris)" or use "heure locale du serveur" to avoid confusion.

#### LOW-051 — chip() color parameters unescaped
**Fix:** Apply `htmlspecialchars($color, ENT_QUOTES)` to color parameters in `chip()`.

#### LOW-052 — item() style parameter unescaped
**Fix:** Apply `htmlspecialchars($style, ENT_QUOTES)` to the style parameter in `item()`.

#### LOW-053 — Duplicate season-countdown element IDs
**Fix:** In `copyright.php` (navbar), rename the element ID to `season-countdown-navbar`. Update the countdown JS to target both IDs.

---

### Batch FF — LOW: Headers & Dead Files
**Files:** `includes/layout.php`, `inscription.php`, `compte.php`, `images/partenariat/`, `copyright.php`

#### LOW-054 — Missing security response headers
**Fix:** In `includes/layout.php` (or a central header-sending function), add:
```php
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
```

#### LOW-055 — Password fields missing autocomplete attributes
**Fix:** Add `autocomplete="new-password"` to registration password fields and `autocomplete="current-password"` to login fields.

#### LOW-056 — Dead partner bar JS with jQuery 1.7.1 (charger_barre.js)
**Fix:** Delete `images/partenariat/charger_barre.js` and any `<script>` tags that load it. Remove the partner bar HTML if no longer needed.

#### LOW-057 — Implicit global variable `lettre` in JS name generator
**Fix:** Add `var lettre;` (or `let lettre;`) declaration inside the function scope to prevent global namespace pollution.

---

## Implementation Order Summary

| Priority | Batches | Findings Count |
|---|---|---|
| CRITICAL | A | 4 |
| HIGH (auth/exploit) | B, C, D, E, F | 22 |
| HIGH (data integrity) | G, H, I, J, K, L | 18 |
| MEDIUM (economy/game) | L, M, N, O, P | 23 |
| MEDIUM (combat/social) | Q, R, S, T, U | 23 |
| MEDIUM (DB/CSP/security) | V, W, X, Y | 19 |
| LOW | Z, AA, BB, CC, DD, EE, FF | 57 |

**Total unique findings in this plan:** 173
**Estimated commits:** 25 (one per batch)
**Prerequisites:** Batch A (CRIT-001) must be deployed before any registration testing. Batch H must be deployed before re-running migrations on a fresh DB.
