<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Unpatched;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('is off unless the project patches Pest, with no canary group', function (): void {
    expect(Patching::off()->isOn())->toBeFalse()
        ->and(Patching::off()->canary())->toBe(Unpatched::Vendor);
});

it('is on with the canary group a shard opens on', function (): void {
    $patching = Patching::on(Group::named('mutation-canary'));

    expect($patching->isOn())->toBeTrue()
        ->and($patching->canary())->toEqual(Group::named('mutation-canary'));
});

it('opens a run on the canary group where it is handed a map and the patch is on, or else on what judges it', function (): void {
    $canary = Group::named('mutation-canary');
    $held = Group::named('holds:src/Money.php');

    expect(Patching::on($canary)->opensOn(WholeSuite::tests(), handedAMap: true))->toEqual($canary)
        ->and(Patching::on($canary)->opensOn($held, handedAMap: false))->toEqual($held)
        ->and(Patching::off()->opensOn(WholeSuite::tests(), handedAMap: true))->toEqual(WholeSuite::tests());
});
