<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\tests\Support\TestCase;

class ReservationMemoizationTest extends TestCase
{
    public function testReservationHasMemoizationProperties(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        $this->assertStringContainsString('private ?Service $_service = null', $source);
        $this->assertStringContainsString('private ?Employee $_employee = null', $source);
        $this->assertStringContainsString('private ?Location $_location = null', $source);
    }

    public function testGetServiceUsesMemoization(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        preg_match('/function getService\(\).*?\n\s*\{(.*?)\n\s*\}/s', $source, $match);
        $this->assertNotEmpty($match[1]);
        $this->assertStringContainsString('$this->_service', $match[1]);
    }

    public function testGetEmployeeUsesMemoization(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        preg_match('/function getEmployee\(\).*?\n\s*\{(.*?)\n\s*\}/s', $source, $match);
        $this->assertNotEmpty($match[1]);
        $this->assertStringContainsString('$this->_employee', $match[1]);
    }

    public function testGetLocationUsesMemoization(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        preg_match('/function getLocation\(\).*?\n\s*\{(.*?)\n\s*\}/s', $source, $match);
        $this->assertNotEmpty($match[1]);
        $this->assertStringContainsString('$this->_location', $match[1]);
    }
}
