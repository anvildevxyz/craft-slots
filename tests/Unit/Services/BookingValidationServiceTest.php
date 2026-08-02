<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\BookingValidationService;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingValidationService Test
 *
 * Tests the pure utility functions in BookingValidationService
 */
class BookingValidationServiceTest extends TestCase
{
    private BookingValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingValidationService();
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Fixed Day Period
    // =========================================================================

    public function testCalculateLimitDateRangeFixedDay(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'fixed', '2024-06-15');

        $this->assertEquals('2024-06-15', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedDayFirstOfMonth(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'fixed', '2024-01-01');

        $this->assertEquals('2024-01-01', $result['start']);
        $this->assertEquals('2024-01-01', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedDayLastOfMonth(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'fixed', '2024-01-31');

        $this->assertEquals('2024-01-31', $result['start']);
        $this->assertEquals('2024-01-31', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Fixed Week Period
    // =========================================================================

    public function testCalculateLimitDateRangeFixedWeekMidWeek(): void
    {
        // June 12, 2024 is a Wednesday
        $result = $this->service->calculateLimitDateRange('week', 'fixed', '2024-06-12');

        // Week starts on Monday (June 10) and ends on Sunday (June 16)
        $this->assertEquals('2024-06-10', $result['start']);
        $this->assertEquals('2024-06-16', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedWeekMonday(): void
    {
        // June 10, 2024 is a Monday
        $result = $this->service->calculateLimitDateRange('week', 'fixed', '2024-06-10');

        $this->assertEquals('2024-06-10', $result['start']);
        $this->assertEquals('2024-06-16', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedWeekSunday(): void
    {
        // June 16, 2024 is a Sunday
        $result = $this->service->calculateLimitDateRange('week', 'fixed', '2024-06-16');

        $this->assertEquals('2024-06-10', $result['start']);
        $this->assertEquals('2024-06-16', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedWeekCrossesMonthBoundary(): void
    {
        // June 30, 2024 is a Sunday
        $result = $this->service->calculateLimitDateRange('week', 'fixed', '2024-06-30');

        // Week starts on Monday (June 24) and ends on Sunday (June 30)
        $this->assertEquals('2024-06-24', $result['start']);
        $this->assertEquals('2024-06-30', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Fixed Month Period
    // =========================================================================

    public function testCalculateLimitDateRangeFixedMonthJanuary(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2024-01-15');

        $this->assertEquals('2024-01-01', $result['start']);
        $this->assertEquals('2024-01-31', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedMonthFebruaryLeapYear(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2024-02-15');

        $this->assertEquals('2024-02-01', $result['start']);
        $this->assertEquals('2024-02-29', $result['end']); // Leap year
    }

    public function testCalculateLimitDateRangeFixedMonthFebruaryNonLeapYear(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2023-02-15');

        $this->assertEquals('2023-02-01', $result['start']);
        $this->assertEquals('2023-02-28', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedMonthDecember(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2024-12-25');

        $this->assertEquals('2024-12-01', $result['start']);
        $this->assertEquals('2024-12-31', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedMonthFromFirstDay(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2024-06-01');

        $this->assertEquals('2024-06-01', $result['start']);
        $this->assertEquals('2024-06-30', $result['end']);
    }

    public function testCalculateLimitDateRangeFixedMonthFromLastDay(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2024-06-30');

        $this->assertEquals('2024-06-01', $result['start']);
        $this->assertEquals('2024-06-30', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Fixed Custom Days Period
    // =========================================================================

    public function testCalculateLimitDateRangeFixedCustomDays30(): void
    {
        $result = $this->service->calculateLimitDateRange('30', 'fixed', '2024-06-15');

        // Trailing window: 30 days before reference, end = reference
        $this->assertEquals('2024-05-16', $result['start']); // -30 days
        $this->assertEquals('2024-06-15', $result['end']);   // reference date
    }

    public function testCalculateLimitDateRangeFixedCustomDays14(): void
    {
        $result = $this->service->calculateLimitDateRange('14', 'fixed', '2024-06-15');

        // Trailing window: 14 days before reference, end = reference
        $this->assertEquals('2024-06-01', $result['start']); // -14 days
        $this->assertEquals('2024-06-15', $result['end']);   // reference date
    }

    public function testCalculateLimitDateRangeFixedCustomDaysOddNumber(): void
    {
        $result = $this->service->calculateLimitDateRange('7', 'fixed', '2024-06-15');

        // Trailing window: 7 days before reference, end = reference
        $this->assertEquals('2024-06-08', $result['start']); // -7 days
        $this->assertEquals('2024-06-15', $result['end']);   // reference date
    }

    public function testCalculateLimitDateRangeFixedDefaultsTo30DaysForInvalidPeriod(): void
    {
        $result = $this->service->calculateLimitDateRange('invalid', 'fixed', '2024-06-15');

        // Default to 30, trailing window: -30 days, end = reference
        $this->assertEquals('2024-05-16', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Rolling Day Period
    // =========================================================================

    public function testCalculateLimitDateRangeRollingDay(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'rolling', '2024-06-15');

        // 1 day before up to reference date (backwards-only rolling window)
        $this->assertEquals('2024-06-14', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingDayAtMonthStart(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'rolling', '2024-06-01');

        $this->assertEquals('2024-05-31', $result['start']);
        $this->assertEquals('2024-06-01', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingDayAtYearStart(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'rolling', '2024-01-01');

        $this->assertEquals('2023-12-31', $result['start']);
        $this->assertEquals('2024-01-01', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Rolling Week Period
    // =========================================================================

    public function testCalculateLimitDateRangeRollingWeek(): void
    {
        $result = $this->service->calculateLimitDateRange('week', 'rolling', '2024-06-15');

        // 7 days before up to reference date (backwards-only rolling window)
        $this->assertEquals('2024-06-08', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingWeekAtMonthEnd(): void
    {
        $result = $this->service->calculateLimitDateRange('week', 'rolling', '2024-06-30');

        $this->assertEquals('2024-06-23', $result['start']);
        $this->assertEquals('2024-06-30', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Rolling Month Period
    // =========================================================================

    public function testCalculateLimitDateRangeRollingMonth(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'rolling', '2024-06-15');

        // 1 month before up to reference date
        $this->assertEquals('2024-05-15', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingMonthFebruary(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'rolling', '2024-02-15');

        // 1 month before up to reference date
        $this->assertEquals('2024-01-15', $result['start']);
        $this->assertEquals('2024-02-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingMonthCrossesYearBoundary(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'rolling', '2024-01-15');

        // 1 month before goes into December 2023
        $this->assertEquals('2023-12-15', $result['start']);
        $this->assertEquals('2024-01-15', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Rolling Custom Days Period
    // =========================================================================

    public function testCalculateLimitDateRangeRollingCustomDays(): void
    {
        $result = $this->service->calculateLimitDateRange('14', 'rolling', '2024-06-15');

        // 14 days before up to reference date (backwards-only rolling window)
        $this->assertEquals('2024-06-01', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingCustomDays60(): void
    {
        $result = $this->service->calculateLimitDateRange('60', 'rolling', '2024-06-15');

        // 60 days before up to reference date (backwards-only rolling window)
        $this->assertEquals('2024-04-16', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingDefaultsTo30DaysForInvalidPeriod(): void
    {
        $result = $this->service->calculateLimitDateRange('invalid', 'rolling', '2024-06-15');

        // Default to 30 (backwards-only rolling window)
        $this->assertEquals('2024-05-16', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingZeroDaysDefaultsTo30(): void
    {
        $result = $this->service->calculateLimitDateRange('0', 'rolling', '2024-06-15');

        // Zero becomes 30 (backwards-only rolling window)
        $this->assertEquals('2024-05-16', $result['start']);
        $this->assertEquals('2024-06-15', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Edge Cases
    // =========================================================================

    public function testCalculateLimitDateRangeEmptyReferenceDate(): void
    {
        // Without a reference date, it uses current date
        $result = $this->service->calculateLimitDateRange('day', 'fixed', '');

        // Should be today
        $today = date('Y-m-d');
        $this->assertEquals($today, $result['start']);
        $this->assertEquals($today, $result['end']);
    }

    public function testCalculateLimitDateRangeLeapYearFebruary29(): void
    {
        $result = $this->service->calculateLimitDateRange('day', 'fixed', '2024-02-29');

        $this->assertEquals('2024-02-29', $result['start']);
        $this->assertEquals('2024-02-29', $result['end']);
    }

    public function testCalculateLimitDateRangeRollingFromLeapDay(): void
    {
        $result = $this->service->calculateLimitDateRange('week', 'rolling', '2024-02-29');

        // 7 days before up to reference date (backwards-only rolling window)
        $this->assertEquals('2024-02-22', $result['start']);
        $this->assertEquals('2024-02-29', $result['end']);
    }

    // =========================================================================
    // calculateLimitDateRange() Tests - Array Return Structure
    // =========================================================================

    public function testCalculateLimitDateRangeReturnsCorrectArrayStructure(): void
    {
        $result = $this->service->calculateLimitDateRange('month', 'fixed', '2024-06-15');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
        $this->assertCount(2, $result);
    }

    public function testCalculateLimitDateRangeStartBeforeEnd(): void
    {
        $testCases = [
            ['day', 'fixed'],
            ['week', 'fixed'],
            ['month', 'fixed'],
            ['day', 'rolling'],
            ['week', 'rolling'],
            ['month', 'rolling'],
        ];

        foreach ($testCases as [$period, $periodType]) {
            $result = $this->service->calculateLimitDateRange($period, $periodType, '2024-06-15');

            $this->assertLessThanOrEqual(
                $result['end'],
                $result['start'],
                "Start date should be <= end date for period={$period}, periodType={$periodType}"
            );
        }
    }

    // =========================================================================
    // Rate Limiting — Structural Verification
    // =========================================================================

    public function testCheckAllRateLimitsMethodExists(): void
    {
        $this->assertTrue(
            method_exists(BookingValidationService::class, 'checkAllRateLimits'),
            'checkAllRateLimits method must exist'
        );
    }

    public function testCheckAllRateLimitsReturnsCorrectStructure(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingValidationService.php'
        );

        // Verify it returns {allowed: bool, reason: ?string}
        $this->assertStringContainsString(
            "return ['allowed' => true, 'reason' => null]",
            $source,
            'checkAllRateLimits must return allowed+reason array'
        );
        $this->assertStringContainsString(
            "'allowed' => false, 'reason' => 'email_rate_limit'",
            $source,
            'Must return email_rate_limit reason when email limit exceeded'
        );
        $this->assertStringContainsString(
            "'allowed' => false, 'reason' => 'ip_rate_limit'",
            $source,
            'Must return ip_rate_limit reason when IP limit exceeded'
        );
    }

    public function testEmailRateLimitComparesAgainstSetting(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingValidationService.php'
        );

        // Verify email rate limiting uses rateLimitPerEmail setting
        $this->assertStringContainsString(
            '$settings->rateLimitPerEmail',
            $source,
            'Email rate limit must compare against rateLimitPerEmail setting'
        );
    }

    public function testIpRateLimitComparesAgainstSetting(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingValidationService.php'
        );

        // Verify IP rate limiting uses rateLimitPerIp setting
        $this->assertStringContainsString(
            '$settings->rateLimitPerIp',
            $source,
            'IP rate limit must compare against rateLimitPerIp setting'
        );
    }

    public function testEmailRateLimitCountsOnlyTodaysNonCancelledBookings(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingValidationService.php'
        );

        // Extract checkAllRateLimits method
        preg_match('/function checkAllRateLimits\b.*?^    \}/ms', $source, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString('userEmail', $method, 'Must filter by email');
        $this->assertStringContainsString('dateCreated', $method, 'Must filter by date');
        $this->assertStringContainsString('STATUS_CANCELLED', $method, 'Must exclude cancelled bookings');
    }

    public function testIpRateLimitUsesCache(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingValidationService.php'
        );

        // IP rate limit uses cache-based tracking
        $this->assertStringContainsString(
            'booking_ip_limit_',
            $source,
            'IP rate limit must use cache with booking_ip_limit_ prefix'
        );
    }

    public function testRateLimitFailsOpenOnError(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingValidationService.php'
        );

        // Both email and IP rate limits should catch exceptions
        preg_match('/function checkAllRateLimits\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        // Count catch blocks — should have at least 2 (one for email, one for IP)
        $catchCount = substr_count($method, 'catch (\\Exception');
        $this->assertGreaterThanOrEqual(2, $catchCount, 'checkAllRateLimits must have catch blocks for fail-open behavior');
    }

    // =========================================================================
    // Rate Limiting — Settings Defaults
    // =========================================================================

    public function testRateLimitingEnabledByDefault(): void
    {
        $settings = new \anvildev\slots\models\Settings();

        $this->assertTrue($settings->enableRateLimiting);
    }

    public function testEmailRateLimitDefaultIs5(): void
    {
        $settings = new \anvildev\slots\models\Settings();

        $this->assertSame(5, $settings->rateLimitPerEmail);
    }

    public function testIpRateLimitDefaultIs10(): void
    {
        $settings = new \anvildev\slots\models\Settings();

        $this->assertSame(10, $settings->rateLimitPerIp);
    }

    public function testRateLimitSettingsHaveMinOneValidation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/models/Settings.php'
        );

        $this->assertStringContainsString(
            "rateLimitPerEmail', 'rateLimitPerIp'], 'integer', 'min' => 1",
            $source,
            'Rate limit settings must have min:1 validation'
        );
    }
}
