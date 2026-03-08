# Pass 7 Combat Formula Audit
**Date:** 2026-03-08
**Scope:** includes/combat.php + includes/formulas.php + includes/prestige.php
**Auditor:** Autonomous agent (Claude Sonnet 4.6)

Pre-cleared as already fixed: Dispersée overkill post-loop, cross-role spec modifiers, catalyst defender, lcg_value→random_int, building targeting, mt_rand→random_int, fractional kill in post-loop.

---

## Findings

### CMB2-P7-001 [MEDIUM] — Prestige combat bonus applies only to damage output, not to molecule HP

**File:** includes/combat.php:173–174
**Function:** `prestigeCombatBonus()` in includes/prestige.php:243

**Proof:**

```php
// combat.php lines 173–174
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
$degatsDefenseur *= prestigeCombatBonus($actions['defenseur']);
```

`prestigeCombatBonus()` returns `PRESTIGE_COMBAT_BONUS = 1.05` when the `maitre_chimiste` unlock is active.

The bonus multiplies each player's *damage output* only. In the casualty loops that follow (lines 213–227 for attacker, lines 230–358 for defender), `pointsDeVieMolecule()` is called **without** any prestige multiplier:

```php
$hpPerMol = pointsDeVieMolecule($classeAttaquant[$i]['brome'], $classeAttaquant[$i]['carbone'], $niveauxAtt['brome'])
            * $bonusDuplicateurAttaque * $attIsotopeHpMod[$i];
// No * prestigeCombatBonus(...) here
```

The unlock description in `$PRESTIGE_UNLOCKS` is `"+5% aux stats de combat"`. "Stats de combat" in the game's context encompasses both offensive (attack/damage) and defensive (HP) dimensions. A player who paid 500 PP for `maitre_chimiste` gets a full 5% bonus to how hard their molecules hit, but zero HP improvement against incoming fire. Their molecules die just as fast as an un-unlocked player's.

**Impact:** Minor power asymmetry — prestige is purely an offensive multiplier despite being described as a general combat stat boost. In a mirror match between a `maitre_chimiste` player and a non-prestige opponent, the prestige player deals 5% more damage but their own molecules absorb 0% more damage. This creates a hidden stat discrepancy that players cannot observe from the UI description.

**Fix:** In both casualty loops, apply the defending side's prestige HP multiplier to `$hpPerMol`. Example for the attacker casualty loop (attacker molecules absorbing defender damage):

```php
$prestigeHpMod = prestigeCombatBonus($actions['attaquant']); // attacker's own HP bonus
$hpPerMol = pointsDeVieMolecule(...) * $bonusDuplicateurAttaque * $attIsotopeHpMod[$i] * $prestigeHpMod;
```

Similarly in the defender casualty loops:
```php
$prestigeHpMod = prestigeCombatBonus($actions['defenseur']);
$hpPerMol = pointsDeVieMolecule(...) * $bonusDuplicateurDefense * $defIsotopeHpMod[$i] * $prestigeHpMod;
```

---

### CMB2-P7-002 [LOW] — Embuscade formation condition ignores dead molecules from attacker's own selection

**File:** includes/combat.php:151–159

**Proof:**

```php
if ($defenderFormation == FORMATION_EMBUSCADE) {
    $totalAttackerMols = 0;
    $totalDefenderMols = 0;
    for ($c = 1; $c <= $nbClasses; $c++) {
        $totalAttackerMols += $classeAttaquant[$c]['nombre'];   // attacker's sent count
        $totalDefenderMols += $classeDefenseur[$c]['nombre'];   // defender's full army
    }
    if ($totalDefenderMols > $totalAttackerMols) {
        $embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS;
    }
}
```

`$classeDefenseur[$c]['nombre']` is the defender's total molecule count as of the battle's start (fetched with `FOR UPDATE` at lines 7–15). `$classeAttaquant[$c]['nombre']` is the attacker's *sent* contingent (`$chaineExplosee`, line 23), not the attacker's total army.

This is the correct semantic: Embuscade should compare the defender's full home army against what the attacker sent — not the attacker's full army. The config comment confirms: "if defender has more total molecules [than the attacker sent]".

However, there is a minor ambiguity: the defender's count includes molecules in transit (on espionage missions, etc.) because the query fetches all molecules for that player regardless of location. In practice this is unlikely to create a meaningful exploit but it is worth noting.

**Impact:** INFO — no actual logic error; semantics are consistent with config description. Documented for clarity.

**Fix:** None required. If strict locality is ever desired, add a `WHERE en_deplacement=0` condition to the defender molecule query.

---

### CMB2-P7-003 [LOW] — HP formula condenseur level uses brome condenseur for all molecule HP, ignoring covalent cross-synergy intent

**File:** includes/formulas.php:164–168 (called from combat.php:213, 236, 260, 300, 325, 345)

**Proof:**

```php
function pointsDeVieMolecule($Br, $C, $nivCondBr)
{
    $base = MOLECULE_MIN_HP + (pow($Br, COVALENT_BASE_EXPONENT) + $Br) * (1 + $C / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondBr));
}
```

The condenseur modifier `modCond($nivCondBr)` amplifies the molecule's HP based on the brome condenseur level (`$niveauxAtt['brome']` or `$niveauxDef['brome']`). The Carbon atom provides the *synergy* bonus inside the formula `(1 + $C / COVALENT_SYNERGY_DIVISOR)` but Carbon's condenseur level (`nivCondC`) is not applied to HP.

By contrast, `attaque()` scales via `modCond($nivCondO)` (oxygen condenseur), `defense()` via `modCond($nivCondC)` (carbon condenseur), and `pillage()` via `modCond($nivCondS)` (sulphur condenseur).

The pattern is intentional and consistent: the *primary* atom's condenseur level scales the formula. For HP, Brome is primary. This matches game design documentation. Carbon's condenseur is correctly used only in the defense formula where Carbon is the primary attack atom.

**Impact:** INFO — no bug; formula is internally consistent.

**Fix:** None required.

---

## Checklist Results

| Check | Result |
|-------|--------|
| 1. HP calculation per molecule class | CORRECT — `pointsDeVieMolecule(Br, C, nivCondBr) * duplicateur * isotopeHpMod` |
| 2. Phalange absorb percentage | CORRECT — `FORMATION_PHALANX_ABSORB = 0.50` applied as `* 0.50` split; class 1 gets `* (1 + FORMATION_PHALANX_DEFENSE_BONUS)` = ×1.20 HP |
| 3. Embuscade attack bonus direction | CORRECT — applied to `$degatsDefenseur` (defender's damage output), not attacker's |
| 4. Isotope Stable HP bonus | CORRECT — `ISOTOPE_STABLE_HP_MOD = 0.40` adds to mod starting at 1.0, giving ×1.40 HP |
| 5. Alliance duplicateur bonus formula | CORRECT — `1 + (level * 0.01)` per level; 1% per level matching config |
| 6. Prestige combat bonus | PARTIAL BUG — applied only to damage output, not HP (CMB2-P7-001) |
| 7. Veteran bonus | N/A — 'veteran' prestige unlock grants +1 protection day only, no combat multiplier; no code needed |
| 8. Damage dealt vs HP destroyed consistency | CONSISTENT — overkill cascade ensures remaining damage after kills does not contribute to casualty count |
| 9. Division by zero risks | SAFE — all `$hpPerMol > 0` guards present; `$activeDefClasses > 0` guards `$sharePerClass`; pillage guarded by `$ressourcesTotalesDefenseur != 0` |
| 10. Off-by-one in class iteration | CORRECT — all loops use `for ($c = 1; $c <= $nbClasses; $c++)`, arrays 1-indexed, consistent throughout |

---

## Summary

**1 actionable finding (MEDIUM), 1 INFO finding, 1 INFO clarification.**

**CMB2-P7-001 (MEDIUM):** The `maitre_chimiste` prestige unlock (`+5% combat stats`) is wired to multiply damage output only. Molecule HP in the casualty loops receives no prestige multiplier. Fix requires applying `prestigeCombatBonus($login)` to `$hpPerMol` in each casualty loop — three locations for attackers (main loop + Phalange class-1 + Phalange overflow) and three for defenders (Phalange class-1, Phalange classes 2–4, Dispersée, Embuscade/default).

All other formula checks pass:
- HP formula (Brome primary, Carbon synergy, brome condenseur level) is correct.
- Phalange 50% absorb + 20% defense bonus is correctly applied.
- Embuscade +40% is correctly applied to defender damage output when defender outnumbers attacker.
- Isotope Stable +40% HP is correctly accumulated via `$isotopeHpMod`.
- Duplicateur 1%/level combat bonus is correctly applied.
- No division-by-zero risks in any damage or HP path.
- Class iteration is consistently 1-indexed, no off-by-one.
