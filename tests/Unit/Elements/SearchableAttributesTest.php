<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\BlackoutDate;
use anvildev\slots\elements\Employee;
use anvildev\slots\elements\Location;
use anvildev\slots\elements\Service;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that all elements define searchable attributes for CP search indexing.
 */
class SearchableAttributesTest extends TestCase
{
    /**
     * @dataProvider elementProvider
     */
    public function testDefineSearchableAttributes(string $elementClass, array $expected): void
    {
        $method = new ReflectionMethod($elementClass, 'defineSearchableAttributes');

        $this->assertSame($expected, $method->invoke(null));
    }

    public static function elementProvider(): array
    {
        return [
            'Service' => [Service::class, ['description']],
            'Employee' => [Employee::class, ['email']],
            'Location' => [Location::class, ['addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode']],
            'BlackoutDate' => [BlackoutDate::class, ['startDate', 'endDate']],
        ];
    }
}
