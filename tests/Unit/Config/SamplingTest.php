<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Sampling;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes each sampling key it sets', function (): void {
    expect(Configs::written(Gate::configure()->with(
        Sampling::confidence(99),
        Sampling::margin(1.5),
        Sampling::minUnits(50),
        Sampling::seed('release'),
    )))->toBe(['sampling' => ['confidence' => 99, 'margin' => 1.5, 'minUnits' => 50, 'seed' => 'release']]);
});
