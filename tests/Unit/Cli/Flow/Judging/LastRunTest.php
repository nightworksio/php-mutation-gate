<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$makeTree = static fn(): Closure => JudgingRuns::tree(...);
$makeReporting = static fn(): Closure => JudgingRuns::reporting(...);

it('records the commit it judged as its scope\'s last run, with what it was made of, and none where git cannot say', function (bool $known) use (
    $makeTree,
    $makeReporting,
): void {
    $tree = $makeTree();
    $reporting = $makeReporting();

    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $checkout = $known ? Flows::checkout() : Flows::checkout()->notHaving(Revision::ref(Flows::HEAD));

    new Judging(
        Flows::adapters($project, [], $store, $checkout, $tree(Floor::of(0))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, JudgingRuns::noResults($project));
    $lastRun = LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->lastRun();

    expect($known ? $lastRun : $lastRun instanceof CannotTell)
        ->toEqual($known ? LastRun::of(JudgedCommits::of(Flows::HEAD), 'mutation / verdict', RunProfile::standard()) : true);
})->with([
    'a commit git can say what it was made of' => [true],
    'one it cannot' => [false],
]);

it('fails a pull request\'s run that stopped once it could not pass, naming the survivor, and counts a cancelled shard\'s units by their newest results', function (): void {
    $store = JudgingRuns::proven('money', 'money test');
    $verdict = JudgingRuns::verdictOf(JudgingRuns::doomed($store, Floor::whole(), cancelled: true, project: Flows::project()));
    $units = array_map(
        static fn(JudgedUnit $unit): string => sprintf('%s %s', $unit->unit()->path()->value(), $unit->origin()->value),
        [...[...$verdict->trees()][0]->units()],
    );

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([JudgingRuns::doomOf()->said(1)])
        ->and($units)->toBe(['src/Money.php run', 'src/Held.php carried'])
        ->and($verdict->wasCutShort())->toBeTrue()
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->lastRun())->toBeInstanceOf(CannotTell::class);
});

it('carries a kill of a cancelled shard\'s unit from another commit, where nothing changed since reaches it', function (): void {
    $commit = Revision::ref(str_repeat('c1', 20));
    [$project] = JudgingRuns::across('src/Tax.php', $commit);
    $files = [];

    foreach (['src/Money.php', 'src/Held.php', 'src/Equals.php', 'src/Tax.php', 'tests/MoneyTest.php', 'composer.json'] as $path) {
        $files[$path] = (string) file_get_contents(sprintf('%s/%s', $project, $path));
    }

    $checkout = new ChangeSourceFake(
        $commit,
        Changes::of(Change::modified(Path::of('src/Tax.php'), Lines::of(Line::of(4)))),
        [Revision::workingTree()->name() => $files, $commit->name() => $files, Flows::MAIN => $files],
    );
    $verdict = JudgingRuns::verdictOf(JudgingRuns::doomed(JudgingRuns::provenAcross($commit, $commit), Floor::whole(), cancelled: true, project: $project, ports: [$checkout]));
    $trees = [...$verdict->trees()];

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and($trees[0]->counts()->number(MutantJudgement::Unjudged))->toBe(0)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([JudgingRuns::doomOf()->said(1)]);
});

it('fails a doomed run by its survivor alone where a cancelled shard\'s units have no result to count', function (): void {
    $verdict = JudgingRuns::verdictOf(JudgingRuns::doomed(new ProofStoreFake(), Floor::whole(), cancelled: true, project: Flows::project()));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([JudgingRuns::doomOf()->said(1)]);
});

it('fails a run by every shard that stopped once it could not pass, though each left its result, and records its last run', function (): void {
    $store = new ProofStoreFake();
    $verdict = JudgingRuns::verdictOf(JudgingRuns::doomed($store, Floor::whole(), cancelled: false, project: Flows::project()));

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([JudgingRuns::doomOf()->said(1), JudgingRuns::doomOf('src/Held.php')->said(2)])
        ->and($verdict->wasCutShort())->toBeFalse()
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->lastRun())->toBeInstanceOf(LastRun::class);
});

it('cannot judge a run whose shard left no result where no shard stopped once it could not pass', function (): void {
    expect(JudgingRuns::doomed(new ProofStoreFake(), Floor::of(50), cancelled: true, project: Flows::project()))->toBeInstanceOf(CannotJudge::class);
});
