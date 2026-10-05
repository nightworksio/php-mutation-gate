<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\PackageWork;
use NightWorksIO\MutationGate\Core\Plan\Run;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Growth;

$weighed = static fn(string $path, float $cost): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::root()),
    Estimated::of(Seconds::of($cost), CostBasis::Guessed),
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

it('gives a unit that stops short of its share a run of its own where that makes the costliest run cheaper', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('A', 299.0), $weighed('B', 301.0))->runs(2)))->toBe([['A'], ['B']]);
});

it('cuts so the costliest run costs as little as any cut in path order, in fewer runs where more would cost no less', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 100.0), $weighed('B', 100.0), $weighed('C', 100.0), $weighed('D', 100.0), $weighed('E', 100.0));
    $lopsided = PackageWork::of($weighed('A', 10.0), $weighed('B', 90.0), $weighed('C', 60.0), $weighed('D', 40.0));

    expect(pathsOfRuns($work->runs(3)))->toBe([['A', 'B'], ['C', 'D'], ['E']])
        ->and(pathsOfRuns($lopsided->runs(2)))->toBe([['A', 'B'], ['C', 'D']])
        ->and(pathsOfRuns($lopsided->runs(3)))->toBe([['A', 'B'], ['C', 'D']]);
});

it('cuts no more runs than asked, leaves none empty, and gives a unit costlier than any share a run of its own', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 100.0), $weighed('B', 400.0), $weighed('C', 100.0), $weighed('D', 0.0));

    expect(pathsOfRuns($work->runs(2)))->toBe([['A', 'B'], ['C', 'D']])
        ->and(pathsOfRuns($work->runs(3)))->toBe([['A'], ['B'], ['C', 'D']])
        ->and(pathsOfRuns($work->runs(9)))->toBe([['A'], ['B'], ['C', 'D']]);
});

it('cuts units of 900, 900, 100, 900 and 100 seconds into three runs, the costliest 1000 seconds', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 900.0), $weighed('B', 900.0), $weighed('C', 100.0), $weighed('D', 900.0), $weighed('E', 100.0));
    $costs = array_map(static fn(Run $run): float => $run->cost()->seconds(), $work->runs(3));

    expect(pathsOfRuns($work->runs(3)))->toBe([['A'], ['B', 'C'], ['D', 'E']])
        ->and($costs)->toBe([900.0, 1000.0, 1000.0]);
});

it('keeps units that cost nothing together in one run', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('A', 0.0), $weighed('B', 0.0))->runs(2)))->toBe([['A', 'B']]);
});

it('finds the least bound to the float, however uneven the costs', function () use ($weighed): void {
    $work = PackageWork::of($weighed('A', 0.1), $weighed('B', 0.2), $weighed('C', 0.3), $weighed('D', 0.4), $weighed('E', 0.7));
    $costs = array_map(static fn(Run $run): float => $run->cost()->seconds(), $work->runs(2));

    expect(pathsOfRuns($work->runs(2)))->toBe([['A', 'B', 'C', 'D'], ['E']])
        ->and(max([0.0, ...$costs]))->toBe(1.0);
});

it('cuts by the least bound that holds, never the one just below it, whatever float lies halfway between them', function () use ($weighed): void {
    expect(pathsOfRuns(PackageWork::of($weighed('A', 21.90625), $weighed('B', 1.1555555555555554))->runs(1)))->toBe([['A', 'B']])
        ->and(pathsOfRuns(PackageWork::of($weighed('A', 18.615384615384617), $weighed('B', 10.044444444444444))->runs(1)))->toBe([['A', 'B']]);
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

it('cuts its units in time near linear in their number', function () use ($weighed): void {
    $cutting = static function (int $size) use ($weighed): Closure {
        $work = PackageWork::of(...array_map(static fn(int $at): Weighed => $weighed(sprintf('src/F%05d.php', $at), (float) ($at % 7 + 1)), range(1, $size)));

        return static fn(): int => count($work->runs(8));
    };

    expect($cutting(16)())->toBe(7)
        ->and(Growth::of(400, $cutting))->toBeLessThan(Growth::LINEAR);
});
