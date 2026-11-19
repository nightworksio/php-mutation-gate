<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Pruning;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Pruning\Outcome;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A mutant of src/Money.php as a run left it. */
function prunedSurvivorMutant(MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0),
        '1',
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-"),
        $status,
        Seconds::of(1.0),
    );
}

it('takes a mutator as pruned once a survivor leaves its window, and still judges the unit that holds that survivor again, so a test added to kill it counts', function (MutantStatus $held, array $files): void {
    $project = Flows::project();
    $settings = Flows::settings(Pruning::window(2));
    $plan = static fn(Mode $mode, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
        Flows::adapters($project, [], ...$ports),
        $settings,
        Flows::setup(),
    )->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
    $full = $plan(Mode::full());
    $digests = $full instanceof Plan ? $full->digests() : Undigested::proof();
    $proof = Proof::of(
        Digest::of('an older key'),
        Path::of('src/Money.php'),
        Mutants::of(prunedSurvivorMutant($held)),
        Run::of('main', Moment::at('2026-09-29T12:00:00Z'), Digest::of(str_repeat('b', 64))),
    )->withInputs($digests instanceof Digests ? $digests->inputsOf(Path::of('src/Money.php'), Paths::none()) : $digests);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proof)->withLearned(Survival::none()->after(
        Name::of('fake'),
        Window::of(2),
        Outcome::through('Plus', 'the survivor this unit holds'),
        Outcome::killed('Plus', 'a'),
        Outcome::killed('Plus', 'b'),
    )));
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );

    $planned = $plan(Mode::since('base'), $store, $checkout);

    expect($planned)->toBeInstanceOf(Plan::class)
        ->and($planned instanceof Plan ? array_map(static fn(Path $file): string => $file->value(), [...$planned->considered()->pruned()->files()]) : $planned)
        ->toBe($files);
})->with([
    'a survivor' => [MutantStatus::Survived, []],
    'a mutant no test covers' => [MutantStatus::Uncovered, []],
    'a kill' => [MutantStatus::Killed, ['src/Money.php']],
]);
