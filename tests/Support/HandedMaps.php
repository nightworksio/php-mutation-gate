<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;

/** The limits a test reads a handed map within: those of a process with no memory limit. */
final class HandedMaps
{
    public static function limits(): HandoffLimits
    {
        return HandoffLimits::under('-1');
    }
}
