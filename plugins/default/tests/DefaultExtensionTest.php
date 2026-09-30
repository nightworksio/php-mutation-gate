<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateDefault\Arithmetic\PlusToMinus;
use NightWorksIO\MutationGateDefault\DefaultExtension;
use NightWorksIO\MutationGateDefault\DefaultSet;
use NightWorksIO\MutationGateDefault\String\EmptyStringToNotEmpty;

it('registers every mutator of the set under its name', function (): void {
    $set = new DefaultExtension()->extend(new Extensions(Origin::of('nightworksio/mutation-gate')))
        ->registered(ExtensionPoint::MutatorSet, DefaultSet::name());
    $mutators = $set instanceof MutatorSet ? iterator_to_array($set, preserve_keys: false) : [];

    expect($mutators)->toHaveCount(159)
        ->and($mutators)->toContain(PlusToMinus::class, EmptyStringToNotEmpty::class);
});
