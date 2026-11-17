<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlKind;
use NightWorksIO\MutationGate\Core\Control\ControlKinds;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\ControlTime;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A control of `src/Money.php` by these tests, allowed this long. */
function controlKindsEntry(float $limit, string ...$tests): Control
{
    return Control::of(Path::of('src/Money.php'), TestIds::of(...array_map(TestId::of(...), $tests)), Seconds::of($limit));
}

/**
 * Each time as the steps hold it.
 *
 * @param  list<ControlTime> $times
 * @return list<array{list<string>, int, int|NotGiven, float, Seconds|Unmeasured, ControlEnd}>
 */
function controlKindsSaid(array $times): array
{
    return array_map(static fn(ControlTime $time): array => [
        array_map(static fn(ControlKind $kind): string => $kind->value, $time->kinds()),
        $time->tests(),
        $time->cases(),
        $time->limit()->seconds(),
        $time->took(),
        $time->end(),
    ], $times);
}

it('names what asked for each control, every kind that asked for one, and the kind otherwise', function (): void {
    $shared = controlKindsEntry(5.0, 'MoneyTest::a', 'MoneyTest::b');
    $timeout = controlKindsEntry(3.0, 'MoneyTest::c');
    $unasked = controlKindsEntry(8.0, 'MoneyTest::d');
    $kinds = ControlKinds::otherwise(ControlKind::Kill)
        ->asking(ControlKind::Kills, Controls::of($shared))
        ->asking(ControlKind::Timeout, Controls::of($timeout, $shared));
    $runs = ControlRuns::none()
        ->with($shared, ControlRun::ofProcess(Ran::finished(succeeded: true, output: '')->took(Seconds::of(1.5)), TestIds::none(), 2))
        ->with($timeout, ControlRun::ofProcess(Ran::stopped('')->took(Seconds::of(3.0)), TestIds::none()));

    expect(controlKindsSaid($kinds->timesOf(Controls::of($shared, $timeout, $unasked), $runs)))->toEqual([
        [['kills', 'timeout'], 2, 2, 5.0, Seconds::of(1.5), ControlEnd::Passed],
        [['timeout'], 1, NotGiven::value(), 3.0, Seconds::of(3.0), ControlEnd::RanOut],
        [['kill'], 1, NotGiven::value(), 8.0, Unmeasured::duration(), ControlEnd::Unrun],
    ])
        ->and(ControlKinds::otherwise(ControlKind::HeavyPart)->timesOf(Controls::none(), $runs))->toBe([]);
});
