<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\Keying;
use NightWorksIO\MutationGate\Cli\Flow\ScoreChanging;
use NightWorksIO\MutationGate\Config\Ignore;
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
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\ScoreChangeText;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A working tree on a feature branch that changed a line of src/Money.php since it left `main`. */
$feature = static fn(): array => [
    new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main'))),
    new ChangeSourceFake(
        Revision::ref(Flows::MAIN),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(11)))),
        [Revision::workingTree()->name() => Flows::FILES, Flows::MAIN => Flows::FILES],
    ),
];

/** The ledger of `main`, proving each of these files as the fake runner judges it, the day before. */
$onMain = static function (string ...$files): Ledger {
    $ledger = Ledger::empty();

    foreach ($files as $file) {
        $ledger = $ledger->withProof(Proof::of(
            Digest::sha256Of(sprintf('main %s', $file)),
            Path::of($file),
            Flows::mutantsOf($file),
            Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('main')),
        ));
    }

    return $ledger;
};

/** The ledger of the feature branch, proving src/Money.php as it is on disk, with these mutants. */
$provedHere = static function (Adapters $adapters, Mutants $mutants): Ledger {
    $inventory = Inventory::of($adapters, Flows::settings());
    $keying = $inventory instanceof Inventory
        ? Keying::of($adapters, Flows::settings(), Flows::setup(), $inventory->suite, Flows::map())
        : $inventory;
    $key = $keying instanceof Keying ? $keying->keysOf(Units::of(Unit::file(Path::of('src/Money.php'))))->keyOf(Path::of('src/Money.php')) : $keying;
    $base = $keying instanceof Keying ? $keying->base() : Digest::sha256Of('none');

    return Ledger::empty()
        ->withProof(Proof::of(
            $key instanceof Digest ? $key : Digest::sha256Of('none'),
            Path::of('src/Money.php'),
            $mutants,
            Run::of('local', Moment::at('2026-09-30T10:00:00Z'), $base),
        ))
        ->atBase($base);
};

it('shows each reached tree against the base, judged by the local result for what is on disk', function () use (
    $feature,
    $onMain,
    $provedHere,
): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $onMain('src/Money.php', 'src/Held.php'));
    $adapters = Flows::adapters(Flows::project(), [], $store, ...$feature());
    $store->write(Scope::branch('feature'), $provedHere($adapters, Mutants::none()));

    expect(new ScoreChanging($adapters, Flows::settings(), Flows::setup())->text())->toBe(
        "src scores 0.00%, below its floor of 50.00%. That is -40.00 against the base.\nThe scores include unstaged changes in 1 file.",
    );
});

it('carries the newest result for a reached unit with no local one, and counts it as unjudged', function () use (
    $feature,
    $onMain,
): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $onMain('src/Money.php', 'src/Held.php'));
    $adapters = Flows::adapters(Flows::project(), [], $store, ...$feature());

    expect(new ScoreChanging($adapters, Flows::settings(), Flows::setup())->text())->toBe(
        "src scores 40.00%, below its floor of 50.00%. That is ±0.00 against the base.\n"
        . "1 unit unjudged since your last run: mutation-gate watch\n"
        . 'The scores include unstaged changes in 1 file.',
    );
});

it('scores each reached tree with the survivors the config ignores left out', function () use ($feature, $onMain): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $onMain('src/Money.php', 'src/Held.php'));
    $adapters = Flows::adapters(Flows::project(), [], $store, ...$feature());
    $settings = Flows::settings(
        Ignore::mutant('49e02fb39669', 'The bound is never reached'),
        Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same', '2026-09-29'),
    );

    expect(new ScoreChanging($adapters, $settings, Flows::setup())->text())->toBe(
        "src scores 50.00% against its floor of 50.00%. That is ±0.00 against the base.\n"
        . "1 unit unjudged since your last run: mutation-gate watch\n"
        . 'The scores include unstaged changes in 1 file.',
    );
});

it('shows no score for a tree with a unit that has no result anywhere', function () use ($feature, $onMain): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $onMain('src/Money.php'));
    $adapters = Flows::adapters(Flows::project(), [], $store, ...$feature());

    expect(new ScoreChanging($adapters, Flows::settings(), Flows::setup())->text())->toBe(
        "src is not measured yet: a unit of it has no result.\n"
        . "2 units unjudged since your last run: mutation-gate watch\n"
        . 'The scores include unstaged changes in 1 file.',
    );
});

it('says when the change reaches no tree', function () use ($onMain): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $onMain('src/Money.php', 'src/Held.php'));
    $unchanged = new ChangeSourceFake(Revision::ref(Flows::MAIN), Changes::none(), [Revision::workingTree()->name() => Flows::FILES]);

    expect(new ScoreChanging(Flows::adapters(Flows::project(), [], $store, $unchanged), Flows::settings(), Flows::setup())->text())
        ->toBe('The change reaches no tree.');
});

it('shows no score where no local run has left a coverage map', function (): void {
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CannotJudge::because('There is no map.'));

    expect(new ScoreChanging(Flows::adapters(Flows::project(), [], $runner), Flows::settings(), Flows::setup())->text())
        ->toBe(ScoreChangeText::unmapped());
});

it('cannot say where the units cannot be found', function (): void {
    $trees = new TreeSourceFake(CannotJudge::because('No tree is declared.'));

    expect(new ScoreChanging(Flows::adapters(Flows::project(), [], $trees), Flows::settings(), Flows::setup())->text())
        ->toEqual(CannotJudge::because('No tree is declared.'));
});

it('cannot say where the baseline cannot be read', function (): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', '{"format": 9}');

    expect(new ScoreChanging(Flows::adapters($project), Flows::settings(), Flows::setup())->text())
        ->toBeInstanceOf(CannotJudge::class);
});

it('cannot say where the run cannot be keyed', function (): void {
    $project = Flows::project();
    Scratch::write($project, '.github/workflows/gate.yml/blocked', '');
    $ci = Flows::ci()->runBy(Paths::of(Path::of('.github/workflows/gate.yml')));

    expect(new ScoreChanging(Flows::adapters($project, [], $ci), Flows::settings(), Flows::setup())->text())
        ->toEqual(CannotJudge::because(sprintf('%s/.github/workflows/gate.yml could not be read.', $project)));
});
