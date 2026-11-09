<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
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
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
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
    $map = CoverageMap::of();

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

it('says in a log which mutant waits on which part and how many remain, and that a run is a part\'s control, and nothing once judged', function (): void {
    $id = partedHeavy()->id()->key();
    $parted = PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedParts());
    $second = $parted->after(ControlRun::passed(Seconds::of(30.0)));
    $controlling = $second->after(ControlRun::ranOut());
    $killed = partedAfter(ControlRun::passed(Seconds::of(30.0)), ControlRun::failed(TestIds::of(TestId::of('LoopTest::b'))));
    $parts = count(partedParts());

    expect($parted->progress(0))->toBe(sprintf('heavy mutant %s, src/Loop.php:3: part 1 of %d, %d left', $id, $parts, $parts - 1))
        ->and($second->progress(0))->toBe(sprintf('heavy mutant %s, src/Loop.php:3: part 2 of %d, %d left', $id, $parts, $parts - 2))
        ->and($controlling->progress(0))->toBe(sprintf('heavy mutant %s, src/Loop.php:3: part 2 of %d, %d left, its control', $id, $parts, $parts - 2))
        ->and($killed->progress(0))->toEqual(NotGiven::value());
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

it('puts the halves of a split part in its place, between the parts before and after it', function (): void {
    $places = TestPlaces::none()
        ->placing(Path::of('tests/CTest.php'), TestIds::of(TestId::of('LoopTest::c')))
        ->placing(Path::of('tests/DTest.php'), TestIds::of(TestId::of('LoopTest::d')));
    $runs = partedRuns($places);
    $part = static fn(string ...$tests): RunTests => RunTests::of(TestIds::of(...array_map(TestId::of(...), $tests)));
    $passed = ControlRun::passed(Seconds::of(1.0));
    $split = PartedMutant::of(partedHeavy(), $runs, [$part('LoopTest::a'), $part('LoopTest::c', 'LoopTest::d'), $part('LoopTest::b')])
        ->after($passed)
        ->after(ControlRun::ranOut())
        ->after(ControlRun::ranOut());
    $nexts = [$split->next(), $split->after($passed)->next(), $split->after($passed)->after($passed)->next()];
    $last = $split->after($passed)->after($passed)->after(ControlRun::ranOut())->after(ControlRun::ranOut())->judged();

    expect($nexts)->toEqual([
        $runs->serving($part('LoopTest::c')),
        $runs->serving($part('LoopTest::d')),
        $runs->serving($part('LoopTest::b')),
    ])->and($last->reason())->toEqual(Reason::that(sprintf(PartedMutant::RAN_OUT, 4, 4)));
});

it('holds each test of a part to the limit of its own the bounds give it, timed by the map', function (): void {
    $map = CoverageMap::of()->timed(TestId::of('LoopTest::a'), Seconds::of(30.0));
    $bounds = LimitBounds::between(Seconds::of(1.0), Seconds::of(300.0))->watchingEachTest(PerTestLimit::standard());
    $part = PartControls::of(partedHeavy(), Contents::of('<?php // mutant'), $map, $bounds, TestPlaces::none())->serving(partedParts()[0]);

    expect($part->watch())->toEqual(TestWatch::of(PerTestLimit::standard(), TestTimes::of(TestTime::of(TestId::of('LoopTest::a'), Seconds::of(30.0)))))
        ->and(partedControls()[0]->watch())->toEqual(NotGiven::value());
});

/**
 * The four parts of the heavy mutant, a test each.
 *
 * @return non-empty-list<RunTests>
 */
function partedFour(): array
{
    return array_map(
        static fn(string $test): RunTests => RunTests::of(TestIds::of(TestId::of($test))),
        ['LoopTest::a', 'LoopTest::b', 'LoopTest::c', 'LoopTest::d'],
    );
}

/** The heavy mutant of four parts, judged by this many at most. */
function partedCapped(int|NotGiven $atMost): PartedMutant
{
    return PartedMutant::of(partedHeavy(), partedRuns(TestPlaces::none()), partedFour())->atMost($atMost);
}

/**
 * @param  list<Control> $parts
 * @return list<string>
 */
function partedTests(array $parts): array
{
    return array_map(static fn(Control $part): string => [...$part->tests()][0]->value(), $parts);
}

/** The run of a part of the heavy mutant by this one test, served the mutant. */
function partedPartBy(string $test): Control
{
    return partedRuns(TestPlaces::none())->serving(RunTests::of(TestIds::of(TestId::of($test))));
}

it('offers the parts after the one at hand, none it has a run of, none past one a test failed in, and none once judged', function (): void {
    $parted = partedCapped(NotGiven::value());
    $passed = ControlRuns::none()->with(partedPartBy('LoopTest::c'), ControlRun::passed(Seconds::of(1.0)));
    $failed = ControlRuns::none()->with(partedPartBy('LoopTest::c'), ControlRun::failed(TestIds::of(TestId::of('LoopTest::c'))));
    $killed = $parted->after(ControlRun::failed(TestIds::of(TestId::of('LoopTest::a'))));

    expect(partedTests($parted->ahead(ControlRuns::none())))->toBe(['LoopTest::b', 'LoopTest::c', 'LoopTest::d'])
        ->and(partedTests($parted->ahead($passed)))->toBe(['LoopTest::b', 'LoopTest::d'])
        ->and(partedTests($parted->ahead($failed)))->toBe(['LoopTest::b'])
        ->and($killed->ahead(ControlRuns::none()))->toBe([]);
});

it('offers no part past its cap, and offers the parts ahead while a part\'s control runs', function (): void {
    $controlling = partedCapped(NotGiven::value())->after(ControlRun::ranOut());

    expect(partedTests(partedCapped(2)->ahead(ControlRuns::none())))->toBe(['LoopTest::b'])
        ->and(partedCapped(1)->ahead(ControlRuns::none()))->toBe([])
        ->and(partedTests($controlling->ahead(ControlRuns::none())))->toBe(['LoopTest::b', 'LoopTest::c', 'LoopTest::d']);
});

it('reads what parts ahead found in the order of its parts, so it comes to what one part after another comes to', function (ControlRuns $found, MutantStatus $status): void {
    $serial = partedCapped(NotGiven::value());

    foreach (['LoopTest::a', 'LoopTest::b', 'LoopTest::c', 'LoopTest::d'] as $test) {
        $run = $found->of(partedPartBy($test));

        if ($serial->judged()->status() !== partedHeavy()->status() || $run->end() === ControlEnd::Unrun) {
            break;
        }

        $serial = $serial->after($run);
    }

    expect(partedCapped(NotGiven::value())->past($found)->judged())->toEqual($serial->judged())
        ->and(partedCapped(NotGiven::value())->past($found)->judged()->status())->toBe($status);
})->with([
    'every part passes' => [fn(): ControlRuns => ControlRuns::none()
        ->with(partedPartBy('LoopTest::d'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::b'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::a'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::c'), ControlRun::passed(Seconds::of(1.0))), MutantStatus::Survived],
    'the third part kills, the fourth having passed' => [fn(): ControlRuns => ControlRuns::none()
        ->with(partedPartBy('LoopTest::d'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::a'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::b'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::c'), ControlRun::failed(TestIds::of(TestId::of('LoopTest::c')))), MutantStatus::Killed],
]);

it('waits at a part no run has found, and on the control of a part that ran out before one found to kill', function (): void {
    $gap = ControlRuns::none()
        ->with(partedPartBy('LoopTest::a'), ControlRun::passed(Seconds::of(1.0)))
        ->with(partedPartBy('LoopTest::c'), ControlRun::failed(TestIds::of(TestId::of('LoopTest::c'))));
    $ranOut = ControlRuns::none()
        ->with(partedPartBy('LoopTest::a'), ControlRun::ranOut())
        ->with(partedPartBy('LoopTest::b'), ControlRun::failed(TestIds::of(TestId::of('LoopTest::b'))));
    $waiting = partedCapped(NotGiven::value())->past($gap);
    $controlling = partedCapped(NotGiven::value())->past($ranOut);
    $next = $controlling->next();

    expect($waiting->next())->toEqual(partedPartBy('LoopTest::b'))
        ->and($waiting->judged()->status())->toBe(MutantStatus::TimedOut)
        ->and($next)->toEqual(partedPartBy('LoopTest::a')->unmutated())
        ->and($controlling->after(ControlRun::passed(Seconds::of(1.0)))->judged()->status())->toBe(MutantStatus::TimedOut);
});

it('is unjudged, never survived or killed, where its cap comes before its last part and no part settled it', function (): void {
    $passed = ControlRun::passed(Seconds::of(1.0));
    $capped = partedCapped(2)->after($passed)->after($passed);
    $reason = $capped->judged()->reason();

    expect($capped->judged()->status())->toBe(MutantStatus::Unjudged)
        ->and($reason instanceof Reason && $reason->isAtThePartCap())->toBeTrue()
        ->and($reason instanceof Reason ? $reason->text() : '')->toBe('timeouts.heavyParts stopped it at part 2 of 4 of its tests, no part settling it.')
        ->and($capped->next())->toEqual(NotGiven::value());
});

it('is killed by a part within its cap, and judged by its last part where its cap is no sooner', function (): void {
    $passed = ControlRun::passed(Seconds::of(1.0));
    $byFirst = partedCapped(1)->after(ControlRun::failed(TestIds::of(TestId::of('LoopTest::a'))));
    $whole = partedCapped(4)->after($passed)->after($passed)->after($passed)->after($passed);

    expect($byFirst->judged()->status())->toBe(MutantStatus::Killed)
        ->and($whole->judged()->status())->toBe(MutantStatus::Survived);
});

it('says in a log how many parts run ahead beside the one it waits on', function (): void {
    expect(partedCapped(NotGiven::value())->progress(2))->toEndWith('part 1 of 4, 3 left, 2 ahead beside it');
});
