<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Structural checks on the control panel.
 *
 * The rest of this suite is source-scanning and never boots Craft, so it cannot
 * see a CP screen 500 because a template was deleted or an asset was renamed on
 * only one side. Stripping Booked down to Slots produced exactly those bugs:
 *
 *  - src/templates/calendar/ was deleted wholesale with calendar *sync*, taking
 *    the CP calendar *view* (a top-level nav item) with it
 *  - SlotsCpAsset pointed at css/cp/slots-cp.css while the file on disk was
 *    still booked-cp.css, so every CP screen rendered unstyled
 *  - SlotsAsset pointed at css/booked.css after the file became slots.css
 *  - SlotsSettingsAsset still loaded a JS file deleted with calendar sync
 *
 * Every one of those is a broken CP screen that a green unit suite reported as
 * fine. These tests make the wiring itself assertable.
 */
class ControlPanelWiringTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path);

        return file_get_contents($path);
    }

    /** @return array<string, string> route pattern => action path */
    private static function cpRoutes(): array
    {
        $source = self::read('src/Slots.php');
        $start = strpos($source, 'EVENT_REGISTER_CP_URL_RULES');
        self::assertNotFalse($start, 'CP URL rules must still be registered');

        $end = strpos($source, ');', strpos($source, 'array_merge($event->rules', $start));
        $block = substr($source, $start, $end - $start);

        preg_match_all("/'([^']+)'\s*=>\s*'(slots\/[^']+)'/", $block, $m, PREG_SET_ORDER);

        $routes = [];
        foreach ($m as $match) {
            $routes[$match[1]] = $match[2];
        }

        return $routes;
    }

    private static function controllerPath(string $actionPath): ?string
    {
        $parts = explode('/', $actionPath);
        if (count($parts) < 3) {
            return null;
        }

        $namespace = ($parts[1] ?? '') === 'cp' ? 'cp/' : '';
        $controller = $parts[count($parts) - 2];
        $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $controller))) . 'Controller';

        return "src/controllers/{$namespace}{$class}.php";
    }

    public function testEveryCpRouteResolvesToAControllerAction(): void
    {
        $routes = self::cpRoutes();
        $this->assertNotEmpty($routes, 'There should be CP routes registered');

        foreach ($routes as $pattern => $actionPath) {
            $path = self::controllerPath($actionPath);
            $this->assertNotNull($path, "Route '{$pattern}' has an unparseable target '{$actionPath}'");
            $this->assertFileExists(self::root() . '/' . $path, "Route '{$pattern}' points at a missing controller");

            $parts = explode('/', $actionPath);
            $method = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', end($parts))));

            $this->assertStringContainsString(
                "function {$method}(",
                file_get_contents(self::root() . '/' . $path),
                "Route '{$pattern}' points at {$method}(), which does not exist in {$path}",
            );
        }
    }

    public function testEveryTemplateRenderedByAControllerExists(): void
    {
        $checked = 0;

        foreach ($this->phpFilesIn(['src/controllers', 'src/widgets']) as $file) {
            preg_match_all("/renderT\w*emplate\(\s*'slots\/([^']+)'/", file_get_contents($file), $m);
            foreach ($m[1] as $template) {
                $this->assertFileExists(
                    self::root() . "/src/templates/{$template}.twig",
                    "{$file} renders slots/{$template}, which does not exist",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'Expected controllers to render templates');
    }

    public function testEveryTemplateIncludeResolves(): void
    {
        foreach ($this->twigFiles() as $file) {
            preg_match_all("/\{%\s*(?:include|extends|embed)\s*'slots\/([^']+)'/", file_get_contents($file), $m);
            foreach ($m[1] as $template) {
                $this->assertFileExists(
                    self::root() . "/src/templates/{$template}.twig",
                    "{$file} includes slots/{$template}, which does not exist",
                );
            }
        }
    }

    public function testEveryAssetBundleFileExistsOnDisk(): void
    {
        $checked = 0;

        foreach (glob(self::root() . '/src/assetbundles/*.php') as $file) {
            preg_match_all("/'((?:js|css)\/[^']+)'/", file_get_contents($file), $m);
            foreach ($m[1] as $asset) {
                $this->assertFileExists(
                    self::root() . "/src/web/{$asset}",
                    basename($file) . " registers {$asset}, which does not exist",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'Expected asset bundles to register files');
    }

    public function testEveryAssetBundleReferencedFromATemplateExists(): void
    {
        foreach ($this->twigFiles() as $file) {
            preg_match_all('/registerAssetBundle\("([^"]+)"\)/', file_get_contents($file), $m);
            foreach ($m[1] as $class) {
                $parts = explode('\\', $class);
                $this->assertFileExists(
                    self::root() . '/src/assetbundles/' . end($parts) . '.php',
                    "{$file} registers {$class}, which does not exist",
                );
            }
        }
    }

    public function testNoTemplateLinksToARemovedCpScreen(): void
    {
        $removed = [
            'slots/settings/calendar',
            'slots/settings/commerce',
            'slots/settings/sms',
            'slots/settings/meetings',
            'slots/settings/webhooks',
            'slots/settings/waitlist',
            'slots/calendar/connect',
            'slots/cp/calendar/disconnect',
            'slots/cp/calendar/send-invite',
            'slots/waitlist',
            'slots/webhooks',
            'slots/service-extras',
            'slots/cp/event-dates',
        ];

        foreach ($this->twigFiles() as $file) {
            $contents = file_get_contents($file);
            foreach ($removed as $route) {
                $this->assertStringNotContainsString(
                    "'{$route}'",
                    $contents,
                    basename($file) . " still links to {$route}, which no longer exists",
                );
            }
        }
    }

    /** @return string[] */
    private function twigFiles(): array
    {
        return $this->filesIn([self::root() . '/src/templates'], 'twig');
    }

    /** @return string[] */
    private function phpFilesIn(array $relativeDirs): array
    {
        return $this->filesIn(array_map(fn($d) => self::root() . '/' . $d, $relativeDirs), 'php');
    }

    /** @return string[] */
    private function filesIn(array $dirs, string $extension): array
    {
        $found = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === $extension) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }
}
