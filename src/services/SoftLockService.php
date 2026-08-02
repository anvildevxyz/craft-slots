<?php

namespace anvildev\slots\services;

use anvildev\slots\records\SoftLockRecord;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use yii\db\ActiveQuery;

/**
 * Manages temporary slot reservations (soft locks) to prevent race conditions.
 *
 * When a customer begins the booking flow, a time-limited lock is placed on the
 * slot. This prevents double-booking when multiple users attempt to reserve the
 * same time concurrently. Locks expire automatically and are garbage-collected
 * on each new lock creation.
 */
class SoftLockService extends Component
{
    /** @return string|false Token if successful */
    public function createLock(array $data, int $durationMinutes = 5): string|false
    {
        if (empty($data['date']) || (empty($data['startTime']) && empty($data['endDate'])) || !isset($data['serviceId'])) {
            return false;
        }

        $this->deleteExpiredRecords();

        $employeeId = $data['employeeId'] ?? null;
        $quantity = max(1, (int)($data['quantity'] ?? 1));
        $capacity = isset($data['capacity']) ? (int)$data['capacity'] : null;
        $endDate = $data['endDate'] ?? null;

        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            $siteId = 1;
        }
        $lockKey = 'slots-softlock-' . $siteId . '-' . $data['date'] . '-' . ($endDate ?? $data['startTime']) . '-' . $data['serviceId'] . '-' . ($employeeId ?? 'any');
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 5)) {
            return false;
        }

        try {
            // For multi-day locks, check date-range overlap instead of time-based overlap
            if ($endDate) {
                $isAlreadyLocked = $this->isDateRangeLocked(
                    $data['date'], $endDate, $data['serviceId'],
                    $employeeId, $data['locationId'] ?? null, $quantity, $capacity
                );
            } else {
                $isAlreadyLocked = $this->isLocked(
                    $data['date'], $data['startTime'], $data['serviceId'],
                    $employeeId, $data['endTime'] ?? null, $data['locationId'] ?? null,
                    null, $quantity, $capacity
                );
            }
            if ($isAlreadyLocked) {
                return false;
            }

            $token = bin2hex(random_bytes(16));
            $expiresAt = new DateTime('now', new DateTimeZone('UTC'));
            $expiresAt->modify("+{$durationMinutes} minutes");

            $record = $this->createRecord();
            $record->token = $token;
            $record->sessionHash = $this->getSessionHash();
            $record->serviceId = $data['serviceId'];
            $record->employeeId = $employeeId;
            $record->locationId = $data['locationId'] ?? null;
            $record->date = $data['date'];
            $record->startTime = $data['startTime'];
            $record->endTime = $data['endTime'] ?? null;
            $record->endDate = $endDate;
            $record->quantity = $quantity;
            $record->expiresAt = Db::prepareDateForDb($expiresAt);

            return $this->saveRecord($record) ? $token : false;
        } finally {
            $mutex->release($lockKey);
        }
    }

    public function isLocked(string $date, string $startTime, int $serviceId, ?int $employeeId = null, ?string $slotEndTime = null, ?int $locationId = null, ?string $excludeToken = null, int $quantity = 1, ?int $capacity = null): bool
    {
        $query = $this->buildLockQuery($date, $startTime, $serviceId, $employeeId, $slotEndTime, $locationId);

        if ($excludeToken !== null && $excludeToken !== '') {
            $query->andWhere(['!=', 'token', $excludeToken]);
        }

        // When capacity is provided, compare total held quantity against it
        if ($capacity !== null) {
            $heldQuantity = (int)$query->sum('quantity');
            $isLocked = ($heldQuantity + $quantity) > $capacity;
        } else {
            $isLocked = $query->exists();
        }

        if ($isLocked && $excludeToken && Craft::$app->getConfig()->getGeneral()->devMode) {
            $debugQuery = $this->buildLockQuery($date, $startTime, $serviceId, $employeeId, $slotEndTime, $locationId);

            $allLocks = $debugQuery->all();
            $lockInfo = array_map(
                fn($lock) => "lock_id=" . substr(hash('sha256', $lock->token), 0, 8) . " time={$lock->startTime}-{$lock->endTime} location={$lock->locationId} qty={$lock->quantity}",
                $allLocks,
            );
            Craft::debug("Slot is locked even after excluding lock_id=" . substr(hash('sha256', $excludeToken), 0, 8) . ". Existing locks: " . implode(', ', $lockInfo), __METHOD__);
        }

        return $isLocked;
    }

    /** @return SoftLockRecord[] */
    public function getActiveSoftLocksForDate(string $date, int $serviceId, ?string $excludeToken = null): array
    {
        $query = $this->getRecordQuery()
            ->where(['date' => $date, 'serviceId' => $serviceId])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);

        if ($excludeToken !== null && $excludeToken !== '') {
            $query->andWhere(['!=', 'token', $excludeToken]);
        }

        return $query->all();
    }

    public function releaseLock(string $token, ?string $sessionHash = null): bool
    {
        $record = $this->getRecordByToken($token);
        if (!$record) {
            return false;
        }

        // If the lock has a session hash, verify it matches
        if ($record->sessionHash && !hash_equals($record->sessionHash, $sessionHash ?? '')) {
            Craft::warning("Soft lock release denied: session mismatch for token (lock_id=" . substr(hash('sha256', $token), 0, 8) . ")", __METHOD__);
            return false;
        }

        return (bool)$this->deleteRecord($record);
    }

    /**
     * Extend a held lock, re-issuing its TTL from now — up to a hard lifetime ceiling.
     *
     * Verifies the session hash (like {@see releaseLock()}) and refuses to
     * resurrect a lock that is missing or already expired. Crucially, the new
     * expiry is clamped to the lock's creation time plus $maxLifetimeMinutes, so
     * a client can renew while it finishes checkout but can never hold a slot
     * indefinitely and starve real bookings. Returns the new UTC expiry on success.
     *
     * @param string $token
     * @param int $durationMinutes
     * @param string|null $sessionHash
     * @param int $maxLifetimeMinutes Hard ceiling on total lifetime, measured from the lock's creation
     * @return DateTime|false New expiry on success, false if the lock is gone, expired, capped out, or the session mismatches
     */
    public function extendLock(string $token, int $durationMinutes = 5, ?string $sessionHash = null, int $maxLifetimeMinutes = 30): DateTime|false
    {
        $record = $this->getRecordByToken($token);
        if (!$record) {
            return false;
        }

        if ($record->sessionHash && !hash_equals($record->sessionHash, $sessionHash ?? '')) {
            Craft::warning("Soft lock extend denied: session mismatch for token (lock_id=" . substr(hash('sha256', $token), 0, 8) . ")", __METHOD__);
            return false;
        }

        // Refuse to resurrect an already-expired lock — the slot may be gone.
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $currentExpiry = new DateTime((string)$record->expiresAt, new DateTimeZone('UTC'));
        if ($currentExpiry <= $now) {
            return false;
        }

        // Hard ceiling: a lock can be renewed, but never past creation + max lifetime.
        // Once the ceiling is reached the client must re-acquire, which re-checks
        // availability — so nobody can sit on a slot forever by extending on a timer.
        $maxExpiry = (new DateTime((string)$record->dateCreated, new DateTimeZone('UTC')))
            ->modify("+{$maxLifetimeMinutes} minutes");
        if ($maxExpiry <= $now) {
            Craft::info("Soft lock extend denied: lifetime ceiling reached (lock_id=" . substr(hash('sha256', $token), 0, 8) . ")", __METHOD__);
            return false;
        }

        $newExpiry = (clone $now)->modify("+{$durationMinutes} minutes");
        if ($newExpiry > $maxExpiry) {
            $newExpiry = $maxExpiry;
        }
        $record->expiresAt = Db::prepareDateForDb($newExpiry);

        return $this->saveRecord($record) ? $newExpiry : false;
    }

    public function cleanupExpiredLocks(): int
    {
        return $this->deleteExpiredRecords();
    }

    public function countExpiredLocks(): int
    {
        return (int)$this->getRecordQuery()
            ->where(['<=', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))])
            ->count();
    }

    public function getSessionHash(): string
    {
        $request = Craft::$app->getRequest();
        $sessionId = !$request->getIsConsoleRequest()
            ? (Craft::$app->getSession()->getId() ?: '')
            : 'console_' . getmypid();
        try {
            $salt = Craft::$app->getConfig()->getGeneral()->securityKey ?? '';
        } catch (\Throwable) {
            $salt = '';
        }
        return hash('sha256', $salt . '|' . $sessionId);
    }

    private function buildLockQuery(string $date, string $startTime, int $serviceId, ?int $employeeId, ?string $endTime, ?int $locationId): ActiveQuery
    {
        $query = $this->getRecordQuery()
            ->where(['date' => $date, 'serviceId' => $serviceId])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);

        if ($employeeId !== null) {
            $query->andWhere(['or', ['employeeId' => $employeeId], ['employeeId' => null]]);
        }
        // When employeeId is null (any available), do NOT filter by employeeId
        // so we check ALL locks (employee-specific and employee-less) for this slot
        if ($locationId !== null) {
            $query->andWhere(['locationId' => $locationId]);
        }

        if ($endTime !== null) {
            // Overlap detection: match locks that overlap the requested time range.
            // Also match locks with null endTime that share the same startTime.
            $query->andWhere(['or',
                ['and', ['<', 'startTime', $endTime], ['>', 'endTime', $startTime]],
                ['and', ['endTime' => null], ['startTime' => $startTime]],
            ]);
        } else {
            $query->andWhere(['startTime' => $startTime]);
        }

        return $query;
    }

    public function isDateRangeLocked(string $startDate, string $endDate, int $serviceId, ?int $employeeId, ?int $locationId, int $quantity = 1, ?int $capacity = null, ?string $excludeToken = null): bool
    {
        $query = $this->getRecordQuery()
            ->where(['serviceId' => $serviceId])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))])
            ->andWhere(['not', ['endDate' => null]])
            ->andWhere(['<=', 'date', $endDate])
            ->andWhere(['>=', 'endDate', $startDate]);

        if ($employeeId !== null) {
            $query->andWhere(['or', ['employeeId' => $employeeId], ['employeeId' => null]]);
        }
        if ($locationId !== null) {
            $query->andWhere(['locationId' => $locationId]);
        }
        if ($excludeToken !== null) {
            $query->andWhere(['not', ['token' => $excludeToken]]);
        }

        if ($capacity !== null) {
            $heldQuantity = (int)$query->sum('quantity');
            return ($heldQuantity + $quantity) > $capacity;
        }

        return $query->exists();
    }

    protected function createRecord()
    {
        return new SoftLockRecord();
    }

    protected function getRecordQuery(): ActiveQuery
    {
        return SoftLockRecord::find();
    }

    /** @return SoftLockRecord|null */
    public function getRecordByToken(string $token)
    {
        return SoftLockRecord::findOne(['token' => $token]);
    }

    protected function saveRecord($record): bool
    {
        return $record->save();
    }

    protected function deleteRecord($record): int
    {
        return $record->delete();
    }

    protected function deleteExpiredRecords(): int
    {
        return SoftLockRecord::deleteAll(['<=', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);
    }

    protected function getMutex(): \yii\mutex\Mutex
    {
        return Slots::getInstance()->mutex->get();
    }
}
