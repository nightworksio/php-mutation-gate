<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\PackageWork;
use NightWorksIO\MutationGate\Core\Plan\Run;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$weighed = static fn(string $path, string $package): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::of($package)),
    Estimated::of(Seconds::of(1.0), CostBasis::Guessed),
);

it('counts its units', function () use ($weighed): void {
    expect(Workload::of())->toHaveCount(0)
        ->and(Workload::of($weighed('src/A.php', '.'), $weighed('src/B.php', '.')))->toHaveCount(2);
});

it('groups its units by package, packages and units each in path order', function () use ($weighed): void {
    $work = Workload::of(
        $weighed('packages/b/src/B.php', 'packages/b'),
        $weighed('src/Z.php', '.'),
        $weighed('src/A.php', '.'),
        $weighed('packages/a/src/A.php', 'packages/a'),
    );

    $grouped = array_map(
        static fn(PackageWork $package): array => array_map(
            static fn(Weighed $unit): string => $unit->unit()->path()->value(),
            iterator_to_array($package->runs(1)[0], preserve_keys: false),
        ),
        $work->byPackage(),
    );

    expect($grouped)->toBe([
        ['src/A.php', 'src/Z.php'],
        ['packages/a/src/A.php'],
        ['packages/b/src/B.php'],
    ]);
});

it('prices each package\'s parts of the test files by the coverage map it is timed by', function () use ($weighed): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/A.php'), Line::of(1), TestId::of('ATest::one'))
        ->timed(TestId::of('ATest::one'), Seconds::of(1.0))
        ->timed(TestId::of('BTest::one'), Seconds::of(1.0));
    $work = Workload::of($weighed('src/A.php', '.'));
    $costs = static fn(Workload $work): array => array_map(
        static fn(Run $run): float => $run->cost()->seconds(),
        $work->byPackage()[0]->parts(2),
    );

    expect($costs($work->timedBy($map)))->toBe([1.0, 0.0])
        ->and($costs($work))->toBe([0.5, 0.5]);
});
