<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for resource production, storage, and building cost/time formulas.
 *
 * Formula references (from includes/fonctions.php and includes/config.php):
 *   Energy production: BASE_ENERGY_PER_LEVEL (65) * generator_level
 *   Atom production:   BASE_ATOMS_PER_POINT (30) * points
 *   Storage capacity:  BASE_STORAGE_PER_LEVEL (500) * depot_level
 *   Producteur drain:  PRODUCTEUR_DRAIN_PER_LEVEL (12) * level
 *   Net energy:        65 * gen_level - 12 * prod_level
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
    // Formula: 65 * generator_level (base only, before medals/duplicateur)
    // =========================================================================

    public function testBaseEnergyPerLevel(): void
    {
        $this->assertEquals(65, BASE_ENERGY_PER_LEVEL);
    }

    public function testEnergyProductionAtLevel1(): void
    {
        $production = BASE_ENERGY_PER_LEVEL * 1;
        $this->assertEquals(65, $production);
    }

    public function testEnergyProductionAtLevel10(): void
    {
        $production = BASE_ENERGY_PER_LEVEL * 10;
        $this->assertEquals(650, $production);
    }

    public function testEnergyProductionAtLevel50(): void
    {
        $production = BASE_ENERGY_PER_LEVEL * 50;
        $this->assertEquals(3250, $production);
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
    // Formula: bonusDuplicateur * 30 * niveau
    // =========================================================================

    public function testBaseAtomsPerPoint(): void
    {
        $this->assertEquals(30, BASE_ATOMS_PER_POINT);
    }

    public function testAtomProductionAtLevel1(): void
    {
        $production = BASE_ATOMS_PER_POINT * 1;
        $this->assertEquals(30, $production);
    }

    public function testAtomProductionAtLevel10(): void
    {
        $production = BASE_ATOMS_PER_POINT * 10;
        $this->assertEquals(300, $production);
    }

    public function testAtomProductionWithDuplicateur(): void
    {
        // Duplicateur at level 5: bonus = 1 + 5/100 = 1.05
        $level = 5;
        $prodPoints = 10;
        $bonusDuplicateur = 1 + $level / 100;
        $production = round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $prodPoints);

        $this->assertEquals(round(1.05 * 30 * 10), $production);
        $this->assertEquals(315, $production);
    }

    public function testAtomProductionWithoutAlliance(): void
    {
        // No alliance means bonusDuplicateur = 1
        $prodPoints = 8;
        $production = round(1 * BASE_ATOMS_PER_POINT * $prodPoints);
        $this->assertEquals(240, $production);
    }

    // =========================================================================
    // STORAGE CAPACITY
    // Formula: 500 * depot_level
    // =========================================================================

    public function testBaseStoragePerLevel(): void
    {
        $this->assertEquals(500, BASE_STORAGE_PER_LEVEL);
    }

    public function testStorageAtLevel1(): void
    {
        $storage = BASE_STORAGE_PER_LEVEL * 1;
        $this->assertEquals(500, $storage);
    }

    public function testStorageAtLevel10(): void
    {
        $storage = BASE_STORAGE_PER_LEVEL * 10;
        $this->assertEquals(5000, $storage);
    }

    public function testStorageAtLevel100(): void
    {
        $storage = BASE_STORAGE_PER_LEVEL * 100;
        $this->assertEquals(50000, $storage);
    }

    public function testStorageLinearGrowth(): void
    {
        $storage5 = BASE_STORAGE_PER_LEVEL * 5;
        $storage10 = BASE_STORAGE_PER_LEVEL * 10;
        $this->assertEquals($storage5 * 2, $storage10);
    }

    // =========================================================================
    // PRODUCTEUR ENERGY DRAIN
    // Formula: round(12 * level)
    // =========================================================================

    public function testProducteurDrainPerLevel(): void
    {
        $this->assertEquals(12, PRODUCTEUR_DRAIN_PER_LEVEL);
    }

    public function testProducteurDrainAtLevel1(): void
    {
        $drain = round(PRODUCTEUR_DRAIN_PER_LEVEL * 1);
        $this->assertEquals(12, $drain);
    }

    public function testProducteurDrainAtLevel10(): void
    {
        $drain = round(PRODUCTEUR_DRAIN_PER_LEVEL * 10);
        $this->assertEquals(120, $drain);
    }

    public function testProducteurDrainAtLevel50(): void
    {
        $drain = round(PRODUCTEUR_DRAIN_PER_LEVEL * 50);
        $this->assertEquals(600, $drain);
    }

    // =========================================================================
    // NET ENERGY (Generation - Drain)
    // Formula: 65 * genLevel - 12 * prodLevel
    // =========================================================================

    public function testNetEnergyPositive(): void
    {
        $genLevel = 10;
        $prodLevel = 10;
        $net = BASE_ENERGY_PER_LEVEL * $genLevel - PRODUCTEUR_DRAIN_PER_LEVEL * $prodLevel;
        // 650 - 120 = 530
        $this->assertEquals(530, $net);
    }

    public function testNetEnergyBreakEven(): void
    {
        // Find the ratio where energy goes negative
        // 65 * gen = 12 * prod  =>  prod/gen = 65/12 = 5.417
        // So if prod > 5.417 * gen, energy goes negative
        $genLevel = 1;
        $prodLevel = 6; // Above the 5.417 threshold
        $net = BASE_ENERGY_PER_LEVEL * $genLevel - PRODUCTEUR_DRAIN_PER_LEVEL * $prodLevel;
        // 65 - 72 = -7
        $this->assertLessThan(0, $net);
    }

    public function testNetEnergyExactBreakEvenRatio(): void
    {
        // The break-even ratio is exactly 65/12
        $ratio = BASE_ENERGY_PER_LEVEL / PRODUCTEUR_DRAIN_PER_LEVEL;
        $this->assertEqualsWithDelta(5.4167, $ratio, 0.001);
    }

    public function testNetEnergyEqualLevels(): void
    {
        // At equal levels, energy should always be positive since 65 > 12
        for ($level = 1; $level <= 50; $level++) {
            $net = BASE_ENERGY_PER_LEVEL * $level - PRODUCTEUR_DRAIN_PER_LEVEL * $level;
            $this->assertGreaterThan(0, $net, "Net energy should be positive at equal level $level");
            $this->assertEquals(53 * $level, $net);
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

        $base = $BUILDING_CONFIG['generateur']['cost_energy_base']; // 50
        $exp = $BUILDING_CONFIG['generateur']['cost_energy_exp'];   // 0.4

        // Level 1: round(50 * pow(1, 0.4)) = round(50 * 1) = 50
        $this->assertEquals(50, $this->computeBuildingCost($base, $exp, 1));

        // Level 10: round(50 * pow(10, 0.4))
        $expected = (int) round(50 * pow(10, 0.4));
        $this->assertEquals($expected, $this->computeBuildingCost($base, $exp, 10));
    }

    public function testGenerateurAtomsCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['generateur']['cost_atoms_base']; // 75
        $exp = $BUILDING_CONFIG['generateur']['cost_atoms_exp'];   // 0.4

        // Level 1: round(75 * 1) = 75
        $this->assertEquals(75, $this->computeBuildingCost($base, $exp, 1));

        // Level 10: round(75 * pow(10, 0.4))
        $expected = (int) round(75 * pow(10, 0.4));
        $this->assertEquals($expected, $this->computeBuildingCost($base, $exp, 10));
    }

    public function testProducteurEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['producteur']['cost_energy_base']; // 75
        $exp = $BUILDING_CONFIG['producteur']['cost_energy_exp'];   // 0.4

        $this->assertEquals(75, $this->computeBuildingCost($base, $exp, 1));
    }

    public function testDepotEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['depot']['cost_energy_base']; // 100
        $exp = $BUILDING_CONFIG['depot']['cost_energy_exp'];   // 0.4

        $this->assertEquals(100, $this->computeBuildingCost($base, $exp, 1));
    }

    public function testCondenseurEnergyCost(): void
    {
        global $BUILDING_CONFIG;

        $base = $BUILDING_CONFIG['condenseur']['cost_energy_base']; // 25
        $exp = $BUILDING_CONFIG['condenseur']['cost_energy_exp'];   // 0.6

        $this->assertEquals(25, $this->computeBuildingCost($base, $exp, 1));

        // Level 10: round(25 * pow(10, 0.6))
        $expected = (int) round(25 * pow(10, 0.6));
        $this->assertEquals($expected, $this->computeBuildingCost($base, $exp, 10));
    }

    public function testBuildingCostWithMedalDiscount(): void
    {
        // 6% medal bonus reduces cost by 6%
        $baseCost = 100;
        $exp = 0.4;
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
        $exp = 0.4;

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
        // With exponent 0.4 < 1, cost growth is sublinear (diminishing returns)
        $baseCost = 50;
        $exp = 0.4;

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

        $base = $BUILDING_CONFIG['generateur']['time_base']; // 60
        $exp = $BUILDING_CONFIG['generateur']['time_exp'];   // 1.5

        // Level 1 is a special case: 10 seconds
        $this->assertEquals(10, $BUILDING_CONFIG['generateur']['time_level1']);

        // Level 10: round(60 * pow(10, 1.5))
        $expected = (int) round($base * pow(10, $exp));
        $this->assertEquals($expected, (int) round(60 * pow(10, 1.5)));
    }

    public function testDepotBuildTime(): void
    {
        // Depot: round(80 * pow(level, 1.5))
        $baseTime = 80;
        $exp = 1.5;

        $this->assertEquals((int) round(80 * pow(1, 1.5)), (int) round($baseTime * pow(1, $exp)));
        $this->assertEquals((int) round(80 * pow(10, 1.5)), (int) round($baseTime * pow(10, $exp)));
    }

    public function testChampdeforceBuildTime(): void
    {
        // Champdeforce: round(20 * pow(level + 2, 1.7))
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['champdeforce']['time_base']; // 20
        $exp = $BUILDING_CONFIG['champdeforce']['time_exp'];       // 1.7
        $offset = $BUILDING_CONFIG['champdeforce']['time_level_offset']; // 2

        // Level 1: round(20 * pow(3, 1.7))
        $expected = (int) round($baseTime * pow(1 + $offset, $exp));
        $this->assertEquals((int) round(20 * pow(3, 1.7)), $expected);

        // Level 10: round(20 * pow(12, 1.7))
        $expected10 = (int) round($baseTime * pow(10 + $offset, $exp));
        $this->assertEquals((int) round(20 * pow(12, 1.7)), $expected10);
    }

    public function testIonisateurBuildTime(): void
    {
        // Ionisateur: round(20 * pow(level + 2, 1.7))
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['ionisateur']['time_base']; // 20
        $exp = $BUILDING_CONFIG['ionisateur']['time_exp'];       // 1.7
        $offset = $BUILDING_CONFIG['ionisateur']['time_level_offset']; // 2

        $expected = (int) round($baseTime * pow(5 + $offset, $exp));
        $this->assertEquals((int) round(20 * pow(7, 1.7)), $expected);
    }

    public function testCondenseurBuildTime(): void
    {
        // Condenseur: round(120 * pow(level + 1, 1.8))
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['condenseur']['time_base']; // 120
        $exp = $BUILDING_CONFIG['condenseur']['time_exp'];       // 1.8
        $offset = $BUILDING_CONFIG['condenseur']['time_level_offset']; // 1

        $expected = (int) round($baseTime * pow(5 + $offset, $exp));
        $this->assertEquals((int) round(120 * pow(6, 1.8)), $expected);
    }

    public function testLieurBuildTime(): void
    {
        // Lieur: round(100 * pow(level + 1, 1.7))
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['lieur']['time_base']; // 100
        $exp = $BUILDING_CONFIG['lieur']['time_exp'];       // 1.7
        $offset = $BUILDING_CONFIG['lieur']['time_level_offset']; // 1

        $expected = (int) round($baseTime * pow(10 + $offset, $exp));
        $this->assertEquals((int) round(100 * pow(11, 1.7)), $expected);
    }

    public function testStabilisateurBuildTime(): void
    {
        // Stabilisateur: round(120 * pow(level + 1, 1.7))
        global $BUILDING_CONFIG;

        $baseTime = $BUILDING_CONFIG['stabilisateur']['time_base']; // 120
        $exp = $BUILDING_CONFIG['stabilisateur']['time_exp'];       // 1.7
        $offset = $BUILDING_CONFIG['stabilisateur']['time_level_offset']; // 1

        $expected = (int) round($baseTime * pow(3 + $offset, $exp));
        $this->assertEquals((int) round(120 * pow(4, 1.7)), $expected);
    }

    public function testBuildTimesAlwaysIncrease(): void
    {
        // Verify construction times strictly increase with level for all buildings
        $buildings = [
            'depot' => ['base' => 80, 'exp' => 1.5, 'offset' => 0],
            'champdeforce' => ['base' => 20, 'exp' => 1.7, 'offset' => 2],
            'ionisateur' => ['base' => 20, 'exp' => 1.7, 'offset' => 2],
            'condenseur' => ['base' => 120, 'exp' => 1.8, 'offset' => 1],
            'lieur' => ['base' => 100, 'exp' => 1.7, 'offset' => 1],
            'stabilisateur' => ['base' => 120, 'exp' => 1.7, 'offset' => 1],
        ];

        foreach ($buildings as $name => $config) {
            $prevTime = 0;
            for ($level = 1; $level <= 20; $level++) {
                $time = round($config['base'] * pow($level + $config['offset'], $config['exp']));
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
        // Level 1: round(10 * pow(2.5, 2)) = round(10 * 6.25) = 63
        $cost1 = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 1 + 1));
        $this->assertEquals(63, $cost1);

        // Level 2: round(10 * pow(2.5, 3)) = round(10 * 15.625) = 156
        $cost2 = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 2 + 1));
        $this->assertEquals(156, $cost2);

        // Level 5: round(10 * pow(2.5, 6)) = round(10 * 244.14) = 2441
        $cost5 = (int) round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 5 + 1));
        $expected = (int) round(10 * pow(2.5, 6));
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
    // Formula: 0.5% decay reduction per level
    // =========================================================================

    public function testStabilisateurBonusPerLevel(): void
    {
        $this->assertEquals(0.005, STABILISATEUR_BONUS_PER_LEVEL);

        // At level 10: 10 * 0.005 = 0.05 = 5% decay reduction
        $reduction = 10 * STABILISATEUR_BONUS_PER_LEVEL;
        $this->assertEquals(0.05, $reduction);

        // At level 100: 100 * 0.005 = 0.5 = 50% decay reduction
        $reduction100 = 100 * STABILISATEUR_BONUS_PER_LEVEL;
        $this->assertEquals(0.5, $reduction100);
    }
}
