<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\tests\Support\TestCase;

class ControllerSourceTest extends TestCase
{
    private string $bookedSource;
    private string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcDir = dirname(__DIR__, 3) . '/src';
        $this->bookedSource = file_get_contents($this->srcDir . '/Slots.php');
    }

    private function controllerSource(string $relativePath): string
    {
        return file_get_contents($this->srcDir . '/controllers/' . $relativePath);
    }

    /**
     * @dataProvider siteRouteProvider
     */
    public function testSiteRouteExists(string $route, string $action): void
    {
        $this->assertStringContainsString(
            $route,
            $this->bookedSource,
            "Site route '{$route}' should be registered in Slots.php"
        );
        $this->assertStringContainsString(
            $action,
            $this->bookedSource,
            "Site route should map to action '{$action}'"
        );
    }

    public static function siteRouteProvider(): array
    {
        return [
            'manage booking' => ['booking/manage/<token:', 'slots/booking-management/manage-booking'],
            'cancel by token' => ['booking/cancel/<token:', 'slots/booking-management/cancel-booking-by-token'],
            'download ics' => ['booking/ics/<token:', 'slots/booking-management/download-ics'],
            'my bookings' => ['account/bookings', 'slots/booking-management/my-bookings'],
            'account index' => ["'slots/account'", 'slots/account/index'],
            'account bookings' => ['slots/account/bookings', 'slots/account/bookings'],
            'account upcoming' => ['slots/account/upcoming', 'slots/account/upcoming'],
            'account past' => ['slots/account/past', 'slots/account/past'],
            'account view' => ['slots/account/<id:', 'slots/account/view'],
        ];
    }

    /**
     * @dataProvider cpRouteProvider
     */
    public function testCpRouteExists(string $route, string $action): void
    {
        $this->assertStringContainsString(
            $route,
            $this->bookedSource,
            "CP route '{$route}' should be registered"
        );
        $this->assertStringContainsString(
            $action,
            $this->bookedSource,
            "CP route should map to action '{$action}'"
        );
    }

    public static function cpRouteProvider(): array
    {
        return [
            'dashboard default' => ["'slots'", 'slots/cp/dashboard/index'],
            'dashboard' => ['slots/dashboard', 'slots/cp/dashboard/index'],
            'calendar month' => ['slots/calendar-view/month', 'slots/cp/calendar-view/month'],
            'calendar week' => ['slots/calendar-view/week', 'slots/cp/calendar-view/week'],
            'calendar day' => ['slots/calendar-view/day', 'slots/cp/calendar-view/day'],
            'calendar reschedule' => ['slots/calendar-view/reschedule', 'slots/cp/calendar-view/reschedule'],
            'reports index' => ["'slots/reports'", 'slots/cp/reports/index'],
            'reports revenue' => ['slots/reports/revenue', 'slots/cp/reports/revenue'],
            'services index' => ["'slots/services'", 'slots/cp/services/index'],
            'services new' => ['slots/services/new', 'slots/cp/services/edit'],
            'employees index' => ["'slots/employees'", 'slots/cp/employees/index'],
            'employees new' => ['slots/employees/new', 'slots/cp/employees/edit'],
            'schedules index' => ["'slots/schedules'", 'slots/cp/schedules/index'],
            'locations index' => ["'slots/locations'", 'slots/cp/locations/index'],
            'blackout dates index' => ["'slots/blackout-dates'", 'slots/cp/blackout-dates/index'],
            'bookings index' => ["'slots/bookings'", 'slots/cp/bookings/index'],
            'bookings new' => ['slots/bookings/new', 'slots/cp/bookings/edit'],
            'bookings export' => ['slots/bookings/export', 'slots/cp/bookings/export'],
            'settings default' => ["'slots/settings'", 'slots/cp/settings/booking'],
            'settings booking' => ['slots/settings/booking', 'slots/cp/settings/booking'],
            'settings security' => ['slots/settings/security', 'slots/cp/settings/security'],
            'settings notifications' => ['slots/settings/notifications', 'slots/cp/settings/notifications'],
        ];
    }

    /**
     * @dataProvider allowAnonymousProvider
     */
    /**
     * Every anonymous endpoint, exactly.
     *
     * This used to assert only that the named actions were present, so adding a
     * new anonymous endpoint could never fail it — the wrong direction for a
     * check about what the internet can reach without logging in. Comparing the
     * whole list means opening something up has to be a deliberate edit here.
     *
     * @dataProvider allowAnonymousProvider
     */
    public function testAllowAnonymousActions(string $controllerFile, array $expectedActions): void
    {
        $source = $this->controllerSource($controllerFile);

        preg_match('/\$allowAnonymous\s*=\s*\[(.*?)\]/s', $source, $match);
        $this->assertNotEmpty($match, "{$controllerFile} should declare \$allowAnonymous");

        preg_match_all("/'([^']+)'/", $match[1], $found);
        $actual = $found[1];
        sort($actual);
        sort($expectedActions);

        $this->assertSame(
            $expectedActions,
            $actual,
            "{$controllerFile} exposes a different set of anonymous actions than this test allows",
        );
    }

    public static function allowAnonymousProvider(): array
    {
        return [
            'BookingController' => ['BookingController.php', ['create-booking']],
            'SlotController' => ['SlotController.php', [
                'get-available-slots', 'get-availability-calendar',
                'create-lock', 'extend-lock', 'release-lock',
            ]],
            'BookingDataController' => ['BookingDataController.php', [
                'get-services', 'get-employees', 'get-payment-settings',
            ]],
            'BookingManagementController' => ['BookingManagementController.php', [
                'manage-booking', 'cancel-booking-by-token', 'download-ics',
                'reduce-quantity', 'increase-quantity',
            ]],
            // `current-user` reports on the requester's own session and takes no
            // identifier, so it cannot be pointed at anybody else. The wizard
            // needs it to prefill a booking for someone already logged in.
            'AccountController' => ['AccountController.php', ['current-user']],
        ];
    }

    /**
     * @dataProvider cpPermissionProvider
     */
    public function testCpControllerRequiresPermission(string $controllerFile, string $permission): void
    {
        $source = $this->controllerSource('cp/' . $controllerFile);
        $this->assertStringContainsString(
            "'{$permission}'",
            $source,
            "{$controllerFile} should require permission '{$permission}'"
        );
    }

    public static function cpPermissionProvider(): array
    {
        return [
            'DashboardController' => ['DashboardController.php', 'slots-accessPlugin'],
            'ReportsController' => ['ReportsController.php', 'slots-viewReports'],
            'BookingsController view' => ['BookingsController.php', 'slots-viewBookings'],
            'BookingsController manage' => ['BookingsController.php', 'slots-manageBookings'],
            'ServicesController' => ['ServicesController.php', 'slots-manageServices'],
            'EmployeesController' => ['EmployeesController.php', 'slots-manageEmployees'],
            'SchedulesController' => ['SchedulesController.php', 'slots-manageEmployees'],
            'LocationsController' => ['LocationsController.php', 'slots-manageLocations'],
            'BlackoutDatesController' => ['BlackoutDatesController.php', 'slots-manageBlackoutDates'],
            'SettingsController' => ['SettingsController.php', 'slots-manageSettings'],
        ];
    }

    public function testBookingsControllerHasDynamicPermissions(): void
    {
        $source = $this->controllerSource('cp/BookingsController.php');
        $this->assertStringContainsString("'index', 'view', 'edit', 'export'", $source);
        $this->assertStringContainsString('slots-viewBookings', $source);
        $this->assertStringContainsString('slots-manageBookings', $source);
    }

    public function testCalendarViewControllerHasDynamicPermissions(): void
    {
        $source = $this->controllerSource('cp/CalendarViewController.php');
        $this->assertStringContainsString('reschedule', $source);
        $this->assertStringContainsString('slots-manageBookings', $source);
        $this->assertStringContainsString('slots-viewCalendar', $source);
    }

    public function testCalendarViewControllerValidatesDateParams(): void
    {
        $source = $this->controllerSource('cp/CalendarViewController.php');
        $this->assertStringContainsString('preg_match', $source,
            'CalendarViewController must validate date params with regex');
        $this->assertStringContainsString('max(2000', $source,
            'CalendarViewController actionMonth must clamp year range');
    }

    /**
     * @dataProvider loginRequiredProvider
     */
    public function testControllerRequiresLogin(string $controllerFile): void
    {
        $source = $this->controllerSource($controllerFile);
        $this->assertStringContainsString(
            'requireLogin()',
            $source,
            "{$controllerFile} should call requireLogin()"
        );
    }

    public static function loginRequiredProvider(): array
    {
        return [
            'AccountController' => ['AccountController.php'],
        ];
    }
}
