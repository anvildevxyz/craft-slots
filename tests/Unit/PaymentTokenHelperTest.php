<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\helpers\PaymentTokenHelper;
use anvildev\slots\tests\Support\TestCase;

class PaymentTokenHelperTest extends TestCase
{
    private const KEY = 'test-security-key-0123456789';

    public function testSignVerifyRoundTrip(): void
    {
        $token = PaymentTokenHelper::sign('res-uid-abc', 42, self::KEY);
        $parts = PaymentTokenHelper::verify($token, self::KEY);
        $this->assertSame(['reservationUid' => 'res-uid-abc', 'paymentId' => 42], $parts);
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = PaymentTokenHelper::sign('res-uid-abc', 42, self::KEY);
        // Flip the payment id in the payload without re-signing.
        $tampered = preg_replace('/\|42$/', '|43', $token);
        $this->assertNull(PaymentTokenHelper::verify($tampered, self::KEY));
    }

    public function testWrongKeyRejected(): void
    {
        $token = PaymentTokenHelper::sign('res-uid-abc', 42, self::KEY);
        $this->assertNull(PaymentTokenHelper::verify($token, 'different-key'));
    }

    public function testMalformedTokenRejected(): void
    {
        $this->assertNull(PaymentTokenHelper::verify('not-a-token', self::KEY));
        $this->assertNull(PaymentTokenHelper::verify('sig|uid|notanumber', self::KEY));
        $this->assertNull(PaymentTokenHelper::verify('', self::KEY));
    }
}
