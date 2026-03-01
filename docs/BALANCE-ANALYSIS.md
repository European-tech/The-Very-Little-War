# TVLW Game Balance Analysis

**Date:** 2026-03-02
**Scope:** Complete analysis of game formulas, combat, economy, and progression
**Files Analyzed:** `includes/fonctions.php`, `includes/constantesBase.php`, `includes/combat.php`, `marche.php`, `allianceadmin.php`, `constructions.php`, `attaquer.php`, `armee.php`, `alliance.php`

---

## Table of Contents

1. [Resource Production Curves](#1-resource-production-curves)
2. [Building ROI Analysis](#2-building-roi-analysis)
3. [Combat Balance](#3-combat-balance)
4. [Market Economics](#4-market-economics)
5. [Alliance Value](#5-alliance-value)
6. [Medal/Achievement Balance](#6-medalachievement-balance)
7. [Monthly Reset Impact](#7-monthly-reset-impact)
8. [New Player Experience](#8-new-player-experience)
9. [Critical Bugs Affecting Balance](#9-critical-bugs-affecting-balance)
10. [Priority Recommendations](#10-priority-recommendations)

---

## 1. Resource Production Curves

### 1.1 Energy Production (Generateur)

**Formula:** `revenuEnergie = 65 * niveau` (base, before bonuses)

| Generateur Level | Base Energy/h | With Duplicateur Lv5 (1.05x) | With Diamond Medal (1.50x) |
|-----------------|---------------|-------------------------------|---------------------------|
| 1               | 65            | 68                            | 97                        |
| 5               | 325           | 341                           | 487                       |
| 10              | 650           | 682                           | 975                       |
| 20              | 1,300         | 1,365                         | 1,950                     |
| 50              | 3,250         | 3,412                         | 4,875                     |

**Key formula chain:** `base * (1 + medalBonus/100) * duplicateurBonus - drainageProducteur`

**Drainage from Producteur:** `12 * producteurLevel` per hour
- At producteur level 10: -120/h energy
- At producteur level 20: -240/h energy
- At producteur level 50: -600/h energy

**Finding:** The linear scaling (65*N) makes energy production very predictable but unexciting. There is no acceleration curve -- a level 50 generator is exactly 50x a level 1. Combined with Producteur drainage (also linear at 12*N), the net energy income is `65*genLevel - 12*prodLevel`. This means the generator must always be significantly ahead of the producteur.

**PROBLEM:** A player with generator level 10 (650/h) and producteur level 10 (-120/h) nets 530/h. But a player with generator level 10 and producteur level 20 (-240/h) nets only 410/h -- the producteur is penalizing them more than it helps. Players must always keep the generator well ahead of the producteur, but there is no in-game guidance about this.

### 1.2 Atom Production (Producteur)

**Formula:** `revenuAtome = bonusDuplicateur * 30 * niveau` per hour (where `niveau` is the number of points allocated to that atom)

Each producteur level grants **8 points** (one per atom type, `sizeof($nomsRes) = 8`).

| Producteur Level | Total Points Available | If All on 1 Atom | Production/h |
|-----------------|----------------------|-------------------|-------------|
| 1               | 8                    | 8 points          | 240/h       |
| 5               | 40                   | 40 points         | 1,200/h     |
| 10              | 80                   | 80 points         | 2,400/h     |
| 20              | 160                  | 160 points        | 4,800/h     |
| 50              | 400                  | 400 points        | 12,000/h    |

**Finding:** Production is perfectly linear. There is zero incentive to spread points across atoms vs. concentrate on one -- the total production rate is identical either way. This makes the "point allocation" mechanic a false choice in terms of total throughput, though it does affect strategic flexibility.

### 1.3 Storage Capacity (Depot)

**Formula:** `placeDepot = 500 * niveau`

| Depot Level | Max Storage (per resource + energy) |
|------------|-------------------------------------|
| 1          | 500                                 |
| 5          | 2,500                               |
| 10         | 5,000                               |
| 20         | 10,000                              |
| 50         | 25,000                              |

### 1.4 Production:Storage Ratio

At generateur level 10, producing 650 energy/h, with depot level 10 (5,000 cap):
- Time to fill: 5,000 / 650 = **7.7 hours**

At generateur level 20, producing 1,300/h, with depot level 20 (10,000 cap):
- Time to fill: 10,000 / 1,300 = **7.7 hours**

**Finding:** Because both are linear, the fill time is constant at `500/65 = 7.69 hours` regardless of level. This means players always have the same window before they waste resources, which is good for consistency but means the storage building never becomes more or less urgent relative to production. The ratio is reasonably balanced for a game where players log in a few times per day.

**PROBLEM:** The atom production rate is much lower relative to storage. At producteur level 10 with 10 points in one atom (300/h) and depot level 10 (5,000 cap), fill time is 16.7 hours. But molecules can require hundreds of atoms each to form, meaning atom production is the real bottleneck -- a single molecule requiring 100 of one atom costs 100 atoms * N molecules. With 300/h production, forming 100 molecules of a 100-atom design takes 100*100/300 = 33 hours of production. This feels slow.

---

## 2. Building ROI Analysis

### 2.1 Building Cost Formulas

All building costs follow the pattern: `round((1 - medalBonus/100) * baseMultiplier * pow(level, exponent))`

| Building       | Energy Cost Formula            | Atom Cost Formula             | Time Formula                        |
|---------------|-------------------------------|-------------------------------|-------------------------------------|
| Generateur    | `50 * level^0.4`             | `75 * level^0.4` (all atoms) | `60 * level^1.5` sec               |
| Producteur    | `75 * level^0.4`             | `50 * level^0.4` (all atoms) | `40 * level^1.5` sec               |
| Depot         | `100 * level^0.4`            | None                          | `80 * level^1.5` sec               |
| Champ de Force| None                          | `100 * level^0.4` (Carbone)  | `20 * (level+2)^1.7` sec           |
| Ionisateur    | None                          | `100 * level^0.4` (Oxygene)  | `20 * (level+2)^1.7` sec           |
| Condenseur    | `25 * level^0.6`             | `100 * level^0.6` (all atoms)| `120 * (level+1)^1.8` sec          |
| Lieur         | None                          | `100 * level^0.6` (Azote)    | `100 * (level+1)^1.7` sec          |
| Stabilisateur | None                          | `75 * level^0.8` (all atoms) | `120 * (level+1)^1.7` sec          |

### 2.2 Cost at Key Levels

**Generateur costs (energy + all atoms each):**

| Level | Energy Cost | Atom Cost (each of 8) | Build Time   |
|-------|------------|----------------------|-------------|
| 2     | 66         | 99                   | 2m 50s      |
| 5     | 103        | 155                  | 11m 10s     |
| 10    | 126        | 189                  | 31m 37s     |
| 20    | 153        | 230                  | 1h 29m      |
| 50    | 194        | 291                  | 5h 53m      |

**CRITICAL FINDING:** The cost exponent is only 0.4, meaning costs grow very slowly. Level 50 costs only about 3x level 1. But the benefit (energy production) is linear, so each level adds exactly +65 energy/h. The ROI per level is essentially constant, which means there is never a "sweet spot" or a "too expensive" level. This makes the progression feel flat.

### 2.3 Building Tier List

**Must-have (S-tier):**
- **Generateur** - Energy funds everything. Without it, nothing happens. Cost is low, benefit is linear, always worth upgrading.
- **Depot** - Storage caps all resources equally. If you don't upgrade this, you waste production. The fill time of ~7.7h means you need this to match your generator.

**Strong (A-tier):**
- **Producteur** - Provides atoms needed for everything. The 8-points-per-level system is generous. However, the energy drainage of 12*level/h can hurt early.
- **Condenseur** - Boosts atom effectiveness in molecules. 3 points per level. Very powerful for combat optimization. Higher cost exponent (0.6) means it's slightly more expensive to level.

**Situational (B-tier):**
- **Ionisateur** - +2% attack per level. Only matters for attackers. At level 10, that's +20% attack -- significant but not game-changing.
- **Champ de force** - +2% defense per level AND acts as building HP shield. Dual purpose makes it valuable for defense.
- **Lieur** - Reduces molecule formation time by `floor(100 * 1.07^level) / 100`. At level 10: 1.96x speed. At level 20: 3.86x speed. Very powerful for army rebuilding.

**Trap (C-tier):**
- **Stabilisateur** - Reduces molecule decay by 0.5% per level. The decay formula is incredibly complex (`pow(pow(0.99, pow(1+nbAtomes/100, 2)/5000), (1-bonus/100)*(1-stabilisateur*0.005))`), and for small molecules the decay is negligible. This building only matters for very large, expensive molecules. Cost exponent of 0.8 is the highest of any building.

**PROBLEM:** The stabilisateur has the highest cost scaling but the most situational benefit. Most players will never need it until very late game, but by then the cost may be prohibitive.

### 2.4 Build Time Pacing

| Level | Generateur | Producteur | Depot    | Condenseur | Lieur     |
|-------|-----------|-----------|---------|-----------|----------|
| 5     | 11m       | 7.5m      | 15m     | 23m       | 13m      |
| 10    | 32m       | 21m       | 42m     | 2h 22m    | 1h 12m   |
| 20    | 1h 29m    | 60m       | 2h      | 16h 39m   | 7h 31m   |
| 50    | 5h 53m    | 3h 55m    | 7h 51m  | 7d 16h    | 2d 21h   |

**Finding:** The basic economic buildings (gen/prod/depot) have gentle time curves (exponent 1.5), while military/upgrade buildings (condenseur/lieur/stabilisateur) have steeper curves (1.7-1.8). This creates a natural pacing where economy builds fast and military takes longer, which is generally good design. However, the gap becomes extreme: at level 50, a generateur takes 6 hours but a condenseur takes over a week.

**PROBLEM:** The 2-construction queue limit combined with steep military building times means military progression feels very slow in mid-to-late game. A player building a level 20 condenseur (16h) blocks their queue for a long time.

---

## 3. Combat Balance

### 3.1 Atom Stat Formulas

Each molecule has up to 200 of each of the 8 atom types. The stat formulas per molecule are:

| Atom     | Stat           | Formula                                                       |
|----------|---------------|---------------------------------------------------------------|
| Carbone  | Defense        | `(1 + (0.1*C)^2 + C) * (1 + condenseurLevel/50) * (1+medal/100)` |
| Oxygene  | Attack         | `(1 + (0.1*O)^2 + O) * (1 + condenseurLevel/50) * (1+medal/100)` |
| Brome    | HP             | `(1 + (0.1*Br)^2 + Br) * (1 + condenseurLevel/50)`           |
| Hydrogene| Building Dmg   | `((0.075*H)^2 + H) * (1 + condenseurLevel/50)`               |
| Soufre   | Pillage        | `((0.1*S)^2 + S/3) * (1 + condenseurLevel/50) * (1+medal/100)` |
| Chlore   | Speed          | `(1 + 0.5*Cl) * (1 + condenseurLevel/50)`                    |
| Azote    | Formation Time | `total / (1 + (0.09*N)^1.09) / (1+condenseurLevel/20) / lieurBonus` |
| Iode     | Energy Prod    | `0.01 * I * (1 + condenseurLevel/50)`                        |

### 3.2 Attack vs. Defense Formula Comparison

Attack (Oxygene) and Defense (Carbone) share the **exact same formula**: `(1 + (0.1*x)^2 + x) * multipliers`

| Atoms | Raw Stat | % Increase per atom |
|-------|---------|-------------------|
| 10    | 12      | --                |
| 20    | 25      | 108%              |
| 50    | 76      | 204%              |
| 100   | 201     | 164%              |
| 150   | 376     | 87%               |
| 200   | 601     | 60%               |

The quadratic component `(0.1*x)^2` means going from 0 to 200 in one stat gives 601 value, while splitting 200 across two stats gives: `(1 + (0.1*100)^2 + 100) * 2 = 402`. **Concentrating atoms in one stat is always superior** due to the quadratic term.

### 3.3 Optimal Molecule Designs

Given 200 max per atom and the quadratic scaling, the dominant strategies are:

**Pure Attacker:** O=200, Br=200 (200 remaining split among utility)
- Attack: 601 * multipliers per molecule
- HP: 601 * multipliers per molecule
- With condenseur level 10: Attack = 601 * 1.2 = 721; HP = 721

**Pure Defender:** C=200, Br=200 (200 remaining split among utility)
- Defense: 601 * multipliers per molecule
- HP: 601 * multipliers per molecule

**CRITICAL FINDING: Attack and Defense are symmetric.** The attacker's Oxygene damage is compared to defender's Brome HP, and the defender's Carbone damage is compared to attacker's Brome HP. Because both formulas are identical, and both sides invest in Brome for HP, pure attack and pure defense are equally strong per atom invested.

**The decisive factor is army size** -- whoever has more molecules wins. There is no rock-paper-scissors, no counter-play, no interesting composition decisions once you realize this.

### 3.4 Front-Line System (Class 1 Takes Damage First)

From `combat.php`, damage is applied sequentially to classes 1 through 4:

```
for ($i = 1; $i <= 4; $i++) {
    if (classeAttaquant[$i]['nombre'] > 0 and degatsUtilises < degatsDefenseur) {
        classe_i_AttaquantMort = floor((degatsDefenseur - degatsUtilises) / HP_per_molecule);
        ...
    }
}
```

**Analysis:** Class 1 absorbs all damage first. If class 1 is wiped, remaining damage rolls to class 2, then 3, then 4. This creates a clear optimal strategy:

- **Class 1:** Pure Brome (HP tank) -- absorbs all damage, acts as meat shield
- **Class 2-4:** Pure attack/utility -- never take damage if class 1 is big enough

**DEGENERATE:** The front-line system is completely solved. There is exactly one optimal composition:
1. Class 1: Br=200 (+ maybe C for defense stat if defending)
2. Class 2: O=200 (pure attack)
3. Class 3: H=200 (building destruction) or S=200 (pillage)
4. Class 4: Whatever utility is needed (Cl for speed, I for energy)

There is no reason to ever mix stats across classes because damage only hits the front line. This makes the 4-class system feel like it has depth, but in practice it is completely solved.

### 3.5 Attacker vs. Defender Advantage

**Attacker advantages:**
- Ionisateur building: +2% attack per level
- Terreur medal: reduces attack energy cost by up to 50%
- Can choose when and who to attack
- Pillage (Soufre) steals resources
- Hydrogene destroys buildings

**Defender advantages:**
- Champ de force building: +2% defense per level
- Defender's molecules don't decay during travel
- All molecules defend (attacker must choose which to send)
- Champ de force absorbs building damage first if highest-level building

**Finding:** The game is **heavily attacker-favored** because:
1. The attacker can spy first (neutrinos) and only attack when they have advantage
2. The attacker's molecules decay during travel (but this is minor for nearby targets)
3. Successful attacks yield pillage + building destruction -- the defender loses doubly
4. The defender gets no resource gain from successful defense, only "not losing"
5. Attack and defense points function identically in rankings

**The cost to attack** is `0.15 * totalAtoms * numberOfMolecules * (1 - terrorMedal/100)` in energy. This is very cheap relative to the potential pillage gain.

### 3.6 BUG in Combat: Duplicateur Applied Differently

**In `fonctions.php` (resource production):**
```php
$bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
// bonusDuplicateur($niveau) = $niveau / 100
// So level 10 = 1 + 0.10 = 1.10 (10% bonus)
```

**In `combat.php`:**
```php
$bonusDuplicateurAttaque = 1 + ((0.1 * $duplicateurAttaque['duplicateur']) / 100);
// So level 10 = 1 + (0.1*10)/100 = 1 + 0.01 = 1.01 (1% bonus!)
```

**CRITICAL BUG:** The duplicateur gives a 10x different bonus in combat vs. resource production. At duplicateur level 10:
- Resource production: +10% bonus
- Combat stats: +1% bonus

This means the duplicateur is effectively useless in combat. The formula in `combat.php` has an extra `0.1 *` multiplier that makes it 10x weaker.

---

## 4. Market Economics

### 4.1 Price Mechanics

**Buy formula (price increase):**
```php
$newPrice = $currentPrice + $volatilite * $amountBought / $placeDepot;
```

**Sell formula (price decrease):**
```php
$newPrice = 1 / (1/$currentPrice + $volatilite * $amountSold / $placeDepot);
```

Where `$volatilite = 0.3 / $activePlayerCount`

### 4.2 Price Sensitivity Analysis

With 10 active players: volatilite = 0.03
With depot level 10 (placeDepot = 5,000):

**Buying 1,000 atoms (starting price = 1.0):**
- Price change: `0.03 * 1000 / 5000 = 0.006`
- New price: 1.006 (+0.6%)

**Buying 5,000 atoms:**
- Price change: `0.03 * 5000 / 5000 = 0.03`
- New price: 1.03 (+3%)

**Selling 1,000 atoms (starting price = 1.0):**
- New price: `1 / (1/1.0 + 0.03 * 1000/5000) = 1 / 1.006 = 0.994` (-0.6%)

### 4.3 Market Manipulation

**CRITICAL VULNERABILITY:** With few active players (e.g., 3 players), volatilite = 0.1. A player with depot level 20 (placeDepot = 10,000) buying 10,000 of one resource:

- Price change: `0.1 * 10000 / 10000 = 0.1`
- Price goes from 1.0 to 1.1 (+10%)

The sell formula is asymmetric (harmonic mean behavior), so selling doesn't drop prices as fast. A player can:
1. Buy a resource cheaply, driving price up 10%
2. Buy more as an "investment"
3. Sell back at inflated price

With only 3 players, a single player controls ~33% of market volume. This is definitely exploitable.

**PROBLEM:** The price formula has no mean-reversion mechanism. Prices can drift arbitrarily high or low over time if trading is one-directional. There is no NPC market maker or price floor/ceiling.

### 4.4 Transfer System Exploit

**Transfer formula:**
```php
$rapportRessource = min(1, recipientRevenue / senderRevenue);
$received = $sent * $rapport;
```

Resources received are scaled by the ratio of the recipient's production to the sender's production. If the sender produces more, the recipient gets less than sent.

**Exploit:** Two accounts (or colluding players) can:
1. Account A has high production (level 20 producteur)
2. Account B has low production (level 5 producteur)
3. A sends to B: B receives at ratio of 5/20 = 0.25 -- loses 75%
4. B sends to A: A receives at ratio of 20/5 = 1.0 -- loses nothing

This means high-production players can receive transfers at full value from low-production players. The system punishes giving to weaker players but doesn't penalize giving to stronger ones. This is backwards from the intended anti-abuse design.

**Same-IP restriction** (`$ipmm['ip'] != $ipdd['ip']`) prevents same-device multi-accounting but is trivially bypassed with a VPN.

---

## 5. Alliance Value

### 5.1 Duplicateur Cost and Benefit

**Cost formula:** `10 * pow(2.5, level + 1)` energy from alliance pool

| Level | Cost (Energy) | Cumulative Cost | Resource Bonus | Combat Bonus (bugged) |
|-------|-------------|----------------|---------------|----------------------|
| 1     | 63          | 63             | +1%           | +0.1%                |
| 2     | 156         | 219            | +2%           | +0.2%                |
| 3     | 391         | 610            | +3%           | +0.3%                |
| 5     | 2,441       | 4,004          | +5%           | +0.5%                |
| 10    | 95,367      | 131,057        | +10%          | +1.0%                |
| 15    | 3,725,290   | --             | +15%          | +1.5%                |

**Finding:** The duplicateur cost grows exponentially (base 2.5) while the benefit grows linearly (+1% per level). This means ROI deteriorates rapidly:

- Level 1: 63 energy for +1% = 63 energy per 1% bonus
- Level 5: 2,441 energy for +1% additional = 2,441 energy per 1% bonus
- Level 10: 95,367 energy for +1% additional = 95,367 energy per 1% bonus

**VERDICT:** Levels 1-5 are worthwhile (modest cost, meaningful bonus). Levels 6+ are increasingly wasteful. Level 10+ is almost never worth the investment during a monthly reset cycle.

**DOUBLE PROBLEM:** Due to the combat.php bug, the duplicateur's combat bonus is 10x weaker than intended. Fixing the bug would make higher levels significantly more attractive.

### 5.2 Alliance War Mechanics

Wars are declared unilaterally and track losses on both sides. There is **no mechanical incentive** to declare war -- it is purely social/political. Wars can be ended unilaterally by the declarer.

**Finding:** Alliance wars have zero gameplay impact beyond tracking losses. They don't:
- Grant bonus points for killing enemies
- Provide special pillage rates
- Enable territory control
- Affect rankings

Wars are functionally just a label. This is a missed opportunity for depth.

### 5.3 Alliance Victory Points

```php
function pointsVictoireAlliance($classement) {
    if ($classement == 1) return 15;
    if ($classement == 2) return 10;
    if ($classement == 3) return 7;
    if ($classement < 10) return 10 - $classement;
    return 0;
}
```

Only top 9 alliances get victory points. The distribution is very flat (15/10/7/6/5/4/3/2/1), which doesn't reward dominance heavily.

---

## 6. Medal/Achievement Balance

### 6.1 Medal Tracks (10 total)

All medals share the same bonus progression: `[1%, 3%, 6%, 10%, 15%, 20%, 30%, 50%]`

| Medal Track    | Tiers                                                      | What it Boosts          |
|---------------|-------------------------------------------------------------|------------------------|
| Terreur       | 5, 15, 30, 60, 120, 250, 500, 1000 attacks                | Attack energy cost      |
| Attaque       | 100, 1K, 5K, 20K, 100K, 500K, 2M, 10M attack pts          | Attack stat             |
| Defense       | 100, 1K, 5K, 20K, 100K, 500K, 2M, 10M defense pts         | Defense stat            |
| Pillage       | 1K, 10K, 50K, 200K, 1M, 5M, 20M, 100M resources pillaged  | Pillage capacity        |
| Pipelette     | 10, 25, 50, 100, 200, 500, 1K, 5K forum posts              | Forum rank (cosmetic)   |
| Pertes        | 10, 100, 500, 2K, 10K, 50K, 200K, 1M molecules lost        | Decay reduction         |
| Energievore   | 100, 500, 3K, 20K, 100K, 2M, 10M, 1B energy spent          | Energy production       |
| Constructeur  | 5, 10, 15, 25, 35, 50, 70, 100 highest building level       | Building cost reduction |
| Bombe         | 1, 2, 3, 4, 5, 6, 8, 12 -- unclear trigger                 | Unknown                 |
| Troll         | 0, 1, 2, 3, 4, 5, 6, 7 -- unknown                          | Unknown (bonus=[0..0])  |

### 6.2 Analysis of Track Values

**Overpowered:**
- **Terreur (attack count):** Reduces attack energy cost. At Diamond Rouge (1000 attacks, +50%), attacks cost half energy. Combined with the already cheap attack cost (0.15 * atoms per molecule), this makes attacking essentially free for active players.
- **Constructeur:** Reduces building costs by up to 50%. Building costs are already low (exponent 0.4), so this makes mid-game buildings trivially cheap.

**Well-balanced:**
- **Attaque/Defense:** Tier thresholds scale exponentially, making higher tiers genuinely hard to reach. The +50% bonus at Diamond Rouge is strong but requires 10M points.
- **Energievore:** The 1 billion energy threshold for Diamond Rouge is essentially unreachable in a monthly cycle, making top tiers aspirational.

**Underpowered:**
- **Pertes (molecules lost):** Reduces decay rate. But decay is already negligible for small molecules. This medal only matters for players with very large, expensive armies -- exactly the players who least need help.
- **Pipelette (forum posts):** Pure cosmetic. No gameplay impact. This is fine for a social feature but doesn't count as a "balanced" medal track.
- **Troll:** Has `$bonusTroll = ['Rien',...,'Rien']` -- literally does nothing. This is either unimplemented or intentionally cosmetic, but it's confusing that it exists as a medal track.

### 6.3 Threshold Reasonableness

**Terreur thresholds are too easy:** 1000 attacks for the maximum bonus is achievable in a single monthly cycle by an active player making 3-4 attacks per day for 30 days = ~100 attacks (Silver tier). A very active player can hit Gold (30 attacks) within a week.

**Pillage thresholds are too high:** 100M resources pillaged for Diamond Rouge requires massive sustained raiding. Given that a typical raid might yield 1,000-10,000 resources, this requires 10,000-100,000 successful raids.

---

## 7. Monthly Reset Impact

### 7.1 Reset Mechanics

The `remiseAZero()` function resets everything:
- All buildings to default (generateur=default, producteur=default, etc.)
- All resources to default
- All molecules to empty
- All alliance duplicateurs to 0
- All constructions, formations, attacks, trades deleted
- All messages and reports deleted
- All players removed from map (repositioned on next login)

**Victory points persist across resets** -- they are the long-term progression.

### 7.2 Victory Point Distribution

```php
function pointsVictoireJoueur($classement) {
    if ($classement == 1) return 100;
    if ($classement == 2) return 80;
    if ($classement == 3) return 70;
    if ($classement <= 10) return 70 - ($classement-3)*5;
    if ($classement <= 20) return 35 - ($classement-10)*2;
    if ($classement <= 50) return floor(15 - ($classement-20)*0.5);
    return 0;
}
```

| Rank | Player VP | Alliance VP | Total Possible |
|------|----------|------------|---------------|
| 1    | 100      | 15         | 115           |
| 2    | 80       | 10         | 90            |
| 3    | 70       | 7          | 77            |
| 5    | 60       | 5          | 65            |
| 10   | 35       | 1          | 36            |
| 20   | 15       | 0          | 15            |
| 50   | 0        | 0          | 0             |

**Finding:** The VP distribution is very top-heavy. Rank 1 gets 100 VP, rank 10 gets 35 VP, and rank 50+ gets nothing. This means:

1. Only the top ~20 players get meaningful VP each month
2. A player who wins 3 months in a row has 300 VP; a player who averages rank 10 for 10 months has 350 VP -- this is reasonably balanced
3. Players outside top 50 have zero long-term progression, which is devastating for new or casual players

**PROBLEM:** The threshold for earning ANY VP is rank 50. In a game with potentially hundreds of players, the majority earn nothing each month. This is deeply discouraging.

### 7.3 Endgame Dynamics

The complete reset means the last few days of each month have no strategic value -- everything is about to be wiped. This creates a "tragedy of the commons" where late-month activity drops.

**PROBLEM:** There is no "last stand" mechanic, no endgame event, no reason to play in the final days. The reset just happens and everything is gone.

---

## 8. New Player Experience

### 8.1 Beginner Protection

**Duration:** 2 days (172,800 seconds) from account creation timestamp.

During protection:
- Cannot be attacked
- Cannot attack others

### 8.2 Starting Resources

From `inscrire()`:
- Generateur: level 1 (65 energy/h)
- Depot: implicit level 1 (500 storage)
- All other buildings: default (0 or 1)
- 4 empty molecule slots
- Random map position

### 8.3 Time to First Meaningful Action

**Creating first molecule:**
- Need atoms to define molecule composition
- Need energy for molecule class cost: `pow(niveauClasse+1, 6)` = `pow(2,6) = 64 energy` for first class
- At 65 energy/h, takes ~1 hour to afford first molecule class
- Then need atoms to form molecules: need producteur + atom allocation

**First attack:**
- Need molecules with at least some Oxygene
- Need energy for attack cost (0.15 * total_atoms * count)
- Beginner protection ends after 2 days
- Realistic first attack: day 2-3

### 8.4 Catch-Up Mechanics

**There are none.** A player who joins mid-month faces opponents with:
- 2+ weeks of building upgrades
- Established armies
- Alliance bonuses
- Medal progress

The linear production formulas mean there is no catch-up mechanism. A player 2 weeks behind stays 2 weeks behind forever (within a monthly cycle).

**PROBLEM:** The 2-day protection is woefully insufficient for a game with monthly resets. By day 2, established players have generateurs at level 5-10+, while the new player has level 1-2. The gap is already insurmountable.

### 8.5 Molecule Class Unlock Cost

```php
function coutClasse($numero) {
    return pow($numero + 1, 6);
}
```

| Class # | niveauClasse | Cost (Energy) |
|---------|-------------|---------------|
| 1       | 1           | 64            |
| 2       | 2           | 729           |
| 3       | 3           | 4,096         |
| 4       | 4           | 15,625        |

**Finding:** The class unlock cost scales as `(N+1)^6`, which is extremely steep. The 4th class costs 15,625 energy. At level 10 generateur (650/h net), this takes 24 hours of pure energy saving. At level 5 (325/h), it takes 48 hours. This is a meaningful decision point.

**PROBLEM:** Class 4 is essential for optimal army composition (tank / attack / utility / utility), but costs 15,625 energy. New players in their first month may not unlock all 4 classes, putting them at a severe compositional disadvantage.

---

## 9. Critical Bugs Affecting Balance

### 9.1 Duplicateur Combat Bug (HIGH PRIORITY)

**Location:** `includes/combat.php` lines 49 and 56

**Current:** `1 + ((0.1 * $duplicateur) / 100)` -- gives 0.1% per level
**Should be:** `1 + ($duplicateur / 100)` -- gives 1% per level (matching resource production formula)

**Impact:** Alliance combat bonus is 10x weaker than intended. A level 10 duplicateur should give +10% combat stats but only gives +1%.

### 9.2 Stabilisateur Cost Uses Wrong Level (MEDIUM PRIORITY)

**Location:** `includes/fonctions.php` line 1293

```php
'coutAtomes' => round((1 - ($bonus / 100)) * 75 * pow($niveauActuelLieur['niveau'], 0.8)),
```

The stabilisateur uses `$niveauActuelLieur` (the LIEUR's level) instead of `$niveauActuelStabilisateur`. This means stabilisateur cost scales with the lieur's level, not its own.

### 9.3 Combat Damage Overflow Bug (LOW PRIORITY)

**Location:** `includes/combat.php` line 94

When class 1 is not fully killed, the code sets `$degatsUtilises = $degatsAttaquant` (line 94), which is the ATTACKER's damage, not the DEFENDER's remaining damage. This means if class 1 survives, classes 2-4 take no damage at all -- which is the intended behavior for the front-line system, but the variable naming is confusing and the logic is fragile.

### 9.4 Building Damage Targeting (MEDIUM PRIORITY)

**Location:** `includes/combat.php` lines 214-241

If `champdeforce` is the highest-level building, ALL hydrogen damage goes to it. Otherwise, damage is randomly distributed among 4 buildings. But there is a **missing break** on case 3 (line 235): producteur damage falls through to depot damage, meaning producteur never takes damage alone -- it always also damages depot.

```php
case 3:
    $degatsProducteur += $degatsAMettre;
// MISSING BREAK! Falls through to:
default:
    $degatsDepot += $degatsAMettre;
```

---

## 10. Priority Recommendations

### CRITICAL (Fix Immediately)

#### C1. Fix Duplicateur Combat Formula
**File:** `includes/combat.php` lines 49, 56
**Change:** `1 + ((0.1 * $duplicateurAttaque['duplicateur']) / 100)` --> `1 + ($duplicateurAttaque['duplicateur'] / 100)`
**Impact:** Makes alliance membership valuable for combat, encouraging social play.

#### C2. Fix Building Damage Fall-Through Bug
**File:** `includes/combat.php` line 235
**Change:** Add `break;` after `$degatsProducteur += $degatsAMettre;`
**Impact:** Producteur and depot take correct separate damage instead of always being hit together.

#### C3. Fix Stabilisateur Cost Variable
**File:** `includes/fonctions.php` line 1293
**Change:** `$niveauActuelLieur['niveau']` --> `$niveauActuelStabilisateur['niveau']`
**Impact:** Stabilisateur cost scales with its own level instead of lieur's level.

### HIGH PRIORITY (Balance Changes)

#### H1. Add Catch-Up Mechanics for New Players
**Current:** No catch-up mechanism exists.
**Recommendation:**
- Extend beginner protection to **5 days** (change `3600 * 24 * 2` to `3600 * 24 * 5`)
- Give new players a "boost period" where production is 2x for the first 3 days
- Or: Award new players starting resources proportional to the current month's day (e.g., joining day 15 gives bonus resources)

#### H2. Add Victory Points for Lower-Ranked Players
**Current:** Rank 50+ gets 0 VP.
**Recommendation:** Change formula to:
```php
if ($classement <= 100) return max(1, floor(15 - ($classement - 20) * 0.15));
```
This gives rank 100 at least 1 VP, ensuring all active players earn something.

#### H3. Market Price Mean-Reversion
**Current:** Prices can drift without bounds.
**Recommendation:** Add a decay-to-mean mechanism:
```php
// After each trade, pull price back toward 1.0
$newPrice = $newPrice * 0.99 + 1.0 * 0.01; // 1% mean reversion per trade
```
Also add price floor (0.1) and ceiling (10.0) to prevent extreme manipulation.

#### H4. Make Combat Composition More Interesting
**Current:** Optimal strategy is solved: Class 1 = pure tank, Class 2+ = pure damage.
**Recommendation Options:**
- **Option A:** Damage hits all classes proportionally to their count, not sequentially. This removes the pure-tank-in-front strategy.
- **Option B:** Add a "morale" system where losing your front line reduces all other classes' effectiveness by 20%.
- **Option C:** Add a "formation bonus" where having diverse stats within a single molecule class grants bonus effectiveness (e.g., +10% to all stats if molecule has at least 3 different atom types with 20+ atoms each).

### MEDIUM PRIORITY (Quality of Life)

#### M1. Make Producteur Drainage More Transparent
**Recommendation:** Show net energy income prominently: `"Net Energy: +530/h (650 base - 120 producteur drain)"`. The current UI shows only the gross production.

#### M2. Reduce Condenseur/Lieur Build Times
**Current:** Level 20 condenseur takes 16+ hours.
**Recommendation:** Reduce exponents from 1.8/1.7 to 1.5/1.5, matching economic buildings. Or increase the construction queue from 2 to 3.

#### M3. Add Alliance War Rewards
**Current:** Wars have no mechanical impact.
**Recommendation:**
- +10% pillage bonus when attacking a player in an alliance you're at war with
- +1 victory point per war won (alliance with fewer losses when war ends)
- War history visible in alliance profile

#### M4. Reduce Class 4 Unlock Cost
**Current:** 15,625 energy (24+ hours of saving at mid-game production).
**Recommendation:** `pow($numero + 1, 4)` instead of `pow($numero + 1, 6)`:
- Class 1: 16 energy
- Class 2: 81 energy
- Class 3: 256 energy
- Class 4: 625 energy

This makes all 4 classes accessible within the first few days.

#### M5. Add Endgame Event Before Monthly Reset
**Recommendation:** In the final 48 hours of each month:
- Double all combat points earned
- Enable "last stand" attacks that cost no energy
- Or add a special "boss" NPC target that all players can attack for bonus VP

### LOW PRIORITY (Nice to Have)

#### L1. Make Decay (Stabilisateur) More Impactful
**Current:** Decay is negligible for small molecules. The stabilisateur is a trap building.
**Recommendation:** Either remove decay entirely (and repurpose stabilisateur) or make decay significant enough that the stabilisateur is a real choice. Current formula is too complex for players to understand.

#### L2. Balance Atom Scaling Curves
**Current:** Attack, Defense, and HP all use `(0.1x)^2 + x`. Pillage uses `(0.1x)^2 + x/3`. Hydrogene uses `(0.075x)^2 + x`.
**Recommendation:** Differentiate the curves more:
- Attack: Keep quadratic for "burst" damage
- Defense: Make linear (`2*x`) for "reliable" tanking
- HP: Make logarithmic (`50*log(1+x)`) for diminishing returns
- This creates real tradeoffs in molecule design

#### L3. Transfer System Anti-Abuse
**Current:** Same-IP check only. Production-ratio scaling is backwards (helps strong players receive more).
**Recommendation:**
- Invert the ratio: `$received = $sent * min(1, senderRevenue / recipientRevenue)` -- senders to weaker players get full transfer, senders to stronger players get reduced transfer
- Add daily transfer cap per player (e.g., 20% of daily production)

#### L4. Make Iode (Energy Molecules) Viable
**Current:** `productionEnergieMolecule = 0.01 * iode * (1 + condenseurLevel/50)`
At 200 Iode with condenseur level 10: `0.01 * 200 * 1.2 = 2.4 energy per molecule per hour`
This is trivial compared to generator output (65+ per level).
**Recommendation:** Increase to `0.1 * iode * (1 + condenseurLevel/50)` -- making 200 Iode produce 24/h per molecule. With 1000 molecules, that's 24,000/h -- meaningful compared to a level 50 generator (3,250/h base). This makes Iode a true alternative energy source worth protecting.

---

## Summary of Key Findings

1. **Production curves are purely linear** -- no acceleration, no sweet spots, no interesting decisions about when to invest.
2. **Building costs scale too slowly** (exponent 0.4) relative to linear benefits -- every level is essentially equal ROI.
3. **Combat is solved** -- the front-line sequential damage system combined with identical attack/defense formulas means there is exactly one optimal composition.
4. **The market is manipulable** with few players and has no price stability mechanism.
5. **Alliance duplicateur is bugged** in combat (10x weaker than intended).
6. **New players have no catch-up path** and beginner protection is too short.
7. **Monthly VP favors top players exclusively** -- bottom 50% earn nothing.
8. **Several bugs** (fall-through in building damage, wrong variable in stabilisateur cost) directly affect balance.

The game has solid bones -- the chemistry theme is unique, the 8-atom-type system creates genuine complexity, and the monthly reset cycle provides natural seasons. But the math underneath is too uniform (linear everything) and too solved (one optimal strategy) to support deep strategic play. The recommendations above aim to add meaningful decisions, fix exploitation vectors, and ensure all player types have a reason to engage.
