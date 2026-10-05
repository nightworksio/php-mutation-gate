<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Parallelism;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

it('runs one mutant per core, or one at a time whatever the cores', function (): void {
    expect(Parallelism::PerCore->processes(ProcessCount::of(8)))->toEqual(ProcessCount::of(8))
        ->and(Parallelism::Serial->processes(ProcessCount::of(8)))->toEqual(ProcessCount::single());
});
