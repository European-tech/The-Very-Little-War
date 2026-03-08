# Pass 7 Audit — PRESTIGE Domain
**Date:** 2026-03-08
**Agent:** Pass7-C4-PRESTIGE

## Summary
| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 0 |
| MEDIUM | 0 |
| LOW | 0 |
| INFO | 2 |
| **Total** | **3** |

---

## CRITICAL Findings

### CRITICAL-001 — Incorrect arithmetic in beginner protection duration display
**File:** `attaquer.php:117`
**Description:** The formula for displaying remaining beginner protection time is in the wrong order:
```php
// WRONG (current):
$attackerProtectionLeft = BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock(...) ? SECONDS_PER_DAY : 0) - time() + $attackerTimestamp;
```
This produces: `protection_duration + veteran_bonus - current_time + registration_time`.

Since `$attackerTimestamp` is a large Unix timestamp (years worth of seconds), the result is a huge nonsensical number displayed in the error message.

**Impact:** UX bug — the error message on line 118 shows wildly incorrect remaining protection time. The actual protection **check** at line 116 is mathematically correct (the same terms, just displayed differently). No game logic is broken — only the displayed duration is wrong.

**Fix:**
```php
// CORRECT:
$attackerProtectionLeft = $attackerTimestamp + BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock($_SESSION['login'], 'veteran') ? SECONDS_PER_DAY : 0) - time();
```

---

## INFO Findings

### INFO-001 — Session caching of streak PP is intentional
**File:** `includes/basicprivatephp.php:149-167`
**Description:** Session stores `streak_pp_today` only when `pp_earned > 0`; date stamp prevents stale values across calendar boundaries. Design is correct — not a bug.

### INFO-002 — DB-level GREATEST(0, ...) provides defense-in-depth on PP deduction
**File:** `includes/prestige.php:233-235`
**Description:** `GREATEST(0, total_pp - ?)` at DB level complements the application-level `WHERE total_pp >= ?` balance check. Defense-in-depth for a theoretical race window. Correct and intentional.

---

## Verified Clean

- **CSRF:** `csrfCheck()` at prestige.php:8; `csrfField()` in purchase form at line 154 — clean.
- **Auth:** `basicprivatephp.php` included; all prestige functions use `$_SESSION['login']` — clean.
- **Double-spend prevention:** `FOR UPDATE` on prestige row (prestige.php:211); in-transaction `in_array($unlockKey, $unlocks)` check (line 220); `WHERE total_pp >= ?` guard (line 225) — clean.
- **PP atomicity:** `ON DUPLICATE KEY UPDATE total_pp = total_pp + ?` atomic (prestige.php:166); entire purchase in `withTransaction()` — clean.
- **Login streak atomicity:** `FOR UPDATE` on `autre` row (player.php:1375); transaction wrapper (line 1374); `ON DUPLICATE KEY UPDATE` PP award; idempotent early return if already logged in today (line 1382) — clean.
- **Comeback bonus cooldown:** `FOR UPDATE` lock (player.php:1446); 7-day cooldown enforced atomically (line 1451); session flag `comeback_checked` prevents per-page re-checks (basicprivatephp.php:174) — clean.
- **PP earning idempotency:** Streak deduplication by date; comeback 7-day cooldown; season award `prestige_awarded_season` guard; `calculatePrestigePoints()` is pure read-only — clean.
- **Config constants:** All `PRESTIGE_*`, `STREAK_*`, `COMEBACK_*` constants from config.php — no magic numbers — clean.
- **XSS:** Unlock cost/name/desc all wrapped in `htmlspecialchars()`; CSS class values hardcoded — clean.
- **Veteran unlock enforcement:** All three beginner protection checks (espionage line 35, defense line 114, attacker line 116) include veteran bonus — clean.
