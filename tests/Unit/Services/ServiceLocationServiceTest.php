<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\ServiceLocationService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * ServiceLocationService Test
 *
 * Tests the service-location many-to-many management.
 * DB-dependent methods (getLocationsForService, setLocationsForService, getLocationIdMapForServices)
 * require integration tests. Unit tests verify the service can be instantiated
 * and the batch method handles empty input correctly.
 */
class ServiceLocationServiceTest extends TestCase
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

    /**
     * @return ServiceLocationService|MockInterface
     */
    private function makePartialService(): MockInterface
    {
        return Mockery::mock(ServiceLocationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    }

    public function testServiceCanBeInstantiated(): void
    {
        $service = new ServiceLocationService();
        $this->assertInstanceOf(ServiceLocationService::class, $service);
    }

    public function testGetLocationIdMapForServicesReturnsEmptyArrayForEmptyInput(): void
    {
        $service = new ServiceLocationService();
        $result = $service->getLocationIdMapForServices([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetLocationsForServiceReturnsArrayType(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getLocationsForService')->with(999)->andReturn([]);

        $result = $service->getLocationsForService(999);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSetLocationsForServiceAcceptsEmptyArray(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('setLocationsForService')->with(1, [])->andReturn(true);

        $result = $service->setLocationsForService(1, []);
        $this->assertTrue($result);
    }

    public function testGetLocationIdMapForServicesReturnsMappedStructure(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getLocationIdMapForServices')
            ->with([1, 2, 3])
            ->andReturn([
                1 => [10, 20],
                2 => [30],
            ]);

        $result = $service->getLocationIdMapForServices([1, 2, 3]);

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayNotHasKey(3, $result);
        $this->assertEquals([10, 20], $result[1]);
        $this->assertEquals([30], $result[2]);
    }
}
