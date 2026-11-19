<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How often `watch` looks at the files it polls, by default: every two
 * seconds (ADR-0010, ADR-0120).
 *
 * @internal
 */
final readonly class Poll
{
    /** The wait between looks, in seconds. */
    private const float INTERVAL = 2.0;

    public static function interval(): Seconds
    {
        return Seconds::of(self::INTERVAL);
    }
}
