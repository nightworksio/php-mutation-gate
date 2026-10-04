<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Measured;
use NightWorksIO\MutationGate\Cli\Flow\MeasuredFloors;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('shows each tree\'s and each security set\'s committed floor beside its last measured score, or why it has none', function (): void {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('local', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));
    $trees = new TreeSourceFake(Trees::of(
        Tree::at(Path::of('src/Money.php'), Floor::of(50), Package::at(Path::root())),
        Tree::at(Path::of('src/Held.php'), Floor::of(50), Package::at(Path::of('src'))),
    ));
    $committed = Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(40)))
        ->withSecurity(Entry::of(Path::of('src'), Floor::of(80)));
    $adapters = Flows::adapters(Flows::project(), [], $store, $trees, NamedMutators::of('Plus'));
    $measured = Measured::of($adapters, Flows::settings(), $committed, new DateTimeImmutable(Configs::NOW));

    expect($measured instanceof Measured ? MeasuredFloors::of($committed, $measured) : $measured)->toBe([
        'src/Money.php: floor 40, measured 50',
        'src/Held.php: floor none, not measured: a unit of it has no result yet. Run mutation-gate to measure it.',
        'security set of .: floor none, measured 100',
        'security set of src: floor 80, not measured: a unit of it has no result yet. Run mutation-gate to measure it.',
    ]);
});
