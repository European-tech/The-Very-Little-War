<?php
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that use a real database.
 * Each test runs in a transaction that is rolled back in tearDown.
 *
 * DB Schema note: uses actual production column names:
 * - membre: login, pass_md5, timestamp, ip, derniereConnexion, email, x, y
 * - autre: login, points, idalliance, totalPoints, etc.
 * - constructions: login, generateur, producteur, depot, etc.
 * - ressources: login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode
 * - molecules: proprietaire, numeroclasse, carbone, azote, etc.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static $db;

    public static function setUpBeforeClass(): void
    {
        global $base;
        self::$db = $base;
    }

    protected function setUp(): void
    {
        self::$db->begin_transaction();
    }

    protected function tearDown(): void
    {
        self::$db->rollback();
    }

    /**
     * Insert a test player with all required related records.
     * Returns ['login' => ..., 'id' => ...]
     */
    protected function createTestPlayer(string $login, array $overrides = []): array
    {
        $defaults = [
            'pass_md5' => password_hash('testpass123', PASSWORD_DEFAULT),
            'email' => $login . '@test.com',
            'timestamp' => time(),
            'ip' => '127.0.0.' . rand(1, 254),
            'x' => rand(1, 50),
            'y' => rand(1, 50),
        ];
        $data = array_merge($defaults, $overrides);

        // Insert into membre
        dbExecute(self::$db,
            'INSERT INTO membre (login, pass_md5, email, timestamp, derniereConnexion, ip, x, y) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'ssssisss',
            $login, $data['pass_md5'], $data['email'],
            $data['timestamp'], $data['timestamp'],
            $data['ip'], (string)$data['x'], (string)$data['y']
        );
        $id = self::$db->insert_id;

        // Create related records
        dbExecute(self::$db, 'INSERT INTO autre (login) VALUES (?)', 's', $login);
        dbExecute(self::$db, 'INSERT INTO constructions (login) VALUES (?)', 's', $login);
        dbExecute(self::$db, 'INSERT INTO ressources (login) VALUES (?)', 's', $login);

        return ['login' => $login, 'id' => $id];
    }

    /**
     * Set a building level for a player.
     */
    protected function setBuildingLevel(string $login, string $building, int $level): void
    {
        $allowed = ['generateur', 'producteur', 'depot', 'champdeforce',
                     'ionisateur', 'condenseur', 'lieur', 'stabilisateur'];
        if (!in_array($building, $allowed)) {
            throw new \InvalidArgumentException("Unknown building: $building");
        }
        dbExecute(self::$db,
            "UPDATE constructions SET $building = ? WHERE login = ?", 'is', $level, $login
        );
    }

    /**
     * Set resource amounts for a player.
     */
    protected function setResources(string $login, array $resources): void
    {
        $validCols = ['energie', 'carbone', 'azote', 'hydrogene', 'oxygene',
                       'chlore', 'soufre', 'brome', 'iode'];
        foreach ($resources as $name => $amount) {
            if (!in_array($name, $validCols)) {
                throw new \InvalidArgumentException("Unknown resource: $name");
            }
            dbExecute(self::$db,
                "UPDATE ressources SET $name = ? WHERE login = ?", 'ds', (float)$amount, $login
            );
        }
    }

    /**
     * Create a molecule class for a player.
     */
    protected function createMoleculeClass(string $login, int $classNum, array $atoms, int $count = 100): int
    {
        dbExecute(self::$db,
            'INSERT INTO molecules (proprietaire, numeroclasse, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode, nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'siiiiiiiiid',
            $login, $classNum,
            $atoms['carbone'] ?? 0, $atoms['azote'] ?? 0,
            $atoms['hydrogene'] ?? 0, $atoms['oxygene'] ?? 0,
            $atoms['chlore'] ?? 0, $atoms['soufre'] ?? 0,
            $atoms['brome'] ?? 0, $atoms['iode'] ?? 0,
            (float)$count
        );
        return self::$db->insert_id;
    }
}
