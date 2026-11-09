<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

use function intdiv;
use function min;

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

/**
 * `local.workers` as a share of the machine's logical cores (ADR-0120).
 */
enum WorkerShare: string
{
    /** Half the logical cores, at least one and at most eight. */
    case Half = 'half';

    /** Every logical core but one, at least one. */
    case AllButOne = 'all-but-one';

    /** The most workers half the cores comes to, however many cores there are. */
    private const int MOST_HALF = 8;

    /** How many workers the share of these cores comes to. */
    public function of(ProcessCount $cores): ProcessCount
    {
        return ProcessCount::atLeastOne(match ($this) {
            self::Half => min(self::MOST_HALF, intdiv($cores->count(), 2)),
            self::AllButOne => $cores->count() - 1,
        });
    }
}
