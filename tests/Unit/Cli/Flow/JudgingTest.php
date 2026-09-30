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
use NightWorksIO\MutationGate\Config\Baseline as BaselineSetting;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Floor as NewCodeFloor;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Config\Uncovered;
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
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

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

/** The settings of the least config, reporting to the recorded reporter, and of these besides. */
function judgingSettings(NewCodeFloor|Setting ...$parts): Settings
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
        judgingSettings(Ci::check('gate / verdict')),
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
        ->and($store->read(Scope::branch('main'))->proofs())->toHaveCount(2);
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
        ->reaching(
            Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(16)))),
            Reasons::of(Reason::that('src/Money.php changed.')),
        );

    $verdict = judgingVerdictOf($judged(
        $plan,
        Flows::adapters(Flows::project(), [], $tree(Floor::of(30))),
        judgingSettings(NewCodeFloor::of(80)),
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
        ->and($store->read(Scope::pullRequest(7)))->toEqual(Ledger::empty());
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

    expect($store->read(Scope::pullRequest(7))->lastPassed())
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
        ->proving(Units::of(Planned::money()))
        ->carrying(Units::of(Planned::held()));

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
        ->and($store->read(Scope::pullRequest(7)))->toEqual(Ledger::empty());
});

it('takes a planned proof from the run\'s own scope where it has moved there from the default branch', function () use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->proving(Units::of(Planned::money()));
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
        ->and($store->read(Scope::pullRequest(7))->lastPassed())
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
            [...$store->read(Scope::branch('main'))->proofs()],
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
        static fn(JudgedMutant $mutant): bool => $mutant->mutant()->location()->file()->value() === 'src/Money.php',
    ));
    $ledger = $store->read(Scope::branch('main'));

    expect(array_unique(array_map(static fn(JudgedMutant $mutant): string => $mutant->judgement()->value, $money)))
        ->toBe(['flaky'])
        ->and($ledger->proofs()->has(Digest::sha256Of('money')))->toBeFalse()
        ->and($ledger->proofs()->has(Digest::sha256Of('held')))->toBeTrue();
});
