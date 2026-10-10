<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$weighed = static fn(string $path, float $cost, string $package = '.'): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::of($package)),
    Estimated::of(Seconds::of($cost), CostBasis::Guessed),
);

$makeSrc = static fn(): Trees => Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())));

// Each shard as its number, label, cost, package and unit paths.
$shards = static fn(Shards|CannotJudge $cut): array => $cut instanceof CannotJudge ? [$cut->why()] : array_map(
    static fn(Shard $shard): array => [
        $shard->id()->number(),
        $shard->label(),
        $shard->cost()->seconds(),
        $shard->package()->path()->value(),
        array_map(
            static fn(Unit $unit): string => $unit->path()->value(),
            iterator_to_array($shard->units(), preserve_keys: false),
        ),
    ],
    iterator_to_array($cut, preserve_keys: false),
);

it('cuts units in path order into as many shards as their cost over the shard size, rounded up', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $src = $makeSrc();

    $work = Workload::of(
        $weighed('src/D.php', 400.0),
        $weighed('src/B.php', 300.0),
        $weighed('src/A.php', 300.0),
        $weighed('src/C.php', 300.0),
    );

    expect($shards(Cut::bySize(600, 20)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 3', 600.0, '.', ['src/A.php', 'src/B.php']],
        [2, 'src, part 2 of 3', 300.0, '.', ['src/C.php']],
        [3, 'src, part 3 of 3', 400.0, '.', ['src/D.php']],
    ]);
});

it('cuts on the unit that reaches an equal share exactly', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 600.0), $weighed('src/B.php', 600.0));

    expect($shards(Cut::bySize(600, 20)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 600.0, '.', ['src/A.php']],
        [2, 'src, part 2 of 2', 600.0, '.', ['src/B.php']],
    ]);
});

it('cuts by size into the shards a size takes, the costliest as cheap as a cut in path order allows', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 299.0), $weighed('src/B.php', 301.0));

    expect($shards(Cut::bySize(300, 20)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 299.0, '.', ['src/A.php']],
        [2, 'src, part 2 of 2', 301.0, '.', ['src/B.php']],
    ]);
});

it('makes shards larger rather than cut more of them than the most there may be', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 1000.0), $weighed('src/B.php', 1000.0), $weighed('src/C.php', 1000.0));

    expect($shards(Cut::bySize(600, 2)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 2000.0, '.', ['src/A.php', 'src/B.php']],
        [2, 'src, part 2 of 2', 1000.0, '.', ['src/C.php']],
    ]);
});

it('stops growing once the shards fit the most there may be exactly', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 600.0), $weighed('src/B.php', 600.0));

    expect($shards(Cut::bySize(600, 2)->cut($work, $src)))->toHaveCount(2);
});

it('gives every package a shard of its own, even past the most there may be', function () use (
    $weighed,
    $shards,
): void {
    $work = Workload::of($weighed('b/src/B.php', 100.0, 'b'), $weighed('a/src/A.php', 100.0, 'a'));

    expect($shards(Cut::bySize(600, 1)->cut($work, Trees::none())))->toBe([
        [1, 'a/src/A.php', 100.0, 'a', ['a/src/A.php']],
        [2, 'b/src/B.php', 100.0, 'b', ['b/src/B.php']],
    ]);
});

it('never puts two packages in one shard, and takes packages in path order', function () use ($weighed, $shards): void {
    $work = Workload::of(
        $weighed('packages/b/src/X.php', 100.0, 'packages/b'),
        $weighed('packages/a/src/Y.php', 100.0, 'packages/a'),
        $weighed('src/Z.php', 100.0),
    );

    expect($shards(Cut::bySize(600, 20)->cut($work, Trees::none())))->toBe([
        [1, 'src/Z.php', 100.0, '.', ['src/Z.php']],
        [2, 'packages/a/src/Y.php', 100.0, 'packages/a', ['packages/a/src/Y.php']],
        [3, 'packages/b/src/X.php', 100.0, 'packages/b', ['packages/b/src/X.php']],
    ]);
});

it('puts units that cost nothing in one shard', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 0.0), $weighed('src/B.php', 0.0));

    expect($shards(Cut::bySize(600, 20)->cut($work, $src)))->toBe([
        [1, 'src', 0.0, '.', ['src/A.php', 'src/B.php']],
    ]);
});

it('puts a package in one shard where a shard has no size', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 100.0), $weighed('src/B.php', 100.0));

    expect($shards(Cut::bySize(0, 20)->cut($work, $src)))->toBe([
        [1, 'src', 200.0, '.', ['src/A.php', 'src/B.php']],
    ]);
});

it('cuts nothing into no shards', function () use ($makeSrc): void {
    $src = $makeSrc();

    expect(Cut::bySize(600, 20)->cut(Workload::of(), $src))->toHaveCount(0);
});

it('cuts exactly as many shards as asked, the ones left over empty and saying so', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 100.0), $weighed('src/B.php', 100.0));

    expect($shards(Cut::exactly(3)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 100.0, '.', ['src/A.php']],
        [2, 'src, part 2 of 2', 100.0, '.', ['src/B.php']],
        [3, 'nothing to mutate', 0.0, '.', []],
    ]);
});

it('shares a fixed count by cost, however small the costs', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 0.5), $weighed('src/B.php', 0.5));

    expect($shards(Cut::exactly(2)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 0.5, '.', ['src/A.php']],
        [2, 'src, part 2 of 2', 0.5, '.', ['src/B.php']],
    ]);
});

it('fills a fixed count with units that cost nothing in the first shard', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 0.0), $weighed('src/B.php', 0.0));

    expect($shards(Cut::exactly(2)->cut($work, $src)))->toBe([
        [1, 'src', 0.0, '.', ['src/A.php', 'src/B.php']],
        [2, 'nothing to mutate', 0.0, '.', []],
    ]);
});

it('cuts a fixed count of empty shards where there is nothing to mutate', function () use ($shards): void {
    expect($shards(Cut::exactly(2)->cut(Workload::of(), Trees::none())))->toBe([
        [1, 'nothing to mutate', 0.0, '.', []],
        [2, 'nothing to mutate', 0.0, '.', []],
    ]);
});

it('fits as many packages as the fixed count', function () use ($weighed, $shards): void {
    $work = Workload::of($weighed('a/A.php', 100.0, 'a'), $weighed('b/B.php', 100.0, 'b'));

    expect($shards(Cut::exactly(2)->cut($work, Trees::none())))->toBe([
        [1, 'a/A.php', 100.0, 'a', ['a/A.php']],
        [2, 'b/B.php', 100.0, 'b', ['b/B.php']],
    ]);
});

it('cannot judge a fixed count smaller than the packages', function () use ($weighed): void {
    $work = Workload::of($weighed('a/A.php', 100.0, 'a'), $weighed('b/B.php', 100.0, 'b'));

    expect(Cut::exactly(1)->cut($work, Trees::none()))->toEqual(CannotJudge::because(
        '--shards=1 cannot hold 2 packages, because packages never share a shard. Ask for 2 shards or more.',
    ));
});

it('cuts to a target wall time the fewest shards whose overhead and share of the cost fit it', function (
    Cut $cut,
    int $count,
) use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of(
        $weighed('src/A.php', 400.0),
        $weighed('src/B.php', 400.0),
        $weighed('src/C.php', 400.0),
    );

    expect($shards($cut->cut($work, $src)))->toHaveCount($count);
})->with([
    'a setup that leaves room for half the cost' => [fn(): Cut => Cut::toTarget(Seconds::of(700.0), Seconds::of(100.0), 20), 2],
    'an opening run on top of the setup' => [
        fn(): Cut => Cut::toTarget(Seconds::of(700.0), Seconds::of(100.0), 20)->opening(Seconds::of(200.0)),
        3,
    ],
    'more than the most shards there may be' => [fn(): Cut => Cut::toTarget(Seconds::of(150.0), Seconds::of(100.0), 2), 2],
    'an overhead past the target' => [fn(): Cut => Cut::toTarget(Seconds::of(60.0), Seconds::of(100.0), 3), 3],
]);

it('cuts no shard to a target where there is no work', function (): void {
    expect(Cut::toTarget(Seconds::of(600.0), Seconds::of(60.0), 20)->cut(Workload::of(), Trees::none()))
        ->toHaveCount(0);
});
