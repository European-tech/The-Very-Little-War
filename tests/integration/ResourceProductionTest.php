<?php
/**
 * Resource Production Integration Tests.
 * Verifies formulas match database values when using real DB helpers.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class ResourceProductionTest extends IntegrationTestCase
{
    public function testEnergyProductionFormula(): void
    {
        $player = $this->createTestPlayer('energy_test');
        $this->setBuildingLevel('energy_test', 'generateur', 10);

        $row = dbFetchOne(self::$db, 'SELECT generateur FROM constructions WHERE login = ?', 's', 'energy_test');
        $expected = BASE_ENERGY_PER_LEVEL * $row['generateur'];
        $this->assertEquals(750, $expected);
    }

    public function testAtomProductionBaseline(): void
    {
        $player = $this->createTestPlayer('atom_test');
        $this->setBuildingLevel('atom_test', 'producteur', 8);

        $row = dbFetchOne(self::$db, 'SELECT producteur FROM constructions WHERE login = ?', 's', 'atom_test');
        $this->assertEquals(480, BASE_ATOMS_PER_POINT * $row['producteur']);
    }

    public function testStorageLimitCalculation(): void
    {
        $this->assertEquals(1150, placeDepot(1));
        $this->assertGreaterThan(5000, placeDepot(15));
        $this->assertLessThan(15000, placeDepot(15));
    }

    public function testEnergyDrainFunction(): void
    {
        // drainageProducteur uses real formula
        $drain10 = drainageProducteur(10);
        $this->assertGreaterThan(25, $drain10);
        $this->assertLessThan(45, $drain10);
    }

    public function testVaultProtectionCap(): void
    {
        $this->assertEquals(
            VAULT_MAX_PROTECTION_PCT,
            capaciteCoffreFort(30, 10) / placeDepot(10)
        );
    }

    public function testPlayerCreatedWithDefaultResources(): void
    {
        $player = $this->createTestPlayer('res_test');
        $res = dbFetchOne(self::$db, 'SELECT energie, carbone FROM ressources WHERE login = ?', 's', 'res_test');
        $this->assertNotEmpty($res, "Ressources record should exist for new player");
        $this->assertGreaterThan(0, $res['energie'], "Default energy should be > 0");
    }

    public function testBuildingLevelsUpdatable(): void
    {
        $this->createTestPlayer('build_test');
        $this->setBuildingLevel('build_test', 'generateur', 20);

        $row = dbFetchOne(self::$db, 'SELECT generateur FROM constructions WHERE login = ?', 's', 'build_test');
        $this->assertEquals(20, $row['generateur']);
    }
}
