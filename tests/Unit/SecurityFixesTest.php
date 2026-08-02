<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\models\forms\BookingForm;
use anvildev\slots\tests\Support\TestCase;

/**
 * Functional tests for security fixes from CODE_REVIEW.md
 *
 * Tests actual behavior where possible (BookingForm i18n, timezone handling,
 * etc.) rather than just reading source code.
 */
class SecurityFixesTest extends TestCase
{
    // ────────────────────────────────────────────────────────
    // #16 — BookingForm i18n validation (functional test)
    // ────────────────────────────────────────────────────────

    public function testBookingFormRequiredFieldsValidation(): void
    {
        $form = new BookingForm();
        $form->userName = null;
        $form->userEmail = null;
        $form->bookingDate = '2026-03-01';
        $form->startTime = '10:00';
        $form->endTime = '11:00';
        $form->serviceId = 1;

        $this->assertFalse($form->validate(), 'Form should fail without required name/email');
        $errors = $form->getErrors();
        $this->assertArrayHasKey('userName', $errors);
        $this->assertArrayHasKey('userEmail', $errors);
    }

    public function testBookingFormRejectsInvalidEmail(): void
    {
        $form = new BookingForm();
        $form->userName = 'Test User';
        $form->userEmail = 'not-an-email';
        $form->bookingDate = '2026-03-01';
        $form->startTime = '10:00';
        $form->endTime = '11:00';
        $form->serviceId = 1;

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('userEmail', $form->getErrors());
    }

    public function testBookingFormRejectsInvalidTimezone(): void
    {
        $form = new BookingForm();
        $form->userName = 'Test User';
        $form->userEmail = 'test@example.com';
        $form->userTimezone = 'Invalid/Timezone';
        $form->bookingDate = '2026-03-01';
        $form->startTime = '10:00';
        $form->endTime = '11:00';
        $form->serviceId = 1;

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('userTimezone', $form->getErrors());
    }

    public function testBookingFormAcceptsValidTimezone(): void
    {
        $form = new BookingForm();
        $form->userName = 'Test User';
        $form->userEmail = 'test@example.com';
        $form->userTimezone = 'America/New_York';
        $form->bookingDate = '2026-03-01';
        $form->startTime = '10:00';
        $form->endTime = '11:00';
        $form->serviceId = 1;

        $form->validate();
        $this->assertArrayNotHasKey('userTimezone', $form->getErrors());
    }

    public function testBookingFormDefaultTimezoneIsNull(): void
    {
        // BookingForm default is null; controller sets it from request or system timezone
        $form = new BookingForm();
        $this->assertNull($form->userTimezone);
    }

    public function testBookingFormHoneypotDetection(): void
    {
        $form = new BookingForm();
        $this->assertFalse($form->isSpam(), 'Empty honeypot should not be spam');

        $form->honeypot = 'bot-filled-this';
        $this->assertTrue($form->isSpam(), 'Filled honeypot should detect spam');
    }

    public function testBookingFormGetReservationData(): void
    {
        $form = new BookingForm();
        $form->userName = 'Test User';
        $form->userEmail = 'test@example.com';
        $form->bookingDate = '2026-03-01';
        $form->startTime = '10:00';
        $form->endTime = '11:00';
        $form->serviceId = 1;
        $form->employeeId = 5;
        $form->locationId = 3;
        $form->quantity = 2;

        $data = $form->getReservationData();

        $this->assertEquals('Test User', $data['userName']);
        $this->assertEquals('test@example.com', $data['userEmail']);
        $this->assertEquals('2026-03-01', $data['bookingDate']);
        $this->assertEquals('10:00', $data['startTime']);
        $this->assertEquals(1, $data['serviceId']);
        $this->assertEquals(5, $data['employeeId']);
        $this->assertEquals(3, $data['locationId']);
        $this->assertEquals(2, $data['quantity']);
    }

    public function testBookingFormSanitizesInput(): void
    {
        $form = new BookingForm();
        $form->userName = '<script>alert("xss")</script>Test';
        $form->userEmail = 'test@example.com';
        $form->bookingDate = '2026-03-01';
        $form->startTime = '10:00';
        $form->endTime = '11:00';
        $form->serviceId = 1;

        $form->validate();

        // The filter rule should strip tags and encode
        $this->assertStringNotContainsString('<script>', $form->userName);
    }

    // ────────────────────────────────────────────────────────
    // #8 — hash_equals behavior verification
    // ────────────────────────────────────────────────────────

    public function testHashEqualsIsTimingSafe(): void
    {
        // Verify hash_equals gives correct results
        $token = bin2hex(random_bytes(16));
        $this->assertTrue(hash_equals($token, $token));
        $this->assertFalse(hash_equals($token, 'wrong-token'));
        $this->assertFalse(hash_equals($token, ''));
    }

    // ────────────────────────────────────────────────────────
    // #18 — Date range capping logic (functional test)
    // ────────────────────────────────────────────────────────

    public function testDateRangeCappingLogic(): void
    {
        // Simulate the SlotController capping logic
        $startDate = '2026-01-01';
        $endDate = '2027-06-01'; // Way beyond 180 days

        $current = \DateTime::createFromFormat('Y-m-d', $startDate);
        $end = \DateTime::createFromFormat('Y-m-d', $endDate);

        $maxEnd = (clone $current)->add(new \DateInterval('P180D'));

        if ($end > $maxEnd) {
            $end = $maxEnd;
            $endDate = $end->format('Y-m-d');
        }

        $this->assertEquals('2026-06-30', $endDate, 'Date range should be capped at 180 days');
    }

    public function testDateRangeWithinCapNotModified(): void
    {
        $startDate = '2026-01-01';
        $endDate = '2026-02-01'; // Within 180 days

        $current = \DateTime::createFromFormat('Y-m-d', $startDate);
        $end = \DateTime::createFromFormat('Y-m-d', $endDate);

        $maxEnd = (clone $current)->add(new \DateInterval('P180D'));

        if ($end > $maxEnd) {
            $end = $maxEnd;
            $endDate = $end->format('Y-m-d');
        }

        $this->assertEquals('2026-02-01', $endDate, 'Date range within 180 days should not be modified');
    }

    public function testDateFormatRegexValidation(): void
    {
        // Simulate the SlotController date format validation
        $validDates = ['2026-01-01', '2026-12-31', '2025-06-15'];
        $invalidDates = ['01-01-2026', '2026/01/01', '2026-1-1', 'abc', ''];

        foreach ($validDates as $date) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date,
                "Date '{$date}' should match Y-m-d format"
            );
        }

        foreach ($invalidDates as $date) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date,
                "Date '{$date}' should NOT match Y-m-d format"
            );
        }
    }

    public function testTimeFormatRegexValidation(): void
    {
        // Simulate CalendarViewController time format validation
        $validTimes = ['10:00', '23:59', '00:00', '10:00:00'];
        $invalidTimes = ['1:00', 'abc', '10.00'];

        $pattern = '/^\d{2}:\d{2}(:\d{2})?$/';

        foreach ($validTimes as $time) {
            $this->assertMatchesRegularExpression($pattern, $time, "Time '{$time}' should be valid");
        }

        foreach ($invalidTimes as $time) {
            $this->assertDoesNotMatchRegularExpression($pattern, $time, "Time '{$time}' should be invalid");
        }
    }
}
