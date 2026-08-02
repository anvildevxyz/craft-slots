<?php

namespace anvildev\slots\tests\Unit\Events;

use anvildev\slots\events\AfterAvailabilityCheckEvent;
use anvildev\slots\tests\Support\TestCase;

/**
 * AfterAvailabilityCheckEvent Test
 *
 * This event has init() logic that syncs alias properties.
 */
class AfterAvailabilityCheckEventTest extends TestCase
{
    public function testDefaults(): void
    {
        $event = new AfterAvailabilityCheckEvent([
            'date' => '2025-06-15',
        ]);

        $this->assertEquals('2025-06-15', $event->date);
        $this->assertNull($event->serviceId);
        $this->assertNull($event->employeeId);
        $this->assertNull($event->locationId);
        $this->assertEquals([], $event->slots);
        $this->assertEquals(0, $event->slotCount);
        $this->assertEquals(0.0, $event->calculationTime);
        $this->assertFalse($event->fromCache);
    }

    public function testInitSyncsAvailableSlotsAlias(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '10:00', 'endTime' => '11:00'],
        ];

        $event = new AfterAvailabilityCheckEvent([
            'date' => '2025-06-15',
            'slots' => $slots,
        ]);

        $this->assertEquals($slots, $event->availableSlots);
    }

    public function testInitSyncsDurationAlias(): void
    {
        $event = new AfterAvailabilityCheckEvent([
            'date' => '2025-06-15',
            'calculationTime' => 0.125,
        ]);

        $this->assertEquals(0.125, $event->duration);
    }

    public function testAcceptsAllProperties(): void
    {
        $event = new AfterAvailabilityCheckEvent([
            'date' => '2025-06-15',
            'serviceId' => 1,
            'employeeId' => 5,
            'locationId' => 3,
            'slots' => [['time' => '09:00']],
            'slotCount' => 1,
            'calculationTime' => 0.05,
            'fromCache' => true,
        ]);

        $this->assertEquals(1, $event->serviceId);
        $this->assertEquals(5, $event->employeeId);
        $this->assertEquals(3, $event->locationId);
        $this->assertEquals(1, $event->slotCount);
        $this->assertTrue($event->fromCache);
    }
}
