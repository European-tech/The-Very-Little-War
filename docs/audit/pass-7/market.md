# Pass 7 Audit — MARKET Domain
**Date:** 2026-03-08
**Agent:** Pass7-B4-MARKET

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 1 |
| LOW | 1 |
| **Total** | **2** |

---

## MEDIUM Findings

### MEDIUM-001 — Chart price precision loss via floatval cast
**File:** `marche.php:765-767`
**Description:** `floatval()` casts and `implode()` back-to-string uses PHP's default 14 significant digits precision. If historical prices stored with higher precision, chart shows truncated values. This is a visual-only issue — transaction prices are re-computed fresh inside locked transaction.
**Fix:**
```php
$vals = array_map(function($v) {
    return sprintf('%.15g', floatval($v));
}, explode(',', $cours['tableauCours']));
```

---

## LOW Findings

### LOW-001 — Chart timestamp timezone not explicitly verified at render time
**File:** `marche.php:767`
**Description:** `date()` uses server timezone; comment references config.php setting but doesn't verify it's active at render time. Chart x-axis could show incorrect times if timezone not properly initialized.
**Fix:** Ensure `date_default_timezone_set(TIMEZONE)` is called in config.php or basicprivatephp.php before any `date()` calls.

---

## Verified Clean

- **CSRF:** `csrfCheck()` on transfer (line 26), buy (line 220), sell (line 359) — clean.
- **Auth:** All operations use `$_SESSION['login']` exclusively; session validated pre-game — clean.
- **Price manipulation / TOCTOU:** `cours` row re-read with `FOR UPDATE` inside `withTransaction()` on both buy and sell — clean.
- **Storage cap bypass:** Depot level re-fetched with `FOR UPDATE` inside tx; buy verifies new total ≤ cap; sell caps energy to available space — clean.
- **Negative resources:** Transfer checks sender balance; buy verifies energy ≥ cost; sell caps `actualSold` to `$locked['res']` — clean.
- **Transaction safety:** All 3 operations (transfer, buy, sell) wrapped in `withTransaction()`; deadlock retry (3 attempts) — clean.
- **Input validation:** `intval(transformInt(...))` + regex on quantities; cap at 10M before intval (float overflow prevention); resource type whitelisted against `$nomsRes` — clean.
- **XSS in chart data:** `floatval()` sanitization on CSV price history (prevents stored XSS) — clean.
- **Rate limiting:** Unified `'market_op'` key for buy+sell; separate limit for transfer — clean.
- **Self-transfer guard:** `$_POST['destinataire'] === $_SESSION['login']` rejected — clean.
- **Multi-account block:** `areFlaggedAccounts()` check before transfer — clean.
