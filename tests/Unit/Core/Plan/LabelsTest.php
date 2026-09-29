<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Labels;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$weighed = static fn(string $path, float $cost = 1.0, string $package = '.'): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::of($package)),
    Seconds::of($cost),
);

$tree = static fn(string $path): Tree => Tree::at(Path::of($path), Undeclared::floor(), Package::at(Path::root()));

$labels = static fn(Shards $shards): array => array_map(
    static fn(Shard $shard): string => $shard->label(),
    iterator_to_array($shards, preserve_keys: false),
);

it('labels each shard with the trees it takes, and the part of each tree that spans several', function () use (
    $weighed,
    $tree,
    $labels,
): void {
    $shards = Labels::of([
        [$weighed('src/A.php'), $weighed('src/Domain/B.php'), $weighed('src/Z.php')],
        [$weighed('src/Domain/C.php'), $weighed('lib/D.php')],
        [$weighed('lib/E.php')],
        [$weighed('bin/tool.php')],
    ], Trees::of($tree('src/Domain'), $tree('src'), $tree('lib')));

    expect($labels($shards))->toBe([
        'src; src/Domain, part 1 of 2',
        'src/Domain, part 2 of 2; lib, part 1 of 2',
        'lib, part 2 of 2',
        'bin/tool.php',
    ]);
});

it('names a unit by the deepest tree it is under, however the trees are listed', function () use (
    $weighed,
    $tree,
    $labels,
): void {
    $run = [[$weighed('src/Domain/B.php')]];

    expect($labels(Labels::of($run, Trees::of($tree('src'), $tree('src/Domain')))))->toBe(['src/Domain'])
        ->and($labels(Labels::of($run, Trees::of($tree('src/Domain'), $tree('src')))))->toBe(['src/Domain']);
});

it('reads the root as a tree every unit is under', function () use ($weighed, $tree, $labels): void {
    expect($labels(Labels::of([[$weighed('bin/tool.php')]], Trees::of($tree('.')))))->toBe(['.']);
});

it('takes a unit whose path is a tree as that tree', function () use ($weighed, $tree, $labels): void {
    expect($labels(Labels::of([[$weighed('lib')]], Trees::of($tree('.'), $tree('lib')))))->toBe(['lib']);
});

it('names a unit under no tree by its own path, and no tree holds a path that only starts like it', function () use (
    $weighed,
    $tree,
    $labels,
): void {
    expect($labels(Labels::of([[$weighed('srcx/A.php')]], Trees::of($tree('src')))))->toBe(['srcx/A.php']);
});

it('numbers the shards from one, each with its package, units and cost', function () use ($weighed): void {
    $shards = iterator_to_array(Labels::of([
        [$weighed('a/A.php', 1.5, 'a'), $weighed('a/B.php', 2.25, 'a')],
        [$weighed('b/C.php', 4.0, 'b')],
    ], Trees::none()), preserve_keys: false);

    expect($shards)->toEqual([
        Shard::of(
            ShardId::of(1),
            Package::at(Path::of('a')),
            Units::of(Unit::file(Path::of('a/A.php')), Unit::file(Path::of('a/B.php'))),
            Seconds::of(3.75),
            'a/A.php; a/B.php',
        ),
        Shard::of(
            ShardId::of(2),
            Package::at(Path::of('b')),
            Units::of(Unit::file(Path::of('b/C.php'))),
            Seconds::of(4.0),
            'b/C.php',
        ),
    ]);
});

it('makes an empty run an empty shard', function (): void {
    expect(iterator_to_array(Labels::of([[], []], Trees::none()), preserve_keys: false))
        ->toEqual([Shard::empty(ShardId::of(1)), Shard::empty(ShardId::of(2))]);
});
