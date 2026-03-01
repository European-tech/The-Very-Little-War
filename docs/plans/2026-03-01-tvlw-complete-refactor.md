# The Very Little War - Complete Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Completely refactor the TVLW legacy PHP game to fix all security vulnerabilities, bugs, game balance issues, and make the codebase maintainable.

**Architecture:** Incremental refactoring of existing procedural PHP codebase. We will NOT rewrite from scratch - we will progressively modernize file-by-file while keeping the game functional at every step. Each phase builds on the previous. Security fixes come first, then structural improvements, then bug fixes, then balance.

**Tech Stack:** PHP 8.x, MySQLi with prepared statements, bcrypt password hashing, CSRF tokens, proper session management. Framework7 Material CSS (kept as-is for UI).

---

## Phase 1: Infrastructure Setup

### Task 1: Initialize Git Repository and Project Structure

**Files:**
- Create: `/home/guortates/TVLW/The-Very-Little-War/.gitignore`
- Create: `/home/guortates/TVLW/The-Very-Little-War/docs/BUGS.md`
- Create: `/home/guortates/TVLW/The-Very-Little-War/docs/SECURITY.md`

**Step 1: Create .gitignore**

Create `/home/guortates/TVLW/The-Very-Little-War/.gitignore`:
```
# Config with credentials
includes/connexion.php.bak
*.sql
*.psd

# IDE
.idea/
.vscode/
*.swp
*.swo

# OS
.DS_Store
Thumbs.db
```

**Step 2: Initialize git repo**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && git init && git add -A && git commit -m "chore: initial commit of legacy codebase"`

**Step 3: Create bug tracker document**

Create `/home/guortates/TVLW/The-Very-Little-War/docs/BUGS.md` documenting all 11 known bugs from the analysis.

**Step 4: Create security tracker document**

Create `/home/guortates/TVLW/The-Very-Little-War/docs/SECURITY.md` documenting all 18 security vulnerabilities from the analysis.

**Step 5: Commit**

```bash
git add docs/
git commit -m "docs: add bug tracker and security vulnerability documentation"
```

---

## Phase 2: Critical Security Fixes

### Task 2: Fix Database Connection - Switch from mysql_connect to mysqli

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php`

**Step 1: Read current connexion.php**

Read the file to understand current connection code.

**Step 2: Rewrite connexion.php with proper mysqli**

Replace the entire file with:
```php
<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'theveryl_theverylittlewar';

$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$base) {
    die('Erreur de connexion à la base de données.');
}

mysqli_set_charset($base, 'utf8');
```

**Step 3: Commit**

```bash
git add includes/connexion.php
git commit -m "fix(security): replace deprecated mysql_connect with proper mysqli_connect"
```

### Task 3: Create Secure Database Helper with Prepared Statements

**Files:**
- Create: `/home/guortates/TVLW/The-Very-Little-War/includes/database.php`

**Step 1: Create database.php with prepared statement helpers**

Create `/home/guortates/TVLW/The-Very-Little-War/includes/database.php`:
```php
<?php
/**
 * Safe database query helpers using prepared statements.
 * Prevents SQL injection throughout the application.
 */

/**
 * Execute a prepared query and return the result.
 * Usage: dbQuery($base, "SELECT * FROM membre WHERE login = ?", "s", $login)
 */
function dbQuery($base, $sql, $types = "", ...$params) {
    $stmt = mysqli_prepare($base, $sql);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . mysqli_error($base) . " | Query: " . $sql);
        return false;
    }
    if ($types && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log("SQL Execute Error: " . mysqli_stmt_error($stmt));
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    return $result;
}

/**
 * Execute a prepared query and return one row.
 */
function dbFetchOne($base, $sql, $types = "", ...$params) {
    $result = dbQuery($base, $sql, $types, ...$params);
    if (!$result) return null;
    return mysqli_fetch_assoc($result);
}

/**
 * Execute a prepared query and return all rows.
 */
function dbFetchAll($base, $sql, $types = "", ...$params) {
    $result = dbQuery($base, $sql, $types, ...$params);
    if (!$result) return [];
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Execute a prepared INSERT/UPDATE/DELETE and return affected rows.
 */
function dbExecute($base, $sql, $types = "", ...$params) {
    $stmt = mysqli_prepare($base, $sql);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . mysqli_error($base) . " | Query: " . $sql);
        return false;
    }
    if ($types && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log("SQL Execute Error: " . mysqli_stmt_error($stmt));
        return false;
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

/**
 * Get the last inserted ID.
 */
function dbLastId($base) {
    return mysqli_insert_id($base);
}

/**
 * Escape string for use in LIKE patterns (not a substitute for prepared statements).
 */
function dbEscapeLike($str) {
    return str_replace(['%', '_'], ['\\%', '\\_'], $str);
}
```

**Step 2: Include database.php in connexion.php**

Add to the end of `connexion.php`:
```php
require_once(__DIR__ . '/database.php');
```

**Step 3: Commit**

```bash
git add includes/database.php includes/connexion.php
git commit -m "feat(security): add prepared statement database helpers to prevent SQL injection"
```

### Task 4: Implement CSRF Protection

**Files:**
- Create: `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`

**Step 1: Create CSRF helper**

Create `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`:
```php
<?php
/**
 * CSRF protection: generate and verify tokens.
 */

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfVerify() {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function csrfCheck() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfVerify()) {
            die('Erreur de sécurité : jeton CSRF invalide. Veuillez rafraîchir la page.');
        }
    }
}
```

**Step 2: Include csrf.php in basicprivatephp.php**

Add `require_once(__DIR__ . '/csrf.php');` at the top of `basicprivatephp.php`, after the session/connexion includes.

**Step 3: Commit**

```bash
git add includes/csrf.php includes/basicprivatephp.php
git commit -m "feat(security): add CSRF token generation and verification"
```

### Task 5: Fix Password Hashing - Replace MD5 with bcrypt

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/inscription.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/compte.php`

**Step 1: Read all affected files**

Read inscription.php, basicpublicphp.php, basicprivatephp.php, and compte.php.

**Step 2: Update inscription.php**

Replace `md5($password)` with `password_hash($password, PASSWORD_DEFAULT)` in the INSERT query.

**Step 3: Update basicpublicphp.php login verification**

Replace the MD5 comparison with `password_verify()`. Also support legacy MD5 logins with automatic upgrade:
```php
// Login verification with automatic MD5->bcrypt migration
$row = dbFetchOne($base, "SELECT login, pass_md5 FROM membre WHERE login = ?", "s", $login);
if ($row) {
    $storedHash = $row['pass_md5'];
    $valid = false;

    // Try bcrypt first (new format)
    if (password_verify($password, $storedHash)) {
        $valid = true;
    }
    // Fall back to legacy MD5
    elseif (md5($password) === $storedHash) {
        $valid = true;
        // Auto-upgrade to bcrypt
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        dbExecute($base, "UPDATE membre SET pass_md5 = ? WHERE login = ?", "ss", $newHash, $login);
        $storedHash = $newHash;
    }

    if ($valid) {
        $_SESSION['login'] = $row['login'];
        $_SESSION['mdp'] = $storedHash;
        // ... rest of login
    }
}
```

**Step 4: Remove plaintext password from localStorage**

In basicpublicphp.php, remove the line:
```php
localStorage.setItem("mdp", "' . $untreatedPass . '");
```
Replace the auto-login mechanism with a secure "remember me" cookie using a random token.

**Step 5: Update basicprivatephp.php session validation**

Change session validation to compare the stored hash directly (no re-hashing needed since we store the hash in session).

**Step 6: Update compte.php password change**

Require current password verification before allowing password change. Use `password_hash()` for new password.

**Step 7: Commit**

```bash
git add inscription.php includes/basicpublicphp.php includes/basicprivatephp.php compte.php
git commit -m "fix(security): replace MD5 with bcrypt, add password migration, remove localStorage passwords"
```

### Task 6: Fix SQL Injection in Registration (inscription.php)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/inscription.php`

**Step 1: Read inscription.php**

**Step 2: Replace all raw SQL with prepared statements**

Replace every `mysqli_query($base, '...' . $_POST['...'] . '...')` with `dbQuery()`/`dbExecute()` calls using parameterized queries.

**Step 3: Add input validation**

- Validate login length (3-20 chars, alphanumeric + underscore)
- Validate email format with `filter_var()`
- Validate password length (min 6 chars)

**Step 4: Commit**

```bash
git add inscription.php
git commit -m "fix(security): prevent SQL injection in registration with prepared statements"
```

### Task 7: Fix SQL Injection in Attack/Espionage (attaquer.php)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/attaquer.php`

**Step 1: Read attaquer.php**

**Step 2: Replace all raw SQL concatenation with prepared statements**

Convert all `mysqli_query()` calls that use `$_POST` or `$_GET` values to use `dbQuery()`/`dbExecute()`.

**Step 3: Add CSRF check for attack/espionage forms**

Add `csrfCheck()` at the top of POST handling blocks.

**Step 4: Commit**

```bash
git add attaquer.php
git commit -m "fix(security): prevent SQL injection in attack/espionage with prepared statements"
```

### Task 8: Fix SQL Injection in All Remaining Files

**Files:**
- Modify: ALL PHP files that use raw `$_POST`/`$_GET` in SQL queries

Target files (in priority order):
1. `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php` - Core functions
2. `/home/guortates/TVLW/The-Very-Little-War/includes/update.php` - Attack resolution
3. `/home/guortates/TVLW/The-Very-Little-War/includes/update1.php` - Espionage resolution
4. `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php` - Combat system
5. `/home/guortates/TVLW/The-Very-Little-War/marche.php` - Market
6. `/home/guortates/TVLW/The-Very-Little-War/alliance.php` - Alliance view
7. `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` - Alliance admin
8. `/home/guortates/TVLW/The-Very-Little-War/armee.php` - Army management
9. `/home/guortates/TVLW/The-Very-Little-War/constructions.php` - Buildings
10. `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php` - Messages
11. `/home/guortates/TVLW/The-Very-Little-War/forum.php` - Forum
12. `/home/guortates/TVLW/The-Very-Little-War/sujet.php` - Forum topics
13. `/home/guortates/TVLW/The-Very-Little-War/listesujets.php` - Forum topic list
14. `/home/guortates/TVLW/The-Very-Little-War/messages.php` - Private messages
15. `/home/guortates/TVLW/The-Very-Little-War/rapports.php` - Reports
16. `/home/guortates/TVLW/The-Very-Little-War/classement.php` - Rankings
17. `/home/guortates/TVLW/The-Very-Little-War/joueur.php` - Player profile
18. `/home/guortates/TVLW/The-Very-Little-War/compte.php` - Account
19. `/home/guortates/TVLW/The-Very-Little-War/don.php` - Donations
20. `/home/guortates/TVLW/The-Very-Little-War/molecule.php` - Molecule view
21. `/home/guortates/TVLW/The-Very-Little-War/guerre.php` - War details
22. `/home/guortates/TVLW/The-Very-Little-War/validerpacte.php` - Pact validation
23. `/home/guortates/TVLW/The-Very-Little-War/historique.php` - History
24. `/home/guortates/TVLW/The-Very-Little-War/annonce.php` - Announcements
25. `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php` - Forum moderation
26. `/home/guortates/TVLW/The-Very-Little-War/medailles.php` - Medals
27. `/home/guortates/TVLW/The-Very-Little-War/api.php` - API
28. `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` - Admin
29. `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php` - Moderation

**Step 1: For each file, read it**
**Step 2: Replace all raw SQL with prepared statements using dbQuery/dbExecute/dbFetchOne/dbFetchAll**
**Step 3: Add CSRF protection to all POST forms**
**Step 4: Replace all `die()` with SQL errors to use `error_log()` and show generic error**
**Step 5: Commit after each file group (3-5 files per commit)**

```bash
git commit -m "fix(security): convert all SQL queries to prepared statements in [file group]"
```

### Task 9: Fix Hardcoded Admin Password

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/admin/index.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/moderation/index.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/moderation/mdp.php`

**Step 1: Read all admin/moderation files**

**Step 2: Replace hardcoded password with bcrypt-hashed constant**

In `constantesBase.php`, add:
```php
// Admin password hash - change this via: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
define('ADMIN_PASSWORD_HASH', '$2y$10$PLACEHOLDER_CHANGE_ME');
```

**Step 3: Update admin/index.php and moderation/index.php**

Use `password_verify()` against the constant instead of string comparison.

**Step 4: Commit**

```bash
git add admin/index.php moderation/index.php moderation/mdp.php includes/constantesBase.php
git commit -m "fix(security): replace hardcoded admin password with bcrypt hash"
```

### Task 10: Remove Dangerous sql.php File

**Files:**
- Delete: `/home/guortates/TVLW/The-Very-Little-War/sql.php`

**Step 1: Read sql.php to check if it has anything needed**
**Step 2: Delete the file**

```bash
git rm sql.php
git commit -m "fix(security): remove dangerous sql.php with arbitrary query execution"
```

### Task 11: Fix File Upload Security

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/compte.php`

**Step 1: Read the file upload section of compte.php**

**Step 2: Add proper validation**

```php
// Validate file upload
$allowed_types = ['image/png', 'image/gif', 'image/jpeg'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed_types)) {
    $erreur = "Type de fichier non autorisé.";
} else {
    // Generate random filename to prevent path traversal
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $ext = strtolower($ext);
    if (!in_array($ext, ['png', 'gif', 'jpg', 'jpeg'])) {
        $erreur = "Extension non autorisée.";
    } else {
        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = 'images/profil/' . $newName;
        move_uploaded_file($_FILES['image']['tmp_name'], $destPath);
        // Update database with new filename
        dbExecute($base, "UPDATE membre SET image = ? WHERE login = ?", "ss", $newName, $_SESSION['login']);
    }
}
```

**Step 3: Commit**

```bash
git add compte.php
git commit -m "fix(security): add proper MIME validation and random filenames for uploads"
```

### Task 12: Add Authentication to API

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/api.php`

**Step 1: Read api.php**
**Step 2: Add session authentication check at the top**
**Step 3: Commit**

```bash
git add api.php
git commit -m "fix(security): require authentication for API endpoints"
```

### Task 13: Fix GET-based Destructive Actions

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/messages.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/rapports.php`

**Step 1: Convert deletion actions from GET to POST with CSRF**

Replace `$_GET['supprimer']` checks with POST form submissions that include CSRF tokens.

**Step 2: Commit**

```bash
git add messages.php rapports.php
git commit -m "fix(security): convert destructive GET actions to POST with CSRF protection"
```

---

## Phase 3: Critical Bug Fixes

### Task 14: Fix Combat Duplicateur Bug (BUG-1 - CRITICAL)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`

**Step 1: Read combat.php around line 64**

**Step 2: Fix the wrong login reference**

Change:
```php
$exDuplicateurDefense = mysqli_query($base, 'SELECT idalliance FROM autre WHERE login=\'' . $actions['attaquant'] . '\'');
```
To:
```php
$exDuplicateurDefense = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $actions['defenseur']);
```

**Step 3: Commit**

```bash
git add includes/combat.php
git commit -m "fix(combat): use defender's login for defender duplicateur bonus (was using attacker's)"
```

### Task 15: Fix Inconsistent Depot/Storage Formulas (BUG-2)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/update1.php`

**Step 1: Read update.php and update1.php to find depot formula**

**Step 2: Replace the exponential formula with the correct linear one**

Replace `4 * pow(4, $depot+2)` with `placeDepot($depot)` (which returns `500 * $niveau`), or inline it as `500 * $depot`.

**Step 3: Commit**

```bash
git add includes/update.php includes/update1.php
git commit -m "fix(storage): use consistent depot formula in attack/spy resolution"
```

### Task 16: Fix Inconsistent Molecule Decay Formulas (BUG-3)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/update1.php`

**Step 1: Read the decay code in update.php and fonctions.php**

**Step 2: Align update.php/update1.php decay with fonctions.php exponential decay**

Use the same `coefDisparition()` and exponential decay formula from `fonctions.php::updateRessources()`.

**Step 3: Commit**

```bash
git add includes/update.php includes/update1.php
git commit -m "fix(decay): use consistent exponential molecule decay formula everywhere"
```

### Task 17: Fix supprimerJoueur Variable Overwrite (BUG-4)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`

**Step 1: Read the supprimerJoueur function**

**Step 2: Fix the duplicate $modif8 variable**

Rename the second `$modif8` to a unique variable name (e.g., `$modif9`).

**Step 3: Commit**

```bash
git add includes/fonctions.php
git commit -m "fix: fix variable overwrite in supprimerJoueur causing skipped deletions"
```

### Task 18: Fix Missing Break in Combat Switch (BUG-5)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`

**Step 1: Read combat.php around line 259**

**Step 2: Add missing `break;` after case 3**

**Step 3: Commit**

```bash
git add includes/combat.php
git commit -m "fix(combat): add missing break in building destruction switch"
```

### Task 19: Fix Molecule Deletion String Concatenation (BUG-8)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/armee.php`

**Step 1: Read armee.php around lines 46-51**

**Step 2: Fix the string concatenation**

Change `$chaine = "0;"` and `$chaine = $explosion[$i - 1] . ";"` to use `.=` instead of `=`.

**Step 3: Commit**

```bash
git add armee.php
git commit -m "fix(army): fix molecule deletion string concatenation in attack queue"
```

### Task 20: Fix Absurd Max Molecule Number (BUG-9)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/armee.php`

**Step 1: Read armee.php around line 339**

**Step 2: Replace the absurdly large number**

Change to a reasonable max like `PHP_INT_MAX` or a game-appropriate limit like `1000000`.

**Step 3: Commit**

```bash
git add armee.php
git commit -m "fix(army): replace overflow-causing max molecule number with reasonable limit"
```

### Task 21: Fix Classement Side-Effect Updates (BUG-10)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/classement.php`

**Step 1: Read classement.php to find the UPDATE queries**

**Step 2: Move alliance stat recalculation to a separate function**

Create a `recalculateAllianceStats()` function in fonctions.php that is called only when alliance data actually changes (member joins/leaves, war ends, etc.), not on every ranking page view.

**Step 3: Remove the UPDATE queries from classement.php**

**Step 4: Commit**

```bash
git add classement.php includes/fonctions.php
git commit -m "fix(ranking): move alliance stat recalculation out of page view"
```

### Task 22: Fix War Ending - Allow Both Sides to End War (BUG-11)

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`

**Step 1: Read allianceadmin.php war ending code**

**Step 2: Update the WHERE clause to allow either alliance to end the war**

Change from:
```sql
WHERE alliance1 = ? AND alliance2 = ?
```
To:
```sql
WHERE (alliance1 = ? AND alliance2 = ?) OR (alliance1 = ? AND alliance2 = ?)
```

**Step 3: Commit**

```bash
git add allianceadmin.php
git commit -m "fix(alliance): allow both war participants to end a war"
```

---

## Phase 4: Code Consolidation and Deduplication

### Task 23: Merge update.php and update1.php Into a Single Function

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`
- Delete: `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
- Delete: `/home/guortates/TVLW/The-Very-Little-War/includes/update1.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`

**Step 1: Read both update.php and update1.php**

**Step 2: Create a unified `updateTargetResources($base, $targetLogin)` function in fonctions.php**

This function should accept the target player login as parameter instead of reading from `$_POST`.

**Step 3: Update callers in basicprivatephp.php to use the new function**

**Step 4: Delete the old files**

**Step 5: Commit**

```bash
git rm includes/update.php includes/update1.php
git add includes/fonctions.php includes/basicprivatephp.php
git commit -m "refactor: merge duplicate update.php/update1.php into unified function"
```

### Task 24: Extract Constants for Magic Numbers

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`

**Step 1: Read constantesBase.php**

**Step 2: Add constants for all magic numbers used in the codebase**

```php
// Time constants
define('SECONDS_PER_HOUR', 3600);
define('SECONDS_PER_DAY', 86400);
define('SECONDS_PER_WEEK', 604800);
define('SECONDS_PER_MONTH', 2678400);

// Game limits
define('MAX_CONCURRENT_CONSTRUCTIONS', 2);
define('MAX_MOLECULE_CLASSES', 5);
define('MAX_ATOMS_PER_MOLECULE', 200);
define('MAX_ALLIANCE_MEMBERS', 20);
define('MAX_MOLECULES_REASONABLE', 1000000);

// Beginner protection
define('BEGINNER_PROTECTION_SECONDS', 2 * SECONDS_PER_DAY);

// Combat
define('ATTACK_ENERGY_COST_FACTOR', 0.15);
define('ESPIONAGE_SPEED', 20); // cases per hour
define('NEUTRINO_COST', 50); // energy per neutrino

// Resource production base rates
define('BASE_ENERGY_PER_LEVEL', 65);
define('BASE_ATOMS_PER_POINT', 30);
define('BASE_STORAGE_PER_LEVEL', 500);
define('PRODUCTEUR_DRAIN_PER_LEVEL', 12);
```

**Step 3: Replace magic numbers throughout the codebase with these constants**

**Step 4: Commit per file group**

```bash
git commit -m "refactor: replace magic numbers with named constants"
```

### Task 25: Clean Up Error Handling

**Files:**
- Modify: ALL PHP files with `die()` statements

**Step 1: Create error handler function**

In `fonctions.php`:
```php
function showError($message = "Une erreur est survenue.") {
    error_log($message);
    // Display user-friendly error
    echo '<div class="card"><div class="card-content"><div class="card-content-inner">';
    echo '<p style="color:red;">' . htmlspecialchars($message) . '</p>';
    echo '</div></div></div>';
}
```

**Step 2: Replace all `die('Erreur SQL...')` with error_log and showError**

Search and replace across all files.

**Step 3: Commit**

```bash
git commit -m "fix: replace die() SQL error disclosure with safe error handling"
```

### Task 26: Fix All mysql_error() to mysqli_error()

**Files:**
- All PHP files using `mysql_error()`

**Step 1: Search for all `mysql_error` usage**

**Step 2: Replace with `mysqli_error($base)` or remove entirely (since we're using error_log now)**

**Step 3: Commit**

```bash
git commit -m "fix: replace deprecated mysql_error with mysqli_error"
```

---

## Phase 5: Game Balance Improvements

### Task 27: Rebalance Attack Energy Cost

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/constantesBase.php`

**Step 1: Read the coutAttaque function**

**Step 2: Make attack cost scale with distance**

```php
function coutAttaque($nbAtomes, $nbMolecules, $distance, $joueur) {
    $baseCost = ATTACK_ENERGY_COST_FACTOR * $nbAtomes * $nbMolecules;
    $distanceFactor = 1 + ($distance / 50); // More expensive to attack far away
    $medalReduction = 1 - bonusMedaille('Terreur', $joueur) / 100;
    return round($baseCost * $distanceFactor * $medalReduction);
}
```

**Step 3: Update attaquer.php to pass distance to the cost function**

**Step 4: Commit**

```bash
git commit -m "balance: make attack cost scale with distance"
```

### Task 28: Rebalance Duplicateur Bonus

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`

**Step 1: Read the duplicateur bonus calculation**

**Step 2: Increase the bonus to be meaningful**

Change from `(0.1 * level) / 100` (0.1% per level) to `(2 * level) / 100` (2% per level, so level 5 = 10% bonus, level 10 = 20% bonus).

**Step 3: Adjust duplicateur cost curve to match the increased value**

**Step 4: Commit**

```bash
git commit -m "balance: increase duplicateur bonus from 0.1% to 2% per level"
```

### Task 29: Improve Storage Scaling

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/includes/fonctions.php`

**Step 1: Read placeDepot function**

**Step 2: Change from linear to slightly exponential**

```php
function placeDepot($niveau) {
    return round(500 * pow($niveau, 1.3));
}
```
This gives: level 1=500, level 5=4152, level 10=9976, level 20=24251, level 50=85387.

**Step 3: Commit**

```bash
git commit -m "balance: improve storage scaling from linear to soft exponential"
```

### Task 30: Add Market Manipulation Protection

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/marche.php`

**Step 1: Read market buy/sell code**

**Step 2: Add transaction limits**

```php
// Max transaction per trade: 20% of player's depot capacity
$maxTransaction = round(placeDepot($constructions['depot']) * 0.2);
if ($quantity > $maxTransaction) {
    $erreur = "Transaction limitée à " . $maxTransaction . " unités par échange.";
}

// Price movement cap: max 5% change per transaction
$maxPriceMovement = $currentPrice * 0.05;
```

**Step 3: Add cooldown between market transactions**

**Step 4: Commit**

```bash
git commit -m "balance: add market transaction limits and price movement caps"
```

### Task 31: Balance Resource Transfer Exploit

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/marche.php` (or wherever transfers are handled)

**Step 1: Read the resource transfer code**

**Step 2: Add flat transfer tax**

```php
// 10% flat transfer tax to prevent resource laundering
$received = round($sent * 0.9);
```

**Step 3: Add daily transfer limit**

**Step 4: Commit**

```bash
git commit -m "balance: add transfer tax and daily limits to prevent resource laundering"
```

---

## Phase 6: Code Quality and Maintenance

### Task 32: Add Proper Input Validation Function

**Files:**
- Create: `/home/guortates/TVLW/The-Very-Little-War/includes/validation.php`

**Step 1: Create validation.php**

```php
<?php
function validateLogin($login) {
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $login);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePositiveInt($value) {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

function validateRange($value, $min, $max) {
    $val = filter_var($value, FILTER_VALIDATE_INT);
    return $val !== false && $val >= $min && $val <= $max;
}

function sanitizeOutput($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
```

**Step 2: Include in basicprivatephp.php and basicpublicphp.php**

**Step 3: Commit**

```bash
git add includes/validation.php includes/basicprivatephp.php includes/basicpublicphp.php
git commit -m "feat: add proper input validation and sanitization functions"
```

### Task 33: Fix XSS in Report Content Output

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/rapports.php`

**Step 1: Read rapports.php around line 29**

**Step 2: Sanitize report content output**

Replace `echo $rapports['contenu'];` with proper sanitization, using a whitelist of allowed HTML tags for report formatting.

**Step 3: Commit**

```bash
git add rapports.php
git commit -m "fix(security): sanitize report content output to prevent XSS"
```

### Task 34: Remove Dead Code and Commented-Out Sections

**Files:**
- Modify: Multiple files with dead code

**Step 1: Search for commented-out code blocks**

**Step 2: Remove dead code that is clearly unused (commented sections, unused variables)**

**Step 3: Remove the comptetest.php and sansinscription.php files if they are test/debug files**

**Step 4: Commit per file**

```bash
git commit -m "chore: remove dead code and commented-out sections"
```

### Task 35: Add CSRF Tokens to ALL Forms

**Files:**
- Modify: All PHP files containing `<form` tags

**Step 1: Search for all form tags across the codebase**

**Step 2: Add `<?php echo csrfField(); ?>` to each form**

**Step 3: Add `csrfCheck()` at the top of each file's POST handling**

**Step 4: Commit per file group**

```bash
git commit -m "feat(security): add CSRF tokens to all forms"
```

### Task 36: Fix Password Change to Require Current Password

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/compte.php`

**Step 1: Read the password change section**

**Step 2: Add current password verification**

Add a "Current Password" field to the form and verify it with `password_verify()` before allowing the change.

**Step 3: Commit**

```bash
git add compte.php
git commit -m "fix(security): require current password verification for password changes"
```

### Task 37: Remove Hardcoded Username Checks

**Files:**
- Modify: `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`
- Modify: Any other file with hardcoded `"Guortates"` checks

**Step 1: Search for hardcoded username**

**Step 2: Replace with admin role check**

Add an `is_admin` column to the `membre` table or use the existing admin session check.

**Step 3: Commit**

```bash
git commit -m "fix: replace hardcoded username checks with proper admin role verification"
```

### Task 38: Final Review and Commit

**Step 1: Run a final scan for remaining issues**

Search for:
- Any remaining `mysql_` function calls
- Any remaining raw SQL concatenation with user input
- Any remaining `die()` with SQL exposure
- Any remaining `md5()` for password operations

**Step 2: Fix any remaining issues found**

**Step 3: Final commit**

```bash
git add -A
git commit -m "chore: final cleanup pass - fix remaining legacy issues"
```

---

## Execution Notes

- **Phase 2 (Security) is highest priority** - these are live vulnerabilities
- **Phase 3 (Bug Fixes)** should be done immediately after security
- **Phase 4 (Code Quality)** improves maintainability
- **Phase 5 (Balance)** can be tuned over time
- Each task is designed to be independently committable
- The game should remain functional after each commit
- Total estimated tasks: 38, grouped into 6 phases
