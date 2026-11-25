<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CheckedFiles;
use NightWorksIO\MutationGate\Cli\Flow\Invoked;
use NightWorksIO\MutationGate\Cli\Flow\Invoking;
use NightWorksIO\MutationGate\Cli\Flow\PartedRuns;
use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Cli\Flow\SurvivorChecking;
use NightWorksIO\MutationGate\Config\Flaky;
use NightWorksIO\MutationGate\Config\PestSpeedUps;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\LoadingTheFilesOfItsTests;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\SideBySideRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

afterEach(function (): void {
    Scratch::sweep();
});

/** A mutant of line 3 of a file, ended so, by its native id. */
function invokedMutant(string $file, string $nativeId, MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'LessThan', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, sprintf("@@ @@\n-<\n+%s", $nativeId)),
        $status,
        Unmeasured::duration(),
    );
}

/** One invocation of this runner, with this much of its budget left, over this map, in this pool, with these ports besides. */
function invokedWithin(ScriptedRunner $runner, Seconds $left, CoverageMap $map, Pool|NotGiven $pool = new NotGiven(), object ...$ports): Invoked
{
    return invokedOver(Paths::of(Path::of('src/Money.php')), $runner, $left, $map, $pool, ...$ports);
}

/** One invocation of these files, in their order, of this runner, as invokedWithin() runs it. */
function invokedOver(Paths $files, ScriptedRunner $runner, Seconds $left, CoverageMap $map, Pool|NotGiven $pool = new NotGiven(), object ...$ports): Invoked
{
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner, ...$ports);
    $deadline = Deadline::after($clock->now(), $left);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $invoked = new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        $deadline,
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, $deadline, Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of($files, WholeSuite::tests())->across($pool instanceof Pool ? $pool : Pool::single()));

    return $invoked instanceof Invoked ? $invoked : throw new LogicException($invoked->why());
}

/** A map in which one test covers line 3 of each of these files. */
function invokedMap(string ...$files): CoverageMap
{
    $map = CoverageMap::of();

    foreach ($files as $file) {
        $map = $map->covered(Path::of($file), Line::of(3), TestId::of(sprintf('%sTest::runs', basename($file, '.php'))));
    }

    return $map;
}

/**
 * The number of controls in each run of controls the runner was asked for.
 *
 * @return list<int>
 */
function controlsRun(ScriptedRunner $runner): array
{
    return array_map(static fn(array $asked): int => count($asked[0]), $runner->controlled());
}

it('runs again only the survivors that fit in the time left, first to last, and leaves the rest unjudged', function (): void {
    $survivors = array_map(static fn(string $id): Mutant => invokedMutant('src/Money.php', $id, MutantStatus::Survived), ['a', 'b', 'c']);
    $runner = ScriptedRunner::fixture()->answering(Mutants::of(...$survivors), 0)->killingAgain();

    $invoked = invokedWithin($runner, Seconds::of(25.0), CoverageMap::of());

    expect(array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$runner->retries()[0][0]]))->toBe(['a', 'b'])
        ->and(count($runner->retries()))->toBe(1)
        ->and([...$invoked->flaky])->toEqual([$survivors[0]->id(), $survivors[1]->id()])
        ->and(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$invoked->result->mutants()]))
        ->toBe([MutantStatus::Survived, MutantStatus::Survived, MutantStatus::Unjudged]);
});

it('runs only the controls that fit in the time left, each by its own limit, a longer one keeping no shorter one from running', function (float $short, float $long, array $run): void {
    $runner = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::TimedOut)->withLimit(Seconds::of($short)),
        invokedMutant('src/Held.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of($long)),
    ), 0);

    invokedWithin($runner, Seconds::of(10.0), invokedMap('src/Money.php', 'src/Held.php'));

    expect(controlsRun($runner))->toBe($run);
})->with([
    'one of two alike' => [6.0, 6.0, [1]],
    'the shorter one, the longer not fitting' => [2.0, 20.0, [1]],
    'neither, both too long' => [12.0, 20.0, []],
]);

it('controls first the kills of the files the batch took first, so a saved unit\'s kills stand where the time left holds one control', function (): void {
    $other = invokedMutant('src/Other.php', 'other', MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('OtherTest::runs')));
    $saved = invokedMutant('src/Saved.php', 'saved', MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('SavedTest::runs')));
    $runner = ScriptedRunner::fixture()->answering(Mutants::of($other, $saved), 0);
    $files = Paths::of(Path::of('src/Saved.php'), Path::of('src/Other.php'));

    $invoked = invokedOver($files, $runner, Seconds::of(15.0), invokedMap('src/Saved.php', 'src/Other.php'));
    $statuses = [];

    foreach ($invoked->result->mutants() as $mutant) {
        $statuses[$mutant->nativeId()] = $mutant->status();
    }

    expect(controlsRun($runner))->toBe([1])
        ->and(array_map(static fn(Control $control): string => $control->file()->value(), [...$runner->controlled()[0][0]]))
        ->toBe(['src/Saved.php'])
        ->and($statuses)->toBe(['other' => MutantStatus::Unjudged, 'saved' => MutantStatus::Killed]);
});

it('allows a kill\'s control, named or not, the standard limit of its tests\' time up to timeouts.controlMost, past timeouts.most', function (bool $named): void {
    $killed = invokedMutant('src/Money.php', 'a', MutantStatus::Killed);
    $killed = $named ? $killed->killedBy(TestIds::of(TestId::of('MoneyTest::runs'))) : $killed;
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::runs'))
        ->timed(TestId::of('MoneyTest::runs'), Seconds::of(150.0));
    $runner = ScriptedRunner::fixture()->answering(Mutants::of($killed), 0);

    invokedWithin($runner, Seconds::of(10_000.0), $map);
    $limits = [];

    foreach ($runner->controlled() as [$controls]) {
        $limits = [...$limits, ...array_map(static fn(Control $control): float => $control->limit()->seconds(), [...$controls])];
    }

    expect(max([0.0, ...$limits]))->toBeGreaterThan(300.0)->toBeLessThanOrEqual(900.0);
})->with(['named' => [true], 'unnamed' => [false]]);

it('passes over, for the run\'s doom, the survivors its runner judged by the shard\'s part of the test files alone', function (): void {
    $whole = invokedMutant('src/Money.php', 'whole', MutantStatus::Survived);
    $owned = invokedMutant('src/Money.php', 'owned', MutantStatus::Survived);
    $other = invokedMutant('src/Money.php', 'other', MutantStatus::Survived);
    $runner = ScriptedRunner::fixture()->answeringInTurn(
        MutationResult::skippingNone(Mutants::of($whole, $owned, $other))->withEvidence(Evidences::none()
            ->with($owned->id(), Evidence::none()->streamed()->sharing(TestShare::OwnedPart))
            ->with($other->id(), Evidence::none()->streamed()->sharing(TestShare::OtherPart))),
    );

    $invoked = invokedWithin($runner, Seconds::of(600.0), CoverageMap::of());

    expect($invoked->parted)->toEqual(MutantIds::of($owned->id(), $other->id()))
        ->and($invoked->passedOver()->has($whole->id()))->toBeFalse()
        ->and($invoked->passedOver()->has($other->id()))->toBeTrue();
});

it('leaves a fallback survivor of its part\'s own run out of its doom, and out of its static checks and proofs of equivalence where another part owns it', function (): void {
    $whole = invokedMutant('src/Money.php', 'whole', MutantStatus::Survived);
    $owned = invokedMutant('src/Money.php', 'owned', MutantStatus::Survived);
    $other = invokedMutant('src/Money.php', 'other', MutantStatus::Survived);
    $runner = ScriptedRunner::fixture()->answeringInTurn(
        MutationResult::skippingNone(Mutants::of($whole, $owned, $other))->withEvidence(Evidences::none()
            ->with($owned->id(), Evidence::none()->sharing(TestShare::OwnedPart))
            ->with($other->id(), Evidence::none()->sharing(TestShare::OtherPart))),
    );

    $invoked = invokedWithin($runner, Seconds::of(600.0), CoverageMap::of());
    $natives = static fn(Mutant ...$mutants): array => array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), $mutants);

    expect([$invoked->passedOver()->has($whole->id()), $invoked->passedOver()->has($owned->id()), $invoked->passedOver()->has($other->id())])
        ->toBe([false, true, true])
        ->and([$invoked->checked->has($whole->id()), $invoked->checked->has($owned->id()), $invoked->checked->has($other->id())])
        ->toBe([true, true, false])
        ->and($natives(...$runner->checkables()))->toBe(['whole', 'owned']);
});

/** A map in which these tests, by their seconds, cover line 3 of src/Money.php. */
function invokedHeavyMap(float ...$seconds): CoverageMap
{
    $map = CoverageMap::of();

    foreach ($seconds as $test => $took) {
        $id = TestId::of(strval($test));
        $map = $map->covered(Path::of('src/Money.php'), Line::of(3), $id)->timed($id, Seconds::of($took));
    }

    return $map;
}

/**
 * How wide each round of a heavy mutant of src/Money.php is, every run passing, and how the mutant comes out.
 *
 * @return array{list<int>, MutantStatus}
 */
function invokedHeavy(CoverageMap $map, Seconds $left): array
{
    $runner = ScriptedRunner::fixture()
        ->answering(Mutants::of(invokedMutant('src/Money.php', 'a', MutantStatus::TimedOut)->withLimit(Seconds::of(300.0))), 0)
        ->controlling(ControlRun::passed(Seconds::of(1.0)));
    $invoked = invokedWithin($runner, $left, $map, Pool::of(ProcessCount::of(3), Workers::Fresh));

    return [controlsRun($runner), [...$invoked->result->mutants()][0]->status()];
}

it('fills a round of heavy parts with those ahead that fit in the time left, and with none where what waits does not fit', function (CoverageMap $map, float $left, array $rounds, MutantStatus $status): void {
    expect(invokedHeavy($map, Seconds::of($left)))->toBe([$rounds, $status]);
})->with([
    'every part fits' => [fn(): CoverageMap => invokedHeavyMap(a: 90.0, b: 90.0, c: 90.0, d: 90.0), 300.0, [3, 1], MutantStatus::Survived],
    'the part at hand does not fit' => [fn(): CoverageMap => invokedHeavyMap(a: 90.0, b: 90.0, c: 90.0, d: 90.0), 200.0, [], MutantStatus::Unjudged],
    'a shorter part at hand, those ahead too long' => [fn(): CoverageMap => invokedHeavyMap(a: 10.0, b: 90.0, c: 90.0, d: 90.0), 100.0, [1], MutantStatus::Unjudged],
]);

/** The fixture's first mutant of src/Money.php, timed out at the most a mutant may run. */
function invokedTimedOut(): Mutant
{
    $fixture = [...Flows::mutantsOf('src/Money.php')][0];

    return Mutant::of($fixture->id(), $fixture->nativeId(), $fixture->location(), $fixture->mutation(), MutantStatus::TimedOut, Seconds::of(300.0))
        ->withLimit(Seconds::of(300.0));
}

/** A map in which two tests of 200 seconds each cover the heavy mutant's line, more than the most one run may take. */
function invokedTimedOutMap(): CoverageMap
{
    $location = invokedTimedOut()->location();

    return CoverageMap::of()
        ->covered($location->file(), $location->start(), TestId::of('a'), TestId::of('b'))
        ->timed(TestId::of('a'), Seconds::of(200.0))
        ->timed(TestId::of('b'), Seconds::of(200.0));
}

it('screens a heavy mutant whose parts all pass as a survivor, after the survivors the run left, and keeps what both screenings found', function (): void {
    $fixture = [...Flows::mutantsOf('src/Money.php')][1];
    $survivor = Mutant::of($fixture->id(), $fixture->nativeId(), $fixture->location(), $fixture->mutation(), MutantStatus::Survived, Seconds::of(0.1));
    $runner = ScriptedRunner::fixture()
        ->answering(Mutants::of(invokedTimedOut(), $survivor), 0)
        ->controlling(ControlRun::passed(Seconds::of(1.0)))
        ->killingAgain();
    $checker = new RecordingChecker(new StaticCheckerFake(
        AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}')),
        Findings::none(),
        [
            CheckedFiles::mutant(invokedTimedOut()->id())->value() => Findings::none(),
            CheckedFiles::mutant($survivor->id())->value() => Findings::none(),
        ],
    ), Flows::project());

    $invoked = invokedWithin($runner, Seconds::of(100000.0), invokedTimedOutMap(), new NotGiven(), $checker);

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$invoked->result->mutants()]))
        ->toBe([MutantStatus::Survived, MutantStatus::Survived])
        ->and(array_map(static fn(array $retry): array => array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$retry[0]]), $runner->retries()))
        ->toBe([[$survivor->nativeId()], [invokedTimedOut()->nativeId()]])
        ->and([...$invoked->flaky])->toEqual([$survivor->id(), invokedTimedOut()->id()])
        ->and(count($checker->asked()))->toBe(2);
});

it('starts its controls beside its survivors\' runs again, each in its share of the pool\'s places, and takes them after', function (): void {
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::Survived),
        invokedMutant('src/Money.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
    ), 0);
    $runner = SideBySideRunner::over($scripted);
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = invokedMap('src/Money.php');
    $tokens = static fn(MutationRequest $request): array => array_map(
        static fn(WorkerSlot $slot): string => (string) iterator_to_array($slot->variables(), preserve_keys: true)['TEST_TOKEN'],
        [...WorkerSlots::inPool($request->pool(), 'run')],
    );

    new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        Unlimited::time(),
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, Unlimited::time(), Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(4), Workers::Fork)));

    expect($runner->calls())->toBe(['started', 'retry', 'taken'])
        ->and($tokens($scripted->retries()[0][4]))->toBe(['1', '2'])
        ->and($tokens($scripted->controlled()[0][1]))->toBe(['3', '4']);
});

it('runs again after its survivors\' runs each control it started beside them that never ran', function (): void {
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::Survived),
        invokedMutant('src/Money.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
    ), 0)->controlling(ControlRun::unrun('its workers did not start'));
    $runner = SideBySideRunner::over($scripted);
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = invokedMap('src/Money.php');

    new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        Unlimited::time(),
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, Unlimited::time(), Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(4), Workers::Fork)));

    expect($runner->calls())->toBe(['started', 'retry', 'taken', 'controls'])
        ->and($scripted->controlled()[1][0]->count())->toBe($scripted->controlled()[0][0]->count())
        ->and($scripted->controlled()[1][1]->pool()->processes()->count())->toBe(4);
});

it('cannot judge an invocation whose controls started beside its survivors\' runs could not be taken, and runs none of them again', function (): void {
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::Survived),
        invokedMutant('src/Money.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
    ), 0)->controlling(CannotJudge::because('the controls\' workers crashed'));
    $runner = SideBySideRunner::over($scripted);
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = invokedMap('src/Money.php');

    $invoked = new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        Unlimited::time(),
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, Unlimited::time(), Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(4), Workers::Fork)));

    expect($invoked)->toEqual(CannotJudge::because('the controls\' workers crashed'))
        ->and($runner->calls())->toBe(['started', 'retry', 'taken']);
});

it('runs its controls after its survivors\' runs again in the whole pool where there are no survivors to run beside them', function (): void {
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
    ), 0);
    $runner = SideBySideRunner::over($scripted);
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = invokedMap('src/Money.php');

    new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        Unlimited::time(),
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, Unlimited::time(), Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(4), Workers::Fork)));

    expect($runner->calls())->toBe(['controls'])
        ->and($scripted->controlled()[0][1]->pool()->processes()->count())->toBe(4);
});

/**
 * The runner's calls, and the slots of the survivors' runs again and of the controls started beside them, of an
 * invocation over this many survivors and timed-out mutants, in a pool of this many places, with these settings
 * and this deadline.
 *
 * @return array{list<string>, list<string>, list<string>}
 */
function overlappedOver(int $survivors, int $timedOut, int $places, Settings $settings, Deadline|Unlimited $deadline): array
{
    $mutants = [];

    for ($at = 0; $at < $survivors + $timedOut; $at++) {
        $mutants[] = $at < $survivors
            ? invokedMutant('src/Money.php', sprintf('s%d', $at), MutantStatus::Survived)
            : invokedMutant('src/Money.php', sprintf('t%d', $at), MutantStatus::TimedOut)->withLimit(Seconds::of(6.0));
    }

    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(...$mutants), 0);
    $runner = SideBySideRunner::over($scripted);
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = invokedMap('src/Money.php');
    $tokens = static fn(MutationRequest $request): array => array_map(
        static fn(WorkerSlot $slot): string => (string) (iterator_to_array($slot->variables(), preserve_keys: true)['TEST_TOKEN'] ?? 'alone'),
        [...WorkerSlots::inPool($request->pool(), 'run')],
    );

    new Invoking(
        $adapters,
        $settings,
        $clock,
        $deadline,
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, $deadline, Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, $settings, $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(max(1, $places)), Workers::Fork)));

    return [
        $runner->calls(),
        $scripted->retries() === [] ? [] : $tokens($scripted->retries()[0][4]),
        $scripted->controlled() === [] ? [] : $tokens($scripted->controlled()[0][1]),
    ];
}

it('gives its survivors\' runs again their share of the places beside one control, rounded, at least one, and leaves the control one at least', function (int $survivors, int $places, array $again, array $controls): void {
    [$calls, $retried, $controlled] = overlappedOver($survivors, 1, $places, Flows::settings(), Unlimited::time());

    expect($calls)->toBe(['started', 'retry', 'taken'])
        ->and($retried)->toBe($again)
        ->and($controlled)->toBe($controls);
})->with([
    'half of four' => [1, 4, ['1', '2'], ['3', '4']],
    'two thirds of four, rounded up from 2.67' => [2, 4, ['1', '2', '3'], ['4']],
    'two thirds of eight, rounded down from 5.33' => [2, 8, ['1', '2', '3', '4', '5'], ['6', '7', '8']],
    'seven eighths of four, all but the control\'s one' => [7, 4, ['1', '2', '3'], ['4']],
    'half of two, at least one' => [1, 2, ['alone'], ['2']],
]);

it('starts no control beside its survivors\' runs again where the steps do not overlap, survivors are not run again, a budget must fit them or one place is all', function (Settings $settings, Deadline|Unlimited $deadline, int $places): void {
    [$calls] = overlappedOver(1, 1, $places, $settings, $deadline);

    expect($calls)->not->toContain('started');
})->with([
    'steps one after another' => [fn(): Settings => Flows::settings(PestSpeedUps::stepByStep()), fn(): Unlimited => Unlimited::time(), 4],
    'survivors not confirmed' => [fn(): Settings => Flows::settings(Flaky::notConfirmingSurvivors()), fn(): Unlimited => Unlimited::time(), 4],
    'a budget' => [fn(): Settings => Flows::settings(), fn(): Deadline => Deadline::after(new DateTimeImmutable('2026-09-30T12:00:00+00:00'), Seconds::of(100000.0)), 4],
    'one place' => [fn(): Settings => Flows::settings(), fn(): Unlimited => Unlimited::time(), 1],
]);

it('stops the controls it started beside its survivors\' runs again where those runs cannot judge', function (): void {
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::Survived),
        invokedMutant('src/Money.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
    ), 0)->refusingAgain('the runner fell over');
    $runner = SideBySideRunner::over($scripted);
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = invokedMap('src/Money.php');

    $invoked = new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        Unlimited::time(),
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, Unlimited::time(), Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(4), Workers::Fork)));

    expect($invoked)->toBeInstanceOf(CannotJudge::class)
        ->and($runner->calls())->toBe(['started', 'retry', 'stopped']);
});

it('tells the controls it starts beside its survivors\' runs again the file each test is in, where the runner loads the files of a control\'s tests', function (bool $loads, int $placed): void {
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::Survived),
        invokedMutant('src/Money.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
    ), 0);
    $scripted = $loads
        ? $scripted->behaving($scripted->behaviour()->selecting(new LoadingTheFilesOfItsTests()))
        : $scripted;
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], SideBySideRunner::over($scripted));
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));

    new Invoking(
        $adapters,
        Flows::settings(),
        $clock,
        Unlimited::time(),
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, Unlimited::time(), Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, Flows::settings(), $map, HeldCovered::none()),
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(4), Workers::Fork)));

    expect($scripted->controlled()[0][1]->narrowing()->places()->count())->toBe($placed);
})->with(['a runner that loads them' => [true, 1], 'a runner that loads every file' => [false, 0]]);
