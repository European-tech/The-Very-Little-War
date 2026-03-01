<?php
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function testValidateLoginAcceptsValid()
    {
        $this->assertTrue(validateLogin('Player1'));
        $this->assertTrue(validateLogin('test_user'));
        $this->assertTrue(validateLogin('abc'));
    }

    public function testValidateLoginRejectsInvalid()
    {
        $this->assertFalse(validateLogin('ab'));  // too short
        $this->assertFalse(validateLogin('a'));   // too short
        $this->assertFalse(validateLogin(''));     // empty
        $this->assertFalse(validateLogin('user with spaces'));
        $this->assertFalse(validateLogin('user<script>'));
        $this->assertFalse(validateLogin(str_repeat('a', 21))); // too long
    }

    public function testValidateEmailAcceptsValid()
    {
        $this->assertTrue(validateEmail('test@example.com'));
        $this->assertTrue(validateEmail('user.name@domain.org'));
    }

    public function testValidateEmailRejectsInvalid()
    {
        $this->assertFalse(validateEmail('notanemail'));
        $this->assertFalse(validateEmail(''));
        $this->assertFalse(validateEmail('@domain.com'));
    }

    public function testValidatePositiveInt()
    {
        $this->assertTrue(validatePositiveInt(1));
        $this->assertTrue(validatePositiveInt(100));
        $this->assertTrue(validatePositiveInt('5'));
        $this->assertFalse(validatePositiveInt(0));
        $this->assertFalse(validatePositiveInt(-1));
        $this->assertFalse(validatePositiveInt('abc'));
    }

    public function testValidateRange()
    {
        $this->assertTrue(validateRange(5, 1, 10));
        $this->assertTrue(validateRange(1, 1, 10));
        $this->assertTrue(validateRange(10, 1, 10));
        $this->assertFalse(validateRange(0, 1, 10));
        $this->assertFalse(validateRange(11, 1, 10));
    }

    public function testSanitizeOutput()
    {
        $this->assertEquals('&lt;script&gt;', sanitizeOutput('<script>'));
        $this->assertEquals('Hello &amp; World', sanitizeOutput('Hello & World'));
        $this->assertEquals('&quot;quoted&quot;', sanitizeOutput('"quoted"'));
    }
}
