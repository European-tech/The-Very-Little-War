<?php
/**
 * Building Construction Integration Tests.
 * Verifies building upgrades, costs, HP, and time formulas with real DB data.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class BuildingConstructionTest extends IntegrationTestCase
{
    public function testBuildingDefaultLevels(): void
    {
        $this->createTestPlayer('build_def');
        $row = dbFetchOne(self::$db, 'SELECT * FROM constructions WHERE login = ?', 's', 'build_def');

        // Default levels from schema: generateur=1, producteur=1, depot=1, rest=0
        $this->assertEquals(1, $row['generateur']);
        $this->assertEquals(1, $row['producteur']);
        $this->assertEquals(1, $row['depot']);
        $this->assertEquals(0, $row['champdeforce']);
        $this->assertEquals(0, $row['condenseur']);
    }

    public function testAllBuildingsUpgradable(): void
    {
        $this->createTestPlayer('upgrade_test');
        $buildings = ['generateur', 'producteur', 'depot', 'champdeforce',
                      'ionisateur', 'condenseur', 'lieur', 'stabilisateur'];

        foreach ($buildings as $b) {
            $this->setBuildingLevel('upgrade_test', $b, 15);
        }

        $row = dbFetchOne(self::$db, 'SELECT * FROM constructions WHERE login = ?', 's', 'upgrade_test');
        foreach ($buildings as $b) {
            $this->assertEquals(15, $row[$b], "Building $b should be level 15");
        }
    }

    public function testBuildingHPStoredCorrectly(): void
    {
        $this->createTestPlayer('hp_test');
        $hpValue = round(BUILDING_HP_BASE * pow(10, BUILDING_HP_POLY_EXP));

        dbExecute(self::$db,
            'UPDATE constructions SET vieGenerateur = ? WHERE login = ?',
            'is', $hpValue, 'hp_test'
        );

        $row = dbFetchOne(self::$db, 'SELECT vieGenerateur FROM constructions WHERE login = ?', 's', 'hp_test');
        $this->assertEquals($hpValue, $row['vieGenerateur']);
    }

    public function testBuildingCostGrowth(): void
    {
        global $BUILDING_CONFIG;

        // Verify costs grow with level for each building
        foreach (['generateur', 'producteur', 'depot'] as $b) {
            $cfg = $BUILDING_CONFIG[$b];
            $base = $cfg['cost_energy_base'] ?? 0;
            if ($base <= 0) continue;

            $cost5 = round($base * pow(5, $cfg['cost_growth_base']));
            $cost10 = round($base * pow(10, $cfg['cost_growth_base']));
            $this->assertGreaterThan($cost5, $cost10,
                "Building $b cost at level 10 should exceed level 5");
        }
    }

    public function testBuildingTimeProgression(): void
    {
        global $BUILDING_CONFIG;

        $cfg = $BUILDING_CONFIG['generateur'];
        $time1 = $cfg['time_level1']; // Level 1 special case
        $time10 = round($cfg['time_base'] * pow(10, $cfg['time_growth_base']));

        $this->assertEquals(10, $time1, "Level 1 should be 10 seconds");
        $this->assertGreaterThan($time1, $time10, "Level 10 should take longer");
    }

    public function testForcefieldTankierThanStandard(): void
    {
        $level = 10;
        $standardHP = round(BUILDING_HP_BASE * pow($level, BUILDING_HP_POLY_EXP));
        $forcefieldHP = round(FORCEFIELD_HP_BASE * pow($level, BUILDING_HP_POLY_EXP));

        $this->assertGreaterThan($standardHP, $forcefieldHP,
            "Champdeforce should have more HP than standard buildings");
    }

    public function testProducteurPointsPerLevel(): void
    {
        global $BUILDING_CONFIG;

        // Producteur gives 8 points to distribute (one per atom type)
        $this->assertEquals(8, $BUILDING_CONFIG['producteur']['points_per_level']);
    }

    public function testHighLevelBuildingFeasible(): void
    {
        $this->createTestPlayer('high_level');
        $this->setBuildingLevel('high_level', 'generateur', 30);
        $this->setBuildingLevel('high_level', 'producteur', 25);
        $this->setBuildingLevel('high_level', 'depot', 20);

        $row = dbFetchOne(self::$db, 'SELECT generateur, producteur, depot FROM constructions WHERE login = ?', 's', 'high_level');
        $this->assertEquals(30, $row['generateur']);
        $this->assertEquals(25, $row['producteur']);
        $this->assertEquals(20, $row['depot']);
    }
}
