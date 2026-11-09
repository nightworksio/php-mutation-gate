<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PullQueue;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;

$item = static fn(int $number, int $job, float $seconds): Shard => Shard::of(
    ShardId::of($number),
    Package::at(Path::root()),
    Units::none(),
    Seconds::of($seconds),
    'src',
)->workedBy(ShardId::of($job));

$taken = static function (PullQueue $queue): array {
    $order = [];

    while (($next = $queue->next()) instanceof Shard) {
        $order[] = $next->id()->number();
        $queue = $queue->without($next->id());
    }

    return $order;
};

$plan = static fn(Shard ...$items): Plan => Plan::of(Revision::ref('5eeca8f'), Digest::sha256Of('base'), Keys::none(), Shards::of(...$items));

it('takes its own items longest first, then the other lists from their backs, the longest list left first', function () use ($item, $taken, $plan): void {
    $queue = PullQueue::of($plan(
        $item(1, 1, 10.0),
        $item(2, 1, 30.0),
        $item(3, 2, 20.0),
        $item(4, 2, 5.0),
        $item(5, 2, 50.0),
        $item(6, 3, 40.0),
    ), ShardId::of(1));

    expect($taken($queue))->toBe([2, 1, 4, 3, 6, 5]);
});

it('takes nothing more once every item is gone, and its own items alone once its claims fail', function () use ($item, $taken, $plan): void {
    $queue = PullQueue::of($plan($item(1, 1, 10.0), $item(2, 2, 30.0), $item(3, 1, 20.0)), ShardId::of(1));

    expect($taken($queue->ownAlone()))->toBe([3, 1])
        ->and($queue->without(ShardId::of(1))->without(ShardId::of(2))->without(ShardId::of(3))->next())->toBeInstanceOf(NotGiven::class);
});
