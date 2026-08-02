<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\BookingNotificationService;
use anvildev\slots\tests\Support\TestCase;

/**
 * BookingNotificationService Test
 *
 * All methods require Craft::$app->getQueue() or Slots::getInstance()->getSettings()
 * and are tested via integration tests. This file covers service structure only.
 */
class BookingNotificationServiceTest extends TestCase
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

    public function testServiceIsComponent(): void
    {
        $service = new BookingNotificationService();
        $this->assertInstanceOf(BookingNotificationService::class, $service);
    }
}
