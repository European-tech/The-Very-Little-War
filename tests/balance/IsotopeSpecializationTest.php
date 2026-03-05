<?php
/**
 * Isotope & Specialization Balance Tests.
 * Verifies isotope variants and specializations offer genuine choices.
 */

require_once __DIR__ . '/bootstrap_balance.php';

use PHPUnit\Framework\TestCase;

class IsotopeSpecializationTest extends TestCase
{
    /**
     * Isotope choices are genuine trade-offs (not strictly better/worse).
     */
    public function testIsotopesAreTradeoffs(): void
    {
        // Stable: gains HP, loses attack — net positive for tanks
        $this->assertLessThan(0, ISOTOPE_STABLE_ATTACK_MOD, "Stable should lose attack");
        $this->assertGreaterThan(0, ISOTOPE_STABLE_HP_MOD, "Stable should gain HP");
        $this->assertLessThan(0, ISOTOPE_STABLE_DECAY_MOD, "Stable should decay slower");

        // Reactif: gains attack, loses HP — glass cannon
        $this->assertGreaterThan(0, ISOTOPE_REACTIF_ATTACK_MOD, "Reactif should gain attack");
        $this->assertLessThan(0, ISOTOPE_REACTIF_HP_MOD, "Reactif should lose HP");
        $this->assertGreaterThan(0, ISOTOPE_REACTIF_DECAY_MOD, "Reactif should decay faster");

        // Catalytique: sacrifices self for team
        $this->assertLessThan(0, ISOTOPE_CATALYTIQUE_ATTACK_MOD, "Catalytique loses attack");
        $this->assertLessThan(0, ISOTOPE_CATALYTIQUE_HP_MOD, "Catalytique loses HP");
        $this->assertGreaterThan(0, ISOTOPE_CATALYTIQUE_ALLY_BONUS, "Catalytique buffs allies");
    }

    /**
     * Reactif decay penalty is significant but not fatal.
     */
    public function testReactifDecayReasonable(): void
    {
        $this->assertEqualsWithDelta(0.20, ISOTOPE_REACTIF_DECAY_MOD, 0.01);
        $this->assertLessThan(0.50, ISOTOPE_REACTIF_DECAY_MOD,
            "Reactif decay should be < 50% faster (was nerfed from 50% to 20%)");
    }

    public function testStableIsotopeHPBuffSufficient(): void
    {
        $this->assertGreaterThanOrEqual(0.40, ISOTOPE_STABLE_HP_MOD,
            'Stable isotope HP bonus should be >= 40%');
    }

    /**
     * Specializations are genuine either-or choices with trade-offs.
     */
    public function testSpecializationChoices(): void
    {
        global $SPECIALIZATIONS;
        $this->assertCount(3, $SPECIALIZATIONS, "3 specialization categories");

        foreach ($SPECIALIZATIONS as $category => $spec) {
            $this->assertCount(2, $spec['options'], "$category: exactly 2 options");
            $this->assertArrayHasKey('column', $spec);
            $this->assertArrayHasKey('unlock_building', $spec);
            $this->assertArrayHasKey('unlock_level', $spec);

            foreach ($spec['options'] as $optId => $option) {
                $positives = 0;
                $negatives = 0;
                foreach ($option['modifiers'] as $stat => $value) {
                    if ($value > 0) $positives++;
                    if ($value < 0) $negatives++;
                }
                $this->assertGreaterThan(0, $positives,
                    "$category option $optId has no positives");
                $this->assertGreaterThan(0, $negatives,
                    "$category option $optId has no negatives — not a real trade-off");
            }
        }
    }

    /**
     * Specialization unlock requirements are mid-game milestones.
     */
    public function testSpecializationUnlockTiming(): void
    {
        global $SPECIALIZATIONS;
        foreach ($SPECIALIZATIONS as $category => $spec) {
            $this->assertGreaterThanOrEqual(15, $spec['unlock_level'],
                "$category unlocks too early (< level 15)");
            $this->assertLessThanOrEqual(25, $spec['unlock_level'],
                "$category unlocks too late (> level 25)");
        }
    }

    /**
     * All 4 isotopes are properly defined.
     */
    public function testAllIsotopesDefined(): void
    {
        global $ISOTOPES;
        $this->assertArrayHasKey(ISOTOPE_NORMAL, $ISOTOPES);
        $this->assertArrayHasKey(ISOTOPE_STABLE, $ISOTOPES);
        $this->assertArrayHasKey(ISOTOPE_REACTIF, $ISOTOPES);
        $this->assertArrayHasKey(ISOTOPE_CATALYTIQUE, $ISOTOPES);

        foreach ($ISOTOPES as $id => $iso) {
            $this->assertArrayHasKey('name', $iso, "Isotope $id missing name");
            $this->assertArrayHasKey('desc', $iso, "Isotope $id missing desc");
        }
    }

    /**
     * Specialization categories cover distinct game dimensions.
     */
    public function testSpecializationCoverage(): void
    {
        global $SPECIALIZATIONS;
        $this->assertArrayHasKey('combat', $SPECIALIZATIONS);
        $this->assertArrayHasKey('economy', $SPECIALIZATIONS);
        $this->assertArrayHasKey('research', $SPECIALIZATIONS);
    }
}
