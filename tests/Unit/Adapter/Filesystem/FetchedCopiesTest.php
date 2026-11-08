<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\FetchedCopies;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Compression;
use NightWorksIO\MutationGate\Core\Proof\Copies;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ZstandardFake;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A ledger whose run at this moment proved this unit. */
function copyProving(string $unit, string $at): Ledger
{
    return Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of(sprintf('%s at %s', $unit, $at)), Path::of($unit), Mutants::none(), Run::of('main', Moment::at($at), Digest::sha256Of('base'))))
        ->atBase(Digest::sha256Of('base'));
}

/**
 * The units a scope's ledger proves, as these copies read it, in order.
 *
 * @return list<string>
 */
function unitsReadFrom(ProofStore $copies): array
{
    $units = array_map(static fn(Proof $proof): string => $proof->unit()->value(), [...LedgerRead::ledger($copies->read(Scope::branch('main')))->proofs()]);
    sort($units);

    return $units;
}

/** A directory holding the default branch's ledger, as fetch writes the store's copy. */
function fetchedHolding(Ledger $ledger): LedgerDirectory
{
    $fetched = LedgerDirectory::at(Scratch::directory());
    $fetched->write(Scope::branch('main'), $ledger);

    return $fetched;
}

/** The store a dataset's factory keeps this ledger of the default branch in. */
function keptCopyIn(Closure $kept, Ledger $ledger): ProofStore
{
    $store = $kept($ledger);

    return $store instanceof ProofStore ? $store : throw new RuntimeException('The factory made no store.');
}

/** The kept stores a run may keep its ledgers in, each holding the default branch's ledger. */
$keptStores = [
    'a directory, as the Actions cache keeps it' => [static function (Ledger $ledger): ProofStore {
        $kept = LedgerDirectory::at(Scratch::directory());
        $kept->write(Scope::branch('main'), $ledger);

        return $kept;
    }],
    'a store of another kind' => [static fn(Ledger $ledger): ProofStore => new ProofStoreFake()->keeping(Scope::branch('main'), $ledger)],
];

it('reads, as the 2 November runs on main left them, the cache\'s copy of 19:06 over the store\'s of 16:57 where the newest wins, the store\'s where they move together, and both where they are merged', function (Closure $kept): void {
    $cache = keptCopyIn($kept, copyProving('src/A.php', '2026-11-02T16:57:00Z')->and(copyProving('src/B.php', '2026-11-02T19:06:00Z')));
    $store = fetchedHolding(copyProving('src/A.php', '2026-11-02T16:57:00Z'));

    expect(unitsReadFrom(FetchedCopies::of($cache, $store, Copies::Newest)))->toBe(['src/A.php', 'src/B.php'])
        ->and(unitsReadFrom(FetchedCopies::of($cache, $store, Copies::Together)))->toBe(['src/A.php'])
        ->and(unitsReadFrom(FetchedCopies::of($cache, fetchedHolding(copyProving('src/C.php', '2026-11-02T16:57:00Z')), Copies::Merged)))
        ->toBe(['src/A.php', 'src/B.php', 'src/C.php']);
})->with($keptStores);

it('reads the kept copy alone where fetch wrote none', function (Closure $kept): void {
    $copies = FetchedCopies::of(keptCopyIn($kept, copyProving('src/A.php', '2026-11-02T16:57:00Z')), LedgerDirectory::at(Scratch::directory()), Copies::Together);

    expect(unitsReadFrom($copies))->toBe(['src/A.php']);
})->with($keptStores);

it('says why the fetched copy could not be read, as one of another format, and reads the kept copy beside it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'refs/heads/main/ledger.json.gz', 'not a ledger');
    $kept = new ProofStoreFake()->keeping(Scope::branch('main'), copyProving('src/A.php', '2026-11-02T16:57:00Z'));

    $read = FetchedCopies::of($kept, LedgerDirectory::at($root), Copies::Newest)->read(Scope::branch('main'));

    expect(LedgerRead::unread($read)[0] ?? null)->toBe(UnreadReason::Malformed)
        ->and($read instanceof Unreadable ? $read->ledger() : null)->toEqual(copyProving('src/A.php', '2026-11-02T16:57:00Z'));
});

it('says why the kept copy could not be read, and reads the fetched copy beside it', function (): void {
    $unread = Unreadable::because(UnreadReason::TimedOut, 'https://ledgers.example.com', 'no answer came in time');
    $fetched = fetchedHolding(copyProving('src/A.php', '2026-11-02T16:57:00Z'));

    $read = FetchedCopies::of(ProofStoreFake::unreadable($unread), $fetched, Copies::Newest)->read(Scope::branch('main'));

    expect(LedgerRead::unread($read))->toBe(LedgerRead::unread($unread))
        ->and($read instanceof Unreadable ? $read->ledger() : null)->toEqual(copyProving('src/A.php', '2026-11-02T16:57:00Z'));
});

it('says why for each, where neither copy can be read, the fetched first', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'refs/heads/main/ledger.json.gz', 'not a ledger');
    $unread = Unreadable::because(UnreadReason::TimedOut, 'https://ledgers.example.com', 'no answer came in time');

    $read = FetchedCopies::of(ProofStoreFake::unreadable($unread), LedgerDirectory::at($root), Copies::Newest)->read(Scope::branch('main'));
    $said = $read instanceof Unreadable ? [...$read->said()] : [];

    expect(count($said))->toBe(2)
        ->and($said[0]->text())->toContain(sprintf('%s/refs/heads/main/ledger.json.gz', $root))
        ->and($said[1]->text())->toBe($unread->why());
});

it('writes, keeps and reads companions in the kept store alone', function (): void {
    $kept = LedgerDirectory::at(Scratch::directory());
    $fetchedRoot = Scratch::directory();
    $copies = FetchedCopies::of($kept, LedgerDirectory::at($fetchedRoot), Copies::Newest);

    $copies->write(Scope::branch('feature'), copyProving('src/A.php', '2026-11-02T16:57:00Z'));
    $copies->keep(Scope::branch('feature'), Companion::Coverage, Contents::of('map'));

    expect(unitsReadFrom(FetchedCopies::of($kept, LedgerDirectory::at($fetchedRoot), Copies::Newest)))->toBe([])
        ->and(LedgerRead::ledger($kept->read(Scope::branch('feature')))->proofs())->toHaveCount(1)
        ->and(LedgerDirectory::at($fetchedRoot)->held(Scope::branch('feature')))->toBeInstanceOf(Missing::class)
        ->and($copies->companion(Scope::branch('feature'), Companion::Coverage))->toEqual(Contents::of('map'));
});

it('reads the copies fetch writes into the project\'s .mutation-gate/fetched, wherever the gate runs from', function (): void {
    $kept = new ProofStoreFake();
    $project = Scratch::directory();
    $fetched = copyProving('src/Money.php', '2026-11-06T09:00:00Z');
    LedgerDirectory::in(Root::of($project), '.mutation-gate/fetched')->write(Scope::branch('main'), $fetched);

    expect(FetchedCopies::over($kept, Copies::Merged, Root::of($project)))
        ->toEqual(FetchedCopies::of($kept, LedgerDirectory::in(Root::of($project), '.mutation-gate/fetched'), Copies::Merged))
        ->and(LedgerRead::ledger(FetchedCopies::over($kept, Copies::Newest, Root::of($project))->read(Scope::branch('main'))))
        ->toEqual($fetched)
        ->and(LedgerRead::ledger(FetchedCopies::over($kept, Copies::Newest, Root::of(Scratch::directory()))->read(Scope::branch('main'))))
        ->toEqual(Ledger::empty());
});

it('reads the kept store\'s copy and the fetched one, and writes the kept one, as its codec says', function (): void {
    $codec = LedgerCodec::of(Compression::Zstd, 9, ZstandardFake::here());
    $keptRoot = Scratch::directory();
    $fetchedRoot = Scratch::directory();
    LedgerDirectory::at($fetchedRoot)->encoding($codec)->write(Scope::branch('main'), copyProving('src/Fetched.php', '2026-10-01T10:00:00Z'));
    $kept = FetchedCopies::of(LedgerDirectory::at($keptRoot), LedgerDirectory::at(Scratch::directory()), Copies::Newest)->encoding($codec);
    $kept->write(Scope::branch('main'), copyProving('src/Kept.php', '2026-10-01T11:00:00Z'));
    $fetched = FetchedCopies::of(new ProofStoreFake(), LedgerDirectory::at($fetchedRoot), Copies::Newest)->encoding($codec);

    expect(str_starts_with((string) file_get_contents(sprintf('%s/refs/heads/main/ledger.json.gz', $keptRoot)), ZstandardFake::MAGIC))->toBeTrue()
        ->and(unitsReadFrom($kept))->toBe(['src/Kept.php'])
        ->and(unitsReadFrom($fetched))->toBe(['src/Fetched.php']);
});
