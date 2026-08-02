<?php

namespace anvildev\slots\tests\Unit\Contracts;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\contracts\ReservationQueryInterface;
use anvildev\slots\elements\db\ReservationQuery;
use anvildev\slots\elements\Reservation;
use anvildev\slots\tests\Support\TestCase;

/**
 * Reservation Interface Contract Test
 *
 * Verifies that the Reservation element implements every method named in
 * ReservationInterface, and its query every method in ReservationQueryInterface.
 * These interfaces are the type in ~40 call sites, so a drift here is silent.
 */
class ReservationInterfaceContractTest extends TestCase
{
    /**
     * Craft generates CustomFieldBehavior at runtime, so an element cannot be
     * constructed in a bare unit run. Skipping the constructor keeps these
     * contract checks running without a booted Craft, as ServiceSoftDeleteTest does.
     */
    private function makeReservation(array $attributes = []): Reservation
    {
        $reservation = (new \ReflectionClass(Reservation::class))->newInstanceWithoutConstructor();

        foreach ($attributes as $name => $value) {
            $reservation->$name = $value;
        }

        return $reservation;
    }

    public function testReservationImplementsInterface(): void
    {
        $this->assertTrue(
            is_a(Reservation::class, ReservationInterface::class, true),
            'Reservation must implement ReservationInterface'
        );
    }

    public function testReservationQueryImplementsInterface(): void
    {
        $this->assertTrue(
            is_a(ReservationQuery::class, ReservationQueryInterface::class, true),
            'ReservationQuery must implement ReservationQueryInterface'
        );
    }

    /**
     * @dataProvider reservationQueryInterfaceMethodsProvider
     */
    public function testReservationQueryHasAllInterfaceMethods(string $methodName): void
    {
        $this->assertTrue(
            method_exists(ReservationQuery::class, $methodName),
            "ReservationQuery is missing method: {$methodName}"
        );
    }

    public static function reservationQueryInterfaceMethodsProvider(): array
    {
        return [
            // Filter Methods
            ['id'],
            ['userName'],
            ['userEmail'],
            ['userId'],
            ['bookingDate'],
            ['startTime'],
            ['endTime'],
            ['employeeId'],
            ['locationId'],
            ['serviceId'],
            ['status'],
            ['reservationStatus'],
            ['confirmationToken'],
            ['forCurrentUser'],

            // Eager Loading
            ['withEmployee'],
            ['withService'],
            ['withLocation'],
            ['withRelations'],

            // Ordering
            ['orderBy'],

            // Pagination
            ['limit'],
            ['offset'],

            // Results
            ['one'],
            ['all'],
            ['count'],
            ['exists'],
            ['ids'],

            // Raw Query
            ['where'],
            ['andWhere'],
        ];
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    public function testCanInstantiateReservation(): void
    {
        $model = $this->makeReservation();
        $this->assertInstanceOf(ReservationInterface::class, $model);
    }

    public function testCanInstantiateReservationQuery(): void
    {
        $this->requiresCraft();
        $query = new ReservationQuery();
        $this->assertInstanceOf(ReservationQueryInterface::class, $query);
    }

    // =========================================================================
    // Model Getter Return Types
    // =========================================================================

    public function testIdentityGettersReturnCorrectTypes(): void
    {
        $model = $this->makeReservation();

        $this->assertNull($model->getId());
        $this->assertTrue(
            is_string($model->getUid()) || is_null($model->getUid())
        );
    }

    public function testCustomerDataGettersReturnStrings(): void
    {
        $model = $this->makeReservation();

        $this->assertIsString($model->getUserName());
        $this->assertIsString($model->getUserEmail());
    }

    public function testBookingDetailsGettersReturnStrings(): void
    {
        $model = $this->makeReservation();

        $this->assertIsString($model->getBookingDate());
        $this->assertIsString($model->getStartTime());
        $this->assertIsString($model->getEndTime());
    }

    public function testQuantityGetterReturnsInt(): void
    {
        $model = $this->makeReservation();
        $this->assertIsInt($model->getQuantity());
    }

    public function testDurationGettersReturnInts(): void
    {
        $model = $this->makeReservation();

        $this->assertIsInt($model->getDurationMinutes());
        $this->assertIsInt($model->getTotalDuration());
    }

    public function testConflictsWithWorksBetweenModels(): void
    {
        $model1 = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $model2 = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30',
            'endTime' => '15:30',
        ]);

        $this->assertTrue($model1->conflictsWith($model2));
        $this->assertTrue($model2->conflictsWith($model1));
    }

    public function testNoConflictWhenNoOverlap(): void
    {
        $model1 = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $model2 = $this->makeReservation([
            'bookingDate' => '2025-06-15',
            'startTime' => '16:00',
            'endTime' => '17:00',
        ]);

        $this->assertFalse($model1->conflictsWith($model2));
        $this->assertFalse($model2->conflictsWith($model1));
    }

    // =========================================================================
    // Status Methods
    // =========================================================================

    public function testModelHasGetStatuses(): void
    {
        $statuses = Reservation::getStatuses();

        $this->assertIsArray($statuses);
        $this->assertNotEmpty($statuses);
    }

    public function testStatusLabelWorksForModel(): void
    {
        $model = $this->makeReservation(['status' => 'confirmed']);
        $this->assertIsString($model->getStatusLabel());
    }
}
