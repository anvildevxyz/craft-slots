<?php

namespace anvildev\slots\helpers;

use Craft;
use craft\web\Request;
use yii\web\NotFoundHttpException;

class SiteHelper
{
    public static function getSiteForRequest(Request $request, bool $requireSiteParam = false): \craft\models\Site
    {
        $siteHandle = $request->getQueryParam('site');
        if (!$siteHandle) {
            if ($requireSiteParam) {
                throw new NotFoundHttpException('Site parameter is required but not provided');
            }

            return Craft::$app->getSites()->getCurrentSite();
        }

        // Strip any appended query parameters from handle
        $cleanHandle = strtok($siteHandle, '?');

        $site = Craft::$app->getSites()->getSiteByHandle($cleanHandle);
        if (!$site) {
            throw new NotFoundHttpException('Site not found: ' . $cleanHandle);
        }

        self::applySite($site);

        return $site;
    }

    /** Resolve site from a posted siteId form field, falling back to the current site. */
    public static function getSiteFromPost(Request $request): \craft\models\Site
    {
        $siteId = $request->getBodyParam('siteId');
        if ($siteId) {
            $site = Craft::$app->getSites()->getSiteById((int)$siteId);
            if ($site) {
                self::applySite($site);
                return $site;
            }
        }

        return Craft::$app->getSites()->getCurrentSite();
    }

    /** Set the current site and application language. */
    private static function applySite(\craft\models\Site $site): void
    {
        Craft::$app->getSites()->setCurrentSite($site);
        Craft::$app->language = $site->language;
    }
}
