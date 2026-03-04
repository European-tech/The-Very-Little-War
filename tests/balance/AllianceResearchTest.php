<?php
/**
 * Alliance Research Balance Tests.
 * Verifies the 5 research trees are balanced and achievable.
 */

require_once __DIR__ . '/bootstrap_balance.php';

use PHPUnit\Framework\TestCase;

class AllianceResearchTest extends TestCase
{
    /**
     * All 5 research trees exist with proper structure.
     */
    public function testResearchTreesComplete(): void
    {
        global $ALLIANCE_RESEARCH;
        $expected = ['catalyseur', 'fortification', 'reseau', 'radar', 'bouclier'];
        foreach ($expected as $tech) {
            $this->assertArrayHasKey($tech, $ALLIANCE_RESEARCH, "Missing research: $tech");
            $this->assertArrayHasKey('effect_per_level', $ALLIANCE_RESEARCH[$tech]);
            $this->assertArrayHasKey('cost_base', $ALLIANCE_RESEARCH[$tech]);
            $this->assertArrayHasKey('cost_factor', $ALLIANCE_RESEARCH[$tech]);
            $this->assertArrayHasKey('name', $ALLIANCE_RESEARCH[$tech]);
            $this->assertArrayHasKey('desc', $ALLIANCE_RESEARCH[$tech]);
        }
    }

    /**
     * Level 10 costs are achievable within a season.
     */
    public function testResearchCostsAchievable(): void
    {
        global $ALLIANCE_RESEARCH;
        foreach ($ALLIANCE_RESEARCH as $name => $tech) {
            $costL10 = round($tech['cost_base'] * pow($tech['cost_factor'], 11));
            $this->assertLessThan(5000000, $costL10,
                "$name level 10 costs $costL10 — too expensive for a season");
        }
    }

    /**
     * Max-level effects are meaningful but bounded.
     * Most techs should stay under 100%; reseau (trade) is an exception
     * since trade points are already capped by MARKET_POINTS_MAX.
     */
    public function testResearchEffectsBounded(): void
    {
        global $ALLIANCE_RESEARCH;
        foreach ($ALLIANCE_RESEARCH as $name => $tech) {
            $effectAtMax = $tech['effect_per_level'] * ALLIANCE_RESEARCH_MAX_LEVEL;

            // All research should have > 5% effect at max (worth investing in)
            $this->assertGreaterThan(0.05, $effectAtMax,
                "$name at max level gives " . ($effectAtMax * 100) . "% — too weak");

            // Combat/economy techs should stay under 100%
            if ($tech['effect_type'] !== 'trade_points') {
                $this->assertLessThan(1.0, $effectAtMax,
                    "$name at max level gives " . ($effectAtMax * 100) . "% — too powerful");
            } else {
                // Trade points bonus is bounded by MARKET_POINTS_MAX cap anyway
                $this->assertLessThan(2.0, $effectAtMax,
                    "$name at max level gives " . ($effectAtMax * 100) . "% — exceeds 200%");
            }
        }
    }

    /**
     * Research max level is reasonable.
     */
    public function testResearchMaxLevel(): void
    {
        $this->assertEquals(25, ALLIANCE_RESEARCH_MAX_LEVEL);
    }

    /**
     * Research covers diverse strategic areas.
     */
    public function testResearchDiversity(): void
    {
        global $ALLIANCE_RESEARCH;
        $effectTypes = array_column($ALLIANCE_RESEARCH, 'effect_type');

        $this->assertContains('formation_speed', $effectTypes);
        $this->assertContains('building_hp', $effectTypes);
        $this->assertContains('trade_points', $effectTypes);
        $this->assertContains('espionage_cost', $effectTypes);
        $this->assertContains('pillage_defense', $effectTypes);
    }
}
