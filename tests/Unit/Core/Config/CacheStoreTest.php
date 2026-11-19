<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\Config\CacheTrust;
use NightWorksIO\MutationGate\Core\Config\OutbreakCache;

it('resolves auto to a registry where one is named, to the Actions cache under the action, and to none elsewhere', function (): void {
    $named = OutbreakCache::of(registry: 'ghcr.io/o/p');

    expect($named->storeFor(underAction: false))->toBe(CacheStore::Oci)
        ->and(OutbreakCache::of()->storeFor(underAction: true))->toBe(CacheStore::Actions)
        ->and(OutbreakCache::of()->storeFor(underAction: false))->toBe(CacheStore::None)
        ->and(OutbreakCache::of(CacheStore::Oci)->storeFor(underAction: true))->toBe(CacheStore::None)
        ->and(OutbreakCache::of(CacheStore::Oci, 'ghcr.io/o/p')->storeFor(underAction: false))->toBe(CacheStore::Oci)
        ->and(OutbreakCache::of(CacheStore::Actions, 'ghcr.io/o/p')->storeFor(underAction: false))->toBe(CacheStore::Actions);
});

it('loosens only where the default branch reads every branch\'s packs', function (): void {
    expect(CacheTrust::Repository->isLoose())->toBeTrue()
        ->and(CacheTrust::DefaultBranch->isLoose())->toBeFalse();
});
