# Ultra Audit Pass 9 — Registration & Account Creation
**Scope:** inscription.php, comptetest.php, includes/validation.php, includes/rate_limiter.php, includes/player.php (inscrire()), includes/config.php
**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent

---

## Executive Summary

The registration subsystem is generally well-hardened after many refactor passes. CSRF, bcrypt hashing, prepared statements, and basic rate limiting are all present. Eight findings remain, ranging from medium severity (underscore-in-login inconsistency, raw IP storage, no CAPTCHA) to low/info (password max-length not enforced in comptetest.php, redundant validation branch, missing email uniqueness check in comptetest.php, starting resources not config-driven, no email verification).

---

## Findings

---

### REG-P9-001
**Severity:** MEDIUM
**File:** comptetest.php:54
**Description — Password max-length not enforced on visitor→full account conversion**
`inscription.php` checks `mb_strlen($passInput) > PASSWORD_BCRYPT_MAX_LENGTH` (72) before hashing. `comptetest.php` only checks the minimum (`PASSWORD_MIN_LENGTH = 8`); there is no upper-bound check. `password_hash()` silently truncates bcrypt inputs beyond 72 bytes, so a visitor who sets a 200-character password will think they have that password, but bcrypt only hashes the first 72 bytes. An attacker who knows the first 72 characters of a long password can authenticate with any suffix appended.
**Recommended fix:** Add `if (mb_strlen($_POST['pass']) > PASSWORD_BCRYPT_MAX_LENGTH)` guard (identical to inscription.php line 28) before the hash call in comptetest.php.

---

### REG-P9-002
**Severity:** MEDIUM
**File:** comptetest.php:59-61 / includes/validation.php:3
**Description — Redundant and contradictory login validation in comptetest.php**
After `validateLogin()` passes on line 59, the code checks `preg_match("#^[A-Za-z0-9]*$#", $_POST['login'])` on line 61. `validateLogin()` already enforces `[a-zA-Z0-9_]{3,20}`, so this second regex passes only logins without underscores. The net result is that:
1. Logins containing `_` (e.g., `test_user`) pass `validateLogin()` and therefore produce no error on line 59-60.
2. The same login then **fails** the secondary `preg_match` on line 61 and drops into the `else` branch at line 119-120, displaying "Vous ne pouvez pas utiliser de caractères spéciaux dans votre login" — even though `_` is explicitly permitted by `validateLogin()`.

The inconsistency means a visitor with an underscore username can never convert their account: they will always see the error message. This is a denial-of-service against valid underscored usernames in comptetest.php. On `inscription.php`, there is no such second check, so `_` is correctly allowed there.
**Recommended fix:** Remove the redundant `preg_match` on comptetest.php line 61; rely solely on `validateLogin()` which already enforces the character set.

---

### REG-P9-003
**Severity:** MEDIUM
**File:** includes/player.php:72 / inscription.php:12
**Description — Raw IP address stored in `membre.ip` column and `login_history.ip`**
`inscrire()` inserts `$_SERVER['REMOTE_ADDR']` directly into `membre.ip` (player.php line 72). `logLoginEvent()` in multiaccount.php also stores the raw IP in `login_history.ip`. Storing raw IPs is a GDPR personal-data concern (IP addresses are personal data under GDPR recital 30) and an operational security risk (IP database leak exposes real player locations). The rate limiter and log events already hash IPs with `SECRET_SALT` before writing to log files, but the DB columns receive the plaintext IP.
**Recommended fix:** Store a salted SHA-256 hash of the IP in both `membre.ip` and `login_history.ip` (using the same `SECRET_SALT` pattern already used in logWarn calls throughout inscription.php).

---

### REG-P9-004
**Severity:** MEDIUM
**File:** inscription.php (absent) / comptetest.php (absent)
**Description — No CAPTCHA or bot-protection on registration forms**
Neither the direct registration form (`inscription.php`) nor the visitor→account conversion (`comptetest.php`) implement any challenge-response mechanism (CAPTCHA, proof-of-work, honeypot field). The rate limit of 3 registrations per hour per IP (RATE_LIMIT_REGISTER_MAX / RATE_LIMIT_REGISTER_WINDOW) is the only bot-deterrent. An adversary can trivially create 3 accounts per hour per IP address, or 72 accounts/day per IP, with no automation barrier. With rotating IPs or a botnet this is unbounded.
**Recommended fix:** Add a simple time-based honeypot field (hidden field that must remain empty) or a JavaScript proof-of-work challenge; a full CAPTCHA integration (hCaptcha or Turnstile) would be stronger.

---

### REG-P9-005
**Severity:** MEDIUM
**File:** comptetest.php:62-68
**Description — No duplicate email check in visitor→full account conversion**
`inscription.php` checks `dbCount(… WHERE email = ?)` before attempting the insert (line 41), and relies on the DB UNIQUE constraint as a second layer. `comptetest.php` performs only a login-uniqueness check (`SELECT count(*) FROM membre WHERE login = ?`, line 66). There is no email-uniqueness pre-check. If two visitors concurrently try to convert with the same email address, one will fail inside `withTransaction` when the UNIQUE constraint fires, but the failure propagates as an unhandled `RuntimeException` that surfaces as a generic PHP fatal/500 rather than a friendly "email already used" message. The UNIQUE constraint catches the actual duplicate, but the UX error handling is absent.
**Recommended fix:** Add `$nbMail = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ?', 's', $email)` before the login check in comptetest.php, mirroring the pattern in inscription.php lines 41-43.

---

### REG-P9-006
**Severity:** LOW
**File:** includes/player.php:48-49
**Description — `antihtml()` applied to username and email before storage (double-encoding risk)**
`inscrire()` calls `antihtml(trim($pseudo))` and `antihtml(trim($mail))` (player.php lines 48-49). `antihtml()` is `htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8')` (display.php line 316). This converts `<`, `>`, `"`, `'`, `&` to HTML entities **before** writing to the database. The value stored in `membre.login` and `membre.email` is therefore an HTML-entity-encoded string. When displayed later via `htmlspecialchars()` or `antiXSS()`, the stored entities are double-encoded (e.g., `&amp;` → `&amp;amp;`). When used in non-HTML contexts (email headers, JSON API responses, CSV exports, log comparisons) the stored entities are incorrect raw data. The caller (`inscription.php`) already passes `$loginNormalized` through `htmlspecialchars()` for display purposes; encoding at storage time is redundant and harmful.
**Recommended fix:** Remove the `antihtml()` wrapping in `inscrire()`; store the raw (but already validated/trimmed) value and apply `htmlspecialchars()` only at display time.

---

### REG-P9-007
**Severity:** LOW
**File:** inscription.php:52 / base_schema.sql:245
**Description — Starting resource values are database defaults, not config constants**
New player starting resources (`energie = 64`, `carbone = 64`, `azote = 64`, etc., `revenuenergie = 12`, `revenucarbone = 9`, etc.) are hardcoded as SQL column DEFAULT values in the schema (base_schema.sql lines 245-264). They are not referenced from `config.php`. A game-balance tuner who wants to change starting resources must alter the database schema rather than editing a constant. This creates a split source of truth: config.php controls all other balance numbers, but starting-player resources live in the DB schema silently.
**Recommended fix:** Define `STARTING_ENERGY`, `STARTING_ATOMS`, `STARTING_REVENUE_ENERGY`, `STARTING_REVENUE_ATOMS` constants in config.php and pass explicit values in `inscrire()`'s INSERT into `ressources`, removing reliance on schema defaults for balance-tunable quantities.

---

### REG-P9-008
**Severity:** INFO
**File:** inscription.php / comptetest.php (absent)
**Description — No email verification step after registration**
Registration completes immediately upon form submission with no email confirmation. A user can register with any email address (including one they do not own) and immediately begin playing. This allows disposable/fake email addresses, makes password-reset flows unreliable, and enables minor harassment (sending game emails to third parties without consent). The popover on inscription.php line 99 explicitly promises that a confirmation email will be sent, but no such email is sent.
**Recommended fix:** Send a token-based verification email on registration and set an `email_verified` flag in the `membre` table; block season-end game-state emails from being sent to unverified addresses.

---

## Domain-Specific Checks Summary

| # | Check | Result |
|---|-------|--------|
| 1 | Rate limiting — 3 per hour per IP | PASS — RATE_LIMIT_REGISTER_MAX=3, RATE_LIMIT_REGISTER_WINDOW=3600 enforced in inscription.php:12. comptetest.php visitor creation uses separate 3/5min limit (line 12). |
| 2 | Username validation — banned characters enforced server-side | PASS with caveat — `validateLogin()` enforces `[a-zA-Z0-9_]{3,20}` server-side. Underscore handling inconsistency in comptetest.php (REG-P9-002). |
| 3 | Email validation — format checked, injection safe | PASS — `filter_var(FILTER_VALIDATE_EMAIL)` used via `validateEmail()`. Prepared statements prevent injection. |
| 4 | Password hashing — bcrypt cost ≥ 10 | PASS — `password_hash($mdp, PASSWORD_DEFAULT)` uses bcrypt with PHP default cost (10+). Max-length guard present in inscription.php, missing in comptetest.php (REG-P9-001). |
| 5 | Username uniqueness — race condition | PASS — UNIQUE DB constraint + INSERT error detection (`errno 1062`) provides TOCTOU-safe deduplication in inscrire(). comptetest.php uses FOR UPDATE lock on rename. |
| 6 | CSRF on registration forms | PASS — `csrfCheck()` called in both inscription.php:9 and comptetest.php:47. |
| 7 | XSS via username in success/error messages | PASS — All error message echoes use `htmlspecialchars()` or HTML-entity literals. Login preview on inscription.php:79 uses `htmlspecialchars($loginPreview, ENT_QUOTES, 'UTF-8')`. GET `?erreur=` param in basicprivatephp.php:72 runs through `antiXSS()`. |
| 8 | Default starting resources — economy abuse | PASS (structural concern) — Starting values (64 atoms, 12 energy revenue) are low enough to prevent early-game economy abuse. See REG-P9-007 for balance-maintainability concern. |
| 9 | Registration emails — header injection | N/A — No email is sent at registration time. Season-end emails use a queue table (migration 0038); `sendAdminAlertEmail()` subject is stripped of CRLF per previous audit fixes. |
| 10 | comptetest.php — rate-limited and access-controlled | PARTIAL — Visitor creation (GET branch) rate-limited at 3/5min (line 12). POST rename branch requires `$_SESSION['login']` matching `Visiteur[0-9]+` (line 41). No independent rate limit on the POST rename path itself, but the visitor-creation rate limit indirectly bounds it since a visitor account must be created before renaming. |

---

## Notes on Previously Fixed Issues

The following items were found in earlier audit passes and are confirmed fixed:
- comptetest.php email validation: previously used weak custom regex; now uses `validateEmail()` (confirmed line 62, fixed in pass-6).
- MD5 password storage: fully removed (all paths use `password_hash`).
- SQL injection via login/email: all queries use prepared statements.
- CSRF missing on comptetest.php POST: fixed (line 47).

---

FINDINGS: 0 critical, 0 high, 4 medium, 2 low, 2 info
