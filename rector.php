<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    // R3 — the same trees phpstan.neon and the Arch suite read.
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/phpstan',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withSkip([
        // An architecture rule names namespaces, and a namespace with no class
        // of that name is not a class constant.
        StringClassNameToClassConstantRector::class => [__DIR__ . '/tests/Arch'],
    ])
    ->withImportNames(importShortClasses: false)
    ->withCache(__DIR__ . '/.rector-cache');
