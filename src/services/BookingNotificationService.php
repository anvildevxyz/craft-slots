<?php

namespace anvildev\slots\services;

use anvildev\slots\queue\jobs\SendBookingEmailJob;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;

/**
 * Queues booking-related notifications asynchronously: confirmation/cancellation emails,
 * owner notifications, reminders and payment-related mail.
 */
class BookingNotificationService extends Component
{
    /**
     * @param string $emailType 'confirmation', 'status_change', 'cancellation', 'owner_notification'
     * @param string|null $oldStatus For status change emails
     */
    public function queueBookingEmail(
        int $reservationId,
        string $emailType,
        ?string $oldStatus = null,
        int $priority = 1024,
    ): void {
        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => $emailType,
            'oldStatus' => $oldStatus,
        ]));
        Craft::info("Queued {$emailType} email for reservation #{$reservationId}", __METHOD__);
    }

    /** Only queues if owner notification is enabled and an email is configured. */
    public function queueOwnerNotification(int $reservationId, int $priority = 1024): void
    {
        $settings = Slots::getInstance()->getSettings();

        if (!$settings->ownerNotificationEnabled) {
            Craft::info("Owner notification disabled - skipping for reservation #{$reservationId}", __METHOD__);
            return;
        }

        if (empty($settings->getEffectiveEmail())) {
            Craft::warning("No owner email configured - skipping notification for reservation #{$reservationId}", __METHOD__);
            return;
        }

        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => 'owner_notification',
        ]));
        Craft::info("Queued owner notification email for reservation #{$reservationId}", __METHOD__);
    }

    public function queueCancellationNotification(int $reservationId, int $priority = 1024): void
    {
        if (!Slots::getInstance()->getSettings()->sendCancellationEmail) {
            Craft::info("Cancellation email disabled - skipping for reservation #{$reservationId}", __METHOD__);
            return;
        }

        $this->queueBookingEmail($reservationId, 'cancellation', null, $priority);
    }

    public function queueRescheduledNotification(
        int $reservationId,
        string $previousDate,
        string $previousStartTime,
        int $priority = 1024,
    ): void {
        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => 'rescheduled',
            'previousDate' => $previousDate,
            'previousStartTime' => $previousStartTime,
        ]));
        Craft::info(
            "Queued rescheduled email for reservation #{$reservationId} ({$previousDate} {$previousStartTime} -> new slot)",
            __METHOD__,
        );
    }

    public function queueQuantityChangedEmail(
        int $reservationId,
        int $previousQuantity,
        int $newQuantity,
        float $refundAmount = 0.0,
        int $priority = 1024,
    ): void {
        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => 'quantity_changed',
            'previousQuantity' => $previousQuantity,
            'newQuantity' => $newQuantity,
            'refundAmount' => $refundAmount,
        ]));
        Craft::info(
            "Queued quantity_changed email for reservation #{$reservationId} ({$previousQuantity} → {$newQuantity})",
            __METHOD__,
        );
    }
}
