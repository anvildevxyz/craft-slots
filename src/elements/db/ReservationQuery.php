<?php

namespace anvildev\slots\elements\db;

use anvildev\slots\contracts\ReservationQueryInterface;
use anvildev\slots\Slots;
use Craft;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\slots\elements\Reservation[]|array all($db = null)
 * @method \anvildev\slots\elements\Reservation|array|null one($db = null)
 * @method \anvildev\slots\elements\Reservation|array|null nth(int $n, ?Connection $db = null)
 */
class ReservationQuery extends ElementQuery implements ReservationQueryInterface
{
    public function init(): void
    {
        parent::init();
        // Return all reservation statuses (confirmed, pending, cancelled) by default,
        // rather than relying on Craft's default 'enabled' element status.
        $this->status = null;
    }

    public ?string $userName = null;
    public ?string $userEmail = null;
    public ?string $userPhone = null;
    public ?int $userId = null;
    public array|string|null $bookingDate = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public array|string|null $reservationStatus = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public ?int $serviceId = null;
    public ?string $confirmationToken = null;

    private bool $_forCurrentUser = false;

    public function employeeId(?int $value): static
    {
        $this->employeeId = $value;
        return $this;
    }

    public function locationId(?int $value): static
    {
        $this->locationId = $value;
        return $this;
    }

    public function serviceId(?int $value): static
    {
        $this->serviceId = $value;
        return $this;
    }

    public function userName(?string $value): static
    {
        $this->userName = $value;
        return $this;
    }

    public function userEmail(?string $value): static
    {
        $this->userEmail = $value;
        return $this;
    }

    public function userPhone(?string $value): static
    {
        $this->userPhone = $value;
        return $this;
    }

    public function userId(?int $value): static
    {
        $this->userId = $value;
        return $this;
    }

    /**
     * Filter by the currently logged-in user (by userId or email fallback).
     * If no user is logged in, returns no results.
     */
    public function forCurrentUser(): static
    {
        $this->_forCurrentUser = true;
        return $this;
    }

    public function bookingDate(array|string|null $value): static
    {
        $this->bookingDate = $value;
        return $this;
    }

    public function startTime(?string $value): static
    {
        $this->startTime = $value;
        return $this;
    }

    public function endTime(?string $value): static
    {
        $this->endTime = $value;
        return $this;
    }

    public function reservationStatus(array|string|null $value): static
    {
        $this->reservationStatus = $value;
        return $this;
    }

    public function confirmationToken(?string $value): static
    {
        $this->confirmationToken = $value;
        return $this;
    }

    public function withEmployee(): static
    {
        $this->with(['employee']);
        return $this;
    }

    public function withService(): static
    {
        $this->with(['service']);
        return $this;
    }

    public function withLocation(): static
    {
        $this->with(['location']);
        return $this;
    }

    public function withRelations(): static
    {
        $this->with(['employee', 'service', 'location']);
        return $this;
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'confirmed', 'pending', 'cancelled', 'no_show' => ['slots_reservations.status' => $status],
            default => parent::statusCondition($status),
        };
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'slots_reservations';
        $this->joinElementTable($t);

        // Prevent ID collisions with other element types when AR mode IDs overlap
        $this->subQuery->andWhere(['elements.type' => $this->elementType]);

        $this->query->addSelect([
            "$t.userName", "$t.userEmail", "$t.userPhone", "$t.userId",
            "$t.userTimezone", "$t.bookingDate", "$t.startTime", "$t.endTime",
            "$t.status", "$t.notes", "$t.sessionNotes", "$t.notificationSent", "$t.confirmationToken",
            "$t.employeeId", "$t.locationId", "$t.serviceId", "$t.quantity",
            "$t.emailReminder24hSent", "$t.emailReminder1hSent",
            "$t.siteId AS bookingSiteId",
        ]);

        // Simple param filters (string keys map property name => column name)
        foreach (['userName', 'userEmail', 'userPhone', 'userId', 'startTime', 'endTime', 'reservationStatus' => 'status', 'employeeId', 'locationId', 'serviceId'] as $key => $col) {
            $prop = is_int($key) ? $col : $key;
            if ($this->$prop !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$col", $this->$prop));
            }
        }

        if ($this->confirmationToken !== null) {
            $this->subQuery->andWhere(Db::parseParam("$t.confirmationToken", $this->confirmationToken));
        }

        // Current user: match by userId OR email (legacy bookings)
        if ($this->_forCurrentUser) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            $this->subQuery->andWhere($currentUser
                ? ['or', ["$t.userId" => $currentUser->id], ["$t.userEmail" => $currentUser->email]]
                : '1 = 0'
            );
        }

        // Booking date supports operator pairs, 'and' compound conditions, and simple values
        if ($this->bookingDate) {
            $this->_applyBookingDateFilter($t);
        }

        $this->_applyStaffScope($t);

        return true;
    }

    /**
     * Limits a staff member to the employees they manage.
     *
     * Callers apply this explicitly too, but it has to live here as well: the
     * native element index builds its own query inside Craft's controller, so a
     * filter applied by our controllers simply never runs for it. Authorisation
     * that only holds when the caller remembers is not authorisation.
     *
     * This is a no-op for everyone else — getStaffEmployeeIds() returns null for
     * admins, for anyone who can manage bookings, and when nobody is logged in.
     */
    private function _applyStaffScope(string $t): void
    {
        // Console and queue runs have no user session to scope against.
        if (!Craft::$app instanceof \craft\web\Application) {
            return;
        }

        $ids = Slots::getInstance()?->getPermission()->getStaffEmployeeIds();
        if ($ids === null) {
            return;
        }

        // An empty managed list means "no bookings", stated explicitly rather
        // than left to how a driver handles an empty IN().
        $this->subQuery->andWhere($ids === [] ? '0=1' : ["$t.employeeId" => $ids]);
    }

    private function _applyBookingDateFilter(string $t): void
    {
        if (!is_array($this->bookingDate)) {
            $this->subQuery->andWhere(Db::parseParam("$t.bookingDate", $this->bookingDate));
            return;
        }

        $bd = $this->bookingDate;

        // Operator format: ['>', '2024-01-01']
        if (count($bd) === 2 && is_string($bd[0]) && in_array($bd[0], ['>', '<', '>=', '<=', '!=', '<>'])) {
            $this->subQuery->andWhere([$bd[0], "$t.bookingDate", $bd[1]]);
            return;
        }

        // Compound: ['and', '>= 2026-02-01', '<= 2026-02-28']
        if (($bd[0] ?? null) === 'and') {
            $conditions = ['and'];
            foreach (array_slice($bd, 1) as $condition) {
                $conditions[] = Db::parseParam("$t.bookingDate", $condition);
            }
            $this->subQuery->andWhere($conditions);
            return;
        }

        $this->subQuery->andWhere(Db::parseParam("$t.bookingDate", $bd));
    }
}
