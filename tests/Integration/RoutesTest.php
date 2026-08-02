<?php

namespace anvildev\slots\tests\Integration;

use anvildev\slots\tests\Support\TestCase;
use ReflectionClass;

/**
 * Routes Test
 *
 * Tests that all routes are properly defined in the Booked plugin.
 * This catches missing routes like /new endpoints that were causing 404 errors.
 *
 * Note: This is a unit test that checks route definitions directly from the code.
 * For full integration tests that verify routes work at runtime, use Craft's test framework.
 */
class RoutesTest extends TestCase
{
    /**
     * CP routes that should be registered
     */
    private array $cpRoutes = [
        // Dashboard
        'slots' => 'slots/cp/dashboard/index',
        'slots/dashboard' => 'slots/cp/dashboard/index',

        // Calendar Views
        'slots/calendar-view/month' => 'slots/cp/calendar-view/month',
        'slots/calendar-view/week' => 'slots/cp/calendar-view/week',
        'slots/calendar-view/day' => 'slots/cp/calendar-view/day',
        'slots/calendar-view/reschedule' => 'slots/cp/calendar-view/reschedule',

        // Reports
        'slots/reports' => 'slots/cp/reports/index',
        'slots/reports/revenue' => 'slots/cp/reports/revenue',
        'slots/reports/by-service' => 'slots/cp/reports/by-service',
        'slots/reports/by-employee' => 'slots/cp/reports/by-employee',
        'slots/reports/cancellations' => 'slots/cp/reports/cancellations',
        'slots/reports/peak-hours' => 'slots/cp/reports/peak-hours',

        // Services - CRITICAL: /new must be before /<id:\d+>
        'slots/services' => 'slots/cp/services/index',
        'slots/services/new' => 'slots/cp/services/edit',
        'slots/services/<id:\d+>' => 'slots/cp/services/edit',

        // Employees - CRITICAL: /new must be before /<id:\d+>
        'slots/employees' => 'slots/cp/employees/index',
        'slots/employees/new' => 'slots/cp/employees/edit',
        'slots/employees/<id:\d+>' => 'slots/cp/employees/edit',

        // Locations - CRITICAL: /new must be before /<id:\d+>
        'slots/locations' => 'slots/cp/locations/index',
        'slots/locations/new' => 'slots/cp/locations/edit',
        'slots/locations/<id:\d+>' => 'slots/cp/locations/edit',

        // Blackout Dates
        'slots/blackout-dates' => 'slots/cp/blackout-dates/index',
        'slots/blackout-dates/new' => 'slots/cp/blackout-dates/new',
        'slots/blackout-dates/<id:\d+>' => 'slots/cp/blackout-dates/edit',

        // Service Extras
        'slots/service-extras' => 'slots/cp/service-extra/index',
        'slots/service-extras/new' => 'slots/cp/service-extra/new',
        'slots/service-extras/<id:\d+>' => 'slots/cp/service-extra/edit',

        // Bookings - CRITICAL: /new must be before /<id:\d+>
        'slots/bookings' => 'slots/cp/bookings/index',
        'slots/bookings/new' => 'slots/cp/bookings/edit',
        'slots/bookings/<id:\d+>' => 'slots/cp/bookings/edit',
        'slots/bookings/<id:\d+>/view' => 'slots/cp/bookings/view',
        'slots/bookings/export' => 'slots/cp/bookings/export',

        // Settings
        'slots/settings' => 'slots/cp/settings/general',
        'slots/settings/general' => 'slots/cp/settings/general',
        'slots/settings/calendar' => 'slots/cp/settings/calendar',
        'slots/settings/meetings' => 'slots/cp/settings/meetings',
        'slots/settings/notifications' => 'slots/cp/settings/notifications',
        'slots/settings/commerce' => 'slots/cp/settings/commerce',
        'slots/settings/captcha' => 'slots/cp/settings/captcha',

        // Calendar Sync (OAuth)
        'slots/calendar/connect' => 'slots/cp/calendar/connect',
        'slots/calendar/callback' => 'slots/cp/calendar/callback',
    ];

    /**
     * Test that all CP routes are defined in the plugin code
     */
    public function testCpRoutesAreDefined(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to parse plugin source');
        
        // Get routes from Booked plugin class
        $routes = $this->getRoutesFromBookedPlugin();

        // Check each expected route
        foreach ($this->cpRoutes as $pattern => $expectedRoute) {
            // Find matching route in defined routes
            $found = false;
            foreach ($routes as $routePattern => $routeValue) {
                // Normalize patterns for comparison (handle regex patterns)
                if ($this->routesMatch($pattern, $routePattern) && $this->routesMatch($expectedRoute, $routeValue)) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue(
                $found,
                "Route '{$pattern}' => '{$expectedRoute}' is not defined in Booked plugin. This will cause 404 errors."
            );
        }
    }

    /**
     * Test that /new routes are defined BEFORE /<id:\d+> routes
     * This is critical - if /<id:\d+> comes first, it will match 'new' as an ID
     */
    public function testNewRoutesComeBeforeIdRoutes(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to parse plugin source');
        
        $routes = array_keys($this->getRoutesFromBookedPlugin());

        // Check services routes order
        $servicesNewIndex = $this->findRouteIndex('slots/services/new', $routes);
        $servicesIdIndex = $this->findRouteIndex('slots/services/<id:\d+>', $routes);

        if ($servicesNewIndex !== false && $servicesIdIndex !== false) {
            $this->assertLessThan(
                $servicesIdIndex,
                $servicesNewIndex,
                "Route 'slots/services/new' must come BEFORE 'slots/services/<id:\d+>' to prevent 'new' from being matched as an ID"
            );
        }

        // Check employees routes order
        $employeesNewIndex = $this->findRouteIndex('slots/employees/new', $routes);
        $employeesIdIndex = $this->findRouteIndex('slots/employees/<id:\d+>', $routes);

        if ($employeesNewIndex !== false && $employeesIdIndex !== false) {
            $this->assertLessThan(
                $employeesIdIndex,
                $employeesNewIndex,
                "Route 'slots/employees/new' must come BEFORE 'slots/employees/<id:\d+>' to prevent 'new' from being matched as an ID"
            );
        }

        // Check locations routes order
        $locationsNewIndex = $this->findRouteIndex('slots/locations/new', $routes);
        $locationsIdIndex = $this->findRouteIndex('slots/locations/<id:\d+>', $routes);

        if ($locationsNewIndex !== false && $locationsIdIndex !== false) {
            $this->assertLessThan(
                $locationsIdIndex,
                $locationsNewIndex,
                "Route 'slots/locations/new' must come BEFORE 'slots/locations/<id:\d+>' to prevent 'new' from being matched as an ID"
            );
        }

        // Check bookings routes order
        $bookingsNewIndex = $this->findRouteIndex('slots/bookings/new', $routes);
        $bookingsIdIndex = $this->findRouteIndex('slots/bookings/<id:\d+>', $routes);

        if ($bookingsNewIndex !== false && $bookingsIdIndex !== false) {
            $this->assertLessThan(
                $bookingsIdIndex,
                $bookingsNewIndex,
                "Route 'slots/bookings/new' must come BEFORE 'slots/bookings/<id:\d+>' to prevent 'new' from being matched as an ID"
            );
        }
    }

    /**
     * Test that critical /new routes exist in source code
     * This ensures routes like /services/new don't get accidentally removed
     */
    public function testCriticalNewRoutesExistInSource(): void
    {
        // Use file reading instead of reflection to avoid Craft CMS initialization issues
        $filename = __DIR__ . '/../../src/Slots.php';
        
        if (!file_exists($filename)) {
            $this->markTestSkipped('Slots.php not found');
            return;
        }
        
        $source = file_get_contents($filename);

        $criticalRoutes = [
            "'slots/services/new'",
            "'slots/employees/new'",
            "'slots/locations/new'",
            "'slots/bookings/new'",
        ];

        foreach ($criticalRoutes as $route) {
            $this->assertStringContainsString(
                $route,
                $source,
                "Critical route {$route} not found in Slots.php source code. This will cause 404 errors."
            );
        }
    }

    /**
     * Helper: Find route index in array
     */
    private function findRouteIndex(string $pattern, array $routes): int|false
    {
        foreach ($routes as $index => $route) {
            if ($this->routesMatch($pattern, $route)) {
                return $index;
            }
        }
        return false;
    }

    /**
     * Helper: Get routes from Booked plugin source file
     * This reads the actual source code to verify routes are defined
     */
    private function getRoutesFromBookedPlugin(): array
    {
        $class = new ReflectionClass(\anvildev\slots\Slots::class);
        $filename = $class->getFileName();
        $source = file_get_contents($filename);

        // Extract all route patterns from the source
        $routes = [];
        
        // Look for route definitions in the registerCpRoutes method
        // Pattern: 'slots/...' => 'slots/cp/...'
        if (preg_match_all(
            "/['\"](\s*booked\/[^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $pattern = trim($match[1]);
                $route = $match[2];
                $routes[$pattern] = $route;
            }
        }

        return $routes;
    }

    /**
     * Helper: Check if two route patterns match
     * Handles regex patterns like <id:\d+>
     */
    private function routesMatch(string $pattern1, string $pattern2): bool
    {
        // Exact match
        if ($pattern1 === $pattern2) {
            return true;
        }

        // Normalize regex patterns for comparison
        $normalized1 = preg_replace('/<[^>]+:\d+>/', '<id:\d+>', $pattern1);
        $normalized2 = preg_replace('/<[^>]+:\d+>/', '<id:\d+>', $pattern2);

        return $normalized1 === $normalized2;
    }
}
