<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Bucket;
use NightWorksIO\MutationGate\Tests\Support\Configs;

const IN_THE_BUCKET = 'https://ledgers.s3.eu-west-1.amazonaws.com/mutation-gate/refs/heads/main/ledger.json.gz';

$proved = static fn(): Ledger => Ledger::empty()->withProof(Proof::of(
    Digest::sha256Of('src/Money.php'),
    Path::of('src/Money.php'),
    Mutants::none(),
    Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64))),
))->atBase(Digest::of(str_repeat('b', 64)));

$store = static fn(Bucket $bucket): BucketLedger => BucketLedger::of($bucket->client(), 'ledgers', 'mutation-gate');

it('writes a scope\'s ledger as one gzipped object under the prefix, and says where', function () use (
    $proved,
    $store,
): void {
    $bucket = new Bucket();

    expect($store($bucket)->write(Scope::branch('main'), $proved()))
        ->toEqual(Written::to('s3://ledgers/mutation-gate/refs/heads/main/ledger.json.gz'))
        ->and($bucket->requests)->toBe([[
            'method' => 'PUT',
            'url' => IN_THE_BUCKET,
            'body' => LedgerFile::encode($proved()),
            'type' => 'Content-Type: application/gzip',
        ]]);
});

it('keeps the prefix without its slashes, and no prefix at all where it is empty', function () use ($proved): void {
    $bucket = new Bucket();

    expect(BucketLedger::of($bucket->client(), 'ledgers', '/ci/proofs/')->write(Scope::pullRequest(12), $proved()))
        ->toEqual(Written::to('s3://ledgers/ci/proofs/refs/pull/12/ledger.json.gz'))
        ->and(BucketLedger::of($bucket->client(), 'ledgers', '')->write(Scope::pullRequest(12), $proved()))
        ->toEqual(Written::to('s3://ledgers/refs/pull/12/ledger.json.gz'))
        ->and(array_column($bucket->requests, 'url'))->toBe([
            'https://ledgers.s3.eu-west-1.amazonaws.com/ci/proofs/refs/pull/12/ledger.json.gz',
            'https://ledgers.s3.eu-west-1.amazonaws.com/refs/pull/12/ledger.json.gz',
        ]);
});

it('addresses a bucket at another endpoint by path', function () use ($proved): void {
    $bucket = new Bucket();
    $client = $bucket->client('{"bucket": "ledgers", "endpoint": "http://minio.test:9000"}');
    BucketLedger::of($client, 'ledgers', 'mutation-gate')->write(Scope::branch('main'), $proved());

    expect(array_column($bucket->requests, 'url'))
        ->toBe(['http://minio.test:9000/ledgers/mutation-gate/refs/heads/main/ledger.json.gz']);
});

it('reads back a scope\'s ledger from its object', function () use ($proved, $store): void {
    $bucket = new Bucket()->holding(IN_THE_BUCKET, LedgerFile::encode($proved()));

    expect(LedgerFile::encode($store($bucket)->read(Scope::branch('main'))))
        ->toBe(LedgerFile::encode($proved()))
        ->and(array_column($bucket->requests, 'method'))->toBe(['GET'])
        ->and(array_column($bucket->requests, 'url'))->toBe([IN_THE_BUCKET]);
});

it('reads an empty ledger for a scope with no object', function () use ($store): void {
    $bucket = new Bucket();

    expect($store($bucket)->read(Scope::branch('main')))->toEqual(Ledger::empty())
        ->and($bucket->requests)->toHaveCount(1);
});

it('reads an empty ledger where the bucket fails, costing a run and never a verdict', function () use ($store): void {
    expect($store(new Bucket(500))->read(Scope::branch('main')))->toEqual(Ledger::empty());
});

it('says why a ledger the bucket refused was not written', function () use ($proved, $store): void {
    expect($store(new Bucket(500))->write(Scope::branch('main'), $proved()))
        ->toEqual(NotWritten::because(sprintf(
            's3://ledgers/mutation-gate/refs/heads/main/ledger.json.gz could not be written: HTTP 500 returned for "%s".',
            IN_THE_BUCKET,
        )));
});

it('neither reads nor writes a scope that is not a ref', function () use ($proved, $store): void {
    $bucket = new Bucket()->holding(IN_THE_BUCKET, LedgerFile::encode($proved()));

    expect($store($bucket)->read(Scope::of('refs/heads/main/../main')))->toEqual(Ledger::empty())
        ->and($store($bucket)->write(Scope::of('refs/tags/v1'), $proved()))
        ->toEqual(NotWritten::because(
            '"refs/tags/v1" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
        ))
        ->and($bucket->requests)->toBe([]);
});

it('is built from its options, which require a bucket', function (): void {
    $stores = Builtins::stores(ProjectRoot::origin());

    expect(BucketLedger::fromOptions(Configs::builtin($stores, 's3', '{"bucket": "ledgers"}')))
        ->toBeInstanceOf(BucketLedger::class)
        ->and(BucketLedger::fromOptions(Configs::options('{"prefix": "p", "region": "r"}')))
        ->toEqual(Invalid::because(Problem::at('bucket', 'expected the bucket, got nothing')));
});
