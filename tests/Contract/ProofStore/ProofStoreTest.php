<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;

// What every proof store answers: an empty ledger for a scope nothing wrote,
// and back what was written to a scope, and only to it. One line per
// implementation.

$stores = [
    'the fake' => fn(): ProofStore => new ProofStoreFake(),
];

it('reads an empty ledger for a scope nothing wrote', function (ProofStore $store): void {
    expect($store->read(Scope::of('refs/heads/main'))->proofs())->toHaveCount(0);
})->with($stores);

it('reads back what was written to a scope, and only to that scope', function (ProofStore $store): void {
    $proofs = $store;
    $ledger = Ledger::empty()->withProof(Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::none()));

    expect($proofs->write(Scope::of('refs/pull/12'), $ledger))->toBeInstanceOf(Written::class)
        ->and($proofs->read(Scope::of('refs/pull/12'))->proofs()->has(Digest::of('9c1e')))->toBeTrue()
        ->and($proofs->read(Scope::of('refs/heads/main'))->proofs())->toHaveCount(0);
})->with($stores);
