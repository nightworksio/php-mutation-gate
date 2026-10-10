<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Config\Uncovered;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

$makeTree = static fn(): Closure => JudgingRuns::tree(...);
$makeReporting = static fn(): Closure => JudgingRuns::reporting(...);
$makeJudged = static fn(): Closure => JudgingRuns::judged(...);

it('kills a timeout only where triage confirms it, so a tree at 100 fails on one it cannot', function (
    CoverageMap $map,
    Setting $timeouts,
    Judgement $judgement,
) use ($makeTree, $makeReporting): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $timedOut = array_values(array_filter(
        [...RunnerFake::ofTheFixture()
            ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()))
            ->mutants()],
        static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::TimedOut,
    ));
    $plan = Planned::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'),
    );
    $adapters = Flows::adapters(
        $project,
        [],
        $tree(Floor::of(100)),
        ScriptedRunner::fixture()->answering(Mutants::of(...$timedOut), 0),
    );
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, $map, KillHistory::none(), Unplaced::map());
    new Running($adapters, JudgingRuns::settings($timeouts), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $judging = new Judging($adapters, JudgingRuns::settings($timeouts), Flows::setup(), $reporting(new ReporterFake()));
    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(count($timedOut))->toBe(1)
        ->and($verdict->judgement())->toBe($judgement);
})->with([
    'tests that take under half its limit' => [fn(): CoverageMap => Flows::map(), fn(): Timeouts => Timeouts::confirmed(), Judgement::Passed],
    'tests whose time the map does not hold' => [fn(): CoverageMap => CoverageMap::empty(), fn(): Timeouts => Timeouts::confirmed(), Judgement::Failed],
    'timeouts.mode unjudged' => [fn(): CoverageMap => Flows::map(), fn(): Timeouts => Timeouts::unjudged(), Judgement::Failed],
]);

it('says how many of the runner\'s own ignore markers ignores.native allows in what the run mutated', function (
    Setting $native,
    Markers|CannotJudge $markers,
    array $said,
) use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), ScriptedRunner::fixture()->marking($markers)),
        JudgingRuns::settings($native),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->warnings()))->toBe($said);
})->with([
    'allowed' => [
        fn(): Ignores => Ignores::allowingNativeMarkers(),
        fn(): Markers => Markers::of(
            Marker::inSource(Path::of('src/Money.php'), Line::of(9), 'a', Nameless::code()),
            Marker::inSource(Path::of('src/Held.php'), Line::of(4), 'b', Nameless::code()),
        ),
        [<<<'SAID'
            2 of the runner's own ignore markers hide mutants in the files this run mutated,
            as ignores.native: allow lets them. The gate cannot count the mutants they hide.
            Move them into ignores.entries.
            SAID],
    ],
    'allowed, and none found' => [fn(): Ignores => Ignores::allowingNativeMarkers(), fn(): Markers => Markers::none(), []],
    'allowed, and not counted' => [
        fn(): Ignores => Ignores::allowingNativeMarkers(),
        fn(): CannotJudge => CannotJudge::because('infection.json5 cannot be read.'),
        ['The runner\'s own ignore markers could not be counted. infection.json5 cannot be read.'],
    ],
    'refused, where the plan already stopped for any' => [
        fn(): Ignores => Ignores::refusingNativeMarkers(),
        fn(): Markers => Markers::of(Marker::inSource(Path::of('src/Money.php'), Line::of(9), 'a', Nameless::code())),
        [],
    ],
]);

it('warns of what a shard warned of, and judges as it would without it', function () use (
    $makeTree,
    $makeReporting,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $plan = Planned::oneShard();
    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)));
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', 'garbled');
    new Running($adapters, JudgingRuns::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);
    $said = JudgingRuns::texts($verdict->warnings());

    expect($said)->toHaveCount(1)
        ->and($said[0] ?? '')->toStartWith('Shard 1 ran its tests without the kill history the plan handed it.')
        ->and($verdict->failures())->toHaveCount(0)
        ->and($verdict->judgement())->toBe(Judgement::Passed);
});

it('kills a survivor static analysis rejects, and warns once for each reason it left the shards\' survivors unchecked', function () use (
    $makeTree,
    $makeReporting,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $plan = Planned::twoShards();
    $identity = AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
    $survivor = Mutants::none();

    foreach (Flows::mutantsOf('src/Money.php') as $mutant) {
        $survivor = $mutant->status() === MutantStatus::Survived ? $survivor->with($mutant) : $survivor;
    }

    $answers = new StaticCheckerFake($identity, Findings::none(), array_merge(...array_map(
        static fn(Mutant $mutant): array => [
            Workspace::checkedMutant($mutant->id())->value() => Findings::of(Finding::error(Path::of('src/Money.php'), 'new', 'New.')),
        ],
        [...$survivor],
    )));
    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)), new RecordingChecker($answers, $project));
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
    new Running($adapters, JudgingRuns::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting(new ReporterFake()));
    $statuses = [];

    foreach ($results instanceof Results ? $results->shards() : [] as [, , $mutated]) {
        foreach ($mutated->mutants() as $mutant) {
            $statuses[] = $mutant->status()->value;
        }
    }

    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(count($survivor))->toBe(1)
        ->and($statuses)->toContain('killed-by-static-analysis')
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe([
            'Static analysis left 1 survivor unchecked, as the analyser could not check them: src/Held.php. '
            . '.mutation-gate/staticcheck/mutants/8705b7dc7d27.php is no mutant the fake was told about.',
        ]);
});

it('names the tests as the plan names them, and warns once where it names none', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $names = TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'));
    $adapters = static fn(): Adapters => Flows::adapters(Flows::project(), [], $tree(Floor::of(0)));
    $named = JudgingRuns::verdictOf($judged(
        Planned::twoShards()->naming($names),
        $adapters(),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $unnamed = JudgingRuns::verdictOf($judged(
        Planned::twoShards()->naming(CannotJudge::because('Pest cannot list its tests.')),
        $adapters(),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect($named->matrix()->names())->toBe($names)
        ->and(JudgingRuns::texts($named->warnings()))->toBe([])
        ->and($unnamed->matrix()->names())->toEqual(TestNames::none())
        ->and(JudgingRuns::texts($unnamed->warnings()))
        ->toBe(['The reports name each test by its coverage id. Pest cannot list its tests.']);
});

it('builds the kill matrix of first killers over the map the plan handed it, as its runner can', function (
    ScriptedRunner $runner,
    NotFull $whyNotFull,
) use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), $runner),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $covered = array_map(
        static fn(JudgedMutant|JudgedKill $judged): array => [
            $judged->mutant()->location()->start()->number(),
            array_map(
                static fn(TestId $test): string => $test->value(),
                [...$verdict->matrix()->coveredBy($judged)],
            ),
        ],
        [...$verdict->trees()->mutants()],
    );

    expect($verdict->matrix()->kind())->toBe(MatrixKind::FirstKiller)
        ->and($verdict->matrix()->whyNotFull())->toBe($whyNotFull)
        ->and($verdict->matrix()->coverage()->files())
        ->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')))
        ->and($covered)->toContain([11, ['MoneyTest::adds']])
        ->and($verdict->matrix()->secondsOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(0.2))
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe([]);
})->with([
    'a runner that can record every killer' => [fn(): ScriptedRunner => ScriptedRunner::fixture(), NotFull::FirstKillers],
    'one that stops at the first' => [
        fn(): ScriptedRunner => ScriptedRunner::fixture()->behaving(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)),
        NotFull::Infection,
    ],
]);

it('builds a full kill matrix where the plan records one, and writes proofs whose runs recorded every killer', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = new ProofStoreFake();
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards()->briefed(Briefing::standard()->recording(MatrixKind::Full)),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $tree(Floor::of(0)), $store),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $matrices = array_map(
        static fn(Proof $proof): MatrixKind => $proof->run()->matrix(),
        [...LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()],
    );

    expect($verdict->matrix()->kind())->toBe(MatrixKind::Full)
        ->and($matrices)->toBe([MatrixKind::Full, MatrixKind::Full]);
});

it('holds each mutant\'s killers alone, and warns, where the plan handed the verdict no map', function () use (
    $makeTree,
    $makeReporting,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $plan = Planned::oneShard();
    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)));
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
    unlink(sprintf('%s/.mutation-gate/coverage/verdict/map.json.gz', $project));
    new Running($adapters, JudgingRuns::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect($verdict->matrix()->coverage())->toEqual(CoverageMap::empty())
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe([
            'The kill matrix holds each mutant\'s killers alone. The verdict was handed no coverage map at '
            . '.mutation-gate/coverage/verdict/map.json.gz. Hand it the plan\'s .mutation-gate/coverage.',
        ])
        ->and($verdict->judgement())->toBe(Judgement::Passed);
});

it('warns of each file most of the suite runs through that nothing holds, past holds.hotPath', function () use (
    $makeTree,
    $makeReporting,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $plan = Planned::oneShard();
    $map = Flows::map();

    foreach (range(1, 20) as $each) {
        $test = TestId::of(sprintf('SuiteTest::case%d', $each));
        $map = $map->covered(Path::of('src/Money.php'), Line::of(11), $test)
            ->covered(Path::of('src/Held.php'), Line::of(11), $test);
    }

    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)));
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, $map, KillHistory::none(), Unplaced::map());
    new Running($adapters, JudgingRuns::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(JudgingRuns::texts($verdict->warnings()))->toBe([
        '`src/Money.php` is run by 21 of 22 tests and nothing holds it; each of its mutants runs most of the suite.',
    ]);
});

it('fails a verdict on a held unit its holding tests miss lines of, and proves nothing of it', function (
    Setting $uncovered,
) use ($makeTree, $makeReporting): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::oneShard();
    $adapters = Flows::adapters(
        $project,
        [],
        $tree(Floor::of(0)),
        $store,
        new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::empty()),
    );
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
    new Running($adapters, JudgingRuns::settings($uncovered), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, JudgingRuns::settings($uncovered), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(JudgingRuns::texts($verdict->failures()))->toBe([<<<'SAID'
        holds:src/Held.php does not cover src/Held.php, so its mutants cannot be judged by it.
        Not reached: src/Held.php, all of it
        Add the test that runs them to the group.
        SAID])
        ->and($verdict->judgement())->toBe(Judgement::Failed)
        ->and(array_map(
            static fn(Proof $proof): string => $proof->unit()->value(),
            [...LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()],
        ))->toBe(['src/Money.php']);
})->with([
    'uncovered mutants counted' => [fn(): Uncovered => Uncovered::counted()],
    'uncovered mutants left out' => [fn(): Uncovered => Uncovered::excluded()],
]);

it('judges flaky what a fresh result and a proof under its key disagree on, and keeps neither', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $earlier = Run::of('github:0/1', Moment::at('2026-09-28T12:00:00Z'), $plan->base());
    $store->write(Scope::branch('main'), Ledger::empty()->atBase($plan->base())->withProof(
        Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), Mutants::none(), $earlier),
    ));

    $verdict = JudgingRuns::verdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), $store),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $money = array_values(array_filter(
        [...$verdict->trees()->mutants()],
        static fn(JudgedMutant|JudgedKill $mutant): bool => $mutant->mutant()->location()->file()->value() === 'src/Money.php',
    ));
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));

    expect(array_unique(array_map(static fn(JudgedMutant|JudgedKill $mutant): string => $mutant->judgement()->value, $money)))
        ->toBe(['flaky'])
        ->and($ledger->proofs()->has(Digest::sha256Of('money')))->toBeFalse()
        ->and($ledger->proofs()->has(Digest::sha256Of('held')))->toBeTrue();
});

it('clusters the survivors of one cause from the project\'s source before it reports', function () use ($makeTree, $makeReporting): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $comparison = static fn(string $mutator, string $added): Mutant => Verdicts::mutant(
        'src/Money.php:7',
        $mutator,
        MutatorFamily::Boundary,
        Verdicts::diff('if ($amount < $limit) {', $added),
    );
    $plan = Planned::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'),
    );
    $adapters = Flows::adapters(
        $project,
        [],
        $tree(Floor::of(0)),
        ScriptedRunner::fixture()->answering(Mutants::of(
            $comparison('LessThan', 'if ($amount <= $limit) {'),
            $comparison('LessThanNegotiation', 'if ($amount > $limit) {'),
        ), 0),
    );
    $recorded = new ReporterFake();
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
    new Running($adapters, JudgingRuns::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting($recorded));
    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);
    $clusters = iterator_to_array($verdict->trees()->clusters(), preserve_keys: false);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]->kind())->toBe(ClusterKind::Expression)
        ->and($clusters[0]->members())->toHaveCount(2)
        ->and($recorded->reported)->toBe([$verdict]);
});
