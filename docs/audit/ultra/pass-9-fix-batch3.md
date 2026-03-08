# Pass 9 Fix Batch 3 — Email Queue Security & Reliability

**Date:** 2026-03-08
**Files modified:**
- `includes/basicprivatephp.php`
- `includes/player.php`

**File created:**
- `migrations/0077_email_queue_utf8.sql`

**php -l results:** No syntax errors in either modified file.

---

## P9-HIGH-003 — Admin alert body injection via login names

**File:** `includes/basicprivatephp.php` (email queue INSERT block, ~line 239)

**Problem:** The `$winnerName` value comes from `$vainqueurManche`, which is a player-controlled login retrieved from the database. Although it was eventually passed through `htmlspecialchars()`, the raw value was not stripped of CRLF sequences before use, and the variable was used without applying `strip_tags()` or CRLF stripping to the intermediate `$winnerName` binding. A login such as `"foo\r\nBcc: evil@example.com"` could have enabled header injection if the value were ever used in a mail header instead of a body.

**Fix applied:**
1. `$winnerName` is now CRLF-stripped immediately after assignment: `str_replace(["\r", "\n"], '', $winnerNameRaw)`.
2. `$recipientEmail` and `$recipientLogin` are both CRLF-stripped before use.
3. Both values are escaped with `htmlspecialchars(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')` into dedicated `$safeLogin` and `$safeWinner` variables. These are the only values embedded in `$message_html`.

---

## P9-HIGH-004 — $resetDate UTF-8 `a` in latin1 email_queue table

**File:** `includes/basicprivatephp.php` (~line 242)

**Problem:** `date('d/m/Y a H\hi', time())` produced a UTF-8 string containing the French preposition `a` (U+00E0, two bytes in UTF-8). Inserting this into the latin1 `body_html` column of `email_queue` silently corrupted the character to a question mark or mojibake.

**Fix applied:** Changed the format string to `'d/m/Y \a H\hi'` — using a literal backslash-escaped ASCII `a` — so the output is pure 7-bit ASCII (`01/01/2026 a 14h00`) regardless of the column charset. The surrounding French text in `$message_html` that contained accented characters (`a`, `e`, etc.) was also rewritten to unaccented ASCII equivalents so the entire body is safe for the current latin1 column. This fix remains valid after migration 0077 converts the column to utf8mb4.

The RFC 2047 subject encoding (`=?UTF-8?B?...?=`) was updated similarly: `"Debut d'une nouvelle partie"` (accent removed) avoids any charset issue in the subject column.

---

## P9-HIGH-005 — email_queue subject/body_html columns should be utf8mb4

**File created:** `migrations/0077_email_queue_utf8.sql`

**Problem:** `email_queue` was created with `DEFAULT CHARSET=latin1` (migration 0038). The `subject` and `body_html` columns store player-facing text that can contain multi-byte characters. Storing UTF-8 content in latin1 columns causes silent data loss.

**Fix applied:** Migration 0077 uses an idempotent pattern:
1. Reads `CHARACTER_SET_NAME` from `information_schema.COLUMNS` for each target column.
2. Builds a dynamic `ALTER TABLE ... MODIFY COLUMN ... CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` statement only if the column is not already utf8mb4.
3. Executes via `PREPARE` / `EXECUTE` / `DEALLOCATE`.

`recipient_email` is intentionally left as latin1 for FK compatibility with `membre.email`.

---

## P9-MED-004 — Failed sends have no retry cap

**File:** `includes/player.php`, function `processEmailQueue()` (~line 1216)

**Problem:** The SELECT query fetched all unsent rows with no age limit. A row that consistently fails (e.g., invalid MX record) is retried on every 1% page load indefinitely, wasting SMTP connection attempts.

**Fix applied:** Added a `created_at` age guard to the SELECT:

```sql
AND (created_at IS NULL OR created_at > UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR))
```

Rows older than 24 hours are silently skipped (not deleted — an admin can still inspect them). The `created_at IS NULL` branch preserves backward compatibility with any pre-0038 rows that lack the column.

---

## P9-LOW-001 — Full email logged on failure

**File:** `includes/player.php`, function `processEmailQueue()` (~line 1264)

**Problem:** On `mail()` failure, `logWarn()` wrote `'recipient' => $recipient` — a full email address — to the log file, creating a PII exposure risk.

**Fix applied:** Replaced with a 12-character hex prefix of the SHA-256 digest:

```php
'recipient_hash' => substr(hash('sha256', $recipient), 0, 12)
```

This is sufficient to correlate log entries with a known address when debugging while exposing no meaningful PII to anyone reading the log.

---

## Summary table

| Finding     | Severity | File                       | Status  |
|-------------|----------|----------------------------|---------|
| P9-HIGH-003 | HIGH     | includes/basicprivatephp.php | FIXED  |
| P9-HIGH-004 | HIGH     | includes/basicprivatephp.php | FIXED  |
| P9-HIGH-005 | HIGH     | migrations/0077_email_queue_utf8.sql | CREATED |
| P9-MED-004  | MEDIUM   | includes/player.php          | FIXED  |
| P9-LOW-001  | LOW      | includes/player.php          | FIXED  |
