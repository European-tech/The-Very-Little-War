# Ultra Audit Pass 9 — Fix Batch 15a

Date: 2026-03-08
Agent: fix-agent (claude-sonnet-4-6)
Scope: Independent items — SPY-P9-009, MULTI-P9-007, REG-P9-004

---

## Summary

| ID | Title | Status |
|----|-------|--------|
| SPY-P9-009 | couleurFormule() unescaped output (latent XSS) | FIXED |
| MULTI-P9-007 | Trusted-proxy documentation | FIXED |
| REG-P9-004 | Registration bot protection | DEFERRED |

---

## SPY-P9-009: game_actions.php — couleurFormule() unescaped output (latent XSS)

**File read before edit:** Yes — `includes/game_actions.php` (lines 360–415)
**Definition read:** Yes — `includes/display.php`, `couleurFormule()` at line 57

**Finding:**
`couleurFormule()` in `includes/display.php` (line 57) applies `preg_replace()` directly to its `$formule` argument without any `htmlspecialchars()` call. The function signature is `couleurFormule($formule)` and it returns the string with color `<span>` elements injected around element symbols. It does not escape its input.

At `includes/game_actions.php` line 398, `$espClass['formule']` (a DB-sourced string) was passed directly to `couleurFormule()` and inserted into the espionage report HTML. A molecule formula containing `<script>` or other HTML meta-characters sourced from the database would be rendered unescaped.

**Fix applied:**
In the `foreach ($espClasses as $espClass)` loop (around line 397), the formula is now escaped with `htmlspecialchars()` before being passed to `couleurFormule()`:

```php
$safeFormule = htmlspecialchars($espClass['formule'], ENT_QUOTES, 'UTF-8');
$armeeHtml .= "<strong>" . couleurFormule($safeFormule) . " : </strong>" . ...
```

**Why this is safe:** The regex patterns in `couleurFormule()` match element letter symbols (single characters like C, N, H, O) followed by `<sub>...</sub>` tags. After `htmlspecialchars()`, any `<` in the formula becomes `&lt;`, so no injected tags survive. The legitimate `<sub>` subscript tags in well-formed formulas stored in the database are NOT present in `$espClass['formule']` — they are produced dynamically by `couleurFormule()` itself via the regex substitution. Therefore pre-escaping the raw formula string does not break the coloring logic.

---

## MULTI-P9-007: config.php + multiaccount.php — Trusted-proxy documentation

**Files read before edit:** Yes — `includes/config.php` (lines 1–50), `includes/multiaccount.php` (lines 1–35)

**Finding:**
`includes/multiaccount.php` line 22 used `$_SERVER['REMOTE_ADDR']` directly with no comment explaining why `X-Forwarded-For` is not used. A future developer adding a reverse proxy (Cloudflare, nginx) might be tempted to switch to `X-Forwarded-For` without understanding that doing so without validating the source IP enables IP spoofing and bypasses multi-account detection. No `TRUSTED_PROXY_IPS` constant existed in `config.php`.

**Fix applied (config.php):**
Added a `TRUSTED_PROXY_IPS` constant definition block in the SECURITY section of `includes/config.php`, immediately before the `ADMIN_RESOURCE_GRANT_DEFAULT` define. The block includes a full explanation of the current architecture (direct VPS, no proxy), the risk of unsanitised `X-Forwarded-For` use, and instructions for future proxy integration:

```php
define('TRUSTED_PROXY_IPS', []); // empty = direct connect assumed; add proxy IPs as array of strings if needed
```

**Fix applied (multiaccount.php):**
Added a two-line comment directly above `$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';` in `logLoginEvent()`:

```php
// Direct connection assumed (TRUSTED_PROXY_IPS is empty in config.php).
// If TRUSTED_PROXY_IPS is ever populated, update this line to extract real client IP from X-Forwarded-For.
```

**Note:** MULTI-P9-004 (truncated IP display in admin dashboard) is explicitly deferred to Batch 15b as it depends on Batch 10 completing first. No changes made for MULTI-P9-004 in this batch.

---

## REG-P9-004: Registration bot protection — DEFERRED

**Status:** DEFERRED — No action taken.

**Reason:** This item is explicitly deferred per the audit specification. Implementing CAPTCHA or honeypot fields requires UX design decisions (choice of provider, accessibility impact, UI placement) that are out of scope for this remediation batch. The gap is acknowledged: the `/inscription.php` registration form has no bot protection beyond rate limiting. This is tracked for a future UX sprint.

---

## Files Modified

| File | Change |
|------|--------|
| `includes/game_actions.php` | SPY-P9-009: Added `htmlspecialchars()` pre-escaping before `couleurFormule()` call in espionage report builder |
| `includes/config.php` | MULTI-P9-007: Added `TRUSTED_PROXY_IPS` constant with full documentation block in SECURITY section |
| `includes/multiaccount.php` | MULTI-P9-007: Added comment above `$ip = $_SERVER['REMOTE_ADDR']` in `logLoginEvent()` |

## Files Read (not modified)

| File | Purpose |
|------|---------|
| `includes/display.php` | Verified `couleurFormule()` definition — confirmed no internal `htmlspecialchars()` call |
