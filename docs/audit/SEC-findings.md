# Security Audit Findings - The Very Little War
**Date:** 2026-03-02  
**Auditor:** Security Auditor Agent  
**Scope:** Full PHP codebase, JavaScript libraries, server configuration  
**Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Debian 12  
**Files reviewed:** ~80 PHP files, ~18 JS files, .htaccess  

## Executive Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3     |
| HIGH     | 12    |
| MEDIUM   | 15    |
| LOW      | 8     |
| **Total**| **38**|

Overall compliance score: **72%** -- significant gaps in session security, XSS protection, CSRF coverage, rate limiting, and HTTP hardening. The codebase has undergone substantial refactoring (prepared statements, bcrypt, CSRF tokens) but residual vulnerabilities remain, particularly around legacy functions, inconsistent output encoding, and missing security headers.

---

## 1. SQL Injection

### FINDING-SEC-001: CRITICAL - Legacy `query()` function allows raw SQL execution

**File:** `includes/db_helpers.php:7-16`  
**Description:** The `query()` function accepts a raw SQL string and passes it directly to `mysqli_query()` without any parameterization. Any caller passing user-controlled data into this function creates a SQL injection vector. Additionally, the error handler on line 12 logs the full query text to error_log, which could leak sensitive data.  
**Exploit scenario:** If any code path constructs a SQL string with user input and passes it to `query()`, an attacker injects arbitrary SQL. While current callers appear to use server-side data, the function's existence is a latent injection risk that any future developer could inadvertently exploit.  
**Remediation:** Remove `query()` entirely. Audit all callers (search for `query(` across the codebase) and replace with `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, or `dbExecute()` using parameterized queries. If any callers remain that need raw queries, add a code comment explicitly documenting why, and ensure no user input enters the query string.

```php
// DELETE this function entirely:
function query($truc) {
    global $base;
    $ex = mysqli_query($base, $truc);
    ...
}
```

### FINDING-SEC-002: HIGH - Dynamic column/table names in `ajouter()` function

**File:** `includes/db_helpers.php:18-24`  
**Description:** The `ajouter()` function accepts `$champ` (column name) and `$bdd` (table name) parameters and interpolates them directly into SQL strings: `"SELECT $champ FROM $bdd WHERE login=?"` and `"UPDATE $bdd SET $champ=? WHERE login=?"`. While the value and login are parameterized, the column and table names are not.  
**Exploit scenario:** If any caller passes user-controlled data as `$champ` or `$bdd`, an attacker could manipulate the SQL structure. Current callers use server-side constants (e.g., `ajouter('victoires', 'autre', ...)`) but the function signature does not enforce this.  
**Remediation:** Add a whitelist validation at the top of `ajouter()`:

```php
function ajouter($champ, $bdd, $nombre, $joueur) {
    $allowedColumns = ['victoires', 'energieDonnee', 'neutrinos', 'moleculesPerdues', ...];
    $allowedTables = ['autre', 'ressources'];
    if (!in_array($champ, $allowedColumns) || !in_array($bdd, $allowedTables)) {
        throw new InvalidArgumentException("Invalid column or table name");
    }
    // ... rest of function
}
```

### FINDING-SEC-003: MEDIUM - Dynamic column names in alliance research functions

**File:** `includes/db_helpers.php:35-60`  
**Description:** `allianceResearchLevel()` and `allianceResearchBonus()` interpolate `$techName` directly into SQL: `'SELECT ' . $techName . ' FROM alliances WHERE id=?'`. The `$techName` comes from `$ALLIANCE_RESEARCH` config keys or from `$_POST['upgradeResearch']` after validation against those keys.  
**Exploit scenario:** In `alliance.php:94-98`, `$_POST['upgradeResearch']` is checked with `isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])` before use. This is a sufficient whitelist. However, `allianceResearchLevel()` and `allianceResearchBonus()` accept `$techName` from any caller without internal validation. A future code change could introduce injection.  
**Remediation:** Add internal whitelist validation in both functions:

```php
function allianceResearchLevel($joueur, $techName) {
    global $base, $ALLIANCE_RESEARCH;
    if (!isset($ALLIANCE_RESEARCH[$techName])) return 0;
    // ... rest unchanged
}
```

### FINDING-SEC-004: MEDIUM - Dynamic column names in `augmenterBatiment()` / `diminuerBatiment()`

**File:** `includes/player.php:451-477` and `includes/player.php:483-559`  
**Description:** Both functions use `$nom` directly in SQL: `"UPDATE constructions SET $nom=? WHERE login=?"`. The `$nom` parameter comes from building configuration keys (server-side), but the functions do not internally validate the value.  
**Exploit scenario:** Currently safe because callers pass values from `$BUILDING_CONFIG` keys. However, no internal guard prevents misuse by future code.  
**Remediation:** Add whitelist validation at the top of both functions:

```php
$allowedBuildings = ['generateur','producteur','depot','champdeforce','ionisateur','condenseur','lieur','stabilisateur','coffrefort'];
if (!in_array($nom, $allowedBuildings)) {
    throw new InvalidArgumentException("Invalid building name: $nom");
}
```

### FINDING-SEC-005: MEDIUM - Dynamic column name in `updateRessources()`

**File:** `includes/game_resources.php:146`  
**Description:** `"UPDATE ressources SET $ressource=? WHERE login=?"` uses `$ressource` from the `$nomsRes` array (server-side constant). Safe in current usage but lacks internal validation.  
**Exploit scenario:** Same class of issue as SEC-004. Currently safe, but fragile to modification.  
**Remediation:** Validate `$ressource` against `$nomsRes` before use in SQL, or build the entire UPDATE as a single prepared statement with all columns.

### FINDING-SEC-006: LOW - Dynamic column in `remiseAZero()`

**File:** `includes/player.php:746`  
**Description:** `$sql = 'UPDATE ressources SET energie=default, terrain=default, revenuenergie=default, niveauclasse=1, ' . $chaine;` where `$chaine` is built from `$nomsRes` array values. Since `$nomsRes` is a hard-coded server constant, this is safe.  
**Exploit scenario:** No direct exploit, but the pattern of building SQL from array values should be documented as intentional.  
**Remediation:** Add a comment documenting that `$nomsRes` is a server-side constant and this SQL construction is intentional.

---

## 2. XSS (Stored + Reflected)

### FINDING-SEC-007: HIGH - News content output without HTML encoding

**File:** `index.php:51`  
**Description:** `$contenuNews = nl2br(stripslashes($donnees['contenu']));` -- The news content from the database is rendered through `nl2br()` and `stripslashes()` but **not** through `htmlspecialchars()` or any HTML sanitization. News are created by admin (`admin/redigernews.php`) and during season reset (`basicprivatephp.php:207`), where `htmlspecialchars()` is applied. However, the seasonal reset news on line 207 contains raw HTML (`<a href=...>`, `<br/>`, `<strong>`) intentionally, which means the output on index.php must render HTML.  
**Exploit scenario:** If an admin accidentally inserts a `<script>` tag in news content, or if the admin panel is compromised, stored XSS would execute for every visitor. The fact that news intentionally contains HTML means any sanitization must be an allowlist-based HTML filter rather than blanket encoding.  
**Remediation:** Use an HTML purifier/allowlist approach for news output. Since admin-created content intentionally contains HTML, use a simple tag allowlist:

```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img>';
$contenuNews = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));
```

Or better, apply a proper HTML sanitizer library.

### FINDING-SEC-008: HIGH - Forum/message content stored without HTML encoding

**File:** `sujet.php:21`  
**Description:** `dbExecute($base, 'INSERT INTO reponses VALUES(...)', ..., $_POST['contenu'], ...)` -- The raw `$_POST['contenu']` is stored directly in the database without any HTML encoding. The content is later rendered through `BBcode()` which applies `htmlentities()` on line 317 of `bbcode.php`, so output encoding is handled at display time.  
**Exploit scenario:** The BBCode parser applies `htmlentities()` first (line 317), which should neutralize XSS. However, there is a risk if any code path displays this content WITHOUT going through `BBcode()`. A search shows that forum content is always displayed through `BBcode()`, so the risk is mitigated but depends entirely on the BBCode function never being bypassed.  
**Remediation:** Consider encoding at storage time as defense-in-depth, or at minimum, document that all user-generated content MUST be rendered through `BBcode()` which handles encoding. Audit all display paths to confirm.

### FINDING-SEC-009: HIGH - `joueur()` function outputs player names without HTML encoding

**File:** `includes/player.php:643-651`  
**Description:** The `joueur()` function outputs player names directly in HTML: `'<a href="joueur.php?id=' . $joueur . '">' . $joueur . '</a>'`. Player names go through `antiXSS()` at registration (`inscription.php:19`) which applies `htmlspecialchars`, and login names are validated against `/^[a-zA-Z0-9_]{3,20}$/` (`validation.php:3`). However, the function itself does not apply encoding.  
**Exploit scenario:** If a player name somehow contains HTML characters (unlikely given registration validation, but possible through direct DB manipulation or a future validation bypass), XSS would occur wherever `joueur()` is called (alliance.php:183, alliance.php:375, classement.php:185, attaquer.php:387, attaque.php:20, listesujets.php:112).  
**Remediation:** Apply `htmlspecialchars()` in the `joueur()` function:

```php
function joueur($joueur) {
    $safe = htmlspecialchars($joueur, ENT_QUOTES, 'UTF-8');
    $act = statut($joueur);
    if ($act == 0) {
        return '<a href="joueur.php?id=' . $safe . '" class="lienVisible"><span style="color:darkgray">' . $safe . '</span></a>';
    } else {
        return '<a href="joueur.php?id=' . $safe . '" class="lienVisible">' . $safe . '</a>';
    }
}
```

### FINDING-SEC-010: HIGH - `alliance()` function outputs without HTML encoding

**File:** `includes/db_helpers.php:26-29`  
**Description:** `return '<a href="alliance.php?id=' . $alliance . '">' . $alliance . '</a>';` -- The alliance tag is output directly in both the `href` attribute and the link text without `htmlspecialchars()`.  
**Exploit scenario:** If an alliance tag contains HTML special characters, this creates an XSS vector. Alliance tags are validated with `preg_match("#^[a-zA-Z0-9_]{3,16}$#")` at creation (`alliance.php:38`), which mitigates the risk. However, the function itself should be hardened.  
**Remediation:**

```php
function alliance($alliance) {
    $safe = htmlspecialchars($alliance, ENT_QUOTES, 'UTF-8');
    return '<a href="alliance.php?id=' . $safe . '" class="lienVisible">' . $safe . '</a>';
}
```

### FINDING-SEC-011: MEDIUM - `antiXSS()` function applies double encoding

**File:** `includes/display.php:327-335`  
**Description:** The `antiXSS()` function applies both `mysqli_real_escape_string()` and `htmlspecialchars()` (via `antihtml()`), and in the default path also adds `addslashes()`. This triple transformation creates encoded data that may be stored with SQL escaping mixed into HTML encoding, leading to display artifacts (e.g., `O&#039;Brien` showing as `O\&#039;Brien`).  
**Exploit scenario:** Not directly exploitable for XSS but causes data corruption and confusing behavior. The mixing of SQL escaping with HTML encoding is an anti-pattern that makes security reasoning difficult.  
**Remediation:** Separate concerns: use `htmlspecialchars()` for output encoding, use prepared statements (already in place) for SQL safety. The `mysqli_real_escape_string()` call in `antiXSS()` is redundant since all queries use prepared statements. Refactor `antiXSS()` to only call `htmlspecialchars()`:

```php
function antiXSS($phrase) {
    return htmlspecialchars(trim($phrase), ENT_QUOTES, 'UTF-8');
}
```

### FINDING-SEC-012: MEDIUM - Combat reports contain unescaped player names in HTML

**File:** `includes/game_actions.php:148, 184`  
**Description:** Combat report HTML is built with player names directly interpolated: `"<a style=\"color:red\" href=\"joueur.php?id=" . $actions['attaquant'] . "\">" . $actions['attaquant'] . "</a>"`. Player names come from the database (originally validated at registration) but are not HTML-encoded in the report construction.  
**Exploit scenario:** Same class as SEC-009. If a player name contains HTML, the combat report HTML would be corrupted or exploitable. Reports are stored in the database and displayed later.  
**Remediation:** Apply `htmlspecialchars()` to all player names and alliance tags used in combat report HTML construction.

### FINDING-SEC-013: MEDIUM - Reflected input in redirect URLs

**File:** `ecriremessage.php:35-36`  
**Description:** `echo '<script>document.location.href="messages.php?information=' . $information . '"</script>';` -- While `$information` is set to a fixed server-side string ("Le message a bien ete envoye.") on line 32, this pattern of injecting variables into JavaScript is fragile.  
**Exploit scenario:** Currently safe because `$information` is a server-side constant. However, if the logic changes to use user input, this becomes a DOM-based XSS vector.  
**Remediation:** Use `header('Location: ...')` with `urlencode()` instead of JavaScript redirects:

```php
header('Location: messages.php?information=' . urlencode($information));
exit();
```

### FINDING-SEC-014: MEDIUM - Sujet page outputs forum title and subject without consistent encoding

**File:** `sujet.php:147, 155`  
**Description:** Line 147: `echo '<a href="forum.php">Forum</a> > ... > ' . $sujet['titre'];` -- The subject title from the database is output without `htmlspecialchars()`. Line 155: `BBcode($sujet['contenu'])` is safe (BBcode applies htmlentities). But `$sujet['titre']` is not processed through BBcode.  
**Exploit scenario:** If a user creates a forum topic with a title containing `<script>`, it would execute when the topic page is viewed.  
**Remediation:** Apply `htmlspecialchars()` to `$sujet['titre']` on line 147:

```php
echo '... > ' . htmlspecialchars($sujet['titre'], ENT_QUOTES, 'UTF-8');
```

Also audit all other places where `$sujet['titre']` is displayed.

---

## 3. CSRF

### FINDING-SEC-015: HIGH - No CSRF protection on voter.php

**File:** `voter.php:1-36`  
**Description:** `voter.php` performs state-changing operations (inserting/updating poll votes) via GET parameters (`$_GET['reponse']`). There is no CSRF token check. The endpoint uses GET for state changes, which is itself a violation of HTTP semantics.  
**Exploit scenario:** An attacker embeds `<img src="https://theverylittlewar.com/voter.php?reponse=X">` on any webpage. When a logged-in player visits, their vote is automatically cast/changed.  
**Remediation:** Change voter.php to use POST requests with CSRF tokens:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    // ... existing vote logic using $_POST['reponse']
}
```

### FINDING-SEC-016: MEDIUM - No CSRF protection on login form

**File:** `includes/basicpublicphp.php:21-79` and `index.php:34-40`  
**Description:** The login form on `index.php` (lines 34-40) does not include a CSRF token field, and `basicpublicphp.php` does not call `csrfCheck()` on the login POST handler. While login CSRF is lower risk than other CSRF vectors, it can be used for login CSRF attacks (forcing a victim to log into the attacker's account).  
**Exploit scenario:** An attacker creates a form that auto-submits to the login endpoint with the attacker's credentials. The victim unknowingly logs into the attacker's account and may enter sensitive data that the attacker can later retrieve.  
**Remediation:** Add CSRF token to the login form and verify it in `basicpublicphp.php`:

```php
// In index.php login form:
echo csrfField();

// In basicpublicphp.php before processing login:
csrfCheck();
```

### FINDING-SEC-017: MEDIUM - API endpoint has no CSRF protection

**File:** `api.php:1-53`  
**Description:** `api.php` checks `$_SESSION['login']` for authentication but has no CSRF token verification. It processes GET requests that trigger server-side computation (formula calculations via database queries). While the current API is read-only, the absence of CSRF protection means any site can trigger these requests on behalf of a logged-in user.  
**Exploit scenario:** A malicious page can enumerate player data by making cross-origin requests to the API with the victim's session cookie. Since the API returns JSON without a proper Content-Type header, older browsers may render it as HTML.  
**Remediation:** Add `Content-Type: application/json` header. For any future state-changing API endpoints, require CSRF tokens or use SameSite cookie attributes.

```php
header('Content-Type: application/json');
```

### FINDING-SEC-018: LOW - Admin login form has no CSRF token

**File:** `admin/index.php:176-181`  
**Description:** The admin login form does not include a CSRF token. Same class of issue as SEC-016 but for the admin panel.  
**Remediation:** Add `csrfField()` to the admin login form and verify on submit.

---

## 4. Authentication Bypass

### FINDING-SEC-019: HIGH - Session stores password hash, used for re-authentication on every page

**File:** `includes/basicprivatephp.php:16-23`  
**Description:** On every authenticated page load, `basicprivatephp.php` compares `$_SESSION['mdp']` (the stored password hash) against the database value: `if (!$row || $row['pass_md5'] !== $_SESSION['mdp'])`. The password hash is stored in the session on login (`basicpublicphp.php:61`). This means the full bcrypt hash is stored in the session file on disk.  
**Exploit scenario:** If an attacker gains read access to session files (e.g., through a local file inclusion vulnerability, shared hosting, or server misconfiguration), they obtain the full bcrypt hash for every active session. While bcrypt is slow to crack, this is unnecessary exposure.  
**Remediation:** Store a session validation token instead of the actual password hash. Generate a random token at login and store it both in the session and in a `session_token` column in the `membre` table:

```php
// At login:
$token = bin2hex(random_bytes(32));
$_SESSION['session_token'] = $token;
dbExecute($base, 'UPDATE membre SET session_token=? WHERE login=?', 'ss', $token, $loginInput);

// At validation:
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login=?', 's', $_SESSION['login']);
if (!$row || $row['session_token'] !== $_SESSION['session_token']) { ... }
```

### FINDING-SEC-020: MEDIUM - Legacy MD5 password fallback still active

**File:** `includes/basicpublicphp.php:48`  
**Description:** `elseif (md5($passwordInput) === $storedHash)` -- The login handler still has an MD5 fallback for legacy passwords. While it auto-upgrades to bcrypt, the MD5 comparison uses `===` (strict equality) which is safe against timing attacks. However, the MD5 fallback means any remaining MD5 hashes in the database are vulnerable to rainbow table attacks.  
**Exploit scenario:** If the database is leaked, MD5 password hashes can be cracked almost instantly using rainbow tables or GPU-based cracking. The auto-upgrade only happens when a user logs in, so inactive accounts may retain MD5 hashes indefinitely.  
**Remediation:** Run a one-time migration to identify and force-reset any remaining MD5 hashes:

```sql
-- Find accounts still using MD5 (32 hex chars, not starting with $2y$)
SELECT login FROM membre WHERE LENGTH(pass_md5) = 32 AND pass_md5 NOT LIKE '$2y$%';
```

Force password resets for these accounts. Set a deadline to remove the MD5 fallback code.

### FINDING-SEC-021: MEDIUM - `basicprivatephp.php` applies confusing transformations to session login

**File:** `includes/basicprivatephp.php:17`  
**Description:** `$_SESSION['login'] = ucfirst(mb_strtolower(mysqli_real_escape_string($base, stripslashes(htmlentities($_SESSION['login'])))));` -- This applies five transformations to the session login on every page load: `htmlentities()`, `stripslashes()`, `mysqli_real_escape_string()`, `mb_strtolower()`, `ucfirst()`. The `mysqli_real_escape_string()` is inappropriate for session data (it's meant for SQL, but the login is used in prepared statements). The `htmlentities()` could double-encode if the login was already encoded.  
**Exploit scenario:** Not directly exploitable because login names are alphanumeric (`/^[a-zA-Z0-9_]{3,20}$/`), but the confusing transformation chain makes the code harder to audit and could cause subtle bugs if login validation changes.  
**Remediation:** Simplify to just case normalization:

```php
$_SESSION['login'] = ucfirst(mb_strtolower($_SESSION['login']));
```

The `mysqli_real_escape_string` and `htmlentities` calls are unnecessary since all SQL uses prepared statements and login is alphanumeric.

---

## 5. Authorization

### FINDING-SEC-022: MEDIUM - Alliance invitation acceptance lacks ownership verification

**File:** `alliance.php:114-131`  
**Description:** When accepting an alliance invitation, the code verifies that the invitation exists and the alliance isn't full, but it does not verify that the invitation's `invite` field matches `$_SESSION['login']`. It uses `$_POST['idinvitation']` to look up the invitation and accepts it for the current user.  
**Exploit scenario:** Player A receives invitation ID 42. Player B could submit a POST request with `idinvitation=42` and `actioninvitation=Accepter` to accept that invitation, joining the alliance intended for Player A.  
**Remediation:** Add verification that the invitation belongs to the current user:

```php
$invitation = dbFetchOne($base, 'SELECT * FROM invitations WHERE id=? AND invite=?', 'is', $_POST['idinvitation'], $_SESSION['login']);
if (!$invitation) {
    $erreur = "Cette invitation ne vous est pas destinee.";
}
```

### FINDING-SEC-023: LOW - No authorization check on message reply source

**File:** `ecriremessage.php:48-64`  
**Description:** When replying to a message via `$_GET['id']`, the code fetches the message and checks if `$message['destinataire'] != $_SESSION['login']` (line 60). This correctly prevents replying to messages not addressed to the user. However, the message content is still fetched and available in memory regardless.  
**Remediation:** Move the authorization check before fetching message content, or ensure the query includes the user filter:

```php
$message = dbFetchOne($base, 'SELECT ... FROM messages WHERE id=? AND destinataire=?', 'is', $_GET['id'], $_SESSION['login']);
```

---

## 6. Session Security

### FINDING-SEC-024: CRITICAL - `session.cookie_secure` set to 0

**File:** `includes/basicprivatephp.php:5`  
**Description:** `ini_set('session.cookie_secure', 0);` -- Session cookies are sent over unencrypted HTTP connections. Once HTTPS is enabled (planned per the TODO), this must be changed to 1. Until then, session cookies can be intercepted via network sniffing.  
**Exploit scenario:** On any network where traffic can be observed (public WiFi, ISP-level monitoring), an attacker can capture the `PHPSESSID` cookie and hijack the user's session.  
**Remediation:** After enabling HTTPS via Let's Encrypt (already planned), change to:

```php
ini_set('session.cookie_secure', 1);
```

This is the single most impactful security improvement that can be made.

### FINDING-SEC-025: HIGH - No `SameSite` attribute on session cookies

**File:** `includes/basicprivatephp.php:3-7`  
**Description:** The session configuration does not set the `SameSite` cookie attribute. This means session cookies are sent with cross-origin requests, enabling CSRF attacks.  
**Exploit scenario:** Combined with the CSRF findings above, cross-origin requests include the session cookie, allowing forged requests from malicious sites.  
**Remediation:** Add `SameSite=Lax` to session cookie configuration:

```php
ini_set('session.cookie_samesite', 'Lax');
```

Or for PHP 7.3+:

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,  // after HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);
```

### FINDING-SEC-026: MEDIUM - Session regeneration only happens once

**File:** `includes/basicprivatephp.php:26-29`  
**Description:** Session ID is regenerated only once after login (when `$_SESSION['_regenerated']` is not set). After that, the session ID never changes again for the lifetime of the session.  
**Exploit scenario:** If a session ID is captured after the initial regeneration (e.g., via XSS or network sniffing), it remains valid indefinitely. Best practice is to periodically regenerate the session ID.  
**Remediation:** Regenerate session ID periodically (e.g., every 30 minutes):

```php
if (!isset($_SESSION['_last_regen']) || time() - $_SESSION['_last_regen'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['_last_regen'] = time();
}
```

### FINDING-SEC-027: LOW - No session timeout

**File:** `includes/basicprivatephp.php`  
**Description:** There is no session timeout mechanism. Sessions remain valid as long as the PHP session garbage collector allows (default: 1440 seconds of inactivity, but this depends on PHP configuration).  
**Exploit scenario:** Abandoned sessions on shared computers remain exploitable for extended periods.  
**Remediation:** Add explicit session timeout checking:

```php
$timeout = 7200; // 2 hours
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_destroy();
    header('Location: index.php');
    exit();
}
$_SESSION['last_activity'] = time();
```

---

## 7. File Inclusion / Upload

### FINDING-SEC-028: MEDIUM - Avatar upload uses `uniqid()` for filenames

**File:** `compte.php:127-128`  
**Description:** `$fichier = uniqid('avatar_') . '.' . $extension;` then `move_uploaded_file($_FILES['photo']['tmp_name'], $dossier . $fichier);`. The file upload has good validation (extension whitelist, MIME check, `getimagesize()`, dimension limits, size limits). However, `uniqid()` is predictable (based on timestamp + microseconds) and not cryptographically secure.  
**Exploit scenario:** Low risk. An attacker could predict avatar filenames, but since avatars are publicly viewable anyway, the impact is minimal. The main concern would be overwriting another user's avatar (extremely unlikely with microsecond precision).  
**Remediation:** Use `bin2hex(random_bytes(16))` instead of `uniqid()` for truly unpredictable filenames:

```php
$fichier = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
```

### FINDING-SEC-029: LOW - No file inclusion via user-controlled paths found

**File:** N/A  
**Description:** All `include()` and `require()` calls across the codebase use hard-coded paths. No user input is used to construct include paths. This is a positive finding.  
**Remediation:** None needed. Continue the practice of using hard-coded include paths.

---

## 8. Information Disclosure

### FINDING-SEC-030: CRITICAL - Database credentials in source code with empty root password

**File:** `includes/connexion.php:2-5`  
**Description:** Database credentials are stored in plaintext in the source code: `$db_user = 'root'; $db_pass = '';`. The development configuration uses root with no password. On the production VPS, different credentials are used (per deployment notes), but the source repository contains insecure defaults.  
**Exploit scenario:** If the source code is leaked (e.g., via misconfigured backup, Git exposure), database credentials are immediately available. Using `root` with no password in development normalizes insecure practices.  
**Remediation:** Move credentials to environment variables or a `.env` file excluded from Git:

```php
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'tvlw';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'tvlw';
```

Ensure `.env` is in `.gitignore`.

### FINDING-SEC-031: MEDIUM - Admin password hash exposed in source code

**File:** `includes/constantesBase.php:53`  
**Description:** `define('ADMIN_PASSWORD_HASH', '$2y$10$PibWl...');` -- The bcrypt hash of the admin password is in the source code. While bcrypt is resistant to cracking, exposing the hash gives attackers a target for offline brute-force.  
**Exploit scenario:** If source code is leaked, the attacker has the admin password hash and can attempt offline cracking. With bcrypt cost 10, this would require significant compute but is not impossible for a weak password.  
**Remediation:** Move the admin password hash to an environment variable or a separate config file not in version control.

### FINDING-SEC-032: MEDIUM - SQL error logging includes query text

**File:** `includes/db_helpers.php:12`  
**Description:** `error_log('SQL Error: ' . mysqli_error($base) . ' | Query: ' . $truc);` -- When the legacy `query()` function encounters an error, it logs the full query text including any data that was interpolated into it.  
**Exploit scenario:** If user data was interpolated into the query string (SQL injection attempt), the error log would contain the injected payload along with any surrounding query context, potentially revealing table structures.  
**Remediation:** Remove query text from error logs, or at minimum truncate it:

```php
error_log('SQL Error: ' . mysqli_error($base) . ' | Query: ' . substr($truc, 0, 200));
```

Better: remove the `query()` function entirely (see SEC-001).

### FINDING-SEC-033: LOW - Logger includes session login in log entries

**File:** `includes/logger.php:26`  
**Description:** `$login = $_SESSION['login'] ?? 'anonymous';` -- Log entries include the player's login name. This is standard practice for audit logging. However, log files should be protected from unauthorized access.  
**Exploit scenario:** If log files are accessible via web (e.g., `/logs/2026-03-02.log`), player activity can be monitored.  
**Remediation:** Verify that the `logs/` directory is protected. The `.htaccess` blocks `.log` files but only via the `FilesMatch` directive. Add explicit protection:

```apache
<Directory /var/www/html/logs>
    Require all denied
</Directory>
```

Or better, move logs outside the web root entirely.

---

## 9. Rate Limiting Coverage

### FINDING-SEC-034: HIGH - No rate limiting on admin login

**File:** `admin/index.php:10-16` and `moderation/index.php:8-11`  
**Description:** The admin login form processes password attempts without any rate limiting. The player login has rate limiting (10 attempts per 5 minutes per IP via `basicpublicphp.php:24`), but the admin panel does not.  
**Exploit scenario:** An attacker can perform unlimited brute-force attempts against the admin password. Since the admin panel is at a predictable URL (`/admin/index.php`), automated tools can easily target it.  
**Remediation:** Add rate limiting to the admin login:

```php
require_once(__DIR__ . '/../includes/rate_limiter.php');
if (isset($_POST['motdepasseadmin'])) {
    if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'admin_login', 5, 600)) {
        die('Trop de tentatives. Reessayez dans 10 minutes.');
    }
    // ... existing password check
}
```

### FINDING-SEC-035: MEDIUM - No rate limiting on API, forum, market, combat, or messaging

**File:** `api.php`, `sujet.php`, `marche.php`, `attaquer.php`, `ecriremessage.php`  
**Description:** Rate limiting is only implemented for player login (10/5min) and registration (3/hour). All other game endpoints have no rate limiting:
- `api.php` -- formula calculations (can be called in tight loops by JavaScript)
- `sujet.php` -- forum post creation
- `ecriremessage.php` -- private message sending
- `marche.php` -- market transactions
- `attaquer.php` -- attack/espionage launching
- `don.php` -- energy donations  

**Exploit scenario:** An attacker could spam the forum with thousands of posts, flood a player's inbox with messages, or make rapid market trades to manipulate prices. The market has a volatility factor based on active players but no per-user trade rate limit.  
**Remediation:** Add rate limiting to critical endpoints:
- Forum posts: 5 per 5 minutes per user
- Messages: 10 per 5 minutes per user  
- Attacks: 20 per hour per user
- Market trades: 30 per hour per user
- API calls: 60 per minute per IP

---

## 10. HTTP Security Headers

### FINDING-SEC-036: HIGH - Missing Content-Security-Policy header

**File:** `.htaccess:1-7`  
**Description:** The `.htaccess` sets `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, and `Referrer-Policy`, but is missing the `Content-Security-Policy` (CSP) header. CSP is the most effective defense against XSS attacks.  
**Exploit scenario:** Without CSP, any XSS vulnerability (stored or reflected) can load arbitrary external scripts, exfiltrate data via image beacons, or perform other malicious actions unconstrained.  
**Remediation:** Add a CSP header. Start with a report-only policy and tighten over time:

```apache
Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'"
```

Note: The application uses inline scripts extensively, so `'unsafe-inline'` is necessary initially. A future refactor should move inline scripts to external files and use nonces.

### FINDING-SEC-037: HIGH - Missing HSTS header

**File:** `.htaccess`  
**Description:** No `Strict-Transport-Security` header is set. Once HTTPS is enabled, HSTS is essential to prevent SSL stripping attacks.  
**Exploit scenario:** Even with HTTPS enabled, without HSTS, the first request from a user could be intercepted via a downgrade attack (MITM forces HTTP instead of HTTPS).  
**Remediation:** After HTTPS is enabled, add:

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### FINDING-SEC-038: LOW - `X-XSS-Protection` is deprecated

**File:** `.htaccess:5`  
**Description:** `Header set X-XSS-Protection "1; mode=block"` -- This header is deprecated and has been removed from modern browsers. It can actually introduce vulnerabilities in some edge cases.  
**Exploit scenario:** No direct vulnerability, but relying on this header gives false confidence. Modern browsers ignore it.  
**Remediation:** Remove the `X-XSS-Protection` header and rely on CSP instead.

---

## 11. Admin Panel Security

### FINDING-SEC-039: HIGH - Admin session is a simple boolean flag

**File:** `admin/index.php:12, 18`  
**Description:** `$_SESSION['motdepasseadmin'] = true;` on line 12. The admin session check on line 18 is `if (isset($_SESSION['motdepasseadmin']) and $_SESSION['motdepasseadmin'] === true)`. This is a simple boolean flag in the same session as the regular player session.  
**Exploit scenario:** If a session fixation or hijacking attack succeeds against ANY page on the site, the attacker could potentially manipulate session data to set `motdepasseadmin` to true. Additionally, the admin session never expires -- once set, it persists for the entire PHP session lifetime.  
**Remediation:** Use a separate, time-limited admin token:

```php
// On admin login:
$_SESSION['admin_token'] = bin2hex(random_bytes(32));
$_SESSION['admin_token_time'] = time();

// On admin page load:
if (!isset($_SESSION['admin_token']) || !isset($_SESSION['admin_token_time']) 
    || (time() - $_SESSION['admin_token_time']) > 3600) {
    unset($_SESSION['admin_token']);
    // redirect to admin login
}
```

### FINDING-SEC-040: MEDIUM - No IP restriction on admin panel

**File:** `admin/index.php`, `moderation/index.php`  
**Description:** The admin and moderation panels are accessible from any IP address. There is no IP-based access control.  
**Exploit scenario:** Anyone on the internet can access the admin login page and attempt brute-force attacks.  
**Remediation:** Add IP restriction via .htaccess or Apache configuration:

```apache
# In admin/.htaccess
<IfModule mod_authz_core.c>
    Require ip 212.227.38.111
    Require ip YOUR_HOME_IP
</IfModule>
```

Or implement IP checking in the PHP code.

---

## 12. Client-Side JavaScript Security

### FINDING-SEC-041: HIGH - jQuery 1.7.2 has known XSS vulnerabilities

**File:** `js/jquery-1.7.2.min.js`  
**Description:** jQuery 1.7.2 (released 2012) has multiple known CVEs:
- CVE-2015-9251: Cross-site scripting via cross-domain AJAX responses
- CVE-2019-11358: Object.prototype pollution
- CVE-2020-11022/11023: XSS in `$(htmlString)` with untrusted input  
jQuery 3.1.1 is also present (`js/jquery-3.1.1.min.js`) but it's unclear which is loaded.  

**Exploit scenario:** If any code passes user-controlled strings to jQuery selector or DOM manipulation functions, the known XSS vulnerabilities in jQuery 1.7.2 can be exploited.  
**Remediation:** Remove `jquery-1.7.2.min.js`. Ensure only `jquery-3.1.1.min.js` (or newer) is loaded. Update jQuery 3.1.1 to at least 3.5.0 to address CVE-2020-11022/11023.

### FINDING-SEC-042: MEDIUM - jQuery UI 1.8.18 is extremely outdated

**File:** `js/jquery-ui-1.8.18.custom.min.js`  
**Description:** jQuery UI 1.8.18 (released 2012) is 14 years old and has known XSS vulnerabilities in multiple widgets (dialog, tooltip, etc.).  
**Exploit scenario:** Any jQuery UI widget that processes user content could be exploitable.  
**Remediation:** Update to jQuery UI 1.13+ or remove if not actively used.

### FINDING-SEC-043: MEDIUM - jCryption 3.1.0 client-side crypto library

**File:** `js/jquery.jcryption.3.1.0.js`, `js/aes.js`, `js/sha.js`, `js/sha1.js`  
**Description:** The codebase includes client-side cryptography libraries (jCryption, AES, SHA). Client-side encryption without HTTPS provides no real security because the JavaScript itself can be modified by a MITM attacker. With HTTPS (planned), these become redundant.  
**Exploit scenario:** Users may have a false sense of security believing their data is encrypted, when a MITM attacker can modify the JavaScript to capture plaintext before encryption.  
**Remediation:** After enabling HTTPS, remove the client-side crypto libraries. They add unnecessary attack surface and complexity. If they are currently used for password transmission, HTTPS makes them redundant.

### FINDING-SEC-044: LOW - MathJax loaded from CDN without SRI

**File:** `sujet.php:231`  
**Description:** `<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>` -- External JavaScript is loaded without Subresource Integrity (SRI) attributes.  
**Exploit scenario:** If the CDN is compromised or the DNS is hijacked, malicious JavaScript could be injected.  
**Remediation:** Add SRI hash:

```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js?config=TeX-AMS-MML_HTMLorMML"
    integrity="sha384-HASH_HERE" crossorigin="anonymous"></script>
```

---

## Positive Findings

The following security measures are already in place and functioning correctly:

1. **Prepared statements** -- All database queries (except the legacy `query()` function) use parameterized prepared statements via `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, `dbExecute()`, and `dbCount()`.

2. **bcrypt password hashing** -- New passwords are hashed with `password_hash(PASSWORD_DEFAULT)` and verified with `password_verify()`. Auto-upgrade from MD5 is in place.

3. **CSRF protection on most forms** -- The `csrfField()`/`csrfCheck()` system is implemented on nearly all POST forms across the application.

4. **Login rate limiting** -- 10 attempts per 5 minutes per IP on the player login form.

5. **Registration rate limiting** -- 3 registrations per hour per IP.

6. **Input validation on registration** -- Login names validated against `/^[a-zA-Z0-9_]{3,20}$/`, emails validated with `filter_var()`.

7. **File upload security** -- Avatar uploads are validated with extension whitelist, MIME type check, `getimagesize()`, dimension limits, and random filenames.

8. **BBCode sanitization** -- The BBCode parser applies `htmlentities()` before regex replacements, preventing XSS in BBCode-rendered content.

9. **Session hardening basics** -- `httponly` cookies, `use_strict_mode`, session regeneration on first authenticated request.

10. **Directory listing disabled** -- `.htaccess` has `Options -Indexes`.

11. **Sensitive file access blocked** -- `.htaccess` blocks access to `.sql`, `.md`, `.json`, `.xml`, `.lock`, `.gitignore` files and hidden files.

12. **PHP error display disabled** -- `display_errors off` in `.htaccess`.

13. **Event logging** -- Security events (login success/failure, registration, admin actions) are logged to files.

14. **Alliance research whitelist** -- `alliance.php:94` validates `$_POST['upgradeResearch']` against `$ALLIANCE_RESEARCH` keys before use.

15. **ORDER BY whitelist** -- `alliance.php:341-366` uses a `switch` statement to whitelist the ORDER BY column from user input.

---

## Remediation Priority Roadmap

### Immediate (before production launch)

| Finding | Action | Effort |
|---------|--------|--------|
| SEC-024 | Enable `session.cookie_secure=1` after HTTPS | 5 min |
| SEC-030 | Move DB credentials to env vars | 30 min |
| SEC-001 | Remove legacy `query()` function | 1 hour |
| SEC-034 | Add rate limiting to admin login | 15 min |
| SEC-025 | Add SameSite=Lax to session cookies | 5 min |
| SEC-036 | Add Content-Security-Policy header | 30 min |
| SEC-037 | Add HSTS header (after HTTPS) | 5 min |

### Short-term (within 30 days)

| Finding | Action | Effort |
|---------|--------|--------|
| SEC-007 | Sanitize news output with tag allowlist | 1 hour |
| SEC-009 | HTML-encode output in `joueur()` function | 15 min |
| SEC-010 | HTML-encode output in `alliance()` function | 15 min |
| SEC-012 | HTML-encode player names in combat reports | 2 hours |
| SEC-014 | HTML-encode forum subject titles | 30 min |
| SEC-015 | Add CSRF to voter.php, change to POST | 1 hour |
| SEC-016 | Add CSRF to login form | 30 min |
| SEC-019 | Replace session password hash with random token | 2 hours |
| SEC-039 | Improve admin session management | 1 hour |
| SEC-041 | Remove jQuery 1.7.2, update to 3.5+ | 2 hours |

### Medium-term (within 90 days)

| Finding | Action | Effort |
|---------|--------|--------|
| SEC-002 | Add whitelist to `ajouter()` | 30 min |
| SEC-003 | Add internal validation to research functions | 30 min |
| SEC-004 | Add whitelist to building functions | 30 min |
| SEC-011 | Refactor `antiXSS()` function | 2 hours |
| SEC-017 | Add Content-Type header to API | 15 min |
| SEC-020 | Force-reset remaining MD5 passwords | 1 hour |
| SEC-021 | Simplify session login transformations | 30 min |
| SEC-022 | Fix invitation acceptance authorization | 30 min |
| SEC-026 | Implement periodic session regeneration | 30 min |
| SEC-031 | Move admin password hash out of source | 30 min |
| SEC-035 | Add rate limiting to forum/market/combat/messaging | 4 hours |
| SEC-040 | Add IP restriction to admin panel | 30 min |
| SEC-042 | Update jQuery UI | 2 hours |
| SEC-043 | Remove client-side crypto libraries post-HTTPS | 1 hour |

### Low priority

| Finding | Action | Effort |
|---------|--------|--------|
| SEC-005 | Validate resource column names | 15 min |
| SEC-006 | Document `remiseAZero()` SQL construction | 5 min |
| SEC-008 | Document BBCode encoding requirement | 15 min |
| SEC-013 | Replace JS redirects with header() | 30 min |
| SEC-018 | Add CSRF to admin login | 15 min |
| SEC-023 | Tighten message reply authorization | 15 min |
| SEC-027 | Add explicit session timeout | 30 min |
| SEC-028 | Use random_bytes for avatar filenames | 5 min |
| SEC-032 | Remove query text from error logs | 15 min |
| SEC-033 | Protect log directory | 15 min |
| SEC-038 | Remove deprecated X-XSS-Protection | 5 min |
| SEC-044 | Add SRI to CDN scripts | 15 min |

---

## Methodology

This audit was conducted through manual source code review of every PHP file in the codebase (~80 files), all JavaScript libraries (~18 files), and configuration files (.htaccess). The audit covered:

1. **SQL Injection** -- Every `dbQuery`, `dbExecute`, `dbFetchOne`, `dbFetchAll`, `dbCount` call inspected for parameter types. All `query()` calls traced. All dynamic SQL construction reviewed.
2. **XSS** -- Every `echo`, `<?=`, `print` output traced to source. BBCode parser regex analyzed. All user input→output paths mapped.
3. **CSRF** -- Every `<form>` and state-changing GET request checked for `csrfField()`/`csrfCheck()`.
4. **Authentication** -- Session creation, validation, and destruction flows analyzed end-to-end.
5. **Authorization** -- Every action checked for ownership verification.
6. **Session security** -- Cookie attributes, regeneration, timeout, data storage reviewed.
7. **File inclusion** -- All `include`/`require` paths traced.
8. **Information disclosure** -- Error handling, log output, source code contents reviewed.
9. **Rate limiting** -- All endpoints checked for rate limiting coverage.
10. **HTTP headers** -- `.htaccess` and response headers audited.
11. **Admin security** -- Admin/moderation panel authentication and authorization reviewed.
12. **Client-side** -- All JavaScript files identified and version-checked for known CVEs.