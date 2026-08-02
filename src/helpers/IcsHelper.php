<?php

namespace anvildev\slots\helpers;

use anvildev\slots\contracts\ReservationInterface;
use Craft;
use DateTime;
use DateTimeZone;

/**
 * Generates RFC 5545-compliant iCalendar (.ics) files for reservations.
 */
class IcsHelper
{
    public static function generate(ReservationInterface $reservation): string
    {
        $tz = new DateTimeZone($reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone());
        $utc = new DateTimeZone('UTC');
        $startTime = (new DateTime($reservation->getBookingDate() . ' ' . $reservation->getStartTime(), $tz))->setTimezone($utc);
        $endTime = (new DateTime($reservation->getBookingDate() . ' ' . $reservation->getEndTime(), $tz))->setTimezone($utc);
        $created = new DateTime('now', $utc);

        $uid = $reservation->getUid() ?: bin2hex(random_bytes(16));
        $summary = $reservation->getService()?->title ?? Craft::t('slots', 'element.booking');
        $employee = $reservation->getEmployee();
        $location = $reservation->getLocation();

        // Build description
        $descParts = [Craft::t('slots', 'ics.bookingId', ['id' => $reservation->getId()])];
        $descMap = [
            'ics.customer' => ['name' => $reservation->getUserName()],
            'ics.email' => ['email' => $reservation->getUserEmail()],
            'ics.phone' => ['phone' => $reservation->getUserPhone()],
        ];
        foreach ($descMap as $key => $params) {
            $val = reset($params);
            if ($val) {
                $descParts[] = Craft::t('slots', $key, $params);
            }
        }

        if ($employee) {
            $employeeName = $employee->title ?: $employee->getUser()?->getName();
            if ($employeeName) {
                $descParts[] = Craft::t('slots', 'ics.employee', ['name' => $employeeName]);
            }
        }

        $locationAddress = $location?->getAddress();
        if ($locationAddress) {
            $descParts[] = Craft::t('slots', 'ics.location', ['address' => $locationAddress]);
        }

        if ($reservation->getNotes()) {
            $descParts[] = Craft::t('slots', 'ics.notes', ['notes' => $reservation->getNotes()]);
        }

        $fmt = fn(DateTime $dt) => $dt->format('Ymd\THis\Z');

        $ics = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Slots Plugin//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'DTSTART:' . $fmt($startTime),
            'DTEND:' . $fmt($endTime),
            'DTSTAMP:' . $fmt($created),
            'UID:' . $uid,
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape(implode("\n", $descParts)),
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'TRANSP:OPAQUE',
            'CLASS:PRIVATE',
        ];

        // LOCATION
        if ($location) {
            $locStr = $locationAddress ?: ($location->title ?? '');
            if ($locStr) {
                $ics[] = 'LOCATION:' . self::escape($locStr);
            }
        }

        // ORGANIZER
        if ($employee) {
            $user = $employee->getUser();
            if ($user?->email) {
                $ics[] = 'ORGANIZER;CN=' . self::escape($employee->title ?: $user->getName()) . ':mailto:' . self::escape($user->email);
            }
        }

        // ATTENDEE
        if ($reservation->getUserEmail()) {
            $ics[] = 'ATTENDEE;CN=' . self::escape($reservation->getUserName() ?: $reservation->getUserEmail()) . ';RSVP=TRUE:mailto:' . self::escape($reservation->getUserEmail());
        }

        if ($reservation->getUserPhone()) {
            $ics[] = 'CONTACT:' . self::escape($reservation->getUserPhone());
        }

        try {
            $bookingUrl = $reservation->getCancelUrl();
            if ($bookingUrl) {
                $ics[] = 'URL:' . $bookingUrl;
            }
        } catch (\Throwable) {
        }

        $ics[] = 'END:VEVENT';
        $ics[] = 'END:VCALENDAR';

        // RFC 5545: fold lines at 75 characters
        $folded = [];
        foreach ($ics as $line) {
            while (strlen($line) > 75) {
                $folded[] = mb_strcut($line, 0, 75, 'UTF-8');
                $line = ' ' . mb_strcut($line, 75, strlen($line), 'UTF-8');
            }
            $folded[] = $line;
        }

        return implode("\r\n", $folded);
    }

    private static function escape(string $text): string
    {
        return str_replace(
            ['\\', ',', ';', "\n", "\r"],
            ['\\\\', '\\,', '\\;', '\\n', ''],
            $text,
        );
    }
}
