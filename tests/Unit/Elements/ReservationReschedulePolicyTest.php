<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Reservation;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\tests\Support\TestCase;

/**
 * Rescheduling must never be permitted where cancelling is not.
 *
 * Moving a booking is cancelling it and taking another slot, so a customer who
 * cannot cancel inside the policy window must not be able to move out of it
 * either — otherwise the policy is defeated by rescheduling to a far-off date
 * and never showing up. The controller enforced only "not past" and "not
 * cancelled" before this, which left exactly that hole.
 */
class ReservationReschedulePolicyTest extends TestCase
{
    /**
     * Craft generates CustomFieldBehavior at runtime, so an element cannot be
     * constructed in a bare unit run.
     */
    private function makeReservation(array $attributes = []): Reservation
    {
        $reservation = (new \ReflectionClass(Reservation::class))->newInstanceWithoutConstructor();

        foreach ($attributes as $name => $value) {
            $reservation->$name = $value;
        }

        return $reservation;
    }

    private function inDays(int $days): string
    {
        return (new \DateTime("+{$days} days"))->format('Y-m-d');
    }

    public function testInterfaceRequiresTheMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\contracts\ReservationInterface::class, 'canBeRescheduled'),
            'ReservationInterface must declare canBeRescheduled(), since the controller gates on it',
        );
    }

    public function testACancelledBookingCannotBeRescheduled(): void
    {
        $reservation = $this->makeReservation([
            'status' => ReservationRecord::STATUS_CANCELLED,
            'bookingDate' => $this->inDays(30),
            'startTime' => '10:00:00',
        ]);

        $this->assertFalse($reservation->canBeRescheduled());
    }

    public function testAPastBookingCannotBeRescheduled(): void
    {
        $reservation = $this->makeReservation([
            'status' => ReservationRecord::STATUS_CONFIRMED,
            'bookingDate' => (new \DateTime('-1 day'))->format('Y-m-d'),
            'startTime' => '10:00:00',
        ]);

        $this->assertFalse($reservation->canBeRescheduled());
    }

    /**
     * The case the missing guard allowed: a booking far enough out to cancel is
     * also far enough out to move.
     */
    public function testABookingWellOutsideThePolicyWindowCanBeRescheduled(): void
    {
        $reservation = $this->makeReservation([
            'status' => ReservationRecord::STATUS_CONFIRMED,
            'bookingDate' => $this->inDays(30),
            'startTime' => '10:00:00',
        ]);

        $this->assertSame($reservation->canBeCancelled(), $reservation->canBeRescheduled());
        $this->assertTrue($reservation->canBeRescheduled());
    }

    /**
     * The two answers are the same by construction. Asserting it across a spread
     * of dates is what stops the two drifting apart later.
     *
     * @dataProvider bookingDateProvider
     */
    public function testRescheduleNeverGrantsMoreThanCancel(int $daysOut, string $status): void
    {
        $reservation = $this->makeReservation([
            'status' => $status,
            'bookingDate' => $this->inDays($daysOut),
            'startTime' => '10:00:00',
        ]);

        $canCancel = $reservation->canBeCancelled();
        $canReschedule = $reservation->canBeRescheduled();

        $this->assertFalse(
            $canReschedule && !$canCancel,
            "A booking {$daysOut} day(s) out with status '{$status}' may be rescheduled but not cancelled, "
                . 'which lets a customer escape the cancellation policy',
        );
    }

    /** @return array<string, array{int, string}> */
    public static function bookingDateProvider(): array
    {
        $confirmed = ReservationRecord::STATUS_CONFIRMED;
        $cancelled = ReservationRecord::STATUS_CANCELLED;

        return [
            'yesterday, confirmed' => [-1, $confirmed],
            'today, confirmed' => [0, $confirmed],
            'tomorrow, confirmed' => [1, $confirmed],
            'a week out, confirmed' => [7, $confirmed],
            'a month out, confirmed' => [30, $confirmed],
            'a month out, cancelled' => [30, $cancelled],
        ];
    }
}
