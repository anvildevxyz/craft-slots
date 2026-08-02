<?php

namespace anvildev\slots\helpers;

/**
 * Shared helpers for redacting PII (email, phone) in log messages.
 */
class PiiRedactor
{
    public static function redactEmail(?string $email): string
    {
        if ($email === null || $email === '') {
            return '***';
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        return substr($parts[0], 0, 2) . '***@' . $parts[1];
    }

    public static function redactPhone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '***';
        }

        $digits = preg_replace('/\D/', '', $phone);
        $len = strlen($digits);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . substr($digits, -4);
    }
}
