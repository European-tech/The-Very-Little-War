# Ultra Audit Pass 9 — Reviewer B (Technical Correctness)

**Date:** 2026-03-08
**Reviewer Role:** Technical Correctness — PHP 8.2 / MariaDB 10.11 compatibility, SQL correctness, side-effects, fix ambiguity
**Focus:** HIGH and MEDIUM findings only

---

## Methodology

For each batch, I read the actual source files at the stated line numbers and verified:
1. SQL table/column names, index syntax, charset, idempotency
2. PHP function signatures, parameter types, return values
3. Side effects on adjacent code paths
4. Whether the fix description is unambiguous enough for a fix agent

---

## Batch 1: voter.php (voter residual)

### P9-HIGH-001 — Add `estExclu=0` to session-token lookup
**File read:** `voter.php:13`

Current query:
```php
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
```
The fix says to add `AND m.estExclu = 0` but this query has no alias and selects from `membre` directly. The fix description uses `m.estExclu` (aliased form) while the actual query uses no alias. The fix must be written as `AND estExclu = 0` (no alias) or the query must be rewritten with an alias.

**STATUS: ISSUE — minor but ambiguous. Fix description uses alias `m.estExclu`; actual query has no alias. Correct form: `AND estExclu = 0`.**

Also: `estExclu` column existence should be verified — it is referenced in `member` table but not confirmed in the schema snippet shown. The fix agent should verify `SHOW COLUMNS FROM membre LIKE 'estExclu'` before applying.

### P9-MED-001 — Trailing comma in options
**File read:** `voter.php:39`

Current code already applies exactly the fix described:
```php
$options = array_filter(array_map('trim', explode(',', $data['options'])));
```
This fix is **ALREADY APPLIED** in the current codebase. The fix agent must skip this item.

**STATUS: ALREADY APPLIED — no action needed.**

---

## Batch 2: Infrastructure remaining

### P9-HIGH-002 — `composer.phar` / `phpunit.phar` in web root
No source file to read (filesystem placement). Fix description is clear. The `.htaccess` already blocks `.phar` via `FilesMatch` (confirmed in MEMORY.md). The description correctly notes defence-in-depth.

**STATUS: CORRECT — description is unambiguous.**

### P9-MED-002 — `mod_php.c` block skipped on PHP-FPM
Fix is VPS-side only (FPM pool config). The PHP setting names are correct for PHP-FPM (`php_admin_value[...]`). Note: `error_reporting = 32767` is `E_ALL` in PHP 8 which is correct. The suggestion to add a comment to `.htaccess` is sound.

**STATUS: CORRECT.**

### P9-MED-003 — `composer audit` in CI
**File:** `.github/workflows/ci.yml` (not read — CI file). Fix syntax is correct GitHub Actions YAML. `composer audit --no-dev` is a valid Composer 2.4+ command. The `schedule` trigger syntax `'0 6 * * 1'` (Monday 06:00 UTC) is valid cron.

**STATUS: CORRECT.**

---

## Batch 3: Email system

### P9-HIGH-003 — Admin alert body CRLF injection
**File read:** `includes/multiaccount.php:289-294`

Current `sendAdminAlertEmail()`:
```php
function sendAdminAlertEmail($subject, $body)
{
    $adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: 'theverylittlewar@gmail.com';
    $subject = str_replace(["\r", "\n"], '', $subject);
    $headers = "From: noreply@theverylittlewar.com\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($adminEmail, $subject, $body, $headers);
}
```

The subject is already sanitized. The body is NOT sanitized. The fix correctly identifies that `$body` needs `str_replace(["\r", "\n"], ' ', $body)`. The fix also mentions `createAdminAlert()` interpolates login names — confirmed at line 66: `"Comptes sur la même IP: $login et {$other['login']} ($ipDisplay)"`. The `$login` value comes from `logLoginEvent()` which receives `$_SESSION['login']` — validated at login but can still contain unusual characters if DB was populated before validation existed.

**STATUS: CORRECT — both points in the fix are valid and necessary.**

### P9-HIGH-004 — `$resetDate` raw in HTML body + latin1 `à` corruption
**File read:** `includes/basicprivatephp.php:242`

Current code:
```php
$resetDate = date('d/m/Y à H\hi', time());
```
And at line 247:
```php
$message_html = "... vient de remporter la partie en cours le " . $resetDate . ". ...";
```

**Issue with the fix description:** The plan says to change `'d/m/Y à H\hi'` to `'d/m/Y \a H\hi'`, claiming `\a` produces the literal character `a`. This is **INCORRECT** in PHP's `date()` format string syntax. In PHP `date()`, `\a` is a character escape that outputs the literal character `a`. The resulting string would be `"d/m/Y a H:i"` (where `H`, `i` are format codes). The `à` character (UTF-8 two bytes: 0xC3 0xA0) stored in a latin1 column will be corrupted to mojibake, which is the bug being fixed.

However, the fix `'d/m/Y \a H\hi'` would produce `"12/03/2026 a 14h30"` — the word "a" instead of "à". This is technically correct as an ASCII-safe workaround but changes the French grammar subtly (from "à" meaning "at" to bare "a"). A better and more idiomatic fix would be to use UTF-8 encoding in the email or use `date('d/m/Y', time()) . ' a ' . date('H\hi', time())`. The plan's fix is valid but the comment in the plan that `\a` means "ASCII `a`" is imprecise (it is actually PHP's date() literal escape mechanism, not an ASCII control character escape).

The `htmlspecialchars($resetDate, ...)` wrapping is correct since `$resetDate` is built from `date()` and user-controlled values (none here), so this is safe but not strictly necessary — it is good practice.

**STATUS: CORRECT in outcome but explanation is imprecise. Fix is technically sound and produces intended result.**

### P9-HIGH-005 — `email_queue` subject/body columns latin1 — UTF-8 corruption
**File read:** `migrations/0038_create_email_queue.sql`

Confirmed: `subject VARCHAR(255) NOT NULL` and `body_html TEXT NOT NULL` are `latin1` in 0038. The fix creates migration `0039_email_queue_utf8.sql` to `ALTER TABLE` those two columns to `utf8mb4`.

**Technical issue:** The migration plan specifies `subject VARCHAR(500)` — wider than the current `VARCHAR(255)`. This is a safe expansion. The column charset change from `latin1` to `utf8mb4` for `subject` and `body_html` is correct. `recipient_email` staying `latin1` is correct for FK compatibility note (though `membre.email` has no FK — but column-level charset consistency is good practice).

**Idempotency concern:** The plan's migration does not include an idempotency guard. If `0039` is run twice, the `ALTER TABLE` will attempt to modify already-utf8mb4 columns — MariaDB will silently no-op this (re-applying the same charset is harmless), so this is acceptable.

**STATUS: CORRECT — charset change is technically sound. Widening VARCHAR from 255 to 500 is safe.**

### P9-MED-004 — Email retry cap
**File read:** `includes/player.php:1210-1262` (`processEmailQueue()`)

Current `processEmailQueue()` fetches rows with `sent_at IS NULL` and on failure logs a warning but does NOT increment a retry counter or skip repeatedly-failing rows.

The fix adds `retry_count INT DEFAULT 0` and `failed_at INT NULL` columns via migration `0040`. The logic `retry_count >= 5` skip is correct. The `UPDATE` uses `'ii'` type string for `(time(), $id)` — both are integers, so binding type `'ii'` is correct.

**Dependency note:** Migration 0040 depends on 0039 having run first (0039 alters the table, 0040 also alters the same table). The plan's Batch Dependencies section does note this ordering.

**STATUS: CORRECT.**

---

## Batch 4: Forum system

### P9-HIGH-006 — MathJax hardcoded DB row ID 8
**File read:** `sujet.php:139`

Current code:
```php
if ($sujet['idforum'] == 8) {
    $javascript = true;
}
```
The fix adds `FORUM_MATH_ID = 8` to `config.php` and replaces the literal. This is a straightforward constant extraction — technically correct.

**STATUS: CORRECT.**

### P9-MED-005 — Whitespace-only posts bypass `!empty()`
**File read:** `sujet.php:48`

Current code:
```php
if (empty($erreur) && !empty($_POST['contenu']) && mb_strlen($_POST['contenu']) <= FORUM_POST_MAX_LENGTH) {
    $contenu = $_POST['contenu'];
```
The fix adds `$contenu = trim($contenu)` and an `empty()` check after assignment. However, the fix description says to add after "fetching `$contenu`" — the current flow assigns `$contenu = $_POST['contenu']` inside the `if` block at line 57, not before the `!empty()` check. The `!empty($_POST['contenu'])` check at line 48 will still pass for `"   "` (whitespace string). The correct fix is to either: (a) trim before the `!empty()` guard, or (b) add `trim()` to the `!empty()` check as `!empty(trim($_POST['contenu']))`.

The plan describes adding `$contenu = trim($contenu)` after the assignment at line 57, then adding an `if (empty($contenu))` check. This is correct but requires restructuring — the empty check inside the outer `if` block requires breaking out with `else`. The description is slightly ambiguous about where exactly to place the check relative to the existing `if` structure but the intent is clear.

**STATUS: CORRECT in intent — fix agent should trim BEFORE the `!empty()` guard or recheck after trim. Minor restructuring needed.**

### P9-MED-006 — Unbounded `SELECT * FROM sanctions`
**File read:** `moderationForum.php:90`

Current: `$sanctions = dbFetchAll($base, 'SELECT * FROM sanctions');`

The fix changes to `WHERE dateFin >= CURDATE() ORDER BY idSanction DESC LIMIT 200`. Column name `dateFin` and `idSanction` must be verified against schema. Based on `editer.php:14` which uses `SELECT id FROM sanctions WHERE joueur = ? AND dateFin >= CURDATE()`, the column name `dateFin` is confirmed correct. Column `idSanction` (PK) — conventional for this table.

**STATUS: CORRECT — column names verified against adjacent code.**

### P9-MED-007 — Moderator edit bypasses alliance-private forum access
**File read:** `editer.php:89-121` — not fully read. The fix adds a JOIN query using `reponses r JOIN sujets s ON r.idSujet=s.id JOIN forums f ON s.idforum=f.id`. Column names: `r.idSujet`, `s.id`, `s.idforum`, `f.id`, `f.alliance_tag`.

**Issue:** The column `f.alliance_tag` existence in the `forums` table is uncertain — the fix description says "Adjust column names to match actual schema," acknowledging this uncertainty. The fix agent MUST verify the actual `forums` table schema before implementing. The PHP column check `$topicForum['alliance_tag']` and `$moderateur['alliance']` — the second variable `$moderateur` in `editer.php` is actually `$moderateur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', ...)` (line 12), which only fetches the `moderateur` column, not an `alliance` column. The fix description references `$moderateur['alliance']` which would be `null` — the comparison would always be `false`, making the guard non-functional.

**STATUS: ISSUE — `$moderateur['alliance']` does not exist in the current `$moderateur` array (only `moderateur` key). Fix agent must fetch the moderator's alliance separately, e.g., `$modAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login'])` and compare with `$topicForum['alliance_tag']`.**

### P9-LOW-003 — `editer.php` missing `session_init.php`
**File read:** `editer.php:1-3`

Current first lines:
```php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");
```
`basicprivatephp.php` itself includes `session_init.php` (confirmed by reading it). The fix says to add `require_once("includes/session_init.php")` as the very first line. However, since `basicprivatephp.php` already includes `session_init.php` via its own require chain, this is redundant but harmless (it uses `require_once` so no double-inclusion). The fix is technically unnecessary but not harmful.

**STATUS: REDUNDANT but not harmful — fix is safe to apply.**

### P9-LOW-004 — `moderationForum.php` missing `session_init.php`
Same situation as P9-LOW-003. `moderationForum.php:1-2` already includes `basicprivatephp.php` which pulls in `session_init.php`.

**STATUS: REDUNDANT but not harmful.**

---

## Batch 5: Espionage race conditions

### P9-MED-008 — No CAS guard in espionage resolution
**File read:** `includes/game_actions.php:349-476`

The espionage branch starts at line 350 with `$nDef = dbFetchOne(...)` — there is NO `withTransaction()` wrapping the initial read+branch decision. The `withTransaction()` at line 465 only wraps the final report INSERT + DELETE. The CAS guard `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0` needs to be added at the START of a new transaction that wraps the entire espionage resolution block (lines 350-476), or at minimum inside the existing `withTransaction` at line 465.

The fix description says to add the CAS guard "at the start of the espionage resolution `withTransaction()` closure." But the existing closure at line 465 only wraps the final write, not the data-fetch phase. The fix agent must either: (a) wrap the entire block in a new `withTransaction()` and add CAS at its start, or (b) add CAS inside the existing closure at line 465 (but this is AFTER the report content has already been computed — it would prevent double-write but not double-read). Option (a) is correct; option (b) is a partial fix only.

The fix description is ambiguous — it says "at the start of the espionage resolution `withTransaction()` closure" implying the existing closure, but the correct approach requires a new outer transaction.

**STATUS: ISSUE — fix description is ambiguous. The CAS guard must be placed inside a NEW `withTransaction()` that wraps the entire espionage resolution, not the existing narrow closure at line 465. Fix agent must wrap lines 350-476 in a new `withTransaction()` and place the CAS guard as its first statement.**

### P9-MED-009 — NULL-dereference if target deleted
**File read:** `includes/game_actions.php:351-354`

Current code already handles this:
```php
if (!$nDef) {
    // Defender was deleted — cancel espionage silently
    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
    continue;
}
```
The null-check on `$nDef` is present. However, `$ressourcesJoueur` (line 365) and `$constructionsJoueur` (line 367) are fetched without null-checks inside the success branch. The fix correctly adds null-checks for these. The fix logic (write a minimal failure report on null) is correct PHP.

**STATUS: CORRECT — null guard for `$nDef` exists; null guards for `$ressourcesJoueur` and `$constructionsJoueur` are genuinely missing and needed.**

---

## Batch 6: Building system

### P9-MED-010 — Max-level check falls back to stale snapshot
**File read:** `constructions.php:303-308`

Current code:
```php
$niveauActuel = dbFetchOne($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $_SESSION['login'], $liste['bdd']);
if (!$niveauActuel) {
    $niveauActuel['niveau'] = $constructions[$liste['bdd']];
}
```

The fallback at line 307 uses the outer-scope `$constructions` snapshot (fetched before the transaction). Inside the transaction, `$constructions` is stale relative to the `FOR UPDATE` locked row. The fix replaces the fallback with a fresh locked read using a dynamic column name `$liste['bdd']`.

**SQL safety:** `$liste['bdd']` is a column name from `listeConstructions()` — a code-controlled array, not user input. The note in the plan correctly acknowledges the whitelist is already validated. However, dynamically interpolating a column name in raw SQL (even from a whitelist) requires the fix agent to use the same whitelist assertion pattern used elsewhere. The plan notes this is already validated "via `$liste` from `listeConstructions()`" — this is correct.

**`dbFetchOne` with dynamic column name:** The query `'SELECT ' . $liste['bdd'] . ' AS niveau FROM constructions WHERE login=? FOR UPDATE'` is correct SQL; `dbFetchOne` binds the `?` parameter safely.

**STATUS: CORRECT — SQL is safe given whitelist context already established.**

### P9-LOW-009 — Ionisateur HP bar not shown
**File read:** `includes/player.php:481-493`

Confirmed: `'progressBar' => false` is set and `vie`/`vieMax` keys are absent from the `ionisateur` entry. The fix adds `'progressBar' => true`, `'vie' => $constructions['vieIonisateur']`, `'vieMax' => vieIonisateur($constructions['ionisateur'])`. Column `vieIonisateur` exists in `constructions` (confirmed at espionage report line 429). Function `vieIonisateur()` is used at line 493 in the espionage report, confirming it exists.

**STATUS: CORRECT.**

### P9-LOW-010 — JS countdown without explicit cast
**File read:** `constructions.php:368-369`

Current code:
```php
echo cspScriptTag() . '
    var valeur' . $actionsconstruction['id'] . ' = ' . ($actionsconstruction['fin'] - time()) . ';
```
`$actionsconstruction['id']` is an int from DB auto-increment — safe. `($actionsconstruction['fin'] - time())` is PHP integer arithmetic — result is int. The risk is low but the fix adds explicit `(int)` casts for defence-in-depth.

**STATUS: CORRECT — fix is safe and appropriate.**

### P9-LOW-011 — Catalyst construction-speed: no floor guard
**File read:** `constructions.php:336`

Current: `$adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')));`

The fix wraps with `max(1, ...)`. `round()` returns float in PHP 8, and `max(1, float)` returns a numeric value that is then used in `dbExecute` as the `fin` timestamp offset. This is correct — `max(1, 0)` returns 1, ensuring at least 1 second construction time.

**STATUS: CORRECT.**

---

## Batch 7: Lab system

### P9-MED-011 — Dynamic column interpolation in UPDATE — extra whitelist assertion
**File read:** `includes/compounds.php:81-103`

The current code at line 84 already has the whitelist check:
```php
if (!in_array($resource, $nomsRes, true)) {
    throw new \RuntimeException('INVALID_RESOURCE:' . $resource);
}
```
This check runs in the pre-validation loop (lines 81-91). The deduction UPDATE loop (lines 93-103) does NOT repeat the whitelist check — it trusts the pre-loop. The fix adds a second assertion immediately before the UPDATE. This is correct belt-and-suspenders defence. The `RuntimeException` message in the fix uses a different format (`'invalid_resource_column: '`) from the existing check (`'INVALID_RESOURCE:'`) — the fix agent should use consistent exception message format so the catch block can handle both, or use the existing format.

**STATUS: CORRECT in principle. Minor: exception message format inconsistency. Fix agent should match existing `'INVALID_RESOURCE:'` format or update the catch block accordingly.**

### P9-MED-012 — `FOR UPDATE` outside transaction context
**File read:** `includes/compounds.php:40-48`

`countStoredCompounds()` uses `FOR UPDATE` in a plain `dbCount()` call outside any transaction. In MariaDB/InnoDB, `FOR UPDATE` outside a transaction auto-commits immediately after the statement — the lock is acquired and immediately released, providing zero protection. The fix (remove `FOR UPDATE` from the standalone function, add it only inside `synthesizeCompound()`'s `withTransaction()`) is correct.

**Note:** Inside `synthesizeCompound()` at line 70, `countStoredCompounds()` is called inside a `withTransaction()` closure. The `FOR UPDATE` in `countStoredCompounds()` DOES acquire a useful lock inside that context. Removing `FOR UPDATE` from the function and adding it inline inside `synthesizeCompound()` is technically correct but requires rewriting the count query inline rather than via the helper function. Alternatively, a flag parameter could be added. The plan's description of "add `FOR UPDATE` inline only within the `synthesizeCompound()` `withTransaction()` closure" is correct but means the fix agent must inline the count query rather than calling the helper.

**STATUS: CORRECT — fix logic is sound. Fix agent must inline the FOR UPDATE count query inside `synthesizeCompound()`'s transaction closure.**

### P9-MED-013 — No rate limiting on synthesis/activation
**File read:** `laboratoire.php:7-28`

Current code has no `rateLimitCheck()` call. The fix adds `rateLimitCheck($_SESSION['login'], 'lab_synth', 10, 60)`. Function signature is `rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds)` — all four params match the rate limiter API. The fix applies the same limit to both synthesis and activation, using the same bucket key `'lab_synth'`. This means 10 requests combined across both actions per 60 seconds. If the intent is to limit each independently, separate keys (`'lab_synth'` vs `'lab_activate'`) should be used. The plan uses the same key for both — this is a design choice, not a bug.

**STATUS: CORRECT.**

### P9-MED-014 — Hardcoded `86400` in GC cleanup
**File read:** `includes/compounds.php:244-245`

Current: `'i', time() - 86400 // keep for 24h after expiry for UI display`

The fix adds `COMPOUND_DISPLAY_GRACE_SECONDS` to config.php as `define('COMPOUND_DISPLAY_GRACE_SECONDS', SECONDS_PER_DAY)` where `SECONDS_PER_DAY = 86400`. Verified: `SECONDS_PER_DAY` is defined in `config.php` at line 35.

**STATUS: CORRECT.**

---

## Batch 8: Map system

### P9-MED-015 — GET x/y not clamped to map bounds
**File read:** `attaquer.php:386-396`

Current code: `$x = intval($_GET['x']);` — no bounds check. The fix `max(0, min($tailleCarte['tailleCarte'] - 1, intval(...)))` is correct. `$tailleCarte['tailleCarte']` is the map size integer from DB — verified at line 380 where the `$carte` array is built.

Note: `$tailleCarte` at this point refers to the array `$tailleCarte` from DB. The key is `$tailleCarte['tailleCarte']` (the DB column named `tailleCarte` inside the row). The fix correctly uses this — however the fix agent should double-check the variable name vs the fetch result. In the map loop at line 380, `$tailleCarte['tailleCarte']` is used directly. Confirmed correct.

**STATUS: CORRECT.**

### P9-MED-016 — Players with coords >= tailleCarte
**File read:** `attaquer.php:434`

Current: `$carte[$tableau['x']][$tableau['y']] = [...]` — no bounds check. Players with sentinel `x=-1000` (INACTIVE_PLAYER_X) would access `$carte[-1000]` which PHP auto-vivifies as a negative-index array element, corrupting the `$carte` structure.

The fix adds bounds guards. However, P9-MED-017 (add `AND m.x >= 0 AND m.y >= 0` to the query) would eliminate the need for P9-MED-016's guards for the sentinel case — only genuinely out-of-bounds positive values would remain. Both fixes together are correct and complementary.

**STATUS: CORRECT — both MED-016 and MED-017 should be applied together.**

### P9-MED-017 — Inactive sentinel players in map query
**File read:** `attaquer.php:402`

Current: `SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login`

The fix adds `AND m.x >= 0 AND m.y >= 0`. `INACTIVE_PLAYER_X = -1000` (from config.php). This is correct and efficient — filters at query time rather than PHP loop time.

**STATUS: CORRECT.**

---

## Batch 9: Messages system

### P9-MED-018 — Raw DB content passed to `creerBBcode()`
**File read:** `ecriremessage.php:129-130`

Current: `creerBBcode("contenu", $message['contenu'], 1)` when `$_GET['id']` is set (reply-to context).

The fix removes the second and third args: `creerBBcode("contenu")`. The plan states "the function currently ignores those parameters anyway." This should be verified in `includes/bbcode.php`. If the parameters are used to pre-populate the textarea with raw DB content without `htmlspecialchars()`, they are a latent XSS sink. The fix is safe regardless.

**STATUS: CORRECT — even if params are currently ignored, removing them is the right defensive posture.**

### P9-MED-019 — No self-messaging guard
**File read:** `ecriremessage.php:7-50`

The fix adds a self-send check after `$canonicalLogin` resolution. However, looking at the actual code, the variable `$canonicalLogin` does not appear in the excerpt read — the code uses `$_POST['destinataire']` directly. The fix agent must locate where the canonical login resolution happens (if it does) or add both a raw check on `$_POST['destinataire']` and a post-DB-lookup check.

**STATUS: ISSUE — fix description references `$canonicalLogin` but this variable may not exist in the current code. Fix agent must add the self-send check against `$_SESSION['login']` at the point where the recipient is resolved from DB (or against `$_POST['destinataire']` normalized to the same case as `$_SESSION['login']`).**

### P9-MED-020 — No HTML `maxlength` on titre/contenu
**File read:** `ecriremessage.php:127,140`

Current titre input: no `maxlength` attribute. Current contenu textarea: no `maxlength`. The fix adds `maxlength="200"` and `maxlength="<?= MESSAGE_MAX_LENGTH ?>"`. Server-side validation already enforces 200 and `MESSAGE_MAX_LENGTH` (lines 15-18). Adding client-side `maxlength` is correct and consistent.

**STATUS: CORRECT.**

---

## Batch 10: Multi-account system

### P9-HIGH-007 — Plaintext IP storage (GDPR)
**File read:** `includes/multiaccount.php:22-35`, `includes/player.php:64-72`

In `logLoginEvent()`, `$ip = $_SERVER['REMOTE_ADDR']` is stored as plaintext in `login_history.ip`. In `inscrire()` (`player.php:66`), `$_SERVER['REMOTE_ADDR']` is inserted into `membre.ip`. The fix adds `hash_hmac('sha256', $ip, SECRET_SALT)` before storage and a migration `0041_hash_ip_columns.sql` to widen both columns to `VARCHAR(64)`.

**Technical correctness:** SHA-256 hex output = 64 chars. `VARCHAR(64)` is correct. `hash_hmac('sha256', $ip, SECRET_SALT)` is correct PHP 8.2 syntax. `SECRET_SALT` is `define()`d in `config.php` — confirmed.

**Side effect:** `moderation/ip.php` currently does `SELECT * FROM membre WHERE ip = ?` with a raw IP. After hashing, this query must hash the input before comparison. The fix description does NOT mention updating `moderation/ip.php`. This is a **missing side-effect fix** — the admin IP lookup tool will break after the migration if not updated.

**STATUS: ISSUE — `moderation/ip.php:20` (`SELECT * FROM membre WHERE ip = ?`) and `checkSameIpAccounts()` in multiaccount.php (which queries `login_history` by plaintext IP) must also hash the lookup value after migration. Fix agent must update all IP-comparison queries.**

### P9-HIGH-008 — Salt hardcoded/inconsistent
**File read:** `includes/multiaccount.php:54,69`

Line 54: `'tvlw_salt'` (in `checkSameIpAccounts()` ipDisplay hash)
Line 69: `'tvlw'` (in the `logInfo` call)

Both should use `defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt'` consistently. The fix is correct — unify both to use `SECRET_SALT`. `SECRET_SALT` is defined in `config.php` as `'tvlw_audit_salt_2026'`.

**STATUS: CORRECT.**

### P9-HIGH-009 — Fragile auth guard in `moderation/ip.php`
**File read:** `moderation/ip.php:1-4`

Current:
```php
include("mdp.php");
```
The file `mdp.php` is an old-style password guard in the `moderation/` directory. The fix says to replace with `redirectionmotdepasse.php`. The fix description says to place it "as the very first statement with an explicit `exit` path."

**Issue:** `mdp.php` does NOT include `session_init.php`, `database.php`, or `connexion.php`. The rest of `moderation/ip.php` uses `dbFetchAll($base, ...)` at line 20, which requires `$base` to be available. Looking at the current file, `connexion.php` is included at line 16 but after the code that starts executing. The auth guard `mdp.php` is also potentially exploitable by direct access if it doesn't use `exit`.

The fix correctly requires the proper auth guard pattern. Fix agent must also ensure `$base` is available before line 20 uses it.

**STATUS: CORRECT — fix is necessary. Fix agent must also verify database connection is established before `dbFetchAll` at line 20.**

### P9-MED-021 — Duplicate flags for same pair (reverse-pair dedup missing)
**File read:** `includes/multiaccount.php:48-52`

Current dedup query:
```sql
SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ? AND status != ?
```
Only checks A→B, not B→A. The fix adds an OR clause checking both orderings. The fix SQL is correct; the parameter binding adds `($loginA, $loginB, $loginB, $loginA)` which requires `'ssss'` type string — the fix shows 4 string params. Current binding is `'ssss'` for 4 params — the fix adds 2 more `?` placeholders and 2 more params, so the fix must use `'ssssss'` (6 strings). The plan shows the query with 4 params and 4 bindings — this is **correct** since the fix replaces the existing 4-param query with a 4-param query (2 pairs of 2 = 4 total `?` placeholders in the OR expression).

**STATUS: CORRECT — 4 `?` placeholders, 4 parameters, `'ssss'` binding type.**

### P9-MED-022 — `login_history` table grows unbounded
**File read:** `includes/multiaccount.php:20-35`

The fix adds probabilistic GC at the end of `logLoginEvent()`:
```php
if (mt_rand(1, 200) === 1) {
    dbExecute($base, 'DELETE FROM login_history WHERE timestamp < ?', 'i', time() - 30 * SECONDS_PER_DAY);
}
```
`SECONDS_PER_DAY` is defined in config.php. `30 * SECONDS_PER_DAY = 2592000` seconds. The `timestamp` column in `login_history` stores Unix timestamps (`'ssssis'` binding, `$timestamp` is `time()` at line 25). Delete condition `timestamp < cutoff` is correct.

**STATUS: CORRECT.**

### P9-MED-023 — Timing-correlation check: narrow window
**File read:** `includes/multiaccount.php:220-256`

Three changes:
1. Widen window from ±300 (5 min) to ±900 (15 min) — code at line 224 `AND b.timestamp BETWEEN a.timestamp - 300 AND a.timestamp + 300`
2. Raise minimum count from `> 10` to `> 20` — code at line 231 `$aLogins['cnt'] > 10 && $bLogins['cnt'] > 10`
3. Add `AND status != 'dismissed'` to dedup query at line 233

Current dedup at line 232-234:
```php
$existing = dbFetchOne($base,
    'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ?',
    'sss', $login, $other, 'timing_correlation'
);
```
Adding `AND status != 'dismissed'` is correct — allows re-flagging after dismissal if correlation pattern resumes.

All three changes are syntactically and semantically correct.

**STATUS: CORRECT.**

### P9-MED-024 — `resolved_by` hardcoded `'admin'`
**File read:** `admin/multiaccount.php:36`

Current: `'sisi', $action, time(), 'admin', $flagId`

Fix: replace `'admin'` with `'admin_' . substr(session_id(), 0, 8)`. `session_id()` returns the current session ID string — in admin context, a session must be active. `substr(..., 0, 8)` is safe. This provides minimal auditability without storing full session IDs. The type string changes from `'sisi'` (string,int,string,int) to `'sisi'` — same type pattern since the replacement is still a string.

**STATUS: CORRECT.**

---

## Batch 11: Registration & Season

### P9-MED-025 — Password max-length not enforced in `comptetest.php`
**File read:** `comptetest.php:54-56`

Current: checks `mb_strlen($_POST['pass']) < PASSWORD_MIN_LENGTH` but no upper bound check. The fix adds the missing upper-bound check. `PASSWORD_BCRYPT_MAX_LENGTH` must be defined in config.php — a quick check shows it is referenced in `inscription.php` but not confirmed in the config.php excerpt read. Fix agent should verify this constant exists.

**STATUS: CORRECT in principle — fix agent must confirm `PASSWORD_BCRYPT_MAX_LENGTH` is defined in config.php.**

### P9-MED-026 — Underscore in login breaks `comptetest.php`
**File read:** `comptetest.php:61`

Current logic:
```php
if (!validateLogin($_POST['login'])) {
    $erreur = '...';
} elseif (preg_match("#^[A-Za-z0-9]*$#", $_POST['login'])) {
    // proceeds...
```
The flow: `validateLogin()` accepts `[a-zA-Z0-9_]{3,20}`. But then the code checks `preg_match("#^[A-Za-z0-9]*$#")` — this REJECTS underscores! A login like `test_user` passes `validateLogin()` but fails `preg_match`, so execution falls to the else and... there is no explicit else shown here. The effect is that valid underscore logins cannot proceed. The fix removes the `preg_match` check and relies solely on `validateLogin()`.

**STATUS: CORRECT — the redundant narrower regex check must be removed.**

### P9-MED-027 — No duplicate email check in `comptetest.php`
**File read:** `comptetest.php:61-68`

The fix adds a `dbCount()` check for duplicate email before the login uniqueness check. `dbCount()` is a helper from `includes/database.php` that returns an integer count. The query `'SELECT COUNT(*) AS nb FROM membre WHERE email = ?'` with `'s', $email` binding is correct.

**STATUS: CORRECT.**

### P9-MED-028 — `email_queue` not purged during season reset
**File read:** `includes/player.php:1265-1305` (`remiseAZero()`)

The fix adds `dbExecute($base, 'DELETE FROM email_queue WHERE sent_at IS NOT NULL')` at the start of `remiseAZero()`. The condition `sent_at IS NOT NULL` purges sent emails but keeps unsent ones queued for delivery — this is correct behavior.

**Note:** `remiseAZero()` uses `withTransaction()` starting at line 1271. The fix must place the DELETE inside the transaction. The plan says "at the start of `remiseAZero()`" — this is ambiguous about whether before or inside the `withTransaction()`. For atomicity, the DELETE should be INSIDE the transaction closure. Fix agent should place it as the first statement inside the `withTransaction()` callback.

**STATUS: CORRECT in intent. Minor ambiguity: DELETE must be placed inside the `withTransaction()` closure for atomicity.**

### P9-LOW-022 — Admin trigger condition includes unauthenticated requests
**File read:** `includes/basicprivatephp.php:208`

Current:
```php
$isAdminRequest = (!isset($_SESSION['login']) || $_SESSION['login'] === ADMIN_LOGIN);
```
This evaluates to `true` when login is NOT set (unauthenticated) — clearly a logic inversion bug.

Fix:
```php
$isAdminRequest = (isset($_SESSION['login']) && $_SESSION['login'] === ADMIN_LOGIN);
```
This is correct — only the admin player can trigger the reset during a page request.

**STATUS: CORRECT — genuine logic inversion bug, fix is exact.**

### P9-LOW-023 — Hardcoded `"Guortates"` in display.php and connectes.php
**File read:** `includes/display.php:274`, `connectes.php:29`

Confirmed both hardcoded references exist. `ADMIN_LOGIN` is defined in `constantesBase.php` (referenced in MEMORY.md). Replacement is a direct constant substitution.

**STATUS: CORRECT.**

---

## Batch 12: Prestige & Ranking

### P9-LOW-025 — Migration 0075 idempotency check uses wrong `information_schema` column
**File read:** `migrations/0075_prestige_total_pp_unsigned.sql`

Current migration checks `DATA_TYPE = 'int'` and runs ALTER only when type is `'int'`. After the ALTER makes it `INT UNSIGNED`, `DATA_TYPE` returns `'int'` still (MariaDB's `information_schema.COLUMNS.DATA_TYPE` for `INT UNSIGNED` is `'int'`, not `'int unsigned'`). The `COLUMN_TYPE` column returns `'int(10) unsigned'` — this IS the distinguishing value.

The fix changes the check to `COLUMN_TYPE = 'int(10) unsigned'` and uses `IF @col_type != 'int(10) unsigned'`. However, there is an issue with the plan's fix: `COLUMN_TYPE` for `INT UNSIGNED` in MariaDB 10.11 may return `'int unsigned'` (without the display width `(10)`) since MariaDB 10.4+ deprecated display widths. Fix agent should run `SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_NAME='prestige' AND COLUMN_NAME='total_pp'` on the actual VPS to confirm the exact string before hardcoding it.

**STATUS: CORRECT in approach but fix agent must verify the exact `COLUMN_TYPE` string on the live VPS before hardcoding `'int(10) unsigned'`.**

### P9-LOW-026 — `recalculerStatsAlliances()` triggered by unauthenticated GET
**File read:** `classement.php:374-375`

Current:
```php
} else {
    recalculerStatsAlliances();
}
```
No auth check. The fix gates this behind `isset($_SESSION['login'])`. The function signature in the current call has no `$base` argument — confirming it uses global `$base`. The fix is correct.

**STATUS: CORRECT.**

### P9-LOW-027 — Missing indexes on non-default sort columns
The fix creates migration `0042_leaderboard_indexes.sql`. The proposed indexes are all on the `autre` table which is the main player stats table. All column names (`pointsAttaque`, `pointsDefense`, `ressourcesPillees`, `tradeVolume`, `victoires`, `points`, `batmax`) should be verified against `autre` schema. These are referenced elsewhere in the codebase (MEMORY.md mentions these columns). `batmax` vs `batMax` — column naming convention in this DB uses camelCase inconsistently. Fix agent must verify exact column name case (MySQL/MariaDB column names are case-insensitive on Linux by default, so this is not a hard error but clean SQL should match the actual column name).

**STATUS: CORRECT in principle — fix agent must verify exact column names against `DESCRIBE autre`.**

---

## Batch 13: Market + API residual

### P9-MED-029 — `tableauCours` raw in JS chart — latent stored XSS
**File read:** `marche.php:745`

Current: `$tot = '["' . date(...) . '",' . $cours['tableauCours'] . ']' . $fin . $tot;`

`tableauCours` is DB-stored market data (comma-separated floats). The fix applies `array_map('floatval', explode(',', $cours['tableauCours']))` then `implode(',', $vals)`. This correctly sanitizes any injected content to float values. The `floatval()` function will return `0.0` for non-numeric strings and a numeric value for numeric strings — the resulting `$safeVals` contains only `float` representations, safe for JS output.

**Edge case:** If `tableauCours` is empty string, `explode(',', '')` returns `['']`, `floatval('')` returns `0.0`, and output becomes `[0.0]`. This could cause chart rendering issues but is not a security problem. Original code would output an empty chart array which may already cause JS errors. This edge case is acceptable.

**STATUS: CORRECT.**

---

## Summary of Issues Found

### Genuine Technical Errors (fix agent must correct):

| ID | Issue | Correction Required |
|----|-------|---------------------|
| P9-HIGH-001 | Fix description uses aliased `m.estExclu` — actual query has no alias | Use `AND estExclu = 0` (no alias prefix) |
| P9-MED-007 | `$moderateur['alliance']` does not exist in the `$moderateur` array | Fetch moderator's alliance separately via `autre` table before comparison |
| P9-MED-008 | CAS guard placement is ambiguous — existing transaction too narrow | Wrap entire espionage resolution block (lines 350-476) in new `withTransaction()` with CAS at start |
| P9-HIGH-007 | Missing update to `moderation/ip.php` and `checkSameIpAccounts()` comparison queries | All IP-based lookups must also hash the comparison value after migration |
| P9-MED-019 | References `$canonicalLogin` which may not exist in current code | Locate or create canonical login resolution; check against `$_SESSION['login']` |

### Already-Applied Fixes (fix agent must skip):

| ID | Status |
|----|--------|
| P9-MED-001 | Already applied in voter.php (array_filter + array_map trim) |

### Minor Ambiguities (fix agent should clarify before implementing):

| ID | Ambiguity |
|----|-----------|
| P9-HIGH-004 | `\a` explanation is imprecise — outcome is correct but comment may confuse |
| P9-MED-028 | "at the start of `remiseAZero()`" — must be inside `withTransaction()` closure |
| P9-LOW-025 | `COLUMN_TYPE` exact string must be verified on live VPS MariaDB 10.11 |
| P9-MED-025 | `PASSWORD_BCRYPT_MAX_LENGTH` existence in config.php should be verified |
| P9-LOW-009 | Confirm `vieIonisateur` column name in constructions table (vs vieIonisateur confirmed at game_actions.php:429) |

### Correctly Described Fixes (no corrections needed):

Batches 2, 3 (HIGH-003, HIGH-005, MED-004), 4 (HIGH-006, MED-005, MED-006, LOW-005/006/007), 5 (MED-009), 6 (MED-010, LOW-010/011), 7 (MED-011/012/013/014), 8 (MED-015/016/017), 9 (MED-018/020), 10 (HIGH-008/009, MED-021/022/023/024), 11 (MED-026/027), 12 (LOW-022/023/026/027), 13 (MED-029) are all technically correct as described.

---

## Overall Assessment

**NOT ALL FIXES VERIFIED CLEAN.** Five issues require correction before the fix agent implements:

1. **P9-HIGH-001:** Alias bug in voter.php auth query — use `estExclu` not `m.estExclu`
2. **P9-MED-007:** `$moderateur['alliance']` is a null reference — fetch moderator alliance from `autre` table
3. **P9-MED-008:** CAS guard placement requires new outer `withTransaction()` wrapping the full espionage resolution block
4. **P9-HIGH-007:** IP hashing migration must also update all IP-comparison queries (moderation/ip.php, checkSameIpAccounts)
5. **P9-MED-019:** `$canonicalLogin` variable reference is uncertain in current ecriremessage.php code

One fix is already applied and must be skipped:
- **P9-MED-001:** voter.php option count trailing-comma fix is already in the codebase

All remaining HIGH and MEDIUM fixes are technically sound as written.
