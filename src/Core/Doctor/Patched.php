<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/** Whether an installed package carries the gate's patch of it: `pest:patch` or `infection:patch` (ADR-0004). */
enum Patched
{
    /** The package carries every hunk of the patch. */
    case Applied;

    /** The package is installed without the patch. */
    case Missing;

    /** The package is not installed. */
    case NotInstalled;
}
