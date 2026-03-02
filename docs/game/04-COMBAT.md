# 04 - Combat System

This document covers the full combat pipeline in TVLW: molecule design, stat formulas,
attack initiation, combat resolution, pillage, building damage, espionage, and neutrinos.

---

## 1. Molecule System

Each player has **4 molecule class slots**. A molecule class is a fixed recipe of 8 atom types.
Once designed, the recipe cannot be changed -- the class must be deleted and re-created.

### 1.1 Atom Types

The 8 atoms and their combat roles:

| # | Atom      | Letter | Role                      | Source |
|---|-----------|--------|---------------------------|--------|
| 0 | Carbone   | C      | Defense                   | `constantesBase.php:8` |
| 1 | Azote     | N      | Formation time reduction  | `constantesBase.php:8` |
| 2 | Hydrogene | H      | Building destruction      | `constantesBase.php:8` |
| 3 | Oxygene   | O      | Attack                    | `constantesBase.php:8` |
| 4 | Chlore    | Cl     | Movement speed            | `constantesBase.php:8` |
| 5 | Soufre    | S      | Pillage capacity          | `constantesBase.php:8` |
| 6 | Brome     | Br     | Hit points                | `constantesBase.php:8` |
| 7 | Iode      | I      | Energy production         | `constantesBase.php:8` |

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

### 1.4 Formation Time

When queuing molecules for production, the time per molecule is:

```
tempsFormation = ceil(totalAtoms / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur * 100) / 100
```

Where:
- `totalAtoms` = sum of all 8 atom counts in the recipe
- `azote` = the Azote (N) atom count in the recipe
- `niveau` = the player's condenseur-allocated level for Azote
- `bonusLieur` = `floor(100 * pow(1.07, lieur_level)) / 100`

**Source:** `formulas.php:159-164`

**Config constants:**
- `FORMATION_AZOTE_COEFFICIENT = 0.09` (`config.php:95`)
- `FORMATION_AZOTE_EXPONENT = 1.09` (`config.php:96`)
- `FORMATION_LEVEL_DIVISOR = 20` (`config.php:97`)
- `LIEUR_GROWTH_BASE = 1.07` (`config.php:407`)

The result is in seconds (can be fractional to 2 decimal places due to the ceil/100 rounding).

Formation is queued at `armee.php:107-119`. If there is already a queue, new formation starts
after the existing queue finishes (`armee.php:109-114`).

### 1.5 Molecule Decay

Molecules decay continuously over time. Every time a player's state is updated, the elapsed
seconds are applied as exponential decay:

```
remaining = pow(coefDisparition, elapsed_seconds) * current_count
```

**Decay coefficient formula** (`formulas.php:167-198`):

```
coefDisparition = pow(
    pow(0.99, pow(1 + nbAtomes / 100, 2) / 25000),
    (1 - medalBonus / 100) * (1 - stabilisateur_level * 0.01)
)
```

Where:
- `nbAtomes` = total atoms in the molecule recipe
- `medalBonus` = bonus from the "Pertes" (losses) medal tier
- `stabilisateur_level` = player's stabilisateur building level

**Config constants:**
- `DECAY_BASE = 0.99` (`config.php:103`)
- `DECAY_ATOM_DIVISOR = 100` (`config.php:104`)
- `DECAY_POWER_DIVISOR = 25000` (`config.php:105`)
- `STABILISATEUR_BONUS_PER_LEVEL = 0.01` (`config.php:106`)

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

Decay at home is applied in `update.php:47-55` (for the target player during resource updates).

---

## 2. Molecule Stats Table

Each stat is computed from one specific atom type, the player's condenseur-allocated level
for that atom (`niveauX`), and in some cases a medal bonus.

| Stat | Atom | Formula | Config Constants | Source |
|------|------|---------|------------------|--------|
| Attack | Oxygene (O) | `round((1 + (0.1*O)^2 + O) * (1+niv/50) * (1+medalBonus/100))` | `ATTACK_ATOM_COEFFICIENT=0.1`, `ATTACK_LEVEL_DIVISOR=50` | `formulas.php:80-96` |
| Defense | Carbone (C) | `round((1 + (0.1*C)^2 + C) * (1+niv/50) * (1+medalBonus/100))` | `DEFENSE_ATOM_COEFFICIENT=0.1`, `DEFENSE_LEVEL_DIVISOR=50` | `formulas.php:98-114` |
| HP | Brome (Br) | `round((1 + (0.1*Br)^2 + Br) * (1+niv/50))` | `HP_ATOM_COEFFICIENT=0.1`, `HP_LEVEL_DIVISOR=50` | `formulas.php:116-119` |
| Destruction | Hydrogene (H) | `round(((0.075*H)^2 + H) * (1+niv/50))` | `DESTRUCTION_ATOM_COEFFICIENT=0.075`, `DESTRUCTION_LEVEL_DIVISOR=50` | `formulas.php:121-124` |
| Pillage | Soufre (S) | `round(((0.1*S)^2 + S/3) * (1+niv/50) * (1+medalBonus/100))` | `PILLAGE_ATOM_COEFFICIENT=0.1`, `PILLAGE_SOUFRE_DIVISOR=3`, `PILLAGE_LEVEL_DIVISOR=50` | `formulas.php:126-142` |
| Energy Prod | Iode (I) | `round(0.05 * I * (1+niv/50))` | `IODE_ENERGY_COEFFICIENT=0.05`, `IODE_LEVEL_DIVISOR=50` | `formulas.php:144-147` |
| Speed | Chlore (Cl) | `floor((1 + 0.5*Cl) * (1+niv/50) * 100) / 100` | `SPEED_ATOM_COEFFICIENT=0.5`, `SPEED_LEVEL_DIVISOR=50` | `formulas.php:149-152` |
| Formation Time | Azote (N) | `ceil(totalAtoms / (1+(0.09*N)^1.09) / (1+niv/20) / bonusLieur * 100) / 100` | `FORMATION_AZOTE_COEFFICIENT=0.09`, `FORMATION_AZOTE_EXPONENT=1.09`, `FORMATION_LEVEL_DIVISOR=20` | `formulas.php:159-164` |

### Notes on Stats

- **`niv` (niveau):** Comes from condenseur point allocation. Each condenseur level grants
  5 distributable points (`config.php:195`). Points are stored in a semicolon-separated
  string in `constructions.pointsProducteur`, one value per atom type.

- **Medal bonuses** for Attack, Defense, and Pillage are looked up from accumulated combat
  stats. The medal tiers and their bonus percentages are:

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

  Source: `constantesBase.php:25` / `config.php:353`

- **Attack medal** thresholds (pointsAttaque): `[100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000]` (`constantesBase.php:30`)
- **Defense medal** thresholds (pointsDefense): `[100, 1000, 5000, 20000, 100000, 500000, 2000000, 10000000]` (`constantesBase.php:31`)
- **Pillage medal** thresholds (ressourcesPillees): `[1000, 10000, 50000, 200000, 1000000, 5000000, 20000000, 100000000]` (`constantesBase.php:32`)

- **HP (Brome) has no medal bonus.** The formula at `formulas.php:116-119` does not
  include a medal multiplier.

- **Speed** is in cases/hour. Travel time = `distance / speed * 3600` seconds (`attaquer.php:123`).

- **Energy Production** is per molecule per hour. Iode molecules passively produce energy.

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

- **Ionisateur** (attacker): `+2% per level` to attack (`combat.php:41`)
- **Champ de force** (defender): `+2% per level` to defense (`combat.php:43`)
- **Alliance Duplicateur** (attacker): `+duplicateur_level%` to attack (`combat.php:45-50`)
- **Alliance Duplicateur** (defender): `+duplicateur_level%` to defense (`combat.php:52-57`)

Config: `IONISATEUR_COMBAT_BONUS_PER_LEVEL = 2`, `CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL = 2` (`config.php:229-230`)

#### Step 5: Calculate total attack and defense damage

```
degatsAttaquant = 0
degatsDefenseur = 0
for each class c (1 to 4):
    degatsAttaquant += attaque(O, nivO, attacker) * (1 + ionisateur*2/100) * bonusDuplicateurAttaque * count_c
    degatsDefenseur += defense(C, nivC, defender) * (1 + champdeforce*2/100) * bonusDuplicateurDefense * count_c
```

Source: `combat.php:62-67`

`degatsAttaquant` is the total damage the attacker deals to the defender's molecules.
`degatsDefenseur` is the total damage the defender deals to the attacker's molecules.

#### Step 6: Calculate attacker casualties

Defender's total defense damage (`degatsDefenseur`) is applied to the attacker's molecules,
class by class in order (class 1 first, then class 2, etc.):

```
for class i = 1 to 4:
    if class_i has molecules AND unused damage remains:
        if brome > 0:
            kills = floor((remainingDamage) / (HP_per_molecule * bonusDuplicateurAttaque))
            kills = min(kills, count)
        else:
            kills = count  (all die if no HP and any damage exists)

        if kills < count:
            all damage is consumed
        else:
            consumed damage += kills * HP_per_molecule * bonusDuplicateurAttaque
```

Source: `combat.php:75-100`

Key detail: The alliance duplicateur bonus also multiplies HP for casualty calculation.
Molecules with 0 Brome die instantly if any defense damage exists.

#### Step 7: Calculate defender casualties

Same logic as Step 6, but attacker's total attack damage (`degatsAttaquant`) is applied
to defender's molecules, with `bonusDuplicateurDefense` applied to defender HP.

Source: `combat.php:102-124`

#### Step 8: Determine winner

```
if attacker_remaining == 0:
    if defender_remaining == 0: gagnant = 0 (draw)
    else: gagnant = 1 (defender wins)
else:
    if defender_remaining == 0: gagnant = 2 (attacker wins)
    else: gagnant = 0 (draw -- both have survivors)
```

Source: `combat.php:126-138`

| Value | Winner |
|-------|--------|
| 0 | Draw (both wiped, or both survive) |
| 1 | Defender wins |
| 2 | Attacker wins |

#### Step 9: Pillage (attacker wins only)

If `gagnant == 2`, the surviving attacker molecules loot resources (`combat.php:162-196`):

```
ressourcesAPiller = SUM over all 4 classes:
    (surviving_count) * pillage(soufre, nivS, attacker)
```

The loot is distributed **proportionally** across all 8 resource types based on the
defender's current resource distribution:

```
for each resource:
    ratio = defender_resource / total_defender_resources
    if total_defender_resources > ressourcesAPiller:
        looted = floor(ressourcesAPiller * ratio)
    else:
        looted = floor(defender_resource)   // take everything
```

Source: `combat.php:173-191`

#### Step 10: Building damage (hydrogene)

Building destruction happens regardless of combat outcome (it uses the attacker's remaining
troops, which may be 0 if attacker lost). In practice, meaningful building damage occurs
only if attacker has surviving troops with Hydrogene.

Total destruction potential: `combat.php:199-202`
```
hydrogeneTotal = SUM over all 4 classes:
    (surviving_count) * potentielDestruction(H, nivH)
```

**Target selection** has a special rule (`combat.php:218-246`):
- If `champdeforce` (forcefield) is the **highest-level** building among the 4 damageable
  buildings (generateur, champdeforce, producteur, depot), **all** destruction damage
  targets the champdeforce.
- Otherwise, each class's damage is randomly assigned to one of the 4 buildings (`rand(1,4)`).

When a building takes enough damage to deplete its HP (`combat.php:250-301`):
- The building is downgraded by 1 level (via `diminuerBatiment()`)
- Unless the building is already at level 1 (minimum), in which case no damage is dealt

Buildings that can be damaged:
| Building | HP Formula | Source |
|----------|-----------|--------|
| Generateur | `round(20 * (pow(1.2, level) + pow(level, 1.2)))` | `formulas.php:206-210` |
| Producteur | Same as Generateur | `formulas.php:206-210` |
| Depot | Same as Generateur | `formulas.php:206-210` |
| Champ de force | `round(50 * (pow(1.2, level) + pow(level, 1.2)))` | `formulas.php:212-215` |

The Champ de force has 2.5x the HP of other buildings at the same level.

#### Step 11: Combat points

```
totalCasualties = attacker_losses + defender_losses
battlePoints = min(20, floor(1 + 0.5 * sqrt(totalCasualties)))
```

- Winner gets `+battlePoints`
- Loser gets `-battlePoints`
- Draw: both get 0
- If attacker wins but defender had 0 losses: both get 0

Source: `combat.php:306-326`

Config: `COMBAT_POINTS_BASE = 1`, `COMBAT_POINTS_CASUALTY_SCALE = 0.5`, `COMBAT_POINTS_MAX_PER_BATTLE = 20` (`config.php:242-244`)

Points are added via `ajouterPoints()` at `combat.php:339-342`:
- Attacker: attack points adjusted, pillage points adjusted
- Defender: defense points adjusted, pillage points (negative) adjusted

#### Step 12: Post-combat updates

- Attacker troop counts updated in the `actionsattaques` table (`combat.php:148-154`)
- Defender molecule counts updated in the `molecules` table (`combat.php:157-160`)
- Resources transferred: attacker gains, defender loses pillaged amounts (`combat.php:350-377`)
- Attack counter incremented for the attacker (`combat.php:379-381`)
- Alliance war stats updated if the two players' alliances are at war (`combat.php:385-398`)

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

Travel time is determined by the **slowest** molecule class being sent:

```
distance = sqrt((x1 - x2)^2 + (y1 - y2)^2)
tempsTrajet = max over all sent classes:
    round(distance / vitesse(chlore, niveauChlore) * 3600)
```

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
- **No decay:** Neutrinos do not decay over time (they are stored as a simple integer in
  the `autre` table, not subject to `coefDisparition`).
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

New players cannot be attacked for 5 days after registration. Players under protection
also cannot attack others.

- Duration: `BEGINNER_PROTECTION_SECONDS = 432000` (5 days) (`config.php:26`)
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

---

## 8. Example Calculations

### 8.1 Attack Stat Example

Molecule with 150 Oxygene, player has condenseur level 10 for Oxygene, Bronze medal (1%):

```
attack = round((1 + (0.1 * 150)^2 + 150) * (1 + 10/50) * (1 + 1/100))
       = round((1 + 225 + 150) * 1.2 * 1.01)
       = round(376 * 1.212)
       = round(455.712)
       = 456
```

### 8.2 Combat Points Example

Battle with 100 attacker casualties and 50 defender casualties:

```
totalCasualties = 150
battlePoints = min(20, floor(1 + 0.5 * sqrt(150)))
             = min(20, floor(1 + 0.5 * 12.247))
             = min(20, floor(7.124))
             = min(20, 7)
             = 7
```

Winner gets +7 points, loser gets -7 points.

### 8.3 Decay Half-life Example

Molecule with 400 total atoms, no medal bonus, stabilisateur level 5:

```
coef = pow(pow(0.99, pow(1 + 400/100, 2) / 25000), (1 - 0/100) * (1 - 5 * 0.01))
     = pow(pow(0.99, 25 / 25000), 0.95)
     = pow(pow(0.99, 0.001), 0.95)
     = pow(0.99999, 0.95)
     ~ 0.999990

halfLife = log(0.5) / log(coef) ~ 69,000 seconds ~ 19 hours
```

---

## 9. File Reference Summary

| File | Lines | Content |
|------|-------|---------|
| `includes/formulas.php` | 80-96 | `attaque()` -- Attack stat |
| `includes/formulas.php` | 98-114 | `defense()` -- Defense stat |
| `includes/formulas.php` | 116-119 | `pointsDeVieMolecule()` -- HP stat |
| `includes/formulas.php` | 121-124 | `potentielDestruction()` -- Building destruction stat |
| `includes/formulas.php` | 126-142 | `pillage()` -- Pillage capacity stat |
| `includes/formulas.php` | 144-147 | `productionEnergieMolecule()` -- Iode energy production |
| `includes/formulas.php` | 149-152 | `vitesse()` -- Movement speed |
| `includes/formulas.php` | 154-157 | `bonusLieur()` -- Lieur building bonus |
| `includes/formulas.php` | 159-164 | `tempsFormation()` -- Formation time |
| `includes/formulas.php` | 167-198 | `coefDisparition()` -- Decay coefficient |
| `includes/formulas.php` | 200-203 | `demiVie()` -- Half-life |
| `includes/formulas.php` | 206-210 | `pointsDeVie()` -- Building HP |
| `includes/formulas.php` | 212-215 | `vieChampDeForce()` -- Forcefield HP |
| `includes/formulas.php` | 217-221 | `coutClasse()` -- Class unlock cost |
| `includes/combat.php` | 1-398 | Full combat resolution |
| `includes/game_actions.php` | 63-424 | Attack/espionage processing |
| `includes/update.php` | 47-55 | Molecule decay during updates |
| `includes/config.php` | 62-97 | Molecule stat constants |
| `includes/config.php` | 100-106 | Decay constants |
| `includes/config.php` | 224-244 | Combat constants |
| `includes/config.php` | 253-260 | Espionage and neutrino constants |
| `includes/constantesBase.php` | 25-38 | Medal thresholds and bonuses |
| `includes/constantesBase.php` | 47-49 | Espionage speed, neutrino cost |
| `attaquer.php` | 15-171 | Attack/espionage initiation |
| `armee.php` | 62-85 | Neutrino purchase |
| `armee.php` | 87-149 | Molecule formation |
| `armee.php` | 151-231 | Molecule class creation |
