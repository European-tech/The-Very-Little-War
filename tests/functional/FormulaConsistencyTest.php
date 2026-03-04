<?php
/**
 * Formula Consistency Smoke Tests.
 * Verifies all game formulas produce sane output across input ranges.
 * Catches regressions if formula changes break game balance.
 */

class FormulaConsistencyTest extends PHPUnit\Framework\TestCase
{
    // --- Attack Formula ---
    public function testAttackMonotonicallyIncreases(): void
    {
        $prev = 0;
        for ($o = 10; $o <= 100; $o += 10) {
            $val = attaque($o, 20, 5);
            $this->assertGreaterThan($prev, $val, "Attack should increase with O=$o");
            $prev = $val;
        }
    }

    public function testAttackWithZeroInputs(): void
    {
        $val = attaque(0, 0, 0);
        $this->assertGreaterThanOrEqual(0, $val, "Attack with 0 inputs should not be negative");
    }

    // --- Defense Formula ---
    public function testDefenseMonotonicallyIncreases(): void
    {
        $prev = 0;
        for ($c = 10; $c <= 100; $c += 10) {
            $val = defense($c, 30, 5);
            $this->assertGreaterThan($prev, $val, "Defense should increase with C=$c");
            $prev = $val;
        }
    }

    public function testDefenseWithZeroInputs(): void
    {
        $val = defense(0, 0, 0);
        $this->assertGreaterThanOrEqual(0, $val, "Defense with 0 inputs should not be negative");
    }

    // --- HP Formula ---
    public function testHPNeverBelowMinimum(): void
    {
        for ($br = 0; $br <= 100; $br += 20) {
            for ($c = 0; $c <= 100; $c += 20) {
                $hp = pointsDeVieMolecule($br, $c, 5);
                $this->assertGreaterThanOrEqual(MOLECULE_MIN_HP, $hp,
                    "HP should never be below min at Br=$br, C=$c");
            }
        }
    }

    public function testHPIncreasesWithBrome(): void
    {
        $hp10 = pointsDeVieMolecule(10, 20, 5);
        $hp80 = pointsDeVieMolecule(80, 20, 5);
        $this->assertGreaterThan($hp10, $hp80, "More Br should give more HP");
    }

    // --- Speed Formula ---
    public function testSpeedBounded(): void
    {
        // Speed should be bounded even with maximum inputs
        $speed = vitesse(100, 100, 25);
        $this->assertGreaterThan(0, $speed);
        // With soft cap on Cl contribution, speed should not be astronomical
        $this->assertLessThan(10000, $speed, "Speed should be reasonably bounded");
    }

    public function testSpeedNonNegative(): void
    {
        $speed = vitesse(0, 0, 0);
        $this->assertGreaterThanOrEqual(0, $speed);
    }

    // --- Pillage Formula ---
    public function testPillageMonotonicallyIncreases(): void
    {
        $prev = 0;
        for ($s = 10; $s <= 100; $s += 10) {
            $val = pillage($s, 20, 5);
            $this->assertGreaterThan($prev, $val, "Pillage should increase with S=$s");
            $prev = $val;
        }
    }

    // --- Storage Formula ---
    public function testStorageMonotonicallyIncreases(): void
    {
        $prev = 0;
        for ($lvl = 1; $lvl <= 30; $lvl++) {
            $val = placeDepot($lvl);
            $this->assertGreaterThan($prev, $val, "Storage should increase at level $lvl");
            $prev = $val;
        }
    }

    // --- Energy Drain ---
    public function testDrainMonotonicallyIncreases(): void
    {
        $prev = 0;
        for ($lvl = 1; $lvl <= 30; $lvl++) {
            $val = drainageProducteur($lvl);
            $this->assertGreaterThanOrEqual($prev, $val, "Drain should increase at level $lvl");
            $prev = $val;
        }
    }

    // --- Class Cost ---
    public function testClassCostIncreases(): void
    {
        $cost1 = coutClasse(1);
        $cost2 = coutClasse(2);
        $cost3 = coutClasse(3);
        $cost4 = coutClasse(4);

        $this->assertGreaterThan(0, $cost1);
        $this->assertGreaterThan($cost1, $cost2);
        $this->assertGreaterThan($cost2, $cost3);
        $this->assertGreaterThan($cost3, $cost4);
    }

    // --- Vault Protection ---
    public function testVaultProtectionBounded(): void
    {
        for ($lvl = 1; $lvl <= 30; $lvl++) {
            $depotLvl = max(1, $lvl - 2);
            $vault = capaciteCoffreFort($lvl, $depotLvl);
            $storage = placeDepot($depotLvl);
            $ratio = $vault / $storage;
            $this->assertLessThanOrEqual(VAULT_MAX_PROTECTION_PCT + 0.01, $ratio,
                "Vault protection should never exceed cap at vault=$lvl, depot=$depotLvl");
        }
    }

    // --- Ranking Formula ---
    public function testRankingFormulaPositive(): void
    {
        $pts = calculerTotalPoints(100, 200, 150, 50, 100);
        $this->assertGreaterThan(0, $pts);
    }

    public function testRankingRewardsDiversity(): void
    {
        // All-in-one: 500 in one category, 0 in others
        $specialist = calculerTotalPoints(500, 0, 0, 0, 0);
        // Spread: 100 in each of 5 categories
        $diverse = calculerTotalPoints(100, 100, 100, 100, 100);
        $this->assertGreaterThan($specialist, $diverse, "Sqrt ranking should reward diversity");
    }

    // --- Iode Energy Production ---
    public function testIodeEnergyPositive(): void
    {
        $energy = productionEnergieMolecule(50, 5);
        $this->assertGreaterThan(0, $energy);
    }

    public function testIodeEnergyScalesWithLevel(): void
    {
        $low = productionEnergieMolecule(50, 1);
        $high = productionEnergieMolecule(50, 10);
        $this->assertGreaterThan($low, $high);
    }
}
