# Pass-9 Fix Batch 5 — Espionage Race Conditions

Date: 2026-03-08
File modified: `includes/game_actions.php`
Lint result: **No syntax errors detected**

---

## P9-MED-008 — Espionage CAS guard (double-report race condition)

### Problem
The espionage resolution block (`else` branch of `$actions['troupes'] != 'Espionnage'`) had no
atomicity protection at entry. Two concurrent requests could both pass the `attaqueFaite == 0`
check in the outer `foreach` row read, then both execute the full resolution logic, producing
duplicate espionage reports and double-consuming the action row.

### Fix
Wrapped the **entire** espionage resolution block in an outer `withTransaction()`. The very first
statement inside that transaction is a CAS UPDATE:

```php
$cas = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $espActionId);
if ($cas === false || $cas === 0) {
    return; // Already resolved by a concurrent request — skip silently.
}
```

`dbExecute()` returns `mysqli_stmt_affected_rows` — 0 when the UPDATE matched no rows (i.e. a
concurrent request already set `attaqueFaite=1`). Both `false` (SQL error) and `0` (no match)
cause an early return, preventing any further resolution work.

The existing inner `withTransaction()` at the report-write/delete step (MED-068) is preserved
inside the outer transaction for additional atomicity of the two INSERT + one DELETE writes.

### Why `actionsattaques`, not `actionsespionnage`
The audit brief mentioned a hypothetical `espionnageFait` column; no such table or column exists
in the codebase. Espionage actions are stored in `actionsattaques` with `troupes = 'Espionnage'`
and the shared `attaqueFaite` flag — matching exactly the column used by the combat CAS guard.

### Key closure variable change
The outer `$actions` loop variable is captured by value as `$espActions` and `$espActionId` before
entering the closure, avoiding any stale-reference issues if the outer `foreach` advances.

---

## P9-MED-009 — NULL-dereference if target deleted between data-read and report-write

### Problem
After a successful espionage threshold check, the code called:

```php
$ressourcesJoueur    = dbFetchOne(...ressources WHERE login=?...);
$constructionsJoueur = dbFetchOne(...constructions WHERE login=?...);
```

If the target account was deleted between the CAS guard and these reads (account deletion is not
wrapped in an exclusive lock on `actionsattaques`), both variables would be `null`/`false`,
causing PHP notices and potentially fatal errors when accessing e.g.
`$ressourcesJoueur['energie']` or `$constructionsJoueur['generateur']`.

### Fix
Added a null-check immediately after the two `dbFetchOne` calls:

```php
if (!$ressourcesJoueur || !$constructionsJoueur) {
    $titreRapportJoueur   = "Espionnage — cible supprimée";
    $contenuRapportJoueur = "<p>La cible a été supprimée avant que le rapport puisse être généré.</p>";
} else {
    // ... full report generation ...
}
```

This ensures a minimal but valid report is still written for the attacker (so they know the
espionage ran), and the action is still deleted cleanly by the inner transaction.

---

## Summary

| Finding     | Severity | Status  | Lines changed (approx) |
|-------------|----------|---------|------------------------|
| P9-MED-008  | MEDIUM   | FIXED   | ~120 (full block restructure) |
| P9-MED-009  | MEDIUM   | FIXED   | +6 (null-check + fallback report) |

Both fixes are contained entirely within `includes/game_actions.php`.
PHP lint passes with zero errors.
