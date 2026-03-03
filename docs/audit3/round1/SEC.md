# Security Audit Round 1 -- SEC Findings

**Auditor:** Claude Opus 4.6 (Anthropic)
**Date:** 2026-03-03
**Scope:** Session management, CSRF, authentication, authorization, XSS, SQL injection, path traversal, IDOR, privilege escalation, password handling, cookie security
**Codebase:** /home/guortates/TVLW/The-Very-Little-War/

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 7     |
| MEDIUM   | 10    |
| LOW      | 8     |
| **Total**| **27**|

---

## CRITICAL

### [SEC-R1-001] CRITICAL admin/index.php:4 -- Admin panel uses bare session_start() instead of session_init.php, missing session hardening

**File:** `admin/index.php:4`

The admin panel calls `session_start()` directly instead of including `includes/session_init.php`. This means the admin session lacks all hardening applied by `session_init.php`: `cookie_httponly`, `cookie_secure`, `use_strict_mode`, `cookie_samesite`, and `gc_maxlifetime`. The same issue exists in `admin/redirectionmotdepasse.php:2` and `moderation/index.php:2`.

**Impact:** The admin session cookie is vulnerable to JavaScript theft (no httponly), cross-site transmission (no samesite), and session fixation (no strict_mode). Since the admin session controls destructive operations (delete accounts, reset game, toggle maintenance), this is a critical exposure.

**Affected files:**
- `admin/index.php:4` -- `session_start()`
- `admin/redirectionmotdepasse.php:2` -- `session_start()`
- `moderation/index.php:2` -- `session_start()`
- `moderation/mdp.php:2` -- Uses `session_init.php` correctly (reference)

**Remediation:** Replace `session_start()` with `require_once(__DIR__ . '/../includes/session_init.php')` in all three files.

---

### [SEC-R1-002] CRITICAL admin/index.php:11,181 -- Admin login form has no CSRF protection

**File:** `admin/index.php:11,181`

The admin login form at line 181 submits `motdepasseadmin` via POST but does not include a CSRF token field. The login handler at line 11 does not call `csrfCheck()`. Although CSRF on login forms is sometimes deprioritized, this specific case is dangerous because:

1. The admin panel shares the same PHP session as the game (same `PHPSESSID` cookie, same session storage). Once `$_SESSION['motdepasseadmin'] = true` is set, it persists alongside any player session.
2. An attacker who knows the admin password could CSRF-login a victim's browser into the admin panel, then use that session for subsequent CSRF-based admin actions.
3. Login CSRF can be used for session fixation attacks where the attacker forces the victim to authenticate under the attacker's session.

The same issue exists in `moderation/index.php:10,24`.

**Remediation:** Add `csrfField()` to both login forms and add `csrfCheck()` before `password_verify()` in both handlers.

---

## HIGH

### [SEC-R1-003] HIGH admin/index.php + moderation/index.php -- Admin and player sessions share the same session namespace, enabling privilege escalation

**File:** `admin/index.php:4,23`, `moderation/index.php:2,22`

The admin and moderation panels use the exact same PHP session as the game. When a player is logged in and then authenticates to the admin panel, `$_SESSION['motdepasseadmin'] = true` is set in their existing session alongside `$_SESSION['login']`. This means:

1. Any player who learns the admin password gains admin privileges in their existing session -- there is no separate admin authentication domain.
2. If a player somehow obtains `$_SESSION['motdepasseadmin'] = true` (e.g., through session injection), they get full admin access.
3. There is no logout mechanism for the admin session. The `motdepasseadmin` flag persists until the session expires or is destroyed.
4. The session token validation in `basicprivatephp.php` does not apply to admin pages, so the admin session is not bound to a per-user token.

**Remediation:** Ideally, admin authentication should use a separate session or at minimum: (a) regenerate the session ID upon admin login, (b) bind the admin flag to a separate token validated on each admin request, (c) add an admin logout mechanism.

---

### [SEC-R1-004] HIGH compte.php:50 -- MD5 password comparison uses === instead of hash_equals(), creating timing oracle

**File:** `compte.php:50`

The legacy MD5 password fallback in the password change flow uses `===` for comparison:
```php
} elseif (md5($currentPassword) === $storedHash) {
```

The `===` operator in PHP is not constant-time for string comparison. An attacker can measure response times to progressively determine the correct MD5 hash byte-by-byte. In contrast, the login flow in `basicpublicphp.php:49` correctly uses `hash_equals()`.

**Impact:** Timing side-channel on the password change form could leak the stored MD5 hash for accounts that have not yet been migrated to bcrypt. The attacker needs the ability to make many requests and measure response times precisely.

**Remediation:** Replace `md5($currentPassword) === $storedHash` with `hash_equals(md5($currentPassword), $storedHash)`. Also add the auto-upgrade to bcrypt (missing in this code path, unlike `basicpublicphp.php:51-54`).

---

### [SEC-R1-005] HIGH compte.php:50 -- MD5 legacy fallback in password change does not auto-upgrade to bcrypt

**File:** `compte.php:48-52`

When a user changes their password via `compte.php`, if their current password is still stored as MD5 and they verify successfully via the MD5 fallback (line 50), the code proceeds to store the new password as bcrypt (line 59) but does NOT first upgrade the current hash. If the user enters the wrong new password confirmation (line 56), the old MD5 hash remains. More importantly, this code path confirms the MD5 hash is valid but does not upgrade it as the login flow does in `basicpublicphp.php:51-54`. This is an inconsistency.

**Impact:** Users who successfully verify their old MD5 password but fail the new password confirmation keep their weak MD5 hash indefinitely.

**Remediation:** After successful MD5 verification at line 50, immediately upgrade the stored hash to bcrypt before proceeding with the new password change logic.

---

### [SEC-R1-006] HIGH voter.php:24-26 -- GET-based vote submission lacks CSRF protection

**File:** `voter.php:24-26`

The voter endpoint accepts votes via GET without any CSRF protection:
```php
} elseif (isset($_GET['reponse'])) {
    // Legacy GET support -- will be removed in future
    $reponse = intval($_GET['reponse']);
}
```

Only the POST path (line 21-23) enforces CSRF. Any external site can embed `<img src="voter.php?reponse=3">` to cast votes on behalf of authenticated users.

**Impact:** An attacker can manipulate poll results by embedding vote links on external pages or in forum posts. While voting manipulation is not critical to game integrity, it undermines the purpose of polls and can be used to influence game direction decisions.

**Remediation:** Remove the GET-based voting path. Force all vote submissions through POST with CSRF tokens. The comment says "will be removed in future" -- this should be done now.

---

### [SEC-R1-007] HIGH index.php:52-53, maintenance.php:9 -- News content rendered with strip_tags allows stored XSS via allowed HTML tags

**File:** `index.php:52-53`, `maintenance.php:8-9`

News content is rendered using `strip_tags()` with an allowlist of HTML tags:
```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
$contenuNews = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));
```

The `strip_tags()` function removes non-allowed tags but does NOT sanitize attributes on allowed tags. An admin writing news content (or an attacker who compromises the admin panel) can inject:
- `<a href="javascript:alert(1)">` -- XSS via javascript: URIs on allowed `<a>` tags
- `<img src=x onerror="alert(1)">` -- XSS via event handlers on allowed `<img>` tags
- `<div onmouseover="alert(1)">` -- XSS via event handlers on allowed `<div>` tags
- `<span style="position:fixed;top:0;left:0;width:100%;height:100%" onclick="...">` -- clickjacking

This same pattern appears in `rapports.php:30-31` for combat reports, though those are generated server-side and less exploitable.

**Impact:** If an attacker gains admin access (or exploits SEC-R1-002/003), they can inject persistent XSS into news displayed to all users on the homepage and maintenance page.

**Remediation:** Use a proper HTML sanitizer library (e.g., HTML Purifier) instead of `strip_tags()`. Alternatively, use `htmlspecialchars()` on all content and convert BBCode/Markdown to safe HTML.

---

### [SEC-R1-008] HIGH comptetest.php:9,22,26 -- Visitor accounts have predictable username=password credentials

**File:** `comptetest.php:9,22,26`

Visitor accounts are created with the password equal to the username:
```php
$visitorPass = "Visiteur" . $visitorNum;
$hashedPass = password_hash($visitorPass, PASSWORD_DEFAULT);
```

The visitor number increments sequentially (e.g., Visiteur42, Visiteur43). Anyone can enumerate visitor accounts and log in with `login=Visiteur42, password=Visiteur42`. While visitors are temporary (cleaned up after 3 hours of inactivity), an attacker could:
1. Log in as any active visitor account
2. Send messages, attack other players, or cause damage under the visitor's identity
3. Access the visitor's resources and data

**Impact:** All active visitor accounts are effectively unauthenticated. Any attacker can impersonate any visitor.

**Remediation:** Generate a random password for visitor accounts and display it once at creation time, or use a non-guessable session-only authentication mechanism for visitors.

---

### [SEC-R1-009] HIGH basicprivatephp.php:23-39 -- Legacy session migration fallback uses non-constant-time comparison

**File:** `basicprivatephp.php:27`

The legacy session validation path compares password hashes with `!==`:
```php
if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
```

This uses PHP's `!==` operator which is not constant-time. An attacker who can control `$_SESSION['mdp']` (e.g., through session injection) could use timing to determine the stored hash. Additionally, this legacy path stores the password hash in the session (`$_SESSION['mdp']`), which means the full bcrypt/MD5 hash is persisted in the session file on disk.

**Impact:** Password hash leakage via session files and timing oracle. The code comment indicates this is a migration path that should be removed.

**Remediation:** Remove this legacy block entirely. Any sessions still using the old format should be forced to re-authenticate.

---

## MEDIUM

### [SEC-R1-010] MEDIUM admin/index.php:30 -- Admin account deletion uses IP as identifier, can delete unrelated accounts

**File:** `admin/index.php:29-34`

The admin account deletion takes an IP address from POST and deletes ALL accounts matching that IP:
```php
$ip = $_POST['supprimercompte'];
$rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
foreach ($rows as $login) {
    supprimerJoueur($login['login']);
}
```

The IP value is taken directly from `$_POST['supprimercompte']` with no validation. While this is behind admin auth, the mass-deletion based on IP with no confirmation is dangerous. A typo or manipulated IP could delete legitimate accounts.

**Remediation:** Add input validation for IP format. Show a confirmation step listing accounts that will be deleted. Consider deleting individual accounts by login instead of by IP.

---

### [SEC-R1-011] MEDIUM comptetest.php:59 -- Visitor-to-player conversion uses $_SESSION['login'] without verifying it was set

**File:** `comptetest.php:59`

In the POST branch (visitor converting to a real account), line 59 reads:
```php
$oldLogin = $_SESSION['login'];
```

If a user reaches this POST path without first creating a visitor account (directly posting to `comptetest.php` with form data), `$_SESSION['login']` may be unset or belong to a different user. The code does not verify that `$_SESSION['login']` corresponds to a valid visitor account before renaming all database records.

**Impact:** If `$_SESSION['login']` is set to another player's name (e.g., through session manipulation), the rename operation at lines 67-82 would reassign ALL of that player's data (resources, molecules, messages, alliance membership) to the new login name, effectively stealing their account.

**Remediation:** Verify that `$_SESSION['login']` is set and starts with "Visiteur" before allowing the conversion. Add a check that the logged-in session has a valid session token.

---

### [SEC-R1-012] MEDIUM comptetest.php:51,52 -- Weaker input validation than inscription.php for registration

**File:** `comptetest.php:51-52`

The visitor conversion registration path uses different validation than `inscription.php`:
- Login: `preg_match("#^[A-Za-z0-9]*$#", ...)` -- no length limit (inscription.php uses `validateLogin()` which enforces 3-20 chars)
- Email: `preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", ...)` -- case-sensitive (no uppercase), limited TLDs, no `validateEmail()`
- No duplicate email check (inscription.php checks this at line 32-34)

**Impact:** A visitor could register with an empty login (regex matches empty string due to `*`), an excessively long login, or a duplicate email address.

**Remediation:** Use the same `validateLogin()` and `validateEmail()` functions as `inscription.php`. Add duplicate email check.

---

### [SEC-R1-013] MEDIUM includes/rate_limiter.php:8,13 -- File-based rate limiter in /tmp is world-readable and clearable

**File:** `includes/rate_limiter.php:8,13`

The rate limiter stores attempt data in `/tmp/tvlw_rates/` with permissions 0755:
```php
define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');
mkdir($dir, 0755, true);
```

Issues:
1. `/tmp` is world-readable on most Linux systems. Other users on the VPS can read rate limit files to determine which IPs are being rate-limited (information disclosure).
2. Other users or processes can delete files from `/tmp`, resetting rate limits and enabling brute-force attacks.
3. Files use `md5($identifier . '_' . $action)` as names -- MD5 collisions could theoretically cause rate limit interference between different identifiers.
4. The data is JSON files with no locking for reads (only `LOCK_EX` on writes), creating potential TOCTOU issues under high concurrency.

**Impact:** Rate limiting for login brute-force protection can be bypassed by clearing files from `/tmp`. On a shared hosting environment, this is exploitable by any user.

**Remediation:** Store rate limit data in a directory owned by the web server user with 0700 permissions, outside of `/tmp` (e.g., `/var/lib/tvlw/rates/`). Consider using database-backed rate limiting for better atomicity.

---

### [SEC-R1-014] MEDIUM includes/session_init.php:8 -- Session cookie_secure is conditional, not enforced

**File:** `includes/session_init.php:8`

```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```

This only sets the secure flag if the current request is HTTPS. On a server behind a reverse proxy or load balancer, `$_SERVER['HTTPS']` may not be set even when the client connection is encrypted. Until HTTPS is enabled (per the TODO), all session cookies are transmitted in cleartext.

**Impact:** Session cookies can be intercepted via network sniffing on HTTP connections. Even after HTTPS is enabled, if the check fails (e.g., behind a proxy), cookies will still be sent over HTTP.

**Remediation:** Once HTTPS is enabled, hardcode `session.cookie_secure = 1` and remove the conditional. Add HSTS headers to prevent downgrade attacks.

---

### [SEC-R1-015] MEDIUM compte.php:129 -- Avatar upload uses uniqid() for filename, which is predictable

**File:** `compte.php:129`

```php
$fichier = uniqid('avatar_') . '.' . $extension;
```

`uniqid()` is based on the current timestamp in microseconds and is predictable. An attacker who knows approximately when an avatar was uploaded can enumerate possible filenames and access the uploaded image directly.

**Impact:** Low -- the uploaded files are validated as images and served from a public directory anyway. However, this could enable targeted access to user avatars when privacy is expected.

**Remediation:** Use `bin2hex(random_bytes(16))` instead of `uniqid()` for cryptographically random filenames.

---

### [SEC-R1-016] MEDIUM includes/bbcode.php:331 -- BBCode [url] tag allows link injection after htmlentities encoding

**File:** `includes/bbcode.php:331`

```php
$text = preg_replace('!\[url=(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\](.+)\[/url\]!isU', '<a href="$1">$5</a>', $text);
```

While the URL regex requires `http(s)://`, the `$5` capture group (link text) is placed inside an `<a>` tag after `htmlentities()` has been applied. The regex is case-insensitive (`i` flag) and uses `isU` modifiers. The text content `$5` could contain encoded HTML entities that, when rendered in the browser, are decoded -- though since `htmlentities()` was already applied at line 317, this specific vector is mitigated.

However, the URL regex itself at line 331 has a catastrophic backtracking vulnerability: the pattern `([\/\w \.-]*)*` contains nested quantifiers (a character class with `*` inside a group with `*`). Input like `[url=http://a.bb/aaaa...]text[/url]` with many characters matching `[\w \.-]` followed by a character that doesn't match can cause exponential regex evaluation time (ReDoS).

**Impact:** A user posting a crafted BBCode URL in a forum post could cause CPU exhaustion on the server, leading to denial of service.

**Remediation:** Fix the regex to remove nested quantifiers: `([\/\w \.-]*)` without the outer `*`.

---

### [SEC-R1-017] MEDIUM includes/bbcode.php:332 -- BBCode [img] tag allows external image loading (SSRF/tracking)

**File:** `includes/bbcode.php:332`

```php
$text = preg_replace('!\[img=(https?:\/\/[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU', '<img alt="undefined" src="$1">', $text);
```

Users can embed arbitrary external images in forum posts. The browser loads these images from the attacker's server, which enables:
1. IP tracking of any user who views the forum post
2. If the server renders pages server-side (e.g., in email or scraping), it could enable SSRF
3. The image URL could point to extremely large files or slow-responding servers, causing client-side DoS

**Impact:** User IP harvesting, potential phishing via lookalike images, and tracking.

**Remediation:** Either proxy external images through the server (with caching and size limits), or restrict image sources to the game's domain only.

---

### [SEC-R1-018] MEDIUM deconnexion.php:5-8 -- Account deletion on logout uses CSRF check but could be triggered by session race

**File:** `deconnexion.php:5-8`

```php
if(isset($_POST['verification']) AND isset($_POST['oui'])) {
    csrfCheck();
    supprimerJoueur($_SESSION['login']);
}
```

The `csrfCheck()` is called after checking POST data, but the `csrf.php` `csrfCheck()` function only triggers on POST requests. The `deconnexion.php` file includes `session_init.php` but NOT `basicprivatephp.php` -- so it does not validate the session token against the database. The `$_SESSION['login']` value used to delete the player is trusted without full session validation.

If an attacker can manipulate the session (e.g., via session fixation before the user logged in), the deletion targets whatever login is in the session.

**Impact:** The session token is not validated, so a stale or manipulated session could trigger account deletion for the wrong user. The CSRF protection mitigates external exploitation but does not prevent session-based attacks.

**Remediation:** Add session token validation (same as `basicprivatephp.php` lines 10-16) before processing the deletion.

---

### [SEC-R1-019] MEDIUM .htaccess:14 -- .env file not explicitly blocked by FilesMatch

**File:** `.htaccess:14`

The `.htaccess` blocks files matching `\.(sql|psd|md|json|xml|lock|gitignore)$` but does NOT include `.env` in this list. While a separate rule blocks all dotfiles (`^\.`), if the Apache configuration has `AllowOverride` issues or mod_rewrite interactions, the `.env` file containing database credentials could be exposed.

The `.env` file is not present in the repository (only `.env.example` exists), but on the VPS it must exist at `/var/www/html/.env` for the application to function.

**Impact:** If the dotfile rule fails or is bypassed, database credentials in `.env` would be exposed to any web visitor.

**Remediation:** Explicitly add `env` to the FilesMatch pattern: `\.(sql|psd|md|json|xml|lock|gitignore|env)$`. Better yet, move `.env` outside the webroot entirely.

---

## LOW

### [SEC-R1-020] LOW includes/constantesBase.php:53-55 -- Admin password hash is hardcoded in source code

**File:** `includes/constantesBase.php:53-55`

```php
if (!defined('ADMIN_PASSWORD_HASH')) {
    define('ADMIN_PASSWORD_HASH', '$2y$10$PibWl.r/3LA3HMwuSchD0et2Mjkac0D6kzuwxvOAbSqUTBf7zhGES');
}
```

The bcrypt hash of the admin password is committed to the Git repository. While bcrypt is slow to brute-force, the hash is now permanently in version history. Anyone with repository access can attempt offline cracking.

**Impact:** If the admin password is weak (short, dictionary word), it can be cracked offline. The hash is in the Git history forever.

**Remediation:** Move `ADMIN_PASSWORD_HASH` to the `.env` file. Rotate the admin password. Consider removing the hash from Git history with `git filter-branch` or BFG.

---

### [SEC-R1-021] LOW admin/index.php:23, moderation/index.php:22 -- No session timeout for admin sessions

**File:** `admin/index.php:23`, `moderation/index.php:22`

Once `$_SESSION['motdepasseadmin'] = true` is set, it remains valid for the entire session lifetime (up to 1 hour via `gc_maxlifetime`). There is no separate timeout for admin sessions and no mechanism to log out of the admin panel without destroying the entire session.

**Impact:** If a user walks away from their browser after accessing the admin panel, anyone with physical access can use the admin functions for up to 1 hour.

**Remediation:** Add a separate admin session timestamp (e.g., `$_SESSION['admin_login_time']`) and enforce a shorter timeout (e.g., 15 minutes) for admin operations.

---

### [SEC-R1-022] LOW admin/listesujets.php:8 -- Charset mismatch (iso-8859-1 vs UTF-8)

**File:** `admin/listesujets.php:8`

```html
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
```

This page declares `iso-8859-1` charset while the rest of the application uses `UTF-8`. The database connection is set to `utf8` (connexion.php:20). Charset mismatches can lead to encoding-based XSS attacks where multi-byte characters are interpreted differently by the browser and the server.

**Impact:** Low in this context because the page uses `htmlspecialchars()` with `ENT_QUOTES, 'UTF-8'` for output. However, the mismatch could cause display corruption for non-ASCII characters and theoretically enable encoding attacks.

**Remediation:** Change to `charset=utf-8` to match the rest of the application.

---

### [SEC-R1-023] LOW includes/basicprivatephp.php:255 -- Email boundary uses md5(rand()), which is predictable

**File:** `includes/basicprivatephp.php:255`

```php
$boundary = "-----=" . md5(rand());
```

The MIME boundary in season-end emails uses `md5(rand())`. PHP's `rand()` uses a predictable PRNG (linear congruential generator). While this is just an email boundary delimiter and not a security token, it follows an insecure pattern.

**Impact:** Negligible -- email boundaries do not need to be cryptographically secure. However, it demonstrates use of weak random number generation.

**Remediation:** Use `bin2hex(random_bytes(16))` or leave as-is given the negligible risk.

---

### [SEC-R1-024] LOW voter.php:43 -- Vote response stored as string type ('s') but value is intval()

**File:** `voter.php:43`

```php
dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, ?, ?)', 'sis', $login, $sondageId, $reponse);
```

The `$reponse` variable is `intval()` converted at line 23 but stored with type `'s'` (string) in the prepared statement. While this is not a security vulnerability (the value is sanitized), it represents a type mismatch that could cause unexpected behavior if the database column expects an integer.

**Impact:** None -- `intval()` ensures the value is numeric. This is a code quality issue.

**Remediation:** Use `'sii'` type string instead of `'sis'` to match the actual data types.

---

### [SEC-R1-025] LOW includes/env.php:11 -- putenv() can be exploited if .env file contains malicious variable names

**File:** `includes/env.php:11`

```php
if (!getenv($name)) putenv("$name=$value");
```

The `.env` parser does not validate variable names. If an attacker can write to the `.env` file, they could set arbitrary environment variables (e.g., `LD_PRELOAD`, `PATH`, `HOME`) that could affect system behavior. The `getenv()` check prevents overwriting existing variables but does not prevent setting new dangerous ones.

**Impact:** Requires write access to `.env` file, which implies the attacker already has significant server access. The risk is from defense-in-depth perspective.

**Remediation:** Whitelist expected variable names (DB_HOST, DB_USER, DB_PASS, DB_NAME) and reject any others.

---

### [SEC-R1-026] LOW comptetest.php:22 -- inscrire() function called with predictable password for visitor accounts

**File:** `comptetest.php:22`

```php
inscrire("Visiteur" . $visitorNum, "Visiteur" . $visitorNum, "Visiteur" . $visitorNum . "@tvlw.com");
```

The `inscrire()` function at `includes/player.php:27` stores the password using `password_hash()`. However, the password passed in is the same as the username ("Visiteur42"). Lines 26-28 then immediately update the password with a bcrypt hash of the same predictable value. This redundant double-write is wasteful but the security issue is the predictable password itself (covered in SEC-R1-008).

**Impact:** See SEC-R1-008.

**Remediation:** See SEC-R1-008.

---

### [SEC-R1-027] LOW includes/basicprivatephp.php:287 -- Season reset emails contain unescaped player login in HTML body

**File:** `includes/basicprivatephp.php:248`

```php
$message_html = "<html><head></head><body>Bonjour " . $donnees['login'] . " ! <b>" . $vainqueurManche . "</b>...
```

Player logins (both the recipient and the winner) are interpolated directly into the HTML email body without `htmlspecialchars()`. While login validation (`preg_match('/^[a-zA-Z0-9_]{3,20}$/')`) prevents most XSS characters, the validation occurs only at registration. If the login validation were ever relaxed, this would become an XSS vector in email clients.

**Impact:** Low due to current login validation. Defense-in-depth issue.

**Remediation:** Apply `htmlspecialchars()` to `$donnees['login']` and `$vainqueurManche` in both the HTML and text email bodies.

---

## Positive Observations

The following security controls were found to be well-implemented:

1. **SQL Injection Prevention:** All database queries use prepared statements via `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, `dbExecute()`. No raw string interpolation into SQL was found.

2. **CSRF Protection:** The `csrf.php` module uses cryptographically random tokens (`random_bytes(32)`), constant-time comparison (`hash_equals()`), and is consistently applied across POST forms. All admin destructive operations require CSRF tokens.

3. **Session Token Binding:** Authenticated sessions are bound to a database-stored `session_token` verified on every private page load. Session IDs are regenerated on login and periodically (every 30 minutes).

4. **Password Hashing:** New passwords use `password_hash(PASSWORD_DEFAULT)` (bcrypt). MD5 passwords are auto-upgraded on successful login. The admin password uses a pre-computed bcrypt hash.

5. **Rate Limiting:** Login (10/5min), registration (3/hour), visitor creation (3/5min), API (60/60s), and admin login (5/5min) all have rate limiting.

6. **XSS Prevention:** The `antiXSS()` function correctly uses `htmlspecialchars(ENT_QUOTES, 'UTF-8')`. Output encoding is applied consistently in admin pages, user profiles, and forum displays.

7. **File Upload Security:** Avatar uploads validate extension whitelist, MIME type via `finfo`, `getimagesize()`, dimensions, and file size. Filenames are randomized.

8. **Security Headers:** CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, and X-XSS-Protection are set via `.htaccess`.

9. **Input Validation:** The `validation.php` module provides `validateLogin()`, `validateEmail()`, `validatePositiveInt()`, and `validateRange()` functions used at registration.

10. **Session Hardening:** `session_init.php` sets httponly, samesite=Lax, strict_mode, and manages session lifetime.

---

## Priority Remediation Order

1. **Immediate (CRITICAL):** SEC-R1-001, SEC-R1-002 -- Fix admin session hardening and add CSRF to login forms
2. **Short-term (HIGH):** SEC-R1-003, SEC-R1-004, SEC-R1-005 -- Separate admin session, fix timing oracle, fix bcrypt upgrade
3. **Short-term (HIGH):** SEC-R1-006, SEC-R1-007, SEC-R1-008, SEC-R1-009 -- Remove GET voting, fix strip_tags XSS, fix visitor passwords, remove legacy session code
4. **Medium-term (MEDIUM):** SEC-R1-010 through SEC-R1-019 -- Input validation, rate limiter hardening, .env protection
5. **Long-term (LOW):** SEC-R1-020 through SEC-R1-027 -- Code quality and defense-in-depth improvements
