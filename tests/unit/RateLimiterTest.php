<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for file-based rate limiter in includes/rate_limiter.php.
 *
 * Functions tested:
 *   rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds)
 *     - returns true if request is allowed, false if rate-limited
 *   rateLimitRemaining($identifier, $action, $maxAttempts, $windowSeconds)
 *     - returns number of attempts remaining in the window
 *
 * Uses a temporary directory isolated per test run to avoid cross-test interference.
 */
class RateLimiterTest extends TestCase
{
    /** @var string Isolated temp directory for this test run */
    private string $testDir;

    protected function setUp(): void
    {
        // Create a unique temp dir for each test to ensure isolation
        $this->testDir = sys_get_temp_dir() . '/tvlw_rate_test_' . uniqid('', true);
        mkdir($this->testDir, 0755, true);

        // Override the RATE_LIMIT_DIR constant by defining it before inclusion
        // We rely on the `if (!defined(...))` guard added to rate_limiter.php
        if (!defined('RATE_LIMIT_DIR')) {
            define('RATE_LIMIT_DIR', $this->testDir);
        }

        if (!function_exists('rateLimitCheck')) {
            require_once __DIR__ . '/../../includes/rate_limiter.php';
        }
    }

    protected function tearDown(): void
    {
        // Clean up all rate limit files created during the test
        if (is_dir($this->testDir)) {
            foreach (glob($this->testDir . '/*.json') as $file) {
                @unlink($file);
            }
            @rmdir($this->testDir);
        }
        // Also clean the actual RATE_LIMIT_DIR if it differs
        $dir = RATE_LIMIT_DIR;
        if ($dir !== $this->testDir && is_dir($dir)) {
            foreach (glob($dir . '/*.json') as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Generate a unique identifier for each test to avoid cross-test interference
     * within the same RATE_LIMIT_DIR.
     */
    private function uniqueKey(string $suffix = ''): string
    {
        return 'test_' . str_replace('.', '_', uniqid('', true)) . $suffix;
    }

    // =========================================================================
    // BASIC ALLOW / BLOCK BEHAVIOR
    // =========================================================================

    public function testAllowsFirstRequest(): void
    {
        $id = $this->uniqueKey();
        $result = rateLimitCheck($id, 'login', 5, 300);
        $this->assertTrue($result, 'First request should be allowed');
    }

    public function testAllowsRequestsUpToLimit(): void
    {
        $id = $this->uniqueKey();
        $action = 'login';
        $max = 3;

        for ($i = 1; $i <= $max; $i++) {
            $result = rateLimitCheck($id, $action, $max, 300);
            $this->assertTrue($result, "Request $i of $max should be allowed");
        }
    }

    public function testBlocksAfterExceedingLimit(): void
    {
        $id = $this->uniqueKey();
        $action = 'login';
        $max = 3;

        // Exhaust the limit
        for ($i = 0; $i < $max; $i++) {
            rateLimitCheck($id, $action, $max, 300);
        }

        // Next request should be blocked
        $result = rateLimitCheck($id, $action, $max, 300);
        $this->assertFalse($result, "Request beyond limit should be blocked");
    }

    public function testBlocksAllSubsequentRequestsAfterLimit(): void
    {
        $id = $this->uniqueKey();
        $action = 'register';
        $max = 2;

        // Exhaust the limit
        rateLimitCheck($id, $action, $max, 300);
        rateLimitCheck($id, $action, $max, 300);

        // Multiple subsequent requests should all be blocked
        $this->assertFalse(rateLimitCheck($id, $action, $max, 300));
        $this->assertFalse(rateLimitCheck($id, $action, $max, 300));
        $this->assertFalse(rateLimitCheck($id, $action, $max, 300));
    }

    // =========================================================================
    // DIFFERENT KEYS ARE INDEPENDENT
    // =========================================================================

    public function testDifferentIdentifiersAreIndependent(): void
    {
        $action = 'login';
        $max = 2;

        $id1 = $this->uniqueKey('_user1');
        $id2 = $this->uniqueKey('_user2');

        // Exhaust limit for id1
        rateLimitCheck($id1, $action, $max, 300);
        rateLimitCheck($id1, $action, $max, 300);
        $this->assertFalse(rateLimitCheck($id1, $action, $max, 300), 'id1 should be blocked');

        // id2 should still be allowed
        $this->assertTrue(rateLimitCheck($id2, $action, $max, 300), 'id2 should be independent');
    }

    public function testDifferentActionsAreIndependent(): void
    {
        $id = $this->uniqueKey();
        $max = 2;

        // Exhaust limit for 'login' action
        rateLimitCheck($id, 'login', $max, 300);
        rateLimitCheck($id, 'login', $max, 300);
        $this->assertFalse(rateLimitCheck($id, 'login', $max, 300), 'login action should be blocked');

        // 'register' action for same id should be independent
        $this->assertTrue(rateLimitCheck($id, 'register', $max, 300), 'register action should be independent');
    }

    public function testMultipleUsersTrackedIndependently(): void
    {
        $action = 'login';
        $max = 3;

        $ids = [$this->uniqueKey('_a'), $this->uniqueKey('_b'), $this->uniqueKey('_c')];

        // Each user makes 2 requests (below limit of 3)
        foreach ($ids as $id) {
            $this->assertTrue(rateLimitCheck($id, $action, $max, 300));
            $this->assertTrue(rateLimitCheck($id, $action, $max, 300));
        }

        // Each user should still have 1 attempt remaining
        foreach ($ids as $id) {
            $this->assertTrue(rateLimitCheck($id, $action, $max, 300), "$id should still be allowed");
        }

        // Now all are exhausted
        foreach ($ids as $id) {
            $this->assertFalse(rateLimitCheck($id, $action, $max, 300), "$id should be blocked");
        }
    }

    // =========================================================================
    // REMAINING ATTEMPTS
    // =========================================================================

    public function testRemainingStartsAtMax(): void
    {
        $id = $this->uniqueKey();
        $max = 5;
        $remaining = rateLimitRemaining($id, 'login', $max, 300);
        $this->assertEquals($max, $remaining, 'Remaining should equal max for fresh identifier');
    }

    public function testRemainingDecreasesAfterEachRequest(): void
    {
        $id = $this->uniqueKey();
        $action = 'login';
        $max = 5;

        for ($used = 1; $used <= $max; $used++) {
            rateLimitCheck($id, $action, $max, 300);
            $remaining = rateLimitRemaining($id, $action, $max, 300);
            $this->assertEquals($max - $used, $remaining, "Remaining should be $max - $used after $used requests");
        }
    }

    public function testRemainingIsZeroWhenExhausted(): void
    {
        $id = $this->uniqueKey();
        $action = 'login';
        $max = 3;

        // Exhaust the limit
        for ($i = 0; $i < $max; $i++) {
            rateLimitCheck($id, $action, $max, 300);
        }

        $remaining = rateLimitRemaining($id, $action, $max, 300);
        $this->assertEquals(0, $remaining, 'Remaining should be 0 when limit exhausted');
    }

    public function testRemainingNeverGoesBelowZero(): void
    {
        $id = $this->uniqueKey();
        $action = 'login';
        $max = 2;

        // Exhaust and then some (blocked attempts don't decrement)
        rateLimitCheck($id, $action, $max, 300);
        rateLimitCheck($id, $action, $max, 300);
        rateLimitCheck($id, $action, $max, 300); // blocked
        rateLimitCheck($id, $action, $max, 300); // blocked

        $remaining = rateLimitRemaining($id, $action, $max, 300);
        $this->assertGreaterThanOrEqual(0, $remaining, 'Remaining should never be negative');
    }

    // =========================================================================
    // WINDOW EXPIRY (uses actual time, no mocking)
    // =========================================================================

    public function testWindowSizeOf1SecondAllowsImmediateReset(): void
    {
        $id = $this->uniqueKey();
        $action = 'login';
        $max = 2;

        // Exhaust a 1-second window
        rateLimitCheck($id, $action, $max, 1);
        rateLimitCheck($id, $action, $max, 1);
        $this->assertFalse(rateLimitCheck($id, $action, $max, 1), 'Should be blocked within window');

        // Wait for window to expire
        sleep(2);

        // Should be allowed again
        $this->assertTrue(rateLimitCheck($id, $action, $max, 1), 'Should be allowed after window expires');
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testMaxAttemptsOfOne(): void
    {
        $id = $this->uniqueKey();
        $action = 'password_reset';

        // With max=1, first attempt is allowed
        $this->assertTrue(rateLimitCheck($id, $action, 1, 300), 'First attempt should be allowed');

        // Second attempt should be blocked immediately
        $this->assertFalse(rateLimitCheck($id, $action, 1, 300), 'Second attempt should be blocked');
    }

    public function testDirectoryIsCreatedIfMissing(): void
    {
        // The function should create its directory if it doesn't exist
        $id = $this->uniqueKey();

        // Remove the directory if it exists to test auto-creation
        if (is_dir(RATE_LIMIT_DIR)) {
            foreach (glob(RATE_LIMIT_DIR . '/*.json') as $f) {
                @unlink($f);
            }
            @rmdir(RATE_LIMIT_DIR);
        }

        $result = rateLimitCheck($id, 'login', 5, 300);
        $this->assertTrue($result, 'Request should succeed even if directory was missing');
        $this->assertDirectoryExists(RATE_LIMIT_DIR, 'Rate limit directory should be created automatically');
    }
}
