<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Schedule;
use anvildev\slots\tests\Support\TestCase;

/**
 * Schedule Element Test
 *
 * Tests pure business logic: isActiveOn(), getCapacityForDay(), and
 * HasWeeklySchedule trait methods inherited from trait.
 *
 * DB-dependent methods (getAssignedEmployees) require integration tests.
 */
class ScheduleTest extends TestCase
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

    private function makeSchedule(array $props = []): Schedule
    {
        $ref = new \ReflectionClass(Schedule::class);
        $schedule = $ref->newInstanceWithoutConstructor();

        $schedule->workingHours = $props['workingHours'] ?? null;
        $schedule->startDate = $props['startDate'] ?? null;
        $schedule->endDate = $props['endDate'] ?? null;
        $schedule->enabled = $props['enabled'] ?? true;

        return $schedule;
    }

    private function makeWorkingHoursWithCapacity(): array
    {
        return [
            1 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00', 'capacity' => 5],
            2 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00', 'capacity' => null],
            3 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00', 'capacity' => ''],
            4 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00', 'capacity' => 0],
            5 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => null, 'breakEnd' => null, 'capacity' => 10],
            6 => ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null, 'capacity' => null],
            7 => ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null, 'capacity' => null],
        ];
    }

    // =========================================================================
    // isActiveOn() - Date range checking
    // =========================================================================

    public function testIsActiveOnReturnsTrueWhenNoDateRestrictions(): void
    {
        $schedule = $this->makeSchedule(['startDate' => null, 'endDate' => null]);
        $this->assertTrue($schedule->isActiveOn('2025-06-15'));
    }

    public function testIsActiveOnReturnsFalseWhenDisabled(): void
    {
        $schedule = $this->makeSchedule(['enabled' => false]);
        $this->assertFalse($schedule->isActiveOn('2025-06-15'));
    }

    public function testIsActiveOnReturnsFalseBeforeStartDate(): void
    {
        $schedule = $this->makeSchedule(['startDate' => '2025-07-01', 'endDate' => null]);
        $this->assertFalse($schedule->isActiveOn('2025-06-15'));
    }

    public function testIsActiveOnReturnsTrueOnStartDate(): void
    {
        $schedule = $this->makeSchedule(['startDate' => '2025-06-15', 'endDate' => null]);
        $this->assertTrue($schedule->isActiveOn('2025-06-15'));
    }

    public function testIsActiveOnReturnsFalseAfterEndDate(): void
    {
        $schedule = $this->makeSchedule(['startDate' => null, 'endDate' => '2025-06-15']);
        $this->assertFalse($schedule->isActiveOn('2025-06-20'));
    }

    public function testIsActiveOnReturnsTrueOnEndDate(): void
    {
        $schedule = $this->makeSchedule(['startDate' => null, 'endDate' => '2025-06-15']);
        $this->assertTrue($schedule->isActiveOn('2025-06-15'));
    }

    public function testIsActiveOnReturnsTrueWithinRange(): void
    {
        $schedule = $this->makeSchedule(['startDate' => '2025-06-01', 'endDate' => '2025-06-30']);
        $this->assertTrue($schedule->isActiveOn('2025-06-15'));
    }

    public function testIsActiveOnAcceptsDateTimeObject(): void
    {
        $schedule = $this->makeSchedule(['startDate' => '2025-06-01', 'endDate' => '2025-06-30']);
        $this->assertTrue($schedule->isActiveOn(new \DateTime('2025-06-15')));
    }

    // =========================================================================
    // getCapacityForDay()
    // =========================================================================

    public function testGetCapacityForDayReturnsCapacityWhenSet(): void
    {
        $schedule = $this->makeSchedule(['workingHours' => $this->makeWorkingHoursWithCapacity()]);
        $this->assertEquals(5, $schedule->getCapacityForDay(1)); // Monday, capacity=5
    }

    public function testGetCapacityForDayReturnsNullWhenCapacityIsNull(): void
    {
        $schedule = $this->makeSchedule(['workingHours' => $this->makeWorkingHoursWithCapacity()]);
        $this->assertNull($schedule->getCapacityForDay(2)); // Tuesday, capacity=null
    }

    public function testGetCapacityForDayReturnsNullWhenCapacityIsEmptyString(): void
    {
        $schedule = $this->makeSchedule(['workingHours' => $this->makeWorkingHoursWithCapacity()]);
        $this->assertNull($schedule->getCapacityForDay(3)); // Wednesday, capacity=''
    }

    public function testGetCapacityForDayReturnsZeroWhenCapacityIsZero(): void
    {
        $schedule = $this->makeSchedule(['workingHours' => $this->makeWorkingHoursWithCapacity()]);
        $this->assertEquals(0, $schedule->getCapacityForDay(4)); // Thursday, capacity=0
    }

    public function testGetCapacityForDayReturnsNullForDisabledDay(): void
    {
        $schedule = $this->makeSchedule(['workingHours' => $this->makeWorkingHoursWithCapacity()]);
        $this->assertNull($schedule->getCapacityForDay(6)); // Saturday, disabled
    }

    public function testGetCapacityForDayReturnsNullWithNoSchedule(): void
    {
        $schedule = $this->makeSchedule(['workingHours' => null]);
        $this->assertNull($schedule->getCapacityForDay(1));
    }

    // =========================================================================
    // getDefaultWorkingHours()
    // =========================================================================

    public function testGetDefaultWorkingHoursIncludesCapacityField(): void
    {
        $schedule = $this->makeSchedule();
        $hours = $schedule->getDefaultWorkingHours();

        $this->assertArrayHasKey('capacity', $hours[1]);
        $this->assertNull($hours[1]['capacity']);
    }

    // =========================================================================
    // getStatus()
    // =========================================================================

    public function testGetStatusReturnsEnabledWhenEnabled(): void
    {
        $schedule = $this->makeSchedule(['enabled' => true]);
        $this->assertEquals('enabled', $schedule->getStatus());
    }

    public function testGetStatusReturnsDisabledWhenDisabled(): void
    {
        $schedule = $this->makeSchedule(['enabled' => false]);
        $this->assertEquals('disabled', $schedule->getStatus());
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(Schedule::hasTitles());
    }

    public function testRefHandleReturnsSchedule(): void
    {
        $this->assertEquals('schedule', Schedule::refHandle());
    }
}
