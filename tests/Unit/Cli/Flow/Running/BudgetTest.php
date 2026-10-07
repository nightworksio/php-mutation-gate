<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Interruption;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Tests;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

afterEach(function (): void {
    Scratch::sweep();
});

$ticking = RunningCases::ticking(...);
$tickingBy = RunningCases::tickingBy(...);
$unjudged = RunningCases::unjudged(...);
$resultIn = RunningCases::resultIn(...);
$statuses = RunningCases::statuses(...);
$orderings = RunningCases::orderings(...);

it('weighs each mutant out of memory by the peak its plan measured, and leaves an older plan\'s unknown', function (
    MemoryCap|NotGiven $peak,
    MemoryCap|Unmeasured $weighed,
) use ($resultIn): void {
    $project = Flows::project();
    $outOfMemory = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::OutOfMemory,
        Seconds::of(0.5),
    )->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes));
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of($outOfMemory), 0);

    new Running(Flows::adapters($project, [], $scripted), Flows::settings(), Flows::setup())
        ->run(
            Planned::handedIn($project, Planned::oneShard()->briefed(Briefing::standard()->weighing($peak))),
            ShardId::of(1),
            Workspace::results(),
        );
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $mutants = $outcome instanceof MutationResult ? [...$outcome->mutants()] : [];

    expect($mutants[0]->unmutatedNeed())->toEqual($weighed)
        ->and($mutants[0]->limit())->toEqual(MemoryCap::of(64, MemoryUnit::Megabytes));
})->with([
    'measured' => [MemoryCap::of(20, MemoryUnit::Megabytes), MemoryCap::of(20, MemoryUnit::Megabytes)],
    'an older plan' => [NotGiven::value(), Unmeasured::duration()],
]);

it('runs each mutant\'s likely killers first, by the kill history the plan handed the shard', function () use (
    $orderings,
): void {
    $project = Flows::project();
    $ranked = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 2));
    $history = KillHistory::none()->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), $ranked);
    new Handoff(Directory::at($project), HandedMaps::limits())->write(Planned::oneShard(), Flows::map(), $history, Unplaced::map());
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    $handed = $history->onlyIn(Paths::of(Path::of('src/Money.php')));

    $ordered = Ordering::of(TestOrder::KillersFirst, $handed);

    expect($orderings($runner))->toEqual([$ordered, $ordered])
        ->and($handed)->not->toEqual(KillHistory::none());
});

it('asks the runner for every killer of each mutant where the plan records a full kill matrix, and the first otherwise', function (): void {
    $project = Flows::project();
    $full = ScriptedRunner::fixture();
    $first = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $full), Flows::settings(), Flows::setup())->run(
        Planned::handedIn($project, Planned::oneShard()->briefed(Briefing::standard()->recording(MatrixKind::Full))),
        ShardId::of(1),
        Workspace::results(),
    );
    new Running(Flows::adapters($project, [], $first), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $matrices = static fn(ScriptedRunner $runner): array => array_map(
        static fn(MutationRequest $request): MatrixKind => $request->search()->matrix(),
        $runner->requests(),
    );

    expect($matrices($full))->toBe([MatrixKind::Full, MatrixKind::Full])
        ->and($matrices($first))->toBe([MatrixKind::FirstKiller, MatrixKind::FirstKiller]);
});

it('runs the tests in the runner\'s own order where tests.order says so', function () use ($orderings): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Tests::inRunnerOrder()), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    $own = Ordering::of(TestOrder::Runner, KillHistory::none());

    expect($orderings($runner))->toEqual([$own, $own]);
});

it('orders a shard handed no kill history as though nothing was killed yet, and warns of nothing', function () use (
    $resultIn,
    $orderings,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    unlink(sprintf('%s/.mutation-gate/coverage/shard-1/killers.json', $project));
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    $cold = Ordering::of(TestOrder::KillersFirst, KillHistory::none());

    expect($orderings($runner))->toEqual([$cold, $cold])
        ->and($result instanceof ShardResult ? $result->warnings() : $result)->toEqual(Warnings::none())
        ->and($result instanceof ShardResult ? $result->outcome() : $result)->toBeInstanceOf(MutationResult::class);
});

it('judges a shard whose kill history cannot be read without it, and warns of it', function () use (
    $resultIn,
    $statuses,
    $orderings,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', '{"format": 1, "tests": 3}');
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $warnings = $result instanceof ShardResult ? [...$result->warnings()] : [];

    $cold = Ordering::of(TestOrder::KillersFirst, KillHistory::none());

    expect($orderings($runner))->toEqual([$cold, $cold])
        ->and($statuses($result))->not->toBeEmpty()
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? null)->toBeInstanceOf(Warning::class)
        ->and(($warnings[0] ?? null)?->text())
        ->toStartWith('Shard 1 ran its tests without the kill history the plan handed it. A kill history cannot be read: ');
});

it('keeps what the runner warned of in each invocation, before the shard\'s own warnings', function () use ($resultIn): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', '{"format": 1, "tests": 3}');
    $warned = static fn(string $text): MutationResult => MutationResult::of(Mutants::none(), 0)
        ->withWarnings(Warnings::of(Warning::that($text)));
    $runner = ScriptedRunner::fixture()->answeringInTurn($warned('held'), $warned('rest'));

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $texts = array_map(
        static fn(Warning $warning): string => $warning->text(),
        $result instanceof ShardResult ? [...$result->warnings()] : [],
    );

    expect(array_slice($texts, 0, 2))->toBe(['held', 'rest'])
        ->and($texts)->toHaveCount(3)
        ->and($texts[2] ?? '')->toStartWith('Shard 1 ran its tests without the kill history');
});

it('runs every batch that fits a budget with the time left, leaving nothing unjudged', function () use ($resultIn, $unjudged): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
    );

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('2s')), $setup)
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(array_map(
        static fn(MutationRequest $request): array => [
            array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
            $request->deadline(),
        ],
        $runner->requests(),
    ))->toEqual([[['src/Money.php'], Seconds::of(2.0)], [['src/Held.php'], Seconds::of(2.0)]])
        ->and($unjudged($resultIn($project, 1)))->toBe([]);
});

it('starts nothing a budget has no room for, and leaves every unit unjudged', function () use ($resultIn, $unjudged, $statuses): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
    );

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('1s')), $setup)
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($runner->requests())->toBe([])
        ->and($unjudged($result))->toBe(['src/Money.php', 'src/Held.php'])
        ->and($statuses($result))->toBe([]);
});

it('stops before the batch after the one an interruption arrived in, leaving the units no batch took unjudged', function () use (
    $resultIn,
    $unjudged,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
    );
    $looks = 0;
    $arrived = Interruption::when(static function () use (&$looks): bool {
        ++$looks;

        return $looks > 1;
    });

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('2s')), $setup)
        ->interrupted($arrived)
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(array_map(
        static fn(MutationRequest $request): array => array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
        $runner->requests(),
    ))->toEqual([['src/Money.php']])
        ->and($unjudged($resultIn($project, 1)))->toBe(['src/Held.php']);
});

it('leaves the steps its time went to in its result, the runner\'s own and those around it, each from when the shard began', function () use (
    $ticking,
    $resultIn,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());

    new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), $ticking())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $steps = $result instanceof ShardResult ? array_map(
        static fn(StepTime $step): array => [$step->step(), $step->count()],
        [...$result->measured()->steps()],
    ) : $result;
    $since = $result instanceof ShardResult ? array_map(
        static fn(StepTime $step): float => $step->since()->seconds(),
        [...$result->measured()->steps()],
    ) : [];
    $ordered = $since;
    sort($ordered);

    expect($steps)->toBe([
        [Step::HeldCoverage, 1],
        [Step::Mutation, 1],
        [Step::Equivalence, 1],
        [Step::Survivors, 1],
        [Step::Mutation, 4],
        [Step::Equivalence, 1],
        [Step::Survivors, 1],
        [Step::StaticCheck, 2],
    ])
        ->and($since)->toBe($ordered);
});

it('leaves the units no batch took unjudged, and each survivor it had no time to confirm', function () use (
    $tickingBy,
    $resultIn,
    $unjudged,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('45s')), $tickingBy(10))
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $survivor = array_values(array_filter(
        $outcome instanceof MutationResult ? [...$outcome->mutants()] : [],
        static fn(Mutant $mutant): bool => $mutant->nativeId() === 'GreaterThan-16',
    ));

    expect(count($runner->requests()))->toBe(1)
        ->and($runner->requests()[0]->deadline())->toEqual(Seconds::of(5.0))
        ->and($runner->retries())->toBe([])
        ->and($unjudged($result))->toBe(['src/Held.php'])
        ->and($survivor[0]->status())->toBe(MutantStatus::Unjudged)
        ->and($survivor[0]->reason())->toEqual(OutOfTime::BeforeConfirming->reason());
});

it('leaves a batch unjudged whose runner stopped at the deadline, and cannot judge one that failed before it', function (
    int $step,
    array $left,
    bool $judged,
) use ($tickingBy, $resultIn, $unjudged): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->refusing('Pest was stopped at its deadline before it had made its mutants.');

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('25s')), $tickingBy($step))
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($runner->requests())->toHaveCount(1)
        ->and($unjudged($result))->toBe($left)
        ->and($result instanceof ShardResult && $result->outcome() instanceof MutationResult)->toBe($judged);
})->with([
    'stopped at the deadline' => [5, ['src/Money.php', 'src/Held.php'], true],
    'failed before it' => [1, [], false],
]);
