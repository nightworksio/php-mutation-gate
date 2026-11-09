<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\Config\CacheTrust;
use NightWorksIO\MutationGate\Core\NotGiven;

it('resolves auto to a registry where one is named, to the Actions cache under the action, and to none elsewhere', function (): void {
    expect(CacheStore::Auto->resolved('ghcr.io/o/p', underAction: false))->toBe(CacheStore::Oci)
        ->and(CacheStore::Auto->resolved(NotGiven::value(), underAction: true))->toBe(CacheStore::Actions)
        ->and(CacheStore::Auto->resolved(NotGiven::value(), underAction: false))->toBe(CacheStore::None)
        ->and(CacheStore::Oci->resolved(NotGiven::value(), underAction: true))->toBe(CacheStore::None)
        ->and(CacheStore::Actions->resolved('ghcr.io/o/p', underAction: false))->toBe(CacheStore::Actions);
});

it('loosens only where the default branch reads every branch\'s packs', function (): void {
    expect(CacheTrust::Repository->isLoose())->toBeTrue()
        ->and(CacheTrust::DefaultBranch->isLoose())->toBeFalse();
});
