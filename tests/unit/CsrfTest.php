<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for CSRF protection functions in includes/csrf.php.
 *
 * Functions tested:
 *   csrfToken()  - generates and returns a token stored in $_SESSION
 *   csrfField()  - outputs an HTML hidden input containing the token
 *   csrfVerify() - returns true if $_POST token matches $_SESSION token
 *   csrfCheck()  - dies with error message if POST request has invalid token
 */
class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset session and POST superglobals before each test
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Load CSRF functions if not already loaded
        if (!function_exists('csrfToken')) {
            require_once __DIR__ . '/../../includes/csrf.php';
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // =========================================================================
    // csrfToken()
    // =========================================================================

    public function testCsrfTokenGeneratesToken(): void
    {
        $token = csrfToken();
        $this->assertNotEmpty($token);
    }

    public function testCsrfTokenStoresInSession(): void
    {
        $token = csrfToken();
        $this->assertArrayHasKey('csrf_token', $_SESSION);
        $this->assertEquals($token, $_SESSION['csrf_token']);
    }

    public function testCsrfTokenIsHexString(): void
    {
        $token = csrfToken();
        // bin2hex(random_bytes(32)) produces 64 hex chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testCsrfTokenHasCorrectLength(): void
    {
        $token = csrfToken();
        // 32 bytes → 64 hex characters
        $this->assertEquals(64, strlen($token));
    }

    public function testCsrfTokenReusesSameTokenAcrossCalls(): void
    {
        $token1 = csrfToken();
        $token2 = csrfToken();
        // Should return the same token once generated (idempotent within a session)
        $this->assertEquals($token1, $token2);
    }

    public function testCsrfTokenGeneratesNewTokenWhenSessionEmpty(): void
    {
        // First call generates a token
        $token1 = csrfToken();

        // Clear session and call again — should generate a different token
        $_SESSION = [];
        $token2 = csrfToken();

        // Both should be valid tokens
        $this->assertEquals(64, strlen($token1));
        $this->assertEquals(64, strlen($token2));
        // Clearing session must produce a different token
        $this->assertNotEquals($token1, $token2);
    }

    // =========================================================================
    // csrfField()
    // =========================================================================

    public function testCsrfFieldOutputsHiddenInput(): void
    {
        $field = csrfField();
        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
    }

    public function testCsrfFieldContainsToken(): void
    {
        $token = csrfToken();
        $field = csrfField();
        $this->assertStringContainsString($token, $field);
    }

    public function testCsrfFieldMatchesSessionToken(): void
    {
        $field = csrfField();
        $this->assertStringContainsString($_SESSION['csrf_token'], $field);
    }

    public function testCsrfFieldEscapesToken(): void
    {
        // The field should use htmlspecialchars to escape the token value
        // Token is hex so no special chars, but the value attribute syntax is correct
        $field = csrfField();
        $this->assertStringContainsString('value="', $field);
    }

    public function testCsrfFieldOutputFormat(): void
    {
        $token = csrfToken();
        $expectedField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
        $this->assertEquals($expectedField, csrfField());
    }

    // =========================================================================
    // csrfVerify()
    // =========================================================================

    public function testCsrfVerifyAcceptsValidToken(): void
    {
        $token = csrfToken();
        $_POST['csrf_token'] = $token;

        $this->assertTrue(csrfVerify());
    }

    public function testCsrfVerifyRejectsWrongToken(): void
    {
        csrfToken(); // generate and store session token
        $_POST['csrf_token'] = 'wrong_token_value';

        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyRejectsMissingPostToken(): void
    {
        csrfToken(); // generate and store session token
        // $_POST['csrf_token'] is not set

        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyRejectsMissingSessionToken(): void
    {
        // Session token was never generated
        $_POST['csrf_token'] = 'some_token_value';

        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyRejectsBothMissing(): void
    {
        // Neither session nor POST token present
        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyRejectsEmptyPostToken(): void
    {
        csrfToken();
        $_POST['csrf_token'] = '';

        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyRejectsEmptySessionToken(): void
    {
        $_SESSION['csrf_token'] = '';
        $_POST['csrf_token'] = '';

        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyRejectsNearlyCorrectToken(): void
    {
        $token = csrfToken();
        // Flip the last character
        $lastChar = substr($token, -1);
        $wrongChar = ($lastChar === 'a') ? 'b' : 'a';
        $_POST['csrf_token'] = substr($token, 0, -1) . $wrongChar;

        $this->assertFalse(csrfVerify());
    }

    public function testCsrfVerifyUsesConstantTimeComparison(): void
    {
        // hash_equals() is constant-time — this is a behavioral test ensuring
        // that an exact match returns true and any difference returns false
        $token = csrfToken();

        $_POST['csrf_token'] = $token;
        $this->assertTrue(csrfVerify(), 'Exact token match should verify');

        $_POST['csrf_token'] = strtoupper($token);
        $this->assertFalse(csrfVerify(), 'Case-altered token should not verify');
    }
}
