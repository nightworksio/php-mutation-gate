<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

/** How a process the adapter ran ended. */
enum Ending
{
    case Succeeded;
    case Failed;

    /** Stopped at its deadline. */
    case Stopped;
}
