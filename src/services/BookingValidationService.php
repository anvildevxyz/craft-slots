<?php

namespace anvildev\slots\services;

use anvildev\slots\elements\Service;
use anvildev\slots\helpers\DateHelper;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use Craft;
use craft\base\Component;

/**
 * Validates booking constraints: per-email and per-IP rate limiting,
 * customer booking limits per service (fixed or rolling periods),
 * and employee-service association checks.
 */
class BookingValidationService extends Component
{
    /**
     * Email rate limits use the database (ReservationRecord) for persistence — accurate
     * across restarts but slightly slower. Fails open on error.
     *
     * @deprecated Use checkAllRateLimits() instead for consistent rate limit checking.
     * @return bool True if allowed, false if rate limited
     */
    public function checkEmailRateLimit(string $email): bool
    {
        $settings = Slots::getInstance()->getSettings();

        if (!$settings->enableRateLimiting || (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'test')) {
            return true;
        }

        try {
            $bookingsToday = ReservationRecord::find()
                ->where(['userEmail' => $email])
                ->andWhere(['>=', 'dateCreated', DateHelper::today() . ' 00:00:00'])
                ->andWhere(['!=', 'status', ReservationRecord::STATUS_CANCELLED])
                ->count();

            return $bookingsToday < $settings->rateLimitPerEmail;
        } catch (\Exception $e) {
            Craft::warning("Could not check email rate limit: " . $e->getMessage(), __METHOD__);
            return true;
        }
    }

    /**
     * IP rate limits use the cache for persistence — fast but lost on cache flush.
     * Fails open on error to match email rate limit behavior.
     *
     * @deprecated Use checkAllRateLimits() instead for consistent rate limit checking.
     * @return bool True if allowed, false if rate limited
     */
    public function checkIPRateLimit(string $ipAddress): bool
    {
        $settings = Slots::getInstance()->getSettings();

        if (!$settings->enableIpBlocking || (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'test')) {
            return true;
        }

        $today = DateHelper::today();
        $cache = Craft::$app->getCache();
        $cacheKey = 'booking_ip_limit_' . md5($ipAddress);

        try {
            $todayStr = DateHelper::parseDate($today)->format('Y-m-d');
            $ipBookings = array_filter(
                $cache->get($cacheKey) ?: [],
                fn($timestamp) => (new \DateTime())->setTimestamp($timestamp)->format('Y-m-d') === $todayStr
            );

            if (count($ipBookings) >= $settings->rateLimitPerIp) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // Fail open on error, matching email rate limit behavior — a cache failure
            // should not block legitimate customers from booking.
            Craft::warning("IP rate limit check failed, allowing booking: " . $e->getMessage(), __METHOD__);
            return true;
        }
    }

    /**
     * Atomically check IP rate limit and record the booking attempt.
     */
    public function checkAndRecordIpBooking(string $ipAddress): bool
    {
        $settings = Slots::getInstance()->getSettings();

        if (!$settings->enableIpBlocking || (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'test')) {
            return true;
        }

        $mutex = Craft::$app->getMutex();
        $mutexKey = 'slots-ip-rate-' . md5($ipAddress);

        if (!$mutex->acquire($mutexKey, 2)) {
            return false;
        }

        try {
            $cache = Craft::$app->getCache();
            $cacheKey = 'booking_ip_limit_' . md5($ipAddress);

            $todayStr = DateHelper::parseDate(DateHelper::today())->format('Y-m-d');
            $ipBookings = array_filter(
                $cache->get($cacheKey) ?: [],
                fn($timestamp) => (new \DateTime())->setTimestamp($timestamp)->format('Y-m-d') === $todayStr
            );

            if (count($ipBookings) >= $settings->rateLimitPerIp) {
                return false;
            }

            $ipBookings[] = time();
            $cache->set($cacheKey, $ipBookings, 86400);

            return true;
        } catch (\Exception $e) {
            Craft::warning("IP rate limit check failed, blocking as precaution: " . $e->getMessage(), __METHOD__);
            return false;
        } finally {
            $mutex->release($mutexKey);
        }
    }

    /**
     * @param string $requestedDate Y-m-d format
     * @return bool True if allowed, false if limit exceeded
     */
    public function checkCustomerBookingLimit(string $customerEmail, Service $service, string $requestedDate = ''): bool
    {
        if (!$service->customerLimitEnabled || !$service->customerLimitCount) {
            return true;
        }

        $dateRange = $this->calculateLimitDateRange(
            $service->customerLimitPeriod ?? 'month',
            $service->customerLimitPeriodType ?? 'rolling',
            $requestedDate ?: date('Y-m-d')
        );

        return ReservationRecord::find()
            ->where(['userEmail' => $customerEmail])
            ->andWhere(['serviceId' => $service->id])
            ->andWhere(['>=', 'bookingDate', $dateRange['start']])
            ->andWhere(['<=', 'bookingDate', $dateRange['end']])
            ->andWhere(['!=', 'status', ReservationRecord::STATUS_CANCELLED])
            ->count() < $service->customerLimitCount;
    }

    /**
     * @param string $period 'day', 'week', 'month', or number of days
     * @param string $periodType 'fixed' or 'rolling'
     * @return array{start: string, end: string}
     */
    public function calculateLimitDateRange(string $period, string $periodType, string $referenceDate = ''): array
    {
        $ref = $referenceDate ? new \DateTime($referenceDate) : new \DateTime();

        if ($periodType === 'fixed') {
            return match ($period) {
                'day' => [
                    'start' => $ref->format('Y-m-d'),
                    'end' => $ref->format('Y-m-d'),
                ],
                'week' => [
                    'start' => (clone $ref)->modify('monday this week')->format('Y-m-d'),
                    'end' => (clone $ref)->modify('sunday this week')->format('Y-m-d'),
                ],
                'month' => [
                    'start' => $ref->format('Y-m-01'),
                    'end' => $ref->format('Y-m-t'),
                ],
                default => $this->window($ref, (int) $period ?: 30, halve: true),
            };
        }

        if ($period === 'month') {
            return [
                'start' => (clone $ref)->modify('-1 month')->format('Y-m-d'),
                'end' => $ref->format('Y-m-d'),
            ];
        }

        $days = match ($period) {
            'day' => 1,
            'week' => 7,
            default => (int) $period ?: 30,
        };

        return $this->window($ref, $days, halve: true);
    }

    /**
     * Check all rate limits (read-only — does NOT record the booking).
     * Call recordIpBooking() separately after a successful commit.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function checkAllRateLimits(?string $email, ?string $ipAddress): array
    {
        $settings = Slots::getInstance()->getSettings();
        $isTest = defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'test';

        if ($settings->enableRateLimiting && $email && !$isTest) {
            try {
                $bookingsToday = ReservationRecord::find()
                    ->where(['userEmail' => $email])
                    ->andWhere(['>=', 'dateCreated', DateHelper::today() . ' 00:00:00'])
                    ->andWhere(['!=', 'status', ReservationRecord::STATUS_CANCELLED])
                    ->count();

                if ($bookingsToday >= $settings->rateLimitPerEmail) {
                    return ['allowed' => false, 'reason' => 'email_rate_limit'];
                }
            } catch (\Exception $e) {
                Craft::warning("Could not check email rate limit: " . $e->getMessage(), __METHOD__);
            }
        }

        if ($settings->enableIpBlocking && $ipAddress && !$isTest) {
            try {
                $cache = Craft::$app->getCache();
                $cacheKey = 'booking_ip_limit_' . md5($ipAddress);
                $todayStr = DateHelper::parseDate(DateHelper::today())->format('Y-m-d');
                $ipBookings = array_filter(
                    $cache->get($cacheKey) ?: [],
                    fn($timestamp) => (new \DateTime())->setTimestamp($timestamp)->format('Y-m-d') === $todayStr
                );

                if (count($ipBookings) >= $settings->rateLimitPerIp) {
                    return ['allowed' => false, 'reason' => 'ip_rate_limit'];
                }
            } catch (\Exception $e) {
                Craft::warning("IP rate limit check failed, allowing booking: " . $e->getMessage(), __METHOD__);
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{start: string, end: string} */
    private function window(\DateTime $ref, int $days, bool $halve = false): array
    {
        if ($halve) {
            return [
                'start' => (clone $ref)->modify("-{$days} days")->format('Y-m-d'),
                'end' => $ref->format('Y-m-d'),
            ];
        }

        return [
            'start' => (clone $ref)->modify("-{$days} days")->format('Y-m-d'),
            'end' => (clone $ref)->modify("+{$days} days")->format('Y-m-d'),
        ];
    }
}
