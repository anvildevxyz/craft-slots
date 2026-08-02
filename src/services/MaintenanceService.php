<?php

namespace anvildev\slots\services;

use anvildev\slots\records\PaymentRecord;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use yii\db\Query;

/**
 * Runs periodic cleanup tasks for the booking system.
 *
 * Registered with Craft's garbage collection to automatically purge
 * expired soft locks,

 * and expired calendar invites.
 */
class MaintenanceService extends Component
{
    public function runAll(): array
    {
        return [
            'expiredSoftLocks' => $this->cleanupExpiredSoftLocks(),
            'stalePendingPayments' => $this->cleanupStalePendingPayments(),
        ];
    }

    public function cleanupExpiredSoftLocks(): int
    {
        try {
            return Slots::getInstance()->getSoftLock()->cleanupExpiredLocks();
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup soft locks: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    /**
     * Garbage-collect abandoned direct-payment bookings — `pending` past the TTL,
     * releasing capacity. Never cancels a paid/refunded booking. Direct mode only.
     */
    public function cleanupStalePendingPayments(?int $minutes = null): int
    {
        $settings = Slots::getInstance()->getSettings();
        if (!$settings->isDirectPayment()) {
            return 0;
        }

        $minutes = max(1, (int) ($minutes ?? $settings->pendingPaymentTtlMinutes));

        try {
            $cutoff = (new \DateTime("-{$minutes} minutes"))->format('Y-m-d H:i:s');

            // Reservations that HAVE been paid must be excluded even if their
            // confirm lost a race — never cancel a paid booking.
            $paidReservationIds = (new Query())
                ->select('reservationId')
                ->from('{{%slots_payments}}')
                ->where(['status' => [
                    PaymentRecord::STATUS_PAID,
                    PaymentRecord::STATUS_PARTIALLY_REFUNDED,
                    PaymentRecord::STATUS_REFUNDED,
                ]])
                ->column();

            $query = (new Query())
                ->select('id')
                ->from('{{%slots_reservations}}')
                ->where(['status' => ReservationRecord::STATUS_PENDING])
                ->andWhere(['<=', 'dateCreated', $cutoff]);
            if ($paidReservationIds) {
                $query->andWhere(['not in', 'id', $paidReservationIds]);
            }

            $cancelled = 0;
            foreach ($query->column() as $id) {
                $this->cancelStaleReservation((int) $id, "Direct payment not completed within {$minutes} minutes");
                $cancelled++;
            }

            return $cancelled;
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup stale pending payments: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    private function cancelStaleReservation(int $reservationId, string $reason): void
    {
        $existing = (new Query())
            ->select(['notes'])
            ->from('{{%slots_reservations}}')
            ->where(['id' => $reservationId])
            ->scalar();

        $reason = mb_substr(strip_tags($reason), 0, 500);

        $updatedNotes = $existing
            ? $existing . "\n---\n" . $reason
            : $reason;

        $affected = Craft::$app->db->createCommand()
            ->update(
                '{{%slots_reservations}}',
                ['status' => ReservationRecord::STATUS_CANCELLED, 'activeSlotKey' => null, 'notes' => $updatedNotes],
                ['id' => $reservationId, 'status' => ReservationRecord::STATUS_PENDING],
            )
            ->execute();

        if ($affected > 0) {
            Slots::getInstance()->getAudit()->logCancellation($reservationId, 'system (maintenance)', $reason, 'service');
        }
    }

    public function getStats(): array
    {
        try {
            return ['expiredSoftLocks' => Slots::getInstance()->getSoftLock()->countExpiredLocks()];
        } catch (\Throwable) {
            return ['expiredSoftLocks' => 'N/A'];
        }
    }
}
