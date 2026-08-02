<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\DateHelper;
use anvildev\slots\tests\Support\TestCase;

/**
 * DateHelper Test
 *
 * Tests the DateHelper utility methods for date/time parsing and comparison
 */
class DateHelperTest extends TestCase
{
    // =========================================================================
    // parseTime() Tests
    // =========================================================================

    public function testParseTimeWithValidHiFormat(): void
    {
        $result = DateHelper::parseTime('14:30');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('14:30', $result->format('H:i'));
    }

    public function testParseTimeWithValidHisFormat(): void
    {
        $result = DateHelper::parseTime('14:30:45');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('14:30:45', $result->format('H:i:s'));
    }

    public function testParseTimeWithEmptyStringReturnsNull(): void
    {
        $result = DateHelper::parseTime('');

        $this->assertNull($result);
    }

    public function testParseTimeWithInvalidTimeReturnsNull(): void
    {
        $result = DateHelper::parseTime('invalid');

        $this->assertNull($result);
    }

    public function testParseTimeWithTimezone(): void
    {
        $result = DateHelper::parseTime('14:30', 'Europe/London');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('Europe/London', $result->getTimezone()->getName());
    }

    public function testParseTimeWithInvalidTimezoneUsesDefault(): void
    {
        $result = DateHelper::parseTime('14:30', 'Invalid/Timezone');

        // Should still return a valid DateTime, just with system default timezone
        $this->assertInstanceOf(\DateTime::class, $result);
    }

    public function testParseTimeMidnight(): void
    {
        $result = DateHelper::parseTime('00:00');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('00:00', $result->format('H:i'));
    }

    public function testParseTimeEndOfDay(): void
    {
        $result = DateHelper::parseTime('23:59');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('23:59', $result->format('H:i'));
    }

    public function testParseTimeWithSecondsAtMidnight(): void
    {
        $result = DateHelper::parseTime('00:00:00');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    // =========================================================================
    // parseDate() Tests
    // =========================================================================

    public function testParseDateWithValidFormat(): void
    {
        $result = DateHelper::parseDate('2025-06-15');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-06-15', $result->format('Y-m-d'));
    }

    public function testParseDateWithEmptyStringReturnsNull(): void
    {
        $result = DateHelper::parseDate('');

        $this->assertNull($result);
    }

    public function testParseDateWithInvalidDateReturnsNull(): void
    {
        $result = DateHelper::parseDate('invalid');

        $this->assertNull($result);
    }

    public function testParseDateWithWrongFormatReturnsNull(): void
    {
        $result = DateHelper::parseDate('15-06-2025'); // DD-MM-YYYY instead of Y-m-d

        $this->assertNull($result);
    }

    public function testParseDateWithTimezone(): void
    {
        $result = DateHelper::parseDate('2025-06-15', 'America/New_York');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('America/New_York', $result->getTimezone()->getName());
    }

    public function testParseDateWithInvalidTimezoneUsesDefault(): void
    {
        $result = DateHelper::parseDate('2025-06-15', 'Invalid/Timezone');

        // Should still return a valid DateTime
        $this->assertInstanceOf(\DateTime::class, $result);
    }

    public function testParseDateLeapYear(): void
    {
        $result = DateHelper::parseDate('2024-02-29');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2024-02-29', $result->format('Y-m-d'));
    }

    public function testParseDateFirstDayOfYear(): void
    {
        $result = DateHelper::parseDate('2025-01-01');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-01-01', $result->format('Y-m-d'));
    }

    public function testParseDateLastDayOfYear(): void
    {
        $result = DateHelper::parseDate('2025-12-31');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-12-31', $result->format('Y-m-d'));
    }

    // =========================================================================
    // parseDateTime() Tests
    // =========================================================================

    public function testParseDateTimeWithValidFormats(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', '14:30');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-06-15 14:30', $result->format('Y-m-d H:i'));
    }

    public function testParseDateTimeWithSeconds(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', '14:30:45');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-06-15 14:30:45', $result->format('Y-m-d H:i:s'));
    }

    public function testParseDateTimeWithEmptyDateReturnsNull(): void
    {
        $result = DateHelper::parseDateTime('', '14:30');

        $this->assertNull($result);
    }

    public function testParseDateTimeWithEmptyTimeReturnsNull(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', '');

        $this->assertNull($result);
    }

    public function testParseDateTimeWithBothEmptyReturnsNull(): void
    {
        $result = DateHelper::parseDateTime('', '');

        $this->assertNull($result);
    }

    public function testParseDateTimeWithInvalidDateReturnsNull(): void
    {
        $result = DateHelper::parseDateTime('invalid', '14:30');

        $this->assertNull($result);
    }

    public function testParseDateTimeWithInvalidTimeReturnsNull(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', 'invalid');

        $this->assertNull($result);
    }

    public function testParseDateTimeWithTimezone(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', '14:30', 'Asia/Tokyo');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('Asia/Tokyo', $result->getTimezone()->getName());
    }

    public function testParseDateTimeAtMidnight(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', '00:00');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-06-15 00:00', $result->format('Y-m-d H:i'));
    }

    public function testParseDateTimeAtEndOfDay(): void
    {
        $result = DateHelper::parseDateTime('2025-06-15', '23:59');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals('2025-06-15 23:59', $result->format('Y-m-d H:i'));
    }

    // =========================================================================
    // today() Tests
    // =========================================================================

    public function testTodayReturnsValidDate(): void
    {
        $result = DateHelper::today();

        $this->assertIsValidDate($result);
    }

    public function testTodayMatchesCurrentDate(): void
    {
        $result = DateHelper::today();
        $expected = (new \DateTime())->format('Y-m-d');

        $this->assertEquals($expected, $result);
    }

    // =========================================================================
    // relativeDate() Tests
    // =========================================================================

    public function testRelativeDatePlusOneDay(): void
    {
        $result = DateHelper::relativeDate('+1 day');

        $expected = (new \DateTime())->modify('+1 day')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDateMinusOneDay(): void
    {
        $result = DateHelper::relativeDate('-1 day');

        $expected = (new \DateTime())->modify('-1 day')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDatePlusOneMonth(): void
    {
        $result = DateHelper::relativeDate('+1 month');

        $expected = (new \DateTime())->modify('+1 month')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDatePlus90Days(): void
    {
        $result = DateHelper::relativeDate('+90 days');

        $expected = (new \DateTime())->modify('+90 days')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDatePlusOneWeek(): void
    {
        $result = DateHelper::relativeDate('+1 week');

        $expected = (new \DateTime())->modify('+1 week')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDatePlusOneYear(): void
    {
        $result = DateHelper::relativeDate('+1 year');

        $expected = (new \DateTime())->modify('+1 year')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDateNextMonday(): void
    {
        $result = DateHelper::relativeDate('next monday');

        $expected = (new \DateTime())->modify('next monday')->format('Y-m-d');
        $this->assertEquals($expected, $result);
    }

    public function testRelativeDateReturnsValidFormat(): void
    {
        $result = DateHelper::relativeDate('+1 day');

        $this->assertIsValidDate($result);
    }
}
