<?php

namespace anvildev\slots\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\slots\elements\Schedule[]|array all($db = null)
 * @method \anvildev\slots\elements\Schedule|array|null one($db = null)
 * @method \anvildev\slots\elements\Schedule|array|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ScheduleQuery extends ElementQuery
{
    public ?int $employeeId = null;
    /** @var string|null Date in Y-m-d format */
    public ?string $activeOn = null;
    public ?bool $enabled = null;

    public function employeeId(?int $value): static
    {
        $this->employeeId = $value;
        return $this;
    }

    public function activeOn(string|\DateTime|null $value): static
    {
        $this->activeOn = $value instanceof \DateTime ? $value->format('Y-m-d') : $value;
        return $this;
    }

    public function enabled(?bool $value = true): static
    {
        $this->enabled = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'slots_schedules';
        $this->joinElementTable($t);

        $this->query->addSelect(["$t.workingHours", "$t.startDate", "$t.endDate"]);

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('elements.enabled', (int)$this->enabled));
        }

        if ($this->employeeId !== null) {
            $joinOn = '[[assignments.scheduleId]] = [[slots_schedules.id]]';
            $this->subQuery->innerJoin('{{%slots_employee_schedule_assignments}} assignments', $joinOn);
            $this->subQuery->andWhere(Db::parseParam('assignments.employeeId', $this->employeeId));

            $this->query->innerJoin(
                '{{%slots_employee_schedule_assignments}} assignments',
                "$joinOn AND [[assignments.employeeId]] = :empId",
                [':empId' => $this->employeeId]
            );
            $this->query->addSelect(['assignments.sortOrder']);

            // Order by date specificity: both dates > one date > neither
            $this->subQuery->orderBy([
                new \yii\db\Expression('CASE
                    WHEN [[slots_schedules.startDate]] IS NOT NULL AND [[slots_schedules.endDate]] IS NOT NULL THEN 1
                    WHEN [[slots_schedules.startDate]] IS NOT NULL OR [[slots_schedules.endDate]] IS NOT NULL THEN 2
                    ELSE 3
                END'),
                'assignments.sortOrder' => SORT_ASC,
            ]);
        }

        if ($this->activeOn !== null) {
            $this->subQuery->andWhere(['or', ["$t.startDate" => null], ['<=', "$t.startDate", $this->activeOn]]);
            $this->subQuery->andWhere(['or', ["$t.endDate" => null], ['>=', "$t.endDate", $this->activeOn]]);
        }

        return true;
    }
}
