<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How often a runner's shell looks at a process it waits on to end by a deadline. */
final readonly class Polling
{
    /** The wait between looks, in seconds: short beside the shortest mutant's run, and a few looks a second. */
    private const float INTERVAL = 0.01;

    public static function interval(): Seconds
    {
        return Seconds::of(self::INTERVAL);
    }
}
