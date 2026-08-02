<?php

namespace anvildev\slots\elements\db;

use Craft;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use yii\db\Expression;

/**
 * @method \anvildev\slots\elements\Employee[]|array all($db = null)
 * @method \anvildev\slots\elements\Employee|array|null one($db = null)
 * @method \anvildev\slots\elements\Employee|array|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class EmployeeQuery extends ElementQuery
{
    public ?int $userId = null;
    public ?int $locationId = null;
    public ?int $serviceId = null;
    public ?bool $enabled = null;

    public function userId(?int $value): static
    {
        $this->userId = $value;
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

        $t = 'slots_employees';
        $this->joinElementTable($t);

        $this->query->addSelect([
            "$t.userId", "$t.locationId", "$t.email", "$t.workingHours", "$t.serviceIds",
        ]);

        foreach (['userId', 'locationId'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$param", $this->$param));
            }
        }

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('elements.enabled', (int)$this->enabled));
        }

        if ($this->serviceId !== null) {
            $id = (int)$this->serviceId;
            $db = Craft::$app->getDb();

            if ($db->getIsPgsql()) {
                $this->subQuery->andWhere(
                    new Expression(
                        '[[slots_employees.serviceIds]]::jsonb @> :serviceId::jsonb',
                        [':serviceId' => json_encode([$id])],
                    )
                );
            } else {
                $this->subQuery->andWhere(
                    new Expression(
                        'JSON_CONTAINS([[slots_employees.serviceIds]], :serviceId)',
                        [':serviceId' => json_encode($id)],
                    )
                );
            }
        }

        return true;
    }
}
