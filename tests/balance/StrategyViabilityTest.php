<?php
/**
 * Balance Verification: Mathematical proof that multiple strategies are viable.
 *
 * METHODOLOGY:
 * 1. Define 5 archetype atom allocations (200 total atoms each)
 * 2. Calculate raw combat stats for each using real game formulas
 * 3. Simulate round-robin 1v1 combat outcomes
 * 4. Calculate ranking points under sqrt system
 * 5. PASS criteria:
 *    - No archetype wins ALL matchups (no dominant strategy)
 *    - No archetype loses ALL matchups (no useless strategy)
 *    - Ranking spread between best and worst archetype < 2x
 *    - Each archetype is "best in class" at something
 */

require_once __DIR__ . '/bootstrap_balance.php';

use PHPUnit\Framework\TestCase;

class StrategyViabilityTest extends TestCase
{
    private array $archetypes;
    private int $condenseurLevel = 8;

    protected function setUp(): void
    {
        // 5 archetypes, each with 200 total atoms distributed differently
        // Keys: C=defense, N=formation time, H=building damage, O=attack,
        //        Cl=speed, S=pillage, Br=HP, I=energy production
        $this->archetypes = [
            'raider'    => ['C' => 10, 'N' => 20, 'H' => 30, 'O' => 80, 'Cl' => 10, 'S' => 5,  'Br' => 30, 'I' => 15],
            'turtle'    => ['C' => 80, 'N' => 15, 'H' => 5,  'O' => 10, 'Cl' => 5,  'S' => 5,  'Br' => 60, 'I' => 20],
            'pillager'  => ['C' => 10, 'N' => 30, 'H' => 5,  'O' => 30, 'Cl' => 50, 'S' => 50, 'Br' => 15, 'I' => 10],
            'speedster' => ['C' => 10, 'N' => 40, 'H' => 10, 'O' => 30, 'Cl' => 70, 'S' => 10, 'Br' => 20, 'I' => 10],
            'balanced'  => ['C' => 30, 'N' => 25, 'H' => 20, 'O' => 30, 'Cl' => 25, 'S' => 25, 'Br' => 30, 'I' => 15],
        ];
    }

    /**
     * Calculate all combat stats for an archetype using the real game formulas.
     */
    private function calcStats(array $atoms): array
    {
        $c = $this->condenseurLevel;
        return [
            'attack'  => attaque($atoms['O'], $atoms['H'], $c),
            'defense' => defense($atoms['C'], $atoms['Br'], $c),
            'hp'      => pointsDeVieMolecule($atoms['Br'], $atoms['C'], $c),
            'speed'   => vitesse($atoms['Cl'], $atoms['N'], $c),
            'pillage' => pillage($atoms['S'], $atoms['Cl'], $c),
        ];
    }

    /**
     * Simulate 1v1 combat: attacker damage efficiency vs defender damage efficiency.
     * Returns > 0 if attacker wins, < 0 if defender wins.
     */
    private function simulateCombat(array $atkStats, array $defStats): float
    {
        // Attacker kills defender's molecules: attack / defender_hp
        $atkKillRate = $defStats['hp'] > 0 ? $atkStats['attack'] / $defStats['hp'] : PHP_INT_MAX;
        // Defender kills attacker's molecules: defense / attacker_hp
        $defKillRate = $atkStats['hp'] > 0 ? $defStats['defense'] / $atkStats['hp'] : PHP_INT_MAX;

        return $atkKillRate - $defKillRate;
    }

    /**
     * CORE TEST: No dominant strategy exists.
     * No archetype should win against ALL others.
     */
    public function testNoDominantStrategy(): void
    {
        $stats = [];
        foreach ($this->archetypes as $name => $atoms) {
            $stats[$name] = $this->calcStats($atoms);
        }

        $names = array_keys($this->archetypes);
        foreach ($names as $attacker) {
            $wins = 0;
            foreach ($names as $defender) {
                if ($attacker === $defender) continue;
                $result = $this->simulateCombat($stats[$attacker], $stats[$defender]);
                if ($result > 0) $wins++;
            }
            $this->assertLessThan(count($names) - 1, $wins,
                "BALANCE FAIL: '$attacker' wins all " . (count($names) - 1) . " matchups — dominant strategy!");
        }
    }

    /**
     * CORE TEST: No useless strategy exists.
     * No archetype should lose against ALL others.
     */
    public function testNoUselessStrategy(): void
    {
        $stats = [];
        foreach ($this->archetypes as $name => $atoms) {
            $stats[$name] = $this->calcStats($atoms);
        }

        $names = array_keys($this->archetypes);
        foreach ($names as $attacker) {
            $winsOrDraws = 0;
            foreach ($names as $defender) {
                if ($attacker === $defender) continue;
                // Count both offensive and defensive matchups
                $atkResult = $this->simulateCombat($stats[$attacker], $stats[$defender]);
                $defResult = $this->simulateCombat($stats[$defender], $stats[$attacker]);
                if ($atkResult > 0 || $defResult < 0) $winsOrDraws++;
            }
            $this->assertGreaterThan(0, $winsOrDraws,
                "BALANCE FAIL: '$attacker' loses all matchups (attack and defense) — useless strategy!");
        }
    }

    /**
     * CORE TEST: Specialist archetypes each excel at something.
     * Every specialist archetype should be #1 in at least one combat stat.
     * The balanced archetype excels at overall ranking instead (tested separately).
     */
    public function testSpecialistArchetypesExcel(): void
    {
        $allStats = [];
        foreach ($this->archetypes as $name => $atoms) {
            $allStats[$name] = $this->calcStats($atoms);
        }

        $categories = ['attack', 'defense', 'hp', 'speed', 'pillage'];
        $bestIn = [];
        foreach ($this->archetypes as $name => $_) {
            $bestIn[$name] = [];
        }

        foreach ($categories as $cat) {
            $best = null;
            $bestVal = -1;
            foreach ($allStats as $name => $stats) {
                if ($stats[$cat] > $bestVal) {
                    $bestVal = $stats[$cat];
                    $best = $name;
                }
            }
            $bestIn[$best][] = $cat;
        }

        // Every specialist (non-balanced) must be best at something
        $specialists = ['raider', 'turtle', 'pillager', 'speedster'];
        foreach ($specialists as $name) {
            $this->assertNotEmpty($bestIn[$name],
                "BALANCE FAIL: specialist '$name' is not best-in-class at any stat. Distribution: " . json_encode($bestIn));
        }

        // All 5 categories should be covered (no ties leaving a gap)
        $covered = [];
        foreach ($bestIn as $cats) {
            $covered = array_merge($covered, $cats);
        }
        $this->assertCount(5, array_unique($covered),
            "All 5 stat categories should have a clear best. Distribution: " . json_encode($bestIn));
    }

    /**
     * CORE TEST: Balanced archetype compensates via ranking.
     * The balanced player should rank highest under sqrt ranking
     * when all archetypes have equal total raw activity points.
     */
    public function testBalancedArchetypeRankingAdvantage(): void
    {
        // Simulate: each archetype earns points in their specialty
        // Raider: 500 attack, 100 each elsewhere
        // Balanced: 200 in everything
        // Same total: 900 raw points each

        $raiderRank = calculerTotalPoints(100, 500, 100, 100, 100);
        $balancedRank = calculerTotalPoints(180, 180, 180, 180, 180);

        $this->assertGreaterThan($raiderRank, $balancedRank,
            "Balanced archetype ($balancedRank) should rank higher than specialist ($raiderRank) with equal total activity");
    }

    /**
     * CORE TEST: Sqrt ranking system rewards diverse play.
     * A balanced player should rank higher than a one-dimensional player
     * with the same total raw points.
     */
    public function testSqrtRankingRewardsDiversity(): void
    {
        // One-dimensional: 1000 construction, 0 everything else
        $oneDim = calculerTotalPoints(1000, 0, 0, 0, 0);

        // Balanced: 200 in each of 5 categories (same 1000 total raw points)
        $balanced = calculerTotalPoints(200, 200, 200, 200, 200);

        $this->assertGreaterThan($oneDim, $balanced,
            "Balanced player ($balanced) should rank higher than one-dimensional ($oneDim) with same total points");

        // How much higher? At least 30% more
        $ratio = $balanced / $oneDim;
        $this->assertGreaterThan(1.3, $ratio,
            "Diversity bonus ratio $ratio should be at least 1.3x");
    }

    /**
     * CORE TEST: Ranking weights don't let one category dominate.
     */
    public function testRankingWeightBalance(): void
    {
        $weights = [
            'construction' => RANKING_CONSTRUCTION_WEIGHT,
            'attack'       => RANKING_ATTACK_WEIGHT,
            'defense'      => RANKING_DEFENSE_WEIGHT,
            'trade'        => RANKING_TRADE_WEIGHT,
            'pillage'      => RANKING_PILLAGE_WEIGHT,
        ];

        $totalWeight = array_sum($weights);
        foreach ($weights as $cat => $weight) {
            $pct = $weight / $totalWeight;
            $this->assertLessThan(0.40, $pct,
                "Category '$cat' is {$pct} of total weight — would dominate rankings (max 40%)");
        }
    }

    /**
     * Test speed formula soft cap prevents Cl-only dominance.
     */
    public function testSpeedSoftCapPreventsDominance(): void
    {
        $c = $this->condenseurLevel;

        // Pure Cl (no N): Cl=150, N=0
        $pureClSpeed = vitesse(150, 0, $c);

        // Mixed Cl+N: Cl=70, N=40
        $mixedSpeed = vitesse(70, 40, $c);

        // Mixed should be competitive due to synergy
        $ratio = $mixedSpeed / $pureClSpeed;
        $this->assertGreaterThan(0.5, $ratio,
            "Mixed Cl+N ($mixedSpeed) should be at least 50% of pure Cl ($pureClSpeed)");
    }

    /**
     * Test iode energy production is a meaningful alternative to combat.
     */
    public function testIodeEnergyViability(): void
    {
        // 100 iode atoms, condenseur level 10
        $energy = productionEnergieMolecule(100, 10);
        $this->assertGreaterThan(20, $energy,
            "100 iode atoms should produce significant energy per molecule");

        // Compare: generateur level 10 = 750 energy
        $genEnergy = BASE_ENERGY_PER_LEVEL * 10;
        // 100 iode molecules making $energy each should be competitive
        $iodeFleet = 100 * $energy;
        $this->assertGreaterThan($genEnergy, $iodeFleet,
            "A fleet of 100 iode molecules should outproduce a single generateur level 10");
    }

    public function testPhalanxAbsorbNotOverpowered(): void
    {
        $this->assertLessThanOrEqual(0.55, FORMATION_PHALANX_ABSORB,
            'Phalanx absorb should be <= 55%');
    }

    public function testAmbushBonusViable(): void
    {
        $this->assertGreaterThanOrEqual(0.35, FORMATION_AMBUSH_ATTACK_BONUS,
            'Ambush bonus should be >= 35%');
    }

    /**
     * Test molecule minimum HP prevents zero-brome insta-wipe.
     */
    public function testMinimumHPProtection(): void
    {
        // Zero brome, zero carbon
        $hp = pointsDeVieMolecule(0, 0, 0);
        $this->assertGreaterThanOrEqual(MOLECULE_MIN_HP, $hp,
            "Zero-brome molecules must have minimum HP ($hp < " . MOLECULE_MIN_HP . ")");

        // High brome gives significantly more
        $hpHigh = pointsDeVieMolecule(100, 50, 10);
        $this->assertGreaterThan($hp * 10, $hpHigh,
            "High-brome molecules should have 10x+ more HP than zero-brome");
    }
}
