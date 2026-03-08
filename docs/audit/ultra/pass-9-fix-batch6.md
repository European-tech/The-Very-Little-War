# Pass 9 Fix Batch 6 — Audit Remediation

Date: 2026-03-08
Agent: PHP Fix Agent

## Findings Fixed

---

### P9-MED-010: Stale max-level snapshot in traitementConstructions()

**File:** `constructions.php` — `traitementConstructions()` function

**Problem:** Inside the transaction, the fallback for computing the current building level used the outer-scope `$constructions[$liste['bdd']]` snapshot captured before the transaction began. A concurrent request could increment the building level between the snapshot and the transaction, allowing the level cap check to be bypassed.

Additionally, `$liste['bdd']` was used as an array key without a whitelist assertion, leaving the code open to unexpected keys if the call graph changed.

**Fix:**
- Added a `$validBuildingCols` whitelist array (`['generateur', 'producteur', 'depot', 'champdeforce', 'ionisateur', 'condenseur', 'lieur', 'stabilisateur', 'coffrefort']`) with an `in_array(..., true)` assertion before using `$liste['bdd']` as a column reference.
- Added a `SELECT $liste['bdd'] AS niveau FROM constructions WHERE login=? FOR UPDATE` query inside the transaction to re-fetch the live building level with a row lock.
- The `$currentLevel` value from this locked fetch replaces the outer-scope snapshot as the fallback when no queued construction action exists.

**Location:** `constructions.php` lines 303–317

---

### P9-LOW-009: Ionisateur HP bar not shown

**File:** `includes/player.php` — `$listeConstructions` array, `ionisateur` entry

**Problem:** The ionisateur entry had `'progressBar' => false`, so the HP bar overlay was never rendered in `constructions.php` (which checks `array_key_exists("progressBar", $liste) && $liste['progressBar']`). The ionisateur is damageable in combat (tracked via `vieIonisateur` column) but its HP state was invisible to players.

Additionally the `vie` and `vieMax` keys were missing from the entry, which would cause a PHP undefined-index notice if `progressBar` were ever enabled without them.

**Fix:**
- Changed `'progressBar' => false` to `'progressBar' => true` for the ionisateur entry.
- Added `'vie' => $constructions['vieIonisateur']` and `'vieMax' => vieIonisateur($constructions['ionisateur'])` to the ionisateur entry, consistent with the generateur, producteur, depot, and champdeforce entries.

**Location:** `includes/player.php` lines 485–494

---

### P9-LOW-010: JS countdown embeds DB int without explicit cast

**File:** `constructions.php` — building finish-time countdown JS block

**Problem:** The PHP expression `($actionsconstruction['fin'] - time())` was written directly into a JavaScript variable initializer without an explicit integer cast. Although both operands are integers, `$actionsconstruction['fin']` comes from a DB result set and is typed as a string in PHP. Without the cast, an unexpected string value (e.g. if the DB driver returns something unexpected) could inject unquoted content into the JS block, creating an XSS vector.

**Fix:**
- Wrapped the expression with `(int)`: `(int)($actionsconstruction['fin'] - time())`.

**Location:** `constructions.php` line 379

---

## Lint Results

```
No syntax errors detected in constructions.php
No syntax errors detected in includes/player.php
```

## Files Modified

- `constructions.php` — P9-MED-010 (whitelist + FOR UPDATE re-fetch) and P9-LOW-010 (int cast)
- `includes/player.php` — P9-LOW-009 (progressBar + vie/vieMax for ionisateur)
