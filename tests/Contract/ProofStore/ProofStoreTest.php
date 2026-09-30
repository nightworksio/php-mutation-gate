<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Bucket;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

// What every proof store answers: an empty ledger for a scope nothing wrote,
// and back what was written to a scope, and only to it. One line per
// implementation.

afterEach(function (): void {
    Scratch::sweep();
});

$stores = [
    'the fake' => fn(): ProofStore => new ProofStoreFake(),
    'the directory' => fn(): ProofStore => LedgerDirectory::at(Scratch::directory()),
    'the bucket' => fn(): ProofStore => BucketLedger::of(new Bucket()->client(), 'ledgers', 'mutation-gate'),
];

it('reads an empty ledger for a scope nothing wrote', function (ProofStore $store): void {
    expect($store->read(Scope::branch('main'))->proofs())->toHaveCount(0);
})->with($stores);

it('reads back what was written to a scope, and only to that scope', function (ProofStore $store): void {
    $key = Digest::sha256Of('src/Money.php');
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $ledger = Ledger::empty()->withProof(Proof::of($key, Path::of('src/Money.php'), Mutants::none(), $run))->atBase($run->base());

    expect($store->write(Scope::pullRequest(12), $ledger))->toBeInstanceOf(Written::class)
        ->and($store->read(Scope::pullRequest(12))->proofs()->has($key))->toBeTrue()
        ->and($store->read(Scope::branch('main'))->proofs())->toHaveCount(0);
})->with($stores);
