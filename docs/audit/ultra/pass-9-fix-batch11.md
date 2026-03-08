# Ultra Audit Pass 9 — Fix Batch 11: Registration & Season

Date: 2026-03-08
Agent: fix-agent (claude-sonnet-4-6)

All files were read before editing. PHP syntax verified on every modified file (`php -l`). Zero syntax errors.

---

## P9-MED-025: comptetest.php — Password max-length not enforced

**Status: APPLIED**

File read: `comptetest.php` (130 lines, full read).

Added an `elseif` branch immediately after the existing `PASSWORD_MIN_LENGTH` check (line 56):

```php
} elseif (mb_strlen($_POST['pass']) > PASSWORD_BCRYPT_MAX_LENGTH) {
    $erreur = 'Le mot de passe est trop long (max ' . PASSWORD_BCRYPT_MAX_LENGTH . ' caractères).';
}
```

This prevents bcrypt silently truncating passwords longer than 72 bytes. `PASSWORD_BCRYPT_MAX_LENGTH` is defined in `includes/config.php` (value: 72).

---

## P9-MED-026: comptetest.php — Underscore in login breaks validation

**Status: APPLIED**

Removed the `elseif (preg_match("#^[A-Za-z0-9]*$#", $_POST['login']))` branch that was wrapping the email-validation and login-uniqueness block. This regex rejected underscores, contradicting `validateLogin()` which allows `[a-zA-Z0-9_]{3,20}`.

The inner block is now reached directly after `validateLogin()` passes (converted `elseif` to `else`). The dead error message `'Vous ne pouvez pas utiliser de caractères spéciaux dans votre login'` was also removed.

---

## P9-MED-027: comptetest.php — No duplicate email check

**Status: APPLIED**

The email field value inside the validation block is `trim($_POST['email'])` (assigned to `$email`). Added duplicate-email check immediately after `validateEmail()` passes, before login normalization and the login-uniqueness query:

```php
$email = trim($_POST['email']);
$nbMail = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ?', 's', $email);
if ($nbMail > 0) {
    $erreur = 'L\'email est déjà utilisé.';
} else {
    // ... login normalization and uniqueness check ...
} // end email-not-duplicate check
```

The redundant `$email = trim($_POST['email'])` that previously appeared inside the login-uniqueness block was removed (the variable is now set earlier in scope and captured by the `withTransaction` closure via `use`).

---

## P9-LOW-021: player.php — Remove antihtml() from inscrire() (double-encoding)

**Status: APPLIED**

File read: `includes/player.php` (offset 36, limit 80 — covers `inscrire()` function opening).

Changed:
```php
$safePseudo = antihtml(trim($pseudo));
$safeMail   = antihtml(trim($mail));
```
to:
```php
$safePseudo = trim($pseudo);
$safeMail   = trim($mail);
```

`antihtml()` applies `htmlspecialchars()` which encodes `&`, `<`, `>`, `"` — storing HTML-encoded text in the DB causes double-encoding at render time (display code calls `htmlspecialchars()` again). Values are already validated upstream (login via `validateLogin()`, email via `validateEmail()`).

---

## P9-MED-028: player.php — email_queue not purged during season reset

**Status: APPLIED**

File read: `includes/player.php` (offset 1276, limit 60 — covers `remiseAZero()` function).

Added at the start of `remiseAZero()`, before the `withTransaction` call:

```php
dbExecute($base, 'DELETE FROM email_queue WHERE sent_at IS NOT NULL');
// login_history and account_flags are intentionally preserved cross-season for ban enforcement
```

Only rows with `sent_at IS NOT NULL` (already delivered) are deleted. Unsent rows (`sent_at IS NULL`) are retained in case the queue drain fires between the reset and the next season's email batch.

---

## P9-LOW-022: basicprivatephp.php — Admin trigger condition logic inversion

**Status: APPLIED**

File read: `includes/basicprivatephp.php` (full file, 352 lines).

The original condition:
```php
$isAdminRequest = (!isset($_SESSION['login']) || $_SESSION['login'] === ADMIN_LOGIN);
```
evaluated to `true` for any unauthenticated (logged-out) request due to the `!isset` branch. This allowed a session-less CLI cron to trigger the season reset, but also allowed any player whose session was absent (e.g., expired, race condition) to pass the admin gate.

Corrected to:
```php
$isAdminRequest = (isset($_SESSION['login']) && $_SESSION['login'] === ADMIN_LOGIN);
```

Unauthenticated requests (including cron) now correctly fail the gate and see the maintenance message. The cron/CLI context should call `remiseAZero()` / `performSeasonEnd()` directly, not via HTTP.

---

## P9-LOW-023: Hardcoded "Guortates" string

**Status: APPLIED — both files**

### includes/display.php

File read: target context at offset 265, limit 20.

Changed:
```php
if ($donnees2['login'] == "Guortates") {
```
to:
```php
if ($donnees2['login'] == ADMIN_LOGIN) {
```

### connectes.php

File read: target context at offset 20, limit 25.

Changed:
```php
if ($donnees['login'] != "Guortates") {
```
to:
```php
if ($donnees['login'] != ADMIN_LOGIN) {
```

Post-fix grep confirmed zero remaining `"Guortates"` literals in either file.

---

## Summary

| ID           | File(s)                         | Status  | Notes                                        |
|--------------|---------------------------------|---------|----------------------------------------------|
| P9-MED-025   | comptetest.php                  | APPLIED | PASSWORD_BCRYPT_MAX_LENGTH elseif added      |
| P9-MED-026   | comptetest.php                  | APPLIED | Redundant preg_match + dead else removed     |
| P9-MED-027   | comptetest.php                  | APPLIED | dbCount email uniqueness check added         |
| P9-LOW-021   | includes/player.php             | APPLIED | antihtml() removed from inscrire()           |
| P9-MED-028   | includes/player.php             | APPLIED | email_queue purge + comment in remiseAZero() |
| P9-LOW-022   | includes/basicprivatephp.php    | APPLIED | Condition inverted: !isset||=== → isset&&=== |
| P9-LOW-023   | includes/display.php + connectes.php | APPLIED | "Guortates" → ADMIN_LOGIN (2 occurrences) |

All 7 fixes applied. PHP syntax clean on all 4 modified files.
