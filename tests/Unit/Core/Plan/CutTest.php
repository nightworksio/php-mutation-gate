<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\CostMode;
use NightWorksIO\MutationGate\Core\Config\ShardTail;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Plan\TailRule;
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

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 20)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 3', 600.0, '.', ['src/A.php', 'src/B.php']],
        [2, 'src, part 2 of 3', 300.0, '.', ['src/C.php']],
        [3, 'src, part 3 of 3', 400.0, '.', ['src/D.php']],
    ]);
});

it('cuts on the unit that reaches an equal share exactly', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 600.0), $weighed('src/B.php', 600.0));

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 20)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 600.0, '.', ['src/A.php']],
        [2, 'src, part 2 of 2', 600.0, '.', ['src/B.php']],
    ]);
});

it('cuts by size into the shards a size takes, the costliest as cheap as a cut in path order allows', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 299.0), $weighed('src/B.php', 301.0));

    expect($shards(Cut::bySize(300, Seconds::of(0.0), 20)->cut($work, $src)))->toBe([
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

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 2)->cut($work, $src)))->toBe([
        [1, 'src, part 1 of 2', 2000.0, '.', ['src/A.php', 'src/B.php']],
        [2, 'src, part 2 of 2', 1000.0, '.', ['src/C.php']],
    ]);
});

it('cuts every shard the most there may be allows, however far one unit\'s estimate is past a share', function () use ($weighed, $makeSrc, $shards): void {
    $units = array_map(static fn(int $at): Weighed => $weighed(sprintf('src/U%03d.php', $at), 600.0), range(1, 36));
    $work = Workload::of($weighed('src/A.php', 28949.0), ...$units);

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 10)->cut($work, $makeSrc())))->toHaveCount(10);
});

it('cuts more shards where a shard\'s size would not fit its job, up to the most there may be', function () use ($weighed, $makeSrc, $shards): void {
    $units = array_map(static fn(int $at): Weighed => $weighed(sprintf('src/U%02d.php', $at), 100.0), range(1, 40));
    $work = Workload::of(...$units);
    $cut = Cut::bySize(4000, Seconds::of(10.0), 10);

    expect($shards($cut->cut($work, $makeSrc())))->toHaveCount(1)
        ->and($shards($cut->withinJobLimit(Seconds::of(1010.0))->cut($work, $makeSrc())))->toHaveCount(4)
        ->and($shards($cut->withinJobLimit(Seconds::of(1010.0))->atMost(2)->opening(Seconds::of(0.0))->cut($work, $makeSrc())))->toHaveCount(2)
        ->and($shards(Cut::exactly(1)->withinJobLimit(Seconds::of(1010.0))->cut($work, $makeSrc())))->toHaveCount(1);
});

it('stops growing once the shards fit the most there may be exactly', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 600.0), $weighed('src/B.php', 600.0));

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 2)->cut($work, $src)))->toHaveCount(2);
});

it('gives every package a shard of its own, even past the most there may be', function () use (
    $weighed,
    $shards,
): void {
    $work = Workload::of($weighed('b/src/B.php', 100.0, 'b'), $weighed('a/src/A.php', 100.0, 'a'));

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 1)->cut($work, Trees::none())))->toBe([
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

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 20)->cut($work, Trees::none())))->toBe([
        [1, 'src/Z.php', 100.0, '.', ['src/Z.php']],
        [2, 'packages/a/src/Y.php', 100.0, 'packages/a', ['packages/a/src/Y.php']],
        [3, 'packages/b/src/X.php', 100.0, 'packages/b', ['packages/b/src/X.php']],
    ]);
});

it('puts units that cost nothing in one shard', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 0.0), $weighed('src/B.php', 0.0));

    expect($shards(Cut::bySize(600, Seconds::of(0.0), 20)->cut($work, $src)))->toBe([
        [1, 'src', 0.0, '.', ['src/A.php', 'src/B.php']],
    ]);
});

it('puts a package in one shard where a shard has no size', function () use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of($weighed('src/A.php', 100.0), $weighed('src/B.php', 100.0));

    expect($shards(Cut::bySize(0, Seconds::of(0.0), 20)->cut($work, $src)))->toBe([
        [1, 'src', 200.0, '.', ['src/A.php', 'src/B.php']],
    ]);
});

it('cuts nothing into no shards', function () use ($makeSrc): void {
    $src = $makeSrc();

    expect(Cut::bySize(600, Seconds::of(0.0), 20)->cut(Workload::of(), $src))->toHaveCount(0);
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
        fn(): Cut => Cut::toTarget(Seconds::of(700.0), Seconds::of(100.0), 20)->opening(Seconds::of(100.0)),
        3,
    ],
    'shards that would each cost less than twice their overhead' => [
        fn(): Cut => Cut::toTarget(Seconds::of(700.0), Seconds::of(100.0), 20)->opening(Seconds::of(200.0)),
        2,
    ],
    'more than the most shards there may be' => [fn(): Cut => Cut::toTarget(Seconds::of(150.0), Seconds::of(100.0), 2), 2],
    'shards.cutAt lower than the overhead asks' => [
        fn(): Cut => Cut::toTarget(Seconds::of(700.0), Seconds::of(100.0), 20)
            ->cuttingAt(1.0)
            ->atMost(20)
            ->opening(Seconds::of(200.0)),
        3,
    ],
    'shards.cutAt higher than every share' => [
        fn(): Cut => Cut::toTarget(Seconds::of(700.0), Seconds::of(100.0), 20)
            ->cuttingAt(5.0)
            ->opening(Seconds::of(200.0)),
        1,
    ],
    'an overhead past the target' => [fn(): Cut => Cut::toTarget(Seconds::of(60.0), Seconds::of(100.0), 3), 3],
    'an overhead just past the target' => [fn(): Cut => Cut::toTarget(Seconds::of(99.5), Seconds::of(100.0), 3), 3],
    'an overhead that fills the target' => [fn(): Cut => Cut::toTarget(Seconds::of(100.0), Seconds::of(100.0), 3), 3],
]);

it('cuts to a target that leaves a second or less of room by the room\'s share of the cost', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $work = Workload::of(...array_map(
        static fn(string $name): Weighed => $weighed(sprintf('src/%s.php', $name), 0.5),
        ['A', 'B', 'C', 'D', 'E', 'F'],
    ));

    expect(array_column($shards(Cut::toTarget(Seconds::of(1.25), Seconds::of(0.25), 20)->cut($work, $makeSrc())), 2))
        ->toBe([1.0, 1.0, 1.0]);
});

it('cuts fewer shards where an overhead of a second leaves a share below twice it', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $work = Workload::of(...array_map(
        static fn(string $name): Weighed => $weighed(sprintf('src/%s.php', $name), 0.5),
        ['A', 'B', 'C', 'D', 'E'],
    ));

    expect(array_column($shards(Cut::bySize(1, Seconds::of(0.5), 20)->cut($work, $makeSrc())), 2))->toBe([1.5, 1.0]);
});

it('never cuts a package into shards that would each cost less than twice their overhead, unless asked for a count', function (
    Cut $cut,
    array $costs,
) use ($weighed, $makeSrc, $shards): void {
    $src = $makeSrc();

    $work = Workload::of(
        $weighed('src/A.php', 400.0),
        $weighed('src/B.php', 400.0),
        $weighed('src/C.php', 400.0),
    );

    expect(array_column($shards($cut->cut($work, $src)), 2))->toBe($costs);
})->with([
    'no overhead' => [fn(): Cut => Cut::bySize(300, Seconds::of(0.0), 20), [400.0, 400.0, 400.0]],
    'shards that cost twice their setup' => [fn(): Cut => Cut::bySize(300, Seconds::of(200.0), 20), [400.0, 400.0, 400.0]],
    'shards that would cost less than twice their setup' => [fn(): Cut => Cut::bySize(300, Seconds::of(250.0), 20), [800.0, 400.0]],
    'shards that would cost less than twice their setup and opening run' => [
        fn(): Cut => Cut::bySize(300, Seconds::of(150.0), 20)->opening(Seconds::of(100.0)),
        [800.0, 400.0],
    ],
    'a package whose whole cost is less than twice the overhead' => [fn(): Cut => Cut::bySize(300, Seconds::of(1000.0), 20), [1200.0]],
    'a count asked for' => [fn(): Cut => Cut::exactly(3)->opening(Seconds::of(1000.0)), [400.0, 400.0, 400.0]],
]);

it('cuts no shard to a target where there is no work', function (): void {
    expect(Cut::toTarget(Seconds::of(600.0), Seconds::of(60.0), 20)->cut(Workload::of(), Trees::none()))
        ->toHaveCount(0);
});

it('gives each shard every unit of its package and its own part of the test files where it cuts the test files', function () use (
    $weighed,
    $makeSrc,
    $shards,
): void {
    $cut = Cut::bySize(600, Seconds::of(0.0), 20)->cuttingTestFiles()->cut(
        Workload::of($weighed('src/B.php', 900.0), $weighed('src/A.php', 900.0)),
        $makeSrc(),
    );
    $parts = $cut instanceof Shards
        ? array_map(
            static fn(Shard $shard): array => [$shard->testPart()->number(), $shard->testPart()->parts()],
            iterator_to_array($cut, preserve_keys: false),
        )
        : [];

    expect($shards($cut))->toBe([
        [1, 'src, part 1 of 3', 600.0, '.', ['src/A.php', 'src/B.php']],
        [2, 'src, part 2 of 3', 600.0, '.', ['src/A.php', 'src/B.php']],
        [3, 'src, part 3 of 3', 600.0, '.', ['src/A.php', 'src/B.php']],
    ])->and($parts)->toBe([[1, 3], [2, 3], [3, 3]]);
});

it('cuts the test files into as many parts as the shards asked for, and keeps doing so at a learned most', function () use (
    $weighed,
    $makeSrc,
): void {
    $parts = static fn(Shards|CannotJudge $cut): array => $cut instanceof Shards
        ? array_map(
            static fn(Shard $shard): array => [$shard->testPart()->number(), $shard->testPart()->parts()],
            iterator_to_array($cut, preserve_keys: false),
        )
        : [];
    $work = Workload::of($weighed('src/A.php', 100.0));

    expect($parts(Cut::exactly(2)->cuttingTestFiles()->cut($work, $makeSrc())))->toBe([[1, 2], [2, 2]])
        ->and($parts(Cut::bySize(10, Seconds::of(0.0), 20)->cuttingTestFiles()->atMost(3)->opening(Seconds::of(0.0))->cut($work, $makeSrc())))
        ->toBe([[1, 3], [2, 3], [3, 3]])
        ->and($parts(Cut::bySize(10, Seconds::of(0.0), 20)->cut($work, $makeSrc())))->toBe([[1, 1]]);
});

it('cuts a pulling run into items of about the item\'s length, consecutive items listed for one shard', function () use ($weighed, $makeSrc): void {
    $work = Workload::of(
        $weighed('src/A.php', 300.0),
        $weighed('src/B.php', 300.0),
        $weighed('src/C.php', 300.0),
        $weighed('src/D.php', 300.0),
    );
    $cut = Cut::bySize(600, Seconds::of(0.0), 20)->pulling(Seconds::of(300.0))->cut($work, $makeSrc());
    $items = $cut instanceof Shards ? array_map(
        static fn(Shard $item): array => [$item->id()->number(), $item->worker()->number(), $item->isPulled()],
        iterator_to_array($cut, preserve_keys: false),
    ) : $cut;

    expect($items)->toBe([[1, 1, true], [2, 1, true], [3, 2, true], [4, 2, true]]);
});

it('spreads a pulling run\'s items over the count of shards asked for', function () use ($weighed, $makeSrc): void {
    $work = Workload::of($weighed('src/A.php', 300.0), $weighed('src/B.php', 300.0), $weighed('src/C.php', 300.0));
    $cut = Cut::exactly(3)->pulling(Seconds::of(100.0))->cut($work, $makeSrc());
    $workers = $cut instanceof Shards ? array_map(
        static fn(Shard $item): int => $item->worker()->number(),
        iterator_to_array($cut, preserve_keys: false),
    ) : $cut;

    expect($workers)->toBe([1, 2, 3]);
});

it('lists each package\'s pulled items for shards numbered after those of the packages before it, one for a package lowered to none, and at least one item for a package that costs nothing', function () use ($weighed): void {
    $work = Workload::of(
        $weighed('a/src/A1.php', 25.0, 'a'),
        $weighed('a/src/A2.php', 25.0, 'a'),
        $weighed('b/src/B1.php', 400.0, 'b'),
        $weighed('b/src/B2.php', 400.0, 'b'),
        $weighed('b/src/B3.php', 400.0, 'b'),
        $weighed('c/src/C1.php', 0.0, 'c'),
        $weighed('d/src/D1.php', 400.0, 'd'),
    );
    $cut = Cut::bySize(600, Seconds::of(60.0), 20)->pulling(Seconds::of(500.0))->cut($work, Trees::none());
    $items = $cut instanceof Shards ? array_map(
        static fn(Shard $item): array => [$item->package()->path()->value(), $item->worker()->number(), count($item->units())],
        iterator_to_array($cut, preserve_keys: false),
    ) : $cut;

    expect($items)->toBe([
        ['a', 1, 2],
        ['b', 2, 1],
        ['b', 2, 1],
        ['b', 3, 1],
        ['c', 4, 1],
        ['d', 5, 1],
    ]);
});

it('cuts a package into items of a second or less where the item is that short', function () use ($weighed, $makeSrc): void {
    $work = Workload::of(...array_map(
        static fn(string $name): Weighed => $weighed(sprintf('src/%s.php', $name), 0.5),
        ['A', 'B', 'C', 'D'],
    ));
    $cut = Cut::exactly(1)->pulling(Seconds::of(0.5))->cut($work, $makeSrc());

    expect($cut instanceof Shards ? count(iterator_to_array($cut, preserve_keys: false)) : $cut)->toBe(4);
});

it('spreads a pulling run\'s items over fewer shards than items, consecutive items to one shard', function () use ($weighed, $makeSrc): void {
    $work = Workload::of(...array_map(
        static fn(string $name): Weighed => $weighed(sprintf('src/%s.php', $name), 100.0),
        ['A', 'B', 'C', 'D'],
    ));
    $cut = Cut::exactly(2)->pulling(Seconds::of(100.0))->cut($work, $makeSrc());

    expect($cut instanceof Shards ? array_map(
        static fn(Shard $item): int => $item->worker()->number(),
        iterator_to_array($cut, preserve_keys: false),
    ) : $cut)->toBe([1, 1, 2, 2]);
});

it('pulls one part of the test files for each shard, however long the items, where a run cuts the test files', function () use ($weighed, $makeSrc): void {
    $work = Workload::of(...array_map(
        static fn(string $name): Weighed => $weighed(sprintf('src/%s.php', $name), 100.0),
        ['A', 'B', 'C', 'D'],
    ));
    $cut = Cut::exactly(2)->cuttingTestFiles()->pulling(Seconds::of(50.0))->cut($work, $makeSrc());

    expect($cut instanceof Shards ? array_map(
        static fn(Shard $item): array => [$item->testPart()->number(), $item->testPart()->parts(), $item->worker()->number()],
        iterator_to_array($cut, preserve_keys: false),
    ) : $cut)->toBe([[1, 2, 1], [2, 2, 2]]);
});

it('pulls one item of a package that costs nothing and is lowered to no shard, where a run cuts the test files', function () use ($weighed): void {
    $cut = Cut::bySize(600, Seconds::of(60.0), 20)
        ->cuttingTestFiles()
        ->pulling(Seconds::of(500.0))
        ->cut(Workload::of($weighed('c/src/C1.php', 0.0, 'c')), Trees::none());

    expect($cut instanceof Shards ? count(iterator_to_array($cut, preserve_keys: false)) : $cut)->toBe(1);
});

it('cuts a run that streams its test parts to the fewest shards whose wall time fits the target, its cost spread over the processes and its second pass scaled, past shards.max and a learned max', function (
    Cut $cut,
    int $count,
) use ($weighed, $makeSrc, $shards): void {
    $work = Workload::of($weighed('src/A.php', 4000.0), $weighed('src/B.php', 4000.0));

    expect($shards($cut->cut($work, $makeSrc())))->toHaveCount($count);
})->with([
    'four processes, ten minutes by default, one minute of setup' => [
        fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(4))->atMost(2),
        4,
    ],
    'one process' => [fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 15],
    'a second pass taking half its covering tests' => [
        fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1, 0.5)),
        8,
    ],
    'a target set' => [
        fn(): Cut => Cut::toTarget(Seconds::of(1060.0), Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(4)),
        2,
    ],
    'not cutting test files: as sized, a unit a shard' => [fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 20)->streamedOver(StreamWall::of(4)), 2],
    'a count asked for: as asked' => [fn(): Cut => Cut::exactly(3)->cuttingTestFiles()->streamedOver(StreamWall::of(4)), 3],
]);

it('cuts a run whose jobs run their own tail and come to more than the pool to the pool, each within the stop of a job that runs its tail, and says how its jobs stop', function (
    ShardTail $tail,
    CostMode $mode,
    int $pool,
    Cut $cut,
    int $count,
    bool $runs,
) use ($weighed, $makeSrc, $shards): void {
    $work = Workload::of($weighed('src/A.php', 4000.0), $weighed('src/B.php', 4000.0));
    $jobs = JobTail::of(Seconds::minutes(360), $pool);
    $made = $cut->tailing(TailRule::of($tail, $mode, $jobs, Seconds::minutes(350), 1.0))->cut($work, $makeSrc());

    expect($shards($made))->toHaveCount($count)
        ->and($made instanceof Shards ? $made->tail() : $made)->toEqual($runs ? $jobs->running() : $jobs);
})->with([
    'auto, more than the pool: cut to the pool, running its tail' => [ShardTail::Auto, CostMode::Wall, 4, fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 4, true],
    'auto, within the pool: stopping at the target' => [ShardTail::Auto, CostMode::Wall, 20, fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 15, false],
    'auto under minutes, within the pool: running its tail' => [ShardTail::Auto, CostMode::Minutes, 20, fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 15, true],
    'stop, more than the pool: as today' => [ShardTail::Stop, CostMode::Wall, 4, fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 15, false],
    'run, within the pool: its count, running its tail' => [ShardTail::Run, CostMode::Wall, 20, fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 15, true],
    'a count asked for past the pool: as asked, running its tail' => [ShardTail::Auto, CostMode::Wall, 4, fn(): Cut => Cut::exactly(6)->cuttingTestFiles()->streamedOver(StreamWall::of(1)), 6, true],
]);

it('never cuts a shard past the share of its stop a job that runs its own tail is filled to: the pool\'s shards where they fit it, more where the cost needs them, running in waves', function (
    int $pool,
    Seconds $stop,
    float $fill,
    Cut $cut,
    array $costs,
    int $count,
    float $room,
) use ($weighed, $makeSrc, $shards): void {
    $units = [];

    foreach (array_values($costs) as $at => $cost) {
        $units[] = $weighed(sprintf('src/U%d.php', $at), is_float($cost) ? $cost : 0.0);
    }

    $work = Workload::of(...$units);
    $jobs = JobTail::of(Seconds::minutes(360), $pool);
    $made = $cut->tailing(TailRule::of(ShardTail::Auto, CostMode::Wall, $jobs, $stop, $fill))->cut($work, $makeSrc());
    $longest = $made instanceof Shards
        ? max([0.0, ...array_map(static fn(Shard $shard): float => $shard->cost()->seconds(), [...$made])])
        : 0.0;

    expect($shards($made))->toHaveCount($count)
        ->and($made instanceof Shards ? $made->tail()->runs() : $made)->toBeTrue()
        ->and($longest)->toBeLessThanOrEqual($room);
})->with([
    'two units of 4,000 s, a pool of 4, an 8-minute stop: twenty shards of at most 420 s, five waves' => [
        4,
        fn(): Seconds => Seconds::minutes(8),
        1.0,
        fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)),
        [4000.0, 4000.0],
        20,
        420.0,
    ],
    'the same under a six-hour stop: the pool\'s four shards' => [
        4,
        fn(): Seconds => Seconds::minutes(350),
        1.0,
        fn(): Cut => Cut::bySize(600, Seconds::of(60.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(1)),
        [4000.0, 4000.0],
        4,
        20940.0,
    ],
    'the gate\'s own run: 111,425 s over a pool of 19, four streams, an 8-minute stop: 65 shards, not 19 of up to 53 minutes' => [
        19,
        fn(): Seconds => Seconds::minutes(8),
        1.0,
        fn(): Cut => Cut::bySize(600, Seconds::of(51.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(4)),
        [12694.0, 10230.0, 9753.0, 9202.0, 8836.0, 7137.0, 6679.0, 6479.0, 5459.0, 5004.0, 4910.0, 4056.0, 3522.0, 3458.0, 3300.0, 3272.0, 3086.0, 2585.0, 1764.0],
        65,
        1716.0,
    ],
    'the same, each job filled to the default share of its stop: 72 shards of at most 1,562 s' => [
        19,
        fn(): Seconds => Seconds::minutes(8),
        TailRule::FILL,
        fn(): Cut => Cut::bySize(600, Seconds::of(51.0), 2)->cuttingTestFiles()->streamedOver(StreamWall::of(4)),
        [12694.0, 10230.0, 9753.0, 9202.0, 8836.0, 7137.0, 6679.0, 6479.0, 5459.0, 5004.0, 4910.0, 4056.0, 3522.0, 3458.0, 3300.0, 3272.0, 3086.0, 2585.0, 1764.0],
        72,
        1562.4,
    ],
    'a consumer\'s run: 36,943 s, sixty shards at the target over a pool of 20, a six-hour stop: the pool\'s 20' => [
        20,
        fn(): Seconds => Seconds::minutes(350),
        1.0,
        fn(): Cut => Cut::toTarget(Seconds::of(650.0), Seconds::of(29.0), 256)->cuttingTestFiles()->streamedOver(StreamWall::of(1)),
        fn(): array => array_fill(0, 60, 615.7),
        20,
        20971.0,
    ],
]);

it('cuts each package, where the minutes are billed, at the count whose jobs bill the fewest whole minutes, a tie going to more shards', function (
    Cut $cut,
    array $costs,
) use ($weighed, $makeSrc, $shards): void {
    $work = Workload::of($weighed('src/A.php', 50.0), $weighed('src/B.php', 50.0));

    expect(array_column($shards($cut->cut($work, $makeSrc())), 2))->toBe($costs);
})->with([
    'the one shard that fits, billed two minutes' => [
        fn(): Cut => Cut::toTarget(Seconds::of(120.0), Seconds::of(10.0), 20),
        [100.0],
    ],
    'two shards billed a minute each, as fast for no more minutes' => [
        fn(): Cut => Cut::toTarget(Seconds::of(120.0), Seconds::of(10.0), 20)->billedByTheMinute(),
        [50.0, 50.0],
    ],
    'no more shards than the most there may be' => [
        fn(): Cut => Cut::toTarget(Seconds::of(120.0), Seconds::of(10.0), 1)->billedByTheMinute(),
        [100.0],
    ],
    'more shards that would bill more minutes' => [
        fn(): Cut => Cut::toTarget(Seconds::of(120.0), Seconds::of(40.0), 20)->billedByTheMinute(),
        [100.0],
    ],
    'a count asked for' => [fn(): Cut => Cut::exactly(1)->billedByTheMinute(), [100.0]],
]);

it('cuts no more shards, where the minutes are billed, than shards.cutAt allows', function () use ($weighed, $makeSrc, $shards): void {
    $work = Workload::of($weighed('src/A.php', 50.0), $weighed('src/B.php', 50.0));

    expect(array_column($shards(Cut::toTarget(Seconds::of(120.0), Seconds::of(10.0), 20)->cuttingAt(6.0)->billedByTheMinute()->cut($work, $makeSrc())), 2))
        ->toBe([100.0]);
});

it('gives the room the most there may be leaves, where the minutes are billed, to the packages in path order', function () use ($weighed, $shards): void {
    $work = Workload::of(
        $weighed('packages/a/src/A.php', 50.0, 'packages/a'),
        $weighed('packages/a/src/B.php', 50.0, 'packages/a'),
        $weighed('src/C.php', 50.0),
        $weighed('src/D.php', 50.0),
    );

    expect(array_column($shards(Cut::toTarget(Seconds::of(120.0), Seconds::of(10.0), 3)->billedByTheMinute()->cut($work, Trees::none())), 3))
        ->toBe(['.', '.', 'packages/a']);
});
