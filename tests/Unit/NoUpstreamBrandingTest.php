<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Guards the fork boundary: nothing shipped may still say "Booked".
 *
 * The rebrand was done with search-and-replace, and search-and-replace misses
 * what it was not told to look for. Every one of these was live at some point
 * after the rename "looked done":
 *
 *  - all eight translation catalogs returned 'Booked' for `plugin.name`, which
 *    is what `Slots::displayName()` renders, so the whole CP said Booked
 *  - IcsHelper wrote `PRODID:-//Booked Plugin//EN` into every calendar file
 *    handed to an attendee
 *  - the payments docs pointed Stripe at `/booked/api/v1/payment/webhook/…`,
 *    a 404, so a customer following them would get no webhooks at all
 *  - the wizard JS still exported window.Booked / BookedDateTime globals
 *  - docs advertised `craft.booked`, `booked-viewBookings`, `templates/_booked/`
 *    and `php craft booked/…` — none of which exist here
 *
 * "Booked" as an English past participle is fine ("fully booked", "Booked On"),
 * so this asserts on brand-shaped references rather than the bare word.
 *
 * Upstream is still referenced on purpose in the files listed in ALLOWED — the
 * fork notice, the sync contract, the importer contract and the store listing
 * all have to name Booked to make sense.
 */
class NoUpstreamBrandingTest extends TestCase
{
    /**
     * Files whose whole purpose is to talk about upstream.
     *
     * @var string[]
     */
    private const ALLOWED = [
        'SYNC.md',
        'CHANGELOG.md',
        'README.md',
        'docs/SCOPE.md',
        'docs/STORE_LISTING.md',
        'tests/Unit/ImportContractTest.php',
        'tests/Unit/ControlPanelWiringTest.php',
        'tests/Unit/NoUpstreamBrandingTest.php',
    ];

    /** Directories that are build output or third-party code. */
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git', 'dist', 'coverage', '.phpstan', '.php-cs-fixer.cache'];

    /**
     * Brand-shaped patterns => why each one matters if it reappears.
     *
     * @return array<string, string[]>
     */
    public static function brandPatternProvider(): array
    {
        return [
            'namespace' => ['/anvildev[\\\\\\/]booked/i', 'PHP namespace / package path'],
            'package name' => ['/craft-booked/i', 'upstream Composer package'],
            'api route' => ['#\bbooked/api/v1#i', 'front-end API base — 404s here'],
            'action path' => ["#(actions/|actionInput\\(')booked/#i", 'controller action path'],
            'console command' => ['/craft\s+booked\//i', 'console command prefix'],
            'twig variable' => ['/\bcraft\.booked\b/i', 'Twig variable — is craft.slots'],
            'translation category' => ["/[|(]\\s*t\\(\\s*['\"]booked['\"]/i", 'translation category — is slots'],
            'table prefix' => ['/\bbooked_[a-z]/i', 'database table prefix — is slots_'],
            'permission handle' => ['/\bbooked-[a-zA-Z]/i', 'permission handle — is slots-'],
            'template path' => ['#\btemplates/_?booked/#i', 'template override path'],
            'js global' => ['/\bwindow\.Booked\b|\bBooked(DateTime|Validation|ServiceSchedules)\b/', 'JS global'],
            'asset alias' => ['#@anvildev/booked#i', 'asset bundle source alias'],
            // "Booked" followed by a capitalised word reads as the product name.
            // The narrow (Plugin|Control Panel|Dashboard) list missed the console
            // banner "Booked Health Check", which every `slots/doctor` run printed.
            // "Booked On" is exempt: it is the bookings-index column header, where
            // the word is the past participle it looks like.
            'proper noun' => ['/\bBooked (?!On\b)[A-Z][a-z]+\b/', 'user-visible product name'],
            'ics producer' => ['#PRODID:.*Booked#i', 'calendar file producer — attendees see this'],
            'runtime key' => ['/[\'"]booked:/i', 'cache / mutex key prefix'],
            'env var' => ['/\bBOOKED_[A-Z]/', 'environment variable'],
            'data attribute' => ['/\bdata-booked-/i', 'DOM contract — markup emits data-slots-*'],
            'js seam' => ['/__bookedController/', 'auto-init seam on the wizard element'],
        ];
    }

    /**
     * @dataProvider brandPatternProvider
     */
    public function testNoShippedFileCarriesUpstreamBranding(string $pattern, string $why): void
    {
        $offenders = [];

        foreach ($this->shippedFiles() as $relative => $path) {
            $contents = file_get_contents($path);
            if (preg_match($pattern, $contents)) {
                preg_match($pattern, $contents, $m);
                $offenders[] = "{$relative} ({$m[0]})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Upstream branding leaked back in — {$why}:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * The translated plugin name is what the whole control panel renders.
     */
    public function testEveryLocaleNamesThePluginSlots(): void
    {
        $catalogs = glob(self::root() . '/src/translations/*/slots.php');
        $this->assertNotEmpty($catalogs, 'Expected translation catalogs');

        foreach ($catalogs as $catalog) {
            $locale = basename(dirname($catalog));
            $messages = require $catalog;

            $this->assertSame(
                'Slots',
                $messages['plugin.name'] ?? null,
                "Locale '{$locale}' does not name the plugin Slots — this is what Slots::displayName() renders",
            );
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every text file the plugin ships, keyed by repo-relative path.
     *
     * @return array<string, string>
     */
    private function shippedFiles(): array
    {
        $root = self::root();
        $keep = ['php', 'twig', 'js', 'mjs', 'ts', 'css', 'md', 'json', 'xml', 'neon', 'sh', 'yaml', 'yml'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            $relative = str_replace($root . '/', '', $file->getPathname());
            $first = explode('/', $relative)[0];

            if (in_array($first, self::SKIP_DIRS, true)) {
                continue;
            }
            if (!$file->isFile() || !in_array($file->getExtension(), $keep, true)) {
                continue;
            }
            // Generated bundle: it is only ever as clean as its sources, which
            // are checked here anyway, and it is regenerated by `npm run build`.
            if (str_ends_with($relative, 'slots-wizard.umd.js')) {
                continue;
            }
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $files[$relative] = $file->getPathname();
        }

        return $files;
    }
}
