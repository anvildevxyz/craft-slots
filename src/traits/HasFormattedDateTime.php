<?php

namespace anvildev\slots\traits;

use anvildev\slots\helpers\DateHelper;
use Craft;

/**
 * Shared formatted date/time display for Reservation element, model, and record.
 *
 * Expects the using class to have: $bookingDate, $startTime, $endTime, $userTimezone properties.
 */
trait HasFormattedDateTime
{
    public function getFormattedDateTime(): string
    {
        if (empty($this->bookingDate)) {
            return '';
        }

        $locale = Craft::$app->language ?: 'en';
        $timezone = $this->userTimezone ?: Craft::$app->getTimeZone();


        // Single-day booking: original behavior
        if (empty($this->startTime) || empty($this->endTime)) {
            return '';
        }

        $date = \DateTime::createFromFormat('Y-m-d', $this->bookingDate);
        $startTime = DateHelper::parseTime($this->startTime);
        $endTime = DateHelper::parseTime($this->endTime);

        if (!$date || !$startTime || !$endTime) {
            return $this->bookingDate . ' ' . $this->startTime . ' - ' . $this->endTime;
        }

        $dateFormatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            $timezone
        );

        return $dateFormatter->format($date) . ' ' .
            Craft::t('slots', 'dateTime.fromTime') . ' ' .
            DateHelper::formatTimeLocale($startTime, $locale, $timezone) . ' ' .
            Craft::t('slots', 'dateTime.toTime') . ' ' .
            DateHelper::formatTimeLocale($endTime, $locale, $timezone);
    }
}
