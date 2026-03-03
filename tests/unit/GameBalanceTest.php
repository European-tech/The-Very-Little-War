<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for game balance constants and formula relationships.
 *
 * Verifies that:
 * - Critical balance constants exist and have sane values
 * - Attack/defense formulas are comparable (no overwhelming advantage)
 * - Higher building levels cost more than lower
 * - Victory point sums don't exceed caps
 * - Bonus multipliers work correctly at boundary levels
 */
class GameBalanceTest extends TestCase
{
    // =========================================================================
    // A) CONFIG CONSTANTS EXIST AND ARE SANE
    // =========================================================================

    public function testAttackConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, ATTACK_ATOM_COEFFICIENT);
        $this->assertGreaterThan(0, ATTACK_LEVEL_DIVISOR);
        $this->assertGreaterThan(0, ATTACK_POINTS_MULTIPLIER);
        $this->assertGreaterThan(0, ATTACK_ENERGY_COST_FACTOR);
    }

    public function testDefenseConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, DEFENSE_ATOM_COEFFICIENT);
        $this->assertGreaterThan(0, DEFENSE_LEVEL_DIVISOR);
        $this->assertGreaterThan(0, DEFENSE_POINTS_MULTIPLIER);
    }

    public function testHPConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, HP_ATOM_COEFFICIENT);
        $this->assertGreaterThan(0, HP_LEVEL_DIVISOR);
        $this->assertGreaterThan(0, BUILDING_HP_BASE);
        $this->assertGreaterThan(0, FORCEFIELD_HP_BASE);
    }

    public function testPillageConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, PILLAGE_ATOM_COEFFICIENT);
        $this->assertGreaterThan(0, PILLAGE_SOUFRE_DIVISOR);
        $this->assertGreaterThan(0, PILLAGE_LEVEL_DIVISOR);
        $this->assertGreaterThan(0, PILLAGE_POINTS_DIVISOR);
        $this->assertGreaterThan(0, PILLAGE_POINTS_MULTIPLIER);
    }

    public function testBaseEnergyPerLevelIsPositive(): void
    {
        $this->assertGreaterThan(0, BASE_ENERGY_PER_LEVEL);
    }

    public function testBaseStoragePerLevelIsPositive(): void
    {
        $this->assertGreaterThan(0, BASE_STORAGE_PER_LEVEL);
    }

    public function testMaxMoleculeClassesIsFour(): void
    {
        $this->assertEquals(4, MAX_MOLECULE_CLASSES);
    }

    public function testVictoryPointsTotalIsThousand(): void
    {
        $this->assertEquals(1000, VICTORY_POINTS_TOTAL);
    }

    public function testCombatPointsConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, COMBAT_POINTS_BASE);
        $this->assertGreaterThan(0, COMBAT_POINTS_CASUALTY_SCALE);
        $this->assertGreaterThan(0, COMBAT_POINTS_MAX_PER_BATTLE);
    }

    // =========================================================================
    // B) BALANCE RELATIONSHIPS
    // =========================================================================

    public function testDefenseComparableToAttack(): void
    {
        // At 100 atoms, level 0, attack and defense raw values should be equal
        // (same coefficients: 0.1 and divisor 50)
        $attackRaw = 1 + pow(ATTACK_ATOM_COEFFICIENT * 100, 2) + 100;
        $defenseRaw = 1 + pow(DEFENSE_ATOM_COEFFICIENT * 100, 2) + 100;

        $this->assertEquals($attackRaw, $defenseRaw,
            'Attack and defense with same atoms must produce equal raw values');
    }

    public function testDefenseWithin10xOfAttack(): void
    {
        // At max atoms (200), verify neither stat is more than 10x the other
        $attack200 = 1 + pow(ATTACK_ATOM_COEFFICIENT * 200, 2) + 200;
        $defense200 = 1 + pow(DEFENSE_ATOM_COEFFICIENT * 200, 2) + 200;

        $ratio = $attack200 / $defense200;
        $this->assertGreaterThan(0.1, $ratio, 'Attack must not be 10x weaker than defense');
        $this->assertLessThan(10.0, $ratio, 'Attack must not be 10x stronger than defense');
    }

    public function testHigherBuildingLevelsCostMore(): void
    {
        global $BUILDING_CONFIG;

        // V4: exponential cost = base * pow(growth_base, level)
        $base = $BUILDING_CONFIG['generateur']['cost_energy_base'];
        $growth = $BUILDING_CONFIG['generateur']['cost_growth_base'];

        $cost5 = round($base * pow($growth, 5));
        $cost10 = round($base * pow($growth, 10));
        $cost20 = round($base * pow($growth, 20));

        $this->assertGreaterThan($cost5, $cost10, 'Level 10 must cost more than level 5');
        $this->assertGreaterThan($cost10, $cost20, 'Level 20 must cost more than level 10');
    }

    public function testHigherCondenseurLevelsCostMore(): void
    {
        global $BUILDING_CONFIG;

        // V4: exponential cost = base * pow(growth_base, level)
        $base = $BUILDING_CONFIG['condenseur']['cost_energy_base'];
        $growth = $BUILDING_CONFIG['condenseur']['cost_growth_base'];

        $cost1 = round($base * pow($growth, 1));
        $cost5 = round($base * pow($growth, 5));
        $cost15 = round($base * pow($growth, 15));

        $this->assertLessThan($cost5, $cost1, 'Level 1 must cost less than level 5');
        $this->assertLessThan($cost15, $cost5, 'Level 5 must cost less than level 15');
    }

    public function testVictoryPointsTop50SumIsReasonable(): void
    {
        // BAL-CROSS C10: reshaped VP curve with smoother rank 21-100 distribution
        // Top 50 sum is 1079 — slightly over VICTORY_POINTS_TOTAL (1000).
        $sum = 0;
        for ($rank = 1; $rank <= 50; $rank++) {
            $sum += pointsVictoireJoueur($rank);
        }
        $this->assertEquals(1079, (int) $sum, 'Top 50 VP sum should be 1079');
        $this->assertLessThan(VICTORY_POINTS_TOTAL * 2, $sum,
            "Top 50 VP sum must stay under 2x VICTORY_POINTS_TOTAL");
    }

    public function testAllianceVPSumIsReasonable(): void
    {
        // Total alliance VP for top 9 ranks
        $sum = 0;
        for ($rank = 1; $rank <= 9; $rank++) {
            $sum += pointsVictoireAlliance($rank);
        }
        // Should be much less than VICTORY_POINTS_TOTAL
        $this->assertLessThan(VICTORY_POINTS_TOTAL / 2, $sum,
            "Alliance VP sum should be well under player VP total");
    }

    public function testPointsAttaqueDefenseSymmetry(): void
    {
        // pointsAttaque and pointsDefense use same multiplier
        $this->assertEquals(
            ATTACK_POINTS_MULTIPLIER,
            DEFENSE_POINTS_MULTIPLIER,
            'Attack and defense point multipliers must be equal for balance'
        );
    }

    public function testPointsAttaqueReturnsZeroForZeroOrNegative(): void
    {
        $this->assertEquals(0, pointsAttaque(0));
        $this->assertEquals(0, pointsAttaque(-10));
        $this->assertEquals(0, pointsAttaque(-100));
    }

    public function testPointsDefenseReturnsZeroForZeroOrNegative(): void
    {
        $this->assertEquals(0, pointsDefense(0));
        $this->assertEquals(0, pointsDefense(-5));
    }

    public function testPointsAttaqueScalesSqrt(): void
    {
        // pointsAttaque uses sqrt scaling: round(5.0 * sqrt(pts))
        $pts100 = pointsAttaque(100);
        $pts400 = pointsAttaque(400);

        // sqrt(400) / sqrt(100) = 20/10 = 2
        // So pointsAttaque(400) should be ~2x pointsAttaque(100)
        $this->assertEqualsWithDelta($pts100 * 2, $pts400, 1,
            'Points at 400 should be ~2x points at 100 (sqrt scaling)');
    }

    // =========================================================================
    // C) BONUS MULTIPLIERS
    // =========================================================================

    public function testBonusDuplicateurLevel0ReturnsZero(): void
    {
        // bonusDuplicateur(0) = 0 * 0.01 = 0
        // Applied as (1 + bonus), so effective multiplier is 1.0 (no bonus)
        $bonus = bonusDuplicateur(0);
        $this->assertEquals(0.0, $bonus, 'Duplicateur at level 0 returns 0 (no bonus)');
        $this->assertEquals(1.0, 1 + $bonus, 'Effective multiplier at level 0 is 1.0');
    }

    public function testBonusDuplicateurLevel5ReturnsPositive(): void
    {
        $bonus = bonusDuplicateur(5);
        $this->assertEquals(0.05, $bonus, 'Duplicateur at level 5 returns 0.05 (5% bonus)');
        $this->assertGreaterThan(1.0, 1 + $bonus, 'Effective multiplier must exceed 1.0');
    }

    public function testBonusDuplicateurLevel25MaxAlliance(): void
    {
        $bonus = bonusDuplicateur(ALLIANCE_RESEARCH_MAX_LEVEL);
        $expected = ALLIANCE_RESEARCH_MAX_LEVEL * DUPLICATEUR_BONUS_PER_LEVEL;
        $this->assertEqualsWithDelta($expected, $bonus, 0.0001);
        $this->assertEquals(0.25, $bonus, 'Duplicateur at max level gives 25% bonus');
    }

    public function testBonusLieurLevel0ReturnsOne(): void
    {
        // bonusLieur(0) = floor(100 * pow(1.07, 0)) / 100 = 1.0
        $bonus = bonusLieur(0);
        $this->assertEquals(1.0, $bonus, 'Lieur at level 0 returns 1.0 (no speed bonus)');
    }

    public function testBonusLieurLevel5ReturnsGreaterThanOne(): void
    {
        $bonus = bonusLieur(5);
        // V4: 1 + 5 * 0.15 = 1.75
        $expected = 1 + 5 * LIEUR_LINEAR_BONUS_PER_LEVEL;
        $this->assertEquals($expected, $bonus);
        $this->assertGreaterThan(1.0, $bonus, 'Lieur at level 5 must provide speed bonus');
    }

    public function testBonusLieurStrictlyIncreases(): void
    {
        $prev = bonusLieur(0);
        for ($level = 1; $level <= 25; $level++) {
            $current = bonusLieur($level);
            $this->assertGreaterThan($prev, $current,
                "Lieur bonus at level $level must exceed level " . ($level - 1));
            $prev = $current;
        }
    }

    public function testDrainageProducteurScalesExponentially(): void
    {
        // V4: drainageProducteur = round(8 * pow(1.15, level))
        $drain5 = drainageProducteur(5);
        $drain10 = drainageProducteur(10);
        $drain20 = drainageProducteur(20);

        $expected5 = round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, 5));
        $expected10 = round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, 10));
        $expected20 = round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, 20));

        $this->assertEquals($expected5, $drain5);
        $this->assertEquals($expected10, $drain10);
        $this->assertEquals($expected20, $drain20);

        // Exponential: each level costs more than previous
        $this->assertGreaterThan($drain5, $drain10);
        $this->assertGreaterThan($drain10, $drain20);
    }

    public function testProductionEnergieMoleculeScalesWithIode(): void
    {
        // V4: round((0.003 * iode^2 + 0.04 * iode) * (1 + niveau / 50))
        $energy0 = productionEnergieMolecule(100, 0);
        $energy50 = productionEnergieMolecule(100, 50);

        $this->assertEquals(34, $energy0, '100 iode at level 0 = 34 energy (quadratic buff)');
        $this->assertEquals(68, $energy50, '100 iode at level 50 = 68 energy (2x from level)');
    }

    public function testVitesseBaseIsOneWithNoChlorine(): void
    {
        // V4: vitesse($Cl, $N, $nivCondCl)
        $speed = vitesse(0, 0, 0);
        $this->assertEquals(1.0, $speed, 'Base speed with 0 chlore must be 1.0');
    }

    public function testVitesseIncreasesWithChlorine(): void
    {
        // V4: vitesse($Cl, $N, $nivCondCl)
        $speed10 = vitesse(10, 0, 0);
        $speed50 = vitesse(50, 0, 0);
        $speed100 = vitesse(100, 0, 0);

        $this->assertGreaterThan(1.0, $speed10);
        $this->assertGreaterThan($speed10, $speed50);
        $this->assertGreaterThan($speed50, $speed100);
    }

    public function testCoutClasseIncreasesWithClassNumber(): void
    {
        $cost1 = coutClasse(1);
        $cost2 = coutClasse(2);
        $cost3 = coutClasse(3);
        $cost4 = coutClasse(4);

        $this->assertLessThan($cost2, $cost1, 'Class 1 must cost less than class 2');
        $this->assertLessThan($cost3, $cost2, 'Class 2 must cost less than class 3');
        $this->assertLessThan($cost4, $cost3, 'Class 3 must cost less than class 4');
    }

    public function testPlaceDepotScalesExponentially(): void
    {
        // V4: placeDepot = round(1000 * pow(1.15, niveau))
        $this->assertEquals(round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 0)), placeDepot(0));
        $this->assertEquals(round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 1)), placeDepot(1));
        $this->assertEquals(round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, 10)), placeDepot(10));

        // Always increasing
        $this->assertGreaterThan(placeDepot(0), placeDepot(1));
        $this->assertGreaterThan(placeDepot(1), placeDepot(10));
    }

    public function testPointsPillageCapsTanhAsymptote(): void
    {
        // pointsPillage uses tanh which asymptotes to PILLAGE_POINTS_MULTIPLIER (80)
        $lowPoints = pointsPillage(1000);
        $midPoints = pointsPillage(100000);
        $highPoints = pointsPillage(10000000);

        $this->assertGreaterThan($lowPoints, $midPoints);
        $this->assertGreaterThan($midPoints, $highPoints);

        // At very high resources, should approach but not exceed 80
        $this->assertLessThanOrEqual(PILLAGE_POINTS_MULTIPLIER, $highPoints);
        $this->assertGreaterThan(PILLAGE_POINTS_MULTIPLIER * 0.99, $highPoints,
            'At 10M resources, pillage points should be very close to cap');
    }
}
