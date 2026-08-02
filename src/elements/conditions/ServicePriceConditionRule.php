<?php

namespace anvildev\slots\elements\conditions;

use anvildev\slots\elements\Service;
use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ServicePriceConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    public int|float|null $step = 0.01;

    public function getLabel(): string
    {
        return Craft::t('slots', 'labels.price');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['price'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->price($this->queryParamValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Service $element */
        return $this->matchValue($element->price);
    }
}
