# Pass 9 — Voter / Elections System Audit

**Date:** 2026-03-08
**Scope:** voter.php, migrations/0031_create_sondages_table.sql, migrations/0032_create_reponses_sondage.sql, includes/database.php (withTransaction), includes/csrf.php
**Domain:** Poll / vote submission endpoint

---

## Findings

---

### VOTE-P9-001
**Severity:** CRITICAL
**File:** voter.php:31 / migrations/0031_create_sondages_table.sql:6
**Description — Column name mismatch (schema vs code): runtime SQL error on every vote**

The `sondages` table was created with column name `options` (migration 0031, line 6):
```sql
options TEXT NOT NULL COMMENT 'Comma-separated poll options',
```
voter.php line 31 queries a column that does not exist:
```php
$data = dbFetchOne($base, 'SELECT id, reponses FROM sondages ORDER BY date DESC LIMIT 1');
```
`dbFetchOne` returns `null` when `dbQuery` returns `false` (a prepare/execute failure). The code then hits the `if (!$data)` guard on line 33 and returns `{"erreur": true}` to every caller — meaning **no vote can ever be submitted**. The election system is completely non-functional in production.
The same mismatch means the option-count validation on line 38–41 (`explode(',', $data['reponses'])`) can never execute, so the option-range guard is dead code until the schema is fixed.

**Recommended fix:** Either rename the column in the migration to `reponses` (or add a `CHANGE COLUMN` migration), or update the SELECT to `SELECT id, options AS reponses FROM sondages …` and consistently use the canonical column name.

---

### VOTE-P9-002
**Severity:** HIGH
**File:** voter.php:31
**Description — Active-poll filter missing: closed/expired polls are still votable**

The query that fetches the current poll has no `WHERE active = 1` clause:
```php
$data = dbFetchOne($base, 'SELECT id, reponses FROM sondages ORDER BY date DESC LIMIT 1');
```
If a poll is closed (set `active = 0`) and a new one not yet created, the latest inactive poll is returned and votes can still be cast against it. Because the `reponses_sondage` unique key is `(login, sondage)`, the same player can change their vote on a closed poll after results have been "frozen", potentially invalidating announced outcomes.

**Recommended fix:** Add `WHERE active = 1` to the SELECT so that a missing active poll returns null and the guard at line 33 exits cleanly.

---

### VOTE-P9-003
**Severity:** HIGH
**File:** voter.php:44, 54–55
**Description — `pasDeVote` parameter allows arbitrary vote-change with no expiry or rate limit**

`pasDeVote` is a POST parameter accepted from the client:
```php
$pasDeVote = $_POST['pasDeVote'] ?? null;
…
if (!$pasDeVote) {
    dbExecute($base, 'UPDATE reponses_sondage SET reponse = ? …', …);
}
```
When `pasDeVote` is absent (falsy), a voter can silently overwrite their existing vote as many times as they wish within a poll's lifetime. There is no timestamp column on `reponses_sondage`, no rate-limit, and no "vote is final" guard. A player can repeatedly change their vote to manipulate tally trends or test option counts.
Additionally, the semantics of `pasDeVote` are inverted and confusing: the variable name translates to "no vote", yet the absence of the flag is what _enables_ the update. The flag is controlled entirely by the client, making it trivially spoofable.

**Recommended fix:** Record a `voted_at TIMESTAMP` on `reponses_sondage` and reject changes after a configurable lock window (e.g., 5 minutes), or make votes strictly immutable by removing the UPDATE branch.

---

### VOTE-P9-004
**Severity:** MEDIUM
**File:** voter.php:49 (FOR UPDATE semantics)
**Description — FOR UPDATE on a SELECT inside dbFetchOne closes the result set before the lock is visible**

`dbFetchOne` calls `mysqli_stmt_get_result()` then `mysqli_stmt_close()` immediately (database.php:39–40). `mysqli_stmt_close()` on a prepared statement that performed `SELECT … FOR UPDATE` releases the statement, but the InnoDB row lock is held by the underlying connection transaction until COMMIT/ROLLBACK. This is correct _for existing rows_; however, when no row exists yet (first vote), `FOR UPDATE` on a non-existent row acquires a **gap lock** not a record lock. Two concurrent first-vote requests can both pass the `if (!$existing)` check because both see `null` and both receive a gap lock — the gap lock does _not_ block the other transaction's SELECT. They then both attempt `INSERT`, and only the UNIQUE KEY `uk_login_sondage` prevents the second from succeeding (it throws a duplicate-key error, which `dbExecute` logs and returns `false`). The transaction is not rolled back (no exception thrown by `dbExecute`), so the first INSERT commits successfully and the second silently fails — net result is one vote recorded, which is correct. However, the silent failure means the caller receives `{"erreur": false, "dejaRepondu": false}` even though the INSERT failed, which is a false positive acknowledgment for the losing concurrent request.

**Recommended fix:** Wrap the duplicate-key INSERT failure in explicit exception handling and return `{"erreur": true}` or `{"dejaRepondu": true}` so the client gets accurate feedback; alternatively use `INSERT IGNORE` / `INSERT … ON DUPLICATE KEY UPDATE` within the transaction to make the race entirely safe and return the correct duplicate flag.

---

### VOTE-P9-005
**Severity:** MEDIUM
**File:** voter.php:8–17
**Description — Authentication relies solely on session + DB session_token; no membership/status check**

The auth guard verifies the player is logged in and the DB session token matches, which is correct. However, there is no check that the player's account is in a non-suspended or non-vacation state. A player placed in vacation mode (`vacances`) or whose account was soft-deleted but whose session persists can still submit votes. This is low-severity for a poll, but inconsistent with the auth pattern used in game-action pages.

**Recommended fix:** Add a check that `membre.estExclu = 0` (or equivalent account status field) in the session-token lookup query.

---

### VOTE-P9-006
**Severity:** MEDIUM
**File:** voter.php:38–41
**Description — Option-count validation depends on correct comma-separated parsing with no trim/empty-filter**

The option range check:
```php
$options = explode(',', $data['reponses']);
if ($reponse < 1 || $reponse > count($options)) { … }
```
`explode(',', '')` on an empty string returns `['']` (count = 1), meaning an empty `reponses` column would accept `reponse = 1` as valid. Trailing commas (e.g., `"A,B,C,"`) produce a spurious empty element that inflates the count by 1, allowing submission of a non-existent option index. Since the column is admin-managed, this is a data-integrity edge case rather than an exploitable injection vector, but votes against phantom options will silently succeed.

**Recommended fix:** Use `array_filter(array_map('trim', explode(',', $data['reponses'])))` to produce a clean count, and return an error if count is 0.

---

### VOTE-P9-007
**Severity:** LOW
**File:** voter.php (entire file)
**Description — No vote-result query exists anywhere in the codebase**

The audit finds no PHP file that `SELECT`s from `reponses_sondage` with a `GROUP BY reponse` to tally results. The poll infrastructure (tables, voter.php) exists but no results display page is implemented. This means:
1. Poll results cannot be viewed by any player or admin.
2. No GROUP BY / HAVING correctness can be assessed — there is nothing to assess.
3. An admin cannot validate that vote data is internally consistent.

**Recommended fix:** Implement a poll-results page or admin view that queries `SELECT reponse, COUNT(*) AS votes FROM reponses_sondage WHERE sondage = ? GROUP BY reponse ORDER BY reponse` and maps indices back to option labels.

---

### VOTE-P9-008
**Severity:** LOW
**File:** voter.php:23
**Description — `intval()` conversion of `$_POST['reponse']` is correct but zero-value guard is fragile**

```php
$reponse = intval($_POST['reponse'] ?? 0);
…
if (!empty($reponse)) { … }
```
`intval('0abc')` returns `0`; `!empty(0)` is `true` for `0` since `empty(0) === true`. This means a POST of `reponse=0` is correctly rejected (falls through to the else-branch returning `{"erreur":true}`). However if `$_POST['reponse']` contains a float string like `"1.9"`, `intval` truncates to `1`, which is silently accepted — this is benign since option 1 is valid, but documents the type coercion. There is no PHP integer overflow risk: `intval` on a 64-bit PHP returns PHP_INT_MAX for extremely large values, and the range check `$reponse > count($options)` will catch it (realistic poll won't have 2^63 options).

**Recommended fix:** Use `filter_input(INPUT_POST, 'reponse', FILTER_VALIDATE_INT)` with range options for more explicit validation and rejection of floats/non-integers.

---

### VOTE-P9-009
**Severity:** INFO
**File:** voter.php:21–22
**Description — CSRF protection is correctly applied**

`csrfCheck()` is called on POST before any data processing, and it uses a constant-time `hash_equals` comparison. The token is session-scoped and generated with `random_bytes(32)`. No finding.

---

### VOTE-P9-010
**Severity:** INFO
**File:** voter.php:13–17
**Description — Session token validation against DB is correctly implemented**

The double check `$_SESSION['session_token']` + `$row['session_token']` with `hash_equals` prevents session fixation and forged sessions. No finding.

---

### VOTE-P9-011
**Severity:** INFO
**File:** voter.php (entire file)
**Description — No alliance scope: polls are game-wide, not alliance-specific**

There is no alliance membership check in voter.php because this is a site-wide poll system (sondages), not an alliance election system. There is no per-alliance vote feature in the codebase; the audit domain description's "alliance vote" concern does not apply. No finding.

---

### VOTE-P9-012
**Severity:** INFO
**File:** voter.php:48–59, includes/database.php:120–152
**Description — SQL injection is fully prevented**

All queries use prepared statements with `dbFetchOne`/`dbExecute`. The `$sondageId` is typed `i` (integer), `$login` is `s` (string from session, not user input), and `$reponse` is already `intval`-cast. No dynamic SQL construction. No finding.

---

## Summary Table

| ID             | Severity | File:Line         | Issue                                              |
|----------------|----------|-------------------|----------------------------------------------------|
| VOTE-P9-001    | CRITICAL | voter.php:31      | Wrong column name `reponses` — system broken       |
| VOTE-P9-002    | HIGH     | voter.php:31      | No `active = 1` filter — closed polls remain open  |
| VOTE-P9-003    | HIGH     | voter.php:54–55   | Unlimited vote-change via client-controlled flag   |
| VOTE-P9-004    | MEDIUM   | voter.php:49      | Gap-lock race on first vote gives false ACK        |
| VOTE-P9-005    | MEDIUM   | voter.php:8–17    | No account status/suspension check                 |
| VOTE-P9-006    | MEDIUM   | voter.php:38–41   | Trailing comma inflates option count               |
| VOTE-P9-007    | LOW      | voter.php (n/a)   | No results query/page exists                       |
| VOTE-P9-008    | LOW      | voter.php:23      | intval float truncation / prefer FILTER_VALIDATE_INT |
| VOTE-P9-009    | INFO     | voter.php:21–22   | CSRF protection — PASS                             |
| VOTE-P9-010    | INFO     | voter.php:13–17   | Session token auth — PASS                          |
| VOTE-P9-011    | INFO     | voter.php (n/a)   | Alliance scope not applicable — PASS               |
| VOTE-P9-012    | INFO     | voter.php queries | SQL injection — PASS                               |

---

FINDINGS: 1 critical, 2 high, 3 medium, 2 low
