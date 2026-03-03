# Admin & Moderation Panel Security Audit -- Round 1

**Auditor:** Claude Opus 4.6 (Security Audit Agent)
**Date:** 2026-03-03
**Scope:** All admin (`admin/`) and moderation (`moderation/`, `moderationForum.php`) files
**Total findings:** 24

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3     |
| HIGH     | 8     |
| MEDIUM   | 9     |
| LOW      | 4     |

---

## CRITICAL Findings

### [ADMIN-R1-001] CRITICAL admin/index.php:4 -- No session_regenerate_id on admin login (session fixation)

When the admin password is verified at line 16-17, `$_SESSION['motdepasseadmin']` is set to `true` but `session_regenerate_id(true)` is never called. The file uses bare `session_start()` (line 4) instead of the hardened `session_init.php`. An attacker who can set or predict a session ID (e.g., via cookie injection on a subdomain) can wait for the admin to log in and then hijack the elevated session.

```php
// admin/index.php lines 16-18
if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
    $_SESSION['motdepasseadmin'] = true;  // No session_regenerate_id(true)!
    logInfo('ADMIN', 'Admin login successful');
}
```

**Impact:** Session fixation allows full admin takeover.
**Remediation:** Call `session_regenerate_id(true)` immediately after setting `$_SESSION['motdepasseadmin'] = true`. Replace `session_start()` with `require_once('../includes/session_init.php')`.

---

### [ADMIN-R1-002] CRITICAL moderation/index.php:15-16 -- Admin and moderation share identical password and session flag (privilege conflation)

Both `admin/index.php` and `moderation/index.php` verify against `ADMIN_PASSWORD_HASH` and set the same session variable `$_SESSION['motdepasseadmin'] = true`. This means:

1. **Anyone who authenticates to moderation automatically has admin access.** The moderation login at `moderation/index.php:15` uses the same hash as the admin login at `admin/index.php:16`.
2. **The `redirectionmotdepasse.php` guard checks the same flag** (`$_SESSION['motdepasseadmin']`), so a moderator session passes admin checks.
3. **No individual accountability** -- all admin/mod actions are performed under a single shared credential with no username audit trail.

```php
// moderation/index.php:15
if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
    $_SESSION['motdepasseadmin'] = true;  // Same flag as admin!

// admin/redirectionmotdepasse.php:4
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
```

**Impact:** Privilege escalation from moderator to admin. Moderators can perform season resets, account deletions by IP, maintenance toggles.
**Remediation:** Use separate session flags (`$_SESSION['admin_auth']` vs `$_SESSION['mod_auth']`) and separate password hashes (`ADMIN_PASSWORD_HASH` vs `MOD_PASSWORD_HASH`). Ideally, implement user-based admin accounts with role columns.

---

### [ADMIN-R1-003] CRITICAL admin/tableau.php:76,285-296,303,635 -- Unescaped database values injected into HTML and JavaScript (stored XSS / JS injection)

The `tableau.php` file outputs several database-sourced values without any escaping:

**Line 76 -- `$data['nom']` rendered directly into HTML:**
```php
<h1>Tableau <?php echo $data['nom'] ?></h1>
```

**Lines 285-296 -- `$data1['unite']` and `$data1['sousunite']` injected into JavaScript regex patterns and string assignments:**
```php
echo "if(/".$data1['unite']."/i.test(unite) == true) {
    unite = \"".$data1['unite']."\";
    compagnie = \"".$data1['compagnie']."\";
}";
```
If any of these database fields contain characters like `"`, `/`, or `</script>`, an attacker who can modify the `unites` table can inject arbitrary JavaScript.

**Line 635 -- `$data['motCle']` injected into JavaScript regex:**
```php
echo "tableauSignalements.push(/ ".$data['motCle']." /ig);";
```

**Line 760 -- `$colonnes[$i]` (from `$data['composition']`) into JavaScript string:**
```php
echo 'ajouterTitre("'.$colonnes[$i].'");';
```

**Impact:** Stored XSS allowing arbitrary script execution in admin sessions. If the tables `tableaux`, `unites`, `signalement`, or `lieux` can be modified by any user or through another vulnerability, this becomes a full admin compromise vector.
**Remediation:** Apply `json_encode()` for JavaScript string injection and `htmlspecialchars()` for HTML contexts. Also note that this file references tables (`tableaux`, `unites`, `signalement`, `lieux`) that do not exist in the TVLW game schema -- see ADMIN-R1-017.

---

## HIGH Findings

### [ADMIN-R1-004] HIGH admin/index.php:4 -- Bare session_start() bypasses security hardening

`admin/index.php` uses `session_start()` directly (line 4) instead of `require_once('../includes/session_init.php')` which sets `cookie_httponly`, `cookie_secure`, `use_strict_mode`, and `cookie_samesite`. This means admin sessions lack:
- HttpOnly cookie flag (session cookie accessible to JavaScript / XSS)
- SameSite attribute (vulnerable to CSRF via cross-site requests)
- Strict session mode (server accepts uninitialized session IDs)

```php
// admin/index.php:4
session_start();  // Should be: require_once('../includes/session_init.php');
```

**Impact:** Admin session cookies are more vulnerable to theft and fixation.
**Remediation:** Replace `session_start()` with `require_once(__DIR__ . '/../includes/session_init.php')`.

---

### [ADMIN-R1-005] HIGH moderation/index.php:16 -- No session_regenerate_id on moderation login (session fixation)

Identical to ADMIN-R1-001 but for the moderation panel. After verifying the password at line 15, the session ID is not regenerated.

```php
// moderation/index.php:15-16
if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
    $_SESSION['motdepasseadmin'] = true;  // No session_regenerate_id(true)
```

**Impact:** Session fixation allows moderation (and, per ADMIN-R1-002, admin) takeover.
**Remediation:** Add `session_regenerate_id(true)` after setting the session flag.

---

### [ADMIN-R1-006] HIGH admin/index.php:30-34 -- Mass account deletion by IP without confirmation or audit detail

The admin dashboard deletes ALL accounts matching a given IP address in a single POST. There is no confirmation dialog, no preview of affected accounts, and the audit log only records IP-level context, not the specific accounts deleted.

```php
if (isset($_POST['supprimercompte'])) {
    $ip = $_POST['supprimercompte'];
    $rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
    foreach ($rows as $login) {
        supprimerJoueur($login['login']);
    }
}
```

An accidental click or CSRF bypass (if token is compromised) deletes all accounts on that IP with no undo path.

**Impact:** Irrecoverable data destruction of potentially many accounts.
**Remediation:** Add a two-step confirmation (display accounts to be deleted, then confirm). Log each individual account deleted with the admin's identity. Consider soft-delete with a grace period.

---

### [ADMIN-R1-007] HIGH admin/index.php:45-48 -- Season reset (remiseAZero) has no confirmation or audit logging

The `miseazero` action calls `remiseAZero()` which resets ALL player data, constructions, molecules, and alliance buildings globally. This is the most destructive single operation in the entire admin panel. It is protected by CSRF but has:
- No confirmation step
- No audit log entry for who triggered it or when
- No backup/snapshot before execution

```php
if (isset($_POST['miseazero'])) {
    remiseAZero();  // Wipes ALL game data for ALL players
}
```

**Impact:** Accidental or malicious season reset destroys all player progress with no recovery.
**Remediation:** Add a confirmation step (e.g., two-phase POST), log the action with `logInfo('ADMIN', 'Season reset triggered')`, and consider creating a database backup before execution.

---

### [ADMIN-R1-008] HIGH admin/index.php:11-21 -- Admin login form has no CSRF token

The admin login form (lines 180-184) submits without a CSRF token. While the login action itself only sets a session flag, a login-CSRF attack is possible: an attacker tricks the victim into logging into the attacker's admin session, then observes their actions.

```php
<form action="index.php" method="post">
    <label for="motdepasseadmin">Mot de passe : </label>
    <input type="password" name="motdepasseadmin" id="motdepasseadmin" />
    <input type="submit" name="valider" value="Valider" />
</form>
<!-- No csrfField() -->
```

**Impact:** Login CSRF -- the attacker can force a victim's browser to authenticate with the attacker's credentials.
**Remediation:** Add `<?php echo csrfField(); ?>` to the login form. Note: the CSRF token requires a session, which is started at line 4, so this is feasible.

---

### [ADMIN-R1-009] HIGH moderation/index.php:24 -- Moderation login form has no CSRF token

Same issue as ADMIN-R1-008 for the moderation login form at lines 24-28.

```php
<form action="index.php" method="post">
    <label for="motdepasseadmin">Mot de passe : </label>
    <input type="password" name="motdepasseadmin" id="motdepasseadmin" />
    <input type="submit" name="valider" value="Valider" />
</form>
<!-- No csrfField() -->
```

**Impact:** Login CSRF on the moderation panel.
**Remediation:** Add CSRF field to the login form.

---

### [ADMIN-R1-010] HIGH moderation/index.php:74-149 -- Resource injection endpoint active despite commented-out UI

The moderation panel's resource-granting feature has its navigation link commented out at line 173 (`<!-- <a href="index.php?sub=1">Donner des ressources</a> -->`), but the server-side POST handler at lines 74-149 is fully active. An attacker (or moderator who discovers the hidden endpoint) can craft a POST request to grant unlimited resources to any player without any visible UI or restriction.

The handler:
- Accepts arbitrary resource amounts (no upper bound validation)
- Adds resources directly without checking caps or storage limits
- Records in moderation log but with `htmlentities` on justification (double-encoding for storage)

**Impact:** Unlimited resource injection into any player account.
**Remediation:** Either fully remove the backend handler code or reinstate the UI with proper authorization. Add value bounds validation. Consider requiring a second admin approval for large grants.

---

### [ADMIN-R1-011] HIGH moderationForum.php:123 -- BBcode renders sanction motif without full XSS protection

The `BBcode()` function at `includes/bbcode.php:316` does call `htmlentities()` first, which provides baseline XSS protection. However, it then applies regex-based tag replacements that can be chained to produce malicious output. Specifically:

- `[url=...]` tag at line 331: `<a href="$1">$5</a>` -- The URL regex allows `javascript:` URIs if they match the pattern. The regex `https?://` prefix requirement blocks this specific vector, but the `[img=...]` tag at line 332 injects `src` attributes that could be manipulated.
- The `[img=...]` replacement creates `<img src="$1">` tags. While the regex restricts to `https?://` URLs, an attacker-controlled image URL could be used for tracking or phishing.

```php
// moderationForum.php:123
"<td>" . BBcode($sanction['motif']) . "</td>"
```

The motif field is stored by moderators, so this is a self-XSS risk within the moderator panel (one moderator attacking another).

**Impact:** Limited XSS risk within the moderator context. If BBcode parsing is ever loosened, this becomes exploitable.
**Remediation:** Use a proper allowlist-based HTML sanitizer instead of regex-based BBcode parsing. At minimum, add `rel="noopener noreferrer"` and `target="_blank"` to generated links, and add CSP headers.

---

## MEDIUM Findings

### [ADMIN-R1-012] MEDIUM admin/redirectionmotdepasse.php:2 -- Auth guard uses session_start() instead of session_init.php

The `redirectionmotdepasse.php` file (the admin auth guard used by most admin sub-pages) calls `session_start()` directly via its include chain. It should use `session_init.php` for consistent security hardening.

```php
// admin/redirectionmotdepasse.php
<?php
session_start();
include_once(__DIR__ . '/../includes/constantesBase.php');
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}
```

**Impact:** All admin sub-pages (ip.php, listenews.php, redigernews.php, listesujets.php, supprimerreponse.php, supprimercompte.php) inherit weak session configuration.
**Remediation:** Replace `session_start()` with `require_once(__DIR__ . '/../includes/session_init.php')`.

---

### [ADMIN-R1-013] MEDIUM moderation/mdp.php:2 -- Moderation auth guard uses session_init.php but moderation/index.php does not

The `moderation/mdp.php` guard uses `session_init.php` (hardened), but `moderation/index.php` uses bare `session_start()` at line 2. Since `index.php` starts the session before `mdp.php` is ever reached by sub-pages, the hardened settings in `session_init.php` are effectively applied only if the session was not already started. The `session_init.php` file checks `session_status() === PHP_SESSION_NONE`, so if `index.php`'s `session_start()` runs first, the hardening is skipped.

```php
// moderation/index.php:2
session_start();  // Starts session without hardening

// moderation/mdp.php:2
require_once(__DIR__ . '/../includes/session_init.php');  // Too late if session already started
```

**Impact:** Inconsistent session security across moderation pages.
**Remediation:** Replace `session_start()` in `moderation/index.php` with `require_once(__DIR__ . '/../includes/session_init.php')`.

---

### [ADMIN-R1-014] MEDIUM admin/supprimercompte.php:11 -- Account deletion uses different POST field for lookup vs action

The delete action checks `isset($_POST['supprimercompte'])` (line 9) but queries the database and calls `supprimerJoueur()` using `$_POST['supprimer']` (lines 11, 14). These are two different POST fields. While both are present in the form, this inconsistency means:
- If `supprimer` field is missing or empty but `supprimercompte` is present, the code queries with an empty/null login
- The `dbCount` query will return 0 and no deletion occurs (safe), but `$_POST['supprimer']` is used without `isset()` check

```php
if (isset($_POST['supprimercompte'])) {
    $joueurExiste = dbCount($base, '...', 's', $_POST['supprimer']);  // Different field!
    if($joueurExiste > 0 ) {
        supprimerJoueur($_POST['supprimer']);  // Uses 'supprimer' not 'supprimercompte'
    }
}
```

**Impact:** Minor -- could cause PHP notices. The split between control flow field and data field is fragile.
**Remediation:** Consolidate to a single field name, or add explicit `isset()` check for `$_POST['supprimer']`.

---

### [ADMIN-R1-015] MEDIUM admin/index.php:156 -- Moderation table energy value output without escaping

The moderation log display outputs `$donnees['energie']` without `htmlspecialchars()`. While this is expected to be numeric, if the `moderation` table's `energie` column ever contains non-numeric data (due to a bug or direct DB manipulation), this becomes an XSS vector.

```php
<td><?php echo $donnees['energie']; ?></td>
```

**Impact:** Potential XSS if database integrity is compromised.
**Remediation:** Apply `htmlspecialchars()` or `(int)` cast: `echo (int)$donnees['energie'];`

---

### [ADMIN-R1-016] MEDIUM admin/index.php:146-158 -- Resource columns output without escaping

In the same moderation log display loop, resource columns from `$nomsRes` and `$nomsAccents` arrays are output. While `$nomsAccents` values come from config (trusted), the pattern of echoing `$donnees[$ressource]` without escaping is unsafe if the database values are ever non-numeric.

```php
<?php foreach ($nomsRes as $num => $ressource) {
    echo '<td>' . $donnees[$ressource] . '</td>';  // No escaping
} ?>
```

**Impact:** Potential XSS from database values.
**Remediation:** Cast to int: `echo '<td>' . (int)$donnees[$ressource] . '</td>';`

---

### [ADMIN-R1-017] MEDIUM admin/tableau.php:1-792 -- Entire file is foreign application code (not part of TVLW game)

`tableau.php` is a 792-line file that references database tables `tableaux`, `unites`, `signalement`, and `lieux` which do not exist in the TVLW database schema. It appears to be a CRPJ (Compte-Rendu de Procedure Judiciaire) document parsing tool from a completely different project. The file:
- Is protected by the admin password, so it is not publicly accessible
- Will throw database errors if accessed since the tables do not exist
- Contains multiple XSS vulnerabilities (see ADMIN-R1-003)
- Contains the `$data['composition']` field being `explode()`d into a switch statement that generates JavaScript -- a code injection surface

**Impact:** Attack surface increase from dead code. If the tables were ever created (e.g., by importing another database), the XSS issues become exploitable.
**Remediation:** Remove `tableau.php` from the repository entirely. It is not part of the TVLW game and should not be deployed.

---

### [ADMIN-R1-018] MEDIUM admin/listenews.php:45-48 -- News content stored and displayed with stripslashes (data corruption)

When news is edited, `redigernews.php` applies `stripslashes()` to the loaded content (line 29), and `listenews.php` applies `stripslashes()` when displaying titles (line 89). This is a legacy practice from the `magic_quotes_gpc` era. On modern PHP (8.x), `stripslashes()` will incorrectly remove legitimate backslashes from content.

```php
// redigernews.php:29
$titre = stripslashes($donnees['titre']);
$contenu = stripslashes($donnees['contenu']);

// listenews.php:89
echo htmlspecialchars(stripslashes($donnees['titre']), ENT_QUOTES, 'UTF-8');
```

**Impact:** Data corruption -- legitimate backslashes in news content are silently removed.
**Remediation:** Remove all `stripslashes()` calls. PHP 8.x does not have `magic_quotes_gpc`.

---

### [ADMIN-R1-019] MEDIUM moderation/index.php:126 -- Double encoding of justification field

The justification text is encoded with `htmlentities()` before being stored in the database (line 126). When later displayed (on admin/index.php:160), it is encoded again with `htmlspecialchars()`. This results in double-encoding: `&` becomes `&amp;amp;`, `<` becomes `&amp;lt;`, etc.

```php
// moderation/index.php:126 (storage)
$justification = htmlentities(trim($_POST['justification']), ENT_QUOTES, 'UTF-8');
$insertParams[] = $justification;  // Stored encoded

// admin/index.php:160 (display)
echo htmlspecialchars($donnees['justification'], ENT_QUOTES, 'UTF-8');  // Double-encoded
```

**Impact:** Display corruption -- special characters in justification text appear as HTML entities.
**Remediation:** Store raw text in the database (remove `htmlentities()` from line 126). Only apply `htmlspecialchars()` at display time.

---

### [ADMIN-R1-020] MEDIUM admin/listesujets.php:8 -- ISO-8859-1 charset in meta tag

The page declares `charset=iso-8859-1` in the meta tag while all other pages use UTF-8. This charset mismatch can cause encoding issues and in some edge cases can enable XSS through charset confusion.

```html
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
```

**Impact:** Encoding confusion, potential edge-case XSS.
**Remediation:** Change to `<meta http-equiv="content-type" content="text/html; charset=utf-8" />`.

---

## LOW Findings

### [ADMIN-R1-021] LOW admin/listesujets.php:73-74, admin/supprimerreponse.php:53 -- Legacy stripslashes on forum data

Same issue as ADMIN-R1-018. `stripslashes()` is applied to forum subject titles and reply content from the database. On PHP 8.x this is unnecessary and can corrupt data.

```php
// listesujets.php:73
echo htmlspecialchars(stripslashes($donnees['titre']), ENT_QUOTES, 'UTF-8');
echo htmlspecialchars(stripslashes($donnees['auteur']), ENT_QUOTES, 'UTF-8');

// supprimerreponse.php:53
echo htmlspecialchars(stripslashes($donnees['contenu']), ENT_QUOTES, 'UTF-8');
echo htmlspecialchars(stripslashes($donnees['auteur']), ENT_QUOTES, 'UTF-8');
```

**Impact:** Minor data corruption of backslash characters.
**Remediation:** Remove `stripslashes()` calls.

---

### [ADMIN-R1-022] LOW admin/index.php:18, moderation/index.php:17 -- Audit logs lack IP address and action detail

Successful admin/mod logins are logged but without the client IP address. Admin actions (account deletion, maintenance toggle, season reset) have no audit logging at all beyond what `supprimerJoueur()` logs internally.

```php
// admin/index.php:18
logInfo('ADMIN', 'Admin login successful');  // No IP, no details

// No logging for:
// - maintenance toggle (line 38)
// - maintenance removal (line 42)
// - season reset (line 46)
// - mass IP deletion (line 29-34)
```

**Impact:** Insufficient audit trail for forensic investigation after incidents.
**Remediation:** Add IP address to all admin log entries. Add explicit logging for every destructive action: `logInfo('ADMIN', 'Season reset triggered', ['ip' => $_SERVER['REMOTE_ADDR']])`.

---

### [ADMIN-R1-023] LOW moderationForum.php:71-72 -- External CDN resources without version pinning for jQuery UI

The moderationForum.php page loads jQuery UI from `cdnjs.cloudflare.com` with SRI hashes (which is good), but should also pin the CSS version with SRI. The CSS link on line 71 does have SRI, so this is adequately handled. However, jQuery core is loaded from elsewhere (presumably by the game framework) and should be verified for consistency.

```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.3/themes/smoothness/jquery-ui.min.css"
    integrity="sha512-EPEm2NPSRmFKzSAFm4xFSVpZMC3cKgBSxMxIfiUVGJGwSCuikYmGmFiuxmGxTQsLMOuQOBVEfCm8bnYJnQMnQ=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
```

**Impact:** Low -- SRI is present. Supply chain risk is mitigated but should be periodically verified.
**Remediation:** No immediate action needed. Consider self-hosting the jQuery UI CSS/JS.

---

### [ADMIN-R1-024] LOW admin/ip.php:14-15, moderation/ip.php:17-18 -- IP address displayed without format validation

Both admin and moderation IP detail pages display the `$_GET['ip']` parameter. While `htmlspecialchars()` is correctly applied for XSS prevention, and the database query uses prepared statements, there is no validation that the `ip` parameter is actually a valid IP address format. This allows querying with arbitrary strings.

```php
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'<p>';
$retour = dbQuery($base, 'SELECT * FROM membre WHERE ip = ?', 's', $ip);
```

**Impact:** Information disclosure -- allows probing the member table with arbitrary strings, though results would typically be empty.
**Remediation:** Validate IP format: `$ip = filter_var($_GET['ip'] ?? '', FILTER_VALIDATE_IP) ?: '';`

---

## Architecture Observations (non-finding, for reference)

### Admin Authentication Model

The admin/moderation authentication model is a single shared password stored as a bcrypt hash in `includes/constantesBase.php`. This is a simple but limited approach:
- No individual admin accounts
- No role separation between admin and moderator
- No session timeout specific to admin
- No multi-factor authentication
- No lockout after failed attempts beyond rate limiting (5 per 5 minutes)

### CSRF Protection Coverage

| File | CSRF Protected | Notes |
|------|---------------|-------|
| admin/index.php (login) | NO | Login form lacks CSRF token |
| admin/index.php (actions) | YES | csrfCheck() for destructive actions |
| admin/listenews.php | YES | csrfCheck() on all POST |
| admin/redigernews.php | N/A | Only displays form with token |
| admin/listesujets.php | YES | csrfCheck() on POST |
| admin/supprimerreponse.php | YES | csrfCheck() on POST |
| admin/supprimercompte.php | YES | csrfCheck() on POST |
| moderation/index.php (login) | NO | Login form lacks CSRF token |
| moderation/index.php (actions) | YES | csrfCheck() on POST |
| moderationForum.php | YES | csrfCheck() on POST |

### SQL Injection Protection

All database queries across admin/moderation files use prepared statements via `dbQuery()`, `dbFetchOne()`, `dbFetchAll()`, `dbExecute()`, and `dbCount()`. Integer parameters are cast with `(int)`. No SQL injection vulnerabilities were found.

### XSS Protection Summary

| File | Escaping Applied | Issues |
|------|-----------------|--------|
| admin/index.php | htmlspecialchars on IP, justification, destinataire | Missing on energie, resource values |
| admin/ip.php | htmlspecialchars on IP, login | Clean |
| admin/listenews.php | htmlspecialchars + stripslashes | stripslashes unnecessary |
| admin/redigernews.php | htmlspecialchars on form values | Clean |
| admin/listesujets.php | htmlspecialchars + stripslashes | stripslashes unnecessary; wrong charset |
| admin/supprimerreponse.php | htmlspecialchars + stripslashes | stripslashes unnecessary |
| admin/supprimercompte.php | htmlspecialchars on login | Clean |
| admin/tableau.php | NONE on multiple DB values | CRITICAL -- see ADMIN-R1-003 |
| moderation/index.php | htmlspecialchars on user input | Clean for user input |
| moderation/ip.php | htmlspecialchars on IP, login | Clean |
| moderationForum.php | htmlspecialchars + BBcode | BBcode concern -- see ADMIN-R1-011 |

---

## Recommended Priority Actions

1. **Immediate (CRITICAL):**
   - ADMIN-R1-001/005: Add `session_regenerate_id(true)` to both admin and moderation login flows
   - ADMIN-R1-002: Separate admin and moderation credentials and session flags
   - ADMIN-R1-003: Remove `tableau.php` or fix all unescaped outputs

2. **Short-term (HIGH):**
   - ADMIN-R1-004/012/013: Replace all `session_start()` with `session_init.php`
   - ADMIN-R1-006/007: Add confirmation steps for destructive operations
   - ADMIN-R1-008/009: Add CSRF tokens to login forms
   - ADMIN-R1-010: Remove or properly gate the resource injection handler

3. **Medium-term (MEDIUM):**
   - ADMIN-R1-015/016: Add escaping to all numeric database outputs
   - ADMIN-R1-018/019: Remove stripslashes, fix double-encoding
   - ADMIN-R1-020: Fix charset declaration
   - ADMIN-R1-022: Comprehensive audit logging

---

*End of Admin & Moderation Panel Security Audit -- Round 1*
