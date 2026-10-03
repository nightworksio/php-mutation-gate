<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ending;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('is a program that finished, and whether it succeeded', function (): void {
    $passed = Ran::finished(succeeded: true, output: 'OK');
    $failed = Ran::finished(succeeded: false, output: 'FAILED');

    expect([$passed->succeeded(), $passed->wasStopped(), $passed->ending(), $passed->output()])
        ->toBe([true, false, Ending::Succeeded, 'OK'])
        ->and([$failed->succeeded(), $failed->wasStopped(), $failed->ending(), $failed->output()])
        ->toBe([false, false, Ending::Failed, 'FAILED']);
});

it('is a program that exited with a code, which succeeded where it is 0, and keeps the code', function (): void {
    $passed = Ran::exited(0, 'OK');
    $failed = Ran::exited(3, 'FAILED');

    expect([$passed->succeeded(), $passed->ending(), $passed->exitCode(), $passed->output()])
        ->toBe([true, Ending::Succeeded, 0, 'OK'])
        ->and([$failed->succeeded(), $failed->ending(), $failed->exitCode(), $failed->output()])
        ->toBe([false, Ending::Failed, 3, 'FAILED'])
        ->and($failed->took(Seconds::of(1.5))->exitCode())->toBe(3);
});

it('has no exit code where it was stopped, or finished with none read', function (): void {
    expect(Ran::stopped('half')->exitCode())->toEqual(NotGiven::value())
        ->and(Ran::finished(succeeded: false, output: '')->exitCode())->toEqual(NotGiven::value());
});

it('is a program stopped at its deadline, which did not succeed', function (): void {
    $stopped = Ran::stopped('half');

    expect([$stopped->wasStopped(), $stopped->succeeded(), $stopped->ending(), $stopped->output()])
        ->toBe([true, false, Ending::Stopped, 'half']);
});

it('took no time it measured, until it is told how long it took', function (): void {
    $ran = Ran::stopped('half');

    expect($ran->duration())->toEqual(Unmeasured::duration())
        ->and($ran->took(Seconds::of(1.5))->duration())->toEqual(Seconds::of(1.5))
        ->and($ran->took(Seconds::of(1.5))->ending())->toBe(Ending::Stopped)
        ->and($ran->took(Seconds::of(1.5))->output())->toBe('half');
});

it('says how long a run took only where the shell measured it', function (): void {
    expect(Ran::finished(succeeded: true, output: '')->took(Seconds::of(1.5))->timed())->toEqual(Seconds::of(1.5))
        ->and(Ran::finished(succeeded: true, output: '')->timed())->toBeInstanceOf(CannotJudge::class)
        ->and(Ran::stopped('')->timed())->toBeInstanceOf(CannotJudge::class);
});
