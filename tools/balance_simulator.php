<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
/**
 * TVLW Balance Simulator v1.0
 * Explores game formula parameter space to identify balance issues.
 *
 * Run: php tools/balance_simulator.php
 *
 * This script uses the SAME constants from config.php and reimplements
 * the pure formula functions without any database dependency.
 *
 * Answers three specific balance questions:
 *   7.1 (H-008): Should PILLAGE_SOUFRE_DIVISOR=2 be applied?
 *   7.2 (H-009): Is chlore too dominant in speed formula?
 *   7.3 (H-010): Which defensive formation wins most often?
 */

// Load constants (no DB needed)
define('TVLW_SIMULATION', true);
require_once(__DIR__ . '/../includes/config.php');

// ============================================================================
// PURE FORMULA RE-IMPLEMENTATIONS (mirrors formulas.php, no DB)
// ============================================================================

function sim_modCond($nivCond)
{
    return 1 + ($nivCond / COVALENT_CONDENSEUR_DIVISOR);
}

function sim_attaque($O, $H, $nivCondO, $bonusMedaille = 0)
{
    $base = (pow($O, COVALENT_BASE_EXPONENT) + $O) * (1 + $H / COVALENT_SYNERGY_DIVISOR);
    return round($base * sim_modCond($nivCondO) * (1 + $bonusMedaille / 100));
}

function sim_defense($C, $Br, $nivCondC, $bonusMedaille = 0)
{
    $base = (pow($C, COVALENT_BASE_EXPONENT) + $C) * (1 + $Br / COVALENT_SYNERGY_DIVISOR);
    return round($base * sim_modCond($nivCondC) * (1 + $bonusMedaille / 100));
}

function sim_pillage($S, $Cl, $nivCondS, $bonusMedaille = 0)
{
    $base = (pow($S, COVALENT_BASE_EXPONENT) + $S) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
    return round($base * sim_modCond($nivCondS) * (1 + $bonusMedaille / 100));
}

function sim_vitesse($Cl, $N, $nivCondCl)
{
    $base = 1 + ($Cl * 0.5) + (($Cl * $N) / 200);
    return max(1.0, floor($base * sim_modCond($nivCondCl) * 100) / 100);
}

function sim_hp($Br, $C, $nivCondBr)
{
    $base = MOLECULE_MIN_HP + (pow($Br, COVALENT_BASE_EXPONENT) + $Br) * (1 + $C / COVALENT_SYNERGY_DIVISOR);
    return round($base * sim_modCond($nivCondBr));
}

function sim_destruction($H, $O, $nivCondH)
{
    $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $O / COVALENT_SYNERGY_DIVISOR);
    return round($base * sim_modCond($nivCondH));
}

function sim_tempsFormation($ntotal, $azote, $iode, $nivCondN, $nivLieur)
{
    $bonus_lieur = 1 + $nivLieur * LIEUR_LINEAR_BONUS_PER_LEVEL;
    $vitesse = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * sim_modCond($nivCondN) * $bonus_lieur;
    return ceil(($ntotal / $vitesse) * 100) / 100;
}

function sim_coefDisparition($nbAtomes, $stabLevel, $medalBonus = 0)
{
    $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
    $modStab = pow(STABILISATEUR_ASYMPTOTE, $stabLevel);
    $modMedal = 1 - ($medalBonus / 100);
    return pow($rawDecay, $modStab * $modMedal);
}

function sim_demiVie($nbAtomes, $stabLevel, $medalBonus = 0)
{
    $coef = sim_coefDisparition($nbAtomes, $stabLevel, $medalBonus);
    if ($coef >= 1.0) return PHP_INT_MAX;
    return round(log(0.5) / log($coef));
}

function sim_placeDepot($niveau)
{
    return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau));
}

function sim_drainageProducteur($niveau)
{
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau));
}

function sim_revenuEnergie($genLevel)
{
    return BASE_ENERGY_PER_LEVEL * $genLevel;
}

function sim_revenuAtome($prodPointLevel)
{
    return BASE_ATOMS_PER_POINT * $prodPointLevel;
}

function sim_pointsAttaque($pts)
{
    return $pts <= 0 ? 0 : round(ATTACK_POINTS_MULTIPLIER * sqrt(abs($pts)));
}

function sim_pointsDefense($pts)
{
    return $pts <= 0 ? 0 : round(DEFENSE_POINTS_MULTIPLIER * sqrt(abs($pts)));
}

function sim_pointsPillage($r)
{
    return round(tanh($r / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER);
}

function sim_calculerTotalPoints($construction, $attaque, $defense, $commerce, $pillage)
{
    return round(
        RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $construction), RANKING_SQRT_EXPONENT)
        + RANKING_ATTACK_WEIGHT * pow(max(0, $attaque), RANKING_SQRT_EXPONENT)
        + RANKING_DEFENSE_WEIGHT * pow(max(0, $defense), RANKING_SQRT_EXPONENT)
        + RANKING_TRADE_WEIGHT * pow(max(0, $commerce), RANKING_SQRT_EXPONENT)
        + RANKING_PILLAGE_WEIGHT * pow(max(0, $pillage), RANKING_SQRT_EXPONENT)
    );
}

function sim_buildingCost($level, $costBase, $growthBase)
{
    return round($costBase * pow($growthBase, $level));
}

// ============================================================================
// PLAYER ARCHETYPES
// ============================================================================

// Atom distributions for a ~200 total atom molecule (one class)
$ARCHETYPES = [
    'raider' => [
        'desc' => 'Aggressive attacker: high O/H for damage',
        'atoms' => ['carbone' => 10, 'azote' => 15, 'hydrogene' => 40, 'oxygene' => 50, 'chlore' => 25, 'soufre' => 25, 'brome' => 15, 'iode' => 20],
        'buildings' => ['generateur' => 25, 'producteur' => 20, 'depot' => 15, 'ionisateur' => 15, 'champdeforce' => 5, 'condenseur' => 10, 'lieur' => 8, 'stabilisateur' => 5, 'coffrefort' => 3],
        'attacks_per_day' => 4,
    ],
    'turtle' => [
        'desc' => 'Defensive player: high C/Br for tanking',
        'atoms' => ['carbone' => 50, 'azote' => 20, 'hydrogene' => 10, 'oxygene' => 10, 'chlore' => 15, 'soufre' => 10, 'brome' => 45, 'iode' => 40],
        'buildings' => ['generateur' => 25, 'producteur' => 20, 'depot' => 18, 'champdeforce' => 18, 'ionisateur' => 3, 'condenseur' => 10, 'lieur' => 5, 'stabilisateur' => 8, 'coffrefort' => 8],
        'attacks_per_day' => 0,
    ],
    'pillager' => [
        'desc' => 'Resource raider: high S/Cl for looting',
        'atoms' => ['carbone' => 10, 'azote' => 15, 'hydrogene' => 15, 'oxygene' => 25, 'chlore' => 40, 'soufre' => 55, 'brome' => 10, 'iode' => 30],
        'buildings' => ['generateur' => 25, 'producteur' => 20, 'depot' => 15, 'ionisateur' => 12, 'champdeforce' => 5, 'condenseur' => 10, 'lieur' => 8, 'stabilisateur' => 5, 'coffrefort' => 3],
        'attacks_per_day' => 3,
    ],
    'speedster' => [
        'desc' => 'Speed-focused: high Cl/N for fast attacks',
        'atoms' => ['carbone' => 15, 'azote' => 45, 'hydrogene' => 20, 'oxygene' => 20, 'chlore' => 55, 'soufre' => 15, 'brome' => 10, 'iode' => 20],
        'buildings' => ['generateur' => 25, 'producteur' => 20, 'depot' => 15, 'ionisateur' => 10, 'champdeforce' => 5, 'condenseur' => 12, 'lieur' => 10, 'stabilisateur' => 5, 'coffrefort' => 3],
        'attacks_per_day' => 2,
    ],
    'balanced' => [
        'desc' => 'Well-rounded: even atom distribution',
        'atoms' => ['carbone' => 25, 'azote' => 25, 'hydrogene' => 25, 'oxygene' => 25, 'chlore' => 25, 'soufre' => 25, 'brome' => 25, 'iode' => 25],
        'buildings' => ['generateur' => 22, 'producteur' => 18, 'depot' => 15, 'ionisateur' => 10, 'champdeforce' => 10, 'condenseur' => 12, 'lieur' => 8, 'stabilisateur' => 5, 'coffrefort' => 5],
        'attacks_per_day' => 1,
    ],
];

// ============================================================================
// ANALYSIS 1: ATOM EFFICIENCY TABLES
// ============================================================================

function analyzeAtomEfficiency()
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║              ATOM EFFICIENCY ANALYSIS                          ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Shows stat output per atom invested. Condenseur level=10 (mid-game).\n\n";

    $cond = 10;
    $primaries = [10, 25, 50, 75, 100, 150, 200];
    $secondaries = [0, 25, 50, 100];

    // --- ATTACK ---
    echo "┌─── ATTACK: attaque(O, H, condO=10) ───────────────────────────┐\n";
    echo " " . str_pad("O\\H", 6);
    foreach ($secondaries as $h) echo str_pad("H=$h", 11);
    echo str_pad("marginal", 11) . "\n";
    echo " " . str_repeat("─", 6 + 11 * 5) . "\n";
    foreach ($primaries as $o) {
        echo " " . str_pad($o, 6);
        foreach ($secondaries as $h) {
            echo str_pad(number_format(sim_attaque($o, $h, $cond)), 11);
        }
        if ($o > 10) {
            $marginal = (sim_attaque($o, 25, $cond) - sim_attaque($o - 10, 25, $cond)) / 10;
            echo str_pad("+" . number_format($marginal, 1) . "/O", 11);
        }
        echo "\n";
    }

    // --- DEFENSE ---
    echo "\n┌─── DEFENSE: defense(C, Br, condC=10) ──────────────────────────┐\n";
    echo " " . str_pad("C\\Br", 6);
    foreach ($secondaries as $br) echo str_pad("Br=$br", 11);
    echo str_pad("marginal", 11) . "\n";
    echo " " . str_repeat("─", 6 + 11 * 5) . "\n";
    foreach ($primaries as $c) {
        echo " " . str_pad($c, 6);
        foreach ($secondaries as $br) {
            echo str_pad(number_format(sim_defense($c, $br, $cond)), 11);
        }
        if ($c > 10) {
            $marginal = (sim_defense($c, 25, $cond) - sim_defense($c - 10, 25, $cond)) / 10;
            echo str_pad("+" . number_format($marginal, 1) . "/C", 11);
        }
        echo "\n";
    }

    // --- PILLAGE ---
    echo "\n┌─── PILLAGE: pillage(S, Cl, condS=10) ─────────────────────────┐\n";
    echo " " . str_pad("S\\Cl", 6);
    foreach ($secondaries as $cl) echo str_pad("Cl=$cl", 11);
    echo str_pad("marginal", 11) . "\n";
    echo " " . str_repeat("─", 6 + 11 * 5) . "\n";
    foreach ($primaries as $s) {
        echo " " . str_pad($s, 6);
        foreach ($secondaries as $cl) {
            echo str_pad(number_format(sim_pillage($s, $cl, $cond)), 11);
        }
        if ($s > 10) {
            $marginal = (sim_pillage($s, 25, $cond) - sim_pillage($s - 10, 25, $cond)) / 10;
            echo str_pad("+" . number_format($marginal, 1) . "/S", 11);
        }
        echo "\n";
    }

    // --- MOLECULE HP ---
    echo "\n┌─── MOLECULE HP: pointsDeVieMolecule(Br, C, condBr=10) ───────┐\n";
    echo " " . str_pad("Br\\C", 6);
    foreach ($secondaries as $c) echo str_pad("C=$c", 11);
    echo "\n " . str_repeat("─", 6 + 11 * 4) . "\n";
    foreach ($primaries as $br) {
        echo " " . str_pad($br, 6);
        foreach ($secondaries as $c) {
            echo str_pad(number_format(sim_hp($br, $c, $cond)), 11);
        }
        echo "\n";
    }

    // --- SPEED ---
    echo "\n┌─── SPEED: vitesse(Cl, N, condCl=10) ─────────────────────────┐\n";
    echo " " . str_pad("Cl\\N", 6);
    foreach ($secondaries as $n) echo str_pad("N=$n", 11);
    echo "\n " . str_repeat("─", 6 + 11 * 4) . "\n";
    foreach ($primaries as $cl) {
        echo " " . str_pad($cl, 6);
        foreach ($secondaries as $n) {
            echo str_pad(number_format(sim_vitesse($cl, $n, $cond), 2), 11);
        }
        echo "\n";
    }

    // --- BUILDING DESTRUCTION ---
    echo "\n┌─── DESTRUCTION: potentielDestruction(H, O, condH=10) ────────┐\n";
    echo " " . str_pad("H\\O", 6);
    foreach ($secondaries as $o) echo str_pad("O=$o", 11);
    echo "\n " . str_repeat("─", 6 + 11 * 4) . "\n";
    foreach ($primaries as $h) {
        echo " " . str_pad($h, 6);
        foreach ($secondaries as $o) {
            echo str_pad(number_format(sim_destruction($h, $o, $cond)), 11);
        }
        echo "\n";
    }
}

// ============================================================================
// ANALYSIS 2: SPEED DOMINANCE (H-009)
// ============================================================================

function analyzeSpeedDominance()
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       SPEED DOMINANCE ANALYSIS (H-009)                         ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Question: Is Cl too dominant in the speed formula?\n";
    echo "Formula: vitesse = 1 + Cl*0.5 + Cl*N/200\n\n";

    $cond = 10;

    echo "┌─── Cl contribution breakdown at N=25, condCl=10 ──────────────┐\n";
    echo " " . str_pad("Cl", 6) . str_pad("Base(1)", 10) . str_pad("Cl*0.5", 10)
        . str_pad("Cl*N/200", 10) . str_pad("Total", 10) . str_pad("Cl%", 10)
        . str_pad("Final", 10) . "\n";
    echo " " . str_repeat("─", 66) . "\n";

    foreach ([10, 25, 50, 75, 100, 150, 200] as $cl) {
        $n = 25;
        $basePart = 1;
        $clPart = $cl * 0.5;
        $synPart = ($cl * $n) / 200;
        $rawTotal = $basePart + $clPart + $synPart;
        $clPct = (($clPart + $synPart) / $rawTotal) * 100; // Both Cl-dependent terms
        $final = sim_vitesse($cl, $n, $cond);
        echo " " . str_pad($cl, 6) . str_pad(number_format($basePart, 1), 10)
            . str_pad(number_format($clPart, 1), 10) . str_pad(number_format($synPart, 1), 10)
            . str_pad(number_format($rawTotal, 1), 10)
            . str_pad(number_format($clPct, 1) . "%", 10)
            . str_pad(number_format($final, 2), 10) . "\n";
    }

    // Compare N influence
    echo "\n┌─── N contribution at Cl=50, condCl=10 ────────────────────────┐\n";
    echo " " . str_pad("N", 6) . str_pad("Base(1)", 10) . str_pad("Cl*0.5", 10)
        . str_pad("Cl*N/200", 10) . str_pad("Total", 10) . str_pad("N%", 10)
        . str_pad("Final", 10) . "\n";
    echo " " . str_repeat("─", 66) . "\n";
    foreach ([0, 10, 25, 50, 100, 150, 200] as $n) {
        $cl = 50;
        $basePart = 1;
        $clPart = $cl * 0.5;
        $synPart = ($cl * $n) / 200;
        $rawTotal = $basePart + $clPart + $synPart;
        $nPct = ($synPart / $rawTotal) * 100;
        $final = sim_vitesse($cl, $n, $cond);
        echo " " . str_pad($n, 6) . str_pad(number_format($basePart, 1), 10)
            . str_pad(number_format($clPart, 1), 10) . str_pad(number_format($synPart, 1), 10)
            . str_pad(number_format($rawTotal, 1), 10)
            . str_pad(number_format($nPct, 1) . "%", 10)
            . str_pad(number_format($final, 2), 10) . "\n";
    }

    // Soft cap comparison
    echo "\n┌─── Soft cap analysis: what if min(30, Cl*0.5)? ──────────────┐\n";
    $softCap = 30;
    echo " " . str_pad("Cl", 6) . str_pad("Current", 12) . str_pad("Capped", 12)
        . str_pad("Diff", 12) . str_pad("%Change", 12) . "\n";
    echo " " . str_repeat("─", 54) . "\n";
    foreach ([10, 25, 50, 75, 100, 150, 200] as $cl) {
        $current = sim_vitesse($cl, 25, $cond);
        $clContribCapped = min($softCap, $cl * 0.5);
        $cappedBase = 1 + $clContribCapped + (($cl * 25) / 200);
        $capped = max(1.0, floor($cappedBase * sim_modCond($cond) * 100) / 100);
        $diff = $current - $capped;
        $pctChange = ($current > 0) ? ($diff / $current) * 100 : 0;
        echo " " . str_pad($cl, 6) . str_pad(number_format($current, 2), 12)
            . str_pad(number_format($capped, 2), 12) . str_pad(number_format($diff, 2), 12)
            . str_pad(number_format($pctChange, 1) . "%", 12) . "\n";
    }

    echo "\n VERDICT: If Cl alone accounts for >70% of speed at high values,\n";
    echo "          a soft cap is warranted to keep N investment meaningful.\n";
}

// ============================================================================
// ANALYSIS 3: PILLAGE SOUFRE DIVISOR (H-008)
// ============================================================================

function analyzePillageDivisor($archetypes)
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       PILLAGE SOUFRE DIVISOR ANALYSIS (H-008)                  ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Question: Should PILLAGE_SOUFRE_DIVISOR=2 be applied?\n";
    echo "Context: PILLAGE_SOUFRE_DIVISOR exists but is unused in V4.\n\n";

    $cond = 10;

    // Depot capacity at various levels for reference
    echo "┌─── Reference: depot capacity at various levels ────────────────┐\n";
    foreach ([10, 15, 20, 25, 30] as $depotLvl) {
        echo "  Depot level $depotLvl: " . number_format(sim_placeDepot($depotLvl)) . " per resource\n";
    }

    echo "\n┌─── Pillage per molecule by archetype ─────────────────────────┐\n";
    echo " " . str_pad("Archetype", 12) . str_pad("S", 6) . str_pad("Cl", 6)
        . str_pad("Pil/mol", 12) . str_pad("w/div Pil", 12)
        . str_pad("500mol", 12) . str_pad("w/div 500", 12)
        . str_pad("vs Dep15", 12) . "\n";
    echo " " . str_repeat("─", 82) . "\n";

    $depotCap = sim_placeDepot(15);

    foreach ($archetypes as $name => $arch) {
        $pil = sim_pillage($arch['atoms']['soufre'], $arch['atoms']['chlore'], $cond);
        $pilDiv = sim_pillage(intval($arch['atoms']['soufre'] / 2), $arch['atoms']['chlore'], $cond);
        $raid500 = $pil * 500;
        $raidDiv500 = $pilDiv * 500;
        $pctOfDepot = ($depotCap > 0) ? ($raid500 / $depotCap) * 100 : 0;

        echo " " . str_pad($name, 12) . str_pad($arch['atoms']['soufre'], 6) . str_pad($arch['atoms']['chlore'], 6)
            . str_pad(number_format($pil), 12) . str_pad(number_format($pilDiv), 12)
            . str_pad(number_format($raid500), 12) . str_pad(number_format($raidDiv500), 12)
            . str_pad(number_format($pctOfDepot, 1) . "%", 12) . "\n";
    }

    // Vault protection comparison
    echo "\n┌─── With vault protection (coffrefort) ──────────────────────────┐\n";
    echo " Vault level 10, depot level 15: protected = " . number_format(round($depotCap * min(VAULT_MAX_PROTECTION_PCT, 10 * VAULT_PCT_PER_LEVEL))) . " per resource\n";
    echo " Effective pillageable = " . number_format($depotCap - round($depotCap * min(VAULT_MAX_PROTECTION_PCT, 10 * VAULT_PCT_PER_LEVEL))) . " per resource\n";

    echo "\n VERDICT: If pillage per 500-molecule raid > 100% of depot capacity,\n";
    echo "          the soufre divisor is needed to prevent raid wipes.\n";
    echo "          If < 50%, soufre is balanced and divisor is not needed.\n";
}

// ============================================================================
// ANALYSIS 4: COMBAT MATCHUPS
// ============================================================================

function simulateCombat($attArch, $defArch, $defFormation, $molsPerClass = 1000)
{
    $cond = 10;
    $nbClasses = 4;

    $ionBonus = 1 + ($attArch['buildings']['ionisateur'] * IONISATEUR_COMBAT_BONUS_PER_LEVEL) / 100;
    $cdFBonus = 1 + ($defArch['buildings']['champdeforce'] * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) / 100;

    // Total attack damage (attacker)
    $totalAttack = 0;
    for ($c = 0; $c < $nbClasses; $c++) {
        $totalAttack += sim_attaque($attArch['atoms']['oxygene'], $attArch['atoms']['hydrogene'], $cond) * $molsPerClass * $ionBonus;
    }

    // Total defense (defender's effective damage)
    $totalDefense = 0;
    for ($c = 0; $c < $nbClasses; $c++) {
        $defBonus = 1.0;
        if ($defFormation == FORMATION_PHALANGE && $c == 0) {
            $defBonus = 1 + FORMATION_PHALANX_DEFENSE_BONUS;
        }
        $totalDefense += sim_defense($defArch['atoms']['carbone'], $defArch['atoms']['brome'], $cond) * $molsPerClass * $cdFBonus * $defBonus;
    }

    // Embuscade
    if ($defFormation == FORMATION_EMBUSCADE) {
        // Defender always has same count in this sim
        // In practice, bonus applies when defender has MORE molecules
        // We'll treat it as: 50% chance defender has more (random variation)
        $totalDefense *= (1 + FORMATION_AMBUSH_ATTACK_BONUS * 0.5);
    }

    // HP per molecule
    $attHpPerMol = sim_hp($attArch['atoms']['brome'], $attArch['atoms']['carbone'], $cond);
    $defHpPerMol = sim_hp($defArch['atoms']['brome'], $defArch['atoms']['carbone'], $cond);

    $totalMols = $molsPerClass * $nbClasses;

    // Attacker casualties from defender's damage
    if ($defFormation == FORMATION_PHALANGE) {
        // 60% of damage hits class 1, rest spread to 2-4
        $phalanxDmg = $totalDefense * FORMATION_PHALANX_ABSORB;  // unused for attacker deaths
        // Just use straight cascade for attacker deaths (defender damages attacker)
    }
    // Simplified: total damage / HP per mol = kills
    $defKills = ($attHpPerMol > 0) ? min($totalMols, floor($totalDefense / $attHpPerMol)) : $totalMols;

    // Defender casualties from attacker damage (formation-dependent)
    if ($defFormation == FORMATION_PHALANGE) {
        $phalanxDmg = $totalAttack * FORMATION_PHALANX_ABSORB;
        $otherDmg = $totalAttack - $phalanxDmg;
        // Class 1 tanks phalanx portion — effective HP boosted by phalanx defense bonus
        $class1Hp = $defHpPerMol * (1 + FORMATION_PHALANX_DEFENSE_BONUS); // Stable isotope HP not applied in sim
        $class1Kills = ($class1Hp > 0) ? min($molsPerClass, floor($phalanxDmg / $class1Hp)) : $molsPerClass;
        $overflow = max(0, $phalanxDmg - $class1Kills * $class1Hp);
        $otherKills = ($defHpPerMol > 0) ? min($molsPerClass * 3, floor(($otherDmg + $overflow) / $defHpPerMol)) : $molsPerClass * 3;
        $attKills = $class1Kills + $otherKills;
    } elseif ($defFormation == FORMATION_DISPERSEE) {
        // Equal split across 4 classes
        $perClassDmg = $totalAttack / 4;
        $totalKills = 0;
        $overflow = 0;
        for ($c = 0; $c < $nbClasses; $c++) {
            $dmg = $perClassDmg + $overflow;
            $overflow = 0;
            if ($defHpPerMol > 0) {
                $kills = min($molsPerClass, floor($dmg / $defHpPerMol));
                $totalKills += $kills;
                $overflow = max(0, $dmg - $kills * $defHpPerMol);
            } else {
                $totalKills += $molsPerClass;
            }
        }
        $attKills = $totalKills;
    } else {
        // Embuscade/default: straight cascade
        $attKills = ($defHpPerMol > 0) ? min($totalMols, floor($totalAttack / $defHpPerMol)) : $totalMols;
    }

    $attSurvivors = max(0, $totalMols - $defKills);
    $defSurvivors = max(0, $totalMols - $attKills);

    // Winner
    if ($attSurvivors <= 0 && $defSurvivors <= 0) $winner = 'draw';
    elseif ($attSurvivors <= 0) $winner = 'defender';
    elseif ($defSurvivors <= 0) $winner = 'attacker';
    else $winner = 'draw'; // both have survivors = draw

    // Pillage
    $pillagePerMol = sim_pillage($attArch['atoms']['soufre'], $attArch['atoms']['chlore'], $cond);
    $totalPillage = ($winner == 'attacker') ? $attSurvivors * $pillagePerMol : 0;

    return [
        'winner' => $winner,
        'att_survivors' => $attSurvivors,
        'def_survivors' => $defSurvivors,
        'att_kills' => $attKills,
        'def_kills' => $defKills,
        'total_pillage' => $totalPillage,
        'total_attack' => $totalAttack,
        'total_defense' => $totalDefense,
    ];
}

function analyzeCombatMatchups($archetypes)
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       COMBAT MATCHUP ANALYSIS                                  ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "4000 molecules each (1000 per class), condLevel=10\n";
    echo "Format: [winner] survivors% | d=draw, a=attacker, D=defender\n\n";

    $formationNames = ['Dispersée', 'Phalange', 'Embuscade'];

    foreach ([FORMATION_DISPERSEE, FORMATION_PHALANGE, FORMATION_EMBUSCADE] as $formation) {
        echo "┌─── Defender Formation: {$formationNames[$formation]} " . str_repeat("─", 45 - strlen($formationNames[$formation])) . "┐\n";
        echo " " . str_pad("Att\\Def", 11);
        foreach ($archetypes as $name => $arch) echo str_pad(substr($name, 0, 10), 13);
        echo "\n " . str_repeat("─", 11 + 13 * count($archetypes)) . "\n";

        foreach ($archetypes as $attName => $attArch) {
            echo " " . str_pad(substr($attName, 0, 10), 11);
            foreach ($archetypes as $defName => $defArch) {
                $result = simulateCombat($attArch, $defArch, $formation);
                $w = substr($result['winner'], 0, 1);
                $attPct = round($result['att_survivors'] / 4000 * 100);
                $defPct = round($result['def_survivors'] / 4000 * 100);
                if ($w == 'a') $label = "A {$attPct}%a";
                elseif ($w == 'd') $label = "D {$defPct}%d";
                else $label = "= {$attPct}/{$defPct}";
                echo str_pad($label, 13);
            }
            echo "\n";
        }
        echo "\n";
    }
}

// ============================================================================
// ANALYSIS 5: FORMATION WIN-RATE (H-010)
// ============================================================================

function analyzeFormationBalance($archetypes)
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       FORMATION WIN-RATE ANALYSIS (H-010)                      ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Which formation gives the best defensive outcomes?\n\n";

    $formationNames = ['Dispersée', 'Phalange', 'Embuscade'];
    $formationWins = [0, 0, 0];
    $formationDraws = [0, 0, 0];
    $formationSurvivors = [0.0, 0.0, 0.0];
    $formationBest = [0, 0, 0]; // how often each is the best choice
    $totalMatchups = 0;

    foreach ($archetypes as $attName => $attArch) {
        foreach ($archetypes as $defName => $defArch) {
            $totalMatchups++;
            $bestFormation = -1;
            $bestSurvivors = -1;
            for ($f = 0; $f <= 2; $f++) {
                $result = simulateCombat($attArch, $defArch, $f);
                $formationSurvivors[$f] += $result['def_survivors'];
                if ($result['winner'] == 'defender') {
                    $formationWins[$f]++;
                } elseif ($result['winner'] == 'draw') {
                    $formationDraws[$f]++;
                }
                if ($result['def_survivors'] > $bestSurvivors) {
                    $bestSurvivors = $result['def_survivors'];
                    $bestFormation = $f;
                }
            }
            $formationBest[$bestFormation]++;
        }
    }

    echo "┌─── Formation Effectiveness Summary ────────────────────────────┐\n";
    echo " " . str_pad("Formation", 15) . str_pad("Def Wins", 12)
        . str_pad("Win Rate", 12) . str_pad("Draws", 10) . str_pad("Avg Surv", 12)
        . str_pad("Best In", 12) . "\n";
    echo " " . str_repeat("─", 73) . "\n";
    for ($f = 0; $f <= 2; $f++) {
        $winRate = ($totalMatchups > 0) ? ($formationWins[$f] / $totalMatchups) * 100 : 0;
        $avgSurv = ($totalMatchups > 0) ? $formationSurvivors[$f] / $totalMatchups : 0;
        $bestPct = ($totalMatchups > 0) ? ($formationBest[$f] / $totalMatchups) * 100 : 0;
        echo " " . str_pad($formationNames[$f], 15) . str_pad($formationWins[$f] . "/" . $totalMatchups, 12)
            . str_pad(number_format($winRate, 1) . "%", 12)
            . str_pad($formationDraws[$f], 10)
            . str_pad(number_format($avgSurv), 12)
            . str_pad(number_format($bestPct, 1) . "%", 12) . "\n";
    }

    echo "\n TARGET: Each formation should win ~33% ± 10% of matchups.\n";
    echo "         'Best In' should be close to 33% each.\n";
    echo "         If Phalange > 45%, reduce FORMATION_PHALANX_ABSORB.\n";
    echo "         If Embuscade < 20%, increase FORMATION_AMBUSH_ATTACK_BONUS.\n";
}

// ============================================================================
// ANALYSIS 6: 31-DAY ECONOMY PROGRESSION
// ============================================================================

function simulateEconomy($archetypes)
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       31-DAY ECONOMY PROGRESSION                               ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Simplified simulation: resource accrual per hour, mol creation\n";
    echo "every 4h when atoms available, decay per hour.\n\n";

    $totalHours = 31 * 24;
    $snapDays = [1, 3, 7, 14, 21, 31];

    foreach ($archetypes as $name => $arch) {
        echo "┌─── $name ({$arch['desc']}) " . str_repeat("─", max(1, 55 - strlen($name) - strlen($arch['desc']))) . "┐\n";
        $bld = $arch['buildings'];
        $totalAtoms = array_sum($arch['atoms']);

        $energy = 0.0;
        $atoms = 0.0; // simplified: total across all types
        $molecules = 0.0;
        $constructionPts = 0;
        $attackPts = 0;
        $defensePts = 0;
        $tradePts = 0;
        $pillagePts = 0.0;

        // Condenseur distributes points to atoms — use average across 8 types
        $condLevel = intval($bld['condenseur'] * 5 / 8); // approx: condenseur gives 5 pts/level, spread over 8

        for ($hour = 1; $hour <= $totalHours; $hour++) {
            // Energy production
            $energyProd = sim_revenuEnergie($bld['generateur']) - sim_drainageProducteur($bld['producteur']);
            $energy += max(0, $energyProd);
            $energy = min($energy, sim_placeDepot($bld['depot']));

            // Atom production (all 8 types, each at producteur level)
            // producteur gives 8 atom point per level, each atom at that level
            $atomsPerHour = sim_revenuAtome($bld['producteur']) * 8;
            $atoms += $atomsPerHour;
            $atoms = min($atoms, sim_placeDepot($bld['depot']) * 8);

            // Molecule creation every 4 hours
            if ($hour % 4 == 0 && $atoms >= $totalAtoms * 5) {
                $batch = 5;
                $atoms -= $totalAtoms * $batch;
                $molecules += $batch;
            }

            // Molecule decay (per second rate, applied for 3600 seconds)
            if ($molecules > 0) {
                $decayCoef = sim_coefDisparition($totalAtoms, $bld['stabilisateur']);
                $molecules *= pow($decayCoef, 3600);
                if ($molecules < 0.01) $molecules = 0;
            }

            // Construction points (simplified: +1 per 4 hours)
            if ($hour % 4 == 0) $constructionPts += 5;

            // Combat (raiders attack)
            if ($arch['attacks_per_day'] > 0 && $hour % intval(24 / $arch['attacks_per_day']) == 0) {
                $attackPts += 5;
                $pillagePts += 5000;
            }

            // Snapshots
            $day = ceil($hour / 24);
            if (in_array($day, $snapDays) && $hour == $day * 24) {
                $halfLife = sim_demiVie($totalAtoms, $bld['stabilisateur']);
                $hlStr = ($halfLife > 86400) ? number_format($halfLife / 86400, 1) . "d" : number_format($halfLife / 3600, 1) . "h";
                echo " Day " . str_pad($day, 3) . ": E=" . str_pad(number_format($energy), 10)
                    . " A=" . str_pad(number_format($atoms), 10)
                    . " Mol=" . str_pad(number_format(round($molecules)), 8)
                    . " T½=" . str_pad($hlStr, 8) . "\n";
            }
        }

        // Final military stats
        $att = sim_attaque($arch['atoms']['oxygene'], $arch['atoms']['hydrogene'], $condLevel) * round($molecules);
        $def = sim_defense($arch['atoms']['carbone'], $arch['atoms']['brome'], $condLevel) * round($molecules);
        $pil = sim_pillage($arch['atoms']['soufre'], $arch['atoms']['chlore'], $condLevel) * round($molecules);
        $spd = sim_vitesse($arch['atoms']['chlore'], $arch['atoms']['azote'], $condLevel);
        $formTime = sim_tempsFormation($totalAtoms, $arch['atoms']['azote'], $arch['atoms']['iode'], $condLevel, $bld['lieur']);

        echo " FINAL: Atk=" . number_format($att) . " Def=" . number_format($def)
            . " Pil=" . number_format($pil) . " Spd=" . number_format($spd, 2)
            . " Form=" . number_format($formTime, 1) . "h\n\n";
    }
}

// ============================================================================
// ANALYSIS 7: RANKING BALANCE
// ============================================================================

function analyzeRankingBalance()
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       RANKING BALANCE ANALYSIS                                 ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Does the sqrt ranking prevent any single activity from dominating?\n\n";

    // Simulated end-of-season stats per archetype
    $stats = [
        'raider'   => ['c' => 500, 'a' => 800, 'd' => 200, 't' => 100, 'p' => 300000],
        'turtle'   => ['c' => 600, 'a' => 50,  'd' => 900, 't' => 200, 'p' => 10000],
        'pillager' => ['c' => 400, 'a' => 400, 'd' => 150, 't' => 50,  'p' => 800000],
        'speedster' => ['c' => 450, 'a' => 500, 'd' => 200, 't' => 300, 'p' => 150000],
        'balanced' => ['c' => 500, 'a' => 400, 'd' => 400, 't' => 400, 'p' => 200000],
    ];

    echo "┌─── Sqrt Ranking Breakdown ────────────────────────────────────┐\n";
    echo " " . str_pad("Archetype", 12) . str_pad("Constr", 10) . str_pad("Attack", 10)
        . str_pad("Defense", 10) . str_pad("Trade", 10) . str_pad("Pillage", 10)
        . str_pad("TOTAL", 10) . str_pad("Top%", 8) . "\n";
    echo " " . str_repeat("─", 80) . "\n";

    foreach ($stats as $name => $s) {
        $transformed = [
            'c' => RANKING_CONSTRUCTION_WEIGHT * pow(max(0, $s['c']), RANKING_SQRT_EXPONENT),
            'a' => RANKING_ATTACK_WEIGHT * pow(max(0, sim_pointsAttaque($s['a'])), RANKING_SQRT_EXPONENT),
            'd' => RANKING_DEFENSE_WEIGHT * pow(max(0, sim_pointsDefense($s['d'])), RANKING_SQRT_EXPONENT),
            't' => RANKING_TRADE_WEIGHT * pow(max(0, $s['t']), RANKING_SQRT_EXPONENT),
            'p' => RANKING_PILLAGE_WEIGHT * pow(max(0, sim_pointsPillage($s['p'])), RANKING_SQRT_EXPONENT),
        ];
        $total = array_sum($transformed);
        $topCat = max($transformed);
        $topPct = ($total > 0) ? ($topCat / $total) * 100 : 0;

        echo " " . str_pad($name, 12)
            . str_pad(number_format($transformed['c'], 1), 10)
            . str_pad(number_format($transformed['a'], 1), 10)
            . str_pad(number_format($transformed['d'], 1), 10)
            . str_pad(number_format($transformed['t'], 1), 10)
            . str_pad(number_format($transformed['p'], 1), 10)
            . str_pad(number_format($total, 1), 10)
            . str_pad(number_format($topPct, 1) . "%", 8) . "\n";
    }

    echo "\n TARGET: No single category > 40% of total.\n";
    echo "         Balanced player should rank highest.\n";
}

// ============================================================================
// ANALYSIS 8: DECAY / HALF-LIFE
// ============================================================================

function analyzeDecay()
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       MOLECULE DECAY ANALYSIS                                  ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Half-life for different molecule sizes and stabilisateur levels.\n\n";

    $stabLevels = [0, 5, 10, 15, 20, 30];
    $atomCounts = [50, 100, 200, 400, 600, 800, 1200];

    echo "┌─── Half-life table (hours/days) ───────────────────────────────┐\n";
    echo " " . str_pad("Atoms\\Stab", 12);
    foreach ($stabLevels as $s) echo str_pad("S=$s", 14);
    echo "\n " . str_repeat("─", 12 + 14 * count($stabLevels)) . "\n";

    foreach ($atomCounts as $atoms) {
        echo " " . str_pad($atoms, 12);
        foreach ($stabLevels as $stab) {
            $hl = sim_demiVie($atoms, $stab);
            if ($hl > 86400 * 365) {
                echo str_pad(">1year", 14);
            } elseif ($hl > 86400) {
                echo str_pad(number_format($hl / 86400, 1) . " days", 14);
            } elseif ($hl > 3600) {
                echo str_pad(number_format($hl / 3600, 1) . " hours", 14);
            } else {
                echo str_pad(number_format($hl) . "s", 14);
            }
        }
        echo "\n";
    }

    echo "\n Molecule size guide:\n";
    echo "   50 atoms = tiny (cheap, fast to make)\n";
    echo "   200 atoms = standard (typical mid-game)\n";
    echo "   800 atoms = large (expensive, needs high stabilisateur)\n";
    echo "   1200 atoms = max-size (requires specialized build)\n";

    echo "\n TARGET: Standard (200 atom) molecules should have 3-7 day half-life\n";
    echo "         at typical stab level (5-10). Large (800+) molecules should\n";
    echo "         need stab 15+ to last more than a few days.\n";
}

// ============================================================================
// ANALYSIS 9: FORMATION TIME
// ============================================================================

function analyzeFormationTime()
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       FORMATION TIME ANALYSIS                                  ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "Time to create molecules at various sizes, azote, iode levels.\n\n";

    $condLevels = [5, 10, 15];
    $lieurLevels = [1, 5, 10, 15];
    $moleculeSizes = [50, 100, 200, 400, 800];
    $azoteLevels = [10, 25, 50, 100];
    $iodeLevels = [0, 20, 50];

    echo "┌─── Formation time in hours (condN=10, iode=20) ───────────────┐\n";
    echo " " . str_pad("Size\\Lieur", 12);
    foreach ($lieurLevels as $l) echo str_pad("L=$l N=25", 14);
    echo str_pad("L=10 N=50", 14) . str_pad("L=10 N=100", 14) . "\n";
    echo " " . str_repeat("─", 12 + 14 * 6) . "\n";

    foreach ($moleculeSizes as $size) {
        echo " " . str_pad($size . " atm", 12);
        foreach ($lieurLevels as $lieur) {
            $time = sim_tempsFormation($size, 25, 20, 10, $lieur);
            echo str_pad(number_format($time, 1) . "h", 14);
        }
        // High azote variants
        $timeN50 = sim_tempsFormation($size, 50, 20, 10, 10);
        echo str_pad(number_format($timeN50, 1) . "h", 14);
        $timeN100 = sim_tempsFormation($size, 100, 20, 10, 10);
        echo str_pad(number_format($timeN100, 1) . "h", 14);
        echo "\n";
    }

    echo "\n TARGET: Standard (200 atom) molecules should take 2-6 hours at\n";
    echo "         mid-game. Large (800+ atom) should take 12-24+ hours.\n";
}

// ============================================================================
// ANALYSIS 10: ARCHETYPE STAT COMPARISON
// ============================================================================

function analyzeArchetypeStats($archetypes)
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║       ARCHETYPE STAT COMPARISON (per molecule, cond=10)        ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

    $cond = 10;
    echo " " . str_pad("Archetype", 12)
        . str_pad("Atoms", 8) . str_pad("Attack", 10) . str_pad("Defense", 10)
        . str_pad("HP", 8) . str_pad("Destruct", 10) . str_pad("Pillage", 10)
        . str_pad("Speed", 8) . str_pad("FormTime", 10) . "\n";
    echo " " . str_repeat("─", 86) . "\n";

    foreach ($archetypes as $name => $arch) {
        $a = $arch['atoms'];
        $totalAtoms = array_sum($a);
        $att = sim_attaque($a['oxygene'], $a['hydrogene'], $cond);
        $def = sim_defense($a['carbone'], $a['brome'], $cond);
        $hp = sim_hp($a['brome'], $a['carbone'], $cond);
        $dest = sim_destruction($a['hydrogene'], $a['oxygene'], $cond);
        $pil = sim_pillage($a['soufre'], $a['chlore'], $cond);
        $spd = sim_vitesse($a['chlore'], $a['azote'], $cond);
        $form = sim_tempsFormation($totalAtoms, $a['azote'], $a['iode'], $cond, $arch['buildings']['lieur']);

        echo " " . str_pad($name, 12)
            . str_pad($totalAtoms, 8)
            . str_pad(number_format($att), 10)
            . str_pad(number_format($def), 10)
            . str_pad(number_format($hp), 8)
            . str_pad(number_format($dest), 10)
            . str_pad(number_format($pil), 10)
            . str_pad(number_format($spd, 2), 8)
            . str_pad(number_format($form, 1) . "h", 10) . "\n";
    }

    echo "\n BALANCE CHECK: No archetype should dominate >2 categories.\n";
    echo "   Raider should lead in Attack+Destruction.\n";
    echo "   Turtle should lead in Defense+HP.\n";
    echo "   Pillager should lead in Pillage.\n";
    echo "   Speedster should lead in Speed.\n";
    echo "   Balanced should be competitive but not best in any.\n";
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║     TVLW Balance Simulator v1.0                                ║\n";
echo "║     Using constants from includes/config.php                   ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";

analyzeArchetypeStats($ARCHETYPES);
analyzeAtomEfficiency();
analyzeSpeedDominance();
analyzePillageDivisor($ARCHETYPES);
analyzeCombatMatchups($ARCHETYPES);
analyzeFormationBalance($ARCHETYPES);
simulateEconomy($ARCHETYPES);
analyzeRankingBalance();
analyzeDecay();
analyzeFormationTime();

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║     SIMULATION COMPLETE                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\nReview results above to determine:\n";
echo "  7.1 (H-008): Whether PILLAGE_SOUFRE_DIVISOR should be applied\n";
echo "  7.2 (H-009): Whether chlore speed needs a soft cap\n";
echo "  7.3 (H-010): Whether formations need rebalancing\n";
echo "\nKey thresholds:\n";
echo "  Speed: Cl should not account for >70% of speed at high values\n";
echo "  Pillage: 500-molecule raid should loot <50% of depot capacity\n";
echo "  Formations: Each should win 25-40% of matchups\n";
echo "  Ranking: No category >40% of total; balanced archetype ranks #1\n";
echo "  Decay: 200-atom mol half-life 3-7 days at stab 5-10\n";
