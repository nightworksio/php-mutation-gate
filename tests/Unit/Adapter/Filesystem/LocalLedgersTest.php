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
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
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

    $read = LedgerRead::ledger(LocalLedgers::of($local, $shared)->read(Scope::branch('main')));

    expect($read->proofs()->has(Digest::sha256Of('src/Local.php')))->toBeTrue()
        ->and($read->proofs()->has(Digest::sha256Of('src/Shared.php')))->toBeTrue();
});

it('writes the local ledger alone, never the shared store', function (): void {
    $shared = new ProofStoreFake();
    $local = LedgerDirectory::at(Scratch::directory());

    LocalLedgers::of($local, $shared)->write(Scope::branch('feature'), ledgerProving('src/Money.php', 11));

    expect(LedgerRead::ledger($local->read(Scope::branch('feature')))->proofs()->has(Digest::sha256Of('src/Money.php')))
        ->toBeTrue()
        ->and(LedgerRead::ledger($shared->read(Scope::branch('feature')))->proofs())->toHaveCount(0);
});

it('says why the shared store could not be read, and keeps the local ledger beside it', function (): void {
    $local = LedgerDirectory::at(Scratch::directory());
    $local->write(Scope::branch('main'), ledgerProving('src/Local.php', 10));
    $unread = Unreadable::because(UnreadReason::TimedOut, 'https://ledgers.example.com', 'no answer came in time');

    $read = LocalLedgers::of($local, ProofStoreFake::unreadable($unread))->read(Scope::branch('main'));

    expect(LedgerRead::unread($read))->toBe(LedgerRead::unread($unread))
        ->and($read instanceof Unreadable ? $read->ledger() : null)->toEqual(ledgerProving('src/Local.php', 10));
});

it('says why the local ledger could not be read, and keeps the shared store\'s beside it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'refs/heads/main/ledger.json.gz', 'not a ledger');
    $shared = new ProofStoreFake();
    $shared->write(Scope::branch('main'), ledgerProving('src/Shared.php', 9));

    $read = LocalLedgers::of(LedgerDirectory::at($root), $shared)->read(Scope::branch('main'));

    expect(LedgerRead::unread($read)[0] ?? null)->toBe(UnreadReason::Malformed)
        ->and($read instanceof Unreadable ? $read->ledger() : null)->toEqual(ledgerProving('src/Shared.php', 9));
});

it('says why for each, where neither can be read, the shared store\'s first', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'refs/heads/main/ledger.json.gz', 'not a ledger');
    $unread = Unreadable::because(UnreadReason::TimedOut, 'https://ledgers.example.com', 'no answer came in time');

    $read = LocalLedgers::of(LedgerDirectory::at($root), ProofStoreFake::unreadable($unread))->read(Scope::branch('main'));
    $said = $read instanceof Unreadable ? [...$read->said()] : [];

    expect(count($said))->toBe(2)
        ->and($said[0]->text())->toBe($unread->why())
        ->and($said[1]->text())->toContain(sprintf('%s/refs/heads/main/ledger.json.gz', $root));
});

it('keeps its own ledgers in .mutation-gate/ledger', function (): void {
    $shared = new ProofStoreFake();

    expect(LocalLedgers::over($shared))->toEqual(LocalLedgers::of(LedgerDirectory::at('.mutation-gate/ledger'), $shared));
});
