# Ultra Audit Pass 9 — Fix Batch 14: Cross-cutting INFO Cleanups

**Date:** 2026-03-08
**Agent:** Fix agent (Sonnet 4.6)
**Fixes applied:** 6/6

---

## P9-INFO-003: player.php — Season reset audit log events

**File read:** `includes/player.php` (lines 1023–1200 read before editing)
**Status:** APPLIED

Two `logInfo` calls added to `performSeasonEnd()`:

1. **Start log** — inserted immediately after lock acquisition and before the `try {` block (line ~1037):
   ```php
   logInfo('SEASON', 'Season reset started', ['trigger' => 'admin/auto', 'timestamp' => time()]);
   ```

2. **Completion log** — inserted just before `return $vainqueurManche;` at the end of the function body:
   ```php
   logInfo('SEASON', 'Season reset completed', ['winner' => $vainqueurManche]);
   ```

These two calls bracket the entire reset operation and provide audit trail visibility for both successful and (via exception omission) failed resets.

---

## P9-INFO-004: prestige.php — awardPrestigePoints idempotency guard

**Files read:** `includes/prestige.php` (full), `migrations/` directory (confirmed 0078 is highest existing migration)
**Status:** APPLIED

### Migration created: `migrations/0079_prestige_awarded_season.sql`
```sql
ALTER TABLE statistiques ADD COLUMN IF NOT EXISTS prestige_awarded_season INT DEFAULT 0 NOT NULL;
```

### prestige.php changes to `awardPrestigePoints()`

**At the start of the function**, before reading player rankings:
- Determines current season as `MAX(season_number) + 1` from `season_recap` (consistent with `archiveSeasonData()`)
- Reads `prestige_awarded_season` from `statistiques`
- If `prestige_awarded_season >= currentSeason`, returns immediately (idempotent no-op)

**At the end of the function**, after the awards transaction:
```php
dbExecute($base, 'UPDATE statistiques SET prestige_awarded_season = ?', 'i', $currentSeason);
```

This prevents double-awarding PP if `performSeasonEnd()` is called concurrently (already protected by advisory lock, but belt-and-suspenders) or retried after a partial failure.

---

## P9-INFO-005: multiaccount.php — Remove @ error suppression from mail()

**File read:** `includes/multiaccount.php` (lines 286–297 read before editing)
**Status:** APPLIED

In `sendAdminAlertEmail()`, replaced:
```php
@mail($adminEmail, $subject, $body, $headers);
```
with:
```php
$sent = mail($adminEmail, $subject, $body, $headers);
if (!$sent) {
    logWarn('MULTI_ALERT', 'Admin alert email failed to send', ['subject' => $subject]);
}
```

The `logWarn` function was already imported via `require_once(__DIR__ . '/logger.php')` at the top of the file.

---

## P9-INFO-006: constructions.php — Comment on count-lock serialization

**File read:** `constructions.php` (lines 280–360 read before editing)
**Status:** APPLIED

Added comment immediately before the `INSERT INTO actionsconstruction` statement (line ~349):
```php
// Note: no FOR UPDATE needed here — the COUNT(*) FOR UPDATE above already
// serializes concurrent queue insertions for this player.
```

The `COUNT(*) ... FOR UPDATE` on `actionsconstruction` at line 285 acquires a range lock on the player's queue rows, preventing concurrent sessions from inserting past the 2-slot limit. The comment clarifies why no additional lock is needed at INSERT time.

---

## P9-INFO-007: player.php — MIME boundary uses random_bytes instead of md5

**File read:** `includes/player.php` (grep + lines 1233–1265 read before editing)
**Status:** APPLIED

Replaced at line 1245 (in the email queue flush function):
```php
// Before:
$boundary = "-----=" . md5((string)$id . (string)time());
// After:
$boundary = "-----=" . bin2hex(random_bytes(8));
```

`random_bytes(8)` produces 8 bytes (64 bits) of CSPRNG output; `bin2hex` encodes it as 16 hex characters. This is cryptographically random and unpredictable, unlike `md5(id . time())` which is deterministic from observable values. MIME boundaries do not need to be secret, but using a PRNG removes the theoretical predictability concern.

---

## P9-INFO-008: config.php and player.php — Starting resource constants

**Files read:** `includes/config.php` (REGISTRATION section, line 578), `includes/player.php` (`inscrire()` function, lines 36–144), DB schema (`tests/integration/fixtures/base_schema.sql` for ressources defaults)
**Status:** APPLIED (constants added; PHP code already uses DB defaults — no PHP hardcodes to replace)

### config.php — added to REGISTRATION / NEW PLAYER section:
```php
define('STARTING_ENERGY', 64);
define('STARTING_ATOMS', 64);
define('STARTING_REVENUE_ENERGY', 12);
define('STARTING_REVENUE_ATOMS', 9);
```

### player.php — inscrire() ressources INSERT:
The `inscrire()` function uses `INSERT INTO ressources (login) VALUES (?)` which relies entirely on MariaDB column DEFAULT values (confirmed in base_schema.sql: `energie DEFAULT 64`, `revenuenergie DEFAULT 12`, `revenuhydrogene/carbone/etc DEFAULT 9`). No PHP-level hardcoded values exist to replace. A clarifying comment was added to the INSERT referencing the config constants:
```php
// Starting values: STARTING_ENERGY (64) energy/atoms, STARTING_REVENUE_ENERGY (12)/STARTING_REVENUE_ATOMS (9) revenues.
// These must match the DEFAULT clauses in the `ressources` table (see config.php constants).
```

The `remiseAZero()` function similarly uses `energie=default` SQL syntax, which correctly defers to the DB default.

**Note:** If the starting values ever need changing, they must be updated in both `config.php` (constants) AND the DB schema defaults (via a migration). The constants serve as the canonical documentation reference.

---

## Summary

| Fix | File(s) | Action |
|-----|---------|--------|
| P9-INFO-003 | includes/player.php | Added 2 logInfo calls bracketing performSeasonEnd() |
| P9-INFO-004 | includes/prestige.php + migrations/0079 | Idempotency guard in awardPrestigePoints() + migration |
| P9-INFO-005 | includes/multiaccount.php | Removed @mail, added failure logging |
| P9-INFO-006 | constructions.php | Added comment explaining COUNT FOR UPDATE serialization |
| P9-INFO-007 | includes/player.php | md5() boundary → random_bytes(8) |
| P9-INFO-008 | includes/config.php + includes/player.php | Added STARTING_* constants, clarifying comment in inscrire() |

All files were read before editing. Zero fixes skipped.
