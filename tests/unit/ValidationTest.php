<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for input validation functions in includes/validation.php.
 *
 * validateLogin() uses preg_match() which returns int (1 or 0), so we cast
 * to bool when asserting truthy/falsy behavior.
 */
class ValidationTest extends TestCase
{
    // =========================================================================
    // LOGIN VALIDATION
    // Pattern: /^[a-zA-Z0-9_]{3,20}$/
    // =========================================================================

    public function testValidateLoginAcceptsValid(): void
    {
        $this->assertTrue((bool) validateLogin('Player1'));
        $this->assertTrue((bool) validateLogin('test_user'));
        $this->assertTrue((bool) validateLogin('abc'));
    }

    public function testValidateLoginRejectsInvalid(): void
    {
        $this->assertFalse((bool) validateLogin('ab'));  // too short (2 chars)
        $this->assertFalse((bool) validateLogin('a'));   // too short (1 char)
        $this->assertFalse((bool) validateLogin(''));    // empty
        $this->assertFalse((bool) validateLogin('user with spaces'));
        $this->assertFalse((bool) validateLogin('user<script>'));
        $this->assertFalse((bool) validateLogin(str_repeat('a', 21))); // too long (21 chars)
    }

    public function testValidateLoginBoundaryLengths(): void
    {
        // Minimum length: exactly 3 chars must be valid
        $this->assertTrue((bool) validateLogin('aaa'));
        $this->assertTrue((bool) validateLogin('a_b'));
        $this->assertTrue((bool) validateLogin('ABC'));

        // 2 chars must be invalid
        $this->assertFalse((bool) validateLogin('aa'));

        // Maximum length: exactly 20 chars must be valid
        $this->assertTrue((bool) validateLogin(str_repeat('a', 20)));

        // 21 chars must be invalid
        $this->assertFalse((bool) validateLogin(str_repeat('a', 21)));
    }

    public function testValidateLoginSqlInjectionAttempts(): void
    {
        $this->assertFalse((bool) validateLogin("' OR 1=1 --"));
        $this->assertFalse((bool) validateLogin("admin'--"));
        $this->assertFalse((bool) validateLogin('"; DROP TABLE'));
        $this->assertFalse((bool) validateLogin('user OR 1=1'));
    }

    public function testValidateLoginUnicodeCharacters(): void
    {
        // Non-ASCII characters are rejected (pattern is [a-zA-Z0-9_] only)
        $this->assertFalse((bool) validateLogin('tëst'));
        $this->assertFalse((bool) validateLogin('用户名'));
        $this->assertFalse((bool) validateLogin('café'));
    }

    public function testValidateLoginSpecialChars(): void
    {
        // Special chars not in [a-zA-Z0-9_] are rejected
        $this->assertFalse((bool) validateLogin('user@domain'));
        $this->assertFalse((bool) validateLogin('user.name'));
        $this->assertFalse((bool) validateLogin('user-name'));
        $this->assertFalse((bool) validateLogin('user name'));
        $this->assertFalse((bool) validateLogin('<script>'));
    }

    public function testValidateLoginAllowsUnderscores(): void
    {
        $this->assertTrue((bool) validateLogin('user_name'));
        $this->assertTrue((bool) validateLogin('_underscore'));
        $this->assertTrue((bool) validateLogin('trailing_'));
        $this->assertTrue((bool) validateLogin('multi__under'));
    }

    // =========================================================================
    // EMAIL VALIDATION
    // Uses FILTER_VALIDATE_EMAIL
    // =========================================================================

    public function testValidateEmailAcceptsValid(): void
    {
        $this->assertTrue(validateEmail('test@example.com'));
        $this->assertTrue(validateEmail('user.name@domain.org'));
        $this->assertTrue(validateEmail('user+tag@example.co.uk'));
    }

    public function testValidateEmailRejectsInvalid(): void
    {
        $this->assertFalse(validateEmail('notanemail'));
        $this->assertFalse(validateEmail(''));
        $this->assertFalse(validateEmail('@domain.com'));
        $this->assertFalse(validateEmail('user@'));
        $this->assertFalse(validateEmail('user @domain.com')); // space
    }

    // =========================================================================
    // POSITIVE INTEGER VALIDATION
    // =========================================================================

    public function testValidatePositiveInt(): void
    {
        $this->assertTrue(validatePositiveInt(1));
        $this->assertTrue(validatePositiveInt(100));
        $this->assertTrue(validatePositiveInt('5'));
        $this->assertFalse(validatePositiveInt(0));
        $this->assertFalse(validatePositiveInt(-1));
        $this->assertFalse(validatePositiveInt('abc'));
    }

    // =========================================================================
    // RANGE VALIDATION
    // =========================================================================

    public function testValidateRange(): void
    {
        $this->assertTrue(validateRange(5, 1, 10));
        $this->assertTrue(validateRange(1, 1, 10));
        $this->assertTrue(validateRange(10, 1, 10));
        $this->assertFalse(validateRange(0, 1, 10));
        $this->assertFalse(validateRange(11, 1, 10));
    }

    // =========================================================================
    // OUTPUT SANITIZATION
    // =========================================================================

    public function testSanitizeOutput(): void
    {
        $this->assertEquals('&lt;script&gt;', sanitizeOutput('<script>'));
        $this->assertEquals('Hello &amp; World', sanitizeOutput('Hello & World'));
        $this->assertEquals('&quot;quoted&quot;', sanitizeOutput('"quoted"'));
    }

    public function testSanitizeOutputXssPatterns(): void
    {
        // Common XSS payloads should be safely escaped
        $this->assertEquals(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            sanitizeOutput('<script>alert(1)</script>')
        );
        $this->assertEquals(
            '&lt;img src=x onerror=alert(1)&gt;',
            sanitizeOutput('<img src=x onerror=alert(1)>')
        );
    }

    public function testSanitizeOutputPreservesNormalText(): void
    {
        $this->assertEquals('Hello World', sanitizeOutput('Hello World'));
        $this->assertEquals('Player123', sanitizeOutput('Player123'));
        $this->assertEquals('123', sanitizeOutput('123'));
    }
}
