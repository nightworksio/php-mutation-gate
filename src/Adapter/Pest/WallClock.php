<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function microtime;

/** The system's clock. */
final readonly class WallClock implements Clock
{
    public function seconds(): float
    {
        return microtime(as_float: true);
    }
}
