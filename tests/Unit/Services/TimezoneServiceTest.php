<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\TimezoneService;
use anvildev\slots\tests\Support\TestCase;
use DateTime;

/**
 * TimezoneService Test
 */
class TimezoneServiceTest extends TestCase
{
    private TimezoneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimezoneService();
    }

    public function testConvertToUtcFromNewYork(): void
    {
        $result = $this->service->convertToUtc('2025-06-15', '14:30', 'America/New_York');

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('UTC', $result->getTimezone()->getName());
        $this->assertEquals('18:30', $result->format('H:i'));
    }

    public function testConvertToUtcFromLondon(): void
    {
        $result = $this->service->convertToUtc('2025-06-15', '14:30', 'Europe/London');

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('UTC', $result->getTimezone()->getName());
        $this->assertEquals('13:30', $result->format('H:i'));
    }

    public function testConvertToUtcFromTokyo(): void
    {
        $result = $this->service->convertToUtc('2025-06-15', '14:30', 'Asia/Tokyo');

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('UTC', $result->getTimezone()->getName());
        $this->assertEquals('05:30', $result->format('H:i'));
    }

    public function testConvertToUtcWithMidnight(): void
    {
        $result = $this->service->convertToUtc('2025-06-15', '00:00', 'America/New_York');

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('04:00', $result->format('H:i'));
    }

    public function testConvertToUtcWithEndOfDay(): void
    {
        $result = $this->service->convertToUtc('2025-06-15', '23:59', 'America/New_York');

        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertEquals('2025-06-16', $result->format('Y-m-d'));
    }

    public function testShiftSlotsSameTimezone(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '10:00', 'endTime' => '11:00'],
        ];

        $result = $this->service->shiftSlots($slots, '2025-06-15', 'America/New_York', 'America/New_York');

        $this->assertEquals($slots, $result);
    }

    public function testShiftSlotsDifferentTimezones(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'available' => true],
            ['time' => '10:00', 'endTime' => '11:00', 'available' => true],
        ];

        $result = $this->service->shiftSlots($slots, '2025-06-15', 'America/New_York', 'Europe/London');

        $this->assertCount(2, $result);
        $this->assertEquals('14:00', $result[0]['time']);
        $this->assertEquals('15:00', $result[0]['endTime']);
        $this->assertTrue($result[0]['available']);
        $this->assertEquals('15:00', $result[1]['time']);
        $this->assertEquals('16:00', $result[1]['endTime']);
    }

    public function testShiftSlotsPreservesAdditionalFields(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'available' => true, 'capacity' => 5],
        ];

        $result = $this->service->shiftSlots($slots, '2025-06-15', 'America/New_York', 'Europe/London');

        $this->assertArrayHasKey('available', $result[0]);
        $this->assertArrayHasKey('capacity', $result[0]);
        $this->assertTrue($result[0]['available']);
        $this->assertEquals(5, $result[0]['capacity']);
    }

    public function testShiftSlotsEmptyArray(): void
    {
        $result = $this->service->shiftSlots([], '2025-06-15', 'America/New_York', 'Europe/London');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
