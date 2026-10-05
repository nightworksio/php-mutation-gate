<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
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

function azureProved(): Ledger
{
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));

    return Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $run))
        ->atBase($run->base());
}

it('puts a scope\'s ledger as a block blob with the token and the service\'s version, and gets it back', function (): void {
    $cloud = new Cloud();
    $store = ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('eyJ'));
    $blob = 'https://acme.blob.core.windows.net/ledgers/gate/refs/pull/12/ledger.json.gz';

    expect($store->write(Scope::pullRequest(12), azureProved()))->toEqual(Written::to($blob))
        ->and(count(LedgerRead::ledger($store->read(Scope::pullRequest(12)))->proofs()))->toBe(1)
        ->and(array_map(static fn(array $request): array => [$request['method'], $request['url']], $cloud->requests))
        ->toBe([['PUT', $blob], ['GET', $blob]])
        ->and($cloud->requests[0]['headers'])->toMatchArray([
            'authorization' => 'Bearer eyJ',
            'content-type' => 'application/gzip',
            'x-ms-version' => '2024-11-04',
            'x-ms-blob-type' => 'BlockBlob',
        ])
        ->and($cloud->requests[1]['headers'])->toMatchArray(['authorization' => 'Bearer eyJ', 'x-ms-version' => '2024-11-04']);
});

it('keeps the default branch\'s scope in the public container, and every other scope in the private one', function (): void {
    $cloud = new Cloud();
    $store = ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('eyJ'))
        ->publishing('public')
        ->forDefaultBranch(Scope::branch('main'));

    $store->write(Scope::branch('main'), azureProved());
    $store->write(Scope::branch('feature'), azureProved());

    expect(array_column($cloud->requests, 'url'))->toBe([
        'https://acme.blob.core.windows.net/public/gate/refs/heads/main/ledger.json.gz',
        'https://acme.blob.core.windows.net/ledgers/gate/refs/heads/feature/ledger.json.gz',
    ])
        ->and(count(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()))->toBe(1);
});

it('keeps every scope in the private container where no public one is named, or the default branch is not known', function (): void {
    $cloud = new Cloud();
    ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('t'))
        ->forDefaultBranch(Scope::branch('main'))
        ->write(Scope::branch('main'), azureProved());
    ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('t'))
        ->publishing('public')
        ->write(Scope::branch('main'), azureProved());

    expect(array_column($cloud->requests, 'url'))->toBe([
        'https://acme.blob.core.windows.net/ledgers/gate/refs/heads/main/ledger.json.gz',
        'https://acme.blob.core.windows.net/ledgers/gate/refs/heads/main/ledger.json.gz',
    ]);
});

it('reads within the limits it is given, and names the blob it could not read for want of a token', function (): void {
    $cloud = new Cloud();
    ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('t'))->write(Scope::branch('main'), azureProved());
    $limited = ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('t'))->within(LedgerLimits::of(1_000_000, 10, 60.0));
    $tokenless = ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::missing('no answer'));

    expect(LedgerRead::unread($limited->read(Scope::branch('main')))[0])->toBe(UnreadReason::TooLarge)
        ->and(LedgerRead::unread($tokenless->read(Scope::branch('main'))))->toBe([
            UnreadReason::Refused,
            'The ledger is unreadable from https://acme.blob.core.windows.net/ledgers/gate/refs/heads/main/ledger.json.gz: no token: no answer. The run judges without it.',
        ]);
});

it('builds from its options and the environment\'s token, keeping the default branch in its public container', function (): void {
    $environment = Variables::of(['MUTATION_GATE_AZURE_TOKEN' => 'eyJ']);
    $cloud = new Cloud();
    $built = ContainerLedger::configured(
        Configs::options('{"account": "acme", "container": "ledgers", "prefix": "gate", "publicContainer": "public"}'),
        $environment,
        $cloud->exchange(),
    );

    if ($built instanceof ContainerLedger) {
        $built->forDefaultBranch(Scope::branch('main'))->write(Scope::branch('main'), azureProved());
    }

    expect($cloud->requests[0]['url'] ?? '')->toBe('https://acme.blob.core.windows.net/public/gate/refs/heads/main/ledger.json.gz')
        ->and($cloud->requests[0]['headers']['authorization'] ?? '')->toBe('Bearer eyJ')
        ->and(ContainerLedger::configured(Configs::options('{"container": "ledgers", "prefix": "gate"}'), $environment, $cloud->exchange()))
        ->toEqual(Invalid::because(Problem::at('account', 'expected the account, got nothing')))
        ->and(ContainerLedger::configured(Configs::options('{"account": "acme", "container": "ledgers", "prefix": "gate"}'), Variables::of([]), $cloud->exchange()))
        ->toBeInstanceOf(Invalid::class);
});

it('keeps the coverage map beside a scope\'s ledger as a block blob, and gets it back', function (): void {
    $cloud = new Cloud();
    $store = ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('eyJ'));
    $blob = 'https://acme.blob.core.windows.net/ledgers/gate/refs/heads/main/coverage.json.gz';

    expect($store->keep(Scope::branch('main'), Companion::Coverage, Contents::of('map')))->toEqual(Written::to($blob))
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toEqual(Contents::of('map'))
        ->and(array_map(static fn(array $request): array => [$request['method'], $request['url']], $cloud->requests))
        ->toBe([['PUT', $blob], ['GET', $blob]]);
});
