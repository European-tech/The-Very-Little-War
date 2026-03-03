# Social Features Deep-Dive -- Round 2

**Audit Date:** 2026-03-03
**Scope:** All multiplayer/social features -- alliances, pacts, wars, donations, messaging, forum, voting, player profiles
**Files Reviewed:** alliance.php, allianceadmin.php, validerpacte.php, don.php, messages.php, ecriremessage.php, messagesenvoyes.php, forum.php, listesujets.php, sujet.php, editer.php, moderationForum.php, voter.php, joueur.php, guerre.php, rapports.php, connectes.php, messageCommun.php, includes/bbcode.php, includes/csrf.php

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3     |
| HIGH     | 8     |
| MEDIUM   | 12    |
| LOW      | 9     |
| **Total**| **32**|

---

## CRITICAL Findings

### SOC-R2-001: CRITICAL -- Invitation Acceptance Has No Ownership Verification

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 117-134
**Component:** Invitation system

**Description:**
When a player accepts an invitation, the code checks that the invitation exists by its numeric ID, but never verifies that the invitation belongs to the currently logged-in player. Any authenticated player can accept (or decline) any invitation intended for any other player, simply by submitting the invitation's numeric ID.

**Vulnerable Code:**
```php
if (isset($_POST['actioninvitation']) and isset($_POST['idinvitation'])) {
    csrfCheck();
    $_POST['idinvitation'] = (int)$_POST['idinvitation'];
    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);

    $ex = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
    $nombreJoueurs = mysqli_num_rows($ex);
    if ($nombreJoueurs < $joueursEquipe) {
        if ($_POST['actioninvitation'] == "Accepter") {
            dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
```

**Attack Scenario:**
1. Player A receives invitation #42 to join EliteAlliance
2. Attacker (Player B) who was NOT invited sends POST with `idinvitation=42` and `actioninvitation=Accepter`
3. Player B joins EliteAlliance using Player A's invitation
4. Player A's invitation is consumed (deleted) -- Player A can no longer join

**Impact:** Alliance membership theft. Attacker can force themselves into any alliance that has pending invitations. The legitimate invitee loses their invitation.

**Fix:**
```php
// Add ownership check after fetching the invitation
$invitation = dbFetchOne($base, 'SELECT idalliance, invite FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
if (!$invitation || $invitation['invite'] !== $_SESSION['login']) {
    $erreur = "Cette invitation ne vous est pas destinee.";
} else {
    // ... proceed with existing acceptance logic using $invitation['idalliance']
}
```

---

### SOC-R2-002: CRITICAL -- Alliance Admin Actions Lack Alliance Membership Verification for Grade Holders

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 6-24
**Component:** Alliance administration

**Description:**
The authorization check in allianceadmin.php fetches the player's alliance, then looks up that alliance's data, then checks if the player is chef or has a grade. However, it does NOT verify that the player is actually a member of the alliance returned. The grade lookup at line 10 uses `$chef['id']` (the alliance ID from the alliances table), but the link is indirect. The critical issue is that the grade permission flags (`$inviter`, `$guerre`, `$pacte`, `$bannir`, `$description`) are parsed from a dot-delimited string with no validation of the format.

At line 27:
```php
list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
```

If the `grade` column in the grades table contains a malformed string (fewer than 5 segments), PHP will emit a warning and the variables will be undefined. If it contains more segments, extra segments are silently ignored. More critically, the loose comparison `if ($inviter == 1)` means that any truthy value (not just "1") will be treated as `true`.

But the real vulnerability is structural: **if a player has been banned from the alliance (removed from `autre.idalliance`) but their grade record was not cleaned up**, they can still access allianceadmin.php and perform graded actions on the old alliance. The ban code at line 183-184 does clean up grades, but the "quitter" action at alliance.php line 71 does NOT clean up grades.

**Attack Scenario:**
1. Player with grade leaves alliance voluntarily (alliance.php "quitter" button)
2. Their `autre.idalliance` is set to 0, but grade record remains
3. Player navigates to allianceadmin.php
4. `$idalliance['idalliance']` is 0, but `$chef` query returns the alliance row for id=0 (no match = null)
5. This would normally fail, but if the player immediately joins another alliance... the stale grade from the OLD alliance could interact with the NEW alliance's admin page depending on query ordering

**Impact:** Stale grade records allow unauthorized alliance administration actions after leaving an alliance.

**Fix:**
```php
// After fetching idalliance, verify membership explicitly:
if ($idalliance['idalliance'] <= 0) {
    header('Location: alliance.php');
    exit();
}

// When player quits alliance (alliance.php line 71), also clean up grades:
if (isset($_POST['quitter'])) {
    csrfCheck();
    $oldAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
    if ($oldAlliance && $oldAlliance['idalliance'] > 0) {
        dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $oldAlliance['idalliance']);
    }
}
```

---

### SOC-R2-003: CRITICAL -- BBCode [img] Tag Allows External Image Tracking and Phishing

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, line 332
**Component:** BBCode rendering (used in forum, messages, alliance descriptions, player descriptions)

**Description:**
The BBCode `[img]` tag renders external images directly:
```php
$text = preg_replace('!\[img=(https?:\/\/[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU', '<img alt="undefined" src="$1">', $text);
```

This allows any user to embed external images from arbitrary domains. When another player views the content (forum post, private message, alliance description), their browser sends a request to the attacker's server, revealing:
- The victim's IP address
- Their User-Agent (browser/OS)
- Referer header (which page they're on)
- Timing information (when they read the message)

Additionally, image URLs with `.jpg` extension can serve HTML or redirect to phishing pages on some server configurations. The regex only checks the file extension, not the actual content type of the response.

**Attack Scenario:**
1. Attacker posts forum message: `[img=https://evil.com/track.php?t=unique_id.jpg]`
2. Every player who views the forum topic loads the tracking image
3. Attacker's server logs all viewers' IPs and timing
4. For targeted attacks: send private message with tracking image to know when target is online

**Impact:** Player IP tracking, activity monitoring, potential SSRF if server-side rendering is ever added. This is a privacy violation that enables targeted attacks.

**Fix:**
```php
// Option 1: Restrict to same-origin images only
$text = preg_replace(
    '!\[img=(images/[a-zA-Z0-9/_.-]+\.(gif|png|jpg|jpeg))\]!isU',
    '<img alt="image" src="$1">',
    $text
);

// Option 2: Proxy external images (more complex but allows external images)
// Route through a server-side proxy that strips tracking info

// Option 3: Add rel="noopener" and use CSP to limit image sources
// In .htaccess or Apache config:
// Header set Content-Security-Policy "img-src 'self' data:;"
```

---

## HIGH Findings

### SOC-R2-004: HIGH -- Alliance Name Change Has No Input Validation (XSS in Stored Context)

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 56-72
**Component:** Alliance settings

**Description:**
When changing the alliance name, there is no validation on the format or content of the new name. While the tag has regex validation (`^[a-zA-Z0-9_]{3,16}$`), the alliance name accepts any string. The name is stored in the database and displayed across many pages.

The display at alliance.php line 168 does use `htmlspecialchars`:
```php
debutCarte(htmlspecialchars(stripslashes($allianceJoueurPage['nom']), ENT_QUOTES, 'UTF-8'));
```

However, there are multiple display contexts where the name may not be consistently escaped -- particularly in report HTML that is generated server-side and stored, or in ranking pages where alliance names appear. The `stripslashes()` call is also suspicious as it suggests legacy escaping issues.

Additionally, there is **no length validation** on the alliance name. A very long name could break UI layouts or be used for content injection.

**Impact:** Potential stored XSS if any display context omits escaping. UI disruption via excessively long names.

**Fix:**
```php
if (isset($_POST['changernom'])) {
    csrfCheck();
    $_POST['changernom'] = trim($_POST['changernom']);
    if (mb_strlen($_POST['changernom']) < 3 || mb_strlen($_POST['changernom']) > 30) {
        $erreur = "Le nom doit avoir entre 3 et 30 caracteres.";
    } elseif (!preg_match('/^[\p{L}\p{N}\s_-]{3,30}$/u', $_POST['changernom'])) {
        $erreur = "Le nom contient des caracteres non autorises.";
    } else {
        // ... existing uniqueness check and update
    }
}
```

---

### SOC-R2-005: HIGH -- Alliance Tag Change Has No Format Validation

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 121-137
**Component:** Alliance settings

**Description:**
When changing the alliance tag, unlike alliance creation (which validates with `^[a-zA-Z0-9_]{3,16}$`), the tag change has NO regex validation. Any string is accepted. This means an existing alliance can change its tag to contain special characters, spaces, or excessively long strings.

```php
if (isset($_POST['changertag'])) {
    csrfCheck();
    if (!empty($_POST['changertag'])) {
        $_POST['changertag'] = trim($_POST['changertag']);
        $nballiance = dbCount($base, 'SELECT count(*) as nb FROM alliances WHERE tag=?', 's', $_POST['changertag']);
        if ($nballiance == 0) {
            dbExecute($base, 'UPDATE alliances SET tag=? WHERE id=?', 'si', $_POST['changertag'], $idalliance['idalliance']);
```

Alliance tags are used as URL parameters (`alliance.php?id=TAG`), displayed unescaped in pact proposals (rapports), and used in comparison operations. A tag containing special characters could break URL routing or HTML display.

**Impact:** XSS via stored tag in contexts that don't escape, URL manipulation, broken alliance references.

**Fix:**
```php
if (!preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['changertag'])) {
    $erreur = "Le TAG ne peut contenir que des lettres, chiffres et _ (3-16 caracteres).";
} else {
    // ... existing uniqueness check and update
}
```

---

### SOC-R2-006: HIGH -- Pact Proposal Embeds Raw HTML Form in Rapport Content

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 213-219
**Component:** Pact system / Reports

**Description:**
When a pact is proposed, an HTML form is embedded directly in the rapport content stored in the database:

```php
$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> vous propose un pacte.
    <form action="validerpacte.php" method="post">
    <input type="submit" value="Accepter" name="accepter"/>
    <input type="submit" value="Refuser" name="refuser"/>
    <input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
    </form>';
```

This embedded form has TWO problems:
1. **No CSRF token**: The form submitted to validerpacte.php will not contain a CSRF token, so the csrfCheck() at validerpacte.php line 5 will reject it. This means the pact acceptance/refusal flow is **broken** -- the Accept/Refuse buttons produce a CSRF error.
2. **Alliance tag is not escaped**: `$chef['tag']` is inserted directly into HTML without `htmlspecialchars()`. While tags are normally alphanumeric, the tag-change vulnerability (SOC-R2-005) means tags can contain arbitrary characters.

Furthermore, rapports.php line 31 uses `strip_tags()` with an allow-list that includes `<form>`, `<input>`, etc. -- but these allowed tags are NOT in the list:
```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><table><tr><td><th><ul><ol><li><hr>';
```

So the form elements are actually **stripped out** by `strip_tags()`, making the Accept/Refuse buttons invisible. The pact acceptance flow is completely broken in the current codebase.

**Impact:** Pact acceptance is non-functional. Players cannot accept or refuse pact proposals through the intended UI. CSRF protection would block the action even if the form rendered.

**Fix:**
```php
// Option 1: Use a link-based approach instead of embedded form
$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8')
    . '</a> vous propose un pacte. '
    . '<a href="validerpacte.php?id=' . (int)$idDeclaration['id'] . '">Voir les propositions de pacte</a>';

// Option 2: Create a dedicated pact management page that lists pending pacts
// and uses proper CSRF-protected forms
```

---

### SOC-R2-007: HIGH -- Grade Deletion Leaks All Grade Records Via Hidden Fields

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 410-439
**Component:** Grade management

**Description:**
The grade deletion form renders ALL grades in a table, each with a hidden field:
```php
while ($listeGrades = mysqli_fetch_array($ex)) {
    echo '<tr>
        <td><a href="joueur.php?id=' . $listeGrades['login'] . '">' . $listeGrades['login'] . '</a></td>
        <td>' . $listeGrades['nom'] . '</td>
        <td>
        <input type="hidden" name="joueurGrade" value="' . $listeGrades['login'] . '"/>
        <input src="images/croix.png" alt="suppr" type="image" name="Supprimer"></td>
        </tr>';
}
```

Two issues:
1. **All hidden fields share the same name** (`joueurGrade`). When any delete button is clicked, PHP will only receive the LAST hidden field value. This means only the last grade in the list can be deleted -- all other delete buttons delete the wrong person.
2. **No HTML escaping** on `$listeGrades['login']` and `$listeGrades['nom']` in the grade list display. The login is inserted into both an href and text content unescaped. The grade name is displayed unescaped.

**Impact:** Grade deletion is broken for all but the last grade. XSS via unescaped grade names.

**Fix:**
```php
while ($listeGrades = mysqli_fetch_array($ex)) {
    $safeLogin = htmlspecialchars($listeGrades['login'], ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($listeGrades['nom'], ENT_QUOTES, 'UTF-8');
    echo '<tr>
        <td><a href="joueur.php?id=' . $safeLogin . '">' . $safeLogin . '</a></td>
        <td>' . $safeName . '</td>
        <td>
        <form method="post" action="allianceadmin.php" style="display:inline">'
        . csrfField()
        . '<input type="hidden" name="joueurGrade" value="' . $safeLogin . '"/>
        <input src="images/croix.png" alt="suppr" type="image" name="Supprimer">
        </form></td>
        </tr>';
}
```

---

### SOC-R2-008: HIGH -- Alliance Research Upgrade Lacks Authorization Check (Any Member Can Upgrade)

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 93-113
**Component:** Alliance research

**Description:**
The alliance research upgrade code at line 94 checks only that the player is viewing their own alliance page (`$_GET['id'] == $allianceJoueur['tag']`), but does NOT check if the player is the chef or has appropriate grade permissions. Any regular alliance member can upgrade research and spend alliance energy.

```php
if ($_GET['id'] == $allianceJoueur['tag'] && $_GET['id'] != -1) {
    // ...
    if (isset($_POST['upgradeResearch']) && isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])) {
        csrfCheck();
        // No chef/grade check here!
        $techName = $_POST['upgradeResearch'];
```

Similarly, the duplicator upgrade at lines 78-90 has the same issue -- any alliance member can upgrade it.

**Impact:** Any alliance member can spend the alliance's shared energy pool on research/duplicator upgrades without authorization. A disgruntled member could drain the alliance's energy.

**Fix:**
```php
// Add authorization check before research upgrades
$isChef = ($allianceJoueur['chef'] == $_SESSION['login']);
$gradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $allianceJoueur['id']);
$canUpgrade = $isChef; // Or add a specific grade permission for research

if (isset($_POST['upgradeResearch']) && isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])) {
    csrfCheck();
    if (!$canUpgrade) {
        $erreur = "Seul le chef peut ameliorer les recherches.";
    } else {
        // ... existing upgrade logic
    }
}
```

---

### SOC-R2-009: HIGH -- Message Reading Has IDOR (Can Read Any Message Between Two Players)

**File:** `/home/guortates/TVLW/The-Very-Little-War/messages.php`, lines 19-37
**Component:** Private messaging

**Description:**
When viewing a single message, the query checks both sender and recipient:
```php
$ex = dbQuery($base, 'SELECT * FROM messages WHERE ( destinataire = ? OR expeditaire = ? ) AND id = ?',
    'ssi', $_SESSION['login'], $_SESSION['login'], $messageId);
```

This correctly limits to messages where the logged-in user is either sender or recipient. However, in the message listing at lines 10-11, the sent messages page (`messagesenvoyes.php`) loads all sent messages without pagination limits. More importantly, the message content rendered via BBCode could contain tracking images (see SOC-R2-003).

The actual IDOR is in **ecriremessage.php** lines 46-56:
```php
if (isset($_GET['id'])) {
    $_GET['id'] = (int)$_GET['id'];
    $message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=?', 'i', $_GET['id']);
}
```

This fetches ANY message by ID with NO ownership check. While it's used to pre-fill the reply form (showing the original message content and sender), this leaks the full content, sender, and recipient of any private message in the system.

**Impact:** Any authenticated player can read any private message by iterating message IDs via `ecriremessage.php?id=1`, `ecriremessage.php?id=2`, etc.

**Fix:**
```php
if (isset($_GET['id'])) {
    $_GET['id'] = (int)$_GET['id'];
    $message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=? AND (destinataire=? OR expeditaire=?)',
        'iss', $_GET['id'], $_SESSION['login'], $_SESSION['login']);
    if (!$message) {
        $message = ['contenu' => '', 'expeditaire' => '', 'destinataire' => $_SESSION['login']];
    }
}
```

---

### SOC-R2-010: HIGH -- Forum Post/Reply Content Stored Without Sanitization

**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`, lines 26-36 and `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, lines 12-36
**Component:** Forum system

**Description:**
Forum subject creation and replies store raw user input directly in the database:
```php
// listesujets.php - subject creation
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)',
    'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);

// sujet.php - reply creation
dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)',
    'issi', $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);
```

The content is stored raw (no sanitization). When displayed, it goes through `BBcode()` which calls `htmlentities()` first (line 317 of bbcode.php), so basic XSS is prevented. However:

1. The **subject title** in listesujets.php is stored raw and displayed with `htmlspecialchars` in the listing, but is passed to `BBcode()` when viewing the subject (sujet.php line 147: `htmlspecialchars($sujet['titre'])` for the breadcrumb, but also `htmlspecialchars($sujet['titre'])` in `carteForum`). This appears safe.

2. There is **no length validation** on titles or content. A user could submit megabytes of content, causing storage and rendering issues.

3. The `BBcode()` function's `[url]` tag regex is:
```php
'!\[url=(https?:\/\/([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?)\](.+)\[/url\]!isU'
```
The URL path allows spaces (` ` in the character class `[\/\w \.-]*`). This could be exploited to create misleading link text that differs from the actual URL, enabling phishing within forum posts.

**Impact:** No length limits allow storage abuse. URL BBCode enables internal phishing.

**Fix:**
```php
// Add length validation
if (mb_strlen($_POST['titre']) > 100) {
    $erreur = "Le titre ne doit pas depasser 100 caracteres.";
} elseif (mb_strlen($_POST['contenu']) > 10000) {
    $erreur = "Le contenu ne doit pas depasser 10000 caracteres.";
} else {
    // ... proceed with insertion
}
```

---

### SOC-R2-011: HIGH -- Broadcast Message to Alliance Has No Rate Limit

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`, lines 12-19
**Component:** Messaging system

**Description:**
When the destination is `[alliance]`, the code sends a message to every alliance member in a loop:
```php
if ($_POST['destinataire'] == "[alliance]") {
    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
    $ex = dbQuery($base, 'SELECT * FROM autre WHERE idalliance=? AND login !=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
    while ($destinataire = mysqli_fetch_array($ex)) {
        $now = time();
        dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)',
            'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $destinataire['login']);
    }
}
```

There is no rate limiting. A player can spam alliance messages repeatedly, flooding every member's inbox. Combined with no message length limit, this allows a single player to generate enormous database load.

Similarly, the admin `[all]` broadcast (line 20-26) has no rate limit, though it is restricted to the "Guortates" login.

**Impact:** Message spam flooding, database bloat, DoS via repeated mass messages.

**Fix:**
```php
// Add rate limiting for alliance broadcasts
$recentMessages = dbCount($base, 'SELECT count(*) as nb FROM messages WHERE expeditaire=? AND timestamp > ?',
    'si', $_SESSION['login'], time() - 300); // 5 minute window
if ($recentMessages > 10) {
    $erreur = "Vous envoyez trop de messages. Veuillez patienter.";
} else {
    // ... proceed with sending
}
```

---

## MEDIUM Findings

### SOC-R2-012: MEDIUM -- Alliance Description Stored Raw (BBCode XSS Vector)

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 163-173
**Component:** Alliance settings

**Description:**
The alliance description is stored raw and rendered through `BBcode()`:
```php
dbExecute($base, 'UPDATE alliances SET description=? WHERE id=?', 'si', $_POST['changerdescription'], $idalliance['idalliance']);
```

Displayed at alliance.php line 257:
```php
echo BBcode($allianceJoueurPage['description'])
```

While `BBcode()` calls `htmlentities()` first, the description is visible to ALL players (anyone can view any alliance page). The `[img]` and `[url]` BBCode tags (see SOC-R2-003) make this a tracking/phishing vector visible to every player who views the alliance.

No length limit is enforced on the description.

**Impact:** Tracking images and phishing links visible to all players viewing the alliance page. Storage abuse via unlimited length.

**Fix:**
```php
if (mb_strlen($_POST['changerdescription']) > 5000) {
    $erreur = "La description ne doit pas depasser 5000 caracteres.";
} else {
    // Store with length limit
    dbExecute($base, 'UPDATE alliances SET description=? WHERE id=?', 'si',
        mb_substr($_POST['changerdescription'], 0, 5000), $idalliance['idalliance']);
}
```

---

### SOC-R2-013: MEDIUM -- Player Description Has No Length Limit

**File:** `/home/guortates/TVLW/The-Very-Little-War/compte.php`, lines 88-93
**Component:** Player profile

**Description:**
Player descriptions are stored without length validation:
```php
if (isset($_POST['changerdescription'])) {
    $newDescription = trim($_POST['changerdescription']);
    dbExecute($base, 'UPDATE autre SET description = ? WHERE login = ?', 'ss', $newDescription, $_SESSION['login']);
}
```

Displayed via `BBcode($donnees1['description'])` on joueur.php line 82, visible to all logged-in players.

**Impact:** Storage abuse, UI disruption with very long descriptions, tracking via `[img]` BBCode.

**Fix:** Add `mb_strlen()` check with a 5000-character cap.

---

### SOC-R2-014: MEDIUM -- War Declaration Leaks Debug Output

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, line 258
**Component:** War system

**Description:**
When declaring war, the code echoes the result of a query directly to the page:
```php
echo $nbDeclarations['nbDeclarations'];
```

This outputs a raw number into the HTML before the page layout is fully rendered. While not a security vulnerability per se, it leaks internal state (the count of existing declarations) to the user and produces invalid HTML.

**Impact:** Information disclosure, broken page rendering.

**Fix:** Remove the debug `echo` statement on line 258.

---

### SOC-R2-015: MEDIUM -- Pact Break Notification Contains Unescaped Alliance Tag

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 239-241
**Component:** Pact system

**Description:**
When breaking a pact, the notification report embeds the alliance tag without escaping:
```php
$rapportTitre = 'L\'alliance ' . $chef['tag'] . ' met fin au pacte qui vous alliait.';
$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> met fin au pacte qui vous alliait.';
```

If `$chef['tag']` contains special characters (possible via SOC-R2-005), this creates stored XSS in the rapport system. The same issue appears in war declarations (line 267-268), pact proposals (line 213-214), and war end notifications (line 289-291).

**Impact:** Stored XSS in rapport content if alliance tag contains HTML special characters.

**Fix:** Apply `htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8')` to all tag insertions in rapport HTML.

---

### SOC-R2-016: MEDIUM -- Alliance Energy Donation Uses Floating-Point Comparison

**File:** `/home/guortates/TVLW/The-Very-Little-War/don.php`, lines 9-10
**Component:** Donation system

**Description:**
The energy donation amount goes through `transformInt()` which converts SI suffixes (K, M, G, etc.) to zeros, but then is validated with:
```php
$_POST['energieEnvoyee'] = transformInt($_POST['energieEnvoyee']);
if(preg_match("#^[0-9]+$#", $_POST['energieEnvoyee']) && $_POST['energieEnvoyee'] > 0) {
```

The value is used in SQL with type `'d'` (double) for the UPDATE statements. However, `transformInt()` processes ALL letters (K, M, G, T, P, E, Z, Y) as SI suffixes. Input like `1E` becomes `1000000000000000000` (1 quintillion). While the `FOR UPDATE` lock and balance check prevent actually donating more than available, the intermediate value could cause PHP integer overflow on 32-bit systems.

**Impact:** Potential integer overflow on 32-bit PHP, unexpected large donation amounts in edge cases.

**Fix:**
```php
$_POST['energieEnvoyee'] = transformInt($_POST['energieEnvoyee']);
if(preg_match("#^[0-9]+$#", $_POST['energieEnvoyee'])) {
    $_POST['energieEnvoyee'] = min((int)$_POST['energieEnvoyee'], PHP_INT_MAX);
    if ($_POST['energieEnvoyee'] > 0 && $_POST['energieEnvoyee'] <= 1000000000) {
        // Reasonable cap on single donation
```

---

### SOC-R2-017: MEDIUM -- Forum Subject Lookup Uses Content Match Instead of LAST_INSERT_ID

**File:** `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`, lines 31-35
**Component:** Forum system

**Description:**
After inserting a new forum subject, the code retrieves its ID by matching on content:
```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);

$sujet = dbFetchOne($base, 'SELECT id FROM sujets WHERE contenu = ?', 's', $_POST['contenu']);
```

If two subjects have identical content (possible with short posts like "ok" or "+1"), this returns the wrong subject ID. The wrong subject gets marked as read in `statutforum`.

**Impact:** Incorrect read status tracking. Race condition where concurrent identical posts get cross-linked.

**Fix:**
```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
$sujetId = mysqli_insert_id($base);
dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sii', $_SESSION['login'], $sujetId, $getId);
```

---

### SOC-R2-018: MEDIUM -- Voter.php Accepts GET Requests Without CSRF Protection

**File:** `/home/guortates/TVLW/The-Very-Little-War/voter.php`, lines 24-27
**Component:** Voting system

**Description:**
The voting endpoint accepts both GET and POST:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $reponse = intval($_POST['reponse'] ?? 0);
} elseif (isset($_GET['reponse'])) {
    // Legacy GET support -- will be removed in future
    $reponse = intval($_GET['reponse']);
}
```

GET requests bypass CSRF protection entirely. An attacker can craft a link or embed an image tag that forces a vote:
```html
<img src="voter.php?reponse=3" />
```

When embedded in a forum post via `[img]` BBCode, every player viewing the post would automatically cast vote #3.

**Impact:** CSRF-based vote manipulation. Combined with BBCode `[img]`, can force mass automatic voting.

**Fix:** Remove the GET path entirely:
```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(["erreur" => true]));
}
csrfCheck();
$reponse = intval($_POST['reponse'] ?? 0);
```

---

### SOC-R2-019: MEDIUM -- Alliance Page SELECT * Exposes All Alliance Data to Non-Members

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, line 159
**Component:** Alliance information display

**Description:**
The alliance page fetches ALL columns from the alliances table:
```php
$allianceJoueurPage = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
```

Then at line 188 it displays the energy amount:
```php
echo nombreEnergie('<span class="important">Energie : </span>' . number_format(floor($allianceJoueurPage['energieAlliance']), 0, ' ', ' '));
```

This displays the alliance's energy balance to ALL viewers, not just members. Additionally, `SELECT *` fetches sensitive internal data like `energieTotaleRecue`, duplicator level, and research levels, which are available in the `$allianceJoueurPage` variable even if not all are displayed to non-members.

For members, the member list at lines 372-393 shows detailed statistics including individual donation percentages, attack/defense points, and pillage amounts. This data is also shown to non-members viewing the alliance page (the member table is inside the `mysqli_num_rows($ex) > 0` block, not the `$_GET['id'] == $allianceJoueur['tag']` member-only block).

**Impact:** Strategic information leakage -- enemy alliances can see exact energy reserves, member contribution percentages, individual military statistics.

**Fix:**
```php
// For non-members, only show basic info
if ($_GET['id'] != $allianceJoueur['tag']) {
    $allianceJoueurPage = dbFetchOne($base, 'SELECT nom, tag, chef, pointsVictoire FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
    // Show limited member list (name and total points only)
} else {
    // Full access for members
    $allianceJoueurPage = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
}
```

---

### SOC-R2-020: MEDIUM -- Forum Edit Links Use GET Without CSRF Protection

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, lines 173-183
**Component:** Forum moderation

**Description:**
The "Supprimer" (delete), "Masquer" (hide), and "Afficher" (show) links in the forum are simple GET links:
```php
$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=3">Supprimer</a>';
$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=5">Masquer</a>';
$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=4">Afficher</a>';
```

The editer.php file checks `$_SERVER['REQUEST_METHOD'] === 'POST'` for types 3, 4, and 5 (lines 16, 34, 41), so GET requests won't actually execute the action. However, the links still navigate the user to editer.php with those parameters, showing the edit form for any post.

**Issue:** The links should be forms with POST method and CSRF tokens, matching what editer.php expects. Currently the links lead to a page that won't work (because it requires POST), creating a broken UX for moderators.

**Impact:** Broken moderation UX -- delete/hide/show buttons don't work as displayed.

**Fix:**
```php
// Replace links with POST forms
$editer = '<form method="post" action="editer.php?id=' . $reponse['id'] . '&type=3" style="display:inline">'
    . csrfField()
    . '<button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;">Supprimer</button></form>';
```

---

### SOC-R2-021: MEDIUM -- Chef Can Ban Themselves From Alliance

**File:** `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`, lines 177-193
**Component:** Alliance management

**Description:**
The ban action checks if the target is in the alliance but does NOT check if the target is the chef:
```php
if ($bannir) {
    if (isset($_POST['bannirpersonne'])) {
        csrfCheck();
        $_POST['bannirpersonne'] = ucfirst(trim($_POST['bannirpersonne']));
        $dansLAlliance = dbCount($base, '...WHERE idalliance=? AND login=?', 'is', $idalliance['idalliance'], $_POST['bannirpersonne']);
        if ($dansLAlliance > 0) {
            dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_POST['bannirpersonne']);
```

The chef (or a graded member with ban permission) can ban the chef, which would orphan the alliance. The alliance would then have a chef that isn't a member, eventually triggering the "chef doesn't exist" cleanup at alliance.php lines 148-157 which deletes the entire alliance.

A graded member could also ban other graded members, or ban everyone else and then leave, destroying the alliance.

**Impact:** Alliance destruction by a graded member. Self-banning the chef orphans the alliance.

**Fix:**
```php
if ($_POST['bannirpersonne'] === $chef['chef']) {
    $erreur = "Le chef ne peut pas etre banni.";
} elseif ($dansLAlliance > 0) {
    // ... proceed with ban
}
```

---

### SOC-R2-022: MEDIUM -- Alliance Creation Does Not Validate Name Format

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 30-65
**Component:** Alliance creation

**Description:**
When creating an alliance, the tag is validated with regex `^[a-zA-Z0-9_]{3,16}$`, but the alliance name (`nomalliance`) has no format or length validation beyond "not empty":
```php
if (!empty($_POST['nomalliance']) and !empty($_POST['tagalliance'])) {
    // ...
    $_POST['nomalliance'] = trim($_POST['nomalliance']);
    $_POST['tagalliance'] = trim($_POST['tagalliance']);
    if (preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['tagalliance'])) {
        // Tag validated, but name is NOT
```

A 10,000-character alliance name would be accepted, and HTML form has `maxlength=10` on tag but not on name.

**Impact:** UI disruption, storage abuse via unlimited alliance names.

**Fix:** Add regex/length validation for alliance name: `mb_strlen() between 3 and 40`.

---

### SOC-R2-023: MEDIUM -- connectes.php Exposes All Player Login Timestamps to Non-Authenticated Users

**File:** `/home/guortates/TVLW/The-Very-Little-War/connectes.php`, lines 1-38
**Component:** Player activity

**Description:**
The connectes.php page shows every player's last login time, and it works for both authenticated and non-authenticated visitors:
```php
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php");
} else {
    include("includes/basicpublicphp.php");
}
```

The query:
```php
$ex = dbQuery($base, 'SELECT login, derniereConnexion FROM membre ORDER BY derniereConnexion DESC');
```

Shows ALL players' login names and exact last connection timestamps to anyone, including non-authenticated visitors.

**Impact:** Reconnaissance -- an attacker can identify active/inactive players, track login patterns, and identify optimal attack times. Player usernames are leaked to unauthenticated users.

**Fix:** Restrict to authenticated users only, and consider limiting the detail shown:
```php
if (!isset($_SESSION['login'])) {
    header('Location: index.php');
    exit();
}
```

---

## LOW Findings

### SOC-R2-024: LOW -- BBCode [url] Regex Allows Misleading Link Text

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, line 331
**Component:** BBCode rendering

**Description:**
The URL BBCode allows arbitrary text as the link label:
```php
$text = preg_replace('!\[url=(https?://...)\](.+)\[/url\]!isU', '<a href="$1">$5</a>', $text);
```

A user can create `[url=https://evil.com]Click here to view alliance admin[/url]` which renders as a link that appears to be internal but leads to an external site.

**Impact:** Phishing within forum posts and messages.

**Fix:** Add `target="_blank" rel="noopener noreferrer nofollow"` to external links, and/or add a visual indicator for external URLs.

---

### SOC-R2-025: LOW -- Rapports Use strip_tags Allow-List That Includes Dangerous Tags

**File:** `/home/guortates/TVLW/The-Very-Little-War/rapports.php`, line 30-31
**Component:** Report display

**Description:**
```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><table><tr><td><th><ul><ol><li><hr>';
echo strip_tags($rapports['contenu'], $allowedTags);
```

The `<img>` tag is allowed, meaning any report content containing `<img>` tags (potentially from server-side code that builds reports) will render external images. The `<a>` tag is allowed with any attributes, including `onclick` and `href="javascript:..."`.

Note: `strip_tags()` does NOT remove attributes from allowed tags. So `<a onclick="alert(1)" href="#">click</a>` passes through untouched.

**Impact:** Stored XSS in reports if any code path inserts user-controlled data into report HTML with event handler attributes.

**Fix:** Use a proper HTML sanitizer library (like HTMLPurifier) instead of `strip_tags()`, or use `htmlspecialchars()` and re-apply known-safe formatting.

---

### SOC-R2-026: LOW -- Admin Broadcast Check Uses Hardcoded Username

**File:** `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`, line 20
**Component:** Messaging

**Description:**
The broadcast-to-all feature is gated by:
```php
} elseif ($_POST['destinataire'] == "[all]" && $_SESSION['login'] == "Guortates") {
```

This is a hardcoded admin username check rather than using the admin authentication system (`$_SESSION['motdepasseadmin']`). If the "Guortates" account is compromised or if the username ever changes, this gate fails. It also can't be extended to other admins.

**Impact:** Fragile admin authorization, inconsistent with the rest of the admin system.

**Fix:** Use `!empty($_SESSION['motdepasseadmin'])` instead of username comparison. Or better, use the dedicated `messageCommun.php` admin page exclusively.

---

### SOC-R2-027: LOW -- Alliance Page Sorting Column is Not Validated Against Whitelist in URL

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`, lines 341-369
**Component:** Alliance member display

**Description:**
The code uses `$_GET['clas']` to select a sort column through a switch statement:
```php
switch ($_GET['clas']) {
    case 0: $order = 'totalPoints'; break;
    case 1: $order = 'victoires'; break;
    // ...
    default: $order = 'totalPoints'; break;
}
```

The comment on line 371 notes: "// $order is from a whitelist, safe to use in query."

This is correct -- the switch/default structure is a safe whitelist. However, `$_GET['clas']` is never cast to int before the switch, relying on PHP's loose comparison. While this works safely here, it's inconsistent with the pattern used elsewhere.

**Impact:** No direct vulnerability. Code quality issue -- loose comparison in security-relevant context.

**Fix:** Cast to int: `$_GET['clas'] = (int)($_GET['clas'] ?? 0);`

---

### SOC-R2-028: LOW -- Forum Ban Check Uses Date Comparison That May Fail on Edge Cases

**File:** `/home/guortates/TVLW/The-Very-Little-War/forum.php`, lines 33-36
**Component:** Forum moderation

**Description:**
The ban expiry check uses a SQL DATEDIFF:
```php
$ex5 = dbQuery($base, 'SELECT DATEDIFF(CURDATE(), ?)', 's', $sanction['dateFin']);
$diff = mysqli_fetch_array($ex5);
if ($diff[0] >= 0) {
    dbExecute($base, 'DELETE FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
```

`DATEDIFF(CURDATE(), dateFin)` returns a positive number when CURDATE is AFTER dateFin. If `$diff[0] >= 0`, the ban has expired (today is the end date or later). This is correct but the `>= 0` means the ban expires AT the start of the end date, not at the end of it. This is a minor logic issue -- bans end one day early.

**Impact:** Forum bans expire approximately one day earlier than the displayed end date.

**Fix:** Use `$diff[0] > 0` to make the ban expire after the end date passes, or use datetime comparison for precision.

---

### SOC-R2-029: LOW -- Forum Thread Navigation Inconsistency

**File:** `/home/guortates/TVLW/The-Very-Little-War/sujet.php`, line 47
**Component:** Forum UX

**Description:**
When viewing a forum thread, if no page is specified, the code defaults to the LAST page:
```php
$page = isset($_GET['page']) ? intval($_GET['page']) : 0;
if ($page < 1 || $page > $nombreDePages) {
    $page = ($nombreDePages > 0) ? $nombreDePages : 1;
}
```

This is intentional for forum threads (show newest replies first), but it's inconsistent with listesujets.php and messages.php which default to page 1 (newest first via ORDER DESC). Not a security issue, but may confuse users navigating from the subject list.

**Impact:** UX inconsistency.

**Fix:** Standardize pagination defaults across all paginated views.

---

### SOC-R2-030: LOW -- Sent Messages Page Has No Pagination

**File:** `/home/guortates/TVLW/The-Very-Little-War/messagesenvoyes.php`, lines 10-26
**Component:** Messaging

**Description:**
The sent messages page loads ALL sent messages without pagination:
```php
$ex = dbQuery($base, 'SELECT * FROM messages WHERE expeditaire = ? ORDER BY timestamp DESC', 's', $_SESSION['login']);
```

For active players who have sent hundreds/thousands of messages, this will load all of them in a single query and render them all on one page.

**Impact:** Performance degradation for active users. No direct security impact.

**Fix:** Add pagination matching the inbox pattern (15 messages per page).

---

### SOC-R2-031: LOW -- Player Profile Reveals Exact Coordinates to Non-Authenticated Users

**File:** `/home/guortates/TVLW/The-Very-Little-War/joueur.php`, lines 61-63
**Component:** Player profile

**Description:**
The player profile page works for both authenticated and non-authenticated users. Player coordinates are shown at line 62:
```php
if($membre['x'] != -1000){
    echo chip('<span class="important">Position : </span>'.'<a href="attaquer.php?x='.$membre['x'].'&y='.$membre['y'].'">'.$membre['x'].';'.$membre['y'].'</a>', ...);
}
```

While the attack/spy links are only shown to authenticated users, the coordinate display itself is outside the auth check. Non-authenticated visitors can see any player's exact coordinates.

**Impact:** Minor strategic information leak. Coordinates are also visible in HTML source even if not linked.

**Fix:** Wrap the coordinate display inside `if(isset($_SESSION['login']))`.

---

### SOC-R2-032: LOW -- BBCode Regex Patterns Use Deprecated `e` Flag Equivalent

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php`, lines 320-364
**Component:** BBCode rendering

**Description:**
The BBCode function uses many `preg_replace` calls with the `!isU` flags. While these don't use the deprecated `e` flag, the sheer number of regex operations (40+ patterns applied sequentially) on every BBCode render is inefficient. More importantly, several patterns are overly broad:

```php
$text = preg_replace('!lol!isU', '...', $text);  // Replaces "lol" ANYWHERE, including in words like "technology"
$text = preg_replace('!=/!isU', '...', $text);    // Replaces "=/" anywhere, including in URLs
```

The emoticon patterns lack word boundary anchors, so they trigger inside URLs, code examples, and regular words.

**Impact:** Content corruption -- legitimate text gets mangled by overly aggressive smiley replacement. Not a security issue but degrades message quality.

**Fix:** Add word boundaries: `'!\blol\b!isU'` or process smileys only outside of `[url]` and `[code]` blocks.

---

## Verification Matrix

| ID | Severity | File | Issue | CSRF | Auth | XSS | Logic |
|----|----------|------|-------|------|------|-----|-------|
| SOC-R2-001 | CRITICAL | alliance.php | Invitation theft | - | YES | - | - |
| SOC-R2-002 | CRITICAL | allianceadmin.php | Stale grade access | - | YES | - | YES |
| SOC-R2-003 | CRITICAL | bbcode.php | External image tracking | - | - | - | YES |
| SOC-R2-004 | HIGH | allianceadmin.php | No name validation | - | - | YES | - |
| SOC-R2-005 | HIGH | allianceadmin.php | No tag validation on change | - | - | YES | - |
| SOC-R2-006 | HIGH | allianceadmin.php | Broken pact accept flow | YES | - | YES | YES |
| SOC-R2-007 | HIGH | allianceadmin.php | Grade deletion broken + XSS | - | - | YES | YES |
| SOC-R2-008 | HIGH | alliance.php | Research no auth check | - | YES | - | - |
| SOC-R2-009 | HIGH | ecriremessage.php | Message IDOR | - | YES | - | - |
| SOC-R2-010 | HIGH | listesujets/sujet.php | No content length limit | - | - | - | YES |
| SOC-R2-011 | HIGH | ecriremessage.php | Alliance broadcast no rate limit | - | - | - | YES |
| SOC-R2-012 | MEDIUM | allianceadmin.php | Description no length limit | - | - | - | YES |
| SOC-R2-013 | MEDIUM | compte.php | Player desc no length limit | - | - | - | YES |
| SOC-R2-014 | MEDIUM | allianceadmin.php | Debug echo in war declaration | - | - | - | YES |
| SOC-R2-015 | MEDIUM | allianceadmin.php | Unescaped tags in rapports | - | - | YES | - |
| SOC-R2-016 | MEDIUM | don.php | Integer overflow via transformInt | - | - | - | YES |
| SOC-R2-017 | MEDIUM | listesujets.php | Content match instead of insert_id | - | - | - | YES |
| SOC-R2-018 | MEDIUM | voter.php | GET bypasses CSRF | YES | - | - | - |
| SOC-R2-019 | MEDIUM | alliance.php | Alliance data exposed to non-members | - | - | - | YES |
| SOC-R2-020 | MEDIUM | sujet.php | Edit/delete links broken (need POST) | YES | - | - | YES |
| SOC-R2-021 | MEDIUM | allianceadmin.php | Chef can ban self | - | - | - | YES |
| SOC-R2-022 | MEDIUM | alliance.php | Alliance name no format check | - | - | YES | - |
| SOC-R2-023 | MEDIUM | connectes.php | Login times visible to public | - | YES | - | - |
| SOC-R2-024 | LOW | bbcode.php | URL phishing via label mismatch | - | - | - | YES |
| SOC-R2-025 | LOW | rapports.php | strip_tags allows dangerous attrs | - | - | YES | - |
| SOC-R2-026 | LOW | ecriremessage.php | Hardcoded admin username | - | YES | - | - |
| SOC-R2-027 | LOW | alliance.php | Sort param not cast to int | - | - | - | YES |
| SOC-R2-028 | LOW | forum.php | Ban expires one day early | - | - | - | YES |
| SOC-R2-029 | LOW | sujet.php | Pagination default inconsistency | - | - | - | YES |
| SOC-R2-030 | LOW | messagesenvoyes.php | No pagination on sent messages | - | - | - | YES |
| SOC-R2-031 | LOW | joueur.php | Coordinates visible to public | - | - | - | YES |
| SOC-R2-032 | LOW | bbcode.php | Smiley regex corrupts content | - | - | - | YES |

---

## Priority Fix Order

### Immediate (CRITICAL -- fix before next deployment)
1. **SOC-R2-001**: Add `invite = $_SESSION['login']` check to invitation acceptance
2. **SOC-R2-002**: Clean up grades on alliance quit; verify membership in allianceadmin.php
3. **SOC-R2-003**: Restrict BBCode `[img]` to same-origin or implement CSP img-src restriction

### High Priority (fix within 1 week)
4. **SOC-R2-009**: Add ownership check to ecriremessage.php message retrieval
5. **SOC-R2-006**: Fix pact acceptance flow (CSRF token + proper form rendering)
6. **SOC-R2-005**: Add tag format validation on tag change
7. **SOC-R2-008**: Add chef/grade check for research/duplicator upgrades
8. **SOC-R2-007**: Fix grade deletion (separate forms per grade) + escape output
9. **SOC-R2-018**: Remove GET support from voter.php

### Medium Priority (fix within 2 weeks)
10. **SOC-R2-015**: Escape alliance tags in rapport HTML
11. **SOC-R2-021**: Prevent self-ban and chef-ban
12. **SOC-R2-019**: Limit alliance data shown to non-members
13. **SOC-R2-010**: Add content length limits to forum posts
14. **SOC-R2-011**: Add rate limiting to alliance broadcasts
15. **SOC-R2-023**: Restrict connectes.php to authenticated users

### Low Priority (fix within 1 month)
16. All remaining LOW findings (SOC-R2-024 through SOC-R2-032)

---

## Cross-References

- SOC-R2-003 + SOC-R2-018: Combining BBCode `[img]` with voter.php GET support enables automatic mass vote manipulation via forum posts
- SOC-R2-005 + SOC-R2-015: Unvalidated tag changes create stored XSS in all rapport notifications
- SOC-R2-002 + SOC-R2-021: Stale grades + self-ban enable alliance destruction attack chain
- SOC-R2-006 + SOC-R2-025: Broken pact flow means alliances cannot form pacts -- game mechanics affected
