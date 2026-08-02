<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Element Type Registration Test
 *
 * Every element class must be passed to Elements::EVENT_REGISTER_ELEMENT_TYPES.
 * Craft only exposes registered types in `Craft.elementTypeNames`, which the CP's
 * ElementDeletionManager dereferences without a guard — an unregistered type makes
 * the Delete action throw before it ever posts. Unregistered types are also skipped
 * by garbage collection, reference tag resolution and primary-site changes.
 *
 * This was found upstream, where an unregistered element type shipped and the
 * Delete action failed in the CP with no server-side error to go on.
 */
class ElementTypeRegistrationTest extends TestCase
{
    private string $srcDir;
    private string $registrationSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->srcDir = dirname(__DIR__, 2) . '/src';
        $this->registrationSource = $this->registerElementTypesBody();
    }

    // =========================================================================
    // Registration Coverage
    // =========================================================================

    /**
     * @dataProvider elementClassProvider
     */
    public function testElementClassIsRegistered(string $class): void
    {
        $this->assertStringContainsString(
            "\\anvildev\\slots\\elements\\{$class}::class",
            $this->registrationSource,
            "Element class '{$class}' should be registered in Slots::registerElementTypes()"
        );
    }

    public static function elementClassProvider(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/elements/*.php') as $file) {
            $name = basename($file, '.php');
            $classes[$name] = [$name];
        }

        return $classes;
    }

    // =========================================================================
    // CP Element Indexes
    // =========================================================================

    /**
     * @dataProvider elementIndexTemplateProvider
     */
    public function testElementIndexTypeIsRegistered(string $template, string $class): void
    {
        $this->assertStringContainsString(
            "\\anvildev\\slots\\elements\\{$class}::class",
            $this->registrationSource,
            "'{$template}' renders an element index for '{$class}', which must be registered "
            . 'or the CP Delete action will throw'
        );
    }

    public static function elementIndexTemplateProvider(): array
    {
        $cases = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/templates/*/_index.twig') as $file) {
            $source = file_get_contents($file);

            if (!str_contains($source, '_layouts/elementindex')) {
                continue;
            }

            if (!preg_match("/set elementType = '([^']+)'/", $source, $match)) {
                continue;
            }

            $template = basename(dirname($file)) . '/_index.twig';
            $cases[$template] = [$template, substr(strrchr(stripslashes($match[1]), '\\'), 1)];
        }

        return $cases;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function registerElementTypesBody(): string
    {
        $source = file_get_contents($this->srcDir . '/Slots.php');
        $start = strpos($source, 'private function registerElementTypes(): void');

        $this->assertNotFalse($start, 'Slots.php should define registerElementTypes()');

        $end = strpos($source, 'private function ', $start + 1);

        return $end !== false ? substr($source, $start, $end - $start) : substr($source, $start);
    }
}
