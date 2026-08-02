<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\IcsHelper;
use anvildev\slots\tests\Support\TestCase;
use Mockery;

/**
 * IcsHelper Test
 *
 * Tests the ICS (iCalendar) file generation functionality
 */
class IcsHelperTest extends TestCase
{
    public function testEscapeMethod(): void
    {
        $reflection = new \ReflectionClass(IcsHelper::class);
        $method = $reflection->getMethod('escape');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'Test; with, special\\ characters');
        $this->assertEquals('Test\\; with\\, special\\\\ characters', $result);
    }

    public function testEscapeMethodHandlesNewlines(): void
    {
        $reflection = new \ReflectionClass(IcsHelper::class);
        $method = $reflection->getMethod('escape');
        $method->setAccessible(true);

        $result = $method->invoke(null, "Line 1\nLine 2\r\nLine 3");
        $this->assertEquals('Line 1\\nLine 2\\nLine 3', $result);
    }
}
