<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;

$numbers = static fn(Plan $plan): array => array_map(
    static fn(Shard $shard): int => $shard->id()->number(),
    iterator_to_array($plan, preserve_keys: true),
);

$shard = static fn(int $number, string $label = 'src'): Shard => Shard::of(
    ShardId::of($number),
    Package::at(Path::root()),
    Units::none(),
    Seconds::of(1.0),
    $label,
);

$planOf = static fn(Shard ...$shards): Plan => Plan::of(Revision::ref('5eeca8f'), Keys::none(), Shards::of(...$shards));

it('may have no shards at all', function () use ($numbers, $planOf): void {
    expect($planOf())->toHaveCount(0)
        ->and($numbers($planOf()))->toBe([]);
});

it('keeps its shards in the order they came, one per number, numbered from nought', function () use (
    $numbers,
    $shard,
    $planOf,
): void {
    $later = $shard(2, 'later');
    $plan = $planOf($shard(2), $shard(1), $later);

    expect($numbers($plan))->toBe([2, 1])
        ->and($plan)->toHaveCount(2)
        ->and($plan->shard(ShardId::of(2)))->toBe($later);
});

it('answers the shard a job names', function () use ($shard, $planOf): void {
    $second = $shard(2);

    expect($planOf($shard(1), $second)->shard(ShardId::of(2)))->toBe($second);
});

it('cannot judge a shard it does not hold', function () use ($shard, $planOf): void {
    expect($planOf($shard(1), $shard(2))->shard(ShardId::of(3)))
        ->toEqual(CannotJudge::because(
            'The plan has no shard 3. It holds 2 shards, so this job was not planned from it.',
        ));
});

it('holds the commit it was made on and the key of every unit it considered', function (): void {
    $keys = Keys::none()->with(Path::of('src/A.php'), Digest::of('9c1e'));
    $plan = Plan::of(Revision::ref('5eeca8f'), $keys, Shards::none());

    expect($plan->commit())->toEqual(Revision::ref('5eeca8f'))
        ->and($plan->keys())->toBe($keys);
});

it('is made for a detached run that cannot tell its default branch, until told what it runs on', function () use ($shard, $planOf): void {
    $plan = $planOf($shard(1));
    $runOn = RunOn::at(Scope::pullRequest(12), Scope::branch('main'));
    $on = $plan->on($runOn);

    expect($plan->runOn())->toEqual(RunOn::detached(CannotTell::because('The plan was made without asking what it runs on.')))
        ->and($on->runOn())->toBe($runOn)
        ->and($on->commit())->toBe($plan->commit())
        ->and($on->keys())->toBe($plan->keys())
        ->and($on)->toHaveCount(1);
});

it('is named by the digest of what its file holds', function () use ($shard, $planOf): void {
    $plan = $planOf($shard(1));

    expect($plan->digest())->toEqual(PlanFile::digestOf($plan));
});

it('is followed on the commit it was made on', function () use ($planOf): void {
    $plan = $planOf();

    expect($plan->forCheckout(Revision::ref('5eeca8f')))->toBe($plan);
});

it('cannot judge a checkout of another commit', function () use ($planOf): void {
    expect($planOf()->forCheckout(Revision::ref('206b4e0')))->toEqual(CannotJudge::because(
        'The plan was made on 5eeca8f, and this checkout is 206b4e0. Run a shard on the commit its plan was made on.',
    ));
});
