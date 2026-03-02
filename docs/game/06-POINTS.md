# 06 -- Points, Rankings, and Medals

This document covers all scoring systems in TVLW: total points composition,
victory point awards, ranking views, and the medal/tier system.

---

## 1. Total Points Formula

A player's `totalPoints` is the sum of five independent contributors stored
in the `autre` database table:

```
totalPoints = constructionPoints
            + pointsAttaque(rawAttack)
            + pointsDefense(rawDefense)
            + pointsPillage(ressourcesPillees)
            + tradePoints
```

Each contributor is updated independently through different game actions.
The `ajouterPoints()` function in `includes/player.php:71` handles the first
four types. Trade points are calculated directly in `marche.php:198-210`.

### 1.1 Construction Points (type=0)

**Source:** Building upgrades via `augmenterBatiment()` in `includes/player.php:429`.

**Formula:** Each building level grants a fixed number of points defined in
`$BUILDING_CONFIG` (`includes/config.php:126-220`):

```
buildingPoints = points_base + floor(level * points_level_factor)
```

| Building       | points_base | points_level_factor | Typical Level 10 |
|----------------|-------------|---------------------|-------------------|
| generateur     | 1           | 0.1                 | 2                 |
| producteur     | 1           | 0.1                 | 2                 |
| depot          | 1           | 0.1                 | 2                 |
| champdeforce   | 1           | 0.075               | 1                 |
| ionisateur     | 1           | 0.075               | 1                 |
| condenseur     | 2           | 0.1                 | 3                 |
| lieur          | 2           | 0.1                 | 3                 |
| stabilisateur  | 3           | 0.1                 | 4                 |

Construction points accumulate over time and are stored in `autre.points`.
They contribute directly (1:1) to `totalPoints`.

**Code path:**
- `includes/player.php:76-81` -- `ajouterPoints($nb, $joueur, 0)` adds `$nb`
  to both `autre.points` and `autre.totalPoints`.

### 1.2 Attack Points (type=1)

**Source:** Combat resolution in `includes/combat.php:339`.

**Raw value:** The raw `pointsAttaque` field accumulates battle outcome points.
Each battle awards:

```php
battlePoints = min(COMBAT_POINTS_MAX_PER_BATTLE,
                   floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt(totalCasualties)))
```

- `COMBAT_POINTS_BASE` = 1 (`includes/config.php:242`)
- `COMBAT_POINTS_CASUALTY_SCALE` = 0.5 (`includes/config.php:243`)
- `COMBAT_POINTS_MAX_PER_BATTLE` = 20 (`includes/config.php:244`)

The winner receives `+battlePoints` raw; the loser receives `-battlePoints` raw.
Draws award zero to both sides. See `includes/combat.php:309-326`.

**Transformation to display/ranking points:**

```php
// includes/formulas.php:53-57
function pointsAttaque($pts) {
    if ($pts <= 0) return 0;
    return round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)));
}
```

- `ATTACK_POINTS_MULTIPLIER` = 3.0 (`includes/config.php:248`)
- Result: `round(3.0 * sqrt(rawAttackPoints))`

**Contribution to totalPoints:**

When raw attack points change, the delta applied to `totalPoints` is:

```
delta = pointsAttaque(newRaw) - pointsAttaque(oldRaw)
```

See `includes/player.php:83-87`.

### 1.3 Defense Points (type=2)

**Source:** Combat resolution in `includes/combat.php:341`.

Identical mechanic to attack points but for the defender.

**Transformation:**

```php
// includes/formulas.php:59-63
function pointsDefense($pts) {
    if ($pts <= 0) return 0;
    return round(DEFENSE_POINTS_MULTIPLIER * sqrt(abs($pts)));
}
```

- `DEFENSE_POINTS_MULTIPLIER` = 3.0 (`includes/config.php:249`)
- Result: `round(3.0 * sqrt(rawDefensePoints))`

**Contribution to totalPoints:**

```
delta = pointsDefense(newRaw) - pointsDefense(oldRaw)
```

See `includes/player.php:88-92`.

### 1.4 Pillage Points (type=3)

**Source:** Combat resolution in `includes/combat.php:340` (attacker gains)
and `includes/combat.php:342` (defender loses).

The raw value `ressourcesPillees` tracks the cumulative total of all resources
looted. When the attacker pillages, the total looted count is added; the
defender's count is reduced by the same amount.

**Transformation:**

```php
// includes/formulas.php:65-68
function pointsPillage($nbRessources) {
    return round(tanh($nbRessources / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
}
```

- `PILLAGE_POINTS_DIVISOR` = 100000 (`includes/config.php:339`)
- `PILLAGE_POINTS_MULTIPLIER` = 50 (`includes/config.php:340`)
- Result: `round(tanh(totalPillaged / 100000) * 50)`

The `tanh` function asymptotically approaches 1, so pillage points are
soft-capped at 50. At 100k resources pillaged the contribution is
approximately `round(tanh(1) * 50) = round(38.08) = 38` points.

**Contribution to totalPoints:**

```
delta = pointsPillage(newTotal) - pointsPillage(oldTotal)
```

See `includes/player.php:93-101`.

### 1.5 Trade Points

**Source:** Market buy transactions in `marche.php:198-210`.

Trade points are awarded only when a player **buys** resources on the market.
The energy spent on the purchase is added to `autre.tradeVolume`. Selling does
not award trade points (this prevents buy-sell cycling exploits).

**Formula:**

```php
// marche.php:203-204
$oldTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($oldVolume)));
$newTradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($newVolume)));
```

- `MARKET_POINTS_SCALE` = 0.05 (`includes/config.php:273`)
- `MARKET_POINTS_MAX` = 40 (`includes/config.php:274`)
- Result: `min(40, floor(0.05 * sqrt(cumulativeEnergySpent)))`

Trade points are hard-capped at 40. To reach the cap requires spending
640,000 energy on market buys (since `0.05 * sqrt(640000) = 40`).

The delta is added directly to `totalPoints` at `marche.php:207`.

---

## 2. Victory Points (End of Season)

Victory points are awarded at the end of each season based on player and
alliance rankings. They accumulate across seasons in `autre.victoires` and
`alliances.pointsVictoire`.

### 2.1 Player Victory Points

Defined in `includes/formulas.php:8-33` and documented in
`includes/config.php:297-319`.

| Rank     | Points | Formula                                  |
|----------|--------|------------------------------------------|
| 1        | 100    | Fixed                                    |
| 2        | 80     | Fixed                                    |
| 3        | 70     | Fixed                                    |
| 4        | 65     | `70 - (4 - 3) * 5`                      |
| 5        | 60     | `70 - (5 - 3) * 5`                      |
| 6        | 55     | `70 - (6 - 3) * 5`                      |
| 7        | 50     | `70 - (7 - 3) * 5`                      |
| 8        | 45     | `70 - (8 - 3) * 5`                      |
| 9        | 40     | `70 - (9 - 3) * 5`                      |
| 10       | 35     | `70 - (10 - 3) * 5`                     |
| 11       | 33     | `35 - (11 - 10) * 2`                    |
| 12       | 31     | `35 - (12 - 10) * 2`                    |
| 13       | 29     | `35 - (13 - 10) * 2`                    |
| 14       | 27     | `35 - (14 - 10) * 2`                    |
| 15       | 25     | `35 - (15 - 10) * 2`                    |
| 16       | 23     | `35 - (16 - 10) * 2`                    |
| 17       | 21     | `35 - (17 - 10) * 2`                    |
| 18       | 19     | `35 - (18 - 10) * 2`                    |
| 19       | 17     | `35 - (19 - 10) * 2`                    |
| 20       | 15     | `35 - (20 - 10) * 2`                    |
| 21-50    | 15..0  | `floor(15 - (rank - 20) * 0.5)`         |
| 51-100   | varies | `max(1, floor(15 - (rank - 20) * 0.15))`|
| 101+     | 0      | No points awarded                        |

**Detailed breakdown for ranks 21-50:**

| Rank | Points | Rank | Points | Rank | Points |
|------|--------|------|--------|------|--------|
| 21   | 14     | 31   | 9      | 41   | 4      |
| 22   | 14     | 32   | 9      | 42   | 4      |
| 23   | 13     | 33   | 8      | 43   | 3      |
| 24   | 13     | 34   | 8      | 44   | 3      |
| 25   | 12     | 35   | 7      | 45   | 2      |
| 26   | 12     | 36   | 7      | 46   | 2      |
| 27   | 11     | 37   | 6      | 47   | 1      |
| 28   | 11     | 38   | 6      | 48   | 1      |
| 29   | 10     | 39   | 5      | 49   | 0      |
| 30   | 10     | 40   | 5      | 50   | 0      |

**Detailed breakdown for ranks 51-100:**

For ranks 51-100, the formula `max(1, floor(15 - (rank - 20) * 0.15))` yields:

| Rank | Points | Rank | Points |
|------|--------|------|--------|
| 51   | 10     | 76   | 6      |
| 55   | 9      | 80   | 6      |
| 58   | 9      | 84   | 5      |
| 61   | 8      | 91   | 4      |
| 65   | 8      | 97   | 3      |
| 68   | 7      | 100  | 3      |
| 71   | 7      |      |        |

All ranks 51-100 receive at least 1 point (enforced by the `max(1, ...)` clause).

### 2.2 Alliance Victory Points

Defined in `includes/formulas.php:35-51` and `includes/config.php:322-333`.

| Rank  | Points | Formula      |
|-------|--------|--------------|
| 1     | 15     | Fixed        |
| 2     | 10     | Fixed        |
| 3     | 7      | Fixed        |
| 4     | 6      | `10 - 4`    |
| 5     | 5      | `10 - 5`    |
| 6     | 4      | `10 - 6`    |
| 7     | 3      | `10 - 7`    |
| 8     | 2      | `10 - 8`    |
| 9     | 1      | `10 - 9`    |
| 10+   | 0      | No points    |

Note: The condition at `formulas.php:46` is `$classement < 10` (strict less-than),
so rank 10 itself falls through to `return 0`.

---

## 3. Ranking Views

Rankings are displayed in `classement.php` with four sub-views selected by
the `sub` GET parameter.

### 3.1 Player Rankings (sub=0)

Default sort: `totalPoints` descending.

Sortable columns (via `clas` GET parameter, `classement.php:19-46`):

| clas value | ORDER BY column    | Column Header    |
|------------|--------------------|------------------|
| (default)  | `totalPoints`      | Points           |
| 0          | `batmax`           | (highest building level) |
| 1          | `victoires`        | Victoire         |
| 2          | `pointsAttaque`    | Attaque          |
| 3          | `pointsDefense`    | Defense          |
| 4          | `ressourcesPillees`| Pillage          |
| 5          | `points`           | Constructions    |

Displayed columns per row (`classement.php:185-192`):
- Rank
- Player name (with active/inactive color)
- Total Points (`autre.totalPoints`)
- Alliance tag
- Construction Points (`autre.points`)
- Attack Points (displayed as `pointsAttaque(autre.pointsAttaque)` -- the sqrt-transformed value)
- Defense Points (displayed as `pointsDefense(autre.pointsDefense)` -- the sqrt-transformed value)
- Resources Pillaged (`autre.ressourcesPillees` -- raw count, NOT the tanh-transformed value)
- Victory Points (`autre.victoires` + projected gain from current rank)

Pagination: 20 players per page. The logged-in player's page is auto-selected.

### 3.2 Alliance Rankings (sub=1)

Default sort: `pointstotaux` descending.

Sortable columns (`classement.php:273-297`):

| clas value | ORDER BY column     | Column Header    |
|------------|---------------------|------------------|
| (default)  | `pointstotaux`      | Points           |
| 1          | `totalConstructions` | Constructions   |
| 2          | `totalAttaque`      | Attaque          |
| 3          | `totalDefense`      | Defense          |
| 4          | `totalPillage`      | Pillage          |
| 5          | `pointsVictoire`    | Victoire         |

Alliance stats are the sum of all member stats, recalculated by
`recalculerStatsAlliances()` (`includes/player.php:631-652`) which is called
at the top of the alliance ranking view.

### 3.3 War Rankings (sub=2)

Sorted by total casualties `(pertes1 + pertes2)` descending. Shows completed
wars only (`fin != 0`). Columns: rank, adversaries (alliance tags), total
losses, duration in days, detail link.

### 3.4 Forum / Special Rankings (sub=3)

Default sort: `nbMessages` descending (from `autre` table).

Sortable columns (`classement.php:502-521`):

| clas value | ORDER BY column | Table   | Column Header |
|------------|-----------------|---------|---------------|
| (default)  | `nbMessages`    | `autre` | Reponses      |
| 0          | `bombe`         | `autre` | Bombe         |
| 1          | `troll`         | `membre`| Aleatoire     |

---

## 4. Medal System

Medals reward cumulative achievements. Each medal has 8 tiers, and reaching
a tier grants a percentage bonus applied to a specific game mechanic.

### 4.1 Medal Tiers

Defined in `includes/config.php:346` and `includes/constantesBase.php:22`.

| Tier | Name          | Bonus % | Image File          |
|------|---------------|---------|---------------------|
| 0    | Bronze        | 1       | medaillebronze.png  |
| 1    | Argent        | 3       | medailleargent.png  |
| 2    | Or            | 6       | medailleor.png      |
| 3    | Emeraude      | 10      | emeraude.png        |
| 4    | Saphir        | 15      | saphir.png          |
| 5    | Rubis         | 20      | rubis.png           |
| 6    | Diamant       | 30      | diamant.png         |
| 7    | Diamant Rouge | 50      | diamantrouge.png    |

Bonus array: `$bonusMedailles = [1, 3, 6, 10, 15, 20, 30, 50]`
(`includes/constantesBase.php:25`, `includes/config.php:353`).

### 4.2 Medal Reference

The medal display page is `medailles.php`. The medal list is built at
`medailles.php:34-43`, which defines all 10 medals, their tracked metrics,
threshold arrays, and bonus descriptions.

#### 4.2.1 Terreur

| Property    | Value                                            |
|-------------|--------------------------------------------------|
| Metric      | `nbattaques` (number of attacks launched)        |
| DB column   | `autre.nbattaques`                               |
| Thresholds  | 5, 15, 30, 60, 120, 250, 500, 1000              |
| Source      | `$paliersTerreur` (`constantesBase.php:29`, `config.php:365`) |
| Effect      | Modifies attack energy cost                       |
| Label       | "% de diminution du cout d'attaque"              |

**Mechanic:** Used in `attaquer.php:9-15`:

```php
$coutPourUnAtome = 0.15 * (1 + $bonus / 100);
```

Note: Despite the label saying "reduction", the formula multiplies by
`(1 + bonus/100)` which **increases** the cost. At Bronze (1%), cost becomes
`0.15 * 1.01 = 0.1515`. This appears to be a long-standing bug where the
formula should use `(1 - bonus/100)` instead.

| Tier          | Threshold | Bonus | Cost per atom |
|---------------|-----------|-------|---------------|
| None          | --        | 0%    | 0.1500        |
| Bronze        | 5         | 1%    | 0.1515        |
| Argent        | 15        | 3%    | 0.1545        |
| Or            | 30        | 6%    | 0.1590        |
| Emeraude      | 60        | 10%   | 0.1650        |
| Saphir        | 120       | 15%   | 0.1725        |
| Rubis         | 250       | 20%   | 0.1800        |
| Diamant       | 500       | 30%   | 0.1950        |
| Diamant Rouge | 1000      | 50%   | 0.2250        |

#### 4.2.2 Attaque (Attaquant)

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `pointsAttaque` (raw cumulative attack points)     |
| DB column   | `autre.pointsAttaque`                              |
| Thresholds  | 100, 1k, 5k, 20k, 100k, 500k, 2M, 10M            |
| Source      | `$paliersAttaque` (`constantesBase.php:30`, `config.php:368`) |
| Effect      | Boosts molecule attack stat                         |
| Label       | "% d'attaque supplementaire"                       |

**Mechanic:** Used in `includes/formulas.php:80-96`:

```php
function attaque($oxygene, $niveau, $joueur) {
    // ... medal lookup ...
    return round((1 + (0.1 * $oxygene)^2 + $oxygene) * (1 + $niveau / 50) * (1 + $bonus / 100));
}
```

The bonus multiplies the final molecule attack value by `(1 + bonus/100)`.

| Tier          | Threshold  | Attack Multiplier |
|---------------|------------|-------------------|
| None          | --         | x1.00             |
| Bronze        | 100        | x1.01             |
| Argent        | 1,000      | x1.03             |
| Or            | 5,000      | x1.06             |
| Emeraude      | 20,000     | x1.10             |
| Saphir        | 100,000    | x1.15             |
| Rubis         | 500,000    | x1.20             |
| Diamant       | 2,000,000  | x1.30             |
| Diamant Rouge | 10,000,000 | x1.50             |

#### 4.2.3 Defense (Defenseur)

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `pointsDefense` (raw cumulative defense points)    |
| DB column   | `autre.pointsDefense`                              |
| Thresholds  | 100, 1k, 5k, 20k, 100k, 500k, 2M, 10M            |
| Source      | `$paliersDefense` (`constantesBase.php:31`, `config.php:371`) |
| Effect      | Boosts molecule defense stat                        |
| Label       | "% de defense supplementaire"                      |

**Mechanic:** Used in `includes/formulas.php:98-114`:

```php
function defense($carbone, $niveau, $joueur) {
    // ... medal lookup ...
    return round((1 + (0.1 * $carbone)^2 + $carbone) * (1 + $niveau / 50) * (1 + $bonus / 100));
}
```

Thresholds and multipliers are identical to the Attaque medal.

#### 4.2.4 Pillage (Pilleur)

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `ressourcesPillees` (cumulative resources looted)  |
| DB column   | `autre.ressourcesPillees`                          |
| Thresholds  | 1k, 10k, 50k, 200k, 1M, 5M, 20M, 100M            |
| Source      | `$paliersPillage` (`constantesBase.php:32`, `config.php:374`) |
| Effect      | Boosts molecule pillage capacity                    |
| Label       | "% de pillage supplementaire"                      |

**Mechanic:** Used in `includes/formulas.php:126-142`:

```php
function pillage($soufre, $niveau, $joueur) {
    // ... medal lookup ...
    return round(((0.1 * $soufre)^2 + $soufre / 3) * (1 + $niveau / 50) * (1 + $bonus / 100));
}
```

| Tier          | Threshold    | Pillage Multiplier |
|---------------|--------------|--------------------|
| None          | --           | x1.00              |
| Bronze        | 1,000        | x1.01              |
| Argent        | 10,000       | x1.03              |
| Or            | 50,000       | x1.06              |
| Emeraude      | 200,000      | x1.10              |
| Saphir        | 1,000,000    | x1.15              |
| Rubis         | 5,000,000    | x1.20              |
| Diamant       | 20,000,000   | x1.30              |
| Diamant Rouge | 100,000,000  | x1.50              |

#### 4.2.5 Pertes

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `moleculesPerdues` (cumulative molecules lost)     |
| DB column   | `autre.moleculesPerdues`                           |
| Thresholds  | 10, 100, 500, 2k, 10k, 50k, 200k, 1M              |
| Source      | `$paliersPertes` (`constantesBase.php:34`, `config.php:380`) |
| Effect      | Reduces molecule decay/disappearance rate           |
| Label       | "% de stabilisation des molecules"                 |

**Mechanic:** Used in `includes/formulas.php:167-197` (`coefDisparition`):

```php
$coef = pow(
    pow(DECAY_BASE, pow(1 + nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR),
    (1 - ($bonus / 100)) * (1 - ($stabilisateur * STABILISATEUR_BONUS_PER_LEVEL))
);
```

The bonus reduces the exponent by `(1 - bonus/100)`, slowing the decay rate.
At Diamant Rouge (50%), the decay exponent is halved, effectively doubling
molecule half-life.

| Tier          | Threshold  | Decay Exponent Factor |
|---------------|------------|-----------------------|
| None          | --         | 1.00                  |
| Bronze        | 10         | 0.99                  |
| Argent        | 100        | 0.97                  |
| Or            | 500        | 0.94                  |
| Emeraude      | 2,000      | 0.90                  |
| Saphir        | 10,000     | 0.85                  |
| Rubis         | 50,000     | 0.80                  |
| Diamant       | 200,000    | 0.70                  |
| Diamant Rouge | 1,000,000  | 0.50                  |

#### 4.2.6 Energievore

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `energieDepensee` (energy spent on constructions)  |
| DB column   | `autre.energieDepensee`                            |
| Thresholds  | 100, 500, 3k, 20k, 100k, 2M, 10M, 1B             |
| Source      | `$paliersEnergievore` (`constantesBase.php:35`, `config.php:383`) |
| Effect      | Reduces building costs                              |
| Label       | "% de production d'energie"                        |

**Mechanic:** The Energievore medal is referenced in the medal display as
providing an energy production bonus. Its actual gameplay effect is tied to
construction cost reduction along with the Constructeur medal -- both use
`$bonusMedailles` to reduce building upgrade costs. The label shown in
`medailles.php:39` is "% de production d'energie".

| Tier          | Threshold      | Bonus % |
|---------------|----------------|---------|
| None          | --             | 0       |
| Bronze        | 100            | 1       |
| Argent        | 500            | 3       |
| Or            | 3,000          | 6       |
| Emeraude      | 20,000         | 10      |
| Saphir        | 100,000        | 15      |
| Rubis         | 2,000,000      | 20      |
| Diamant       | 10,000,000     | 30      |
| Diamant Rouge | 1,000,000,000  | 50      |

#### 4.2.7 Constructeur

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `batmax` (highest building level across all types) |
| DB column   | `autre.batmax` (updated by `batMax()` in `player.php:601-619`) |
| Thresholds  | 5, 10, 15, 25, 35, 50, 70, 100                    |
| Source      | `$paliersConstructeur` (`constantesBase.php:36`, `config.php:386`) |
| Effect      | Reduces building costs                              |
| Label       | "% de reduction du cout des batiments"             |

**Mechanic:** Used in `includes/player.php:166-171` during `initPlayer()`:

```php
$bonus = 0;
foreach ($paliersConstructeur as $num => $palier) {
    if ($plusHaut >= $palier) {
        $bonus = $bonusMedailles[$num];
    }
}
```

This `$bonus` is then applied to all building cost calculations as
`(1 - bonus/100)` multiplier. For example, at `player.php:299`:

```php
round((1 - ($bonus / 100)) * $BUILDING_CONFIG['generateur']['cost_energy_base']
      * pow($niveauActuel['niveau'], $BUILDING_CONFIG['generateur']['cost_energy_exp']))
```

| Tier          | Threshold | Cost Multiplier |
|---------------|-----------|-----------------|
| None          | --        | x1.00           |
| Bronze        | 5         | x0.99           |
| Argent        | 10        | x0.97           |
| Or            | 15        | x0.94           |
| Emeraude      | 25        | x0.90           |
| Saphir        | 35        | x0.85           |
| Rubis         | 50        | x0.80           |
| Diamant       | 70        | x0.70           |
| Diamant Rouge | 100       | x0.50           |

#### 4.2.8 Pipelette

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `nbMessages` (forum messages/replies posted)       |
| DB column   | `autre.nbMessages` (count from `reponses` table used on display) |
| Thresholds  | 10, 25, 50, 100, 200, 500, 1000, 5000              |
| Source      | `$paliersPipelette` (`constantesBase.php:33`, `config.php:377`) |
| Effect      | Forum badge display only (no gameplay bonus)        |
| Label       | (empty string -- no bonus description)             |

The Pipelette medal uses `$bonusForum` (`constantesBase.php:26`) for display
instead of `$bonusMedailles`. The forum badges are:

```
insigne bronze, insigne argent, insigne or, insigne emeraude,
insigne saphir, insigne rubis, insigne diamant, insigne diamant rouge
```

This medal has no mechanical effect on gameplay.

#### 4.2.9 Bombe (Explosif)

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `bombe` (bomb game wins / buildings destroyed)     |
| DB column   | `autre.bombe`                                      |
| Thresholds  | 1, 2, 3, 4, 5, 6, 8, 12                            |
| Source      | `$paliersBombe` (`constantesBase.php:37`, `config.php:389`) |
| Effect      | Badge display only (no gameplay bonus)              |
| Label       | (no bonus description)                             |

The Bombe medal uses `$bonusTroll` (`constantesBase.php:27`) which is an array
of `"Rien"` (nothing) for all tiers. It tracks an achievement related to
building destruction or a mini-game, displayed in the forum/special ranking
view (`classement.php:556-575`) with custom images per tier.

#### 4.2.10 Troll (Aleatoire)

| Property    | Value                                              |
|-------------|----------------------------------------------------|
| Metric      | `troll` (randomly assigned value)                  |
| DB column   | `membre.troll`                                     |
| Thresholds  | 0, 1, 2, 3, 4, 5, 6, 7                             |
| Source      | `$paliersTroll` (`constantesBase.php:38`, `config.php:392`) |
| Effect      | Badge display only (no gameplay bonus)              |
| Label       | (no bonus description)                             |

The Troll medal is assigned randomly at registration (`includes/player.php:33-50`)
using weighted probability:

| Value | Probability | Tier Name     |
|-------|-------------|---------------|
| 0     | 50.0%       | Bronze        |
| 1     | 25.0%       | Argent        |
| 2     | 12.5%       | Or            |
| 3     | 6.0%        | Emeraude      |
| 4     | 3.0%        | Saphir        |
| 5     | 2.0%        | Rubis         |
| 6     | 1.0%        | Diamant       |
| 7     | 0.5%        | Diamant Rouge |

Since all thresholds start at 0 and increment by 1, every player immediately
qualifies for their assigned tier. This medal is purely cosmetic and cannot
be changed after registration.

### 4.3 Summary Table: All Medals

| #  | Medal Name   | DB Column          | Thresholds (8 tiers)                          | Gameplay Effect                        |
|----|--------------|--------------------|-------------------------------------------------|----------------------------------------|
| 1  | Terreur      | nbattaques         | 5, 15, 30, 60, 120, 250, 500, 1000             | Modifies attack energy cost (see note) |
| 2  | Attaquant    | pointsAttaque      | 100, 1k, 5k, 20k, 100k, 500k, 2M, 10M         | x(1+bonus/100) molecule attack         |
| 3  | Defenseur    | pointsDefense      | 100, 1k, 5k, 20k, 100k, 500k, 2M, 10M         | x(1+bonus/100) molecule defense        |
| 4  | Pilleur      | ressourcesPillees  | 1k, 10k, 50k, 200k, 1M, 5M, 20M, 100M         | x(1+bonus/100) molecule pillage        |
| 5  | Pertes       | moleculesPerdues   | 10, 100, 500, 2k, 10k, 50k, 200k, 1M           | x(1-bonus/100) decay exponent          |
| 6  | Energievore  | energieDepensee    | 100, 500, 3k, 20k, 100k, 2M, 10M, 1B           | Energy production bonus                |
| 7  | Constructeur | batmax             | 5, 10, 15, 25, 35, 50, 70, 100                 | x(1-bonus/100) building costs          |
| 8  | Pipelette    | nbMessages         | 10, 25, 50, 100, 200, 500, 1k, 5k              | Forum badge only                       |
| 9  | Explosif     | bombe              | 1, 2, 3, 4, 5, 6, 8, 12                        | Badge only                             |
| 10 | Aleatoire    | troll (in membre)  | 0, 1, 2, 3, 4, 5, 6, 7                         | Badge only (random at registration)    |

---

## 5. Source File Reference

| File                         | Lines     | Content                                     |
|------------------------------|-----------|---------------------------------------------|
| `includes/formulas.php`      | 8-33      | `pointsVictoireJoueur()` function           |
| `includes/formulas.php`      | 35-51     | `pointsVictoireAlliance()` function         |
| `includes/formulas.php`      | 53-57     | `pointsAttaque()` transformation            |
| `includes/formulas.php`      | 59-63     | `pointsDefense()` transformation            |
| `includes/formulas.php`      | 65-68     | `pointsPillage()` transformation            |
| `includes/formulas.php`      | 80-96     | `attaque()` with Attaque medal bonus        |
| `includes/formulas.php`      | 98-114    | `defense()` with Defense medal bonus        |
| `includes/formulas.php`      | 126-142   | `pillage()` with Pillage medal bonus        |
| `includes/formulas.php`      | 167-198   | `coefDisparition()` with Pertes medal bonus |
| `includes/player.php`        | 71-102    | `ajouterPoints()` for types 0-3             |
| `includes/player.php`        | 166-171   | Constructeur medal cost reduction           |
| `includes/player.php`        | 299-301   | Building cost with medal bonus applied      |
| `includes/player.php`        | 601-619   | `batMax()` for Constructeur medal metric    |
| `includes/combat.php`        | 304-326   | Battle point calculation and assignment      |
| `includes/combat.php`        | 339-342   | `ajouterPoints()` calls for combat stats    |
| `includes/config.php`        | 240-249   | Combat point constants and multipliers       |
| `includes/config.php`        | 271-274   | Market/trade point constants                 |
| `includes/config.php`        | 297-333   | Victory point definitions                    |
| `includes/config.php`        | 338-340   | Pillage point formula constants              |
| `includes/config.php`        | 344-393   | Medal tier names, bonuses, and thresholds    |
| `includes/constantesBase.php`| 22-38     | Legacy medal arrays (paliers and bonuses)    |
| `marche.php`                 | 198-210   | Trade point calculation on market buy        |
| `classement.php`             | 19-46     | Player ranking sort options                  |
| `classement.php`             | 136-198   | Player ranking table display                 |
| `classement.php`             | 273-297   | Alliance ranking sort options                |
| `classement.php`             | 304-359   | Alliance ranking table display               |
| `medailles.php`              | 34-43     | Medal list definition and display            |
| `attaquer.php`               | 9-15      | Terreur medal attack cost application        |
