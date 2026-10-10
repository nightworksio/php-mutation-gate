<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A mutant that changes nothing, ended so, its run taking a second. */
function unchangedMutant(MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'control', '', 0),
        'control',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('control', MutatorFamily::None, ''),
        $status,
        Seconds::of(1.0),
    );
}

/**
 * How a control ended, how long its tests took and why it never ran, as plain values.
 *
 * @return array{string, float|string, string}
 */
function controlRunSaid(ControlRun $run): array
{
    $took = $run->took();
    $why = $run->why();

    return [$run->end()->value, $took instanceof Seconds ? $took->seconds() : 'unmeasured', $why instanceof NotGiven ? '' : $why];
}

it('says how a control ended: passed in a time, failed, ran out, or never run and why', function (ControlRun $run, array $said): void {
    expect(controlRunSaid($run))->toBe($said);
})->with([
    'passed' => [fn(): ControlRun => ControlRun::passed(Seconds::of(1.5)), ['passed', 1.5, '']],
    'passed, untimed' => [fn(): ControlRun => ControlRun::passed(Unmeasured::duration()), ['passed', 'unmeasured', '']],
    'failed' => [fn(): ControlRun => ControlRun::failed(), ['failed', 'unmeasured', '']],
    'ran out' => [fn(): ControlRun => ControlRun::ranOut(), ['ran-out', 'unmeasured', '']],
    'out of memory' => [fn(): ControlRun => ControlRun::outOfMemory(), ['out-of-memory', 'unmeasured', '']],
    'never run' => [fn(): ControlRun => ControlRun::unrun('the file is gone'), ['unrun', 'unmeasured', 'the file is gone']],
]);

it('reads a control run as a mutant that changes nothing by the status its run gave it', function (Mutant $unchanged, array $said): void {
    expect(controlRunSaid(ControlRun::asMutant($unchanged)))->toBe($said);
})->with([
    'survived' => [fn(): Mutant => unchangedMutant(MutantStatus::Survived), ['passed', 1.0, '']],
    'killed' => [fn(): Mutant => unchangedMutant(MutantStatus::Killed), ['failed', 'unmeasured', '']],
    'killed by static analysis' => [fn(): Mutant => unchangedMutant(MutantStatus::KilledByStaticAnalysis), ['failed', 'unmeasured', '']],
    'errored' => [fn(): Mutant => unchangedMutant(MutantStatus::Errored), ['failed', 'unmeasured', '']],
    'out of memory' => [fn(): Mutant => unchangedMutant(MutantStatus::OutOfMemory), ['out-of-memory', 'unmeasured', '']],
    'timed out' => [fn(): Mutant => unchangedMutant(MutantStatus::TimedOut), ['ran-out', 'unmeasured', '']],
    'skipped' => [fn(): Mutant => unchangedMutant(MutantStatus::Skipped), ['ran-out', 'unmeasured', '']],
    'uncovered, with no reason' => [fn(): Mutant => unchangedMutant(MutantStatus::Uncovered), ['unrun', 'unmeasured', ControlRun::NO_ANSWER]],
    'ignored by a marker' => [fn(): Mutant => unchangedMutant(MutantStatus::IgnoredByMarker), ['unrun', 'unmeasured', ControlRun::NO_ANSWER]],
    'unjudged, for a reason' => [
        fn(): Mutant => unchangedMutant(MutantStatus::Unjudged)->because(Reason::that('The run wrote no guard.')),
        ['unrun', 'unmeasured', 'The run wrote no guard.'],
    ],
]);

it('reads a control run as a process of its own by how the process ended', function (Ran $ran, array $said): void {
    expect(controlRunSaid(ControlRun::ofProcess($ran)))->toBe($said);
})->with([
    'succeeded' => [fn(): Ran => Ran::finished(succeeded: true, output: '')->took(Seconds::of(2.5)), ['passed', 2.5, '']],
    'failed' => [fn(): Ran => Ran::finished(succeeded: false, output: '')->took(Seconds::of(2.5)), ['failed', 'unmeasured', '']],
    'stopped at its limit' => [fn(): Ran => Ran::stopped('')->took(Seconds::of(5.0)), ['ran-out', 'unmeasured', '']],
    'ended by a signal' => [fn(): Ran => Ran::signalled(9, ''), ['failed', 'unmeasured', '']],
    'out of its memory limit' => [
        fn(): Ran => Ran::finished(succeeded: false, output: 'PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted (tried to allocate 20480 bytes)'),
        ['out-of-memory', 'unmeasured', ''],
    ],
]);

it('carries the peak its launcher measured, and none where it measured none', function (): void {
    expect(ControlRun::passed(Seconds::of(1.0))->withPeak(MemoryCap::of(20, MemoryUnit::Megabytes))->peak())->toEqual(MemoryCap::of(20, MemoryUnit::Megabytes))
        ->and(ControlRun::passed(Seconds::of(1.0))->peak())->toEqual(NotGiven::value());
});
