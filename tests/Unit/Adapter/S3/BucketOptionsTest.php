<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\S3\BucketOptions;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$said = static fn(BucketOptions|Invalid $bucket): mixed => $bucket instanceof BucketOptions
    ? [$bucket->bucket(), $bucket->prefix(), $bucket->configuration()]
    : $bucket;

/** The `s3` store's options, as the definition reads what a config writes. */
$s3 = static fn(string $with): Options => Configs::builtin(Builtins::stores(ProjectRoot::origin()), 's3', $with);

it('keeps ledgers under the definition\'s prefix and region unless the options say otherwise', function () use (
    $said,
    $s3,
): void {
    expect($said(BucketOptions::read($s3('{"bucket": "ledgers"}'))))
        ->toBe(['ledgers', 'mutation-gate', ['region' => 'us-east-1']]);
});

it('addresses a bucket at another endpoint by path, in the region the options name', function () use (
    $said,
    $s3,
): void {
    $bucket = BucketOptions::read(
        $s3('{"bucket": "ledgers", "prefix": "ci/proofs", "region": "auto", "endpoint": "https://account.r2.example"}'),
    );

    expect($said($bucket))
        ->toBe(['ledgers', 'ci/proofs', [
            'region' => 'auto',
            'endpoint' => 'https://account.r2.example',
            'pathStyleEndpoint' => 'true',
        ]]);
});

it('refuses an http:// endpoint, which sends the signed requests and the ledgers in the clear', function () use (
    $s3,
): void {
    expect(BucketOptions::read($s3('{"bucket": "ledgers", "endpoint": "http://minio:9000"}')))
        ->toEqual(Invalid::because(Problem::at(
            'endpoint',
            "is http://, which sends the store's signed requests and the ledgers in the clear; use https://,\n"
            . 'or set insecureEndpoint: true for a store on a network you trust',
        )));
});

it('refuses an http:// endpoint in options that leave insecureEndpoint out, as no definition reads them', function (): void {
    $bucket = BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": "r", "endpoint": "http://minio"}'));

    expect($bucket instanceof Invalid ? array_map(static fn(Problem $problem): string => $problem->path(), [...$bucket]) : $bucket)
        ->toBe(['endpoint']);
});

it('addresses an http:// endpoint where insecureEndpoint says the network is trusted', function () use (
    $said,
    $s3,
): void {
    $bucket = BucketOptions::read($s3('{"bucket": "ledgers", "endpoint": "http://minio:9000", "insecureEndpoint": true}'));

    expect($said($bucket))->toBe(['ledgers', 'mutation-gate', [
        'region' => 'us-east-1',
        'endpoint' => 'http://minio:9000',
        'pathStyleEndpoint' => 'true',
    ]]);
});

it('refuses an insecureEndpoint that is not true or false', function (): void {
    expect(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": "r", "insecureEndpoint": "yes"}')))
        ->toEqual(Invalid::because(Problem::at('insecureEndpoint', 'expected true or false, got "yes"')));
});

it('requires a bucket, a prefix and a region, which the definition gives all but the first of', function (): void {
    expect(BucketOptions::read(Options::none()))->toEqual(Invalid::because(
        Problem::at('bucket', 'expected the bucket, got nothing'),
        Problem::at('prefix', 'expected the prefix, got nothing'),
        Problem::at('region', 'expected the region, got nothing'),
    ));
});

it('refuses each option that is not written as text, and nothing more', function (): void {
    expect(BucketOptions::read(Configs::options('{"bucket": 5, "prefix": "p", "region": "r"}')))
        ->toEqual(Invalid::because(Problem::at('bucket', 'expected text, got 5')))
        ->and(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": 1, "endpoint": ["minio"]}')))
        ->toEqual(Invalid::because(
            Problem::at('region', 'expected text, got 1'),
            Problem::at('endpoint', 'expected text, got a list'),
        ))
        ->and(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": "r", "endpoint": 3}')))
        ->toEqual(Invalid::because(Problem::at('endpoint', 'expected text, got 3')));
});

it('names the public URL a job without credentials reads from, and none where the options name none', function () use (
    $s3,
): void {
    $named = BucketOptions::read($s3('{"bucket": "ledgers", "publicUrl": "https://ledgers.example.com/pub"}'));
    $unnamed = BucketOptions::read($s3('{"bucket": "ledgers"}'));

    expect($named instanceof BucketOptions ? $named->publicUrl() : $named)->toBe('https://ledgers.example.com/pub')
        ->and($unnamed instanceof BucketOptions ? $unnamed->publicUrl() : $unnamed)->toEqual(NotGiven::value());
});

it('refuses a public URL not written as text, besides a missing bucket', function (): void {
    expect(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": "r", "publicUrl": 3}')))
        ->toEqual(Invalid::because(Problem::at('publicUrl', 'expected text, got 3')))
        ->and(BucketOptions::read(Configs::options('{"prefix": "p", "region": "r", "publicUrl": 3}')))
        ->toEqual(Invalid::because(
            Problem::at('bucket', 'expected the bucket, got nothing'),
            Problem::at('publicUrl', 'expected text, got 3'),
        ));
});
