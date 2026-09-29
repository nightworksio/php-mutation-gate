<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Runs;
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
 * @param  list<list<Weighed>> $runs
 * @return list<list<string>>
 */
function pathsOfRuns(array $runs): array
{
    $paths = [];

    foreach ($runs as $run) {
        $paths[] = array_map(static fn(Weighed $unit): string => $unit->unit()->path()->value(), $run);
    }

    return $paths;
}

it('cuts after the unit that reaches an equal share exactly', function () use ($weighed): void {
    expect(pathsOfRuns(Runs::into([$weighed('A', 300.0), $weighed('B', 300.0)], 2)))->toBe([['A'], ['B']]);
});

it('keeps a unit that stops short of its share in the run before it, and leaves no run empty', function () use ($weighed): void {
    expect(pathsOfRuns(Runs::into([$weighed('A', 299.0), $weighed('B', 301.0)], 2)))->toBe([['A', 'B']]);
});

it('cuts each run on the first unit that passes its share of the whole', function () use ($weighed): void {
    $units = [$weighed('A', 100.0), $weighed('B', 100.0), $weighed('C', 100.0), $weighed('D', 100.0), $weighed('E', 100.0)];

    expect(pathsOfRuns(Runs::into($units, 3)))->toBe([['A', 'B'], ['C', 'D'], ['E']]);
});

it('cuts no more runs than asked, whatever the later units weigh', function () use ($weighed): void {
    $units = [$weighed('A', 100.0), $weighed('B', 400.0), $weighed('C', 100.0), $weighed('D', 0.0)];

    expect(pathsOfRuns(Runs::into($units, 2)))->toBe([['A', 'B'], ['C', 'D']]);
});

it('keeps every unit in one run when one is asked for', function () use ($weighed): void {
    expect(pathsOfRuns(Runs::into([$weighed('A', 100.0), $weighed('B', 200.0)], 1)))->toBe([['A', 'B']]);
});

it('cuts nothing into no runs', function (): void {
    expect(Runs::into([], 1))->toBe([]);
});

it('adds up what units cost', function () use ($weighed): void {
    expect(Runs::costOf([$weighed('A', 1.5), $weighed('B', 2.25)]))->toBe(3.75)
        ->and(Runs::costOf([]))->toBe(0.0);
});
