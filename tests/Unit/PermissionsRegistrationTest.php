<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Permissions Registration Test
 *
 * Verifies that all expected permissions are registered in Slots.php
 * and that controllers and nav items reference the correct permission strings.
 */
class PermissionsRegistrationTest extends TestCase
{
    private string $slotsSource;
    private string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcDir = dirname(__DIR__, 2) . '/src';
        $this->slotsSource = file_get_contents($this->srcDir . '/Slots.php');
    }

    // =========================================================================
    // Permission Registration
    // =========================================================================

    /**
     * @dataProvider registeredPermissionsProvider
     */
    public function testPermissionIsRegisteredInSlotsPhp(string $permission): void
    {
        $this->assertStringContainsString(
            "'{$permission}'",
            $this->slotsSource,
            "Permission '{$permission}' should be registered in Slots.php"
        );
    }

    public static function registeredPermissionsProvider(): array
    {
        return [
            'accessPlugin' => ['slots-accessPlugin'],
            'viewBookings' => ['slots-viewBookings'],
            'manageBookings' => ['slots-manageBookings'],
            'manageRefunds' => ['slots-manageRefunds'],
            'viewCalendar' => ['slots-viewCalendar'],
            'viewReports' => ['slots-viewReports'],
            'manageServices' => ['slots-manageServices'],
            'manageEmployees' => ['slots-manageEmployees'],
            'manageLocations' => ['slots-manageLocations'],
            'manageSettings' => ['slots-manageSettings'],
            'manageBlackoutDates' => ['slots-manageBlackoutDates'],
        ];
    }

    public function testTotalPermissionCount(): void
    {
        preg_match_all("/('slots-[a-zA-Z]+')\s*=>\s*\[/", $this->slotsSource, $matches);

        $this->assertCount(
            11,
            $matches[1],
            'Slots.php should register exactly 11 permissions. Found: ' . implode(', ', $matches[1])
        );
    }

    // =========================================================================
    // Controller Permission Checks
    // =========================================================================

    /**
     * @dataProvider controllerPermissionProvider
     */
    public function testControllerUsesCorrectPermission(string $controllerFile, string $expectedPermission): void
    {
        $source = file_get_contents($this->srcDir . '/controllers/cp/' . $controllerFile);

        $this->assertStringContainsString(
            "requirePermission('{$expectedPermission}')",
            $source,
            "{$controllerFile} should require '{$expectedPermission}'"
        );
    }

    public static function controllerPermissionProvider(): array
    {
        return [
            'BlackoutDatesController' => ['BlackoutDatesController.php', 'slots-manageBlackoutDates'],
        ];
    }

    // =========================================================================
    // Nav Item Permission Checks
    // =========================================================================

    /**
     * @dataProvider navPermissionProvider
     */
    public function testNavItemUsesCorrectPermission(string $navKey, string $expectedPermission): void
    {
        // Match data-driven navDefs: ['key', 'translationKey', 'url', 'permission', ...]
        $found = false;
        $foundPermission = null;

        if (preg_match_all("/\['([^']+)',\s*'[^']+',\s*'[^']+',\s*'([^']+)'/", $this->slotsSource, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] === $navKey) {
                    $found = true;
                    $foundPermission = $match[2];
                    break;
                }
            }
        }

        $this->assertTrue($found, "Nav key '{$navKey}' should exist in Slots.php navDefs");
        $this->assertSame(
            $expectedPermission,
            $foundPermission,
            "Nav item '{$navKey}' should be gated by '{$expectedPermission}', got '{$foundPermission}'"
        );
    }

    public static function navPermissionProvider(): array
    {
        return [
            'blackout-dates' => ['blackout-dates', 'slots-manageBlackoutDates'],
        ];
    }

    // =========================================================================
    // Translation Strings
    // =========================================================================

    /**
     * @dataProvider translationKeyProvider
     */
    public function testEnglishTranslationExists(string $key): void
    {
        $translations = require $this->srcDir . '/translations/en/slots.php';

        $this->assertArrayHasKey(
            $key,
            $translations,
            "English translation for '{$key}' should exist"
        );
    }

    /**
     * @dataProvider translationKeyProvider
     */
    public function testGermanTranslationExists(string $key): void
    {
        $translations = require $this->srcDir . '/translations/de/slots.php';

        $this->assertArrayHasKey(
            $key,
            $translations,
            "German translation for '{$key}' should exist"
        );
    }

    public static function translationKeyProvider(): array
    {
        return [
            'manageBlackoutDates' => ['permissions.manageBlackoutDates'],
            'manageRefunds' => ['permissions.manageRefunds'],
        ];
    }
}
