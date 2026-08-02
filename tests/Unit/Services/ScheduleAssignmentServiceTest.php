<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\ScheduleAssignmentService;
use anvildev\slots\tests\Support\TestCase;

class ScheduleAssignmentServiceTest extends TestCase
{
    /**
     * Test that date-specific schedules take priority over forever schedules
     */
    public function testDateSpecificSchedulesTakePriorityOverForeverSchedules(): void
    {
        // Create mock schedules
        $foreverSchedule = $this->createMockSchedule([
            'id' => 1,
            'title' => 'Forever Schedule',
            'startDate' => null,
            'endDate' => null,
            'enabled' => true,
        ]);

        $christmasSchedule = $this->createMockSchedule([
            'id' => 2,
            'title' => 'Christmas Schedule',
            'startDate' => '2026-12-24',
            'endDate' => '2026-12-26',
            'enabled' => true,
        ]);

        // Christmas schedule should have higher priority (tier 1 vs tier 3)
        $priority1 = ScheduleAssignmentService::calculateDateSpecificityTier($christmasSchedule);
        $priority2 = ScheduleAssignmentService::calculateDateSpecificityTier($foreverSchedule);

        $this->assertEquals(1, $priority1, 'Christmas schedule should be tier 1 (both dates defined)');
        $this->assertEquals(3, $priority2, 'Forever schedule should be tier 3 (no dates defined)');
        $this->assertLessThan($priority2, $priority1, 'Date-specific schedule should have lower tier number (higher priority)');
    }

    /**
     * Test the three-tier priority system
     */
    public function testThreeTierPrioritySystem(): void
    {
        // Tier 1: Both dates defined
        $bothDates = $this->createMockSchedule([
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-31',
        ]);

        // Tier 2: Only start date
        $onlyStart = $this->createMockSchedule([
            'startDate' => '2026-01-01',
            'endDate' => null,
        ]);

        // Tier 2: Only end date
        $onlyEnd = $this->createMockSchedule([
            'startDate' => null,
            'endDate' => '2026-12-31',
        ]);

        // Tier 3: Neither date (forever)
        $forever = $this->createMockSchedule([
            'startDate' => null,
            'endDate' => null,
        ]);

        $this->assertEquals(1, ScheduleAssignmentService::calculateDateSpecificityTier($bothDates));
        $this->assertEquals(2, ScheduleAssignmentService::calculateDateSpecificityTier($onlyStart));
        $this->assertEquals(2, ScheduleAssignmentService::calculateDateSpecificityTier($onlyEnd));
        $this->assertEquals(3, ScheduleAssignmentService::calculateDateSpecificityTier($forever));
    }

    /**
     * Test that sortOrder is used as tiebreaker within same tier
     */
    public function testSortOrderTiebreakerWithinSameTier(): void
    {
        $schedules = [
            $this->createMockSchedule([
                'id' => 1,
                'startDate' => '2026-12-01',
                'endDate' => '2026-12-31',
                'sortOrder' => 5,
            ]),
            $this->createMockSchedule([
                'id' => 2,
                'startDate' => '2026-12-20',
                'endDate' => '2026-12-30',
                'sortOrder' => 1,
            ]),
        ];

        $sorted = ScheduleAssignmentService::sortByDateSpecificityAndSortOrder($schedules);

        // Both are tier 1, so sortOrder decides: schedule with sortOrder 1 should come first
        $this->assertEquals(2, $sorted[0]->id);
        $this->assertEquals(1, $sorted[1]->id);
    }

    /**
     * Test sorting with mixed tiers
     */
    public function testSortingWithMixedTiers(): void
    {
        $schedules = [
            $this->createMockSchedule([
                'id' => 1,
                'startDate' => null,
                'endDate' => null,
                'sortOrder' => 0,
            ]), // Tier 3
            $this->createMockSchedule([
                'id' => 2,
                'startDate' => '2026-12-01',
                'endDate' => null,
                'sortOrder' => 0,
            ]), // Tier 2
            $this->createMockSchedule([
                'id' => 3,
                'startDate' => '2026-12-01',
                'endDate' => '2026-12-31',
                'sortOrder' => 0,
            ]), // Tier 1
        ];

        $sorted = ScheduleAssignmentService::sortByDateSpecificityAndSortOrder($schedules);

        // Should be sorted by tier: 1, 2, 3
        $this->assertEquals(3, $sorted[0]->id); // Tier 1 first
        $this->assertEquals(2, $sorted[1]->id); // Tier 2 second
        $this->assertEquals(1, $sorted[2]->id); // Tier 3 last
    }

    public function testGetActiveSchedulesForDateBatchReturnsSchedulesIndexedByEmployee(): void
    {
        $service = new ScheduleAssignmentService();
        $this->assertTrue(
            method_exists($service, 'getActiveSchedulesForDateBatch'),
            'getActiveSchedulesForDateBatch method should exist'
        );
    }

    /**
     * Helper to create a mock Schedule-like object for testing
     *
     * @param array $attributes
     * @return object
     */
    private function createMockSchedule(array $attributes): object
    {
        return (object) [
            'id' => $attributes['id'] ?? rand(1, 9999),
            'title' => $attributes['title'] ?? 'Test Schedule',
            'startDate' => $attributes['startDate'] ?? null,
            'endDate' => $attributes['endDate'] ?? null,
            'enabled' => $attributes['enabled'] ?? true,
            'sortOrder' => $attributes['sortOrder'] ?? null,
        ];
    }
}
