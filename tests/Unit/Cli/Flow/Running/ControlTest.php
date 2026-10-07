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

/** The mutant of a shard's result with this id, as the shard left it. */
function controlMutantIn(ShardResult|CannotJudge $result, string $nativeId): Mutant
{
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $found = array_values(array_filter(
        $outcome instanceof MutationResult ? [...$outcome->mutants()] : [],
        static fn(Mutant $mutant): bool => $mutant->nativeId() === $nativeId,
    ));

    return $found === [] ? throw new LogicException(sprintf('no mutant %s', $nativeId)) : $found[0];
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

    expect(array_map(static fn(array $asked): array => array_map(static fn(Control $control): string => $control->key(), [...$asked[0]]), $runner->controlled()))
        ->toBe([[$moneyControl()->key()]])
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
        ->and(controlMutantIn($resultIn($project, 1), 'Plus-11')->status())->toBe(MutantStatus::TimedOut);
});

it('runs no control under timeouts.mode unjudged, which makes every timeout too slow to judge', function () use ($resultIn): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Timeouts::unjudged()), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect($runner->controlled())->toBe([])
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
