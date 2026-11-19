<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Invoked;
use NightWorksIO\MutationGate\Cli\Flow\Invoking;
use NightWorksIO\MutationGate\Cli\Flow\PartedRuns;
use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Cli\Flow\SurvivorChecking;
use NightWorksIO\MutationGate\Config\HeavyMutants;
use NightWorksIO\MutationGate\Config\Setting;
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
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

afterEach(function (): void {
    Scratch::sweep();
});

/** A mutant of line 3 of a file, timed out at this limit, by its native id. */
function besideControlsTimedOut(string $file, string $nativeId, float $limit): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'LessThan', sprintf("-<\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of('LessThan', MutatorFamily::Boundary, sprintf("@@ @@\n-<\n+%s", $nativeId)),
        MutantStatus::TimedOut,
        Seconds::of($limit),
    )->withLimit(Seconds::of($limit));
}

/** The heavy mutant: four tests of 90 seconds each cover it, more than one run may take, so it is judged in parts. */
function besideControlsHeavy(): Mutant
{
    return besideControlsTimedOut('src/Money.php', 'heavy', 300.0);
}

/** A mutant timed out at six seconds whose one test of a second its control runs. */
function besideControlsLight(): Mutant
{
    return besideControlsTimedOut('src/Held.php', 'light', 6.0);
}

/** The map of both: four tests of 90 seconds each cover the heavy mutant's line, and one of a second the light's. */
function besideControlsMap(): CoverageMap
{
    $map = CoverageMap::of()
        ->covered(Path::of('src/Held.php'), Line::of(3), TestId::of('light'))
        ->timed(TestId::of('light'), Seconds::of(1.0));

    foreach (['a', 'b', 'c', 'd'] as $test) {
        $map = $map->covered(Path::of('src/Money.php'), Line::of(3), TestId::of($test))->timed(TestId::of($test), Seconds::of(90.0));
    }

    return $map;
}

/**
 * These mutants, invoked in a pool of three places with an hour left under this `timeouts.heavyBeside`, every
 * control and part ending so, with the size of each set of controls the runner was asked for, and how each mutant
 * came out, by its native id.
 *
 * @return array{list<int>, array<string, MutantStatus>}
 */
function besideControlsInvoked(ControlRun $ending, Setting $beside, Mutant ...$mutants): array
{
    $settings = Flows::settings($beside);
    $runner = ScriptedRunner::fixture()->answering(Mutants::of(...$mutants), 0)->controlling($ending);
    $clock = new StoppedClock('2026-11-19T12:00:00+00:00');
    $adapters = Flows::adapters(Flows::project(), [], $runner);
    $deadline = Deadline::after($clock->now(), Seconds::of(3600.0));
    $stopwatch = new Stopwatch($clock, $clock->now());
    $map = besideControlsMap();
    $invoked = new Invoking(
        $adapters,
        $settings,
        $clock,
        $deadline,
        $stopwatch,
        $map,
        HeldCovered::none(),
        new SurvivorChecking($adapters, $clock, $deadline, Seconds::of(60.0), $stopwatch),
        new PartedRuns($adapters, $settings, $map, HeldCovered::none()),
    )->invoked(
        MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')), WholeSuite::tests())
            ->across(Pool::of(ProcessCount::of(3), Workers::Fresh)),
    );
    $judged = [];

    foreach ($invoked instanceof Invoked ? $invoked->result->mutants() : throw new LogicException($invoked->why()) as $mutant) {
        $judged[$mutant->nativeId()] = $mutant->status();
    }

    return [array_map(static fn(array $asked): int => count($asked[0]), $runner->controlled()), $judged];
}

it('runs the first round of a heavy mutant\'s parts in the controls\' pool, behind the controls, and the next round after', function (): void {
    [$sets] = besideControlsInvoked(ControlRun::passed(Seconds::of(1.0)), HeavyMutants::firstRoundBesideControls(), besideControlsLight(), besideControlsHeavy());

    expect($sets)->toBe([4, 1]);
});

it('runs the first round after the controls under timeouts.heavyBeside off', function (): void {
    [$sets] = besideControlsInvoked(ControlRun::passed(Seconds::of(1.0)), HeavyMutants::firstRoundAfterControls(), besideControlsLight(), besideControlsHeavy());

    expect($sets)->toBe([1, 3, 1]);
});

it('judges every mutant as it judges each alone, and as it does with the first round after the controls', function (ControlRun $ending, array $judged): void {
    $beside = HeavyMutants::firstRoundBesideControls();
    [$lightAlone, $light] = besideControlsInvoked($ending, $beside, besideControlsLight());
    [, $heavy] = besideControlsInvoked($ending, $beside, besideControlsHeavy());
    [, $both] = besideControlsInvoked($ending, $beside, besideControlsLight(), besideControlsHeavy());
    [, $after] = besideControlsInvoked($ending, HeavyMutants::firstRoundAfterControls(), besideControlsLight(), besideControlsHeavy());

    expect($both)->toBe([...$light, ...$heavy])
        ->and($both)->toBe($judged)
        ->and($after)->toBe($judged)
        ->and($lightAlone)->toBe([1]);
})->with([
    'every run passes' => [
        fn(): ControlRun => ControlRun::passed(Seconds::of(1.0)),
        ['light' => MutantStatus::TimedOut, 'heavy' => MutantStatus::Survived],
    ],
    'every run fails' => [
        fn(): ControlRun => ControlRun::failed(TestIds::of(TestId::of('a'))),
        ['light' => MutantStatus::FailsUnmutated, 'heavy' => MutantStatus::Killed],
    ],
]);
