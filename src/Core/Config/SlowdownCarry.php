<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.slowdownCarry`: where the slowdown a pass's streams measured
 * themselves at carries on to, as the slowdown the next pass's streams start
 * at (ADR-0184).
 */
enum SlowdownCarry: string
{
    /** To the next pass of the run, and to the first pass of the shard's next run. */
    case Shard = 'shard';

    /** To the next pass of the same run alone. */
    case Run = 'run';

    /** Nowhere: every pass's streams start as fast as the map. */
    case None = 'none';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Shard => 'StreamWork::slowdownThroughTheShard()',
            self::Run => 'StreamWork::slowdownThroughTheRun()',
            self::None => 'StreamWork::slowdownPerPass()',
        };
    }
}
