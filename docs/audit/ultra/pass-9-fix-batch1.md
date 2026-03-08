# Pass 9 Fix Batch 1

Date: 2026-03-08
File touched: voter.php

## P9-HIGH-001 — voter.php: account status check

**Severity:** HIGH
**Status:** FIXED

### Change

Line 13: added `AND estExclu = 0` to the session-token lookup query.

```php
// Before
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);

// After
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ? AND estExclu = 0', 's', $_SESSION['login']);
```

### Effect

Suspended or vacation players (estExclu != 0) now receive an authentication failure at the token-check gate and cannot submit votes. The query returns no row, which causes the existing `!$row` branch to call `session_destroy()` and exit with `{"erreur":true}`.

No table alias was added — the WHERE clause operates directly on the `membre` table, matching the existing query style.

---

## P9-CRIT-001 — voter.php: no poll results page

**Severity:** CRIT (downgraded to INFO — design gap, not exploitable)
**Status:** DOCUMENTED (TODO comment added)

### Change

Line 60 (the final `else` branch at end of file): added a TODO comment.

```php
} else {
    // TODO: P9-CRIT-001 — no poll results endpoint exists; results can only be viewed via DB query
    exit(json_encode(["erreur" => true]));
}
```

### Effect

No behavioral change. Documents that poll results are not exposed to players and can only be inspected via direct DB query. A future sprint should add a read-only results page (either a separate `sondage_resultats.php` or a `GET` branch in voter.php returning aggregated counts).

---

## Verification

```
php -l voter.php
No syntax errors detected in voter.php
```

Both changes are minimal and non-breaking. No other files were touched.
