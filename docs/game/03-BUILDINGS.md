# Buildings Reference

> Source files:
> - `includes/config.php` (lines 126--220): `$BUILDING_CONFIG` array
> - `includes/config.php` (lines 112--119): HP constants
> - `includes/config.php` (lines 47--57): energy/storage/drain constants
> - `includes/formulas.php` (lines 206--226): `pointsDeVie()`, `vieChampDeForce()`, `bonusLieur()`, `placeDepot()`
> - `includes/player.php` (lines 104--427): `initPlayer()` -- builds `$listeConstructions`
> - `includes/player.php` (lines 429--459): `augmenterBatiment()` -- level-up logic
> - `includes/constantesBase.php` (line 25): `$bonusMedailles` array
> - `includes/constantesBase.php` (line 36): `$paliersConstructeur` thresholds

---

## Table of Contents

1. [Overview](#overview)
2. [General Formulas](#general-formulas)
3. [Building Details](#building-details)
   - [1. Generateur (Energy Generator)](#1-generateur-energy-generator)
   - [2. Producteur (Atom Producer)](#2-producteur-atom-producer)
   - [3. Depot (Storage)](#3-depot-storage)
   - [4. Champ de Force (Force Field)](#4-champ-de-force-force-field)
   - [5. Ionisateur (Attack Booster)](#5-ionisateur-attack-booster)
   - [6. Condenseur (Molecule Enhancement)](#6-condenseur-molecule-enhancement)
   - [7. Lieur (Formation Speed)](#7-lieur-formation-speed)
   - [8. Stabilisateur (Decay Reduction)](#8-stabilisateur-decay-reduction)
4. [Construction Queue Rules](#construction-queue-rules)
5. [Cost Comparison Table](#cost-comparison-table)
6. [Build Time Comparison Table](#build-time-comparison-table)
7. [HP Comparison Table](#hp-comparison-table)
8. [Points Comparison Table](#points-comparison-table)

---

## Overview

There are **8 buildings** in TVLW. Each can be upgraded indefinitely. Buildings fall into
two categories:

- **Damageable** (4): Generateur, Producteur, Depot, Champ de Force -- these have HP
  and can be damaged during attacks via Hydrogene-based destruction.
  See `NUM_DAMAGEABLE_BUILDINGS = 4` in `includes/config.php` line 237.
- **Non-damageable** (4): Ionisateur, Condenseur, Lieur, Stabilisateur -- these cannot
  be targeted in combat and have no HP.

The 8 buildings are enumerated in `batMax()` at `includes/player.php` line 607:

```php
$liste = ['generateur', 'producteur', 'champdeforce', 'ionisateur',
          'depot', 'stabilisateur', 'condenseur', 'lieur'];
```

---

## General Formulas

### Resource Cost

```
cost = round((1 - medalBonus / 100) * costBase * pow(level, costExponent))
```

Where `medalBonus` comes from the **Constructeur** medal track. The Constructeur medal
is earned by reaching high building levels (`$paliersConstructeur`). The medal grants a
percentage discount on all building costs.

- `$paliersConstructeur = [5, 10, 15, 25, 35, 50, 70, 100]`
  (`includes/constantesBase.php` line 36)
- `$bonusMedailles = [1, 3, 6, 10, 15, 20, 30, 50]`
  (`includes/constantesBase.php` line 25)

At max medal (Diamant Rouge, highest building >= level 100), the cost discount is 50%.

### Build Time

```
time = round(timeBase * pow(level [+ offset], timeExponent))
```

Some buildings have a `time_level_offset` that shifts the level input (e.g.,
`pow(level + 2, exp)` instead of `pow(level, exp)`). Two buildings (Generateur and
Producteur) have a special case: level 1 construction takes only 10 seconds
(`time_level1 = 10`).

### Points Awarded

```
points = pointsBase + floor(level * pointsLevelFactor)
```

Points are construction points added to the player's score upon completion.

### Building HP (Damageable Buildings Only)

Standard buildings (Generateur, Producteur, Depot):
```
HP = round(BUILDING_HP_BASE * (pow(BUILDING_HP_GROWTH_BASE, level) + pow(level, BUILDING_HP_LEVEL_EXP)))
   = round(20 * (pow(1.2, level) + pow(level, 1.2)))
```
Source: `includes/formulas.php` lines 206--210, constants at `includes/config.php` lines 112--114.

Champ de Force:
```
HP = round(FORCEFIELD_HP_BASE * (pow(FORCEFIELD_HP_GROWTH_BASE, level) + pow(level, FORCEFIELD_HP_LEVEL_EXP)))
   = round(50 * (pow(1.2, level) + pow(level, 1.2)))
```
Source: `includes/formulas.php` lines 212--215, constants at `includes/config.php` lines 117--119.

Upon construction completion, HP is restored to maximum (`includes/player.php` lines 445--452).

---

## Building Details

---

### 1. Generateur (Energy Generator)

**Purpose:** Produces energy, the primary resource used for all activities.

**Config key:** `$BUILDING_CONFIG['generateur']`
(`includes/config.php` lines 127--138)

**Production:**
```
energyPerHour = BASE_ENERGY_PER_LEVEL * level = 75 * level
```
Source: `includes/config.php` line 48, `includes/game_resources.php` line 47.

This is the base production before medal bonuses, Duplicateur bonuses, and Iode molecule
contributions. The Producteur building drains energy from this output.

**Energy Cost:**
```
round((1 - medalBonus/100) * 50 * pow(level, 0.7))
```
- `cost_energy_base = 50`, `cost_energy_exp = 0.7`

**Atom Cost (per atom type):**
```
round((1 - medalBonus/100) * 75 * pow(level, 0.7))
```
- `cost_atoms_base = 75`, `cost_atoms_exp = 0.7`

**Build Time:**
```
Level 1:  10 seconds (special case, time_level1 = 10)
Level 2+: round(60 * pow(level, 1.5))
```
- `time_base = 60`, `time_exp = 1.5`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** Yes (damageable). Uses the standard building HP formula:
```
round(20 * (pow(1.2, level) + pow(level, 1.2)))
```

**Display name in game:** "Generateur" (with accents in-game)
(`includes/player.php` line 290)

---

### 2. Producteur (Atom Producer)

**Purpose:** Produces atoms (the 8 element types) by consuming energy.

**Config key:** `$BUILDING_CONFIG['producteur']`
(`includes/config.php` lines 139--151)

**Production:**
Each level grants **8 distributable points** (`points_per_level = 8`, matching
`sizeof($nomsRes)`). The player allocates these points across the 8 atom types to
control which atoms are produced faster.

See `includes/player.php` line 161:
```php
$points = ['condenseur' => $BUILDING_CONFIG['condenseur']['points_per_level'],
           'producteur' => sizeof($nomsRes)];
```

**Energy Drain:**
```
drainageProducteur(level) = round(PRODUCTEUR_DRAIN_PER_LEVEL * level) = round(8 * level)
```
Source: `includes/formulas.php` lines 75--78, `includes/config.php` line 57.

**Energy Cost:**
```
round((1 - medalBonus/100) * 75 * pow(level, 0.7))
```
- `cost_energy_base = 75`, `cost_energy_exp = 0.7`

**Atom Cost (per atom type):**
```
round((1 - medalBonus/100) * 50 * pow(level, 0.7))
```
- `cost_atoms_base = 50`, `cost_atoms_exp = 0.7`

**Build Time:**
```
Level 1:  10 seconds (special case, time_level1 = 10)
Level 2+: round(40 * pow(level, 1.5))
```
- `time_base = 40`, `time_exp = 1.5`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** Yes (damageable). Uses the standard building HP formula:
```
round(20 * (pow(1.2, level) + pow(level, 1.2)))
```

**Display name in game:** "Producteur"
(`includes/player.php` line 308: `'titre' => 'Producteur'`)

---

### 3. Depot (Storage)

**Purpose:** Determines the maximum amount of each resource (energy and atoms) a player
can store.

**Config key:** `$BUILDING_CONFIG['depot']`
(`includes/config.php` lines 152--162)

**Capacity:**
```
placeDepot(level) = 500 * level
```
Source: `includes/formulas.php` lines 223--226, `includes/config.php` line 54 (`BASE_STORAGE_PER_LEVEL = 500`).

**Energy Cost:**
```
round((1 - medalBonus/100) * 100 * pow(level, 0.7))
```
- `cost_energy_base = 100`, `cost_energy_exp = 0.7`

**Atom Cost:** None (`cost_atoms_base = 0`).

**Build Time:**
```
round(80 * pow(level, 1.5))
```
- `time_base = 80`, `time_exp = 1.5`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** Yes (damageable). Uses the standard building HP formula:
```
round(20 * (pow(1.2, level) + pow(level, 1.2)))
```

**Display name in game:** "Stockage"
(`includes/player.php` line 329: `'titre' => 'Stockage'`)

---

### 4. Champ de Force (Force Field)

**Purpose:** Provides a defensive bonus to all defending molecules and absorbs incoming
building damage first if it is the highest-level building.

**Config key:** `$BUILDING_CONFIG['champdeforce']`
(`includes/config.php` lines 163--173)

**Effect:**
```
defenseBonus = level * 2  (percent)
```
- `bonus_per_level = 2` (+2% defense per level)
- See also `CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL = 2` at `includes/config.php` line 230.

In combat, the bonus is computed as `level * 2 / 100` and applied as a multiplier.
The Champ de Force also acts as a **damage sponge**: if its level is the highest among
all damageable buildings, incoming building destruction damage targets it first.

**Carbone Cost:**
```
round((1 - medalBonus/100) * 100 * pow(level, 0.7))
```
- `cost_carbone_base = 100`, `cost_carbone_exp = 0.7`

**Build Time:**
```
round(20 * pow(level + 2, 1.7))
```
- `time_base = 20`, `time_exp = 1.7`, `time_level_offset = 2`

**Points Awarded:**
```
1 + floor(level * 0.075)
```
- `points_base = 1`, `points_level_factor = 0.075`

**HP:** Yes (damageable). Uses the **Force Field HP formula** (2.5x standard):
```
round(50 * (pow(1.2, level) + pow(level, 1.2)))
```

**Display name in game:** "Champ de force"
(`includes/player.php` line 347: `'titre' => 'Champ de force'`)

---

### 5. Ionisateur (Attack Booster)

**Purpose:** Provides an attack bonus to all outgoing molecule attacks.

**Config key:** `$BUILDING_CONFIG['ionisateur']`
(`includes/config.php` lines 174--184)

**Effect:**
```
attackBonus = level * 2  (percent)
```
- `bonus_per_level = 2` (+2% attack per level)
- See also `IONISATEUR_COMBAT_BONUS_PER_LEVEL = 2` at `includes/config.php` line 229.

In combat, the bonus is computed as `level * 2 / 100` and applied as a multiplier to
outgoing attack damage.

**Oxygene Cost:**
```
round((1 - medalBonus/100) * 100 * pow(level, 0.7))
```
- `cost_oxygene_base = 100`, `cost_oxygene_exp = 0.7`

**Build Time:**
```
round(20 * pow(level + 2, 1.7))
```
- `time_base = 20`, `time_exp = 1.7`, `time_level_offset = 2`

**Points Awarded:**
```
1 + floor(level * 0.075)
```
- `points_base = 1`, `points_level_factor = 0.075`

**HP:** No. The Ionisateur is not damageable (`progressBar = false`).

**Display name in game:** "Ionisateur"
(`includes/player.php` line 364: `'titre' => 'Ionisateur'`)

---

### 6. Condenseur (Molecule Enhancement)

**Purpose:** Grants distributable points that increase atom "niveaux" (levels),
strengthening the stats of molecules using those atom types.

**Config key:** `$BUILDING_CONFIG['condenseur']`
(`includes/config.php` lines 185--197)

**Effect:**
Each level grants **5 distributable points** (`points_per_level = 5`). The player
allocates these across the 8 atom types. Higher atom niveaux improve molecule stats
(attack, defense, HP, etc.) via the `(1 + niveau / 50)` multiplier in molecule stat
formulas.

**Energy Cost:**
```
round((1 - medalBonus/100) * 25 * pow(level, 0.8))
```
- `cost_energy_base = 25`, `cost_energy_exp = 0.8`

**Atom Cost (per atom type):**
```
round((1 - medalBonus/100) * 100 * pow(level, 0.8))
```
- `cost_atoms_base = 100`, `cost_atoms_exp = 0.8`

**Build Time:**
```
round(120 * pow(level + 1, 1.6))
```
- `time_base = 120`, `time_exp = 1.6`, `time_level_offset = 1`

Note: The exponent was reduced from 1.8 to 1.6 for faster military progression
(see comment at `includes/config.php` line 191).

**Points Awarded:**
```
2 + floor(level * 0.1)
```
- `points_base = 2`, `points_level_factor = 0.1`

**HP:** No. The Condenseur is not damageable (`progressBar = false`).

**Display name in game:** "Condenseur"
(`includes/player.php` line 379: `'titre' => 'Condenseur'`)

---

### 7. Lieur (Formation Speed)

**Purpose:** Reduces the time required to form (train) molecules.

**Config key:** `$BUILDING_CONFIG['lieur']`
(`includes/config.php` lines 198--208)

**Effect:**
```
bonusLieur(level) = floor(100 * pow(1.07, level)) / 100
```
Source: `includes/formulas.php` lines 154--157.

This returns a speed multiplier (e.g., 1.07 at level 1, 1.40 at level 5, 1.96 at
level 10). The formation time formula divides by this value, so higher levels mean
faster molecule creation. The UI displays the bonus as a percentage reduction:
`-floor((bonusLieur(level) - 1) * 100)%`.

- `lieur_growth_base = 1.07` (7% compounding per level)

**Azote Cost:**
```
round((1 - medalBonus/100) * 100 * pow(level, 0.8))
```
- `cost_azote_base = 100`, `cost_azote_exp = 0.8`

**Build Time:**
```
round(100 * pow(level + 1, 1.5))
```
- `time_base = 100`, `time_exp = 1.5`, `time_level_offset = 1`

Note: The exponent was reduced from 1.7 to 1.5 for faster military progression
(see comment at `includes/config.php` line 202).

**Points Awarded:**
```
2 + floor(level * 0.1)
```
- `points_base = 2`, `points_level_factor = 0.1`

**HP:** No. The Lieur is not damageable (`progressBar = false`).

**Display name in game:** "Lieur"
(`includes/player.php` line 397: `'titre' => 'Lieur'`)

**Lieur bonus at selected levels:**

| Level | Multiplier | Speed Boost |
|------:|-----------:|------------:|
|     1 |       1.07 |         +7% |
|     5 |       1.40 |        +40% |
|    10 |       1.96 |        +96% |
|    20 |       3.86 |       +386% |
|    50 |      29.45 |      +2945% |

---

### 8. Stabilisateur (Decay Reduction)

**Purpose:** Reduces the rate at which molecules decay (disappear) over time.

**Config key:** `$BUILDING_CONFIG['stabilisateur']`
(`includes/config.php` lines 209--219)

**Effect:**
Each level reduces molecule decay by **0.5%**:
```
decayReduction = level * 0.5  (percent)
```
- `stability_per_level = 0.5` in `$BUILDING_CONFIG`

The decay coefficient formula (in `includes/formulas.php` lines 167--198) applies
this as:
```
coefDisparition = pow(
    pow(0.99, pow(1 + nbAtomes/100, 2) / 25000),
    (1 - medalBonus/100) * (1 - stabilisateur * STABILISATEUR_BONUS_PER_LEVEL)
)
```
Where `STABILISATEUR_BONUS_PER_LEVEL = 0.01` (defined at `includes/config.php` line 106).

Note: The config has both `stability_per_level = 0.5` (display) and the constant
`STABILISATEUR_BONUS_PER_LEVEL = 0.01` (formula). The display shows `level * 0.5`%
while the formula uses `level * 0.01` as a factor in the exponent. These are different
representations of the same effect.

**Atom Cost (per atom type):**
```
round((1 - medalBonus/100) * 75 * pow(level, 0.9))
```
- `cost_atoms_base = 75`, `cost_atoms_exp = 0.9`

**Build Time:**
```
round(120 * pow(level + 1, 1.5))
```
- `time_base = 120`, `time_exp = 1.5`, `time_level_offset = 1`

Note: The exponent was reduced from 1.7 to 1.5 for faster military progression
(see comment at `includes/config.php` line 213).

**Points Awarded:**
```
3 + floor(level * 0.1)
```
- `points_base = 3`, `points_level_factor = 0.1`

**HP:** No. The Stabilisateur is not damageable (`progressBar = false`).

**Display name in game:** "Stabilisateur"
(`includes/player.php` line 413: `'titre' => 'Stabilisateur'`)

---

## Construction Queue Rules

- **Max concurrent constructions:** 2 (`MAX_CONCURRENT_CONSTRUCTIONS = 2`,
  `includes/config.php` line 22).
- Queued constructions are stored in the `actionsconstruction` database table.
- The construction level for cost/time purposes uses the **next level** (current
  highest queued or current building level + 1). See `includes/player.php` lines 224--286
  where the highest queued level is fetched per building.

### Medal Bonuses Affecting Construction

Two medal tracks reduce building costs:

1. **Constructeur** (`$paliersConstructeur`): Based on your highest building level
   (`batMax`). Reduces all building costs by the medal percentage.
   - Thresholds: `[5, 10, 15, 25, 35, 50, 70, 100]`
   - Bonuses: `[1%, 3%, 6%, 10%, 15%, 20%, 30%, 50%]`

2. **Energievore** (`$paliersEnergievore`): Based on total energy spent. Increases
   energy production (not directly a construction discount, but affects the economy).
   - Thresholds: `[100, 500, 3000, 20000, 100000, 2000000, 10000000, 1000000000]`
   - Bonuses: `[1%, 3%, 6%, 10%, 15%, 20%, 30%, 50%]`

### On Construction Completion

When a building finishes construction (`augmenterBatiment()` at `includes/player.php`
lines 429--459):

1. Building level is incremented by 1.
2. For damageable buildings (generateur, champdeforce, producteur, depot), HP is
   restored to the new maximum.
3. Construction points are awarded via `ajouterPoints()`.
4. For Producteur: distributable atom production points are added
   (`pointsProducteurRestants += 8`).
5. For Condenseur: distributable atom level points are added
   (`pointsCondenseurRestants += 5`).

---

## Cost Comparison Table

All costs shown **without** medal bonus (medalBonus = 0). Actual costs may be up to 50%
lower with max Constructeur medal. Values are computed from the formulas below; verify
edge cases by running the PHP formulas directly.

### Energy Costs

| Level | Generateur | Producteur | Depot | Condenseur |
|------:|-----------:|-----------:|------:|-----------:|
|     1 |         50 |         75 |   100 |         25 |
|     5 |        154 |        231 |   308 |         91 |
|    10 |        251 |        376 |   501 |        158 |
|    20 |        407 |        611 |   814 |        274 |
|    50 |        773 |       1160 |  1546 |        560 |

Formula reference:
- Generateur: `round(50 * pow(L, 0.7))`
- Producteur: `round(75 * pow(L, 0.7))`
- Depot: `round(100 * pow(L, 0.7))`
- Condenseur: `round(25 * pow(L, 0.8))`

### Atom Costs

| Level | Generateur (each) | Producteur (each) | Condenseur (each) | Stabilisateur (each) |
|------:|-------------------:|-------------------:|-------------------:|---------------------:|
|     1 |                 75 |                 50 |                100 |                   75 |
|     5 |                231 |                154 |                362 |                319 |
|    10 |                376 |                251 |                631 |                596 |
|    20 |                611 |                407 |               1099 |               1111 |
|    50 |               1160 |                773 |               2239 |               2536 |

Formula reference:
- Generateur: `round(75 * pow(L, 0.7))` -- cost per atom type
- Producteur: `round(50 * pow(L, 0.7))` -- cost per atom type
- Condenseur: `round(100 * pow(L, 0.8))` -- cost per atom type
- Stabilisateur: `round(75 * pow(L, 0.9))` -- cost per atom type

### Single-Resource Costs

| Level | Champ de Force (Carbone) | Ionisateur (Oxygene) | Lieur (Azote) |
|------:|-------------------------:|---------------------:|--------------:|
|     1 |                      100 |                  100 |           100 |
|     5 |                      308 |                  308 |           362 |
|    10 |                      501 |                  501 |           631 |
|    20 |                      814 |                  814 |          1099 |
|    50 |                     1546 |                 1546 |          2239 |

Formula reference:
- Champ de Force: `round(100 * pow(L, 0.7))` in Carbone
- Ionisateur: `round(100 * pow(L, 0.7))` in Oxygene
- Lieur: `round(100 * pow(L, 0.8))` in Azote

---

## Build Time Comparison Table

All times shown in seconds, with human-readable equivalents.

| Level | Generateur | Producteur | Depot | Champ de Force | Ionisateur | Condenseur | Lieur | Stabilisateur |
|------:|-----------:|-----------:|------:|---------------:|-----------:|-----------:|------:|--------------:|
|     1 |        10s |        10s |   80s |          129s  |      129s  |      364s  |  283s |          339s |
|     5 |       671s |       447s |  894s |          547s  |      547s  |     2109s  | 1470s |         1764s |
|    10 |      1897s |      1265s | 2530s |         1367s  |     1367s  |     5564s  | 3648s |         4378s |
|    20 |      5367s |      3578s | 7155s |         3829s  |     3829s  |    15659s  | 9623s |        11548s |
|    50 |     21213s |     14142s |28284s |        16528s  |    16528s  |    64760s  |36421s |        43706s |

Human-readable equivalents:

| Level | Generateur | Producteur | Depot  | Champ de Force | Ionisateur | Condenseur | Lieur   | Stabilisateur |
|------:|-----------:|-----------:|-------:|---------------:|-----------:|-----------:|--------:|--------------:|
|     1 |       10s  |       10s  |  1.3m  |          2.2m  |      2.2m  |      6.1m  |   4.7m  |         5.7m  |
|     5 |     11.2m  |      7.5m  | 14.9m  |          9.1m  |      9.1m  |     35.2m  |  24.5m  |        29.4m  |
|    10 |     31.6m  |     21.1m  | 42.2m  |         22.8m  |     22.8m  |      1.5h  |   1.0h  |         1.2h  |
|    20 |      1.5h  |      1.0h  |  2.0h  |          1.1h  |      1.1h  |      4.3h  |   2.7h  |         3.2h  |
|    50 |      5.9h  |      3.9h  |  7.9h  |          4.6h  |      4.6h  |     18.0h  |  10.1h  |        12.1h  |

Formulas used:
- Generateur: `L1=10`, else `round(60 * pow(L, 1.5))`
- Producteur: `L1=10`, else `round(40 * pow(L, 1.5))`
- Depot: `round(80 * pow(L, 1.5))`
- Champ de Force: `round(20 * pow(L+2, 1.7))`
- Ionisateur: `round(20 * pow(L+2, 1.7))`
- Condenseur: `round(120 * pow(L+1, 1.6))`
- Lieur: `round(100 * pow(L+1, 1.5))`
- Stabilisateur: `round(120 * pow(L+1, 1.5))`

---

## HP Comparison Table

Only damageable buildings are shown. Non-damageable buildings (Ionisateur, Condenseur,
Lieur, Stabilisateur) have no HP. High-level values are approximate; run the PHP
formulas for exact results.

| Level | Generateur | Producteur | Depot  | Champ de Force |
|------:|-----------:|-----------:|-------:|---------------:|
|     1 |         44 |         44 |     44 |            110 |
|     5 |        188 |        188 |    188 |            469 |
|    10 |        441 |        441 |    441 |           1102 |
|    20 |       1495 |       1495 |   1495 |           3737 |
|    50 |    ~184200 |    ~184200 |~184200 |        ~460500 |

Formulas:
- Standard: `round(20 * (pow(1.2, L) + pow(L, 1.2)))`
- Champ de Force: `round(50 * (pow(1.2, L) + pow(L, 1.2)))` -- 2.5x standard

The exponential growth `pow(1.2, L)` dominates at higher levels, causing HP to
increase dramatically. At level 50, `pow(1.2, 50) ~= 9100`, making the building
extremely durable.

---

## Points Comparison Table

Construction points awarded upon completing each level.

| Level | Generateur | Producteur | Depot | CdF | Ionisateur | Condenseur | Lieur | Stabilisateur |
|------:|-----------:|-----------:|------:|----:|-----------:|-----------:|------:|--------------:|
|     1 |          1 |          1 |     1 |   1 |          1 |          2 |     2 |             3 |
|     5 |          1 |          1 |     1 |   1 |          1 |          2 |     2 |             3 |
|    10 |          2 |          2 |     2 |   1 |          1 |          3 |     3 |             4 |
|    20 |          3 |          3 |     3 |   2 |          2 |          4 |     4 |             5 |
|    50 |          6 |          6 |     6 |   4 |          4 |          7 |     7 |             8 |

Formula: `pointsBase + floor(level * pointsLevelFactor)`

| Building       | pointsBase | pointsLevelFactor |
|:---------------|-----------:|------------------:|
| Generateur     |          1 |               0.1 |
| Producteur     |          1 |               0.1 |
| Depot          |          1 |               0.1 |
| Champ de Force |          1 |             0.075 |
| Ionisateur     |          1 |             0.075 |
| Condenseur     |          2 |               0.1 |
| Lieur          |          2 |               0.1 |
| Stabilisateur  |          3 |               0.1 |

The Stabilisateur awards the most points per upgrade, compensating for its higher
cost exponent (0.9 vs 0.7--0.8 for other buildings).

---

## Config Reference Summary

| Building       | Config Key       | config.php Lines | Cost Type(s)            | costExp | timeBase | timeExp | offset |
|:---------------|:-----------------|:-----------------|:------------------------|--------:|---------:|--------:|-------:|
| Generateur     | `generateur`     | 127--138         | Energy + Atoms          |     0.7 |       60 |     1.5 |      0 |
| Producteur     | `producteur`     | 139--151         | Energy + Atoms          |     0.7 |       40 |     1.5 |      0 |
| Depot          | `depot`          | 152--162         | Energy only             |     0.7 |       80 |     1.5 |      0 |
| Champ de Force | `champdeforce`   | 163--173         | Carbone only            |     0.7 |       20 |     1.7 |      2 |
| Ionisateur     | `ionisateur`     | 174--184         | Oxygene only            |     0.7 |       20 |     1.7 |      2 |
| Condenseur     | `condenseur`     | 185--197         | Energy + Atoms          |     0.8 |      120 |     1.6 |      1 |
| Lieur          | `lieur`          | 198--208         | Azote only              |     0.8 |      100 |     1.5 |      1 |
| Stabilisateur  | `stabilisateur`  | 209--219         | Atoms only              |     0.9 |      120 |     1.5 |      1 |
