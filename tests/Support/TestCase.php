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
     * The exact source of one method, bounded by its real start and end lines.
     *
     * Contract tests that assert on source text used to take a fixed slice —
     * `substr($source, $pos, 2000)`. A slice long enough to cover the method
     * today stops covering it the moment the method grows, and the assertion
     * then fails reporting a broken contract that is not broken. That happened
     * for real: adding the booking-window ceiling to filterPastSlots() pushed
     * the asserted expression past the 2000th character.
     *
     * Reflection has no such cliff, and several suites here already used it
     * privately; this is that approach shared rather than a new one.
     *
     * @param class-string $class
     */
    protected function sourceOfMethod(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $this->assertNotFalse($file, "{$class} has no source file");

        $lines = file($file);
        $this->assertNotFalse($lines, "Could not read {$file}");

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
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
