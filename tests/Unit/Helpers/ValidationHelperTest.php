<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\ValidationHelper;
use anvildev\slots\tests\Support\TestCase;

/**
 * ValidationHelper Test
 *
 * Tests the ValidationHelper constants and patterns
 */
class ValidationHelperTest extends TestCase
{
    // =========================================================================
    // TIME_FORMAT_PATTERN Tests
    // =========================================================================

    public function testTimeFormatPatternMatchesHiFormat(): void
    {
        $validTimes = [
            '00:00',
            '09:00',
            '14:30',
            '23:59',
            '0:00',  // Single digit hour
            '9:30',  // Single digit hour
        ];

        foreach ($validTimes as $time) {
            $this->assertMatchesRegularExpression(
                ValidationHelper::TIME_FORMAT_PATTERN,
                $time,
                "Expected '$time' to match TIME_FORMAT_PATTERN"
            );
        }
    }

    public function testTimeFormatPatternMatchesHisFormat(): void
    {
        $validTimes = [
            '00:00:00',
            '09:00:30',
            '14:30:45',
            '23:59:59',
        ];

        foreach ($validTimes as $time) {
            $this->assertMatchesRegularExpression(
                ValidationHelper::TIME_FORMAT_PATTERN,
                $time,
                "Expected '$time' to match TIME_FORMAT_PATTERN"
            );
        }
    }

    public function testTimeFormatPatternRejectsInvalidTimes(): void
    {
        $invalidTimes = [
            '24:00',     // Hour too high
            '25:00',     // Hour way too high
            '14:60',     // Minute too high
            '14:99',     // Minute way too high
            '2pm',       // AM/PM format
            '14:30:60',  // Second too high
            '14',        // Missing minutes
            ':30',       // Missing hours
            'invalid',   // Not a time
            '',          // Empty string
        ];

        foreach ($invalidTimes as $time) {
            $this->assertDoesNotMatchRegularExpression(
                ValidationHelper::TIME_FORMAT_PATTERN,
                $time,
                "Expected '$time' to NOT match TIME_FORMAT_PATTERN"
            );
        }
    }

    public function testTimeFormatPatternBoundaryValues(): void
    {
        // Valid boundary values
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '00:00');
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '23:59');
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '00:00:00');
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '23:59:59');
    }

    // =========================================================================
    // DATE_VALIDATOR Tests
    // =========================================================================

    public function testDateValidatorConstant(): void
    {
        $this->assertEquals('date', ValidationHelper::DATE_VALIDATOR);
    }

    // =========================================================================
    // DATE_FORMAT Tests
    // =========================================================================

    public function testDateFormatConstant(): void
    {
        $this->assertEquals('php:Y-m-d', ValidationHelper::DATE_FORMAT);
    }

    public function testDateFormatIsValidPhpFormat(): void
    {
        // Extract the PHP format part after 'php:'
        $format = str_replace('php:', '', ValidationHelper::DATE_FORMAT);

        // Create a date and format it to verify the format works
        $date = new \DateTime('2025-06-15');
        $formatted = $date->format($format);

        $this->assertEquals('2025-06-15', $formatted);
    }

    // =========================================================================
    // Edge Cases for Time Pattern
    // =========================================================================

    public function testTimePatternSingleDigitHours(): void
    {
        // The pattern allows single digit hours (0-9)
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '0:00');
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '9:30');
    }

    public function testTimePatternLeadingZeros(): void
    {
        // Leading zeros should work
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '00:00');
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '01:05');
        $this->assertMatchesRegularExpression(ValidationHelper::TIME_FORMAT_PATTERN, '09:09');
    }

    public function testTimePatternAllHours(): void
    {
        // Test all valid hours 00-23
        for ($hour = 0; $hour < 24; $hour++) {
            $time = sprintf('%02d:00', $hour);
            $this->assertMatchesRegularExpression(
                ValidationHelper::TIME_FORMAT_PATTERN,
                $time,
                "Hour $hour should be valid"
            );
        }
    }

    public function testTimePatternAllMinutes(): void
    {
        // Test all valid minutes 00-59
        for ($minute = 0; $minute < 60; $minute++) {
            $time = sprintf('12:%02d', $minute);
            $this->assertMatchesRegularExpression(
                ValidationHelper::TIME_FORMAT_PATTERN,
                $time,
                "Minute $minute should be valid"
            );
        }
    }

    public function testTimePatternAllSeconds(): void
    {
        // Test all valid seconds 00-59
        for ($second = 0; $second < 60; $second++) {
            $time = sprintf('12:30:%02d', $second);
            $this->assertMatchesRegularExpression(
                ValidationHelper::TIME_FORMAT_PATTERN,
                $time,
                "Second $second should be valid"
            );
        }
    }
}
