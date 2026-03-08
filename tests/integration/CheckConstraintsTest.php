<?php
/**
 * CHECK Constraint Integration Tests.
 * Verifies that DB CHECK constraints reject invalid values (e.g. negative resources).
 * Requires a live test database — skipped automatically if none is available.
 *
 * LOW-028: Cover CHECK constraints added in migration 0017.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class CheckConstraintsTest extends IntegrationTestCase
{
    /**
     * Attempting to INSERT a negative energie value must be rejected by the DB
     * CHECK constraint (ressources.energie >= 0).
     */
    public function testNegativeEnergieRejected(): void
    {
        if (!self::$db) {
            $this->markTestSkipped('No live DB connection available.');
        }

        $this->expectException(\Exception::class);
        dbExecute(
            self::$db,
            'INSERT INTO ressources (login, energie) VALUES (?, ?)',
            'sd',
            'constraint_check_test_' . uniqid(),
            -1.0
        );
    }

    /**
     * Attempting to INSERT a negative carbone value must be rejected.
     */
    public function testNegativeCarboneRejected(): void
    {
        if (!self::$db) {
            $this->markTestSkipped('No live DB connection available.');
        }

        $this->expectException(\Exception::class);
        dbExecute(
            self::$db,
            'INSERT INTO ressources (login, carbone) VALUES (?, ?)',
            'sd',
            'constraint_check_test_' . uniqid(),
            -5.0
        );
    }

    /**
     * A zero value for energie must be accepted (boundary condition).
     */
    public function testZeroEnergieAccepted(): void
    {
        if (!self::$db) {
            $this->markTestSkipped('No live DB connection available.');
        }

        $login = 'constraint_zero_test_' . uniqid();
        // Insert parent membre row first so FK is satisfied
        $this->createTestPlayer($login);

        // Update to zero — should not throw
        dbExecute(self::$db, 'UPDATE ressources SET energie = 0 WHERE login = ?', 's', $login);

        $row = dbFetchOne(self::$db, 'SELECT energie FROM ressources WHERE login = ?', 's', $login);
        $this->assertEquals(0.0, (float)$row['energie'], 'Zero energie should be stored as 0');
    }

    /**
     * Attempting to set a negative building level (e.g. champdeforce) must be rejected.
     * Migration 0017 adds CHECK (champdeforce >= 0) and similar constraints.
     */
    public function testNegativeBuildingLevelRejected(): void
    {
        if (!self::$db) {
            $this->markTestSkipped('No live DB connection available.');
        }

        $this->expectException(\Exception::class);
        dbExecute(
            self::$db,
            'INSERT INTO constructions (login, champdeforce) VALUES (?, ?)',
            'si',
            'constraint_check_bld_' . uniqid(),
            -1
        );
    }
}
