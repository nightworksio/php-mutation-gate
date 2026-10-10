<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Baseline as BaselineSetting;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Floor as NewCodeFloor;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$tree = JudgingRuns::tree(...);
$reporting = JudgingRuns::reporting(...);
$judged = JudgingRuns::judged(...);

it('proves a survivor equivalent where it compiles to its original program, and leaves the others survivors', function (
    Setting $equivalence,
    string $judgement,
) use ($tree, $reporting, $judged): void {
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), JudgingRuns::moneyLaidOut()),
        JudgingRuns::settings($equivalence),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::judgements($verdict))->toMatchArray(['GreaterThan-16' => $judgement, 'Plus-11' => 'survived'])
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe([]);
})->with([
    'by default' => [Equivalence::provenStatically(), 'equivalent'],
    'not where equivalence.static is false' => [Equivalence::notProvenStatically(), 'survived'],
]);

it('says no mutant was checked, and proves none, where opcache gives no opcodes', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $silent = new Prover(new Compiler(PHP_BINARY, sprintf('%s/.mutation-gate/equivalence', $project), 30.0, 1, ['opcache.opt_debug_level=0']));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $tree(Floor::of(0)), JudgingRuns::moneyLaidOut(), $silent),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::judgements($verdict))->toMatchArray(['GreaterThan-16' => 'survived'])
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe(['No mutant was checked for equivalence: opcache is not available.']);
});

it('keeps an ignore that leaves out only mutants proven equivalent, and says it can go', function (
    Ignore $ignore,
    array $judgements,
    array $said,
) use ($tree, $reporting, $judged): void {
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), JudgingRuns::moneyLaidOut()),
        JudgingRuns::settings($ignore),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::judgements($verdict))->toMatchArray($judgements)
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe($said)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([]);
})->with([
    'one that leaves out only proven ones' => [
        Ignore::mutator('GreaterThan', 'src/Money.php', 'The bound is never reached'),
        ['GreaterThan-16' => 'ignored', 'Plus-11' => 'survived'],
        ['The ignore of GreaterThan in src/Money.php leaves out only mutants proven equivalent: this ignore can go.'],
    ],
    'not one that leaves out a survivor no proof covers' => [
        Ignore::mutator('Plus', 'src/**', 'Both sums are the same'),
        ['GreaterThan-16' => 'equivalent', 'Plus-11' => 'ignored'],
        [],
    ],
]);

it('judges every tree whole, records the run, and reports it, timed by each shard\'s result', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $recorded = new ReporterFake();

    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $store, $tree(Floor::of(50))),
        JudgingRuns::settings(),
        $reporting($recorded),
    );
    $verdict = JudgingRuns::verdictOf($judgement);

    expect($judgement instanceof Judged ? $judgement->said : $judgement)
        ->toBe(['Wrote memory:refs/heads/main.', 'Wrote memory.'])
        ->and($recorded->reported)->toBe([$verdict])
        ->and($verdict->judgement())->toBe(Judgement::Failed)
        ->and(count($verdict->trees()->units()))->toBe(2)
        ->and(count($verdict->trees()->mutants()))->toBe(5)
        ->and(count($verdict->sets()->newCode()))->toBe(0)
        ->and(count($verdict->failures()))->toBe(0)
        ->and($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::Failed)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toBeInstanceOf(CannotTell::class)
        ->and($verdict->account()->timings() instanceof RunTimings ? count([...$verdict->account()->timings()->shards()]) : 0)
        ->toBe(2);
});

it('judges a plan\'s results again as the verdict did, reporting nothing and writing no ledger', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $adapters = Flows::adapters($project, [], $store, $tree(Floor::of(50)));
    $plan = Planned::twoShards();
    $first = JudgingRuns::verdictOf($judged($plan, $adapters, JudgingRuns::settings(), $reporting(new ReporterFake())));
    $written = $store->read(Scope::branch('main'));
    $recorded = new ReporterFake();
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $again = $results instanceof Results
        ? new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting($recorded))->again($plan, $results)
        : $results;

    expect($again instanceof Verdict ? [$again->judgement(), count($again->trees()->mutants())] : $again)
        ->toBe([$first->judgement(), count($first->trees()->mutants())])
        ->and($recorded->reported)->toBe([])
        ->and($store->read(Scope::branch('main')))->toEqual($written);
});

it('cannot judge again where the trees or the baseline cannot be read', function (string $broken) use ($tree, $reporting): void {
    $project = Flows::project();
    $trees = $broken === 'trees' ? new TreeSourceFake(CannotJudge::because('No trees.')) : $tree(Floor::of(50));

    if ($broken === 'baseline') {
        Scratch::write($project, 'mutation-gate.baseline.json', '{');
    }

    $adapters = Flows::adapters($project, [], new ProofStoreFake(), $trees);
    $again = new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting(new ReporterFake()))
        ->again(Planned::twoShards(), JudgingRuns::noResults($project));

    $baseline = BaselineFile::decode('{', Path::of('mutation-gate.baseline.json'));
    $unreadable = $baseline instanceof CannotJudge ? $baseline->why() : 'a baseline';

    expect($again instanceof CannotJudge ? $again->why() : $again)->toBe($broken === 'trees' ? 'No trees.' : $unreadable);
})->with(['trees', 'baseline']);

it('records a pass under the check the config names', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $store, $tree(Floor::of(40))),
        JudgingRuns::settings(Ci::check('gate / verdict')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'gate / verdict', 0)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW))));
});

it('records a pass measured against its own scope\'s coverage map as one that used its own scope, which the default branch never trusts', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();

    JudgingRuns::verdictOf($judged(
        $plan->briefed($plan->briefing()->onOwnScopeCoverage()),
        Flows::adapters($project, [], $store, $tree(Floor::of(40))),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));
    $passed = LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed();

    expect($passed)->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 0)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW)))->onOwnScopeCoverage())
        ->and($passed instanceof Passed && $passed->usedOwnScope())->toBeTrue();
});

it('says what a reporter could not write', function () use ($tree, $reporting, $judged): void {
    $unwritten = new class implements Reporter {
        public function report(Verdict $verdict): NotWritten
        {
            return NotWritten::because('The disk is full.');
        }
    };

    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(50))),
        JudgingRuns::settings(),
        $reporting($unwritten),
    );

    expect($judgement instanceof Judged ? $judgement->said : $judgement)
        ->toBe(['Wrote memory:refs/heads/main.', 'The disk is full.']);
});

it('says why the ledger was not written', function () use ($tree, $reporting, $judged): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(50))),
        JudgingRuns::settings(Proofs::readOnly()),
        $reporting(new ReporterFake()),
    );

    expect($judgement instanceof Judged ? $judgement->said[0] : $judgement)
        ->toBe('proofs.write is never, so the ledger of refs/heads/main is read and not written.');
});

it('cannot judge where the ledger cannot learn what a shard cost, and reports nothing', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $recorded = new ReporterFake();
    $adapters = Flows::adapters($project, [], $tree(Floor::of(50)));
    $plan = Planned::handedIn($project, Planned::twoShards());
    new Running($adapters, JudgingRuns::settings(), Flows::setup())->runAll($plan, Workspace::results());
    unlink(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::shardCoverage(ShardId::of(1)))->value()));
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $judgement = $results instanceof Results
        ? new Judging($adapters, JudgingRuns::settings(), Flows::setup(), $reporting($recorded))->verdict($plan, $results)
        : $results;

    expect($judgement)->toEqual(new Handoff(Directory::at($project), HandedMaps::limits())->read(ShardId::of(1)))
        ->and($judgement)->toBeInstanceOf(CannotJudge::class)
        ->and($recorded->reported)->toBe([]);
});

it('stops a CI run on a tree held to no floor once it reported and recorded what it measured', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $tree(Undeclared::floor()), $store),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    );
    $verdict = JudgingRuns::verdictOf($judgement);

    expect($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::CannotJudge)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            sprintf(
                "%s\n%s %s",
                'src has no floor: no floor is declared for it, and the baseline holds none.',
                'A tree is never held to no floor.',
                'Run mutation-gate baseline --write and commit mutation-gate.baseline.json.',
            ),
            sprintf(
                "The baseline this run measured, ready to commit as mutation-gate.baseline.json:\n%s",
                BaselineFile::encode(Baseline::of(Entry::of(Path::of('src'), Floor::of(40)))),
            ),
        ])
        ->and($verdict->warnings())->toHaveCount(0)
        ->and($judgement instanceof Judged ? $judgement->said : $judgement)->toHaveCount(2)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs())->toHaveCount(2);
});

it('warns of a tree held to no floor outside CI', function () use ($tree, $reporting, $judged): void {
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Undeclared::floor())),
        JudgingRuns::settings(BaselineSetting::at('floors.json')),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->warnings()))
        ->toBe(['src has no floor yet. Run mutation-gate baseline --write and commit floors.json.']);
});

it('warns of each mutator set a preset turns on that is not installed', function () use ($reporting, $judged): void {
    $skipped = Warning::that('The laravel preset turns on the mutator set "laravel", which is not installed: `composer require --dev nightworksio/mutation-gate-laravel`');
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], Warnings::of($skipped)),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->warnings()))->toContain($skipped->text());
});

it('says in the verdict why a ledger could not be read, before what else it warns of', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $unread = Unreadable::because(UnreadReason::Refused, 'https://ledgers.example.com', 'HTTP 503');
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Undeclared::floor()), ProofStoreFake::unreadable($unread)),
        JudgingRuns::settings(BaselineSetting::at('floors.json')),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->warnings()))->toBe([
        'The ledger is unreadable from https://ledgers.example.com: HTTP 503. The run judges without it.',
        'src has no floor yet. Run mutation-gate baseline --write and commit floors.json.',
    ]);
});

it('holds each tree to the higher of its declared floor and the baseline\'s', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', BaselineFile::encode(
        Baseline::of(Entry::of(Path::of('src'), Floor::of(45))),
    ));

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $tree(Floor::of(40))),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed);
});

it('cannot judge a baseline it cannot read', function () use ($tree, $reporting): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', 'not a baseline');

    $judgement = new Judging(
        Flows::adapters($project, [], $tree(Floor::of(40))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict(Planned::of(), JudgingRuns::noResults($project));

    expect($judgement)->toEqual(BaselineFile::decode('not a baseline', Path::of('mutation-gate.baseline.json')))
        ->and($judgement)->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge where the trees cannot be read', function () use ($reporting): void {
    $project = Flows::project();
    $unread = new TreeSourceFake(CannotJudge::because('There is no composer.json.'));

    $judgement = new Judging(
        Flows::adapters($project, [], $unread),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict(Planned::of(), JudgingRuns::noResults($project));

    expect($judgement)->toEqual(CannotJudge::because('There is no composer.json.'));
});

it('cannot judge with a report it cannot build', function () use ($tree, $reporting): void {
    $project = Flows::project();

    $judgement = new Judging(
        Flows::adapters($project, [], $tree(Floor::of(40))),
        Flows::settings(Report::uses('nowhere')),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict(Planned::of(), JudgingRuns::noResults($project));

    expect($judgement instanceof CannotJudge ? $judgement->why() : $judgement)->toContain('nowhere');
});

it('judges a pull request\'s new code against its own floor, and fails a raise not committed with it', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $plan = Planned::twoShards()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(Considered::everything()->reaching(
            Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(16)))),
            Reasons::of(Reason::that('src/Money.php changed.')),
        ));

    $verdict = JudgingRuns::verdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30))),
        JudgingRuns::settings(NewCodeFloor::of(80)),
        $reporting(new ReporterFake()),
    ));
    $newCode = [...$verdict->sets()->newCode()];

    expect(count($newCode))->toBe(1)
        ->and($newCode[0]->floor())->toEqual(Floor::of(80))
        ->and(count($newCode[0]->mutants()))->toBe(1)
        ->and(JudgingRuns::texts($verdict->reach()))->toBe(['src/Money.php changed.'])
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([<<<'SAID'
            src scored 40, above the floor of 30 it was held to. Commit the raised floor with this change:
            run mutation-gate baseline --write and commit mutation-gate.baseline.json.
            SAID])
        ->and($verdict->judgement())->toBe(Judgement::Failed);
});

it('only reports a raise in a pull request where baseline.improvement is report', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

    $verdict = JudgingRuns::verdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30))),
        JudgingRuns::settings(BaselineSetting::reportingImprovement(), NewCodeFloor::of(0)),
        $reporting(new ReporterFake()),
    ));

    expect(count($verdict->failures()))->toBe(0)
        ->and($verdict->judgement())->toBe(Judgement::Passed);
});

it('fails a pull request that lowers a floor the default branch holds without a reason', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', BaselineFile::encode(
        Baseline::of(Entry::of(Path::of('src'), Floor::of(40))),
    ));
    $onMain = BaselineFile::encode(Baseline::of(Entry::of(Path::of('src'), Floor::of(45))));
    $changes = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => Flows::FILES,
        'refs/remotes/origin/main' => ['mutation-gate.baseline.json' => $onMain],
    ]);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

    $verdict = JudgingRuns::verdictOf($judged(
        $plan,
        Flows::adapters($project, [], $tree(Floor::of(30)), $changes),
        JudgingRuns::settings(NewCodeFloor::of(0)),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->failures()))->toBe([<<<'SAID'
        The floor of src went down from 45 to 40 with no reason. A floor goes down only on purpose:
        add "lowered": { "from": 45, "reason": "…" } to its entry in the baseline.
        SAID]);
});

it('cannot judge a pull request where git cannot read the default branch\'s baseline', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $shallow = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => Flows::FILES,
    ]);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $store = new ProofStoreFake();

    $judgement = $judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30)), $shallow, $store),
        JudgingRuns::settings(NewCodeFloor::of(0)),
        $reporting(new ReporterFake()),
    );

    expect($judgement)->toEqual(CannotJudge::because(sprintf(
        "%s\n%s %s\n%s",
        'The baseline refs/remotes/origin/main holds cannot be read,',
        'so a floor lowered here cannot be checked against it.',
        'refs/remotes/origin/main is not a revision this repository has.',
        'Fetch the default branch into the checkout before the verdict.',
    )))
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7))))->toEqual(Ledger::empty());
});

it('counts the proofs and the results of its own scope it took, and records how many it used', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(
            Considered::everything()->proving(Units::of(Planned::money()))->carrying(Units::of(Planned::held())),
        );
    $run = Run::of('github:1/1', Moment::at('2026-09-29T12:00:00Z'), $plan->base());
    $proofOf = static fn(string $key, string $file): Proof => Proof::of(
        Digest::sha256Of($key),
        Path::of($file),
        Flows::mutantsOf($file),
        $run,
    );
    $store->write(
        Scope::pullRequest(7),
        Ledger::empty()
            ->withProof($proofOf('money', 'src/Money.php'))
            ->withProof($proofOf('elsewhere', 'src/Held.php')),
    );

    $judgement = new Judging(
        Flows::adapters($project, [], $store, $tree(Floor::of(40))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, JudgingRuns::noResults($project));
    $passed = LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->passed();

    expect($judgement instanceof Judged ? $judgement->verdict->judgement() : $judgement)->toBe(Judgement::Passed)
        ->and($passed)->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 2)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW))));
});

it('uses none of its own scope where the default branch proved what it took', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(Considered::everything()->proving(Units::of(Planned::money())));
    $run = Run::of('github:1/1', Moment::at('2026-09-29T12:00:00Z'), $plan->base());
    $mutants = Flows::mutantsOf('src/Money.php');
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(
        Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), $mutants, $run),
    ));

    $judgement = new Judging(
        Flows::adapters($project, [], $store, $tree(Floor::of(50))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, JudgingRuns::noResults($project));

    expect(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->passed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 0)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW))));
});

it('cannot judge where a unit the plan proved or carried has lost its proof', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(
            Considered::everything()->proving(Units::of(Planned::money()))->carrying(Units::of(Planned::held())),
        );

    $judgement = new Judging(
        Flows::adapters($project, [], $store, $tree(Floor::of(100))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, JudgingRuns::noResults($project));

    expect($judgement)->toEqual(CannotJudge::because(<<<'SAID'
        The ledger no longer holds a proof the plan took, so these units cannot be judged:
        src/Money.php, src/Held.php
        A proof pruned, or a cache replaced, between the plan and the verdict does this. Plan the run again.
        SAID))
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7))))->toEqual(Ledger::empty());
});

it('takes a planned proof from the run\'s own scope where it has moved there from the default branch', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(Considered::everything()->proving(Units::of(Planned::money())));
    $run = Run::of('github:1/1', Moment::at('2026-09-29T12:00:00Z'), $plan->base());
    $mutants = Flows::mutantsOf('src/Money.php');
    $store->write(Scope::pullRequest(7), Ledger::empty()->withProof(
        Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), $mutants, $run),
    ));

    $judgement = new Judging(
        Flows::adapters($project, [], $store, $tree(Floor::of(50))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, JudgingRuns::noResults($project));

    expect($judgement instanceof Judged ? count($judgement->verdict->trees()->units()) : $judgement)->toBe(1)
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->passed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 1)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW))));
});

it('carries the last result\'s mutants of a mutator the plan pruned into the unit, and writes no proof of it', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = JudgingRuns::proven('money', 'money test');
    $digested = JudgingRuns::digested('money', 'money test');
    $plan = $digested->considering(
        $digested->considered()->pruning(Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/Money.php')))),
    );
    $unpruned = JudgingRuns::verdictOf($judged($digested, Flows::adapters($project, [], JudgingRuns::proven('money', 'money test'), $tree(Floor::of(50))), JudgingRuns::settings(), $reporting(new ReporterFake())));
    Scratch::sweep();
    $project = Flows::project();

    $verdict = JudgingRuns::verdictOf($judged($plan, Flows::adapters($project, [], $store, $tree(Floor::of(50))), JudgingRuns::settings(), $reporting(new ReporterFake())));
    $carried = MutantId::hash(Path::of('src/Money.php'), 'Plus', 'carried kill', 0);
    $ids = array_map(static fn(JudgedMutant|JudgedKill $judged): string => $judged->mutant()->id()->value(), [...$verdict->trees()->mutants()]);

    $made = static fn(Verdict $judged): array => array_map(
        static fn(JudgedMutant|JudgedKill $mutant): string => sprintf('%s %s', $mutant->mutant()->location()->file()->value(), $mutant->mutant()->mutator()),
        [...$judged->trees()->mutants()],
    );

    $pruned = static fn(Verdict $judged): array => array_values(array_map(
        static fn(JudgedMutant|JudgedKill $mutant): string => $mutant->mutant()->id()->value(),
        array_filter([...$judged->trees()->mutants()], static fn(JudgedMutant|JudgedKill $mutant): bool => $mutant->isCarriedPruned()),
    ));

    expect($ids)->toContain($carried->value())
        ->and($made($unpruned))->toContain('src/Money.php Plus')
        ->and(array_count_values($made($verdict))['src/Money.php Plus'] ?? 0)->toBe(1)
        ->and(count($verdict->trees()->mutants()))->toBe(count($unpruned->trees()->mutants()))
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()->has(Digest::sha256Of('money')))->toBeFalse()
        ->and([...$verdict->account()->pruning()->mutators()])->toBe(['Plus'])
        ->and($verdict->account()->pruning()->carried())->toBe(1)
        ->and($verdict->account()->pruning()->window()->mutants())->toBe(500)
        ->and($unpruned->account()->pruning()->isNone())->toBeTrue()
        ->and($pruned($verdict))->toBe([$carried->value()])
        ->and($pruned($unpruned))->toBe([])
        ->and(array_values(array_intersect_key(
            Decoded::column(JsonReport::encode($verdict), 'id', 'mutants'),
            array_filter(Decoded::column(JsonReport::encode($verdict), 'carriedPruned', 'mutants'), static fn(mixed $mark): bool => $mark === true),
        )))->toBe([$carried->value()]);
});
