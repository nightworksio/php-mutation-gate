<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\Config\KeyedStore;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Credentials;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Support\Bucket;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const KEYED_STORE_OPTIONS = '{"bucket": "ledgers", "prefix": "mutation-gate", "region": "eu-west-1", "publicUrl": "https://ledgers.example.com"}';

/** The S3 store over this bucket, chosen by a job with these variables, reading public URLs through this client. */
function keyedStoreIn(Bucket $bucket, Variables $environment, MockHttpClient $client, string $options): ProofStore|Invalid
{
    $keyed = KeyedStore::of(
        Credentials::of('AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'),
        static fn(): BucketLedger => BucketLedger::of($bucket->client(), 'ledgers', 'mutation-gate'),
        $client,
        $environment,
    );

    return $keyed->build(Configs::options($options));
}

it('gives a job that holds the credentials the store itself', function (): void {
    $bucket = new Bucket();
    $keys = Variables::of(['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret']);
    $store = keyedStoreIn($bucket, $keys, new MockHttpClient(), KEYED_STORE_OPTIONS);

    expect($store)->toBeInstanceOf(BucketLedger::class);
});

it('opens the store read-only through its public URL for a job without them, and sends the store nothing', function (): void {
    $bucket = new Bucket();
    $requested = [];
    $client = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
        $requested[] = sprintf('%s %s', $method, $url);

        return new MockResponse('', ['http_code' => 404]);
    });
    $store = keyedStoreIn($bucket, Variables::of(['AWS_ACCESS_KEY_ID' => 'AKIA']), $client, KEYED_STORE_OPTIONS);
    $read = $store instanceof ProofStore ? $store->read(Scope::branch('main')) : $store;
    $written = $store instanceof ProofStore ? $store->write(Scope::branch('main'), Ledger::empty()) : $store;

    expect($store)->toBeInstanceOf(PublicLedger::class)
        ->and($read)->toEqual(Ledger::empty())
        ->and($requested)->toBe(['GET https://ledgers.example.com/mutation-gate/refs/heads/main/ledger.json.gz'])
        ->and($written)->toEqual(NotWritten::because(
            'read-only: no credentials; this run\'s proofs are not kept. The ledgers are read from https://ledgers.example.com.',
        ))
        ->and($bucket->requests)->toBe([]);
});

it('reads and writes nothing for a job without the credentials where no public URL is named', function (): void {
    $bucket = new Bucket();
    $client = new MockHttpClient();
    $store = keyedStoreIn($bucket, Variables::of([]), $client, '{"bucket": "ledgers", "prefix": "mutation-gate"}');

    expect($store)->toEqual(PublicLedger::nowhere($client))
        ->and($bucket->requests)->toBe([]);
});

it('says why the store\'s options build none, with the credentials or without', function (Variables $environment): void {
    $invalid = Invalid::because(Problem::at('bucket', 'expected the bucket, got nothing'));
    $keyed = KeyedStore::of(
        Credentials::of('AWS_ACCESS_KEY_ID'),
        static fn(): Invalid => $invalid,
        new MockHttpClient(),
        $environment,
    );

    expect($keyed->build(Configs::options(KEYED_STORE_OPTIONS)))->toBe($invalid);
})->with([
    'with them' => [Variables::of(['AWS_ACCESS_KEY_ID' => 'AKIA'])],
    'without them' => [Variables::of([])],
]);

it('reads from the top of the public URL where the options name no prefix', function (): void {
    $requested = [];
    $client = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
        $requested[] = $url;

        return new MockResponse('', ['http_code' => 404]);
    });
    $store = keyedStoreIn(new Bucket(), Variables::of([]), $client, '{"bucket": "ledgers", "publicUrl": "https://ledgers.example.com"}');
    if ($store instanceof ProofStore) {
        $store->read(Scope::pullRequest(7));
    }

    expect($requested)->toBe(['https://ledgers.example.com/refs/pull/7/ledger.json.gz']);
});
