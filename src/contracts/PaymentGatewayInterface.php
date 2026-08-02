<?php

namespace anvildev\slots\contracts;

use anvildev\slots\payments\PaymentContext;
use anvildev\slots\payments\PaymentResult;
use anvildev\slots\payments\PaymentSession;
use anvildev\slots\payments\RefundResult;
use anvildev\slots\payments\WebhookEvent;
use anvildev\slots\records\PaymentRecord;
use craft\web\Request;

/**
 * A pluggable payment gateway for direct payment mode.
 *
 * Adapters register via {@see \anvildev\slots\services\PaymentGatewayService::EVENT_REGISTER_PAYMENT_GATEWAYS}
 * and are resolved by {@see getHandle()}. Stripe ships built-in; third parties
 * (and future first-party adapters) implement this same contract. The interface
 * is deliberately shaped to fit both embedded-element gateways (Stripe Payment
 * Element → `clientSecret`) and redirect-style gateways (→ `redirectUrl`). All
 * monetary amounts are in **minor units** (integer). See PRD §7.2.
 */
interface PaymentGatewayInterface
{
    /** Stable machine handle, e.g. 'stripe'. */
    public function getHandle(): string;

    /** Human-readable name for the CP, e.g. 'Stripe'. */
    public function getDisplayName(): string;

    /** Create a payment at the gateway and return what the front-end needs. */
    public function createPayment(ReservationInterface $reservation, PaymentContext $context): PaymentSession;

    /** Retrieve authoritative payment state from the gateway by its external id. */
    public function confirmPayment(string $externalId): PaymentResult;

    /** Refund (full or partial) a captured payment; $amount is in minor units. */
    public function refund(PaymentRecord $payment, int $amount): RefundResult;

    /**
     * Verify an inbound webhook request's signature and normalize it. Returns
     * null when the request cannot be verified (dropped + logged by the caller).
     */
    public function verifyWebhook(Request $request): ?WebhookEvent;

    /** Front-end config (publishable key, mount options) for a reservation's checkout. */
    public function getFrontendConfig(ReservationInterface $reservation): array;

    /** Whether this gateway supports partial refunds. */
    public function supportsPartialRefunds(): bool;
}
