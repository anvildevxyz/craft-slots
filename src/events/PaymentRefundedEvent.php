<?php

namespace anvildev\slots\events;

use anvildev\slots\records\PaymentRecord;
use yii\base\Event;

/**
 * Raised after a direct payment is successfully refunded, in
 * full or in part. Amounts are in the currency's minor units, matching * {@see PaymentRecord}.
 */
class PaymentRefundedEvent extends Event
{
    public int $reservationId;
    public PaymentRecord $record;

    /** The amount refunded by this operation, in minor units. */
    public int $amount;

    /** The record's cumulative refunded total after this operation, in minor units. */
    public int $totalRefunded;
}
