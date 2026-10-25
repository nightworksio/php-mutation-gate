<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\Profiles;

it('turns on each mutator a block names, in its order and once, and off the ones it turns off', function (): void {
    $profiles = Profiles::installed(static fn(): bool => true);
    $on = $profiles instanceof Profiles
        ? $profiles->on([['Plus', true], ['Minus', true], ['Plus', true], ['Division', true], ['Minus', false]])
        : [];

    expect($on)->toBe(['Plus', 'Division']);
});
