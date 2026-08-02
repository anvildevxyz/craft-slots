<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Location;
use anvildev\slots\tests\Support\TestCase;

/**
 * Location Element Test
 *
 * Tests the getAddress() formatting method and static element metadata.
 * Uses ReflectionClass to bypass Element::init().
 */
class LocationTest extends TestCase
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

    private function makeLocation(array $props = []): Location
    {
        $ref = new \ReflectionClass(Location::class);
        $location = $ref->newInstanceWithoutConstructor();

        $location->addressLine1 = $props['addressLine1'] ?? null;
        $location->addressLine2 = $props['addressLine2'] ?? null;
        $location->locality = $props['locality'] ?? null;
        $location->administrativeArea = $props['administrativeArea'] ?? null;
        $location->postalCode = $props['postalCode'] ?? null;
        $location->countryCode = $props['countryCode'] ?? null;
        $location->enabled = $props['enabled'] ?? true;

        return $location;
    }

    // =========================================================================
    // getAddress()
    // =========================================================================

    public function testGetAddressFormatsFullAddress(): void
    {
        $location = $this->makeLocation([
            'addressLine1' => 'Bahnhofstrasse 1',
            'locality' => 'Zürich',
            'postalCode' => '8001',
            'countryCode' => 'CH',
        ]);

        $this->assertEquals('Bahnhofstrasse 1, Zürich, 8001, CH', $location->getAddress());
    }

    public function testGetAddressSkipsNullParts(): void
    {
        $location = $this->makeLocation([
            'addressLine1' => 'Bahnhofstrasse 1',
            'locality' => 'Zürich',
        ]);

        $this->assertEquals('Bahnhofstrasse 1, Zürich', $location->getAddress());
    }

    public function testGetAddressSkipsEmptyParts(): void
    {
        $location = $this->makeLocation([
            'addressLine1' => 'Main St',
            'addressLine2' => '',
            'locality' => 'Bern',
        ]);

        $this->assertEquals('Main St, Bern', $location->getAddress());
    }

    public function testGetAddressReturnsEmptyWhenAllNull(): void
    {
        $location = $this->makeLocation();
        $this->assertEquals('', $location->getAddress());
    }

    public function testGetAddressIncludesAddressLine2(): void
    {
        $location = $this->makeLocation([
            'addressLine1' => 'Bahnhofstrasse 1',
            'addressLine2' => 'Suite 200',
            'locality' => 'Zürich',
        ]);

        $this->assertEquals('Bahnhofstrasse 1, Suite 200, Zürich', $location->getAddress());
    }

    // =========================================================================
    // getStatus()
    // =========================================================================

    public function testGetStatusReturnsEnabledWhenEnabled(): void
    {
        $location = $this->makeLocation(['enabled' => true]);
        $this->assertEquals('enabled', $location->getStatus());
    }

    public function testGetStatusReturnsDisabledWhenDisabled(): void
    {
        $location = $this->makeLocation(['enabled' => false]);
        $this->assertEquals('disabled', $location->getStatus());
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(Location::hasTitles());
    }

    public function testHasStatusesReturnsTrue(): void
    {
        $this->assertTrue(Location::hasStatuses());
    }

    public function testRefHandleReturnsLocation(): void
    {
        $this->assertEquals('location', Location::refHandle());
    }

    public function testCanDuplicateReturnsTrue(): void
    {
        $location = $this->makeLocation();
        $user = \Mockery::mock(\craft\elements\User::class);
        $this->assertTrue($location->canDuplicate($user));
    }
}
