# Ultra Audit Pass 9 — Fix Batch 7

Date: 2026-03-08
Files modified: includes/compounds.php, laboratoire.php
Lint results: PASS (both files, zero syntax errors)

---

## P9-MED-011 — Dynamic column interpolation in compounds.php deduction loop

**File:** includes/compounds.php, second `foreach ($recipe ...)` loop (~line 99)

**Fix:** Added an explicit `$allowedCols` whitelist check (`['C','N','H','O','Cl','S','Br','I']`)
with `in_array(..., true)` immediately before the column is interpolated into the UPDATE SQL.
A `RuntimeException('Invalid resource: ...')` is thrown if the key is not in the list.

**Why belt-and-suspenders:** The first `foreach` (validation loop) already checks against
`$nomsRes`, but that guard could in theory be bypassed if a tampered `$COMPOUNDS` config
contained a key that slipped through or if the two loops diverged in a future refactor.
The inner guard in the deduction loop makes the SQL-injection prevention unconditional.

---

## P9-MED-012 — countStoredCompounds() FOR UPDATE outside transaction

**File:** includes/compounds.php, `countStoredCompounds()` docblock

**Fix:** Added a `@note` paragraph to the function docblock stating that it MUST be called
inside a `withTransaction()` block, explaining that FOR UPDATE is a no-op outside an active
transaction and that calling it unguarded creates a TOCTOU race between count and INSERT.

The existing call site in `synthesizeCompound()` is already correctly wrapped in
`withTransaction()` — this note guards against future callers being unaware of the contract.

---

## P9-MED-013 — No rate limiting on synthesis/activation

**File:** laboratoire.php, synthesis POST handler (lines 7-16 → now 7-22)

**Fix:** After `csrfCheck()`, require `includes/rate_limiter.php` and call
`rateLimitCheck($_SESSION['login'], 'lab_synthesis', 5, 60)`.
If the check fails the synthesis logic is skipped and `$erreur` is set to a French
user-visible message. The existing synthesis logic is wrapped in the `else` branch,
so it only executes when the rate limit is satisfied.

**Scope note:** The activation POST (`$_POST['activate']`) was not listed in the finding
and was left unchanged to minimise diff. It can be rate-limited in a future pass if needed.

---

## P9-MED-014 — 86400 hardcoded in GC cleanup

**File:** includes/compounds.php, `cleanupExpiredCompounds()` (~line 245)

**Fix:** Replaced bare `86400` with the named constant `SECONDS_PER_DAY`, which is defined
in `includes/config.php` (line 35: `define('SECONDS_PER_DAY', 86400)`). The inline comment
was preserved and annotated with the finding ID for traceability.

---

## Verification

```
php -l includes/compounds.php  =>  No syntax errors detected
php -l laboratoire.php         =>  No syntax errors detected
```

All four findings resolved. No logic changes, no new dependencies beyond those already
available in the codebase (config.php, rate_limiter.php).
