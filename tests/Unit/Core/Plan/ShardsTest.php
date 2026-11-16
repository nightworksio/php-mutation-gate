<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$numbers = static fn(Shards $shards): array => array_map(
    static fn(Shard $shard): int => $shard->id()->number(),
    iterator_to_array($shards, preserve_keys: true),
);

it('holds no shards to begin with', function (): void {
    expect(Shards::none())->toHaveCount(0);
});

it('keeps shards in the order they were cut, numbered from nought', function () use ($numbers): void {
    $shards = Shards::of(...['b' => Shard::empty(ShardId::of(2)), 'a' => Shard::empty(ShardId::of(1))]);

    expect($numbers($shards))->toBe([2, 1])
        ->and($shards)->toHaveCount(2);
});

it('adds a shard without changing the shards it came from', function () use ($numbers): void {
    $shards = Shards::of(Shard::empty(ShardId::of(1)));

    expect($numbers($shards->with(Shard::empty(ShardId::of(2)))))->toBe([1, 2])
        ->and($shards)->toHaveCount(1);
});

it('lists the path of each unit of every shard once, in the shards\' order', function (): void {
    $shard = static fn(int $id, Unit ...$units): Shard => Shard::of(ShardId::of($id), Package::at(Path::root()), Units::of(...$units), Seconds::of(1.0), 'shard');
    $shards = Shards::of(
        $shard(1, Unit::file(Path::of('src/B.php')), Unit::file(Path::of('src/A.php'))),
        $shard(2, Unit::file(Path::of('src/A.php')), Unit::held(Path::of('src/Held'), Group::named('holds:src/Held'))),
    );

    expect(array_map(static fn(Path $path): string => $path->value(), [...$shards->paths()]))->toBe(['src/B.php', 'src/A.php', 'src/Held'])
        ->and(Shards::none()->paths())->toHaveCount(0);
});
