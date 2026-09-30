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

    expect(new PublicBucket($client)->build(Configs::options($options)))
        ->toEqual(PublicLedger::at($client, 'https://ledgers.example.com', 'gate'))
        ->and(new PublicBucket($client)->build(Configs::options('{"bucket": "ledgers", "prefix": "gate", "region": "r"}')))
        ->toEqual(PublicLedger::nowhere($client));
});

it('says why options that name no bucket open none', function (): void {
    expect(new PublicBucket(new MockHttpClient())->build(Configs::options('{"prefix": "gate", "region": "eu-west-1"}')))
        ->toEqual(Invalid::because(Problem::at('bucket', 'expected the bucket, got nothing')));
});
