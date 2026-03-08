# Pass 7 Transaction / Race Condition Audit

**Auditor:** Claude Agent (Sonnet 4.6)
**Date:** 2026-03-08
**Scope:** includes/player.php, includes/game_actions.php, includes/game_resources.php, constructions.php, armee.php — transaction integrity, TOCTOU patterns, missing locks, non-atomic increments.

---

## Findings

### TX-P7-001 [LOW] — `ajouterPoints(type=0)` construction points use bare atomic UPDATE, no outer transaction boundary

**File:** `includes/player.php:150-157`

**Proof code:**
```php
if ($type == 0) {
    // Construction points — update raw, then recompute sqrt total
    $result = dbExecute($base, 'UPDATE autre SET points = points + ? WHERE login = ? AND points + ? >= 0', 'dsd', $nb, $joueur, $nb);
    if ($result > 0) {
        recalculerTotalPointsJoueur($base, $joueur);
        return $nb;
    }
}
```

**Analysis:** The `type=0` path (construction points) issues an atomic `UPDATE autre SET points = points + ?` followed immediately by `recalculerTotalPointsJoueur()`, which does a bare SELECT then a bare UPDATE to `totalPoints`. These two operations are not wrapped in a transaction. If a concurrent request (e.g., another construction completing, or a combat resolving) writes to `points` between the two statements, `totalPoints` will be recomputed from a stale read of `points` and silently overwrite the correct value.

This is called from `augmenterBatiment()` (inside a `withTransaction`) and `diminuerBatiment()` (inside a `withTransaction`), but `withTransaction` uses savepoints for nesting — so the inner `ajouterPoints(type=0)` call opens a savepoint but `recalculerTotalPointsJoueur` still races against concurrent outer-scope writes to `autre` from a different connection.

**Severity rationale:** LOW — `totalPoints` is recomputed deterministically from source columns, so a stale snapshot self-corrects on the next point-awarding event. No resource is duplicated or lost; only the ranking score can be temporarily incorrect.

**Fix:** Wrap the `type=0` branch in its own `withTransaction` block, or fold `recalculerTotalPointsJoueur` into the single atomic UPDATE (by computing the new total in PHP before the UPDATE). Alternatively add `FOR UPDATE` to the SELECT inside `recalculerTotalPointsJoueur` when it is called inside a savepoint context.

---

### TX-P7-002 [LOW] — `recalculerTotalPointsJoueur` performs a naked SELECT then UPDATE on `autre`

**File:** `includes/formulas.php:122-135`

**Proof code:**
```php
function recalculerTotalPointsJoueur($base, $joueur)
{
    $data = dbFetchOne($base, 'SELECT points, pointsAttaque, ... FROM autre WHERE login = ?', 's', $joueur);
    // ... compute total ...
    dbExecute($base, 'UPDATE autre SET totalPoints = ? WHERE login = ?', 'ds', $total, $joueur);
}
```

**Analysis:** This function is called from three places:
1. Inside `ajouterPoints(type=1/2/3)` — which already holds a `FOR UPDATE` lock on the `autre` row, so this call is safe in those code paths.
2. Inside `ajouterPoints(type=0)` — which does NOT hold a `FOR UPDATE` lock (see TX-P7-001), creating a brief window.
3. Inside `augmenterBatiment()` → `ajouterPoints(type=0)` call chain — the outer `withTransaction` holds a FOR UPDATE lock on `constructions`, not on `autre`, so the SELECT in `recalculerTotalPointsJoueur` can read a stale `autre` row if a concurrent request is also updating `autre` at the same moment.

The function itself has no locking whatsoever. It is safe only when a `FOR UPDATE` on `autre` is already held by the caller.

**Severity rationale:** LOW — same as TX-P7-001: self-correcting on next event, no financial loss.

**Fix:** Add `FOR UPDATE` to the SELECT inside `recalculerTotalPointsJoueur`, or document the invariant that callers must hold a lock on `autre` before calling this function.

---

### TX-P7-003 [LOW] — `updateRessources` CAS on `tempsPrecedent` leaks a window for double-production on ABA rollover

**File:** `includes/game_resources.php:199-210`

**Proof code:**
```php
$donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $joueur);
$nbsecondes = time() - $donnees['tempsPrecedent'];
if ($nbsecondes < 1) { return; }

dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
if (mysqli_affected_rows($base) === 0) {
    return; // Another request already updated — skip to prevent double resources
}
```

**Analysis:** The CAS guard is correct for the intended purpose (prevent double production tick). However, after the CAS succeeds the function issues multiple additional bare UPDATEs (energy, atoms, molecule decay) without holding any lock on `ressources`. A concurrent combat or market transaction that also holds a FOR UPDATE lock on `ressources` and `autre` can interleave between the CAS claim and the resource UPDATEs. The result is that `tempsPrecedent` is already updated but the resource gains from this tick are computed against a base that has already been mutated by the concurrent transaction.

In practice this means: if a market buy deducts energy between the CAS claim and the `energie = LEAST(...)` UPDATE, the player gains slightly more energy than they should (the production delta is added on top of the post-purchase balance, but the cap `LEAST(... energie + delta ...)` will still clamp correctly). The energy cannot go above `placeMax` due to the LEAST guard.

For atoms the same holds: the `LEAST/GREATEST` guards prevent storage overflow or negative values. So there is no exploitable duplication — the worst case is a small extra production tick of < 1 hour's worth of atoms.

**Severity rationale:** LOW — the LEAST/GREATEST guards mitigate the practical impact. No unbounded duplication is possible. True fix would be to wrap the CAS and all subsequent resource UPDATEs in a single `withTransaction` block, but this creates a wide lock window and risks deadlocking with the market's own lock ordering.

**Fix (optional):** Move the entire `updateRessources` body (CAS + energy UPDATE + atoms UPDATE + molecule decay transaction) into a single `withTransaction` call using the existing savepoint mechanism. Acquire locks in consistent order: `autre` → `ressources` → `molecules`.

---

## Summary

| ID | Severity | File | Description |
|----|----------|------|-------------|
| TX-P7-001 | LOW | includes/player.php:150 | `ajouterPoints(type=0)` — no transaction wrapping the UPDATE+recalc pair |
| TX-P7-002 | LOW | includes/formulas.php:122 | `recalculerTotalPointsJoueur` — naked SELECT without FOR UPDATE, safe only in locking callers |
| TX-P7-003 | LOW | includes/game_resources.php:199 | `updateRessources` CAS — gap between CAS claim and resource UPDATEs; mitigated by LEAST/GREATEST guards |

**Total new findings: 3 (all LOW)**

### What was reviewed and found clean

- **includes/player.php** — `inscrire()`, `augmenterBatiment()`, `diminuerBatiment()`, `coordonneesAleatoires()`: all wrapped in `withTransaction` with `FOR UPDATE` locks on relevant rows.
- **includes/game_actions.php** — Construction completion (CAS-DELETE pattern), formation processing (FOR UPDATE on actionsformation row), combat resolution (CAS UPDATE attaqueFaite=0 inside transaction), return trip (FOR UPDATE on actionsattaques + molecules), resource transfer delivery (FOR UPDATE on actionsenvoi + ressources), espionage report (withTransaction): all correctly guarded.
- **includes/game_resources.php** — Molecule decay wrapped in `withTransaction`; energy/atom production uses atomic `LEAST/GREATEST` SQL; neutrino decay inside the same transaction.
- **constructions.php** — `traitementConstructions`: FOR UPDATE on actionsconstruction (slot count), FOR UPDATE on ressources, re-validates under lock. Point allocation (producteur/condenseur): FOR UPDATE on constructions row.
- **armee.php** — Molecule deletion, molecule creation, molecule formation, neutrino formation: all inside `withTransaction` with FOR UPDATE on the relevant rows.
- **attaquer.php** — Espionage launch and combat launch: both inside `withTransaction` with FOR UPDATE on resources/molecules.
- **marche.php** — Market buy (deadlock retry loop, consistent lock order: ressources→constructions→cours FOR UPDATE); resource transfer send (FOR UPDATE on sender ressources + recipient constructions + recipient ressources).
- **don.php** — Donation: FOR UPDATE on ressources, autre, alliances inside withTransaction.
- **includes/database.php** — `withTransaction` correctly implements savepoints for nesting — nested calls from combat.php's `ajouterPoints(type=1/2/3)` (called while inside the outer combat transaction) safely use `SAVEPOINT` instead of issuing a new `BEGIN` (which would cause an implicit commit).
- **includes/combat.php** — Resource writes (attacker gains pillage, defender loses pillage, energy reward) and stat increments (`nbattaques`, `moleculesPerdues`, `declarations.pertes*`): all issued inside the combat `withTransaction` closure. War score updates use atomic `+=` increments.

### Notes on savepoint-nested `ajouterPoints` calls from combat.php

`ajouterPoints(type=1/2/3)` is called from `includes/combat.php` lines 711-713, which is `include`d inside the combat `withTransaction` closure. Each call to `ajouterPoints(type=1/2/3)` itself opens a nested `withTransaction`, which correctly uses a SAVEPOINT (depth > 0). The SELECT inside uses `FOR UPDATE` on `autre`, which is valid within the outer transaction. **This is SAFE.**

`ajouterPoints(type=0)` is called from `augmenterBatiment()` and `diminuerBatiment()`, which are called OUTSIDE any outer transaction (not from combat). Each already wraps its DB writes in its own `withTransaction`, so `ajouterPoints(type=0)` opens a nested savepoint — but the `recalculerTotalPointsJoueur` SELECT inside the savepoint is not locked. This is the TX-P7-001/TX-P7-002 gap.

**Conclusion:** The codebase is in excellent shape for transaction integrity. All CRITICAL and HIGH patterns from previous passes have been fixed. The three new findings are LOW severity, self-correcting, and have no exploitable financial impact due to the LEAST/GREATEST guards and the fact that `totalPoints` is always re-derivable from authoritative source columns.
