<?php

namespace anvildev\slots\tests\Unit\Queue;

use anvildev\slots\queue\jobs\SendBookingEmailJob;
use anvildev\slots\tests\Support\TestCase;

/**
 * SendBookingEmailJob Test
 *
 * Tests the configuration and behavior of the email queue job
 */
class SendBookingEmailJobTest extends TestCase
{
    // =========================================================================
    // Job Configuration Tests
    // =========================================================================

    public function testJobHasReservationIdProperty(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertTrue(property_exists($job, 'reservationId'));
    }

    public function testJobHasEmailTypeProperty(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertTrue(property_exists($job, 'emailType'));
    }

    public function testJobHasOldStatusProperty(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertTrue(property_exists($job, 'oldStatus'));
    }

    public function testJobHasRecipientEmailProperty(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertTrue(property_exists($job, 'recipientEmail'));
    }

    public function testJobHasAttemptProperty(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertTrue(property_exists($job, 'attempt'));
    }

    public function testJobDefaultAttemptIsOne(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertEquals(1, $job->attempt);
    }

    public function testJobOldStatusDefaultsToNull(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertNull($job->oldStatus);
    }

    public function testJobRecipientEmailDefaultsToNull(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertNull($job->recipientEmail);
    }

    // =========================================================================
    // Job Configuration Assignment Tests
    // =========================================================================

    public function testJobAcceptsReservationId(): void
    {
        $job = new SendBookingEmailJob();
        $job->reservationId = 123;

        $this->assertEquals(123, $job->reservationId);
    }

    public function testJobAcceptsEmailTypeConfirmation(): void
    {
        $job = new SendBookingEmailJob();
        $job->emailType = 'confirmation';

        $this->assertEquals('confirmation', $job->emailType);
    }

    public function testJobAcceptsEmailTypeStatusChange(): void
    {
        $job = new SendBookingEmailJob();
        $job->emailType = 'status_change';

        $this->assertEquals('status_change', $job->emailType);
    }

    public function testJobAcceptsEmailTypeCancellation(): void
    {
        $job = new SendBookingEmailJob();
        $job->emailType = 'cancellation';

        $this->assertEquals('cancellation', $job->emailType);
    }

    public function testJobAcceptsEmailTypeOwnerNotification(): void
    {
        $job = new SendBookingEmailJob();
        $job->emailType = 'owner_notification';

        $this->assertEquals('owner_notification', $job->emailType);
    }

    public function testJobAcceptsEmailTypeReminder24h(): void
    {
        $job = new SendBookingEmailJob();
        $job->emailType = 'reminder_24h';

        $this->assertEquals('reminder_24h', $job->emailType);
    }

    public function testJobAcceptsEmailTypeReminder1h(): void
    {
        $job = new SendBookingEmailJob();
        $job->emailType = 'reminder_1h';

        $this->assertEquals('reminder_1h', $job->emailType);
    }

    public function testJobAcceptsOldStatus(): void
    {
        $job = new SendBookingEmailJob();
        $job->oldStatus = 'pending';

        $this->assertEquals('pending', $job->oldStatus);
    }

    public function testJobAcceptsRecipientEmailOverride(): void
    {
        $job = new SendBookingEmailJob();
        $job->recipientEmail = 'test@example.com';

        $this->assertEquals('test@example.com', $job->recipientEmail);
    }

    // =========================================================================
    // TTR (Time To Reserve) Tests
    // =========================================================================

    public function testGetTtrReturns60Seconds(): void
    {
        $job = new SendBookingEmailJob();

        $this->assertEquals(60, $job->getTtr());
    }

    // =========================================================================
    // Retry Logic Tests
    // =========================================================================

    public function testCanRetryOnFirstAttempt(): void
    {
        $job = new SendBookingEmailJob();
        $error = new \Exception('Test error');

        $this->assertTrue($job->canRetry(1, $error));
    }

    public function testCanRetryOnSecondAttempt(): void
    {
        $job = new SendBookingEmailJob();
        $error = new \Exception('Test error');

        $this->assertTrue($job->canRetry(2, $error));
    }

    public function testCannotRetryOnThirdAttempt(): void
    {
        $job = new SendBookingEmailJob();
        $error = new \Exception('Test error');

        $this->assertFalse($job->canRetry(3, $error));
    }

    public function testCannotRetryOnFourthAttempt(): void
    {
        $job = new SendBookingEmailJob();
        $error = new \Exception('Test error');

        $this->assertFalse($job->canRetry(4, $error));
    }

    // =========================================================================
    // Bulk Configuration Tests
    // =========================================================================

    public function testJobCanBeConfiguredViaConstructor(): void
    {
        $job = new SendBookingEmailJob([
            'reservationId' => 456,
            'emailType' => 'confirmation',
            'oldStatus' => 'pending',
            'recipientEmail' => 'custom@example.com',
            'attempt' => 2,
        ]);

        $this->assertEquals(456, $job->reservationId);
        $this->assertEquals('confirmation', $job->emailType);
        $this->assertEquals('pending', $job->oldStatus);
        $this->assertEquals('custom@example.com', $job->recipientEmail);
        $this->assertEquals(2, $job->attempt);
    }

    // =========================================================================
    // Email Type Validation
    // =========================================================================

    public function testAllSupportedEmailTypes(): void
    {
        $supportedTypes = [
            'confirmation',
            'status_change',
            'cancellation',
            'owner_notification',
            'reminder_24h',
            'reminder_1h',
        ];

        foreach ($supportedTypes as $type) {
            $job = new SendBookingEmailJob();
            $job->emailType = $type;

            $this->assertEquals($type, $job->emailType, "Email type '{$type}' should be assignable");
        }
    }

    // =========================================================================
    // Idempotency Guard (Source Verification)
    // =========================================================================

    public function testExecuteChecksNotificationSentBeforeSending(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/queue/jobs/SendBookingEmailJob.php');
        $executeStart = strpos($source, 'function execute');
        $executeSource = substr($source, $executeStart);

        // Find the idempotency guard — should check notificationSent before mailer->send
        $guardPos = strpos($executeSource, 'notificationSent');
        $sendPos = strpos($executeSource, 'mailer->send');

        $this->assertNotFalse($guardPos, 'Must check notificationSent');
        $this->assertNotFalse($sendPos, 'Must have mailer->send');
        $this->assertLessThan($sendPos, $guardPos,
            'notificationSent check must come before mailer->send');
    }
}
