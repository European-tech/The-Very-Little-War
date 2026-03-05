<?php
/**
 * Economy Progression Verification.
 *
 * Simulates resource accumulation over a 31-day season.
 * Verifies early game accessibility, mid-game progression,
 * late-game feasibility, and catch-up mechanics.
 */

require_once __DIR__ . '/bootstrap_balance.php';

use PHPUnit\Framework\TestCase;

class EconomyProgressionTest extends TestCase
{
    /**
     * New player can afford first building upgrades quickly.
     */
    public function testEarlyGameAccessibility(): void
    {
        global $BUILDING_CONFIG;
        $gen = $BUILDING_CONFIG['generateur'];

        // Level 1: special fast time (10 seconds)
        $this->assertEquals(10, $gen['time_level1']);

        // Level 2 cost: affordable
        $costL2 = round($gen['cost_energy_base'] * pow($gen['cost_growth_base'], 2));
        $this->assertLessThan(100, $costL2, "Gen level 2 should cost < 100 energy");

        // Level 2 time: < 5 minutes
        $timeL2 = round($gen['time_base'] * pow($gen['time_growth_base'], 2));
        $this->assertLessThan(300, $timeL2, "Gen level 2 should take < 5 minutes");
    }

    /**
     * Mid-game building levels achievable within 2 weeks.
     */
    public function testMidGameProgression(): void
    {
        global $BUILDING_CONFIG;
        $gen = $BUILDING_CONFIG['generateur'];

        // Level 15 time: < 1 hour
        $timeL15 = round($gen['time_base'] * pow($gen['time_growth_base'], 15));
        $this->assertLessThan(SECONDS_PER_HOUR, $timeL15, "Gen level 15 should take < 1 hour");

        // Level 15 hourly energy: 1125
        $hourlyEnergy = BASE_ENERGY_PER_LEVEL * 15;
        $this->assertEquals(1125, $hourlyEnergy);
    }

    /**
     * Late-game costs are high but achievable.
     */
    public function testLateGameFeasibility(): void
    {
        global $BUILDING_CONFIG;
        $gen = $BUILDING_CONFIG['generateur'];

        // Level 50 cost
        $costL50 = round($gen['cost_energy_base'] * pow($gen['cost_growth_base'], 50));
        $this->assertLessThan(200000, $costL50, "Gen level 50 should cost < 200k");

        // Can afford within 48 hours of gen 50 production
        $hoursToAfford = $costL50 / (BASE_ENERGY_PER_LEVEL * 50);
        $this->assertLessThan(48, $hoursToAfford,
            "Should afford gen 50 within 48h of its own production");
    }

    /**
     * Duplicateur alliance research has meaningful but achievable impact.
     */
    public function testDuplicateurScaling(): void
    {
        // Level 10 gives 10% bonus
        $bonusL10 = 10 * DUPLICATEUR_BONUS_PER_LEVEL;
        $this->assertEqualsWithDelta(0.10, $bonusL10, 0.001);

        // Level 10 cost is achievable
        $costL10 = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, 11));
        $this->assertLessThan(100000, $costL10, "Duplicateur level 10 should be < 100k");
    }

    /**
     * Vault protects enough resources to prevent total wipe-out from raids.
     */
    public function testVaultProtection(): void
    {
        // Level 10: 30% protection (P1-D4-030: 3% per level)
        $prot10 = min(VAULT_MAX_PROTECTION_PCT, 10 * VAULT_PCT_PER_LEVEL);
        $this->assertEqualsWithDelta(0.30, $prot10, 0.001);

        // Max vault (level 17+): 50% cap
        $protMax = min(VAULT_MAX_PROTECTION_PCT, 25 * VAULT_PCT_PER_LEVEL);
        $this->assertEqualsWithDelta(0.50, $protMax, 0.001);

        // At least 50% protected at max
        $this->assertGreaterThanOrEqual(0.50, VAULT_MAX_PROTECTION_PCT);
    }

    public function testVaultProtectionPerLevelReasonable(): void
    {
        $this->assertGreaterThanOrEqual(0.03, VAULT_PCT_PER_LEVEL,
            'Vault should give >= 3% per level');
    }

    /**
     * Compound synthesis costs are meaningful but affordable.
     */
    public function testCompoundCosts(): void
    {
        global $COMPOUNDS;
        foreach ($COMPOUNDS as $formula => $compound) {
            $totalCost = 0;
            foreach ($compound['recipe'] as $resource => $quantity) {
                $totalCost += $quantity * COMPOUND_ATOM_MULTIPLIER;
            }
            $this->assertGreaterThan(100, $totalCost, "$formula should cost > 100 atoms");
            $this->assertLessThan(2000, $totalCost, "$formula should cost < 2000 atoms");
        }
    }

    /**
     * Beginner protection lasts long enough to establish a base.
     */
    public function testBeginnerProtection(): void
    {
        $this->assertEquals(5 * SECONDS_PER_DAY, BEGINNER_PROTECTION_SECONDS);

        // In 5 days at gen level 5: meaningful accumulation
        $energyIn5Days = BASE_ENERGY_PER_LEVEL * 5 * 120; // 120 hours
        $this->assertGreaterThan(10000, $energyIn5Days,
            "5 days of protection should yield > 10k energy at gen 5");
    }

    /**
     * Energy production outpaces energy drain in realistic play scenarios.
     * Players typically build generateur ahead of producteur. The required gap
     * grows at high levels (exponential drain vs linear production).
     */
    public function testEnergyNetPositiveRealisticPlay(): void
    {
        // Early game (prod 1-15): gen 3 ahead is sufficient
        for ($prodLevel = 1; $prodLevel <= 15; $prodLevel++) {
            $genLevel = $prodLevel + 3;
            $energyProduction = BASE_ENERGY_PER_LEVEL * $genLevel;
            $totalDrain = drainageProducteur($prodLevel) * 8;
            $this->assertGreaterThan($totalDrain, $energyProduction,
                "Gen $genLevel / Prod $prodLevel: energy ($energyProduction) must exceed drain ($totalDrain)");
        }

        // Late game (prod 16-25): gen 5 ahead needed
        for ($prodLevel = 16; $prodLevel <= 25; $prodLevel++) {
            $genLevel = $prodLevel + 5;
            $energyProduction = BASE_ENERGY_PER_LEVEL * $genLevel;
            $totalDrain = drainageProducteur($prodLevel) * 8;
            $this->assertGreaterThan($totalDrain, $energyProduction,
                "Gen $genLevel / Prod $prodLevel: energy ($energyProduction) must exceed drain ($totalDrain)");
        }
    }

    /**
     * At equal building levels, energy becomes tight around level 20+.
     * This is intentional — it creates strategic tension and prevents
     * infinite growth without investment in energy infrastructure.
     */
    public function testEnergyTensionAtHighLevels(): void
    {
        // At equal levels, should be sustainable up to level 20
        for ($level = 1; $level <= 20; $level++) {
            $energyProduction = BASE_ENERGY_PER_LEVEL * $level;
            $totalDrain = drainageProducteur($level) * 8;
            $this->assertGreaterThan($totalDrain, $energyProduction,
                "At equal level $level: should be net positive");
        }

        // At level 30 equal, drain exceeds production (intentional)
        $energy30 = BASE_ENERGY_PER_LEVEL * 30;
        $drain30 = drainageProducteur(30) * 8;
        $this->assertGreaterThan($energy30, $drain30,
            "At equal level 30, drain should exceed production (forces gen investment)");
    }

    /**
     * Storage capacity grows fast enough to hold meaningful reserves.
     */
    public function testStorageGrowth(): void
    {
        // Level 1: 1150
        $this->assertEquals(1150, placeDepot(1));

        // Level 20: should hold > 10k
        $this->assertGreaterThan(10000, placeDepot(20));

        // Storage should always grow with level
        for ($level = 2; $level <= 50; $level++) {
            $this->assertGreaterThan(placeDepot($level - 1), placeDepot($level),
                "Storage at level $level should exceed level " . ($level - 1));
        }
    }

    /**
     * Molecule class unlock costs follow predictable escalation.
     */
    public function testClassUnlockCosts(): void
    {
        $costs = [];
        for ($i = 0; $i < MAX_MOLECULE_CLASSES; $i++) {
            $costs[] = coutClasse($i);
        }
        // Each class more expensive than previous
        for ($i = 1; $i < count($costs); $i++) {
            $this->assertGreaterThan($costs[$i-1], $costs[$i],
                "Class $i should cost more than class " . ($i-1));
        }

        // First class should be cheap
        $this->assertLessThan(10, $costs[0], "First class should be very cheap");

        // Fourth class should be expensive but achievable
        $this->assertLessThan(1000, $costs[3], "Fourth class should be < 1000");
    }
}
