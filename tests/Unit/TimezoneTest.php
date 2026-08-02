<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\helpers\DateHelper;
use anvildev\slots\helpers\IcsHelper;
use anvildev\slots\elements\Reservation;
use anvildev\slots\tests\Support\TestCase;
use Mockery;

/**
 * Timezone Tests
 *
 * Verifies that timezone handling is dynamic throughout the codebase,
 * using the location's timezone or system timezone instead of hardcoded values.
 */
class TimezoneTest extends TestCase
{
    /**
     * Craft generates CustomFieldBehavior at runtime, so an element cannot be
     * constructed in a bare unit run. Skipping the constructor keeps these
     * checks running without a booted Craft, as ServiceSoftDeleteTest does.
     */
    private function makeReservation(array $attributes = []): Reservation
    {
        $reservation = (new \ReflectionClass(Reservation::class))->newInstanceWithoutConstructor();

        foreach ($attributes as $name => $value) {
            $reservation->$name = $value;
        }

        return $reservation;
    }

    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    // =========================================================================
    // Reservation::getBookingDateTime() timezone tests
    // =========================================================================

    public function testGetBookingDateTimeUsesUserTimezone(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('America/New_York', $dateTime->getTimezone()->getName());
        $this->assertEquals('14:00', $dateTime->format('H:i'));
        $this->assertEquals('2025-06-15', $dateTime->format('Y-m-d'));
    }

    public function testGetBookingDateTimeWithEuropeBerlin(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-12-20',
            'startTime' => '09:30',
            'userTimezone' => 'Europe/Berlin',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('Europe/Berlin', $dateTime->getTimezone()->getName());
        $this->assertEquals('09:30', $dateTime->format('H:i'));
    }

    public function testGetBookingDateTimeWithAsiaTokyo(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '09:00',
            'userTimezone' => 'Asia/Tokyo',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('Asia/Tokyo', $dateTime->getTimezone()->getName());
    }

    public function testGetBookingDateTimeFallsBackToSystemTimezone(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'userTimezone' => null,
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        // Should use system timezone (Yii::$app->getTimeZone()), not hardcoded Europe/Zurich
        $systemTz = \Yii::$app->getTimeZone();
        $this->assertEquals($systemTz, $dateTime->getTimezone()->getName());
    }

    public function testGetBookingDateTimeWithSecondsFormat(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30:00',
            'userTimezone' => 'Pacific/Auckland',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('Pacific/Auckland', $dateTime->getTimezone()->getName());
        $this->assertEquals('14:30', $dateTime->format('H:i'));
    }

    public function testGetBookingDateTimeReturnsNullForEmptyFields(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '',
            'startTime' => '',
        ]);

        $this->assertNull($model->getBookingDateTime());
    }

    public function testGetBookingDateTimeReturnsNullForEmptyDate(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '',
            'startTime' => '14:00',
        ]);

        $this->assertNull($model->getBookingDateTime());
    }

    // =========================================================================
    // Timezone conversion correctness (UTC offset verification)
    // =========================================================================

    public function testTimezoneConversionToUtcNewYork(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // June 15 = EDT (UTC-4), so 14:00 EDT = 18:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('18:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionToUtcTokyo(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '09:00',
            'userTimezone' => 'Asia/Tokyo',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // JST is UTC+9, so 09:00 JST = 00:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('00:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionToUtcLondonSummer(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-07-01',
            'startTime' => '15:00',
            'userTimezone' => 'Europe/London',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // BST (UTC+1) in July, so 15:00 BST = 14:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('14:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionToUtcLondonWinter(): void
    {
        $model = $this->makeReservation([
            'bookingDate' => '2025-01-15',
            'startTime' => '15:00',
            'userTimezone' => 'Europe/London',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // GMT (UTC+0) in January, so 15:00 GMT = 15:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('15:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionDateBoundary(): void
    {
        // Late-night booking in New York should cross date boundary when converted to UTC
        $model = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '22:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // 22:00 EDT = 02:00 UTC next day
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('02:00', $utc->format('H:i'));
        $this->assertEquals('2025-06-16', $utc->format('Y-m-d'));
    }

    // =========================================================================
    // IcsHelper timezone tests
    // =========================================================================

    public function testDstTransitionSpringForward(): void
    {
        // March 9, 2025: US clocks spring forward (2:00 AM -> 3:00 AM)
        // A booking at 3:00 AM should be in EDT (UTC-4)
        $model = $this->makeReservation([
            'bookingDate' => '2025-03-09',
            'startTime' => '15:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // After spring forward, 15:00 EDT = 19:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('19:00', $utc->format('H:i'));
    }

    public function testDstTransitionFallBack(): void
    {
        // November 2, 2025: US clocks fall back (2:00 AM -> 1:00 AM)
        // A booking at 15:00 should be in EST (UTC-5)
        $model = $this->makeReservation([
            'bookingDate' => '2025-11-02',
            'startTime' => '15:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // After fall back, 15:00 EST = 20:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('20:00', $utc->format('H:i'));
    }

    public function testEuropeDstTransition(): void
    {
        // March 30, 2025: Europe clocks spring forward
        $model = $this->makeReservation([
            'bookingDate' => '2025-03-30',
            'startTime' => '15:00',
            'userTimezone' => 'Europe/Berlin',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // After spring forward, CEST (UTC+2), so 15:00 CEST = 13:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('13:00', $utc->format('H:i'));
    }

    // =========================================================================
    // No hardcoded timezones regression tests
    // =========================================================================

    public function testReservationSaveDoesNotHardcodeTimezone(): void
    {
        // Verify the code path in save() uses system timezone, not 'Europe/Zurich'
        $reflection = new \ReflectionClass(Reservation::class);
        $source = file_get_contents($reflection->getFileName());

        // The save() method should not contain hardcoded 'Europe/Zurich'
        // (it was replaced with Craft::$app->getTimeZone())
        $this->assertStringNotContainsString("'Europe/Zurich'", $source,
            'Reservation should not contain hardcoded Europe/Zurich timezone');
    }

    public function testIcsHelperDoesNotHardcodeTimezone(): void
    {
        $reflection = new \ReflectionClass(IcsHelper::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString("'Europe/Zurich'", $source,
            'IcsHelper should not contain hardcoded Europe/Zurich timezone');
    }
}
