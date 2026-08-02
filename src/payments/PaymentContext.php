<?php

namespace anvildev\slots\payments;

/**
 * Immutable input to {@see \anvildev\slots\contracts\PaymentGatewayInterface::createPayment()}.
 * The amount is server-computed (service price and policy) in **minor units**
 * (integer) — the client never supplies it. See PRD §7.
 */
final class PaymentContext
{
    /**
     * @param int $amount Amount to charge, in minor units (e.g. cents).
     * @param string $currency ISO 4217 code.
     * @param string|null $description Human-readable line description.
     * @param string|null $returnUrl Where redirect-style gateways send the customer back.
     * @param array<string, mixed> $metadata Extra key/values to attach at the gateway.
     */
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $description = null,
        public readonly ?string $returnUrl = null,
        public readonly array $metadata = [],
    ) {
    }
}
