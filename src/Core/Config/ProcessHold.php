<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `processes.group` and `processes.contain`: whether the gate holds what it starts that way (ADR-0158). */
enum ProcessHold: string
{
    /** Held where this machine can hold it, and run as before where it cannot. */
    case Auto = 'auto';

    /** Never held that way. */
    case Off = 'off';

    /** Held, and nothing started where this machine cannot hold it. */
    case Require = 'require';

    /** Whether it holds what it starts where it can. */
    public function holds(): bool
    {
        return $this !== self::Off;
    }
}
