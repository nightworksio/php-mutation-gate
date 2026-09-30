<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** How a program ended. */
enum Ending
{
    /** It finished, and exited 0. */
    case Succeeded;

    /** It finished, and exited otherwise. */
    case Failed;

    /** It was stopped at its deadline. */
    case Stopped;
}
