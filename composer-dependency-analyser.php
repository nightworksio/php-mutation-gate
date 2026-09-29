<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * G4 — no dev dependency is reachable from the code that ships, and every
 * dependency is used.
 *
 * `src` is what a project that requires this package runs. Anything it names
 * that only `require-dev` installs is absent there, and the first a user hears
 * of it is a fatal error in their CI.
 */

return (new Configuration())
    // A dev tool invoked from a composer script is never named in PHP, so every
    // dev dependency is either used in code or listed below with what runs it.
    ->enableAnalysisOfUnusedDevDependencies()
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    ->addPathToScan(__DIR__ . '/phpstan', isDev: true)
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
    // The libraries ADR-0001 builds the adapters on: the clock, the process
    // runner and the HTTP client. They are required so a project installs the
    // versions the package supports, and no file under src names them; the
    // tests run processes with the process runner.
    ->ignoreErrorsOnPackages(['psr/clock', 'symfony/http-client'], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('symfony/process', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);
