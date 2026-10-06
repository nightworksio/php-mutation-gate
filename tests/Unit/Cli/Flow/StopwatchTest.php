<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

it('times each step from when the shard began, a runner\'s own steps moved to when its run began, its run whole where it timed none', function (): void {
    $clock = new TickingClock('2026-09-30T12:00:00+00:00', 2);
    $stopwatch = new Stopwatch($clock, new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $from = $stopwatch->now();
    $stopwatch->stop(Step::HeldCoverage, $from, 2);
    $stopwatch->ran(StepTimes::of(StepTime::of(Step::Mutation, Seconds::of(1.0), Seconds::of(3.0), 9)), $stopwatch->now());
    $stopwatch->ran(StepTimes::none(), $stopwatch->now());

    expect(array_map(
        static fn(StepTime $step): array => [$step->step(), $step->since()->seconds(), $step->took()->seconds(), $step->count()],
        [...$stopwatch->steps()],
    ))->toBe([
        [Step::HeldCoverage, 0.0, 2.0, 2],
        [Step::Mutation, 5.0, 3.0, 9],
        [Step::Mutation, 6.0, 2.0, 1],
    ]);
});
