<?php

namespace anvildev\slots\traits;

use anvildev\slots\helpers\DateHelper;
use Craft;

trait ValidatesTimeRange
{
    public function validateTimeRange(): void
    {
        if (!$this->startTime || !$this->endTime) {
            return;
        }

        $start = DateHelper::parseTime($this->startTime);
        $end = DateHelper::parseTime($this->endTime);
        if (!$start || !$end) {
            return;
        }

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            $this->addError('endTime', Craft::t('slots', 'validation.endTimeAfterStart'));
        }
    }
}
