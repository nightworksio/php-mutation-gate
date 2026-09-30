<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\PackageWork;
use NightWorksIO\MutationGate\Core\Plan\Run;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$weighed = static fn(string $path, float $cost): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::root()),
    Seconds::of($cost),
);

/**
 * Each run as the paths of its units.
 *
 * @param  list<Run>          $runs
 * @return list<list<string>>
 */
function pathsOfRuns(array $runs): array
{
    $paths = [];

    foreach ($runs as $run) {
        $paths[] = array_map(static fn(Weighed $unit): string => $unit->unit()->path()->value(), iterator_to_array($run, preserve_keys: false));
    }

    return $paths;
}

it('takes its units in path order, however they came', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('B', 1.0), $weighed('A', 1.0))->runs(1)))->toBe([['A', 'B']]);
});

it('cuts after the unit that reaches an equal share exactly', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('A', 300.0), $weighed('B', 300.0))->runs(2)))->toBe([['A'], ['B']]);
});

it('keeps a unit that stops short of its share in the run before it, and leaves no run empty', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('A', 299.0), $weighed('B', 301.0))->runs(2)))->toBe([['A', 'B']]);
});

it('cuts each run on the first unit that passes its share of the whole', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 100.0), $weighed('B', 100.0), $weighed('C', 100.0), $weighed('D', 100.0), $weighed('E', 100.0));

    expect(pathsOfRuns($work->runs(3)))->toBe([['A', 'B'], ['C', 'D'], ['E']]);
});

it('cuts no more runs than asked, whatever the later units weigh', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 100.0), $weighed('B', 400.0), $weighed('C', 100.0), $weighed('D', 0.0));

    expect(pathsOfRuns($work->runs(2)))->toBe([['A', 'B'], ['C', 'D']]);
});

it('keeps every unit in one run when one is asked for', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('A', 100.0), $weighed('B', 200.0))->runs(1)))->toBe([['A', 'B']]);
});

it('cuts nothing into no runs', function (): void {
    expect(PackageWork::of()->runs(1))->toBe([]);
});

it('adds up what its units cost', function () use ($weighed): void {
    expect(PackageWork::of($weighed('A', 1.5), $weighed('B', 2.25))->cost())->toEqual(Seconds::of(3.75))
        ->and(PackageWork::of()->cost())->toEqual(Seconds::of(0.0));
});

it('fills a shard for each part of a size its cost takes, and at least one', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 250.0), $weighed('B', 350.0));

    expect($work->shardsAt(600.0))->toBe(1)
        ->and($work->shardsAt(599.0))->toBe(2)
        ->and($work->shardsAt(200.0))->toBe(3)
        ->and($work->shardsAt(0.0))->toBe(1)
        ->and(PackageWork::of($weighed('A', 0.0))->shardsAt(600.0))->toBe(1);
});
