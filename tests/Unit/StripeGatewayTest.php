<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\gateways\StripeGateway;
use anvildev\slots\records\PaymentRecord;
use anvildev\slots\tests\Support\TestCase;

/**
 * Pure unit tests for the Stripe adapter's identity + status mapping. The
 * network-bound create/confirm/refund/webhook paths are covered by the
 * env-gated integration test against Stripe test mode (see StripeIntegrationTest).
 */
class StripeGatewayTest extends TestCase
{
    public function testIdentity(): void
    {
        $gateway = new StripeGateway();
        $this->assertSame('stripe', $gateway->getHandle());
        $this->assertSame('Stripe', $gateway->getDisplayName());
        $this->assertTrue($gateway->supportsPartialRefunds());
    }

    /**
     * @dataProvider statusProvider
     */
    public function testMapStatus(string $stripeStatus, string $expected): void
    {
        $this->assertSame($expected, StripeGateway::mapStatus($stripeStatus));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function statusProvider(): array
    {
        return [
            'succeeded → paid' => ['succeeded', PaymentRecord::STATUS_PAID],
            'requires_capture → authorized' => ['requires_capture', PaymentRecord::STATUS_AUTHORIZED],
            'canceled → failed' => ['canceled', PaymentRecord::STATUS_FAILED],
            'processing → pending' => ['processing', PaymentRecord::STATUS_PENDING],
            'requires_payment_method → pending' => ['requires_payment_method', PaymentRecord::STATUS_PENDING],
        ];
    }
}
