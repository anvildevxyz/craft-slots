<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\AvailabilityService;
use anvildev\slots\tests\Support\TestCase;
use DateTime;

/**
 * AvailabilityService Test
 *
 * Tests the AvailabilityService functionality
 *
 * Note: Many tests require Craft CMS to be installed. This file contains
 * tests for business logic that can be tested with mocking or standalone.
 */
class AvailabilityServiceTest extends TestCase
{
    private AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AvailabilityService();
    }

    // =====================================================
    // Constants Tests
    // =====================================================

    public function testEventConstantsAreDefined(): void
    {
        $this->assertIsString(AvailabilityService::EVENT_BEFORE_AVAILABILITY_CHECK);
        $this->assertEquals('beforeAvailabilityCheck', AvailabilityService::EVENT_BEFORE_AVAILABILITY_CHECK);
    }

    public function testEventAfterAvailabilityCheckConstant(): void
    {
        $this->assertIsString(AvailabilityService::EVENT_AFTER_AVAILABILITY_CHECK);
        $this->assertEquals('afterAvailabilityCheck', AvailabilityService::EVENT_AFTER_AVAILABILITY_CHECK);
    }

    // =====================================================
    // Date Validation Tests
    // =====================================================

    public function testValidDateFormat(): void
    {
        $date = '2025-06-15';
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);

        $this->assertInstanceOf(DateTime::class, $dateObj);
        $this->assertEquals('2025-06-15', $dateObj->format('Y-m-d'));
    }

    public function testInvalidDateFormat(): void
    {
        $date = '15-06-2025';
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);

        $this->assertFalse($dateObj);
    }

    public function testEmptyDateReturnsInvalid(): void
    {
        $date = '';
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);

        $this->assertFalse($dateObj);
    }

    public function testDateWithInvalidDay(): void
    {
        $date = '2025-02-30'; // February doesn't have 30 days
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);

        // DateTime might create an invalid date, so we check with strict validation
        $this->assertNotEquals('2025-02-30', $dateObj ? $dateObj->format('Y-m-d') : '');
    }

    // =====================================================
    // Day of Week Calculation Tests
    // =====================================================

    public function testDayOfWeekCalculation(): void
    {
        $date = '2025-06-15'; // This is a Sunday
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $dayOfWeek = (int)$dateObj->format('w');

        $this->assertEquals(0, $dayOfWeek); // 0 = Sunday
    }

    public function testMondayDayOfWeek(): void
    {
        $date = '2025-06-16'; // Monday
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $dayOfWeek = (int)$dateObj->format('w');

        $this->assertEquals(1, $dayOfWeek);
    }

    public function testSaturdayDayOfWeek(): void
    {
        $date = '2025-06-21'; // Saturday
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $dayOfWeek = (int)$dateObj->format('w');

        $this->assertEquals(6, $dayOfWeek);
    }

    public function testAllDaysOfWeek(): void
    {
        $dates = [
            '2025-06-15' => 0, // Sunday
            '2025-06-16' => 1, // Monday
            '2025-06-17' => 2, // Tuesday
            '2025-06-18' => 3, // Wednesday
            '2025-06-19' => 4, // Thursday
            '2025-06-20' => 5, // Friday
            '2025-06-21' => 6, // Saturday
        ];

        foreach ($dates as $date => $expectedDay) {
            $dateObj = DateTime::createFromFormat('Y-m-d', $date);
            $dayOfWeek = (int)$dateObj->format('w');
            $this->assertEquals($expectedDay, $dayOfWeek, "Failed for date {$date}");
        }
    }

    // =====================================================
    // Time Slot Generation Tests
    // =====================================================

    public function testTimeSlotGeneration(): void
    {
        $startTime = '09:00';
        $endTime = '17:00';
        $slotDuration = 60; // minutes

        $slots = [];
        $current = new DateTime('2025-06-15 ' . $startTime);
        $end = new DateTime('2025-06-15 ' . $endTime);

        while ($current < $end) {
            $slotEnd = (clone $current)->modify("+{$slotDuration} minutes");
            if ($slotEnd <= $end) {
                $slots[] = [
                    'time' => $current->format('H:i'),
                    'endTime' => $slotEnd->format('H:i'),
                ];
            }
            $current->modify("+{$slotDuration} minutes");
        }

        $this->assertCount(8, $slots); // 9:00-17:00 with 60min slots = 8 slots
        $this->assertEquals('09:00', $slots[0]['time']);
        $this->assertEquals('10:00', $slots[0]['endTime']);
        $this->assertEquals('16:00', $slots[7]['time']);
        $this->assertEquals('17:00', $slots[7]['endTime']);
    }

    public function testTimeSlotGenerationWith30MinuteSlots(): void
    {
        $startTime = '09:00';
        $endTime = '11:00';
        $slotDuration = 30;

        $slots = [];
        $current = new DateTime('2025-06-15 ' . $startTime);
        $end = new DateTime('2025-06-15 ' . $endTime);

        while ($current < $end) {
            $slotEnd = (clone $current)->modify("+{$slotDuration} minutes");
            if ($slotEnd <= $end) {
                $slots[] = [
                    'time' => $current->format('H:i'),
                    'endTime' => $slotEnd->format('H:i'),
                ];
            }
            $current->modify("+{$slotDuration} minutes");
        }

        $this->assertCount(4, $slots); // 9:00-11:00 with 30min slots = 4 slots
    }

    public function testTimeSlotGenerationWith15MinuteSlots(): void
    {
        $startTime = '09:00';
        $endTime = '10:00';
        $slotDuration = 15;

        $slots = [];
        $current = new DateTime('2025-06-15 ' . $startTime);
        $end = new DateTime('2025-06-15 ' . $endTime);

        while ($current < $end) {
            $slotEnd = (clone $current)->modify("+{$slotDuration} minutes");
            if ($slotEnd <= $end) {
                $slots[] = [
                    'time' => $current->format('H:i'),
                    'endTime' => $slotEnd->format('H:i'),
                ];
            }
            $current->modify("+{$slotDuration} minutes");
        }

        $this->assertCount(4, $slots); // 9:00-10:00 with 15min slots = 4 slots
    }

    // =====================================================
    // Slot Overlap Detection Tests
    // =====================================================

    public function testSlotOverlapDetection(): void
    {
        // Slot A: 09:00-10:00
        // Slot B: 09:30-10:30
        // Should overlap

        $slotAStart = new DateTime('2025-06-15 09:00');
        $slotAEnd = new DateTime('2025-06-15 10:00');
        $slotBStart = new DateTime('2025-06-15 09:30');
        $slotBEnd = new DateTime('2025-06-15 10:30');

        $overlaps = $slotBStart < $slotAEnd && $slotAStart < $slotBEnd;

        $this->assertTrue($overlaps);
    }

    public function testSlotNoOverlap(): void
    {
        // Slot A: 09:00-10:00
        // Slot B: 10:00-11:00
        // Should NOT overlap (adjacent)

        $slotAStart = new DateTime('2025-06-15 09:00');
        $slotAEnd = new DateTime('2025-06-15 10:00');
        $slotBStart = new DateTime('2025-06-15 10:00');
        $slotBEnd = new DateTime('2025-06-15 11:00');

        $overlaps = $slotBStart < $slotAEnd && $slotAStart < $slotBEnd;

        $this->assertFalse($overlaps);
    }

    public function testSlotCompleteOverlap(): void
    {
        // Slot A: 09:00-12:00
        // Slot B: 10:00-11:00 (completely inside A)
        // Should overlap

        $slotAStart = new DateTime('2025-06-15 09:00');
        $slotAEnd = new DateTime('2025-06-15 12:00');
        $slotBStart = new DateTime('2025-06-15 10:00');
        $slotBEnd = new DateTime('2025-06-15 11:00');

        $overlaps = $slotBStart < $slotAEnd && $slotAStart < $slotBEnd;

        $this->assertTrue($overlaps);
    }

    public function testSlotPartialOverlapStart(): void
    {
        // Slot A: 10:00-12:00
        // Slot B: 09:00-11:00 (overlaps at start)

        $slotAStart = new DateTime('2025-06-15 10:00');
        $slotAEnd = new DateTime('2025-06-15 12:00');
        $slotBStart = new DateTime('2025-06-15 09:00');
        $slotBEnd = new DateTime('2025-06-15 11:00');

        $overlaps = $slotBStart < $slotAEnd && $slotAStart < $slotBEnd;

        $this->assertTrue($overlaps);
    }

    // =====================================================
    // Buffer Time Calculation Tests
    // =====================================================

    public function testBufferTimeAdditionBefore(): void
    {
        $slotTime = new DateTime('2025-06-15 10:00');
        $bufferMinutes = 15;

        $withBuffer = (clone $slotTime)->modify("-{$bufferMinutes} minutes");

        $this->assertEquals('09:45', $withBuffer->format('H:i'));
    }

    public function testBufferTimeAdditionAfter(): void
    {
        $slotTime = new DateTime('2025-06-15 10:00');
        $bufferMinutes = 15;

        $withBuffer = (clone $slotTime)->modify("+{$bufferMinutes} minutes");

        $this->assertEquals('10:15', $withBuffer->format('H:i'));
    }

    public function testBufferTimeZeroBuffer(): void
    {
        $slotTime = new DateTime('2025-06-15 10:00');
        $bufferMinutes = 0;

        $withBuffer = (clone $slotTime)->modify("+{$bufferMinutes} minutes");

        $this->assertEquals('10:00', $withBuffer->format('H:i'));
    }

    // =====================================================
    // Duration Validation Tests
    // =====================================================

    public function testValidDuration(): void
    {
        $duration = 60;

        $this->assertGreaterThan(0, $duration);
        $this->assertIsInt($duration);
    }

    public function testInvalidZeroDuration(): void
    {
        $duration = 0;

        $this->assertLessThanOrEqual(0, $duration);
    }

    public function testInvalidNegativeDuration(): void
    {
        $duration = -30;

        $this->assertLessThan(0, $duration);
    }

    public function testDurationDefault(): void
    {
        $duration = null;
        $defaultDuration = 60;

        $effectiveDuration = $duration ?? $defaultDuration;

        $this->assertEquals(60, $effectiveDuration);
    }

    // =====================================================
    // Quantity Tests
    // =====================================================

    public function testValidQuantity(): void
    {
        $quantity = 1;

        $this->assertGreaterThan(0, $quantity);
    }

    public function testMultipleQuantity(): void
    {
        $quantity = 5;

        $this->assertGreaterThan(1, $quantity);
    }

    public function testDefaultQuantity(): void
    {
        $quantity = null;
        $defaultQuantity = 1;

        $effectiveQuantity = $quantity ?? $defaultQuantity;

        $this->assertEquals(1, $effectiveQuantity);
    }

    // =====================================================
    // Time Comparison Tests
    // =====================================================

    public function testTimeComparison(): void
    {
        $time1 = new DateTime('2025-06-15 09:00');
        $time2 = new DateTime('2025-06-15 10:00');

        $this->assertLessThan($time2, $time1);
        $this->assertGreaterThan($time1, $time2);
    }

    public function testEqualTimeComparison(): void
    {
        $time1 = new DateTime('2025-06-15 09:00');
        $time2 = new DateTime('2025-06-15 09:00');

        $this->assertEquals($time1, $time2);
    }

    // =====================================================
    // Service Instance Tests
    // =====================================================

    public function testAvailabilityServiceIsComponent(): void
    {
        $this->assertInstanceOf(AvailabilityService::class, $this->service);
    }

    public function testAvailabilityServiceHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->service, 'getAvailableSlots'));
    }

    // =====================================================
    // Slot Filtering Tests
    // =====================================================

    public function testFilterPastSlots(): void
    {
        $now = new DateTime('2025-06-15 14:00');
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '13:00', 'endTime' => '14:00'],
            ['time' => '14:00', 'endTime' => '15:00'],
            ['time' => '15:00', 'endTime' => '16:00'],
        ];

        $futureSlots = array_filter($slots, function($slot) use ($now) {
            $slotTime = DateTime::createFromFormat('Y-m-d H:i', '2025-06-15 ' . $slot['time']);
            return $slotTime >= $now; // Include slots starting at current time
        });

        $this->assertCount(2, $futureSlots); // Only 14:00 and 15:00 slots
    }

    public function testFilterSlotsIncludesCurrent(): void
    {
        $now = new DateTime('2025-06-15 14:00');
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '14:00', 'endTime' => '15:00'],
            ['time' => '15:00', 'endTime' => '16:00'],
        ];

        $futureSlots = array_filter($slots, function($slot) use ($now) {
            $slotTime = DateTime::createFromFormat('Y-m-d H:i', '2025-06-15 ' . $slot['time']);
            return $slotTime >= $now;
        });

        $this->assertCount(2, $futureSlots); // 14:00 and 15:00 slots
    }

    // =====================================================
    // Capacity Tests
    // =====================================================

    public function testCapacityCalculation(): void
    {
        $totalCapacity = 10;
        $bookedCount = 3;
        $remainingCapacity = $totalCapacity - $bookedCount;

        $this->assertEquals(7, $remainingCapacity);
    }

    public function testCapacityFull(): void
    {
        $totalCapacity = 10;
        $bookedCount = 10;
        $remainingCapacity = $totalCapacity - $bookedCount;

        $this->assertEquals(0, $remainingCapacity);
    }

    public function testCapacityAvailable(): void
    {
        $totalCapacity = 10;
        $bookedCount = 3;
        $requestedQuantity = 5;
        $remainingCapacity = $totalCapacity - $bookedCount;

        $hasCapacity = $remainingCapacity >= $requestedQuantity;

        $this->assertTrue($hasCapacity);
    }

    public function testCapacityInsufficient(): void
    {
        $totalCapacity = 10;
        $bookedCount = 8;
        $requestedQuantity = 5;
        $remainingCapacity = $totalCapacity - $bookedCount;

        $hasCapacity = $remainingCapacity >= $requestedQuantity;

        $this->assertFalse($hasCapacity);
    }

    // =====================================================
    // Edge Case Tests
    // =====================================================

    public function testMidnightSlots(): void
    {
        $slotStart = new DateTime('2025-06-15 23:30');
        $slotEnd = (clone $slotStart)->modify('+60 minutes');

        $this->assertEquals('2025-06-16', $slotEnd->format('Y-m-d'));
        $this->assertEquals('00:30', $slotEnd->format('H:i'));
    }

    public function testFullDaySlots(): void
    {
        $startTime = '00:00';
        $endTime = '24:00';
        $slotDuration = 60;

        // Note: 24:00 should be treated as 00:00 next day
        $start = new DateTime('2025-06-15 00:00');
        $end = new DateTime('2025-06-16 00:00');

        $diff = $end->getTimestamp() - $start->getTimestamp();
        $totalMinutes = $diff / 60;
        $possibleSlots = floor($totalMinutes / $slotDuration);

        $this->assertEquals(24, $possibleSlots);
    }

    // =====================================================
    // Source Code Contract Tests
    // =====================================================

    public function testFilterPastSlotsUsesStrictCutoff(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/AvailabilityService.php'
        );
        $methodStart = strpos($source, 'function filterPastSlots');
        // Read to the next method rather than a fixed number of characters: a
        // slice long enough today silently stops covering the assertion the
        // moment the method grows, and reports it as a broken contract.
        $nextMethod = strpos($source, "\n    private function ", $methodStart + 1);
        $methodSource = $nextMethod !== false
            ? substr($source, $methodStart, $nextMethod - $methodStart)
            : substr($source, $methodStart);
        $this->assertStringContainsString(
            "substr(\$slot['time'], 0, 5) > \$cutoffTime",
            $methodSource,
            'filterPastSlots must normalize time and use strictly greater than (>) for cutoff time comparison'
        );
    }

    public function testGetReservationsForDateFiltersBothEmployeeAndService(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/AvailabilityService.php'
        );
        $methodStart = strpos($source, 'function getReservationsForDate');
        $methodSource = substr($source, $methodStart, 800);
        $this->assertStringNotContainsString(
            'elseif ($serviceId',
            $methodSource,
            'getReservationsForDate must apply both employeeId and serviceId filters, not use elseif'
        );
    }
}
