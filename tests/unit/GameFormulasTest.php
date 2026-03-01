<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for game balance formulas and calculations.
 * These tests verify the pure computation functions used in combat,
 * resource generation, and other game mechanics.
 */
class GameFormulasTest extends TestCase
{
    /**
     * Placeholder test - to be expanded as game formulas are extracted
     * into testable pure functions.
     */
    public function testGameConstantsLoaded()
    {
        global $nomsRes, $nbRes, $nbClasses;

        $this->assertCount(8, $nomsRes);
        $this->assertEquals(7, $nbRes); // sizeof(8 elements) - 1
        $this->assertEquals(4, $nbClasses);
    }

    public function testResourceNamesMatchLetters()
    {
        global $nomsRes, $lettre;

        $this->assertCount(count($nomsRes), $lettre);
        $this->assertEquals('C', $lettre[0]);  // carbone
        $this->assertEquals('N', $lettre[1]);  // azote
        $this->assertEquals('H', $lettre[2]);  // hydrogene
        $this->assertEquals('O', $lettre[3]);  // oxygene
        $this->assertEquals('Cl', $lettre[4]); // chlore
        $this->assertEquals('S', $lettre[5]);  // soufre
        $this->assertEquals('Br', $lettre[6]); // brome
        $this->assertEquals('I', $lettre[7]);  // iode
    }

    public function testMedalTiersConsistency()
    {
        global $paliersMedailles, $imagesMedailles, $bonusMedailles;

        $this->assertCount(8, $paliersMedailles);
        $this->assertCount(8, $imagesMedailles);
        $this->assertCount(8, $bonusMedailles);
    }

    public function testMedalBonusesAreIncreasing()
    {
        global $bonusMedailles;

        for ($i = 1; $i < count($bonusMedailles); $i++) {
            $this->assertGreaterThan(
                $bonusMedailles[$i - 1],
                $bonusMedailles[$i],
                "Medal bonus at tier $i should be greater than tier " . ($i - 1)
            );
        }
    }

    public function testVictoryPointsConstant()
    {
        global $nbPointsVictoire;

        $this->assertEquals(1000, $nbPointsVictoire);
    }
}
