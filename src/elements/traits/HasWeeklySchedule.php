<?php

namespace anvildev\slots\elements\traits;

trait HasWeeklySchedule
{
    /** @return array|null Schedule data keyed by day number (1=Monday, 7=Sunday) */
    abstract public function getScheduleData(): ?array;

    /** Mon-Fri 9-17 with lunch break, Sat-Sun off */
    public function getDefaultWorkingHours(): array
    {
        $weekday = ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00', 'capacity' => null];
        $weekend = ['enabled' => false, 'start' => null, 'end' => null, 'breakStart' => null, 'breakEnd' => null, 'capacity' => null];
        return [1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekday, 6 => $weekend, 7 => $weekend];
    }

    /**
     * Normalize a workingHours value from JSON string, array, or null into an array.
     * Suitable for both init() deserialization and afterSave() record assignment.
     */
    protected function normalizeWorkingHours(mixed $value): array
    {
        return match (true) {
            is_array($value) => $value,
            is_string($value) => json_decode($value, true) ?? $this->getDefaultWorkingHours(),
            default => $this->getDefaultWorkingHours(),
        };
    }

    public function getScheduleForDay(int $dayOfWeek): ?array
    {
        $schedule = $this->getScheduleData();
        if (empty($schedule) || !is_array($schedule)) {
            return null;
        }

        $hours = $schedule[$dayOfWeek] ?? $schedule[(string)$dayOfWeek] ?? null;
        if (!$hours || !($hours['enabled'] ?? false)) {
            return null;
        }

        return [
            'start' => $hours['start'] ?? null,
            'end' => $hours['end'] ?? null,
            'breakStart' => $hours['breakStart'] ?? null,
            'breakEnd' => $hours['breakEnd'] ?? null,
        ];
    }

    /** @return array Array of time windows, empty if not available */
    public function getTimeSlotsForDay(int $dayOfWeek): array
    {
        $hours = $this->getScheduleForDay($dayOfWeek);
        if (!$hours || empty($hours['start']) || empty($hours['end'])) {
            return [];
        }

        $start = $hours['start'];
        $end = $hours['end'];
        $breakStart = $hours['breakStart'] ?? null;
        $breakEnd = $hours['breakEnd'] ?? null;

        if (empty($breakStart) || empty($breakEnd)) {
            return [['start' => $start, 'end' => $end]];
        }

        $slots = [];
        if ($start < $breakStart) {
            $slots[] = ['start' => $start, 'end' => $breakStart];
        }
        if ($breakEnd < $end) {
            $slots[] = ['start' => $breakEnd, 'end' => $end];
        }

        return $slots;
    }

    public function isScheduledOnDay(int $dayOfWeek): bool
    {
        return $this->getScheduleForDay($dayOfWeek) !== null;
    }

    /** @return int[] Array of day numbers (1-7) */
    public function getScheduledDays(): array
    {
        return array_filter(range(1, 7), fn(int $i) => $this->isScheduledOnDay($i));
    }

    /** Alias for {@see getScheduleForDay()} */
    public function getWorkingHoursForDay(int $dayOfWeek): ?array
    {
        return $this->getScheduleForDay($dayOfWeek);
    }

    /** Alias for {@see getTimeSlotsForDay()} */
    public function getWorkingSlotsForDay(int $dayOfWeek): array
    {
        return $this->getTimeSlotsForDay($dayOfWeek);
    }

    /** Alias for {@see isScheduledOnDay()} */
    public function worksOnDay(int $dayOfWeek): bool
    {
        return $this->isScheduledOnDay($dayOfWeek);
    }

    /** Alias for {@see getScheduledDays()} */
    public function getWorkingDays(): array
    {
        return $this->getScheduledDays();
    }

    public function getEarliestStartTime(): ?string
    {
        $earliest = null;
        for ($i = 1; $i <= 7; $i++) {
            $hours = $this->getScheduleForDay($i);
            if ($hours && !empty($hours['start']) && ($earliest === null || $hours['start'] < $earliest)) {
                $earliest = $hours['start'];
            }
        }
        return $earliest;
    }

    public function getLatestEndTime(): ?string
    {
        $latest = null;
        for ($i = 1; $i <= 7; $i++) {
            $hours = $this->getScheduleForDay($i);
            if ($hours && !empty($hours['end']) && ($latest === null || $hours['end'] > $latest)) {
                $latest = $hours['end'];
            }
        }
        return $latest;
    }
}
