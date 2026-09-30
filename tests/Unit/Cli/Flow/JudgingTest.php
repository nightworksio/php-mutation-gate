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
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
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
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The one tree, `src`, held to this floor. */
$tree = static fn(Floor|Undeclared $floor): TreeSourceFake => new TreeSourceFake(
    Trees::of(Tree::at(Path::of('src'), $floor, Package::at(Path::root()))),
);

/** What chooses the verdict's reporters: this package's own, and one that remembers what it was handed. */
$reporting = static fn(Reporter $recorded): Reporting => new Reporting(
    new Chosen(new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE)))->withReporter(
        Name::of('recorded'),
        static fn(): Reporter => $recorded,
    )),
    Variables::of([]),
);

/**
 * The settings of the least config, reporting to the recorded reporter, and
 * of these besides.
 *
 * @param array<string, array<string, int|string>> $config
 */
function judgingSettings(array $config = []): Settings
{
    return Configs::flows(['reports' => [['use' => 'recorded']], ...$config]);
}

/** Every shard of a plan run, each handed its map, and then judged. */
$judged = static function (
    Plan $plan,
    Adapters $adapters,
    Settings $settings,
    Reporting $reporting,
): Judged|Invalid|CannotJudge {
    new Handoff($adapters->project)->write($plan, CoverageMap::empty());
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
        ->and(count($verdict->units()))->toBe(2)
        ->and(count($verdict->mutants()))->toBe(5)
        ->and(count($verdict->newCode()))->toBe(0)
        ->and(count($verdict->failures()))->toBe(0)
        ->and($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::Failed)
        ->and($store->read(Scope::branch('main'))->lastPassed())->toBeInstanceOf(CannotTell::class);
});

it('records a pass under the check the config names', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $store = new ProofStoreFake();

    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $store, $tree(Floor::of(40))),
        judgingSettings(['ci' => ['check' => 'gate / verdict']]),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($store->read(Scope::branch('main'))->lastPassed())
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
        judgingSettings(['proofs' => ['write' => 'never']]),
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
    $plan = Planned::twoShards();
    new Running($adapters, judgingSettings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), $adapters->project);

    $judgement = $results instanceof Results
        ? new Judging($adapters, judgingSettings(), Flows::setup(), $reporting($recorded))->verdict($plan, $results)
        : $results;

    expect($judgement)->toEqual(new Handoff(Directory::at($project))->read(ShardId::of(1)))
        ->and($judgement)->toBeInstanceOf(CannotJudge::class)
        ->and($recorded->reported)->toBe([]);
});

it('stops a CI run on a tree held to no floor', function () use ($tree, $reporting, $judged): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $tree(Undeclared::floor())),
        judgingSettings(),
        $reporting(new ReporterFake()),
    );

    expect($judgement)->toEqual(CannotJudge::because(<<<'SAID'
        src has no floor: no floor is declared for it, and the baseline holds none.
        A tree is never held to no floor. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.
        SAID));
});

it('warns of a tree held to no floor outside CI', function () use ($tree, $reporting, $judged): void {
    $verdict = judgingVerdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Undeclared::floor())),
        judgingSettings(['baseline' => ['path' => 'floors.json']]),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->warnings()))
        ->toBe(['src has no floor yet. Run mutation-gate baseline --write and commit floors.json.']);
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
        Configs::flows(['reports' => [['use' => 'nowhere']]]),
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
        ->reaching(
            Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(16)))),
            Reasons::of(Reason::that('src/Money.php changed.')),
        );

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30))),
        judgingSettings(['newCode' => ['floor' => 80]]),
        $reporting(new ReporterFake()),
    ));
    $newCode = [...$verdict->newCode()];

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
        judgingSettings(['baseline' => ['improvement' => 'report'], 'newCode' => ['floor' => 0]]),
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
        judgingSettings(['newCode' => ['floor' => 0]]),
        $reporting(new ReporterFake()),
    ));

    expect(judgingTexts($verdict->failures()))->toBe([<<<'SAID'
        The floor of src went down from 45 to 40 with no reason. A floor goes down only on purpose:
        add "lowered": { "from": 45, "reason": "…" } to its entry in the baseline.
        SAID]);
});

it('counts the proofs and the results of its own scope it took, and records how many it used', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->proving(Units::of(Planned::money()))
        ->carrying(Units::of(Planned::held()));
    $run = Run::of('github:1/1', Moment::at('2026-09-29T12:00:00Z'), $plan->base());
    $fixture = RunnerFake::ofTheFixture();
    $proofOf = static fn(string $key, string $file): Proof => Proof::of(
        Digest::sha256Of($key),
        Path::of($file),
        $fixture->mutate(MutationRequest::of(Paths::of(Path::of($file)), WholeSuite::tests()))->mutants(),
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
    $passed = $store->read(Scope::pullRequest(7))->lastPassed();

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
        ->proving(Units::of(Planned::money()));
    $run = Run::of('github:1/1', Moment::at('2026-09-29T12:00:00Z'), $plan->base());
    $mutants = RunnerFake::ofTheFixture()
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()))
        ->mutants();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(
        Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), $mutants, $run),
    ));

    $judgement = new Judging(
        Flows::adapters($project, [], $store, $tree(Floor::of(50))),
        judgingSettings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, judgingNoResults($project));

    expect($store->read(Scope::pullRequest(7))->lastPassed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 0));
});
