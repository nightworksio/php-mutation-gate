<?php

declare(strict_types=1);

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Cli\Flow\Cores;

it('counts the cores as pest-plugin-mutate counts them', function (): void {
    expect(Cores::counted()->count())->toBe(new CpuCoreCounter()->getCountWithFallback(1));
});
