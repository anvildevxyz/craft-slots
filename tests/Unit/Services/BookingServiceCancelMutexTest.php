<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\BookingService;
use anvildev\slots\tests\Support\TestCase;

class BookingServiceCancelMutexTest extends TestCase
{
    public function testCancelLockKeyForServiceBooking(): void
    {
        $date = '2026-03-15';
        $time = '14:00:00';
        $employeeId = 5;
        $serviceId = 10;
        $expected = "slots-booking-{$date}-{$time}-{$employeeId}-{$serviceId}";
        $this->assertEquals('slots-booking-2026-03-15-14:00:00-5-10', $expected);
    }
}
