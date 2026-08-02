<?php

namespace anvildev\slots\contracts;

/**
 * Query contract for reservations in both Element and ActiveRecord modes.
 */
interface ReservationQueryInterface
{
    /** @param int|int[]|null $value */
    public function id($value): static;

    /** @param mixed $value Site ID, '*' for all sites, or null */
    public function siteId($value): static;

    public function userName(?string $value): static;
    public function userEmail(?string $value): static;
    public function userId(?int $value): static;

    /** @param string|array|null $value Single date, range array, or comparison */
    public function bookingDate(array|string|null $value): static;

    public function startTime(?string $value): static;
    public function endTime(?string $value): static;
    public function employeeId(?int $value): static;
    public function locationId(?int $value): static;
    public function serviceId(?int $value): static;

    /** @param string|string[]|null $value */
    public function status(array|string|null $value): static;

    /** @param string|string[]|null $value */
    public function reservationStatus(array|string|null $value): static;

    public function confirmationToken(?string $value): static;
    public function forCurrentUser(): static;
    public function withEmployee(): static;
    public function withService(): static;
    public function withLocation(): static;
    public function withRelations(): static;

    // Signatures match Yii QueryTrait (no return type declarations)
    public function orderBy($columns);
    public function limit($limit);
    public function offset($offset);
    public function one($db = null);
    public function all($db = null);
    public function sum($q, $db = null);
    public function count($q = '*', $db = null);
    public function exists($db = null);
    public function each($batchSize = 100, $db = null);

    /** @return int[] */
    public function ids(?\yii\db\Connection $db = null): array;

    public function where($condition, $params = []);
    public function andWhere($condition, $params = []);
}
