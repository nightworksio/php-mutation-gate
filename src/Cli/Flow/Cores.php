<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

/**
 * The cores of the machine the gate runs on, counted as pest-plugin-mutate
 * counts them, with `fidry/cpu-core-counter`: one where it finds no count.
 * They are counted once a process, since the count asks the system each
 * time and a machine's cores do not change while it runs (ADR-0260).
 *
 * @internal
 */
final class Cores
{
    private static ?ProcessCount $counted = null;

    public static function counted(): ProcessCount
    {
        return self::$counted ??= ProcessCount::of(
            new CpuCoreCounter()->getCountWithFallback(ProcessCount::single()->count()),
        );
    }
}
