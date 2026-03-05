# Pass 2 -- Deep Dive: Domain 1 -- Security

**Auditor:** Claude Opus 4.6 (Security Auditor)
**Date:** 2026-03-05
**Scope:** Line-by-line analysis of all high-risk PHP files, tracing every data flow from input to output
**Methodology:** TOCTOU analysis, race condition simulation, type coercion audit, authorization path tracing, transaction isolation verification

---

## Table of Contents

1. [Critical Findings](#critical-findings)
2. [High Findings](#high-findings)
3. [Medium Findings](#medium-findings)
4. [Low Findings](#low-findings)
5. [Informational Findings](#informational-findings)
6. [Summary Statistics](#summary-statistics)

---

## Critical Findings

### P2-D1-001 | CRITICAL | CSRF Open Redirect via HTTP_REFERER Header Injection

**Location:** `includes/csrf.php` line 41-42

**Code:**
```php
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $referer . (strpos($referer, '?') !== false ? '&' : '?') . 'erreur=...');
```

**Description:** When CSRF validation fails, the user is redirected to `$_SERVER['HTTP_REFERER']` which is entirely attacker-controlled. An attacker can craft a request from a malicious page where the Referer header is set to `https://evil.com/phishing.php`. The victim's browser will follow the redirect to the attacker's domain, potentially including the error message in the URL. This is a classic open redirect vulnerability.

The attack scenario: Attacker embeds a form on `evil.com` that POSTs to `compte.php` on the game server. The Referer will be `evil.com`. When CSRF fails (by design -- the attacker does not know the token), the server redirects the victim's browser to `evil.com/phishing.php?erreur=...`. The attacker's page can then display a convincing "please re-login" form.

**Impact:** Phishing attacks, credential theft, session hijacking via redirect chain. Severity amplified because the redirect happens on a CSRF failure path, which is exactly the scenario an attacker would trigger.

**Fix:**
```php
// Replace with a safe static redirect
$safeFallback = 'index.php';
header('Location: ' . $safeFallback . '?erreur=' . urlencode('Erreur de securite...'));
exit();
```

---

### P2-D1-002 | CRITICAL | Resource Transfer Not Wrapped in Transaction (TOCTOU Race Condition)

**Location:** `marche.php` lines 55-127 (resource send flow)

**Code path:**
```php
// Line 55: Read resources OUTSIDE any transaction
$ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);

// Line 58-62: Check sufficiency based on stale data
foreach ($nomsRes as $num => $ressource) {
    if ($ressources[$ressource] < $_POST[$ressource . 'Envoyee']) { $bool = 0; }
}

// Line 63: Check energy
if ($ressources['energie'] >= $_POST['energieEnvoyee'] and $bool == 1) {
    // ... Lines 101-127: INSERT into actionsenvoi, then UPDATE ressources
    // ALL WITHOUT A TRANSACTION OR FOR UPDATE LOCK
}
```

**Description:** The entire resource transfer flow (player-to-player sending on the market page) has no transaction wrapping and no FOR UPDATE lock. The data flow is:

1. Read resources (line 55) -- no lock
2. Validate sufficiency (lines 58-63) -- stale data
3. Insert transfer action (line 101) -- no lock
4. Deduct resources (lines 122-126) -- UPDATE without transaction

Two concurrent requests can both read sufficient resources at step 1, both pass validation at step 2, and both deduct resources at step 4. This allows a player to send more resources than they actually have by submitting multiple transfer requests simultaneously.

The buy and sell flows at lines 180 and 287 DO use `withTransaction` + `FOR UPDATE` -- but the player-to-player send flow at line 55 does not.

**Proof of concept:**
- Player has 1000 carbone
- Player opens two browser tabs, fills both with "send 800 carbone to friend"
- Submits both simultaneously
- Both requests read 1000 carbone at line 55
- Both pass the check at line 59 (1000 >= 800)
- Both insert transfer actions and deduct 800
- Player ends up with -600 carbone (or 200 if second UPDATE clobbers first with `SET carbone=200`)

**Impact:** Resource duplication, economic exploitation, game integrity compromise. The deduction at line 118 uses `carbone=(computed value)` not `carbone=carbone-800`, so the second request's deduction clobbers the first, resulting in only a single deduction while two transfers were created.

**Fix:** Wrap the entire send flow (lines 55-127) in `withTransaction` with a `FOR UPDATE` lock on the sender's resources, mirroring the pattern already used for buy/sell.

---

### P2-D1-003 | CRITICAL | Resource Transfer Action Processing Without Transaction (Double-Spend)

**Location:** `includes/game_actions.php` lines 526-600

**Code:**
```php
// Line 529: DELETE without transaction -- not atomic with the resource credit
dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);

// Lines 567-599: Read recipient resources, compute new values, UPDATE resources
$ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['receveur']);
// ... compute ...
dbExecute($base, 'UPDATE ressources SET ' . implode(',', $envoiSetClauses) . ' WHERE login=?', ...);
```

**Description:** When processing completed resource transfers, `game_actions.php` first DELETEs the action row (line 529), then reads the recipient's current resources (line 567), computes the new values (lines 571-596), and UPDATEs the resources (line 599). None of this is inside a transaction.

Two attack vectors:
1. **Double-credit:** If `updateActions` is called concurrently for the same player (possible via simultaneous page loads), both calls see the same action at line 526. The first DELETE succeeds, but if the second call's `dbFetchAll` already loaded the rows before the DELETE, it will also attempt to process the same action. The DELETE on line 529 will return 0 affected rows, but there is no check on the return value -- processing continues regardless.

2. **Stale resource reads:** The recipient's resources are read without FOR UPDATE (line 567). The computed UPDATE at line 599 uses absolute values (`carbone=X`) not increments (`carbone=carbone+X`). If another concurrent process updates the recipient's resources between lines 567 and 599, those changes are silently overwritten.

**Impact:** Resource duplication for receivers, resource loss from overwritten concurrent updates.

**Fix:**
```php
// Wrap in withTransaction with CAS guard
withTransaction($base, function() use ($base, $actions, $nomsRes, ...) {
    $affected = dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);
    if ($affected === 0 || $affected === false) return; // Already processed

    $ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $actions['receveur']);
    // ... compute and update ...
});
```

---

## High Findings

### P2-D1-004 | HIGH | withTransaction Catches Exception But Not Throwable (TypeError/Error Bypass Rollback)

**Location:** `includes/database.php` lines 111-121

**Code:**
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

**Description:** The `catch (Exception $e)` clause only catches `Exception` and its subclasses. In PHP 7+, errors like `TypeError`, `DivisionByZeroError`, `ArgumentCountError`, and other `Error` subclasses do NOT extend `Exception` -- they extend `Throwable` directly via the `Error` class.

If any code inside a transaction triggers a `TypeError` (e.g., passing null to a function expecting a string), an `ArgumentCountError`, or a `DivisionByZeroError`, the transaction is committed (the `try` block exits to the `catch`, which does not match, so PHP propagates the error -- but `mysqli_commit` may or may not have been called depending on PHP's behavior). Actually in PHP, if an uncaught `Error` propagates, the commit on line 115 is NOT reached, but neither is the rollback on line 118. The transaction remains open and will be auto-rolled-back when the connection closes, BUT if the connection is reused (persistent connections) or if there are partial writes before the error, the state is unpredictable.

More importantly, if any `Error` is thrown AFTER the commit on line 115 (e.g., in a cleanup callback), the transaction is already committed with partial state.

**Impact:** Data corruption from partially committed transactions when PHP `Error` types occur. The combat system (game_actions.php lines 109-360) and market operations are protected by `withTransaction`, making this a systemic risk.

**Fix:**
```php
} catch (\Throwable $e) {
    mysqli_rollback($base);
    throw $e;
}
```

---

### P2-D1-005 | HIGH | Rate Limiter TOCTOU -- Concurrent Requests Can Bypass Limits

**Location:** `includes/rate_limiter.php` lines 11-37

**Code:**
```php
function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
    // Line 22: READ (no file lock)
    $data = json_decode(file_get_contents($file), true);
    // Lines 25-27: filter expired attempts
    // Line 31: check count
    if (count($attempts) >= $maxAttempts) { return false; }
    // Line 36: WRITE (with LOCK_EX)
    file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
}
```

**Description:** The `file_get_contents` on line 22 is NOT atomic with `file_put_contents` on line 36. Between the read and the write, concurrent requests can read the same stale file. The `LOCK_EX` flag on `file_put_contents` only locks during the WRITE operation, not during the read-check-write cycle.

Attack scenario for login brute force:
- Rate limit is 5 attempts per 60 seconds
- Attacker sends 20 concurrent login requests
- All 20 requests read the file simultaneously, seeing 0 attempts (or fewer than 5)
- All 20 pass the check on line 31
- All 20 write their attempt (last writer wins on the file)
- Result: 20 login attempts instead of 5

This undermines the security of login brute-force protection, registration abuse prevention, and API rate limiting.

**Impact:** Brute-force attacks against login, registration spam, API abuse. The rate limiter is the primary defense against credential stuffing, and it can be trivially bypassed with concurrent requests.

**Fix:**
```php
function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
    $file = $dir . '/' . md5($identifier . '_' . $action) . '.json';
    $fp = fopen($file, 'c+');
    if (!$fp) return false;

    // Exclusive lock for entire read-check-write cycle
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

    $data = json_decode(stream_get_contents($fp), true);
    $attempts = is_array($data) ? array_filter($data, fn($t) => (time() - $t) < $windowSeconds) : [];

    if (count($attempts) >= $maxAttempts) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $attempts[] = time();
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($attempts)));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
```

---

### P2-D1-006 | HIGH | Espionage Neutrino Deduction Not In Transaction (Double-Spend)

**Location:** `attaquer.php` lines 36-40

**Code:**
```php
dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,...)', ...);
$newNeutrinos = $autre['neutrinos'] - $_POST['nombreneutrinos'];
dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $_SESSION['login']);
$autre['neutrinos'] -= $_POST['nombreneutrinos'];
```

**Description:** The espionage launch at lines 36-40 deducts neutrinos using an absolute SET (not atomic `neutrinos=neutrinos-?`), and the entire flow has no transaction. The `$autre['neutrinos']` value was read during `initPlayer()` at page load time.

Two concurrent espionage requests:
1. Both read `$autre['neutrinos'] = 100`
2. Both validate `100 >= 50` at line 26
3. Both insert espionage actions
4. Both SET neutrinos to 50 (100-50)
5. Player spent 50 neutrinos but launched 2 espionage operations worth 100

This is the same race condition pattern as P2-D1-002 but for neutrinos instead of resources.

**Impact:** Players can launch more espionage operations than their neutrino balance allows. Combined with the espionage information advantage, this degrades game fairness.

**Fix:** Use `withTransaction` with FOR UPDATE on `autre`, and use atomic decrement: `UPDATE autre SET neutrinos=neutrinos-? WHERE login=? AND neutrinos >= ?`

---

### P2-D1-007 | HIGH | Attack Troop Deduction Race Before Transaction

**Location:** `attaquer.php` lines 110-142 (pre-transaction validation) vs. lines 153-169 (transaction)

**Code:**
```php
// Lines 110-142: OUTSIDE TRANSACTION - reads molecules, validates troop counts, computes cost
$moleculesAttaqueRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
// ...validation...
foreach ($moleculesAttaqueRows as $moleculesAttaque) {
    if (ceil($moleculesAttaque['nombre']) < $_POST['nbclasse' . $c]) { $bool = 0; }
}

// Lines 153-169: INSIDE TRANSACTION - re-reads with FOR UPDATE, deducts
withTransaction($base, function() use ($base, $cout, $troupes, $tempsTrajet) {
    $moleculesAttaqueTxRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $_SESSION['login']);
    // Line 157: Uses $_POST superglobal directly inside closure
    $newNombre = $moleculesAttaque['nombre'] - $_POST['nbclasse' . $c];
    if ($newNombre < 0) { throw new Exception('Pas assez de molecules'); }
});
```

**Description:** While the transaction at lines 153-169 does correctly re-read molecules with FOR UPDATE and validate, there is a subtle issue: the `$cout` (energy cost) was computed OUTSIDE the transaction at lines 139-141 based on stale molecule data. The transaction deducts energy at line 167 using `ajouter('energie', 'ressources', -$cout, ...)` which uses the pre-transaction cost.

If molecules are modified between lines 110 and 153 (e.g., another attack returns, adding molecules), the troop counts inside the transaction are correct (re-validated), but the energy cost is wrong because it was computed from stale molecule data. The actual energy cost could be different if molecule compositions changed.

Additionally, line 157 accesses `$_POST['nbclasse' . $c]` directly inside the closure rather than capturing it in the `use` clause. In PHP, `$_POST` is a superglobal and is available inside closures without explicit `use`, but this is a maintenance hazard and violates the principle of explicit data flow.

**Impact:** Players could be charged the wrong energy amount if concurrent operations modify their molecule data between validation and deduction.

**Fix:** Recompute `$cout` inside the transaction using the locked molecule data. Capture POST values in local variables before the closure.

---

### P2-D1-008 | HIGH | Voter.php Legacy GET Endpoint Allows CSRF-Free Vote Manipulation

**Location:** `voter.php` lines 24-26, 46

**Code:**
```php
// Lines 24-26: Legacy GET support -- no CSRF check
} elseif (isset($_GET['reponse'])) {
    $reponse = intval($_GET['reponse']);
}

// Line 46: pasDeVote flag accessible via GET (no CSRF)
$pasDeVote = $_POST['pasDeVote'] ?? $_GET['pasDeVote'] ?? null;
```

**Description:** The voter endpoint accepts votes via GET without CSRF protection. While POST requests go through `csrfCheck()`, GET requests skip it entirely. An attacker can craft an image tag or link: `<img src="voter.php?reponse=3">` and any logged-in player who loads the page will have their vote changed.

Furthermore, the `pasDeVote` parameter at line 46 is readable from GET, allowing an attacker to control whether an existing vote is updated (`pasDeVote=1` prevents the update).

**Impact:** Vote manipulation across all players via simple link injection (forum posts, alliance descriptions, external sites).

**Fix:** Remove the legacy GET support or require CSRF on GET requests. The comment says "will be removed in future" -- this should be done now.

---

### P2-D1-009 | HIGH | Season Reset Email HTML Injection via Player Login Names

**Location:** `includes/basicprivatephp.php` lines 157-164

**Code:**
```php
$message_txt = "Bonjour " . $donnees['login'] . " ! ...";
$message_html = "<html>...<body>Bonjour " . $donnees['login'] . " ! <b>" . $winnerName . "</b>...";
```

**Description:** Player login names are inserted directly into HTML email bodies without any escaping. Login names are validated at registration to be alphanumeric (via `validateLogin`), but the winner name `$winnerName` comes from `performSeasonEnd()` which reads from the database. If a player's login in the DB were somehow modified (SQL admin access, future code path), it could contain HTML/JavaScript.

More critically, the email MIME boundary uses `md5(rand())` at line 168:
```php
$boundary = "-----=" . md5(rand());
```
`rand()` is not cryptographically secure and only produces values in the range 0 to `getrandmax()` (typically 2^31). The boundary is predictable and could allow a MIME injection attack if the attacker can influence any part of the email content.

**Impact:** HTML injection in emails, potential phishing via crafted HTML content. MIME boundary predictability could allow attachment injection.

**Fix:**
```php
$safeName = htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8');
$message_html = "...Bonjour " . $safeName . "...";
$boundary = "-----=" . bin2hex(random_bytes(16));
```

---

### P2-D1-010 | HIGH | Alliance Tag Change Has No Format Validation (XSS via Alliance Tag in Reports)

**Location:** `allianceadmin.php` lines 119-135

**Code:**
```php
if (isset($_POST['changertag'])) {
    csrfCheck();
    if (!empty($_POST['changertag'])) {
        $_POST['changertag'] = trim($_POST['changertag']);
        $nballiance = dbCount($base, 'SELECT count(*) as nb FROM alliances WHERE tag=?', 's', $_POST['changertag']);
        if ($nballiance == 0) {
            // NO format validation -- any string is accepted
            dbExecute($base, 'UPDATE alliances SET tag=? WHERE id=?', 'si', $_POST['changertag'], $currentAlliance['idalliance']);
        }
    }
}
```

**Description:** Alliance tag change has NO format validation. Compare with alliance creation in `alliance.php` which requires `preg_match("#^[a-zA-Z0-9_]{3,16}$#", ...)`. The tag change accepts any string: `<script>alert(1)</script>`, SQL keywords, excessively long strings, etc.

The alliance tag is then used unsanitized in several critical locations:
- `allianceadmin.php` line 206-212: Pact proposal report content (HTML stored in DB)
- `allianceadmin.php` line 267: War declaration report content
- `allianceadmin.php` line 232-233: Pact termination report content
- `allianceadmin.php` lines 466, 514: Pact/war listing tables

While report content is passed through `BBCode()` when displayed (which calls `htmlentities` first), the alliance tag in the pact/war report at lines 206-212 is inserted into a form element:
```php
'<input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>'
```
The `$chef['tag']` on lines 206-207 is inserted into link text and HTML -- if it contains HTML entities, `htmlentities` in BBCode would double-encode it. But more importantly, the tag is used in `<a href="alliance.php?id=TAG">` patterns throughout the codebase, and a tag containing `"` or `>` characters could break out of the attribute context.

**Impact:** Stored XSS via alliance tag in combat reports, war declarations, and pact proposals. The tag persists in the database and is rendered to all alliance leaders who view their diplomacy pages.

**Fix:** Add the same regex validation used during creation:
```php
if (!preg_match("#^[a-zA-Z0-9_]{3," . ALLIANCE_TAG_MAX_LENGTH . "}$#", $_POST['changertag'])) {
    $erreur = "Le tag ne peut contenir que des lettres, chiffres et underscores.";
}
```

---

### P2-D1-011 | HIGH | Alliance Name Change Has No Validation (Unrestricted Input)

**Location:** `allianceadmin.php` lines 54-69

**Code:**
```php
if (isset($_POST['changernom'])) {
    csrfCheck();
    if (!empty($_POST['changernom'])) {
        $_POST['changernom'] = trim($_POST['changernom']);
        // Only checks uniqueness -- no format, length, or character validation
        $nballiance = dbCount($base, 'SELECT count(*) as nb FROM alliances WHERE nom=?', 's', $_POST['changernom']);
        if ($nballiance == 0) {
            dbExecute($base, 'UPDATE alliances SET nom=? WHERE id=?', 'si', $_POST['changernom'], $currentAlliance['idalliance']);
        }
    }
}
```

**Description:** No format validation, no length limit, no character restrictions. A malicious alliance leader could set their alliance name to a 10MB string (causing storage and display issues), control characters, or HTML content. The alliance name is displayed with `htmlspecialchars` in most places (line 63), but it is stored in the database without any length constraint enforcement at the application level.

**Impact:** Denial of service via extremely long names, display corruption, potential stored content injection if any display path misses escaping.

**Fix:** Add length and format validation matching the creation flow.

---

## Medium Findings

### P2-D1-012 | MEDIUM | Vacation Date Parsing Lacks Numeric Validation (DateTime Crash)

**Location:** `compte.php` lines 13-28

**Code:**
```php
if (isset($_POST['dateFin'])) {
    list($jour, $mois, $annee) = explode('/', $_POST['dateFin']);
    $dateT = new DateTime();
    $dateT->setDate($annee, $mois, $jour);
    if ($dateT->getTimestamp() >= time() + VACATION_MIN_ADVANCE_SECONDS) {
        // ...
    }
}
```

**Description:** The `$_POST['dateFin']` is split by `/` with no validation that:
1. The string contains exactly 2 `/` delimiters (could have fewer, causing `list()` to fail)
2. The parts are numeric (letters cause `setDate()` to use 0)
3. The parts form a valid date (month 13, day 32 will be silently adjusted by DateTime)

If `dateFin` is `a/b/c`, `setDate('c', 'b', 'a')` will silently set the date to a computed value based on PHP's string-to-int coercion (all 0), resulting in a negative timestamp, which would fail the check -- but the error handling is opaque to the user.

If `dateFin` is `01/01/2000/extra/stuff`, `list()` assigns only the first 3 values but PHP will emit a notice about extra values.

**Impact:** User confusion from opaque errors, potential PHP warnings in error logs, edge-case date manipulation.

**Fix:**
```php
$parts = explode('/', $_POST['dateFin']);
if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
    $erreur = "Format de date invalide. Utilisez JJ/MM/AAAA.";
} else {
    list($jour, $mois, $annee) = $parts;
    if (!checkdate((int)$mois, (int)$jour, (int)$annee)) {
        $erreur = "Date invalide.";
    } else {
        // proceed...
    }
}
```

---

### P2-D1-013 | MEDIUM | Email Validation Inconsistency Between Pages

**Location:** `compte.php` line 78 vs. `includes/validation.php`

**Code in compte.php:**
```php
if (preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", $_POST['changermail'])) {
```

**Code in validation.php:**
```php
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
```

**Description:** `compte.php` uses a custom regex for email validation that:
1. Only accepts lowercase letters (rejects `User@Example.com`)
2. Limits TLD to 2-4 characters (rejects `.museum`, `.community`, `.technology`)
3. Uses its own pattern instead of the centralized `validateEmail()` function

The inconsistency means emails accepted at registration (via `validateEmail`) could be rejected when changed on the account page, and vice versa.

**Impact:** User confusion, inconsistent security posture, potential bypass by finding emails accepted by one validator but rejected by the other.

**Fix:** Use `validateEmail()` from `validation.php` consistently across all email validation points.

---

### P2-D1-014 | MEDIUM | Attacker Login Displayed Without htmlspecialchars in Attack List

**Location:** `attaquer.php` lines 219, 221

**Code:**
```php
// Line 219 (outgoing attack)
echo '...<a href="joueur.php?id=' . $actionsattaques['defenseur'] . '">' . $actionsattaques['defenseur'] . '</a>...';
// Line 221 (outgoing espionage)
echo '...<a href="joueur.php?id=' . $actionsattaques['defenseur'] . '">' . $actionsattaques['defenseur'] . '</a>...';
```

**Description:** The defender's login name from the `actionsattaques` table is output directly into HTML without `htmlspecialchars()`. While login names are validated at registration to be alphanumeric, the data comes from the database -- if the database were compromised or if a future code path allowed non-alphanumeric logins, this would be a reflected XSS vector.

Compare with line 261 which correctly uses:
```php
htmlspecialchars($actionsattaques['attaquant'], ENT_QUOTES, 'UTF-8')
```

This shows inconsistent application of output encoding within the same file.

**Impact:** Stored XSS if login validation is ever weakened or database is compromised. Even now, this violates defense-in-depth principles.

**Fix:** Apply `htmlspecialchars($actionsattaques['defenseur'], ENT_QUOTES, 'UTF-8')` on lines 219 and 221.

---

### P2-D1-015 | MEDIUM | Static CSRF Token Per Session (No Rotation)

**Location:** `includes/csrf.php` lines 6-11

**Code:**
```php
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
```

**Description:** The CSRF token is generated once per session and never rotated. This means:
1. Any single token leak (via Referer header, log file, XSS, browser extension) compromises CSRF protection for the entire session
2. The token persists across password changes (only session_token is regenerated at line 64-66 of compte.php, not csrf_token)
3. Browser back-button caching can expose the token in page source

The token is correctly validated with `hash_equals()` (timing-safe), and SameSite=Lax cookies provide additional protection. However, per-request or per-form token rotation is a stronger defense.

**Impact:** Extended window of CSRF exploitation if token is leaked through any channel.

**Fix:** Consider rotating the CSRF token after each successful form submission, or at minimum after sensitive operations (password change, account deletion).

---

### P2-D1-016 | MEDIUM | SQL Query Logged with Full Statement on Prepare Failure

**Location:** `includes/database.php` lines 14, 61

**Code:**
```php
error_log("SQL Prepare Error: " . mysqli_error($base) . " | Query: " . $sql);
```

**Description:** When a prepared statement fails, the full SQL query string is logged including table names, column names, and query structure. While `error_log` writes to the server's error log (not user-visible), if the error log is ever exposed (misconfigured web server, log viewer tool, debug mode), it reveals the complete database schema.

The `mysqli_error($base)` call can also leak database version, table names, and column names in its error message.

**Impact:** Information disclosure of database schema if error logs are accessible. Aids SQL injection attacks if they exist.

**Fix:** Log a query hash or identifier instead of the full query:
```php
error_log("SQL Prepare Error: " . mysqli_error($base) . " | QueryID: " . md5($sql));
```

---

### P2-D1-017 | MEDIUM | Combat Report Contains Unescaped Building Percentages (Integer Overflow Edge Case)

**Location:** `includes/combat.php` lines 502-565

**Code:**
```php
$destructionGenEnergie = round($constructions['vieGenerateur'] / pointsDeVie($constructions['generateur']) * 100)
    . "% <img ...> "
    . max(round(($constructions['vieGenerateur'] - $degatsGenEnergie) / pointsDeVie($constructions['generateur']) * 100), 0) . "%";
```

**Description:** If `pointsDeVie($constructions['generateur'])` returns 0 (e.g., building at level 0 with a formula that computes 0 HP), this causes a division by zero. PHP 8+ throws a `DivisionByZeroError` (not `Exception`), which would not be caught by `withTransaction`'s `catch (Exception $e)` (see P2-D1-004).

The formula `pointsDeVie($niveau)` at formulas.php line 285 is:
```php
$base_hp = round(BUILDING_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
```
The `max(1, $niveau)` prevents level 0 from causing issues, but if `BUILDING_HP_BASE` were 0, the result would be 0. This is an unlikely edge case but demonstrates the fragility of the computation chain.

**Impact:** Division by zero error during combat resolution, potentially leaving partial combat state committed (per P2-D1-004).

**Fix:** Add a guard: `$hp = pointsDeVie(...); if ($hp <= 0) $hp = 1;`

---

### P2-D1-018 | MEDIUM | Pact Proposal Report Contains CSRF-less Form

**Location:** `allianceadmin.php` lines 207-212

**Code:**
```php
$rapportContenu = '...
    <form action="validerpacte.php" method="post">
    <input type="submit" value="Accepter" name="accepter"/>
    <input type="submit" value="Refuser" name="refuser"/>
    <input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
    </form>';
```

**Description:** The pact accept/reject form stored in the rapport is missing a CSRF token. When the alliance leader views this report and clicks Accept/Refuse, the POST to `validerpacte.php` will fail CSRF validation (line 5 of validerpacte.php calls `csrfCheck()`).

This means the pact acceptance flow is broken -- the form in the report will always fail with a CSRF error.

**Impact:** Alliance leaders cannot accept or reject pacts via the report system. This is both a security finding (missing CSRF field) and a functionality bug (the feature is broken).

**Fix:** Include the CSRF field in the report form:
```php
$rapportContenu = '...<form action="validerpacte.php" method="post">'
    . csrfField()
    . '<input type="submit" ...';
```
Note: This creates a different issue -- the CSRF token is captured at report creation time and may expire by the time the recipient views it. A better approach would be to use a dedicated accept/reject page that generates its own CSRF token.

---

### P2-D1-019 | MEDIUM | Charset Mismatch Between Connection and Database

**Location:** `includes/connexion.php` line 20

**Code:**
```php
mysqli_set_charset($base, 'utf8mb4');
```

**Description:** The connection charset is set to `utf8mb4`, but per the project memory, the database tables use `latin1` charset for FK compatibility. This mismatch means:

1. Characters outside the latin1 range will be silently truncated or replaced when stored
2. Multi-byte sequences in `utf8mb4` could be misinterpreted as multiple latin1 characters
3. String length calculations differ between the two charsets (a 4-byte emoji in utf8mb4 is 4 bytes, but in latin1 context it might be treated as 4 separate characters)

While this does not directly cause a security vulnerability with prepared statements (which handle encoding correctly), it can cause data corruption and potentially exploitable encoding confusion in string comparison operations.

**Impact:** Data corruption for non-ASCII characters, potential encoding-based bypasses in string matching, truncation attacks.

**Fix:** Either migrate all tables to `utf8mb4` (preferred for long-term), or set the connection charset to `latin1` to match the database.

---

### P2-D1-020 | MEDIUM | Attacker Action ID Injected Into JavaScript Variable Names

**Location:** `attaquer.php` lines 226-239, `marche.php` lines 408-421

**Code:**
```php
echo cspScriptTag() . '
    var valeur' . $actionsattaques['id'] . ' = ' . ($actionsattaques['tempsAttaque'] - time()) . ';
    function tempsDynamique' . $actionsattaques['id'] . '(){ ... }
    setInterval(tempsDynamique' . $actionsattaques['id'] . ', 1000);
</script>';
```

**Description:** The `$actionsattaques['id']` from the database is interpolated directly into JavaScript variable names and function names. While `id` is an auto-increment integer in the database, if it were ever corrupted or if the query returned unexpected data, the value would be injected directly into a `<script>` block.

The CSP nonce on the script tag prevents injection of new script blocks, but this is still a defense-in-depth concern. The same pattern appears in `marche.php` lines 408-421.

**Impact:** Low risk due to CSP nonce protection and integer auto-increment nature of IDs, but violates the principle of never interpolating database values into JavaScript.

**Fix:** Cast to integer before interpolation:
```php
$safeId = (int)$actionsattaques['id'];
echo cspScriptTag() . 'var valeur' . $safeId . ' = ...';
```

---

### P2-D1-021 | MEDIUM | BBCode [url] Tag Allows Arbitrary External URLs (Phishing Vector)

**Location:** `includes/bbcode.php` line 18

**Code:**
```php
$text = preg_replace('!\[url=(https?://[^\]]+)\](.+?)\[/url\]!isU',
    '<a href="$1" rel="noopener noreferrer" target="_blank">$2</a>', $text);
```

**Description:** The `[url]` BBCode tag allows any `https?://` URL. Since `htmlentities` is applied first (line 4), the URL is HTML-encoded, but the `href` attribute still accepts any external domain. An attacker can create forum posts or alliance descriptions containing:
```
[url=https://theverylittlewar.fakegamesite.com/login]Click here for free atoms[/url]
```

The `rel="noopener noreferrer"` and `target="_blank"` are good defenses against tab-napping, but the core phishing risk remains.

**Impact:** Phishing via in-game communication channels (forum, alliance descriptions, messages).

**Fix:** Consider adding a redirect warning page, or restricting URLs to a whitelist of trusted domains.

---

### P2-D1-022 | MEDIUM | BBCode [latex] Tag Could Enable Script Execution via Math Renderers

**Location:** `includes/bbcode.php` line 32

**Code:**
```php
$text = preg_replace('!\[latex\](.+)\[/latex\]!isU', '\$\$$1\$\$', $text);
```

**Description:** The `[latex]` tag converts content to `$$...$$` delimiters used by MathJax and KaTeX. If either library is loaded on the page, the LaTeX content is rendered by the math engine. Some MathJax/KaTeX versions allow arbitrary HTML through LaTeX commands like `\href{javascript:alert(1)}{click}` or `\style{...}{}`.

While `htmlentities` is applied first (line 4), the math renderer parses the encoded content and may interpret `&lt;` as `<` in certain rendering modes. The risk depends on which math library version is loaded and its configuration.

**Impact:** Potential XSS via math rendering engine if MathJax/KaTeX is present. Requires specific library version vulnerabilities.

**Fix:** If math rendering is not actively used, remove the `[latex]` tag. If it is used, configure the math renderer to disable dangerous commands (`\href`, `\style`, `\unicode`).

---

### P2-D1-023 | MEDIUM | Grade Permissions Parsing Fragile -- Malformed String Causes Warnings

**Location:** `allianceadmin.php` line 30

**Code:**
```php
list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
```

**Description:** If the `grade` string in the database has fewer than 4 `.` delimiters (e.g., `1.0.1`), `list()` will not have enough values to unpack and PHP will emit an `E_WARNING`. If it has MORE than 4 delimiters, extra values are silently discarded. If the grade string is empty or null, `explode` returns `['']` and `list()` fails.

The grade is written at line 92:
```php
$gradeStr = $droit_inviter . '.' . $droit_guerre . '.' . $droit_pacte . '.' . $droit_bannir . '.' . $droit_description;
```
This always produces 5 dot-separated values, but if the database row is manually modified or if a migration error occurs, the parsing breaks.

**Impact:** PHP warnings in error logs, potentially undefined permission variables leading to implicit `false` (which would deny access -- fail-safe, but with confusing behavior).

**Fix:**
```php
$parts = explode('.', $grade['grade'] ?? '');
$inviter = ($parts[0] ?? 0) == 1;
$guerre = ($parts[1] ?? 0) == 1;
$pacte = ($parts[2] ?? 0) == 1;
$bannir = ($parts[3] ?? 0) == 1;
$description = ($parts[4] ?? 0) == 1;
```

---

## Low Findings

### P2-D1-024 | LOW | Profile Image Filename Uses Predictable uniqid()

**Location:** `compte.php` line 131

**Code:**
```php
$fichier = uniqid('avatar_') . '.' . $extension;
```

**Description:** `uniqid()` is based on the current time in microseconds. It is predictable if the server time is known (approximately). An attacker who knows when another player uploaded their avatar can guess the filename. Combined with the known directory (`images/profil/`), this allows direct URL access to any avatar.

The actual security impact is minimal because avatars are intended to be public, and the MIME type + extension + `getimagesize()` validation (lines 104-124) prevents upload of non-image files. However, using a cryptographically random filename would be more robust.

**Impact:** Enumeration of avatar filenames, information disclosure of upload timestamps.

**Fix:**
```php
$fichier = bin2hex(random_bytes(16)) . '.' . $extension;
```

---

### P2-D1-025 | LOW | Session Cookie Not Secure on HTTP (Expected but Documented)

**Location:** `includes/session_init.php` line 10

**Code:**
```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```

**Description:** The session cookie's `Secure` flag is only set when HTTPS is detected. Per the project notes, the site currently runs on HTTP (HTTPS blocked on DNS). This means the session cookie is sent over plaintext HTTP, vulnerable to network sniffing (MITM, shared WiFi).

This is a known limitation documented in the project memory ("Batch F: HTTPS -- BLOCKED on DNS"), but it remains a security risk.

**Impact:** Session hijacking via network sniffing on HTTP.

**Fix:** Complete the HTTPS migration (Batch F) and then ensure `cookie_secure` is always 1.

---

### P2-D1-026 | LOW | Account Deletion Confirmation Form Missing CSRF Token

**Location:** `compte.php` lines 248-257

**Code:**
```php
// Inside the "suppression du compte" confirmation page
item(['input' => '
    <center>
        <input type="image" style="..." src="images/yes.png" name="oui" value="Oui"/>
        <input src="images/croix.png" style="..." type="image" name="non" value="Non"/>
        <input type="hidden" name="verification"/>
        ' . csrfField() . '
    </center>', 'form' => ["compte.php", "supprimerLeCompte"]]);
```

**Description:** The confirmation form DOES include `csrfField()` (line 256), and the page does call `csrfCheck()` at line 6. However, the form's action is `compte.php` and uses `type="image"` inputs, which submit the click coordinates as `oui_x`, `oui_y` (not `oui=Oui`). Line 8 checks:
```php
if (isset($_POST['verification']) and isset($_POST['oui'])) {
```

Since `type="image"` submits `oui.x` and `oui.y` (not `oui`), `$_POST['oui']` will NOT be set. PHP converts dots to underscores in POST keys, so `$_POST['oui_x']` exists but `$_POST['oui']` does not. This means the account deletion confirmation may never actually trigger.

Wait -- actually PHP converts dots in POST keys to underscores only in older versions. In PHP 8.2, `name="oui"` with `type="image"` sends `oui.x` and `oui.y`. PHP does convert the dot to underscore: `$_POST['oui_x']` and `$_POST['oui_y']`. So `$_POST['oui']` is NOT set, and the deletion never executes.

**Impact:** The account deletion feature is likely broken (cannot confirm deletion). This is a functional bug with security implications (users cannot exercise their right to delete their account).

**Fix:** Change the check to:
```php
if (isset($_POST['verification']) and (isset($_POST['oui_x']) or isset($_POST['oui']))) {
```
Or use a regular submit button instead of `type="image"`.

---

### P2-D1-027 | LOW | War Declaration Debug Echo Leaks Data

**Location:** `allianceadmin.php` line 251

**Code:**
```php
echo $nbDeclarations['nbDeclarations'];
```

**Description:** A debug `echo` statement outputs the count of existing declarations directly to the HTTP response body. This leaks internal database counts to the user and produces malformed HTML (the number appears before the proper page layout).

**Impact:** Minor information disclosure, broken HTML output during war declaration flow.

**Fix:** Remove the debug echo statement.

---

### P2-D1-028 | LOW | Market Resource Transfer Information Message Contains Unescaped Recipient Name

**Location:** `marche.php` lines 136, 138

**Code:**
```php
$information = "Vous avez envoye " . ... . ' a ' . $_POST['destinataire'];
```

**Description:** The recipient's name from `$_POST['destinataire']` is included in the success message without `htmlspecialchars()`. Since the recipient name was validated as an existing player (line 53-54), it must match a login in the database which is alphanumeric. However, the POST value itself is not the validated value -- it is the raw POST data. The validated check is `$verification['joueurOuPas'] == 1`, which confirms a player EXISTS with that login, but `$_POST['destinataire']` could have different casing or contain HTML if the DB comparison is case-insensitive.

**Impact:** Low risk reflected XSS if database login comparison is case-insensitive and the displayed name differs from the stored name.

**Fix:** Use `htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8')` in the information message.

---

### P2-D1-029 | LOW | Database ID Used As Molecule Identifier (Integer Type Mismatch)

**Location:** `includes/game_actions.php` lines 54, 66, 74

**Code:**
```php
$molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse']);
// ...
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'ds', (...), $actions['idclasse']);
```

**Description:** The `idclasse` field is bound as type `'s'` (string) in the prepared statement, but molecule IDs are likely integers. While MySQL/MariaDB will handle implicit type conversion, this is a type mismatch that could cause unexpected behavior with strict mode enabled. The neutrino special case at line 65 checks `if ($actions['idclasse'] != 'neutrino')` suggesting `idclasse` is sometimes a string literal.

The inconsistency between using `'s'` type for what is sometimes an integer ID and sometimes the string `'neutrino'` indicates a design issue where two different data types share the same column.

**Impact:** Potential type confusion, index bypass in strict SQL mode.

**Fix:** Separate the neutrino case from the molecule ID case explicitly, and use the correct bind type for each.

---

## Informational Findings

### P2-D1-030 | INFO | Combat System Uses include() Inside Transaction

**Location:** `includes/game_actions.php` line 134

**Code:**
```php
mysqli_begin_transaction($base);
try {
    // ... 25 lines of setup ...
    include("includes/combat.php"); // 700 lines of combat resolution
    // ... 200 lines of report generation ...
    mysqli_commit($base);
} catch (Exception $combatException) {
    mysqli_rollback($base);
}
```

**Description:** The entire combat.php file (700 lines) is `include()`d inside a manually managed transaction. This means:
1. Any fatal error in combat.php (syntax error, require failure) will not trigger the rollback
2. The transaction holds locks for the entire duration of combat resolution + report generation
3. Testing combat.php in isolation is difficult because it depends on variables set in the calling scope

This is not a vulnerability per se, but it increases the risk surface for P2-D1-004 (Throwable not caught) and creates long-running transactions that can cause lock contention.

**Impact:** Increased risk of partial transaction commits, lock contention under load.

**Fix:** Consider refactoring combat.php into a function that can be called within `withTransaction()`, and ensure the transaction wrapper catches `\Throwable`.

---

### P2-D1-031 | INFO | Multiple DB Queries Per Player Per Page Load

**Location:** `includes/basicprivatephp.php` lines 39-95, `includes/game_resources.php`, `includes/formulas.php`

**Description:** Each private page load triggers:
1. Session token validation query (line 12)
2. Position check query (line 39)
3. `initPlayer()` -- multiple queries for resources, constructions, molecules, etc.
4. `updateRessources()` -- reads tempsPrecedent, updates resources, updates timestamp
5. `updateActions()` -- reads and processes all pending actions
6. Online tracking queries (lines 59-78)
7. Vacation check query (line 82)
8. Season check queries (lines 119-123)

This amounts to 15-30+ database queries before any page-specific logic runs. While not a security vulnerability, it creates opportunities for race conditions between the early reads and later writes.

**Impact:** Performance concern that indirectly affects security (long page loads increase TOCTOU windows).

---

### P2-D1-032 | INFO | Cookie SameSite=Lax Does Not Protect Against Same-Site Attacks

**Location:** `includes/session_init.php` line 12

**Description:** `SameSite=Lax` allows cookies to be sent on top-level navigations from external sites (GET requests). This means an attacker on any subdomain of `theverylittlewar.com` (if subdomains exist) can read session cookies. Additionally, Lax does not prevent cookie sending on GET-based CSRF attacks (such as the voter.php GET endpoint in P2-D1-008).

**Impact:** GET-based CSRF attacks bypass SameSite=Lax protection.

---

### P2-D1-033 | INFO | MD5 Hash Used for Rate Limiter Filenames

**Location:** `includes/rate_limiter.php` line 17

**Code:**
```php
$file = $dir . '/' . md5($identifier . '_' . $action) . '.json';
```

**Description:** MD5 is used to generate filenames for rate limiter state files. Two different identifier+action pairs could theoretically produce the same MD5 hash (collision), causing rate limit state to be shared between unrelated operations. MD5 collision probability is extremely low for realistic inputs, but this is a theoretical weakness.

**Impact:** Negligible -- MD5 collisions for short strings are computationally infeasible.

---

### P2-D1-034 | INFO | Espionage Report Reveals Full Defender State

**Location:** `includes/game_actions.php` lines 376-464

**Description:** A successful espionage operation reveals the defender's complete state: all molecule formulas and counts, all resource quantities, all building levels and current HP, and their defensive formation. This is by game design, but the information asymmetry combined with the espionage cost bypass (P2-D1-006) could allow cheap intelligence gathering.

**Impact:** Game balance concern rather than security vulnerability.

---

## Summary Statistics

| Severity | Count |
|----------|-------|
| CRITICAL | 3 |
| HIGH | 8 |
| MEDIUM | 12 |
| LOW | 6 |
| INFO | 5 |
| **Total** | **34** |

### Critical Chain Analysis

The most dangerous combination of findings is:

1. **P2-D1-002** (resource transfer no transaction) + **P2-D1-003** (action processing no transaction) = **Resource duplication chain**. A player can send resources to an alt, and the receiving side can also be double-credited. Net result: unlimited resource generation.

2. **P2-D1-005** (rate limiter bypass) + **P2-D1-001** (CSRF open redirect) = **Account takeover chain**. Bypass rate limiting with concurrent requests, then use the open redirect to phish credentials.

3. **P2-D1-004** (withTransaction catches Exception not Throwable) + **P2-D1-030** (combat include inside transaction) = **Partial combat resolution**. A TypeError in combat.php leaves the transaction uncommitted but without explicit rollback, potentially causing inconsistent game state.

### Priority Remediation Order

1. **P2-D1-002** and **P2-D1-003** -- Resource duplication (immediate economic impact)
2. **P2-D1-001** -- CSRF open redirect (active attack vector)
3. **P2-D1-004** -- withTransaction Throwable fix (systemic risk)
4. **P2-D1-005** -- Rate limiter TOCTOU (brute force enablement)
5. **P2-D1-010** -- Alliance tag validation (stored XSS)
6. **P2-D1-006** -- Espionage double-spend (game integrity)
7. **P2-D1-008** -- Voter GET CSRF bypass (vote manipulation)
8. **P2-D1-009** -- Email HTML injection (phishing via email)
9. Remaining findings in severity order
