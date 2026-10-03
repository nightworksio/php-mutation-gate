<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Proof\Credentials;

it('names each built-in proof store by the name a config chooses it by', function (BuiltinStore $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinStore::cases());

it('needs no credentials to write a directory, and the access key\'s id and secret to write a bucket', function (): void {
    $keys = Variables::of(['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret']);

    expect(BuiltinStore::Directory->credentials())->toEqual(Credentials::none())
        ->and(BuiltinStore::S3->credentials()->heldIn($keys))->toBeTrue()
        ->and(BuiltinStore::S3->credentials()->heldIn(Variables::of(['AWS_ACCESS_KEY_ID' => 'AKIA'])))->toBeFalse()
        ->and(BuiltinStore::S3->credentials()->heldIn(Variables::of(['AWS_SECRET_ACCESS_KEY' => 'secret'])))->toBeFalse();
});

it('reads the access key\'s id and secret, then a session token and a role, for a bucket, and nothing for a directory', function (): void {
    expect([...BuiltinStore::S3->variables()])
        ->toBe(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_ROLE_ARN'])
        ->and([...BuiltinStore::Directory->variables()])->toBe([]);
});

it('needs the external-account file or a ready token to write Cloud Storage', function (): void {
    $credentials = BuiltinStore::Gcs->credentials();

    expect($credentials->heldIn(Variables::of(['GOOGLE_APPLICATION_CREDENTIALS' => '/creds.json'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['MUTATION_GATE_GCS_TOKEN' => 'ya29'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['ACTIONS_ID_TOKEN_REQUEST_URL' => 'https://token'])))->toBeFalse()
        ->and([...BuiltinStore::Gcs->variables()])->toBe(['GOOGLE_APPLICATION_CREDENTIALS', 'MUTATION_GATE_GCS_TOKEN']);
});

it('needs GitHub\'s OIDC request and the tenant and client, or a ready token, to write Azure', function (): void {
    $github = [
        'ACTIONS_ID_TOKEN_REQUEST_URL' => 'https://token',
        'ACTIONS_ID_TOKEN_REQUEST_TOKEN' => 'request',
        'AZURE_TENANT_ID' => 'tenant',
        'AZURE_CLIENT_ID' => 'client',
    ];
    $credentials = BuiltinStore::Azure->credentials();

    expect($credentials->heldIn(Variables::of($github)))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['MUTATION_GATE_AZURE_TOKEN' => 'eyJ'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of([...$github, 'AZURE_CLIENT_ID' => ''])))->toBeFalse()
        ->and([...BuiltinStore::Azure->variables()])->toBe([...array_keys($github), 'MUTATION_GATE_AZURE_TOKEN']);
});
