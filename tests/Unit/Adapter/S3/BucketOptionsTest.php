<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\S3\BucketOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Extension\Options;

$said = static fn(BucketOptions|Invalid $bucket): mixed => $bucket instanceof BucketOptions
    ? [$bucket->bucket(), $bucket->prefix(), $bucket->configuration()]
    : $bucket;

it('keeps ledgers under mutation-gate in us-east-1 unless the options say otherwise', function () use ($said): void {
    $bucket = BucketOptions::read(Options::ofJson('{"bucket": "ledgers"}'));

    expect($said($bucket))
        ->toBe(['ledgers', 'mutation-gate', ['region' => 'us-east-1']]);
});

it('addresses a bucket at another endpoint by path, in the region the options name', function () use ($said): void {
    $bucket = BucketOptions::read(Options::ofJson(
        '{"bucket": "ledgers", "prefix": "ci/proofs", "region": "auto", "endpoint": "https://account.r2.example"}',
    ));

    expect($said($bucket))
        ->toBe(['ledgers', 'ci/proofs', [
            'region' => 'auto',
            'endpoint' => 'https://account.r2.example',
            'pathStyleEndpoint' => 'true',
        ]]);
});

it('requires a bucket', function (): void {
    $required = Invalid::because(Problem::at('bucket', 'The bucket the ledgers are kept in is required.'));

    expect(BucketOptions::read(Options::none()))->toEqual($required)
        ->and(BucketOptions::read(Options::ofJson('{"bucket": ""}')))->toEqual($required);
});

it('refuses each option that is not written as text, and nothing more', function (): void {
    expect(BucketOptions::read(Options::ofJson('{"bucket": 5}')))
        ->toEqual(Invalid::because(Problem::at('bucket', 'The bucket is written as text.')))
        ->and(BucketOptions::read(Options::ofJson('{"prefix": 5}')))
        ->toEqual(Invalid::because(Problem::at('prefix', 'The prefix is written as text.')))
        ->and(BucketOptions::read(Options::ofJson('{"bucket": "ledgers", "region": 1, "endpoint": ["minio"]}')))
        ->toEqual(Invalid::because(
            Problem::at('region', 'The region is written as text.'),
            Problem::at('endpoint', 'The endpoint is written as text.'),
        ));
});
