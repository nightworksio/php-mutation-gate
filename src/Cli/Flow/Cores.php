<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

/**
 * The cores of the machine the gate runs on, counted as pest-plugin-mutate
 * counts them, with `fidry/cpu-core-counter`: one where it finds no count.
 */
final readonly class Cores
{
    public static function counted(): ProcessCount
    {
        return ProcessCount::of(new CpuCoreCounter()->getCountWithFallback(ProcessCount::single()->count()));
    }
}
