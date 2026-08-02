<?php

namespace anvildev\slots\tests\Unit\Models;

use anvildev\slots\models\Settings;
use anvildev\slots\tests\Support\TestCase;

/**
 * Settings Validation Test
 *
 * Tests configuration validation rules that warn about
 * insecure or broken settings combinations.
 *
 * Note: Tests requiring Craft CMS initialization are skipped in unit test mode.
 */
class SettingsValidationTest extends TestCase
{
    public function testCaptchaDisabledPassesValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = false;
        $model->captchaProvider = null;

        $model->validate(['captchaProvider']);

        $this->assertFalse($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithoutProviderFailsValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = null;

        $model->validate(['captchaProvider']);

        $this->assertTrue($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithRecaptchaKeysPassesValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = 'recaptcha';
        $model->recaptchaSiteKey = 'site-key-123';
        $model->recaptchaSecretKey = 'secret-key-456';

        $model->validate(['captchaProvider']);

        $this->assertFalse($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithMissingRecaptchaKeysFailsValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = 'recaptcha';
        $model->recaptchaSiteKey = 'site-key-123';
        $model->recaptchaSecretKey = null;

        $model->validate(['captchaProvider']);

        $this->assertTrue($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithHcaptchaKeysPassesValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = 'hcaptcha';
        $model->hcaptchaSiteKey = 'site-key-123';
        $model->hcaptchaSecretKey = 'secret-key-456';

        $model->validate(['captchaProvider']);

        $this->assertFalse($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithMissingHcaptchaKeysFailsValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = 'hcaptcha';
        $model->hcaptchaSiteKey = null;
        $model->hcaptchaSecretKey = 'secret-key-456';

        $model->validate(['captchaProvider']);

        $this->assertTrue($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithTurnstileKeysPassesValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = 'turnstile';
        $model->turnstileSiteKey = 'site-key-123';
        $model->turnstileSecretKey = 'secret-key-456';

        $model->validate(['captchaProvider']);

        $this->assertFalse($model->hasErrors('captchaProvider'));
    }

    public function testCaptchaEnabledWithMissingTurnstileKeysFailsValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableCaptcha = true;
        $model->captchaProvider = 'turnstile';
        $model->turnstileSiteKey = null;
        $model->turnstileSecretKey = null;

        $model->validate(['captchaProvider']);

        $this->assertTrue($model->hasErrors('captchaProvider'));
    }

    // =========================================================================
    // =========================================================================
    // Rate Limiting Validation
    // =========================================================================

    public function testRateLimitingEnabledPassesValidation(): void
    {
        $this->requiresCraft();

        $model = new Settings();
        $model->enableRateLimiting = true;

        $model->validate(['enableRateLimiting']);

        $this->assertFalse($model->hasErrors('enableRateLimiting'));
    }

    public function testRateLimitingDisabledInDevModePassesValidation(): void
    {
        $this->requiresCraft();

        $devMode = \Craft::$app->getConfig()->getGeneral()->devMode;

        if (!$devMode) {
            $this->markTestSkipped('Requires devMode to be enabled');
        }

        $model = new Settings();
        $model->enableRateLimiting = false;

        $model->validate(['enableRateLimiting']);

        $this->assertFalse($model->hasErrors('enableRateLimiting'));
    }

    public function testRateLimitingDisabledInProductionFailsValidation(): void
    {
        $this->requiresCraft();

        $devMode = \Craft::$app->getConfig()->getGeneral()->devMode;

        if ($devMode) {
            $this->markTestSkipped('Requires devMode to be disabled (production)');
        }

        $model = new Settings();
        $model->enableRateLimiting = false;

        $model->validate(['enableRateLimiting']);

        $this->assertTrue($model->hasErrors('enableRateLimiting'));
    }
}
