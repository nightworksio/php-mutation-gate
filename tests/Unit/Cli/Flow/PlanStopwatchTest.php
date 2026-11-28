<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\PlanStopwatch;
use NightWorksIO\MutationGate\Core\Cost\PlanLap;
use NightWorksIO\MutationGate\Core\Cost\PlanStep;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\ScriptedClock;

/** Each lap as its step, when it started and how long it took. */
$lapsOf = static fn(PlanStopwatch $stopwatch): array => array_map(
    static fn(PlanLap $lap): array => [$lap->step()->value, $lap->since()->seconds(), $lap->took()->seconds()],
    [...$stopwatch->laps()],
);

it('times each step from where the one before it ended, so every second is a step\'s', function () use ($lapsOf): void {
    $stopwatch = PlanStopwatch::started(new ScriptedClock('2026-11-19 12:00:00', 0, 2, 7, 7, 10));
    $stopwatch->lap(PlanStep::Inventory);
    $stopwatch->lap(PlanStep::Ledgers);
    $stopwatch->lap(PlanStep::Unchanged);
    $stopwatch->lap(PlanStep::Map);

    expect($lapsOf($stopwatch))->toBe([
        ['inventory', 0.0, 2.0],
        ['ledgers', 2.0, 5.0],
        ['unchanged', 7.0, 0.0],
        ['map', 7.0, 3.0],
    ])->and($stopwatch->laps()->whole()->seconds())->toBe(10.0);
});

it('times its first step from when the plan began, however long before the stopwatch that was', function () use ($lapsOf): void {
    $clock = new ScriptedClock('2026-11-19 12:00:00', 4);
    $stopwatch = PlanStopwatch::since($clock, new DateTimeImmutable('2026-11-19 12:00:00'));
    $stopwatch->lap(PlanStep::Composing);

    expect($lapsOf($stopwatch))->toBe([['composing', 0.0, 4.0]]);
});

it('takes the stopwatch it is given, and starts one where none is', function (): void {
    $clock = new ScriptedClock('2026-11-19 12:00:00', 0, 1);
    $given = PlanStopwatch::started($clock);

    expect(PlanStopwatch::given($given, $clock))->toBe($given)
        ->and(PlanStopwatch::given(NotGiven::value(), $clock))->not->toBe($given)
        ->and(PlanStopwatch::given(NotGiven::value(), $clock)->laps())->toHaveCount(0);
});
