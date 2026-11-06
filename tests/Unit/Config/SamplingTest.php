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

it('writes when a run samples by itself, and which runs may', function (): void {
    expect(Configs::written(Gate::configure()->with(Sampling::auto('2h'), Sampling::autoRuns('pullRequests', 'branches'))))
        ->toBe(['sampling' => ['auto' => '2h', 'autoRuns' => ['pullRequests', 'branches']]])
        ->and(Configs::written(Gate::configure()->with(Sampling::auto(20000))))->toBe(['sampling' => ['auto' => 20000]])
        ->and(Configs::written(Gate::configure()->with(Sampling::autoOff())))->toBe(['sampling' => ['auto' => 'off']]);
});
