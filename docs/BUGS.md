# Known Bugs

## BUG-1 (CRITICAL): Combat duplicateur uses wrong login

**File:** `combat.php` line ~64

The combat logic fetches the attacker's alliance when calculating the DEFENDER's duplicateur bonus. This means the defender's duplicateur bonus is incorrectly based on the attacker's alliance membership rather than their own.

---

## BUG-2: Inconsistent depot/storage formulas

**Files:** `fonctions.php`, `update.php`, `update1.php`

The storage capacity calculation is inconsistent across the codebase:
- `fonctions.php` uses `500 * level`
- `update.php` / `update1.php` use `4 * pow(4, depot+2)`

These two formulas produce wildly different results, meaning storage limits differ depending on which code path is executed.

---

## BUG-3: Inconsistent molecule decay

**Files:** `fonctions.php`, `update.php`, `update1.php`

Molecule decay is calculated differently depending on where it runs:
- `fonctions.php` uses exponential decay
- `update.php` / `update1.php` use linear per-hour decay

Players experience different decay rates depending on which code path handles the update.

---

## BUG-4: supprimerJoueur() overwrites $modif8 variable

**File:** `fonctions.php`

The `supprimerJoueur()` function overwrites the `$modif8` variable, which causes the rapports (reports) deletion query to be skipped entirely. Deleted players leave orphaned report records in the database.

---

## BUG-5: Missing break in combat.php switch statement

**File:** `combat.php` line ~259

A missing `break` statement in a switch case causes unintended fall-through behavior during combat resolution.

---

## BUG-6: connexion.php uses deprecated mysql_connect()

**File:** `includes/connexion.php`

The database connection file uses the deprecated `mysql_connect()` function, but the rest of the codebase uses `mysqli_query()`. This mismatch means the connection handle from `mysql_connect()` is incompatible with `mysqli_*` functions.

---

## BUG-7: Mixed mysql_error() and mysqli_error() calls

**Files:** Throughout the codebase

Error handling inconsistently uses both `mysql_error()` (deprecated) and `mysqli_error()` calls, leading to potential silent failures or incorrect error reporting.

---

## BUG-8: armee.php molecule deletion uses = instead of .=

**File:** `armee.php` lines 46-51

String concatenation for molecule deletion queries uses `=` (assignment) instead of `.=` (concatenation). This overwrites the query string on each iteration, meaning only the last molecule deletion is actually executed.

---

## BUG-9: armee.php absurdly large max molecule number

**File:** `armee.php` line ~339

The maximum molecule number is set to a value that exceeds PHP float precision limits, making comparisons unreliable and potentially allowing overflow-related bugs.

---

## BUG-10: classement.php triggers UPDATE queries on view

**File:** `classement.php`

Viewing the alliance rankings page triggers UPDATE queries as a side effect. Simply viewing the rankings modifies database state, which is incorrect behavior for a read-only page.

---

## BUG-11: War ending only works for declaring alliance

**File:** `allianceadmin.php`

The war-ending logic has a WHERE clause that is too restrictive -- it only matches when the declaring alliance ends the war. The defending alliance cannot end the war through the normal UI flow.
