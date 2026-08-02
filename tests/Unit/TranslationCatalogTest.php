<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Guards the translation catalogs.
 *
 * P6 pruned 336 keys left behind by the removed features. These tests stop the
 * two ways that goes wrong again: a locale drifting out of parity with `en`
 * (which is how `permissions.manageRefunds` shipped untranslated in six
 * locales), and a key being referenced in source but never added to a catalog
 * (which renders the raw key in the UI rather than failing loudly).
 */
class TranslationCatalogTest extends TestCase
{
    private const CATEGORY = 'slots';

    private static function translationsDir(): string
    {
        return dirname(__DIR__, 2) . '/src/translations';
    }

    /** @return string[] */
    private static function locales(): array
    {
        $dirs = glob(self::translationsDir() . '/*', GLOB_ONLYDIR) ?: [];
        return array_map('basename', $dirs);
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        return require self::translationsDir() . "/{$locale}/" . self::CATEGORY . '.php';
    }

    public function testEnglishCatalogIsNotEmpty(): void
    {
        $this->assertNotEmpty(self::catalog('en'), 'The en catalog should not be empty');
    }

    public function testEveryLocaleHasExactlyTheEnglishKeys(): void
    {
        $en = array_keys(self::catalog('en'));
        sort($en);

        foreach (self::locales() as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $keys = array_keys(self::catalog($locale));
            sort($keys);

            $missing = array_diff($en, $keys);
            $extra = array_diff($keys, $en);

            $this->assertSame([], array_values($missing), "Locale '{$locale}' is missing keys present in en");
            $this->assertSame([], array_values($extra), "Locale '{$locale}' has keys that en does not");
        }
    }

    /**
     * A key referenced in source but defined nowhere renders as the raw key.
     *
     * Parity alone does not catch this: a key missing from *every* catalog is
     * perfectly in parity. Fifty of them shipped that way — the whole calendar
     * section rendered `calendar.today`, `calendar.week` and friends, because
     * the calendar templates were restored after the P6 prune had already
     * dropped their keys.
     */
    public function testEveryReferencedKeyIsDefined(): void
    {
        $en = self::catalog('en');
        $undefined = [];

        foreach (self::sourceFiles() as $relative => $path) {
            foreach (self::referencedKeys(file_get_contents($path)) as $key) {
                if (!array_key_exists($key, $en)) {
                    $undefined[$key][] = $relative;
                }
            }
        }

        $report = [];
        foreach ($undefined as $key => $files) {
            $report[] = $key . ' <- ' . implode(', ', array_unique($files));
        }

        $this->assertSame(
            [],
            $report,
            "These keys are referenced in source but defined in no catalog, so the UI shows the raw key:\n  "
                . implode("\n  ", $report),
        );
    }

    /**
     * The other direction: a key nothing references is dead weight, and it is
     * how the removed calendar-sync and events features left eleven strings
     * behind in all eight catalogs.
     *
     * The rule is deliberately loose — a key counts as referenced if its
     * literal appears anywhere in source. Keys reach `Craft::t()` indirectly
     * often enough (nav labels via `$def[1]`, refund guards thrown as
     * `RuntimeException('payment.refundBusy')`, IcsHelper's `$descMap`) that
     * matching only on call-shaped references would report false positives.
     */
    public function testEveryDefinedKeyIsReferenced(): void
    {
        $source = '';
        foreach (self::sourceFiles() as $path) {
            $source .= file_get_contents($path) . "\n";
        }

        // Some keys are composed at runtime — `('payment.status.' ~ status)|t`
        // never appears in source as a whole key. Collect those prefixes from
        // the source itself so the check covers them instead of tripping over
        // them; a literal allowlist would rot the moment a prefix is renamed.
        preg_match_all("/'([a-zA-Z][a-zA-Z0-9_.]*\\.)'\\s*~/", $source, $m);
        $dynamicPrefixes = array_unique($m[1] ?? []);

        $unreferenced = [];
        foreach (array_keys(self::catalog('en')) as $key) {
            if (str_contains($source, $key)) {
                continue;
            }
            foreach ($dynamicPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    continue 2;
                }
            }
            $unreferenced[] = $key;
        }

        $this->assertSame(
            [],
            $unreferenced,
            "These keys are defined in every catalog but referenced nowhere in source:\n  "
                . implode("\n  ", $unreferenced),
        );
    }

    /**
     * Every translatable string the plugin ships, keyed by src-relative path.
     *
     * The generated wizard bundle is skipped: it would vouch for keys that no
     * longer exist in the sources it is built from.
     *
     * @return array<string, string>
     */
    private static function sourceFiles(): array
    {
        $src = dirname(__DIR__, 2) . '/src';
        $keep = ['php', 'twig', 'js'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $relative = str_replace($src . '/', '', $file->getPathname());

            if (str_starts_with($relative, 'translations/')) {
                continue;
            }
            if (!$file->isFile() || !in_array($file->getExtension(), $keep, true)) {
                continue;
            }
            if (str_ends_with($relative, '.umd.js') || str_ends_with($relative, '.test.js')) {
                continue;
            }

            $files[$relative] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Key-shaped translation references in one file.
     *
     * Only dotted identifiers are collected. Craft also accepts an English
     * sentence as its own key, and those legitimately have no catalog entry.
     *
     * @return string[]
     */
    private static function referencedKeys(string $contents): array
    {
        $category = preg_quote(self::CATEGORY, '/');
        $patterns = [
            // Craft::t('slots', 'key') and Yii::t('slots', 'key')
            "/(?:Craft|Yii)::t\(\s*'" . $category . "'\s*,\s*'([^']+)'/",
            // Twig: 'key'|t('slots') and "key"|t('slots')
            "/'([^']+)'\s*\|\s*t\(\s*'" . $category . "'/",
            '/"([^"]+)"\s*\|\s*t\(\s*[\'"]' . $category . '[\'"]/',
        ];

        $keys = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $contents, $matches)) {
                foreach ($matches[1] as $key) {
                    if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*\.[a-zA-Z][a-zA-Z0-9.]*$/', $key)) {
                        $keys[] = $key;
                    }
                }
            }
        }

        return array_unique($keys);
    }

    /**
     * Only a genuinely empty string is a bug. A whitespace-only value can be
     * deliberate: `labels.at` is " " in Japanese because the language takes no
     * preposition between a date and a time.
     */
    public function testNoLocaleHasAnEmptyValue(): void
    {
        foreach (self::locales() as $locale) {
            foreach (self::catalog($locale) as $key => $value) {
                $this->assertNotSame('', (string) $value, "Locale '{$locale}' has an empty value for '{$key}'");
            }
        }
    }
}
