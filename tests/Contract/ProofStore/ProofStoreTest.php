<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Adapter\Gcs\BucketLedger as GcsBucket;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Bucket;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\FixedTokens;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// What every proof store answers: an empty ledger for a scope nothing wrote,
// and back what was written to a scope, and only to it; or, for a store
// opened read-only, why it keeps nothing; for a store that leaves what it
// writes for `deliver`, what the store it reads holds; and, for a scope whose
// ledger it holds but cannot read, why and where. One line per implementation.

const UNREADABLE_KEY = 'refs/heads/main/ledger.json.gz';

/** A new directory whose main branch's ledger file holds no ledger. */
function directoryHoldingNoLedger(): string
{
    $root = Scratch::directory();
    Scratch::write($root, UNREADABLE_KEY, 'not a ledger');

    return $root;
}

afterEach(function (): void {
    Scratch::sweep();
});

$stores = [
    'the fake' => fn(): ProofStore => new ProofStoreFake(),
    'the directory' => fn(): ProofStore => LedgerDirectory::at(Scratch::directory()),
    'the bucket' => fn(): ProofStore => BucketLedger::of(new Bucket()->client(), 'ledgers', 'mutation-gate'),
    'the local ledgers' => fn(): ProofStore => LocalLedgers::of(LedgerDirectory::at(Scratch::directory()), new ProofStoreFake()),
    'the Cloud Storage bucket' => fn(): ProofStore => GcsBucket::of(new Cloud()->exchange(), 'ledgers', 'mutation-gate', FixedTokens::of('t')),
    'the Azure container' => fn(): ProofStore => ContainerLedger::of(new Cloud()->exchange(), 'gate', 'ledgers', 'mutation-gate', FixedTokens::of('t')),
    'the Azure container, with a public one' => fn(): ProofStore => ContainerLedger::of(new Cloud()->exchange(), 'gate', 'ledgers', 'mutation-gate', FixedTokens::of('t'))
        ->publishing('public')
        ->forDefaultBranch(Scope::branch('main')),
];

$readOnly = [
    'the public ledger' => fn(): ProofStore => PublicLedger::at(
        new MockHttpClient(new MockResponse('', ['http_code' => 404])),
        'https://ledgers.example.com',
        'mutation-gate',
    ),
];

/** A store that reads from this one and leaves what it writes in a delivery, under `--deliver-later`. */
$delivered = static fn(ProofStore $read): ProofStore => DeliveredLedger::over(
    $read,
    DeliveryDirectory::of(Directory::at(Scratch::directory()), Stage::Verdict),
);

$deferring = [
    'the delivered ledger' => fn(): ProofStore => $delivered(LedgerDirectory::at(Scratch::directory())),
];

$unreadable = [
    'the fake' => fn(): ProofStore => ProofStoreFake::unreadable(
        Unreadable::because(UnreadReason::Malformed, 'memory:refs/heads/main', 'not a ledger'),
    ),
    'the directory' => fn(): ProofStore => LedgerDirectory::at(directoryHoldingNoLedger()),
    'the bucket' => fn(): ProofStore => BucketLedger::of(
        new Bucket()->holding(sprintf('https://ledgers.s3.eu-west-1.amazonaws.com/mutation-gate/%s', UNREADABLE_KEY), 'not a ledger')->client(),
        'ledgers',
        'mutation-gate',
    ),
    'the local ledgers' => fn(): ProofStore => LocalLedgers::of(
        LedgerDirectory::at(directoryHoldingNoLedger()),
        new ProofStoreFake(),
    ),
    'the Cloud Storage bucket' => fn(): ProofStore => GcsBucket::of(
        new Cloud()->holding(sprintf('https://storage.googleapis.com/ledgers/mutation-gate/%s', UNREADABLE_KEY), 'not a ledger')->exchange(),
        'ledgers',
        'mutation-gate',
        FixedTokens::of('t'),
    ),
    'the Azure container' => fn(): ProofStore => ContainerLedger::of(
        new Cloud()->holding(sprintf('https://gate.blob.core.windows.net/ledgers/mutation-gate/%s', UNREADABLE_KEY), 'not a ledger')->exchange(),
        'gate',
        'ledgers',
        'mutation-gate',
        FixedTokens::of('t'),
    ),
    'the public ledger' => fn(): ProofStore => PublicLedger::at(
        new MockHttpClient(new MockResponse('not a ledger')),
        'https://ledgers.example.com',
        'mutation-gate',
    ),
    'the delivered ledger' => fn(): ProofStore => $delivered(LedgerDirectory::at(directoryHoldingNoLedger())),
];

it('reads an empty ledger for a scope nothing wrote', function (ProofStore $store): void {
    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs())->toHaveCount(0);
})->with([...$stores, ...$readOnly, ...$deferring]);

it('reads back what was written to a scope, and only to that scope', function (ProofStore $store): void {
    $key = Digest::sha256Of('src/Money.php');
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $ledger = Ledger::empty()->withProof(Proof::of($key, Path::of('src/Money.php'), Mutants::none(), $run))->atBase($run->base());

    expect($store->write(Scope::pullRequest(12), $ledger))->toBeInstanceOf(Written::class)
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(12)))->proofs()->has($key))->toBeTrue()
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs())->toHaveCount(0);
})->with($stores);

it('says why a store opened read-only keeps nothing, and reads nothing back', function (ProofStore $store): void {
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $ledger = Ledger::empty()->withProof(Proof::of(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $run));
    $written = $store->write(Scope::pullRequest(12), $ledger);

    expect($written)->toBeInstanceOf(NotWritten::class)
        ->and($written instanceof NotWritten ? $written->why() : '')->not->toBe('')
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(12)))->proofs())->toHaveCount(0);
})->with($readOnly);

it('says it wrote what it leaves for deliver, and reads what the store it reads holds, never what it wrote', function (ProofStore $store): void {
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $ledger = Ledger::empty()->withProof(Proof::of(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $run));

    expect($store->write(Scope::pullRequest(12), $ledger))->toBeInstanceOf(Written::class)
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(12)))->proofs())->toHaveCount(0)
        ->and($store->keep(Scope::pullRequest(12), Companion::Coverage, Contents::of(Gzip::pack('{"format":1}'))))->toBeInstanceOf(Written::class)
        ->and($store->companion(Scope::pullRequest(12), Companion::Coverage))->toBeInstanceOf(Missing::class);
})->with($deferring);

it('says why it cannot read a ledger it holds, and judges with none of it', function (ProofStore $store): void {
    $read = $store->read(Scope::branch('main'));

    expect(LedgerRead::unread($read)[0] ?? null)->toBe(UnreadReason::Malformed)
        ->and($read instanceof Unreadable ? $read->ledger() : null)->toEqual(Ledger::empty());
})->with($unreadable);

it('reads no coverage map beside a ledger for a scope nothing kept one in', function (ProofStore $store): void {
    expect($store->companion(Scope::branch('main'), Companion::Coverage))->toBeInstanceOf(Missing::class);
})->with([...$stores, ...$readOnly, ...$deferring]);

it('reads back the coverage map kept beside a scope\'s ledger, byte for byte, and only that scope\'s', function (ProofStore $store): void {
    $bytes = Contents::of(Gzip::pack('{"format":1}'));

    expect($store->keep(Scope::pullRequest(12), Companion::Coverage, $bytes))->toBeInstanceOf(Written::class)
        ->and($store->companion(Scope::pullRequest(12), Companion::Coverage))->toEqual($bytes)
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toBeInstanceOf(Missing::class)
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(12)))->proofs())->toHaveCount(0);
})->with($stores);

it('says why a store opened read-only keeps no coverage map, and reads none back', function (ProofStore $store): void {
    $kept = $store->keep(Scope::pullRequest(12), Companion::Coverage, Contents::of(Gzip::pack('{"format":1}')));

    expect($kept)->toBeInstanceOf(NotWritten::class)
        ->and($kept instanceof NotWritten ? $kept->why() : '')->not->toBe('')
        ->and($store->companion(Scope::pullRequest(12), Companion::Coverage))->toBeInstanceOf(Missing::class);
})->with($readOnly);
