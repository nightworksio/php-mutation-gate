<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** How a process a runner's shell started ended. */
enum Ending
{
    /** It finished, and exited 0. */
    case Succeeded;

    /** It finished, and exited otherwise, or could not start. */
    case Failed;

    /** It was stopped at its deadline. */
    case Stopped;
}
