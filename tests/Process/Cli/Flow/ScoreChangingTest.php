<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Cli\Flow\ScoreChanging;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

$holds = [
    'holds:src/Adapter/Opcache/BodiesOut.php',
    'holds:src/Adapter/Opcache/Declarations.php',
    'holds:src/Core/Verdict/Judge.php',
];

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

it('scores each reached tree with the survivors proven equivalent left out', function () use ($feature, $onMain): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $onMain('src/Money.php', 'src/Held.php'));
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n")));
    $adapters = Flows::adapters(Flows::project(), [], $store, $runner, ...$feature());

    expect(new ScoreChanging($adapters, Flows::settings(), Flows::setup())->text())->toBe(
        "src scores 50.00% against its floor of 50.00%. That is ±0.00 against the base.\n"
        . "1 unit unjudged since your last run: mutation-gate watch\n"
        . 'The scores include unstaged changes in 1 file.',
    );
})->group(...$holds);
