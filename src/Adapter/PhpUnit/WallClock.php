<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function hrtime;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** The system's monotonic clock. */
final readonly class WallClock implements Clock
{
    public function seconds(): Seconds
    {
        return Seconds::of(hrtime(as_number: true) / Seconds::NANOSECONDS);
    }
}
