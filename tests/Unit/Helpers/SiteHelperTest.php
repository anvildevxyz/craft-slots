<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\helpers\SiteHelper;
use anvildev\slots\tests\Support\TestCase;

/**
 * SiteHelper Test
 *
 * All methods depend on Craft::$app->getSites() and Request.
 * Structure test only; functional tests require integration environment.
 */
class SiteHelperTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(SiteHelper::class));
    }

    public function testGetSiteForRequestMethodExists(): void
    {
        $this->assertTrue(method_exists(SiteHelper::class, 'getSiteForRequest'));
    }
}
