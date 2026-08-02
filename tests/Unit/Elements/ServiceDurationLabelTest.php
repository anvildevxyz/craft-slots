<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Service;
use anvildev\slots\tests\Support\TestCase;

/**
 * Replaces ServicePricingModeTest, which tested a setting that no longer exists.
 *
 * `pricingMode` offered a flat price or a price per unit, where the unit was a
 * day — meaningful only alongside multi-day stays. Those went with the
 * strip-down, nothing was left to multiply by, and getTotalPrice() had always
 * ignored the setting. Duration labelling is the part of that file worth keeping.
 */
class ServiceDurationLabelTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    private function makeService(?int $duration): Service
    {
        $service = (new \ReflectionClass(Service::class))->newInstanceWithoutConstructor();
        $service->duration = $duration;

        return $service;
    }

    public function testTheLabelCarriesTheDuration(): void
    {
        $this->assertStringContainsString('60', $this->makeService(60)->getDurationLabel());
    }

    /**
     * A service with no duration set has nothing to label — an empty string
     * rather than a bare unit with no number in front of it.
     */
    public function testAServiceWithoutADurationHasNoLabel(): void
    {
        $this->assertSame('', $this->makeService(null)->getDurationLabel());
    }

    public function testTheServiceNoLongerCarriesAPricingMode(): void
    {
        $this->assertFalse(
            property_exists(Service::class, 'pricingMode'),
            'pricingMode was removed: nothing computed a price from it',
        );
    }
}
