<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\elements\Reservation;
use anvildev\slots\tests\Support\TestCase;

/**
 * `totalPrice` is a stored column, not a computed one, so anything that changes
 * what a booking costs has to call recalculateTotals() before saving. Reducing
 * a booking's quantity is the path that gets this wrong: the seats go back but
 * the price stays at the old total unless it is recomputed.
 */
class ReservationRecalculateTotalsTest extends TestCase
{
    /**
     * Craft generates CustomFieldBehavior at runtime, so an element cannot be
     * constructed in a bare unit run. Skipping the constructor keeps these
     * checks running without a booted Craft, as ServiceSoftDeleteTest does.
     */
    private function makeReservation(): Reservation
    {
        return (new \ReflectionClass(Reservation::class))->newInstanceWithoutConstructor();
    }

    private function elementSource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
    }

    public function testRecalculateTotalsMethodExists(): void
    {
        $this->assertTrue(method_exists(Reservation::class, 'recalculateTotals'));
    }

    public function testTotalPriceIsAStoredProperty(): void
    {
        $reservation = $this->makeReservation();

        $this->assertObjectHasProperty('totalPrice', $reservation);
        $this->assertSame(0.0, $reservation->totalPrice);
        $this->assertStringContainsString('public float $totalPrice = 0.0;', $this->elementSource());
    }

    /**
     * With no service attached there is nothing to price, so the total stays at
     * zero rather than inheriting whatever was on the element before.
     */
    public function testRecalculateTotalsIsZeroWithoutAService(): void
    {
        $reservation = $this->makeReservation();
        $reservation->quantity = 3;

        $reservation->recalculateTotals();

        $this->assertSame(0.0, $reservation->totalPrice);
    }

    public function testRecalculateTotalsStoresTheComputedValue(): void
    {
        preg_match('/function recalculateTotals\(\).*?\{(.*?)\}/s', $this->elementSource(), $match);

        $this->assertNotEmpty($match[1] ?? '');
        $this->assertStringContainsString('getTotalPrice()', $match[1]);
        $this->assertStringContainsString('$this->totalPrice', $match[1]);
    }

    public function testReduceQuantityRecalculates(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/BookingService.php');
        preg_match('/function reduceQuantity\(.*?\{(.*?)\n    \}/s', $source, $match);

        $this->assertNotEmpty($match[1] ?? '', 'reduceQuantity should exist in BookingService');
        $this->assertStringContainsString('recalculateTotals()', $match[1]);
    }

    /**
     * Order matters: recalculating before the new quantity is set prices the old
     * booking, and recalculating after the save writes a stale total.
     */
    public function testRecalculateHappensBetweenTheQuantityChangeAndTheSave(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/BookingService.php');

        $quantityPos = strpos($source, '$reservation->quantity = $newQuantity;');
        $recalcPos = strpos($source, '$reservation->recalculateTotals();');
        $savePos = strpos($source, '$reservation->save()', $quantityPos ?: 0);

        $this->assertNotFalse($quantityPos, 'quantity assignment should exist');
        $this->assertNotFalse($recalcPos, 'recalculateTotals call should exist');
        $this->assertNotFalse($savePos, 'save call should exist after quantity is set');
        $this->assertGreaterThan($quantityPos, $recalcPos, 'recalculateTotals should come after the quantity change');
        $this->assertLessThan($savePos, $recalcPos, 'recalculateTotals should come before the save');
    }
}
