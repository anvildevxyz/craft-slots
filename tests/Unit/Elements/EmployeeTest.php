<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Employee;
use anvildev\slots\tests\Support\TestCase;

/**
 * Employee Element Test
 *
 * Tests pure business logic and HasWeeklySchedule trait methods.
 * Uses ReflectionClass to bypass Element::init() which requires Craft::$app.
 *
 * DB-dependent methods (getUser, getLocation, getServices, getSchedules)
 * require integration tests.
 */
class EmployeeTest extends TestCase
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

    private function makeEmployee(array $props = []): Employee
    {
        $ref = new \ReflectionClass(Employee::class);
        $employee = $ref->newInstanceWithoutConstructor();

        $employee->workingHours = $props['workingHours'] ?? null;
        $employee->serviceIds = $props['serviceIds'] ?? null;
        $employee->enabled = $props['enabled'] ?? true;
        $employee->userId = $props['userId'] ?? null;
        $employee->locationId = $props['locationId'] ?? null;
        $employee->email = $props['email'] ?? null;

        return $employee;
    }

    private function makeStandardWorkingHours(): array
    {
        return [
            1 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
            2 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
            3 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
            4 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
            5 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
            6 => ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null],
            7 => ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null],
        ];
    }

    // =========================================================================
    // getServiceIds() - JSON/array handling
    // =========================================================================

    public function testGetServiceIdsReturnsArrayWhenAlreadyArray(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => [1, 2, 3]]);
        $this->assertEquals([1, 2, 3], $employee->getServiceIds());
    }

    public function testGetServiceIdsDecodesJsonString(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => '[1,2,3]']);
        $this->assertEquals([1, 2, 3], $employee->getServiceIds());
    }

    public function testGetServiceIdsReturnsEmptyArrayWhenNull(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => null]);
        $this->assertEquals([], $employee->getServiceIds());
    }

    public function testGetServiceIdsCastsToIntegers(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => ['1', '2', '3']]);
        $result = $employee->getServiceIds();
        $this->assertSame([1, 2, 3], $result);
    }

    public function testGetServiceIdsHandlesInvalidJson(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => 'not-json']);
        $this->assertEquals([], $employee->getServiceIds());
    }

    // =========================================================================
    // hasService()
    // =========================================================================

    public function testHasServiceReturnsTrueForAssignedService(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => [1, 5, 10]]);
        $this->assertTrue($employee->hasService(5));
    }

    public function testHasServiceReturnsFalseForUnassignedService(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => [1, 5, 10]]);
        $this->assertFalse($employee->hasService(99));
    }

    public function testHasServiceReturnsFalseWithNoServices(): void
    {
        $employee = $this->makeEmployee(['serviceIds' => null]);
        $this->assertFalse($employee->hasService(1));
    }

    // =========================================================================
    // HasWeeklySchedule trait — getWorkingHoursForDay()
    // =========================================================================

    public function testGetWorkingHoursForDayReturnsHoursWhenEnabled(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $hours = $employee->getWorkingHoursForDay(1); // Monday

        $this->assertNotNull($hours);
        $this->assertEquals('09:00', $hours['start']);
        $this->assertEquals('17:00', $hours['end']);
        $this->assertEquals('12:00', $hours['breakStart']);
        $this->assertEquals('13:00', $hours['breakEnd']);
    }

    public function testGetWorkingHoursForDayReturnsNullWhenDisabled(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $this->assertNull($employee->getWorkingHoursForDay(6)); // Saturday
    }

    public function testGetWorkingHoursForDayReturnsNullWhenNoSchedule(): void
    {
        $employee = $this->makeEmployee(['workingHours' => null]);
        $this->assertNull($employee->getWorkingHoursForDay(1));
    }

    // =========================================================================
    // HasWeeklySchedule trait — getWorkingSlotsForDay()
    // =========================================================================

    public function testGetWorkingSlotsForDaySplitsByBreak(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $slots = $employee->getWorkingSlotsForDay(1);

        $this->assertCount(2, $slots);
        $this->assertEquals(['start' => '09:00', 'end' => '12:00'], $slots[0]);
        $this->assertEquals(['start' => '13:00', 'end' => '17:00'], $slots[1]);
    }

    public function testGetWorkingSlotsForDayReturnsOneSlotWithNoBreak(): void
    {
        $hours = $this->makeStandardWorkingHours();
        $hours[1]['breakStart'] = null;
        $hours[1]['breakEnd'] = null;

        $employee = $this->makeEmployee(['workingHours' => $hours]);
        $slots = $employee->getWorkingSlotsForDay(1);

        $this->assertCount(1, $slots);
        $this->assertEquals(['start' => '09:00', 'end' => '17:00'], $slots[0]);
    }

    public function testGetWorkingSlotsForDayReturnsEmptyWhenDisabled(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $this->assertEmpty($employee->getWorkingSlotsForDay(7)); // Sunday
    }

    // =========================================================================
    // HasWeeklySchedule trait — worksOnDay() / getWorkingDays()
    // =========================================================================

    public function testWorksOnDayReturnsTrueForWorkingDay(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $this->assertTrue($employee->worksOnDay(1)); // Monday
    }

    public function testWorksOnDayReturnsFalseForDayOff(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $this->assertFalse($employee->worksOnDay(7)); // Sunday
    }

    public function testGetWorkingDaysReturnsMonThroughFri(): void
    {
        $employee = $this->makeEmployee(['workingHours' => $this->makeStandardWorkingHours()]);
        $this->assertEquals([1, 2, 3, 4, 5], $employee->getWorkingDays());
    }

    // =========================================================================
    // HasWeeklySchedule trait — getEarliestStartTime / getLatestEndTime
    // =========================================================================

    public function testGetEarliestStartTimeReturnsEarliest(): void
    {
        $hours = $this->makeStandardWorkingHours();
        $hours[3]['start'] = '07:30'; // Wednesday starts earlier

        $employee = $this->makeEmployee(['workingHours' => $hours]);
        $this->assertEquals('07:30', $employee->getEarliestStartTime());
    }

    public function testGetLatestEndTimeReturnsLatest(): void
    {
        $hours = $this->makeStandardWorkingHours();
        $hours[4]['end'] = '19:00'; // Thursday ends later

        $employee = $this->makeEmployee(['workingHours' => $hours]);
        $this->assertEquals('19:00', $employee->getLatestEndTime());
    }

    public function testGetEarliestStartTimeReturnsNullWithNoSchedule(): void
    {
        $employee = $this->makeEmployee(['workingHours' => null]);
        $this->assertNull($employee->getEarliestStartTime());
    }

    public function testGetLatestEndTimeReturnsNullWithNoSchedule(): void
    {
        $employee = $this->makeEmployee(['workingHours' => null]);
        $this->assertNull($employee->getLatestEndTime());
    }

    // =========================================================================
    // getDefaultWorkingHours()
    // =========================================================================

    public function testGetDefaultWorkingHoursReturnsSevenDays(): void
    {
        $employee = $this->makeEmployee();
        $hours = $employee->getDefaultWorkingHours();

        $this->assertCount(7, $hours);
        $this->assertTrue($hours[1]['enabled']); // Monday
        $this->assertFalse($hours[6]['enabled']); // Saturday
        $this->assertFalse($hours[7]['enabled']); // Sunday
    }

    // =========================================================================
    // getStatus()
    // =========================================================================

    public function testGetStatusReturnsEnabledWhenEnabled(): void
    {
        $employee = $this->makeEmployee(['enabled' => true]);
        $this->assertEquals('enabled', $employee->getStatus());
    }

    public function testGetStatusReturnsDisabledWhenDisabled(): void
    {
        $employee = $this->makeEmployee(['enabled' => false]);
        $this->assertEquals('disabled', $employee->getStatus());
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(Employee::hasTitles());
    }

    public function testRefHandleReturnsEmployee(): void
    {
        $this->assertEquals('employee', Employee::refHandle());
    }

    // =========================================================================
    // String key schedule support
    // =========================================================================

    public function testScheduleWorksWithStringKeys(): void
    {
        $hours = [
            '1' => ['enabled' => true, 'start' => '08:00', 'end' => '16:00', 'breakStart' => null, 'breakEnd' => null],
            '2' => ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null],
        ];

        $employee = $this->makeEmployee(['workingHours' => $hours]);
        $this->assertNotNull($employee->getWorkingHoursForDay(1));
        $this->assertNull($employee->getWorkingHoursForDay(2));
    }
}
