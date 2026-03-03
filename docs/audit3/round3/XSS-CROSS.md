# XSS Cross-Domain Analysis — Round 3

**Auditor:** Penetration Tester Agent
**Date:** 2026-03-03
**Scope:** All 93 PHP files — 10 XSS analysis categories
**Round 1/2 cross-reference:** All prior XSS findings reviewed

## Summary

Total findings: 16 (0 CRITICAL, 0 HIGH, 5 MEDIUM, 7 LOW, 4 INFORMATIONAL)

The codebase has strong XSS defenses overall: `antiXSS()`/`antihtml()`/`sanitizeOutput()` helpers are widely used, BBCode parser pre-encodes with `htmlentities()`, `json_encode()` is used for JS context, and login validation regex blocks XSS characters. However, several output points bypass these defenses.

---

## MEDIUM Findings

### XSS-01 [MEDIUM] Unescaped player names in attaquer.php
**File:** attaquer.php:207, 209, 248
**Issue:** Player login names rendered without `htmlspecialchars()` in attack target display.
**Risk:** Player names are validated by regex at registration (alphanumeric + limited chars), making exploitation unlikely but defense-in-depth requires escaping.
**Fix:** Wrap all player name outputs in `htmlspecialchars($name, ENT_QUOTES, 'UTF-8')`.

### XSS-02 [MEDIUM] Unescaped alliance data in alliance.php
**File:** alliance.php:196, 209, 212, 224, 227, 432
**Issue:** Alliance names, descriptions, and member data rendered without consistent escaping.
**Risk:** Alliance names set by players could contain XSS payloads if input validation is bypassed.
**Fix:** Apply `htmlspecialchars()` to all alliance data outputs.

### XSS-03 [MEDIUM] Stored XSS via message titles — MOST EXPLOITABLE
**File:** messages.php:28
**Issue:** Message titles passed to `debutCarte()` without escaping. Any player can send a message with XSS payload in the title. The `debutCarte()` function in `ui_components.php:16-19` renders the title as raw HTML.
**Fix (Priority 1):**
```php
debutCarte(htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8'));
```

### XSS-05 [MEDIUM] Admin tableau name unescaped
**File:** admin/tableau.php:76
**Issue:** Player names from database rendered without escaping in admin panel.
**Fix:** Apply `htmlspecialchars()` to all database values in admin display.

### XSS-06 [MEDIUM] PHP-to-JS without json_encode
**File:** basicprivatehtml.php:433-459
**Issue:** PHP variables embedded in JavaScript context without `json_encode()` wrapper.
**Fix:** Use `json_encode()` for all PHP→JS value injection.

---

## LOW Findings

### XSS-04 [LOW] Player login as card title in joueur.php
**File:** joueur.php:42
**Issue:** Player login used as card title without escaping. Low risk due to input validation at registration.

### XSS-07 [LOW] htmlspecialchars in JS redirect context
**File:** basicprivatehtml.php (JS redirects)
**Issue:** `htmlspecialchars()` used in JavaScript redirect context. Only hardcoded strings used, so no actual risk.

### XSS-09 [LOW] Video iframe src without protocol validation
**File:** video.php:31, 33
**Issue:** Video URLs rendered in iframe `src` without protocol validation. File is dead/unreachable.

### XSS-10 [LOW] Inconsistent urlencode vs htmlspecialchars
**File:** joueur.php:68-73
**Issue:** `urlencode()` used where `htmlspecialchars()` would be more appropriate for HTML attribute context.

### XSS-11 [LOW] BBCode external image loading
**File:** bbcode.php:332
**Issue:** BBCode `[img]` tag allows loading external images. Could be used for tracking/CSRF via image URLs but not direct XSS.

### XSS-13 [LOW] Raw $erreur echo in moderation
**File:** moderation/index.php:179
**Issue:** Error variable echoed without escaping. Usually contains server-generated messages but should be escaped for defense-in-depth.

### XSS-12 [LOW] Forum post content rendering
**File:** sujet.php (message display loop)
**Issue:** Forum messages processed through BBCode parser which pre-encodes with `htmlentities()`. Safe by design but relies on BBCode parser correctness.

---

## INFORMATIONAL

### XSS-14 [INFO] strip_tags allows attribute XSS
**File:** rapports.php:31
**Issue:** `strip_tags()` used on combat report content. `strip_tags()` does not remove attributes from allowed tags. If allowed tags include `<img>`, then `<img onerror=...>` passes through.
**Note:** Cross-references with SEC-CROSS CHAIN-05 (admin news editor XSS).

### XSS-15 [INFO] CSP unsafe-inline
**Issue:** Content-Security-Policy header allows `'unsafe-inline'` for both `script-src` and `style-src`, defeating XSS protection at the browser level.
**Note:** Tracked as INFRA-R1-001 / INFRA-R2-001 across all rounds.

### XSS-16 [INFO] Tutorial text without escaping
**File:** tutoriel.php (various lines)
**Issue:** Tutorial text output without escaping. All values are hardcoded French strings — zero risk.

### XSS-17 [INFO] debutCarte() systemic issue
**File:** includes/modules/ui_components.php:16-19
**Issue:** The `debutCarte()` function renders its title parameter as raw HTML. Every caller must ensure the title is pre-escaped. This is a systemic pattern — any new caller that passes user data will create a stored XSS.
**Fix:** Escape inside `debutCarte()` itself: `$titre = htmlspecialchars($titre, ENT_QUOTES, 'UTF-8');`

---

## Positive Controls Identified

| Control | Location | Status |
|---------|----------|--------|
| `antiXSS()` input sanitizer | includes/validation.php | Widely used on POST inputs |
| `antihtml()` / `sanitizeOutput()` | includes/modules/display.php | Used on most display outputs |
| BBCode `htmlentities()` pre-encoding | includes/bbcode.php | Prevents XSS in BBCode content |
| `json_encode()` for JS context | includes/copyright.php | Correctly used for error messages |
| Login regex validation | inscription.php, comptetest.php | Blocks special chars in usernames |
| Prepared statements | includes/database.php | Prevents SQL injection (indirect XSS vector) |

## Priority Fix Order

1. **XSS-03** — Message title stored XSS (any player can exploit)
2. **XSS-17** — Fix `debutCarte()` to escape internally (systemic fix)
3. **XSS-02** — Alliance data escaping
4. **XSS-06** — PHP-to-JS `json_encode()` fixes
5. **XSS-05** — Admin panel escaping
6. **XSS-01** — Attack page player name escaping
