<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\TimeoutControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/** A mutant of a file from line 3 to the last, ended so, by its native id. */
function timeoutMutant(string $file, string $nativeId, MutantStatus $status, int $last = 3): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'LessThan', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of($file), Line::of(3), Line::of($last)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** A mutant of `src/Loop.php` that timed out at a limit of ten seconds. */
function hungMutant(string $nativeId = '1', int $last = 3): Mutant
{
    return timeoutMutant('src/Loop.php', $nativeId, MutantStatus::TimedOut, $last)->withLimit(Seconds::of(10.0));
}

/**
 * Each control asked for, as its file, its tests and its limit.
 *
 * @return list<array{string, list<string>, float}>
 */
function askedControls(TimeoutControls $controls): array
{
    return array_map(static fn(Control $control): array => [
        $control->file()->value(),
        array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
        $control->limit()->seconds(),
    ], [...$controls->asked()]);
}

/** A map in which each of these tests covers lines 3 to 5 of `src/Loop.php`. */
function loopMap(string ...$tests): CoverageMap
{
    $map = CoverageMap::empty();

    foreach ($tests as $test) {
        $map = $map->covered(Path::of('src/Loop.php'), Line::of(3), TestId::of($test));
    }

    return $map;
}

it('asks for one control of each timed-out mutant: its covering tests, its file and its limit', function (): void {
    $map = loopMap('LoopTest::a')
        ->covered(Path::of('src/Loop.php'), Line::of(4), TestId::of('LoopTest::b'))
        ->covered(Path::of('src/Loop.php'), Line::of(5), TestId::of('LoopTest::c'));

    expect(askedControls(TimeoutControls::of(Mutants::of(hungMutant(last: 4)), $map, HeldCovered::none())))
        ->toBe([['src/Loop.php', ['LoopTest::a', 'LoopTest::b'], 10.0]]);
});

it('asks for no control of a mutant that did not time out, of one with no limit in seconds, of one no test covers, or of one whose run timed its tests unmutated', function (): void {
    $mutants = Mutants::of(
        timeoutMutant('src/Loop.php', 'survived', MutantStatus::Survived)->withLimit(Seconds::of(10.0)),
        timeoutMutant('src/Loop.php', 'skipped', MutantStatus::Skipped)->withLimit(Seconds::of(10.0)),
        timeoutMutant('src/Loop.php', 'no limit', MutantStatus::TimedOut),
        timeoutMutant('src/Loop.php', 'capped', MutantStatus::TimedOut)->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes)),
        timeoutMutant('src/Other.php', 'uncovered', MutantStatus::TimedOut)->withLimit(Seconds::of(10.0)),
        hungMutant('trialled')->withUnmutatedNeed(Seconds::of(0.4)),
    );

    expect(askedControls(TimeoutControls::of($mutants, loopMap('LoopTest::a'), HeldCovered::none())))->toBe([]);
});

it('asks for one control of mutants alike in their file, tests and limit', function (): void {
    $controls = TimeoutControls::of(Mutants::of(hungMutant('1'), hungMutant('2')), loopMap('LoopTest::a'), HeldCovered::none());

    expect(askedControls($controls))->toBe([['src/Loop.php', ['LoopTest::a'], 10.0]]);
});

// A held unit's run selects its holding tests alone, so its control runs
// those: a test that covers the line from outside the group never ran with
// the mutant in place.
it('controls a mutant of a held unit by the tests covering its lines that hold it, and any other by every test covering its lines', function (): void {
    $map = loopMap('LoopTest::a', 'SuiteTest::b')->covered(Path::of('src/Other.php'), Line::of(3), TestId::of('SuiteTest::b'));
    $held = HeldCovered::of(Covered::by(
        Unit::held(Path::of('src/Loop.php'), Group::named('holds:src/Loop.php')),
        TestIds::of(TestId::of('LoopTest::a'), TestId::of('LoopTest::c')),
    ));
    $other = timeoutMutant('src/Other.php', '2', MutantStatus::TimedOut)->withLimit(Seconds::of(10.0));

    expect(askedControls(TimeoutControls::of(Mutants::of(hungMutant(), $other), $map, $held)))
        ->toBe([['src/Loop.php', ['LoopTest::a'], 10.0], ['src/Other.php', ['SuiteTest::b'], 10.0]]);
});

it('judges each timeout by what its control found', function (ControlRun $found, MutantStatus $status, Seconds|Unmeasured $need, string $reason): void {
    $mutants = Mutants::of(hungMutant(), timeoutMutant('src/Loop.php', 'killed', MutantStatus::Killed));
    $controls = TimeoutControls::of($mutants, loopMap('LoopTest::a'), HeldCovered::none());
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], $found), Controls::none())];
    $said = $applied[0]->reason();

    expect([$applied[0]->status(), $applied[0]->unmutatedNeed(), $said instanceof Reason ? $said->text() : ''])
        ->toEqual([$status, $need, $reason])
        ->and($applied[1])->toEqual([...$mutants][1]);
})->with([
    'tests that passed in a time' => [ControlRun::passed(Seconds::of(1.5)), MutantStatus::TimedOut, Seconds::of(1.5), ''],
    'tests that passed, untimed' => [ControlRun::passed(Unmeasured::duration()), MutantStatus::TimedOut, Seconds::of(10.0), ''],
    'tests that failed' => [ControlRun::failed(), MutantStatus::Unjudged, Unmeasured::duration(), TimeoutControls::FAILS_UNMUTATED],
    'tests out of memory' => [ControlRun::outOfMemory(), MutantStatus::Unjudged, Unmeasured::duration(), TimeoutControls::OUT_OF_MEMORY],
    'tests that ran out' => [ControlRun::ranOut(), MutantStatus::TimedOut, Unmeasured::duration(), TimeoutControls::RAN_OUT],
    'a control never run' => [
        ControlRun::unrun('the file is gone'),
        MutantStatus::TimedOut,
        Unmeasured::duration(),
        sprintf(TimeoutControls::UNRUN, 'the file is gone'),
    ],
]);

it('says a control the runs hold nothing for never ran', function (): void {
    $mutants = Mutants::of(hungMutant());
    $applied = [...TimeoutControls::of($mutants, loopMap('LoopTest::a'), HeldCovered::none())
        ->applied($mutants, ControlRuns::none(), Controls::none())];

    expect($applied[0]->reason())->toEqual(Reason::that(sprintf(TimeoutControls::UNRUN, ControlRuns::NOT_RUN)));
});

it('leaves a timeout unjudged, the budget having run out before its control, where its control is among those left', function (): void {
    $mutants = Mutants::of(hungMutant('1'), timeoutMutant('src/Loop.php', '2', MutantStatus::TimedOut)->withLimit(Seconds::of(20.0)));
    $controls = TimeoutControls::of($mutants, loopMap('LoopTest::a'), HeldCovered::none());
    [$first, $second] = [...$controls->asked()];
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with($first, ControlRun::passed(Seconds::of(1.0))), Controls::of($second))];

    expect($applied[0]->unmutatedNeed())->toEqual(Seconds::of(1.0))
        ->and($applied[1]->status())->toBe(MutantStatus::Unjudged)
        ->and($applied[1]->reason())->toEqual(OutOfTime::BeforeControlling->reason());
});

it('asks for and applies controls in time linear in the number of mutants', function (): void {
    $timing = static function (int $size): Closure {
        $map = loopMap('LoopTest::a');
        $mutants = Mutants::of(...array_map(static fn(int $at): Mutant => hungMutant(strval($at)), range(1, $size)));

        return static function () use ($mutants, $map): int {
            $controls = TimeoutControls::of($mutants, $map, HeldCovered::none());

            return count($controls->applied($mutants, ControlRuns::none(), Controls::none()));
        };
    };

    expect($timing(10)())->toBe(10)
        ->and(Growth::of(1000, $timing))->toBeLessThan(Growth::LINEAR);
});
