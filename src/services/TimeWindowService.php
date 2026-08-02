<?php

namespace anvildev\slots\services;

use craft\base\Component;

/**
 * Pure time window arithmetic: merging, subtracting, and
 * converting between H:i strings and minutes-since-midnight.
 */
class TimeWindowService extends Component
{
    /**
     * Merge overlapping or adjacent time windows into contiguous blocks.
     *
     * Callers must pre-group windows by employee/location before calling this
     * method. Merging windows across different employees or locations will
     * produce incorrect availability results.
     *
     * @param array $windows Array of ['start' => 'H:i', 'end' => 'H:i', ...]
     * @return array Merged windows sorted by start time
     */
    public function mergeWindows(array $windows): array
    {
        if (empty($windows)) {
            return [];
        }

        usort($windows, fn($a, $b) => strcmp($a['start'], $b['start']));

        $merged = [];
        $current = $windows[0];

        for ($i = 1, $len = count($windows); $i < $len; $i++) {
            if ($this->timeToMinutes($current['end']) >= $this->timeToMinutes($windows[$i]['start'])) {
                $current['end'] = $this->minutesToTime(max(
                    $this->timeToMinutes($current['end']),
                    $this->timeToMinutes($windows[$i]['end']),
                ));
            } else {
                $merged[] = $current;
                $current = $windows[$i];
            }
        }

        $merged[] = $current;
        return $merged;
    }

    public function subtractWindow(array $windows, string $startTime, string $endTime): array
    {
        $subStart = $this->timeToMinutes($startTime);
        $subEnd = $this->timeToMinutes($endTime);
        $adjusted = [];

        foreach ($windows as $window) {
            $winStart = $this->timeToMinutes($window['start']);
            $winEnd = $this->timeToMinutes($window['end']);

            if ($winEnd <= $subStart || $winStart >= $subEnd) {
                $adjusted[] = $window;
            } elseif ($winStart >= $subStart && $winEnd <= $subEnd) {
                // Fully covered — skip
            } elseif ($winStart < $subStart && $winEnd > $subEnd) {
                $adjusted[] = array_merge($window, ['end' => $startTime]);
                $adjusted[] = array_merge($window, ['start' => $endTime]);
            } elseif ($winStart >= $subStart) {
                $adjusted[] = array_merge($window, ['start' => $endTime]);
            } else {
                $adjusted[] = array_merge($window, ['end' => $startTime]);
            }
        }

        return $adjusted;
    }

    public function addMinutes(string $time, int $minutes): string
    {
        return $this->minutesToTime($this->timeToMinutes($time) + $minutes);
    }

    public function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    }

    public function minutesToTime(int $minutes): string
    {
        if ($minutes === 1440) {
            return '24:00';
        }

        if ($minutes < 0 || $minutes > 1440) {
            throw new \InvalidArgumentException("minutesToTime received out-of-range value: {$minutes} (expected 0–1440)");
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
