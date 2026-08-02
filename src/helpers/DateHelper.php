<?php

namespace anvildev\slots\helpers;

class DateHelper
{
    public static function parseTime(string $time, ?string $timezone = null): ?\DateTime
    {
        if (empty($time)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('H:i:s', $time)
            ?: \DateTime::createFromFormat('H:i', $time);

        return $dt ? self::applyTimezone($dt, $timezone) : null;
    }

    public static function parseDate(string $date, ?string $timezone = null): ?\DateTime
    {
        if (empty($date)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $date);

        return $dt ? self::applyTimezone($dt, $timezone) : null;
    }

    public static function parseDateTime(string $date, string $time, ?string $timezone = null): ?\DateTime
    {
        if (empty($date) || empty($time)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', "$date $time")
            ?: \DateTime::createFromFormat('Y-m-d H:i', "$date $time");

        return $dt ? self::applyTimezone($dt, $timezone) : null;
    }

    public static function today(): string
    {
        return (new \DateTime())->format('Y-m-d');
    }

    public static function relativeDate(string $modify): string
    {
        return (new \DateTime())->modify($modify)->format('Y-m-d');
    }
    /**
     * Format a time value using locale-aware formatting (e.g. "2:00 PM" for en-US, "14:00" for de).
     */
    public static function formatTimeLocale(\DateTimeInterface $time, ?string $locale = null, ?string $timezone = null): string
    {
        $locale = $locale ?: (\Craft::$app->language ?: 'en');
        $timezone = $timezone ?: \Craft::$app->getTimeZone();

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT,
            $timezone
        );

        return $formatter->format($time);
    }

    /**
     * Format a date string (Y-m-d) using locale-aware formatting.
     */
    public static function formatDateLocale(string $dateStr, ?string $locale = null, ?string $timezone = null, int $dateType = \IntlDateFormatter::LONG): string
    {
        $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$date) {
            return $dateStr;
        }

        $locale = $locale ?: (\Craft::$app->language ?: 'en');
        $timezone = $timezone ?: \Craft::$app->getTimeZone();

        $formatter = new \IntlDateFormatter(
            $locale,
            $dateType,
            \IntlDateFormatter::NONE,
            $timezone
        );

        return $formatter->format($date);
    }

    private static function applyTimezone(\DateTime $dt, ?string $timezone): \DateTime
    {
        if ($timezone) {
            try {
                $dt->setTimezone(new \DateTimeZone($timezone));
            } catch (\Exception) {
            }
        }

        return $dt;
    }
}
