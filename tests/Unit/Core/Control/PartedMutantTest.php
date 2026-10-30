<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\PartedMutant;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A mutant of src/Loop.php that timed out at the most of 300 seconds. */
function partedHeavy(): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Loop.php'), 'LessThan', "-<\n+<=", 0),
        '1',
        Location::of(Path::of('src/Loop.php'), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
        MutantStatus::TimedOut,
        Seconds::of(300.0),
    )->withLimit(Seconds::of(300.0));
}

/**
 * The parts of the heavy mutant: the first by LoopTest::a under 100 seconds, the second by LoopTest::b under 120.
 *
 * @return non-empty-list<Control>
 */
function partedParts(): array
{
    $part = static fn(string $test, float $limit): Control => Control::of(
        Path::of('src/Loop.php'),
        TestIds::of(TestId::of($test)),
        Seconds::of($limit),
    )->serving(Contents::of('<?php // mutant'), Seconds::of(20.0));

    return [$part('LoopTest::a', 100.0), $part('LoopTest::b', 120.0)];
}

/** The heavy mutant after these runs, each found by the run it waits on. */
function partedAfter(ControlRun ...$runs): PartedMutant
{
    $parted = PartedMutant::of(partedHeavy(), partedParts());

    foreach ($runs as $run) {
        $parted = $parted->after($run);
    }

    return $parted;
}

it('runs its parts served the mutant one after another, and is killed by the tests that fail in one, running no later part', function (): void {
    $parted = PartedMutant::of(partedHeavy(), partedParts());
    $first = $parted->next();
    $second = $parted->after(ControlRun::passed(Seconds::of(30.0)))->next();
    $killed = partedAfter(ControlRun::passed(Seconds::of(30.0)), ControlRun::failed(TestIds::of(TestId::of('LoopTest::b'))));

    expect([$first, $second])->toEqual([partedParts()[0], partedParts()[1]])
        ->and($killed->next())->toEqual(NotGiven::value())
        ->and([$killed->judged()->status(), [...$killed->judged()->killers()], $killed->judged()->limit()])
        ->toEqual([MutantStatus::Killed, [TestId::of('LoopTest::b')], Seconds::of(120.0)]);
});

it('survives where every part passes with the mutant served', function (): void {
    expect(partedAfter(ControlRun::passed(Seconds::of(30.0)), ControlRun::passed(Seconds::of(40.0)))->judged()->status())
        ->toBe(MutantStatus::Survived);
});

it('runs the control of a part that runs out, and times out where its tests pass unmutated, needing the time they took', function (): void {
    $ranOut = partedAfter(ControlRun::ranOut());
    $judged = partedAfter(ControlRun::ranOut(), ControlRun::passed(Seconds::of(35.0)))->judged();

    expect($ranOut->next())->toEqual(partedParts()[0]->unmutated())
        ->and([$judged->status(), $judged->limit(), $judged->unmutatedNeed()])
        ->toEqual([MutantStatus::TimedOut, Seconds::of(100.0), Seconds::of(35.0)]);
});

it('runs the next part where a part cannot judge it, and is too slow to judge, or unjudged, where no later part kills it', function (): void {
    $slow = partedAfter(ControlRun::ranOut(), ControlRun::ranOut(), ControlRun::passed(Seconds::of(40.0)))->judged();
    $failing = partedAfter(
        ControlRun::ranOut(),
        ControlRun::failed(TestIds::of(TestId::of('LoopTest::a'))),
        ControlRun::ranOut(),
        ControlRun::ranOut(),
    )->judged();
    $killedAfter = partedAfter(ControlRun::ranOut(), ControlRun::ranOut(), ControlRun::failed(TestIds::of(TestId::of('LoopTest::b'))));

    expect([$slow->status(), $slow->reason()])
        ->toEqual([MutantStatus::TimedOut, Reason::that(sprintf(PartedMutant::RAN_OUT, 1, 2))])
        ->and([$failing->status(), [...$failing->killers()]])->toEqual([MutantStatus::FailsUnmutated, [TestId::of('LoopTest::a')]])
        ->and($killedAfter->judged()->status())->toBe(MutantStatus::Killed);
});

it('is unjudged where a part runs out of memory or cannot run, or the time budget runs out before its next run', function (): void {
    expect([
        partedAfter(ControlRun::outOfMemory())->judged()->reason(),
        partedAfter(ControlRun::unrun('no PHP'))->judged()->reason(),
        partedAfter(ControlRun::ranOut(), ControlRun::outOfMemory(), ControlRun::passed(Seconds::of(1.0)))->judged()->reason(),
        partedAfter(ControlRun::ranOut(), ControlRun::unrun('no PHP'))->judged()->reason(),
        PartedMutant::of(partedHeavy(), partedParts())->outOfTime()->judged()->reason(),
    ])->toEqual([
        Reason::that(sprintf(PartedMutant::OUT_OF_MEMORY, 1, 2)),
        Reason::that(sprintf(PartedMutant::UNRUN, 1, 2, 'no PHP')),
        Reason::that(sprintf(PartedMutant::OUT_OF_MEMORY, 1, 2)),
        Reason::that(sprintf(PartedMutant::UNRUN, 1, 2, 'no PHP')),
        OutOfTime::BeforeControlling->reason(),
    ])->and(partedAfter(ControlRun::outOfMemory())->judged()->status())->toBe(MutantStatus::Unjudged)
        ->and(PartedMutant::of(partedHeavy(), partedParts())->judged())->toEqual(partedHeavy())
        ->and(PartedMutant::of(partedHeavy(), partedParts())->mutant())->toEqual(partedHeavy());
});
