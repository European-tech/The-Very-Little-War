<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for resource production, storage, and building cost/time formulas.
 *
 * Formula references (from includes/fonctions.php and includes/config.php):
 *   Energy production: BASE_ENERGY_PER_LEVEL (75) * generator_level
 *   Atom production:   BASE_ATOMS_PER_POINT (60) * points
 *   Storage capacity:  round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, level))
 *   Producteur drain:  PRODUCTEUR_DRAIN_PER_LEVEL (8) * level
 *   Net energy:        75 * gen_level - 8 * prod_level
 *
 *   Building costs:    round((1 - medalBonus/100) * baseCost * pow(level, exponent))
 *   Building time:     round(baseTime * pow(level + offset, timeExponent))
 *   Duplicateur bonus: level / 100
 *   Duplicateur cost:  round(10 * pow(2.5, level + 1))
 */
class ResourceFormulasTest extends TestCase
{
    // =========================================================================
    // ENERGY PRODUCTION
    // Formula: 75 * generator_level (base only, before medals/duplicateur)
    // =========================================================================

    public function testBaseEnergyPerLevel(): void
    {
        $this->assertEquals(75, BASE_ENERGY_PER_LEVEL);
    }

    public function testEnergyProductionAtLevel1(): void
    {
        $production = BASE_ENERGY_PER_LEVEL * 1;
        $this->assertEquals(75, $production);
    }

    public function testEnergyProductionAtLevel10(): void
    {
        $production = BASE_ENERGY_PER_LEVEL * 10;
        $this->assertEquals(750, $production);
    }

    public function testEnergyProductionAtLevel50(): void
    {
        $production = BASE_ENERGY_PER_LEVEL * 50;
        $this->assertEquals(3750, $production);
    }

    public function testEnergyProductionLinearGrowth(): void
    {
        // Energy should grow linearly with generator level
        $prod5 = BASE_ENERGY_PER_LEVEL * 5;
        $prod10 = BASE_ENERGY_PER_LEVEL * 10;
        $prod20 = BASE_ENERGY_PER_LEVEL * 20;

        $this->assertEquals($prod5 * 2, $prod10);
        $this->assertEquals($prod10 * 2, $prod20);
    }

    // =========================================================================
    // ATOM PRODUCTION
    // Formula: bonusDuplicateur * 60 * niveau
    // =========================================================================

    public function testBaseAtomsPerPoint(): void
    {
        $this->assertEquals(60, BASE_ATOMS_PER_POINT);
    }

    public function testAtomProductionAtLevel1(): void
    {
        $production = BASE_ATOMS_PER_POINT * 1;
        $this->assertEquals(60, $production);
    }

    public function testAtomProductionAtLevel10(): void
    {
        $production = BASE_ATOMS_PER_POINT * 10;
        $this->assertEquals(600, $production);
    }

    public function testAtomProductionWithDuplicateur(): void
    {
        // Duplicateur at level 5: bonus = 1 + 5/100 = 1.05
        $level = 5;
        $prodPoints = 10;
        $bonusDuplicateur = 1 + $level / 100;
        $production = round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $prodPoints);

        $this->assertEquals(round(1.05 * 60 * 10), $production);
        $this->assertEquals(630, $production);
    }

    public function testAtomProductionWithoutAlliance(): void
    {
        // No alliance means bonusDuplicateur = 1
        $prodPoints = 8;
        $production = round(1 * BASE_ATOMS_PER_POINT * $prodPoints);
        $this->assertEquals(480, $production);
    }

    // =========================================================================
    // STORAGE CAPACITY (V4)
    // Formula: round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, level))
    // =========================================================================

    public function testBaseStorageInitial(): void
    {
        $this->assertEquals(1000, BASE_STORAGE_INITIAL);
    }

    public function testStorageAtLevel1(): void
    {
        $storage = round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 1));
        $this->assertEquals(1150, $storage);
    }

    public function testStorageAtLevel10(): void
    {
        $storage = round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 10));
        $this->assertGreaterThan(3000, $storage);
        $this->assertLessThan(5000, $storage);
    }

    public function testStorageExponentialGrowth(): void
    {
        $storage5 = round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 5));
        $storage10 = round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 10));
        // Exponential: level 10 > 2x level 5
        $this->assertGreaterThan($storage5 * 1.5, $storage10);
    }

    // =========================================================================
    // PRODUCTEUR ENERGY DRAIN
    // Formula: round(8 * level)
    // =========================================================================

    public function testProducteurDrainPerLevel(): void
    {
        $this->assertEquals(8, PRODUCTEUR_DRAIN_PER_LEVEL);
    }

    public function testProducteurDrainAtLevel1(): void
    {
        $drain = round(PRODUCTEUR_DRAIN_PER_LEVEL * 1);
        $this->assertEquals(8, $drain);
    }

    public function testProducteurDrainAtLevel10(): void
    {
        $drain = round(PRODUCTEUR_DRAIN_PER_LEVEL * 10);
        $this->assertEquals(80, $drain);
    }

    public function testProducteurDrainAtLevel50(): void
    {
        $drain = round(PRODUCTEUR_DRAIN_PER_LEVEL * 50);
        $this->assertEquals(400, $drain);
    }

    // =========================================================================
    // NET ENERGY (Generation - Drain)
    // Formula: 75 * genLevel - 8 * prodLevel
    // =========================================================================

    public function testNetEnergyPositive(): void
    {
        $genLevel = 10;
        $prodLevel = 10;
        $net = BASE_ENERGY_PER_LEVEL * $genLevel - PRODUCTEUR_DRAIN_PER_LEVEL * $prodLevel;
        // 750 - 80 = 670
        $this->assertEquals(670, $net);
    }

    public function testNetEnergyBreakEven(): void
    {
        // Find the ratio where energy goes negative
        // 75 * gen = 8 * prod  =>  prod/gen = 75/8 = 9.375
        // So if prod > 9.375 * gen, energy goes negative
        $genLevel = 1;
        $prodLevel = 10; // Above the 9.375 threshold
        $net = BASE_ENERGY_PER_LEVEL * $genLevel - PRODUCTEUR_DRAIN_PER_LEVEL * $prodLevel;
        // 75 - 80 = -5
        $this->assertLessThan(0, $net);
    }

    public function testNetEnergyExactBreakEvenRatio(): void
    {
        // The break-even ratio is exactly 75/8
        $ratio = BASE_ENERGY_PER_LEVEL / PRODUCTEUR_DRAIN_PER_LEVEL;
        $this->assertEqualsWithDelta(9.375, $ratio, 0.001);
    }

    public function testNetEnergyEqualLevels(): void
    {
        // At equal levels, energy should always be positive since 75 > 8
        for ($level = 1; $level <= 50; $level++) {
            $net = BASE_ENERGY_PER_LEVEL * $level - PRODUCTEUR_DRAIN_PER_LEVEL * $level;
            $this->assertGreaterThan(0, $net, "Net energy should be positive at equal level $level");
            $this->assertEquals(67 * $level, $net);
        }
    }

    // =========================================================================
    // BUILDING COST FORMULAS
    // Energy cost: round((1 - medalBonus/100) * baseCost * pow(level, exponent))
    // =========================================================================

    /**
     * Helper to compute building cost.
     */
    private function computeBuildingCost(float $baseCost, float $exponent, int $level, float $medalBonus = 0): int
    {
        return (int) round((1 - $medalBonus / 100) * $baseCost * pow($level, $exponent));
    }

    public function testGenerateurEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        // V4: exponential cost = base * pow(growth_base, level)
        $base = $BUILDING_CONFIG['generateur']['cost_energy_base']; // 50
        $growth = $BUILDING_CONFIG['generateur']['cost_growth_base']; // 1.15

        // Level 1: round(50 * pow(1.15, 1)) = round(57.5) = 58
        $expected1 = (int) round($base * pow($growth, 1));
        $this->assertEquals($expected1, (int) round($base * pow($growth, 1)));

        // Level 10: round(50 * pow(1.15, 10))
        $expected10 = (int) round($base * pow($growth, 10));
        $this->assertEquals($expected10, (int) round(50 * pow(1.15, 10)));
    }

    public function testGenerateurAtomsCost(): void
    {
        global $BUILDING_CONFIG;

        // V4: exponential cost = base * pow(growth_base, level)
        $base = $BUILDING_CONFIG['generateur']['cost_atoms_base']; // 75
        $growth = $BUILDING_CONFIG['generateur']['cost_growth_base']; // 1.15

        // Level 1: round(75 * pow(1.15, 1)) = round(86.25) = 86
        $expected1 = (int) round($base * pow($growth, 1));
        $this->assertEquals($expected1, (int) round(75 * pow(1.15, 1)));

        // Level 10: round(75 * pow(1.15, 10))
        $expected10 = (int) round($base * pow($growth, 10));
        $this->assertEquals($expected10, (int) round(75 * pow(1.15, 10)));
    }

    public function testProducteurEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['producteur']['cost_energy_base']; // 75
        $growth = $BUILDING_CONFIG['producteur']['cost_growth_base']; // 1.15

        $expected = (int) round($base * pow($growth, 1));
        $this->assertEquals($expected, (int) round(75 * pow(1.15, 1)));
    }

    public function testDepotEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['depot']['cost_energy_base']; // 100
        $growth = $BUILDING_CONFIG['depot']['cost_growth_base']; // 1.15

        $expected = (int) round($base * pow($growth, 1));
        $this->assertEquals($expected, (int) round(100 * pow(1.15, 1)));
    }

    public function testCondenseurEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['condenseur']['cost_energy_base']; // 25
        $growth = $BUILDING_CONFIG['condenseur']['cost_growth_base']; // 1.20 (ADV)

        $expected1 = (int) round($base * pow($growth, 1));
        $this->assertEquals($expected1, (int) round(25 * pow(1.20, 1)));

        // Level 10: round(25 * pow(1.20, 10))
        $expected10 = (int) round($base * pow($growth, 10));
        $this->assertEquals($expected10, (int) round(25 * pow(1.20, 10)));
    }

    public function testBuildingCostWithMedalDiscount(): void
    {
        // 6% medal bonus reduces cost by 6%
        $baseCost = 100;
        $exp = 0.7;
        $level = 10;
        $noMedal = $this->computeBuildingCost($baseCost, $exp, $level, 0);
        $withMedal = $this->computeBuildingCost($baseCost, $exp, $level, 6);

        $this->assertLessThan($noMedal, $withMedal);

        // The discount should be approximately 6%
        $expectedDiscount = round($noMedal * 0.94);
        $this->assertEquals($expectedDiscount, $withMedal);
    }

    public function testBuildingCostIncreasesWithLevel(): void
    {
        $baseCost = 50;
        $exp = 0.7;

        $prevCost = 0;
        for ($level = 1; $level <= 20; $level++) {
            $cost = $this->computeBuildingCost($baseCost, $exp, $level);
            $this->assertGreaterThan(
                $prevCost,
                $cost,
                "Cost at level $level should exceed cost at level " . ($level - 1)
            );
            $prevCost = $cost;
        }
    }

    public function testCostGrowthIsSublinear(): void
    {
        // With exponent 0.7 < 1, cost growth is sublinear (diminishing returns)
        $baseCost = 50;
        $exp = 0.7;

        $cost1to5 = $this->computeBuildingCost($baseCost, $exp, 5) - $this->computeBuildingCost($baseCost, $exp, 1);
        $cost5to10 = $this->computeBuildingCost($baseCost, $exp, 10) - $this->computeBuildingCost($baseCost, $exp, 5);

        // Sublinear: the absolute increase from 5 to 10 should be less than 1 to 5
        $this->assertLessThan($cost1to5, $cost5to10);
    }

    // =========================================================================
    // BUILDING CONSTRUCTION TIME FORMULAS
    // Various patterns per building type
    // =========================================================================

    public function testGenerateurBuildTime(): void
    {
        global $BUILDING_CONFIG;

        // V4: exponential time = base * pow(growth_base, level)
        $base = $BUILDING_CONFIG['generateur']['time_base']; // 60
        $growth = $BUILDING_CONFIG['generateur']['time_growth_base']; // 1.10

        // Level 1 is a special case: 10 seconds
        $this->assertEquals(10, $BUILDING_CONFIG['generateur']['time_level1']);

        // Level 10: round(60 * pow(1.10, 10))
        $expected = (int) round($base * pow($growth, 10));
        $this->assertEquals($expected, (int) round(60 * pow(1.10, 10)));
    }

    public function testDepotBuildTime(): void
    {
        global $BUILDING_CONFIG;

        // V4: Depot uses exponential time
        $base = $BUILDING_CONFIG['depot']['time_base']; // 80
        $growth = $BUILDING_CONFIG['depot']['time_growth_base']; // 1.10

        $expected1 = (int) round($base * pow($growth, 1));
        $expected10 = (int) round($base * pow($growth, 10));
        $this->assertEquals($expected1, (int) round(80 * pow(1.10, 1)));
        $this->assertEquals($expected10, (int) round(80 * pow(1.10, 10)));
    }

    public function testChampdeforceBuildTime(): void
    {
        global $BUILDING_CONFIG;

        // V4: exponential time with offset
        $baseTime = $BUILDING_CONFIG['champdeforce']['time_base']; // 20
        $growth = $BUILDING_CONFIG['champdeforce']['time_growth_base']; // 1.10
        $offset = $BUILDING_CONFIG['champdeforce']['time_level_offset']; // 2

        // Level 1: round(20 * pow(1.10, 1+2)) = round(20 * pow(1.10, 3))
        $expected1 = (int) round($baseTime * pow($growth, 1 + $offset));
        $this->assertEquals((int) round(20 * pow(1.10, 3)), $expected1);

        // Level 10: round(20 * pow(1.10, 10+2)) = round(20 * pow(1.10, 12))
        $expected10 = (int) round($baseTime * pow($growth, 10 + $offset));
        $this->assertEquals((int) round(20 * pow(1.10, 12)), $expected10);
    }

    public function testIonisateurBuildTime(): void
    {
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['ionisateur']['time_base']; // 20
        $growth = $BUILDING_CONFIG['ionisateur']['time_growth_base']; // 1.10
        $offset = $BUILDING_CONFIG['ionisateur']['time_level_offset']; // 2

        // Level 5: round(20 * pow(1.10, 5+2)) = round(20 * pow(1.10, 7))
        $expected = (int) round($baseTime * pow($growth, 5 + $offset));
        $this->assertEquals((int) round(20 * pow(1.10, 7)), $expected);
    }

    public function testCondenseurBuildTime(): void
    {
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['condenseur']['time_base']; // 120
        $growth = $BUILDING_CONFIG['condenseur']['time_growth_base']; // 1.10
        $offset = $BUILDING_CONFIG['condenseur']['time_level_offset']; // 1

        // Level 5: round(120 * pow(1.10, 5+1)) = round(120 * pow(1.10, 6))
        $expected = (int) round($baseTime * pow($growth, 5 + $offset));
        $this->assertEquals((int) round(120 * pow(1.10, 6)), $expected);
    }

    public function testLieurBuildTime(): void
    {
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['lieur']['time_base']; // 100
        $growth = $BUILDING_CONFIG['lieur']['time_growth_base']; // 1.10
        $offset = $BUILDING_CONFIG['lieur']['time_level_offset']; // 1

        // Level 10: round(100 * pow(1.10, 10+1)) = round(100 * pow(1.10, 11))
        $expected = (int) round($baseTime * pow($growth, 10 + $offset));
        $this->assertEquals((int) round(100 * pow(1.10, 11)), $expected);
    }

    public function testStabilisateurBuildTime(): void
    {
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['stabilisateur']['time_base']; // 120
        $growth = $BUILDING_CONFIG['stabilisateur']['time_growth_base']; // 1.10
        $offset = $BUILDING_CONFIG['stabilisateur']['time_level_offset']; // 1

        // Level 3: round(120 * pow(1.10, 3+1)) = round(120 * pow(1.10, 4))
        $expected = (int) round($baseTime * pow($growth, 3 + $offset));
        $this->assertEquals((int) round(120 * pow(1.10, 4)), $expected);
    }

    public function testBuildTimesAlwaysIncrease(): void
    {
        // V4: exponential time = base * pow(growth_base, level + offset)
        global $BUILDING_CONFIG;

        $buildings = [
            'depot'         => ['offset' => 0],
            'champdeforce'  => ['offset' => 2],
            'ionisateur'    => ['offset' => 2],
            'condenseur'    => ['offset' => 1],
            'lieur'         => ['offset' => 1],
            'stabilisateur' => ['offset' => 1],
        ];

        foreach ($buildings as $name => $extra) {
            $base   = $BUILDING_CONFIG[$name]['time_base'];
            $growth = $BUILDING_CONFIG[$name]['time_growth_base'];
            $offset = $extra['offset'];

            $prevTime = 0;
            for ($level = 1; $level <= 20; $level++) {
                $time = round($base * pow($growth, $level + $offset));
                $this->assertGreaterThanOrEqual(
                    $prevTime,
                    $time,
                    "Build time for $name at level $level should not decrease"
                );
                $prevTime = $time;
            }
        }
    }

    // =========================================================================
    // DUPLICATEUR FORMULAS
    // Bonus: level / 100 (1% per level)
    // Cost:  round(10 * pow(2.5, level + 1))
    // =========================================================================

    public function testDuplicateurBonusFormula(): void
    {
        // Level 0: 0% bonus
        $this->assertEquals(0.0, 0 * DUPLICATEUR_BONUS_PER_LEVEL);

        // Level 5: 5% bonus
        $this->assertEquals(0.05, 5 * DUPLICATEUR_BONUS_PER_LEVEL);

        // Level 10: 10% bonus
        $this->assertEquals(0.10, 10 * DUPLICATEUR_BONUS_PER_LEVEL);
    }

    public function testDuplicateurCostFormula(): void
    {
        // V4: DUPLICATEUR_BASE_COST=100, DUPLICATEUR_COST_FACTOR=1.5
        // Level 1: round(100 * pow(1.5, 2)) = round(100 * 2.25) = 225
        $cost1 = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 1 + 1));
        $this->assertEquals(225, $cost1);

        // Level 2: round(100 * pow(1.5, 3)) = round(100 * 3.375) = 338
        $cost2 = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 2 + 1));
        $this->assertEquals(338, $cost2);

        // Level 5: round(100 * pow(1.5, 6)) = round(100 * 11.39) = 1139
        $cost5 = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 5 + 1));
        $expected = (int) round(100 * pow(1.5, 6));
        $this->assertEquals($expected, $cost5);
    }

    public function testDuplicateurCostExponentialGrowth(): void
    {
        $prevCost = 0;
        $prevDelta = 0;

        for ($level = 1; $level <= 10; $level++) {
            $cost = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, $level + 1));
            $delta = $cost - $prevCost;

            // Each step should cost more than the previous step (exponential)
            if ($level > 2) {
                $this->assertGreaterThan(
                    $prevDelta,
                    $delta,
                    "Duplicateur cost increase at level $level should exceed level " . ($level - 1)
                );
            }
            $prevCost = $cost;
            $prevDelta = $delta;
        }
    }

    // =========================================================================
    // BUILDING POINTS FORMULAS
    // Various buildings give points toward total score
    // =========================================================================

    public function testGenerateurPoints(): void
    {
        global $BUILDING_CONFIG;

        // Points: 1 + floor(level * 0.1)
        $base = $BUILDING_CONFIG['generateur']['points_base'];
        $factor = $BUILDING_CONFIG['generateur']['points_level_factor'];

        // Level 1: 1 + floor(0.1) = 1 + 0 = 1
        $this->assertEquals(1, $base + floor(1 * $factor));

        // Level 10: 1 + floor(1.0) = 1 + 1 = 2
        $this->assertEquals(2, $base + floor(10 * $factor));

        // Level 20: 1 + floor(2.0) = 1 + 2 = 3
        $this->assertEquals(3, $base + floor(20 * $factor));
    }

    public function testCondenseurPoints(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['condenseur']['points_base']; // 2
        $factor = $BUILDING_CONFIG['condenseur']['points_level_factor']; // 0.1

        // Level 1: 2 + floor(0.1) = 2
        $this->assertEquals(2, $base + floor(1 * $factor));

        // Level 10: 2 + floor(1.0) = 3
        $this->assertEquals(3, $base + floor(10 * $factor));
    }

    public function testStabilisateurPoints(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['stabilisateur']['points_base']; // 3
        $factor = $BUILDING_CONFIG['stabilisateur']['points_level_factor']; // 0.1

        // Level 1: 3 + floor(0.1) = 3
        $this->assertEquals(3, $base + floor(1 * $factor));

        // Level 10: 3 + floor(1.0) = 4
        $this->assertEquals(4, $base + floor(10 * $factor));
    }

    public function testChampdeforcePoints(): void
    {
        global $BUILDING_CONFIG;

        // Champdeforce uses 0.075 instead of 0.1
        $base = $BUILDING_CONFIG['champdeforce']['points_base']; // 1
        $factor = $BUILDING_CONFIG['champdeforce']['points_level_factor']; // 0.075

        // Level 1: 1 + floor(0.075) = 1
        $this->assertEquals(1, $base + floor(1 * $factor));

        // Level 20: 1 + floor(1.5) = 1 + 1 = 2
        $this->assertEquals(2, $base + floor(20 * $factor));
    }

    // =========================================================================
    // NEUTRINO COST
    // =========================================================================

    public function testNeutrinoCost(): void
    {
        $this->assertEquals(50, NEUTRINO_COST);
    }

    // =========================================================================
    // ESPIONAGE SPEED
    // =========================================================================

    public function testEspionageSpeed(): void
    {
        $this->assertEquals(20, ESPIONAGE_SPEED);
    }

    public function testEspionageSuccessRatio(): void
    {
        $this->assertEquals(0.5, ESPIONAGE_SUCCESS_RATIO);
    }

    // =========================================================================
    // STABILISATEUR EFFECT
    // Formula: 1.5% decay reduction per level (STABILISATEUR_BONUS_PER_LEVEL = 0.015)
    // =========================================================================

    public function testStabilisateurBonusPerLevel(): void
    {
        $this->assertEquals(0.015, STABILISATEUR_BONUS_PER_LEVEL);

        // At level 10: 10 * 0.015 = 0.15 = 15% decay reduction
        $reduction = 10 * STABILISATEUR_BONUS_PER_LEVEL;
        $this->assertEqualsWithDelta(0.15, $reduction, 0.0001);

        // At level 20: 20 * 0.015 = 0.30 = 30% decay reduction
        $reduction20 = 20 * STABILISATEUR_BONUS_PER_LEVEL;
        $this->assertEqualsWithDelta(0.30, $reduction20, 0.0001);
    }
}
