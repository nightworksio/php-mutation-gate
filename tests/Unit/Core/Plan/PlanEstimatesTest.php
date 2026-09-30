<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

/** A shard of one unit, its estimate learned and guessed in these seconds, with this opening run. */
function estimatedShard(int $id, float $learned, float $guessed, float $opening): Shard
{
    return Shard::of(
        ShardId::of($id),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of(sprintf('src/%d.php', $id)))),
        Seconds::of($learned + $guessed),
        sprintf('src, part %d', $id),
    )->estimated(
        ShardEstimate::none()
            ->with(Estimated::of(Seconds::of($learned), CostBasis::Learned))
            ->with(Estimated::of(Seconds::of($guessed), CostBasis::Guessed))
            ->opening(Seconds::of($opening)),
    );
}

/** A plan of these shards. */
function estimatedPlan(Shard ...$shards): Plan
{
    return Plan::of(Revision::ref('head'), Digest::sha256Of('base'), Keys::none(), Shards::of(...$shards));
}

it('expects the longest job as wall time and every job as runner time, each with its opening run and setup', function (): void {
    $plan = estimatedPlan(estimatedShard(1, 100.0, 0.0, 20.0), estimatedShard(2, 0.0, 300.0, 20.0), Shard::empty(ShardId::of(3)));

    expect(PlanEstimates::of($plan, Seconds::of(60.0))->runTime())
        ->toEqual(RunTime::estimated(Seconds::of(380.0), Seconds::of(560.0)));
});

it('says what share of the units\' time each basis gives, and none of a plan of no time', function (): void {
    $estimates = PlanEstimates::of(estimatedPlan(estimatedShard(1, 100.0, 300.0, 0.0)), Seconds::of(0.0));

    expect($estimates->share(CostBasis::Learned))->toEqual(Percentage::parse(25))
        ->and($estimates->share(CostBasis::Guessed))->toEqual(Percentage::parse(75))
        ->and($estimates->share(CostBasis::Measured))->toEqual(Percentage::none())
        ->and(PlanEstimates::of(estimatedPlan(), Seconds::of(0.0))->share(CostBasis::Guessed))->toEqual(Percentage::none())
        ->and($estimates->work()->measured())->toEqual(Percentage::parse(25))
        ->and($estimates->work()->estimate())->toEqual($estimates->runTime());
});

it('writes a line for each shard with units and one for the run', function (): void {
    $plan = estimatedPlan(estimatedShard(1, 0.0, 540.0, 60.0), estimatedShard(2, 120.0, 0.0, 60.0), Shard::empty(ShardId::of(3)));

    expect(PlanEstimates::of($plan, Seconds::of(60.0))->lines())->toBe([
        'Shard 1 (src, part 1): about 11m, guessed.',
        'Shard 2 (src, part 2): about 4m, learned.',
        'The plan expects about 11m of wall time and 15m of runner time: 18% learned, 0% measured, 81% guessed.',
    ]);
});

it('warns where shards.max stops the plan meeting shards.target, and only then', function (): void {
    $plan = estimatedPlan(estimatedShard(1, 0.0, 700.0, 60.0), estimatedShard(2, 0.0, 400.0, 60.0));
    $estimates = PlanEstimates::of($plan, Seconds::of(60.0));

    expect(array_map(static fn(Warning $warning): string => $warning->text(), [...$estimates->unmet(Seconds::of(600.0), 2)]))
        ->toBe([<<<'SAID'
            shards.target is 10m, and at shards.max of 2 shards the longest is expected to take 14m.
            Raise shards.max, or shards.target, to meet it.
            SAID])
        ->and($estimates->unmet(Seconds::of(900.0), 2))->toHaveCount(0)
        ->and($estimates->unmet(Seconds::of(600.0), 3))->toHaveCount(0)
        ->and($estimates->unmet(Absent::setting(), 2))->toHaveCount(0);
});
