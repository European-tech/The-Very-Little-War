# 09 — Complete Game Balance Reference

> **Source of truth:** `includes/config.php` — all constants live here.
> **Formulas:** `includes/formulas.php`, `includes/game_resources.php`, `includes/combat.php`
> **Bonuses:** `includes/prestige.php`, `includes/catalyst.php`, `includes/db_helpers.php`

This document lists **every** game constant, formula, and balance-relevant parameter.
Nothing is omitted. Sections are organized by game system.

---

## Table of Contents

1. [Time Constants](#1-time-constants)
2. [Game Limits](#2-game-limits)
3. [Atom Types](#3-atom-types)
4. [Resource Production](#4-resource-production)
5. [Molecule Stat Formulas](#5-molecule-stat-formulas)
6. [Molecule Decay](#6-molecule-decay)
7. [Buildings](#7-buildings)
8. [Combat](#8-combat)
9. [Defensive Formations](#9-defensive-formations)
10. [Isotope Variants](#10-isotope-variants)
11. [Chemical Reactions (REMOVED)](#11-chemical-reactions--removed-in-v4)
12. [Espionage & Neutrinos](#12-espionage--neutrinos)
13. [Market](#13-market)
14. [Alliance & Duplicateur](#14-alliance--duplicateur)
15. [Alliance Research Tree](#15-alliance-research-tree)
16. [Molecule Classes](#16-molecule-classes)
17. [Victory Points — Player](#17-victory-points--player)
18. [Victory Points — Alliance](#18-victory-points--alliance)
19. [Points System (totalPoints)](#19-points-system-totalpoints)
20. [Medals](#20-medals)
21. [Prestige System](#21-prestige-system)
22. [Weekly Catalyst System](#22-weekly-catalyst-system)
23. [Atom Specializations](#23-atom-specializations)
24. [Registration & New Player](#24-registration--new-player)
25. [Vault (Coffre-fort)](#25-vault-coffre-fort)
26. [Session & Security](#26-session--security)
27. [Rate Limiting](#27-rate-limiting)
28. [Pagination & Display](#28-pagination--display)
29. [Tutorial](#29-tutorial)
30. [Map Display](#30-map-display)
31. [Account & Profile](#31-account--profile)
32. [Formula Quick Reference](#32-formula-quick-reference)

---

## 1. Time Constants

| Constant | Value | Notes |
|----------|-------|-------|
| `SECONDS_PER_HOUR` | 3600 | |
| `SECONDS_PER_DAY` | 86400 | |
| `SECONDS_PER_WEEK` | 604800 | |
| `SECONDS_PER_MONTH` | 2678400 | 31 days — active player check |

---

## 2. Game Limits

| Constant | Value | Notes |
|----------|-------|-------|
| `MAX_CONCURRENT_CONSTRUCTIONS` | 2 | Build queue limit |
| `MAX_MOLECULE_CLASSES` | 4 | Classes per player |
| `MAX_ATOMS_PER_ELEMENT` | 200 | Max atoms of one type in a molecule |
| `MAX_ALLIANCE_MEMBERS` | 20 | Max players per alliance |
| `BEGINNER_PROTECTION_SECONDS` | 259200 | 3 days (V4: reduced from 5) |
| `ABSENCE_REPORT_THRESHOLD_HOURS` | 6 | Hours offline before loss report generated |
| `ONLINE_TIMEOUT_SECONDS` | 300 | 5 min — used for "online" status |
| `VICTORY_POINTS_TOTAL` | 1000 | Season VP pool reference |
| `ACTIVE_PLAYER_THRESHOLD` | 2678400 | 31 days — same as `SECONDS_PER_MONTH` |

---

## 3. Atom Types

8 atoms in order (index 0–7):

| Index | Name | Letter | Color | Role |
|-------|------|--------|-------|------|
| 0 | Carbone | C | black | Defense |
| 1 | Azote | N | blue | Formation speed |
| 2 | Hydrogène | H | gray | Building destruction |
| 3 | Oxygène | O | red | Attack |
| 4 | Chlore | Cl | green | Fleet speed |
| 5 | Soufre | S | orange | Pillage |
| 6 | Brome | Br | brown | Hit points |
| 7 | Iode | I | pink | Energy production |

---

## 4. Resource Production

### 4.1 Energy Production

```
prodBase         = BASE_ENERGY_PER_LEVEL × generateur_level
prodIode         = prodBase × iodeCatalystBonus
prodMedaille     = prodIode × (1 + energievoreMedalBonus / 100)
prodDuplicateur  = prodMedaille × bonusDuplicateur
prodPrestige     = prodDuplicateur × prestigeProductionBonus
revenuEnergie    = max(0, round(prodPrestige - drainageProducteur(producteur_level)))
```

| Constant | Value |
|----------|-------|
| `BASE_ENERGY_PER_LEVEL` | 75 |
| `PRODUCTEUR_DRAIN_PER_LEVEL` | 8 |
| `IODE_CATALYST_DIVISOR` | 50000 |
| `IODE_CATALYST_MAX_BONUS` | 1.0 (max +100%) |

**drainageProducteur(level)** = `round(PRODUCTEUR_DRAIN_PER_LEVEL × pow(ECO_GROWTH_BASE, level))` = `round(8 × pow(1.15, level))`

This is **exponential** (V4), not linear. Examples: level 5 = 16, level 10 = 32, level 20 = 131.

**iodeCatalystBonus** = `1.0 + min(IODE_CATALYST_MAX_BONUS, totalIodeAtoms / IODE_CATALYST_DIVISOR)`

Where `totalIodeAtoms` = sum over 4 classes of `iode_atoms × nombre`. This is a **multiplicative**
bonus on base energy production, not an additive term. At 50,000 total iode atoms the bonus
reaches the +100% cap (doubling energy output).

### 4.2 Atom Production

```
revenuAtome(num, joueur) = round(
    bonusDuplicateur
    × BASE_ATOMS_PER_POINT
    × pointsProducteur[num]
    × prestigeProductionBonus
)
```

| Constant | Value |
|----------|-------|
| `BASE_ATOMS_PER_POINT` | 60 |

Atom production per hour = the above result. Resources accumulate proportionally to elapsed seconds / 3600.

### 4.3 Storage

```
placeDepot(level) = round(BASE_STORAGE_INITIAL × pow(ECO_GROWTH_BASE, level))
```

| Constant | Value |
|----------|-------|
| `BASE_STORAGE_INITIAL` | 1000 |
| `ECO_GROWTH_BASE` | 1.15 |

This is **exponential** (V4), not linear. Examples: Level 1 = 1150, Level 5 = 2011,
Level 10 = 4046, Level 20 = 16367, Level 50 = 1083657.

---

## 5. Molecule Stat Formulas

All molecule stats use the **V4 covalent synergy** pattern:
```
stat = round(
    (pow(primary, COVALENT_BASE_EXPONENT) + primary)
    × (1 + secondary / COVALENT_SYNERGY_DIVISOR)
    × modCond(condenseurLevel)
    × medalBonus
)
```

Where:
- `COVALENT_BASE_EXPONENT` = 1.2
- `COVALENT_SYNERGY_DIVISOR` = 100
- `modCond(level)` = `1 + level / COVALENT_CONDENSEUR_DIVISOR` = `1 + level / 50`

Each stat has a **primary atom** (superlinear scaling) and a **secondary atom** (synergy bonus).

### 5.1 Attack: attaque(O, H, nivCondO, medalBonus)

```
attaque = round(
    (pow(O, 1.2) + O) × (1 + H / 100) × modCond(nivCondO) × (1 + medalBonus / 100)
)
```

Primary: Oxygene (O). Secondary: Hydrogene (H). Condenseur: Oxygene level.

| Constant | Value |
|----------|-------|
| `COVALENT_BASE_EXPONENT` | 1.2 |
| `COVALENT_SYNERGY_DIVISOR` | 100 |
| `COVALENT_CONDENSEUR_DIVISOR` | 50 |

Medal bonus is capped at `MAX_CROSS_SEASON_MEDAL_BONUS` (10%).

### 5.2 Defense: defense(C, Br, nivCondC, medalBonus)

```
defense = round(
    (pow(C, 1.2) + C) × (1 + Br / 100) × modCond(nivCondC) × (1 + medalBonus / 100)
)
```

Primary: Carbone (C). Secondary: Brome (Br). Condenseur: Carbone level.

Same structure as attack -- symmetrical by design.

### 5.3 Hit Points: pointsDeVieMolecule(Br, C, nivCondBr)

```
HP = round(
    (MOLECULE_MIN_HP + (pow(Br, 1.2) + Br) × (1 + C / 100)) × modCond(nivCondBr)
)
```

Primary: Brome (Br). Secondary: Carbone (C). Condenseur: Brome level.

| Constant | Value |
|----------|-------|
| `MOLECULE_MIN_HP` | 10 |

The minimum HP of 10 prevents 0-brome molecules from being instantly wiped. No medal bonus on HP.

### 5.4 Destruction: potentielDestruction(H, O, nivCondH)

```
destruction = round(
    (pow(H, 1.2) + H) × (1 + O / 100) × modCond(nivCondH)
)
```

Primary: Hydrogene (H). Secondary: Oxygene (O). Condenseur: Hydrogene level.

No medal bonus on destruction.

### 5.5 Pillage: pillage(S, Cl, nivCondS, medalBonus)

```
pillage = round(
    (pow(S, 1.2) + S) × (1 + Cl / 100) × modCond(nivCondS) × (1 + medalBonus / 100)
)
```

Primary: Soufre (S). Secondary: Chlore (Cl). Condenseur: Soufre level.

Note: the catalyst pillage bonus is now applied in combat.php (not in the pure formula).

### 5.6 Energy from Iode: productionEnergieMolecule(I, niveau)

```
iodeEnergy = round(
    (IODE_QUADRATIC_COEFFICIENT × I² + IODE_ENERGY_COEFFICIENT × I)
    × (1 + niveau / IODE_LEVEL_DIVISOR)
)
```

| Constant | Value |
|----------|-------|
| `IODE_QUADRATIC_COEFFICIENT` | 0.003 |
| `IODE_ENERGY_COEFFICIENT` | 0.04 |
| `IODE_LEVEL_DIVISOR` | 50 |

Examples: I=100 level 0 = 34 energy/mol. I=100 level 50 = 68 energy/mol.

Note: Iode energy is NOT used as an additive term in revenuEnergie. Instead,
total iode atoms across all classes provide a multiplicative `iodeCatalystBonus`
on base energy production (see Section 4.1).

### 5.7 Speed: vitesse(Cl, N, nivCondCl)

```
vitesse = max(1.0, floor(
    (1 + Cl × 0.5 + (Cl × N) / 200) × modCond(nivCondCl) × 100
) / 100)
```

Primary: Chlore (Cl). Synergy: Azote (N) via `(Cl × N) / 200`. Condenseur: Chlore level.

Result is a multiplier (1.0 = base speed). Cl=0 gives 1.0. The `Cl × N` interaction
term means Chlore and Azote synergize for speed.

### 5.8 Formation Time: tempsFormation(nTotal, N, I, nivCondN, nivLieur, joueur)

```
vitesse_form = (1 + pow(N, 1.1) × (1 + I / 200))
               × modCond(nivCondN)
               × bonusLieur(nivLieur)
               × catalystSpeedBonus
               × allianceCatalyseurBonus

tempsFormation = ceil(nTotal / vitesse_form × 100) / 100
```

Primary speed scaling: Azote (N) with exponent 1.1. Synergy: Iode (I) via `(1 + I / 200)`.

| Constant | Value |
|----------|-------|
| `COVALENT_CONDENSEUR_DIVISOR` | 50 (modCond) |

Result is in hours. `nTotal` = total atoms in the molecule.

### 5.9 Lieur Bonus

```
bonusLieur(level) = 1 + level × LIEUR_LINEAR_BONUS_PER_LEVEL
```

| Constant | Value |
|----------|-------|
| `LIEUR_LINEAR_BONUS_PER_LEVEL` | 0.15 |

V4: now **linear** (not exponential `pow(1.07, level)`). Level 0 = 1.0, Level 5 = 1.75,
Level 10 = 2.50, Level 20 = 4.00.

---

## 6. Molecule Decay

### 6.1 Disappearance Coefficient

```
rawDecay = pow(DECAY_BASE, pow(1 + nbAtoms / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR)
modStab  = pow(STABILISATEUR_ASYMPTOTE, stabilisateur_level)
modMedal = 1 - (medalBonus / 100)
baseDecay = pow(rawDecay, modStab × modMedal)
```

| Constant | Value |
|----------|-------|
| `DECAY_BASE` | 0.99 |
| `DECAY_ATOM_DIVISOR` | 150 |
| `DECAY_MASS_EXPONENT` | 1.5 (V4: was 2) |
| `DECAY_POWER_DIVISOR` | 25000 |
| `STABILISATEUR_ASYMPTOTE` | 0.98 (V4: asymptotic `pow(0.98, level)`) |
| `STABILISATEUR_BONUS_PER_LEVEL` | 0.015 (1.5% per level -- legacy constant) |

The decay coefficient is <1.0 -- each second the molecule count is multiplied by `pow(coef, elapsedSeconds)`.

V4 changes:
- `DECAY_MASS_EXPONENT` reduced from 2 to 1.5, making large molecules slightly more viable.
- Stabilisateur modifier is now **asymptotic**: `pow(0.98, level)` instead of `1 - stab × 0.01`.
  This means the stabilisateur can never fully eliminate decay, and high levels give diminishing returns.

### 6.2 Decay Modifiers

After base calculation, the decay is modified by:

**Catalyst (Volatilité):** `baseDecay = pow(baseDecay, 1.0 + catalystEffect('decay_increase'))`
- If Volatilité active: +30% faster decay (exponent 1.30)

**Isotope Stable:** `baseDecay = pow(baseDecay, 1.0 + ISOTOPE_STABLE_DECAY_MOD)` = `pow(baseDecay, 0.70)` → slower decay

**Isotope Réactif:** `baseDecay = pow(baseDecay, 1.0 + ISOTOPE_REACTIF_DECAY_MOD)` = `pow(baseDecay, 1.20)` → faster decay

### 6.3 Half-Life

```
demiVie = round(log(0.5, DECAY_BASE) / log(coefDisparition, DECAY_BASE))
```

Result is in seconds. If `coef >= 1.0`, returns `PHP_INT_MAX` (infinite).

### 6.4 Molecule Loss per Update

Every resource update (on page load), for each class:
```
moleculesRestantes = pow(coefDisparition, elapsedSeconds) × currentCount
```

If player was absent >6 hours, a loss report is generated.

---

## 7. Buildings

### 7.1 Building Cost Formula (V4: Exponential)

```
costEnergy = round((1 - constructeurMedalBonus/100) × cost_energy_base × pow(cost_growth_base, level))
costAtoms  = round((1 - constructeurMedalBonus/100) × cost_atoms_base  × pow(cost_growth_base, level))
```

V4 uses exponential costs (`base × pow(growth_base, level)`) instead of polynomial (`base × pow(level, exp)`).

Growth base tiers:
- `ECO_GROWTH_BASE` = 1.15 -- standard buildings (generateur, producteur, depot, coffrefort)
- `ECO_GROWTH_ADV` = 1.20 -- strategic buildings (champdeforce, ionisateur, condenseur, lieur)
- `ECO_GROWTH_ULT` = 1.25 -- stabilisateur (strong exponential)

### 7.2 Building Construction Time (V4: Exponential)

```
time = round(time_base × pow(time_growth_base, level + time_level_offset))
```

All buildings use `time_growth_base = 1.10`. Level 1 for Generateur/Producteur has special case: 10 seconds.

### 7.3 Building Points (added to totalPoints on upgrade)

```
points = points_base + floor(level × points_level_factor)
```

### 7.4 Building Configuration Table (V4)

| Building | Energy Base | Atom Base | Cost Growth | Time Base | Time Growth | Offset | Pts Base | Pts Factor |
|----------|-------------|-----------|-------------|-----------|-------------|--------|----------|------------|
| Generateur | 50 | 75 (all) | 1.15 | 60 | 1.10 | 0 | 1 | 0.1 |
| Producteur | 75 | 50 (all) | 1.15 | 40 | 1.10 | 0 | 1 | 0.1 |
| Depot | 100 | 0 | 1.15 | 80 | 1.10 | 0 | 1 | 0.1 |
| Champ de Force | 0 | 100 (C) | 1.20 | 20 | 1.10 | +2 | 1 | 0.075 |
| Ionisateur | 0 | 100 (O) | 1.20 | 20 | 1.10 | +2 | 1 | 0.075 |
| Condenseur | 25 | 100 (all) | 1.20 | 120 | 1.10 | +1 | 2 | 0.1 |
| Lieur | 0 | 100 (N) | 1.20 | 100 | 1.10 | +1 | 2 | 0.1 |
| Stabilisateur | 0 | 75 (all) | 1.25 | 120 | 1.10 | +1 | 3 | 0.1 |
| Coffre-fort | 150 | 0 | 1.15 | 90 | 1.10 | +1 | 1 | 0.1 |

Notes:
- "100 (C)" means cost is in carbone, "(O)" in oxygene, "(N)" in azote, "(all)" in all atom types.
- Offset means `pow(time_growth_base, level + offset)` for construction time.
- Level 1 special case for Generateur/Producteur: 10 seconds.
- Cost Growth is the exponential base applied to both energy and atom costs.

### 7.5 Building HP (V4: Polynomial)

```
pointsDeVie(level) = round(BUILDING_HP_BASE × pow(max(1, level), BUILDING_HP_POLY_EXP))
```

| Constant | Value |
|----------|-------|
| `BUILDING_HP_BASE` | 50 |
| `BUILDING_HP_POLY_EXP` | 2.5 |

V4: pure polynomial `50 × level^2.5` (not the old `base × (pow(1.2, level) + pow(level, 1.2))`).

Examples: Level 1 = 50, Level 5 = 2795, Level 10 = 15811, Level 20 = 89443.

With alliance Fortification research: `HP × (1 + fortificationLevel × 0.01)`

### 7.6 Forcefield HP (V4: Polynomial)

```
vieChampDeForce(level) = round(FORCEFIELD_HP_BASE × pow(max(1, level), BUILDING_HP_POLY_EXP))
```

| Constant | Value |
|----------|-------|
| `FORCEFIELD_HP_BASE` | 125 |
| `BUILDING_HP_POLY_EXP` | 2.5 |

Same polynomial structure as building HP but 2.5x base. Examples: Level 1 = 125,
Level 5 = 6988, Level 10 = 39528.

### 7.7 Building Combat Bonuses

| Building | Bonus |
|----------|-------|
| Ionisateur | `+level × 2%` attack |
| Champ de Force | `+level × 2%` defense |

Applied in combat as: `(1 + (level × 2) / 100)`

### 7.8 Producteur Points per Level

Each Producteur upgrade grants 8 distributable points (= number of atom types).

### 7.9 Condenseur Points per Level

Each Condenseur upgrade grants 5 distributable points.

---

## 8. Combat

### 8.1 Total Damage Calculation (V4 Covalent)

**Attacker total damage:**
```
for each class c (1-4):
    attaque(O[c], H[c], nivCondO, attackMedalBonus)
    × attIsotopeAttackMod[c]         (isotope modifier)
    × (1 + ionisateurLevel × 2/100)  (building bonus)
    × bonusDuplicateurAttaque        (alliance bonus)
    × catalystAttackBonus            (weekly catalyst)
    × nombre[c]                      (molecule count)

Total × prestigeCombatBonus(attacker)
```

**Defender total damage:**
```
for each class c (1-4):
    defense(C[c], Br[c], nivCondC, defenseMedalBonus)
    × defIsotopeAttackMod[c]         (isotope modifier on defense output)
    × phalanxDefenseBonus            (if Phalange + class 1: x1.20)
    × (1 + champdeforceLevel × 2/100)(building bonus)
    × bonusDuplicateurDefense        (alliance bonus)
    × nombre[c]                      (molecule count)

Total × prestigeCombatBonus(defender) × embuscadeDefBoost
```

Note: Chemical reactions have been **removed** in V4. All bonuses now come from
covalent synergy (secondary atom), condenseur levels, and isotopes.

### 8.2 Attack Energy Cost

```
energyCost = ATTACK_ENERGY_COST_FACTOR × (1 + terreurMedalBonus / 100) × totalAtoms
```

| Constant | Value |
|----------|-------|
| `ATTACK_ENERGY_COST_FACTOR` | 0.15 |

### 8.3 Damage Distribution -- V4 Overkill Cascade (Attacker Casualties)

Sequential cascade through classes 1-4. Overkill damage carries to the next class:
```
remainingDamage = totalDefenderDamage
for each class c (1-4):
    hpPerMol = pointsDeVieMolecule(Br[c], C[c], nivCondBr) × duplicateur × isotopeHpMod[c]
    kills = min(count[c], floor(remainingDamage / hpPerMol))
    remainingDamage -= kills × hpPerMol
```

### 8.4 Damage Distribution -- V4 Overkill Cascade (Defender Casualties)

Depends on **formation**, all using overkill cascade:

- **Dispersee (0):** Equal split among active classes, overkill cascades within.
  `damagePerClass = totalDamage / activeClassCount`, overflow carries to next class.
- **Phalange (1):** Class 1 absorbs 60% of damage, classes 2-4 share the remaining 40%.
  Phalanx overkill on class 1 carries to classes 2-4. All use sequential cascade.
- **Embuscade (2) / Default:** Straight cascade through all classes 1-4 (same as attacker).

### 8.5 Winner Determination

| Attacker remaining | Defender remaining | Result |
|--------------------|-------------------|--------|
| 0 | 0 | Draw (0) |
| 0 | >0 | Defender wins (1) |
| >0 | 0 | Attacker wins (2) |
| >0 | >0 | Draw (0) |

### 8.6 Pillaging (Attacker Wins)

```
ressourcesAPiller = sum over surviving attacker classes:
    (count - killed) × pillage(S[c], Cl[c], nivCondS, pillageMedalBonus)

After catalyst: ressourcesAPiller × catalystPillageBonus
After alliance Bouclier: ressourcesAPiller × (1 - bouclierReduction)
```

Pillage is distributed proportionally to each resource above vault protection:
```
pillageable[res] = max(0, defenderRes[res] - vaultProtection)
pilledRes[res] = floor(ressourcesAPiller × pillageable[res] / totalPillageable)
```

Capped at attacker's storage limit.

### 8.7 Building Damage (Attacker Wins)

Destruction potential from surviving attackers:
```
hydrogeneTotal = sum(surviving × potentielDestruction(H, levelH))
```

**Targeting:**
- If champdeforce level > all other buildings: all damage goes to champdeforce
- Otherwise: random 1–4 per surviving class → generateur, champdeforce, producteur, depot

If building HP reaches 0 and level > 1, building loses 1 level. Level 1 buildings cannot be destroyed.

### 8.8 Combat Points (V4: Mass-Based)

```
massDestroyed[side] = sum over classes: killed[c] × totalAtoms[c]
totalMassDestroyed = massDestroyedAttacker + massDestroyedDefender
battlePoints = min(COMBAT_POINTS_MAX_PER_BATTLE,
    floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE × sqrt(totalMassDestroyed / COMBAT_MASS_DIVISOR)))
```

| Constant | Value |
|----------|-------|
| `COMBAT_POINTS_BASE` | 1 |
| `COMBAT_POINTS_CASUALTY_SCALE` | 0.5 |
| `COMBAT_POINTS_MAX_PER_BATTLE` | 20 |
| `COMBAT_MASS_DIVISOR` | 100 |

V4: Points are now based on total atom mass destroyed (not molecule count). This makes
battles with heavier molecules worth more points.

**Distribution:**
- Defender wins: defender gets `floor(battlePoints × DEFENSE_POINTS_MULTIPLIER_BONUS)`, attacker gets `-battlePoints`
- Attacker wins: attacker gets `battlePoints`, defender gets `-battlePoints`
- Draw: both get 0

| Constant | Value |
|----------|-------|
| `DEFENSE_POINTS_MULTIPLIER_BONUS` | 1.5 |

### 8.9 Defensive Rewards

When defender wins:
```
defenseRewardEnergy = floor(totalAttackerPillageCapacity × DEFENSE_REWARD_RATIO)
```

| Constant | Value |
|----------|-------|
| `DEFENSE_REWARD_RATIO` | 0.20 (20%) |

### 8.10 Attack Cooldowns

After ANY combat:

| Outcome | Cooldown |
|---------|----------|
| Attacker loses or draw | `ATTACK_COOLDOWN_SECONDS` = 4 hours |
| Attacker wins | `ATTACK_COOLDOWN_WIN_SECONDS` = 1 hour |

Cooldowns are per attacker–defender pair (ON DUPLICATE KEY UPDATE).

### 8.11 Duplicateur in Combat

```
combatDuplicateur = 1 + (duplicateurLevel × DUPLICATEUR_COMBAT_COEFFICIENT / 100)
```

| Constant | Value |
|----------|-------|
| `DUPLICATEUR_COMBAT_COEFFICIENT` | 1.0 |

At level 10: +10% combat bonus.

---

## 9. Defensive Formations

| ID | Name | Effect |
|----|------|--------|
| 0 | Dispersée | Damage split equally among active classes |
| 1 | Phalange | Class 1 absorbs 60% of damage, gets +20% defense |
| 2 | Embuscade | If defender has more total molecules, +15% effective damage |

| Constant | Value |
|----------|-------|
| `FORMATION_PHALANX_ABSORB` | 0.60 |
| `FORMATION_PHALANX_DEFENSE_BONUS` | 0.20 |
| `FORMATION_AMBUSH_ATTACK_BONUS` | 0.15 |

---

## 10. Isotope Variants

Chosen at molecule creation. Irreversible.

| ID | Name | Attack Mod | HP Mod | Decay Mod | Special |
|----|------|-----------|--------|-----------|---------|
| 0 | Normal | 0% | 0% | 0% | — |
| 1 | Stable | -5% | +40% | -30% (slower) | Tank/defender |
| 2 | Réactif | +20% | -10% | +20% (faster) | Glass cannon |
| 3 | Catalytique | -10% | -10% | 0% | +15% all stats to OTHER classes |

| Constant | Value |
|----------|-------|
| `ISOTOPE_STABLE_ATTACK_MOD` | -0.05 |
| `ISOTOPE_STABLE_HP_MOD` | +0.40 |
| `ISOTOPE_STABLE_DECAY_MOD` | -0.30 |
| `ISOTOPE_REACTIF_ATTACK_MOD` | +0.20 |
| `ISOTOPE_REACTIF_HP_MOD` | -0.10 |
| `ISOTOPE_REACTIF_DECAY_MOD` | +0.20 |
| `ISOTOPE_CATALYTIQUE_ATTACK_MOD` | -0.10 |
| `ISOTOPE_CATALYTIQUE_HP_MOD` | -0.10 |
| `ISOTOPE_CATALYTIQUE_ALLY_BONUS` | +0.15 |

Catalytique ally bonus applies to all non-Catalytique classes on the same side (+15% attack AND HP).

---

## 11. Chemical Reactions -- REMOVED in V4

Chemical reactions have been **removed** in V4. The bonuses they provided are now handled
by the V4 covalent synergy system (secondary atom scaling in each stat formula) and
isotope variants. See Section 5 for the replacement formulas.

---

## 12. Espionage & Neutrinos

| Constant | Value |
|----------|-------|
| `ESPIONAGE_SPEED` | 20 cases/hour |
| `ESPIONAGE_SUCCESS_RATIO` | 0.5 (need > 50% of defender's neutrinos) |
| `NEUTRINO_COST` | 50 energy per neutrino |

Alliance Radar research: reduces neutrino cost by `2% × radarLevel`.

---

## 13. Market

### 13.1 Market Price Mechanics

| Constant | Value |
|----------|-------|
| `MARKET_VOLATILITY_FACTOR` | 0.3 (divided by active players) |
| `MARKET_GLOBAL_ECONOMY_DIVISOR` | 10000 (V4: global price impact scale) |
| `MARKET_PRICE_FLOOR` | 0.1 |
| `MARKET_PRICE_CEILING` | 10.0 |
| `MARKET_MEAN_REVERSION` | 0.01 (1% pull toward 1.0 per trade) |
| `MARKET_SELL_TAX_RATE` | 0.95 (5% tax on sell revenue) |
| `MARKET_HISTORY_LIMIT` | 1000 (chart data points) |
| `MERCHANT_SPEED` | 20 cases/hour |

**V4 Price Impact Formulas:**
```
Buy:  newPrice = oldPrice + volatility × quantity / MARKET_GLOBAL_ECONOMY_DIVISOR
Sell: newPrice = 1 / (1/oldPrice + volatility × actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR)
```

The divisor is a global constant (10000), not the player's `placeDepot`. This ensures
uniform market behavior regardless of individual depot levels.

### 13.2 Market Trading Points

```
tradePoints = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE × sqrt(totalTradeVolume)))
```

| Constant | Value |
|----------|-------|
| `MARKET_POINTS_SCALE` | 0.08 |
| `MARKET_POINTS_MAX` | 80 |

Trade points contribute to `totalPoints` (delta added on each trade). Alliance Réseau research boosts by `+5% × reseauLevel`.

---

## 14. Alliance & Duplicateur

### 14.1 Duplicateur Cost

```
coutDuplicateur(level) = round(DUPLICATEUR_BASE_COST × pow(DUPLICATEUR_COST_FACTOR, level + 1))
```

| Constant | Value |
|----------|-------|
| `DUPLICATEUR_BASE_COST` | 100 |
| `DUPLICATEUR_COST_FACTOR` | 1.5 |

Cost to upgrade TO level N (from N-1): Level 1 = 225, Level 5 = 1139, Level 10 = 8650.
The reduced cost factor (1.5 vs old 2.0) makes levels 10-12 achievable in a 31-day season.

### 14.2 Duplicateur Resource Bonus

```
bonusDuplicateur(level) = level × DUPLICATEUR_BONUS_PER_LEVEL
```

| Constant | Value |
|----------|-------|
| `DUPLICATEUR_BONUS_PER_LEVEL` | 0.01 (1% per level) |

Applied as: `production × (1 + bonusDuplicateur)`. Level 10 = +10%.

### 14.3 Alliance Limits

| Constant | Value |
|----------|-------|
| `ALLIANCE_RESEARCH_MAX_LEVEL` | 25 |
| `ALLIANCE_TAG_MIN_LENGTH` | 3 |
| `ALLIANCE_TAG_MAX_LENGTH` | 16 |

---

## 15. Alliance Research Tree

6 technologies total (Duplicateur + 5 research techs).

### 15.1 Research Cost Formula

```
cost = round(cost_base × pow(cost_factor, level + 1))
```

### 15.2 Research Technologies

| Tech | Name | Effect/Level | Effect Type | Cost Base | Cost Factor |
|------|------|-------------|-------------|-----------|-------------|
| Duplicateur | Duplicateur | +1% resource/combat | (see §14) | 100 | 1.5 |
| catalyseur | Catalyseur | -2% formation time | formation_speed | 15 | 2.0 |
| fortification | Fortification | +1% building HP | building_hp | 15 | 2.0 |
| reseau | Réseau | +5% trade points | trade_points | 12 | 1.8 |
| radar | Radar | -2% neutrino cost | espionage_cost | 20 | 2.5 |
| bouclier | Bouclier | -1% pillage losses | pillage_defense | 15 | 2.0 |

### 15.3 Alliance Research Bonus Application

```
allianceResearchBonus(joueur, effectType) = level × effect_per_level
```

Example: Catalyseur level 10 → `10 × 0.02 = 0.20` = 20% formation speed reduction.

---

## 16. Molecule Classes

### 16.1 Class Unlock Cost

```
coutClasse(numero) = pow(numero + CLASS_COST_OFFSET, CLASS_COST_EXPONENT)
```

| Constant | Value |
|----------|-------|
| `CLASS_COST_EXPONENT` | 4 |
| `CLASS_COST_OFFSET` | 1 |

| Class | Cost (energy) |
|-------|--------------|
| 1 | pow(2, 4) = 16 |
| 2 | pow(3, 4) = 81 |
| 3 | pow(4, 4) = 256 |
| 4 | pow(5, 4) = 625 |

---

## 17. Victory Points — Player

Awarded at end of season based on ranking by `totalPoints`.

| Rank | VP |
|------|----|
| 1 | 100 |
| 2 | 80 |
| 3 | 70 |
| 4–10 | `70 - (rank - 3) × 5` → 65, 60, 55, 50, 45, 40, 35 |
| 11–20 | `35 - (rank - 10) × 2` → 33, 31, 29, 27, 25, 23, 21, 19, 17, 15 |
| 21–50 | `max(1, floor(12 - (rank - 20) × 0.23))` |
| 51–100 | `max(1, floor(6 - (rank - 50) × 0.08))` |
| 101+ | 0 |

| Constant | Value |
|----------|-------|
| `VP_PLAYER_RANK1` | 100 |
| `VP_PLAYER_RANK2` | 80 |
| `VP_PLAYER_RANK3` | 70 |
| `VP_PLAYER_RANK4_10_BASE` | 70 |
| `VP_PLAYER_RANK4_10_STEP` | 5 |
| `VP_PLAYER_RANK11_20_BASE` | 35 |
| `VP_PLAYER_RANK11_20_STEP` | 2 |
| `VP_PLAYER_RANK21_50_BASE` | 12 |
| `VP_PLAYER_RANK21_50_STEP` | 0.23 |
| `VP_PLAYER_RANK51_100_BASE` | 6 |
| `VP_PLAYER_RANK51_100_STEP` | 0.08 |

Top 50 VP sum = 1079.

---

## 18. Victory Points — Alliance

| Rank | VP |
|------|----|
| 1 | 15 |
| 2 | 10 |
| 3 | 7 |
| 4–9 | `10 - rank` → 6, 5, 4, 3, 2, 1 |
| 10+ | 0 |

| Constant | Value |
|----------|-------|
| `VP_ALLIANCE_RANK1` | 15 |
| `VP_ALLIANCE_RANK2` | 10 |
| `VP_ALLIANCE_RANK3` | 7 |

---

## 19. Points System (totalPoints)

`totalPoints` determines player ranking. It is the sum of 4 components:

### 19.1 Construction Points (type=0)

```
ajouterPoints(nb, joueur, 0)
→ points += nb
→ totalPoints += nb
```

Gained from building upgrades: `points_base + floor(level × points_level_factor)` per upgrade.

### 19.2 Attack Points (type=1)

```
raw pointsAttaque += battlePoints (winner) or -= battlePoints (loser)
contribution to totalPoints = pointsAttaque(raw) = round(ATTACK_POINTS_MULTIPLIER × sqrt(raw))
```

| Constant | Value |
|----------|-------|
| `ATTACK_POINTS_MULTIPLIER` | 5.0 |

### 19.3 Defense Points (type=2)

```
raw pointsDefense += battlePoints × 1.5 (defender wins) or -= battlePoints (attacker wins)
contribution to totalPoints = pointsDefense(raw) = round(DEFENSE_POINTS_MULTIPLIER × sqrt(raw))
```

| Constant | Value |
|----------|-------|
| `DEFENSE_POINTS_MULTIPLIER` | 5.0 |

### 19.4 Pillage Points (type=3)

```
raw ressourcesPillees += totalPillaged
contribution to totalPoints = pointsPillage(raw) = round(tanh(raw / PILLAGE_POINTS_DIVISOR) × PILLAGE_POINTS_MULTIPLIER)
```

| Constant | Value |
|----------|-------|
| `PILLAGE_POINTS_DIVISOR` | 50000 |
| `PILLAGE_POINTS_MULTIPLIER` | 80 |

tanh asymptotes: at 10M resources pillaged, pillage points ≈ 80 (the cap).

### 19.5 Market Points

```
contribution = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE × sqrt(tradeVolume)))
```

Delta is added to `totalPoints` on each trade.

---

## 20. Medals

### 20.1 Medal Tiers

8 tiers with bonus percentages:

| Tier | Name | Bonus % |
|------|------|---------|
| 0 | Bronze | 1 |
| 1 | Argent | 3 |
| 2 | Or | 6 |
| 3 | Émeraude | 10 |
| 4 | Saphir | 15 |
| 5 | Rubis | 20 |
| 6 | Diamant | 30 |
| 7 | Diamant Rouge | 50 |

### 20.2 Medal Thresholds

| Medal | T0 | T1 | T2 | T3 | T4 | T5 | T6 | T7 |
|-------|----|----|----|----|----|----|----|----|
| Terreur (attacks) | 5 | 15 | 30 | 60 | 120 | 250 | 500 | 1000 |
| Attaque (atk pts) | 100 | 1K | 5K | 20K | 100K | 500K | 2M | 10M |
| Défense (def pts) | 100 | 1K | 5K | 20K | 100K | 500K | 2M | 10M |
| Pillage (resources) | 1K | 10K | 50K | 200K | 1M | 5M | 20M | 100M |
| Pipelette (forum) | 10 | 25 | 50 | 100 | 200 | 500 | 1K | 5K |
| Pertes (lost mols) | 10 | 100 | 500 | 2K | 10K | 50K | 200K | 1M |
| Énergivore (energy) | 100 | 500 | 3K | 20K | 100K | 2M | 10M | 1B |
| Constructeur (max lvl) | 5 | 10 | 15 | 25 | 35 | 50 | 70 | 100 |
| Bombe (destroyed) | 1 | 2 | 3 | 4 | 5 | 6 | 8 | 12 |
| Troll | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 |

### 20.3 Medal Bonus Application

Medals provide bonus % to their associated stat:
- **Terreur** → reduces attack energy cost
- **Attaque** → increases attack damage
- **Défense** → increases defense damage
- **Pillage** → increases pillage capacity
- **Pertes** → reduces molecule decay
- **Énergivore** → increases energy production
- **Constructeur** → reduces building costs

### 20.4 Cross-Season Medal Cap

| Constant | Value | Notes |
|----------|-------|-------|
| `MAX_CROSS_SEASON_MEDAL_BONUS` | 10 | Absolute % cap (Émeraude tier) |
| `MEDAL_GRACE_PERIOD_DAYS` | 14 | First N days of season: use grace cap |
| `MEDAL_GRACE_CAP_TIER` | 3 | Max tier during grace = Or (6%) |

Applied to: attaque(), defense(), pillage() medal bonuses.

---

## 21. Prestige System

Cross-season progression. Prestige Points (PP) earned at end of each season.

### 21.1 PP Earning

| Source | Constant | PP |
|--------|----------|----|
| Active in final week | `PRESTIGE_PP_ACTIVE_FINAL_WEEK` | +5 |
| Per medal tier reached (6 categories) | — | +1 each |
| Attacks >= `PRESTIGE_PP_ATTACK_THRESHOLD` (10) | `PRESTIGE_PP_ATTACK_BONUS` | +5 |
| Trade volume >= `PRESTIGE_PP_TRADE_THRESHOLD` (20) | `PRESTIGE_PP_TRADE_BONUS` | +3 |
| Donated energy | `PRESTIGE_PP_DONATION_BONUS` | +2 |

**Rank bonus** (via `$PRESTIGE_RANK_BONUSES` array):

| Rank | Extra PP |
|------|----------|
| 1–5 | +50 |
| 6–10 | +30 |
| 11–25 | +20 |
| 26–50 | +10 |

### 21.2 Prestige Unlocks

| Key | Name | Cost (PP) | Effect |
|-----|------|----------|--------|
| debutant_rapide | Débutant Rapide | 50 | Start with Générateur level 2 |
| experimente | Expérimenté | 100 | +5% resource production |
| veteran | Vétéran | 250 | +1 day beginner protection |
| maitre_chimiste | Maître Chimiste | 500 | +5% combat stats |
| legende | Légende | 1000 | Unique badge + colored name |

### 21.3 Prestige Bonus Functions

```
prestigeProductionBonus(login) = PRESTIGE_PRODUCTION_BONUS (1.05) if 'experimente' unlocked, else 1.0
prestigeCombatBonus(login)     = PRESTIGE_COMBAT_BONUS (1.05) if 'maitre_chimiste' unlocked, else 1.0
```

These multiply ALL production and ALL combat damage respectively.

---

## 22. Weekly Catalyst System

One catalyst active per week. Rotates on Monday. Global modifier for all players.

| ID | Name | Effects |
|----|------|---------|
| 0 | Combustion | `attack_bonus: +10%` |
| 1 | Synthèse | `formation_speed: +20%` |
| 2 | Équilibre | `market_convergence: +50%` |
| 3 | Fusion | `duplicateur_discount: -25%` |
| 4 | Cristallisation | `construction_speed: -15%` |
| 5 | Volatilité | `decay_increase: +30%`, `pillage_bonus: +25%` |

Rotation: `catalystId = weekNumber % 6`

### 22.1 Where Catalysts Apply

- `attack_bonus` → multiplier on attacker damage in combat
- `formation_speed` → divisor in tempsFormation
- `market_convergence` → faster price normalization in marche.php
- `duplicateur_discount` → reduces duplicateur cost
- `construction_speed` → reduces building construction time
- `decay_increase` → `pow(baseDecay, 1.30)` = 30% faster decay
- `pillage_bonus` → multiplier in pillage() formula

---

## 23. Atom Specializations

Irreversible choices unlocked at building milestones.

### 23.1 Combat Specialization

**Unlock:** Ionisateur level 15

| Option | Name | Effect |
|--------|------|--------|
| 1 | Oxydant | +10% attack, -5% defense |
| 2 | Réducteur | +10% defense, -5% attack |

### 23.2 Economy Specialization

**Unlock:** Producteur level 20

| Option | Name | Effect |
|--------|------|--------|
| 1 | Industriel | +20% atom production, -10% energy production |
| 2 | Énergétique | +20% energy production, -10% atom production |

### 23.3 Research Specialization

**Unlock:** Condenseur level 15

| Option | Name | Effect |
|--------|------|--------|
| 1 | Théorique | +2 condenseur points/level, -20% formation speed |
| 2 | Appliqué | +20% formation speed, -1 condenseur point/level |

---

## 24. Registration & New Player

### 24.1 Starting Element Assignment

Random roll 1–200 determines starting bonus element:

| Range | Probability | Element Index |
|-------|------------|---------------|
| 1–100 | 50.0% | 0 (Carbone) |
| 101–150 | 25.0% | 1 (Azote) |
| 151–175 | 12.5% | 2 (Hydrogène) |
| 176–187 | 6.0% | 3 (Oxygène) |
| 188–193 | 3.0% | 4 (Chlore) |
| 194–197 | 2.0% | 5 (Soufre) |
| 198–199 | 1.0% | 6 (Brome) |
| 200 | 0.5% | 7 (Iode) |

| Constant | Value |
|----------|-------|
| `REGISTRATION_RANDOM_MAX` | 200 |

### 24.2 Starting Buildings

| Building | Starting Level |
|----------|---------------|
| Générateur | 1 (or 2 with Prestige) |
| Producteur | 1 |
| Dépôt | 1 |
| Condenseur | 0 |
| Champ de Force | 0 |
| Ionisateur | 0 |
| Lieur | 0 |
| Stabilisateur | 0 |
| Coffre-fort | 0 |

### 24.3 Starting Resources

All atoms start at 0. Energy starts at 0. One random element receives starting bonus based on §24.1.

---

## 25. Vault (Coffre-fort)

```
capaciteCoffreFort(nivCoffre, nivDepot) =
    round(placeDepot(nivDepot) × min(VAULT_MAX_PROTECTION_PCT, nivCoffre × VAULT_PCT_PER_LEVEL))
```

| Constant | Value |
|----------|-------|
| `VAULT_PCT_PER_LEVEL` | 0.02 (2% per level) |
| `VAULT_MAX_PROTECTION_PCT` | 0.50 (50% cap) |

V4: Vault protection is now **percentage-based** relative to storage capacity. At level 25
(cap), it protects 50% of `placeDepot` of EACH resource from pillage.

Examples (depot level 10, placeDepot = 4046):
- Coffre level 5: `min(50%, 10%) × 4046` = 405 protected per resource
- Coffre level 15: `min(50%, 30%) × 4046` = 1214 protected per resource
- Coffre level 25: `min(50%, 50%) × 4046` = 2023 protected per resource

The legacy constant `VAULT_PROTECTION_PER_LEVEL = 100` still exists in config.php but
the actual vault formula uses the percentage-based `capaciteCoffreFort()` from formulas.php.

---

## 26. Session & Security

| Constant | Value | Notes |
|----------|-------|-------|
| `SESSION_IDLE_TIMEOUT` | 3600 | 1 hour idle = auto-logout |
| `SESSION_REGEN_INTERVAL` | 1800 | Regenerate session ID every 30 min |
| `ONLINE_UPDATE_THROTTLE_SECONDS` | 60 | Online status DB write throttle |
| `ONLINE_TIMEOUT_SECONDS` | 300 | 5 min offline = removed from connected |
| `SEASON_MAINTENANCE_PAUSE_SECONDS` | 86400 | 24h pause between season phases |
| `VISITOR_SESSION_CLEANUP_SECONDS` | 10800 | 3 hours — delete stale visitor accounts |

---

## 27. Rate Limiting

| Constant | Value | Notes |
|----------|-------|-------|
| `RATE_LIMIT_LOGIN_MAX` | 10 | Max login attempts per window |
| `RATE_LIMIT_LOGIN_WINDOW` | 300 | 5 minute window |
| `RATE_LIMIT_REGISTER_MAX` | 3 | Max registrations per window |
| `RATE_LIMIT_REGISTER_WINDOW` | 3600 | 1 hour window |
| `RATE_LIMIT_ADMIN_MAX` | 5 | Max admin login attempts |
| `RATE_LIMIT_ADMIN_WINDOW` | 300 | 5 minute window |

---

## 28. Pagination & Display

| Constant | Value | Notes |
|----------|-------|-------|
| `LEADERBOARD_PAGE_SIZE` | 20 | Players/alliances/wars per page |
| `SEASON_ARCHIVE_TOP_N` | 20 | Top N archived at season end |
| `MESSAGES_PER_PAGE` | 15 | Inbox messages per page |
| `REPORTS_PER_PAGE` | 15 | Battle reports per page |
| `FORUM_POSTS_PER_PAGE` | 10 | Forum posts per page |
| `MARKET_HISTORY_LIMIT` | 1000 | Price chart data points |

---

## 29. Tutorial

| Constant | Value | Notes |
|----------|-------|-------|
| `TUTORIAL_STARTER_MOLECULE_TOTAL_ATOMS` | 1000 | Atoms for starter molecule grant |

---

## 30. Map Display

| Constant | Value | Notes |
|----------|-------|-------|
| `MAP_TILE_SIZE_PX` | 80 | Tile size in pixels |
| `$MAP_ICON_DIVISORS` | [16, 8, 4, 2] | VP fraction thresholds for player icon sizes |

Icon sizes (smallest to largest): petit, moyen, grand, tgrand, geant.
A player with points <= VP_TOTAL/16 shows "petit", up to VP_TOTAL/2 shows "tgrand", above shows "geant".

---

## 31. Account & Profile

| Constant | Value | Notes |
|----------|-------|-------|
| `PASSWORD_MIN_LENGTH` | 8 | Minimum password length |
| `LOGIN_MIN_LENGTH` | 3 | Minimum username length |
| `LOGIN_MAX_LENGTH` | 20 | Maximum username length |
| `VACATION_MIN_ADVANCE_SECONDS` | 259200 | 3 days notice for vacation |
| `PROFILE_IMAGE_MAX_SIZE_BYTES` | 2000000 | 2MB upload limit |
| `PROFILE_IMAGE_MAX_DIMENSION_PX` | 150 | 150x150 max dimensions |

---

## 32. Formula Quick Reference (V4)

All formulas in one place, matching the actual V4 code in `formulas.php`.

```
# PRODUCTION
prodBase              = BASE_ENERGY_PER_LEVEL(75) × genLevel
iodeCatalystBonus     = 1.0 + min(1.0, totalIodeAtoms / 50000)
revenuEnergie         = max(0, round(prodBase × iodeCatalystBonus × medalBonus × duplicateur × prestige - drainageProducteur))
revenuAtome           = round(BASE_ATOMS_PER_POINT(60) × allocatedPoints × duplicateur × prestige)
drainageProducteur    = round(8 × pow(1.15, prodLevel))                     # EXPONENTIAL
placeDepot            = round(1000 × pow(1.15, depotLevel))                 # EXPONENTIAL

# MOLECULE STATS (V4 COVALENT SYNERGY: primary^1.2 + primary, secondary/100 synergy, modCond)
modCond(niv)          = 1 + niv / 50
attaque(O, H, niv)    = round((pow(O, 1.2) + O) × (1 + H/100) × modCond(niv) × medalBonus)
defense(C, Br, niv)   = round((pow(C, 1.2) + C) × (1 + Br/100) × modCond(niv) × medalBonus)
HP(Br, C, niv)        = round((10 + (pow(Br, 1.2) + Br) × (1 + C/100)) × modCond(niv))
destruction(H, O, niv)= round((pow(H, 1.2) + H) × (1 + O/100) × modCond(niv))
pillage(S, Cl, niv)   = round((pow(S, 1.2) + S) × (1 + Cl/100) × modCond(niv) × medalBonus)
iodeEnergy(I, niv)    = round((0.003×I² + 0.04×I) × (1 + niv/50))         # QUADRATIC
vitesse(Cl, N, niv)   = max(1.0, floor((1 + Cl×0.5 + Cl×N/200) × modCond(niv) × 100) / 100)
tempsFormation(nTot, N, I, niv, lieur) = ceil(nTot / ((1 + pow(N, 1.1) × (1 + I/200)) × modCond(niv) × bonusLieur) × 100) / 100
bonusLieur(level)     = 1 + level × 0.15                                   # LINEAR

# BUILDINGS (V4: EXPONENTIAL cost + time, POLYNOMIAL HP)
buildCost             = round((1 - medalBonus%) × costBase × pow(growthBase, level))
buildTime             = round(timeBase × pow(1.10, level + offset))
buildingHP            = round(50 × pow(max(1, level), 2.5))                # POLYNOMIAL
forcefieldHP          = round(125 × pow(max(1, level), 2.5))               # POLYNOMIAL
buildPoints           = pointsBase + floor(level × pointsFactor)

# DECAY (V4: mass exponent 1.5, asymptotic stabilisateur)
rawDecay              = pow(0.99, pow(1 + atoms/150, 1.5) / 25000)
modStab               = pow(0.98, stabLevel)                                # ASYMPTOTIC
baseDecay             = pow(rawDecay, modStab × (1 - medal%/100))
demiVie               = round(log(0.5) / log(coef))                        # in seconds

# VAULT (V4: percentage-based)
vaultProtection       = round(placeDepot(depot) × min(0.50, coffreLevel × 0.02))

# ALLIANCE
bonusDuplicateur      = level × 0.01
coutDuplicateur       = round(100 × pow(1.5, level + 1))                   # V4: 100/1.5
bonusLieur            = 1 + level × 0.15                                    # V4: LINEAR
allianceBonus         = level × effect_per_level

# POINTS
pointsAttaque(raw)    = round(5.0 × sqrt(raw))
pointsDefense(raw)    = round(5.0 × sqrt(raw))
pointsPillage(raw)    = round(tanh(raw / 50000) × 80)
tradePoints           = min(80, floor(0.08 × sqrt(volume)))
coutClasse(n)         = pow(n + 1, 4)

# COMBAT
battlePoints          = min(20, floor(1 + 0.5 × sqrt(massDestroyed / 100)))
attackEnergyCost      = 0.15 × (1 + terreurBonus%) × totalAtoms

# MARKET (V4: global economy divisor)
buyImpact             = oldPrice + volatility × qty / 10000
sellImpact            = 1 / (1/oldPrice + volatility × qty / 10000)

# PRESTIGE
prestigeProd          = 1.05 if 'experimente', else 1.0
prestigeCombat        = 1.05 if 'maitre_chimiste', else 1.0
```
