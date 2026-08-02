<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\elements\Service;
use anvildev\slots\services\AvailabilityService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;

/**
 * Capacity-aware window subtraction for employee-less services.
 *
 * A booking on a slot whose Schedule grants more than one seat must only
 * shrink the remaining capacity — the slot may only leave the working window
 * once every seat is taken. Exercises the sweep directly through reflection so
 * the arithmetic is covered without a Craft bootstrap.
 */
class AvailabilityCapacityTest extends TestCase
{
    private AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AvailabilityService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =====================================================
    // Helpers
    // =====================================================

    /** Invoke a private AvailabilityService method. */
    private function invoke(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(AvailabilityService::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }

    /** Minimal booking stand-in — the sweep only reads these three fields. */
    private function booking(?string $start, ?string $end, int $quantity = 1): object
    {
        return (object)['startTime' => $start, 'endTime' => $end, 'quantity' => $quantity];
    }

    private function service(int $bufferBefore = 0, int $bufferAfter = 0): Service
    {
        $service = Mockery::mock(Service::class);
        $service->bufferBefore = $bufferBefore;
        $service->bufferAfter = $bufferAfter;
        return $service;
    }

    /** @param array<array{start: string, end: string}> $bookings */
    private function subtract(array $windows, array $bookings, ?int $capacity, ?Service $service = null): array
    {
        return $this->invoke('subtractBookingsFromWindows', [
            $windows,
            $bookings,
            $service ?? $this->service(),
            $capacity,
        ]);
    }

    private const DAY = [['start' => '09:00', 'end' => '17:00']];

    // =====================================================
    // Capacity > 1: the reported bug
    // =====================================================

    public function testSingleBookingDoesNotCloseAMultiCapacitySlot(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('09:00', '10:00')], 10);

        $this->assertEquals(self::DAY, $windows, 'One booking must not blank a capacity-10 window');
    }

    public function testWindowSurvivesUntilTheLastSeatIsTaken(): void
    {
        $bookings = [];
        for ($i = 0; $i < 2; $i++) {
            $bookings[] = $this->booking('09:00', '10:00');
        }

        $this->assertEquals(self::DAY, $this->subtract(self::DAY, $bookings, 3), '2 of 3 seats leaves the window open');

        $bookings[] = $this->booking('09:00', '10:00');
        $this->assertEquals(
            [['start' => '10:00', 'end' => '17:00']],
            $this->subtract(self::DAY, $bookings, 3),
            '3 of 3 seats closes the booked hour',
        );
    }

    public function testGroupBookingConsumesItsFullQuantity(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('09:00', '10:00', 4)], 4);

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $windows, 'A quantity-4 booking fills a capacity-4 slot');
    }

    public function testMixedQuantitiesAccumulate(): void
    {
        $bookings = [$this->booking('09:00', '10:00', 3), $this->booking('09:00', '10:00', 2)];

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $this->subtract(self::DAY, $bookings, 5));
        $this->assertEquals(self::DAY, $this->subtract(self::DAY, $bookings, 6), 'One seat left keeps the window open');
    }

    public function testOverbookingBeyondCapacityStillOnlyClosesTheBookedRange(): void
    {
        $bookings = [$this->booking('09:00', '10:00', 50)];

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $this->subtract(self::DAY, $bookings, 2));
    }

    public function testOnlyTheSaturatedRangeIsRemoved(): void
    {
        // 09:00-10:00 is full (2/2); 10:00-11:00 has one seat left.
        $bookings = [
            $this->booking('09:00', '10:00'),
            $this->booking('09:00', '11:00'),
        ];

        $this->assertEquals(
            [['start' => '10:00', 'end' => '17:00']],
            $this->subtract(self::DAY, $bookings, 2),
        );
    }

    public function testTouchingSaturatedSegmentsMergeIntoOneRange(): void
    {
        // Back-to-back full hours must come out as a single 09:00-11:00 cut.
        $bookings = [
            $this->booking('09:00', '10:00'),
            $this->booking('10:00', '11:00'),
        ];

        $this->assertEquals(
            [['start' => '11:00', 'end' => '17:00']],
            $this->subtract(self::DAY, $bookings, 1),
        );
    }

    public function testSaturatedRangeInTheMiddleSplitsTheWindow(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('12:00', '13:00')], 1);

        $this->assertEquals([
            ['start' => '09:00', 'end' => '12:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ], $windows);
    }

    // =====================================================
    // Capacity 1 / null: unchanged single-booking behaviour
    // =====================================================

    public function testCapacityOneBlocksOnTheFirstBooking(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('09:00', '10:00')], 1);

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $windows);
    }

    public function testNullCapacityBehavesAsASingleSeat(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('09:00', '10:00')], null);

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $windows, 'An unset capacity must not silently mean unlimited');
    }

    public function testZeroAndNegativeCapacityAreTreatedAsOneSeat(): void
    {
        foreach ([0, -5] as $capacity) {
            $this->assertEquals(
                [['start' => '10:00', 'end' => '17:00']],
                $this->subtract(self::DAY, [$this->booking('09:00', '10:00')], $capacity),
                "Capacity {$capacity} must not open the slot up",
            );
        }
    }

    // =====================================================
    // Buffers
    // =====================================================

    public function testBuffersExtendTheSaturatedRange(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('12:00', '13:00')], 1, $this->service(15, 30));

        $this->assertEquals([
            ['start' => '09:00', 'end' => '11:45'],
            ['start' => '13:30', 'end' => '17:00'],
        ], $windows);
    }

    public function testBuffersDoNotBlockWhileSeatsRemain(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('12:00', '13:00')], 5, $this->service(15, 30));

        $this->assertEquals(self::DAY, $windows, 'A partly filled group slot must not block its buffer window');
    }

    public function testBufferBeforeMidnightIsClampedInsteadOfThrowing(): void
    {
        $windows = $this->subtract(
            [['start' => '00:00', 'end' => '23:59']],
            [$this->booking('00:00', '01:00')],
            1,
            $this->service(30, 0),
        );

        $this->assertEquals([['start' => '01:00', 'end' => '23:59']], $windows);
    }

    public function testBufferPastMidnightIsClampedInsteadOfThrowing(): void
    {
        $windows = $this->subtract(
            [['start' => '00:00', 'end' => '23:59']],
            [$this->booking('23:00', '23:30')],
            1,
            $this->service(0, 120),
        );

        $this->assertEquals([['start' => '00:00', 'end' => '23:00']], $windows);
    }

    // =====================================================
    // Degenerate input
    // =====================================================

    public function testNoBookingsLeavesTheWindowsUntouched(): void
    {
        $this->assertEquals(self::DAY, $this->subtract(self::DAY, [], 10));
    }

    public function testTimelessBookingsAreIgnored(): void
    {
        $bookings = [$this->booking(null, null), $this->booking('09:00', null), $this->booking(null, '10:00')];

        $this->assertEquals(self::DAY, $this->subtract(self::DAY, $bookings, 1));
    }

    public function testSecondsInBookingTimesAreNormalised(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('09:00:00', '10:00:00')], 1);

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $windows);
    }

    public function testBookingsOutsideTheWindowChangeNothing(): void
    {
        $windows = $this->subtract(self::DAY, [$this->booking('06:00', '07:00')], 1);

        $this->assertEquals(self::DAY, $windows);
    }

    public function testMissingQuantityCountsAsOneSeat(): void
    {
        $booking = (object)['startTime' => '09:00', 'endTime' => '10:00'];

        $this->assertEquals([['start' => '10:00', 'end' => '17:00']], $this->subtract(self::DAY, [$booking], 1));
    }

    // =====================================================
    // Sweep internals
    // =====================================================

    public function testFindSaturatedRangesReportsEachDistinctBlock(): void
    {
        $intervals = [
            ['start' => '09:00', 'end' => '10:00', 'seats' => 2],
            ['start' => '14:00', 'end' => '15:00', 'seats' => 2],
        ];

        $this->assertEquals(
            [['09:00', '10:00'], ['14:00', '15:00']],
            $this->invoke('findSaturatedRanges', [$intervals, 2]),
        );
    }

    public function testFindSaturatedRangesIgnoresUnsaturatedSegments(): void
    {
        $intervals = [['start' => '09:00', 'end' => '10:00', 'seats' => 1]];

        $this->assertSame([], $this->invoke('findSaturatedRanges', [$intervals, 2]));
    }

    public function testShiftWithinDayClampsToTheDayBounds(): void
    {
        $this->assertSame('00:00', $this->invoke('shiftWithinDay', ['00:10', -30]));
        $this->assertSame('24:00', $this->invoke('shiftWithinDay', ['23:50', 30]));
        $this->assertSame('09:45', $this->invoke('shiftWithinDay', ['09:00', 45]));
    }

    // =====================================================
    // Seat accounting agrees with the window subtraction
    // =====================================================

    public function testSeatAccountingCountsEveryStatusThatHoldsASlot(): void
    {
        $statuses = (new \ReflectionClass(\anvildev\slots\services\CapacityService::class))
            ->getConstant('SEAT_HOLDING_STATUSES');

        // AvailabilityService subtracts everything but cancelled from the working
        // window. Counting fewer statuses here would report seats that are taken.
        $this->assertContains('confirmed', $statuses);
        $this->assertContains('pending', $statuses);
        $this->assertContains('no_show', $statuses, 'A no-show keeps its slot rather than releasing the seat');
        $this->assertNotContains('cancelled', $statuses);
    }

    public function testCapacityEnrichmentCanExcludeTheBookingBeingRescheduled(): void
    {
        $parameters = (new \ReflectionMethod(\anvildev\slots\services\CapacityService::class, 'enrichSlotsWithCapacity'))
            ->getParameters();

        $last = end($parameters);
        $this->assertSame('excludeReservationId', $last->getName());
        $this->assertTrue($last->isOptional(), 'Callers that are not rescheduling must be able to omit it');
    }

    public function testBuildOccupancyIntervalsPadsWithBuffers(): void
    {
        $intervals = $this->invoke('buildOccupancyIntervals', [
            [$this->booking('10:00', '11:00', 3)],
            15,
            30,
        ]);

        $this->assertEquals([['start' => '09:45', 'end' => '11:30', 'seats' => 3]], $intervals);
    }
}
