<?php

namespace anvildev\slots\elements\conditions;

use anvildev\slots\elements\Reservation;
use anvildev\slots\elements\Service;
use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ReservationServiceConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('slots', 'labels.service');
    }

    protected function elementType(): string
    {
        return Service::class;
    }

    public function getExclusiveQueryParams(): array
    {
        return ['serviceId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->serviceId($this->getElementIds());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Reservation $element */
        return $this->matchValue($element->serviceId);
    }
}
