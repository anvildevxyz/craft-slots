<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\MaintenanceService;
use anvildev\slots\tests\Support\TestCase;
use craft\base\Component;

/**
 * MaintenanceService Test
 *
 * Tests the maintenance service that handles cleanup tasks for the booking system.
 */
class MaintenanceServiceTest extends TestCase
{
    private MaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MaintenanceService();
    }

    // =========================================================================
    // Class Structure
    // =========================================================================

    public function testExtendsComponent(): void
    {
        $this->assertInstanceOf(Component::class, $this->service);
    }

    public function testHasRunAllMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'runAll'));
    }

    public function testHasCleanupExpiredSoftLocksMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'cleanupExpiredSoftLocks'));
    }

    public function testHasGetStatsMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'getStats'));
    }

    // =========================================================================
    // Method Signatures
    // =========================================================================

    public function testRunAllReturnsArray(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'runAll');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testGetStatsReturnsArray(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'getStats');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    // =========================================================================
    // Default Parameters
    // =========================================================================
}
