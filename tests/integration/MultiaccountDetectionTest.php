<?php
/**
 * Multiaccount Detection Integration Tests.
 * Verifies IP matching and coordinated attack detection with real DB.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class MultiaccountDetectionTest extends IntegrationTestCase
{
    public function testSameIPDetection(): void
    {
        $this->createTestPlayer('multi_ip_1', ['ip' => '192.168.1.100']);
        $this->createTestPlayer('multi_ip_2', ['ip' => '192.168.1.100']);
        $this->createTestPlayer('multi_ip_3', ['ip' => '10.0.0.1']);

        $sameIP = dbFetchAll(self::$db,
            'SELECT login FROM membre WHERE ip = ? AND login != ?',
            'ss', '192.168.1.100', 'multi_ip_1'
        );

        $this->assertCount(1, $sameIP);
        $this->assertEquals('multi_ip_2', $sameIP[0]['login']);
    }

    public function testDifferentIPsNotFlagged(): void
    {
        $this->createTestPlayer('clean_1', ['ip' => '10.0.0.1']);
        $this->createTestPlayer('clean_2', ['ip' => '10.0.0.2']);

        $sameIP = dbFetchAll(self::$db,
            'SELECT login FROM membre WHERE ip = ? AND login != ?',
            'ss', '10.0.0.1', 'clean_1'
        );
        $this->assertCount(0, $sameIP);
    }

    public function testIPColumnPopulated(): void
    {
        $this->createTestPlayer('ip_test', ['ip' => '172.16.0.1']);
        $row = dbFetchOne(self::$db, 'SELECT ip FROM membre WHERE login = ?', 's', 'ip_test');
        $this->assertEquals('172.16.0.1', $row['ip']);
    }

    public function testCoordinatedAttackDetection(): void
    {
        $this->createTestPlayer('coord_atk_1');
        $this->createTestPlayer('coord_atk_2');
        $this->createTestPlayer('coord_def');

        $now = time();
        // Two attacks on same target within 1 hour
        dbExecute(self::$db,
            'INSERT INTO actionsattaques (attaquant, defenseur, tempsAttaque, troupes, attaqueFaite) VALUES (?, ?, ?, ?, 0)',
            'ssis', 'coord_atk_1', 'coord_def', $now + 100, '100'
        );
        dbExecute(self::$db,
            'INSERT INTO actionsattaques (attaquant, defenseur, tempsAttaque, troupes, attaqueFaite) VALUES (?, ?, ?, ?, 0)',
            'ssis', 'coord_atk_2', 'coord_def', $now + 200, '100'
        );

        // Find coordinated attacks: different attackers, same target, within 1 hour
        $coordinated = dbFetchAll(self::$db,
            'SELECT DISTINCT a1.attaquant AS player1, a2.attaquant AS player2
             FROM actionsattaques a1
             JOIN actionsattaques a2 ON a1.defenseur = a2.defenseur
                AND a1.attaquant != a2.attaquant
                AND ABS(a1.tempsAttaque - a2.tempsAttaque) < ?',
            'i', SECONDS_PER_HOUR
        );

        $this->assertGreaterThanOrEqual(1, count($coordinated));
    }
}
