<?php

namespace anvildev\slots\elements\conditions;

use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Reservation;
use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ReservationEmployeeConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('slots', 'labels.employee');
    }

    protected function elementType(): string
    {
        return Employee::class;
    }

    public function getExclusiveQueryParams(): array
    {
        return ['employeeId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->employeeId($this->getElementIds());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Reservation $element */
        return $this->matchValue($element->employeeId);
    }
}
