<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\UnnamedKillControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unevidenced;
use NightWorksIO\MutationGate\Core\Runner\ExactRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\LoadingUnfiltered;

/** A mutant of a file at line 3, ended so, by its native id. */
function unnamedMutant(string $file, string $nativeId, MutantStatus $status = MutantStatus::Killed): Mutant
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

/** Evidence that a process exited with code 1, neither a signal nor a fatal error ending it. */
function exitedOne(): Evidence
{
    return Evidence::none()->withEnded(Ended::unprinted(1, signalled: false)->withFatal(fatal: false));
}

/** These mutants' evidence, each that its process exited with code 1. */
function exitedEach(Mutant ...$mutants): Evidences
{
    $evidence = Evidences::none();

    foreach ($mutants as $mutant) {
        $evidence = $evidence->with($mutant->id(), exitedOne());
    }

    return $evidence;
}

/** A map in which RecordTest::a, timed at two seconds, covers line 3 of `src/Record.php`. */
function recordMap(): CoverageMap
{
    return CoverageMap::empty()
        ->covered(Path::of('src/Record.php'), Line::of(3), TestId::of('RecordTest::a'))
        ->timed(TestId::of('RecordTest::a'), Seconds::of(2.0));
}

/** Ten seconds at least, a hundred at most. */
function unnamedBounds(): LimitBounds
{
    return LimitBounds::between(Seconds::of(10.0), Seconds::of(100.0));
}

/** The controls of these mutants, each exited with code 1, over the record map. */
function unnamedControls(Mutant ...$mutants): UnnamedKillControls
{
    return UnnamedKillControls::of(
        Mutants::of(...$mutants),
        exitedEach(...$mutants),
        recordMap(),
        HeldCovered::none(),
        new ExactRun(),
        unnamedBounds(),
    );
}

/**
 * Each control asked for, as its file, its tests and its limit.
 *
 * @return list<array{string, list<string>, float}>
 */
function askedUnnamedControls(UnnamedKillControls $controls): array
{
    return array_map(static fn(Control $control): array => [
        $control->file()->value(),
        array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]),
        $control->limit()->seconds(),
    ], [...$controls->asked()]);
}

it('asks for one control of each kill no test is named for and its ending does not vouch for: its covering tests, its file, and the standard limit of their time', function (): void {
    $controls = unnamedControls(unnamedMutant('src/Record.php', '1'), unnamedMutant('src/Record.php', '2'));

    expect(askedUnnamedControls($controls))->toBe([['src/Record.php', ['RecordTest::a'], 11.0]]);
});

it('asks for a control that loads the test files its kill\'s run loaded with no filter, as its evidence says', function (): void {
    $mutant = unnamedMutant('src/Record.php', '1');
    $files = Paths::of(Path::of('tests/RecordTest.php'));
    $evidence = Evidences::none()->with($mutant->id(), exitedOne()->loadingUnfiltered($files));
    $asked = [...UnnamedKillControls::of(Mutants::of($mutant), $evidence, recordMap(), HeldCovered::none(), new LoadingUnfiltered(), unnamedBounds())->asked()];

    expect($asked[0]->selected())->toBe($files);
});

it('asks for no control of a kill a test is named for, one a signal, a fatal error or a control vouched for, a survivor, or one no test covers, and one of a kill after them', function (): void {
    $named = unnamedMutant('src/Record.php', 'named')->killedBy(TestIds::of(TestId::of('RecordTest::a')));
    $signalled = unnamedMutant('src/Record.php', 'signalled');
    $fatal = unnamedMutant('src/Record.php', 'fatal');
    $vouched = unnamedMutant('src/Record.php', 'vouched');
    $survivor = unnamedMutant('src/Record.php', 'survivor', MutantStatus::Survived);
    $uncovered = unnamedMutant('src/Other.php', 'uncovered');
    $after = unnamedMutant('src/Record.php', 'after');
    $evidence = exitedEach($named, $survivor, $uncovered, $after)
        ->with($signalled->id(), Evidence::none()->withEnded(Ended::unprinted(137, signalled: true)))
        ->with($fatal->id(), Evidence::none()->withEnded(Ended::unprinted(255, signalled: false)->withFatal(fatal: true)))
        ->with($vouched->id(), exitedOne()->vouched());
    $controls = UnnamedKillControls::of(
        Mutants::of($named, $signalled, $fatal, $vouched, $survivor, $uncovered, $after),
        $evidence,
        recordMap(),
        HeldCovered::none(),
        new ExactRun(),
        unnamedBounds(),
    );

    expect(askedUnnamedControls($controls))->toBe([['src/Record.php', ['RecordTest::a'], 11.0]]);
});

it('controls an unnamed kill of a held unit by the tests covering its line that hold it', function (): void {
    $map = recordMap()->covered(Path::of('src/Record.php'), Line::of(3), TestId::of('SuiteTest::b'));
    $held = HeldCovered::of(Covered::by(
        Unit::held(Path::of('src/Record.php'), Group::named('holds:src/Record.php')),
        TestIds::of(TestId::of('RecordTest::a')),
    ));
    $mutant = unnamedMutant('src/Record.php', '1');

    expect(askedUnnamedControls(UnnamedKillControls::of(Mutants::of($mutant), exitedEach($mutant), $map, $held, new ExactRun(), unnamedBounds())))
        ->toBe([['src/Record.php', ['RecordTest::a'], 11.0]]);
});

it('lets an unnamed kill stand, vouched for, where its control passed, and leaves it unjudged, saying why, where its tests fail unmutated too, run out or never run', function (ControlRun $found, MutantStatus $status, string $reason, bool $vouched): void {
    $mutant = unnamedMutant('src/Record.php', '1');
    $mutants = Mutants::of($mutant);
    $controls = unnamedControls($mutant);
    $runs = ControlRuns::none()->with([...$controls->asked()][0], $found);
    $applied = [...$controls->applied($mutants, $runs, Controls::none())];
    $evidence = $controls->vouching($mutants, exitedEach($mutant), $runs, Controls::none());
    $said = $applied[0]->reason();
    $judged = [...Unevidenced::judged(Mutants::of(...$applied), $evidence)];

    expect([$applied[0]->status(), $said instanceof Reason ? $said->text() : '', $evidence->of($mutant->id())->isVouched()])
        ->toEqual([$status, $reason, $vouched])
        ->and($judged[0]->status())->toBe($status);
})->with([
    'a control that passed' => [fn(): ControlRun => ControlRun::passed(Seconds::of(1.0)), MutantStatus::Killed, '', true],
    'a red unmutated run' => [fn(): ControlRun => ControlRun::failed(TestIds::none()), MutantStatus::FailsUnmutated, UnnamedKillControls::FAILS_UNMUTATED, false],
    'a control out of memory' => [fn(): ControlRun => ControlRun::outOfMemory(), MutantStatus::Unjudged, UnnamedKillControls::OUT_OF_MEMORY, false],
    'a control out of time' => [fn(): ControlRun => ControlRun::ranOut(), MutantStatus::Unjudged, UnnamedKillControls::RAN_OUT, false],
    'a control never run' => [
        fn(): ControlRun => ControlRun::unrun('the file is gone'),
        MutantStatus::Unjudged,
        fn(): string => sprintf(UnnamedKillControls::UNRUN, 'the file is gone'),
        false,
    ],
]);

it('vouches for no kill whose control the budget left, and leaves that kill unjudged by the budget', function (): void {
    $mutant = unnamedMutant('src/Record.php', '1');
    $mutants = Mutants::of($mutant);
    $controls = unnamedControls($mutant);
    $control = [...$controls->asked()][0];
    $passed = ControlRuns::none()->with($control, ControlRun::passed(Seconds::of(1.0)));
    $left = $controls->asked();
    $applied = [...$controls->applied($mutants, $passed, $left)];

    expect($controls->vouching($mutants, exitedEach($mutant), $passed, $left)->of($mutant->id())->isVouched())->toBeFalse()
        ->and($applied[0]->reason())->toEqual(OutOfTime::BeforeControlling->reason());
});

it('keeps the evidence of every other mutant as it was while it vouches', function (): void {
    $controlled = unnamedMutant('src/Record.php', '1');
    $other = unnamedMutant('src/Other.php', '2');
    $mutants = Mutants::of($controlled, $other);
    $controls = unnamedControls($controlled);
    $runs = ControlRuns::none()->with([...$controls->asked()][0], ControlRun::passed(Seconds::of(1.0)));
    $evidence = exitedEach($controlled, $other);

    $vouched = $controls->vouching($mutants, $evidence, $runs, Controls::none());

    expect($vouched->of($other->id()))->toEqual(exitedOne())
        ->and($vouched->of($controlled->id()))->toEqual(exitedOne()->vouched());
});

it('asks for and applies unnamed kill controls in time linear in the number of mutants', function (): void {
    $timing = static function (int $size): Closure {
        $mutants = Mutants::of(...array_map(static fn(int $at): Mutant => unnamedMutant('src/Record.php', strval($at)), range(1, $size)));
        $evidence = Evidences::none();

        return static fn(): int => count(UnnamedKillControls::of($mutants, $evidence, recordMap(), HeldCovered::none(), new ExactRun(), unnamedBounds())
            ->applied($mutants, ControlRuns::none(), Controls::none()));
    };

    expect($timing(10)())->toBe(10)
        ->and(Growth::of(1000, $timing))->toBeLessThan(Growth::LINEAR);
});

it('names, for an unnamed kill whose tests fail without it, the tests its control recorded failing, or every test it ran', function (TestIds $recorded, array $failing): void {
    $mutant = unnamedMutant('src/Record.php', '1');
    $controls = unnamedControls($mutant);
    $applied = [...$controls->applied(Mutants::of($mutant), ControlRuns::none()->with([...$controls->asked()][0], ControlRun::failed($recorded)), Controls::none())];

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$applied[0]->killers()]))->toBe($failing);
})->with([
    'recorded' => [fn(): TestIds => TestIds::of(TestId::of('RecordTest::b')), ['RecordTest::b']],
    'none recorded' => [fn(): TestIds => TestIds::none(), ['RecordTest::a']],
]);
