<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How often `watch` looks at the files it polls: once a second (ADR-0010, decision 1). */
final readonly class Poll
{
    /** The wait between looks, in seconds. */
    private const float INTERVAL = 1.0;

    public static function interval(): Seconds
    {
        return Seconds::of(self::INTERVAL);
    }
}
