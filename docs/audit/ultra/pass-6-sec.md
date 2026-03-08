# Pass 6 Security Audit

**Date:** 2026-03-08
**Scope:** All PHP files in root, includes/, admin/, moderation/
**Auditor:** Automated security agent (Pass 6 — iterative, post-remediation)

---

## Findings

### SEC-P6-001 [HIGH] moderation/index.php missing IP binding — session theft viable

**File:** `moderation/index.php:17-19`

**Description:**
`admin/index.php` stores `$_SESSION['admin_ip']` at login and validates it on every request via `admin/redirectionmotdepasse.php:18`. The equivalent moderation panel (`moderation/index.php`) does **not** store or validate the IP after successful login. If a moderator's session cookie is stolen (e.g., via network interception or MITM — both more likely while HTTPS is pending), an attacker can replay the session from a different IP with no revocation.

**Code:**
```php
// moderation/index.php:17-19 — no admin_ip stored after auth
} elseif (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
    session_regenerate_id(true);
    $_SESSION['motdepasseadmin'] = true;
    // MISSING: $_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR'];
```

Compare with `admin/index.php:24` which correctly stores `$_SESSION['admin_ip']`, and `admin/redirectionmotdepasse.php:18` which validates it on every sub-page request.

`moderation/mdp.php` (the guard for sub-pages ip.php) has no IP check at all:
```php
// moderation/mdp.php:5-8
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}
```

**Fix:** After `session_regenerate_id(true)` in `moderation/index.php`, add `$_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';`. In `moderation/mdp.php`, after the auth check, add IP validation identical to `admin/redirectionmotdepasse.php:15-23`.

---

### SEC-P6-002 [HIGH] moderation/index.php and admin/index.php share the same password hash and session key — moderator login grants full admin access

**File:** `moderation/index.php:17`, `admin/index.php:21`

**Description:**
Both panels authenticate against `ADMIN_PASSWORD_HASH` and set `$_SESSION['motdepasseadmin'] = true`. The session name differs (`TVLW_MOD` vs `TVLW_ADMIN`), so the sessions are technically isolated. However, the two panels are functionally equivalent in privilege: anyone with the moderation password gains the same power as the admin. More importantly, both panels use the same static password — there is no way to grant moderation access without giving full admin power, and no way to revoke mod access without changing the shared password (which also locks out the admin).

This was flagged in previous audit rounds but does not appear to have been mitigated. The session name isolation prevents a mod session cookie from being used on the admin panel, but the password is still shared.

**Code:**
```php
// moderation/index.php:17
} elseif (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {

// admin/index.php:21
} elseif (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
```

**Fix:** Use a separate `MOD_PASSWORD_HASH` constant for the moderation panel, or — better — migrate to the `moderateur` flag in the `membre` table (which `moderationForum.php` already uses as a role-based model), eliminating password-based mod auth entirely.

---

### SEC-P6-003 [MEDIUM] ecriremessage.php [all] broadcast relies on a `role` column that may not exist in the schema

**File:** `ecriremessage.php:42`

**Description:**
The [all] admin broadcast feature checks `membre.role = 'admin'` to authorize a server-wide message to every player. The `role` column is not defined in any migration file visible in the codebase, and no other PHP file references it. If the column does not exist, `dbCount()` returns 0 and `$isAdmin` is always `false`, meaning the feature is silently broken and nobody can send a global broadcast. More critically, if an attacker can insert a row into `membre` with `role='admin'` (e.g., via a SQL injection elsewhere), they gain the ability to message every player.

**Code:**
```php
$isAdmin = (dbCount($base, "SELECT COUNT(*) FROM membre WHERE login = ? AND role = 'admin'", 's', $_SESSION['login']) > 0);
```

**Fix:** Either (a) verify the `role` column exists and is properly access-controlled, (b) replace this check with a constant `ADMIN_LOGIN` comparison (`$_SESSION['login'] === ADMIN_LOGIN`), or (c) remove the [all] broadcast if it is unused. Also add a query-error check: if `dbCount()` returns 0 due to a missing column rather than a legitimate denial, the failure mode is silent.

---

### SEC-P6-004 [MEDIUM] Rate limiter uses file `mtime` for expiry but only writes on allowed requests — stale entries survive indefinitely after the window if no new request occurs

**File:** `includes/rate_limiter.php:22-26`

**Description:**
The probabilistic garbage collector (1% of calls) checks `filemtime($file) < time() - $maxWindow` where `$maxWindow` is hardcoded to 3600 seconds. However, the rate limit file is only written when a request is **allowed** (line 47: `$attempts[] = $now; file_put_contents(…)`). A rate-limited key that has no new attempts after the window expires will keep its stale file indefinitely — the GC will eventually clean it but only after `filemtime` ages past 3600 seconds from the last *write*, not from the last *read*. This creates a subtle issue where:

1. A key is rate-limited at T=0.
2. No further requests come until T=7200 (past the 3600s GC threshold from last write).
3. GC deletes the file.
4. A new attack at T=7200 starts a fresh window — correct behavior.

However, the `$maxWindow = 3600` hardcoded GC threshold is less than some rate limit windows (e.g., admin login uses `RATE_LIMIT_ADMIN_WINDOW`). If `RATE_LIMIT_ADMIN_WINDOW > 3600`, the GC will delete the file before the window expires, allowing bypassing of the rate limit entirely for long-window actions.

**Code:**
```php
// includes/rate_limiter.php:21-26
if (mt_rand(1, 100) === 1) {
    $maxWindow = 3600; // max rate limit window in seconds
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (filemtime($file) < time() - $maxWindow) {
            @unlink($file);
        }
    }
}
```

**Fix:** Set `$maxWindow` to a value larger than any configured rate limit window (e.g., `max(RATE_LIMIT_LOGIN_WINDOW, RATE_LIMIT_ADMIN_WINDOW, RATE_LIMIT_REGISTER_WINDOW, …)` or simply a safe constant like 86400). Alternatively, store the window duration inside the JSON file and use it for GC decisions.

---

### SEC-P6-005 [MEDIUM] Profile image upload uses `uniqid()` — predictable filename allows overwrite timing attack

**File:** `compte.php:164`

**Description:**
Uploaded profile images are renamed using `uniqid('avatar_')`, which is based on `microtime()` and is deterministic and guessable to within ~1 microsecond. While the attacker cannot overwrite another player's file (each upload replaces the *current* player's `image` column with the new filename), a race-condition window exists: between `move_uploaded_file()` (line 165) and the `UPDATE autre SET image = ?` (line 166), the old file is orphaned and the new filename is predictable. More practically, `uniqid()` filenames in PHP are known to be non-cryptographic and predictable in time-sharing environments. If the attacker can enumerate uploaded files (e.g., via directory listing if misconfigured), they can discover avatar filenames for all users.

**Code:**
```php
$fichier = uniqid('avatar_') . '.' . $extension;
move_uploaded_file($_FILES['photo']['tmp_name'], $dossier . $fichier);
```

**Fix:** Replace `uniqid()` with `bin2hex(random_bytes(16))` for a cryptographically random filename. This is a low-effort change: `$fichier = bin2hex(random_bytes(16)) . '.' . $extension;`

---

### SEC-P6-006 [MEDIUM] Vacation mode bypass via POST to vacation-allowed pages

**File:** `includes/basicprivatephp.php:90-102`

**Description:**
The vacation allowlist checks `basename($_SERVER['PHP_SELF'])` against a static list of safe pages and redirects if the page is not in the list. However, `compte.php` is in the allowlist (implicitly — it is the page where vacation is activated) and it processes `$_POST['changermdpactuel']`, `$_POST['changermail']`, `$_POST['changerdescription']`, and file uploads even when a player is on vacation. These mutations (password change, email change, description change, photo upload) are arguably acceptable since they are account management. However, `$_POST['verification']` + `$_POST['oui']` — account self-deletion — is also processed on `compte.php` while vacation is active. A player can delete their account while on vacation mode, which may leave orphaned records (e.g., in `actionsattaques` if vacation did not properly check for in-flight attacks before deletion).

More significantly: `alliance.php` is also in the allowlist. `alliance.php` processes POST actions including `quitter` (leave alliance), `accepter`/`refuser` invitations, `augmenterDuplicateur`, and `upgradeResearch` — all of which mutate game state. A player on vacation can upgrade alliance research, accept alliance invitations, and leave their alliance.

**Code:**
```php
// includes/basicprivatephp.php:91-101
$vacationAllowedPages = [
    'compte.php', 'regles.php', 'prestige.php', 'maintenance.php',
    'deconnexion.php', 'bilan.php', 'classement.php', 'alliance.php',
    'index.php', 'joueur.php', 'classement.php', 'saison.php',
    'alliance_discovery.php', 'season_recap.php',
];
```

**Fix:** `alliance.php` should be removed from the allowlist (or POST actions within `alliance.php` should check vacation status individually). Account deletion via `compte.php` should add a vacation check. The simplest fix for `alliance.php` is to add a `$joueurEnVac` check at the top of each POST handler within that file.

---

### SEC-P6-007 [LOW] Moderator panel (moderation/index.php) lacks a CSP header

**File:** `moderation/index.php` (entire file)

**Description:**
`admin/index.php` emits a strict CSP header (`Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-...'`). The moderation panel `moderation/index.php` has no CSP header at all. The page renders user-controlled content in one place (`echo $erreur` at line 203) — while `$erreur` is built from `htmlspecialchars()`-escaped strings in this file, third-party JS loaded from `../style/css/templatemo-style.css` (a CSS file at a relative path that may load JS) could introduce XSS vectors. Without a CSP, any inline script or external resource injection has no mitigation.

**Code:**
`moderation/index.php` — no `header("Content-Security-Policy: …")` call anywhere in the file.

**Fix:** Add a CSP header at the top of `moderation/index.php` identical to the one in `admin/index.php`. Also include `cspNonce()` and replace any inline scripts with nonce-tagged scripts.

---

### SEC-P6-008 [LOW] comptetest.php (visitor registration) does not bind session_token to DB after rename — session hijack window

**File:** `comptetest.php:108-112`

**Description:**
When a visitor renames their account, the code sets a new `session_token` in `$_SESSION` and updates it in the DB. However, the rename transaction (lines 76-106) executes **before** the session token is updated (lines 108-111). During the window between the `withTransaction()` commit and the `UPDATE membre SET session_token = ?` call, the DB still contains the old session token. A concurrent request from the same player during this window would find the old token mismatching the new `$_SESSION['session_token']` and get kicked out (logged out). This is a very narrow window but a correctness issue.

More importantly, if the `UPDATE membre SET session_token` at line 111 fails silently (e.g., DB transient error — `dbExecute()` logs errors but does not throw), `$_SESSION['session_token']` contains a value that no DB row agrees with. On the next page load, `basicprivatephp.php` will call `session_destroy()` and redirect the user to `index.php`, effectively logging them out immediately after registration — confusing UX and a potential DoS if reliably triggerable.

**Code:**
```php
// comptetest.php:108-112
$_SESSION['login'] = $newLogin;
$sessionToken = bin2hex(random_bytes(32));
$_SESSION['session_token'] = $sessionToken;
dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $sessionToken, $newLogin);
header("Location: index.php?inscrit=1"); exit;
```

**Fix:** Move the session token update inside the existing `withTransaction()` block so it is atomic with the rename. If the DB update fails, the transaction rolls back and the visitor stays logged in with the original token.

---

### SEC-P6-009 [LOW] comptetest.php email validation uses a weaker regex than inscription.php

**File:** `comptetest.php:62`

**Description:**
`inscription.php` (and `compte.php`) use `validateEmail()` from `includes/validation.php`, which calls `filter_var($email, FILTER_VALIDATE_EMAIL)` — the PHP built-in that follows RFC 5321. `comptetest.php` uses a hand-rolled regex `#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#` which:

- Only accepts lowercase (uppercase domain/local is valid per RFC).
- Restricts TLD to 2–4 characters — rejecting modern TLDs like `.museum`, `.photography`, `.academy` (up to 63 chars per RFC).
- Does not accept `+` in the local part — blocking `user+tag@example.com` which is valid.

A visitor who registered with a valid email via `inscription.php` (e.g., `User@EXAMPLE.COM` or `user@example.photography`) could not complete the visitor→full-account conversion because `comptetest.php` would reject their email.

**Code:**
```php
// comptetest.php:62
} elseif (preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", $_POST['email'])) {
```

**Fix:** Replace with `validateEmail($_POST['email'])` (calls `filter_var(…, FILTER_VALIDATE_EMAIL)`) to match the same validation used everywhere else.

---

### SEC-P6-010 [LOW] rate_limiter.php is susceptible to a filename collision via MD5 — two distinct (identifier, action) pairs could share the same rate limit bucket

**File:** `includes/rate_limiter.php:29`

**Description:**
Rate limit files are keyed by `md5($identifier . '_' . $action)`. MD5 is a 128-bit hash and has no known practical preimage attacks for this use case, but the key space is not collision-free by construction. More concretely: two different `($identifier, $action)` pairs that produce the same MD5 digest would share a rate-limit bucket. This is a theoretical concern, not a practical exploitable vulnerability at current traffic levels.

A more realistic concern: `$identifier` is often `$_SERVER['REMOTE_ADDR']` (IP) and `$action` is a short string. IPv6 addresses include colons; the concatenation `"::1_login"` and `"::1"` with action `"_login"` are different pairs but would collide if the concatenation separator happens to be part of either component. The current code uses `'_'` as separator, but some action names could theoretically contain `_` and some identifiers (player logins) can also contain `_`.

**Code:**
```php
$file = $dir . '/' . md5($identifier . '_' . $action) . '.json';
```

**Fix:** Use a separator that cannot appear in either component, or use `hash('sha256', json_encode([$identifier, $action]))` to make the key injection-proof.

---

### SEC-P6-011 [INFO] session_init.php sets cookie_secure dynamically based on HTTPS detection — non-HTTPS requests during HTTPS→HTTP downgrade will silently send session cookie over HTTP

**File:** `includes/session_init.php:10`

**Description:**
```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```
Until the DNS cutover + certbot deployment completes, the game runs over HTTP and `cookie_secure` is 0 — this is known and accepted. However, once HTTPS is live, if a request arrives via HTTP (e.g., an unredirected bookmark, a CDN that strips HTTPS, or a redirect loop), `cookie_secure` will be 0 for that request and the session cookie will be sent over plaintext. Combined with the pending HSTS configuration, the first-request window could leak session cookies.

**Code:**
```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```

**Fix:** After HTTPS is live and HSTS is deployed, hardcode `ini_set('session.cookie_secure', 1)` rather than relying on runtime detection. Also set `session.cookie_samesite` to `Strict` (currently `Lax`) to prevent cross-site request leakage of the cookie.

---

### SEC-P6-012 [INFO] SECRET_SALT is committed in config.php in plaintext — defeats purpose of IP hashing in logs

**File:** `includes/config.php:20`

**Description:**
```php
define('SECRET_SALT', 'tvlw_audit_salt_2026');
```
The `SECRET_SALT` is used to hash IP addresses before writing them to log files. If the salt is committed to the git repository, anyone with repository access can reverse the IP hashes by testing candidate IPs against the known salt. The config file carries a `// TODO: Load from .env for production` comment acknowledging this.

**Fix:** Move `SECRET_SALT` to `.env` and load it via `loadEnv()`. Add `SECRET_SALT` to `.gitignore` enforcement. Until then, if git history has been pushed to GitHub, rotate the salt to a new value and treat all existing log hashes as potentially reversible.

---

## Summary

Total: **12 findings**

| ID | Severity | Category | File |
|----|----------|----------|------|
| SEC-P6-001 | HIGH | Session hijack / no IP binding | moderation/index.php |
| SEC-P6-002 | HIGH | Privilege conflation / shared password | moderation/index.php, admin/index.php |
| SEC-P6-003 | MEDIUM | Broken auth / missing DB column | ecriremessage.php |
| SEC-P6-004 | MEDIUM | Rate limiter GC bug / window bypass | includes/rate_limiter.php |
| SEC-P6-005 | MEDIUM | Predictable filename / info disclosure | compte.php |
| SEC-P6-006 | MEDIUM | Vacation bypass / state mutation | includes/basicprivatephp.php, alliance.php |
| SEC-P6-007 | LOW | Missing CSP | moderation/index.php |
| SEC-P6-008 | LOW | Session token write outside transaction | comptetest.php |
| SEC-P6-009 | LOW | Weak email regex | comptetest.php |
| SEC-P6-010 | LOW | Rate limiter key separator collision | includes/rate_limiter.php |
| SEC-P6-011 | INFO | cookie_secure dynamic detection | includes/session_init.php |
| SEC-P6-012 | INFO | SECRET_SALT committed to repo | includes/config.php |

**Breakdown:** 0 CRITICAL / 2 HIGH / 4 MEDIUM / 4 LOW / 2 INFO

**No previously-fixed issues were re-opened.** The core SQL injection, CSRF, XSS, bcrypt, session auth, CSP (main app), FOR UPDATE locking, withTransaction, and column whitelist controls are all correctly in place.
