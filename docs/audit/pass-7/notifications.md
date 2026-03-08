# Pass 7 Audit — NOTIFICATIONS Domain
**Date:** 2026-03-08
**Agent:** Pass7-C6-NOTIFICATIONS

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 1 |
| MEDIUM | 3 |
| LOW | 1 |
| **Total** | **5** |

---

## HIGH Findings

### HIGH-001 — Email subject not MIME-encoded
**File:** `includes/player.php:1242`
**Description:** Email subjects containing non-ASCII characters (French accents: "Égalité", "d'espionnage", etc.) are CRLF-stripped but NOT encoded with `mb_encode_mimeheader()`. Recipients may receive corrupted subject lines (mojibake).
**Fix:**
```php
// BEFORE:
$subject = str_replace(["\r", "\n"], '', $row['subject']);
// AFTER:
$subject = mb_encode_mimeheader(str_replace(["\r", "\n"], '', $row['subject']), 'UTF-8');
```

---

## MEDIUM Findings

### MEDIUM-001 — Missing space in From header (RFC 5322 non-compliance)
**File:** `includes/player.php:1259`
**Description:** `"From: \"The Very Little War\"<noreply@...>"` is missing a space between the display name and the angle-bracketed address. RFC 5322 requires a space. Some mail servers may reject or misparse.
**Fix:**
```php
$header = "From: \"The Very Little War\" <noreply@theverylittlewar.com>" . $eol;
```

### MEDIUM-002 — Email From/Reply-To addresses hardcoded
**File:** `includes/player.php:1259-1260`
**Description:** Email addresses hardcoded in `processEmailQueue()` instead of using config constants. Changing them requires code edits.
**Fix:** Define in config.php:
```php
define('EMAIL_FROM', 'noreply@theverylittlewar.com');
define('EMAIL_REPLY_TO', 'theverylittlewar@gmail.com');
define('EMAIL_FROM_NAME', 'The Very Little War');
```
Use in player.php:
```php
$header = "From: \"" . EMAIL_FROM_NAME . "\" <" . EMAIL_FROM . ">" . $eol;
$header .= "Reply-to: \"" . EMAIL_FROM_NAME . "\" <" . EMAIL_REPLY_TO . ">" . $eol;
```

### MEDIUM-003 — Email queue drain probability hardcoded
**File:** `includes/basicprivatephp.php:349`
**Description:** `mt_rand(1, 100) === 1` hardcodes 1% drain probability instead of using a config constant.
**Fix:** Add `define('EMAIL_QUEUE_DRAIN_PROB_DENOM', 100)` to config.php and use it in the condition.

### MEDIUM-004 — Report read-status UPDATE lacks destinataire defense-in-depth
**File:** `rapports.php:26`
**Description:** The `UPDATE rapports SET statut=1 WHERE id = ?` does not include `AND destinataire = ?` in the WHERE clause. Ownership is verified by the surrounding `if ($nb_rapports > 0)` guard (based on a SELECT that includes `destinataire`), but the UPDATE itself is not self-defending. A future refactor removing the if-guard would leave the UPDATE unprotected.
**Fix:**
```php
dbExecute($base, 'UPDATE rapports SET statut=1 WHERE id = ? AND destinataire = ? AND statut=0',
    'is', $rapportId, $_SESSION['login']);
```

---

## LOW Findings

### LOW-001 — No explicit email queue size cap
**File:** `includes/player.php:1225-1283`
**Description:** No maximum queue size check before draining. Email queue is capped implicitly by the 24-hour retention filter (`created_at > NOW() - INTERVAL 24 HOUR`) and the fact that emails are queued only once per season reset (≤ active player count). Low risk in practice.
**Fix:** Optional guard: check queue size before processing; skip drain if queue is suspiciously large (could indicate a bug).

---

## Verified Clean

- **Access control on rapports.php:** All SELECT queries include `AND destinataire = ?` with `$_SESSION['login']` — clean.
- **XSS on report content:** Report titles escaped with `htmlspecialchars(ENT_QUOTES, 'UTF-8')`; report images sanitized with `strip_tags()` + event handler removal — clean.
- **Email CRLF injection on recipient:** `str_replace(["\r", "\n"], '', ...)` on recipient email — clean.
- **Random MIME boundary:** `random_bytes(8)` hex-encoded — clean.
- **Pagination bounds:** Page number bounds-checked; used in prepared statement — clean.
- **historique.php access control:** Displays only archived season data from `parties` table; no current player data leaked — clean.
- **CSRF on report deletion:** All DELETE forms include `csrfField()` — clean.
- **Email body HTML encoding:** User-controlled values (login, winner name) escaped with `htmlspecialchars()` before embedding in HTML body — clean.
- **processEmailQueue LIMIT:** Processes at most 20 emails per invocation — no unbounded processing — clean.
