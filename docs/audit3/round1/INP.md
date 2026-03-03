# INP -- Input Validation Audit (Round 1)

**Auditor:** Claude Opus 4.6
**Date:** 2026-03-03
**Scope:** All PHP files handling user input (~40 files)
**Codebase:** /home/guortates/TVLW/The-Very-Little-War/

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 12    |
| MEDIUM   | 24    |
| LOW      | 15    |
| **Total**| **53**|

---

## CRITICAL

**[INP-R1-001] [CRITICAL] comptetest.php:51 -- Login regex matches empty string, allows zero-length login registration**

The regex `#^[A-Za-z0-9]*$#` uses `*` (zero or more) instead of `+` (one or more). Combined with the outer `!empty()` check on line 46, an all-whitespace login would be trimmed to empty after `ucfirst(mb_strtolower())` on line 53 but the regex itself would still pass. More critically, there is no minimum or maximum length validation on the login, unlike `inscription.php` which uses `validateLogin()` requiring 3-20 characters. A 1-character or 200-character login can be registered.

```php
// comptetest.php:51
if (preg_match("#^[A-Za-z0-9]*$#", $_POST['login'])) {
```

**Recommendation:** Replace with `validateLogin()` from `includes/validation.php` which enforces `/^[a-zA-Z0-9_]{3,20}$/`.

---

**[INP-R1-002] [CRITICAL] compte.php:14 -- Vacation date parsed from POST without format validation, causes uncaught exception**

`explode('/', $_POST['dateFin'])` assumes exactly 3 parts in `dd/mm/yyyy` format. Malformed input (e.g., `"abc"`, `"01-02-2025"`, `""`) will cause `list()` to fail or produce undefined behavior when passed to `DateTime::setDate()` with garbage values. No `checkdate()` or format validation is performed, unlike `moderationForum.php:36` which properly validates with `checkdate()`.

```php
// compte.php:14
list($jour, $mois, $annee) = explode('/', $_POST['dateFin']);
$dateT = new DateTime();
$dateT->setDate($annee, $mois, $jour);
```

**Recommendation:** Validate format and date values before processing:
```php
$parts = explode('/', $_POST['dateFin']);
if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) {
    $erreur = "Date invalide.";
} else { ... }
```

---

## HIGH

**[INP-R1-003] [HIGH] comptetest.php:51 -- No minimum password length on visitor-to-full account conversion**

When a visitor converts to a full account, the password is accepted with no minimum length. A single-character or empty (after the `!empty()` check passes) password can be set with `password_hash()`. This is especially risky because visitor accounts auto-login and users may set weak passwords.

```php
// comptetest.php:46-63
if ((isset($_POST['pass']) && !empty($_POST['pass'])) ...
    $hashedPassword = password_hash($_POST['pass'], PASSWORD_DEFAULT);
```

**Recommendation:** Enforce a minimum password length (e.g., 8 characters) before hashing.

---

**[INP-R1-004] [HIGH] compte.php:33-59 -- No minimum password length on password change**

The password change form on `compte.php` accepts any non-empty password with no minimum length requirement.

```php
// compte.php:33-59
if (!empty($_POST['changermdp']) && !empty($_POST['changermdp1'])) {
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
```

**Recommendation:** Add `strlen($newPassword) >= 8` check before accepting the new password.

---

**[INP-R1-005] [HIGH] comptetest.php:52 -- Email regex is case-sensitive and rejects valid TLDs**

The regex `#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#` only matches lowercase characters, so `User@Example.COM` would fail. It also limits TLDs to 2-4 characters, rejecting valid modern TLDs like `.museum`, `.technology`, `.photography`. The proper `validateEmail()` using `filter_var(FILTER_VALIDATE_EMAIL)` exists in `validation.php` but is not used here.

```php
// comptetest.php:52
if (preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", $_POST['email'])) {
```

**Recommendation:** Use `validateEmail()` from `includes/validation.php`.

---

**[INP-R1-006] [HIGH] compte.php:76 -- Email change uses same weak regex instead of validateEmail()**

Same case-sensitive, TLD-limited regex as comptetest.php for the email change flow.

```php
// compte.php:76
if (preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", $_POST['changermail'])) {
```

**Recommendation:** Use `validateEmail()` from `includes/validation.php`.

---

**[INP-R1-007] [HIGH] attaquer.php:95 -- Troop count compared without intval, type juggling risk**

`$_POST['nbclasse'.$i]` is compared with `< 0` without prior `intval()` cast. While `intval()` is applied later on line 103, the initial comparison on line 95 operates on a raw string, which in PHP 8.x will compare as string vs int. Non-numeric strings like `"abc"` will evaluate as `0 < 0` = false (correct), but edge cases like `"-1abc"` would compare correctly only because PHP casts to int in comparison context. The inconsistency is error-prone.

```php
// attaquer.php:91-95
if (!isset($_POST['nbclasse' . $i])) {
    $_POST['nbclasse' . $i] = 0;
}
if ($_POST['nbclasse' . $i] < 0) {
    $troupesPositives = false;
}
```

**Recommendation:** Apply `intval()` immediately after the `isset` default on line 92:
```php
$_POST['nbclasse' . $i] = intval($_POST['nbclasse' . $i] ?? 0);
```

---

**[INP-R1-008] [HIGH] attaquer.php:257-258 -- $_GET['type'] not cast to int, used in loose comparisons**

`$_GET['type']` is set to `0` if not present but never explicitly cast to `(int)`. It is then used in loose `==` comparisons on lines 261, 383, 496. While PHP's loose comparison handles this correctly for simple numeric strings, type juggling with values like `"0e1"` or `"0x0"` could cause unexpected matches.

```php
// attaquer.php:257-258
if (!isset($_GET['type'])) {
    $_GET['type'] = 0;
}
// line 261: if ($_GET['type'] == 0) {
// line 383: if ($_GET['type'] == 1) {
// line 496: } elseif ($_GET['type'] == 2) {
```

**Recommendation:** Cast to int: `$_GET['type'] = isset($_GET['type']) ? (int)$_GET['type'] : 0;`

---

**[INP-R1-009] [HIGH] attaquer.php:376 -- $_GET['id'] trimmed but not cast to int before DB lookup**

`$_GET['id']` is trimmed but used directly as a string in the database query. While the prepared statement with `'s'` type handles SQL injection, the value is used as a player login name. An attacker could pass arbitrary strings to look up non-existent players, wasting DB queries. Additionally, this value is later used in HTML output contexts.

```php
// attaquer.php:375-378
if (isset($_GET['id'])) {
    $_GET['id'] = trim($_GET['id']);
    $ex = dbQuery($base, 'SELECT * FROM membre WHERE login=?', 's', $_GET['id']);
```

**Recommendation:** Validate format with `validateLogin()` or at minimum `preg_match('/^[a-zA-Z0-9_]{1,20}$/', $_GET['id'])`.

---

**[INP-R1-010] [HIGH] messages.php:6 -- Weak delete validation regex matches any string containing a digit**

The regex `#\d#` only checks if the string contains at least one digit anywhere. Values like `"delete_1_all"`, `"1'; DROP TABLE--"` would pass this check. While the prepared statement prevents SQL injection, the logic is wrong: `$_POST['supprimer'] == 1` (line 8) triggers mass deletion of all messages when any string containing "1" is sent.

```php
// messages.php:6
if(isset($_POST['supprimer']) AND preg_match("#\d#",$_POST['supprimer'])) {
```

**Recommendation:** Use `#^\d+$#` to require the entire value to be numeric, and use strict `===` comparison for the mass-delete check.

---

**[INP-R1-011] [HIGH] rapports.php:5 -- Same weak delete validation regex as messages.php**

Identical issue to INP-R1-010. A string containing any digit passes the check, and `== 1` triggers mass deletion.

```php
// rapports.php:5
if(isset($_POST['supprimer']) AND preg_match("#\d#",$_POST['supprimer'])) {
```

**Recommendation:** Same fix as INP-R1-010.

---

**[INP-R1-012] [HIGH] admin/listenews.php:40 -- csrfCheck() called unconditionally on every request including GET**

`csrfCheck()` is called at line 40 before the POST checks. On GET requests to view the news list, this will either fail (if CSRF token not present) or silently pass. If the CSRF implementation requires a token on every call, GET requests to this page will fail.

```php
// admin/listenews.php:39-40
// CSRF check for all POST actions
csrfCheck();
```

**Recommendation:** Wrap in `if ($_SERVER['REQUEST_METHOD'] === 'POST')` guard, as done in other admin pages like `listesujets.php:34`.

---

**[INP-R1-013] [HIGH] admin/listenews.php:46-47 -- News title and content stored without any sanitization or length validation**

News title and content from POST are stored directly in the database with no length limit, no character validation, and no sanitization. While prepared statements prevent SQL injection, excessively long content could cause storage issues. Display-side `stripslashes()` is applied but no `htmlspecialchars()` on the content when displayed to end users on `index.php`.

```php
// admin/listenews.php:45-47
if (isset($_POST['titre']) and isset($_POST['contenu'])) {
    $titre = $_POST['titre'];
    $contenu = $_POST['contenu'];
```

**Recommendation:** Add length limits (e.g., title 200 chars, content 10000 chars) and ensure output encoding on display.

---

**[INP-R1-014] [HIGH] admin/index.php:180-184 -- Admin login form has no CSRF token**

The admin login form is rendered without a CSRF token. While CSRF on login forms is less critical than on authenticated actions, it enables login CSRF attacks where an attacker tricks an admin into logging into the attacker's admin session.

```php
// admin/index.php:180-183
<form action="index.php" method="post">
    <label for="motdepasseadmin">Mot de passe : </label>
    <input type="password" name="motdepasseadmin" id="motdepasseadmin" />
    <input type="submit" name="valider" value="Valider" />
</form>
```

**Recommendation:** Add `<?php echo csrfField(); ?>` inside the form and add `csrfCheck()` to the login handler.

---

## MEDIUM

**[INP-R1-015] [MEDIUM] armee.php:5 -- Regex #^[0-9]*$# matches empty string for molecule slot validation**

The regex `#^[0-9]*$#` allows an empty string to pass validation. While `!empty()` is checked first in the `and` chain, PHP's short-circuit evaluation means the empty check protects against this. However, this pattern is inconsistent and fragile -- if the condition chain is refactored, the empty check could be lost.

```php
// armee.php:5
if (isset($_POST['emplacementmoleculesupprimer']) and !empty(...)
    and preg_match("#^[0-9]*$#", $_POST['emplacementmoleculesupprimer']) ...
```

This same pattern appears at: armee.php:65, armee.php:87, armee.php:90, armee.php:155.

**Recommendation:** Use `#^[0-9]+$#` (one or more) consistently, or better, use `validatePositiveInt()` from `validation.php`.

---

**[INP-R1-016] [MEDIUM] armee.php:179 -- Variable variables from POST create dynamic local variables**

Inside the molecule creation loop, `$$ressource = $_POST[$ressource]` creates variable variables from user input. While `$ressource` iterates over the server-side `$nomsRes` array (safe), the value `$_POST[$ressource]` is assigned to a dynamically-named local variable. The values are validated by `preg_match("#^[0-9]*$#")` on line 155, but this pattern is hard to audit and maintain.

```php
// armee.php:177-179
foreach ($nomsRes as $num => $ressource) {
    if (!empty($_POST[$ressource])) {
        $$ressource = $_POST[$ressource];
```

**Recommendation:** Use an associative array instead of variable variables: `$atomCounts[$ressource] = intval($_POST[$ressource]);`

---

**[INP-R1-017] [MEDIUM] sujet.php:15 -- Regex #^[0-9]*$# matches empty string for forum topic ID**

Same empty-string regex issue as armee.php. A trimmed empty `$_GET['id']` would pass the regex on line 15 but `(int)""` would become `0`, leading to database queries with ID `0`.

```php
// sujet.php:14-15
$_GET['id'] = trim($_GET['id']);
if (preg_match("#^[0-9]*$#", $_GET['id'])) {
```

**Recommendation:** Use `#^[0-9]+$#` or `(int)$_GET['id'] > 0`.

---

**[INP-R1-018] [MEDIUM] marche.php:44 -- Regex #^[0-9]*$# matches empty string for resource donation amounts**

Same empty-string regex pattern. While `intval()` is called on line 40 before this check, the regex is redundant and misleading.

```php
// marche.php:44
if (!(preg_match("#^[0-9]*$#", $_POST[$ressource . 'Envoyee']))) {
```

**Recommendation:** Remove redundant regex check since `intval()` already converts to integer on line 40.

---

**[INP-R1-019] [MEDIUM] marche.php:124,126 -- Recipient name in info message without htmlspecialchars**

When displaying the donation confirmation, `$_POST['destinataire']` is interpolated into the `$information` string without `htmlspecialchars()`. While this is stored in a PHP variable (not directly echoed to HTML at this point), if `$information` is later output without encoding, it creates an XSS vector.

```php
// marche.php:124
$information = "Vous avez envoye ..." . $chaine . ' a ' . $_POST['destinataire'];
// marche.php:126
$information = "Vous avez envoye " . $chaine . " a " . $_POST['destinataire'];
```

**Recommendation:** Wrap with `htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8')`.

---

**[INP-R1-020] [MEDIUM] marche.php:367-368 -- $_GET['sub'] assigned 0 without intval**

`$_GET['sub']` is set to `0` if not present, but when present, it remains a string. It is then used in loose `==` comparisons on lines 411 and 445.

```php
// marche.php:367-368
if (!isset($_GET['sub'])) {
    $_GET['sub'] = 0;
}
```

**Recommendation:** `$_GET['sub'] = isset($_GET['sub']) ? (int)$_GET['sub'] : 0;`

---

**[INP-R1-021] [MEDIUM] allianceadmin.php:59 -- Alliance name has no length validation or character restriction**

Alliance name is only checked for `!empty()` and uniqueness. No maximum length limit, no character whitelist, and no minimum meaningful length beyond 1 character.

```php
// allianceadmin.php:58-63
if (!empty($_POST['changernom'])) {
    $_POST['changernom'] = trim($_POST['changernom']);
    $nballiance = dbCount($base, '... WHERE nom=?', 's', $_POST['changernom']);
    if ($nballiance == 0) {
        dbExecute($base, 'UPDATE alliances SET nom=? WHERE id=?', 'si', $_POST['changernom'], ...);
```

**Recommendation:** Add length validation (e.g., 2-50 characters) and optional character whitelist.

---

**[INP-R1-022] [MEDIUM] allianceadmin.php:123-128 -- Alliance tag change has no format validation**

When changing the alliance tag, only `!empty()` and uniqueness are checked. Unlike `alliance.php:38` which validates with `#^[a-zA-Z0-9_]{3,16}$#` on creation, the tag change skips format validation entirely.

```php
// allianceadmin.php:123-128
if (!empty($_POST['changertag'])) {
    $_POST['changertag'] = trim($_POST['changertag']);
    $nballiance = dbCount($base, '... WHERE tag=?', 's', $_POST['changertag']);
    if ($nballiance == 0) {
        dbExecute($base, 'UPDATE alliances SET tag=? WHERE id=?', 'si', $_POST['changertag'], ...);
```

**Recommendation:** Apply the same regex validation as alliance creation: `preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['changertag'])`.

---

**[INP-R1-023] [MEDIUM] allianceadmin.php:76 -- Grade name has no length or character validation**

Grade names are stored with only `!empty()` check. No length limit or character restriction.

```php
// allianceadmin.php:76-78
$_POST['nomgrade'] = trim($_POST['nomgrade']);
$_POST['personnegrade'] = ucfirst(trim($_POST['personnegrade']));
if (!empty($_POST['nomgrade']) and !empty($_POST['personnegrade'])) {
```

**Recommendation:** Add length limit (e.g., 3-30 characters) and validate against alphanumeric + spaces.

---

**[INP-R1-024] [MEDIUM] allianceadmin.php:167 -- Alliance description stored without length limit**

Alliance description has no maximum length constraint. The field is trimmed and checked for `!empty()` but a 100MB description could be submitted.

```php
// allianceadmin.php:166-168
$_POST['changerdescription'] = trim($_POST['changerdescription']);
dbExecute($base, 'UPDATE alliances SET description=? WHERE id=?', 'si',
    $_POST['changerdescription'], $idalliance['idalliance']);
```

**Recommendation:** Enforce a maximum length (e.g., 5000 characters).

---

**[INP-R1-025] [MEDIUM] compte.php:89 -- Player description stored without length limit**

Same issue as alliance description. No maximum length on profile description.

```php
// compte.php:89-90
$newDescription = trim($_POST['changerdescription']);
dbExecute($base, 'UPDATE autre SET description = ? WHERE login = ?', 'ss', $newDescription, ...);
```

**Recommendation:** Enforce a maximum length (e.g., 2000 characters).

---

**[INP-R1-026] [MEDIUM] ecriremessage.php:6-11 -- Message title and content stored without length limits**

Private messages have no maximum length on title or content. A player could send messages with megabytes of content.

```php
// ecriremessage.php:9-11
$_POST['titre'] = trim($_POST['titre']);
$_POST['destinataire'] = ucfirst(trim($_POST['destinataire']));
$_POST['contenu'] = trim($_POST['contenu']);
```

**Recommendation:** Add length limits (e.g., title 200 chars, content 10000 chars).

---

**[INP-R1-027] [MEDIUM] sujet.php:17-21 -- Forum post content stored without length limit**

Forum reply content has no maximum length. Combined with BBCode expansion, this could create very large stored HTML.

```php
// sujet.php:17-21
if (!empty($_POST['contenu'])) {
    dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)', 'issi',
        $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);
```

**Recommendation:** Add a content length limit (e.g., 20000 characters).

---

**[INP-R1-028] [MEDIUM] listesujets.php:29-31 -- Forum topic title and content stored without length limits**

Forum topic creation accepts title and content with no length validation.

```php
// listesujets.php:29-31
if (!empty($_POST['titre']) and !empty($_POST['contenu'])) {
    dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi',
        $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
```

**Recommendation:** Add length limits (e.g., title 200 chars, content 20000 chars).

---

**[INP-R1-029] [MEDIUM] alliance.php:35 -- Alliance name has no validation on creation**

When creating an alliance, the tag is validated with `#^[a-zA-Z0-9_]{3,16}$#` (line 38), but the alliance name has zero validation beyond `!empty()`.

```php
// alliance.php:35-36
$_POST['nomalliance'] = trim($_POST['nomalliance']);
$_POST['tagalliance'] = trim($_POST['tagalliance']);
```

**Recommendation:** Add length and character validation for alliance names.

---

**[INP-R1-030] [MEDIUM] alliance.php:27 -- $_GET['id'] used as alliance tag without format validation**

`$_GET['id']` is trimmed but never validated against the expected tag format `[a-zA-Z0-9_]{3,16}`. It is used directly in DB queries and display contexts.

```php
// alliance.php:27
$_GET['id'] = trim($_GET['id']);
```

**Recommendation:** Validate with `preg_match("#^[a-zA-Z0-9_]{1,16}$#", $_GET['id'])` or redirect on invalid format.

---

**[INP-R1-031] [MEDIUM] alliance.php:342-343 -- $_GET['clas'] used in switch without explicit cast**

`$_GET['clas']` is set to `0` if not present but not cast to `(int)`. While the `switch` statement has a `default` case, the loose comparison could be unexpected.

```php
// alliance.php:341-343
if (!isset($_GET['clas'])) {
    $_GET['clas'] = 0;
}
switch ($_GET['clas']) {
```

**Recommendation:** Cast to int: `$_GET['clas'] = isset($_GET['clas']) ? (int)$_GET['clas'] : 0;`

---

**[INP-R1-032] [MEDIUM] moderationForum.php:31 -- Ban pseudo field has no login format validation**

The moderator ban form accepts a pseudo without any format validation. While the player existence is checked via DB query, invalid characters in the pseudo will simply not match any player, wasting a query.

```php
// moderationForum.php:30-31
if (!empty($_POST['pseudo']) && !empty($_POST['dateFin']) && !empty($_POST['motif'])) {
    $nb = dbCount($base, 'SELECT count(*) FROM membre WHERE login = ?', 's', $_POST['pseudo']);
```

**Recommendation:** Validate with `validateLogin()` before the DB lookup.

---

**[INP-R1-033] [MEDIUM] moderationForum.php:41 -- Ban motif (reason) stored without length limit**

The ban reason is stored with no length constraint.

```php
// moderationForum.php:41
dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)', 'ssss',
    $_POST['pseudo'], $date, $_POST['motif'], $_SESSION['login']);
```

**Recommendation:** Add a reasonable length limit (e.g., 2000 characters).

---

**[INP-R1-034] [MEDIUM] voter.php:46 -- pasDeVote parameter used without validation**

`$_POST['pasDeVote']` / `$_GET['pasDeVote']` is used as a boolean flag without any type or value validation. Any truthy value skips the vote update.

```php
// voter.php:46-49
$pasDeVote = $_POST['pasDeVote'] ?? $_GET['pasDeVote'] ?? null;
if (!$pasDeVote) {
    dbExecute($base, 'UPDATE reponses SET reponse = ? WHERE login = ? AND sondage = ?', ...);
}
```

**Recommendation:** Cast to boolean explicitly: `$pasDeVote = !empty($_POST['pasDeVote'] ?? $_GET['pasDeVote'] ?? null);`

---

**[INP-R1-035] [MEDIUM] attaquer.php:26 -- Regex #^[0-9]*$# matches empty string for neutrino count after intval**

After `intval()` on line 24, the value is always numeric. The subsequent regex check on line 26 with `#^[0-9]*$#` is redundant but also matches empty string. This is a minor inconsistency.

```php
// attaquer.php:24-26
$_POST['nombreneutrinos'] = intval($_POST['nombreneutrinos']);
if (preg_match("#^[0-9]*$#", $_POST['nombreneutrinos']) and $_POST['nombreneutrinos'] >= 1 ...
```

**Recommendation:** Remove the redundant regex after `intval()`, or use `$_POST['nombreneutrinos'] >= 1` alone.

---

**[INP-R1-036] [MEDIUM] joueur.php:18 -- Player ID from GET trimmed but not format-validated**

`$_GET['id']` is used as a player login. It is trimmed but not validated against the login format, allowing arbitrary strings to be passed to the DB query and potentially displayed.

```php
// joueur.php:17-18
if (isset($_GET['id'])) {
    $_GET['id'] = trim($_GET['id']);
```

**Recommendation:** Validate with `validateLogin()` or a suitable regex.

---

**[INP-R1-037] [MEDIUM] admin/supprimercompte.php:11 -- Login input not validated before DB lookup**

The admin account deletion form accepts a login with no format validation. Invalid inputs waste DB queries.

```php
// admin/supprimercompte.php:11
$joueurExiste = dbCount($base, 'SELECT count(*) FROM membre WHERE login = ?', 's', $_POST['supprimer']);
```

**Recommendation:** Validate login format before DB lookup.

---

**[INP-R1-038] [MEDIUM] ecriremessage.php:91-92 -- Legacy stripslashes + preg_replace on POST content**

Content processing uses `stripslashes()` and a regex to normalize line endings. `stripslashes()` is a legacy pattern from the `magic_quotes` era (removed in PHP 5.4) and should not be needed. It could corrupt content containing intentional backslashes.

```php
// ecriremessage.php:91-92
creerBBcode("contenu", stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu']))));
$options = stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])));
```

**Recommendation:** Remove `stripslashes()` and simplify the line ending normalization.

---

## LOW

**[INP-R1-039] [LOW] bbcode.php:332 -- [img] BBCode tag allows any HTTP/HTTPS URL, enabling tracking pixels**

The `[img]` tag regex allows any URL matching common image extensions. An attacker could embed tracking pixels to harvest IP addresses, session timing, and browser fingerprints of other players viewing forum posts or messages.

```php
// bbcode.php:332
$text = preg_replace('!\[img=(https?:\/\/[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU',
    '<img alt="undefined" src="$1">', $text);
```

**Recommendation:** Consider restricting to HTTPS only, adding `referrerpolicy="no-referrer"` to the img tag, or implementing an image proxy.

---

**[INP-R1-040] [LOW] bbcode.php:331 -- [url] BBCode regex may allow crafted URLs**

The URL regex `(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\` has limited path matching. URLs with query strings, fragments, or special characters in paths may bypass or be partially matched. The catastrophic backtracking risk is mitigated by the `U` (ungreedy) flag.

```php
// bbcode.php:331
$text = preg_replace('!\[url=(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\](.+)\[/url\]!isU',
    '<a href="$1">$5</a>', $text);
```

**Recommendation:** Use a more robust URL validation or `filter_var($url, FILTER_VALIDATE_URL)` before rendering.

---

**[INP-R1-041] [LOW] allianceadmin.php:428-429 -- Grade listing outputs login and grade name without htmlspecialchars**

In the grades table listing, `$listeGrades['login']` and `$listeGrades['nom']` are output directly without `htmlspecialchars()`. While logins are constrained by registration validation, grade names have no character restrictions (see INP-R1-023).

```php
// allianceadmin.php:428-429
echo '<td><a href="joueur.php?id=' . $listeGrades['login'] . '">' . $listeGrades['login'] . '</a></td>
      <td>' . $listeGrades['nom'] . '</td>';
```

**Recommendation:** Wrap both values in `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.

---

**[INP-R1-042] [LOW] alliance.php:196 -- Grade login and name output without htmlspecialchars**

Alliance grades listing outputs login and grade name without encoding.

```php
// alliance.php:196
echo '<span class="subimportant">' . $grades['nom'] . ' : </span><a href="joueur.php?id=' . $grades['login'] . '">' . $grades['login'] . '</a><br/>';
```

**Recommendation:** Wrap in `htmlspecialchars()`.

---

**[INP-R1-043] [LOW] allianceadmin.php:242 -- Information message with alliance tag not htmlspecialchars'd**

Pact end confirmation uses the raw alliance tag from DB without encoding.

```php
// allianceadmin.php:242
$information = "Le pacte avec " . $allianceAdverse['tag'] . " est bien rompu.";
```

**Recommendation:** Wrap `$allianceAdverse['tag']` in `htmlspecialchars()`.

---

**[INP-R1-044] [LOW] marche.php:149 -- Market buy amount validated with redundant empty+regex after intval**

After `intval(transformInt(...))` on line 147, the value is already an integer. The `!empty()` and `preg_match("#^[0-9]*$#")` checks on line 149 are redundant.

```php
// marche.php:147-149
$_POST['nombreRessourceAAcheter'] = intval(transformInt($_POST['nombreRessourceAAcheter']));
$_POST['typeRessourceAAcheter'] = trim($_POST['typeRessourceAAcheter']);
if (!empty($_POST['nombreRessourceAAcheter']) and preg_match("#^[0-9]*$#", ...)) {
```

**Recommendation:** Simplify to `if ($_POST['nombreRessourceAAcheter'] >= 1)`.

---

**[INP-R1-045] [LOW] marche.php:259 -- Market sell amount validated with redundant empty+regex after intval**

Same redundant validation as INP-R1-044 for the sell flow.

```php
// marche.php:257-259
$_POST['nombreRessourceAVendre'] = intval(transformInt($_POST['nombreRessourceAVendre']));
...
if (!empty($_POST['nombreRessourceAVendre']) and preg_match("#^[0-9]*$#", ...)) {
```

**Recommendation:** Simplify to `if ($_POST['nombreRessourceAVendre'] >= 1)`.

---

**[INP-R1-046] [LOW] don.php:10 -- Energy donation uses preg_match after transformInt+intval**

After `transformInt()` on line 9, the regex `#^[0-9]+$#` is applied. Since `transformInt` already strips non-numeric characters, the regex is largely redundant, though it does correctly use `+` instead of `*`.

```php
// don.php:9-10
$_POST['energieEnvoyee'] = transformInt($_POST['energieEnvoyee']);
if(preg_match("#^[0-9]+$#", $_POST['energieEnvoyee']) && $_POST['energieEnvoyee'] > 0) {
```

**Recommendation:** Minor -- consider simplifying to `intval()` + `> 0` check.

---

**[INP-R1-047] [LOW] allianceadmin.php:431-432 -- Hidden joueurGrade field allows last-row override**

Multiple grades are listed with each row containing a hidden field `name="joueurGrade"`. Since all rows share the same form, submitting the form would only use the last row's value. This is a UX bug, not a security issue, since the grade deletion is validated against the DB.

```php
// allianceadmin.php:431
<input type="hidden" name="joueurGrade" value="' . $listeGrades['login'] . '"/>
```

**Recommendation:** Use per-row forms (as done elsewhere in the codebase) instead of a shared form.

---

**[INP-R1-048] [LOW] constructions.php:30 -- Variable variables used for point allocation**

The producer point allocation uses variable variables `${'points' . $ressource}` to access point values. While `$ressource` comes from the server-side `$nomsRes` array, this pattern is hard to audit.

```php
// constructions.php:30
$chaine = $chaine . ($_POST['nbPoints' . $ressource] + ${'points' . $ressource}) . $plus;
```

**Recommendation:** Use an associative array for point values.

---

**[INP-R1-049] [LOW] armee.php:65 -- Neutrino formation regex allows empty string**

Same `#^[0-9]*$#` pattern but mitigated by `!empty()` check and `>= 1` comparison.

```php
// armee.php:65
if (preg_match("#^[0-9]*$#", $_POST['nombreneutrinos']) and $_POST['nombreneutrinos'] >= 1) {
```

**Recommendation:** Use `#^[0-9]+$#`.

---

**[INP-R1-050] [LOW] armee.php:90 -- Molecule formation regex allows empty string**

Same pattern on molecule formation amount.

```php
// armee.php:90
if (isset($_POST['nombremolecules']) and !empty($_POST['nombremolecules'])
    and preg_match("#^[0-9]*$#", $_POST['nombremolecules'])) {
```

**Recommendation:** Use `#^[0-9]+$#`.

---

**[INP-R1-051] [LOW] allianceadmin.php:213-218 -- Pact report content contains inline HTML form stored in reports table**

The pact proposal sends a report containing a raw HTML form with hidden fields. While this is intentional design, storing raw HTML in the database is fragile. The report content is displayed via `strip_tags()` with an allowlist in `rapports.php:31`, but `<form>` and `<input>` are in the allowlist, so this form renders correctly.

```php
// allianceadmin.php:214-219
$rapportContenu = '...<form action="validerpacte.php" method="post">
<input type="submit" value="Accepter" name="accepter"/>
<input type="submit" value="Refuser" name="refuser"/>
<input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/></form>';
```

**Recommendation:** Note that this stored form lacks CSRF token. Consider generating the accept/reject UI dynamically instead of storing raw HTML.

---

**[INP-R1-052] [LOW] allianceadmin.php:213-218 -- Pact report stored form lacks CSRF token**

The accept/reject form stored in the reports table (see INP-R1-051) does not contain a CSRF token. When the target alliance chief views the report and clicks Accept, the form submission to `validerpacte.php` will lack a CSRF token, which may cause the `csrfCheck()` on line 5 of `validerpacte.php` to fail.

**Recommendation:** Either add a CSRF token when rendering the report, or generate the form dynamically on the rapports.php page instead of storing it.

---

**[INP-R1-053] [LOW] classement.php:15 -- $_GET['clas'] properly cast but page variable has edge case**

`classement.php` properly casts `$_GET['clas']` to `(int)` and whitelists columns via switch. This is a positive example and noted for completeness. No issue found.

---

## Positive Patterns (No Issues)

The following files demonstrate good input validation practices:

1. **inscription.php** -- Uses `validateLogin()` and `validateEmail()` from `validation.php`
2. **classement.php** -- Properly casts `$_GET['clas']` to `(int)`, whitelists column names via switch
3. **attaque.php:8** -- Properly casts `$_GET['id']` to `(int)`
4. **molecule.php:7** -- Properly casts `$_GET['id']` to `(int)`
5. **sinstruire.php:17-20** -- Properly casts and bounds-checks `$_GET['cours']`
6. **tutoriel.php:168-170** -- Properly validates `$_POST['claimMission']` with intval and bounds
7. **editer.php:6-7** -- Properly casts `$_GET['id']` and `$_GET['type']` to `(int)`
8. **compte.php:96-133** -- File upload has proper MIME, extension, dimension, and size validation
9. **moderationForum.php:36** -- Properly validates date with `checkdate()`
10. **alliance.php:38** -- Properly validates tag with `#^[a-zA-Z0-9_]{3,16}$#`
11. **alliance.php:94-97** -- Research upgrade properly validated against `$ALLIANCE_RESEARCH` array keys
12. **validerpacte.php:9-16** -- Properly checks authorization (chef of target alliance)
13. **includes/validation.php** -- Good helper functions exist but are underutilized

---

## Recommendations Summary

### Immediate Actions (CRITICAL + HIGH)
1. Replace `comptetest.php` login regex with `validateLogin()` from `validation.php`
2. Add date format validation to `compte.php` vacation date parsing
3. Enforce minimum password length (8+ chars) on both registration and change
4. Replace weak email regex with `validateEmail()` in both `comptetest.php` and `compte.php`
5. Cast `$_POST['nbclasse']` values to int immediately in `attaquer.php`
6. Cast `$_GET['type']` to int in `attaquer.php`
7. Fix delete validation regex `#\d#` to `#^\d+$#` in `messages.php` and `rapports.php`
8. Guard `csrfCheck()` with POST check in `admin/listenews.php`
9. Add CSRF to admin login form

### Systematic Changes (MEDIUM)
1. Replace all `#^[0-9]*$#` with `#^[0-9]+$#` or `validatePositiveInt()`
2. Add length limits to all user-content storage (descriptions, messages, forum posts)
3. Validate tag format on change (not just creation) in `allianceadmin.php`
4. Add format validation for player names passed via GET parameters
5. Replace variable variables with associative arrays

### Code Quality (LOW)
1. Remove redundant regex checks after `intval()`/`transformInt()`
2. Add `htmlspecialchars()` to remaining unencoded output contexts
3. Remove legacy `stripslashes()` calls
4. Add `referrerpolicy="no-referrer"` to BBCode `[img]` output
