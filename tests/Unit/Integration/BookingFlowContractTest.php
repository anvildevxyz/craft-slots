<?php

namespace anvildev\slots\tests\Unit\Integration;

use anvildev\slots\tests\Support\TestCase;

/**
 * Booking Flow Contract Test
 *
 * Smoke tests that verify the booking flow components exist and are properly connected.
 * These tests validate the contract between services without requiring database operations.
 */
class BookingFlowContractTest extends TestCase
{
    // =========================================================================
    // Core Service Existence Tests
    // =========================================================================

    public function testBookingServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\services\BookingService::class),
            'BookingService class should exist'
        );
    }

    public function testAvailabilityServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\services\AvailabilityService::class),
            'AvailabilityService class should exist'
        );
    }

    public function testBookingValidationServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\services\BookingValidationService::class),
            'BookingValidationService class should exist'
        );
    }

    public function testBookingSecurityServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\services\BookingSecurityService::class),
            'BookingSecurityService class should exist'
        );
    }

    public function testBookingNotificationServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\services\BookingNotificationService::class),
            'BookingNotificationService class should exist'
        );
    }

    public function testSoftLockServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\services\SoftLockService::class),
            'SoftLockService class should exist'
        );
    }

    public function testServiceElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Service.php',
            'Service element file should exist'
        );
    }

    public function testEmployeeElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Employee.php',
            'Employee element file should exist'
        );
    }

    public function testLocationElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Location.php',
            'Location element file should exist'
        );
    }

    public function testScheduleElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Schedule.php',
            'Schedule element file should exist'
        );
    }

    public function testBlackoutDateElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/BlackoutDate.php',
            'BlackoutDate element file should exist'
        );
    }

    public function testReservationRecordExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\records\ReservationRecord::class),
            'ReservationRecord class should exist'
        );
    }

    public function testSoftLockRecordExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\records\SoftLockRecord::class),
            'SoftLockRecord class should exist'
        );
    }

    public function testSendBookingEmailJobExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\queue\jobs\SendBookingEmailJob::class),
            'SendBookingEmailJob class should exist'
        );
    }

    public function testSendRemindersJobExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\queue\jobs\SendRemindersJob::class),
            'SendRemindersJob class should exist'
        );
    }

    // =========================================================================
    // Exception Type Existence Tests
    // =========================================================================

    public function testBookingExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\exceptions\BookingException::class),
            'BookingException class should exist'
        );
    }

    public function testBookingConflictExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\exceptions\BookingConflictException::class),
            'BookingConflictException class should exist'
        );
    }

    public function testBookingNotFoundExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\exceptions\BookingNotFoundException::class),
            'BookingNotFoundException class should exist'
        );
    }

    public function testBookingRateLimitExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\exceptions\BookingRateLimitException::class),
            'BookingRateLimitException class should exist'
        );
    }

    public function testBookingValidationExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\exceptions\BookingValidationException::class),
            'BookingValidationException class should exist'
        );
    }

    // =========================================================================
    // Controller Existence Tests
    // =========================================================================

    public function testBookingControllerExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\controllers\BookingController::class),
            'BookingController class should exist'
        );
    }

    public function testAccountControllerExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\controllers\AccountController::class),
            'AccountController class should exist'
        );
    }

    // =========================================================================
    // ReservationRecord Status Constants Tests
    // =========================================================================

    public function testReservationStatusPendingConstant(): void
    {
        $this->assertEquals(
            'pending',
            \anvildev\slots\records\ReservationRecord::STATUS_PENDING
        );
    }

    public function testReservationStatusConfirmedConstant(): void
    {
        $this->assertEquals(
            'confirmed',
            \anvildev\slots\records\ReservationRecord::STATUS_CONFIRMED
        );
    }

    public function testSecurityResultValidConstant(): void
    {
        $this->assertEquals(
            'valid',
            \anvildev\slots\services\BookingSecurityService::RESULT_VALID
        );
    }

    public function testSecurityResultIpBlockedConstant(): void
    {
        $this->assertEquals(
            'ip_blocked',
            \anvildev\slots\services\BookingSecurityService::RESULT_IP_BLOCKED
        );
    }

    public function testSecurityResultRateLimitedConstant(): void
    {
        $this->assertEquals(
            'rate_limited',
            \anvildev\slots\services\BookingSecurityService::RESULT_RATE_LIMITED
        );
    }

    // =========================================================================
    // Helper Class Existence Tests
    // =========================================================================

    public function testDateHelperExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\helpers\DateHelper::class),
            'DateHelper class should exist'
        );
    }

    public function testValidationHelperExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\helpers\ValidationHelper::class),
            'ValidationHelper class should exist'
        );
    }

    public function testIcsHelperExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\helpers\IcsHelper::class),
            'IcsHelper class should exist'
        );
    }

    // =========================================================================
    // Model Class Existence Tests
    // =========================================================================

    public function testSettingsModelExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\models\Settings::class),
            'Settings model class should exist'
        );
    }

    // =========================================================================
    // Plugin Class Existence Tests
    // =========================================================================

    public function testPluginClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\Slots::class),
            'Booked plugin class should exist'
        );
    }

    // =========================================================================
    // Service Method Existence Tests (Contract Verification)
    // =========================================================================

    public function testBookingServiceHasCreateReservationMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\BookingService::class, 'createReservation'),
            'BookingService should have createReservation() method'
        );
    }

    public function testBookingServiceHasCancelReservationMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\BookingService::class, 'cancelReservation'),
            'BookingService should have cancelReservation() method'
        );
    }

    public function testAvailabilityServiceHasGetAvailableSlotsMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\AvailabilityService::class, 'getAvailableSlots'),
            'AvailabilityService should have getAvailableSlots() method'
        );
    }

    public function testSoftLockServiceHasCreateLockMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\SoftLockService::class, 'createLock'),
            'SoftLockService should have createLock() method'
        );
    }

    public function testSoftLockServiceHasIsLockedMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\SoftLockService::class, 'isLocked'),
            'SoftLockService should have isLocked() method'
        );
    }

    public function testSoftLockServiceHasReleaseLockMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\SoftLockService::class, 'releaseLock'),
            'SoftLockService should have releaseLock() method'
        );
    }

    public function testCapacityServiceHasHasAvailableCapacityMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\slots\services\CapacityService::class, 'hasAvailableCapacity'),
            'CapacityService should have hasAvailableCapacity() method'
        );
    }
}
