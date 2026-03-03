# BAL-CROSS: Unified Balance Roadmap
# The Very Little War — Chemistry Strategy Game

**Document type:** Cross-round synthesis and actionable roadmap
**Source audits:** BAL R1 (42 findings) + BAL R2 (26 findings)
**Author:** Claude Sonnet 4.6 — game-developer agent
**Date:** 2026-03-03
**Files analyzed:** includes/config.php, includes/formulas.php, includes/combat.php,
  includes/prestige.php, includes/catalyst.php

---

## Executive Summary

Two rounds of balance analysis identified 68 distinct findings across atom parity, formation
viability, snowball mechanics, and dead features. This document synthesizes those findings into
four concrete deliverables: a per-atom parity table with exact constant changes, a formation
viability matrix with specific fixes, a top-10 prioritized config change list with math, and a
dependency-ordered activation plan for the three dormant feature clusters (reactions,
specializations, prestige). Every recommendation references the config.php constant it targets
and includes before/after calculation.

---

## A. ATOM PARITY TABLE

The eight atom types feed eight distinct game systems. The fundamental problem is structural: six
atoms use quadratic formulas while iode uses pure linear and soufre uses a penalized linear term.
This creates a ~20x effectiveness gap at mid-game counts that widens to ~50x at cap.

### A.1 Formula Reference

All non-iode combat formulas follow the pattern:
```
stat(X, niveau) = f(X) * (1 + niveau/DIVISOR)
```
Where `f(X)` is one of:
- Quadratic: `1 + (coef*X)^2 + X`  (C, O, Br)
- Reduced quadratic: `(coef*X)^2 + X`  (H — no +1 base)
- Penalized quadratic: `(0.1*X)^2 + X/3`  (S — linear term divided by 3)
- Linear: `1 + 0.5*X`  (Cl — speed only, no quadratic)
- Pure linear: `0.10*X`  (I — energy only)
- Formation denominator: `1 + pow(0.09*X, 1.09)`  (N — inverse benefit)

### A.2 Marginal Return Per Atom at Key Counts

Marginal return = d(stat)/dX = derivative of the formula with respect to X.

| Atom | Formula | dStat/dX at X=50 | dStat/dX at X=100 | dStat/dX at X=150 | Role Index* |
|------|---------|------------------|-------------------|-------------------|-------------|
| O (attack) | `(0.1X)^2 + X` | 2.00 | 3.00 | 4.00 | 1.00 (baseline) |
| C (defense) | `(0.1X)^2 + X` | 2.00 | 3.00 | 4.00 | 1.00 |
| Br (HP) | `(0.1X)^2 + X` | 2.00 | 3.00 | 4.00 | 1.00 |
| H (destroy) | `(0.075X)^2 + X` | 1.56 | 2.13 | 2.69 | 0.78 |
| S (pillage) | `(0.1X)^2 + X/3` | 1.33 | 2.33 | 3.33 | 0.67 |
| Cl (speed) | `0.5` (constant slope) | 0.50 | 0.50 | 0.50 | 0.25 |
| N (form.time) | inverse denom | ~0.08 | ~0.05 | ~0.03 | ~0.04 |
| I (energy) | `0.10` (constant slope) | 0.10 | 0.10 | 0.10 | 0.05 |

*Role Index: marginal return at X=100 relative to O/C/Br at X=100 (value 3.00).

### A.3 Atom-by-Atom Parity Assessment and Fixes

---

**CARBONE (C) — Defense**
- Formula: `(1 + (0.1*C)^2 + C) * (1 + niveauC/50) * (1 + medalBonus/100)`
- Current effectiveness: Full parity with O/Br. Marginal return 3.00 at C=100.
- Current role: Primary tank stat. Symmetric counterpart to O.
- Problem: Champdeforce (defense building) IS damageable; ionisateur (attack building) IS NOT.
  A player who loses 10 champdeforce levels under sustained attack loses `10 * 2% = 20%` of their
  defense multiplier permanently. The attacker never loses ionisateur levels. Over 30 days of
  aggressive war: net defense value of C-investment degrades ~15-25% through building destruction
  while the opponent's O-investment compounds uninhibited.
- Fix: Add ionisateur to the damageable building list (see Section C, change #3).
- Constant: `NUM_DAMAGEABLE_BUILDINGS`: 4 → 5

---

**AZOTE (N) — Formation Speed**
- Formula: `time = ceil(ntotal / (1 + pow(0.09*N, 1.09)) / (1+niveau/20) / bonusLieur)`
- Current effectiveness: Useful at N=10-50, strongly diminishing returns past N=80.
  - N=0: no reduction (1.0x denominator)
  - N=20: 2.98x denominator (66% faster)
  - N=50: 6.06x denominator (83% faster)
  - N=100: 11.57x denominator (91% faster)
- Current role: Logistics support. Valid but saturates quickly.
- Problem: Formation time scales with `ntotal` (total atoms in molecule). Adding N reduces time but
  the most efficient strategy is simply to reduce molecule size, not add N. Result: N competes with
  atom slots for offensive purpose while providing only logistics return.
- Additional problem: Condenseur divisor for N uses level/20 (faster scaling) while all combat
  atoms use level/50. This means N benefits more per condenseur point than combat atoms, making N
  slightly over-rewarded relative to its logistics role.
- Fix: No formula change. UI tooltip clarification: "N speeds molecule assembly, not combat power."
  Consider reducing `FORMATION_LEVEL_DIVISOR` from 20 to 30 to normalize condenseur sensitivity.
- Current constant: `FORMATION_LEVEL_DIVISOR = 20`
- Proposed: `FORMATION_LEVEL_DIVISOR = 30`
- Effect: N's condenseur scaling weakens from `(1 + niv/20)` to `(1 + niv/30)` — 33% less
  sensitive, matching combat atoms more closely.

---

**HYDROGENE (H) — Building Destruction**
- Formula: `((0.075*H)^2 + H) * (1 + niveauH/50)`
- At H=100, niv=0: `((7.5)^2 + 100) = 56.25 + 100 = 156.25`
- Compare O=100: `(10^2 + 100) = 200`
- Ratio: H gives 78% as much stat as O per atom.
- Current role: Offensive side-effect. Wins battles via molecule casualties; destroys buildings as
  a bonus. Molecule with H still needs O to deal damage and win.
- Problem 1: H formula lacks the `+1` base term that O/C/Br have, so H=0 gives exactly 0
  destruction. This is thematically consistent but means any molecule without H does zero building
  damage even on winning, which is the dominant meta molecule (pure O+C+Cl+Br, no H).
- Problem 2: Building damage targeting is pure RNG per class (`rand(1,4)`). The only deterministic
  case is when champdeforce level > all others, in which case 100% of H damage concentrates there.
  This RNG creates extreme variance — four classes hitting the same building has 1/64 probability
  but 4x expected damage to that building.
- Fix: H formula gap vs O (78% efficiency) is acceptable. The real fix is deterministic building
  targeting (see Section C, change #5).
- Constant: `DESTRUCTION_ATOM_COEFFICIENT`: 0.075 — no change needed.

---

**OXYGENE (O) — Attack**
- Formula: `(1 + (0.1*O)^2 + O) * (1 + niveauO/50) * (1 + medalBonus/100)`
- At O=200, niv=50 (max): `601 * 2.0 * medalMult`
- Current effectiveness: Best combat atom per atom slot. Full quadratic scaling.
- Current role: Primary damage stat. Dominant due to offense-first meta.
- Problem: Offense dominance compounds through: attackers choose targets, ionisateur is
  indestructible, attack medals provide +50% at Diamond Rouge, attack cooldown only triggers on
  loss. Result: stacking O is always the optimal strategy since attackers control engagement timing.
- Fix: Address through ionisateur vulnerability and win-cooldown (see Section C, changes #3, #4).

---

**CHLORE (Cl) — Fleet Speed**
- Formula: `floor((1 + 0.5*Cl) * (1 + niveauCl/50) * 100) / 100`
- At Cl=100, niv=0: speed = 51 cases/hour
- At Cl=0: speed = 1 case/hour
- 51x speed difference between zero and heavy Cl investment.
- Current effectiveness: Pure logistics. No in-combat effect whatsoever.
- Current role: Travel time reduction. Valuable because: (a) slower armies take more decay damage
  in transit, (b) faster armies arrive before target can react.
- Problem: Because the army's travel speed = slowest class's speed, one low-Cl class drags the
  entire fleet. This forces Cl into every class as a mandatory "tax," reducing slots for combat
  atoms. Net effect: Cl is both underpowered (no combat payoff) and mandatory.
- Fix: Change army speed from min() of all classes to weighted average of all classes. This
  reduces the "tax" nature of Cl without changing its logistics value.
- Code location: `attaquer.php` — the army travel time calculation (uses `max($tempsTrajet, ...)`)
  should be changed to weighted average: `sum(speed[c] * count[c]) / sum(count[c])`.
- No config constant change needed; this is a formula logic change.

---

**SOUFRE (S) — Pillage**
- Formula: `((0.1*S)^2 + S/3) * (1 + niveauS/50) * (1 + medalBonus/100) * catalystBonus`
- At S=100, niv=0: `100 + 33.3 = 133.3 pillage per molecule`
- Compare O=100 attack: `100 + 100 = 200 attack per molecule`
- Ratio at S=100: 66.7% of O's return per atom.
- At S=200: `400 + 66.7 = 466.7` vs O=200: `400 + 200 = 600`. Ratio: 77.8%.
- At S=50: `25 + 16.7 = 41.7` vs O=50: `25 + 50 = 75`. Ratio: 55.6%.
- Soufre is ALWAYS weaker than oxygene per atom, and the penalty is WORST at low atom counts
  (mid-game players in the 50-100 atom range see only 55-67% efficiency).
- Current role: DEAD at competitive play. The PILLAGE_SOUFRE_DIVISOR = 3 penalty means a raider
  molecule has inferior combat stats AND reduced pillage return. The coffrefort vault (indestructible)
  further negates pillage value: at vault level 10, `100 * 10 = 1000` of each resource is immune.
  With 8 resource types: 8000 atoms fully protected regardless of pillage army size.
- Fix (R2 priority): Change `PILLAGE_SOUFRE_DIVISOR` from 3 to 2. This bridges the gap halfway
  without making S equal to O (S still has lower combat utility tradeoff).
  - S=100 new: `100 + 50 = 150` (was 133.3) — 75% of O, up from 66.7%.
  - S=200 new: `400 + 100 = 500` (was 466.7) — 83.3% of O, up from 77.8%.
- Current constant: `PILLAGE_SOUFRE_DIVISOR = 3`
- Proposed: `PILLAGE_SOUFRE_DIVISOR = 2`

---

**BROME (Br) — Hit Points**
- Formula: `(1 + (0.1*Br)^2 + Br) * (1 + niveauBr/50) * bonusDuplicateur * reactionHpBonus * isotopeHpMod`
- At Br=100, niv=0: `1 + 100 + 100 = 201 HP per molecule`
- Full parity with O/C. Quadratic scaling.
- Current role: Survivability. Indirectly improves attack effectiveness by keeping molecules alive
  longer in both transit (decay) and combat (more surviving molecules deal more total damage).
- Problem: Br is currently under-used because its benefit is indirect. A player with 200 Br
  molecules survives longer but a player with 200 O molecules kills targets before they can
  exploit the HP advantage. The offense-first meta devalues defensive investment including Br.
- Fix: Br becomes more valuable once ionisateur is made damageable and win cooldowns apply
  (defensive holding becomes a viable long-term strategy). No direct formula change needed.

---

**IODE (I) — Energy Production**
- Formula: `round(IODE_ENERGY_COEFFICIENT * iode * (1 + niveauI/50))`
  where `IODE_ENERGY_COEFFICIENT = 0.10`
- At I=100, niv=0: `0.10 * 100 = 10 energy per molecule per hour`
- At I=100, niv=10: `10 * 1.2 = 12 energy per molecule per hour`
- Compare O=100, niv=0: `200 attack per molecule` (combat stat, no direct energy value)
- The scale comparison: 100 iode molecules * 10 energy = 1000 E/h. This matches generateur level
  13.3 (`75 * 13.3 = 998`). But those 100 molecules contribute ZERO to combat.
- Structural problem: Iode is linear (`0.10 * I`) while O/C/Br are quadratic (`(0.1X)^2 + X`).
  At X=50: iode = 5 energy, oxygene = 76 attack. Ratio 50/76 ≈ 66%. But this ratio WORSENS as
  atoms increase:
  - X=100: iode = 10, oxygene = 201. Ratio 10/201 = 5%.
  - X=200: iode = 20, oxygene = 601. Ratio 20/601 = 3.3%.
  - The gap compounds quadratically. At max atoms, iode produces 30x less value-per-atom than O.
- Current role: DEAD at mid-to-late game. Only viable in early game when generateur is level 1-3.
  Confirmed "noob trap" — looks useful, provides no competitive edge once economy scales.
- Fix (R2 proposed): Add quadratic component to match structural parity:
  ```
  IODE_ENERGY_COEFFICIENT: 0.10 → 0.04 (reduce flat)
  Add IODE_QUADRATIC_COEFFICIENT: 0.003 (new constant)
  New formula: round((IODE_QUADRATIC_COEFFICIENT * iode^2 + IODE_ENERGY_COEFFICIENT * iode) * (1 + niv/50))
  ```
  At I=100, niv=0: `(0.003 * 10000 + 0.04 * 100) = 30 + 4 = 34 energy per molecule`
  (vs current 10). At 100 molecules: 3400 E/h — equivalent to generateur level 45. Now
  competitive with building investment. At I=200: `(0.003 * 40000 + 0.04 * 200) = 120 + 8 = 128`.
  100 molecules: 12800 E/h — a genuine alternative energy strategy.
- Proposed new constants:
  ```
  IODE_ENERGY_COEFFICIENT: 0.10 → 0.04
  IODE_QUADRATIC_COEFFICIENT: 0.003  (new, add to config.php)
  ```
  Add to formulas.php productionEnergieMolecule():
  ```php
  return round((IODE_QUADRATIC_COEFFICIENT * $iode * $iode + IODE_ENERGY_COEFFICIENT * $iode) * (1 + $niveau / IODE_LEVEL_DIVISOR));
  ```

---

### A.4 Atom Parity Summary Table

| Atom | Role | Effectiveness vs O at X=100 | Structural Problem | Fix Type | Config Change |
|------|------|----------------------------|--------------------|----------|---------------|
| O | Attack | 100% (baseline) | Offense meta advantage | Indirect | ionisateur vulnerability |
| C | Defense | 100% | Champdeforce damageable, ionisateur not | Building rule | NUM_DAMAGEABLE_BUILDINGS 4→5 |
| Br | HP/Survival | 100% | Under-valued in offense meta | Indirect | same as C fix |
| H | Building dmg | 78% | No +1 base term; RNG targeting | Targeting rule | Deterministic building damage |
| S | Pillage | 66.7% | S/3 linear term divisor | Direct constant | PILLAGE_SOUFRE_DIVISOR 3→2 |
| Cl | Speed | N/A (logistics) | Mandatory tax; min-speed drags army | Logic change | Weighted avg army speed |
| N | Form. speed | N/A (logistics) | Over-scaled by condenseur | Minor | FORMATION_LEVEL_DIVISOR 20→30 |
| I | Energy | 5% at X=100 (!) | Linear vs quadratic; 20x gap | Direct constant | New IODE_QUADRATIC_COEFFICIENT |

---

## B. FORMATION VIABILITY

Three defensive formations are defined. All three have structural flaws that converge
experienced players to Dispersée-with-1-molecule-trick or Phalange-against-solo-attackers.

### B.1 Dispersée (Formation 0) — Default

**How it works:** Damage split equally across all active classes (classes with at least 1 molecule).

**Math:**
```
damageShare[i] = degatsAttaquant / activeDefClasses  (for each active class)
```

**Exploit — 1-molecule damage sponge:**
If defender keeps 1 molecule alive in class 4 (the weakest class), then activeDefClasses = 4
and class 4 absorbs 25% of all incoming damage. Since it has 1 molecule, it absorbs damage up
to its single molecule's HP, then the remaining damage in that "bucket" is discarded — not
redistributed to other classes.

Concrete numbers: Attacker does 1,000,000 total damage.
- Class 4 has 1 molecule with HP = 500.
- Dispersée allocates 250,000 damage to class 4.
- Class 4 takes 500 damage (its HP), dies, and the remaining 249,500 damage is WASTED.
- Classes 1-3 only take 250,000 each instead of 333,333 each.
- Net effect: defender wastes 25% of attacker's damage with just 1 molecule.

**Current utility:** Exploitable via 1-molecule trick. Otherwise mediocre — ignores HP weights,
so a 1-molecule class and a 1000-molecule class each take the same damage share.

**Fix:**
Change Dispersée to HP-weighted proportional distribution. This is already implemented as the
else-branch fallback for Embuscade in combat.php (lines 286-288). Apply it as the Dispersée logic:

```php
// Dispersée: HP-weighted proportional distribution
for ($i = 1; $i <= $nbClasses; $i++) {
    $defDamageShares[$i] = ($totalDefenderHP > 0)
        ? $degatsAttaquant * (${'defHP'.$i} / $totalDefenderHP)
        : 0;
}
```

This eliminates the sponge exploit and makes Dispersée genuinely "proportional."

**Post-fix utility:** Default, balanced, exploitable-only through building a genuinely large
high-HP class 1 — a real strategic choice.

---

### B.2 Phalange (Formation 1) — Class 1 Shield

**How it works:**
```
Class 1: absorbs 70% of damage, receives +30% defense bonus
Classes 2-4: absorb 10% each (30% split 3 ways)
```

**Condition for Phalange to outperform fixed Dispersée (25% each):**
Class 1 must survive 70% of damage despite only having +30% HP boost from its defense bonus.
For Class 1 to survive: `0.70 * D <= class1.molecules * hpPerMol * 1.30`
For Dispersée survival of class 1: `0.25 * D <= class1.molecules * hpPerMol`

Phalange requires Class 1 to be `(0.70 / 0.25) / 1.30 = 2.15x` larger than what Dispersée
would need to not lose any molecules from Class 1. Phalange only pays off when Class 1 is a
massive tank and Classes 2-4 are fragile.

**Multi-wave collapse:**
Alliance attackers with 4h cooldowns from different players can each send attacks sequentially.
Each attack hits Class 1 with 70% of damage. After attack 1: Class 1 at 50% strength.
After attack 2: Class 1 possibly destroyed. Now Phalange triggers but Class 1 = 0 molecules,
so `defDamageShares[1] = degatsAttaquant * 0.70` — all 70% is applied to 0 molecules = wasted.
Classes 2-4 each take 10% = 30% total. Only 30% of damage reaches remaining classes.
Paradoxically, once Class 1 is dead, Phalange is BETTER than Dispersée — but the defender
cannot switch formations dynamically.

**Empty class 1 exploit:**
A defender who intentionally builds class 1 with 0 molecules and uses Phalange: 70% of all
incoming damage is directed at 0 molecules and discarded. Classes 2-4 only take 10% each = 30%
total damage received. This is the "70% free damage absorption" exploit referenced in R1/R2.

**Current utility:** Useful against single strong attacker if Class 1 is genuinely large and
tanky. Broken against coordinated multi-wave attacks. Exploitable with empty Class 1.

**Fix:**
1. Eliminate the empty-class exploit by redirecting undamaged share. If Class 1 has 0 molecules,
   Phalange falls back to proportional distribution for that share.
2. Add formation change cooldown (1 hour) to prevent instant switching between attacks.
3. Reduce absorb from 70% to 60% and bonus from +30% to +20% to decrease exploit magnitude.

Config changes:
```
FORMATION_PHALANX_ABSORB: 0.70 → 0.60
FORMATION_PHALANX_DEFENSE_BONUS: 0.30 → 0.20
```

**Post-fix utility:** Still the strongest single-engagement tank formation. Weaker against
multi-wave but no longer zero-cost with empty class.

---

### B.3 Embuscade (Formation 2) — Ambush Counter-Attack

**How it works:**
```
if (totalDefenderMols > totalAttackerMols sent):
    embuscadeDefBoost = 1.25  // +25% to defender's damage output
else:
    embuscadeDefBoost = 1.0   // no bonus
```

**Three problems:**

Problem 1 — Binary threshold: +25% or +0%. A defender with 1001 molecules vs attacker's 1000
molecules gets full +25%. A defender with 999 vs 1000 gets nothing. The threshold creates an
all-or-nothing cliff.

Problem 2 — Molecule count not power: A defender with 1000 molecules that have 0 carbone (no
defense score) gets +25% to their defense output of 0. The formation does nothing because
`0 * 1.25 = 0`. The trigger condition should be combat-power based, not headcount based.

Problem 3 — Scout exploit: An attacker who deliberately sends 1 molecule per class (4 total)
ensures the defender always outnumbers them and always gets the Embuscade bonus. But 4 molecules
do essentially zero damage, so the defender is incentivized to trigger Embuscade even though the
attacker learns the defender's class compositions.

**Current utility:** Low. Only benefits established defenders with both many molecules AND high
carbone. New players with many cheap molecules never gain meaningful return because their
defense stat (carbone) is 0.

**Fix:**
Replace molecule-count trigger with defense-power trigger. Use the already-computed `$degatsDefenseur`
and `$degatsAttaquant` values (both are calculated before formation bonuses apply):

```php
// New Embuscade condition: defender outmatches attacker on combat power
$preFormationDefPower = /* sum of defense() * count per class, pre-boost */;
if ($preFormationDefPower > $degatsAttaquant && $defenderFormation == FORMATION_EMBUSCADE) {
    $embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS;
}
```

Additionally, reduce bonus from +25% to +15% to prevent stacking with Catalytique:
```
FORMATION_AMBUSH_ATTACK_BONUS: 0.25 → 0.15
```

**Post-fix utility:** Meaningful reward for players who have built genuine defensive power.
Triggers when the defender would naturally win anyway — boosting margin of victory rather than
enabling reversals from a weak position.

---

### B.4 Formation Viability Summary

| Formation | Current Exploit | Current Utility | Fix | Post-fix Role |
|-----------|----------------|-----------------|-----|---------------|
| Dispersée | 1-molecule damage sponge wastes 25% damage | Below average (default for new players) | HP-weighted proportional distribution | Balanced default; no exploits |
| Phalange | Empty class 1 discards 70% damage for free | Good vs single attacker; broken vs multi-wave | 60/20 ratios; redirect empty-class damage; 1h cooldown | Tank formation; genuine tradeoff required |
| Embuscade | Count-based trigger; 0-carbone army still gets +25% | Near-useless for new players | Power-based trigger; reduce bonus to +15% | Reward for genuinely superior defenders |

**Key config changes:**
```
FORMATION_PHALANX_ABSORB: 0.70 → 0.60
FORMATION_PHALANX_DEFENSE_BONUS: 0.30 → 0.20
FORMATION_AMBUSH_ATTACK_BONUS: 0.25 → 0.15
```

---

## C. TOP 10 CONFIG CHANGES

Ordered by impact/complexity ratio. Each change includes the math proof, before/after values,
and the specific PHP constant in config.php to modify.

---

### C1. PILLAGE_SOUFRE_DIVISOR: 3 → 2

**Impact:** HIGH | **Complexity:** Trivial (one constant)

**Problem (BAL-R2-007, BAL-R1-002):**
Soufre's linear term is `S/3` vs `S` for attack/defense/HP. This structural penalty means:
- At S=50: pillage = 41.7 vs attack O=50: 75. Ratio 55.6%.
- At S=100: pillage = 133.3 vs O=100: 200. Ratio 66.7%.
- At S=200: pillage = 466.7 vs O=200: 600. Ratio 77.8%.

The penalty is worst in the mid-game range (50-100 atoms) where most players operate.

**Proposed fix:**
```php
define('PILLAGE_SOUFRE_DIVISOR', 2);  // was: 3
```

**After-change numbers:**
- At S=50: `(0.1*50)^2 + 50/2 = 25 + 25 = 50`. Ratio vs O: 66.7% (up from 55.6%)
- At S=100: `100 + 50 = 150`. Ratio vs O: 75% (up from 66.7%)
- At S=200: `400 + 100 = 500`. Ratio vs O: 83.3% (up from 77.8%)

Soufre is still weaker than O per atom (S molecules are combat-inferior, which is appropriate
since pillage is a strategic bonus, not the primary win condition). However, the investment is no
longer a trap. A raider army is now 75-83% as combat-effective as a pure attacker while retaining
20% pillage reaction bonus potential.

**Vault balance:** The indestructible coffrefort still counters pillage. With this change, soufre
remains a secondary win condition — it does not make pillage dominant.

---

### C2. IODE_ENERGY_COEFFICIENT + new IODE_QUADRATIC_COEFFICIENT

**Impact:** HIGH | **Complexity:** Low (2 constants + 1 formula line)

**Problem (BAL-R2-006, BAL-R1-005):**
Iode's pure-linear formula creates a widening gap vs quadratic atoms:
- X=50: iode = 5 energy/mol, O = 76 attack/mol. Ratio 6.6%.
- X=100: iode = 10, O = 201. Ratio 5.0%.
- X=200: iode = 20, O = 601. Ratio 3.3%.

The gap grows as players invest more atoms, making iode worthless precisely when atom investment
matters most.

**Proposed fix (adds quadratic term to match structural class of other atoms):**
```php
define('IODE_ENERGY_COEFFICIENT', 0.04);       // was: 0.10
define('IODE_QUADRATIC_COEFFICIENT', 0.003);   // new constant
```

New formula in `formulas.php:productionEnergieMolecule()`:
```php
function productionEnergieMolecule($iode, $niveau) {
    return round(
        (IODE_QUADRATIC_COEFFICIENT * $iode * $iode + IODE_ENERGY_COEFFICIENT * $iode)
        * (1 + $niveau / IODE_LEVEL_DIVISOR)
    );
}
```

**After-change numbers:**
- I=50, niv=0: `(0.003*2500 + 0.04*50) = 7.5 + 2 = 9.5 → 10 E/mol` (was 5)
- I=100, niv=0: `(0.003*10000 + 0.04*100) = 30 + 4 = 34 E/mol` (was 10)
- I=150, niv=0: `(0.003*22500 + 0.04*150) = 67.5 + 6 = 73.5 E/mol` (was 15)
- I=200, niv=0: `(0.003*40000 + 0.04*200) = 120 + 8 = 128 E/mol` (was 20)

With 100 molecules at I=100: 3400 E/h — comparable to generateur level 45. A genuine
alternative energy strategy becomes viable. Players specializing in iode can trade combat power
for economic independence (energy surplus for building construction, neutrinos, and molecule
formation without competing with generateur levels).

---

### C3. NUM_DAMAGEABLE_BUILDINGS: 4 → 5 (add ionisateur)

**Impact:** HIGH | **Complexity:** Medium (constant change + HP formula for ionisateur)

**Problem (BAL-R2-003, BAL-R1-020):**
Ionisateur is an attack-boosting building that cannot be destroyed. Champdeforce is a
defense-boosting building that CAN be destroyed. This asymmetry means:

Over a 30-day season with 10 successful attacks against a defender:
- Defender potentially loses 10 champdeforce levels.
- At 2% per level: 20 percentage points lost from defense multiplier.
- Attacker's ionisateur: 0% lost. Permanent advantage.

Math: Attacker at ionisateur level 20 has `1 + 0.40 = 1.40x` attack. After 10 battles where
champdeforce degrades: defender at `1 + (level-10)*0.02` defense vs original `1 + level*0.02`.
The combat power ratio swings irreversibly toward the attacker with each battle.

**Proposed fix:**
```php
define('NUM_DAMAGEABLE_BUILDINGS', 5);  // was: 4
```

Add ionisateur to the random building targeting in `combat.php` — change `rand(1, 4)` to
`rand(1, 5)`, mapping case 5 to ionisateur damage. Add ionisateur HP in the constructions table
(same formula as `vieChampDeForce` using `FORCEFIELD_HP_BASE = 50`).

In `combat.php`, add ionisateur damage tracking alongside existing champdeforce damage handling.
The ionisateur has its own HP bar (`vieIonisateur` column in constructions table, same schema
as `vieChampdeforce`). When it reaches 0, diminuerBatiment("ionisateur", ...) reduces its level.

**After-change effects:**
Aggressive attackers now risk their ionisateur in wars of attrition. A defender who consistently
defeats attackers can degrade their ionisateur over time. This creates genuine strategic parity:
both offensive and defensive investments are now vulnerable, making both worth protecting.

---

### C4. ATTACK_COOLDOWN_SECONDS: apply on WINS too (new WIN_COOLDOWN constant)

**Impact:** HIGH | **Complexity:** Low (add one constant, modify combat.php condition)

**Problem (BAL-R1-020):**
The 4-hour cooldown only triggers on `$gagnant != 2` (loss or draw). Winning attackers have
zero cooldown against the same target. This enables "chain-bullying": a dominant player can
repeatedly attack a weaker player, destroying buildings each time with no restriction.

Scenario: Player A attacks Player B and wins. Player A immediately attacks again. With 4 classes
of hydrogen, A destroys a building every ~2 attacks. By attack #5 (within hours), B has lost
multiple building levels. B cannot rebuild faster than A can destroy.

**Proposed fix:**
```php
define('ATTACK_COOLDOWN_WIN_SECONDS', 1 * 3600);  // new constant: 1 hour on win
// Keep existing ATTACK_COOLDOWN_SECONDS (4 hours) for loss/draw
```

In `combat.php`, replace the single cooldown insert:
```php
// Current:
if ($gagnant != 2) {
    $cooldownExpires = time() + ATTACK_COOLDOWN_SECONDS;
    dbExecute(...);
}

// Proposed:
if ($gagnant != 2) {
    $cooldownExpires = time() + ATTACK_COOLDOWN_SECONDS;      // 4h on loss/draw
} else {
    $cooldownExpires = time() + ATTACK_COOLDOWN_WIN_SECONDS;  // 1h on win
}
dbExecute($base, 'INSERT INTO attack_cooldowns (attacker, defender, expires) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE expires=?', 'ssii', $attaquant, $defenseur, $cooldownExpires, $cooldownExpires);
```

**After-change effects:**
Winners must wait 1 hour before hitting the same target again. At 1 hour per hit, destroying
a generateur requires days of focused attacks — a significant investment that the defender can
disrupt by altering their troop positioning or calling for alliance support.

---

### C5. Replace rand(1,4) building targeting with deterministic rotation

**Impact:** MEDIUM-HIGH | **Complexity:** Low

**Problem (BAL-R2-005, BAL-R1-007):**
Per-class building targeting uses independent `rand(1,4)` rolls. With 4 attacker classes, the
probability that all 4 hit the same building is `(1/4)^3 = 1.56%` — small but generates
extreme outlier outcomes when it occurs (4x expected damage to one building = instant
destruction even from modest armies). This creates a "lottery" feel to building damage.

**Proposed fix:**
Replace independent random rolls with a sequential cycle per-combat:
```php
// Instead of: $bat = rand(1, 4);
// Use: cycle through buildings 1-2-3-4 in order per combat
$buildingCycle = [1, 2, 3, 4]; // generateur, champdeforce, producteur, depot
foreach ($activeClasses as $classIndex => $classData) {
    $bat = $buildingCycle[$classIndex % 4]; // deterministic rotation
    ...
}
```

This ensures each building receives exactly one attack class's damage allocation per combat
(or zero if fewer than 4 active classes). Variance is eliminated; each building takes
`hydrogeneTotal / 4` damage on average with no high-variance outliers.

No config constant change needed. Code change is localized to `combat.php` lines 447-468.

**After-change effects:**
Building destruction is predictable and strategically controllable. Players can choose which
buildings to invest in HP knowing that damage will distribute evenly. Removes lottery element
that punishes some defenders arbitrarily.

---

### C6. MEDAL_BONUSES cap on carry-forward: seasonal reset to tier floor

**Impact:** HIGH (cross-season) | **Complexity:** Medium

**Problem (BAL-R2-010, BAL-R1-012):**
Medal bonuses are permanent cross-season advantages. At Diamond Rouge (tier 8), players have
`+50%` to attack/defense. A veteran entering Season 2 immediately has 1.50x attack multiplier
vs a new player's 1.01x — a 49% raw advantage before counting atoms, condenseur levels, or
buildings.

Combined with the quadratic atom formula (13x power ratio at max atoms/condenseur) and building
medal discount (veteran builds 50% cheaper), the total power ratio between veteran and new player
easily exceeds 50:1.

**Proposed fix (soft seasonal reset):**
At season start, each player's medal TIER is preserved but their cumulative medal POINTS within
that tier are reset to the tier's minimum threshold. Effect: Diamond player (tier 7, 30% bonus)
retains 30% — they do not lose their tier. But they cannot immediately carry over points toward
Diamond Rouge tier. They must re-earn within-tier progress each season.

Additionally: apply a grace period cap for the first 14 days of a new season:
```php
// New constant:
define('MEDAL_GRACE_PERIOD_DAYS', 14);   // new
define('MEDAL_GRACE_CAP_TIER', 3);       // Gold tier (6% bonus) as first-14-day max
```

For the first 14 days of each season, `attaque()` and `defense()` use `min(tier, MEDAL_GRACE_CAP_TIER)`
as the effective tier for bonus calculation. This gives new players 2 weeks to build up before
veteran medal bonuses come fully online.

**After-change effects:**
Veteran advantage is still present but compressed in time. New players have a meaningful early
game window. Veteran players still have career progression through tier advancement.

---

### C7. FORMATION_PHALANX_ABSORB: 0.70 → 0.60 and FORMATION_PHALANX_DEFENSE_BONUS: 0.30 → 0.20

**Impact:** MEDIUM | **Complexity:** Trivial (two constants)

**Problem (see Section B.2):**
70% absorption enables an empty-class-1 exploit that discards 70% of all incoming damage for
free. Even without the exploit, 70% concentration on one class is extreme — it means a player
with a moderately sized Class 1 and tiny Classes 2-4 can use Phalange defensively with very
little real cost, since 30% damage divided among 3 small classes does negligible harm.

**Proposed fix:**
```php
define('FORMATION_PHALANX_ABSORB', 0.60);         // was: 0.70
define('FORMATION_PHALANX_DEFENSE_BONUS', 0.20);  // was: 0.30
```

**After-change numbers:**
Phalange new: Class 1 absorbs 60% with +20% defense. Classes 2-4 absorb 13.3% each.
The break-even molecule count for Class 1 vs Dispersée: `(0.60/0.25) / 1.20 = 2.0x` — Class 1
needs to be twice as large as Dispersée would require, down from 2.15x. Phalange is slightly
easier to justify but the empty-class exploit is reduced: an empty class 1 now discards 60%
(not 70%) of damage. The remaining 40% going to Classes 2-4 (13.3% each) is more punishing
than the previous 10% each — making the exploit substantially less cost-free.

---

### C8. DUPLICATEUR_COST_FACTOR: 2.5 → 2.0

**Impact:** MEDIUM | **Complexity:** Trivial

**Problem (BAL-R2-024):**
Cost formula: `round(10 * pow(DUPLICATEUR_COST_FACTOR, level + 1))`

With factor 2.5:
- Level 1: `10 * pow(2.5, 2) = 62.5` energy
- Level 5: `10 * pow(2.5, 6) = 2441` energy
- Level 10: `10 * pow(2.5, 11) = 238,419` energy
- Level 15: `10 * pow(2.5, 16) = 23.3M` energy

Level 10 at 500 E/h production requires 477 hours (almost 20 days) of ALL energy going to
duplicateur. In a 31-day season this is literally impossible. Alliances are stuck at level 5-7.

With factor 2.0:
- Level 10: `10 * pow(2.0, 11) = 20,480` energy — achievable in ~41 hours of production
- Level 15: `10 * pow(2.0, 16) = 327,680` energy — takes weeks but reachable for active alliances
- Level 20: `10 * pow(2.0, 21) = 20.9M` — appropriate endgame wall

**Proposed fix:**
```php
define('DUPLICATEUR_COST_FACTOR', 2.0);  // was: 2.5
```

**After-change effects:**
Alliances can realistically reach duplicateur level 10-12 in a 31-day season, providing a
meaningful +10-12% combat bonus that rewards coordination without making level 20-25 trivial.

---

### C9. ISOTOPE_REACTIF_DECAY_MOD: 0.50 → 0.20

**Impact:** MEDIUM | **Complexity:** Trivial

**Problem (BAL-R2-015):**
Réactif isotope gives +20% attack but +50% faster decay. Expected lifetime attack output:

```
Réactif: 1.20 * attack * (half-life / ln(2))
       With +50% decay: half-life = base * (1/1.50) = 0.667x
       = 1.20 * attack * 0.667 * (base_half_life / ln(2))
       = 0.80 * attack * (base_half_life / ln(2))
```

Réactif delivers only 80% of Normal's lifetime attack despite costing +50% decay. Players who
choose Réactif expecting a glass cannon receive a net-negative choice — 20% more peak power but
20% less total output over the molecule's lifetime.

**Proposed fix:**
```php
define('ISOTOPE_REACTIF_DECAY_MOD', 0.20);  // was: 0.50
```

**After-change recalculation:**
```
Réactif new: 1.20 * attack * (1/1.20) * (base_half_life / ln(2))
           = 1.00 * attack * (base_half_life / ln(2))
```

With decay mod = 0.20: lifetime output equals Normal. Peak power is 20% higher — paid for by
20% shorter lifetime. Réactif is now genuinely a glass cannon: bursts harder, dies at the same
rate relative to its peak advantage. A valid rush-strategy choice.

---

### C10. VP curve reshape — ranks 21-100

**Impact:** MEDIUM | **Complexity:** Low

**Problem (BAL-R2-011, BAL-R1-018):**
Current formula for ranks 51-100: `max(1, floor(3 - (rank-50) * 0.04))`
- Rank 51: 2 VP
- Rank 75: 2 VP
- Rank 100: 1 VP

The difference between rank 51 and rank 100 is 1 VP. Players in this band have zero incentive
to improve their ranking. With ~60-80% of players likely in ranks 21-100, this means the
majority of the playerbase has no VP-based motivation to compete.

**Proposed fix:**
```php
define('VP_PLAYER_RANK51_100_BASE', 6);      // was: 3
define('VP_PLAYER_RANK51_100_STEP', 0.08);   // was: 0.04
```

New formula produces:
- Rank 51: `max(1, floor(6 - 1*0.08)) = 5 VP`
- Rank 75: `max(1, floor(6 - 25*0.08)) = max(1, floor(4)) = 4 VP`
- Rank 100: `max(1, floor(6 - 50*0.08)) = max(1, floor(2)) = 2 VP`

Difference between rank 51 and rank 100: 3 VP (up from 1 VP). Small but creates meaningful
differentiation across the full rank band. Each 10-rank improvement is now worth ~0.8 VP
instead of ~0.4 VP.

Also adjust ranks 21-50:
```php
define('VP_PLAYER_RANK21_50_BASE', 12);    // was: 15
define('VP_PLAYER_RANK21_50_STEP', 0.23); // was: 0.5
```
- Rank 21: `max(1, floor(12 - 1*0.23)) = 11 VP`
- Rank 35: `max(1, floor(12 - 15*0.23)) = max(1, floor(8.55)) = 8 VP`
- Rank 50: `max(1, floor(12 - 30*0.23)) = max(1, floor(5.1)) = 5 VP`

Smooth curve from rank 20 (currently 15 VP) to rank 50 (5 VP) to rank 100 (2 VP) instead of
the current collapse to 1 VP at rank 49+.

---

### C. Top 10 Summary Table

| # | Constant | Current Value | Proposed Value | Reason | Source Finding |
|---|----------|--------------|----------------|--------|----------------|
| 1 | `PILLAGE_SOUFRE_DIVISOR` | 3 | 2 | Bridges S/O efficiency gap from 56-67% to 67-83% | BAL-R2-007 |
| 2 | `IODE_ENERGY_COEFFICIENT` + new `IODE_QUADRATIC_COEFFICIENT` | 0.10 / n/a | 0.04 / 0.003 | Makes iode competitive at mid-game (34 E/mol at I=100 vs current 10) | BAL-R2-006 |
| 3 | `NUM_DAMAGEABLE_BUILDINGS` | 4 | 5 | Adds ionisateur to destructible list; creates defense/offense parity | BAL-R2-003 |
| 4 | `ATTACK_COOLDOWN_WIN_SECONDS` (new) | n/a | 3600 (1 hour) | Prevents chain-attack bullying on wins | BAL-R1-020 |
| 5 | Building targeting logic | `rand(1,4)` | sequential cycle | Eliminates RNG building destruction variance | BAL-R2-005 |
| 6 | `MEDAL_GRACE_PERIOD_DAYS` + `MEDAL_GRACE_CAP_TIER` (new) | n/a | 14 / 3 | Reduces cross-season veteran snowball for first 2 weeks | BAL-R2-010 |
| 7 | `FORMATION_PHALANX_ABSORB` | 0.70 | 0.60 | Reduces empty-class-1 exploit from 70% to 60% free damage discard | BAL-R2-013 |
| 8 | `DUPLICATEUR_COST_FACTOR` | 2.5 | 2.0 | Makes duplicateur level 10-12 achievable in a 31-day season | BAL-R2-024 |
| 9 | `ISOTOPE_REACTIF_DECAY_MOD` | 0.50 | 0.20 | Réactif now matches Normal lifetime output with front-loaded power | BAL-R2-015 |
| 10 | `VP_PLAYER_RANK51_100_BASE` / `_STEP` | 3 / 0.04 | 6 / 0.08 | Ranks 51-100 span 3 VP difference instead of 1 VP | BAL-R2-011 |

---

## D. DEAD FEATURE ACTIVATION ORDER

Three feature clusters are fully defined in config.php but either never activate (chemical
reactions — data exists but no UI triggers them), have no UI (specializations — config exists
but no selection page), or compound existing snowball problems (prestige — defined and partially
wired but amplifies cross-season inequality).

The dependency chain matters: activating prestige without fixing snowball mechanics makes the
game permanently unbalanced for new players. Activating specializations without fixing formation
exploits gives veterans another combinatorial advantage. Chemical reactions must fire first
because they provide a low-power bonus that introduces new molecule archetypes without breaking
the existing balance envelope.

---

### D.1 PHASE 1: CHEMICAL REACTIONS (Activate First)

**Current state:** Fully defined in `$CHEMICAL_REACTIONS` (config.php lines 313-344).
`checkReactions()` function exists in `combat.php` and IS called. The reaction bonus multipliers
ARE applied in combat. However, there is no UI that shows players:
1. Which reactions are currently active in their army.
2. What atom counts they need to trigger a reaction.
3. Which reactions triggered in the last combat report.

**The reactions DO work mechanically.** The "dead feature" is the missing UI layer. Players
cannot optimize for reactions because they cannot see them.

**Prerequisites before activation (fixes required first):**
- None for mechanical activation — combat.php already calls `checkReactions()`.
- Required for meaningful activation: the iode quadratic fix (Change #2) because the
  Halogénation reaction (Cl>=80 + I>=80 = +20% speed) currently requires investing in iode,
  which is currently a dead atom. After the iode fix, Halogénation becomes a genuine strategic
  choice: sacrifice some combat atoms for speed + energy synergy.
- The Sulfuration reaction (S>=100 + N>=50 = +20% pillage) becomes more attractive after the
  soufre divisor fix (Change #1) because soufre investment is no longer a trap.

**Activation steps:**
1. Add reaction status display to molecule detail page (`armee.php` or similar) showing:
   - Which reactions are active for each class pair.
   - Atom thresholds required for potential reactions.
2. Add reaction outcomes to combat report (`rapport.php`): "Combustion active: +15% attack."
3. Add reaction tooltips to molecule creation page (`creer.php`): "Tip: combining 100 O in one
   class with 100 C in another activates Combustion (+15% attack)."

**UI implementation pattern (no new PHP logic needed):**
In armee.php, after loading molecule data, call `checkReactions()` with the player's own classes
and display a list of active reactions:
```php
$myClasses = [];
for ($c = 1; $c <= $nbClasses; $c++) {
    if (${'classe' . $c}['nombre'] > 0) $myClasses[$c] = ${'classe' . $c};
}
$myReactions = [];
checkReactions($myClasses, $nbClasses, $myReactions);
// Then display $myReactions in the UI
```

**Expected player impact:**
Reactions incentivize atom diversity. Currently optimal play is single-atom-focus (all O, all C,
etc.). Once reactions are visible, players will mix atom types to trigger bonuses:
- Combustion: O>=100 + C>=100 = +15% attack. Encourages maintaining both attack and defense.
- Hydrogénation: H>=100 + Br>=100 = +15% HP. Makes H+Br molecule a viable HP tank class.
- Halogénation: Cl>=80 + I>=80 = +20% speed (after iode fix: also energy). Logistics specialist.
- Sulfuration: S>=100 + N>=50 = +20% pillage (after S divisor fix). Raider specialist.
- Neutralisation: O>=80 + H>=80+C>=80 = +15% defense. Hybrid class archetype.

**Timeline:** Can activate immediately after Changes #1 and #2 are deployed. Estimated
development: 2-3 hours for UI additions.

---

### D.2 PHASE 2: SPECIALIZATIONS (Activate Second)

**Current state:** Fully defined in `$SPECIALIZATIONS` (config.php lines 571-629) with three
trees (combat, economy, research). Each tree has an unlock condition (building milestone) and two
mutually exclusive options. However:
1. No UI page exists to view or choose specializations.
2. No code reads `spec_combat`, `spec_economy`, `spec_research` columns from the database.
3. No code applies specialization effects anywhere in the game logic.

**The specializations are completely inert.** Even if a player had these database columns set,
they would have zero effect.

**Prerequisites before activation:**
- Phase 1 reactions must be live first (reactions reveal atom diversity value; specializations
  then lock in that diversity through permanent choices).
- ionisateur must be made damageable (Change #3) before combat specializations activate.
  Reason: the Oxydant option gives +10% attack. If ionisateur is indestructible, this compounds
  with the existing offense asymmetry. After Change #3, both Oxydant (offense) and Réducteur
  (defense) have symmetric risk profiles.
- The medal grace period (Change #6) should be live before specializations, since veteran players
  unlocking Oxydant on top of high medal tiers would otherwise create another snowball vector.

**Database prerequisite:**
Check if `spec_combat`, `spec_economy`, `spec_research` columns exist in the players/constructions
table. If not, add migration:
```sql
ALTER TABLE constructions
    ADD COLUMN spec_combat TINYINT DEFAULT 0,
    ADD COLUMN spec_economy TINYINT DEFAULT 0,
    ADD COLUMN spec_research TINYINT DEFAULT 0;
```

**Activation steps:**
1. **Add a specializations UI page** (`specialisations.php` or tab in `constructions.php`):
   - For each specialization tree, show current unlock status.
   - If building prerequisite met and spec = 0: show choice UI with two options.
   - If spec != 0: show chosen option (irreversible — display warning).
   - Handle POST to set spec column (validate building level server-side, CSRF protected).

2. **Wire effects in game logic:**
   - `spec_combat`: Applied in `attaque()` / `defense()` in formulas.php.
     ```php
     $specData = dbFetchOne($base, 'SELECT spec_combat FROM constructions WHERE login=?', 's', $joueur);
     if ($specData['spec_combat'] == 1) $bonus *= (1 + 0.10); // Oxydant: +10% attack
     if ($specData['spec_combat'] == 2) $bonus *= (1 - 0.05); // Réducteur: -5% attack
     // For defense formula:
     if ($specData['spec_combat'] == 1) $bonus *= (1 - 0.05); // Oxydant: -5% defense
     if ($specData['spec_combat'] == 2) $bonus *= (1 + 0.10); // Réducteur: +10% defense
     ```
   - `spec_economy`: Applied in `revenuAtome()` and `revenuEnergie()` in `game_resources.php`.
     ```php
     if ($specData['spec_economy'] == 1) $prod *= 1.20; // Industriel: +20% atoms
     if ($specData['spec_economy'] == 1) $energy *= 0.90; // Industriel: -10% energy
     if ($specData['spec_economy'] == 2) $energy *= 1.20; // Énergétique: +20% energy
     if ($specData['spec_economy'] == 2) $prod *= 0.90; // Énergétique: -10% atoms
     ```
   - `spec_research`: Applied in `tempsFormation()` and condenseur point distribution logic.
     ```php
     if ($specData['spec_research'] == 1) $condenseurPointsPerLevel += 2; // Théorique
     if ($specData['spec_research'] == 1) $formationTime *= 1.20; // -20% speed
     if ($specData['spec_research'] == 2) $formationTime *= 0.80; // Appliqué: +20% speed
     if ($specData['spec_research'] == 2) $condenseurPointsPerLevel -= 1; // -1 point/level
     ```

3. **Integration test:** Verify that choosing Oxydant with ionisateur level 15 actually shows
   +10% in combat previews and reports.

**Unlock conditions review:**
Current thresholds: ionisateur 15 (combat), producteur 20 (economy), condenseur 15 (research).
These are asymmetric: aggressive players rush ionisateur, reaching combat spec early. Economic
players build producteur last, reaching economy spec latest. This reinforces offense-first meta.

Proposed rebalance:
```
combat spec unlock: ionisateur >= 15 (unchanged)
economy spec unlock: producteur >= 15 (was 20 — reduces early disadvantage for economic players)
research spec unlock: condenseur >= 12 (was 15 — makes research spec accessible earlier)
```

Config change:
The unlock levels are not currently in config.php as constants — they are hardcoded in
`$SPECIALIZATIONS` array. Refactor to:
```php
define('SPEC_COMBAT_UNLOCK_LEVEL', 15);
define('SPEC_ECONOMY_UNLOCK_LEVEL', 15);  // was effectively 20
define('SPEC_RESEARCH_UNLOCK_LEVEL', 12); // was effectively 15
```

**Timeline:** Requires Phase 1 live plus Changes #3, #6. Estimated development: 6-8 hours
for UI + logic wiring + tests.

---

### D.3 PHASE 3: PRESTIGE CROSS-SEASON PROGRESSION (Activate Last)

**Current state:** Prestige system is largely functional. `prestige.php` handles PP calculation,
award, unlock purchasing, and bonus functions. `prestigeCombatBonus()` and
`prestigeProductionBonus()` are called from `combat.php` and `game_resources.php`. The system
DOES work but has two problems:

**Problem 1 — Snowball amplifier:**
Prestige PP rewards favor top players: rank 1-5 get +50 PP per season on top of activity-based
PP. A veteran player with Maître Chimiste (500 PP, +5% combat) has a permanent combat advantage
that a new player cannot acquire in their first season. Combined with medal bonuses (+50% at
Diamond Rouge), the veteran total multiplier stack is:
```
medal * ionisateur * duplicateur * prestige = 1.50 * 1.40 * 1.25 * 1.05 = 2.756x base attack
```
A new player has: `1.01 * 1.00 * 1.00 * 1.00 = 1.01x`. Ratio: 2.73:1 from bonuses alone.

**Problem 2 — PP calculation missing defensive activity:**
```php
// From prestige.php line 79:
if ($autre['nbattaques'] >= 10) $pp += 5;
```
There is no equivalent PP bonus for successful defenses or number of defenses mounted. The
prestige system rewards aggressive play (+5 PP for 10+ attacks) but ignores defensive activity,
compounding the offense-dominant meta.

**Prerequisites before UI exposure:**
- Phase 2 must be live (specializations create enough build diversity that prestige becomes a
  meaningful differentiation layer rather than a blunt power multiplier).
- Medal grace period (Change #6) must be live. Prestige effects should be gated similarly:
  Maître Chimiste (+5% combat) should not apply during the first 14-day grace period.

**Required fixes before fully activating:**
1. Add defensive PP reward:
   ```php
   // Add to calculatePrestigePoints() in prestige.php:
   if (isset($autre['nbDefenses']) && $autre['nbDefenses'] >= 10) $pp += 5;
   // (requires tracking nbDefenses in autre table — new column)
   ```
   SQL migration: `ALTER TABLE autre ADD COLUMN nbDefenses INT DEFAULT 0;`
   In combat.php when `$gagnant == 1` (defender wins): increment `nbDefenses` for the defender.

2. Gate prestige combat bonus with grace period:
   ```php
   function prestigeCombatBonus($login) {
       if (!hasPrestigeUnlock($login, 'maitre_chimiste')) return 1.0;
       // Check if within grace period
       $seasonStart = ...; // timestamp of current season start
       if (time() - $seasonStart < MEDAL_GRACE_PERIOD_DAYS * SECONDS_PER_DAY) return 1.0;
       return 1.05;
   }
   ```

3. Rebalance PP rank bonuses to reduce top-heavy concentration:
   ```php
   // Current:
   if ($rank <= 5)  $pp += 50;
   if ($rank <= 10) $pp += 30;
   if ($rank <= 25) $pp += 20;
   if ($rank <= 50) $pp += 10;

   // Proposed (flatter curve):
   if ($rank <= 5)  $pp += 25;  // was 50
   if ($rank <= 10) $pp += 15;  // was 30
   if ($rank <= 25) $pp += 10;  // was 20
   if ($rank <= 50) $pp += 5;   // was 10
   ```
   Top players still earn more PP but the gap is halved. A rank-1 player earns `25 + activity`
   PP vs `25` max activity PP for a fully engaged new player — ratio 2:1 instead of the current
   `50 + activity` vs `0 rank bonus` = potentially >3:1.

**Activation steps:**
1. Deploy prestige fixes (defensive PP, grace period gating, flatter rank bonus curve).
2. Add prestige UI page or section in player profile showing:
   - Current PP total.
   - Available unlocks and their costs.
   - Already-purchased unlocks.
   - PP earned last season (breakdown: activity + medals + rank bonus).
3. The prestige shop (purchase unlocks with PP) already has backend logic in
   `purchasePrestigeUnlock()`. Only the UI page is missing.
4. Add prestige status to player profile display: show unlock badges (Débutant Rapide, Expérimenté, etc.).

**Timeline:** Requires Phases 1+2 live plus Change #6 (medal grace period). Estimated
development: 4-6 hours for UI + fixes + tests.

---

### D.4 Dependency Chain Diagram

```
PREREQUISITE CONSTANTS                FEATURE ACTIVATION ORDER
────────────────────────              ──────────────────────────────────────
Change #1: PILLAGE_SOUFRE_DIVISOR ──► PHASE 1: CHEMICAL REACTIONS (UI layer only)
Change #2: IODE_QUADRATIC ──────────►   Halogénation & Sulfuration now meaningful
Change #9: ISOTOPE_REACTIF_DECAY ──► PHASE 2: SPECIALIZATIONS (after ~2 weeks live)
Change #3: NUM_DAMAGEABLE_BUILDINGS ►   Spec_combat now balanced (ionisateur vulnerable)
Change #6: MEDAL_GRACE_PERIOD ──────►   Spec unlocks don't compound veteran gap
Change #4: WIN_COOLDOWN ─────────────►   Chain-attack reduced; defense spec viable
                                      ┃
                                      ▼
                                    PHASE 3: PRESTIGE (after ~4 weeks live)
                                      + Defensive PP tracking
                                      + Grace period gating
                                      + Flatter rank bonus curve
                                      + PP shop UI
```

**Critical constraint:** Do NOT activate prestige UI or specialization UI before Changes #3
and #6 are deployed. If veterans can stack specialization bonuses on top of current unmodified
medal bonuses and indestructible ionisateur, the power gap against new players becomes
structurally unrecoverable within a single season.

---

## E. Implementation Sequence (Recommended Order)

The following order minimizes regression risk and maintains game playability throughout:

| Step | Change | Files Modified | Risk | When |
|------|--------|---------------|------|------|
| 1 | `PILLAGE_SOUFRE_DIVISOR` 3→2 | config.php | Minimal | Immediate |
| 2 | `ISOTOPE_REACTIF_DECAY_MOD` 0.50→0.20 | config.php | Minimal | Immediate |
| 3 | `DUPLICATEUR_COST_FACTOR` 2.5→2.0 | config.php | Minimal | Immediate |
| 4 | `VP_PLAYER_RANK51_100_BASE/_STEP` change | config.php | Minimal | Immediate |
| 5 | `FORMATION_PHALANX_ABSORB` 0.70→0.60, `_DEFENSE_BONUS` 0.30→0.20 | config.php | Low | Immediate |
| 6 | `FORMATION_AMBUSH_ATTACK_BONUS` 0.25→0.15 | config.php | Low | Immediate |
| 7 | Add `IODE_QUADRATIC_COEFFICIENT` + update formula | config.php, formulas.php | Low | Week 1 |
| 8 | Add ionisateur to damageable buildings | config.php, combat.php, DB migration | Medium | Week 1 |
| 9 | Add win-cooldown constant + combat.php branch | config.php, combat.php | Low | Week 1 |
| 10 | Deterministic building targeting | combat.php | Low | Week 1 |
| 11 | Dispersée HP-weighted distribution | combat.php | Low | Week 1 |
| 12 | Embuscade power-based trigger | combat.php | Medium | Week 2 |
| 13 | Chemical reactions UI (armee.php, rapport.php) | UI files only | Low | Week 2 |
| 14 | Medal grace period constants + logic | config.php, formulas.php | Medium | Week 2 |
| 15 | Specializations: DB migration + UI + logic | New page + 3 includes | High | Week 3+ |
| 16 | Prestige: defensive PP + flatter curve + UI | prestige.php + new UI | Medium | Week 4+ |

---

## F. Quick Reference — All Constants Changed

```php
// SECTION A fixes (atom parity)
define('PILLAGE_SOUFRE_DIVISOR', 2);              // was: 3
define('IODE_ENERGY_COEFFICIENT', 0.04);          // was: 0.10
define('IODE_QUADRATIC_COEFFICIENT', 0.003);      // NEW
define('FORMATION_LEVEL_DIVISOR', 30);            // was: 20

// SECTION B fixes (formations)
define('FORMATION_PHALANX_ABSORB', 0.60);         // was: 0.70
define('FORMATION_PHALANX_DEFENSE_BONUS', 0.20);  // was: 0.30
define('FORMATION_AMBUSH_ATTACK_BONUS', 0.15);    // was: 0.25

// SECTION C fixes (combat/balance)
define('NUM_DAMAGEABLE_BUILDINGS', 5);            // was: 4 (add ionisateur)
define('ATTACK_COOLDOWN_WIN_SECONDS', 3600);      // NEW: 1 hour cooldown on win
define('DUPLICATEUR_COST_FACTOR', 2.0);           // was: 2.5
define('ISOTOPE_REACTIF_DECAY_MOD', 0.20);        // was: 0.50
define('VP_PLAYER_RANK51_100_BASE', 6);           // was: 3
define('VP_PLAYER_RANK51_100_STEP', 0.08);        // was: 0.04
define('VP_PLAYER_RANK21_50_BASE', 12);           // was: 15
define('VP_PLAYER_RANK21_50_STEP', 0.23);         // was: 0.5
define('MEDAL_GRACE_PERIOD_DAYS', 14);            // NEW
define('MEDAL_GRACE_CAP_TIER', 3);                // NEW (Gold tier = index 2 in 0-indexed array)

// SECTION D fixes (dead features / prestige)
// New prestige rank bonuses (in prestige.php, not config.php constants):
// rank <= 5:  +25 PP (was +50)
// rank <= 10: +15 PP (was +30)
// rank <= 25: +10 PP (was +20)
// rank <= 50: +5 PP  (was +10)
// SPEC unlock levels (new constants for $SPECIALIZATIONS array):
define('SPEC_COMBAT_UNLOCK_LEVEL', 15);   // unchanged
define('SPEC_ECONOMY_UNLOCK_LEVEL', 15);  // was effectively 20
define('SPEC_RESEARCH_UNLOCK_LEVEL', 12); // was effectively 15
```

---

*End of BAL-CROSS.md — Unified Balance Roadmap*
*Sources: BAL R1 (42 findings, BAL-R1-001 through BAL-R1-042)*
*         BAL R2 (26 findings, BAL-R2-001 through BAL-R2-026)*
*         includes/config.php, includes/formulas.php, includes/combat.php*
*         includes/prestige.php, includes/catalyst.php*
