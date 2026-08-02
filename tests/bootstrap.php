<?php

/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment
 */

// Define test environment
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_BASE_PATH', dirname(__DIR__));

// Load Composer autoloader
require_once CRAFT_VENDOR_PATH . '/autoload.php';

// Define YII_DEBUG for testing
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

// Load Yii
require_once CRAFT_VENDOR_PATH . '/yiisoft/yii2/Yii.php';

// Create a minimal Yii application for testing
$config = [
    'id' => 'craft-test',
    'basePath' => CRAFT_BASE_PATH,
    'vendorPath' => CRAFT_VENDOR_PATH,
    'components' => [
        'i18n' => [
            'class' => 'yii\i18n\I18N',
            'translations' => [
                'slots' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => CRAFT_BASE_PATH . '/src/translations',
                ],
            ],
        ],
    ],
];

new yii\console\Application($config);

// PHP 8.4 deprecates implicitly-nullable parameters (`Foo $bar = null`), and
// craftcms/cms is not yet clean — craft\console\Controller::output() is one.
// Yii's error handler promotes any *reported* deprecation to an ErrorException,
// so merely loading a class that extends Craft's console controller aborts the
// test instead of running it.
//
// Report everything except engine deprecations. Warnings, notices and
// E_USER_DEPRECATED still fail the suite, so this hides third-party noise
// without weakening our own checks. Remove once craftcms/cms is PHP 8.4-clean.
//
// This is deliberately here rather than in phpunit.xml's <php> block: PHPUnit
// applies that block after loading the bootstrap, so a value set there wins.
error_reporting(E_ALL & ~E_DEPRECATED);
