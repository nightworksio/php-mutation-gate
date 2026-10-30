<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\PartControls;
use NightWorksIO\MutationGate\Core\Control\PartedMutant;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
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
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
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
 * The runs of the heavy mutant's parts, its tests timed so: LoopTest::a and LoopTest::b 30 and 40 seconds, and
 * LoopTest::c and LoopTest::d 10 each, placed so in their files, under a most of 300 seconds.
 */
function partedRuns(TestPlaces $places): PartControls
{
    $map = CoverageMap::empty();

    foreach (['LoopTest::a' => 30.0, 'LoopTest::b' => 40.0, 'LoopTest::c' => 10.0, 'LoopTest::d' => 10.0] as $test => $seconds) {
        $map = $map->timed(TestId::of($test), Seconds::of($seconds));
    }

    $bounds = LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0))->startingIn(Seconds::of(0.0));

    return PartControls::of(partedHeavy(), Contents::of('<?php // mutant'), $map, $bounds, $places);
}

/**
 * The parts of the heavy mutant: the first by LoopTest::a, allowed 90 seconds, the second by LoopTest::b, allowed 120.
 *
 * @return non-empty-list<RunTests>
 */
function partedParts(): array
{
    return [RunTests::of(TestIds::of(TestId::of('LoopTest::a'))), RunTests::of(TestIds::of(TestId::of('LoopTest::b')))];
}

/**
 * The parts of the heavy mutant as they run, served the mutant.
 *
 * @return list<Control>
 */
function partedControls(): array
{
    return array_map(partedRuns(TestPlaces::none())->serving(...), partedParts());
}

/** The heavy mutant after these runs, each found by the run it waits on. */
function partedAfter(ControlRun ...$runs): PartedMutant
{
    $parted = PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedParts());

    foreach ($runs as $run) {
        $parted = $parted->after($run);
    }

    return $parted;
}

it('runs its parts served the mutant one after another, and is killed by the tests that fail in one, running no later part', function (): void {
    $parted = PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedParts());
    $first = $parted->next();
    $second = $parted->after(ControlRun::passed(Seconds::of(30.0)))->next();
    $killed = partedAfter(ControlRun::passed(Seconds::of(30.0)), ControlRun::failed(TestIds::of(TestId::of('LoopTest::b'))));

    expect([$first, $second])->toEqual([partedControls()[0], partedControls()[1]])
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

    expect($ranOut->next())->toEqual(partedControls()[0]->unmutated())
        ->and([$judged->status(), $judged->limit(), $judged->unmutatedNeed()])
        ->toEqual([MutantStatus::TimedOut, Seconds::of(90.0), Seconds::of(35.0)]);
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
        PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedParts())->outOfTime()->judged()->reason(),
    ])->toEqual([
        Reason::that(sprintf(PartedMutant::OUT_OF_MEMORY, 1, 2)),
        Reason::that(sprintf(PartedMutant::UNRUN, 1, 2, 'no PHP')),
        Reason::that(sprintf(PartedMutant::OUT_OF_MEMORY, 1, 2)),
        Reason::that(sprintf(PartedMutant::UNRUN, 1, 2, 'no PHP')),
        OutOfTime::BeforeControlling->reason(),
    ])->and(partedAfter(ControlRun::outOfMemory())->judged()->status())->toBe(MutantStatus::Unjudged)
        ->and(PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedParts())->judged())->toEqual(partedHeavy())
        ->and(PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedParts())->mutant())->toEqual(partedHeavy());
});

it('splits a part that runs out unmutated too in two by its test files, each half run in its place, down to one file\'s tests, and names that file', function (): void {
    $places = TestPlaces::none()
        ->placing(Path::of('tests/CTest.php'), TestIds::of(TestId::of('LoopTest::c')))
        ->placing(Path::of('tests/DTest.php'), TestIds::of(TestId::of('LoopTest::d')));
    $runs = partedRuns($places);
    $both = RunTests::of(TestIds::of(TestId::of('LoopTest::c'), TestId::of('LoopTest::d')));
    $parted = PartedMutant::of(partedHeavy(), $runs, [$both])->after(ControlRun::ranOut())->after(ControlRun::ranOut());
    $c = $runs->serving(RunTests::of(TestIds::of(TestId::of('LoopTest::c'))));
    $d = $runs->serving(RunTests::of(TestIds::of(TestId::of('LoopTest::d'))));
    $slow = $parted->after(ControlRun::ranOut())->after(ControlRun::ranOut())->after(ControlRun::passed(Seconds::of(5.0)));
    $killed = $parted->after(ControlRun::passed(Seconds::of(5.0)))->after(ControlRun::failed(TestIds::of(TestId::of('LoopTest::d'))));

    expect([$parted->next(), $parted->after(ControlRun::passed(Seconds::of(5.0)))->next()])->toEqual([$c, $d])
        ->and([$c->limit(), $d->limit()])->toEqual([Seconds::of(30.0), Seconds::of(30.0)])
        ->and([$slow->judged()->status(), $slow->judged()->reason()])
        ->toEqual([MutantStatus::TimedOut, Reason::that(sprintf(PartedMutant::FILE_RAN_OUT, 1, 2, 'tests/CTest.php'))])
        ->and([$killed->judged()->status(), [...$killed->judged()->killers()]])->toEqual([MutantStatus::Killed, [TestId::of('LoopTest::d')]]);
});

it('splits the files of a part with an odd number of them, the larger half first, and tests in no file with the second', function (): void {
    $places = TestPlaces::none()
        ->placing(Path::of('tests/ATest.php'), TestIds::of(TestId::of('LoopTest::a')))
        ->placing(Path::of('tests/BTest.php'), TestIds::of(TestId::of('LoopTest::b')));
    $runs = partedRuns($places);
    $three = TestIds::of(TestId::of('LoopTest::a'), TestId::of('LoopTest::b'), TestId::of('LoopTest::c'));
    $halves = $runs->halves($runs->serving(RunTests::of($three)));

    expect(array_map(static fn(Control $half): array => array_map(static fn(TestId $test): string => $test->value(), [...$half->tests()]), $halves))
        ->toBe([['LoopTest::a', 'LoopTest::b'], ['LoopTest::c']])
        ->and($runs->fileOf($halves[1]))->toEqual(NotGiven::value())
        ->and($runs->fileOf($halves[0]))->toEqual(NotGiven::value())
        ->and($runs->halves($halves[1]))->toBe([]);
});
