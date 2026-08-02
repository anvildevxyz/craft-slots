<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\BookingSecurityService;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingSecurityService Test
 *
 * Tests the pure IP validation functions in BookingSecurityService
 */
class BookingSecurityServiceTest extends TestCase
{
    private BookingSecurityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingSecurityService();
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testResultConstants(): void
    {
        $this->assertEquals('valid', BookingSecurityService::RESULT_VALID);
        $this->assertEquals('ip_blocked', BookingSecurityService::RESULT_IP_BLOCKED);
        $this->assertEquals('rate_limited', BookingSecurityService::RESULT_RATE_LIMITED);
        $this->assertEquals('captcha_failed', BookingSecurityService::RESULT_CAPTCHA_FAILED);
        $this->assertEquals('spam_detected', BookingSecurityService::RESULT_SPAM_DETECTED);
    }

    // =========================================================================
    // isIpBlocked() Tests - Empty/Null Inputs
    // =========================================================================

    public function testIsIpBlockedWithNullBlocklist(): void
    {
        $result = $this->service->isIpBlocked('192.168.1.1', null);

        $this->assertFalse($result);
    }

    public function testIsIpBlockedWithEmptyBlocklist(): void
    {
        $result = $this->service->isIpBlocked('192.168.1.1', '');

        $this->assertFalse($result);
    }

    public function testIsIpBlockedWithEmptyJsonArray(): void
    {
        $result = $this->service->isIpBlocked('192.168.1.1', '[]');

        $this->assertFalse($result);
    }

    // =========================================================================
    // isIpBlocked() Tests - Exact Match (JSON format)
    // =========================================================================

    public function testIsIpBlockedExactMatchJson(): void
    {
        $blockedIps = '["192.168.1.100", "10.0.0.50"]';

        $this->assertTrue($this->service->isIpBlocked('192.168.1.100', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('10.0.0.50', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('192.168.1.1', $blockedIps));
    }

    public function testIsIpBlockedNotInListJson(): void
    {
        $blockedIps = '["192.168.1.100"]';

        $this->assertFalse($this->service->isIpBlocked('192.168.1.101', $blockedIps));
    }

    // =========================================================================
    // isIpBlocked() Tests - Exact Match (Newline-separated format)
    // =========================================================================

    public function testIsIpBlockedExactMatchNewline(): void
    {
        $blockedIps = "192.168.1.100\n10.0.0.50";

        $this->assertTrue($this->service->isIpBlocked('192.168.1.100', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('10.0.0.50', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('192.168.1.1', $blockedIps));
    }

    public function testIsIpBlockedNewlineWithWhitespace(): void
    {
        $blockedIps = "  192.168.1.100  \n  10.0.0.50  ";

        $this->assertTrue($this->service->isIpBlocked('192.168.1.100', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('10.0.0.50', $blockedIps));
    }

    public function testIsIpBlockedNewlineWithEmptyLines(): void
    {
        $blockedIps = "192.168.1.100\n\n10.0.0.50\n";

        $this->assertTrue($this->service->isIpBlocked('192.168.1.100', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('10.0.0.50', $blockedIps));
    }

    // =========================================================================
    // isIpBlocked() Tests - CIDR Notation
    // =========================================================================

    public function testIsIpBlockedCidrMatch(): void
    {
        $blockedIps = '["192.168.1.0/24"]';

        $this->assertTrue($this->service->isIpBlocked('192.168.1.1', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('192.168.1.255', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('192.168.2.1', $blockedIps));
    }

    public function testIsIpBlockedCidrNewlineFormat(): void
    {
        $blockedIps = "10.0.0.0/8\n172.16.0.0/12";

        $this->assertTrue($this->service->isIpBlocked('10.1.2.3', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('172.16.5.10', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('192.168.1.1', $blockedIps));
    }

    public function testIsIpBlockedMixedExactAndCidr(): void
    {
        $blockedIps = '["192.168.1.100", "10.0.0.0/24"]';

        $this->assertTrue($this->service->isIpBlocked('192.168.1.100', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('10.0.0.50', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('192.168.1.101', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('10.0.1.1', $blockedIps));
    }

    // =========================================================================
    // ipInCidr() Tests - IPv4
    // =========================================================================

    public function testIpInCidrIpv4ClassC(): void
    {
        // /24 = 256 addresses (192.168.1.0 - 192.168.1.255)
        $this->assertTrue($this->service->ipInCidr('192.168.1.0', '192.168.1.0/24'));
        $this->assertTrue($this->service->ipInCidr('192.168.1.1', '192.168.1.0/24'));
        $this->assertTrue($this->service->ipInCidr('192.168.1.128', '192.168.1.0/24'));
        $this->assertTrue($this->service->ipInCidr('192.168.1.255', '192.168.1.0/24'));
        $this->assertFalse($this->service->ipInCidr('192.168.2.0', '192.168.1.0/24'));
    }

    public function testIpInCidrIpv4ClassB(): void
    {
        // /16 = 65536 addresses (192.168.0.0 - 192.168.255.255)
        $this->assertTrue($this->service->ipInCidr('192.168.0.0', '192.168.0.0/16'));
        $this->assertTrue($this->service->ipInCidr('192.168.255.255', '192.168.0.0/16'));
        $this->assertFalse($this->service->ipInCidr('192.169.0.0', '192.168.0.0/16'));
    }

    public function testIpInCidrIpv4ClassA(): void
    {
        // /8 = 16,777,216 addresses (10.0.0.0 - 10.255.255.255)
        $this->assertTrue($this->service->ipInCidr('10.0.0.0', '10.0.0.0/8'));
        $this->assertTrue($this->service->ipInCidr('10.255.255.255', '10.0.0.0/8'));
        $this->assertFalse($this->service->ipInCidr('11.0.0.0', '10.0.0.0/8'));
    }

    public function testIpInCidrIpv4Slash32(): void
    {
        // /32 = single IP
        $this->assertTrue($this->service->ipInCidr('192.168.1.1', '192.168.1.1/32'));
        $this->assertFalse($this->service->ipInCidr('192.168.1.2', '192.168.1.1/32'));
    }

    public function testIpInCidrIpv4Slash0(): void
    {
        // /0 = all IPv4 addresses
        $this->assertTrue($this->service->ipInCidr('192.168.1.1', '0.0.0.0/0'));
        $this->assertTrue($this->service->ipInCidr('10.0.0.1', '0.0.0.0/0'));
    }

    public function testIpInCidrIpv4CommonRanges(): void
    {
        // Private network ranges
        $this->assertTrue($this->service->ipInCidr('10.50.100.200', '10.0.0.0/8'));
        $this->assertTrue($this->service->ipInCidr('172.16.50.100', '172.16.0.0/12'));
        $this->assertTrue($this->service->ipInCidr('172.31.255.255', '172.16.0.0/12'));
        $this->assertFalse($this->service->ipInCidr('172.32.0.0', '172.16.0.0/12'));
    }

    // =========================================================================
    // ipInCidr() Tests - IPv6
    // =========================================================================

    public function testIpInCidrIpv6Basic(): void
    {
        $this->assertTrue($this->service->ipInCidr('2001:db8::1', '2001:db8::/32'));
        $this->assertTrue($this->service->ipInCidr('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', '2001:db8::/32'));
        $this->assertFalse($this->service->ipInCidr('2001:db9::1', '2001:db8::/32'));
    }

    public function testIpInCidrIpv6Slash128(): void
    {
        // /128 = single IPv6 address
        $this->assertTrue($this->service->ipInCidr('2001:db8::1', '2001:db8::1/128'));
        $this->assertFalse($this->service->ipInCidr('2001:db8::2', '2001:db8::1/128'));
    }

    public function testIpInCidrIpv6Slash64(): void
    {
        // /64 = standard subnet
        $this->assertTrue($this->service->ipInCidr('2001:db8:85a3::1', '2001:db8:85a3::/64'));
        $this->assertTrue($this->service->ipInCidr('2001:db8:85a3::ffff:ffff:ffff:ffff', '2001:db8:85a3::/64'));
        $this->assertFalse($this->service->ipInCidr('2001:db8:85a4::1', '2001:db8:85a3::/64'));
    }

    // =========================================================================
    // ipInCidr() Tests - Edge Cases
    // =========================================================================

    public function testIpInCidrInvalidIpv4(): void
    {
        $this->assertFalse($this->service->ipInCidr('not-an-ip', '192.168.1.0/24'));
    }

    public function testIpInCidrInvalidSubnet(): void
    {
        $this->assertFalse($this->service->ipInCidr('192.168.1.1', 'not-a-subnet/24'));
    }

    public function testIpInCidrIpv4WithIpv6Subnet(): void
    {
        // IPv4 address should not match IPv6 subnet
        $this->assertFalse($this->service->ipInCidr('192.168.1.1', '2001:db8::/32'));
    }

    public function testIpInCidrIpv6WithIpv4Subnet(): void
    {
        // IPv6 address should not match IPv4 subnet
        $this->assertFalse($this->service->ipInCidr('2001:db8::1', '192.168.1.0/24'));
    }

    public function testIpInCidrInvalidMaskTooHigh(): void
    {
        $this->assertFalse($this->service->ipInCidr('192.168.1.1', '192.168.1.0/33'));
    }

    public function testIpInCidrInvalidMaskNegative(): void
    {
        $this->assertFalse($this->service->ipInCidr('192.168.1.1', '192.168.1.0/-1'));
    }

    // =========================================================================
    // isIpBlocked() Tests - Real-world Scenarios
    // =========================================================================

    public function testIsIpBlockedTypicalSpammerBlock(): void
    {
        $blockedIps = json_encode([
            '203.0.113.0/24',     // Known spam network
            '198.51.100.50',      // Individual spammer
            '2001:db8:bad::/48',  // IPv6 spam network
        ]);

        // Blocked addresses
        $this->assertTrue($this->service->isIpBlocked('203.0.113.42', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('198.51.100.50', $blockedIps));
        $this->assertTrue($this->service->isIpBlocked('2001:db8:bad::1', $blockedIps));

        // Allowed addresses
        $this->assertFalse($this->service->isIpBlocked('8.8.8.8', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('203.0.114.1', $blockedIps));
    }

    public function testIsIpBlockedLocalNetworkAllowed(): void
    {
        // Only block public IPs, allow local
        $blockedIps = '["203.0.113.0/24"]';

        $this->assertFalse($this->service->isIpBlocked('192.168.1.1', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('10.0.0.1', $blockedIps));
        $this->assertFalse($this->service->isIpBlocked('127.0.0.1', $blockedIps));
    }

    // =========================================================================
    // checkTimeBasedLimit() Tests - Minimum Submission Time
    // =========================================================================

    private function ensureCacheComponent(): void
    {
        $this->requiresCraft();

        if (!\Yii::$app->has('cache')) {
            \Yii::$app->set('cache', [
                'class' => \yii\caching\ArrayCache::class,
            ]);
        }
    }

    public function testCheckTimeBasedLimitAllowsFirstSubmission(): void
    {
        $this->ensureCacheComponent();

        $result = $this->service->checkTimeBasedLimit('192.168.1.1', 3);

        $this->assertTrue($result, 'First submission should always be allowed');
    }

    public function testCheckTimeBasedLimitRejectsImmediateSecondSubmission(): void
    {
        $this->ensureCacheComponent();
        $ip = '10.0.0.' . random_int(1, 254); // unique IP to avoid cross-test cache hits

        // First submission succeeds
        $this->assertTrue($this->service->checkTimeBasedLimit($ip, 3));

        // Immediate second submission (0 seconds elapsed) should be rejected
        $this->assertFalse(
            $this->service->checkTimeBasedLimit($ip, 3),
            'Instant re-submission within minimum time should be rejected'
        );
    }

    public function testCheckTimeBasedLimitAllowsDifferentIps(): void
    {
        $this->ensureCacheComponent();
        $ip1 = '10.1.1.' . random_int(1, 254);
        $ip2 = '10.2.2.' . random_int(1, 254);

        // First IP submits
        $this->assertTrue($this->service->checkTimeBasedLimit($ip1, 3));

        // Different IP should not be affected
        $this->assertTrue(
            $this->service->checkTimeBasedLimit($ip2, 3),
            'Different IP should not be rate-limited by another IP submission'
        );
    }

    public function testCheckTimeBasedLimitAllowsWhenMinimumIsZero(): void
    {
        // No Craft needed — returns true early before touching cache
        $this->assertTrue($this->service->checkTimeBasedLimit('10.3.3.1', 0));
        $this->assertTrue(
            $this->service->checkTimeBasedLimit('10.3.3.1', 0),
            'Zero minimum submission time should allow all submissions'
        );
    }

    public function testCheckTimeBasedLimitAllowsWhenMinimumIsNegative(): void
    {
        // No Craft needed — returns true early before touching cache
        $this->assertTrue($this->service->checkTimeBasedLimit('10.4.4.1', -1));
        $this->assertTrue(
            $this->service->checkTimeBasedLimit('10.4.4.1', -1),
            'Negative minimum submission time should allow all submissions'
        );
    }

    // =========================================================================
    // validateRequest() integration — Source-level structural checks
    // =========================================================================

    public function testValidateRequestChecksTimeBasedLimitsWhenEnabled(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingSecurityService.php'
        );

        $this->assertStringContainsString(
            'enableTimeBasedLimits',
            $source,
            'validateRequest must check enableTimeBasedLimits setting'
        );

        $this->assertStringContainsString(
            'minimumSubmissionTime',
            $source,
            'validateRequest must use minimumSubmissionTime setting'
        );

        $this->assertStringContainsString(
            'checkTimeBasedLimit',
            $source,
            'validateRequest must call checkTimeBasedLimit'
        );
    }

    public function testValidateRequestReturnsRateLimitedOnTooFastSubmission(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/BookingSecurityService.php'
        );

        // Verify the rate_limited result type is returned
        $this->assertStringContainsString(
            'RESULT_RATE_LIMITED',
            $source,
            'validateRequest must return RESULT_RATE_LIMITED when time-based check fails'
        );

        // Verify it logs the rate limit event
        $this->assertStringContainsString(
            "logRateLimit('time_based'",
            $source,
            'validateRequest must log time_based rate limit events'
        );
    }

    // =========================================================================
    // Settings model — minimumSubmissionTime configuration
    // =========================================================================

    public function testMinimumSubmissionTimeDefaultIs3Seconds(): void
    {
        $settings = new \anvildev\slots\models\Settings();

        $this->assertSame(3, $settings->minimumSubmissionTime);
    }

    public function testEnableTimeBasedLimitsEnabledByDefault(): void
    {
        $settings = new \anvildev\slots\models\Settings();

        $this->assertTrue($settings->enableTimeBasedLimits);
    }

    public function testMinimumSubmissionTimeHasMinZeroValidation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/models/Settings.php'
        );

        $this->assertStringContainsString(
            "'minimumSubmissionTime'], 'integer', 'min' => 0",
            $source,
            'minimumSubmissionTime must have min:0 validation rule'
        );
    }
}
