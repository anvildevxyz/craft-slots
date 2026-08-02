<?php

namespace anvildev\slots\services;

use anvildev\slots\contracts\PaymentGatewayInterface;
use anvildev\slots\events\RegisterPaymentGatewaysEvent;
use craft\base\Component;

/**
 * Registry of payment gateway adapters for direct (Commerce-free) payment mode.
 *
 * Adapters are collected once via {@see EVENT_REGISTER_PAYMENT_GATEWAYS} and
 * resolved by handle. Available in both editions — direct payments are Lite's
 * anchor feature. See PRD §7.2.
 */
class PaymentGatewayService extends Component
{
    /**
     * @event RegisterPaymentGatewaysEvent Register direct-payment gateway adapters.
     */
    public const EVENT_REGISTER_PAYMENT_GATEWAYS = 'registerPaymentGateways';

    /** @var array<string, PaymentGatewayInterface>|null */
    private ?array $_gateways = null;

    /**
     * All registered gateways keyed by handle.
     *
     * @return array<string, PaymentGatewayInterface>
     */
    public function getGateways(): array
    {
        if ($this->_gateways === null) {
            $event = new RegisterPaymentGatewaysEvent();
            $this->trigger(self::EVENT_REGISTER_PAYMENT_GATEWAYS, $event);
            $registry = [];
            foreach ($event->gateways as $gateway) {
                $registry[$gateway->getHandle()] = $gateway;
            }
            $this->_gateways = $registry;
        }
        return $this->_gateways;
    }

    /** Resolve a gateway by handle, or null if none is registered under it. */
    public function getGateway(string $handle): ?PaymentGatewayInterface
    {
        return $this->getGateways()[$handle] ?? null;
    }

    /** Whether any gateway is registered under the given handle. */
    public function hasGateway(string $handle): bool
    {
        return isset($this->getGateways()[$handle]);
    }
}
