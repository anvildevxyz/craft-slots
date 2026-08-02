<?php

namespace anvildev\slots\services;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\helpers\DateHelper;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;
use DateTime;
use DateTimeZone;

/**
 * Calculates tiered refund percentages based on how far in advance a booking is cancelled.
 *
 * Tier resolution order:
 * 1. Entity-specific tiers (Service)
 * 2. Global default tiers from plugin settings
 * 3. 100% refund if no tiers are defined anywhere
 */
class RefundPolicyService extends Component
{
    /**
     * Calculate the refund percentage for a reservation based on its applicable tier configuration.
     */
    public function calculateRefundPercentage(ReservationInterface $reservation): int
    {
        $tiers = $this->resolveTiers($reservation);
        $hoursUntilBooking = $this->calculateHoursUntilBooking($reservation);

        return $this->calculatePercentageFromTiers($tiers, $hoursUntilBooking);
    }

    /**
     * Evaluate tiers to determine the refund percentage for a given number of hours before start.
     *
     * Tiers are sorted descending by hoursBeforeStart. The first tier where
     * hoursUntilBooking >= hoursBeforeStart wins. If no tier matches (hours below
     * all thresholds), the last tier's percentage is returned as a fallback.
     *
     * Returns 100 if no tiers are defined (full refund by default).
     */
    public function calculatePercentageFromTiers(?array $tiers, float $hoursUntilBooking): int
    {
        if (empty($tiers)) {
            return 100;
        }

        usort($tiers, fn($a, $b) => $b['hoursBeforeStart'] <=> $a['hoursBeforeStart']);

        foreach ($tiers as $tier) {
            if ($hoursUntilBooking >= $tier['hoursBeforeStart']) {
                return (int) $tier['refundPercentage'];
            }
        }

        return (int) end($tiers)['refundPercentage'];
    }

    /**
     * Resolve tiers in priority order: Service -> Settings default.
     */
    private function resolveTiers(ReservationInterface $reservation): ?array
    {
        // Check if refunds are disabled on the service
        $service = $reservation->getService();
        if ($service && $service->allowRefund === false) {
            return [['hoursBeforeStart' => 0, 'refundPercentage' => 0]];
        }

        // Check service
        if ($service && !empty($service->refundTiers)) {
            $tiers = $this->decodeTiers($service->refundTiers, 'service', $service->id);
            if ($tiers !== null) {
                return $tiers;
            }
        }

        // Fall back to global default
        $settings = Slots::getInstance()->getSettings();

        return $settings->defaultRefundTiers ?? null;
    }

    /**
     * Safely decode refund tiers from a string or array value.
     * Returns null on decode failure so the caller can fall through to the next tier source.
     */
    private function decodeTiers(mixed $value, string $entityType, int $entityId): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            Craft::warning(
                "Failed to decode refundTiers JSON for {$entityType} #{$entityId}: " . json_last_error_msg(),
                __METHOD__
            );
            return null;
        }

        return $decoded;
    }

    /**
     * Calculate hours remaining until the booking's start time.
     *
     * Returns 0 if the booking is in the past or cannot be parsed.
     */
    private function calculateHoursUntilBooking(ReservationInterface $reservation): float
    {
        $bookingDateTime = DateHelper::parseDateTime(
            $reservation->getBookingDate(),
            $reservation->getStartTime()
        );

        if (!$bookingDateTime) {
            return 0;
        }

        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $bookingDateTime->setTimezone($tz);
        $now = new DateTime('now', $tz);
        $diff = $now->diff($bookingDateTime);
        $hours = ($diff->days * 24) + $diff->h + ($diff->i / 60);

        return $diff->invert ? 0 : $hours;
    }
}
