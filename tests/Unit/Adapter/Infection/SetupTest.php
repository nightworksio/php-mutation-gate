<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Setup;
use NightWorksIO\MutationGate\Adapter\Infection\StaticAnalysis;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('allows each mutant 10 s, refuses native markers and finds the tests in tests, by default', function (): void {
    $setup = Setup::of(Options::none());

    expect($setup instanceof Setup ? [$setup->cap(), $setup->allowsNativeMarkers(), $setup->tests(), $setup->analysis()] : [])
        ->toEqual([Seconds::of(10.0), false, Paths::of(Path::of('tests')), StaticAnalysis::Infection]);
});

it('leaves static analysis to the gate where the flows say it checks the survivors', function (): void {
    $setup = Setup::of(Configs::options('{"staticAnalysis": "gate"}'));

    expect($setup instanceof Setup ? $setup->analysis() : $setup)->toBe(StaticAnalysis::Gate);
});

it('takes the timeout, the native markers and the test directories the flows write', function (): void {
    $setup = Setup::of(Configs::options('{"timeout": 30, "nativeMarkers": "allow", "tests": ["tests/Unit", "tests/Feature"]}'));
    $refusing = Setup::of(Configs::options('{"nativeMarkers": "refuse"}'));

    expect($setup instanceof Setup ? [$setup->cap(), $setup->allowsNativeMarkers(), $setup->tests()] : [])
        ->toEqual([Seconds::of(30.0), true, Paths::of(Path::of('tests/Unit'), Path::of('tests/Feature'))])
        ->and($refusing instanceof Setup && ! $refusing->allowsNativeMarkers())->toBeTrue();
});

it('is invalid where an option is not in its shape', function (): void {
    expect(Setup::of(Configs::options('{"nativeMarkers": "maybe"}')))
        ->toEqual(Invalid::because(Problem::at('nativeMarkers', 'expected "refuse" or "allow", got "maybe"')))
        ->and(Setup::of(Configs::options('{"nativeMarkers": 1}')))
        ->toEqual(Invalid::because(Problem::at('nativeMarkers', 'expected text, got 1')))
        ->and(Setup::of(Configs::options('{"timeout": "10s"}')))
        ->toEqual(Invalid::because(Problem::at('timeout', 'expected a number, got "10s"')))
        ->and(Setup::of(Configs::options('{"staticAnalysis": "both"}')))
        ->toEqual(Invalid::because(Problem::at('staticAnalysis', 'expected "infection" or "gate", got "both"')))
        ->and(Setup::of(Configs::options('{"staticAnalysis": 1}')))
        ->toEqual(Invalid::because(Problem::at('staticAnalysis', 'expected text, got 1')))
        ->and(Setup::of(Configs::options('{"tests": "tests"}')))
        ->toEqual(Invalid::because(Problem::at('tests', 'expected a list of paths, got "tests"')));
});
