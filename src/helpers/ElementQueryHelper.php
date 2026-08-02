<?php

namespace anvildev\slots\helpers;

use Craft;
use craft\elements\db\ElementQuery;

/**
 * Standardized site filtering for element queries.
 * Use for site-aware elements (Service) only.
 * Non-site-aware elements (Employee, Location, Reservation, BlackoutDate) don't need site filtering.
 */
class ElementQueryHelper
{
    public static function forCurrentSite(ElementQuery $query): ElementQuery
    {
        return $query->siteId(Craft::$app->getSites()->getCurrentSite()->id);
    }

    public static function forSite(ElementQuery $query, int $siteId): ElementQuery
    {
        return $query->siteId($siteId);
    }

    public static function forAllSites(ElementQuery $query): ElementQuery
    {
        return $query->siteId('*');
    }
}
