<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/config.php';

class CompoundsTest extends TestCase
{
    public function testCompoundConstants()
    {
        $this->assertGreaterThan(0, COMPOUND_ATOM_MULTIPLIER);
        $this->assertGreaterThan(0, COMPOUND_MAX_STORED);
        $this->assertEquals(100, COMPOUND_ATOM_MULTIPLIER);
        $this->assertEquals(3, COMPOUND_MAX_STORED);
    }

    public function testAllCompoundsDefined()
    {
        global $COMPOUNDS;
        $this->assertCount(5, $COMPOUNDS);
        $this->assertArrayHasKey('H2O', $COMPOUNDS);
        $this->assertArrayHasKey('NaCl', $COMPOUNDS);
        $this->assertArrayHasKey('CO2', $COMPOUNDS);
        $this->assertArrayHasKey('NH3', $COMPOUNDS);
        $this->assertArrayHasKey('H2SO4', $COMPOUNDS);
    }

    public function testCompoundStructure()
    {
        global $COMPOUNDS;
        $requiredKeys = ['name', 'recipe', 'effect', 'effect_value', 'duration', 'description'];
        foreach ($COMPOUNDS as $key => $compound) {
            foreach ($requiredKeys as $required) {
                $this->assertArrayHasKey($required, $compound, "Compound $key missing '$required'");
            }
        }
    }

    public function testRecipesUseValidResources()
    {
        global $COMPOUNDS, $nomsRes;
        $validResources = array_values($nomsRes);
        foreach ($COMPOUNDS as $key => $compound) {
            foreach ($compound['recipe'] as $resource => $qty) {
                $this->assertContains($resource, $validResources, "Compound $key uses invalid resource '$resource'");
                $this->assertGreaterThan(0, $qty, "Compound $key has zero/negative qty for $resource");
            }
        }
    }

    public function testEffectTypes()
    {
        global $COMPOUNDS;
        $validEffects = ['production_boost', 'defense_boost', 'attack_boost', 'speed_boost', 'pillage_boost'];
        foreach ($COMPOUNDS as $key => $compound) {
            $this->assertContains($compound['effect'], $validEffects, "Compound $key has invalid effect '{$compound['effect']}'");
        }
    }

    public function testEffectValuesReasonable()
    {
        global $COMPOUNDS;
        foreach ($COMPOUNDS as $key => $compound) {
            $this->assertGreaterThan(0, $compound['effect_value'], "Compound $key has non-positive effect value");
            $this->assertLessThanOrEqual(0.50, $compound['effect_value'], "Compound $key effect value > 50% seems too high");
        }
    }

    public function testDurationsReasonable()
    {
        global $COMPOUNDS;
        foreach ($COMPOUNDS as $key => $compound) {
            $this->assertGreaterThanOrEqual(SECONDS_PER_HOUR / 2, $compound['duration'], "Compound $key duration too short");
            $this->assertLessThanOrEqual(SECONDS_PER_DAY, $compound['duration'], "Compound $key duration too long");
        }
    }

    public function testUniqueEffectPerCompound()
    {
        global $COMPOUNDS;
        $effects = [];
        foreach ($COMPOUNDS as $key => $compound) {
            $effects[] = $compound['effect'];
        }
        // Each compound should have a unique effect
        $this->assertEquals(count($effects), count(array_unique($effects)), "Multiple compounds share the same effect type");
    }

    public function testH2ORecipe()
    {
        global $COMPOUNDS;
        $h2o = $COMPOUNDS['H2O'];
        $this->assertEquals('Eau', $h2o['name']);
        $this->assertEquals(['hydrogene' => 2, 'oxygene' => 1], $h2o['recipe']);
        $this->assertEquals('production_boost', $h2o['effect']);
        $this->assertEquals(0.10, $h2o['effect_value']);
    }

    public function testNaClRecipe()
    {
        global $COMPOUNDS;
        $nacl = $COMPOUNDS['NaCl'];
        $this->assertEquals('Sel', $nacl['name']);
        $this->assertEquals(['chlore' => 1, 'soufre' => 1], $nacl['recipe']);
        $this->assertEquals('defense_boost', $nacl['effect']);
        $this->assertEquals(0.15, $nacl['effect_value']);
    }

    public function testH2SO4RecipeIsExpensive()
    {
        global $COMPOUNDS;
        $h2so4 = $COMPOUNDS['H2SO4'];
        // H2SO4 = 2H + 1S + 4O = 7 recipe units → 700 atoms total
        $totalUnits = array_sum($h2so4['recipe']);
        $this->assertEquals(7, $totalUnits);
        $this->assertEquals(700, $totalUnits * COMPOUND_ATOM_MULTIPLIER);
        // Should have the highest effect value as compensation
        $this->assertEquals(0.25, $h2so4['effect_value']);
    }

    public function testCompoundCostsScale()
    {
        global $COMPOUNDS;
        // Verify total atom costs for each compound
        $costs = [];
        foreach ($COMPOUNDS as $key => $compound) {
            $costs[$key] = array_sum($compound['recipe']) * COMPOUND_ATOM_MULTIPLIER;
        }
        // H2O = 300 (2+1), NaCl = 200 (1+1), CO2 = 300 (1+2), NH3 = 400 (1+3), H2SO4 = 700 (2+1+4)
        $this->assertEquals(300, $costs['H2O']);
        $this->assertEquals(200, $costs['NaCl']);
        $this->assertEquals(300, $costs['CO2']);
        $this->assertEquals(400, $costs['NH3']);
        $this->assertEquals(700, $costs['H2SO4']);
    }
}
