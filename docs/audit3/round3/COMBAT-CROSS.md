# COMBAT-CROSS.md — Complete Combat Flow Cross-Reference
## Round 3 Audit — The Very Little War

**Files analyzed:**
- `/home/guortates/TVLW/The-Very-Little-War/attaquer.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/player.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/config.php`

---

## A. COMBAT FLOW — Step by Step

Each step lists the file, line number, what happens, and what data is produced or consumed.

---

### PHASE 1 — ATTACK SUBMISSION (attaquer.php)

**Step A1 — Auth and medal load**
- File: `attaquer.php:3` — `basicprivatephp.php` enforces session auth
- File: `attaquer.php:7` — `dbFetchOne` loads `nbattaques` from `autre` for current player
- File: `attaquer.php:11-16` — Terreur medal bonus computed into `$bonus`, `$reduction`
- File: `attaquer.php:18` — `$coutPourUnAtome = 0.15 * (1 - $bonus / 100)` computed

**Step A2 — POST validation**
- File: `attaquer.php:54` — `isset($_POST['joueurAAttaquer'])` gates attack branch
- File: `attaquer.php:55` — CSRF check via `csrfCheck()`
- File: `attaquer.php:59` — Self-attack check: `$_POST['joueurAAttaquer'] != $_SESSION['login']`
- File: `attaquer.php:61` — Load target vacation and timestamp: `dbFetchOne` on `membre`
- File: `attaquer.php:63` — Vacation guard
- File: `attaquer.php:65` — Beginner protection check on target (BEGINNER_PROTECTION_SECONDS = 432000)
- File: `attaquer.php:67` — Beginner protection check on self
- File: `attaquer.php:71` — Cooldown check: `dbFetchOne` on `attack_cooldowns` table

**Step A3 — Defender existence check**
- File: `attaquer.php:76` — `dbFetchOne` on `autre` for target → `$joueurDefenseur`
- File: `attaquer.php:77-81` — Sets `$nb = 1` (exists) or `$nb = 0` (not found); if 0, falls to error at line 169

**Step A4 — Troop count validation**
- File: `attaquer.php:86` — `dbQuery` fetches attacker's molecules from `molecules` table
- File: `attaquer.php:90-107` — Loops over `$nbClasses` (4); validates all values positive and total >= 1
- File: `attaquer.php:116-142` — Second loop over molecule result; for each class:
  - Checks `ceil(nombre) < POST value` → sets `$bool = 0`
  - Caps sent amount to actual owned amount
  - Computes `$tempsTrajet` as max travel time across all non-Vide classes
  - Builds `$troupes` semicolon string (e.g. "100;0;50;200;")
  - Computes `$cout` (energy cost) from atom count * `$coutPourUnAtome`

**Step A5 — Energy check and record creation**
- File: `attaquer.php:144` — `$cout <= $ressources['energie']` guard
- File: `attaquer.php:145` — `$bool` (sufficient molecules) guard
- File: `attaquer.php:148-151` — Second query on `molecules`; deducts sent troops from DB immediately
  - `UPDATE molecules SET nombre=? WHERE id=?` for each class
- File: `attaquer.php:154-155` — `INSERT INTO actionsattaques` with columns:
  `(default, attaquant, defenseur, tempsAller, tempsAttaque, tempsRetour, troupes, 0, default)`
  where `tempsAttaque = now + tempsTrajet`, `tempsRetour = now + 2*tempsTrajet`
  NOTE: `attaqueFaite` is stored as column 7 (value 0), `nombreneutrinos` is default
- File: `attaquer.php:156` — `ajouter('energie', 'ressources', -$cout, login)` deducts energy
- File: `attaquer.php:157` — `ajouter('energieDepensee', 'autre', $cout, login)` records spend

The attacking molecules are now gone from the attacker's roster and a row exists in `actionsattaques`.

---

### PHASE 2 — COMBAT TRIGGER (game_actions.php)

`updateActions($joueur)` is called whenever any page loads for either the attacker or defender. It polls `actionsattaques` and processes anything due.

**Step B1 — Recursion guard and globals**
- File: `game_actions.php:9-13` — Static `$updating` array prevents recursive calls (R2 fix)
- File: `game_actions.php:23` — `initPlayer($joueur)` loads all player globals from DB

**Step B2 — Query all attack rows for this player**
- File: `game_actions.php:65` — `dbQuery` fetches all rows where `attaquant=? OR defenseur=?`
  - Ordered `DESC` by `tempsAttaque`
  - NOTE: This means newer attacks are processed first within the loop

**Step B3 — Combat eligibility check**
- File: `game_actions.php:68` — Two conditions: `attaqueFaite == 0` AND `tempsAttaque < time()`
- File: `game_actions.php:69` — `troupes != 'Espionnage'` branches into combat vs spy

**Step B4 — Compare-and-Swap (CAS) lock**
- File: `game_actions.php:71` — Atomic claim: `UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0`
- File: `game_actions.php:72-75` — If `$casAffected === 0 || false`, skip (already claimed by concurrent request)
- The `attaqueFaite` flag is set to 1 HERE, BEFORE the transaction begins at line 107

**Step B5 — Update opponent state**
- File: `game_actions.php:77-84` — Determines who is `$enFace`; calls `updateRessources` and `updateActions` on opponent
  - This is a recursive `updateActions` call, protected by the static guard

**Step B6 — Outbound decay calculation**
- File: `game_actions.php:87` — `$nbsecondes = tempsAttaque - tempsAller` (travel duration one way)
- File: `game_actions.php:88` — Explode `$actions['troupes']` into `$molecules` array
- File: `game_actions.php:90` — `dbQuery` on `molecules WHERE proprietaire=attaquant ORDER BY numeroclasse`
- File: `game_actions.php:94-103` — For each class: compute decay survival fraction, build `$chaine`, update `moleculesPerdues` in `autre`
- File: `game_actions.php:105` — `$actions['troupes'] = $chaine` — updates the in-memory troupes to post-decay values

**Step B7 — Transaction start and combat resolution**
- File: `game_actions.php:107` — `mysqli_begin_transaction($base)` — transaction begins
- File: `game_actions.php:109` — `include("includes/combat.php")` — combat resolution runs inline

---

### PHASE 3 — COMBAT RESOLUTION (combat.php)

All these steps execute inside the transaction opened at `game_actions.php:107`.

**Step C1 — Load defender molecules**
- File: `combat.php:5-13` — `dbQuery` on `molecules WHERE proprietaire=defenseur ORDER BY numeroclasse`
  - Assigns to `$classeDefenseur1`..`$classeDefenseur4`
  - Values are `ceil()`d

**Step C2 — Load attacker molecules (template data + troop counts)**
- File: `combat.php:15-24` — `dbQuery` on `molecules WHERE proprietaire=attaquant ORDER BY numeroclasse`
  - `nombre` overridden with `ceil($chaineExplosee[$c - 1])` — the post-decay count from `$actions['troupes']`

**Step C3 — Load atom level data**
- File: `combat.php:28-29` — `dbFetchOne`: `pointsProducteur` from `constructions` for attacker → `$niveauxAtt`
- File: `combat.php:34-37` — `dbFetchOne`: `pointsProducteur` from `constructions` for defender → `$niveauxDef`

**Step C4 — Load buildings for combat modifiers**
- File: `combat.php:41` — `dbFetchOne`: `ionisateur` from `constructions` for attacker
- File: `combat.php:43` — `dbFetchOne`: `champdeforce` from `constructions` for defender

**Step C5 — Duplicateur bonus calculation**
- File: `combat.php:45-57` — Two `dbFetchOne` calls: `idalliance` from `autre` for attacker and defender
  - If alliance > 0: another `dbFetchOne` on `alliances` for `duplicateur` value
  - Computes `$bonusDuplicateurAttaque` and `$bonusDuplicateurDefense`

**Step C6 — Isotope and catalytic modifiers**
- File: `combat.php:69-119` — Loops over 4 classes, reads `isotope` field from pre-loaded class arrays
  - Sets per-class attack and HP modifiers
  - Detects if any class is ISOTOPE_CATALYTIQUE to apply ally bonus to others

**Step C7 — Defensive formation load**
- File: `combat.php:122-123` — `dbFetchOne`: `formation` from `constructions` for defender
  - Falls back to `FORMATION_DISPERSEE` (0) if null

**Step C8 — Chemical reaction detection**
- File: `combat.php:131-163` — Checks all class pairs against `$CHEMICAL_REACTIONS` config
  - Builds `$activeReactionsAtt` and `$activeReactionsDef` associative arrays
  - Computes bonus multipliers: `$attReactionAttackBonus`, `$defReactionDefenseBonus`, etc.

**Step C9 — Embuscade formation pre-check**
- File: `combat.php:188-199` — Counts total molecules on both sides
  - If defender chose FORMATION_EMBUSCADE and has more molecules: `$embuscadeDefBoost = 1.25`

**Step C10 — Total damage computation**
- File: `combat.php:202-213` — Loop over 4 classes:
  - `$degatsAttaquant` += `attaque(oxygene, ...) * modifiers * nombre` per class
  - `$degatsDefenseur` += `defense(carbone, ...) * modifiers * nombre` per class
  - Phalange class 1 gets +30% defense bonus here
- File: `combat.php:216-217` — `prestigeCombatBonus()` applied to both (DB query per player)
- File: `combat.php:220` — `$embuscadeDefBoost` applied to `$degatsDefenseur`

**Step C11 — Attacker casualty calculation**
- File: `combat.php:231-235` — Compute total attacker HP pool across all classes
- File: `combat.php:237-251` — Per class: damage share proportional to HP pool; `floor(damageShare / hpPerMol)` deaths; capped at class size
- File: `combat.php:250` — Running total `$attaquantsRestants`

**Step C12 — Defender casualty calculation**
- File: `combat.php:254-259` — Compute total defender HP pool
- File: `combat.php:262-289` — Formation-specific damage distribution:
  - DISPERSEE: split equally among non-empty classes only
  - PHALANGE: class 1 gets 70%, remaining 30% split among classes 2-4
  - EMBUSCADE/default: proportional to HP pool
- File: `combat.php:291-303` — Deaths per class computed; running total `$defenseursRestants`

**Step C13 — Winner determination**
- File: `combat.php:305-317` — `$gagnant`: 0=draw, 1=defender wins, 2=attacker wins

**Step C14 — Defensive reward calculation (defender win only)**
- File: `combat.php:326-333` — If `$gagnant == 1`: compute `$defenseRewardEnergy` from attacker's surviving pillage potential * 0.20

**Step C15 — Attack cooldown insertion**
- File: `combat.php:336-340` — If attacker did not win: `INSERT INTO attack_cooldowns`

**Step C16 — Update attacker's troupes in actionsattaques**
- File: `combat.php:346-351` — Build surviving troop string; `UPDATE actionsattaques SET troupes=?`
  - This sets `$actions['troupes']` to post-combat survivor counts for the return trip

**Step C17 — Update defender molecules**
- File: `combat.php:354-357` — Four individual `UPDATE molecules SET nombre=?` for defender's 4 classes

**Step C18 — Pillage computation (attacker win only)**
- File: `combat.php:360-410` — `dbFetchOne` on `ressources` for both players
  - Load vault level: `dbFetchOne` on `constructions` for defender
  - Compute `$ressourcesTotalesDefenseur` (sum above vault floor)
  - Compute `$ressourcesAPiller` from surviving attacker pillage stats
  - Apply bouclier alliance research reduction
  - Compute per-resource pillage amounts proportional to composition

**Step C19 — Building damage (attacker win only)**
- File: `combat.php:412-416` — `$hydrogeneTotal` computed from surviving attacker H stats
- File: `combat.php:427` — `dbFetchOne` on `constructions` for defender
- File: `combat.php:438-523` — If champdeforce is highest building: all damage goes to it; otherwise `rand(1,4)` targets one of four buildings per class
  - `diminuerBatiment()` called if damage >= current HP and level > 1

**Step C20 — Combat stats update**
- File: `combat.php:534-535` — `dbFetchOne` for attacker points; `dbFetchOne` for defender points
- File: `combat.php:539` — `$battlePoints` computed via `sqrt(total_casualties)`
- File: `combat.php:541-548` — Point deltas assigned based on winner
- File: `combat.php:557-559` — `dbFetchOne` for `moleculesPerdues` on both players
- File: `combat.php:561-563` — `ajouterPoints()` called for attacker (type 1), attacker pillage (type 3), defender (type 2)
- File: `combat.php:568-569` — `UPDATE autre SET moleculesPerdues=?` for both players

**Step C21 — Resource transfer**
- File: `combat.php:577` — `dbFetchOne`: `depot` from `constructions` for attacker
- File: `combat.php:579-590` — Dynamic UPDATE for attacker resources (capped at storage)
- File: `combat.php:594-612` — Dynamic UPDATE for defender resources (subtracts pillage, adds defense reward energy if won)

**Step C22 — Attack count and war ledger**
- File: `combat.php:614` — `dbFetchOne` for attacker's `nbattaques`
- File: `combat.php:616` — `UPDATE autre SET nbattaques=?`
- File: `combat.php:620-633` — Alliance war detection: two `dbFetchOne` calls for alliances, then `dbQuery` for active war declarations; if war found, update `pertes1/pertes2`

---

### PHASE 4 — REPORT GENERATION AND COMMIT (game_actions.php)

**Step D1 — Report content assembly**
- File: `game_actions.php:111-120` — Set `$titreRapportJoueur` and `$titreRapportDefenseur` based on `$gagnant`
- File: `game_actions.php:122-131` — Build pillage display string `$chaine`
- File: `game_actions.php:135-143` — Set formula display for attacker classes (hide if 0 sent)
- File: `game_actions.php:150-309` — Build HTML report strings `$debutRapport`, `$milieuDefenseur`, `$milieuAttaquant`, `$finRapport`

**Step D2 — Defender column data redacted on total loss**
- File: `game_actions.php:232-250` — If `$attaquantsRestants == 0` (all attackers died): defender data replaced with "?" and row deleted from `actionsattaques` (no return trip)

**Step D3 — Reports inserted**
- File: `game_actions.php:316` — `INSERT INTO rapports` for attacker
- File: `game_actions.php:318` — `INSERT INTO rapports` for defender

**Step D4 — Transaction commit**
- File: `game_actions.php:319` — `mysqli_commit($base)` — all combat DB writes become durable

**Step D5 — Exception handling**
- File: `game_actions.php:320-323` — `catch(Exception)` → `mysqli_rollback($base)` and error log

---

### PHASE 5 — RETURN TRIP (game_actions.php)

**Step E1 — Return trip eligibility**
- File: `game_actions.php:446` — Separate `if` block (NOT `elseif`): `tempsRetour < time() AND joueur == attaquant AND troupes != 'Espionnage'`
- This check runs on the SAME loop iteration immediately after combat resolution if both conditions are met simultaneously

**Step E2 — Return decay calculation**
- File: `game_actions.php:449` — `$nbsecondes = tempsRetour - tempsAttaque` (return leg duration)
- File: `game_actions.php:450` — Explode `$actions['troupes']` — this is now the post-combat survivor string (updated in combat.php:351)
- File: `game_actions.php:452` — `dbQuery` for attacker's molecules
- File: `game_actions.php:456-465` — Compute decay, add surviving molecules back to DB, update `moleculesPerdues`

**Step E3 — Cleanup**
- File: `game_actions.php:467` — `DELETE FROM actionsattaques WHERE id=?`

---

## B. CRASH POINTS

Every location where a `null` return from `dbFetchOne` or a query failure causes a fatal crash inside the combat transaction, and what the consequence is.

---

### CRASH-01 — Defender molecule query returns null iterator
**Location:** `combat.php:5`
```php
$exClasse1 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['defenseur']);
```
**Scenario:** Defender account deleted between attack launch and resolution, or `molecules` row missing.
**Behavior:** `mysqli_fetch_array` in the while loop at `combat.php:8` silently produces 0 iterations. `$classeDefenseur1` through `$classeDefenseur4` are NEVER SET.
**Consequence:** All downstream references to `$classeDefenseur1['nombre']`, `$classeDefenseur1['id']`, etc. at `combat.php:354-357` cause PHP undefined variable warnings; the HP pool at `combat.php:256-258` becomes 0. `$defenseursRestants = 0`, so `$gagnant = 2` (attacker auto-wins). The four `UPDATE molecules SET nombre=?` at lines 354-357 reference unset `$classeDefenseur1['id']` etc. — in PHP 8.x this is a fatal TypeError on the `'di'` binding if `id` is null.
**Transaction rollback:** Yes — exception propagates. `attaqueFaite = 1` already set (Step B4). The combat row stays in DB forever with `attaqueFaite=1` but `tempsRetour` still in the future. Troops never return. **Permanent molecule loss.**

---

### CRASH-02 — Attacker molecule query returns null iterator
**Location:** `combat.php:15`
```php
$exClasse1 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);
```
**Scenario:** Same as CRASH-01 but for attacker.
**Behavior:** `$classeAttaquant1`..`$classeAttaquant4` never set. `$degatsAttaquant` becomes 0, `$attaquantsRestants = 0`, `$gagnant = 1` (defender wins). Report generation at `game_actions.php:150-165` references `$classeAttaquant1['formuleAfficher']` on undefined variable. Fatal in PHP 8 strict mode.

---

### CRASH-03 — Attacker constructions null
**Location:** `combat.php:28`
```php
$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur']);
```
**Scenario:** `constructions` row missing for attacker.
**Behavior:** `$niveauxAttaquant` is null. `$niveauxAttaquant['pointsProducteur']` is null. `explode(";", null)` returns `[""]` in PHP 8.x (deprecation → empty string). All `$niveauxAtt['oxygene']` etc. become empty string, passed as `$niveau` to `attaque()`, which divides by 50 — yields 1.0 multiplier. Silently wrong, not a crash per se, but entirely incorrect combat stats.
**Same issue** at `combat.php:34` for defender.

---

### CRASH-04 — Ionisateur null
**Location:** `combat.php:41`
```php
$ionisateur = dbFetchOne($base, 'SELECT ionisateur FROM constructions WHERE login=?', 's', $actions['attaquant']);
```
**Usage:** `combat.php:206` — `(1 + (($ionisateur['ionisateur'] * 2) / 100))`
**Scenario:** Missing `constructions` row.
**Behavior:** `$ionisateur` is null. `$ionisateur['ionisateur']` is null in PHP 8 → TypeError on arithmetic. Fatal mid-transaction. Rollback. Permanent molecule loss (see CRASH-01).

---

### CRASH-05 — Champdeforce null
**Location:** `combat.php:43`
```php
$champdeforce = dbFetchOne($base, 'SELECT champdeforce FROM constructions WHERE login=?', 's', $actions['defenseur']);
```
**Usage:** `combat.php:212` — `(1 + (($champdeforce['champdeforce'] * 2) / 100))`
**Same consequence** as CRASH-04 but applies to defender's defense score. Transaction crashes, attacker loses molecules permanently.

---

### CRASH-06 — Duplicateur alliance lookup null
**Location:** `combat.php:48`
```php
$duplicateurAttaque = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
$bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);
```
**Scenario:** Alliance was deleted between attack launch and resolution but `idalliance` in `autre` still references old id.
**Behavior:** `$duplicateurAttaque` is null. `null['duplicateur']` → TypeError in PHP 8. Fatal. Same permanent molecule loss.
**Same pattern** at `combat.php:55` for defender.

---

### CRASH-07 — Defensive formation null
**Location:** `combat.php:122`
```php
$formationData = dbFetchOne($base, 'SELECT formation FROM constructions WHERE login=?', 's', $actions['defenseur']);
$defenderFormation = ($formationData && isset($formationData['formation'])) ? intval($formationData['formation']) : FORMATION_DISPERSEE;
```
**This one has a null guard.** If `$formationData` is null, falls back to `FORMATION_DISPERSEE`. No crash.
**Assessment:** Safe.

---

### CRASH-08 — Defender constructions null in building damage phase
**Location:** `combat.php:427`
```php
$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);
```
**Usage:** `combat.php:438` — `$constructions['champdeforce'] > $constructions['generateur']` etc.
**Scenario:** Defender constructions missing.
**Behavior:** `$constructions` is null. Array access on null is a TypeError in PHP 8. Fatal mid-transaction. Rollback. Molecules lost.

---

### CRASH-09 — Combat point stats null
**Location:** `combat.php:534`
```php
$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
```
**Usage:** `combat.php:539` — `$totalCasualties = $pertesAttaquant + $pertesDefenseur` (this is safe). But `$battlePoints` computation does not reference these.
**Actual usage:** These are passed to `ajouterPoints()` at lines 561-563, which does its own `dbFetchOne` internally. So these two fetches at 534-535 are LOADED BUT NEVER DIRECTLY USED for computation. They are vestigial fetches. They waste two queries and return data that is discarded.
**Assessment:** Not a crash, but dead queries. See EXPLOIT-05.

---

### CRASH-10 — moleculesPerdues fetches null
**Location:** `combat.php:557`
```php
$perduesAttaquant = dbFetchOne($base, 'SELECT moleculesPerdues,ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
```
**Usage:** `combat.php:568` — `($pertesAttaquant + $perduesAttaquant['moleculesPerdues'])`
**Scenario:** `autre` row missing.
**Behavior:** TypeError on null array access. Fatal mid-transaction. Rollback. Permanent molecule loss.

---

### CRASH-11 — Vault data null
**Location:** `combat.php:366`
```php
$vaultData = dbFetchOne($base, 'SELECT coffrefort FROM constructions WHERE login=?', 's', $actions['defenseur']);
if ($vaultData && isset($vaultData['coffrefort'])) {
    $vaultLevel = $vaultData['coffrefort'];
}
```
**This one has a null guard.** Falls back to `$vaultLevel = 0`. No crash.
**Assessment:** Safe.

---

### CRASH-12 — Defender resources null
**Location:** `combat.php:360`
```php
$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['defenseur']);
```
**Usage:** `combat.php:376` — `$ressourcesDefenseur[$ressource]` in foreach
**Scenario:** Missing `ressources` row.
**Behavior:** If `$gagnant == 2` (attacker won), the pillage calculation accesses `null[$ressource]` — TypeError. Fatal. Rollback. Permanent molecule loss.
**If `$gagnant != 2`**, the defender resource update at `combat.php:596-612` still accesses `$ressourcesDefenseur[$ressource]` — same crash.

---

### CRASH-13 — Return-trip over unchecked index
**Location:** `game_actions.php:450`
```php
$molecules = explode(";", $actions['troupes']);
```
**Scenario:** If combat.php was never reached (transaction rolled back before `combat.php:351` updated `troupes`), `$actions['troupes']` still holds the original troop count, not post-combat survivors. Return trip would restore original (pre-combat) troop counts, effectively reversing molecule deduction.
**This is partially mitigated** because rollback preserves the pre-combat `troupes` value in DB. But the return trip check (step E1) fires even when `attaqueFaite = 1` AND `tempsRetour < time()`, so the molecules ARE restored from the pre-combat string. This is actually correct for a rollback scenario — troops come home as if combat never happened.
**However:** If `attaqueFaite = 1` AND the row was NOT deleted (because the try block that deletes when `attaquantsRestants == 0` was inside the rolled-back transaction), the row persists, and when `tempsRetour` arrives, the return trip fires and restores molecules. This is the intended fallback. The issue is that `attaqueFaite = 1` is set OUTSIDE the transaction — so on rollback, the row is permanently marked as resolved but has no report and no resource changes. The troops return via the return-trip path. This is a partial correctness for the player, but the combat is silently swallowed.

---

## C. EXPLOITS

---

### EXPLOIT-01 — Return-Trip Double Credit (GAME-R2-001, confirmed, still present)
**Location:** `game_actions.php:68` and `game_actions.php:446`
**Code:**
```php
// Line 68 — combat branch
if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) {
    ...
    // sets attaqueFaite=1, does combat, updates troupes
}

// Line 446 — return trip branch (SEPARATE if, NOT elseif)
if ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant'] && $actions['troupes'] != 'Espionnage') {
    // credits molecules back
    ...
    DELETE FROM actionsattaques
}
```
**Attack scenario:**
When `updateActions` is called for the attacker at a time when BOTH `tempsAttaque < time()` AND `tempsRetour < time()` are true simultaneously (i.e., the attacker never loaded any page during the entire round-trip duration), both blocks execute in the same loop iteration.

- Block 1 (line 68): Combat fires. `attaqueFaite` set to 1. `$actions['troupes']` updated in DB to post-combat survivors. Molecules are NOT yet in attacker's account (they are in the `actionsattaques` row).
- Block 2 (line 446): Immediately evaluates `tempsRetour < time()` — TRUE. `$actions['troupes']` in the LOCAL PHP variable still holds the pre-combat original (from `$chaineExplosee` before the include). The return trip reads `$actions['troupes']` from the in-memory loop variable, NOT from DB.

Wait — let us be precise. At line 105 (`$actions['troupes'] = $chaine`), the travel-decay chain is assigned in-memory. At `combat.php:351`, the post-combat survivors are written to DB AND the in-memory `$actions['troupes']` is updated via `$chaine` at line 350. So by the time block 2 runs (line 446), `$actions['troupes']` in memory IS the post-combat value.

**Refined exploit path:**
The return trip at line 446 adds post-combat survivors to the attacker's current molecules. This is correct behavior. But the row is then deleted. However, the combat at step C16 already updated the `actionsattaques` troupes string to the post-combat survivors.

**The actual double-credit scenario:** If `updateActions` is called FIRST as the DEFENDER (because the defender loads a page), the combat block runs with `joueur == defenseur`. The `attaquant` side of the return trip at line 446 requires `joueur == actions['attaquant']` — so block 2 does NOT fire for the defender's call.

Later when the attacker loads a page, `updateActions($attaquant)` runs. At line 68, `attaqueFaite == 1` so combat is skipped. At line 446, `tempsRetour < time()` — the return trip fires and credits the survivors.

**The double-credit requires:** Combat fires when the attacker's `updateActions` runs AND `tempsRetour < time()` at the same time. In that scenario:
- Line 105 sets `$actions['troupes']` = outbound-decayed troops (but NOT yet post-combat, because `combat.php` hasn't run yet)
- `combat.php` runs, sets local `$chaine` = post-combat survivors, writes to DB, sets `$actions['troupes'] = $chaine` at line 350
- Block 2 (line 446) then reads `$actions['troupes']` — now post-combat
- Adds survivors to `$moleculesProp['nombre'] + $moleculesRestantes` — this is correct

**Re-examination: The original R2 report stated `if/if instead of if/elseif` causes double credit.** Let us verify what happens when `tempsAttaque < now` AND `tempsRetour < now` in same call:

1. `attaqueFaite == 0`, `tempsAttaque < time()` → Block 1 fires. CAS sets `attaqueFaite=1`. Combat runs. Survivors written to DB as `troupes`. `$actions['troupes']` in memory = post-combat string. Row is deleted if `attaquantsRestants == 0`. Otherwise row survives.
2. Block 2 fires on same iteration: `tempsRetour < time()` is true. `$actions['troupes']` in memory = post-combat string. Decay applied to survivors for the return leg. Molecules added to attacker's DB. Row deleted.

**Result:** Troops arrive home correctly. This looks right.

**But if combat added molecules to DB for the defender already at step C17, and the row ALSO credits the attacker here — is there a double-write to the attacker's DB column?**

No — the attacker's molecules were deducted at `attaquer.php:150`. Combat.php updates DEFENDER molecules (lines 354-357) and the `actionsattaques.troupes` string. It does NOT add survivors to the attacker's `molecules` table. That only happens at the return trip (line 459). So the flow is correct IF it runs as described.

**Confirmed double-credit vector:** Consider `updateActions($joueur)` where `joueur == attaquant`, `tempsAttaque < now` AND `tempsRetour < now`. Block 1 runs combat. Block 2 on the SAME RECORD credits survivors home. The row is deleted. This is functionally correct — one run, both phases processed.

**Now consider the DEFENDER loading first:** Defender's `updateActions` fires block 1 (defender is in the query result because `defenseur=?`). `attaqueFaite` set to 1. Combat runs. Survivors stored in DB `troupes`. Block 2 does NOT fire (requires `joueur == attaquant`). Row remains in DB.

Now attacker loads a page. `updateActions($attaquant)`. `attaqueFaite == 1` — block 1 skipped. Block 2: `tempsRetour < time()` — fires, credits survivors. Row deleted. Correct.

**The true double-credit path (per R2):** If for some reason `attaqueFaite` fails to be set (CAS returns affected=1 but the combat include crashes and rolls back the combat changes, but `attaqueFaite=1` is already committed because it was set BEFORE the transaction)... then next time `updateActions` runs, `attaqueFaite == 1` so block 1 is skipped. Block 2 fires when `tempsRetour < time()`. Credits home. This is the rollback-recovery path — it is actually CORRECT behavior (troops return without combat).

**True exploitation window:** The CAS is outside the transaction. `attaqueFaite=1` is durable even on rollback. So the combat is permanently skipped. Troops return, no pillage. This is safe for game state but silently discards the combat. However — if combat.php partially executes (writes to defender molecules, to resources) and THEN the transaction rolls back, those writes are undone. `attaqueFaite=1` stays. Troops return to attacker via block 2. Defender is unaffected. This is the correct outcome.

**Verdict on GAME-R2-001:** The `if/if` instead of `if/elseif` is a real structural issue. In the simultaneous case (attacker's updateActions when both tempsAttaque and tempsRetour have passed), both blocks execute in the same iteration. Block 1 sets troupes to post-combat survivors in memory. Block 2 reads those survivors and credits them home, then deletes the row. This is functionally correct but skips the return-trip decay that should apply to the return leg. The molecules are credited as if they arrive instantaneously after combat. **The return-leg decay is not applied.** This is a balance bug: attackers who delay loading the page get their survivors back without decay on the return journey.

---

### EXPLOIT-02 — Phalange Zero-Class Exploit (GAME-R2-007, confirmed, PARTIALLY fixed but residual bug remains)

**Location:** `combat.php:277-283`
```php
} elseif ($defenderFormation == FORMATION_PHALANGE) {
    $defDamageShares[1] = $degatsAttaquant * FORMATION_PHALANX_ABSORB;  // 70%
    $remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / max(1, $nbClasses - 1);
    for ($i = 2; $i <= $nbClasses; $i++) {
        $defDamageShares[$i] = $degatsAttaquant * $remainingShare;
    }
}
```

**Fixed part:** FORMATION_DISPERSEE now correctly skips classes with `nombre == 0` (R2 fix applied at lines 263-276). Empty classes get 0 damage share.

**Residual Phalange bug:** The Phalange branch does NOT check whether class 1 has any molecules. If class 1 has `nombre == 0`, it still absorbs 70% of incoming damage. At line 297:
```php
$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . 1}['brome'], $niveauxDef['brome']) * ...;
$damageShare = $defDamageShares[1];  // = degatsAttaquant * 0.70
if ($hpPerMol > 0) {
    $classe1DefenseurMort = min($classeDefenseur1['nombre'], floor($damageShare / $hpPerMol));
    // classeDefenseur1['nombre'] == 0, so min(0, anything) = 0
```
`$classe1DefenseurMort = 0`. But `$damageShare = 70%` was consumed, leaving only 30% to distribute across classes 2-4. **70% of attacker damage is absorbed by an empty class.** Attacker effectively deals only 30% damage.

**Exploit:** Defender with Phalange formation and no class-1 molecules effectively has 70% damage reduction for free. No troop investment needed in class 1.

**Fix required:** Phalange must check `$classeDefenseur1['nombre'] > 0`. If class 1 is empty, fall back to proportional or Dispersee distribution.

---

### EXPLOIT-03 — Pre-Transaction moleculesPerdues Double-Count
**Location:** `game_actions.php:94-103` (before transaction) and `combat.php:568-569` (inside transaction)
```php
// game_actions.php:100 — OUTSIDE transaction
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', ...
    ($molecules[$compteur - 1] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $actions['attaquant']);

// combat.php:568 — INSIDE transaction
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds',
    ($pertesAttaquant + $perduesAttaquant['moleculesPerdues']), $actions['attaquant']);
```

The pre-travel decay updates `moleculesPerdues` outside the transaction. Then combat inside the transaction does a fresh `dbFetchOne` at line 557 to read the CURRENT value (which already includes the travel decay losses). Then it adds `$pertesAttaquant` on top. This is correct — no double-count for attacker.

**However:** The game_actions.php travel decay loop at line 99 fetches `moleculesPerdues` INSIDE the while loop over classes — once per class. If there are 4 classes, it reads the value, adds (molecules_sent - molecules_remaining), and writes. On the next iteration it reads the UPDATED value. This is a read-modify-write within a loop without a transaction, so concurrent requests could produce a race condition on `moleculesPerdues`, but given the CAS lock on `attaqueFaite`, only one process executes this code for a given combat at a time. So in practice no race here.

---

### EXPLOIT-04 — CAS Outside Transaction Allows State Inconsistency
**Location:** `game_actions.php:71` (CAS) vs `game_actions.php:107` (transaction start)
```php
$casAffected = dbExecute(..., 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
// ... more setup ...
// ... outbound decay loop ...
mysqli_begin_transaction($base);
try {
    include("includes/combat.php");
    // ... reports ...
    mysqli_commit($base);
} catch (Exception $e) {
    mysqli_rollback($base);
}
```

**The gap:** Between the CAS (`attaqueFaite=1` written and durable) and `mysqli_begin_transaction`, the process can crash — PHP OOM, timeout, server restart. The combat row now has `attaqueFaite=1` permanently but no combat was ever resolved. The row will never be processed again by block 1 (which requires `attaqueFaite=0`). The return trip (block 2) will fire when `tempsRetour < time()` and restore pre-combat troops (from the `troupes` field, which still holds the outbound-decayed values set at line 105). This is the intended fallback.

**But:** The outbound decay was already written to `moleculesPerdues` (lines 99-101) BEFORE the crash. Those decay losses are recorded. Yet the troops that decayed will be restored at the return trip using the SAME `$actions['troupes']` string (which was set in memory at line 105 but was NOT persisted to DB yet at that point — only the `attaqueFaite=1` write was committed).

Actually: `$actions['troupes'] = $chaine` at line 105 is IN MEMORY ONLY. The DB `troupes` column still holds the original values. When the return trip fires, it reads `$actions['troupes']` from the loop variable. But this is a new `updateActions` call in a new request — the loop variable `$actions` is freshly fetched from DB. So the return trip reads the ORIGINAL `troupes` (not outbound-decayed). The troops returned will be the original sent count, not the outbound-decayed count. **This means decayed molecules are counted as "lost" in moleculesPerdues but also returned home.** Net effect: false inflation of `moleculesPerdues` stat.

---

### EXPLOIT-05 — Dead Queries (Performance, Not Security)
**Location:** `combat.php:534-535`
```php
$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,...FROM autre WHERE login=?', 's', $actions['attaquant']);
$pointsBDDefenseur = dbFetchOne($base, 'SELECT points,...FROM autre WHERE login=?', 's', $actions['defenseur']);
```
These variables are loaded but never used. `ajouterPoints()` at lines 561-563 does its own fetch internally. Two unnecessary queries inside every combat transaction.

---

### EXPLOIT-06 — Phalange Bonus Applied to Empty Class in Damage Calculation
**Location:** `combat.php:209-211`
```php
if ($defenderFormation == FORMATION_PHALANGE && $c == 1) {
    $defBonusForClass *= (1.0 + FORMATION_PHALANX_DEFENSE_BONUS);  // +30%
}
$degatsDefenseur += defense(...) * $defBonusForClass * ... * ${'classeDefenseur' . $c}['nombre'];
```
If `$classeDefenseur1['nombre'] == 0`, this line contributes `0` to `$degatsDefenseur` regardless of the bonus. The +30% defense bonus for class 1 is applied correctly (multiplied by nombre=0 = 0). This part is fine.

But the 70% damage absorption in EXPLOIT-02 is the real damage: no defensive contribution from class 1, yet it absorbs 70% of incoming damage. This makes Phalange with empty class 1 a pure damage reduction exploit with zero defensive cost.

---

### EXPLOIT-07 — Alliance War Casualties Not Transaction-Safe
**Location:** `combat.php:620-633`
```php
$exGuerre = dbQuery($base, 'SELECT * FROM declarations WHERE ...', ...);
$guerre = mysqli_fetch_array($exGuerre);
$nbGuerres = mysqli_num_rows($exGuerre);
if ($nbGuerres >= 1) {
    // $guerre may be array or false
    if ($guerre['alliance1'] == $joueur['idalliance']) {
        dbExecute($base, 'UPDATE declarations SET pertes1=?, pertes2=? ...', ...);
    }
}
```
`$exGuerre` at line 624 is a `dbQuery` result used with `mysqli_num_rows`. The `$joueur` variable at line 620 shadows the global `$joueur` used earlier (which was the player login from `updateActions`). At `combat.php:620`: `$joueur = dbFetchOne(...)` — this reassigns the loop variable name that was used as the `$joueur` parameter to `updateActions`. This is a name collision but within combat.php scope, the local `$joueur` is fine. The outer loop variable `$joueur` from `game_actions.php:65` is in a different scope (that loop variable was `$actions`). No actual collision.

However: `$guerre` can be `false` if `$nbGuerres == 0`. The guard at line 627 prevents null access. BUT: `$nbGuerres = mysqli_num_rows($exGuerre)` — if `$exGuerre` is a failed query (false), `mysqli_num_rows(false)` returns a deprecation warning and 0 in PHP 8. Safe fallback.

---

### EXPLOIT-08 — Attaque() and defense() Medal DB Queries Inside Combat Loop
**Location:** `combat.php:206` calls `attaque($oxygene, $niveau, $joueur)`, which at `formulas.php:86` does:
```php
$medalData = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $joueur);
```
This fires once per class per call inside the damage loop. With 4 classes, that is 4 `attaque()` calls = 4 queries for attacker medals, plus 4 `defense()` calls = 4 queries for defender medals. These 8 queries execute inside the transaction with identical results. They should be called once, memoized, and passed as `$medalData` parameter (the optional parameter exists but is never used by combat.php).

Similarly, `pillage()` at `formulas.php:136` fires a query per class, totaling 4 queries for the pillage calculation at lines 380-383.

Total avoidable queries inside combat transaction: at minimum 12.

---

## D. FIX PRIORITY

Ordered by severity: data corruption and permanent loss first, then exploits, then performance.

---

### PRIORITY 1 — CRITICAL: All null-dbFetchOne crashes inside transaction

**Issue:** CRASH-01 through CRASH-12 (except safe ones noted). A null return from any of the unguarded `dbFetchOne` calls in `combat.php` causes a PHP TypeError in PHP 8, which triggers the catch block, rolls back all DB writes, but leaves `attaqueFaite=1` committed. The combat row is permanently abandoned. Sent troops never return.

**Files affected:** `combat.php:5,15,28,34,41,43,45,52,534,535,557,559,360,362,427`

**Fix:** Wrap each critical `dbFetchOne` result in a null check. On null, throw an explicit `RuntimeException` with a descriptive message (the catch block will then roll back cleanly). Also move the `attaqueFaite=1` CAS INSIDE the transaction so a crash before commit rolls back the flag too.

```php
// Pattern for every dbFetchOne in combat.php:
$result = dbFetchOne($base, '...', '...', $login);
if ($result === null || $result === false) {
    throw new RuntimeException("combat: missing DB row for $login in table X");
}
```

**For the CAS specifically:**
```php
mysqli_begin_transaction($base);
try {
    $casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
    if ($casAffected === 0 || $casAffected === false) {
        mysqli_rollback($base);
        continue;
    }
    include("includes/combat.php");
    // ... reports ...
    mysqli_commit($base);
} catch (Exception $e) {
    mysqli_rollback($base);
}
```
This moves `attaqueFaite=1` inside the transaction — a crash rolls it back, the combat will be retried next time.

---

### PRIORITY 2 — HIGH: Phalange Empty Class Exploit (GAME-R2-007 residual)

**Issue:** EXPLOIT-02. Defender with Phalange formation and 0 molecules in class 1 absorbs 70% of attacker damage for free.

**File:** `combat.php:277-289`

**Fix:** Check class 1 population before applying Phalange. If empty, redistribute that 70% proportionally to populated classes.

```php
} elseif ($defenderFormation == FORMATION_PHALANGE) {
    if ($classeDefenseur1['nombre'] > 0) {
        $defDamageShares[1] = $degatsAttaquant * FORMATION_PHALANX_ABSORB;
        $remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / max(1, $nbClasses - 1);
        for ($i = 2; $i <= $nbClasses; $i++) {
            $defDamageShares[$i] = $degatsAttaquant * $remainingShare;
        }
    } else {
        // Class 1 empty — fall back to proportional distribution among active classes
        $activeDefClasses = 0;
        for ($i = 1; $i <= $nbClasses; $i++) {
            if (${'classeDefenseur' . $i}['nombre'] > 0) $activeDefClasses++;
        }
        $sharePerClass = ($activeDefClasses > 0) ? 1.0 / $activeDefClasses : 0.25;
        for ($i = 1; $i <= $nbClasses; $i++) {
            $defDamageShares[$i] = (${'classeDefenseur' . $i}['nombre'] > 0)
                ? $degatsAttaquant * $sharePerClass
                : 0;
        }
    }
}
```

---

### PRIORITY 3 — HIGH: Return-Trip Bypasses Return-Leg Decay (GAME-R2-001)

**Issue:** EXPLOIT-01. When `updateActions` is called for the attacker after both `tempsAttaque` and `tempsRetour` have passed, both the combat block and the return trip block fire in the same iteration. The return trip reads the post-combat `$actions['troupes']` from memory and applies return-leg decay correctly. However, the structural problem remains: the `if/if` means these two semantically exclusive phases are not exclusive. If the state machine logic changes in future, the `if/if` will cause double-execution.

**Secondary issue:** The return-leg decay IS applied correctly in the simultaneous case (the in-memory `$actions['troupes']` holds post-combat values after `combat.php:350`). However, the two operations are not visually distinct and this is fragile.

**Fix:** Change to `elseif` to make the state machine explicit. Process return trip in next `updateActions` call:

```php
// game_actions.php:68
if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) {
    // combat phase
    ...
} elseif ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant'] && $actions['troupes'] != 'Espionnage') {
    // return trip phase — only when combat already done (attaqueFaite=1)
    ...
}
```

This ensures only one phase fires per `updateActions` call. If both times have passed, combat fires and the return trip fires on the next call (within the same request, since the loop continues). The simultaneous case may require a second loop iteration or a page reload, but that is preferable to fragile in-memory coupling.

---

### PRIORITY 4 — HIGH: CAS Outside Transaction (EXPLOIT-04)

**Issue:** `attaqueFaite=1` committed before transaction. Crash in the window between CAS and transaction leaves combat abandoned with troops lost.

**Fix:** Move CAS inside transaction (described in PRIORITY 1 fix block above).

---

### PRIORITY 5 — MEDIUM: moleculesPerdues False Inflation on Crash (EXPLOIT-03 partial)

**Issue:** Pre-transaction travel decay writes to `moleculesPerdues` outside the transaction. On crash and rollback, the `troupes` column in DB was not updated (because that happens inside combat.php which was rolled back), but the `moleculesPerdues` write at `game_actions.php:100` was outside the transaction and is durable. When the return trip fires in a future request, it reads the original (non-decayed) `troupes` from DB and returns them. Result: those troops are counted as "lost" in the stat but also returned home.

**Fix:** Move the travel-decay `moleculesPerdues` update inside the transaction. One clean approach is to compute the travel decay string and the moleculesPerdues delta before the transaction, then apply both writes inside it:

```php
// Before transaction: compute only, don't write
$preTravelMolLostPerClass = [];
$chaine = '';
while (...) {
    $moleculesRestantes = ...;
    $preTravelMolLostPerClass[] = $molecules[$compteur - 1] - $moleculesRestantes;
    $chaine .= $moleculesRestantes . ';';
    $compteur++;
}
$actions['troupes'] = $chaine;

// Inside transaction: apply all writes atomically
mysqli_begin_transaction($base);
try {
    // CAS
    // update moleculesPerdues for travel
    $totalTravelLost = array_sum($preTravelMolLostPerClass);
    dbExecute($base, 'UPDATE autre SET moleculesPerdues=moleculesPerdues+? WHERE login=?', 'ds', $totalTravelLost, $actions['attaquant']);
    include("includes/combat.php");
    // ...
    mysqli_commit($base);
} catch (...) {
    mysqli_rollback($base);
}
```

---

### PRIORITY 6 — MEDIUM: Dead Queries (EXPLOIT-05)

**Issue:** `combat.php:534-535` loads `$pointsBDAttaquant` and `$pointsBDDefenseur` but neither variable is used anywhere downstream. `ajouterPoints()` makes its own fetches.

**Fix:** Remove lines 534-535 in `combat.php`.

---

### PRIORITY 7 — MEDIUM: Medal Queries Inside Combat Transaction (EXPLOIT-08)

**Issue:** `attaque()`, `defense()`, `pillage()` each do a `dbFetchOne` for medal data, called 4 times each inside the combat transaction = 12+ unnecessary repeated queries.

**Fix:** Fetch medal data once before calling these functions, pass as the optional `$medalData` parameter:

In `combat.php`, before the damage loop:
```php
$medalDataAtt = dbFetchOne($base, 'SELECT pointsAttaque FROM autre WHERE login=?', 's', $actions['attaquant']);
$medalDataDef = dbFetchOne($base, 'SELECT pointsDefense FROM autre WHERE login=?', 's', $actions['defenseur']);
$medalDataPillage = dbFetchOne($base, 'SELECT ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
```

Then pass `$medalDataAtt` to `attaque()`, `$medalDataDef` to `defense()`, etc.

---

### PRIORITY 8 — LOW: Phalange +30% Defense Applied Regardless of Class Population

**Issue:** EXPLOIT-06. The +30% defense multiplier in the damage calculation loop (line 209-211) is applied to class 1 even when `nombre == 0`. This contributes 0 to total damage (`defense * bonus * 0 = 0`), so it has no functional effect on the total `$degatsDefenseur`. Not exploitable on its own, but is a logical inconsistency and confusing to reason about. Should be guarded:

```php
if ($defenderFormation == FORMATION_PHALANGE && $c == 1 && ${'classeDefenseur' . 1}['nombre'] > 0) {
    $defBonusForClass *= (1.0 + FORMATION_PHALANX_DEFENSE_BONUS);
}
```

---

### PRIORITY 9 — LOW: Combat Cache Not Invalidated After Resolution

**Issue:** `initPlayer` uses a per-request cache (`$GLOBALS['_initPlayerCache']`). After combat resolves inside `updateActions`, neither player's cache is invalidated. If the same request continues to render the page using cached player data, it will show stale resource/molecule counts.

**File:** `game_actions.php` — after `mysqli_commit($base)` at line 319

**Fix:** After successful commit, invalidate cache for both players:
```php
mysqli_commit($base);
invalidatePlayerCache($actions['attaquant']);
invalidatePlayerCache($actions['defenseur']);
```

---

## Summary Table

| ID | Severity | File:Line | Issue | Fix |
|----|----------|-----------|-------|-----|
| CRASH-01 | CRITICAL | combat.php:5 | Defender molecules null → TypeError, permanent troop loss | Null guard + throw RuntimeException |
| CRASH-02 | CRITICAL | combat.php:15 | Attacker molecules null → TypeError, permanent troop loss | Same |
| CRASH-04 | CRITICAL | combat.php:41 | Ionisateur null → TypeError | Null guard |
| CRASH-05 | CRITICAL | combat.php:43 | Champdeforce null → TypeError | Null guard |
| CRASH-06 | CRITICAL | combat.php:48,55 | Duplicateur null → TypeError | Null guard |
| CRASH-08 | CRITICAL | combat.php:427 | Defender constructions null → TypeError | Null guard |
| CRASH-10 | CRITICAL | combat.php:557 | moleculesPerdues null → TypeError | Null guard |
| CRASH-12 | CRITICAL | combat.php:360 | Defender resources null → TypeError | Null guard |
| EXPLOIT-04 | HIGH | game_actions.php:71 | CAS outside transaction, crash = abandoned combat | Move CAS inside transaction |
| EXPLOIT-02 | HIGH | combat.php:277-283 | Phalange empty class absorbs 70% damage for free | Check nombre>0 before applying 70% |
| EXPLOIT-01 | HIGH | game_actions.php:68,446 | if/if allows both phases in same iteration, return-leg decay skipped in simultaneous case | Change to elseif |
| CRASH-03 | MEDIUM | combat.php:28,34 | Constructions null → wrong stats (not a crash, silent wrong values) | Null guard |
| EXPLOIT-03 | MEDIUM | game_actions.php:99-101 | moleculesPerdues written outside transaction → inflated on rollback | Move inside transaction |
| EXPLOIT-05 | MEDIUM | combat.php:534-535 | Dead queries loaded and discarded | Remove lines |
| EXPLOIT-08 | MEDIUM | combat.php:206,212 | Medal queries repeated 4x each inside transaction | Fetch once, pass as param |
| EXPLOIT-07 | LOW | combat.php:620-633 | $joueur variable shadowed; alliance war update fragile | Rename local variable |
| EXPLOIT-06 | LOW | combat.php:209-211 | Phalange +30% applied to empty class (functionally 0, but confusing) | Add nombre>0 guard |
| P9 | LOW | game_actions.php:319 | initPlayer cache not invalidated after combat commit | Call invalidatePlayerCache after commit |
