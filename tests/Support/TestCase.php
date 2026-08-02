<?php

namespace anvildev\slots\tests\Support;

use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base Test Case
 *
 * Provides common functionality for all test cases
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Skip test if Craft CMS is not fully initialized.
     *
     * Checks the Yii application type rather than class_exists(\Craft::class),
     * because the Craft class may be autoloaded by other tests without the
     * full application being available.
     */
    protected function requiresCraft(): void
    {
        if (!\Yii::$app instanceof \craft\console\Application
            && !\Yii::$app instanceof \craft\web\Application) {
            $this->markTestSkipped('Requires full Craft CMS initialization');
        }
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Assert that an array has keys
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message);
        }
    }

    /**
     * Assert that a value is a valid date string
     */
    protected function assertIsValidDate(string $date, string $format = 'Y-m-d', string $message = ''): void
    {
        $d = \DateTime::createFromFormat($format, $date);
        $this->assertTrue(
            $d && $d->format($format) === $date,
            $message ?: "Failed asserting that {$date} is a valid date in format {$format}"
        );
    }

    /**
     * Assert that a value is a valid time string
     */
    protected function assertIsValidTime(string $time, string $format = 'H:i', string $message = ''): void
    {
        $t = \DateTime::createFromFormat($format, $time);
        $this->assertTrue(
            $t && $t->format($format) === $time,
            $message ?: "Failed asserting that {$time} is a valid time in format {$format}"
        );
    }

    /**
     * Assert that a value is a valid timezone
     */
    protected function assertIsValidTimezone(string $timezone, string $message = ''): void
    {
        $this->assertTrue(
            in_array($timezone, \DateTimeZone::listIdentifiers()),
            $message ?: "Failed asserting that {$timezone} is a valid timezone"
        );
    }
}
