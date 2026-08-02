<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Service;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;

/**
 * Generates bookable time slots from time windows, applying duration/interval
 * settings, employee assignment, deduplication, and quantity-based filtering.
 */
class SlotGeneratorService extends Component
{
    private ?TimeWindowService $timeWindowService = null;

    public function setTimeWindowService(TimeWindowService $service): void
    {
        $this->timeWindowService = $service;
    }

    private function getTimeWindowService(): TimeWindowService
    {
        return $this->timeWindowService ??= new TimeWindowService();
    }

    /**
     * @param array $windows Time windows [['start' => 'H:i', 'end' => 'H:i', ...], ...]
     * @param int $duration Slot duration in minutes
     * @param int|null $interval Interval between slot starts (null = use duration)
     * @param array $slotDefaults Default values to include in each slot
     */
    public function generateSlots(
        array $windows,
        int $duration,
        ?int $interval = null,
        array $slotDefaults = [],
    ): array {
        $slots = [];
        $slotInterval = $interval ?? $duration;

        if ($slotInterval <= 0 || $duration <= 0) {
            Craft::warning("SlotGeneratorService: Invalid duration ({$duration}) or interval ({$slotInterval})", __METHOD__);
            return [];
        }

        foreach ($windows as $window) {
            if (empty($window['start']) || empty($window['end'])) {
                continue;
            }

            $start = $this->getTimeWindowService()->timeToMinutes($window['start']);
            $end = $this->getTimeWindowService()->timeToMinutes($window['end']);

            Craft::debug("SlotGeneratorService: Window {$window['start']}-{$window['end']}, duration: {$duration}, interval: {$slotInterval}", __METHOD__);

            $slotCount = 0;
            for ($current = $start; $current + $duration <= $end; $current += $slotInterval) {
                $slots[] = array_merge($slotDefaults, [
                    'time' => $this->getTimeWindowService()->minutesToTime($current),
                    'endTime' => $this->getTimeWindowService()->minutesToTime($current + $duration),
                    'duration' => $duration,
                    'employeeId' => $window['employeeId'] ?? null,
                    'locationId' => $window['locationId'] ?? null,
                ]);
                $slotCount++;
            }

            Craft::debug("SlotGeneratorService: Generated {$slotCount} slot(s) for window", __METHOD__);
        }

        return $slots;
    }

    /**
     * Priority: Service timeSlotLength -> Global defaultTimeSlotLength -> duration
     */
    public function getSlotInterval(Service|int|null $serviceOrId, int $duration): int
    {
        if ($serviceOrId !== null) {
            $service = $serviceOrId instanceof Service ? $serviceOrId : Service::find()->siteId('*')->id($serviceOrId)->one();
            if ($service?->timeSlotLength > 0) {
                return $service->timeSlotLength;
            }
        }

        $globalInterval = Slots::getInstance()->getSettings()->defaultTimeSlotLength;
        return ($globalInterval !== null && $globalInterval > 0) ? $globalInterval : $duration;
    }

    public function addEmployeeInfo(array $slots, int $employeeId, string $employeeName, ?string $timezone): array
    {
        $tz = $timezone ?? Craft::$app->getTimezone();
        return array_map(fn($slot) => array_merge($slot, [
            'employeeId' => $employeeId,
            'employeeName' => $employeeName,
            'timezone' => $tz,
        ]), $slots);
    }

    /** Deduplicate slots by time (for "Any available" employee selection). */
    public function deduplicateByTime(array $slots): array
    {
        $seen = [];
        $unique = [];

        foreach ($slots as $slot) {
            $key = $slot['time'] . '-' . ($slot['endTime'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = count($unique);
                $employeeIds = [];
                if (!empty($slot['employeeId'])) {
                    $employeeIds[] = $slot['employeeId'];
                }
                $unique[] = array_merge($slot, [
                    'employeeId' => null,
                    'employeeName' => null,
                    'availableEmployeeIds' => $employeeIds,
                ]);
            } else {
                $idx = $seen[$key];
                if (!empty($slot['employeeId']) && !in_array($slot['employeeId'], $unique[$idx]['availableEmployeeIds'], true)) {
                    $unique[$idx]['availableEmployeeIds'][] = $slot['employeeId'];
                }
            }
        }

        return $unique;
    }

    public function sortByTime(array $slots): array
    {
        usort($slots, fn($a, $b) => strcmp($a['time'], $b['time']));
        return $slots;
    }

    /** Filter slots requiring $quantity employees available at same time. */
    public function filterByEmployeeQuantity(array $slots, int $quantity): array
    {
        $byTime = [];
        foreach ($slots as $slot) {
            $key = $slot['time'] . '-' . ($slot['endTime'] ?? '');
            $byTime[$key][] = $slot;
        }

        $filtered = array_values(array_filter($byTime, fn($group) => count($group) >= $quantity));
        return $filtered !== [] ? array_merge(...$filtered) : [];
    }
}
