<?php

namespace anvildev\slots\services;

use craft\base\Component;
use DateTime;
use DateTimeZone;

/**
 * Handles timezone conversions between location-local times, UTC, and user-facing display.
 * Includes DST transition safety checks.
 */
class TimezoneService extends Component
{
    /** @var array<string, true>|null Cached map of valid timezone identifiers */
    private static ?array $validTimezones = null;

    private static function getValidTimezones(): array
    {
        return self::$validTimezones ??= array_flip(DateTimeZone::listIdentifiers());
    }

    public function convertToUtc(string $date, string $time, string $timezone): DateTime
    {
        if (!isset(self::getValidTimezones()[$timezone])) {
            throw new \InvalidArgumentException("Invalid timezone: {$timezone}");
        }

        $dt = new DateTime("{$date} {$time}", new DateTimeZone($timezone));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt;
    }

    public function shiftSlots(array $slots, string $date, string $fromTz, string $toTz): array
    {
        if ($fromTz === $toTz) {
            return $slots;
        }

        $validTimezones = self::getValidTimezones();
        if (!isset($validTimezones[$fromTz])
            || !isset($validTimezones[$toTz])) {
            throw new \InvalidArgumentException("Invalid timezone in shiftSlots: fromTz={$fromTz}, toTz={$toTz}");
        }

        $converted = [];
        foreach ($slots as $slot) {
            $start = $this->convertToUtc($date, $slot['time'], $fromTz);
            $start->setTimezone(new DateTimeZone($toTz));
            $end = $this->convertToUtc($date, $slot['endTime'], $fromTz);
            $end->setTimezone(new DateTimeZone($toTz));

            $convertedDate = $start->format('Y-m-d');

            // Skip slots where the converted start date no longer matches the requested date
            if ($convertedDate !== $date) {
                continue;
            }

            $slotData = array_merge($slot, [
                'time' => $start->format('H:i'),
                'endTime' => $end->format('H:i'),
                'date' => $convertedDate,
            ]);

            // Flag slots where the end time crosses into the next day
            if ($end->format('Y-m-d') !== $convertedDate) {
                $slotData['crossesDateBoundary'] = true;
                $slotData['endDate'] = $end->format('Y-m-d');
                $slotData['endTime'] = '24:00';
            }

            $converted[] = $slotData;
        }

        return $converted;
    }
}
