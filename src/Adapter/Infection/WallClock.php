<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function hrtime;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The system's monotonic clock.
 *
 * @internal
 */
final readonly class WallClock implements Clock
{
    public function nanoseconds(): int
    {
        [$seconds, $nanoseconds] = hrtime();

        return $seconds * Seconds::NANOSECONDS + $nanoseconds;
    }
}
