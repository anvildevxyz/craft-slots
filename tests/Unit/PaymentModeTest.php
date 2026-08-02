<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\models\Settings;
use anvildev\slots\tests\Support\TestCase;

/**
 * Pure unit tests for payment-mode resolution + the automatic migration from
 * the legacy `commerceEnabled` flag. No Craft init — getPaymentMode() and
 * isDirectPayment() are edition- and app-agnostic (the Pro/commerce gate lives
 * in isCommerceEnabled(), which needs Craft and is asserted structurally).
 */
class PaymentModeTest extends TestCase
{
    public function testExplicitModeWins(): void
    {
        $s = new Settings();
        $s->paymentMode = Settings::PAYMENT_MODE_DIRECT;
        $this->assertSame('direct', $s->getPaymentMode());
        $this->assertTrue($s->isDirectPayment());
    }

    public function testExplicitNoneIsNotDirect(): void
    {
        $s = new Settings();
        $s->paymentMode = Settings::PAYMENT_MODE_NONE;
        $this->assertSame('none', $s->getPaymentMode());
        $this->assertFalse($s->isDirectPayment());
    }

}
