# Ultra Audit Pass 8: Combat Formula Correctness & Edge Cases

**Date:** 2026-03-08  
**Domain:** Combat formula verification + edge case handling  
**Auditor:** Claude Haiku (Pass 8)  
**Status:** CLEAN — All 10 critical audit points verified PASSED

---

## Executive Summary

Comprehensive audit of `includes/combat.php` across all 10 specified domains:

| # | Domain | Status | Notes |
|---|--------|--------|-------|
| 1 | Phalange formation split math | PASS | 50% absorb, cap at HP, overkill cascade correct |
| 2 | Embuscade condition check | PASS | Outnumber condition correctly implemented |
| 3 | Dispersée overkill redistribution | PASS | Fractional kill probability in place (CMB-P6-002) |
| 4 | Building targeting algorithm | PASS | Uses `random_int()` with weighted distribution (CMB-P6-001) |
| 5 | Stable isotope modifier | PASS | -5% attack, +40% HP, signs correct |
| 6 | Combat result variable names | PASS | No swaps, damage assigned correctly |
| 7 | Season war query | PASS | `SELECT debut FROM statistiques` used correctly |
| 8 | Condenseur redistribution | PASS | No negative values possible via max(0) guards |
| 9 | Molecule destruction limits | PASS | Both attacker/defender limited by own counts |
| 10 | Alliance war pertes tracking | PASS | Correct side credited in declarations table |

---

## Findings: ZERO

### ✓ PASS 1: Phalange Formation Split Math

**Verification:** Lines 230–273

Phalange formation correctly implements the 50% damage absorption with defense bonus:

```php
// Line 232–233: Split total damage
$phalanxDamage = $degatsAttaquant * FORMATION_PHALANX_ABSORB;  // 0.50 = 50%
$otherDamage = $degatsAttaquant - $phalanxDamage;              // remaining 50%

// Line 236–238: Class 1 HP multiplier includes defense bonus
$hpPerMol1 = pointsDeVieMolecule(...) 
             * $bonusDuplicateurDefense 
             * $defIsotopeHpMod[1]
             * (1.0 + FORMATION_PHALANX_DEFENSE_BONUS);  // +20%

// Line 241–246: Fractional kill probability + overflow tracking
$kills1 = min($classeDefenseur[1]['nombre'], (int)floor($phalanxDamage / $hpPerMol1));
$remainder1 = fmod($phalanxDamage, $hpPerMol1);
if ($remainder1 > 0 && (random_int(...) < $remainder1 / $hpPerMol1) && ...) $kills1++;
$phalanxOverflow = max(0.0, $phalanxDamage - $kills1 * $hpPerMol1);
```

**No off-by-one errors:** `min()` prevents excessive kills, `fmod()` captures fractional remainder, `random_int()` probabilistically adds final kill.

**Overkill cascade:** Line 256 correctly combines remaining 50% damage + phalanx overflow:
```php
$remainingDamage = $otherDamage + $phalanxOverflow;
```

✓ **PASS**

---

### ✓ PASS 2: Embuscade Condition

**Verification:** Lines 149–160

Embuscade bonus (+40% attack) is awarded **only when defender outnumbers attacker**:

```php
$totalAttackerMols = 0;
$totalDefenderMols = 0;
for ($c = 1; $c <= $nbClasses; $c++) {
    $totalAttackerMols += $classeAttaquant[$c]['nombre'];
    $totalDefenderMols += $classeDefenseur[$c]['nombre'];
}
if ($totalDefenderMols > $totalAttackerMols) {  // MUST be >
    $embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS;  // 1.40
}
```

**Correct condition logic:** Uses strict `>` (not `>=`), meaning:
- Attacker 100 vs Defender 100 = NO bonus (equal)
- Attacker 100 vs Defender 101 = YES bonus (defender wins)

Applied at line 201:
```php
$degatsDefenseur *= $embuscadeDefBoost;  // Multiplies counter-damage
```

✓ **PASS**

---

### ✓ PASS 3: Dispersée Overkill Redistribution

**Verification:** Lines 274–338

Dispersée formation splits damage equally across active classes with overkill cascade. **CMB-P6-002 fix verified:**

Fractional kill probability implemented at lines 305, 330:

```php
$kills = min($classeDefenseur[$i]['nombre'], (int)floor($classDamage / $hpPerMol));
$rem = fmod($classDamage, $hpPerMol);
if ($rem > 0 && (random_int(0, 1000000) / 1000000.0) < $rem / $hpPerMol && $kills < $classeDefenseur[$i]['nombre']) $kills++;
```

This allows a fractional remainder to probabilistically award +1 kill, e.g.:
- Damage: 500, HP: 150 → kills = 3, remainder = 50 → P(extra kill) = 50/150 = 33%

**Overkill redistribution logic (line 286–298):**
```php
// Line 289–290: Recount only LIVE classes ahead (not already dead in this round)
for ($j = $i + 1; $j <= $nbClasses; $j++) {
    if (($classeDefenseur[$j]['nombre'] - ($defenseurMort[$j] ?? 0)) > 0) $liveClassesAhead++;
}

// Line 296: Include current class in spread denominator
$spreadDenominator = 1 + $liveClassesAhead;
$classDamage += $disperseeOverkill / $spreadDenominator;
$disperseeOverkill -= $disperseeOverkill / $spreadDenominator;  // Consumed portion
```

Also handles final overkill (line 321–337): if remaining overkill after all classes, apply to last surviving class.

✓ **PASS**

---

### ✓ PASS 4: Building Targeting — Weighted random_int()

**Verification:** Lines 530–562

**CMB-P6-001 fix verified:** Uses `random_int()` with weighted cumulative distribution, not `mt_rand()`:

```php
// Line 530–536: Filter buildings at level >= 1 (no level-0 targets)
$buildingTargets = array_filter([
    'generateur' => (int)$constructions['generateur'],
    'champdeforce' => (int)$constructions['champdeforce'],
    'producteur' => (int)$constructions['producteur'],
    'depot' => (int)$constructions['depot'],
    'ionisateur' => (int)$constructions['ionisateur'],
], fn($v) => $v > 0);

// Line 538: Total weight = sum of levels (higher = more attractive)
$totalWeight = array_sum($buildingTargets);

// Line 542–562: Per-unit targeting with proper weighted roll
for ($u = 0; $u < $surviving; $u++) {
    $roll = random_int(1, $totalWeight);  // ← uses random_int, not mt_rand
    $cumul = 0;
    foreach ($buildingTargets as $building => $weight) {
        $cumul += $weight;
        if ($roll <= $cumul) {  // ← accumulate weights for hits
            switch ($building) { ... }
            break;
        }
    }
}
```

**Correctness:** Each unit rolls independently, allowing damage distribution across multiple buildings. Fallback at line 537 prevents infinite loop if all buildings are level 0.

✓ **PASS**

---

### ✓ PASS 5: Stable Isotope Modifier Signs & Magnitude

**Verification:** Lines 89–121, config.php lines 313–314

Constants verified:
```php
// config.php
define('ISOTOPE_STABLE_ATTACK_MOD', -0.05);   // -5% attack
define('ISOTOPE_STABLE_HP_MOD', 0.40);        // +40% HP
```

Applied in combat.php (lines 98–100):
```php
if ($attIso == ISOTOPE_STABLE) {
    $attIsotopeAttackMod[$c] += ISOTOPE_STABLE_ATTACK_MOD;  // 1.0 + (-0.05) = 0.95
    $attIsotopeHpMod[$c] += ISOTOPE_STABLE_HP_MOD;         // 1.0 + 0.40 = 1.40
}
```

**Signs are CORRECT:**
- Negative modifier (`-0.05`) reduces attack ✓
- Positive modifier (`+0.40`) increases HP ✓

Applied at line 167 (attack) and 214 (HP):
```php
$degatsAttaquant += attaque(...) * $attIsotopeAttackMod[$c] * ... * $classeAttaquant[$c]['nombre'];
$hpPerMol = pointsDeVieMolecule(...) * $attIsotopeHpMod[$i];
```

✓ **PASS**

---

### ✓ PASS 6: Combat Result Variable Assignment

**Verification:** Lines 164–175, 209–227

Damage calculation assigns correctly:

```php
// Line 164–165: Initialize
$degatsAttaquant = 0;  // Damage FROM attacker TO defender
$degatsDefenseur = 0;  // Damage FROM defender TO attacker

// Line 167: Add attacker's damage
$degatsAttaquant += attaque(...) * ... * $classeAttaquant[$c]['nombre'];

// Line 169: Add defender's damage  
$degatsDefenseur += defense(...) * ... * $classeDefenseur[$c]['nombre'];

// Line 173–174: Apply combat bonuses
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
$degatsDefenseur *= prestigeCombatBonus($actions['defenseur']);
```

**Casualties calculated correctly:**

```php
// Line 209–227: Attacker takes defender's damage
$remainingDamage = $degatsDefenseur;
for ($i = 1; $i <= $nbClasses; $i++) {
    if (...) {
        $hpPerMol = pointsDeVieMolecule(...);
        $kills = min($classeAttaquant[$i]['nombre'], (int)floor($remainingDamage / $hpPerMol));
        ...
        $attaquantMort[$i] = $kills;  // ← Attacker's losses
        $remainingDamage -= ...;
    }
}

// Line 257–273: Defender takes attacker's damage (Phalange example)
for ($i = 2; $i <= $nbClasses; $i++) {
    ...
    $hpPerMol = pointsDeVieMolecule(...);
    ...
    $defenseurMort[$i] = $kills;  // ← Defender's losses
    ...
}
```

**No variable swaps:** Variables named logically throughout, damage flows correctly from attacker → defender → attacker's casualties, and defender → attacker → defender's casualties.

✓ **PASS**

---

### ✓ PASS 7: Season War Query

**Verification:** Line 693

Verify that combat points use correct table for season start:

```php
// Line 693
$jeuData = dbFetchOne($base, 'SELECT debut FROM statistiques LIMIT 1', '');
$seasonStart = $jeuData ? (int)$jeuData['debut'] : time();
```

**Correct:** Queries `statistiques` table (not `jeu` table) for season `debut` (start timestamp). Used to calculate `seasonDay` for catchup weekend multiplier.

✓ **PASS**

---

### ✓ PASS 8: Condenseur Redistribution — No Negatives

**Verification:** Lines 740–775

Attacker resource gain capped at storage (line 745):
```php
$setParams[] = min($maxStorageAtt, ($ressourcesJoueur[$ressource] + ($ressourcePille[$ressource] ?? 0)));
```

Defender resource loss clamped at 0 (line 762):
```php
$setParams[] = max(0, ($ressourcesDefenseur[$ressource] - ($ressourcePille[$ressource] ?? 0)));
```

**Also verified:** Pillageable calculation prevents negative subtractions:
```php
// Line 443
$ressourcesTotalesDefenseur += max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);

// Line 477
$pillageable = max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
```

✓ **PASS** — No negative values possible.

---

### ✓ PASS 9: Molecule Destruction Limits

**Verification:** Lines 7–26 (parsing), 209–227 (attacker), 230–338 (defender)

Attacker molecules destroyed cannot exceed attacker's own supply:

```php
// Lines 209–227
for ($i = 1; $i <= $nbClasses; $i++) {
    if ($classeAttaquant[$i]['nombre'] > 0 && $remainingDamage > 0) {
        ...
        $kills = min($classeAttaquant[$i]['nombre'], ...);  // ← CAPPED at available
        ...
        $attaquantMort[$i] = $kills;
        ...
    }
}
```

Defender molecules destroyed cannot exceed defender's own supply (same pattern at lines 230–338):

```php
// Line 242
$kills1 = min($classeDefenseur[1]['nombre'], (int)floor($phalanxDamage / $hpPerMol1));

// Line 263
$kills = min($classeDefenseur[$i]['nombre'], (int)floor($remainingDamage / $hpPerMol));
```

Final update at line 418 correctly subtracts from defender's molecules:
```php
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', 
    ($classeDefenseur[$di]['nombre'] - $defenseurMort[$di]), 
    $classeDefenseur[$di]['id']);
```

✓ **PASS**

---

### ✓ PASS 10: Alliance War Pertes Tracking

**Verification:** Lines 780–797

Correct side credited based on alliance membership:

```php
// Lines 781–785: Get alliance IDs
$joueurAlliance = ($joueur && isset($joueur['idalliance'])) ? $joueur['idalliance'] : 0;
$autreAlliance = ($idallianceAutre && isset($idallianceAutre['idalliance'])) ? $idallianceAutre['idalliance'] : 0;

// Lines 787–788: Fetch war declaration
$guerres = dbFetchAll($base, 'SELECT * FROM declarations WHERE type=0 AND fin=0 AND 
    ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?))', 
    'iiii', $joueurAlliance, $autreAlliance, $joueurAlliance, $autreAlliance);
$guerre = !empty($guerres) ? $guerres[0] : null;

// Lines 792–796: Update correct sides based on alliance1 membership
if ($guerre['alliance1'] == $joueurAlliance) {
    // Attacker's alliance is alliance1
    // pertes1 += attacker losses
    // pertes2 += defender losses
    dbExecute(..., 'UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? ...',
        'ddi', $pertesAttaquant, $pertesDefenseur, $guerre['id']);
} else {
    // Attacker's alliance is alliance2
    // pertes1 += defender losses (because alliance1 ≠ attacker)
    // pertes2 += attacker losses
    dbExecute(..., 'UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? ...',
        'ddi', $pertesDefenseur, $pertesAttaquant, $guerre['id']);
}
```

**Correctness verified:** 
- If attacker's alliance is `alliance1`, attacker losses → `pertes1`, defender losses → `pertes2` ✓
- If attacker's alliance is `alliance2`, defender losses → `pertes1`, attacker losses → `pertes2` ✓

✓ **PASS**

---

## Summary

**All 10 audit domains PASSED.** Combat formula implementation is sound:

✓ Formation damage splits use correct formulas and overkill cascades  
✓ Embuscade bonus applies only on outnumber condition  
✓ Dispersée fractional kills with proper probability  
✓ Building targeting uses weighted `random_int()` distribution  
✓ Isotope modifiers applied with correct signs and magnitudes  
✓ Combat damage flows correctly without variable swaps  
✓ Season queries use correct table and columns  
✓ Resource redistribution guarded against negatives  
✓ Molecule destruction bounded by available units  
✓ Alliance war losses credited to correct sides  

**Status:** ZERO findings. Combat formula passes comprehensive correctness audit.
