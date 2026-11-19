<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\StepsOverlap;
use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

it('stops the controls it started, and nothing where it started none', function (): void {
    $stopped = 0;
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $pending = PendingControls::taking(
        static fn(): ControlRuns => ControlRuns::none(),
        static function () use (&$stopped): void {
            $stopped++;
        },
    );
    StepsOverlap::none($request)->stop();
    $before = $stopped;
    StepsOverlap::started($pending, new DateTimeImmutable(), 1, $request)->stop();

    expect($before)->toBe(0)
        ->and($stopped)->toBe(1);
});

it('takes what the controls it started found, timed as the controls step of as many as started, and nothing where it started none', function (): void {
    $clock = new TickingClock('2026-09-30T12:00:00+00:00', 2);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $runs = ControlRuns::none();
    $pending = PendingControls::taking(static fn(): ControlRuns => $runs, static function (): void {
    });

    $none = StepsOverlap::none($request)->taken($stopwatch);
    $taken = StepsOverlap::started($pending, $stopwatch->now(), 3, $request)->taken($stopwatch);

    expect($none)->toBeInstanceOf(NotGiven::class)
        ->and($taken)->toBe($runs)
        ->and(array_map(static fn(StepTime $step): array => [$step->step(), $step->count()], [...$stopwatch->steps()]))
        ->toBe([[Step::Controls, 3]]);
});
