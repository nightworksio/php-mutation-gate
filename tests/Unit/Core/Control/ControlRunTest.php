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
    'passed' => [ControlRun::passed(Seconds::of(1.5)), ['passed', 1.5, '']],
    'passed, untimed' => [ControlRun::passed(Unmeasured::duration()), ['passed', 'unmeasured', '']],
    'failed' => [ControlRun::failed(), ['failed', 'unmeasured', '']],
    'ran out' => [ControlRun::ranOut(), ['ran-out', 'unmeasured', '']],
    'never run' => [ControlRun::unrun('the file is gone'), ['unrun', 'unmeasured', 'the file is gone']],
]);

it('reads a control run as a mutant that changes nothing by the status its run gave it', function (Mutant $unchanged, array $said): void {
    expect(controlRunSaid(ControlRun::asMutant($unchanged)))->toBe($said);
})->with([
    'survived' => [unchangedMutant(MutantStatus::Survived), ['passed', 1.0, '']],
    'killed' => [unchangedMutant(MutantStatus::Killed), ['failed', 'unmeasured', '']],
    'killed by static analysis' => [unchangedMutant(MutantStatus::KilledByStaticAnalysis), ['failed', 'unmeasured', '']],
    'errored' => [unchangedMutant(MutantStatus::Errored), ['failed', 'unmeasured', '']],
    'out of memory' => [unchangedMutant(MutantStatus::OutOfMemory), ['failed', 'unmeasured', '']],
    'timed out' => [unchangedMutant(MutantStatus::TimedOut), ['ran-out', 'unmeasured', '']],
    'skipped' => [unchangedMutant(MutantStatus::Skipped), ['ran-out', 'unmeasured', '']],
    'uncovered, with no reason' => [unchangedMutant(MutantStatus::Uncovered), ['unrun', 'unmeasured', ControlRun::NO_ANSWER]],
    'ignored by a marker' => [unchangedMutant(MutantStatus::IgnoredByMarker), ['unrun', 'unmeasured', ControlRun::NO_ANSWER]],
    'unjudged, for a reason' => [
        unchangedMutant(MutantStatus::Unjudged)->because(Reason::that('The run wrote no guard.')),
        ['unrun', 'unmeasured', 'The run wrote no guard.'],
    ],
]);

it('reads a control run as a process of its own by how the process ended', function (Ran $ran, array $said): void {
    expect(controlRunSaid(ControlRun::ofProcess($ran)))->toBe($said);
})->with([
    'succeeded' => [Ran::finished(succeeded: true, output: '')->took(Seconds::of(2.5)), ['passed', 2.5, '']],
    'failed' => [Ran::finished(succeeded: false, output: '')->took(Seconds::of(2.5)), ['failed', 'unmeasured', '']],
    'stopped at its limit' => [Ran::stopped('')->took(Seconds::of(5.0)), ['ran-out', 'unmeasured', '']],
    'ended by a signal' => [Ran::signalled(9, ''), ['failed', 'unmeasured', '']],
]);
