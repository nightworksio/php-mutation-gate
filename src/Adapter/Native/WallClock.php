<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use function hrtime;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The system's monotonic clock.
 *
 * @internal
 */
final readonly class WallClock implements Clock
{
    public function seconds(): Seconds
    {
        return Seconds::of(hrtime(as_number: true) / Seconds::NANOSECONDS);
    }
}
