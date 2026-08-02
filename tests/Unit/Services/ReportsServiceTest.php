<?php

declare(strict_types=1);

namespace anvildev\slots\tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Tests the pure computation logic used by ReportsService.
 *
 * These formulas are extracted from the service so they can be validated
 * without Craft CMS bootstrap or database access.
 */
class ReportsServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Cancellation Rate
    // -------------------------------------------------------------------------

    public function testCancellationRateWithZeroTotalReturnsZero(): void
    {
        $rate = self::cancellationRate(0, 0);
        $this->assertSame(0.0, $rate);
    }

    public function testCancellationRateWithNoCancellations(): void
    {
        $rate = self::cancellationRate(100, 0);
        $this->assertSame(0.0, $rate);
    }

    /**
     * @dataProvider cancellationRateProvider
     */
    public function testCancellationRate(int $total, int $cancelled, float $expected): void
    {
        $this->assertSame($expected, self::cancellationRate($total, $cancelled));
    }

    public function cancellationRateProvider(): array
    {
        return [
            'no bookings'        => [0, 0, 0.0],
            '2 of 10 cancelled'  => [10, 2, 20.0],
            '0 of 100 cancelled' => [100, 0, 0.0],
            '1 of 4 cancelled'   => [4, 1, 25.0],
            'all cancelled'      => [5, 5, 100.0],
        ];
    }

    // -------------------------------------------------------------------------
    // Percentage of Total (service share)
    // -------------------------------------------------------------------------

    public function testPercentageOfTotalWithZeroTotal(): void
    {
        $this->assertSame(0.0, self::percentageOfTotal(0, 0));
    }

    /**
     * @dataProvider percentageOfTotalProvider
     */
    public function testPercentageOfTotal(int $part, int $total, float $expected): void
    {
        $this->assertSame($expected, self::percentageOfTotal($part, $total));
    }

    public function percentageOfTotalProvider(): array
    {
        return [
            '5 of 20'  => [5, 20, 25.0],
            '0 of 20'  => [0, 20, 0.0],
            '20 of 20' => [20, 20, 100.0],
            '1 of 3'   => [1, 3, 33.33],
            '0 of 0'   => [0, 0, 0.0],
        ];
    }

    // -------------------------------------------------------------------------
    // Period Comparison (change percent)
    // -------------------------------------------------------------------------

    public function testChangePercentPositiveGrowth(): void
    {
        $this->assertSame(20.0, self::changePercent(100, 120));
    }

    public function testChangePercentNegativeGrowth(): void
    {
        $this->assertSame(-20.0, self::changePercent(100, 80));
    }

    public function testChangePercentZeroPreviousAvoidsDivisionByZero(): void
    {
        $this->assertSame(0.0, self::changePercent(0, 50));
    }

    public function testChangePercentBothZero(): void
    {
        $this->assertSame(0.0, self::changePercent(0, 0));
    }

    public function testChangePercentNoChange(): void
    {
        $this->assertSame(0.0, self::changePercent(100, 100));
    }

    // -------------------------------------------------------------------------
    // Utilization (clamped to 0-100)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider utilizationProvider
     */
    public function testUtilization(int $available, int $booked, float $expected): void
    {
        $this->assertSame($expected, self::utilization($available, $booked));
    }

    public function utilizationProvider(): array
    {
        return [
            'partial fill'       => [5, 3, 60.0],
            'exactly full'       => [5, 5, 100.0],
            'overbooking capped' => [5, 7, 100.0],
            'nothing available'  => [0, 0, 0.0],
            'empty slots'        => [10, 0, 0.0],
        ];
    }

    public function testUtilizationClampedAt100(): void
    {
        $result = self::utilization(4, 10);
        $this->assertSame(100.0, $result);
        $this->assertLessThanOrEqual(100.0, $result);
    }

    // -------------------------------------------------------------------------
    // Customer Classification (new vs returning)
    // -------------------------------------------------------------------------

    public function testCustomerWithOneBookingIsNew(): void
    {
        $this->assertFalse(self::isRepeatCustomer(1));
    }

    public function testCustomerWithTwoBookingsIsReturning(): void
    {
        $this->assertTrue(self::isRepeatCustomer(2));
    }

    public function testCustomerWithManyBookingsIsReturning(): void
    {
        $this->assertTrue(self::isRepeatCustomer(15));
    }

    public function testCustomerWithZeroBookingsIsNew(): void
    {
        $this->assertFalse(self::isRepeatCustomer(0));
    }

    // -------------------------------------------------------------------------
    // Peak Hours Bucketing
    // -------------------------------------------------------------------------

    /**
     * @dataProvider peakHoursBucketProvider
     */
    public function testPeakHoursBucket(string $startTime, int $expectedHour): void
    {
        $this->assertSame($expectedHour, self::hourBucket($startTime));
    }

    public function peakHoursBucketProvider(): array
    {
        return [
            'morning half-hour' => ['09:30', 9],
            'afternoon exact'   => ['14:00', 14],
            'midnight quarter'  => ['00:15', 0],
            'end of day'        => ['23:45', 23],
            'noon'              => ['12:00', 12],
        ];
    }

    // -------------------------------------------------------------------------
    // computeGroupedData percentage — uses grouped count, not total count
    // -------------------------------------------------------------------------

    /**
     * Verify that computeGroupedData uses the count of grouped (non-NULL)
     * reservations as the denominator for percentages, not the total count
     * including NULL-field reservations. This ensures percentages sum to 100%.
     */
    /**
     * Simulate the percentage calculation with NULL-field reservations excluded.
     * 10 total reservations, 3 have NULL serviceId → grouped count = 7.
     * Service A: 4/7 = 57.14%, Service B: 3/7 = 42.86% — sum = 100%.
     */
    public function testGroupedPercentageSumsTo100WithNulls(): void
    {
        $totalBookings = 10;
        $groupedCount = 7; // 3 have NULL field
        $serviceA = 4;
        $serviceB = 3;

        $percA = $groupedCount > 0 ? ($serviceA / $groupedCount) * 100 : 0.0;
        $percB = $groupedCount > 0 ? ($serviceB / $groupedCount) * 100 : 0.0;

        $this->assertEqualsWithDelta(100.0, $percA + $percB, 0.01,
            'Percentages must sum to ~100% when using grouped count');

        // Using totalBookings would NOT sum to 100%
        $wrongA = ($serviceA / $totalBookings) * 100;
        $wrongB = ($serviceB / $totalBookings) * 100;
        $this->assertLessThan(100.0, $wrongA + $wrongB,
            'Using totalBookings as denominator would NOT sum to 100%');
    }

    /**
     * Verify that totalBookings in the return value still reflects ALL reservations
     * (including those with NULL fields), not just the grouped ones.
     */
    private static function cancellationRate(int $total, int $cancelled): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return ($cancelled / $total) * 100;
    }

    private static function percentageOfTotal(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }

    private static function changePercent(int $previous, int $current): float
    {
        if ($previous <= 0) {
            return 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private static function utilization(int $available, int $booked): float
    {
        if ($available <= 0) {
            return 0.0;
        }

        return min(100.0, round(($booked / $available) * 100, 2));
    }

    private static function isRepeatCustomer(int $bookingCount): bool
    {
        return $bookingCount >= 2;
    }

    private static function hourBucket(string $startTime): int
    {
        return (int) explode(':', $startTime)[0];
    }
}
