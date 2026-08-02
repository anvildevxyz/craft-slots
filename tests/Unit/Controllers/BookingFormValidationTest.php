<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\models\forms\BookingForm;
use anvildev\slots\tests\Support\TestCase;

class BookingFormValidationTest extends TestCase
{
    private function validServiceForm(array $overrides = []): BookingForm
    {
        $form = new BookingForm();
        $form->setAttributes(array_merge([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '10:00',
            'endTime' => '11:00',
            'serviceId' => 1,
            'userTimezone' => 'Europe/Zurich',
        ], $overrides), false);
        return $form;
    }

    public function testValidServiceBookingPasses(): void
    {
        $form = $this->validServiceForm();
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testRequiredFieldsForServiceBooking(): void
    {
        $form = new BookingForm();
        $form->validate();
        $errors = $form->getErrors();

        $this->assertArrayHasKey('userName', $errors);
        $this->assertArrayHasKey('userEmail', $errors);
        $this->assertArrayHasKey('bookingDate', $errors);
        $this->assertArrayHasKey('startTime', $errors);
        $this->assertArrayHasKey('endTime', $errors);
        $this->assertArrayHasKey('serviceId', $errors);
    }

    /**
     * @dataProvider invalidEmailProvider
     */
    public function testInvalidEmailFormat(string $email): void
    {
        $form = $this->validServiceForm(['userEmail' => $email]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('userEmail'));
    }

    public static function invalidEmailProvider(): array
    {
        return [
            'no at sign' => ['notanemail'],
            'no domain' => ['user@'],
            'spaces' => ['user @example.com'],
        ];
    }

    public function testInvalidDateFormat(): void
    {
        $form = $this->validServiceForm(['bookingDate' => '15-06-2025']);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('bookingDate'));
    }

    /**
     * @dataProvider invalidTimeProvider
     */
    public function testInvalidTimeFormat(string $time): void
    {
        $form = $this->validServiceForm(['startTime' => $time]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('startTime'));
    }

    public static function invalidTimeProvider(): array
    {
        return [
            'am/pm format' => ['10:00 AM'],
            'invalid hour' => ['25:00'],
            'no colon' => ['1000'],
        ];
    }

    public function testInvalidTimezone(): void
    {
        $form = $this->validServiceForm(['userTimezone' => 'Not/A/Timezone']);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('userTimezone'));
    }

    public function testValidTimezone(): void
    {
        $form = $this->validServiceForm(['userTimezone' => 'America/New_York']);
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testQuantityMinimumIsOne(): void
    {
        $form = $this->validServiceForm(['quantity' => 0]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('quantity'));
    }

    public function testHoneypotSpamDetection(): void
    {
        $form = $this->validServiceForm(['honeypot' => 'spam content']);
        $this->assertTrue($form->isSpam());

        $cleanForm = $this->validServiceForm(['honeypot' => null]);
        $this->assertFalse($cleanForm->isSpam());

        $emptyForm = $this->validServiceForm(['honeypot' => '']);
        $this->assertFalse($emptyForm->isSpam());
    }

    public function testInputSanitizationStripsHtml(): void
    {
        $form = $this->validServiceForm([
            'userName' => '<script>alert("xss")</script>John',
        ]);
        $form->validate();
        $this->assertStringNotContainsString('<script>', $form->userName);
    }

    public function testEmailIsLowercased(): void
    {
        $form = $this->validServiceForm(['userEmail' => 'John@EXAMPLE.COM']);
        $form->validate();
        $this->assertSame('john@example.com', $form->userEmail);
    }
}
