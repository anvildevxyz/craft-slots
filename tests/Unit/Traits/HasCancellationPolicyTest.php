<?php

namespace anvildev\slots\tests\Unit\Traits;

use anvildev\slots\records\ReservationRecord;
use anvildev\slots\tests\Support\TestCase;
use anvildev\slots\traits\HasCancellationPolicy;

class HasCancellationPolicyTest extends TestCase
{
    private function makeReservation(array $overrides = []): object
    {
        return new class($overrides) {
            use HasCancellationPolicy;

            public string $status;
            public string $bookingDate;
            public string $startTime;
            public ?int $serviceId;
            private ?int $policyOverride;

            public function __construct(array $overrides)
            {
                $this->status = $overrides['status'] ?? ReservationRecord::STATUS_CONFIRMED;
                $this->bookingDate = $overrides['bookingDate'] ?? (new \DateTime('+30 days'))->format('Y-m-d');
                $this->startTime = $overrides['startTime'] ?? '10:00:00';
                $this->serviceId = $overrides['serviceId'] ?? null;
                $this->policyOverride = $overrides['policyOverride'] ?? null;
            }

            protected function resolveCancellationPolicyHours(): ?int
            {
                return $this->policyOverride;
            }
        };
    }

    public function testCancelledReservationCannotBeCancelled(): void
    {
        $r = $this->makeReservation(['status' => ReservationRecord::STATUS_CANCELLED]);
        $this->assertFalse($r->canBeCancelled());
    }

    public function testFutureReservationCanBeCancelled(): void
    {
        $r = $this->makeReservation([
            'bookingDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
        ]);
        $this->assertTrue($r->canBeCancelled());
    }

    public function testEntityPolicyOverridesGlobal(): void
    {
        // Entity says 720 hours (30 days) — booking is only 2 days out, should be blocked
        $r = $this->makeReservation([
            'bookingDate' => (new \DateTime('+2 days'))->format('Y-m-d'),
            'policyOverride' => 720,
        ]);
        $this->assertFalse($r->canBeCancelled());
    }

    public function testEntityPolicyZeroAlwaysAllowsCancellation(): void
    {
        // Entity says 0 = always cancellable, even if booking is soon (but still in future)
        $r = $this->makeReservation([
            'bookingDate' => (new \DateTime('+2 hours'))->format('Y-m-d'),
            'startTime' => (new \DateTime('+2 hours'))->format('H:i:s'),
            'policyOverride' => 0,
        ]);
        $this->assertTrue($r->canBeCancelled());
    }

    public function testPastBookingCannotBeCancelled(): void
    {
        $r = $this->makeReservation([
            'bookingDate' => (new \DateTime('-1 day'))->format('Y-m-d'),
            'startTime' => '10:00:00',
        ]);
        $this->assertFalse($r->canBeCancelled());
    }

    public function testBookingStartingNowCannotBeCancelled(): void
    {
        $r = $this->makeReservation([
            'bookingDate' => (new \DateTime())->format('Y-m-d'),
            'startTime' => (new \DateTime('-1 minute'))->format('H:i:s'),
        ]);
        $this->assertFalse($r->canBeCancelled());
    }

    public function testNullEntityPolicyFallsBackToGlobal(): void
    {
        // policyOverride = -1 → resolveCancellationPolicyHours returns null → use global
        $r = $this->makeReservation([
            'bookingDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
        ]);
        $this->assertTrue($r->canBeCancelled());
    }

    private function makeReservationWithLocation(array $overrides = [], ?string $locationTimezone = null): object
    {
        return new class($overrides, $locationTimezone) {
            use HasCancellationPolicy;

            public string $status;
            public string $bookingDate;
            public string $startTime;
            public ?int $serviceId;
            private ?int $policyOverride;
            private ?string $locationTimezone;

            public function __construct(array $overrides, ?string $locationTimezone)
            {
                $this->status = $overrides['status'] ?? ReservationRecord::STATUS_CONFIRMED;
                $this->bookingDate = $overrides['bookingDate'] ?? (new \DateTime('+30 days'))->format('Y-m-d');
                $this->startTime = $overrides['startTime'] ?? '10:00:00';
                $this->serviceId = $overrides['serviceId'] ?? null;
                $this->policyOverride = $overrides['policyOverride'] ?? null;
                $this->locationTimezone = $locationTimezone;
            }

            public function getLocation(): ?object
            {
                if ($this->locationTimezone === null) {
                    return null;
                }

                return (object) ['timezone' => $this->locationTimezone];
            }

            protected function resolveCancellationPolicyHours(): ?int
            {
                return $this->policyOverride;
            }
        };
    }

    public function testTimezoneResolvedFromLocation(): void
    {
        // A booking at 10:00 tomorrow in Pacific/Auckland (UTC+12/+13).
        // The method should use the location timezone for all comparisons.
        $r = $this->makeReservationWithLocation([
            'bookingDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
            'startTime' => '10:00:00',
        ], 'Pacific/Auckland');

        $this->assertTrue($r->canBeCancelled());
    }

    public function testInvalidLocationTimezoneFallsBackToSystem(): void
    {
        // An invalid timezone should not cause an error — falls back to system timezone
        $r = $this->makeReservationWithLocation([
            'bookingDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
        ], 'Invalid/Timezone');

        $this->assertTrue($r->canBeCancelled());
    }

    public function testNullLocationReturnsUsesSystemTimezone(): void
    {
        // getLocation() returns null — should use system timezone
        $r = $this->makeReservationWithLocation([
            'bookingDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
        ]);

        $this->assertTrue($r->canBeCancelled());
    }
}
