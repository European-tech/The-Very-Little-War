<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for database connection configuration correctness.
 *
 * These are unit-level sanity checks that verify our connection settings
 * without requiring a live database. They document the expected state and
 * guard against accidental regressions (e.g., someone swapping 'latin1'
 * back to 'utf8mb4').
 *
 * PASS1-CRITICAL-001: connexion.php must set charset latin1, not utf8mb4.
 * The entire schema (all tables, all columns) uses the latin1 character set.
 * Setting a utf8mb4 connection causes MariaDB to silently perform charset
 * conversion on every query — wasting CPU and risking data corruption on any
 * column that stores bytes outside the latin1 range. The fix is to call
 * mysqli_set_charset($base, 'latin1') so the wire encoding matches storage.
 *
 * PASS1-CRITICAL-002: Migration 0026 must use IF NOT EXISTS on its INDEX
 * creation. Migration 0015 already created idx_totalPoints with IF NOT EXISTS.
 * If 0026 runs on a production server where 0015 already ran, the bare
 * ALTER TABLE … ADD INDEX will throw "Duplicate key name 'idx_totalPoints'"
 * and crash the migration runner, preventing all subsequent migrations
 * (0027-0032) from being applied. All migrations from 0027-0030 have also
 * been hardened with IF NOT EXISTS guards.
 *
 * NOTE: No live DB connection is made here. Assertions operate on file
 * contents and configuration constants only.
 */
class DatabaseConnectionTest extends TestCase
{
    private string $connexionFile;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->connexionFile  = __DIR__ . '/../../includes/connexion.php';
        $this->migrationsDir  = __DIR__ . '/../../migrations/';
    }

    // =========================================================================
    // CHARSET CONFIGURATION (PASS1-CRITICAL-001)
    // =========================================================================

    /**
     * Verifies that connexion.php requests a latin1 connection charset.
     *
     * If this test fails it means someone changed the charset back to utf8mb4
     * (or another variant). All tables in the TVLW schema use DEFAULT CHARSET=latin1.
     * A mismatched connection charset forces MariaDB to transcode every value
     * on the wire, which is both slow and can corrupt stored data.
     *
     * Integration equivalent (run manually on the VPS):
     *   SHOW VARIABLES LIKE 'character_set_client';   -- expected: latin1
     *   SHOW VARIABLES LIKE 'character_set_results';  -- expected: latin1
     *   SHOW VARIABLES LIKE 'character_set_connection';-- expected: latin1
     */
    public function testConnexionFileUsesLatin1Charset(): void
    {
        $this->assertFileExists(
            $this->connexionFile,
            'connexion.php must exist'
        );

        $source = file_get_contents($this->connexionFile);

        $this->assertStringContainsString(
            "mysqli_set_charset(\$base, 'latin1')",
            $source,
            'connexion.php must call mysqli_set_charset with latin1 — not utf8mb4 or any other charset'
        );
    }

    public function testConnexionFileDoesNotUseUtf8mb4(): void
    {
        $source = file_get_contents($this->connexionFile);

        $this->assertStringNotContainsString(
            'utf8mb4',
            $source,
            'connexion.php must not reference utf8mb4 — all tables use latin1'
        );
    }

    public function testConnexionFileDoesNotUseUtf8(): void
    {
        $source = file_get_contents($this->connexionFile);

        // 'latin1' contains the substring 'latin1' not 'utf8', so this is safe
        $this->assertStringNotContainsString(
            "'utf8'",
            $source,
            'connexion.php must not use plain utf8 charset either — use latin1'
        );
    }

    // =========================================================================
    // MIGRATION IDEMPOTENCY GUARDS (PASS1-CRITICAL-002)
    // =========================================================================

    /**
     * Verifies migration 0026 uses IF NOT EXISTS on the idx_totalPoints index.
     *
     * testMigration0026IsIdempotent: migration 0026 must be safe to run twice.
     *
     * Scenario that breaks without IF NOT EXISTS:
     *   1. Production server runs migration 0015 (creates idx_totalPoints IF NOT EXISTS).
     *   2. Migration runner runs 0026 — bare ADD INDEX crashes with
     *      "Duplicate key name 'idx_totalPoints'".
     *   3. Runner aborts; migrations 0027-0032 never execute.
     *   4. Features depending on streak_days, comeback_shield_until, season_recap,
     *      alliance unique constraints, and poll tables are silently broken at runtime.
     */
    public function testMigration0026IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0026_add_totalpoints_index.sql';

        $this->assertFileExists($file, 'Migration 0026 must exist');

        $sql = file_get_contents($file);

        // Both ADD INDEX statements must carry IF NOT EXISTS
        $this->assertStringContainsString(
            'ADD INDEX IF NOT EXISTS idx_totalPoints',
            $sql,
            '0026 must use IF NOT EXISTS on idx_totalPoints to be idempotent'
        );
    }

    /**
     * Verifies migration 0027 uses IF NOT EXISTS on all ADD COLUMN statements.
     *
     * MariaDB 10.11 supports ADD COLUMN IF NOT EXISTS. Without it, re-running
     * the migration on a server where 0027 already ran throws
     * "Duplicate column name 'streak_days'" and crashes the runner.
     */
    public function testMigration0027IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0027_add_login_streak.sql';

        $this->assertFileExists($file, 'Migration 0027 must exist');

        $sql = file_get_contents($file);

        $this->assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS streak_days',
            $sql,
            '0027 must use IF NOT EXISTS on streak_days column'
        );

        $this->assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS streak_last_date',
            $sql,
            '0027 must use IF NOT EXISTS on streak_last_date column'
        );
    }

    /**
     * Verifies migration 0028 uses IF NOT EXISTS on all ADD COLUMN statements.
     */
    public function testMigration0028IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0028_add_comeback_tracking.sql';

        $this->assertFileExists($file, 'Migration 0028 must exist');

        $sql = file_get_contents($file);

        $this->assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS last_catch_up',
            $sql,
            '0028 must use IF NOT EXISTS on last_catch_up column'
        );

        $this->assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS comeback_shield_until',
            $sql,
            '0028 must use IF NOT EXISTS on comeback_shield_until column'
        );
    }

    /**
     * Verifies migration 0029 uses CREATE TABLE IF NOT EXISTS.
     */
    public function testMigration0029IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0029_create_season_recap.sql';

        $this->assertFileExists($file, 'Migration 0029 must exist');

        $sql = file_get_contents($file);

        $this->assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS season_recap',
            $sql,
            '0029 must use CREATE TABLE IF NOT EXISTS'
        );
    }

    /**
     * Verifies migration 0030 uses IF NOT EXISTS on both UNIQUE INDEX additions.
     */
    public function testMigration0030IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0030_alliance_unique_constraints.sql';

        $this->assertFileExists($file, 'Migration 0030 must exist');

        $sql = file_get_contents($file);

        $this->assertStringContainsString(
            'ADD UNIQUE INDEX IF NOT EXISTS idx_alliances_tag',
            $sql,
            '0030 must use IF NOT EXISTS on idx_alliances_tag'
        );

        $this->assertStringContainsString(
            'ADD UNIQUE INDEX IF NOT EXISTS idx_alliances_nom',
            $sql,
            '0030 must use IF NOT EXISTS on idx_alliances_nom'
        );
    }

    /**
     * Verifies migration 0031 uses CREATE TABLE IF NOT EXISTS.
     */
    public function testMigration0031IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0031_create_sondages_table.sql';

        $this->assertFileExists($file, 'Migration 0031 must exist');

        $sql = file_get_contents($file);

        $this->assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS sondages',
            $sql,
            '0031 must use CREATE TABLE IF NOT EXISTS'
        );
    }

    /**
     * Verifies migration 0032 uses CREATE TABLE IF NOT EXISTS.
     */
    public function testMigration0032IsIdempotent(): void
    {
        $file = $this->migrationsDir . '0032_create_reponses_sondage.sql';

        $this->assertFileExists($file, 'Migration 0032 must exist');

        $sql = file_get_contents($file);

        $this->assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS reponses_sondage',
            $sql,
            '0032 must use CREATE TABLE IF NOT EXISTS'
        );
    }

    // =========================================================================
    // NEW TABLES USE LATIN1 CHARSET
    // =========================================================================

    /**
     * Verifies that all CREATE TABLE migrations in 0029-0032 specify latin1.
     *
     * New tables must use DEFAULT CHARSET=latin1 for foreign key compatibility
     * with the `membre` table (and all other existing tables). utf8mb4 tables
     * cannot have FK relationships with latin1 tables in MariaDB without
     * explicit CONVERT ON UPDATE / CONVERT ON DELETE clauses, which we do not use.
     */
    public function testNewTablesMigrations0029to0032UseLatinCharset(): void
    {
        $createTableMigrations = [
            '0029_create_season_recap.sql',
            '0031_create_sondages_table.sql',
            '0032_create_reponses_sondage.sql',
        ];

        foreach ($createTableMigrations as $filename) {
            $file = $this->migrationsDir . $filename;

            $this->assertFileExists($file, "$filename must exist");

            $sql = file_get_contents($file);

            $this->assertStringContainsString(
                'CHARSET=latin1',
                $sql,
                "$filename must specify DEFAULT CHARSET=latin1 for FK compatibility with existing tables"
            );

            $this->assertStringNotContainsString(
                'utf8mb4',
                $sql,
                "$filename must not use utf8mb4 — all FK-linked tables use latin1"
            );
        }
    }
}
