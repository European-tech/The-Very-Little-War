# Ultra Audit Pass 1 — Domain 1: Security & Vulnerability

**Date:** 2026-03-04
**Pass:** 1 (Broad Scan)
**Subagents:** 5 (Auth/Session, Input/Injection, AuthZ/Access, Infra/Headers, Game Exploits)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 8 |
| MEDIUM | 18 |
| LOW | 24 |
| **Total** | **50** |

Note: Many previously known items (HTTPS/HSTS, cookie_secure) are blocked on DNS migration. Findings focus on actionable issues.

---

## Area 1: Authentication & Session Security

#### P1-D1-001: Admin/Moderation sessions bypass session_init.php hardening
- **Severity:** HIGH
- **Category:** Security
- **Location:** `admin/index.php:4-5`, `moderation/index.php:2-3`
- **Description:** Admin and moderation login pages call bare `session_start()` without `session_init.php`, so admin cookies lack HttpOnly, SameSite, strict mode.
- **Impact:** Admin session cookies accessible via XSS, vulnerable to CSRF-based session riding.
- **Fix:** Replace bare `session_start()` with `require_once('includes/session_init.php')` after `session_name()`.
- **Effort:** S

#### P1-D1-002: MD5 password comparison uses timing-unsafe === operator
- **Severity:** LOW
- **Category:** Security
- **Location:** `compte.php:50`
- **Description:** Legacy MD5 fallback uses `===` instead of `hash_equals()`. Login page correctly uses `hash_equals()`.
- **Impact:** Theoretical timing side-channel on password change flow.
- **Fix:** Change to `hash_equals(md5($currentPassword), $storedHash)`.
- **Effort:** XS

#### P1-D1-003: No admin session idle timeout
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `admin/redirectionmotdepasse.php:1-8`
- **Description:** Admin session only checks `$_SESSION['motdepasseadmin'] === true`. No IP binding, idle timeout, or activity check. Stolen cookie = unlimited admin access.
- **Impact:** Persistent admin access from stolen session cookie.
- **Fix:** Add IP binding, 30-min idle timeout, re-auth for destructive actions.
- **Effort:** S

#### P1-D1-004: Visitor account passwords are predictable (username = password)
- **Severity:** HIGH
- **Category:** Security
- **Location:** `comptetest.php:22-27`
- **Description:** Visitor accounts created with password = username (e.g., "Visiteur42"/"Visiteur42"). Sequential numbering makes all active visitors guessable.
- **Impact:** Any attacker can hijack active visitor sessions.
- **Fix:** Use `bin2hex(random_bytes(16))` as visitor password instead.
- **Effort:** XS

#### P1-D1-005: comptetest.php lacks password minimum length on conversion
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `comptetest.php:46-51`
- **Description:** Visitor-to-full account conversion allows 1-character passwords. Main registration enforces `PASSWORD_MIN_LENGTH`.
- **Impact:** Weak passwords on converted accounts.
- **Fix:** Add `mb_strlen($_POST['pass']) < PASSWORD_MIN_LENGTH` check.
- **Effort:** XS

#### P1-D1-006: Bcrypt cost factor is 10, recommended minimum is 12
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/player.php:44`, `basicpublicphp.php:54`, `compte.php:61`
- **Description:** All `password_hash()` calls use `PASSWORD_DEFAULT` (cost=10). OWASP recommends cost=12 minimum.
- **Impact:** 4x faster offline cracking if DB is breached.
- **Fix:** Define `PASSWORD_BCRYPT_COST = 12` in config.php, use consistently.
- **Effort:** S

#### P1-D1-007: Session Secure flag conditional on current request HTTPS
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/session_init.php:10`
- **Description:** `session.cookie_secure` is set conditionally on `$_SERVER['HTTPS']`. First HTTP request creates insecure cookie.
- **Impact:** Session cookies can be intercepted on HTTP before HTTPS redirect.
- **Fix:** Hardcode `1` once HTTPS deployed. Add `FORCE_SECURE_COOKIES` config constant.
- **Effort:** XS

#### P1-D1-008: Session ID not regenerated after password change
- **Severity:** LOW
- **Category:** Security
- **Location:** `compte.php:62-66`
- **Description:** Password change updates `session_token` in DB but doesn't call `session_regenerate_id(true)`. Old PHP session ID remains valid.
- **Impact:** Previously-stolen session ID retains access after password change.
- **Fix:** Add `session_regenerate_id(true)` after password update.
- **Effort:** XS

#### P1-D1-009: CSRF failure redirects to unvalidated HTTP_REFERER
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/csrf.php:41-42`
- **Description:** CSRF failure on non-AJAX requests redirects to `$_SERVER['HTTP_REFERER']` which is attacker-controlled.
- **Impact:** Open redirect vulnerability — can redirect to phishing sites.
- **Fix:** Replace with hardcoded `index.php` fallback.
- **Effort:** XS

#### P1-D1-010: Rate limiting is IP-only, no per-username limiting
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/rate_limiter.php:11`, `basicpublicphp.php:26`
- **Description:** Login rate limit tied to IP only. Distributed attacks bypass it. Shared IPs lock out legitimate users.
- **Impact:** Brute-force from multiple IPs undetected.
- **Fix:** Add per-username limiting (20 attempts/15 min regardless of IP).
- **Effort:** S

#### P1-D1-011: comptetest.php visitor creation via GET without CSRF
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `comptetest.php:9`
- **Description:** Visitor accounts created via GET `?inscription`. GET state-changing ops can't have CSRF protection. Rate limiting exists (3/5min/IP).
- **Impact:** Cross-site visitor creation, resource exhaustion.
- **Fix:** Convert to POST with CSRF, or add confirmation step.
- **Effort:** S

#### P1-D1-012: Email validation inconsistency across registration paths
- **Severity:** LOW
- **Category:** Security
- **Location:** `inscription.php:34` vs `comptetest.php:52` vs `compte.php:78`
- **Description:** Three different email validation methods. Main uses `filter_var`, others use restrictive regex rejecting valid emails.
- **Impact:** Users with newer TLDs or plus-addressing can't update email.
- **Fix:** Use `validateEmail()` everywhere.
- **Effort:** XS

#### P1-D1-013: Rate limit check before CSRF validation in inscription.php
- **Severity:** LOW
- **Category:** Security
- **Location:** `inscription.php:7-15`
- **Description:** Rate limit counted before CSRF check — attacker can exhaust rate limit with invalid CSRF tokens.
- **Impact:** Rate limit DoS against legitimate users' IPs.
- **Fix:** Move `csrfCheck()` before `rateLimitCheck()`.
- **Effort:** XS

#### P1-D1-014: Visitor cleanup on every login attempt (DoS vector)
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/basicpublicphp.php:37-40`
- **Description:** Every login attempt triggers stale visitor cleanup with expensive cascading deletes.
- **Impact:** DoS via rapid login attempts triggering expensive DB operations.
- **Fix:** Move cleanup to cron job or limit to once per time window.
- **Effort:** S

#### P1-D1-015: deconnexion.php deletion without full auth verification
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `deconnexion.php:6-9`
- **Description:** Account deletion proceeds with only session data check, no `basicprivatephp.php` session token validation.
- **Impact:** Stale session could trigger account deletion.
- **Fix:** Include `basicprivatephp.php` or verify session token before deletion logic.
- **Effort:** XS

#### P1-D1-016: Admin + moderator share same password hash
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `moderation/index.php:17`, `admin/index.php:18`
- **Description:** Both panels use `ADMIN_PASSWORD_HASH`. No privilege separation between moderators and full admins.
- **Impact:** Compromised moderator = full admin access.
- **Fix:** Define separate `MODERATION_PASSWORD_HASH`.
- **Effort:** S

---

## Area 2: Input Validation & Injection

#### P1-D1-017: News content stored as raw HTML, strip_tags allows XSS
- **Severity:** HIGH
- **Category:** Security
- **Location:** `admin/listenews.php:46-47`, `index.php:115-116`
- **Description:** Admin news stored without sanitization. Rendered with `strip_tags` with allowed tags (`<a>`, `<img>`, `<span>`, `<div>`). `strip_tags` doesn't strip attributes — `<img onerror=alert(1)>` passes through.
- **Impact:** Stored XSS on homepage affecting all players.
- **Fix:** Use HTML Purifier or `htmlspecialchars()` + BBCode instead of `strip_tags`.
- **Effort:** M

#### P1-D1-018: BBCode [latex] outputs raw content into MathJax context
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/bbcode.php:32`
- **Description:** `[latex]` tag passes content to `$$..$$` delimiters. MathJax can execute JS via `\href{javascript:...}`.
- **Impact:** XSS if MathJax loaded (sujet.php has MathJax script tag).
- **Fix:** Remove `[latex]` tag or add LaTeX command sanitizer.
- **Effort:** XS

#### P1-D1-019: rapports.php on-event stripping bypassable
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `rapports.php:31`
- **Description:** Uses `strip_tags` + regex to strip `on*` handlers. Bypassable via tabs between `on` and event name, or `style` attributes with `url()`.
- **Impact:** Stored XSS through combat reports if user data reaches report HTML.
- **Fix:** Use HTML Purifier or generate reports from escaped components only.
- **Effort:** M

#### P1-D1-020: BBCode [img] allows path traversal via ../
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/bbcode.php:24`
- **Description:** Server-absolute path regex allows `/../../../` sequences.
- **Impact:** Potential access to files above web root on misconfigured servers.
- **Fix:** Reject URLs containing `..`: `if (strpos($url, '..') !== false) return '[Image bloquée]';`
- **Effort:** XS

#### P1-D1-021: BBCode $javascript parameter unused (dead code)
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/bbcode.php:2`
- **Description:** Function accepts `$javascript` param that's never used.
- **Fix:** Remove unused parameter.
- **Effort:** XS

#### P1-D1-022: API intval() accepts negative numbers
- **Severity:** LOW
- **Category:** Security
- **Location:** `api.php:50-53`
- **Description:** Formula API parameters allow negatives, could produce NaN/INF.
- **Fix:** Clamp to non-negative: `max(0, intval($val))`.
- **Effort:** XS

#### P1-D1-023: Admin mass-delete by IP lacks IP format validation and confirmation
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `admin/index.php:33-37`
- **Description:** No `FILTER_VALIDATE_IP` check, no confirmation step. Shared NAT IP could delete many legitimate accounts.
- **Fix:** Add IP validation, two-step confirmation, action logging.
- **Effort:** S

#### P1-D1-024: md5(rand()) used for email MIME boundary
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/basicprivatephp.php:168`
- **Description:** `md5(rand())` is predictable. Should use CSPRNG.
- **Fix:** `bin2hex(random_bytes(16))`.
- **Effort:** XS

#### P1-D1-025: Rate limiter file-based read-modify-write race condition
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/rate_limiter.php:21-36`
- **Description:** `file_get_contents` not locked, only `file_put_contents` has `LOCK_EX`. Concurrent requests can bypass limit.
- **Fix:** Use `fopen()` + `LOCK_EX` for both read and write.
- **Effort:** S

#### P1-D1-026: database.php error_log leaks SQL query structure
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/database.php:14,61`
- **Description:** Logs full SQL query template on prepare failure.
- **Fix:** Log reference ID only; store full details in restricted debug log.
- **Effort:** XS

---

## Area 3: Authorization & Access Control

#### P1-D1-027: Forum hide/show missing moderator auth check
- **Severity:** HIGH
- **Category:** Security
- **Location:** `editer.php:34-46`
- **Description:** Hide (type=5) and show (type=4) execute without verifying user is moderator. `$moderateur` fetched but never checked. Any player can hide/show any post.
- **Impact:** Any player can censor any forum post or restore hidden posts.
- **Fix:** Add `if ($moderateur['moderateur'] != 1) { $erreur = "Accès refusé."; }` guard.
- **Effort:** XS

#### P1-D1-028: ecriremessage.php message IDOR (information leak)
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `ecriremessage.php:46-62`
- **Description:** Any message fetched by ID before ownership check. Content cleared on mismatch but existence confirmed.
- **Fix:** Add `AND (destinataire = ? OR expeditaire = ?)` to WHERE clause.
- **Effort:** XS

#### P1-D1-029: Mass message bypass via hardcoded username check
- **Severity:** HIGH
- **Category:** Security
- **Location:** `ecriremessage.php:20`
- **Description:** `$_POST['destinataire'] == "[all]" && $_SESSION['login'] == "Guortates"` — only username check, no admin auth. If username re-registered, mass messaging is gained.
- **Impact:** Mass spam to all players. No rate limiting on mass-send loop.
- **Fix:** Remove `[all]` special case. Fix `messageCommun.php` auth for admin mass messaging.
- **Effort:** S

#### P1-D1-030: Alliance admin can ban the chef (leader)
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `allianceadmin.php:169-186`
- **Description:** Ban action doesn't check if target is alliance leader. Graded member can ban chef, orphaning the alliance.
- **Fix:** Add `if ($_POST['bannirpersonne'] == $chef['chef']) { $erreur = "..."; }` guard.
- **Effort:** XS

#### P1-D1-031: Vacation mode bypass via JavaScript-only redirect
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/redirectionVacance.php:6-8`
- **Description:** Uses client-side `window.location` redirect. Disabling JS or using cURL bypasses vacation restriction entirely.
- **Impact:** Players on vacation can attack/build while maintaining protection.
- **Fix:** Replace with `header('Location: vacance.php'); exit();`.
- **Effort:** XS

#### P1-D1-032: Espionage bypasses beginner protection and vacation mode
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `attaquer.php:20-51`
- **Description:** Attack checks beginner protection and vacation mode, but espionage code path checks neither. Protected/vacation players can be spied on.
- **Fix:** Add vacation and beginner protection checks to espionage block.
- **Effort:** XS

#### P1-D1-033: voter.php accepts state-changing GET requests
- **Severity:** LOW
- **Category:** Security
- **Location:** `voter.php:24-26`
- **Description:** Legacy GET support for votes without CSRF. Comment says "will be removed."
- **Fix:** Remove GET support, POST-only with CSRF.
- **Effort:** XS

#### P1-D1-034: MathJax script tag without nonce or SRI
- **Severity:** LOW
- **Category:** Security
- **Location:** `sujet.php:220`
- **Description:** External MathJax CDN script without nonce (blocked by CSP) or SRI hash.
- **Fix:** Add nonce + SRI, or remove if not needed.
- **Effort:** XS

---

## Area 4: Infrastructure & Headers

#### P1-D1-035: health.php exposes PHP version and disk space
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `health.php:23-29`
- **Description:** Unauthenticated endpoint returns `PHP_VERSION` and `disk_free_space()`.
- **Fix:** Strip sensitive details from public response; add IP allowlist or bearer token.
- **Effort:** XS

#### P1-D1-036: vendor/ directory not protected from web access
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `.htaccess` (missing rule)
- **Description:** No `.htaccess` deny in vendor/. PHPUnit and dependencies are web-accessible.
- **Fix:** Add `vendor/.htaccess` with `Require all denied`.
- **Effort:** XS

#### P1-D1-037: PHPUnit dev dependencies present in production
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `composer.json:8`, `vendor/phpunit/`
- **Description:** Full PHPUnit with code execution capabilities in prod. Combined with P1-D1-036.
- **Fix:** Run `composer install --no-dev` on VPS.
- **Effort:** XS

#### P1-D1-038: CSP allows style-src unsafe-inline
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/layout.php:3`
- **Description:** `style-src 'self' 'unsafe-inline'` allows CSS injection/exfiltration via attribute selectors.
- **Fix:** Migrate inline styles to external sheets or nonce-based.
- **Effort:** L

#### P1-D1-039: No Permissions-Policy header
- **Severity:** LOW
- **Category:** Security
- **Location:** `.htaccess`
- **Description:** No restriction on browser APIs (camera, mic, geolocation).
- **Fix:** Add `Permissions-Policy: camera=(), microphone=(), geolocation=()` etc.
- **Effort:** XS

#### P1-D1-040: HSTS not configured (pre-HTTPS readiness)
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `.htaccess`
- **Description:** No HSTS header prepared. Users vulnerable to SSL stripping when HTTPS goes live.
- **Fix:** Prepare HSTS header in `.htaccess`, commented out, ready for HTTPS.
- **Effort:** XS

#### P1-D1-041: X-XSS-Protection header deprecated and potentially harmful
- **Severity:** LOW
- **Category:** Security
- **Location:** `.htaccess:5`
- **Description:** `X-XSS-Protection: 1; mode=block` is deprecated, removed from Chrome 78+. Could introduce XSS in old IE via selective blocking.
- **Fix:** Change to `0` or remove entirely. Nonce CSP provides superior protection.
- **Effort:** XS

#### P1-D1-042: logs/.htaccess and data/.htaccess use Apache 2.2 syntax only
- **Severity:** LOW
- **Category:** Security
- **Location:** `logs/.htaccess:1`, `data/.htaccess:1-2`
- **Description:** `Deny from all` requires `mod_access_compat` on Apache 2.4. If not loaded, silently ignored.
- **Fix:** Use dual-syntax `IfModule` pattern like root `.htaccess`.
- **Effort:** XS

#### P1-D1-043: CSP header sent after HTML output in deconnexion.php
- **Severity:** LOW
- **Category:** Security
- **Location:** `deconnexion.php:29-36`
- **Description:** HTML output before `header()`. Relies on output buffering. If buffer disabled, CSP silently dropped.
- **Fix:** Move CSP header before `<!DOCTYPE html>`.
- **Effort:** XS

#### P1-D1-044: CSP missing explicit object-src and connect-src
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/layout.php:3`
- **Description:** Falls back to `default-src 'self'` but best practice is explicit `object-src 'none'; connect-src 'self'`.
- **Fix:** Add explicit directives.
- **Effort:** XS

#### P1-D1-045: Log injection via unsanitized user data
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/logger.php:19-33`
- **Description:** User-controlled login names and messages written to logs without newline/control char sanitization.
- **Fix:** Strip `\n`, `\r`, control chars from log inputs.
- **Effort:** S

---

## Area 5: Game-Specific Exploits

#### P1-D1-046: Espionage neutrino double-spend race condition
- **Severity:** HIGH
- **Category:** Security
- **Location:** `attaquer.php:26-40`
- **Description:** No transaction/lock on neutrino read-check-deduct. Concurrent espionage requests both read same balance. Uses stale value for UPDATE instead of atomic `neutrinos = neutrinos - ?`.
- **Impact:** Double-spend neutrinos — use more than owned.
- **Fix:** Wrap in `withTransaction()` with `FOR UPDATE` lock, or use atomic `UPDATE...WHERE neutrinos >= ?`.
- **Effort:** S

#### P1-D1-047: Resource transfer sender deduction lacks transaction
- **Severity:** HIGH
- **Category:** Security
- **Location:** `marche.php:108-127`
- **Description:** Resource send: check, INSERT, UPDATE all without transaction or lock. Two concurrent sends = overdraft + double resources to receiver.
- **Impact:** Resource duplication via race condition.
- **Fix:** Wrap in `withTransaction()` with `SELECT...FOR UPDATE` on sender resources.
- **Effort:** S

#### P1-D1-048: Resource delivery (envoi arrival) double-credit race
- **Severity:** HIGH
- **Category:** Security
- **Location:** `includes/game_actions.php:528-599`
- **Description:** DELETE + credit without transaction. Two concurrent requests process same envoi, crediting twice.
- **Impact:** Resource duplication on receipt.
- **Fix:** Wrap in transaction; check DELETE affected rows as CAS guard.
- **Effort:** M

#### P1-D1-049: Compound synthesis TOCTOU (resource check outside transaction)
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/compounds.php:49-91`
- **Description:** Resource read without FOR UPDATE before transaction. Concurrent synthesis = double-craft with negative resources.
- **Fix:** Move resource read inside transaction with FOR UPDATE.
- **Effort:** S

#### P1-D1-050: Market tradeVolume lost update (read-modify-write without lock)
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `marche.php:226-231`
- **Description:** `autre.tradeVolume` updated via stale read-modify-write. Lost updates corrupt ranking.
- **Fix:** Use atomic `UPDATE autre SET tradeVolume = tradeVolume + ?`.
- **Effort:** XS

#### P1-D1-051: Combat resolution lacks FOR UPDATE on resource/construction reads
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/combat.php:356-678`
- **Description:** Inside transaction, resource/construction rows read without FOR UPDATE. Concurrent market/build operations can produce stale calculations.
- **Fix:** Add `FOR UPDATE` to resource/construction SELECT statements.
- **Effort:** S

#### P1-D1-052: Multi-account detection trivially bypassable
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/multiaccount.php:22-34`
- **Description:** Detection based on IP + User-Agent hash only. VPN changes IP, different browser changes fingerprint.
- **Impact:** Alt accounts easily maintained.
- **Fix:** Add behavioral analysis, canvas fingerprinting, directional transfer tracking.
- **Effort:** L

#### P1-D1-053: Coordinated attack detection is post-hoc only
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `includes/multiaccount.php:112-155`, `game_actions.php:354`
- **Description:** `checkCoordinatedAttacks()` called after combat resolution. Creates alerts but damage already done.
- **Fix:** Move check to attack submission phase, block attacks between flagged pairs.
- **Effort:** S

#### P1-D1-054: Resource transfer IP check compares stored IPs, not current
- **Severity:** HIGH
- **Category:** Security
- **Location:** `marche.php:25-32`
- **Description:** Compares `membre.ip` (stored at last login) not current request IP. Login from different IP = bypass.
- **Fix:** Compare current `$_SERVER['REMOTE_ADDR']` + add daily transfer caps + track asymmetric patterns.
- **Effort:** M

#### P1-D1-055: Alliance donation bypasses multi-account transfer blocks
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `don.php:17-31`
- **Description:** No daily cap, no `areFlaggedAccounts` check. Alt accounts funnel energy through alliance donations.
- **Fix:** Add flagged account check + daily donation cap.
- **Effort:** S

#### P1-D1-056: Market buy-sell cycle generates free trade points
- **Severity:** MEDIUM
- **Category:** Security
- **Location:** `marche.php:224-231`, `marche.php:346-352`
- **Description:** Both buy and sell award trade points. 5% tax costs resources but generates ~1.95x trade points per cycle. Ranking manipulation.
- **Fix:** Award trade points on buys only, or cap points per time period.
- **Effort:** S

#### P1-D1-057: Combat integer overflow at extreme molecule counts
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/combat.php:171,177`
- **Description:** Damage calculations could exceed PHP float precision with billions of molecules.
- **Fix:** Add max molecule count cap, use `bcmath` for large-value calculations.
- **Effort:** S

#### P1-D1-058: Resource node bonus stacking uncapped
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/resource_nodes.php:97-108`
- **Description:** Multiple nodes of same type with overlapping radii stack without cap.
- **Fix:** Cap total node bonus per resource type.
- **Effort:** XS

#### P1-D1-059: CSRF token per-session, not rotated
- **Severity:** LOW
- **Category:** Security
- **Location:** `includes/csrf.php:6-11`
- **Description:** Same token for entire session lifetime. Leaked token = all forms vulnerable.
- **Fix:** Rotate after critical operations (password change, account deletion).
- **Effort:** S
