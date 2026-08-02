<?php

namespace anvildev\slots\elements\conditions;

use anvildev\slots\elements\Location;
use anvildev\slots\elements\Reservation;
use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ReservationLocationConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('slots', 'labels.location');
    }

    protected function elementType(): string
    {
        return Location::class;
    }

    public function getExclusiveQueryParams(): array
    {
        return ['locationId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->locationId($this->getElementIds());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Reservation $element */
        return $this->matchValue($element->locationId);
    }
}
