<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Baseline as BaselineSetting;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Floor as NewCodeFloor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Config\Uncovered;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\Reason as MutantReason;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\CountedChanges;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
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

/** The one tree, `src`, held to this floor. */
$tree = static fn(Floor|Undeclared $floor): TreeSourceFake => new TreeSourceFake(
    Trees::of(Tree::at(Path::of('src'), $floor, Package::at(Path::root()))),
);

/** What chooses the verdict's reporters: this package's own, and one that remembers what it was handed. */
$reporting = static fn(Reporter $recorded): Reporting => new Reporting(
    new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))->withReporter(
        Name::of('recorded'),
        static fn(): Reporter => $recorded,
    )),
    Variables::of([]),
);

/** The settings of the least config, reporting to the recorded reporter, and of these besides. */
function judgingSettings(NewCodeFloor|Ignore|Setting ...$parts): Settings
{
    return Flows::settings(Report::uses('recorded'), ...$parts);
}

/** Every shard of a plan run, each handed its map, and then judged. */
$judged = static function (
    Plan $plan,
    Adapters $adapters,
    Settings $settings,
    Reporting $reporting,
): Judged|Invalid|CannotJudge {
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    new Running($adapters, $settings, Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    return $results instanceof Results
        ? new Judging($adapters, $settings, Flows::setup(), $reporting)->verdict($plan, $results)
        : $results;
};

/** The verdict judged, which a test that reaches one expects. */
function judgingVerdictOf(Judged|Invalid|CannotJudge $judged): Verdict
{
    return $judged instanceof Judged
        ? $judged->verdict
        : throw new RuntimeException($judged instanceof CannotJudge ? $judged->why() : 'The config is invalid.');
}

/** The results of a plan with no shard, which are none. */
function judgingNoResults(string $project): Results
{
    $results = Results::read(Planned::of(), Workspace::results(), Directory::at($project));

    return $results instanceof Results ? $results : throw new RuntimeException($results->why());
}

/**
 * @param iterable<Failure|Warning|Reason> $said
 *
 * @return list<string> the text of each
 */
function judgingTexts(iterable $said): array
{
    return array_map(
        static fn(Failure|Warning|Reason $each): string => $each->text(),
        iterator_to_array($said, preserve_keys: false),
    );
}

it('judges every tree whole, records the run, and reports it', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $recorded = new ReporterFake();

    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $store, $tree(Floor::of(50))),
        judgingSettings(),
        $reporting($recorded),
    );
    $verdict = judgingVerdictOf($judgement);

    expect($judgement instanceof Judged ? $judgement->said : $judgement)
        ->toBe(['Wrote memory:refs/heads/main.', 'Wrote memory.'])
        ->and($recorded->reported)->toBe([$verdict])
        ->and($verdict->judgement())->toBe(Judgement::Failed)
        ->and(count($verdict->trees()->units()))->toBe(2)
        ->and(count($verdict->trees()->mutants()))->toBe(5)
        ->and(count($verdict->sets()->newCode()))->toBe(0)
        ->and(count($verdict->failures()))->toBe(0)
        ->and($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::Failed)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed())->toBeInstanceOf(CannotTell::class);
});

it('judges a plan\'s results again as the verdict did, reporting nothing and writing no ledger', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $adapters = Flows::adapters($project, [], $store, $tree(Floor::of(50)));
    $plan = Planned::twoShards();
    $first = judgingVerdictOf($judged($plan, $adapters, judgingSettings(), $reporting(new ReporterFake())));
    $written = $store->read(Scope::branch('main'));
    $recorded = new ReporterFake();
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $again = $results instanceof Results
        ? new Judging($adapters, judgingSettings(), Flows::setup(), $reporting($recorded))->again($plan, $results)
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
    $again = new Judging($adapters, judgingSettings(), Flows::setup(), $reporting(new ReporterFake()))
        ->again(Planned::twoShards(), judgingNoResults($project));

    $baseline = BaselineFile::decode('{', Path::of('mutation-gate.baseline.json'));
    $unreadable = $baseline instanceof CannotJudge ? $baseline->why() : 'a baseline';

    expect($again instanceof CannotJudge ? $again->why() : $again)->toBe($broken === 'trees' ? 'No trees.' : $unreadable);
})->with(['trees', 'baseline']);

it('records a pass under the check the config names', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();

    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $store, $tree(Floor::of(40))),
        judgingSettings(Ci::check('gate / verdict')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'gate / verdict', 0));
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
        judgingSettings(),
        $reporting($unwritten),
    );

    expect($judgement instanceof Judged ? $judgement->said : $judgement)
        ->toBe(['Wrote memory:refs/heads/main.', 'The disk is full.']);
});

it('says why the ledger was not written', function () use ($tree, $reporting, $judged): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(50))),
        judgingSettings(Proofs::readOnly()),
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
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    unlink(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::shardCoverage(ShardId::of(1)))->value()));
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $judgement = $results instanceof Results
        ? new Judging($adapters, judgingSettings(), Flows::setup(), $reporting($recorded))->verdict($plan, $results)
        : $results;

    expect($judgement)->toEqual(new Handoff(Directory::at($project))->read(ShardId::of(1)))
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
        judgingSettings(),
        $reporting(new ReporterFake()),
    );
    $verdict = judgingVerdictOf($judgement);

    expect($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::CannotJudge)
        ->and(judgingTexts($verdict->failures()))->toBe([
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
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Undeclared::floor())),
        judgingSettings(BaselineSetting::at('floors.json')),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->warnings()))
        ->toBe(['src has no floor yet. Run mutation-gate baseline --write and commit floors.json.']);
});

it('warns of each mutator set a preset turns on that is not installed', function () use ($reporting, $judged): void {
    $skipped = Warning::that('The laravel preset turns on the mutator set "laravel", which is not installed: `composer require --dev nightworksio/mutation-gate-laravel`');
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], Warnings::of($skipped)),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->warnings()))->toContain($skipped->text());
});

it('says in the verdict why a ledger could not be read, before what else it warns of', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $unread = Unreadable::because(UnreadReason::Refused, 'https://ledgers.example.com', 'HTTP 503');
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Undeclared::floor()), ProofStoreFake::unreadable($unread)),
        judgingSettings(BaselineSetting::at('floors.json')),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->warnings()))->toBe([
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

    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $tree(Floor::of(40))),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed);
});

it('cannot judge a baseline it cannot read', function () use ($tree, $reporting): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', 'not a baseline');

    $judgement = new Judging(
        Flows::adapters($project, [], $tree(Floor::of(40))),
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict(Planned::of(), judgingNoResults($project));

    expect($judgement)->toEqual(BaselineFile::decode('not a baseline', Path::of('mutation-gate.baseline.json')))
        ->and($judgement)->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge where the trees cannot be read', function () use ($reporting): void {
    $project = Flows::project();
    $unread = new TreeSourceFake(CannotJudge::because('There is no composer.json.'));

    $judgement = new Judging(
        Flows::adapters($project, [], $unread),
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict(Planned::of(), judgingNoResults($project));

    expect($judgement)->toEqual(CannotJudge::because('There is no composer.json.'));
});

it('cannot judge with a report it cannot build', function () use ($tree, $reporting): void {
    $project = Flows::project();

    $judgement = new Judging(
        Flows::adapters($project, [], $tree(Floor::of(40))),
        Flows::settings(Report::uses('nowhere')),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict(Planned::of(), judgingNoResults($project));

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

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30))),
        judgingSettings(NewCodeFloor::of(80)),
        $reporting(new ReporterFake()),
    ));
    $newCode = [...$verdict->sets()->newCode()];

    expect(count($newCode))->toBe(1)
        ->and($newCode[0]->floor())->toEqual(Floor::of(80))
        ->and(count($newCode[0]->mutants()))->toBe(1)
        ->and(judgingTexts($verdict->reach()))->toBe(['src/Money.php changed.'])
        ->and(judgingTexts($verdict->failures()))->toBe([<<<'SAID'
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

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30))),
        judgingSettings(BaselineSetting::reportingImprovement(), NewCodeFloor::of(0)),
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

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters($project, [], $tree(Floor::of(30)), $changes),
        judgingSettings(NewCodeFloor::of(0)),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->failures()))->toBe([<<<'SAID'
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
        judgingSettings(NewCodeFloor::of(0)),
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
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, judgingNoResults($project));
    $passed = LedgerRead::ledger($store->read(Scope::pullRequest(7)))->lastPassed();

    expect($judgement instanceof Judged ? $judgement->verdict->judgement() : $judgement)->toBe(Judgement::Passed)
        ->and($passed)->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 2));
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
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, judgingNoResults($project));

    expect(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->lastPassed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 0));
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
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, judgingNoResults($project));

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
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, judgingNoResults($project));

    expect($judgement instanceof Judged ? count($judgement->verdict->trees()->units()) : $judgement)->toBe(1)
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->lastPassed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 1));
});

it('kills a timeout only where triage confirms it, so a tree at 100 fails on one it cannot', function (
    CoverageMap $map,
    Setting $timeouts,
    Judgement $judgement,
) use ($tree, $reporting): void {
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
    new Handoff($adapters->project)->write($plan, $map, KillHistory::none());
    new Running($adapters, judgingSettings($timeouts), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $judging = new Judging($adapters, judgingSettings($timeouts), Flows::setup(), $reporting(new ReporterFake()));
    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(count($timedOut))->toBe(1)
        ->and($verdict->judgement())->toBe($judgement);
})->with([
    'tests that take under half its limit' => [Flows::map(), Timeouts::confirmed(), Judgement::Passed],
    'tests whose time the map does not hold' => [CoverageMap::empty(), Timeouts::confirmed(), Judgement::Failed],
    'timeouts.mode unjudged' => [Flows::map(), Timeouts::unjudged(), Judgement::Failed],
]);

it('says how many of the runner\'s own ignore markers ignores.native allows in what the run mutated', function (
    Setting $native,
    Markers|CannotJudge $markers,
    array $said,
) use ($tree, $reporting, $judged): void {
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), ScriptedRunner::fixture()->marking($markers)),
        judgingSettings($native),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->warnings()))->toBe($said);
})->with([
    'allowed' => [
        Ignores::allowingNativeMarkers(),
        Markers::of(
            Marker::inSource(Path::of('src/Money.php'), Line::of(9), 'a', Nameless::code()),
            Marker::inSource(Path::of('src/Held.php'), Line::of(4), 'b', Nameless::code()),
        ),
        [<<<'SAID'
            2 of the runner's own ignore markers hide mutants in the files this run mutated,
            as ignores.native: allow lets them. The gate cannot count the mutants they hide.
            Move them into ignores.entries.
            SAID],
    ],
    'allowed, and none found' => [Ignores::allowingNativeMarkers(), Markers::none(), []],
    'allowed, and not counted' => [
        Ignores::allowingNativeMarkers(),
        CannotJudge::because('infection.json5 cannot be read.'),
        ['The runner\'s own ignore markers could not be counted. infection.json5 cannot be read.'],
    ],
    'refused, where the plan already stopped for any' => [
        Ignores::refusingNativeMarkers(),
        Markers::of(Marker::inSource(Path::of('src/Money.php'), Line::of(9), 'a', Nameless::code())),
        [],
    ],
]);

it('warns of what a shard warned of, and judges as it would without it', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $plan = Planned::oneShard();
    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)));
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', 'garbled');
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, judgingSettings(), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);
    $said = judgingTexts($verdict->warnings());

    expect($said)->toHaveCount(1)
        ->and($said[0] ?? '')->toStartWith('Shard 1 ran its tests without the kill history the plan handed it.')
        ->and($verdict->failures())->toHaveCount(0)
        ->and($verdict->judgement())->toBe(Judgement::Passed);
});

it('kills a survivor static analysis rejects, and warns once for each reason it left the shards\' survivors unchecked', function () use (
    $tree,
    $reporting,
): void {
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
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, judgingSettings(), Flows::setup(), $reporting(new ReporterFake()));
    $statuses = [];

    foreach ($results instanceof Results ? $results->shards() : [] as [, , $mutated]) {
        foreach ($mutated->mutants() as $mutant) {
            $statuses[] = $mutant->status()->value;
        }
    }

    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(count($survivor))->toBe(1)
        ->and($statuses)->toContain('killed-by-static-analysis')
        ->and(judgingTexts($verdict->warnings()))->toBe([
            'Static analysis left 1 survivor unchecked, as the analyser could not check them: src/Held.php. '
            . '.mutation-gate/staticcheck/mutants/8705b7dc7d27.php is no mutant the fake was told about.',
        ]);
});

it('names the tests as the plan names them, and warns once where it names none', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $names = TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'));
    $adapters = static fn(): Adapters => Flows::adapters(Flows::project(), [], $tree(Floor::of(0)));
    $named = judgingVerdictOf($judged(
        Planned::twoShards()->naming($names),
        $adapters(),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));
    $unnamed = judgingVerdictOf($judged(
        Planned::twoShards()->naming(CannotJudge::because('Pest cannot list its tests.')),
        $adapters(),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));

    expect($named->matrix()->names())->toBe($names)
        ->and(judgingTexts($named->warnings()))->toBe([])
        ->and($unnamed->matrix()->names())->toEqual(TestNames::none())
        ->and(judgingTexts($unnamed->warnings()))
        ->toBe(['The reports name each test by its coverage id. Pest cannot list its tests.']);
});

it('builds the kill matrix of first killers over the map the plan handed it, as its runner can', function (
    ScriptedRunner $runner,
    NotFull $whyNotFull,
) use ($tree, $reporting, $judged): void {
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), $runner),
        judgingSettings(),
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
        ->and(judgingTexts($verdict->warnings()))->toBe([]);
})->with([
    'a runner that can record every killer' => [ScriptedRunner::fixture(), NotFull::FirstKillers],
    'one that stops at the first' => [
        ScriptedRunner::fixture()->behaving(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)),
        NotFull::Infection,
    ],
]);

it('builds a full kill matrix where the plan records one, and writes proofs whose runs recorded every killer', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards()->briefed(Briefing::standard()->recording(MatrixKind::Full)),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $tree(Floor::of(0)), $store),
        judgingSettings(),
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
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $plan = Planned::oneShard();
    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)));
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    unlink(sprintf('%s/.mutation-gate/coverage/verdict/map.json.gz', $project));
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, judgingSettings(), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect($verdict->matrix()->coverage())->toEqual(CoverageMap::empty())
        ->and(judgingTexts($verdict->warnings()))->toBe([
            'The kill matrix holds each mutant\'s killers alone. The verdict was handed no coverage map at '
            . '.mutation-gate/coverage/verdict/map.json.gz. Hand it the plan\'s .mutation-gate/coverage.',
        ])
        ->and($verdict->judgement())->toBe(Judgement::Passed);
});

it('warns of each file most of the suite runs through that nothing holds, past holds.hotPath', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $plan = Planned::oneShard();
    $map = Flows::map();

    foreach (range(1, 20) as $each) {
        $test = TestId::of(sprintf('SuiteTest::case%d', $each));
        $map = $map->covered(Path::of('src/Money.php'), Line::of(11), $test)
            ->covered(Path::of('src/Held.php'), Line::of(11), $test);
    }

    $adapters = Flows::adapters($project, [], $tree(Floor::of(0)));
    new Handoff($adapters->project)->write($plan, $map, KillHistory::none());
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, judgingSettings(), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(judgingTexts($verdict->warnings()))->toBe([
        '`src/Money.php` is run by 21 of 22 tests and nothing holds it; each of its mutants runs most of the suite.',
    ]);
});

it('fails a verdict on a held unit its holding tests miss lines of, and proves nothing of it', function (
    Setting $uncovered,
) use ($tree, $reporting): void {
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
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    new Running($adapters, judgingSettings($uncovered), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, judgingSettings($uncovered), Flows::setup(), $reporting(new ReporterFake()));

    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(judgingTexts($verdict->failures()))->toBe([<<<'SAID'
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
    'uncovered mutants counted' => [Uncovered::counted()],
    'uncovered mutants left out' => [Uncovered::excluded()],
]);

it('judges flaky what a fresh result and a proof under its key disagree on, and keeps neither', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $earlier = Run::of('github:0/1', Moment::at('2026-09-28T12:00:00Z'), $plan->base());
    $store->write(Scope::branch('main'), Ledger::empty()->atBase($plan->base())->withProof(
        Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), Mutants::none(), $earlier),
    ));

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), $store),
        judgingSettings(),
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

it('clusters the survivors of one cause from the project\'s source before it reports', function () use ($tree, $reporting): void {
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
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, judgingSettings(), Flows::setup(), $reporting($recorded));
    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);
    $clusters = iterator_to_array($verdict->trees()->clusters(), preserve_keys: false);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]->kind())->toBe(ClusterKind::Expression)
        ->and($clusters[0]->members())->toHaveCount(2)
        ->and($recorded->reported)->toBe([$verdict]);
});

/** A budgeted run's plan: the two-shard plan, with its digests and the name of the test that kills in src/Money.php. */
function judgingDigested(string $moneySource, string $moneyTest): Plan
{
    return Planned::twoShards()
        ->digesting(Digests::of(Digest::sha256Of('mutation'))
            ->withSource(Path::of('src/Money.php'), Digest::sha256Of($moneySource))
            ->withSource(Path::of('src/Held.php'), Digest::sha256Of('held'))
            ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of($moneyTest)))
        ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds')));
}

/**
 * A store whose default branch proves both units at the plan's base, with
 * these digests: src/Money.php with a survivor and a kill by MoneyTest::adds.
 */
function judgingProven(string $moneySource, string $moneyTest): ProofStoreFake
{
    $run = Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of(Planned::BASE));
    $mutants = Flows::mutantsOf('src/Money.php');
    $survivor = [...array_filter([...$mutants], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived)][0];
    $kill = ProvedKill::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', 'carried kill', 0),
        Path::of('src/Money.php'),
        Line::of(3),
        'Plus',
        TestIds::of(TestId::of('MoneyTest::adds')),
    );
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof(Proof::held(Digest::sha256Of('money before'), Path::of('src/Money.php'), Mutants::of($survivor), ProvedKills::of($kill), $run)
            ->withInputs(Inputs::of(Digest::sha256Of($moneySource), Digest::sha256Of('mutation'))
                ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of($moneyTest))))
        ->withProof(Proof::of(Digest::sha256Of('held before'), Path::of('src/Held.php'), Mutants::none(), $run)
            ->withInputs(Inputs::of(Digest::sha256Of('held'), Digest::sha256Of('mutation')))));

    return $store;
}

/**
 * The project, where src/Money.php uses the trait src/Equals.php declares
 * and nothing names src/Tax.php, and a checkout whose working tree changed
 * this file since each of these commits.
 *
 * @return array{string, ChangeSourceFake}
 */
function judgingAcross(string $changed, Revision ...$commits): array
{
    $files = [
        ...Flows::FILES,
        'src/Money.php' => "<?php\n\nfinal class Money\n{\n    use Equals;\n}\n",
        'src/Equals.php' => "<?php\n\ntrait Equals\n{\n}\n",
        'src/Tax.php' => "<?php\n\nfinal class Tax\n{\n}\n",
    ];
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $byRevision = [Revision::workingTree()->name() => $files];

    foreach ($commits as $commit) {
        $byRevision[$commit->name()] = $files;
    }

    $checkout = new ChangeSourceFake($commits[0], Changes::of(Change::modified(Path::of($changed), Lines::of(Line::of(4)))), $byRevision);

    foreach (array_slice($commits, 1) as $commit) {
        $checkout = $checkout->alsoFrom($commit);
    }

    return [$project, $checkout];
}

/**
 * A store whose default branch proves both units at another base, each
 * result's digests taken at a commit: src/Money.php with a survivor and a
 * kill by MoneyTest::adds, and src/Held.php with a kill by MoneyTest::adds.
 */
function judgingProvenAcross(Revision $money, Revision $held): ProofStoreFake
{
    $run = Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('another base'));
    $kill = static fn(string $unit): ProvedKill => ProvedKill::of(
        MutantId::hash(Path::of($unit), 'Plus', 'carried kill', 0),
        Path::of($unit),
        Line::of(3),
        'Plus',
        TestIds::of(TestId::of('MoneyTest::adds')),
    );
    $inputs = static fn(string $source, Revision $commit): Inputs => Inputs::of(Digest::sha256Of($source), Digest::sha256Of('mutation'))
        ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
        ->takenAt($commit);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof(Proof::held(Digest::sha256Of('money before'), Path::of('src/Money.php'), Mutants::none(), ProvedKills::of($kill('src/Money.php')), $run)
            ->withInputs($inputs('money', $money)))
        ->withProof(Proof::held(Digest::sha256Of('held before'), Path::of('src/Held.php'), Mutants::none(), ProvedKills::of($kill('src/Held.php')), $run)
            ->withInputs($inputs('held', $held))));

    return $store;
}

it('carries a kill from another commit where nothing changed since reaches its unit or its test by name', function () use ($tree, $reporting, $judged): void {
    $commit = Revision::ref(str_repeat('c1', 20));
    [$project, $checkout] = judgingAcross('src/Tax.php', $commit);
    $store = judgingProvenAcross($commit, $commit);

    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters($project, [], $store, $tree(Floor::of(0)), $checkout),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($trees[0]->counts()->number(MutantJudgement::Killed))->toBe(2)
        ->and($trees[0]->counts()->number(MutantJudgement::Unjudged))->toBe(0);
});

it('leaves a kill from another commit unjudged where what changed since reaches its unit by a name it uses', function () use ($tree, $reporting, $judged): void {
    $commit = Revision::ref(str_repeat('c1', 20));
    [$project, $checkout] = judgingAcross('src/Equals.php', $commit);

    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters($project, [], judgingProvenAcross($commit, $commit), $tree(Floor::of(0)), $checkout),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(judgingTexts($verdict->failures()))->toBe([
            "The time budget left 1 of the mutants of src/Money.php unjudged, so this run cannot pass it.\n"
            . 'More time judges them: vendor/bin/mutation-gate run --budget=<duration>',
        ]);
});

it('carries no kill from a commit git cannot read, and warns why', function () use ($tree, $reporting, $judged): void {
    $commit = Revision::ref(str_repeat('c1', 20));
    [$project, $checkout] = judgingAcross('src/Tax.php', Revision::ref(str_repeat('c2', 20)));

    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters($project, [], judgingProvenAcross($commit, $commit), $tree(Floor::of(0)), $checkout),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $warnings = array_map(static fn(Warning $warning): string => $warning->text(), [...$verdict->warnings()]);

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and($warnings)->toContain(sprintf(
            'No kill proved at %1$s carries for a unit the budget never started. %1$s is not a revision this repository has.',
            str_repeat('c1', 20),
        ));
});

it('reads what changed since each commit once, however many results share it', function (Revision $money, Revision $held, array $asked) use ($tree, $reporting, $judged): void {
    [$project, $checkout] = judgingAcross('src/Tax.php', $money, $held);
    $counted = new CountedChanges($checkout);

    $judged(
        judgingDigested('money', 'money test'),
        Flows::adapters($project, [], judgingProvenAcross($money, $held), $tree(Floor::of(0)), $counted),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    );

    expect($counted->askedFrom())->toBe($asked);
})->with([
    'one commit' => [Revision::ref(str_repeat('c1', 20)), Revision::ref(str_repeat('c1', 20)), [str_repeat('c1', 20)]],
    'two commits' => [Revision::ref(str_repeat('c1', 20)), Revision::ref(str_repeat('c2', 20)), [str_repeat('c1', 20), str_repeat('c2', 20)]],
]);

it('never passes new code in a unit the budget never started, whose newest result is of the code before', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = judgingProven('money before', 'money test');
    $plan = judgingDigested('money now', 'money test')
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->considering(Considered::everything()->reaching(
            Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(20), Line::of(21)))),
            Reasons::of(Reason::that('src/Money.php changed.')),
        ));

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(judgingTexts($verdict->failures()))->toBe([
            "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'Its newest result is of other source, so its mutants are not this code\'s. '
            . 'More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        ])
        ->and($verdict->wasCutShort())->toBeTrue()
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->lastPassed())->toBeInstanceOf(CannotTell::class);
});

it('never records a pass for a run whose budget left a mutant unjudged', function () use ($tree, $reporting, $judged): void {
    $store = judgingProven('money', 'money test before');

    $judgement = $judged(
        judgingDigested('money', 'money test now'),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    );
    $verdict = judgingVerdictOf($judgement);
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(judgingTexts($verdict->failures()))->toBe([
            "The time budget left 1 of the mutants of src/Money.php unjudged, so this run cannot pass it.\n"
            . 'More time judges them: vendor/bin/mutation-gate run --budget=<duration>',
        ])
        ->and($trees[0]->counts()->number(MutantJudgement::Unjudged))->toBe(1)
        ->and($trees[0]->raised())->toEqual(Unraised::floor())
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed())->toBeInstanceOf(CannotTell::class)
        ->and(count(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()))->toBe(2);
});

it('passes a run whose budget left units whose newest results all stand, and records it', function () use ($tree, $reporting, $judged): void {
    $store = judgingProven('money', 'money test');

    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($verdict->wasCutShort())->toBeTrue()
        ->and($trees[0]->counts()->number(MutantJudgement::Killed))->toBe(1)
        ->and($trees[0]->counts()->number(MutantJudgement::Survived))->toBe(1)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed())->toBeInstanceOf(Passed::class);
});

it('fails on an ignore that names nothing where every unit the budget left has a result that stands', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters(Flows::project(), [], judgingProven('money', 'money test'), $tree(Floor::of(0))),
        judgingSettings(Budget::of('1s'), Ignore::mutator('MethodCallRemoval', 'src/Log/**', 'Logged elsewhere')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->wasCutShort())->toBeTrue()
        ->and(judgingTexts($verdict->failures()))->toBe([
            'The ignore of MethodCallRemoval in src/Log/** names no mutant it could leave out, in a run that judged every unit: remove it.',
        ]);
});

it('fails on no ignore that names nothing where the budget left a unit with no result that stands', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters(Flows::project(), [], new ProofStoreFake(), $tree(Floor::of(0))),
        judgingSettings(Budget::of('1s'), Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same')),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->failures()))->toBe([
        "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n"
        . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        "src/Held.php is unjudged: the time budget ran out before this run mutated it.\n"
        . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
    ]);
});

it('fails on no ignore that names nothing where a held unit did not run', function () use ($tree, $reporting): void {
    $project = Flows::project();
    $plan = Planned::oneShard();
    $settings = judgingSettings(Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same'));
    $adapters = Flows::adapters(
        $project,
        [],
        $tree(Floor::of(0)),
        new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::empty()),
    );
    new Handoff($adapters->project)->write($plan, Flows::map(), KillHistory::none());
    new Running($adapters, $settings, Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);
    $judging = new Judging($adapters, $settings, Flows::setup(), $reporting(new ReporterFake()));

    $verdict = judgingVerdictOf($results instanceof Results ? $judging->verdict($plan, $results) : $results);

    expect(judgingTexts($verdict->failures()))->toBe([<<<'SAID'
        holds:src/Held.php does not cover src/Held.php, so its mutants cannot be judged by it.
        Not reached: src/Held.php, all of it
        Add the test that runs them to the group.
        SAID]);
});

it('counts no unit the budget never started by a result of an earlier ledger format, nor one no ledger holds', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('money before'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of(Planned::BASE)),
    )));

    $verdict = judgingVerdictOf($judged(
        judgingDigested('money', 'money test'),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0))),
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(judgingTexts($verdict->failures()))->toBe([
            "src/Money.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'Its newest result records no digests of its inputs to say it is this code\'s. '
            . 'More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
            "src/Held.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        ])
        ->and([...$verdict->trees()][0]->mutants())->toHaveCount(0)
        ->and(count(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()))->toBe(1);
});

it('records no pass for a verdict that passed with a mutant the runner left unjudged', function () use ($tree, $reporting, $judged): void {
    $store = new ProofStoreFake();
    $unjudged = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', 'unjudged', 0),
        'Plus-9',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::Unjudged,
        Seconds::of(0.1),
    )->because(MutantReason::that('No test could be named.'));

    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($unjudged), 0)),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed())->toBeInstanceOf(CannotTell::class);
});

it('keeps the last commit that passed where a budget left a kill unjudged, so the next run from it judges the unit again', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $passed = Revision::ref(str_repeat('a1', 20));
    $store = judgingProven('money', 'money test before');
    $store->write(Scope::branch('main'), LedgerRead::ledger($store->read(Scope::branch('main')))->withPassed(Passed::of($passed, 'mutation-gate', 0)));
    $checkout = new ChangeSourceFake(
        $passed,
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, $passed->name() => Flows::FILES],
    )->unchangedSince(Revision::ref(Flows::HEAD));
    $adapters = Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(0)), $checkout);

    $first = judgingVerdictOf($judged(
        judgingDigested('money', 'money test now'),
        $adapters,
        judgingSettings(Budget::of('1s')),
        $reporting(new ReporterFake()),
    ));
    $next = new Planning($adapters, Flows::settings(), Flows::setup())
        ->plan(
            Mode::since(Mode::LAST_PASSED),
            CoverageRun::of(WholeSuite::tests(), Workspace::coverage()),
            Cut::exactly(1),
            MatrixKind::FirstKiller,
        );
    $planned = [];

    foreach ($next instanceof Plan ? $next : [] as $shard) {
        foreach ($shard->units() as $unit) {
            $planned[] = $unit->path()->value();
        }
    }

    $lastPassed = LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed();

    expect($first->judgement())->toBe(Judgement::Failed)
        ->and($lastPassed instanceof Passed ? $lastPassed->commit() : $lastPassed)->toEqual($passed)
        ->and($planned)->toBe(['src/Money.php']);
});

it('finds the weak tests that let a survivor through from the test files the plan names, before it reports', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    Scratch::write($project, 'tests/MoneyTest.php', "<?php\n\nit('adds', function () {\n    expect(fits(1, 2))->toBeBool();\n});\n");
    $literal = Verdicts::mutant('src/Money.php:11', 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'));
    $recorded = new ReporterFake();
    $verdict = judgingVerdictOf($judged(
        Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))),
        Flows::adapters($project, [], $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($literal), 0)),
        judgingSettings(),
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

it('reads the helpers the files that define the runner declare, and names no test that calls one weak', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    Scratch::write($project, 'tests/Pest.php', implode("\n", ['<?php', 'function fitsExactly(bool $fits): void { expect($fits)->toBe(false); }', '']));
    Scratch::write($project, 'tests/MoneyTest.php', "<?php\n\nit('adds', function () {\n    expect(fits(1, 2))->toBeBool();\n    fitsExactly(fits(1, 2));\n});\n");
    $literal = Verdicts::mutant('src/Money.php:11', 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'));
    $verdict = judgingVerdictOf($judged(
        Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))),
        Flows::adapters($project, [], $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($literal), 0)),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));
    $survivors = array_values(array_filter([...$verdict->trees()->mutants()], static fn(JudgedMutant|JudgedKill $judged): bool => $judged instanceof JudgedMutant));

    expect($survivors[0]->finding())->toEqual(NoFinding::survivor());
});

it('suggests deleting the callee of a surviving removal whose body the tests leave unchecked, before it reports', function () use ($tree, $reporting, $judged): void {
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
    $verdict = judgingVerdictOf($judged(
        Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))),
        Flows::adapters($project, [], $tree(Floor::of(0)), ScriptedRunner::fixture()->answering(Mutants::of($removal, $body), 0)),
        judgingSettings(),
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

it('leaves the survivors the config ignores out of the score, and names an ignore that ends soon or has ended', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(50))),
        judgingSettings(
            Ignore::mutant('49e02fb39669', 'The bound is never reached', '2026-10-10'),
            Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same'),
            Ignore::mutant('95e61bd8bf62', 'Covered by the integration suite', '2026-09-29'),
        ),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($verdict->trees()->mutants()->counts()->number(MutantJudgement::Ignored))->toBe(2)
        ->and($verdict->trees()->mutants()->counts()->number(MutantJudgement::Uncovered))->toBe(1)
        ->and(judgingTexts($verdict->warnings()))->toBe([
            'The ignore of 95e61bd8bf62 expired on 2026-09-29, so its mutants count again.',
            'The ignore of 49e02fb39669 expires on 2026-10-10.',
        ]);
});

it('fails a run that judged every unit on an ignore that names no mutant it leaves out', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0))),
        judgingSettings(Ignore::mutator('MethodCallRemoval', 'src/Log/**', 'Logged elsewhere')),
        $reporting(new ReporterFake()),
    );

    expect(judgingTexts(judgingVerdictOf($judgement)->failures()))->toBe([
        'The ignore of MethodCallRemoval in src/Log/** names no mutant it could leave out, in a run that judged every unit: remove it.',
    ])
        ->and($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::Failed);
});

it('stops a CI run on a security set held to no floor, and hands over its measured floor', function () use ($tree, $reporting, $judged): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $tree(Floor::of(10)), NamedMutators::of('Plus')),
        judgingSettings(),
        $reporting(new ReporterFake()),
    );
    $verdict = judgingVerdictOf($judgement);
    $sets = [...$verdict->sets()->security()];

    expect($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::CannotJudge)
        ->and(count($sets))->toBe(1)
        ->and($sets[0]->mutants())->toHaveCount(2)
        ->and(judgingTexts($verdict->failures()))->toBe([
            <<<'SAID'
                The security set of . has no floor: neither security.floor nor the package's securityFloor
                declares one, and the baseline holds none.
                A security set is never held to no floor. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.
                SAID,
            sprintf(
                "The baseline this run measured, ready to commit as mutation-gate.baseline.json:\n%s",
                BaselineFile::encode(
                    Baseline::of(Entry::of(Path::of('src'), Floor::of(40)))->withSecurity(Entry::of(Path::root(), Floor::of(50))),
                ),
            ),
        ]);
});

it('holds only the security sets in a run of the security mutators alone, exempting each tree and recording no pass', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->reporting(Report::uses('recorded'))
        ->security(NewCodeFloor::of(0)));
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(100)), NamedMutators::of('Plus'), Mutators::named('Plus')),
        $settings,
        $reporting(new ReporterFake()),
    ));
    $trees = [...$verdict->trees()];
    $mutators = array_map(static fn(JudgedMutant|JudgedKill $judged): string => $judged->mutant()->mutator(), [...$trees[0]->mutants()]);

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($trees[0]->floor())->toEqual(Exempt::because('--security judges only the security sets'))
        ->and($mutators)->toBe(['Plus', 'Plus'])
        ->and([...$verdict->sets()->security()][0]->mutants())->toHaveCount(2)
        ->and([...$verdict->sets()->newCode()])->toBe([])
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->lastPassed())->toBeInstanceOf(CannotTell::class);
});

it('warns of a security set held to no floor outside CI', function () use ($tree, $reporting, $judged): void {
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(10)), NamedMutators::of('Plus')),
        judgingSettings(),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->warnings()))
        ->toContain('The security set of . has no floor yet. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.');
});

it('fails a security set below the floor security.floor declares, though its tree passes', function () use ($tree, $reporting, $judged): void {
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->reporting(Report::uses('recorded'))
        ->security(NewCodeFloor::of(60)));
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(10)), NamedMutators::of('Plus')),
        $settings,
        $reporting(new ReporterFake()),
    ));
    $sets = [...$verdict->sets()->security()];

    expect($sets[0]->judgement())->toBe(Judgement::Failed)
        ->and([...$verdict->trees()][0]->judgement())->toBe(Judgement::Passed)
        ->and($verdict->judgement())->toBe(Judgement::Failed)
        ->and(judgingTexts($verdict->warnings()))->not->toContain(
            'The security set of . has no floor yet. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.',
        );
});

it('fails a pull request until a raised security floor is committed with it', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $committed = Baseline::of(Entry::of(Path::of('src'), Floor::of(40)))->withSecurity(Entry::of(Path::root(), Floor::of(40)));
    Scratch::write($project, 'mutation-gate.baseline.json', BaselineFile::encode($committed));
    $changes = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => Flows::FILES,
        'refs/remotes/origin/main' => ['mutation-gate.baseline.json' => BaselineFile::encode($committed)],
    ]);

    $verdict = judgingVerdictOf($judged(
        Planned::twoShards()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))),
        Flows::adapters($project, [], $tree(Floor::of(30)), $changes, NamedMutators::of('Plus')),
        judgingSettings(NewCodeFloor::of(0)),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->failures()))->toBe([<<<'SAID'
        The security set of . scored 50, above the floor of 40 it was held to.
        Commit the raised floor with this change: run mutation-gate baseline --write and commit mutation-gate.baseline.json.
        SAID]);
});
