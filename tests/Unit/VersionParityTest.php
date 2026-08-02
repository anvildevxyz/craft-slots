<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * The plugin ships as one product, but says its version in four places:
 * composer.json, package.json and a `version` export in each of the two JS
 * entry points. Nothing kept them together, and they had already drifted to
 * 1.0.0, 0.1.0 and 1.0.0-dev — the JS one being a published export, so
 * integrators read a version the plugin never had.
 */
class VersionParityTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function composerVersion(): string
    {
        $json = json_decode(file_get_contents(self::root() . '/composer.json'), true);
        return (string)($json['version'] ?? '');
    }

    public function testComposerDeclaresAVersion(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', self::composerVersion());
    }

    public function testPackageJsonMatchesComposer(): void
    {
        $json = json_decode(file_get_contents(self::root() . '/package.json'), true);
        $this->assertSame(
            self::composerVersion(),
            (string)($json['version'] ?? ''),
            'package.json version must match composer.json',
        );
    }

    /**
     * @dataProvider jsEntryPointProvider
     */
    public function testJsEntryPointMatchesComposer(string $relativePath): void
    {
        $source = file_get_contents(self::root() . '/' . $relativePath);
        preg_match("/export const version = '([^']+)'/", $source, $m);

        $this->assertNotEmpty($m, "{$relativePath} must export a version");
        $this->assertSame(
            self::composerVersion(),
            $m[1],
            "{$relativePath} exports a version that does not match composer.json",
        );
    }

    /** @return array<string, array{string}> */
    public static function jsEntryPointProvider(): array
    {
        return [
            'headless core' => ['src/web/js/core/index.js'],
            'rendered entry' => ['src/web/js/ui/index.js'],
        ];
    }
}
