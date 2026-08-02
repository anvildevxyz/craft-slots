<?php

namespace anvildev\slots\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\slots\elements\BlackoutDate[]|array all($db = null)
 * @method \anvildev\slots\elements\BlackoutDate|array|null one($db = null)
 * @method \anvildev\slots\elements\BlackoutDate|array|null nth(int $n, ?Connection $db = null)
 */
class BlackoutDateQuery extends ElementQuery
{
    public ?string $startDate = null;
    public ?string $endDate = null;
    public ?bool $isActive = null;
    public array|int|null $locationId = null;
    public array|int|null $employeeId = null;

    public function startDate(?string $value): static
    {
        $this->startDate = $value;
        return $this;
    }

    public function endDate(?string $value): static
    {
        $this->endDate = $value;
        return $this;
    }

    public function isActive(?bool $value): static
    {
        $this->isActive = $value;
        return $this;
    }

    public function locationId(array|int|null $value): static
    {
        $this->locationId = $value;
        return $this;
    }

    public function employeeId(array|int|null $value): static
    {
        $this->employeeId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'slots_blackout_dates';
        $this->joinElementTable($t);

        $this->query->addSelect(["$t.startDate", "$t.endDate", "$t.isActive"]);

        foreach (['startDate', 'endDate'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$param", $this->$param));
            }
        }

        if ($this->isActive !== null) {
            $this->subQuery->andWhere(Db::parseParam("$t.isActive", (int) $this->isActive));
        }

        // Filter by location/employee via junction tables
        foreach ([
            'locationId' => ['slots_blackout_dates_locations', 'locationId', 'blackoutDateId'],
            'employeeId' => ['slots_blackout_dates_employees', 'employeeId', 'blackoutDateId'],
        ] as $prop => [$junctionTable, $fk, $pk]) {
            if ($this->$prop !== null) {
                $this->subQuery->andWhere([
                    'in', 'elements.id',
                    (new \craft\db\Query())
                        ->select([$pk])
                        ->from("{{%$junctionTable}}")
                        ->where(['in', $fk, (array)$this->$prop]),
                ]);
            }
        }

        return true;
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'active' => ['slots_blackout_dates.isActive' => true],
            'inactive' => ['slots_blackout_dates.isActive' => false],
            default => parent::statusCondition($status),
        };
    }
}
