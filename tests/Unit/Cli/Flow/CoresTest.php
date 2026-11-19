<?php

declare(strict_types=1);

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Cli\Flow\Cores;

it('counts the cores as pest-plugin-mutate counts them', function (): void {
    expect(Cores::counted()->count())->toBe(new CpuCoreCounter()->getCountWithFallback(1));
});

it('counts them once a process', function (): void {
    expect(Cores::counted())->toBe(Cores::counted());
});
