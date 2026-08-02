<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\AuditService;
use anvildev\slots\tests\Support\TestCase;

/**
 * AuditService Test
 *
 * Tests the convenience methods delegate correctly to log().
 * The actual file writing (log() → FileHelper::writeToFile) requires integration tests.
 *
 * All private methods (isEnabled, getLogPath, getClientIp, etc.) need
 * Slots::getInstance() or Craft::$app and cannot be unit tested.
 */
class AuditServiceTest extends TestCase
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

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new AuditService();
        $this->assertInstanceOf(AuditService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new AuditService();
        $this->assertTrue(method_exists($service, 'log'));
        $this->assertTrue(method_exists($service, 'logCancellation'));
        $this->assertTrue(method_exists($service, 'logStatusChange'));
        $this->assertTrue(method_exists($service, 'logAuthFailure'));
        $this->assertTrue(method_exists($service, 'logRateLimit'));
        $this->assertTrue(method_exists($service, 'logSettingsChange'));
    }
}
