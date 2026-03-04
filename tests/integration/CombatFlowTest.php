<?php
/**
 * Combat Flow Integration Tests.
 * Tests combat formulas with real DB-backed player data.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class CombatFlowTest extends IntegrationTestCase
{
    public function testMoleculeCreationPersists(): void
    {
        $this->createTestPlayer('mol_test');
        $id = $this->createMoleculeClass('mol_test', 1, [
            'carbone' => 50, 'oxygene' => 80, 'brome' => 30
        ], 200);

        $this->assertGreaterThan(0, $id);

        $mol = dbFetchOne(self::$db, 'SELECT * FROM molecules WHERE id = ?', 'i', $id);
        $this->assertEquals('mol_test', $mol['proprietaire']);
        $this->assertEquals(50, $mol['carbone']);
        $this->assertEquals(80, $mol['oxygene']);
        $this->assertEquals(200, $mol['nombre']);
    }

    public function testCombatFormulasWithDbData(): void
    {
        $this->createTestPlayer('combat_test');
        $this->setBuildingLevel('combat_test', 'condenseur', 8);
        $this->createMoleculeClass('combat_test', 1, [
            'oxygene' => 80, 'hydrogene' => 20, 'carbone' => 10, 'brome' => 30
        ]);

        // Fetch molecule data
        $mol = dbFetchOne(self::$db, 'SELECT * FROM molecules WHERE proprietaire = ? AND numeroclasse = ?', 'si', 'combat_test', 1);
        $cond = dbFetchOne(self::$db, 'SELECT condenseur FROM constructions WHERE login = ?', 's', 'combat_test');

        // Compute stats
        $atk = attaque($mol['oxygene'], $mol['hydrogene'], $cond['condenseur']);
        $def = defense($mol['carbone'], $mol['brome'], $cond['condenseur']);
        $hp = pointsDeVieMolecule($mol['brome'], $mol['carbone'], $cond['condenseur']);

        $this->assertGreaterThan(200, $atk, "80O molecule should have strong attack");
        $this->assertGreaterThan(0, $def);
        $this->assertGreaterThanOrEqual(MOLECULE_MIN_HP, $hp);
    }

    public function testAttackEnergyCostFormula(): void
    {
        $nbAtomes = 100;
        $nbMolecules = 200;
        $terreurBonus = 10;

        $cost = ATTACK_ENERGY_COST_FACTOR * (1 - $terreurBonus / 100) * $nbAtomes * $nbMolecules;
        $this->assertEquals(2700, $cost);
    }

    public function testBuildingHPFormulas(): void
    {
        // Standard building HP
        $hp1 = round(BUILDING_HP_BASE * pow(1, BUILDING_HP_POLY_EXP));
        $this->assertEquals(50, $hp1);

        $hp10 = round(BUILDING_HP_BASE * pow(10, BUILDING_HP_POLY_EXP));
        $this->assertGreaterThan(10000, $hp10);

        // Champdeforce HP (tankier)
        $cfHp10 = round(FORCEFIELD_HP_BASE * pow(10, BUILDING_HP_POLY_EXP));
        $this->assertGreaterThan($hp10, $cfHp10);
    }

    public function testCombatPointsCapping(): void
    {
        // 10000 casualties → capped at COMBAT_POINTS_MAX_PER_BATTLE
        $rawPoints = floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt(10000));
        $cappedPoints = min(COMBAT_POINTS_MAX_PER_BATTLE, $rawPoints);
        $this->assertEquals(COMBAT_POINTS_MAX_PER_BATTLE, $cappedPoints);
    }

    public function testMultipleMoleculeClasses(): void
    {
        $this->createTestPlayer('multi_mol');
        $this->createMoleculeClass('multi_mol', 1, ['carbone' => 80, 'brome' => 60], 100);
        $this->createMoleculeClass('multi_mol', 2, ['oxygene' => 80, 'hydrogene' => 40], 150);

        $mols = dbFetchAll(self::$db, 'SELECT * FROM molecules WHERE proprietaire = ? ORDER BY numeroclasse', 's', 'multi_mol');
        $this->assertCount(2, $mols);
        $this->assertEquals(1, $mols[0]['numeroclasse']);
        $this->assertEquals(2, $mols[1]['numeroclasse']);
    }
}
