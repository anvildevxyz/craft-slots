<?php

namespace anvildev\slots\traits;

use anvildev\slots\helpers\DateHelper;
use anvildev\slots\models\Settings;
use anvildev\slots\records\ReservationRecord;

/**
 * Shared cancellation policy logic for Reservation element, model, and record.
 *
 * Expects the using class to have: $status, $bookingDate, $startTime properties.
 * Optionally implements resolveCancellationPolicyHours() for per-entity overrides.
 *
 * Resolution order:
 * 1. Entity override (Service cancellationPolicyHours)
 * 2. Global Settings::cancellationPolicyHours (default 24)
 *
 * A value of 0 means cancellation is always allowed regardless of timing.
 */
trait HasCancellationPolicy
{
    protected function resolveCancellationPolicyHours(): ?int
    {
        if (property_exists($this, 'serviceId') && $this->serviceId && method_exists($this, 'getService')) {
            $service = $this->getService();
            if ($service && $service->cancellationPolicyHours !== null) {
                return $service->cancellationPolicyHours;
            }
        }

        return null;
    }

    public function canBeCancelled(): bool
    {
        if ($this->status === ReservationRecord::STATUS_CANCELLED) {
            return false;
        }

        // Check if cancellation is disabled on the service
        if (property_exists($this, 'serviceId') && $this->serviceId && method_exists($this, 'getService')) {
            $service = $this->getService();
            if ($service && $service->allowCancellation === false) {
                return false;
            }
        }

        // Resolve timezone from location, fall back to system timezone
        $systemTz = class_exists(\Craft::class, false) && \Craft::$app
            ? \Craft::$app->getTimeZone()
            : date_default_timezone_get();
        $timezone = new \DateTimeZone($systemTz);
        if (method_exists($this, 'getLocation')) {
            $location = $this->getLocation();
            if ($location && !empty($location->timezone)) {
                try {
                    $timezone = new \DateTimeZone($location->timezone);
                } catch (\Exception) {
                    // Invalid timezone, keep system default
                }
            }
        }

        // startTime should always be set; fall back to midnight defensively
        $startTime = $this->startTime ?? '00:00:00';
        $bookingDateTime = DateHelper::parseDateTime($this->bookingDate, $startTime);
        if (!$bookingDateTime) {
            return false;
        }

        // Convert booking time to location timezone for comparison
        $bookingDateTime->setTimezone($timezone);

        $now = new \DateTime('now', $timezone);

        // Block cancellation once the booking has started
        if ($bookingDateTime->getTimestamp() <= $now->getTimestamp()) {
            return false;
        }

        $entityPolicy = $this->resolveCancellationPolicyHours();

        $hoursBeforeCancellation = $entityPolicy ?? (Settings::loadSettings()->cancellationPolicyHours ?? 24);

        if ($hoursBeforeCancellation === 0) {
            return true;
        }

        $cutoffTime = new \DateTime('now', $timezone);
        $cutoffTime->modify("+{$hoursBeforeCancellation} hours");

        return $bookingDateTime->getTimestamp() > $cutoffTime->getTimestamp();
    }

    /**
     * Rescheduling is bound to the cancellation policy, and deliberately so.
     *
     * Moving a booking is cancelling it and taking a different slot, so any
     * window a customer cannot cancel inside is a window they must not be able
     * to move out of either — otherwise the policy is trivially defeated by
     * rescheduling to a far-off date and never showing up. The same argument
     * applies to a service with `allowCancellation` switched off.
     *
     * This deliberately never grants more than canBeCancelled() does. If
     * rescheduling should ever be allowed on its own terms, it needs its own
     * setting rather than a loosening of this.
     */
    public function canBeRescheduled(): bool
    {
        return $this->canBeCancelled();
    }
}
