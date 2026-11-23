<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\AbnormalEnds;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlKind;
use NightWorksIO\MutationGate\Core\Control\ControlKinds;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\CrashControls;
use NightWorksIO\MutationGate\Core\Control\MemoryControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
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
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\UnfilteredRuns;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A mutant of a file at line 3 by its native id, with this status. */
function crashMutant(string $file, string $nativeId, MutantStatus $status = MutantStatus::Unjudged): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'Increment', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of('Increment', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** A mutant of `src/Signal.php` whose run alone crashed. */
function loneCrasher(string $nativeId = '1'): Mutant
{
    return crashMutant('src/Signal.php', $nativeId)->unjudged(OutOfTime::CrashedAlone);
}

/** A map in which SignalTest::a, timed at two seconds, covers line 3 of `src/Signal.php`. */
function signalMap(): CoverageMap
{
    return CoverageMap::of()
        ->covered(Path::of('src/Signal.php'), Line::of(3), TestId::of('SignalTest::a'))
        ->timed(TestId::of('SignalTest::a'), Seconds::of(2.0));
}

/** The crash controls of these mutants over that map, each limit between ten seconds and a hundred. */
function crashControlsOf(Mutants $mutants): CrashControls
{
    return CrashControls::of(
        $mutants,
        UnfilteredRuns::none(),
        signalMap(),
        HeldCovered::none(),
        new ExactRun(),
        LimitBounds::between(Seconds::of(10.0), Seconds::of(100.0)),
    );
}

it('asks for one control of each mutant whose run alone crashed: its covering tests, its file, and the standard limit of their time', function (): void {
    $asked = [...crashControlsOf(Mutants::of(loneCrasher('1'), loneCrasher('2')))->asked()];

    expect(array_map(static fn(Control $control): array => [
        $control->file()->value(),
        array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
        $control->limit()->seconds(),
    ], $asked))->toBe([['src/Signal.php', ['SignalTest::a'], 11.0]]);
});

it('asks for no control of a mutant a crashed run left with others, of one judged, or of one no test covers', function (): void {
    $controls = crashControlsOf(Mutants::of(
        crashMutant('src/Signal.php', 'with others')->unjudged(OutOfTime::Crashed),
        crashMutant('src/Signal.php', 'killed', MutantStatus::Killed),
        crashMutant('src/Signal.php', 'unreported'),
        crashMutant('src/Other.php', 'uncovered')->unjudged(OutOfTime::CrashedAlone),
    ));

    expect([...$controls->asked()])->toBe([]);
});

it('judges each mutant whose run alone crashed by what its control found', function (ControlRun $found, MutantStatus $status, string $reason): void {
    $mutants = Mutants::of(loneCrasher());
    $controls = crashControlsOf($mutants);
    $applied = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], $found), Controls::none())];
    $said = $applied[0]->reason();

    expect([$applied[0]->status(), $said instanceof Reason ? $said->text() : ''])
        ->toEqual([$status, $reason])
        ->and($applied[0]->id())->toEqual(loneCrasher()->id())
        ->and($applied[0]->nativeId())->toBe('1');
})->with([
    'a control that passed' => [fn(): ControlRun => ControlRun::passed(Seconds::of(1.0)), MutantStatus::Errored, CrashControls::CRASHED_ITS_RUN],
    'a control that failed' => [fn(): ControlRun => ControlRun::failed(TestIds::none()), MutantStatus::FailsUnmutated, CrashControls::FAILS_UNMUTATED],
    'a control out of memory' => [fn(): ControlRun => ControlRun::outOfMemory(), MutantStatus::Unjudged, fn(): string => sprintf(CrashControls::UNFINISHED, 'out-of-memory')],
    'a control out of time' => [fn(): ControlRun => ControlRun::ranOut(), MutantStatus::Unjudged, fn(): string => sprintf(CrashControls::UNFINISHED, 'ran-out')],
    'a control never run' => [fn(): ControlRun => ControlRun::unrun('the file is gone'), MutantStatus::Unjudged, fn(): string => sprintf(CrashControls::UNFINISHED, 'the file is gone')],
]);

it('leaves a mutant whose control the budget left, or that was halted, unjudged before its control, and every other mutant as it is', function (): void {
    $killed = crashMutant('src/Signal.php', 'killed', MutantStatus::Killed);
    $mutants = Mutants::of(loneCrasher(), $killed);
    $controls = crashControlsOf($mutants);
    $left = [...$controls->applied($mutants, ControlRuns::none(), $controls->asked())];
    $halted = [...$controls->applied($mutants, ControlRuns::none()->with([...$controls->asked()][0], ControlRun::ofProcess(Ran::halted(''), TestIds::none())), Controls::none())];

    expect($left[0]->reason())->toEqual(OutOfTime::BeforeControlling->reason())
        ->and($halted[0]->reason())->toEqual(OutOfTime::BeforeControlling->reason())
        ->and($left[1])->toBe($killed);
});

it('asks for the controls of mutants out of memory and of those whose run alone crashed, each of its own kind, and applies both', function (): void {
    $grown = crashMutant('src/Signal.php', 'grown', MutantStatus::OutOfMemory)->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes));
    $crasher = crashMutant('src/Crash.php', 'crasher')->unjudged(OutOfTime::CrashedAlone);
    $mutants = Mutants::of($grown, $crasher);
    $map = signalMap()
        ->covered(Path::of('src/Crash.php'), Line::of(3), TestId::of('CrashTest::a'))
        ->timed(TestId::of('CrashTest::a'), Seconds::of(2.0));
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(100.0));
    $memory = MemoryControls::of($mutants, UnfilteredRuns::none(), $map, HeldCovered::none(), new ExactRun(), $bounds);
    $crashes = CrashControls::of($mutants, UnfilteredRuns::none(), $map, HeldCovered::none(), new ExactRun(), $bounds);
    $ends = AbnormalEnds::of($memory, $crashes);
    [$ofMemory, $ofCrash] = [...$ends->asked()];
    $kinds = $ends->asking(ControlKinds::otherwise(ControlKind::Kills));
    $asked = static fn(Control $control): array => [...$kinds->timesOf(Controls::of($control), ControlRuns::none())[0]->kinds()];
    $runs = ControlRuns::none()
        ->with($ofMemory, ControlRun::passed(Seconds::of(1.0))->withPeak(MemoryCap::of(20, MemoryUnit::Megabytes)))
        ->with($ofCrash, ControlRun::passed(Seconds::of(1.0)));
    $applied = [...$ends->applied($mutants, $runs, Controls::none())];

    expect([$ofMemory->file()->value(), $ofCrash->file()->value()])->toBe(['src/Signal.php', 'src/Crash.php'])
        ->and([$asked($ofMemory), $asked($ofCrash)])->toBe([[ControlKind::Memory], [ControlKind::Crash]])
        ->and($applied[0]->stop()->need())->toEqual(MemoryCap::of(20, MemoryUnit::Megabytes))
        ->and($applied[1]->status())->toBe(MutantStatus::Errored);
});
