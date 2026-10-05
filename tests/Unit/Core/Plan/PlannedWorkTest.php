<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

it('is the units every shard mutates, in the order the shards take them', function (): void {
    $work = ShardedPlan::planned(3);

    expect($work->units())->toEqual(Units::of(
        Unit::file(Path::of('src/1.php')),
        Unit::file(Path::of('src/2.php')),
        Unit::file(Path::of('src/3.php')),
    ))->and($work->shards())->toBe(3);
});

it('counts only the shards that have units to mutate', function (): void {
    $plan = Plan::of(Revision::ref('5eeca8f'), Digest::sha256Of('base'), Keys::none(), Shards::of(
        Shard::of(
            ShardId::of(1),
            Package::at(Path::root()),
            Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php'))),
            Seconds::of(60.0),
            'src',
        ),
        Shard::empty(ShardId::of(2)),
    ));
    $work = PlannedWork::of($plan, RunTime::estimated(Seconds::of(1.0), Seconds::of(1.0)), Percentage::of(Floor::of(0)));

    expect($work->shards())->toBe(1)
        ->and($work->units())->toHaveCount(2);
});

it('keeps the estimate and how much of it was measured', function (): void {
    $estimate = RunTime::estimated(Seconds::of(360.0), Seconds::of(840.0));
    $measured = Percentage::of(Floor::of(94.5));
    $work = PlannedWork::of(ShardedPlan::of(1), $estimate, $measured);

    expect($work->estimate())->toBe($estimate)
        ->and($work->measured())->toBe($measured);
});
