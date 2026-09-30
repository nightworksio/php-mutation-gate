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
