<?php

namespace anvildev\slots\queue\jobs;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\helpers\PiiRedactor;
use anvildev\slots\models\Settings;
use anvildev\slots\Slots;
use Craft;
use craft\mail\Message;
use craft\queue\BaseJob;

class SendBookingEmailJob extends BaseJob
{
    public int $reservationId;
    public string $emailType;
    public ?string $oldStatus = null;
    public ?string $recipientEmail = null;
    public int $attempt = 1;
    public ?int $previousQuantity = null;
    public ?int $newQuantity = null;
    public ?string $previousDate = null;
    public ?string $previousStartTime = null;
    public float $refundAmount = 0.0;

    public function execute($queue): void
    {
        $reservation = ReservationFactory::find()->siteId('*')->id($this->reservationId)->status(null)->one();
        if (!$reservation) {
            Craft::error("Cannot send email: Reservation #{$this->reservationId} not found", __METHOD__);
            return;
        }

        // Atomic idempotency guard for confirmation emails (prevents duplicate sends via CAS)
        if ($this->emailType === 'confirmation') {
            $affected = Craft::$app->db->createCommand()->update(
                '{{%slots_reservations}}',
                ['notificationSent' => true],
                ['id' => $this->reservationId, 'notificationSent' => false],
            )->execute();

            if ($affected === 0) {
                Craft::info("Booking email already sent for reservation #{$this->reservationId} — skipping", __METHOD__);
                return;
            }
        }

        $settings = Settings::loadSettings();
        $recipientEmail = ($this->emailType === 'owner_notification')
            ? $settings->getEffectiveEmail()
            : $reservation->userEmail;

        $this->setProgress($queue, 0.1, "Preparing email for " . PiiRedactor::redactEmail($recipientEmail));

        try {
            [$subject, $body] = $this->prepareEmail($reservation, $settings);
            $this->setProgress($queue, 0.3, 'Sending email');

            $message = (new Message())
                ->setTo($recipientEmail)
                ->setFrom([$settings->getEffectiveEmail() => $settings->getEffectiveName()])
                ->setSubject($subject)
                ->setHtmlBody($body);

            if ($this->emailType === 'confirmation' || str_starts_with($this->emailType, 'reminder_')) {
                $service = $reservation->getService();
                $message->attachContent(\anvildev\slots\helpers\IcsHelper::generate($reservation), [
                    'fileName' => 'Booking - ' . ($service?->title ?? 'Appointment') . '.ics',
                    'contentType' => 'text/calendar; charset=utf-8; method=REQUEST',
                ]);
            }

            if (!Craft::$app->mailer->send($message)) {
                throw new \Exception('Mailer returned false');
            }

            $this->setProgress($queue, 0.9, 'Email sent successfully');

            Craft::info("Email sent successfully: {$this->emailType} to " . PiiRedactor::redactEmail($recipientEmail) . " (Attempt {$this->attempt})", __METHOD__);
            $this->setProgress($queue, 1, 'Complete');
        } catch (\Throwable $e) {
            Craft::error("Failed to send {$this->emailType} email to " . PiiRedactor::redactEmail($recipientEmail) . " (Attempt {$this->attempt}): " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    private function prepareEmail(ReservationInterface $reservation, Settings $settings): array
    {
        $language = $this->getEmailLanguage($reservation);
        $originalLanguage = Craft::$app->language;
        $emailRender = Slots::getInstance()->emailRender;

        try {
            Craft::$app->language = $language;

            $subject = match ($this->emailType) {
                'confirmation' => $settings->getEffectiveBookingConfirmationSubject(),
                'status_change' => Craft::t('slots', 'emails.subject.statusChange'),
                'cancellation' => $settings->getEffectiveCancellationEmailSubject(),
                'owner_notification' => (function() use ($settings, $language) {
                    Craft::$app->language = $settings->getOwnerNotificationLanguageCode();
                    $s = $settings->getEffectiveOwnerNotificationSubject();
                    Craft::$app->language = $language;
                    return $s;
                }
                )(),
                'reminder_24h', 'reminder_1h' => $settings->getEffectiveReminderEmailSubject(),
                'quantity_changed' => Craft::t('slots', 'emails.subject.quantityChanged'),
                'rescheduled' => Craft::t('slots', 'emails.subject.rescheduled'),
                default => throw new \Exception("Unknown email type: {$this->emailType}"),
            };
        } finally {
            Craft::$app->language = $originalLanguage;
        }

        $body = match ($this->emailType) {
            'confirmation' => $emailRender->renderConfirmationEmail($reservation, $settings),
            'status_change' => $emailRender->renderStatusChangeEmail($reservation, $this->oldStatus ?? 'unknown', $settings),
            'cancellation' => $emailRender->renderCancellationEmail($reservation, $settings),
            'owner_notification' => $emailRender->renderOwnerNotificationEmail($reservation, $settings),
            'reminder_24h' => $emailRender->renderReminderEmail($reservation, $settings, 24),
            'reminder_1h' => $emailRender->renderReminderEmail($reservation, $settings, 1),
            'rescheduled' => $emailRender->renderRescheduledEmail(
                $reservation,
                $settings,
                $this->previousDate ?? '',
                $this->previousStartTime ?? '',
            ),
            'quantity_changed' => $emailRender->renderQuantityChangedEmail(
                $reservation,
                $settings,
                $this->previousQuantity ?? 0,
                $this->newQuantity ?? $reservation->quantity,
                $this->refundAmount,
            ),
            default => throw new \Exception("Unknown email type: {$this->emailType}"),
        };

        return [$subject, $body];
    }

    private function getEmailLanguage(ReservationInterface $reservation): string
    {
        $siteId = $reservation->getSiteId();
        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId) : null;
        return $site?->language ?? Craft::$app->getSites()->getPrimarySite()->language;
    }

    protected function defaultDescription(): string
    {
        return Craft::t('slots', 'queue.sendBookingEmail.description', ['type' => $this->emailType, 'id' => $this->reservationId]);
    }

    public function getTtr(): int
    {
        return 60;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }
}
