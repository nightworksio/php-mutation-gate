<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** `costs.mode`: what a plan spends least of, the run's wall time or its runners' billed minutes (ADR-0240). */
enum CostMode: string
{
    /** The wall time: shards cut to `shards.target`, ten minutes where it is not set. */
    case Wall = 'wall';

    /** The billed minutes: fewer, fuller shards, each job's time rounded up to a whole minute. */
    case Minutes = 'minutes';

    private const int MINUTES_TARGET_SECONDS = 1_800;

    /** The wall time a shard is cut to where `shards.target` is not set. */
    public function target(): Seconds
    {
        return Seconds::of(match ($this) {
            self::Wall => Shards::SECONDS,
            self::Minutes => self::MINUTES_TARGET_SECONDS,
        });
    }

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Wall => 'Costs::wall()',
            self::Minutes => 'Costs::minutes()',
        };
    }
}
