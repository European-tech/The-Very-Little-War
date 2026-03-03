# AUTH-CROSS: Complete Session & Authentication Flow Trace

**Auditor:** Round 3 Cross-Domain Security Analysis
**Date:** 2026-03-03
**Scope:** Full auth lifecycle from registration to session destruction across all entry points

---

## A) AUTH FLOW DIAGRAM

### 1. Registration Flow

```
inscription.php
    |
    v
includes/basicpublicphp.php
    |-- includes/connexion.php         (DB connection from .env)
    |-- includes/fonctions.php         (loads 7 modules)
    |-- includes/csrf.php
    |-- includes/logger.php
    |-- includes/rate_limiter.php
    |-- includes/session_init.php      (hardened session_start)
    |
    |-- Clears any active login session:
    |     unset($_SESSION['login'])
    |     unset($_SESSION['mdp'])
    |     unset($_SESSION['session_token'])
    |
    v
POST /inscription.php
    |
    |-- Rate limit check: 3 registrations/hour per IP
    |-- CSRF token verification
    |-- Input validation:
    |     validateLogin(): ^[a-zA-Z0-9_]{3,20}$
    |     validateEmail(): filter_var FILTER_VALIDATE_EMAIL
    |     Password confirmation match
    |-- NO PASSWORD STRENGTH CHECK <-- FINDING
    |-- Duplicate email/login check (prepared statements)
    |
    v
inscrire() in includes/player.php
    |
    |-- password_hash($mdp, PASSWORD_DEFAULT)   (bcrypt)
    |-- withTransaction() wraps 5 INSERTs:
    |     INSERT INTO membre (login, pass_md5, ...)
    |     INSERT INTO autre (...)
    |     INSERT INTO ressources (...)
    |     UPDATE statistiques SET inscrits=...
    |     INSERT INTO molecules (4 rows)
    |     INSERT INTO constructions (...)
    |
    v
JavaScript redirect to index.php?inscrit=1
    |-- NOTE: No auto-login after registration
    |-- NOTE: No email verification required
    |-- NOTE: No session_token created at registration
```

### 2. Visitor (Test Account) Flow

```
comptetest.php?inscription=1
    |
    |-- includes/session_init.php (hardened)
    |-- Rate limit: 3 visitor accounts per 5 min per IP
    |-- Atomic visitor number increment (LAST_INSERT_ID)
    |-- Calls inscrire() with predictable credentials:
    |     login: "VisiteurN"
    |     password: "VisiteurN"        <-- FINDING: predictable credentials
    |     email: "VisiteurN@tvlw.com"
    |
    v
Immediate auto-login:
    |-- session_regenerate_id(true)
    |-- $_SESSION['login'] = "VisiteurN"
    |-- $sessionToken = bin2hex(random_bytes(32))
    |-- $_SESSION['session_token'] = $sessionToken
    |-- UPDATE membre SET session_token = ?
    |
    v
Redirect to tutoriel.php?deployer=1
```

### 3. Login Flow (Player)

```
index.php  (or any public page with login form)
    |
    v
POST to index.php (or current public page)
    |
    |-- Handled in includes/basicpublicphp.php (lines 21-83)
    |
    v
Authentication checks:
    |-- CSRF verification: csrfCheck()
    |-- Non-empty field validation
    |-- Rate limit: 10 attempts per 5 min per IP
    |-- Normalize login: ucfirst(mb_strtolower(trim()))
    |
    v
Password verification (two-phase):
    |
    |-- Phase 1: password_verify($input, $storedHash)  (bcrypt)
    |     |-- If match: authenticated = true
    |
    |-- Phase 2 (fallback): hash_equals(md5($input), $storedHash)
    |     |-- If match: authenticated = true
    |     |-- AUTO-UPGRADE: password_hash() replaces MD5 in DB
    |     |-- UPDATE membre SET pass_md5 = ? (bcrypt hash)
    |
    v
On successful authentication:
    |-- session_regenerate_id(true)        (fixation protection)
    |-- $_SESSION['login'] = $loginInput
    |-- $sessionToken = bin2hex(random_bytes(32))   (64 hex chars)
    |-- $_SESSION['session_token'] = $sessionToken
    |-- UPDATE membre SET session_token=? WHERE login=?
    |-- UPDATE membre SET ip=? WHERE login=?
    |-- logInfo('AUTH', 'Login successful', ...)
    |
    v
header('Location: constructions.php')
```

### 4. Private Page Session Validation

```
Any private page (e.g., constructions.php)
    |
    v
includes/basicprivatephp.php
    |-- includes/session_init.php        (hardened session)
    |-- includes/connexion.php           (DB)
    |-- includes/fonctions.php
    |-- includes/csrf.php
    |-- includes/validation.php
    |-- includes/logger.php
    |
    v
Session validation (3 branches):
    |
    |-- Branch A: $_SESSION['login'] + $_SESSION['session_token'] set
    |     |-- DB lookup: SELECT session_token FROM membre WHERE login=?
    |     |-- hash_equals(DB token, session token)
    |     |-- If mismatch: session_destroy() + redirect to index.php
    |     |-- Periodic regeneration: every 30 min
    |     |     session_regenerate_id(true)
    |     |     $_SESSION['_last_regeneration'] = time()
    |
    |-- Branch B (LEGACY): $_SESSION['login'] + $_SESSION['mdp'] set
    |     |-- DB lookup: SELECT pass_md5 FROM membre WHERE login=?
    |     |-- TIMING-UNSAFE: $row['pass_md5'] !== $_SESSION['mdp']  <-- FINDING
    |     |-- If mismatch: session_destroy() + redirect
    |     |-- If match: MIGRATE to session_token
    |     |     Generate new session_token
    |     |     Unset $_SESSION['mdp']
    |     |     Update DB with session_token
    |     |     session_regenerate_id(true)
    |
    |-- Branch C: Neither set
    |     |-- session_destroy() + redirect to index.php
    |
    v
Idle timeout check:
    |-- If $_SESSION['last_activity'] > 3600 seconds ago:
    |     session_destroy() + redirect with "Session expiree" message
    |-- Update: $_SESSION['last_activity'] = time()
    |
    v
Game state initialization:
    |-- Random position for new players (x == -1000)
    |-- Load constantes.php
    |-- Track connected users (IP-based, 5-min window)
    |-- Update resources if not on vacation
    |-- Check season maintenance state
```

### 5. Admin Login Flow

```
admin/index.php
    |
    v
session_start()   <-- BARE, NO HARDENING (no session_init.php)
    |-- Missing: cookie_httponly, cookie_secure, use_strict_mode
    |-- Missing: cookie_samesite, gc_maxlifetime
    |
    v
POST with motdepasseadmin:
    |-- Rate limit: 5 attempts per 5 min per IP
    |-- password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)
    |-- ADMIN_PASSWORD_HASH: pre-computed bcrypt in constantesBase.php
    |-- On success: $_SESSION['motdepasseadmin'] = true
    |-- NO session_regenerate_id(true)    <-- FINDING: session fixation
    |-- NO CSRF on login form             <-- FINDING
    |-- logInfo('ADMIN', 'Admin login successful')
    |
    v
Admin session persists indefinitely:
    |-- NO idle timeout                   <-- FINDING
    |-- NO periodic session ID regeneration
    |-- NO session_token (DB-validated) mechanism
    |-- Shares PHPSESSID namespace with player sessions

Sub-page auth guard: admin/redirectionmotdepasse.php
    |-- session_start()                   <-- BARE
    |-- Check: $_SESSION['motdepasseadmin'] === true
    |-- If not: redirect to index.php + exit
    |-- Used by: supprimercompte, supprimerreponse, listenews,
    |            redigernews, listesujets, ip, tableau
```

### 6. Moderation Login Flow

```
moderation/index.php
    |
    v
session_start()   <-- BARE, NO HARDENING
    |
    v
POST with motdepasseadmin:
    |-- Rate limit: 5 attempts per 5 min per IP
    |-- password_verify() against SAME ADMIN_PASSWORD_HASH
    |-- On success: $_SESSION['motdepasseadmin'] = true
    |-- Same session key as admin!         <-- FINDING
    |-- NO session_regenerate_id(true)
    |-- NO CSRF on login form
    |
    v
moderation/mdp.php (sub-page guard):
    |-- require_once(session_init.php)     <-- HARDENED (inconsistent!)
    |-- Check: $_SESSION['motdepasseadmin'] === true
    |-- Used by: moderation/ip.php

moderationForum.php (in web root):
    |-- Uses basicprivatephp.php (player auth required)
    |-- THEN checks: SELECT moderateur FROM membre WHERE login=?
    |-- Requires BOTH player session AND moderateur flag
    |-- Different auth model from moderation/index.php
```

### 7. Password Change Flow

```
compte.php
    |
    v
includes/basicprivatephp.php (full private auth)
    |
    v
POST with changermdpactuel + changermdp + changermdp1:
    |-- CSRF verification: csrfCheck()
    |-- Fetch current hash: SELECT pass_md5 FROM membre WHERE login=?
    |
    v
Current password verification (two-phase):
    |-- Phase 1: password_verify($current, $storedHash)
    |-- Phase 2: md5($current) === $storedHash
    |     TIMING-UNSAFE comparison (===)    <-- FINDING
    |
    v
On success:
    |-- $newHash = password_hash($newPassword, PASSWORD_DEFAULT)
    |-- UPDATE membre SET pass_md5 = ?
    |-- Regenerate session_token:
    |     $newToken = bin2hex(random_bytes(32))
    |     $_SESSION['session_token'] = $newToken
    |     UPDATE membre SET session_token = ?
    |-- This invalidates ALL other sessions for this user
    |
    |-- NO minimum password length check   <-- FINDING
    |-- NO password complexity requirements
    |-- New/confirm password match check only
```

### 8. Logout Flow

```
deconnexion.php
    |
    v
includes/session_init.php (hardened session)
includes/connexion.php + includes/fonctions.php
    |
    v
Optional account deletion (POST):
    |-- CSRF check: csrfCheck()
    |-- supprimerJoueur($_SESSION['login'])
    |
    v
Session destruction (comprehensive):
    |-- Invalidate DB token: UPDATE membre SET session_token = NULL
    |-- session_unset()       (clear all session variables)
    |-- session_destroy()     (destroy session file)
    |-- Clear session cookie:
    |     setcookie(session_name(), '', time() - 42000, ...)
    |     Respects path, domain, secure, httponly params
    |
    v
Client-side cleanup:
    |-- localStorage.removeItem("login")
    |-- JavaScript redirect to index.php
```

### 9. Public Page Session Clearing

```
includes/basicpublicphp.php
    |
    v
On load (EVERY public page):
    |-- If $_SESSION['login'] is set:
    |     unset($_SESSION['login'])
    |     unset($_SESSION['mdp'])
    |     unset($_SESSION['session_token'])
    |-- NOTE: Does NOT unset $_SESSION['motdepasseadmin']  <-- FINDING
    |-- NOTE: Does NOT call session_destroy()
    |     (preserves CSRF tokens for forms)
    |
    v
Login form processing (if POST):
    |-- (See Login Flow above)
```

---

## B) SESSION SECURITY MATRIX

| Security Feature | Implemented? | Location | Gap |
|---|---|---|---|
| **Session Hardening** | | | |
| cookie_httponly | PARTIAL | `session_init.php` L7 | Admin/mod login pages use bare `session_start()`, bypass hardening |
| cookie_secure | PARTIAL | `session_init.php` L8 | Conditional on HTTPS; admin pages bypass; not yet on HTTPS |
| use_strict_mode | PARTIAL | `session_init.php` L9 | Admin pages bypass |
| cookie_samesite=Lax | PARTIAL | `session_init.php` L10 | Admin pages bypass |
| gc_maxlifetime=3600 | PARTIAL | `session_init.php` L11 | Admin pages bypass; gc_maxlifetime is not a hard timeout |
| **Session Fixation Protection** | | | |
| Regenerate on player login | YES | `basicpublicphp.php` L61 | Properly implemented with `session_regenerate_id(true)` |
| Regenerate on admin login | NO | `admin/index.php` L17 | `$_SESSION['motdepasseadmin'] = true` set without regeneration |
| Regenerate on mod login | NO | `moderation/index.php` L16 | Same issue |
| Regenerate periodically | YES (player) | `basicprivatephp.php` L19-22 | Every 30 min for player sessions; admin has NO periodic regeneration |
| Regenerate on privilege change | YES | `compte.php` L63 | Token regenerated after password change |
| Regenerate on visitor login | YES | `comptetest.php` L23 | Properly implemented |
| **Session Validation** | | | |
| DB-backed session token | YES (player) | `basicprivatephp.php` L10-16 | Token compared with `hash_equals()` (timing-safe) |
| DB-backed token (admin) | NO | `admin/index.php` | Admin auth is session-only boolean, no DB validation |
| Legacy mdp fallback | YES (migration) | `basicprivatephp.php` L23-39 | Legacy branch uses `!==` (timing-unsafe) for hash comparison |
| **Timeout Controls** | | | |
| Player idle timeout | YES | `basicprivatephp.php` L47-52 | 1 hour idle timeout, properly destroys session |
| Admin idle timeout | NO | `admin/index.php` | No timeout mechanism at all |
| Admin absolute timeout | NO | -- | Session persists until PHP garbage collection |
| gc_maxlifetime | PARTIAL | `session_init.php` L11 | 1h for player; admin pages may use PHP default (24 min or 1440 sec) |
| **Session Namespace** | | | |
| Player-admin separation | NO | All files | Both use default `PHPSESSID`, shared `$_SESSION` superglobal |
| Admin key in player namespace | YES (problem) | `$_SESSION['motdepasseadmin']` | Player visiting admin can have BOTH player AND admin in same session |
| Public page clears admin flag | NO | `basicpublicphp.php` L12-16 | Only clears `login`, `mdp`, `session_token`; leaves `motdepasseadmin` |
| **CSRF Protection** | | | |
| Player login form | YES | `basicpublicphp.php` L22 | csrfCheck() before processing |
| Registration form | YES | `inscription.php` L14 | csrfCheck() |
| Admin login form | NO | `admin/index.php` L180-184 | No csrfField() in login form, no csrfCheck() on login POST |
| Mod login form | NO | `moderation/index.php` L24-28 | Same issue |
| Admin action forms | YES | `admin/index.php` L25-27 | csrfCheck() for supprimercompte, maintenance, miseazero |
| Mod action forms | YES | `moderation/index.php` L32-33 | csrfCheck() on all POST |
| **Rate Limiting** | | | |
| Player login | YES | `basicpublicphp.php` L25 | 10 attempts / 5 min / IP |
| Registration | YES | `inscription.php` L9 | 3 registrations / 1 hour / IP |
| Admin login | YES | `admin/index.php` L12 | 5 attempts / 5 min / IP |
| Mod login | YES | `moderation/index.php` L11 | 5 attempts / 5 min / IP |
| Visitor creation | YES | `comptetest.php` L11 | 3 / 5 min / IP |
| Rate limit storage | CONCERN | `rate_limiter.php` | File-based (/tmp), no atomic locking, race conditions possible |
| **Password Security** | | | |
| Bcrypt hashing (new) | YES | `inscription.php` -> `inscrire()` L54 | PASSWORD_DEFAULT (bcrypt) |
| MD5 auto-upgrade | YES | `basicpublicphp.php` L49-54 | Transparent upgrade on login |
| MD5 upgrade on pw change | YES | `compte.php` L59 | New password always bcrypt |
| Password min length | NO | -- | No minimum length enforced anywhere |
| Password complexity | NO | -- | No requirements |
| **Logging** | | | |
| Login success | YES | `basicpublicphp.php` L68 | logInfo with login name |
| Login failure | YES | `basicpublicphp.php` L73-74 | logWarn with attempted login |
| Admin login success | YES | `admin/index.php` L18 | logInfo |
| Admin login failure | YES | `admin/index.php` L20 | logWarn |
| Registration | YES | `inscription.php` L40 | logInfo with login + email |
| Session invalidation | NO | `basicprivatephp.php` L13-14 | Token mismatch destroys session silently, no log |
| **Cookie Cleanup** | | | |
| Logout clears cookie | YES | `deconnexion.php` L19-24 | setcookie with expired time, respects params |
| Logout clears DB token | YES | `deconnexion.php` L12 | session_token set to NULL |
| Logout clears localStorage | YES | `deconnexion.php` L37 | Removes "login" key |
| Admin has no logout | YES (problem) | -- | No admin/deconnexion.php exists |

---

## C) ADMIN vs PLAYER AUTH COMPARISON

### What Currently Differs

| Aspect | Player Auth | Admin Auth | Security Impact |
|---|---|---|---|
| **Session init** | `session_init.php` (hardened) | Bare `session_start()` | Admin sessions lack HttpOnly, SameSite, strict mode |
| **Credential storage** | DB `membre.pass_md5` (bcrypt per-user) | `ADMIN_PASSWORD_HASH` constant in `constantesBase.php` | Single shared password, no per-admin identity |
| **Session key** | `$_SESSION['login']` + `$_SESSION['session_token']` | `$_SESSION['motdepasseadmin']` = `true` | Admin has no identity, just a boolean flag |
| **DB validation** | Every page: token compared to `membre.session_token` | None: boolean flag checked in session only | Admin sessions cannot be remotely invalidated |
| **Session regeneration** | On login + every 30 min | Never | Admin sessions vulnerable to fixation |
| **Idle timeout** | 1 hour via `$_SESSION['last_activity']` | None | Admin sessions persist indefinitely |
| **CSRF on login** | Yes | No | Admin login susceptible to login CSRF |
| **Logout** | `deconnexion.php` (comprehensive) | None exists | Admin must wait for session GC or clear cookies manually |
| **Rate limiting** | 10/5min | 5/5min | Admin is slightly stricter (good) |
| **Logging** | Success + failure | Success + failure | Both adequate |
| **Namespace** | `$_SESSION['login']`, `$_SESSION['session_token']` | `$_SESSION['motdepasseadmin']` | Shared `PHPSESSID` -- cross-contamination possible |

### What SHOULD Differ (Recommended Admin Hardening)

| Feature | Current State | Required State |
|---|---|---|
| Session hardening | Bare `session_start()` | MUST use `session_init.php` -- admin needs MORE security, not less |
| Session fixation | No regeneration on admin login | MUST call `session_regenerate_id(true)` after setting admin flag |
| Separate session namespace | Shared PHPSESSID | SHOULD use `session_name('TVLW_ADMIN')` or separate cookie |
| Idle timeout | None | MUST implement -- 15 minutes recommended for admin (stricter than player) |
| Absolute timeout | None | SHOULD implement 2-hour maximum session lifetime |
| IP binding | None | SHOULD bind admin session to IP (admin rarely changes network) |
| Admin logout | Does not exist | MUST create `admin/deconnexion.php` |
| CSRF on login | Missing | MUST add csrfField() to login form |
| Admin identity | Anonymous boolean | SHOULD log which admin (if multiple) -- currently moot with single password |
| DB-backed validation | None | SHOULD store admin session token for remote revocation capability |
| Public page clearing | Does not clear `motdepasseadmin` | MUST unset `$_SESSION['motdepasseadmin']` in `basicpublicphp.php` |

### Critical Cross-Contamination Scenario

Because admin and player sessions share the same `PHPSESSID` cookie and `$_SESSION` array:

1. Admin logs in at `admin/index.php` -- `$_SESSION['motdepasseadmin'] = true`
2. Admin navigates to main site `index.php` -- `basicpublicphp.php` runs
3. `basicpublicphp.php` clears `$_SESSION['login']`, `$_SESSION['mdp']`, `$_SESSION['session_token']`
4. But `$_SESSION['motdepasseadmin']` survives (NOT cleared)
5. Admin logs in as a player -- now session has BOTH player AND admin flags
6. If the player session is somehow compromised, attacker also gets admin access

Reverse scenario:
1. Player has active session with `$_SESSION['login']` + `$_SESSION['session_token']`
2. Player navigates to `admin/index.php` and enters admin password
3. Admin flag is added to existing player session
4. Player session is now privileged -- no session regeneration occurred
5. Any pre-existing session fixation attack now grants admin access

### Moderation Auth Inconsistency

There are TWO completely separate moderation auth models:

**Model A -- `moderation/index.php` and `moderation/mdp.php`:**
- Password-based: same ADMIN_PASSWORD_HASH as admin panel
- Session key: `$_SESSION['motdepasseadmin']` (same as admin -- grants admin access!)
- No player identity required
- FINDING: A moderator who knows the admin password has full admin panel access

**Model B -- `moderationForum.php` (in web root):**
- Player-session-based: requires `basicprivatephp.php` (full player auth)
- Then checks `SELECT moderateur FROM membre WHERE login=?`
- Requires the `moderateur` DB flag on the player's account
- CSRF protected, proper session hardening
- This is the CORRECT model

The two models are incompatible. Model A should be deprecated in favor of Model B (role-based access through the player session).

---

## D) MD5 MIGRATION STATUS

### Migration Mechanism Analysis

**Where MD5 hashes are detected and upgraded:**

| File | Line | Trigger | Action |
|---|---|---|---|
| `basicpublicphp.php` | 49-54 | Login with MD5-hashed password | `password_hash()` replaces MD5 in DB |
| `compte.php` | 50 | Password change verification | Verifies MD5 but always stores bcrypt for new password |
| `basicprivatephp.php` | 23-39 | Active session with `$_SESSION['mdp']` | Migrates session from password-hash to session_token |

**How to determine current migration status:**

MD5 hashes are exactly 32 hex characters. Bcrypt hashes start with `$2y$` and are 60 characters. A database query can reveal:

```sql
-- Count users still on MD5
SELECT COUNT(*) FROM membre WHERE LENGTH(pass_md5) = 32;

-- Count users upgraded to bcrypt
SELECT COUNT(*) FROM membre WHERE pass_md5 LIKE '$2y$%';

-- List MD5 users (if any remain)
SELECT login, LENGTH(pass_md5) as hash_len,
       FROM_UNIXTIME(derniereConnexion) as last_login
FROM membre
WHERE LENGTH(pass_md5) = 32
ORDER BY derniereConnexion DESC;
```

### Migration Completeness Assessment

| Aspect | Status | Notes |
|---|---|---|
| New registrations | COMPLETE | `inscrire()` always uses `password_hash(PASSWORD_DEFAULT)` |
| Login upgrade | COMPLETE | `basicpublicphp.php` L49-54 transparently upgrades |
| Password change upgrade | COMPLETE | `compte.php` L59 always stores bcrypt |
| Visitor accounts | COMPLETE | `comptetest.php` L27 uses `password_hash()` |
| Session migration | COMPLETE | `basicprivatephp.php` L23-39 migrates `$_SESSION['mdp']` to `session_token` |
| Inactive users | NOT MIGRATED | Users who have not logged in since the upgrade still have MD5 hashes |
| Force migration | NOT IMPLEMENTED | No mechanism to force password reset for MD5 users |

### Migration Gaps and Risks

**FINDING AUTH-CROSS-001 [MEDIUM]: Stale MD5 hashes persist indefinitely for inactive users**

Users who registered before the bcrypt upgrade and have never logged in since still have MD5 hashes in the database. If the database is compromised, these hashes can be cracked trivially (MD5 rainbow tables, hashcat at billions/sec).

The migration is purely opportunistic (on-login). There is no:
- Forced password reset after N days
- Background migration job
- Notification to users with weak hashes
- Expiration of MD5-only accounts

**Recommendation:** After a reasonable migration window (e.g., 6 months), either:
1. Force password reset for remaining MD5 users on next login
2. Lock accounts with MD5 hashes and require email-based password reset
3. At minimum, log which accounts still use MD5 for monitoring

**FINDING AUTH-CROSS-002 [LOW]: Legacy session fallback uses timing-unsafe comparison**

In `basicprivatephp.php` line 27:
```php
if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
```

This uses `!==` (strict comparison) instead of `hash_equals()`. While the session data comes from the server (not user input), in the legacy branch `$_SESSION['mdp']` contained the password hash. A timing side-channel is theoretically exploitable if an attacker can measure response times with sufficient precision. The primary branch (line 12) correctly uses `hash_equals()`.

**FINDING AUTH-CROSS-003 [LOW]: Password change MD5 fallback uses timing-unsafe comparison**

In `compte.php` line 50:
```php
} elseif (md5($currentPassword) === $storedHash) {
```

Uses `===` instead of `hash_equals()`. Since `$currentPassword` comes from user input and the comparison is against the stored hash, a timing attack could theoretically leak hash bytes. Low severity because:
- The attacker already needs the current password
- MD5 hashes are being phased out
- The comparison is between `md5(user_input)` and `stored_hash`, not raw secrets

---

## E) COMPLETE FINDINGS SUMMARY

### CRITICAL

| ID | Finding | File(s) | Line(s) |
|---|---|---|---|
| AUTH-CROSS-004 | Admin login pages use bare `session_start()` bypassing ALL session hardening (HttpOnly, SameSite, strict mode) | `admin/index.php`, `moderation/index.php`, `admin/redirectionmotdepasse.php` | 4, 2, 2 |
| AUTH-CROSS-005 | No `session_regenerate_id(true)` after admin/mod login -- session fixation vulnerability | `admin/index.php`, `moderation/index.php` | 17, 16 |
| AUTH-CROSS-006 | Admin and player sessions share namespace (`PHPSESSID`); admin flag `$_SESSION['motdepasseadmin']` persists across player login/public pages | `admin/index.php`, `basicpublicphp.php` | 17, 12-16 |

### HIGH

| ID | Finding | File(s) | Line(s) |
|---|---|---|---|
| AUTH-CROSS-007 | No admin session idle/absolute timeout -- admin sessions persist indefinitely until PHP GC | `admin/index.php` | -- |
| AUTH-CROSS-008 | No admin logout mechanism exists -- no `admin/deconnexion.php` or equivalent | `admin/` directory | -- |
| AUTH-CROSS-009 | Admin auth is a session-only boolean with no DB-backed validation -- cannot remotely revoke admin sessions | `admin/index.php`, `admin/redirectionmotdepasse.php` | 17, 4 |
| AUTH-CROSS-010 | Moderation panel and admin panel share same password AND same session key (`motdepasseadmin`) -- mod access grants admin access | `moderation/index.php`, `admin/index.php` | 16, 17 |
| AUTH-CROSS-011 | No password minimum length or complexity enforcement at registration or password change | `inscription.php`, `compte.php` | 24, 56 |

### MEDIUM

| ID | Finding | File(s) | Line(s) |
|---|---|---|---|
| AUTH-CROSS-001 | MD5 hashes persist indefinitely for inactive users with no forced migration or expiration | `basicpublicphp.php` | 49 |
| AUTH-CROSS-012 | Admin login form lacks CSRF token -- login CSRF possible (attacker can log victim into attacker's admin session) | `admin/index.php` | 180-184 |
| AUTH-CROSS-013 | Mod login form lacks CSRF token | `moderation/index.php` | 24-28 |
| AUTH-CROSS-014 | `basicpublicphp.php` does not clear `$_SESSION['motdepasseadmin']` when clearing player session data | `basicpublicphp.php` | 12-16 |
| AUTH-CROSS-015 | Visitor accounts use predictable credentials (`VisiteurN` / `VisiteurN`) -- can be brute-forced by iterating N | `comptetest.php` | 22 |
| AUTH-CROSS-016 | Session token invalidation not logged -- failed token validation in `basicprivatephp.php` silently destroys session without audit trail | `basicprivatephp.php` | 13-14 |
| AUTH-CROSS-017 | `moderation/mdp.php` uses `session_init.php` but `moderation/index.php` uses bare `session_start()` -- inconsistent hardening within same sub-application | `moderation/index.php`, `moderation/mdp.php` | 2, 2 |

### LOW

| ID | Finding | File(s) | Line(s) |
|---|---|---|---|
| AUTH-CROSS-002 | Legacy session fallback uses timing-unsafe `!==` for hash comparison | `basicprivatephp.php` | 27 |
| AUTH-CROSS-003 | Password change MD5 fallback uses timing-unsafe `===` for hash comparison | `compte.php` | 50 |
| AUTH-CROSS-018 | No email verification at registration -- accounts can be created with any email address | `inscription.php` | 39 |
| AUTH-CROSS-019 | Rate limiter is file-based (`/tmp/tvlw_rates/`) with no atomic locking -- race conditions under concurrent requests | `rate_limiter.php` | 36 |
| AUTH-CROSS-020 | No account lockout after repeated failed logins -- only rate limiting per IP, not per account | `basicpublicphp.php` | 25 |

---

## F) PRIORITIZED REMEDIATION PLAN

### Phase 1: Critical Session Fixes (Immediate)

**1. Replace bare `session_start()` in admin/mod files**

Files to modify:
- `admin/index.php` line 4
- `admin/redirectionmotdepasse.php` line 2
- `moderation/index.php` line 2

Replace `session_start()` with:
```php
require_once(__DIR__ . '/../includes/session_init.php');
```

**2. Add session fixation protection to admin/mod login**

In `admin/index.php` after line 17 (`$_SESSION['motdepasseadmin'] = true`):
```php
session_regenerate_id(true);
```

Same in `moderation/index.php` after line 16.

**3. Clear admin flag on public pages**

In `basicpublicphp.php`, add to the session clearing block (after line 15):
```php
unset($_SESSION['motdepasseadmin']);
```

### Phase 2: High-Priority Fixes

**4. Add admin idle timeout**

Create `admin/includes/admin_auth.php`:
```php
<?php
require_once(__DIR__ . '/../../includes/session_init.php');
if (!isset($_SESSION['motdepasseadmin']) || $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}
// 15-minute idle timeout for admin
if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 900) {
    unset($_SESSION['motdepasseadmin']);
    unset($_SESSION['admin_last_activity']);
    header('Location: index.php?expired=1');
    exit();
}
$_SESSION['admin_last_activity'] = time();
```

**5. Create admin logout**

Create `admin/deconnexion.php`:
```php
<?php
require_once(__DIR__ . '/../includes/session_init.php');
unset($_SESSION['motdepasseadmin']);
unset($_SESSION['admin_last_activity']);
header('Location: index.php');
exit();
```

**6. Add password minimum length**

In `inscription.php` and `compte.php`, add validation:
```php
if (strlen($passInput) < 8) {
    $erreur = 'Le mot de passe doit contenir au moins 8 caracteres.';
}
```

**7. Add CSRF to admin/mod login forms**

In `admin/index.php` login form (line 180):
```php
<form action="index.php" method="post">
    <?php echo csrfField(); ?>
    ...
```

And add `csrfCheck()` before the password verification (line 11).

### Phase 3: Medium-Term Improvements

**8. Separate admin/mod session namespace or use DB-backed admin tokens**
**9. Implement per-account lockout (not just per-IP rate limiting)**
**10. Add audit logging for session invalidation events**
**11. Deprecate `moderation/index.php` password-based auth in favor of the `moderationForum.php` role-based model**
**12. Force password reset for remaining MD5 accounts after migration window**
**13. Implement visitor account cleanup (currently only 3-hour idle cleanup in `basicpublicphp.php`)**

---

## G) SESSION LIFECYCLE STATE DIAGRAM

```
                    +------------------+
                    |   UNAUTHENTICATED|
                    |   (public page)  |
                    +--------+---------+
                             |
              +--------------+--------------+
              |              |              |
         [Register]     [Login]      [Visit Admin]
              |              |              |
              v              v              v
    +----------+    +-----------+    +------------+
    | REGISTERED|    |  PLAYER   |    |   ADMIN    |
    | (no login)|    |  SESSION  |    |   SESSION  |
    +----------+    +-----+-----+    +------+-----+
                          |                 |
                    [Every page]     [No timeout!]
                          |                 |
                    +-----v-----+    +------v-----+
                    | Validated |    | Boolean     |
                    | token +   |    | flag only   |
                    | idle check|    | No DB check |
                    +-----+-----+    +------+-----+
                          |                 |
              +-----------+-----------+     |
              |           |           |     |
         [Idle 1h]   [Token     [Logout]    |
              |       mismatch]   |         |
              v           v       v         |
         +--------+  +--------+  +--+       |
         |EXPIRED |  |REVOKED |  |OUT|      |
         +--------+  +--------+  +--+      |
                                      [No logout
                                       exists]
                                            |
                                            v
                                      +----------+
                                      | PERSISTS |
                                      | FOREVER  |
                                      +----------+
```

---

## H) FILES AUDITED

| File | Absolute Path | Role |
|---|---|---|
| session_init.php | `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php` | Centralized session configuration |
| inscription.php | `/home/guortates/TVLW/The-Very-Little-War/inscription.php` | Registration page |
| basicpublicphp.php | `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php` | Public page auth + login processing |
| basicprivatephp.php | `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` | Private page session validation |
| admin/index.php | `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` | Admin login + main admin panel |
| admin/redirectionmotdepasse.php | `/home/guortates/TVLW/The-Very-Little-War/admin/redirectionmotdepasse.php` | Admin sub-page auth guard |
| moderation/index.php | `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php` | Moderation login + panel |
| moderation/mdp.php | `/home/guortates/TVLW/The-Very-Little-War/moderation/mdp.php` | Moderation sub-page auth guard |
| moderationForum.php | `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php` | Role-based forum moderation |
| compte.php | `/home/guortates/TVLW/The-Very-Little-War/compte.php` | Password change + account management |
| deconnexion.php | `/home/guortates/TVLW/The-Very-Little-War/deconnexion.php` | Logout + session destruction |
| comptetest.php | `/home/guortates/TVLW/The-Very-Little-War/comptetest.php` | Visitor account creation + auto-login |
| index.php | `/home/guortates/TVLW/The-Very-Little-War/index.php` | Main entry point / login page |
| player.php | `/home/guortates/TVLW/The-Very-Little-War/includes/player.php` | inscrire() registration function |
| csrf.php | `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php` | CSRF token generation/validation |
| validation.php | `/home/guortates/TVLW/The-Very-Little-War/includes/validation.php` | Input validation functions |
| rate_limiter.php | `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php` | File-based rate limiting |
| logger.php | `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php` | Event/error logging |
| connexion.php | `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php` | Database connection |
| constantesBase.php | `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php` | ADMIN_PASSWORD_HASH constant |
| config.php | `/home/guortates/TVLW/The-Very-Little-War/includes/config.php` | Game configuration constants |
| messageCommun.php | `/home/guortates/TVLW/The-Very-Little-War/messageCommun.php` | Mass messaging (admin-only) |

---

## I) STATISTICS

- **Total findings:** 20
- **Critical:** 3 (bare session_start in admin, no session fixation protection, shared session namespace)
- **High:** 5 (no admin timeout, no admin logout, no DB-backed admin validation, shared admin/mod password, no password policy)
- **Medium:** 7 (MD5 persistence, missing CSRF on admin login, admin flag not cleared, predictable visitor creds, missing audit logs, inconsistent hardening)
- **Low:** 5 (timing-unsafe comparisons, no email verification, file-based rate limiter races, no per-account lockout)
- **Files audited:** 22
- **Auth entry points traced:** 5 (player login, registration, visitor, admin, moderation)
