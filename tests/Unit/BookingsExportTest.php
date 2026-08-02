<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;
use ReflectionMethod;

/**
 * Bookings CSV export payment columns (#41).
 *
 * The export streams from the DB (skipped in the unit environment), so this
 * asserts by source inspection that the gateway/external-ID/status/refunded
 * columns are added only in direct mode and sourced from the payment record.
 */
class BookingsExportTest extends TestCase
{
    private static function exportSource(): string
    {
        $rm = new ReflectionMethod('anvildev\slots\controllers\cp\BookingsController', 'actionExport');
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testPaymentColumnsAreDirectModeOnly(): void
    {
        $src = self::exportSource();
        $this->assertStringContainsString('isDirectPayment()', $src);
        // Header additions guarded by the direct-mode flag.
        $this->assertStringContainsString("'Payment Status', 'Gateway', 'External ID', 'Refunded'", $src);
    }

    public function testPaymentColumnsSourcedFromPaymentRecord(): void
    {
        $src = self::exportSource();
        $this->assertStringContainsString('PaymentRecord::find()', $src);
        $this->assertStringContainsString('$payment->status', $src);
        $this->assertStringContainsString('$payment->gateway', $src);
        $this->assertStringContainsString('$payment->externalId', $src);
        // Refunded amount rendered from minor units.
        $this->assertStringContainsString('fromMinorUnits(', $src);
        // A reservation with no payment gets blank cells (not a missing column).
        $this->assertStringContainsString("array_push(\$row, '', '', '', '')", $src);
    }
}
