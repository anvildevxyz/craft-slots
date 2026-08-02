<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Guards the Slots → Booked upgrade path from this side.
 *
 * Booked ships `booked/import/from-slots`, which reads these tables and columns
 * directly. Slots and Booked are separate repos with separate release cycles, so
 * neither can depend on the other's code — instead each keeps a copy of the
 * contract and fails its own build when it drifts.
 *
 * Booked has the mirror of this test (`ImportFromSlotsTest`). If you change a
 * column named here, update both.
 *
 * Adding columns is always safe. Renaming, retyping or dropping one is what
 * breaks a customer mid-upgrade.
 *
 * One change since the importer was written that no column list can express:
 * every one of these tables is now keyed on `elements.id`, and a deleted record
 * is soft-deleted — the row stays exactly as it was and only `elements
 * .dateDeleted` is set. A reader going straight to these tables therefore sees
 * deleted bookings as live ones. Anything importing from here has to join
 * `elements` and skip rows where `dateDeleted` is not null.
 */
class ImportContractTest extends TestCase
{
    /** @var array<string, string[]> */
    private const CONTRACT = [
        'services' => [
            'description', 'duration', 'bufferBefore', 'bufferAfter', 'price',
            'minTimeBeforeBooking', 'timeSlotLength', 'availabilitySchedule', 'customerLimitEnabled',
            'customerLimitCount', 'customerLimitPeriod', 'customerLimitPeriodType', 'taxCategoryId',
            'allowCancellation', 'cancellationPolicyHours', 'allowRefund', 'refundTiers',
        ],
        'employees' => ['userId', 'locationId', 'email', 'workingHours', 'serviceIds'],
        'locations' => [
            'timezone', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea',
            'postalCode', 'countryCode',
        ],
        'schedules' => ['workingHours', 'startDate', 'endDate'],
        'blackout_dates' => ['title', 'startDate', 'endDate', 'isActive'],
        'service_locations' => ['serviceId', 'locationId'],
        'employee_schedule_assignments' => ['employeeId', 'scheduleId', 'sortOrder'],
        'service_schedule_assignments' => ['serviceId', 'scheduleId', 'sortOrder'],
        'blackout_dates_employees' => ['blackoutDateId', 'employeeId'],
        'blackout_dates_locations' => ['blackoutDateId', 'locationId'],
        'reservations' => [
            'userName', 'userEmail', 'userPhone', 'userId', 'userTimezone', 'bookingDate', 'startTime',
            'endTime', 'status', 'activeSlotKey', 'employeeId', 'locationId', 'serviceId', 'siteId',
            'quantity', 'notes', 'sessionNotes', 'notificationSent', 'emailReminder24hSent',
            'emailReminder1hSent', 'confirmationToken',
        ],
        'payments' => [
            'reservationId', 'gateway', 'externalId', 'status', 'amount', 'currency',
            'refundedAmount', 'payload',
        ],
    ];

    private static function installSource(): string
    {
        $path = dirname(__DIR__, 2) . '/src/migrations/Install.php';
        self::assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * @dataProvider tableProvider
     */
    public function testInstallStillDeclaresTheContractColumns(string $table, array $columns): void
    {
        $source = self::installSource();

        $start = strpos($source, "createTable('{{%slots_{$table}}}'");
        $this->assertNotFalse($start, "Install must still create slots_{$table} — Booked's importer reads it");

        $end = strpos($source, ']);', $start);
        $block = substr($source, $start, $end - $start);

        foreach ($columns as $column) {
            $this->assertStringContainsString(
                "'{$column}' =>",
                $block,
                "slots_{$table} must keep '{$column}' — Booked's importer copies it on upgrade",
            );
        }
    }

    public static function tableProvider(): array
    {
        $cases = [];
        foreach (self::CONTRACT as $table => $columns) {
            $cases[$table] = [$table, $columns];
        }

        return $cases;
    }

    /**
     * The importer cannot tell a trashed booking from a live one by reading
     * slots_reservations alone, because the soft-delete flag is not there. This
     * pins that fact: if a dateDeleted column is ever added to the table, the
     * guidance above is wrong and both sides need revisiting.
     */
    public function testDeletedStateLivesInTheElementsTableNotOurs(): void
    {
        $install = file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
        $reservations = substr(
            $install,
            strpos($install, "createTable('{{%slots_reservations}}'"),
            2000,
        );

        $this->assertStringNotContainsString(
            "'dateDeleted'",
            $reservations,
            'slots_reservations has no soft-delete flag of its own; consumers must join elements',
        );

        $this->assertStringContainsString(
            "addForeignKey(null, '{{%slots_reservations}}', 'id', '{{%elements}}', 'id', 'CASCADE'",
            $install,
            'The reservation key is an element id — that is what makes the join possible',
        );
    }

    public function testTablePrefixIsStillSlots(): void
    {
        $this->assertStringContainsString(
            '{{%slots_reservations}}',
            self::installSource(),
            "Booked's importer defaults to the 'slots_' prefix; changing it breaks the upgrade path",
        );
    }
}
