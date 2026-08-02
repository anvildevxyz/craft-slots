<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;

/**
 * Guards the test suite against silently losing tests.
 *
 * The strip-down deleted features by cutting text out of files, and in 26 test
 * files it cut the opening `<?php`, the namespace and the class declaration
 * along with them. PHPUnit does not report a file it cannot find a test class
 * in — it simply runs nothing — so roughly 2,500 lines of assertions stopped
 * running without a single red build. SecurityFixesTest, TimezoneTest and
 * ReminderServiceTest were among them.
 *
 * A test file that declares no test class is worse than a deleted one: the
 * deleted file is visible in review, the hollow one still looks like coverage.
 *
 * QUARANTINE held that debt while it was being worked through, and is now
 * empty: 16 files were restored from git and adapted to what still ships, and 9
 * were deleted because the feature they covered is gone. It stays here as the
 * pressure valve for a repair that has to land over more than one commit. The
 * list may only ever shrink; adding to it means a test file was hollowed out
 * again.
 */
class TestSuiteIntegrityTest extends TestCase
{
    /**
     * Known-hollow files, relative to tests/. Shrink this list, never grow it.
     *
     * @var string[]
     */
    private const QUARANTINE = [];

    /**
     * @return array<string, string> relative path => absolute path
     */
    private static function testFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[str_replace($root . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    public function testEveryTestFileDeclaresATestClass(): void
    {
        $hollow = [];

        foreach (self::testFiles() as $relative => $path) {
            $contents = file_get_contents($path);

            $opensPhp = str_starts_with(ltrim($contents), '<?php');
            $declaresClass = (bool)preg_match('/^(?:final |abstract )?class \w+/m', $contents);

            if (!$opensPhp || !$declaresClass) {
                $hollow[] = $relative;
            }
        }

        $unexpected = array_values(array_diff($hollow, self::QUARANTINE));
        $this->assertSame(
            [],
            $unexpected,
            "These test files declare no test class, so PHPUnit runs nothing in them:\n  "
                . implode("\n  ", $unexpected),
        );
    }

    /**
     * Keeps the quarantine list honest — a file that has been repaired or
     * deleted has to leave the list, or the list stops meaning anything.
     */
    public function testQuarantineListHasNoStaleEntries(): void
    {
        $files = self::testFiles();
        $stale = [];

        foreach (self::QUARANTINE as $relative) {
            if (!isset($files[$relative])) {
                $stale[] = "{$relative} (file is gone)";
                continue;
            }

            $contents = file_get_contents($files[$relative]);
            if (str_starts_with(ltrim($contents), '<?php') && preg_match('/^(?:final |abstract )?class \w+/m', $contents)) {
                $stale[] = "{$relative} (now declares a class)";
            }
        }

        $this->assertSame(
            [],
            $stale,
            "Remove these from QUARANTINE — they are no longer hollow:\n  " . implode("\n  ", $stale),
        );
    }
}
