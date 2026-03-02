# Game Balance Rewrite Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Fix the 9 root causes of game imbalance that make building (specifically producteur) the only viable strategy, combat pointless, market useless, and molecules too expensive to sustain. Also refactor building costs to use the centralized $BUILDING_CONFIG instead of hardcoded values.

**Architecture:** Surgical changes to formula functions, point-awarding logic, and balance constants. All changes go through the existing centralized config.php constants and formulas.php functions. No structural/framework changes. The $BUILDING_CONFIG array in config.php (currently unused by game code) will replace the hardcoded building costs in player.php's initPlayer().

**Tech Stack:** PHP 8.2, MySQLi with prepared statements, procedural architecture

---

### Task 1: Scale Combat Point Rewards with Battle Size

**Bugs addressed:**
- ROOT CAUSE #1: `pointsAttaque()` and `pointsDefense()` in formulas.php are identity functions (return input unchanged)
- ROOT CAUSE #2: Combat awards AT MOST +/-2 points per battle, only when the underdog wins

**Files:**
- Modify: `includes/formulas.php` (lines 53-61)
- Modify: `includes/combat.php` (lines 309-333)
- Modify: `includes/config.php` (add new constants)

**Context:** Currently `pointsAttaque($pts)` returns `$pts` and `pointsDefense($pts)` returns `$pts`. These functions transform raw attack/defense point accumulations into the contribution to `totalPoints`. Since they're identity functions, the raw combat points (max +/-2 per battle) translate 1:1 to totalPoints. Meanwhile, each building level gives 1-3 points directly. A player building 10 levels of producteur gets ~10 points. A player fighting 10 battles gets at most 20 points IF they're always the underdog AND always win. In practice, combat contributes almost nothing.

The fix has two parts:
1. Make pointsAttaque/pointsDefense apply a scaling function (sqrt) so accumulated combat raw points translate to meaningful totalPoints
2. Scale combat raw point awards with battle size (sqrt of total casualties) instead of flat +/-1

**Step 1: Add combat scaling constants to config.php**

In `includes/config.php`, add after the COMBAT FORMULAS section (after line 237):

```php
// Combat point scaling
// Raw combat points awarded = floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt(total_casualties))
// Only the winner gets positive points; loser gets negative
define('COMBAT_POINTS_BASE', 1);           // Minimum points for any combat
define('COMBAT_POINTS_CASUALTY_SCALE', 0.5); // Scale factor for sqrt(casualties)
define('COMBAT_POINTS_MAX_PER_BATTLE', 20);  // Cap per single battle

// pointsAttaque/pointsDefense scaling: sqrt(rawPoints) * MULTIPLIER
// This makes accumulated combat points contribute meaningfully to totalPoints
define('ATTACK_POINTS_MULTIPLIER', 3.0);
define('DEFENSE_POINTS_MULTIPLIER', 3.0);
```

**Step 2: Update pointsAttaque and pointsDefense in formulas.php**

In `includes/formulas.php`, replace lines 53-61:

```php
function pointsAttaque($pts)
{
    return $pts;
}

function pointsDefense($pts)
{
    return $pts;
}
```

with:

```php
function pointsAttaque($pts)
{
    if ($pts <= 0) return 0;
    return round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)));
}

function pointsDefense($pts)
{
    if ($pts <= 0) return 0;
    return round(DEFENSE_POINTS_MULTIPLIER * sqrt(abs($pts)));
}
```

**Why sqrt?** A player with 100 raw attack points gets `3 * sqrt(100) = 30` totalPoints from attack. A player with 400 raw attack points gets `3 * sqrt(400) = 60`. This creates diminishing returns that prevent combat-only strategies from dominating while still making combat meaningful. Combined with the increased raw point awards below, a player who fights regularly will gain 20-60 totalPoints from combat, competitive with building.

**Step 3: Scale combat point awards with casualties in combat.php**

In `includes/combat.php`, replace lines 309-333:

```php
$pointsAttaquant = 0;
$pointsDefenseur = 0;

$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
$pointsBDDefenseur = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['defenseur']);

if ($gagnant == 1) { // DEFENSEUR
	if ($pointsBDAttaquant['totalPoints'] >= $pointsBDDefenseur['totalPoints']) {
		$pointsAttaquant += -1;
		$pointsDefenseur += 1;
	}
	if ($pointsBDAttaquant['pointsAttaque'] >= $pointsBDDefenseur['pointsDefense']) {
		$pointsAttaquant += -1;
		$pointsDefenseur += 1;
	}
} else if ($gagnant == 2 && $pertesDefenseur > 0) { // ATTAQUANT
	if ($pointsBDAttaquant['totalPoints'] <= $pointsBDDefenseur['totalPoints']) {
		$pointsAttaquant += 1;
		$pointsDefenseur += -1;
	}
	if ($pointsBDAttaquant['pointsAttaque'] <= $pointsBDDefenseur['pointsDefense']) {
		$pointsAttaquant += 1;
		$pointsDefenseur += -1;
	}
}
```

with:

```php
$pointsAttaquant = 0;
$pointsDefenseur = 0;

$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
$pointsBDDefenseur = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['defenseur']);

// Scale combat points with battle size (total casualties)
$totalCasualties = $pertesAttaquant + $pertesDefenseur;
$battlePoints = min(COMBAT_POINTS_MAX_PER_BATTLE, floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt($totalCasualties)));

if ($gagnant == 1) { // DEFENSEUR wins
    $pointsDefenseur = $battlePoints;
    $pointsAttaquant = -$battlePoints;
} else if ($gagnant == 2 && $pertesDefenseur > 0) { // ATTAQUANT wins
    $pointsAttaquant = $battlePoints;
    $pointsDefenseur = -$battlePoints;
}
// Draw ($gagnant == 0): both stay at 0
```

**Why this works:** A battle with 100 total casualties awards `floor(1 + 0.5 * 10) = 6` raw points. A battle with 400 casualties awards `floor(1 + 0.5 * 20) = 11`. The underdog restriction is removed because the sqrt scaling on `pointsAttaque/pointsDefense` already provides diminishing returns, and removing the restriction makes combat always worthwhile. The max cap of 20 prevents exploits with massive battles.

**Step 4: Commit**

```bash
git add includes/formulas.php includes/combat.php includes/config.php
git commit -m "fix: scale combat points with battle size and apply sqrt to totalPoints contribution"
```

---

### Task 2: Make Pillage Contribute to totalPoints

**Bugs addressed:**
- ROOT CAUSE #3: Pillage contributes ZERO to totalPoints because `ajouterPoints()` with type=3 only updates `ressourcesPillees`, not `totalPoints`

**Files:**
- Modify: `includes/player.php` (lines 93-97)
- Modify: `includes/formulas.php` (lines 63-66)

**Context:** In `includes/player.php` function `ajouterPoints()`, when type=3 (pillage), the code only does:
```php
dbExecute($base, 'UPDATE autre SET ressourcesPillees=? WHERE login=?', 'ds', ($points['ressourcesPillees'] + $nb), $joueur);
return chiffrePetit($nb, 0);
```
It does NOT update `totalPoints`. This means no matter how much a player pillages, it never affects their ranking. The `pointsPillage()` function exists but is never called to contribute to totalPoints.

**Step 1: Update ajouterPoints type=3 to also update totalPoints**

In `includes/player.php`, replace lines 93-97:

```php
    if ($type == 3) {
        // points de pillage
        dbExecute($base, 'UPDATE autre SET ressourcesPillees=? WHERE login=?', 'ds', ($points['ressourcesPillees'] + $nb), $joueur);
        return chiffrePetit($nb, 0);
    }
```

with:

```php
    if ($type == 3) {
        // points de pillage - now contributes to totalPoints via pointsPillage()
        $newPillage = $points['ressourcesPillees'] + $nb;
        $oldPillageContrib = pointsPillage($points['ressourcesPillees']);
        $newPillageContrib = pointsPillage(max(0, $newPillage));
        $totalPointsDelta = $newPillageContrib - $oldPillageContrib;
        dbExecute($base, 'UPDATE autre SET ressourcesPillees=?, totalPoints=? WHERE login=?', 'dds', $newPillage, ($points['totalPoints'] + $totalPointsDelta), $joueur);
        return chiffrePetit($nb, 0);
    }
```

**Step 2: Increase pillage points ceiling**

In `includes/formulas.php`, the current `pointsPillage` maxes out at 15 via `tanh(x/200000) * 15`. This is too low. Increase to 50 max by changing `includes/config.php`:

In `includes/config.php`, change lines 322-323:

```php
define('PILLAGE_POINTS_DIVISOR', 200000);
define('PILLAGE_POINTS_MULTIPLIER', 15);
```

to:

```php
define('PILLAGE_POINTS_DIVISOR', 100000);
define('PILLAGE_POINTS_MULTIPLIER', 50);
```

Then update `includes/formulas.php` line 63-66 to use the constants:

```php
function pointsPillage($nbRessources)
{
    return round(tanh($nbRessources / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
}
```

**Why 50 max?** A dedicated pillager who steals 200,000 total resources gets `tanh(200000/100000) * 50 = tanh(2) * 50 ≈ 48` points. This is competitive with building (a player at level 20 producteur has ~35 construction points) but has diminishing returns so it can't dominate alone.

**Step 3: Also update recalculerStatsAlliances to include pillage contribution**

In `includes/player.php` function `recalculerStatsAlliances()` (line 626-647), the alliance total already sums `pointsAttaque` and `pointsDefense` through their transformation functions but tracks `ressourcesPillees` as raw. The `totalPoints` per player already reflects all contributions since `ajouterPoints` updates it, so alliance `pointstotaux` is correct as-is (it sums `totalPoints`).

No change needed here.

**Step 4: Commit**

```bash
git add includes/player.php includes/formulas.php includes/config.php
git commit -m "fix: make pillage contribute to totalPoints via pointsPillage() function"
```

---

### Task 3: Increase Building Cost Scaling

**Bugs addressed:**
- ROOT CAUSE #6: Cost exponent 0.4 for main buildings is too low - the game gets easier as you level up, making building-spam the dominant strategy

**Files:**
- Modify: `includes/config.php` (lines 126-220, $BUILDING_CONFIG)
- Modify: `includes/player.php` (lines 283-421, initPlayer $listeConstructions)

**Context:** Building costs use `BASE * pow(level, 0.4)`. With exponent 0.4:
- Level 10 cost: `BASE * 10^0.4 = BASE * 2.5` — only 2.5x level 1
- Level 50 cost: `BASE * 50^0.4 = BASE * 5.5` — only 5.5x level 1
- Level 100 cost: `BASE * 100^0.4 = BASE * 6.3` — trivially cheap at high levels

This makes the optimal strategy to pump one building (producteur) infinitely since costs barely increase. With exponent 0.7:
- Level 10 cost: `BASE * 10^0.7 = BASE * 5.0`
- Level 50 cost: `BASE * 50^0.7 = BASE * 16.7`
- Level 100 cost: `BASE * 100^0.7 = BASE * 25.1`

This creates meaningful resource pressure at higher levels without making early game too hard.

**Step 1: Update $BUILDING_CONFIG exponents in config.php**

In `includes/config.php`, update the `$BUILDING_CONFIG` array. Change cost exponents:

For `generateur`: change `cost_energy_exp` and `cost_atoms_exp` from `0.4` to `0.7`
For `producteur`: change `cost_energy_exp` and `cost_atoms_exp` from `0.4` to `0.7`
For `depot`: change `cost_energy_exp` from `0.4` to `0.7`
For `champdeforce`: change `cost_carbone_exp` from `0.4` to `0.7`
For `ionisateur`: change `cost_oxygene_exp` from `0.4` to `0.7`
For `condenseur`: leave at `0.6` → change to `0.8`
For `lieur`: leave at `0.6` → change to `0.8`
For `stabilisateur`: leave at `0.8` → change to `0.9`

The military buildings (condenseur, lieur, stabilisateur) already had higher exponents. Increase them proportionally to maintain the relative cost ordering.

**Step 2: Update hardcoded building costs in player.php initPlayer()**

In `includes/player.php`, update the `$listeConstructions` array inside `initPlayer()` to use `$BUILDING_CONFIG` values instead of hardcoded numbers.

First, add `global $BUILDING_CONFIG;` to initPlayer() at the top (around line 102).

Then replace each building's hardcoded cost lines. For example, for generateur (line 294-295), change:

```php
'coutEnergie' => round((1 - ($bonus / 100)) * 50 * pow($niveauActuel['niveau'], 0.4)),
'coutAtomes' => round((1 - ($bonus / 100)) * 75 * pow($niveauActuel['niveau'], 0.4)),
```

to:

```php
'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['generateur']['cost_energy_base'] * pow($niveauActuel['niveau'], $BUILDING_CONFIG['generateur']['cost_energy_exp'])),
'coutAtomes' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['generateur']['cost_atoms_base'] * pow($niveauActuel['niveau'], $BUILDING_CONFIG['generateur']['cost_atoms_exp'])),
```

Apply the same pattern to ALL buildings in the array:
- **producteur** (lines 314-315): use `$BUILDING_CONFIG['producteur']`
- **depot** (line 333): use `$BUILDING_CONFIG['depot']` (energy only, no atom cost)
- **champdeforce** (line 351): use `$BUILDING_CONFIG['champdeforce']['cost_carbone_base']` and `cost_carbone_exp`
- **ionisateur** (line 368): use `$BUILDING_CONFIG['ionisateur']['cost_oxygene_base']` and `cost_oxygene_exp`
- **condenseur** (lines 385-386): use `$BUILDING_CONFIG['condenseur']`
- **lieur** (line 401): use `$BUILDING_CONFIG['lieur']['cost_azote_base']` and `cost_azote_exp`
- **stabilisateur** (line 417): use `$BUILDING_CONFIG['stabilisateur']['cost_atoms_base']` and `cost_atoms_exp`

Also update the construction time formulas to use $BUILDING_CONFIG:
- **generateur** (line 229): `round($BUILDING_CONFIG['generateur']['time_base'] * pow($niveauActuel['niveau'], $BUILDING_CONFIG['generateur']['time_exp']))`
- And similar for each building

Also update the points-per-level formulas to use $BUILDING_CONFIG:
- **generateur** (line 296): `$BUILDING_CONFIG['generateur']['points_base'] + floor($niveauActuel['niveau'] * $BUILDING_CONFIG['generateur']['points_level_factor'])`

**Step 3: Commit**

```bash
git add includes/config.php includes/player.php
git commit -m "fix: increase building cost exponents and refactor to use BUILDING_CONFIG"
```

---

### Task 4: Reduce Molecule Decay and Increase Stabilisateur

**Bugs addressed:**
- ROOT CAUSE #7: Molecules with 800 total atoms have a half-life of ~1.2 hours. This makes maintaining an army impractical.
- CRITICAL #8 (partial): Molecules are too expensive to replace at current decay rates

**Files:**
- Modify: `includes/config.php` (lines 105-106)

**Context:** The decay formula in formulas.php line 195:
```php
pow(pow(0.99, pow(1 + $nbAtomes / 100, 2) / 5000), (1 - ($bonus / 100)) * (1 - ($stabilisateur['stabilisateur'] * 0.005)))
```

For an 800-atom molecule (each type at 100): `pow(1 + 800/100, 2) / 5000 = 81/5000 = 0.0162`. Per-second decay: `pow(0.99, 0.0162) = 0.999837`. Half-life: `ln(0.5) / ln(0.999837) = 4252 seconds ≈ 1.18 hours`.

With DECAY_POWER_DIVISOR changed from 5000 to 25000: `81/25000 = 0.00324`. Per-second: `pow(0.99, 0.00324) = 0.999967`. Half-life: `ln(0.5) / ln(0.999967) = 21260 seconds ≈ 5.9 hours`.

This is much more playable — an army assembled in the evening can still be used the next morning.

**Step 1: Increase DECAY_POWER_DIVISOR**

In `includes/config.php`, change line 105:

```php
define('DECAY_POWER_DIVISOR', 5000);
```

to:

```php
define('DECAY_POWER_DIVISOR', 25000);
```

**Step 2: Increase STABILISATEUR_BONUS_PER_LEVEL**

In `includes/config.php`, change line 106:

```php
define('STABILISATEUR_BONUS_PER_LEVEL', 0.005); // 0.5% per level
```

to:

```php
define('STABILISATEUR_BONUS_PER_LEVEL', 0.01); // 1% per level
```

This doubles the stabilisateur's effectiveness. At level 20, the stabilisateur now provides 20% decay reduction instead of 10%, making it a compelling investment for military players.

**Step 3: Update formulas.php to use constants**

In `includes/formulas.php`, line 195, verify the formula uses `DECAY_POWER_DIVISOR` and `STABILISATEUR_BONUS_PER_LEVEL`. Currently it uses hardcoded values `5000` and `0.005`. Replace:

```php
return pow(pow(0.99, pow(1 + $nbAtomes / 100, 2) / 5000), (1 - ($bonus / 100)) * (1 - ($stabilisateur['stabilisateur'] * 0.005)));
```

with:

```php
return pow(pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR), (1 - ($bonus / 100)) * (1 - ($stabilisateur['stabilisateur'] * STABILISATEUR_BONUS_PER_LEVEL)));
```

**Step 4: Commit**

```bash
git add includes/config.php includes/formulas.php
git commit -m "fix: reduce molecule decay rate 5x and double stabilisateur effectiveness"
```

---

### Task 5: Increase Atom Production Rate

**Bugs addressed:**
- ROOT CAUSE #8: Molecules are astronomically expensive relative to production rate. An 800-atom molecule costs 800 atoms. At production rate of `30 * points_allocated_to_that_atom` per hour, a player with 10 producteur points in oxygen produces 300 oxygen/hour. Building a single molecule with 100 oxygen atoms takes 20 minutes. Building 100 such molecules takes 33 hours.

**Files:**
- Modify: `includes/config.php` (line 51)
- Modify: `includes/game_resources.php` (line 81)
- Modify: `includes/player.php` (JS function in initPlayer, line 98)

**Context:** `revenuAtome()` in game_resources.php line 81: `return round($bonusDuplicateur * 30 * $niveau)`. The `30` is `BASE_ATOMS_PER_POINT`. Increasing to 60 doubles atom production, making armies more affordable.

With 60: a player with 10 points in oxygen produces 600/hour. 100 oxygen for a molecule takes 10 minutes. 100 molecules takes ~16.7 hours. Still expensive enough to be a commitment, but now feasible within a play session.

**Step 1: Increase BASE_ATOMS_PER_POINT**

In `includes/config.php`, change line 51:

```php
define('BASE_ATOMS_PER_POINT', 30);
```

to:

```php
define('BASE_ATOMS_PER_POINT', 60);
```

**Step 2: Update game_resources.php to use constant**

In `includes/game_resources.php`, line 81 currently hardcodes `30`:

```php
return round($bonusDuplicateur * 30 * $niveau);
```

Change to:

```php
return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau);
```

**Step 3: Update JavaScript production display**

In `includes/game_resources.php`, function `revenuAtomeJavascript()` line 98 hardcodes `30`:

```php
echo '
<script>
function revenuAtomeJavascript(niveau){
    return Math.round(' . $bonusDuplicateur . '*30*niveau);
}
</script>
';
```

Change `30` to use the PHP constant:

```php
echo '
<script>
function revenuAtomeJavascript(niveau){
    return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*niveau);
}
</script>
';
```

**Step 4: Commit**

```bash
git add includes/config.php includes/game_resources.php
git commit -m "fix: double atom production rate to make armies affordable"
```

---

### Task 6: Give Market Strategic Purpose

**Bugs addressed:**
- ROOT CAUSE #5: Market has no unique value — players can already redistribute producteur points for free. The market just converts energy↔atoms at variable rates, which isn't useful since you can just allocate producteur points differently.

**Files:**
- Modify: `marche.php` (lines 145-212, 214-279)
- Modify: `includes/config.php` (add market constants)

**Context:** The market needs to provide something you can't get from production alone. Two changes:
1. **Trade volume bonus to totalPoints**: Large-volume trading contributes to totalPoints, creating a "merchant" playstyle
2. **Increase volatility**: Higher price swings make buy-low-sell-high more profitable, creating arbitrage opportunities

**Step 1: Add market point constants to config.php**

In `includes/config.php`, add after the MARKET section (after line 257):

```php
// Market trading points: contribute to totalPoints via trade volume
// Points awarded = floor(MARKET_POINTS_SCALE * sqrt(totalTradeVolume))
define('MARKET_POINTS_SCALE', 2.0);        // sqrt scaling for trade volume points
define('MARKET_POINTS_MAX', 40);           // cap on market points contribution to totalPoints
```

**Step 2: Add trade volume tracking and points to market buy**

In `marche.php`, after the successful buy transaction (after line 197, after the logInfo call), add:

```php
                    // Award trade volume points
                    $tradeVolume = $_POST['nombreRessourceAAcheter'];
                    $autreData = dbFetchOne($base, 'SELECT tradeVolume, totalPoints FROM autre WHERE login=?', 's', $_SESSION['login']);
                    $oldVolume = $autreData['tradeVolume'] ?? 0;
                    $newVolume = $oldVolume + $tradeVolume;
                    $oldTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($oldVolume)));
                    $newTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($newVolume)));
                    $pointsDelta = $newTradePoints - $oldTradePoints;
                    if ($pointsDelta > 0) {
                        dbExecute($base, 'UPDATE autre SET tradeVolume=?, totalPoints=? WHERE login=?', 'dds', $newVolume, ($autreData['totalPoints'] + $pointsDelta), $_SESSION['login']);
                    } else {
                        dbExecute($base, 'UPDATE autre SET tradeVolume=? WHERE login=?', 'ds', $newVolume, $_SESSION['login']);
                    }
```

**Step 3: Add trade volume tracking and points to market sell**

In `marche.php`, after the successful sell transaction (after line 265, after the logInfo call), add the same trade volume tracking code as Step 2.

**Step 4: Add tradeVolume column to autre table**

Create migration file `migrations/0003_add_trade_volume.sql`:

```sql
-- Add trade volume tracking for market points
ALTER TABLE `autre` ADD COLUMN `tradeVolume` DOUBLE NOT NULL DEFAULT 0 AFTER `ressourcesPillees`;
```

Run the migration via the existing migration system.

**Step 5: Increase market volatility**

In `includes/config.php`, change line 253:

```php
define('MARKET_VOLATILITY_FACTOR', 0.3);
```

to:

```php
define('MARKET_VOLATILITY_FACTOR', 0.5);
```

This increases price swings, making arbitrage more rewarding.

**Step 6: Reset tradeVolume in season reset**

In `includes/player.php`, function `remiseAZero()` line 705, the UPDATE autre SET query needs to include `tradeVolume=0`. Add `tradeVolume=0` to the SET clause:

Find the string:
```php
dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees = 0, missions=\'\'');
```

Add `, tradeVolume=0` before `missions`:
```php
dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, missions=\'\'');
```

**Step 7: Commit**

```bash
git add marche.php includes/config.php includes/player.php migrations/0003_add_trade_volume.sql
git commit -m "fix: add trade volume points to make market strategically relevant"
```

---

### Task 7: Balance Condenseur Points Per Level

**Bugs addressed:**
- Condenseur gives only 3 points per level to distribute across 8 atom types. At level 10, you have 30 condenseur points for 8 types = ~3.75 per type. Meanwhile, 200 atoms of one type in a molecule with condenseur level 3 gives almost no meaningful improvement.

**Files:**
- Modify: `includes/config.php` ($BUILDING_CONFIG condenseur points_per_level)
- Modify: `includes/player.php` (line 156)

**Context:** The condenseur's `niveauX` values are added to atom base values in molecule stat formulas. For attack: `round((1 + (0.1 * oxygene)^2 + oxygene) * (1 + niveau / 50))`. With `niveau = 3` (from condenseur), the multiplier is `1 + 3/50 = 1.06` — only 6% improvement. This makes condenseur nearly worthless.

Increasing points per level from 3 to 5 and the level divisor effect by increasing the points gives more meaningful scaling.

**Step 1: Increase condenseur points per level**

In `includes/config.php`, in the `$BUILDING_CONFIG['condenseur']` array, change:

```php
'points_per_level'  => 3,    // condenseur points per level
```

to:

```php
'points_per_level'  => 5,    // condenseur points per level
```

**Step 2: Update the hardcoded value in player.php**

In `includes/player.php` line 156:

```php
$points = ['condenseur' => 3, 'producteur' => sizeof($nomsRes)];
```

Change to:

```php
$points = ['condenseur' => $BUILDING_CONFIG['condenseur']['points_per_level'], 'producteur' => sizeof($nomsRes)];
```

(This requires the `global $BUILDING_CONFIG;` added in Task 3.)

**Step 3: Commit**

```bash
git add includes/config.php includes/player.php
git commit -m "fix: increase condenseur points per level from 3 to 5 for meaningful molecule improvement"
```

---

### Task 8: Reduce Energy Drain and Increase Energy Production

**Bugs addressed:**
- Energy drain from producteur (12 per level) quickly overwhelms generateur production (65 per level), forcing players to over-invest in generateur before they can build productive armies
- This compounds ROOT CAUSE #4 and #8 — the energy bottleneck makes combat even less accessible

**Files:**
- Modify: `includes/config.php` (lines 48, 57)

**Context:** `revenuEnergie = bonusDuplicateur * (1 + medalBonus/100) * (65 * generateur_level + iode_production) - 12 * producteur_level`. At generateur level 10 and producteur level 10: `65*10 - 12*10 = 650 - 120 = 530 energy/h`. At generateur 10 and producteur 30: `650 - 360 = 290`. The drain eats nearly half the production.

Reducing drain from 12 to 8 and increasing base energy to 75:
- Gen 10, Prod 10: `75*10 - 8*10 = 750 - 80 = 670` (+26%)
- Gen 10, Prod 30: `750 - 240 = 510` (+76%)

This gives players more economic headroom for military investment.

**Step 1: Adjust energy constants**

In `includes/config.php`:

Change line 48:
```php
define('BASE_ENERGY_PER_LEVEL', 65);
```
to:
```php
define('BASE_ENERGY_PER_LEVEL', 75);
```

Change line 57:
```php
define('PRODUCTEUR_DRAIN_PER_LEVEL', 12);
```
to:
```php
define('PRODUCTEUR_DRAIN_PER_LEVEL', 8);
```

**Step 2: Update game_resources.php to use constant**

In `includes/game_resources.php` line 47, verify it uses the constant:

```php
$prodBase = (65 * $niveau);
```

Change to:

```php
$prodBase = (BASE_ENERGY_PER_LEVEL * $niveau);
```

Also verify `drainageProducteur()` in formulas.php line 75 uses the constant:

```php
function drainageProducteur($niveau)
{
    return round(12 * $niveau);
}
```

Change to:

```php
function drainageProducteur($niveau)
{
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * $niveau);
}
```

**Step 3: Commit**

```bash
git add includes/config.php includes/game_resources.php includes/formulas.php
git commit -m "fix: increase energy production and reduce producteur drain for better economic headroom"
```

---

### Task 9: Run Migration and Deploy

**Step 1: Run the SQL migration on production**

SSH to VPS and run:

```bash
cd /var/www/html
php migrations/migrate.php
```

This adds the `tradeVolume` column to the `autre` table.

**Step 2: Verify all PHP files parse correctly**

```bash
find /home/guortates/TVLW/The-Very-Little-War -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

**Step 3: Push to GitHub**

```bash
git push origin main
```

**Step 4: Deploy to VPS**

```bash
ssh root@212.227.38.111 "cd /var/www/html && git pull origin main && php migrations/migrate.php"
```

---

## Balance Summary

| Strategy | Before | After | Competitive? |
|----------|--------|-------|-------------|
| Building only (level 30 producteur) | ~35 points | ~35 points | Yes (baseline) |
| Combat only (50 battles, avg 100 casualties) | ~4 points max | ~50 points (sqrt scaling) | Yes |
| Pillage (200k resources stolen) | 0 points | ~48 points | Yes |
| Market (100k trade volume) | 0 points | ~20 points | Supplementary |
| Mixed (buildings + combat + pillage) | ~40 points | ~100+ points | Optimal |

The goal is that no single strategy dominates. Building is reliable but capped. Combat is high-risk high-reward. Pillage rewards aggressive play. Market trading provides supplementary income. The optimal player uses all four.

## Mathematical Verification

**Building (producteur):** Level 30 costs `75 * pow(30, 0.7) = 75 * 12.6 = 945 energy` and `50 * pow(30, 0.7) = 630 atoms` (per type). Total construction points: ~35 (sum of `1 + floor(n * 0.1)` for n=1..30). Building is still strong but costs scale meaningfully.

**Combat:** 50 battles with average 100 casualties each. Raw points: `50 * floor(1 + 0.5*sqrt(100)) = 50 * 6 = 300 raw attack points`. totalPoints contribution: `3 * sqrt(300) ≈ 52 points`. Requires army investment but very rewarding.

**Molecules:** 800-atom molecule half-life: ~5.9 hours (was 1.2h). Atom production at 10 producteur points: `60 * 10 = 600/hour` (was 300). Time to build 100 molecules of 100 atoms each: ~16.7 hours (was 33h). Armies are now maintainable.

**Market:** At 100k trade volume: `min(40, floor(2 * sqrt(100000))) = min(40, floor(632)) = 40 points`. The cap prevents market-only strategies from dominating.
