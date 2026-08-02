<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        // Skip migrations directory
        __DIR__ . '/src/migrations/*',

        // Skip templates directory
        __DIR__ . '/src/templates/*',

        // Skip web assets
        __DIR__ . '/src/web/*',

        // Skip translations
        __DIR__ . '/src/translations/*',

        // Rector rules to skip
        RemoveExtraParametersRector::class,
    ])
    ->withPhpSets(php80: true);
