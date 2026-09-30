<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Unpatched;
use NightWorksIO\MutationGate\Core\Test\Group;

it('is off unless the project patches Pest, with no canary group', function (): void {
    expect(Patching::off()->isOn())->toBeFalse()
        ->and(Patching::off()->canary())->toBe(Unpatched::Vendor);
});

it('is on with the canary group a shard opens on', function (): void {
    $patching = Patching::on(Group::named('mutation-canary'));

    expect($patching->isOn())->toBeTrue()
        ->and($patching->canary())->toEqual(Group::named('mutation-canary'));
});
