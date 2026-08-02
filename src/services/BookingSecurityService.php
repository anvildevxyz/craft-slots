<?php

namespace anvildev\slots\services;

use anvildev\slots\Slots;
use Craft;
use craft\base\Component;

/**
 * Validates booking request security through IP blocking, time-based rate limiting,
 * CAPTCHA verification, and honeypot spam detection.
 */
class BookingSecurityService extends Component
{
    public const RESULT_VALID = 'valid';
    public const RESULT_IP_BLOCKED = 'ip_blocked';
    public const RESULT_RATE_LIMITED = 'rate_limited';
    public const RESULT_CAPTCHA_FAILED = 'captcha_failed';
    public const RESULT_SPAM_DETECTED = 'spam_detected';

    public function validateRequest(
        ?string $ipAddress = null,
        ?string $captchaToken = null,
        ?string $honeypotValue = null,
        bool $skipCaptcha = false,
    ): array {
        $settings = Slots::getInstance()->getSettings();
        $audit = Slots::getInstance()->getAudit();

        if ($settings->enableIpBlocking && $ipAddress && $this->isIpBlocked($ipAddress, $settings->blockedIps)) {
            Craft::warning("Booking blocked: IP address {$ipAddress} is blocked", __METHOD__);
            $audit->logRateLimit('ip_blocked', ['ip' => $ipAddress]);
            return ['valid' => false, 'error' => Craft::t('slots', 'booking.blocked'), 'errorType' => self::RESULT_IP_BLOCKED];
        }

        if ($settings->enableTimeBasedLimits && $ipAddress && !$this->checkTimeBasedLimit($ipAddress, $settings->minimumSubmissionTime ?? 0)) {
            Craft::warning("Booking blocked: Time-based limit exceeded for IP {$ipAddress}", __METHOD__);
            $audit->logRateLimit('time_based', ['ip' => $ipAddress]);
            return ['valid' => false, 'error' => Craft::t('slots', 'booking.tooFast'), 'errorType' => self::RESULT_RATE_LIMITED];
        }

        if (!$skipCaptcha && $settings->enableCaptcha && $settings->captchaProvider) {
            if (empty($captchaToken)) {
                Craft::warning('Booking blocked: CAPTCHA token is missing', __METHOD__);
                $audit->logRateLimit('captcha_missing', []);
                return ['valid' => false, 'error' => Craft::t('slots', 'booking.captchaFailed'), 'errorType' => self::RESULT_CAPTCHA_FAILED];
            }
            if (!$this->verifyCaptcha($captchaToken, $ipAddress)) {
                Craft::warning('Booking blocked: CAPTCHA verification failed', __METHOD__);
                $audit->logRateLimit('captcha_failed', []);
                return ['valid' => false, 'error' => Craft::t('slots', 'booking.captchaFailed'), 'errorType' => self::RESULT_CAPTCHA_FAILED];
            }
        }

        if ($settings->enableHoneypot && !empty($honeypotValue)) {
            Craft::warning('Booking blocked: Honeypot field was filled (spam bot detected)', __METHOD__);
            $audit->logRateLimit('honeypot_triggered', []);
            return ['valid' => false, 'error' => Craft::t('slots', 'booking.createFailed'), 'errorType' => self::RESULT_SPAM_DETECTED];
        }

        return ['valid' => true, 'error' => null, 'errorType' => self::RESULT_VALID];
    }

    public function isIpBlocked(string $ipAddress, ?string $blockedIps): bool
    {
        if (empty($blockedIps)) {
            return false;
        }

        $blockedList = json_decode($blockedIps, true);
        if (!is_array($blockedList)) {
            $blockedList = array_filter(array_map('trim', explode("\n", $blockedIps)));
        }

        foreach ($blockedList as $blockedIp) {
            $blockedIp = trim($blockedIp);
            if ($blockedIp === '') {
                continue;
            }
            if (str_contains($blockedIp, '/') ? $this->ipInCidr($ipAddress, $blockedIp) : $ipAddress === $blockedIp) {
                return true;
            }
        }

        return false;
    }

    public function ipInCidr(string $ipAddress, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr);

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->ipv4InCidr($ipAddress, $subnet, (int)$mask);
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6InCidr($ipAddress, $subnet, (int)$mask);
        }

        return false;
    }

    private function ipv4InCidr(string $ipAddress, string $subnet, int $mask): bool
    {
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask < 0 || $mask > 32) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        return (ip2long($ipAddress) & $maskLong) === (ip2long($subnet) & $maskLong);
    }

    private function ipv6InCidr(string $ipAddress, string $subnet, int $mask): bool
    {
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || $mask < 0 || $mask > 128) {
            return false;
        }

        $ipBin = inet_pton($ipAddress);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $bytes = intval($mask / 8);
        $bits = $mask % 8;

        for ($i = 0; $i < $bytes; $i++) {
            if ($ipBin[$i] !== $subnetBin[$i]) {
                return false;
            }
        }

        if ($bits > 0) {
            $maskByte = 0xFF << (8 - $bits);
            if ((ord($ipBin[$bytes]) & $maskByte) !== (ord($subnetBin[$bytes]) & $maskByte)) {
                return false;
            }
        }

        return true;
    }

    public function checkTimeBasedLimit(string $ipAddress, int $minimumSeconds): bool
    {
        if ($minimumSeconds <= 0) {
            return true;
        }

        $cache = Craft::$app->cache;
        $normalizedIp = inet_ntop(inet_pton($ipAddress)) ?: $ipAddress;
        $cacheKey = 'booking_time_limit_' . md5($normalizedIp);
        $lastSubmission = $cache->get($cacheKey);

        if ($lastSubmission !== false && (time() - $lastSubmission) < $minimumSeconds) {
            return false;
        }

        // Intentional eager-recording: the submission timestamp is stored BEFORE the booking
        // action completes. This ensures the audit trail captures the attempt even if the
        // subsequent booking save fails, preventing rapid-fire abuse of failed submissions.
        // The tradeoff is that a legitimate user whose booking fails due to an unrelated error
        // will still be subject to the time-based rate limit for their next attempt.
        $cache->set($cacheKey, time(), $minimumSeconds * 2);
        return true;
    }

    public function verifyCaptcha(string $token, ?string $ipAddress = null): bool
    {
        return Slots::getInstance()->getCaptcha()->verify($token, $ipAddress);
    }

    public function getHoneypotFieldName(): ?string
    {
        $settings = Slots::getInstance()->getSettings();
        return $settings->enableHoneypot ? $settings->honeypotFieldName : null;
    }
}
