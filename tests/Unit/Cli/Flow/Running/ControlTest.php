<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\MemoryControls;
use NightWorksIO\MutationGate\Core\Control\TimeoutControls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

$resultIn = RunningCases::resultIn(...);
$tickingBy = RunningCases::tickingBy(...);

/** The mutant of a shard's result of this file with this id, as the shard left it. */
function controlMutantIn(ShardResult|CannotJudge $result, string $nativeId, string $file = 'src/Money.php'): Mutant
{
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $found = array_values(array_filter(
        $outcome instanceof MutationResult ? [...$outcome->mutants()] : [],
        static fn(Mutant $mutant): bool => $mutant->nativeId() === $nativeId && $mutant->location()->file()->value() === $file,
    ));

    return $found === [] ? throw new LogicException(sprintf('no mutant %s', $nativeId)) : $found[0];
}

/**
 * The key of every control a runner was asked to run, in order.
 *
 * @return list<string>
 */
function controlKeysAsked(ScriptedRunner $runner): array
{
    $keys = [];

    foreach ($runner->controlled() as [$controls]) {
        $keys = [...$keys, ...array_map(static fn(Control $control): string => $control->key(), [...$controls])];
    }

    return $keys;
}

/** The fixture's timed-out mutant's control: its covering test, allowed its limit. */
$moneyControl = static fn(): Control => Control::of(
    Path::of('src/Money.php'),
    TestIds::of(TestId::of('MoneyTest::adds')),
    Seconds::of(5.0),
);

it('keeps with each timed-out mutant the time its unmutated control took, its tests run under its limit', function () use (
    $resultIn,
    $moneyControl,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->controlling(ControlRuns::none()->with($moneyControl(), ControlRun::passed(Seconds::of(1.5))));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $timedOut = controlMutantIn($resultIn($project, 1), 'Decrement-27');

    expect(controlKeysAsked($runner))->toContain($moneyControl()->key())
        ->and($timedOut->status())->toBe(MutantStatus::TimedOut)
        ->and($timedOut->unmutatedNeed())->toEqual(Seconds::of(1.5));
});

it('leaves a timeout unjudged where its tests fail unmutated too, and too slow to judge, saying why, where they run out or never run', function (
    ControlRun $found,
    MutantStatus $status,
    string $reason,
) use ($resultIn, $moneyControl): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->controlling(ControlRuns::none()->with($moneyControl(), $found));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $timedOut = controlMutantIn($resultIn($project, 1), 'Decrement-27');
    $said = $timedOut->reason();

    expect($timedOut->status())->toBe($status)
        ->and($said instanceof Reason ? $said->text() : '')->toBe($reason)
        ->and($timedOut->unmutatedNeed())->toEqual(Unmeasured::duration());
})->with([
    'tests that fail unmutated' => [ControlRun::failed(), MutantStatus::Unjudged, TimeoutControls::FAILS_UNMUTATED],
    'tests that run out unmutated' => [ControlRun::ranOut(), MutantStatus::TimedOut, TimeoutControls::RAN_OUT],
    'a control never run' => [
        ControlRun::unrun('the file is gone'),
        MutantStatus::TimedOut,
        sprintf(TimeoutControls::UNRUN, 'the file is gone'),
    ],
]);

// The held unit's run selects its holding tests alone. A test that covers
// the line from outside the group never ran with the mutant in place, so
// the control runs only the tests that hold it.
it('controls a held unit\'s timed-out mutant by its holding tests that run it, not every covering test', function () use (
    $resultIn,
): void {
    $project = Flows::project();
    $held = Path::of('src/Held.php');
    $suite = CoverageMap::empty()
        ->covered($held, Line::of(11), TestId::of('HeldTest::a'))
        ->covered($held, Line::of(11), TestId::of('SuiteTest::b'))
        ->timed(TestId::of('HeldTest::a'), Seconds::of(0.1))
        ->timed(TestId::of('SuiteTest::b'), Seconds::of(30.0));
    $group = CoverageMap::empty()->covered($held, Line::of(11), TestId::of('HeldTest::a'))
        ->timed(TestId::of('HeldTest::a'), Seconds::of(0.1));
    $hung = Mutant::of(
        MutantId::hash($held, 'Plus', '11', 0),
        'Plus-11',
        Location::of($held, Line::of(11), Line::of(11)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::TimedOut,
        Unmeasured::duration(),
    )->withLimit(Seconds::of(10.0));
    $scripted = ScriptedRunner::fixture()->answeringInTurn(
        MutationResult::of(Mutants::of($hung), 0),
        MutationResult::of(Mutants::none(), 0),
    );
    $asked = new CoverageAsked($scripted, $group);
    new Handoff(Directory::at($project), HandedMaps::limits())->write(Planned::oneShard(), $suite, KillHistory::none(), Unplaced::map());

    new Running(Flows::adapters($project, [], $asked), Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());

    expect(array_map(
        static fn(array $controls): array => array_map(
            static fn(Control $control): array => [$control->file()->value(), array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()])],
            [...$controls[0]],
        ),
        $scripted->controlled(),
    ))->toBe([[['src/Held.php', ['HeldTest::a']]]])
        ->and(controlMutantIn($resultIn($project, 1), 'Plus-11', 'src/Held.php')->status())->toBe(MutantStatus::TimedOut);
});

it('runs no control of a timeout under timeouts.mode unjudged, which makes every timeout too slow to judge', function () use (
    $resultIn,
    $moneyControl,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Timeouts::unjudged()), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(controlKeysAsked($runner))->not->toContain($moneyControl()->key())
        ->and(controlMutantIn($resultIn($project, 1), 'Decrement-27')->unmutatedNeed())->toEqual(Unmeasured::duration());
});

it('runs no control of a timeout whose own run already ran its tests unmutated, as a trial does', function () use ($resultIn): void {
    $project = Flows::project();
    $trialled = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Decrement', '27', 0),
        'Decrement-27',
        Location::of(Path::of('src/Money.php'), Line::of(27), Line::of(27)),
        Mutation::of('Decrement', MutatorFamily::Arithmetic, ''),
        MutantStatus::TimedOut,
        Unmeasured::duration(),
    )->withLimit(Seconds::of(5.0))->withUnmutatedNeed(Seconds::of(0.4));
    $runner = ScriptedRunner::fixture()->answeringInTurn(
        MutationResult::of(Mutants::of($trialled), 0),
        MutationResult::of(Mutants::none(), 0),
    );

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect($runner->controlled())->toBe([])
        ->and(controlMutantIn($resultIn($project, 1), 'Decrement-27')->unmutatedNeed())->toEqual(Seconds::of(0.4));
});

it('leaves a timeout unjudged where its control would not fit in the time the budget has left', function () use (
    $tickingBy,
    $resultIn,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('45s')), $tickingBy(10))
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $timedOut = controlMutantIn($resultIn($project, 1), 'Decrement-27');

    expect($runner->controlled())->toBe([])
        ->and($timedOut->status())->toBe(MutantStatus::Unjudged)
        ->and($timedOut->reason())->toEqual(OutOfTime::BeforeControlling->reason());
});

it('cannot judge a shard whose runner cannot run its controls', function () use ($resultIn): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->controlling(CannotJudge::because('The runner cannot serve a file unmutated.'));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    $result = $resultIn($project, 1);

    expect($result instanceof ShardResult ? $result->outcome() : $result)
        ->toEqual(CannotJudge::because('The runner cannot serve a file unmutated.'));
});

it('leaves a kill unjudged where the tests that killed it fail unmutated too, and lets it stand where they pass', function (ControlRun $found, MutantStatus $status) use ($resultIn): void {
    $project = Flows::project();
    $killControl = Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of('MoneyTest::adds')), Seconds::of(10.0));
    $runner = ScriptedRunner::fixture()->controlling(ControlRuns::none()->with($killControl, $found));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $kill = controlMutantIn($resultIn($project, 1), 'Plus-11');

    expect(controlKeysAsked($runner))->toContain($killControl->key())
        ->and($kill->status())->toBe($status);
})->with([
    'tests that pass unmutated' => [ControlRun::passed(Seconds::of(0.2)), MutantStatus::Killed],
    'tests that fail unmutated' => [ControlRun::failed(), MutantStatus::Unjudged],
]);

it('weighs a mutant out of memory by the peak of its control under the same cap, and leaves it too heavy where the control runs out too', function (
    ControlRun $found,
    MemoryCap|Unmeasured $need,
    string $reason,
) use ($resultIn): void {
    $project = Flows::project();
    $outOfMemory = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0),
        'Grow-11',
        Location::of(Path::of('src/Money.php'), Line::of(11), Line::of(11)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::OutOfMemory,
        Seconds::of(0.5),
    )->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes));
    $control = Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of('MoneyTest::adds')), Seconds::of(10.0));
    $runner = ScriptedRunner::fixture()
        ->answering(Mutants::of($outOfMemory), 0)
        ->controlling(ControlRuns::none()->with($control, $found));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $weighed = controlMutantIn($resultIn($project, 1), 'Grow-11');
    $said = $weighed->reason();

    expect(controlKeysAsked($runner))->toContain($control->key())
        ->and($weighed->unmutatedNeed())->toEqual($need)
        ->and($said instanceof Reason ? $said->text() : '')->toBe($reason);
})->with([
    'a control that held 20M' => [ControlRun::passed(Seconds::of(1.0))->withPeak(MemoryCap::of(20, MemoryUnit::Megabytes)), MemoryCap::of(20, MemoryUnit::Megabytes), ''],
    'a control out of memory too' => [ControlRun::outOfMemory(), Unmeasured::duration(), MemoryControls::OUT_OF_MEMORY],
]);

it('allows a kill\'s control the limit laid on the start-up the shard measured', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->startingUpIn(Seconds::of(4.0));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $limits = [];

    foreach ($runner->controlled() as [$controls]) {
        $limits = [...$limits, ...array_map(static fn(Control $control): float => $control->limit()->seconds(), [...$controls])];
    }

    // A timeout's control keeps the limit its mutant ran out of; a kill's is laid anew, past the floor of 10 s.
    expect(max([0.0, ...$limits]))->toBeGreaterThanOrEqual(12.0);
});
