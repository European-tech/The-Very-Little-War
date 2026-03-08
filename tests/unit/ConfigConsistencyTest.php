<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for configuration integrity and consistency.
 *
 * Validates that:
 * - All building configs have required keys
 * - Medal threshold arrays have consistent lengths
 * - Time constants are mathematically correct
 * - Game limits are positive integers
 * - Building config values are valid (positive costs, etc.)
 */
class ConfigConsistencyTest extends TestCase
{
    // =========================================================================
    // TIME CONSTANTS
    // =========================================================================

    public function testSecondsPerHour(): void
    {
        $this->assertEquals(3600, SECONDS_PER_HOUR);
        $this->assertEquals(60 * 60, SECONDS_PER_HOUR);
    }

    public function testSecondsPerDay(): void
    {
        $this->assertEquals(86400, SECONDS_PER_DAY);
        $this->assertEquals(24 * SECONDS_PER_HOUR, SECONDS_PER_DAY);
    }

    public function testSecondsPerWeek(): void
    {
        $this->assertEquals(604800, SECONDS_PER_WEEK);
        $this->assertEquals(7 * SECONDS_PER_DAY, SECONDS_PER_WEEK);
    }

    public function testSecondsPerMonth(): void
    {
        $this->assertEquals(2678400, SECONDS_PER_MONTH);
        $this->assertEquals(31 * SECONDS_PER_DAY, SECONDS_PER_MONTH);
    }

    public function testTimeConstantsAreConsistent(): void
    {
        $this->assertGreaterThan(SECONDS_PER_HOUR, SECONDS_PER_DAY);
        $this->assertGreaterThan(SECONDS_PER_DAY, SECONDS_PER_WEEK);
        $this->assertGreaterThan(SECONDS_PER_WEEK, SECONDS_PER_MONTH);
    }

    // =========================================================================
    // GAME LIMITS
    // =========================================================================

    public function testMaxConcurrentConstructions(): void
    {
        $this->assertEquals(2, MAX_CONCURRENT_CONSTRUCTIONS);
        $this->assertIsInt(MAX_CONCURRENT_CONSTRUCTIONS);
        $this->assertGreaterThan(0, MAX_CONCURRENT_CONSTRUCTIONS);
    }

    public function testMaxMoleculeClasses(): void
    {
        $this->assertEquals(4, MAX_MOLECULE_CLASSES);
        $this->assertIsInt(MAX_MOLECULE_CLASSES);
        $this->assertGreaterThan(0, MAX_MOLECULE_CLASSES);
    }

    public function testMaxAtomsPerElement(): void
    {
        $this->assertEquals(200, MAX_ATOMS_PER_ELEMENT);
        $this->assertIsInt(MAX_ATOMS_PER_ELEMENT);
        $this->assertGreaterThan(0, MAX_ATOMS_PER_ELEMENT);
    }

    public function testMaxAllianceMembers(): void
    {
        $this->assertEquals(20, MAX_ALLIANCE_MEMBERS);
        $this->assertIsInt(MAX_ALLIANCE_MEMBERS);
        $this->assertGreaterThan(0, MAX_ALLIANCE_MEMBERS);
    }

    public function testBeginnerProtection(): void
    {
        $this->assertEquals(3 * SECONDS_PER_DAY, BEGINNER_PROTECTION_SECONDS);
        $this->assertGreaterThan(0, BEGINNER_PROTECTION_SECONDS);
    }

    public function testAbsenceReportThreshold(): void
    {
        $this->assertEquals(6, ABSENCE_REPORT_THRESHOLD_HOURS);
        $this->assertGreaterThan(0, ABSENCE_REPORT_THRESHOLD_HOURS);
    }

    public function testOnlineTimeoutSeconds(): void
    {
        $this->assertEquals(300, ONLINE_TIMEOUT_SECONDS);
        $this->assertEquals(5 * 60, ONLINE_TIMEOUT_SECONDS);
        $this->assertGreaterThan(0, ONLINE_TIMEOUT_SECONDS);
    }

    public function testVictoryPointsTotal(): void
    {
        $this->assertEquals(1000, VICTORY_POINTS_TOTAL);
        $this->assertGreaterThan(0, VICTORY_POINTS_TOTAL);
    }

    public function testActivePlayerThreshold(): void
    {
        $this->assertEquals(SECONDS_PER_MONTH, ACTIVE_PLAYER_THRESHOLD);
    }

    // =========================================================================
    // BUILDING CONFIG STRUCTURE
    // =========================================================================

    public function testAllBuildingsExist(): void
    {
        global $BUILDING_CONFIG;

        $expectedBuildings = [
            'generateur', 'producteur', 'depot',
            'champdeforce', 'ionisateur',
            'condenseur', 'lieur', 'stabilisateur', 'coffrefort'
        ];

        foreach ($expectedBuildings as $building) {
            $this->assertArrayHasKey(
                $building,
                $BUILDING_CONFIG,
                "Building '$building' should exist in BUILDING_CONFIG"
            );
        }
    }

    public function testBuildingConfigCount(): void
    {
        global $BUILDING_CONFIG;
        // 9 buildings: generateur, producteur, depot, champdeforce, ionisateur,
        //              condenseur, lieur, stabilisateur, coffrefort
        $this->assertCount(9, $BUILDING_CONFIG);
    }

    public function testAllBuildingsHaveDescription(): void
    {
        global $BUILDING_CONFIG;

        foreach ($BUILDING_CONFIG as $name => $config) {
            $this->assertArrayHasKey(
                'description',
                $config,
                "Building '$name' should have a description"
            );
            $this->assertNotEmpty(
                $config['description'],
                "Building '$name' description should not be empty"
            );
        }
    }

    public function testAllBuildingsHaveTimeConfig(): void
    {
        global $BUILDING_CONFIG;

        foreach ($BUILDING_CONFIG as $name => $config) {
            $this->assertArrayHasKey(
                'time_base',
                $config,
                "Building '$name' should have time_base"
            );
            $this->assertArrayHasKey(
                'time_growth_base',
                $config,
                "Building '$name' should have time_growth_base"
            );
            $this->assertGreaterThan(
                0,
                $config['time_base'],
                "Building '$name' time_base should be positive"
            );
            $this->assertGreaterThan(
                0,
                $config['time_growth_base'],
                "Building '$name' time_growth_base should be positive"
            );
        }
    }

    public function testAllBuildingsHavePointsConfig(): void
    {
        global $BUILDING_CONFIG;

        foreach ($BUILDING_CONFIG as $name => $config) {
            $this->assertArrayHasKey(
                'points_base',
                $config,
                "Building '$name' should have points_base"
            );
            $this->assertArrayHasKey(
                'points_level_factor',
                $config,
                "Building '$name' should have points_level_factor"
            );
            $this->assertGreaterThan(
                0,
                $config['points_base'],
                "Building '$name' points_base should be positive"
            );
        }
    }

    public function testBuildingsWithEnergyCostHavePositiveBase(): void
    {
        global $BUILDING_CONFIG;

        $buildingsWithEnergyCost = ['generateur', 'producteur', 'depot', 'condenseur'];

        foreach ($buildingsWithEnergyCost as $name) {
            $this->assertArrayHasKey(
                'cost_energy_base',
                $BUILDING_CONFIG[$name],
                "Building '$name' should have cost_energy_base"
            );
            $this->assertGreaterThan(
                0,
                $BUILDING_CONFIG[$name]['cost_energy_base'],
                "Building '$name' cost_energy_base should be positive"
            );
        }
    }

    public function testBuildingsWithAtomCostHavePositiveBase(): void
    {
        global $BUILDING_CONFIG;

        $buildingsWithAtomCost = ['generateur', 'producteur', 'condenseur', 'stabilisateur'];

        foreach ($buildingsWithAtomCost as $name) {
            $this->assertArrayHasKey(
                'cost_atoms_base',
                $BUILDING_CONFIG[$name],
                "Building '$name' should have cost_atoms_base"
            );
            $this->assertGreaterThan(
                0,
                $BUILDING_CONFIG[$name]['cost_atoms_base'],
                "Building '$name' cost_atoms_base should be positive"
            );
        }
    }

    public function testDepotHasNoAtomCost(): void
    {
        global $BUILDING_CONFIG;

        $this->assertEquals(0, $BUILDING_CONFIG['depot']['cost_atoms_base']);
    }

    public function testSpecialBuildingsHaveSpecificCosts(): void
    {
        global $BUILDING_CONFIG;

        // Champdeforce costs carbone
        $this->assertArrayHasKey('cost_carbone_base', $BUILDING_CONFIG['champdeforce']);
        $this->assertGreaterThan(0, $BUILDING_CONFIG['champdeforce']['cost_carbone_base']);

        // Ionisateur costs oxygene
        $this->assertArrayHasKey('cost_oxygene_base', $BUILDING_CONFIG['ionisateur']);
        $this->assertGreaterThan(0, $BUILDING_CONFIG['ionisateur']['cost_oxygene_base']);

        // Lieur costs azote
        $this->assertArrayHasKey('cost_azote_base', $BUILDING_CONFIG['lieur']);
        $this->assertGreaterThan(0, $BUILDING_CONFIG['lieur']['cost_azote_base']);
    }

    public function testBuildingsWithLevelOffsetHavePositiveOffset(): void
    {
        global $BUILDING_CONFIG;

        $buildingsWithOffset = ['champdeforce', 'ionisateur', 'condenseur', 'lieur', 'stabilisateur'];

        foreach ($buildingsWithOffset as $name) {
            $this->assertArrayHasKey(
                'time_level_offset',
                $BUILDING_CONFIG[$name],
                "Building '$name' should have time_level_offset"
            );
            $this->assertGreaterThan(
                0,
                $BUILDING_CONFIG[$name]['time_level_offset'],
                "Building '$name' time_level_offset should be positive"
            );
        }
    }

    // =========================================================================
    // MEDAL THRESHOLD ARRAYS
    // =========================================================================

    public function testAllMedalThresholdsHaveEightTiers(): void
    {
        global $MEDAL_THRESHOLDS_TERREUR, $MEDAL_THRESHOLDS_ATTAQUE, $MEDAL_THRESHOLDS_DEFENSE;
        global $MEDAL_THRESHOLDS_PILLAGE, $MEDAL_THRESHOLDS_PIPELETTE, $MEDAL_THRESHOLDS_PERTES;
        global $MEDAL_THRESHOLDS_ENERGIEVORE, $MEDAL_THRESHOLDS_CONSTRUCTEUR, $MEDAL_THRESHOLDS_BOMBE;
        global $MEDAL_THRESHOLDS_TROLL;

        $expectedCount = 8;

        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_TERREUR, 'Terreur thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_ATTAQUE, 'Attaque thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_DEFENSE, 'Defense thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_PILLAGE, 'Pillage thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_PIPELETTE, 'Pipelette thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_PERTES, 'Pertes thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_ENERGIEVORE, 'Energievore thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_CONSTRUCTEUR, 'Constructeur thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_BOMBE, 'Bombe thresholds');
        $this->assertCount($expectedCount, $MEDAL_THRESHOLDS_TROLL, 'Troll thresholds');
    }

    public function testMedalTierNamesHaveEightEntries(): void
    {
        global $MEDAL_TIER_NAMES, $MEDAL_TIER_IMAGES;

        $this->assertCount(8, $MEDAL_TIER_NAMES);
        $this->assertCount(8, $MEDAL_TIER_IMAGES);
    }

    public function testMedalBonusesHaveEightEntries(): void
    {
        global $MEDAL_BONUSES;

        $this->assertCount(8, $MEDAL_BONUSES);
    }

    public function testMedalForumBadgesHaveEightEntries(): void
    {
        global $MEDAL_FORUM_BADGES;

        $this->assertCount(8, $MEDAL_FORUM_BADGES);
    }

    public function testAllMedalThresholdsAreStrictlyIncreasing(): void
    {
        global $MEDAL_THRESHOLDS_TERREUR, $MEDAL_THRESHOLDS_ATTAQUE, $MEDAL_THRESHOLDS_DEFENSE;
        global $MEDAL_THRESHOLDS_PILLAGE, $MEDAL_THRESHOLDS_PIPELETTE, $MEDAL_THRESHOLDS_PERTES;
        global $MEDAL_THRESHOLDS_ENERGIEVORE, $MEDAL_THRESHOLDS_CONSTRUCTEUR, $MEDAL_THRESHOLDS_BOMBE;
        global $MEDAL_THRESHOLDS_TROLL;

        $allThresholds = [
            'Terreur' => $MEDAL_THRESHOLDS_TERREUR,
            'Attaque' => $MEDAL_THRESHOLDS_ATTAQUE,
            'Defense' => $MEDAL_THRESHOLDS_DEFENSE,
            'Pillage' => $MEDAL_THRESHOLDS_PILLAGE,
            'Pipelette' => $MEDAL_THRESHOLDS_PIPELETTE,
            'Pertes' => $MEDAL_THRESHOLDS_PERTES,
            'Energievore' => $MEDAL_THRESHOLDS_ENERGIEVORE,
            'Constructeur' => $MEDAL_THRESHOLDS_CONSTRUCTEUR,
            'Bombe' => $MEDAL_THRESHOLDS_BOMBE,
            'Troll' => $MEDAL_THRESHOLDS_TROLL,
        ];

        foreach ($allThresholds as $name => $thresholds) {
            for ($i = 1; $i < count($thresholds); $i++) {
                $this->assertGreaterThan(
                    $thresholds[$i - 1],
                    $thresholds[$i],
                    "$name threshold at tier $i should exceed tier " . ($i - 1)
                );
            }
        }
    }

    public function testMedalBonusesAreStrictlyIncreasing(): void
    {
        global $MEDAL_BONUSES;

        for ($i = 1; $i < count($MEDAL_BONUSES); $i++) {
            $this->assertGreaterThan(
                $MEDAL_BONUSES[$i - 1],
                $MEDAL_BONUSES[$i],
                "Medal bonus at tier $i should exceed tier " . ($i - 1)
            );
        }
    }

    public function testMedalBonusesArePositive(): void
    {
        global $MEDAL_BONUSES;

        foreach ($MEDAL_BONUSES as $i => $bonus) {
            $this->assertGreaterThan(0, $bonus, "Medal bonus at tier $i should be positive");
        }
    }

    public function testMedalBonusExpectedValues(): void
    {
        global $MEDAL_BONUSES;

        $expected = [1, 3, 6, 10, 15, 20, 30, 50];
        $this->assertEquals($expected, $MEDAL_BONUSES);
    }

    // =========================================================================
    // LEGACY CONSTANTS MATCH CONFIG CONSTANTS
    // =========================================================================

    public function testLegacyConstantsMatchConfig(): void
    {
        global $nomsRes, $lettre, $nbClasses, $nbPointsVictoire;
        global $paliersMedailles, $imagesMedailles, $bonusMedailles;
        global $RESOURCE_NAMES, $RESOURCE_LETTERS;
        global $MEDAL_TIER_NAMES, $MEDAL_TIER_IMAGES, $MEDAL_BONUSES;

        // Resource names match
        $this->assertEquals($RESOURCE_NAMES, $nomsRes);

        // Resource letters match
        $this->assertEquals($RESOURCE_LETTERS, $lettre);

        // Medal tiers match
        $this->assertEquals($MEDAL_TIER_NAMES, $paliersMedailles);
        $this->assertEquals($MEDAL_TIER_IMAGES, $imagesMedailles);
        $this->assertEquals($MEDAL_BONUSES, $bonusMedailles);

        // Game limits match
        $this->assertEquals($nbClasses, MAX_MOLECULE_CLASSES);
        $this->assertEquals($nbPointsVictoire, VICTORY_POINTS_TOTAL);
    }

    public function testLegacyMedalThresholdsMatchConfig(): void
    {
        global $paliersTerreur, $paliersAttaque, $paliersDefense, $paliersPillage;
        global $paliersPipelette, $paliersPertes, $paliersEnergievore, $paliersConstructeur;
        global $paliersBombe, $paliersTroll;
        global $MEDAL_THRESHOLDS_TERREUR, $MEDAL_THRESHOLDS_ATTAQUE, $MEDAL_THRESHOLDS_DEFENSE;
        global $MEDAL_THRESHOLDS_PILLAGE, $MEDAL_THRESHOLDS_PIPELETTE, $MEDAL_THRESHOLDS_PERTES;
        global $MEDAL_THRESHOLDS_ENERGIEVORE, $MEDAL_THRESHOLDS_CONSTRUCTEUR, $MEDAL_THRESHOLDS_BOMBE;
        global $MEDAL_THRESHOLDS_TROLL;

        $this->assertEquals($MEDAL_THRESHOLDS_TERREUR, $paliersTerreur);
        $this->assertEquals($MEDAL_THRESHOLDS_ATTAQUE, $paliersAttaque);
        $this->assertEquals($MEDAL_THRESHOLDS_DEFENSE, $paliersDefense);
        $this->assertEquals($MEDAL_THRESHOLDS_PILLAGE, $paliersPillage);
        $this->assertEquals($MEDAL_THRESHOLDS_PIPELETTE, $paliersPipelette);
        $this->assertEquals($MEDAL_THRESHOLDS_PERTES, $paliersPertes);
        $this->assertEquals($MEDAL_THRESHOLDS_ENERGIEVORE, $paliersEnergievore);
        $this->assertEquals($MEDAL_THRESHOLDS_CONSTRUCTEUR, $paliersConstructeur);
        $this->assertEquals($MEDAL_THRESHOLDS_BOMBE, $paliersBombe);
        $this->assertEquals($MEDAL_THRESHOLDS_TROLL, $paliersTroll);
    }

    public function testLegacyAllianceMembersMatchConfig(): void
    {
        global $joueursEquipe;
        $this->assertEquals(MAX_ALLIANCE_MEMBERS, $joueursEquipe);
    }

    public function testLegacyMarketSpeedMatchesConfig(): void
    {
        global $vitesseMarchands;
        $this->assertEquals(MERCHANT_SPEED, $vitesseMarchands);
    }

    public function testLegacyEspionageMatchesConfig(): void
    {
        global $vitesseEspionnage, $coutNeutrino;
        $this->assertEquals(ESPIONAGE_SPEED, $vitesseEspionnage);
        $this->assertEquals(NEUTRINO_COST, $coutNeutrino);
    }

    // =========================================================================
    // RESOURCE ARRAYS CONSISTENCY
    // =========================================================================

    public function testResourceArraysHaveEightElements(): void
    {
        global $RESOURCE_NAMES, $RESOURCE_COLORS, $RESOURCE_COLORS_SIMPLE, $RESOURCE_LETTERS;
        global $RESOURCE_NAMES_ACCENTED;

        $this->assertCount(8, $RESOURCE_NAMES);
        $this->assertCount(8, $RESOURCE_NAMES_ACCENTED);
        $this->assertCount(8, $RESOURCE_COLORS);
        $this->assertCount(8, $RESOURCE_COLORS_SIMPLE);
        $this->assertCount(8, $RESOURCE_LETTERS);
    }

    public function testResourceArraysMatchLegacy(): void
    {
        global $nomsRes, $couleurs, $couleursSimples, $lettre;
        global $RESOURCE_NAMES, $RESOURCE_COLORS, $RESOURCE_COLORS_SIMPLE, $RESOURCE_LETTERS;

        $this->assertEquals($nomsRes, $RESOURCE_NAMES);
        $this->assertEquals($couleurs, $RESOURCE_COLORS);
        $this->assertEquals($couleursSimples, $RESOURCE_COLORS_SIMPLE);
        $this->assertEquals($lettre, $RESOURCE_LETTERS);
    }

    public function testLegacyNbResCalculation(): void
    {
        global $nomsRes, $nbRes;

        // MARKET-CRIT-001: nbRes = count(nomsRes) = 8 (all atoms incl. iode at index 7)
        // Previously was count($nomsRes)-1 = 7, which caused iode to always cost 1 energy.
        $this->assertEquals(count($nomsRes), $nbRes);
        $this->assertEquals(8, $nbRes);
    }

    // =========================================================================
    // V4 COVALENT SYNERGY CONSTANTS
    // =========================================================================

    public function testCovalentSynergyConstants(): void
    {
        $this->assertEquals(50, COVALENT_CONDENSEUR_DIVISOR);
        $this->assertEquals(1.2, COVALENT_BASE_EXPONENT);
        $this->assertEquals(100, COVALENT_SYNERGY_DIVISOR);
        $this->assertEquals(10, MOLECULE_MIN_HP);
    }

    public function testIodeConstants(): void
    {
        $this->assertEquals(0.04, IODE_ENERGY_COEFFICIENT);
        $this->assertEquals(0.003, IODE_QUADRATIC_COEFFICIENT);
        $this->assertGreaterThan(0, IODE_LEVEL_DIVISOR);
    }

    // =========================================================================
    // BUILDING HP CONSTANTS
    // =========================================================================

    public function testBuildingHPConstants(): void
    {
        $this->assertEquals(50, BUILDING_HP_BASE);
        $this->assertEquals(2.5, BUILDING_HP_POLY_EXP);
    }

    public function testForceFieldHPConstants(): void
    {
        $this->assertEquals(125, FORCEFIELD_HP_BASE);
        $this->assertEquals(2.5, BUILDING_HP_POLY_EXP);
    }

    public function testForceFieldHPBaseHigherThanBuilding(): void
    {
        $this->assertGreaterThan(BUILDING_HP_BASE, FORCEFIELD_HP_BASE);
    }

    // =========================================================================
    // ALLIANCE CONSTANTS
    // =========================================================================

    public function testAllianceTagConstraints(): void
    {
        $this->assertEquals(3, ALLIANCE_TAG_MIN_LENGTH);
        $this->assertEquals(16, ALLIANCE_TAG_MAX_LENGTH);
        $this->assertLessThan(ALLIANCE_TAG_MAX_LENGTH, ALLIANCE_TAG_MIN_LENGTH);
    }

    public function testDuplicateurConstants(): void
    {
        $this->assertEquals(100, DUPLICATEUR_BASE_COST);
        $this->assertEquals(1.5, DUPLICATEUR_COST_FACTOR);
        $this->assertEquals(0.01, DUPLICATEUR_BONUS_PER_LEVEL);
    }

    // =========================================================================
    // REGISTRATION CONSTANTS
    // =========================================================================

    public function testRegistrationRandomMax(): void
    {
        $this->assertEquals(200, REGISTRATION_RANDOM_MAX);
    }

    public function testRegistrationElementThresholds(): void
    {
        global $REGISTRATION_ELEMENT_THRESHOLDS;

        $this->assertCount(8, $REGISTRATION_ELEMENT_THRESHOLDS);

        // Should be strictly increasing
        for ($i = 1; $i < count($REGISTRATION_ELEMENT_THRESHOLDS); $i++) {
            $this->assertGreaterThan(
                $REGISTRATION_ELEMENT_THRESHOLDS[$i - 1],
                $REGISTRATION_ELEMENT_THRESHOLDS[$i]
            );
        }

        // Last threshold should equal REGISTRATION_RANDOM_MAX
        $this->assertEquals(
            REGISTRATION_RANDOM_MAX,
            end($REGISTRATION_ELEMENT_THRESHOLDS)
        );
    }

    public function testRegistrationElementProbabilities(): void
    {
        global $REGISTRATION_ELEMENT_THRESHOLDS;

        // First element (common) should have highest probability
        $firstProb = $REGISTRATION_ELEMENT_THRESHOLDS[0] / REGISTRATION_RANDOM_MAX;
        $this->assertEquals(0.5, $firstProb); // 50% chance

        // Last element (rare) should have lowest probability
        $lastProb = (REGISTRATION_RANDOM_MAX - $REGISTRATION_ELEMENT_THRESHOLDS[6]) / REGISTRATION_RANDOM_MAX;
        $this->assertEquals(0.005, $lastProb); // 0.5% chance
    }

    // =========================================================================
    // VICTORY POINTS STRUCTURE
    // =========================================================================

    public function testVictoryPointsPlayerStructure(): void
    {
        global $VICTORY_POINTS_PLAYER;

        $this->assertArrayHasKey(1, $VICTORY_POINTS_PLAYER);
        $this->assertArrayHasKey(2, $VICTORY_POINTS_PLAYER);
        $this->assertArrayHasKey(3, $VICTORY_POINTS_PLAYER);

        // Top 3 should be decreasing
        $this->assertGreaterThan($VICTORY_POINTS_PLAYER[2], $VICTORY_POINTS_PLAYER[1]);
        $this->assertGreaterThan($VICTORY_POINTS_PLAYER[3], $VICTORY_POINTS_PLAYER[2]);
    }

    public function testVictoryPointsAllianceStructure(): void
    {
        global $VICTORY_POINTS_ALLIANCE;

        $this->assertArrayHasKey(1, $VICTORY_POINTS_ALLIANCE);
        $this->assertArrayHasKey(2, $VICTORY_POINTS_ALLIANCE);
        $this->assertArrayHasKey(3, $VICTORY_POINTS_ALLIANCE);

        // Top 3 should be decreasing
        $this->assertGreaterThan($VICTORY_POINTS_ALLIANCE[2], $VICTORY_POINTS_ALLIANCE[1]);
        $this->assertGreaterThan($VICTORY_POINTS_ALLIANCE[3], $VICTORY_POINTS_ALLIANCE[2]);
    }

    public function testVictoryPointsPlayerAlwaysPositiveOrZero(): void
    {
        // Verify the formula for all ranks produces non-negative values
        for ($rank = 1; $rank <= 60; $rank++) {
            if ($rank == 1) {
                $points = VP_PLAYER_RANK1;
            } elseif ($rank == 2) {
                $points = VP_PLAYER_RANK2;
            } elseif ($rank == 3) {
                $points = VP_PLAYER_RANK3;
            } elseif ($rank <= 10) {
                $points = VP_PLAYER_RANK4_10_BASE - ($rank - 3) * VP_PLAYER_RANK4_10_STEP;
            } elseif ($rank <= 20) {
                $points = VP_PLAYER_RANK11_20_BASE - ($rank - 10) * VP_PLAYER_RANK11_20_STEP;
            } elseif ($rank <= 50) {
                $points = floor(VP_PLAYER_RANK21_50_BASE - ($rank - 20) * VP_PLAYER_RANK21_50_STEP);
            } else {
                $points = 0;
            }

            $this->assertGreaterThanOrEqual(
                0,
                $points,
                "Victory points for rank $rank should be non-negative"
            );
        }
    }

    // =========================================================================
    // COMBAT CONSTANTS
    // =========================================================================

    public function testCombatConstants(): void
    {
        $this->assertEquals(0.15, ATTACK_ENERGY_COST_FACTOR);
        $this->assertEquals(2, IONISATEUR_COMBAT_BONUS_PER_LEVEL);
        $this->assertEquals(2, CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL);
        // DUPLICATEUR_COMBAT_COEFFICIENT removed (CMB-P6-003): formula uses DUPLICATEUR_BONUS_PER_LEVEL directly
        $this->assertEquals(4, NUM_DAMAGEABLE_BUILDINGS);
    }

    public function testCombatBonusesAreSymmetric(): void
    {
        // Ionisateur (attack) and champdeforce (defense) should give same bonus per level
        $this->assertEquals(
            IONISATEUR_COMBAT_BONUS_PER_LEVEL,
            CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL,
            'Attack and defense building bonuses should be symmetric'
        );
    }

    // =========================================================================
    // LIEUR CONSTANTS
    // =========================================================================

    public function testLieurLinearBonusPerLevel(): void
    {
        $this->assertEquals(0.15, LIEUR_LINEAR_BONUS_PER_LEVEL);
        $this->assertGreaterThan(0, LIEUR_LINEAR_BONUS_PER_LEVEL, 'Lieur bonus per level should be positive');
    }

    // =========================================================================
    // DECAY CONSTANTS
    // =========================================================================

    public function testDecayConstants(): void
    {
        $this->assertEquals(0.99, DECAY_BASE);
        // Increased from 100: large molecules slightly more viable
        $this->assertEquals(150, DECAY_ATOM_DIVISOR);
        // Increased from 5000
        $this->assertEquals(25000, DECAY_POWER_DIVISOR);
        // Buffed from 0.01 -> 1.5% per level
        $this->assertEquals(0.015, STABILISATEUR_BONUS_PER_LEVEL);
    }

    public function testDecayBaseIsLessThanOne(): void
    {
        // Decay base must be < 1 for molecules to actually decay
        $this->assertLessThan(1.0, DECAY_BASE);
        $this->assertGreaterThan(0.0, DECAY_BASE);
    }
}
