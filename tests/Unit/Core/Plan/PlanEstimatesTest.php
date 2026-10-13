<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

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

it('sets the planned work the changed lines the plan says no test runs, by file', function (): void {
    $lines = Lines::of(Line::of(4), Line::of(9));
    $plan = estimatedPlan(estimatedShard(1, 100.0, 300.0, 0.0))->considering(
        Considered::everything()->untesting(Changes::of(Change::modified(Path::of('src/Money.php'), $lines))),
    );

    expect(PlanEstimates::of($plan, Seconds::of(0.0))->work()->uncovered())
        ->toEqual(ByPath::none()->with(Path::of('src/Money.php'), $lines))
        ->and(PlanEstimates::of(estimatedPlan(), Seconds::of(0.0))->work()->uncovered())->toEqual(ByPath::none());
});

it('writes a line for each shard with units', function (): void {
    $plan = estimatedPlan(estimatedShard(1, 0.0, 540.0, 60.0), estimatedShard(2, 120.0, 0.0, 60.0), Shard::empty(ShardId::of(3)));

    expect(PlanEstimates::of($plan, Seconds::of(60.0))->lines())->toBe([
        'Shard 1 (src, part 1): about 11m, guessed.',
        'Shard 2 (src, part 2): about 4m, learned.',
    ]);
});

it('sums the run up in one line where nothing is measured and the target is met', function (): void {
    $plan = estimatedPlan(estimatedShard(1, 0.0, 540.0, 60.0), estimatedShard(2, 120.0, 0.0, 60.0), Shard::empty(ShardId::of(3)));

    expect(PlanEstimates::of($plan, Seconds::of(60.0))->summary(ProcessCount::of(4), Absent::setting(), 2))->toBe([
        'The plan expects about 11m of wall time and 15m of runner time: 18% learned, 0% measured, 81% guessed.',
    ]);
});

it('warns where the plan cannot meet shards.target, saying what stops it', function (): void {
    $plan = estimatedPlan(estimatedShard(1, 0.0, 700.0, 60.0), estimatedShard(2, 0.0, 400.0, 60.0));
    $estimates = PlanEstimates::of($plan, Seconds::of(60.0));
    $total = 'The plan expects about 14m of wall time and 22m of runner time: 0% learned, 0% measured, 100% guessed.';

    expect($estimates->summary(ProcessCount::of(4), Seconds::of(600.0), 2))
        ->toBe([$total, <<<'SAID'
            shards.target is 10m, and at shards.max of 2 shards the longest is expected to take 14m.
            Raise shards.max, or shards.target, to meet it.
            SAID])
        ->and($estimates->summary(ProcessCount::of(4), Seconds::of(600.0), 3))->toBe([$total, <<<'SAID'
            shards.target is 10m, and at 2 shards the longest is expected to take 14m.
            More shards would each cost less than twice their opening run and setup, or split a unit, which no cut does.
            Raise shards.target to meet it.
            SAID])
        ->and($estimates->summary(ProcessCount::of(4), $estimates->runTime()->wall(), 2))->toBe([$total])
        ->and($estimates->summary(ProcessCount::of(4), Absent::setting(), 2))->toBe([$total]);
});

it('says each shard\'s runner is taken to run as many mutants at once as this machine, where a unit\'s estimate is measured', function (): void {
    $measured = estimatedShard(1, 0.0, 10.0, 0.0)->estimated(
        ShardEstimate::none()->with(Estimated::of(Seconds::of(5.0), CostBasis::Measured)),
    );

    expect(PlanEstimates::of(estimatedPlan($measured), Seconds::of(0.0))->summary(ProcessCount::of(4), Absent::setting(), 2))
        ->toBe([
            'The plan expects about 5s of wall time and 5s of runner time: 0% learned, 100% measured, 0% guessed.',
            'It takes each shard\'s runner to run 4 mutants at once, as this machine does.',
        ]);
});

it('expects a plan of shards under a second at their own time, and no time of a plan of none', function (): void {
    expect(PlanEstimates::of(estimatedPlan(estimatedShard(1, 0.0, 0.5, 0.0)), Seconds::of(0.0))->runTime())
        ->toEqual(RunTime::estimated(Seconds::of(0.5), Seconds::of(0.5)))
        ->and(PlanEstimates::of(estimatedPlan(), Seconds::of(0.0))->runTime())
        ->toEqual(RunTime::estimated(Seconds::of(0.0), Seconds::of(0.0)));
});

it('gives the whole share to the one basis of a plan of a microsecond', function (): void {
    $estimates = PlanEstimates::of(estimatedPlan(estimatedShard(1, 0.000001, 0.0, 0.0)), Seconds::of(0.0));

    expect($estimates->share(CostBasis::Learned))->toEqual(Percentage::whole())
        ->and($estimates->share(CostBasis::Guessed))->toEqual(Percentage::none());
});

it('says how many mutants a runner is taken to run at once where a microsecond of the estimate is measured', function (): void {
    $measured = Shard::of(ShardId::of(1), Package::at(Path::root()), Units::none(), Seconds::of(0.000001), 'src')->estimated(
        ShardEstimate::none()->with(Estimated::of(Seconds::of(0.000001), CostBasis::Measured)),
    );

    expect(PlanEstimates::of(estimatedPlan($measured), Seconds::of(0.0))->summary(ProcessCount::of(4), Absent::setting(), 2))
        ->toContain('It takes each shard\'s runner to run 4 mutants at once, as this machine does.');
});

it('names every basis a shard\'s estimate rests on, however little of it each gives', function (): void {
    expect(PlanEstimates::of(estimatedPlan(estimatedShard(1, 0.5, 0.5, 0.0)), Seconds::of(0.0))->lines())
        ->toBe(['Shard 1 (src, part 1): about 1s, learned and guessed.']);
});
