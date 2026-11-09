<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Compression;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ZstandardFake;
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

it('keeps its own ledgers in the project\'s .mutation-gate/ledger', function (): void {
    $shared = new ProofStoreFake();
    $project = Root::of(Scratch::directory());

    expect(LocalLedgers::over($shared, $project))->toEqual(LocalLedgers::of(LedgerDirectory::in($project, '.mutation-gate/ledger'), $shared));
});

it('reads an object beside the local ledger, or the shared store\'s where there is none locally', function (): void {
    $shared = new ProofStoreFake();
    $shared->keep(Scope::branch('main'), Companion::Coverage, Contents::of('shared map'));
    $shared->keep(Scope::branch('feature'), Companion::Coverage, Contents::of('shared feature map'));
    $local = LedgerDirectory::at(Scratch::directory());
    $local->keep(Scope::branch('main'), Companion::Coverage, Contents::of('local map'));
    $store = LocalLedgers::of($local, $shared);

    expect($store->companion(Scope::branch('main'), Companion::Coverage))->toEqual(Contents::of('local map'))
        ->and($store->companion(Scope::branch('feature'), Companion::Coverage))->toEqual(Contents::of('shared feature map'));
});

it('keeps an object beside the local ledger alone, never the shared store\'s', function (): void {
    $shared = new ProofStoreFake();
    $local = LedgerDirectory::at(Scratch::directory());

    LocalLedgers::of($local, $shared)->keep(Scope::branch('main'), Companion::Coverage, Contents::of('map'));

    expect($local->companion(Scope::branch('main'), Companion::Coverage))->toEqual(Contents::of('map'))
        ->and($shared->companion(Scope::branch('main'), Companion::Coverage))->toBeInstanceOf(Missing::class);
});

it('writes the local ledger, and reads it and a shared store that encodes, as its codec says', function (): void {
    $codec = LedgerCodec::of(Compression::Zstd, 9, ZstandardFake::here());
    $root = Scratch::directory();
    $shared = LedgerDirectory::at(Scratch::directory())->encoding($codec);
    $shared->write(Scope::branch('main'), ledgerProving('src/Shared.php', 9));
    $ledgers = LocalLedgers::of(LedgerDirectory::at($root), $shared)->encoding($codec);
    $ledgers->write(Scope::branch('main'), ledgerProving('src/Local.php', 10));
    $read = LedgerRead::ledger($ledgers->read(Scope::branch('main')));
    $plain = LedgerRead::ledger(LocalLedgers::of(LedgerDirectory::at($root), new ProofStoreFake())->encoding($codec)->read(Scope::branch('main')));

    expect(str_starts_with((string) file_get_contents(sprintf('%s/refs/heads/main/ledger.json.gz', $root)), ZstandardFake::MAGIC))->toBeTrue()
        ->and($read->proofs()->has(Digest::sha256Of('src/Local.php')))->toBeTrue()
        ->and($read->proofs()->has(Digest::sha256Of('src/Shared.php')))->toBeTrue()
        ->and($plain->proofs()->has(Digest::sha256Of('src/Local.php')))->toBeTrue();
});

it('holds its own ledger, and a shared store that limits its reads, to the limits it is given', function (): void {
    $limits = LedgerLimits::of(1, 1, 60.0);
    $local = LedgerDirectory::at(Scratch::directory());
    $shared = LedgerDirectory::at(Scratch::directory());
    $unlimited = new ProofStoreFake();
    $local->write(Scope::branch('main'), ledgerProving('src/Local.php', 10));
    $shared->write(Scope::branch('main'), ledgerProving('src/Shared.php', 9));
    $unlimited->write(Scope::branch('main'), ledgerProving('src/Shared.php', 9));
    $both = LocalLedgers::of($local, $shared)->limitedTo($limits)->read(Scope::branch('main'));
    $own = LocalLedgers::of($local, $unlimited)->limitedTo($limits)->read(Scope::branch('main'));

    expect($both instanceof Unreadable ? [$both->reason(), count($both->said())] : $both)->toBe([UnreadReason::TooLarge, 2])
        ->and($own instanceof Unreadable ? [$own->reason(), count($own->said()), $own->ledger()->proofs()->has(Digest::sha256Of('src/Shared.php'))] : $own)
        ->toBe([UnreadReason::TooLarge, 1, true]);
});
