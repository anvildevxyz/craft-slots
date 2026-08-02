<?php

namespace anvildev\slots\helpers;

/**
 * CSV export utilities.
 */
class CsvHelper
{
    /**
     * Prevent CSV injection by prefixing dangerous first-characters with a single quote.
     *
     * @see https://owasp.org/www-community/attacks/CSV_Injection
     */
    public static function sanitizeValue(string $value): string
    {
        return $value !== '' && strpbrk($value[0], '=+-@' . "\t\r") !== false
            ? "'" . $value
            : $value;
    }
}
