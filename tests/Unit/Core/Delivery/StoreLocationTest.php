<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Delivery\StoreLocation;

/** The store these variables locate, as its name and its options' JSON, or why there is none. */
function storeLocationOf(Variables $environment): string
{
    $chosen = StoreLocation::chosen($environment);

    return $chosen instanceof Choice
        ? sprintf('%s %s', $chosen->use()->value(), $chosen->options()->written()->line())
        : $chosen->why();
}

it('locates each store by its variables alone, with what they leave out as the config\'s definition has it', function (Variables $environment, string $located): void {
    expect(storeLocationOf($environment))->toBe($located);
})->with([
    's3' => [
        Variables::of(['MUTATION_GATE_STORE' => 's3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs']),
        's3 {"prefix":"mutation-gate","region":"us-east-1","insecureEndpoint":false,"bucket":"proofs"}',
    ],
    's3 at another endpoint' => [
        Variables::of([
            'MUTATION_GATE_STORE' => 's3',
            'MUTATION_GATE_STORE_BUCKET' => 'proofs',
            'MUTATION_GATE_STORE_PREFIX' => 'ci/proofs',
            'MUTATION_GATE_STORE_REGION' => 'auto',
            'MUTATION_GATE_STORE_ENDPOINT' => 'https://r2.example.com',
        ]),
        's3 {"prefix":"ci/proofs","region":"auto","insecureEndpoint":false,"bucket":"proofs","endpoint":"https://r2.example.com"}',
    ],
    'gcs' => [
        Variables::of(['MUTATION_GATE_STORE' => 'gcs', 'MUTATION_GATE_STORE_BUCKET' => 'proofs']),
        'gcs {"prefix":"mutation-gate","bucket":"proofs"}',
    ],
    'azure' => [
        Variables::of([
            'MUTATION_GATE_STORE' => 'azure',
            'MUTATION_GATE_STORE_ACCOUNT' => 'acme',
            'MUTATION_GATE_STORE_CONTAINER' => 'proofs',
            'MUTATION_GATE_STORE_PUBLIC_CONTAINER' => 'public',
        ]),
        'azure {"prefix":"mutation-gate","account":"acme","container":"proofs","publicContainer":"public"}',
    ],
    'an empty variable, as unset' => [
        Variables::of(['MUTATION_GATE_STORE' => 'gcs', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_PREFIX' => '']),
        'gcs {"prefix":"mutation-gate","bucket":"proofs"}',
    ],
]);

it('locates no store its variables do not name as a built-in one that needs credentials', function (Variables $environment): void {
    expect(storeLocationOf($environment))
        ->toBe('MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.');
})->with([
    'none' => [Variables::of([])],
    'the directory' => [Variables::of(['MUTATION_GATE_STORE' => 'directory'])],
    'a class' => [Variables::of(['MUTATION_GATE_STORE' => 'Acme\\Store'])],
    'another case' => [Variables::of(['MUTATION_GATE_STORE' => 'S3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs'])],
]);

it('sends no credential to an endpoint that is not https://, and takes one for s3 alone', function (Variables $environment, string $why): void {
    expect(storeLocationOf($environment))->toBe($why);
})->with([
    'http' => [
        Variables::of(['MUTATION_GATE_STORE' => 's3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_ENDPOINT' => 'http://minio.internal']),
        'MUTATION_GATE_STORE_ENDPOINT is not an https:// URL, so no store is located there.',
    ],
    'no scheme' => [
        Variables::of(['MUTATION_GATE_STORE' => 's3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_ENDPOINT' => 'evil.example']),
        'MUTATION_GATE_STORE_ENDPOINT is not an https:// URL, so no store is located there.',
    ],
    'https in capitals' => [
        Variables::of(['MUTATION_GATE_STORE' => 's3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_ENDPOINT' => 'HTTP://evil.example']),
        'MUTATION_GATE_STORE_ENDPOINT is not an https:// URL, so no store is located there.',
    ],
    'https and nothing after' => [
        Variables::of(['MUTATION_GATE_STORE' => 's3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_ENDPOINT' => 'https://']),
        'MUTATION_GATE_STORE_ENDPOINT is not an https:// URL, so no store is located there.',
    ],
    'gcs' => [
        Variables::of(['MUTATION_GATE_STORE' => 'gcs', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_ENDPOINT' => 'https://evil.example']),
        'MUTATION_GATE_STORE_ENDPOINT is set, and only the s3 store takes an endpoint.',
    ],
    'azure' => [
        Variables::of(['MUTATION_GATE_STORE' => 'azure', 'MUTATION_GATE_STORE_ACCOUNT' => 'acme', 'MUTATION_GATE_STORE_CONTAINER' => 'proofs', 'MUTATION_GATE_STORE_ENDPOINT' => 'https://evil.example']),
        'MUTATION_GATE_STORE_ENDPOINT is set, and only the s3 store takes an endpoint.',
    ],
]);

it('names the variable behind each option the store refuses', function (Variables $environment, string $why): void {
    expect(storeLocationOf($environment))->toBe($why);
})->with([
    'no bucket' => [Variables::of(['MUTATION_GATE_STORE' => 's3']), 'MUTATION_GATE_STORE_BUCKET: expected a bucket name, got nothing'],
    'an account under s3' => [
        Variables::of(['MUTATION_GATE_STORE' => 's3', 'MUTATION_GATE_STORE_BUCKET' => 'proofs', 'MUTATION_GATE_STORE_ACCOUNT' => 'acme']),
        'MUTATION_GATE_STORE_ACCOUNT: unknown key',
    ],
    'a region under gcs, and a bucket that is a host' => [
        Variables::of(['MUTATION_GATE_STORE' => 'gcs', 'MUTATION_GATE_STORE_BUCKET' => 'evil.example:443', 'MUTATION_GATE_STORE_REGION' => 'auto']),
        'MUTATION_GATE_STORE_BUCKET: expected a Cloud Storage bucket name, got "evil.example:443"; MUTATION_GATE_STORE_REGION: unknown key',
    ],
    'an account that is a host' => [
        Variables::of(['MUTATION_GATE_STORE' => 'azure', 'MUTATION_GATE_STORE_ACCOUNT' => 'evil.example/x?', 'MUTATION_GATE_STORE_CONTAINER' => 'proofs']),
        'MUTATION_GATE_STORE_ACCOUNT: expected a storage account name, got "evil.example/x?"',
    ],
]);

it('reads none of a store\'s options that no variable of its location sets', function (): void {
    expect(storeLocationOf(Variables::of([
        'MUTATION_GATE_STORE' => 's3',
        'MUTATION_GATE_STORE_BUCKET' => 'proofs',
        'MUTATION_GATE_STORE_PUBLIC_URL' => 'https://evil.example',
        'MUTATION_GATE_STORE_INSECURE_ENDPOINT' => 'true',
    ])))->toBe('s3 {"prefix":"mutation-gate","region":"us-east-1","insecureEndpoint":false,"bucket":"proofs"}')
        ->and(StoreLocation::chosen(Variables::of([])))->toBeInstanceOf(CannotJudge::class);
});
