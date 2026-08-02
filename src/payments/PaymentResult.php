<?php

namespace anvildev\slots\payments;

/**
 * Immutable result of confirming/retrieving a payment from the gateway
 * (webhook or server-side retrieval — the source of truth). Amount is in minor
 * units. See PRD §7.3.
 */
final class PaymentResult
{
    /**
     * @param string $status Resolved status (e.g. PaymentRecord::STATUS_PAID).
     * @param string $externalId Gateway payment id.
     * @param int $amount Confirmed amount in minor units.
     * @param bool $paid Convenience flag: whether the payment is fully captured.
     * @param array<string, mixed> $raw Raw gateway response snapshot.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $externalId,
        public readonly int $amount,
        public readonly bool $paid,
        public readonly array $raw = [],
    ) {
    }
}
