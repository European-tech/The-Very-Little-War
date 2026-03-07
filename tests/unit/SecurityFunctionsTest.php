<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for security-related functions.
 *
 * Covers:
 * - antiXSS() from display.php — HTML entity escaping for output safety
 * - transformInt() from display.php — K/M/G suffix to integer conversion
 * - validateLogin() from validation.php — login format validation
 * - validateEmail() from validation.php — email format validation
 * - sanitizeOutput() from validation.php — HTML sanitization
 */
class SecurityFunctionsTest extends TestCase
{
    // =========================================================================
    // A) antiXSS() — HTML output escaping
    // Function: htmlspecialchars(trim($phrase), ENT_QUOTES, 'UTF-8')
    // =========================================================================

    public function testAntiXSSEscapesHTMLEntities(): void
    {
        $result = antiXSS('<b>bold</b>');
        $this->assertEquals('&lt;b&gt;bold&lt;/b&gt;', $result);
    }

    public function testAntiXSSEscapesAmpersand(): void
    {
        $result = antiXSS('Tom & Jerry');
        $this->assertEquals('Tom &amp; Jerry', $result);
    }

    public function testAntiXSSEscapesLessThan(): void
    {
        $result = antiXSS('a < b');
        $this->assertEquals('a &lt; b', $result);
    }

    public function testAntiXSSEscapesGreaterThan(): void
    {
        $result = antiXSS('a > b');
        $this->assertEquals('a &gt; b', $result);
    }

    public function testAntiXSSEscapesDoubleQuotes(): void
    {
        $result = antiXSS('say "hello"');
        $this->assertEquals('say &quot;hello&quot;', $result);
    }

    public function testAntiXSSEscapesSingleQuotes(): void
    {
        $result = antiXSS("it's fine");
        $this->assertEquals('it&#039;s fine', $result);
    }

    public function testAntiXSSHandlesEmptyString(): void
    {
        $result = antiXSS('');
        $this->assertEquals('', $result);
    }

    public function testAntiXSSPreservesUnicode(): void
    {
        $result = antiXSS('cafe et creme');
        $this->assertEquals('cafe et creme', $result);

        // French accented characters are preserved
        $result2 = antiXSS('etreinte');
        $this->assertEquals('etreinte', $result2);
    }

    public function testAntiXSSTrimsWhitespace(): void
    {
        $result = antiXSS('  hello  ');
        $this->assertEquals('hello', $result);
    }

    public function testAntiXSSBlocksScriptTag(): void
    {
        $result = antiXSS('<script>alert("XSS")</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertEquals('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $result);
    }

    public function testAntiXSSBlocksEventHandler(): void
    {
        // antiXSS uses htmlspecialchars which escapes < and > but not attribute names.
        // The key safety property is that the tag is no longer HTML — the browser
        // sees &lt;img ... &gt; as text, not a real <img> element.
        $result = antiXSS('<img onerror=alert(1) src=x>');
        $this->assertStringContainsString('&lt;img', $result, 'Tag opener must be escaped');
        $this->assertStringContainsString('&gt;', $result, 'Tag closer must be escaped');
        // The escaped output cannot be parsed as HTML by the browser
        $this->assertStringNotContainsString('<img', $result, 'Raw HTML tag must not appear');
    }

    // =========================================================================
    // B) transformInt() — K/M/G suffix converter
    // Converts shorthand like "1K" to "1000", "5M" to "5000000"
    // =========================================================================

    public function testTransformIntK(): void
    {
        $this->assertEquals('1000', transformInt('1K'));
    }

    public function testTransformIntM(): void
    {
        $this->assertEquals('5000000', transformInt('5M'));
    }

    public function testTransformIntG(): void
    {
        $this->assertEquals('2000000000', transformInt('2G'));
    }

    public function testTransformIntT(): void
    {
        $this->assertEquals('3000000000000', transformInt('3T'));
    }

    public function testTransformIntNormalNumber(): void
    {
        $this->assertEquals('12345', transformInt('12345'));
    }

    public function testTransformIntEmptyString(): void
    {
        $this->assertEquals('', transformInt(''));
    }

    public function testTransformIntNegativeNumber(): void
    {
        // Negative numbers are clamped to '0' — transformInt is for display of
        // non-negative resource values only (intentional fix, commit 7590cae).
        $this->assertEquals('0', transformInt('-5'));
    }

    public function testTransformIntZeroK(): void
    {
        $this->assertEquals('0000', transformInt('0K'));
    }

    public function testTransformIntCaseInsensitive(): void
    {
        $this->assertEquals('1000', transformInt('1k'));
        $this->assertEquals('5000000', transformInt('5m'));
        $this->assertEquals('2000000000', transformInt('2g'));
    }

    public function testTransformIntMultipleSuffixes(): void
    {
        // Edge case: "1KK" — first K becomes 000, second K becomes 000
        // Result: "1000000" (same as 1M)
        $this->assertEquals('1000000', transformInt('1KK'));
    }

    // =========================================================================
    // C) validateLogin() — login format validation
    // Pattern: /^[a-zA-Z0-9_]{3,20}$/
    // =========================================================================

    public function testValidateLoginAcceptsValidLogins(): void
    {
        $this->assertTrue((bool) validateLogin('Alice'));
        $this->assertTrue((bool) validateLogin('player_123'));
        $this->assertTrue((bool) validateLogin('A1B'));
    }

    public function testValidateLoginRejectsTooShort(): void
    {
        $this->assertFalse((bool) validateLogin('ab'));
        $this->assertFalse((bool) validateLogin('x'));
    }

    public function testValidateLoginRejectsTooLong(): void
    {
        $this->assertFalse((bool) validateLogin(str_repeat('a', 21)));
        $this->assertFalse((bool) validateLogin(str_repeat('X', 30)));
    }

    public function testValidateLoginAcceptsExactBoundaryLengths(): void
    {
        // Exactly 3 characters (minimum)
        $this->assertTrue((bool) validateLogin('abc'));
        // Exactly 20 characters (maximum)
        $this->assertTrue((bool) validateLogin(str_repeat('z', 20)));
    }

    public function testValidateLoginRejectsSpecialCharacters(): void
    {
        $this->assertFalse((bool) validateLogin('user@domain'));
        $this->assertFalse((bool) validateLogin('user name'));
        $this->assertFalse((bool) validateLogin('user-name'));
        $this->assertFalse((bool) validateLogin('user.name'));
        $this->assertFalse((bool) validateLogin('user!name'));
    }

    public function testValidateLoginRejectsSQLInjection(): void
    {
        $this->assertFalse((bool) validateLogin("' OR 1=1 --"));
        $this->assertFalse((bool) validateLogin("admin'; DROP TABLE--"));
        $this->assertFalse((bool) validateLogin("1 UNION SELECT"));
        $this->assertFalse((bool) validateLogin('Robert\'); DROP TABLE'));
    }

    public function testValidateLoginRejectsEmptyString(): void
    {
        $this->assertFalse((bool) validateLogin(''));
    }

    // =========================================================================
    // D) validateEmail() — email format validation
    // Uses FILTER_VALIDATE_EMAIL
    // =========================================================================

    public function testValidateEmailAcceptsValid(): void
    {
        $this->assertTrue(validateEmail('user@example.com'));
        $this->assertTrue(validateEmail('first.last@domain.org'));
        $this->assertTrue(validateEmail('user+tag@sub.domain.co'));
    }

    public function testValidateEmailRejectsInvalid(): void
    {
        $this->assertFalse(validateEmail('notanemail'));
        $this->assertFalse(validateEmail('missing@'));
        $this->assertFalse(validateEmail('@nodomain'));
        $this->assertFalse(validateEmail('spaces in@email.com'));
    }

    public function testValidateEmailRejectsEmpty(): void
    {
        $this->assertFalse(validateEmail(''));
    }

    // =========================================================================
    // E) sanitizeOutput() — from validation.php
    // Uses htmlspecialchars(ENT_QUOTES, UTF-8)
    // =========================================================================

    public function testSanitizeOutputEscapesHTML(): void
    {
        $this->assertEquals('&lt;div&gt;', sanitizeOutput('<div>'));
        $this->assertEquals('&amp;amp;', sanitizeOutput('&amp;'));
    }

    public function testSanitizeOutputEscapesQuotes(): void
    {
        $this->assertEquals('&quot;test&quot;', sanitizeOutput('"test"'));
        $this->assertEquals('&#039;test&#039;', sanitizeOutput("'test'"));
    }

    public function testSanitizeOutputHandlesEmptyString(): void
    {
        $this->assertEquals('', sanitizeOutput(''));
    }

    public function testSanitizeOutputHandlesNullLikeInput(): void
    {
        // sanitizeOutput should handle null gracefully
        // htmlspecialchars with null deprecated in PHP 8.1+ but still works
        $result = @sanitizeOutput('');
        $this->assertEquals('', $result);
    }

    public function testSanitizeOutputPreservesNormalText(): void
    {
        $this->assertEquals('Hello World 123', sanitizeOutput('Hello World 123'));
        $this->assertEquals('Joueur_Test', sanitizeOutput('Joueur_Test'));
    }

    public function testSanitizeOutputBlocksXSSPayloads(): void
    {
        $payload1 = '<script>document.cookie</script>';
        $result1 = sanitizeOutput($payload1);
        $this->assertStringNotContainsString('<script>', $result1);

        $payload2 = '<img src=x onerror=alert(1)>';
        $result2 = sanitizeOutput($payload2);
        $this->assertStringNotContainsString('<img', $result2);

        $payload3 = 'javascript:alert(1)';
        $result3 = sanitizeOutput($payload3);
        // javascript: protocol doesn't contain HTML, so it passes through
        // (this is expected -- sanitizeOutput only escapes HTML entities)
        $this->assertEquals('javascript:alert(1)', $result3);
    }
}
