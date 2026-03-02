# AUTH + SESSION + INPUT VALIDATION Audit Findings

**Audit Date:** 2026-03-02
**Auditor:** Claude Opus 4.6 (Code Review Agent)
**Scope:** Authentication flows, session management, and input validation across all PHP files
**Files Reviewed:** 43 root PHP files + 15 include files

---

## Summary

| Domain   | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| AUTH     | 1        | 3    | 3      | 2   | 9     |
| SESSION  | 1        | 2    | 2      | 1   | 6     |
| INPUT    | 1        | 4    | 5      | 3   | 13    |
| **Total**| **3**    | **9**| **10** | **6**| **28**|

---

## AUTHENTICATION FINDINGS

### FINDING-AUTH-001: CRITICAL - No session_regenerate_id() on login

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php` lines 56-67

**Description:**
When a user successfully authenticates, the code sets session variables and redirects but never calls `session_regenerate_id(true)`. This is a textbook session fixation vulnerability. An attacker can set a known session ID (e.g. via a crafted URL or XSS on a pre-auth page), wait for the victim to log in, and then hijack the session using the pre-set ID.

```php
// Line 56-67 - No session_regenerate_id() call
if ($authenticated) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['login'] = $loginInput;
    $_SESSION['mdp'] = $storedHash;
    // ... redirect, but NO session_regenerate_id(true)
    header('Location: constructions.php');
    exit();
}
```

Note: `basicprivatephp.php` line 26-29 has a `_regenerated` flag that calls `session_regenerate_id(true)` on first access, but this is too late -- the vulnerable window exists between login and the first private page load.

**Remediation:**
Add `session_regenerate_id(true)` immediately after successful authentication in `basicpublicphp.php`, before setting any session variables:

```php
if ($authenticated) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_regenerate_id(true); // Prevent session fixation
    $_SESSION['login'] = $loginInput;
    $_SESSION['mdp'] = $storedHash;
    // ...
}
```

---

### FINDING-AUTH-002: HIGH - Password hash stored in session

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php` line 61
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` line 19

**Description:**
The bcrypt password hash is stored in `$_SESSION['mdp']` and used on every private page load to verify the session is still valid by comparing it to the database value. While bcrypt hashes are not directly reversible, storing the hash in the session:
1. Exposes it in session storage files (typically `/tmp/sess_*` on the server)
2. Creates a secondary authentication channel (session compromise = hash compromise)
3. The comparison on line 19 of basicprivatephp.php uses `!==` (strict inequality), which is not timing-safe for string comparison

```php
// basicpublicphp.php line 61
$_SESSION['mdp'] = $storedHash;

// basicprivatephp.php line 19 - non-timing-safe comparison
if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
```

**Remediation:**
Replace the password hash in session with a random session token:
1. On login, generate a random token: `$token = bin2hex(random_bytes(32));`
2. Store the token both in `$_SESSION['session_token']` and in a `session_token` column in the `membre` table
3. In `basicprivatephp.php`, compare using `hash_equals($row['session_token'], $_SESSION['session_token'])`

---

### FINDING-AUTH-003: HIGH - No rate limiting on admin/moderation login

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` lines 10-16
**File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php` lines 8-11

**Description:**
Both the admin and moderation panels accept password submissions without any rate limiting. The main login page has rate limiting (10 attempts per 5 minutes), but the admin panel has none. Since both panels use a single shared password (`ADMIN_PASSWORD_HASH`), an attacker can brute-force the admin password at unlimited speed.

```php
// admin/index.php lines 10-16 - No rate limit check
if (isset($_POST['motdepasseadmin'])) {
    if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
        $_SESSION['motdepasseadmin'] = true;
        logInfo('ADMIN', 'Admin login successful');
    } else {
        logWarn('ADMIN', 'Admin login failed');
    }
}
```

**Remediation:**
Add rate limiting before password verification:

```php
if (isset($_POST['motdepasseadmin'])) {
    if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'admin_login', 5, 600)) {
        die('Trop de tentatives. Reessayez dans 10 minutes.');
    }
    // ... existing password_verify logic
}
```

---

### FINDING-AUTH-004: HIGH - No CSRF protection on admin/moderation login forms

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` lines 176-180
**File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php` lines 15-19

**Description:**
The admin and moderation login forms do not include a CSRF token. While CSRF on login forms is lower risk than on state-changing actions, it enables "login CSRF" attacks where an attacker forces a victim to log into the attacker's admin session. Additionally, the admin panel does check CSRF for admin actions (line 20-22 of admin/index.php), but that check relies on a CSRF token generated in a session that was established without CSRF protection on the login step itself.

```html
<!-- admin/index.php lines 176-180 -->
<form action="index.php" method="post">
    <label for="motdepasseadmin">Mot de passe : </label>
    <input type="password" name="motdepasseadmin" id="motdepasseadmin" />
    <input type="submit" name="valider" value="Valider" />
</form>
```

**Remediation:**
Add `csrfField()` to the login forms and call `csrfCheck()` before `password_verify()`:

```php
if (isset($_POST['motdepasseadmin'])) {
    csrfCheck();
    // ... password_verify logic
}
```

```html
<form action="index.php" method="post">
    <?php echo csrfField(); ?>
    <!-- ... -->
</form>
```

---

### FINDING-AUTH-005: MEDIUM - Visitor account creation lacks rate limiting on GET

**File:** `/home/guortates/TVLW/The-Very-Little-War/comptetest.php` lines 8-26

**Description:**
Visitor account creation is triggered by a simple GET parameter `?inscription`, with only a 60-second cooldown between the last visitor and the new one (line 13). This is not a per-IP rate limit -- it is a global cooldown based on the last visitor's creation time. An attacker can create many visitor accounts by spacing requests 61 seconds apart, or multiple attackers can create accounts simultaneously since the check is against the last visitor, not the requesting IP.

```php
// Line 8-26
if (isset($_GET['inscription'])) {
    $nb = dbFetchOne($base, 'SELECT numerovisiteur FROM statistiques');
    // ...
    $time = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login = ?', 's', $log);
    if ($time && time() - $time['timestamp'] > 60) {
        inscrire("Visiteur" . $nb['numerovisiteur'], ...);
```

Additionally, this is a state-changing action triggered by GET, which violates HTTP method semantics and is vulnerable to CSRF via image tags or link prefetching.

**Remediation:**
1. Add per-IP rate limiting: `rateLimitCheck($_SERVER['REMOTE_ADDR'], 'visitor_create', 3, 3600)`
2. Ideally, change to POST method with CSRF protection

---

### FINDING-AUTH-006: MEDIUM - No session_regenerate_id on visitor account creation

**File:** `/home/guortates/TVLW/The-Very-Little-War/comptetest.php` lines 15-21

**Description:**
When a visitor account is created, the session variables are set directly without calling `session_regenerate_id(true)`. This has the same session fixation risk as FINDING-AUTH-001.

```php
$_SESSION['login'] = ucfirst(mb_strtolower("Visiteur" . $nb['numerovisiteur']));
$_SESSION['mdp'] = $hashedPass;
header('Location: tutoriel.php?deployer=1');
exit();
```

**Remediation:**
Add `session_regenerate_id(true);` before setting session variables.

---

### FINDING-AUTH-007: MEDIUM - MD5 fallback still present in login flow

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php` lines 47-54

**Description:**
The login flow still includes an MD5 fallback for legacy password verification with auto-upgrade to bcrypt. While the auto-upgrade is a good migration strategy, the MD5 comparison on line 48 uses `===` (strict equality), which is not timing-safe. This could theoretically leak hash information via timing side-channels, though the practical risk is low since MD5 hashes are short and the fallback should eventually be removed.

```php
// Line 48 - MD5 fallback with non-timing-safe comparison
elseif (md5($passwordInput) === $storedHash) {
    $authenticated = true;
    $newHash = password_hash($passwordInput, PASSWORD_DEFAULT);
    dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $newHash, $loginInput);
    $storedHash = $newHash;
}
```

**Remediation:**
1. Use `hash_equals(md5($passwordInput), $storedHash)` for the MD5 comparison
2. Plan to remove the MD5 fallback entirely once all users have logged in at least once (monitor via a query: `SELECT COUNT(*) FROM membre WHERE LENGTH(pass_md5) = 32`)

---

### FINDING-AUTH-008: LOW - Admin panel uses single shared password

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php` lines 52-54
**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`
**File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php`

**Description:**
Both the admin and moderation panels authenticate using the same `ADMIN_PASSWORD_HASH` constant. This means:
1. Anyone with the moderation password also has admin access
2. No individual accountability for admin actions
3. Password rotation requires changing the constant for all users simultaneously

**Remediation:**
For a game of this scale, this is acceptable. For improvement, consider adding per-user admin flags in the `membre` table (e.g., `is_admin` and `is_moderator` columns) and using the regular login system with privilege escalation.

---

### FINDING-AUTH-009: LOW - Session not destroyed before redirect on auth failure

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` lines 19-22

**Description:**
When session validation fails (password hash mismatch), `session_destroy()` is called and then a redirect. However, `session_destroy()` does not delete the session cookie -- the browser will send the same session ID on the next request, potentially allowing a new session with the old ID (mitigated by `use_strict_mode=1`).

```php
if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
    session_destroy();
    header('Location: index.php');
    exit();
}
```

**Remediation:**
Before `session_destroy()`, explicitly delete the session cookie:

```php
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
session_destroy();
```

---

## SESSION FINDINGS

### FINDING-SESS-001: CRITICAL - session.cookie_secure disabled

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` line 5

**Description:**
`session.cookie_secure` is set to `0`, meaning session cookies will be transmitted over unencrypted HTTP connections. If a user accesses the site over HTTP (even accidentally), the session cookie is exposed to network sniffers and man-in-the-middle attacks. This is currently expected because HTTPS is not yet configured, but it must be addressed before or immediately after enabling HTTPS.

```php
ini_set('session.cookie_secure', 0);
```

**Remediation:**
Once HTTPS is enabled (Let's Encrypt is ready per deployment notes):
1. Change to `ini_set('session.cookie_secure', 1);`
2. Add HSTS header: `header('Strict-Transport-Security: max-age=31536000; includeSubDomains');`
3. Force HTTPS redirect in Apache vhost or .htaccess

---

### FINDING-SESS-002: HIGH - No SameSite cookie attribute

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` lines 3-7

**Description:**
The session configuration does not set `session.cookie_samesite`. Without this, most modern browsers default to `Lax`, but older browsers may default to `None`, allowing the session cookie to be sent in cross-site requests. This weakens CSRF protection and could enable cross-site request attacks in older browsers.

```php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0);
    ini_set('session.use_strict_mode', 1);
    // Missing: session.cookie_samesite
    session_start();
}
```

**Remediation:**
Add `ini_set('session.cookie_samesite', 'Lax');` before `session_start()`. Use `Strict` if the site does not need to preserve sessions when navigating from external links.

---

### FINDING-SESS-003: HIGH - No session timeout/expiry mechanism

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`

**Description:**
There is no session inactivity timeout. Once a user logs in, their session remains valid indefinitely until they explicitly log out or the server's garbage collector removes the session file. This means:
1. Abandoned sessions on shared computers remain active
2. Stolen session cookies remain valid indefinitely
3. No protection against long-lived session hijacking

**Remediation:**
Add session timeout checking at the top of `basicprivatephp.php`:

```php
$SESSION_TIMEOUT = 7200; // 2 hours
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    header('Location: index.php?erreur=' . urlencode('Session expiree'));
    exit();
}
$_SESSION['last_activity'] = time();
```

---

### FINDING-SESS-004: MEDIUM - Session regeneration only once via flag

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` lines 26-29

**Description:**
Session ID regeneration happens only once per session lifetime, controlled by the `_regenerated` flag. Once set, the session ID is never regenerated again, even after hours/days of activity. Best practice is to regenerate the session ID periodically (e.g., every 30 minutes) to limit the window of opportunity for session hijacking.

```php
if (!isset($_SESSION['_regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['_regenerated'] = true;
}
```

**Remediation:**
Replace the boolean flag with a timestamp:

```php
$REGEN_INTERVAL = 1800; // 30 minutes
if (!isset($_SESSION['_regenerated_at']) || (time() - $_SESSION['_regenerated_at'] > $REGEN_INTERVAL)) {
    session_regenerate_id(true);
    $_SESSION['_regenerated_at'] = time();
}
```

---

### FINDING-SESS-005: MEDIUM - No concurrent session handling

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`

**Description:**
There is no mechanism to limit concurrent sessions per user. A user (or attacker with stolen credentials) can log in from multiple browsers/devices simultaneously without the original session being invalidated. This prevents users from detecting unauthorized access to their accounts.

**Remediation:**
For a game of this scale, this is lower priority. If desired:
1. Store a `session_token` in the `membre` table
2. On login, generate a new token and store it in DB + session
3. In `basicprivatephp.php`, verify the session token matches the DB token
4. A new login automatically invalidates all previous sessions

---

### FINDING-SESS-006: LOW - Session started multiple times in different entry points

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` line 7
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php` line 8
**File:** `/home/guortates/TVLW/The-Very-Little-War/comptetest.php` line 2
**File:** `/home/guortates/TVLW/The-Very-Little-War/classement.php` line 2
**File:** `/home/guortates/TVLW/The-Very-Little-War/historique.php` line 2

**Description:**
`session_start()` is called in multiple places with different session configuration. `basicprivatephp.php` sets `cookie_httponly`, `cookie_secure`, and `use_strict_mode` before `session_start()`, but files like `comptetest.php` and `classement.php` call `session_start()` directly at line 2 without any security configuration. If `session_start()` is called before `basicprivatephp.php` is included (as happens in these files), the security ini_set calls in `basicprivatephp.php` have no effect because the session is already started.

```php
// classement.php line 2 - session started WITHOUT security config
session_start();
$_SESSION['start'] = "start";
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php"); // ini_set calls are too late
```

**Remediation:**
Create a single `session_init.php` file that centralizes all session configuration and `session_start()`. Include it at the top of every entry point instead of calling `session_start()` directly.

---

## INPUT VALIDATION FINDINGS

### FINDING-INPUT-001: CRITICAL - Stored XSS via report content echoed raw

**File:** `/home/guortates/TVLW/The-Very-Little-War/rapports.php` line 28

**Description:**
Report content is echoed directly to the page without any sanitization or escaping. Reports are generated by the combat system and other server-side processes, so the content is typically server-generated HTML. However, if any user-controllable data (e.g., player names, alliance tags) is included in report content without escaping during report creation, it becomes a stored XSS vector.

```php
// Line 28 - Raw echo of database content
echo $rapports['contenu'];
```

The report title on line 26 IS properly escaped with `htmlspecialchars()`, making the inconsistency on line 28 especially notable.

**Remediation:**
Audit all report generation code to verify that content is properly escaped at creation time. If reports legitimately contain HTML (for formatting), use a sanitization library or a whitelist of allowed HTML tags. Alternatively, pass through BBcode rendering:

```php
echo BBcode($rapports['contenu']); // BBcode() calls htmlentities() first
```

Or if HTML is intended:

```php
echo strip_tags($rapports['contenu'], '<br><strong><em><a><img><span><table><tr><td><th><div>');
```

---

### FINDING-INPUT-002: HIGH - Message title echoed without escaping in sent messages

**File:** `/home/guortates/TVLW/The-Very-Little-War/messagesenvoyes.php` line 21

**Description:**
Message titles in the sent messages list are output without `htmlspecialchars()`. While the title is sanitized with `antihtml()` (which calls `htmlspecialchars()`) before storage in `ecriremessage.php` line 9, this relies on input-side sanitization. If any other code path writes message titles without sanitization, this becomes an XSS vector. Defense-in-depth requires output escaping.

```php
// Line 21 - title not escaped on output
echo '<tr><td><a href="messages.php?message='.$message['id'].'">'.$message['titre'].'</a></td>';
```

Compare with `messages.php` line 77 which correctly escapes:
```php
echo '<td><a href="messages.php?message='.$messages['id'].'">'.htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';
```

**Remediation:**
Add `htmlspecialchars()` to the title output:

```php
echo '<tr><td><a href="messages.php?message='.$message['id'].'">'.htmlspecialchars($message['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';
```

---

### FINDING-INPUT-003: HIGH - Message title rendered raw in debutCarte

**File:** `/home/guortates/TVLW/The-Very-Little-War/messages.php` line 28

**Description:**
When viewing a single message, the message title is passed directly to `debutCarte()` without escaping:

```php
debutCarte($messages['titre']);
```

The `debutCarte()` function in `ui_components.php` (line 17-19) outputs the title directly into a `<div class="card-header">` without any escaping. While message titles are sanitized with `antihtml()` at storage time (`ecriremessage.php` line 9), defense-in-depth requires output escaping.

**Remediation:**
```php
debutCarte(htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8'));
```

---

### FINDING-INPUT-004: HIGH - Alliance grade login/name not escaped in HTML output

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php` line 193

**Description:**
Grade names and associated player logins are output directly into HTML without escaping:

```php
echo '<span class="subimportant">' . $grades['nom'] . ' : </span><a href="joueur.php?id=' . $grades['login'] . '">' . $grades['login'] . '</a><br/>';
```

If a grade name contains HTML/JavaScript (set by an alliance admin in `allianceadmin.php`), it will be rendered as HTML. Similarly, while login names are validated as alphanumeric at registration, grade names are not validated.

**Remediation:**
```php
echo '<span class="subimportant">' . htmlspecialchars($grades['nom'], ENT_QUOTES, 'UTF-8') . ' : </span><a href="joueur.php?id=' . htmlspecialchars($grades['login'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($grades['login'], ENT_QUOTES, 'UTF-8') . '</a><br/>';
```

---

### FINDING-INPUT-005: HIGH - Unquoted HTML attribute values in alliance admin

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` lines 356, 367, 400

**Description:**
Option values in `<select>` elements are not quoted, and login names are not escaped:

```php
$options = $options . '<option value=' . $chef1['login'] . '>' . $chef1['login'] . '</option>';
```

While player logins are validated as alphanumeric (3-20 chars), unquoted attribute values can be exploited if the validation is bypassed at any point. The unquoted `value=` attribute allows attribute injection if the login contains spaces (which current validation prevents, but defense-in-depth requires quoting).

**Remediation:**
Quote attribute values and escape output:

```php
$options .= '<option value="' . htmlspecialchars($chef1['login'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($chef1['login'], ENT_QUOTES, 'UTF-8') . '</option>';
```

---

### FINDING-INPUT-006: MEDIUM - Historic archive data echoed raw

**File:** `/home/guortates/TVLW/The-Very-Little-War/historique.php` lines 84-91, 130-138, 170-173

**Description:**
Archive data retrieved from the `parties` table is parsed by `explode()` and echoed directly to the page without any escaping. This data includes player names, alliance tags, and war descriptions that were stored during season resets:

```php
// Lines 84-91
<td><a href="joueur.php?id=<?php echo $valeurs[0];?>"><?php echo $valeurs[0]; ?></a></td>
<td><?php echo $valeurs[1]; ?></td>
<td><?php echo $valeurs[2]; ?></td>
// ... etc
```

The archive data is server-generated during season reset (`basicprivatephp.php` lines 135-164), but player names and alliance tags in the data are not escaped at storage time. A player with a malicious name (if validation was bypassed) would have persistent XSS in the archive pages.

**Remediation:**
Escape all values on output:

```php
<td><?php echo htmlspecialchars($valeurs[0], ENT_QUOTES, 'UTF-8'); ?></td>
```

Apply to all `$valeurs[N]` outputs across all three sub-sections.

---

### FINDING-INPUT-007: MEDIUM - classement.php appends $_GET['clas'] to URLs unescaped

**File:** `/home/guortates/TVLW/The-Very-Little-War/classement.php` lines 237, 503

**Description:**
The `$_GET['clas']` value is appended to form action URLs without escaping:

```php
// Line 237
$plus = '&clas='.$_GET['clas'];
// Used in line 241:
item(['form' => ['classement.php?sub=0'.$plus, "rechercher"], ...]);
```

While `$_GET['clas']` is passed through `antihtml()` on line 15, which calls `htmlspecialchars()`, and then through `mysqli_real_escape_string()` with `addslashes()`, the resulting value is placed into an HTML attribute (the form action URL). The sanitization order is wrong: `mysqli_real_escape_string` adds backslashes that are not appropriate for HTML context, and the value should be `urlencode()`-ed for URL context.

**Remediation:**
Use `urlencode()` for URL parameters and `htmlspecialchars()` for HTML attribute context:

```php
$plus = '&clas=' . urlencode($_GET['clas']);
```

Or better, use `intval()` since `clas` should always be an integer:

```php
$plus = '&clas=' . intval($_GET['clas']);
```

---

### FINDING-INPUT-008: MEDIUM - Forum post content stored raw without antiXSS

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php` line 21
**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php` line 31

**Description:**
Forum post content is inserted into the database without any sanitization. The content is user-supplied `$_POST['contenu']`:

```php
// sujet.php line 21
dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)', 'issi', $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);

// listesujets.php line 31
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
```

SQL injection is prevented by prepared statements, but the raw content is stored without HTML entity encoding. This is mitigated by the fact that `BBcode()` calls `htmlentities()` before rendering (line 317 of bbcode.php), but the title in `listesujets.php` line 31 is also stored raw and may be rendered without BBcode processing.

**Remediation:**
The current architecture of "store raw, sanitize on output via BBcode()" is acceptable if consistently applied. Verify that all rendering paths for forum content go through `BBcode()`. For titles specifically, ensure `htmlspecialchars()` is applied on output wherever titles are displayed.

---

### FINDING-INPUT-009: MEDIUM - Message content stored without output encoding

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php` line 11

**Description:**
Message content is stored using `mysqli_real_escape_string()` but not HTML-encoded:

```php
$_POST['contenu'] = mysqli_real_escape_string($base, $_POST['contenu']);
```

The message title IS HTML-encoded on line 9 (`antihtml($_POST['titre'])`), but the content is not. Since `dbExecute` uses prepared statements for the actual INSERT (line 17), the `mysqli_real_escape_string` is redundant and the content goes into the DB with its original HTML. Messages are rendered via `BBcode()` in `messages.php` line 30, which applies `htmlentities()`, so this is mitigated at display time.

**Remediation:**
Since prepared statements handle SQL escaping, remove the redundant `mysqli_real_escape_string()` call and rely on output encoding via `BBcode()`. For consistency, apply the same pattern as titles -- or better, store raw and ensure all output goes through `BBcode()`:

```php
// Remove the redundant escaping:
// $_POST['contenu'] = mysqli_real_escape_string($base, $_POST['contenu']);
// The prepared statement in dbExecute handles SQL escaping
```

---

### FINDING-INPUT-010: MEDIUM - voter.php stores $_GET['reponse'] without sanitization

**File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php` line 26

**Description:**
The poll response value is taken from `$_GET['reponse']` and stored directly in the database without any validation or sanitization:

```php
$reponse = $_GET['reponse'];
// ...
dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, ?, ?)', 'sis', $login, $sondageId, $reponse);
```

SQL injection is prevented by the prepared statement, but:
1. No validation that `$reponse` is a valid poll option
2. No CSRF protection (no `csrfCheck()` call) -- this is a state-changing GET request
3. The stored value is not sanitized, so if it is displayed elsewhere, XSS is possible

**Remediation:**
1. Add CSRF check or convert to POST
2. Validate the response against known poll options
3. Apply `antihtml()` before storage or ensure output encoding where responses are displayed

---

### FINDING-INPUT-011: LOW - Page number validation uses weak regex

**File:** `/home/guortates/TVLW/The-Very-Little-War/rapports.php` line 43
**File:** `/home/guortates/TVLW/The-Very-Little-War/messages.php` line 45
**File:** `/home/guortates/TVLW/The-Very-Little-War/classement.php` lines 115, 266, 399, 476

**Description:**
Page number validation uses `preg_match("#\d#", $_GET['page'])` which checks if the string *contains* a digit, not that it *is* a number. The value `"1<script>alert(1)</script>"` would pass this check. However, the values are used in LIMIT clauses of prepared statements with `'ii'` type binding, so they are cast to integers, mitigating the practical risk.

```php
if (isset($_GET['page']) AND $_GET['page'] <= $nombreDePages AND $_GET['page'] > 0 AND preg_match("#\d#",$_GET['page']))
```

**Remediation:**
Use strict integer validation:

```php
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1 || $page > $nombreDePages) {
    $page = 1;
}
```

---

### FINDING-INPUT-012: LOW - antiXSS function applies double escaping

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php` lines 327-335

**Description:**
The `antiXSS()` function applies three layers of escaping: `htmlspecialchars()`, `addslashes()`, and `mysqli_real_escape_string()`. When used with prepared statements (which handle SQL escaping), the SQL escaping is redundant and causes double-encoding:

```php
function antiXSS($phrase, $specialTexte = false) {
    global $base;
    if ($specialTexte) {
        return mysqli_real_escape_string($base, antihtml($phrase));
    } else {
        return mysqli_real_escape_string($base, addslashes(antihtml(trim($phrase))));
    }
}
```

Values processed by `antiXSS()` and then passed to prepared statements get SQL-escaped twice (once by `antiXSS`, once by the prepared statement driver), resulting in visible backslashes in displayed data.

**Remediation:**
Since all database operations now use prepared statements, simplify `antiXSS()` to only do HTML sanitization:

```php
function antiXSS($phrase, $specialTexte = false) {
    if ($specialTexte) {
        return antihtml($phrase);
    } else {
        return antihtml(trim($phrase));
    }
}
```

This is a significant refactor that should be done carefully with testing, as many files depend on `antiXSS()`.

---

### FINDING-INPUT-013: LOW - Date input not validated in compte.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php` (vacation date handling)

**Description:**
The vacation date input is parsed using `explode()` without validating the date format. While the resulting values are passed to database queries via prepared statements, invalid date parts could cause unexpected behavior in date arithmetic.

**Remediation:**
Validate the date format before processing:

```php
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'])) {
    $parts = explode('-', $_POST['date']);
    if (checkdate($parts[1], $parts[2], $parts[0])) {
        // Process valid date
    }
}
```

---

## Cross-Cutting Concerns

### Positive Findings (No Finding Number)

The following security measures are properly implemented:

1. **Prepared statements**: All SQL queries use parameterized queries via `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, `dbExecute()`, and `dbCount()`. No raw SQL injection vectors found.

2. **CSRF protection**: All state-changing POST actions on authenticated pages include `csrfCheck()` and `csrfField()`. The CSRF implementation uses `random_bytes(32)` and `hash_equals()` for timing-safe comparison.

3. **BBcode XSS protection**: The `BBcode()` function calls `htmlentities($text, ENT_QUOTES, 'UTF-8')` as its first operation (bbcode.php line 317), ensuring stored forum/message content is HTML-escaped before BBcode tags are processed. The BBcode regex patterns use whitelisted values for colors, and URLs are restricted to http/https protocols.

4. **Login rate limiting**: The main login form has rate limiting (10 attempts per 5 minutes per IP).

5. **Password hashing**: bcrypt via `password_hash(PASSWORD_DEFAULT)` with automatic MD5 upgrade.

6. **File upload security**: `compte.php` validates file uploads with extension whitelist, MIME type checking via `finfo_file()`, `getimagesize()` validation, and random filename generation.

7. **ORDER BY whitelisting**: `classement.php` uses switch statements to whitelist sort columns, preventing SQL injection through ORDER BY clauses.

8. **Registration validation**: `inscription.php` uses `validateLogin()` (alphanumeric 3-20 chars) and `validateEmail()` (filter_var).

---

## Priority Remediation Plan

### Immediate (Before HTTPS deployment)

| Finding | Action |
|---------|--------|
| AUTH-001 | Add `session_regenerate_id(true)` on login |
| SESS-001 | Set `cookie_secure=1` when HTTPS is enabled |
| SESS-002 | Add `session.cookie_samesite=Lax` |
| INPUT-001 | Audit report content generation; add escaping on output |

### Short-term (Next deployment cycle)

| Finding | Action |
|---------|--------|
| AUTH-002 | Replace password hash in session with random token |
| AUTH-003 | Add rate limiting to admin/moderation login |
| AUTH-004 | Add CSRF to admin/moderation login forms |
| SESS-003 | Implement session timeout |
| INPUT-002 | Add htmlspecialchars to messagesenvoyes.php line 21 |
| INPUT-003 | Escape message title in messages.php debutCarte call |
| INPUT-004 | Escape grade names/logins in alliance.php |
| INPUT-005 | Quote and escape option values in allianceadmin.php |

### Medium-term (Planned maintenance)

| Finding | Action |
|---------|--------|
| AUTH-005 | Add per-IP rate limiting to visitor creation |
| AUTH-006 | Add session_regenerate_id to comptetest.php |
| AUTH-007 | Remove MD5 fallback after all users migrated |
| SESS-004 | Implement periodic session regeneration |
| SESS-006 | Centralize session initialization |
| INPUT-006 | Escape archive data in historique.php |
| INPUT-007 | Use intval for classement.php clas parameter |
| INPUT-008 | Verify all forum content goes through BBcode() |
| INPUT-009 | Remove redundant mysqli_real_escape_string in ecriremessage.php |
| INPUT-010 | Add CSRF and validation to voter.php |
| INPUT-011 | Use intval for page numbers |
| INPUT-012 | Simplify antiXSS function |
| INPUT-013 | Validate date format in compte.php |

---

*End of AUTH + SESSION + INPUT VALIDATION audit findings.*
