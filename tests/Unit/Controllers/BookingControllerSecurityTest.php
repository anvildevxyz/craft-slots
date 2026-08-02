<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\services\BookingSecurityService;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingController Security Tests
 *
 * Verifies rate limiting returns 429 and IP blocking rejects bookings
 * at the controller level through source-level analysis.
 */
class BookingControllerSecurityTest extends TestCase
{
    private string $controllerSource;
    private string $securityServiceSource;
    private string $bookingServiceSource;
    private string $handlesExceptionSource;
    private string $jsonResponseSource;

    protected function setUp(): void
    {
        parent::setUp();
        $srcDir = dirname(__DIR__, 3) . '/src';
        $this->controllerSource = file_get_contents($srcDir . '/controllers/BookingController.php');
        $this->securityServiceSource = file_get_contents($srcDir . '/services/BookingSecurityService.php');
        $this->bookingServiceSource = file_get_contents($srcDir . '/services/BookingService.php');
        $this->handlesExceptionSource = file_get_contents($srcDir . '/controllers/traits/HandlesExceptionsTrait.php');
        $this->jsonResponseSource = file_get_contents($srcDir . '/controllers/traits/JsonResponseTrait.php');
    }

    // =========================================================================
    // Rate Limiting → 429 Response (BookingController)
    // =========================================================================

    public function testControllerCallsSecurityValidateRequest(): void
    {
        $this->assertStringContainsString(
            'securityService->validateRequest(',
            $this->controllerSource,
            'BookingController must call securityService->validateRequest()'
        );
    }

    public function testControllerChecksRateLimitErrorType(): void
    {
        $this->assertStringContainsString(
            'RESULT_RATE_LIMITED',
            $this->controllerSource,
            'BookingController must check for RESULT_RATE_LIMITED error type'
        );
    }

    public function testControllerChecksIpBlockedErrorType(): void
    {
        $this->assertStringContainsString(
            'RESULT_IP_BLOCKED',
            $this->controllerSource,
            'BookingController must check for RESULT_IP_BLOCKED error type'
        );
    }

    public function testControllerReturns429ForRateLimitAndIpBlock(): void
    {
        // Verify the controller returns 429 status code for rate limit / IP block
        $this->assertStringContainsString(
            '$isRateLimit ? 429',
            $this->controllerSource,
            'BookingController must return HTTP 429 for rate limited and IP blocked requests'
        );
    }

    public function testJsonErrorTraitSetsHttpStatusCode(): void
    {
        $this->assertStringContainsString(
            'setStatusCode($statusCode)',
            $this->jsonResponseSource,
            'jsonError must set HTTP status code on response'
        );
    }

    public function testJsonErrorDefaultStatusIs400(): void
    {
        $this->assertStringContainsString(
            'int $statusCode = 400',
            $this->jsonResponseSource,
            'jsonError default status code should be 400'
        );
    }

    // =========================================================================
    // Rate Limiting → BookingRateLimitException (BookingService)
    // =========================================================================

    public function testBookingServiceChecksRateLimitsBeforeCreating(): void
    {
        $this->assertStringContainsString(
            'checkRateLimits',
            $this->bookingServiceSource,
            'BookingService must call checkRateLimits during reservation creation'
        );
    }

    public function testBookingServiceThrowsRateLimitException(): void
    {
        $this->assertStringContainsString(
            'BookingRateLimitException',
            $this->bookingServiceSource,
            'BookingService must throw BookingRateLimitException when rate limited'
        );
    }

    public function testHandlesExceptionTraitMapsRateLimitException(): void
    {
        $this->assertStringContainsString(
            'BookingRateLimitException',
            $this->handlesExceptionSource,
            'HandlesExceptionsTrait must handle BookingRateLimitException'
        );

        $this->assertStringContainsString(
            "'rate_limit'",
            $this->handlesExceptionSource,
            'HandlesExceptionsTrait must map rate limit to rate_limit error type'
        );
    }

    // =========================================================================
    // IP Blocking (BookingSecurityService)
    // =========================================================================

    public function testSecurityServiceChecksIpBlocking(): void
    {
        preg_match('/function validateRequest\b.*?^    \}/ms', $this->securityServiceSource, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString(
            'enableIpBlocking',
            $method,
            'validateRequest must check enableIpBlocking setting'
        );

        $this->assertStringContainsString(
            'isIpBlocked',
            $method,
            'validateRequest must call isIpBlocked'
        );
    }

    public function testIpBlockReturnsFalseValidResult(): void
    {
        preg_match('/function validateRequest\b.*?^    \}/ms', $this->securityServiceSource, $matches);
        $method = $matches[0];

        $this->assertStringContainsString(
            'RESULT_IP_BLOCKED',
            $method,
            'validateRequest must return RESULT_IP_BLOCKED for blocked IPs'
        );

        $this->assertStringContainsString(
            "'valid' => false",
            $method,
            'validateRequest must return valid=false for blocked IPs'
        );
    }

    public function testIpBlockLogsAuditEvent(): void
    {
        preg_match('/function validateRequest\b.*?^    \}/ms', $this->securityServiceSource, $matches);
        $method = $matches[0];

        $this->assertStringContainsString(
            "logRateLimit('ip_blocked'",
            $method,
            'validateRequest must log ip_blocked audit event'
        );
    }

    // =========================================================================
    // Security check ordering: IP block runs before rate limit
    // =========================================================================

    public function testIpBlockCheckedBeforeTimeBasedLimit(): void
    {
        preg_match('/function validateRequest\b.*?^    \}/ms', $this->securityServiceSource, $matches);
        $method = $matches[0];

        $ipBlockPos = strpos($method, 'enableIpBlocking');
        $timeLimitPos = strpos($method, 'enableTimeBasedLimits');

        $this->assertNotFalse($ipBlockPos);
        $this->assertNotFalse($timeLimitPos);
        $this->assertLessThan(
            $timeLimitPos,
            $ipBlockPos,
            'IP blocking must be checked before time-based limits (blocked IPs should be rejected immediately)'
        );
    }

    public function testIpBlockCheckedBeforeCaptcha(): void
    {
        preg_match('/function validateRequest\b.*?^    \}/ms', $this->securityServiceSource, $matches);
        $method = $matches[0];

        $ipBlockPos = strpos($method, 'enableIpBlocking');
        $captchaPos = strpos($method, 'enableCaptcha');

        $this->assertNotFalse($ipBlockPos);
        $this->assertNotFalse($captchaPos);
        $this->assertLessThan(
            $captchaPos,
            $ipBlockPos,
            'IP blocking must be checked before CAPTCHA (no point verifying captcha for blocked IPs)'
        );
    }

    // =========================================================================
    // Honeypot returns fake success (not 429)
    // =========================================================================

    public function testHoneypotReturnsFakeSuccessNotError(): void
    {
        $this->assertStringContainsString(
            'RESULT_SPAM_DETECTED',
            $this->controllerSource,
            'BookingController must check for RESULT_SPAM_DETECTED'
        );

        // Verify honeypot returns fake success (not an error/429)
        preg_match('/RESULT_SPAM_DETECTED.*?redirectToPostedUrl/s', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString(
            "'success' => true",
            $matches[0],
            'Honeypot detection must return fake success to fool bots'
        );
    }

    // =========================================================================
    // SlotController also has rate limiting
    // =========================================================================

    public function testSlotControllerHasThrottling(): void
    {
        $slotSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/controllers/SlotController.php'
        );

        $this->assertStringContainsString(
            'slots_slots_throttle',
            $slotSource,
            'SlotController must throttle slot requests'
        );

        $this->assertStringContainsString(
            'statusCode: 429',
            $slotSource,
            'SlotController must return 429 when throttled'
        );
    }

    // =========================================================================
    // BookingRateLimitException
    // =========================================================================

    public function testBookingRateLimitExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\slots\exceptions\BookingRateLimitException::class),
            'BookingRateLimitException class must exist'
        );
    }

    public function testBookingRateLimitExceptionExtendsBookingException(): void
    {
        $reflection = new \ReflectionClass(\anvildev\slots\exceptions\BookingRateLimitException::class);

        $this->assertTrue(
            $reflection->isSubclassOf(\anvildev\slots\exceptions\BookingException::class),
            'BookingRateLimitException must extend BookingException'
        );
    }

    // =========================================================================
    // Security constants
    // =========================================================================

    public function testSecurityServiceHasAllResultConstants(): void
    {
        $this->assertSame('valid', BookingSecurityService::RESULT_VALID);
        $this->assertSame('ip_blocked', BookingSecurityService::RESULT_IP_BLOCKED);
        $this->assertSame('rate_limited', BookingSecurityService::RESULT_RATE_LIMITED);
        $this->assertSame('captcha_failed', BookingSecurityService::RESULT_CAPTCHA_FAILED);
        $this->assertSame('spam_detected', BookingSecurityService::RESULT_SPAM_DETECTED);
    }
}
