<?php

namespace anvildev\slots\tests\Unit\Queue;

use anvildev\slots\queue\jobs\SendBookingEmailJob;
use anvildev\slots\queue\jobs\SendRemindersJob;
use anvildev\slots\tests\Support\TestCase;

/**
 * Structural checks on the queue jobs.
 *
 * execute() needs Slots::getInstance(), so these assert the shape a job has to
 * have for Craft's queue to drive it correctly — the base class it extends, the
 * properties the queue serialises between push and run, and its time-to-reserve.
 *
 * This class previously covered SendWebhookJob, SendSmsJob and SyncToCalendarJob
 * and was left testing nothing when those features were removed, which PHPUnit
 * reports only as a warning. It now covers the two jobs that actually ship.
 */
class QueueJobsStructureTest extends TestCase
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

    public function testSendBookingEmailJobExtendsBaseJob(): void
    {
        $this->assertTrue(
            is_subclass_of(SendBookingEmailJob::class, \craft\queue\BaseJob::class),
            'SendBookingEmailJob must extend BaseJob for Craft to queue it',
        );
    }

    public function testSendRemindersJobExtendsBaseJob(): void
    {
        $this->assertTrue(
            is_subclass_of(SendRemindersJob::class, \craft\queue\BaseJob::class),
            'SendRemindersJob must extend BaseJob for Craft to queue it',
        );
    }

    /**
     * The queue serialises a job to the database between push and run, so every
     * value execute() needs has to be a public property. A private one is simply
     * gone by the time the job runs.
     *
     * @dataProvider sendBookingEmailPropertyProvider
     */
    public function testSendBookingEmailJobCarriesItsPayload(string $property): void
    {
        $this->assertTrue(
            (new \ReflectionClass(SendBookingEmailJob::class))->getProperty($property)->isPublic(),
            "SendBookingEmailJob::\${$property} must be public to survive queue serialisation",
        );
    }

    /** @return array<string, string[]> */
    public static function sendBookingEmailPropertyProvider(): array
    {
        return [
            'reservationId' => ['reservationId'],
            'emailType' => ['emailType'],
            'oldStatus' => ['oldStatus'],
            'recipientEmail' => ['recipientEmail'],
            'attempt' => ['attempt'],
            'previousQuantity' => ['previousQuantity'],
            'newQuantity' => ['newQuantity'],
        ];
    }

    public function testSendBookingEmailJobTtr(): void
    {
        $this->assertSame(60, (new SendBookingEmailJob())->getTtr());
    }

    /**
     * Reminders process a batch rather than one message, so the job needs a much
     * longer reservation than a single send.
     */
    public function testSendRemindersJobTtrAllowsForABatch(): void
    {
        $this->assertSame(300, (new SendRemindersJob())->getTtr());
    }

    public function testBothJobsDeclareRetryBehaviour(): void
    {
        foreach ([SendBookingEmailJob::class, SendRemindersJob::class] as $job) {
            $this->assertTrue(
                method_exists($job, 'canRetry'),
                "{$job} should decide explicitly whether a failure is worth retrying",
            );
        }
    }
}
