<?php

namespace anvildev\slots\traits;

use anvildev\slots\contracts\ReservationInterface;
use Craft;

/**
 * Provides language-aware rendering helpers for notification services.
 *
 * Resolves the target language from a reservation's site and temporarily
 * switches Craft::$app->language for the duration of a callback.
 */
trait RendersInLanguage
{
    /**
     * Resolve the language for a given site ID, falling back to the primary site's language.
     */
    protected function getLanguageForSiteId(?int $siteId): string
    {
        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId) : null;
        return $site?->language ?? Craft::$app->getSites()->getPrimarySite()->language;
    }

    /**
     * Resolve the target language from a reservation's site.
     */
    protected function getReservationLanguage(ReservationInterface $reservation): string
    {
        return $this->getLanguageForSiteId($reservation->getSiteId());
    }

    /**
     * Execute a callback with Craft's language temporarily switched.
     */
    protected function renderWithLanguage(callable $callback, string $language): string
    {
        $originalLanguage = Craft::$app->language;
        $formatter = Craft::$app->getFormatter();
        $originalLocale = $formatter->locale;
        try {
            Craft::$app->language = $language;
            $formatter->locale = $language;
            return $callback();
        } finally {
            Craft::$app->language = $originalLanguage;
            $formatter->locale = $originalLocale;
        }
    }
}
