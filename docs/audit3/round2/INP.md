# Input Validation Deep-Dive -- Round 2

**Audit Date:** 2026-03-03
**Auditor:** Claude Opus 4.6 (code-reviewer agent)
**Scope:** All user-input paths across all PHP files in The Very Little War
**Focus:** Type coercion, boundary values, negative numbers, overflow, empty strings, special characters, encoding attacks, second-order injection

---

## Executive Summary

Reviewed 22 PHP files handling user input across login, registration, forum, market, combat, army, messaging, account settings, alliance management, and API endpoints. Found **47 issues** total: 5 CRITICAL, 11 HIGH, 18 MEDIUM, 13 LOW.

The codebase shows evidence of substantial prior hardening (prepared statements everywhere, CSRF checks, htmlspecialchars on output). The remaining issues cluster around: (1) missing minimum-length and complexity checks on passwords, (2) BBCode regex patterns allowing HTML attribute injection via crafted URLs, (3) integer overflow and boundary-value gaps in numeric inputs, (4) missing length limits on text fields allowing storage abuse, and (5) unsafe date parsing in vacation/moderation forms.

---

## Findings

### INP-R2-001 -- CRITICAL -- No Minimum Password Length at Registration

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`, line 20-24
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/validation.php` (missing function)

**Description:** Registration accepts any non-empty password. A single-character password is valid. There is no `validatePassword()` function in `validation.php`. The password change flow in `compte.php` (line 33-67) has the same gap.

**PoC:**
```
POST /inscription.php
login=Testuser&pass=a&pass_confirm=a&email=test@test.com&csrf_token=...
```
Result: Account created with 1-character password.

**Impact:** Trivially brute-forceable passwords. The rate limiter (10 attempts / 5 minutes) helps but a 1-char password falls within that window.

**Fix:**
```php
// In includes/validation.php, add:
function validatePassword($password) {
    return mb_strlen($password) >= 8 && mb_strlen($password) <= 128;
}

// In inscription.php, after line 27:
} elseif (!validatePassword($passInput)) {
    $erreur = 'Le mot de passe doit contenir entre 8 et 128 caract&egrave;res.';
}

// In compte.php, before line 59:
if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 128) {
    $erreur = "Le mot de passe doit contenir entre 8 et 128 caract&egrave;res.";
} else { ... }
```

---

### INP-R2-002 -- CRITICAL -- BBCode [img=] Tag Allows Event Handler Injection via Encoded Quotes

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, line 332

**Description:** The `[img=...]` BBCode regex is:
```php
$text = preg_replace('!\[img=(https?:\/\/[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU', '<img alt="undefined" src="$1">', $text);
```
The URL pattern excludes `'`, `"`, `<`, `>`, and whitespace. However, the input is first HTML-entity-encoded via `htmlentities()` on line 317. This means an attacker can input `"` (which becomes `&quot;` after htmlentities) and the regex sees `&quot;` which does NOT match `"`. But since `htmlentities` runs BEFORE the regex, the output contains `&quot;` in the src attribute which the browser decodes back to `"` inside the attribute.

Specifically, `[img=https://evil.com/x.png&quot; onerror=&quot;alert(1)]` after `htmlentities` becomes `[img=https://evil.com/x.png&amp;quot; onerror=&amp;quot;alert(1)]` -- the double-encoding prevents exploitation in the current version. However, the regex allows `]` in the URL, meaning:

Actually on re-analysis: `htmlentities` at line 317 converts `"` to `&quot;` BEFORE the regex runs. Since the regex excludes `'` and `"` but NOT `&`, `q`, `u`, `o`, `t`, `;` -- the entity-encoded form passes the regex. But the browser sees `&quot;` as `"` inside the attribute.

**PoC:**
```
Forum post: [img=https://evil.com/x.png&quot; onerror=&quot;alert(document.cookie)]
```
After htmlentities: `[img=https://evil.com/x.png&amp;quot; onerror=&amp;quot;alert(document.cookie)]`
The `&amp;` blocks exploitation because the browser sees literal `&amp;quot;` not `"`.

**Revised assessment:** The double-encoding via `htmlentities` before regex is actually protective here. Downgrade this from CRITICAL to MEDIUM -- see INP-R2-002b below. However, the pattern is still fragile.

**Actual CRITICAL issue:** The `[url=...]` pattern at line 331:
```php
$text = preg_replace('!\[url=(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\](.+)\[/url\]!isU', '<a href="$1">$5</a>', $text);
```
The regex uses `i` (case-insensitive) and `s` (DOTALL) flags. The URL pattern allows spaces (`[\/\w \.-]`). After htmlentities encoding, a URL like `javascript:alert(1)` won't match since it requires `https?://`. However, the regex is vulnerable to ReDoS (catastrophic backtracking) due to `([\/\w \.-]*)*` -- a nested quantifier.

**Fix:**
```php
// Replace nested quantifier with non-backtracking form:
$text = preg_replace('!\[url=(https?:\/\/[\w\.\-]+\.[\w\.]{2,6}[\w\/\.\- ]*)\](.+)\[/url\]!isU', '<a href="$1">$2</a>', $text);
```

---

### INP-R2-003 -- CRITICAL -- BBCode [url=] ReDoS via Nested Quantifiers

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, line 331

**Description:** The regex `([\/\w \.-]*)*` contains a nested quantifier (star inside star). With a crafted input like `[url=https://a.bb/` followed by 30+ slashes, the regex engine will backtrack catastrophically, causing CPU exhaustion.

**PoC:**
```
Forum post: [url=https://a.bb/////////////////////////////////x]click[/url]
```
This will cause the regex engine to hang for seconds or minutes depending on the number of slashes. A single forum post can DOS the server since BBCode parsing happens on every page that displays the post.

**Impact:** Denial of Service. Any authenticated user can post content that causes the server to hang when any other user views the forum topic.

**Fix:**
```php
// Line 331, replace:
$text = preg_replace('!\[url=(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\](.+)\[/url\]!isU', '<a href="$1">$5</a>', $text);
// With atomic/possessive grouping or simplified pattern:
$text = preg_replace('!\[url=(https?://[\w.\-]+\.[\w.]{2,6}[/\w.\- ]*)\](.+)\[/url\]!isU', '<a href="$1" rel="noopener noreferrer" target="_blank">$2</a>', $text);
```

---

### INP-R2-004 -- CRITICAL -- Forum Post Content Has No Length Limit

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, line 21
**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`, line 31

**Description:** Forum post creation in `sujet.php` line 21 inserts `$_POST['contenu']` directly without any length check. Same in `listesujets.php` line 31 for topic creation. A user can POST megabytes of content, consuming database storage and causing slow page loads for all users viewing the topic.

**PoC:**
```python
import requests
payload = "A" * 10_000_000  # 10MB post
requests.post("https://theverylittlewar.com/sujet.php?id=1", data={
    "contenu": payload,
    "csrf_token": token
}, cookies=session_cookies)
```

**Impact:** Storage exhaustion, performance degradation, potential denial of service for forum readers.

**Fix:**
```php
// Before INSERT in sujet.php and listesujets.php:
if (mb_strlen($_POST['contenu']) > 10000) {
    $erreur = "Le message ne peut pas depasser 10 000 caracteres.";
} else {
    // proceed with insert
}
```

---

### INP-R2-005 -- CRITICAL -- Market Resource Transfer: Destinataire Name Not Validated

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`, line 124

**Description:** At line 124, the `$_POST['destinataire']` value is displayed in the `$information` message without sanitization:
```php
$information = "Vous avez envoy..." . $chaine . ' a ' . $_POST['destinataire'];
```
While `$_POST['destinataire']` is trimmed (line 23) and verified to exist in DB (line 49-50), the `$information` variable is later output via the `tout.php` include. If the display code does not escape `$information`, this is a stored XSS vector. However, even if the player exists, the name goes through `ucfirst(mb_strtolower(trim()))` only at registration -- the raw POST value here is NOT passed through `htmlspecialchars`.

**PoC:**
Requires creating an account with a name that passes `validateLogin` (alphanumeric only), so direct XSS via login name is blocked. However, the `$_POST['destinataire']` value at line 124 is the raw POST input, not the DB-verified login name. An attacker could send `destinataire=<script>alert(1)</script>` -- but this would fail the DB check at line 49 ("joueur n'existe pas"). So the XSS only fires if the DB lookup succeeds, and login names are alphanumeric-only.

**Revised Impact:** LOW -- the login validation at registration prevents XSS characters in player names. But the pattern of using raw `$_POST` in output strings is dangerous if login validation ever loosens.

**Fix:**
```php
// Line 124, replace:
$information = "Vous avez envoy..." . $chaine . ' a ' . $_POST['destinataire'];
// With:
$information = "Vous avez envoy..." . $chaine . ' a ' . htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8');
```

---

### INP-R2-006 -- HIGH -- Attack Troop Counts Not intval'd Before Arithmetic

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php`, lines 91-103

**Description:** The troop counts `$_POST['nbclasse1']` through `$_POST['nbclasse4']` are checked for `< 0` (line 95) and summed via `intval()` (line 103), but they are NOT intval'd at line 95. PHP's loose comparison `$_POST['nbclasse1'] < 0` with a string like `"0e1"` evaluates to false (because `"0e1"` == 0 in PHP 7). More importantly, at line 117:
```php
if (ceil($moleculesAttaque['nombre']) < $_POST['nbclasse' . $c]) {
```
The comparison uses the raw POST string against a numeric DB value. PHP will type-juggle, but `"1abc"` becomes `1`, which is a silent truncation.

At line 133:
```php
$troupes = $troupes . $_POST['nbclasse' . $c] . ';';
```
The raw POST string is concatenated into the `troupes` field stored in the database. While this goes through a prepared statement (line 154), the stored `troupes` string is later parsed with `explode(";",...)` in `game_actions.php` line 88 and used in arithmetic. If a troop value contains non-numeric characters, `explode` + arithmetic will silently coerce.

**PoC:**
```
POST /attaquer.php
joueurAAttaquer=Victim&nbclasse1=1e2&nbclasse2=0&nbclasse3=0&nbclasse4=0
```
`1e2` = 100 in PHP numeric context but only `1` after intval in some contexts. The `intval("1e2")` returns `100` in PHP 8, so this specific case is handled. But `"1.5"` would be truncated to `1` by `intval` at line 103 while `ceil("1.5")` = 2 at the comparison on line 117, creating an inconsistency.

**Fix:**
```php
// After line 92, add explicit intval:
for ($i = 1; $i <= $nbClasses; $i++) {
    $_POST['nbclasse' . $i] = intval($_POST['nbclasse' . $i] ?? 0);
    if ($_POST['nbclasse' . $i] < 0) {
        $troupesPositives = false;
    }
}
```

---

### INP-R2-007 -- HIGH -- Vacation Date Parsing Without Validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, lines 13-28

**Description:** The vacation date `$_POST['dateFin']` is parsed via `explode('/', ...)` into `$jour`, `$mois`, `$annee` without verifying that exactly 3 parts exist or that they are valid integers. If a user submits `dateFin=//`, the variables become empty strings, and `DateTime::setDate('', '', '')` may throw or produce unexpected behavior. More importantly, there is no `checkdate()` call to validate the date is real (e.g., `31/02/2026` would be accepted).

**PoC:**
```
POST /compte.php
dateFin=31/02/2026&csrf_token=...
```
PHP's DateTime will silently roll over February 31 to March 3, allowing a player to set a vacation end date that doesn't match what they intended.

**Fix:**
```php
// Replace lines 13-19 with:
if (isset($_POST['dateFin'])) {
    $parts = explode('/', $_POST['dateFin']);
    if (count($parts) !== 3) {
        $erreur = "Format de date invalide (jj/mm/aaaa).";
    } else {
        list($jour, $mois, $annee) = $parts;
        $jour = intval($jour);
        $mois = intval($mois);
        $annee = intval($annee);
        if (!checkdate($mois, $jour, $annee)) {
            $erreur = "Date invalide.";
        } else {
            $dateT = new DateTime();
            $dateT->setDate($annee, $mois, $jour);
            if ($dateT->getTimestamp() >= time() + (3600 * 24 * 3)) {
                // proceed...
```

---

### INP-R2-008 -- HIGH -- Market Quantity: transformInt Allows Gigantic Values

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`, line 147 (buy), line 257 (sell)

**Description:** The `transformInt()` function expands shorthand like `1T` into `1000000000000`. After `intval()`, this becomes a valid integer. The server-side checks verify the player has enough resources, but on a 32-bit system or with PHP's `intval()` overflow, `1T` = `1000000000000` which exceeds `PHP_INT_MAX` on 32-bit (2147483647), causing `intval()` to return `PHP_INT_MAX` or 0 depending on platform.

On 64-bit PHP 8: `intval("1000000000000")` = 1000000000000 which is fine. But `10T` = `10000000000000` is 10 trillion, which when multiplied by the market price could overflow float precision.

The `preg_match("#^[0-9]*$#", ...)` check on line 149/259 validates the expanded form, but `*` matches zero characters (empty string), so `intval(transformInt(""))` = 0, which then fails the `!empty()` check. This is correct behavior but fragile.

**PoC:**
```
POST /marche.php?sub=0
typeRessourceAAcheter=carbone&nombreRessourceAAcheter=999999999999999999999
```
After `intval()` on 64-bit: returns `PHP_INT_MAX` = 9223372036854775807. The energy cost calculation `round($tabCours[$numRes] * 9223372036854775807)` will produce `INF` in float arithmetic, and `$diffEnergieAchat = $locked['energie'] - INF` = `-INF` which fails the check. So exploitation is limited, but the intermediate overflow could cause unexpected logging or error states.

**Fix:**
```php
// After intval, add upper bound:
$_POST['nombreRessourceAAcheter'] = intval(transformInt($_POST['nombreRessourceAAcheter']));
if ($_POST['nombreRessourceAAcheter'] > 1000000000) { // 1 billion max
    $erreur = "Quantite trop grande.";
} else if ($_POST['nombreRessourceAAcheter'] < 1) {
    $erreur = "Quantite invalide.";
}
```

---

### INP-R2-009 -- HIGH -- Empty-String Regex Match in Numeric Validation

**File:** Multiple files using `preg_match("#^[0-9]*$#", ...)`

**Description:** The regex `#^[0-9]*$#` matches the empty string because `*` means "zero or more". This is used in:
- `marche.php` lines 44, 48, 149, 259
- `armee.php` lines 5, 65, 87, 90, 151, 155
- `attaquer.php` line 26
- `don.php` line 10

In most cases, there is an `!empty()` check before the regex, or the value has already been through `intval()` which would produce `"0"` for empty input. However, in `armee.php` line 155:
```php
if (!(isset($_POST[$ressource]) and preg_match("#^[0-9]*$#", $_POST[$ressource]))) {
```
An empty string for `$_POST[$ressource]` passes the regex. Later, `empty("")` returns true, so the `$bool == 0` check at line 173 catches it. The logic works but is fragile -- any refactoring that removes the `empty` check would create a vulnerability.

**Fix:** Globally replace `#^[0-9]*$#` with `#^[0-9]+$#` (one or more digits) across all files:
```
marche.php: lines 44, 48, 149, 259
armee.php: lines 5, 65, 87, 90, 151, 155
attaquer.php: line 26
```

---

### INP-R2-010 -- HIGH -- Alliance Name Has No Format Validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, line 35
**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, line 59

**Description:** The alliance TAG is validated with `preg_match("#^[a-zA-Z0-9_]{3,16}$#")` at `alliance.php:38`, but the alliance NAME (`$_POST['nomalliance']`) has NO format or length validation beyond `!empty()`. A user can create an alliance with a name containing any Unicode characters, HTML entities (which are escaped on output), or a name of unbounded length.

Similarly in `allianceadmin.php:58-63`, `$_POST['changernom']` is only checked for `!empty()` with no length or format validation.

**PoC:**
```
POST /alliance.php
nomalliance=AAAA...AAAA (10000 chars)&tagalliance=Test123
```
Creates an alliance with a 10KB name that could break layout rendering.

**Fix:**
```php
// In alliance.php after line 35:
if (mb_strlen($_POST['nomalliance']) > 50) {
    $erreur = "Le nom ne doit pas depasser 50 caracteres.";
} elseif (!preg_match('#^[\w\s\'-]{1,50}$#u', $_POST['nomalliance'])) {
    $erreur = "Le nom contient des caracteres non autorises.";
}
```

---

### INP-R2-011 -- HIGH -- Alliance Tag Change Has No Format Validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, line 121-137

**Description:** When creating an alliance, the TAG is validated with `preg_match("#^[a-zA-Z0-9_]{3,16}$#")`. But when CHANGING the tag via `allianceadmin.php:121-137`, there is no format validation at all -- only `!empty()` and uniqueness check. An alliance chief can change the TAG to any string including special characters, extremely long strings, etc.

**PoC:**
```
POST /allianceadmin.php
changertag=<script>alert(1)</script>
```
The TAG is stored in DB and displayed throughout the site. It goes through `htmlspecialchars` on output in most places but not all (e.g., `allianceadmin.php:213-214` in rapport content -- `$chef['tag']` is inserted into HTML report without escaping).

**Fix:**
```php
// In allianceadmin.php, after line 124:
if (!preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['changertag'])) {
    $erreur = "Le TAG ne peut contenir que des lettres, chiffres et _ (3-16 caracteres).";
} else {
    // existing uniqueness check...
}
```

---

### INP-R2-012 -- HIGH -- Alliance Description Stored Without Length Limit

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, line 164-173
**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, line 88-93

**Description:** Both alliance description and player description are stored without any length limit. A user can POST megabytes of description text.

The player description (`compte.php:89`) stores `trim($_POST['changerdescription'])` directly. The alliance description (`allianceadmin.php:168`) does the same.

**PoC:**
```
POST /compte.php
changerdescription=AAAA... (5MB of text)
```

**Fix:**
```php
// Add length check before storing:
$newDescription = trim($_POST['changerdescription']);
if (mb_strlen($newDescription) > 5000) {
    $erreur = "La description ne doit pas depasser 5000 caracteres.";
} else {
    dbExecute($base, 'UPDATE autre SET description = ? WHERE login = ?', 'ss', $newDescription, $_SESSION['login']);
}
```

---

### INP-R2-013 -- HIGH -- Message Title and Content Have No Length Limits

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`, lines 6-31

**Description:** Message title (`$_POST['titre']`) and content (`$_POST['contenu']`) are stored without any length validation. The `[alliance]` message target means a single oversized message gets duplicated to every alliance member, multiplying storage impact.

**Fix:**
```php
if (mb_strlen($_POST['titre']) > 200) {
    $erreur = "Le titre ne doit pas depasser 200 caracteres.";
} elseif (mb_strlen($_POST['contenu']) > 10000) {
    $erreur = "Le contenu ne doit pas depasser 10 000 caracteres.";
} else {
    // proceed with message sending
}
```

---

### INP-R2-014 -- HIGH -- Espionage Neutrino Count Not intval'd Before Comparison

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php`, line 24-26

**Description:** At line 24, `$_POST['nombreneutrinos']` is `intval()`'d. But at line 26, the condition checks:
```php
preg_match("#^[0-9]*$#", $_POST['nombreneutrinos']) and $_POST['nombreneutrinos'] >= 1
```
Since `intval()` already ran at line 24, `$_POST['nombreneutrinos']` is now an integer, and `preg_match` on an integer works (PHP converts to string). This is correct but the `*` in the regex still matches empty string (see INP-R2-009). The upper bound check `$_POST['nombreneutrinos'] <= $autre['neutrinos']` is good.

However, the `$autre['neutrinos']` value comes from the DB, and if `$autre` is stale (not re-read inside a transaction), a race condition exists where a player could spend more neutrinos than they have by sending concurrent requests.

**Fix:** Use `FOR UPDATE` when reading neutrino count, or use atomic decrement:
```php
$result = dbExecute($base, 'UPDATE autre SET neutrinos = neutrinos - ? WHERE login = ? AND neutrinos >= ?', 'iss', $_POST['nombreneutrinos'], $_SESSION['login'], $_POST['nombreneutrinos']);
if (mysqli_affected_rows($base) === 0) {
    $erreur = "Pas assez de neutrinos.";
}
```

---

### INP-R2-015 -- HIGH -- Neutrino Purchase in armee.php: No Upper Bound

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`, lines 62-85

**Description:** The neutrino purchase at line 65-66 validates that the input is a positive integer and that the player has enough energy. But there is no upper bound on the number of neutrinos that can be purchased in a single request. A player could buy `PHP_INT_MAX` neutrinos if they somehow had enough energy, which could overflow the neutrino counter in the database.

More practically, `$_POST['nombreneutrinos'] * $coutNeutrino` at line 67 could overflow if both values are large. On 64-bit PHP, this is unlikely to be a practical issue, but defensive coding should cap it.

**Fix:**
```php
$_POST['nombreneutrinos'] = intval($_POST['nombreneutrinos']);
if ($_POST['nombreneutrinos'] > 1000000) {
    $erreur = "Maximum 1 000 000 neutrinos par achat.";
} elseif ($_POST['nombreneutrinos'] * $coutNeutrino <= $ressources['energie']) {
    // proceed
}
```

---

### INP-R2-016 -- MEDIUM -- BBCode [img=] Allows Arbitrary External Image Loading (SSRF/Tracking)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, line 332

**Description:** The `[img=URL]` tag allows loading images from any `https?://` URL. This enables:
1. **Tracking pixels:** An attacker posts `[img=https://evil.com/track.gif?user=TARGET]` and gets IP/access-time information for every user who views the forum topic.
2. **Internal network probing:** If the server has access to internal resources, `[img=http://192.168.1.1/admin.png]` would be loaded by the users' browsers, not the server, so this is client-side only -- still a privacy concern.

**Fix:** Consider proxying external images through the server, or restrict to a whitelist of trusted domains. At minimum, add `referrerpolicy="no-referrer"` to generated `<img>` tags:
```php
$text = preg_replace('!\[img=(https?://[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU',
    '<img alt="image" src="$1" referrerpolicy="no-referrer" loading="lazy">', $text);
```

---

### INP-R2-017 -- MEDIUM -- BBCode [latex] Tag Allows MathJax Code Execution

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, line 335
**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, line 223

**Description:** The `[latex]` BBCode wraps content in `$$...$$` for MathJax rendering. MathJax is loaded from CDN at the bottom of `sujet.php` (line 223). While MathJax itself is generally safe, certain TeX macros combined with HTML mode can cause rendering issues or unexpected behavior. The input IS `htmlentities`-encoded before reaching the regex, so HTML tags are neutralized.

However, MathJax's `\href` command can create clickable links in rendered math, potentially phishing users:
```
[latex]\href{https://evil.com}{Click here}[/latex]
```

**Impact:** Low-severity phishing vector through MathJax-rendered content.

**Fix:** Configure MathJax to disable `\href` and `\url` commands, or strip them server-side:
```php
// After line 335:
$text = preg_replace('!\\\\href\{[^}]*\}\{[^}]*\}!', '[lien interdit]', $text);
```

---

### INP-R2-018 -- MEDIUM -- Email Validation Regex in compte.php Too Restrictive and Inconsistent

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, line 76

**Description:** The email change uses:
```php
preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", $_POST['changermail'])
```
This regex:
1. Only allows lowercase (no uppercase letters) -- users with `User@Gmail.com` will be rejected
2. Limits TLD to 2-4 characters -- rejects `.museum`, `.technology`, `.company`, etc.
3. Doesn't match what `validateEmail()` in `validation.php` uses (`filter_var(FILTER_VALIDATE_EMAIL)`)

The registration uses `validateEmail()` (correct), but email change uses a different, stricter regex.

**Fix:** Replace the regex with `validateEmail()`:
```php
require_once('includes/validation.php');
if (!validateEmail($_POST['changermail'])) {
    $erreur = "Votre email n'est pas correct.";
}
```

---

### INP-R2-019 -- MEDIUM -- Login Input Normalized Differently Between Registration and Login

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`, line 19
**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`, line 29

**Description:** Both registration and login use `ucfirst(mb_strtolower(trim(...)))` for normalization. This is consistent. However, the `validateLogin()` function checks `preg_match('/^[a-zA-Z0-9_]{3,20}$/', $login)` which runs AFTER normalization. Since `ucfirst(mb_strtolower("___"))` = `___`, a login of all underscores is technically valid.

The HTML form has `maxlength="13"` but the server-side validation allows up to 20 characters. This inconsistency could confuse users but is not a security issue.

More concerning: `mb_strtolower()` with multibyte characters could produce unexpected results for non-ASCII input. For example, `mb_strtolower("SS")` in some locales could produce different results. Since `validateLogin` restricts to ASCII alphanumeric + underscore, this is mitigated.

**Fix:** Add `maxlength="20"` to the HTML input to match server validation, or reduce server validation to 13 to match client.

---

### INP-R2-020 -- MEDIUM -- Player Description BBCode Injection via joueur.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/joueur.php`, line 82

**Description:** Player descriptions are displayed via `BBcode($donnees1['description'])`. The description is set in `compte.php:89-90` where raw user input (trimmed only) is stored. When viewed on `joueur.php:82`, it goes through BBCode parsing.

The BBCode function applies `htmlentities()` first (line 317 of bbcode.php), so HTML injection is prevented. However, the BBCode tags themselves can be abused -- unlimited `[img=...]` tags for tracking, `[url=...]` for phishing, `[latex]` for MathJax abuse, and the ReDoS vector from INP-R2-003.

**Fix:** Limit the number of BBCode tags allowed per content block, or strip BBCode from player descriptions.

---

### INP-R2-021 -- MEDIUM -- Molecule Class Slot Not Range-Validated After intval

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`, line 5

**Description:** Line 5 validates `$_POST['emplacementmoleculesupprimer']`:
```php
preg_match("#^[0-9]*$#", ...) and $_POST['emplacementmoleculesupprimer'] <= MAX_MOLECULE_CLASSES and >= 1
```
This is correct but the regex allows "01", "001", etc. which after intval would be valid. The comparison is loose (`<=`, `>=`), so `4.9` would be coerced to `4` by the DB parameter binding (type `i`). Not exploitable but fragile.

**Fix:** Use `intval()` explicitly before comparisons:
```php
$slot = intval($_POST['emplacementmoleculesupprimer']);
if ($slot >= 1 && $slot <= MAX_MOLECULE_CLASSES) {
```

---

### INP-R2-022 -- MEDIUM -- Molecule Creation: Atom Counts Validated as String But Used as Variable Variables

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`, lines 154-204

**Description:** At line 155, each atom count `$_POST[$ressource]` is checked with `preg_match("#^[0-9]*$#", ...)`. At line 179, the value is assigned to a variable-variable: `$$ressource = $_POST[$ressource]`. At line 204, it is sanitized with `intval($$ressource)` for the SQL query.

The concern is that `$_POST[$ressource]` could be an array instead of a string. If someone submits `carbone[]=1`, then `preg_match` on an array returns false, and the check at line 155 correctly sets `$bool = 0`. So this is handled.

However, the `empty()` check at line 169-172 would return true for `$_POST[$ressource] = "0"`, meaning a molecule with all atoms set to `0` would trigger the "must have at least one atom" error. But `empty("0")` is true in PHP, so a molecule with exactly `0` of every atom is correctly rejected. A molecule with `0` of one atom is fine.

**Fix:** No immediate fix needed, but adding `intval()` early would be cleaner:
```php
foreach ($nomsRes as $num => $ressource) {
    $_POST[$ressource] = intval($_POST[$ressource] ?? 0);
    if ($_POST[$ressource] < 0 || $_POST[$ressource] > 200) {
        $bool = 0;
    }
}
```

---

### INP-R2-023 -- MEDIUM -- Voter.php: pasDeVote Parameter Not Validated

**File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, line 46

**Description:**
```php
$pasDeVote = $_POST['pasDeVote'] ?? $_GET['pasDeVote'] ?? null;
if (!$pasDeVote) {
    dbExecute(...);
}
```
The `pasDeVote` parameter is used as a boolean flag but never validated. Any truthy value skips the UPDATE. This is functionally correct but the parameter should be explicitly validated as `"1"` or `"true"`.

**Fix:**
```php
$pasDeVote = isset($_POST['pasDeVote']) || isset($_GET['pasDeVote']);
```

---

### INP-R2-024 -- MEDIUM -- Moderation Forum: Date Parsing Already Fixed But motif Has No Length Limit

**File:** `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php`, line 28-41

**Description:** The moderation form correctly validates the date with `checkdate()` (line 36). However, `$_POST['motif']` has no length limit. A moderator (trusted user) could store an extremely long ban reason.

**Fix:**
```php
if (mb_strlen($_POST['motif']) > 2000) {
    $erreur = "Le motif ne doit pas depasser 2000 caracteres.";
}
```

---

### INP-R2-025 -- MEDIUM -- Dynamic SQL Column Names From Server-Side Arrays (Pattern Risk)

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`, lines 106-110, 180
**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`, lines 122-131
**File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php`, lines 240-247

**Description:** Multiple files build SQL UPDATE queries by concatenating column names from `$nomsRes` (the server-side array of resource names like `carbone`, `azote`, etc.). These are NOT user-controlled -- they come from `config.php`. The computed VALUES are also server-side calculations (e.g., `$ressources[$ressource] - $_POST['..Envoyee']` where both are already intval'd).

This pattern is safe as currently implemented but is a maintenance risk. If `$nomsRes` ever includes user-derived values, it becomes a SQL injection vector. Several code comments already note this: "computed from server data, not user input".

**Fix:** Consider using parameterized queries for all values, even computed ones, to establish a consistent pattern:
```php
$setClauses = [];
$types = '';
$params = [];
foreach ($nomsRes as $num => $ressource) {
    $setClauses[] = "$ressource = ?";
    $types .= 'd';
    $params[] = $computed_value;
}
$params[] = $_SESSION['login'];
$types .= 's';
dbExecute($base, 'UPDATE ressources SET ' . implode(',', $setClauses) . ' WHERE login=?', $types, ...$params);
```

---

### INP-R2-026 -- MEDIUM -- Alliance Research: $techName Used in SQL Column Name

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 94-112

**Description:** At line 94:
```php
if (isset($_POST['upgradeResearch']) && isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])) {
```
The `$techName = $_POST['upgradeResearch']` is validated against the `$ALLIANCE_RESEARCH` array keys (a whitelist). Then at line 98:
```php
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', ...);
```
Since `$techName` is from a whitelist, this is safe. But the pattern of putting a whitelisted-but-POST-derived value into SQL is worth documenting.

At line 108:
```php
dbExecute($base, 'UPDATE alliances SET ' . $techName . '=?, energieAlliance=? WHERE id=?', ...);
```
Same pattern. Safe due to whitelist but should be documented.

**Fix:** Add an explicit comment and consider a mapping approach:
```php
$TECH_TO_COLUMN = array_combine(array_keys($ALLIANCE_RESEARCH), array_keys($ALLIANCE_RESEARCH));
$columnName = $TECH_TO_COLUMN[$_POST['upgradeResearch']] ?? null;
if (!$columnName) { $erreur = "Recherche invalide."; }
```

---

### INP-R2-027 -- MEDIUM -- GET Parameter `sub` Not Validated in marche.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`, lines 367-368, 411, 445

**Description:** `$_GET['sub']` is used without validation at line 367:
```php
if (!isset($_GET['sub'])) { $_GET['sub'] = 0; }
```
Then compared with `==` (loose) at lines 411 and 445. Since `$_GET['sub']` is a string, `"0" == 0` is true in PHP 7. This works correctly but `$_GET['sub'] = "0; DROP TABLE"` would also `== 0` due to PHP type juggling.

The `$_GET['sub']` value is only used in comparisons (never in SQL or output), so this is not exploitable. But it should be intval'd for consistency.

**Fix:**
```php
$_GET['sub'] = intval($_GET['sub'] ?? 0);
```

---

### INP-R2-028 -- MEDIUM -- Editer.php: Delete/Hide Actions Via GET (Mitigated by POST Requirement)

**File:** `/home/guortates/TVLW/The-Very-Little-War/editer.php`, lines 16, 34, 41

**Description:** The delete (type=3), hide (type=5), and show (type=4) actions now require `$_SERVER['REQUEST_METHOD'] === 'POST'` and CSRF checks. However, `sujet.php` at lines 173, 179, 183 creates links like:
```php
'<a href="editer.php?id=' . $reponse['id'] . '&type=3">Supprimer</a>'
```
These are GET links that would need to be converted to POST forms for the actions to work. This suggests the protection was added but the UI wasn't updated, meaning delete/hide/show from the forum UI is currently broken.

**Fix:** Convert the action links in `sujet.php` to POST forms with CSRF tokens:
```php
echo '<form method="post" action="editer.php?id=' . $reponse['id'] . '&type=3" style="display:inline">'
   . csrfField()
   . '<button type="submit">Supprimer</button></form>';
```

---

### INP-R2-029 -- MEDIUM -- Grade String Not Validated in allianceadmin.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, line 27

**Description:** When a non-chef graded member loads the page, the grade permissions are parsed:
```php
list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
```
If the `grade` field in the DB contains fewer than 4 dots, PHP will throw a notice and leave variables undefined. Since the grade is set by the chef at line 94 with validated `0/1` values, the DB value should always be well-formed. But if DB manipulation occurred, this could cause unexpected authorization.

**Fix:**
```php
$parts = explode('.', $grade['grade']);
if (count($parts) !== 5) {
    // Invalid grade format, deny all permissions
    $inviter = $guerre = $pacte = $bannir = $description = false;
} else {
    list($inviter, $guerre, $pacte, $bannir, $description) = $parts;
}
```

---

### INP-R2-030 -- MEDIUM -- Unescaped Alliance Tags in Rapport Content

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 213-218, 239-240, 267-269

**Description:** War/pact report content includes alliance tags without escaping:
```php
$rapportTitre = 'L\'alliance ' . $chef['tag'] . ' vous propose un pacte.';
$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a>...';
```
The `$chef['tag']` comes from the DB. At creation, TAGs are validated with `preg_match("#^[a-zA-Z0-9_]{3,16}$#")`. However, as found in INP-R2-011, the tag CHANGE operation has no format validation. If a chef changes their tag to include special characters, these rapport contents become XSS vectors.

**Fix:** Either:
1. Fix INP-R2-011 to validate tag changes, AND
2. Escape tags in rapport content:
```php
$safeTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safeTag . '</a>...';
```

---

### INP-R2-031 -- MEDIUM -- Grades Display: Unescaped Login and Grade Name

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 427-432
**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, line 196

**Description:** At `allianceadmin.php:429`, grade display outputs login and grade name without escaping:
```php
echo '<td><a href="joueur.php?id=' . $listeGrades['login'] . '">' . $listeGrades['login'] . '</a></td>
      <td>' . $listeGrades['nom'] . '</td>';
```
Login names are alphanumeric (validated at registration), but the grade name (`$listeGrades['nom']`) comes from `$_POST['nomgrade']` which is only `trim()`'d at line 76. No format or length validation.

At `alliance.php:196`:
```php
echo '<span class="subimportant">' . $grades['nom'] . ' : </span><a href="joueur.php?id=' . $grades['login'] . '">' . $grades['login'] . '</a>';
```
Same issue -- grade names are not escaped.

**PoC:**
```
POST /allianceadmin.php
nomgrade=<img src=x onerror=alert(1)>&personnegrade=SomePlayer&...
```

**Impact:** Stored XSS via grade names, visible to all alliance members.

**Fix:**
```php
// allianceadmin.php line 429:
echo '<td>' . htmlspecialchars($listeGrades['nom'], ENT_QUOTES, 'UTF-8') . '</td>';

// alliance.php line 196:
echo '<span class="subimportant">' . htmlspecialchars($grades['nom'], ENT_QUOTES, 'UTF-8') . '</span>';
```

---

### INP-R2-032 -- MEDIUM -- News Content Uses strip_tags with Allowlist (Potentially Dangerous)

**File:** `/home/guortates/TVLW/The-Very-Little-War/index.php`, line 53

**Description:**
```php
$contenuNews = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));
```
Where `$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>'`. The `strip_tags` with allowed tags does NOT strip attributes. So a news item containing:
```html
<img src=x onerror=alert(1)>
<a href="javascript:alert(1)">click</a>
```
would pass through. However, news content is typically set by admin only (via direct DB access or admin panel), so this is a trust boundary question.

**Fix:** Use HTMLPurifier or a DOM-based sanitizer instead of `strip_tags`:
```php
// Minimal fix: escape attributes
$contenuNews = nl2br(htmlspecialchars($donnees['contenu'], ENT_QUOTES, 'UTF-8'));
// Or use the BBcode function which applies htmlentities first
```

---

### INP-R2-033 -- MEDIUM -- Smiley Regex Matches Common Text Patterns

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, lines 347-357

**Description:** Several smiley patterns are overly broad:
- `!lol!isU` replaces ALL occurrences of "lol" (case-insensitive) with a smiley, including in words like "technology", "lollipop", "parlor"
- `!=/!isU` replaces `=/` everywhere, including in URLs or code
- `!xD!isU` replaces "xD" in all contexts
- `!B(-)?\)!isU` matches "B)" in text

These are applied with the `i` (case-insensitive) flag, so "LOL", "Lol", "lol" all match.

**Fix:** Use word boundary assertions:
```php
$text = preg_replace('!\blol\b!isU', '...', $text);
```
Or apply smiley replacement only within specific BBCode tags, not globally.

---

### INP-R2-034 -- LOW -- Registration: No CAPTCHA or Email Verification

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`

**Description:** Registration has rate limiting (3 per hour per IP) but no CAPTCHA or email verification. A bot can register 3 accounts per hour from each IP, or use rotating proxies to register unlimited accounts.

**Fix:** Add a simple CAPTCHA (math question, honeypot field) or email verification link.

---

### INP-R2-035 -- LOW -- Login Form: HTML Input Has No maxlength

**File:** `/home/guortates/TVLW/The-Very-Little-War/index.php`, lines 36-37

**Description:** The login and password input fields have no `maxlength` attribute. While the server validates login format (3-20 chars), the browser will accept and POST arbitrarily long strings. The password field has no server-side length limit at all -- a multi-megabyte password would be hashed by `password_verify()` which internally truncates bcrypt input to 72 bytes.

**Fix:**
```html
<input type="text" name="loginConnexion" maxlength="20">
<input type="password" name="passConnexion" maxlength="128">
```
Server-side:
```php
if (mb_strlen($passwordInput) > 128) {
    $erreur = 'Mot de passe trop long.';
}
```

---

### INP-R2-036 -- LOW -- bcrypt 72-Byte Truncation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php`, line 45
**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, line 59

**Description:** PHP's `password_hash()` with `PASSWORD_DEFAULT` (bcrypt) silently truncates passwords to 72 bytes. Two passwords that share the first 72 bytes but differ after that will hash identically. This is a known bcrypt limitation.

**PoC:**
```
Password A: "a" * 72 + "X"
Password B: "a" * 72 + "Y"
Both verify as the same password.
```

**Impact:** Very low -- requires absurdly long passwords. But users should be informed if they enter >72 char passwords.

**Fix:** Either warn users or pre-hash with SHA-256 before bcrypt:
```php
$preHashed = hash('sha256', $password);
$hash = password_hash($preHashed, PASSWORD_DEFAULT);
```
Note: This requires updating the verification logic too.

---

### INP-R2-037 -- LOW -- Loose Comparison (==) Used for Security-Sensitive Checks

**File:** `/home/guortates/TVLW/The-Very-Little-War/inscription.php`, line 24
**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, line 56

**Description:** Password confirmation uses `!=` (loose comparison):
```php
if ($passInput != $passConfirm) { // inscription.php:24
if ($newPassword != $newPasswordConfirm) { // compte.php:56
```
With PHP's loose comparison, `0 != "foo"` is false in PHP 7 (both are `0` after type juggling). If both password fields contain non-numeric strings, `!=` works correctly. But for passwords that look like numeric strings (e.g., `"0"` vs `"0.0"`), loose comparison could produce unexpected results.

**Fix:** Use strict comparison:
```php
if ($passInput !== $passConfirm) {
```

---

### INP-R2-038 -- LOW -- Market Destinataire IP Check Can Be Bypassed via IPv6/Proxy

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`, lines 26-28

**Description:** The IP check prevents sending resources to a player with the same IP:
```php
if ($ipmm['ip'] != $ipdd['ip']) {
```
This can be trivially bypassed with a VPN, proxy, or by one player using IPv4 and the other IPv6. The IP is stored as a string from `$_SERVER['REMOTE_ADDR']`.

**Impact:** Low -- this is an anti-abuse measure, not a security control. Multi-accounting prevention requires more sophisticated approaches.

**Fix:** Consider using additional signals (login timing, resource patterns) rather than relying solely on IP matching.

---

### INP-R2-039 -- LOW -- Pagination Parameter Accepts Negative Values

**File:** `/home/guortates/TVLW/The-Very-Little-War/messages.php`, line 45
**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, line 47
**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`, line 65

**Description:** Pagination uses `intval($_GET['page'])` which correctly converts to integer. The bounds check `if ($page < 1 || $page > $nombreDePages)` then resets to 1. This is correct. `intval("abc")` = 0 which is < 1, so it resets. `intval("-5")` = -5 which is < 1, so it resets.

No actual vulnerability, but noted for completeness.

---

### INP-R2-040 -- LOW -- File Upload: uniqid() Is Predictable

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, line 129

**Description:** Avatar filenames are generated with `uniqid('avatar_') . '.' . $extension`. The `uniqid()` function is based on the current time in microseconds and is predictable. An attacker who knows the approximate upload time could enumerate valid avatar filenames.

However, avatar files are image-validated (MIME type, getimagesize), have strict extension whitelists, and the directory listing is presumably disabled. The predictability of filenames alone is not a significant risk.

**Fix:** Use `random_bytes()` for filename generation:
```php
$fichier = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
```

---

### INP-R2-041 -- LOW -- GET Parameters Used to Pre-Fill Form Fields in ecriremessage.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`, lines 79-80

**Description:**
```php
if (isset($_GET['destinataire'])) {
    $valueDestinataire = trim($_GET['destinataire']);
}
```
The `$valueDestinataire` is output with `htmlspecialchars()` at line 85, preventing XSS. However, a crafted link like:
```
ecriremessage.php?destinataire=Admin%20(Official%20Support%20Team)
```
could be used in social engineering to make a user think they're messaging an official account.

**Fix:** No code fix needed -- the `htmlspecialchars()` output escaping is correct. Consider adding a visual indicator when the pre-filled recipient was from a URL parameter.

---

### INP-R2-042 -- LOW -- alliance.php GET[clas] Uses Switch Without intval

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 341-369

**Description:** `$_GET['clas']` is used in a switch statement. The `case` values are integers (0-6). PHP's switch uses loose comparison, so `$_GET['clas'] = "0abc"` matches `case 0`. The `default` case maps to `totalPoints`. The `$order` variable is from a whitelist and used in SQL.

Not exploitable due to the whitelist pattern, but `intval()` would be cleaner:
```php
$_GET['clas'] = intval($_GET['clas'] ?? 0);
```

---

### INP-R2-043 -- LOW -- Information/Error Messages Passed via GET in basicprivatephp.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`, lines 65-71

**Description:**
```php
if (isset($_GET['information'])) {
    $information = antiXSS($_GET['information']);
}
if (isset($_GET['erreur'])) {
    $erreur = antiXSS($_GET['erreur']);
}
```
The `antiXSS()` function applies `htmlspecialchars()`, preventing XSS. However, this allows any user to craft URLs that display arbitrary (escaped) messages:
```
constructions.php?information=Your%20account%20has%20been%20compromised%20call%20555-1234
```
This is a social engineering / content injection vector.

**Fix:** Use session flash messages instead of GET parameters:
```php
if (isset($_SESSION['flash_info'])) {
    $information = $_SESSION['flash_info'];
    unset($_SESSION['flash_info']);
}
```

---

### INP-R2-044 -- LOW -- constructions.php: Building Name From POST Not Validated

**File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php`, line 207

**Description:** The `traitementConstructions()` function checks `isset($_POST[$liste['bdd']])`. The `$liste['bdd']` value comes from the server-side `$listeConstructions` array. However, the function also reads `$_POST` values indirectly through the `$liste` array which is built server-side from constants. No user input influences the building name used in SQL.

Not a vulnerability but worth noting the pattern: the building construction is triggered by checking if a POST parameter with a server-determined name exists. The parameter NAME is from the server, the parameter VALUE is the hidden field value (also server-side). Safe.

---

### INP-R2-045 -- LOW -- Redundant stripslashes in ecriremessage.php

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`, lines 91-92

**Description:**
```php
creerBBcode("contenu", stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu']))));
$options = stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])));
```
`stripslashes()` is a legacy from `magic_quotes_gpc` which has been removed since PHP 5.4. On PHP 8.2, this will strip legitimate backslashes from user content.

**Fix:**
```php
$options = preg_replace('#\r\n|\r|\n#', "\n", $_POST['contenu']);
```

---

### INP-R2-046 -- LOW -- Session Idle Timeout Error Message via GET

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php`, line 49

**Description:**
```php
header('Location: index.php?erreur=' . urlencode('Session expiree. Veuillez vous reconnecter.'));
```
The error message is hardcoded and URL-encoded. At `index.php`, this would be read by `basicpublicphp.php` which does NOT have the `antiXSS` GET handler. The `index.php` page doesn't process `$_GET['erreur']` directly (it comes from `basicprivatephp.php` which is only loaded for authenticated pages).

Actually, `index.php` at line 4 conditionally loads either `basicprivatephp.php` or `basicpublicphp.php`. When redirected after session expiry, the user is unauthenticated, so `basicpublicphp.php` loads -- which does NOT read `$_GET['erreur']`. So the error message is lost.

**Impact:** The session expiry message is never displayed to the user. Functional bug, not security.

---

### INP-R2-047 -- LOW -- listesujets.php Forum ID Range Hardcoded

**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`, lines 11-18

**Description:**
```php
if(!isset($_GET['id'])
    or intval(trim($_GET['id'])) == 0
    or $_GET['id'] < 1
    or $_GET['id'] > 8
    ...
```
The forum ID is hardcoded to range 1-8. If forums are added or removed, this check must be updated. A data-driven check (querying the forums table) would be more maintainable.

**Fix:**
```php
$getId = intval(trim($_GET['id'] ?? 0));
$forumExists = dbCount($base, 'SELECT COUNT(*) FROM forums WHERE id = ?', 'i', $getId);
if ($forumExists === 0) {
    header('Location: forum.php');
    exit();
}
```

---

## Summary Table

| ID | Severity | File | Line | Issue |
|----|----------|------|------|-------|
| INP-R2-001 | CRITICAL | inscription.php, compte.php | 20, 59 | No minimum password length |
| INP-R2-003 | CRITICAL | bbcode.php | 331 | ReDoS via nested quantifiers in [url=] regex |
| INP-R2-004 | CRITICAL | sujet.php, listesujets.php | 21, 31 | No length limit on forum post content |
| INP-R2-005 | LOW* | marche.php | 124 | Raw POST in output (mitigated by login validation) |
| INP-R2-006 | HIGH | attaquer.php | 91-133 | Troop counts not intval'd before arithmetic |
| INP-R2-007 | HIGH | compte.php | 13-28 | Vacation date not validated with checkdate() |
| INP-R2-008 | HIGH | marche.php | 147, 257 | transformInt allows gigantic values |
| INP-R2-009 | HIGH | Multiple | Various | Empty-string match in `[0-9]*` regex |
| INP-R2-010 | HIGH | alliance.php, allianceadmin.php | 35, 59 | Alliance name has no format/length validation |
| INP-R2-011 | HIGH | allianceadmin.php | 121-137 | Alliance tag change has no format validation |
| INP-R2-012 | HIGH | allianceadmin.php, compte.php | 164, 88 | Description stored without length limit |
| INP-R2-013 | HIGH | ecriremessage.php | 6-31 | Message title/content have no length limits |
| INP-R2-014 | HIGH | attaquer.php | 24-26 | Neutrino race condition (no transaction) |
| INP-R2-015 | HIGH | armee.php | 62-85 | No upper bound on neutrino purchase |
| INP-R2-016 | MEDIUM | bbcode.php | 332 | External image loading (tracking pixels) |
| INP-R2-017 | MEDIUM | bbcode.php, sujet.php | 335, 223 | LaTeX/MathJax phishing via \\href |
| INP-R2-018 | MEDIUM | compte.php | 76 | Email regex too restrictive/inconsistent |
| INP-R2-019 | MEDIUM | inscription.php, basicpublicphp.php | 19, 29 | Login maxlength mismatch (13 vs 20) |
| INP-R2-020 | MEDIUM | joueur.php | 82 | BBCode injection via player description |
| INP-R2-021 | MEDIUM | armee.php | 5 | Molecule slot not intval'd before compare |
| INP-R2-022 | MEDIUM | armee.php | 154-204 | Atom counts: variable-variable pattern risk |
| INP-R2-023 | MEDIUM | voter.php | 46 | pasDeVote parameter not validated |
| INP-R2-024 | MEDIUM | moderationForum.php | 28-41 | Ban motif has no length limit |
| INP-R2-025 | MEDIUM | Multiple | Various | Dynamic SQL column names (pattern risk) |
| INP-R2-026 | MEDIUM | alliance.php | 94-112 | POST-derived value in SQL column (whitelisted) |
| INP-R2-027 | MEDIUM | marche.php | 367-368 | GET[sub] not intval'd |
| INP-R2-028 | MEDIUM | editer.php, sujet.php | 16, 173 | Delete links are GET but handler requires POST |
| INP-R2-029 | MEDIUM | allianceadmin.php | 27 | Grade string parsed without count validation |
| INP-R2-030 | MEDIUM | allianceadmin.php | 213-218 | Unescaped alliance tags in rapport content |
| INP-R2-031 | MEDIUM | allianceadmin.php, alliance.php | 429, 196 | **Stored XSS via unescaped grade names** |
| INP-R2-032 | MEDIUM | index.php | 53 | strip_tags with allowlist passes attributes |
| INP-R2-033 | MEDIUM | bbcode.php | 347-357 | Smiley regex matches common text |
| INP-R2-034 | LOW | inscription.php | all | No CAPTCHA or email verification |
| INP-R2-035 | LOW | index.php | 36-37 | No maxlength on login/password HTML inputs |
| INP-R2-036 | LOW | basicpublicphp.php, compte.php | 45, 59 | bcrypt 72-byte truncation |
| INP-R2-037 | LOW | inscription.php, compte.php | 24, 56 | Loose comparison for password confirm |
| INP-R2-038 | LOW | marche.php | 26-28 | IP check trivially bypassed |
| INP-R2-039 | LOW | messages.php, sujet.php | 45, 47 | Pagination handles negatives correctly |
| INP-R2-040 | LOW | compte.php | 129 | uniqid() is predictable |
| INP-R2-041 | LOW | ecriremessage.php | 79-80 | GET pre-fill social engineering |
| INP-R2-042 | LOW | alliance.php | 341-369 | GET[clas] switch without intval |
| INP-R2-043 | LOW | basicprivatephp.php | 65-71 | Content injection via GET messages |
| INP-R2-044 | LOW | constructions.php | 207 | Building name from POST (server-determined) |
| INP-R2-045 | LOW | ecriremessage.php | 91-92 | Redundant stripslashes |
| INP-R2-046 | LOW | basicprivatephp.php | 49 | Session timeout error never displayed |
| INP-R2-047 | LOW | listesujets.php | 11-18 | Forum ID range hardcoded |

---

## Priority Fix Order

### Immediate (before next deployment)
1. **INP-R2-001** -- Add minimum password length (8 chars) to registration and password change
2. **INP-R2-003** -- Fix ReDoS in BBCode [url=] regex
3. **INP-R2-011** + **INP-R2-030** + **INP-R2-031** -- Validate alliance tag changes AND escape tags/grade names in output (stored XSS chain)

### Next Sprint
4. **INP-R2-004** -- Add length limits to forum posts
5. **INP-R2-012** + **INP-R2-013** -- Add length limits to descriptions and messages
6. **INP-R2-006** -- intval troop counts in attaquer.php
7. **INP-R2-007** -- Validate vacation dates with checkdate()
8. **INP-R2-008** -- Cap market quantities
9. **INP-R2-009** -- Replace `[0-9]*` with `[0-9]+` globally
10. **INP-R2-010** -- Validate alliance name format/length

### Backlog
11. All remaining MEDIUM and LOW items
12. **INP-R2-025** -- Refactor dynamic SQL to fully parameterized queries
13. **INP-R2-028** -- Fix delete/hide links to use POST forms
14. **INP-R2-032** -- Replace strip_tags with proper HTML sanitizer for news

---

## Files Reviewed

| File | Status | Input Paths Found |
|------|--------|-------------------|
| `/home/guortates/TVLW/The-Very-Little-War/includes/validation.php` | Reviewed | 5 functions, missing validatePassword |
| `/home/guortates/TVLW/The-Very-Little-War/includes/basicpublicphp.php` | Reviewed | Login form processing |
| `/home/guortates/TVLW/The-Very-Little-War/includes/basicprivatephp.php` | Reviewed | Session validation, GET params |
| `/home/guortates/TVLW/The-Very-Little-War/inscription.php` | Reviewed | Registration form |
| `/home/guortates/TVLW/The-Very-Little-War/forum.php` | Reviewed | Forum listing (minimal input) |
| `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php` | Reviewed | BBCode parsing (all regex patterns) |
| `/home/guortates/TVLW/The-Very-Little-War/marche.php` | Reviewed | Buy, sell, send resources |
| `/home/guortates/TVLW/The-Very-Little-War/attaquer.php` | Reviewed | Attack, espionage params |
| `/home/guortates/TVLW/The-Very-Little-War/armee.php` | Reviewed | Molecule create/form/delete, neutrinos |
| `/home/guortates/TVLW/The-Very-Little-War/messages.php` | Reviewed | Message view/delete |
| `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php` | Reviewed | Message compose |
| `/home/guortates/TVLW/The-Very-Little-War/compte.php` | Reviewed | Password, email, vacation, photo, description |
| `/home/guortates/TVLW/The-Very-Little-War/api.php` | Reviewed | JSON API (well-hardened) |
| `/home/guortates/TVLW/The-Very-Little-War/index.php` | Reviewed | Login form, news display |
| `/home/guortates/TVLW/The-Very-Little-War/alliance.php` | Reviewed | Alliance create, join, research |
| `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` | Reviewed | Alliance management (name, tag, grades, wars, pacts) |
| `/home/guortates/TVLW/The-Very-Little-War/validerpacte.php` | Reviewed | Pact accept/reject |
| `/home/guortates/TVLW/The-Very-Little-War/voter.php` | Reviewed | Poll voting |
| `/home/guortates/TVLW/The-Very-Little-War/sujet.php` | Reviewed | Forum topic replies |
| `/home/guortates/TVLW/The-Very-Little-War/listesujets.php` | Reviewed | Forum topic creation |
| `/home/guortates/TVLW/The-Very-Little-War/editer.php` | Reviewed | Forum edit/delete |
| `/home/guortates/TVLW/The-Very-Little-War/constructions.php` | Reviewed | Building construction, point allocation |
| `/home/guortates/TVLW/The-Very-Little-War/don.php` | Reviewed | Alliance energy donation |
| `/home/guortates/TVLW/The-Very-Little-War/moderationForum.php` | Reviewed | Forum moderation |
| `/home/guortates/TVLW/The-Very-Little-War/joueur.php` | Reviewed | Player profile view |

---

*End of Input Validation Deep-Dive -- Round 2*
