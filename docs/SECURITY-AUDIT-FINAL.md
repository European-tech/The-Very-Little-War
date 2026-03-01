# Security Audit - Final Pass (Pass 3 of 3)

**Date:** 2026-03-02
**Auditor:** Security Audit (Automated)
**Scope:** All PHP files in /home/guortates/TVLW/The-Very-Little-War/
**Type:** Read-only comprehensive security review

## Summary

This is the third and final security audit pass. Previous audits fixed SQL injection,
XSS, CSRF, password security, session hardening, rate limiting, file upload security,
security headers, and BBCode parser hardening.

This final pass identified **7 CRITICAL**, **9 HIGH**, **11 MEDIUM**, **6 LOW**, and
**7 INFO** findings. The majority of CRITICAL findings are concentrated in `voter.php`,
which appears to be a completely unfixed legacy file operating outside the main
application framework.

---

## CRITICAL Findings

### C-01: voter.php - Hardcoded Database Credentials
- **Severity:** CRITICAL
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, line 4
- **Description:** The file contains hardcoded database credentials in plain text:
  `$base = mysqli_connect('localhost', 'theveryl_admin', 'mno33d65e');`
  This file uses its own direct database connection bypassing the shared `connexion.php`
  configuration. The credentials are exposed in source code and differ from the main
  application credentials in `connexion.php`.
- **Impact:** Full database compromise if source is exposed. Credential leakage.
- **Recommendation:** Remove hardcoded credentials. Use `includes/connexion.php` instead.

### C-02: voter.php - Multiple SQL Injection Vulnerabilities
- **Severity:** CRITICAL
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, lines 13, 17, 22
- **Description:** User input from `$_GET['login']` and `$_GET['reponse']` is directly
  concatenated into SQL queries without any sanitization or prepared statements:
  ```php
  mysqli_query($base,'SELECT count(*),id AS nb FROM reponses WHERE login=\''.$_GET['login'].'\' AND sondage=\''.$data['id'].'\'');
  mysqli_query($base,'INSERT INTO reponses VALUES(default,"'.$_GET['login'].'","'.$data['id'].'","'.$_GET['reponse'].'")');
  mysqli_query($base,'UPDATE reponses SET reponse=\''.$_GET['reponse'].'\' WHERE login=\''.$_GET['login'].'\'...');
  ```
- **Impact:** Full database read/write/delete. Potential server compromise.
- **Recommendation:** Convert all queries to use `dbQuery`/`dbExecute` with prepared statements.

### C-03: voter.php - No Authentication Required
- **Severity:** CRITICAL
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, lines 1-30
- **Description:** The file performs database operations based on `$_GET['login']`
  without any session validation. Any anonymous user can vote as any player by supplying
  their username in the URL. No CSRF protection exists either.
- **Impact:** Vote manipulation, impersonation of any player.
- **Recommendation:** Require session authentication. Use `$_SESSION['login']` instead
  of `$_GET['login']`. Add CSRF protection.

### C-04: voter.php - Wildcard CORS Header
- **Severity:** CRITICAL
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, line 3
- **Description:** `header("Access-Control-Allow-Origin: *");` allows any website to
  make cross-origin requests to this endpoint. Combined with the SQL injection and lack
  of authentication, this enables remote exploitation from any website.
- **Impact:** Any malicious website can exploit the SQL injection vulnerabilities
  on behalf of any visitor.
- **Recommendation:** Remove the wildcard CORS header or restrict to the game domain.

### C-05: voter.php - Information Disclosure via mysql_error()
- **Severity:** CRITICAL
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, line 5
- **Description:** `die('Erreur de connexion a la base de donnees'.mysql_error());`
  leaks database error details. Also uses the deprecated `mysql_error()` function
  (not even `mysqli_error()`), which will produce a PHP warning/error itself.
- **Impact:** Database schema and error information disclosed to attackers.
- **Recommendation:** Log errors server-side. Show generic error to users.

### C-06: Password Stored in Client localStorage
- **Severity:** CRITICAL
- **Status:** NEW (design-level issue)
- **File:** `/home/guortates/TVLW/The-Very-Little-War/index.php`, lines 121-126
- **Description:** The auto-login system reads from `localStorage.getItem("mdp")`
  and auto-submits the login form. While the `localStorage.setItem` for "mdp" was
  apparently removed from `basicpublicphp.php` (only "login" is stored at line 62),
  the index.php still reads it. The `deconnexion.php` also clears `localStorage["mdp"]`
  at line 25, confirming this mechanism existed. If any code path still writes the
  password to localStorage, it would be accessible to any XSS attack on the domain.
- **Impact:** Password exposure via any XSS vulnerability or browser extension access.
- **Recommendation:** Remove all localStorage password storage. Use server-side
  "remember me" tokens with httponly cookies instead.

### C-07: admin/index.php - Account Deletion via GET Without CSRF
- **Severity:** CRITICAL
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`, line 18-24
- **Description:** Account deletion is triggered via GET parameter
  `$_GET['supprimercompte']` without CSRF protection:
  ```php
  if (isset($_GET['supprimercompte'])) {
      $ip = $_GET['supprimercompte'];
      $rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
      foreach ($rows as $login) {
          supprimerJoueur($login['login']);
      }
  }
  ```
  This means a link like `admin/index.php?supprimercompte=1.2.3.4` can delete all
  accounts from an IP. Can be exploited via image tags or link previews.
- **Impact:** Mass account deletion if admin is tricked into clicking a link.
- **Recommendation:** Change to POST method with CSRF token verification.

---

## HIGH Findings

### H-01: admin/index.php - Raw mysqli_query Without Prepared Statements
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`, lines 27, 31, 111
- **Description:** Three raw `mysqli_query()` calls remain:
  ```php
  mysqli_query($base, 'UPDATE statistiques SET maintenance = 1');
  mysqli_query($base, 'UPDATE statistiques SET maintenance = 0');
  $sqlMaintenance = mysqli_query($base, 'SELECT maintenance FROM statistiques');
  ```
  While these do not contain user input and are not directly exploitable, they bypass
  the prepared statement pattern established for the codebase.
- **Impact:** Low direct risk (no user input), but inconsistent security pattern.
- **Recommendation:** Convert to `dbExecute`/`dbQuery` for consistency.

### H-02: includes/redirectionVacance.php - Raw mysqli_query With Session Data
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/redirectionVacance.php`, lines 4-6
- **Description:** Raw SQL query with session data:
  ```php
  $sqlJoueurVac = 'SELECT vacance FROM membre WHERE login=\''.$_SESSION['login'].'\'';
  $exJoueurVac = mysqli_query($base,$sqlJoueurVac);
  ```
  While `$_SESSION['login']` is server-controlled, this bypasses the prepared statement
  pattern. If session data were ever tainted, this becomes injectable.
- **Impact:** Moderate risk. Session data is validated but pattern is unsafe.
- **Recommendation:** Convert to `dbFetchOne($base, 'SELECT vacance FROM membre WHERE login=?', 's', $_SESSION['login'])`.

### H-03: includes/basicpublicphp.php - Raw mysqli_query Calls
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`, lines 29, 80
- **Description:** Two raw `mysqli_query()` calls remain:
  ```php
  $a = mysqli_query($base, "SELECT login FROM membre WHERE login LIKE 'Visiteur%' AND derniereConnexion < " . (time() - 3600 * 3) . "");
  mysqli_query($base, 'DELETE FROM connectes WHERE timestamp < ' . $timestamp_5min);
  ```
  The first concatenates a computed timestamp. The second uses a computed value.
  Neither contains user input but both bypass the prepared statement standard.
- **Impact:** Low direct risk, but `time()` values are predictable and the pattern is unsafe.
- **Recommendation:** Convert to prepared statements.

### H-04: includes/fonctions.php - Legacy query() Function
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`, lines 1677-1686
- **Description:** A legacy `query()` function wraps raw `mysqli_query()`:
  ```php
  function query($truc) {
      global $base;
      $ex = mysqli_query($base, $truc);
  }
  ```
  This function is still called in `video.php` line 25 with user-controlled data.
- **Impact:** Any caller passing user input creates SQL injection.
- **Recommendation:** Remove this function. Convert all callers to use `dbQuery`.

### H-05: video.php - SQL Injection via query() Function
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/video.php`, line 25
- **Description:** User input is passed to the unsafe `query()` function:
  ```php
  $_GET['id'] = antiXSS($_GET['id']);
  $ex = query('SELECT lien FROM liens WHERE id=\''.$_GET['id'].'\'');
  ```
  While `antiXSS()` applies `mysqli_real_escape_string`, the pattern is fragile and
  the `query()` function should not exist.
- **Impact:** Mitigated by `antiXSS()` but still a risky pattern.
- **Recommendation:** Convert to `dbQuery($base, 'SELECT lien FROM liens WHERE id=?', 's', $_GET['id'])`.

### H-06: maintenance.php - Raw mysqli_query
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/maintenance.php`, line 6
- **Description:** `$retour = mysqli_query($base, 'SELECT * FROM news ORDER BY id DESC LIMIT 0, 1');`
  While this contains no user input, it bypasses the prepared statement standard.
- **Impact:** Low direct risk, inconsistent pattern.
- **Recommendation:** Convert to `dbQuery`.

### H-07: maintenance.php - Potential Stored XSS via News Content
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/maintenance.php`, lines 8-14
- **Description:** News content and title are output without escaping:
  ```php
  $contenu = nl2br(stripslashes($donnees['contenu']));
  echo important($donnees['titre'] . '<em> le ' . date(...) . '</em>');
  echo $contenu;
  ```
  If an admin injects malicious content via the news editor, it renders unescaped.
  The news title is also not escaped. While only admins can create news, the content
  is displayed to all users visiting the maintenance page.
- **Impact:** Stored XSS if admin account is compromised or admin is malicious.
- **Recommendation:** Apply `htmlspecialchars()` to `$donnees['titre']` and `$donnees['contenu']`.

### H-08: ecriremessage.php - Unescaped Message Content in Textarea
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`, line 100
- **Description:** Message content is output in a textarea without escaping:
  ```php
  item(['floating' => true, 'titre' => "Contenu", 'input' => '<textarea ...>' . $options . '</textarea>']);
  ```
  The `$options` variable contains raw message content or POST data. If a message
  contains `</textarea><script>...`, it could break out of the textarea.
- **Impact:** Reflected or stored XSS via message reply content.
- **Recommendation:** Apply `htmlspecialchars($options, ENT_QUOTES, 'UTF-8')` before
  placing in textarea.

### H-09: allianceadmin.php - Unescaped Output in HTML Attributes
- **Severity:** HIGH
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 335, 337, 341
- **Description:** Alliance name, tag, and description are output with only `stripslashes()`
  but no `htmlspecialchars()`:
  ```php
  'value="' . stripslashes($chef['nom']) . '"'
  'value="' . stripslashes($chef['tag']) . '"'
  '<textarea ...>' . $chef['description'] . '</textarea>'
  ```
- **Impact:** Stored XSS if alliance names/tags/descriptions contain HTML.
- **Recommendation:** Apply `htmlspecialchars($chef['nom'], ENT_QUOTES, 'UTF-8')` etc.

---

## MEDIUM Findings

### M-01: constructions.php - Echoing POST Data (Minor XSS)
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php`, lines 15, 52
- **Description:** POST values are echoed after `intval()` conversion:
  ```php
  echo $_POST['nbPoints' . $ressource];
  echo $_POST['nbPointsCondenseur' . $ressource];
  ```
  After `intval()`, the values are safe integers (intval returns 0 for non-numeric).
  However, the raw `$_POST` variable is echoed, not the intval result. The intval is
  applied to the POST variable before echo, so it is actually safe, but the pattern
  is fragile.
- **Impact:** Low. intval() is applied before echo.
- **Recommendation:** Echo the intval result explicitly: `echo intval($_POST[...]);`

### M-02: armee.php - Unescaped POST in Hidden Field
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`, line 315
- **Description:**
  ```php
  <input type="hidden" name="emplacementmoleculecreer1" value="<?php echo $_POST['emplacementmoleculecreer']; ?>" />
  ```
  User POST data is echoed into a hidden field without escaping.
- **Impact:** Reflected XSS via crafted POST request.
- **Recommendation:** Apply `htmlspecialchars($_POST['emplacementmoleculecreer'], ENT_QUOTES, 'UTF-8')`.

### M-03: sinstruire.php - Unescaped GET in Links
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/sinstruire.php`, lines 316, 320
- **Description:** `$_GET['cours']` is used in arithmetic operations and output in links:
  ```php
  echo '<a href="sinstruire.php?cours='.($_GET['cours'] - 1).'">';
  ```
  PHP's arithmetic coercion means non-numeric input becomes 0, but the GET value
  is also used in comparisons at lines 315 and 319 without explicit casting.
- **Impact:** Low. PHP arithmetic coercion provides implicit protection.
- **Recommendation:** Cast to int: `$cours = (int)$_GET['cours'];`

### M-04: historique.php - Unescaped GET in Form Action
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/historique.php`, line 34
- **Description:**
  ```php
  echo '<form action="historique.php?sub='.$_GET['sub'].'" method="post">';
  ```
  While `$_GET['sub']` has been passed through `mysqli_real_escape_string` and `antihtml`
  at line 16, the value is placed in an HTML attribute where different escaping rules
  apply. `antihtml()` uses `htmlspecialchars` with `ISO8859-1` charset instead of `UTF-8`.
- **Impact:** Potential XSS via multi-byte character encoding attacks.
- **Recommendation:** Use `htmlspecialchars($_GET['sub'], ENT_QUOTES, 'UTF-8')` in HTML context.

### M-05: antihtml() Uses Wrong Charset
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`, line 1591
- **Description:** `antihtml()` uses `ISO8859-1` charset:
  ```php
  function antihtml($phrase) {
      return htmlspecialchars($phrase, ENT_SUBSTITUTE, 'ISO8859-1');
  }
  ```
  The application uses UTF-8 encoding (`mysqli_set_charset($base, 'utf8')`).
  Using ISO8859-1 for HTML escaping can allow multi-byte character bypasses.
- **Impact:** Potential XSS bypass via UTF-8 multi-byte sequences.
- **Recommendation:** Change to `htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8')`.

### M-06: Session Cookie Not Set Secure
- **Severity:** MEDIUM
- **Status:** NEW (acknowledged trade-off)
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`, line 8
- **Description:** `ini_set('session.cookie_secure', 0);` means session cookies
  are transmitted over unencrypted HTTP connections.
- **Impact:** Session hijacking via network sniffing on non-HTTPS connections.
- **Recommendation:** Enable HTTPS and set `session.cookie_secure` to 1. Add HSTS header.

### M-07: Admin Panel Missing CSRF on POST Forms
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`, lines 109, 154
- **Description:** The admin panel forms for maintenance toggle and reset do not
  include CSRF tokens:
  ```php
  <form action="index.php" method="post">
      <input type="submit" name="maintenance" value="Mise en maintenance" />
  </form>
  ```
- **Impact:** Admin actions (maintenance mode, game reset) can be triggered by CSRF.
- **Recommendation:** Add `csrfField()` to all admin forms. Add `csrfCheck()` for POST handling.

### M-08: Admin Password Hash Regenerated on Every Load
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`, line 52
- **Description:**
  ```php
  define('ADMIN_PASSWORD_HASH', password_hash('Faux mot de passe', PASSWORD_DEFAULT));
  ```
  This generates a new hash on every page load since `password_hash` produces different
  output each time. The actual password "Faux mot de passe" (meaning "Fake password")
  is embedded in source code. This means the admin password is literally in the codebase.
- **Impact:** Anyone with source code access knows the admin password. The hash is
  regenerated each request, wasting CPU cycles.
- **Recommendation:** Store a pre-computed hash as a string constant. Move the actual
  password to an environment variable or separate config file outside web root.

### M-09: moderationForum.php - Missing CSRF Protection
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php`, lines 6-9, 12-27
- **Description:** The forum moderation page allows deleting sanctions via GET (line 6-9)
  and creating bans via POST (line 12-27) without CSRF verification:
  ```php
  if (isset($_GET['supprimer'])) {
      dbExecute($base, 'DELETE FROM sanctions WHERE idSanction = ?', 'i', $supprimerId);
  }
  ```
  The ban creation form also lacks `csrfField()` and `csrfCheck()`.
- **Impact:** A moderator can be tricked into deleting sanctions or banning players.
- **Recommendation:** Add CSRF protection to both GET (convert to POST) and POST actions.

### M-10: admin/supprimercompte.php - Missing CSRF Protection
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/supprimercompte.php`, lines 5-15
- **Description:** Account deletion form does not include CSRF token:
  ```php
  if (isset($_POST['supprimercompte'])) {
      supprimerJoueur($_POST['supprimer']);
  }
  ```
  No `csrfCheck()` call and no `csrfField()` in the form.
- **Impact:** CSRF-triggered account deletion from admin panel.
- **Recommendation:** Add `csrfField()` to form and `csrfCheck()` to POST handler.

### M-11: admin/listenews.php - Missing CSRF on News Operations
- **Severity:** MEDIUM
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/admin/listenews.php`, lines 39-61
- **Description:** News creation/modification via POST and deletion via GET have no
  CSRF protection. The news deletion is particularly concerning as it uses GET:
  ```php
  if (isset($_GET['supprimer_news'])) {
      dbExecute($base, 'DELETE FROM news WHERE id = ?', 'i', $supprimerNewsId);
  }
  ```
- **Impact:** News can be created, modified, or deleted via CSRF attacks.
- **Recommendation:** Add CSRF protection. Convert deletion to POST.

---

## LOW Findings

### L-01: index.php - Unescaped News Content on Homepage
- **Severity:** LOW
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/index.php`, lines 51, 55
- **Description:** News content displayed on homepage without escaping:
  ```php
  $contenuNews = nl2br(stripslashes($donnees['contenu']));
  itemAccordion($donnees['titre'], ..., $contenuNews);
  ```
  Only admins can create news, so exploitation requires admin compromise.
- **Impact:** Low. Requires admin-level access to exploit.
- **Recommendation:** Apply htmlspecialchars to title and content.

### L-02: alliance.php - Unescaped Alliance Name Display
- **Severity:** LOW
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 142, 156
- **Description:** Alliance name and tag displayed with `stripslashes()` only:
  ```php
  debutCarte(stripslashes($allianceJoueurPage['nom']));
  echo chipInfo('... TAG : ...' . stripslashes($allianceJoueurPage['tag']), ...);
  ```
- **Impact:** Stored XSS if alliance names contain HTML. Mitigated by input validation
  on creation (tag regex `^[a-zA-Z0-9_]{3,16}$`), but name has no such restriction.
- **Recommendation:** Apply `htmlspecialchars()` to both values.

### L-03: migrations/migrate.php - Raw mysqli_query
- **Severity:** LOW
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/migrations/migrate.php`, lines 15, 25
- **Description:** Uses raw `mysqli_query()` for schema creation and migration tracking.
  This is a CLI migration script, not web-accessible.
- **Impact:** None if not web-accessible. The .htaccess blocks `.php` in subdirectories
  is not configured, but the queries contain no user input.
- **Recommendation:** Ensure migration scripts are not web-accessible. Consider
  moving outside webroot.

### L-04: Multiple Files - Use of die() for Error Handling
- **Severity:** LOW
- **Status:** NEW
- **Files:** `connexion.php:10`, `basicpublicphp.php:20`, `inscription.php:13`, `csrf.php:27`
- **Description:** Several files use `die()` which abruptly terminates execution
  and may expose partial HTML or miss cleanup. The messages are generic (good) but
  the abrupt termination is not ideal.
- **Impact:** Minor information disclosure, poor user experience.
- **Recommendation:** Use proper error handling with HTTP status codes and clean error pages.

### L-05: moderationForum.php - Loading jQuery from HTTP
- **Severity:** LOW
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php`, lines 50-53
- **Description:** jQuery is loaded from `http://code.jquery.com/` (HTTP, not HTTPS).
  Also loads from an old version (1.9.1) with known vulnerabilities.
  ```html
  <script src="http://code.jquery.com/jquery-1.9.1.js"></script>
  <script src="http://code.jquery.com/ui/1.10.3/jquery-ui.js"></script>
  ```
- **Impact:** Man-in-the-middle attack could inject malicious JavaScript. Also,
  jQuery 1.9.1 has known XSS vulnerabilities.
- **Recommendation:** Use HTTPS URLs. Add Subresource Integrity (SRI) hashes.
  Update to current jQuery version or self-host.

### L-06: sujet.php - Loading MathJax from HTTP
- **Severity:** LOW
- **Status:** NEW
- **File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, line 231
- **Description:** MathJax loaded over HTTP:
  ```html
  <script src="http://cdn.mathjax.org/mathjax/latest/MathJax.js?..."></script>
  ```
  This CDN domain (cdn.mathjax.org) has been deprecated and may redirect or fail.
- **Impact:** MITM attack vector. Deprecated CDN may serve unexpected content.
- **Recommendation:** Use `https://cdnjs.cloudflare.com/ajax/libs/mathjax/...` with SRI.

---

## INFO Findings

### I-01: MD5 Still Used for Legacy Password Migration
- **Severity:** INFO
- **Status:** ALREADY FIXED (by design)
- **Files:** `basicpublicphp.php:43`, `compte.php:50`
- **Description:** `md5()` is used only as a fallback check during login and password
  change to detect legacy MD5 hashes and auto-upgrade to bcrypt. This is the correct
  migration pattern.
- **Impact:** None. Legacy hashes are upgraded on first use.
- **Assessment:** Working as designed. Will become unnecessary once all users have logged
  in at least once.

### I-02: MD5 Used for Rate Limiter File Names
- **Severity:** INFO
- **Status:** ALREADY FIXED (by design)
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php`, line 15
- **Description:** `md5($identifier . '_' . $action)` used to generate cache filenames.
  This is not a security-sensitive use of MD5 -- it is only used as a hash for
  filename generation, not for password storage or authentication.
- **Impact:** None.
- **Assessment:** Acceptable use of MD5 for non-security purposes.

### I-03: MD5 Used for Email Boundary
- **Severity:** INFO
- **Status:** ALREADY FIXED (by design)
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`, line 233
- **Description:** `md5(rand())` used to generate MIME boundary for emails.
  Not security-sensitive.
- **Impact:** None.
- **Assessment:** Acceptable. Not a security-sensitive use.

### I-04: No eval/system/exec/passthru Found
- **Severity:** INFO
- **Status:** ALREADY FIXED
- **Description:** Search for `eval()`, `system()`, `exec()`, `passthru()`,
  `shell_exec()`, `proc_open()`, `popen()` returned no results in PHP code.
  JavaScript `.exec()` regex calls in `admin/tableau.php` are not PHP command
  execution and are safe.
- **Impact:** None.
- **Assessment:** No command injection vectors exist.

### I-05: No Local File Inclusion (LFI) Found
- **Severity:** INFO
- **Status:** ALREADY FIXED
- **Description:** All `include`/`require` calls use hardcoded paths. No instances
  of `include($variable)` or user-controlled file paths in includes.
- **Impact:** None.
- **Assessment:** LFI vectors have been eliminated.

### I-06: .htaccess Security Headers Present but Missing CSP
- **Severity:** INFO
- **Status:** PARTIAL (noted for future)
- **File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
- **Description:** Good security headers are in place:
  - X-Content-Type-Options: nosniff
  - X-Frame-Options: SAMEORIGIN
  - X-XSS-Protection: 1; mode=block
  - Referrer-Policy: strict-origin-when-cross-origin
  - display_errors: off

  Missing:
  - Content-Security-Policy (CSP) header
  - Strict-Transport-Security (HSTS) -- requires HTTPS first
  - Permissions-Policy
- **Impact:** Browser-level protections are not maximized.
- **Recommendation:** Add a CSP header once all inline scripts are migrated.
  Add HSTS once HTTPS is enforced.

### I-07: CSRF Token Does Not Rotate Per-Request
- **Severity:** INFO
- **Status:** NOTED
- **File:** `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`
- **Description:** The CSRF token is generated once per session and reused until
  the session ends. Best practice is per-request or per-form token rotation.
  However, the current implementation using `bin2hex(random_bytes(32))` with
  `hash_equals()` comparison is cryptographically sound and the per-session approach
  is a valid trade-off for usability (prevents issues with back button, multiple tabs).
- **Impact:** Minimal. Per-session tokens are an accepted pattern.
- **Assessment:** Acceptable. No action needed.

---

## Race Condition Assessment

### No Critical Race Conditions Found

Resource operations (construction, market trades, attacks, energy donations) follow
a read-check-write pattern that is typical for this type of PHP application. While
not using database transactions or row-level locking, the practical risk is low because:

1. The game operates on per-player resources (each player modifies their own data).
2. PHP request handling is sequential per-user (single session).
3. The market uses computed values and prices drift naturally.

For a more robust implementation, database transactions (BEGIN/COMMIT) should wrap
multi-step operations like attacks and market trades. This is a game balance concern
more than a security vulnerability.

---

## Previously Fixed Items (Verified)

The following items from previous audits were verified as properly fixed:

- [VERIFIED] SQL injection: Most queries use `dbQuery`/`dbExecute`/`dbFetchOne`/`dbFetchAll`
- [VERIFIED] XSS: Most user-facing output uses `htmlspecialchars()`
- [VERIFIED] CSRF: All major game forms include `csrfField()` and `csrfCheck()`
- [VERIFIED] Password security: bcrypt with `password_hash()`/`password_verify()` and MD5 auto-migration
- [VERIFIED] Session security: httponly cookies, strict mode, session regeneration
- [VERIFIED] Rate limiting: Login (10/5min) and registration (3/hour) rate limits
- [VERIFIED] File upload: MIME validation, extension whitelist, random filenames, size checks
- [VERIFIED] Directory listing: Disabled via .htaccess `Options -Indexes`
- [VERIFIED] BBCode parser: Runs `htmlentities()` before BBCode replacement
- [VERIFIED] Admin auth: Uses `password_verify()` against stored hash
- [VERIFIED] Prepared statements: `dbQuery`/`dbExecute` wrapper functions properly implemented

---

## Priority Remediation Order

1. **IMMEDIATE:** Fix `voter.php` -- this is a completely vulnerable file with SQL injection,
   no auth, hardcoded credentials, and wildcard CORS. Either rewrite from scratch using
   the secure patterns or disable it entirely.

2. **URGENT:** Fix `admin/index.php` GET-based account deletion (C-07). Add CSRF to
   all admin forms (M-07, M-10, M-11).

3. **HIGH:** Remove localStorage password storage mechanism (C-06). Fix remaining
   raw `mysqli_query()` calls (H-01 through H-06). Fix XSS in allianceadmin.php (H-09)
   and ecriremessage.php (H-08).

4. **MEDIUM:** Fix `antihtml()` charset (M-05). Add CSRF to moderationForum.php (M-09).
   Fix unescaped outputs in historique.php, armee.php, constructions.php.

5. **LOW:** Update external JS library URLs to HTTPS. Fix remaining `stripslashes()`
   without `htmlspecialchars()` instances.

---

## Files Fully Audited

All 88 PHP files were examined:

```
admin/index.php, admin/ip.php, admin/listenews.php, admin/listesujets.php,
admin/redirectionmotdepasse.php, admin/redigernews.php, admin/supprimercompte.php,
admin/supprimerreponse.php, admin/tableau.php, alliance.php, allianceadmin.php,
annonce.php, api.php, armee.php, atomes.php, attaque.php, attaquer.php,
classement.php, compte.php, comptetest.php, connectes.php, constructions.php,
credits.php, deconnexion.php, don.php, ecriremessage.php, editer.php, forum.php,
guerre.php, historique.php, includes/atomes.php, includes/basicprivatehtml.php,
includes/basicprivatephp.php, includes/basicpublichtml.php, includes/basicpublicphp.php,
includes/bbcode.php, includes/cardsprivate.php, includes/cardspublic.php,
includes/combat.php, includes/config.php, includes/connexion.php,
includes/constantes.php, includes/constantesBase.php, includes/copyright.php,
includes/csrf.php, includes/database.php, includes/fonctions.php,
includes/logger.php, includes/menus.php, includes/meta.php, includes/mots.php,
includes/partenariat.php, includes/rate_limiter.php, includes/redirectionVacance.php,
includes/ressources.php, includes/statistiques.php, includes/style.php,
includes/tout.php, includes/update.php, includes/validation.php, index.php,
inscription.php, joueur.php, listesujets.php, maintenance.php, marche.php,
medailles.php, messageCommun.php, messages.php, messagesenvoyes.php,
migrations/migrate.php, moderation/index.php, moderation/ip.php, moderation/mdp.php,
moderationForum.php, molecule.php, rapports.php, regles.php, sinstruire.php,
sujet.php, tests/*, tutoriel.php, vacance.php, validerpacte.php, version.php,
video.php, voter.php
```

---

## Conclusion

The codebase has been significantly hardened by the two previous audit passes. The
majority of the application now uses prepared statements, proper XSS escaping, and
CSRF protection. The most critical remaining vulnerability is `voter.php`, which was
entirely missed by previous audits and contains multiple severe vulnerabilities
including SQL injection with hardcoded credentials and no authentication. The admin
panel also needs CSRF hardening. Once these items are addressed, the application will
have a substantially improved security posture for a legacy PHP game.
