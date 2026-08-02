<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;
use ReflectionMethod;

/**
 * Payment ops: Doctor config checks (#50) + reconcile console command (#51).
 *
 * Both are console/DB-bound (skipped in the unit environment), so the logic is
 * asserted by source inspection: the Doctor validates key shapes + the webhook
 * secret and matches test/live keys to the environment; the reconcile command
 * re-queries non-finalized records and finalizes via the idempotent path.
 */
class PaymentOpsTest extends TestCase
{
    private static function methodSource(string $class, string $method): string
    {
        $rm = new ReflectionMethod($class, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testDoctorPaymentChecksAreDirectModeOnly(): void
    {
        $src = self::methodSource('anvildev\slots\console\controllers\DoctorController', 'checkPayments');
        $this->assertStringContainsString('PAYMENT_MODE_DIRECT', $src);
    }

    public function testDoctorValidatesKeyShapesAndWebhookSecret(): void
    {
        $src = self::methodSource('anvildev\slots\console\controllers\DoctorController', 'checkPayments');
        // Key-prefix validation, incl. the secret-as-publishable footgun.
        $this->assertStringContainsString("str_starts_with(\$secret, 'sk_')", $src);
        $this->assertStringContainsString("str_starts_with(\$publishable, 'sk_')", $src);
        $this->assertStringContainsString("str_starts_with(\$publishable, 'pk_')", $src);
        $this->assertStringContainsString("str_starts_with(\$webhookSecret, 'whsec_')", $src);
        // Test/live keys matched to devMode.
        $this->assertStringContainsString("str_starts_with(\$secret, 'sk_live_')", $src);
        $this->assertStringContainsString("str_starts_with(\$secret, 'sk_test_')", $src);
    }

    public function testDoctorCallsPaymentCheck(): void
    {
        $src = self::methodSource('anvildev\slots\console\controllers\DoctorController', 'actionIndex');
        $this->assertStringContainsString('checkPayments($settings)', $src);
    }

    public function testReconcileIsDirectModeOnly(): void
    {
        $src = self::methodSource('anvildev\slots\console\controllers\PaymentsController', 'actionReconcile');
        $this->assertStringContainsString('PAYMENT_MODE_DIRECT', $src);
    }

    public function testReconcileTargetsNonFinalizedAndUsesIdempotentPath(): void
    {
        $src = self::methodSource('anvildev\slots\console\controllers\PaymentsController', 'actionReconcile');
        // Only non-finalized records are re-queried.
        $this->assertStringContainsString("['not in', 'status'", $src);
        $this->assertStringContainsString('STATUS_PAID', $src);
        // Finalization routes through the same idempotent path as the webhook.
        $this->assertStringContainsString('handleVerifiedPayment(', $src);
        // Dry-run writes nothing.
        $this->assertStringContainsString('$this->dryRun', $src);
    }

    public function testReconcileExposesDryRunAndSinceOptions(): void
    {
        $src = self::methodSource('anvildev\slots\console\controllers\PaymentsController', 'options');
        $this->assertStringContainsString('dryRun', $src);
        $this->assertStringContainsString('since', $src);
    }
}
