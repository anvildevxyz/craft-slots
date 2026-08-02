<?php

namespace anvildev\slots\helpers;

use Craft;

/**
 * Strips internal details (SQL, file paths, table names) from error messages
 * to prevent information leakage in user-facing responses.
 */
class ErrorSanitizer
{
    public static function sanitize(string $message): string
    {
        if (preg_match('/SQLSTATE|\.php|{{%|`\w+`\.\`|INTO\s|FROM\s|SELECT\s/i', $message)) {
            Craft::error('Sanitized internal error: ' . $message, __METHOD__);
            return 'An internal error occurred.';
        }

        return $message;
    }
}
