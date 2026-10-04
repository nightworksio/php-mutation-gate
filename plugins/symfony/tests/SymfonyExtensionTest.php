<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateSymfony\Mutators\IsGrantedToTrue;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveFlush;
use NightWorksIO\MutationGateSymfony\SymfonyExtension;
use NightWorksIO\MutationGateSymfony\SymfonySet;

it('registers every mutator of the set under its name', function (): void {
    $set = new SymfonyExtension()->extend(new Extensions(Origin::of('nightworksio/mutation-gate-symfony')))
        ->registered(ExtensionPoint::MutatorSet, SymfonySet::name());
    $mutators = $set instanceof MutatorSet ? iterator_to_array($set, preserve_keys: false) : [];

    expect($mutators)->toHaveCount(9)
        ->and($mutators)->toContain(IsGrantedToTrue::class, RemoveFlush::class);
});

it('names the set symfony, and each mutator within it', function (): void {
    expect(SymfonySet::name()->value())->toBe('symfony')
        ->and(SymfonySet::mutator('IsGrantedToTrue')->value())->toBe('symfony/IsGrantedToTrue');
});
