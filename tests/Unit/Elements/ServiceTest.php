<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Service;
use anvildev\slots\tests\Support\TestCase;

/**
 * Service Element Test
 *
 * Tests pure business logic: getStatus(), getScheduleData(), and
 * HasWeeklySchedule trait methods via the availabilitySchedule property.
 *
 * hasAvailabilitySchedule() and getSchedules() require Slots::getInstance()
 * and need integration tests.
 */
class ServiceTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    private function makeService(array $props = []): Service
    {
        $ref = new \ReflectionClass(Service::class);
        $service = $ref->newInstanceWithoutConstructor();

        $service->enabled = $props['enabled'] ?? true;
        $service->duration = $props['duration'] ?? 60;
        $service->price = $props['price'] ?? null;
        $service->bufferBefore = $props['bufferBefore'] ?? null;
        $service->bufferAfter = $props['bufferAfter'] ?? null;
        $service->availabilitySchedule = $props['availabilitySchedule'] ?? null;
        $service->timeSlotLength = $props['timeSlotLength'] ?? null;
        $service->customerLimitEnabled = $props['customerLimitEnabled'] ?? false;

        return $service;
    }

    // =========================================================================
    // getStatus()
    // =========================================================================

    public function testGetStatusReturnsEnabledWhenEnabled(): void
    {
        $service = $this->makeService(['enabled' => true]);
        $this->assertEquals('enabled', $service->getStatus());
    }

    public function testGetStatusReturnsDisabledWhenDisabled(): void
    {
        $service = $this->makeService(['enabled' => false]);
        $this->assertEquals('disabled', $service->getStatus());
    }

    // =========================================================================
    // getScheduleData() - availability schedule parsing
    // =========================================================================

    public function testGetScheduleDataReturnsArrayWhenSet(): void
    {
        $schedule = [
            1 => ['enabled' => true, 'start' => '08:00', 'end' => '16:00'],
        ];
        $service = $this->makeService(['availabilitySchedule' => $schedule]);

        $this->assertEquals($schedule, $service->getScheduleData());
    }

    public function testGetScheduleDataDecodesJsonString(): void
    {
        $schedule = [
            1 => ['enabled' => true, 'start' => '08:00', 'end' => '16:00'],
        ];
        $service = $this->makeService(['availabilitySchedule' => json_encode($schedule)]);

        // Keys become strings after JSON round-trip
        $result = $service->getScheduleData();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('1', $result);
    }

    public function testGetScheduleDataReturnsNullWhenNull(): void
    {
        $service = $this->makeService(['availabilitySchedule' => null]);
        $this->assertNull($service->getScheduleData());
    }

    public function testGetScheduleDataReturnsNullForInvalidJson(): void
    {
        $service = $this->makeService(['availabilitySchedule' => 'not-json']);
        $this->assertNull($service->getScheduleData());
    }

    // =========================================================================
    // HasWeeklySchedule trait via Service
    // =========================================================================

    public function testGetScheduleForDayWorksWithAvailabilitySchedule(): void
    {
        $schedule = [
            1 => ['enabled' => true, 'start' => '08:00', 'end' => '16:00', 'breakStart' => null, 'breakEnd' => null],
            2 => ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null],
        ];
        $service = $this->makeService(['availabilitySchedule' => $schedule]);

        $monday = $service->getScheduleForDay(1);
        $this->assertNotNull($monday);
        $this->assertEquals('08:00', $monday['start']);

        $this->assertNull($service->getScheduleForDay(2));
    }

    public function testGetTimeSlotsForDayWorksWithAvailabilitySchedule(): void
    {
        $schedule = [
            1 => ['enabled' => true, 'start' => '08:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
        ];
        $service = $this->makeService(['availabilitySchedule' => $schedule]);

        $slots = $service->getTimeSlotsForDay(1);
        $this->assertCount(2, $slots);
        $this->assertEquals('08:00', $slots[0]['start']);
        $this->assertEquals('12:00', $slots[0]['end']);
        $this->assertEquals('13:00', $slots[1]['start']);
        $this->assertEquals('17:00', $slots[1]['end']);
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(Service::hasTitles());
    }

    public function testHasStatusesReturnsTrue(): void
    {
        $this->assertTrue(Service::hasStatuses());
    }

    public function testIsLocalizedReturnsTrue(): void
    {
        $this->assertTrue(Service::isLocalized());
    }

    public function testRefHandleReturnsService(): void
    {
        $this->assertEquals('service', Service::refHandle());
    }
}
