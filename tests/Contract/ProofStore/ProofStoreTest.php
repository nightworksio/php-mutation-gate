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
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Extension\ProofStoreContract;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Bucket;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\FixedTokens;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$holds = [
    'holds:src/Adapter/Azure',
    'holds:src/Adapter/Filesystem/DeliveredLedger.php',
    'holds:src/Adapter/Filesystem/DeliveryDirectory.php',
    'holds:src/Adapter/Filesystem/LedgerDirectory.php',
    'holds:src/Adapter/Filesystem/LocalLedgers.php',
    'holds:src/Adapter/Filesystem/ProjectPath.php',
    'holds:src/Adapter/Gcs',
    'holds:src/Adapter/Http',
    'holds:src/Adapter/S3',
    'holds:src/Core/Delivery/DeliveryFile.php',
    'holds:src/Core/File/Digest.php',
    'holds:src/Core/Proof/Bases.php',
    'holds:src/Core/Proof/EncodedLedger.php',
    'holds:src/Core/Proof/Ledger.php',
    'holds:src/Core/Proof/LedgerFile.php',
    'holds:src/Core/Proof/LedgerJson.php',
    'holds:src/Core/Proof/LedgerLimits.php',
    'holds:src/Core/Proof/LedgerObject.php',
    'holds:src/Core/Proof/LedgerRetention.php',
    'holds:src/Core/Proof/ObjectLedger.php',
    'holds:src/Core/Proof/ProofRecord.php',
    'holds:src/Core/Proof/Proofs.php',
    'holds:src/Core/Proof/Unreadable.php',
    'holds:src/Extension/ProofStoreContract.php',
];

// What every proof store answers, the gate's own and an extension's alike: the
// contract an extension's tests hold its store to, which ProofStoreContract
// says. One line per implementation.

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
        new MockHttpClient(static fn(): MockResponse => new MockResponse('', ['http_code' => 404])),
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

it('keeps the proof store contract', function (ProofStore $store): void {
    expect([...ProofStoreContract::failures($store)])->toBe([]);
})->with($stores)->group(...$holds);

it('keeps the contract of a store opened read-only', function (ProofStore $store): void {
    expect([...ProofStoreContract::readOnlyFailures($store)])->toBe([]);
})->with($readOnly)->group(...$holds);

it('keeps the contract of a store that leaves what it writes for deliver', function (ProofStore $store): void {
    expect([...ProofStoreContract::deliveringFailures($store)])->toBe([]);
})->with($deferring)->group(...$holds);

it('keeps the contract of a store holding a ledger it cannot read', function (ProofStore $store): void {
    expect([...ProofStoreContract::unreadableFailures($store)])->toBe([]);
})->with($unreadable)->group(...$holds);
