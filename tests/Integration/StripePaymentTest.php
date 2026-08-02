<?php

namespace anvildev\slots\tests\Integration;

use anvildev\slots\gateways\StripeGateway;
use anvildev\slots\tests\Support\TestCase;
use Stripe\StripeClient;

/**
 * Live integration tests for the Stripe adapter against **Stripe test mode**.
 *
 * These are env-gated: they run only when `STRIPE_TEST_SECRET_KEY` is set (a
 * Stripe test-mode secret key). Without it — the default in CI and local dev —
 * every test is skipped, so the suite stays green while still documenting the
 * pre-merge verification path. The rest of the payment stack is covered by the
 * pure unit tests (gateway registry, token, status resolution, idempotency).
 *
 * To run: `STRIPE_TEST_SECRET_KEY=sk_test_… vendor/bin/phpunit --filter StripePayment`.
 */
class StripePaymentTest extends TestCase
{
    private ?StripeClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $key = getenv('STRIPE_TEST_SECRET_KEY') ?: '';
        if ($key === '' || !str_starts_with($key, 'sk_test_')) {
            $this->markTestSkipped('Set STRIPE_TEST_SECRET_KEY (a sk_test_… key) to run live Stripe integration tests.');
        }
        $this->client = new StripeClient($key);
    }

    public function testCreatesAndRetrievesAPaymentIntentInTestMode(): void
    {
        $intent = $this->client->paymentIntents->create([
            'amount' => 4000,
            'currency' => 'usd',
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        ]);

        $this->assertNotEmpty($intent->id);
        $this->assertNotEmpty($intent->client_secret);
        $this->assertSame(StripeGateway::mapStatus($intent->status), \anvildev\slots\records\PaymentRecord::STATUS_PENDING);

        // Round-trips through the adapter's confirm path.
        $gateway = new StripeGateway($this->client);
        $result = $gateway->confirmPayment($intent->id);
        $this->assertSame($intent->id, $result->externalId);
        $this->assertSame(4000, $result->amount);
    }

    public function testRefundOnASucceededTestPayment(): void
    {
        // Create + confirm with Stripe's always-succeeds test PaymentMethod.
        $intent = $this->client->paymentIntents->create([
            'amount' => 2000,
            'currency' => 'usd',
            'payment_method' => 'pm_card_visa',
            'confirm' => true,
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        ]);
        $this->assertSame('succeeded', $intent->status);

        // Verify the live refund path with the exact params the adapter sends
        // (the adapter's refund() is a thin wrapper over this call; instantiating
        // a real PaymentRecord would require the DB component this context lacks).
        $refund = $this->client->refunds->create([
            'payment_intent' => $intent->id,
            'amount' => 2000,
        ], ['idempotency_key' => 'slots_re_test_' . $intent->id]);

        $this->assertContains($refund->status, ['succeeded', 'pending']);
        $this->assertSame(2000, $refund->amount);
    }
}
