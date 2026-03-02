# 08 - Technical Reference

Comprehensive technical documentation for The Very Little War (TVLW) codebase.
Covers the API layer, database helpers, security mechanisms, session/auth flow,
logging, display formatting, UI components, and all game configuration constants.

---

## Table of Contents

1. [API Endpoints](#1-api-endpoints)
2. [Database Helper Functions](#2-database-helper-functions)
3. [Security Layer](#3-security-layer)
4. [Session and Authentication](#4-session-and-authentication)
5. [Logging System](#5-logging-system)
6. [Display Functions](#6-display-functions)
7. [UI Component Functions](#7-ui-component-functions)
8. [Complete Constants Reference](#8-complete-constants-reference)

---

## 1. API Endpoints

**File:** `api.php`

All endpoints are accessed via `GET /api.php?id=<endpoint>`. Every request requires
an active PHP session (`$_SESSION['login']` must be set). Unauthenticated requests
receive HTTP 401 with `{"error": "Authentication required"}`.

All responses are JSON-encoded with the format `{"valeur": <result>}`.

Common parameters are read from the query string and sanitized through `antiXSS()`.
Missing parameters default to `0`.

### Endpoint Reference

| Endpoint | Query Parameter (`?id=`) | Additional Parameters | Return Type | Description |
|---|---|---|---|---|
| Attack | `attaque` | `nombre` (atom count), `niveau` (level), `joueur` (player) | `int` | Calculates the attack power of a molecule based on oxygen atoms, level, and player bonuses. Formula: `round((1 + (0.1 * oxygene)^2 + oxygene) * (1 + niveau / 50))` |
| Defense | `defense` | `nombre` (atom count), `niveau` (level), `joueur` (player) | `int` | Calculates the defense power of a molecule based on carbon atoms, level, and player bonuses. Formula: `round((1 + (0.1 * carbone)^2 + carbone) * (1 + niveau / 50))` |
| HP | `pointsDeVieMolecule` | `nombre` (atom count), `niveau` (level) | `int` | Calculates molecule hit points based on bromine atoms and level. Formula: `round((1 + (0.1 * brome)^2 + brome) * (1 + niveau / 50))` |
| Destruction | `potentielDestruction` | `nombre` (atom count), `niveau` (level) | `int` | Calculates destruction potential based on hydrogen atoms and level. Formula: `round(((0.075 * hydrogene)^2 + hydrogene) * (1 + niveau / 50))` |
| Pillage | `pillage` | `nombre` (atom count), `niveau` (level), `joueur` (player) | `int` | Calculates pillage capacity based on sulfur atoms, level, and player bonuses. Formula: `round(((0.1 * soufre)^2 + soufre / 3) * (1 + niveau / 50))` |
| Energy Production | `productionEnergieMolecule` | `nombre` (atom count), `niveau` (level) | `int` | Calculates energy production from iodine atoms. Formula: `round(0.05 * iode * (1 + niveau / 50))` |
| Speed | `vitesse` | `nombre` (atom count), `niveau` (level) | `float` | Calculates molecule speed from chlorine atoms. Formula: `floor((1 + 0.5 * chlore) * (1 + niveau / 50) * 100) / 100` |
| Formation Time | `tempsFormation` | `nombre` (atom count), `niveau` (level), `nbTotalAtomes` (total atoms), `joueur` (player) | `string` | Calculates and formats the time to form a molecule based on nitrogen atoms, level, total atoms, and lieur bonus. Returns human-readable time string. Formula: `ceil(ntotal / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur * 100) / 100` |
| Half-Life | `demiVie` | `joueur` (player class or identifier), `nbTotalAtomes` (total atoms) | `string` | Calculates and formats the decay half-life of a molecule or class. Returns human-readable time string. |

### Example Request

```
GET /api.php?id=attaque&nombre=50&niveau=10&joueur=Player1
```

### Example Response

```json
{"valeur": 365}
```

### Parameter Handling

All parameters go through a sanitization loop at `api.php:14-23`:

```php
$param = ['nombre', 'niveau', 'joueur', 'nbTotalAtomes'];
foreach ($param as $num => $val) {
    if (isset($_GET[$val])) {
        $_GET[$val] = antiXSS($_GET[$val]);
    } else {
        $_GET[$val] = 0;
    }
}
```

---

## 2. Database Helper Functions

**File:** `includes/database.php`

All database operations use **prepared statements** via MySQLi to prevent SQL injection.
These functions wrap the raw MySQLi prepared statement API into a convenient, safe interface.

### dbQuery()

```php
function dbQuery($base, $sql, $types = "", ...$params): mysqli_result|false
```

Execute a prepared SELECT query and return the raw `mysqli_result` object.

| Parameter | Type | Description |
|---|---|---|
| `$base` | `mysqli` | Database connection handle |
| `$sql` | `string` | SQL query with `?` placeholders |
| `$types` | `string` | Type string for `bind_param` (`s`=string, `i`=int, `d`=double, `b`=blob) |
| `...$params` | `mixed` | Values to bind to the placeholders |

**Returns:** `mysqli_result` on success, `false` on failure (logged to `error_log`).

**Example:**
```php
$result = dbQuery($base, "SELECT * FROM membre WHERE login = ?", "s", $login);
```

**Defined at:** `includes/database.php:11`

---

### dbFetchOne()

```php
function dbFetchOne($base, $sql, $types = "", ...$params): ?array
```

Execute a prepared query and return a single row as an associative array.

| Parameter | Type | Description |
|---|---|---|
| `$base` | `mysqli` | Database connection handle |
| `$sql` | `string` | SQL query with `?` placeholders |
| `$types` | `string` | Type string for `bind_param` |
| `...$params` | `mixed` | Values to bind |

**Returns:** Associative `array` for the first row, or `null` if no results or error.

**Example:**
```php
$player = dbFetchOne($base, "SELECT login, pass_md5 FROM membre WHERE login = ?", "s", $login);
if ($player) {
    echo $player['login'];
}
```

**Defined at:** `includes/database.php:33`

---

### dbFetchAll()

```php
function dbFetchAll($base, $sql, $types = "", ...$params): array
```

Execute a prepared query and return all rows as an array of associative arrays.

| Parameter | Type | Description |
|---|---|---|
| `$base` | `mysqli` | Database connection handle |
| `$sql` | `string` | SQL query with `?` placeholders |
| `$types` | `string` | Type string for `bind_param` |
| `...$params` | `mixed` | Values to bind |

**Returns:** Array of associative arrays. Empty array `[]` on failure or no results.

**Example:**
```php
$members = dbFetchAll($base, "SELECT login FROM autre WHERE idalliance = ?", "i", $allianceId);
foreach ($members as $member) {
    echo $member['login'];
}
```

**Defined at:** `includes/database.php:44`

---

### dbExecute()

```php
function dbExecute($base, $sql, $types = "", ...$params): int|false
```

Execute a prepared INSERT, UPDATE, or DELETE statement and return the affected row count.

| Parameter | Type | Description |
|---|---|---|
| `$base` | `mysqli` | Database connection handle |
| `$sql` | `string` | SQL statement with `?` placeholders |
| `$types` | `string` | Type string for `bind_param` |
| `...$params` | `mixed` | Values to bind |

**Returns:** `int` (affected rows count) on success, `false` on failure.

**Example:**
```php
$affected = dbExecute($base, "UPDATE membre SET ip = ? WHERE login = ?", "ss", $ip, $login);
```

**Defined at:** `includes/database.php:58`

---

### dbLastId()

```php
function dbLastId($base): int|string
```

Get the last auto-increment ID inserted via the connection.

| Parameter | Type | Description |
|---|---|---|
| `$base` | `mysqli` | Database connection handle |

**Returns:** The last inserted auto-increment ID.

**Example:**
```php
dbExecute($base, "INSERT INTO news VALUES(default, ?, ?, ?)", "ssi", $title, $content, time());
$newId = dbLastId($base);
```

**Defined at:** `includes/database.php:80`

---

### dbCount()

```php
function dbCount($base, $sql, $types = "", ...$params): int
```

Execute a COUNT query and return the integer result. Uses `dbFetchOne()` internally
and returns the first column of the first row cast to `int`.

| Parameter | Type | Description |
|---|---|---|
| `$base` | `mysqli` | Database connection handle |
| `$sql` | `string` | SQL query returning a single count value |
| `$types` | `string` | Type string for `bind_param` |
| `...$params` | `mixed` | Values to bind |

**Returns:** `int` count value. Returns `0` if no result.

**Example:**
```php
$count = dbCount($base, "SELECT COUNT(*) AS nb FROM membre WHERE login = ?", "s", $login);
```

**Defined at:** `includes/database.php:95`

---

### dbEscapeLike()

```php
function dbEscapeLike($str): string
```

Escape special characters (`%` and `_`) in strings intended for LIKE clauses.
This is **not** a substitute for prepared statements -- it only handles LIKE wildcards.

| Parameter | Type | Description |
|---|---|---|
| `$str` | `string` | The string to escape for LIKE patterns |

**Returns:** Escaped string with `%` and `_` prefixed by `\`.

**Example:**
```php
$safeTerm = dbEscapeLike($searchTerm);
$results = dbFetchAll($base, "SELECT * FROM membre WHERE login LIKE ?", "s", "%{$safeTerm}%");
```

**Defined at:** `includes/database.php:87`

---

## 3. Security Layer

### 3.1 CSRF Protection

**File:** `includes/csrf.php`

CSRF protection uses a per-session token stored in `$_SESSION['csrf_token']`.
All POST forms must include the token, and POST handlers must verify it.

#### csrfToken()

```php
function csrfToken(): string
```

Generate or retrieve the current session CSRF token. Creates a 32-byte random
hex token via `random_bytes(32)` if one does not already exist in the session.

**Returns:** 64-character hex string.

**Defined at:** `includes/csrf.php:6`

---

#### csrfField()

```php
function csrfField(): string
```

Generate an HTML hidden input field containing the CSRF token, ready to embed in a form.

**Returns:** HTML string: `<input type="hidden" name="csrf_token" value="...">`

**Usage in forms:**
```php
echo '<form method="post">';
echo csrfField();
echo '<button type="submit">Submit</button></form>';
```

**Defined at:** `includes/csrf.php:13`

---

#### csrfVerify()

```php
function csrfVerify(): bool
```

Compare the submitted `$_POST['csrf_token']` against `$_SESSION['csrf_token']`
using `hash_equals()` (timing-safe comparison).

**Returns:** `true` if tokens match, `false` if either is missing or they differ.

**Defined at:** `includes/csrf.php:17`

---

#### csrfCheck()

```php
function csrfCheck(): void
```

Automatic guard for POST requests. If the request method is POST and `csrfVerify()`
fails, execution is terminated with `die()` and an error message in French.

Call this at the top of any page that handles POST data.

**Usage:**
```php
csrfCheck(); // dies if POST request has invalid/missing CSRF token
```

**Used in:** `constructions.php`, `attaquer.php`, `alliance.php`, `messages.php`,
`ecriremessage.php`, `deconnexion.php`, `tutoriel.php`, `moderation/index.php`

**Defined at:** `includes/csrf.php:24`

---

### 3.2 XSS Prevention

**File:** `includes/display.php`

#### antiXSS()

```php
function antiXSS($phrase, $specialTexte = false): string
```

Sanitize user input against XSS and SQL injection. Applies `htmlspecialchars()` with
`ENT_QUOTES` and `UTF-8`, then `addslashes()` (or not, for special text mode), then
`mysqli_real_escape_string()`.

| Parameter | Type | Description |
|---|---|---|
| `$phrase` | `string` | Input to sanitize |
| `$specialTexte` | `bool` | If `true`, skips `addslashes()` and `trim()` (for rich text) |

**Returns:** Sanitized string.

**Defined at:** `includes/display.php:327`

---

#### antihtml()

```php
function antihtml($phrase): string
```

Pure HTML entity encoding using `htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8')`.
Called internally by `antiXSS()`.

**Defined at:** `includes/display.php:322`

---

#### sanitizeOutput()

**File:** `includes/validation.php`

```php
function sanitizeOutput($str): string
```

Output encoding using `htmlspecialchars($str, ENT_QUOTES, 'UTF-8')`.

**Defined at:** `includes/validation.php:19`

---

### 3.3 Input Validation

**File:** `includes/validation.php`

#### validateLogin()

```php
function validateLogin($login): bool
```

Validates a login username. Must be 3-20 characters, alphanumeric plus underscore only.

**Pattern:** `/^[a-zA-Z0-9_]{3,20}$/`

**Defined at:** `includes/validation.php:2`

---

#### validateEmail()

```php
function validateEmail($email): bool
```

Validates an email address using PHP's `FILTER_VALIDATE_EMAIL`.

**Defined at:** `includes/validation.php:6`

---

#### validatePositiveInt()

```php
function validatePositiveInt($value): bool
```

Validates that a value is a positive integer (>= 1) using `FILTER_VALIDATE_INT`.

**Defined at:** `includes/validation.php:10`

---

#### validateRange()

```php
function validateRange($value, $min, $max): bool
```

Validates that a value is an integer within the specified inclusive range.

| Parameter | Type | Description |
|---|---|---|
| `$value` | `mixed` | Value to validate |
| `$min` | `int` | Minimum allowed value (inclusive) |
| `$max` | `int` | Maximum allowed value (inclusive) |

**Defined at:** `includes/validation.php:14`

---

### 3.4 Rate Limiting

**File:** `includes/rate_limiter.php`

File-based rate limiter using JSON files stored in `/tmp/tvlw_rates/`. Each
identifier+action pair gets its own file named with an MD5 hash. Timestamps
of attempts within the sliding window are tracked.

#### rateLimitCheck()

```php
function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds): bool
```

Check if an action is allowed and record the attempt if so.

| Parameter | Type | Description |
|---|---|---|
| `$identifier` | `string` | Unique identifier (e.g., IP address) |
| `$action` | `string` | Action name (e.g., `'login'`, `'register'`) |
| `$maxAttempts` | `int` | Maximum attempts allowed in the window |
| `$windowSeconds` | `int` | Sliding window duration in seconds |

**Returns:** `true` if allowed (attempt recorded), `false` if rate-limited (attempt NOT recorded).

**Current usage:**
- Login: 10 attempts per 300 seconds (5 min) per IP -- `includes/basicpublicphp.php:24`
- Registration: 3 attempts per 3600 seconds (1 hour) per IP -- `inscription.php:9`

**Defined at:** `includes/rate_limiter.php:9`

---

#### rateLimitRemaining()

```php
function rateLimitRemaining($identifier, $action, $maxAttempts, $windowSeconds): int
```

Query how many attempts remain before rate limiting kicks in, without recording a new attempt.

| Parameter | Type | Description |
|---|---|---|
| `$identifier` | `string` | Unique identifier |
| `$action` | `string` | Action name |
| `$maxAttempts` | `int` | Maximum attempts allowed |
| `$windowSeconds` | `int` | Sliding window duration in seconds |

**Returns:** Number of remaining attempts (minimum 0).

**Defined at:** `includes/rate_limiter.php:38`

---

### 3.5 Prepared Statements

All database queries throughout the codebase use the helper functions from
`includes/database.php` (see [Section 2](#2-database-helper-functions)), which
enforce prepared statements with parameterized queries. Direct string interpolation
in SQL is not used.

---

## 4. Session and Authentication

### 4.1 Session Configuration

**File:** `includes/basicprivatephp.php:2-8`

PHP native sessions are configured with hardened settings before `session_start()`:

```php
ini_set('session.cookie_httponly', 1);    // Prevent JavaScript access to session cookie
ini_set('session.cookie_secure', 0);      // Not enforcing HTTPS-only (HTTP still supported)
ini_set('session.use_strict_mode', 1);    // Reject uninitialized session IDs
session_start();
```

### 4.2 Login Flow (Public Pages)

**File:** `includes/basicpublicphp.php`

The login flow proceeds as follows:

1. **Session cleanup** (`basicpublicphp.php:7-18`): On public pages, any existing
   `$_SESSION['login']` and `$_SESSION['mdp']` are cleared. The session itself is
   NOT destroyed to preserve the CSRF token for form submissions.

2. **Form submission check** (`basicpublicphp.php:21`): Checks for `$_POST['loginConnexion']`
   and `$_POST['passConnexion']`.

3. **Rate limiting** (`basicpublicphp.php:24`): Calls `rateLimitCheck()` with the
   client IP, action `'login'`, max 10 attempts, 300-second window. If exceeded,
   execution is terminated with a French-language error message.

4. **Input sanitization** (`basicpublicphp.php:28`): Login input is normalized:
   `ucfirst(mb_strtolower(antiXSS($input)))`.

5. **User lookup** (`basicpublicphp.php:32`): Prepared statement fetches `login`
   and `pass_md5` from `membre` table.

6. **Guest cleanup** (`basicpublicphp.php:34-37`): Stale "Visiteur%" accounts
   (last connection > 3 hours ago) are deleted.

7. **Password verification** (`basicpublicphp.php:39-54`): Two-stage check:
   - **bcrypt first**: `password_verify($input, $storedHash)` -- handles modern hashes.
   - **Legacy MD5 fallback**: `md5($input) === $storedHash` -- handles old-format hashes.
     On successful MD5 match, the hash is **auto-upgraded** to bcrypt via
     `password_hash($input, PASSWORD_DEFAULT)` and the database is updated.

8. **Session creation** (`basicpublicphp.php:56-63`): On success:
   - `$_SESSION['login']` = normalized login name
   - `$_SESSION['mdp']` = stored password hash (bcrypt)
   - Player IP is updated in the database
   - `logInfo('AUTH', 'Login successful', ...)` is recorded
   - Redirect to `constructions.php`

9. **Failure handling** (`basicpublicphp.php:68-74`): Generic error message
   (does not reveal whether the username or password was wrong). Failed attempts
   are logged via `logWarn('AUTH', ...)`.

### 4.3 Page Authentication (Private Pages)

**File:** `includes/basicprivatephp.php`

Every private page includes `basicprivatephp.php` which performs authentication
on every request:

1. **Session check** (`basicprivatephp.php:16`): Verifies both `$_SESSION['login']`
   and `$_SESSION['mdp']` exist.

2. **Database hash comparison** (`basicprivatephp.php:17-18`): The login is
   normalized, then a prepared statement fetches `pass_md5` from the `membre` table.
   The stored hash is compared against `$_SESSION['mdp']`.

3. **Failure redirect** (`basicprivatephp.php:19-21`): If the session variables are
   missing or the hash does not match, the session is destroyed and the user is
   redirected to `index.php`.

4. **Session ID regeneration** (`basicprivatephp.php:26-29`): On first successful
   validation, `session_regenerate_id(true)` is called to prevent session fixation
   attacks. A `$_SESSION['_regenerated']` flag prevents repeated regeneration.

5. **Position initialization** (`basicprivatephp.php:38-42`): If the player's
   coordinates are at the default `-1000`, random map coordinates are assigned
   (new round placement).

6. **Online tracking** (`basicprivatephp.php:58-68`): The player's IP is upserted
   into the `connectes` table with the current timestamp. Entries older than 5
   minutes are purged (used for online player count).

7. **Vacation mode check** (`basicprivatephp.php:76-107`): Checks `vacance` status.
   If vacation has expired, the mode is automatically disabled.

8. **Resource update** (`basicprivatephp.php:79-88`): `updateRessources()` and
   `updateActions()` are called on each page load for non-vacation players.

9. **Round reset check** (`basicprivatephp.php:111-279`): Checks if a new month
   has started. If so, triggers maintenance mode and eventually a full game reset
   with victory point distribution, archival, and email notifications.

### 4.4 Authentication Flow Diagram

```
[Login Form POST]
    |
    v
[Rate Limit Check] --FAIL--> [Block: "Too many attempts"]
    |
    v (PASS)
[Sanitize Input]
    |
    v
[DB Lookup: membre.login] --NOT FOUND--> [Error + logWarn]
    |
    v (FOUND)
[password_verify(bcrypt)] --PASS--> [Create Session] --> [Redirect]
    |
    v (FAIL)
[md5() === stored] --PASS--> [Auto-upgrade to bcrypt] --> [Create Session] --> [Redirect]
    |
    v (FAIL)
[Error + logWarn]

[Private Page Load]
    |
    v
[Session login+mdp exist?] --NO--> [Destroy + Redirect index.php]
    |
    v (YES)
[DB: pass_md5 === session mdp?] --NO--> [Destroy + Redirect index.php]
    |
    v (YES)
[Regenerate session ID (once)]
    |
    v
[Update online tracking, resources, actions]
    |
    v
[Render page]
```

---

## 5. Logging System

**File:** `includes/logger.php`

File-based logging system that writes to daily log files in the `logs/` directory.

### Configuration Constants

| Constant | Value | Defined At |
|---|---|---|
| `LOG_DIR` | `__DIR__ . '/../logs'` | `includes/logger.php:7` |
| `LOG_LEVEL_DEBUG` | `0` | `includes/logger.php:8` |
| `LOG_LEVEL_INFO` | `1` | `includes/logger.php:9` |
| `LOG_LEVEL_WARN` | `2` | `includes/logger.php:10` |
| `LOG_LEVEL_ERROR` | `3` | `includes/logger.php:11` |
| `MIN_LOG_LEVEL` | `1` (INFO) | `includes/logger.php:14` |

In production, `MIN_LOG_LEVEL` is set to `LOG_LEVEL_INFO`, so `DEBUG` messages
are suppressed.

### Log Format

```
[YYYY-MM-DD HH:MM:SS] [LEVEL] [CATEGORY] [login@ip] message | {"context":"data"}
```

Example:
```
[2026-03-02 14:30:00] [INFO] [AUTH] [Player1@192.168.1.1] Login successful | {"login":"Player1"}
```

### Log Files

Files are stored as `logs/YYYY-MM-DD.log` (one file per day). The directory is
auto-created with permissions `0755` if it does not exist. Writes use `LOCK_EX`
for file locking.

### Functions

#### gameLog()

```php
function gameLog($level, $category, $message, $context = []): void
```

Core logging function. Writes a formatted log line if `$level >= MIN_LOG_LEVEL`.

| Parameter | Type | Description |
|---|---|---|
| `$level` | `int` | Log level constant (`LOG_LEVEL_*`) |
| `$category` | `string` | Category tag (e.g., `'COMBAT'`, `'AUTH'`) |
| `$message` | `string` | Human-readable log message |
| `$context` | `array` | Optional associative array, JSON-encoded in the log line |

**Defined at:** `includes/logger.php:16`

---

#### logInfo()

```php
function logInfo($category, $message, $context = []): void
```

Convenience wrapper: calls `gameLog(LOG_LEVEL_INFO, ...)`.

**Defined at:** `includes/logger.php:36`

---

#### logWarn()

```php
function logWarn($category, $message, $context = []): void
```

Convenience wrapper: calls `gameLog(LOG_LEVEL_WARN, ...)`.

**Defined at:** `includes/logger.php:40`

---

#### logError()

```php
function logError($category, $message, $context = []): void
```

Convenience wrapper: calls `gameLog(LOG_LEVEL_ERROR, ...)`.

**Defined at:** `includes/logger.php:44`

---

#### logDebug()

```php
function logDebug($category, $message, $context = []): void
```

Convenience wrapper: calls `gameLog(LOG_LEVEL_DEBUG, ...)`. Suppressed in production
by the default `MIN_LOG_LEVEL = 1` (INFO).

**Defined at:** `includes/logger.php:48`

---

### Log Categories In Use

| Category | Context | Source Files |
|---|---|---|
| `AUTH` | Login success/failure, admin auth | `includes/basicpublicphp.php`, `admin/index.php` |
| `COMBAT` | Combat resolution results | `includes/combat.php` |
| `ATTACK` | Attack launched | `attaquer.php` |
| `MARKET` | Market buy/sell transactions | `marche.php` |
| `REGISTER` | New player registration | `inscription.php` |
| `ALLIANCE` | Alliance creation/deletion | `alliance.php`, `allianceadmin.php` |
| `ACCOUNT` | Account deletion | `includes/player.php` |
| `ADMIN` | Admin panel access | `admin/index.php` |

---

## 6. Display Functions

**File:** `includes/display.php`

Formatting and rendering helpers for images, numbers, text, costs, and misc UI output.

### chiffrePetit() -- Compact Number Formatting

```php
function chiffrePetit($chiffre, $type = 1): string
```

Formats large numbers with SI suffixes for compact display. Wraps the result in a
`<span>` with the full number as a `title` attribute (for hover tooltip).

| Parameter | Type | Description |
|---|---|---|
| `$chiffre` | `int\|float` | Number to format |
| `$type` | `int` | `1` = HTML span with tooltip (default), other = plain text |

**Suffix table:**

| Suffix | Magnitude | Example |
|---|---|---|
| K | 10^3 (Thousand) | 1,500 -> "1.5K" |
| M | 10^6 (Million) | 2,300,000 -> "2.3M" |
| G | 10^9 (Billion) | 5,000,000,000 -> "5G" |
| T | 10^12 (Trillion) | |
| P | 10^15 (Peta) | |
| E | 10^18 (Exa) | |
| Z | 10^21 (Zetta) | |
| Y | 10^24 (Yotta) | |

Precision: values < 10 get 2 decimal places, < 100 get 1, >= 100 get none.

**Defined at:** `includes/display.php:70`

---

### affichageTemps() -- Time Display

```php
function affichageTemps($secondes, $petitTemps = false): string
```

Formats a duration in seconds into a human-readable time string.

| Parameter | Type | Description |
|---|---|---|
| `$secondes` | `int\|float` | Duration in seconds |
| `$petitTemps` | `bool` | If `true` and seconds <= 60, returns just `Xs` format |

**Output rules:**
- If `$petitTemps` and <= 60 seconds: `"45s"`
- If >= 48 hours (2 days): `"X.XX jours"` (days with 2 decimal places)
- Otherwise: `"H:MM:SS"` format

**Defined at:** `includes/display.php:124`

---

### couleurFormule() -- Molecule Formula Coloring

```php
function couleurFormule($formule): string
```

Takes a molecule formula string (with `<sub>` subscripts) and wraps each chemical
element symbol in a colored `<span>` matching the game's atom color scheme.

Uses the global arrays `$nomsRes`, `$lettre`, and `$couleurs` to map element letters
(C, N, H, O, Cl, S, Br, I) to their display colors (black, blue, gray, red, green,
orange, brown, pink).

**Defined at:** `includes/display.php:57`

---

### antiXSS() -- Input Sanitization

```php
function antiXSS($phrase, $specialTexte = false): string
```

See [Section 3.2](#32-xss-prevention) for full documentation.

**Defined at:** `includes/display.php:327`

---

### transformInt() -- SI Suffix to Integer Expansion

```php
function transformInt($nombre): string
```

Reverse of `chiffrePetit()`. Converts SI-suffixed number strings back into full
integer strings by replacing suffix letters with the corresponding zeros.

| Suffix | Replacement |
|---|---|
| K/k | 000 |
| M/m | 000000 |
| G/g | 000000000 |
| T/t | 000000000000 |
| P/p | 000000000000000 |
| E/e | 000000000000000000 |
| Z/z | 000000000000000000000 |
| Y/y | 000000000000000000000000 |

**Example:** `"5K"` becomes `"5000"`, `"2.3M"` becomes `"2.3000000"` (note: expects integer input).

**Defined at:** `includes/display.php:344`

---

### Other Display Functions

| Function | Signature | Description | Defined At |
|---|---|---|---|
| `image($num)` | `image(int $num): string` | Returns an `<img>` tag for atom type by index (0-7) | `display.php:7` |
| `imageEnergie($imageAide)` | `imageEnergie(bool $imageAide = false): string` | Returns energy icon `<img>` tag | `display.php:14` |
| `imagePoints()` | `imagePoints(): string` | Returns points icon `<img>` tag | `display.php:24` |
| `imageLabel($image, $label, $lien)` | `imageLabel(string, string, string\|false): string` | Image with label below, optional link | `display.php:29` |
| `separerZeros($nombre)` | `separerZeros(int $nombre): string` | Format number with space thousands separator | `display.php:41` |
| `couleur($chiffre)` | `couleur(int $chiffre): string` | Red if negative, green with + if positive | `display.php:46` |
| `nombreMolecules($nombre)` | `nombreMolecules(int $nombre): string` | Molecule count chip display | `display.php:168` |
| `nombrePoints($nombre)` | `nombrePoints(int $nombre): string` | Points count chip display | `display.php:173` |
| `nombreAtome($num, $nombre)` | `nombreAtome(int $num, int $nombre): string` | Atom count chip with icon | `display.php:178` |
| `nombreNeutrino($nombre)` | `nombreNeutrino(int $nombre): string` | Neutrino count chip display | `display.php:183` |
| `nombreEnergie($nombre, $id)` | `nombreEnergie(int $nombre, string\|false $id): string` | Energy count chip display | `display.php:188` |
| `nombreTemps($nombre)` | `nombreTemps(string $nombre): string` | Time display chip with hourglass | `display.php:193` |
| `coutEnergie($cout)` | `coutEnergie(int $cout): string` | Energy cost chip (green if affordable, red if not) | `display.php:207` |
| `coutAtome($num, $cout)` | `coutAtome(int $num, int $cout): string` | Atom cost chip (green/red affordability) | `display.php:218` |
| `coutTout($cout)` | `coutTout(int $cout): string` | "All resources" cost chip (green/red) | `display.php:230` |
| `pref($ressource)` | `pref(string $ressource): string` | French prefix: returns "d'" or "de " | `display.php:255` |
| `rangForum($joueur)` | `rangForum(string $joueur): array` | Forum rank with color and tier name | `display.php:264` |

---

## 7. UI Component Functions

**File:** `includes/ui_components.php`

Framework7-style UI component rendering functions. These output HTML directly
via `echo` unless a return mode is specified.

### Card Components

#### debutCarte()

```php
function debutCarte($titre = false, $style = "", $image = false, $overflow = false): void
```

Opens a Framework7 card structure. Echoes opening HTML tags.

| Parameter | Type | Description |
|---|---|---|
| `$titre` | `string\|false` | Card header title text |
| `$style` | `string` | Additional CSS styles for the header |
| `$image` | `string\|false` | Background image URL for header |
| `$overflow` | `string\|false` | If set, used as element `id` with scroll overflow enabled |

**Defined at:** `includes/ui_components.php:7`

---

#### finCarte()

```php
function finCarte($footer = false): void
```

Closes a card opened by `debutCarte()`. Optionally adds a card footer.

| Parameter | Type | Description |
|---|---|---|
| `$footer` | `string\|false` | Optional footer HTML content |

**Defined at:** `includes/ui_components.php:38`

---

### List Components

#### debutListe()

```php
function debutListe($retour = false): string|void
```

Opens a Framework7 media list block.

| Parameter | Type | Description |
|---|---|---|
| `$retour` | `bool` | If `true`, returns HTML string instead of echoing |

**Defined at:** `includes/ui_components.php:55`

---

#### finListe()

```php
function finListe($retour = false): string|void
```

Closes a list opened by `debutListe()`.

| Parameter | Type | Description |
|---|---|---|
| `$retour` | `bool` | If `true`, returns HTML string instead of echoing |

**Defined at:** `includes/ui_components.php:67`

---

### Content Blocks

#### debutContent() / finContent()

```php
function debutContent($inner = false, $return = false): string|void
function finContent($inner = false, $return = false): string|void
```

Open/close a Framework7 content block. Optionally wraps in a `content-block-inner` div.

| Parameter | Type | Description |
|---|---|---|
| `$inner` | `bool` | Wrap content in `content-block-inner` div |
| `$return` | `bool` | Return HTML string instead of echoing |

**Defined at:** `includes/ui_components.php:79` and `includes/ui_components.php:96`

---

### item() -- List Item Builder

```php
function item($options): string|void
```

Highly configurable list item builder. Generates a Framework7 list item from an
associative array of options.

**Options array keys:**

| Key | Type | Description |
|---|---|---|
| `titre` | `string` | Item title text |
| `soustitre` | `string` | Item subtitle (triggers title-row layout) |
| `media` | `string` | Left-side media (icon/image HTML) |
| `after` | `string` | Right-side after text |
| `input` | `string` | Input field HTML |
| `link` | `string` | Makes item a clickable link |
| `ajax` | `bool` | If true with `link`, adds Framework7 ajax class |
| `form` | `array` | `[action_url, form_name]` wraps item in a form |
| `select` | `array` | Smart select dropdown configuration |
| `accordion` | `string` | Accordion content (makes item expandable) |
| `autocomplete` | `string` | Autocomplete opener ID |
| `floating` | `bool` | Floating label style |
| `disabled` | `bool` | Disabled state |
| `style` | `string` | CSS style for "after" div |
| `noList` | `bool` | Omit `<li>` wrapper tags |
| `retour` | `bool` | Return HTML string instead of echoing |

**Defined at:** `includes/ui_components.php:129`

---

### accordion() -- Standalone Accordion Item

```php
function accordion($options): void
```

Renders an individual accordion item (outside of a list context).

| Key | Type | Description |
|---|---|---|
| `media` | `string` | Icon/image HTML |
| `titre` | `string` | Accordion header title |
| `contenu` | `string` | Expandable content |

**Defined at:** `includes/ui_components.php:362`

---

### itemAccordion() -- List Accordion Item

```php
function itemAccordion($titre = false, $media = false, $contenu = false, $id = false): void
```

Renders an accordion item as a list element with positional parameters.

**Defined at:** `includes/ui_components.php:324`

---

### chip() -- Badge/Tag Component

```php
function chip($label, $image, $couleurImage = "black", $couleur = "", $circle = false, $id = false): string
```

Renders a Framework7 chip (badge/tag) with an image/icon and a text label.

| Parameter | Type | Description |
|---|---|---|
| `$label` | `string` | Display text |
| `$image` | `string` | HTML for the chip media (icon/image) |
| `$couleurImage` | `string` | CSS background class for the media circle |
| `$couleur` | `string` | CSS background class for the chip body |
| `$circle` | `bool` | If true, adds a border to the media circle |
| `$id` | `string\|false` | Optional HTML `id` for the label div |

**Returns:** HTML string (always returns, never echoes).

**Defined at:** `includes/ui_components.php:450`

---

### progressBar()

```php
function progressBar($vie, $vieMax, $couleur): string
```

Renders a Framework7 progress bar with current/max values displayed below.

| Parameter | Type | Description |
|---|---|---|
| `$vie` | `int` | Current value |
| `$vieMax` | `int` | Maximum value |
| `$couleur` | `string` | Framework7 color name (e.g., `"green"`, `"red"`) |

**Returns:** HTML string.

**Defined at:** `includes/ui_components.php:474`

---

### submit() -- Action Button

```php
function submit($options): string
```

Renders a styled action button (Framework7 raised/filled button).

| Key | Type | Description |
|---|---|---|
| `titre` | `string` | Button text |
| `form` | `string` | Form name to submit via JavaScript |
| `link` | `string` | Direct URL (overrides `form`) |
| `style` | `string` | Additional CSS |
| `id` | `string` | HTML element ID |
| `classe` | `string` | CSS classes (default: `"button-raised button-fill"`) |
| `image` | `string` | Icon image URL (displayed on left and right of text) |
| `simple` | `bool` | If true with `image`, only shows left icon |
| `nom` | `string` | Hidden input name to include |

**Returns:** HTML string.

**Defined at:** `includes/ui_components.php:520`

---

### aide() -- Help Icon

```php
function aide($page, $noir = false): string
```

Returns a help icon that opens a popover with the corresponding help content.

| Parameter | Type | Description |
|---|---|---|
| `$page` | `string` | Help page identifier (becomes `popover-{$page}`) |
| `$noir` | `bool` | If true, uses dark question mark icon; otherwise white help icon |

**Defined at:** `includes/ui_components.php:582`

---

### popover()

```php
function popover($nom, $image): string
```

Returns an anchor tag that triggers a Framework7 popover.

| Parameter | Type | Description |
|---|---|---|
| `$nom` | `string` | Popover CSS class selector |
| `$image` | `string` | Icon image path |

**Defined at:** `includes/ui_components.php:591`

---

### Other UI Functions

| Function | Description | Defined At |
|---|---|---|
| `debutAccordion()` / `finAccordion()` | Open/close an accordion list wrapper | `ui_components.php:115` / `ui_components.php:122` |
| `checkbox($liste)` | Render a list of checkbox items from array config | `ui_components.php:404` |
| `chipInfo($label, $image, $id)` | Chip with a small image icon (25x25) | `ui_components.php:469` |
| `slider($options)` | Range slider input with min/max/step/color | `ui_components.php:486` |
| `important($contenu)` | Highlighted important text with `<hr>` | `ui_components.php:577` |
| `carteForum(...)` | Forum post card with avatar, login, date, content, grade | `ui_components.php:596` |
| `imageClassement($rang)` | Ranking medal image (gold/silver/bronze or number) | `ui_components.php:618` |

---

## 8. Complete Constants Reference

**File:** `includes/config.php`

All `define()` constants and global configuration arrays, grouped by category.

### Time Constants

| Constant | Value | Description | Defined At |
|---|---|---|---|
| `SECONDS_PER_HOUR` | `3600` | Seconds in one hour | `config.php:14` |
| `SECONDS_PER_DAY` | `86400` | Seconds in one day | `config.php:15` |
| `SECONDS_PER_WEEK` | `604800` | Seconds in one week | `config.php:16` |
| `SECONDS_PER_MONTH` | `2678400` | Seconds in 31 days; used for active player threshold | `config.php:17` |

### Game Limits

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `MAX_CONCURRENT_CONSTRUCTIONS` | `2` | Maximum simultaneous building constructions | `config.php:22` | `constructions.php` |
| `MAX_MOLECULE_CLASSES` | `4` | Maximum molecule classes per player | `config.php:23` | Molecule creation |
| `MAX_ATOMS_PER_ELEMENT` | `200` | Maximum atoms of one type in a molecule | `config.php:24` | Molecule design |
| `MAX_ALLIANCE_MEMBERS` | `20` | Maximum players per alliance (was `$joueursEquipe`) | `config.php:25` | `alliance.php` |
| `BEGINNER_PROTECTION_SECONDS` | `432000` | 5 days of attack immunity for new players | `config.php:26` | `attaquer.php` |
| `NEW_PLAYER_BOOST_DURATION` | `259200` | 3 days of production boost for new players | `config.php:27` | Resource production |
| `NEW_PLAYER_BOOST_MULTIPLIER` | `2` | 2x production multiplier during boost period | `config.php:28` | Resource production |
| `ABSENCE_REPORT_THRESHOLD_HOURS` | `6` | Hours offline before loss report is generated | `config.php:29` | Combat reports |
| `ONLINE_TIMEOUT_SECONDS` | `300` | 5 minutes; threshold for "online" status | `config.php:30` | `basicprivatephp.php` |
| `VICTORY_POINTS_TOTAL` | `1000` | Total victory points pool (was `$nbPointsVictoire`) | `config.php:31` | Round end |

### Resource Production

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `BASE_ENERGY_PER_LEVEL` | `75` | Energy per generateur level (`revenuEnergie = 75 * level`) | `config.php:48` | Resource update |
| `BASE_ATOMS_PER_POINT` | `60` | Atoms per producteur point per level | `config.php:51` | Resource update |
| `BASE_STORAGE_PER_LEVEL` | `500` | Storage capacity per depot level | `config.php:54` | Resource caps |
| `PRODUCTEUR_DRAIN_PER_LEVEL` | `8` | Energy drained per producteur level | `config.php:57` | Resource update |

### Molecule Stat Formulas -- Attack

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `ATTACK_ATOM_COEFFICIENT` | `0.1` | Oxygen atom coefficient in attack formula | `config.php:65` | `fonctions.php` (attaque) |
| `ATTACK_LEVEL_DIVISOR` | `50` | Level divisor in attack formula | `config.php:66` | `fonctions.php` (attaque) |

### Molecule Stat Formulas -- Defense

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `DEFENSE_ATOM_COEFFICIENT` | `0.1` | Carbon atom coefficient in defense formula | `config.php:69` | `fonctions.php` (defense) |
| `DEFENSE_LEVEL_DIVISOR` | `50` | Level divisor in defense formula | `config.php:70` | `fonctions.php` (defense) |

### Molecule Stat Formulas -- HP

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `HP_ATOM_COEFFICIENT` | `0.1` | Bromine atom coefficient in HP formula | `config.php:73` | `fonctions.php` (pointsDeVieMolecule) |
| `HP_LEVEL_DIVISOR` | `50` | Level divisor in HP formula | `config.php:74` | `fonctions.php` (pointsDeVieMolecule) |

### Molecule Stat Formulas -- Destruction

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `DESTRUCTION_ATOM_COEFFICIENT` | `0.075` | Hydrogen atom coefficient in destruction formula | `config.php:77` | `fonctions.php` (potentielDestruction) |
| `DESTRUCTION_LEVEL_DIVISOR` | `50` | Level divisor in destruction formula | `config.php:78` | `fonctions.php` (potentielDestruction) |

### Molecule Stat Formulas -- Pillage

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `PILLAGE_ATOM_COEFFICIENT` | `0.1` | Sulfur atom coefficient in pillage formula | `config.php:81` | `fonctions.php` (pillage) |
| `PILLAGE_SOUFRE_DIVISOR` | `3` | Sulfur divisor in pillage formula (soufre/3) | `config.php:82` | `fonctions.php` (pillage) |
| `PILLAGE_LEVEL_DIVISOR` | `50` | Level divisor in pillage formula | `config.php:83` | `fonctions.php` (pillage) |

### Molecule Stat Formulas -- Iode Energy Production

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `IODE_ENERGY_COEFFICIENT` | `0.05` | Iodine energy coefficient (buffed 5x from 0.01) | `config.php:87` | `fonctions.php` (productionEnergieMolecule) |
| `IODE_LEVEL_DIVISOR` | `50` | Level divisor in iodine energy formula | `config.php:88` | `fonctions.php` (productionEnergieMolecule) |

### Molecule Stat Formulas -- Speed

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `SPEED_ATOM_COEFFICIENT` | `0.5` | Chlorine atom coefficient in speed formula | `config.php:91` | `fonctions.php` (vitesse) |
| `SPEED_LEVEL_DIVISOR` | `50` | Level divisor in speed formula | `config.php:92` | `fonctions.php` (vitesse) |

### Molecule Stat Formulas -- Formation Time

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `FORMATION_AZOTE_COEFFICIENT` | `0.09` | Nitrogen coefficient in formation time formula | `config.php:95` | `fonctions.php` (tempsFormation) |
| `FORMATION_AZOTE_EXPONENT` | `1.09` | Nitrogen exponent in formation time formula | `config.php:96` | `fonctions.php` (tempsFormation) |
| `FORMATION_LEVEL_DIVISOR` | `20` | Level divisor in formation time formula | `config.php:97` | `fonctions.php` (tempsFormation) |

### Molecule Decay / Disappearance

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `DECAY_BASE` | `0.99` | Base decay coefficient | `config.php:103` | Decay calculation |
| `DECAY_ATOM_DIVISOR` | `100` | Atom count divisor in decay formula | `config.php:104` | Decay calculation |
| `DECAY_POWER_DIVISOR` | `25000` | Power divisor in decay exponent | `config.php:105` | Decay calculation |
| `STABILISATEUR_BONUS_PER_LEVEL` | `0.01` | 1% decay reduction per stabilisateur level | `config.php:106` | Decay calculation |

### Building HP Formulas

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `BUILDING_HP_BASE` | `20` | Base HP multiplier for standard buildings | `config.php:112` | Building HP calculation |
| `BUILDING_HP_GROWTH_BASE` | `1.2` | Exponential growth base for building HP | `config.php:113` | Building HP calculation |
| `BUILDING_HP_LEVEL_EXP` | `1.2` | Level exponent for building HP | `config.php:114` | Building HP calculation |
| `FORCEFIELD_HP_BASE` | `50` | Base HP multiplier for champ de force | `config.php:117` | Force field HP calculation |
| `FORCEFIELD_HP_GROWTH_BASE` | `1.2` | Exponential growth base for force field HP | `config.php:118` | Force field HP calculation |
| `FORCEFIELD_HP_LEVEL_EXP` | `1.2` | Level exponent for force field HP | `config.php:119` | Force field HP calculation |

### Combat

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `ATTACK_ENERGY_COST_FACTOR` | `0.15` | Energy cost per atom per molecule sent to attack | `config.php:226` | `attaquer.php` |
| `IONISATEUR_COMBAT_BONUS_PER_LEVEL` | `2` | +2% attack bonus per ionisateur level | `config.php:229` | `includes/combat.php` |
| `CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL` | `2` | +2% defense bonus per champ de force level | `config.php:230` | `includes/combat.php` |
| `DUPLICATEUR_COMBAT_COEFFICIENT` | `1.0` | Duplicateur combat bonus scaling (fixed from 0.1) | `config.php:234` | `includes/combat.php` |
| `NUM_DAMAGEABLE_BUILDINGS` | `4` | Number of building types that can take damage | `config.php:237` | `includes/combat.php` |
| `COMBAT_POINTS_BASE` | `1` | Minimum combat points per battle | `config.php:241` | `includes/combat.php` |
| `COMBAT_POINTS_CASUALTY_SCALE` | `0.5` | Casualty-based scaling factor for combat points | `config.php:242` | `includes/combat.php` |
| `COMBAT_POINTS_MAX_PER_BATTLE` | `20` | Maximum combat points per single battle | `config.php:243` | `includes/combat.php` |
| `ATTACK_POINTS_MULTIPLIER` | `3.0` | Multiplier for attack points -> total points | `config.php:248` | Rankings |
| `DEFENSE_POINTS_MULTIPLIER` | `3.0` | Multiplier for defense points -> total points | `config.php:249` | Rankings |

### Espionage

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `ESPIONAGE_SPEED` | `20` | Spy movement speed (cases per hour) | `config.php:254` | Espionage system |
| `ESPIONAGE_SUCCESS_RATIO` | `0.5` | Must have > 50% of defender's neutrinos to succeed | `config.php:255` | Espionage system |

### Neutrinos

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `NEUTRINO_COST` | `50` | Energy cost per neutrino | `config.php:260` | Neutrino purchase |

### Market

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `MARKET_VOLATILITY_FACTOR` | `0.5` | Base volatility factor (divided by active players) | `config.php:265` | `marche.php` |
| `MARKET_PRICE_FLOOR` | `0.1` | Minimum resource price | `config.php:266` | `marche.php` |
| `MARKET_PRICE_CEILING` | `10.0` | Maximum resource price | `config.php:267` | `marche.php` |
| `MARKET_MEAN_REVERSION` | `0.01` | 1% pull toward baseline price per trade | `config.php:268` | `marche.php` |
| `MERCHANT_SPEED` | `20` | Merchant travel speed (cases per hour) | `config.php:269` | `marche.php` |
| `MARKET_POINTS_SCALE` | `0.05` | Sqrt scaling for trade volume points | `config.php:273` | Rankings |
| `MARKET_POINTS_MAX` | `40` | Cap on market points contribution | `config.php:274` | Rankings |

### Alliance / Duplicateur

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `DUPLICATEUR_BASE_COST` | `10` | Base cost for duplicateur upgrade | `config.php:280` | `alliance.php` |
| `DUPLICATEUR_COST_FACTOR` | `2.5` | Cost growth factor per level | `config.php:281` | `alliance.php` |
| `DUPLICATEUR_BONUS_PER_LEVEL` | `0.01` | 1% resource production bonus per level | `config.php:284` | Resource production |
| `ALLIANCE_TAG_MIN_LENGTH` | `3` | Minimum alliance tag length | `config.php:287` | `alliance.php` |
| `ALLIANCE_TAG_MAX_LENGTH` | `16` | Maximum alliance tag length | `config.php:288` | `alliance.php` |

### Molecule Class Costs

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `CLASS_COST_EXPONENT` | `4` | Cost exponent for unlocking classes (reduced from 6) | `config.php:294` | Molecule class creation |
| `CLASS_COST_OFFSET` | `1` | Offset added to class number: `pow(n + 1, 4)` | `config.php:295` | Molecule class creation |

### Pillage Points

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `PILLAGE_POINTS_DIVISOR` | `100000` | Divisor in tanh pillage points formula | `config.php:339` | Rankings |
| `PILLAGE_POINTS_MULTIPLIER` | `50` | Multiplier in tanh pillage points formula | `config.php:340` | Rankings |

### Victory Points -- Player Rankings

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `VP_PLAYER_RANK1` | `100` | Points for 1st place player | `config.php:311` | Round end |
| `VP_PLAYER_RANK2` | `80` | Points for 2nd place player | `config.php:312` | Round end |
| `VP_PLAYER_RANK3` | `70` | Points for 3rd place player | `config.php:313` | Round end |
| `VP_PLAYER_RANK4_10_BASE` | `70` | Base for ranks 4-10: `70 - (rank - 3) * 5` | `config.php:314` | Round end |
| `VP_PLAYER_RANK4_10_STEP` | `5` | Step decrease per rank for 4-10 | `config.php:315` | Round end |
| `VP_PLAYER_RANK11_20_BASE` | `35` | Base for ranks 11-20: `35 - (rank - 10) * 2` | `config.php:316` | Round end |
| `VP_PLAYER_RANK11_20_STEP` | `2` | Step decrease per rank for 11-20 | `config.php:317` | Round end |
| `VP_PLAYER_RANK21_50_BASE` | `15` | Base for ranks 21-50: `15 - (rank - 20) * 0.5` | `config.php:318` | Round end |
| `VP_PLAYER_RANK21_50_STEP` | `0.5` | Step decrease per rank for 21-50 | `config.php:319` | Round end |

### Victory Points -- Alliance Rankings

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `VP_ALLIANCE_RANK1` | `15` | Points for 1st place alliance | `config.php:331` | Round end |
| `VP_ALLIANCE_RANK2` | `10` | Points for 2nd place alliance | `config.php:332` | Round end |
| `VP_ALLIANCE_RANK3` | `7` | Points for 3rd place alliance | `config.php:333` | Round end |

### Lieur Bonus

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `LIEUR_GROWTH_BASE` | `1.07` | Lieur formation speed bonus: `floor(100 * pow(1.07, level)) / 100` | `config.php:407` | `fonctions.php` |

### Active Player

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `ACTIVE_PLAYER_THRESHOLD` | `2678400` | Equals `SECONDS_PER_MONTH` (31 days); player is "active" if last connection within this window | `config.php:413` | Market, rankings |

### Registration

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `REGISTRATION_RANDOM_MAX` | `200` | Maximum random value for element distribution | `config.php:400` | `inscription.php` |

### Rate Limiter

| Constant | Value | Description | Defined At | Used In |
|---|---|---|---|---|
| `RATE_LIMIT_DIR` | `/tmp/tvlw_rates` | Directory for rate limit data files | `rate_limiter.php:7` | `rate_limiter.php` |

### Global Configuration Arrays

These are PHP arrays (not `define()` constants) declared in `includes/config.php`:

| Variable | Type | Description | Defined At |
|---|---|---|---|
| `$RESOURCE_NAMES` | `array(8)` | Atom type names: carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode | `config.php:38` |
| `$RESOURCE_NAMES_ACCENTED` | `array(8)` | Accented display names (currently same as above) | `config.php:39` |
| `$RESOURCE_COLORS` | `array(8)` | Hex/named colors for each atom type | `config.php:40` |
| `$RESOURCE_COLORS_SIMPLE` | `array(8)` | Simple color names for each atom type | `config.php:41` |
| `$RESOURCE_LETTERS` | `array(8)` | Chemical symbols: C, N, H, O, Cl, S, Br, I | `config.php:42` |
| `$BUILDING_CONFIG` | `array` | Complete building configuration (costs, times, bonuses) for all 8 building types | `config.php:126-220` |
| `$VICTORY_POINTS_PLAYER` | `array` | Victory point awards by player rank | `config.php:301-309` |
| `$VICTORY_POINTS_ALLIANCE` | `array` | Victory point awards by alliance rank | `config.php:324-330` |
| `$MEDAL_TIER_NAMES` | `array(8)` | Medal tier names: Bronze through Diamant Rouge | `config.php:346` |
| `$MEDAL_TIER_IMAGES` | `array(8)` | Image filenames for each medal tier | `config.php:347-350` |
| `$MEDAL_BONUSES` | `array(8)` | Bonus percentages per tier: 1, 3, 6, 10, 15, 20, 30, 50 | `config.php:353` |
| `$MEDAL_FORUM_BADGES` | `array(8)` | Forum badge names per tier | `config.php:356-359` |
| `$MEDAL_THRESHOLDS_TERREUR` | `array(8)` | Attacks launched: 5, 15, 30, 60, 120, 250, 500, 1000 | `config.php:365` |
| `$MEDAL_THRESHOLDS_ATTAQUE` | `array(8)` | Attack points: 100 to 10,000,000 | `config.php:368` |
| `$MEDAL_THRESHOLDS_DEFENSE` | `array(8)` | Defense points: 100 to 10,000,000 | `config.php:371` |
| `$MEDAL_THRESHOLDS_PILLAGE` | `array(8)` | Resources pillaged: 1,000 to 100,000,000 | `config.php:374` |
| `$MEDAL_THRESHOLDS_PIPELETTE` | `array(8)` | Forum posts: 10 to 5,000 | `config.php:377` |
| `$MEDAL_THRESHOLDS_PERTES` | `array(8)` | Molecules lost: 10 to 1,000,000 | `config.php:380` |
| `$MEDAL_THRESHOLDS_ENERGIEVORE` | `array(8)` | Energy spent: 100 to 1,000,000,000 | `config.php:383` |
| `$MEDAL_THRESHOLDS_CONSTRUCTEUR` | `array(8)` | Highest building level: 5 to 100 | `config.php:386` |
| `$MEDAL_THRESHOLDS_BOMBE` | `array(8)` | Buildings destroyed: 1 to 12 | `config.php:389` |
| `$MEDAL_THRESHOLDS_TROLL` | `array(8)` | Troll score: 0 to 7 | `config.php:392` |
| `$REGISTRATION_ELEMENT_THRESHOLDS` | `array(8)` | Random element distribution cutoffs (out of 200) | `config.php:401` |

### Building Configuration Detail ($BUILDING_CONFIG)

Each building type in `$BUILDING_CONFIG` has the following structure (keys vary per building):

| Building | Energy Cost Base | Energy Cost Exp | Atom Cost Base | Atom Cost Exp | Time Base (s) | Time Exp | Special |
|---|---|---|---|---|---|---|---|
| `generateur` | 50 | 0.7 | 75 (all atoms) | 0.7 | 60 (L1: 10s) | 1.5 | Generates energy |
| `producteur` | 75 | 0.7 | 50 (all atoms) | 0.7 | 40 (L1: 10s) | 1.5 | Produces atoms, drains energy |
| `depot` | 100 | 0.7 | 0 | 0 | 80 | 1.5 | Storage only, no atom cost |
| `champdeforce` | -- | -- | 100 (carbone) | 0.7 | 20 | 1.7 | +2% defense/level, offset +2 |
| `ionisateur` | -- | -- | 100 (oxygene) | 0.7 | 20 | 1.7 | +2% attack/level, offset +2 |
| `condenseur` | 25 | 0.8 | 100 (all atoms) | 0.8 | 120 | 1.6 | 5 points/level, offset +1 |
| `lieur` | -- | -- | 100 (azote) | 0.8 | 100 | 1.5 | Growth base 1.07, offset +1 |
| `stabilisateur` | -- | -- | 75 (all atoms) | 0.9 | 120 | 1.5 | 0.5% decay reduction/level, offset +1 |

**Defined at:** `includes/config.php:126-220`
