<?php

namespace anvildev\slots\events;

use yii\base\Event;

/**
 * Fired so plugins can register payment gateway adapters for direct payment
 * mode. Handlers push instances implementing
 * {@see \anvildev\slots\contracts\PaymentGatewayInterface} onto {@see $gateways}.
 */
class RegisterPaymentGatewaysEvent extends Event
{
    /** @var \anvildev\slots\contracts\PaymentGatewayInterface[] */
    public array $gateways = [];
}
