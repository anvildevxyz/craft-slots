<?php

namespace anvildev\slots\tests\Unit\Controllers;

use anvildev\slots\tests\Support\TestCase;
use ReflectionClass;

class ControllerReflectionTest extends TestCase
{
    /**
     * @dataProvider allControllerProvider
     */
    public function testControllerExtendsCraftController(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->assertTrue(
            $ref->isSubclassOf(\craft\web\Controller::class),
            "{$className} should extend craft\\web\\Controller"
        );
    }

    /**
     * @dataProvider allControllerProvider
     */
    public function testActionMethodsReturnCorrectType(string $className): void
    {
        $ref = new ReflectionClass($className);
        $allowedTypes = ['craft\web\Response', 'yii\web\Response', 'Response', 'mixed'];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'action')) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $returnType = $method->getReturnType();
            $this->assertNotNull(
                $returnType,
                "{$className}::{$method->getName()} should declare a return type"
            );

            $typeName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : (string)$returnType;
            $this->assertTrue(
                in_array($typeName, $allowedTypes, true),
                "{$className}::{$method->getName()} should return Response or mixed, got {$typeName}"
            );
        }
    }

    /**
     * @dataProvider controllerTraitProvider
     */
    public function testControllerUsesExpectedTraits(string $className, array $expectedTraits): void
    {
        $ref = new ReflectionClass($className);
        $actualTraits = array_keys($ref->getTraits());

        if (empty($expectedTraits)) {
            $bookedTraits = array_filter($actualTraits, fn($t) => str_starts_with($t, 'anvildev\\slots\\'));
            $this->assertEmpty(
                $bookedTraits,
                "{$className} should not use any booked controller traits"
            );
            return;
        }

        foreach ($expectedTraits as $trait) {
            $this->assertContains(
                $trait,
                $actualTraits,
                "{$className} should use {$trait}"
            );
        }
    }

    /**
     * @dataProvider actionCountProvider
     */
    public function testControllerActionCount(string $className, int $expectedCount): void
    {
        $ref = new ReflectionClass($className);
        $actionCount = 0;

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'action') && $method->getDeclaringClass()->getName() === $className) {
                $actionCount++;
            }
        }

        $this->assertSame(
            $expectedCount,
            $actionCount,
            "{$className} should have {$expectedCount} action methods, found {$actionCount}"
        );
    }

    public static function allControllerProvider(): array
    {
        return array_map(fn($class) => [$class], self::allControllerClasses());
    }

    public static function controllerTraitProvider(): array
    {
        $json = 'anvildev\\slots\\controllers\\traits\\JsonResponseTrait';
        $exc = 'anvildev\\slots\\controllers\\traits\\HandlesExceptionsTrait';
        $help = 'anvildev\\slots\\controllers\\traits\\BookingHelpersTrait';

        return [
            'BookingController' => ['anvildev\\slots\\controllers\\BookingController', [$json, $exc, $help]],
            'SlotController' => ['anvildev\\slots\\controllers\\SlotController', [$json, $exc, $help]],
            'PaymentController' => ['anvildev\\slots\\controllers\\PaymentController', [$json, $exc, $help]],
            'BookingDataController' => ['anvildev\\slots\\controllers\\BookingDataController', [$json, $help]],
            'BookingManagementController' => ['anvildev\\slots\\controllers\\BookingManagementController', [$json, $help]],
            'AccountController' => ['anvildev\\slots\\controllers\\AccountController', [$json, $help]],
            'cp/DashboardController' => ['anvildev\\slots\\controllers\\cp\\DashboardController', []],
            'cp/CalendarViewController' => ['anvildev\\slots\\controllers\\cp\\CalendarViewController', [$json]],
            'cp/ReportsController' => ['anvildev\\slots\\controllers\\cp\\ReportsController', []],
            'cp/ServicesController' => ['anvildev\\slots\\controllers\\cp\\ServicesController', [$json]],
            'cp/EmployeesController' => ['anvildev\\slots\\controllers\\cp\\EmployeesController', [$json]],
            'cp/SchedulesController' => ['anvildev\\slots\\controllers\\cp\\SchedulesController', []],
            'cp/LocationsController' => ['anvildev\\slots\\controllers\\cp\\LocationsController', []],
            'cp/BlackoutDatesController' => ['anvildev\\slots\\controllers\\cp\\BlackoutDatesController', []],
            'cp/BookingsController' => ['anvildev\\slots\\controllers\\cp\\BookingsController', [$json, $exc]],
            'cp/SettingsController' => ['anvildev\\slots\\controllers\\cp\\SettingsController', [$json]],
        ];
    }

    public static function actionCountProvider(): array
    {
        return [
            'BookingController' => ['anvildev\\slots\\controllers\\BookingController', 1],
            'SlotController' => ['anvildev\\slots\\controllers\\SlotController', 5],
            'PaymentController' => ['anvildev\\slots\\controllers\\PaymentController', 3],
            'BookingDataController' => ['anvildev\\slots\\controllers\\BookingDataController', 3],
            'BookingManagementController' => ['anvildev\\slots\\controllers\\BookingManagementController', 7],
            'AccountController' => ['anvildev\\slots\\controllers\\AccountController', 7],
            'cp/DashboardController' => ['anvildev\\slots\\controllers\\cp\\DashboardController', 1],
            'cp/CalendarViewController' => ['anvildev\\slots\\controllers\\cp\\CalendarViewController', 5],
            'cp/ReportsController' => ['anvildev\\slots\\controllers\\cp\\ReportsController', 2],
            'cp/ServicesController' => ['anvildev\\slots\\controllers\\cp\\ServicesController', 4],
            'cp/EmployeesController' => ['anvildev\\slots\\controllers\\cp\\EmployeesController', 3],
            'cp/SchedulesController' => ['anvildev\\slots\\controllers\\cp\\SchedulesController', 3],
            'cp/LocationsController' => ['anvildev\\slots\\controllers\\cp\\LocationsController', 3],
            'cp/BlackoutDatesController' => ['anvildev\\slots\\controllers\\cp\\BlackoutDatesController', 5],
            'cp/BookingsController' => ['anvildev\\slots\\controllers\\cp\\BookingsController', 10],
            'cp/SettingsController' => ['anvildev\\slots\\controllers\\cp\\SettingsController', 5],
        ];
    }

    /**
     * Read off the filesystem rather than listed, so a controller added or
     * removed cannot silently drop out of these checks. The hardcoded list that
     * used to live here went stale through the strip-down: it named five
     * deleted controllers and missed PaymentController entirely.
     *
     * @return string[]
     */
    private static function allControllerClasses(): array
    {
        $base = dirname(__DIR__, 3) . '/src/controllers';
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }

            $relative = str_replace([$base . '/', '.php'], '', $file->getPathname());
            $classes[] = 'anvildev\\slots\\controllers\\' . str_replace('/', '\\', $relative);
        }

        sort($classes);

        return $classes;
    }
}
