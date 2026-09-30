<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A ledger proving one unit, by a run at this hour of the day. */
function ledgerProving(string $unit, int $hour): Ledger
{
    $run = Run::of('local', Instant::at(new DateTimeImmutable(sprintf('2026-09-30T%02d:00:00Z', $hour))), Digest::of(str_repeat('b', 64)));

    return Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of($unit), Path::of($unit), Mutants::none(), $run))
        ->atBase($run->base());
}

it('reads a scope\'s local ledger together with the shared store\'s', function (): void {
    $shared = new ProofStoreFake();
    $shared->write(Scope::branch('main'), ledgerProving('src/Shared.php', 9));
    $local = LedgerDirectory::at(Scratch::directory());
    $local->write(Scope::branch('main'), ledgerProving('src/Local.php', 10));

    $read = LocalLedgers::of($local, $shared)->read(Scope::branch('main'));

    expect($read->proofs()->has(Digest::sha256Of('src/Local.php')))->toBeTrue()
        ->and($read->proofs()->has(Digest::sha256Of('src/Shared.php')))->toBeTrue();
});

it('writes the local ledger alone, never the shared store', function (): void {
    $shared = new ProofStoreFake();
    $local = LedgerDirectory::at(Scratch::directory());

    LocalLedgers::of($local, $shared)->write(Scope::branch('feature'), ledgerProving('src/Money.php', 11));

    expect($local->read(Scope::branch('feature'))->proofs()->has(Digest::sha256Of('src/Money.php')))->toBeTrue()
        ->and($shared->read(Scope::branch('feature'))->proofs())->toHaveCount(0);
});

it('keeps its own ledgers in .mutation-gate/ledger', function (): void {
    $shared = new ProofStoreFake();

    expect(LocalLedgers::over($shared))->toEqual(LocalLedgers::of(LedgerDirectory::at('.mutation-gate/ledger'), $shared));
});
