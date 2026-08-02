<?php

namespace anvildev\slots\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\slots\elements\Service[]|array all($db = null)
 * @method \anvildev\slots\elements\Service|array|null one($db = null)
 * @method \anvildev\slots\elements\Service|array|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ServiceQuery extends ElementQuery
{
    public ?int $duration = null;
    public ?float $price = null;
    public ?bool $enabled = null;
    public bool $withTrashed = false;
    public array|int|null $locationId = null;

    public function duration(?int $value): static
    {
        $this->duration = $value;
        return $this;
    }

    public function price(?float $value): static
    {
        $this->price = $value;
        return $this;
    }

    public function enabled(?bool $value = true): static
    {
        $this->enabled = $value;
        return $this;
    }

    public function withTrashed(bool $value = true): static
    {
        $this->withTrashed = $value;
        return $this;
    }

    public function locationId(array|int|null $value): static
    {
        $this->locationId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'slots_services';
        $this->joinElementTable($t);
        $this->subQuery->andWhere(['is not', "$t.id", null]);

        $this->query->addSelect([
            "$t.propagationMethod", "$t.description", "$t.duration", "$t.bufferBefore", "$t.bufferAfter",
            "$t.price", "$t.allowCancellation", "$t.cancellationPolicyHours", "$t.allowRefund", "$t.refundTiers", "$t.minTimeBeforeBooking",
            "$t.timeSlotLength", "$t.availabilitySchedule",
            "$t.customerLimitEnabled", "$t.customerLimitCount",
            "$t.customerLimitPeriod", "$t.customerLimitPeriodType",
            "$t.taxCategoryId", "$t.deletedAt",
        ]);

        foreach (['duration', 'price'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$param", $this->$param));
            }
        }

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('elements.enabled', (int)$this->enabled));
        }

        if (!$this->withTrashed) {
            $this->subQuery->andWhere(["$t.deletedAt" => null]);
        }

        if ($this->locationId !== null) {
            $this->subQuery->andWhere([
                'in', 'elements.id',
                (new \craft\db\Query())
                    ->select(['serviceId'])
                    ->from('{{%slots_service_locations}}')
                    ->where(['in', 'locationId', (array) $this->locationId]),
            ]);
        }

        return true;
    }
}
