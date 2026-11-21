<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `shards.tail`: whether a job stops at its target and hands what it left to
 * a second wave, or runs its own tail to its job's limit (ADR-0303).
 */
enum ShardTail: string
{
    /** Runs its own tail where its wave's jobs come to more than run at once, or where `costs.mode` is `minutes`. */
    case Auto = 'auto';

    /** Stops at its target and hands what it left to a second wave. */
    case Stop = 'stop';

    /** Runs its own tail to its job's limit. */
    case Run = 'run';

    /** Whether a wave of jobs runs its own tail under this cost mode, where it outnumbers the jobs that run at once. */
    public function runs(bool $outnumbers, CostMode $mode): bool
    {
        return match ($this) {
            self::Run => true,
            self::Stop => false,
            self::Auto => $mode === CostMode::Minutes || $outnumbers,
        };
    }
}
