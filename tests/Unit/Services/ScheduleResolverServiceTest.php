<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\ScheduleResolverService;
use anvildev\slots\tests\Support\TestCase;

/**
 * ScheduleResolverService Test
 *
 * Tests the pure utility functions in ScheduleResolverService
 */
class ScheduleResolverServiceTest extends TestCase
{
    private ScheduleResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleResolverService();
    }

    // =========================================================================
    // buildWindowsFromServiceAvailability() Tests - Basic Cases
    // =========================================================================

    public function testBuildWindowsDisabled(): void
    {
        $availability = [
            'enabled' => false,
            'start' => '09:00',
            'end' => '17:00',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testBuildWindowsEnabledWithoutBreak(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => null,
            'breakEnd' => null,
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('17:00', $result[0]['end']);
    }

    public function testBuildWindowsEnabledWithEmptyBreak(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => '',
            'breakEnd' => '',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('17:00', $result[0]['end']);
    }

    // =========================================================================
    // buildWindowsFromServiceAvailability() Tests - With Break
    // =========================================================================

    public function testBuildWindowsWithLunchBreak(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => '12:00',
            'breakEnd' => '13:00',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertCount(2, $result);

        // Morning window
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('12:00', $result[0]['end']);

        // Afternoon window
        $this->assertEquals('13:00', $result[1]['start']);
        $this->assertEquals('17:00', $result[1]['end']);
    }

    public function testBuildWindowsWithShortBreak(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '08:00',
            'end' => '16:00',
            'breakStart' => '10:00',
            'breakEnd' => '10:15',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertCount(2, $result);

        // Before break
        $this->assertEquals('08:00', $result[0]['start']);
        $this->assertEquals('10:00', $result[0]['end']);

        // After break
        $this->assertEquals('10:15', $result[1]['start']);
        $this->assertEquals('16:00', $result[1]['end']);
    }

    public function testBuildWindowsBreakAtStart(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => '09:00',
            'breakEnd' => '09:30',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Break at start means no window before break
        $this->assertCount(1, $result);
        $this->assertEquals('09:30', $result[0]['start']);
        $this->assertEquals('17:00', $result[0]['end']);
    }

    public function testBuildWindowsBreakAtEnd(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => '16:30',
            'breakEnd' => '17:00',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Break at end means no window after break
        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('16:30', $result[0]['end']);
    }

    // =========================================================================
    // buildWindowsFromServiceAvailability() Tests - Combined Windows
    // =========================================================================

    public function testBuildWindowsWithCombinedWindows(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            '_combinedWindows' => [
                ['start' => '09:00', 'end' => '11:00'],
                ['start' => '14:00', 'end' => '17:00'],
            ],
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Should return combined windows directly
        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('11:00', $result[0]['end']);
        $this->assertEquals('14:00', $result[1]['start']);
        $this->assertEquals('17:00', $result[1]['end']);
    }

    public function testBuildWindowsWithEmptyCombinedWindows(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            '_combinedWindows' => [],
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Empty combined windows returns empty
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // buildWindowsFromServiceAvailability() Tests - Edge Cases
    // =========================================================================

    public function testBuildWindowsEmptyAvailability(): void
    {
        $result = $this->service->buildWindowsFromServiceAvailability([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testBuildWindowsMissingEnabledKey(): void
    {
        $availability = [
            'start' => '09:00',
            'end' => '17:00',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Missing 'enabled' defaults to false
        $this->assertEmpty($result);
    }

    public function testBuildWindowsOnlyBreakStartSet(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => '12:00',
            'breakEnd' => null,
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Partial break = no break applied
        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('17:00', $result[0]['end']);
    }

    public function testBuildWindowsOnlyBreakEndSet(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '17:00',
            'breakStart' => null,
            'breakEnd' => '13:00',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        // Partial break = no break applied
        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('17:00', $result[0]['end']);
    }

    public function testBuildWindowsRealWorldScenarioFullDay(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '08:00',
            'end' => '18:00',
            'breakStart' => '12:30',
            'breakEnd' => '13:30',
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertCount(2, $result);

        // Morning session: 8:00 - 12:30 (4.5 hours)
        $this->assertEquals('08:00', $result[0]['start']);
        $this->assertEquals('12:30', $result[0]['end']);

        // Afternoon session: 13:30 - 18:00 (4.5 hours)
        $this->assertEquals('13:30', $result[1]['start']);
        $this->assertEquals('18:00', $result[1]['end']);
    }

    public function testBuildWindowsRealWorldScenarioHalfDay(): void
    {
        $availability = [
            'enabled' => true,
            'start' => '09:00',
            'end' => '13:00',
            'breakStart' => null,
            'breakEnd' => null,
        ];

        $result = $this->service->buildWindowsFromServiceAvailability($availability);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['start']);
        $this->assertEquals('13:00', $result[0]['end']);
    }
}
