# Game Logic Deep-Dive — Round 2

Audit date: 2026-03-03
Files inspected: includes/game_actions.php, includes/player.php, includes/combat.php,
includes/formulas.php, includes/config.php, includes/game_resources.php,
constructions.php, armee.php, attaquer.php, includes/catalyst.php, includes/prestige.php,
includes/db_helpers.php, tutoriel.php

---

## GAME-R2-001 — CRITICAL — Return Trip Processes Even When Attack Not Yet Complete

**File:** includes/game_actions.php, lines 446–468

**Execution trace:**

`updateActions()` fetches all `actionsattaques` rows where `attaquant=? OR defenseur=?`
and loops through them. Inside the loop two independent conditions are evaluated:

```
if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) {
    // resolve combat
}

if ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant']
    && $actions['troupes'] != 'Espionnage') {
    // credit troops back
}
```

These two `if` blocks are **not** mutually exclusive. When `tempsRetour < time()` is true,
`tempsAttaque < time()` is also necessarily true (return time is always later than attack time).

**Scenario:** Player A logs in after both `tempsAttaque` and `tempsRetour` have passed.

1. First `if` fires: combat is resolved, troops written to `troupes`, `attaqueFaite` set to 1 by the CAS UPDATE.
2. Second `if` fires **in the same loop iteration**: the row was not yet deleted (deletion only happens inside the first block for zero-survivor cases), so `$actions['troupes']` still holds the **pre-combat values read at query time** (the loop variable `$actions` was fetched before the CAS update modified the row).

Wait — let's trace more carefully. The CAS UPDATE on line 71 sets `attaqueFaite=1`. Then
`include combat.php` modifies the DB (updates troops) and the row **may** be deleted at line
248 if all attackers died. If NOT deleted, we fall through to the return-trip block on line 446.

At that point `$actions['troupes']` is the **original unmodified troops string** (read at the
start of the loop before combat), not the post-combat string. The return trip will add back
the pre-combat molecule count, ignoring all combat losses. Surviving attackers are credited
**twice**: once in `combat.php` (UPDATE molecules nombre += survivorsFromCombat) and again
here in the return-trip block (UPDATE molecules nombre += preCombatCount * decayCoef).

**Incorrect behavior:** When combat is resolved and return trip completes in the same
`updateActions` call, the attacker's molecules are credited twice — once from combat.php's
survivor write to `actionsattaques.troupes`, and once from the return-trip block reading the
stale pre-combat `$actions['troupes']`.

**Fix:**

Add an `elseif` for the return-trip block, or add an explicit check that `attaqueFaite == 1`
AND the row still exists. The simplest fix is to restructure as `elseif`:

```php
if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) {
    // ... resolve combat ...
} elseif ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant']
          && $actions['troupes'] != 'Espionnage') {
    // ... credit return trip ...
}
```

Using `elseif` ensures that when combat just fired in this same request, the return-trip block
does not also fire. Combat resolution deletes the row when all attackers die; when survivors
exist the row persists until next `updateActions` call where `attaqueFaite` is already 1 so
the combat block is skipped and only the return-trip block runs.

---

## GAME-R2-002 — CRITICAL — Formation Time Calculated With Wrong Level Variable

**File:** armee.php, line 116

**Execution trace:**

```php
$tempsForm = tempsFormation($donneesFormer['azote'], $niveauazote, $total, $_SESSION['login']);
```

`$niveauazote` is set inside `initPlayer()` via:

```php
$niveauxAtomes = explode(';', $constructions['pointsCondenseur']);
foreach ($nomsRes as $num => $ressource) {
    ${'niveau' . $ressource} = $niveauxAtomes[$num];
}
```

`$niveauazote` is therefore the **condenseur level for azote** (nitrogen), i.e. the per-atom
condenseur upgrade points. This is correct: it feeds into `tempsFormation` as the `$niveau`
parameter.

However, `tempsFormation()` in formulas.php uses `$niveau` in the formula:

```php
return ceil($ntotal / (1 + pow(FORMATION_AZOTE_COEFFICIENT * $azote, FORMATION_AZOTE_EXPONENT))
       / (1 + $niveau / FORMATION_LEVEL_DIVISOR) / bonusLieur(...) / ...);
```

`FORMATION_LEVEL_DIVISOR` is 20. So the condenseur azote level directly divides by 20.
This is intentional per the design — but the **display** in `constructions.php` uses the
same variable name `$niveauazote` which is set before `updateActions` fires during a page
load (via `constantes.php -> initPlayer`). After a construction completes inside
`updateActions()`, `augmenterBatiment` calls `invalidatePlayerCache` + `initPlayer` for
`$_SESSION['login']`, but the `armee.php` POST handler runs before `tout.php` (which calls
`constantes.php`). This means the armee.php formation handler may use **stale** `$niveauazote`
from a pre-`tout.php` state — the variable is a global set by `initPlayer` but armee.php
sets it again from `constantes.php` via `tout.php` at the bottom, after the POST handler.

Specifically at line 116 of armee.php:

```php
$tempsForm = tempsFormation($donneesFormer['azote'], $niveauazote, $total, $_SESSION['login']);
```

`$niveauazote` here references the **page-load global** from the `include("includes/tout.php")`
on line 237, which is *after* this POST handler. So at the time of the call, `$niveauazote`
is **undefined** — PHP uses the last-assigned value, which is the value from the previous
page load (persisted in memory via opcache global state). In fresh PHP-FPM requests this
means it starts as 0.

**Incorrect behavior:** With `$niveauazote = 0`, the condenseur azote bonus is never applied
to formation time, making azote condenseur upgrades ineffective for formation speed.

**Fix:**

Fetch the correct condenseur azote level inside the POST handler before computing formation time:

```php
// In the 'emplacementmoleculeformer' POST handler, replace:
$tempsForm = tempsFormation($donneesFormer['azote'], $niveauazote, $total, $_SESSION['login']);

// With:
$condenseurData = dbFetchOne($base, 'SELECT pointsCondenseur FROM constructions WHERE login=?',
    's', $_SESSION['login']);
$condenseurLevels = explode(';', $condenseurData['pointsCondenseur']);
$niveauazoteLocal = $condenseurLevels[1] ?? 0; // azote is index 1 in $nomsRes
$tempsForm = tempsFormation($donneesFormer['azote'], $niveauazoteLocal, $total, $_SESSION['login']);
```

Or, more robustly, move the `include("includes/tout.php")` call to before the POST handlers
(but this requires restructuring the page).

---

## GAME-R2-003 — HIGH — initPlayer Cache Never Invalidated for Defender in Combat

**File:** includes/game_actions.php, lines 77–85 and 541

**Execution trace:**

Inside `updateActions($joueur)`, when the current player is the attacker:

```php
if ($actions['attaquant'] == $joueur) {
    $enFace = $actions['defenseur'];
    updateRessources($actions['defenseur']);
    updateActions($actions['defenseur']);    // recursive call
}
```

`updateActions($actions['defenseur'])` triggers another `initPlayer($defender)` call at the
end (line 541). That call populates `$GLOBALS['_initPlayerCache'][$defender]` with fresh data.

Then, back in the attacker's context, `include("includes/combat.php")` executes. Combat.php
reads defender data via `dbFetchOne` directly (molecules, constructions, resources) and
modifies the DB. After combat, resources are updated in DB for both players.

At line 541, `initPlayer($joueur)` is called again — this re-caches the **attacker**. But the
**defender's** cache entry (populated by the recursive `updateActions($defender)`) is now
stale because combat modified the defender's molecules and resources. The cache for the
defender still holds pre-combat values.

**Scenario:** Another page request for the defender fires within the same PHP-FPM process
lifecycle before the cache is cleared (within the same request via PHP's global state). PHP
`static` caches and `$GLOBALS` caches persist for the duration of one HTTP request. However,
the real issue surfaces when `augmenterBatiment` or other functions call `initPlayer($defender)`
during the same request — they will get the pre-combat cached values.

More concretely: after combat resolves, `initPlayer($joueur)` on line 541 uses the cache
which was populated **before** combat for the defender's context (the recursive call context
set `$GLOBALS['_initPlayerCache'][$defender]` but then combat modified the DB without calling
`invalidatePlayerCache($defender)`).

**Incorrect behavior:** Post-combat state of the defender (molecules, resources) is not
reflected in any subsequent `initPlayer($defender)` call within the same PHP request. The
`$constructions` global would show pre-combat building levels, causing `diminuerBatiment`
to operate on stale data if called again in the same request cycle.

**Fix:**

After `include("includes/combat.php")`, explicitly invalidate the defender's cache:

```php
// In game_actions.php, after the include("includes/combat.php") block resolves:
invalidatePlayerCache($actions['defenseur']);
invalidatePlayerCache($actions['attaquant']);
```

Add these calls immediately after the `include` on line 109, before the report generation.

---

## GAME-R2-004 — HIGH — Molecule Return Trip Fires for Completed Combats (attaqueFaite=1)

**File:** includes/game_actions.php, lines 446–468

**Execution trace:**

The return-trip block condition is:

```php
if ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant']
    && $actions['troupes'] != 'Espionnage') {
```

This fires for **any** completed combat where `tempsRetour` has elapsed, regardless of
`attaqueFaite`. When combat was resolved in a **previous** request cycle (attaqueFaite = 1,
row not yet deleted), this block correctly credits the return. The row is then deleted on
line 467.

BUT: when `joueur == defenseur` (i.e., the defender called `updateActions` and is the current
`$joueur`), the outer query fetches rows where `defenseur = $joueur` too. For those rows,
`$joueur == $actions['attaquant']` is false, so the return-trip block does not fire — which
is correct.

However, when the **attacker** calls `updateActions` and combat resolves in this same call
(block 1 fires), the row is NOT deleted for surviving attackers (only deleted for zero
survivors at line 248). The next time `updateActions($attaquant)` is called (next page load),
`attaqueFaite = 1` in the DB row, so block 1 is skipped. But now the `$actions['troupes']`
field has been updated by combat.php to hold post-combat troops. The return-trip block fires
correctly. **This path is fine.**

The real bug is in the SAME-REQUEST path (as described in GAME-R2-001 above). To avoid
confusion this entry is marked HIGH as a secondary confirmation of the same root cause.

**Fix:** Same as GAME-R2-001 — use `elseif`.

---

## GAME-R2-005 — HIGH — pointsVictoireAlliance Ranks 4-9 Use Rank 2 Formula Instead of Rank 3

**File:** includes/formulas.php, lines 34–50

**Execution trace:**

```php
function pointsVictoireAlliance($classement)
{
    if ($classement == 1) return VP_ALLIANCE_RANK1;  // 15
    if ($classement == 2) return VP_ALLIANCE_RANK2;  // 10
    if ($classement == 3) return VP_ALLIANCE_RANK3;  // 7
    if ($classement < 10) return VP_ALLIANCE_RANK2 - $classement;  // BUG
    return 0;
}
```

For ranks 4–9 the formula returns `VP_ALLIANCE_RANK2 - classement = 10 - classement`:
- Rank 4: 10 - 4 = 6
- Rank 5: 10 - 5 = 5
- Rank 6: 10 - 6 = 4
- Rank 7: 10 - 7 = 3
- Rank 8: 10 - 8 = 2
- Rank 9: 10 - 9 = 1

The config comment says "Ranks 4-9: 10 - rank", so rank 4 gets 6 VP and rank 3 gets 7 VP —
the 4-9 range correctly gives less than rank 3. This is probably intentional.

However, rank 9 gets 1 VP and rank 10 gets 0 VP (falls to `return 0`), creating a cliff. The
condition `$classement < 10` should likely be `$classement <= 10` to include rank 10 giving
`10 - 10 = 0` which is the same result, so this specific edge is harmless.

**The real bug:** rank 10 is off — it should return `VP_ALLIANCE_RANK2 - 10 = 0` which is the
same as not returning anything. But the real formula should be based on `VP_ALLIANCE_RANK3`
for consistency. Looking at the config comment:

```
// Ranks 4-9: 10 - rank
```

This uses `VP_ALLIANCE_RANK2 (10)` as a base. The comment says "10 - rank" which implicitly
uses the raw number 10 rather than `VP_ALLIANCE_RANK3 (7)`. If the intent were to step down
from rank 3's value of 7, it should be `VP_ALLIANCE_RANK3 - (classement - 3)`:
- Rank 4: 7 - 1 = 6
- Rank 5: 7 - 2 = 5

This gives the same results! So numerically both formulas yield the same VP for ranks 4-9.
The code is correct as-is but the formula is semantically unclear. This is a **documentation
bug** not a logic bug — mark as LOW.

---

## GAME-R2-006 — HIGH — Formation Time Enqueued With Total Atoms at Formation Time, Not Per-Molecule Cost

**File:** armee.php, lines 103–117

**Execution trace:**

```php
$total = 0;
foreach ($nomsRes as $num => $ressource) {
    $total = $total + $donneesFormer[$ressource];  // sum of all atom types in the molecule
}
// ...
$tempsForm = tempsFormation($donneesFormer['azote'], $niveauazote, $total, $_SESSION['login']);
$finTemps = $tempsDebut + $tempsForm * $_POST['nombremolecules'];
```

`tempsFormation()` signature:

```php
function tempsFormation($azote, $niveau, $ntotal, $joueur)
{
    return ceil($ntotal / (1 + pow(FORMATION_AZOTE_COEFFICIENT * $azote, ...)) / ...);
}
```

`$ntotal` is the total number of atoms in **one molecule**, not the number of molecules being
formed. `tempsFormation` returns the time in seconds to form **one molecule** of this
composition. Then `$tempsForm * $_POST['nombremolecules']` gives total time for the batch.

This is correct by design — per-molecule formation time scales linearly with `nombremolecules`.

However, the `INSERT INTO actionsformation` on line 118 stores `$tempsForm` (per-molecule
time) in the `tempsPourUn` column. Then in `updateActions`, the progress calculation uses:

```php
$derniereFormation = ($actions['nombreDebut'] - $actions['nombreRestant'])
    * $actions['tempsPourUn'] + $actions['debut'];
```

`tempsPourUn` is used as "time per unit". The issue is that `$tempsForm` returned by
`tempsFormation()` is computed with `ceil()`, which rounds up per molecule. For a molecule
with 200 atoms where `tempsFormation` returns 100 seconds, forming 1000 molecules gives:
`$finTemps = $tempsDebut + 100 * 1000 = $tempsDebut + 100000`.

The stored `tempsPourUn = 100` is correct. No bug here.

**Actual issue found:** `$tempsForm` is computed before the `INSERT`, and the `INSERT` stores
it as an integer (`actionsformation` schema likely stores INT). But `tempsFormation()` returns
`ceil(...)` which is a float in PHP. The INSERT type string is `'issiiisis'` — `i` for
`tempsPourUn`. If `tempsFormation` returns a float like `100.0`, casting to `int` is safe.
But if it returns `100.7`, the `ceil()` already rounds to `101.0`, and `int` cast gives `101`.
**No bug.**

This item is cleared — no actual bug in this path.

---

## GAME-R2-007 — HIGH — Phalange Formation: Damage Distribution Does Not Account for Empty Classes

**File:** includes/combat.php, lines 277–283

**Execution trace:**

```php
} elseif ($defenderFormation == FORMATION_PHALANGE) {
    $defDamageShares[1] = $degatsAttaquant * FORMATION_PHALANX_ABSORB;  // 70%
    $remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / max(1, $nbClasses - 1);
    for ($i = 2; $i <= $nbClasses; $i++) {
        $defDamageShares[$i] = $degatsAttaquant * $remainingShare;
    }
}
```

When class 1 is empty (nombre = 0) and defender uses Phalange:

- `$defDamageShares[1]` = 70% of total damage
- The subsequent loop at line 291 checks: `if (${'classeDefenseur' . 1}['nombre'] > 0 ...)`
- Since class 1 has 0 molecules, `$classe1DefenseurMort = 0` and 0 casualties occur
- But 70% of damage was allocated to class 1 — that damage is **silently lost**

Result: a Phalange defender whose class 1 is empty takes only 30% of attacker damage instead
of the full 100%. This makes Phalange with empty class 1 an unintended damage reduction
exploit — any damage allocated to an empty class is wasted.

**Incorrect behavior:** Attacker deals full damage but 70% is absorbed by an empty class,
meaning the defender effectively takes only 30% damage when their class 1 is empty.

**Fix:**

Redistribute damage from empty classes. Before computing damage shares, calculate the actual
absorb ratio based on classes that have molecules:

```php
} elseif ($defenderFormation == FORMATION_PHALANGE) {
    // Only assign Phalange absorb to class 1 if it has molecules
    if (${'classeDefenseur1'}['nombre'] > 0) {
        $defDamageShares[1] = $degatsAttaquant * FORMATION_PHALANX_ABSORB;
        $remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / max(1, $nbClasses - 1);
    } else {
        // Class 1 empty: distribute equally among active classes
        $activeClasses = 0;
        for ($i = 1; $i <= $nbClasses; $i++) {
            if (${'classeDefenseur' . $i}['nombre'] > 0) $activeClasses++;
        }
        $defDamageShares[1] = 0;
        $remainingShare = ($activeClasses > 0) ? (1.0 / $activeClasses) : 0.25;
    }
    for ($i = 2; $i <= $nbClasses; $i++) {
        $defDamageShares[$i] = (${'classeDefenseur' . $i}['nombre'] > 0)
            ? $degatsAttaquant * $remainingShare : 0;
    }
}
```

---

## GAME-R2-008 — HIGH — augmenterBatiment Uses $points from initPlayer Called for $joueur, Then Awards Points to $joueur But Reports for $_SESSION['login']

**File:** includes/player.php, lines 508–540

**Execution trace:**

```php
function augmenterBatiment($nom, $joueur)
{
    global $listeConstructions;
    global $points;
    invalidatePlayerCache($joueur);
    initPlayer($joueur);           // sets global $listeConstructions, $points for $joueur

    $batiments = dbFetchOne(...);  // fetches from DB for $joueur

    // ... upgrades building for $joueur ...

    ajouterPoints($listeConstructions[$nom]['points'], $joueur);   // awards points to $joueur

    invalidatePlayerCache($_SESSION['login']);
    initPlayer($_SESSION['login']);  // reinitializes for logged-in player
}
```

`$listeConstructions` is a global array. When `initPlayer($joueur)` runs, it populates
`$listeConstructions` based on `$joueur`'s building levels. This is correct — the points
awarded should reflect the building being upgraded.

However, `$listeConstructions[$nom]['points']` is computed based on:

```php
$niveauActuel['niveau'] + 1  // the building level being queued
```

This is the level from `$joueur`'s queue, not from combat. But in the combat context
(building damaged), `augmenterBatiment` is **not** called — `diminuerBatiment` is. And
`augmenterBatiment` is only called from `updateActions` when a construction completes.

The issue: `ajouterPoints($listeConstructions[$nom]['points'], $joueur)` awards the points
value from `$listeConstructions` which was computed during `initPlayer($joueur)`. The points
value in `$listeConstructions[$nom]['points']` depends on the **next queued level**:

```php
'points' => $BUILDING_CONFIG[$nom]['points_base'] +
            floor($niveauActuel['niveau'] * $BUILDING_CONFIG[$nom]['points_level_factor']),
```

where `$niveauActuel['niveau']` is the **highest queued level** (from `actionsconstruction`).
But at the time `augmenterBatiment` runs, the construction row has already been deleted by
`updateActions` (line 30: `DELETE FROM actionsconstruction WHERE id=?`). So the `SELECT MAX
niveau FROM actionsconstruction` query in `initPlayer` returns the current DB level, and the
points awarded are based on the **current level** (the one just built) rather than the
queued-next level. This is actually correct.

**No bug in this specific path.** The concern was that `$_SESSION['login']` vs `$joueur`
could diverge, but `updateActions` is called with `$_SESSION['login']` as `$joueur` for
constructions (line 23: `updateActions($joueur)` where `$joueur` is set from `$_SESSION`
in `constantes.php`). When `updateActions` recursively calls for the defender, constructions
are not processed for the defender. This path is clean.

This item is cleared — no bug found.

---

## GAME-R2-009 — HIGH — remiseAZero Does Not Invalidate Player Caches

**File:** includes/player.php, line 793–838

**Execution trace:**

`remiseAZero()` executes mass UPDATE/DELETE statements that reset the entire game state:

```php
dbExecute($base, 'UPDATE autre SET points=0, ...');
dbExecute($base, 'UPDATE constructions SET generateur=default, ...');
dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
dbExecute($base, 'UPDATE membre SET timestamp=?', 'i', time());
```

After these updates, `$GLOBALS['_initPlayerCache']` may still contain entries for any players
who were initialized earlier in the same admin request. Subsequent calls to `initPlayer()` for
those players will return cached pre-reset values rather than reading the fresh post-reset data.

**Incorrect behavior:** If any code runs after `remiseAZero()` in the same request and calls
`initPlayer($somePlayer)`, it gets stale pre-reset data. The prestige-application block on
line 829 calls:

```php
dbExecute($base, 'UPDATE constructions SET generateur=2, vieGenerateur=? WHERE login=?',
    'ds', pointsDeVie(2), $pp['login']);
```

This writes to DB correctly. But if any subsequent code reads through `initPlayer`, it would
see the cached old state. In practice the reset page likely redirects after, so this is low
risk — but it's a latent correctness issue.

**Fix:**

Add at the start of `remiseAZero()`:

```php
function remiseAZero() {
    // Invalidate entire player cache before mass reset
    $GLOBALS['_initPlayerCache'] = [];
    // ... rest of function
}
```

---

## GAME-R2-010 — HIGH — Dispersee Formation: Equal Split Is Calculated as 1/activeClasses But sharePerClass Applied to All Classes Regardless

**File:** includes/combat.php, lines 263–276

**Execution trace:**

```php
if ($defenderFormation == FORMATION_DISPERSEE) {
    $activeDefClasses = 0;
    for ($i = 1; $i <= $nbClasses; $i++) {
        if (${'classeDefenseur' . $i}['nombre'] > 0) $activeDefClasses++;
    }
    $sharePerClass = ($activeDefClasses > 0) ? 1.0 / $activeDefClasses : 0.25;
    for ($i = 1; $i <= $nbClasses; $i++) {
        if (${'classeDefenseur' . $i}['nombre'] > 0) {
            $defDamageShares[$i] = $degatsAttaquant * $sharePerClass;
        } else {
            $defDamageShares[$i] = 0;
        }
    }
}
```

This correctly skips empty classes and only assigns damage to populated classes. For 2 active
classes, `sharePerClass = 0.5` and each active class gets 50% of damage. Total damage
distributed = 100%. This is correct.

**But:** the FIX comment says "Only split damage among classes that have molecules" — and the
code does this correctly. **No bug here.** Cleared.

---

## GAME-R2-011 — HIGH — Update for Defender Resources in Combat Overwrites Energie From Before Combat Read

**File:** includes/combat.php, lines 592–612

**Execution trace:**

`$ressourcesDefenseur` is fetched at line 360:

```php
$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?',
    's', $actions['defenseur']);
```

This is fetched AFTER `updateRessources($actions['defenseur'])` was called in `updateActions`
(line 79). So the resource snapshot is post-production. Good.

Then pillage amounts are subtracted from `$ressourcesDefenseur[$ressource]`. And the defender
update at lines 593–612 writes back:

```php
$setParams[] = max(0, ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'}));
```

The `energie` column update for the defense reward uses:

```php
$setParams[] = min($maxEnergy, $ressourcesDefenseur['energie'] + $defenseRewardEnergy);
```

Note: `$ressourcesDefenseur['energie']` does NOT have pillage subtracted from it because
the pillage loop only processes `$nomsRes` (the 8 atom types), and `energie` is separate.
So energy is not subject to pillage (correct), and the defense reward adds to energy.

But there is a time-of-check-time-of-use issue: between the time `$ressourcesDefenseur` was
fetched and the time the UPDATE executes, another concurrent request for the defender could
have modified their resources. The UPDATE is not atomic (it reads then writes).

**Severity:** MEDIUM rather than HIGH — race condition requires concurrent requests for the
same defender at the exact moment of combat, which is unlikely but possible.

**Fix:** Use atomic SQL updates rather than read-then-write:

```php
// Instead of read + compute + write, use:
UPDATE ressources SET energie = LEAST(maxEnergy, energie + defenseRewardEnergy) WHERE login=?
UPDATE ressources SET carbone = GREATEST(0, carbone - carbonePille) WHERE login=?
// etc.
```

Or wrap the resource fetch and update in a transaction with a FOR UPDATE lock.

---

## GAME-R2-012 — MEDIUM — updateActions Processes ALL Attacks for $joueur, Including Already-Returned Ones

**File:** includes/game_actions.php, lines 65–68

**Execution trace:**

```php
$ex = dbQuery($base, 'SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=?
    ORDER BY tempsAttaque DESC', 'ss', $joueur, $joueur);

while ($actions = mysqli_fetch_array($ex)) {
    if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) {
```

The query uses `ORDER BY tempsAttaque DESC` but the return-trip block (lines 446–468) checks
`$actions['tempsRetour'] < time()`. For a row where both `attaqueFaite = 1` AND
`tempsRetour < time()`, the first block is skipped but the second block fires and deletes the
row. This is correct.

**However:** the query does NOT filter out rows where `attaqueFaite = 1` AND
`tempsRetour > time()` (attack done, troops in return). For those rows:
- Block 1: `attaqueFaite == 0` is false → skipped
- Block 2: `tempsRetour < time()` is false → skipped

So these rows are fetched, loop iterations occur, but nothing is done. This is just
inefficiency — not a bug. But it does cause unnecessary DB reads per active attack in flight.

**Improvement:** Add `AND (attaqueFaite = 0 OR tempsRetour < ?)` to the query with `time()`
to skip in-flight return trips. Not a bug, marked as optimization.

---

## GAME-R2-013 — MEDIUM — Formation Enqueue Uses Stale actionsformation Row for $tempsDebut

**File:** armee.php, lines 107–117

**Execution trace:**

```php
$ex = dbQuery($base, 'SELECT * FROM actionsformation WHERE login=? ORDER BY fin DESC', 's', $_SESSION['login']);
$nb = mysqli_num_rows($ex);
if ($nb > 0) {
    $actionsformation = mysqli_fetch_array($ex);
    $tempsDebut = $actionsformation['fin'];
} else {
    $tempsDebut = time();
}

$tempsForm = tempsFormation(...);
$finTemps = $tempsDebut + $tempsForm * $_POST['nombremolecules'];
dbExecute($base, 'INSERT INTO actionsformation VALUES(default,?,?,?,?,?,?,?,?)', ...
    $donneesFormer['id'], $_SESSION['login'], $tempsDebut, $finTemps, ...);
```

When multiple formation orders are queued, the new order starts at `$tempsDebut` =
last queue entry's `fin`. This is correct — it chains behind the current queue.

**Issue:** if the player submits two formation requests in rapid succession (double POST or
race condition between two browser tabs), both requests may read the **same** `fin` value for
`$tempsDebut`. Both inserts would have the same `debut`, causing the two batches to run
**concurrently** instead of sequentially. The second batch would overwrite progress intended
for the first.

In `updateActions`, when both formations have the same `debut`:

```php
$ex = dbQuery($base, 'SELECT * FROM actionsformation WHERE login=? AND debut<?', 'si', $joueur, time());
```

Both would be processed simultaneously, potentially crediting molecules from both batches.

**Fix:** Wrap the formation query + insert in a transaction:

```php
mysqli_begin_transaction($base);
$ex = dbQuery($base, 'SELECT fin FROM actionsformation WHERE login=? ORDER BY fin DESC LIMIT 1 FOR UPDATE', 's', $_SESSION['login']);
// ... compute tempsDebut ...
dbExecute($base, 'INSERT INTO actionsformation ...', ...);
mysqli_commit($base);
```

---

## GAME-R2-014 — MEDIUM — molecules Decay Losses Double-Counted in moleculesPerdues for Troops in Transit

**File:** includes/game_actions.php, lines 93–103 (outbound) and 456–462 (return)

**Execution trace:**

During the outbound leg (lines 93–103):

```php
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesRestantes = (pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1]);
    $chaine = $chaine . $moleculesRestantes . ';';

    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues ...');
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=?', 'ds',
        ($molecules[$compteur - 1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), ...);
    $compteur++;
}
```

This records `original - surviving` as lost for the outbound trip. Good.

During the return leg (lines 456–462):

```php
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesRestantes = (pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1]);

    dbExecute($base, 'UPDATE molecules SET nombre=?', 'di',
        ($moleculesProp['nombre'] + $moleculesRestantes), $moleculesProp['id']);

    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues ...');
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=?', 'ds',
        ($molecules[$compteur - 1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), ...);
    $compteur++;
}
```

`$molecules[$compteur - 1]` here is the count of troops that **survived to the attack point**
(after combat losses). `$moleculesRestantes` is the count surviving the return trip. The
difference `$molecules[$compteur-1] - $moleculesRestantes` is decay during return. This is
recorded as additional losses in `moleculesPerdues`. Correct.

But also: in `combat.php` lines 568–569:

```php
dbExecute($base, 'UPDATE autre SET moleculesPerdues=?', 'ds',
    ($pertesAttaquant + $perduesAttaquant['moleculesPerdues']), $actions['attaquant']);
```

`$pertesAttaquant` is the count of troops killed IN COMBAT. This is recorded as additional
losses. Correct.

**The actual double-count path:** During combat.php resolution (within updateActions), the
combat-killed molecules are already recorded in `moleculesPerdues`. Then at the start of
`updateRessources($defender)` called from line 79, the defender's in-base molecules undergo
decay and their decay is also recorded in `moleculesPerdues`. This is correct — they are
separate events.

No double-count bug confirmed. Cleared.

---

## GAME-R2-015 — MEDIUM — tutoriel.php Mission 6 Condition Checks for Reports With LIKE '%spionnage%', Missing 'e'

**File:** tutoriel.php, lines 113–114

**Execution trace:**

```php
$exEspionnage = dbFetchOne($base,
    'SELECT count(*) AS nb FROM rapports WHERE destinataire=? AND titre LIKE ?',
    'ss', $_SESSION['login'], '%spionnage%');
$aEspionne = ($exEspionnage && intval($exEspionnage['nb']) > 0);
```

The espionage report title is set in `updateActions()` at line 343:

```php
$titreRapportJoueur = "Vous espionnez " . htmlspecialchars($actions['defenseur'], ...);
```

The word is "**espionnez**" — which contains "**espionnage**"? Let's check: "espionnez"
does NOT contain "espionnage". The LIKE pattern `%spionnage%` would match "espionnage" but
not "espionnez".

The report title for a failed espionage is at line 428:

```php
$titreRapportJoueur = "Espionnage raté";
```

This DOES contain "spionnage". So successful espionage sets title "Vous espionnez X" and
failed sets "Espionnage raté".

The LIKE `%spionnage%` would match:
- "Espionnage raté" (failed) — YES
- "Vous espionnez X" (successful) — NO ("espionnez" does not contain "spionnage")

**Incorrect behavior:** Mission 6 completion condition fires only for **failed** espionage
reports, not successful ones. A player who successfully spies gets the report title "Vous
espionnez X" which does not match `%spionnage%`. They must fail an espionage attempt to
complete the tutorial mission — backwards from the intent.

**Fix:**

Change the LIKE to match either title:

```php
$exEspionnage = dbFetchOne($base,
    'SELECT count(*) AS nb FROM rapports WHERE destinataire=? AND (titre LIKE ? OR titre LIKE ?)',
    'sss', $_SESSION['login'], '%spionnez%', '%Espionnage%');
```

Or change the mission to check the `image` field which contains `binoculars.png` for all
espionage reports:

```php
$exEspionnage = dbFetchOne($base,
    'SELECT count(*) AS nb FROM rapports WHERE destinataire=? AND image LIKE ?',
    'ss', $_SESSION['login'], '%binoculars%');
```

---

## GAME-R2-016 — MEDIUM — constructeurs.php: Phalange Formation Bonus Display Uses $bonusDuplicateur Not Phalange Constant

**File:** includes/player.php, lines 388–389

**Execution trace:**

In `$listeConstructions` setup:

```php
'champdeforce' => [
    'revenu' => chip('+' . floor($bonusDuplicateur * $constructions['champdeforce'] * 2) . '%', ...),
    'revenu1' => chip('+' . floor($bonusDuplicateur * ($niveauActuelChampDeForce['niveau'] + 1) * 2) . '%', ...),
```

The display multiplies by `$bonusDuplicateur`. But in `combat.php`, the defense calculation is:

```php
$degatsDefenseur += defense(...) * $defBonusForClass * (1 + (($champdeforce['champdeforce'] * 2) / 100))
    * $bonusDuplicateurDefense * ...
```

In combat, the champdeforce bonus is `champdeforce_level * 2 / 100` (2% per level), and
separately multiplied by `$bonusDuplicateurDefense`. The display in player.php shows
`floor($bonusDuplicateur * level * 2) %` — this attempts to show the combined bonus.

`$bonusDuplicateur` in `initPlayer` is set as:

```php
$bonusDuplicateur = 1;
if ($autre['idalliance'] > 0) {
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', ...);
    $bonusDuplicateur = 1 + ($duplicateur['duplicateur'] / 100);
}
```

In combat, `$bonusDuplicateurDefense = 1 + ($duplicateurDefense['duplicateur'] / 100)`.
These are the same formula. The display `floor($bonusDuplicateur * level * 2)` would give
e.g. for level=5, duplicateur=1 (1%): `floor(1.01 * 5 * 2) = floor(10.1) = 10%`.

In combat: `(1 + (5 * 2 / 100)) * 1.01 = 1.10 * 1.01 = 1.111`, meaning ~11.1% bonus total.
The display shows `10%` instead of `11.1%`. The display is inaccurate.

**Incorrect behavior:** The displayed champdeforce defense bonus is `floor(bonusDuplicateur *
level * 2)` but the actual combat bonus is `(level * 2%) * bonusDuplicateur` which is
`bonusDuplicateur * level * 2` percent as an additive step before multiplicative application.
The math is compound multiplication in combat but additive display. The display is a rough
approximation only.

**Fix (display-only):** Show both components separately or use the correct formula:

```php
// Correct display: base bonus + combined with duplicateur
$displayBonus = round((1 + ($constructions['champdeforce'] * 2 / 100)) * $bonusDuplicateur * 100 - 100);
'revenu' => chip('+' . $displayBonus . '%', ...)
```

---

## GAME-R2-017 — MEDIUM — remiseAZero Sets molecules.nombre=0 But Doesn't Reset molecule isotope Field

**File:** includes/player.php, line 802

**Execution trace:**

```php
dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
```

This resets `formule` to "Vide" and `nombre` to 0. But the `molecules` table also has an
`isotope` column (added as a game feature). The isotope type chosen at molecule creation is
not reset by this statement.

After `remiseAZero`, a player's molecule rows have:
- `formule = "Vide"`
- `nombre = 0`
- `isotope = <previous value>` (e.g., ISOTOPE_STABLE = 1)

When the player creates a new molecule class next season, the `UPDATE molecules SET ... WHERE
proprietaire=? AND numeroclasse=?` in armee.php (line 207) sets the new isotope. So existing
isotope values would be overwritten on molecule creation.

**Incorrect behavior if:** A player views molecule details or the isotope is read before the
player creates a new class. In `combat.php`:

```php
$attIso = intval(${'classeAttaquant' . $c}['isotope'] ?? 0);
```

If a player attacks with a "Vide" class (nombre=0), the isotope would still be their old
value. However, empty classes contribute 0 molecules so their combat stats are multiplied by
0 anyway — no practical impact on combat.

**Fix:** Add `isotope=0` to the reset:

```php
dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0, isotope=0');
```

---

## GAME-R2-018 — MEDIUM — constructors.php Point Distribution: Separator Is Always ";" Including Last Element

**File:** constructions.php, lines 24–31 and armee.php lines 122–128

**Execution trace:**

In `constructions.php` (Producteur point distribution):

```php
foreach ($nomsRes as $num => $ressource) {
    $plus = "";
    if ($num - 1 < sizeof($nomsRes)) {
        $plus = ";";
    }
    $chaine = $chaine . ($_POST['nbPoints' . $ressource] + ${'points' . $ressource}) . $plus;
}
```

`$num` is 0-indexed. `sizeof($nomsRes)` = 8. `$num - 1 < 8` is:
- num=0: -1 < 8 → true → adds ";"
- num=7: 6 < 8 → true → adds ";"

The condition is **always true** for all 8 elements. The last element always gets a trailing
semicolon. So `$chaine` = `"val0;val1;val2;val3;val4;val5;val6;val7;"` (trailing semicolon).

When read back in `initPlayer`:

```php
$niveaux = explode(';', $constructions['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
    ${'points' . $ressource} = $niveaux[$num];
}
```

`explode(';', "val0;val1;...;val7;")` produces `['val0','val1',...,'val7','']` — a 9-element
array. The 8 resources are read from indices 0-7 correctly. The trailing empty string at
index 8 is ignored. **No functional bug.**

Same pattern in `condenseur` and in `armee.php`. The trailing semicolon is harmless because
`explode` + index access is safe. Cleared as non-bug.

---

## GAME-R2-019 — MEDIUM — Attack Energy Cost Uses Pre-Battle Molecule Count, Not Post-Battle

**File:** attaquer.php, lines 135–139

**Execution trace:**

```php
$nbAtomes = 0;
foreach ($nomsRes as $num => $res) {
    $nbAtomes += $moleculesAttaque[$res];
}
$cout += $_POST['nbclasse' . $c] * $coutPourUnAtome * $nbAtomes;
```

The energy cost is computed from `$_POST['nbclasse']` (requested troops) × cost per atom.
`$coutPourUnAtome = 0.15 * (1 - $bonus / 100)`.

Energy is deducted atomically via `ajouter('energie', 'ressources', -$cout, ...)` at line
156. The `ajouter()` function uses atomic SQL `UPDATE ressources SET energie = energie + ?`.

But `$ressources['energie']` is checked for sufficiency at line 144:

```php
if ($cout <= $ressources['energie']) {
```

`$ressources` was populated by `initPlayer` which runs via `constantes.php` → `tout.php`.
This is the player's energy at page-load time. If between page-load and form submission the
player's energy changed (another tab, resource production tick), the check could allow an
attack with insufficient energy. `ajouter()` would then set `energie = energie - $cout`
potentially going negative (no floor in `ajouter`).

**Incorrect behavior:** Energy can go negative if energy changed between check and update.

**Fix:** Use atomic deduct-with-floor in SQL:

```php
dbExecute($base, 'UPDATE ressources SET energie = GREATEST(0, energie - ?) WHERE login=? AND energie >= ?',
    'dsd', $cout, $_SESSION['login'], $cout);
if (mysqli_affected_rows($base) === 0) {
    $erreur = "Pas assez d'énergie.";
    // skip the attack
}
```

---

## GAME-R2-020 — MEDIUM — Battle Report: $milieuAttaquant Block Displays Defender Data in Attacker Section

**File:** includes/game_actions.php, lines 253–276

**Execution trace:**

After the `$milieuDefenseur` string is built (lines 207–230) showing defender's class headers
and troop/casualty data:

```php
$milieuAttaquant = "
    <th>" . couleurFormule($classeDefenseur1['formule']) . "</th>   // BUG: uses Defenseur
    <th>" . couleurFormule($classeDefenseur2['formule']) . "</th>   // BUG: uses Defenseur
    <th>" . couleurFormule($classeDefenseur3['formule']) . "</th>   // BUG: uses Defenseur
    <th>" . couleurFormule($classeDefenseur4['formule']) . "</th>   // BUG: uses Defenseur
    ...
    <td>" . $classeDefenseur1['nombre'] . "</td>  // BUG: uses Defenseur
    <td>" . $classeDefenseur2['nombre'] . "</td>  // BUG: uses Defenseur
    ...
    <td>" . $classe1DefenseurMort . "</td>         // BUG: uses Defenseur
```

`$milieuAttaquant` is the section that goes into the **attacker's report** (after
`$debutRapport`). The attacker sees their own view in `$contenuRapportAttaquant =
$debutRapport . $milieuAttaquant . $finRapport`.

But `$milieuAttaquant` is built using `$classeDefenseur*` variables — the **defender's**
molecule formulas and troop counts. This is the "what did the attacker see of the enemy"
section — from the attacker's perspective, this section should show the defender's data
(what they faced). This is intentional fog-of-war design: the attacker sees what they fought.

Wait — `$debutRapport` already contains the attacker's own stats (classes 1-4 with
`$classeAttaquant1` etc.). Then `$milieuAttaquant` appends defender class headers. The
structure for the attacker's report is:
1. `$debutRapport`: attacker table with formulas + troops + losses
2. `$milieuAttaquant`: defender table headers (class formulas) — but uses `$classeDefenseur`
3. `$finRapport`: pillaged resources + buildings

This looks intentional: the attacker sees the defender's molecule formulas and casualties.

**However**, the block is confusingly named `$milieuAttaquant` but contains defender data.
And it renders in the attacker's own battle report. This IS the intended behavior — the
attacker's report ends with defender info. But the variable name is misleading.

More importantly: at lines 232–246, when `$attaquantsRestants == 0`, the defender's data
is replaced with `"?"`:

```php
if ($attaquantsRestants == 0) {
    $classeDefenseur1['formule'] = "?";
    // etc.
    $classeDefenseur1['nombre'] = "?";
    // etc.
```

These replacements happen **after** `$milieuDefenseur` is already built (lines 207–230) but
**before** `$milieuAttaquant` is built (lines 253–276). So `$milieuAttaquant` correctly gets
the "?" values (unknown) while `$milieuDefenseur` has the real values for the defender's
report. This is correct.

**Actual bug:** `$milieuDefenseur` is assigned on line 207 **before** the zero-attacker check.
The defender's report (`$contenuRapportDefenseur = $debutRapport . $milieuDefenseur .
$finRapport`) shows the real defender data — which is correct.

Wait, there is still a naming confusion issue: `$contenuRapportAttaquant` uses
`$milieuAttaquant` (which contains defender data as seen by attacker), and
`$contenuRapportDefenseur` uses `$milieuDefenseur` (which contains defender data for
defender's own view). Both contain defender class data in their second section, which is
correct — both players see the defender side of the table. The difference is the first
`$debutRapport` section which is identical for both (attacker data).

This means: both the attacker's report and the defender's report have the same attacker
section AND the same defender section. The only difference should be in which context the
report is received. This is likely correct but means the attacker can see the full defender
data (molecule counts, formulas, casualties) which may be an information exposure issue for
fog of war, though it's gated behind the zero-survivor check.

**This is a design issue not a bug.** Cleared.

---

## GAME-R2-021 — MEDIUM — VP Formula Constants Mismatch Between Config Comment and Code

**File:** includes/config.php, lines 469–470 and includes/formulas.php, lines 28–31

**Execution trace:**

Config defines:

```php
define('VP_PLAYER_RANK51_100_BASE', 3);
define('VP_PLAYER_RANK51_100_STEP', 0.04);
```

Config comment says:
```
// Ranks 51-100: max(1, floor(15 - (rank - 20) * 0.15))
```

But the constant `VP_PLAYER_RANK51_100_BASE` = 3 and `VP_PLAYER_RANK51_100_STEP` = 0.04
produce: `max(1, floor(3 - (rank - 50) * 0.04))`.

For rank 51: `max(1, floor(3 - 1 * 0.04)) = max(1, floor(2.96)) = max(1, 2) = 2` VP.

The comment says rank 51-100 should use `max(1, floor(15 - (rank - 20) * 0.15))`.
For rank 51: `max(1, floor(15 - 31 * 0.15)) = max(1, floor(15 - 4.65)) = max(1, 10) = 10` VP.

**The comment is wrong** (it describes the old formula before the constants were introduced),
or **the constants are wrong** (set too low relative to the comment). The code uses the
constants:

```php
if ($classement <= 100) {
    return max(1, floor(VP_PLAYER_RANK51_100_BASE - ($classement - 50) * VP_PLAYER_RANK51_100_STEP));
}
```

With BASE=3, STEP=0.04, rank 100 gives: `max(1, floor(3 - 50*0.04)) = max(1, floor(1)) = 1`.
With BASE=3, STEP=0.04, rank 51 gives: ~2 VP.

The gap between rank 50 (which gets VP from the 21-50 formula) and rank 51 may be jarring.
For rank 50: `max(1, floor(15 - 30*0.5)) = max(1, floor(0)) = 1` VP.
For rank 51: 2 VP. Rank 51 gets MORE than rank 50 — **inverted ranking incentive!**

**Incorrect behavior:** Players ranked 51-53 get more victory points than players ranked
48-50. Specifically:
- Rank 50: `max(1, floor(15 - 30*0.5)) = max(1, 0) = 1`
- Rank 51: `max(1, floor(3 - 1*0.04)) = max(1, 2) = 2`
- Rank 52: `max(1, floor(3 - 2*0.04)) = max(1, 2) = 2`

A player ranked 50 gets fewer VP than a player ranked 51. This inverts the leaderboard
incentive for this range.

**Fix:**

Adjust `VP_PLAYER_RANK51_100_BASE` to match or continue from the rank 50 floor:

Rank 50 from rank 21-50 formula: `max(1, floor(15 - 30*0.5)) = 1`.
So rank 51 should get <= 1 VP, meaning these players get 0 VP (or 1 as floor). The simplest
fix is to set `VP_PLAYER_RANK51_100_BASE = 1` and `VP_PLAYER_RANK51_100_STEP = 0`:

```php
define('VP_PLAYER_RANK51_100_BASE', 1);
define('VP_PLAYER_RANK51_100_STEP', 0);
// All ranks 51-100 get 1 VP (same as max(1, ...) floor)
```

Or set the 51-100 range to return 0 (only top-50 get VP beyond 0), which matches most
strategy game conventions. Update `pointsVictoireJoueur`:

```php
if ($classement <= 50) {
    return max(1, floor(VP_PLAYER_RANK21_50_BASE - ($classement - 20) * VP_PLAYER_RANK21_50_STEP));
}
// Ranks 51+: 0 VP
return 0;
```

---

## GAME-R2-022 — MEDIUM — Decay Coefficient: $nbsecondes Used in Seconds But coefDisparition Returns Per-Second Decay

**File:** includes/game_resources.php, line 174

**Execution trace:**

```php
$moleculesRestantes = (pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre']);
```

`$nbsecondes = time() - $donnees['tempsPrecedent']` — this is the elapsed time in **seconds**.

`coefDisparition()` returns a base coefficient, e.g. `0.9999943`. Raising this to `$nbsecondes`
gives decay over that many seconds. For example, over 3600 seconds: `0.9999943^3600 ≈ 0.812`.

This is mathematically a per-second decay model. But what does `coefDisparition` actually
compute?

```php
$baseDecay = pow(pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR),
    (1 - ($bonus / 100)) * (1 - ($stabilisateur['stabilisateur'] * STABILISATEUR_BONUS_PER_LEVEL)));
```

`DECAY_BASE = 0.99`, `DECAY_ATOM_DIVISOR = 150`, `DECAY_POWER_DIVISOR = 25000`.

For a simple molecule with 1 atom: `pow(1 + 1/150, 2) / 25000 ≈ 0.00004044`.
`pow(0.99, 0.00004044) ≈ 0.9999996` (extremely small decay per second, ~99.99996% survival
per second).

Over 3600 seconds (1 hour): `0.9999996^3600 ≈ 0.9856` → ~1.4% loss per hour.

For a molecule with 200 atoms of one type (max): `pow(1 + 200/150, 2) / 25000 ≈ 0.00064`.
`pow(0.99, 0.00064) ≈ 0.9999936`. Over 3600s: `0.9999936^3600 ≈ 0.7926` → ~20.7% loss per hour.

This looks reasonable. The model is consistent. **No bug.**

However, `$nbsecondes` used in `updateRessources` vs `game_actions.php` outbound trip also
uses `$nbsecondes` in seconds. Both use the same model consistently. **Cleared.**

---

## GAME-R2-023 — LOW — constructors.php: Time Remaining Display for Formation Uses Division Without Zero Check

**File:** includes/player.php, line 333

**Execution trace:**

In the generateur `effetSup`:

```php
'effetSup' => '...<strong>Stockage plein : </strong>'
    . date('d/m/Y', time() + 3600 * ($placeDepot - $ressources['energie']) / $revenu['energie'])
    . ' à ' . date('H\hi', time() + 3600 * ($placeDepot - $ressources['energie']) / $revenuEnergie),
```

`$revenu['energie']` is the net energy production per hour. If the generateur's energy
production is less than the producteur's drainage, `revenuEnergie()` can return a negative
or zero value.

`revenuEnergie()` final line: `return round($prodProducteur)` where:
`$prodProducteur = $prodPrestige - drainageProducteur($producteur['producteur'])`.

If `drainageProducteur > prodPrestige`, `revenuEnergie < 0`. In that case:

```
($placeDepot - $ressources['energie']) / $revenu['energie']
```

With energy < max: `($placeDepot - energy)` > 0.
With `$revenu['energie']` < 0: result is negative.
`time() + 3600 * negative` = timestamp far in the past → `date()` shows a date in the past.

If `$revenu['energie'] = 0` exactly: division by zero, PHP warning, `date()` gets `INF`.

**Incorrect behavior:** The "stockage plein" date is nonsensical when net energy production is
zero or negative.

**Fix:**

```php
'effetSup' => ($revenu['energie'] > 0)
    ? '...<strong>Stockage plein : </strong>'
      . date('d/m/Y', time() + 3600 * ($placeDepot - $ressources['energie']) / $revenu['energie'])
    : '...<strong>Production d\'énergie négative ou nulle</strong>',
```

Similarly the producteur `effetSup` at line 352 uses `$max` which could be very large or
negative if any `$revenu[$ressource] = 0`.

---

## GAME-R2-024 — LOW — Catalyst Weekly Rotation Uses Calendar Week + Year, Vulnerable to Year-Change Collision

**File:** includes/catalyst.php, line 61

**Execution trace:**

```php
$currentWeek = intval(date('W')) + intval(date('Y')) * 100;
```

`date('W')` is ISO week number 1-53. `date('Y')` is the 4-digit year.

Example: Week 1 of 2026 → `1 + 2026 * 100 = 202601`. Week 52 of 2025 → `52 + 2025 * 100 = 202552`.

Problem: ISO week 1 of a new year can actually start in December of the previous year. For
example, 2015-12-28 was ISO week 53 of 2015. But 2016-01-04 was ISO week 1 of 2016.

More critically: Week 52 of 2026 = `202652`. Week 1 of 2027 = `202701`. The catalyst changes
as expected.

BUT: `date('Y')` may not match `date('o')` (ISO year). For example, 2016-01-03 has
`date('W') = 53` but `date('Y') = 2016` giving `2016 * 100 + 53 = 201653`. The following
week 2016-01-04 has `date('W') = 1` and `date('Y') = 2016` giving `201601`. These are
different IDs, so the catalyst changes. **No collision on transition.**

**The actual collision case:** If two different years both have the same week number at the
same time is impossible by definition. But week 53 only exists in some years. If last week
of one year is week 53 (`202553`) and the first week of next year is week 1 (`202601`), the
catalyst correctly changes.

**No collision bug.** But there is a subtle issue: `date('Y')` gives the Gregorian year, not
the ISO week year. Use `date('o')` for ISO week year to be safe:

```php
$currentWeek = intval(date('W')) + intval(date('o')) * 100;
```

This is a low-severity correctness improvement, not a bug in practice.

---

## GAME-R2-025 — LOW — armee.php: Max Molecules Display (nbmoleculesMax) Excludes Molecules Already In Formation Queue

**File:** armee.php, lines 356–360

**Execution trace:**

```php
$nbmoleculesMax = PHP_INT_MAX;
foreach ($nomsRes as $num => $ressource) {
    if ($molecule[$ressource] > 0) {
        $nbmoleculesMax = min($nbmoleculesMax, floor($ressources[$ressource] / $molecule[$ressource]));
    }
}
```

`$ressources` is the current on-hand atoms. But atoms in the formation queue are already
deducted from `ressources` at the time of formation insertion (armee.php line 128-138). So
the display correctly shows how many MORE can be formed with current atoms. No bug here.

However: the `$ressources` variable may be stale. It's set at line 93:
`$ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);`

This re-fetch is inside the `emplacementmoleculeformer` POST handler. The display section
uses the global `$ressources` from `initPlayer` (before the re-fetch). The JS "Max" button
at line 369 uses `$nbmoleculesMax` which was computed from the re-fetched `$ressources` in
the POST handler. But on a non-POST page load, `$ressources` comes from `initPlayer`. Both
are recent-enough reads. **No material bug.**

---

## GAME-R2-026 — LOW — Missing attack_cooldowns Table Cleanup in supprimerJoueur

**File:** includes/player.php, lines 752–778

**Execution trace:**

`supprimerJoueur` deletes rows from 13 tables. The `attack_cooldowns` table exists (created
and referenced in combat.php lines 338–340 and attaquer.php line 71). When a player is
deleted, their cooldown rows in `attack_cooldowns` are not cleaned up.

Specifically, cooldowns where `attacker = $joueur` or `defender = $joueur` remain. These
orphaned rows are harmless (they reference a now-deleted player) but accumulate over time
and would affect any new player who registered with the same login name (if allowed).

**Fix:**

```php
// In supprimerJoueur, inside the withTransaction block:
dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?',
    'ss', $joueur, $joueur);
```

---

## GAME-R2-027 — LOW — remiseAZero Resets molecules.formule But Not molecules.isotope and No pointsCondenseur Reset for All Atoms

**File:** includes/player.php, line 800

**Execution trace:**

```php
dbExecute($base, 'UPDATE constructions SET ... pointsCondenseur=default, pointsCondenseurRestants=default ...');
```

`pointsCondenseur=default` resets the entire condenseur points string to the database default
(likely `"0;0;0;0;0;0;0;0;"`). This correctly clears all condenseur atom upgrades.

`pointsProducteur=default` resets atom production distribution. **Correct.**

The `molecules` table reset (line 802): `UPDATE molecules SET formule="Vide", nombre=0`.
As noted in GAME-R2-017, `isotope` is not reset.

Additionally, the `constructions` reset does not explicitly reset `formation` to 0 —
but `formation=0` IS in the UPDATE at line 800. **Correct for formation.**

`coffrefort=0` is also in the UPDATE. **Correct.**

The reset looks complete except for `molecules.isotope`. Severity: LOW, same fix as
GAME-R2-017.

---

## GAME-R2-028 — LOW — Chemical Reaction 'Halogénation' Applies Speed Bonus to combat.php But Combat Has No Speed Path

**File:** includes/config.php, lines 329–332 and includes/combat.php

**Execution trace:**

`$CHEMICAL_REACTIONS['Halogénation']` grants `['speed' => 0.20]` bonus. In combat.php:

```php
foreach ($activeReactionsAtt as $name => $bonuses) {
    if (isset($bonuses['attack'])) $attReactionAttackBonus += $bonuses['attack'];
    if (isset($bonuses['hp'])) $attReactionHpBonus += $bonuses['hp'];
    if (isset($bonuses['pillage'])) $attReactionPillageBonus += $bonuses['pillage'];
    if (isset($bonuses['defense'])) $attReactionAttackBonus += 0; // no-op
}
foreach ($activeReactionsDef as $name => $bonuses) {
    if (isset($bonuses['defense'])) $defReactionDefenseBonus += $bonuses['defense'];
    if (isset($bonuses['hp'])) $defReactionHpBonus += $bonuses['hp'];
    if (isset($bonuses['attack'])) $defReactionDefenseBonus += 0; // no-op
}
```

There is no handling for `$bonuses['speed']`. The 'Halogénation' reaction gives a +20% speed
bonus (`'bonus' => ['speed' => 0.20]`) which is **completely ignored** in combat. The bonus
description says "+20% fleet speed" but this is never applied anywhere in the codebase
(neither in combat.php nor in attaquer.php travel time calculation).

**Incorrect behavior:** Players who achieve the Halogénation reaction condition (Cl>=80 +
I>=80) receive no actual game benefit. The reaction is detected, displayed in the battle
report, but has zero effect.

**Fix:**

Either implement the speed effect in the attack submission (attaquer.php travel time):

```php
// In attaquer.php, when computing tempsTrajet:
// Check if attacker has active Halogénation reaction and apply speed bonus
$speedBonus = 1.0;
// ... check class atom conditions for Halogénation ...
$tempsTrajet = max($tempsTrajet, round($distance / (vitesse(...) * $speedBonus) * 3600));
```

Or change the bonus to something that IS implemented, like attack or pillage:

```php
// In config.php:
'Halogénation' => [
    'condA' => ['chlore' => 80],
    'condB' => ['iode' => 80],
    'bonus' => ['pillage' => 0.20],  // Change to a supported effect
    'description' => 'Cl>=80 + I>=80 : +20% pillage',
],
```

---

## GAME-R2-029 — LOW — inscrire() Uses $timestamps = "$now,$now,$now,$now" But autre INSERT Has Only 3 Timestamp Columns

**File:** includes/player.php, lines 56 and 62

**Execution trace:**

```php
$timestamps = $now . ',' . $now . ',' . $now . ',' . $now;
// ...
dbExecute($base, 'INSERT INTO autre VALUES(?, default, default, "Pas de description", ?, default, default, default, default, default, default, default, default, default, default, ?, default, default, default, default, "", default)',
    'sis', $safePseudo, $now, $timestamps);
```

`$timestamps` is a comma-separated string of 4 values. The INSERT uses type `'sis'` (string,
int, string). The third bound parameter is `$timestamps` which is bound as a single string
`'1234567890,1234567890,1234567890,1234567890'`.

This string goes into a single `?` placeholder, not 4 separate columns. This would insert
the literal string `"1234567890,1234567890,1234567890,1234567890"` into one column.

Looking at the `autre` table structure from context: the INSERT has 21 value positions.
The `?` after `default, default, ?, default, ...` at position ~17 (the `$timestamps` binding)
appears to map to a single column that stores comma-separated timestamps. This is the
`missions` field or a similar multi-value field.

Cross-referencing with `remiseAZero()` line 799: `missions=''` is reset. In registration,
the empty `""` literal near the end of the INSERT suggests `missions` is set to empty.
The `$timestamps` column is actually `datesinscription` or similar, which stores multiple
dates as a comma-separated string (a design choice, not a bug in itself).

**No functional bug** — the `$timestamps` is intentionally a single string value with
commas, mapping to a single column. Cleared.

---

## GAME-R2-030 — LOW — revenuAtomeJavascript() Omits prestigeProductionBonus from JavaScript Formula

**File:** includes/game_resources.php, lines 96–103

**Execution trace:**

```php
function revenuAtomeJavascript($joueur)
{
    // ...
    echo '
    <script>
    function revenuAtomeJavascript(niveau){
        return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*niveau);
    }
    </script>
    ';
}
```

The server-side `revenuAtome()` is:

```php
return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur));
```

`prestigeProductionBonus()` returns 1.05 for players with the 'experimente' prestige unlock.
The JavaScript formula omits this factor, so the displayed "per point" production preview on
the constructions page is 5% lower than actual production for prestige players.

**Incorrect behavior:** The producteur point allocation UI shows inaccurate production
estimates for players with the 'experimente' prestige unlock.

**Fix:**

```php
$prestigeMult = prestigeProductionBonus($joueur);
echo '
<script>
function revenuAtomeJavascript(niveau){
    return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*' . $prestigeMult . '*niveau);
}
</script>
';
```

---

## Summary Table

| ID | Severity | File | Description |
|----|----------|------|-------------|
| GAME-R2-001 | CRITICAL | game_actions.php:446 | Return trip fires same request as combat → double molecule credit |
| GAME-R2-002 | CRITICAL | armee.php:116 | Formation time uses uninitialized $niveauazote = 0 |
| GAME-R2-003 | HIGH | game_actions.php:77 | Defender initPlayer cache not invalidated after combat DB writes |
| GAME-R2-004 | HIGH | game_actions.php:446 | Return trip and combat block not mutually exclusive (same root as 001) |
| GAME-R2-005 | LOW | formulas.php:45 | Alliance VP formula uses misleading constant name, functionally correct |
| GAME-R2-006 | CLEARED | armee.php:116 | Formation time linearity — no bug |
| GAME-R2-007 | HIGH | combat.php:277 | Phalange with empty class 1 absorbs 70% damage with 0 molecules |
| GAME-R2-008 | CLEARED | player.php:508 | augmenterBatiment points calculation — correct |
| GAME-R2-009 | HIGH | player.php:793 | remiseAZero does not clear _initPlayerCache |
| GAME-R2-010 | CLEARED | combat.php:263 | Dispersee formation correctly skips empty classes |
| GAME-R2-011 | MEDIUM | combat.php:592 | Defender resource update is non-atomic, race condition window |
| GAME-R2-012 | LOW | game_actions.php:65 | Unnecessary loop iterations for in-flight return trips |
| GAME-R2-013 | MEDIUM | armee.php:107 | Formation queue enqueue not transactional, double-submit race |
| GAME-R2-014 | CLEARED | game_actions.php:93 | Decay loss accounting — correct |
| GAME-R2-015 | MEDIUM | tutoriel.php:113 | Mission 6 LIKE pattern matches failed spy reports only, not successful |
| GAME-R2-016 | MEDIUM | player.php:388 | Champdeforce display bonus formula inaccurate vs combat formula |
| GAME-R2-017 | MEDIUM | player.php:802 | remiseAZero does not reset molecules.isotope column |
| GAME-R2-018 | CLEARED | constructions.php:24 | Trailing semicolon harmless |
| GAME-R2-019 | MEDIUM | attaquer.php:144 | Attack energy check is non-atomic, can go negative |
| GAME-R2-020 | CLEARED | game_actions.php:253 | milieuAttaquant uses defender data intentionally |
| GAME-R2-021 | MEDIUM | config.php:469 | VP rank 50-51 inversion: rank 51 earns more VP than rank 50 |
| GAME-R2-022 | CLEARED | game_resources.php:174 | Decay model consistent in seconds |
| GAME-R2-023 | LOW | player.php:333 | Division by zero risk when net energy revenue <= 0 |
| GAME-R2-024 | LOW | catalyst.php:61 | Use date('o') not date('Y') for ISO week year |
| GAME-R2-025 | CLEARED | armee.php:356 | Max molecules display — correct |
| GAME-R2-026 | LOW | player.php:752 | supprimerJoueur misses attack_cooldowns cleanup |
| GAME-R2-027 | LOW | player.php:800 | remiseAZero does not reset molecules.isotope (same as 017) |
| GAME-R2-028 | LOW | config.php:329 | Halogénation speed bonus defined but never applied in any code path |
| GAME-R2-029 | CLEARED | player.php:56 | $timestamps is a legitimate single-column comma string |
| GAME-R2-030 | LOW | game_resources.php:96 | JS production preview missing prestigeProductionBonus multiplier |

---

## Bugs Requiring Immediate Fixes (CRITICAL/HIGH)

1. **GAME-R2-001**: Change second `if` to `elseif` in `updateActions` return-trip block.
2. **GAME-R2-002**: Fetch `pointsCondenseur` directly inside armee.php formation POST handler.
3. **GAME-R2-003**: Call `invalidatePlayerCache` for both players after combat.php include.
4. **GAME-R2-007**: Redistribute Phalange damage when class 1 is empty.
5. **GAME-R2-009**: Add `$GLOBALS['_initPlayerCache'] = []` at start of `remiseAZero()`.

## Bugs Requiring Near-Term Fixes (MEDIUM)

6. **GAME-R2-011**: Wrap defender resource deduction in transaction with FOR UPDATE.
7. **GAME-R2-013**: Wrap formation queue insert in transaction.
8. **GAME-R2-015**: Fix tutoriel.php mission 6 LIKE pattern to detect successful espionage.
9. **GAME-R2-019**: Make attack energy deduction atomic using SQL conditional UPDATE.
10. **GAME-R2-021**: Adjust VP rank 51-100 constants to prevent rank inversion.

## Bugs for Backlog (LOW)

11. **GAME-R2-017/027**: Add `isotope=0` to `remiseAZero` molecule reset.
12. **GAME-R2-023**: Guard division by zero for energy revenue display.
13. **GAME-R2-026**: Add `attack_cooldowns` cleanup in `supprimerJoueur`.
14. **GAME-R2-028**: Implement or replace Halogénation speed bonus.
15. **GAME-R2-030**: Add `prestigeProductionBonus` factor to JS production preview.
