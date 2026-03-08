# Pass 7 Audit — GAME_CORE Domain
**Date:** 2026-03-08
**Agent:** Pass7-C3-GAME_CORE

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 3 |
| INFO | 1 |
| **Total** | **6** |

---

## MEDIUM Findings

### MEDIUM-001 — voter.php poll results endpoint missing (P9-CRIT-001 deferred TODO)
**File:** `voter.php:60`
**Description:** The voter.php POST handler exists but no GET endpoint returns poll results. A code comment marks this as TODO. Players cannot verify their vote was recorded, and results are only accessible via DB query. The TODO has been present since Pass 9 (P9-CRIT-001 deferred).
**Fix:** Implement a GET endpoint returning aggregated vote counts per option (not individual votes):
```php
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $results = dbFetchAll($base, 'SELECT option_id, COUNT(*) as votes FROM voter_log WHERE date=CURDATE() GROUP BY option_id');
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}
```

### MEDIUM-002 — sinstruire.php JavaScript navigation uses GET without CSRF pattern
**File:** `sinstruire.php:308-314`
**Description:** The course navigator uses JavaScript `document.location` redirect (GET) without CSRF token. While GET-only navigation is low risk, the pattern is inconsistent with the project's CSRF discipline. No immediate vulnerability, but a future change to POST could introduce a gap.
**Fix:** Document explicitly in a comment that course selection is intentionally GET-only and safe; add a note to the future-change checklist.

---

## LOW Findings

### LOW-001 — medailles.php player lookup enables timing-based login enumeration
**File:** `medailles.php:20-26`
**Description:** `$_GET['login']` is accepted to look up medals for any player. No rate limiting on this endpoint. An attacker could enumerate valid logins by timing response differences between found/not-found players.
**Fix:** Add `rateLimitCheck('medals_lookup', $_SERVER['REMOTE_ADDR'], 30, 60)` to throttle enumeration attempts.

### LOW-002 — bilan.php hardcodes molecule class count (4) instead of MAX_MOLECULE_CLASSES
**File:** `bilan.php:22-25, 639-652`
**Description:** Loops use `for ($i = 1; $i <= 4; $i++)` instead of `MAX_MOLECULE_CLASSES`. If the config changes, the loops silently skip or miss classes.
**Fix:** Use `for ($i = 1; $i <= MAX_MOLECULE_CLASSES; $i++)`.

### LOW-003 — vacance.php provides no early-exit mechanism
**File:** `vacance.php`
**Description:** Players have no way to exit vacation mode early — they must wait until the scheduled end date. This is likely by design but is not documented. Verify with game design intent.
**Fix:** Confirm and document as intentional. If early exit is desired, add a "Quitter les vacances" button with appropriate cooldown.

---

## INFO

### INFO-001 — Tutorial mission sequential logic verified correct
**File:** `tutoriel.php:199-309`
**Description:** All 7 missions include `verify_db` callables that re-check DB state inside the transaction. Double-claim prevented by FOR UPDATE + atomic missions field update. LEAST() enforces energy cap. All correct.

---

## Verified Clean

- **tutoriel.php:** CSRF (`csrfCheck()` line 196), FOR UPDATE preventing double-claim (line 232), prerequisites re-verified inside transaction, energy capped by `LEAST()`, all 7 missions have callable DB verification — clean.
- **medailles.php:** XSS (`htmlspecialchars()` on player name line 44, medal descriptions line 96), numeric vote counts — clean.
- **voter.php:** Session token `hash_equals()` check (line 14), CSRF (line 22), POST-only, FOR UPDATE (line 50), transactional `INSERT IGNORE` for double-vote prevention (line 52), option bounds validated (lines 39-41) — clean.
- **bilan.php:** CSRF (line 52), specialization column whitelist (line 59), specialization validated against config (line 71), all numeric outputs via `fmtNum()`, all text escaped with `htmlspecialchars()` — clean.
- **compte.php:** CSRF (line 6), vacation activation active-combat check (line 24) + FOR UPDATE (line 30), password change requires current password + bcrypt + session regen (line 80), email change requires password + dedup check, profile image MIME/extension/dimension/size/random-filename validation — clean.
- **sinstruire.php:** Course ID bounds-clamped to `[0, count-1]`, all output escaped, CSP script tag used — clean.
