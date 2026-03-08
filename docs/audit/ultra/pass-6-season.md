# Pass 6 Season/Admin Audit

## Scope

Files audited:
- `maintenance.php`
- `includes/player.php` (season functions: `archiveSeasonData`, `performSeasonEnd`, `remiseAZero`, `supprimerJoueur`)
- `admin/` (all 10 files)
- `moderation/` (all 3 files)
- `season_recap.php`
- `includes/multiaccount.php`

---

## Findings

### ADM-P6-001 [HIGH] supprimerJoueur does not delete login_history or account_flags rows

**File:** `includes/player.php:907-938`
**Description:** When a player account is deleted (via admin panel or `supprimercompte.php`), `supprimerJoueur()` omits two multi-account-system tables: `login_history` and `account_flags`. After deletion, stale rows for the deleted login remain in both tables. Consequences:
1. `checkSameIpAccounts` and `checkSameFingerprintAccounts` continue to find the ghost login in `login_history` and create new `account_flags` rows referencing a non-existent player, inflating the alert queue with false positives.
2. The orphaned `account_flags` rows (which still name the deleted login) cause the "Anti multi-comptes" admin view to display investigation links for accounts that no longer exist, leading to confusion.
3. `admin_alerts` rows also accumulate permanently; there is no purge.

**Code:**
```php
// supprimerJoueur() at line 913 — note what is NOT here:
withTransaction($base, function() use ($base, $joueur) {
    dbExecute($base, 'DELETE FROM connectes WHERE ip IN (SELECT ip FROM membre WHERE login=?)', 's', $joueur);
    // ... 17 other tables ...
    dbExecute($base, 'DELETE FROM season_recap WHERE login=?', 's', $joueur);
    // MISSING:
    // dbExecute($base, 'DELETE FROM login_history WHERE login=?', 's', $joueur);
    // dbExecute($base, 'DELETE FROM account_flags WHERE login=? OR related_login=?', 'ss', $joueur, $joueur);
    // dbExecute($base, 'DELETE FROM admin_alerts WHERE message LIKE ?', 's', '%' . $joueur . '%');
```

**Fix:** Add to the `supprimerJoueur()` transaction:
```php
dbExecute($base, 'DELETE FROM login_history WHERE login=?', 's', $joueur);
dbExecute($base, 'DELETE FROM account_flags WHERE login=? OR related_login=?', 'ss', $joueur, $joueur);
```
For `admin_alerts`, consider marking rather than deleting (alerts are audit records), but at minimum ensure no dashboard view crashes on a null-login reference.

---

### ADM-P6-002 [HIGH] Moderation session has no IP binding — session theft undetectable

**File:** `moderation/mdp.php:1-8`, `moderation/index.php:1-43`
**Description:** The admin panel (`admin/redirectionmotdepasse.php`) stores `$_SESSION['admin_ip']` at login time and validates it on every subsequent request (line 18). The moderation panel (`moderation/mdp.php`) performs **no equivalent IP binding**. An attacker who steals or fixates a moderation session ID gains full access to: resource grants to any player (`energieEnvoyee` form), bomb point manipulation (`joueurBombe`), subject deletion and locking, and the multi-account IP viewer. Unlike the admin session, there is no session regeneration logged event, no IP check, and no `mod_ip` stored at login.

**Code:**
```php
// moderation/mdp.php — entire file:
session_name('TVLW_MOD');
require_once(__DIR__ . '/../includes/session_init.php');
include_once(__DIR__ . '/../includes/constantesBase.php');
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
    header('Location: index.php');
    exit();
}
// NO IP binding check — contrast with admin/redirectionmotdepasse.php line 18
```

**Fix:** Mirror the admin IP binding pattern in `moderation/index.php` (at login, store `$_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR'] ?? ''`) and in `moderation/mdp.php` (validate `hash_equals($_SESSION['mod_ip'], $_SERVER['REMOTE_ADDR'] ?? '')`). Also add an idle-timeout check equivalent to the admin panel's `SESSION_IDLE_TIMEOUT` guard.

---

### ADM-P6-003 [MEDIUM] admin/index.php mass-deletes by raw POST IP without format validation

**File:** `admin/index.php:43-48`
**Description:** The `supprimercompte` action on the admin dashboard deletes all players matching a submitted IP address. The IP value comes directly from `$_POST['supprimercompte']` and is passed to a DB query with no IP-format validation:

```php
if (isset($_POST['supprimercompte'])) {
    $ip = $_POST['supprimercompte'];  // raw, unvalidated
    $rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
    foreach ($rows as $login) {
        supprimerJoueur($login['login']);
    }
}
```

While the query is parameterised (no SQL injection), a crafted form submission (bypassing the HTML form, e.g. via curl) can send an arbitrary string. If the `membre.ip` column ever contains values like `'unknown'` or `''` due to registration edge cases, submitting that string would mass-delete all accounts sharing that sentinel value. Additionally, there is no confirmation step or limit on how many accounts can be deleted in one operation.

**Fix:** Validate the IP format before accepting it:
```php
$ip = $_POST['supprimercompte'] ?? '';
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    logWarn('ADMIN', 'Suppression with invalid IP rejected', ['raw' => substr($ip, 0, 64)]);
    // show error and stop
} else {
    // proceed
}
```
Also cap the deletion (e.g., refuse if `>5` accounts match) and log the total deleted count.

---

### ADM-P6-004 [MEDIUM] checkTimingCorrelation uses a Cartesian-product query with no index hint — DoS risk at scale

**File:** `includes/multiaccount.php:221-224`
**Description:** The timing-correlation check joins `login_history` with itself on `ABS(a.timestamp - b.timestamp) < 300`, which is a non-sargable predicate. For every login event, it executes this self-join for every related account pair. As `login_history` grows (no purge is implemented), this query becomes a full table scan × full table scan per pair. With 100 active players each logging in 10 times/day over 30 days = 30,000 rows, the join produces up to 900,000,000 row comparisons. Because `logLoginEvent()` is called on every login, this executes on the hot authentication path.

**Code:**
```php
$overlap = dbFetchOne($base,
    'SELECT COUNT(*) AS cnt FROM login_history a INNER JOIN login_history b ON ABS(a.timestamp - b.timestamp) < 300 WHERE a.login = ? AND b.login = ? AND a.timestamp > ? AND b.timestamp > ?',
    'ssii', $login, $other, $cutoff, $cutoff
);
```

**Fix:** Rewrite to avoid the Cartesian self-join. An EXISTS-based approach that uses the indexed `(login, timestamp)` columns is far more efficient:
```sql
SELECT COUNT(*) AS cnt
FROM login_history a
WHERE a.login = ?
  AND a.timestamp > ?
  AND EXISTS (
      SELECT 1 FROM login_history b
      WHERE b.login = ?
        AND b.timestamp BETWEEN a.timestamp - 300 AND a.timestamp + 300
  )
```
Also add a periodic purge of old `login_history` rows (e.g., `WHERE timestamp < NOW() - INTERVAL 90 DAY`) to bound table size.

---

### ADM-P6-005 [MEDIUM] checkSameFingerprintAccounts stores raw fingerprint (SHA-256 of UA+lang) in evidence JSON — PII leakage

**File:** `includes/multiaccount.php:91-96`
**Description:** The `same_fingerprint` evidence blob stores the raw `$fingerprint` value (SHA-256 of `User-Agent + Accept-Language`). Unlike the IP in `same_ip` evidence (which is hashed with a salt and truncated to 12 chars at lines 54 and 69), the fingerprint is stored in full (64 hex chars). While SHA-256 of UA+lang is not directly PII, it is a stable cross-session identifier that enables long-term tracking of a device across account deletions and season resets. The `evidence` column is visible in the admin dashboard at `multiaccount.php?view=flags` (the pre tag at line 275).

**Code:**
```php
$evidence = json_encode([
    'shared_fingerprint' => $fingerprint,  // full 64-char SHA-256, never truncated
    'detection_time' => $timestamp,
    'login_a' => $login,
    'login_b' => $other['login']
]);
```

Compare with the (correct) IP handling:
```php
$ipDisplay = substr(hash('sha256', $ip . SECRET_SALT), 0, 12);  // truncated + salted
```

**Fix:** Apply the same truncation to fingerprint evidence:
```php
'shared_fingerprint' => substr($fingerprint, 0, 12),
```
This preserves enough entropy to correlate flags across sessions while preventing the full token from being exposed in the admin UI.

---

### ADM-P6-006 [MEDIUM] season_recap.php leaks `molecules_perdues` as float (display only), but bind type comment contradicts code

**File:** `includes/player.php:980`, `season_recap.php:28`
**Description:** The bind string comment at player.php:980 says `'isiiiidiiiisi'` — the `d` at position 8 binds `tradeVolume` as double (correct). However, the comment on the same line says "moleculesPerdues is BIGINT, use 'i' not 'd'", referring to position 11. The actual bind type at position 11 is `i` (correct). This is not a runtime bug but creates a maintenance hazard: a developer reading the bind string to add a new column will miscount because the comment describes position 11 but the string is indexed from position 1. More concretely, `season_recap.php:28` casts `molecules_perdues` to `float` with `(float)$recap['molecules_perdues']`, even though the column is `BIGINT`. For large values (>2^53), float truncates silently. The display at line 50 uses `number_format($molLost, 0, ...)` which is correct for small values but the intermediate float cast is unnecessary and potentially lossy.

**Code:**
```php
// season_recap.php:28
$molLost = (float)$recap['molecules_perdues'];  // should be (int)
```

**Fix:** Change `season_recap.php:28` to `$molLost = (int)$recap['molecules_perdues'];` to match the DB column type and the display intent.

---

### ADM-P6-007 [LOW] admin/multiaccount.php POST action handler does not validate `$detailLogin` before DB query

**File:** `admin/multiaccount.php:69-70`, `237-246`
**Description:** The `$detailLogin` variable is read from `$_GET['login']` without any sanitisation or length limit:

```php
$detailLogin = isset($_GET['login']) ? $_GET['login'] : '';
```

It is then used directly in two DB queries at lines 239-245:
```php
$loginHistory = dbFetchAll($base,
    'SELECT * FROM login_history WHERE login = ? ORDER BY timestamp DESC LIMIT 50',
    's', $detailLogin
);
$accountFlags = dbFetchAll($base,
    'SELECT * FROM account_flags WHERE login = ? OR related_login = ? ORDER BY created_at DESC',
    'ss', $detailLogin, $detailLogin
);
```

The queries are parameterised (no SQL injection), but `$detailLogin` is also echoed unescaped in the heading at line 249:
```php
$loginSafe = htmlspecialchars($detailLogin, ENT_QUOTES, 'UTF-8');
// ...
echo '<h3>Détail: ' . $loginSafe . '</h3>';
```
The `htmlspecialchars` call handles XSS correctly. The remaining issue is an arbitrarily long GET parameter (no `strlen` cap) that results in a DB query with an overlong string, which wastes resources but does not cause correctness issues. However, a missing existence check means the section renders a blank table even for completely fabricated logins, which could be used to probe for valid usernames via response-time differences.

**Fix:** Add a length check and optionally verify the login exists in `membre` before querying `login_history`:
```php
if (!empty($detailLogin) && strlen($detailLogin) <= 20 && preg_match('/^[a-zA-Z0-9_-]+$/', $detailLogin)) {
    // existing queries
}
```

---

### ADM-P6-008 [LOW] maintenance.php double-renders nl2br and strip_tags output without escaping HTML entities from DB

**File:** `maintenance.php:37-49`
**Description:** The news content is fetched from the DB, passed through `strip_tags()` (with an allowlist), then regex-filtered for `on*` attributes and javascript: hrefs, then `nl2br()`'d. The result is echoed directly:

```php
$contenu = strip_tags($donnees['contenu'], $allowedTags);
$contenu = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $contenu);
$contenu = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $contenu);
$contenu = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $contenu);
$contenu = nl2br($contenu);
echo '...' . $contenu . '...';
```

The title is correctly escaped via `htmlspecialchars` (line 44), but the `$contenu` HTML is echo'd raw. Because `$contenu` is HTML (having passed through strip_tags with an allowlist), this is intentional rendering — but the allowlist includes `<img>` and `<span>` which can be abused with `data:` URIs or `class=` attributes. The `<img src="data:text/html,<script>...">` vector is blocked by the `<img>` tag remaining in the allowlist while `src=` pointing to external URLs is not stripped. An admin who writes news content (the only path to the DB) controls this entirely, so the attack surface is self-inflicted. However, the `<a>` tag's `href` is only checked for `javascript:` (line 42) and not for `data:` URIs or `vbscript:` schemes.

**Code:**
```php
$contenu = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $contenu);
// MISSING: data: and vbscript: href schemes
```

**Fix:** Expand the href scheme filter:
```php
$contenu = preg_replace('/href\s*=\s*["\']?\s*(javascript|data|vbscript)\s*:/i', 'href="', $contenu);
```

---

### ADM-P6-009 [INFO] `performSeasonEnd` called from `basicprivatephp.php` only when admin is the triggering session — but the admin check relies on `$_SESSION['login']` which can be absent in CLI/cron contexts

**File:** `includes/basicprivatephp.php:194`
**Description:** The season-reset admin gate reads:
```php
$isAdminRequest = (!isset($_SESSION['login']) || $_SESSION['login'] === ADMIN_LOGIN);
```
The `!isset($_SESSION['login'])` branch (no session = CLI/cron) is treated as admin, which is correct for automation. However, if a future feature introduces unauthenticated web access to a page that includes `basicprivatephp.php` (e.g., a public API endpoint or a webhook), this condition becomes true for any unauthenticated request, allowing an unauthenticated visitor to trigger a season reset during maintenance. Currently `basicprivatephp.php` enforces session auth earlier (before the maintenance block), so there is no immediate exploit path. This is an INFO-level design fragility rather than an active vulnerability.

**Fix:** Document the assumption explicitly. Consider using a dedicated flag (`defined('CLI_CONTEXT')` or a signed cron token) instead of relying on the absence of a session variable to indicate CLI access.

---

## Summary

| ID | Severity | Title |
|----|----------|-------|
| ADM-P6-001 | HIGH | supprimerJoueur omits login_history and account_flags cleanup |
| ADM-P6-002 | HIGH | Moderation session has no IP binding |
| ADM-P6-003 | MEDIUM | Mass-delete by IP uses unvalidated POST IP |
| ADM-P6-004 | MEDIUM | checkTimingCorrelation Cartesian self-join DoS at scale |
| ADM-P6-005 | MEDIUM | Full fingerprint stored in evidence JSON (inconsistent with IP handling) |
| ADM-P6-006 | MEDIUM | molecules_perdues cast to float in season_recap.php (BIGINT truncation risk) |
| ADM-P6-007 | LOW | detailLogin GET param not validated before DB query in multiaccount.php |
| ADM-P6-008 | LOW | maintenance.php href filter misses data: and vbscript: schemes |
| ADM-P6-009 | INFO | Admin gate for season reset relies on absent session — CLI assumption fragile |

**Total: 9 findings — 2 HIGH, 4 MEDIUM, 2 LOW, 1 INFO**

No CRITICAL findings. The season-reset pipeline itself (archiveSeasonData → performSeasonEnd → remiseAZero) is sound: advisory lock, correct bind types, transaction wrapping, and maintenance flag are all properly implemented. The main gaps are in the multi-account system's data lifecycle (orphan rows, privacy) and the moderation panel's missing session security parity with the admin panel.
