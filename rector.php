<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    // R3 — the same trees phpstan.neon and the Arch suite read, and the executables.
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/plugins',
        __DIR__ . '/tests',
        __DIR__ . '/phpstan',
        __DIR__ . '/bin/mutation-gate',
        __DIR__ . '/bin/mutation-gate-worker',
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
            // Core reads #[Holds], PHPUnit's #[Group] and its #[Depends] from tokens by
            // their names, and names nothing of the attribute layer (A5) or of PHPUnit (A1).
            __DIR__ . '/src/Core/Php/HoldsReader.php',
            __DIR__ . '/src/Core/Php/DependsReader.php',
        ],
        // Pest binds a closure in a dataset to the test case before calling it,
        // and the closure of a static method cannot be bound.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [__DIR__ . '/tests/Contract'],
        // The runner contract suite's fixture is a project of its own, written as a user's library is.
        __DIR__ . '/tests/Contract/Runner/fixture',
        __DIR__ . '/tests/Contract/Runner/infection-fixture',
        __DIR__ . '/tests/Contract/Runner/crash',
        __DIR__ . '/tests/Contract/Runner/reach',
        __DIR__ . '/tests/Contract/Runner/stall',
        __DIR__ . '/tests/Contract/Runner/override',
        __DIR__ . '/tests/Contract/Runner/interceptor',
        __DIR__ . '/tests/Contract/Runner/twins',
        __DIR__ . '/tests/Contract/Runner/phpunit-fixture',
        // The static checker contract suite's fixture holds mutants, which are wrong on purpose.
        __DIR__ . '/tests/Contract/StaticChecker/fixture',
    ])
    ->withImportNames(importShortClasses: false)
    ->withCache(__DIR__ . '/.rector-cache');
