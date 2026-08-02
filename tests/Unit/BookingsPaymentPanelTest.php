<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;
use ReflectionMethod;

/**
 * CP booking payment panel + refund action (#38).
 *
 * The panel assembly and refund action are DB/permission/session-bound (skipped
 * in the unit environment), so these assert the wiring by source inspection: the
 * refund action is gated by `slots-manageRefunds` and delegates to the refund
 * service, and the panel only appears for direct-mode payments with a
 * policy-derived refund ceiling.
 */
class BookingsPaymentPanelTest extends TestCase
{
    private const CONTROLLER = 'anvildev\slots\controllers\cp\BookingsController';

    private static function methodSource(string $method): string
    {
        $rm = new ReflectionMethod(self::CONTROLLER, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testRefundActionRequiresManageRefundsPermission(): void
    {
        $src = self::methodSource('actionRefund');
        $this->assertStringContainsString("requirePermission('slots-manageRefunds')", $src);
        $this->assertStringContainsString('requirePostRequest()', $src);
    }

    public function testRefundActionDelegatesToPaymentServiceAndTranslatesGuardErrors(): void
    {
        $src = self::methodSource('actionRefund');
        $this->assertStringContainsString('getPayments()->refund(', $src);
        // Guard violations throw a translation-key message, rendered via Craft::t.
        $this->assertStringContainsString('catch (\RuntimeException $e)', $src);
        $this->assertStringContainsString("Craft::t('slots', \$e->getMessage())", $src);
    }

    public function testPanelIsDirectModeOnlyAndPolicyCapped(): void
    {
        $src = self::methodSource('getPaymentPanel');
        // Only direct mode, only when a payment record exists.
        $this->assertStringContainsString('isDirectPayment()', $src);
        $this->assertStringContainsString('reservationId', $src);
        // Refund ceiling is policy-derived and net of prior refunds.
        $this->assertStringContainsString('calculateRefundPercentage(', $src);
        $this->assertStringContainsString('canRefund', $src);
        // Amounts rendered from minor units.
        $this->assertStringContainsString('fromMinorUnits(', $src);
    }

    public function testDashboardUrlIsStripeTestAware(): void
    {
        $src = self::methodSource('gatewayDashboardUrl');
        $this->assertStringContainsString("dashboard.stripe.com", $src);
        $this->assertStringContainsString("sk_test_", $src);
    }
}
