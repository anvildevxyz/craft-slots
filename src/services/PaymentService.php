<?php

namespace anvildev\slots\services;

use anvildev\slots\contracts\PaymentGatewayInterface;
use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\events\PaymentRefundedEvent;
use anvildev\slots\helpers\PaymentTokenHelper;
use anvildev\slots\models\Settings;
use anvildev\slots\payments\PaymentContext;
use anvildev\slots\payments\RefundResult;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use craft\helpers\App;
use RuntimeException;

/**
 * Orchestrates native (direct) payments: create a gateway payment for a
 * reservation, persist the record, and confirm status. Amounts are always
 * computed server-side (from the reservation total) in minor units — the client
 * never supplies an amount. See PRD §7.3.
 */
class PaymentService extends Component
{
    /**
     * Raised after a direct payment is successfully refunded (full or partial).
     * @event PaymentRefundedEvent
     */
    public const EVENT_PAYMENT_REFUNDED = 'paymentRefunded';

    /**
     * Create a payment for a reservation through the given gateway. Persists a
     * pending {@see PaymentRecord} and returns it plus the gateway session and a
     * signed token for the follow-up confirm call.
     *
     * @return array{record: PaymentRecord, session: \anvildev\slots\payments\PaymentSession, token: string}
     */
    public function createForReservation(ReservationInterface $reservation, PaymentGatewayInterface $gateway): array
    {
        // Shared resolver so record/report/refund currencies always agree.
        $currency = Slots::getInstance()->getReports()->getCurrency();
        $amount = self::toMinorUnits($reservation->getTotalPrice(), $currency);

        $context = new PaymentContext(
            $amount,
            $currency,
            'Booking #' . $reservation->getId(),
            null,
            ['reservationId' => (string) $reservation->getId()],
        );

        $session = $gateway->createPayment($reservation, $context);

        // Reuse the row for this intent (same externalId on retry/refresh) so a
        // repeated create doesn't duplicate it; never clobber an advanced status.
        $record = PaymentRecord::findOne(['externalId' => $session->externalId, 'gateway' => $gateway->getHandle()])
            ?? new PaymentRecord();
        $record->reservationId = $reservation->getId();
        $record->gateway = $gateway->getHandle();
        $record->externalId = $session->externalId;
        if (empty($record->status)) {
            $record->status = PaymentRecord::STATUS_PENDING;
        }
        $record->amount = $amount;
        $record->currency = $currency;
        if ($record->refundedAmount === null) {
            $record->refundedAmount = 0;
        }
        $record->save(false);

        $token = PaymentTokenHelper::sign((string) $reservation->getUid(), (int) $record->id, self::securityKey());

        return ['record' => $record, 'session' => $session, 'token' => $token];
    }

    /**
     * Handle a verified inbound webhook. Returns whether the event was applied.
     * Idempotent: a payment already marked paid is a no-op, so replays never
     * re-confirm a reservation. The gateway's signature verification (the
     * caller drops null events) is the trust boundary. See PRD §7.7.
     */
    public function handleVerifiedPayment(PaymentRecord $record): bool
    {
        if (self::isFinalized((string) $record->status)) {
            return false; // already handled — idempotent
        }
        $record->status = PaymentRecord::STATUS_PAID;
        $record->save(false);
        $this->confirmReservation((int) $record->reservationId);
        return true;
    }

    /** Whether a payment status is already terminal (paid/refunded). */
    public static function isFinalized(string $status): bool
    {
        return in_array($status, [
            PaymentRecord::STATUS_PAID,
            PaymentRecord::STATUS_REFUNDED,
            PaymentRecord::STATUS_PARTIALLY_REFUNDED,
        ], true);
    }

    /**
     * Refund a direct payment (per-reservation mutex, policy-capped, idempotent).
     * `$amount` is minor units; null = the policy-allowed maximum. Throws
     * RuntimeException (message = a translation key) on a guard violation.
     */
    public function refund(ReservationInterface $reservation, ?int $amount = null): RefundResult
    {
        $mutex = Craft::$app->getMutex();
        $mutexKey = 'slots:refund:' . $reservation->getId();
        if (!$mutex->acquire($mutexKey, 10)) {
            throw new RuntimeException('payment.refundBusy');
        }

        try {
            /** @var PaymentRecord|null $record */
            $record = PaymentRecord::find()
                ->where(['reservationId' => $reservation->getId()])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->one();

            if (!$record || !in_array($record->status, [
                PaymentRecord::STATUS_PAID,
                PaymentRecord::STATUS_PARTIALLY_REFUNDED,
            ], true)) {
                throw new RuntimeException('payment.refundNoPayment');
            }

            $captured = (int) $record->amount;
            $alreadyRefunded = (int) ($record->refundedAmount ?? 0);
            $pct = Slots::getInstance()->getRefundPolicy()->calculateRefundPercentage($reservation);
            $requested = self::resolveRefundAmount($captured, $alreadyRefunded, $pct, $amount);

            $gateway = Slots::getInstance()->getPaymentGateways()->getGateway((string) $record->gateway);
            if (!$gateway) {
                throw new RuntimeException('payment.gatewayUnavailable');
            }

            $result = $gateway->refund($record, $requested);
            if (!$result->success) {
                return $result; // record untouched; caller surfaces $result->error
            }

            $totalRefunded = $alreadyRefunded + $result->refundedAmount;
            $record->refundedAmount = $totalRefunded;
            $record->status = $totalRefunded >= $captured
                ? PaymentRecord::STATUS_REFUNDED
                : PaymentRecord::STATUS_PARTIALLY_REFUNDED;
            $record->save(false);

            if ($this->hasEventHandlers(self::EVENT_PAYMENT_REFUNDED)) {
                $event = new PaymentRefundedEvent();
                $event->reservationId = (int) $record->reservationId;
                $event->record = $record;
                $event->amount = $result->refundedAmount;
                $event->totalRefunded = $totalRefunded;
                $this->trigger(self::EVENT_PAYMENT_REFUNDED, $event);
            }

            return $result;
        } finally {
            $mutex->release($mutexKey);
        }
    }

    /**
     * Reconcile a refund seen via webhook (e.g. issued in the gateway dashboard):
     * sets the absolute refunded total + status, monotonically.
     */
    public function applyRefundSync(PaymentRecord $record, int $refundedAmount): void
    {
        $captured = (int) $record->amount;
        $refundedAmount = max(0, min($refundedAmount, $captured));

        if ($refundedAmount <= (int) ($record->refundedAmount ?? 0)) {
            return;
        }

        $status = $refundedAmount >= $captured
            ? PaymentRecord::STATUS_REFUNDED
            : PaymentRecord::STATUS_PARTIALLY_REFUNDED;

        $record->refundedAmount = $refundedAmount;
        $record->status = $status;
        $record->save(false);

        if ($this->hasEventHandlers(self::EVENT_PAYMENT_REFUNDED)) {
            $event = new PaymentRefundedEvent();
            $event->reservationId = (int) $record->reservationId;
            $event->record = $record;
            $event->amount = $refundedAmount;
            $event->totalRefunded = $refundedAmount;
            $this->trigger(self::EVENT_PAYMENT_REFUNDED, $event);
        }
    }

    /** Confirm a pending reservation (direct mode) and fire the usual notifications. */
    private function confirmReservation(int $reservationId): void
    {
        $affected = Craft::$app->db->createCommand()
            ->update(
                '{{%slots_reservations}}',
                ['status' => \anvildev\slots\records\ReservationRecord::STATUS_CONFIRMED],
                ['id' => $reservationId, 'status' => \anvildev\slots\records\ReservationRecord::STATUS_PENDING],
            )
            ->execute();
        if ($affected < 1) {
            return;
        }

        try {
            $ns = Slots::getInstance()->bookingNotification;
            $ns->queueBookingEmail($reservationId, 'confirmation', null, 512);
            $ns->queueOwnerNotification($reservationId, 512);
            $reservation = \anvildev\slots\factories\ReservationFactory::findById($reservationId);
            if ($reservation) {
            }
        } catch (\Throwable $e) {
            Craft::error("Failed to queue notifications for direct-paid reservation #{$reservationId}: " . $e->getMessage(), __METHOD__);
        }
    }

    // Reservation-level computed payment statuses (superset of PaymentRecord's).
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_FREE = 'free';

    /**
     * The reservation's payment status, read from the {@see PaymentRecord}
     * table so CP columns and exports share one query surface.
     */
    public function getStatusForReservation(ReservationInterface $reservation): string
    {
        $mode = Slots::getInstance()->getSettings()->getPaymentMode();
        $total = $reservation->getTotalPrice();
        $recordStatus = null;

        if ($mode === Settings::PAYMENT_MODE_DIRECT) {
            $record = PaymentRecord::find()
                ->where(['reservationId' => $reservation->getId()])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->one();
            $recordStatus = $record?->status;
        }

        return self::resolveStatus($mode, $total, $recordStatus);
    }

    /**
     * Pure status resolution (no I/O), given the gathered inputs. A zero total is
     * always `free`; direct mode reflects the latest payment record (or `unpaid`).
     */
    public static function resolveStatus(string $mode, float $total, ?string $recordStatus): string
    {
        if ($total <= 0.0) {
            return self::STATUS_FREE;
        }
        if ($mode === Settings::PAYMENT_MODE_DIRECT) {
            return $recordStatus ?? self::STATUS_UNPAID;
        }
        return self::STATUS_FREE; // mode 'none'
    }

    /**
     * Pure (no-I/O) refund-amount resolution: validate a requested refund against
     * the policy ceiling + remaining refundable. `$requested` null = policy max.
     * @throws RuntimeException with a translation-key message on any violation.
     */
    public static function resolveRefundAmount(int $captured, int $alreadyRefunded, int $policyPercent, ?int $requested): int
    {
        $remaining = $captured - $alreadyRefunded;
        if ($remaining <= 0) {
            throw new RuntimeException('payment.refundAlreadyFull');
        }
        $policyCeiling = max(0, (int) floor($captured * $policyPercent / 100) - $alreadyRefunded);

        $amount = $requested ?? min($remaining, $policyCeiling);
        if ($amount <= 0) {
            throw new RuntimeException('payment.refundPolicyZero');
        }
        if ($amount > $remaining) {
            throw new RuntimeException('payment.refundExceedsRemaining');
        }
        if ($amount > $policyCeiling) {
            throw new RuntimeException('payment.refundExceedsPolicy');
        }
        return $amount;
    }

    /**
     * Currencies Stripe treats as zero-decimal — their "minor unit" IS the major
     * unit, so amounts must NOT be multiplied by 100 (else a 100x overcharge).
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Convert a decimal major-unit amount to integer minor units for the currency. */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    /**
     * Convert integer minor units back to a decimal major-unit amount for the
     * currency — the inverse of {@see toMinorUnits}, used to render stored payment
     * amounts (which are always minor units) in reports and exports.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency): float
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (float) $minorUnits;
        }
        return $minorUnits / 100;
    }

    private static function securityKey(): string
    {
        return (string) App::parseEnv(Craft::$app->getConfig()->getGeneral()->securityKey);
    }
}
