<?php

namespace anvildev\slots\payments;

/**
 * Immutable result of a refund attempt. Amount is in minor units. See PRD §7.4.
 */
final class RefundResult
{
    /**
     * @param bool $success Whether the refund was accepted by the gateway.
     * @param int $refundedAmount Amount refunded this call, in minor units.
     * @param string|null $externalId Gateway refund id, when available.
     * @param string|null $error Error message when $success is false.
     * @param array<string, mixed> $raw Raw gateway response snapshot.
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $refundedAmount = 0,
        public readonly ?string $externalId = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {
    }
}
