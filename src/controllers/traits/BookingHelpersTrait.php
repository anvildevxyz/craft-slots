<?php

namespace anvildev\slots\controllers\traits;

trait BookingHelpersTrait
{
    protected function closeSession(): void
    {
        $session = \Craft::$app->getSession();
        if ($session->getIsActive()) {
            $session->close();
        }
    }

    /**
     * IP-based rate limiting via cache. Returns true if the request is allowed.
     */
    protected function checkRateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): bool
    {
        $ipAddress = \Craft::$app->request->getUserIP();
        $throttleKey = $key . '_' . md5($ipAddress);
        $cache = \Craft::$app->getCache();
        $requestCount = (int)($cache->get($throttleKey) ?: 0);

        if ($requestCount >= $maxRequests) {
            return false;
        }

        $cache->set($throttleKey, $requestCount + 1, $windowSeconds);
        return true;
    }

    protected function validateDate(string $date): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    protected function normalizeQuantity($quantity): int
    {
        return max(1, min(10000, (int)($quantity ?? 1)));
    }

    protected function normalizeId($id): ?int
    {
        return ($id === null || $id === '') ? null : (int)$id;
    }
}
