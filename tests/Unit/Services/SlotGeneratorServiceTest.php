<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\SlotGeneratorService;
use anvildev\slots\tests\Support\TestCase;

/**
 * SlotGeneratorService Test
 *
 * Tests the pure utility functions in SlotGeneratorService.
 * Note: Methods that depend on Craft CMS (getSlotInterval, addEmployeeInfo) are skipped.
 */
class SlotGeneratorServiceTest extends TestCase
{
    private SlotGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotGeneratorService();
    }

    // =========================================================================
    // deduplicateByTime() Tests
    // =========================================================================

    public function testDeduplicateByTimeEmpty(): void
    {
        $result = $this->service->deduplicateByTime([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeduplicateByTimeSingleSlot(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertNull($result[0]['employeeId']);
    }

    public function testDeduplicateByTimeDuplicatesRemoved(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'employeeName' => 'John'],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'employeeName' => 'Jane'],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1, 'employeeName' => 'John'],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[1]['time']);
    }

    public function testDeduplicateByTimeSetsEmployeeToNull(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 5, 'employeeName' => 'John Doe'],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertNull($result[0]['employeeId']);
        $this->assertNull($result[0]['employeeName']);
    }

    public function testDeduplicateByTimePreservesOtherFields(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'duration' => 60, 'available' => true],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertEquals(60, $result[0]['duration']);
        $this->assertTrue($result[0]['available']);
    }

    public function testDeduplicateByTimeKeepsFirstOccurrence(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'locationId' => 100],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'locationId' => 200],
        ];

        $result = $this->service->deduplicateByTime($slots);

        // Should keep first occurrence's location
        $this->assertEquals(100, $result[0]['locationId']);
    }

    // =========================================================================
    // sortByTime() Tests
    // =========================================================================

    public function testSortByTimeEmpty(): void
    {
        $result = $this->service->sortByTime([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSortByTimeAlreadySorted(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '10:00', 'endTime' => '11:00'],
            ['time' => '11:00', 'endTime' => '12:00'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[1]['time']);
        $this->assertEquals('11:00', $result[2]['time']);
    }

    public function testSortByTimeReversed(): void
    {
        $slots = [
            ['time' => '11:00', 'endTime' => '12:00'],
            ['time' => '10:00', 'endTime' => '11:00'],
            ['time' => '09:00', 'endTime' => '10:00'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[1]['time']);
        $this->assertEquals('11:00', $result[2]['time']);
    }

    public function testSortByTimeRandom(): void
    {
        $slots = [
            ['time' => '14:00', 'endTime' => '15:00'],
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '11:00', 'endTime' => '12:00'],
            ['time' => '08:00', 'endTime' => '09:00'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('08:00', $result[0]['time']);
        $this->assertEquals('09:00', $result[1]['time']);
        $this->assertEquals('11:00', $result[2]['time']);
        $this->assertEquals('14:00', $result[3]['time']);
    }

    public function testSortByTimePreservesAllFields(): void
    {
        $slots = [
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2, 'extra' => 'B'],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'extra' => 'A'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('A', $result[0]['extra']);
        $this->assertEquals(1, $result[0]['employeeId']);
        $this->assertEquals('B', $result[1]['extra']);
        $this->assertEquals(2, $result[1]['employeeId']);
    }

    public function testSortByTimeSameTime(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('09:00', $result[1]['time']);
    }

    // =========================================================================
    // filterByEmployeeQuantity() Tests
    // =========================================================================

    public function testFilterByEmployeeQuantityEmpty(): void
    {
        $result = $this->service->filterByEmployeeQuantity([], 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFilterByEmployeeQuantityOne(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 1);

        $this->assertCount(2, $result);
    }

    public function testFilterByEmployeeQuantityTwo(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        // Only 09:00 has 2 employees available
        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('09:00', $result[1]['time']);
    }

    public function testFilterByEmployeeQuantityNoneMatch(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        $this->assertCount(0, $result);
    }

    public function testFilterByEmployeeQuantityThree(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 3],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 3);

        // Only 09:00 has 3 employees
        $this->assertCount(3, $result);
        foreach ($result as $slot) {
            $this->assertEquals('09:00', $slot['time']);
        }
    }

    public function testFilterByEmployeeQuantityPreservesAllFields(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'duration' => 60],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'duration' => 60],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        $this->assertEquals(60, $result[0]['duration']);
        $this->assertEquals(60, $result[1]['duration']);
    }

    public function testFilterByEmployeeQuantityMultipleTimeSlots(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2],
            ['time' => '11:00', 'endTime' => '12:00', 'employeeId' => 1],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        // 09:00 and 10:00 have 2 employees each
        $this->assertCount(4, $result);

        $times = array_column($result, 'time');
        $this->assertCount(2, array_filter($times, fn($t) => $t === '09:00'));
        $this->assertCount(2, array_filter($times, fn($t) => $t === '10:00'));
    }
}
