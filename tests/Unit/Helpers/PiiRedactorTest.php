<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\PiiRedactor;
use anvildev\slots\tests\Support\TestCase;

class PiiRedactorTest extends TestCase
{
    // =========================================================================
    // Email redaction
    // =========================================================================

    public function testRedactEmailBasic(): void
    {
        $this->assertSame('jo***@example.com', PiiRedactor::redactEmail('john@example.com'));
    }

    public function testRedactEmailShortLocal(): void
    {
        $this->assertSame('a***@example.com', PiiRedactor::redactEmail('a@example.com'));
    }

    public function testRedactEmailNull(): void
    {
        $this->assertSame('***', PiiRedactor::redactEmail(null));
    }

    public function testRedactEmailEmpty(): void
    {
        $this->assertSame('***', PiiRedactor::redactEmail(''));
    }

    public function testRedactEmailNoAtSign(): void
    {
        $this->assertSame('***', PiiRedactor::redactEmail('notanemail'));
    }

    // =========================================================================
    // Phone redaction
    // =========================================================================

    public function testRedactPhoneBasic(): void
    {
        $this->assertSame('*******4567', PiiRedactor::redactPhone('+1-555-123-4567'));
    }

    public function testRedactPhoneShort(): void
    {
        $this->assertSame('****', PiiRedactor::redactPhone('1234'));
    }

    public function testRedactPhoneNull(): void
    {
        $this->assertSame('***', PiiRedactor::redactPhone(null));
    }

    public function testRedactPhoneEmpty(): void
    {
        $this->assertSame('***', PiiRedactor::redactPhone(''));
    }

    public function testRedactPhoneFormattedNumber(): void
    {
        $result = PiiRedactor::redactPhone('(555) 123-4567');
        $this->assertStringEndsWith('4567', $result);
        $this->assertStringStartsWith('***', $result);
    }
}
