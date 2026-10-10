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
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Outcome;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A plan over the project, with these ports in place of the fakes. */
$plan = (static fn(string $project, Mode $mode, Cut $cut, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), $cut, MatrixKind::FirstKiller)));

it('carries a pull request\'s own results for what it changed before its last run, however newer the default branch\'s, and reaches what no longer reads as they record', function (bool $heldAsItWas, bool $heldChanged, array $run, array $carriedOwn) use ($plan): void {
    $money = Unit::file(Path::of('src/Money.php'));
    $held = Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'));
    $project = Flows::project();
    $full = $plan($project, Mode::full(), Cut::exactly(1));
    $digests = $full instanceof Plan ? $full->digests() : Undigested::proof();
    $base = $full instanceof Plan ? $full->base() : Digest::of('none');
    $mutation = $digests instanceof Digests ? $digests->mutation() : Digest::sha256Of('none');
    $sourceOf = static function (Unit $unit) use ($digests): Digest {
        $source = $digests instanceof Digests ? $digests->sourceOf($unit->path()) : Digest::sha256Of('none');

        return $source instanceof Digest ? $source : Digest::sha256Of('none');
    };
    $proof = static fn(Unit $unit, Digest $source, string $scope, string $at): Proof => Proof::of(
        Digest::of(sprintf('%s-%s', $unit->path()->value(), $scope)),
        $unit->path(),
        Mutants::none(),
        Run::of($scope, Moment::at($at), $base),
    )->withInputs(Inputs::of($source, $mutation));
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proof($money, Digest::sha256Of('main'), 'main', '2026-10-03T10:00:00Z'))
        ->withProof($proof($held, Digest::sha256Of('main'), 'main', '2026-10-03T10:00:00Z')));
    $store->write(Scope::pullRequest(7), Ledger::empty()
        ->withProof($proof($money, $sourceOf($money), 'pr', '2026-10-01T10:00:00Z'))
        ->withProof($proof($held, $heldAsItWas ? $sourceOf($held) : Digest::sha256Of('before'), 'pr', '2026-10-01T10:00:00Z'))
        ->withRuns(ScopeRuns::none()->lastRunAt(LastRun::of(JudgedCommits::of('last-run'), Flows::settings()->ci()->check(), RunProfile::standard()))));
    $sinceRef = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), $heldChanged
        ? $sinceRef->with(Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(1))))
        : $sinceRef, [
            Revision::workingTree()->name() => Flows::FILES,
            Flows::MAIN => Flows::FILES,
            'last-run' => Flows::FILES,
        ])->changedFrom(Revision::ref('last-run'), Changes::none());
    $pullRequest = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

    $planned = $plan($project, Mode::since(Mode::LAST_RUN), Cut::exactly(1), $store, $checkout, $pullRequest);

    $digested = $planned instanceof Plan ? $planned->digests() : Undigested::proof();

    expect($planned instanceof Plan ? [...[...$planned][0]->units()] : $planned)->toEqual($run)
        ->and($planned instanceof Plan ? [...$planned->considered()->carriedOwn()] : $planned)->toEqual($carriedOwn)
        ->and($digested instanceof Digests ? $digested->sourceOf(Path::of('src/Money.php')) : $digested)->toEqual($sourceOf($money));
})->with([
    'both as their own results record them' => [true, true, [], fn(): array => [Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')), Unit::file(Path::of('src/Money.php'))]],
    'one changed since its own result, as a race leaves it' => [false, true, fn(): array => [Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'))], fn(): array => [Unit::file(Path::of('src/Money.php'))]],
    'one unchanged since the ref, which carries the newest result of either' => [false, false, [], fn(): array => [Unit::file(Path::of('src/Money.php'))]],
]);

it('prunes the clean mutators of a run with a base in each unit whose newest result is of its code, and none in a full run', function (): void {
    $project = Flows::project();
    $settings = Flows::settings(Pruning::window(2));
    $planWith = static fn(Mode $mode, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
        Flows::adapters($project, [], ...$ports),
        $settings,
        Flows::setup(),
    )->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
    $full = $planWith(Mode::full());
    $digests = $full instanceof Plan ? $full->digests() : Undigested::proof();
    $proof = Proof::of(
        Digest::of('an older key'),
        Path::of('src/Money.php'),
        Mutants::none(),
        Run::of('main', Moment::at('2026-09-29T12:00:00Z'), Digest::of(str_repeat('b', 64))),
    )->withInputs($digests instanceof Digests ? $digests->inputsOf(Path::of('src/Money.php'), Paths::none()) : $digests);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proof)->withLearned(Survival::none()->after(
        Name::of('fake'),
        Window::of(2),
        Outcome::killed('Plus', 'a'),
        Outcome::killed('Plus', 'b'),
        Outcome::killed('Minus', 'c'),
        Outcome::through('Minus', 'd'),
    )));
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );
    $scoped = $planWith(Mode::since('base'), $store, $checkout);
    $everything = $planWith(Mode::full(), $store, $checkout);

    expect($scoped instanceof Plan ? $scoped->considered()->pruned() : $scoped)
        ->toEqual(Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/Money.php'))))
        ->and($everything instanceof Plan ? $everything->considered()->pruned() : $everything)->toEqual(Pruned::none())
        ->and($scoped instanceof Plan ? PlanFile::decode(PlanFile::encode($scoped)) : $scoped)->toEqual($scoped);
});
