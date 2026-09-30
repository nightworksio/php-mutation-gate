<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Core\Runner\Processes;

/**
 * How many mutants Pest runs at once: pest-plugin-mutate starts as many
 * processes as `fidry/cpu-core-counter` counts cores on the machine it runs
 * on, and the gate counts them the same way. Where the counter finds no
 * count, the gate counts one.
 */
final readonly class Cores
{
    public static function counted(): Processes
    {
        return Processes::of(new CpuCoreCounter()->getCountWithFallback(Processes::single()->count()));
    }
}
