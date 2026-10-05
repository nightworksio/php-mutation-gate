<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Gcs\BucketLedger;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\FixedTokens;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;

function gcsProved(): Ledger
{
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));

    return Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $run))
        ->atBase($run->base());
}

it('puts a scope\'s ledger at its object in the bucket with the token, gzipped, and gets it back', function (): void {
    $cloud = new Cloud();
    $tokens = FixedTokens::of('ya29');
    $store = BucketLedger::of($cloud->exchange(), 'acme-ledgers', 'gate', $tokens);

    expect($store->write(Scope::pullRequest(12), gcsProved()))->toEqual(Written::to('gs://acme-ledgers/gate/refs/pull/12/ledger.json.gz'))
        ->and(count(LedgerRead::ledger($store->read(Scope::pullRequest(12)))->proofs()))->toBe(1)
        ->and(array_map(static fn(array $request): array => [$request['method'], $request['url']], $cloud->requests))->toBe([
            ['PUT', 'https://storage.googleapis.com/acme-ledgers/gate/refs/pull/12/ledger.json.gz'],
            ['GET', 'https://storage.googleapis.com/acme-ledgers/gate/refs/pull/12/ledger.json.gz'],
        ])
        ->and([$cloud->requests[0]['headers']['authorization'], $cloud->requests[0]['headers']['content-type']])
        ->toBe(['Bearer ya29', 'application/gzip'])
        ->and($cloud->requests[1]['headers']['authorization'])->toBe('Bearer ya29');
});

it('reads within the limits it is given', function (): void {
    $cloud = new Cloud();
    BucketLedger::of($cloud->exchange(), 'acme-ledgers', 'gate', FixedTokens::of('t'))->write(Scope::branch('main'), gcsProved());
    $store = BucketLedger::of($cloud->exchange(), 'acme-ledgers', 'gate', FixedTokens::of('t'))->within(LedgerLimits::of(1_000_000, 10, 60.0));

    expect(LedgerRead::unread($store->read(Scope::branch('main')))[0])->toBe(UnreadReason::TooLarge);
});

it('names the object it could not read for want of a token', function (): void {
    $store = BucketLedger::of(new Cloud()->exchange(), 'acme-ledgers', 'gate', FixedTokens::missing('no answer'));

    expect(LedgerRead::unread($store->read(Scope::branch('main'))))->toBe([
        UnreadReason::Refused,
        'The ledger is unreadable from gs://acme-ledgers/gate/refs/heads/main/ledger.json.gz: no token: no answer. The run judges without it.',
    ]);
});

it('builds from its options and the environment\'s token, or says why it cannot', function (): void {
    $environment = Variables::of(['MUTATION_GATE_GCS_TOKEN' => 'ya29']);
    $cloud = new Cloud();
    $built = BucketLedger::configured(Configs::options('{"bucket": "acme-ledgers", "prefix": "gate"}'), $environment, $cloud->exchange());

    if ($built instanceof BucketLedger) {
        $built->write(Scope::branch('main'), gcsProved());
    }

    expect($built)->toBeInstanceOf(BucketLedger::class)
        ->and($cloud->requests[0]['url'] ?? '')->toBe('https://storage.googleapis.com/acme-ledgers/gate/refs/heads/main/ledger.json.gz')
        ->and(BucketLedger::configured(Configs::options('{"prefix": "gate"}'), $environment, $cloud->exchange()))
        ->toEqual(Invalid::because(Problem::at('bucket', 'expected the bucket, got nothing')))
        ->and(BucketLedger::configured(Configs::options('{"bucket": "acme-ledgers", "prefix": "gate"}'), Variables::of([]), $cloud->exchange()))
        ->toBeInstanceOf(Invalid::class);
});

it('keeps the coverage map beside a scope\'s ledger in the bucket, and gets it back', function (): void {
    $cloud = new Cloud();
    $store = BucketLedger::of($cloud->exchange(), 'acme-ledgers', 'gate', FixedTokens::of('ya29'));

    expect($store->keep(Scope::branch('main'), Companion::Coverage, Contents::of('map')))
        ->toEqual(Written::to('gs://acme-ledgers/gate/refs/heads/main/coverage.json.gz'))
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toEqual(Contents::of('map'))
        ->and(array_map(static fn(array $request): array => [$request['method'], $request['url']], $cloud->requests))->toBe([
            ['PUT', 'https://storage.googleapis.com/acme-ledgers/gate/refs/heads/main/coverage.json.gz'],
            ['GET', 'https://storage.googleapis.com/acme-ledgers/gate/refs/heads/main/coverage.json.gz'],
        ]);
});
