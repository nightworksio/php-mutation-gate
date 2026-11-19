<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$makeMoney = static fn(): Unit => Unit::file(Path::of('src/Money.php'));
$makeHeld = static fn(): Unit => Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'));

/** The units each shard of a plan runs, by the shard's number. */
$shards = static function (Plan|CannotJudge $plan): array {
    $units = [];

    foreach ($plan instanceof Plan ? $plan : [] as $shard) {
        $units[$shard->id()->number()] = [...$shard->units()];
    }

    return $plan instanceof Plan ? $units : [$plan->why()];
};

/** A plan over the project, with these ports in place of the fakes. */
$plan = (static fn(string $project, Mode $mode, Cut $cut, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), $cut, MatrixKind::FirstKiller)));

/** The default branch's ledger, proving each of these units with no mutant, so none is at risk from its last result. */
$settled = static function (string ...$units): ProofStoreFake {
    $store = new ProofStoreFake();
    $ledger = Ledger::empty();

    foreach ($units as $unit) {
        $ledger = $ledger->withProof(Proof::of(
            Digest::sha256Of(sprintf('%s before', $unit)),
            Path::of($unit),
            Mutants::none(),
            Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('main')),
        ));
    }

    $store->write(Scope::branch('main'), $ledger);

    return $store;
};

it('lists each shard\'s units the riskiest first, a unit never mutated before a settled one', function () use (
    $plan,
    $shards,
    $settled,
    $makeMoney,
    $makeHeld,
): void {
    $money = $makeMoney();
    $held = $makeHeld();

    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $settled('src/Held.php'));

    expect($shards($planned))->toEqual([1 => [$money, $held]]);
});

it('lists the least risky units the most recently changed first, by what git says', function (
    string $moneyChanged,
    string $heldChanged,
    bool $moneyFirst,
) use ($plan, $shards, $settled, $makeMoney, $makeHeld): void {
    $money = $makeMoney();
    $held = $makeHeld();

    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::none(),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES, Flows::MAIN => Flows::FILES],
        ['src/Money.php' => $moneyChanged, 'src/Held.php' => $heldChanged],
    );
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $settled('src/Held.php', 'src/Money.php'), $checkout);

    expect($shards($planned))->toEqual([1 => $moneyFirst ? [$money, $held] : [$held, $money]]);
})->with([
    'the money file changed last' => ['2026-09-20T10:00:00Z', '2026-09-01T10:00:00Z', true],
    'the held path changed last' => ['2026-09-01T10:00:00Z', '2026-09-20T10:00:00Z', false],
]);

it('lists the least risky units by path where git cannot say when they last changed', function () use (
    $plan,
    $shards,
    $settled,
    $makeMoney,
    $makeHeld,
): void {
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::none(),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES, Flows::MAIN => Flows::FILES],
        CannotTell::because('git log failed.'),
    );
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $settled('src/Held.php', 'src/Money.php'), $checkout);

    expect($shards($planned))->toEqual([1 => [$makeHeld(), $makeMoney()]]);
});

it('lists a unit whose lines the change touched first, before one never mutated', function () use (
    $plan,
    $shards,
    $makeMoney,
    $makeHeld,
): void {
    $money = $makeMoney();
    $held = $makeHeld();

    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );

    expect($shards($plan(Flows::project(), Mode::since('base'), Cut::exactly(1), new ProofStoreFake(), $checkout)))
        ->toEqual([1 => [$money, $held]]);
});
