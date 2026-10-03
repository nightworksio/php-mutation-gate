<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Gcs\GcsOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads the bucket, the prefix and the public URL', function (): void {
    $options = GcsOptions::read(Configs::options('{"bucket": "acme-ledgers", "prefix": "gate", "publicUrl": "https://storage.googleapis.com/acme-ledgers"}'));
    $bare = GcsOptions::read(Configs::options('{"bucket": "acme-ledgers", "prefix": "gate"}'));

    expect($options instanceof GcsOptions ? [$options->bucket(), $options->prefix(), $options->publicUrl()] : [])
        ->toBe(['acme-ledgers', 'gate', 'https://storage.googleapis.com/acme-ledgers'])
        ->and($bare instanceof GcsOptions ? $bare->publicUrl() : null)->toEqual(NotGiven::value());
});

it('says why options that miss a bucket or a prefix, or give a public URL that is no text, open none', function (string $options, Problem $problem): void {
    expect(GcsOptions::read(Configs::options($options)))->toEqual(Invalid::because($problem));
})->with([
    'no bucket' => ['{"prefix": "gate"}', Problem::at('bucket', 'expected the bucket, got nothing')],
    'no prefix' => ['{"bucket": "acme-ledgers"}', Problem::at('prefix', 'expected the prefix, got nothing')],
    'a public URL that is no text' => ['{"bucket": "b", "prefix": "p", "publicUrl": 3}', Problem::at('publicUrl', 'expected text, got 3')],
]);
