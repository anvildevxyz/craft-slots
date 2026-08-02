<?php

namespace anvildev\slots\tests\Unit\Models;

use anvildev\slots\models\forms\BookingForm;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingForm Model Test
 *
 * Tests the BookingForm model validation and functionality
 */
class BookingFormTest extends TestCase
{
    public function testRequiredFieldsValidation(): void
    {
        $form = new BookingForm();

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('userName', $form->getErrors());
        $this->assertArrayHasKey('userEmail', $form->getErrors());
        $this->assertArrayHasKey('bookingDate', $form->getErrors());
        $this->assertArrayHasKey('startTime', $form->getErrors());
        $this->assertArrayHasKey('endTime', $form->getErrors());
        $this->assertArrayHasKey('serviceId', $form->getErrors());
    }

    public function testValidDataPassesValidation(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $this->assertTrue($form->validate());
        $this->assertEmpty($form->getErrors());
    }

    public function testEmailValidation(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'invalid-email',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('userEmail', $form->getErrors());
    }

    public function testEmailIsLowercased(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'JOHN@EXAMPLE.COM',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $form->validate();
        $this->assertEquals('john@example.com', $form->userEmail);
    }

    public function testStringFieldsMaxLength(): void
    {
        $longString = str_repeat('a', 300);

        $form = new BookingForm([
            'userName' => $longString,
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('userName', $form->getErrors());
    }

    public function testInputSanitization(): void
    {
        $form = new BookingForm([
            'userName' => '<script>alert("xss")</script>John Doe',
            'userEmail' => 'john@example.com',
            'userPhone' => '<b>+1234567890</b>',
            'notes' => '<a href="#">Test</a>',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $form->validate();

        $this->assertStringNotContainsString('<script>', $form->userName);
        $this->assertStringNotContainsString('<b>', $form->userPhone);
        $this->assertStringNotContainsString('<a', $form->notes);
    }

    public function testInputTrimming(): void
    {
        $form = new BookingForm([
            'userName' => '  John Doe  ',
            'userEmail' => '  john@example.com  ',
            'userPhone' => '  +1234567890  ',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $form->validate();

        $this->assertEquals('John Doe', $form->userName);
        $this->assertEquals('john@example.com', $form->userEmail);
        $this->assertEquals('+1234567890', $form->userPhone);
    }

    public function testBookingDateMustBeValidFormat(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '15-06-2025', // Wrong format
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('bookingDate', $form->getErrors());
    }

    public function testStartTimeValidFormat(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testStartTimeAcceptsWithSeconds(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00:00',
            'endTime' => '15:00:00',
            'serviceId' => 1,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testStartTimeRejectsInvalidFormat(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '2pm', // Invalid format (should be HH:MM)
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('startTime', $form->getErrors());
    }

    public function testEndTimeRejectsInvalidHours(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '25:00', // Invalid hour
            'serviceId' => 1,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('endTime', $form->getErrors());
    }

    public function testServiceIdAcceptsIntegerStrings(): void
    {
        // PHP's typed properties convert string numbers to integers
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => '123', // String representation should work
        ]);

        $this->assertTrue($form->validate());
        $this->assertSame(123, $form->serviceId); // Converted to int
    }

    public function testEmployeeIdIsOptional(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'employeeId' => null,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testEmployeeIdAcceptsIntegerStrings(): void
    {
        // PHP's typed properties convert string numbers to integers
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'employeeId' => '456', // String representation should work
        ]);

        $this->assertTrue($form->validate());
        $this->assertSame(456, $form->employeeId); // Converted to int
    }

    public function testLocationIdIsOptional(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'locationId' => null,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testQuantityDefaultsToOne(): void
    {
        $form = new BookingForm();

        $this->assertEquals(1, $form->quantity);
    }

    public function testQuantityMustBePositive(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'quantity' => 0,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('quantity', $form->getErrors());
    }

    public function testQuantityMustBeAtLeastOne(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'quantity' => -5,
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('quantity', $form->getErrors());
    }

    public function testUserTimezoneDefaultsToNull(): void
    {
        $form = new BookingForm();

        $this->assertNull($form->userTimezone);
    }

    public function testUserTimezoneValidation(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'userTimezone' => 'Invalid/Timezone',
        ]);

        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('userTimezone', $form->getErrors());
    }

    public function testUserTimezoneAcceptsValidTimezones(): void
    {
        $validTimezones = [
            'Europe/London',
            'America/New_York',
            'Asia/Tokyo',
            'UTC',
        ];

        foreach ($validTimezones as $timezone) {
            $form = new BookingForm([
                'userName' => 'John Doe',
                'userEmail' => 'john@example.com',
                'bookingDate' => '2025-06-15',
                'startTime' => '14:00',
                'endTime' => '15:00',
                'serviceId' => 1,
                'userTimezone' => $timezone,
            ]);

            $this->assertTrue($form->validate(), "Failed for timezone: {$timezone}");
        }
    }

    public function testNotesAreOptional(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'notes' => null,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testPhoneIsOptional(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'userPhone' => null,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testHoneypotIsOptional(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'honeypot' => null,
        ]);

        $this->assertTrue($form->validate());
    }

    public function testCaptchaTokenIsOptional(): void
    {
        $form = new BookingForm([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
            'captchaToken' => null,
        ]);

        $this->assertTrue($form->validate());
    }

    // =========================================================================
    // Phone validation (when SMS enabled)
    // =========================================================================

    public function testExtrasMatchingServicePassValidation(): void
    {
        $form = new class([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]) extends BookingForm {
            protected function getValidExtraIdsForService(int $serviceId): array
            {
                return [10, 20, 30];
            }
        };

        $this->assertTrue($form->validate());
    }

    public function testBookingFormDoesNotUseHtmlspecialcharsForStorage(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/models/forms/BookingForm.php');
        $this->assertStringNotContainsString('htmlspecialchars', $source,
            'BookingForm must not use htmlspecialchars at storage layer');
    }

    public function testAmpersandNotDoubleEncoded(): void
    {
        $form = new BookingForm([
            'userName' => 'Tom & Jerry',
            'userEmail' => 'tom@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'serviceId' => 1,
        ]);

        $form->validate();

        $this->assertEquals('Tom & Jerry', $form->userName);
    }
}
