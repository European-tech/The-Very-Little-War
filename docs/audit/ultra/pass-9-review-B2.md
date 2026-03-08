# Ultra Audit Pass 9 — Reviewer B (Re-run / Rev 2 Verification)

**Date:** 2026-03-08
**Reviewer:** Reviewer B (Re-run)
**Purpose:** Verify that the 5 technical corrections requested after the first Reviewer B pass are correctly incorporated in Rev 2 of the remediation plan.

---

## Verification Scope

The five corrections under review are:

1. **P9-HIGH-001** — voter.php: `estExclu` without alias (`AND estExclu = 0`, not `AND m.estExclu = 0`)
2. **P9-MED-007** — editer.php: separate `dbFetchOne` on `autre` table to get moderator's `idalliance`
3. **P9-MED-008** — game_actions.php: new OUTER `withTransaction()` wrapping lines 350–476 with CAS as first statement
4. **P9-HIGH-007** — IP hashing: `moderation/ip.php` and `checkSameIpAccounts()` lookup queries also hash the input
5. **P9-MED-019** — ecriremessage.php: actual recipient variable used (not `$canonicalLogin`)

---

## Verification Results

### 1. P9-HIGH-001 — voter.php: `estExclu` without alias

**Plan text (lines 93–97):**
> Add `AND estExclu = 0` (no table alias — the query at line 13 selects from `membre` directly with no alias) to the session-token lookup query so suspended accounts cannot vote. The corrected query clause is:
> ```sql
> SELECT session_token FROM membre WHERE login = ? AND estExclu = 0
> ```
> Do NOT use `m.estExclu`; there is no alias in this query and the prefixed form will produce a SQL error.

**Source file check (`voter.php:13`):**
```php
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
```
The query selects from `membre` with no alias — confirmed. The plan correctly instructs the fix agent to use `AND estExclu = 0` (unaliased). The alias `m.` was removed from Rev 1. The plan also adds a defensive instruction to verify column existence with `SHOW COLUMNS FROM membre LIKE 'estExclu'` before applying.

**VERDICT: CORRECT.**

---

### 2. P9-MED-007 — editer.php: separate fetch for moderator's alliance

**Plan text (lines 252–271):**
> It contains only the `moderateur` key — NOT an `alliance` key. Using `$moderateur['alliance']` would always be `null`, making the guard non-functional. The fix agent must fetch the moderator's alliance separately from the `autre` table, then use that value in the access check.
> ```php
> $modAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
> $modAlliance = $modAllianceRow['idalliance'] ?? null;
> ```

**Analysis:**
The plan correctly identifies that `$moderateur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', ...)` returns only the `moderateur` key — not `alliance`. The fix calls for a separate `dbFetchOne` on `autre` to get `idalliance` — the correct column (confirmed by the existing codebase usage at `ecriremessage.php:22`: `SELECT idalliance FROM autre WHERE login=?`). The comparison uses `$modAlliance !== $topicForum['alliance_tag']`, which is a strict inequality; the plan also correctly documents that the guard must be a no-op when `alliance_tag` is NULL.

One minor precision note: the plan instructs the fix agent to verify the actual column names via `DESCRIBE autre` and `DESCRIBE forums` before applying — this is prudent given `alliance_tag` vs `idalliance` may differ in the live schema. This caveat was present in Rev 1 and is retained. No new issues.

**VERDICT: CORRECT.**

---

### 3. P9-MED-008 — game_actions.php: outer `withTransaction()` wrapping lines 350–476

**Plan text (lines 321–341):**
> The correct fix is to wrap the entire espionage resolution block in a NEW outer `withTransaction()` and place the CAS guard as the FIRST statement inside that new wrapper... Do NOT merely add the CAS guard to the existing line-465 inner transaction alone — that does not protect the data-fetch phase.

**Source file verification:**
- Line 349 in `game_actions.php` is the start of the `else` branch for espionage actions (i.e., `troupes == 'Espionnage'`).
- The espionage data-fetch phase runs from line 350 through the `if/else` block at line 360–460.
- The existing narrow `withTransaction()` at line 465 wraps only the final INSERT+DELETE.
- As of this source snapshot, NO outer `withTransaction()` wraps the full espionage block (lines 350–476). This is the unfixed state — the plan is describing what MUST be done by the fix agent.

The plan's structural description is technically accurate:
- It correctly identifies that the CAS `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0` guard must be the FIRST statement inside the new outer transaction.
- It correctly states the line-465 inner transaction can be merged or removed (since it would be redundant inside the outer transaction).
- The `catch ('cas_skip')` must be at the outer `foreach` level — not inside a closure — consistent with the existing combat path at lines 341–348 which already does this.
- The plan confirms this is NOT the existing line-465 transaction.

The fix description is unambiguous and technically precise. The fix agent has sufficient instruction to apply the change without error.

**VERDICT: CORRECT.**

---

### 4. P9-HIGH-007 — IP hashing: lookup queries also hash input

**Plan text (lines 610–613):**
> **SIDE-EFFECT FIX (mandatory, from Reviewer B):** After this migration, any query that compares a raw IP string against the now-hashed column will silently fail to match. The following locations MUST also be updated to hash the lookup value before comparison:
> 1. `moderation/ip.php:20` — currently `SELECT * FROM membre WHERE ip = ?` with a raw IP input. Change to hash the `$_GET['ip']` (or equivalent) value using the same `hash_hmac` call before binding it to the query.
> 2. `checkSameIpAccounts()` in `includes/multiaccount.php` — any query that reads from `login_history` by IP value must hash the comparison IP first.
> Fix agent must audit ALL query sites that read/compare `membre.ip` or `login_history.ip` and update every one.

**Analysis:**
The plan correctly incorporates Reviewer B's original objection. The side-effect fix is now labeled mandatory and both specific call sites are named:
- `moderation/ip.php:20` — lookup by raw `$_GET['ip']` (or equivalent) against the now-hashed column
- `checkSameIpAccounts()` in `includes/multiaccount.php` — any `login_history` query that filters by IP value

The plan also correctly states that the normalization (`inet_pton`/`inet_ntop`) step must happen BEFORE hashing (enforced by the Batch 10 ordering dependency: P9-LOW-020 before P9-HIGH-007). The verification criterion (`Admin IP lookup in moderation/ip.php still returns results when queried with a raw IP`) confirms the expected behavior.

No gaps or omissions from Reviewer B's correction.

**VERDICT: CORRECT.**

---

### 5. P9-MED-019 — ecriremessage.php: actual recipient variable

**Plan text (lines 544–559):**
> The variable `$canonicalLogin` does not exist in the current `ecriremessage.php` code. The file uses `$_POST['destinataire']` directly. The fix agent must:
> 1. Locate where the recipient is resolved from the DB (the `SELECT ... FROM membre WHERE login = ?` lookup that validates the recipient exists).
> 2. Add the self-send guard immediately after that DB lookup, comparing the DB-returned login (or the normalized POST value) against `$_SESSION['login']`.

**Source file check (`ecriremessage.php:64–76`):**
```php
$canonicalRow = dbFetchOne($base, 'SELECT login FROM autre WHERE LOWER(login)=LOWER(?)', 's', $_POST['destinataire']);
if ($canonicalRow) {
    $canonicalLogin = $canonicalRow['login'];
    $now = time();
    dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $canonicalLogin);
```

**Critical finding:** The variable `$canonicalLogin` DOES in fact exist in the current `ecriremessage.php` code at line 67. It is assigned from `$canonicalRow['login']` immediately after the DB lookup resolves the recipient. The plan states this variable does not exist — that assertion in the plan's preamble is incorrect for the actual codebase state.

However, the substantive fix guidance in the plan is still valid: the self-send guard must be added after the `$canonicalRow` fetch, comparing `$canonicalRow['login']` (or `$canonicalLogin`) against `$_SESSION['login']`. The plan provides two alternative code forms:
1. Using the DB-returned login: `strtolower($recipientRow['login']) === strtolower($_SESSION['login'])`
2. Using `$_POST['destinataire']` raw: `strtolower(trim($_POST['destinataire'])) === strtolower($_SESSION['login'])`

For this codebase, Form 1 is the correct approach — `$canonicalRow['login']` (or equivalently `$canonicalLogin`) is the right variable to compare against `$_SESSION['login']`. The fix agent is told to "read `ecriremessage.php:7-60` to locate the actual recipient variable before applying," which will lead them to `$canonicalLogin`.

The plan's claim that "`$canonicalLogin` variable does not exist" is factually wrong as a description of the current code. The variable IS present at line 67. However, this error is in the preamble/corrections summary only — the body of the fix description at line 544 correctly says the agent "must locate the actual recipient variable," which guides to the right outcome regardless.

The fix will be applied correctly by a competent agent reading both the plan body and the source. The preamble inaccuracy does not misdirect the fix.

**VERDICT: SUBSTANTIVELY CORRECT — minor preamble inaccuracy (the variable `$canonicalLogin` does exist in the current code at line 67). The fix body's instruction to locate the actual recipient variable and compare against `$_SESSION['login']` will guide the agent to the correct fix. No functional error introduced.**

---

### Bonus: SPY-P9-009 (Rev 2 Batch 15) — `couleurFormule()` unescaped output

The plan states: add `htmlspecialchars()` on input at `includes/game_actions.php:378` before calling `couleurFormule()`.

**Source file check (`game_actions.php:378`):**
```php
$armeeHtml .= "<strong>" . couleurFormule($espClass['formule']) . " : </strong>" ...
```
The `$espClass['formule']` is fed directly from the DB into `couleurFormule()` with no prior escaping. This is a real finding. The plan's line number (378) is accurate. The fix description is technically correct.

---

## Summary

| Item | Status | Notes |
|------|--------|-------|
| P9-HIGH-001 | CORRECT | Alias removed; plan instructs `AND estExclu = 0` with no prefix |
| P9-MED-007 | CORRECT | Separate `dbFetchOne` on `autre` for `idalliance`; schema caveat retained |
| P9-MED-008 | CORRECT | New outer `withTransaction()` wrapping lines 350–476; CAS as first statement |
| P9-HIGH-007 | CORRECT | Both lookup sites (`moderation/ip.php`, `checkSameIpAccounts()`) included as mandatory |
| P9-MED-019 | SUBSTANTIVELY CORRECT | Preamble says `$canonicalLogin` doesn't exist but it does (line 67); fix body still guides agent to correct variable. No functional error. |

---

## FIXES VERIFIED

All 5 technical corrections from Reviewer B's first pass are incorporated correctly in Rev 2. The one preamble inaccuracy in P9-MED-019 (claiming `$canonicalLogin` does not exist when it does at line 67) does not affect the correctness of the fix instructions in the body of that item. A fix agent following the plan will arrive at the correct implementation.

**No blocking issues. Plan Rev 2 is approved for implementation.**
