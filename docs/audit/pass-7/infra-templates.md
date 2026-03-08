# Pass 7 Audit — INFRA-TEMPLATES Domain
**Date:** 2026-03-08
**Agent:** Pass7-C7-INFRA-TEMPLATES

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 1 |
| LOW | 3 |
| INFO | 10 |
| **Total** | **4** |

---

## MEDIUM Findings

### MEDIUM-001 — couleurFormule() requires pre-escaped input but has no internal guard
**File:** `includes/display.php:57-68`
**Description:** `couleurFormule()` uses `preg_replace()` to inject colored HTML spans but does NOT apply `htmlspecialchars()` internally. All current callers (game_actions.php:401, armee.php:171/311/407/498, attaque.php:71, molecule.php:50, bilan.php:648) correctly pre-escape before calling. However, the function has no defensive escaping, creating an implicit contract that could be violated by future callers.
**Fix:** Add an internal `htmlspecialchars()` call at the start of `couleurFormule()`, or add a docblock comment explicitly stating the pre-escaping requirement.

---

## LOW Findings

### LOW-001 — health.php exposes PHP version to localhost
**File:** `health.php:46`
**Description:** PHP version included in response body for requests from 127.0.0.1 or ::1. Acceptable for monitoring but minor information disclosure if localhost filter bypassed.
**Risk:** Very low — localhost-only. No fix required, but monitor for proxy bypass.

### LOW-002 — Season countdown data-end attribute lacks consistent htmlspecialchars
**File:** `includes/layout.php:74`
**Description:** Countdown uses `data-end="<?php echo (int)$seasonEndTimestamp; ?>"`. The `(int)` cast prevents XSS, but `index.php:150` adds `htmlspecialchars()` for consistency. Minor defensive coding inconsistency.
**Fix:** Use `htmlspecialchars((int)$seasonEndTimestamp, ENT_QUOTES, 'UTF-8')` for uniformity.

### LOW-003 — BBCode URL length limit hardcoded (not a config constant)
**File:** `includes/bbcode.php:29`
**Description:** `[url]` regex limits URLs to 500 characters (`{1,500}`) but this is not extracted to a named constant. If the limit needs to change, it must be updated in-place.
**Fix:** Add `define('BBCODE_URL_MAX_LENGTH', 500)` to config.php and use in regex.

---

## Verified Clean

- **CSP nonce:** `cspNonce()` called once per request (layout.php:2); stored in `$GLOBALS['csp_nonce']`; same nonce used in header and all script tags; `script-src` has no `unsafe-inline` — clean.
- **Inline event handlers:** No `onclick=`, `onload=`, `onerror=` in layout.php or templates; all binding via JS event listeners — clean.
- **jQuery SRI:** jQuery 3.7.1 from CDN with full `integrity=sha512-...` and `crossorigin="anonymous"` — clean.
- **Countdown timer:** Reads from PHP-injected `(int)` cast data attribute; handles negative countdown with "Nouvelle saison imminente !"; clears interval at zero — clean.
- **SEO meta tags:** `og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `og:locale`, `og:site_name` all present in meta.php — clean.
- **XSS in templates:** Player names in navbar not displayed; grade names in forum cards escaped with `htmlspecialchars()` — clean.
- **health.php:** No sensitive data to remote clients; valid JSON response; DB credentials not returned — clean.
- **version.php:** No PHP/MariaDB version exposed; git hash restricted to admin sessions only; `escapeshellarg()` used for shell safety — clean.
- **BBCode parser:** `htmlspecialchars()` applied FIRST (line 14) before parsing; `[img]` whitelisted to `images/` prefix, absolute paths, and `https://www.theverylittlewar.com/` only; LaTeX denylist for RCE prevention; URLs use `rel="nofollow noopener noreferrer"` — clean.
- **HTML maxlength:** Login form attributes use `LOGIN_MAX_LENGTH`, `EMAIL_MAX_LENGTH` constants — clean.
