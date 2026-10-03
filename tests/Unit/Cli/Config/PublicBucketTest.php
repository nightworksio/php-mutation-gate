<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Cli\Config\PublicBucket;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use Symfony\Component\HttpClient\MockHttpClient;

it('opens the bucket read-only through its public URL, under its prefix', function (): void {
    $client = new MockHttpClient();
    $options = '{"bucket": "ledgers", "prefix": "gate", "region": "eu-west-1", "publicUrl": "https://ledgers.example.com"}';

    expect(new PublicBucket($client)->s3(Configs::options($options)))
        ->toEqual(PublicLedger::at($client, 'https://ledgers.example.com', 'gate'))
        ->and(new PublicBucket($client)->s3(Configs::options('{"bucket": "ledgers", "prefix": "gate", "region": "r"}')))
        ->toEqual(PublicLedger::nowhere($client));
});

it('says why options that name no bucket open none', function (): void {
    expect(new PublicBucket(new MockHttpClient())->s3(Configs::options('{"prefix": "gate", "region": "eu-west-1"}')))
        ->toEqual(Invalid::because(Problem::at('bucket', 'expected the bucket, got nothing')));
});

it('opens Cloud Storage and Azure read-only through their public URLs, under their prefixes', function (): void {
    $client = new MockHttpClient();
    $public = new PublicBucket($client);

    expect($public->gcs(Configs::options('{"bucket": "acme", "prefix": "gate", "publicUrl": "https://storage.googleapis.com/acme"}')))
        ->toEqual(PublicLedger::at($client, 'https://storage.googleapis.com/acme', 'gate'))
        ->and($public->gcs(Configs::options('{"bucket": "acme", "prefix": "gate"}')))->toEqual(PublicLedger::nowhere($client))
        ->and($public->azure(Configs::options('{"account": "acme", "container": "ledgers", "prefix": "gate", "publicUrl": "https://acme.blob.core.windows.net/public"}')))
        ->toEqual(PublicLedger::at($client, 'https://acme.blob.core.windows.net/public', 'gate'))
        ->and($public->azure(Configs::options('{"account": "acme", "container": "ledgers", "prefix": "gate"}')))->toEqual(PublicLedger::nowhere($client));
});

it('says why Cloud Storage or Azure options that miss what they need open none', function (): void {
    $public = new PublicBucket(new MockHttpClient());

    expect($public->gcs(Configs::options('{"prefix": "gate"}')))->toEqual(Invalid::because(Problem::at('bucket', 'expected the bucket, got nothing')))
        ->and($public->azure(Configs::options('{"container": "ledgers", "prefix": "gate"}')))
        ->toEqual(Invalid::because(Problem::at('account', 'expected the account, got nothing')));
});
