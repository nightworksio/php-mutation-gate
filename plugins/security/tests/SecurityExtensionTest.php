<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToTrue;
use NightWorksIO\MutationGateSecurity\Mutators\RemoveSessionRegenerate;
use NightWorksIO\MutationGateSecurity\SecurityExtension;
use NightWorksIO\MutationGateSecurity\SecuritySet;

it('registers every mutator of the set under its name', function (): void {
    $set = new SecurityExtension()->extend(new Extensions(Origin::of('nightworksio/mutation-gate-security')))
        ->registered(ExtensionPoint::MutatorSet, SecuritySet::name());
    $mutators = $set instanceof MutatorSet ? iterator_to_array($set, preserve_keys: false) : [];

    expect($mutators)->toHaveCount(10)
        ->and($mutators)->toContain(HashEqualsToTrue::class, RemoveSessionRegenerate::class);
});

it('names the set security, and each mutator within it', function (): void {
    expect(SecuritySet::name()->value())->toBe('security')
        ->and(SecuritySet::mutator('HashEqualsToTrue')->value())->toBe('security/HashEqualsToTrue');
});
