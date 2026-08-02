<?php

namespace anvildev\slots\records;

use craft\db\ActiveRecord;

/**
 * A native payment against a reservation. One row per payment
 * attempt; the gateway's webhook/retrieval is the source of truth for `status`.
 * Monetary amounts are stored in **minor units** (integer cents) to avoid float
 * drift. See docs/prd/ (removed) §7.5.
 *
 * @property int $id
 * @property int $reservationId
 * @property string $gateway
 * @property string|null $externalId
 * @property string $status
 * @property int $amount           minor units (e.g. cents)
 * @property string $currency      ISO 4217 code
 * @property int $refundedAmount   minor units
 * @property string|null $payload  last gateway response snapshot (JSON)
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class PaymentRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partiallyRefunded';

    public static function tableName(): string
    {
        return '{{%slots_payments}}';
    }
}
