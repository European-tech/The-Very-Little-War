# Pass 7 Resource Formula Audit

**Date:** 2026-03-08
**Scope:** `includes/formulas.php`, `includes/game_resources.php`, `includes/config.php`, `includes/prestige.php`, `includes/resource_nodes.php`, `marche.php`
**Already fixed (pre-audit):** `revenuAtome max(0,...)`, `tradeVolume TRADE_VOLUME_CAP`, atom production N+1 cache, market price loop guard.

---

## Findings

### FORM-P7-001 [MEDIUM] — Docs stale: vault percentage (2% vs 3%)

**File:** `includes/config.php:132`, `docs/game/09-BALANCE.md` §25
**Proof:**
- `config.php`: `define('VAULT_PCT_PER_LEVEL', 0.03);  // P1-D4-030: buffed from 2% to 3% per level`
- Docs section 25 still shows `VAULT_PCT_PER_LEVEL | 0.02 (2% per level)` and the formula quick reference at §32 shows `min(0.50, coffreLevel × 0.02)` — both stale.
- `regles.php` reads the constant dynamically, so it is correct. `formulas.php:335` uses the constant. Only the docs are wrong.
- At vault level 10, docs says 20% protection; reality is 30% — a 50% understatement of actual vault protection.

**Fix:** Update `docs/game/09-BALANCE.md` §25 and §32 quick reference:
- Change `VAULT_PCT_PER_LEVEL | 0.02 (2% per level)` → `VAULT_PCT_PER_LEVEL | 0.03 (3% per level)`
- Change `vaultProtection = round(placeDepot(depot) × min(0.50, coffreLevel × 0.02))` → `× min(0.50, coffreLevel × 0.03)`
- Change example: "Coffre level 5: `min(50%, 10%) × 4046` = 405" → "min(50%, 15%) × 4046 = 607"
- Change "V4: At level 25 (cap), it protects 50% ... level 17 (not 25) achieves cap": `ceil(0.50 / 0.03) = 17`

---

### FORM-P7-002 [LOW] — Docs incomplete: energy/atom production formulas omit 3 post-prestige multipliers

**File:** `docs/game/09-BALANCE.md` §4.1, §4.2, §32
**Proof:**

Energy production formula in docs (§4.1):
```
prodPrestige  = prodDuplicateur × prestigeProductionBonus
revenuEnergie = max(0, round(prodPrestige - drainageProducteur))
```

Actual code (`game_resources.php:63-74`):
```php
$prodPrestige    = $prodDuplicateur * prestigeProductionBonus($joueur);
$prodNodes       = $prodPrestige * (1 + $energyNodeBonus);        // MISSING from docs
$prodCompound    = $prodNodes * (1 + $compoundProdBonus);          // MISSING from docs
$prodSpec        = $prodCompound * (1 + $specEnergyMod);           // MISSING from docs
$prodProducteur  = $prodSpec - drainageProducteur($producteur['producteur']);
```

Atom production formula in docs (§4.2):
```
revenuAtome = round(bonusDuplicateur × BASE_ATOMS_PER_POINT × pointsProducteur[num] × prestigeProductionBonus)
```

Actual code (`game_resources.php:134`):
```php
$result = max(0, round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau
    * prestigeProductionBonus($joueur)
    * (1 + $nodeBonus)         // MISSING from docs
    * (1 + $compoundProdBonus) // MISSING from docs
    * (1 + $specAtomMod)       // MISSING from docs
));
```

The code is correct. The documentation simply does not document the resource node proximity bonus, compound synthesis production_boost, and specialization atom_production/energy_production modifiers that were added in later phases. Players reading the docs will see lower production numbers than they actually receive.

**Fix:** Update docs §4.1 and §4.2 to add the three additional multiplier stages after `prodPrestige`. Also update §32 quick reference. No code change needed.

---

### FORM-P7-003 [LOW] — Docs stale: formation Phalange absorb percentage (60% vs 50%)

**File:** `docs/game/09-BALANCE.md` §9
**Proof:**
- `config.php:295`: `define('FORMATION_PHALANX_ABSORB', 0.50);  // P1-D4-021: nerfed from 60% to 50%`
- Docs §9 table shows: `Class 1 absorbs 60% of damage` and `FORMATION_PHALANX_ABSORB | 0.60`
- Docs §8.4 bullet: "Class 1 absorbs 60% of damage"
- The code uses `FORMATION_PHALANX_ABSORB` (0.50) so behavior is correct; docs are stale.

**Fix:** Update `docs/game/09-BALANCE.md` §9 constant table: `FORMATION_PHALANX_ABSORB | 0.50` and prose to "Class 1 absorbs 50% of damage". Update §8.4 bullet to match.

---

### FORM-P7-004 [LOW] — Docs stale: Embuscade attack bonus (15% vs 40%)

**File:** `docs/game/09-BALANCE.md` §9
**Proof:**
- `config.php:298`: `define('FORMATION_AMBUSH_ATTACK_BONUS', 0.40);  // P1-D4-022: buffed from 25% to 40%`
- Docs §9 table shows: `Embuscade: +15% effective damage` and `FORMATION_AMBUSH_ATTACK_BONUS | 0.15`

**Fix:** Update docs §9 constant table: `FORMATION_AMBUSH_ATTACK_BONUS | 0.40` and description to "+40% attack bonus".

---

### FORM-P7-005 [LOW] — Docs stale: defense reward ratio (20% vs 30%)

**File:** `docs/game/09-BALANCE.md` §8.9
**Proof:**
- `config.php:281`: `define('DEFENSE_REWARD_RATIO', 0.30);  // P1-D4-027: buffed from 20% to 30%`
- Docs §8.9 constant table: `DEFENSE_REWARD_RATIO | 0.20 (20%)`

**Fix:** Update docs §8.9: `DEFENSE_REWARD_RATIO | 0.30 (30%)`.

---

### FORM-P7-006 [LOW] — Docs stale: medal thresholds for Attaque/Défense/Pillage

**File:** `docs/game/09-BALANCE.md` §20.2
**Proof:**
- `config.php:540-546` (actual values):
  - `MEDAL_THRESHOLDS_ATTAQUE = [50, 200, 500, 1500, 4000, 8000, 15000, 30000]`
  - `MEDAL_THRESHOLDS_DEFENSE = [50, 200, 500, 1500, 4000, 8000, 15000, 30000]`
  - `MEDAL_THRESHOLDS_PILLAGE = [500, 5000, 25000, 100000, 500000, 2000000, 5000000, 10000000]`
- Docs §20.2 table shows much larger values:
  - Attaque: `100 | 1K | 5K | 20K | 100K | 500K | 2M | 10M`
  - Défense: `100 | 1K | 5K | 20K | 100K | 500K | 2M | 10M`
  - Pillage: `1K | 10K | 50K | 200K | 1M | 5M | 20M | 100M`
- The docs show values roughly 2–10x higher than what is actually in config.php. Players reading docs will think medals are much harder to earn than they are.

**Fix:** Update docs §20.2 table with actual thresholds from config.php.

---

## Checklist: Nine Audited Points

| # | Question | Result |
|---|----------|--------|
| 1 | Atom production formula matches docs? | MISMATCH — docs omit 3 multipliers (FORM-P7-002) |
| 2 | Energy production formula correct? | Code correct; docs incomplete (FORM-P7-002) |
| 3 | Market price slippage direction correct? | CLEAN — buy raises price, sell lowers price |
| 4 | Molecule decay half-life computation correct? | CLEAN — `log(0.5)/log(coef)` is mathematically correct |
| 5 | Total points formula all components weighted? | CLEAN — 5 weighted components, TRADE_VOLUME_CAP applied |
| 6 | Prestige production bonus applied correctly? | CLEAN — multiplicative at correct stage in pipeline |
| 7 | Resource node proximity distance calculation? | CLEAN — Euclidean `sqrt(dx²+dy²)`, capped at RESOURCE_NODE_MAX_BONUS_PCT |
| 8 | Vault protection percentage correct? | Code correct (3%); docs stale (say 2%) — FORM-P7-001 |
| 9 | Any formula producing NaN or Infinity? | CLEAN — `demiVie` guards coef≥1.0; `vitesse` clamped at 1.0; `max(0,...)` throughout |

---

## Detailed Formula Verification (Code vs Docs)

### Market Price Slippage — CLEAN

Buy path (`marche.php:283`):
```php
$ajout = $txTabCours[$num] + $volatilite * $_POST['nombreRessourceAAcheter'] / MARKET_GLOBAL_ECONOMY_DIVISOR;
```
Effect: price INCREASES on buy. Correct.

Sell path (`marche.php:433`):
```php
$ajout = 1 / (1 / $currentPrice + $volatilite * $actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR);
```
Effect: harmonic formula, price DECREASES on sell (adding to 1/p increases 1/p, decreasing p). Correct.

Both use the same `$volatilite = MARKET_VOLATILITY_FACTOR / max(1, nbActifs)`, preventing volatile swings with many active players.

### Decay Formula — CLEAN

Code (`formulas.php:256-259`):
```php
$rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
$modStab  = pow(STABILISATEUR_ASYMPTOTE, $stabilisateur['stabilisateur']);
$modMedal = 1 - ($bonus / 100);
$baseDecay = pow($rawDecay, $modStab * $modMedal);
```

Matches docs §6.1 exactly. The asymptotic stabilisateur `pow(0.98, level)` → level 0 = 1.0 (no effect), level 50 = 0.364 (significant reduction). Never reaches 0, so decay can never be fully suppressed.

Isotope modifiers applied as exponents on `$baseDecay` (lines 263-277):
- Volatilité catalyst: `pow(baseDecay, 1.30)` — faster decay (coef further from 1). Correct.
- Stable: `pow(baseDecay, 0.70)` — slower decay (coef closer to 1). Correct.
- Réactif: `pow(baseDecay, 1.20)` — faster decay. Correct.

### Half-Life — CLEAN

Code (`formulas.php:289`):
```php
return round((log(0.5, DECAY_BASE) / log($coef, DECAY_BASE)));
```

`log(x, b) = log(x)/log(b)`. The `log(b)` denominators cancel: `log(0.5)/log(0.99) / (log(coef)/log(0.99))` = `log(0.5)/log(coef)`. This is the correct half-life formula in seconds. Guard at line 288 prevents division by zero when coef ≥ 1.0.

### Vault Formula — Code correct, docs stale

Code (`formulas.php:335-336`):
```php
$pct = min(VAULT_MAX_PROTECTION_PCT, $nivCoffre * VAULT_PCT_PER_LEVEL);  // 0.03 per level, cap 0.50
return round(placeDepot($nivDepot) * $pct);
```

- Level 17 achieves `17 × 0.03 = 0.51 → capped at 0.50` (50%). Cap is at level 17, not 25 as docs imply.
- `regles.php` computes `ceil(VAULT_MAX_PROTECTION_PCT / VAULT_PCT_PER_LEVEL)` = 17 dynamically — correct.
- Docs §25 says cap at level 25 (assuming 2% per level). This is wrong; cap is at level 17.

---

## Summary

**6 findings total — all documentation discrepancies, ZERO code bugs.**

| ID | Severity | Category | Description |
|----|----------|----------|-------------|
| FORM-P7-001 | MEDIUM | Docs | Vault % stale: docs say 2%/level, code uses 3%/level (cap at lvl 17 not 25) |
| FORM-P7-002 | LOW | Docs | Energy/atom production docs omit 3 multipliers: nodes, compound, specialization |
| FORM-P7-003 | LOW | Docs | Phalange absorb stale: docs say 60%, code uses 50% |
| FORM-P7-004 | LOW | Docs | Embuscade bonus stale: docs say 15%, code uses 40% |
| FORM-P7-005 | LOW | Docs | Defense reward ratio stale: docs say 20%, code uses 30% |
| FORM-P7-006 | LOW | Docs | Medal thresholds for Attaque/Défense/Pillage stale (2–10x too high in docs) |

**All 9 audited formula systems are correct in code.** The production chain, market slippage, decay half-life, total points weights, prestige multiplier, proximity bonus, and vault formula all compute accurately with no NaN/Infinity risk. The only issues are stale documentation in `docs/game/09-BALANCE.md` that accumulated during balance passes that updated `config.php` but did not update the docs.

**Recommended action:** Single docs-update commit to sync `docs/game/09-BALANCE.md` with current `config.php` values. No production code changes required.
