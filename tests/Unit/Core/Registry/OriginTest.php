<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Registry\FirstPartyPackage;
use NightWorksIO\MutationGate\Core\Registry\Origin;

it('names the package an extension came from', function (): void {
    expect(Origin::of('acme/gate-slack')->name())->toBe('acme/gate-slack');
});

it('counts the packages this repository ships as first party, and no other', function (): void {
    expect(Origin::of('nightworksio/mutation-gate')->isFirstParty())->toBeTrue()
        ->and(Origin::of(FirstPartyPackage::DefaultSet->value)->isFirstParty())->toBeTrue()
        ->and(Origin::of('nightworksio/mutation-gate-slack')->isFirstParty())->toBeFalse()
        ->and(Origin::of('acme/gate-slack')->isFirstParty())->toBeFalse();
});
