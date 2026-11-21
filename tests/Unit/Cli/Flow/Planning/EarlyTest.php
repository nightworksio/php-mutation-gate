<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\KeptMaps;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Coverage;
use NightWorksIO\MutationGate\Config\EarlyStreams;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Config\SplitStream;
use NightWorksIO\MutationGate\Config\StreamShards;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HoldingSuites;
use NightWorksIO\MutationGate\Tests\Support\MisbehavingRunner;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** The settings of a full split-stream run that always starts early. */
$early = static fn(): Settings => Flows::settings(ConfiguredRunner::uses('pest'), Pest::patched(), SplitStream::on(), StreamShards::byTests(), EarlyStreams::always());

/**
 * The adapters of the holding-suites project, whose Process test holds
 * src/Held.php, the default branch keeping this map, read as it is, and
 * every coverage run measuring the Process test on a line of src/Held.php.
 */
$keeping = static function (CoverageMap $kept): Adapters {
    $project = HoldingSuites::project();
    $store = new ProofStoreFake();
    $fresh = CoverageMap::of(
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS),
        CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS),
    );
    $starts = TestIds::of(TestId::of(HoldingSuites::STARTS));
    $process = TestListing::of($starts)->grouping(Group::named('holds:src/Held.php'), $starts);
    $runner = new MisbehavingRunner(
        HoldingSuites::runner(TestListing::none(), $process, $fresh),
        coverage: static fn(CoverageRun|CoverageRead|CoverageRan $asked): CoverageMap => $asked instanceof CoverageRead ? $kept : $fresh,
    );
    $adapters = HoldingSuites::adapters($project, HoldingSuites::checkout(), $runner, $store);
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz', CoverageMapFile::encode($kept, MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false)));
    new KeptMaps($adapters, Flows::settings())->keep(Access::of(Scope::branch('main'), Scope::branch('main'), Writing::Auto));
    unlink(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project));

    return $adapters;
};

/** A map kept before the Process test held src/Held.php: it runs no line of it. */
$older = static fn(): CoverageMap => CoverageMap::of(
    CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS, HoldingSuites::STARTS),
);

it('plans nothing for a run that waits for a fresh map, where it cannot start early, saying so', function (): void {
    $planned = new Planning(Flows::adapters(Flows::project()), Flows::settings(), Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller, earlyOnly: true);
    $plan = Planned::from($planned);

    expect($plan instanceof Plan ? [$plan->briefing()->waits(), count($plan->shards())] : $plan)->toBe([true, 0]);
});

it('plans a full split-stream run early against the map the default branch keeps, where it starts early', function (): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz', CoverageMapFile::encode(Flows::map(), MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false)));
    new KeptMaps(Flows::adapters($project, [], $store), Flows::settings())->keep(Access::of(Scope::branch('main'), Scope::branch('main'), Writing::Auto));
    unlink(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project));
    $settings = Flows::settings(ConfiguredRunner::uses('pest'), Pest::patched(), SplitStream::on(), StreamShards::byTests(), EarlyStreams::always());

    $planned = new Planning(Flows::adapters($project, [], $store), $settings, Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller);
    $plan = Planned::from($planned);

    expect($plan instanceof Plan ? $plan->briefing()->isEarly() : $plan)->toBeTrue();
});

it('cannot plan a quick round whose saved test files\' tests the runner cannot tell', function (): void {
    $runner = new MisbehavingRunner(
        new CoverageAsked(ScriptedRunner::fixture(), Flows::map()),
        testsIn: static fn(): CannotJudge => CannotJudge::because('The runner cannot list them.'),
    );
    $round = QuickRound::after(Changes::of(Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(3)))));

    $planned = new Planning(Flows::adapters(Flows::project(), [], $runner), Flows::settings(Coverage::incremental()), Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller, quick: $round);

    expect($planned)->toEqual(CannotJudge::because('The runner cannot list them.'));
});

it('plans nothing for a run asked to plan early alone where the kept map runs no line a hold holds, saying why', function () use ($early, $keeping, $older): void {
    $planned = new Planning($keeping($older()), $early(), Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller, earlyOnly: true);
    $plan = Planned::from($planned);

    expect($plan instanceof Plan ? [$plan->briefing()->waits(), count($plan->shards())] : $plan)->toBe([true, 0])
        ->and($planned instanceof PlanMade ? $planned->coverage() : $planned)->toBe(<<<'SAID'
            The map the default branch keeps cannot plan the run early:
            holds:src/Held.php in the holding suites runs no line of src/Held.php, so it judges none of its mutants.
            Add the test that runs it to the group, or remove the hold.
            The plan was asked to plan early alone, so it plans nothing:
            the caller that hands a fresh map runs it (ADR-0320).
            SAID);
});

it('measures a fresh map for a run whose kept map runs no line a hold holds, and plans by it', function () use ($early, $keeping, $older): void {
    $planned = new Planning($keeping($older()), $early(), Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller);
    $plan = Planned::from($planned);

    expect($plan instanceof Plan ? [$plan->briefing()->isEarly(), $plan->briefing()->waits(), count($plan->shards())] : $plan)
        ->toBe([false, false, 1]);
});

it('plans early against a kept map whose every hold runs a line of what it holds', function () use ($early, $keeping): void {
    $kept = CoverageMap::of(
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS),
        CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS),
    );

    $planned = new Planning($keeping($kept), $early(), Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller, earlyOnly: true);
    $plan = Planned::from($planned);

    expect($plan instanceof Plan ? [$plan->briefing()->isEarly(), $plan->briefing()->waits()] : $plan)->toBe([true, false]);
});
