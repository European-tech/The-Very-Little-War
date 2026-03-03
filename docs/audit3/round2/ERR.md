# Error Handling Deep-Dive — Round 2

Audited files: includes/database.php, includes/combat.php, includes/formulas.php,
includes/player.php, includes/game_actions.php, includes/game_resources.php,
includes/db_helpers.php, marche.php, attaquer.php, alliance.php, classement.php, armee.php

---

## ERR-R2-001

**Severity:** CRITICAL
**File:** `includes/combat.php` lines 28–32
**Category:** Null dereference — combat crash

**Unchecked call:**
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur']);
```

**Crash scenario:**
If the attacker's row is missing from the `constructions` table (corrupted data, partial
deletion, or race condition during season reset), `dbFetchOne` returns `null`.
The next line then reads `null['pointsProducteur']`, causing a fatal
"Cannot use a scalar value as an array" PHP error. This crashes the combat transaction
mid-flight, leaving the attack in the `actionsattaques` table with `attaqueFaite=1` but
no combat result written, no report generated, no resource updates applied. The combat
is then permanently stuck — the molecules are already deducted from the attacker and
will never return.

**Fix:**
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
if (!$niveauxAttaquant) {
    error_log("COMBAT: missing constructions row for attacker {$actions['attaquant']}, action id={$actions['id']}");
    throw new Exception("COMBAT_DATA_MISSING_ATTACKER");
}
$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur']);
```

---

## ERR-R2-002

**Severity:** CRITICAL
**File:** `includes/combat.php` lines 34–38
**Category:** Null dereference — combat crash (mirror of R2-001)

**Unchecked call:**
```php
$niveauxDefenseur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['defenseur']);
$niveauxDefenseur = explode(";", $niveauxDefenseur['pointsProducteur']);
```

**Crash scenario:**
Same as R2-001 but for the defender. If the defender deleted their account during the
travel time of the attacking molecules (season reset removes players, etc.),
`dbFetchOne` returns null, causing a fatal error mid-combat transaction.
The attacker loses their molecules permanently with no report.

**Fix:**
```php
$niveauxDefenseur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['defenseur']);
if (!$niveauxDefenseur) {
    error_log("COMBAT: missing constructions row for defender {$actions['defenseur']}, action id={$actions['id']}");
    throw new Exception("COMBAT_DATA_MISSING_DEFENDER");
}
$niveauxDefenseur = explode(";", $niveauxDefenseur['pointsProducteur']);
```

---

## ERR-R2-003

**Severity:** CRITICAL
**File:** `includes/combat.php` line 206
**Category:** Null dereference — division potential, wrong value used in damage formula

**Unchecked call:**
```php
$ionisateur = dbFetchOne($base, 'SELECT ionisateur FROM constructions WHERE login=?', 's', $actions['attaquant']);
// ... later at line 206:
$degatsAttaquant += attaque(...) * ... * (1 + (($ionisateur['ionisateur'] * 2) / 100)) * ...
```

**Crash scenario:**
`$ionisateur` is fetched but never null-checked. If the `constructions` row is missing
(same conditions as R2-001), `$ionisateur` is `null`, and `$ionisateur['ionisateur']`
evaluates to `null`, which is cast to 0 in arithmetic — silently giving a wrong ionisateur
bonus of 0 instead of the player's actual level. If PHP strict mode is enabled this would
produce a warning and potentially break the page. The null dereference risk is a crash path.

**Fix:**
```php
$ionisateur = dbFetchOne($base, 'SELECT ionisateur FROM constructions WHERE login=?', 's', $actions['attaquant']);
$ionisateurLevel = ($ionisateur && isset($ionisateur['ionisateur'])) ? (int)$ionisateur['ionisateur'] : 0;
// use $ionisateurLevel in the damage formula
```

---

## ERR-R2-004

**Severity:** CRITICAL
**File:** `includes/combat.php` line 212
**Category:** Null dereference — wrong defense value in damage formula

**Unchecked call:**
```php
$champdeforce = dbFetchOne($base, 'SELECT champdeforce FROM constructions WHERE login=?', 's', $actions['defenseur']);
// ... at line 212:
$degatsDefenseur += defense(...) * ... * (1 + (($champdeforce['champdeforce'] * 2) / 100)) * ...
```

**Crash scenario:**
`$champdeforce` is never null-checked. If `constructions` row missing for defender,
`$champdeforce['champdeforce']` silently evaluates to 0, causing every defender whose
row is absent to fight with zero force-field bonus. In PHP 8 this triggers a deprecation
notice on null array access which may be logged as an error.

**Fix:**
```php
$champdeforce = dbFetchOne($base, 'SELECT champdeforce FROM constructions WHERE login=?', 's', $actions['defenseur']);
$champdeforceLevel = ($champdeforce && isset($champdeforce['champdeforce'])) ? (int)$champdeforce['champdeforce'] : 0;
```

---

## ERR-R2-005

**Severity:** CRITICAL
**File:** `includes/combat.php` lines 45–57
**Category:** Null dereference — alliance bonus silently wrong

**Unchecked calls:**
```php
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$bonusDuplicateurAttaque = 1;
if ($idalliance['idalliance'] > 0) {
    $duplicateurAttaque = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
    $bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);
}
```

**Crash scenario:**
(a) If `$idalliance` is null (missing `autre` row), accessing `$idalliance['idalliance']`
produces a PHP 8 deprecation/warning. The `> 0` comparison on null casts to false,
silently skipping the bonus — but log spam can fill disk.
(b) If the attacker's alliance was deleted between when the attack was launched and when
combat resolves, `$duplicateurAttaque` will be null, and `$duplicateurAttaque['duplicateur']`
causes a fatal null dereference, crashing combat.

Identical pattern on lines 52–57 for the defender.

**Fix:**
```php
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$bonusDuplicateurAttaque = 1;
if ($idalliance && ($idalliance['idalliance'] ?? 0) > 0) {
    $duplicateurAttaque = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
    if ($duplicateurAttaque) {
        $bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);
    }
}
// Mirror fix for $idallianceDef / $duplicateurDefense
```

---

## ERR-R2-006

**Severity:** HIGH
**File:** `includes/combat.php` lines 360–362
**Category:** Null dereference — resource update with null data

**Unchecked calls:**
```php
$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['defenseur']);
$ressourcesJoueur    = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['attaquant']);
```

**Crash scenario:**
Both are used extensively afterward — `$ressourcesDefenseur[$ressource]` inside a foreach
(line 376), and `$ressourcesJoueur[$ressource]` in the update SET clause (line 585).
If either player's `ressources` row is missing (season reset, manual deletion), every
foreach loop crashes with a null-array dereference, rolling back the transaction and
leaving the combat in an undefined state.

**Fix:**
```php
$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['defenseur']);
if (!$ressourcesDefenseur) {
    throw new Exception("COMBAT_RESOURCES_MISSING_DEFENDER");
}
$ressourcesJoueur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['attaquant']);
if (!$ressourcesJoueur) {
    throw new Exception("COMBAT_RESOURCES_MISSING_ATTACKER");
}
```

---

## ERR-R2-007

**Severity:** HIGH
**File:** `includes/combat.php` lines 534–535
**Category:** Null dereference — combat point update fails silently

**Unchecked calls:**
```php
$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
$pointsBDDefenseur = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['defenseur']);
```

**Crash scenario:**
These results are never checked before use. `ajouterPoints()` (line 561–563) calls
`dbFetchOne` again internally, but the combat report builder (lines 541–548) uses
`$pointsBDAttaquant` directly in the points calculation context.
If either row is null, `ajouterPoints` still works, but any display logic relying on
these variables will fail.

**Fix:**
```php
$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
if (!$pointsBDAttaquant) {
    error_log("COMBAT: missing autre row for attacker {$actions['attaquant']}");
    $pointsBDAttaquant = ['points'=>0,'pointsAttaque'=>0,'pointsDefense'=>0,'totalPoints'=>0];
}
// same for $pointsBDDefenseur
```

---

## ERR-R2-008

**Severity:** HIGH
**File:** `includes/combat.php` lines 557–559
**Category:** Null dereference — stat update crashes on null

**Unchecked calls:**
```php
$perduesAttaquant = dbFetchOne($base, 'SELECT moleculesPerdues,ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
$perduesDefenseur = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['defenseur']);
```

**Crash scenario:**
Used immediately at lines 568–569:
```php
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($pertesAttaquant + $perduesAttaquant['moleculesPerdues']), $actions['attaquant']);
```
If either `dbFetchOne` returns null, `$perduesAttaquant['moleculesPerdues']` is a fatal
null dereference. The transaction rolls back, the CAS lock already set (`attaqueFaite=1`)
means the combat never re-runs, and both players see no report.

**Fix:**
```php
$perduesAttaquant = dbFetchOne($base, 'SELECT moleculesPerdues,ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
if (!$perduesAttaquant) { throw new Exception("COMBAT_STAT_MISSING_ATTACKER"); }
$perduesDefenseur = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['defenseur']);
if (!$perduesDefenseur) { throw new Exception("COMBAT_STAT_MISSING_DEFENDER"); }
```

---

## ERR-R2-009

**Severity:** HIGH
**File:** `includes/combat.php` lines 577–578
**Category:** Null dereference — attacker storage cap crashes on null

**Unchecked call:**
```php
$depotAtt = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['attaquant']);
$maxStorageAtt = placeDepot($depotAtt['depot']);
```

**Crash scenario:**
`$depotAtt` is never checked. If null, `$depotAtt['depot']` causes a fatal PHP error.
`placeDepot(null)` would return 0, capping all pillaged resources to 0 (game-breaking).
Worse, the null dereference crashes the transaction before the resource update runs.

**Fix:**
```php
$depotAtt = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['attaquant']);
if (!$depotAtt) { throw new Exception("COMBAT_DEPOT_MISSING_ATTACKER"); }
$maxStorageAtt = placeDepot((int)$depotAtt['depot']);
```

---

## ERR-R2-010

**Severity:** HIGH
**File:** `includes/combat.php` lines 603–607
**Category:** Null dereference — defender energy cap crashes

**Unchecked call:**
```php
$depotDef = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['defenseur']);
$maxEnergy = placeDepot($depotDef['depot']);
```

**Crash scenario:**
Inside the `if ($defenseRewardEnergy > 0)` block. If `$depotDef` is null (same conditions
as R2-009), fatal crash. This only fires when the defender wins, but when it does the
entire combat transaction rolls back, leaving the attack marked done but no report written.

**Fix:**
```php
$depotDef = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['defenseur']);
if (!$depotDef) { throw new Exception("COMBAT_DEPOT_MISSING_DEFENDER"); }
$maxEnergy = placeDepot((int)$depotDef['depot']);
```

---

## ERR-R2-011

**Severity:** HIGH
**File:** `includes/combat.php` lines 614–616
**Category:** Null dereference — attack counter update crashes

**Unchecked call:**
```php
$nbattaques = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', 's', $actions['attaquant']);
dbExecute($base, 'UPDATE autre SET nbattaques=? WHERE login=?', 'is', ($nbattaques['nbattaques'] + 1), $actions['attaquant']);
```

**Crash scenario:**
If `$nbattaques` is null, `$nbattaques['nbattaques']` is a fatal null dereference.
The combat transaction crashes here after resources and molecules have already been
updated but before the attack counter is incremented, leaving inconsistent data.

**Fix:**
```php
$nbattaques = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', 's', $actions['attaquant']);
$currentNbAttaques = $nbattaques ? (int)$nbattaques['nbattaques'] : 0;
dbExecute($base, 'UPDATE autre SET nbattaques=? WHERE login=?', 'is', ($currentNbAttaques + 1), $actions['attaquant']);
```

---

## ERR-R2-012

**Severity:** HIGH
**File:** `includes/combat.php` lines 620–632
**Category:** Null dereference — war loss tracking crashes

**Unchecked calls:**
```php
$joueur          = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$idallianceAutre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
$exGuerre = dbQuery($base, 'SELECT * FROM declarations WHERE type=0 AND fin=0 AND ((alliance1=? AND alliance2=?) OR ...)',
    'iiii', $joueur['idalliance'], $idallianceAutre['idalliance'], ...);
```

**Crash scenario:**
Neither `$joueur` nor `$idallianceAutre` is null-checked. If either returns null
(missing `autre` row), `$joueur['idalliance']` is a fatal null dereference passed as a
parameter to `dbQuery`. The combat transaction crashes at the very end after all combat
results have been applied, which means the crash happens inside the `try` block and
triggers a rollback — undoing all resource changes, molecule updates, and reports.

**Fix:**
```php
$joueur          = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$idallianceAutre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
$attAllianceId   = $joueur          ? (int)$joueur['idalliance']          : 0;
$defAllianceId   = $idallianceAutre ? (int)$idallianceAutre['idalliance'] : 0;
// use $attAllianceId and $defAllianceId in the war query
```

---

## ERR-R2-013

**Severity:** HIGH
**File:** `includes/combat.php` lines 427–524
**Category:** Null dereference — building damage crashes combat

**Unchecked call:**
```php
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);

if ($gagnant == 2 && $hydrogeneTotal > 0) {
    // ... uses $constructions['champdeforce'], $constructions['generateur'] etc at line 438
```

**Crash scenario:**
`$constructions` is fetched once at line 427 and used in several array accesses. If null,
the first access at line 438 (`$constructions['champdeforce']`) causes a fatal error in the
building damage block. Because this code is inside the combat try-block, the transaction
rolls back, undoing all combat results — molecules lost but no outcome recorded.

**Fix:**
```php
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);
if (!$constructions) {
    error_log("COMBAT: missing constructions for defender {$actions['defenseur']} — skipping building damage");
    // Initialize safe defaults to skip damage block naturally
    $constructions = ['champdeforce'=>1,'generateur'=>1,'producteur'=>1,'depot'=>1,
                      'vieGenerateur'=>0,'vieChampdeforce'=>0,'vieProducteur'=>0,'vieDepot'=>0];
}
```

---

## ERR-R2-014

**Severity:** HIGH
**File:** `includes/formulas.php` lines 86–96 (`attaque()` function)
**Category:** Null dereference — medal data missing silently ignores bonus

**Unchecked call:**
```php
function attaque($oxygene, $niveau, $joueur, $medalData = null)
{
    if ($medalData === null) {
        global $base;
        $medalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
    }
    $bonus = 0;
    foreach ($paliersAttaque as $num => $palier) {
        if ($medalData['pointsAttaque'] >= $palier) {
```

**Crash scenario:**
If `dbFetchOne` returns null (player deleted, DB error), `$medalData['pointsAttaque']`
causes a fatal PHP error in the foreach. This function is called inside the damage
calculation loop in combat.php — a crash here is unrecoverable within the transaction.
Even without crashing (if null comparison works), every medal threshold check silently
fails, giving 0 bonus when the player may have earned a large one.

**Fix:**
```php
if ($medalData === null) {
    global $base;
    $medalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
}
if (!$medalData) {
    $medalData = ['pointsAttaque' => 0];
}
```

---

## ERR-R2-015

**Severity:** HIGH
**File:** `includes/formulas.php` lines 104–116 (`defense()` function)
**Category:** Null dereference — mirror of R2-014 for defense medals

**Unchecked call:**
```php
$medalData = dbFetchOne($base, 'SELECT pointsDefense FROM autre WHERE login=?', 's', $joueur);
// ...
if ($medalData['pointsDefense'] >= $palier) {
```

**Crash scenario:** Identical to R2-014. Defense value is used in the same combat loop.
**Fix:** Same pattern — add null guard and default `['pointsDefense' => 0]`.

---

## ERR-R2-016

**Severity:** HIGH
**File:** `includes/formulas.php` lines 134–147 (`pillage()` function)
**Category:** Null dereference — medal data missing in pillage calculation

**Unchecked call:**
```php
$medalData = dbFetchOne($base, 'SELECT ressourcesPillees FROM autre WHERE login=?', 's', $joueur);
// ...
if ($medalData['ressourcesPillees'] >= $palier) {
```

**Crash scenario:** Same as R2-014 but for pillage medals. If `$medalData` is null,
the array access crashes. This function is called in combat.php for every attacker class
during pillage computation.
**Fix:** Add null guard and default `['ressourcesPillees' => 0]`.

---

## ERR-R2-017

**Severity:** HIGH
**File:** `includes/formulas.php` lines 166–172 (`tempsFormation()` function)
**Category:** Null dereference + division by zero cascade

**Unchecked call:**
```php
function tempsFormation($azote, $niveau, $ntotal, $joueur)
{
    global $base;
    $constructions = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
    // ...
    return ceil($ntotal / ... / bonusLieur($constructions['lieur']) / ...);
}
```

**Crash scenario:**
If `$constructions` is null, `$constructions['lieur']` is a fatal null dereference.
`bonusLieur(null)` would call `pow(LIEUR_GROWTH_BASE, null)` which evaluates to 1.0,
silently giving wrong formation time rather than crashing — but the null array access
itself is a fatal error. This function is called in `armee.php` when queuing formation;
if it crashes, the molecule formation queue is corrupted.

**Fix:**
```php
$constructions = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
$lieurLevel = ($constructions && isset($constructions['lieur'])) ? (int)$constructions['lieur'] : 0;
return ceil($ntotal / ... / bonusLieur($lieurLevel) / ...);
```

---

## ERR-R2-018

**Severity:** HIGH
**File:** `includes/formulas.php` lines 183–225 (`coefDisparition()` function)
**Category:** Null dereference — molecule decay crashes everywhere

**Unchecked calls:**
```php
if ($type == 0) {
    $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
}
$stabilisateur    = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);
$donneesMedaille  = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
// ...
if ($donneesMedaille['moleculesPerdues'] >= $palier) {
// ...
pow(... * (1 - ($stabilisateur['stabilisateur'] * STABILISATEUR_BONUS_PER_LEVEL)));
// ...
if ($type == 0 && isset($donnees['isotope'])) {
```

**Crash scenario:**
Three separate null-unchecked fetches. `$donneesMedaille['moleculesPerdues']` crashes if
`autre` row is missing. `$stabilisateur['stabilisateur']` crashes if `constructions` row
is missing. Both are used in the decay exponent formula — a crash here propagates to
`demiVie()`, `updateRessources()`, and `game_actions.php` during molecule travel decay
calculations, crashing the entire action-processing loop.

**Fix:**
```php
$stabilisateur   = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);
$stabLevel       = ($stabilisateur && isset($stabilisateur['stabilisateur'])) ? $stabilisateur['stabilisateur'] : 0;

$donneesMedaille = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
if (!$donneesMedaille) { $donneesMedaille = ['moleculesPerdues' => 0]; }
```

---

## ERR-R2-019

**Severity:** HIGH
**File:** `includes/player.php` lines 30–31 (`inscrire()` function)
**Category:** Null dereference — registration crashes if statistiques is empty

**Unchecked call:**
```php
$data1 = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
$nbinscrits = $data1['inscrits'] + 1;
```

**Crash scenario:**
If the `statistiques` table is empty (first install, reset, or accidental truncation),
`dbFetchOne` returns null and `$data1['inscrits']` is a fatal null dereference.
Player registration fails with a PHP fatal error, leaving a partial user record if
executed in a non-transactional context. The player sees a 500 error and may retry,
creating duplicate registration attempts.

**Fix:**
```php
$data1 = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
$nbinscrits = ($data1 ? (int)$data1['inscrits'] : 0) + 1;
```

---

## ERR-R2-020

**Severity:** HIGH
**File:** `includes/player.php` lines 76–106 (`ajouterPoints()` function)
**Category:** Null dereference — all point updates fail silently or crash

**Unchecked call:**
```php
function ajouterPoints($nb, $joueur, $type = 0)
{
    global $base;
    $points = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);

    if ($type == 0) {
        if ($points['points'] + $nb >= 0) {
```

**Crash scenario:**
`$points` is never null-checked. Every branch accesses `$points['points']`,
`$points['pointsAttaque']`, `$points['totalPoints']`, etc. If the `autre` row is missing,
the first access is a fatal null dereference. `ajouterPoints` is called inside the combat
transaction (lines 561–563 of combat.php), so a crash here rolls back ALL combat results
after molecules, resources, and reports have been partially written — leaving the game in
a corrupted state.

**Fix:**
```php
$points = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);
if (!$points) {
    error_log("ajouterPoints: missing autre row for player $joueur");
    return 0;
}
```

---

## ERR-R2-021

**Severity:** HIGH
**File:** `includes/player.php` lines 150–170 (`initPlayer()` function)
**Category:** Null dereference — constructions explode crash on null

**Unchecked calls:**
```php
$ressources    = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $joueur);
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);

$niveaux       = explode(';', $constructions['pointsProducteur']);
$niveauxAtomes = explode(';', $constructions['pointsCondenseur']);
```

**Crash scenario:**
Both `$ressources` and `$constructions` are used immediately without null checks.
If either row is missing (new player whose registration transaction partially failed,
or season-reset race), the `explode` on `null['pointsProducteur']` causes a fatal error.
`initPlayer` is called on every authenticated page load, so a missing `constructions`
row would make the entire game inaccessible for that player.

**Fix:**
```php
$ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $joueur);
if (!$ressources) {
    error_log("initPlayer: missing ressources row for $joueur");
    return; // graceful bail — caller should handle absent player data
}
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
if (!$constructions) {
    error_log("initPlayer: missing constructions row for $joueur");
    return;
}
```

---

## ERR-R2-022

**Severity:** HIGH
**File:** `includes/player.php` line 161
**Category:** Explode out-of-bounds — null from missing semicolons in DB

**Code:**
```php
$niveaux = explode(';', $constructions['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
    ${'points' . $ressource} = $niveaux[$num];
}
```

**Crash scenario:**
If `pointsProducteur` is stored with fewer segments than `count($nomsRes)` (e.g., data
corruption, a new atom type added without a migration), `$niveaux[$num]` returns `null`
for out-of-bounds indices. Every downstream formula that uses `$pointsOxygene` etc.
receives null instead of an integer, silently producing wrong production values.
No error is thrown in PHP 8, but production calculations are corrupted.

**Fix:**
```php
$niveaux = explode(';', $constructions['pointsProducteur'] ?? '');
foreach ($nomsRes as $num => $ressource) {
    ${'points' . $ressource} = isset($niveaux[$num]) ? (int)$niveaux[$num] : 0;
}
```

---

## ERR-R2-023

**Severity:** HIGH
**File:** `includes/player.php` lines 215–218 (`initPlayer()` — duplicateur block)
**Category:** Null dereference — duplicateur crash when alliance row deleted

**Unchecked call:**
```php
if ($autre['idalliance'] > 0) {
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $autre['idalliance']);
    $bonusDuplicateur = 1 + ($duplicateur['duplicateur'] / 100);
}
```

**Crash scenario:**
If the alliance was deleted (e.g., chef left and `supprimerAlliance` ran) but
`autre.idalliance` was not zeroed out (a known race condition), `$duplicateur` returns
null and `$duplicateur['duplicateur']` is a fatal null dereference. This crashes
`initPlayer`, which is called on every authenticated page load — locking out the player.

**Fix:**
```php
if ($autre['idalliance'] > 0) {
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $autre['idalliance']);
    if ($duplicateur) {
        $bonusDuplicateur = 1 + ($duplicateur['duplicateur'] / 100);
    }
    // else: alliance deleted but autre.idalliance not cleared — silently use default bonus of 1
}
```

---

## ERR-R2-024

**Severity:** HIGH
**File:** `includes/player.php` lines 333 (`initPlayer()` — revenuEnergie division)
**Category:** Division by zero — production fill-time calculation

**Code:**
```php
'effetSup' => '... date(..., time() + 3600 * ($placeDepot - $ressources['energie']) / $revenu['energie']) ...',
```

**Crash scenario:**
`$revenuEnergie` (assigned to `$revenu['energie']`) can be zero or negative when:
(a) The player has a very high `producteur` drainage that exceeds generator output.
(b) `revenuEnergie()` returns 0 for a new player with niveau=0 generator.

Division by zero produces a PHP warning and `INF` timestamp, which `date()` converts to
`01/01/1970` — a misleading display. If strict error handling is on, this is a fatal.

**Fix:**
```php
'effetSup' => ($revenu['energie'] > 0)
    ? '... date(..., time() + 3600 * ($placeDepot - $ressources['energie']) / $revenu['energie']) ...'
    : '... (Production insuffisante) ...',
```

---

## ERR-R2-025

**Severity:** HIGH
**File:** `includes/player.php` lines 557–579 (`diminuerBatiment()`)
**Category:** Null dereference — building level fetch missing null check

**Unchecked call:**
```php
$batiments = dbFetchOne($base, "SELECT $nom FROM constructions WHERE login=?", 's', $joueur);

if ($batiments[$nom] > 1) {
```

**Crash scenario:**
`$batiments` is never null-checked. If `constructions` row is missing for the player
(same conditions as R2-021), `$batiments[$nom]` crashes. `diminuerBatiment` is called
from inside the combat transaction when buildings take lethal damage — a crash here
rolls back all combat results.

**Fix:**
```php
$batiments = dbFetchOne($base, "SELECT $nom FROM constructions WHERE login=?", 's', $joueur);
if (!$batiments) {
    error_log("diminuerBatiment: missing constructions for $joueur");
    return;
}
```

---

## ERR-R2-026

**Severity:** HIGH
**File:** `includes/game_actions.php` lines 38–48 (formation loop)
**Category:** Null dereference — molecule not found during formation completion

**Unchecked call:**
```php
$molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse']);

if ($actions['fin'] >= time()) {
    // ...
    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'ds',
        ($molecule['nombre'] + floor(...)), $actions['idclasse']);
```

**Crash scenario:**
`$molecule` is never null-checked. If the molecule class was deleted mid-formation
(e.g., player deleted the class in `armee.php` while formation was queued), `dbFetchOne`
returns null, and `$molecule['nombre']` is a fatal null dereference. This crashes
`updateActions` and prevents all other pending actions for this player from processing.

**Fix:**
```php
$molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse']);
if (!$molecule) {
    // Molecule class deleted, clean up orphan formation action
    dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
    continue;
}
```

---

## ERR-R2-027

**Severity:** HIGH
**File:** `includes/game_actions.php` lines 99–100 (pre-combat molecule decay)
**Category:** Null dereference — per-molecule lost stat update crashes

**Unchecked call:**
```php
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['attaquant']);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($molecules[$compteur - 1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $actions['attaquant']);
```

**Crash scenario:**
`$moleculesPerdues` is not null-checked. If the `autre` row is missing for the attacker,
`$moleculesPerdues['moleculesPerdues']` is a fatal null dereference. This runs in the
pre-combat travel decay loop — a crash here aborts combat processing for all subsequent
actions in the queue.

**Fix:**
```php
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['attaquant']);
$perduesActuelles = $moleculesPerdues ? (float)$moleculesPerdues['moleculesPerdues'] : 0.0;
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($molecules[$compteur - 1] - $moleculesRestantes + $perduesActuelles), $actions['attaquant']);
```

---

## ERR-R2-028

**Severity:** HIGH
**File:** `includes/game_actions.php` lines 461–462 (return travel decay)
**Category:** Null dereference — lost stat update on return journey crashes

**Unchecked call:**
```php
$moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($molecules[$compteur - 1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $joueur);
```

**Crash scenario:**
Exact same pattern as R2-027 but on the return journey. `$moleculesPerdues` unchecked.
A crash here prevents the attacker's surviving molecules from being returned to their
base — permanently lost.

**Fix:** Same null guard as R2-027.

---

## ERR-R2-029

**Severity:** HIGH
**File:** `includes/game_actions.php` lines 512–515 (envoi resource delivery)
**Category:** Null dereference — resource delivery crashes on missing row

**Unchecked calls:**
```php
$ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['receveur']);
$depotReceveur          = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
$maxStorageRecv         = placeDepot($depotReceveur['depot']);
```

**Crash scenario:**
Neither result is null-checked. If the recipient player deleted their account between
when the shipment was sent and when it arrives, both rows will be missing.
`$depotReceveur['depot']` is a fatal null dereference. The report is never created, the
sender's resources (already deducted) are lost permanently.

**Fix:**
```php
$ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['receveur']);
$depotReceveur          = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
if (!$ressourcesDestinataire || !$depotReceveur) {
    // Recipient no longer exists — silently drop the shipment, clean up
    dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);
    continue;
}
$maxStorageRecv = placeDepot((int)$depotReceveur['depot']);
```

---

## ERR-R2-030

**Severity:** HIGH
**File:** `includes/game_resources.php` lines 14–34 (`revenuEnergie()`)
**Category:** Null dereference — energy production crashes for all players

**Unchecked calls:**
```php
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
$niveauxAtomes = explode(';', $constructions['pointsCondenseur']);

$producteur = dbFetchOne($base, 'SELECT producteur FROM constructions WHERE login=?', 's', $joueur);
// ...
$idalliance = dbFetchOne($base, 'SELECT idalliance,totalPoints FROM autre WHERE login=?', 's', $joueur);
```

**Crash scenario:**
`$constructions` is used immediately without null check — `explode(';', null['pointsCondenseur'])`
is fatal. `revenuEnergie` is called from `updateRessources` (every login), from `initPlayer`
(every authenticated page), and from combat. A missing `constructions` row would make
the game completely unusable for a player. `$producteur` is also unchecked — its null
dereference at `drainageProducteur($producteur['producteur'])` would crash the same function.

**Fix:**
```php
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
if (!$constructions) { return 0; }
$producteur = dbFetchOne($base, 'SELECT producteur FROM constructions WHERE login=?', 's', $joueur);
if (!$producteur) { return 0; }
$idalliance = dbFetchOne($base, 'SELECT idalliance,totalPoints FROM autre WHERE login=?', 's', $joueur);
if (!$idalliance) { $idalliance = ['idalliance' => 0]; }
```

---

## ERR-R2-031

**Severity:** HIGH
**File:** `includes/game_resources.php` lines 71–73 (`revenuAtome()`)
**Category:** Null dereference — atom production crashes

**Unchecked call:**
```php
$pointsProducteur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $joueur);
$niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];
```

**Crash scenario:**
`$pointsProducteur` is not null-checked. `explode(';', null['pointsProducteur'])` is a
fatal error. `revenuAtome` is called from `updateRessources` (every login) and from
`initPlayer`. Missing `constructions` row = complete game lockout for the player.

**Fix:**
```php
$pointsProducteur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $joueur);
if (!$pointsProducteur) { return 0; }
$segments = explode(';', $pointsProducteur['pointsProducteur']);
$niveau = isset($segments[$num]) ? (int)$segments[$num] : 0;
```

---

## ERR-R2-032

**Severity:** HIGH
**File:** `includes/game_resources.php` lines 112–113 (`updateRessources()`)
**Category:** Null dereference — resource update crashes on missing tempsPrecedent

**Unchecked call:**
```php
$donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $joueur);
$nbsecondes = time() - $donnees['tempsPrecedent'];
```

**Crash scenario:**
If `$donnees` is null (missing `autre` row), `$donnees['tempsPrecedent']` is a fatal
null dereference. `updateRessources` is called on every authenticated page load. A
missing `autre` row completely locks the player out of the game.

**Fix:**
```php
$donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $joueur);
if (!$donnees) {
    error_log("updateRessources: missing autre row for $joueur");
    return;
}
$nbsecondes = time() - (int)$donnees['tempsPrecedent'];
```

---

## ERR-R2-033

**Severity:** MEDIUM
**File:** `marche.php` lines 10–11
**Category:** Null dereference — market entirely broken when cours table is empty

**Unchecked call:**
```php
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
$tabCours = explode(",", $val['tableauCours']);
```

**Crash scenario:**
If the `cours` table is empty (e.g., first install, season reset before any trade), `$val`
is null and `$val['tableauCours']` is a fatal null dereference. The entire `marche.php`
fails with a PHP fatal error — no buying, no selling, no sending. `$tabCours` is used
throughout the file for all market operations.

**Fix:**
```php
$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
if (!$val) {
    // Initialize default prices of 1.0 for all resources
    $defaultCours = implode(',', array_fill(0, count($nomsRes), '1.0'));
    dbExecute($base, 'INSERT INTO cours VALUES (default,?,?)', 'si', $defaultCours, time());
    $val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
}
$tabCours = explode(",", $val['tableauCours']);
```

---

## ERR-R2-034

**Severity:** MEDIUM
**File:** `marche.php` lines 25–28 (send resource IP check)
**Category:** Null dereference — self-trade IP check crashes on unknown player

**Unchecked calls:**
```php
$ipdd = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_POST['destinataire']);
$ipmm = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);

if ($ipmm['ip'] != $ipdd['ip']) {
```

**Crash scenario:**
`$ipdd` is fetched before the `joueurExiste` check (line 49). If the destinataire does
not exist in the database and `$ipdd` returns null, `$ipdd['ip']` is a fatal null
dereference. The `joueurOuPas` check at line 49 is nested inside the block that already
executed the IP comparison — so the crash happens before the existence check.

**Fix:**
Reverse the order: check player existence first, then fetch IP:
```php
$verification = dbFetchOne($base, 'SELECT count(*) AS joueurOuPas FROM membre WHERE login=?', 's', $_POST['destinataire']);
if ($verification['joueurOuPas'] != 1) { $erreur = "Le destinataire n'existe pas."; }
else {
    $ipdd = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_POST['destinataire']);
    $ipmm = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);
    if (!$ipdd || !$ipmm || $ipmm['ip'] == $ipdd['ip']) { $erreur = "..."; }
    // ...
}
```

---

## ERR-R2-035

**Severity:** MEDIUM
**File:** `marche.php` lines 60–63 (market send — exchange rate division)
**Category:** Division by zero — revenuEnergie used as divisor

**Code:**
```php
if ($revenuEnergie >= revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire'])) {
    $rapportEnergie = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']) / $revenuEnergie;
```

**Crash scenario:**
`$revenuEnergie` (the sender's energy revenue, set by `initPlayer`) can be 0 when:
(a) The generator is level 0 (new player).
(b) Producteur drainage exceeds generator output.

Division by zero produces `INF` in PHP (a warning, not fatal), but `$rapportEnergie = INF`
means the recipient receives infinite energy — an exploit. The JS mirror on line 550
also divides by `echange[numVente]` which could be 0 if market prices collapse.

**Fix:**
```php
$recipientRevenue = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']);
$rapportEnergie = ($revenuEnergie > 0)
    ? min(1.0, $recipientRevenue / $revenuEnergie)
    : 1.0; // no penalty if sender has 0 production
```

---

## ERR-R2-036

**Severity:** MEDIUM
**File:** `marche.php` lines 71–74 (send resource — atom exchange rate division)
**Category:** Division by zero — revenuAtome used as divisor

**Code:**
```php
foreach ($nomsRes as $num => $ressource) {
    if ($revenu[$ressource] >= revenuAtome($num, $_POST['destinataire'])) {
        ${'rapport' . $ressource} = revenuAtome($num, $_POST['destinataire']) / $revenu[$ressource];
```

**Crash scenario:**
`$revenu[$ressource]` is 0 for any atom the sender has no production points in.
Division by zero produces `INF` — the recipient receives an infinite ratio of that atom
for free. This is both a crash risk (if strict error handling) and a game-breaking exploit.

**Fix:**
```php
$recipientRevAtome = revenuAtome($num, $_POST['destinataire']);
$senderRevAtome    = max(1, $revenu[$ressource]); // guard against 0
${'rapport' . $ressource} = min(1.0, $recipientRevAtome / $senderRevAtome);
```

---

## ERR-R2-037

**Severity:** MEDIUM
**File:** `marche.php` line 310 (sell — price update formula)
**Category:** Division by zero — inverse price update when market price collapses

**Code:**
```php
$ajout = 1 / (1 / $tabCours[$num] + $volatilite * $_POST['nombreRessourceAVendre'] / $placeDepot);
```

**Crash scenario:**
`$tabCours[$num]` could theoretically reach 0 if `MARKET_PRICE_FLOOR` is 0 (verify in
config). `1 / 0` is a PHP warning + INF. If `$placeDepot` is also 0 (new player with
depot level 0), the divisor is `0 + INF` which gives 0, and then `1/0` for `$ajout`.
While `MARKET_PRICE_FLOOR` is presumably > 0, the floor guard is applied *after* this
calculation at line 315 — so the intermediate `1 / (1/0)` crash happens first.

**Fix:**
```php
$currentPrice = max(MARKET_PRICE_FLOOR, $tabCours[$num]);
$ajout = 1 / (1 / $currentPrice + $volatilite * $_POST['nombreRessourceAVendre'] / max(1, $placeDepot));
$ajout = max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout));
```

---

## ERR-R2-038

**Severity:** MEDIUM
**File:** `attaquer.php` lines 27–32 (espionage — target player fetch unchecked)
**Category:** Null dereference — espionage crash on non-existent player

**Unchecked call:**
```php
$membreJoueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
// ...
$distance = pow(pow($membre['x'] - $membreJoueur['x'], 2) + pow($membre['y'] - $membreJoueur['y'], 2), 0.5);
```

**Crash scenario:**
`$membreJoueur` is not null-checked. If the target player does not exist (race condition
between form display and submit), `$membreJoueur['x']` is a fatal null dereference.
The espionage action is never inserted, and the player's neutrinos are never deducted —
but the error page reveals an unhandled exception.

**Fix:**
```php
$membreJoueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
if (!$membreJoueur) {
    $erreur = "Ce joueur n'existe pas.";
} else {
    $distance = pow(pow($membre['x'] - $membreJoueur['x'], 2) + pow($membre['y'] - $membreJoueur['y'], 2), 0.5);
    // ...
}
```

---

## ERR-R2-039

**Severity:** MEDIUM
**File:** `attaquer.php` lines 83–84 (attack — defender position fetch unchecked)
**Category:** Null dereference — distance calculation crashes silently

**Unchecked call:**
```php
$positions = dbFetchOne($base, 'SELECT x,y FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
// ...
if ($moleculesAttaque['formule'] != "Vide" && $_POST['nbclasse' . $c] > 0) {
    $distance = pow(pow($membre['x'] - $positions['x'], 2) + pow($membre['y'] - $positions['y'], 2), 0.5);
```

**Crash scenario:**
`$positions` is not null-checked. If the target player deleted their account between
the map click and the form submit, `$positions` is null and `$positions['x']` is fatal.
`$distance` would be 0, meaning the attack arrives instantly — a potential exploit if
not caught.

**Fix:**
```php
$positions = dbFetchOne($base, 'SELECT x,y FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
if (!$positions) {
    $erreur = "Ce joueur n'existe pas.";
    goto attack_end; // or restructure with else
}
```

---

## ERR-R2-040

**Severity:** MEDIUM
**File:** `attaquer.php` lines 456–463 (attack view — per-class molecule fetch in JS builder)
**Category:** Null dereference — atom count for JS incorrectly computed when null

**Unchecked call:**
```php
for ($i = 1; $i <= $nbClasses; $i++) {
    $molecules1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $i);
    $totAtomes = 0;
    foreach ($nomsRes as $num => $res) {
        $totAtomes += $molecules1[$res];
    }
```

**Crash scenario:**
`$molecules1` is not null-checked. If the player has fewer molecule classes than
`$nbClasses` (stale data from a concurrent deletion), `$molecules1[$res]` is a null
dereference. The JavaScript cost-display builder crashes, producing a broken page with
no attack form rendered.

**Fix:**
```php
$molecules1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $i);
if (!$molecules1) { continue; }
$totAtomes = 0;
foreach ($nomsRes as $num => $res) {
    $totAtomes += (int)($molecules1[$res] ?? 0);
}
```

---

## ERR-R2-041

**Severity:** MEDIUM
**File:** `attaquer.php` lines 266–268 (map — statistiques fetch unchecked)
**Category:** Null dereference — map crashes when statistiques row is missing

**Unchecked call:**
```php
$tailleCarte = dbFetchOne($base, 'SELECT tailleCarte FROM statistiques');

$carte = [];
for ($i = 0; $i < $tailleCarte['tailleCarte']; $i++) {
```

**Crash scenario:**
`$tailleCarte` is not null-checked. If the `statistiques` table is empty (fresh install,
reset), `$tailleCarte['tailleCarte']` is a fatal null dereference. The entire map page
crashes, preventing all attack/espionage operations.

**Fix:**
```php
$tailleCarte = dbFetchOne($base, 'SELECT tailleCarte FROM statistiques');
$mapSize = ($tailleCarte && isset($tailleCarte['tailleCarte'])) ? (int)$tailleCarte['tailleCarte'] : 10;
```

---

## ERR-R2-042

**Severity:** MEDIUM
**File:** `alliance.php` lines 74–76 (duplicateur cost calculation)
**Category:** Null dereference — duplicateur cost crashes when alliance deleted mid-session

**Unchecked calls:**
```php
$idalliance  = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
$duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
$cout = round(10 * pow(2.5, ($duplicateur['duplicateur'] + 1)) * ...);
```

**Crash scenario:**
`$idalliance` is not null-checked before accessing `$idalliance['idalliance']`.
`$duplicateur` is not null-checked before accessing `$duplicateur['duplicateur']`.
If the alliance was deleted between when the player loaded the page and when they submitted
the upgrade form, `$duplicateur` returns null and `$duplicateur['duplicateur']` is a fatal
null dereference. The entire `alliance.php` page fails.

**Fix:**
```php
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
if (!$idalliance || !$idalliance['idalliance']) {
    $erreur = "Vous n'avez pas d'alliance.";
    goto display_only;
}
$duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
if (!$duplicateur) {
    $erreur = "Alliance introuvable.";
    goto display_only;
}
```

---

## ERR-R2-043

**Severity:** MEDIUM
**File:** `alliance.php` lines 98–99 (research upgrade)
**Category:** Null dereference — research data missing crashes upgrade

**Unchecked call:**
```php
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
$currentLevel = intval($allianceData[$techName]);
```

**Crash scenario:**
`$allianceData` is not null-checked. If the alliance was deleted between page load and
form submit, this returns null and `$allianceData[$techName]` is a fatal null dereference.
This crashes the entire alliance page for all visitors, not just the submitter.

**Fix:**
```php
$allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
if (!$allianceData) {
    $erreur = "Alliance introuvable.";
    // fall through to display
} else {
    $currentLevel = intval($allianceData[$techName]);
    // ...
}
```

---

## ERR-R2-044

**Severity:** MEDIUM
**File:** `alliance.php` line 185 (member average calculation)
**Category:** Division by zero — alliance member count is zero

**Code:**
```php
echo chipInfo('<span class="important">Moyenne : </span>' . floor($pointstotaux / $nbjoueurs), ...);
```

**Crash scenario:**
`$nbjoueurs` is computed from `mysqli_num_rows($ex2)` on line 162. If the alliance has
no members (all left between queries), `$nbjoueurs` is 0. `$pointstotaux / 0` is a PHP
warning and produces `INF`, displayed as an empty string — but triggers log noise and
can cause `floor(INF)` = INF display.

**Fix:**
```php
echo chipInfo('<span class="important">Moyenne : </span>' . ($nbjoueurs > 0 ? floor($pointstotaux / $nbjoueurs) : 0), ...);
```

---

## ERR-R2-045

**Severity:** MEDIUM
**File:** `alliance.php` lines 120–123 (accept invitation — player count check)
**Category:** Null dereference — invitation data missing

**Unchecked call:**
```php
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);

$ex = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
```

**Crash scenario:**
`$idalliance` is not null-checked. If the invitation was deleted (expired or revoked)
between display and submit, `$idalliance` is null and `$idalliance['idalliance']` is
a fatal null dereference. The player sees a PHP error instead of a friendly message.

**Fix:**
```php
$idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
if (!$idalliance) {
    $erreur = "Cette invitation n'existe plus.";
} else {
    $ex = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
    // ...
}
```

---

## ERR-R2-046

**Severity:** MEDIUM
**File:** `classement.php` lines 54–58 (player search rank)
**Category:** Null dereference — search rank silently wrong

**Unchecked call:**
```php
$playerScore = dbFetchOne($base, 'SELECT ' . $order . ' AS score FROM autre WHERE login=?', 's', $searchLogin);
if ($playerScore) {
    $rankRow = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM autre WHERE ' . $order . ' > ?', 'd', $playerScore['score']);
    $place = ($rankRow['rank'] ?? 0) + 1;
```

**Crash scenario:**
`$playerScore` is already guarded by `if ($playerScore)`. However, `$rankRow` is not
null-checked — `($rankRow['rank'] ?? 0)` uses `??` which correctly defaults to 0 on null.
This is actually safe. However, `$playerScore['score']` passed as type `'d'` (double) to
`dbFetchOne` via `dbQuery` may fail if the column value is NULL in DB — producing a
misleading rank of 1 for a player who has 0 points stored as NULL.

**Recommendation:**
Ensure `score` in `autre` defaults to 0 (NOT NULL constraint), and verify `$playerScore['score']`
is not NULL before using as a bound parameter:
```php
$score = (float)($playerScore['score'] ?? 0);
$rankRow = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM autre WHERE ' . $order . ' > ?', 'd', $score);
```

---

## ERR-R2-047

**Severity:** MEDIUM
**File:** `classement.php` lines 83–87 (logged-in player rank)
**Category:** Null dereference — player rank silently defaults to page 1 when row missing

**Unchecked call:**
```php
$myScore = dbFetchOne($base, 'SELECT ' . $order . ' AS score FROM autre WHERE login=?', 's', $_SESSION['login']);
if ($myScore) {
    $myRank = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM autre WHERE ' . $order . ' > ?', 'd', $myScore['score']);
    $place = ($myRank['rank'] ?? 0) + 1;
```

**Crash scenario:**
`$myRank` uses `??` correctly — this is safe. However, if the logged-in player's `autre`
row is missing entirely, `$myScore` is null, the `if` fails, `$pageParDefaut` is never
set, and the code falls through to line 104:
```php
$page = isset($_GET['page']) ? intval($_GET['page']) : $pageParDefaut;
```
`$pageParDefaut` is undefined, PHP produces a notice and defaults to null, `intval(null)` = 0,
and the page clamp (`if ($page < 1)`) resets to `$pageParDefaut` again — infinite loop of
undefined variable references.

**Fix:**
```php
$pageParDefaut = 1; // always initialize
if (isset($_SESSION['login'])) {
    $myScore = dbFetchOne($base, 'SELECT ' . $order . ' AS score FROM autre WHERE login=?', 's', $_SESSION['login']);
    if ($myScore) { ... $pageParDefaut = ceil($place / $nombreDeJoueursParPage); }
}
```

---

## ERR-R2-048

**Severity:** MEDIUM
**File:** `armee.php` lines 92–97 (formation — molecule data unchecked)
**Category:** Null dereference — molecule class missing when forming

**Unchecked call:**
```php
$donneesFormer = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $_POST['emplacementmoleculeformer']);
// ...
foreach ($nomsRes as $num => $ressource) {
    if (($donneesFormer[$ressource] * $_POST['nombremolecules']) > $ressources[$ressource]) {
```

**Crash scenario:**
`$donneesFormer` is not null-checked. If the molecule class does not exist (concurrent
deletion), `$donneesFormer[$ressource]` is a fatal null dereference. The formation is
never queued, but the player sees a PHP error instead of a validation message.

**Fix:**
```php
$donneesFormer = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $_POST['emplacementmoleculeformer']);
if (!$donneesFormer || $donneesFormer['formule'] === 'Vide') {
    $erreur = "Cette classe de molécule n'existe pas.";
} else {
    // formation logic
}
```

---

## ERR-R2-049

**Severity:** MEDIUM
**File:** `armee.php` lines 174–175 (molecule creation — energy/class level check)
**Category:** Null dereference — creation fails with wrong error when DB row missing

**Unchecked call:**
```php
$cout = dbFetchOne($base, 'SELECT energie, niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
if ($cout['energie'] >= (coutClasse($cout['niveauclasse']))) {
```

**Crash scenario:**
`$cout` is not null-checked. If `ressources` row is missing, `$cout['energie']` is a
fatal null dereference. The player sees a PHP error during molecule class creation.

**Fix:**
```php
$cout = dbFetchOne($base, 'SELECT energie, niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
if (!$cout) {
    $erreur = "Erreur lors de la lecture de vos ressources.";
} elseif ($cout['energie'] >= coutClasse($cout['niveauclasse'])) {
    // creation logic
}
```

---

## ERR-R2-050

**Severity:** MEDIUM
**File:** `armee.php` lines 250–263 (formation display — molecule per queue item)
**Category:** Null dereference — formation display breaks if molecule deleted

**Unchecked call:**
```php
$moleculeEnCours = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 'i', $actionsformation['idclasse']);
// ...
if ($actionsformation['idclasse'] != 'neutrino') {
    $affichageFormule = couleurFormule($moleculeEnCours['formule']);
```

**Crash scenario:**
`$moleculeEnCours` is not null-checked. If the molecule class was deleted (e.g., player
deleted it while formation was in progress, then refreshed the page), `$moleculeEnCours`
is null and `$moleculeEnCours['formule']` causes a fatal null dereference. The entire
`armee.php` page fails to render, preventing the player from managing their army at all.

**Fix:**
```php
$moleculeEnCours = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 'i', $actionsformation['idclasse']);
if (!$moleculeEnCours && $actionsformation['idclasse'] != 'neutrino') {
    // Orphan formation — clean up
    dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actionsformation['id']);
    continue;
}
$affichageFormule = ($actionsformation['idclasse'] != 'neutrino')
    ? couleurFormule($moleculeEnCours['formule'])
    : $actionsformation['formule'];
```

---

## ERR-R2-051

**Severity:** MEDIUM
**File:** `armee.php` line 377 (molecule creation display — niveauclasse fetch)
**Category:** Null dereference — creation button crashes when ressources row missing

**Unchecked call:**
```php
$cout = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
echo '... coutEnergie(coutClasse($cout['niveauclasse'])) ...'
```

**Crash scenario:**
`$cout` not checked — same as R2-049. In the page-render loop (not the POST handler),
if `ressources` row is missing, every empty molecule slot in the list display causes a
fatal null dereference, making the entire army page crash.

**Fix:** Same null guard as R2-049.

---

## ERR-R2-052

**Severity:** LOW
**File:** `includes/player.php` line 193 (`initPlayer()` — resource fill-time calculation)
**Category:** Division by zero — `max(1, $revenu[$ressource])` guards against zero correctly

**Code:**
```php
$max = 0;
foreach ($nomsRes as $num => $ressource) {
    $max = max($max, 3600 * ($placeDepot - $ressources[$ressource]) / max(1, $revenu[$ressource]));
}
```

**Observation:**
This is correctly guarded with `max(1, ...)`. However, if `$placeDepot` is 0 (new player,
depot level 0), `$placeDepot - $ressources[$ressource]` is negative (player has no storage
cap), giving a negative fill time. `date()` on a negative offset produces a past date.
Not a crash but a confusing display.

**Fix:**
```php
if ($placeDepot > 0 && $revenu[$ressource] > 0) {
    $max = max($max, 3600 * ($placeDepot - $ressources[$ressource]) / $revenu[$ressource]);
}
```

---

## ERR-R2-053

**Severity:** LOW
**File:** `includes/db_helpers.php` lines 60–63 (`allianceResearchLevel()`)
**Category:** Cascading null — defensive but logging too aggressive

**Code:**
```php
$autre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
if (!$autre || $autre['idalliance'] <= 0) return 0;
$alliance = dbFetchOne($base, 'SELECT ' . $techName . ' FROM alliances WHERE id=?', 'i', $autre['idalliance']);
return $alliance ? intval($alliance[$techName]) : 0;
```

**Observation:**
This function is correctly null-guarded. However, it makes 2 DB queries every time it
is called, and `allianceResearchBonus()` calls it in a loop over all researches. With 5
tech types and multiple calls per combat, this is 10+ queries for something that could
be cached. Not a crash but a performance/reliability concern under load.

**Recommendation:**
Cache the player's alliance research data in `initPlayer`'s cache snapshot to avoid
repeated queries. This eliminates the failure surface for each individual lookup as well.

---

## Summary

| ID | Severity | File | Pattern | Impact |
|---|---|---|---|---|
| ERR-R2-001 | CRITICAL | combat.php:28 | Null from dbFetchOne on attacker constructions | Combat stuck permanently |
| ERR-R2-002 | CRITICAL | combat.php:34 | Null from dbFetchOne on defender constructions | Combat stuck permanently |
| ERR-R2-003 | CRITICAL | combat.php:206 | Null ionisateur used in damage formula | Wrong attack damage silently |
| ERR-R2-004 | CRITICAL | combat.php:212 | Null champdeforce in defense formula | Wrong defense damage silently |
| ERR-R2-005 | CRITICAL | combat.php:45 | Null idalliance/duplicateur in alliance bonus | Combat crash if alliance deleted |
| ERR-R2-006 | HIGH | combat.php:360 | Null ressourcesDefenseur/Joueur | Combat transaction rollback |
| ERR-R2-007 | HIGH | combat.php:534 | Null pointsBDAttaquant/Defenseur | Points not updated |
| ERR-R2-008 | HIGH | combat.php:557 | Null perduesAttaquant/Defenseur | Transaction rollback, no report |
| ERR-R2-009 | HIGH | combat.php:577 | Null depotAtt in pillage cap | Crash after resources updated |
| ERR-R2-010 | HIGH | combat.php:603 | Null depotDef in defense reward | Crash on defender win |
| ERR-R2-011 | HIGH | combat.php:614 | Null nbattaques | Crash at end of combat |
| ERR-R2-012 | HIGH | combat.php:620 | Null joueur/idallianceAutre in war query | Full rollback at end of combat |
| ERR-R2-013 | HIGH | combat.php:427 | Null constructions in building damage | Crash on attacker win |
| ERR-R2-014 | HIGH | formulas.php:86 | Null medalData in attaque() | Combat crash, wrong damage |
| ERR-R2-015 | HIGH | formulas.php:104 | Null medalData in defense() | Combat crash, wrong damage |
| ERR-R2-016 | HIGH | formulas.php:134 | Null medalData in pillage() | Combat crash, wrong pillage |
| ERR-R2-017 | HIGH | formulas.php:168 | Null constructions in tempsFormation() | Formation queue corrupted |
| ERR-R2-018 | HIGH | formulas.php:183 | Multiple nulls in coefDisparition() | Decay crashes everywhere |
| ERR-R2-019 | HIGH | player.php:30 | Null statistiques in inscrire() | Registration crash |
| ERR-R2-020 | HIGH | player.php:76 | Null autre in ajouterPoints() | Full combat transaction rollback |
| ERR-R2-021 | HIGH | player.php:150 | Null constructions in initPlayer() | Game inaccessible for player |
| ERR-R2-022 | HIGH | player.php:161 | explode out-of-bounds on pointsProducteur | Wrong production values |
| ERR-R2-023 | HIGH | player.php:215 | Null duplicateur in initPlayer() | Page crash every auth load |
| ERR-R2-024 | HIGH | player.php:333 | Division by zero revenuEnergie==0 | INF date display |
| ERR-R2-025 | HIGH | player.php:557 | Null batiments in diminuerBatiment() | Combat rollback |
| ERR-R2-026 | HIGH | game_actions.php:41 | Null molecule in formation loop | Action loop crash |
| ERR-R2-027 | HIGH | game_actions.php:99 | Null moleculesPerdues in travel decay | Action loop crash |
| ERR-R2-028 | HIGH | game_actions.php:461 | Null moleculesPerdues on return | Molecules permanently lost |
| ERR-R2-029 | HIGH | game_actions.php:512 | Null recipient rows in envoi delivery | Resources permanently lost |
| ERR-R2-030 | HIGH | game_resources.php:14 | Null constructions in revenuEnergie() | Game lockout |
| ERR-R2-031 | HIGH | game_resources.php:71 | Null constructions in revenuAtome() | Game lockout |
| ERR-R2-032 | HIGH | game_resources.php:112 | Null autre in updateRessources() | Game lockout |
| ERR-R2-033 | MEDIUM | marche.php:10 | Null from empty cours table | Market entirely broken |
| ERR-R2-034 | MEDIUM | marche.php:25 | Null ipdd before existence check | Market crash on bad target |
| ERR-R2-035 | MEDIUM | marche.php:63 | Division by zero revenuEnergie==0 | Infinite energy exploit |
| ERR-R2-036 | MEDIUM | marche.php:71 | Division by zero revenuAtome==0 | Infinite atoms exploit |
| ERR-R2-037 | MEDIUM | marche.php:310 | Possible /0 in inverse price formula | Price update crash |
| ERR-R2-038 | MEDIUM | attaquer.php:32 | Null membreJoueur in espionage | PHP error on bad target |
| ERR-R2-039 | MEDIUM | attaquer.php:83 | Null positions in attack distance | 0-distance attack exploit |
| ERR-R2-040 | MEDIUM | attaquer.php:456 | Null molecules1 in JS builder | Attack form broken |
| ERR-R2-041 | MEDIUM | attaquer.php:266 | Null tailleCarte from statistiques | Map crash |
| ERR-R2-042 | MEDIUM | alliance.php:74 | Null duplicateur in cost calc | Alliance page crash |
| ERR-R2-043 | MEDIUM | alliance.php:98 | Null allianceData in research upgrade | Alliance page crash |
| ERR-R2-044 | MEDIUM | alliance.php:185 | Division by zero nbjoueurs==0 | INF average display |
| ERR-R2-045 | MEDIUM | alliance.php:120 | Null idalliance in invitation accept | PHP error on expired invite |
| ERR-R2-046 | MEDIUM | classement.php:54 | Null score treated as 0 | Wrong rank display |
| ERR-R2-047 | MEDIUM | classement.php:83 | pageParDefaut undefined when autre missing | Undefined variable loop |
| ERR-R2-048 | MEDIUM | armee.php:92 | Null donneesFormer in formation | PHP error on concurrent delete |
| ERR-R2-049 | MEDIUM | armee.php:174 | Null cout in creation check | PHP error if ressources missing |
| ERR-R2-050 | MEDIUM | armee.php:250 | Null moleculeEnCours in display loop | Army page crash |
| ERR-R2-051 | MEDIUM | armee.php:377 | Null cout in display render | Army page crash |
| ERR-R2-052 | LOW | player.php:193 | Negative fill-time when placeDepot==0 | Wrong past-date display |
| ERR-R2-053 | LOW | db_helpers.php:60 | 2 queries per research lookup, no cache | Performance/reliability |

**Total issues found: 53**
- CRITICAL: 5 (all in combat path — molecules lost permanently, transactions rolled back)
- HIGH: 27 (game lockout, wrong combat math, permanent resource loss)
- MEDIUM: 19 (page crashes, exploits, wrong data)
- LOW: 2 (display glitches, performance)

**Most dangerous cascade:**
ERR-R2-001 through ERR-R2-013 form a chain. Any single null in the combat include file
causes the `try` block to throw, `mysqli_rollback` undoes all changes, but `attaqueFaite=1`
was set before the transaction (the CAS guard at game_actions.php line 71). The attack
is permanently marked done with no outcome — molecules are gone, no report exists, no
points awarded, no resources transferred. Players have no way to recover.

**Recommended priority:**
1. Fix all CRITICAL issues in combat.php first — add null guards with `throw new Exception()`
   so the transaction rollback is controlled and at minimum logs the failure.
2. Fix HIGH issues in formulas.php (`attaque`, `defense`, `pillage`) which are called
   inside the same combat transaction.
3. Fix HIGH issues in player.php/game_resources.php to prevent game lockouts.
4. Fix MEDIUM issues in marche.php (division-by-zero exploits are particularly dangerous).
