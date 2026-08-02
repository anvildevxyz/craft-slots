<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Service;
use anvildev\slots\events\AfterAvailabilityCheckEvent;
use anvildev\slots\events\BeforeAvailabilityCheckEvent;
use anvildev\slots\factories\ReservationFactory;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use DateTime;

/**
 * Availability Service
 *
 * Subtractive model: Available = WorkingHours \ (Bookings ∪ Buffers ∪ Exclusions)
 *
 * Delegates to TimeWindowService, SlotGeneratorService, ScheduleResolverService, CapacityService.
 */
class AvailabilityService extends Component
{
    public const EVENT_BEFORE_AVAILABILITY_CHECK = 'beforeAvailabilityCheck';
    public const EVENT_AFTER_AVAILABILITY_CHECK = 'afterAvailabilityCheck';

    protected ?DateTime $currentDateTime = null;

    /** @var array<string, array> Cache of reservations keyed by date */
    private array $reservationCache = [];
    private bool $reservationCacheActive = false;
    private ?array $currentReservations = null;

    private TimeWindowService $timeWindowService;
    private SlotGeneratorService $slotGeneratorService;
    private ScheduleResolverService $scheduleResolverService;
    private CapacityService $capacityService;
    private TimezoneService $timezoneService;

    /** @var array<string, array> Per-request slot cache keyed by "{date}-{employeeId}-{locationId}-{serviceId}" */
    private array $slotCache = [];

    /**
     * Get the shared TimeWindowService instance used by this service and its delegates.
     */
    public function getSharedTimeWindowService(): TimeWindowService
    {
        return $this->timeWindowService;
    }

    public function init(): void
    {
        parent::init();
        $this->timeWindowService = new TimeWindowService();
        $this->slotGeneratorService = new SlotGeneratorService();
        $this->slotGeneratorService->setTimeWindowService($this->timeWindowService);
        $this->scheduleResolverService = new ScheduleResolverService();
        $this->capacityService = new CapacityService();
        $this->capacityService->setTimeWindowService($this->timeWindowService);
        $this->timezoneService = new TimezoneService();
    }

    /**
     * Clear the per-request slot cache. Call when reservation state changes
     * (e.g., at the start of createReservation) to prevent stale results.
     */
    public function clearSlotCache(): void
    {
        $this->slotCache = [];
    }

    /**
     * Get available time slots for a specific date.
     * No caching -- data freshness is critical to prevent double-bookings.
     */
    public function getAvailableSlots(
        string $date,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?int $serviceId = null,
        int $requestedQuantity = 1,
        ?string $userTimezone = null,
        ?string $softLockToken = null,
        int $extrasDuration = 0,
        ?string $targetTime = null,
        ?int $excludeReservationId = null,
    ): array {
        $perfStart = microtime(true);

        $beforeCheckEvent = new BeforeAvailabilityCheckEvent([
            'date' => $date,
            'serviceId' => $serviceId,
            'employeeId' => $employeeId,
            'locationId' => $locationId,
            'quantity' => $requestedQuantity,
            'criteria' => ['userTimezone' => $userTimezone],
        ]);
        $this->trigger(self::EVENT_BEFORE_AVAILABILITY_CHECK, $beforeCheckEvent);

        if (!$beforeCheckEvent->isValid) {
            return $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [], $perfStart);
        }

        $date = $beforeCheckEvent->date;
        $serviceId = $beforeCheckEvent->serviceId;
        $employeeId = $beforeCheckEvent->employeeId;
        $locationId = $beforeCheckEvent->locationId;
        $requestedQuantity = $beforeCheckEvent->quantity;

        $slotCacheKey = "{$date}-" . ($employeeId ?? 'null') . '-' . ($locationId ?? 'null') . '-' . ($serviceId ?? 'null')
            . "-q{$requestedQuantity}-tz" . ($userTimezone ?? 'null') . '-sl' . ($softLockToken ?? 'null')
            . "-ed{$extrasDuration}-tt" . ($targetTime ?? 'null')
            . '-ex' . ($excludeReservationId ?? 'null');
        if (isset($this->slotCache[$slotCacheKey])) {
            return $this->slotCache[$slotCacheKey];
        }

        if (count($this->slotCache) >= 500) {
            $this->slotCache = [];
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            Craft::warning("Invalid date format: {$date}", __METHOD__);
            return $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [], $perfStart);
        }

        $dayOfWeek = (int)$dateObj->format('N');

        $service = $serviceId ? Service::find()->siteId('*')->id($serviceId)->one() : null;
        if ($serviceId && (!$service || !$service->enabled)) {
            return $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [], $perfStart);
        }

        $extrasDuration = max(0, $extrasDuration);
        $duration = ($service?->duration ?? 60) + $extrasDuration;
        if ($duration <= 0) {
            Craft::error("Service {$serviceId} has invalid duration: {$duration}", __METHOD__);
            return $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [], $perfStart);
        }

        $schedules = $this->scheduleResolverService->getWorkingHours($dayOfWeek, $employeeId, $locationId, $serviceId, $date);

        if (empty($schedules) && $service?->hasAvailabilitySchedule()) {
            Craft::debug("Using service-level availability schedule for service {$serviceId}" . ($employeeId ? ", filtering for employee {$employeeId}" : ""), __METHOD__);
            return $this->getSlotsFromServiceSchedule($service, $date, $dayOfWeek, $locationId, $perfStart, $softLockToken, $employeeId, $extrasDuration, $excludeReservationId);
        }

        if (empty($schedules)) {
            Craft::debug("No working hours found for {$date}", __METHOD__);
            return $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [], $perfStart);
        }

        $allSlots = $this->processEmployeeSlots($schedules, $date, $service, $serviceId, $locationId, $softLockToken, $duration, $excludeReservationId);
        $allSlots = $this->capacityService->enrichSlotsWithCapacity($allSlots, $date, $serviceId, $excludeReservationId);
        $allSlots = $this->filterByCapacity($allSlots);
        $slotTimezone = !empty($allSlots) ? ($allSlots[array_key_first($allSlots)]['timezone'] ?? null) : null;
        $allSlots = $this->filterPastSlots($allSlots, $date, $serviceId, $service, $slotTimezone);
        $allSlots = $this->filterSoftLockedSlots($allSlots, $date, $serviceId, $locationId, $softLockToken);

        if ($requestedQuantity > 1) {
            $allSlots = $this->filterByQuantity($allSlots, $requestedQuantity, $serviceId);
        }

        if ($userTimezone) {
            $allSlots = $this->shiftAllSlots($allSlots, $date, $userTimezone, $this->timezoneService);
        }

        if ($employeeId === null && count($allSlots) > 0) {
            $allSlots = $this->slotGeneratorService->deduplicateByTime($allSlots);
            $allSlots = $this->filterDeduplicatedSoftLocks($allSlots, $date, $serviceId, $locationId, $softLockToken, $this->currentReservations);
        }

        $finalSlots = $this->slotGeneratorService->sortByTime(array_values($allSlots));
        $this->currentReservations = null;

        if ($targetTime !== null) {
            foreach ($finalSlots as $slot) {
                if (($slot['time'] ?? '') === $targetTime) {
                    $result = $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [$slot], $perfStart);
                    $this->slotCache[$slotCacheKey] = $result;
                    return $result;
                }
            }

            $result = $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, [], $perfStart);
            $this->slotCache[$slotCacheKey] = $result;
            return $result;
        }

        Craft::info("Returning " . count($finalSlots) . " available slots for {$date}", __METHOD__);
        $result = $this->fireAfterEvent($date, $serviceId, $employeeId, $locationId, $finalSlots, $perfStart);
        $this->slotCache[$slotCacheKey] = $result;
        return $result;
    }

    /**
     * Generate available slots per employee using the subtractive model:
     * working hours minus bookings, buffers, and blackouts.
     */
    private function processEmployeeSlots(
        array $schedules,
        string $date,
        ?Service $service,
        ?int $serviceId,
        ?int $locationId,
        ?string $softLockToken,
        ?int $duration = null,
        ?int $excludeReservationId = null,
    ): array {
        $schedulesByEmployee = [];
        foreach ($schedules as $schedule) {
            $schedulesByEmployee[$schedule->employeeId][] = [
                'start' => $schedule->startTime,
                'end' => $schedule->endTime,
                'locationId' => $schedule->locationId,
            ];
        }

        $employeeIds = array_keys($schedulesByEmployee);
        if (empty($employeeIds)) {
            return [];
        }

        $employeesById = [];
        foreach (Employee::find()->siteId('*')->id($employeeIds)->indexBy('id')->all() as $id => $emp) {
            $employeesById[$id] = ['title' => $emp->title, 'timezone' => $emp->timezone ?? null];
        }

        // Fetch ALL reservations for these employees on this date (regardless of service)
        // so that cross-service bookings block the employee's time correctly.
        $allReservations = $this->getReservationsForDate($date);

        // When rescheduling, exclude the reservation being updated so its own time block
        // is not subtracted from availability (otherwise rescheduling to the same slot fails).
        if ($excludeReservationId !== null) {
            $allReservations = array_filter($allReservations, fn($r) => $r->id !== $excludeReservationId);
            $allReservations = array_values($allReservations);
        }

        $this->currentReservations = $allReservations;
        $reservationsByEmployee = [];
        foreach ($allReservations as $res) {
            $reservationsByEmployee[$res->employeeId][] = $res;
        }

        $blackedOutEmployees = $this->scheduleResolverService->getBlackedOutEmployeeIds($date, $employeeIds, $locationId);
        $duration = $duration ?? ($service?->duration ?? 60);
        $slotInterval = $this->slotGeneratorService->getSlotInterval($service ?? $serviceId, $duration);

        $allSlots = [];
        foreach ($schedulesByEmployee as $empId => $empWindowsRaw) {
            if (isset($blackedOutEmployees[$empId])) {
                continue;
            }

            $timeWindows = $this->timeWindowService->mergeWindows($empWindowsRaw);
            $timeWindows = $this->subtractEmployeeBookingsFromList($timeWindows, $reservationsByEmployee[$empId] ?? [], $service);

            $empLocationId = $locationId ?? ($timeWindows[0]['locationId'] ?? null);

            $empSlots = $this->slotGeneratorService->generateSlots(
                $timeWindows,
                $duration,
                $slotInterval,
                ['serviceId' => $serviceId, 'locationId' => $locationId ?? $empLocationId]
            );

            $empData = $employeesById[$empId] ?? ['title' => 'Unknown', 'timezone' => null];
            $allSlots = array_merge($allSlots, $this->slotGeneratorService->addEmployeeInfo($empSlots, $empId, $empData['title'], $empData['timezone']));
        }

        return $allSlots;
    }

    /**
     * Subtract bookings from time windows using a pre-loaded list.
     * Buffers: blockedStart = booking.startTime - bufferBefore, blockedEnd = booking.endTime + bufferAfter
     */
    private function subtractEmployeeBookingsFromList(array $timeWindows, array $bookings, ?Service $service): array
    {
        $serviceIds = array_unique(array_filter(array_map(fn($b) => $b->serviceId, $bookings)));
        $servicesById = !empty($serviceIds) ? Service::find()->siteId('*')->id($serviceIds)->indexBy('id')->all() : [];

        foreach ($bookings as $booking) {
            // Timeless bookings (whole-day/flexible-day services) don't block a
            // timed window and have no start/end time to subtract.
            if ($booking->startTime === null || $booking->endTime === null) {
                continue;
            }
            $bookingService = $booking->serviceId ? ($servicesById[$booking->serviceId] ?? null) : null;
            $blockedStart = $this->shiftWithinDay($booking->startTime, -($bookingService?->bufferBefore ?? 0));
            $blockedEnd = $this->shiftWithinDay($booking->endTime, $bookingService?->bufferAfter ?? 0);
            $timeWindows = $this->timeWindowService->subtractWindow($timeWindows, $blockedStart, $blockedEnd);
        }

        return $timeWindows;
    }

    /**
     * Subtract existing bookings (with buffer expansion) from time windows for employee-less services.
     *
     * Each booking occupies its duration plus bufferBefore/bufferAfter and consumes
     * `quantity` seats of the day's capacity. A range is only cut out of the windows
     * once the bookings overlapping it have taken every seat, so a group slot stays
     * bookable until it is genuinely full.
     *
     * @param int|null $capacity Seats per slot from the Schedule's per-day capacity. Null = one booking per slot.
     */
    private function subtractBookingsFromWindows(array $timeWindows, array $bookings, Service $service, ?int $capacity = null): array
    {
        $occupancy = $this->buildOccupancyIntervals($bookings, $service->bufferBefore ?? 0, $service->bufferAfter ?? 0);
        if (empty($occupancy)) {
            return $timeWindows;
        }

        foreach ($this->findSaturatedRanges($occupancy, max(1, $capacity ?? 1)) as [$blockedStart, $blockedEnd]) {
            $timeWindows = $this->timeWindowService->subtractWindow($timeWindows, $blockedStart, $blockedEnd);
        }

        return $timeWindows;
    }

    /**
     * Expand bookings into buffer-padded occupancy intervals.
     *
     * @return array<array{start: string, end: string, seats: int}>
     */
    private function buildOccupancyIntervals(array $bookings, int $bufferBefore, int $bufferAfter): array
    {
        $intervals = [];

        foreach ($bookings as $booking) {
            // Timeless bookings (whole-day/flexible-day services) don't block a
            // timed window and have no start/end time to subtract.
            if ($booking->startTime === null || $booking->endTime === null) {
                continue;
            }

            $intervals[] = [
                'start' => $this->shiftWithinDay($booking->startTime, -$bufferBefore),
                'end' => $this->shiftWithinDay($booking->endTime, $bufferAfter),
                'seats' => max(1, (int)($booking->quantity ?? 1)),
            ];
        }

        return $intervals;
    }

    /**
     * Find the ranges where overlapping bookings have taken every seat.
     *
     * Sweeps the interval boundaries: seat usage is constant between two consecutive
     * boundaries, so each segment is either saturated or not. Touching segments are
     * merged so subtractWindow sees contiguous blocks rather than adjacent slivers.
     *
     * @param array<array{start: string, end: string, seats: int}> $intervals
     * @return array<array{0: string, 1: string}> Saturated ranges as [start, end] pairs
     */
    private function findSaturatedRanges(array $intervals, int $capacity): array
    {
        $boundaries = [];
        foreach ($intervals as $interval) {
            $boundaries[$this->timeWindowService->timeToMinutes($interval['start'])] = $interval['start'];
            $boundaries[$this->timeWindowService->timeToMinutes($interval['end'])] = $interval['end'];
        }
        ksort($boundaries);
        $boundaries = array_values($boundaries);

        $saturated = [];
        for ($i = 0, $len = count($boundaries) - 1; $i < $len; $i++) {
            [$segmentStart, $segmentEnd] = [$boundaries[$i], $boundaries[$i + 1]];

            $used = 0;
            foreach ($intervals as $interval) {
                if ($interval['start'] < $segmentEnd && $interval['end'] > $segmentStart) {
                    $used += $interval['seats'];
                }
            }

            if ($used < $capacity) {
                continue;
            }

            $last = array_key_last($saturated);
            if ($last !== null && $saturated[$last][1] === $segmentStart) {
                $saturated[$last][1] = $segmentEnd;
                continue;
            }

            $saturated[] = [$segmentStart, $segmentEnd];
        }

        return $saturated;
    }

    /** Shift a time by minutes, clamped to 00:00–24:00 so buffers can't run off the end of the day. */
    private function shiftWithinDay(string $time, int $minutes): string
    {
        $shifted = $this->timeWindowService->timeToMinutes($time) + $minutes;
        return $this->timeWindowService->minutesToTime(max(0, min(1440, $shifted)));
    }

    public function isSlotAvailable(
        string $date,
        string $startTime,
        string $endTime,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?int $serviceId = null,
        int $requestedQuantity = 1,
        ?string $softLockToken = null,
        int $extrasDuration = 0,
        ?int $excludeReservationId = null,
        bool $bypassCache = false,
    ): bool {
        if (!$this->capacityService->isQuantityAllowed($requestedQuantity, $serviceId)) {
            Craft::info("isSlotAvailable FAIL: quantity not allowed for serviceId=" . ($serviceId ?? 'NULL'), __METHOD__);
            return false;
        }

        if (!$this->capacityService->hasAvailableCapacity($date, $startTime, $endTime, $employeeId, $serviceId, $requestedQuantity, $excludeReservationId)) {
            Craft::info("isSlotAvailable FAIL: no capacity for employeeId=" . ($employeeId ?? 'NULL') . " serviceId=" . ($serviceId ?? 'NULL') . " at {$date} {$startTime}-{$endTime}", __METHOD__);
            return false;
        }

        // When bypassCache is true (e.g., re-checking after acquiring an employee lock),
        // temporarily disable the reservation cache and clear the slot cache to query the
        // DB directly and avoid stale results that could lead to double-booking.
        $wasCacheActive = $this->reservationCacheActive;
        if ($bypassCache) {
            $this->reservationCacheActive = false;
            $this->slotCache = [];
        }

        $normalizedStart = substr($startTime, 0, 5);
        $slots = $this->getAvailableSlots($date, $employeeId, $locationId, $serviceId, $requestedQuantity, null, $softLockToken, $extrasDuration, $normalizedStart, $excludeReservationId);
        $normalizedEnd = substr($endTime, 0, 5);

        if ($bypassCache) {
            $this->reservationCacheActive = $wasCacheActive;
        }

        foreach ($slots as $slot) {
            if (substr($slot['time'] ?? '', 0, 5) === $normalizedStart
                && substr($slot['endTime'] ?? '', 0, 5) === $normalizedEnd
                && ($employeeId === null || $slot['employeeId'] === null || $slot['employeeId'] === $employeeId)
            ) {
                return true;
            }
        }

        Craft::info("isSlotAvailable FAIL: no matching slot for {$normalizedStart}-{$normalizedEnd} employeeId=" . ($employeeId ?? 'NULL') . " | found " . count($slots) . " slots: " . json_encode(array_slice(array_map(fn($s) => ($s['time'] ?? '?') . '-' . ($s['endTime'] ?? '?') . '@emp=' . ($s['employeeId'] ?? 'NULL'), $slots), 0, 10)), __METHOD__);
        return false;
    }

    /**
     * Get slots from service-level schedule.
     * Two scenarios: employee-less (capacity from schedule) or employee-based (capacity 1 per employee).
     */
    protected function getSlotsFromServiceSchedule(
        Service $service,
        string $date,
        int $dayOfWeek,
        ?int $locationId,
        float $perfStart,
        ?string $softLockToken = null,
        ?int $employeeId = null,
        int $extrasDuration = 0,
        ?int $excludeReservationId = null,
    ): array {
        $availability = $this->scheduleResolverService->getServiceAvailability($service, $date, $dayOfWeek);
        if ($availability === null) {
            Craft::debug("Service {$service->id} has no matching schedule for date {$date}", __METHOD__);
            return $this->fireAfterEvent($date, $service->id, $employeeId, $locationId, [], $perfStart);
        }

        $timeWindows = $this->scheduleResolverService->buildWindowsFromServiceAvailability($availability);
        if (empty($timeWindows) || $this->scheduleResolverService->isDateBlackedOut($date, $employeeId, $locationId)) {
            return $this->fireAfterEvent($date, $service->id, $employeeId, $locationId, [], $perfStart);
        }

        $duration = ($service->duration ?? 60) + max(0, $extrasDuration);
        $slotInterval = $this->slotGeneratorService->getSlotInterval($service, $duration);

        $employeeQuery = Employee::find()->siteId('*')->serviceId($service->id);
        if ($employeeId !== null) {
            $employeeQuery->id($employeeId);
        }
        $employees = $employeeQuery->all();

        if ($locationId !== null) {
            $employees = array_filter($employees, fn($e) => $e->locationId === $locationId || $e->locationId === null);
        }

        if (!empty($employees)) {
            Craft::debug("Service {$service->id} has " . count($employees) . " employees, generating employee-based slots from service schedule" . ($employeeId ? " (filtered for employee {$employeeId})" : ""), __METHOD__);
            return $this->getSlotsFromServiceScheduleWithEmployees($service, $date, $timeWindows, $employees, $locationId, $perfStart, $softLockToken, $employeeId, $extrasDuration, $excludeReservationId);
        }

        Craft::debug("Service {$service->id} has no employees, using service schedule for employee-less booking", __METHOD__);

        // Subtract existing bookings (with buffer expansion) from time windows
        // so overlapping slots and buffer periods are properly blocked — but only
        // once they have used up the day's capacity, so group slots stay bookable.
        $existingBookings = $this->getReservationsForDate($date, null, $service->id);
        $employeelessBookings = array_filter(
            $existingBookings,
            // When rescheduling, the booking being moved must not block its own slot.
            fn($r) => $r->employeeId === null && ($excludeReservationId === null || $r->id !== $excludeReservationId),
        );
        $scheduleCapacity = Slots::getInstance()->getScheduleAssignment()
            ->getActiveScheduleForServiceOnDate($service->id, $date)
            ?->getCapacityForDay($dayOfWeek === 0 ? 7 : $dayOfWeek);
        $timeWindows = $this->subtractBookingsFromWindows($timeWindows, $employeelessBookings, $service, $scheduleCapacity);

        $allSlots = $this->slotGeneratorService->generateSlots($timeWindows, $duration, $slotInterval, [
            'serviceId' => $service->id,
            'locationId' => $locationId,
            'isServiceSchedule' => true,
            'timezone' => Craft::$app->getTimeZone(),
        ]);

        $allSlots = $this->capacityService->enrichSlotsWithCapacity($allSlots, $date, $service->id, $excludeReservationId);
        $allSlots = $this->filterByCapacity($allSlots);
        $serviceTimezone = !empty($allSlots) ? ($allSlots[array_key_first($allSlots)]['timezone'] ?? null) : null;
        $allSlots = $this->filterPastSlots($allSlots, $date, $service->id, $service, $serviceTimezone);
        $allSlots = $this->filterSoftLockedSlots($allSlots, $date, $service->id, $locationId, $softLockToken);
        $allSlots = $this->slotGeneratorService->sortByTime($allSlots);

        return $this->fireAfterEvent($date, $service->id, null, $locationId, $allSlots, $perfStart);
    }

    /**
     * Generate slots from service schedule for employees.
     * Each employee works the service schedule with capacity 1.
     */
    protected function getSlotsFromServiceScheduleWithEmployees(
        Service $service,
        string $date,
        array $timeWindows,
        array $employees,
        ?int $locationId,
        float $perfStart,
        ?string $softLockToken = null,
        ?int $selectedEmployeeId = null,
        int $extrasDuration = 0,
        ?int $excludeReservationId = null,
    ): array {
        $duration = ($service->duration ?? 60) + max(0, $extrasDuration);
        $slotInterval = $this->slotGeneratorService->getSlotInterval($service, $duration);

        // Fetch ALL reservations on this date (regardless of service)
        // so that cross-service bookings block employee time correctly.
        $allReservations = $this->getReservationsForDate($date);

        // When rescheduling, the booking being moved must not block its own slot.
        if ($excludeReservationId !== null) {
            $allReservations = array_values(array_filter($allReservations, fn($r) => $r->id !== $excludeReservationId));
        }
        $reservationsByEmployee = [];
        $unassignedBookings = [];
        foreach ($allReservations as $res) {
            if ($res->employeeId === null) {
                // Only count unassigned bookings for the current service toward capacity
                if ($res->serviceId === $service->id) {
                    $qty = $res->quantity ?? 1;
                    for ($q = 0; $q < $qty; $q++) {
                        $unassignedBookings[] = ['start' => $res->startTime, 'end' => $res->endTime];
                    }
                }
            } else {
                $reservationsByEmployee[$res->employeeId][] = $res;
            }
        }

        // Batch load locations for timezone lookup
        $locationIds = array_filter(array_unique(array_map(fn($e) => $e->locationId, $employees)));
        $locationsById = [];
        if (!empty($locationIds)) {
            foreach (\anvildev\slots\elements\Location::find()->siteId('*')->id($locationIds)->indexBy('id')->all() as $id => $loc) {
                $locationsById[$id] = $loc->timezone ?? Craft::$app->getTimeZone();
            }
        }
        $defaultTimezone = Craft::$app->getTimeZone();

        $employeeIds = array_map(fn($e) => $e->id, $employees);
        $blackedOutEmployees = $this->scheduleResolverService->getBlackedOutEmployeeIds($date, $employeeIds, $locationId);

        $allSlots = [];
        foreach ($employees as $employee) {
            if (isset($blackedOutEmployees[$employee->id])) {
                continue;
            }

            $empWindows = $this->subtractEmployeeBookingsFromList($timeWindows, $reservationsByEmployee[$employee->id] ?? [], $service);

            $empSlots = $this->slotGeneratorService->generateSlots($empWindows, $duration, $slotInterval, [
                'serviceId' => $service->id,
                'locationId' => $locationId ?? $employee->locationId,
            ]);

            $empTimezone = $employee->locationId ? ($locationsById[$employee->locationId] ?? $defaultTimezone) : $defaultTimezone;
            $allSlots = array_merge($allSlots, $this->slotGeneratorService->addEmployeeInfo($empSlots, $employee->id, $employee->title ?? "Unknown", $empTimezone));
        }

        $allSlots = $this->capacityService->enrichSlotsWithCapacity($allSlots, $date, $service->id, $excludeReservationId);
        $allSlots = $this->filterByCapacity($allSlots);
        $empTimezone = !empty($allSlots) ? ($allSlots[array_key_first($allSlots)]['timezone'] ?? null) : null;
        $allSlots = $this->filterPastSlots($allSlots, $date, $service->id, $service, $empTimezone);
        $allSlots = $this->filterSoftLockedSlots($allSlots, $date, $service->id, $locationId, $softLockToken);

        // Only deduplicate when no specific employee is selected ("Any available")
        if ($selectedEmployeeId === null) {
            $allSlots = $this->slotGeneratorService->deduplicateByTime($allSlots);
            $allSlots = $this->filterDeduplicatedSoftLocks($allSlots, $date, $service->id, $locationId, $softLockToken, $allReservations);

            if (!empty($unassignedBookings)) {
                $allSlots = $this->removeUnassignedBookingSlots($allSlots, $unassignedBookings);
            }
        }

        return $this->fireAfterEvent($date, $service->id, $selectedEmployeeId, $locationId, $this->slotGeneratorService->sortByTime(array_values($allSlots)), $perfStart);
    }

    private function fireAfterEvent(
        string $date,
        ?int $serviceId,
        ?int $employeeId,
        ?int $locationId,
        array $slots,
        float $perfStart,
    ): array {
        $calculationTime = microtime(true) - $perfStart;
        $afterCheckEvent = new AfterAvailabilityCheckEvent([
            'date' => $date,
            'serviceId' => $serviceId,
            'employeeId' => $employeeId,
            'locationId' => $locationId,
            'slots' => $slots,
            'availableSlots' => $slots,
            'slotCount' => count($slots),
            'calculationTime' => $calculationTime,
            'duration' => $calculationTime,
        ]);
        $this->trigger(self::EVENT_AFTER_AVAILABILITY_CHECK, $afterCheckEvent);

        return $afterCheckEvent->slots;
    }

    /**
     * @param array $locks Pre-loaded active soft lock records
     */
    private function isSlotLockedByRecords(array $locks, string $startTime, string $endTime, ?int $employeeId, ?int $locationId): bool
    {
        foreach ($locks as $lock) {
            if ($employeeId !== null && $lock->employeeId !== null && $lock->employeeId !== $employeeId) {
                continue;
            }
            if ($locationId !== null && $lock->locationId !== null && $lock->locationId !== $locationId) {
                continue;
            }
            if ($lock->startTime < $endTime && $lock->endTime > $startTime) {
                return true;
            }
        }
        return false;
    }

    /**
     * Total quantity held by active soft locks overlapping a slot (matching
     * employee/location), so a multi-capacity slot only loses the held seats
     * instead of disappearing on the first hold.
     *
     * @param array $locks Pre-loaded active soft lock records
     */
    private function sumLockedQuantity(array $locks, string $startTime, string $endTime, ?int $employeeId, ?int $locationId): int
    {
        $total = 0;
        foreach ($locks as $lock) {
            if ($employeeId !== null && $lock->employeeId !== null && $lock->employeeId !== $employeeId) {
                continue;
            }
            if ($locationId !== null && $lock->locationId !== null && $lock->locationId !== $locationId) {
                continue;
            }
            if ($lock->startTime < $endTime && $lock->endTime > $startTime) {
                $total += max(1, (int) ($lock->quantity ?? 1));
            }
        }
        return $total;
    }

    protected function filterPastSlots(array $slots, string $date, ?int $serviceId = null, ?Service $service = null, ?string $timezone = null): array
    {
        // Use the slot/employee timezone when available to avoid filtering
        // future slots when the server timezone differs from the location timezone.
        $now = $timezone
            ? new DateTime('now', new \DateTimeZone($timezone))
            : $this->getCurrentDateTime();
        $today = $now->format('Y-m-d');

        if ($date < $today) {
            return [];
        }

        $minAdvanceHours = Slots::getInstance()->getSettings()->minimumAdvanceBookingHours ?? 0;

        // Per-service override takes precedence over global setting
        if ($serviceId !== null) {
            $resolvedService = $service ?? Service::find()->siteId('*')->id($serviceId)->one();
            if ($resolvedService && $resolvedService->minTimeBeforeBooking !== null) {
                $minAdvanceHours = $resolvedService->minTimeBeforeBooking;
            }
        }

        $cutoffDateTime = clone $now;
        if ($minAdvanceHours > 0) {
            $cutoffDateTime->add(new \DateInterval("PT{$minAdvanceHours}H"));
        }

        $cutoffDate = $cutoffDateTime->format('Y-m-d');
        if ($date < $cutoffDate) {
            return [];
        }
        if ($date > $cutoffDate) {
            return $slots;
        }

        // Strictly greater than: excludes the current minute to prevent booking in-progress slots
        $cutoffTime = $cutoffDateTime->format('H:i');
        return array_filter($slots, fn($slot) => substr($slot['time'], 0, 5) > $cutoffTime);
    }

    protected function filterSoftLockedSlots(
        array $slots,
        string $date,
        ?int $serviceId,
        ?int $locationId = null,
        ?string $softLockToken = null,
    ): array {
        if (empty($slots) || $serviceId === null) {
            return $slots;
        }

        $locks = Slots::getInstance()->getSoftLock()->getActiveSoftLocksForDate($date, $serviceId, $softLockToken);
        if (empty($locks)) {
            return $slots;
        }

        $result = [];
        foreach ($slots as $slot) {
            $startTime = $slot['time'] ?? null;
            $endTime = $slot['endTime'] ?? null;
            if ($startTime === null || $endTime === null) {
                continue;
            }
            $employeeId = $slot['employeeId'] ?? null;
            $slotLocationId = $slot['locationId'] ?? $locationId;

            // Multi-capacity (group) slots: a soft lock holds a number of seats,
            // not the whole slot. Decrement the remaining capacity by the held
            // quantity and keep the slot while seats are still free. Single-
            // capacity, per-employee and unlimited slots keep the original
            // all-or-nothing hold.
            $capacity = $slot['availableCapacity'] ?? null;
            if (is_int($capacity) && $capacity > 1) {
                $held = $this->sumLockedQuantity($locks, $startTime, $endTime, $employeeId, $slotLocationId);
                $remaining = $capacity - $held;
                if ($remaining <= 0) {
                    continue;
                }
                $slot['availableCapacity'] = $remaining;
                $result[] = $slot;
                continue;
            }

            if (!$this->isSlotLockedByRecords($locks, $startTime, $endTime, $employeeId, $slotLocationId)) {
                $result[] = $slot;
            }
        }

        return array_values($result);
    }

    /**
     * Post-deduplication soft lock filter for "Any available" employee mode.
     * Checks whether at least one employee remains available (not soft-locked and not booked)
     * for each time slot. Unassigned bookings reduce the effective employee count.
     */
    protected function filterDeduplicatedSoftLocks(
        array $slots,
        string $date,
        ?int $serviceId,
        ?int $locationId = null,
        ?string $softLockToken = null,
        ?array $preloadedReservations = null,
    ): array {
        if (empty($slots) || $serviceId === null) {
            return $slots;
        }

        $employees = Employee::find()->siteId('*')->serviceId($serviceId)->all();
        if ($locationId !== null) {
            $employees = array_filter($employees, fn($e) => $e->locationId === $locationId || $e->locationId === null);
        }

        $locks = Slots::getInstance()->getSoftLock()->getActiveSoftLocksForDate($date, $serviceId, $softLockToken);

        // Deliberately unfiltered by service: an employee booked on *any* service is
        // busy, so their bookings elsewhere must still count against this one.
        $reservations = $preloadedReservations ?? $this->getReservationsForDate($date);
        $reservationsByEmployee = [];
        foreach ($reservations as $reservation) {
            // Timeless bookings (whole-day services, events) can't overlap a timed
            // slot; skip them so the time-overlap math below never receives a null
            // start/end (preloaded reservations may span other services).
            if ($reservation->startTime === null || $reservation->endTime === null) {
                continue;
            }

            // Unassigned bookings are the exception: one belongs to the capacity of
            // the service it was made against, so another service's employee-less
            // bookings must not eat into this service's pool of free employees.
            if ($reservation->employeeId === null && (int)$reservation->serviceId !== $serviceId) {
                continue;
            }

            $reservationsByEmployee[$reservation->employeeId][] = [
                'start' => $reservation->startTime,
                'end' => $reservation->endTime,
            ];
        }

        $employeeIds = array_map(fn($e) => $e->id, $employees);
        Craft::debug("filterDeduplicatedSoftLocks: date={$date}, serviceId={$serviceId}, employees=[" . implode(',', $employeeIds) . "], reservations=" . count($reservations), __METHOD__);
        if (YII_DEBUG) { // @phpstan-ignore-line (YII_DEBUG is false in static analysis but true at runtime in dev)
            foreach ($reservations as $reservation) {
                Craft::debug("  Reservation: id={$reservation->id}, employeeId=" . ($reservation->employeeId ?? 'NULL') . ", time={$reservation->startTime}-{$reservation->endTime}", __METHOD__);
            }
        }

        $unassignedBookings = $reservationsByEmployee[null] ?? [];
        Craft::debug("  Unassigned bookings count: " . count($unassignedBookings), __METHOD__);

        $filteredSlots = [];
        foreach ($slots as $slot) {
            $startTime = $slot['time'] ?? null;
            $endTime = $slot['endTime'] ?? null;
            $slotLocationId = $slot['locationId'] ?? $locationId;

            if ($startTime === null || $endTime === null) {
                continue;
            }

            $slotStartMin = $this->timeWindowService->timeToMinutes($startTime);
            $slotEndMin = $this->timeWindowService->timeToMinutes($endTime);

            $unassignedOverlapCount = 0;
            foreach ($unassignedBookings as $booking) {
                if ($slotStartMin < $this->timeWindowService->timeToMinutes($booking['end']) && $slotEndMin > $this->timeWindowService->timeToMinutes($booking['start'])) {
                    $unassignedOverlapCount++;
                }
            }

            $availableEmployeeCount = 0;
            foreach ($employees as $employee) {
                if ($this->isSlotLockedByRecords($locks, $startTime, $endTime, $employee->id, $slotLocationId)) {
                    continue;
                }

                $hasOverlap = false;
                foreach ($reservationsByEmployee[$employee->id] ?? [] as $booking) {
                    if ($slotStartMin < $this->timeWindowService->timeToMinutes($booking['end']) && $slotEndMin > $this->timeWindowService->timeToMinutes($booking['start'])) {
                        $hasOverlap = true;
                        break;
                    }
                }

                if (!$hasOverlap) {
                    $availableEmployeeCount++;
                }
            }

            Craft::debug("  Slot {$startTime}: availableEmployees={$availableEmployeeCount}, unassignedOverlap={$unassignedOverlapCount}, show=" . ($availableEmployeeCount > $unassignedOverlapCount ? 'YES' : 'NO'), __METHOD__);
            if ($availableEmployeeCount > $unassignedOverlapCount) {
                $filteredSlots[] = $slot;
            }
        }

        return $filteredSlots;
    }

    /** Remove fully booked slots (availableCapacity === 0). Null capacity = unlimited. */
    protected function filterByCapacity(array $slots): array
    {
        return array_filter($slots, fn($slot) => ($slot['availableCapacity'] ?? null) === null || $slot['availableCapacity'] > 0);
    }

    /**
     * Filter slots that cannot accommodate the requested quantity.
     * For employee-less services, capacity is handled by filterByCapacity().
     */
    protected function filterByQuantity(array $slots, int $quantity, ?int $serviceId = null): array
    {
        // Employee-less services use capacity-based filtering (handled by filterByCapacity),
        // so skip employee quantity filtering when the service has no assigned employees.
        if ($serviceId !== null) {
            $employeeCount = Employee::find()->siteId('*')->serviceId($serviceId)->count();
            if ($employeeCount === 0) {
                return $slots;
            }
        }

        return $this->slotGeneratorService->filterByEmployeeQuantity($slots, $quantity);
    }

    /** Shift slots from source timezones to user timezone, grouped by source for efficiency. */
    protected function shiftAllSlots(array $slots, string $date, string $userTimezone, TimezoneService $timezoneService): array
    {
        $slotsByTimezone = [];
        foreach ($slots as $slot) {
            $slotsByTimezone[$slot['timezone'] ?? Craft::$app->getTimezone()][] = $slot;
        }

        $allShifted = [];
        foreach ($slotsByTimezone as $sourceTz => $tzSlots) {
            $allShifted = array_merge($allShifted, $timezoneService->shiftSlots($tzSlots, $date, $sourceTz, $userTimezone));
        }
        return $allShifted;
    }

    /**
     * Remove slots that overlap with unassigned bookings.
     * A booking overlaps a slot when bookingStart < slotEnd AND bookingEnd > slotStart.
     * Each booking entry removes at most one slot.
     *
     * @param array $slots Available slots with 'time' and 'endTime' keys
     * @param array $unassignedBookings Booking intervals [['start' => 'H:i', 'end' => 'H:i'], ...]
     */
    protected function removeUnassignedBookingSlots(array $slots, array $unassignedBookings): array
    {
        // Track which bookings have been consumed
        $consumed = array_fill(0, count($unassignedBookings), false);

        $result = [];
        foreach ($slots as $slot) {
            $slotStart = $slot['time'];
            $slotEnd = $slot['endTime'] ?? $slotStart;
            $removed = false;

            foreach ($unassignedBookings as $i => $booking) {
                if ($consumed[$i]) {
                    continue;
                }

                // Overlap: bookingStart < slotEnd AND bookingEnd > slotStart
                if ($booking['start'] < $slotEnd && $booking['end'] > $slotStart) {
                    $consumed[$i] = true;
                    Craft::debug("Removing slot at {$slotStart} due to overlapping unassigned booking {$booking['start']}-{$booking['end']}", __METHOD__);
                    $removed = true;
                    break;
                }
            }

            if (!$removed) {
                $result[] = $slot;
            }
        }

        return $result;
    }

    /**
     * Fetch reservations for a date, optionally filtered by employee or service.
     * Uses preloaded cache when available. Excludes cancelled reservations.
     */
    protected function getReservationsForDate(string $date, ?int $employeeId = null, ?int $serviceId = null): array
    {
        if ($this->reservationCacheActive && array_key_exists($date, $this->reservationCache)) {
            $reservations = $this->reservationCache[$date];
            if ($employeeId !== null) {
                $reservations = array_filter($reservations, fn($r) => $r->employeeId === $employeeId);
            }
            if ($serviceId !== null) {
                $reservations = array_filter($reservations, fn($r) => $r->serviceId === $serviceId);
            }
            return array_values($reservations);
        }

        $query = ReservationFactory::find()
            ->siteId('*')
            ->bookingDate($date)
            ->status(['not', \anvildev\slots\records\ReservationRecord::STATUS_CANCELLED]);

        if ($employeeId !== null) {
            $query->employeeId($employeeId);
        }
        if ($serviceId !== null) {
            $query->serviceId($serviceId);
        }

        return $query->all();
    }

    /**
     * Preload all reservations for a date range into cache.
     * Avoids N+1 queries for calendar views.
     */
    protected function preloadReservationsForDateRange(string $startDate, string $endDate): void
    {
        $allReservations = ReservationFactory::find()
            ->siteId('*')
            ->bookingDate(['and', '>= ' . $startDate, '<= ' . $endDate])
            ->status(['not', \anvildev\slots\records\ReservationRecord::STATUS_CANCELLED])
            ->all();

        $current = DateTime::createFromFormat('Y-m-d', $startDate);
        $end = DateTime::createFromFormat('Y-m-d', $endDate);
        if ($current && $end) {
            while ($current <= $end) {
                $this->reservationCache[$current->format('Y-m-d')] = [];
                $current->modify('+1 day');
            }
        }

        foreach ($allReservations as $reservation) {
            $this->reservationCache[$reservation->getBookingDate()][] = $reservation;
        }

        $this->reservationCacheActive = true;
    }

    protected function clearReservationCache(): void
    {
        $this->reservationCache = [];
        $this->reservationCacheActive = false;
        $this->slotCache = [];
    }

    protected function getCurrentDateTime(): DateTime
    {
        return $this->currentDateTime ?: new DateTime();
    }

    public function getNextAvailableDate(
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        int $maxDaysToSearch = 90,
    ): ?string {
        $settings = Slots::getInstance()->getSettings();
        $minAdvanceHours = $settings->minimumAdvanceBookingHours ?? 0;

        // Per-service override takes precedence over global setting
        if ($serviceId !== null) {
            $service = \anvildev\slots\elements\Service::find()->siteId('*')->id($serviceId)->one();
            if ($service && $service->minTimeBeforeBooking !== null) {
                $minAdvanceHours = $service->minTimeBeforeBooking;
            }
        }

        $startDate = new DateTime();
        if ($minAdvanceHours > 0) {
            $startDate->modify("+{$minAdvanceHours} hours");
        }

        $rangeStart = (clone $startDate)->format('Y-m-d');
        $rangeEnd = (clone $startDate)->modify('+' . ($maxDaysToSearch - 1) . ' days')->format('Y-m-d');
        $this->preloadReservationsForDateRange($rangeStart, $rangeEnd);

        try {
            for ($i = 0; $i < $maxDaysToSearch; $i++) {
                $checkDate = (clone $startDate)->modify("+{$i} days")->format('Y-m-d');
                if (!empty($this->getAvailableSlots($checkDate, $employeeId, $locationId, $serviceId))) {
                    return $checkDate;
                }
            }
            return null;
        } finally {
            $this->clearReservationCache();
        }
    }

    /**
     * Count raw capacity slots for a date (working hours only, no booking subtraction).
     * Used by utilization reports to compute `booked / capacity`.
     */
    public function getCapacitySlotCount(
        string $date,
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): int {
        // When no service filter, aggregate capacity across all services
        if ($serviceId === null) {
            return $this->getAggregateCapacitySlotCount($date, $employeeId, $locationId);
        }

        return $this->getCapacitySlotCountForService($date, $serviceId, $employeeId, $locationId);
    }

    /**
     * Sum capacity across all enabled services for a date.
     */
    private function getAggregateCapacitySlotCount(string $date, ?int $employeeId, ?int $locationId): int
    {
        $services = Service::find()->siteId('*')->unique()->enabled()->all();
        $total = 0;
        foreach ($services as $service) {
            $total += $this->getCapacitySlotCountForService($date, $service->id, $employeeId, $locationId);
        }

        return $total;
    }

    /**
     * Count raw capacity slots for a single service on a date (no booking subtraction).
     * Used by utilization reports to compute `booked / capacity`.
     */
    private function getCapacitySlotCountForService(
        string $date,
        int $serviceId,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): int {
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            return 0;
        }

        $dayOfWeek = (int) $dateObj->format('N');

        $service = Service::find()->siteId('*')->id($serviceId)->one();
        if (!$service || !$service->enabled) {
            return 0;
        }

        $duration = $service->duration ?? 60;
        if ($duration <= 0) {
            return 0;
        }

        $slotInterval = $this->slotGeneratorService->getSlotInterval($service, $duration);

        $schedules = $this->scheduleResolverService->getWorkingHours($dayOfWeek, $employeeId, $locationId, $serviceId, $date);

        // Service-schedule path: service has its own availability schedule and no employee schedules matched
        if (empty($schedules) && $service->hasAvailabilitySchedule()) {
            return $this->getCapacityFromServiceSchedule($service, $date, $dayOfWeek, $employeeId, $locationId, $duration, $slotInterval);
        }

        if (empty($schedules)) {
            return 0;
        }

        // Group schedules by employee
        $schedulesByEmployee = [];
        foreach ($schedules as $schedule) {
            $schedulesByEmployee[$schedule->employeeId][] = [
                'start' => $schedule->startTime,
                'end' => $schedule->endTime,
            ];
        }

        $employeeIds = array_keys($schedulesByEmployee);
        $blackedOutEmployees = $this->scheduleResolverService->getBlackedOutEmployeeIds($date, $employeeIds, $locationId);

        $total = 0;
        foreach ($schedulesByEmployee as $empId => $empWindows) {
            if (isset($blackedOutEmployees[$empId])) {
                continue;
            }

            $merged = $this->timeWindowService->mergeWindows($empWindows);
            $slots = $this->slotGeneratorService->generateSlots($merged, $duration, $slotInterval);
            $total += count($slots);
        }

        return $total;
    }

    /**
     * Count capacity slots from a service-level schedule (no booking subtraction).
     */
    private function getCapacityFromServiceSchedule(
        Service $service,
        string $date,
        int $dayOfWeek,
        ?int $employeeId,
        ?int $locationId,
        int $duration,
        int $slotInterval,
    ): int {
        $availability = $this->scheduleResolverService->getServiceAvailability($service, $date, $dayOfWeek);
        if ($availability === null) {
            return 0;
        }

        $timeWindows = $this->scheduleResolverService->buildWindowsFromServiceAvailability($availability);
        if (empty($timeWindows)) {
            return 0;
        }

        $employeeQuery = Employee::find()->siteId('*')->serviceId($service->id);
        if ($employeeId !== null) {
            $employeeQuery->id($employeeId);
        }
        $employees = $employeeQuery->all();

        if ($locationId !== null) {
            $employees = array_filter($employees, fn($e) => $e->locationId === $locationId || $e->locationId === null);
        }

        if (!empty($employees)) {
            // Employee-based: each employee gets full service schedule capacity
            $employeeIds = array_map(fn($e) => $e->id, $employees);
            $blackedOutEmployees = $this->scheduleResolverService->getBlackedOutEmployeeIds($date, $employeeIds, $locationId);

            $total = 0;
            $slotsPerSchedule = count($this->slotGeneratorService->generateSlots($timeWindows, $duration, $slotInterval));
            foreach ($employees as $employee) {
                if (!isset($blackedOutEmployees[$employee->id])) {
                    $total += $slotsPerSchedule;
                }
            }

            return $total;
        }

        // Employee-less: just count slots from service schedule
        return count($this->slotGeneratorService->generateSlots($timeWindows, $duration, $slotInterval));
    }

    public function getAvailabilitySummary(
        string $startDate,
        string $endDate,
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): array {
        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end = DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$start || !$end) {
            Craft::warning("Invalid date format in getAvailabilitySummary: {$startDate} to {$endDate}", __METHOD__);
            return [];
        }

        // Cap the date range to 366 days to prevent DoS via excessively large ranges
        $maxDays = 366;
        $daysDiff = (int) $start->diff($end)->days;
        if ($daysDiff > $maxDays) {
            Craft::warning("getAvailabilitySummary range of {$daysDiff} days exceeds maximum of {$maxDays}, clamping end date", __METHOD__);
            $end = (clone $start)->modify("+{$maxDays} days");
            $endDate = $end->format('Y-m-d');
        }

        $this->preloadReservationsForDateRange($startDate, $endDate);

        // Pre-check which ISO days-of-week (1=Mon..7=Sun) have any working hours
        // configured across all applicable schedules. Days with no working hours
        // can never produce slots, so we skip the expensive getAvailableSlots() call.
        $workingDays = $this->getConfiguredWorkingDays($serviceId, $employeeId, $locationId);

        try {
            $summary = [];
            $current = clone $start;
            $today = (new DateTime())->setTime(0, 0, 0);

            while ($current <= $end) {
                $dateStr = $current->format('Y-m-d');

                if ($current < $today) {
                    $summary[$dateStr] = ['date' => $dateStr, 'available' => false, 'slotCount' => 0, 'isPast' => true];
                } elseif (!empty($workingDays) && !isset($workingDays[(int) $current->format('N')])) {
                    // No schedule has working hours for this day of week — skip slot calculation
                    $summary[$dateStr] = ['date' => $dateStr, 'available' => false, 'slotCount' => 0, 'isPast' => false];
                } else {
                    $slotCount = count($this->getAvailableSlots($dateStr, $employeeId, $locationId, $serviceId));
                    $summary[$dateStr] = ['date' => $dateStr, 'available' => $slotCount > 0, 'slotCount' => $slotCount, 'isPast' => false];
                }

                $current->modify('+1 day');
            }

            return $summary;
        } finally {
            $this->clearReservationCache();
        }
    }

    /**
     * Collect all ISO days-of-week (1=Mon..7=Sun) that have working hours in any
     * applicable employee or service schedule. Returns a set keyed by day number
     * for O(1) lookup, or an empty array when no schedules exist (meaning we
     * cannot safely skip any day and must fall through to full slot calculation).
     *
     * @return array<int, true> Set of ISO day numbers that have configured hours
     */
    private function getConfiguredWorkingDays(?int $serviceId, ?int $employeeId, ?int $locationId): array
    {
        $workingDays = [];

        // Collect from employee schedules (assigned Schedule elements + inline workingHours)
        $employeeQuery = Employee::find()->siteId('*');
        if ($employeeId !== null) {
            $employeeQuery->id($employeeId);
        }
        if ($serviceId !== null) {
            $employeeQuery->serviceId($serviceId);
        }
        if ($locationId !== null) {
            $employeeQuery->andWhere([
                'or',
                ['slots_employees.locationId' => $locationId],
                ['slots_employees.locationId' => null],
            ]);
        }

        $employees = $employeeQuery->all();

        if (empty($employees) && $serviceId === null) {
            // No employees and no service — we cannot determine schedules, fall through
            return [];
        }

        // Check inline (legacy) working hours on each employee
        foreach ($employees as $employee) {
            foreach ($employee->getScheduledDays() as $day) {
                $workingDays[$day] = true;
            }
        }

        // Check assigned Schedule elements for each employee
        foreach ($employees as $employee) {
            foreach ($employee->getEnabledSchedules() as $schedule) {
                foreach ($schedule->getScheduledDays() as $day) {
                    $workingDays[$day] = true;
                }
            }
        }

        // Check service-level availability schedule
        if ($serviceId !== null) {
            $service = Service::find()->siteId('*')->id($serviceId)->one();
            if ($service?->hasAvailabilitySchedule()) {
                $serviceSchedules = Slots::getInstance()->getScheduleAssignment()
                    ->getSchedulesForService($serviceId);

                foreach ($serviceSchedules as $schedule) {
                    if ($schedule->enabled) {
                        foreach ($schedule->getScheduledDays() as $day) {
                            $workingDays[$day] = true;
                        }
                    }
                }
            }
        }

        // If we found no schedules at all (no employees, no service schedule),
        // return empty to avoid incorrectly skipping days
        return $workingDays;
    }
}
