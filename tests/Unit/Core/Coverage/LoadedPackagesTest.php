<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** @return list<string> */
function loadingTests(LoadedPackages $loaded, string ...$packages): array
{
    return array_map(static fn(TestId $test): string => $test->value(), [...$loaded->testsLoading(Listed::of(...$packages))]);
}

it('counts a package as loaded by the test that first loaded it and each test after it in its process, and no other', function (): void {
    $loaded = LoadedPackages::of(
        ProcessLoads::of(TestLoad::of('A::a'), TestLoad::of('A::b', 'acme/one'), TestLoad::of('A::c', 'acme/two')),
        ProcessLoads::of(TestLoad::of('B::a', 'acme/two'), TestLoad::of('B::b')),
    );

    expect(loadingTests($loaded, 'acme/one'))->toBe(['A::b', 'A::c'])
        ->and(loadingTests($loaded, 'acme/two'))->toBe(['A::c', 'B::a', 'B::b'])
        ->and(loadingTests($loaded, 'acme/one', 'acme/two'))->toBe(['A::b', 'A::c', 'B::a', 'B::b'])
        ->and(loadingTests($loaded, 'acme/none'))->toBe([]);
});

it('counts a test that started a process as loading every package, and the tests before and after it as they are', function (): void {
    $loaded = LoadedPackages::of(ProcessLoads::of(
        TestLoad::of('A::a'),
        TestLoad::of('A::b')->startingProcesses(),
        TestLoad::of('A::c'),
    ));

    expect(loadingTests($loaded, 'acme/none'))->toBe(['A::b']);
});

it('covers tests only where each is recorded', function (): void {
    $loaded = LoadedPackages::of(ProcessLoads::of(TestLoad::of('A::a'), TestLoad::of('A::b')));
    $of = static fn(string ...$tests): TestIds => TestIds::of(...array_map(TestId::of(...), array_values($tests)));

    expect($loaded->covers($of('A::a', 'A::b')))->toBeTrue()
        ->and($loaded->covers($of('A::a')))->toBeTrue()
        ->and($loaded->covers($of('A::a', 'B::a')))->toBeFalse()
        ->and(LoadedPackages::unrecorded()->covers($of()))->toBeFalse()
        ->and(LoadedPackages::of()->covers($of()))->toBeFalse();
});

it('gives back its processes and the record of each test', function (): void {
    $test = TestLoad::of('A::a', 'acme/one', 'acme/two')->startingProcesses();
    $processes = [...LoadedPackages::of(ProcessLoads::of($test))->processes()];

    expect($test->test()->value())->toBe('A::a')
        ->and([...$test->packages()])->toBe(['acme/one', 'acme/two'])
        ->and($test->startsProcesses())->toBeTrue()
        ->and(TestLoad::of('A::a')->startsProcesses())->toBeFalse()
        ->and([...$processes[0]->tests()])->toBe([$test]);
});

it('joins a later run of some of the tests to what it holds', function (): void {
    $earlier = ProcessLoads::of(TestLoad::of('A::a'));
    $later = ProcessLoads::of(TestLoad::of('A::a', 'acme/one'));

    expect(LoadedPackages::of($earlier)->and(LoadedPackages::of($later)))->toEqual(LoadedPackages::of($earlier, $later))
        ->and(loadingTests(LoadedPackages::of($earlier)->and(LoadedPackages::of($later)), 'acme/one'))->toBe(['A::a']);
});
