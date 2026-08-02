<?php

namespace anvildev\slots\tests\Unit\Models;

use anvildev\slots\models\Settings;
use anvildev\slots\tests\Support\TestCase;

class SettingsConfigurationTest extends TestCase
{
    // =========================================================================
    // Defaults
    // =========================================================================

    public function testSecurityDefaults(): void
    {
        $s = new Settings();

        $this->assertFalse($s->enableCaptcha);
        $this->assertTrue($s->enableHoneypot);
        $this->assertSame('website', $s->honeypotFieldName);
        $this->assertFalse($s->enableIpBlocking);
        $this->assertTrue($s->enableTimeBasedLimits);
        $this->assertSame(3, $s->minimumSubmissionTime);
        $this->assertFalse($s->enableAuditLog);
    }
}
