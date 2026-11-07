<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Invoked;
use NightWorksIO\MutationGate\Cli\Flow\Invoking;
use NightWorksIO\MutationGate\Cli\Flow\PartedRuns;
use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Cli\Flow\SurvivorChecking;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
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

/** One invocation of this runner, with this much of its budget left, over this map. */
function invokedWithin(ScriptedRunner $runner, Seconds $left, CoverageMap $map, Pool|NotGiven $pool = new NotGiven()): Invoked
{
    $clock = new StoppedClock('2026-09-30T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
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
    )->invoked(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across($pool instanceof Pool ? $pool : Pool::single()));

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

it('runs only the controls that fit in the time left, at the longest limit among them', function (float $short, float $long, array $run): void {
    $runner = ScriptedRunner::fixture()->answering(Mutants::of(
        invokedMutant('src/Money.php', 'a', MutantStatus::TimedOut)->withLimit(Seconds::of($short)),
        invokedMutant('src/Held.php', 'b', MutantStatus::TimedOut)->withLimit(Seconds::of($long)),
    ), 0);

    invokedWithin($runner, Seconds::of(10.0), invokedMap('src/Money.php', 'src/Held.php'));

    expect(controlsRun($runner))->toBe($run);
})->with([
    'one of two alike' => [6.0, 6.0, [1]],
    'none, the longer not fitting' => [2.0, 20.0, []],
]);

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
