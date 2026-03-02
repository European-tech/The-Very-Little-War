# 02 - Resources

This document covers the full resource system in TVLW: the 8 atom types, energy
production, atom production, resource update cycles, the condenseur system, and
molecule decay.

---

## 1. The 8 Atom Types

Every molecule in the game is composed of up to 8 atom types. Each atom type
maps to a specific molecule stat through a dedicated formula function.

| # | Name      | French     | Letter | Color     | Primary Role             | Formula Function                |
|---|-----------|------------|--------|-----------|--------------------------|---------------------------------|
| 0 | Carbon    | Carbone    | C      | black     | Defense                  | `defense()`                     |
| 1 | Nitrogen  | Azote      | N      | blue      | Formation time reduction | `tempsFormation()`              |
| 2 | Hydrogen  | Hydrogene  | H      | gray      | Building destruction     | `potentielDestruction()`        |
| 3 | Oxygen    | Oxygene    | O      | red       | Attack                   | `attaque()`                     |
| 4 | Chlorine  | Chlore     | Cl     | green     | Movement speed           | `vitesse()`                     |
| 5 | Sulfur    | Soufre     | S      | #D07D00   | Pillage capacity         | `pillage()`                     |
| 6 | Bromine   | Brome      | Br     | #840000   | Hit points               | `pointsDeVieMolecule()`         |
| 7 | Iodine    | Iode       | I      | #BB6668   | Energy production        | `productionEnergieMolecule()`   |

**Source:** `includes/config.php:38-42`, `includes/constantesBase.php:4-9`

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
boosted by iodine molecules and alliance bonuses, and drained by the Producteur
building.

### 2.1 Energy Revenue Formula

**Function:** `revenuEnergie($niveau, $joueur, $detail = 0)`
**Source:** `includes/game_resources.php:7-63`

The calculation proceeds in stages:

```
Stage 4 - prodBase         = BASE_ENERGY_PER_LEVEL (75) * generateur_level
Stage 3 - prodIode         = prodBase + totalIode
Stage 2 - prodMedaille     = prodIode * (1 + medalBonus / 100)
Stage 1 - prodDuplicateur  = prodMedaille * bonusDuplicateur
Stage 0 - prodProducteur   = prodDuplicateur - drainageProducteur(producteur_level)
```

The `$detail` parameter selects which stage to return:

| detail | Returns            | Description                              |
|--------|--------------------|------------------------------------------|
| 0      | `prodProducteur`   | Final net energy (default, used in game) |
| 1      | `prodDuplicateur`  | Before producteur drain                  |
| 2      | `prodMedaille`     | Before alliance bonus                    |
| 3      | `prodIode`         | Before medal bonus                       |
| 4      | `prodBase`         | Raw generateur output only               |

All return values are wrapped in `round()`.

### 2.2 Base Energy Production

```php
$prodBase = BASE_ENERGY_PER_LEVEL * $niveau;
// = 75 * generateur_level
```

**Source:** `includes/game_resources.php:47`, `includes/config.php:48`

### 2.3 Iodine Molecule Energy

Iodine molecules passively produce energy. The total iodine contribution is
summed across all 4 molecule classes:

```php
$totalIode = 0;
for ($i = 1; $i <= 4; $i++) {
    $totalIode += productionEnergieMolecule($molecules['iode'], $niveauiode)
                  * $molecules['nombre'];
}
```

**Source:** `includes/game_resources.php:31-36`

Where `productionEnergieMolecule` is:

```php
function productionEnergieMolecule($iode, $niveau) {
    return round(IODE_ENERGY_COEFFICIENT * $iode * (1 + $niveau / 50));
    // = round(0.05 * iode * (1 + niveauIode / 50))
}
```

**Source:** `includes/formulas.php:144-147`, `includes/config.php:87`

The `$niveau` here is the condenseur level for iodine (index 7 in
`pointsCondenseur`), and `$molecules['nombre']` is the current molecule count
for that class. The energy produced is per-molecule, multiplied by the number
of molecules.

### 2.4 Medal Bonus (Energievore)

The Energievore medal gives a percentage bonus to energy production. The tier
is determined by cumulative energy spent on constructions:

```php
$prodMedaille = (1 + $bonus / 100) * $prodIode;
```

**Source:** `includes/game_resources.php:38-49`

Medal bonus percentages by tier: `[1, 3, 6, 10, 15, 20, 30, 50]`

Energievore thresholds: `[100, 500, 3000, 20000, 100000, 2000000, 10000000, 1000000000]`

**Source:** `includes/constantesBase.php:25,35`

### 2.5 Alliance Duplicateur Bonus

If the player belongs to an alliance with a duplicateur upgrade:

```php
$bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
// bonusDuplicateur($niveau) = $niveau / 100
// So: 1 + (duplicateur_level / 100)
```

**Source:** `includes/game_resources.php:24-28`, `includes/formulas.php:70-73`

A level 10 duplicateur gives a 10% bonus (multiplier of 1.10).

### 2.6 Producteur Energy Drain

The Producteur building drains energy to produce atoms:

```php
function drainageProducteur($niveau) {
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * $niveau);
    // = round(8 * producteur_level)
}
```

**Source:** `includes/formulas.php:75-78`, `includes/config.php:57`

This is subtracted from the final energy revenue:

```php
$prodProducteur = $prodDuplicateur - drainageProducteur($producteur['producteur']);
```

**Source:** `includes/game_resources.php:51`

Note: if the producteur drain exceeds production, net energy revenue goes
negative. The `updateRessources` function clamps energy to a minimum of 0
(`game_resources.php:124-126`).

### 2.7 Energy Storage Cap

```php
function placeDepot($niveau) {
    return 500 * $niveau;
    // = BASE_STORAGE_PER_LEVEL (500) * depot_level
}
```

**Source:** `includes/formulas.php:223-226`, `includes/config.php:54`

Energy is capped at `placeDepot(depot_level)` during resource updates. A
level 20 depot stores up to 10,000 energy.

---

## 3. Atom Production

### 3.1 Atom Revenue Formula

**Function:** `revenuAtome($num, $joueur)`
**Source:** `includes/game_resources.php:66-82`

```php
function revenuAtome($num, $joueur) {
    $niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];

    // ... alliance duplicateur lookup ...

    return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau);
    // = round(bonusDuplicateur * 60 * pointsForThisAtom)
}
```

Where:
- `BASE_ATOMS_PER_POINT` = 60 (`config.php:51`)
- `$niveau` = the number of producteur points assigned to this atom type
- `$bonusDuplicateur` = `1 + (duplicateur_level / 100)` (same as energy)

### 3.2 Producteur Points Distribution

The Producteur building grants **8 points per level** to distribute among the 8
atom types. These are stored as a semicolon-separated string in the database
column `constructions.pointsProducteur`.

```
Example: "3;2;0;1;0;1;1;0"
         C  N  H  O Cl  S Br  I
```

**Source:** `includes/config.php:149` (`points_per_level => 8`)

Each point assigned to an atom type produces `round(bonusDuplicateur * 60 * points)`
atoms per hour. A player with a level 5 producteur has 40 points to allocate.

### 3.3 Atom Storage Cap

Atoms share the same storage cap as energy:

```php
if ($$ressource >= placeDepot($depot['depot'])) {
    $$ressource = placeDepot($depot['depot']);
}
```

**Source:** `includes/game_resources.php:133-135`

Storage = `500 * depot_level` for each atom type independently.

---

## 4. Resource Update Cycle

**Function:** `updateRessources($joueur)`
**Source:** `includes/game_resources.php:104-180`

This function is called on every page load. It performs a time-based resource
update calculating what the player accumulated while away.

### 4.1 Time Delta

```php
$nbsecondes = time() - $donnees['tempsPrecedent'];
```

**Source:** `includes/game_resources.php:112`

The timestamp `tempsPrecedent` in the `autre` table records the last update
time and is immediately updated to `time()` (`game_resources.php:114`).

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

**Source:** `includes/game_resources.php:119-127`

Revenue is per-hour, so it is scaled by `elapsed_seconds / 3600`.

### 4.3 Atom Updates

```php
foreach ($nomsRes as $num => $ressource) {
    ${'revenu' . $ressource} = revenuAtome($num, $joueur);
    $$ressource = $donnees[$ressource] + ${'revenu' . $ressource} * ($nbsecondes / 3600);
    if ($$ressource >= placeDepot($depot['depot'])) {
        $$ressource = placeDepot($depot['depot']);
    }
}
```

**Source:** `includes/game_resources.php:130-137`

Each of the 8 atom types is updated independently with the same formula:
`current + revenue * (seconds / 3600)`, then capped to depot storage.

### 4.4 Molecule Decay

After resources, molecule counts are decayed:

```php
$moleculesRestantes = pow(coefDisparition($joueur, $compteur + 1), $nbsecondes)
                      * $molecules['nombre'];
```

**Source:** `includes/game_resources.php:153`

This applies exponential decay to every molecule class the player owns. The
lost molecules are tracked in `autre.moleculesPerdues` for medal progression
(`game_resources.php:159-160`).

### 4.5 Absence Report

If the player has been offline for more than 6 hours, a loss report is
generated and inserted into the `rapports` table:

```php
if ($nbheuresDebut > 6) {
    // Build report showing molecule losses per class
    // Insert into rapports table
}
```

**Source:** `includes/game_resources.php:165-179`

The threshold is defined as `ABSENCE_REPORT_THRESHOLD_HOURS = 6`
(`config.php:29`). The report lists molecule losses for all 4 classes during
the absence period.

### 4.6 Update Sequence Summary

1. Calculate elapsed seconds since last update
2. Update `tempsPrecedent` to current time
3. Calculate and apply energy revenue (capped by depot, floored at 0)
4. For each of 8 atom types: calculate and apply atom revenue (capped by depot)
5. For each molecule class: apply exponential decay, track losses
6. If offline > 6 hours: generate absence report

---

## 5. Condenseur System

The Condenseur building provides points that boost atom effectiveness in
molecule stat formulas.

### 5.1 Points Per Level

The condenseur grants **5 points per level** to distribute among the 8 atom
"niveaux" (levels).

**Source:** `includes/config.php:195` (`points_per_level => 5`)

These points are stored as a semicolon-separated string in the database column
`constructions.pointsCondenseur`.

```
Example: "2;0;0;3;0;0;0;0"
         C  N  H  O Cl  S Br  I
```

### 5.2 Effect on Molecule Stats

Most molecule stat formulas include a multiplier based on the condenseur
niveau for the relevant atom:

```
(1 + niveau / 50)
```

Where `niveau` is the condenseur points assigned to that atom type.

The specific divisor varies by stat:

| Stat            | Atom    | Level Divisor | Formula Multiplier       | Source                |
|-----------------|---------|---------------|--------------------------|------------------------|
| Attack          | Oxygen  | 50            | `(1 + niveau / 50)`     | `formulas.php:95`      |
| Defense         | Carbon  | 50            | `(1 + niveau / 50)`     | `formulas.php:113`     |
| HP              | Bromine | 50            | `(1 + niveau / 50)`     | `formulas.php:118`     |
| Destruction     | Hydrogen| 50            | `(1 + niveau / 50)`     | `formulas.php:123`     |
| Pillage         | Sulfur  | 50            | `(1 + niveau / 50)`     | `formulas.php:141`     |
| Energy (iodine) | Iodine  | 50            | `(1 + niveau / 50)`     | `formulas.php:146`     |
| Speed           | Chlorine| 50            | `(1 + niveau / 50)`     | `formulas.php:151`     |
| Formation time  | Nitrogen| 20            | `(1 + niveau / 20)`     | `formulas.php:163`     |

Note that formation time uses a divisor of **20** rather than 50, making
condenseur points more impactful for nitrogen. All other stats use 50.

**Source:** `includes/config.php:66-97`

### 5.3 Example

With 10 condenseur points in oxygen and 100 oxygen atoms:

```
attack = round((1 + (0.1 * 100)^2 + 100) * (1 + 10/50))
       = round((1 + 100 + 100) * 1.2)
       = round(201 * 1.2)
       = round(241.2)
       = 241
```

Without condenseur points (niveau = 0):

```
attack = round((1 + 100 + 100) * (1 + 0/50))
       = round(201 * 1.0)
       = 201
```

---

## 6. Molecule Decay

Molecules slowly disappear over time via exponential decay. Larger molecules
(more total atoms) decay faster.

### 6.1 Decay Coefficient

**Function:** `coefDisparition($joueur, $classeOuNbTotal, $type = 0)`
**Source:** `includes/formulas.php:167-198`

The per-second decay coefficient is:

```php
pow(
    pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR),
    (1 - ($bonus / 100)) * (1 - ($stabilisateur * STABILISATEUR_BONUS_PER_LEVEL))
)
```

Substituting constants (`config.php:103-106`):

```
coefDisparition = pow(
    pow(0.99, pow(1 + totalAtoms / 100, 2) / 25000),
    (1 - medalBonus / 100) * (1 - stabilisateur_level * 0.01)
)
```

Where:
- `totalAtoms` = sum of all 8 atom counts in the molecule
- `medalBonus` = Pertes medal tier bonus (`[1, 3, 6, 10, 15, 20, 30, 50]`)
- `stabilisateur_level` = level of the stabilisateur building
- `STABILISATEUR_BONUS_PER_LEVEL` = 0.01 (1% decay reduction per level)

The `$type` parameter controls how `totalAtoms` is determined:
- `$type = 0` (default): looks up the molecule class from the database and sums all atom counts
- `$type = 1`: uses `$classeOuNbTotal` directly as the total atom count

**Source:** `includes/formulas.php:174-196`

### 6.2 How Decay Is Applied

During `updateRessources`, for each molecule class:

```php
$moleculesRestantes = pow(coefDisparition($joueur, $classNum), $nbsecondes)
                      * $molecules['nombre'];
```

**Source:** `includes/game_resources.php:153`

This means after `t` seconds, the molecule count becomes:

```
nombre(t) = nombre(0) * coefDisparition ^ t
```

Since `coefDisparition` is always less than 1, the count decreases
exponentially over time.

### 6.3 Factors That Reduce Decay

**More atoms = faster decay.** The `pow(1 + totalAtoms/100, 2) / 25000` term
grows quadratically with molecule size. A molecule with 800 total atoms decays
significantly faster than one with 80.

**Stabilisateur building reduces decay.** Each stabilisateur level reduces the
effective decay exponent by 1%:

```
exponent *= (1 - stabilisateur_level * 0.01)
```

At stabilisateur level 50, decay is halved. At level 100, decay is eliminated
entirely (coefficient becomes 1.0).

**Source:** `includes/config.php:106,217`

**Pertes medal reduces decay.** The medal for molecules lost also reduces the
decay exponent:

```
exponent *= (1 - medalBonus / 100)
```

At Diamond Rouge tier (50% bonus), the exponent is halved.

These two reductions multiply together, so a stabilisateur level 50 combined
with a 50% medal bonus gives:

```
effective exponent = base_exponent * (1 - 0.50) * (1 - 0.50)
                   = base_exponent * 0.25
```

### 6.4 Half-Life Formula

**Function:** `demiVie($joueur, $classeOuNbTotal, $type = 0)`
**Source:** `includes/formulas.php:200-203`

The half-life (time in seconds for molecule count to halve) is:

```php
function demiVie($joueur, $classeOuNbTotal, $type = 0) {
    return round(log(0.5, 0.99) / log(coefDisparition($joueur, $classeOuNbTotal, $type), 0.99));
}
```

This simplifies mathematically to:

```
halfLife = log(0.5) / log(coefDisparition)
```

Both logarithms use base 0.99 in the PHP code, but since
`log_b(x) / log_b(y) = log(x) / log(y)` for any base `b`, the base cancels
out and the result is equivalent.

### 6.5 Decay Example

Consider a molecule with 200 total atoms, no stabilisateur, no medal bonus:

```
inner = pow(0.99, pow(1 + 200/100, 2) / 25000)
      = pow(0.99, pow(3, 2) / 25000)
      = pow(0.99, 9 / 25000)
      = pow(0.99, 0.00036)
      = 0.99999638...

coef  = pow(0.99999638, 1.0 * 1.0)
      = 0.99999638

halfLife = log(0.5) / log(0.99999638)
         ~ 191,400 seconds
         ~ 53.2 hours
```

Now with 800 total atoms:

```
inner = pow(0.99, pow(1 + 800/100, 2) / 25000)
      = pow(0.99, pow(9, 2) / 25000)
      = pow(0.99, 81 / 25000)
      = pow(0.99, 0.00324)
      = 0.99996745...

coef  = 0.99996745

halfLife = log(0.5) / log(0.99996745)
         ~ 21,300 seconds
         ~ 5.9 hours
```

Larger molecules decay roughly 9x faster in this example.

---

## Source File Reference

| File                         | Key Contents                                          |
|------------------------------|-------------------------------------------------------|
| `includes/config.php`        | All game constants (defines and arrays)               |
| `includes/constantesBase.php`| Legacy arrays: atom names, colors, medal thresholds   |
| `includes/game_resources.php`| `revenuEnergie`, `revenuAtome`, `updateRessources`    |
| `includes/formulas.php`      | All stat formulas, decay, half-life, storage          |
