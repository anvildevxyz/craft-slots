<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Schedule;
use anvildev\slots\records\EmployeeScheduleAssignmentRecord;
use anvildev\slots\records\ServiceScheduleAssignmentRecord;
use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;

/**
 * Manages many-to-many assignments between schedules and employees/services.
 *
 * Handles CRUD for assignment records, priority ordering via sortOrder,
 * and resolves the active schedule for a given employee or service on a specific date
 * using date specificity tiers.
 */
class ScheduleAssignmentService extends Component
{
    /** @var array<string, Schedule|null> Memoized service schedules keyed by "serviceId:date" */
    private array $serviceScheduleCache = [];

    /** Clear the memoized service schedule cache. */
    public function clearServiceScheduleCache(): void
    {
        $this->serviceScheduleCache = [];
    }

    /** Evict the oldest cache entry (LRU approximation via FIFO) when the cache reaches its maximum size. */
    private function evictCacheIfFull(): void
    {
        if (count($this->serviceScheduleCache) >= 100) {
            array_shift($this->serviceScheduleCache);
        }
    }

    public function assignScheduleToEmployee(int $scheduleId, int $employeeId, int $sortOrder = 0): bool
    {
        $existing = EmployeeScheduleAssignmentRecord::find()
            ->where(['scheduleId' => $scheduleId, 'employeeId' => $employeeId])
            ->one();

        if ($existing) {
            if ($existing->sortOrder === $sortOrder) {
                return true;
            }
            $existing->sortOrder = $sortOrder;
            return $existing->save(false);
        }

        $record = new EmployeeScheduleAssignmentRecord();
        $record->scheduleId = $scheduleId;
        $record->employeeId = $employeeId;
        $record->sortOrder = $sortOrder;
        $record->uid = StringHelper::UUID();
        return $record->save();
    }

    public function unassignScheduleFromEmployee(int $scheduleId, int $employeeId): bool
    {
        return EmployeeScheduleAssignmentRecord::deleteAll([
            'scheduleId' => $scheduleId,
            'employeeId' => $employeeId,
        ]) > 0;
    }

    /** @return Schedule[] */
    public function getSchedulesForEmployee(int $employeeId): array
    {
        return Schedule::find()->siteId('*')->employeeId($employeeId)->status(null)->all();
    }

    /**
     * @param int[] $scheduleIds Schedule IDs in order
     * @throws \Throwable
     */
    public function setSchedulesForEmployee(int $employeeId, array $scheduleIds): bool
    {
        return $this->inTransaction(function() use ($employeeId, $scheduleIds) {
            EmployeeScheduleAssignmentRecord::deleteAll(['employeeId' => $employeeId]);
            foreach ($scheduleIds as $sortOrder => $scheduleId) {
                $record = new EmployeeScheduleAssignmentRecord();
                $record->scheduleId = (int)$scheduleId;
                $record->employeeId = $employeeId;
                $record->sortOrder = $sortOrder;
                $record->uid = StringHelper::UUID();
                if (!$record->save()) {
                    throw new \Exception("Failed to create assignment for schedule {$scheduleId}");
                }
            }
        });
    }

    /** @return int[] */
    public function getScheduleIdsForEmployee(int $employeeId): array
    {
        return array_map(
            fn($r) => $r->scheduleId,
            EmployeeScheduleAssignmentRecord::find()->where(['employeeId' => $employeeId])->orderBy(['sortOrder' => SORT_ASC])->all(),
        );
    }

    public function getActiveScheduleForDate(int $employeeId, string $date): ?Schedule
    {
        return $this->getActiveSchedulesForDateBatch([$employeeId], $date)[$employeeId] ?? null;
    }

    /**
     * Batch-fetch active schedules for multiple employees on a given date.
     * Single query replaces N individual getActiveScheduleForDate() calls.
     *
     * @param int[] $employeeIds
     * @return array<int, Schedule|null> Keyed by employee ID
     */
    public function getActiveSchedulesForDateBatch(array $employeeIds, string $date): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $assignments = EmployeeScheduleAssignmentRecord::find()
            ->where(['employeeId' => $employeeIds])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        if (empty($assignments)) {
            return array_fill_keys($employeeIds, null);
        }

        $employeeScheduleIds = [];
        $sortOrders = [];
        foreach ($assignments as $a) {
            $employeeScheduleIds[$a->employeeId][] = $a->scheduleId;
            $sortOrders[$a->scheduleId][$a->employeeId] = $a->sortOrder;
        }

        $schedules = Schedule::find()
            ->siteId('*')
            ->id(array_unique(array_merge(...array_values($employeeScheduleIds))))
            ->status(null)
            ->indexBy('id')
            ->all();

        $result = [];
        foreach ($employeeIds as $empId) {
            $ids = $employeeScheduleIds[$empId] ?? [];
            $active = [];
            foreach ($ids as $sid) {
                $s = $schedules[$sid] ?? null;
                if ($s?->enabled && $s->isActiveOn($date)) {
                    // Clone so that per-employee sortOrder doesn't mutate the shared indexed schedule instance.
                    $clone = clone $s;
                    $clone->sortOrder = $sortOrders[$sid][$empId] ?? PHP_INT_MAX;
                    $active[] = $clone;
                }
            }
            $result[$empId] = empty($active) ? null : self::sortByDateSpecificityAndSortOrder($active)[0];
        }
        return $result;
    }

    /**
     * @param int[] $scheduleIds Schedule IDs in order
     * @throws \Throwable
     */
    public function setSchedulesForService(int $serviceId, array $scheduleIds): bool
    {
        $result = $this->inTransaction(function() use ($serviceId, $scheduleIds) {
            ServiceScheduleAssignmentRecord::deleteAll(['serviceId' => $serviceId]);
            foreach ($scheduleIds as $sortOrder => $scheduleId) {
                $record = new ServiceScheduleAssignmentRecord();
                $record->scheduleId = (int)$scheduleId;
                $record->serviceId = $serviceId;
                $record->sortOrder = $sortOrder;
                $record->uid = StringHelper::UUID();
                if (!$record->save()) {
                    throw new \Exception("Failed to create assignment for schedule {$scheduleId}");
                }
            }
        });

        $this->clearServiceScheduleCache();

        return $result;
    }

    /** @return Schedule[] */
    public function getSchedulesForService(int $serviceId): array
    {
        $records = ServiceScheduleAssignmentRecord::find()
            ->where(['serviceId' => $serviceId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        if (empty($records)) {
            return [];
        }

        $scheduleIds = array_map(fn($r) => $r->scheduleId, $records);
        $schedules = Schedule::find()->siteId('*')->id($scheduleIds)->status(null)->indexBy('id')->all();

        return array_values(array_filter(array_map(fn($id) => $schedules[$id] ?? null, $scheduleIds)));
    }

    /**
     * Get the active schedule for a service on a specific date.
     *
     * Priority: date-specific (both dates) > semi-specific (one date) > forever,
     * then by sortOrder within each tier.
     */
    public function getActiveScheduleForServiceOnDate(int $serviceId, string $date): ?Schedule
    {
        $cacheKey = "{$serviceId}:{$date}";
        if (array_key_exists($cacheKey, $this->serviceScheduleCache)) {
            return $this->serviceScheduleCache[$cacheKey];
        }

        $records = ServiceScheduleAssignmentRecord::find()
            ->where(['serviceId' => $serviceId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        if (empty($records)) {
            $this->evictCacheIfFull();
            return $this->serviceScheduleCache[$cacheKey] = null;
        }

        $scheduleIds = array_map(fn($r) => $r->scheduleId, $records);
        $sortOrders = array_column(
            array_map(fn($r) => ['id' => $r->scheduleId, 'order' => $r->sortOrder], $records),
            'order',
            'id',
        );

        $schedules = Schedule::find()->siteId('*')->id($scheduleIds)->status(null)->indexBy('id')->all();

        $active = [];
        foreach ($scheduleIds as $id) {
            $s = $schedules[$id] ?? null;
            if ($s?->enabled && $s->isActiveOn($date)) {
                // Clone so that per-assignment sortOrder doesn't mutate the shared indexed schedule instance.
                $clone = clone $s;
                $clone->sortOrder = $sortOrders[$id] ?? PHP_INT_MAX;
                $active[] = $clone;
            }
        }

        $this->evictCacheIfFull();
        return $this->serviceScheduleCache[$cacheKey] = empty($active)
            ? null
            : self::sortByDateSpecificityAndSortOrder($active)[0];
    }

    public static function calculateDateSpecificityTier(object $schedule): int
    {
        $hasStart = $schedule->startDate !== null;
        $hasEnd = $schedule->endDate !== null;

        return match (true) {
            $hasStart && $hasEnd => 1,
            $hasStart || $hasEnd => 2,
            default => 3,
        };
    }

    /** @return Schedule[]|object[] Sorted by date specificity tier, then sortOrder */
    public static function sortByDateSpecificityAndSortOrder(array $schedules): array
    {
        usort($schedules, fn(object $a, object $b) =>
            self::calculateDateSpecificityTier($a) <=> self::calculateDateSpecificityTier($b)
            ?: ($a->sortOrder ?? PHP_INT_MAX) <=> ($b->sortOrder ?? PHP_INT_MAX));

        return $schedules;
    }

    /**
     * Execute callback in a DB transaction, returning true on success.
     *
     * @throws \Throwable Re-throws after rollback so callers can handle failures.
     */
    private function inTransaction(callable $callback): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $callback();
            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
