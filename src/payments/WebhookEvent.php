<?php

namespace anvildev\slots\payments;

/**
 * Immutable, gateway-agnostic view of a *verified* webhook event. A gateway's
 * {@see \anvildev\slots\contracts\PaymentGatewayInterface::verifyWebhook()}
 * returns null for events that fail signature verification. See PRD §7.7.
 */
final class WebhookEvent
{
    /**
     * @param string $type Normalized event type (e.g. 'payment.succeeded').
     * @param string $eventId Gateway event id — used for idempotent handling.
     * @param string|null $externalId Related payment id, when applicable.
     * @param string|null $status Resolved payment status, when applicable.
     * @param array<string, mixed> $raw Raw verified event payload.
     * @param int|null $refundedAmount Absolute refunded total (minor units) for
     *                                 refund events, else null. Used to reconcile
     *                                 refunds issued outside Slots.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $eventId,
        public readonly ?string $externalId = null,
        public readonly ?string $status = null,
        public readonly array $raw = [],
        public readonly ?int $refundedAmount = null,
    ) {
    }
}
