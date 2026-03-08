# Pass 9 — Email System Security Audit

**Scope:** All `mail()` calls, email address handling, and notification emails in The Very Little War.
**Date:** 2026-03-08
**Auditor:** Narrow-domain email security agent
**Files examined:**
- `includes/player.php` (processEmailQueue)
- `includes/basicprivatephp.php` (season reset email queuing)
- `includes/multiaccount.php` (sendAdminAlertEmail, createAdminAlert)
- `includes/validation.php` (validateEmail)
- `includes/config.php` (rate limit constants, EMAIL_MAX_LENGTH)
- `inscription.php`, `compte.php`, `comptetest.php`
- `migrations/0038_create_email_queue.sql`

---

## Findings

---

### EMAIL-P9-001
**Severity:** HIGH
**File:line:** `includes/player.php:1222,1254`
**Title:** `recipient_email` used as `mail()` To argument without CRLF sanitization

**Description:**
`processEmailQueue()` reads `recipient_email` directly from the `email_queue` DB table and passes it verbatim to PHP's `mail($recipient, ...)`.  The `email_queue` table is populated from `membre.email`, which is stored at registration and account-update time.  Although `validateEmail()` (using `FILTER_VALIDATE_EMAIL`) rejects addresses containing literal CR or LF at the point of storage, there is **no explicit `str_replace(["\r","\n"], '', ...)` sanitization applied to the recipient before it reaches `mail()`**.

PHP's `mail()` function passes the To address to the MTA without envelope-level sanitization on all PHP/MTA combinations.  On some platforms (e.g. `sendmail`-compatible MTAs without a hardened PHP SMTP wrapper), a stored email such as `a@b.com\r\nBcc: victim@evil.com` that slips past FILTER_VALIDATE_EMAIL (which does block literal CRLF but not all encoded variants) could inject additional headers.

The risk is partially mitigated by `FILTER_VALIDATE_EMAIL` at insertion time, but defence-in-depth requires explicit sanitization at the point of use.

**Recommended fix:** Add `$recipient = str_replace(["\r", "\n"], '', $recipient);` immediately after reading the row in `processEmailQueue()` (line 1222), before passing it to `mail()`.

---

### EMAIL-P9-002
**Severity:** HIGH
**File:line:** `includes/player.php:1239–1242`
**Title:** MIME boundary injected unsanitized into `Content-Type` header

**Description:**
The MIME boundary is constructed as:
```php
$boundary = "-----=" . md5((string)$id . (string)time());
```
`md5()` output is hex-safe, so the boundary itself cannot contain CRLF.  However, the boundary string is embedded directly into the `Content-Type` header line:
```php
$header .= "Content-Type: multipart/alternative;" . $eol . " boundary=\"$boundary\"" . $eol;
```
While an md5 boundary is safe today, the pattern is fragile: if the boundary construction is ever changed to use user-supplied data (e.g. the email itself), a CRLF injection would follow.  More critically, **the double-boundary closing marker** (`--boundary--`) appears correctly once in the code, but earlier audits (I18N.md round 2) noted a duplicate closing boundary in a prior version; this should be confirmed.

More critically — `$eol` itself is chosen based on a **case-sensitive lowercase** regex match against `$recipient`:
```php
if (preg_match("#^[a-z0-9._-]+@(hotmail|live|msn)\.[a-z]{2,4}$#", $recipient)) {
    $eol = "\n";
} else {
    $eol = "\r\n";
}
```
This regex uses lowercase character class `[a-z0-9._-]` for the local part, meaning `User@hotmail.com` (uppercase U) will not match and will incorrectly receive `\r\n` instead of `\n`.  While this is a rendering/delivery issue rather than a security injection, it demonstrates that the `$eol` logic is unreliable.

**Recommended fix:** Use `mb_strtolower($recipient)` before the hotmail regex check to make it case-insensitive; separately, switch the boundary to `bin2hex(random_bytes(16))` (already done per audit history but confirm it's using random_bytes, not md5(rand())).

---

### EMAIL-P9-003
**Severity:** MEDIUM
**File:line:** `includes/multiaccount.php:282,294`
**Title:** Admin alert `$body` (containing user-controlled login names) passed unsanitized to `mail()` body — log injection risk

**Description:**
`sendAdminAlertEmail($subject, $body)` applies CRLF stripping to `$subject` but does **nothing** to `$body`:
```php
$subject = str_replace(["\r", "\n"], '', $subject);
@mail($adminEmail, $subject, $body, $headers);
```
The `$message` argument passed to `mail()` is the body, not additional headers — so this cannot inject *email headers*.  However, the body itself is built from user-controlled login names interpolated without escaping:
```php
"ALERTE: Attaque coordonnée sur $defender par $attacker et {$other['attaquant']} (même IP)"
```
A player who registers with a login such as `Hack\r\n\r\n<script>alert(1)</script>` will have their login string appear verbatim in the plain-text admin email body.  The email type is `text/plain` so XSS via email rendering is unlikely, but the raw CRLF injection into a `text/plain` body can be used to inject fake alert lines, create visual confusion in the admin's mailbox, or confuse naive email parsers.

**Recommended fix:** In `sendAdminAlertEmail()`, sanitize `$body` with `str_replace(["\r", "\n"], ' ', $body)` before passing to `mail()`, and in `createAdminAlert()`, escape login names in messages with `htmlspecialchars()` if switching to HTML email, or with `preg_replace('/[\r\n]/', ' ', $message)`.

---

### EMAIL-P9-004
**Severity:** MEDIUM
**File:line:** `includes/basicprivatephp.php:247–250`
**Title:** HTML email body includes un-escaped `$resetDate` (date string) without sanitization

**Description:**
The season-reset notification email body is built as:
```php
$message_html = "<html>...<b>" . htmlspecialchars($winnerName, ...) . "</b> vient de remporter la partie en cours le "
    . $resetDate . "...";
```
`$winnerName` and `$recipientLogin` are correctly escaped with `htmlspecialchars()`.  However, `$resetDate` is produced by:
```php
$resetDate = date('d/m/Y à H\hi', time());
```
`date()` output is fully server-controlled and contains no user input, so this is **not currently exploitable**.  However:
1. `à` in the format string is a raw UTF-8 character embedded in a `latin1`-declared database table (`email_queue` uses `DEFAULT CHARSET=latin1`).  Writing a UTF-8 string into a `latin1` column causes silent truncation or mojibake at the `à` character (`\xC3\xA0` in UTF-8, but latin1 `\xC3` = `Ã`, `\xA0` = non-breaking space), reproducing the historical `Ã→à` encoding bug documented in project MEMORY.md.
2. If `date()` is ever replaced with player-supplied data or a locale-aware function, the lack of escaping becomes exploitable.

**Recommended fix:** Either declare `email_queue` as `utf8mb4` (create a migration), or use only ASCII characters in the date format string (e.g., replace `à` with `a` in `'d/m/Y à H\hi'`), and add `htmlspecialchars($resetDate, ENT_QUOTES, 'UTF-8')` as a defensive measure.

---

### EMAIL-P9-005
**Severity:** MEDIUM
**File:line:** `migrations/0038_create_email_queue.sql:19`
**Title:** `email_queue` table declared `latin1` — UTF-8 email subjects/bodies will suffer encoding corruption

**Description:**
The `email_queue` table uses `DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci`.  The `subject` and `body_html` columns store email subjects encoded as `=?UTF-8?B?...?=` base64 (safe) and HTML bodies containing raw UTF-8 characters (e.g., `être`, `à`, `é`).  When PHP inserts a UTF-8 string into a latin1 MySQL column over a `latin1` connection, multi-byte UTF-8 sequences are silently corrupted: `é` (`\xC3\xA9`) becomes two latin1 characters `Ã©`, reproducing the encoding bug.

The schema comment acknowledges this trade-off ("latin1 for FK compatibility with membre") but `subject` and `body_html` have no FK dependency and could be `utf8mb4` independently.  `recipient_email` is the only column that needs latin1 for FK compatibility.

**Recommended fix:** Add a migration to alter `subject` and `body_html` columns in `email_queue` to `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`, keeping `recipient_email` as `latin1`.

---

### EMAIL-P9-006
**Severity:** MEDIUM
**File:line:** `includes/player.php:1200–1261`
**Title:** Failed email sends have no retry cap — permanently undeliverable addresses fill the queue forever

**Description:**
When `mail()` returns `false`, the queue row is left with `sent_at = NULL`:
```php
logWarn('EMAIL_QUEUE', 'mail() failed for queued email', ['id' => $id, 'recipient' => $recipient]);
// row NOT updated; left in queue
```
There is no `retry_count` column, no `failed_at` timestamp, and no upper bound on retry attempts.  A permanently invalid address (e.g., one that was valid at registration but the mailbox was later deleted, or a typo that somehow passed `FILTER_VALIDATE_EMAIL`) will be retried on every one-in-one-hundred page loads forever.  With a large player base, this creates a growing queue that degrades `processEmailQueue()` performance and pollutes logs.

**Recommended fix:** Add a `retry_count INT DEFAULT 0` and `failed_at INT NULL` column to `email_queue`; in `processEmailQueue()`, increment `retry_count` and set `failed_at` on failure, and skip rows where `retry_count >= 5`.

---

### EMAIL-P9-007
**Severity:** LOW
**File:line:** `inscription.php:42–43`
**Title:** Email enumeration via distinct error messages at registration

**Description:**
Registration reveals whether an email address is already registered via a distinct error message:
```php
if ($nbMail > 0) {
    $erreur = 'L\'email est déjà utilisé.';
```
An attacker can enumerate which email addresses have TVLW accounts by submitting registration forms with target email addresses and observing this error vs. "L'email n'est pas correct." (invalid format) or "Ce login est déjà utilisé." (login conflict).

This is a common trade-off in game registration (user-friendliness vs. privacy), but in a small-community game where player identities may be partially known, leaking email membership can facilitate targeted social engineering.  The same disclosure exists in `compte.php:109` for the email-change flow.

**Recommended fix:** Replace the distinct "email already used" message with a generic "Ce login ou email est déjà utilisé." message that does not distinguish between login and email conflicts; alternatively, use a side-channel-safe response ("If this email is not already registered, your account has been created") consistent with security-focused registration flows.

---

### EMAIL-P9-008
**Severity:** LOW
**File:line:** `includes/player.php:1239`
**Title:** `From` header display name not encoded — may cause rendering issues with non-ASCII game names

**Description:**
The From header is:
```php
$header = "From: \"The Very Little War\"<noreply@theverylittlewar.com>" . $eol;
```
The display name `"The Very Little War"` is ASCII-only today, so this is safe.  However, RFC 2047 requires non-ASCII display names to be encoded with `mb_encode_mimeheader()` or equivalent.  If the game name is ever localised (e.g., to include accented characters), this will produce a malformed From header causing rejection by strict MTAs.  Also, there is a missing space between the closing `"` of the display name and `<` which some MTA implementations handle inconsistently.

**Recommended fix:** Use `mb_encode_mimeheader()` for the From display name and add a space before `<`: `"From: " . mb_encode_mimeheader('"The Very Little War"') . " <noreply@theverylittlewar.com>"`.

---

### EMAIL-P9-009
**Severity:** LOW
**File:line:** `includes/basicprivatephp.php:250`
**Title:** Unsubscribe mechanism is a workaround (change email) rather than a proper opt-out

**Description:**
The season-end email includes:
```html
<i>Si vous ne souhaitez plus recevoir ce genre de mail il suffit de changer votre adresse e-mail sur www.theverylittlewar.com dans la partie "Mon compte".</i>
```
This instructs players to change their email to opt out, which is not a standard unsubscribe mechanism.  There is no `List-Unsubscribe` header, no dedicated unsubscribe endpoint, and no `email_notifications` preference column.  While the game is small and not subject to GDPR enforcement at this scale, this pattern is contrary to good email hygiene practices and best-practice deliverability (RFC 8058, Gmail/Yahoo bulk sender requirements).  Changing one's email as an unsubscribe mechanism also risks players providing fake emails, degrading future notification quality.

**Recommended fix:** Add an `email_notifications TINYINT(1) DEFAULT 1` column to `membre`; add a `List-Unsubscribe` header pointing to an `unsubscribe.php?token=...` endpoint that sets this flag to 0; filter out opted-out players in the `SELECT email, login FROM membre` query in `basicprivatephp.php`.

---

### EMAIL-P9-010
**Severity:** LOW
**File:line:** `includes/player.php:1258`
**Title:** Failed `mail()` calls log the raw recipient email address — PII in log files

**Description:**
```php
logWarn('EMAIL_QUEUE', 'mail() failed for queued email', ['id' => $id, 'recipient' => $recipient]);
```
The player's full email address is written to the application log on send failure.  Log files are typically stored in world-readable locations (`/var/log/`, `data/logs/`) or transmitted to aggregation services.  Email addresses are PII under GDPR and should not be logged in plaintext.

**Recommended fix:** Log a hashed or redacted form of the email: `'recipient_hash' => substr(hash('sha256', $recipient . SECRET_SALT), 0, 12)` instead of the full address.

---

### EMAIL-P9-011
**Severity:** LOW
**File:line:** `includes/multiaccount.php:291`
**Title:** Admin email address falls back to hardcoded Gmail address in source code

**Description:**
```php
$adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: 'theverylittlewar@gmail.com';
```
If the `ADMIN_ALERT_EMAIL` environment variable is not set (which is the common case on the VPS based on the deployment notes — there is no `.env` loader), the hardcoded fallback Gmail address is used.  Hardcoding a Gmail address in source code that is committed to a public GitHub repository means the admin contact email is permanently disclosed.  While this is not immediately a security vulnerability, it exposes the admin's email to scrapers and removes the ability to change it without a code commit.

**Recommended fix:** Load configuration from a `.env` file (already partially planned in MEMORY.md) or read from a `config.php` constant `ADMIN_ALERT_EMAIL` that is set per deployment; ensure the fallback email is not committed to version control.

---

### EMAIL-P9-012
**Severity:** INFO
**File:line:** `includes/player.php:1228–1232`
**Title:** Hotmail/Live/MSN EOL detection regex is case-sensitive and TLD-limited

**Description:**
```php
if (preg_match("#^[a-z0-9._-]+@(hotmail|live|msn)\.[a-z]{2,4}$#", $recipient)) {
    $eol = "\n";
}
```
- Matches only lowercase local parts (`User@hotmail.com` won't match — `U` fails `[a-z]`).
- Limits Hotmail TLD to 2–4 characters, missing `hotmail.co.uk` (8 chars) — but `co.uk` is 5 chars total after the dot, so it actually is caught... wait: `\.co\.uk` is two segments; this regex only allows one dot after the domain name, so `hotmail.co.uk` does NOT match.
- `msn.com` is essentially retired; `outlook.com` is the modern Hotmail successor and is absent.

This is an INFO-level finding (delivery quality, not security), but it means some Hotmail/Outlook users receive emails with RFC-incorrect `\r\n` line endings.

**Recommended fix:** Use `stripos($recipient, '@hotmail.') !== false || stripos($recipient, '@outlook.') !== false || stripos($recipient, '@live.') !== false` for the EOL check, or simply standardize on `\r\n` (which is RFC 5322 correct) for all recipients and let modern MTAs handle normalization.

---

### EMAIL-P9-013
**Severity:** INFO
**File:line:** `includes/player.php:1234`
**Title:** MIME boundary uses `md5(id + time())` — low entropy, not cryptographically random

**Description:**
```php
$boundary = "-----=" . md5((string)$id . (string)time());
```
`md5()` of a predictable input (sequential queue ID + Unix timestamp) produces a deterministic, guessable boundary.  Per RFC 2046, MIME boundaries do not need to be secret or unpredictable — they only need to be absent from the body content.  This is not a security vulnerability but follows an insecure pattern inconsistent with `bin2hex(random_bytes(16))` used elsewhere in the codebase.

**Recommended fix:** Replace with `$boundary = "-----=" . bin2hex(random_bytes(8));` for consistency and slightly better collision resistance.

---

## Summary Table

| ID | Severity | File | Title |
|----|----------|------|-------|
| EMAIL-P9-001 | HIGH | player.php:1222 | recipient_email not CRLF-sanitized before mail() |
| EMAIL-P9-002 | HIGH | player.php:1228 | EOL detection case-sensitive; hotmail.co.uk missed; pattern fragility |
| EMAIL-P9-003 | MEDIUM | multiaccount.php:294 | Admin alert body contains raw user login names — CRLF body injection |
| EMAIL-P9-004 | MEDIUM | basicprivatephp.php:247 | $resetDate written raw into HTML body; latin1 column corrupts UTF-8 `à` |
| EMAIL-P9-005 | MEDIUM | migrations/0038 | email_queue subject/body columns latin1 — UTF-8 content corrupted |
| EMAIL-P9-006 | MEDIUM | player.php:1257 | No retry cap on failed sends; poisoned queue grows unbounded |
| EMAIL-P9-007 | LOW | inscription.php:42 | Email enumeration via distinct error messages |
| EMAIL-P9-008 | LOW | player.php:1239 | From display name not RFC 2047 encoded; missing space before `<` |
| EMAIL-P9-009 | LOW | basicprivatephp.php:250 | No proper unsubscribe / List-Unsubscribe header |
| EMAIL-P9-010 | LOW | player.php:1258 | Full email address (PII) logged on send failure |
| EMAIL-P9-011 | LOW | multiaccount.php:291 | Admin email hardcoded in version-controlled source |
| EMAIL-P9-012 | INFO | player.php:1228 | Hotmail EOL regex case-sensitive, misses outlook.com and .co.uk TLDs |
| EMAIL-P9-013 | INFO | player.php:1234 | MIME boundary uses md5(id+time) instead of random_bytes |

---

## Notes on Items NOT Found (confirmed clean)

- **No password reset flow exists** — the game has no "forgot password" feature, so there is no password-reset token email attack surface.
- **No plaintext passwords in emails** — passwords are bcrypt-hashed; neither the season-reset email nor the admin alert email contains any credential.
- **No session tokens in emails** — session tokens are not included in any email body.
- **Subject encoding correct** — the season-reset subject uses `=?UTF-8?B?` base64 encoding (`base64_encode("Début d'une nouvelle partie")`), which is RFC 2047 compliant.
- **Reply-to typo previously fixed** — prior audits found `theverylittewar@gmail.com` (missing 'l'); the current code at `player.php:1240` correctly shows `theverylittlewar@gmail.com`.
- **No SMTP credentials in source** — the game uses PHP's native `mail()` via the system MTA; no SMTP username/password is present in any file.
- **XSS in HTML email body** — player login (`$recipientLogin`) and winner name (`$winnerName`) are both escaped with `htmlspecialchars()` in the season-reset email body.

---

FINDINGS: 0 critical, 2 high, 4 medium, 5 low
