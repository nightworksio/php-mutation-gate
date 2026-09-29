<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Extension\Origin;

it('names the package an extension came from', function (): void {
    expect(Origin::of('acme/gate-slack')->name())->toBe('acme/gate-slack');
});
