<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\RefundPolicyService;
use anvildev\slots\tests\Support\TestCase;

class RefundPolicyServiceTest extends TestCase
{
    private RefundPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RefundPolicyService();
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(RefundPolicyService::class, $this->service);
    }

    // --- calculatePercentageFromTiers ---

    public function testEmptyTiersReturns100(): void
    {
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers(null, 48));
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers([], 48));
    }

    public function testSingleTierAboveThreshold(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 24, 'refundPercentage' => 100],
        ];
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 48));
    }

    public function testSingleTierExactlyAtThreshold(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 24, 'refundPercentage' => 100],
        ];
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 24));
    }

    public function testSingleTierBelowThreshold(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 24, 'refundPercentage' => 100],
        ];
        // Below the only tier's threshold, returns that tier's percentage (last tier fallback)
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 12));
    }

    public function testMultipleTiersFullRefund(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 72));
    }

    public function testMultipleTiersPartialRefund(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(50, $this->service->calculatePercentageFromTiers($tiers, 36));
    }

    public function testMultipleTiersNoRefund(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(0, $this->service->calculatePercentageFromTiers($tiers, 12));
    }

    public function testMultipleTiersExactBoundary48(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 48));
    }

    public function testMultipleTiersExactBoundary24(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(50, $this->service->calculatePercentageFromTiers($tiers, 24));
    }

    public function testMultipleTiersExactBoundary0(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(0, $this->service->calculatePercentageFromTiers($tiers, 0));
    }

    public function testTiersAreSortedDescending(): void
    {
        // Provide tiers in unsorted order
        $tiers = [
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
        ];
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 72));
        $this->assertEquals(50, $this->service->calculatePercentageFromTiers($tiers, 30));
        $this->assertEquals(0, $this->service->calculatePercentageFromTiers($tiers, 10));
    }

    public function testFractionalHours(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 2, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 1, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertEquals(100, $this->service->calculatePercentageFromTiers($tiers, 2.5));
        $this->assertEquals(50, $this->service->calculatePercentageFromTiers($tiers, 1.5));
        $this->assertEquals(0, $this->service->calculatePercentageFromTiers($tiers, 0.5));
    }

    public function testSingleTierWith0HoursAlwaysMatches(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 0, 'refundPercentage' => 25],
        ];
        $this->assertEquals(25, $this->service->calculatePercentageFromTiers($tiers, 100));
        $this->assertEquals(25, $this->service->calculatePercentageFromTiers($tiers, 0));
    }

    public function testReturnsIntegerType(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 75],
        ];
        $result = $this->service->calculatePercentageFromTiers($tiers, 72);
        $this->assertIsInt($result);
    }

    public function testFallbackToLastTierWhenBelowAllThresholds(): void
    {
        // When hoursUntilBooking is below the lowest tier's hoursBeforeStart,
        // the last tier (lowest hoursBeforeStart) should be returned
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
        ];
        // 12 hours is below 24, so fallback to last tier (24h = 50%)
        $this->assertEquals(50, $this->service->calculatePercentageFromTiers($tiers, 12));
    }
}
