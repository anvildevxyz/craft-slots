<?php

namespace anvildev\slots\traits;

use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\ArrayHelper;

/**
 * Shared multi-site propagation support for localized elements.
 *
 * Provides the $propagationMethod property and getSupportedSites() implementation.
 * Used by Service elements.
 */
trait HasPropagation
{
    public PropagationMethod $propagationMethod = PropagationMethod::None;

    public function getSupportedSites(): array
    {
        $sites = Craft::$app->getSites();
        $currentSite = fn() => $sites->getSiteById($this->siteId) ?? $sites->getPrimarySite();
        return match ($this->propagationMethod) {
            PropagationMethod::All => ArrayHelper::getColumn($sites->getAllSites(), 'id'),
            PropagationMethod::SiteGroup => ArrayHelper::getColumn($sites->getSitesByGroupId($currentSite()->groupId), 'id'),
            PropagationMethod::Language => ArrayHelper::getColumn(
                array_filter($sites->getAllSites(), fn($s) => $s->language === $currentSite()->language), 'id'
            ),
            default => [$this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id],
        };
    }
}
