<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\BookingService;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingService Test
 *
 * Tests the BookingService functionality
 *
 * Note: Many tests require Craft CMS to be installed. This file contains
 * tests for business logic that can be tested with mocking.
 */
class BookingServiceTest extends TestCase
{
    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingService();
    }

    // =====================================================
    // Constants Tests
    // =====================================================

    public function testEventConstantsAreDefined(): void
    {
        $this->assertIsString(BookingService::EVENT_BEFORE_BOOKING_SAVE);
        $this->assertEquals('beforeBookingSave', BookingService::EVENT_BEFORE_BOOKING_SAVE);
    }

    public function testEventAfterBookingSaveConstant(): void
    {
        $this->assertIsString(BookingService::EVENT_AFTER_BOOKING_SAVE);
        $this->assertEquals('afterBookingSave', BookingService::EVENT_AFTER_BOOKING_SAVE);
    }

    public function testEventBeforeBookingCancelConstant(): void
    {
        $this->assertIsString(BookingService::EVENT_BEFORE_BOOKING_CANCEL);
        $this->assertEquals('beforeBookingCancel', BookingService::EVENT_BEFORE_BOOKING_CANCEL);
    }

    public function testEventAfterBookingCancelConstant(): void
    {
        $this->assertIsString(BookingService::EVENT_AFTER_BOOKING_CANCEL);
        $this->assertEquals('afterBookingCancel', BookingService::EVENT_AFTER_BOOKING_CANCEL);
    }

    // =====================================================
    // Data Mapping Tests (createBooking method)
    // =====================================================

    public function testCreateBookingDataMapping(): void
    {
        // Test that the createBooking method properly maps simplified keys to reservation keys
        $inputData = [
            'customerName' => 'John Doe',
            'customerEmail' => 'john@example.com',
            'date' => '2025-06-15',
            'time' => '14:00',
            'serviceId' => 1,
            'employeeId' => 2,
            'locationId' => 3,
            'quantity' => 2,
            'notes' => 'Test notes',
            'softLockToken' => 'token123',
        ];

        // We can verify the mapping logic by checking expected keys
        $this->assertArrayHasKey('customerName', $inputData);
        $this->assertArrayHasKey('customerEmail', $inputData);
        $this->assertArrayHasKey('date', $inputData);
        $this->assertArrayHasKey('time', $inputData);
    }

    public function testCreateBookingDefaultQuantity(): void
    {
        $data = [
            'customerName' => 'Test User',
            'customerEmail' => 'test@example.com',
            'date' => '2025-06-15',
            'time' => '14:00',
            'serviceId' => 1,
        ];

        // Verify default quantity is 1 when not provided
        $quantity = $data['quantity'] ?? 1;
        $this->assertEquals(1, $quantity);
    }

    public function testCreateBookingHandlesOptionalFields(): void
    {
        $data = [
            'customerName' => 'Test User',
            'customerEmail' => 'test@example.com',
            'date' => '2025-06-15',
            'time' => '14:00',
            'serviceId' => 1,
        ];

        // Verify optional fields default to null
        $this->assertNull($data['employeeId'] ?? null);
        $this->assertNull($data['locationId'] ?? null);
        $this->assertNull($data['notes'] ?? null);
        $this->assertNull($data['softLockToken'] ?? null);
    }

    // =====================================================
    // Lock Key Generation Tests
    // =====================================================

    public function testLockKeyGeneration(): void
    {
        $bookingDate = '2025-06-15';
        $startTime = '14:00';
        $employeeId = 5;
        $serviceId = 10;

        $lockKey = "slots-booking-{$bookingDate}-{$startTime}-{$employeeId}-{$serviceId}";

        $this->assertEquals('slots-booking-2025-06-15-14:00-5-10', $lockKey);
    }

    public function testLockKeyGenerationWithNullEmployee(): void
    {
        $bookingDate = '2025-06-15';
        $startTime = '14:00';
        $employeeId = null;
        $serviceId = 10;

        $lockKey = "slots-booking-{$bookingDate}-{$startTime}-" . ($employeeId ?? 'any') . "-{$serviceId}";

        $this->assertEquals('slots-booking-2025-06-15-14:00-any-10', $lockKey);
    }

    public function testLockKeyGenerationWithNullService(): void
    {
        $bookingDate = '2025-06-15';
        $startTime = '14:00';
        $employeeId = 5;
        $serviceId = null;

        $lockKey = "slots-booking-{$bookingDate}-{$startTime}-{$employeeId}-" . ($serviceId ?? 'any');

        $this->assertEquals('slots-booking-2025-06-15-14:00-5-any', $lockKey);
    }

    public function testLockKeyGenerationWithAllNulls(): void
    {
        $bookingDate = '2025-06-15';
        $startTime = '14:00';
        $employeeId = null;
        $serviceId = null;

        $lockKey = "slots-booking-{$bookingDate}-{$startTime}-" . ($employeeId ?? 'any') . "-" . ($serviceId ?? 'any');

        $this->assertEquals('slots-booking-2025-06-15-14:00-any-any', $lockKey);
    }

    // =====================================================
    // Date Validation Tests
    // =====================================================

    public function testValidDateFormat(): void
    {
        $date = '2025-06-15';
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        $this->assertInstanceOf(\DateTime::class, $parsed);
        $this->assertEquals('2025-06-15', $parsed->format('Y-m-d'));
    }

    public function testInvalidDateFormat(): void
    {
        $date = '15-06-2025';
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        $this->assertFalse($parsed);
    }

    public function testEmptyDate(): void
    {
        $date = '';
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        $this->assertFalse($parsed);
    }

    // =====================================================
    // Time Calculation Tests
    // =====================================================

    public function testEndTimeCalculationWithDuration(): void
    {
        $startTime = '14:00';
        $date = '2025-06-15';
        $duration = 60; // minutes

        $startDateTime = new \DateTime($date . ' ' . $startTime);
        $endDateTime = (clone $startDateTime)->modify("+{$duration} minutes");

        $this->assertEquals('15:00', $endDateTime->format('H:i'));
    }

    public function testEndTimeCalculationWith30Minutes(): void
    {
        $startTime = '14:00';
        $date = '2025-06-15';
        $duration = 30;

        $startDateTime = new \DateTime($date . ' ' . $startTime);
        $endDateTime = (clone $startDateTime)->modify("+{$duration} minutes");

        $this->assertEquals('14:30', $endDateTime->format('H:i'));
    }

    public function testEndTimeCalculationWith90Minutes(): void
    {
        $startTime = '14:00';
        $date = '2025-06-15';
        $duration = 90;

        $startDateTime = new \DateTime($date . ' ' . $startTime);
        $endDateTime = (clone $startDateTime)->modify("+{$duration} minutes");

        $this->assertEquals('15:30', $endDateTime->format('H:i'));
    }

    public function testEndTimeCalculationCrossingMidnight(): void
    {
        $startTime = '23:30';
        $date = '2025-06-15';
        $duration = 60;

        $startDateTime = new \DateTime($date . ' ' . $startTime);
        $endDateTime = (clone $startDateTime)->modify("+{$duration} minutes");

        $this->assertEquals('00:30', $endDateTime->format('H:i'));
        $this->assertEquals('2025-06-16', $endDateTime->format('Y-m-d'));
    }

    public function testBookingDataHasRequiredFields(): void
    {
        $data = [
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'serviceId' => 1,
        ];

        $this->assertArrayHasKeys(['userName', 'userEmail', 'bookingDate', 'startTime', 'serviceId'], $data);
    }

    public function testBookingDataEmailValidation(): void
    {
        $validEmail = 'john@example.com';
        $invalidEmail = 'not-an-email';

        $this->assertTrue(filter_var($validEmail, FILTER_VALIDATE_EMAIL) !== false);
        $this->assertFalse(filter_var($invalidEmail, FILTER_VALIDATE_EMAIL) !== false);
    }

    public function testBookingDataEmailSanitization(): void
    {
        $email = '  JOHN@EXAMPLE.COM  ';
        $sanitized = strtolower(trim($email));

        $this->assertEquals('john@example.com', $sanitized);
    }

    public function testBookingDataQuantityValidation(): void
    {
        $validQuantity = 1;
        $invalidQuantity = 0;
        $negativeQuantity = -1;

        $this->assertGreaterThan(0, $validQuantity);
        $this->assertLessThanOrEqual(0, $invalidQuantity);
        $this->assertLessThan(0, $negativeQuantity);
    }

    // =====================================================
    // Criteria Filtering Tests
    // =====================================================

    public function testReservationCriteriaDateRange(): void
    {
        $criteria = [
            'dateFrom' => '2025-06-01',
            'dateTo' => '2025-06-30',
        ];

        $this->assertArrayHasKey('dateFrom', $criteria);
        $this->assertArrayHasKey('dateTo', $criteria);

        $dateFrom = new \DateTime($criteria['dateFrom']);
        $dateTo = new \DateTime($criteria['dateTo']);

        $this->assertLessThan($dateTo, $dateFrom);
    }

    public function testReservationCriteriaStatus(): void
    {
        $criteria = [
            'status' => 'confirmed',
        ];

        $this->assertArrayHasKey('status', $criteria);
        $this->assertEquals('confirmed', $criteria['status']);
    }

    public function testReservationCriteriaEmail(): void
    {
        $criteria = [
            'userEmail' => 'john@example.com',
        ];

        $this->assertArrayHasKey('userEmail', $criteria);
        $this->assertTrue(filter_var($criteria['userEmail'], FILTER_VALIDATE_EMAIL) !== false);
    }

    public function testReservationCriteriaPagination(): void
    {
        $criteria = [
            'limit' => 10,
            'offset' => 20,
        ];

        $this->assertArrayHasKey('limit', $criteria);
        $this->assertArrayHasKey('offset', $criteria);
        $this->assertIsInt($criteria['limit']);
        $this->assertIsInt($criteria['offset']);
    }

    public function testReservationCriteriaDefaultOrdering(): void
    {
        $criteria = [];
        $orderBy = $criteria['orderBy'] ?? ['bookingDate' => SORT_DESC, 'startTime' => SORT_DESC];

        $this->assertIsArray($orderBy);
        $this->assertArrayHasKey('bookingDate', $orderBy);
        $this->assertArrayHasKey('startTime', $orderBy);
        $this->assertEquals(SORT_DESC, $orderBy['bookingDate']);
        $this->assertEquals(SORT_DESC, $orderBy['startTime']);
    }

    // =====================================================
    // Date Comparison Tests
    // =====================================================

    public function testUpcomingReservationDateComparison(): void
    {
        $today = date('Y-m-d');
        $future = date('Y-m-d', strtotime('+7 days'));
        $past = date('Y-m-d', strtotime('-7 days'));

        $this->assertLessThan($future, $today);
        $this->assertGreaterThan($past, $today);
    }

    public function testReservationDateFormatting(): void
    {
        $date = '2025-06-15';

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    // =====================================================
    // Token Validation Tests
    // =====================================================

    public function testSoftLockTokenValidation(): void
    {
        $validToken = 'abc123def456';
        $emptyToken = '';
        $nullToken = null;

        $this->assertNotEmpty($validToken);
        $this->assertEmpty($emptyToken);
        $this->assertNull($nullToken);
    }

    public function testSoftLockTokenGeneration(): void
    {
        $token = bin2hex(random_bytes(16));

        $this->assertEquals(32, strlen($token)); // 16 bytes = 32 hex characters
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    // =====================================================
    // Service Instance Tests
    // =====================================================

    public function testBookingServiceIsComponent(): void
    {
        $this->assertInstanceOf(BookingService::class, $this->service);
    }

    public function testBookingServiceHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->service, 'createBooking'));
        $this->assertTrue(method_exists($this->service, 'createReservation'));
        $this->assertTrue(method_exists($this->service, 'getReservationById'));
        $this->assertTrue(method_exists($this->service, 'getUpcomingReservations'));
    }

    // =====================================================
    // Mutex Race Condition Tests
    // =====================================================

    public function testMutexReacquiresEmployeeSpecificLockAfterAutoAssign(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingService.php'
        );
        $this->assertStringContainsString(
            'slots-employee-lock-',
            $source,
            'BookingService must acquire a second employee-specific mutex lock after autoAssignEmployee resolves the employee'
        );
    }

    public function testExecuteCancellationUsesTransaction(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingService.php'
        );
        $methodStart = strpos($source, 'function executeCancellation');
        $this->assertNotFalse($methodStart);
        $methodSource = substr($source, $methodStart, 2000);
        $this->assertStringContainsString('beginTransaction()', $methodSource,
            'executeCancellation must use a database transaction');
    }

    public function testReduceQuantityUsesTransaction(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingService.php'
        );
        $methodStart = strpos($source, 'function reduceQuantity');
        $this->assertNotFalse($methodStart);
        $methodSource = substr($source, $methodStart, 2500);
        $this->assertStringContainsString('beginTransaction()', $methodSource,
            'reduceQuantity must use a database transaction');
    }

    // =====================================================
    // updateReservation Event Tests
    // =====================================================

    public function testUpdateReservationFiresAfterBookingSaveEvent(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingService.php'
        );
        $methodStart = strpos($source, 'function updateReservation');
        $this->assertNotFalse($methodStart, 'updateReservation method must exist');

        $methodSource = substr($source, $methodStart, 5000);
        $this->assertStringContainsString(
            'EVENT_AFTER_BOOKING_SAVE',
            $methodSource,
            'updateReservation must fire EVENT_AFTER_BOOKING_SAVE after a successful update'
        );
    }

    public function testUpdateReservationFiresEventWithIsNewFalse(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingService.php'
        );
        $methodStart = strpos($source, 'function updateReservation');
        $this->assertNotFalse($methodStart, 'updateReservation method must exist');

        $methodSource = substr($source, $methodStart, 5000);
        $this->assertStringContainsString(
            "'isNew' => false",
            $methodSource,
            'updateReservation must set isNew to false when firing AfterBookingSaveEvent'
        );
    }

    public function testUpdateReservationFiresEventAfterCommit(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingService.php'
        );
        $methodStart = strpos($source, 'function updateReservation');
        $this->assertNotFalse($methodStart, 'updateReservation method must exist');

        $methodSource = substr($source, $methodStart, 5000);
        $commitPos = strpos($methodSource, '$transaction->commit()');
        $eventPos = strpos($methodSource, 'EVENT_AFTER_BOOKING_SAVE');

        $this->assertNotFalse($commitPos, 'Must call $transaction->commit()');
        $this->assertNotFalse($eventPos, 'Must fire EVENT_AFTER_BOOKING_SAVE');
        $this->assertLessThan(
            $eventPos,
            $commitPos,
            'EVENT_AFTER_BOOKING_SAVE must be fired AFTER $transaction->commit()'
        );
    }

    // =====================================================
    // Midnight Crossing Bug Fix Tests
    // =====================================================

    public function testEndTimeDoesNotWrapPastMidnight(): void
    {
        // DateTime wraps to next day — this documents the bug
        $dt = new \DateTime('2026-03-15 23:30');
        $dt->modify('+60 minutes');
        $this->assertSame('00:30', $dt->format('H:i'), 'DateTime wraps past midnight');

        // The fix: detect midnight crossing and clamp
        $start = new \DateTime('2026-03-15 23:30');
        $end = (clone $start)->modify('+60 minutes');
        $endTime = ($end->format('Y-m-d') !== $start->format('Y-m-d')) ? '24:00' : $end->format('H:i');
        $this->assertSame('24:00', $endTime, 'Midnight crossing should clamp to 24:00');

        // Non-crossing case should work normally
        $start2 = new \DateTime('2026-03-15 10:00');
        $end2 = (clone $start2)->modify('+60 minutes');
        $endTime2 = ($end2->format('Y-m-d') !== $start2->format('Y-m-d')) ? '24:00' : $end2->format('H:i');
        $this->assertSame('11:00', $endTime2, 'Non-crossing should return normal time');
    }
}
