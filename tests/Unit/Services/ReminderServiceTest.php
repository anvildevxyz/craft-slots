<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\models\Settings;
use anvildev\slots\services\ReminderService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * ReminderService Test
 *
 * Tests the processReservationReminders() time-based logic using partial mocks.
 * The protected helpers (sendEmailReminder, saveReservation, claimReminderFlag)
 * are mocked out; the actual time comparison and conditional logic is what we test.
 *
 * sendReminders() and getPendingReminders() require integration tests
 * (Slots::getInstance(), ReservationFactory queries).
 */
class ReminderServiceTest extends TestCase
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
     * Create a partial mock with protected method mocking
     */
    private function makePartialService(): MockInterface
    {
        return Mockery::mock(ReminderService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    /**
     * Create a mock reservation implementing ReservationInterface
     * with direct property access support (the service uses $reservation->bookingDate etc.)
     */
    private function makeReservation(array $props = []): MockInterface
    {
        $mock = Mockery::mock(ReservationInterface::class);

        // Set properties directly — Mockery mocks support dynamic property access
        $mock->id = $props['id'] ?? 1;
        $mock->bookingDate = $props['bookingDate'] ?? null;
        $mock->startTime = $props['startTime'] ?? null;
        $mock->emailReminder24hSent = $props['emailReminder24hSent'] ?? false;

        return $mock;
    }

    /**
     * Create settings with given properties
     */
    private function makeSettings(array $overrides = []): Settings
    {
        $settings = new Settings();
        $settings->emailRemindersEnabled = $overrides['emailRemindersEnabled'] ?? true;
        $settings->emailReminderHoursBefore = $overrides['emailReminderHoursBefore'] ?? 24;

        return $settings;
    }

    /**
     * Call processReservationReminders via reflection
     */
    private function callProcessReminders(MockInterface $service, MockInterface $reservation, Settings $settings): bool
    {
        $method = new \ReflectionMethod(ReminderService::class, 'processReservationReminders');
        $method->setAccessible(true);

        return $method->invoke($service, $reservation, $settings);
    }

    // =========================================================================
    // processReservationReminders() - Past bookings
    // =========================================================================

    public function testDoesNotSendRemindersForPastBookings(): void
    {
        $reservation = $this->makeReservation([
            'bookingDate' => date('Y-m-d', strtotime('-1 day')),
            'startTime' => '09:00',
        ]);

        $service = $this->makePartialService();
        $service->shouldNotReceive('sendEmailReminder');

        $settings = $this->makeSettings();
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertFalse($result);
    }

    // =========================================================================
    // processReservationReminders() - Email reminders
    // =========================================================================

    public function testSendsEmailReminderWithinWindow(): void
    {
        // Booking 12 hours from now — within 24h window, above 1h minimum
        $futureTime = new \DateTime('+12 hours');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
            'emailReminder24hSent' => false,
        ]);

        $service = $this->makePartialService();
        $service->shouldReceive('claimReminderFlag')
            ->with($reservation->id, 'emailReminder24hSent')
            ->once()
            ->andReturn(true);
        $service->shouldReceive('sendEmailReminder')
            ->once()
            ->with($reservation, '24h')
            ->andReturn(true);

        $settings = $this->makeSettings(['emailRemindersEnabled' => true]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertTrue($result);
    }

    public function testDoesNotSendEmailReminderWhenAlreadySent(): void
    {
        $futureTime = new \DateTime('+12 hours');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
            'emailReminder24hSent' => true, // Already sent
        ]);

        $service = $this->makePartialService();
        $service->shouldNotReceive('sendEmailReminder');

        $settings = $this->makeSettings(['emailRemindersEnabled' => true]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertFalse($result);
    }

    public function testDoesNotSendEmailReminderWhenDisabled(): void
    {
        $futureTime = new \DateTime('+12 hours');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
        ]);

        $service = $this->makePartialService();
        $service->shouldNotReceive('sendEmailReminder');

        $settings = $this->makeSettings(['emailRemindersEnabled' => false]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertFalse($result);
    }

    public function testDoesNotSendEmailReminderTooFarInFuture(): void
    {
        // Booking 48 hours from now — outside 24h window
        $futureTime = new \DateTime('+48 hours');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
        ]);

        $service = $this->makePartialService();
        $service->shouldNotReceive('sendEmailReminder');

        $settings = $this->makeSettings(['emailRemindersEnabled' => true, 'emailReminderHoursBefore' => 24]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertFalse($result);
    }

    public function testSendsEmailReminderUnderOneHour(): void
    {
        // Booking 30 minutes from now — under 1h but above 0, should still send
        $futureTime = new \DateTime('+30 minutes');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
        ]);

        $service = $this->makePartialService();
        $service->shouldReceive('claimReminderFlag')
            ->with($reservation->id, 'emailReminder24hSent')
            ->once()
            ->andReturn(true);
        $service->shouldReceive('sendEmailReminder')
            ->once()
            ->with($reservation, '24h')
            ->andReturn(true);

        $settings = $this->makeSettings(['emailRemindersEnabled' => true]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertTrue($result);
    }

    // =========================================================================
    // processReservationReminders() - Failed send
    // =========================================================================

    public function testDoesNotMarkSentWhenEmailSendFails(): void
    {
        $futureTime = new \DateTime('+12 hours');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
            'emailReminder24hSent' => false,
        ]);

        $service = $this->makePartialService();
        $service->shouldReceive('claimReminderFlag')
            ->with($reservation->id, 'emailReminder24hSent')
            ->once()
            ->andReturn(true);
        $service->shouldReceive('sendEmailReminder')
            ->once()
            ->andReturn(false); // Send failed
        $service->shouldReceive('revertReminderFlag')
            ->with($reservation->id, 'emailReminder24hSent')
            ->once();

        $settings = $this->makeSettings(['emailRemindersEnabled' => true]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertFalse($result);
    }

    // =========================================================================
    // processReservationReminders() - Custom reminder window
    // =========================================================================

    public function testRespectsCustomEmailReminderHoursSetting(): void
    {
        // Booking 36 hours from now, with reminder window set to 48h
        $futureTime = new \DateTime('+36 hours');

        $reservation = $this->makeReservation([
            'bookingDate' => $futureTime->format('Y-m-d'),
            'startTime' => $futureTime->format('H:i'),
        ]);

        $service = $this->makePartialService();
        $service->shouldReceive('claimReminderFlag')->once()->andReturn(true);
        $service->shouldReceive('sendEmailReminder')->once()->andReturn(true);

        $settings = $this->makeSettings([
            'emailRemindersEnabled' => true,
            'emailReminderHoursBefore' => 48, // Extended window
        ]);
        $result = $this->callProcessReminders($service, $reservation, $settings);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new ReminderService();
        $this->assertInstanceOf(ReminderService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new ReminderService();
        $this->assertTrue(method_exists($service, 'sendReminders'));
        $this->assertTrue(method_exists($service, 'getPendingReminders'));
    }
}
