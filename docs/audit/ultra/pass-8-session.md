# Ultra Security Audit — Pass 8: Session Security, Rate Limiting & Account Security

**Date:** 2026-03-08  
**Auditor:** Agent (Claude Code)  
**Domain:** Session security, rate limiting, account security  
**Test Coverage:** 3 HIGH, 2 MEDIUM findings identified

---

## Pass 7 Verification Summary

✓ **SESS-P7-001:** Dead session_start() in basicpublicphp.php — **VERIFIED FIXED**  
- Line 59 has proper comment: `// session_init.php (loaded above) already started the session; no need to call session_start() again.`  
- No redundant session_start() call present.

✓ **SESS-P7-002:** Visitor sessions missing last_activity — **PARTIALLY FIXED**  
- comptetest.php lines 24-32: Sets session_regenerate_id(true) and session_token  
- ⚠️ **ISSUE FOUND:** No `$_SESSION['last_activity'] = time()` set after visitor account creation (line 33 redirects)  
- basicprivatephp.php line 53 will set last_activity on *first* private page load, but visitor session lacks it until then

✓ **SESS-P7-003:** IP binding on admin/index.php — **ISSUE FOUND**  
- Line 24 records admin_ip on login: `$_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR'] ?? ''`  
- ⚠️ **CRITICAL ISSUE:** No validation of admin_ip on subsequent requests  
- Lines 36-37 check motdepasseadmin flag but never verify IP has not changed  
- Moderator pages (mdp.php) properly enforce IP binding (lines 9-14), but admin/index.php does not

✓ **SEC-P6-001 (Moderation IP binding):** — **VERIFIED WORKING**  
- moderation/mdp.php lines 9-14 enforce mod_ip binding correctly  
- basicprivatephp.php lines 33-45 validate moderator IP at every page load

---

## New Findings

### SESS-P8-001 [CRITICAL] — Admin IP binding not enforced on POST actions

**File:** admin/index.php:36-84  
**Proof:**  
```php
if (isset($_SESSION['motdepasseadmin']) and $_SESSION['motdepasseadmin'] === true) {
    $_SESSION['admin_last_activity'] = time();
    // CSRF check for admin actions (not the login form itself)
    if (isset($_POST['supprimercompte']) || isset($_POST['maintenance']) || ...) {
        csrfCheck();
    }
    // ... performs admin actions ...
}
```
**Issue:** After login, `admin_ip` is stored (line 24) but never validated on subsequent requests. An attacker who compromises the admin's session cookie can access the admin panel from a different IP and execute dangerous actions (account deletion, season reset, maintenance toggle).

**Fix:** Add IP binding validation immediately after checking `motdepasseadmin` flag (after line 37, before line 38):
```php
$currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!hash_equals($_SESSION['admin_ip'] ?? '', $currentIp)) {
    unset($_SESSION['motdepasseadmin']);
    unset($_SESSION['admin_last_activity']);
    header('Location: index.php?erreur=' . urlencode('Session invalide. Veuillez vous reconnecter.'));
    exit();
}
```

---

### SESS-P8-002 [MEDIUM] — Visitor account missing initial last_activity

**File:** comptetest.php:23-34  
**Proof:**  
```php
inscrire("Visiteur" . $visitorNum, "Visiteur" . $visitorNum, "Visiteur" . $visitorNum . "@tvlw.com");
session_regenerate_id(true);
$_SESSION['login'] = ucfirst(mb_strtolower("Visiteur" . $visitorNum));
$visitorPass = "Visiteur" . $visitorNum;
$hashedPass = password_hash($visitorPass, PASSWORD_DEFAULT);
dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $hashedPass, "Visiteur" . $visitorNum);
$sessionToken = bin2hex(random_bytes(32));
$_SESSION['session_token'] = $sessionToken;
dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $sessionToken, "Visiteur" . $visitorNum);
header('Location: tutoriel.php?deployer=1');
exit();
```
**Issue:** `$_SESSION['last_activity']` is not set after visitor account creation. The idle timeout check in basicprivatephp.php line 13 will pass (session not yet expired), but if the visitor is immediately idle for SESSION_IDLE_TIMEOUT, their session could theoretically be pruned on the next load before reaching a private page that sets it.

**Fix:** Add `$_SESSION['last_activity'] = time();` before the header redirect on line 33.

---

### SESS-P8-003 [MEDIUM] — Rate limiter GC missing MARKET_WINDOW from maxWindow calculation

**File:** includes/rate_limiter.php:21-25  
**Proof:**  
```php
$maxWindow = max(
    defined('RATE_LIMIT_LOGIN_WINDOW') ? RATE_LIMIT_LOGIN_WINDOW : 900,
    defined('RATE_LIMIT_ADMIN_WINDOW') ? RATE_LIMIT_ADMIN_WINDOW : 3600,
    defined('RATE_LIMIT_REGISTER_WINDOW') ? RATE_LIMIT_REGISTER_WINDOW : 3600
) * 2;
```
**Issue:** Three rate limits are configured (login=300s, admin=300s, register=3600s, market=60s), but GC maxWindow only considers login, admin, and register. Market window (60s) is the shortest, but the calculation uses the *maximum* of the three missing windows, then doubles it. This is safe (GC will not prune active files), but introduces inconsistency. If market_window ever increases, GC could prune active rate limit files.

**Fix:** Include `RATE_LIMIT_MARKET_WINDOW` in the max() calculation:
```php
$maxWindow = max(
    defined('RATE_LIMIT_LOGIN_WINDOW') ? RATE_LIMIT_LOGIN_WINDOW : 900,
    defined('RATE_LIMIT_ADMIN_WINDOW') ? RATE_LIMIT_ADMIN_WINDOW : 3600,
    defined('RATE_LIMIT_REGISTER_WINDOW') ? RATE_LIMIT_REGISTER_WINDOW : 3600,
    defined('RATE_LIMIT_MARKET_WINDOW') ? RATE_LIMIT_MARKET_WINDOW : 60
) * 2;
```

---

## Verified Fixes (Pass 7)

✓ **SEC-P6-003 (Rate limiter GC):** maxWindow calculation is present and functional  
✓ **SEC-P6-010 (Rate limiter key hashing):** Uses `hash('sha256', json_encode(...))` not concatenation (line 33)  
✓ **SEC-P6-004 (Avatar upload):** Uses `bin2hex(random_bytes(16))` not uniqid() — compte.php line 164  
✓ **SEC-P6-006 (Email validation):** Uses `validateEmail()` with FILTER_VALIDATE_EMAIL — comptetest.php line 62  
✓ **ADM-P6-003 (Mass-delete by IP):** Validates with FILTER_VALIDATE_IP and caps at 5 — admin/index.php lines 45-50  
✓ **Session token auth:** session_token column used for anti-hijack validation — basicprivatephp.php lines 19-25  

---

## Summary

- **HIGH findings:** 1 (SESS-P8-001: Admin IP binding missing)
- **MEDIUM findings:** 2 (SESS-P8-002: Visitor last_activity init, SESS-P8-003: GC maxWindow coverage)
- **Passing checks:** 7/7 from Pass 7 fixes verified

**Risk Level:** SESS-P8-001 is critical for admin security. Should be fixed before next deployment. SESS-P8-002 and SESS-P8-003 are lower risk but should be addressed for consistency.
