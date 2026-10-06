<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Unidentified;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('names its run and the trace every job of it adds to, and knows nothing more until told', function (): void {
    $spent = RunTime::measured(Seconds::of(360.0), Seconds::of(840.0));
    $timings = RunTimings::of('github:12345/1', $spent);

    expect($timings->run())->toBe('github:12345/1')
        ->and($timings->traceId())->toBe('9b2d6cafc59d87d7a027463ce78b2d84')
        ->and($timings->plan())->toEqual(Unmeasured::duration())
        ->and($timings->verdict())->toEqual(Unmeasured::duration())
        ->and(iterator_to_array($timings->shards(), preserve_keys: false))->toBe([])
        ->and($timings->spent())->toBe($spent)
        ->and($timings->runner())->toEqual(Unidentified::runner())
        ->and($timings->isSharded())->toBeFalse();
});

it('names the runner that judged the run, as the plan\'s proof keys name it', function (): void {
    $runner = Identity::of('pest', Versions::of(Version::of('pestphp/pest', '4.1.0', 'abc123')), Digest::of('php'));
    $timings = RunTimings::of('run', RunTime::measured(Seconds::of(1.0), Seconds::of(1.0)))->ranBy($runner);

    expect($timings->runner())->toBe($runner)
        ->and($timings->withVerdict(Verdicts::shard()->whole())->runner())->toBe($runner);
});

it('keeps the plan, each shard and the verdict, and is sharded from a second shard on', function (): void {
    $plan = Phase::of(Moment::at('2026-09-30T11:50:00Z'), Seconds::of(40.0));
    $mutation = StepTime::of(Step::Mutation, Seconds::of(20.5), Seconds::of(200.0), 40);
    $steps = StepTimes::of(StepTime::of(Step::Coverage, Seconds::of(0.0), Seconds::of(20.5)), $mutation);
    $shard = ShardTiming::of(1, Phase::of(Moment::at('2026-09-30T11:51:00Z'), Seconds::of(220.5)), $steps);
    $timings = RunTimings::of('github:12345/1', RunTime::estimated(Seconds::of(1.0), Seconds::of(2.0)))->withPlan($plan)->withShard($shard);

    expect($timings->plan())->toBe($plan)
        ->and($plan->start())->toEqual(Moment::at('2026-09-30T11:50:00Z'))
        ->and($plan->duration())->toEqual(Seconds::of(40.0))
        ->and($shard->shard())->toBe(1)
        ->and($shard->whole()->duration())->toEqual(Seconds::of(220.5))
        ->and($shard->steps())->toBe($steps)
        ->and($shard->phaseOf($mutation)->start())->toEqual(Moment::at('2026-09-30T11:51:00Z'))
        ->and($shard->phaseOf($mutation)->after())->toEqual(Seconds::of(20.5))
        ->and($shard->phaseOf($mutation)->duration())->toEqual(Seconds::of(200.0))
        ->and($plan->after())->toEqual(Seconds::of(0.0))
        ->and($plan->later(Seconds::of(2.0))->later(Seconds::of(1.5))->after())->toEqual(Seconds::of(3.5))
        ->and(iterator_to_array($timings->shards(), preserve_keys: false))->toBe([$shard])
        ->and($timings->isSharded())->toBeFalse()
        ->and($timings->withShard($shard)->isSharded())->toBeTrue()
        ->and($timings->withVerdict($plan)->verdict())->toBe($plan);
});

it('says whether the CI measured a run time or the gate estimated it', function (): void {
    $measured = RunTime::measured(Seconds::of(360.0), Seconds::of(840.0));
    $estimated = RunTime::estimated(Seconds::of(420.0), Seconds::of(900.0));

    expect([$measured->wall(), $measured->runner(), $measured->isMeasured()])->toEqual([Seconds::of(360.0), Seconds::of(840.0), true])
        ->and($estimated->isMeasured())->toBeFalse()
        ->and(Verdicts::account()->timings())->toBeInstanceOf(RunTimings::class);
});
