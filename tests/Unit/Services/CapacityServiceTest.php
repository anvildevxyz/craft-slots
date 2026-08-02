<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\CapacityService;
use anvildev\slots\services\TimeWindowService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;

/**
 * CapacityService Test
 *
 * Tests capacity checking and slot enrichment logic.
 *
 * Uses Mockery partial mocks to stub Craft-dependent lookups
 * (getCapacityForSlot, getBookedQuantity) while testing real
 * orchestration and math logic.
 */
class CapacityServiceTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        // Define a minimal Craft stub so Craft::info() calls don't fatal.
        // Must run once before any tests since the class persists across tests.
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    /**
     * Create a partial mock with timeWindowService initialized
     * (Mockery partial mocks don't call init())
     */
    private function makePartialService(): Mockery\MockInterface
    {
        $service = Mockery::mock(CapacityService::class)->makePartial();

        // Inject TimeWindowService via reflection since init() isn't called on mocks
        $ref = new \ReflectionProperty(CapacityService::class, 'timeWindowService');
        $ref->setAccessible(true);
        $ref->setValue($service, new TimeWindowService());

        return $service;
    }

    // =========================================================================
    // isQuantityAllowed() - Pure boundary checks (no Craft dependency)
    // =========================================================================

    public function testIsQuantityAllowedRejectsZero(): void
    {
        $service = new CapacityService();
        $this->assertFalse($service->isQuantityAllowed(0));
    }

    public function testIsQuantityAllowedRejectsNegative(): void
    {
        $service = new CapacityService();
        $this->assertFalse($service->isQuantityAllowed(-1));
    }

    public function testIsQuantityAllowedRejectsLargeNegative(): void
    {
        $service = new CapacityService();
        $this->assertFalse($service->isQuantityAllowed(-100));
    }

    public function testIsQuantityAllowedAcceptsOneWithNullService(): void
    {
        $service = new CapacityService();
        $this->assertTrue($service->isQuantityAllowed(1, null));
    }

    public function testIsQuantityAllowedAcceptsLargeQuantityWithNullService(): void
    {
        $service = new CapacityService();
        $this->assertTrue($service->isQuantityAllowed(999, null));
    }

    public function testIsQuantityAllowedAcceptsOneWithNoServiceId(): void
    {
        $service = new CapacityService();
        $this->assertTrue($service->isQuantityAllowed(1));
    }

    // =========================================================================
    // hasAvailableCapacity() - Capacity math via partial mock
    // =========================================================================

    public function testHasAvailableCapacityReturnsTrueWhenUnlimited(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(null);

        $result = $service->hasAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1, 5);

        $this->assertTrue($result);
    }

    public function testHasAvailableCapacityReturnsTrueWhenWithinLimit(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(10);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1, null)
            ->andReturn(3);

        $result = $service->hasAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1, 5);

        $this->assertTrue($result); // 5 + 3 = 8 <= 10
    }

    public function testHasAvailableCapacityReturnsTrueAtExactLimit(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(10);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1, null)
            ->andReturn(5);

        $result = $service->hasAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1, 5);

        $this->assertTrue($result); // 5 + 5 = 10 <= 10
    }

    public function testHasAvailableCapacityReturnsFalseWhenExceedsLimit(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(10);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1, null)
            ->andReturn(8);

        $result = $service->hasAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1, 5);

        $this->assertFalse($result); // 5 + 8 = 13 > 10
    }

    public function testHasAvailableCapacityReturnsFalseWhenFullyBooked(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(5);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1, null)
            ->andReturn(5);

        $result = $service->hasAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1, 1);

        $this->assertFalse($result); // 1 + 5 = 6 > 5
    }

    public function testHasAvailableCapacityReturnsTrueWhenNoBookings(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(10);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1, null)
            ->andReturn(0);

        $result = $service->hasAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1, 1);

        $this->assertTrue($result); // 1 + 0 = 1 <= 10
    }

    public function testHasAvailableCapacityWithEmployeeId(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '14:00', 42, 1)
            ->andReturn(1);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '14:00', '15:00', 42, 1, null)
            ->andReturn(0);

        $result = $service->hasAvailableCapacity('2025-06-15', '14:00', '15:00', 42, 1, 1);

        $this->assertTrue($result); // 1 + 0 = 1 <= 1
    }

    public function testHasAvailableCapacityEmployeeSlotAlreadyTaken(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '14:00', 42, 1)
            ->andReturn(1);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '14:00', '15:00', 42, 1, null)
            ->andReturn(1);

        $result = $service->hasAvailableCapacity('2025-06-15', '14:00', '15:00', 42, 1, 1);

        $this->assertFalse($result); // 1 + 1 = 2 > 1
    }

    // =========================================================================
    // getAvailableCapacity() - Available capacity math via partial mock
    // =========================================================================

    public function testGetAvailableCapacityReturnsNullWhenUnlimited(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(null);

        $result = $service->getAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1);

        $this->assertNull($result);
    }

    public function testGetAvailableCapacityReturnsRemainingSlots(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(10);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1)
            ->andReturn(3);

        $result = $service->getAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1);

        $this->assertEquals(7, $result); // 10 - 3 = 7
    }

    public function testGetAvailableCapacityReturnsZeroWhenFull(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(5);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1)
            ->andReturn(5);

        $result = $service->getAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1);

        $this->assertEquals(0, $result);
    }

    public function testGetAvailableCapacityNeverReturnsNegative(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(5);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1)
            ->andReturn(8); // Overbooking scenario

        $result = $service->getAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1);

        $this->assertEquals(0, $result); // max(0, 5 - 8) = 0
    }

    public function testGetAvailableCapacityFullCapacityWhenNoBookings(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getCapacityForSlot')
            ->with('2025-06-15', '09:00', null, 1)
            ->andReturn(10);
        $service->shouldReceive('getBookedQuantity')
            ->with('2025-06-15', '09:00', '10:00', null, 1)
            ->andReturn(0);

        $result = $service->getAvailableCapacity('2025-06-15', '09:00', '10:00', null, 1);

        $this->assertEquals(10, $result);
    }

    // =========================================================================
    // getCapacityForSlot() - Date parsing (pure part, no Craft dependency)
    // =========================================================================

    public function testGetCapacityForSlotReturnsNullForInvalidDate(): void
    {
        $service = new CapacityService();

        $result = $service->getCapacityForSlot('not-a-date', '09:00', null, null);

        $this->assertNull($result);
    }

    public function testGetCapacityForSlotReturnsNullForEmptyDate(): void
    {
        $service = new CapacityService();

        $result = $service->getCapacityForSlot('', '09:00', null, null);

        $this->assertNull($result);
    }

    // =========================================================================
    // enrichSlotsWithCapacity() - Slot enrichment via partial mock
    //
    // The batch version pre-loads data via loadBatchCapacityData() (protected),
    // then uses getCapacityFromPreloaded() (private) for pure in-memory lookups.
    // Tests mock loadBatchCapacityData() to inject pre-built data without DB.
    // =========================================================================

    /**
     * Helper: create a partial mock that stubs loadBatchCapacityData()
     * with given service schedule capacity and reservation index.
     */
    private function makeEnrichService(?int $scheduleCapacity, array $reservationRecords): Mockery\MockInterface
    {
        $service = Mockery::mock(CapacityService::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // Inject TimeWindowService via reflection since init() isn't called on mocks
        $ref = new \ReflectionProperty(CapacityService::class, 'timeWindowService');
        $ref->setAccessible(true);
        $ref->setValue($service, new TimeWindowService());

        // Create a mock Schedule that returns the given capacity for any day
        $serviceSchedule = null;
        if ($scheduleCapacity !== null) {
            $serviceSchedule = Mockery::mock(\anvildev\slots\elements\Schedule::class);
            $serviceSchedule->shouldReceive('getCapacityForDay')->andReturn($scheduleCapacity);
            $serviceSchedule->shouldReceive('getWorkingHoursForDay')->andReturn(null);
            $serviceSchedule->shouldReceive('getWorkingSlotsForDay')->andReturn([]);
        }

        $service->shouldReceive('loadBatchCapacityData')->andReturn([
            'employees' => [],
            'schedulesByEmployee' => [],
            'serviceSchedule' => $serviceSchedule,
            'reservationRecords' => $reservationRecords,
            'dayOfWeek' => 7, // Sunday (2025-06-15 is a Sunday)
        ]);

        return $service;
    }

    /**
     * Helper: create reservation records from a simple "startTime:employeeId => quantity" map.
     * Each record gets a 1-hour duration by default.
     */
    /**
     * Helper: create reservation records from a "HH:MM|employeeId => quantity" map.
     * Each record gets a 1-hour duration by default.
     */
    private function makeReservationRecords(array $map): array
    {
        $records = [];
        foreach ($map as $key => $quantity) {
            // Format: "HH:MM|employeeId" where employeeId is "null" or an integer
            [$time, $empId] = explode('|', $key, 2);
            $endHour = ((int) substr($time, 0, 2)) + 1;
            $records[] = [
                'startTime' => $time,
                'endTime' => sprintf('%02d:%s', $endHour, substr($time, 3, 2)),
                'employeeId' => $empId === 'null' ? null : (int) $empId,
                'quantity' => $quantity,
            ];
        }

        return $records;
    }

    public function testEnrichSlotsWithCapacityAddsCapacityKeys(): void
    {
        $service = $this->makeEnrichService(10, $this->makeReservationRecords(['09:00|null' => 3]));

        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => null],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertArrayHasKey('maxCapacity', $result[0]);
        $this->assertArrayHasKey('bookedQuantity', $result[0]);
        $this->assertArrayHasKey('availableCapacity', $result[0]);
        $this->assertArrayHasKey('capacity', $result[0]);
    }

    public function testEnrichSlotsWithCapacityCalculatesCorrectValues(): void
    {
        $service = $this->makeEnrichService(10, $this->makeReservationRecords(['09:00|null' => 3]));

        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => null],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertEquals(10, $result[0]['maxCapacity']);
        $this->assertEquals(3, $result[0]['bookedQuantity']);
        $this->assertEquals(7, $result[0]['availableCapacity']); // 10 - 3
        $this->assertEquals(10, $result[0]['capacity']);
    }

    public function testEnrichSlotsWithCapacityHandlesUnlimitedCapacity(): void
    {
        // null schedule capacity => no service schedule => null maxCapacity
        $service = $this->makeEnrichService(null, $this->makeReservationRecords(['09:00|null' => 3]));

        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => null],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertNull($result[0]['maxCapacity']);
        $this->assertEquals(3, $result[0]['bookedQuantity']);
        $this->assertNull($result[0]['availableCapacity']);
        $this->assertEquals(1, $result[0]['capacity']); // defaults to 1 when null
    }

    public function testEnrichSlotsWithCapacityHandlesMultipleSlots(): void
    {
        $service = $this->makeEnrichService(5, $this->makeReservationRecords([
            '09:00|null' => 1,
            '10:00|null' => 3,
            '11:00|null' => 5,
        ]));

        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => null],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => null],
            ['time' => '11:00', 'endTime' => '12:00', 'employeeId' => null],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertCount(3, $result);
        $this->assertEquals(4, $result[0]['availableCapacity']); // 5 - 1
        $this->assertEquals(2, $result[1]['availableCapacity']); // 5 - 3
        $this->assertEquals(0, $result[2]['availableCapacity']); // 5 - 5
    }

    public function testEnrichSlotsWithCapacityRemovesScheduleCapacityMarker(): void
    {
        $service = $this->makeEnrichService(5, []);


        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => null, '_scheduleCapacity' => 5],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertArrayNotHasKey('_scheduleCapacity', $result[0]);
    }

    public function testEnrichSlotsWithCapacityCalculatesEndTimeFromDuration(): void
    {
        $service = $this->makeEnrichService(5, []);


        // Slot with duration but no endTime
        $slots = [
            ['time' => '09:00', 'duration' => 60, 'employeeId' => null],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertEquals(5, $result[0]['maxCapacity']);
        $this->assertEquals(0, $result[0]['bookedQuantity']);
        $this->assertEquals(5, $result[0]['availableCapacity']);
    }

    public function testEnrichSlotsWithCapacityHandlesEmptyArray(): void
    {
        $service = $this->makePartialService();

        $result = $service->enrichSlotsWithCapacity([], '2025-06-15', 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testEnrichSlotsWithCapacityPreservesExistingSlotData(): void
    {
        // Use employee-based slot; mock loadBatchCapacityData with employee schedule
        $service = Mockery::mock(CapacityService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $ref = new \ReflectionProperty(CapacityService::class, 'timeWindowService');
        $ref->setAccessible(true);
        $ref->setValue($service, new TimeWindowService());

        $employeeSchedule = Mockery::mock(\anvildev\slots\elements\Schedule::class);
        $employeeSchedule->shouldReceive('getWorkingSlotsForDay')->andReturn([
            ['start' => '08:00', 'end' => '17:00'],
        ]);

        $service->shouldReceive('loadBatchCapacityData')->andReturn([
            'employees' => [],
            'schedulesByEmployee' => [42 => $employeeSchedule],
            'serviceSchedule' => null,
            'reservationRecords' => [],
            'dayOfWeek' => 7,
        ]);

        $slots = [
            [
                'time' => '09:00',
                'endTime' => '10:00',
                'employeeId' => 42,
                'serviceName' => 'Haircut',
                'price' => 25.00,
            ],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[0]['endTime']);
        $this->assertEquals(42, $result[0]['employeeId']);
        $this->assertEquals('Haircut', $result[0]['serviceName']);
        $this->assertEquals(25.00, $result[0]['price']);
    }

    public function testEnrichSlotsAvailableCapacityNeverNegative(): void
    {
        $service = $this->makeEnrichService(2, $this->makeReservationRecords(['09:00|null' => 5]));

        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => null],
        ];

        $result = $service->enrichSlotsWithCapacity($slots, '2025-06-15', 1);

        $this->assertEquals(0, $result[0]['availableCapacity']); // max(0, 2-5) = 0
    }
}
