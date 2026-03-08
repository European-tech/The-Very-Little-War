# Pass 6 Combat/Balance Audit

**Date:** 2026-03-08
**Scope:** includes/combat.php, includes/formulas.php, includes/player.php, includes/compounds.php, attaquer.php, includes/config.php
**Auditor:** Automated static analysis

---

## Findings

### CMB-P6-001 [LOW] `mt_rand` still used in building targeting — inconsistent with CSPRNG policy

**File:** includes/combat.php:543
**Description:** The per-unit building-damage targeting roll uses `mt_rand(1, $totalWeight)`. All other random rolls in combat (casualty fractional kills at lines 218, 244, 265, 305, 346) correctly use `random_int(0, 1000000)`. This inconsistency violates the established policy of using `random_int` for all combat randomness (the "lcg_value() → random_int()" fix previously applied). `mt_rand` produces predictable output from a known seed. While building targeting has less exploitable consequences than casualty calculation, an attacker who could predict the seed could concentrate fire on a specific building.
**Code:** `$roll = mt_rand(1, $totalWeight);`
**Fix:** Replace with `$roll = random_int(1, $totalWeight);`

---

### CMB-P6-002 [LOW] Dispersée post-loop overkill omits fractional kill probability

**File:** includes/combat.php:328
**Description:** When the Dispersée formation's main loop completes with residual `$disperseeOverkill > 0`, the post-loop block applies the overkill to the last surviving class (lines 321-334). The kill count uses `(int)floor($disperseeOverkill / $hpPerMol)` with no probabilistic rounding for the fractional remainder. In contrast, every kill calculation inside the main loop (lines 303-305, 263-265, etc.) applies a probability roll for fractional kills: `if ($rem > 0 && random_int(...) < $rem / $hpPerMol) $kills++`. This means that residual overkill targeting the last class systematically under-kills by up to 0.999 molecules. The effect is small but inconsistent.
**Code:**
```php
$killsFromOverkill = min($remaining, (int)floor($disperseeOverkill / $hpPerMol));
$defenseurMort[$ci] = ($defenseurMort[$ci] ?? 0) + $killsFromOverkill;
```
**Fix:** Add fractional kill probability to the post-loop, mirroring the main loop pattern:
```php
$rem = fmod($disperseeOverkill, $hpPerMol);
if ($rem > 0 && (random_int(0, 1000000) / 1000000.0) < $rem / $hpPerMol && $killsFromOverkill < $remaining) $killsFromOverkill++;
```

---

### CMB-P6-003 [LOW] `DUPLICATEUR_COMBAT_COEFFICIENT` constant is dead code

**File:** includes/config.php:263
**Description:** `define('DUPLICATEUR_COMBAT_COEFFICIENT', 1.0)` is defined but never referenced in any PHP runtime code. Combat.php uses `DUPLICATEUR_BONUS_PER_LEVEL` (0.01 per level) directly. The constant exists only in docs, tests, and comments. The in-code comment at config.php:261 says "used in combat.php" — this is inaccurate. A developer reading the code could mistakenly believe this constant controls a behavior it does not, leading to incorrect balance tuning. The docs at 09-BALANCE.md:628 describe the formula as `1 + (level × DUPLICATEUR_COMBAT_COEFFICIENT / 100)`, but combat.php actually computes `1 + (level × DUPLICATEUR_BONUS_PER_LEVEL)` = `1 + (level × 0.01)`, which equals `1 + (level × 1.0 / 100)`. The result is numerically identical to what the docs say, so no balance bug exists — but the constant is misleading dead code.
**Code:** `define('DUPLICATEUR_COMBAT_COEFFICIENT', 1.0);` (config.php:263), never used in PHP
**Fix:** Remove the constant definition and update the comment at config.php:261 to accurately describe the formula using `DUPLICATEUR_BONUS_PER_LEVEL`.

---

### CMB-P6-004 [MEDIUM] Defender's `defense_boost` compound is snapshotted by the attacker — defender cannot activate compound after attack is launched

**File:** attaquer.php:206
**Description:** When an attack is launched, the attacker snapshots the defender's active `defense_boost` compound at that moment:
```php
$defBoostSnapshot = getCompoundBonus($base, $_POST['joueurAAttaquer'], 'defense_boost');
```
This value is stored in `actionsattaques.compound_def_bonus` and used by combat.php at resolution time. Because the snapshot is taken at launch (not at resolution), if the defender activates a `defense_boost` compound (NaCl) *after* the attack is launched but *before* it resolves (during travel time), that boost is NOT applied in combat. Conversely, if the defender had an active NaCl when the attack was launched but it expires before resolution, the snapshot value still applies (defender benefits from an expired compound). This creates an asymmetry: the attacker's attack boost (`compound_atk_bonus`) is also snapshotted at launch, but the attacker controls when to launch. The defender cannot control when they are attacked.

**Impact:** A skilled defender who activates NaCl immediately upon seeing an incoming attack in their UI gets no benefit from it if the attack was already launched. This makes the defense compound less useful than intended for reactive play. The effect is asymmetric: the attacker has full control over when to snapshot their own bonus; the defender has no control over when the snapshot of their bonus is taken.

**Code:** attaquer.php:206 `$defBoostSnapshot = getCompoundBonus($base, $_POST['joueurAAttaquer'], 'defense_boost');`

**Fix (design choice):** Two options:
1. **Accept as designed (current):** Document this explicitly in the game rules — "defense compounds must be active before an attack is launched to be effective."
2. **Re-snapshot at resolution:** In combat.php, query the defender's current active defense compound instead of using the snapshot. Replace the snapshot approach with `$compoundDefenseBonus = getCompoundBonus($base, $actions['defenseur'], 'defense_boost');`. This matches the spirit of "defense is reactive" but requires removing the compound_def_bonus column from actionsattaques or keeping it as audit log only.

Note: this was intentional per HIGH-024 (anti-retroactive-activation), but the asymmetry between attacker and defender snapshots merits explicit documentation for players.

---

### CMB-P6-005 [INFO] NaCl compound recipe uses soufre (sulfur) instead of sodium — chemistry mismatch

**File:** includes/config.php:769-775
**Description:** The compound `NaCl` ("Sel") is defined with recipe `['chlore' => 1, 'soufre' => 1]`. Real NaCl (table salt) is sodium chloride — it contains no sulfur. The game has no "sodium" atom, but this recipe creates a chemistry inconsistency: NaCl is named sodium chloride yet requires sulfur. In contrast, H2O (water), CO2, NH3, and H2SO4 all have chemically accurate recipes. The NaCl recipe should arguably use `chlore` with another appropriate atom (e.g., `carbone` as a stand-in for a metallic element, or just be renamed to a sulfur-chlorine compound like "HCl" or "SCl2"). This has no gameplay impact but may confuse chemistry-literate players.
**Code:** `'recipe' => ['chlore' => 1, 'soufre' => 1]`
**Fix:** Either rename the compound to something chemically consistent with its recipe (e.g., "SO₂Cl₂" = "Chlorure de Sulfonyle"), or change the recipe to not require soufre. This is low-priority cosmetic.

---

### CMB-P6-006 [MEDIUM] `Dispersée` overkill redistribution partially absorbs overkill into current class when prior classes ahead exist — non-intuitive redistribution logic

**File:** includes/combat.php:293-298
**Description:** The Dispersée overkill redistribution logic at lines 293-298 spreads accumulated overkill among ALL remaining classes (including the current one):
```php
if ($disperseeOverkill > 0 && $liveClassesAhead > 0) {
    $spreadDenominator = 1 + $liveClassesAhead;
    $classDamage += $disperseeOverkill / $spreadDenominator;
    $disperseeOverkill -= $disperseeOverkill / $spreadDenominator; // consumed portion
}
```
But `$classDamage` already starts at `$sharePerClass` (the class's own share). By adding `overkill/(1+ahead)` to `$classDamage`, this class receives **two damage sources in one pass**: its own equal share PLUS a portion of accumulated overkill. If this class survives (kills < nombre), the overkill is then zeroed at line 312. This means overkill is partially "consumed" by a surviving class even when it cannot kill any additional molecules — the overkill portion is lost, not cascaded to subsequent classes. Specifically, if `$classDamage = $sharePerClass + $overkill/(1+ahead)` but the class absorbs it without dying extra molecules, `$disperseeOverkill` is reduced by `$overkill/(1+ahead)` and the remainder carries forward — but the "current class consumed" portion that didn't kill anything is gone.

**Example:** Class 1: 10 molecules, 200 HP each (2000 total HP). $sharePerClass = 1500. Class 2: 5 molecules, 100 HP each. Class 3: 5 molecules, 100 HP each.
- i=1: liveClassesAhead=2. overkill=0. classDamage=1500. kills=min(10,7)=7. 3 survive. overkill→0 (class not wiped).
- i=2: liveClassesAhead=1. overkill=0. classDamage=1500. kills=5. All dead. overkill += (1500-500)=1000.
- i=3: liveClassesAhead=0. overkill=1000. Condition false. classDamage=1500. kills=5. All dead. overkill += 1000. disperseeOverkill=2000.
- Post-loop: scan finds no survivors. Overkill lost (but all dead, no issue here).

The redistribution logic is complex but appears to work correctly in common cases. However, the comment "Spread accumulated overkill across remaining classes **including this one**" is misleading when the class is not wiped — the overkill share given to a surviving class is effectively wasted if it doesn't add kills.

**Impact:** LOW — overkill is only partially misapplied in edge cases where a class receives an overkill share but survives. The post-loop handles the most important case (applying overkill to last survivor). No outcome difference for completely-wiped armies.
**Fix (recommended):** Change the condition to only apply overkill when `$liveClassesAhead == 0` AND add it directly to the current class's damage (eliminating the partial-redistribution approach). Simpler and more predictable: accumulate all overkill and distribute it linearly via the post-loop. However, this is a design change that requires careful testing to not regress the existing fix.

---

### CMB-P6-007 [INFO] Building targeting `mt_rand` uses unsigned `int` array keys — `array_filter` on building levels preserves 0-level guard correctly

**File:** includes/combat.php:526-533
**Description:** The `$buildingTargets` array is filtered with `fn($v) => $v > 0` to exclude level-0 buildings. This correctly prevents targeting unbuilt buildings. The fallback `if (empty($buildingTargets)) $buildingTargets = ['generateur' => 1]` is sound — a new player always has generateur at level 1. No issue, but noting for documentation completeness.
**Code:** `array_filter([...], fn($v) => $v > 0)` + fallback
**Fix:** No action needed.

---

### CMB-P6-008 [MEDIUM] Defense compound boost applied via attacker-controlled snapshot — defender who de-activates a defense compound (impossible via UI) retains protection

**File:** attaquer.php:206, combat.php:182-184
**Description:** Since `compound_def_bonus` is snapshotted at attack launch and cannot be changed, if the defender's NaCl compound expires naturally DURING the attack's travel time, the snapshot still shows the old bonus value. Combat.php line 184:
```php
if ($compoundDefenseBonus > 0) $degatsDefenseur *= (1 + $compoundDefenseBonus);
```
The defender receives the defense bonus even if their compound expired mid-flight. This is a mirror of CMB-P6-004: it benefits the defender in the compound-expires scenario. Combined with CMB-P6-004's attacker-launch-time snapshot, the net effect is that the defense compound's coverage window is determined by its state at attack launch, not at resolution.
**Impact:** Balanced in that both attacker and defender compounds are snapshotted at launch. Slight defender advantage in the compound-expires-mid-flight scenario.
**Fix:** Same as CMB-P6-004 — document the snapshot behavior or re-snapshot at resolution.

---

## Summary

**Total: 8 findings**

| ID | Severity | File | Short Title |
|----|----------|------|-------------|
| CMB-P6-001 | LOW | combat.php:543 | `mt_rand` in building targeting |
| CMB-P6-002 | LOW | combat.php:328 | Dispersée post-loop overkill missing fractional kill probability |
| CMB-P6-003 | LOW | config.php:263 | `DUPLICATEUR_COMBAT_COEFFICIENT` dead constant |
| CMB-P6-004 | MEDIUM | attaquer.php:206 | Defender defense_boost snapshot taken by attacker at launch |
| CMB-P6-005 | INFO | config.php:769 | NaCl compound recipe chemistry mismatch |
| CMB-P6-006 | MEDIUM | combat.php:293-298 | Dispersée partial overkill redistribution into surviving classes |
| CMB-P6-007 | INFO | combat.php:526-533 | Building targeting 0-level guard documented (no action) |
| CMB-P6-008 | MEDIUM | attaquer.php:206 | Expired defense compound still applied if active at launch |

**Count by severity:** CRITICAL: 0 / HIGH: 0 / MEDIUM: 3 / LOW: 3 / INFO: 2

### Pass 6 Verdict

No CRITICAL or HIGH findings. The combat system is fundamentally sound. The three MEDIUM findings (CMB-P6-004, CMB-P6-006, CMB-P6-008) relate to compound snapshot semantics and a non-intuitive but functionally working Dispersée overkill redistribution. The two LOW findings (CMB-P6-001, CMB-P6-002) are minor inconsistencies that should be fixed for code quality.

**Recommended action order:**
1. CMB-P6-001 — one-line fix (`random_int`)
2. CMB-P6-002 — add fractional kill rounding to post-loop
3. CMB-P6-003 — remove dead constant
4. CMB-P6-004/CMB-P6-008 — decide on design intent and document in regles.php
5. CMB-P6-006 — document or simplify overkill logic
