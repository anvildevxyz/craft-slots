<?php

namespace anvildev\slots\traits;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\helpers\DateHelper;
use anvildev\slots\Slots;

/**
 * Computed reservation values shared by the Element and ActiveRecord
 * implementations of {@see ReservationInterface}.
 */
trait HasReservationComputations
{
    public function getDurationMinutes(): int
    {
        $start = DateHelper::parseTime($this->startTime);
        $end = DateHelper::parseTime($this->endTime);

        if (!$start || !$end) {
            return 0;
        }

        $diff = $start->diff($end);
        return (int) ($diff->h * 60 + $diff->i);
    }

    public function conflictsWith(ReservationInterface $other): bool
    {
        if ($this->bookingDate !== $other->getBookingDate()) {
            return false;
        }

        $thisStartTime = DateHelper::parseTime($this->getStartTime());
        $thisEndTime = DateHelper::parseTime($this->getEndTime());
        $otherStartTime = DateHelper::parseTime($other->getStartTime());
        $otherEndTime = DateHelper::parseTime($other->getEndTime());

        if (!$thisStartTime || !$thisEndTime || !$otherStartTime || !$otherEndTime) {
            return false;
        }

        return !($thisEndTime->getTimestamp() <= $otherStartTime->getTimestamp()
            || $thisStartTime->getTimestamp() >= $otherEndTime->getTimestamp());
    }

    /**
     * The reservation's payment status, resolved by the payment service from
     * the native payments table — one query surface for CP columns and exports.
     */
    public function getPaymentStatus(): string
    {
        /** @var ReservationInterface $this */
        return Slots::getInstance()->getPayments()->getStatusForReservation($this);
    }

    public function getTotalPrice(): float
    {
        $service = $this->getService();
        $servicePrice = 0.0;

        if ($service && isset($service->price)) {
            $servicePrice = (float)$service->price * $this->quantity;
        }

        return $servicePrice;
    }
}
