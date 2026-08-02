<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;
use ReflectionMethod;

/**
 * Reports revenue sourcing (#40).
 *
 * The aggregation SQL is DB-bound (skipped in the unit environment), so these
 * assert the *wiring* by source inspection: in direct mode revenue comes from
 * the payments table (net of refunds, in minor units → major), while
 * commerce/none mode keeps the catalog-price path. The minor→major conversion
 * itself is covered by {@see PaymentServiceTest}.
 */
class ReportsServiceTest extends TestCase
{
    private static function methodSource(string $method): string
    {
        $rm = new ReflectionMethod('anvildev\slots\services\ReportsService', $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testRevenueSumBranchesToPaymentsInDirectMode(): void
    {
        $src = self::methodSource('aggregateRevenueSum');
        // Direct mode delegates to the payments-sourced aggregate...
        $this->assertStringContainsString('PAYMENT_MODE_DIRECT', $src);
        $this->assertStringContainsString('aggregateDirectPaymentsSum(', $src);
        // ...and the catalog-price sum remains for the other modes.
        $this->assertStringContainsString('s.price', $src);
    }

    public function testDirectPaymentsSumIsNetOfRefundsAndConverted(): void
    {
        $src = self::methodSource('aggregateDirectPaymentsSum');
        // Net captured: amount minus refundedAmount. Bracket-quoted because
        // Postgres folds an unquoted camelCase identifier to lowercase, which is
        // how the revenue report 500'd there.
        $this->assertStringContainsString('p.[[amount]] - COALESCE(p.[[refundedAmount]], 0)', $src);
        // Only actually-captured rows count (not failed/pending).
        $this->assertStringContainsString('STATUS_PAID', $src);
        $this->assertStringContainsString('STATUS_PARTIALLY_REFUNDED', $src);
        $this->assertStringContainsString('STATUS_REFUNDED', $src);
        // Confirmed reservations only, and stored minor units → major currency once.
        $this->assertStringContainsString("'r.status' => 'confirmed'", $src);
        $this->assertStringContainsString('fromMinorUnits(', $src);
        // Staff scoping preserved.
        $this->assertStringContainsString('getStaffEmployeeIds()', $src);
    }
}
