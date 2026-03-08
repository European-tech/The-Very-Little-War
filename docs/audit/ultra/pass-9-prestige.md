# Pass 9 — Prestige & PP Economy Audit

**Scope:** prestige.php (root), includes/prestige.php, includes/player.php (updateLoginStreak,
performSeasonEnd, awardPrestigePoints), includes/basicprivatephp.php (streak trigger),
migrations/0007/0027/0075, includes/config.php (PP constants).

**Date:** 2026-03-08

---

## Findings

### PRES-P9-001 — INFO — awardPrestigePoints: no per-season idempotency stamp

**File:** `includes/prestige.php:110–157` / `includes/player.php:1153`

**Description:**
`awardPrestigePoints()` has no record of which season it last ran.  It relies entirely on
the `GET_LOCK('tvlw_season_reset', 0)` advisory lock inside `performSeasonEnd()` to prevent
a double-award.  If an operator calls `awardPrestigePoints()` manually from a cron or admin
script (outside `performSeasonEnd()`), or if `performSeasonEnd()` fails after calling
`awardPrestigePoints()` but before `remiseAZero()` and is then re-triggered by the admin,
the function will award PP a second time with no DB-level guard to stop it.  The lock is
connection-scoped and released in the `finally` block of `performSeasonEnd()`, so a second
call from any other connection (or after the first connection drops) will succeed.

**Severity:** INFO (requires privileged access path to exploit)

**Recommended fix:** Add a `prestige_season_awarded INT DEFAULT 0` counter column (or
timestamp) to the `statistiques` table and `INSERT … ON DUPLICATE KEY UPDATE` logic in
`awardPrestigePoints()` so it is a no-op if already run for the current season number.

---

### PRES-P9-002 — INFO — migration 0075 idempotency guard uses wrong information_schema column

**File:** `migrations/0075_prestige_total_pp_unsigned.sql:3–12`

**Description:**
The migration reads `DATA_TYPE` from `information_schema.COLUMNS` to decide whether to skip
the `ALTER TABLE`.  In MariaDB/MySQL, `DATA_TYPE` for both `INT` and `INT UNSIGNED` is
`'int'` — the signed/unsigned distinction lives in `COLUMN_TYPE` (`'int(10) unsigned'`).
Therefore the guard condition `IF(@col_type = 'int', ALTER ..., 'already modified')` is
always TRUE even after the column has already been made UNSIGNED, causing the `ALTER TABLE`
to re-execute on every migration run.  The ALTER is idempotent in effect (no data loss), so
this is not a correctness bug, but it adds unnecessary DDL overhead and misleadingly suggests
the guard works.

**Severity:** INFO

**Recommended fix:** Change the guard to check `COLUMN_TYPE` (value `'int unsigned'`) instead
of `DATA_TYPE` so the migration skips cleanly when already applied.

---

### PRES-P9-003 — LOW — no cap on total_pp accumulation (PP overflow not a real risk, but undocumented)

**File:** `includes/prestige.php:154, 220` / `includes/player.php:1382`

**Description:**
`total_pp` is `INT UNSIGNED` (max 4,294,967,295).  At the theoretical maximum earning rate
of ~220 PP/season it would take approximately 19.5 million seasons to overflow — no practical
risk.  However there is no application-level cap, no balance rationale documented for leaving
accumulation uncapped, and no comment explaining why a cap was deliberately omitted.  Future
changes (e.g., awarding bulk PP for special events) could increase the rate without review.
This is a design documentation gap rather than an exploitable vulnerability.

**Severity:** LOW

**Recommended fix:** Add a comment in config.php and the `purchasePrestigeUnlock` /
`awardPrestigePoints` functions documenting that no PP cap is intentional, and note the
maximum earn rate so future balance changes are made with awareness of the accumulation ceiling.

---

### PRES-P9-004 — LOW — PRES-P8-002 design gap confirmed: ~25 non-milestone PP/season undocumented in player guide

**File:** `includes/player.php:1376–1379`

**Description:**
The `updateLoginStreak()` function awards `STREAK_REWARD_DAY_1` (1 PP) on every non-milestone
streak day (i.e., days that are not 1, 3, 7, 14, 21, or 28).  Over a 31-day season this
produces 25 additional PP for a fully active player — 30% of total possible streak PP (83 PP)
and ~11% of the theoretical maximum per season (220 PP).  The code comment at line 1377 says
this is "intentional — small reward for consistency", and `basicprivatephp.php` comment at
line 149 references PRES-P7-002 acknowledging the design.

However, the player-facing documentation (`docs/game/10-PLAYER-GUIDE.md` and `regles.php`)
only lists the six milestone values; the 1 PP/day baseline for non-milestone days is not
mentioned.  Players cannot accurately forecast their PP earnings per season, and the gap
between documented and actual earnings could be perceived as a hidden inflation mechanism
during future balance reviews.

**Severity:** LOW (design gap / docs issue, not a security vulnerability)

**Recommended fix:** Add one sentence to `regles.php` and the player guide stating that every
login also earns +1 PP regardless of streak milestone, clarifying this is the floor reward.

---

### PRES-P9-005 — INFO — PP award atomicity (awardPrestigePoints): VERIFIED CLEAN

**File:** `includes/prestige.php:150–157`

**Description:**
`awardPrestigePoints()` correctly wraps all `INSERT … ON DUPLICATE KEY UPDATE total_pp =
total_pp + ?` statements for all players in a single `withTransaction()` call (MEDIUM-014
fix).  All-or-nothing semantics are preserved; a mid-loop failure will roll back the entire
award batch.

**Verdict:** No finding — implementation is correct.

---

### PRES-P9-006 — INFO — double daily PP claim prevention: VERIFIED CLEAN

**File:** `includes/player.php:1346–1357`

**Description:**
`updateLoginStreak()` uses `FOR UPDATE` row-locking inside a `withTransaction()` closure.
The early-return guard `if ($lastDate === $today) { return; }` is evaluated after the lock
is acquired, preventing two simultaneous page loads from both awarding PP on the same
calendar day.  The session variable `$_SESSION['streak_pp_today']` is only updated when
`pp_earned > 0` (see `basicprivatephp.php:156–165`), so the display is also consistent.

**Verdict:** No finding — double-award is properly prevented.

---

### PRES-P9-007 — INFO — CSRF on PP spending: VERIFIED CLEAN

**File:** `prestige.php:8` / `prestige.php:153–159`

**Description:**
The purchase POST handler calls `csrfCheck()` before any processing (line 8).  The shop form
injects `csrfField()` (line 154) and submits to `prestige.php` via POST.  Every purchase
button is wrapped in its own `<form>` with a fresh CSRF token.

**Verdict:** No finding — CSRF protection is correctly applied.

---

### PRES-P9-008 — INFO — SQL injection in prestige queries: VERIFIED CLEAN

**File:** `includes/prestige.php:56,67,88,154,165,196,220`

**Description:**
All six user-controlled parameters (login, unlockKey, PP amount) are passed via prepared-
statement placeholders (`?`) with correct type strings (`s`, `i`, `si`).  The only string
concatenation in a query is `INACTIVE_PLAYER_X` at line 116, which is a `define()`'d
integer constant (`-1000`) — not user-controlled.

**Verdict:** No finding — all queries are parameterized.

---

### PRES-P9-009 — INFO — authorization (player A spending player B's PP): VERIFIED CLEAN

**File:** `prestige.php:9` / `includes/prestige.php:184–230`

**Description:**
`purchasePrestigeUnlock()` receives `$_SESSION['login']` (not a user-supplied parameter)
as the `$login` argument.  The `FOR UPDATE` lock and `WHERE login=?` / `WHERE login=? AND
total_pp >= ?` clauses ensure the operation is scoped to the authenticated player.  No
player-supplied login parameter exists in the purchase flow.

**Verdict:** No finding — authorization is correctly enforced via session.

---

### PRES-P9-010 — INFO — streak manipulation via streak_last_date: VERIFIED CLEAN

**File:** `includes/player.php:1346–1368`

**Description:**
`streak_last_date` is a server-side `DATE` column; it is never populated from user input.
The streak update runs inside a `withTransaction … FOR UPDATE` block so concurrent
manipulation is blocked.  A player cannot submit a past date to claim a streak they did not
earn.  The timezone is pinned to `Europe/Paris` via `DateTimeZone`, so DST transitions do
not create exploitable windows.

**Verdict:** No finding — streak is not manipulable by users.

---

### PRES-P9-011 — INFO — UNSIGNED constraint on total_pp preventing negatives: VERIFIED CLEAN (with caveat)

**File:** `migrations/0075_prestige_total_pp_unsigned.sql` / `includes/prestige.php:219`

**Description:**
Migration 0075 converts `total_pp` to `INT UNSIGNED`, providing a DB-level floor of 0.  The
application also uses `GREATEST(0, total_pp - ?)` in the UPDATE (line 219) and the `WHERE
total_pp >= ?` guard ensures the deduction only fires when sufficient PP are available.
These three layers together prevent negative balances.

Caveat: see PRES-P9-002 regarding the migration's idempotency guard using the wrong
`information_schema` column, though this does not affect the correctness of the UNSIGNED
constraint once applied.

**Verdict:** No finding for negative balances — constraint is effective.

---

### PRES-P9-012 — INFO — PP shop cost validated server-side: VERIFIED CLEAN

**File:** `includes/prestige.php:187,191,210`

**Description:**
`purchasePrestigeUnlock()` validates `$unlockKey` against the server-side `$PRESTIGE_UNLOCKS`
array (line 187) and reads `$unlock['cost']` from that authoritative array — never from
`$_POST`.  A client cannot submit a forged cost.  The update's `WHERE total_pp >= ?` clause
uses the server-side cost value, providing a second enforcement layer.

**Verdict:** No finding — costs are enforced server-side.

---

## Summary Table

| ID | Severity | Area | Status |
|----|----------|------|--------|
| PRES-P9-001 | INFO | awardPrestigePoints double-season guard | New finding |
| PRES-P9-002 | INFO | Migration 0075 idempotency check uses wrong column | New finding |
| PRES-P9-003 | LOW | No PP cap — undocumented design decision | New finding |
| PRES-P9-004 | LOW | Non-milestone 25 PP/season undocumented in player guide | PRES-P8-002 carry |
| PRES-P9-005 | INFO | PP award atomicity | CLEAN |
| PRES-P9-006 | INFO | Double daily PP claim | CLEAN |
| PRES-P9-007 | INFO | CSRF on purchase | CLEAN |
| PRES-P9-008 | INFO | SQL injection | CLEAN |
| PRES-P9-009 | INFO | Authorization (cross-player spend) | CLEAN |
| PRES-P9-010 | INFO | Streak manipulation | CLEAN |
| PRES-P9-011 | INFO | UNSIGNED floor prevents negatives | CLEAN (see P9-002) |
| PRES-P9-012 | INFO | Shop costs validated server-side | CLEAN |

---

FINDINGS: 0 critical, 0 high, 0 medium, 2 low, 2 info (actionable), 8 info (clean verifications)
