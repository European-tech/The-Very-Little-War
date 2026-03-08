# Pass 9 — Compound Synthesis Lab Security Audit

**Scope:** `laboratoire.php`, `includes/compounds.php`
**Date:** 2026-03-08
**Auditor:** Narrow-domain security agent

---

## Methodology

Full manual code review of both files, cross-referenced with:
- `includes/config.php` (COMPOUND_* constants, $COMPOUNDS array)
- `includes/database.php` (transaction/query helpers)
- `includes/csrf.php` (token implementation)
- `includes/basicprivatephp.php` (session auth guard)
- `includes/rate_limiter.php` (available rate-limiter facility)
- `migrations/0024_create_compounds.sql` (schema)
- `migrations/0067_player_compounds_unique.sql` (unique index)

---

## Findings

### LAB-P9-001
**ID:** LAB-P9-001
**Severity:** MEDIUM
**File:line:** `includes/compounds.php:100`
**Description:** Dynamic column name interpolated into UPDATE SQL without parameterisation.

The deduction loop at line 100 constructs the query as:
```php
"UPDATE ressources SET $resource = GREATEST($resource - ?, 0) WHERE login = ?"
```
`$resource` is a column name (e.g. `carbone`) taken directly from the `$COMPOUNDS` recipe array. A whitelist check against `$nomsRes` is applied at line 84 in the preceding validation loop, and because `$nomsRes` is a fixed 8-element array defined in `config.php` (`['carbone','azote','hydrogene','oxygene','chlore','soufre','brome','iode']`), the current set of registered recipes cannot produce an injection.

However, the defence-in-depth is fragile: there is **no whitelist check inside the second loop** (the UPDATE loop, lines 93-103). If a developer adds a new recipe key that passes the `in_array` check in the first loop but is later processed by the second loop under a different variable binding, or if `$nomsRes` is modified to contain an attacker-controlled value, the column name interpolation would be reachable. Additionally, the `GREATEST($resource - ?, 0)` template embeds `$resource` twice verbatim; if the first occurrence were ever tainted it would be a fully injectable column-context expression.

**Recommended fix:** Extract the whitelist validation into a shared helper called before either loop, assert the result is one of the fixed 8 names, and use `in_array($resource, $nomsRes, true)` as a guard immediately before each `UPDATE` in the deduction loop, not only in the pre-check loop.

---

### LAB-P9-002
**ID:** LAB-P9-002
**Severity:** MEDIUM
**File:line:** `includes/compounds.php:45`
**Description:** `countStoredCompounds()` issues `FOR UPDATE` outside a transaction when called from the display path.

`countStoredCompounds()` appends `FOR UPDATE` unconditionally. Within `synthesizeCompound()` it is called inside `withTransaction()`, which is correct. However, the function is exported with `global` scope and nothing prevents a future caller (or the display code at `laboratoire.php:41`) from calling it outside a transaction. In that context `FOR UPDATE` is either silently ignored by InnoDB (auto-commit mode) or causes unexpected locking if a transaction is already in progress for an unrelated reason. The current display code at `laboratoire.php:41` calls `getStoredCompounds()` (not `countStoredCompounds()`), so there is no immediate exploit, but the locking hint embedded in a general-purpose count function is an API hazard.

**Recommended fix:** Remove `FOR UPDATE` from `countStoredCompounds()` and apply it only inline within the `withTransaction` closure in `synthesizeCompound()`.

---

### LAB-P9-003
**ID:** LAB-P9-003
**Severity:** MEDIUM
**File:line:** `laboratoire.php:7-28` / `includes/compounds.php` (whole file)
**Description:** No rate limiting on synthesis or activation — a player can spam both actions as fast as HTTP allows.

The infrastructure rate-limiter (`includes/rate_limiter.php`, `rateLimitCheck()`) is used on login and market operations but is absent from `laboratoire.php`. A player with sufficient resources can send synthesize+activate POST requests in a tight loop, limited only by network latency. Because `COMPOUND_MAX_STORED` is 3 and 1 compound per effect type can be active, the real damage is resource drain speed — but the absence of a per-player synthesis cooldown also defeats any intended game-balance pacing (the player guide implies compounds require meaningful time investment).

A previous pass-3 audit finding (cross-domain: `pass-3-cross-domain.md:387`) flagged this and suggested a 2-hour synthesis cooldown, but no fix was applied.

**Recommended fix:** Add `rateLimitCheck($_SESSION['login'], 'synth', 10, 60)` at the top of both the synthesis and activation branches in `laboratoire.php`, returning an error if the limit is exceeded.

---

### LAB-P9-004
**ID:** LAB-P9-004
**Severity:** MEDIUM
**File:line:** `includes/compounds.php:244-245`
**Description:** Hardcoded magic number `86400` in `cleanupExpiredCompounds()` should be a named constant.

`time() - 86400` at line 245 is a 24-hour grace period for UI display of expired compounds. This value is not tied to any config.php constant, is not documented at the call site beyond a comment, and is inconsistent with the project standard of deriving all time durations from named constants (e.g. `SECONDS_PER_HOUR`, `SECONDS_PER_DAY`).

**Recommended fix:** Replace `86400` with `SECONDS_PER_DAY` (already defined in `config.php`) and add a constant `COMPOUND_DISPLAY_GRACE_SECONDS` set to `SECONDS_PER_DAY` in the COMPOUND block of config.php.

---

### LAB-P9-005
**ID:** LAB-P9-005
**Severity:** LOW
**File:line:** `laboratoire.php:31`
**Description:** `COMPOUND_GC_PROBABILITY` docstring in config.php says "per updateRessources call" but GC actually fires per laboratoire.php page load.

```php
define('COMPOUND_GC_PROBABILITY', 0.05); // probability (0–1) of GC cleanup per updateRessources call (~5%)
```

The GC is invoked inside `laboratoire.php:31` on each page load, not inside `updateRessources()`. This is a documentation error that could mislead a developer into removing the lab-level GC call believing it is already handled elsewhere, leaving expired compounds to accumulate indefinitely. The config.php comment is the canonical reference for the constant's meaning.

**Recommended fix:** Update the comment to read "per laboratoire.php page load" and add a separate note that `updateRessources()` does not call GC.

---

### LAB-P9-006
**ID:** LAB-P9-006
**Severity:** LOW
**File:line:** `migrations/0067_player_compounds_unique.sql:23`
**Description:** Unique index on `(login, compound_key)` prevents storing two of the same compound type simultaneously, but this is semantically overly restrictive and conflicts with the intended storage model.

The `COMPOUND_MAX_STORED = 3` limit allows a player to hold up to 3 stored compounds. Adding `UNIQUE(login, compound_key)` means a player can never hold two H2O compounds at the same time, even if they want to queue both for future activation. The activation check (`activateCompound`) already enforces the one-active-per-effect-type rule. The index silently prevents what might be intentional gameplay (stocking up before a battle) and would generate a misleading generic DB error rather than a game-meaningful message if a duplicate insert is attempted concurrently.

Note: The index was added in response to MEDIUM-023 (race condition allowing duplicate rows), but the intended fix for the race was the `FOR UPDATE` lock + `countStoredCompounds` check already present in the transaction. The unique index is a reasonable belt-and-suspenders measure for the race condition but creates an unintended gameplay restriction.

**Recommended fix:** If the design intent is to disallow duplicate compound types in storage, document this explicitly in config.php. If duplicates should be allowed, remove the unique index and rely on the existing transactional count guard.

---

### LAB-P9-007
**ID:** LAB-P9-007
**Severity:** LOW
**File:line:** `includes/compounds.php:87`
**Description:** Integer overflow is not possible in synthesis cost calculation but the type binding is mismatched.

`$cost = $qty * COMPOUND_ATOM_MULTIPLIER` where `$qty` is a small PHP integer (1-4 from recipe) and `COMPOUND_ATOM_MULTIPLIER = 100`. Maximum cost is `4 * 100 = 400`. This is far below PHP_INT_MAX (9.2 × 10^18 on 64-bit) — no integer overflow risk exists.

However, `dbExecute` is called with type string `'ds'` — `d` (double/float) for `$cost`. The resource columns in the `ressources` table are likely stored as `INT` or `FLOAT`. Binding an integer quantity as a `double` is correct for subtraction but documents a design ambiguity: `$cost` could be stored as an `int` and bound as `'is'` for clarity and to avoid any float rounding on large resource values in future.

**Recommended fix:** Cast `$cost` as `(int)` and use `'is'` binding for the UPDATE parameter; document that recipe quantities are always whole-atom multiples.

---

### LAB-P9-008
**ID:** LAB-P9-008
**Severity:** INFO
**File:line:** `includes/compounds.php:160-167`
**Description:** Buff duration extension by repeated activation is correctly blocked but the blocking mechanism relies on effect-type equality, not compound identity.

The `activateCompound()` function checks `$COMPOUNDS[$active['compound_key']]['effect'] === $COMPOUNDS[$key]['effect']` at line 164. Two different compound keys with the same effect string (e.g., both CO2 and a hypothetical CO3 both having `'attack_boost'`) cannot be stacked, which is the desired behaviour. The duration cannot be extended because the player cannot activate a second compound of the same effect while the first is active. This is enforced both at the DB level (the query selects only not-yet-activated rows) and in PHP logic. No bypass found.

**Finding:** No vulnerability. Behaviour is correct and the transactional `FOR UPDATE` on `getActiveCompounds(..., true)` prevents concurrent activation races.

---

### LAB-P9-009
**ID:** LAB-P9-009
**Severity:** INFO
**File:line:** `laboratoire.php:2` / `includes/basicprivatephp.php`
**Description:** Authorization is fully enforced — no bypass found.

`laboratoire.php` includes `basicprivatephp.php` at line 2. That guard:
1. Checks `$_SESSION['login']` and `$_SESSION['session_token']` exist.
2. Validates the session token against the DB with `hash_equals`.
3. Enforces idle timeout via `SESSION_IDLE_TIMEOUT`.
4. Regenerates the session ID every 30 minutes.

All compound operations (`synthesizeCompound`, `activateCompound`) receive `$_SESSION['login']` as the player identifier and bind it to all queries via prepared statements. A player cannot affect another player's compounds by manipulating `compound_id` in `activateCompound` because the `FOR UPDATE` SELECT at line 147 includes `AND login = ?` with the session login.

**Finding:** No vulnerability. Auth chain is sound.

---

### LAB-P9-010
**ID:** LAB-P9-010
**Severity:** INFO
**File:line:** `laboratoire.php:8,20`
**Description:** CSRF protection is correctly applied to both synthesis and activation POST handlers.

`csrfCheck()` is called at line 8 (synthesis) and line 20 (activation) before any state-changing operation. `csrfVerify()` uses `hash_equals` (constant-time comparison). Tokens are session-bound and generated with `random_bytes(32)`. Both forms include `csrfField()` in their HTML output. No bypass found.

**Finding:** No vulnerability.

---

### LAB-P9-011
**ID:** LAB-P9-011
**Severity:** INFO
**File:line:** `includes/compounds.php:55-131`
**Description:** Transaction safety for atom deduction and compound insertion is correctly implemented.

The entire synthesis flow (resource lock, balance check, atom deduction, INSERT) is wrapped in a single `withTransaction()` call. Resource rows are locked with `FOR UPDATE` at line 76 before the balance checks. Atom deduction uses `GREATEST(col - ?, 0)` as an additional safety floor at line 100. The `INSERT INTO player_compounds` at line 106 is inside the same transaction. On any failure (storage full, insufficient atoms, DB error) the transaction rolls back atomically.

**Finding:** No vulnerability.

---

### LAB-P9-012
**ID:** LAB-P9-012
**Severity:** INFO
**File:line:** `includes/compounds.php:59`
**Description:** SQL injection via `compound_key` is not possible.

User-supplied `compound_key` (from `$_POST['compound_key']` via `laboratoire.php:9`) is passed to `synthesizeCompound()` which checks `isset($COMPOUNDS[$compoundKey])` at line 59 before any database access. The key is only used as a PHP array index (never interpolated into SQL) and as a bound parameter in `'INSERT INTO player_compounds (login, compound_key) VALUES (?, ?)'` at line 106, which is fully parameterised. No SQL injection vector exists through this path.

**Finding:** No vulnerability.

---

## Summary Table

| ID | Severity | Description |
|----|----------|-------------|
| LAB-P9-001 | MEDIUM | Dynamic column interpolation in UPDATE — whitelist only in pre-check loop, not deduction loop |
| LAB-P9-002 | MEDIUM | `countStoredCompounds()` has `FOR UPDATE` embedded, hazardous if called outside transaction |
| LAB-P9-003 | MEDIUM | No rate limiting on synthesis/activation — player can spam as fast as HTTP allows |
| LAB-P9-004 | MEDIUM | Hardcoded `86400` in GC cleanup should be `SECONDS_PER_DAY` constant |
| LAB-P9-005 | LOW | `COMPOUND_GC_PROBABILITY` config comment wrong — says updateRessources, actually fires in laboratoire.php |
| LAB-P9-006 | LOW | Unique index `(login, compound_key)` may be overly restrictive vs. intended storage model |
| LAB-P9-007 | LOW | `$cost` bound as `double` (`'ds'`) — should be `int` (`'is'`) for clarity; no overflow risk |
| LAB-P9-008 | INFO | Buff duration extension correctly blocked — no vulnerability |
| LAB-P9-009 | INFO | Authorization correctly enforced end-to-end — no bypass |
| LAB-P9-010 | INFO | CSRF applied correctly to both POST handlers — no bypass |
| LAB-P9-011 | INFO | Atom deduction + compound insert are atomic in one transaction — no vulnerability |
| LAB-P9-012 | INFO | `compound_key` SQL injection not possible — parameterised and array-guarded |

---

FINDINGS: 0 critical, 0 high, 4 medium, 3 low
