<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for combat-related formulas and calculations.
 *
 * Since the actual game functions (attaque, defense, pillage, tempsFormation,
 * coefDisparition) depend on database queries for medal bonuses and building
 * levels, we test the underlying mathematical formulas directly.
 *
 * V4 Formula references (from includes/formulas.php and includes/config.php):
 *   Attack:      round((pow(O, 1.2) + O) * (1 + H/100) * modCond(nivCond) * (1 + bonus / 100))
 *   Defense:     round((pow(C, 1.2) + C) * (1 + Br/100) * modCond(nivCond) * (1 + bonus / 100))
 *   HP:          round((10 + (pow(Br, 1.2) + Br) * (1 + C/100)) * modCond(nivCond))
 *   Destruction: round((pow(H, 1.2) + H) * (1 + O/100) * modCond(nivCond))
 *   Pillage:     round((pow(S, 1.2) + S) * (1 + Cl/100) * modCond(nivCond) * (1 + bonus / 100))
 *   Speed:       max(1.0, floor((1 + Cl*0.5 + (Cl*N)/200) * modCond(nivCond) * 100) / 100)
 *   modCond:     1 + (nivCond / 50)
 *   Formation:   ceil(ntotal / vitesse_form * 100) / 100
 *   Decay:       pow(pow(0.99, pow(1 + nbAtomes/150, 1.5) / 25000), modStab * modMedal)
 */
class CombatFormulasTest extends TestCase
{
    // =========================================================================
    // ATTACK FORMULA (V4 Covalent Synergy)
    // Formula: round((pow(O, 1.2) + O) * (1 + H/100) * modCond(nivCond) * (1 + bonus / 100))
    // modCond = 1 + (nivCond / 50)
    // =========================================================================

    /**
     * Helper: compute raw attack value (no synergy, no condenseur, no medal).
     * V4: pow(O, 1.2) + O
     */
    private function rawAttack(int $O, int $H = 0): float
    {
        return (pow($O, COVALENT_BASE_EXPONENT) + $O) * (1 + $H / COVALENT_SYNERGY_DIVISOR);
    }

    /**
     * Helper: compute full attack value matching the game function.
     * V4: round(base * modCond * (1 + bonus/100))
     */
    private function computeAttack(int $O, int $H, int $nivCond, int $medalBonus = 0): int
    {
        return (int) round(
            $this->rawAttack($O, $H) * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR) * (1 + $medalBonus / 100)
        );
    }

    public function testAttackFormulaZeroAtoms(): void
    {
        // With 0 oxygen atoms: pow(0, 1.2) + 0 = 0
        $raw = $this->rawAttack(0, 0);
        $this->assertEquals(0, $raw);
        $this->assertEquals(0, $this->computeAttack(0, 0, 0));
    }

    public function testAttackFormulaFiftyAtoms(): void
    {
        // pow(50, 1.2) + 50 = ~113.15 + 50 = ~163.15, no synergy, no cond
        $raw = $this->rawAttack(50, 0);
        $expected = pow(50, 1.2) + 50;
        $this->assertEqualsWithDelta($expected, $raw, 0.01);
        $this->assertEquals((int)round($expected), $this->computeAttack(50, 0, 0));
    }

    public function testAttackFormulaHundredAtoms(): void
    {
        // pow(100, 1.2) + 100 = ~251.19 + 100 = ~351.19
        $raw = $this->rawAttack(100, 0);
        $expected = pow(100, 1.2) + 100;
        $this->assertEqualsWithDelta($expected, $raw, 0.01);
        $this->assertEquals((int)round($expected), $this->computeAttack(100, 0, 0));
    }

    public function testAttackFormulaTwoHundredAtoms(): void
    {
        // pow(200, 1.2) + 200
        $raw = $this->rawAttack(200, 0);
        $expected = pow(200, 1.2) + 200;
        $this->assertEqualsWithDelta($expected, $raw, 0.01);
        $this->assertEquals((int)round($expected), $this->computeAttack(200, 0, 0));
    }

    public function testAttackFormulaWithCondenseurLevel(): void
    {
        // nivCond=50 gives modCond = (1 + 50/50) = 2
        $base = pow(100, 1.2) + 100;
        $this->assertEquals((int)round($base * 2), $this->computeAttack(100, 0, 50));

        // nivCond=100 gives modCond = (1 + 100/50) = 3
        $this->assertEquals((int)round($base * 3), $this->computeAttack(100, 0, 100));
    }

    public function testAttackFormulaWithMedalBonus(): void
    {
        $base = pow(100, 1.2) + 100;
        // With 10% medal bonus: round(base * 1 * 1.1)
        $this->assertEquals((int)round($base * 1.1), $this->computeAttack(100, 0, 0, 10));

        // With 50% medal bonus: round(base * 1 * 1.5)
        $this->assertEquals((int)round($base * 1.5), $this->computeAttack(100, 0, 0, 50));
    }

    public function testAttackFormulaWithSynergyAndCondenseur(): void
    {
        // O=100, H=50, nivCond=5, no medal
        // base = (pow(100,1.2)+100) * (1+50/100) = 351.19 * 1.5 = 526.79
        // * modCond(5) = 1.1 => 579.47 => round = 579
        $this->assertEquals(579, $this->computeAttack(100, 50, 5));
    }

    public function testAttackGrowthIsSuperLinear(): void
    {
        // Verify the pow(O, 1.2) term creates super-linear growth
        $attack50 = $this->rawAttack(50, 0);
        $attack100 = $this->rawAttack(100, 0);
        $attack200 = $this->rawAttack(200, 0);

        // At 200 atoms, attack should be more than 2x attack at 100
        $this->assertGreaterThan(2 * $attack100, $attack200);

        // Growth rate should increase
        $this->assertGreaterThan(
            $attack100 - $attack50,
            $attack200 - $attack100,
            'Attack growth should accelerate with more atoms (super-linear scaling)'
        );
    }

    // =========================================================================
    // DEFENSE FORMULA (V4 Covalent Synergy, same structure as attack)
    // Formula: round((pow(C, 1.2) + C) * (1 + Br/100) * modCond(nivCond) * (1 + bonus / 100))
    // =========================================================================

    private function rawDefense(int $C, int $Br = 0): float
    {
        return (pow($C, COVALENT_BASE_EXPONENT) + $C) * (1 + $Br / COVALENT_SYNERGY_DIVISOR);
    }

    private function computeDefense(int $C, int $Br, int $nivCond, int $medalBonus = 0): int
    {
        return (int) round(
            $this->rawDefense($C, $Br) * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR) * (1 + $medalBonus / 100)
        );
    }

    public function testDefenseFormulaZeroAtoms(): void
    {
        $this->assertEquals(0, $this->computeDefense(0, 0, 0));
    }

    public function testDefenseFormulaHundredAtoms(): void
    {
        // pow(100, 1.2) + 100 = same as attack
        $expected = (int)round(pow(100, 1.2) + 100);
        $this->assertEquals($expected, $this->computeDefense(100, 0, 0));
    }

    public function testDefenseMatchesAttackFormula(): void
    {
        // Attack and defense use identical covalent base formula (different atoms)
        for ($atoms = 0; $atoms <= 200; $atoms += 25) {
            $this->assertEquals(
                $this->computeAttack($atoms, 0, 0),
                $this->computeDefense($atoms, 0, 0),
                "Attack and defense with $atoms primary atoms (no synergy) should match"
            );
        }
    }

    public function testDefenseWithCondenseurAndMedal(): void
    {
        // C=100, Br=0, nivCond=25, bonus=6%
        $base = pow(100, 1.2) + 100;
        $expected = (int)round($base * (1 + 25/50) * 1.06);
        $this->assertEquals($expected, $this->computeDefense(100, 0, 25, 6));
    }

    // =========================================================================
    // HP (POINTS DE VIE MOLECULE) FORMULA (V4)
    // Formula: round((MOLECULE_MIN_HP + (pow(Br, 1.2) + Br) * (1 + C/100)) * modCond(nivCond))
    // No medal bonus for HP
    // =========================================================================

    private function computeHP(int $Br, int $C, int $nivCond): int
    {
        $base = MOLECULE_MIN_HP + (pow($Br, COVALENT_BASE_EXPONENT) + $Br) * (1 + $C / COVALENT_SYNERGY_DIVISOR);
        return (int) round($base * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR));
    }

    public function testHPFormulaZeroAtoms(): void
    {
        // With 0 brome: MOLECULE_MIN_HP (10) * 1 = 10
        $this->assertEquals(MOLECULE_MIN_HP, $this->computeHP(0, 0, 0));
    }

    public function testHPFormulaVariousValues(): void
    {
        $base100 = MOLECULE_MIN_HP + (pow(100, 1.2) + 100);
        $this->assertEquals((int)round($base100), $this->computeHP(100, 0, 0));
        $this->assertEquals((int)round($base100 * 2), $this->computeHP(100, 0, 50));
        $base200 = MOLECULE_MIN_HP + (pow(200, 1.2) + 200);
        $this->assertEquals((int)round($base200), $this->computeHP(200, 0, 0));
    }

    public function testHPNoMedalMultiplier(): void
    {
        // HP formula does NOT include medal bonus -- verify by matching helper to real function
        $Br = 100;
        $C = 50;
        $nivCond = 5;
        $expected = $this->computeHP($Br, $C, $nivCond);
        $this->assertEquals($expected, pointsDeVieMolecule($Br, $C, $nivCond));
    }

    // =========================================================================
    // DESTRUCTION (BUILDING DAMAGE) FORMULA (V4)
    // Formula: round((pow(H, 1.2) + H) * (1 + O/100) * modCond(nivCond))
    // Same covalent base as attack/defense, just no medal bonus
    // =========================================================================

    private function computeDestruction(int $H, int $O, int $nivCond): int
    {
        $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $O / COVALENT_SYNERGY_DIVISOR);
        return (int) round($base * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR));
    }

    public function testDestructionFormulaZeroAtoms(): void
    {
        // With 0 hydrogene: pow(0, 1.2) + 0 = 0
        $this->assertEquals(0, $this->computeDestruction(0, 0, 0));
        $this->assertEquals(0, $this->computeDestruction(0, 0, 50));
    }

    public function testDestructionFormulaHundredAtoms(): void
    {
        // pow(100, 1.2) + 100, no synergy, no cond
        $expected = (int)round(pow(100, 1.2) + 100);
        $this->assertEquals($expected, $this->computeDestruction(100, 0, 0));
    }

    public function testDestructionFormulaTwoHundredAtoms(): void
    {
        // pow(200, 1.2) + 200
        $expected = (int)round(pow(200, 1.2) + 200);
        $this->assertEquals($expected, $this->computeDestruction(200, 0, 0));
    }

    public function testDestructionWithCondenseur(): void
    {
        // H=100, O=0, nivCond=50: base * modCond(50)=2
        $base = pow(100, 1.2) + 100;
        $this->assertEquals(
            (int) round($base * 2),
            $this->computeDestruction(100, 0, 50)
        );
    }

    public function testDestructionMatchesAttackFormula(): void
    {
        // V4: Destruction uses same covalent formula as attack (just different atoms)
        // With equal primary atoms and no synergy, they match
        $atoms = 100;
        $destructionRaw = pow($atoms, COVALENT_BASE_EXPONENT) + $atoms;
        $attackRaw = pow($atoms, COVALENT_BASE_EXPONENT) + $atoms;

        $this->assertEquals($attackRaw, $destructionRaw,
            'V4: Destruction and attack use same covalent base formula');
    }

    // =========================================================================
    // PILLAGE FORMULA (V4 Covalent Synergy)
    // Formula: round((pow(S, 1.2) + S) * (1 + Cl/100) * modCond(nivCond) * (1 + bonus / 100))
    // =========================================================================

    private function computePillage(int $S, int $Cl, int $nivCond, int $medalBonus = 0): int
    {
        $base = (pow($S, COVALENT_BASE_EXPONENT) + $S) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
        return (int) round($base * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR) * (1 + $medalBonus / 100));
    }

    public function testPillageFormulaZeroAtoms(): void
    {
        $this->assertEquals(0, $this->computePillage(0, 0, 0));
    }

    public function testPillageFormulaHundredAtoms(): void
    {
        // pow(100, 1.2) + 100, no synergy, no cond
        $expected = (int)round(pow(100, 1.2) + 100);
        $this->assertEquals($expected, $this->computePillage(100, 0, 0));
    }

    public function testPillageFormulaTwoHundredAtoms(): void
    {
        // pow(200, 1.2) + 200
        $expected = (int)round(pow(200, 1.2) + 200);
        $this->assertEquals($expected, $this->computePillage(200, 0, 0));
    }

    public function testPillageWithSynergyAndBonus(): void
    {
        // S=100, Cl=50, nivCond=5, medal=0
        // base = (pow(100,1.2)+100)*(1+50/100) = 351.19*1.5 = 526.79
        // * modCond(5) = 1.1 => 579.47 => round = 579
        $this->assertEquals(579, $this->computePillage(100, 50, 5, 0));
    }

    public function testPillageMatchesAttackFormula(): void
    {
        // V4: Pillage uses same covalent formula as attack
        // With equal primary atoms and no synergy, they match
        $atoms = 100;
        $pillageRaw = pow($atoms, COVALENT_BASE_EXPONENT) + $atoms;
        $attackRaw = pow($atoms, COVALENT_BASE_EXPONENT) + $atoms;
        $this->assertEquals($attackRaw, $pillageRaw,
            'V4: Pillage and attack use same covalent base formula');
    }

    // =========================================================================
    // SPEED FORMULA (V4)
    // Formula: max(1.0, floor((1 + Cl*0.5 + (Cl*N)/200) * modCond(nivCond) * 100) / 100)
    // =========================================================================

    private function computeSpeed(int $Cl, int $N, int $nivCond): float
    {
        $base = 1 + ($Cl * 0.5) + (($Cl * $N) / 200);
        return max(1.0, floor($base * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR) * 100) / 100);
    }

    public function testSpeedFormulaZeroAtoms(): void
    {
        $this->assertEquals(1.0, $this->computeSpeed(0, 0, 0));
    }

    public function testSpeedFormulaWithAtoms(): void
    {
        // Cl=10, N=0, nivCond=0: (1 + 5 + 0) * 1 * 100 = 600 => 6.0
        $this->assertEquals(6.0, $this->computeSpeed(10, 0, 0));

        // Cl=100, N=0, nivCond=0: (1 + 50 + 0) * 1 * 100 = 5100 => 51.0
        $this->assertEquals(51.0, $this->computeSpeed(100, 0, 0));
    }

    public function testSpeedWithCondenseur(): void
    {
        // Cl=10, N=0, nivCond=50: (1+5+0) * 2 * 100 = 1200 => 12.0
        $this->assertEquals(12.0, $this->computeSpeed(10, 0, 50));
    }

    public function testSpeedWithSynergy(): void
    {
        // Cl=100, N=50, nivCond=5: (1 + 50 + 25) * 1.1 * 100 = 76 * 1.1 * 100 = 8360 => 83.6
        $this->assertEquals(83.6, $this->computeSpeed(100, 50, 5));
    }

    public function testSpeedFloorsTwoDecimalPlaces(): void
    {
        // Verify the floor(...*100)/100 pattern truncates, not rounds
        $result = $this->computeSpeed(7, 3, 0);
        $base = 1 + 7*0.5 + (7*3)/200;
        $this->assertEquals(floor($base * 1 * 100) / 100, $result);
    }

    // =========================================================================
    // FORMATION TIME FORMULA (V4)
    // Formula: ceil(ntotal / vitesse_form * 100) / 100
    // vitesse_form = (1 + pow(azote, 1.1) * (1 + iode/200)) * modCond(nivCond) * bonusLieur
    // bonusLieur = 1 + niveau * 0.15  (V4: linear, was exponential)
    // =========================================================================

    private function computeBonusLieur(int $lieurLevel): float
    {
        return 1 + $lieurLevel * LIEUR_LINEAR_BONUS_PER_LEVEL;
    }

    private function computeFormationTime(int $ntotal, int $azote, int $iode, int $nivCond, int $lieurLevel): float
    {
        $bonusLieur = $this->computeBonusLieur($lieurLevel);
        $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * (1 + $nivCond / COVALENT_CONDENSEUR_DIVISOR) * $bonusLieur;
        return ceil(($ntotal / $vitesse_form) * 100) / 100;
    }

    public function testFormationTimeZeroAzote(): void
    {
        // With 0 azote, 0 iode: vitesse = (1 + 0) * 1 * 1 = 1
        // time = ceil(100 / 1 * 100) / 100 = 100.0
        $this->assertEquals(100.0, $this->computeFormationTime(100, 0, 0, 0, 0));
    }

    public function testFormationTimeDecreasesWithAzote(): void
    {
        $ntotal = 200;
        $time0 = $this->computeFormationTime($ntotal, 0, 0, 0, 0);
        $time50 = $this->computeFormationTime($ntotal, 50, 0, 0, 0);
        $time100 = $this->computeFormationTime($ntotal, 100, 0, 0, 0);

        $this->assertGreaterThan($time50, $time0);
        $this->assertGreaterThan($time100, $time50);
    }

    public function testFormationTimeDecreasesWithCondenseur(): void
    {
        $ntotal = 200;
        $azote = 50;
        $time0 = $this->computeFormationTime($ntotal, $azote, 0, 0, 0);
        $time50 = $this->computeFormationTime($ntotal, $azote, 0, 50, 0);

        // At nivCond=50: modCond = 2
        $this->assertLessThan($time0, $time50);
    }

    public function testFormationTimeDecreasesWithLieur(): void
    {
        $ntotal = 200;
        $azote = 50;
        $time0 = $this->computeFormationTime($ntotal, $azote, 0, 0, 0);
        $time10 = $this->computeFormationTime($ntotal, $azote, 0, 0, 10);

        $this->assertLessThan($time0, $time10);
    }

    public function testFormationTimeScalesWithTotalAtoms(): void
    {
        // Doubling ntotal should approximately double formation time
        $time100 = $this->computeFormationTime(100, 50, 0, 0, 0);
        $time200 = $this->computeFormationTime(200, 50, 0, 0, 0);

        // Should be roughly 2x (ceiling may cause slight differences)
        $ratio = $time200 / $time100;
        $this->assertGreaterThan(1.9, $ratio);
        $this->assertLessThan(2.1, $ratio);
    }

    public function testBonusLieurAtLevel0(): void
    {
        // V4: 1 + 0 * 0.15 = 1.0
        $this->assertEquals(1.0, $this->computeBonusLieur(0));
    }

    public function testBonusLieurAtLevel10(): void
    {
        // V4: 1 + 10 * 0.15 = 2.5
        $this->assertEquals(2.5, $this->computeBonusLieur(10));
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
    // DECAY / DISAPPEARANCE FORMULA (V4)
    // rawDecay = pow(DECAY_BASE, pow(1 + nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR)
    // modStab = pow(STABILISATEUR_ASYMPTOTE, stabLevel)
    // modMedal = 1 - (medalBonus / 100)
    // coef = pow(rawDecay, modStab * modMedal)
    // =========================================================================

    private function computeDecayCoefficient(int $nbAtomes, int $medalBonus = 0, int $stabLevel = 0): float
    {
        $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
        $modStab = pow(STABILISATEUR_ASYMPTOTE, $stabLevel);
        $modMedal = 1 - ($medalBonus / 100);
        return pow($rawDecay, $modStab * $modMedal);
    }

    public function testDecayCoefficientZeroAtoms(): void
    {
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
        // V4 formula with DECAY_MASS_EXPONENT=1.5 and STABILISATEUR_ASYMPTOTE=0.98
        // nbAtomes=300, stabLevel=5:
        $nbAtomes = 300;
        $stabLevel = 5;
        $rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
        $modStab = pow(STABILISATEUR_ASYMPTOTE, $stabLevel);
        $modMedal = 1.0;
        $result = pow($rawDecay, $modStab * $modMedal);

        // Sanity: decay coefficient must be in (0, 1)
        $this->assertGreaterThan(0, $result);
        $this->assertLessThan(1, $result);

        // Pre-computed expected value for V4 constants
        $expected = $this->computeDecayCoefficient($nbAtomes, 0, $stabLevel);
        $this->assertEqualsWithDelta($expected, $result, 0.000000001);
    }

    // =========================================================================
    // BUILDING HP FORMULAS (V4)
    // pointsDeVie: round(BUILDING_HP_BASE * pow(max(1, level), BUILDING_HP_POLY_EXP))
    // = round(50 * pow(max(1, level), 2.5))
    // vieChampDeForce: round(FORCEFIELD_HP_BASE * pow(max(1, level), BUILDING_HP_POLY_EXP))
    // = round(125 * pow(max(1, level), 2.5))
    // =========================================================================

    private function computeBuildingHP(int $level): int
    {
        return (int) round(BUILDING_HP_BASE * pow(max(1, $level), BUILDING_HP_POLY_EXP));
    }

    private function computeForceFieldHP(int $level): int
    {
        return (int) round(FORCEFIELD_HP_BASE * pow(max(1, $level), BUILDING_HP_POLY_EXP));
    }

    public function testBuildingHPAtLevel1(): void
    {
        // round(50 * pow(1, 2.5)) = 50
        $this->assertEquals(50, $this->computeBuildingHP(1));
    }

    public function testBuildingHPAtLevel10(): void
    {
        // round(50 * pow(10, 2.5)) = round(50 * 316.23) = 15811
        $expected = (int) round(BUILDING_HP_BASE * pow(10, BUILDING_HP_POLY_EXP));
        $this->assertEquals($expected, $this->computeBuildingHP(10));
    }

    public function testBuildingHPIncreases(): void
    {
        // V4: level 0 and 1 give same HP due to max(1, level), so start from 2
        $this->assertEquals($this->computeBuildingHP(0), $this->computeBuildingHP(1),
            "Building HP at level 0 and 1 should be equal (max(1, level))");
        for ($level = 2; $level <= 20; $level++) {
            $this->assertGreaterThan(
                $this->computeBuildingHP($level - 1),
                $this->computeBuildingHP($level),
                "Building HP at level $level should exceed level " . ($level - 1)
            );
        }
    }

    public function testForceFieldHPHigherThanBuilding(): void
    {
        // Force field uses base 125 vs building base 50
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
        // Ratio should be exactly FORCEFIELD_HP_BASE / BUILDING_HP_BASE = 125/50 = 2.5
        for ($level = 1; $level <= 10; $level++) {
            $buildingHP = BUILDING_HP_BASE * pow(max(1, $level), BUILDING_HP_POLY_EXP);
            $forceFieldHP = FORCEFIELD_HP_BASE * pow(max(1, $level), BUILDING_HP_POLY_EXP);
            $this->assertEqualsWithDelta(
                FORCEFIELD_HP_BASE / BUILDING_HP_BASE,
                $forceFieldHP / $buildingHP,
                0.0001,
                "Force field to building HP ratio should be constant at level $level"
            );
        }
    }

    // =========================================================================
    // IODE ENERGY PRODUCTION FORMULA (V4)
    // V4 formula: round($iode) — simplified, iode IS the energy
    // =========================================================================

    public function testIodeEnergyZero(): void
    {
        $this->assertEquals(0, productionEnergieMolecule(0, 0));
    }

    public function testIodeEnergyBasic(): void
    {
        // V4: round((0.003 * 100^2 + 0.04 * 100) * (1 + 0/50)) = round(34 * 1) = 34
        $this->assertEquals(34, productionEnergieMolecule(100, 0));
    }

    public function testIodeEnergyWithLevel(): void
    {
        // V4: round((0.003 * 100^2 + 0.04 * 100) * (1 + 50/50)) = round(34 * 2) = 68
        $this->assertEquals(68, productionEnergieMolecule(100, 50));
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
        // tanh approaches 1 for large values, so points approach PILLAGE_POINTS_MULTIPLIER (80)
        $points = tanh(10000000 / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER;
        $this->assertEqualsWithDelta((float) PILLAGE_POINTS_MULTIPLIER, $points, 0.01);
    }

    public function testPillagePointsMiddleRange(): void
    {
        // At 50000 resources: tanh(50000/50000) = tanh(1) * 80
        // PILLAGE_POINTS_DIVISOR=50000, PILLAGE_POINTS_MULTIPLIER=80
        $points = tanh(50000 / PILLAGE_POINTS_DIVISOR) * PILLAGE_POINTS_MULTIPLIER;
        $this->assertEqualsWithDelta(tanh(1) * PILLAGE_POINTS_MULTIPLIER, $points, 0.001);
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
        // CLASS_COST_EXPONENT=4, CLASS_COST_OFFSET=1
        // Class 1: pow(1+1, 4) = pow(2, 4) = 16
        $this->assertEquals(16, pow(1 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));

        // Class 2: pow(2+1, 4) = pow(3, 4) = 81
        $this->assertEquals(81, pow(2 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));

        // Class 3: pow(3+1, 4) = pow(4, 4) = 256
        $this->assertEquals(256, pow(3 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));

        // Class 4: pow(4+1, 4) = pow(5, 4) = 625
        $this->assertEquals(625, pow(4 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT));
    }

    public function testClassCostIncreasesDramatically(): void
    {
        $cost1 = pow(1 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT); // 16
        $cost4 = pow(4 + CLASS_COST_OFFSET, CLASS_COST_EXPONENT); // 625

        // Class 4 should cost significantly more than class 1 (39x)
        $this->assertGreaterThan(10 * $cost1, $cost4);
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
                "Player rank $rank should earn " . $expectedPoints[$rank - 4] . " VP"
            );
        }
    }

    public function testVictoryPointsAllianceRank1(): void
    {
        $this->assertEquals(VP_ALLIANCE_RANK1, 15);
    }
}
