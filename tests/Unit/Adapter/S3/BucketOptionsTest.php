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
    expect(BucketOptions::read(Configs::options('{"bucket": 5, "prefix": "p", "region": "auto"}')))
        ->toEqual(Invalid::because(Problem::at('bucket', 'expected text, got 5')))
        ->and(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": 1, "endpoint": ["minio"]}')))
        ->toEqual(Invalid::because(
            Problem::at('region', 'expected text, got 1'),
            Problem::at('endpoint', 'expected text, got a list'),
        ))
        ->and(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": "auto", "endpoint": 3}')))
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
    expect(BucketOptions::read(Configs::options('{"bucket": "b", "prefix": "p", "region": "auto", "publicUrl": 3}')))
        ->toEqual(Invalid::because(Problem::at('publicUrl', 'expected text, got 3')))
        ->and(BucketOptions::read(Configs::options('{"prefix": "p", "region": "auto", "publicUrl": 3}')))
        ->toEqual(Invalid::because(
            Problem::at('bucket', 'expected the bucket, got nothing'),
            Problem::at('publicUrl', 'expected text, got 3'),
        ));
});

it('refuses a region the host cannot hold, so the keys and session token reach only the store', function (string $region): void {
    expect(BucketOptions::read(Configs::options((string) json_encode(['bucket' => 'ledgers', 'prefix' => 'p', 'region' => $region]))))
        ->toEqual(Invalid::because(Problem::at('region', sprintf('expected a region, got %s', json_encode($region, JSON_UNESCAPED_SLASHES)))));
})->with([
    'another host and a query' => ['evil.example/x?'],
    'user information' => ['us-east-1@evil.example'],
    'a subdomain' => ['us-east-1.evil'],
    'a fragment' => ['eu-west-1#'],
    'a port' => ['eu-west-1:443'],
    'white space' => ['eu west 1'],
    'a doubled hyphen' => ['eu--west-1'],
    'a slash after another host' => ['evil.com/'],
    'a signature in a query' => ['x?sig='],
    'a bare fragment' => ['a#'],
    'two dots' => ['..'],
    'uppercase' => ['US-EAST-1'],
    'a slash' => ['eu-west-1/x'],
    'a dot' => ['eu-west-1.evil'],
]);

it('reads AWS\'s regions, Cloudflare\'s auto and a MinIO region alike', function (string $region): void {
    $options = BucketOptions::read(Configs::options((string) json_encode(['bucket' => 'ledgers', 'prefix' => 'p', 'region' => $region])));

    expect($options instanceof BucketOptions ? $options->configuration()['region'] : $options)->toBe($region);
})->with(['us-east-1', 'eusc-de-east-1', 'us-gov-west-1', 'auto', 'minio']);
