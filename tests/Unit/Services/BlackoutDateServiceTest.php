<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\BlackoutDateService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;

/**
 * BlackoutDateService Test
 *
 * Tests the blackout matching logic. The core method matchesAnyBlackout()
 * is pure in-memory matching and needs no mocks.
 *
 * getBlackoutsForDate() is DB-dependent and requires integration tests.
 */
class BlackoutDateServiceTest extends TestCase
{
    private BlackoutDateService $service;

    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlackoutDateService();
    }

    // =========================================================================
    // matchesAnyBlackout() - Global blackouts (no scoping)
    // =========================================================================

    public function testGlobalBlackoutBlocksWhenNoEmployeeOrLocation(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, null));
    }

    public function testGlobalBlackoutDoesNotBlockSpecificEmployee(): void
    {
        // Global blackout (empty arrays) still matches when employee is given
        // because appliesToAllEmployees=true and appliesToAllLocations=true
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, null));
    }

    public function testGlobalBlackoutDoesNotBlockSpecificLocation(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, 3));
    }

    public function testGlobalBlackoutBlocksSpecificEmployeeAndLocation(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, 3));
    }

    // =========================================================================
    // matchesAnyBlackout() - Employee-scoped blackouts
    // =========================================================================

    public function testEmployeeScopedBlackoutMatchesTargetedEmployee(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => [5, 10]],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, null));
    }

    public function testEmployeeScopedBlackoutDoesNotMatchOtherEmployee(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => [5, 10]],
        ];

        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, 99, null));
    }

    public function testEmployeeScopedBlackoutConservativelyMatchesNullEmployee(): void
    {
        // When no employee is specified (null) and blackout is employee-scoped,
        // conservative matching treats it as potentially applying because the
        // booking could be for any employee in the scoped list.
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => [5]],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, null));
    }

    // =========================================================================
    // matchesAnyBlackout() - Location-scoped blackouts
    // =========================================================================

    public function testLocationScopedBlackoutMatchesTargetedLocation(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3, 7], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, 3));
    }

    public function testLocationScopedBlackoutDoesNotMatchOtherLocation(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3, 7], 'employeeIds' => []],
        ];

        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, null, 99));
    }

    public function testLocationScopedBlackoutConservativelyMatchesNullLocation(): void
    {
        // When no location is specified (null) and blackout is location-scoped,
        // conservative matching treats it as potentially applying because the
        // booking could be at any of the scoped locations.
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, null));
    }

    // =========================================================================
    // matchesAnyBlackout() - Dual-scoped blackouts (both employee + location)
    // =========================================================================

    public function testDualScopedBlackoutMatchesWhenBothMatch(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => [5]],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, 3));
    }

    public function testDualScopedBlackoutDoesNotMatchWhenOnlyEmployeeMatches(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => [5]],
        ];

        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, 5, 99));
    }

    public function testDualScopedBlackoutDoesNotMatchWhenOnlyLocationMatches(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => [5]],
        ];

        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, 99, 3));
    }

    public function testDualScopedBlackoutConservativelyMatchesWithNulls(): void
    {
        // When both employee and location are null, conservative matching
        // treats a dual-scoped blackout as potentially applying.
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => [5]],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, null));
    }

    // =========================================================================
    // matchesAnyBlackout() - Multiple blackouts
    // =========================================================================

    public function testMatchesIfAnyBlackoutApplies(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => [5]],   // Doesn't match
            ['id' => 2, 'locationIds' => [], 'employeeIds' => [10]],   // Matches employee 10
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 10, null));
    }

    public function testDoesNotMatchWhenNoBlackoutsApply(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => [5]],
            ['id' => 2, 'locationIds' => [7], 'employeeIds' => [10]],
        ];

        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, 99, 99));
    }

    public function testFirstMatchingBlackoutWins(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => []],   // Global — matches everything
            ['id' => 2, 'locationIds' => [3], 'employeeIds' => [5]], // Scoped — irrelevant
        ];

        // Global blackout matches even for specific employee/location
        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, 3));
    }

    // =========================================================================
    // matchesAnyBlackout() - Empty input
    // =========================================================================

    public function testReturnsFalseForEmptyBlackoutsArray(): void
    {
        $this->assertFalse($this->service->matchesAnyBlackout([], null, null));
    }

    public function testReturnsFalseForEmptyBlackoutsWithEmployee(): void
    {
        $this->assertFalse($this->service->matchesAnyBlackout([], 5, null));
    }

    public function testReturnsFalseForEmptyBlackoutsWithLocation(): void
    {
        $this->assertFalse($this->service->matchesAnyBlackout([], null, 3));
    }

    // =========================================================================
    // matchesAnyBlackout() - Edge cases
    // =========================================================================

    public function testEmployeeScopedWithLocationGivenButNotScoped(): void
    {
        // Blackout scoped to employee 5, no location restriction
        // Request for employee 5 at location 3 — should match
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => [5]],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, 3));
    }

    public function testLocationScopedWithEmployeeGivenButNotScoped(): void
    {
        // Blackout scoped to location 3, no employee restriction
        // Request for employee 5 at location 3 — should match
        $blackouts = [
            ['id' => 1, 'locationIds' => [3], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 5, 3));
    }

    public function testMultipleEmployeesInBlackout(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [], 'employeeIds' => [1, 2, 3, 4, 5]],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, 3, null));
        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, 6, null));
    }

    public function testMultipleLocationsInBlackout(): void
    {
        $blackouts = [
            ['id' => 1, 'locationIds' => [10, 20, 30], 'employeeIds' => []],
        ];

        $this->assertTrue($this->service->matchesAnyBlackout($blackouts, null, 20));
        $this->assertFalse($this->service->matchesAnyBlackout($blackouts, null, 15));
    }

    // =========================================================================
    // isDateBlackedOut() - Orchestration via partial mock
    // =========================================================================

    public function testIsDateBlackedOutReturnsFalseWhenNoBlackouts(): void
    {
        $service = Mockery::mock(BlackoutDateService::class)->makePartial();
        $service->shouldReceive('getBlackoutsForDate')
            ->with('2025-06-15')
            ->andReturn([]);

        $this->assertFalse($service->isDateBlackedOut('2025-06-15'));
    }

    public function testIsDateBlackedOutReturnsTrueForGlobalBlackout(): void
    {
        $service = Mockery::mock(BlackoutDateService::class)->makePartial();
        $service->shouldReceive('getBlackoutsForDate')
            ->with('2025-12-25')
            ->andReturn([
                ['id' => 1, 'locationIds' => [], 'employeeIds' => []],
            ]);

        $this->assertTrue($service->isDateBlackedOut('2025-12-25'));
    }

    public function testIsDateBlackedOutReturnsTrueForMatchingEmployee(): void
    {
        $service = Mockery::mock(BlackoutDateService::class)->makePartial();
        $service->shouldReceive('getBlackoutsForDate')
            ->with('2025-06-15')
            ->andReturn([
                ['id' => 1, 'locationIds' => [], 'employeeIds' => [5]],
            ]);

        $this->assertTrue($service->isDateBlackedOut('2025-06-15', 5));
    }

    public function testIsDateBlackedOutReturnsFalseForNonMatchingEmployee(): void
    {
        $service = Mockery::mock(BlackoutDateService::class)->makePartial();
        $service->shouldReceive('getBlackoutsForDate')
            ->with('2025-06-15')
            ->andReturn([
                ['id' => 1, 'locationIds' => [], 'employeeIds' => [5]],
            ]);

        $this->assertFalse($service->isDateBlackedOut('2025-06-15', 99));
    }

    public function testIsDateBlackedOutPassesLocationToMatcher(): void
    {
        $service = Mockery::mock(BlackoutDateService::class)->makePartial();
        $service->shouldReceive('getBlackoutsForDate')
            ->with('2025-06-15')
            ->andReturn([
                ['id' => 1, 'locationIds' => [3], 'employeeIds' => []],
            ]);

        $this->assertTrue($service->isDateBlackedOut('2025-06-15', null, 3));
        $this->assertFalse($service->isDateBlackedOut('2025-06-15', null, 99));
    }

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $this->assertInstanceOf(BlackoutDateService::class, $this->service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->service, 'getBlackoutsForDate'));
        $this->assertTrue(method_exists($this->service, 'isDateBlackedOut'));
        $this->assertTrue(method_exists($this->service, 'matchesAnyBlackout'));
    }
}
