<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Unit\Units;

$numbers = static fn(Plan $plan): array => array_map(static fn(Shard $shard): int => $shard->id()->number(), iterator_to_array($plan, preserve_keys: true));

it('may have no shards at all', function () use ($numbers): void {
    expect(Plan::of())->toHaveCount(0)
        ->and($numbers(Plan::of()))->toBe([]);
});

it('keeps its shards in the order they came, one per number', function () use ($numbers): void {
    $plan = Plan::of(Shard::of(ShardId::of(2), Units::none()), Shard::of(ShardId::of(1), Units::none()), Shard::of(ShardId::of(2), Units::none()));

    expect($numbers($plan))->toBe([2, 1])
        ->and($plan)->toHaveCount(2);
});

it('answers the shard a job names', function (): void {
    $shard = Shard::of(ShardId::of(2), Units::none());

    expect(Plan::of(Shard::of(ShardId::of(1), Units::none()), $shard)->shard(ShardId::of(2)))->toBe($shard);
});

it('cannot judge a shard it does not hold', function (): void {
    expect(Plan::of(Shard::of(ShardId::of(1), Units::none()), Shard::of(ShardId::of(2), Units::none()))->shard(ShardId::of(3)))
        ->toEqual(CannotJudge::because('The plan has no shard 3. It holds 2 shards, so this job was not planned from it.'));
});
