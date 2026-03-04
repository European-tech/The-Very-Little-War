# Balance Tuning & Appendix Remediation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Simulate game playthrough data to inform balance tuning (Tasks 7.1-7.3), then implement remaining appendix fixes.

**Architecture:** A standalone PHP balance simulator (`tools/balance_simulator.php`) that uses the real formulas from config.php/formulas.php to explore the parameter space across player archetypes. No database needed — pure math. Results drive constant adjustments for pillage, speed, and formations. After balance tuning, implement remaining appendix schema/UX fixes.

**Tech Stack:** PHP 8.2, PHPUnit 9.6, config.php constants, formulas.php functions

---

## Part 1: Balance Simulation Tool

### Task 1.1: Create simulation infrastructure

**Files:**
- Create: `tools/balance_simulator.php`

This script includes config.php constants and re-implements formula functions as pure math (no DB). It defines 5 player archetypes representing different playstyles.

**Step 1: Create tools directory and bootstrap file**

```php
<?php
/**
 * TVLW Balance Simulator
 * Explores game formula parameter space to identify balance issues.
 * Run: php tools/balance_simulator.php
 */

// Load constants without DB
define('TVLW_SIMULATION', true);
require_once(__DIR__ . '/../includes/config.php');

// Re-implement pure formulas (same as formulas.php, no DB dependency)
function sim_attaque($O, $H, $nivCondO, $bonusMedaille = 0) {
    $base = (pow($O, COVALENT_BASE_EXPONENT) + $O) * (1 + $H / COVALENT_SYNERGY_DIVISOR);
    return round($base * (1 + $nivCondO / COVALENT_CONDENSEUR_DIVISOR) * (1 + $bonusMedaille / 100));
}
function sim_defense($C, $Br, $nivCondC, $bonusMedaille = 0) {
    $base = (pow($C, COVALENT_BASE_EXPONENT) + $C) * (1 + $Br / COVALENT_SYNERGY_DIVISOR);
    return round($base * (1 + $nivCondC / COVALENT_CONDENSEUR_DIVISOR) * (1 + $bonusMedaille / 100));
}
function sim_pillage($S, $Cl, $nivCondS, $bonusMedaille = 0) {
    $base = (pow($S, COVALENT_BASE_EXPONENT) + $S) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
    return round($base * (1 + $nivCondS / COVALENT_CONDENSEUR_DIVISOR) * (1 + $bonusMedaille / 100));
}
function sim_vitesse($Cl, $N, $nivCondCl) {
    $base = 1 + ($Cl * 0.5) + (($Cl * $N) / 200);
    return max(1.0, floor($base * (1 + $nivCondCl / COVALENT_CONDENSEUR_DIVISOR) * 100) / 100);
}
function sim_hp($Br, $C, $nivCondBr) {
    $base = MOLECULE_MIN_HP + (pow($Br, COVALENT_BASE_EXPONENT) + $Br) * (1 + $C / COVALENT_SYNERGY_DIVISOR);
    return round($base * (1 + $nivCondBr / COVALENT_CONDENSEUR_DIVISOR));
}
function sim_destruction($H, $O, $nivCondH) {
    $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $O / COVALENT_SYNERGY_DIVISOR);
    return round($base * (1 + $nivCondH / COVALENT_CONDENSEUR_DIVISOR));
}
function sim_tempsFormation($ntotal, $azote, $iode, $nivCondN, $nivLieur) {
    $bonus_lieur = 1 + $nivLieur * LIEUR_LINEAR_BONUS_PER_LEVEL;
    $vitesse = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * (1 + $nivCondN / COVALENT_CONDENSEUR_DIVISOR) * $bonus_lieur;
    return ceil(($ntotal / $vitesse) * 100) / 100;
}
function sim_coefDisparition($nbAtomes, $stabLevel, $medalBonus = 0) {
    $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
    $modStab = pow(STABILISATEUR_ASYMPTOTE, $stabLevel);
    $modMedal = 1 - ($medalBonus / 100);
    return pow($rawDecay, $modStab * $modMedal);
}
function sim_demiVie($nbAtomes, $stabLevel, $medalBonus = 0) {
    $coef = sim_coefDisparition($nbAtomes, $stabLevel, $medalBonus);
    if ($coef >= 1.0) return PHP_INT_MAX;
    return round(log(0.5, DECAY_BASE) / log($coef, DECAY_BASE));
}
function sim_placeDepot($niveau) {
    return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau));
}
function sim_drainageProducteur($niveau) {
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau));
}
function sim_revenuEnergie($genLevel) {
    return BASE_ENERGY_PER_LEVEL * $genLevel;
}
function sim_revenuAtome($prodLevel) {
    return BASE_ATOMS_PER_POINT * $prodLevel;
}
function sim_calculerTotalPoints($construction, $attaque, $defense, $commerce, $pillage) {
    return round(
        RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $construction), RANKING_SQRT_EXPONENT)
        + RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
        + RANKING_DEFENSE_WEIGHT * pow(max(0, $defense), RANKING_SQRT_EXPONENT)
        + RANKING_TRADE_WEIGHT * pow(max(0, $commerce), RANKING_SQRT_EXPONENT)
        + RANKING_PILLAGE_WEIGHT * pow(max(0, $pillage), RANKING_SQRT_EXPONENT)
    );
}
function sim_pointsAttaque($pts) { return $pts <= 0 ? 0 : round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts))); }
function sim_pointsDefense($pts) { return $pts <= 0 ? 0 : round(DEFENSE_POINTS_MULTIPLIER * sqrt(abs($pts))); }
function sim_pointsPillage($r) { return round(tanh($r / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER); }
```

**Step 2: Define player archetypes**

Each archetype has: atom distribution (per molecule), building priority, play pattern.

```php
// Atom distributions for a ~200 total atom molecule (one class)
$ARCHETYPES = [
    'raider' => [
        'desc' => 'Aggressive attacker, high O/H',
        'atoms' => ['carbone'=>10, 'azote'=>15, 'hydrogene'=>40, 'oxygene'=>50, 'chlore'=>25, 'soufre'=>25, 'brome'=>15, 'iode'=>20],
        'buildings' => ['generateur'=>25, 'producteur'=>20, 'depot'=>15, 'ionisateur'=>15, 'champdeforce'=>5, 'condenseur'=>10, 'lieur'=>8, 'stabilisateur'=>5, 'coffrefort'=>3],
        'attacks_per_day' => 4,
    ],
    'turtle' => [
        'desc' => 'Defensive player, high C/Br',
        'atoms' => ['carbone'=>50, 'azote'=>20, 'hydrogene'=>10, 'oxygene'=>10, 'chlore'=>15, 'soufre'=>10, 'brome'=>45, 'iode'=>40],
        'buildings' => ['generateur'=>25, 'producteur'=>20, 'depot'=>18, 'champdeforce'=>18, 'ionisateur'=>3, 'condenseur'=>10, 'lieur'=>5, 'stabilisateur'=>8, 'coffrefort'=>8],
        'attacks_per_day' => 0,
    ],
    'pillager' => [
        'desc' => 'Resource raider, high S/Cl',
        'atoms' => ['carbone'=>10, 'azote'=>15, 'hydrogene'=>15, 'oxygene'=>25, 'chlore'=>40, 'soufre'=>55, 'brome'=>10, 'iode'=>30],
        'buildings' => ['generateur'=>25, 'producteur'=>20, 'depot'=>15, 'ionisateur'=>12, 'champdeforce'=>5, 'condenseur'=>10, 'lieur'=>8, 'stabilisateur'=>5, 'coffrefort'=>3],
        'attacks_per_day' => 3,
    ],
    'speedster' => [
        'desc' => 'Speed-focused, high Cl/N',
        'atoms' => ['carbone'=>15, 'azote'=>45, 'hydrogene'=>20, 'oxygene'=>20, 'chlore'=>55, 'soufre'=>15, 'brome'=>10, 'iode'=>20],
        'buildings' => ['generateur'=>25, 'producteur'=>20, 'depot'=>15, 'ionisateur'=>10, 'champdeforce'=>5, 'condenseur'=>12, 'lieur'=>10, 'stabilisateur'=>5, 'coffrefort'=>3],
        'attacks_per_day' => 2,
    ],
    'balanced' => [
        'desc' => 'Well-rounded player',
        'atoms' => ['carbone'=>25, 'azote'=>25, 'hydrogene'=>25, 'oxygene'=>25, 'chlore'=>25, 'soufre'=>25, 'brome'=>25, 'iode'=>25],
        'buildings' => ['generateur'=>22, 'producteur'=>18, 'depot'=>15, 'ionisateur'=>10, 'champdeforce'=>10, 'condenseur'=>12, 'lieur'=>8, 'stabilisateur'=>5, 'coffrefort'=>5],
        'attacks_per_day' => 1,
    ],
];
```

**Step 3: Run test to verify simulator loads**

Run: `php tools/balance_simulator.php`
Expected: No errors, outputs "Simulator loaded" (add a print at end)

**Step 4: Commit**

```bash
git add tools/balance_simulator.php
git commit -m "feat: add balance simulation infrastructure with formulas and archetypes"
```

---

### Task 1.2: Implement formula analysis section

**Files:**
- Modify: `tools/balance_simulator.php`

Add analysis functions that systematically explore how each stat scales with atom counts.

**Step 1: Add formula exploration**

```php
// === ANALYSIS 1: Atom Efficiency Tables ===
function analyzeAtomEfficiency() {
    echo "\n=== ATOM EFFICIENCY ANALYSIS ===\n";
    echo "Shows stat output per atom invested at different levels.\n";
    echo "Condenseur level: 10 (typical mid-game)\n\n";

    $condLevel = 10;
    $atomLevels = [10, 25, 50, 75, 100, 150, 200];
    $secondaryLevels = [0, 25, 50, 100];

    // Attack (O primary, H secondary)
    echo "--- ATTACK: attaque(O, H, condO=10) ---\n";
    echo str_pad("O\\H", 8);
    foreach ($secondaryLevels as $h) echo str_pad("H=$h", 12);
    echo str_pad("per-O", 12) . "\n";
    foreach ($atomLevels as $o) {
        echo str_pad("$o", 8);
        $prevVal = 0;
        foreach ($secondaryLevels as $h) {
            $val = sim_attaque($o, $h, $condLevel);
            echo str_pad(number_format($val), 12);
        }
        // Marginal return: attack per O atom (at H=25)
        if ($o > 10) {
            $marginal = (sim_attaque($o, 25, $condLevel) - sim_attaque($o-10, 25, $condLevel)) / 10;
            echo str_pad(number_format($marginal, 1), 12);
        }
        echo "\n";
    }

    // Defense (C primary, Br secondary)
    echo "\n--- DEFENSE: defense(C, Br, condC=10) ---\n";
    echo str_pad("C\\Br", 8);
    foreach ($secondaryLevels as $br) echo str_pad("Br=$br", 12);
    echo "\n";
    foreach ($atomLevels as $c) {
        echo str_pad("$c", 8);
        foreach ($secondaryLevels as $br) {
            echo str_pad(number_format(sim_defense($c, $br, $condLevel)), 12);
        }
        echo "\n";
    }

    // Pillage (S primary, Cl secondary)
    echo "\n--- PILLAGE: pillage(S, Cl, condS=10) ---\n";
    echo str_pad("S\\Cl", 8);
    foreach ($secondaryLevels as $cl) echo str_pad("Cl=$cl", 12);
    echo "\n";
    foreach ($atomLevels as $s) {
        echo str_pad("$s", 8);
        foreach ($secondaryLevels as $cl) {
            echo str_pad(number_format(sim_pillage($s, $cl, $condLevel)), 12);
        }
        echo "\n";
    }

    // Speed (Cl primary, N secondary)
    echo "\n--- SPEED: vitesse(Cl, N, condCl=10) ---\n";
    echo str_pad("Cl\\N", 8);
    foreach ($secondaryLevels as $n) echo str_pad("N=$n", 12);
    echo "\n";
    foreach ($atomLevels as $cl) {
        echo str_pad("$cl", 8);
        foreach ($secondaryLevels as $n) {
            echo str_pad(number_format(sim_vitesse($cl, $n, $condLevel), 2), 12);
        }
        echo "\n";
    }

    // HP (Br primary, C secondary)
    echo "\n--- MOLECULE HP: pointsDeVieMolecule(Br, C, condBr=10) ---\n";
    echo str_pad("Br\\C", 8);
    foreach ($secondaryLevels as $c) echo str_pad("C=$c", 12);
    echo "\n";
    foreach ($atomLevels as $br) {
        echo str_pad("$br", 8);
        foreach ($secondaryLevels as $c) {
            echo str_pad(number_format(sim_hp($br, $c, $condLevel)), 12);
        }
        echo "\n";
    }
}
```

**Step 2: Add speed dominance analysis (Task 7.2 specific)**

```php
function analyzeSpeedDominance() {
    echo "\n=== SPEED DOMINANCE ANALYSIS (H-009) ===\n";
    echo "Question: Is Cl too dominant in speed formula?\n";
    echo "Current: vitesse = 1 + Cl*0.5 + Cl*N/200\n\n";

    // Compare Cl contribution vs N contribution
    $condLevel = 10;
    echo "--- Cl contribution breakdown at N=25, condCl=10 ---\n";
    echo str_pad("Cl", 8) . str_pad("Base(1)", 10) . str_pad("Cl*0.5", 10) . str_pad("Cl*N/200", 10) . str_pad("Total", 10) . str_pad("Cl%", 10) . "\n";
    foreach ([10, 25, 50, 75, 100, 150, 200] as $cl) {
        $n = 25;
        $basePart = 1;
        $clPart = $cl * 0.5;
        $synPart = ($cl * $n) / 200;
        $total = $basePart + $clPart + $synPart;
        $clPct = ($clPart / $total) * 100;
        echo str_pad($cl, 8) . str_pad(number_format($basePart, 1), 10) . str_pad(number_format($clPart, 1), 10) . str_pad(number_format($synPart, 1), 10) . str_pad(number_format($total, 1), 10) . str_pad(number_format($clPct, 1) . "%", 10) . "\n";
    }

    // What if we applied a soft cap?
    echo "\n--- With soft cap at SPEED_SOFT_CAP=30: min(30, Cl*0.5) ---\n";
    $softCap = 30;
    echo str_pad("Cl", 8) . str_pad("Current", 12) . str_pad("Capped", 12) . str_pad("Difference", 12) . "\n";
    foreach ([10, 25, 50, 75, 100, 150, 200] as $cl) {
        $current = sim_vitesse($cl, 25, $condLevel);
        $cappedBase = 1 + min($softCap, $cl * 0.5) + (min($softCap * 2, $cl) * 25) / 200;
        $capped = max(1.0, floor($cappedBase * (1 + $condLevel / COVALENT_CONDENSEUR_DIVISOR) * 100) / 100);
        echo str_pad($cl, 8) . str_pad(number_format($current, 2), 12) . str_pad(number_format($capped, 2), 12) . str_pad(number_format($current - $capped, 2), 12) . "\n";
    }
}
```

**Step 3: Run and verify output**

Run: `php tools/balance_simulator.php`
Expected: Clean tables showing atom scaling

**Step 4: Commit**

```bash
git add tools/balance_simulator.php
git commit -m "feat: add atom efficiency and speed dominance analysis"
```

---

### Task 1.3: Implement combat simulation (Monte Carlo)

**Files:**
- Modify: `tools/balance_simulator.php`

Simulate 1000 combats between archetype pairs to measure formation effectiveness.

**Step 1: Add combat simulator**

```php
function simulateCombat($attacker, $defender, $defFormation, $attBuildings, $defBuildings) {
    // attacker/defender = ['atoms' => [...], ...]
    $condLevel = 10;
    $nbClasses = 4;
    $nbMolecules = 1000; // each player has 1000 molecules per class

    // Calculate total attack damage
    $totalAttack = 0;
    $totalDefense = 0;
    for ($c = 0; $c < $nbClasses; $c++) {
        $att = sim_attaque($attacker['atoms']['oxygene'], $attacker['atoms']['hydrogene'], $condLevel);
        $totalAttack += $att * $nbMolecules * (1 + ($attBuildings['ionisateur'] * IONISATEUR_COMBAT_BONUS_PER_LEVEL) / 100);

        $def = sim_defense($defender['atoms']['carbone'], $defender['atoms']['brome'], $condLevel);
        $defBonus = 1;
        if ($defFormation == FORMATION_PHALANGE && $c == 0) {
            $defBonus = 1 + FORMATION_PHALANX_DEFENSE_BONUS;
        }
        $totalDefense += $def * $nbMolecules * (1 + ($defBuildings['champdeforce'] * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) / 100) * $defBonus;
    }

    // Embuscade bonus
    if ($defFormation == FORMATION_EMBUSCADE) {
        $totalDefense *= (1 + FORMATION_AMBUSH_ATTACK_BONUS);
    }

    // HP calculations
    $attHpPerMol = sim_hp($attacker['atoms']['brome'], $attacker['atoms']['carbone'], $condLevel);
    $defHpPerMol = sim_hp($defender['atoms']['brome'], $defender['atoms']['carbone'], $condLevel);

    // Casualties
    $attKills = ($defHpPerMol > 0) ? min($nbMolecules * $nbClasses, floor($totalAttack / $defHpPerMol)) : $nbMolecules * $nbClasses;
    $defKills = ($attHpPerMol > 0) ? min($nbMolecules * $nbClasses, floor($totalDefense / $attHpPerMol)) : $nbMolecules * $nbClasses;

    $attSurvivors = ($nbMolecules * $nbClasses) - $defKills;
    $defSurvivors = ($nbMolecules * $nbClasses) - $attKills;

    // Pillage calculation
    $pillagePerSurvivor = sim_pillage($attacker['atoms']['soufre'], $attacker['atoms']['chlore'], $condLevel);
    $totalPillage = max(0, $attSurvivors) * $pillagePerSurvivor;

    // Winner
    if ($attSurvivors <= 0 && $defSurvivors <= 0) $winner = 'draw';
    elseif ($attSurvivors <= 0) $winner = 'defender';
    else $winner = 'attacker';

    return [
        'winner' => $winner,
        'att_survivors' => max(0, $attSurvivors),
        'def_survivors' => max(0, $defSurvivors),
        'att_kills' => $defKills,
        'def_kills' => $attKills,
        'total_pillage' => $totalPillage,
        'total_attack' => $totalAttack,
        'total_defense' => $totalDefense,
    ];
}

function analyzeCombatMatchups($archetypes) {
    global $FORMATIONS;
    echo "\n=== COMBAT MATCHUP ANALYSIS ===\n";
    echo "4000 molecules each (1000 per class), condLevel=10\n\n";

    $formationNames = ['Dispersée', 'Phalange', 'Embuscade'];

    foreach ([FORMATION_DISPERSEE, FORMATION_PHALANGE, FORMATION_EMBUSCADE] as $formation) {
        echo "--- Defender Formation: {$formationNames[$formation]} ---\n";
        echo str_pad("Att\\Def", 12);
        foreach ($archetypes as $name => $arch) echo str_pad($name, 14);
        echo "\n";

        foreach ($archetypes as $attName => $attArch) {
            echo str_pad($attName, 12);
            foreach ($archetypes as $defName => $defArch) {
                $result = simulateCombat($attArch, $defArch, $formation, $attArch['buildings'], $defArch['buildings']);
                $label = substr($result['winner'], 0, 1) . " " . round($result['att_survivors'] / 40) . "%";
                echo str_pad($label, 14);
            }
            echo "\n";
        }
        echo "\n";
    }
}
```

**Step 2: Add pillage analysis (Task 7.1 specific)**

```php
function analyzePillageDivisor($archetypes) {
    echo "\n=== PILLAGE SOUFRE DIVISOR ANALYSIS (H-008) ===\n";
    echo "Question: Should PILLAGE_SOUFRE_DIVISOR=2 be applied?\n\n";

    $condLevel = 10;
    $defResources = 50000; // defender has 50k of each resource

    echo str_pad("Archetype", 12) . str_pad("S", 6) . str_pad("Cl", 6)
         . str_pad("Pillage/mol", 14) . str_pad("With/2 div", 14)
         . str_pad("1000mol raid", 14) . str_pad("w/div raid", 14)
         . str_pad("% of 50k", 12) . "\n";

    foreach ($archetypes as $name => $arch) {
        $pil = sim_pillage($arch['atoms']['soufre'], $arch['atoms']['chlore'], $condLevel);
        // Hypothetical: divide soufre contribution by 2
        $pilWithDiv = sim_pillage(intval($arch['atoms']['soufre'] / 2), $arch['atoms']['chlore'], $condLevel);
        $raid1000 = $pil * 1000;
        $raidDiv1000 = $pilWithDiv * 1000;
        $pctOf50k = ($raid1000 / $defResources) * 100;

        echo str_pad($name, 12) . str_pad($arch['atoms']['soufre'], 6) . str_pad($arch['atoms']['chlore'], 6)
             . str_pad(number_format($pil), 14) . str_pad(number_format($pilWithDiv), 14)
             . str_pad(number_format($raid1000), 14) . str_pad(number_format($raidDiv1000), 14)
             . str_pad(number_format($pctOf50k, 1) . "%", 12) . "\n";
    }

    echo "\nConclusion: If pillage per raid exceeds 50% of typical storage, soufre divisor is warranted.\n";
}
```

**Step 3: Add formation win-rate analysis (Task 7.3 specific)**

```php
function analyzeFormationBalance($archetypes) {
    echo "\n=== FORMATION WIN-RATE ANALYSIS (H-010) ===\n";
    echo "Which formation gives the best defensive outcomes?\n\n";

    $formationNames = ['Dispersée', 'Phalange', 'Embuscade'];
    $formationWins = [0, 0, 0];
    $formationSurvivors = [0, 0, 0];
    $totalMatchups = 0;

    foreach ($archetypes as $attName => $attArch) {
        foreach ($archetypes as $defName => $defArch) {
            $totalMatchups++;
            $bestFormation = -1;
            $bestSurvivors = -1;
            for ($f = 0; $f <= 2; $f++) {
                $result = simulateCombat($attArch, $defArch, $f, $attArch['buildings'], $defArch['buildings']);
                $formationSurvivors[$f] += $result['def_survivors'];
                if ($result['winner'] == 'defender') $formationWins[$f]++;
                if ($result['def_survivors'] > $bestSurvivors) {
                    $bestSurvivors = $result['def_survivors'];
                    $bestFormation = $f;
                }
            }
        }
    }

    echo str_pad("Formation", 15) . str_pad("Def Wins", 12) . str_pad("Win Rate", 12) . str_pad("Avg Survivors", 15) . "\n";
    for ($f = 0; $f <= 2; $f++) {
        echo str_pad($formationNames[$f], 15)
             . str_pad($formationWins[$f], 12)
             . str_pad(number_format(($formationWins[$f] / $totalMatchups) * 100, 1) . "%", 12)
             . str_pad(number_format($formationSurvivors[$f] / $totalMatchups), 15) . "\n";
    }

    echo "\nTarget: Each formation should win ~33% ± 10% of matchups.\n";
    echo "If Phalange > 45%, reduce FORMATION_PHALANX_ABSORB.\n";
    echo "If Embuscade < 25%, increase FORMATION_AMBUSH_ATTACK_BONUS.\n";
}
```

**Step 4: Run analysis and verify**

Run: `php tools/balance_simulator.php`
Expected: All tables render with data

**Step 5: Commit**

```bash
git add tools/balance_simulator.php
git commit -m "feat: add combat, pillage, and formation balance analysis"
```

---

### Task 1.4: Add economy progression simulation

**Files:**
- Modify: `tools/balance_simulator.php`

Simulate 31-day resource accumulation and molecule lifecycle.

**Step 1: Add economy simulation**

```php
function simulateEconomy($archetypes) {
    echo "\n=== 31-DAY ECONOMY PROGRESSION ===\n";
    echo "Simulates resource accumulation per archetype over a 31-day season.\n\n";

    $hoursPerDay = 24;
    $totalHours = 31 * $hoursPerDay;
    $snapshots = [1, 3, 7, 14, 21, 31]; // days to report

    foreach ($archetypes as $name => $arch) {
        echo "--- $name ({$arch['desc']}) ---\n";
        $buildings = $arch['buildings'];
        $energy = 0;
        $atoms = 0; // simplified: total atoms (all types summed)
        $molecules = 0;
        $constructionPoints = 0;

        for ($hour = 1; $hour <= $totalHours; $hour++) {
            // Energy production (minus drain)
            $energyProd = sim_revenuEnergie($buildings['generateur']) - sim_drainageProducteur($buildings['producteur']);
            $energy += max(0, $energyProd);
            $energy = min($energy, sim_placeDepot($buildings['depot']));

            // Atom production (per atom type, simplified)
            $atomProd = sim_revenuAtome($buildings['producteur']) * 8; // 8 atom types
            $atoms += $atomProd;
            $atoms = min($atoms, sim_placeDepot($buildings['depot']) * 8);

            // Molecule creation (every 4 hours if enough atoms)
            $totalAtomsPerMol = array_sum($arch['atoms']);
            if ($hour % 4 == 0 && $atoms >= $totalAtomsPerMol * 10) {
                $newMols = 10;
                $atoms -= $totalAtomsPerMol * $newMols;
                $molecules += $newMols;
            }

            // Molecule decay (per second, but we simulate per hour)
            $decayCoef = sim_coefDisparition($totalAtomsPerMol, $buildings['stabilisateur']);
            $molecules *= pow($decayCoef, 3600);

            // Building upgrades (simplified: every 8 hours, upgrade cheapest priority building)
            if ($hour % 8 == 0) {
                $constructionPoints += 10; // approximate points per building level
            }

            // Daily snapshot
            $day = ceil($hour / $hoursPerDay);
            if (in_array($day, $snapshots) && $hour == $day * $hoursPerDay) {
                echo "  Day $day: Energy=" . number_format($energy)
                     . " Atoms=" . number_format($atoms)
                     . " Molecules=" . number_format(round($molecules))
                     . " HalfLife=" . number_format(sim_demiVie($totalAtomsPerMol, $buildings['stabilisateur'])) . "s"
                     . "\n";
            }
        }

        // Final stats
        $att = sim_attaque($arch['atoms']['oxygene'], $arch['atoms']['hydrogene'], 10) * round($molecules);
        $def = sim_defense($arch['atoms']['carbone'], $arch['atoms']['brome'], 10) * round($molecules);
        $pil = sim_pillage($arch['atoms']['soufre'], $arch['atoms']['chlore'], 10) * round($molecules);
        $spd = sim_vitesse($arch['atoms']['chlore'], $arch['atoms']['azote'], 10);
        echo "  FINAL: Atk=" . number_format($att) . " Def=" . number_format($def)
             . " Pil=" . number_format($pil) . " Spd=" . number_format($spd, 2) . "\n\n";
    }
}
```

**Step 2: Add ranking analysis**

```php
function analyzeRankingBalance($archetypes) {
    echo "\n=== RANKING BALANCE ANALYSIS ===\n";
    echo "Question: Does the sqrt ranking prevent any single activity from dominating?\n\n";

    // Simulate typical end-of-season stats per archetype
    $endOfSeason = [
        'raider'   => ['construction'=>500, 'attaque'=>800, 'defense'=>200, 'commerce'=>100, 'pillage'=>300000],
        'turtle'   => ['construction'=>600, 'attaque'=>50,  'defense'=>900, 'commerce'=>200, 'pillage'=>10000],
        'pillager' => ['construction'=>400, 'attaque'=>400, 'defense'=>150, 'commerce'=>50,  'pillage'=>800000],
        'speedster'=> ['construction'=>450, 'attaque'=>500, 'defense'=>200, 'commerce'=>300, 'pillage'=>150000],
        'balanced' => ['construction'=>500, 'attaque'=>400, 'defense'=>400, 'commerce'=>400, 'pillage'=>200000],
    ];

    echo str_pad("Archetype", 12) . str_pad("Constr", 10) . str_pad("Attack", 10)
         . str_pad("Defense", 10) . str_pad("Trade", 10) . str_pad("Pillage", 10)
         . str_pad("TOTAL", 10) . str_pad("Top Cat%", 10) . "\n";

    foreach ($endOfSeason as $name => $stats) {
        $transformed = [
            'c' => RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $stats['construction']), RANKING_SQRT_EXPONENT),
            'a' => RANKING_ATTACK_WEIGHT * pow(max(0, sim_pointsAttaque($stats['attaque'])), RANKING_SQRT_EXPONENT),
            'd' => RANKING_DEFENSE_WEIGHT * pow(max(0, sim_pointsDefense($stats['defense'])), RANKING_SQRT_EXPONENT),
            't' => RANKING_TRADE_WEIGHT * pow(max(0, $stats['commerce']), RANKING_SQRT_EXPONENT),
            'p' => RANKING_PILLAGE_WEIGHT * pow(max(0, sim_pointsPillage($stats['pillage'])), RANKING_SQRT_EXPONENT),
        ];
        $total = array_sum($transformed);
        $topCat = max($transformed);
        $topPct = ($total > 0) ? ($topCat / $total) * 100 : 0;

        echo str_pad($name, 12)
             . str_pad(number_format($transformed['c'], 1), 10)
             . str_pad(number_format($transformed['a'], 1), 10)
             . str_pad(number_format($transformed['d'], 1), 10)
             . str_pad(number_format($transformed['t'], 1), 10)
             . str_pad(number_format($transformed['p'], 1), 10)
             . str_pad(number_format($total, 1), 10)
             . str_pad(number_format($topPct, 1) . "%", 10) . "\n";
    }

    echo "\nTarget: No single category > 40% of total. Balanced player should rank highest.\n";
}
```

**Step 3: Add decay/half-life analysis**

```php
function analyzeDecay() {
    echo "\n=== MOLECULE DECAY ANALYSIS ===\n";
    echo "Half-life in seconds for different molecule sizes and stabilisateur levels.\n\n";

    $stabLevels = [0, 5, 10, 15, 20];
    $atomCounts = [50, 100, 200, 400, 800, 1200];

    echo str_pad("Atoms\\Stab", 12);
    foreach ($stabLevels as $s) echo str_pad("Stab=$s", 14);
    echo "\n";

    foreach ($atomCounts as $atoms) {
        echo str_pad($atoms, 12);
        foreach ($stabLevels as $stab) {
            $hl = sim_demiVie($atoms, $stab);
            if ($hl > 86400 * 365) {
                echo str_pad(">1year", 14);
            } elseif ($hl > 86400) {
                echo str_pad(number_format($hl / 86400, 1) . "d", 14);
            } elseif ($hl > 3600) {
                echo str_pad(number_format($hl / 3600, 1) . "h", 14);
            } else {
                echo str_pad(number_format($hl) . "s", 14);
            }
        }
        echo "\n";
    }
}
```

**Step 4: Wire up main execution**

```php
// === MAIN ===
echo "TVLW Balance Simulator v1.0\n";
echo "============================\n";
echo "Using constants from config.php\n\n";

analyzeAtomEfficiency();
analyzeSpeedDominance();
analyzePillageDivisor($ARCHETYPES);
analyzeCombatMatchups($ARCHETYPES);
analyzeFormationBalance($ARCHETYPES);
simulateEconomy($ARCHETYPES);
analyzeRankingBalance($ARCHETYPES);
analyzeDecay();

echo "\n=== SIMULATION COMPLETE ===\n";
echo "Review results above to determine:\n";
echo "  7.1: Whether PILLAGE_SOUFRE_DIVISOR should be applied\n";
echo "  7.2: Whether chlore speed needs a soft cap\n";
echo "  7.3: Whether formations need rebalancing\n";
```

**Step 5: Run full simulator**

Run: `php tools/balance_simulator.php > tools/balance_results.txt 2>&1`
Expected: Complete output file with all analysis tables

**Step 6: Commit**

```bash
git add tools/balance_simulator.php
git commit -m "feat: complete balance simulator with economy, ranking, and decay analysis"
```

---

## Part 2: Balance Tuning (Constants Adjustment)

### Task 2.1: Apply balance findings — speed formula (H-009)

**Files:**
- Modify: `includes/config.php` (add SPEED constants)
- Modify: `includes/formulas.php` (update vitesse function)

Based on simulator results, extract the hardcoded `0.5` and `200` in vitesse() to named constants. If chlore is dominant (>60% of speed from Cl alone), add a soft cap.

**Step 1: Add speed constants to config.php**

```php
// Speed formula: vitesse = 1 + Cl * SPEED_ATOM_COEFFICIENT + Cl*N / SPEED_SYNERGY_DIVISOR
define('SPEED_ATOM_COEFFICIENT', 0.5);
define('SPEED_SYNERGY_DIVISOR', 200);
// define('SPEED_SOFT_CAP', 30); // Uncomment if analysis shows Cl too dominant
```

**Step 2: Update vitesse() in formulas.php**

```php
function vitesse($Cl, $N, $nivCondCl)
{
    $clContrib = $Cl * SPEED_ATOM_COEFFICIENT;
    // Uncomment for soft cap: $clContrib = min(SPEED_SOFT_CAP, $clContrib);
    $base = 1 + $clContrib + (($Cl * $N) / SPEED_SYNERGY_DIVISOR);
    return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
}
```

**Step 3: Run tests**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/`
Expected: All 415+ tests pass

**Step 4: Commit**

```bash
git add includes/config.php includes/formulas.php
git commit -m "feat: extract speed formula hardcoded values to named constants (H-009)"
```

---

### Task 2.2: Evaluate pillage soufre divisor (H-008)

**Files:**
- Modify: `includes/config.php` (document decision)

Based on simulator results: if pillage per 1000-molecule raid is < 50% of typical depot capacity (sim_placeDepot at level 15-20 is ~4000-8000), soufre is NOT too strong and the divisor is NOT needed. Otherwise, apply it.

**Step 1: Document decision in config.php**

```php
// PILLAGE_SOUFRE_DIVISOR: Analysis shows pillage per 1000-mol raid = X% of typical depot.
// Decision: [Applied/Not needed] — soufre pillage is [balanced/too strong].
// define('PILLAGE_SOUFRE_DIVISOR', 2); // Exists but unused; see balance_results.txt
```

**Step 2: If divisor IS needed, apply in formulas.php**

Only if simulator shows pillage > 50% of depot per raid:
```php
function pillage($S, $Cl, $nivCondS, $bonusMedaille = 0)
{
    $effectiveS = $S; // or: $S / PILLAGE_SOUFRE_DIVISOR if needed
    $base = (pow($effectiveS, COVALENT_BASE_EXPONENT) + $effectiveS) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondS) * (1 + $bonusMedaille / 100));
}
```

**Step 3: Commit**

```bash
git add includes/config.php includes/formulas.php
git commit -m "balance: evaluate soufre pillage divisor (H-008) based on simulation data"
```

---

### Task 2.3: Evaluate formation balance (H-010)

**Files:**
- Modify: `includes/config.php` (adjust formation constants if needed)

Based on simulator formation win-rate analysis: if any formation wins >45% of matchups, adjust its constants.

**Step 1: Review and document findings**

Add comment to config.php formation section with analysis results.

**Step 2: Adjust if needed**

Possible adjustments:
- FORMATION_PHALANX_ABSORB: reduce from 0.60 to 0.50 if Phalange dominates
- FORMATION_AMBUSH_ATTACK_BONUS: increase from 0.15 to 0.20 if Embuscade is weak
- Add FORMATION_DISPERSEE_DODGE chance if Dispersée is weakest

**Step 3: Run tests and commit**

```bash
git add includes/config.php
git commit -m "balance: adjust formation constants based on simulation (H-010)"
```

---

## Part 3: Appendix Schema & Data Quality Fixes

### Task 3.1: Alliance creation atomic (M-003)

**Files:**
- Modify: `alliance.php`

Wrap alliance creation (INSERT INTO alliances + UPDATE autre SET idalliance) in withTransaction().

**Step 1: Read alliance.php creation code**

**Step 2: Wrap in withTransaction**

```php
withTransaction($base, function() use ($base, ...) {
    dbExecute($base, 'INSERT INTO alliances ...', ...);
    $allianceId = mysqli_insert_id($base);
    dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $allianceId, $joueur);
});
```

**Step 3: Commit**

```bash
git add alliance.php
git commit -m "fix: wrap alliance creation in transaction (M-003)"
```

---

### Task 3.2: Alliance join atomic (M-004)

**Files:**
- Modify: `alliance.php`

Wrap alliance join (INSERT candidature or UPDATE membership) in withTransaction().

**Step 1: Read alliance join code**

**Step 2: Wrap in withTransaction**

**Step 3: Commit**

```bash
git add alliance.php
git commit -m "fix: wrap alliance join in transaction (M-004)"
```

---

### Task 3.3: Pact accept check-then-act (M-008)

**Files:**
- Modify: `validerpacte.php`

Add SELECT...FOR UPDATE or use atomic UPDATE with WHERE conditions to prevent TOCTOU race.

**Step 1: Review current pact acceptance flow**

**Step 2: Use atomic approach**

```php
// Instead of: SELECT pact, check conditions, then UPDATE
// Use: UPDATE declarations SET ... WHERE id=? AND fin=0 AND alliance2=?
// Check affected_rows to confirm it worked
```

**Step 3: Commit**

```bash
git add validerpacte.php
git commit -m "fix: make pact acceptance atomic (M-008)"
```

---

### Task 3.4: totalPoints index (M-040)

**Files:**
- Create: `migrations/0026_add_totalpoints_index.sql`

**Step 1: Create migration**

```sql
ALTER TABLE autre ADD INDEX idx_totalPoints (totalPoints);
```

**Step 2: Commit**

```bash
git add migrations/0026_add_totalpoints_index.sql
git commit -m "perf: add index on autre.totalPoints (M-040)"
```

---

### Task 3.5: vacances idJoueur type fix (M-044)

**Files:**
- Create: `migrations/0027_fix_vacances_idjoueur.sql`

Ensure vacances.idJoueur column type matches membre.id.

**Step 1: Check current types and create migration if needed**

**Step 2: Commit**

```bash
git add migrations/0027_fix_vacances_idjoueur.sql
git commit -m "fix: normalize vacances.idJoueur type (M-044)"
```

---

## Part 4: Appendix UX Polish

### Task 4.1: Espionage LIKE pattern fix (M-010)

**Files:**
- Modify: `includes/game_actions.php`

Replace fragile `LIKE '%espionnage%'` with exact match or enum column.

**Step 1: Find the LIKE pattern**

**Step 2: Replace with exact match**

```php
// Before: WHERE type LIKE '%espionnage%'
// After: WHERE type = 'espionnage'
```

**Step 3: Commit**

```bash
git add includes/game_actions.php
git commit -m "fix: replace fragile espionage LIKE with exact match (M-010)"
```

---

### Task 4.2: Attack cost calculator clear fix (M-014)

**Files:**
- Modify: `attaquer.php`

Update the JavaScript attack cost calculator to reset when troop count is cleared.

**Step 1: Find the calculator JS**

**Step 2: Add oninput handler to reset on empty**

**Step 3: Commit**

```bash
git add attaquer.php
git commit -m "fix: attack cost calculator resets on clear (M-014)"
```

---

### Task 4.3: Incoming attack time estimate (M-015)

**Files:**
- Modify: `armee.php` or `includes/game_actions.php`

Add estimated arrival time for incoming attacks based on distance and speed.

**Step 1: Calculate ETA from attack action data**

```php
$distance = sqrt(pow($attacker_x - $defender_x, 2) + pow($attacker_y - $defender_y, 2));
$speed = vitesse($molecules['chlore'], $molecules['azote'], $condCl);
$eta = ceil($distance / $speed * SECONDS_PER_HOUR);
```

**Step 2: Display in attack notifications**

**Step 3: Commit**

```bash
git add armee.php includes/game_actions.php
git commit -m "feat: show estimated arrival time for incoming attacks (M-015)"
```

---

### Task 4.4: Password change shows email (M-030)

**Files:**
- Modify: `compte.php`

Display current email near the password change section for verification.

**Step 1: Add email display**

The $mail variable is already fetched on line 155. Just display it near the password section.

**Step 2: Commit**

```bash
git add compte.php
git commit -m "ux: show current email near password change (M-030)"
```

---

### Task 4.5: Map rendering optimization (M-035)

**Files:**
- Modify: `carte.php`

Instead of rendering all N×N tiles, only render tiles in the viewport.

**Step 1: Read current map rendering**

**Step 2: Add viewport bounds**

```php
$viewX = max(0, $playerX - 15);
$viewY = max(0, $playerY - 15);
$viewW = min($mapSize, $viewX + 30);
$viewH = min($mapSize, $viewY + 30);
// Only render tiles from viewX,viewY to viewW,viewH
```

**Step 3: Commit**

```bash
git add carte.php
git commit -m "perf: render only viewport tiles on map (M-035)"
```

---

### Task 4.6: Navigation badges for pending items (QOL-005)

**Files:**
- Modify: `includes/basicprivatehtml.php`

Add badge counts for unread reports, pending alliance requests, etc.

**Step 1: Count pending items per category**

```php
$unreadReports = dbCount($base, 'SELECT COUNT(*) as cnt FROM rapports WHERE proprietaire=? AND lu=0', 's', $_SESSION['login']);
$pendingRequests = dbCount($base, ...);
```

**Step 2: Add badge HTML to nav items**

```html
<span class="badge"><?= $unreadReports ?></span>
```

**Step 3: Commit**

```bash
git add includes/basicprivatehtml.php
git commit -m "feat: add navigation badges for pending items (QOL-005)"
```

---

### Task 4.7: Alliance research bonuses visible (QOL-013)

**Files:**
- Modify: `alliance.php`

Show current alliance research levels and their actual bonus effects.

**Step 1: Read current alliance research display**

**Step 2: Add bonus descriptions from $ALLIANCE_RESEARCH config**

```php
foreach ($ALLIANCE_RESEARCH as $key => $research) {
    $level = $allianceData[$key] ?? 0;
    $bonus = $level * $research['effect_per_level'];
    echo $research['name'] . " Niv.$level (+{$bonus}% {$research['effect_type']})";
}
```

**Step 3: Commit**

```bash
git add alliance.php
git commit -m "ux: show alliance research bonus effects (QOL-013)"
```

---

### Task 4.8: Attack cost preview (QOL-010)

**Files:**
- Modify: `attaquer.php`

Add a live preview showing energy cost before launching attack.

**Step 1: Add JS calculator for energy cost**

```javascript
function updateEnergyCost() {
    var totalAtoms = 0;
    // sum all troop inputs × atom count per molecule
    var cost = Math.round(ATTACK_ENERGY_COST_FACTOR * totalAtoms);
    document.getElementById('energyCostPreview').textContent = cost;
}
```

**Step 2: Commit**

```bash
git add attaquer.php
git commit -m "feat: live attack energy cost preview (QOL-010)"
```

---

## Part 5: Final Verification & Deploy

### Task 5.1: Run full test suite

**Step 1: Run tests**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-configuration tests/
```

Expected: All tests pass

**Step 2: Run balance simulator and save results**

```bash
php tools/balance_simulator.php > tools/balance_results.txt 2>&1
```

**Step 3: Commit results**

```bash
git add tools/balance_results.txt
git commit -m "docs: save balance simulation results"
```

---

### Task 5.2: Deploy to VPS

**Step 1: Push to GitHub**

```bash
git push origin main
```

**Step 2: Deploy to VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
```

**Step 3: Run any new migrations**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < migrations/0026_add_totalpoints_index.sql'
```

---

## Execution Order

```
Task 1.1-1.4: Build balance simulator (1.5h)
Task 2.1-2.3: Apply balance findings (30min)
Task 3.1-3.5: Schema fixes (30min)
Task 4.1-4.8: UX polish (2h)
Task 5.1-5.2: Verify & deploy (15min)
```

**Total estimated: 4-5 hours**
