# Buildings Reference

> Source files:
> - `includes/config.php` (lines 151--253): `$BUILDING_CONFIG` array
> - `includes/config.php` (lines 123--134): HP constants
> - `includes/config.php` (lines 31--34): V4 economic growth bases (`ECO_GROWTH_BASE`, `ECO_GROWTH_ADV`, `ECO_GROWTH_ULT`)
> - `includes/config.php` (lines 50--60): energy/storage/drain constants
> - `includes/config.php` (lines 136--144): V4 vault/lieur/storage constants
> - `includes/formulas.php` (lines 215--249): `pointsDeVie()`, `vieChampDeForce()`, `bonusLieur()`, `placeDepot()`, `capaciteCoffreFort()`
> - `includes/player.php` (lines 100--470): `initPlayer()` -- builds `$listeConstructions`
> - `includes/player.php` (lines 500--533): `augmenterBatiment()` -- level-up logic
> - `includes/constantesBase.php` (line 25): `$bonusMedailles` array
> - `includes/constantesBase.php` (line 36): `$paliersConstructeur` thresholds

---

## Table of Contents

1. [Overview](#overview)
2. [General Formulas (V4)](#general-formulas-v4)
3. [Building Details](#building-details)
   - [1. Generateur (Energy Generator)](#1-generateur-energy-generator)
   - [2. Producteur (Atom Producer)](#2-producteur-atom-producer)
   - [3. Depot (Storage)](#3-depot-storage)
   - [4. Champ de Force (Force Field)](#4-champ-de-force-force-field)
   - [5. Ionisateur (Attack Booster)](#5-ionisateur-attack-booster)
   - [6. Condenseur (Molecule Enhancement)](#6-condenseur-molecule-enhancement)
   - [7. Lieur (Formation Speed)](#7-lieur-formation-speed)
   - [8. Stabilisateur (Decay Reduction)](#8-stabilisateur-decay-reduction)
   - [9. Coffre-fort (Vault)](#9-coffre-fort-vault)
4. [Construction Queue Rules](#construction-queue-rules)
5. [Cost Comparison Table](#cost-comparison-table)
6. [Build Time Comparison Table](#build-time-comparison-table)
7. [HP Comparison Table](#hp-comparison-table)
8. [Storage and Vault Table](#storage-and-vault-table)
9. [Points Comparison Table](#points-comparison-table)

---

## Overview

There are **9 buildings** in TVLW. Each can be upgraded indefinitely. Buildings fall into
two categories:

- **Damageable** (4): Generateur, Producteur, Depot, Champ de Force -- these have HP
  and can be damaged during attacks via Hydrogene-based destruction.
  See `NUM_DAMAGEABLE_BUILDINGS = 4` in `includes/config.php` line 272.
- **Non-damageable** (5): Ionisateur, Condenseur, Lieur, Stabilisateur, Coffre-fort --
  these cannot be targeted in combat and have no HP.

The buildings are enumerated in `batMax()` at `includes/player.php`:

```php
$liste = ['generateur', 'producteur', 'champdeforce', 'ionisateur',
          'depot', 'stabilisateur', 'condenseur', 'lieur'];
```

The Coffre-fort is listed separately in `$listeConstructions` but not in `batMax()`.

---

## General Formulas (V4)

### V4 Economic Growth Bases

V4 replaces polynomial cost/time scaling with **exponential** growth, using three
growth rate tiers defined in `includes/config.php` lines 31--34:

```
ECO_GROWTH_BASE = 1.15  -- Standard buildings (generateur, producteur, depot, coffrefort)
ECO_GROWTH_ADV  = 1.20  -- Strategic buildings (champdeforce, ionisateur, condenseur, lieur)
ECO_GROWTH_ULT  = 1.25  -- Stabilisateur (strongest exponential)
```

### Resource Cost (V4: Exponential)

```
cost = round((1 - medalBonus / 100) * costBase * pow(cost_growth_base, level))
```

Where `level` = current building level (`niveauActuel`). The cost displayed is the
price to upgrade from `level` to `level + 1`.

**V3 (old):** `cost = round((1 - medalBonus/100) * costBase * pow(level, costExponent))`
-- polynomial, grew as a power of level.

**V4 (new):** Each building has a `cost_growth_base` (1.15, 1.20, or 1.25) instead of
a `cost_energy_exp` / `cost_atoms_exp`. Costs grow exponentially with level, making
high-level upgrades progressively more expensive and discouraging runaway building levels.

Where `medalBonus` comes from the **Constructeur** medal track. The Constructeur medal
is earned by reaching high building levels (`$paliersConstructeur`). The medal grants a
percentage discount on all building costs.

- `$paliersConstructeur = [5, 10, 15, 25, 35, 50, 70, 100]`
  (`includes/constantesBase.php` line 36)
- `$bonusMedailles = [1, 3, 6, 10, 15, 20, 30, 50]`
  (`includes/constantesBase.php` line 25)

At max medal (Diamant Rouge, highest building >= level 100), the cost discount is 50%.

### Build Time (V4: Exponential)

```
time = round(timeBase * pow(time_growth_base, level + time_level_offset))
```

Where `level` = current building level (`niveauActuel`). The time displayed is the
duration to build from `level` to `level + 1`.

**V3 (old):** `time = round(timeBase * pow(level [+ offset], timeExponent))` -- polynomial.

**V4 (new):** All buildings use `time_growth_base = 1.10`. Two buildings (Generateur and
Producteur) have a special case: when the current level is 0 (building to level 1),
construction takes only 10 seconds (`time_level1 = 10`). The level 1 check is
`$niveauActuel['niveau'] == 0`.

### Points Awarded

```
points = pointsBase + floor(level * pointsLevelFactor)
```

Points are construction points added to the player's score upon completion. This formula
is unchanged from V3.

### Building HP (V4: Polynomial, was Exponential+Polynomial)

**V3 (old):**
```
HP = round(BASE * (pow(1.2, level) + pow(level, 1.2)))
```
The exponential `pow(1.2, L)` term dominated at high levels, causing HP to skyrocket
(e.g., ~184,200 at level 50).

**V4 (new) -- Standard buildings** (Generateur, Producteur, Depot):
```
HP = round(BUILDING_HP_BASE * pow(max(1, level), BUILDING_HP_POLY_EXP))
   = round(50 * pow(max(1, level), 2.5))
```
Source: `includes/formulas.php` lines 215--223, constants at `includes/config.php` lines 126--129.

**V4 (new) -- Champ de Force:**
```
HP = round(FORCEFIELD_HP_BASE * pow(max(1, level), BUILDING_HP_POLY_EXP))
   = round(125 * pow(max(1, level), 2.5))
```
Source: `includes/formulas.php` lines 225--233, constants at `includes/config.php` lines 132--134.

The purely polynomial formula grows much more predictably than the old exponential form.
At level 50, standard HP is ~883,883 instead of the old ~184,200 -- the polynomial
`level^2.5` still grows substantially but without the runaway exponential curve at
extreme levels.

**Alliance Fortification Research** further increases building HP:
```
effectiveHP = HP * (1 + allianceResearchBonus(joueur, 'building_hp'))
```
Where `effect_per_level = 0.01` (+1% HP per Fortification research level, max level 25).
Source: `includes/config.php` line 398, `includes/formulas.php` lines 218--221.

Upon construction completion, HP is restored to maximum (`includes/player.php` lines 517--524).

---

## Building Details

---

### 1. Generateur (Energy Generator)

**Purpose:** Produces energy, the primary resource used for all activities.

**Config key:** `$BUILDING_CONFIG['generateur']`
(`includes/config.php` lines 152--162)

**Production:**
```
energyPerHour = BASE_ENERGY_PER_LEVEL * level = 75 * level
```
Source: `includes/config.php` line 51, `includes/game_resources.php`.

This is the base production before medal bonuses, Duplicateur bonuses, and Iode molecule
contributions. The Producteur building drains energy from this output.

**Energy Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 50 * pow(1.15, level))
```
- `cost_energy_base = 50`, `cost_growth_base = ECO_GROWTH_BASE = 1.15`

**Atom Cost (per atom type, V4: exponential):**
```
round((1 - medalBonus/100) * 75 * pow(1.15, level))
```
- `cost_atoms_base = 75`, `cost_growth_base = 1.15`

**Build Time (V4: exponential):**
```
Level 0 (building to 1):  10 seconds (special case, time_level1 = 10)
Level 1+: round(60 * pow(1.10, level))
```
- `time_base = 60`, `time_growth_base = 1.10`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** Yes (damageable). Uses the V4 polynomial HP formula:
```
round(50 * pow(max(1, level), 2.5))
```

**Display name in game:** "Generateur"
(`includes/player.php`: `'titre' => 'Generateur'`)

---

### 2. Producteur (Atom Producer)

**Purpose:** Produces atoms (the 8 element types) by consuming energy.

**Config key:** `$BUILDING_CONFIG['producteur']`
(`includes/config.php` lines 163--174)

**Production:**
Each level grants **8 distributable points** (`points_per_level = 8`, matching
`sizeof($nomsRes)`). The player allocates these points across the 8 atom types to
control which atoms are produced faster.

**Energy Drain (V4: exponential):**
```
drainageProducteur(level) = round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, level))
                          = round(8 * pow(1.15, level))
```
Source: `includes/formulas.php` lines 79--82, `includes/config.php` lines 59--60.

**V3 (old):** `round(8 * level)` -- linear drain.
**V4 (new):** Exponential drain scales with the same growth base as costs.

| Level |  V3 Drain |  V4 Drain |
|------:|----------:|----------:|
|     1 |         8 |         9 |
|     5 |        40 |        16 |
|    10 |        80 |        32 |
|    20 |       160 |       131 |
|    50 |       400 |      8669 |

**Energy Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 75 * pow(1.15, level))
```
- `cost_energy_base = 75`, `cost_growth_base = 1.15`

**Atom Cost (per atom type, V4: exponential):**
```
round((1 - medalBonus/100) * 50 * pow(1.15, level))
```
- `cost_atoms_base = 50`, `cost_growth_base = 1.15`

**Build Time (V4: exponential):**
```
Level 0 (building to 1):  10 seconds (special case, time_level1 = 10)
Level 1+: round(40 * pow(1.10, level))
```
- `time_base = 40`, `time_growth_base = 1.10`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** Yes (damageable). Uses the V4 polynomial HP formula:
```
round(50 * pow(max(1, level), 2.5))
```

**Display name in game:** "Producteur"
(`includes/player.php`: `'titre' => 'Producteur'`)

---

### 3. Depot (Storage)

**Purpose:** Determines the maximum amount of each resource (energy and atoms) a player
can store.

**Config key:** `$BUILDING_CONFIG['depot']`
(`includes/config.php` lines 175--184)

**Capacity (V4: exponential):**
```
placeDepot(level) = round(1000 * pow(1.15, level))
```
Source: `includes/formulas.php` lines 240--243, `includes/config.php` lines 57, 137.

**V3 (old):** `placeDepot(level) = 500 * level` -- linear. Level 10 = 5000.
**V4 (new):** Exponential with base 1000. Level 10 = 4046. The exponential curve
starts lower but overtakes the linear formula at higher levels.

| Level | V3 Storage | V4 Storage |
|------:|-----------:|-----------:|
|     1 |        500 |       1150 |
|     5 |       2500 |       2011 |
|    10 |       5000 |       4046 |
|    20 |      10000 |      16367 |
|    50 |      25000 |    1083657 |

**Energy Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 100 * pow(1.15, level))
```
- `cost_energy_base = 100`, `cost_growth_base = 1.15`

**Atom Cost:** None (`cost_atoms_base = 0`).

**Build Time (V4: exponential):**
```
round(80 * pow(1.10, level))
```
- `time_base = 80`, `time_growth_base = 1.10`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** Yes (damageable). Uses the V4 polynomial HP formula:
```
round(50 * pow(max(1, level), 2.5))
```

**Display name in game:** "Stockage"
(`includes/player.php`: `'titre' => 'Stockage'`)

---

### 4. Champ de Force (Force Field)

**Purpose:** Provides a defensive bonus to all defending molecules and absorbs incoming
building damage first if it is the highest-level building.

**Config key:** `$BUILDING_CONFIG['champdeforce']`
(`includes/config.php` lines 185--195)

**Effect:**
```
defenseBonus = level * 2  (percent)
```
- `bonus_per_level = 2` (+2% defense per level)
- See also `CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL = 2` at `includes/config.php` line 265.

In combat, the bonus is computed as `level * 2 / 100` and applied as a multiplier.
The Champ de Force also acts as a **damage sponge**: if its level is the highest among
all damageable buildings, incoming building destruction damage targets it first.

**Carbone Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 100 * pow(1.20, level))
```
- `cost_carbone_base = 100`, `cost_growth_base = ECO_GROWTH_ADV = 1.20`

**Build Time (V4: exponential):**
```
round(20 * pow(1.10, level + 2))
```
- `time_base = 20`, `time_growth_base = 1.10`, `time_level_offset = 2`

**Points Awarded:**
```
1 + floor(level * 0.075)
```
- `points_base = 1`, `points_level_factor = 0.075`

**HP:** Yes (damageable). Uses the **V4 Force Field polynomial HP formula** (2.5x standard base):
```
round(125 * pow(max(1, level), 2.5))
```

**Display name in game:** "Champ de force"
(`includes/player.php`: `'titre' => 'Champ de force'`)

---

### 5. Ionisateur (Attack Booster)

**Purpose:** Provides an attack bonus to all outgoing molecule attacks.

**Config key:** `$BUILDING_CONFIG['ionisateur']`
(`includes/config.php` lines 196--206)

**Effect:**
```
attackBonus = level * 2  (percent)
```
- `bonus_per_level = 2` (+2% attack per level)
- See also `IONISATEUR_COMBAT_BONUS_PER_LEVEL = 2` at `includes/config.php` line 264.

In combat, the bonus is computed as `level * 2 / 100` and applied as a multiplier to
outgoing attack damage.

**Oxygene Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 100 * pow(1.20, level))
```
- `cost_oxygene_base = 100`, `cost_growth_base = ECO_GROWTH_ADV = 1.20`

**Build Time (V4: exponential):**
```
round(20 * pow(1.10, level + 2))
```
- `time_base = 20`, `time_growth_base = 1.10`, `time_level_offset = 2`

**Points Awarded:**
```
1 + floor(level * 0.075)
```
- `points_base = 1`, `points_level_factor = 0.075`

**HP:** No. The Ionisateur is not damageable (`progressBar = false`).

**Display name in game:** "Ionisateur"
(`includes/player.php`: `'titre' => 'Ionisateur'`)

---

### 6. Condenseur (Molecule Enhancement)

**Purpose:** Grants distributable points that increase atom "niveaux" (levels),
strengthening the stats of molecules using those atom types.

**Config key:** `$BUILDING_CONFIG['condenseur']`
(`includes/config.php` lines 207--218)

**Effect:**
Each level grants **5 distributable points** (`points_per_level = 5`). The player
allocates these across the 8 atom types. Higher atom niveaux improve molecule stats
(attack, defense, HP, etc.) via the `(1 + niveau / 50)` multiplier in molecule stat
formulas.

**Energy Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 25 * pow(1.20, level))
```
- `cost_energy_base = 25`, `cost_growth_base = ECO_GROWTH_ADV = 1.20`

**Atom Cost (per atom type, V4: exponential):**
```
round((1 - medalBonus/100) * 100 * pow(1.20, level))
```
- `cost_atoms_base = 100`, `cost_growth_base = 1.20`

**Build Time (V4: exponential):**
```
round(120 * pow(1.10, level + 1))
```
- `time_base = 120`, `time_growth_base = 1.10`, `time_level_offset = 1`

**Points Awarded:**
```
2 + floor(level * 0.1)
```
- `points_base = 2`, `points_level_factor = 0.1`

**HP:** No. The Condenseur is not damageable (`progressBar = false`).

**Display name in game:** "Condenseur"
(`includes/player.php`: `'titre' => 'Condenseur'`)

---

### 7. Lieur (Formation Speed)

**Purpose:** Reduces the time required to form (train) molecules.

**Config key:** `$BUILDING_CONFIG['lieur']`
(`includes/config.php` lines 219--228)

**Effect (V4: linear, was exponential):**
```
bonusLieur(level) = 1 + level * LIEUR_LINEAR_BONUS_PER_LEVEL
                  = 1 + level * 0.15
```
Source: `includes/formulas.php` lines 125--128, `includes/config.php` line 144.

**V3 (old):** `bonusLieur(level) = floor(100 * pow(1.07, level)) / 100` -- exponential
(7% compounding per level). Level 50 gave a 29.45x multiplier.

**V4 (new):** Linear growth at 15% per level. More predictable, no runaway at high
levels. Level 50 gives an 8.50x multiplier.

This returns a speed multiplier. The formation time formula divides by this value, so
higher levels mean faster molecule creation. The UI displays the bonus as a percentage
reduction: `-round((1 - 1/bonusLieur(level)) * 100)%`.

**Lieur bonus at selected levels:**

| Level | V3 Multiplier | V4 Multiplier | V4 Time Reduction |
|------:|--------------:|--------------:|------------------:|
|     1 |          1.07 |          1.15 |               13% |
|     5 |          1.40 |          1.75 |               43% |
|    10 |          1.96 |          2.50 |               60% |
|    20 |          3.86 |          4.00 |               75% |
|    50 |         29.45 |          8.50 |               88% |

**Azote Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 100 * pow(1.20, level))
```
- `cost_azote_base = 100`, `cost_growth_base = ECO_GROWTH_ADV = 1.20`

**Build Time (V4: exponential):**
```
round(100 * pow(1.10, level + 1))
```
- `time_base = 100`, `time_growth_base = 1.10`, `time_level_offset = 1`

**Points Awarded:**
```
2 + floor(level * 0.1)
```
- `points_base = 2`, `points_level_factor = 0.1`

**HP:** No. The Lieur is not damageable (`progressBar = false`).

**Display name in game:** "Lieur"
(`includes/player.php`: `'titre' => 'Lieur'`)

---

### 8. Stabilisateur (Decay Reduction)

**Purpose:** Reduces the rate at which molecules decay (disappear) over time.

**Config key:** `$BUILDING_CONFIG['stabilisateur']`
(`includes/config.php` lines 230--240)

**Effect (V4: asymptotic, was linear):**

**V3 (old):** Linear reduction at 0.5% per level (display) / 1% per level (formula).
Could theoretically reach 100% reduction at level 100.

**V4 (new):** Asymptotic reduction using `STABILISATEUR_ASYMPTOTE = 0.98`:
```
decayModifier = pow(0.98, level)
displayReduction = round((1 - pow(0.98, level)) * 100, 1)%
```
Source: `includes/formulas.php` line 180, `includes/config.php` line 119.

The decay coefficient formula applies this as:
```
modStab = pow(STABILISATEUR_ASYMPTOTE, stabilisateur_level)
baseDecay = pow(rawDecay, modStab * modMedal)
```

Because `pow(0.98, level)` approaches but never reaches 0, the decay reduction can
**never reach 100%**. Each additional level gives diminishing returns:

| Level | Decay Reduction |
|------:|----------------:|
|     1 |            2.0% |
|     5 |            9.6% |
|    10 |           18.3% |
|    20 |           33.2% |
|    50 |           63.6% |
|   100 |           86.7% |

**Atom Cost (per atom type, V4: exponential):**
```
round((1 - medalBonus/100) * 75 * pow(1.25, level))
```
- `cost_atoms_base = 75`, `cost_growth_base = ECO_GROWTH_ULT = 1.25`

The Stabilisateur uses the strongest growth base (1.25), making it the most expensive
building to level at high levels.

**Build Time (V4: exponential):**
```
round(120 * pow(1.10, level + 1))
```
- `time_base = 120`, `time_growth_base = 1.10`, `time_level_offset = 1`

**Points Awarded:**
```
3 + floor(level * 0.1)
```
- `points_base = 3`, `points_level_factor = 0.1`

**HP:** No. The Stabilisateur is not damageable (`progressBar = false`).

**Display name in game:** "Stabilisateur"
(`includes/player.php`: `'titre' => 'Stabilisateur'`)

---

### 9. Coffre-fort (Vault)

**Purpose:** Protects a percentage of stored resources from pillage.

**Config key:** `$BUILDING_CONFIG['coffrefort']`
(`includes/config.php` lines 241--253)

**Effect (V4: percentage-based):**
```
capaciteCoffreFort(coffre_level, depot_level) =
    round(min(VAULT_MAX_PROTECTION_PCT, coffre_level * VAULT_PCT_PER_LEVEL) * placeDepot(depot_level))
```
Source: `includes/formulas.php` lines 245--249, `includes/config.php` lines 138--139.

- `VAULT_PCT_PER_LEVEL = 0.02` -- each level protects 2% more of storage
- `VAULT_MAX_PROTECTION_PCT = 0.50` -- protection caps at 50%
- At level 25, the cap is reached (25 * 2% = 50%)

The vault protection scales with the Depot level since it protects a percentage of
`placeDepot(depot_level)`. Upgrading both Depot and Coffre-fort together provides
the best protection.

**Energy Cost (V4: exponential):**
```
round((1 - medalBonus/100) * 150 * pow(1.15, level))
```
- `cost_energy_base = 150`, `cost_growth_base = ECO_GROWTH_BASE = 1.15`

**Atom Cost:** None (`cost_atoms_base = 0`).

**Build Time (V4: exponential):**
```
round(90 * pow(1.10, level + 1))
```
- `time_base = 90`, `time_growth_base = 1.10`, `time_level_offset = 1`

**Points Awarded:**
```
1 + floor(level * 0.1)
```
- `points_base = 1`, `points_level_factor = 0.1`

**HP:** No. The Coffre-fort is not damageable (`progressBar = false`).

**Display name in game:** "Coffre-fort"
(`includes/player.php`: `'titre' => 'Coffre-fort'`)

---

## Construction Queue Rules

- **Max concurrent constructions:** 2 (`MAX_CONCURRENT_CONSTRUCTIONS = 2`,
  `includes/config.php` line 22).
- Queued constructions are stored in the `actionsconstruction` database table.
- The construction level for cost/time purposes uses the **next level** (current
  highest queued or current building level + 1). See `includes/player.php` lines 232--314
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
lines 500--533):

1. Building level is incremented by 1.
2. For damageable buildings (generateur, champdeforce, producteur, depot), HP is
   restored to the new maximum (including alliance Fortification research bonus).
3. Construction points are awarded via `ajouterPoints()`.
4. For Producteur: distributable atom production points are added
   (`pointsProducteurRestants += 8`).
5. For Condenseur: distributable atom level points are added
   (`pointsCondenseurRestants += 5`).

---

## Cost Comparison Table

All costs shown **without** medal bonus (medalBonus = 0). Actual costs may be up to 50%
lower with max Constructeur medal. "Level" = the target level being built. The cost
exponent uses `niveauActuel = Level - 1` (current level before building), matching the
PHP code: `cost = round(costBase * pow(growth_base, niveauActuel))`.

### Energy Costs

| Level | Generateur | Producteur | Depot | Condenseur | Coffre-fort |
|------:|-----------:|-----------:|------:|-----------:|------------:|
|     1 |         50 |         75 |   100 |         25 |         150 |
|     5 |         87 |        131 |   175 |         52 |         262 |
|    10 |        176 |        264 |   352 |        129 |         528 |
|    20 |        712 |       1067 |  1423 |        799 |        2135 |
|    50 |      47116 |      70673 | 94231 |     189592 |      141347 |

Formula reference (V4), where N = Level - 1:
- Generateur: `round(50 * pow(1.15, N))`
- Producteur: `round(75 * pow(1.15, N))`
- Depot: `round(100 * pow(1.15, N))`
- Condenseur: `round(25 * pow(1.20, N))`
- Coffre-fort: `round(150 * pow(1.15, N))`

### Atom Costs (per atom type)

| Level | Generateur | Producteur | Condenseur | Stabilisateur |
|------:|-----------:|-----------:|-----------:|--------------:|
|     1 |         75 |         50 |        100 |            75 |
|     5 |        131 |         87 |        207 |           183 |
|    10 |        264 |        176 |        516 |           559 |
|    20 |       1067 |        712 |       3195 |          5204 |
|    50 |      70673 |      47116 |     758370 |       4203895 |

Formula reference (V4), where N = Level - 1:
- Generateur: `round(75 * pow(1.15, N))` -- cost per atom type
- Producteur: `round(50 * pow(1.15, N))` -- cost per atom type
- Condenseur: `round(100 * pow(1.20, N))` -- cost per atom type
- Stabilisateur: `round(75 * pow(1.25, N))` -- cost per atom type (strongest growth)

### Single-Resource Costs

| Level | Champ de Force (Carbone) | Ionisateur (Oxygene) | Lieur (Azote) |
|------:|-------------------------:|---------------------:|--------------:|
|     1 |                      100 |                  100 |           100 |
|     5 |                      207 |                  207 |           207 |
|    10 |                      516 |                  516 |           516 |
|    20 |                     3195 |                 3195 |          3195 |
|    50 |                   758370 |               758370 |        758370 |

Formula reference (V4), where N = Level - 1:
- Champ de Force: `round(100 * pow(1.20, N))` in Carbone
- Ionisateur: `round(100 * pow(1.20, N))` in Oxygene
- Lieur: `round(100 * pow(1.20, N))` in Azote

---

## Build Time Comparison Table

All times shown in seconds, with human-readable equivalents. "Level" = the target
level being built. The time exponent uses `N = Level - 1` (niveauActuel, the current
level before building). All buildings use `time_growth_base = 1.10`.

| Level | Generateur | Producteur | Depot | Champ de Force | Ionisateur | Condenseur | Lieur | Stabilisateur | Coffre-fort |
|------:|-----------:|-----------:|------:|---------------:|-----------:|-----------:|------:|--------------:|------------:|
|     1 |        10s |        10s |   80s |           24s  |       24s  |      132s  |  110s |          132s |         99s |
|     5 |        88s |        59s |  117s |           35s  |       35s  |      193s  |  161s |          193s |        145s |
|    10 |       141s |        94s |  189s |           57s  |       57s  |      311s  |  259s |          311s |        233s |
|    20 |       367s |       245s |  489s |          148s  |      148s  |      807s  |  673s |          807s |        605s |
|    50 |      6403s |      4269s | 8538s |         2583s  |     2583s  |    14087s  |11739s |        14087s |      10565s |

Human-readable equivalents:

| Level | Generateur | Producteur | Depot  | Champ de Force | Ionisateur | Condenseur | Lieur   | Stabilisateur | Coffre-fort |
|------:|-----------:|-----------:|-------:|---------------:|-----------:|-----------:|--------:|--------------:|------------:|
|     1 |       10s  |       10s  |  1.3m  |           24s  |       24s  |      2.2m  |   1.8m  |         2.2m  |       1.6m  |
|     5 |      1.5m  |       59s  |  1.9m  |           35s  |       35s  |      3.2m  |   2.7m  |         3.2m  |       2.4m  |
|    10 |      2.4m  |      1.6m  |  3.1m  |           57s  |       57s  |      5.2m  |   4.3m  |         5.2m  |       3.9m  |
|    20 |      6.1m  |      4.1m  |  8.2m  |          2.5m  |      2.5m  |     13.4m  |  11.2m  |        13.4m  |      10.1m  |
|    50 |      1.8h  |      1.2h  |  2.4h  |         43.0m  |     43.0m  |      3.9h  |   3.3h  |         3.9h  |       2.9h  |

Formulas used (V4), where N = Level - 1 (niveauActuel):
- Generateur: `N==0: 10s`, else `round(60 * pow(1.10, N))`
- Producteur: `N==0: 10s`, else `round(40 * pow(1.10, N))`
- Depot: `round(80 * pow(1.10, N))`
- Champ de Force: `round(20 * pow(1.10, N+2))`
- Ionisateur: `round(20 * pow(1.10, N+2))`
- Condenseur: `round(120 * pow(1.10, N+1))`
- Lieur: `round(100 * pow(1.10, N+1))`
- Stabilisateur: `round(120 * pow(1.10, N+1))`
- Coffre-fort: `round(90 * pow(1.10, N+1))`

---

## HP Comparison Table

Only damageable buildings are shown. Non-damageable buildings (Ionisateur, Condenseur,
Lieur, Stabilisateur, Coffre-fort) have no HP.

V4 uses a **purely polynomial** formula instead of the old exponential+polynomial mix.

| Level | Generateur | Producteur |  Depot  | Champ de Force |
|------:|-----------:|-----------:|--------:|---------------:|
|     1 |         50 |         50 |      50 |            125 |
|     5 |       2795 |       2795 |    2795 |           6988 |
|    10 |      15811 |      15811 |   15811 |          39528 |
|    20 |      89443 |      89443 |   89443 |         223607 |
|    50 |     883883 |     883883 |  883883 |        2209709 |

Formulas (V4):
- Standard: `round(50 * pow(max(1, L), 2.5))`
- Champ de Force: `round(125 * pow(max(1, L), 2.5))` -- 2.5x standard base

The polynomial `L^2.5` grows steadily without the runaway exponential behavior of V3.
Alliance Fortification research adds up to +25% HP on top of these base values.

**V3 vs V4 HP comparison:**

| Level | V3 Standard | V4 Standard | V3 Force Field | V4 Force Field |
|------:|------------:|------------:|---------------:|---------------:|
|     1 |          44 |          50 |            110 |            125 |
|    10 |         441 |       15811 |           1102 |          39528 |
|    20 |        1495 |       89443 |           3737 |         223607 |
|    50 |     ~184200 |      883883 |        ~460500 |        2209709 |

V4 buildings are significantly tougher across all levels, making building destruction
a more strategic commitment.

---

## Storage and Vault Table

### Storage Capacity by Depot Level

| Level | V3 Storage | V4 Storage |
|------:|-----------:|-----------:|
|     1 |        500 |       1150 |
|     5 |       2500 |       2011 |
|    10 |       5000 |       4046 |
|    20 |      10000 |      16367 |
|    50 |      25000 |    1083657 |

Formula: `placeDepot(level) = round(1000 * pow(1.15, level))`

### Vault Protection (V4: percentage-based)

Each Coffre-fort level protects 2% of total storage, capping at 50% at level 25.
Protection amount depends on both Coffre-fort and Depot levels.

| Coffre Level | Protection % | At Depot 5 | At Depot 10 | At Depot 20 |
|-------------:|-------------:|-----------:|------------:|------------:|
|            1 |           2% |         40 |          81 |         327 |
|            5 |          10% |        201 |         405 |        1637 |
|           10 |          20% |        402 |         809 |        3273 |
|           15 |          30% |        603 |        1214 |        4910 |
|           20 |          40% |        804 |        1618 |        6547 |
|           25 |     50% (cap)|       1006 |        2023 |        8184 |

Formula: `capaciteCoffreFort(coffre, depot) = round(min(0.50, coffre * 0.02) * placeDepot(depot))`

---

## Points Comparison Table

Construction points awarded upon completing each level. Unchanged from V3.

| Level | Generateur | Producteur | Depot | CdF | Ionisateur | Condenseur | Lieur | Stabilisateur | Coffre-fort |
|------:|-----------:|-----------:|------:|----:|-----------:|-----------:|------:|--------------:|------------:|
|     1 |          1 |          1 |     1 |   1 |          1 |          2 |     2 |             3 |           1 |
|     5 |          1 |          1 |     1 |   1 |          1 |          2 |     2 |             3 |           1 |
|    10 |          2 |          2 |     2 |   1 |          1 |          3 |     3 |             4 |           2 |
|    20 |          3 |          3 |     3 |   2 |          2 |          4 |     4 |             5 |           3 |
|    50 |          6 |          6 |     6 |   4 |          4 |          7 |     7 |             8 |           6 |

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
| Coffre-fort    |          1 |               0.1 |

The Stabilisateur awards the most points per upgrade, compensating for its highest
cost growth base (1.25 vs 1.15--1.20 for other buildings).

---

## Config Reference Summary (V4)

| Building       | Config Key       | Cost Type(s)            | Growth Base | timeBase | time_growth | offset |
|:---------------|:-----------------|:------------------------|:------------|--------:|:-----------:|-------:|
| Generateur     | `generateur`     | Energy + Atoms          | 1.15        |      60 |        1.10 |      0 |
| Producteur     | `producteur`     | Energy + Atoms          | 1.15        |      40 |        1.10 |      0 |
| Depot          | `depot`          | Energy only             | 1.15        |      80 |        1.10 |      0 |
| Champ de Force | `champdeforce`   | Carbone only            | 1.20        |      20 |        1.10 |      2 |
| Ionisateur     | `ionisateur`     | Oxygene only            | 1.20        |      20 |        1.10 |      2 |
| Condenseur     | `condenseur`     | Energy + Atoms          | 1.20        |     120 |        1.10 |      1 |
| Lieur          | `lieur`          | Azote only              | 1.20        |     100 |        1.10 |      1 |
| Stabilisateur  | `stabilisateur`  | Atoms only              | 1.25        |     120 |        1.10 |      1 |
| Coffre-fort    | `coffrefort`     | Energy only             | 1.15        |      90 |        1.10 |      1 |
