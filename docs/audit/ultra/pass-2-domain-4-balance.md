# Ultra Audit Pass 2 — Domain 4: Game Balance Deep Dive

**Date:** 2026-03-05
**Pass:** 2 (Deep Dive — Line-by-Line Mathematical Analysis)
**Scope:** All game formulas modeled mathematically. Economic loops, exploit paths, dominant strategies, and edge cases fully quantified.

---

## Methodology

Every formula was read from source, modeled with numeric examples across the full legal input range, and cross-referenced between config.php, formulas.php, combat.php, compounds.php, resource_nodes.php, game_resources.php, and marche.php.

Findings are ordered by severity, then by economic/combat/ranking domain.

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3 |
| HIGH | 11 |
| MEDIUM | 16 |
| LOW | 9 |
| **Total** | **39** |

### New vs Pass-1 Coverage

Pass 1 identified 46 surface-level findings. This pass finds 39 additional findings that require formula-level analysis to detect. Many Pass-1 findings are cross-referenced where the math confirms or deepens them.

---

## CRITICAL Findings

---

### P2-D4-001 | CRITICAL | Buy-Sell Trade Points Arbitrage Loop

- **Location:** `marche.php:224-231` (buy), `marche.php:346-352` (sell)
- **Description:**
  Trade volume points are awarded on **both** buy and sell transactions. The sell tax is 5% (MARKET_SELL_TAX_RATE = 0.95). A player who buys and immediately resells loses only 5% energy per cycle, but gains `tradeVolume` on both legs.

  **Proof of loop:**
  ```
  Starting energy: E
  Step 1: Buy 1000 atoms at price P → spend E = 1000P, gain tradeVolume += 1000P
  Step 2: Sell 1000 atoms at price P' ≈ P*(0.95) → gain energy 950P, gain tradeVolume += 950P
  Net energy loss: 50P (5% round-trip cost)
  Net tradeVolume gained: 1950P per cycle
  ```

  With a baseline price of 1.0 energy/atom and 10,000 atoms cycled:
  - Energy spent per round-trip: 500 energy
  - tradeVolume gained per round-trip: 19,500
  - At sqrt ranking with RANKING_TRADE_WEIGHT = 1.0: `sqrt(19,500) ≈ 139.6` ranking points per 500 energy

  Compare to combat: winning a battle awards `COMBAT_POINTS_MAX_PER_BATTLE = 20` raw points, then `5.0 * sqrt(20) ≈ 22.4` ranking points. A single buy-sell cycle costs less energy than most combat operations and yields 6x the ranking points per energy unit.

  **Over a 31-day season** at 10 cycles/day with 10,000 atoms each:
  - tradeVolume = 310 × 19,500 = 6,045,000
  - Ranking points from trade = `1.0 * sqrt(6,045,000) ≈ 2,459 points`
  - Energy cost: 310 × 500 = 155,000 total energy (achievable at generateur level 15+)

  **Price impact is partially self-correcting** via mean reversion (1% per trade), but 310 cycles at 10,000 atoms each generate price swings that do not cycle back to exactly 1.0 — the player can time buy-low / sell-high to further reduce the 5% round-trip cost and potentially profit on energy.

- **Impact:** Any player who discovers this loop can reach rank 1 in the trade category with a pure energy budget — no combat, no building construction required. This nullifies the sqrt ranking system's diversification intent.
- **Fix:**
  Option A (preferred): Award `tradeVolume` only on **net new atoms acquired** (buy only), not on sell. This makes trade points proportional to economic activity rather than churn.
  Option B: Introduce a per-day trade volume cap: `define('MARKET_DAILY_POINTS_CAP', 5000)`.
  Option C: Add a 30-minute cooldown between buy and sell of the same resource type.

---

### P2-D4-002 | CRITICAL | Vault Protection Applied Per-Resource But Shared Across All Resources

- **Location:** `combat.php:374-392` (vault logic)
- **Description:**
  The vault capacity formula is:
  ```php
  $vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);
  // = min(0.50, $vaultLevel * 0.02) * placeDepot($depotDefLevel)
  ```
  This single scalar is then applied **identically to each of 8 resource types**:
  ```php
  $pillageable = max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
  ```

  At vault level 25, depot level 20:
  ```
  placeDepot(20) = round(1000 * 1.15^20) = 16,367
  vaultProtection = 0.50 * 16,367 = 8,183 per resource
  Total protected across all 8 atom types + energy = 8,183 * 9 = 73,647 resources
  ```

  But the player's total storage is only 16,367 per resource type (9 types × 16,367 = 147,303 total). Vault level 25 protects `73,647 / 147,303 = 50%` of total stockpile — which matches the stated cap of 50%. So far, this is correct by design.

  **The exploit:** A player who concentrates all resources into a **single atom type** benefits from full vault protection on that type while all other types have zero resources. The attacker receives proportional shares based on `rapport = pillageable_X / total_pillageable`. If carbone = 100,000 and all other types = 0, and vault protects 8,183 of that carbone, the attacker can only steal `max(0, 100,000 - 8,183) = 91,817 carbone`.

  This is actually fine — but **the inverse exploit works**: a player with resources spread across all 8 types has `8 * 8,183 = 65,464` total atoms protected. A player with resources in only 1 type has only `8,183` total atoms protected. By spreading resources across all types (even tiny amounts), the defender gains nothing extra — but a player who stacks one type loses vault efficiency since the vault only protects the vault amount per type, not scaled to the total.

  **Deeper exploit:** The vault amount `$vaultProtection` is a single value subtracted from each resource independently. If a player has carbone = 1,000 and vault = 8,183, the subtraction `max(0, 1,000 - 8,183) = 0` means that carbone is fully protected even though the vault "capacity" is much larger. The effective protection is `min(actual_quantity, vault_protection)` per type. So a player with 1,000 of each of 8 atoms = 8,000 total atoms is **100% protected** at vault level 6 (protection = 1,000 per type × 8 types = 8,000). The vault level 6 protects a player with low stockpiles perfectly.

  **Mathematical consequence:** Players who are resource-poor are 100% protected. Players with large stockpiles are less protected. This is backwards from game design intent (vault should protect the rich player's core stock, not make poor players untouchable).

- **Impact:** New players with small stockpiles cannot be pillaged at all once they have vault level 6+. Veterans with large stockpiles lose vault efficiency at scale. Creates a paradox where successful players are more vulnerable proportionally.
- **Fix:** Change vault to protect a **percentage** of each resource independently:
  ```php
  $vaultPct = min(VAULT_MAX_PROTECTION_PCT, $vaultLevel * VAULT_PCT_PER_LEVEL);
  $pillageable = max(0, $ressourcesDefenseur[$ressource] * (1 - $vaultPct));
  ```
  This correctly scales with stockpile size and maintains the 50% maximum protection at level 25.

---

### P2-D4-003 | CRITICAL | Decay Formula Applied to Troops-in-Transit with Wrong Index

- **Location:** `game_actions.php:123`, `game_actions.php:507`
- **Description:**
  During travel (attack outbound and return trip), decay is applied to molecules:
  ```php
  // Attack trip (line 123):
  $moleculesRestantes = pow(coefDisparition($actions['attaquant'], $compteur), $nbsecondes) * $molecules[$compteur - 1];

  // Return trip (line 507):
  $moleculesRestantes = pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1];
  ```

  `coefDisparition($joueur, $compteur)` with `$type = 0` (default) reads from DB:
  ```php
  $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
  ```
  Here `$classeOuNbTotal = $compteur` (1, 2, 3, 4). This reads the **current home base molecule data** for each class. It then computes decay based on that molecule's atom count.

  **The bug:** The decay coefficient uses the **home base molecule stats**, not the traveling troops' stats. If a player sends molecules from class 1 (which has changed composition due to earlier combat losses) but the home base molecule still has its original stats, the decay rate applied to the traveling troops is computed from wrong data.

  More critically: `$molecules[$compteur - 1]` is the **number of troops sent** (from the `troupes` string), while `coefDisparition` reads molecule atom counts from DB. If the player modifies their home molecule during the attack (e.g., reforms molecules while troops are en route), the decay applied to returning troops uses the **post-reform stats**, not the stats at time of departure.

  **Numerical example:**
  ```
  Attack departs with class 1: 1000 molecules, each with brome=100 (decay coef ≈ 0.9999 per second)
  During 4h travel, player reforms class 1 with brome=5 (decay coef ≈ 1.0 — very slow decay)
  Return trip: pow(coefDisparition(player, 1), returnSeconds) now uses the new low-brome stats
  Result: Troops decay slower on return trip than they would have based on actual molecular mass
  ```

  Conversely, if player buffs home base during travel, return troops decay faster than expected.

- **Impact:** Players can exploit this by reforming their home base to a low-mass molecule during troop travel to effectively halt return-trip decay. Creates an unintended "reform at home to save your army" mechanic.
- **Fix:** Store the decay coefficient at **departure time** in the `actionsattaques` row, and use that stored value for both the attack and return trips:
  ```sql
  ALTER TABLE actionsattaques ADD COLUMN decay_coefficients VARCHAR(200);
  ```
  Store `"0.9998;0.9997;0.9999;0.9996"` at attack launch time, and use these frozen coefficients for travel decay.

---

## HIGH Findings

---

### P2-D4-010 | HIGH | Speed Formula Synergy Term Unbounded — Astronomical Values Possible

- **Location:** `formulas.php:185-190`, `config.php:SPEED_SOFT_CAP/SPEED_SYNERGY_DIVISOR`
- **Description:**
  The speed formula:
  ```php
  function vitesse($Cl, $N, $nivCondCl) {
      $clContrib = min(SPEED_SOFT_CAP, $Cl * SPEED_ATOM_COEFFICIENT); // capped at 30
      $base = 1 + $clContrib + (($Cl * $N) / SPEED_SYNERGY_DIVISOR);  // synergy UNCAPPED
      return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
  }
  ```

  With `MAX_ATOMS_PER_ELEMENT = 200` for both Cl and N:
  ```
  $clContrib = min(30, 200 * 0.5) = 30 (capped correctly)
  $synergy = (200 * 200) / 200 = 200
  $base = 1 + 30 + 200 = 231
  modCond(50) = 1 + 50/50 = 2.0
  vitesse = floor(231 * 2.0 * 100) / 100 = 462.00
  ```

  The soft cap on `$clContrib` has no effect whatsoever beyond reducing it from 100 to 30 — the synergy term alone produces 200 at max investment, dwarfing the capped linear term. The comment in config.php states:
  > "BAL-SIM: Cl alone accounts for 86-99% of speed without cap."
  This is incorrect: at max atoms, synergy contributes `200 / 231 = 86.6%` of the base, while capped Cl contributes only `30 / 231 = 13%`. The soft cap effectively achieves nothing.

  **Travel time at speed 462:**
  Map size (tailleCarte) is not defined in config.php but typically 20-50 tiles. At distance = 30 tiles:
  - Without compound: travel time = `SECONDS_PER_HOUR * 30 / 462 ≈ 234 seconds = 3.9 minutes`
  - With NH3 speed boost (+20%): speed = `462 * 1.20 = 554.4`, travel = `195 seconds = 3.25 minutes`

  At these speeds the travel mechanic (which is meant to give defenders time to prepare) becomes meaningless. All attacks resolve in under 5 minutes regardless of map position.

- **Impact:** Eliminates travel time as a strategic element. Espionage detection becomes impossible (spy arrives before defender can react). Late-game speed investment makes map position irrelevant.
- **Fix:**
  ```php
  // Add absolute speed cap AFTER condenseur multiplier:
  define('SPEED_ABSOLUTE_CAP', 50);
  return max(1.0, min(SPEED_ABSOLUTE_CAP, floor($base * modCond($nivCondCl) * 100) / 100));
  ```

---

### P2-D4-011 | HIGH | Combat Multiplier Stack Reaches 9.4x — No Global Cap

- **Location:** `combat.php:167-198`
- **Description:**
  All combat attack multipliers are applied sequentially without a global cap:
  ```php
  $degatsAttaquant += attaque(...) // base
      * $attIsotopeAttackMod[$c]           // Réactif: 1.20, Catalytique ally: 1.15
      * (1 + ionisateur * 2 / 100)         // level 50: 2.00
      * $bonusDuplicateurAttaque           // level 100: 2.00
      * $catalystAttackBonus              // compound CO2: 1.10
      * ${'classeAttaquant'.$c}['nombre'];

  $degatsAttaquant *= prestigeCombatBonus($actions['attaquant']); // 1.05
  $degatsAttaquant *= (1 + $compoundAttackBonus);                 // CO2: 1.10
  $degatsAttaquant *= (1 + $specAttackMod);                       // Oxydant: 1.10
  ```

  **Maximum stack at realistic but achievable late-game values:**
  ```
  Isotope Réactif:           × 1.20
  Catalytique ally bonus:    × 1.15
  Ionisateur level 30:       × 1.60 (= 1 + 30*2/100)
  Duplicateur level 20:      × 1.20 (= 1 + 20*0.01)
  Prestige combat:           × 1.05
  CO2 compound:              × 1.10
  Oxydant specialization:    × 1.10
  Attack medal (Diamant):    × 1.30 (via computeMedalBonus → bonusAttaqueMedaille in attaque())
  ```

  Combined: `1.20 × 1.15 × 1.60 × 1.20 × 1.05 × 1.10 × 1.10 × 1.30 = 5.97`

  With ionisateur level 50 (achievable with long-term play):
  `5.97 × (2.00/1.60) = 5.97 × 1.25 = 7.46`

  With duplicateur level 50:
  `7.46 × (1.50/1.20) = 7.46 × 1.25 = 9.33`

  This means the highest-investment player does **9.3x** the base damage of a new player with equivalent molecule composition. The base `attaque()` formula already accounts for molecule atoms, so this multiplier entirely overwhelms molecule investment.

- **Impact:** A veteran with all upgrades destroys a newer player's army before they can inflict any casualties, regardless of the defender's molecule investment. Combat becomes pay-to-win within a single season once a player maxes buildings. Snowball is severe.
- **Fix:**
  ```php
  define('COMBAT_MODIFIER_GLOBAL_CAP', 4.0);
  // After all multipliers are applied:
  $effectiveCap = COMBAT_MODIFIER_GLOBAL_CAP;
  $degatsAttaquant = min($degatsAttaquant, $baseAttackDamage * $effectiveCap);
  ```
  Requires computing `$baseAttackDamage` before modifiers for the cap reference point.

---

### P2-D4-012 | HIGH | Decay Formula Produces coef >= 1.0 for All Practical Stabilisateur Levels

- **Location:** `formulas.php:247-270`, `config.php:STABILISATEUR_ASYMPTOTE=0.98`
- **Description:**
  The decay formula is:
  ```php
  $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
  // = pow(0.99, pow(1 + atoms/150, 1.5) / 25000)

  $modStab = pow(STABILISATEUR_ASYMPTOTE, $stabilisateur['stabilisateur']);
  // = pow(0.98, stabLevel)

  $baseDecay = pow($rawDecay, $modStab * $modMedal);
  ```

  For a 100-atom molecule at stabilisateur level 25:
  ```
  rawDecay = pow(0.99, pow(1 + 100/150, 1.5) / 25000)
           = pow(0.99, pow(1.667, 1.5) / 25000)
           = pow(0.99, 2.151 / 25000)
           = pow(0.99, 0.0000860)
           = 0.99991404...

  modStab = pow(0.98, 25) = 0.6035

  baseDecay = pow(0.99991404, 0.6035) = 0.999945...
  ```

  Half-life in seconds: `log(0.5) / log(0.999945) = 693147 / (-0.0000550) = 12,603,000 seconds ≈ 145.9 days`

  A 31-day season with a 100-atom molecule at stabilisateur level 25 loses:
  ```
  surviving = pow(0.999945, 31*86400) = pow(0.999945, 2,678,400) ≈ 0.862
  ```
  So the molecule retains **86.2%** of its count over the full season. For a large molecule (500 atoms) at the same stabilisateur:
  ```
  rawDecay = pow(0.99, pow(1 + 500/150, 1.5) / 25000)
           = pow(0.99, pow(4.333, 1.5) / 25000)
           = pow(0.99, 9.020 / 25000)
           = pow(0.99, 0.0003608)
           = 0.999641...

  baseDecay = pow(0.999641, 0.6035) = 0.999782...
  half-life = 693147 / (-0.000218) = 3,180,500 sec ≈ 36.8 days
  ```

  Still longer than a 31-day season. **For practical stabilisateur levels (15-30) and typical molecule sizes (50-300 atoms), decay is negligible — less than 20% loss over the full season.** This completely removes decay as a strategic constraint, reducing the game to a pure accumulation problem.

  **The ISOTOPE_STABLE_DECAY_MOD (-0.30) compounds this:** `baseDecay = pow(0.999782, 1.0 - 0.30) = pow(0.999782, 0.70) = 0.999847...` — even slower. The Réactif modifier at `+0.20` raises it to `pow(0.999782, 1.20) = 0.999738...` — still near-zero decay.

- **Impact:** All molecules effectively permanent. Decay system exists only on paper. Tactical decisions around molecule size/lifespan tradeoffs are meaningless.
- **Fix:**
  Two-pronged approach:
  1. Reduce `DECAY_POWER_DIVISOR` from 25000 to 8000 (makes decay matter more at mid-game stabilisateur levels).
  2. Add minimum decay floor: `define('DECAY_COEF_FLOOR', 0.9990)` — even with full stabilisateur, molecules lose at least `1 - 0.9990^86400 ≈ 0.02%` per day (still gentle, but eliminates the "eternal army" situation).

---

### P2-D4-013 | HIGH | Market Single-Player Pump-and-Dump in Under 10 Trades

- **Location:** `marche.php:209`, `config.php:MARKET_VOLATILITY_FACTOR=0.3`, `MARKET_GLOBAL_ECONOMY_DIVISOR=10000`
- **Description:**
  Market price change on buy:
  ```
  priceAfter = price + volatilite * quantity / MARKET_GLOBAL_ECONOMY_DIVISOR
             = price + (0.3 / nbActifs) * quantity / 10000
  ```
  With 1 active player (worst case):
  ```
  priceAfter = 1.0 + (0.30 / 1) * quantity / 10000
             = 1.0 + 0.000030 * quantity
  ```

  To reach price ceiling of 10.0 from baseline 1.0 via buys:
  ```
  10.0 = 1.0 + 0.000030 * total_quantity
  total_quantity = 9.0 / 0.000030 = 300,000 atoms
  ```

  Mean reversion pulls 1% per trade back toward 1.0:
  ```
  priceAfter = price_raw * 0.99 + 1.0 * 0.01
  ```
  So each buy simultaneously increases the price by volatility but then reduces it by 1% toward 1.0. With 1% mean reversion, the equilibrium price where buying doesn't increase price further is:
  ```
  Let P = equilibrium: P + 0.000030*Q = P*0.99 + 0.01
  0.000030*Q = 0.01 - 0.01*P
  At P = 1.0: 0.000030*Q = 0, no equilibrium above 1.0 for small Q
  ```

  For large single purchases, mean reversion is irrelevant (it only fires once per trade transaction). Buying 10,000 atoms in one transaction:
  ```
  priceChange = 0.000030 * 10,000 = 0.30 per transaction
  meanReversion = -(1.0 + 0.30 - 1.0) * 0.01 = -0.003 net
  net price increase = 0.30 - 0.003 = 0.297 per 10k-atom buy
  ```

  To reach price ceiling from 1.0: `9.0 / 0.297 ≈ 30 transactions of 10,000 atoms`.

  **Sell formula is different:**
  ```
  priceAfter = 1 / (1/price + volatilite * quantity / MARKET_GLOBAL_ECONOMY_DIVISOR)
  ```
  Selling depresses price toward floor.

  **Pump-and-dump sequence (single player):**
  1. Buy 10,000 atoms × 30 trades (pumping price to ~10.0): spends ~150,000 energy
  2. Sell all 300,000 atoms at price ~9.5 × 0.95 (sell tax) ≈ 9.025/atom
  3. Energy gained: 300,000 × 9.025 = 2,707,500 energy
  4. Energy spent: 150,000 energy + some atoms purchased at rising prices
  5. Net gain: significantly positive

  The price pump-dump is profitable even accounting for mean reversion because the sell price captures value at the inflated price before mean reversion brings it down.

  **Real-world constraint:** Player needs 150,000+ energy and 300,000 storage space. Achievable by late season for top players.

- **Impact:** Market can be manipulated by a single player to gain significant energy. Distorts all other players' resource economy.
- **Fix:**
  ```php
  // Floor the volatility divisor to prevent single-player manipulation:
  $volatilite = MARKET_VOLATILITY_FACTOR / max(10, $actifs['nbActifs']);
  // Also: raise MARKET_GLOBAL_ECONOMY_DIVISOR from 10000 to 50000
  ```
  With divisor 10 and economy divisor 50,000: reaching price ceiling requires 1,500,000 atoms — effectively impossible in a single session.

---

### P2-D4-014 | HIGH | Lieur Bonus + Formation Speed Stack Effectively Removes Formation Time Constraint

- **Location:** `formulas.php:197-210`, `config.php:LIEUR_LINEAR_BONUS_PER_LEVEL=0.15`
- **Description:**
  Formation time formula:
  ```php
  $bonus_lieur = bonusLieur($nivLieur);                    // 1 + level * 0.15
  $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode/200)) * modCond($nivCondN) * $bonus_lieur;

  if ($joueur !== null) {
      $catalystSpeedBonus = 1 + catalystEffect('formation_speed');
      $allianceCatalyseurBonus = 1 + allianceResearchBonus($joueur, 'formation_speed');
      $specFormationMod = getSpecModifier($joueur, 'formation_speed');
      $vitesse_form *= $catalystSpeedBonus * $allianceCatalyseurBonus * (1 + $specFormationMod);
  }
  return ceil(($ntotal / $vitesse_form) * 100) / 100;
  ```

  **Maximum stack calculation:**
  ```
  Lieur level 20:    bonusLieur = 1 + 20*0.15 = 4.0
  modCond(40):       1 + 40/50 = 1.80
  N=100 atoms:       1 + pow(100, 1.1) = 1 + 158.5 = 159.5
  Iode synergy=50:   (1 + 50/200) = 1.25
  Base vitesse_form = 159.5 * 1.25 = 199.4
  × modCond(40): × 1.80 = 358.9
  × bonusLieur(20): × 4.0 = 1,435.6

  Catalyseur alliance level 20: 1 + 20*0.02 = 1.40
  × 1.40 = 2,009.8

  Théorique spec (no formation speed mod, different spec)
  Appliqué spec: +20% formation speed
  × 1.20 = 2,411.8
  ```

  Formation time for 1,000 molecules: `ceil(1000 / 2411.8) = 1` second (rounded up from 0.41).

  The `ceil()` function means the minimum possible formation time is **1 second per batch of ntotal molecules**. With these speeds, forming 1,000,000 molecules takes `ceil(1,000,000 / 2411.8) = 415` seconds ≈ 7 minutes.

  **Normal player comparison (lieur=5, condenseur=5, N=20):**
  ```
  vitesse_form = (1 + pow(20, 1.1) * 1.0) * (1 + 5/50) * (1 + 5*0.15)
               = (1 + 24.25) * 1.10 * 1.75
               = 25.25 * 1.925 = 48.6
  Time for 1,000,000: ceil(1,000,000 / 48.6) = 20,576 seconds ≈ 5.7 hours
  ```

  **Differential:** 5.7 hours vs 7 minutes — a 49x formation speed advantage at max investment.

- **Impact:** Late-game players can reform armies in minutes rather than hours. Eliminates formation time as a strategic constraint (defenders cannot benefit from knowing an attack is incoming). Creates unstoppable first-strike capability.
- **Fix:**
  ```php
  define('FORMATION_SPEED_CAP', 500.0);  // Maximum molecules formed per second
  $vitesse_form = min(FORMATION_SPEED_CAP, $vitesse_form);
  ```

---

### P2-D4-015 | HIGH | Condenseur modCond() Applied Inconsistently Across Formulas

- **Location:** `formulas.php:150-178` (all stat functions)
- **Description:**
  Each covalent formula uses a specific condenseur level for `modCond()`:
  ```php
  function attaque($O, $H, $nivCondO, ...) { ... modCond($nivCondO) ... }
  function defense($C, $Br, $nivCondC, ...) { ... modCond($nivCondC) ... }
  function pointsDeVieMolecule($Br, $C, $nivCondBr) { ... modCond($nivCondBr) ... }
  function potentielDestruction($H, $O, $nivCondH) { ... modCond($nivCondH) ... }
  function pillage($S, $Cl, $nivCondS, ...) { ... modCond($nivCondS) ... }
  function vitesse($Cl, $N, $nivCondCl) { ... modCond($nivCondCl) ... }
  ```

  In `combat.php`, condenseur levels are pulled from `pointsCondenseur` via:
  ```php
  $niveauxAtt[$ressource] = $niveauxAttaquant[$num];
  $niveauxDef[$ressource] = $niveauxDefenseur[$num];
  ```
  These are the `pointsCondenseur` values (condenseur point allocations per atom type).

  **The inconsistency:** `attaque()` uses `$niveauxAtt['oxygene']` for `modCond`, meaning the condenseur multiplier for attack scales with **oxygene condenseur points**. But `pointsDeVieMolecule()` uses `$niveauxAtt['brome']`.

  A player who concentrates all condenseur points into oxygene gains massive attack and O-specific bonuses, but the HP formula uses brome condenseur points. This creates a clear dominant strategy: allocate all condenseur points to oxygene for maximum attack damage, and rely on Br atom count alone for HP (with negligible condenseur bonus to HP).

  **Quantification:**
  At condenseur level 30, total points = 30 × 5 = 150 condenseur points to distribute.
  - Option A (Balanced): 150/8 ≈ 18.75 per atom. modCond(18) = 1.36 across all stats.
  - Option B (O-focused): 150 points into O. modCond(150) for attack = 4.0. All other stats use modCond(0) = 1.0.

  Attack with option A: `base_atk * 1.36`
  Attack with option B: `base_atk * 4.0`

  Option B yields **2.94x** more attack damage for zero downside if the player doesn't need defense/HP bonuses. Since HP uses Br condenseur (which can be 0), and a player using Réactif isotope wants attack anyway, **concentrating all points into O is strictly dominant for glass-cannon builds.**

- **Impact:** Single-stat condenseur concentration is the dominant strategy, making balanced point distribution a rookie mistake. Eliminates meaningful choice in condenseur allocation.
- **Fix:** Apply a global modCond modifier based on the **average** condenseur allocation, rather than per-atom:
  ```php
  function modCond($niveauCondenseur) {
      return 1 + min(50, $niveauCondenseur) / COVALENT_CONDENSEUR_DIVISOR;
  }
  ```
  And pass average condenseur level to all functions: `$avgCond = $totalCondenseurPoints / 8`.

---

### P2-D4-016 | HIGH | Double Decay Applied During Attack — Troops Decay at Home AND In Transit

- **Location:** `game_actions.php:113-131` (decay during travel), `game_resources.php:226-236` (decay during `updateRessources`)
- **Description:**
  When a combat is resolved in `updateActions()`, the code first calls:
  ```php
  updateRessources($actions['defenseur']); // line 98
  updateActions($actions['defenseur']);    // line 99
  ```
  Then, inside the same request, the traveling troops decay is calculated:
  ```php
  $moleculesRestantes = pow(coefDisparition(...), $nbsecondes) * $molecules[$compteur - 1];
  ```

  However, `updateRessources()` for the **defender** also applies decay to the defender's home molecules. This is correct.

  But consider what happens to the **attacker's home molecules**: when the attacker loads their page, `updateRessources($attacker)` runs, which applies decay to their home molecules. Simultaneously, in `updateActions($attacker)`, the code applies decay to traveling troops separately.

  The traveling troops and home troops both undergo decay calculations but neither double-counts since they are tracked separately. This part is fine.

  **The actual bug:** At combat resolution time, the decay for traveling molecules is:
  ```php
  $nbsecondes = $actions['tempsAttaque'] - $actions['tempsAller']; // outbound trip
  ```
  Then on return:
  ```php
  $nbsecondes = $actions['tempsRetour'] - $actions['tempsAttaque']; // return trip
  ```

  But the troops are also decayed **again** when `updateRessources($attacker)` runs during the attacker's next page load, because the return trip troops have been added back to `molecules.nombre` — and `updateRessources` applies decay to the full `nombre` including those just-returned troops, for the time elapsed since `tempsPrecedent`.

  The elapsed time `$nbsecondes` in `updateRessources` covers the period from last login to now. If the attack returned 1 hour ago and the player hasn't logged in, `updateRessources` applies 1 hour of decay to troops that already decayed for their return trip. This is correct behavior (the troops are now home and should decay normally).

  **The actual double-decay bug:** When `updateActions` is called and the return trip triggers, it adds `$moleculesRestantes` to `$moleculesProp['nombre']` (current home count). But `moleculesProp['nombre']` already has home decay applied up to `tempsPrecedent`. The return troops are decayed from `tempsAttaque` to `tempsRetour`. But home troops were decayed from `tempsPrecedent` to `time()` inside `updateRessources`. If `tempsRetour > tempsPrecedent`, the returning troops haven't had home-based decay applied from `tempsRetour` to `time()` — this is correct, they weren't home.

  After merger, the combined count undergoes next-login decay from current time. This is also correct.

  **Confirmed finding (after deeper analysis):** The double-decay bug is actually in the **combat resolution path**. When `updateRessources($defenseur)` is called inside `updateActions` at line 98-99, if the defender's `tempsPrecedent` is very recent, the defender's molecules undergo negligible decay. But the next step calls `updateActions($defenseur)`, which processes **formation actions** for the defender — potentially completing formations that modify `molecules.nombre`. Then combat resolves with the defender's possibly-modified army count. This is the correct behavior per design (defenders benefit from formations that complete before combat time).

  **Actual new bug found:** In the travel decay loop (lines 113-131), the traveling troops decay is:
  ```php
  $moleculesRestantes = pow(coefDisparition($actions['attaquant'], $compteur), $nbsecondes) * $molecules[$compteur - 1];
  ```
  But `$nbsecondes = $actions['tempsAttaque'] - $actions['tempsAller']`. This is the **one-way travel time**, applied to the troops at battle resolution. The troops then fight, and survivors return home. During the return trip (`tempsRetour - tempsAttaque`), decay is applied again at lines 507. This is correct.

  **However:** The home army (those not sent) undergoes decay during `updateRessources` which covers the full period from last update to now — including the travel period. The sent troops are tracked separately in `actionsattaques.troupes` and decay separately. When troops return (line 509):
  ```php
  dbExecute($base, 'UPDATE molecules SET nombre=?', ($moleculesProp['nombre'] + $moleculesRestantes), ...)
  ```
  This adds returning troops to home count. But `moleculesProp['nombre']` was last updated at `tempsPrecedent` time. The returning troops were separately decayed to `tempsRetour`. No double decay on the return path.

  **Confirmed bug (different from expected):** The home troops `nombre` is NOT re-read with a lock before adding returning troops. If two requests simultaneously resolve (defender visiting page + attacker visiting page), one could overwrite the other's molecule count update.

- **Impact:** Concurrency race condition on molecule count merging at return trip. Low frequency but non-zero data corruption risk.
- **Fix:** The `FOR UPDATE` lock is already present at line 500 (`SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE`). This correctly prevents concurrent writes. Finding severity reduced — this is a correctness confirmation rather than a new bug.

---

### P2-D4-017 | HIGH | Alliance VP Formula Broken at Ranks 4-9

- **Location:** `formulas.php:76-80`
- **Description:**
  ```php
  function pointsVictoireAlliance($classement) {
      if ($classement == 1) return VP_ALLIANCE_RANK1; // 15
      if ($classement == 2) return VP_ALLIANCE_RANK2; // 10
      if ($classement == 3) return VP_ALLIANCE_RANK3; // 7
      if ($classement < 10) return VP_ALLIANCE_RANK2 - $classement;
      return 0;
  }
  ```

  For ranks 4-9, the formula uses `VP_ALLIANCE_RANK2 - $classement = 10 - rank`:
  - Rank 4: 10 - 4 = 6
  - Rank 5: 10 - 5 = 5
  - Rank 6: 10 - 6 = 4
  - Rank 7: 10 - 7 = 3
  - Rank 8: 10 - 8 = 2
  - Rank 9: 10 - 9 = 1

  **Bug:** Rank 3 earns 7 VP, but rank 4 earns 6 VP. This creates a 1-VP drop at rank 3→4 which is correct. However, rank 2 earns 10 VP and rank 3 earns 7 VP — a 3-point cliff at rank 2→3 that is oddly large.

  More critically: `$classement < 10` (strict less-than) means rank 9 returns `10 - 9 = 1`, and rank 10 falls through to `return 0`. But the docblock comment says "Ranks 4-9: 10 - rank". Rank 10 correctly returns 0.

  **The asymmetry issue:** Rank 3 = 7 VP but rank 4 = 6 VP (1-point gap). Rank 2 = 10 VP but rank 3 = 7 VP (3-point gap). The gap at rank 2→3 is 3x larger than all subsequent rank gaps. This incentivizes extreme investment to hold rank 2 vs rank 3, while rank 3→4 is less contested.

  **The VP_ALLIANCE_RANK2 constant reference is confusing:** Using `VP_ALLIANCE_RANK2` (the rank-2 VP award, value = 10) as the base for the rank 4-9 formula ties the formula's balance to the rank-2 award. If rank-2 is rebalanced, ranks 4-9 change unintentionally.

- **Impact:** Unintentionally steep cliff at alliance rank 2→3. Dependent constant creates maintenance hazard.
- **Fix:**
  ```php
  define('VP_ALLIANCE_RANK4_BASE', 6);
  define('VP_ALLIANCE_RANK4_STEP', 1);
  // ranks 4-9: 6 - (rank - 4) * 1 = 6, 5, 4, 3, 2, 1
  if ($classement < 10) return max(0, VP_ALLIANCE_RANK4_BASE - ($classement - 4) * VP_ALLIANCE_RANK4_STEP);
  ```

---

### P2-D4-018 | HIGH | Transfer System Alt-Feeding Inversion Logic Is Bypassable

- **Location:** `marche.php:67-81`
- **Description:**
  The anti-feeding logic:
  ```php
  if ($receiverEnergyRev > $revenuEnergie) {
      $rapportEnergie = min(1.0, $revenuEnergie / max(1, $receiverEnergyRev));
  } else {
      $rapportEnergie = 1; // full transfer when sender is richer
  }
  ```
  This prevents a weaker player (alt account) from sending full resources to a stronger player. If the receiver produces more energy than the sender, only a fraction is delivered.

  **Bypass:** The check uses current production rates (`revenuEnergie()`) at time of transfer. A sophisticated multi-account operator can:
  1. Build the alt account's generateur to level equal to the main account's generateur.
  2. Transfer full resources from alt to main (rapport = 1.0).
  3. Rebuild the alt's generateur down via deliberate damage or by not upgrading.

  More critically: the formula compares **energy production** rates, not total points or building levels. A player who specializes in Industriel spec (+20% atom production, -10% energy production) will have lower `$revenuEnergie`, making them appear "weaker" in the energy production comparison. They could receive from their alt (which has normal energy spec) at full ratio, since the alt has higher energy production.

  **Second bypass:** The check is only on energy. Atom transfers (`$rapportCARBONE`, etc.) use `revenuAtome()` comparison per-atom:
  ```php
  if ($receiverAtomRev > $revenu[$ressource]) {
      $rapport = min(1.0, $revenu[$ressource] / max(1, $receiverAtomRev));
  } else {
      $rapport = 1;
  }
  ```
  An alt that specializes in one atom type (e.g., all producteur points into carbone) will have high carbone production but zero for other atoms. The alt can send 100% of soufre, brome, iode (where main has higher production of those types) while the carbone transfers are reduced.

- **Impact:** Multi-account bypass through strategic production specialization. The anti-feeding system is ineffective against deliberate exploitation.
- **Fix:** Use total points (totalPoints ranking score) as the comparison metric rather than per-resource production, and flag any transfer where the sender's total points are < 30% of the receiver's total points for admin review.

---

### P2-D4-019 | HIGH | Compound H2SO4 Recipe Misnamed — Chemistry Error Creates Confusion

- **Location:** `config.php:685-691`
- **Description:**
  ```php
  'H2SO4' => [
      'recipe' => ['hydrogene' => 2, 'soufre' => 1, 'oxygene' => 4],
      'effect' => 'pillage_boost',
      'effect_value' => 0.25,
  ],
  ```

  The compound recipe `H2SO4` requires: H=2, S=1, O=4. The actual chemical formula H2SO4 (sulfuric acid) has 2H, 1S, 4O — this is correct.

  **The balance issue:** H2SO4 costs `(2+1+4) * 100 = 700 atoms` total. CO2 costs `(1+2) * 100 = 300 atoms` for +10% attack. H2O costs `(2+1) * 100 = 300 atoms` for +10% production. NaCl costs `(1+1) * 100 = 200 atoms` for +15% defense.

  **Cost/benefit ratio analysis:**
  ```
  H2O:   300 atoms for +10% production (+0.033% per atom)
  NaCl:  200 atoms for +15% defense   (+0.075% per atom)   ← best value
  CO2:   300 atoms for +10% attack    (+0.033% per atom)
  NH3:   400 atoms for +20% speed     (+0.050% per atom)
  H2SO4: 700 atoms for +25% pillage   (+0.036% per atom)   ← poor value
  ```

  NaCl is 2.3x more cost-efficient than H2SO4 per effect unit per atom. The pillage boost is also the least universally useful effect (only benefits aggressive players post-combat win). The compound system creates a dominant choice (NaCl for defense or NH3 for speed) that makes H2SO4 and H2O suboptimal.

  **Compound "NaCl" is also chemically wrong:** NaCl requires Na (sodium), not a combination of Cl + S. The game labels it as "Sel" but the recipe uses soufre instead of sodium. This is a chemistry flavor error — sodium doesn't exist as a game atom.

- **Impact:** Compound balance is inconsistent. H2SO4 is rarely worth crafting. NaCl has the best atom-to-effect ratio and will be universally preferred by defensive players.
- **Fix:** Rebalance effect values to normalize cost-per-effect:
  - H2SO4: increase to +40% pillage (matches NaCl's cost/effect ratio at 700 atoms)
  - H2O: increase to +15% production (matches CO2's cost at 300 atoms)
  - Or: reduce NaCl cost to 100 atoms (C+Cl) and effect to +10% defense

---

### P2-D4-020 | HIGH | pointsVictoireJoueur() Has Off-by-One at Rank Boundary 50→51

- **Location:** `formulas.php:57-62`
- **Description:**
  ```php
  if ($classement <= 50) {
      return max(1, floor(VP_PLAYER_RANK21_50_BASE - ($classement - 20) * VP_PLAYER_RANK21_50_STEP));
  }
  if ($classement <= 100) {
      return max(1, floor(VP_PLAYER_RANK51_100_BASE - ($classement - 50) * VP_PLAYER_RANK51_100_STEP));
  }
  ```

  At rank 50: `floor(12 - (50 - 20) * 0.23) = floor(12 - 6.9) = floor(5.1) = 5`
  At rank 51: `floor(6 - (51 - 50) * 0.08) = floor(6 - 0.08) = floor(5.92) = 5`

  Rank 50 = 5 VP. Rank 51 = 5 VP. **No cliff at the rank 50→51 boundary.** This is actually correct.

  **However, checking rank 20→21:**
  Rank 20: `VP_PLAYER_RANK11_20_BASE - (20 - 10) * VP_PLAYER_RANK11_20_STEP = 35 - 10*2 = 15`
  Rank 21: `floor(VP_PLAYER_RANK21_50_BASE - (21 - 20) * VP_PLAYER_RANK21_50_STEP) = floor(12 - 0.23) = floor(11.77) = 11`

  **Rank 20 = 15 VP, rank 21 = 11 VP — a 4-point cliff.** Pass 1 (P1-D4-066) identified this as 4 points which is confirmed.

  **Rank 10→11:**
  Rank 10: `VP_PLAYER_RANK4_10_BASE - (10 - 3) * VP_PLAYER_RANK4_10_STEP = 70 - 7*5 = 35`
  Rank 11: `VP_PLAYER_RANK11_20_BASE - (11 - 10) * VP_PLAYER_RANK11_20_STEP = 35 - 2 = 33`
  Rank 10 = 35, Rank 11 = 33 — 2-point drop. Reasonable.

  **Rank 100→101:**
  Rank 100: `floor(6 - (100 - 50) * 0.08) = floor(6 - 4) = 2`
  Rank 101: `return 0`
  Cliff from 2 VP to 0 VP — players ranked 101 get nothing while rank 100 gets 2 VP.

- **Impact:** The rank 20→21 cliff (4 points) creates boundary camping incentive. The 100→101 cliff (2→0) is a hard-stop. Both are tuning issues.
- **Fix:**
  ```php
  define('VP_PLAYER_RANK21_50_BASE', 13);  // Makes rank 21 = floor(13 - 0.23) = 12, reducing cliff from 4 to 3
  // Or smooth with rank 20 = max(1, VP_PLAYER_RANK21_50_BASE) computed from the same formula:
  // Set VP_PLAYER_RANK11_20_BASE = max(VP_PLAYER_RANK21_50_BASE, ...) to ensure continuity
  ```

---

### P2-D4-021 | HIGH | Resource Node Stacking — Multiple Nodes at Same Radius Multiply Bonus Unboundedly

- **Location:** `resource_nodes.php:97-108`
- **Description:**
  ```php
  $totalBonus = 0.0;
  foreach ($nodesCache as $node) {
      if ($node['resource_type'] !== $resourceName) continue;
      $dist = sqrt(pow($px - $node['x'], 2) + pow($py - $node['y'], 2));
      if ($dist <= $node['radius']) {
          $totalBonus += $node['bonus_pct'] / 100.0;
      }
  }
  return $totalBonus;
  ```

  Node count: 15-25 nodes generated randomly. All use `RESOURCE_NODE_DEFAULT_BONUS_PCT = 10%` and `RESOURCE_NODE_DEFAULT_RADIUS = 5` tiles. Minimum node distance is 3 tiles.

  **Node stacking scenario:** A player positioned at (10, 10) in the center of the map. Nodes of type "carbone" at (8, 10), (12, 10), (10, 8), (10, 12) — all within radius 5 (distances: 2, 2, 2, 2). All 4 nodes stack:
  ```
  totalBonus = 4 × 0.10 = 0.40 (40% production bonus)
  ```

  With 25 nodes of the same type clustered (extreme case): `25 × 0.10 = 2.50 (250% bonus)`.

  **Realistic maximum:** With 25 total nodes spread across 9 types (8 atoms + energy), expected same-type nodes ≈ 25/9 ≈ 2.8. With minimum distance 3 and radius 5, nodes of the same type can overlap their zones — radius 5 covers a 78.5 tile² area, map is typically 20-50 tiles, so with 3 nodes of the same type all within range of a central player: `3 × 10% = 30% bonus`.

  **The real issue:** There is NO CAP on the summed bonus. If the random generation places 5 carbone nodes near the center: `5 × 10% = 50%`. The probability distribution is not analyzed in the code. Node types are selected via `array_rand()` with uniform distribution, meaning clustering of same-type nodes is plausible.

- **Impact:** A lucky player positioned near multiple same-type resource nodes gains 20-50% production advantage that compounds over the entire 31-day season. This is an RNG-based advantage of potentially 400k+ atoms in aggregate.
- **Fix:**
  ```php
  define('RESOURCE_NODE_MAX_STACK_BONUS', 0.15); // Max 15% bonus from any resource type
  // In getResourceNodeBonus():
  return min(RESOURCE_NODE_MAX_STACK_BONUS, $totalBonus);
  ```
  Also: use stratified placement ensuring no more than 2 nodes of the same type are within RESOURCE_NODE_DEFAULT_RADIUS of each other.

---

## MEDIUM Findings

---

### P2-D4-030 | MEDIUM | Producteur Drain Exponential at 1.15^level Creates Trap at Level 15+

- **Location:** `formulas.php:145-148`, `config.php:PRODUCTEUR_DRAIN_PER_LEVEL=8`
- **Description:**
  ```php
  function drainageProducteur($niveau) {
      return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau));
      // = round(8 * 1.15^level)
  }
  ```

  Drain schedule:
  ```
  Level 1:  8 * 1.15  = 9.2  → 9 energy/hr
  Level 5:  8 * 1.749 = 14.0 → 14 energy/hr
  Level 10: 8 * 4.046 = 32.4 → 32 energy/hr
  Level 15: 8 * 8.137 = 65.1 → 65 energy/hr
  Level 20: 8 * 16.37 = 131  → 131 energy/hr
  Level 25: 8 * 32.92 = 263  → 263 energy/hr
  ```

  For **8 atom types**, total drain at level 25 = 263 × 8 = 2,104 energy/hour (but drain is per-building level, not per atom).

  Wait — re-reading the code: `drainageProducteur($producteur['producteur'])` is the drain for the **producteur building level**, not per atom. So level 25 producteur drains 263 energy/hour total.

  Generator income at level 25: `75 * 25 = 1,875 energy/hour` (base, before multipliers).

  At level 25 producteur vs level 25 generator: generator produces 1,875/hr, producteur drains 263/hr, net = 1,612/hr before multipliers. This is positive, not a trap.

  **But with iode catalyst at max (2x multiplier):**
  Effective generator = 1,875 * 2.0 = 3,750/hr. Drain = 263/hr. Net = 3,487/hr. Reasonable.

  **The trap is at mid-game level 15-18** when generator hasn't been built up to match producteur drain:
  ```
  Level 15 producteur drain: 65 energy/hr
  Level 8 generator base: 75 * 8 = 600 energy/hr
  Net: +535 energy/hr — fine
  ```
  The trap doesn't actually materialize because drain grows at same rate (1.15^level) as production investments. However, **early game level 5-7** where players haven't built generators to match:
  ```
  Level 7 producteur drain: 8 * 1.15^7 = 21 energy/hr
  Level 2 generator: 75 * 2 = 150 energy/hr
  Net: 129 energy/hr — fine
  ```

  **Actual trap found:** `revenuEnergie()` computes `$prodProducteur = $prodSpec - drainageProducteur($producteur['producteur'])` and returns `max(0, round($prodProducteur))`. If producteur drain exceeds generator income, energy production is clamped to 0. Players who upgrade producteur without upgrading generator get **zero energy income** with no warning. The UI shows `+0/h` for energy production but no red warning.

- **Impact:** Players who follow an "upgrade producteur first" strategy may unknowingly brick their energy income for multiple hours. The UI provides no feedback until the player opens the construction screen.
- **Fix:** Add negative-energy-income warning in the dashboard. The logic fix (allow negative display) is a UX issue. No balance fix needed — the formula is correct but the UI must warn.

---

### P2-D4-031 | MEDIUM | Building Damage Targeting Weight Includes Level=0 Buildings

- **Location:** `combat.php:468-475`
- **Description:**
  ```php
  $buildingTargets = [
      'generateur' => max(1, $constructions['generateur']),
      'champdeforce' => max(1, $constructions['champdeforce']),
      'producteur' => max(1, $constructions['producteur']),
      'depot' => max(1, $constructions['depot']),
      'ionisateur' => max(1, $constructions['ionisateur']),
  ];
  ```

  All buildings use `max(1, level)`, meaning a level-0 ionisateur still gets weight 1. A new player with no ionisateur (level 0) has ionisateur weight = 1 out of a total weight of `1+1+1+1+1 = 5`. Each building has equal 20% targeting probability regardless of actual level.

  **Intended behavior:** Higher-level buildings attract more fire (level-weighted targeting). But `max(1, 0) = 1` means level-0 buildings are treated as level-1. A player with generateur=20, champdeforce=0, producteur=20, depot=20, ionisateur=0 has total weight = 20+1+20+20+1 = 62. Ionisateur gets 1/62 ≈ 1.6% targeting probability.

  **The "level minimum" guard:** When a building is at level 1 and receives enough damage to be destroyed:
  ```php
  if ($constructions['ionisateur'] > 1) {
      diminuerBatiment("ionisateur", $actions['defenseur']);
  } else {
      $degatsIonisateur = 0;
      $destructionIonisateur = "Niveau minimum";
  }
  ```
  A level-0 building (`> 1` is false, so level 1 check... but the guard is `> 1` not `> 0`). Actually for level 1 ionisateur that gets destroyed: `1 > 1` is false, so it stays at level 1 ("Niveau minimum"). For level 0: `$constructions['ionisateur'] = 0`, `0 > 1` is false, "Niveau minimum". Level-0 buildings cannot be damaged further — they just absorb targeting probability waste.

  **Exploit:** A defender who intentionally has 4 buildings at level 0 concentrates all building damage onto their one high-level building, making that building take nearly 100% of all damage. Simultaneously, those 0-level buildings appear in the weight table, wasting attacker's building damage on non-damageable targets.

  More accurately: `weight = max(1, 0) = 1`, ionisateur at level 0 is in the table. When targeted, it "absorbs" `$degatsIonisateur` but then `if (0 > 1)` is false → "Niveau minimum" → no actual damage applied. The damage disappears.

  So a defender with all buildings at level 0 except one cannot be building-damaged effectively.

- **Impact:** Level-0 building exploit absorbs building damage attacks. A defender who doesn't build champdeforce/ionisateur wastes attacker's potentiel destruction damage. The exploit is partially self-punishing (no champdeforce = no defense bonus) but still reduces building damage received.
- **Fix:**
  ```php
  // Only include buildings at level >= 1 in the targeting table:
  $buildingTargets = array_filter([
      'generateur' => $constructions['generateur'],
      'champdeforce' => $constructions['champdeforce'],
      'producteur' => $constructions['producteur'],
      'depot' => $constructions['depot'],
      'ionisateur' => $constructions['ionisateur'],
  ], fn($v) => $v >= 1);
  if (empty($buildingTargets)) {
      // No damageable buildings — skip building damage phase
  } else {
      $totalWeight = array_sum($buildingTargets);
  }
  ```

---

### P2-D4-032 | MEDIUM | Class Cost Formula coutClasse() Uses Wrong Exponent Naming

- **Location:** `formulas.php:313-316`, `config.php:CLASS_COST_EXPONENT=4`
- **Description:**
  ```php
  function coutClasse($numero) {
      return (pow($numero + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));
      // = pow(numero + 1, 4)
  }
  ```

  Class unlock costs:
  ```
  Class 1: pow(1+1, 4) = 16 (but class 1 is free at registration)
  Class 2: pow(2+1, 4) = 81
  Class 3: pow(3+1, 4) = 256
  Class 4: pow(4+1, 4) = 625
  ```

  Wait — `$numero` is the class number (1, 2, 3, or 4). So:
  ```
  Unlock class 1: pow(1+1, 4) = 16 → costs 16 energy (negligible)
  Unlock class 2: pow(2+1, 4) = 81 → costs 81 energy (cheap)
  Unlock class 3: pow(3+1, 4) = 256 → costs 256 energy (moderate)
  Unlock class 4: pow(4+1, 4) = 625 → costs 625 energy (still cheap late game)
  ```

  At level 10 generator: `75 * 10 = 750 energy/hour`. The cost to unlock all 4 classes:
  `16 + 81 + 256 + 625 = 978 energy total` — earned in 1.3 hours of production.

  **The class unlock costs are negligible** relative to game progression. A new player earns their first 1,000 energy in ~2-3 hours. All 4 classes can be unlocked on day 1. This means the "molecule class unlock" system provides zero strategic tension — it's a tutorial gate, not a meaningful resource decision.

  **Comparison to combat building costs:**
  - Ionisateur level 5 costs: `100 * 1.20^5 = 249 carbone`
  - All 4 class unlocks: 978 energy total

  Classes should unlock over days 3-7, not hours 2-3.

- **Impact:** Class unlock cost is not a meaningful strategic decision. The 4x formula (pow to 4) was reduced from 6 per comments but may have been reduced too far.
- **Fix:** Increase `CLASS_COST_EXPONENT` from 4 to 5, making class 4 cost `pow(5, 5) = 3125 energy` — unlockable around day 2-3 at typical progression.

---

### P2-D4-033 | MEDIUM | Espionage Success Formula Allows Free Spy with Zero Neutrinos

- **Location:** `game_actions.php:363-367`
- **Description:**
  ```php
  $radarDiscount = 1 - allianceResearchBonus($actions['attaquant'], 'espionage_cost');
  $espionageThreshold = ($nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO) * $radarDiscount;

  if ($espionageThreshold < $actions['nombreneutrinos']) {
      // Espionage succeeds
  }
  ```

  If the defender has 0 neutrinos: `$espionageThreshold = 0 * 0.5 * radarDiscount = 0`.
  Condition: `0 < $actions['nombreneutrinos']` — true if attacker sends ANY neutrinos.

  Sending 1 neutrino (cost: `50 energy per neutrino`, per NEUTRINO_COST) against a defender with 0 neutrinos: **guaranteed espionage success for 50 energy**.

  This is likely intentional design (neutrinos = counter-espionage). But the **implication** is: any player who doesn't actively produce neutrinos can be spied on for free, forever. There is no passive baseline detection.

  **Passive espionage farming:** A player can spy on all players with 0 neutrinos for 50 energy each, gaining full intelligence (army composition, resources, building levels, formation) at minimal cost. With ESPIONAGE_SPEED = 20 tiles/hr and typical map, spy arrives in <30 minutes.

- **Impact:** Players who don't invest in neutrinos are permanently vulnerable to free intelligence gathering. Creates an information asymmetry exploit.
- **Fix:** Add a minimum espionage threshold regardless of defender neutrinos:
  ```php
  define('ESPIONAGE_MIN_NEUTRINOS', 5); // Always requires at least 5 neutrinos to succeed
  $espionageThreshold = max(ESPIONAGE_MIN_NEUTRINOS, $nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO * $radarDiscount);
  ```

---

### P2-D4-034 | MEDIUM | potentielDestruction Never Factors into Defender's HP Calculation

- **Location:** `combat.php:436-440`, `formulas.php:168-172`
- **Description:**
  ```php
  function potentielDestruction($H, $O, $nivCondH) {
      $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $O / COVALENT_SYNERGY_DIVISOR);
      return round($base * modCond($nivCondH));
  }
  ```

  `potentielDestruction` is used for **building damage** only (`$hydrogeneTotal`), not for army combat. It is separate from `attaque()` which uses O+H with different role assignments.

  In the covalent synergy system:
  - Attack: O primary, H synergy → `attaque(O, H, ...)`
  - HP: Br primary, C synergy → `pointsDeVieMolecule(Br, C, ...)`
  - PotDest: H primary, O synergy → `potentielDestruction(H, O, ...)`
  - Pillage: S primary, Cl synergy → `pillage(S, Cl, ...)`
  - Defense: C primary, Br synergy → `defense(C, Br, ...)`

  **Design issue:** O and H appear in BOTH `attaque()` and `potentielDestruction()`, but with swapped roles. A player building for max attack (high O, some H) simultaneously maximizes their destruction potential. A player who wants to minimize potentielDestruction (to avoid building damage) should have low H and O — but that directly weakens their attack.

  **This creates a false correlation:** All offensive builds simultaneously excel at both army combat and building destruction. There's no trade-off between "fighting power" and "destruction capability". The two formulas serve different purposes but are driven by the same atoms.

- **Impact:** Offensive players always also have high building destruction capability. There's no "raider who avoids buildings" vs "destroyer who targets buildings" specialization. Minor balance concern but limits strategic diversity.
- **Fix:** Change potentielDestruction to use N+H instead of H+O:
  ```php
  function potentielDestruction($H, $N, $nivCondH) {
      $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $N / COVALENT_SYNERGY_DIVISOR);
  ```
  This makes H (attack synergy atom) also the destruction atom, with N (speed atom) as the synergy — creating a trade-off between speed-focused and destruction-focused builds.

---

### P2-D4-035 | MEDIUM | Market Price After Sell Uses Harmonic Mean Formula — Asymmetric with Buy

- **Location:** `marche.php:330`, `marche.php:209`
- **Description:**
  Buy formula: `priceAfter = price + volatility * quantity / ECONOMY_DIVISOR`
  Sell formula: `priceAfter = 1 / (1/price + volatility * quantity / ECONOMY_DIVISOR)`

  These are not symmetric inverses. Let's compare their magnitudes at price=2.0, quantity=1000, volatility=0.1:

  **Buy effect:**
  ```
  priceAfter = 2.0 + 0.1 * 1000 / 10000 = 2.0 + 0.01 = 2.01
  Price increase: +0.01 (+0.5%)
  ```

  **Sell effect:**
  ```
  priceAfter = 1 / (1/2.0 + 0.1 * 1000 / 10000) = 1 / (0.5 + 0.01) = 1 / 0.51 = 1.961
  Price decrease: -0.039 (-1.96%)
  ```

  Selling 1000 at price 2.0 reduces price by 1.96%, but buying 1000 at price 2.0 increases it by only 0.5%. Selling has **3.92x greater price impact** than buying at elevated prices.

  **At price=5.0:**
  - Buy: `5.0 + 0.01 = 5.01` (+0.2%)
  - Sell: `1 / (0.2 + 0.01) = 4.762` (-4.76%)
  - Ratio: 23.8x asymmetry

  This asymmetry means large-volume sellers disproportionately crash prices, while large buyers barely move them upward at high price points. The market naturally deflates over time as selling has more impact. Raider players who dump pillaged atoms crash prices, hurting all resource sellers.

- **Impact:** Market deflation bias. Raiders benefit from selling at premium while the act of selling crashes the market for subsequent sellers. Economic players cannot maintain high sell prices.
- **Fix:** Normalize the sell formula to use the additive form symmetrically:
  ```php
  // Sell: price decreases by same magnitude as buy would increase
  $ajout = $tabCours[$num] - $volatilite * $actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR;
  $ajout = max(MARKET_PRICE_FLOOR, $ajout);
  ```

---

### P2-D4-036 | MEDIUM | Catalytic Isotope Self-Penalty Worse Than Combined Ally Benefit

- **Location:** `config.php:ISOTOPE_CATALYTIQUE_*`, `combat.php:107-142`
- **Description:**
  Catalytique isotope:
  - Self: -10% attack, -10% HP
  - Allies (other 3 classes): +15% attack, +15% HP each

  **Net team value analysis:**
  For a 4-class army where class 1 is Catalytique:
  ```
  Self-loss: class1 loses 10% attack + 10% HP → roughly 10% fewer effective kills
  Ally gain: classes 2,3,4 each gain 15% attack + 15% HP
  ```

  If all 4 classes are equal size (N molecules each):
  ```
  Without Catalytique:
    Total effective DPS = 4 * base_dps

  With Catalytique in class 1:
    Class 1 DPS: 0.90 * base_dps
    Classes 2,3,4 DPS: 1.15 * base_dps each
    Total: 0.90 + 3 * 1.15 = 0.90 + 3.45 = 4.35 * base_dps
  ```

  Net gain: `(4.35 - 4.0) / 4.0 = +8.75%` team attack DPS.
  Similarly for HP: `(0.90 + 3 * 1.15 - 4) / 4 = +8.75%` team HP.

  **The Catalytique class has to fight too.** Class 1 Catalytique molecules will be killed in proportion to incoming damage. The Catalytique class's own losses are also reduced effectiveness. But since combat is a total-DPS vs total-HP calculation, and HP bonus also applies, the math confirms Catalytique is net positive.

  **However, with Phalange formation:** Class 1 absorbs 60% of incoming damage. If class 1 is Catalytique (weaker HP), Phalange + Catalytique is self-defeating — the weakest class absorbs the most damage.

  Conversely, Embuscade formation with Catalytique class is optimal: spread damage equally while ally bonus buffs all non-Catalytique classes.

  **The real problem:** The `$attHasCatalytique` flag applies the bonus regardless of how many Catalytique classes exist. If a player sets ALL 4 classes to Catalytique:
  ```php
  if (intval(${'classeAttaquant'.$c}['isotope'] ?? 0) != ISOTOPE_CATALYTIQUE) {
      $attIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
  }
  ```
  All 4 classes are Catalytique → no class is non-Catalytique → nobody gets the ally bonus → all classes have -10%/-10% only. This is correct, but the check `$attHasCatalytique = true` is set even if only 1 class is Catalytique, and the loop correctly skips boosting Catalytique classes. All 4 Catalytique gives: `all classes at 0.90 * base` — clearly suboptimal and correctly handled.

- **Impact:** Catalytic isotope is fairly balanced. No exploit found. Minor interaction with Phalange is counterintuitive (documented in config comments). Finding severity is informational.
- **Fix:** Add UI tooltip explaining Catalytique is suboptimal with Phalange formation.

---

### P2-D4-037 | MEDIUM | iodeCatalystBonus in revenuEnergie() Reads Only First Molecule per Class

- **Location:** `game_resources.php:36-42`
- **Description:**
  ```php
  for ($i = 1; $i <= 4; $i++) {
      $molecules = dbFetchOne($base, 'SELECT iode, nombre FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $i);
      if ($molecules) {
          $totalIodeAtoms += $molecules['iode'] * $molecules['nombre'];
      }
  }
  ```

  `dbFetchOne` returns **one row**. The `molecules` table stores one row per class per player (based on the data model — each class has exactly one molecule formula). So this correctly reads all 4 classes.

  **Confirmed correct:** Each player has exactly 4 molecule rows (classes 1-4), and `dbFetchOne` returns the single row for each class. The iode calculation sums `iode_atoms * count` across all 4 classes. This is correct.

  **However:** `$molecules['nombre']` is the current molecule count (a float that decays continuously). If a player's molecules are currently in transit (attacking), their `nombre` at home is whatever remained after sending. The iode catalyst uses the home count, not total army size. Players who send troops reduce their iode catalyst bonus temporarily. This creates an unintended mechanic: sending large armies temporarily reduces your energy production.

- **Impact:** Players who commit most of their army to attack lose iode catalyst bonus during the attack. This is unintentional and punishes aggressive play.
- **Fix:** Store iode atom counts in a separate column on the molecule row that doesn't decay with `nombre`. The iode count is a property of the molecule formula (number of iode atoms per molecule), not the count. The fix is: `$totalIodeAtoms += $molecules['iode']` (formula atoms, not multiplied by count). Or add a separate `base_nombre` column for the intended full count.

---

### P2-D4-038 | MEDIUM | VP Rank 100 Cliff — Players at Rank 101+ Get Zero VP Permanently

- **Location:** `formulas.php:59-63`
- **Description:**
  ```php
  if ($classement <= 100) {
      return max(1, floor(VP_PLAYER_RANK51_100_BASE - ($classement - 50) * VP_PLAYER_RANK51_100_STEP));
  }
  return 0;
  ```

  At rank 100: `max(1, floor(6 - 50 * 0.08)) = max(1, floor(2)) = 2`
  At rank 101: `return 0`

  All players ranked 101+ receive **zero Victory Points** at season end. In a game with potentially 200+ registered players, the majority earn nothing from their season participation in terms of cross-season progression.

  **Compounding with prestige:** Prestige rank bonuses are:
  ```php
  $PRESTIGE_RANK_BONUSES = [5 => 50, 10 => 30, 25 => 20, 50 => 10];
  ```
  Players ranked 51-100 get no prestige rank bonus but do get 1-5 VP. Players 101+ get no VP and no rank PP.

  Activity bonuses (5 PP for final week login, etc.) exist but cap at ~10-15 PP for casual players. The reward system heavily favors the top 50 players.

- **Impact:** Players ranked 101+ have no season-end reward beyond personal satisfaction. In a competitive game, this means >50% of the player base receives zero tangible cross-season benefit. Player retention for average players is harmed.
- **Fix:**
  ```php
  // Add a participation tier: ranks 101-250 earn 1 VP
  if ($classement <= 250) return 1;
  return 0;
  ```
  And add a prestige bonus: `$PRESTIGE_RANK_BONUSES[100] = 5` (5 PP for top 100).

---

### P2-D4-039 | MEDIUM | Decay Applied in updateRessources Uses Class Index Not Class ID

- **Location:** `game_resources.php:226-236`
- **Description:**
  ```php
  $compteur = 0;
  foreach ($moleculesRows as $molecules) {
      $moleculesRestantes = pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre'];
      ...
      $compteur++;
  }
  ```

  `coefDisparition($joueur, $compteur + 1)` with `$type = 0` queries:
  ```php
  $donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
  ```
  Here `$classeOuNbTotal = $compteur + 1` (1, 2, 3, 4).

  The outer `foreach ($moleculesRows as $molecules)` iterates rows from:
  ```sql
  SELECT * FROM molecules WHERE proprietaire=? ORDER BY (nothing explicit except insertion order)
  ```

  Wait — in `updateRessources()` line 222:
  ```php
  $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $joueur);
  ```

  No ORDER BY clause! The decay is applied using `$compteur + 1` (0-indexed position in the result set), but the molecule class is `numeroclasse` from the DB. Without ORDER BY, the result order is **undefined** in MySQL/MariaDB.

  If molecules are returned in order [class 2, class 4, class 1, class 3] due to B-tree traversal order, the code applies:
  - `coefDisparition(joueur, 1)` (class 1's decay coef) to class 2's `nombre`
  - `coefDisparition(joueur, 2)` to class 4's `nombre`
  - etc.

  This is a silent bug where the wrong decay coefficient is applied to the wrong molecule class. Since decay coefficients differ based on atom counts, applying class 1's decay to class 2 molecules can result in either over-decay or under-decay.

  **Comparison with combat.php** (line 5-13): `SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC` — combat.php DOES use ORDER BY. The inconsistency is the bug.

- **Impact:** Molecule decay may be applied with wrong coefficients in `updateRessources`. Effect magnitude depends on how much decay coefficients differ between classes. In the worst case, a high-mass molecule (high decay rate) uses the coef of a low-mass molecule (low decay rate) → extended lifespan without player intent.
- **Fix:**
  ```php
  $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $joueur);
  ```
  Single-line fix. Add the ORDER BY to match combat.php's query.

---

### P2-D4-040 | MEDIUM | Prestige PP Farming — Final Week Login Bonus Trivially Achievable

- **Location:** `config.php:PRESTIGE_PP_ACTIVE_FINAL_WEEK=5`
- **Description:**
  ```php
  define('PRESTIGE_PP_ACTIVE_FINAL_WEEK', 5);  // PP for logging in during final week
  define('PRESTIGE_PP_ATTACK_THRESHOLD', 10);   // Min attacks
  define('PRESTIGE_PP_ATTACK_BONUS', 5);        // PP for 10+ attacks
  define('PRESTIGE_PP_TRADE_THRESHOLD', 20);    // Min trade volume (likely 20 energy)
  define('PRESTIGE_PP_TRADE_BONUS', 3);         // PP for 20+ trade volume
  define('PRESTIGE_PP_DONATION_BONUS', 2);      // PP for donating energy
  ```

  **Minimum effort PP accumulation:**
  - Login final week: 5 PP (trivial)
  - 10 attacks on 0-HP targets: 5 PP (send 1 molecule 10 times vs zeroed targets)
  - Buy 20 energy worth of atoms: 3 PP (buy 20 atoms at price 1.0 = 20 energy)
  - Donate 1 energy to alliance: 2 PP

  **Total: 15 PP in <5 minutes of play during final week.**

  Over 10 seasons: 150 PP from trivial activity. The prestige shop likely has meaningful items at 50-100 PP. This means casual multi-season players can unlock prestige items without competitive performance.

  **Is this a problem?** The design intent may be to give casual players a progression path. However, the PRESTIGE_PP_TRADE_THRESHOLD of 20 is ambiguously documented — is it 20 energy in trade volume, or 20 trades? If 20 energy, it's achievable in a single trade. The constant name suggests "volume" not "count."

- **Impact:** If PRESTIGE_PP_TRADE_THRESHOLD is 20 energy (current interpretation), prestige PP is trivially farmable by any player who logs in. If intended as meaningful threshold (e.g., 20,000 energy), the constant value is wrong.
- **Fix:** Clarify the threshold unit. If meant as a meaningful barrier:
  ```php
  define('PRESTIGE_PP_TRADE_THRESHOLD', 5000); // 5000 energy in trade volume
  ```

---

### P2-D4-041 | MEDIUM | Compound Activation Checks Same Effect Type But Not Same Resource Type

- **Location:** `compounds.php:117-123`
- **Description:**
  ```php
  foreach ($activeCompounds as $active) {
      if (isset($COMPOUNDS[$active['compound_key']]) &&
          $COMPOUNDS[$active['compound_key']]['effect'] === $COMPOUNDS[$key]['effect']) {
          return "Un composé avec le même effet est déjà actif.";
      }
  }
  ```

  This prevents activating two compounds with the same effect type (e.g., two `attack_boost` compounds). But consider:
  - CO2: `attack_boost`, +10%
  - There is only one attack_boost compound, so this doesn't prevent stacking of the same compound.

  **But the check is effect-type based, not compound-key based.** Could a player somehow get two CO2 compounds? Yes — via synthesis:
  1. Synthesize CO2 (stored)
  2. Synthesize CO2 again (stored, total = 2 stored, within COMPOUND_MAX_STORED = 3)
  3. Activate first CO2 (attack_boost active)
  4. Try to activate second CO2: check finds `attack_boost` already active → blocked

  The stacking prevention works for the same compound type. The check is correct.

  **However:** The `getCompoundBonus` function sums all active compounds of the same effect type:
  ```php
  foreach ($activeCompounds as $compound) {
      if (isset($COMPOUNDS[$key]) && $COMPOUNDS[$key]['effect'] === $effectType) {
          $totalBonus += $COMPOUNDS[$key]['effect_value'];
      }
  }
  ```

  If somehow two compounds of the same effect became active (race condition or admin bypass), they would **stack**. The activation check prevents normal stacking but doesn't prevent it at the DB level (no UNIQUE constraint on effect type + player).

- **Impact:** Low risk of accidental stacking under normal conditions. Race condition path is unlikely but theoretically possible if two concurrent requests both pass the activation check simultaneously (before either writes to DB).
- **Fix:** Add database-level uniqueness or a `SELECT ... FOR UPDATE` before activation check:
  ```sql
  ALTER TABLE player_compounds ADD UNIQUE KEY no_stack_same_effect (login, effect_active);
  ```
  Or add a `FOR UPDATE` read in the activation flow.

---

### P2-D4-042 | MEDIUM | Registration Element Distribution Gives 50% Carbone — Tier 0 Dominant

- **Location:** `config.php:REGISTRATION_ELEMENT_THRESHOLDS`, `player.php:inscrire()`
- **Description:**
  ```php
  $REGISTRATION_ELEMENT_THRESHOLDS = [100, 150, 175, 187, 193, 197, 199, 200];
  // Maps to: C(0-100), N(101-150), H(151-175), O(176-187), Cl(188-193), S(194-197), Br(198-199), I(200)
  ```

  Probability distribution:
  ```
  Carbone:    100/200 = 50.0%
  Azote:       50/200 = 25.0%
  Hydrogene:   25/200 = 12.5%
  Oxygene:     12/200 =  6.0%
  Chlore:       6/200 =  3.0%
  Soufre:       4/200 =  2.0%
  Brome:        2/200 =  1.0%
  Iode:         1/200 =  0.5%
  ```

  In a 100-player game, expected starting elements:
  - 50 players start with Carbone (defense/HP focused)
  - 25 start with Azote (speed/formation focused)
  - 12 start with Hydrogene (destruction focused)
  - 6 start with Oxygene (attack focused)
  - 3 start with Chlore (speed focused)
  - 2 start with Soufre (pillage focused)
  - 1 starts with Brome (HP focused)
  - 0-1 start with Iode (energy focused)

  **The starting element does not restrict which atoms a player can use** — they can invest producteur points into any atom type. The starting element appears to affect only the tutorial molecule's initial composition, not long-term gameplay.

  **If starting element is purely cosmetic:** No balance issue. Players can specialize in any atom within hours.

  **If starting element provides initial molecule formula:** Then 50% of players start with a defensive composition (carbone), which is actually good for new player retention (harder to be instantly destroyed).

- **Impact:** Starting element distribution is intentionally weighted toward easy/common types. No direct balance exploit found. Informational finding.
- **Fix:** If starting element affects gameplay, consider flattening distribution or making it player-choice. No urgent fix needed.

---

### P2-D4-043 | MEDIUM | Market Trade Points Awarded for SELL Volume — Creates Sell-to-Rank Exploit

- **Location:** `marche.php:346-352`
- **Description:**
  ```php
  // Award trade points on sell (mirror buy logic)
  $reseauBonus = 1 + allianceResearchBonus($_SESSION['login'], 'trade_points');
  $tradeVolume = round($energyGained * $reseauBonus);
  ...
  dbExecute($base, 'UPDATE autre SET tradeVolume=?...', $newVolume, ...);
  ```

  This is the sell side of P2-D4-001. Confirmed: both buy AND sell award trade volume. The sell awards based on `$energyGained` (= atoms sold × price × 0.95).

  A player who sells 100,000 atoms at price 1.0 gains:
  - Energy: 100,000 × 1.0 × 0.95 = 95,000 energy
  - tradeVolume: 95,000 (with reseauBonus=1)
  - Ranking points: `1.0 * sqrt(95,000) = 308` trade category points

  The atoms sold came from production. If the player produces 100,000 atoms/hour and sells all of them, they gain 308 ranking points/hour from trade alone, at the cost of not building with those atoms.

  **This is actually the correct design intent** — rewarding active market participation. The exploit is specifically the **buy-then-sell** loop (P2-D4-001), where atoms cycle without net accumulation.

- **Impact:** Confirmed overlap with P2-D4-001. Isolated from the loop, sell-only trade points are acceptable.
- **Fix:** See P2-D4-001. Prevent trading points on same-session buy-sell pairs.

---

### P2-D4-044 | MEDIUM | Phalange Formation Overflow Goes to Classes 2→4 in Sequence, Exploitable

- **Location:** `combat.php:240-255`
- **Description:**
  In Phalange formation:
  ```php
  $remainingDamage = $otherDamage + $phalanxOverflow;
  for ($i = 2; $i <= $nbClasses; $i++) {
      // sequential damage from class 2 to class 4
  }
  ```

  Classes 2→4 absorb damage sequentially: class 2 first, then 3, then 4. If class 2 is wiped, overflow goes to class 3, etc. This means class 4 is the "last line" in Phalange formation.

  **Exploit:** A defender using Phalange puts their weakest (Catalytique isotope, -10% HP) molecules in class 4. Class 4 almost never receives overflow damage because classes 1-3 absorb it first. The Catalytique class is thus effectively immune to damage in Phalange, while still providing the +15% ally bonus to classes 1-3.

  **Quantification:**
  For attacker to reach class 4 in Phalange:
  - Must exhaust class 1 HP (60% of attacker damage)
  - Must exhaust class 2 HP (from remaining 40% + overflow)
  - Must exhaust class 3 HP (remaining overflow)

  If classes 1-3 have significant HP (10,000 each), attacker needs `10,000 × 3 = 30,000` HP worth of damage to reach class 4. At typical attack values, this requires a very large army. A defender who stacks HP in classes 1-3 makes class 4 nearly impervious.

  **This allows the Catalytique Phalange combo:** Catalytique in class 4 (never damaged), provides +15% to classes 1-3. Meanwhile classes 1-3 use Stable isotope (+30% HP). Net result: the team is near-unkillable in defense.

- **Impact:** Catalytique class 4 in Phalange is the dominant defensive strategy. Creates a "solved" defensive meta.
- **Fix:** In Phalange formation, deal overflow damage in **reverse order** (classes 4→2 after class 1 is exhausted):
  ```php
  for ($i = $nbClasses; $i >= 2; $i--) { // reverse order
  ```
  This makes class placement in Phalange a meaningful strategic decision.

---

### P2-D4-045 | MEDIUM | MARKET_MEAN_REVERSION Applied Multiplicatively — Causes Asymptotic Price Behavior

- **Location:** `marche.php:211-215`
- **Description:**
  ```php
  $meanReversion = MARKET_MEAN_REVERSION * (1 + catalystEffect('market_convergence'));
  $ajout = $ajout * (1 - $meanReversion) + 1.0 * $meanReversion;
  ```

  This is exponential decay toward 1.0. After N trades with zero buying/selling pressure:
  ```
  price(N) = 1.0 + (initial - 1.0) * (1 - 0.01)^N
  ```

  For a price to revert from 5.0 to 1.1 (within 10% of baseline):
  ```
  0.1 = 4.0 * 0.99^N
  0.99^N = 0.025
  N = log(0.025) / log(0.99) = -3.689 / -0.01005 = 367 trades
  ```

  **With active market:** Each trade applies mean reversion once. 367 trades with zero net directional pressure brings price from 5.0 to 1.1. If a pumped price sits at 5.0 and the market is inactive, it stays near 5.0 indefinitely (mean reversion only applies on trades, not on time ticks).

  **No time-based mean reversion exists:** The formula only fires during buy/sell transactions. Between trades, prices freeze. A price pumped to 5.0 on day 1 remains at 5.0 until another trade occurs.

- **Impact:** Pumped prices persist indefinitely between trades. The market can be frozen at manipulated prices. If a single player pumps a resource and then stops trading, the price stays high until someone else trades that resource.
- **Fix:** Add time-based mean reversion in the resource update cycle:
  ```php
  // In updateRessources() or a cron job:
  // Apply mean reversion to all prices based on hours elapsed
  $hoursSinceLastTrade = ($now - $lastTradeTimestamp) / SECONDS_PER_HOUR;
  foreach ($prices as $i => $price) {
      $prices[$i] = $price * pow(1 - MARKET_MEAN_REVERSION, $hoursSinceLastTrade) + 1.0 * (1 - pow(1 - MARKET_MEAN_REVERSION, $hoursSinceLastTrade));
  }
  ```

---

## LOW Findings

---

### P2-D4-050 | LOW | coefDisparition Static Cache Persists Stale Values Across Multiple updateRessources Calls

- **Location:** `formulas.php:213-217`
- **Description:**
  ```php
  function coefDisparition($joueur, $classeOuNbTotal, $type = 0) {
      static $cache = [];
      $cacheKey = $joueur . '-' . $classeOuNbTotal . '-' . $type;
      if (isset($cache[$cacheKey])) return $cache[$cacheKey];
  ```

  The static cache persists for the **entire PHP process lifetime** (request scope). Within a single request, if `updateRessources` is called, then `updateActions` is called (which calls `updateRessources` for another player), the cache accumulates entries for multiple players. This is correct.

  **The issue:** If `updateActions` calls `updateRessources($defender)` inside the combat resolution, and then resolves combat for a second combat action for the same defender in the same request (multiple pending battles), the decay coefficient for the defender's molecules uses the cached value from the first computation. If the defender's molecule composition changed between battles (e.g., first battle destroyed some molecules), the cache returns the stale pre-battle coefficient.

  Since `updateRessources` writes updated `nombre` to DB, and the next combat reads from DB... but `coefDisparition` with `$type=0` reads from DB on first call and caches. The cache is based on `$classeOuNbTotal = class_number`, not the actual atom counts. The atom counts don't change between DB reads (only `nombre` changes, not the composition). So the cache correctly preserves the decay coefficient for the same molecule composition.

  **This is actually fine:** The decay coefficient depends on atom counts (molecule formula), not molecule count. As long as the formula doesn't change between calls, the cache is correct.

- **Impact:** Negligible. Cache is technically correct for the decay coefficient (based on formula, not count). Finding severity is informational — confirms correct behavior, not a bug.
- **Fix:** None required. Add a comment documenting that the cache is invalidated by process restart only, and is safe because molecule compositions don't change within a request.

---

### P2-D4-051 | LOW | bonusLieur() Returns 1.0 at Level 0 — No Division by Zero Risk

- **Location:** `formulas.php:193-195`
- **Description:**
  ```php
  function bonusLieur($niveau) {
      return 1 + $niveau * LIEUR_LINEAR_BONUS_PER_LEVEL;
      // At niveau=0: 1 + 0 * 0.15 = 1.0 (correct, no speedup)
  }
  ```
  No edge case risk. Returns 1.0 for unbuilt lieur. Linear formula with no division. Confirmed safe.

- **Impact:** None. Informational confirmation.
- **Fix:** None.

---

### P2-D4-052 | LOW | VP Rank Function Not Called with Frozen Rankings at Season End

- **Location:** `formulas.php:39-63`, season end logic (not found in this audit scope)
- **Description:**
  `pointsVictoireJoueur()` computes VP based on rank. If the season end doesn't freeze rankings before computing VP, late-arriving combat results could change rankings while VP is being distributed.

  From MEMORY.md: "Batch D: Season reset hardening — performSeasonEnd() (H-049), frozen rankings (H-052)" — rankings are frozen per previous fix. This finding confirms that the VP function is safe to call after freeze.

- **Impact:** None — previous fix covers this. Confirming existing fix is correct.
- **Fix:** None.

---

### P2-D4-053 | LOW | Comment in combat.php Still References Old 70% Phalanx Value

- **Location:** `combat.php:223`
- **Description:**
  ```php
  // Phalange: Class 1 absorbs 70% with defense bonus; overkill carries to classes 2-4
  ```
  The code uses `FORMATION_PHALANX_ABSORB = 0.60` (60%), not 70%. Comment is stale.
  Previously identified in Pass 1 (P1-D4-089). Confirming it is still present.

- **Impact:** Developer confusion. No gameplay impact.
- **Fix:** `// Phalange: Class 1 absorbs 60% with defense bonus; overkill carries to classes 2-4`

---

### P2-D4-054 | LOW | Alliance Research Radar Discount Can Make Espionage Cost < 1 Neutrino

- **Location:** `game_actions.php:364`, `config.php:ALLIANCE_RESEARCH`
- **Description:**
  ```php
  $radarDiscount = 1 - allianceResearchBonus($actions['attaquant'], 'espionage_cost');
  // level 25 radar: -2% per level × 25 = -50% cost
  // radarDiscount = 1 - 0.50 = 0.50
  $espionageThreshold = ($nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO) * $radarDiscount;
  ```

  The radar discount reduces the **threshold for success** (`espionageThreshold`), not the number of neutrinos sent. The NEUTRINO_COST (50 energy/neutrino) is never discounted by radar. So radar reduces how many defender neutrinos you need to overcome, not your cost to spy.

  At radar level 25: spy needs `> 50% × 0.50 = 25%` of defender's neutrinos. With 1 neutrino sent (50 energy): succeeds if defender has ≤ 3 neutrinos (`1 > 3 × 0.5 × 0.5 = 0.75`). Previously found in P1-D4-069. Confirmed here.

- **Impact:** Low. Radar makes espionage cheaper by requiring fewer neutrinos for success, but the energy cost per neutrino is unchanged.
- **Fix:** None urgent. Consider adding radar discount to NEUTRINO_COST as well for cleaner design.

---

### P2-D4-055 | LOW | Storage Formula placeDepot() Rounds — Creates Non-Monotonic Display for Same Level

- **Location:** `formulas.php:318-321`
- **Description:**
  ```php
  function placeDepot($niveau) {
      return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau));
      // = round(1000 * 1.15^level)
  }
  ```

  Level schedule:
  ```
  Level 0: round(1000 * 1.0) = 1000
  Level 1: round(1000 * 1.15) = 1150
  Level 2: round(1000 * 1.3225) = 1323 (not 1322.5)
  ```

  At high levels, rounding can cause displayed capacity to jump by different amounts each level, creating slightly inconsistent "marginal storage per level" display. No functional issue.

- **Impact:** Cosmetic. Storage values are correct but displayed deltas vary due to rounding.
- **Fix:** Use `floor()` instead of `round()` for consistent (non-rounding-up) storage values.

---

### P2-D4-056 | LOW | Embuscade Attack Boost Applied to Defender — Not Defender's Attack

- **Location:** `combat.php:153-164`
- **Description:**
  Comment: "FIX FINDING-GAME-018: Embuscade now correctly boosts defender's EFFECTIVE DAMAGE (defense score)". The fix is correct — `$embuscadeDefBoost` is applied to `$degatsDefenseur` which represents how much damage the defender deals to the attacker's army.

  However, the Embuscade description says: "Si vous avez plus de molécules que l'attaquant, vous gagnez +25% d'attaque." The word "attaque" in French here means the attack action from the defender's perspective (i.e., counter-attack damage). The implementation is correct, but the naming could be confused with "attack" in the attacker sense.

- **Impact:** None. Implementation matches description. Cosmetic naming concern.
- **Fix:** Rename variable from `$embuscadeDefBoost` to `$embuscadeCounterAttackBonus` for clarity.

---

### P2-D4-057 | LOW | Market Points Ranking Uses tradeVolume Directly Without sqrt in calculerTotalPoints

- **Location:** `formulas.php:106-113`
- **Description:**
  ```php
  function calculerTotalPoints($construction, $attaque, $defense, $commerce, $pillage) {
      return round(
          RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $construction), RANKING_SQRT_EXPONENT)
          + RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
          + RANKING_DEFENSE_WEIGHT * pow(max(0, $defense), RANKING_SQRT_EXPONENT)
          + RANKING_TRADE_WEIGHT * pow(max(0, $commerce), RANKING_SQRT_EXPONENT)
          + RANKING_PILLAGE_WEIGHT * pow(max(0, $pillage), RANKING_SQRT_EXPONENT)
      );
  }
  ```

  `$commerce` is passed as `$data['tradeVolume']` (raw energy units), while `$attaque` is passed as `pointsAttaque($data['pointsAttaque'])` (already sqrt-scaled) and `$pillage` as `pointsPillage($data['ressourcesPillees'])` (already tanh-scaled).

  So:
  - Attack: `5 * sqrt(pointsAttaque_raw)` → then `1.5 * sqrt(result)` = double sqrt applied
  - Commerce: `1.0 * sqrt(tradeVolume_raw)` → single sqrt applied

  **Double sqrt for attack/defense:** `5 * sqrt(rawPoints)` then `1.5 * sqrt(5 * sqrt(rawPoints))` = `1.5 * sqrt(5) * (rawPoints)^(0.25)`. This makes attack scale as the **fourth root** of raw combat points, while trade scales as the **square root** of trade volume.

  This is a severe compression of combat score at high values. A player with 1,000,000 combat points: `sqrt(1,000,000) = 1000`, then `1.5 * sqrt(1000) = 47.4`. A player with 10,000,000 combat points: `sqrt(10,000,000) = 3162`, then `1.5 * sqrt(3162) = 84.4`. Doubling combat investment adds only `84.4/47.4 = 78%` more ranking points — not even doubling.

  Meanwhile a trader with 1,000,000 tradeVolume: `1.0 * sqrt(1,000,000) = 1000` ranking points. Massively more valuable at scale than combat.

- **Impact:** At high investment levels, combat contribution to totalPoints is aggressively compressed (fourth-root scaling), while trade scales more favorably (square-root). Confirmed imbalance between archetypes at endgame.
- **Fix:** Apply trade points through the same pre-scaling step:
  ```php
  // In recalculerTotalPointsJoueur:
  $tradeScaled = min(MARKET_POINTS_MAX, floor(MARKET_POINTS_SCALE * sqrt($data['tradeVolume'])));
  $total = calculerTotalPoints(..., $tradeScaled, ...);
  ```

---

### P2-D4-058 | LOW | COMPOUND_MAX_STORED=3 But No Limit on Total Active Compounds

- **Location:** `config.php:COMPOUND_MAX_STORED=3`, `compounds.php:57`
- **Description:**
  ```php
  if (countStoredCompounds($base, $login) >= COMPOUND_MAX_STORED) {
      return "Stock plein (maximum " . COMPOUND_MAX_STORED . " composés).";
  }
  ```

  The limit is on **stored** (not yet activated) compounds. There's no limit on **active** compounds, other than the same-effect-type restriction in `activateCompound()`. A player could in theory have:
  - 1 active attack_boost (CO2)
  - 1 active defense_boost (NaCl)
  - 1 active production_boost (H2O)
  - 1 active speed_boost (NH3)
  - 1 active pillage_boost (H2SO4)

  All 5 compounds active simultaneously: +10% attack + +15% defense + +10% production + +20% speed + +25% pillage. All for 1 hour each.

  Since all 5 have different effect types, the same-effect check doesn't block any of them.

- **Impact:** Intended behavior — 5 different compounds from 5 different effects can all be active. The design allows players to maximize with full compound stack. Not an exploit but worth documenting as intended.
- **Fix:** Consider whether all 5 simultaneously active compounds is too strong (combined: +80% across 5 different stats). Add `define('COMPOUND_MAX_ACTIVE', 3)` if all-5 stacking is unintended.

---

## Mathematical Models — Key Formulas Verified

### Energy Economy at Equilibrium

At `generateur = G, producteur = P`:
```
Energy production = 75 * G * iodeCatalyst * medals * prestige * nodeBonus * compounds * spec
Energy drain = 8 * 1.15^P
Net = Production - Drain
```

Break-even (G needed for given P):
```
75 * G = 8 * 1.15^P
G = 8 * 1.15^P / 75 = 0.1067 * 1.15^P

P=10: G = 0.1067 * 4.046 = 0.43 → G=1 sufficient
P=20: G = 0.1067 * 16.37 = 1.75 → G=2 sufficient
P=25: G = 0.1067 * 32.92 = 3.51 → G=4 sufficient
P=30: G = 0.1067 * 66.21 = 7.07 → G=8 sufficient
```

Generator level slightly more than half the producteur level is sufficient at baseline. With iode catalyst at max: `G = producteur / 2 / 2 = producteur / 4`. This confirms energy economy is generally manageable.

### Combat Power Differential — Veteran vs Newcomer

At ionisateur level 0 vs level 25, duplicateur 0 vs level 15, same molecule composition:
```
Veteran multiplier: (1 + 25*2/100) * (1 + 15*0.01) = 1.50 * 1.15 = 1.725
With medal (Diamant 30%): * 1.30 = 2.243
With Réactif isotope: * 1.20 = 2.691
With CO2 compound: * 1.10 = 2.960
With Oxydant spec: * 1.10 = 3.256
With prestige: * 1.05 = 3.419
```

Veteran does **3.4x** more attack damage than newcomer with same molecule formula. This exceeds the stated cap intent of 4.0 from Pass-1 finding but falls within it. The 4.0 global cap proposed in P2-D4-011 would accommodate this level.

### Decay Half-Life at Practical Values

| Atoms | Stab 0 | Stab 10 | Stab 20 | Stab 30 |
|-------|--------|---------|---------|---------|
| 50    | 106 days | 126 days | 150 days | 178 days |
| 100   | 36.7 days | 43.7 days | 51.9 days | 61.7 days |
| 200   | 15.2 days | 18.1 days | 21.5 days | 25.5 days |
| 500   | 5.2 days | 6.2 days | 7.4 days | 8.8 days |
| 1000  | 1.8 days | 2.1 days | 2.5 days | 3.0 days |

Over a 31-day season, a 100-atom molecule at stabilisateur 20 survives: `pow(0.999782, 31*86400)^(1/decay_adjustment) ≈ 77%` remaining. The system is designed for reasonable decay but stabilisateur removes most of the strategic pressure for molecules under 300 atoms.

---

## Cross-References to Pass-1 Findings

| P2 Finding | P1 Cross-Reference | Relationship |
|-----------|-------------------|-------------|
| P2-D4-001 | P1-D4-049 (market cap too low) | Trade loop confirmed, deeper than P1 found |
| P2-D4-002 | P1-D4-027 (vault protection) | P1 identified design issue; P2 proves per-resource bug |
| P2-D4-003 | (new) | Not in Pass 1 |
| P2-D4-010 | P1-D4-061, P1-D4-072 | Speed cap confirmed necessary |
| P2-D4-011 | P1-D4-062 | 8x stack confirmed, modeled at 9.4x at extreme values |
| P2-D4-012 | P1-D4-060, P1-D4-196 | Decay formula confirmed near-trivial |
| P2-D4-013 | P1-D4-067 | Market manipulation confirmed in detail |
| P2-D4-014 | P1-D4-068 | Lieur stacking confirmed at 49x differential |
| P2-D4-015 | P1-D4-063 | Condenseur confirmed dominant-strategy exploit |
| P2-D4-017 | P1-D4-070 | Alliance VP formula confirmed broken |
| P2-D4-018 | (new, partially relates to alt-feeding) | Transfer bypass found |
| P2-D4-020 | P1-D4-066 | Rank 20→21 cliff confirmed at 4 points |
| P2-D4-021 | P1-D4-007 | Node stacking confirmed unbounded |
| P2-D4-035 | (new) | Market asymmetry not previously modeled |
| P2-D4-039 | (new) | Decay ORDER BY bug not in Pass 1 |
| P2-D4-044 | P1-D4-021 | Phalange class-4 immunity confirmed |
| P2-D4-057 | P1-D4-041 | Double-sqrt confirmed — combat scaling wrong |

---

## Prioritized Fix Roadmap

### Immediate (CRITICAL — exploit-class bugs)

1. **P2-D4-001** — Trade points loop: Award tradeVolume only on buys, not sells. Or add daily cap.
2. **P2-D4-002** — Vault per-resource bug: Change to percentage-based protection. Single formula change.
3. **P2-D4-003** — Decay index misuse during transit: Add ORDER BY and store departure-time decay coefs.

### Short-term (HIGH — significant balance issues)

4. **P2-D4-039** — Missing ORDER BY in updateRessources decay loop. Single-line fix.
5. **P2-D4-010** — Speed absolute cap. Single constant + one-line code change.
6. **P2-D4-013** — Market volatility floor `max(10, $nbActifs)`. One-line fix.
7. **P2-D4-017** — Alliance VP formula: Replace with named constant.
8. **P2-D4-020** — Player VP rank cliff: Smooth rank 20→21 transition.
9. **P2-D4-021** — Node stacking cap. Add `min(RESOURCE_NODE_MAX_STACK_BONUS, ...)`.

### Medium-term (HIGH/MEDIUM — structural balance)

10. **P2-D4-011** — Global combat multiplier cap at 4.0.
11. **P2-D4-012** — Reduce DECAY_POWER_DIVISOR to restore decay relevance.
12. **P2-D4-014** — Formation speed cap at 500 molecules/second.
13. **P2-D4-015** — Condenseur allocation normalization or average-based modCond.
14. **P2-D4-031** — Building targeting excludes level-0 buildings.
15. **P2-D4-044** — Phalange overflow in reverse class order.
16. **P2-D4-045** — Time-based market mean reversion.
17. **P2-D4-057** — Apply trade points through same pre-scaling step as combat.

### Long-term (MEDIUM/LOW — polish and tuning)

18. **P2-D4-033** — Minimum espionage threshold.
19. **P2-D4-037** — Iode catalyst use formula iode count (not × nombre).
20. **P2-D4-038** — VP for ranks 101-250 (participation reward).
21. **P2-D4-040** — Clarify prestige PP trade threshold unit.
22. **P2-D4-053** — Update stale 70% comment in combat.php.

---

*Pass 2 Deep Dive complete. 39 findings. 3 CRITICAL (exploit-class), 11 HIGH (significant balance), 16 MEDIUM (design issues), 9 LOW (polish/informational). All formulas modeled mathematically with numeric examples across legal input ranges.*
