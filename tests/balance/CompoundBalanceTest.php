<?php
/**
 * Compound Synthesis Balance Tests.
 * Verifies compounds provide meaningful but not broken temporary buffs.
 */

require_once __DIR__ . '/bootstrap_balance.php';

use PHPUnit\Framework\TestCase;

class CompoundBalanceTest extends TestCase
{
    /**
     * Each compound has a unique effect type.
     */
    public function testUniqueEffects(): void
    {
        global $COMPOUNDS;
        $effects = [];
        foreach ($COMPOUNDS as $formula => $compound) {
            $this->assertNotContains($compound['effect'], $effects,
                "Duplicate effect: {$compound['effect']} for $formula");
            $effects[] = $compound['effect'];
        }
    }

    /**
     * Compound buffs are between 10-30% — meaningful but not game-breaking.
     */
    public function testBuffRange(): void
    {
        global $COMPOUNDS;
        foreach ($COMPOUNDS as $formula => $compound) {
            $this->assertGreaterThanOrEqual(0.10, $compound['effect_value'],
                "$formula buff too weak (< 10%)");
            $this->assertLessThanOrEqual(0.30, $compound['effect_value'],
                "$formula buff too strong (> 30%)");
        }
    }

    /**
     * Compound durations are tactical (1 hour), not permanent.
     */
    public function testDurationTactical(): void
    {
        global $COMPOUNDS;
        foreach ($COMPOUNDS as $formula => $compound) {
            $this->assertEquals(SECONDS_PER_HOUR, $compound['duration'],
                "$formula duration should be 1 hour");
        }
    }

    /**
     * Storage cap prevents hoarding.
     */
    public function testStorageCap(): void
    {
        $this->assertEquals(3, COMPOUND_MAX_STORED);
    }

    /**
     * All recipe ingredients are valid resource names.
     */
    public function testValidRecipes(): void
    {
        global $COMPOUNDS, $RESOURCE_NAMES;
        foreach ($COMPOUNDS as $formula => $compound) {
            foreach ($compound['recipe'] as $resource => $qty) {
                $this->assertContains($resource, $RESOURCE_NAMES,
                    "$formula uses invalid resource: $resource");
                $this->assertGreaterThan(0, $qty,
                    "$formula has zero-quantity ingredient: $resource");
            }
        }
    }

    /**
     * Compounds cover diverse strategic effects.
     */
    public function testStrategicDiversity(): void
    {
        global $COMPOUNDS;
        $effectTypes = array_column($COMPOUNDS, 'effect');

        // Should cover production, defense, attack, speed, and pillage
        $this->assertContains('production_boost', $effectTypes);
        $this->assertContains('defense_boost', $effectTypes);
        $this->assertContains('attack_boost', $effectTypes);
        $this->assertContains('speed_boost', $effectTypes);
        $this->assertContains('pillage_boost', $effectTypes);
    }
}
