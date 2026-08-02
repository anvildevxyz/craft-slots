<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\tests\Support\TestCase;

class DashboardRevenueTest extends TestCase
{
    public function testRevenueMetricsDoNotLoadAllReservations(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/DashboardService.php');
        $pos = strpos($source, 'function calculateRevenueMetrics');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 2000);

        // Should NOT load all confirmed reservations via ->all()
        $this->assertStringNotContainsString(
            "->all()",
            $methodBody,
            'calculateRevenueMetrics must not load reservations into memory'
        );
    }

    public function testPopularServicesUsesGroupBy(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/DashboardService.php');
        $pos = strpos($source, 'function getPopularServices');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 1000);

        $this->assertStringContainsString(
            'groupBy',
            $methodBody,
            'getPopularServices must use GROUP BY instead of iterating all reservations'
        );

        $this->assertStringNotContainsString(
            'array $reservations',
            $methodBody,
            'getPopularServices must not accept pre-loaded reservations array'
        );
    }
}
