<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\BlackoutDate;
use anvildev\slots\tests\Support\TestCase;

/**
 * BlackoutDate Element Test
 *
 * Tests pure business logic methods on the BlackoutDate element.
 * Uses ReflectionClass to bypass Element::init() which requires Craft::$app.
 *
 * DB-dependent methods (getLocations, getEmployees) require integration tests.
 */
class BlackoutDateTest extends TestCase
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

    /**
     * Create a BlackoutDate instance without calling init()
     */
    private function makeBlackout(array $props = []): BlackoutDate
    {
        $ref = new \ReflectionClass(BlackoutDate::class);
        $blackout = $ref->newInstanceWithoutConstructor();

        $blackout->startDate = $props['startDate'] ?? '2025-06-15';
        $blackout->endDate = $props['endDate'] ?? '2025-06-15';
        $blackout->isActive = $props['isActive'] ?? true;
        $blackout->locationIds = $props['locationIds'] ?? [];
        $blackout->employeeIds = $props['employeeIds'] ?? [];

        return $blackout;
    }

    // =========================================================================
    // getFormattedDateRange()
    // =========================================================================

    public function testGetFormattedDateRangeSingleDay(): void
    {
        $blackout = $this->makeBlackout([
            'startDate' => '2025-06-15',
            'endDate' => '2025-06-15',
        ]);

        $result = $blackout->getFormattedDateRange();

        // IntlDateFormatter with de_CH and MEDIUM format
        // Should be a single date (not a range)
        $this->assertIsString($result);
        $this->assertStringNotContainsString(' - ', $result);
    }

    public function testGetFormattedDateRangeMultipleDays(): void
    {
        $blackout = $this->makeBlackout([
            'startDate' => '2025-06-15',
            'endDate' => '2025-06-20',
        ]);

        $result = $blackout->getFormattedDateRange();

        // Should contain a separator for range
        $this->assertIsString($result);
        $this->assertStringContainsString(' - ', $result);
    }

    public function testGetStatusReturnsActiveWhenActive(): void
    {
        $blackout = $this->makeBlackout(['isActive' => true]);
        $this->assertEquals('active', $blackout->getStatus());
    }

    public function testGetStatusReturnsInactiveWhenNotActive(): void
    {
        $blackout = $this->makeBlackout(['isActive' => false]);
        $this->assertEquals('inactive', $blackout->getStatus());
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(BlackoutDate::hasTitles());
    }

    public function testHasStatusesReturnsTrue(): void
    {
        $this->assertTrue(BlackoutDate::hasStatuses());
    }

    public function testRefHandleReturnsBlackoutDate(): void
    {
        $this->assertEquals('blackoutDate', BlackoutDate::refHandle());
    }

    public function testStatusesContainsActiveAndInactive(): void
    {
        $statuses = BlackoutDate::statuses();
        $this->assertArrayHasKey('active', $statuses);
        $this->assertArrayHasKey('inactive', $statuses);
    }
}
