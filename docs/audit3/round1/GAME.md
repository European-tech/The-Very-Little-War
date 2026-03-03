# Game Logic Audit - Round 1

**Auditor:** Claude Opus 4.6
**Date:** 2026-03-03
**Scope:** 28 files across includes/, pages, and alliance systems
**Focus:** Combat formulas, resource production, building mechanics, army formation,
molecule composition, prestige, market manipulation, alliance research, catalysts,
vacation mode, season reset, beginner protection, decay formulas, trade points,
isotope/formation/reaction bugs.

---

## Findings Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 8     |
| MEDIUM   | 12    |
| LOW      | 8     |
| **Total**| **30**|

---

## CRITICAL

### [GAME-R1-001] [CRITICAL] includes/update.php:21 -- Non-atomic tempsPrecedent update allows double resource production for attack targets

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
**Line:** 21

The function `updateTargetResources()` updates `tempsPrecedent` without the atomic
compare-and-swap guard that `updateRessources()` uses.

```php
// update.php line 21 -- VULNERABLE
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $targetPlayer);

// game_resources.php line 120-123 -- CORRECT atomic pattern
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
if (mysqli_affected_rows($base) === 0) {
    return; // Another request already updated
}
```

If two concurrent attack actions target the same defender, both calls to
`updateTargetResources()` will read the same `tempsPrecedent`, then both will
unconditionally overwrite it. Both will then produce resources for the full elapsed
time, doubling the defender's resource gains.

**Exploit scenario:** An attacker launches two attacks timed to arrive at the same
second against the same defender. Both combat resolution threads call
`updateTargetResources()`, and the defender receives double resource production.

**Fix:** Add the same atomic CAS pattern used in `updateRessources()`:
```php
$oldTime = $adversaire['tempsPrecedent'];
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?',
    'isi', time(), $targetPlayer, $oldTime);
if (mysqli_affected_rows($base) === 0) {
    return; // Another concurrent request already updated
}
```

---

### [GAME-R1-002] [CRITICAL] includes/player.php:538,619 -- augmenterBatiment/diminuerBatiment invalidate wrong player's cache

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 538, 619

Both `augmenterBatiment()` and `diminuerBatiment()` accept a `$joueur` parameter but
end by invalidating `$_SESSION['login']`'s cache instead of `$joueur`'s cache.

```php
// Line 508: function signature
function augmenterBatiment($nom, $joueur) {
    // ...line 513: correctly invalidates $joueur
    invalidatePlayerCache($joueur);
    initPlayer($joueur);
    // ... building upgrade logic for $joueur ...

    // Line 538-539: BUG -- invalidates/reinits $_SESSION['login'] instead of $joueur
    invalidatePlayerCache($_SESSION['login']);
    initPlayer($_SESSION['login']);
}
```

During combat resolution in `game_actions.php`, `diminuerBatiment()` is called on the
**defender** (`$actions['defenseur']`). But the function then reinitializes
`$_SESSION['login']` (the attacker or whoever triggered the update). This means:

1. The defender's cache is correctly invalidated at the START of the function (line 553)
   but then the ATTACKER's cache is needlessly rebuilt at the END (line 619).
2. If `$_SESSION` is not set (e.g., during cron or background processing), this will
   throw an undefined index error.
3. The `$joueur`'s globals (`$constructions`, `$points`, etc.) get overwritten with
   `$_SESSION['login']`'s data, potentially corrupting subsequent combat calculations
   for the same request.

**Impact:** During combat, if the defender loses a building level, the attacker's cached
data overwrites the defender's globals. Subsequent building damage calculations in the
same combat resolution may use the attacker's building stats instead of the defender's.

**Fix:** Replace `$_SESSION['login']` with `$joueur` on lines 538-539 and 619-620:
```php
invalidatePlayerCache($joueur);
initPlayer($joueur);
```

---

## HIGH

### [GAME-R1-003] [HIGH] includes/update.php:25-27 -- Stale revenuenergie used for target resource calculation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
**Lines:** 25-27

`updateTargetResources()` reads `revenuenergie` from the `ressources` table, which is
a cached/stored value. The actual function `revenuEnergie()` in `game_resources.php`
calculates it fresh from building levels, medals, duplicateur, iode, prestige, and
catalyst effects.

```php
// update.php line 25-27 -- uses stale cached value
$donnees = dbFetchOne($base, 'SELECT energie, revenuenergie FROM ressources WHERE login=?', 's', $targetPlayer);
$energie = $donnees['energie'] + round($donnees['revenuenergie'] * $nbsecondesAdverse / 3600);
```

Compare with `updateRessources()` in `game_resources.php` line 130:
```php
// game_resources.php line 130 -- calculates fresh
$revenuenergie = revenuEnergie($depot['generateur'], $joueur);
```

If the defender's buildings, alliance, or prestige changed since the cached
`revenuenergie` was last written, the attack target's resources will be calculated
incorrectly. This systematically undervalues or overvalues the defender's resources
at the time of combat.

**Fix:** Call `revenuEnergie()` with the defender's generator level instead of reading
the stale cached column.

---

### [GAME-R1-004] [HIGH] includes/combat.php:277-283 -- Phalange formation wastes damage on empty molecule classes 2-4

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 277-283

When the defender uses Phalange formation, 30% of attacker damage is split equally
among classes 2, 3, and 4 regardless of whether those classes have molecules.

```php
} elseif ($defenderFormation == FORMATION_PHALANGE) {
    $defDamageShares[1] = $degatsAttaquant * FORMATION_PHALANX_ABSORB; // 70%
    $remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / max(1, $nbClasses - 1); // 10% each
    for ($i = 2; $i <= $nbClasses; $i++) {
        $defDamageShares[$i] = $degatsAttaquant * $remainingShare; // 10% to EACH, even empty
    }
}
```

If a defender only has molecules in classes 1 and 2, then 20% of attacker damage goes
to empty classes 3 and 4, which means 20% of attacker damage is wasted (kills zero
molecules). This makes Phalange formation strictly better than intended: it effectively
reduces incoming damage by wasting it on empty slots.

**Exploit:** A player intentionally keeps only 2 classes active and uses Phalange. 20%
of incoming damage is absorbed by the void, making the defender significantly harder
to kill.

**Fix:** Only distribute the remaining 30% among non-empty classes 2-4:
```php
$activeRearClasses = 0;
for ($i = 2; $i <= $nbClasses; $i++) {
    if (${'classeDefenseur' . $i}['nombre'] > 0) $activeRearClasses++;
}
if ($activeRearClasses > 0) {
    $remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / $activeRearClasses;
    for ($i = 2; $i <= $nbClasses; $i++) {
        $defDamageShares[$i] = (${'classeDefenseur' . $i}['nombre'] > 0)
            ? $degatsAttaquant * $remainingShare : 0;
    }
} else {
    // Only class 1 has molecules -- it takes all damage
    $defDamageShares[1] = $degatsAttaquant;
}
```

---

### [GAME-R1-005] [HIGH] attaquer.php:117-122 -- Fractional molecule exploit allows sending more molecules than owned

**File:** `/home/guortates/TVLW/The-Very-Little-War/attaquer.php`
**Lines:** 117-122

The troop validation uses `ceil()` for the comparison but the actual value for the
silent reduction.

```php
if (ceil($moleculesAttaque['nombre']) < $_POST['nbclasse' . $c]) {
    $bool = 0; // reject: player requests more than ceil(nombre)
}

if ($moleculesAttaque['nombre'] < $_POST['nbclasse' . $c]) {
    $_POST['nbclasse' . $c] = $moleculesAttaque['nombre']; // silently reduce to nombre (fractional!)
}
```

Consider: a player has 99.3 molecules. `ceil(99.3) = 100`. If they send 100:
- Line 117: `ceil(99.3)` = 100, which is NOT < 100, so `$bool` stays 1 (pass).
- Line 121: `99.3 < 100` is true, so POST is silently set to `99.3`.
- Result: The player sends 99.3 molecules but the UI showed 100 and the POST was 100.

The troops string becomes `"99.3;"` which gets `ceil()`'d to 100 in combat.php line 21:
```php
${'classeAttaquant' . $c}['nombre'] = ceil($chaineExplosee[$c - 1]);
```

This means the attacker effectively gets `ceil(99.3) = 100` molecules in combat while
only losing 99.3 from their stockpile. Over many attacks with fractional molecules
across 4 classes, this gives a consistent small advantage.

**Fix:** Use `floor()` for the troop count instead of allowing fractional values:
```php
$_POST['nbclasse' . $c] = floor($moleculesAttaque['nombre']);
```

---

### [GAME-R1-006] [HIGH] includes/combat.php:49 -- Duplicateur combat bonus uses raw level/100 instead of bonusDuplicateur() function

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 49, 56

The combat damage calculation uses `$duplicateur['duplicateur'] / 100` for the
duplicateur bonus, while resource production uses `bonusDuplicateur()` which is
defined as `$niveau * DUPLICATEUR_BONUS_PER_LEVEL` (= `$niveau * 0.01`).

```php
// combat.php line 49
$bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);

// formulas.php line 69-72
function bonusDuplicateur($niveau) {
    return $niveau * DUPLICATEUR_BONUS_PER_LEVEL; // 0.01 per level
}
```

Currently these produce the same result (`level / 100` = `level * 0.01`). However,
this is a maintenance hazard: if `DUPLICATEUR_BONUS_PER_LEVEL` is changed in config.php,
the combat bonus will NOT be updated, creating an inconsistency between resource
production and combat bonuses.

**Fix:** Use `bonusDuplicateur()` consistently:
```php
$bonusDuplicateurAttaque = 1 + bonusDuplicateur($duplicateurAttaque['duplicateur']);
```

---

### [GAME-R1-007] [HIGH] don.php:1-56 -- No vacation mode check allows energy donation while on vacation

**File:** `/home/guortates/TVLW/The-Very-Little-War/don.php`
**Line:** 1-3

The file `don.php` includes `basicprivatephp.php` but does NOT include
`redirectionVacance.php`. Compare with other action pages:

```php
// attaquer.php, armee.php, marche.php, constructions.php all include:
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php"); // <-- MISSING from don.php
```

A player in vacation mode (who should be unable to perform any game actions) can still
donate energy to their alliance via `don.php`. This allows a player to:
1. Accumulate resources before going on vacation.
2. While on vacation (protected from attacks), donate all their energy to the alliance.
3. The alliance gets free energy from a protected player.

**Fix:** Add `include("includes/redirectionVacance.php");` after line 2 of `don.php`.

---

### [GAME-R1-008] [HIGH] includes/game_actions.php:94-100 -- Travel decay uses attacker's class number but iterates defender's molecule order

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 90-103

When applying travel decay to molecules en route to battle, the code iterates over the
attacker's molecule rows from DB (ordered by `numeroclasse ASC`) and uses `$compteur`
as the class number for `coefDisparition()`.

```php
$ex3 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);
$compteur = 1;
while ($moleculesProp = mysqli_fetch_array($ex3)) {
    $moleculesRestantes = (pow(coefDisparition($actions['attaquant'], $compteur), $nbsecondes) * $molecules[$compteur - 1]);
    // ...
    $compteur++;
}
```

The `$molecules` array (from `explode(";", $actions['troupes'])`) is indexed 0-3,
and `$compteur` runs 1-4. `$molecules[$compteur - 1]` correctly maps to the troop
count for each class. However, `coefDisparition()` with `$compteur` (1-4) queries
the DB for molecules WHERE `numeroclasse=$compteur`. This is correct as long as the
attacker always has all 4 classes in the DB ordered sequentially. If a player has
gaps in their molecule classes (e.g., deleted class 2), the iteration would still
fetch 4 rows but `$moleculesProp` might have different `numeroclasse` values than
assumed by `$compteur`.

However, players always have exactly 4 molecule rows (even if some are "Vide"), so this
is unlikely to cause issues in practice. The real problem is that the
`moleculesPerdues` counter is read-update-written per class (line 99-100) without
any concurrency protection, which can lose decay counts under concurrent access.

```php
// Lines 99-100: TOCTOU on moleculesPerdues
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['attaquant']);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($molecules[$compteur - 1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']),
    $actions['attaquant']);
```

**Fix:** Use atomic increment:
```php
$decayed = $molecules[$compteur - 1] - $moleculesRestantes;
dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?',
    'ds', $decayed, $actions['attaquant']);
```

---

### [GAME-R1-009] [HIGH] includes/game_resources.php:180-181 -- moleculesPerdues TOCTOU in decay loop

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php`
**Lines:** 180-181

The molecule decay loop re-reads `moleculesPerdues` from the DB on each iteration
(4 times total), then sets it to an absolute value. Under concurrent access this
creates a read-then-write race condition.

```php
while ($molecules = mysqli_fetch_array($ex)) {
    // ...
    $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
        ($molecules['nombre'] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $joueur);
    $compteur++;
}
```

If two requests update resources for the same player concurrently (e.g., from an attack
and a page load), both read the same `moleculesPerdues` value and then both write their
own computed total, causing one write to be lost.

The same pattern appears in `update.php:55` and `game_actions.php:99-100,461-462`.

**Fix:** Use atomic increment across all locations:
```php
$decayed = $molecules['nombre'] - $moleculesRestantes;
if ($decayed > 0) {
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?',
        'ds', $decayed, $joueur);
}
```

---

### [GAME-R1-010] [HIGH] includes/prestige.php:46-84 -- Prestige PP calculation omits 4 of 10 medal categories

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php`
**Lines:** 62-69

The `calculatePrestigePoints()` function checks only 6 medal categories for tier-based
PP awards:

```php
$medalChecks = [
    [$autre['nbattaques'], $MEDAL_THRESHOLDS_TERREUR],
    [$autre['pointsAttaque'], $MEDAL_THRESHOLDS_ATTAQUE],
    [$autre['pointsDefense'], $MEDAL_THRESHOLDS_DEFENSE],
    [$autre['ressourcesPillees'], $MEDAL_THRESHOLDS_PILLAGE],
    [$autre['moleculesPerdues'], $MEDAL_THRESHOLDS_PERTES],
    [$autre['energieDepensee'], $MEDAL_THRESHOLDS_ENERGIEVORE],
];
```

Four medal categories defined in config.php are missing:
- `$MEDAL_THRESHOLDS_CONSTRUCTEUR` (building levels)
- `$MEDAL_THRESHOLDS_PIPELETTE` (forum messages)
- `$MEDAL_THRESHOLDS_BOMBE` (buildings destroyed)
- `$MEDAL_THRESHOLDS_TROLL` (troll activity)

This means builders and social players who focus on construction or community
engagement receive fewer prestige points than combat-focused players, biasing the
prestige system toward military playstyles.

**Fix:** Add the missing medal checks. Note that some require data from different tables
(`constructeur` needs building data from `constructions`, `pipelette` from forum stats).

---

## MEDIUM

### [GAME-R1-011] [MEDIUM] includes/player.php:793-838 -- Season reset does not clear specialization columns

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
**Lines:** 799

The `remiseAZero()` function resets most player data but does not reset the
specialization columns (`spec_combat`, `spec_economy`, `spec_research`).

```php
dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,
    moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0,
    pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, missions=\'\'');
// NOTE: spec_combat, spec_economy, spec_research are NOT reset
```

Per config.php, specializations are "irreversible choices unlocked at building
milestones" (ionisateur 15, producteur 20, condenseur 15). Since buildings are reset
to level 1, players who had specializations from the previous season carry them into
the new season without needing to re-unlock them.

**Design question:** This may be intentional (specializations as cross-season
progression like prestige), but it is not documented. If unintentional, players get
permanent bonuses that new players cannot access until late game, creating an unfair
advantage.

**Fix (if unintentional):**
```php
dbExecute($base, 'UPDATE autre SET spec_combat=0, spec_economy=0, spec_research=0');
```

---

### [GAME-R1-012] [MEDIUM] marche.php:197,310 -- Market buy-sell cycling accumulates trade points despite sell tax

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Lines:** 197, 310 (inside transaction callbacks)

Both buying and selling on the market award trade points based on energy volume
(lines 212-225 for buy, 326-338 for sell). The 5% sell tax mitigates pure arbitrage
but does not prevent trade point farming.

```php
// Buy: tradeVolume += coutAchat * reseauBonus (line 214)
// Sell: tradeVolume += energyGained * reseauBonus (line 327)
```

A player can buy 1000 atoms for 1000 energy, then sell them for 950 energy (5% tax),
netting tradeVolume of 1950 for a cost of only 50 energy. The trade points formula
uses `sqrt(totalTradeVolume)`, so even with diminishing returns, a player can grind
trade points with minimal cost.

At scale: spending 1000 energy repeatedly yields ~1950 trade volume per cycle. After
100 cycles (50,000 energy spent in tax), `tradeVolume` = 195,000, giving
`floor(0.08 * sqrt(195000))` = 35 points. This is cheap relative to other point sources.

**Fix:** Only award trade points on buy transactions (not sell), or track net trade
volume separately, or increase the sell tax.

---

### [GAME-R1-013] [MEDIUM] includes/combat.php:206 -- attaque() function queries DB for medal data per class per combat

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Line:** 206

The combat damage loop calls `attaque()` 4 times for the attacker and `defense()` 4
times for the defender. Each of these functions queries the DB for medal data
(`pointsAttaque` or `pointsDefense` from the `autre` table).

```php
// combat.php line 206 - called 4 times with different class data
$degatsAttaquant += attaque(${'classeAttaquant' . $c}['oxygene'], $niveauxAtt['oxygene'],
    $actions['attaquant']) * ...;
```

```php
// formulas.php line 84-87
function attaque($oxygene, $niveau, $joueur, $medalData = null) {
    if ($medalData === null) {
        global $base;
        $medalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
    }
    // ...
}
```

This results in 8 unnecessary DB queries per combat (4 for attacker attack medals +
4 for defender defense medals). The medal data is the same for all classes of the
same player.

**Fix:** Pre-fetch medal data once and pass it via the `$medalData` parameter:
```php
$attMedalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $actions['attaquant']);
$defMedalData = dbFetchOne($base, 'SELECT pointsDefense FROM autre WHERE login=?', 's', $actions['defenseur']);
// Then: attaque(..., $attMedalData) and defense(..., $defMedalData)
```

---

### [GAME-R1-014] [MEDIUM] includes/combat.php:413-416 vs 431-435 -- Building destruction potential calculated twice

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 413-416 and 431-435

`$hydrogeneTotal` is calculated on lines 413-416 using pre-casualty surviving counts,
then recalculated on lines 431-434 inside the `if ($gagnant == 2)` block. The first
calculation (lines 413-416) is dead code since the variable is immediately overwritten.

```php
// Lines 413-416: Dead code -- calculated BEFORE the gagnant==2 check
$hydrogeneTotal = ($classeAttaquant1['nombre'] - $classe1AttaquantMort) * ...

// Lines 429-435: Recalculated inside the guard
if ($gagnant == 2 && $hydrogeneTotal > 0) {
    // Recalculate hydrogeneTotal from SURVIVING attackers
    $hydrogeneTotal = 0;
    for ($i = 1; $i <= $nbClasses; $i++) {
        // ...recalculated here
    }
```

The first calculation IS used in the `if ($gagnant == 2 && $hydrogeneTotal > 0)` guard
condition, meaning it serves as a pre-check. However, both calculations use the same
surviving attacker counts, so the guard check uses the correct value. This is not a
functional bug but is confusing and wasteful.

**Impact:** Cosmetic/performance only. No game logic error.

---

### [GAME-R1-015] [MEDIUM] includes/combat.php:446-468 -- Return trip decay double-counts molecules lost during outbound travel

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 446-468

After combat, surviving molecules travel back to the attacker. The return trip applies
decay using the full return travel duration. However, the outbound decay was already
applied (lines 90-103), and the `troupes` field was updated with post-decay,
post-combat counts.

```php
// Return trip (lines 449-462)
$nbsecondes = $actions['tempsRetour'] - $actions['tempsAttaque'];
$molecules = explode(";", $actions['troupes']); // post-combat troops
// ...
$moleculesRestantes = (pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1]);
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di',
    ($moleculesProp['nombre'] + $moleculesRestantes), $moleculesProp['id']);
```

The return trip correctly uses `$actions['troupes']` (post-combat surviving troops) and
applies decay for the return duration. This is working as designed -- molecules decay
during both outbound and return travel. However, the moleculesPerdues tracking on
lines 461-462 again uses the non-atomic read-then-write pattern (see GAME-R1-009).

**Impact:** The double decay is by design (molecules decay while traveling). The
non-atomic counter update is the real issue (covered by GAME-R1-009).

---

### [GAME-R1-016] [MEDIUM] armee.php:162 -- Atom cap check uses > instead of >= for MAX_ATOMS_PER_ELEMENT

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php`
**Line:** 162

```php
if ($_POST[$ressource] > 200) { // should be > MAX_ATOMS_PER_ELEMENT
    $bool = 0;
}
```

The constant `MAX_ATOMS_PER_ELEMENT` is defined as 200 in config.php. The check uses
`> 200`, meaning exactly 200 atoms is allowed. This is consistent with the constant
name ("max 200"). However, the hardcoded `200` should reference the constant:

```php
if ($_POST[$ressource] > MAX_ATOMS_PER_ELEMENT) {
```

**Impact:** No functional bug (200 is correctly allowed), but the hardcoded value
creates a maintenance risk if the constant is changed.

---

### [GAME-R1-017] [MEDIUM] includes/combat.php:446 -- Return trip check ignores defenders who also resolve the same action

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Line:** 446

```php
if ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant'] && ...) {
```

The return trip is only processed when `$joueur == $actions['attaquant']`. If the
defender logs in before the attacker's troops return, the defender's `updateActions()`
call skips the return processing (correct). But if neither player logs in for a long
time, the troops stay in the `actionsattaques` table indefinitely until the attacker
logs in.

During this time, the troops are NOT in the attacker's molecule count (they were
subtracted at launch). If a season reset occurs before the attacker logs in, the
troops are lost permanently since `remiseAZero()` clears `actionsattaques`.

**Impact:** Troops in transit at season reset are permanently lost. This is arguably
by design (you should recall troops before season end), but it is not documented.

---

### [GAME-R1-018] [MEDIUM] attaquer.php:26 -- Espionage neutrino check allows exactly half defender's neutrinos to succeed

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
**Lines:** 328-330

```php
$espionageThreshold = ($nDef['neutrinos'] / 2) * $radarDiscount;
if ($espionageThreshold < $actions['nombreneutrinos']) {
    // espionage succeeds
```

The check is `threshold < sent`, meaning espionage succeeds if sent > threshold.
If `radarDiscount = 1` (no radar research), threshold = defender's neutrinos / 2.
Sending exactly `floor(defender/2) + 1` neutrinos guarantees success.

This is documented behavior. However, the radar discount reduces the threshold,
meaning higher radar levels make espionage cheaper for the alliance. At max radar
level 25 (50% discount), the threshold becomes `defender / 4`. This is very
aggressive -- sending only 25% of defender neutrinos succeeds.

**Impact:** Balance concern rather than a bug. High radar research makes espionage
trivially cheap.

---

### [GAME-R1-019] [MEDIUM] includes/game_resources.php:186-199 -- Decay report checks variables that may not exist for players with < 4 classes

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php`
**Lines:** 186-199

The decay report after 6+ hours of absence references `$nombre1` through `$nombre4`
(set on line 175 via `${'nombre' . ($compteur + 1)}`). But `$compteur` only increments
for rows returned by the query. If a player has fewer than 4 molecule classes with
`nombre > 0`, not all `$nombre1`-`$nombre4` variables will be set.

However, players always have exactly 4 molecule rows (even "Vide" ones), so the
`SELECT * FROM molecules WHERE proprietaire=?` on line 169 always returns 4 rows.
The actual risk is minimal, but the code is fragile.

**Impact:** Low risk in practice, but `$nombre3`/`$nombre4` could be undefined if
molecule rows are somehow missing from the DB (e.g., data corruption).

---

### [GAME-R1-020] [MEDIUM] alliance.php:98 -- Alliance research column name from POST used in SQL without explicit whitelist match

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`
**Lines:** 94-98

```php
if (isset($_POST['upgradeResearch']) && isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])) {
    $techName = $_POST['upgradeResearch'];
    $allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', ...);
```

The POST value is validated against `$ALLIANCE_RESEARCH` keys (catalyseur, fortification,
reseau, radar, bouclier), which IS a whitelist. The `isset($ALLIANCE_RESEARCH[$_POST[...]])`
check ensures only valid keys pass through. However, `$techName` is then used directly
in SQL column names on lines 98 and 108 without further sanitization.

Since the `$ALLIANCE_RESEARCH` keys are hardcoded strings matching column names, this is
safe. But it relies on the config array keys exactly matching DB column names -- a
maintenance coupling risk.

**Impact:** Not exploitable currently, but the pattern is fragile. Consider an explicit
whitelist array of allowed column names.

---

### [GAME-R1-021] [MEDIUM] includes/combat.php:175-180 -- Chemical reaction bonuses silently ignore cross-role effects

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 175, 180

```php
// Line 175: attackers don't use defense bonus -- silently ignored
if (isset($bonuses['defense'])) $attReactionAttackBonus += 0;
// Line 180: defenders don't use attack bonus -- silently ignored
if (isset($bonuses['attack'])) $defReactionDefenseBonus += 0;
```

When an attacker triggers the "Neutralisation" reaction (which grants `defense: 0.15`),
the defense bonus is silently discarded with `+= 0`. This means:
- Combustion (+15% attack) benefits attackers but not defenders.
- Neutralisation (+15% defense) benefits defenders but not attackers.

This asymmetry is likely intentional (attackers attack, defenders defend) but is not
documented. The `+= 0` lines are confusing dead code.

**Fix:** Remove the dead code lines or add comments explaining the design intent.

---

### [GAME-R1-022] [MEDIUM] includes/combat.php:329-332 -- Halogénation reaction speed bonus is calculated but never used in combat

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/config.php`
**Line:** 329

The Halogénation chemical reaction grants `'speed' => 0.20` (+20% fleet speed).
However, in `combat.php`, only `attack`, `hp`, `pillage`, and `defense` bonuses are
extracted from reaction results (lines 166-181). The `speed` bonus is never read.

```php
// config.php
'Halogénation' => [
    'bonus' => ['speed' => 0.20], // +20% fleet speed
],

// combat.php -- only these are checked:
if (isset($bonuses['attack'])) $attReactionAttackBonus += $bonuses['attack'];
if (isset($bonuses['hp'])) $attReactionHpBonus += $bonuses['hp'];
if (isset($bonuses['pillage'])) $attReactionPillageBonus += $bonuses['pillage'];
if (isset($bonuses['defense'])) ...;
// 'speed' is NEVER checked
```

The speed bonus should affect travel time, but combat.php runs AFTER troops have
already arrived (travel time is calculated in attaquer.php at launch time). The
reaction is checked at combat resolution time, not at launch time.

**Impact:** The Halogénation reaction has NO effect in the game. Players who build
Cl>=80 + I>=80 class combos expecting a speed bonus get nothing.

**Fix:** Either check reactions at attack launch time in `attaquer.php` to apply
the speed bonus, or change the Halogénation bonus to something usable in combat.

---

## LOW

### [GAME-R1-023] [LOW] marche.php:62-65 -- Resource transfer rapport calculation divides by own revenue which could be zero

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php`
**Lines:** 62-73

```php
if ($revenuEnergie >= revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire'])) {
    $rapportEnergie = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']) / $revenuEnergie;
} else {
    $rapportEnergie = 1;
}
```

If the sender's `$revenuEnergie` is 0 (which can happen if producteur drain exceeds
production), this causes a division by zero. Similarly for `$revenu[$ressource]` on
line 71.

**Impact:** PHP warning on division by zero, resulting in INF or NAN being stored as
the transfer ratio.

**Fix:** Guard against zero revenue:
```php
if ($revenuEnergie <= 0) { $rapportEnergie = 0; }
```

---

### [GAME-R1-024] [LOW] includes/combat.php:450 -- Random building target uses rand(1,4) which is not cryptographically secure

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Line:** 450

```php
$bat = rand(1, 4);
```

`rand()` is predictable and uses a simple PRNG. For game fairness, `mt_rand(1, 4)` or
`random_int(1, 4)` would be more appropriate. In a multiplayer game, a determined
player could potentially predict the random target.

**Impact:** Minimal practical impact since PHP's `rand()` is reseeded per-request.
However, `mt_rand()` is the modern standard.

---

### [GAME-R1-025] [LOW] constructions.php:162 -- Building cost affordability check uses wrong operator

**File:** `/home/guortates/TVLW/The-Very-Little-War/constructions.php`
**Line:** 152

```php
if ($liste['coutEnergie'] >= $ressources['energie'] or $bool == 0) {
```

This checks if cost >= energy (i.e., player CANNOT afford it). The condition should be
`>` not `>=`, because if cost exactly equals energy, the player CAN afford it. The
processing function `traitementConstructions()` on line 238 uses `>=` correctly:

```php
if ($ressources['energie'] >= $liste['coutEnergie'] and $bool == 1) {
```

So the display function `mepConstructions()` shows "not enough resources" when the
player has exactly enough, but the actual upgrade still works via the processing
function. This is a UI-only bug.

**Impact:** Player sees "not enough resources" message when they have exactly enough,
but can still upgrade by refreshing and trying again.

---

### [GAME-R1-026] [LOW] includes/update.php:47-57 -- Target decay loop iterates with $compteurClasse but queries use different class numbering

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/update.php`
**Lines:** 49-57

```php
$compteurClasse = 0;
while ($molecules = mysqli_fetch_array($exResult)) {
    $compteurClasse++;
    $moleculesRestantes = pow(coefDisparition($targetPlayer, $compteurClasse), $nbsecondesAdverse) * $molecules['nombre'];
```

The query on line 47 uses `WHERE nombre > 0`, which skips empty classes. But
`coefDisparition()` with `$compteurClasse` assumes sequential class numbers. If
class 2 has 0 molecules and is skipped, then classes 3 and 4 get passed as
`$compteurClasse` 2 and 3, computing decay with the wrong class's atom composition.

Compare with `updateRessources()` in `game_resources.php` line 169:
```php
$ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $joueur);
// This fetches ALL classes, including those with 0 molecules
```

**Fix:** Either remove the `AND nombre > 0` filter, or use `$molecules['numeroclasse']`
instead of `$compteurClasse` for the class number:
```php
$moleculesRestantes = pow(coefDisparition($targetPlayer, $molecules['numeroclasse']),
    $nbsecondesAdverse) * $molecules['nombre'];
```

---

### [GAME-R1-027] [LOW] includes/combat.php:329-331 -- Pillage formula uses all 4 class soufre levels but not all classes may have molecules

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
**Lines:** 380-383

```php
$ressourcesAPiller = (($classeAttaquant1['nombre'] - $classe1AttaquantMort) * pillage(...) +
    ($classeAttaquant2['nombre'] - $classe2AttaquantMort) * pillage(...) +
    ($classeAttaquant3['nombre'] - $classe3AttaquantMort) * pillage(...) +
    ($classeAttaquant4['nombre'] - $classe4AttaquantMort) * pillage(...)) * $attReactionPillageBonus;
```

When a class has 0 surviving molecules, `(0 - 0) * pillage(...)` = 0, which is correct.
However, `pillage()` still queries the DB for the attacker's medal data per class
(same issue as GAME-R1-013). Could avoid 3 DB queries by skipping classes with 0
survivors.

**Impact:** Performance only, no game logic error.

---

### [GAME-R1-028] [LOW] alliance.php:70-72 -- Quitting alliance has no confirmation or chef-transfer logic

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php`
**Lines:** 69-72

```php
if (isset($_POST['quitter'])) {
    csrfCheck();
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
}
```

If the alliance chef quits, the alliance is orphaned. The check on lines 143-157
will detect this on the next page load and auto-delete the entire alliance with all
its research, declarations, and invitations.

**Impact:** An alliance chef can accidentally destroy the alliance and all its research
progress by clicking "Quitter" with no confirmation. All members lose their alliance.

**Fix:** Either prevent the chef from quitting (must transfer leadership first) or
auto-transfer leadership to the highest-ranked member.

---

### [GAME-R1-029] [LOW] includes/catalyst.php:61 -- Catalyst rotation formula uses week+year*100, creating non-uniform distribution

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/catalyst.php`
**Line:** 61

```php
$currentWeek = intval(date('W')) + intval(date('Y')) * 100;
$newCatalyst = $currentWeek % count($CATALYSTS); // % 6
```

Week numbers go from 1-52 (or 53). Year*100 + week gives values like 202601-202652.
Modulo 6 of these values does not produce a uniform distribution across catalysts:
- 202601 % 6 = 1
- 202602 % 6 = 2
- ...
- 202606 % 6 = 0
- 202607 % 6 = 1

This is actually fairly uniform since the incrementing week number just cycles through
0-5. But at year boundaries (e.g., 202652 to 202701), the jump of 49 (2701-2652) means
the catalyst after week 52 of 2026 skips ahead by 49 % 6 = 1 position. This is not a
meaningful issue for gameplay.

**Impact:** Negligible. Some catalysts may appear slightly more or less often over
multi-year periods.

---

### [GAME-R1-030] [LOW] includes/game_resources.php:96-103 -- revenuAtomeJavascript omits prestige bonus in JS calculation

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php`
**Lines:** 96-103

```php
function revenuAtomeJavascript($joueur) {
    // ...
    echo 'return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*niveau);';
}
```

The server-side `revenuAtome()` on line 82 includes the prestige production bonus:
```php
return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur));
```

The JavaScript version does NOT include the prestige bonus. This means the UI
calculator will show incorrect atom revenue for players who have the "Experimente"
prestige unlock (+5% production).

**Impact:** Cosmetic. The actual production is correct; only the JS preview is wrong.

---

## Summary of Most Critical Fixes Needed

1. **GAME-R1-001** (CRITICAL): Add atomic CAS to `updateTargetResources()` to prevent
   double resource production.
2. **GAME-R1-002** (CRITICAL): Fix `augmenterBatiment()`/`diminuerBatiment()` to
   invalidate `$joueur`'s cache, not `$_SESSION['login']`.
3. **GAME-R1-003** (HIGH): Use `revenuEnergie()` in `updateTargetResources()` instead
   of stale cached value.
4. **GAME-R1-004** (HIGH): Fix Phalange formation to only distribute damage to non-empty
   rear classes.
5. **GAME-R1-005** (HIGH): Use `floor()` for fractional molecule counts in attack
   launching.
6. **GAME-R1-007** (HIGH): Add vacation mode check to `don.php`.
7. **GAME-R1-009** (HIGH): Replace all `moleculesPerdues` read-then-write patterns with
   atomic increments (`moleculesPerdues = moleculesPerdues + ?`).
8. **GAME-R1-022** (MEDIUM): Fix Halogenation reaction to actually apply its speed bonus,
   or change it to a combat-usable bonus.
