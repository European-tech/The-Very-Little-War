# Admin Panel Deep-Dive -- Round 2

**Auditor:** Claude Opus 4.6 (Security)
**Date:** 2026-03-03
**Scope:** All files under `admin/`, `moderation/`, `moderationForum.php`, plus supporting auth infrastructure
**Round 1 reference:** `docs/audit3/round1/ADMIN.md` (24 findings)

---

## Executive Summary

The admin and moderation subsystem comprises 12 PHP files across three directories. Authentication relies on a single shared bcrypt password stored in `constantesBase.php`, verified against `ADMIN_PASSWORD_HASH`, and gated by a boolean session flag `$_SESSION['motdepasseadmin']`. There is no role-based separation between admin and moderator, no per-user admin accounts, no audit trail for destructive actions, and no admin session isolation from the game session.

This round identifies **27 findings** across authentication, authorization, session management, input handling, stored XSS, audit logging, and architectural weaknesses.

---

## Files Audited

| File | Role | Lines |
|------|------|-------|
| `admin/index.php` | Admin dashboard: multi-account detection, maintenance toggle, season reset, account deletion by IP | 187 |
| `admin/redirectionmotdepasse.php` | Auth guard (redirect if not admin) | 7 |
| `admin/supprimercompte.php` | Delete player by username | 52 |
| `admin/supprimerreponse.php` | Delete forum replies | 63 |
| `admin/listenews.php` | News CRUD | 98 |
| `admin/redigernews.php` | News editor form | 55 |
| `admin/listesujets.php` | Lock/unlock/delete forum topics | 83 |
| `admin/ip.php` | View accounts by IP | 24 |
| `admin/tableau.php` | Embedded HTML display page | large |
| `moderation/index.php` | Mod dashboard: bomb points, forum topics, resource grants, multi-account view | 270 |
| `moderation/mdp.php` | Mod auth guard | 7 |
| `moderation/ip.php` | Mod multi-account IP view | 29 |
| `moderationForum.php` | In-game moderator: sanctions (bans), requires `moderateur` DB flag | 139 |
| `messageCommun.php` | Admin mass-message to all players | 50 |

---

## Findings

### ADMIN-R2-001: Shared Password, No Per-User Admin Accounts
**Severity:** CRITICAL
**Files:** `admin/index.php:16`, `moderation/index.php:15`, `includes/constantesBase.php:53-55`

Admin and moderation both authenticate against a single `ADMIN_PASSWORD_HASH` bcrypt constant. There are no individual admin accounts, no usernames, and no way to:
- Attribute actions to specific administrators
- Revoke access for a single person without changing the password for everyone
- Enforce different password policies per admin
- Implement MFA per admin

**PoC:** If an admin shares the password with a moderator (or the password leaks), both have identical access. There is no way to determine who performed any admin action.

**Evidence:**
```php
// constantesBase.php line 53-55
if (!defined('ADMIN_PASSWORD_HASH')) {
    define('ADMIN_PASSWORD_HASH', '$2y$10$PibWl.r/3LA3HMwuSchD0et2Mjkac0D6kzuwxvOAbSqUTBf7zhGES');
}
```

```php
// admin/index.php line 16
if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
    $_SESSION['motdepasseadmin'] = true;
}

// moderation/index.php line 15 -- EXACT SAME check
if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
    $_SESSION['motdepasseadmin'] = true;
}
```

**Fix:**
1. Create an `admins` table with columns: `id`, `username`, `password_hash`, `role` (admin/moderator), `created_at`, `last_login`.
2. Replace the shared password with per-user login.
3. Gate admin pages on role = 'admin', mod pages on role IN ('admin', 'moderator').

---

### ADMIN-R2-002: No Admin/Moderator Role Separation
**Severity:** CRITICAL
**Files:** `admin/redirectionmotdepasse.php:4`, `moderation/mdp.php:4`

Both auth guards check the identical session flag:

```php
// admin/redirectionmotdepasse.php line 4
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}

// moderation/mdp.php line 4 -- SAME CHECK
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}
```

**Impact:** Anyone who authenticates to the moderation panel automatically has full admin access (account deletion, season reset, maintenance toggle). A moderator should not be able to delete all accounts by IP or trigger a season reset.

**Fix:** Use distinct session flags (`$_SESSION['admin_role']` set to 'admin' or 'moderator'). Admin guard checks for 'admin' role, mod guard checks for 'admin' OR 'moderator'.

---

### ADMIN-R2-003: No Session Regeneration on Admin Login
**Severity:** HIGH
**Files:** `admin/index.php:16-18`, `moderation/index.php:15-17`

When the admin password is verified and `$_SESSION['motdepasseadmin'] = true` is set, neither file calls `session_regenerate_id(true)`. This leaves the session vulnerable to session fixation attacks: an attacker who can plant a session ID cookie (e.g., via a subdomain or XSS) can wait for the admin to log in and then use the same session ID to gain admin access.

**PoC:**
1. Attacker sets `PHPSESSID=known_value` cookie via XSS or subdomain cookie scope.
2. Admin visits `admin/index.php`, enters password; `$_SESSION['motdepasseadmin'] = true` is set on session `known_value`.
3. Attacker uses `PHPSESSID=known_value` and accesses admin panel directly.

**Fix:** Add `session_regenerate_id(true)` immediately after setting `$_SESSION['motdepasseadmin'] = true`.

---

### ADMIN-R2-004: Admin Uses Bare session_start() Instead of Hardened session_init.php
**Severity:** HIGH
**Files:** `admin/index.php:4`, `moderation/index.php:2`

Both admin login pages use bare `session_start()` instead of the hardened `includes/session_init.php` that sets `cookie_httponly`, `cookie_secure`, `use_strict_mode`, `cookie_samesite`, and `gc_maxlifetime`. This means admin sessions may lack HttpOnly, Secure, SameSite, and strict mode protections.

**Evidence:**
```php
// admin/index.php line 4
session_start();  // bare, no hardening

// Compare with moderation/mdp.php line 2 which DOES use session_init.php:
require_once(__DIR__ . '/../includes/session_init.php');
```

Note: `moderation/mdp.php` (the auth guard for sub-pages) uses `session_init.php`, but `moderation/index.php` (the login page itself) uses bare `session_start()`. The inconsistency means the login page where the password is submitted has weaker session protections.

**Fix:** Replace `session_start()` with `require_once(__DIR__ . '/../includes/session_init.php')` in both `admin/index.php` and `moderation/index.php`.

---

### ADMIN-R2-005: No Admin Session Timeout or Logout
**Severity:** HIGH
**Files:** `admin/index.php`, `moderation/index.php`

Once `$_SESSION['motdepasseadmin'] = true` is set, it persists until the PHP session expires (up to 1 hour via `gc_maxlifetime` in `session_init.php`, but potentially longer with bare `session_start()`). There is:
- No admin-specific timeout
- No logout button or mechanism
- No session activity tracking for admin actions

The game's `basicprivatephp.php` has a 1-hour idle timeout, but the admin panel does not use `basicprivatephp.php`.

**Fix:**
1. Record `$_SESSION['admin_login_time']` when setting the admin flag.
2. Check elapsed time on every admin page load; expire after 15-30 minutes.
3. Add a logout link that unsets the admin session flag.

---

### ADMIN-R2-006: Admin Session Not Isolated from Game Session
**Severity:** HIGH
**Files:** `admin/index.php`, `moderation/index.php`, `includes/basicprivatephp.php`

The admin and game sessions share the same `PHPSESSID` cookie and session storage. When a player authenticates to the admin panel, `$_SESSION['motdepasseadmin'] = true` is set in the same session as `$_SESSION['login']`, `$_SESSION['session_token']`, etc.

**Impact:**
- If a game-level session hijack succeeds, the attacker also gains any admin flag already set in that session.
- The game session's `last_activity` tracking in `basicprivatephp.php` does not apply to admin pages, meaning admin access could persist even if the game session would have expired.
- The admin flag could be inadvertently inherited if session data is shared across subdomains.

**Fix:** Use a separate session name/cookie for admin (`session_name('TVLW_ADMIN')`) or use a completely separate application path with its own session configuration.

---

### ADMIN-R2-007: No CSRF Token on Admin Login Form
**Severity:** MEDIUM
**Files:** `admin/index.php:180-184`, `moderation/index.php:24-28`

Neither admin nor moderator login form includes a CSRF token:

```php
// admin/index.php line 180-184
<form action="index.php" method="post">
    <label for="motdepasseadmin">Mot de passe : </label>
    <input type="password" name="motdepasseadmin" id="motdepasseadmin" />
    <input type="submit" name="valider" value="Valider" />
</form>
```

While CSRF on login forms is sometimes deprioritized, in this case it is dangerous because:
1. The admin session flag persists alongside the game session.
2. An attacker could CSRF-login the victim into the admin panel using the known admin password (if they have it), causing the victim's session to gain `motdepasseadmin = true`.
3. A "login CSRF" attack could also be used to force a victim to authenticate as admin before exploiting other admin CSRF vectors.

**Fix:** Add `<?php echo csrfField(); ?>` to both login forms. Add `csrfCheck()` before password verification.

---

### ADMIN-R2-008: Stored XSS via News Content -- Admin-to-Public
**Severity:** HIGH
**Files:** `admin/listenews.php:46-47`, `admin/redigernews.php:41-52`, `index.php:51-58`

News content submitted through `admin/redigernews.php` is stored raw (no sanitization):

```php
// listenews.php line 46-47 (insert handler)
$titre = $_POST['titre'];
$contenu = $_POST['contenu'];
// ... inserted directly into DB with prepared statement (SQL-safe but not XSS-safe)
```

When displayed on the public `index.php`:

```php
// index.php line 52-53
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
$contenuNews = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));
```

The `strip_tags` allowlist includes `<a>`, `<img>`, `<div>`, and `<span>` tags. This allows:
- `<a href="javascript:alert(1)">click</a>` -- javascript: protocol in href
- `<img src=x onerror=alert(1)>` -- event handler on img tag
- `<span onmouseover=alert(1)>text</span>` -- event handler on span

The `strip_tags()` function does NOT strip attributes, so event handlers and javascript: URLs pass through.

Additionally, `$donnees['titre']` at line 58 is passed directly to `itemAccordion()` without any sanitization.

**PoC:**
1. Admin creates news with content: `<img src=x onerror="fetch('/admin/index.php',{method:'POST',body:'maintenance=1'})">`
2. Every visitor to the public homepage executes the JavaScript.

**Fix:**
1. Sanitize news content on output with `htmlspecialchars()` or use a proper HTML sanitizer library (HTML Purifier).
2. If rich HTML is required, use HTML Purifier with a strict allowlist that strips event handlers and javascript: URLs.
3. Sanitize `$donnees['titre']` with `htmlspecialchars()` before passing to `itemAccordion()`.

---

### ADMIN-R2-009: No Audit Trail for Any Admin/Mod Action
**Severity:** HIGH
**Files:** All admin and moderation files

The only logging that occurs is:
- `logInfo('ADMIN', 'Admin login successful')` -- login event only
- `logInfo('ACCOUNT', 'Account deleted', ...)` -- inside `supprimerJoueur()` function, but does not record WHO deleted the account

No logging exists for:
- Maintenance mode toggle
- Season reset (`remiseAZero()`)
- Account deletion by IP (bulk deletion)
- Account deletion by username
- News creation/modification/deletion
- Forum topic lock/unlock/deletion
- Forum reply deletion
- Resource grants to players (moderator)
- Bomb point additions (moderator)
- Forum ban creation/deletion (moderator)
- Forum topic moves (moderator)
- Mass messages sent

**Fix:** Add `logInfo()` calls with the action type, target entity, and admin identity for every state-changing operation. Since there are no per-user admin accounts, at minimum log the IP address and session ID. Example:

```php
logInfo('ADMIN', 'Maintenance enabled', [
    'ip' => $_SERVER['REMOTE_ADDR'],
    'session_id' => session_id()
]);
```

---

### ADMIN-R2-010: Admin Account Deletion by IP -- No Confirmation, Bulk Destructive
**Severity:** HIGH
**File:** `admin/index.php:29-34`

The admin can delete ALL accounts sharing an IP address with a single button click:

```php
if (isset($_POST['supprimercompte'])) {
    $ip = $_POST['supprimercompte'];
    $rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
    foreach ($rows as $login) {
        supprimerJoueur($login['login']);
    }
}
```

**Issues:**
1. No confirmation dialog or two-step process.
2. No limit on how many accounts can be deleted at once.
3. The IP value comes from POST but is not validated as a valid IP format.
4. No logging of which specific accounts were deleted or how many.
5. CSRF-protected, but a CSRF vulnerability elsewhere could trigger mass deletion.

**Fix:**
1. Validate IP format with `filter_var($ip, FILTER_VALIDATE_IP)`.
2. Add a confirmation step (e.g., list accounts first, then confirm).
3. Log every deletion with account names and the IP that was targeted.

---

### ADMIN-R2-011: Season Reset (remiseAZero) Exposed Without Confirmation
**Severity:** HIGH
**File:** `admin/index.php:45-48`

A single button click triggers `remiseAZero()` which wipes all player progress:

```php
if (isset($_POST['miseazero'])) {
    remiseAZero();
}
```

The `remiseAZero()` function in `player.php:793` resets ALL player stats, buildings, alliances, molecules, and constructions. This is the most destructive single action in the entire application.

**Issues:**
1. No confirmation step.
2. No check that maintenance mode is active first (the in-game reset in `basicprivatephp.php` requires maintenance mode).
3. No prestige point awarding before reset (the in-game flow calls `awardPrestigePoints()` first).
4. No archival of current season data (the in-game flow archives rankings first).

**Fix:**
1. Require a confirmation token or two-step process.
2. Either remove this button entirely (season reset should only happen via the automatic flow) or add all the pre-reset steps (archive, award prestige, etc.).
3. Add prominent warning text and require re-entering the admin password.

---

### ADMIN-R2-012: Moderator Resource Grant -- No Upper Bound, No Negative Check
**Severity:** HIGH
**File:** `moderation/index.php:74-149`

Moderators can grant arbitrary amounts of resources to any player:

```php
// line 91 -- regex only checks for digits, allows extremely large values
if (preg_match("#^[0-9]*$#", $_POST['energieEnvoyee']) and $bool == 1) {
    // ... grants resources
}
```

**Issues:**
1. No upper bound on resource amounts. A moderator could grant billions of resources.
2. The regex `#^[0-9]*$#` matches empty strings (the `*` quantifier), handled separately but indicates weak validation.
3. No approval workflow for large grants.
4. The moderation table records grants, but there is no review mechanism or alert for anomalous amounts.
5. Resources are added directly to the player's account with no rollback capability.

**Fix:**
1. Define maximum grant amounts in `config.php` (e.g., 10000 per resource per grant).
2. Use `#^[1-9][0-9]*$#` or `(int)` cast with range validation.
3. Log grants with moderator identity and IP.

---

### ADMIN-R2-013: Moderator $erreur Variable Echoed with Raw HTML
**Severity:** MEDIUM
**File:** `moderation/index.php:139, 178-179`

The `$erreur` variable is echoed without escaping:

```php
// line 178-179
if (isset($erreur)) {
    echo $erreur;
}
```

Several places construct `$erreur` with HTML content:

```php
// line 47
$erreur = "Vous avez rajout\u00e9 un point de bombe \u00e0 " . htmlspecialchars($_POST['joueurBombe'], ENT_QUOTES, 'UTF-8') . ".";

// line 137
$chaine = $chaine . '' . number_format(...) . '<img src="../images/' . htmlspecialchars($ressource, ...) . '.png" alt="..."/>' . $plus;

// line 139
$erreur = "Vous avez donn\u00e9 " . number_format(...) . "<img src=\"../images/energie.png\"..." . $chaine . " \u00e0 " . htmlspecialchars($_POST['destinataire'], ...) . ".";
```

While user inputs within `$erreur` are escaped with `htmlspecialchars()`, the deliberate inclusion of raw HTML (`<img>` tags) means `$erreur` is expected to be raw HTML. This is fragile -- any future modification that adds unescaped user input to `$erreur` will create XSS. The pattern of mixing HTML and escaped text in the same variable is an anti-pattern.

**Fix:** Use separate output for status messages. Either escape everything and use CSS for icons, or use a template function that clearly separates HTML structure from user data.

---

### ADMIN-R2-014: News Title Not Sanitized on Public Display
**Severity:** MEDIUM
**File:** `index.php:58`

```php
itemAccordion($donnees['titre'], '<img src="images/accueil/newspaper.png" width="44">', $contenuNews);
```

The news title `$donnees['titre']` is passed directly from the database to `itemAccordion()` without `htmlspecialchars()`. While only an admin can create news, this is still a stored XSS vector that could be exploited if the admin account is compromised, or by a malicious admin injecting XSS into the title for persistence.

**Fix:** Apply `htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8')` before passing to `itemAccordion()`.

---

### ADMIN-R2-015: listenews.php Calls csrfCheck() on GET Requests
**Severity:** LOW (functional bug, not security)
**File:** `admin/listenews.php:40`

```php
// CSRF check for all POST actions
csrfCheck();
```

The comment says "POST actions" but `csrfCheck()` is called unconditionally. However, reviewing the `csrfCheck()` implementation:

```php
function csrfCheck() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfVerify()) {
            die('...');
        }
    }
}
```

The function internally checks for POST, so GET requests pass through harmlessly. However, the unconditional call is misleading and could hide bugs if the CSRF implementation changes.

**Fix:** Wrap in `if ($_SERVER['REQUEST_METHOD'] === 'POST')` for clarity, matching the pattern used in `moderation/index.php` and `admin/listesujets.php`.

---

### ADMIN-R2-016: admin/redirectionmotdepasse.php Uses Bare session_start()
**Severity:** MEDIUM
**File:** `admin/redirectionmotdepasse.php:2`

```php
session_start();
include_once(__DIR__ . '/../includes/constantesBase.php');
```

This auth guard used by `supprimercompte.php`, `supprimerreponse.php`, `listenews.php`, `redigernews.php`, `listesujets.php`, `ip.php`, and `tableau.php` uses bare `session_start()` without session hardening.

**Fix:** Replace with `require_once(__DIR__ . '/../includes/session_init.php')`.

---

### ADMIN-R2-017: No IP-Based Access Restriction on Admin Panel
**Severity:** MEDIUM
**Files:** `admin/index.php`, `moderation/index.php`

The admin and moderation panels are accessible from any IP address. There is no `.htaccess` file in the `admin/` or `moderation/` directories to restrict access.

**Fix:** Add IP-based restrictions via `.htaccess`:
```apache
# admin/.htaccess
<IfModule mod_authz_core.c>
    Require ip 192.168.1.0/24
    Require ip YOUR_ADMIN_IP
</IfModule>
```

Alternatively, add IP validation in `admin/index.php` and `moderation/index.php` before showing the login form.

---

### ADMIN-R2-018: Moderator Bomb Point Increment -- No Validation on Player Name
**Severity:** MEDIUM
**File:** `moderation/index.php:41-50`

```php
if (isset($_POST['joueurBombe'])) {
    $nb = dbCount($base, 'SELECT count(login) AS nb FROM membre WHERE login = ?', 's', $_POST['joueurBombe']);
    if ($nb > 0) {
        $joueur = dbFetchOne($base, 'SELECT bombe FROM autre WHERE login = ?', 's', $_POST['joueurBombe']);
        dbExecute($base, 'UPDATE autre SET bombe = ? WHERE login = ?', 'is', ($joueur['bombe'] + 1), $_POST['joueurBombe']);
    }
}
```

**Issues:**
1. The player username is used directly from POST without length or format validation (only existence check).
2. No maximum limit on bomb points -- can be incremented indefinitely by clicking repeatedly.
3. No audit log of who received bomb points or why.
4. A moderator could target any player, including themselves, for medal progression abuse.

**Fix:**
1. Validate username format (alphanumeric, length bounds).
2. Consider adding a maximum bomb point limit.
3. Log all bomb point additions.

---

### ADMIN-R2-019: Forum Ban Motif Rendered via BBCode Without Full Sanitization
**Severity:** MEDIUM
**File:** `moderationForum.php:123`

```php
"<td>" . BBcode($sanction['motif']) . "</td>"
```

The `BBcode()` function in `includes/bbcode.php` applies `htmlentities()` first (line 317), which provides base XSS protection. However, it then applies regex replacements that re-introduce HTML:

```php
// bbcode.php line 331
$text = preg_replace('!\[url=(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\](.+)\[/url\]!isU', '<a href="$1">$5</a>', $text);

// bbcode.php line 332
$text = preg_replace('!\[img=(https?:\/\/[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU', '<img alt="undefined" src="$1">', $text);
```

The `[url=...]` regex restricts to `https?://` URLs, which prevents `javascript:` injection in the href. The `[img=...]` regex also restricts to `https?://` with image extensions. These are reasonably safe.

However, the `$sanction['motif']` value is stored from `$_POST['motif']` at line 41 without any sanitization before storage. A moderator could potentially craft BBCode that, when combined with the regex processing, produces unexpected output. The attack surface is limited by `htmlentities()` being applied first, but relying on regex for security is fragile.

**Fix:** Validate and sanitize `$_POST['motif']` before storage. Consider limiting maximum length.

---

### ADMIN-R2-020: moderationForum.php -- Moderator Can Delete Any Sanction
**Severity:** LOW
**File:** `moderationForum.php:21-24`

```php
if (isset($_POST['supprimer'])) {
    csrfCheck();
    $supprimerId = (int)$_POST['supprimer'];
    dbExecute($base, 'DELETE FROM sanctions WHERE idSanction = ?', 'i', $supprimerId);
}
```

Any user with the `moderateur` database flag can delete any sanction, including those created by other moderators. There is no ownership check and no logging.

**Fix:** Either require the deleting moderator to be the one who created the sanction, or log all sanction deletions with the acting moderator.

---

### ADMIN-R2-021: Forum Moderator Flag (DB Column) Has No Admin-Only Control
**Severity:** MEDIUM
**Files:** `moderationForum.php:7-8`, database schema

The moderator status is stored as a `moderateur` column in the `membre` table:

```php
$joueur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$joueur['moderateur']) {
    // deny access
}
```

**Issues:**
1. There is no admin interface to grant or revoke moderator status. It must be done via direct SQL.
2. No logging when moderator status changes.
3. A SQL injection vulnerability anywhere in the application could be used to self-promote to moderator.
4. The moderator flag persists across season resets (it is on the `membre` table which is not wiped).

**Fix:** Add a moderator management page in the admin panel with proper logging.

---

### ADMIN-R2-022: Admin supprimercompte.php -- Double Execution on POST
**Severity:** LOW (functional bug)
**File:** `admin/supprimercompte.php:9-18, 39-47`

The file processes the deletion at lines 9-18, then checks again at lines 39-47 to display a message. The actual deletion happens correctly (once), but the logic structure is confusing and fragile:

```php
// Line 9-18: Actual deletion
if (isset($_POST['supprimercompte'])) {
    csrfCheck();
    $joueurExiste = dbCount(..., $_POST['supprimer']);
    if ($joueurExiste > 0) {
        supprimerJoueur($_POST['supprimer']);
    }
}

// Line 39-47: Display message (re-checks the same POST data)
if (isset($_POST['supprimercompte'])) {
    if ($_POST['supprimercompte'] == "Supprimer le compte") {
        if ($joueurExiste > 0) {
            echo "Vous avez supprimer le compte " . htmlspecialchars($_POST['supprimer'], ENT_QUOTES, 'UTF-8') . ".";
        }
    }
}
```

The second check compares `$_POST['supprimercompte']` against the submit button's value string, which is brittle (depends on exact button text matching).

**Fix:** Use a single processing block with a status flag.

---

### ADMIN-R2-023: Admin tableau.php -- Large Unsanitized HTML Block
**Severity:** LOW
**File:** `admin/tableau.php`

This page contains a large hardcoded HTML template (54.9 KB) with instructions for embedding CRPJs. It is behind admin auth, but contains no dynamic content and no user input processing. The file is essentially a static page served through PHP.

**Risk:** Minimal, as it is purely informational and behind authentication. However, the large static HTML block is unnecessary PHP overhead.

**Fix:** Convert to a static HTML file or move to a documentation directory.

---

### ADMIN-R2-024: Moderator Forum Topic Move -- No Validation of Target Forum ID
**Severity:** LOW
**File:** `moderation/index.php:35-39`

```php
if (isset($_POST['deplacer']) and isset($_POST['deplacerSubmit']) and isset($_POST['idSujet'])) {
    $deplacer = (int)$_POST['deplacer'];
    $idSujet = (int)$_POST['idSujet'];
    dbExecute($base, 'UPDATE sujets SET idforum = ? WHERE id = ?', 'ii', $deplacer, $idSujet);
}
```

The target forum ID `$deplacer` is cast to `(int)` but not validated against existing forum IDs. A moderator could set a topic's forum ID to a non-existent forum, making it invisible.

**Fix:** Verify `$deplacer` exists in the `forums` table before updating.

---

### ADMIN-R2-025: messageCommun.php -- Admin Flag Check Uses empty() Instead of Strict Comparison
**Severity:** LOW
**File:** `messageCommun.php:10`

```php
if (empty($_SESSION['motdepasseadmin'])) {
    header('Location: index.php');
    exit();
}
```

The `empty()` function returns true for `null`, `false`, `0`, `""`, `"0"`, and unset variables. Since `$_SESSION['motdepasseadmin']` is set to boolean `true`, `empty()` works correctly here. However, using `empty()` is less strict than the `=== true` comparison used in the other admin guards and could be vulnerable if the session value is somehow set to a truthy-but-not-true value.

**Fix:** Use `!isset($_SESSION['motdepasseadmin']) || $_SESSION['motdepasseadmin'] !== true` for consistency with other admin guards.

---

### ADMIN-R2-026: moderationForum.php -- Ban Date Validation Incomplete
**Severity:** LOW
**File:** `moderationForum.php:35-38`

```php
$parts = explode('/', $_POST['dateFin']);
if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) {
    $erreur = "<strong>Erreur</strong> : Date invalide.";
}
```

While `checkdate()` validates the date components, there is no check that:
1. The ban end date is in the future (a moderator could set a past date, creating a meaningless ban).
2. The ban duration is reasonable (no maximum duration check; a moderator could ban someone until year 9999).

**Fix:** Add validation that the end date is after today and within a reasonable maximum (e.g., 1 year).

---

### ADMIN-R2-027: Admin Panel Exposed via Predictable URL Path
**Severity:** LOW
**Files:** `admin/index.php`, `moderation/index.php`

The admin panel is at the predictable path `/admin/index.php` and the moderation panel at `/moderation/index.php`. Automated scanners routinely probe these paths.

**Fix:** While security through obscurity is not sufficient alone, consider:
1. Renaming the directories to non-obvious paths.
2. Adding IP restrictions (see ADMIN-R2-017).
3. Adding fail2ban rules for repeated failed admin login attempts.
4. The rate limiter is already in place (5 attempts / 300 seconds), which is good.

---

## Positive Findings (What Works Well)

1. **Admin password is bcrypt-hashed** (not plaintext or MD5) in `constantesBase.php`.
2. **Rate limiting on admin login** -- both `admin/index.php` and `moderation/index.php` use `rateLimitCheck()` with 5 attempts per 300 seconds.
3. **CSRF protection on all admin actions** -- every destructive action (delete account, maintenance toggle, reset, resource grant, forum operations) has `csrfCheck()` and `csrfField()`.
4. **Prepared statements throughout** -- all SQL in admin/mod files uses parameterized queries via `dbExecute()`, `dbFetchOne()`, `dbQuery()`.
5. **XSS-safe output in most places** -- `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` is used consistently when displaying user data in admin pages.
6. **Integer casting on IDs** -- all forum/news/sanction IDs are cast with `(int)` before use.
7. **Login events are logged** -- successful and failed admin/mod logins are recorded via `logInfo()`/`logWarn()`.
8. **validerpacte.php is properly secured** -- requires `basicprivatephp.php` auth, CSRF check, alliance chief authorization.
9. **voter.php is properly secured** -- session token validation, CSRF on POST, login check.
10. **moderationForum.php checks moderateur flag** before processing any actions and correctly exits on unauthorized access.
11. **Moderation resource grants are recorded** in the `moderation` table with justification text.

---

## Summary Table

| ID | Severity | Category | Finding |
|----|----------|----------|---------|
| ADMIN-R2-001 | CRITICAL | AuthN | Shared password, no per-user admin accounts |
| ADMIN-R2-002 | CRITICAL | AuthZ | No admin/moderator role separation |
| ADMIN-R2-003 | HIGH | Session | No session regeneration on admin login |
| ADMIN-R2-004 | HIGH | Session | Bare session_start() instead of hardened session_init.php |
| ADMIN-R2-005 | HIGH | Session | No admin session timeout or logout |
| ADMIN-R2-006 | HIGH | Session | Admin session not isolated from game session |
| ADMIN-R2-007 | MEDIUM | CSRF | No CSRF token on admin login form |
| ADMIN-R2-008 | HIGH | XSS | Stored XSS via news content (admin-to-public) |
| ADMIN-R2-009 | HIGH | Audit | No audit trail for any admin/mod action |
| ADMIN-R2-010 | HIGH | Logic | Bulk account deletion by IP, no confirmation |
| ADMIN-R2-011 | HIGH | Logic | Season reset button with no confirmation or pre-checks |
| ADMIN-R2-012 | HIGH | Validation | Moderator resource grant with no upper bound |
| ADMIN-R2-013 | MEDIUM | XSS | $erreur echoed with raw HTML in moderation |
| ADMIN-R2-014 | MEDIUM | XSS | News title not sanitized on public display |
| ADMIN-R2-015 | LOW | Code Quality | csrfCheck() called unconditionally in listenews.php |
| ADMIN-R2-016 | MEDIUM | Session | redirectionmotdepasse.php uses bare session_start() |
| ADMIN-R2-017 | MEDIUM | Access | No IP-based access restriction on admin panel |
| ADMIN-R2-018 | MEDIUM | Validation | Bomb point increment has no max limit or audit |
| ADMIN-R2-019 | MEDIUM | XSS | BBCode in ban motif -- fragile sanitization chain |
| ADMIN-R2-020 | LOW | AuthZ | Any moderator can delete any sanction |
| ADMIN-R2-021 | MEDIUM | AuthZ | No admin interface to manage moderator status |
| ADMIN-R2-022 | LOW | Code Quality | Double POST check in supprimercompte.php |
| ADMIN-R2-023 | LOW | Code Quality | Large static HTML in tableau.php |
| ADMIN-R2-024 | LOW | Validation | Topic move allows non-existent forum IDs |
| ADMIN-R2-025 | LOW | AuthN | messageCommun.php uses empty() vs strict comparison |
| ADMIN-R2-026 | LOW | Validation | Ban date has no future/max-duration check |
| ADMIN-R2-027 | LOW | Access | Admin panel at predictable URL path |

**Severity Distribution:** 2 CRITICAL, 8 HIGH, 8 MEDIUM, 9 LOW

---

## Priority Remediation Order

### Phase 1 -- Immediate (CRITICAL + highest-impact HIGH)
1. **ADMIN-R2-008** -- Stored XSS via news. An admin can inject JavaScript that executes for ALL visitors. Fix: sanitize with HTML Purifier or strip all tags from news content.
2. **ADMIN-R2-003 + ADMIN-R2-004** -- Session fixation and hardening. Replace bare `session_start()` with `session_init.php` and add `session_regenerate_id(true)` after admin login.
3. **ADMIN-R2-011** -- Remove or heavily guard the manual season reset button.
4. **ADMIN-R2-009** -- Add logging to all admin actions. This is foundational for incident response.

### Phase 2 -- Short-term (remaining HIGH + MEDIUM)
5. **ADMIN-R2-001 + ADMIN-R2-002** -- Implement per-user admin accounts with role separation.
6. **ADMIN-R2-005** -- Admin session timeout (15 min) and logout.
7. **ADMIN-R2-006** -- Separate admin session cookie.
8. **ADMIN-R2-010** -- Confirmation step for bulk account deletion.
9. **ADMIN-R2-012** -- Resource grant upper bounds.
10. **ADMIN-R2-007** -- CSRF on login forms.
11. **ADMIN-R2-014** -- Sanitize news title.
12. **ADMIN-R2-017** -- IP restriction on admin panel.

### Phase 3 -- Hardening (LOW items)
13. Remaining LOW findings: code quality improvements, validation enhancements, URL obscurity.
