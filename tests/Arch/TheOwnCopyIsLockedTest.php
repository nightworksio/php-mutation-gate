<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// The gate's own copy the jobs that hold a credential install, from the
// manifest and the lock under resources/action/keyed (ADR-0007): the gate's
// runtime packages and the S3 store's client, each at the constraint the
// package states, and nothing it develops with.

/** The package's own manifest. */
const PACKAGE_MANIFEST = 'composer.json';

/** The manifest the gate's own copy installs from. */
const KEYED_MANIFEST = 'resources/action/keyed/composer.json';

/** The lock beside it. */
const KEYED_LOCK = 'resources/action/keyed/composer.lock';

/** The packages the S3 store runs on, which the package requires for development alone. */
const STORE_CLIENT = ['async-aws/core', 'async-aws/s3'];

/** What a JSON file of the repository holds at a path of keys. */
function keyedJsonAt(string $file, string ...$keys): mixed
{
    return Decoded::at((string) file_get_contents(Tree::at($file)), ...$keys);
}

it('requires every runtime package the gate requires, at its constraint, and the client of the AWS store', function (): void {
    $required = keyedJsonAt(PACKAGE_MANIFEST, 'require');
    $developed = keyedJsonAt(PACKAGE_MANIFEST, 'require-dev');
    $store = is_array($developed) ? array_intersect_key($developed, array_flip(STORE_CLIENT)) : [];
    $wanted = is_array($required) ? [...$required, ...$store] : $store;
    $keyed = keyedJsonAt(KEYED_MANIFEST, 'require');
    ksort($wanted);

    if (is_array($keyed)) {
        ksort($keyed);
    }

    expect($store)->toHaveCount(count(STORE_CLIENT))
        ->and($keyed)->toBe($wanted)
        ->and(keyedJsonAt(KEYED_MANIFEST, 'require-dev'))->toBeNull();
});

it('is the gate itself, autoloaded and resolved for the platform the package is', function (): void {
    expect(keyedJsonAt(KEYED_MANIFEST, 'name'))->toBe(ThisPackage::COMPOSER)
        ->and(keyedJsonAt(KEYED_MANIFEST, 'autoload'))->toBe(keyedJsonAt(PACKAGE_MANIFEST, 'autoload'))
        ->and(keyedJsonAt(KEYED_MANIFEST, 'config', 'platform'))->toBe(keyedJsonAt(PACKAGE_MANIFEST, 'config', 'platform'));
});

it('locks the client of the AWS store, and no package for development', function (): void {
    $lock = (string) file_get_contents(Tree::at(KEYED_LOCK));

    expect(Decoded::column($lock, 'name', 'packages'))->toContain(...STORE_CLIENT)
        ->and(Decoded::at($lock, 'packages-dev'))->toBe([]);
});
