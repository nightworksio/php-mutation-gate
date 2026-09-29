<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;

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
