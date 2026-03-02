# Changelog

## [2.0.0] - 2026-03-02 - Complete Security & Quality Refactor

This release represents a comprehensive security hardening, bug fix, and code quality
overhaul of The Very Little War. Every PHP file has been reviewed and most have been modified.

### Security (30 commits)

**Critical Fixes:**
- Replaced deprecated `mysql_connect()` with `mysqli_connect()` + `mysqli_set_charset()`
- Converted ALL SQL queries to prepared statements using `dbQuery/dbFetchOne/dbFetchAll/dbExecute`
- Added CSRF protection (`csrfField()`/`csrfCheck()`) to every form in the application
- Migrated password hashing from MD5 to bcrypt with transparent auto-upgrade on login
- Rewrote `voter.php` from scratch (had hardcoded DB credentials, SQL injection, no auth)
- Removed localStorage password auto-login mechanism (XSS risk)
- Converted all admin GET-based destructive actions to POST with CSRF
- Added session security: httponly cookies, strict mode, session_regenerate_id()
- Hardened file uploads: MIME validation, extension whitelist, random filenames, size limits
- Fixed antihtml() charset from ISO-8859-1 to UTF-8

**New Security Infrastructure:**
- `includes/csrf.php` - CSRF token generation and verification
- `includes/validation.php` - Input validation helpers
- `includes/logger.php` - Event logging with daily rotation
- `includes/rate_limiter.php` - File-based rate limiting (login: 10/5min, registration: 3/hr)
- `.htaccess` - Security headers, directory protection, upload security

**XSS Prevention:**
- Applied `htmlspecialchars()` to all user-generated output
- Hardened BBCode parser: requires https:// for URLs, prevents attribute injection in [img]
- Updated external JS libraries from HTTP to HTTPS

### Bug Fixes (11 bugs)

- BUG-1: Combat duplicateur used wrong login for defender's alliance lookup
- BUG-2: Inconsistent depot/storage formulas
- BUG-3: Inconsistent molecule decay calculations
- BUG-4: supprimerJoueur() overwrote $modif8 causing orphaned reports
- BUG-5: Missing break in combat.php switch case 3
- BUG-6: connexion.php used deprecated mysql_connect()
- BUG-7: Mixed mysql_error()/mysqli_error() calls
- BUG-8: armee.php molecule deletion used = instead of .=
- BUG-9: Absurdly large max molecule number
- BUG-10: classement.php triggered UPDATE on view
- BUG-11: War ending only worked for declaring alliance

### Game Balance

- Extended beginner protection from 2 to 5 days
- Fixed duplicateur combat coefficient (was 10x too weak)
- Buffed iode energy production 5x
- Reduced class cost exponent (6 -> 4)
- Added market price mean-reversion, floor (0.1), ceiling (10.0)
- Reduced military building time exponents

### Code Quality

- Modularized fonctions.php (2585 lines) into 7 focused modules
- Merged duplicate update.php/update1.php
- Extracted all magic numbers into centralized config.php
- Removed dead code and unused files
- Fixed missing global declarations, stale arguments, broken includes

### Testing

- PHPUnit infrastructure with combat, resource, market, and config tests

### Database

- Migration system with 25 new indexes and column type fixes

### Tutorial

- Rewrote with 7 structured missions, progress bar, energy rewards
