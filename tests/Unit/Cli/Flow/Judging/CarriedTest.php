<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason as MutantReason;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unraised;
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
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\CountedChanges;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

$makeTree = static fn(): Closure => JudgingRuns::tree(...);
$makeReporting = static fn(): Closure => JudgingRuns::reporting(...);
$makeJudged = static fn(): Closure => JudgingRuns::judged(...);

it('carries a kill from another commit where nothing changed since reaches its unit or its test by name', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $commit = Revision::ref(str_repeat('c1', 20));
    [$project, $checkout] = JudgingRuns::across('src/Tax.php', $commit);
    $store = JudgingRuns::provenAcross($commit, $commit);

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters($project, [], $store, $tree(Floor::of(0)), $checkout),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($trees[0]->counts()->number(MutantJudgement::Killed))->toBe(2)
        ->and($trees[0]->counts()->number(MutantJudgement::Unjudged))->toBe(0);
});

it('leaves a kill from another commit unjudged where what changed since reaches its unit by a name it uses', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $commit = Revision::ref(str_repeat('c1', 20));
    [$project, $checkout] = JudgingRuns::across('src/Equals.php', $commit);

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters($project, [], JudgingRuns::provenAcross($commit, $commit), $tree(Floor::of(0)), $checkout),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            "The time budget left 1 of the mutants of src/Money.php unjudged, so this run cannot pass it.\n"
            . 'More time judges them: vendor/bin/mutation-gate run --budget=<duration>',
        ]);
});

it('carries no kill from a commit git cannot read, and warns why', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $commit = Revision::ref(str_repeat('c1', 20));
    [$project, $checkout] = JudgingRuns::across('src/Tax.php', Revision::ref(str_repeat('c2', 20)));

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters($project, [], JudgingRuns::provenAcross($commit, $commit), $tree(Floor::of(0)), $checkout),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $warnings = array_map(static fn(Warning $warning): string => $warning->text(), [...$verdict->warnings()]);

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and($warnings)->toContain(sprintf(
            'No kill proved at %1$s carries for a unit the budget never started. %1$s is not a revision this repository has.',
            str_repeat('c1', 20),
        ));
});

it('reads what changed since each commit once, however many results share it', function (Revision $money, Revision $held, array $asked) use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    [$project, $checkout] = JudgingRuns::across('src/Tax.php', $money, $held);
    $counted = new CountedChanges($checkout);

    $judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters($project, [], JudgingRuns::provenAcross($money, $held), $tree(Floor::of(0)), $counted),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    );

    expect($counted->askedFrom())->toBe($asked);
})->with([
    'one commit' => [fn(): Revision => Revision::ref(str_repeat('c1', 20)), fn(): Revision => Revision::ref(str_repeat('c1', 20)), fn(): array => [str_repeat('c1', 20)]],
    'two commits' => [fn(): Revision => Revision::ref(str_repeat('c1', 20)), fn(): Revision => Revision::ref(str_repeat('c2', 20)), fn(): array => [str_repeat('c1', 20), str_repeat('c2', 20)]],
]);

it('never passes new code in a unit the budget never started, whose newest result is of the code before', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = JudgingRuns::proven('money before', 'money test');
    $plan = JudgingRuns::digested('money now', 'money test')
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(Considered::everything()->reaching(
            Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(20), Line::of(21)))),
            Reasons::of(Reason::that('src/Money.php changed.')),
        ));

    $verdict = JudgingRuns::verdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'Its newest result is of other source, so its mutants are not this code\'s. '
            . 'More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        ])
        ->and($verdict->wasCutShort())->toBeTrue()
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->passed())->toBeInstanceOf(CannotTell::class);
});

it('never records a pass for a run whose budget left a mutant unjudged', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = JudgingRuns::proven('money', 'money test before');

    $judgement = $judged(
        JudgingRuns::digested('money', 'money test now'),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    );
    $verdict = JudgingRuns::verdictOf($judgement);
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            "The time budget left 1 of the mutants of src/Money.php unjudged, so this run cannot pass it.\n"
            . 'More time judges them: vendor/bin/mutation-gate run --budget=<duration>',
        ])
        ->and($trees[0]->counts()->number(MutantJudgement::Unjudged))->toBe(1)
        ->and($trees[0]->raised())->toEqual(Unraised::floor())
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toBeInstanceOf(CannotTell::class)
        ->and(count(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()))->toBe(2);
});

it('passes a run whose budget left units whose newest results all stand, and records it', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = JudgingRuns::proven('money', 'money test');

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($verdict->wasCutShort())->toBeTrue()
        ->and($trees[0]->counts()->number(MutantJudgement::Killed))->toBe(1)
        ->and($trees[0]->counts()->number(MutantJudgement::Survived))->toBe(1)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toBeInstanceOf(Passed::class);
});

it('fails on an ignore that names nothing where every unit the budget left has a result that stands', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters(Flows::project(), [], JudgingRuns::proven('money', 'money test'), $tree(Floor::of(0))),
        JudgingRuns::settings(Budget::of('1s'), Ignore::mutator('MethodCallRemoval', 'src/Log/**', 'Logged elsewhere')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->wasCutShort())->toBeTrue()
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            'The ignore of MethodCallRemoval in src/Log/** names no mutant it could leave out, in a run that judged every unit: remove it.',
        ]);
});

it('fails on no ignore that names nothing where the budget left a unit with no result that stands', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters(Flows::project(), [], new ProofStoreFake(), $tree(Floor::of(0))),
        JudgingRuns::settings(Budget::of('1s'), Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same')),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->failures()))->toBe([
        "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n"
        . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        "src/Held.php is unjudged: the time budget ran out before this run mutated it.\n"
        . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
    ]);
});

it('fails on no ignore that names nothing where a held unit did not run', function () use ($makeTree, $makeReporting): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $plan = Planned::oneShard();
    $settings = JudgingRuns::settings(Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same'));
    $adapters = Flows::adapters(
        $project,
        [],
        $tree(Floor::of(0)),
        new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::empty()),
    );
    new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
    new Running($adapters, $settings, Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, $settings, Flows::setup(), $reporting(new ReporterFake()));

    $verdict = JudgingRuns::verdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(JudgingRuns::texts($verdict->failures()))->toBe([<<<'SAID'
        holds:src/Held.php does not cover src/Held.php, so its mutants cannot be judged by it.
        Not reached: src/Held.php, all of it
        Add the test that runs them to the group.
        SAID]);
});

it('counts no unit the budget never started by a result of an earlier ledger format, nor one no ledger holds', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('money before'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of(Planned::BASE)),
    )));

    $verdict = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test'),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'Its newest result records no digests of its inputs to say it is this code\'s. '
            . 'More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
            "src/Held.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        ])
        ->and([...$verdict->trees()][0]->mutants())->toHaveCount(0)
        ->and(count(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()))->toBe(1);
});

it('records no pass for a verdict that passed with a mutant the runner left unjudged', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $store = new ProofStoreFake();
    $unjudged = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', 'unjudged', 0),
        'Plus-9',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::Unjudged,
        Seconds::of(0.1),
    )->because(MutantReason::that('No test could be named.'));

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($unjudged), 0)),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toBeInstanceOf(CannotTell::class);
});

it('keeps the last commit that passed where a budget left a kill unjudged, so the next run from it judges the unit again', function () use (
    $makeTree,
    $makeReporting,
    $makeJudged,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $passed = Revision::ref(str_repeat('a1', 20));
    $store = JudgingRuns::proven('money', 'money test before');
    $store->write(Scope::branch('main'), LedgerRead::ledger($store->read(Scope::branch('main')))->withRuns(ScopeRuns::none()->passing(Passed::of($passed, 'mutation-gate', 0))));
    $checkout = new ChangeSourceFake(
        $passed,
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, $passed->name() => Flows::FILES],
    )->unchangedSince(Revision::ref(Flows::HEAD));
    $adapters = Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0)), $checkout);

    $first = JudgingRuns::verdictOf($judged(
        JudgingRuns::digested('money', 'money test now'),
        $adapters,
        JudgingRuns::settings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $next = Planned::from(new Planning($adapters, Flows::settings(), Flows::setup())
        ->plan(
            Mode::since(Mode::LAST_PASSED),
            CoverageRun::of(WholeSuite::tests(), Workspace::coverage()),
            Cut::exactly(1),
            MatrixKind::FirstKiller,
        ));
    $planned = [];

    foreach ($next instanceof Plan ? $next : [] as $shard) {
        foreach ($shard->units() as $unit) {
            $planned[] = $unit->path()->value();
        }
    }

    $lastPassed = LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed();

    expect($first->judgement())->toBe(Judgement::Failed)
        ->and($lastPassed instanceof Passed ? $lastPassed->commit() : $lastPassed)->toEqual($passed)
        ->and($planned)->toBe(['src/Money.php']);
});

it('finds the weak tests that let a survivor through from the test files the plan names, before it reports', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    Scratch::write($project, 'tests/MoneyTest.php', "<?php\n\nit('adds', function () {\n    expect(fits(1, 2))->toBeBool();\n});\n");
    $literal = Verdicts::mutant('src/Money.php:11', 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'));
    $recorded = new ReporterFake();
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))),
        Flows::adapters($project, [], $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($literal), 0)),
        JudgingRuns::settings(),
        $reporting($recorded),
    ));
    $survivors = array_values(array_filter([...$verdict->trees()->mutants()], static fn(JudgedMutant|JudgedKill $judged): bool => $judged instanceof JudgedMutant));
    $finding = $survivors[0]->finding();

    expect($finding)->toBeInstanceOf(WeaklyAsserted::class)
        ->and($finding instanceof WeaklyAsserted ? $finding->first()->test()->value() : '')->toBe('MoneyTest::adds')
        ->and($finding instanceof WeaklyAsserted ? $finding->function() : '')->toBe('fits')
        ->and($survivors[0]->hint()->text())->toContain('`tests/MoneyTest.php::it adds` asserts only `->toBeBool()`')
        ->and($recorded->reported)->toBe([$verdict]);
});

it('reads the helpers the files that define the runner declare, and names no test that calls one weak', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    Scratch::write($project, 'tests/Pest.php', implode("\n", ['<?php', 'function fitsExactly(bool $fits): void { expect($fits)->toBe(false); }', '']));
    Scratch::write($project, 'tests/MoneyTest.php', "<?php\n\nit('adds', function () {\n    expect(fits(1, 2))->toBeBool();\n    fitsExactly(fits(1, 2));\n});\n");
    $literal = Verdicts::mutant('src/Money.php:11', 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))),
        Flows::adapters($project, [], $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($literal), 0)),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $survivors = array_values(array_filter([...$verdict->trees()->mutants()], static fn(JudgedMutant|JudgedKill $judged): bool => $judged instanceof JudgedMutant));

    expect($survivors[0]->finding())->toEqual(NoFinding::survivor());
});

it('suggests deleting the callee of a surviving removal whose body the tests leave unchecked, before it reports', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $project = Flows::project();
    $lines = array_fill(0, 30, '');
    $lines[0] = '<?php';
    $lines[4] = 'final class Money';
    $lines[5] = '{';
    $lines[8] = '    public function add(int $amount): void';
    $lines[9] = '    {';
    $lines[10] = '        $this->record($amount);';
    $lines[11] = '    }';
    $lines[13] = '    private function record(int $amount): void';
    $lines[14] = '    {';
    $lines[26] = '        $this->total += $amount;';
    $lines[27] = '    }';
    $lines[28] = '}';
    Scratch::write($project, 'src/Money.php', implode("\n", $lines));
    Scratch::write($project, 'tests/MoneyTest.php', implode("\n", ['<?php', "it('adds', function () { expect(total())->toBe(3); });", '']));
    $removal = Verdicts::mutant('src/Money.php:11', 'RemoveMethodCall', MutatorFamily::RemovedCall, Verdicts::diff('$this->record($amount);', ''));
    $body = Verdicts::mutant('src/Money.php:27', 'PlusEqualToMinusEqual', MutatorFamily::Arithmetic, Verdicts::diff('$this->total += $amount;', '$this->total -= $amount;'));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))),
        Flows::adapters($project, [], $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($removal, $body), 0)),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $found = [];

    foreach ($verdict->trees()->mutants() as $mutant) {
        $finding = $mutant instanceof JudgedMutant ? $mutant->finding() : NoFinding::survivor();
        $found[$mutant->mutant()->location()->start()->number()] = [$finding instanceof Removable ? $finding->name() : '', $mutant->hint()->text()];
    }

    expect($found[11][0])->toBe('record')
        ->and($found[11][1])->toContain('if nothing outside the tests needs `record()`, it can be deleted.')
        ->and($found[27][0])->toBe('');
});
