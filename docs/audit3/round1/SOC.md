# SOC - Social/Alliance Features Audit

**Auditor:** Claude Opus 4.6
**Date:** 2026-03-03
**Scope:** Alliance management, invitation system, war/pact declarations, messaging, donations, forum, grade/permission enforcement, cross-alliance information leakage

**Files Reviewed:**
- `/home/guortates/TVLW/The-Very-Little-War/alliance.php`
- `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php`
- `/home/guortates/TVLW/The-Very-Little-War/validerpacte.php`
- `/home/guortates/TVLW/The-Very-Little-War/guerre.php`
- `/home/guortates/TVLW/The-Very-Little-War/don.php`
- `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php`
- `/home/guortates/TVLW/The-Very-Little-War/messages.php`
- `/home/guortates/TVLW/The-Very-Little-War/messagesenvoyes.php`
- `/home/guortates/TVLW/The-Very-Little-War/messageCommun.php`
- `/home/guortates/TVLW/The-Very-Little-War/annonce.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
- `/home/guortates/TVLW/The-Very-Little-War/forum.php`
- `/home/guortates/TVLW/The-Very-Little-War/sujet.php`
- `/home/guortates/TVLW/The-Very-Little-War/editer.php`
- `/home/guortates/TVLW/The-Very-Little-War/listesujets.php`

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 8     |
| MEDIUM   | 12    |
| LOW      | 8     |
| **Total**| **30**|

---

## CRITICAL Findings

### [SOC-R1-001] CRITICAL alliance.php:116-134 -- Invitation acceptance does not verify invitation belongs to current player

The invitation acceptance handler at line 120 fetches the invitation by ID only:
```php
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
```
It then joins the current `$_SESSION['login']` into that alliance at line 126 without verifying that the invitation's `invite` field matches the logged-in player. Any authenticated player who knows or guesses a valid invitation ID can steal another player's invitation to join any alliance.

**Exploit scenario:** Player A receives invitation ID 42. Player B submits `idinvitation=42` with `actioninvitation=Accepter` and joins the alliance instead. The invitation is then deleted (line 130), so Player A can never use it.

**Note:** This was previously identified as FINDING-SEC-022 in an earlier audit but the fix was never applied.

**Fix:** Add `AND invite=?` to the invitation lookup:
```php
$invitation = dbFetchOne($base, 'SELECT * FROM invitations WHERE id=? AND invite=?', 'is', $_POST['idinvitation'], $_SESSION['login']);
if (!$invitation) {
    $erreur = "Cette invitation ne vous est pas destinee.";
} else {
    $idalliance = $invitation;
    // ... rest of logic
}
```

---

### [SOC-R1-002] CRITICAL allianceadmin.php:214-219 -- Pact acceptance form in report message lacks CSRF token

When a pact is proposed, a form is embedded directly in the report content stored in the database:
```php
$rapportContenu = 'L\'alliance ... vous propose un pacte.
    <form action="validerpacte.php" method="post">
    <input type="submit" value="Accepter" name="accepter"/>
    <input type="submit" value="Refuser" name="refuser"/>
    <input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
    </form>';
```
This form has no CSRF token field. While `validerpacte.php` line 5 calls `csrfCheck()`, this means the legitimate form submission from the report will always fail CSRF validation, effectively making the pact acceptance form non-functional through the intended UI flow. Users cannot accept pacts through the normal game flow.

Additionally, even if CSRF were bypassed, embedding raw HTML forms in database-stored content is an architectural anti-pattern that mixes data and presentation, creating fragility.

**Fix:** Either (a) add `csrfField()` to the embedded form at render time (not at storage time), or (b) redesign the pact acceptance to use a dedicated page that generates its own CSRF-protected form based on the declaration ID.

---

## HIGH Findings

### [SOC-R1-003] HIGH allianceadmin.php:56-72 -- Alliance name change has no character/length validation

When the chef changes the alliance name (line 56-72), the only validation is:
1. Not empty
2. No duplicate name exists

There is no length limit, no character restriction, and no sanitization. A malicious chef could set an alliance name to an extremely long string (thousands of characters), or to strings containing special characters that could break display layouts.

While the name is properly escaped with `htmlspecialchars` in most output locations, the lack of input validation means:
- Database storage of arbitrarily long names (VARCHAR overflow or performance impact)
- UI layout breakage with excessively long names

**Fix:** Add regex validation similar to the tag validation:
```php
if (!preg_match('/^[\p{L}\p{N}\s_\'-]{1,50}$/u', $_POST['changernom'])) {
    $erreur = "Le nom doit faire entre 1 et 50 caracteres.";
}
```

---

### [SOC-R1-004] HIGH allianceadmin.php:121-137 -- Alliance tag change bypasses format validation

Alliance creation (alliance.php:38) validates tags with `preg_match("#^[a-zA-Z0-9_]{3,16}$#")`, but when the chef changes the tag via allianceadmin.php (lines 121-137), there is no regex validation at all. The only checks are:
1. Not empty
2. No duplicate tag

This allows setting tags with special characters, spaces, single characters, or excessively long strings, potentially breaking URL routing (alliance.php uses tag as GET parameter), display, and BBCode `[alliance=TAG/]` patterns.

**Fix:** Apply the same regex validation used during creation:
```php
if (!preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['changertag'])) {
    $erreur = "Le TAG ne peut contenir que lettres, chiffres et _, entre 3 et 16 caracteres.";
}
```

---

### [SOC-R1-005] HIGH alliance.php:30-66 -- Alliance name has no validation on creation

When creating an alliance, the tag is validated with `preg_match("#^[a-zA-Z0-9_]{3,16}$#")` but the name (`$_POST['nomalliance']`) has zero validation beyond being non-empty. This allows:
- Extremely long names (no length limit)
- Any Unicode character or special characters
- HTML-like strings (though escaped on output)

Combined with finding SOC-R1-003, the alliance name is consistently unvalidated across all code paths.

**Fix:** Add name validation alongside the existing tag validation.

---

### [SOC-R1-006] HIGH allianceadmin.php:176-193 -- Chef can be banned from their own alliance by a graded member

The ban handler at lines 176-193 is guarded by the `$bannir` permission flag. A graded member with ban rights checks if the target is in the alliance (line 181), then removes them (line 183). However, there is no check to prevent banning the alliance chef. If a graded member with ban rights bans the chef:
1. The chef's `idalliance` is set to 0
2. The alliance still has `chef` pointing to a player no longer in the alliance
3. On next page load of alliance.php (lines 149-157), the validation detects the chef is gone and calls `supprimerAlliance()`, deleting the entire alliance

**Exploit:** A graded member with ban permission can destroy the entire alliance by banning the chef.

**Fix:** Add a chef protection check:
```php
if ($_POST['bannirpersonne'] == $chef['chef']) {
    $erreur = "Vous ne pouvez pas bannir le chef de l'equipe.";
} else {
    // existing ban logic
}
```

---

### [SOC-R1-007] HIGH alliance.php:68-72 -- Chef can quit their own alliance, triggering auto-deletion

The "quitter" (leave) handler at lines 68-72 sets the player's `idalliance=0` without checking if the player is the alliance chef:
```php
if (isset($_POST['quitter'])) {
    csrfCheck();
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
}
```
If the chef submits this form, they leave the alliance, and on next page load the alliance auto-deletes (lines 149-157) since the chef is no longer a member. This has no confirmation and no warning.

**Fix:** Block the chef from using the quit button:
```php
if (isset($_POST['quitter'])) {
    csrfCheck();
    if ($allianceJoueur['chef'] == $_SESSION['login']) {
        $erreur = "Le chef ne peut pas quitter l'equipe. Transferez le role de chef ou supprimez l'equipe.";
    } else {
        dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
    }
}
```

---

### [SOC-R1-008] HIGH allianceadmin.php:426-433 -- Stored XSS via grade name and login in grade list table

The grade list table renders grade data without escaping:
```php
echo '<tr>
    <td><a href="joueur.php?id=' . $listeGrades['login'] . '">' . $listeGrades['login'] . '</a></td>
    <td>' . $listeGrades['nom'] . '</td>
    <td>
    <input type="hidden" name="joueurGrade" value="' . $listeGrades['login'] . '"/>
```
Both `$listeGrades['login']` and `$listeGrades['nom']` are output without `htmlspecialchars()`. While login names are typically alphanumeric, the grade name (`nom`) is user-controlled input set by the chef at line 95 with no sanitization. A chef could set a grade name containing JavaScript to create a stored XSS that executes when any admin views the grade list.

**Fix:** Escape all output:
```php
echo '<td>' . htmlspecialchars($listeGrades['nom'], ENT_QUOTES, 'UTF-8') . '</td>';
echo '<td><a href="joueur.php?id=' . htmlspecialchars($listeGrades['login'], ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($listeGrades['login'], ENT_QUOTES, 'UTF-8') . '</a></td>';
```

---

### [SOC-R1-009] HIGH alliance.php:196 -- XSS via unescaped grade name and login in public alliance page

The public alliance page renders grade information without escaping:
```php
echo '<span class="subimportant">' . $grades['nom'] . ' : </span><a href="joueur.php?id=' . $grades['login'] . '">' . $grades['login'] . '</a><br/>';
```
This is the same data as SOC-R1-008 but on the public-facing alliance page, visible to all players. A malicious grade name would execute JavaScript for any visitor.

**Fix:**
```php
echo '<span class="subimportant">' . htmlspecialchars($grades['nom'], ENT_QUOTES, 'UTF-8') . ' : </span>'
    . '<a href="joueur.php?id=' . urlencode($grades['login']) . '">'
    . htmlspecialchars($grades['login'], ENT_QUOTES, 'UTF-8') . '</a><br/>';
```

---

### [SOC-R1-010] HIGH editer.php:34-46 -- Moderator actions (hide/show posts) lack moderator permission check

The hide (type=5) and show (type=4) handlers at lines 34-46 require POST and CSRF, but they do not verify that the current user is a moderator:
```php
if ($type == 5 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    dbExecute($base, 'UPDATE reponses SET visibilite = 0 WHERE id = ?', 'i', $id);
```
Any authenticated player who crafts a POST request with `type=5` and a valid `id` can hide any forum post. Similarly, `type=4` lets any player unhide hidden posts.

**Fix:** Add moderator check at the start:
```php
if (($type == 4 || $type == 5) AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $modo = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login=?', 's', $_SESSION['login']);
    if (!$modo || $modo['moderateur'] != 1) {
        $erreur = "Action reservee aux moderateurs.";
    } else {
        // existing logic
    }
}
```

---

## MEDIUM Findings

### [SOC-R1-011] MEDIUM alliance.php:78-90 -- Duplicateur upgrade has no permission/grade check

The duplicateur upgrade at lines 78-90 is inside the `if ($_GET['id'] == $allianceJoueur['tag'])` block (line 68) meaning any alliance member can upgrade it. There is no check for chef status or grade permissions. While this may be intentional game design, it means any member can spend the entire alliance energy pool without authorization.

**Recommendation:** Consider adding a permission check or minimum grade requirement for spending alliance energy.

---

### [SOC-R1-012] MEDIUM alliance.php:94-113 -- Alliance research upgrades have no permission check

Similarly, research upgrades (catalyseur, fortification, reseau, radar, bouclier) at lines 94-113 are accessible to any alliance member. The same concern as SOC-R1-011 applies -- any member can drain the alliance energy pool through research upgrades.

---

### [SOC-R1-013] MEDIUM allianceadmin.php:74-106 -- Grade can be assigned to a player not in the alliance

When creating a grade (lines 74-106), the code checks:
1. The target is not the chef (line 80)
2. The target is not already graded (line 79)
3. The target exists in `membre` table (line 81)

But it does NOT check that the target player is actually a member of the alliance (`autre.idalliance = $chef['id']`). A chef can assign a grade with permissions (invite, ban, war, pact, description) to any player in the game, regardless of whether they are in the alliance.

When that external player visits `allianceadmin.php`, the check at line 10-12 finds their grade and grants them access to the alliance admin panel.

**Fix:** Replace the `membre` existence check with an alliance membership check:
```php
$existe = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE login=? AND idalliance=?', 'si', $_POST['personnegrade'], $chef['id']);
```

---

### [SOC-R1-014] MEDIUM allianceadmin.php:139-158 -- Chef transfer has no confirmation mechanism

The chef transfer (lines 139-158) changes the alliance leader in a single POST request with only CSRF protection. There is no confirmation step, no password re-entry, and no cooldown. A CSRF vulnerability elsewhere or a social engineering attack could result in permanent loss of alliance control.

**Recommendation:** Add a confirmation mechanism (e.g., require password re-entry or a two-step process).

---

### [SOC-R1-015] MEDIUM ecriremessage.php:12-18 -- Alliance mass message sends to all members without rate limiting

When sending to `[alliance]` (line 12), the code loops through every alliance member and inserts a separate message row:
```php
$ex = dbQuery($base, 'SELECT * FROM autre WHERE idalliance=? AND login !=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
while ($destinataire = mysqli_fetch_array($ex)) {
    dbExecute($base, 'INSERT INTO messages VALUES(...)');
}
```
There is no rate limiting on message sends. A player could spam the alliance message endpoint rapidly, generating 19 rows per request (20 members minus self), potentially causing database bloat.

**Fix:** Apply the rate limiter to message sending, e.g., max 5 alliance messages per minute.

---

### [SOC-R1-016] MEDIUM ecriremessage.php:20-26 -- Hardcoded admin check for broadcast messages

The broadcast-to-all feature (line 20) uses a hardcoded username check:
```php
} elseif ($_POST['destinataire'] == "[all]" && $_SESSION['login'] == "Guortates") {
```
This is fragile: if the admin username changes, the feature breaks. It also bypasses the admin password mechanism used elsewhere (e.g., `messageCommun.php` checks `$_SESSION['motdepasseadmin']`).

**Recommendation:** Use `$_SESSION['motdepasseadmin']` check or a proper admin role flag instead of hardcoded username comparison. The `messageCommun.php` page already implements the correct pattern.

---

### [SOC-R1-017] MEDIUM ecriremessage.php:46-56 -- Message reply allows reading other players' message metadata

When replying to a message by ID (lines 46-56), the code fetches the message:
```php
$message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=?', 'i', $_GET['id']);
```
It then checks if the recipient matches the current user (line 58) and blanks the content if not. However, before that check, the query has already executed, and the `destinataire` check only blanks content -- it does not prevent the page from processing. If a player supplies someone else's message ID, they get an error message but the query still runs. While content is blanked before display, this is a minor IDOR pattern.

**Note:** The data is properly blanked before display so this is low actual impact, but the pattern should use the ownership check in the query itself.

---

### [SOC-R1-018] MEDIUM alliance.php:257 -- Alliance description rendered via BBcode without input length limit

The alliance description is rendered through the BBcode function:
```php
echo BBcode($allianceJoueurPage['description'])
```
While BBcode properly applies `htmlentities()` before regex processing (bbcode.php:317), there is no length limit on the description. A chef could store an extremely long description that causes performance issues during BBcode regex processing or creates excessive page sizes for visitors.

**Fix:** Enforce a maximum length on `changerdescription` in `allianceadmin.php`:
```php
if (strlen($_POST['changerdescription']) > 5000) {
    $erreur = "La description est trop longue (max 5000 caracteres).";
}
```

---

### [SOC-R1-019] MEDIUM allianceadmin.php:249-297 -- War declaration/pact does not check if declaring against own alliance

While the query at line 253 uses `AND id!=?` to exclude declaring war on yourself:
```php
$ex = dbQuery($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['guerre'], $idalliance['idalliance']);
```
The same check exists for pacts (line 199). However, the duplicate declaration check only considers existing records between the two specific alliances but does not account for pending (unvalidated) pact requests in the reverse direction when declaring war. Theoretically, Alliance A could send a pact request to Alliance B, and before B accepts, Alliance A declares war -- resulting in both a pending pact and an active war against the same alliance.

**Fix:** When declaring war, also delete any pending (unvalidated) pact requests between the two alliances, and vice versa.

---

### [SOC-R1-020] MEDIUM sujet.php:12-36 -- Forum post creation stores raw user content without length limit

Forum replies (line 21) store `$_POST['contenu']` directly into the database:
```php
dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)', 'issi', $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);
```
There is no length validation on the content. A user could submit megabytes of text in a single forum post.

**Fix:** Add a length check:
```php
if (strlen($_POST['contenu']) > 10000) {
    $erreur = "Le message est trop long (max 10000 caracteres).";
}
```

---

### [SOC-R1-021] MEDIUM listesujets.php:26-43 -- Forum topic creation stores raw content without length limit

Same issue as SOC-R1-020 but for topic creation at line 31:
```php
dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);
```
Neither title nor content have length validation.

---

### [SOC-R1-022] MEDIUM listesujets.php:33 -- Forum topic lookup by content is fragile and potentially wrong

After inserting a new topic, the code looks up the newly created topic by its content:
```php
$sujet = dbFetchOne($base, 'SELECT id FROM sujets WHERE contenu = ?', 's', $_POST['contenu']);
```
This will match any topic with identical content, potentially returning the wrong topic ID. This is used to insert into `statutforum`, so the wrong topic could get marked as read.

**Fix:** Use `mysqli_insert_id()` or `LAST_INSERT_ID()` to get the actual inserted ID.

---

## LOW Findings

### [SOC-R1-023] LOW allianceadmin.php:258 -- War declaration debug output leaked to page

Line 258 contains:
```php
echo $nbDeclarations['nbDeclarations'];
```
This outputs the raw count of existing declarations to the HTML page during war declaration processing. This is debug output that should not be visible to users.

**Fix:** Remove or comment out the `echo` statement.

---

### [SOC-R1-024] LOW alliance.php:44-49 -- Alliance creation is not wrapped in a transaction

The creation of an alliance (INSERT into alliances) and the update of the creator's `idalliance` (UPDATE autre) are two separate queries at lines 44 and 49 without a transaction. If the server crashes between the two operations, the alliance exists with no members and the creator has no alliance.

**Fix:** Wrap in `withTransaction()`.

---

### [SOC-R1-025] LOW messagesenvoyes.php:10 -- Sent messages page has no pagination

The sent messages page loads ALL sent messages in one query:
```php
$ex = dbQuery($base, 'SELECT * FROM messages WHERE expeditaire = ? ORDER BY timestamp DESC', 's', $_SESSION['login']);
```
Unlike the inbox (messages.php) which uses pagination, the sent messages page has no `LIMIT` clause. An active player who sends thousands of messages would experience progressively slower page loads.

**Fix:** Add pagination similar to messages.php.

---

### [SOC-R1-026] LOW alliance.php:185 -- Division by zero possible when alliance has 0 members

Line 185 computes average points:
```php
echo chipInfo('<span class="important">Moyenne : </span>' . floor($pointstotaux / $nbjoueurs), ...);
```
While `$nbjoueurs` is derived from a count query and should always be >= 1 (since the page only renders if the alliance exists), in a race condition where all members leave simultaneously, this could divide by zero.

**Fix:** Add a guard: `max(1, $nbjoueurs)`.

---

### [SOC-R1-027] LOW editer.php:23-25 -- Deleting a post decrements the deleter's message count, not the author's

When a moderator deletes another player's post (lines 23-25):
```php
$nbMessages = dbFetchOne($base, 'SELECT nbMessages FROM autre WHERE login = ?', 's', $_SESSION['login']);
$newNbMessages = $nbMessages['nbMessages'] - 1;
dbExecute($base, 'UPDATE autre SET nbMessages = ? WHERE login = ?', 'is', $newNbMessages, $_SESSION['login']);
```
This decrements the logged-in user's (moderator's) message count, not the original author's. The moderator who did not write the post gets their count reduced.

**Fix:** Decrement the author's count, not the session user's:
```php
if ($auteur) {
    dbExecute($base, 'UPDATE autre SET nbMessages = nbMessages - 1 WHERE login=?', 's', $auteur['auteur']);
}
```

---

### [SOC-R1-028] LOW alliance.php:30 -- Mixed operator precedence with `and` vs `&&`

Line 30 mixes `and` and `&&`:
```php
if (isset($_POST['nomalliance']) and isset($_POST['tagalliance']) && $allianceJoueur['tag'] == -1) {
```
In PHP, `&&` has higher precedence than `and`. This means the condition is parsed as:
```php
isset($_POST['nomalliance']) and (isset($_POST['tagalliance']) && $allianceJoueur['tag'] == -1)
```
This happens to work correctly in context (the result is the same), but the inconsistent operator usage is a maintenance hazard and could cause unexpected behavior if the condition is modified.

**Fix:** Use consistent operators, preferably `&&` throughout.

---

### [SOC-R1-029] LOW sujet.php:223 -- External CDN loaded without SRI hash

Line 223 loads MathJax from a CDN without Subresource Integrity:
```php
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
```
If the CDN is compromised, arbitrary JavaScript could be injected into the forum pages.

**Fix:** Add an `integrity` attribute with the correct hash and a `crossorigin="anonymous"` attribute, consistent with the jQuery SRI already implemented elsewhere in the project.

---

### [SOC-R1-030] LOW ecriremessage.php:91-92 -- Unnecessary stripslashes on POST content

Lines 91-92 apply `stripslashes` to `$_POST['contenu']`:
```php
creerBBcode("contenu", stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu']))));
$options = stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])));
```
With `magic_quotes_gpc` removed since PHP 5.4, `stripslashes` on POST data is unnecessary and may corrupt legitimate backslash content in messages.

**Fix:** Remove the `stripslashes` calls.

---

## Positive Observations

1. **CSRF protection is consistently applied** across all state-changing POST handlers in alliance, messaging, and forum code.
2. **Prepared statements are used consistently** for all SQL queries -- no SQL injection vectors found.
3. **BBcode function properly escapes HTML** via `htmlentities()` before applying BBcode regex transformations (bbcode.php:317).
4. **validerpacte.php has proper authorization** -- it correctly verifies the current user is the chef of the target alliance before accepting/refusing a pact (lines 9-16).
5. **Donation system (don.php) uses transactions with row locking** (`FOR UPDATE`) to prevent TOCTOU race conditions, which is exemplary.
6. **Message deletion verifies ownership** -- messages.php uses `AND destinataire = ?` in delete queries.
7. **Forum ban system works correctly** with date-based expiry and proper display.
8. **Alliance deletion (supprimerAlliance) uses a transaction** to clean up all related tables atomically.
9. **editer.php requires POST for destructive actions** (delete, hide, show) -- the GET links in sujet.php are for the edit form only, not for direct execution.
10. **Message viewing properly checks authorization** -- messages.php uses `destinataire = ? OR expeditaire = ?` to only show messages the user is party to.
