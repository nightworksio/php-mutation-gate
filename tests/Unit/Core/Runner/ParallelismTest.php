<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Parallelism;
use NightWorksIO\MutationGate\Core\Runner\Processes;

it('runs one mutant per core, or one at a time whatever the cores', function (): void {
    expect(Parallelism::PerCore->processes(Processes::of(8)))->toEqual(Processes::of(8))
        ->and(Parallelism::Serial->processes(Processes::of(8)))->toEqual(Processes::single());
});
