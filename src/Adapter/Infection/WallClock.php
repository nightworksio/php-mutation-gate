<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function hrtime;
use function is_int;

/** The system's monotonic clock. */
final readonly class WallClock implements Clock
{
    public function nanoseconds(): int
    {
        $now = hrtime(as_number: true);

        return is_int($now) ? $now : 0;
    }
}
