<?php

namespace anvildev\slots\payments;

/**
 * Immutable result of creating a payment at the gateway. Carries what the
 * front-end needs to complete payment: an embedded-element `clientSecret`
 * (Stripe Payment Element) OR a `redirectUrl` (redirect-style gateways such as
 * PayPal), plus any mount config. `externalId` is the gateway's own id
 * (PaymentIntent / order / transaction). See PRD §7.2–§7.3.
 */
final class PaymentSession
{
    /**
     * @param string $externalId Gateway payment id (PaymentIntent/transaction).
     * @param string $status Initial status (e.g. PaymentRecord::STATUS_PENDING).
     * @param string|null $clientSecret For embedded elements (Stripe), else null.
     * @param string|null $redirectUrl For redirect-style gateways, else null.
     * @param array<string, mixed> $frontendConfig Publishable key + mount options.
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly ?string $clientSecret = null,
        public readonly ?string $redirectUrl = null,
        public readonly array $frontendConfig = [],
    ) {
    }
}
