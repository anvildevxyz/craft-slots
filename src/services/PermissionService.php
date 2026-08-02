<?php

namespace anvildev\slots\services;

use anvildev\slots\contracts\ReservationQueryInterface;
use anvildev\slots\elements\Employee;
use Craft;
use craft\base\Component;

/**
 * Centralizes permission logic for the Slots plugin.
 *
 * Provides employee lookup for staff scoping, and query-level
 * filtering so staff members see only their own bookings.
 * Source: the employee the user IS (userId 1:1).
 */
class PermissionService extends Component
{
    /**
     * Request-scoped cache of employees by user ID.
     *
     * This cache lives only for the duration of a single HTTP request (or console command).
     * It is NOT persisted across requests since it is stored as an in-memory property.
     * For long-lived processes (e.g. queue workers handling multiple jobs), call
     * clearCache() between jobs to prevent stale data.
     *
     * @var array<int, Employee[]>
     */
    private array $employeesCache = [];

    /** @return Employee[] */
    public function getEmployeesForCurrentUser(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        return $user ? $this->getEmployeesForUser($user->id) : [];
    }

    /** @return Employee[] */
    public function getEmployeesForUser(int $userId): array
    {
        if (!isset($this->employeesCache[$userId])) {
            $ownEmployee = Employee::find()->siteId('*')->userId($userId)->one();
            $employees = [];

            if ($ownEmployee) {
                $employees[$ownEmployee->id] = $ownEmployee;
            }

            $this->employeesCache[$userId] = array_values($employees);
        }

        return $this->employeesCache[$userId];
    }

    /**
     * Clear the employees cache. Useful in long-lived processes (e.g. queue workers)
     * where the cache may become stale across different user contexts.
     */
    public function clearCache(): void
    {
        $this->employeesCache = [];
    }

    /**
     * Staff = has viewBookings but NOT manageBookings, and is linked to an Employee.
     *
     * @api Public developer API — available for plugin extensions and custom modules.
     */
    public function isStaffMember(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        return $user
            && !$user->admin
            && $user->can('slots-viewBookings')
            && !$user->can('slots-manageBookings')
            && count($this->getEmployeesForCurrentUser()) > 0;
    }

    /** @return int[]|null Null means full access. */
    public function getStaffEmployeeIds(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user
            || $user->admin
            || !$user->can('slots-viewBookings')
            || $user->can('slots-manageBookings')
        ) {
            return null;
        }

        $employees = $this->getEmployeesForCurrentUser();

        return count($employees) > 0
            ? array_map(fn(Employee $e) => $e->id, $employees)
            : [];
    }

    /**
     * Scope a ReservationQuery or ReservationModelQuery to the current user's employees.
     *
     * @template T of ReservationQueryInterface
     * @param T $query
     * @return T
     */
    public function scopeReservationQuery(ReservationQueryInterface $query): ReservationQueryInterface
    {
        $ids = $this->getStaffEmployeeIds();
        if ($ids === null) {
            return $query;
        }

        if (empty($ids)) {
            // Empty managed employee list — return no results rather than relying on
            // Yii's handling of empty IN() which varies by DB driver.
            $query->andWhere('0=1');
            return $query;
        }

        $query->andWhere(['employeeId' => $ids]);

        return $query;
    }
}
