<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
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
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/tests/Arch',
            // Core reads #[Holds] from tokens by its name, and names nothing
            // of the attribute layer (A5).
            __DIR__ . '/src/Core/Php/HoldsAttributes.php',
        ],
        // Pest binds a closure in a dataset to the test case before calling it,
        // and the closure of a static method cannot be bound.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [__DIR__ . '/tests/Contract'],
        // The runner contract suite's fixture is a project of its own, written as a user's library is.
        __DIR__ . '/tests/Contract/Runner/fixture',
        __DIR__ . '/tests/Contract/Runner/infection-fixture',
    ])
    ->withImportNames(importShortClasses: false)
    ->withCache(__DIR__ . '/.rector-cache');
