<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Service;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;

/**
 * Resolves working hours and schedules for employees and services by looking up
 * Schedule element assignments with fallback to legacy inline working hours.
 */
class ScheduleResolverService extends Component
{
    public function getWorkingHours(
        int $dayOfWeek,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?int $serviceId = null,
        ?string $date = null,
    ): array {
        if ($employeeId === null && $locationId === null && $serviceId === null) {
            Craft::warning('getWorkingHours called with all-null filters (employeeId, locationId, serviceId); returning empty result', __METHOD__);
            return [];
        }

        $dayOfWeekNew = self::toIsoDayOfWeek($dayOfWeek);

        $query = Employee::find()->siteId('*');
        if ($employeeId !== null) {
            $query->id($employeeId);
        }
        if ($serviceId !== null) {
            $query->serviceId($serviceId);
        }
        if ($locationId !== null) {
            $query->andWhere([
                'or',
                ['slots_employees.locationId' => $locationId],
                ['slots_employees.locationId' => null],
            ]);
        }

        $employees = $query->all();
        $workingHoursData = [];

        // Batch load schedules
        $employeeIds = array_map(fn($e) => $e->id, $employees);
        $schedulesByEmployee = ($date !== null && !empty($employeeIds))
            ? Slots::getInstance()->getScheduleAssignment()->getActiveSchedulesForDateBatch($employeeIds, $date)
            : [];

        foreach ($employees as $employee) {
            if ($date !== null) {
                $activeSchedule = $schedulesByEmployee[$employee->id] ?? null;
                if ($activeSchedule !== null) {
                    $slots = $activeSchedule->getWorkingSlotsForDay($dayOfWeekNew);
                    if (!empty($slots)) {
                        Craft::debug("Employee {$employee->id} has " . count($slots) . " working slots for {$date} (day {$dayOfWeekNew}) via schedule {$activeSchedule->id}", __METHOD__);
                        foreach ($slots as $slot) {
                            $workingHoursData[] = (object)[
                                'startTime' => $slot['start'],
                                'endTime' => $slot['end'],
                                'employeeId' => $employee->id,
                                'locationId' => $employee->locationId,
                                'capacity' => 1,
                                'simultaneousSlots' => 1,
                            ];
                        }
                        continue;
                    }
                }
                Craft::debug("Employee {$employee->id} has no matching schedules for date {$date}, trying inline workingHours", __METHOD__);
            }

            // Fallback: legacy workingHours
            foreach ($employee->getWorkingSlotsForDay($dayOfWeekNew) as $slot) {
                $workingHoursData[] = (object)[
                    'startTime' => $slot['start'],
                    'endTime' => $slot['end'],
                    'employeeId' => $employee->id,
                    'locationId' => $employee->locationId,
                    'capacity' => 1,
                    'simultaneousSlots' => 1,
                ];
            }
        }

        return $workingHoursData;
    }

    public function isDateBlackedOut(string $date, ?int $employeeId = null, ?int $locationId = null): bool
    {
        return Slots::getInstance()->getBlackoutDate()->isDateBlackedOut($date, $employeeId, $locationId);
    }

    /**
     * Get set of employee IDs that are blacked out on a date (use as lookup: isset($result[$empId])).
     */
    public function getBlackedOutEmployeeIds(string $date, array $employeeIds, ?int $locationId = null): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $blackoutService = Slots::getInstance()->getBlackoutDate();
        $blackouts = $blackoutService->getBlackoutsForDate($date);

        if (empty($blackouts)) {
            return [];
        }

        // Global blackout (no employee or location scoping) applies to all employees
        $hasGlobalBlackout = false;
        foreach ($blackouts as $b) {
            if (empty($b['locationIds']) && empty($b['employeeIds'])) {
                $hasGlobalBlackout = true;
                break;
            }
        }
        if ($hasGlobalBlackout) {
            return array_flip($employeeIds);
        }

        $blackedOutIds = [];
        foreach ($employeeIds as $empId) {
            if ($blackoutService->matchesAnyBlackout($blackouts, $empId, $locationId, $date)) {
                $blackedOutIds[$empId] = true;
            }
        }

        return $blackedOutIds;
    }

    public function getServiceAvailability(Service $service, string $date, int $dayOfWeek): ?array
    {
        $dayNum = self::toIsoDayOfWeek($dayOfWeek);
        $activeSchedule = Slots::getInstance()->getScheduleAssignment()
            ->getActiveScheduleForServiceOnDate($service->id, $date);

        if ($activeSchedule === null) {
            return null;
        }

        $dayHours = $activeSchedule->getWorkingHoursForDay($dayNum);
        return $dayHours !== null ? [
            'enabled' => true,
            'start' => $dayHours['start'] ?? null,
            'end' => $dayHours['end'] ?? null,
            'breakStart' => $dayHours['breakStart'] ?? null,
            'breakEnd' => $dayHours['breakEnd'] ?? null,
        ] : null;
    }

    public function buildWindowsFromServiceAvailability(array $availability): array
    {
        if (!($availability['enabled'] ?? false)) {
            return [];
        }

        // Combined availability from multiple schedules
        if (isset($availability['_combinedWindows']) && is_array($availability['_combinedWindows'])) {
            return $availability['_combinedWindows'];
        }

        $breakStart = $availability['breakStart'] ?? null;
        $breakEnd = $availability['breakEnd'] ?? null;

        if (empty($breakStart) || empty($breakEnd)) {
            return [['start' => $availability['start'], 'end' => $availability['end']]];
        }

        $windows = [];
        if ($availability['start'] < $breakStart) {
            $windows[] = ['start' => $availability['start'], 'end' => $breakStart];
        }
        if ($breakEnd < $availability['end']) {
            $windows[] = ['start' => $breakEnd, 'end' => $availability['end']];
        }
        return $windows;
    }

    /**
     * Day-based capacity lives on Schedule.workingHours[day].capacity, not on Service.
     * Resolution order mirrors hasScheduleForDay(). Null = no constraint.
     *
     * @param int $dayOfWeek ISO-8601 day (1=Mon, 7=Sun)
     */
    public function getCapacityForDay(int $serviceId, ?int $employeeId, string $date, int $dayOfWeek): ?int
    {
        $scheduleAssignment = Slots::getInstance()->getScheduleAssignment();

        $service = Service::find()->siteId('*')->id($serviceId)->one();
        if ($service?->hasAvailabilitySchedule()) {
            $serviceSchedule = $scheduleAssignment->getActiveScheduleForServiceOnDate($serviceId, $date);
            if ($serviceSchedule !== null && $serviceSchedule->getWorkingHoursForDay($dayOfWeek) !== null) {
                return $serviceSchedule->getCapacityForDay($dayOfWeek) ?? 1;
            }
        }

        if ($employeeId) {
            $empSchedule = $scheduleAssignment->getActiveScheduleForDate($employeeId, $date);
            if ($empSchedule !== null && $empSchedule->getWorkingHoursForDay($dayOfWeek) !== null) {
                return $empSchedule->getCapacityForDay($dayOfWeek) ?? 1;
            }
            return null;
        }

        $employees = Employee::find()->siteId('*')->serviceId($serviceId)->all();
        $employeeIds = array_map(fn($e) => $e->id, $employees);
        if (empty($employeeIds)) {
            return null;
        }

        $schedulesByEmployee = $scheduleAssignment->getActiveSchedulesForDateBatch($employeeIds, $date);
        $total = 0;
        $anyFound = false;
        foreach ($schedulesByEmployee as $sched) {
            // Employees without an active schedule on this date come back as null.
            if ($sched === null || $sched->getWorkingHoursForDay($dayOfWeek) === null) {
                continue;
            }
            $anyFound = true;
            $total += $sched->getCapacityForDay($dayOfWeek) ?? 1;
        }

        return $anyFound ? $total : null;
    }

    /**
     * Check if a service/employee has any schedule configured for a specific day.
     * Used to determine whether any schedule covers the slot.
     *
     * @param int $dayOfWeek ISO-8601 day (1=Mon, 7=Sun)
     */
    public function hasScheduleForDay(int $serviceId, ?int $employeeId, string $date, int $dayOfWeek): bool
    {
        // Convert ISO day (1-7, Sun=7) to PHP day (0-6, Sun=0) for getServiceAvailability(),
        // which internally converts back to ISO. This round-trip is intentional.
        $phpDayOfWeek = $dayOfWeek === 7 ? 0 : $dayOfWeek;

        // Check service's own schedule first
        $service = Service::find()->siteId('*')->id($serviceId)->one();
        if ($service?->hasAvailabilitySchedule()) {
            $serviceAvailability = $this->getServiceAvailability($service, $date, $phpDayOfWeek);
            if ($serviceAvailability && ($serviceAvailability['enabled'] ?? false)) {
                return true;
            }
        }

        $scheduleAssignment = Slots::getInstance()->getScheduleAssignment();

        if ($employeeId) {
            $activeSchedule = $scheduleAssignment->getActiveScheduleForDate($employeeId, $date);
            return $activeSchedule !== null && !empty($activeSchedule->getWorkingSlotsForDay($dayOfWeek));
        }

        $employees = Employee::find()->siteId('*')->serviceId($serviceId)->all();
        $employeeIds = array_map(fn($e) => $e->id, $employees);
        if (!empty($employeeIds)) {
            $schedulesByEmployee = $scheduleAssignment->getActiveSchedulesForDateBatch($employeeIds, $date);
            foreach ($schedulesByEmployee as $activeSchedule) {
                // Employees without an active schedule on this date come back as null.
                if ($activeSchedule !== null && !empty($activeSchedule->getWorkingSlotsForDay($dayOfWeek))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convert PHP day-of-week (0=Sunday) to ISO 8601 day-of-week (1=Monday, 7=Sunday).
     */
    private static function toIsoDayOfWeek(int $dayOfWeek): int
    {
        return $dayOfWeek === 0 ? 7 : $dayOfWeek;
    }
}
