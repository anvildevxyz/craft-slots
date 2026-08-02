<?php

namespace anvildev\slots\tests\Unit\Elements;

use anvildev\slots\records\ReservationRecord;
use anvildev\slots\tests\Support\TestCase;

/**
 * Deleting a booking must not destroy it.
 *
 * Craft soft-deletes by default and the control panel's Delete action does
 * exactly that — which only became reachable for bookings when the index became
 * a native element index. afterDelete() was deleting the slots_reservations row
 * outright, so a trashed booking lost its data and restoring it produced an
 * empty reservation. Confirmed against the database before it was fixed.
 *
 * The lifecycle is now: soft delete keeps the row and releases the slot; restore
 * reclaims the slot; hard delete removes both halves through the foreign key.
 */
class ReservationDeleteLifecycleTest extends TestCase
{
    private function elementSource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
    }

    /**
     * The row must only ever be removed by the cascade, never by hand — a manual
     * delete cannot tell a soft delete from a hard one until it is too late.
     */
    public function testAfterDeleteDoesNotDeleteTheRecord(): void
    {
        $source = $this->elementSource();
        $start = strpos($source, 'public function afterDelete()');
        $this->assertNotFalse($start);

        $body = substr($source, $start, strpos($source, "\n    /**", $start + 1) - $start);

        $this->assertStringNotContainsString(
            'getRecord()?->delete()',
            $body,
            'Deleting the row here destroys a soft-deleted booking; the foreign key cascade handles hard deletes',
        );
    }

    public function testSoftDeleteReleasesTheSlot(): void
    {
        $source = $this->elementSource();
        $start = strpos($source, 'public function afterDelete()');
        $body = substr($source, $start, strpos($source, "\n    /**", $start + 1) - $start);

        $this->assertStringContainsString('!$this->hardDelete', $body, 'Soft and hard deletes must behave differently');
        $this->assertStringContainsString(
            "'activeSlotKey' => null",
            $body,
            'A booking in the trash must not keep holding its seat',
        );
    }

    public function testRestoreReclaimsTheSlot(): void
    {
        $this->assertStringContainsString(
            'public function afterRestore()',
            $this->elementSource(),
            'A restored booking should take its slot back',
        );
    }

    /**
     * The schema has to keep the promise afterDelete() now relies on.
     */
    public function testTheSchemaCascadesFromElements(): void
    {
        $install = file_get_contents(dirname(__DIR__, 3) . '/src/migrations/Install.php');

        $this->assertStringContainsString(
            "addForeignKey(null, '{{%slots_reservations}}', 'id', '{{%elements}}', 'id', 'CASCADE'",
            $install,
            'Hard deletes rely on this cascade to remove the reservation row',
        );
    }

    /**
     * The unique index only prevents double-booking when one slot produces one
     * key. "15:00" arrives from the booking form and "15:00:00" comes back out
     * of the TIME column, so an unnormalised key let a restored booking and a
     * new one occupy the same slot without the index noticing.
     *
     * @dataProvider timeFormatProvider
     */
    public function testTheSlotKeyIsTheSameWhicheverTimeFormatArrives(string $startTime): void
    {
        $this->assertSame(
            '2026-08-04|15:00|42',
            ReservationRecord::computeSlotKey(ReservationRecord::STATUS_CONFIRMED, 42, '2026-08-04', $startTime),
        );
    }

    /** @return array<string, string[]> */
    public static function timeFormatProvider(): array
    {
        return [
            'from the booking form' => ['15:00'],
            'read back from the database' => ['15:00:00'],
        ];
    }

    public function testCancelledAndStafflessBookingsHoldNoSlot(): void
    {
        $this->assertNull(
            ReservationRecord::computeSlotKey(ReservationRecord::STATUS_CANCELLED, 42, '2026-08-04', '15:00'),
            'A cancelled booking must free its slot',
        );

        $this->assertNull(
            ReservationRecord::computeSlotKey(ReservationRecord::STATUS_CONFIRMED, null, '2026-08-04', '15:00'),
            'Group bookings are limited by capacity, not by this key',
        );
    }
}
