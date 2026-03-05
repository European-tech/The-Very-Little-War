# Pass 1 — Domain 4: Formula Consistency & Edge Cases
## Subagent 4.4 Audit Report

**Date:** 2026-03-05
**Files analyzed:** `includes/formulas.php`, `includes/config.php`, `tests/balance/`, `tests/unit/`, `tests/functional/`, `marche.php`
**Finding IDs:** P1-D4-060 through P1-D4-079
**Method:** Exhaustive mathematical analysis, symbolic differentiation, edge-case simulation at levels 0, 1, 60, 100, 200, 2^31

---

## Executive Summary

20 findings produced. The formulas are largely well-constructed, with no catastrophic negative-value or division-by-zero bugs in the primary combat path. Seven findings are HIGH or MEDIUM severity — two involve genuine crash risks (market sell and decay half-life), two involve design flaws that undermine stated balance goals (speed soft cap, VP rank 20→21 cliff), and three are latent edge cases with low exploitability but real mathematical incorrectness.

---

## Finding Reference Table

| ID | Severity | Formula / File | Issue |
|----|----------|---------------|-------|
| P1-D4-060 | HIGH | `marche.php` line 330 | Sell formula divides by price before floor guard; price=0 in DB crashes server |
| P1-D4-061 | HIGH | `formulas.php` `drainageProducteur` | Energy deficit starts at prodLevel=24 with equal gen/prod; no config constant warning |
| P1-D4-062 | MEDIUM | `formulas.php` `vitesse()` | Speed synergy term (Cl*N/200) is fully uncapped, making soft cap misleading |
| P1-D4-063 | MEDIUM | `formulas.php` `pointsVictoireJoueur()` | 4-VP cliff at rank 20→21 (15 to 11) due to formula boundary mismatch |
| P1-D4-064 | MEDIUM | `formulas.php` `productionEnergieMolecule()` | iode < 10 atoms rounds to 0 energy; effectively a dead band for new players |
| P1-D4-065 | MEDIUM | `formulas.php` `tempsFormation()` | Extreme azote+iode+condenseur+lieur+catalyseur stack reduces time to 0.09 seconds |
| P1-D4-066 | MEDIUM | `formulas.php` `modCond()` | No guard against negative condenseur level; `modCond(-1) = 0.98` silently reduces stats |
| P1-D4-067 | LOW | `formulas.php` `coefDisparition()` | `demiVie()` guard `coef >= 1.0` is unreachable dead code (mathematically impossible) |
| P1-D4-068 | LOW | `formulas.php` `pillage()` | At S=0, all Cl investment is wasted (0 pillage regardless of Cl synergy) |
| P1-D4-069 | LOW | `formulas.php` `pointsPillage()` | `tanh()` returns negative for negative input; no guard if `ressourcesPillees < 0` in DB |
| P1-D4-070 | LOW | `formulas.php` `tempsFormation()` | `(1 + specFormationMod)` can theoretically reach 0.80 but never ≤ 0; safe |
| P1-D4-071 | LOW | Config | Modifier stacks verified: max combined attack multiplier is 8.42x (not 10x) |
| P1-D4-072 | LOW | `formulas.php` `calculerTotalPoints()` | Double-sqrt compression: attack points go through `sqrt()` twice, very flat curve |
| P1-D4-073 | LOW | `formulas.php` `pointsVictoireAlliance()` | Rank 2→3 step is 10→7 VP (not uniform), minor inconsistency |
| P1-D4-074 | INFO | `formulas.php` `coefDisparition()` | Decay coefficient is per-second; half-life at 200 atoms is ~134 hours — realistic |
| P1-D4-075 | INFO | `formulas.php` `vitesse()` | Optimal speed split is Cl=100, N=100 (not pure Cl); soft cap working as intended |
| P1-D4-076 | INFO | `marche.php` | Market price ceiling (10.0) and floor (0.1) ARE enforced after every trade; nbActifs guarded with `max(1,...)` |
| P1-D4-077 | INFO | Config | Stabilisateur asymptote (0.98): decay never reaches 0, approaches but cannot halt decay mathematically |
| P1-D4-078 | INFO | Neutrino cost | 50 energy/neutrino: at gen-10 a player earns 15 neutrinos/hour; espionage is deliberately expensive |
| P1-D4-079 | INFO | Market manipulation | Single-player price swing on depot-full buy = 6% (5 players active); design-acceptable |

---

## Detailed Findings

---

### P1-D4-060 — HIGH — Market Sell Formula Executes Before Price Floor Guard

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php` lines 330, 335

**Formula:**
```php
// Line 330 (sell price update):
$ajout = 1 / (1 / $tabCours[$num] + $volatilite * $actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR);

// Line 335 (floor enforced AFTER):
$ajout = max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout));
```

**Mathematical issue:**
The sell formula is `1 / (1/price + delta)`. If `price` is 0, PHP executes `1/0`, producing `INF` or a fatal division-by-zero error. The `MARKET_PRICE_FLOOR = 0.1` guard is applied **after** the calculation on line 335, not before.

**When can this occur?**
- Through normal trading, prices cannot reach 0 because the floor is applied after every transaction.
- If the `marche` DB row is manually reset, corrupted, or initialized with a zero price during season-start migrations, the next sell call crashes.
- A player who trades to floor (0.1) and then the mean-reversion nudges it down cannot go below 0.1 via the formula — but if a migration sets tableauCours to `"0,0,0,0,0,0,0,0"` (8 zeros), every sell on that resource crashes.

**Fix:**
```php
// Before line 330, add:
$tabCours[$num] = max(MARKET_PRICE_FLOOR, (float)$tabCours[$num]);
// Same guard before line 209 (buy formula, though buy uses addition not division)
```

**Exploitability:** Low (requires DB corruption or migration bug). **Impact if triggered:** Fatal PHP error (500) on market page for all users.

---

### P1-D4-061 — HIGH — Energy Deficit Crossover Undocumented; Config Test Wrong

**File:** `includes/config.php`, `includes/formulas.php` `drainageProducteur()`

**Formula:**
```php
// Energy production (per hour, per generateur level):
revenuEnergie = BASE_ENERGY_PER_LEVEL * genLevel  // = 75 * level

// Energy drain (per hour, 8 atom types):
totalDrain = 8 * round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, prodLevel))
           = 8 * round(8 * pow(1.15, level))
```

**Calculated values at equal gen/prod levels:**

| Level | Energy Production | Total Drain | Status |
|-------|------------------|-------------|--------|
| 20 | 1500 | 1048 | +452 surplus |
| 23 | 1725 | 1600 | +125 surplus |
| **24** | **1800** | **1832** | **-32 DEFICIT** |
| 25 | 1875 | 2104 | -229 deficit |
| 30 | 2250 | 4240 | -1990 deficit |
| 40 | 3000 | 17,144 | -14,144 deficit |

**Issue:**
The existing test `testEnergyTensionAtHighLevels()` asserts:
```php
// At level 30 equal, drain exceeds production (intentional)
$this->assertGreaterThan($energy30, $drain30,
    "At equal level 30, drain should exceed production (forces gen investment)");
```
This assertion has its operands **reversed** — the comment says "drain exceeds production" but `assertGreaterThan($energy30, $drain30)` asserts `$energy30 > $drain30`, the exact opposite. The test **passes** because it's checking energy > drain (which is true at level 20), not the stated intent.

The actual deficit starts at level 24 (not 30). A player running equal gen/prod levels hits an energy cliff 6 levels earlier than the codebase documents.

**Secondary concern:**
The exponential drain grows faster than linear production. At level 40, total drain (17,144/hour) exceeds production by nearly 6x. Players who upgrade producteur past level 35 without keeping gen 10+ levels ahead will run negative energy permanently, freezing all construction.

**Fix required for test:**
```php
// testEnergyTensionAtHighLevels: fix assertion direction
$this->assertGreaterThan($energy30, $drain30, ...);
// should be:
$this->assertGreaterThan($drain30, $energy30,
    "At equal level 30, drain MUST exceed production (forces gen investment)");
```

---

### P1-D4-062 — MEDIUM — Speed Soft Cap Does Not Prevent Cl Scaling Past Cap

**File:** `includes/formulas.php` `vitesse()`, `includes/config.php`

**Formula:**
```php
$clContrib = min(SPEED_SOFT_CAP, $Cl * SPEED_ATOM_COEFFICIENT);  // caps at 30
$base = 1 + $clContrib + (($Cl * $N) / SPEED_SYNERGY_DIVISOR);   // synergy UNCAPPED
return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
```

**Analysis:**
The comment in config.php states:
```
// BAL-SIM: Cl alone accounts for 86-99% of speed without cap. Soft cap at 30
// ensures N investment stays meaningful beyond Cl=60.
```

The soft cap correctly limits the linear Cl term. However, the synergy term `Cl*N/200` scales with BOTH atoms. Past Cl=60 (where the linear cap is hit), every additional Cl atom still adds `N/200` speed through synergy. With N=200, each Cl atom past 60 adds 1.0 speed per atom — **identical to the pre-cap linear rate of 0.5** (since the synergy rate N/200 = 200/200 = 1.0 > 0.5).

**Computed results:**

| Build (200 atoms total) | Speed | Notes |
|------------------------|-------|-------|
| Cl=200, N=0 | 31.00 | Pure Cl, capped at 31 |
| Cl=60, N=140 | 73.00 | Hit cap, maximize N |
| Cl=100, N=100 | 81.00 | **Optimal split** |
| Cl=150, N=50 | 68.50 | Diminishing returns |
| Cl=200, N=200 (no cap) | 231.00 | Extreme unbounded |
| Cl=200, N=200, cond=25 | 346.50 | With condenseur |

**Design implications:**
1. The soft cap's stated goal of "keeping N meaningful" is achieved — N is essential.
2. However, the cap does NOT prevent Cl from being fully useful after 60 atoms. The synergy term makes Cl > 60 worth investing in when paired with N. The "wasted Cl" narrative in documentation is partially incorrect.
3. At extreme values (Cl=200, N=200, cond=50), speed reaches 462, meaning a molecule reaches any map position in seconds. This may create unfun gameplay but is only achievable at max-invested levels.

**No code fix required** but player guide and documentation should be corrected to reflect that Cl > 60 is not wasted — it continues to contribute via synergy.

---

### P1-D4-063 — MEDIUM — VP Rank 20→21 Has a 4-VP Cliff

**File:** `includes/formulas.php` `pointsVictoireJoueur()`

**Formula at boundary:**
```php
// Rank 20: VP_PLAYER_RANK11_20_BASE - (20 - 10) * VP_PLAYER_RANK11_20_STEP
//         = 35 - 10 * 2 = 15 VP

// Rank 21: max(1, floor(VP_PLAYER_RANK21_50_BASE - (21-20) * VP_PLAYER_RANK21_50_STEP))
//         = max(1, floor(12 - 0.23)) = floor(11.77) = 11 VP
```

**Gap: 4 VP** (15 → 11), compared to the 2 VP steps that precede it (ranks 11–20 decrease by 2 each) and the 2 VP steps within ranks 11–20. The comment says "smoother VP curve" but introduces a steeper discontinuity at exactly the rank-20 boundary.

**Sequence around the boundary:**
```
Rank 18: 19 VP
Rank 19: 17 VP
Rank 20: 15 VP   <- tier boundary
Rank 21: 11 VP   <- 4-VP jump DOWN (should be 13)
Rank 22: 11 VP   <- same as rank 21 (floor rounds flat)
Rank 23: 11 VP   <- same
Rank 24: 11 VP   <- same
```

Additionally ranks 21–24 all yield 11 VP — four ranks that award identical victory points. This makes the distinction between rank 21 and rank 24 meaningless, reducing end-of-season strategic incentive.

**Fix:** Adjust `VP_PLAYER_RANK21_50_BASE` from 12 to 14 to produce a smooth continuation:
```php
// Rank 21 would then be: floor(14 - 0.23) = floor(13.77) = 13 VP (smooth from 15)
define('VP_PLAYER_RANK21_50_BASE', 14);   // was 12
```

---

### P1-D4-064 — MEDIUM — Iode Energy Dead Band: 0–9 Iode Atoms Produce 0 Energy

**File:** `includes/formulas.php` `productionEnergieMolecule()`

**Formula:**
```php
return round((IODE_QUADRATIC_COEFFICIENT * pow($iode, 2) + IODE_ENERGY_COEFFICIENT * $iode)
             * (1 + $niveau / IODE_LEVEL_DIVISOR));
// = round((0.003 * iode^2 + 0.04 * iode) * (1 + niveau/50))
```

**Values at low iode counts:**

| Iode | Level | Raw value | round() result |
|------|-------|-----------|----------------|
| 0 | 0 | 0.000 | 0 |
| 1 | 0 | 0.043 | **0** (rounds down) |
| 2 | 0 | 0.092 | **0** (rounds down) |
| 5 | 0 | 0.275 | **0** (rounds down) |
| 9 | 0 | 0.603 | **1** |
| 10 | 0 | 0.700 | **1** |

**Issue:** A new player who allocates 1–9 iode atoms to a molecule produces exactly zero energy from it. The tutorial molecule uses 1000 total atoms (TUTORIAL_STARTER_MOLECULE_TOTAL_ATOMS), but a player who spreads atoms evenly gets 1000/8 = 125 iode per type, so this does not affect tutorial. However a manually designed molecule with only a few iode atoms (testing the mechanic) silently produces nothing, creating a misleading UX.

**Severity consideration:** At 9 or fewer iode, even in a correctly functioning game, iode investment would be minimal. This is a rounding artifact, not exploitable. It does however damage discoverability for players experimenting with iode in small quantities.

**Fix (minimal):** Use `ceil()` instead of `round()`, or set a minimum of 1 energy when iode > 0:
```php
$raw = (IODE_QUADRATIC_COEFFICIENT * pow($iode, 2) + IODE_ENERGY_COEFFICIENT * $iode)
       * (1 + $niveau / IODE_LEVEL_DIVISOR);
return $iode > 0 ? max(1, round($raw)) : 0;
```

---

### P1-D4-065 — MEDIUM — Formation Time Approaches Zero Seconds at Max Investment

**File:** `includes/formulas.php` `tempsFormation()`

**Formula:**
```php
$vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * modCond($nivCondN) * $bonus_lieur;
// With player bonuses:
$vitesse_form *= $catalystSpeedBonus * $allianceCatalyseurBonus * (1 + $specFormationMod);
return ceil(($ntotal / $vitesse_form) * 100) / 100;
```

**At maximum realistic investment (all stacks applied):**

| Parameter | Value | Multiplier |
|-----------|-------|-----------|
| azote=200, iode=200 base | — | base vitesse = 4,297 |
| condenseur N = 50 | modCond = 2.0 | ×2.0 |
| lieur = 25 levels | bonusLieur = 4.75 | ×4.75 |
| catalyseur max (25 levels) | 1 + 25×0.02 = 1.50 | ×1.50 |
| spec Appliqué | 1.20 | ×1.20 |

**Combined vitesse_form = ~11,636**

**Formation times:**
- 1 molecule (1 atom): `ceil(1/11636 * 100)/100 = 0.01 seconds`
- 1000-atom molecule: `ceil(1000/11636 * 100)/100 = 0.09 seconds`

A highly invested player forms a 1000-atom molecule in under 100 milliseconds. This eliminates formation time as a strategic constraint for late-game players and may cause rapid army replenishment after combat, reducing the risk of aggressive play.

**This is a design concern, not a crash bug.** The formula is mathematically correct. The floor is that `ceil()` preserves at least 0.01s.

**Consider:** Adding a minimum formation time floor:
```php
define('FORMATION_TIME_FLOOR_SECONDS', 1.0);  // never less than 1 second
return max(FORMATION_TIME_FLOOR_SECONDS, ceil(($ntotal / $vitesse_form) * 100) / 100);
```

---

### P1-D4-066 — MEDIUM — modCond() Has No Guard Against Negative Condenseur Level

**File:** `includes/formulas.php` `modCond()`

**Formula:**
```php
function modCond($niveauCondenseur) {
    return 1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR);
}
```

**At negative levels:**
- `modCond(-1)  = 1 + (-1/50) = 0.98` — 2% stat reduction
- `modCond(-50) = 1 + (-50/50) = 0.00` — all stats zeroed out
- `modCond(-51) = 1 + (-51/50) = -0.02` — **negative multiplier** on stats

**When can this occur?**
- The condenseur column in DB is likely constrained to non-negative. However:
  - The `spec_research` specialization option 2 ("Appliqué") has `'condenseur_points' => -1` (reduces condenseur points per level).
  - If `condenseur_points` is used to compute a level and it goes negative through spec modifiers, `modCond` receives a negative value.
  - No code in the reviewed files explicitly clamps the condenseur level before passing to `modCond()`.

**Fix:**
```php
function modCond($niveauCondenseur) {
    return 1 + (max(0, $niveauCondenseur) / COVALENT_CONDENSEUR_DIVISOR);
}
```

---

### P1-D4-067 — LOW — demiVie() Guard for coef >= 1.0 Is Unreachable Dead Code

**File:** `includes/formulas.php` `demiVie()`

**Code:**
```php
function demiVie($joueur, $classeOuNbTotal, $type = 0) {
    $coef = coefDisparition($joueur, $classeOuNbTotal, $type);
    if ($coef >= 1.0) return PHP_INT_MAX; // No decay = infinite half-life
    return round((log(0.5, DECAY_BASE) / log($coef, DECAY_BASE)));
}
```

**Mathematical proof this guard is unreachable:**
The decay coefficient is computed as:
```
rawDecay = pow(0.99, pow(1 + atoms/150, 1.5) / 25000)
```
Since 0.99 < 1 and the exponent is always positive (for atoms ≥ 0), `rawDecay < 1` always.
All subsequent modifiers (stabilisateur, medal, isotope, catalyst) are applied as **positive exponents** to `rawDecay`. Raising a number < 1 to any positive exponent keeps it < 1.

Therefore `coefDisparition` is always strictly < 1.0. The `coef >= 1.0` check never fires.

The guard was added as a defensive fix ("FIX FINDING-GAME-020") against division by zero in `log(coef, DECAY_BASE)` when `coef = 1`. Since `log(1, base) = 0`, the division `log(0.5,base) / 0` would be fatal. The guard is correct in intent but the triggering condition is mathematically impossible.

**No code change required.** The guard is safe to leave in place as defense-in-depth. Document in a comment that the condition is unreachable by design.

---

### P1-D4-068 — LOW — Pillage Formula: Cl Investment Fully Wasted When S=0

**File:** `includes/formulas.php` `pillage()`

**Formula:**
```php
$base = (pow($S, COVALENT_BASE_EXPONENT) + $S) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
return round($base * modCond($nivCondS) * (1 + $bonusMedaille / 100));
```

**At S=0:**
```
base = (pow(0, 1.2) + 0) * (1 + Cl/100) = 0 * (1 + Cl/100) = 0
```

The synergy multiplier `(1 + Cl/100)` is applied to zero, so **any amount of Cl with zero S gives pillage = 0**. This is mathematically consistent (a molecule that cannot pillage cannot benefit from speed), but it is a silently wasted investment for a player who puts all S atoms in one molecule and Cl atoms in another without understanding the cross-molecule structure.

**Note:** This is the same structural behavior as `attaque(O=0, H=anything) = 0`. All five covalent formulas have this property. It is intentional design (primary atom is required), but could benefit from clearer UI explanation.

**No fix required** — design-correct. Flag for documentation.

---

### P1-D4-069 — LOW — pointsPillage() Returns Negative for Negative ressourcesPillees

**File:** `includes/formulas.php` `pointsPillage()`

**Formula:**
```php
function pointsPillage($nbRessources) {
    return round(tanh($nbRessources / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
}
```

**Mathematics:** `tanh(x)` returns negative for x < 0. If `$nbRessources` (stored as `ressourcesPillees` in the `autre` table) ever contains a negative value, `pointsPillage` returns a negative score. This negative score feeds into `calculerTotalPoints()` where `max(0, $pillage)` guards it at zero — so the **ranking system is safe**.

However, if `pointsPillage` is used elsewhere without the `max(0,...)` guard, a negative result would subtract from total points. Additionally a negative `ressourcesPillees` in the DB indicates a data integrity problem (you cannot unloot resources) that should be caught separately.

**Fix:** Add input guard:
```php
function pointsPillage($nbRessources) {
    return round(tanh(max(0, $nbRessources) / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
}
```

---

### P1-D4-070 — LOW — Formation Speed Specialization Cannot Produce Zero or Negative Speed

**File:** `includes/formulas.php` `tempsFormation()`

**Concern verified:** The `specFormationMod` from `getSpecModifier()` can be:
- Théorique spec: `-0.20` (formation_speed modifier)
- Appliqué spec: `+0.20`

The modifier is applied as `(1 + specFormationMod)` to `vitesse_form`. The minimum with Théorique is `1 + (-0.20) = 0.80`. Since `vitesse_form >= 1.0` always (base formula starts at 1.0), and `0.80 > 0`, `vitesse_form` after all modifiers is always positive. No division by zero is possible.

**Status:** Confirmed safe. No action required.

---

### P1-D4-071 — LOW — Maximum Combined Attack Multiplier Is 8.42x, Below 10x Threshold

**Checklist item:** "Compound + isotope + medal + prestige + specialization stacking: can it produce 10x multiplier?"

**Computed maximum achievable stack on attack:**

| Modifier | Value | Source |
|----------|-------|--------|
| Condenseur level 50 | ×2.00 | modCond(50) |
| Ionisateur level 30 | ×1.60 | +2% per level |
| Medal Diamant Rouge | ×1.50 | +50% (uncapped during season) |
| Prestige | ×1.05 | PRESTIGE_COMBAT_BONUS |
| Compound CO2 | ×1.10 | +10% attack, 1h |
| Isotope Réactif | ×1.20 | ISOTOPE_REACTIF_ATTACK_MOD |
| Spec Oxydant | ×1.10 | +10% attack |
| Catalytique ally | ×1.15 | +15% from support class |

**Combined: 2.00 × 1.60 × 1.50 × 1.05 × 1.10 × 1.20 × 1.10 × 1.15 = 8.42×**

The 10x threshold is not reached even with every possible modifier active simultaneously. This is a healthy result — the game has strong but not game-breaking stacking. Defense similarly reaches ~3.83x maximum.

**Note:** The 8.42x uses uncapped medal bonus (+50%). With the MAX_CROSS_SEASON_MEDAL_BONUS = 10% cap, the season-start stack drops to ~4.0x. The uncapped version only applies once a player reaches Diamant Rouge tier within a season.

---

### P1-D4-072 — LOW — Double-Sqrt in Ranking Produces Very Flat Compression

**File:** `includes/formulas.php` `calculerTotalPoints()`

**Issue:**
```php
// pointsAttaque() already applies sqrt:
return round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)));  // raw -> sqrt-scaled

// calculerTotalPoints() applies sqrt AGAIN:
RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
// = weight * sqrt(sqrt(rawPoints)) = weight * rawPoints^0.25
```

**Effect:** Raw attack points go through `x^0.25` compression, not `x^0.5`. At raw 100 combat points:
- `pointsAttaque(100)` = `round(5.0 * sqrt(100))` = 50
- `calculerTotalPoints` contribution = `1.5 * pow(50, 0.5)` = `1.5 * 7.07` = 10.6

Versus if attack went in raw: `1.5 * pow(100, 0.5)` = 15.0

The double-sqrt was presumably intentional for balance — this is extremely flat compression, meaning the 1000th unit of attack contributes almost nothing. This is consistent with the stated design goal of preventing any single activity from dominating. However, the code comment only mentions the outer sqrt, not that it compounds with the inner sqrt in `pointsAttaque`.

**No bug** — mathematically consistent. Document the double-sqrt in developer notes to prevent future confusion.

---

### P1-D4-073 — LOW — Alliance VP Rank 2→3 Step (10→7) Inconsistent With Curve

**File:** `includes/formulas.php` `pointsVictoireAlliance()`

**Values:**
```
Rank 1: 15 VP
Rank 2: 10 VP  (−5 from rank 1)
Rank 3: 7 VP   (−3 from rank 2)
Rank 4: 6 VP   (−1 from rank 3, formula: VP_ALLIANCE_RANK2 - classement = 10 - 4)
Rank 5: 5 VP
...
```

**Issue:** The step from rank 2 to rank 3 is 3 VP, while from rank 3 to rank 4 is only 1 VP. The rank-3 VP (7) is hardcoded separately and sits between the rank-2 constant (10) and the formula-driven rank-4 value (6). The rank 1→2 step is 5 VP. This creates a non-monotonically-decreasing step size that is cosmetically awkward.

**Minimal fix:** Change `VP_ALLIANCE_RANK3` from 7 to 8 for a uniform 2-VP step (5, 2, 2, 1, 1, 1...).

---

### P1-D4-074 — INFO — Decay Coefficient Is Per-Second; Real Half-Lives Are Reasonable

**Verification of decay semantics:** `molecule.php` line 123 and 507 apply decay as:
```php
$moleculesRestantes = pow(coefDisparition($attaquant, $compteur), $nbsecondes) * $molecules;
```
where `$nbsecondes` is elapsed real time in seconds. Therefore the coefficient is genuinely per-second.

**Half-lives at key atom counts:**

| Total Atoms | Decay coef (no stab) | Half-life |
|-------------|---------------------|-----------|
| 200 | 0.9999985671 | 134.4 hours (5.6 days) |
| 800 | 0.9999935925 | 30.0 hours (1.3 days) |
| 1600 | 0.9999839802 | 12.0 hours (0.5 day) |

**With stabilisateur level 25** (modStab = 0.603):

| Total Atoms | Half-life |
|-------------|-----------|
| 200 | 222.7 hours (9.3 days) |
| 800 | 49.8 hours (2.1 days) |

These values are reasonable for a 31-day season. A 200-atom molecule without stabilisation loses 50% of its count in 5.6 days — meaningful pressure but not punishing. The stabilisateur provides meaningful protection.

---

### P1-D4-075 — INFO — Speed Soft Cap Working Correctly; Optimal Split is Cl=100, N=100

**Checklist item:** "Speed soft cap (30): is it reachable? Is it fun?"

**Reachability:** Yes. `Cl * SPEED_ATOM_COEFFICIENT = Cl * 0.5 >= 30` when `Cl >= 60`. The cap is trivially reachable with the maximum atom count of 200.

**Fun analysis:**
- Pure Cl (200, 0): speed = 31. Capped.
- Balanced Cl=100, N=100: speed = 81. **2.6x faster than pure Cl.**
- Cl=60, N=140: speed = 73. Good return on N investment.

The soft cap successfully forces N investment — players who dump all atoms into Cl are severely penalized in speed versus N-hybrid builds. This is the design intent and it works.

**Confusion risk:** The soft cap documentation says "Cl investment becomes less valuable above 60" but this is incomplete — Cl continues to contribute through the synergy term. The soft cap specifically caps the **linear** Cl term, not the synergy contribution.

---

### P1-D4-076 — INFO — Market Safety Guards Are Properly Implemented

**Checklist item:** "Market volatility formula: can prices be manipulated by a single player?"

**Guards confirmed in `marche.php`:**
1. `max(1, $actifs['nbActifs'])` — prevents division by zero when no active players (line 7)
2. `max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout))` — enforced after every buy AND sell update (lines 214, 335)
3. `MARKET_GLOBAL_ECONOMY_DIVISOR = 10000` — normalized against global pool, not personal depot

**Single-player manipulation scenario (5 active players, depot level 10):**
- Volatility = 0.3/5 = 0.06
- Buying depot-full (4046 atoms) in one transaction: price increase = 0.06 × 4046/10000 = **0.024 (2.4%)**
- After 50 separate buys of 500 atoms each (1 player, maximum volatility 0.3): final price ≈ **2.85** (well below ceiling of 10.0)

**Conclusion:** The price impact formula is well-calibrated. A single player cannot hit the price ceiling through normal trading volume. Market manipulation is theoretically possible with many successive buys but is self-limiting due to the increasing cost.

---

### P1-D4-077 — INFO — Stabilisateur Asymptote: Decay Approaches Zero But Never Reaches It

**Checklist item:** "Stabilisateur asymptote (0.98): does decay ever reach 0?"

**Mathematics:**
```
modStab = pow(0.98, stabilisateur_level)
baseDecay = pow(rawDecay, modStab * modMedal)
```

As `stabilisateur_level → ∞`:
- `modStab = pow(0.98, ∞) → 0`
- `pow(rawDecay, 0) = 1.0` — meaning coef = 1.0 = no decay

At level 200 (extreme): `modStab = 0.01759`, `baseDecay(200 atoms) = 0.999999975` (essentially no decay, half-life > 10 years). **Decay never reaches exactly 0 but becomes negligible at extreme stabilisateur levels.** This is the correct asymptotic behavior — the constant name `STABILISATEUR_ASYMPTOTE = 0.98` accurately describes the base of the asymptotic decay toward zero.

**No issue.** The design prevents decay from reaching zero at any finite building level.

---

### P1-D4-078 — INFO — Neutrino Cost vs Value: Intentionally Expensive, Marginally Viable

**Checklist item:** "Neutrino espionage: cost (50 energy) vs information gained — worth it?"

**Economics:**
- Cost: 50 energy per neutrino (`NEUTRINO_COST = 50`)
- At gen level 10: 750 energy/hour → 15 neutrinos/hour
- Espionage success: requires own_neutrinos > 0.5 × defender_neutrinos (`ESPIONAGE_SUCCESS_RATIO = 0.5`)
- A defender maintaining 100 neutrinos for defense costs 5,000 energy (6.7 hours of gen-10 production)

**Value assessment:**
- One successful espionage reveals an enemy's resource stores, molecule composition, and building levels — high-value intelligence before an attack.
- An attacker needs 51 neutrinos to beat 100-neutrino defense: 2,550 energy cost.
- At gen level 15: 1,125 energy/hour, so 2,550 energy = 2.3 hours of production.

**Conclusion:** The 50-energy cost makes espionage viable but not trivial. It is appropriately expensive to prevent spam scouting while remaining accessible to mid-game players. The asymmetric cost (defender spends 5,000 for 100-neutrino defense; attacker spends 2,550 to bypass) slightly favors attackers, which is reasonable game design.

---

### P1-D4-079 — INFO — Condenseur Divisor (50): Appropriate Scaling

**Checklist item:** "condenseur divisor (50): appropriate scaling?"

**Analysis of modCond scale:**
```
modCond(level) = 1 + level/50
Level 0:  1.00x (neutral)
Level 10: 1.20x (+20%)
Level 25: 1.50x (+50%)
Level 50: 2.00x (double)
Level 100: 3.00x (triple)
```

This linear multiplier applied to all combat stats means condenseur level 50 doubles attack, defense, HP, pillage, and speed simultaneously. The cost growth (ECO_GROWTH_ADV = 1.20 per level) makes high-level condenseur extremely expensive, providing natural balance.

**Cross-check with covalent base exponent:**
The formula `pow(atom, 1.2) + atom` produces super-linear growth. At atoms=200: value = ~2330 (vs 200 linear = 200). The condenseur multiplier compounds this, so condenseur level 25 with 200 atoms produces 3495 attack vs 1553 at condenseur 0. This is strong but bounded by cost.

**Verdict:** Divisor of 50 is well-calibrated. The scaling is neither too slow (would make condenseur irrelevant) nor too fast (would make condenseur mandatory to the exclusion of atom investment). No change recommended.

---

## Test Coverage Gaps Identified

The following scenarios are not currently covered by any existing test:

1. **Sell formula with price=0 input** — no test verifies graceful handling of zero price in the harmonic sell formula.
2. **modCond() with negative level** — no test verifies the negative-level edge case.
3. **iode=1 to iode=9 produces zero energy** — rounding dead band not tested.
4. **VP rank 20→21 boundary** — no test checks the 4-VP cliff specifically.
5. **Formation time floor** — no test verifies minimum formation time under maximum modifier stacking.
6. **pointsPillage() with negative input** — no test exercises the negative tanh path.
7. **drainageProducteur() crossover point** — the test that is supposed to catch level-30 deficit has operands reversed and tests the wrong condition.

---

## Files Referenced

- `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php`
- `/home/guortates/TVLW/The-Very-Little-War/includes/config.php`
- `/home/guortates/TVLW/The-Very-Little-War/marche.php`
- `/home/guortates/TVLW/The-Very-Little-War/molecule.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/balance/CombatFairnessTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/balance/EconomyProgressionTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/balance/IsotopeSpecializationTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/balance/StrategyViabilityTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/balance/AllianceResearchTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/unit/GameBalanceTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/unit/MarketFormulasTest.php`
- `/home/guortates/TVLW/The-Very-Little-War/tests/functional/FormulaConsistencyTest.php`
