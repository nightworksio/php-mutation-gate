<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Setup;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Options;

it('allows each mutant 10 s, refuses native markers and finds the tests in tests, by default', function (): void {
    $setup = Setup::of(Options::none());

    expect($setup instanceof Setup ? [$setup->cap(), $setup->allowsNativeMarkers(), $setup->tests()] : [])
        ->toEqual([Seconds::of(10.0), false, Paths::of(Path::of('tests'))]);
});

it('takes the timeout, the native markers and the test directories the flows write', function (): void {
    $setup = Setup::of(Options::ofJson('{"timeout": 30, "nativeMarkers": "allow", "tests": ["tests/Unit", "tests/Feature"]}'));
    $refusing = Setup::of(Options::ofJson('{"nativeMarkers": "refuse"}'));

    expect($setup instanceof Setup ? [$setup->cap(), $setup->allowsNativeMarkers(), $setup->tests()] : [])
        ->toEqual([Seconds::of(30.0), true, Paths::of(Path::of('tests/Unit'), Path::of('tests/Feature'))])
        ->and($refusing instanceof Setup && ! $refusing->allowsNativeMarkers())->toBeTrue();
});

it('is invalid where an option is not in its shape', function (): void {
    expect(Setup::of(Options::ofJson('{"nativeMarkers": "maybe"}')))
        ->toEqual(Invalid::because(Problem::at('runner', 'the file.nativeMarkers is not "refuse" or "allow".')))
        ->and(Setup::of(Options::ofJson('{"timeout": "10s"}')))
        ->toEqual(Invalid::because(Problem::at('runner', 'the file.timeout is not a number.')))
        ->and(Setup::of(Options::ofJson('{"tests": "tests"}')))
        ->toEqual(Invalid::because(Problem::at('runner', 'the file.tests is not a list.')));
});
