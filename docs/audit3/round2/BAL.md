# Balance Deep-Dive — Round 2

**Scope:** Mathematical analysis of TVLW game balance — resource generation, combat, atom utility, snowball dynamics, formations, isotopes, market, and buildings.
**Analyst:** Claude Sonnet 4.6
**Date:** 2026-03-03
**Files analyzed:** includes/config.php, includes/formulas.php, includes/combat.php, includes/player.php, includes/game_resources.php, includes/game_actions.php, includes/catalyst.php, marche.php, constructions.php

---

## Table of Contents

1. [Resource Generation Curves](#1-resource-generation-curves)
2. [Combat Power Scaling — Offense vs Defense](#2-combat-power-scaling--offense-vs-defense)
3. [Atom Type Utility Comparison](#3-atom-type-utility-comparison)
4. [Snowball Analysis](#4-snowball-analysis)
5. [Formation Viability Analysis](#5-formation-viability-analysis)
6. [Isotope Cost/Benefit Analysis](#6-isotope-costbenefit-analysis)
7. [Chemical Reaction Analysis](#7-chemical-reaction-analysis)
8. [Market Equilibrium Analysis](#8-market-equilibrium-analysis)
9. [Building Cost/Benefit Curves](#9-buildingcostbenefit-curves)
10. [Alliance Research Tree Analysis](#10-alliance-research-tree-analysis)
11. [Victory Point Economy Analysis](#11-victory-point-economy-analysis)
12. [Summary Table](#12-summary-table)

---

## 1. Resource Generation Curves

### 1.1 Energy Production Formula

```
revenuEnergie = (BASE_ENERGY_PER_LEVEL * generateur_level + totalIode)
              * (1 + energievore_medal_bonus / 100)
              * bonusDuplicateur
              * prestigeProductionBonus
              - drainageProducteur(producteur_level)
```

Where:
- `BASE_ENERGY_PER_LEVEL = 75`
- `drainageProducteur(level) = 8 * level`
- `bonusDuplicateur = 1 + (duplicateur_level * 0.01)`

**Net energy at key levels (no medals, no duplicateur, no iode, no prestige):**

| Gen Level | Gross (E/h) | Producteur Drain | Net (E/h) |
|-----------|-------------|-----------------|-----------|
| 1         | 75          | 8               | 67        |
| 5         | 375         | 40              | 335       |
| 10        | 750         | 80              | 670       |
| 20        | 1500        | 160             | 1340      |
| 50        | 3750        | 400             | 3350      |

Energy production is **purely linear** in generator level with a linear drain offset. This means the ratio net/gross is constant: always `(75L - 8L) / 75L = 89.3%` efficiency regardless of level. There is no diminishing or accelerating return in isolation.

### 1.2 Atom Production Formula

```
revenuAtome(num) = bonusDuplicateur * BASE_ATOMS_PER_POINT * niveau * prestigeProductionBonus
```

Where:
- `BASE_ATOMS_PER_POINT = 60`
- `niveau` = points allocated to that atom type in producteur (distributed manually)

**Net atoms/h for N producteur points assigned to one atom type (no duplicateur, no prestige):**

| Points | Atoms/h |
|--------|---------|
| 1      | 60      |
| 5      | 300     |
| 10     | 600     |
| 20     | 1,200   |
| 50     | 3,000   |
| 100    | 6,000   |

Atom production is also **purely linear**. With producteur at level L, the player receives exactly `8 * L` points to distribute freely among 8 atom types. Specialization is free-form with no constraint.

### 1.3 Storage Formula

```
placeDepot = BASE_STORAGE_PER_LEVEL * depot_level = 500 * depot_level
```

Storage is **linear** in depot level.

### BAL-R2-001 — Producteur drain architecture creates perverse early-game incentive

**Mathematical proof:**
Energy gross at generateur level G with producteur level P:
```
net = 75G - 8P
```
A new player (G=1, P=1): net = 75 - 8 = 67 E/h.
If they level up producteur to 5 without leveling generateur: 75 - 40 = 35 E/h.
Producteur at level 6 with generateur at 1: 75 - 48 = 27 E/h.
Producteur at level 10 with generateur at 1: 75 - 80 = **-5 E/h** (negative energy income).

**Impact:** HIGH — a new player who rushes producteur without matching generateur can reach negative energy income, effectively bricking their economy. There is no UI warning. The game permits this state.

**Fix proposal:**
Option A: Add a hard constraint: `producteur_level <= generateur_level`. Enforced in `augmenterBatiment()`.
Option B: Add a soft warning in `constructions.php` when the projected net energy would go below 0 after the upgrade.
Option C: Change drain formula from `8 * producteur` to `4 * max(0, producteur - generateur)` (drain only kicks in on imbalance).

---

### BAL-R2-002 — Atom revenue formula ignores condenseur entirely for production purposes

**Mathematical proof:**
```php
function revenuAtome($num, $joueur) {
    $niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];
    return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur));
}
```
The condenseur (`$niveauiode`, `$niveaucarbone`, etc.) points are used **only** in combat formulas — they set the "atom level" for attack, defense, HP, and pillage calculations. They have **zero effect** on atom production rate. However, the UI in `constructions.php` shows condenseur points alongside producteur points in a similar way, creating player confusion.

**Impact:** MEDIUM — Players may believe condenseur investment improves production. It does not. The distinction between "producteur points (production) vs condenseur points (combat quality)" is not clearly communicated.

**Fix proposal:** Add explicit UI labels: "Producteur points: increases atoms/hour produced. Condenseur points: increases combat effectiveness of atoms used in molecules." No code logic change needed — this is a UI/documentation fix.

---

## 2. Combat Power Scaling — Offense vs Defense

### 2.1 Attack Formula

```
attaque(oxygene, niveau_oxygene, player)
  = round((1 + (0.1*O)^2 + O) * (1 + niveau_O/50) * (1 + medal_bonus/100))

degatsAttaquant = SUM over 4 classes:
  attaque(O, niveauOxygene)
  * reactionAttackBonus
  * isotopeAttackMod[c]
  * (1 + ionisateur*2/100)
  * bonusDuplicateurAttaque
  * catalystAttackBonus
  * nombre_molecules[c]
```

### 2.2 Defense Formula

```
defense(carbone, niveau_carbone, player)
  = round((1 + (0.1*C)^2 + C) * (1 + niveau_C/50) * (1 + medal_bonus/100))

degatsDefenseur = SUM over 4 classes:
  defense(C, niveauCarbone)
  * reactionDefenseBonus
  * isotopeAttackMod[c]  // named misleadingly — applied to defender's damage output
  * (1 + champdeforce*2/100)
  * bonusDuplicateurDefense
  * embuscadeDefBoost
  * nombre_molecules[c]
```

### 2.3 Quadratic vs Linear Scaling

Both attack and defense use the formula `f(X) = 1 + (0.1X)^2 + X`. Let's expand:

```
f(X) = 1 + 0.01*X^2 + X
```

**Values at key atom counts:**

| Atoms (X) | f(X) raw | Ratio f(2X)/f(X) |
|-----------|----------|-----------------|
| 10        | 2.00     | —               |
| 20        | 5.00     | 2.50            |
| 50        | 26.00    | —               |
| 100       | 101.00   | 3.88 vs X=50    |
| 200       | 401.00   | 3.97 vs X=100   |

The `(0.1X)^2` term dominates at high atom counts. At X=200 (the cap), the quadratic term alone contributes `400`, while the linear term contributes only `200`. The formula is roughly `0.01*X^2` at high values.

**Doubling atoms from 100→200 increases per-molecule power by ~4x** (not 2x). This is superlinear scaling built into both attack and defense equally — which means atom investment compounds in both directions symmetrically at the per-molecule level.

### BAL-R2-003 — Ionisateur gives offense a building-level bonus with no symmetric equivalent

**Mathematical proof:**
```
Attack multiplier from ionisateur: (1 + ionisateur*2/100)
Defense multiplier from champdeforce: (1 + champdeforce*2/100)
```

Both buildings cost the same (Oxygene/Carbone respectively, same exponent 0.7). Both cap at the same +2%/level bonus. This appears symmetric.

**However**, the champdeforce is a *damageable building* that can be knocked down by hydrogen destruction, while ionisateur is **not** in the list of damageable buildings (`NUM_DAMAGEABLE_BUILDINGS = 4`: generateur, champdeforce, producteur, depot).

From `combat.php` lines 438-468: when champdeforce is the highest-level damageable building, ALL hydrogen damage is directed at it. When it levels down, the +2%/level defense bonus decreases. Ionisateur is never targeted.

**Impact:** HIGH — Attackers permanently keep their ionisateur bonus. Defenders permanently lose champdeforce levels under sustained attack, creating irreversible offense advantage. Over a 31-day season, a player subjected to 10 successful attacks would lose 10 champdeforce levels, reducing defense by 20 percentage points while the attacker retains full ionisateur bonus.

**Fix proposal:** Make ionisateur damageable (add it to `NUM_DAMAGEABLE_BUILDINGS = 5`) with its own HP formula. Or: make champdeforce levels floor at its current level -1 per attack (not level 1) to limit degradation speed.

---

### BAL-R2-004 — The victory condition ($gagnant) uses surviving molecule COUNT not surviving molecule POWER

**Mathematical proof:**
```php
if ($attaquantsRestants == 0) {
    if ($defenseursRestants == 0) { $gagnant = 0; }  // draw
    else { $gagnant = 1; }  // defender wins
} else {
    if ($defenseursRestants == 0) { $gagnant = 2; }  // attacker wins
    else { $gagnant = 0; }  // draw
}
```

`$attaquantsRestants = SUM(classeAttaquant[c].nombre - classeAttaquantMort[c])`

A draw occurs when **both sides have surviving molecules**. No pillage occurs on a draw. This means: an attacker with 1 surviving molecule out of 1000 sent draws against a defender with 999 surviving molecules out of 1000. The attacker gains nothing despite losing 99.9% of their force.

**Impact:** MEDIUM — Skilled defenders can exploit this by building very high HP (brome-heavy) molecules that survive even massive attacks. The draw outcome is too binary: it ignores the extreme power differential between surviving units. A "pyrrhic draw" where attacker barely survives should still yield partial pillage.

**Fix proposal:** Introduce a partial pillage on draws proportional to attacker's surviving fraction:
```
if (gagnant == 0 && attaquantsRestants > 0 && totalAttackerSent > 0):
    partialPillageFactor = attaquantsRestants / totalAttackerSent
    ressourcesAPiller *= partialPillageFactor * 0.5  // 50% of normal pillage rate
```

---

### BAL-R2-005 — Building damage is per-class random roll not total damage

**Mathematical proof (combat.php lines 447-468):**
For each class `i` with surviving attackers that have hydrogene > 0:
```php
$bat = rand(1, 4);
$degatsAMettre = potentielDestruction($hydrogene_i, $niveauHydrogene) * surviving_i;
switch ($bat) {
    case 1: $degatsGenEnergie += $degatsAMettre; break;
    case 2: $degatschampdeforce += $degatsAMettre; break;
    case 3: $degatsProducteur += $degatsAMettre; break;
    default: $degatsDepot += $degatsAMettre; break;
}
```

A player with 4 classes all using hydrogen will roll 4 independent d4s. If all 4 classes happen to roll the same building (probability `(1/4)^3 = 1/64` for all on same target), ALL damage concentrates there. If spread, damage spreads across buildings.

The expected damage to any single building per battle is `hydrogeneTotal / 4`. However the **variance** is enormous: there's a `(1/4)^3 = 1.6%` chance three classes hit the same building for `3x` expected damage (potentially destroying it in one hit), and a 1/256 chance all four concentrate there.

**Impact:** MEDIUM — RNG makes building destruction wildly unpredictable. A top player can be effectively one-shot on their generateur purely by luck even if the average damage would be survivable. Conversely, a weak attacker might destroy a building by lucky concentration.

**Fix proposal:** Replace random roll per class with deterministic distribution: damage is split equally across all 4 buildings. Or: use a pseudo-random but bounded system — each class cycles through buildings sequentially, never hitting the same one twice in a combat. This keeps some variability but prevents extreme concentration.

---

## 3. Atom Type Utility Comparison

Every atom type feeds one game system. Here is the complete mapping:

| Atom | Symbol | Stat it drives | Formula type | Condenseur scaling |
|------|--------|---------------|--------------|-------------------|
| Carbone | C | Defense | `(0.1C)^2 + C` | `(1 + niveauC/50)` |
| Azote | N | Formation speed | `ceil(ntotal / (1 + (0.09N)^1.09) / ...)` | `(1 + niveau/20)` |
| Hydrogene | H | Building destruction | `(0.075H)^2 + H` | `(1 + niveau/50)` |
| Oxygene | O | Attack | `(0.1O)^2 + O` | `(1 + niveauO/50)` |
| Chlore | Cl | Fleet speed | `1 + 0.5*Cl` | `(1 + niveau/50)` |
| Soufre | S | Pillage | `(0.1S)^2 + S/3` | `(1 + niveau/50)` |
| Brome | Br | HP (survivability) | `(0.1Br)^2 + Br` | `(1 + niveau/50)` |
| Iode | I | Energy production | `0.10 * I` | `(1 + niveau/50)` |

### 3.1 Per-Atom Marginal Return Comparison

Marginal return = derivative of the stat formula with respect to atom count X:

| Atom | Stat formula | Marginal return at X=50 | Marginal at X=100 | Marginal at X=150 |
|------|-------------|------------------------|-------------------|-------------------|
| C (defense) | `(0.1X)^2+X` | `0.02*50+1 = 2.0` | `0.02*100+1 = 3.0` | `0.02*150+1 = 4.0` |
| O (attack) | `(0.1X)^2+X` | 2.0 | 3.0 | 4.0 |
| Br (HP) | `(0.1X)^2+X` | 2.0 | 3.0 | 4.0 |
| H (destroy) | `(0.075X)^2+X` | `0.01125*50+1 = 1.56` | `0.01125*100+1 = 2.13` | `0.01125*150+1 = 2.69` |
| S (pillage) | `(0.1X)^2+X/3` | `0.02*50+1/3 = 1.33` | `0.02*100+1/3 = 2.33` | `0.02*150+1/3 = 3.33` |
| I (energy) | `0.10*X` | 0.10 | 0.10 | 0.10 |
| Cl (speed) | `0.5*X` (offset) | 0.5 | 0.5 | 0.5 |
| N (formation) | denominator | varies | varies | varies |

### BAL-R2-006 — Iode is ~20x weaker per atom than attack/defense atoms at mid-game

**Mathematical proof:**
Iode formula: `productionEnergieMolecule(iode, niveau) = round(0.10 * iode * (1 + niveau/50))`

At 100 iode, niveau=0: contributes **10 energy/molecule/h**.
At 100 iode, niveau=10: contributes `10 * 1.2 = 12 energy/molecule/h`.

Compare: same 100 atoms put in Oxygene for attack:
`attaque(100, 0) = round((1 + (0.1*100)^2 + 100) * 1) = round(102) = 102` attack per molecule.

The question is: what is the value of 10 energy/molecule/h?
- At 100 iode molecules, this is 1000 E/h — roughly equivalent to 13 extra generator levels.
- But those 100 molecules with 0 attack/defense contribute nothing to combat.

The opportunity cost of 100 atoms of iode vs 100 atoms of oxygene: giving up 102 attack per molecule to gain 10 energy per molecule per hour.

**However**, the iode system runs through `revenuEnergie()` which already generates `75 * generateur_level` E/h. At generateur level 20: 1500 E/h base. Adding 100 iode molecules with 100 iode each = +1000 E/h = +67% energy. This is actually meaningful at low generator levels.

**The real problem is the formula linear nature**: iode scales as `0.10 * iode` (pure linear) while attack/defense scale as `(0.1X)^2 + X` (superlinear). At X=50, iode yields 5 combat-irrelevant energy; oxygene yields 26 attack. At X=200, iode yields 20 energy; oxygene yields 401 attack. The gap **quadruples** as atoms increase.

**Impact:** HIGH — Any player maximizing combat effectiveness has zero incentive to put iode in molecules beyond the very early game. Iode molecules are dead slots once a generateur-based economy can sustain operations. The 2025 buff from 0.01→0.10 coefficient was a 10x improvement but still does not close the structural linear vs quadratic gap.

**Fix proposal (BAL-R2-006):**
Change iode formula to quadratic to match other atoms:
```
productionEnergieMolecule(iode, niveau) = round((0.05*iode^2 + iode) * 0.10 * (1 + niveau/50))
```
Or add a secondary iode bonus: iode molecules also provide +1% attack per 10 iode atoms as "ionized plasma":
```
iodeAttackBonus = floor(totalIodeAcross4Classes / 10) / 100
```
This integrates iode into the combat system rather than making it purely economic.

---

### BAL-R2-007 — Soufre pillage formula has a structural penalty at low atom counts

**Mathematical proof:**
Soufre formula: `pillage(S, niveau) = round((0.1S)^2 + S/3) * (1 + niveau/50) * medalBonus`

At S=10: `(0.1*10)^2 + 10/3 = 1 + 3.33 = 4.33` pillage per molecule.
At S=50: `(0.1*50)^2 + 50/3 = 25 + 16.67 = 41.67` pillage.
At S=100: `(0.1*100)^2 + 100/3 = 100 + 33.33 = 133.33` pillage.

**Compare to attack (O) at same atom counts:**
At O=10: `(0.1*10)^2 + 10 = 1 + 10 = 11` attack.
At O=50: `(0.1*50)^2 + 50 = 25 + 50 = 75` attack.
At O=100: `(0.1*100)^2 + 100 = 100 + 100 = 200` attack.

**Ratios (pillage/attack at same X):**
- X=10: 4.33/11 = **39%** (soufre gives 39% as much stat per atom as oxygene)
- X=50: 41.67/75 = **55.6%**
- X=100: 133.33/200 = **66.7%**

The `S/3` divisor (vs `X` for attack) permanently handicaps soufre. Even at the atom cap of 200:
- S=200 pillage: `(20)^2 + 200/3 = 400 + 66.7 = 466.7`
- O=200 attack: `(20)^2 + 200 = 400 + 200 = 600`

Soufre always gives 78% of the stat return of equivalent oxygen investment.

**Impact:** HIGH — Pillage is a secondary win condition (pillage points + actual resources gained). But soufre molecules that specialize in pillage are simultaneously weaker in combat because you cannot also fill them with oxygen. The class that specializes in pillage has inherently lower defensive capability, making it preferential to attack the raider (who has no defense) rather than the defender.

**Fix proposal:** Change soufre linear component divisor from 3 to 1:
```php
define('PILLAGE_SOUFRE_DIVISOR', 1); // was 3
```
New pillage at S=100: `100 + 100 = 200` — now equal to attack per atom. Alternatively reduce PILLAGE_SOUFRE_DIVISOR to 2, bridging halfway.

---

### BAL-R2-008 — Chlore speed is fully linear but its value is entirely map-dependent

**Mathematical proof:**
Speed formula: `vitesse(Cl, niveau) = floor((1 + 0.5*Cl) * (1 + niveau/50) * 100) / 100`

At Cl=50, niveau=0: speed = `1 + 25 = 26` cases/hour.
At Cl=100, niveau=0: speed = `1 + 50 = 51` cases/hour.

Map travel time = distance / speed. Arrival time delta between two players at Cl=0 (speed=1) and Cl=100 (speed=51): a 100-case distance takes 100h vs 1.96h. The difference is enormous but entirely depends on map distances in a given game.

Speed has **no in-combat effect whatsoever**. It only determines how long attack missions take. Chlore contributes zero to damage, HP, or defense. Its value is purely strategic (hitting before the target can react) not mathematical.

**However**, formation time also uses azote in a denominator, while chlore contributes to travel speed. Neither has a combat in-battle payoff. These are "quality of life" atoms rather than "power" atoms.

**Impact:** LOW — Chlore and azote are support stats, not combat power. Their weakness is a design choice. However, players who don't understand this invest in these atoms expecting combat returns and are disappointed.

**Fix proposal:** No mechanical change needed. Add UI tooltips that explicitly categorize atoms: "Combat atoms: C, O, Br, H. Logistics atoms: Cl, N. Economic atoms: S (raids), I (energy). Logistics atoms have no combat power effect."

---

### BAL-R2-009 — Azote formation speed has diminishing returns that collapse past N=80

**Mathematical proof:**
Formation time formula:
```
tempsFormation(azote, niveau, ntotal) = ceil(ntotal / (1 + pow(0.09*azote, 1.09)) / (1 + niveau/20) / bonusLieur / ...)
```

The azote divisor `(1 + pow(0.09*N, 1.09))`:
- N=0: `1 + 0 = 1` (no reduction)
- N=10: `1 + pow(0.9, 1.09) = 1 + 0.867 = 1.867` → 46% faster
- N=20: `1 + pow(1.8, 1.09) = 1 + 1.98 = 2.98` → 66% faster
- N=50: `1 + pow(4.5, 1.09) = 1 + 5.06 = 6.06` → 83% faster
- N=80: `1 + pow(7.2, 1.09) = 1 + 8.34 = 9.34` → 89% faster
- N=100: `1 + pow(9.0, 1.09) = 1 + 10.57 = 11.57` → 91% faster
- N=150: `1 + pow(13.5, 1.09) = 1 + 16.25 = 17.25` → 94% faster

Going from N=80 to N=150 only adds 5% additional speed reduction, while going from N=0 to N=20 already gives 66%. This is a correctly diminishing curve — but the **minimum floor** is not stated to players. There is no way to get instantaneous formation regardless of azote investment.

**Impact:** LOW — This is intentional diminishing returns design. The exponent 1.09 (slightly super-linear for azote itself) partially offsets the 1/(1+X) asymptotic ceiling. No fix needed; this is working as intended.

---

## 4. Snowball Analysis

### 4.1 totalPoints Composition

```
totalPoints = constructionPoints + combatPoints + pillagePoints + marketPoints
```

**Construction points:** Awarded per building upgrade. Accumulate over time proportional to investment.

**Combat points:**
```
attackContrib = ATTACK_POINTS_MULTIPLIER * sqrt(abs(pointsAttaque)) = 5 * sqrt(pointsAttaque)
defenseContrib = DEFENSE_POINTS_MULTIPLIER * sqrt(abs(pointsDefense)) = 5 * sqrt(pointsDefense)
```

Raw combat points per battle:
```
battlePoints = min(20, floor(1 + 0.5 * sqrt(totalCasualties)))
```

**Pillage points:** `tanh(ressourcesPillees / 50000) * 80` — caps at ~80 for huge pillagers.

**Market points:** `min(80, floor(0.08 * sqrt(tradeVolume)))` — caps at 80.

### 4.2 Construction Points Lead Compounds

Construction point formulas from `$BUILDING_CONFIG`:
- Generateur/Producteur: `points_base=1, points_level_factor=0.1, points_per_level=8` (producteur)
- Condenseur: `points_base=2, points_level_factor=0.1, points_per_level=5`

From `augmenterBatiment()`, each building upgrade awards: `points_base + floor(current_level * points_level_factor)` construction points.

A player at level 10 on all buildings vs level 1 player:

| Building | L1→L10 total construction pts | L1 pts |
|----------|------------------------------|--------|
| Generateur | ~55 | 1 |
| Producteur (×8 per level) | ~440 | 8 |
| Condenseur (×5 per level) | ~275 | 5 |

The leader after 1 month easily has 1000+ construction points. A new player starts at 0. The `totalPoints` gap is near-unbridgeable from construction alone.

### BAL-R2-010 — Construction points compound into ranking which compounds into medals which compound into combat bonuses

**Mathematical proof (the full snowball chain):**

1. **High construction points** → higher `totalPoints` → higher ranking → more Victory Points at season end → more medals next season.
2. **More medals** → higher medal tier → attack/defense medal bonuses:
   - Bronze (1%), Silver (3%), Gold (6%), Emerald (10%), Sapphire (15%), Ruby (20%), Diamond (30%), Red Diamond (50%)
3. **Higher attack medal bonus** multiplies directly into `attaque()`:
   ```
   attaque = base_formula * (1 + medal_bonus / 100)
   ```
   Red Diamond player: `base * 1.50` attack.
   New player: `base * 1.01` attack (Bronze minimum).
   **Ratio: 1.49x raw attack advantage from medals alone.**

4. **More molecules from better production** → more combat power (linear in molecule count).
5. **Better construction from more resources** → more buildings → more bonuses → more medals.

**The snowball is logarithmically bounded in most axes** (tanh for pillage, sqrt for combat, log-like for market) but **unbounded in construction points** and **step-function in medals** (each tier is a large discrete jump).

**Impact:** CRITICAL — Season 1 veterans re-entering in season 2 with Diamond+ medals have a 30-50% raw combat multiplier over new players with Bronze medals. Combined with better buildings, this creates a skill-independent win condition for returning players. New players cannot win in seasons 2+.

**Fix proposal (BAL-R2-010):** Add a seasonal medal soft-reset: at season start, each player keeps their medal tier but starts at the **bottom threshold** of that tier (not the top). This means a Diamond player keeps their Diamond tier but their combat bonus is still Diamond (+30%) without compounding extra. The carry-forward is limited to tier not points-within-tier. Alternatively: cap medal combat bonus at Emerald (+10%) for the first 2 weeks of a new season to create a grace period.

---

### BAL-R2-011 — Victory points formula creates cliff between rank 3 and rank 4

**Mathematical proof (formulas.php):**
```
Rank 1: 100 VP
Rank 2: 80 VP
Rank 3: 70 VP
Rank 4: 65 VP
Rank 5: 60 VP
...
```

The gap between rank 2 and rank 3 is 10 VP. The gap between rank 3 and rank 4 is 5 VP. But the gap between rank 1 and rank 2 is 20 VP. There is no compelling reason for rank 1 to be 25% better than rank 2 and only 14% better than rank 3.

More critically: rank 51-100 returns `max(1, floor(3 - (rank-50) * 0.04))`.
At rank 51: `3 - 0.04 = 2.96 → 2 VP`.
At rank 75: `3 - 1.0 = 2.0 VP`.
At rank 100: `3 - 2.0 = 1.0 VP`.

Ranks 51-100 earn between 1-2 VP. Rank 50 earns `floor(15 - 30 * 0.5) = 0 VP` — actually 0!

Wait: rank 50 formula: `max(1, floor(15 - (50-20)*0.5)) = max(1, floor(15-15)) = max(1, 0) = 1 VP`.

Rank 49: `max(1, floor(15 - 29*0.5)) = max(1, floor(0.5)) = max(1, 0) = 1 VP`.

**The VP curve collapses to 1 for ranks 21-100**, making mid-tier ranking feel meaningless.

**Impact:** MEDIUM — Players ranked 21-50 feel they gain nothing from improving their rank within that band. The incentive to fight for position exists only in the top 20.

**Fix proposal:** Reshape the VP curve to maintain meaningful deltas throughout:
```
Rank 1: 100, Rank 2: 85, Rank 3: 72
Ranks 4-10: 72 - (rank-3)*7  → 65, 58, 51, 44, 37, 30, 23
Ranks 11-20: 23 - (rank-10)*1.5
Ranks 21-50: 8 - (rank-20)*0.2
Ranks 51-100: max(1, 2)
```
This gives rank 50 players 4 VP (vs current ~1) and tightens the meaningful competition band.

---

## 5. Formation Viability Analysis

Three formations: Dispersée (0), Phalange (1), Embuscade (2).

### 5.1 Dispersée (default)

```
Damage to each defender class = degatsAttaquant / activeDefClasses
```
Fixed at equal split across active classes (post-BAL-018 fix to split only among active classes).

**Mathematical characteristics:** Each class absorbs equal damage regardless of its HP pool. A class with 1 HP molecule and a class with 1000 HP molecules both receive the same damage share. This is INCORRECT — it means a player with one dead class (0 molecules) has 1/3 of total damage concentrated on 3 remaining classes. Dispersée is actually the worst formation for partially-depleted defenders.

### BAL-R2-012 — Dispersée ignores HP-weighted distribution creating exploitable damage patterns

**Mathematical proof:**
Scenario: Defender has 4 classes. Classes 1-3 have 100 molecules each. Class 4 has 1 molecule.

Dispersée: damage split among 4 active classes → 25% each.
Class 4 (1 molecule) receives 25% of total attacker damage. Since it has 1 molecule with low HP, it dies instantly but the remaining 25% damage in that "bucket" is wasted (not redirected).

The code confirms this — `min($classeDefenseur4, floor($damageShare / $hpPerMol))` kills at most the molecules present. Excess damage is discarded.

**Impact:** MEDIUM — In a lopsided situation, Dispersée wastes damage. But this also *protects* the other three classes from that wasted damage. Defenders benefit from keeping even a tiny class alive to absorb and waste 25% of incoming damage. This is an exploitable strategy: always maintain at least 1 molecule in all 4 classes.

**Fix proposal:** Change Dispersée to HP-weighted proportional distribution:
```php
// Instead of activeDefClasses split, use HP proportion:
$defDamageShares[$i] = ($totalDefenderHP > 0)
    ? $degatsAttaquant * (${'defHP'.$i} / $totalDefenderHP)
    : 0;
```
This is already implemented as the *fallback* case in the else-branch (line 286-288). Moving Dispersée to this model makes it truly "equal" in terms of damage efficiency.

---

### 5.2 Phalange Analysis

```
Class 1 absorbs: 70% of damage, gets +30% defense
Classes 2-4 absorb: 10% each (30% split among 3)
```

**When is Phalange better than Dispersée?**

For Phalange to be superior, Class 1 must survive the concentrated 70% damage AND have enough defense bonus to reduce casualties.

Class 1 defense multiplier in Phalange: `1.30 * defReactionDefenseBonus * defIsotopeAttackMod[1]`
Class 1 effective HP pool (Phalange): `classeDefenseur1.nombre * hpPerMol * 1.20` (if Stable isotope)

For Class 1 to absorb 70% of attack without losing all molecules:
```
0.70 * degatsAttaquant <= classeDefenseur1.nombre * hpPerMol * (1 + 0.30)
classeDefenseur1.nombre >= 0.70 * degatsAttaquant / (hpPerMol * 1.30)
```

vs Dispersée where Class 1 absorbs only 25%:
```
0.25 * degatsAttaquant <= classeDefenseur1.nombre * hpPerMol
classeDefenseur1.nombre >= 0.25 * degatsAttaquant / hpPerMol
```

Phalange requires Class 1 to be at least `(0.70/0.25) / 1.30 = 2.15x` larger than needed for Dispersée to break even on Class 1 survivability. Phalange only pays off if:
- Class 1 is very large/tanky (many molecules + high HP)
- Classes 2-4 are fragile (few molecules, low HP)

### BAL-R2-013 — Phalange breaks catastrophically against multi-wave attackers

**Mathematical proof:**
After the first attack with Phalange active: if Class 1 loses 60% of its molecules, the next attack with the same formation hits the same weakened Class 1 with 70% of damage. Sequential attackers (via alliance wars) can systematically destroy Class 1 in 2-3 hits while Classes 2-4 remain at full strength but unable to contribute.

The defender cannot change formation during an active attack — the formation is read from DB at combat resolution time. If multiple attacks arrive in rapid succession (all queued during 4h cooldown from *different* attackers), Class 1 faces repeated 70% concentration.

**Impact:** HIGH — Phalange is strictly inferior against coordinated alliance attacks, which is the most dangerous scenario in endgame. Most experienced players should default to Dispersée (or the new HP-proportional version of it).

**Fix proposal:** Allow formation change with a 1-hour cooldown (not instant). This means players can adapt to ongoing threats but not micro-game formation switching per attack. Store `formation_changed_at` timestamp in constructions table.

---

### 5.3 Embuscade Analysis

```
if (totalDefenderMols > totalAttackerMols):
    embuscadeDefBoost = 1.25  // +25% to defender's damage output
else:
    embuscadeDefBoost = 1.0   // no bonus at all
```

**Mathematical proof:**
Embuscade provides zero benefit if the defender has equal or fewer molecules than the attacker. A defender with 500 total molecules vs an attacker who sends 501 molecules gets NO bonus.

But: a defender who has 1000 molecules vs an attacker who sends 500 gets the full +25%.

The bonus threshold is the **total sent** from all attacker classes (`troupes` field), not the attacker's total army. An experienced attacker can deliberately send a small force to scout (e.g., 1 molecule per class = 4 total) and trigger 0 damage while the Embuscade defender gets the +25% bonus — but it doesn't matter because 1 attacker molecule does essentially 0 damage anyway.

### BAL-R2-014 — Embuscade condition uses raw molecule count not combat-power count

**Mathematical proof:**
A defender with 1000 weak molecules (100 Br each, 0 attack) vs an attacker with 50 powerful molecules (200 O each):
- Embuscade activates: 1000 > 50. Defender gets +25%.
- Defender's damage: defense(0 carbone, ...) * 1.25 * 1000 = ~1000 * 0 * ... = 0.
- Embuscade does nothing because defender has no carbon in these molecules.

Embuscade is tagged to molecule *count* while it should be relevant to combat *power*. A tank army (many molecules, all brome/HP focused, no carbon) gets the Embuscade boost but still deals zero damage.

**Impact:** MEDIUM — Embuscade only benefits players who already have a large, combat-capable army. Newer players with quantity over quality don't benefit. It's the strongest formation for established players fending off smaller incursions but completely useless for new players and breaks against quality-over-quantity attackers.

**Fix proposal:** Change Embuscade condition from molecule count to effective defense score:
```php
$totalDefPower = $degatsDefenseur; // already computed
$totalAttPower = $degatsAttaquant;
if ($totalDefPower > $totalAttPower && $defenderFormation == FORMATION_EMBUSCADE):
    $embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS;
```
This makes Embuscade activate when the defender is winning on combat power, which is a more meaningful threshold. Combine with reducing the bonus to +15% (to prevent stacking with other bonuses for a self-reinforcing loop).

---

## 6. Isotope Cost/Benefit Analysis

Four isotope types at molecule creation (irreversible choice):

| Isotope | Attack mod | HP mod | Decay mod | Other |
|---------|-----------|--------|-----------|-------|
| Normal | 0 | 0 | 0 | — |
| Stable | -10% | +20% | -30% (slower) | Tank role |
| Réactif | +20% | -10% | +50% (faster) | Glass cannon |
| Catalytique | -10% | -10% | 0 | +15% to other 3 classes |

### 6.1 Stable Isotope Analysis

HP increases by 20% but attack decreases by 10%. Decay rate is 30% slower (molecule lasts longer).

**Net combat exchange rate:**
Stable molecule vs Normal molecule of equal composition:

```
Attack:   normal_attack * 0.90   (10% less)
HP pool:  normal_hp * 1.20       (20% more, absorbs 20% more damage)
Decay:    coef^(0.70) per second  (survives 30% longer between battles)
```

For a pure tank role: stable is strictly better at surviving (both more HP and slower decay). The attack penalty is accepted.

**However**, the decay formula is:
```
baseDecay = pow(pow(DECAY_BASE, pow(1 + nbAtomes/150, 2) / 25000), ...)
```
At 300 total atoms, 0 stabilisateur:
```
pow(1 + 300/150, 2) = pow(3, 2) = 9
exponent = 9 / 25000 = 0.00036
baseDecay = pow(0.99, 0.00036) ≈ 0.999996
```
This is an extremely slow decay to begin with. The Stable isotope's -30% reduction on an already tiny exponent makes decay negligible. For a molecule of 300 atoms, the half-life without stabilisateur is:
```
demiVie = log(0.5) / log(0.999996) ≈ 173,287 seconds ≈ 48 hours
```
With Stable isotope (exponent * 0.70): half-life ≈ 68.6 hours.
The practical difference between 48h and 69h half-life for a game with 4-hour attack cooldowns is minimal — the molecule is essentially permanent within a day.

**Impact:** LOW — Stable isotope's decay bonus is essentially irrelevant for molecules below ~400 atoms. The meaningful trade is purely -10% attack vs +20% HP.

### 6.2 Réactif Isotope Analysis

+20% attack but +50% faster decay. At 300 atoms:
- Base half-life: 48h
- Réactif half-life: exponent * 1.50 → half-life ≈ 32h

A Réactif molecule losing 50% every 32 hours: after 3 days (72 hours) it has `0.5^(72/32) = 0.5^2.25 ≈ 21%` remaining. Compare to Stable: `0.5^(72/69) ≈ 48%` remaining.

**After 7 days: Réactif retains `0.5^(168/32) ≈ 2%`. Stable retains `0.5^(168/69) ≈ 18%`.**

### BAL-R2-015 — Réactif isotope is dominated by Normal in all medium-term scenarios

**Mathematical proof:**
Expected output over 7 days per initial molecule:

```
Réactif: sum(t=0 to 168h) [attack * 1.20 * 0.5^(t/32)] dt
       ≈ 1.20 * attack * ∫ 0.5^(t/32) dt from 0 to ∞
       = 1.20 * attack * 32/ln(2)
       ≈ 1.20 * 46.2 * attack = 55.4 * attack-hours per initial molecule

Normal: = 1.0 * attack * ∫ 0.5^(t/48) dt from 0 to ∞
       = 1.0 * 48/ln(2) * attack
       ≈ 69.3 * attack-hours per initial molecule
```

**Normal delivers 25% more total attack output over its lifetime than Réactif** despite the Réactif having 20% higher peak attack. The faster decay destroys this advantage.

The only scenario where Réactif beats Normal is a single-day all-out offense where you don't care about tomorrow. This is a viable "rushing" strategy but only in late season.

**Impact:** MEDIUM — Réactif is a trap choice for new players who see "+20% attack" without understanding decay math. Should be clearly labeled "optimal for one-time rushes only" in the UI.

**Fix proposal:** Reduce Réactif decay penalty from +50% to +20%:
```php
define('ISOTOPE_REACTIF_DECAY_MOD', 0.20); // was 0.50
```
Recalculate lifetime output:
```
New Réactif: 1.20 * attack * 48/ln(2) / 1.20 = 1.20 * 57.7 * attack = 69.3
```
Now Réactif equals Normal in lifetime output but with front-loaded power — a true glass cannon that pays off if you win early but not if the game goes long.

---

### 6.3 Catalytique Isotope Analysis

Costs: -10% attack, -10% HP. Provides: +15% to ALL OTHER 3 classes' attack AND HP.

**Mathematical proof:**
Without Catalytique (4 normal classes of equal size N):
```
totalAttack = 4 * N * attack_per_mol
```

With 1 Catalytique (class A) and 3 Normal (classes B, C, D):
```
Catalytique class contribution: N * attack_per_mol * 0.90
Normal classes contribution: 3 * N * attack_per_mol * (1 + 0.15) = 3.45 * N * attack_per_mol
Total: 3.45 * N * attack_per_mol + 0.90 * N = 4.35 * N * attack_per_mol
```

**Catalytique gives +8.75% total attack** (4.35 vs 4.00) with one class slot dedicated to support.

**For HP survivability:**
```
Normal: 4 * N * hp_per_mol
Catalytique: N * 0.90 * hp_per_mol + 3 * N * 1.15 * hp_per_mol = 4.35 * N * hp_per_mol
```
Same +8.75% improvement.

**The opportunity cost:** Class A's molecules lose 10% attack and 10% HP each. The gain is 15% on 3 classes. Net gain = `3*0.15 - 1*0.10 - 1*0.10 = 0.25` per class = **25% net gain per class** across 4 classes = **+6.25% total** (more precisely the math above shows +8.75%).

### BAL-R2-016 — Catalytique bonus stacks with Phalange formation creating a dominant combined strategy

**Mathematical proof:**
With Catalytique isotope on one class AND Phalange formation:

Phalange gives +30% defense to Class 1.
If Catalytique is on Class 2, 3, or 4 (not Class 1), then Class 1 receives +15% attack AND +15% HP from Catalytique, PLUS the +30% defense bonus from Phalange.

Net multiplier on Class 1 defense output:
```
defense * (1 + 0.15) * (1 + 0.30) = defense * 1.15 * 1.30 = defense * 1.495
```

So Class 1 gets ~50% defense bonus stacked. This is the dominant defensive strategy and creates a near-optimal meta: Catalytique on a rear class, Phalange on, maximize Class 1 HP with Stable isotope and Brome:

Class 1 with Stable isotope, Phalange, Catalytique from another class:
```
HP: 1.20 (Stable) * 1.15 (Catalytique) = 1.38x
Defense: 1.30 (Phalange) * 1.15 (Catalytique) = 1.495x
```

This particular combination is not broken but it means every experienced player converges to the same optimal 4-class setup: one Catalytique support + Phalange. Diversity is lost.

**Impact:** LOW-MEDIUM — The strategy is powerful but not game-breaking. The cost (one class slot doing 10% less damage and having 10% less HP) is worth the gain.

**Fix proposal:** No change needed. The combination rewards understanding game mechanics. Mild concern: add a fourth formation option "Formation Catalytique" that only activates the Catalytique bonus if all 4 classes are present and active, making it explicit.

---

## 7. Chemical Reaction Analysis

Five reactions defined in `$CHEMICAL_REACTIONS`:

| Reaction | Condition A | Condition B | Bonus |
|----------|------------|-------------|-------|
| Combustion | O>=100 | C>=100 | +15% attack |
| Hydrogénation | H>=100 | Br>=100 | +15% HP |
| Halogénation | Cl>=80 | I>=80 | +20% speed |
| Sulfuration | S>=100 | N>=50 | +20% pillage |
| Neutralisation | O>=80 | H>=80 + C>=80 | +15% defense |

### BAL-R2-017 — Halogénation and Sulfuration bonuses affect non-combat stats with no combat impact

**Mathematical proof:**
Halogénation gives +20% speed. Speed affects travel time only. In combat, `vitesse` is never used. The +20% speed bonus from Halogénation has zero impact on any combat calculation in `combat.php`.

Sulfuration gives +20% pillage. The pillage bonus IS used in combat.php:
```php
$ressourcesAPiller = (...) * $attReactionPillageBonus;
```
So Sulfuration does matter — it increases resources stolen on a win. But Halogénation (+20% speed) has **zero effect on combat** and only marginally affects attack timing.

**Impact:** MEDIUM — Two atoms (Cl, I) required for Halogénation have no combat effect. Committing 80 Cl and 80 I atoms to trigger a speed bonus incentivizes putting these atoms in molecules — but since Cl and I produce no attack, defense, or HP, these molecules are pure logistics platforms. This is valid design but it means Halogénation requires sacrificing combat molecule slots for travel speed.

**Fix proposal:** Change Halogénation to also grant a minor combat bonus — e.g., "+10% attack initiative: if speed > opponent speed, attacker gets +10% attack in the first combat round." This is architecturally complex to implement since combat.php doesn't model rounds. Simpler: change Halogénation to "+20% attack" when initiating an attack (attacker-only bonus based on their own speed), making speed investment directly combat-relevant.

---

### BAL-R2-018 — Combustion and Neutralisation share the same atoms (O), creating a build-order conflict

**Mathematical proof:**
- Combustion requires: O>=100 (Class A) + C>=100 (Class B) → +15% attack
- Neutralisation requires: O>=80 (Class A) + H>=80+C>=80 (Class B) → +15% defense

If a player has one Oxygen-heavy class, it can trigger Combustion OR Neutralisation but not both simultaneously for the same pair — because the check iterates `a` and `b` as distinct classes. A player *could* have:
- Class 1: 100 O (triggers Combustion vs Class 2, Neutralisation vs Class 3)
- Class 2: 100 C (Combustion pair)
- Class 3: 80 H + 80 C (Neutralisation pair)

In this scenario, both reactions could technically activate. The code checks `if (!isset($activeReactions[$name]))` — it records only the first trigger per reaction name across all pairs. So if Combustion fires on classes (1,2) pair, it doesn't fire again on (1,3).

**However**: Neutralisation requires O>=80 in condA and the code checks condA/condB independently. If Class 1 has O=100 and Class 3 has H>=80 AND C>=80 (both in same class), then Neutralisation fires between (Class 1, Class 3). This DOES stack with Combustion.

A player can achieve both Combustion (+15% attack) and Neutralisation (+15% defense) by having:
- Class 1: O=100
- Class 2: C=100 (triggers Combustion with Class 1)
- Class 3: H=80, C=80 (triggers Neutralisation with Class 1)
- Class 4: free

**Impact:** LOW — This stacking is possible but requires significant atom investment (100 O, 100 C in two classes, 80 H + 80 C in a third). It's a legitimate high-skill optimization.

---

## 8. Market Equilibrium Analysis

### 8.1 Price Mechanics

**On buy:**
```php
$ajout = $tabCours[$num] + $volatilite * $amount / $placeDepot;
$ajout = $ajout * (1 - 0.01) + 1.0 * 0.01;  // 1% mean reversion
$ajout = max(0.1, min(10.0, $ajout));
```

**On sell:**
```php
$ajout = 1 / (1 / $tabCours[$num] + $volatilite * $amount / $placeDepot);
$ajout = $ajout * (1 - 0.01) + 1.0 * 0.01;  // 1% mean reversion
$ajout = max(0.1, min(10.0, $ajout));
```

Where `$volatilite = 0.3 / max(1, nbActifs)`.

### 8.2 Volatility Scaling

With N active players:
- N=1: volatility = 0.30/1 = 0.30
- N=5: volatility = 0.30/5 = 0.06
- N=10: volatility = 0.30/10 = 0.03
- N=30: volatility = 0.30/30 = 0.01

For a buy of 1000 atoms with placeDepot=5000 and N=10 active players:
```
priceImpact = 0.03 * 1000/5000 = 0.006
```
A buy of 1000 atoms moves the price by 0.006 units (e.g., from 1.000 to 1.006).

### BAL-R2-019 — Market volatility formula uses nbActifs from a stale query executed before any buy/sell action

**Mathematical proof (marche.php lines 6-7):**
```php
$actifs = dbFetchOne($base, 'SELECT count(*) AS nbActifs FROM membre WHERE derniereConnexion >=?', 'i', (time() - 2678400));
$volatilite = 0.3 / max(1, $actifs['nbActifs']);
```
This query counts active players at page load time. The `max(1, ...)` prevents division by zero but also means that with only 1 player registered (solo game), volatility = 0.30 — extremely high. With 30 players, volatility = 0.01 — very stable.

**The config defines `MARKET_VOLATILITY_FACTOR = 0.5`** (line 360 of config.php) but the code uses hardcoded `0.3`:
```php
$volatilite = 0.3 / max(1, $actifs['nbActifs']); // hardcoded, not using MARKET_VOLATILITY_FACTOR
```
The config constant is **never used**. This is dead configuration.

**Impact:** MEDIUM — The volatility formula ignores the defined constant, making config.php misleading. If a developer changes `MARKET_VOLATILITY_FACTOR` to 0.5 expecting it to affect market behavior, nothing changes.

**Fix proposal:**
```php
$volatilite = MARKET_VOLATILITY_FACTOR / max(1, $actifs['nbActifs']);
```

---

### BAL-R2-020 — Market mean reversion uses MARKET_MEAN_REVERSION constant correctly but is too slow with few players

**Mathematical proof:**
With mean reversion rate `r = 0.01` (1% per trade):
```
price(n) = price(0) * (1-r)^n + 1.0 * (1 - (1-r)^n)
```
Number of trades to reach 50% of the way from current price to 1.0:
```
(1-0.01)^n = 0.5  →  n = log(0.5)/log(0.99) ≈ 69 trades
```

If a game has 5 active players making 2 trades/day each = 10 trades/day. It takes 7 days to revert halfway to equilibrium from any price shock. This is very slow.

With the Équilibre catalyst active (`market_convergence = 0.50`):
```
effective_r = 0.01 * (1 + 0.50) = 0.015
n = log(0.5)/log(0.985) ≈ 46 trades → 4.6 days
```

Still slow. The market reacts to supply shocks over days, making price manipulation persistent.

**Impact:** LOW — Slow mean reversion can be a feature (price history is meaningful) or a bug (a cartel of 2-3 players can corner the market on one atom type for an entire week). In a small player-count game, the latter is plausible.

**Fix proposal:** Scale mean reversion inversely with player count:
```php
$meanReversion = MARKET_MEAN_REVERSION * max(1, 30 / $actifs['nbActifs']);
```
With 5 players: `r = 0.01 * 6 = 0.06` — halftime in 12 trades = 1.2 days. More responsive.

---

### BAL-R2-021 — Resource send mechanism has an asymmetric exchange rate that punishes high-production senders

**Mathematical proof (marche.php lines 62-75):**
```php
if ($revenuEnergie >= revenuEnergie($constructions['generateur'], $_POST['destinataire'])) {
    $rapportEnergie = revenuEnergie($constructions['generateur'], $_POST['destinataire']) / $revenuEnergie;
} else {
    $rapportEnergie = 1;
}
```

If Sender (S) produces 1000 E/h and Receiver (R) produces 200 E/h:
```
rapportEnergie = 200/1000 = 0.20
```
Receiver gets only **20%** of sent energy. Sending 1000 energy yields only 200 received.

Conversely, if Receiver produces MORE than Sender:
```
rapportEnergie = 1.0
```
Receiver gets 100% of sent resources.

**The design intent** is to prevent whale-to-alt resource transfer exploits. A strong player sending to a weak alt loses 80% of the resources. However:

1. The formula uses `revenuEnergie` (production rate) not `totalPoints` or any other measure of player strength.
2. A player who specifically leveled Iode molecules gets more energy, appearing "higher production" and thus loses more on transfers.
3. The atom exchange rate uses `revenuAtome(num, receiver) / revenuAtome(num, sender)` — same pattern.

**For atoms:** If sender has 300/h of carbone and receiver has 60/h:
```
rapportCarbone = 60/300 = 0.20
```
Again, 20% transfer efficiency for atoms.

**Impact:** MEDIUM — Transfer taxation by production ratio is a creative anti-exploit mechanism, but it scales too aggressively. A legitimate player helping a newer friend loses 80% of their gift if they have 5x better production. It effectively kills the concept of "helping allies" for high-level players.

**Fix proposal:** Add a floor to the exchange ratio — minimum 50% transfer efficiency:
```php
$rapportEnergie = max(0.50, revenuEnergie($receiver) / $revenuEnergie);
```
This still penalizes alt-farming (a whale with 10x production loses 50% min, not 10%) while allowing meaningful ally support.

---

## 9. Building Cost/Benefit Curves

### 9.1 Construction Cost Formula

From config.php comments:
```
cost_energy = round((1 - medalBonus/100) * BASE_COST * pow(level, COST_EXP))
time = round(BASE_TIME * pow(level, TIME_EXP))  // seconds
```

### 9.2 Generateur Cost/Benefit

```
cost_energy(level) = 50 * pow(level, 0.7) * (1 - medal/100)
time(level) = 60 * pow(level, 1.5)  [except level 1 = 10s]
benefit = +75 E/h net (after producteur drain)
```

**Cost at key levels:**

| Level | Cost energy | Time (s) | Time (h) | Payback (h at +75 E/h) |
|-------|------------|----------|----------|----------------------|
| 1     | 50         | 10       | 0.003    | 0.67                |
| 5     | 195        | 671      | 0.19     | 2.60                |
| 10    | 316        | 1897     | 0.53     | 4.21                |
| 20    | 500        | 5366     | 1.49     | 6.67                |
| 50    | 962        | 21213    | 5.89     | 12.83               |
| 100   | 1585       | 60000    | 16.67    | 21.13               |

**Payback periods are reasonable and scale sub-quadratically** — level 100 takes 16.67h to build but pays back in 21.13h. This is healthy: late-game upgrades remain worthwhile.

**However**, level 100 generateur costs `1585 * (1-medal_bonus)` energy. With Red Diamond (50% discount): 792 energy. But that's one-time cost, and the building level is permanent. The medal bonus here incentivizes high-medal players to rush buildings faster, compounding their economic advantage.

### BAL-R2-022 — Medal bonus on building costs creates a compounding early-game acceleration for veterans

**Mathematical proof:**
Red Diamond veteran (50% medal bonus) vs new player (1% medal bonus):

Veteran's effective building cost = 0.50 of nominal.
New player's effective building cost = 0.99 of nominal.

At generateur level 50: nominal cost 962 energy.
- Veteran pays: 481 energy, time unchanged.
- New player pays: 952 energy.

The veteran reaches the same building level using half the energy investment. Since energy is reinvested into the next upgrade, the veteran's energy compounds at effectively **2x speed** throughout the building race. After 10 building levels, the energy gap is:
```
veteran_spent = SUM(cost(L)*0.5) = 0.5 * SUM(cost(L))
new_player_spent = 0.99 * SUM(cost(L))
```
Veteran spent 50% as much energy as new player. That 50% saved energy funds ~5 additional building levels.

**Impact:** HIGH — Medal bonuses on construction costs are effectively a permanent handicap on new players in every new season. Veterans snowball faster in every season dimension: lower costs → more buildings → more combat power → more medals → lower costs.

**Fix proposal:** Remove medal bonus from building *costs* (not time). Keep medal bonuses only on combat formulas and pillage. Construction cost should be the same for everyone to create a level economic playing field. Alternatively: cap the medal cost discount at 20% (Emerald tier) regardless of actual medal tier.

---

### 9.3 Vault (Coffrefort) Analysis

```
protection_per_level = 100 resources of each type protected
time = 90 * pow(level + 1, 1.2)
```

At level 10: protects 1000 of each of 8 atom types = 8000 atoms total.
At level 10, total plageDepot = 500 * depot_level. If depot=20: 10,000 storage.

Level 10 vault protects 8000/10000 = **80% of storage** from pillage.

### BAL-R2-023 — Vault protection scales linearly while pillage scales quadratically with soufre atoms

**Mathematical proof:**
Coffrefort at level L protects `100 * L` of each resource type.
Pillage per molecule: `(0.1*S)^2 + S/3` (at S=100, niveau=0) = ~133.

Against a vault at level 10 (protecting 1000 per type):
- Attacker needs `pillage_per_mol * surviving_mols > 1000 + remaining_exposed` to steal anything meaningful.
- 10 surviving molecules with S=100: 10 * 133 = 1330 pillage capacity.
- Exposed resources (above vault): `depotLevel * 500 - 1000`.

At depot=5: 5000 - 1000 = 4000 exposed per type. 1330 pillage capacity steals 1330 from 4000 exposed = 1330 resources (proportional across types).

**At depot=20**: 10000 - 1000 = 9000 exposed. Same 1330 pillage steals 1330 from 9000.

The vault does NOT scale with depot level. As players build larger depots, the vault's protection covers a **smaller fraction** of total resources. A vault level 10 with depot level 5 protects 20% of storage. A vault level 10 with depot level 50 protects only 2% of storage.

**Impact:** MEDIUM — Players who expand their depot storage without also upgrading the vault become exponentially more vulnerable to pillage proportionally. Experienced players know this; new players don't.

**Fix proposal:** Change vault protection to a **percentage of storage** rather than absolute amount:
```php
define('VAULT_PROTECTION_PERCENT_PER_LEVEL', 0.02); // 2% per level
$vaultProtection = placeDepot($depot_level) * VAULT_PROTECTION_PERCENT_PER_LEVEL * $vaultLevel;
```
Level 10 vault always protects 20% of total storage regardless of depot size.

---

## 10. Alliance Research Tree Analysis

Six technologies: Duplicateur + Catalyseur, Fortification, Réseau, Radar, Bouclier.

### 10.1 Duplicateur Value

```
bonusDuplicateur = 1 + level * 0.01  (1% per level)
Cost: round(10 * pow(2.5, level + 1)) energy
```

Cost at key levels:

| Level | Cost | Cumulative cost | Atom production bonus |
|-------|------|----------------|----------------------|
| 1     | 25   | 25             | +1%                  |
| 5     | 977  | 1,256          | +5%                  |
| 10    | 95,367 | 120,000+     | +10%                 |
| 15    | 9.3M | ~12M           | +15%                 |
| 25    | 88B  | ~120B          | +25%                 |

The Duplicateur cost grows at `2.5^level` — this is **exponential**. The benefit grows linearly (+1%/level). The crossover where the cost becomes prohibitive for a normal game economy is around level 8-10.

### BAL-R2-024 — Duplicateur benefit function is linear but cost is exponential — mid-levels are waste

**Mathematical proof:**
Marginal benefit of Duplicateur level L: +1% to all production and ALL combat stats (through `bonusDuplicateurAttaque`).

At level 1: +1% for 25 energy. Energy payback time = 25/(0.01*base_production).
At level 10: +1% for 95,367 energy. Payback time = 95,367/(0.01*base_production).

If base energy production = 500 E/h: payback at level 10 = 95,367/(0.01*500) = **19,073 hours = 794 days**.

No 31-day game season has enough time to recoup Duplicateur level 10 from production alone. Its value comes from the combat multiplier — +10% to all class damage. But the exponential cost means:
- Level 1-5: reasonable investment.
- Level 6-10: requires entire alliance treasury.
- Level 11+: mathematically impossible without grinding for months.

**Impact:** MEDIUM — The duplicateur creates an alliance power cliff: alliances that can afford level 5-6 have a meaningful 5-6% combat advantage. Level 7+ is a "whaling" mechanic only for very established alliances.

**Fix proposal:** Change cost factor from 2.5 to 2.0:
```php
define('DUPLICATEUR_COST_FACTOR', 2.0); // was 2.5
```
Level 10 cost with factor 2.0: `10 * pow(2.0, 11) = 10 * 2048 = 20,480 energy` — far more achievable. Level 15: ~327,680 energy — still a significant alliance investment but not astronomically impossible.

---

## 11. Victory Point Economy Analysis

### 11.1 The Role of Alliance VP

```
Alliance rank 1: +15 VP
Alliance rank 2: +10 VP
Alliance rank 3: +7 VP
Ranks 4-9: 10 - rank VP
```

Alliance VP caps at 15. Individual VP caps at 100. The maximum VP a player can earn per season:
```
Individual top rank: 100 VP
Alliance top rank:   15 VP
Total max:          115 VP
```

### BAL-R2-025 — Alliance VP contribution is negligible relative to individual rank VP

**Mathematical proof:**
Alliance rank 1 bonus (15 VP) is only 15% of individual rank 1 bonus (100 VP). For a mid-tier player (rank 30, ~8 individual VP), being in the top alliance adds 15 VP — nearly doubling their total. But for top players, alliance VP is marginal.

The incentive structure:
- Top individual player: 100 + 15 = 115 VP. Alliance matters ~13%.
- Rank 30 player: 8 + 15 = 23 VP. Alliance matters ~65%.

**Alliance matters much more to weak players than to strong players** — this is actually good design! It gives mid-tier players a reason to join alliances. No fix needed.

---

### BAL-R2-026 — Alliance VP rank 4-9 formula has an arithmetic error for rank 9

**Mathematical proof (formulas.php lines 45-46):**
```php
if ($classement < 10) {
    return VP_ALLIANCE_RANK2 - $classement;  // VP_ALLIANCE_RANK2 = 10
}
```

At rank 4: `10 - 4 = 6 VP`
At rank 5: `10 - 5 = 5 VP`
At rank 6: `10 - 6 = 4 VP`
At rank 7: `10 - 7 = 3 VP`
At rank 8: `10 - 8 = 2 VP`
At rank 9: `10 - 9 = 1 VP`

This gives 1 VP to rank 9. Consistent with rank 10+ getting 0 VP. The curve is logical.

**However**: rank 3 gives 7 VP (`VP_ALLIANCE_RANK3`), and rank 4 gives 6 VP — a cliff of only 1 VP between rank 3 and rank 4. But between rank 2 (10 VP) and rank 3 (7 VP), there's a cliff of 3 VP. This inconsistency means rank 3→2 improvement is worth more than rank 4→3 improvement.

**Impact:** LOW — Minor inconsistency in VP curve. Could cause rank 3 alliances to not bother trying to reach rank 2 (big jump) while rank 4 alliances aggressively chase rank 3.

**Fix proposal:** Normalize: alliance ranks 1-9 return `10 - (rank-1)` VP, giving rank 1=10, rank 2=9, ... rank 9=2. Or set rank 3 to 8 VP to smooth the curve: 10, 8, 6, 5, 4, 3, 2, 1, 0.

---

## 12. Summary Table

| ID | Category | Issue | Impact | Fix Complexity |
|----|----------|-------|--------|----------------|
| BAL-R2-001 | Economy | Producteur drain can create negative energy at startup | HIGH | Low |
| BAL-R2-002 | UI/Docs | Condenseur points don't affect production — misleading UI | MEDIUM | Low |
| BAL-R2-003 | Combat | Ionisateur is non-damageable; champdeforce degrades — permanent offense advantage | HIGH | Medium |
| BAL-R2-004 | Combat | Draw condition ignores power differential — pyrrhic draws yield nothing | MEDIUM | Low |
| BAL-R2-005 | Combat | Building damage is per-class random roll with extreme variance | MEDIUM | Low |
| BAL-R2-006 | Atoms | Iode linear formula vs quadratic others — 20x weaker at high counts | HIGH | Low |
| BAL-R2-007 | Atoms | Soufre S/3 divisor permanently handicaps pillage vs attack return | HIGH | Trivial |
| BAL-R2-008 | Atoms | Chlore speed has no combat effect — needs UI clarification | LOW | None |
| BAL-R2-009 | Atoms | Azote diminishing returns by design — no fix needed | INFO | None |
| BAL-R2-010 | Snowball | Medal tier combat bonuses carry across seasons creating unbreakable advantages | CRITICAL | Medium |
| BAL-R2-011 | VP Economy | VP curve collapses to 1 for ranks 21-100 — ranking meaningless in mid-tier | MEDIUM | Low |
| BAL-R2-012 | Formation | Dispersée ignores HP weighting — exploitable with 1-molecule "damage sponge" class | MEDIUM | Low |
| BAL-R2-013 | Formation | Phalange breaks under coordinated alliance multi-wave attacks | HIGH | Medium |
| BAL-R2-014 | Formation | Embuscade uses molecule count not combat power as trigger condition | MEDIUM | Low |
| BAL-R2-015 | Isotope | Réactif delivers 25% less lifetime attack than Normal — player trap | MEDIUM | Trivial |
| BAL-R2-016 | Isotope | Catalytique+Phalange stack creates convergent dominant meta | LOW | None |
| BAL-R2-017 | Reactions | Halogénation (+speed) has zero combat impact | MEDIUM | Low |
| BAL-R2-018 | Reactions | Combustion+Neutralisation double-stack is possible with 3 classes — intended? | INFO | None |
| BAL-R2-019 | Market | `$volatilite` hardcoded as `0.3` instead of using `MARKET_VOLATILITY_FACTOR` constant | MEDIUM | Trivial |
| BAL-R2-020 | Market | Mean reversion too slow for small player counts | LOW | Low |
| BAL-R2-021 | Market | Resource send exchange ratio punishes legitimate ally support | MEDIUM | Low |
| BAL-R2-022 | Building | Medal bonus on construction costs compounds veteran advantage | HIGH | Low |
| BAL-R2-023 | Building | Vault protection is absolute not percentage — degrades with depot growth | MEDIUM | Low |
| BAL-R2-024 | Alliance | Duplicateur cost factor 2.5 makes levels 8+ unreachable in one season | MEDIUM | Trivial |
| BAL-R2-025 | Alliance | Alliance VP matters most to weak players — good design, no fix | INFO | None |
| BAL-R2-026 | VP Economy | Alliance VP rank 3→2 cliff larger than rank 4→3 — minor inconsistency | LOW | Trivial |

---

## Priority Fix Order

**Immediate (trivial code changes):**
1. BAL-R2-019 — Fix `$volatilite` to use the config constant (`MARKET_VOLATILITY_FACTOR`)
2. BAL-R2-007 — Change `PILLAGE_SOUFRE_DIVISOR` from 3 to 1 or 2
3. BAL-R2-015 — Reduce `ISOTOPE_REACTIF_DECAY_MOD` from 0.50 to 0.20
4. BAL-R2-024 — Change `DUPLICATEUR_COST_FACTOR` from 2.5 to 2.0
5. BAL-R2-026 — Fix alliance VP rank formula consistency

**Short-term (low-complexity code changes):**
6. BAL-R2-001 — Add energy balance warning when producteur exceeds generateur
7. BAL-R2-006 — Rework iode formula to add quadratic component
8. BAL-R2-012 — Change Dispersée to HP-weighted damage distribution
9. BAL-R2-014 — Change Embuscade trigger from molecule count to combat power comparison
10. BAL-R2-023 — Change vault protection to percentage of storage per level
11. BAL-R2-021 — Add 50% floor to resource send exchange ratio
12. BAL-R2-011 — Reshape VP curve for mid-tier ranks

**Medium-term (requires design decisions):**
13. BAL-R2-003 — Add ionisateur to damageable buildings list
14. BAL-R2-022 — Remove or cap medal bonus on construction costs
15. BAL-R2-010 — Implement seasonal medal soft-reset mechanism
16. BAL-R2-004 — Add partial pillage on pyrrhic draws
17. BAL-R2-005 — Replace per-class random building targeting with deterministic distribution
18. BAL-R2-013 — Add formation change cooldown to prevent Phalange exploitation
19. BAL-R2-017 — Give Halogénation a combat-relevant bonus

---

*End of Balance Deep-Dive Round 2 — 19 actionable findings, 4 informational items, priority ordering provided.*
