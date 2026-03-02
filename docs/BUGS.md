# Bug Tracker

## Status: ALL 11 ORIGINAL BUGS FIXED

All bugs below were identified and fixed in commit `0f59bf0` (2026-03-02).

---

## BUG-1 (CRITICAL): Combat duplicateur uses wrong login -- FIXED

**File:** `includes/combat.php`
**Fix:** Changed defender's alliance lookup to use defender's login instead of attacker's.

---

## BUG-2: Inconsistent depot/storage formulas -- FIXED

**Files:** `includes/game_resources.php` (was `fonctions.php`), `includes/update.php`
**Fix:** Merged update.php and update1.php into single module. Storage formula unified to `500 * level`.

---

## BUG-3: Inconsistent molecule decay -- FIXED

**Files:** `includes/formulas.php` (was `fonctions.php`), `includes/update.php`
**Fix:** Merged update files; decay formula now uses the exponential formula consistently.

---

## BUG-4: supprimerJoueur() overwrites $modif8 variable -- FIXED

**File:** `includes/player.php` (was `fonctions.php`)
**Fix:** Fixed variable naming to avoid overwrite. Reports deletion query now executes correctly.

---

## BUG-5: Missing break in combat.php switch statement -- FIXED

**File:** `includes/combat.php`
**Fix:** Added missing `break` statement in switch case 3.

---

## BUG-6: connexion.php uses deprecated mysql_connect() -- FIXED

**File:** `includes/connexion.php`
**Fix:** Replaced `mysql_connect()` with `mysqli_connect()`. Added `mysqli_set_charset($base, 'utf8')`.

---

## BUG-7: Mixed mysql_error() and mysqli_error() calls -- FIXED

**Files:** All PHP files
**Fix:** Removed all `or die()` error patterns. Replaced with proper error logging via `includes/logger.php`.

---

## BUG-8: armee.php molecule deletion uses = instead of .= -- FIXED

**File:** `armee.php`
**Fix:** Changed `=` to `.=` for string concatenation in molecule deletion loop.

---

## BUG-9: armee.php absurdly large max molecule number -- FIXED

**File:** `armee.php`
**Fix:** Replaced absurd constant with `PHP_INT_MAX`.

---

## BUG-10: classement.php triggers UPDATE queries on view -- FIXED

**File:** `classement.php`
**Fix:** Moved UPDATE side effects out of view logic.

---

## BUG-11: War ending only works for declaring alliance -- FIXED

**File:** `allianceadmin.php`
**Fix:** Expanded WHERE clause to match either alliance1 or alliance2.

---

## Security Bugs Found During Audits

These were found and fixed during 3 rounds of security auditing:

| ID | Severity | Description | Status |
|----|----------|-------------|--------|
| SQLI-* | CRITICAL | SQL injection across 50+ files | FIXED - All queries use prepared statements |
| XSS-* | HIGH | Cross-site scripting in forums, messages, profiles | FIXED - htmlspecialchars applied |
| CSRF-* | HIGH | Missing CSRF on forms and GET-based mutations | FIXED - csrfField/csrfCheck on all forms |
| AUTH-01 | CRITICAL | voter.php: hardcoded DB creds, no auth, SQL injection | FIXED - Complete rewrite |
| AUTH-02 | CRITICAL | Admin panel: GET-based account deletion | FIXED - Changed to POST+CSRF |
| PASS-01 | HIGH | MD5 password hashing | FIXED - bcrypt with auto-migration |
| SESS-01 | HIGH | Session fixation vulnerability | FIXED - session_regenerate_id |
| UPLOAD-01 | HIGH | File upload without MIME validation | FIXED - Extension whitelist + finfo check |
| LOCAL-01 | CRITICAL | localStorage password storage | FIXED - Auto-login removed |
