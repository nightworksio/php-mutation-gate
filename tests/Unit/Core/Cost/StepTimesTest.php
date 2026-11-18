<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** @return list<array{Step, float, float, int}> each step as its name, start, length and count */
function stepsAsRead(StepTimes $steps): array
{
    return array_map(
        static fn(StepTime $step): array => [$step->step(), $step->since()->seconds(), $step->took()->seconds(), $step->count()],
        [...$steps],
    );
}

it('keeps steps in the order they started, those after after, and moves them all later together', function (): void {
    $first = StepTimes::of(StepTime::of(Step::Coverage, Seconds::of(0.0), Seconds::of(1.5)));
    $then = StepTimes::of(StepTime::of(Step::Mutation, Seconds::of(1.5), Seconds::of(8.0), 40));

    expect(stepsAsRead($first->and($then)->later(Seconds::of(10.0))))->toBe([
        [Step::Coverage, 10.0, 1.5, 1],
        [Step::Mutation, 11.5, 8.0, 40],
    ])
        ->and(count($first->and($then)))->toBe(2)
        ->and(count(StepTimes::none()))->toBe(0)
        ->and(stepsAsRead(StepTimes::of(StepTime::counted(StepTime::of(Step::Survivors, Seconds::of(1.0), Seconds::of(2.0)), 3))))
        ->toBe([[Step::Survivors, 1.0, 2.0, 3]]);
});

it('times each lap from when the run began, on the runner\'s clock, in seconds or nanoseconds', function (): void {
    $reads = [4.0, 6.0, 9.5];
    $clock = static function () use (&$reads): Seconds {
        return Seconds::of((float) array_shift($reads));
    };
    $laps = Laps::from($clock);
    $from = $laps->now();
    $nanoseconds = [1_000_000_000, 3_500_000_000];
    $nano = Laps::fromNanoseconds(static function () use (&$nanoseconds): int {
        return (int) array_shift($nanoseconds);
    });
    $since = Laps::since(static fn(): Seconds => Seconds::of(20.0), Seconds::of(15.0));

    expect(stepsAsRead(StepTimes::of(
        $laps->lap(Step::Preparing, $from, 7),
        $nano->lap(Step::Reading, Seconds::of(2.0)),
        $since->lap(Step::Trials, Seconds::of(16.0)),
    )))->toBe([
        [Step::Preparing, 2.0, 3.5, 7],
        [Step::Reading, 1.0, 1.5, 1],
        [Step::Trials, 1.0, 4.0, 1],
    ]);
});

it('takes its second passes\' factor as their seconds over their bounds, leaving out first passes and passes of no bound', function (): void {
    $second = static fn(float $took, float $bound): StepTime => StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of($took), 3)->bounded(Seconds::of($bound));
    $steps = StepTimes::of(
        StepTime::of(Step::FirstPass, Seconds::of(0.0), Seconds::of(50.0))->bounded(Seconds::of(10.0)),
        $second(30.0, 100.0),
        $second(5.0, 0.0),
        StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(80.0)),
        $second(40.0, 100.0),
    );

    expect($steps->secondPassFactor())->toBe(0.35)
        ->and(StepTimes::of($second(5.0, 0.0))->secondPassFactor())->toBeInstanceOf(NotGiven::class)
        ->and(StepTimes::none()->secondPassFactor())->toBeInstanceOf(NotGiven::class)
        ->and($second(1.0, 2.0)->bound())->toEqual(Seconds::of(2.0))
        ->and(StepTime::counted($second(1.0, 2.0), 7)->bound())->toEqual(Seconds::of(2.0))
        ->and($second(1.0, 2.0)->later(Seconds::of(3.0))->bound())->toEqual(Seconds::of(2.0))
        ->and(StepTime::of(Step::Mutation, Seconds::of(0.0), Seconds::of(1.0))->bound())->toBeInstanceOf(NotGiven::class);
});
