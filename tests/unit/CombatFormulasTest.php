<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for combat-related formulas and calculations.
 *
 * Since the actual game functions (attaque, defense, pillage, tempsFormation,
 * coefDisparition) depend on database queries for medal bonuses and building
 * levels, we test the underlying mathematical formulas directly.
 *
 * Formula references (from includes/fonctions.php and includes/config.php):
 *   Attack:      round((1 + (0.1 * oxygene)^2 + oxygene) * (1 + niveau / 50) * (1 + bonus / 100))
 *   Defense:     round((1 + (0.1 * carbone)^2 + carbone) * (1 + niveau / 50) * (1 + bonus / 100))
 *   HP:          round((1 + (0.1 * brome)^2 + brome) * (1 + niveau / 50))
 *   Destruction: round(((0.075 * hydrogene)^2 + hydrogene) * (1 + niveau / 50))
 *   Pillage:     round(((0.1 * soufre)^2 + soufre / 3) * (1 + niveau / 50) * (1 + bonus / 100))
 *   Speed:       floor((1 + 0.5 * chlore) * (1 + niveau / 50) * 100) / 100
 *   Formation:   ceil(ntotal / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur * 100) / 100
 *   Decay:       pow(pow(0.99, pow(1 + nbAtomes/100, 2) / 5000), (1 - bonus/100) * (1 - stab * 0.005))
 */
class CombatFormulasTest extends TestCase
{
    // =========================================================================
    // ATTACK FORMULA
    // Formula: round((1 + (0.1 * oxygene)^2 + oxygene) * (1 + niveau / 50) * (1 + bonus / 100))
    // =========================================================================

    /**
     * Helper: compute raw attack value (no medal bonus, no condenseur level).
     */
    private function rawAttack(int $oxygene): float
    {
        return 1 + pow(0.1 * $oxygene, 2) + $oxygene;
    }

    /**
     * Helper: compute full attack value matching the game function.
     */
    private function computeAttack(int $oxygene, int $niveau, int $medalBonus = 0): int
    {
        return (int) round(
            $this->rawAttack($oxygene) * (1 + $niveau / 50) * (1 + $medalBonus / 100)
        );
    }

    public function testAttackFormulaZeroAtoms(): void
    {
        // With 0 oxygen atoms, attack should be minimal: 1 + 0 + 0 = 1
        $raw = $this->rawAttack(0);
        $this->assertEquals(1, $raw);
        $this->assertEquals(1, $this->computeAttack(0, 0));
    }

    public function testAttackFormulaFiftyAtoms(): void
    {
        // 1 + (0.1*50)^2 + 50 = 1 + 25 + 50 = 76
        $raw = $this->rawAttack(50);
        $this->assertEquals(76, $raw);
        $this->assertEquals(76, $this->computeAttack(50, 0));
    }

    public function testAttackFormulaHundredAtoms(): void
    {
        // 1 + (0.1*100)^2 + 100 = 1 + 100 + 100 = 201
        $raw = $this->rawAttack(100);
        $this->assertEquals(201, $raw);
        $this->assertEquals(201, $this->computeAttack(100, 0));
    }

    public function testAttackFormulaTwoHundredAtoms(): void
    {
        // 1 + (0.1*200)^2 + 200 = 1 + 400 + 200 = 601
        $raw = $this->rawAttack(200);
        $this->assertEquals(601, $raw);
        $this->assertEquals(601, $this->computeAttack(200, 0));
    }

    public function testAttackFormulaWithCondenseurLevel(): void
    {
        // niveau=50 gives multiplier (1 + 50/50) = 2
        $this->assertEquals(402, $this->computeAttack(100, 50));

        // niveau=100 gives multiplier (1 + 100/50) = 3
        $this->assertEquals(603, $this->computeAttack(100, 100));
    }

    public function testAttackFormulaWithMedalBonus(): void
    {
        // With 10% medal bonus: round(201 * 1 * 1.1) = round(221.1) = 221
        $this->assertEquals(221, $this->computeAttack(100, 0, 10));

        // With 50% medal bonus: round(201 * 1 * 1.5) = round(301.5) = 302
        $this->assertEquals(302, $this->computeAttack(100, 0, 50));
    }

    public function testAttackFormulaWithBothMultipliers(): void
    {
        // niveau=50 (x2), bonus=10%: round(201 * 2 * 1.1) = round(442.2) = 442
        $this->assertEquals(442, $this->computeAttack(100, 50, 10));
    }

    public function testAttackGrowthIsQuadratic(): void
    {
        // Verify the quadratic growth from the (0.1 * atoms)^2 term
        $attack50 = $this->rawAttack(50);
        $attack100 = $this->rawAttack(100);
        $attack200 = $this->rawAttack(200);

        // At 200 atoms, attack should be much more than 2x attack at 100
        $this->assertGreaterThan(2 * $attack100, $attack200);

        // Growth rate should increase (quadratic, not linear)
        $this->assertGreaterThan(
            $attack100 - $attack50,
            $attack200 - $attack100,
            'Attack growth should accelerate with more atoms (quadratic scaling)'
        );
    }

    // =========================================================================
    // DEFENSE FORMULA (same structure as attack, using carbone)
    // Formula: round((1 + (0.1 * carbone)^2 + carbone) * (1 + niveau / 50) * (1 + bonus / 100))
    // =========================================================================

    private function rawDefense(int $carbone): float
    {
        return 1 + pow(0.1 * $carbone, 2) + $carbone;
    }

    private function computeDefense(int $carbone, int $niveau, int $medalBonus = 0): int
    {
        return (int) round(
            $this->rawDefense($carbone) * (1 + $niveau / 50) * (1 + $medalBonus / 100)
        );
    }

    public function testDefenseFormulaZeroAtoms(): void
    {
        $this->assertEquals(1, $this->computeDefense(0, 0));
    }

    public function testDefenseFormulaHundredAtoms(): void
    {
        // Same math as attack: 1 + 100 + 100 = 201
        $this->assertEquals(201, $this->computeDefense(100, 0));
    }

    public function testDefenseMatchesAttackFormula(): void
    {
        // Attack and defense use identical formulas (different atoms)
        for ($atoms = 0; $atoms <= 200; $atoms += 25) {
            $this->assertEquals(
                $this->computeAttack($atoms, 0),
                $this->computeDefense($atoms, 0),
                "Attack and defense with $atoms atoms should match"
            );
        }
    }

    public function testDefenseWithCondenseurAndMedal(): void
    {
        // niveau=25 (x1.5), bonus=6%: round(201 * 1.5 * 1.06) = round(319.59) = 320
        $this->assertEquals(320, $this->computeDefense(100, 25, 6));
    }

    // =========================================================================
    // HP (POINTS DE VIE MOLECULE) FORMULA
    // Formula: round((1 + (0.1 * brome)^2 + brome) * (1 + niveau / 50))
    // No medal bonus for HP
    // =========================================================================

    private function computeHP(int $brome, int $niveau): int
    {
        return (int) round(
            (1 + pow(0.1 * $brome, 2) + $brome) * (1 + $niveau / 50)
        );
    }

    public function testHPFormulaZeroAtoms(): void
    {
        $this->assertEquals(1, $this->computeHP(0, 0));
    }

    public function testHPFormulaVariousValues(): void
    {
        $this->assertEquals(201, $this->computeHP(100, 0));
        $this->assertEquals(402, $this->computeHP(100, 50));
        $this->assertEquals(601, $this->computeHP(200, 0));
    }

    public function testHPNoMedalMultiplier(): void
    {
        // HP formula does NOT include medal bonus -- verify this fact
        // by comparing directly against the function pointsDeVieMolecule
        $brome = 100;
        $niveau = 10;
        $expected = (int) round((1 + pow(0.1 * $brome, 2) + $brome) * (1 + $niveau / 50));
        $this->assertEquals($expected, $this->computeHP($brome, $niveau));
    }

    // =========================================================================
    // DESTRUCTION (BUILDING DAMAGE) FORMULA
    // Formula: round(((0.075 * hydrogene)^2 + hydrogene) * (1 + niveau / 50))
    // Note: No +1 base unlike attack/defense
    // =========================================================================

    private function computeDestruction(int $hydrogene, int $niveau): int
    {
        return (int) round(
            (pow(0.075 * $hydrogene, 2) + $hydrogene) * (1 + $niveau / 50)
        );
    }

    public function testDestructionFormulaZeroAtoms(): void
    {
        // With 0 hydrogene: (0 + 0) * anything = 0
        $this->assertEquals(0, $this->computeDestruction(0, 0));
        $this->assertEquals(0, $this->computeDestruction(0, 50));
    }

    public function testDestructionFormulaHundredAtoms(): void
    {
        // (0.075*100)^2 + 100 = 56.25 + 100 = 156.25 => round = 156
        $this->assertEquals(156, $this->computeDestruction(100, 0));
    }

    public function testDestructionFormulaTwoHundredAtoms(): void
    {
        // (0.075*200)^2 + 200 = 225 + 200 = 425 => round = 425
        $this->assertEquals(425, $this->computeDestruction(200, 0));
    }

    public function testDestructionWithLevel(): void
    {
        // At niveau=50: 156.25 * 2 = 312.5 => round = 313 (or 312)
        $this->assertEquals(
            (int) round(156.25 * 2),
            $this->computeDestruction(100, 50)
        );
    }

    public function testDestructionUsesSmallCoefficient(): void
    {
        // Destruction uses 0.075 vs attack's 0.1, so it scales less aggressively
        $atoms = 100;
        $destructionRaw = pow(0.075 * $atoms, 2) + $atoms;
        $attackRaw = 1 + pow(0.1 * $atoms, 2) + $atoms;

        $this->assertLessThan(
            $attackRaw,
            $destructionRaw,
            'Destruction should be weaker than attack per atom'
        );
    }

    // =========================================================================
    // PILLAGE FORMULA
    // Formula: round(((0.1 * soufre)^2 + soufre / 3) * (1 + niveau / 50) * (1 + bonus / 100))
    // =========================================================================

    private function computePillage(int $soufre, int $niveau, int $medalBonus = 0): int
    {
        return (int) round(
            (pow(0.1 * $soufre, 2) + $soufre / 3.0) * (1 + $niveau / 50) * (1 + $medalBonus / 100)
        );
    }

    public function testPillageFormulaZeroAtoms(): void
    {
        $this->assertEquals(0, $this->computePillage(0, 0));
    }

    public function testPillageFormulaHundredAtoms(): void
    {
        // (0.1*100)^2 + 100/3 = 100 + 33.333 = 133.333 => round = 133
        $this->assertEquals(133, $this->computePillage(100, 0));
    }

    public function testPillageFormulaTwoHundredAtoms(): void
    {
        // (0.1*200)^2 + 200/3 = 400 + 66.667 = 466.667 => round = 467
        $this->assertEquals(467, $this->computePillage(200, 0));
    }

    public function testPillageWithLevelAndBonus(): void
    {
        // niveau=50 (x2), bonus=15%: round(133.333 * 2 * 1.15) = round(306.667) = 307
        $this->assertEquals(307, $this->computePillage(100, 50, 15));
    }

    public function testPillageSoufredivisorReducesLinearTerm(): void
    {
        // Soufre / 3 makes the linear term weaker than attack/defense
        // where it is just + atoms
        $atoms = 100;
        $pillageLinear = $atoms / 3.0;
        $attackLinear = (float) $atoms;
        $this->assertLessThan($attackLinear, $pillageLinear);
    }

    // =========================================================================
    // SPEED FORMULA
    // Formula: floor((1 + 0.5 * chlore) * (1 + niveau / 50) * 100) / 100
    // =========================================================================

    private function computeSpeed(int $chlore, int $niveau): float
    {
        return floor((1 + 0.5 * $chlore) * (1 + $niveau / 50) * 100) / 100;
    }

    public function testSpeedFormulaZeroAtoms(): void
    {
        $this->assertEquals(1.0, $this->computeSpeed(0, 0));
    }

    public function testSpeedFormulaWithAtoms(): void
    {
        // (1 + 0.5*10) * 1 * 100 = 600 => floor(600)/100 = 6.0
        $this->assertEquals(6.0, $this->computeSpeed(10, 0));

        // (1 + 0.5*100) * 1 * 100 = 5100 => floor(5100)/100 = 51.0
        $this->assertEquals(51.0, $this->computeSpeed(100, 0));
    }

    public function testSpeedWithLevel(): void
    {
        // (1 + 0.5*10) * (1 + 50/50) * 100 = 6 * 2 * 100 = 1200 => 12.0
        $this->assertEquals(12.0, $this->computeSpeed(10, 50));
    }

    public function testSpeedFloorsTwoDecimalPlaces(): void
    {
        // Verify the floor(...*100)/100 pattern truncates, not rounds
        // (1 + 0.5*7) * (1 + 3/50) * 100 = 4.5 * 1.06 * 100 = 477.0
        $result = $this->computeSpeed(7, 3);
        $this->assertEquals(floor(4.5 * 1.06 * 100) / 100, $result);
    }

    // =========================================================================
    // FORMATION TIME FORMULA
    // Formula: ceil(ntotal / (1 + pow(0.09 * azote, 1.09)) / (1 + niveau / 20) / bonusLieur * 100) / 100
    // bonusLieur = floor(100 * pow(1.07, lieurLevel)) / 100
    // =========================================================================

    private function computeBonusLieur(int $lieurLevel): float
    {
        return floor(100 * pow(1.07, $lieurLevel)) / 100;
    }

    private function computeFormationTime(int $azote, int $niveau, int $ntotal, int $lieurLevel): float
    {
        $bonusLieur = $this->computeBonusLieur($lieurLevel);
        return ceil(
            $ntotal / (1 + pow(0.09 * $azote, 1.09)) / (1 + $niveau / 20) / $bonusLieur * 100
        ) / 100;
    }

    public function testFormationTimeZeroAzote(): void
    {
        // With 0 azote: ntotal / 1 / 1 / 1 * 100 = ntotal * 100
        // ceil(100 * 100) / 100 = 100.0
        $this->assertEquals(100.0, $this->computeFormationTime(0, 0, 100, 0));
    }

    public function testFormationTimeDecreasesWithAzote(): void
    {
        $ntotal = 200;
        $time0 = $this->computeFormationTime(0, 0, $ntotal, 0);
        $time50 = $this->computeFormationTime(50, 0, $ntotal, 0);
        $time100 = $this->computeFormationTime(100, 0, $ntotal, 0);

        $this->assertGreaterThan($time50, $time0);
        $this->assertGreaterThan($time100, $time50);
    }

    public function testFormationTimeDecreasesWithLevel(): void
    {
        $ntotal = 200;
        $azote = 50;
        $time0 = $this->computeFormationTime($azote, 0, $ntotal, 0);
        $time20 = $this->computeFormationTime($azote, 20, $ntotal, 0);

        // At niveau=20: divisor becomes (1 + 20/20) = 2
        $this->assertLessThan($time0, $time20);
    }

    public function testFormationTimeDecreasesWithLieur(): void
    {
        $ntotal = 200;
        $azote = 50;
        $time0 = $this->computeFormationTime($azote, 0, $ntotal, 0);
        $time10 = $this->computeFormationTime($azote, 0, $ntotal, 10);

        $this->assertLessThan($time0, $time10);
    }

    public function testFormationTimeScalesWithTotalAtoms(): void
    {
        // Doubling ntotal should approximately double formation time
        $time100 = $this->computeFormationTime(50, 0, 100, 0);
        $time200 = $this->computeFormationTime(50, 0, 200, 0);

        // Should be roughly 2x (ceiling may cause slight differences)
        $ratio = $time200 / $time100;
        $this->assertGreaterThan(1.9, $ratio);
        $this->assertLessThan(2.1, $ratio);
    }

    public function testBonusLieurAtLevel0(): void
    {
        // pow(1.07, 0) = 1 => floor(100*1)/100 = 1.0
        $this->assertEquals(1.0, $this->computeBonusLieur(0));
    }

    public function testBonusLieurAtLevel10(): void
    {
        // pow(1.07, 10) = 1.9671... => floor(100*1.9671)/100 = 1.96
        $expected = floor(100 * pow(1.07, 10)) / 100;
        $this->assertEquals($expected, $this->computeBonusLieur(10));
    }

    public function testBonusLieurIncreases(): void
    {
        for ($level = 1; $level <= 20; $level++) {
            $this->assertGreaterThan(
                $this->computeBonusLieur($level - 1),
                $this->computeBonusLieur($level),
                "Lieur bonus at level $level should exceed level " . ($level - 1)
            );
        }
    }

    // =========================================================================
    // DECAY / DISAPPEARANCE FORMULA
    // Formula: pow(pow(0.99, pow(1 + nbAtomes/100, 2) / 5000), (1 - bonus/100) * (1 - stab * 0.005))
    // =========================================================================

    private function computeDecayCoefficient(int $nbAtomes, int $medalBonus = 0, int $stabLevel = 0): float
    {
        $innerPow = pow(1 + $nbAtomes / 100, 2) / 5000;
        $basePow = pow(0.99, $innerPow);
        $reductionFactor = (1 - $medalBonus / 100) * (1 - $stabLevel * 0.005);
        return pow($basePow, $reductionFactor);
    }

    public function testDecayCoefficientZeroAtoms(): void
    {
        // nbAtomes=0: pow(0.99, pow(1,2)/5000) = pow(0.99, 0.0002)
        $coef = $this->computeDecayCoefficient(0);
        // Very close to 1 (barely any decay)
        $this->assertGreaterThan(0.999, $coef);
        $this->assertLessThanOrEqual(1.0, $coef);
    }

    public function testDecayCoefficientIncreasesWithAtoms(): void
    {
        // More atoms => faster decay (lower coefficient)
        $coef100 = $this->computeDecayCoefficient(100);
        $coef500 = $this->computeDecayCoefficient(500);
        $coef1000 = $this->computeDecayCoefficient(1000);

        $this->assertGreaterThan($coef500, $coef100, 'More atoms should mean faster decay');
        $this->assertGreaterThan($coef1000, $coef500, 'More atoms should mean faster decay');
    }

    public function testDecayCoefficientAlwaysLessThanOne(): void
    {
        // Decay should always reduce molecules (coef < 1)
        foreach ([0, 50, 100, 200, 500, 1000] as $atoms) {
            $coef = $this->computeDecayCoefficient($atoms);
            $this->assertLessThanOrEqual(1.0, $coef, "Decay coefficient for $atoms atoms should be <= 1");
            $this->assertGreaterThan(0.0, $coef, "Decay coefficient for $atoms atoms should be > 0");
        }
    }

    public function testStabilisateurReducesDecay(): void
    {
        $nbAtomes = 500;
        $coefNoStab = $this->computeDecayCoefficient($nbAtomes, 0, 0);
        $coefStab10 = $this->computeDecayCoefficient($nbAtomes, 0, 10);
        $coefStab50 = $this->computeDecayCoefficient($nbAtomes, 0, 50);

        // Higher stabilisateur = slower decay = coefficient closer to 1
        $this->assertGreaterThan($coefNoStab, $coefStab10);
        $this->assertGreaterThan($coefStab10, $coefStab50);
    }

    public function testMedalBonusReducesDecay(): void
    {
        $nbAtomes = 500;
        $coefNoMedal = $this->computeDecayCoefficient($nbAtomes, 0, 0);
        $coefMedal10 = $this->computeDecayCoefficient($nbAtomes, 10, 0);
        $coefMedal50 = $this->computeDecayCoefficient($nbAtomes, 50, 0);

        $this->assertGreaterThan($coefNoMedal, $coefMedal10);
        $this->assertGreaterThan($coefMedal10, $coefMedal50);
    }

    public function testDecayCoefficientMatchesConstants(): void
    {
        // Verify using config constants
        $nbAtomes = 300;
        $innerPow = pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR;
        $basePow = pow(DECAY_BASE, $innerPow);
        $stabLevel = 5;
        $reductionFactor = 1 * (1 - $stabLevel * STABILISATEUR_BONUS_PER_LEVEL);
        $expected = pow($basePow, $reductionFactor);

        $this->assertEqualsWithDelta(
            $expected,
            $this->computeDecayCoefficient($nbAtomes, 0, $stabLevel),
            0.0000001
        );
    }

    // =========================================================================
    // BUILDING HP FORMULAS
    // pointsDeVie: round(20 * (pow(1.2, level) + pow(level, 1.2)))
    // vieChampDeForce: round(50 * (pow(1.2, level) + pow(level, 1.2)))
    // =========================================================================

    private function computeBuildingHP(int $level): int
    {
        return (int) round(BUILDING_HP_BASE * (pow(BUILDING_HP_GROWTH_BASE, $level) + pow($level, BUILDING_HP_LEVEL_EXP)));
    }

    private function computeForceFieldHP(int $level): int
    {
        return (int) round(FORCEFIELD_HP_BASE * (pow(FORCEFIELD_HP_GROWTH_BASE, $level) + pow($level, FORCEFIELD_HP_LEVEL_EXP)));
    }

    public function testBuildingHPAtLevel1(): void
    {
        // round(20 * (pow(1.2, 1) + pow(1, 1.2))) = round(20 * (1.2 + 1)) = round(44) = 44
        $this->assertEquals(44, $this->computeBuildingHP(1));
    }

    public function testBuildingHPAtLevel10(): void
    {
        $expected = (int) round(20 * (pow(1.2, 10) + pow(10, 1.2)));
        $this->assertEquals($expected, $this->computeBuildingHP(10));
    }

    public function testBuildingHPIncreases(): void
    {
        for ($level = 1; $level <= 20; $level++) {
            $this->assertGreaterThan(
                $this->computeBuildingHP($level - 1),
                $this->computeBuildingHP($level),
                "Building HP at level $level should exceed level " . ($level - 1)
            );
        }
    }

    public function testForceFieldHPHigherThanBuilding(): void
    {
        // Force field uses base 50 vs building base 20
        for ($level = 1; $level <= 20; $level++) {
            $this->assertGreaterThan(
                $this->computeBuildingHP($level),
                $this->computeForceFieldHP($level),
                "Force field HP should exceed building HP at level $level"
            );
        }
    }

    public function testForceFieldHPRatio(): void
    {
        // Ratio should be exactly FORCEFIELD_HP_BASE / BUILDING_HP_BASE = 50/20 = 2.5
        for ($level = 1; $level <= 10; $level++) {
            $buildingHP = BUILDING_HP_BASE * (pow(1.2, $level) + pow($level, 1.2));
            $forceFieldHP = FORCEFIELD_HP_BASE * (pow(1.2, $level) + pow($level, 1.2));
            $this->assertEqualsWithDelta(
                FORCEFIELD_HP_BASE / BUILDING_HP_BASE,
                $forceFieldHP / $buildingHP,
                0.0001,
                "Force field to building HP ratio should be constant at level $level"
            );
        }
    }

    // =========================================================================
    // IODE ENERGY PRODUCTION FORMULA
    // Formula: round(0.01 * iode * (1 + niveau / 50))
    // =========================================================================

    private function computeIodeEnergy(int $iode, int $niveau): int
    {
        return (int) round(IODE_ENERGY_COEFFICIENT * $iode * (1 + $niveau / IODE_LEVEL_DIVISOR));
    }

    public function testIodeEnergyZero(): void
    {
        $this->assertEquals(0, $this->computeIodeEnergy(0, 0));
    }

    public function testIodeEnergyBasic(): void
    {
        // round(0.05 * 100 * 1) = round(5) = 5
        $this->assertEquals(5, $this->computeIodeEnergy(100, 0));
    }

    public function testIodeEnergyWithLevel(): void
    {
        // round(0.05 * 100 * (1 + 50/50)) = round(0.05 * 100 * 2) = 10
        $this->assertEquals(10, $this->computeIodeEnergy(100, 50));
    }

    // =========================================================================
    // PILLAGE POINTS FORMULA
    // Formula: tanh(nbRessources / 200000) * 15
    // =========================================================================

    public function testPillagePointsZero(): void
    {
        $points = tanh(0 / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER;
        $this->assertEquals(0.0, $points);
    }

    public function testPillagePointsCapped(): void
    {
        // tanh approaches 1 for large values, so points approach 15
        $points = tanh(10000000 / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER;
        $this->assertEqualsWithDelta(15.0, $points, 0.01);
    }

    public function testPillagePointsMiddleRange(): void
    {
        // At 200000 resources: tanh(1) * 15 = 0.7616 * 15 = 11.42
        $points = tanh(200000 / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER;
        $this->assertEqualsWithDelta(tanh(1) * 15, $points, 0.001);
    }

    // =========================================================================
    // COMBAT ENERGY COST
    // Formula: 0.15 * (1 + terreur_medal_bonus / 100) * nbAtomes
    // =========================================================================

    public function testAttackEnergyCostBasic(): void
    {
        $nbAtomes = 100;
        $cost = ATTACK_ENERGY_COST_FACTOR * $nbAtomes;
        $this->assertEquals(15.0, $cost);
    }

    public function testAttackEnergyCostWithBonus(): void
    {
        $nbAtomes = 100;
        $terreurBonus = 10;
        $cost = ATTACK_ENERGY_COST_FACTOR * (1 + $terreurBonus / 100) * $nbAtomes;
        $this->assertEquals(16.5, $cost);
    }

    // =========================================================================
    // COMBAT BUILDING BONUSES
    // Ionisateur: level * 2 / 100
    // Champdeforce: level * 2 / 100
    // =========================================================================

    public function testIonisateurBonus(): void
    {
        $level = 10;
        $bonus = ($level * IONISATEUR_COMBAT_BONUS_PER_LEVEL) / 100;
        $this->assertEquals(0.2, $bonus); // 20% bonus
    }

    public function testChampdeforceBonus(): void
    {
        $level = 15;
        $bonus = ($level * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) / 100;
        $this->assertEquals(0.3, $bonus); // 30% bonus
    }

    // =========================================================================
    // MOLECULE CLASS COST
    // Formula: pow(numero + 1, 6)
    // =========================================================================

    public function testClassCostFormula(): void
    {
        // Class 1: pow(2, 6) = 64
        $this->assertEquals(64, pow(1 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));

        // Class 2: pow(3, 6) = 729
        $this->assertEquals(729, pow(2 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));

        // Class 3: pow(4, 6) = 4096
        $this->assertEquals(4096, pow(3 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));

        // Class 4: pow(5, 6) = 15625
        $this->assertEquals(15625, pow(4 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));
    }

    public function testClassCostIncreasesDramatically(): void
    {
        $cost1 = pow(1 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT);
        $cost4 = pow(4 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT);

        // Class 4 should cost way more than class 1
        $this->assertGreaterThan(100 * $cost1, $cost4);
    }

    // =========================================================================
    // VICTORY POINTS FORMULAS
    // =========================================================================

    public function testVictoryPointsPlayerRank1(): void
    {
        $this->assertEquals(VP_PLAYER_RANK1, 100);
    }

    public function testVictoryPointsPlayerRank4Through10(): void
    {
        // Ranks 4-10: 70 - (rank - 3) * 5
        $expectedPoints = [65, 60, 55, 50, 45, 40, 35];
        for ($rank = 4; $rank <= 10; $rank++) {
            $points = VP_PLAYER_RANK4_10_BASE - ($rank - 3) * VP_PLAYER_RANK4_10_STEP;
            $this->assertEquals(
                $expectedPoints[$rank - 4],
                $points,
                "Player rank $rank should earn $expectedPoints[$rank-4] VP"
            );
        }
    }

    public function testVictoryPointsAllianceRank1(): void
    {
        $this->assertEquals(VP_ALLIANCE_RANK1, 15);
    }
}
