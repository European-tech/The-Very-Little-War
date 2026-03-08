# Pass 9 Fix Batch 13 — Market + API Residual

**Date:** 2026-03-08
**Files modified:** `marche.php`, `api.php`
**Status:** ALL FIXES APPLIED

---

## Summary

Five targeted fixes addressing stored XSS in the market chart, a stale volatility
snapshot inside buy/sell transactions, missing length assertions on `cours` CSV
slices, and two informational comments in the API dispatch layer.

---

## Fixes Applied

### P9-MED-029 — marche.php: `tableauCours` raw value in JS chart (stored XSS)

**File read:** Yes (`marche.php`, full file)
**Location:** Chart generation loop, inside the `foreach ($coursRows as $cours)` block
(line ~759 after edits).

**Problem:** `$cours['tableauCours']` was embedded verbatim into a JS array literal
(`'["' . date(...) . '",' . $cours['tableauCours'] . ']'`). A malicious value stored
in the `cours` table could inject arbitrary JS tokens.

**Fix applied:** Each iteration now passes the stored CSV through
`array_map('floatval', explode(',', ...))` → `implode(',', ...)` before embedding,
guaranteeing only IEEE 754 float strings reach the JS context.

```php
// P9-MED-029: Sanitize stored CSV — cast each token to float to prevent stored XSS
$vals = array_map('floatval', explode(',', $cours['tableauCours']));
$safeVals = implode(',', $vals);
$tot = '["' . date('d/m H\hi', $cours['timestamp']) . '",' . $safeVals . ']' . $fin . $tot;
```

---

### P9-LOW-028 — marche.php: Stale volatility snapshot inside transaction

**File read:** Yes
**Location:** Buy closure (~line 269–271) and sell closure (~line 411–413).

**Problem:** `$volatilite` was computed once at the top of the script (before either
transaction), using a potentially stale count of active players. Inside the
transaction, after the `cours FOR UPDATE` lock is acquired, the value was still the
outer stale snapshot.

**Fix applied:** Added a fresh `SELECT count(*) AS nbActifs FROM membre WHERE
derniereConnexion >= ?` query and recompute of `$volatilite` inside both the buy and
sell `withTransaction` closures, immediately after the `cours FOR UPDATE` row is
fetched. The outer `$volatilite` captured via `use(...)` is overwritten with the
fresh in-transaction value before it is used in any price calculation.

Applied in two places:
- Buy closure: after `$txTabCours = array_slice(...)` (buy path)
- Sell closure: after `$txTabCours = array_slice(...)` (sell path)

---

### P9-LOW-029 — marche.php: No length assertion on `txTabCours` slice

**File read:** Yes
**Location:** Buy closure (~line 265–268) and sell closure (~line 407–410).

**Problem:** After `array_slice($txTabCours, 0, $nbRes)`, the resulting array could
be shorter than `$nbRes` if the stored `tableauCours` CSV was corrupt or truncated.
Using `$txTabCours[$numRes]` on a shorter array would silently return `null`,
producing a zero-cost trade.

**Fix applied:** Added a strict count assertion immediately after each `array_slice`
call in both closures. A mismatch throws a `\RuntimeException` with a descriptive
message that will be caught by the deadlock-retry handler and logged.

```php
// P9-LOW-029: Assert slice length matches expected resource count
if (count($txTabCours) !== $nbRes) {
    throw new \RuntimeException('corrupt_cours_data: expected ' . $nbRes . ', got ' . count($txTabCours));
}
```

Applied in two places:
- Buy closure: after the buy-path `array_slice`
- Sell closure: after the sell-path `array_slice`

---

### P9-INFO-001 — api.php: CSRF note comment above dispatch table

**File read:** Yes (`api.php`, full file)
**Location:** Line 64–65 (before `$dispatch = [`).

**Fix applied:** Inserted two-line comment block immediately above the dispatch table
declaration:

```php
// IMPORTANT: All handlers in this dispatch table are read-only (formula preview).
// Any future handler that mutates state MUST: (1) verify POST method, (2) call csrfCheck().
```

---

### P9-INFO-002 — api.php: Rate limit bucket comment

**File read:** Yes
**Location:** Line 37 (before `if (!rateLimitCheck($ip, 'api', 60, 60))`).

**Fix applied:** Added inline comment on the line immediately before the rate limit
check call:

```php
// 'api' bucket: formula preview only; market ops are rate-limited in their own pages (marche.php)
rateLimitCheck($ip, 'api', 60, 60);
```

---

## Verification

- Both `array_slice` call sites in `marche.php` now have assertions (buy and sell).
- Both `withTransaction` closures in `marche.php` now recompute `$volatilite` from a
  fresh DB query after the `cours FOR UPDATE` lock is acquired.
- The JS chart loop now produces only float values in the data table.
- `api.php` dispatch table and rate limit call have clarifying comments.
- No functional behavior changed for well-formed data; all changes are either
  sanitization, stricter guards, or documentation.
