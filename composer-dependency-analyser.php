<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * G4 — no dev dependency is reachable from the code that ships, and every
 * dependency is used.
 *
 * `src` and each plugin's `src` are what a project that requires this package
 * runs. Anything they name that only `require-dev` installs is absent there,
 * and the first a user hears of it is a fatal error in their CI.
 */

return (new Configuration())
    // A dev tool invoked from a composer script is never named in PHP, so every
    // dev dependency is either used in code or listed below with what runs it.
    ->enableAnalysisOfUnusedDevDependencies()
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/plugins/default/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    ->addPathToScan(__DIR__ . '/plugins/default/tests', isDev: true)
    ->addPathToScan(__DIR__ . '/phpstan', isDev: true)
    // The runner contract suite's fixture is a project of its own, with its own
    // dependencies.
    ->addPathToExclude(__DIR__ . '/tests/Contract/Runner/fixture')
    ->addPathToExclude(__DIR__ . '/tests/Contract/Runner/infection-fixture')
    ->addPathToExclude(__DIR__ . '/tests/Contract/Runner/phpunit-fixture')
    ->addPathToExclude(__DIR__ . '/tests/Contract/StaticChecker/fixture')
    ->ignoreErrorsOnPackages(
        [
            // Included by phpstan.neon, which is configuration rather than code.
            'phpstan/phpstan-strict-rules',
            'phpstan/phpstan-deprecation-rules',
            'ergebnis/phpstan-rules',
            'shipmonk/phpstan-rules',
            'spaze/phpstan-disallowed-calls',
            'tomasvotruba/type-coverage',
            'tomasvotruba/cognitive-complexity',
            // Binaries a composer script runs.
            'laravel/pint',
            'rector/rector',
            'ergebnis/composer-normalize',
            'shipmonk/composer-dependency-analyser',
            // S2 — conflict-only: it fails resolution when a dependency has a
            // published advisory, and ships no code to name.
            'roave/security-advisories',
        ],
        [ErrorType::UNUSED_DEPENDENCY],
    )
    // The S3 proof store's client and the YAML and NEON config readers, which
    // are suggested rather than required: a project that keeps its ledger in a
    // bucket, or writes its config in YAML or NEON, installs the library, and
    // the gate builds each adapter only where it is chosen and installed.
    ->ignoreErrorsOnPackages(
        ['async-aws/core', 'async-aws/s3', 'symfony/yaml', 'nette/neon'],
        [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
    // The Pest adapter and its plugin run only in a project that runs Pest,
    // which installs these; composer.json suggests Pest, and its `conflict`
    // holds pest-plugin-mutate to the release the runner contract suite passes.
    ->ignoreErrorsOnPackages(
        ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/php-code-coverage', 'phpunit/phpunit'],
        [ErrorType::DEV_DEPENDENCY_IN_PROD],
    )
    // The Infection adapter runs only in a project that runs Infection, which
    // requires these: it reads the project's infection.json5, and PHPUnit's XML
    // coverage and JUnit log. composer.json suggests each.
    ->ignoreErrorsOnPackages(['colinodell/json5'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // Importing an Infection config reads the installed Infection's own
    // profile list, where Infection is installed; it asks first.
    ->ignoreErrorsOnPackages(['infection/infection'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnExtension('ext-dom', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
