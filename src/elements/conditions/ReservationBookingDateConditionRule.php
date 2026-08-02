<?php

namespace anvildev\slots\elements\conditions;

use anvildev\slots\elements\Reservation;
use Craft;
use craft\base\conditions\BaseDateRangeConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ReservationBookingDateConditionRule extends BaseDateRangeConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('slots', 'labels.bookingDate');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['bookingDate'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->bookingDate($this->queryParamValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Reservation $element */
        $date = $element->bookingDate ? new \DateTime($element->bookingDate) : null;

        return $this->matchValue($date);
    }
}
