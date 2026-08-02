<?php

namespace anvildev\slots\helpers;

/**
 * Signs/verifies the opaque token that authorizes payment endpoints, so a
 * reservation's payment cannot be driven by guessing/enumerating ids. The token
 * is an HMAC (keyed with Craft's security key) over the reservation UID + payment
 * id. Pure functions — the key is injected so this is unit-testable. See PRD §7.3.
 */
final class PaymentTokenHelper
{
    /** Build a signed token: `sig|reservationUid|paymentId`. */
    public static function sign(string $reservationUid, int $paymentId, string $key): string
    {
        $payload = $reservationUid . '|' . $paymentId;
        return hash_hmac('sha256', $payload, $key) . '|' . $payload;
    }

    /**
     * Verify a token and return its parts, or null if the signature is invalid
     * or the token is malformed.
     *
     * @return array{reservationUid: string, paymentId: int}|null
     */
    public static function verify(string $token, string $key): ?array
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$sig, $uid, $paymentId] = $parts;
        if ($uid === '' || !ctype_digit($paymentId)) {
            return null;
        }
        $expected = hash_hmac('sha256', $uid . '|' . $paymentId, $key);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return ['reservationUid' => $uid, 'paymentId' => (int) $paymentId];
    }
}
