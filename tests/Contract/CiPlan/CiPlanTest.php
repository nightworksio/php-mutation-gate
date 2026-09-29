<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;

// What every CI plan answers for a job started as shard 2: that it is shard 2
// of a plan that holds it, and cannot judge a plan that does not. One line per
// implementation.

$plans = [
    'the fake' => fn(): CiPlan => new CiPlanFake(ShardId::of(2)),
];

it('publishes a plan, even one with no shards', function (CiPlan $ci): void {
    expect($ci->publish(Plan::of(Shard::of(ShardId::of(1), Units::none()), Shard::of(ShardId::of(2), Units::none()))))->toBeInstanceOf(Written::class)
        ->and($ci->publish(Plan::of()))->toBeInstanceOf(Written::class);
})->with($plans);

it('names the shard this job runs', function (CiPlan $ci): void {
    expect($ci->shard(Plan::of(Shard::of(ShardId::of(1), Units::none()), Shard::of(ShardId::of(2), Units::none()))))->toEqual(ShardId::of(2));
})->with($plans);

it('cannot judge a plan that does not hold this job', function (CiPlan $ci): void {
    expect($ci->shard(Plan::of(Shard::of(ShardId::of(1), Units::none()))))->toBeInstanceOf(CannotJudge::class);
})->with($plans);
