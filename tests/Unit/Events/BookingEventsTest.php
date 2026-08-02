<?php

namespace anvildev\slots\tests\Unit\Events;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\events\AfterBookingCancelEvent;
use anvildev\slots\events\AfterBookingSaveEvent;
use anvildev\slots\events\BeforeAvailabilityCheckEvent;
use anvildev\slots\events\BeforeBookingCancelEvent;
use anvildev\slots\events\BeforeBookingSaveEvent;
use anvildev\slots\tests\Support\TestCase;
use Mockery;

/**
 * Booking Events Test
 *
 * Tests event DTO properties, defaults, and instantiation.
 * Events are data containers fired by services.
 */
class BookingEventsTest extends TestCase
{
    // =========================================================================
    // BeforeBookingSaveEvent
    // =========================================================================

    public function testBeforeBookingSaveEventDefaults(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeBookingSaveEvent([
            'reservation' => $reservation,
        ]);

        $this->assertSame($reservation, $event->reservation);
        $this->assertTrue($event->isNew);
        $this->assertEquals([], $event->bookingData);
        $this->assertNull($event->source);
        $this->assertNull($event->errorMessage);
    }

    public function testBeforeBookingSaveEventAcceptsProperties(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeBookingSaveEvent([
            'reservation' => $reservation,
            'isNew' => false,
            'bookingData' => ['date' => '2025-06-15'],
            'source' => 'api',
            'errorMessage' => 'Custom error',
        ]);

        $this->assertFalse($event->isNew);
        $this->assertEquals(['date' => '2025-06-15'], $event->bookingData);
        $this->assertEquals('api', $event->source);
        $this->assertEquals('Custom error', $event->errorMessage);
    }

    public function testBeforeBookingSaveEventIsCancelable(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeBookingSaveEvent([
            'reservation' => $reservation,
        ]);

        $this->assertTrue($event->isValid);
        $event->isValid = false;
        $this->assertFalse($event->isValid);
    }

    // =========================================================================
    // AfterBookingSaveEvent
    // =========================================================================

    public function testAfterBookingSaveEventDefaults(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterBookingSaveEvent([
            'reservation' => $reservation,
        ]);

        $this->assertTrue($event->success);
        $this->assertEquals([], $event->errors);
        $this->assertTrue($event->isNew);
    }

    public function testAfterBookingSaveEventWithErrors(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterBookingSaveEvent([
            'reservation' => $reservation,
            'success' => false,
            'errors' => ['email' => 'Invalid email'],
        ]);

        $this->assertFalse($event->success);
        $this->assertEquals(['email' => 'Invalid email'], $event->errors);
    }

    // =========================================================================
    // BeforeBookingCancelEvent
    // =========================================================================

    public function testBeforeBookingCancelEventDefaults(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeBookingCancelEvent([
            'reservation' => $reservation,
        ]);

        $this->assertNull($event->reason);
        $this->assertNull($event->cancelledBy);
        $this->assertTrue($event->sendNotification);
        $this->assertNull($event->errorMessage);
    }

    public function testBeforeBookingCancelEventAcceptsProperties(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeBookingCancelEvent([
            'reservation' => $reservation,
            'reason' => 'No longer needed',
            'cancelledBy' => 'admin',
            'sendNotification' => false,
        ]);

        $this->assertEquals('No longer needed', $event->reason);
        $this->assertEquals('admin', $event->cancelledBy);
        $this->assertFalse($event->sendNotification);
    }

    // =========================================================================
    // AfterBookingCancelEvent
    // =========================================================================

    public function testAfterBookingCancelEventDefaults(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterBookingCancelEvent([
            'reservation' => $reservation,
        ]);

        $this->assertFalse($event->wasPaid);
        $this->assertFalse($event->shouldRefund);
        $this->assertNull($event->reason);
        $this->assertTrue($event->success);
    }

    public function testAfterBookingCancelEventWithRefund(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterBookingCancelEvent([
            'reservation' => $reservation,
            'wasPaid' => true,
            'shouldRefund' => true,
            'reason' => 'Changed plans',
        ]);

        $this->assertTrue($event->wasPaid);
        $this->assertTrue($event->shouldRefund);
        $this->assertEquals('Changed plans', $event->reason);
    }

    // =========================================================================
    // BeforeAvailabilityCheckEvent
    // =========================================================================

    public function testBeforeAvailabilityCheckEventDefaults(): void
    {
        $event = new BeforeAvailabilityCheckEvent([
            'date' => '2025-06-15',
        ]);

        $this->assertEquals('2025-06-15', $event->date);
        $this->assertNull($event->serviceId);
        $this->assertNull($event->employeeId);
        $this->assertNull($event->locationId);
        $this->assertEquals(1, $event->quantity);
        $this->assertEquals([], $event->criteria);
        $this->assertNull($event->errorMessage);
    }

    public function testBeforeAvailabilityCheckEventWithCriteria(): void
    {
        $event = new BeforeAvailabilityCheckEvent([
            'date' => '2025-06-15',
            'serviceId' => 1,
            'employeeId' => 5,
            'locationId' => 3,
            'quantity' => 2,
            'criteria' => ['excludeEarlySlots' => true],
        ]);

        $this->assertEquals(1, $event->serviceId);
        $this->assertEquals(5, $event->employeeId);
        $this->assertEquals(3, $event->locationId);
        $this->assertEquals(2, $event->quantity);
        $this->assertEquals(['excludeEarlySlots' => true], $event->criteria);
    }

}
