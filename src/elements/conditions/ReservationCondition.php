<?php

namespace anvildev\slots\elements\conditions;

use craft\elements\conditions\ElementCondition;

class ReservationCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            ReservationServiceConditionRule::class,
            ReservationEmployeeConditionRule::class,
            ReservationLocationConditionRule::class,
            ReservationBookingDateConditionRule::class,
        ]);
    }
}
