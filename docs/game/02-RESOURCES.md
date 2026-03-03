# 02 - Resources

This document covers the full resource system in TVLW: the 8 atom types, energy
production, atom production, resource update cycles, the condenseur system, and
molecule decay.

---

## 1. The 8 Atom Types

Every molecule in the game is composed of up to 8 atom types. Each atom type has
a **primary role** (its main stat contribution) and a **secondary role** (it
boosts the stat of another atom type via V4 covalent synergy).

| # | Name      | French     | Letter | Color     | Primary Role             | Secondary Role       | Formula Function                |
|---|-----------|------------|--------|-----------|--------------------------|----------------------|---------------------------------|
| 0 | Carbon    | Carbone    | C      | black     | Defense                  | HP synergy           | `defense()`                     |
| 1 | Nitrogen  | Azote      | N      | blue      | Formation time reduction | Speed synergy        | `tempsFormation()`              |
| 2 | Hydrogen  | Hydrogene  | H      | gray      | Building destruction     | Attack synergy       | `potentielDestruction()`        |
| 3 | Oxygen    | Oxygene    | O      | red       | Attack                   | Destruction synergy  | `attaque()`                     |
| 4 | Chlorine  | Chlore     | Cl     | green     | Movement speed           | Pillage synergy      | `vitesse()`                     |
| 5 | Sulfur    | Soufre     | S      | #D07D00   | Pillage capacity         | (primary only)       | `pillage()`                     |
| 6 | Bromine   | Brome      | Br     | #840000   | Hit points               | Defense synergy      | `pointsDeVieMolecule()`         |
| 7 | Iodine    | Iode       | I      | #BB6668   | Generator catalyst       | Formation synergy    | `productionEnergieMolecule()`   |

**V4 Covalent Synergy pairs** (see Section 5.2 for full formulas):

| Primary Atom | Secondary Atom | Synergy Mechanic                          |
|--------------|----------------|-------------------------------------------|
| O (Attack)   | H              | `(1 + H / COVALENT_SYNERGY_DIVISOR)`     |
| C (Defense)  | Br             | `(1 + Br / COVALENT_SYNERGY_DIVISOR)`    |
| Br (HP)      | C              | `(1 + C / COVALENT_SYNERGY_DIVISOR)`     |
| H (Destruct) | O              | `(1 + O / COVALENT_SYNERGY_DIVISOR)`     |
| S (Pillage)  | Cl             | `(1 + Cl / COVALENT_SYNERGY_DIVISOR)`    |
| Cl (Speed)   | N              | `(1 + (Cl * N) / 200)`                   |
| I (Energy)   | (condenseur)   | via `modCond(nivCondI)` multiplier        |
| N (Formation)| I              | `(1 + I / 200)` in formation formula     |

Where `COVALENT_SYNERGY_DIVISOR = 100` (`config.php:70`).

**Source:** `includes/config.php:41-45`, `includes/constantesBase.php:4-9`

The arrays that define these are:

```php
$nomsRes    = ['carbone','azote','hydrogene','oxygene','chlore','soufre','brome','iode'];
$couleurs   = ['black','blue','gray','red','green','#D07D00','#840000','#BB6668'];
$lettre     = ['C','N','H','O','Cl','S','Br','I'];
$utilite    = ['Defense','Temps de formation','Degats aux batiments','Attaque',
               'Vitesse de deplacement','Capacite de pillage','Points de vie',
               'Produit de l\'energie'];
```

Each atom type also has a detailed help text in `$aidesAtomes` explaining its
role to the player (`constantesBase.php:10-17`).

---

## 2. Energy System

Energy is the primary resource. It is produced by the Generateur building,
boosted multiplicatively by iodine molecules (V4 catalyst), alliance bonuses,
prestige bonuses, and medal bonuses, then drained by the Producteur building.

### 2.1 Energy Revenue Formula

**Function:** `revenuEnergie($niveau, $joueur, $detail = 0)`
**Source:** `includes/game_resources.php:7-72`

The calculation proceeds in stages:

```
Stage 5 - prodBase         = BASE_ENERGY_PER_LEVEL (75) * generateur_level
Stage 4 - prodIode         = prodBase * iodeCatalystBonus   (V4: multiplicative catalyst)
Stage 3 - prodMedaille     = (1 + medalBonus / 100) * prodIode
Stage 2 - prodDuplicateur  = bonusDuplicateur * prodMedaille
Stage 1 - prodPrestige     = prodDuplicateur * prestigeProductionBonus
Stage 0 - prodProducteur   = prodPrestige - drainageProducteur(producteur_level)
```

The `$detail` parameter selects which stage to return:

| detail | Returns            | Description                                  |
|--------|--------------------|----------------------------------------------|
| 0      | `prodProducteur`   | Final net energy, clamped >= 0 (default)     |
| 1      | `prodDuplicateur`  | Before prestige and producteur drain         |
| 2      | `prodMedaille`     | Before duplicateur bonus                     |
| 3      | `prodIode`         | After iode catalyst, before medal bonus      |
| 4      | `prodBase`         | Raw generateur output only                   |

All return values are wrapped in `round()`.

### 2.2 Base Energy Production

```php
$prodBase = BASE_ENERGY_PER_LEVEL * $niveau;
// = 75 * generateur_level
```

**Source:** `includes/game_resources.php:53`, `includes/config.php:51`

### 2.3 Iodine Catalyst Bonus (V4)

In V4, iodine molecules act as a **multiplicative generator catalyst** rather
than adding flat energy. The total iodine atom count across all molecule classes
is summed, then applied as a scaling multiplier to base energy production.

```php
// Sum total iodine atoms across all 4 molecule classes
$totalIodeAtoms = 0;
for ($i = 1; $i <= 4; $i++) {
    $totalIodeAtoms += $molecules['iode'] * $molecules['nombre'];
}

// Calculate catalyst bonus (capped at IODE_CATALYST_MAX_BONUS = 1.0, i.e. +100%)
$iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $totalIodeAtoms / IODE_CATALYST_DIVISOR);
// = 1.0 + min(1.0, totalIodeAtoms / 50000)

// Apply multiplicatively to base energy
$prodIode = $prodBase * $iodeCatalystBonus;
```

**Source:** `includes/game_resources.php:34-54`, `includes/config.php:142-143`

**Constants:**
- `IODE_CATALYST_DIVISOR = 50000` -- divisor for scaling iode atoms to bonus
- `IODE_CATALYST_MAX_BONUS = 1.0` -- maximum +100% bonus (catalyst caps at 2x)

**Example:** A player with 25,000 total iodine atoms across all molecules:
```
iodeCatalystBonus = 1.0 + min(1.0, 25000 / 50000)
                  = 1.0 + 0.5
                  = 1.5   (50% energy boost)
```

At 50,000+ total iodine atoms the bonus caps at 2.0x (doubling base energy).

### 2.4 Per-Molecule Iodine Energy Value

The `productionEnergieMolecule` function computes how much energy value a single
molecule's iodine atoms contribute. This is used to display per-molecule stats,
not directly in the revenue chain (which uses the catalyst system above).

```php
function productionEnergieMolecule($iode, $niveau) {
    return round((IODE_QUADRATIC_COEFFICIENT * pow($iode, 2)
                + IODE_ENERGY_COEFFICIENT * $iode)
               * (1 + $niveau / IODE_LEVEL_DIVISOR));
    // = round((0.003 * iode^2 + 0.04 * iode) * (1 + niveauIode / 50))
}
```

**Source:** `includes/formulas.php:114-117`, `includes/config.php:98-100`

**Constants:**
- `IODE_QUADRATIC_COEFFICIENT = 0.003` -- quadratic scaling term
- `IODE_ENERGY_COEFFICIENT = 0.04` -- linear scaling term
- `IODE_LEVEL_DIVISOR = 50` -- condenseur level divisor

The V4 quadratic formula gives iodine meaningful scaling at high atom counts:

| Iodine Atoms | Condenseur 0 | Condenseur 10 | Condenseur 50 |
|-------------|--------------|---------------|---------------|
| 10          | 1            | 1             | 1             |
| 50          | 10           | 12            | 19            |
| 100         | 34           | 41            | 68            |
| 200         | 128          | 154           | 256           |

### 2.5 Medal Bonus (Energievore)

The Energievore medal gives a percentage bonus to energy production. The tier
is determined by cumulative energy spent on constructions:

```php
$prodMedaille = (1 + $bonus / 100) * $prodIode;
```

**Source:** `includes/game_resources.php:44-55`

Medal bonus percentages by tier: `[1, 3, 6, 10, 15, 20, 30, 50]`

Energievore thresholds: `[100, 500, 3000, 20000, 100000, 2000000, 10000000, 1000000000]`

**Source:** `includes/constantesBase.php:25,35`

### 2.6 Alliance Duplicateur Bonus

If the player belongs to an alliance with a duplicateur upgrade:

```php
$bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
// bonusDuplicateur($niveau) = $niveau * DUPLICATEUR_BONUS_PER_LEVEL
// = $niveau * 0.01
// So: 1 + (duplicateur_level / 100)
```

**Source:** `includes/game_resources.php:28-32`, `includes/formulas.php:69-72`

A level 10 duplicateur gives a 10% bonus (multiplier of 1.10).

### 2.7 Prestige Production Bonus

If the player has the "Experimente" prestige unlock, all production is
multiplied by `PRESTIGE_PRODUCTION_BONUS`:

```php
$prodPrestige = $prodDuplicateur * prestigeProductionBonus($joueur);
// prestigeProductionBonus returns PRESTIGE_PRODUCTION_BONUS (1.05) or 1.0
```

**Source:** `includes/game_resources.php:57`, `includes/prestige.php:185-190`

This is a flat +5% multiplier applied after the duplicateur stage.

### 2.8 Producteur Energy Drain

The Producteur building drains energy to produce atoms. In V4, this drain
uses **exponential growth**:

```php
function drainageProducteur($niveau) {
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau));
    // = round(8 * pow(1.15, producteur_level))
}
```

**Source:** `includes/formulas.php:79-82`, `includes/config.php:60,32`

**Constants:**
- `PRODUCTEUR_DRAIN_PER_LEVEL = 8` -- base drain coefficient
- `ECO_GROWTH_BASE = 1.15` -- exponential growth base

Example drain values:

| Producteur Level | Drain (V4 exponential) |
|-----------------|------------------------|
| 1               | 9                      |
| 5               | 16                     |
| 10              | 32                     |
| 15              | 65                     |
| 20              | 131                    |
| 30              | 531                    |

This is subtracted from the final energy revenue:

```php
$prodProducteur = $prodPrestige - drainageProducteur($producteur['producteur']);
```

**Source:** `includes/game_resources.php:58`

Note: if the producteur drain exceeds production, net energy revenue goes
negative. The `revenuEnergie` function clamps `detail=0` returns to `max(0, ...)`,
and `updateRessources` clamps energy to a minimum of 0
(`game_resources.php:149-151`).

### 2.9 Energy Storage Cap (V4 Exponential)

In V4, storage uses **exponential growth** instead of linear:

```php
function placeDepot($niveau) {
    return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau));
    // = round(1000 * pow(1.15, depot_level))
}
```

**Source:** `includes/formulas.php:240-243`, `includes/config.php:137,32`

**Constants:**
- `BASE_STORAGE_INITIAL = 1000` -- base storage at level 0
- `ECO_GROWTH_BASE = 1.15` -- exponential growth base

Example storage values:

| Depot Level | Storage (V4 exponential) |
|-------------|--------------------------|
| 0           | 1000                     |
| 5           | 2011                     |
| 10          | 4046                     |
| 15          | 8137                     |
| 20          | 16367                    |
| 30          | 66212                    |

Energy is capped at `placeDepot(depot_level)` during resource updates.

---

## 3. Atom Production

### 3.1 Atom Revenue Formula

**Function:** `revenuAtome($num, $joueur)`
**Source:** `includes/game_resources.php:75-97`

```php
function revenuAtome($num, $joueur) {
    $niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];

    // ... alliance duplicateur lookup ...

    return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur));
    // = round(bonusDuplicateur * 60 * pointsForThisAtom * prestigeBonus)
}
```

Where:
- `BASE_ATOMS_PER_POINT` = 60 (`config.php:54`)
- `$niveau` = the number of producteur points assigned to this atom type
- `$bonusDuplicateur` = `1 + (duplicateur_level / 100)` (same as energy)
- `prestigeProductionBonus` = 1.05 if prestige unlocked, else 1.0

### 3.2 Producteur Points Distribution

The Producteur building grants **8 points per level** to distribute among the 8
atom types. These are stored as a semicolon-separated string in the database
column `constructions.pointsProducteur`.

```
Example: "3;2;0;1;0;1;1;0"
         C  N  H  O Cl  S Br  I
```

**Source:** `includes/config.php:172` (`points_per_level => 8`)

Each point assigned to an atom type produces `round(bonusDuplicateur * 60 * points * prestigeBonus)`
atoms per hour. A player with a level 5 producteur has 40 points to allocate.

### 3.3 Atom Storage Cap

Atoms share the same exponential storage cap as energy:

```php
if ($$ressource >= placeDepot($depot['depot'])) {
    $$ressource = placeDepot($depot['depot']);
}
```

**Source:** `includes/game_resources.php:167-169`

Storage = `round(1000 * pow(1.15, depot_level))` for each atom type independently.

---

## 4. Resource Update Cycle

**Function:** `updateRessources($joueur)`
**Source:** `includes/game_resources.php:119-232`

This function is called on every page load. It performs a time-based resource
update calculating what the player accumulated while away.

### 4.1 Time Delta

```php
$nbsecondes = time() - $donnees['tempsPrecedent'];
```

**Source:** `includes/game_resources.php:127`

The timestamp `tempsPrecedent` in the `autre` table records the last update
time. An atomic compare-and-swap UPDATE prevents double-counting if two requests
arrive simultaneously (`game_resources.php:134-137`).

### 4.2 Energy Update

```php
$energie = $donnees['energie'] + $revenuenergie * ($nbsecondes / 3600);
if ($energie >= placeDepot($depot['depot'])) {
    $energie = placeDepot($depot['depot']);
}
if ($energie < 0) {
    $energie = 0;
}
```

**Source:** `includes/game_resources.php:145-151`

Revenue is per-hour, so it is scaled by `elapsed_seconds / 3600`.

### 4.3 Atom Updates

```php
foreach ($nomsRes as $num => $ressource) {
    ${'revenu' . $ressource} = revenuAtome($num, $joueur);
    $$ressource = $donnees[$ressource] + ${'revenu' . $ressource} * ($nbsecondes / 3600);
    if ($$ressource >= $placeMax) {
        $$ressource = $placeMax;
    }
}
```

**Source:** `includes/game_resources.php:161-173`

Each of the 8 atom types is updated independently with the same formula:
`current + revenue * (seconds / 3600)`, then capped to depot storage. All 8
columns are written in a single batched UPDATE query.

### 4.4 Molecule Decay

After resources, molecule counts are decayed:

```php
$moleculesRestantes = pow(coefDisparition($joueur, $compteur + 1), $nbsecondes)
                      * $molecules['nombre'];
```

**Source:** `includes/game_resources.php:193`

This applies exponential decay to every molecule class the player owns. The
lost molecules are tracked in `autre.moleculesPerdues` for medal progression
(`game_resources.php:198,203-205`).

### 4.5 Neutrino Decay (V4)

After molecule decay, neutrinos are also decayed. Neutrinos are treated as
mass-1 molecules for decay purposes:

```php
$coefNeutrino = coefDisparition($joueur, 1, 1); // type=1, nbAtomes=1
$neutrinosRestants = floor(pow($coefNeutrino, $nbsecondes) * $neutrinoData['neutrinos']);
```

**Source:** `includes/game_resources.php:207-215`

The `type=1` flag tells `coefDisparition` to use the second argument (1) directly
as the total atom count, rather than looking up a molecule class. This means
neutrinos decay very slowly (equivalent to a molecule with just 1 total atom).
The result is floored with `floor()` rather than rounded.

### 4.6 Absence Report

If the player has been offline for more than 6 hours, a loss report is
generated and inserted into the `rapports` table:

```php
if ($nbheuresDebut > 6) {
    // Build report showing molecule losses per class
    // Insert into rapports table
}
```

**Source:** `includes/game_resources.php:217-231`

The threshold is defined as `ABSENCE_REPORT_THRESHOLD_HOURS = 6`
(`config.php:27`). The report lists molecule losses for all 4 classes during
the absence period.

### 4.7 Update Sequence Summary

1. Calculate elapsed seconds since last update
2. Atomic compare-and-swap of `tempsPrecedent` to prevent double-updates
3. Calculate and apply energy revenue (capped by depot, floored at 0)
4. For each of 8 atom types: calculate and apply atom revenue (capped by depot)
5. For each molecule class: apply exponential decay, track losses
6. **Neutrino decay:** apply mass-1 exponential decay to neutrino count (V4)
7. If offline > 6 hours: generate absence report

---

## 5. Condenseur System

The Condenseur building provides points that boost atom effectiveness in
molecule stat formulas.

### 5.1 Points Per Level

The condenseur grants **5 points per level** to distribute among the 8 atom
"niveaux" (levels).

**Source:** `includes/config.php:216` (`points_per_level => 5`)

These points are stored as a semicolon-separated string in the database column
`constructions.pointsCondenseur`.

```
Example: "2;0;0;3;0;0;0;0"
         C  N  H  O Cl  S Br  I
```

### 5.2 V4 Unified Condenseur Modifier

In V4, all condenseur effects use a single unified modifier function:

```php
function modCond($niveauCondenseur) {
    return 1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR);
    // = 1 + (niveauCondenseur / 50)
}
```

**Source:** `includes/formulas.php:74-77`, `includes/config.php:68`

Where `COVALENT_CONDENSEUR_DIVISOR = 50` for all stats (except formation time,
which uses `FORMATION_LEVEL_DIVISOR = 20`).

### 5.3 Effect on Molecule Stats (V4 Covalent Synergy)

V4 introduces **covalent synergy** where each stat formula uses a primary atom
with an exponent-based scaling and a secondary atom that provides a linear
bonus. All formulas follow the pattern:

```
stat = round((pow(primary, COVALENT_BASE_EXPONENT) + primary)
             * (1 + secondary / COVALENT_SYNERGY_DIVISOR)
             * modCond(nivCondPrimary))
```

Where:
- `COVALENT_BASE_EXPONENT = 1.2` (`config.php:69`)
- `COVALENT_SYNERGY_DIVISOR = 100` (`config.php:70`)

| Stat            | Primary | Secondary | Medal Bonus? | Formula Source          |
|-----------------|---------|-----------|--------------|-------------------------|
| Attack          | O       | H         | Yes          | `formulas.php:84-88`    |
| Defense         | C       | Br        | Yes          | `formulas.php:90-94`    |
| HP              | Br      | C         | No           | `formulas.php:96-100`   |
| Destruction     | H       | O         | No           | `formulas.php:102-106`  |
| Pillage         | S       | Cl        | Yes          | `formulas.php:108-112`  |
| Energy (iodine) | I       | (cond)    | No           | `formulas.php:114-117`  |
| Speed           | Cl      | N         | No           | `formulas.php:119-123`  |
| Formation time  | N       | I         | No           | `formulas.php:130-142`  |

Note: HP has a minimum of `MOLECULE_MIN_HP = 10` (`config.php:71`) to prevent
0-brome molecules from being instantly wiped.

### 5.4 Example

With 10 condenseur points in oxygen, 100 oxygen atoms, and 50 hydrogen atoms
(secondary):

```
attack = round((pow(100, 1.2) + 100) * (1 + 50/100) * modCond(10))
       = round((251.19 + 100) * 1.5 * 1.2)
       = round(351.19 * 1.5 * 1.2)
       = round(632.1)
       = 632
```

Without condenseur points (niveau = 0) and no secondary atoms (H = 0):

```
attack = round((pow(100, 1.2) + 100) * (1 + 0/100) * (1 + 0/50))
       = round(351.19 * 1.0 * 1.0)
       = 351
```

---

## 6. Molecule Decay

Molecules slowly disappear over time via exponential decay. Larger molecules
(more total atoms) decay faster.

### 6.1 Decay Coefficient

**Function:** `coefDisparition($joueur, $classeOuNbTotal, $type = 0)`
**Source:** `includes/formulas.php:145-204`

The per-second decay coefficient is calculated in three steps:

**Step 1 -- Raw decay based on molecule mass:**

```php
$rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
// = pow(0.99, pow(1 + totalAtoms / 150, 1.5) / 25000)
```

**Step 2 -- Stabilisateur modifier (V4 asymptotic):**

```php
$modStab = pow(STABILISATEUR_ASYMPTOTE, $stabilisateur['stabilisateur']);
// = pow(0.98, stabilisateur_level)
```

**Step 3 -- Medal modifier and combined exponent:**

```php
$modMedal = 1 - ($bonus / 100);
$baseDecay = pow($rawDecay, $modStab * $modMedal);
```

Substituting V4 constants (`config.php:115-120`):

```
rawDecay  = pow(0.99, pow(1 + totalAtoms / 150, 1.5) / 25000)
modStab   = pow(0.98, stabilisateur_level)
modMedal  = 1 - medalBonus / 100
baseDecay = pow(rawDecay, modStab * modMedal)
```

Where:
- `DECAY_BASE = 0.99`
- `DECAY_ATOM_DIVISOR = 150` (V4: increased from 100 -- large molecules more viable)
- `DECAY_MASS_EXPONENT = 1.5` (V4: reduced from 2 -- longer survival for big molecules)
- `DECAY_POWER_DIVISOR = 25000`
- `STABILISATEUR_ASYMPTOTE = 0.98` (V4: asymptotic, never reaches zero)
- `totalAtoms` = sum of all 8 atom counts in the molecule
- `medalBonus` = Pertes medal tier bonus (`[1, 3, 6, 10, 15, 20, 30, 50]`)

The `$type` parameter controls how `totalAtoms` is determined:
- `$type = 0` (default): looks up the molecule class from the database and sums all atom counts
- `$type = 1`: uses `$classeOuNbTotal` directly as the total atom count

**Additional modifiers** (applied after base decay):
- **Catalyst decay increase:** if an active catalyst has a `decay_increase` effect, the decay exponent is amplified: `baseDecay = pow(baseDecay, 1.0 + catalystDecayIncrease)`
- **Isotope variants:** Stable isotope reduces decay (`pow(baseDecay, 0.7)`), Reactif isotope increases decay (`pow(baseDecay, 1.2)`) (BAL-CROSS C9: reduced from 1.5)

**Source:** `includes/formulas.php:179-200`

### 6.2 How Decay Is Applied

During `updateRessources`, for each molecule class:

```php
$moleculesRestantes = pow(coefDisparition($joueur, $classNum), $nbsecondes)
                      * $molecules['nombre'];
```

**Source:** `includes/game_resources.php:193`

This means after `t` seconds, the molecule count becomes:

```
nombre(t) = nombre(0) * coefDisparition ^ t
```

Since `coefDisparition` is always less than 1, the count decreases
exponentially over time.

### 6.3 Factors That Reduce Decay

**More atoms = faster decay.** The `pow(1 + totalAtoms/150, 1.5) / 25000` term
grows with molecule size. With the V4 exponent of 1.5 (down from 2), the
scaling is subquadratic -- large molecules are more viable than before but
still decay faster than small ones.

**Stabilisateur building reduces decay (V4 asymptotic).** The stabilisateur
now uses an asymptotic formula that never fully eliminates decay:

```
modStab = pow(0.98, stabilisateur_level)
```

At each level, the effective decay exponent is multiplied by 0.98 (a 2%
reduction compounding per level):

| Stab Level | modStab | Decay Reduction |
|------------|---------|-----------------|
| 0          | 1.000   | 0%              |
| 10         | 0.817   | 18.3%           |
| 25         | 0.604   | 39.6%           |
| 50         | 0.364   | 63.6%           |
| 100        | 0.133   | 86.7%           |

Decay is never fully eliminated regardless of stabilisateur level.

**Source:** `includes/config.php:119`

**Pertes medal reduces decay.** The medal for molecules lost also reduces the
decay exponent:

```
modMedal = 1 - medalBonus / 100
```

At Diamond Rouge tier (50% bonus), the exponent is halved.

These two reductions multiply together, so a stabilisateur level 50 combined
with a 50% medal bonus gives:

```
effective exponent = modStab * modMedal
                   = pow(0.98, 50) * (1 - 0.50)
                   = 0.364 * 0.50
                   = 0.182
```

### 6.4 Half-Life Formula

**Function:** `demiVie($joueur, $classeOuNbTotal, $type = 0)`
**Source:** `includes/formulas.php:206-212`

The half-life (time in seconds for molecule count to halve) is:

```php
function demiVie($joueur, $classeOuNbTotal, $type = 0) {
    $coef = coefDisparition($joueur, $classeOuNbTotal, $type);
    if ($coef >= 1.0) return PHP_INT_MAX; // No decay = infinite half-life
    return round(log(0.5, DECAY_BASE) / log($coef, DECAY_BASE));
}
```

This simplifies mathematically to:

```
halfLife = log(0.5) / log(coefDisparition)
```

Both logarithms use base `DECAY_BASE` (0.99) in the PHP code, but since
`log_b(x) / log_b(y) = log(x) / log(y)` for any base `b`, the base cancels
out and the result is equivalent.

A safety check returns `PHP_INT_MAX` if the coefficient is >= 1.0 (no decay).

### 6.5 Neutrino Decay

Neutrinos decay like mass-1 molecules, using `type=1` to pass the atom count
directly:

```php
$coefNeutrino = coefDisparition($joueur, 1, 1);
```

**Source:** `includes/game_resources.php:210`

This means neutrinos experience very slow decay (equivalent to a molecule with
only 1 total atom). The neutrino count is floored rather than rounded after
decay is applied.

### 6.6 Decay Example

Consider a molecule with 200 total atoms, no stabilisateur, no medal bonus:

```
rawDecay = pow(0.99, pow(1 + 200/150, 1.5) / 25000)
         = pow(0.99, pow(2.333, 1.5) / 25000)
         = pow(0.99, 3.563 / 25000)
         = pow(0.99, 0.0001425)
         = 0.999998567...

baseDecay = pow(0.999998567, pow(0.98, 0) * 1.0)
          = pow(0.999998567, 1.0)
          = 0.999998567

halfLife  = log(0.5) / log(0.999998567)
          ~ 483,700 seconds
          ~ 134.4 hours
```

Now with 800 total atoms:

```
rawDecay = pow(0.99, pow(1 + 800/150, 1.5) / 25000)
         = pow(0.99, pow(6.333, 1.5) / 25000)
         = pow(0.99, 15.937 / 25000)
         = pow(0.99, 0.0006375)
         = 0.999993597...

baseDecay = 0.999993597

halfLife  = log(0.5) / log(0.999993597)
          ~ 108,200 seconds
          ~ 30.1 hours
```

With V4's reduced mass exponent (1.5 vs 2) and larger divisor (150 vs 100),
larger molecules survive significantly longer than in previous versions.
The 800-atom molecule decays about 4.5x faster than the 200-atom one
(compared to ~9x faster under the old formula).

---

## Source File Reference

| File                         | Key Contents                                          |
|------------------------------|-------------------------------------------------------|
| `includes/config.php`        | All game constants (defines and arrays)               |
| `includes/constantesBase.php`| Legacy arrays: atom names, colors, medal thresholds   |
| `includes/game_resources.php`| `revenuEnergie`, `revenuAtome`, `updateRessources`    |
| `includes/formulas.php`      | All stat formulas, decay, half-life, storage          |
| `includes/prestige.php`      | `prestigeProductionBonus` (prestige unlock multiplier)|
| `includes/catalyst.php`      | `catalystEffect` (active catalyst modifiers)          |
