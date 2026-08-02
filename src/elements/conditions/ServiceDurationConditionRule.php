<?php

namespace anvildev\slots\elements\conditions;

use anvildev\slots\elements\Service;
use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ServiceDurationConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('slots', 'labels.duration');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['duration'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->duration($this->queryParamValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Service $element */
        return $this->matchValue($element->duration);
    }
}
