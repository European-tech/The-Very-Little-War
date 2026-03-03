# 04 - Combat System

This document covers the full combat pipeline in TVLW: molecule design, stat formulas,
attack initiation, combat resolution, pillage, building damage, espionage, and neutrinos.

---

## 1. Molecule System

Each player has **4 molecule class slots**. A molecule class is a fixed recipe of 8 atom types.
Once designed, the recipe cannot be changed -- the class must be deleted and re-created.

### 1.1 Atom Types

The 8 atoms and their combat roles. In V4, every stat uses a **covalent synergy** formula
taking a primary atom and a secondary atom (see Section 2 for details):

| # | Atom      | Letter | Primary Role              | Secondary Role            | Source |
|---|-----------|--------|---------------------------|---------------------------|--------|
| 0 | Carbone   | C      | Defense                   | HP (secondary)            | `config.php:41` |
| 1 | Azote     | N      | Formation time reduction  | Speed (secondary)         | `config.php:41` |
| 2 | Hydrogene | H      | Building destruction      | Attack (secondary)        | `config.php:41` |
| 3 | Oxygene   | O      | Attack                    | Destruction (secondary)   | `config.php:41` |
| 4 | Chlore    | Cl     | Movement speed            | Pillage (secondary)       | `config.php:41` |
| 5 | Soufre    | S      | Pillage capacity          | --                        | `config.php:41` |
| 6 | Brome     | Br     | Hit points                | Defense (secondary)       | `config.php:41` |
| 7 | Iode      | I      | Energy production         | Formation time (secondary)| `config.php:41` |

**Max atoms per element:** 200 (`config.php:24`, `MAX_ATOMS_PER_ELEMENT`)

Validation is at `armee.php:161-165` -- each element is checked `<= 200`.

### 1.2 Class Unlock Cost

Each new class costs energy based on how many classes the player has already created.
The cost formula uses the `niveauclasse` counter (starts at 0 for a player with no classes):

```
cost = pow(niveauclasse + 1, 4)
```

- `formulas.php:217-221`: `coutClasse($numero) = pow($numero + 1, 4)`
- `config.php:293-295`: `CLASS_COST_EXPONENT = 4`, `CLASS_COST_OFFSET = 1`

| Classes owned | niveauclasse | Cost (energy) |
|---------------|--------------|---------------|
| 0             | 0            | 1             |
| 1             | 1            | 16            |
| 2             | 2            | 81            |
| 3             | 3            | 256           |

The energy is deducted at `armee.php:215-216`.

### 1.3 Class Deletion

Deleting a class (`armee.php:5-58`):
- Decrements `niveauclasse` by 1
- Resets the molecule row to defaults (formule, atom counts, nombre)
- Cancels any pending formation queue for that class
- Zeroes out that class's troops in any pending attacks

### 1.4 Isotope Variants (V4)

When creating a molecule class, the player chooses an isotope variant. This is an
irreversible specialization that modifies combat and decay performance.

| Isotope | Attack Mod | HP Mod | Decay Mod | Special |
|---------|-----------|--------|-----------|---------|
| Normal (0) | -- | -- | -- | No modification |
| Stable (1) | -10% | +20% | -30% (slower) | Tank/defender role |
| Reactif (2) | +20% | -10% | +20% (faster) | Glass cannon role |
| Catalytique (3) | -10% | -10% | -- | +15% attack and HP to all OTHER classes |

Source: `config.php:313-334`

The Catalytique isotope is a support class: it weakens itself but boosts every non-Catalytique
class in the player's army by `ISOTOPE_CATALYTIQUE_ALLY_BONUS = 0.15` (+15%) to both attack
and HP modifiers. Only one Catalytique class is needed to boost all other classes.

Isotope modifiers are applied in `combat.php:83-142` as per-class multipliers to attack
damage and HP during casualty calculation.

### 1.5 Formation Time

When queuing molecules for production, formation speed uses azote+iode covalent synergy:

```
tempsFormation(ntotal, azote, iode, nivCondN, nivLieur, joueur)

vitesse_form = (1 + pow(azote, 1.1) * (1 + iode / 200)) * modCond(nivCondN) * bonusLieur
time = ceil((ntotal / vitesse_form) * 100) / 100
```

Where:
- `ntotal` = sum of all 8 atom counts in the recipe
- `azote` = the Azote (N) atom count in the recipe
- `iode` = the Iode (I) atom count in the recipe (V4: secondary synergy atom)
- `nivCondN` = the player's condenseur-allocated level for Azote
- `modCond(niv) = 1 + niv / COVALENT_CONDENSEUR_DIVISOR` (universal condenseur modifier)
- `bonusLieur(level) = 1 + level * 0.15` (V4: **linear** bonus, not exponential)

If a `joueur` context is provided, additional multipliers apply:
- Catalyst speed bonus: `1 + catalystEffect('formation_speed')`
- Alliance Catalyseur research bonus: `1 + allianceResearchBonus(joueur, 'formation_speed')`

**Source:** `formulas.php:130-142`

**Config constants:**
- `COVALENT_CONDENSEUR_DIVISOR = 50` (`config.php:68`)
- `LIEUR_LINEAR_BONUS_PER_LEVEL = 0.15` (`config.php:144`)

The result is in seconds (can be fractional to 2 decimal places due to the ceil/100 rounding).

Formation is queued at `armee.php:107-119`. If there is already a queue, new formation starts
after the existing queue finishes (`armee.php:109-114`).

### 1.6 Molecule Decay

Molecules decay continuously over time. Every time a player's state is updated, the elapsed
seconds are applied as exponential decay:

```
remaining = pow(coefDisparition, elapsed_seconds) * current_count
```

**Decay coefficient formula** (`formulas.php:145-204`):

```
rawDecay = pow(DECAY_BASE, pow(1 + nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR)
modStab = pow(STABILISATEUR_ASYMPTOTE, stabilisateur_level)
modMedal = 1 - (medalBonus / 100)
baseDecay = pow(rawDecay, modStab * modMedal)
```

Additional V4 modifiers:
- **Catalyst decay:** If weekly catalyst `decay_increase` is active, `baseDecay = pow(baseDecay, 1.0 + effect)`
- **Isotope Stable:** `baseDecay = pow(baseDecay, 0.7)` (30% slower decay)
- **Isotope Reactif:** `baseDecay = pow(baseDecay, 1.2)` (20% faster decay)

Where:
- `nbAtomes` = total atoms in the molecule recipe
- `medalBonus` = bonus from the "Pertes" (losses) medal tier
- `stabilisateur_level` = player's stabilisateur building level

**Config constants:**
- `DECAY_BASE = 0.99` (`config.php:115`)
- `DECAY_ATOM_DIVISOR = 150` (`config.php:116`) -- V4: increased from 100, large molecules more viable
- `DECAY_POWER_DIVISOR = 25000` (`config.php:117`)
- `STABILISATEUR_BONUS_PER_LEVEL = 0.015` (`config.php:118`) -- V4: 1.5% per level (buffed from 1%)
- `STABILISATEUR_ASYMPTOTE = 0.98` (`config.php:119`) -- V4: `pow(0.98, level)`, never negative
- `DECAY_MASS_EXPONENT = 1.5` (`config.php:120`) -- V4: power 1.5 (was 2)

**Half-life** (`formulas.php:200-203`):
```
demiVie = round(log(0.5, 0.99) / log(coefDisparition, 0.99))
```
Returns the number of seconds until half the molecules have decayed.

**Decay during travel:** When molecules are sent on an attack, they also decay during
the outbound and return journeys. The same `coefDisparition` formula is used with the
travel time in seconds:
- Outbound: `game_actions.php:90` -- applied when combat resolves
- Return: `game_actions.php:413` -- applied when troops arrive home

Decay at home is applied in `game_resources.php` (for the target player during resource updates).

### 1.7 Neutrino Decay (V4)

Neutrinos now decay over time, treated as mass-1 molecules:

```
coefNeutrino = coefDisparition($joueur, 1, type=1)   // nbAtomes = 1
neutrinosRestants = floor(pow(coefNeutrino, elapsed_seconds) * current_neutrinos)
```

Source: `game_resources.php:207-215`

Neutrinos decay very slowly (mass-1 means minimal decay rate), but they are no longer
permanent. The decay rate is affected by the player's stabilisateur and loss medal as usual.

---

## 2. Molecule Stats Table (V4 Covalent Synergy)

In V4, ALL stat formulas use a **covalent synergy** system: each stat takes a **primary**
atom and a **secondary** atom. The universal pattern is:

```
base = (pow(primary, 1.2) + primary) * (1 + secondary / 100)
stat = round(base * modCond(nivCond) * medalMultiplier)
```

Where `modCond(niv) = 1 + niv / 50` (`formulas.php:74-77`).

**Key constants** (`config.php:68-71`):
- `COVALENT_BASE_EXPONENT = 1.2`
- `COVALENT_SYNERGY_DIVISOR = 100`
- `COVALENT_CONDENSEUR_DIVISOR = 50`
- `MOLECULE_MIN_HP = 10`

| Stat | Primary | Secondary | Function Signature | Formula | Source |
|------|---------|-----------|-------------------|---------|--------|
| Attack | O (Oxygene) | H (Hydrogene) | `attaque($O, $H, $nivCondO, $medal)` | `round((pow(O,1.2)+O) * (1+H/100) * modCond(nivO) * (1+medal/100))` | `formulas.php:84-88` |
| Defense | C (Carbone) | Br (Brome) | `defense($C, $Br, $nivCondC, $medal)` | `round((pow(C,1.2)+C) * (1+Br/100) * modCond(nivC) * (1+medal/100))` | `formulas.php:90-94` |
| HP | Br (Brome) | C (Carbone) | `pointsDeVieMolecule($Br, $C, $nivCondBr)` | `round((10 + (pow(Br,1.2)+Br) * (1+C/100)) * modCond(nivBr))` | `formulas.php:96-100` |
| Destruction | H (Hydrogene) | O (Oxygene) | `potentielDestruction($H, $O, $nivCondH)` | `round((pow(H,1.2)+H) * (1+O/100) * modCond(nivH))` | `formulas.php:102-106` |
| Pillage | S (Soufre) | Cl (Chlore) | `pillage($S, $Cl, $nivCondS, $medal)` | `round((pow(S,1.2)+S) * (1+Cl/100) * modCond(nivS) * (1+medal/100))` | `formulas.php:108-112` |
| Energy Prod | I (Iode) | -- | `productionEnergieMolecule($I, $niv)` | `round((0.003*I^2 + 0.04*I) * (1+niv/50))` | `formulas.php:114-117` |
| Speed | Cl (Chlore) | N (Azote) | `vitesse($Cl, $N, $nivCondCl)` | `max(1.0, floor((1 + Cl*0.5 + Cl*N/200) * modCond(nivCl) * 100) / 100)` | `formulas.php:119-123` |
| Formation | N (Azote) | I (Iode) | `tempsFormation($ntotal, $N, $I, $nivCondN, $nivLieur)` | `ceil((ntotal / ((1+pow(N,1.1)*(1+I/200)) * modCond(nivN) * bonusLieur)) * 100) / 100` | `formulas.php:130-142` |

### Synergy Design Notes

The covalent synergy creates **atom pair bonds** that incentivize diverse molecule designs:
- **Attack/Destruction** share O+H (but in opposite primary/secondary roles)
- **Defense/HP** share C+Br (but in opposite primary/secondary roles)
- **Pillage** uses S (primary) + Cl (secondary)
- **Speed** uses Cl (primary) + N (secondary)
- **Formation** uses N (primary) + I (secondary)

This means a pure-attack molecule (all O) is suboptimal -- adding some H gives a synergy bonus.

### Notes on Stats

- **`nivCond` (condenseur level):** Comes from condenseur point allocation. Each condenseur
  level grants 5 distributable points (`config.php:216`). Points are stored in a
  semicolon-separated string in `constructions.pointsProducteur`, one value per atom type.

- **Medal bonuses** for Attack, Defense, and Pillage are looked up from accumulated combat
  stats via `computeMedalBonus()` (`formulas.php:251-257`). The medal tiers and their
  bonus percentages are:

  | Tier | Name | Bonus % |
  |------|------|---------|
  | 0 | Bronze | 1% |
  | 1 | Argent | 3% |
  | 2 | Or | 6% |
  | 3 | Emeraude | 10% |
  | 4 | Saphir | 15% |
  | 5 | Rubis | 20% |
  | 6 | Diamant | 30% |
  | 7 | Diamant Rouge | 50% |

  Source: `config.php:498-499`

- **Medal bonus cap (V4):** `MAX_CROSS_SEASON_MEDAL_BONUS = 10` (`config.php:503`).
  All medal bonuses passed through `computeMedalBonus()` are capped at the Emeraude tier
  (10%). During the first 14 days of a new season, a grace period cap further limits
  bonuses to Gold tier (6%) (`config.php:504-505`).

- **Attack medal** thresholds (pointsAttaque): `[100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000]` (`config.php:520`)
- **Defense medal** thresholds (pointsDefense): `[100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000]` (`config.php:523`)
- **Pillage medal** thresholds (ressourcesPillees): `[1000, 10000, 50000, 200000, 1000000, 5000000, 20000000, 100000000]` (`config.php:526`)

- **HP has a minimum floor.** `MOLECULE_MIN_HP = 10` ensures even molecules with 0 Brome
  have at least 10 HP per molecule (prevents instant-wipe). Source: `formulas.php:98`.

- **Chemical reactions are REMOVED in V4.** There are no longer reaction bonuses between
  atom types. The old reaction system has been replaced by covalent synergy.

- **Speed** is in cases/hour. Minimum speed is 1.0. Travel time = `distance / speed * 3600`
  seconds (`attaquer.php:123`).

- **Energy Production** uses a quadratic+linear formula. At I=100: ~34 energy/molecule
  (V4 buff via `IODE_QUADRATIC_COEFFICIENT = 0.003`).

---

## 3. Combat Resolution

Combat resolution happens in `includes/combat.php`, which is included (not called as a
function) from `game_actions.php:102`. The file operates on the `$actions` array representing
a pending attack action.

### Step-by-step

#### Step 1: Load defender molecules
Load all 4 molecule classes for the defender with their current counts (`combat.php:5-13`).

#### Step 2: Load attacker molecules
Load all 4 molecule classes for the attacker. The counts come from the `troupes` field of
the attack action (semicolon-separated), not from the database -- because the troops were
already subtracted at launch time (`combat.php:15-24`).

#### Step 3: Load atom levels
Load condenseur-allocated levels for both attacker and defender from
`constructions.pointsProducteur` (`combat.php:28-38`).

#### Step 4: Load combat bonuses

- **Ionisateur** (attacker): `+2% per level` to attack (`combat.php:49`)
- **Champ de force** (defender): `+2% per level` to defense (`combat.php:55`)
- **Alliance Duplicateur** (attacker): `+1% per level` to attack and HP (`combat.php:62-66`)
- **Alliance Duplicateur** (defender): `+1% per level` to defense and HP (`combat.php:68-73`)
- **Medal bonuses (V4):** Pre-computed via `computeMedalBonus()` with
  `MAX_CROSS_SEASON_MEDAL_BONUS = 10` cap (`combat.php:76-81`)
- **Isotope modifiers (V4):** Per-class attack/HP multipliers based on isotope variant
  (Stable: -10% atk/+20% HP, Reactif: +20% atk/-10% HP, Catalytique: -10% both but
  +15% to other classes) (`combat.php:83-142`)
- **Defensive formation:** Defender's pre-selected formation affects damage distribution
  and defense bonuses (`combat.php:144-164`)

Config: `IONISATEUR_COMBAT_BONUS_PER_LEVEL = 2`, `CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL = 2` (`config.php:264-265`),
`DUPLICATEUR_BONUS_PER_LEVEL = 0.01` (`config.php:371`)

#### Step 5: Calculate total attack and defense damage

V4 uses the covalent synergy functions with both primary and secondary atoms,
plus isotope modifiers, formation bonuses, and catalyst bonuses:

```
catalystAttackBonus = 1 + catalystEffect('attack_bonus')
degatsAttaquant = 0
degatsDefenseur = 0
for each class c (1 to 4):
    degatsAttaquant += attaque(O_c, H_c, nivCondO, attackMedal)
                       * isotopeAttackMod[c]
                       * (1 + ionisateur * 2 / 100)
                       * bonusDuplicateurAttaque
                       * catalystAttackBonus
                       * count_c

    defBonus = isotopeAttackMod[c]
    if formation == PHALANGE and c == 1: defBonus *= (1 + 0.20)
    degatsDefenseur += defense(C_c, Br_c, nivCondC, defenseMedal)
                       * defBonus
                       * (1 + champdeforce * 2 / 100)
                       * bonusDuplicateurDefense
                       * count_c

// Post-multipliers
degatsAttaquant *= prestigeCombatBonus(attacker)
degatsDefenseur *= prestigeCombatBonus(defender)
if formation == EMBUSCADE and defenderMols > attackerMols:
    degatsDefenseur *= 1.15
```

Source: `combat.php:166-185`

`degatsAttaquant` is the total damage the attacker deals to the defender's molecules.
`degatsDefenseur` is the total damage the defender deals to the attacker's molecules.

Note: Medal bonuses are pre-computed via `computeMedalBonus()` which applies the
`MAX_CROSS_SEASON_MEDAL_BONUS = 10` cap (`combat.php:79-81`).

#### Step 6: Calculate attacker casualties (Overkill Cascade)

V4 uses a **sequential overkill cascade**: defender's total defense damage
(`degatsDefenseur`) is applied to the attacker's molecules class by class (1 to 4).
If damage exceeds a class's total HP pool, the surplus **carries forward** to the next
class. This replaces the old proportional system.

```
remainingDamage = degatsDefenseur
for class i = 1 to 4:
    if class_i has molecules AND remainingDamage > 0:
        hpPerMol = pointsDeVieMolecule(Br_i, C_i, nivCondBr)
                   * bonusDuplicateurAttaque * isotopeHpMod[i]
        if hpPerMol > 0:
            kills = min(count_i, floor(remainingDamage / hpPerMol))
            remainingDamage -= kills * hpPerMol
        else:
            kills = count_i   // 0 HP = all die instantly
    attaquantsRestants += count_i - kills
```

Source: `combat.php:188-204`

Key details:
- HP now uses covalent synergy: `pointsDeVieMolecule(Br, C, nivCondBr)` with a minimum
  floor of `MOLECULE_MIN_HP = 10`.
- Alliance duplicateur bonus AND isotope HP modifier both multiply HP for casualty
  calculation.
- Overkill surplus carries between classes -- a fragile class 1 does not "waste" damage.

#### Step 7: Calculate defender casualties (Formation-Aware Cascade)

Defender casualties use the same overkill cascade, but the damage distribution depends
on the defender's chosen **formation** (`combat.php:206-289`):

**Embuscade / Default** -- straight cascade through all 4 classes sequentially (same as
attacker casualties). Source: `combat.php:268-285`

**Dispersee** -- damage is split equally across active classes, with overkill from each
class cascading to the next:
```
sharePerClass = degatsAttaquant / activeDefClasses
for each active class:
    damageForClass = sharePerClass + overflow_from_previous
    kills = min(count, floor(damageForClass / hpPerMol))
    overflow = damageForClass - kills * hpPerMol
```
Source: `combat.php:244-267`

**Phalange** -- class 1 absorbs 60% of total damage (with +20% defense bonus from
Step 5), remaining 40% plus any class-1 overkill cascades through classes 2-4:
```
phalanxDamage = degatsAttaquant * 0.60  // FORMATION_PHALANX_ABSORB
otherDamage = degatsAttaquant - phalanxDamage
// Class 1 absorbs phalanxDamage; overflow + otherDamage cascades to 2-4
```
Source: `combat.php:209-243`

#### Step 8: Determine winner

```
if attacker_remaining == 0:
    if defender_remaining == 0: gagnant = 0 (draw)
    else: gagnant = 1 (defender wins)
else:
    if defender_remaining == 0: gagnant = 2 (attacker wins)
    else: gagnant = 0 (draw -- both have survivors)
```

Source: `combat.php:291-303`

| Value | Winner |
|-------|--------|
| 0 | Draw (both wiped, or both survive) |
| 1 | Defender wins |
| 2 | Attacker wins |

#### Step 9: Pillage (attacker wins only)

If `gagnant == 2`, the surviving attacker molecules loot resources.

**Vault Protection (V4):** Resources below the vault threshold cannot be pillaged:

```
vaultProtection = capaciteCoffreFort(coffreLevel, depotLevel)
                = round(placeDepot(depotLevel) * min(50%, coffreLevel * 2%))
```

Where `VAULT_PCT_PER_LEVEL = 0.02` and `VAULT_MAX_PROTECTION_PCT = 0.50` (`config.php:138-139`).
Source: `formulas.php:245-249`, `combat.php:361-372`

**Pillage capacity** uses V4 covalent synergy (Soufre primary, Chlore secondary):

```
ressourcesAPiller = SUM over all 4 classes:
    (surviving_count) * pillage(S, Cl, nivCondS, pillageMedal)

// V4 post-multipliers:
ressourcesAPiller *= catalystPillageBonus     // weekly catalyst effect
ressourcesAPiller *= (1 - bouclierReduction)  // alliance Bouclier research
```

Source: `combat.php:382-395`

The loot is distributed **proportionally** across all 8 resource types based on the
defender's **pillageable** resources (above vault protection):

```
for each resource:
    pillageable = max(0, defender_resource - vaultProtection)
    ratio = pillageable / total_pillageable
    if total_pillageable > ressourcesAPiller:
        looted = floor(ressourcesAPiller * ratio)
    else:
        looted = floor(pillageable)   // take everything above vault
```

Source: `combat.php:374-411`

Attacker's gained resources are also capped at their storage limit (`combat.php:605-621`).

#### Step 10: Building damage (hydrogene)

Building destruction only occurs when the **attacker wins** (`gagnant == 2`).
Source: `combat.php:439`

Total destruction potential uses V4 covalent synergy (H primary, O secondary):

```
hydrogeneTotal = SUM over all 4 classes:
    (surviving_count) * potentielDestruction(H, O, nivCondH)
```

Source: `combat.php:419-422`, recalculated from survivors at `combat.php:441-445`

**Target selection** has a special rule (`combat.php:448-478`):
- If `champdeforce` (forcefield) is the **highest-level** building among the 4 damageable
  buildings (generateur, champdeforce, producteur, depot), **all** destruction damage
  targets the champdeforce.
- Otherwise, each class's damage is randomly assigned to one of the 4 buildings (`rand(1,4)`).

When a building takes enough damage to deplete its HP (`combat.php:482-533`):
- The building is downgraded by 1 level (via `diminuerBatiment()`)
- Unless the building is already at level 1 (minimum), in which case no damage is dealt

Buildings that can be damaged (V4 polynomial HP formula):

| Building | HP Formula | Constants | Source |
|----------|-----------|-----------|--------|
| Generateur | `round(50 * pow(max(1, level), 2.5))` | `BUILDING_HP_BASE=50`, `BUILDING_HP_POLY_EXP=2.5` | `formulas.php:215-223` |
| Producteur | Same as Generateur | Same | `formulas.php:215-223` |
| Depot | Same as Generateur | Same | `formulas.php:215-223` |
| Champ de force | `round(125 * pow(max(1, level), 2.5))` | `FORCEFIELD_HP_BASE=125`, `BUILDING_HP_POLY_EXP=2.5` | `formulas.php:225-233` |

The Champ de force has 2.5x the HP of other buildings at the same level.

If a `joueur` context is provided, alliance Fortification research bonus is applied:
`base_hp * (1 + allianceResearchBonus(joueur, 'building_hp'))`.

Example HP values at key levels:

| Level | Standard Building | Champ de force |
|-------|-------------------|----------------|
| 1     | 50                | 125            |
| 5     | 2,795             | 6,988          |
| 10    | 15,811            | 39,528         |
| 20    | 89,443            | 223,607        |

#### Step 11: Combat points (mass-based)

V4 uses **mass-based** combat points -- total atoms destroyed, not molecule count:

```
// Count total atoms destroyed on each side
for each class i (1 to 4):
    attAtoms = sum of all 8 atom counts in attacker class i
    defAtoms = sum of all 8 atom counts in defender class i
    massDestroyedAttacker += kills_att_i * attAtoms
    massDestroyedDefender += kills_def_i * defAtoms

totalMassDestroyed = massDestroyedAttacker + massDestroyedDefender
battlePoints = min(20, floor(1 + 0.5 * sqrt(totalMassDestroyed / 100)))
```

Source: `combat.php:551-565`

Config: `COMBAT_POINTS_BASE = 1`, `COMBAT_POINTS_CASUALTY_SCALE = 0.5`,
`COMBAT_POINTS_MAX_PER_BATTLE = 20`, `COMBAT_MASS_DIVISOR = 100` (`config.php:277-280, 141`)

Point distribution:
- **Defender wins** (`gagnant == 1`): defender gets `floor(battlePoints * 1.5)` (defense
  multiplier bonus), attacker gets `-battlePoints` (`config.php:288`)
- **Attacker wins** (`gagnant == 2`) with defender losses > 0: attacker gets
  `+battlePoints`, defender gets `-battlePoints`
- **Draw** (`gagnant == 0`): both get 0

Source: `combat.php:567-574`

Points are added via `ajouterPoints()` at `combat.php:589-594`:
- Attacker: attack points adjusted (type 1), pillage points adjusted (type 3)
- Defender: defense points adjusted (type 2)

**Defense reward (V4):** When the defender wins, they also receive bonus energy equal to
20% of what the attacker's army could have pillaged (`DEFENSE_REWARD_RATIO = 0.20`,
`combat.php:311-319`).

**Attack cooldown (V4):** After every combat, the attacker is placed on cooldown against
the same defender: 4 hours on loss/draw, 1 hour on win (`combat.php:322-329`).

#### Step 12: Post-combat updates

- Attacker troop counts updated in the `actionsattaques` table (`combat.php:334-340`)
- Defender molecule counts updated in the `molecules` table (`combat.php:343-346`)
- Resources transferred: attacker gains (capped at storage), defender loses pillaged
  amounts (clamped at 0) (`combat.php:602-646`)
- Defense reward energy added to defender if they won (`combat.php:636-642`)
- Attack counter incremented for the attacker (`combat.php:649`)
- Alliance war stats updated if the two players' alliances are at war (`combat.php:651-667`)
- Attack cooldown inserted/updated to prevent chain-attacks (`combat.php:327-329`)

#### Step 13: Combat reports

Both attacker and defender receive detailed reports containing:
- Troops sent and losses per class
- Resources pillaged (itemized by resource type)
- Building damage (per building, with HP percentage before/after)

Generated in `game_actions.php:104-296`.

**Fog of war:** If all the attacker's molecules die (`attaquantsRestants == 0`), the
attacker's report shows "?" for all defender data (formulas, troop counts, losses)
(`game_actions.php:225-243`). Additionally, there is no return trip -- the attack action
is deleted immediately (`game_actions.php:241`).

---

## 4. Attack Energy Cost

Launching an attack costs energy based on the total atoms in the molecules being sent:

```
coutPourUnAtome = 0.15 * (1 + terreurMedalBonus / 100)
totalCost = SUM over all 4 classes:
    count_sent * coutPourUnAtome * totalAtomsInRecipe
```

Source: `attaquer.php:15`, `attaquer.php:127-131`

**Important:** The Terreur medal bonus **reduces** cost because `terreurMedalBonus` is
the bonus percentage from the Terreur medal. Since the formula multiplies by
`(1 + bonus/100)`, higher medal tiers result in a higher `coutPourUnAtome`. However,
examining `attaquer.php:15-24`, the code uses the medal bonus from `paliersTerreur`
(thresholds on `nbattaques`), and the bonus is applied as a multiplier -- meaning the
Terreur medal actually **increases** the cost per atom slightly. The comment in
`config.php:225` says "terreur_medal_bonus" makes attacks cheaper, but the code at
`attaquer.php:15` computes `0.15 * (1 + bonus/100)` which increases cost.

**NOTE:** This appears to be a bug or misleading documentation. The actual implementation
at `attaquer.php:15` increases cost with higher Terreur medal, not decreases it.

Config: `ATTACK_ENERGY_COST_FACTOR = 0.15` (`config.php:226`)

Terreur medal thresholds (number of attacks launched):
`[5, 15, 30, 60, 120, 250, 500, 1000]` (`constantesBase.php:29`)

### Attack Travel Time

Travel time is determined by the **slowest** molecule class being sent. V4 speed uses
covalent synergy (Chlore primary, Azote secondary):

```
distance = sqrt((x1 - x2)^2 + (y1 - y2)^2)
tempsTrajet = max over all sent classes:
    round(distance / vitesse(Cl, N, nivCondCl) * 3600)
```

Where `vitesse(Cl, N, nivCondCl) = max(1.0, floor((1 + Cl*0.5 + Cl*N/200) * modCond(nivCondCl) * 100) / 100)`

Source: `attaquer.php:122-123`

The attack action records three timestamps:
- `tempsAller` = launch time (`now`)
- `tempsAttaque` = arrival time (`now + tempsTrajet`)
- `tempsRetour` = return time (`now + 2 * tempsTrajet`)

Source: `attaquer.php:146-147`

### Pre-combat Decay

Before combat resolution, molecules that traveled are subject to decay during the
outbound journey (`game_actions.php:82-100`):

```
travelSeconds = tempsAttaque - tempsAller
for each class:
    remaining = pow(coefDisparition(attacker, classNumber), travelSeconds) * sentCount
```

The decayed counts replace the original troop numbers before combat begins.

### Return Trip Decay

Surviving attacker molecules also decay during the return trip (`game_actions.php:402-424`):

```
returnSeconds = tempsRetour - tempsAttaque
for each class:
    returning = pow(coefDisparition(attacker, classNumber), returnSeconds) * survivors
```

The returning molecules are added back to the player's molecule counts. Losses to decay
are tracked in `moleculesPerdues`.

---

## 5. Espionage

### 5.1 Initiating Espionage

Espionage is launched from `attaquer.php:26-57`. The player selects a target and specifies
how many neutrinos to send.

**Travel time:**
```
distance = sqrt((x1 - x2)^2 + (y1 - y2)^2)
tempsTrajet = round(distance / vitesseEspionnage * 3600)
```

Where `vitesseEspionnage = 20` cases/hour (`constantesBase.php:48`, `config.php:254`).

The espionage action is stored in `actionsattaques` with `troupes = 'Espionnage'` and
`nombreneutrinos` set to the number sent. The sent neutrinos are immediately deducted
from the player's count (`attaquer.php:44-46`).

**NOTE:** Espionage has no return trip for the neutrinos. They are consumed on use.

### 5.2 Espionage Resolution

When the espionage action's `tempsAttaque` arrives, it is resolved in
`game_actions.php:297-399`:

**Success condition:**
```
defender_neutrinos / 2 < sent_neutrinos
```

Source: `game_actions.php:300`

In other words: you must send **more than half** the defender's current neutrino count.

Config: `ESPIONAGE_SUCCESS_RATIO = 0.5` (`config.php:255`)

### 5.3 Successful Espionage Report

A successful spy reveals (`game_actions.php:301-389`):

1. **Army:** All 4 molecule classes with formulas and current counts
2. **Resources:** Energy + all 8 atom resource quantities
3. **Buildings:** All 8 building types with their current level and HP
   - Generateur, Producteur, Depot, Champ de force show level + HP/maxHP
   - Ionisateur, Condenseur, Lieur, Stabilisateur show level only ("Pas de vie")

### 5.4 Failed Espionage

If the condition is not met, the attacker receives a report stating:
> "Votre espionnage a rate, vous avez envoye moins de la moitie des neutrinos de votre adversaire."

Source: `game_actions.php:391-392`

**The defender is NOT notified** of either successful or failed espionage attempts.

---

## 6. Neutrinos

### 6.1 Purchase

Neutrinos are purchased with energy. Each neutrino costs **50 energy**.

Source: `constantesBase.php:49`, `config.php:260` (`NEUTRINO_COST = 50`)

Purchase happens at `armee.php:62-85`:
- Validates the player has enough energy
- Adds neutrinos to `autre.neutrinos` immediately (instant formation, no queue)
- Deducts energy from `ressources.energie`
- Tracks the energy spent in `autre.energieDepensee`

### 6.2 Properties

- **Instant formation:** No queue time. Neutrinos are available immediately upon purchase.
- **Neutrino decay (V4):** Neutrinos now decay over time, treated as mass-1 molecules
  (see Section 1.7). They are subject to the same `coefDisparition` decay system as
  molecules, using a total atom count of 1. The decay rate is very slow but non-zero.
- **Consumed on use:** When sent for espionage, the neutrinos are permanently spent
  regardless of success or failure.
- **Dual purpose:** The help text (`basicprivatehtml.php:408`) states neutrinos serve
  both offensive espionage and defensive counter-espionage. Having more neutrinos makes
  it harder for enemies to spy on you (they need more than half your count).

### 6.3 Neutrinos in Formation Queue

Despite instant purchase, the codebase also supports neutrino formation via the
formation queue system (`game_actions.php:45-49`, `game_actions.php:54-58`). The
`actionsformation.idclasse` field can be `'neutrino'`, in which case completed
formations add to `autre.neutrinos` instead of a molecule class. However, the current
purchase flow at `armee.php:62-85` bypasses the queue entirely.

---

## 7. Additional Combat Mechanics

### 7.1 Beginner Protection

New players cannot be attacked for 3 days after registration (V4: reduced from 5 days).
Players under protection also cannot attack others.

- Duration: `BEGINNER_PROTECTION_SECONDS = 259200` (3 days) (`config.php:26`)
- Check: `attaquer.php:71-74`

### 7.2 Vacation Mode

Players in vacation mode cannot be attacked (`attaquer.php:69-70`).

### 7.3 Self-Attack Prevention

A player cannot attack themselves (`attaquer.php:65`) or spy on themselves (`attaquer.php:31`).

### 7.4 Alliance War Tracking

If the attacker's and defender's alliances are at war, combat casualties are tracked
in the `declarations` table for both sides (`combat.php:385-398`).

### 7.5 Combat Report Visibility

- The defender sees incoming attacks as "?" in the attack list -- they know an attack is
  coming but not the exact arrival time (`attaquer.php:238-240`).
- Espionage actions are hidden from the defender entirely (filtered out with
  `troupes != 'Espionnage'`).

### 7.6 Prestige Combat Bonus

Players who have unlocked the "Maitre Chimiste" prestige unlock receive a
`PRESTIGE_COMBAT_BONUS = 1.05` (+5%) multiplier to both their attack and defense damage
in combat (`combat.php:181-182`).

Source: `prestige.php:195-200`, `config.php:559`

### 7.7 Weekly Catalyst System

The active weekly catalyst can affect combat through several bonuses applied in combat.php
and formulas.php:
- `attack_bonus`: Multiplies attacker's total damage (`combat.php:167`)
- `pillage_bonus`: Multiplies pillage capacity after pure formula (`combat.php:388-389`)
- `decay_increase`: Accelerates molecule decay (`formulas.php:184-188`)
- `formation_speed`: Speeds up molecule formation (`formulas.php:136`)

Source: `catalyst.php:81+`

### 7.8 Alliance Research Combat Effects

Several alliance research technologies affect combat:
- **Fortification:** +1% building HP per level (`formulas.php:219`)
- **Bouclier:** -1% pillage losses for defender per level (`combat.php:392-395`)
- **Catalyseur:** -2% formation time per level (`formulas.php:137`)

Source: `db_helpers.php:70+`, `config.php:384-430`

---

## 8. Example Calculations

### 8.1 Attack Stat Example (V4 Covalent Synergy)

Molecule with 150 Oxygene (primary), 50 Hydrogene (secondary), condenseur level 10 for
Oxygene, Bronze medal (1%):

```
modCond = 1 + 10/50 = 1.2
base = (pow(150, 1.2) + 150) * (1 + 50/100)
     = (pow(150, 1.2) + 150) * 1.5
     = (489.39 + 150) * 1.5
     = 639.39 * 1.5
     = 959.09
attack = round(959.09 * 1.2 * 1.01)
       = round(1162.37)
       = 1162
```

Compare: same molecule with 0 Hydrogene (no synergy):
```
base = (489.39 + 150) * (1 + 0/100) = 639.39
attack = round(639.39 * 1.2 * 1.01) = round(775.0) = 775
```
The 50 H secondary atoms give a **50% damage boost**.

### 8.2 HP Example (V4 Covalent Synergy)

Molecule with 100 Brome (primary), 80 Carbone (secondary), condenseur level 5 for Brome:

```
modCond = 1 + 5/50 = 1.1
base = 10 + (pow(100, 1.2) + 100) * (1 + 80/100)
     = 10 + (251.19 + 100) * 1.80
     = 10 + 351.19 * 1.80
     = 10 + 632.14
     = 642.14
HP = round(642.14 * 1.1) = round(706.35) = 706
```

Note: MOLECULE_MIN_HP=10 ensures even a 0-Brome/0-Carbone molecule has at least
`round(10 * modCond) = 11` HP (at condenseur level 5).

### 8.3 Combat Points Example (V4 Mass-Based)

Battle where 100 attacker molecules are killed (each with 200 total atoms) and
50 defender molecules are killed (each with 300 total atoms):

```
massDestroyedAttacker = 100 * 200 = 20,000
massDestroyedDefender = 50 * 300 = 15,000
totalMassDestroyed = 35,000
battlePoints = min(20, floor(1 + 0.5 * sqrt(35000 / 100)))
             = min(20, floor(1 + 0.5 * sqrt(350)))
             = min(20, floor(1 + 0.5 * 18.71))
             = min(20, floor(10.35))
             = min(20, 10)
             = 10
```

If attacker wins: +10 points. Defender gets -10 points.
If defender wins: defender gets `floor(10 * 1.5) = 15` points, attacker gets -10.

### 8.4 Vault Protection Example

Defender has coffrefort level 10, depot level 8:

```
placeDepot(8) = round(1000 * pow(1.15, 8)) = round(1000 * 3.059) = 3059
vaultPct = min(0.50, 10 * 0.02) = min(0.50, 0.20) = 0.20
vaultProtection = round(3059 * 0.20) = 612
```

Each resource type is protected up to 612 units. Any resource below 612 cannot be
pillaged at all. Only the portion above 612 is exposed.

### 8.5 Overkill Cascade Example

Attacker deals 10,000 total damage. Defender has 3 classes:
- Class 1: 20 molecules, 200 HP each (pool = 4,000)
- Class 2: 10 molecules, 300 HP each (pool = 3,000)
- Class 3: 30 molecules, 150 HP each (pool = 4,500)

Straight cascade:
```
remainingDamage = 10,000
Class 1: kills = min(20, floor(10000/200)) = min(20, 50) = 20 (all)
         remaining = 10000 - 20*200 = 6,000
Class 2: kills = min(10, floor(6000/300)) = min(10, 20) = 10 (all)
         remaining = 6000 - 10*300 = 3,000
Class 3: kills = min(30, floor(3000/150)) = 20 (10 survive)
         remaining = 3000 - 20*150 = 0
```
Total kills: 50/60. Without overkill cascade, class 1 would absorb all 10,000 damage
and only kill 20 molecules (wasting 6,000 damage).

### 8.6 Building HP Example (V4 Polynomial)

Standard building at level 10:
```
HP = round(50 * pow(10, 2.5)) = round(50 * 316.23) = 15,811
```

Forcefield at level 10:
```
HP = round(125 * pow(10, 2.5)) = round(125 * 316.23) = 39,528
```

### 8.7 Decay Half-life Example (V4)

Molecule with 400 total atoms, no medal bonus, stabilisateur level 5:

```
rawDecay = pow(0.99, pow(1 + 400/150, 1.5) / 25000)
         = pow(0.99, pow(3.667, 1.5) / 25000)
         = pow(0.99, 7.022 / 25000)
         = pow(0.99, 0.000281)
         ~ 0.999997

modStab = pow(0.98, 5) = 0.9039
modMedal = 1 - 0/100 = 1.0

coef = pow(0.999997, 0.9039 * 1.0)
     = pow(0.999997, 0.9039)
     ~ 0.999997

halfLife = log(0.5) / log(coef) ~ 231,000 seconds ~ 2.67 days
```

Note: The V4 changes (DECAY_ATOM_DIVISOR 100->150, DECAY_MASS_EXPONENT 2->1.5,
and asymptotic stabilisateur `pow(0.98, level)`) result in significantly longer
half-lives compared to the old formulas, making large molecules more viable.

---

## 9. File Reference Summary

| File | Lines | Content |
|------|-------|---------|
| `includes/formulas.php` | 74-77 | `modCond()` -- Universal condenseur modifier |
| `includes/formulas.php` | 84-88 | `attaque($O, $H, $nivCondO, $medal)` -- Attack stat (V4 covalent) |
| `includes/formulas.php` | 90-94 | `defense($C, $Br, $nivCondC, $medal)` -- Defense stat (V4 covalent) |
| `includes/formulas.php` | 96-100 | `pointsDeVieMolecule($Br, $C, $nivCondBr)` -- HP stat (V4 covalent) |
| `includes/formulas.php` | 102-106 | `potentielDestruction($H, $O, $nivCondH)` -- Destruction stat (V4 covalent) |
| `includes/formulas.php` | 108-112 | `pillage($S, $Cl, $nivCondS, $medal)` -- Pillage capacity (V4 covalent) |
| `includes/formulas.php` | 114-117 | `productionEnergieMolecule()` -- Iode energy (quadratic+linear) |
| `includes/formulas.php` | 119-123 | `vitesse($Cl, $N, $nivCondCl)` -- Movement speed (V4 covalent) |
| `includes/formulas.php` | 125-128 | `bonusLieur($niveau)` -- Lieur building bonus (V4 linear) |
| `includes/formulas.php` | 130-142 | `tempsFormation($ntotal, $N, $I, ...)` -- Formation time (V4 azote+iode) |
| `includes/formulas.php` | 145-204 | `coefDisparition()` -- Decay coefficient (with isotope/catalyst mods) |
| `includes/formulas.php` | 206-212 | `demiVie()` -- Half-life |
| `includes/formulas.php` | 215-223 | `pointsDeVie()` -- Building HP (V4 polynomial) |
| `includes/formulas.php` | 225-233 | `vieChampDeForce()` -- Forcefield HP (V4 polynomial) |
| `includes/formulas.php` | 235-238 | `coutClasse()` -- Class unlock cost |
| `includes/formulas.php` | 240-243 | `placeDepot()` -- Storage capacity |
| `includes/formulas.php` | 245-249 | `capaciteCoffreFort()` -- Vault protection |
| `includes/formulas.php` | 251-257 | `computeMedalBonus()` -- Medal bonus with cap |
| `includes/combat.php` | 1-667 | Full combat resolution (V4: overkill cascade, formations, vault) |
| `includes/game_actions.php` | 63-424 | Attack/espionage processing |
| `includes/game_resources.php` | 180-215 | Molecule decay and neutrino decay (V4: mass-1) |
| `includes/prestige.php` | 195-200 | `prestigeCombatBonus()` -- Prestige combat multiplier |
| `includes/db_helpers.php` | 70+ | `allianceResearchBonus()` -- Alliance research effects |
| `includes/catalyst.php` | 81+ | `catalystEffect()` -- Weekly catalyst bonuses |
| `includes/config.php` | 66-104 | Molecule stat constants (V4 covalent synergy) |
| `includes/config.php` | 111-121 | Decay constants |
| `includes/config.php` | 126-134 | Building HP constants (V4 polynomial) |
| `includes/config.php` | 136-144 | Vault/storage/combat mass constants |
| `includes/config.php` | 258-308 | Combat constants (formations, cooldowns, defense rewards) |
| `includes/config.php` | 310-334 | Isotope variant constants |
| `includes/config.php` | 338-346 | Espionage and neutrino constants |
| `includes/config.php` | 488-505 | Medal thresholds, bonuses, and caps |
| `attaquer.php` | 15-171 | Attack/espionage initiation |
| `armee.php` | 62-85 | Neutrino purchase |
| `armee.php` | 87-149 | Molecule formation |
| `armee.php` | 151-231 | Molecule class creation |
