<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A control of `src/Money.php` by one test, allowed this long. */
function controlRunsEntry(float $limit): Control
{
    return Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of('MoneyTest::a')), Seconds::of($limit));
}

it('gives what each control found, by the control, the last given for it winning', function (): void {
    $runs = ControlRuns::none()
        ->with(controlRunsEntry(5.0), ControlRun::failed())
        ->with(controlRunsEntry(6.0), ControlRun::ranOut())
        ->with(controlRunsEntry(5.0), ControlRun::passed(Seconds::of(1.0)));

    expect($runs->of(controlRunsEntry(5.0)))->toEqual(ControlRun::passed(Seconds::of(1.0)))
        ->and($runs->of(controlRunsEntry(6.0)))->toEqual(ControlRun::ranOut());
});

it('says a control it holds nothing for never ran', function (): void {
    $run = ControlRuns::none()->with(controlRunsEntry(5.0), ControlRun::failed())->of(controlRunsEntry(7.0));

    expect($run->end())->toBe(ControlEnd::Unrun)
        ->and($run->why())->toBe(ControlRuns::NOT_RUN);
});

it('joins two sets of runs, those given last winning for a control both hold', function (): void {
    $runs = ControlRuns::none()
        ->with(controlRunsEntry(5.0), ControlRun::failed())
        ->with(controlRunsEntry(6.0), ControlRun::ranOut())
        ->and(ControlRuns::none()->with(controlRunsEntry(5.0), ControlRun::passed(Seconds::of(2.0))));

    expect($runs->of(controlRunsEntry(5.0)))->toEqual(ControlRun::passed(Seconds::of(2.0)))
        ->and($runs->of(controlRunsEntry(6.0)))->toEqual(ControlRun::ranOut());
});
