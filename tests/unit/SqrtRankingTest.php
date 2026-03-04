<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/formulas.php';

class SqrtRankingTest extends TestCase
{
    public function testBalancedPlayerBeatsSpecialist()
    {
        // Pure builder: 10000 construction, 0 everything else
        $builder = calculerTotalPoints(10000, 0, 0, 0, 0);
        // Balanced: 2000 each category
        $balanced = calculerTotalPoints(2000, 2000, 2000, 2000, 2000);
        // Balanced should win (sqrt rewards diversity)
        $this->assertGreaterThan($builder, $balanced);
    }

    public function testSqrtPreventsLinearSnowball()
    {
        $small = calculerTotalPoints(100, 100, 100, 100, 100);
        $big = calculerTotalPoints(10000, 10000, 10000, 10000, 10000);
        // 100x more raw points should NOT give 100x more ranking
        $ratio = $big / $small;
        $this->assertLessThan(20, $ratio); // sqrt(100x) ≈ 10x
        $this->assertGreaterThan(5, $ratio); // but still significantly more
    }

    public function testZeroPointsGivesZero()
    {
        $this->assertEquals(0, calculerTotalPoints(0, 0, 0, 0, 0));
    }

    public function testNegativePointsTreatedAsZero()
    {
        $this->assertEquals(0, calculerTotalPoints(-100, -100, -100, -100, -100));
    }

    public function testSingleCategoryContribution()
    {
        // Only construction: 1.0 * sqrt(100) = 10
        $this->assertEquals(10, calculerTotalPoints(100, 0, 0, 0, 0));
    }

    public function testAttackWeightHigherThanConstruction()
    {
        // Same raw points, but attack should contribute more due to 1.5x weight
        $constructionOnly = calculerTotalPoints(1000, 0, 0, 0, 0);
        $attackOnly = calculerTotalPoints(0, 1000, 0, 0, 0);
        $this->assertGreaterThan($constructionOnly, $attackOnly);
    }

    public function testDefenseWeightHigherThanConstruction()
    {
        $constructionOnly = calculerTotalPoints(1000, 0, 0, 0, 0);
        $defenseOnly = calculerTotalPoints(0, 0, 1000, 0, 0);
        $this->assertGreaterThan($constructionOnly, $defenseOnly);
    }

    public function testPillageWeightBetweenConstructionAndAttack()
    {
        $constructionOnly = calculerTotalPoints(1000, 0, 0, 0, 0);
        $pillageOnly = calculerTotalPoints(0, 0, 0, 0, 1000);
        $attackOnly = calculerTotalPoints(0, 1000, 0, 0, 0);
        // pillage weight 1.2 > construction weight 1.0
        $this->assertGreaterThan($constructionOnly, $pillageOnly);
        // pillage weight 1.2 < attack weight 1.5
        $this->assertGreaterThan($pillageOnly, $attackOnly);
    }

    public function testDiminishingReturns()
    {
        // Going from 0→1000 should give more marginal benefit than 1000→2000
        $base = calculerTotalPoints(0, 0, 0, 0, 0);
        $first1000 = calculerTotalPoints(1000, 0, 0, 0, 0) - $base;
        $second1000 = calculerTotalPoints(2000, 0, 0, 0, 0) - calculerTotalPoints(1000, 0, 0, 0, 0);
        $this->assertGreaterThan($second1000, $first1000);
    }

    public function testSymmetry()
    {
        // Same total raw points distributed differently
        $allInOne = calculerTotalPoints(5000, 0, 0, 0, 0);
        $spread = calculerTotalPoints(1000, 1000, 1000, 1000, 1000);
        // Spread should be higher (diversification bonus from sqrt)
        $this->assertGreaterThan($allInOne, $spread);
    }

    public function testRealisticScenario()
    {
        // Aggressive player: high attack, low defense
        $aggressive = calculerTotalPoints(500, 800, 100, 200, 600);
        // Defensive player: low attack, high defense
        $defensive = calculerTotalPoints(500, 100, 800, 200, 100);
        // Balanced player: moderate everywhere
        $balanced = calculerTotalPoints(500, 400, 400, 400, 400);
        // All should produce meaningful different scores
        $this->assertGreaterThan(0, $aggressive);
        $this->assertGreaterThan(0, $defensive);
        $this->assertGreaterThan(0, $balanced);
    }

    public function testWeightConstants()
    {
        $this->assertEquals(1.0, RANKING_CONSTRUCTION_WEIGHT);
        $this->assertEquals(1.5, RANKING_ATTACK_WEIGHT);
        $this->assertEquals(1.5, RANKING_DEFENSE_WEIGHT);
        $this->assertEquals(1.0, RANKING_TRADE_WEIGHT);
        $this->assertEquals(1.2, RANKING_PILLAGE_WEIGHT);
        $this->assertEquals(0.5, RANKING_SQRT_EXPONENT);
    }
}
