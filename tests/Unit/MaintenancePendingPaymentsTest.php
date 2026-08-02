<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\models\Settings;
use anvildev\slots\services\MaintenanceService;
use anvildev\slots\tests\Support\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pending-payment garbage collection (#48).
 *
 * The GC query is DB-bound (skipped in the unit environment), so the sweep logic
 * is asserted by source inspection: direct-mode-only, TTL-cutoff, and paid
 * bookings excluded. The setting default is read via reflection (no Craft app).
 */
class MaintenancePendingPaymentsTest extends TestCase
{
    private static function methodSource(string $method): string
    {
        $rm = new ReflectionMethod(MaintenanceService::class, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testTtlSettingDefaultsToThirtyMinutes(): void
    {
        $default = (new ReflectionProperty(Settings::class, 'pendingPaymentTtlMinutes'))->getDefaultValue();
        $this->assertSame(30, $default);
    }

    public function testGcIsDirectModeOnly(): void
    {
        $src = self::methodSource('cleanupStalePendingPayments');
        $this->assertStringContainsString('isDirectPayment()', $src);
        $this->assertStringContainsString('pendingPaymentTtlMinutes', $src);
    }

    public function testGcTargetsStalePendingAndExcludesPaid(): void
    {
        $src = self::methodSource('cleanupStalePendingPayments');
        // Only pending reservations older than the cutoff.
        $this->assertStringContainsString('STATUS_PENDING', $src);
        $this->assertStringContainsString("['<=', 'dateCreated', \$cutoff]", $src);
        // Paid/refunded bookings are excluded — never cancel a paid booking.
        $this->assertStringContainsString('STATUS_PAID', $src);
        $this->assertStringContainsString("['not in', 'id', \$paidReservationIds]", $src);
        $this->assertStringContainsString('cancelStaleReservation(', $src);
    }

    public function testGcRegisteredInRunAll(): void
    {
        $src = self::methodSource('runAll');
        $this->assertStringContainsString('cleanupStalePendingPayments()', $src);
    }
}
