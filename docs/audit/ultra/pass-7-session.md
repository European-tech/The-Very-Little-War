# Pass 7 Session Security Audit

**Date:** 2026-03-08
**Scope:** includes/session_init.php, includes/basicprivatephp.php, includes/basicpublicphp.php, admin/index.php, admin/redirectionmotdepasse.php, moderation/index.php, moderation/mdp.php, comptetest.php, compte.php, maintenance.php

---

## Findings

### SESS-P7-001 [LOW] — Redundant session_start() guard in basicpublicphp.php

**File:** `includes/basicpublicphp.php:59-61`

**Code:**
```php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_regenerate_id(true);
```

**Detail:** `session_init.php` is `require_once`'d at line 8 of `basicpublicphp.php`, which already starts the session unconditionally (guarded by its own `PHP_SESSION_NONE` check). The `session_start()` call at line 60 can therefore never execute — the session is always active by that point. This is dead code rather than a bypass, but it is confusing and could mask a future ordering bug if the includes are ever reordered.

**Fix:** Remove the `if (session_status() === PHP_SESSION_NONE) { session_start(); }` block at lines 59-61; the surrounding `session_regenerate_id(true)` call at line 62 is correct and should stay.

---

### SESS-P7-002 [LOW] — Visitor sessions created without `last_activity` stamp

**File:** `comptetest.php:24-34`

**Code:**
```php
session_regenerate_id(true);
$_SESSION['login'] = ucfirst(mb_strtolower("Visiteur" . $visitorNum));
...
$_SESSION['session_token'] = $sessionToken;
dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', ...);
header('Location: tutoriel.php?deployer=1');
exit();
```

**Detail:** When a visitor session is created here, `$_SESSION['last_activity']` is never set. On the very first private-page load (tutoriel.php → basicprivatephp.php), the idle-timeout check runs `if (isset($_SESSION['last_activity']) && ...)` — this is safe because `isset` returns false and the branch is skipped. However, `$_SESSION['last_activity']` is only written at `basicprivatephp.php:53` *after* authentication succeeds. This means a visitor session created here has a window between creation and first page load where `last_activity` is absent. Practically low risk because the idle check short-circuits on absence, but it is inconsistent with authenticated sessions created in `basicpublicphp.php:68` which do set `last_activity` immediately.

**Fix:** Add `$_SESSION['last_activity'] = time();` after line 31 in `comptetest.php`, immediately after `$_SESSION['session_token'] = $sessionToken;`.

---

### SESS-P7-003 [LOW] — Admin panel IP binding not enforced on all admin pages via a shared guard

**File:** `admin/index.php:24`, `admin/redirectionmotdepasse.php:18`

**Detail:** `admin/index.php` sets `$_SESSION['admin_ip']` on login (line 24), and `admin/redirectionmotdepasse.php` validates it (line 18). All sub-pages (`supprimercompte.php`, `listenews.php`, `redigernews.php`, `listesujets.php`, `supprimerreponse.php`, `tableau.php`, `ip.php`, `multiaccount.php`) include `redirectionmotdepasse.php`, so they all inherit the IP check correctly.

However, `admin/index.php` itself — which handles the privileged POST actions (`supprimercompte`, `maintenance`, `plusmaintenance`, `miseazero`) — does **not** include `redirectionmotdepasse.php`. It defines its own auth check (`$_SESSION['motdepasseadmin'] === true`) and idle timeout, but the IP binding check (`$_SESSION['admin_ip']`) is only present in `redirectionmotdepasse.php`. Therefore, after an admin authenticates and their session is hijacked (e.g., by cookie theft), the attacker can reach the privileged POST actions in `admin/index.php` from a different IP — the IP binding protection does not apply there.

**Fix:** Add the IP check directly to `admin/index.php` after line 35 (the auth gate), mirroring `redirectionmotdepasse.php:18`:
```php
if (isset($_SESSION['admin_ip']) && !hash_equals((string)$_SESSION['admin_ip'], (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}
```

---

### SESS-P7-004 [INFO] — `cookie_secure` is conditionally off when HTTPS is not detected

**File:** `includes/session_init.php:10`

**Code:**
```php
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
```

**Detail:** This is correct defensive coding for a server not yet on HTTPS. Once DNS points to the VPS and Certbot is deployed (tracked as the remaining HTTPS TODO), `$_SERVER['HTTPS']` will be set by Apache and the flag will activate automatically. No code change needed — this is noted for post-HTTPS verification only.

**Action:** After enabling HTTPS and configuring Apache to set `HTTPS` env var, verify `cookie_secure` activates, then optionally hard-code it to `1` for clarity.

---

### SESS-P7-005 [INFO] — Session regeneration interval applies only to authenticated players, not admin/mod sessions

**File:** `includes/basicprivatephp.php:28-31`, `admin/redirectionmotdepasse.php` (no regeneration)

**Detail:** Player sessions are regenerated every `SESSION_REGEN_INTERVAL` (30 min) via `basicprivatephp.php`. Admin sessions in the `TVLW_ADMIN` cookie are regenerated only at login (`admin/index.php:22`). Subsequent page loads via `redirectionmotdepasse.php` perform no periodic regeneration. Given the admin session has IP binding, the residual risk is low. Noted for completeness.

**Fix (optional hardening):** Add a periodic regeneration block to `admin/redirectionmotdepasse.php` after line 24 (the activity timestamp update):
```php
if (!isset($_SESSION['admin_regen_at']) || (time() - $_SESSION['admin_regen_at']) > SESSION_REGEN_INTERVAL) {
    session_regenerate_id(true);
    $_SESSION['admin_regen_at'] = time();
}
```

---

## Summary

| ID | Severity | File | Description |
|----|----------|------|-------------|
| SESS-P7-001 | LOW | basicpublicphp.php:59 | Dead redundant session_start() inside already-started session |
| SESS-P7-002 | LOW | comptetest.php:34 | Visitor sessions missing initial last_activity timestamp |
| SESS-P7-003 | LOW | admin/index.php | IP binding not enforced on privileged POST actions in admin/index.php itself |
| SESS-P7-004 | INFO | session_init.php:10 | cookie_secure conditional — acceptable, verify after HTTPS activation |
| SESS-P7-005 | INFO | admin/redirectionmotdepasse.php | No periodic session regeneration for admin sessions (mitigated by IP binding) |

**No CRITICAL or HIGH findings.**

The core session architecture is sound: `session_init.php` correctly sets `httponly`, `use_only_cookies`, `use_strict_mode`, `use_trans_sid=0`, `SameSite=Lax`, and gc_maxlifetime. `basicprivatephp.php` correctly performs DB-backed session token validation with `hash_equals`, periodic session ID regeneration, idle timeout, and moderator IP binding. Login flow in `basicpublicphp.php` correctly calls `session_regenerate_id(true)` at privilege elevation and issues a fresh 256-bit token via `random_bytes(32)`. Password change in `compte.php` correctly regenerates both session ID and DB session token. Admin panel uses a separate `TVLW_ADMIN` session name with its own auth token, rate limiting, IP binding on sub-pages, and idle timeout.

Three LOW findings require minor fixes; two INFO items are post-HTTPS operational checkpoints.
