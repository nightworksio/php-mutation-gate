<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The commit the default branch's ledger last passed at, before main moved on. */
const LEDGER_PASSED = '1edcafe0d0b1c4e7a9f3b6d8e0c2a4f6b8d0e2c4';

/**
 * A pull request's plan, which changed only the README since main, in a
 * project of these files, against a default branch whose ledger proves every
 * unit as it is and last passed at this commit, since which main changed
 * these files.
 *
 * @param  array<string, string> $files
 * @return array{Plan|CannotJudge, list<string>}
 */
function plannedSinceLedger(array $files, string $passedAt, Change ...$onMain): array
{
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $plan = static fn(object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
        Flows::adapters($project, [], ...$ports),
        Flows::settings(),
        Flows::setup(),
    )->plan(Mode::since(Flows::MAIN), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
    $readme = Change::modified(Path::of('README.md'), Lines::of(Line::of(1)));
    $checkout = new ChangeSourceFake(
        Revision::ref(Flows::MAIN),
        Changes::of($readme),
        [Revision::workingTree()->name() => $files, Flows::MAIN => $files, $passedAt => $files],
    )->changedFrom(Revision::ref($passedAt), Changes::of($readme, ...$onMain));
    $full = Planned::from(new Planning(Flows::adapters($project, [], $checkout), Flows::settings(), Flows::setup())
        ->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
    $digests = $full instanceof Plan ? $full->digests() : Undigested::proof();
    $proof = static fn(string $unit): Proof => Proof::of(Digest::sha256Of($unit), Path::of($unit), Mutants::none(), Run::of('main', Moment::at('2026-11-02T16:57:00Z'), Digest::sha256Of('the ledger\'s base')))
        ->withInputs($digests instanceof Digests ? $digests->inputsOf(Path::of($unit), Paths::none()) : $digests);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proof('src/Money.php'))
        ->withProof($proof('src/Held.php'))
        ->withRuns(ScopeRuns::none()->passing(Passed::of(Revision::ref($passedAt), 'mutation / verdict', 0))));
    $planned = $plan($store, $checkout, new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))));

    return [
        $planned,
        $planned instanceof Plan ? array_map(static fn(Reason $reason): string => $reason->text(), [...$planned->considered()->reach()]) : [],
    ];
}

/**
 * The units a plan runs, in its one shard; why there is no plan, where there is none.
 *
 * @return list<string>
 */
function runSinceLedger(Plan|CannotJudge $planned): array
{
    return $planned instanceof Plan ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...[...$planned][0]->units()]) : [$planned->why()];
}

it('reaches a source file the default branch changed since its ledger, as a change of the pull request would, and carries the unit that calls it', function (): void {
    $files = [...Flows::FILES, 'src/Money.php' => "<?php\n\nfinal class Money\n{\n    public function held(): Held\n    {\n        return new Held();\n    }\n}\n"];
    [$planned, $reasons] = plannedSinceLedger($files, LEDGER_PASSED, Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(3))));

    expect(runSinceLedger($planned))->toBe(['src/Held.php'])
        ->and($planned instanceof Plan ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$planned->considered()->carried()]) : $planned)
        ->toBe(['src/Money.php'])
        ->and($reasons)->toContain(sprintf('On the default branch since its ledger last passed, at %s: `src/Held.php` changed, so its unit is reached.', LEDGER_PASSED))
        ->and($planned instanceof Plan ? $planned->considered()->changed() : $planned)
        ->toEqual(Changes::of(Change::modified(Path::of('README.md'), Lines::of(Line::of(1)))));
});

it('reaches what a killing test the default branch changed since its ledger runs', function (): void {
    $files = [
        ...Flows::FILES,
        'phpunit.xml' => "<phpunit><testsuites><testsuite name=\"Unit\"><directory suffix=\"Spec.php\">tests</directory></testsuite></testsuites></phpunit>\n",
        'tests/MoneySpec.php' => "<?php\n\nfinal class MoneySpec\n{\n    public function itAdds(): void\n    {\n    }\n}\n",
    ];
    [$planned, $reasons] = plannedSinceLedger($files, LEDGER_PASSED, Change::modified(Path::of('tests/MoneySpec.php'), Lines::of(Line::of(3))));

    expect(runSinceLedger($planned))->toBe(['src/Held.php', 'src/Money.php'])
        ->and($reasons)->toContain(sprintf('On the default branch since its ledger last passed, at %s: The coverage map places no test in `tests/MoneySpec.php` by its id, so the 2 files the runner judges by it are reached.', LEDGER_PASSED));
});

it('reaches nothing more where the default branch\'s ledger last passed at the commit the pull request is read against', function (): void {
    [$planned, $reasons] = plannedSinceLedger(Flows::FILES, Flows::MAIN);

    expect(runSinceLedger($planned))->toBe([])
        ->and($planned instanceof Plan ? count($planned->considered()->carried()) : $planned)->toBe(2)
        ->and($reasons)->toBe(['`README.md` reaches nothing by itself.']);
});

it('reaches everything where git cannot tell what the default branch changed since its ledger', function (): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()->passing(Passed::of(Revision::ref('gone'), 'mutation / verdict', 0))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), Changes::none(), [Revision::workingTree()->name() => Flows::FILES, Flows::MAIN => Flows::FILES]);
    $planned = Planned::from(new Planning(
        Flows::adapters($project, [], $store, $checkout, new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))),
        Flows::settings(),
        Flows::setup(),
    )->plan(Mode::since(Flows::MAIN), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

    expect(runSinceLedger($planned))->toBe(['src/Held.php', 'src/Money.php']);
});
