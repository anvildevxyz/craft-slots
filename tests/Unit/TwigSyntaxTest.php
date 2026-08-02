<?php

namespace anvildev\slots\tests\Unit;

use anvildev\slots\tests\Support\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Compiles every template so an unbalanced tag fails the build.
 *
 * Deleting features from Twig with regexes left two templates with orphaned
 * `{% endfor %}` tags — the CP dashboard and the booking edit screen both 500'd
 * with a Twig\Error\SyntaxError. Nothing in a source-scanning suite can see
 * that; only parsing the template can.
 *
 * This parses rather than renders, so no Craft bootstrap or database is needed.
 * Craft's own tags, filters and functions are registered as no-op stubs purely
 * so the parser recognises them — this test says nothing about whether a
 * template *works*, only that it is syntactically valid.
 */
class TwigSyntaxTest extends TestCase
{
    /** Craft + plugin filters used across the templates. */
    private const FILTERS = [
        't', 'currency', 'date', 'datetime', 'time', 'number', 'filesize', 'json_encode', 'raw',
        'markdown', 'md', 'ucfirst', 'lcfirst', 'kebab', 'camel', 'snake', 'pascal', 'namespace',
        'id', 'hash', 'attr', 'literal', 'encenc', 'index', 'group', 'without', 'withoutKey',
        'values', 'duration', 'timestamp', 'multisort', 'intersect', 'append', 'prepend', 'purify',
        'parseRefs', 'money', 'percentage', 'address', 'contains', 'column', 'diff', 'explode',
        'firstWhere', 'push', 'unshift', 'removeClass', 'string', 'boolean', 'float', 'integer', 'json_decode', 'timezone', 'transform',
    ];

    private const FUNCTIONS = [
        'url', 'siteUrl', 'cpUrl', 'actionUrl', 'csrfInput', 'redirectInput', 'svg', 'tag', 'attr',
        'shuffle', 'plugin', 'craft', 'clone', 'expression', 'gql', 'seq', 'combine', 'create',
        'dump', 'className', 'configure', 'collect', 'ceil', 'floor', 'min', 'max', 'random',
        'renderObjectTemplate', 'date', 'now', 'today', 'tomorrow', 'yesterday', 'beginBody',
        'endBody', 'head', 'getenv', 'parseEnv', 'actionInput', 'failMessageInput',
        'successMessageInput', 'hiddenInput', 'input', 'ol', 'ul', 'fieldValueSql', 'canCreateDrafts',
    ];

    /** Craft template tags with no Twig equivalent. */
    private const TAG_STUBS = [
        'css', 'endcss', 'js', 'endjs', 'html', 'endhtml', 'script', 'endscript',
        'cache', 'endcache', 'nav', 'endnav', 'switch', 'endswitch', 'case', 'default',
        'exit', 'header', 'redirect', 'requireLogin', 'requirePermission', 'requireGuest',
        'hook', 'paginate', 'namespace', 'endnamespace', 'tag', 'endtag', 'minify', 'endminify',
    ];

    /** @return array<string, string[]> */
    public static function templateProvider(): array
    {
        $root = dirname(__DIR__, 2) . '/src/templates';
        $cases = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $relative = str_replace($root . '/', '', $file->getPathname());
                $cases[$relative] = [$file->getPathname(), $relative];
            }
        }

        ksort($cases);

        return $cases;
    }

    /**
     * @dataProvider templateProvider
     */
    public function testTemplateParses(string $path, string $relative): void
    {
        $source = file_get_contents($path);

        // Strip Craft-only block tags the stock parser cannot know about. Their
        // bodies are plain markup or JS, so removing the wrapper keeps the rest
        // of the template's tag balance intact.
        $source = preg_replace('/\{%-?\s*(css|js|html|script|minify)\b.*?%\}.*?\{%-?\s*end\1\s*-?%\}/s', '', $source);
        // Remaining Craft statement tags become comments so `{% do %}`,
        // `{% hook %}`, `{% exit %}` and friends do not trip the lexer.
        $stubs = implode('|', self::TAG_STUBS);
        $source = preg_replace('/\{%-?\s*(?:' . $stubs . ')\b[^%]*-?%\}/s', '', $source);
        // `{% extends %}` / `{% include %}` targets are Craft-resolved paths.
        $source = preg_replace('/\{%-?\s*(?:extends|include|embed|import|from|use)\b[^%]*-?%\}/s', '', $source);
        $source = preg_replace('/\{%-?\s*endembed\s*-?%\}/s', '', $source);

        // Craft adds an `instance of` test that stock Twig does not have.
        $source = preg_replace('/\bis\s+instance\s+of\(/', 'is defined and true and (', $source);

        $twig = new Environment(new ArrayLoader(['t' => $source]), ['cache' => false, 'strict_variables' => false]);
        foreach (self::FILTERS as $filter) {
            $twig->addFilter(new TwigFilter($filter, fn($v = null) => $v));
        }
        foreach (self::FUNCTIONS as $function) {
            $twig->addFunction(new TwigFunction($function, fn(...$a) => null));
        }

        try {
            $twig->parse($twig->tokenize($twig->getLoader()->getSourceContext('t')));
        } catch (SyntaxError $e) {
            $this->fail("{$relative} has a Twig syntax error: {$e->getRawMessage()} (line {$e->getTemplateLine()})");
        }

        $this->assertTrue(true, "{$relative} parses");
    }
}
