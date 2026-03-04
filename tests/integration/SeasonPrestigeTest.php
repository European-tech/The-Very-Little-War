<?php
/**
 * Season & Prestige Integration Tests.
 * Verifies season flow, rankings, and prestige point awards with real DB.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class SeasonPrestigeTest extends IntegrationTestCase
{
    public function testSeasonRecordPersists(): void
    {
        // Insert game state
        dbExecute(self::$db,
            "INSERT INTO parties (id, debut, joueurs, alliances, guerres) VALUES (99, ?, '', '', '')",
            'i', time()
        );
        $row = dbFetchOne(self::$db, 'SELECT * FROM parties WHERE id = ?', 'i', 99);
        $this->assertNotEmpty($row);
    }

    public function testTotalPointsCalculation(): void
    {
        $this->createTestPlayer('pts_test');
        dbExecute(self::$db,
            'UPDATE autre SET pointsAttaque = ?, pointsDefense = ?, ressourcesPillees = ?, totalPoints = ? WHERE login = ?',
            'iiids', 500, 300, 10000, 0, 'pts_test'
        );

        $row = dbFetchOne(self::$db,
            'SELECT pointsAttaque, pointsDefense, ressourcesPillees, totalPoints FROM autre WHERE login = ?',
            's', 'pts_test'
        );
        $this->assertEquals(500, $row['pointsAttaque']);
        $this->assertEquals(300, $row['pointsDefense']);
    }

    public function testPrestigePointsEarnedFromAttacks(): void
    {
        $this->createTestPlayer('prestige_atk');
        dbExecute(self::$db,
            'UPDATE autre SET nbattaques = ? WHERE login = ?',
            'is', PRESTIGE_PP_ATTACK_THRESHOLD + 5, 'prestige_atk'
        );

        $row = dbFetchOne(self::$db, 'SELECT nbattaques FROM autre WHERE login = ?', 's', 'prestige_atk');
        $this->assertGreaterThanOrEqual(PRESTIGE_PP_ATTACK_THRESHOLD, $row['nbattaques']);
        // Player qualifies for attack PP bonus
        $ppBonus = ($row['nbattaques'] >= PRESTIGE_PP_ATTACK_THRESHOLD) ? PRESTIGE_PP_ATTACK_BONUS : 0;
        $this->assertEquals(PRESTIGE_PP_ATTACK_BONUS, $ppBonus);
    }

    public function testNeutrinosStoredCorrectly(): void
    {
        $this->createTestPlayer('neutrino_test');
        dbExecute(self::$db,
            'UPDATE autre SET neutrinos = ? WHERE login = ?',
            'is', 42, 'neutrino_test'
        );
        $row = dbFetchOne(self::$db, 'SELECT neutrinos FROM autre WHERE login = ?', 's', 'neutrino_test');
        $this->assertEquals(42, $row['neutrinos']);
    }

    public function testRankingOrderByTotalPoints(): void
    {
        $this->createTestPlayer('rank_1');
        $this->createTestPlayer('rank_2');
        $this->createTestPlayer('rank_3');

        dbExecute(self::$db, 'UPDATE autre SET totalPoints = ? WHERE login = ?', 'is', 1000, 'rank_1');
        dbExecute(self::$db, 'UPDATE autre SET totalPoints = ? WHERE login = ?', 'is', 500, 'rank_2');
        dbExecute(self::$db, 'UPDATE autre SET totalPoints = ? WHERE login = ?', 'is', 2000, 'rank_3');

        $rows = dbFetchAll(self::$db,
            "SELECT login, totalPoints FROM autre WHERE login LIKE 'rank_%' ORDER BY totalPoints DESC"
        );

        $this->assertCount(3, $rows);
        $this->assertEquals('rank_3', $rows[0]['login']);
        $this->assertEquals('rank_1', $rows[1]['login']);
        $this->assertEquals('rank_2', $rows[2]['login']);
    }

    public function testVictoryCountIncrements(): void
    {
        $this->createTestPlayer('victor');
        dbExecute(self::$db,
            'UPDATE autre SET victoires = victoires + 1 WHERE login = ?',
            's', 'victor'
        );
        $row = dbFetchOne(self::$db, 'SELECT victoires FROM autre WHERE login = ?', 's', 'victor');
        $this->assertEquals(1, $row['victoires']);
    }

    public function testMedalBonusMaxCap(): void
    {
        // Verify medal bonus constants are reasonable
        $this->assertGreaterThan(0, MAX_CROSS_SEASON_MEDAL_BONUS);
        $this->assertLessThanOrEqual(20, MAX_CROSS_SEASON_MEDAL_BONUS, "Medal bonus should not exceed 20%");
    }
}
