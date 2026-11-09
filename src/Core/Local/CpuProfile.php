<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * `local.cpu`: how much of the machine `watch` and `serve` take (ADR-0120),
 * the worker count and the priority each profile sets unless
 * `local.workers` or `local.priority` sets its own.
 */
enum CpuProfile: string
{
    /** Half the logical cores, at least one and at most eight, at utility priority. */
    case Balanced = 'balanced';

    /** Every core but one, at normal priority: the fastest, and the loudest. */
    case Fast = 'fast';

    /** One worker at background priority: the quietest, and the slowest. */
    case Quiet = 'quiet';

    public function workers(): WorkerShare|int
    {
        return match ($this) {
            self::Balanced => WorkerShare::Half,
            self::Fast => WorkerShare::AllButOne,
            self::Quiet => 1,
        };
    }

    public function priority(): Priority
    {
        return match ($this) {
            self::Balanced => Priority::Utility,
            self::Fast => Priority::Normal,
            self::Quiet => Priority::Background,
        };
    }
}
