<?php
/**
 * Integration test bootstrap — connects to real test database.
 * Each test runs inside a transaction that rolls back on tearDown.
 *
 * SETUP:
 * 1. Create test DB: mysql -u root -e "CREATE DATABASE tvlw_test CHARACTER SET latin1;"
 * 2. Create user: mysql -u root -e "CREATE USER 'tvlw_test'@'localhost' IDENTIFIED BY 'tvlw_test_password'; GRANT ALL ON tvlw_test.* TO 'tvlw_test'@'localhost';"
 * 3. Load schema: mysql -u tvlw_test -ptvlw_test_password tvlw_test < tests/integration/fixtures/base_schema.sql
 * 4. Apply migrations: for f in migrations/*.sql; do mysql -u tvlw_test -ptvlw_test_password tvlw_test < "$f" 2>/dev/null; done
 * 5. Load seeds: mysql -u tvlw_test -ptvlw_test_password tvlw_test < tests/integration/fixtures/seed_players.sql
 */

// Load the same constants/config as the real game
require_once __DIR__ . '/../../includes/constantesBase.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/display.php';
require_once __DIR__ . '/../../includes/formulas.php';
require_once __DIR__ . '/../../includes/database.php';

// Mock session
$_SESSION = [];

// Test DB credentials — env vars or defaults
$testDbHost = getenv('TVLW_TEST_DB_HOST') ?: '127.0.0.1';
$testDbUser = getenv('TVLW_TEST_DB_USER') ?: 'tvlw_test';
$testDbPass = getenv('TVLW_TEST_DB_PASS') ?: 'tvlw_test_password';
$testDbName = getenv('TVLW_TEST_DB_NAME') ?: 'tvlw_test';

// Connect to test database
$base = new mysqli($testDbHost, $testDbUser, $testDbPass, $testDbName);
if ($base->connect_error) {
    echo "Integration test DB connection failed: " . $base->connect_error . "\n";
    echo "Run: bash tests/integration/fixtures/setup_test_db.sh\n";
    echo "Or set env vars: TVLW_TEST_DB_HOST, TVLW_TEST_DB_USER, TVLW_TEST_DB_PASS, TVLW_TEST_DB_NAME\n";
    exit(1);
}
$base->set_charset('utf8mb4');
