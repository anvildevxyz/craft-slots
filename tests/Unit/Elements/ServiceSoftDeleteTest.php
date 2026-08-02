<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Service;
use anvildev\slots\tests\Support\TestCase;

class ServiceSoftDeleteTest extends TestCase
{
    private function makeService(array $props = []): Service
    {
        $ref = new \ReflectionClass(Service::class);
        $service = $ref->newInstanceWithoutConstructor();

        $service->duration = $props['duration'] ?? 60;
        $service->price = $props['price'] ?? null;
        $service->description = $props['description'] ?? null;
        $service->deletedAt = $props['deletedAt'] ?? null;

        return $service;
    }

    public function testServiceHasDeletedAtProperty(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->deletedAt);
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $service = $this->makeService();
        $service->softDelete();
        $this->assertNotNull($service->deletedAt);
        $this->assertInstanceOf(\DateTime::class, new \DateTime($service->deletedAt));
    }

    public function testIsSoftDeletedReturnsFalseByDefault(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isSoftDeleted());
    }

    public function testIsSoftDeletedReturnsTrueAfterSoftDelete(): void
    {
        $service = $this->makeService();
        $service->softDelete();
        $this->assertTrue($service->isSoftDeleted());
    }

    /**
     * `deletedAt` is stored in UTC without a marker, matching soft locks and the
     * audit log and Craft's own datetime columns. Parsing it back in the local
     * zone — which is what this test did — shifts it by the machine's offset and
     * fails everywhere except UTC. The column is only ever read as null or
     * not-null, so the stored zone never reaches a comparison in the plugin.
     */
    public function testSoftDeleteSetsValidDateTimeString(): void
    {
        $utc = new \DateTimeZone('UTC');
        $service = $this->makeService();
        $before = new \DateTime('now', $utc);
        $service->softDelete();
        $after = new \DateTime('now', $utc);

        $deletedAt = new \DateTime($service->deletedAt, $utc);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $deletedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $deletedAt->getTimestamp());
    }

    public function testIsSoftDeletedReturnsTrueWithManualDeletedAt(): void
    {
        $service = $this->makeService(['deletedAt' => '2025-01-01 00:00:00']);
        $this->assertTrue($service->isSoftDeleted());
    }
}
