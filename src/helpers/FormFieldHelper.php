<?php

namespace anvildev\slots\helpers;

use craft\helpers\DateTimeHelper;

class FormFieldHelper
{
    /**
     * Extract time from Craft's forms.time() input (string, array, DateTime, or null).
     */
    public static function extractTimeValue(mixed $value, ?string $default = null): ?string
    {
        if (empty($value)) {
            return $default;
        }

        if (is_array($value)) {
            return empty($value['time']) ? $default : self::normalizeTime($value['time']);
        }

        if ($value instanceof \DateTime) {
            return $value->format('H:i');
        }

        return is_string($value) ? self::normalizeTime($value) : $default;
    }

    /**
     * Normalize a time string (12-hour "9:00 AM" or 24-hour "09:00") to HH:mm format.
     */
    private static function normalizeTime(string $time): string
    {
        $parsed = \DateTime::createFromFormat('g:i A', $time)
            ?: \DateTime::createFromFormat('H:i', $time)
            ?: \DateTime::createFromFormat('H:i:s', $time);

        return $parsed ? $parsed->format('H:i') : $time;
    }

    /**
     * Extract date from Craft's forms.date() input, returns Y-m-d or null.
     */
    public static function extractDateValue(mixed $value): ?string
    {
        if (empty($value) || $value === 'null') {
            return null;
        }

        $dateTime = DateTimeHelper::toDateTime($value);

        return $dateTime !== false ? $dateTime->format('Y-m-d') : null;
    }

    public static function extractCapacityValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    /**
     * Parse 7-day working hours form structure into a normalized array keyed by day (1-7).
     */
    public static function formatWorkingHoursFromRequest(array $workingHours, bool $includeCapacity = false): array
    {
        $formattedHours = [];
        for ($day = 1; $day <= 7; $day++) {
            $d = $workingHours[$day] ?? [];

            $formattedHours[$day] = [
                'enabled' => !empty($d['enabled']),
                'start' => self::extractTimeValue($d['start'] ?? null, '09:00'),
                'end' => self::extractTimeValue($d['end'] ?? null, '17:00'),
                'breakStart' => self::extractTimeValue($d['breakStart'] ?? null),
                'breakEnd' => self::extractTimeValue($d['breakEnd'] ?? null),
            ];

            if ($includeCapacity) {
                $formattedHours[$day]['capacity'] = self::extractCapacityValue($d['capacity'] ?? null);
            }
        }

        return $formattedHours;
    }
}
