<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Core\Runner\Processes;

/**
 * The cores of the machine the gate runs on, counted as pest-plugin-mutate
 * counts them, with `fidry/cpu-core-counter`: one where it finds no count.
 */
final readonly class Cores
{
    public static function counted(): Processes
    {
        return Processes::of(new CpuCoreCounter()->getCountWithFallback(Processes::single()->count()));
    }
}
