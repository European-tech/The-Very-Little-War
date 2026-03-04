<?php
/**
 * Config Sanity Smoke Tests.
 * Verifies all critical constants are defined and have reasonable values.
 * Uses the unit test bootstrap (no DB needed).
 */

class ConfigSanityTest extends PHPUnit\Framework\TestCase
{
    // --- Core Economy Constants ---
    public function testEnergyConstantsDefined(): void
    {
        $this->assertTrue(defined('BASE_ENERGY_PER_LEVEL'));
        $this->assertTrue(defined('BASE_ATOMS_PER_POINT'));
        $this->assertGreaterThan(0, BASE_ENERGY_PER_LEVEL);
        $this->assertGreaterThan(0, BASE_ATOMS_PER_POINT);
    }

    public function testStorageConstantsDefined(): void
    {
        $this->assertTrue(defined('BASE_STORAGE_INITIAL'));
        $this->assertTrue(defined('VAULT_PCT_PER_LEVEL'));
        $this->assertTrue(defined('VAULT_MAX_PROTECTION_PCT'));
        $this->assertGreaterThan(0, BASE_STORAGE_INITIAL);
        $this->assertLessThanOrEqual(1, VAULT_MAX_PROTECTION_PCT);
    }

    // --- Combat Constants ---
    public function testCombatConstantsDefined(): void
    {
        $this->assertTrue(defined('COVALENT_BASE_EXPONENT'));
        $this->assertTrue(defined('COVALENT_SYNERGY_DIVISOR'));
        $this->assertTrue(defined('COVALENT_CONDENSEUR_DIVISOR'));
        $this->assertTrue(defined('COMBAT_MASS_DIVISOR'));
        $this->assertTrue(defined('MOLECULE_MIN_HP'));
    }

    public function testCombatConstantsReasonable(): void
    {
        // Primary exponent should give increasing returns but not extreme
        $this->assertGreaterThan(1.0, COVALENT_BASE_EXPONENT);
        $this->assertLessThan(2.0, COVALENT_BASE_EXPONENT);

        // Synergy divisor should be positive
        $this->assertGreaterThan(0, COVALENT_SYNERGY_DIVISOR);

        // Min HP must be positive
        $this->assertGreaterThan(0, MOLECULE_MIN_HP);
    }

    // --- Building Constants ---
    public function testBuildingConfigExists(): void
    {
        global $BUILDING_CONFIG;
        $this->assertIsArray($BUILDING_CONFIG);

        $required = ['generateur', 'producteur', 'depot', 'champdeforce',
                     'ionisateur', 'condenseur', 'lieur', 'stabilisateur'];
        foreach ($required as $b) {
            $this->assertArrayHasKey($b, $BUILDING_CONFIG, "Missing building: $b");
        }
    }

    public function testBuildingConfigComplete(): void
    {
        global $BUILDING_CONFIG;

        foreach ($BUILDING_CONFIG as $name => $cfg) {
            $this->assertArrayHasKey('time_base', $cfg, "Missing time_base for $name");
            $this->assertArrayHasKey('time_growth_base', $cfg, "Missing time_growth_base for $name");
            $this->assertArrayHasKey('description', $cfg, "Missing description for $name");
            $this->assertNotEmpty($cfg['description'], "Empty description for $name");
        }
    }

    public function testBuildingHPConstants(): void
    {
        $this->assertTrue(defined('BUILDING_HP_BASE'));
        $this->assertTrue(defined('BUILDING_HP_POLY_EXP'));
        $this->assertTrue(defined('FORCEFIELD_HP_BASE'));
        $this->assertGreaterThan(0, BUILDING_HP_BASE);
        $this->assertGreaterThan(BUILDING_HP_BASE, FORCEFIELD_HP_BASE, "Forcefield should be tankier");
    }

    // --- Market Constants ---
    public function testMarketConstantsDefined(): void
    {
        $constants = ['MARKET_VOLATILITY_FACTOR', 'MARKET_PRICE_FLOOR', 'MARKET_PRICE_CEILING',
                      'MARKET_SELL_TAX_RATE', 'MARKET_MEAN_REVERSION', 'MARKET_POINTS_SCALE', 'MARKET_POINTS_MAX'];
        foreach ($constants as $c) {
            $this->assertTrue(defined($c), "Missing constant: $c");
        }
    }

    public function testMarketPriceFloorBelowCeiling(): void
    {
        $this->assertLessThan(MARKET_PRICE_CEILING, MARKET_PRICE_FLOOR);
    }

    // --- Speed & Cooldown ---
    public function testSpeedConstantsDefined(): void
    {
        $this->assertTrue(defined('SPEED_SOFT_CAP'));
        $this->assertGreaterThan(0, SPEED_SOFT_CAP);
    }

    // --- Prestige & Season ---
    public function testPrestigeConstantsDefined(): void
    {
        $constants = ['PRESTIGE_PRODUCTION_BONUS', 'PRESTIGE_COMBAT_BONUS',
                      'PRESTIGE_PP_ATTACK_THRESHOLD', 'PRESTIGE_PP_ATTACK_BONUS',
                      'PRESTIGE_PP_TRADE_THRESHOLD', 'PRESTIGE_PP_TRADE_BONUS'];
        foreach ($constants as $c) {
            $this->assertTrue(defined($c), "Missing constant: $c");
        }
    }

    public function testPrestigeRankBonusesExist(): void
    {
        global $PRESTIGE_RANK_BONUSES;
        $this->assertIsArray($PRESTIGE_RANK_BONUSES);
        $this->assertNotEmpty($PRESTIGE_RANK_BONUSES);
    }

    // --- Time Constants ---
    public function testTimeConstantsDefined(): void
    {
        $this->assertTrue(defined('SECONDS_PER_HOUR'));
        $this->assertTrue(defined('SECONDS_PER_DAY'));
        $this->assertEquals(3600, SECONDS_PER_HOUR);
        $this->assertEquals(86400, SECONDS_PER_DAY);
    }

    // --- Compound System ---
    public function testCompoundRecipesExist(): void
    {
        global $COMPOUNDS;
        $this->assertIsArray($COMPOUNDS);
        $this->assertNotEmpty($COMPOUNDS);
    }

    // --- Isotope System ---
    public function testIsotopeDefinitionsExist(): void
    {
        global $ISOTOPES;
        $this->assertIsArray($ISOTOPES);
        $this->assertNotEmpty($ISOTOPES);
    }

    // --- Alliance Research ---
    public function testAllianceResearchTreeExists(): void
    {
        global $ALLIANCE_RESEARCH;
        $this->assertIsArray($ALLIANCE_RESEARCH);
        $this->assertNotEmpty($ALLIANCE_RESEARCH);
    }

    // --- Ranking Weights ---
    public function testRankingWeightsDefined(): void
    {
        $this->assertTrue(defined('RANKING_CONSTRUCTION_WEIGHT'));
        $this->assertTrue(defined('RANKING_ATTACK_WEIGHT'));
        $this->assertTrue(defined('RANKING_DEFENSE_WEIGHT'));
        $this->assertTrue(defined('RANKING_TRADE_WEIGHT'));
        $this->assertTrue(defined('RANKING_PILLAGE_WEIGHT'));
        $this->assertTrue(defined('RANKING_SQRT_EXPONENT'));
        $this->assertEquals(0.5, RANKING_SQRT_EXPONENT);
    }
}
