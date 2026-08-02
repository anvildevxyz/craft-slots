<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\tests\Support\TestCase;

class BookingsControllerTest extends TestCase
{
    private string $controllerSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/controllers/cp/BookingsController.php'
        );
    }

    public function testActionSaveUsesScopedReservationForExistingBookings(): void
    {
        $this->assertStringNotContainsString(
            'ReservationFactory::find()->siteId(\'*\')->id($id)->one()',
            $this->controllerSource,
            'actionSave must not use unscoped ReservationFactory::find() for existing bookings — use findScopedReservation() instead'
        );
    }

    public function testActionSaveValidatesStatusAgainstWhitelist(): void
    {
        // Verify that actionSave validates the submitted status via in_array + getStatuses
        $this->assertStringContainsString(
            'ReservationRecord::getStatuses()',
            $this->controllerSource,
            'actionSave must validate status against ReservationRecord::getStatuses()'
        );
    }

    public function testActionUpdateStatusValidatesAgainstWhitelist(): void
    {
        // Both actionSave and actionUpdateStatus must validate — need at least 2 occurrences
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->controllerSource, 'ReservationRecord::getStatuses()'),
            'Both actionSave and actionUpdateStatus must validate status against ReservationRecord::getStatuses()'
        );
    }
}
