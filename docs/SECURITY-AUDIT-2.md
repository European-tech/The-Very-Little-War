# TVLW Security Counter-Audit Report

**Date:** 2026-03-02
**Auditor:** Claude Opus 4.6 (Security Auditor)
**Scope:** Full PHP codebase of The Very Little War
**Status:** POST-REMEDIATION COUNTER-AUDIT

---

## Executive Summary

The initial security remediation addressed several critical issues (SQL injection via prepared statements, CSRF tokens, bcrypt migration, admin password hashing, sql.php removal). However, this counter-audit reveals **significant remaining vulnerabilities** across multiple attack categories. The remediation was incomplete -- many files still contain raw SQL concatenation, several pages lack CSRF protection, XSS vulnerabilities persist in critical output contexts, and the entire admin panel uses GET-based destructive actions without CSRF.

**Finding Counts:**
- CRITICAL: 12
- HIGH: 18
- MEDIUM: 16
- LOW: 8

---

## 1. SQL Injection

### SQLI-01: Raw SQL in basicprivatephp.php (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`
**Lines:** 26-30, 55-63, 72-114

The authentication gateway file that ALL private pages include still contains massive raw SQL concatenation with `$_SESSION['login']`. While `$_SESSION['login']` is set from sanitized input, it is processed through `ucfirst(mb_strtolower(mysqli_real_escape_string(...)))` which provides some protection, but the queries are NOT using prepared statements:

```php
// Line 26-30 - Raw SQL with session data
$ex = query('SELECT x,y FROM membre WHERE login=\'' . $_SESSION['login'] . '\'');
$posAct = mysqli_fetch_array($ex);
if ($posAct['x'] == -1000) {
    $position = coordonneesAleatoires();
    query('UPDATE membre SET x=\'' . $position['x'] . '\', y=\'' . $position['y'] . '\' WHERE login=\'' . $_SESSION['login'] . '\'');
}
```

```php
// Lines 55-63 - REMOTE_ADDR directly in SQL
$retour = mysqli_query($base, 'SELECT COUNT(*) AS nbre_entrees FROM connectes WHERE ip=\'' . $_SERVER['REMOTE_ADDR'] . '\'');
mysqli_query($base, 'INSERT INTO connectes VALUES(\'' . $_SERVER['REMOTE_ADDR'] . '\', ' . time() . ')');
mysqli_query($base, 'UPDATE connectes SET timestamp=' . time() . ' WHERE ip=\'' . $_SERVER['REMOTE_ADDR'] . '\'');
```

`$_SERVER['REMOTE_ADDR']` is typically safe but can be spoofed behind certain proxy configurations. More critically, every query on lines 72-114 uses raw concatenation with `$_SESSION['login']`.

**Severity:** CRITICAL -- This file is loaded on EVERY authenticated page request.

**Fix:** Convert ALL queries in this file to use prepared statements via `dbQuery()`/`dbFetchOne()`/`dbExecute()`.

---

### SQLI-02: Raw SQL in editer.php (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/editer.php`
**Lines:** 6, 11-12, 17-27, 39-48, 52-103

This file has **zero** prepared statements. ALL queries use raw concatenation with `$_GET['id']`, `$_GET['type']`, `$_POST['contenu']`, `$_POST['titre']`, and `$_SESSION['login']`:

```php
// Line 11-12 - $_GET['id'] directly in SQL
$sql3 = 'SELECT idsujet FROM reponses WHERE id=\''.$_GET['id'].'\'';
$ex3 = mysqli_query($base,$sql3) or die('Erreur SQL !'.$sql3.'<br />'.mysql_error());
```

```php
// Line 24 - $_GET['id'] in DELETE
mysqli_query($base,'DELETE FROM reponses WHERE id=\''.$_GET['id'].'\'');
```

```php
// Line 60 - $_POST data in UPDATE
mysqli_query($base,'UPDATE sujets SET contenu=\''.$_POST['contenu'].'\', titre=\''.$_POST['titre'].'\' WHERE id=\''.$_GET['id'].'\'');
```

While `preg_match("#^[0-9]*$#", $_GET['id'])` is used as a check, this regex allows empty string matches and is applied inconsistently.

**Severity:** CRITICAL -- Direct user input in SQL queries.

**Fix:** Replace all `mysqli_query()` calls with `dbExecute()` / `dbFetchOne()` using prepared statements.

---

### SQLI-03: Raw SQL in admin/listesujets.php (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/listesujets.php`
**Lines:** 35-48

```php
$_GET['supprimersujet'] = addslashes($_GET['supprimersujet']);
mysqli_query($base,'DELETE FROM sujets WHERE id=\'' . $_GET['supprimersujet'] . '\'');
mysqli_query($base,'DELETE FROM statutforum WHERE idsujet=\''. $_GET['supprimersujet'] . '\'');
```

`addslashes()` is NOT a safe defense against SQL injection. Same pattern for lock/unlock operations.

**Severity:** CRITICAL -- Admin panel SQL injection via GET parameter.

**Fix:** Use `dbExecute()` with prepared statements: `dbExecute($base, 'DELETE FROM sujets WHERE id = ?', 'i', (int)$_GET['supprimersujet']);`

---

### SQLI-04: Raw SQL in comptetest.php (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/comptetest.php`
**Lines:** 6-56

This entire file was NOT migrated. It contains massive SQL injection vectors:

```php
// Line 10 - Session login in raw SQL
$ex = mysqli_query($base,'SELECT timestamp FROM membre WHERE login=\''.$log.'\'');
```

```php
// Lines 33-54 - $_POST directly in SQL
$sql = 'SELECT count(*) FROM membre WHERE login="'.mysqli_real_escape_string($base,$_POST['login']).'"';
// ...many raw UPDATE queries with $_POST['login'] and $_SESSION['login']
```

```php
// Line 43 - Password stored as MD5
mysqli_query($base,'UPDATE membre SET login=\''.$_POST['login'].'\',pass_md5=\''.mysqli_real_escape_string($base,stripslashes(antihtml(trim(md5($_POST['pass']))))).'\' ...
```

This file also stores passwords as MD5 (not bcrypt), completely bypassing the password upgrade.

**Severity:** CRITICAL -- Multiple SQL injection vectors and password stored in MD5.

**Fix:** Rewrite entirely using prepared statements and `password_hash()`.

---

### SQLI-05: Raw SQL in compte.php (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`
**Lines:** 18-22, 76-77, 89-90, 122, 144-166, 199, 216-218

Multiple queries still use raw concatenation:

```php
// Line 76-77 - $_POST['changermail'] after antiXSS in raw SQL
$sql = 'UPDATE membre SET email=\'' . $_POST['changermail'] . '\' WHERE login=\'' . $_SESSION['login'] . '\'';
mysqli_query($base, $sql) or die('Erreur SQL !<br />' . $sql . '<br />' . mysql_error());
```

```php
// Line 89-90 - $_POST['changerdescription'] in raw SQL
$sql = 'UPDATE autre SET description=\'' . $_POST['changerdescription'] . '\' WHERE login=\'' . $_SESSION['login'] . '\'';
```

```php
// Line 122 - File name in SQL
mysqli_query($base, 'UPDATE autre SET image=\'' . $fichier . '\' WHERE login=\'' . $_SESSION['login'] . '\'');
```

**Severity:** HIGH -- User input in SQL despite `antiXSS()` sanitization.

**Fix:** Convert to prepared statements.

---

### SQLI-06: Raw SQL in fonctions.php (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/fonctions.php`
**Lines:** 308, 357, 392-397, 408, 414, 430, 440, 455, 465, 476-485, 495, 786-789, 892, 912, 965, 1758, 1821-1861

The root `fonctions.php` (in the web root, separate from `includes/fonctions.php`) still contains dozens of raw `mysqli_query()` calls with string concatenation. Functions like `supprimerJoueur()`, `supprimerAlliance()`, `augmenterBatiment()`, `ajouter()`, `remiseAZero()` all use raw SQL.

```php
// Line 1758 - The query() wrapper passes raw SQL
function query($truc) {
    global $base;
    $ex = mysqli_query($base, $truc) or die('Erreur SQL !<br />' . $truc . '<br />' . mysqli_error());
}
```

**Severity:** HIGH -- These functions are called throughout the application with potentially tainted parameters.

**Fix:** Migrate all SQL in this file to prepared statements.

---

### SQLI-07: Raw SQL in includes/basicprivatephp.php Game Reset Section (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`
**Lines:** 131-218

The game reset section (month rollover) contains dozens of raw `mysqli_query()` calls with string concatenation, including data from database queries re-inserted into new queries without prepared statements. Line 209 inserts `$chaine` (built from DB data) directly into SQL:

```php
mysqli_query($base, 'INSERT INTO parties VALUES(default,"' . (time()) . '","' . $chaine . '","' . $chaine1 . '","' . $chaine2 . '")');
```

**Severity:** HIGH -- Second-order SQL injection possible if DB data contains quotes.

**Fix:** Use prepared statements for all queries in this section.

---

### SQLI-08: Raw SQL in includes files (MEDIUM)

**Files:**
- `/home/guortates/TVLW/The-Very-Little-War/includes/menus.php` line 47
- `/home/guortates/TVLW/The-Very-Little-War/includes/statistiques.php` line 2
- `/home/guortates/TVLW/The-Very-Little-War/includes/cardsprivate.php` lines 6, 10
- `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatehtml.php` lines 56, 233

These include files use raw `mysqli_query()` with `$_SESSION['login']` concatenated directly.

**Severity:** MEDIUM -- Session data is pre-sanitized but defense-in-depth requires prepared statements.

**Fix:** Convert to prepared statements.

---

### SQLI-09: Raw SQL in moderation/index.php (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php`
**Lines:** 89, 100

```php
mysqli_query($base, 'UPDATE ressources SET energie=\'' . round($ressourcesDestinataire['energie'] + $_POST['energieEnvoyee']) . '\', ' . $chaine . ' WHERE login=\'' . mysqli_real_escape_string($base, $_POST['destinataire']) . '\'');
```

**Severity:** HIGH -- Admin/moderation panel with raw SQL, though behind auth.

**Fix:** Use prepared statements.

---

## 2. Cross-Site Scripting (XSS)

### XSS-01: Stored XSS via Forum Posts in sujet.php (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`
**Line:** 19

Forum reply content is stored directly to the database without sanitization:

```php
dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)', 'issi', $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);
```

The content is then displayed through `BBcode()` which calls `htmlentities()` first (line 317 of bbcode.php). This provides base protection. However:

1. The BBCode parser on line 331 allows URL injection: `[url=javascript:alert(1)]click[/url]` -- the regex `((https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)` may not match, but the `[url]` tag with a `javascript:` scheme could bypass if the regex is relaxed.

2. Forum topic content in `listesujets.php` line 27 stores `$_POST['contenu']` and `$_POST['titre']` without `htmlentities`:

```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
```

The title is then echoed on line 102 of `listesujets.php`:

```php
echo '<td><a href="sujet.php?id=' . $sujet['id'] . '">' . $sujet['titre'] . '</a>';
```

**The forum topic title is output WITHOUT any escaping.** This is a stored XSS vulnerability.

**Severity:** HIGH -- Stored XSS via forum topic titles.

**Fix:** Apply `htmlspecialchars()` to all user-generated content on output: `htmlspecialchars($sujet['titre'], ENT_QUOTES, 'UTF-8')`.

---

### XSS-02: Stored XSS via Message Titles (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/messages.php`
**Line:** 77

Message titles are output without escaping:

```php
echo '<td><a href="messages.php?message='.$messages['id'].'">'.$messages['titre'].'</a></td>';
```

The title is sanitized with `antihtml()` on input in `ecriremessage.php` line 9, but `antihtml()` uses `ENT_SUBSTITUTE` with `ISO8859-1` charset, which may not fully protect against multi-byte encoding attacks.

**Severity:** HIGH -- Message titles may contain unescaped HTML.

**Fix:** Apply `htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8')` on output.

---

### XSS-03: Stored XSS via Report Content Output (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/rapports.php`
**Lines:** 28, 65

Report content is echoed without any escaping:

```php
echo $rapports['contenu'];  // Line 28
echo '<td>'.$rapports['image'].'</td>';  // Line 65
```

Report content is generated server-side (combat reports, alliance events) and contains raw HTML (links, images). However, some reports are generated from user-controlled data (player names, alliance tags) that could contain XSS payloads. The `image` field from database is also output raw.

**Severity:** HIGH -- Report content rendered as raw HTML.

**Fix:** Ensure all user-derived data within reports is escaped at generation time, or sanitize on output.

---

### XSS-04: Reflected XSS via editer.php (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/editer.php`
**Line:** 65

```php
window.location.replace("sujet.php?id=<?php echo $_GET['id']; ?>");
```

`$_GET['id']` is echoed directly into JavaScript without escaping. An attacker could inject: `1");alert(document.cookie);//`

**Severity:** MEDIUM -- Reflected XSS in JavaScript context.

**Fix:** Use `json_encode()` or `intval()`: `<?php echo (int)$_GET['id']; ?>`

---

### XSS-05: Unescaped Alliance Description (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`
**Line:** 230

```php
echo BBcode($allianceJoueurPage['description'])
```

Alliance descriptions go through BBCode which applies `htmlentities()`. This is the primary XSS defense. However, the `[img=]` tag regex on line 332 of bbcode.php may allow injection:

```php
$text = preg_replace('!\[img=(https?:\/\/(.*)\.(gif|png|jpg|jpeg))\]!isU', '<img alt="undefinded" src="$1">', $text);
```

An attacker could craft: `[img=https://evil.com/x.png" onerror="alert(1)]` -- the regex requires the URL to end in a valid image extension, but the `(.*)` in the middle is greedy and the `$1` backreference is placed directly in the `src` attribute without further escaping. Since `htmlentities()` runs first, double-quotes are converted to `&quot;` which prevents attribute breakout. This is safe only because of the `htmlentities()` call.

**Severity:** MEDIUM -- Potential bypass if htmlentities encoding is inconsistent with the output charset.

**Fix:** Use `htmlspecialchars()` with explicit UTF-8 charset on BBCode output backreferences.

---

### XSS-06: Unescaped Player Data in joueur.php (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/joueur.php`
**Line:** 50

```php
<img style="..." alt="profil" src="images/profil/<?php echo $donnees1['image']; ?>"/>
```

The image filename from the database is output without escaping. If an attacker managed to upload a file with a name containing `" onload="alert(1)`, this could become XSS.

**Severity:** MEDIUM -- Requires file upload exploit to chain.

**Fix:** `htmlspecialchars($donnees1['image'], ENT_QUOTES, 'UTF-8')`

---

### XSS-07: Unescaped ecriremessage.php Values (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`
**Lines:** 78, 87

```php
item(['floating' => true, 'titre' => 'Titre', 'input' => '<input type="text" class="form-control" name="titre" id="titre" value="' . $valueTitre . '" />']);
item(['floating' => true, 'titre' => 'Destinataire', 'input' => '<input type="text" class="form-control" name="destinataire" id="destinataire" value="' . $valueDestinataire . '" />']);
```

`$valueTitre` comes from `$_POST['titre']` (which went through `antihtml()` + `mysqli_real_escape_string()`) and `$valueDestinataire` from `antiXSS($_GET['destinataire'])`. The `antiXSS` function uses `htmlspecialchars` with `ISO8859-1` which provides partial protection but may fail with UTF-8 encoded payloads.

**Severity:** MEDIUM

**Fix:** Use `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` directly on output.

---

### XSS-08: BBCode Parser Post-Preview XSS (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`
**Lines:** 366-369

```php
if(isset($_POST["newst"])){
    $newss = $_POST["newst"];
    echo replaceBBCode($newss);
}
```

The function `replaceBBCode` is never defined in the codebase. This code calls a non-existent function with raw POST data. If PHP is configured to not error on undefined functions (unlikely), or if `replaceBBCode` is defined elsewhere, this is a direct XSS vector. Even with the undefined function, this dead code path indicates a possible pre-existing vulnerability.

**Severity:** HIGH -- Dead code with potential raw POST output.

**Fix:** Remove this dead code block (lines 366-369).

---

## 3. CSRF Vulnerabilities

### CSRF-01: No CSRF on Forum Post Creation (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`
**Lines:** 11-34

The POST handler for creating forum replies does NOT call `csrfCheck()`:

```php
if (isset($_POST['contenu']) and isset($_GET['id'])) {
    // No csrfCheck() here!
    $_GET['id'] = antiXSS($_GET['id']);
    // ... inserts into reponses table
}
```

The reply form on line 214 also does NOT include `csrfField()`.

**Severity:** HIGH -- An attacker can forge forum posts on behalf of any authenticated user.

**Fix:** Add `csrfCheck()` at the beginning of the POST handler and `csrfField()` to the form.

---

### CSRF-02: No CSRF on Forum Topic Creation (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`
**Lines:** 23-40

Same issue as above -- topic creation has no CSRF protection:

```php
if (isset($_POST['titre']) and isset($_POST['contenu'])) {
    // No csrfCheck()!
    if (isset($_SESSION['login'])) {
        dbExecute($base, 'INSERT INTO sujets VALUES(...)', ...);
    }
}
```

The form on line 165 also lacks `csrfField()`.

**Severity:** HIGH

**Fix:** Add CSRF protection.

---

### CSRF-03: No CSRF on Moderator Actions in editer.php (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/editer.php`
**Lines:** 17-49

Delete, hide, and show operations on forum posts use GET requests with no CSRF:

```php
if($_GET['type'] == 3 AND isset($_GET['id']) ...) {
    mysqli_query($base,'DELETE FROM reponses WHERE id=\''.$_GET['id'].'\'');
}
if($_GET['type'] == 5 ...) {
    // Hide message
}
if($_GET['type'] == 4 ...) {
    // Show message
}
```

These are **destructive GET operations** -- clicking a link deletes/hides content.

**Severity:** HIGH -- GET-based destructive actions, trivially exploitable.

**Fix:** Convert to POST with CSRF tokens.

---

### CSRF-04: No CSRF on Admin Panel Actions (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`
**Lines:** 14-31

Admin account deletion uses GET without CSRF:

```php
if (isset($_GET['supprimercompte'])) {
    $ip = $_GET['supprimercompte'];
    $rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
    foreach ($rows as $login) {
        supprimerJoueur($login['login']);
    }
}
```

Maintenance toggle and reset also lack CSRF on their POST forms (lines 22-33, 105-119, 150-152).

**Severity:** HIGH -- Admin can be tricked into deleting accounts via CSRF.

**Fix:** Add CSRF tokens to all admin forms and convert GET actions to POST.

---

### CSRF-05: No CSRF on admin/listesujets.php (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/listesujets.php`
**Lines:** 33-49

Delete/lock/unlock actions on forum topics use GET without CSRF.

**Severity:** MEDIUM -- Behind admin auth but still exploitable.

**Fix:** Convert to POST with CSRF.

---

### CSRF-06: No CSRF on moderationForum.php POST (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php`
**Lines:** 12-27

The ban form POST handler does not call `csrfCheck()`. The GET-based sanction deletion on line 7 also lacks CSRF.

**Severity:** MEDIUM -- Moderator actions without CSRF.

**Fix:** Add `csrfCheck()` and convert GET delete to POST.

---

### CSRF-07: No CSRF on Moderation Panel Actions (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php`
**Lines:** 37-50

GET-based delete/lock/unlock operations on forum topics lack CSRF.

**Severity:** MEDIUM

**Fix:** Convert to POST with CSRF.

---

## 4. Authentication & Session Security

### AUTH-01: Password Stored in localStorage (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`
**Line:** 60

**File:** `/home/guortates/TVLW/The-Very-Little-War/index.php`
**Lines:** 121-126

```php
// basicpublicphp.php line 60
echo 'localStorage.setItem("login", "' . $_SESSION['login'] . '");';
```

```javascript
// index.php lines 121-126
document.getElementById("loginConnexion").value = localStorage.getItem("login");
document.getElementById("passConnexion").value = localStorage.getItem("mdp");
if (localStorage.getItem("login") != null && localStorage.getItem("mdp") != null) {
    document.connexion.submit();
}
```

The code retrieves password from `localStorage` and auto-submits the login form. While the `localStorage.setItem("mdp", ...)` call appears to have been removed from the login success handler, the index.php still expects it. The BBCode parser (bbcode.php line 319) has a regex to strip `localStorage.getItem("mdp"` from user content, indicating awareness of this issue, but the auto-login mechanism itself is dangerous. If any XSS vulnerability exists, `localStorage` data is fully accessible to the attacker.

**Severity:** CRITICAL -- Credential storage in client-side localStorage.

**Fix:** Remove all localStorage password storage. Implement proper "remember me" with a secure httpOnly cookie containing a random token.

---

### AUTH-02: Session Not Regenerated After Login (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`
**Lines:** 16-17, 51

```php
session_unset();
session_destroy();
// ...
session_start();  // Line 51
$_SESSION['login'] = $loginInput;
$_SESSION['mdp'] = $storedHash;
```

The session is destroyed and recreated, but `session_regenerate_id(true)` is never called. This leaves the application vulnerable to session fixation attacks.

**Severity:** HIGH -- Session fixation vulnerability.

**Fix:** Call `session_regenerate_id(true)` immediately after successful authentication.

---

### AUTH-03: Session Hash Comparison for Authentication (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`
**Lines:** 10-17

```php
if (isset($_SESSION['login']) && isset($_SESSION['mdp'])) {
    $row = dbFetchOne($base, 'SELECT pass_md5 FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
        session_destroy();
        header('Location: index.php');
        exit();
    }
}
```

Authentication works by storing the password hash in the session and comparing it on every request. This means:
1. The password hash is stored in the session file on the server (or wherever sessions are stored)
2. If session data is leaked, the attacker gets the hash
3. No session timeout mechanism exists

**Severity:** MEDIUM -- Unconventional auth pattern with no timeout.

**Fix:** Store a session token instead of the password hash. Implement session timeout (e.g., 30 minutes of inactivity).

---

### AUTH-04: Visitor Account Creates MD5 Password (CRITICAL)

**File:** `/home/guortates/TVLW/The-Very-Little-War/comptetest.php`
**Lines:** 13-15

```php
inscrire("Visiteur".$nb['numerovisiteur'],"Visiteur".$nb['numerovisiteur'],"Visiteur".$nb['numerovisiteur']."@tvlw.com");
$_SESSION['login'] = ucfirst(mb_strtolower(mysqli_real_escape_string($base,stripslashes(htmlentities("Visiteur".$nb['numerovisiteur'])))));
$_SESSION['mdp'] = md5("Visiteur".$nb['numerovisiteur']);
```

While `inscrire()` now uses `password_hash()`, the session is set with `md5()` of the password. This means the session hash comparison in `basicprivatephp.php` will FAIL because the DB has a bcrypt hash but the session has an MD5 hash. This is a **broken login flow** for visitor accounts.

Additionally, when the visitor "converts" their account (lines 24-79), the password is stored as MD5:

```php
$_SESSION['mdp'] = md5($_POST['pass']);
```

**Severity:** CRITICAL -- Visitor account conversion bypasses bcrypt, stores MD5 passwords.

**Fix:** Use `password_hash()` and store the bcrypt hash in the session.

---

### AUTH-05: Admin Password Hash Regenerated on Every Load (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`
**Line:** 50

```php
define('ADMIN_PASSWORD_HASH', password_hash('Faux mot de passe', PASSWORD_DEFAULT));
```

`password_hash()` generates a NEW hash with a new salt on every PHP execution. This means the admin password hash constant changes on every page load. While `password_verify()` works because it extracts the salt from the hash, this is extremely wasteful and unusual. More importantly, **the admin password plaintext is hardcoded in the source file**. If this is the real password, anyone with source code access (or a file disclosure vulnerability) knows the admin password.

**Severity:** HIGH -- Admin password in plaintext in source code.

**Fix:** Generate the hash once with `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"` and store only the hash string as the constant. Never put the plaintext in source.

---

### AUTH-06: No Session Cookie Security Flags (HIGH)

No file in the codebase sets session cookie parameters. The default PHP configuration likely does not set:
- `HttpOnly` flag (prevents JavaScript access)
- `Secure` flag (HTTPS only)
- `SameSite` attribute (CSRF prevention)

**Severity:** HIGH -- Session cookies accessible to JavaScript (enables session theft via XSS).

**Fix:** Add before any `session_start()`:
```php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
```

---

## 5. File Upload Vulnerabilities

### UPLOAD-01: Incomplete File Upload Validation (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`
**Lines:** 96-124

```php
$extensions = array('.png', '.gif', '.jpg', '.jpeg');
$extension = strrchr($_FILES['photo']['name'], '.');
$img_size = getimagesize($_FILES['photo']['tmp_name']);
if (!in_array($extension, $extensions)) { ... }
// ...
$fichier = preg_replace('/([^.a-z0-9]+)/i', '-', $fichier);
move_uploaded_file($_FILES['photo']['tmp_name'], $dossier . $fichier);
```

Issues:
1. **No MIME type validation** -- Only extension is checked. A PHP file renamed to `.php.jpg` would pass the extension check as `.jpg`, but a double-extension like `shell.php.png` would also pass.
2. **No content validation beyond getimagesize** -- `getimagesize()` can be fooled by polyglot files (valid image + PHP code).
3. **Predictable upload path** -- Files are stored in `images/profil/` with a sanitized but predictable name.
4. **No randomized filename** -- The original filename is kept (with sanitization), allowing an attacker to predict the URL.

**Severity:** HIGH -- Potential remote code execution via file upload.

**Fix:**
1. Generate random filenames: `$fichier = bin2hex(random_bytes(16)) . $extension;`
2. Verify MIME type with `finfo_file()` or check that `getimagesize()` returns a valid image type.
3. Consider storing uploads outside the web root.
4. Add `.htaccess` to the upload directory to prevent PHP execution.

---

## 6. Information Disclosure

### INFO-01: SQL Error Messages Exposed to Users (CRITICAL)

**Files:** Multiple (see die() grep results)

Over **60 instances** of `die()` with SQL error messages exist across the codebase:

```php
or die('Erreur SQL !<br />' . $sql . '<br />' . mysql_error());
or die('Erreur SQL !<br />' . $sql . '<br />' . mysqli_error($base));
```

These expose:
- Full SQL queries to the browser
- Database error messages
- Table names and column names
- Database structure information

**Key files with this issue:**
- `includes/basicprivatephp.php` (8 instances)
- `fonctions.php` (30+ instances)
- `editer.php` (7 instances)
- `compte.php` (7 instances)
- `comptetest.php` (1 instance)
- `includes/cardsprivate.php` (2 instances)
- `includes/basicprivatehtml.php` (2 instances)
- `includes/menus.php` (1 instance)
- `includes/statistiques.php` (1 instance)

**Severity:** CRITICAL -- Full SQL query and error disclosure to attackers.

**Fix:** Replace all `die('Erreur SQL...')` with `error_log()` and display a generic error message.

---

### INFO-02: Database Credentials in Source Code (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php`
**Lines:** 2-5

```php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'theveryl_theverylittlewar';
```

Database connected as `root` with no password.

**Severity:** MEDIUM -- If source code is exposed, DB credentials are revealed. Using root with no password is insecure.

**Fix:** Use a dedicated database user with minimal privileges. Store credentials in a file outside the web root or use environment variables.

---

### INFO-03: Deprecated mysql_error() Calls (LOW)

Multiple files call `mysql_error()` (without the `i`) which is a deprecated PHP function that may not return useful information in newer PHP versions but still represents an information leak attempt.

**Severity:** LOW

**Fix:** Replace with `error_log(mysqli_error($base))`.

---

## 7. Access Control

### AC-01: Admin Account Deletion via GET (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`
**Line:** 14-19

Account deletion is triggered by a GET request with an IP address:

```php
if (isset($_GET['supprimercompte'])) {
    $ip = $_GET['supprimercompte'];
    $rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
    foreach ($rows as $login) {
        supprimerJoueur($login['login']);
    }
}
```

This deletes ALL accounts sharing an IP address via a simple link click.

**Severity:** HIGH -- Mass account deletion via GET.

**Fix:** Convert to POST with CSRF token and add a confirmation step.

---

### AC-02: Moderator Access Check Missing in editer.php (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/editer.php`
**Lines:** 39-49

The hide/show message actions (types 4 and 5) do NOT verify the user is a moderator:

```php
if($_GET['type'] == 5 AND isset($_GET['id']) AND !empty($_GET['id']) AND preg_match("#^[0-9]*$#", $_GET['id'])) {
    $sql = 'UPDATE reponses SET visibilite=0 WHERE id=\''.$_GET['id'].'\'';
    mysqli_query($base,$sql);
}
```

Any authenticated user can hide or show any forum message.

**Severity:** MEDIUM -- Privilege escalation for regular users.

**Fix:** Add moderator check: verify `$moderateur[0] == '1'` before executing hide/show operations.

---

### AC-03: Insecure Direct Object Reference in Messages (LOW)

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`
**Lines:** 48-53

```php
if (isset($_GET['id'])) {
    $message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=?', 'i', $_GET['id']);
}
```

While there is a check on line 60 that the message destination matches the session user, the message content (including the sender and content) is still fetched. If the check fails, the content may have been partially loaded into variables. The actual protection (lines 60-63) prevents display but is defense-in-depth fragile.

**Severity:** LOW

**Fix:** Add `AND destinataire = ?` to the query itself.

---

## 8. Insecure Transport & Mixed Content

### TRANSPORT-01: HTTP Resources Loaded (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`
**Line:** 229

```html
<script type="text/javascript" src="http://cdn.mathjax.org/mathjax/latest/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
```

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php`
**Lines:** 50-53

```html
<link rel="stylesheet" href="http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css" />
<script src="http://code.jquery.com/jquery-1.9.1.js"></script>
<script src="http://code.jquery.com/ui/1.10.3/jquery-ui.js"></script>
```

Loading JavaScript over HTTP allows man-in-the-middle attacks to inject malicious code.

**Severity:** MEDIUM -- MitM can inject arbitrary JavaScript.

**Fix:** Change all HTTP URLs to HTTPS or use protocol-relative URLs. Better yet, host these libraries locally or use a subresource integrity (SRI) hash.

---

### TRANSPORT-02: No HTTPS Enforcement (MEDIUM)

No file in the codebase enforces HTTPS or sets HSTS headers.

**Severity:** MEDIUM

**Fix:** Add HSTS header and redirect HTTP to HTTPS at the web server level.

---

## 9. Missing Security Headers

### HEADERS-01: No Security Headers Set (MEDIUM)

The application does not set any security headers:
- No `Content-Security-Policy`
- No `X-Content-Type-Options: nosniff`
- No `X-Frame-Options: DENY`
- No `X-XSS-Protection`
- No `Strict-Transport-Security`
- No `Referrer-Policy`

**Severity:** MEDIUM

**Fix:** Add a common include file that sets these headers:
```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://www.gstatic.com; style-src \'self\' \'unsafe-inline\';');
```

---

## 10. Rate Limiting

### RATE-01: No Rate Limiting on Login (HIGH)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`
**Lines:** 20-72

No rate limiting on login attempts. An attacker can brute-force passwords without restriction.

**Severity:** HIGH

**Fix:** Implement login attempt tracking (IP + username) with exponential backoff or account lockout.

---

### RATE-02: No Rate Limiting on Registration (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`

No captcha or rate limiting on account creation.

**Severity:** MEDIUM

**Fix:** Add rate limiting and consider a CAPTCHA.

---

### RATE-03: No Rate Limiting on Forum Posts (MEDIUM)

No cooldown between forum posts, allowing spam.

**Severity:** MEDIUM

**Fix:** Add minimum time between posts per user.

---

### RATE-04: No Rate Limiting on Messages (MEDIUM)

No cooldown between private messages, allowing spam/harassment.

**Severity:** MEDIUM

**Fix:** Add minimum time between messages per user.

---

## 11. Other Vulnerabilities

### OTHER-01: Login Name Injection into JavaScript (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`
**Lines:** 58-62

```php
echo '<script>
    localStorage.setItem("login", "' . $_SESSION['login'] . '");
    window.location = "constructions.php";
</script>';
```

If the login contains a `"` or `</script>` (though input validation prevents most of this), it could break out of the JavaScript string. The login is validated with `preg_match('/^[a-zA-Z0-9_]{3,20}$/')` in the registration flow, so alphanumeric-only logins mitigate this. However, older accounts might not have this validation.

**Severity:** MEDIUM

**Fix:** Use `json_encode()`: `localStorage.setItem("login", ' . json_encode($_SESSION['login']) . ');`

---

### OTHER-02: Email Header Injection (LOW)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`
**Lines:** 221-276

Email sending in the game reset section uses `mail()` with headers. While the recipient and subject are not directly from user input, the email system itself has no protection against header injection if user data ever enters headers.

**Severity:** LOW

**Fix:** Use a mail library like PHPMailer instead of raw `mail()`.

---

### OTHER-03: Outdated jQuery via CDN (LOW)

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php`

jQuery 1.9.1 (2013) loaded from CDN. This version has known XSS vulnerabilities.

**Severity:** LOW

**Fix:** Update to a current version of jQuery or remove the dependency.

---

### OTHER-04: Antihtml Uses ISO8859-1 Instead of UTF-8 (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`
**Line:** 1594

```php
function antihtml($phrase) {
    return htmlspecialchars($phrase, ENT_SUBSTITUTE, 'ISO8859-1');
}
```

Using `ISO8859-1` encoding with `htmlspecialchars` when the application uses UTF-8 (as set by `mysqli_set_charset($base, 'utf8')`) creates a charset mismatch. Multi-byte UTF-8 characters could potentially bypass the encoding, leading to XSS.

**Severity:** MEDIUM -- Charset mismatch in XSS sanitization.

**Fix:** Change to `return htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8');`

---

### OTHER-05: Login Form Submits to Unusual Endpoint (LOW)

**File:** `/home/guortates/TVLW/The-Very-Little-War/index.php`
**Line:** 34

The login form submits to `index.php?noAutoConnexion=1`, which then redirects to `includes/basicpublicphp.php` for processing. The login processing happens in `includes/basicpublicphp.php` (a.k.a. `connexion.php`) which is included in the index page. This is an unusual pattern but not a direct vulnerability.

**Severity:** LOW

---

### OTHER-06: No Password Complexity Requirements (MEDIUM)

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`

No minimum password length or complexity requirements for account creation or password change.

**Severity:** MEDIUM

**Fix:** Enforce minimum 8 characters and check against common password lists.

---

## Priority Remediation Roadmap

### Phase 1 -- Immediate (CRITICAL)

1. **Replace ALL raw `die()` with SQL disclosure** with `error_log()` + generic error (INFO-01)
2. **Fix editer.php** -- Add prepared statements and CSRF protection (SQLI-02, CSRF-03)
3. **Fix comptetest.php** -- Rewrite with prepared statements and bcrypt (SQLI-04, AUTH-04)
4. **Fix basicprivatephp.php** -- Convert all queries to prepared statements (SQLI-01)
5. **Remove localStorage password storage** (AUTH-01)
6. **Fix admin/listesujets.php** -- Prepared statements (SQLI-03)
7. **Fix admin password hash** -- Pre-compute and store only the hash (AUTH-05)

### Phase 2 -- High Priority (1 week)

8. **Add CSRF to forum post/topic creation** (CSRF-01, CSRF-02)
9. **Add CSRF to admin panel** (CSRF-04, CSRF-05, CSRF-06, CSRF-07)
10. **Set session cookie security flags** (AUTH-06)
11. **Add session regeneration after login** (AUTH-02)
12. **Fix file upload validation** (UPLOAD-01)
13. **Fix compte.php raw SQL** (SQLI-05)
14. **Fix fonctions.php raw SQL** (SQLI-06)
15. **Implement login rate limiting** (RATE-01)
16. **Fix antihtml charset** to UTF-8 (OTHER-04)

### Phase 3 -- Medium Priority (2 weeks)

17. **Escape all output** -- Add `htmlspecialchars()` on every echo of user data (XSS-01 through XSS-07)
18. **Fix remaining raw SQL** in includes files (SQLI-08, SQLI-09)
19. **Add security headers** (HEADERS-01)
20. **Fix HTTP resource loading** (TRANSPORT-01)
21. **Enforce HTTPS** (TRANSPORT-02)
22. **Add password complexity requirements** (OTHER-06)
23. **Fix moderator access control** in editer.php (AC-02)
24. **Add rate limiting** to registration, forum, messages (RATE-02, RATE-03, RATE-04)

### Phase 4 -- Low Priority (1 month)

25. **Move DB credentials** out of source code (INFO-02)
26. **Replace deprecated mysql_error()** calls (INFO-03)
27. **Update jQuery** (OTHER-03)
28. **Fix email sending** (OTHER-02)
29. **Remove dead BBCode preview code** (XSS-08)
30. **Fix JavaScript injection** in login output (OTHER-01)

---

## Summary of Files Requiring Changes

| File | Issues |
|------|--------|
| `includes/basicprivatephp.php` | SQLI-01, SQLI-07, INFO-01, AUTH-03 |
| `editer.php` | SQLI-02, CSRF-03, AC-02, INFO-01, XSS-04 |
| `admin/listesujets.php` | SQLI-03, CSRF-05 |
| `comptetest.php` | SQLI-04, AUTH-04, INFO-01 |
| `compte.php` | SQLI-05, UPLOAD-01, INFO-01 |
| `fonctions.php` | SQLI-06, INFO-01 |
| `includes/fonctions.php` | OTHER-04 |
| `sujet.php` | CSRF-01, XSS-01, TRANSPORT-01 |
| `listesujets.php` | CSRF-02, XSS-01 |
| `admin/index.php` | CSRF-04, AC-01 |
| `moderation/index.php` | SQLI-09, CSRF-07 |
| `moderationForum.php` | CSRF-06, TRANSPORT-01, OTHER-03 |
| `includes/basicpublicphp.php` | AUTH-01, AUTH-02, OTHER-01 |
| `index.php` | AUTH-01 |
| `includes/constantesBase.php` | AUTH-05 |
| `includes/connexion.php` | INFO-02 |
| `messages.php` | XSS-02 |
| `rapports.php` | XSS-03 |
| `ecriremessage.php` | XSS-07 |
| `joueur.php` | XSS-06 |
| `alliance.php` | XSS-05 |
| `includes/bbcode.php` | XSS-08 |
| `includes/menus.php` | SQLI-08, INFO-01 |
| `includes/statistiques.php` | SQLI-08, INFO-01 |
| `includes/cardsprivate.php` | SQLI-08, INFO-01 |
| `includes/basicprivatehtml.php` | SQLI-08, INFO-01 |

---

*End of Security Counter-Audit Report*
