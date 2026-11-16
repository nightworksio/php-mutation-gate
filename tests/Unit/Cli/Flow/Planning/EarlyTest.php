<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\KeptMaps;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Coverage;
use NightWorksIO\MutationGate\Config\EarlyStreams;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Config\SplitStream;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
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
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\MisbehavingRunner;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

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
    $settings = Flows::settings(ConfiguredRunner::uses('pest'), Pest::patched(), SplitStream::on(), SplitStream::shardingByTests(), EarlyStreams::always());

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
