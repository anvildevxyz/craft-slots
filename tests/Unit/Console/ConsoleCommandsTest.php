<?php

declare(strict_types=1);

namespace anvildev\slots\tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * Structural tests for all console commands.
 *
 * Verifies each console controller:
 * - Returns proper int exit codes from all action methods
 * - Destructive commands use $this->confirm() for safety
 * - Commands handle not-found/error cases with stderr output
 * - All action methods are public
 */
class ConsoleCommandsTest extends TestCase
{
    private const CONSOLE_DIR = __DIR__ . '/../../../src/console/controllers';

    private function getControllerSource(string $filename): string
    {
        $path = self::CONSOLE_DIR . '/' . $filename;
        $this->assertFileExists($path, "Console controller {$filename} must exist");
        return file_get_contents($path);
    }

    // =========================================================================
    // All controllers return int exit codes
    // =========================================================================

    /**
     * @dataProvider controllerFileProvider
     */
    public function testAllActionsReturnInt(string $filename): void
    {
        $source = $this->getControllerSource($filename);

        // Every "public function action*" must declare ": int" return type
        preg_match_all('/public function (action\w+)\(/', $source, $matches);
        $this->assertNotEmpty($matches[1], "{$filename} must have at least one action method");

        foreach ($matches[1] as $method) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $method . '\([^)]*\)\s*:\s*int/',
                $source,
                "{$filename}::{$method}() must declare : int return type"
            );
        }
    }

    /**
     * @dataProvider controllerFileProvider
     */
    public function testAllActionsUseExitCodes(string $filename): void
    {
        $source = $this->getControllerSource($filename);

        // Should use ExitCode constants or return 0/1, not arbitrary numbers
        $this->assertTrue(
            str_contains($source, 'ExitCode') || str_contains($source, 'return 0') || str_contains($source, 'return 1'),
            "{$filename} must use ExitCode constants or standard exit codes"
        );
    }

    public function controllerFileProvider(): array
    {
        $files = glob(self::CONSOLE_DIR . '/*.php');
        $data = [];
        foreach ($files as $file) {
            $name = basename($file);
            $data[$name] = [$name];
        }
        return $data;
    }

    // =========================================================================
    // Destructive commands require confirmation
    // =========================================================================

    public function testBookingsCancelRequiresConfirmation(): void
    {
        $source = $this->getControllerSource('BookingsController.php');
        $this->assertStringContainsString(
            '$this->confirm(',
            $source,
            'BookingsController::actionCancel must use $this->confirm() before cancelling'
        );
    }

    public function testBookingsControllerHandlesNotFound(): void
    {
        $source = $this->getControllerSource('BookingsController.php');
        $this->assertStringContainsString(
            '$this->stderr(',
            $source,
            'BookingsController must output errors via stderr'
        );

        // Should check for booking not found
        $this->assertStringContainsString(
            'not found',
            $source,
            'BookingsController must handle booking not found'
        );
    }

    public function testDoctorControllerPerformsChecks(): void
    {
        $source = $this->getControllerSource('DoctorController.php');

        // Doctor should check settings/configuration
        $this->assertTrue(
            str_contains($source, 'getSettings') || str_contains($source, 'Settings'),
            'DoctorController must check plugin settings'
        );
    }

    // =========================================================================
    // Export command validates format
    // =========================================================================

    public function testRemindersControllerHasSendAndQueue(): void
    {
        $source = $this->getControllerSource('RemindersController.php');
        $this->assertStringContainsString('function actionSend(', $source);
        $this->assertStringContainsString('function actionQueue(', $source);
    }

    // =========================================================================
    // All action methods are public
    // =========================================================================

    /**
     * @dataProvider controllerFileProvider
     */
    public function testActionMethodsArePublic(string $filename): void
    {
        $source = $this->getControllerSource($filename);

        // No protected/private action methods (Yii2 requires public)
        $this->assertDoesNotMatchRegularExpression(
            '/(protected|private)\s+function\s+action\w+/',
            $source,
            "{$filename} must not have protected/private action methods"
        );
    }
}
