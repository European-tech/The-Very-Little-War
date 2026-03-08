# Pass 7 Prestige/Balance Audit

**Date:** 2026-03-08
**Scope:** Prestige system integrity, streak system, comeback bonus, daily login replay protection, PP display consistency, medal threshold achievability.
**Auditor:** Autonomous agent (Pass 7)

---

## Findings

### PRES-P7-001 [MEDIUM] — Constructeur medal tiers 7 & 8 permanently unachievable

**File:** `includes/config.php:51` (MAX_BUILDING_LEVEL), `includes/config.php:558` (MEDAL_THRESHOLDS_CONSTRUCTEUR)

**Proof:**
```php
define('MAX_BUILDING_LEVEL', 50); // Hard cap on building upgrades
$MEDAL_THRESHOLDS_CONSTRUCTEUR = [5, 10, 15, 25, 35, 50, 70, 100];
```

Tier 7 requires `batMax >= 70` and tier 8 requires `batMax >= 100`, but `MAX_BUILDING_LEVEL = 50` is enforced as a hard cap in `augmenterBatiment()`. No player can ever exceed level 50 in any building. The `batMax()` function in `includes/player.php:835` returns the highest building level across all buildings, which is bounded at 50. Both the Constructeur medal tiers 7 and 8 (and their associated PP in `calculatePrestigePoints`) are permanently unreachable.

**Impact:** Players are shown tiers they can never reach, creating misleading UI. The PP ceiling from Constructeur is 6 instead of the implied 8.

**Fix:** Lower tiers 7 and 8 of `$MEDAL_THRESHOLDS_CONSTRUCTEUR` to values within reach of MAX_BUILDING_LEVEL:
```php
$MEDAL_THRESHOLDS_CONSTRUCTEUR = [5, 10, 15, 25, 35, 50, 50, 50];
// Or, more cleanly, only 6 tiers:
$MEDAL_THRESHOLDS_CONSTRUCTEUR = [5, 10, 15, 25, 35, 50];
// (array must be padded to 8 if medal display code expects 8 tiers)
```
Alternatively, raise MAX_BUILDING_LEVEL to 100, but that would require significant balance review.

---

### PRES-P7-002 [LOW] — `streak_pp_today` session variable overwritten to 0 on subsequent page loads

**File:** `includes/basicprivatephp.php:153`

**Proof:**
```php
$_SESSION['streak_pp_today'] = $streakResult['pp_earned'] ?? 0;
```

`updateLoginStreak()` returns `pp_earned=0` on every page load after the first of the day (the DB guard `$lastDate === $today` returns early). On the second (or any later) page load within the same session, `$_SESSION['streak_pp_today']` is unconditionally overwritten to `0`. The comment even documents this behavior.

Consequence: if a user visits `index.php` first (earning 1 PP and setting the session to 1), then navigates to `prestige.php`, the "PP de connexion aujourd'hui" widget displays 0 instead of the actual PP earned. The PP is correctly awarded to the DB — only the display is wrong.

**Impact:** UX inaccuracy. Players who do not visit prestige.php as their very first page each day will see "0 PP earned today" even on a day they did earn streak PP. No integrity issue.

**Fix:** Only update the session variable when the new value is non-zero:
```php
// In basicprivatephp.php, replace:
$_SESSION['streak_pp_today'] = $streakResult['pp_earned'] ?? 0;

// With:
if (($streakResult['pp_earned'] ?? 0) > 0) {
    $_SESSION['streak_pp_today'] = $streakResult['pp_earned'];
} elseif (!isset($_SESSION['streak_pp_today'])) {
    $_SESSION['streak_pp_today'] = 0;
}
```
This preserves the earned value for the rest of the session day.

---

### PRES-P7-003 [LOW] — `prestige.total_pp` column allows negative values (no DB floor)

**File:** `migrations/0007_add_prestige_table.sql:4`

**Proof:**
```sql
total_pp INT DEFAULT 0,
```

The column is signed `INT` with no `CHECK (total_pp >= 0)` constraint and no `UNSIGNED` modifier. The application-level guard in `purchasePrestigeUnlock()` (lines 210-213, 218) prevents negative values via `WHERE total_pp >= ?` on the UPDATE, but the DB column itself has no enforcement.

**Scenarios where negative is possible:**
- Direct DB manipulation (admin error, SQL injection via a hypothetical future vulnerability)
- A bug introduced in future code that doesn't replicate the WHERE guard

**Impact:** If `total_pp` goes negative, `prestige.php` displays a negative PP balance (e.g., "-25 PP"), and no floor clamping exists in `getPrestige()` or the display code. Players could potentially be permanently locked out of all shop items.

**Fix:** Add a CHECK constraint or make the column UNSIGNED:
```sql
ALTER TABLE prestige MODIFY total_pp INT UNSIGNED DEFAULT 0;
-- or:
ALTER TABLE prestige ADD CONSTRAINT chk_prestige_total_pp CHECK (total_pp >= 0);
```
Add as migration `0073_prestige_total_pp_unsigned.sql`.

---

## Items Verified CLEAN

### PP negativity via purchase (CLEAN)
`purchasePrestigeUnlock()` (`includes/prestige.php:184-228`) uses `FOR UPDATE` row lock + atomic `WHERE total_pp >= ?` guard on the UPDATE statement. No TOCTOU window. Double-spending is impossible — the `in_array($unlockKey, $unlocks)` check within the locked transaction prevents re-purchasing the same perk. PP cannot go negative via any in-game purchase path.

### Streak increment atomicity (CLEAN)
`updateLoginStreak()` (`includes/player.php:1324-1374`) wraps the full read-modify-write in `withTransaction()` with a `FOR UPDATE` lock on the `autre` row. The `streak_last_date === $today` early-return is inside the transaction. Concurrent requests cannot double-increment the streak or double-award PP. Clock skew: the function uses `DateTimeZone('Europe/Paris')` consistently for both `$today` and `$yesterday`, matching `config.php`'s `date_default_timezone_set('Europe/Paris')`. No skew window.

### Streak milestone double-award (CLEAN)
The PP calculation uses a correct `if/elseif` structure (`player.php:1358-1364`): milestone days go into the `if` branch only; non-milestone days get the base `STREAK_REWARD_DAY_1` (1 PP) via `elseif`. No day awards both milestone PP and base PP simultaneously.

### Comeback bonus replay (CLEAN)
`checkComebackBonus()` (`includes/player.php:1381-1450`) uses `FOR UPDATE` on the `autre` row and checks `($now - $lastCatchUp) > COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY`. The session flag `comeback_checked` (basicprivatephp.php:160-166) prevents per-page-load overhead, but the actual DB transaction guard is the authoritative protection. Even without the session flag (e.g., after session regeneration), the 7-day DB-level cooldown prevents double-award. `last_catch_up` is updated atomically within the same transaction that grants resources.

### Daily login bonus replay (CLEAN)
`updateLoginStreak()` guards against same-day replay via `$lastDate === $today` inside a `FOR UPDATE` transaction. The check uses the DB column `streak_last_date` as the authoritative source, not session state. A player who opens multiple tabs simultaneously cannot trigger double PP awards.

### Prestige PP display consistency (CLEAN)
`classement.php` (lines 115-117, 228-231) loads `total_pp` from the `prestige` table via `SELECT login, total_pp FROM prestige` and stores in a local cache. `prestige.php` (line 18-19) loads via `getPrestige()` which also reads `total_pp` from the same table column. Both display the same DB value. The classement page correctly defaults to 0 for players with no prestige row via `isset($prestigeCache[$login]) ? ... : 0`.

### Prestige unlock persistence (CLEAN)
Unlocks are stored as a comma-separated string in `prestige.unlocks`. The `purchasePrestigeUnlock()` function reads, modifies, and writes the unlocks field within a single `FOR UPDATE` transaction. `hasPrestigeUnlock()` parses the same column. No race condition between reading and updating the unlock list is possible.

### `prevConnexion` correctness for comeback (CLEAN)
`basicprivatephp.php:105-106` captures `derniereConnexion` before line 112 overwrites it. The correct "previous last login" is passed to `checkComebackBonus()` at line 161. The absence duration is therefore calculated correctly.

---

## Medal Threshold Achievability Summary

| Medal | Top Tier Threshold | Achievable in 31-day season? | Notes |
|-------|-------------------|------------------------------|-------|
| Terreur | 1000 attacks | Theoretically (32+/day) | Border-line; top players possible |
| Attaque | 30000 pts | Aspirational (Red Diamond) | Intended as hard goal |
| Defense | 30000 pts | Aspirational | Same as Attaque |
| Pillage | 10M resources | Possibly achievable for dedicated raiders | |
| Pipelette | 5000 messages | Very unlikely (161/day) | Effectively unreachable |
| Pertes | 1M molecules | Possibly via mass combat | |
| Energievore | 1B energy | **Unreachable** with ECO caps | Tier 8 = 1,000,000,000; tier 7 = 10M is border-line |
| Constructeur | Level 70/100 | **Unreachable** (MAX=50) | **PRES-P7-001** |
| Bombe | 12 buildings destroyed | Achievable | |
| Bombe | 8 buildings destroyed | Achievable | |

Note on Energievore tier 8 (1,000,000,000): with exponential building costs capped at level 50, a reasonable upper bound for total energy spent in a season is in the tens of millions. Tier 8 is effectively unreachable; this is LOW severity since it's an aspirational/stretch goal by design. Tier 7 (10,000,000) is achievable by dedicated builders.

---

## Summary

Three findings, all non-critical:

| ID | Severity | System | Description |
|----|----------|--------|-------------|
| PRES-P7-001 | MEDIUM | Balance | Constructeur medal tiers 7-8 unreachable (level 70/100 > MAX_BUILDING_LEVEL=50) |
| PRES-P7-002 | LOW | UX | streak_pp_today session overwritten to 0 on page 2+ of same day |
| PRES-P7-003 | LOW | DB Integrity | prestige.total_pp is signed INT with no floor constraint |

**No CRITICAL or HIGH findings.**

The prestige shop purchase path, streak increment, comeback cooldown, daily login replay protection, and PP display consistency are all correctly implemented and protected against double-spend/replay attacks.
