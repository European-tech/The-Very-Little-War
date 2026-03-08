# Pass 9 Fix — Batch 15b: Admin IP Display Truncation

**Date:** 2026-03-08
**Finding:** MULTI-P9-004
**Severity:** LOW (information-disclosure hardening)

## Summary

Since Batch 10, `membre.ip` and `login_history.ip` store HMAC-SHA256 hashes (64-char hex strings) instead of raw IP addresses. Before this batch the admin account-detail view in `admin/multiaccount.php` displayed the full 64-char hash in the IP column of the login-history table, which is unnecessary exposure of the opaque identifier. This batch truncates the display to the first 12 characters followed by an ellipsis (`…`), matching the treatment already applied to the `fingerprint` column.

## Files Changed

### `admin/multiaccount.php` — line 262

**Before:**
```php
<td><?php echo htmlspecialchars($lh['ip'], ENT_QUOTES, 'UTF-8'); ?></td>
<td title="..."><?php echo htmlspecialchars(substr($lh['fingerprint'] ?? '', 0, 12), ENT_QUOTES, 'UTF-8'); ?>...</td>
```

**After:**
```php
<td title="<?php echo htmlspecialchars($lh['ip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php $ipDisplay = substr($lh['ip'] ?? '', 0, 12) . '…'; echo htmlspecialchars($ipDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
<td title="<?php echo htmlspecialchars($lh['fingerprint'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr($lh['fingerprint'] ?? '', 0, 12), ENT_QUOTES, 'UTF-8'); ?>…</td>
```

**Changes made:**
- IP cell: now shows only first 12 chars of the stored hash + `…` (was full 64-char hash)
- IP cell: full hash moved to `title` attribute for hover-inspect if needed
- Fingerprint cell: opportunistically changed ASCII `...` to `…` to match IP cell style (no functional change)
- Used `?? ''` null-safe fallback on `$lh['ip']` to match the existing fingerprint pattern

**Lines affected:** 262–263

## Files Inspected but Not Changed

### `moderation/ip.php`

This file takes a raw IP from `$_GET['ip']` and displays it back in the page heading for context (`htmlspecialchars`-escaped). It does **not** display the stored hash from the database — it only outputs the player logins that matched the hashed lookup. No truncation is needed here; the admin inputs the raw IP themselves.

## PHP Lint Result

```
No syntax errors detected in admin/multiaccount.php
```

## Verification

- The `fingerprint` column was already truncated to 12 chars before this batch (correct baseline)
- The `user_agent` column was already truncated to 60 chars before this batch (correct baseline)
- No other IP output locations were found in `admin/multiaccount.php` (the `$uniqueIPs` stat on the stats tab is a count, not a displayed IP value)
- `moderation/ip.php` confirmed clean — displays user logins only, not stored hashes
