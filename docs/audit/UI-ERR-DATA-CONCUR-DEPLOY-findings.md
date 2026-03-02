# Combined Audit Report: UI, Error Handling, Data Integrity, Concurrency, Deployment

**Project:** The Very Little War
**Date:** 2026-03-02
**Auditor:** Claude Opus 4.6
**Scope:** 5 domains across ~80 PHP files, includes/, deployment config

---

## Summary

| Domain        | Critical | High | Medium | Low | Total |
|---------------|----------|------|--------|-----|-------|
| UI            | 2        | 4    | 5      | 4   | 15    |
| Error Handling| 1        | 3    | 3      | 2   | 9     |
| Data Integrity| 2        | 4    | 3      | 2   | 11    |
| Concurrency   | 3        | 3    | 2      | 1   | 9     |
| Deployment    | 1        | 2    | 3      | 2   | 8     |
| **Total**     | **9**    | **16** | **16** | **11** | **52** |

---

## 1. UI DOMAIN FINDINGS

### FINDING-UI-001 [CRITICAL] XSS in ui_components.php -- All parameters echoed raw

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php`
**Lines:** 11, 17, 25, 152-178, 239, 253, 271, 463-466, 574, 596-616

The `debutCarte()`, `item()`, `chip()`, `submit()`, `carteForum()`, and nearly every function in this file echoes parameters directly into HTML without escaping. While many callers pass server-side data, any path where user input flows into these functions creates XSS.

Specific high-risk examples:
- `debutCarte($titre)` -- `$titre` echoed directly into `<div class="card-header">` (line 17)
- `item($options)` -- `$options["titre"]`, `$options["after"]`, `$options["media"]`, `$options["input"]` all unescaped
- `chip($label, $image, ...)` -- `$label` goes straight into `<div class="chip-label">`
- `carteForum($login, $contenu, ...)` -- `$contenu` is the BBCode-processed forum text; if BBCode fails to sanitize, XSS flows through
- `submit($options)` -- `$options["link"]` goes into `href` attribute unescaped

**Impact:** Stored XSS possible through forum posts, player descriptions, or alliance names if any bypass exists in BBCode sanitization.

**Recommendation:** Apply `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` to all text parameters in these wrapper functions, or create a separate `safeHtml()` wrapper that is the only path for rendering user-controlled text.

---

### FINDING-UI-002 [CRITICAL] Scripts loaded after `</html>` tag

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php`
**Lines:** 17-29

```php
</body>
</html>

  <script type="text/javascript" src="cordova.js"></script>
  <script type="text/javascript" src="js/notification.js"></script>
  <script type="text/javascript" src="js/PushNotification.js"></script>
  <script type="text/javascript" src="js/framework7.min.js"></script>
  <script type="text/javascript" src="js/jquery-3.1.1.min.js"></script>
  <script type="text/javascript" src="js/loader.js"></script>
  <script type="text/javascript" src="js/aes.js"></script>
  <script type="text/javascript" src="js/aes-json-format.js"></script>
```

All JavaScript (Framework7, jQuery, AES encryption, app initialization) is loaded AFTER `</html>`. This is invalid HTML. Browsers handle this gracefully but it violates the HTML spec and causes race conditions. The Framework7 initialization code on line 33 (`var myApp = new Framework7(...)`) runs after the closing tags.

**Impact:** Potential rendering issues, broken behavior on strict parsers, SEO issues with validators, and unpredictable load order. CSP policies may not apply to scripts outside `<html>`.

**Recommendation:** Move all `<script>` tags to just before `</body>`, reorganize copyright.php to close `</body></html>` last.

---

### FINDING-UI-003 [HIGH] Mixed HTTP/HTTPS resources in partenariat.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/partenariat.php`
**Lines:** 8-11

```html
<script src="http://www.theverylittlewar.com/images/partenariat/jquery.js" type="text/javascript"></script>
<script type="text/javascript" src="http://www.theverylittlewar.com/images/partenariat/charger_barre.js"></script>
<script type="application/javascript" src="http://www.theverylittlewar.com/images/partenariat/news.json"></script>
<link href="http://www.theverylittlewar.com/images/partenariat/style_barre.css" rel="stylesheet">
```

All four external resources use `http://` (plaintext). Once HTTPS is enabled, these will be blocked as mixed content by modern browsers. Additionally, this loads a second copy of jQuery separate from the one in `js/jquery-3.1.1.min.js`.

**Impact:** Broken functionality when HTTPS is enabled; double jQuery loading; potential MITM injection of malicious JS via HTTP resources.

**Recommendation:** Either remove partenariat.php entirely (it appears to be an unused partnership banner system) or switch all URLs to `https://` or protocol-relative `//`.

---

### FINDING-UI-004 [HIGH] Raw SQL query() used with session variable interpolation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php`
**Line:** 52

```php
$ex = query('SELECT login FROM membre WHERE login!=\''.$_SESSION['login'].'\'');
```

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/atomes.php`
**Line:** 5

```php
$ex = query('SELECT nombre FROM molecules WHERE proprietaire=\''.$_SESSION['login'].'\'');
```

These use the legacy `query()` function with string concatenation instead of prepared statements. While `$_SESSION['login']` is not directly user-controlled at this point, it was originally set from user input. The `query()` function in `db_helpers.php` calls raw `mysqli_query()`.

**Impact:** SQL injection if session data is ever manipulated. Inconsistent with the project's migration to prepared statements.

**Recommendation:** Replace with `dbQuery($base, 'SELECT login FROM membre WHERE login!=?', 's', $_SESSION['login'])` and `dbQuery($base, 'SELECT nombre FROM molecules WHERE proprietaire=?', 's', $_SESSION['login'])`.

---

### FINDING-UI-005 [HIGH] Duplicate JavaScript code -- name generator duplicated entirely

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/mots.php` (lines 1-104)
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php` (lines 160-262)

The entire username generator (consonnes, voyelles, generate(), genererLettre(), genererConsonne()) is duplicated verbatim in two separate files. Both are included on every page load.

**Impact:** Double memory usage, potential function redefinition conflicts, maintenance burden.

**Recommendation:** Keep the code in one file only (copyright.php or a dedicated JS file) and remove the duplicate.

---

### FINDING-UI-006 [HIGH] Duplicate BBCode function definition in bbcode.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`
**Lines:** 1-87, 89-140

The `storeCaret()` JavaScript function is defined TWICE (lines 1-87 and 89-140). The second definition silently overwrites the first. Both contain references to undefined variable `as_mef` (should be `as_mf`) on lines 132, 184, 241, 299 causing JavaScript ReferenceErrors.

**Impact:** BBCode insertion toolbar is broken in IE browsers; JavaScript errors in console for all browsers when storeCaret functions execute the IE path.

**Recommendation:** Remove the first `storeCaret` definition (lines 1-87), fix `as_mef` to `as_mf` in the remaining functions.

---

### FINDING-UI-007 [MEDIUM] No ARIA attributes or semantic HTML for accessibility

**Files:** All UI template files

The entire UI uses `<div>` soup without any ARIA roles, labels, or semantic HTML5 elements (`<nav>`, `<main>`, `<article>`, `<section>`). No `aria-label`, `aria-expanded`, `aria-hidden`, or `role` attributes anywhere. Form inputs lack explicit `<label for="">` associations in most cases.

**Impact:** The game is inaccessible to screen reader users and fails WCAG 2.1 Level A compliance.

**Recommendation:** Add `role="navigation"` to menus, `role="main"` to content areas, `aria-label` to interactive elements, and proper `<label>` elements for all form inputs.

---

### FINDING-UI-008 [MEDIUM] Inline styles used pervasively instead of CSS classes

**Files:** All UI files, particularly `ui_components.php`, `display.php`, `basicprivatehtml.php`

Nearly every HTML element has inline `style=""` attributes for sizing, positioning, and colors. Example from `display.php` line 11:
```php
return '<img style="vertical-align:middle;width:37px;height:37px;" alt="Energie" ...>';
```

**Impact:** Impossible to implement Content Security Policy `style-src` restrictions; maintenance nightmare; no theming capability; increased page size.

**Recommendation:** Extract repeated inline styles into CSS classes in `style.php` or `my-app.css`.

---

### FINDING-UI-009 [MEDIUM] Broken HTML attribute in style.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/style.php`
**Line:** 261

```css
.imageClassement{
    width: 32px;
    height; 32px;  /* semicolon instead of colon */
}
```

**Impact:** `height` property not applied to ranking images, causing potential layout issues.

**Recommendation:** Change `height;` to `height:`.

---

### FINDING-UI-010 [MEDIUM] Deprecated `<center>` tag and `<nobr>` tag usage

**Files:** `basicprivatehtml.php`, `cardsprivate.php`, `display.php`

Multiple uses of deprecated HTML elements: `<center>` (lines 296, 400 in basicprivatehtml.php) and `<nobr>` (lines 401 in basicprivatehtml.php, multiple in cardsprivate.php).

**Impact:** Not valid HTML5; may not render correctly in future browser versions.

**Recommendation:** Replace `<center>` with `<div style="text-align:center">` or a CSS class; replace `<nobr>` with `<span style="white-space:nowrap">`.

---

### FINDING-UI-011 [MEDIUM] Image with duplicate `alt` attribute

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php`
**Line:** 11

```php
return '<img ... alt="Energie" src="images/' . $nomsRes[$num] . '.png" alt="' . $nomsRes[$num] . '" ...>';
```

Two `alt` attributes on one `<img>` tag. The second one is ignored by browsers.

**Impact:** Incorrect alt text for accessibility; HTML validation failure.

**Recommendation:** Remove the first `alt="Energie"` and keep only the dynamic one.

---

### FINDING-UI-012 [LOW] Framework7 Material initialized with deprecated options

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php`
**Line:** 33

```javascript
var myApp = new Framework7({swipePanel: 'left',ajaxLinks:'.ajax',animateNavBackIcon: true,material:true,smartSelectOpenIn:'picker',externalLinks:'.external',pushState:true,swipePanelActiveArea: 40});
```

This uses Framework7 v1 API. The `material:true`, `swipePanel`, `ajaxLinks`, and `pushState` options are v1-specific. If Framework7 is ever updated, all of this breaks.

**Impact:** Lock-in to Framework7 v1; no path to upgrade without full rewrite.

**Recommendation:** Document the Framework7 version dependency. Do not upgrade without a full UI review.

---

### FINDING-UI-013 [LOW] Encoding issue in date display

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/cardspublic.php`
**Line:** 30

```php
echo date('d/m/Y Ã  H\hi', $donnees['timestamp']);
```

The `Ã ` should be `a` (the French preposition "a" with a grave accent). This is a character encoding corruption, likely from a UTF-8/Latin-1 mismatch.

**Impact:** Dates display with garbled characters on the public news page.

**Recommendation:** Replace `Ã ` with the correct UTF-8 character `\xC3\xA0` or simply use `a` without accent.

---

### FINDING-UI-014 [LOW] Unescaped news content output

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/cardspublic.php`
**Lines:** 30, 34

```php
echo $donnees['titre'];
$contenu = nl2br(stripslashes($donnees['contenu']));
echo $contenu;
```

News title and content from the database are echoed without any HTML escaping. While only admin can create news, this is a stored XSS risk if admin credentials are compromised.

**Impact:** Stored XSS through admin-created news posts.

**Recommendation:** Apply `htmlspecialchars()` to `$donnees['titre']` and process `$donnees['contenu']` through BBCode() or escape it.

---

### FINDING-UI-015 [LOW] Unused AES encryption JavaScript loaded on every page

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/copyright.php`
**Lines:** 28-29

```html
<script type="text/javascript" src="js/aes.js"></script>
<script type="text/javascript" src="js/aes-json-format.js"></script>
```

AES encryption libraries are loaded on every page but appear unused (no references found in any PHP or JS file to AES encryption functions).

**Impact:** Unnecessary bandwidth and page load time.

**Recommendation:** Remove these script includes unless they serve a purpose that wasn't found in the audit.

---

## 2. ERROR HANDLING DOMAIN FINDINGS

### FINDING-ERR-001 [CRITICAL] Database connection error exposes die() message without logging

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php`
**Line:** 10

```php
if (!$base) {
    die('Erreur de connexion à la base de données.');
}
```

While the message itself is sanitized (no SQL details), there is:
- No logging of the connection failure
- No error code or timestamp for diagnosis
- The `die()` produces a bare text response without proper HTTP status code
- The logger.php module is not yet loaded at this point

**Impact:** Database outages produce unhelpful pages; no alerts or monitoring can be triggered; debugging connection issues is difficult.

**Recommendation:** Add `error_log()` before `die()` with connection error details, or better, set up a proper error page with HTTP 503 response code.

---

### FINDING-ERR-002 [HIGH] Zero try/catch blocks in entire codebase

**Files:** All PHP files

A search across all PHP files confirms there are zero `try/catch` blocks anywhere in the codebase. PHP can throw exceptions from:
- `TypeError` on type mismatches (PHP 8.x strict mode)
- `DivisionByZeroError`
- `json_decode()` failures
- File operations in `logger.php` and `rate_limiter.php`

**Impact:** Any unhandled exception produces a bare PHP error page, potentially exposing file paths and code structure.

**Recommendation:** Add try/catch at minimum in:
1. `database.php` query functions (wrap mysqli calls)
2. `logger.php` file operations
3. `rate_limiter.php` file operations
4. Entry point files that include other modules

---

### FINDING-ERR-003 [HIGH] database.php logs SQL queries in error messages

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/database.php`
**Lines:** 14, 21, 61, 68

```php
error_log("SQL Prepare Error: " . mysqli_error($base) . " | Query: " . $sql);
error_log("SQL Execute Error: " . mysqli_stmt_error($stmt));
```

While `error_log()` writes to the PHP error log (not displayed to users), the full SQL query is logged including the query text. If an attacker can trigger prepared statement failures, they could probe the database schema by observing error log entries (if log files become accessible).

Additionally, `mysqli_error($base)` can contain table names and column details.

**Impact:** Information disclosure through error logs; useful for schema enumeration if logs are exposed.

**Recommendation:** Log a sanitized version: log the error category and a query identifier rather than the full SQL text. Example: `error_log("SQL Error in dbQuery: " . mysqli_error($base))` without the query.

---

### FINDING-ERR-004 [HIGH] CSRF failure produces die() with user-facing error details

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php`
**Line:** 27

```php
die('Erreur de securite : jeton CSRF invalide. Veuillez rafraichir la page.');
```

This `die()` produces a bare text page without proper HTML, CSS, or navigation. While the message is in French and doesn't expose technical details, the UX is terrible -- users get a blank page with text and no way to navigate back.

**Impact:** Poor user experience on CSRF token expiry (common with long idle sessions); no logging of CSRF failures (which could indicate attacks).

**Recommendation:** Log the CSRF failure with `logWarn()`, then redirect to the referring page with an error parameter, or render a proper error page.

---

### FINDING-ERR-005 [MEDIUM] Logger lacks log rotation and size limits

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php`
**Lines:** 32-33

```php
$filename = LOG_DIR . '/' . date('Y-m-d') . '.log';
file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
```

One log file per day with no size limit. A busy day or attack could generate gigabytes of logs filling the disk.

**Impact:** Disk space exhaustion leading to server downtime; no automatic cleanup of old logs.

**Recommendation:** Add:
1. Log file size check before writing (rotate if > 50MB)
2. A cleanup mechanism for logs older than 30 days
3. Consider using syslog or a proper logging library

---

### FINDING-ERR-006 [MEDIUM] Division by zero risks in display.php and formulas.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/display.php`
**Line:** 481 (progressBar function)

```php
return '... data-progress="' . ($vie / $vieMax * 100) . '" ...';
```

If `$vieMax` is 0, this produces a division by zero warning. Also in `player.php` line 175:

```php
$max = max($max, 3600 * ($placeDepot - $ressources[$ressource]) / max(1, $revenu[$ressource]));
```

The `max(1, ...)` guard is only on one occurrence. Similar risks exist in `formulas.php` line 165 for `tempsFormation()`.

**Impact:** PHP warnings/errors visible in output if display_errors is accidentally enabled.

**Recommendation:** Add `max(1, $vieMax)` or a zero-check before all divisions.

---

### FINDING-ERR-007 [MEDIUM] error_reporting set to E_ALL (32767) in production .htaccess

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
**Line:** 38

```
php_value error_reporting 32767
```

While `display_errors` is off, setting `error_reporting` to E_ALL means every notice, warning, and deprecation gets logged. This can create enormous log files with PHP 8.x strict type notices.

**Impact:** Log flooding; difficulty finding real errors among noise; disk space consumption.

**Recommendation:** Use `E_ALL & ~E_NOTICE & ~E_DEPRECATED` (30711) for production, or keep E_ALL but ensure log rotation is in place.

---

### FINDING-ERR-008 [LOW] No error handling in migration runner for partial failures

**File:** `/home/guortates/TVLW/The-Very-Little-War/migrations/migrate.php`
**Lines:** 43-53

```php
if (mysqli_multi_query($base, $sql)) {
    do { ... } while (mysqli_next_result($base));
}
if (mysqli_errno($base)) {
    echo "ERROR: " . mysqli_error($base) . "\n";
    exit(1);
}
```

`mysqli_multi_query()` can partially succeed -- some statements execute while later ones fail. The error check only catches the last error. There's no transaction wrapping.

**Impact:** Partially applied migrations leave the database in an inconsistent state with no rollback.

**Recommendation:** Wrap each migration in a transaction (`BEGIN`/`COMMIT`/`ROLLBACK`) or execute statements individually with error checking after each.

---

### FINDING-ERR-009 [LOW] Logger exposes login and IP in log entries

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/logger.php`
**Lines:** 26-27

```php
$login = $_SESSION['login'] ?? 'anonymous';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
```

Every log entry contains the player's login and IP address. Under GDPR, IP addresses are personal data.

**Impact:** Log files contain personal data requiring GDPR compliance measures (retention limits, access controls, DPA documentation).

**Recommendation:** Hash IP addresses in logs (`md5($ip)` for correlation without storing the raw IP), or document log retention and access policies.

---

## 3. DATA INTEGRITY DOMAIN FINDINGS

### FINDING-DATA-001 [CRITICAL] Market trades can drive defender resources negative

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 564-568

```php
foreach ($nomsRes as $num => $ressource) {
    $setClauses[] = "$ressource=?";
    $setTypes .= 'd';
    $setParams[] = ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'});
}
```

The defender's resources are reduced by the pillaged amount, but there is no `max(0, ...)` guard. If the defender's resources changed between the read at line 341 and the write at line 580 (e.g., the defender spent resources in another request), the value can go negative.

**Impact:** Players can have negative resource values, which breaks many game calculations and UI displays.

**Recommendation:** Add `max(0, $ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'})` or use a SQL `GREATEST(0, ...)` in the UPDATE query.

---

### FINDING-DATA-002 [CRITICAL] totalPoints cache desynchronization in ajouterPoints()

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 71-101 (ajouterPoints function)

The `ajouterPoints()` function reads `totalPoints` and then writes an updated value. If two concurrent requests call `ajouterPoints()` for the same player, they both read the same `totalPoints`, compute independently, and the last write wins -- losing the first update entirely.

For construction points (type 0, line 79):
```php
dbExecute($base, 'UPDATE autre SET points=?, totalPoints=? WHERE login=?',
    'dds', ($points['points'] + $nb), ($points['totalPoints'] + $nb), $joueur);
```

This should use `SET totalPoints = totalPoints + ?` to be atomic.

For attack/defense points (types 1, 2), the formula is even more complex:
```php
($points['totalPoints'] - pointsAttaque($points['pointsAttaque']) + pointsAttaque($points['pointsAttaque'] + $nb))
```

This reads, computes, and writes -- a classic read-modify-write race condition.

**Impact:** Leaderboard corruption; players can lose or gain points incorrectly; competitive integrity compromised.

**Recommendation:** Use `UPDATE autre SET points = points + ?, totalPoints = totalPoints + ? WHERE login = ?` for the simple cases. For complex formulas, use `SELECT ... FOR UPDATE` within a transaction.

---

### FINDING-DATA-003 [HIGH] ajouter() function has no bounds checking

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/db_helpers.php`
**Lines:** 18-24

```php
function ajouter($champ, $bdd, $nombre, $joueur) {
    global $base;
    $d = dbFetchOne($base, "SELECT $champ FROM $bdd WHERE login=?", 's', $joueur);
    dbExecute($base, "UPDATE $bdd SET $champ=? WHERE login=?", 'ds', ($d[$champ] + $nombre), $joueur);
}
```

This function:
1. Accepts `$champ` and `$bdd` parameters that are interpolated into SQL (not parameterized). While called with server-side constants, the pattern is dangerous.
2. Has no upper or lower bounds checking. Resources can be added beyond storage capacity.
3. Is a read-modify-write without atomicity.

Used in `cardsprivate.php` for tutorial mission rewards (lines 185-196) to grant resources without checking storage limits.

**Impact:** Tutorial mission rewards can push resources above storage capacity, breaking the depot limit mechanic.

**Recommendation:** Add `min(placeDepot($depot), $d[$champ] + $nombre)` for resource fields, or validate after adding.

---

### FINDING-DATA-004 [HIGH] supprimerJoueur() missing cleanup for actionsconstruction

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 687-711

The `supprimerJoueur()` function deletes from:
- vacances, autre, membre, ressources, molecules, constructions
- invitations, messages, rapports, grades
- actionsattaques, actionsformation, actionsenvoi, statutforum

**Missing tables:**
- `actionsconstruction` -- pending construction actions remain as orphaned rows
- `connectes` -- online status entries remain
- `medailles` -- medal records remain as orphaned data
- `prestige` -- prestige records remain
- `attack_cooldowns` -- cooldown entries remain

**Impact:** Database accumulates orphaned rows over time; potential foreign key violations if constraints are added later; wasted storage.

**Recommendation:** Add DELETE statements for `actionsconstruction`, `connectes`, `medailles`, `prestige`, and `attack_cooldowns`.

---

### FINDING-DATA-005 [HIGH] Market sell does not award trade points

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Lines:** 229-295

When buying resources (lines 145-226), trade points are calculated and awarded (lines 200-212). But when selling resources (lines 229-295), no trade points are awarded at all. Only the `logInfo()` call exists.

**Impact:** Asymmetric scoring incentivizes buying over selling, which could destabilize market prices.

**Recommendation:** Add trade point calculation for sell transactions, using the energy gained as the trade volume (mirroring the buy logic).

---

### FINDING-DATA-006 [HIGH] Negative pillage points possible via ajouterPoints type 3

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 94-101

```php
if ($type == 3) {
    $newPillage = $points['ressourcesPillees'] + $nb;
    $oldPillageContrib = pointsPillage($points['ressourcesPillees']);
    $newPillageContrib = pointsPillage(max(0, $newPillage));
    ...
}
```

In combat.php line 537:
```php
ajouterPoints(-$totalPille, $actions['defenseur'], 3);
```

The defender's `ressourcesPillees` is reduced by the pillaged amount. Since `pointsPillage()` uses `tanh()`, negative values produce negative point contributions. While `max(0, $newPillage)` protects the contribution, `$newPillage` itself can go negative in `ressourcesPillees`, creating incorrect lifetime stats.

**Impact:** Player stats can show negative "resources pillaged" values.

**Recommendation:** Clamp `$newPillage` to 0 minimum: `$newPillage = max(0, $points['ressourcesPillees'] + $nb)`.

---

### FINDING-DATA-007 [MEDIUM] Alliance member count not updated after player deletion

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 687-711

When a player is deleted via `supprimerJoueur()`, the code removes them from `autre` (which has `idalliance`), but never updates the alliance's cached member count or total points. The `recalculerStatsAlliances()` function exists but is not called from `supprimerJoueur()`.

**Impact:** Alliance statistics (total points, member contributions) remain stale after player deletion, showing inflated alliance scores on the leaderboard.

**Recommendation:** Call `recalculerStatsAlliances()` after player deletion, or at minimum recalculate stats for the player's former alliance.

---

### FINDING-DATA-008 [MEDIUM] updateTargetResources() does not use atomic tempsPrecedent guard

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
**Line:** 21

```php
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $targetPlayer);
```

Unlike `updateRessources()` in `game_resources.php` (line 119) which uses an atomic CAS-style guard:
```php
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', ...);
```

`updateTargetResources()` unconditionally overwrites `tempsPrecedent`. If two requests update the same target simultaneously, resources can be double-credited.

**Impact:** Resource duplication when multiple combat/espionage actions resolve against the same defender simultaneously.

**Recommendation:** Apply the same CAS guard pattern from `updateRessources()`.

---

### FINDING-DATA-009 [MEDIUM] remiseAZero() does not clean up forum data correctly

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 726-771

The season reset function `remiseAZero()` does not:
- Reset the `statutforum` table (forum read tracking)
- Clean up the `cours` (market prices) table -- old price history persists
- Reset `medailles` table (medals should arguably reset with the season)
- Reset `attack_cooldowns` (done, good)

Also, it resets ALL players unconditionally including inactive ones, allocating resources for players who may never log in again.

**Impact:** Stale data accumulates across seasons; potential confusion with old market price charts.

**Recommendation:** Clear `cours` table during reset (or archive it); decide on medal policy per season.

---

### FINDING-DATA-010 [LOW] placeDepot uses magic number despite config constant

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php`
**Line:** 256

```php
function placeDepot($niveau) {
    return 500 * $niveau;
}
```

`BASE_STORAGE_PER_LEVEL` is defined as 500 in config.php but `placeDepot()` uses the hardcoded value 500 instead of the constant.

**Impact:** Changing `BASE_STORAGE_PER_LEVEL` in config.php would have no effect. Configuration is misleading.

**Recommendation:** Change to `return BASE_STORAGE_PER_LEVEL * $niveau;`.

---

### FINDING-DATA-011 [LOW] progressBar division by zero when vieMax is 0

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php`
**Line:** 481

```php
'data-progress="' . ($vie / $vieMax * 100) . '"'
```

If `$vieMax` is 0 (e.g., a champdeforce at level 0 whose vieChampDeForce returns 0), this triggers a division by zero.

**Impact:** PHP warning; potentially broken progress bar display.

**Recommendation:** Guard with `$vieMax > 0 ? ($vie / $vieMax * 100) : 0`.

---

## 4. CONCURRENCY DOMAIN FINDINGS

### FINDING-CONCUR-001 [CRITICAL] Combat resolution has no mutual exclusion

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 67-71

```php
while ($actions = mysqli_fetch_array($ex)) {
    if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) {
        dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=?', 'i', $actions['id']);
```

Two simultaneous requests (e.g., both attacker and defender load a page at the same moment when an attack resolves) can both read `attaqueFaite == 0`, both set it to 1, and both execute the combat resolution. The `UPDATE` does not use a `WHERE attaqueFaite=0` condition.

**Impact:** Combat is resolved twice: defender loses troops twice, attacker gets resources twice, duplicate reports generated, points awarded twice. This is an exploitable resource duplication vulnerability.

**Recommendation:** Change to:
```php
$affected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
if ($affected === 0) continue; // Another request already resolved this
```

---

### FINDING-CONCUR-002 [CRITICAL] Market trades have no atomicity

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Lines:** 145-226

The buy flow:
1. Reads `$ressources` (line 51 via basicprivatephp.php)
2. Checks `$ressources['energie'] >= $coutAchat` (line 161)
3. Updates resources (line 167-172)
4. Inserts new market price (line 196)

Between steps 1 and 3, another request can also read the same resources and execute a trade. Both trades succeed even if the player only had enough energy for one. No database transaction or row locking is used.

**Impact:** Players can buy more resources than their energy allows by sending rapid concurrent requests. Energy balance goes negative.

**Recommendation:** Wrap the buy/sell flow in a MySQL transaction with `SELECT ... FOR UPDATE` on the resources row, or use atomic `UPDATE ... SET energie = energie - ? WHERE energie >= ?` and check affected rows.

---

### FINDING-CONCUR-003 [CRITICAL] Season reset can be triggered by concurrent requests

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Line:** 726

The `remiseAZero()` function performs destructive operations (deleting declarations, messages, etc.) without any lock or guard. If two requests detect the season-end condition simultaneously and both call `remiseAZero()`, the function runs twice. The second run operates on already-reset data, potentially causing:
- Double resource allocations for prestige players
- Corrupted statistics
- Lost data from the brief window between the two resets

**Impact:** Data corruption during the monthly season reset.

**Recommendation:** Implement a database-level lock: `INSERT INTO season_lock (id) VALUES (1)` with a unique constraint, or use `GET_LOCK('season_reset', 0)` in MySQL.

---

### FINDING-CONCUR-004 [HIGH] Rate limiter file-based TOCTOU race condition

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php`
**Lines:** 19-34

```php
if (file_exists($file)) {
    $data = json_decode(file_get_contents($file), true);
    ...
}
if (count($attempts) >= $maxAttempts) {
    return false; // Rate limited
}
$attempts[] = $now;
file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
```

Classic TOCTOU (Time-of-check-to-time-of-use) vulnerability. Between `file_get_contents()` and `file_put_contents()`, another process can modify the file. While `LOCK_EX` is used on write, it is not used on read. Two concurrent requests can both read the same attempt count, both pass the check, and both write -- effectively doubling the allowed attempts.

**Impact:** Rate limiting on login (5 attempts/300s) can be bypassed by sending requests in parallel, allowing brute-force attacks at 2x the intended rate.

**Recommendation:** Use `flock()` for the entire read-check-write cycle:
```php
$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$data = json_decode(stream_get_contents($fp), true);
// ... check and update ...
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode(...));
flock($fp, LOCK_UN);
fclose($fp);
```
Or migrate to database-based rate limiting.

---

### FINDING-CONCUR-005 [HIGH] Building queue can be manipulated during construction

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 26-31

```php
$ex = dbQuery($base, 'SELECT * FROM actionsconstruction WHERE login=? AND fin<?', 'si', $joueur, time());
while ($actions = mysqli_fetch_array($ex)) {
    augmenterBatiment($actions['batiment'], $joueur);
    dbExecute($base, 'DELETE FROM actionsconstruction WHERE id=?', 'i', $actions['id']);
}
```

If a player sends two requests simultaneously when a construction finishes:
1. Both read the pending construction action
2. Both call `augmenterBatiment()` for the same building
3. Both delete the action

The building gets upgraded twice from one construction action. The DELETE should use the same CAS pattern: check affected rows.

**Impact:** Buildings can be upgraded twice from a single construction, gaining free levels and points.

**Recommendation:** Use `DELETE FROM actionsconstruction WHERE id=? AND fin < ?` and check `affected_rows > 0` before calling `augmenterBatiment()`.

---

### FINDING-CONCUR-006 [HIGH] Formation queue double-credit on concurrent access

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 35-61

Similar to construction: formation actions are read, molecules are added, and the action is deleted/updated. Two concurrent requests can both process the same formation action, adding molecules twice.

The `nombreRestant` field is decremented in a read-modify-write pattern (line 51):
```php
dbExecute($base, 'UPDATE actionsformation SET nombreRestant=? WHERE id=?', 'di',
    ($actions['nombreRestant'] - floor((time() - $derniereFormation) / $actions['tempsPourUn'])), $actions['id']);
```

This uses the stale `$actions['nombreRestant']` value rather than an atomic SQL decrement.

**Impact:** Molecule duplication; army inflation; competitive advantage through exploit.

**Recommendation:** Use `UPDATE actionsformation SET nombreRestant = nombreRestant - ? WHERE id = ? AND nombreRestant >= ?` with affected-rows checking.

---

### FINDING-CONCUR-007 [MEDIUM] Resource sending has read-modify-write without transaction

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Lines:** 51-115

The resource sending flow:
1. Reads sender's resources
2. Checks sender has enough
3. Subtracts from sender
4. Creates envoi action

No transaction wrapping. Concurrent sends can both read the same balance and both succeed, draining resources below zero.

**Impact:** Resource duplication through rapid concurrent resource sends.

**Recommendation:** Wrap in a MySQL transaction or use atomic `UPDATE ... WHERE ressource >= sent_amount`.

---

### FINDING-CONCUR-008 [MEDIUM] Alliance energy donation likely has same race condition

**File:** Not directly inspected, but based on the pattern in `allianceadmin.php` -- any operation that reads alliance energy, checks if sufficient, then updates, would suffer the same read-modify-write concurrency issue.

**Impact:** Alliance research can potentially be purchased multiple times if requests are timed carefully.

**Recommendation:** Audit alliance energy operations for atomic update patterns.

---

### FINDING-CONCUR-009 [LOW] Prestige points can be double-awarded

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php`
**Lines:** 88-115

`awardPrestigePoints()` iterates all players and inserts/updates prestige. If called twice (e.g., from the season-reset race condition in FINDING-CONCUR-003), every player gets double prestige points. The `ON DUPLICATE KEY UPDATE total_pp = total_pp + ?` means each call additively increases PP.

**Impact:** Prestige point inflation if season reset runs twice.

**Recommendation:** Guard with the same lock mechanism as the season reset.

---

## 5. DEPLOYMENT DOMAIN FINDINGS

### FINDING-DEPLOY-001 [CRITICAL] Database credentials in connexion.php with no environment separation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/connexion.php`
**Lines:** 2-5

```php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'theveryl_theverylittlewar';
```

Hardcoded credentials with empty password for root. The VPS deployment uses different credentials (documented in MEMORY.md). This means:
1. The git repository contains development credentials
2. There is no environment-based configuration switching
3. The `root` user with no password is the development default

**Impact:** If this file is accidentally deployed without modification, the game connects with root/no-password. Credentials in version control are a security anti-pattern.

**Recommendation:** Use environment variables or a `.env` file (gitignored) for credentials:
```php
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'theveryl_theverylittlewar';
```

---

### FINDING-DEPLOY-002 [HIGH] No .htaccess for docs/ directory

**Glob search:** No `.htaccess` found in `docs/` directory.

The `docs/` directory contains audit reports, changelogs, and deployment guides. Without an `.htaccess` deny rule, these are publicly accessible via HTTP.

**Impact:** Sensitive information in audit reports (including this one) can be accessed by anyone who guesses the URL.

**Recommendation:** Create `docs/.htaccess`:
```
Require all denied
```

---

### FINDING-DEPLOY-003 [HIGH] robots.txt exposes admin paths

**File:** `/home/guortates/TVLW/The-Very-Little-War/robots.txt`

```
User-agent: *
Disallow: /admin/
Disallow: /moderation/
```

This tells search engines about the existence of `/admin/` and `/moderation/` directories. Malicious actors frequently check robots.txt to discover sensitive endpoints.

**Impact:** Information disclosure of admin panel locations.

**Recommendation:** Remove specific paths from robots.txt (they should be protected by authentication, not obscurity). Or use a blanket `Disallow: /` for the entire site if SEO isn't important.

---

### FINDING-DEPLOY-004 [MEDIUM] composer.json requires PHP >=7.4 but server runs PHP 8.2

**File:** `/home/guortates/TVLW/The-Very-Little-War/composer.json`
**Line:** 4

```json
"require": { "php": ">=7.4" }
```

The VPS runs PHP 8.2. PHP 8.x has breaking changes from 7.4 (stricter types, deprecations). The constraint should reflect the actual minimum tested version.

**Impact:** Untested PHP versions might be deployed; false confidence in compatibility.

**Recommendation:** Update to `"php": ">=8.2"` since that is the tested runtime.

---

### FINDING-DEPLOY-005 [MEDIUM] Migration runner uses mysqli_multi_query (SQL injection risk)

**File:** `/home/guortates/TVLW/The-Very-Little-War/migrations/migrate.php`
**Line:** 43

```php
if (mysqli_multi_query($base, $sql)) {
```

`mysqli_multi_query()` executes multiple statements from a file. While migration files are trusted, if a migration file is maliciously modified (supply chain attack), this would execute arbitrary SQL including DDL.

Additionally, `exit(1)` on error (line 53) provides no cleanup of partial execution state.

**Impact:** No transaction safety for migrations; potential for partial application.

**Recommendation:** Execute statements one at a time within transactions, or add a `--dry-run` flag for safety.

---

### FINDING-DEPLOY-006 [MEDIUM] .htaccess FilesMatch does not block .php~ or .bak files

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`
**Lines:** 13-21

```
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore)$">
```

The pattern blocks common development files but misses:
- `.php~` (vim/emacs backup files)
- `.bak` files
- `.swp` (vim swap files)
- `.log` files
- `.env` files
- `composer.lock`

**Impact:** Editor backup files or log files could expose source code or sensitive data.

**Recommendation:** Expand the pattern:
```
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore|bak|log|env|swp|swo)$">
```

---

### FINDING-DEPLOY-007 [LOW] No Content-Security-Policy header

**File:** `/home/guortates/TVLW/The-Very-Little-War/.htaccess`

While `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, and `Referrer-Policy` are set, there is no `Content-Security-Policy` header. Given the extensive use of inline scripts and styles (see FINDING-UI-008), a restrictive CSP cannot be applied today, but a report-only CSP would be valuable.

**Impact:** No defense-in-depth against XSS; the X-XSS-Protection header is deprecated and no longer effective in modern browsers.

**Recommendation:** Start with a report-only CSP to identify inline script/style usage, then work toward eliminating inline code:
```
Header set Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self' 'unsafe-inline' https://www.gstatic.com; report-uri /csp-report"
```

---

### FINDING-DEPLOY-008 [LOW] phpunit.xml references tests/bootstrap.php (not verified to exist)

**File:** `/home/guortates/TVLW/The-Very-Little-War/phpunit.xml`
**Line:** 4

```xml
bootstrap="tests/bootstrap.php"
```

The audit did not verify this file exists. If missing, `phpunit` will fail silently or with a confusing error.

**Impact:** CI/CD pipeline or local test runs may fail.

**Recommendation:** Verify `tests/bootstrap.php` exists and is functional.

---

## Cross-Domain Observations

### Pattern: Read-Modify-Write Without Atomicity

The most pervasive issue across all domains is the read-modify-write anti-pattern. It appears in:
- Resource updates (market, combat, sending)
- Point calculations (ajouterPoints)
- Building upgrades (augmenterBatiment)
- Formation processing
- Alliance energy operations

**Systemic Recommendation:** Introduce a `withTransaction()` helper:
```php
function withTransaction($base, callable $fn) {
    mysqli_begin_transaction($base);
    try {
        $result = $fn();
        mysqli_commit($base);
        return $result;
    } catch (Exception $e) {
        mysqli_rollback($base);
        throw $e;
    }
}
```

Use `SELECT ... FOR UPDATE` within transactions for any read-modify-write cycle.

### Pattern: Legacy query() Function Still in Use

Three files still use the unsafe legacy `query()` function:
- `includes/atomes.php`
- `includes/copyright.php`
- `admin/tableau.php`

These should be migrated to `dbQuery()` with prepared statements.

### Pattern: No Input Validation on Numeric Parameters

Many game actions accept numeric input via `$_POST` and validate only with regex `#^[0-9]*$#`. This allows:
- `0` as a valid input (sending 0 resources, buying 0 atoms)
- Very large numbers that could cause integer overflow

**Systemic Recommendation:** Use `filter_input(INPUT_POST, 'field', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]])`.

---

## Priority Action Items

### Immediate (Week 1) -- Security Critical
1. FINDING-CONCUR-001: Add CAS guard to combat resolution
2. FINDING-CONCUR-002: Add transaction to market trades
3. FINDING-DATA-001: Prevent negative resources after pillage
4. FINDING-UI-004: Replace remaining raw query() calls

### Short-term (Week 2-3) -- High Priority
5. FINDING-UI-002: Fix scripts-after-html structure
6. FINDING-CONCUR-004: Fix rate limiter TOCTOU
7. FINDING-CONCUR-005: Fix construction queue double-credit
8. FINDING-DATA-002: Make totalPoints updates atomic
9. FINDING-DEPLOY-002: Add docs/.htaccess deny rule
10. FINDING-UI-003: Fix mixed content in partenariat.php

### Medium-term (Month 1) -- Quality Improvements
11. FINDING-ERR-002: Add try/catch in critical paths
12. FINDING-DATA-004: Complete supprimerJoueur cleanup
13. FINDING-DEPLOY-001: Move credentials to environment variables
14. FINDING-CONCUR-003: Add season-reset locking
15. FINDING-ERR-005: Add log rotation

### Long-term (Quarter 1) -- Technical Debt
16. FINDING-UI-001: Systematic XSS prevention in UI components
17. FINDING-UI-007: Accessibility improvements
18. FINDING-UI-008: Extract inline styles to CSS
19. FINDING-DEPLOY-007: Implement Content-Security-Policy
20. All remaining LOW findings

---

*End of audit report. 52 findings across 5 domains.*
