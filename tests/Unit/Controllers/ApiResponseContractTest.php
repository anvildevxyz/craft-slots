<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\tests\Support\TestCase;

/**
 * API Response Contract Test
 *
 * Verifies that JSON API endpoints return the expected response keys.
 * Source-level tests that catch accidental renames, removals, or structural changes
 * to the frontend API contract without requiring Craft CMS initialization.
 */
class ApiResponseContractTest extends TestCase
{
    private string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcDir = dirname(__DIR__, 3) . '/src/controllers';
    }

    private function source(string $relativePath): string
    {
        return file_get_contents($this->srcDir . '/' . $relativePath);
    }

    // =========================================================================
    // BookingDataController — GET /services
    // =========================================================================

    /**
     * @dataProvider serviceResponseKeyProvider
     */
    public function testGetServicesResponseKeys(string $key): void
    {
        $source = $this->source('BookingDataController.php');
        $this->assertStringContainsString(
            "'{$key}'",
            $source,
            "GET services response should include '{$key}' key"
        );
    }

    /**
     * @dataProvider extraResponseKeyProvider
     */
    public static function extraResponseKeyProvider(): array
    {
        return [
            'id' => ['id'],
            'title' => ['title'],
            'description' => ['description'],
            'price' => ['price'],
            'duration' => ['duration'],
            'maxQuantity' => ['maxQuantity'],
            'isRequired' => ['isRequired'],
        ];
    }

    // =========================================================================
    // BookingDataController — GET /employees
    // =========================================================================

    /**
     * @dataProvider employeeResponseKeyProvider
     */
    public function testGetEmployeesResponseKeys(string $key): void
    {
        $source = $this->source('BookingDataController.php');
        $this->assertStringContainsString(
            "'{$key}'",
            $source,
            "GET employees response should include '{$key}' key"
        );
    }

    public static function employeeResponseKeyProvider(): array
    {
        return [
            'employees' => ['employees'],
            'employeeRequired' => ['employeeRequired'],
            'hasSchedules' => ['hasSchedules'],
            'serviceHasSchedule' => ['serviceHasSchedule'],
            'locations' => ['locations'],
        ];
    }

    public function testGetEmployeesLocationShape(): void
    {
        $source = $this->source('BookingDataController.php');
        foreach (['name', 'address', 'timezone'] as $key) {
            $this->assertStringContainsString(
                "'{$key}'",
                $source,
                "Employee location data should include '{$key}'"
            );
        }
    }

    public function testEmployeeRequiredLogic(): void
    {
        $source = $this->source('BookingDataController.php');
        $this->assertStringContainsString(
            "count(\$employees) === 1 && !\$serviceHasSchedule",
            $source,
            'employeeRequired should be true only when exactly 1 employee and no service schedule'
        );
    }

    public function testLocationAddressUsesArrayFilter(): void
    {
        $source = $this->source('BookingDataController.php');
        $this->assertStringContainsString('implode', $source);
        $this->assertStringContainsString('array_filter', $source);
        $this->assertStringContainsString('addressLine1', $source);
        $this->assertStringContainsString('postalCode', $source);
        $this->assertStringContainsString('countryCode', $source);
    }

    // =========================================================================
    // BookingDataController — GET /commerce-settings
    // =========================================================================

    /**
     * @dataProvider calendarStateKeyProvider
     */
    public function testGetAvailabilityCalendarResponseKeys(string $key): void
    {
        $source = $this->source('SlotController.php');
        $this->assertStringContainsString(
            "'{$key}'",
            $source,
            "GET availability-calendar state should include '{$key}' key"
        );
    }

    public static function calendarStateKeyProvider(): array
    {
        return [
            'calendar' => ['calendar'],
            'hasAvailability' => ['hasAvailability'],
            'isBlackedOut' => ['isBlackedOut'],
            'isBookable' => ['isBookable'],
        ];
    }

    public function testCalendarBookableLogic(): void
    {
        $source = $this->source('SlotController.php');
        $this->assertStringContainsString(
            '$hasSlots && !$isBlackedOut',
            $source,
            'isBookable should be hasSlots AND NOT isBlackedOut'
        );
    }

    // =========================================================================
    // SlotController — POST /available-slots
    // =========================================================================

    public function testGetAvailableSlotsResponseKeys(): void
    {
        $source = $this->source('SlotController.php');
        $this->assertStringContainsString("'slots'", $source);
    }

    // =========================================================================
    // SlotController — POST /create-lock, /release-lock
    // =========================================================================

    public function testCreateLockResponseKeys(): void
    {
        $source = $this->source('SlotController.php');
        $this->assertStringContainsString("'token'", $source);
        $this->assertStringContainsString("'expiresIn'", $source);
    }

    public function testReleaseLockResponseKeys(): void
    {
        $source = $this->source('SlotController.php');
        $this->assertStringContainsString("'released'", $source);
    }

    // =========================================================================
    // BookingController — POST /create-booking
    // =========================================================================

    /**
     * @dataProvider bookingResponseKeyProvider
     */
    public function testCreateBookingResponseKeys(string $key): void
    {
        $source = $this->source('BookingController.php');
        $this->assertStringContainsString(
            "'{$key}'",
            $source,
            "POST create-booking response should include '{$key}' key"
        );
    }

    public static function bookingResponseKeyProvider(): array
    {
        return [
            'reservation' => ['reservation'],
            'id' => ['id'],
            'formattedDateTime' => ['formattedDateTime'],
            'status' => ['status'],
        ];
    }

    public function testCreateBookingSupportsAlternateFieldNames(): void
    {
        $source = $this->source('BookingController.php');
        $this->assertStringContainsString("'customerName'", $source);
        $this->assertStringContainsString("'customerEmail'", $source);
        $this->assertStringContainsString("'customerPhone'", $source);
        $this->assertStringContainsString("'customerNotes'", $source);
    }

    // =========================================================================
    // BookingManagementController — reschedule response
    // =========================================================================

    public function testRescheduleResponseKeys(): void
    {
        $source = $this->source('BookingManagementController.php');
        $this->assertStringContainsString("'formattedDateTime'", $source);
        $this->assertStringContainsString("'status'", $source);
        $this->assertStringContainsString("'reservation'", $source);
    }

    // =========================================================================
    // AccountController — GET /current-user
    // =========================================================================

    /**
     * @dataProvider currentUserResponseKeyProvider
     */
    public function testCurrentUserResponseKeys(string $key): void
    {
        $source = $this->source('AccountController.php');
        $this->assertStringContainsString(
            "'{$key}'",
            $source,
            "GET current-user response should include '{$key}' key"
        );
    }

    public static function currentUserResponseKeyProvider(): array
    {
        return [
            'loggedIn' => ['loggedIn'],
            'user' => ['user'],
            'id' => ['id'],
            'email' => ['email'],
            'name' => ['name'],
            'firstName' => ['firstName'],
            'lastName' => ['lastName'],
            'phone' => ['phone'],
        ];
    }

    // =========================================================================
    // CP BookingsController — sort whitelist and pagination
    // =========================================================================

    /**
     * @dataProvider allowedSortFieldProvider
     */
    public function testBookingsIndexAllowsSortField(string $field): void
    {
        $source = $this->source('cp/BookingsController.php');
        $this->assertStringContainsString(
            "'{$field}'",
            $source,
            "Bookings index should allow sorting by '{$field}'"
        );
    }

    public static function allowedSortFieldProvider(): array
    {
        return [
            'bookingDate' => ['bookingDate'],
            'userName' => ['userName'],
            'startTime' => ['startTime'],
            'status' => ['status'],
            'dateCreated' => ['dateCreated'],
        ];
    }

    public static function serviceResponseKeyProvider(): array
    {
        return [
            'id' => ['id'],
            'title' => ['title'],
            'description' => ['description'],
            'duration' => ['duration'],
            'price' => ['price'],
            'bufferBefore' => ['bufferBefore'],
            'bufferAfter' => ['bufferAfter'],
        ];
    }
}
