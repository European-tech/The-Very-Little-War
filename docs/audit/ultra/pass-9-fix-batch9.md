# Ultra Audit Pass 9 — Fix Batch 9

Date: 2026-03-08
File modified: ecriremessage.php
PHP syntax check: PASSED (no errors detected)

---

## P9-MED-019: No self-messaging guard — FIXED

**Location:** ecriremessage.php, lines 68-78 (private message branch)

**What was done:**
After the existing canonical-login DB lookup resolves `$canonicalLogin` from the database
(case-insensitive LOWER() query, LOW-031 logic), a new guard compares `$canonicalLogin` to
`$_SESSION['login']` using `strtolower()` on both sides. If they match, `$erreur` is set and
no INSERT is performed. The DB insert and redirect now live inside the `else` branch.

**Why `$canonicalLogin` instead of `$_POST['destinataire']`:**
Using the canonical form retrieved from the DB (same row as the session login) ensures the
comparison is immune to case-variation attacks (e.g., sending a message to "MyLogin" when
logged in as "mylogin"). The strtolower() call is a belt-and-suspenders measure on top of
the already-normalized canonical value.

**Code added (lines 68-78):**
```php
// P9-MED-019: Self-messaging guard (compare canonical DB login, case-insensitive)
if (strtolower($canonicalLogin) === strtolower($_SESSION['login'])) {
    $erreur = "Vous ne pouvez pas vous envoyer un message à vous-même.";
} else {
    $now = time();
    dbExecute(...);
    $_SESSION['flash_message'] = 'Message envoyé avec succès.';
    header('Location: messages.php');
    exit();
}
```

---

## P9-MED-020: No HTML maxlength on titre/contenu inputs — FIXED

**Location:** ecriremessage.php, HTML form section (previously lines 123 and 145)

**What was done:**
- `<input type="text" name="titre">`: added `maxlength="200"` matching the server-side
  `mb_strlen($_POST['titre'], 'UTF-8') > 200` check.
- `<textarea name="contenu">`: added `maxlength="<?= MESSAGE_MAX_LENGTH ?>"` rendered as
  `maxlength="' . MESSAGE_MAX_LENGTH . '"` (PHP constant, value = 5000 per config.php line 660),
  matching the server-side `mb_strlen($_POST['contenu'], 'UTF-8') > MESSAGE_MAX_LENGTH` check.

**Effect:** Browser enforces the same length limits client-side, preventing accidental overlong
submissions. Server-side validation remains the authoritative enforcement.

---

## P9-MED-018: Raw DB content passed to creerBBcode() — latent XSS — DOCUMENTED

**Location:** ecriremessage.php, isset($_GET['id']) branch (previously line 130, now ~140)

**What was done:**
Added a multi-line security comment directly above the `creerBBcode("contenu", $message['contenu'], 1)`
call explaining:
- The second argument to `creerBBcode()` is a pre-fill value, not rendered as HTML by
  `creerBBcode()` itself.
- The value is only output via `htmlspecialchars($options, ENT_QUOTES, 'UTF-8')` inside the
  `<textarea>` — which is safe.
- Warning that this raw DB value must never be passed to any function that emits it as raw HTML.

**Why minimal fix (comment only):**
`creerBBcode()` does not render the second argument as HTML; it populates the `$options`
variable which is subsequently escaped via `htmlspecialchars()` before being placed in the
`<textarea>`. There is no active XSS vector. The comment documents the invariant that must
hold to keep this branch safe, and flags it for future developers.

---

## Summary

| Finding    | Status  | Change type          |
|------------|---------|----------------------|
| P9-MED-019 | FIXED   | Code — guard added   |
| P9-MED-020 | FIXED   | HTML — maxlength attr |
| P9-MED-018 | DOCUMENTED | Comment — no active XSS, invariant documented |

PHP lint: `No syntax errors detected in ecriremessage.php`
