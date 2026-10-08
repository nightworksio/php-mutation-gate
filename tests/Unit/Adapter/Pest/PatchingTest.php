<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Unpatched;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;

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

it('tells a patched run its bounds and the start-up its run measured, and an unpatched one nothing', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
    $patching = Patching::on(Group::named('mutation-canary'));

    expect($patching->bounding($bounds->startingIn(Seconds::of(2.5))))->toBe([
        GateVariable::MutantFloor->value => '10.000000',
        GateVariable::MutantCap->value => '300.000000',
        GateVariable::MutantStartUp->value => '2.500000',
    ])
        ->and($patching->bounding($bounds))->not->toHaveKey(GateVariable::MutantStartUp->value)
        ->and(Patching::off()->bounding($bounds->startingIn(Seconds::of(2.5))))->toBe([]);
});
