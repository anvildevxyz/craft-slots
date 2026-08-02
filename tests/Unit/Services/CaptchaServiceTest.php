<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\Slots;
use anvildev\slots\models\Settings;
use anvildev\slots\services\CaptchaService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * CaptchaService Test
 *
 * Tests the verify() dispatch logic and provider-specific verification methods.
 * The protected sendVerificationRequest() is mocked to avoid real HTTP calls.
 *
 * Full verify() flow requires Slots::getInstance() — tested via overload mocks
 * in separate processes. Protected methods can be tested without Craft integration.
 */
class CaptchaServiceTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    /**
     * Create partial mock with protected method mocking
     */
    private function makePartialService(): MockInterface
    {
        return Mockery::mock(CaptchaService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    /**
     * Create settings with given properties
     */
    private function makeSettings(array $overrides = []): Settings
    {
        $settings = new Settings();
        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        return $settings;
    }

    // =========================================================================
    // verify() - Empty token (after enableCaptcha check)
    // =========================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testVerifyReturnsFalseForEmptyToken(): void
    {
        $settings = $this->makeSettings(['enableCaptcha' => true]);
        $booked = Mockery::mock('overload:' . Slots::class);
        $booked->shouldReceive('getInstance')->andReturnSelf();
        $booked->shouldReceive('getSettings')->andReturn($settings);

        $service = new CaptchaService();
        $this->assertFalse($service->verify(''));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testVerifyReturnsTrueWhenCaptchaDisabled(): void
    {
        $settings = $this->makeSettings(['enableCaptcha' => false]);
        $booked = Mockery::mock('overload:' . Slots::class);
        $booked->shouldReceive('getInstance')->andReturnSelf();
        $booked->shouldReceive('getSettings')->andReturn($settings);

        $service = new CaptchaService();
        $this->assertTrue($service->verify(''));
    }

    // =========================================================================
    // verifyRecaptcha() - via reflection
    // =========================================================================

    public function testRecaptchaReturnsFalseWhenNoSecretKey(): void
    {
        $service = $this->makePartialService();
        $settings = $this->makeSettings(['recaptchaSecretKey' => '']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testRecaptchaReturnsTrueWhenScoreAboveThreshold(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => true, 'score' => 0.9]);

        $settings = $this->makeSettings(['recaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'test-token', null, $settings));
    }

    public function testRecaptchaReturnsFalseWhenScoreBelowThreshold(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => true, 'score' => 0.3]);

        $settings = $this->makeSettings(['recaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testRecaptchaReturnsFalseWhenSuccessIsFalse(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => false, 'score' => 0.9]);

        $settings = $this->makeSettings(['recaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testRecaptchaReturnsFalseWhenResponseIsNull(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(null);

        $settings = $this->makeSettings(['recaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testRecaptchaReturnsFalseWhenNoScoreInResponse(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => true]); // No score key

        $settings = $this->makeSettings(['recaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        // Default score is 0 when missing, which is < 0.5
        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testRecaptchaIncludesIpWhenProvided(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->with(
                'https://www.google.com/recaptcha/api/siteverify',
                Mockery::on(function ($data) {
                    return $data['secret'] === 'secret-key'
                        && $data['response'] === 'test-token'
                        && $data['remoteip'] === '1.2.3.4';
                })
            )
            ->andReturn(['success' => true, 'score' => 0.9]);

        $settings = $this->makeSettings(['recaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyRecaptcha');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'test-token', '1.2.3.4', $settings));
    }

    // =========================================================================
    // verifyHcaptcha() - via reflection
    // =========================================================================

    public function testHcaptchaReturnsFalseWhenNoSecretKey(): void
    {
        $service = $this->makePartialService();
        $settings = $this->makeSettings(['hcaptchaSecretKey' => '']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyHcaptcha');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testHcaptchaReturnsTrueOnSuccess(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => true]);

        $settings = $this->makeSettings(['hcaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyHcaptcha');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'test-token', null, $settings));
    }

    public function testHcaptchaReturnsFalseOnFailure(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => false]);

        $settings = $this->makeSettings(['hcaptchaSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyHcaptcha');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    // =========================================================================
    // verifyTurnstile() - via reflection
    // =========================================================================

    public function testTurnstileReturnsFalseWhenNoSecretKey(): void
    {
        $service = $this->makePartialService();
        $settings = $this->makeSettings(['turnstileSecretKey' => '']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyTurnstile');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    public function testTurnstileReturnsTrueOnSuccess(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => true]);

        $settings = $this->makeSettings(['turnstileSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyTurnstile');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'test-token', null, $settings));
    }

    public function testTurnstileReturnsFalseOnFailure(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('sendVerificationRequest')
            ->once()
            ->andReturn(['success' => false]);

        $settings = $this->makeSettings(['turnstileSecretKey' => 'secret-key']);

        $method = new \ReflectionMethod(CaptchaService::class, 'verifyTurnstile');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'test-token', null, $settings));
    }

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new CaptchaService();
        $this->assertInstanceOf(CaptchaService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new CaptchaService();
        $this->assertTrue(method_exists($service, 'verify'));
    }
}
