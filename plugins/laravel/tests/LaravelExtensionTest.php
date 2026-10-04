<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateLaravel\LaravelExtension;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use NightWorksIO\MutationGateLaravel\Mutators\GateAllowsToTrue;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveGuardedEntry;

it('registers every mutator of the set under its name', function (): void {
    $set = new LaravelExtension()->extend(new Extensions(Origin::of('nightworksio/mutation-gate-laravel')))
        ->registered(ExtensionPoint::MutatorSet, LaravelSet::name());
    $mutators = $set instanceof MutatorSet ? iterator_to_array($set, preserve_keys: false) : [];

    expect($mutators)->toHaveCount(14)
        ->and($mutators)->toContain(GateAllowsToTrue::class, RemoveGuardedEntry::class);
});

it('names the set laravel, and each mutator within it', function (): void {
    expect(LaravelSet::name()->value())->toBe('laravel')
        ->and(LaravelSet::mutator('GateAllowsToTrue')->value())->toBe('laravel/GateAllowsToTrue');
});
